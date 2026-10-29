<?php
/**
 * functions/pay_rules.php
 *
 * Shift rounding, auto-lunch and company pay rules. Turns an employee-day of RAW
 * punches into PAID times and PAID hours. Raw punch times are never modified: the
 * paid values are stored beside them (PaidIn, PaidOut, LunchDeductMin) and paid hours
 * go to TotalHours, which every report already reads (see hours.php).
 *
 *   applyShiftRules()   pure: one employee-day of rows -> paid columns per row
 *   recomputeDayHours() loads the employee's shift, applies the rules, writes the row(s)
 *   recomputeRange()    the same across a date range (shift "Recalculate" tool)
 *   payPolicy()         company-wide pay rules from the settings table
 *
 * Every writer that changes punch times calls recomputeDayHours() afterwards, for the
 * whole day: an auto-deducted lunch, and the snap of the day's first clock-in and last
 * clock-out, depend on every row of that day, not just the one that changed.
 *
 * With no shift assigned and no default rounding, the result is identical to
 * calculateTotalHours() — existing installs are unaffected until they opt in.
 */

require_once __DIR__ . '/hours.php';

if (!defined('SHIFT_MATCH_HOURS')) {
    // A day's first clock-in further than this from the shift start (e.g. a Saturday
    // call-in on a Mon-Fri shift) is not snapped to the shift — it is only rounded.
    define('SHIFT_MATCH_HOURS', 3);
}

/** Lunch modes, as offered on the shift editor. */
const LUNCH_MODES = [
    'punched'             => 'Employees punch lunch out and in',
    'auto'                => 'Take lunch out automatically',
    'auto_unless_punched' => 'Take lunch out automatically unless they punch it',
];

/** Rounding modes, as offered on the shift editor and Pay Rules page. */
const ROUND_MODES = [
    'nearest' => 'to the nearest',
    'up'      => 'up to the next',
    'down'    => 'down to the previous',
];

/** Flag codes from applyShiftRules() -> short label for timesheets. */
const PUNCH_FLAG_LABELS = [
    'early_in'       => 'Clocked in before the early window',
    'early_in_unpaid'=> 'Early clock-in not paid (needs approval)',
    'late_in'        => 'Late',
    'left_early'     => 'Left early',
    'late_out'       => 'Stayed past the shift',
    'late_out_unpaid'=> 'Time past the shift not paid (needs approval)',
    'unscheduled'    => 'Not a scheduled shift day — rounded only',
    'auto_lunch'     => 'Lunch taken out automatically',
];

/**
 * Company pay rules (settings table), cached per request.
 * @return array{weekStartDay:int,periodType:string,periodAnchor:?string,otWeekly:float,otDaily:float,roundIncrement:int,roundMode:string}
 */
function payPolicy(mysqli $conn, bool $refresh = false): array {
    static $cache = null;
    if ($cache !== null && !$refresh) {
        return $cache;
    }
    $s = [];
    if ($res = $conn->query("SELECT SettingKey, SettingValue FROM settings WHERE SettingKey IN
            ('PayWeekStartDay','PayPeriodType','PayPeriodAnchor','OvertimeWeeklyHours',
             'OvertimeDailyHours','DefaultRoundIncrement','DefaultRoundMode')")) {
        while ($row = $res->fetch_assoc()) {
            $s[$row['SettingKey']] = $row['SettingValue'];
        }
    }
    $mode = $s['DefaultRoundMode'] ?? 'nearest';
    $cache = [
        'weekStartDay'   => workWeekStartDay(),
        'periodType'     => ($s['PayPeriodType'] ?? 'weekly') === 'biweekly' ? 'biweekly' : 'weekly',
        'periodAnchor'   => ($s['PayPeriodAnchor'] ?? '') ?: null,
        'otWeekly'       => overtimeWeeklyHours(),
        'otDaily'        => overtimeDailyHours(),
        'roundIncrement' => max(0, (int) ($s['DefaultRoundIncrement'] ?? 0)),
        'roundMode'      => isset(ROUND_MODES[$mode]) ? $mode : 'nearest',
    ];
    return $cache;
}

/**
 * The pay period containing $date: the work week, or (bi-weekly) the 14-day block
 * counted from the anchor date on the Pay Rules page.
 * @return array{0:string,1:string} [start, end] as 'Y-m-d'
 */
function payPeriodRange(string $date, array $policy): array {
    if ($policy['periodType'] === 'biweekly' && $policy['periodAnchor']) {
        $anchor = new DateTime($policy['periodAnchor']);
        $d = new DateTime($date);
        $days = (int) $anchor->diff($d)->format('%r%a');
        $offset = (int) floor($days / 14) * 14;
        $start = (clone $anchor)->modify(($offset >= 0 ? '+' : '') . $offset . ' days');
        return [$start->format('Y-m-d'), (clone $start)->modify('+13 days')->format('Y-m-d')];
    }
    return [payPeriodStart($date), payPeriodEnd($date)];
}

/** Round seconds-since-midnight to an increment of $minutes (0 = unchanged). */
function roundToIncrement(int $sec, int $minutes, string $mode): int {
    if ($minutes <= 0) return $sec;
    $inc = $minutes * 60;
    switch ($mode) {
        case 'up':   return (int) (ceil($sec / $inc) * $inc);
        case 'down': return (int) (floor($sec / $inc) * $inc);
        default:     return (int) (round($sec / $inc) * $inc);
    }
}

/** Seconds (may exceed a day) -> 'H:i:s' clock time. */
function secondsToTime(int $sec): string {
    $sec = (($sec % 86400) + 86400) % 86400;
    return sprintf('%02d:%02d:%02d', intdiv($sec, 3600), intdiv($sec % 3600, 60), $sec % 60);
}

/** The shift's start/end in seconds from the punch date's midnight (end rolled past start). */
function shiftWindow(array $shift): array {
    $start = hms_to_seconds($shift['StartTime']);
    $end   = hms_to_seconds($shift['EndTime']);
    if ($end <= $start) {
        $end += 86400; // overnight shift
    }
    return [$start, $end];
}

/** Does $shift run on $date ('Y-m-d')? */
function shiftRunsOn(array $shift, string $date): bool {
    $days = array_filter(explode(',', (string) ($shift['Days'] ?? '')));
    return in_array(date('D', strtotime($date)), $days, true);
}

/**
 * THE rules engine. Pure: no DB, no session.
 *
 * @param array  $rows   the employee's timepunches rows for ONE date, any order. Keys used:
 *                       id, TimeIN, LunchStart, LunchEnd, TimeOut, LunchWaived, OutsideApproved
 * @param ?array $shift  shifts row, or null for no shift
 * @param array  $policy payPolicy() (only roundIncrement/roundMode are used)
 * @param string $date   the rows' Date
 * @return array<int|string,array{ShiftID:?int,PaidIn:?string,PaidOut:?string,LunchDeductMin:?int,RawHours:?float,TotalHours:?float,flags:string[]}>
 *         keyed by row id. Rows that can't be paid yet (still open, or clocked out with
 *         an unfinished lunch awaiting review) get null hours and null paid times.
 */
function applyShiftRules(array $rows, ?array $shift, array $policy, string $date): array {
    $inc  = $shift ? (int) $shift['RoundIncrement'] : (int) ($policy['roundIncrement'] ?? 0);
    $mode = $shift ? (string) $shift['RoundMode']   : (string) ($policy['roundMode'] ?? 'nearest');
    $rnd  = fn(int $s) => roundToIncrement($s, $inc, $mode);

    usort($rows, fn($a, $b) => (hms_to_seconds($a['TimeIN']) ?? PHP_INT_MAX) <=> (hms_to_seconds($b['TimeIN']) ?? PHP_INT_MAX));

    $out = [];
    $complete = []; // rows we can pay, in clock-in order
    foreach ($rows as $r) {
        $out[$r['id']] = [
            'ShiftID' => $shift ? (int) $shift['ID'] : null,
            'PaidIn' => null, 'PaidOut' => null, 'LunchDeductMin' => null,
            'RawHours' => null, 'TotalHours' => null, 'flags' => [],
        ];
        $openLunch = !empty($r['LunchStart']) && empty($r['LunchEnd']);
        if (empty($r['TimeIN']) || empty($r['TimeOut']) || $openLunch) {
            continue;
        }
        $raw = calculateTotalHours($r['TimeIN'], $r['LunchStart'], $r['LunchEnd'], $r['TimeOut']);
        $out[$r['id']]['RawHours'] = $raw;
        $inS  = hms_to_seconds($r['TimeIN']);
        $outS = hms_to_seconds($r['TimeOut']);
        $rolled = $outS <= $inS;
        if ($rolled) {
            $outS += 86400;
        }
        if ($rolled && $outS - $inS > MAX_SHIFT_HOURS * 3600) {
            // Same garbage guard as calculateTotalHours() — only an implausible overnight
            // roll-over is garbage; a long same-day span is paid (validatePunch flags it).
            $out[$r['id']]['PaidIn']     = $r['TimeIN'];
            $out[$r['id']]['PaidOut']    = $r['TimeOut'];
            $out[$r['id']]['LunchDeductMin'] = 0;
            $out[$r['id']]['TotalHours'] = 0.0;
            continue;
        }
        $complete[] = $r + ['_in' => $inS, '_out' => $outS];
    }
    if (!$complete) {
        return $out;
    }

    // Snap to the shift only on a scheduled day, and only when the day's first
    // clock-in is plausibly this shift (not a Saturday call-in or a double).
    $scheduled = false;
    if ($shift) {
        [$S, $E] = shiftWindow($shift);
        $firstIn = $complete[0]['_in'];
        $dist = min(abs($firstIn - $S), 86400 - abs($firstIn - $S));
        $scheduled = shiftRunsOn($shift, $date) && $dist <= SHIFT_MATCH_HOURS * 3600;
        if (!$scheduled) {
            $out[$complete[0]['id']]['flags'][] = 'unscheduled';
        }
    }

    $last = count($complete) - 1;
    $paid = []; // index => [in, out, punchedLunch]
    foreach ($complete as $i => $r) {
        $id = $r['id'];
        $approved = !empty($r['OutsideApproved']);
        $in = $r['_in'];
        $o  = $r['_out'];

        if ($i === 0 && $scheduled) {
            // Offset from the shift start, wrapped across midnight: a 00:30 clock-in for a
            // 22:00 shift is 2.5 h late, not 21.5 h early.
            $rel = $in - $S;
            if ($rel > 43200) {
                $rel -= 86400;
            } elseif ($rel < -43200) {
                $rel += 86400;
            }
            $start = $in - $rel; // the shift start on this punch's own clock
            if ($rel < -(int) $shift['EarlyInMinutes'] * 60) {
                if ($shift['OutsidePolicy'] === 'cap' && !$approved) {
                    $pIn = $start;
                    $out[$id]['flags'][] = 'early_in_unpaid';
                } else {
                    $pIn = $rnd($in);
                    $out[$id]['flags'][] = 'early_in';
                }
            } elseif ($rel <= (int) $shift['LateInGraceMinutes'] * 60) {
                $pIn = $start;
            } else {
                $pIn = $rnd($in);
                $out[$id]['flags'][] = 'late_in';
            }
        } else {
            $pIn = $rnd($in);
        }

        if ($i === $last && $scheduled) {
            // Compare against the shift end on the same clock as this row's clock-out.
            $endRef = $E;
            if ($o - $endRef > 43200) $endRef += 86400;
            if ($endRef - $o > 43200) $endRef -= 86400;
            if ($o > $endRef + (int) $shift['LateOutMinutes'] * 60) {
                if ($shift['OutsidePolicy'] === 'cap' && !$approved) {
                    $pOut = $endRef;
                    $out[$id]['flags'][] = 'late_out_unpaid';
                } else {
                    $pOut = $rnd($o);
                    $out[$id]['flags'][] = 'late_out';
                }
            } elseif ($o >= $endRef - (int) $shift['EarlyOutGraceMinutes'] * 60) {
                $pOut = $endRef;
            } else {
                $pOut = $rnd($o);
                $out[$id]['flags'][] = 'left_early';
            }
        } else {
            $pOut = $rnd($o);
        }
        if ($pOut < $pIn) {
            $pOut = $pIn;
        }

        $lunch = 0;
        $ls = hms_to_seconds($r['LunchStart']);
        $le = hms_to_seconds($r['LunchEnd']);
        if ($ls !== null && $le !== null && $le > $ls) {
            $lunch = max(0, $rnd($le) - $rnd($ls));
        }
        $paid[$i] = [$pIn, $pOut, $lunch];
    }

    // Lunch. Breaks already taken = punched lunches + gaps between rows (the
    // "clock out for lunch, clock back in" pattern of the quick-clock).
    $lunchMode = $shift ? (string) $shift['LunchMode'] : 'punched';
    $breakTaken = 0;
    $onClock = 0;
    foreach ($paid as $i => [$pIn, $pOut, $lunch]) {
        $breakTaken += $lunch;
        $onClock    += $pOut - $pIn;
        if ($i > 0) {
            $breakTaken += max(0, $pIn - $paid[$i - 1][1]);
        }
    }
    $waived = (bool) array_filter($complete, fn($r) => !empty($r['LunchWaived']));
    $extra = 0;
    if ($lunchMode !== 'punched' && !$waived
        && !($lunchMode === 'auto_unless_punched' && $breakTaken > 0)
        && $onClock >= (float) $shift['LunchMinWorkedHours'] * 3600) {
        $extra = max(0, (int) $shift['LunchMinutes'] * 60 - $breakTaken);
    }
    if ($extra > 0) {
        // Book the deduction on the longest row so no row goes negative.
        $target = 0;
        foreach ($paid as $i => [$pIn, $pOut, $lunch]) {
            if (($pOut - $pIn - $lunch) > ($paid[$target][1] - $paid[$target][0] - $paid[$target][2])) {
                $target = $i;
            }
        }
        $paid[$target][2] += $extra;
        $out[$complete[$target]['id']]['flags'][] = 'auto_lunch';
    }

    foreach ($paid as $i => [$pIn, $pOut, $lunch]) {
        $id = $complete[$i]['id'];
        $net = max(0, $pOut - $pIn - $lunch);
        $out[$id]['PaidIn']         = secondsToTime($pIn);
        $out[$id]['PaidOut']        = secondsToTime($pOut);
        $out[$id]['LunchDeductMin'] = (int) round($lunch / 60);
        $out[$id]['TotalHours']     = round($net / 3600, 2);
    }
    return $out;
}

/** The shift assigned to an employee (archived shifts still apply until reassigned), or null. */
function employeeShift(mysqli $conn, int $empID): ?array {
    try {
        $stmt = $conn->prepare("SELECT s.* FROM users u JOIN shifts s ON s.ID = u.ShiftID WHERE u.ID = ?");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param("i", $empID);
        $stmt->execute();
        $shift = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $shift;
    } catch (mysqli_sql_exception $e) {
        return null; // shifts migration not applied yet — behave as "no shift"
    }
}

/**
 * Apply the rules to every row of one employee-day and store the result.
 * Returns the rows whose paid hours changed: [id => ['old' => ?string, 'new' => ?float]].
 * With $dryRun nothing is written (used by the Recalculate preview).
 */
function recomputeDayHours(mysqli $conn, int $empID, string $date, bool $dryRun = false, ?array $shift = null, bool $shiftKnown = false): array {
    if (!$shiftKnown) {
        $shift = employeeShift($conn, $empID);
    }
    $stmt = $conn->prepare("SELECT id, TimeIN, LunchStart, LunchEnd, TimeOut, TotalHours, LunchWaived, OutsideApproved FROM timepunches WHERE EmployeeID = ? AND Date = ?");
    $stmt->bind_param("is", $empID, $date);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if (!$rows) {
        return [];
    }

    $result  = applyShiftRules($rows, $shift, payPolicy($conn), $date);
    $changes = [];
    $upd = $dryRun ? null : $conn->prepare("UPDATE timepunches SET ShiftID = ?, PaidIn = ?, PaidOut = ?, LunchDeductMin = ?, RawHours = ?, TotalHours = ? WHERE id = ?");
    foreach ($rows as $r) {
        $c   = $result[$r['id']];
        $old = $r['TotalHours'];
        $new = $c['TotalHours'];
        $same = ($old === null && $new === null)
            || ($old !== null && $new !== null && abs((float) $old - $new) < 0.005);
        if (!$same) {
            $changes[$r['id']] = ['old' => $old, 'new' => $new];
        }
        if ($upd) {
            $id = (int) $r['id'];
            $upd->bind_param("issiddi", $c['ShiftID'], $c['PaidIn'], $c['PaidOut'], $c['LunchDeductMin'], $c['RawHours'], $c['TotalHours'], $id);
            $upd->execute();
        }
    }
    if ($upd) {
        $upd->close();
    }
    return $changes;
}

/**
 * recomputeDayHours() for the day of one punch, then return that punch's paid hours
 * (for the "Worked: 8.00 hrs" message after a clock-out).
 */
function recomputePunchHours(mysqli $conn, int $empID, string $date, int $punchID): ?float {
    recomputeDayHours($conn, $empID, $date);
    $stmt = $conn->prepare("SELECT TotalHours FROM timepunches WHERE id = ?");
    $stmt->bind_param("i", $punchID);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ($row && $row['TotalHours'] !== null) ? (float) $row['TotalHours'] : null;
}

/**
 * Re-apply the rules to every completed punch in [$from, $to], optionally for one
 * shift's employees only. Returns per-employee totals of the change:
 *   [empID => ['name' => string, 'rows' => int, 'before' => float, 'after' => float]]
 * With $dryRun nothing is written. Writes are logged to punch_changelog as $changedBy.
 */
function recomputeRange(mysqli $conn, string $from, string $to, ?int $shiftID = null, bool $dryRun = true, string $changedBy = 'SYSTEM'): array {
    $sql = "SELECT DISTINCT tp.EmployeeID, tp.Date, u.FirstName, u.LastName
              FROM timepunches tp JOIN users u ON u.ID = tp.EmployeeID
             WHERE tp.Date BETWEEN ? AND ? AND tp.TimeIN IS NOT NULL AND tp.TimeOut IS NOT NULL";
    $types = "ss";
    $params = [$from, $to];
    if ($shiftID !== null) {
        $sql .= " AND u.ShiftID = ?";
        $types .= "i";
        $params[] = $shiftID;
    }
    $sql .= " ORDER BY u.LastName, u.FirstName, tp.Date";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $days = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $summary = [];
    $shifts  = [];
    $log = $dryRun ? null : $conn->prepare("INSERT INTO punch_changelog (EmployeeID, Date, ChangedBy, FieldChanged, OldValue, NewValue, Reason) VALUES (?, ?, ?, 'TotalHours', ?, ?, 'Shift rules re-applied')");
    foreach ($days as $d) {
        $emp = (int) $d['EmployeeID'];
        if (!array_key_exists($emp, $shifts)) {
            $shifts[$emp] = employeeShift($conn, $emp);
        }
        $changes = recomputeDayHours($conn, $emp, $d['Date'], $dryRun, $shifts[$emp], true);
        if (!$changes) {
            continue;
        }
        $summary[$emp] ??= ['name' => $d['FirstName'] . ' ' . $d['LastName'], 'rows' => 0, 'before' => 0.0, 'after' => 0.0];
        foreach ($changes as $c) {
            $summary[$emp]['rows']++;
            $summary[$emp]['before'] += (float) $c['old'];
            $summary[$emp]['after']  += (float) $c['new'];
            if ($log) {
                $old = $c['old'] === null ? null : (string) $c['old'];
                $new = $c['new'] === null ? null : number_format($c['new'], 2, '.', '');
                $log->bind_param("issss", $emp, $d['Date'], $changedBy, $old, $new);
                $log->execute();
            }
        }
    }
    if ($log) {
        $log->close();
    }
    return $summary;
}

/**
 * Whether punches are being rounded at the source (anyone on a shift, or default
 * rounding on). Reports then hide their own "round to nearest" option, which would
 * round already-rounded totals a second time.
 */
function punchRoundingActive(mysqli $conn): bool {
    if (payPolicy($conn)['roundIncrement'] > 0) {
        return true;
    }
    try {
        $res = $conn->query("SELECT 1 FROM users WHERE ShiftID IS NOT NULL LIMIT 1");
        return $res && $res->num_rows > 0;
    } catch (mysqli_sql_exception $e) {
        return false; // shifts migration not applied yet
    }
}

/** Shifts for pickers: [ID => row], active first then archived, by start time. */
function listShifts(mysqli $conn, bool $includeArchived = false): array {
    $list = [];
    $sql = "SELECT * FROM shifts" . ($includeArchived ? "" : " WHERE Archived = 0") . " ORDER BY Archived, StartTime, Name";
    if ($res = $conn->query($sql)) {
        while ($row = $res->fetch_assoc()) {
            $list[(int) $row['ID']] = $row;
        }
    }
    return $list;
}

/** "7:00a–3:30p" */
function shiftTimeRange(array $shift): string {
    $f = fn($t) => str_replace(['am', 'pm', ':00'], ['a', 'p', ''], date('g:ia', strtotime($t)));
    return $f($shift['StartTime']) . '–' . $f($shift['EndTime']);
}

/** Small colored name chip for tables. */
function shiftChip(?array $shift): string {
    if (!$shift) {
        return '<span style="color:#888;">—</span>';
    }
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', $shift['Color']) ? $shift['Color'] : '#0a4a6b';
    return '<span class="shift-chip" style="display:inline-block; padding:.1rem .5rem; border-radius:999px; font-size:.85em; color:#fff; background:' . $color . ';">'
         . htmlspecialchars($shift['Name']) . '</span>';
}
