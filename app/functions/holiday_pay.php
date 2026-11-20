<?php
/**
 * functions/holiday_pay.php
 *
 * Holiday pay hours (Settings -> Holidays -> Holiday pay). Off until an admin turns it on.
 *
 * For each paid company holiday in a date range, an employee earns holiday hours:
 *   - Hours: the employee's scheduled shift hours less lunch ('shift' mode), or a
 *     fixed number ('fixed' mode, and for employees without a shift).
 *   - Holiday on the employee's day off (not one of their shift's days; Mon-Fri without
 *     a shift): no holiday pay, or paid anyway, per HolidayPayDayOff.
 *   - Optional rule: the employee must work their last scheduled day before the holiday
 *     and their first scheduled day after it. Approved PTO/Sick counts as worked. While
 *     the day after is still to come, the holiday isn't counted yet.
 *   - Holidays before an employee's first day on the clock earn nothing, and so do
 *     holidays with no punch or paid time off in the 14 days before them (company
 *     holidays not counted): someone who has left but isn't archived yet, or is on
 *     long unpaid leave. Holidays after the day an employee was archived earn nothing.
 *   - An employee's own Holiday hours (Edit User) replace the setting, e.g. 8 for a
 *     part-timer; an admin can also set one employee's hours for one holiday by hand
 *     (holiday_pay_adjustments, e.g. half a holiday), which wins over every rule.
 * Holiday hours are reported in their own column. By default they don't count toward
 * overtime (they aren't hours worked); HolidayPayCountsOT counts them toward the
 * weekly threshold (holidayOvertimeCredit()). Working on a holiday is paid as usual, on top.
 */

require_once __DIR__ . '/settings_helper.php';
require_once __DIR__ . '/holidays.php';
require_once __DIR__ . '/pay_rules.php';      // shiftWindow(), shiftRunsOn(), listShifts()
require_once __DIR__ . '/time_off_hours.php'; // fetchApprovedTimeOff()

/** Holiday pay needs a punch or paid time off within this many (non-holiday) days before the holiday. */
const HOLIDAY_PAY_ACTIVE_DAYS = 14;

/** @return array{enabled:bool,mode:string,fixedHours:float,dayOff:string,requireAdjacent:bool,countsOT:bool} */
function holidayPayPolicy(mysqli $conn): array
{
    $get = fn(string $k, string $d) => (string) (getSettingValue($k, $conn) ?? $d);
    $fixed = (float) $get('HolidayPayFixedHours', '8');
    return [
        'enabled'         => $get('HolidayPayEnabled', '0') === '1',
        'mode'            => $get('HolidayPayMode', 'shift') === 'fixed' ? 'fixed' : 'shift',
        'fixedHours'      => ($fixed > 0 && $fixed <= 24) ? $fixed : 8.0,
        'dayOff'          => $get('HolidayPayDayOff', 'none') === 'pay' ? 'pay' : 'none',
        'requireAdjacent' => $get('HolidayPayRequireAdjacent', '0') === '1',
        'countsOT'        => $get('HolidayPayCountsOT', '0') === '1',
    ];
}

/** Paid holidays observed in [$start, $end]: 'Y-m-d' => name. */
function paidHolidaysInRange(string $start, string $end): array
{
    $out = [];
    // +1 year: a holiday can be observed in the year before its own (Jan 1 on a Saturday)
    for ($y = (int) substr($start, 0, 4); $y <= (int) substr($end, 0, 4) + 1; $y++) {
        foreach (holidayScheduleForYear($y) as $h) {
            if ((int) ($h['rule']['Paid'] ?? 1) !== 1 || $h['date'] < $start || $h['date'] > $end) {
                continue;
            }
            $name = $h['rule']['Name'];
            $out[$h['date']] = isset($out[$h['date']]) && $out[$h['date']] !== $name ? $out[$h['date']] . ' / ' . $name : $name;
        }
    }
    ksort($out);
    return $out;
}

/**
 * Holiday hours per employee for [$start, $end].
 * Each day: name, hours, and 'adjusted' (+ 'note', 'rule') when set by hand.
 * $withAdjustments false gives the hours the rules alone would pay.
 * @return array<int,array{hours:float,days:array<string,array{name:string,hours:float,adjusted?:bool,note?:string,rule?:float}>}>
 */
function holidayPayByEmployee(mysqli $conn, string $start, string $end, bool $withAdjustments = true): array
{
    $policy = holidayPayPolicy($conn);
    if (!$policy['enabled']) {
        return [];
    }
    $holidays = paidHolidaysInRange($start, $end);
    if (!$holidays) {
        return [];
    }
    try {
        $shifts = listShifts($conn, true);
    } catch (mysqli_sql_exception $e) {
        $shifts = [];
    }
    try {
        $users = $conn->query("SELECT ID, ShiftID, HolidayHours, ArchivedAt FROM users")->fetch_all(MYSQLI_ASSOC);
    } catch (mysqli_sql_exception $e) {
        $users = $conn->query("SELECT ID, ShiftID, NULL AS HolidayHours, NULL AS ArchivedAt FROM users")->fetch_all(MYSQLI_ASSOC);
    }

    $firstDay = [];
    $res = $conn->query("SELECT EmployeeID, MIN(Date) AS d FROM timepunches WHERE TimeIN IS NOT NULL GROUP BY EmployeeID");
    while ($r = $res->fetch_assoc()) {
        $firstDay[(int) $r['EmployeeID']] = $r['d'];
    }

    // Days worked (or on paid time off) around the holidays, for the recent-activity
    // rule and the before/after rule
    $worked = [];
    $from = (new DateTime(array_key_first($holidays)))->modify('-' . (HOLIDAY_PAY_ACTIVE_DAYS + 31) . ' days')->format('Y-m-d');
    $to   = (new DateTime(array_key_last($holidays)))->modify('+21 days')->format('Y-m-d');
    $st = $conn->prepare("SELECT DISTINCT EmployeeID, Date FROM timepunches WHERE TimeIN IS NOT NULL AND Date BETWEEN ? AND ?");
    $st->bind_param("ss", $from, $to);
    $st->execute();
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $worked[(int) $r['EmployeeID']][$r['Date']] = true;
    }
    foreach (fetchApprovedTimeOff($conn, $from, $to) as $req) {
        if (!timeOffIsPaid($req)) {
            continue; // an unpaid absence isn't paid leave
        }
        for ($d = new DateTime(max($req['StartDate'], $from)); $d->format('Y-m-d') <= min($req['EndDate'], $to); $d->modify('+1 day')) {
            $worked[(int) $req['EmployeeID']][$d->format('Y-m-d')] = true;
        }
    }

    // Worked or on paid time off in the HOLIDAY_PAY_ACTIVE_DAYS days before $date. Company
    // holidays don't count toward those days, so a run of holidays (Christmas Eve,
    // Christmas, New Year's) doesn't push the employee's last work day out of the window.
    $recentlyActive = function (int $eid, string $date) use (&$worked): bool {
        $d = new DateTime($date);
        for ($seen = 0, $i = 0; $seen < HOLIDAY_PAY_ACTIVE_DAYS && $i < HOLIDAY_PAY_ACTIVE_DAYS + 31; $i++) {
            $x = $d->modify('-1 day')->format('Y-m-d');
            if (!empty($worked[$eid][$x])) {
                return true;
            }
            if (isCompanyHoliday($x) === null) {
                $seen++;
            }
        }
        return false;
    };

    $today = date('Y-m-d');
    $out = [];
    foreach ($users as $u) {
        $eid   = (int) $u['ID'];
        $shift = $u['ShiftID'] !== null ? ($shifts[(int) $u['ShiftID']] ?? null) : null;
        $runs  = fn(string $date) => $shift ? shiftRunsOn($shift, $date) : (int) date('N', strtotime($date)) < 6;
        // The nearest scheduled, non-holiday day before/after $date (within 3 weeks)
        $adjacent = function (string $date, int $step) use ($runs): ?string {
            $d = new DateTime($date);
            for ($i = 0; $i < 21; $i++) {
                $d->modify(($step > 0 ? '+' : '-') . '1 day');
                $x = $d->format('Y-m-d');
                if ($runs($x) && isCompanyHoliday($x) === null) {
                    return $x;
                }
            }
            return null;
        };

        $archivedOn = $u['ArchivedAt'] !== null ? substr($u['ArchivedAt'], 0, 10) : null;
        foreach ($holidays as $date => $name) {
            if (!isset($firstDay[$eid]) || $firstDay[$eid] > $date) {
                continue; // not on the clock yet
            }
            if ($archivedOn !== null && $date > $archivedOn) {
                continue; // archived before the holiday
            }
            if (!$runs($date) && $policy['dayOff'] === 'none') {
                continue; // the holiday falls on this employee's day off
            }
            if (!$recentlyActive($eid, $date)) {
                continue; // nothing in the last two weeks: has left, or on unpaid leave
            }
            if ($policy['requireAdjacent']) {
                $before = $adjacent($date, -1);
                $after  = $adjacent($date, 1);
                if ($after === null || $after >= $today) {
                    continue; // the day after hasn't been worked yet
                }
                if (($before !== null && empty($worked[$eid][$before])) || empty($worked[$eid][$after])) {
                    continue;
                }
            }
            $hours = holidayHoursFor($u, $shift, $policy);
            if ($hours <= 0) {
                continue;
            }
            $out[$eid]['days'][$date] = ['name' => $name, 'hours' => $hours];
        }
    }

    if ($withAdjustments) {
        foreach (holidayPayAdjustments($conn, $start, $end) as $eid => $byDate) {
            foreach ($byDate as $date => $adj) {
                if (!isset($holidays[$date])) {
                    continue; // no longer a paid holiday: the hand-set hours don't apply
                }
                $rule = $out[$eid]['days'][$date]['hours'] ?? 0.0;
                $out[$eid]['days'][$date] = ['name' => $holidays[$date], 'hours' => $adj['hours'],
                                             'adjusted' => true, 'note' => $adj['note'], 'rule' => $rule];
            }
        }
    }
    foreach ($out as $eid => &$o) {
        $o['days'] = array_filter($o['days'] ?? [], fn($d) => $d['hours'] > 0 || !empty($d['adjusted']));
        ksort($o['days']);
        $o['hours'] = round(array_sum(array_column($o['days'], 'hours')), 2);
        if (!$o['days']) {
            unset($out[$eid]);
        }
    }
    unset($o);
    return $out;
}

/** A full holiday's hours for one employee: their own Holiday hours, else the shift or fixed setting. */
function holidayHoursFor(array $user, ?array $shift, array $policy): float
{
    if (isset($user['HolidayHours']) && $user['HolidayHours'] !== null && $user['HolidayHours'] !== '') {
        return round(max(0.0, (float) $user['HolidayHours']), 2);
    }
    return ($policy['mode'] === 'shift' && $shift) ? shiftScheduledHours($shift) : $policy['fixedHours'];
}

/** Hand-set holiday hours in [$start, $end]: [employeeID][date] => [hours, note, by, at]. */
function holidayPayAdjustments(mysqli $conn, string $start, string $end): array
{
    $out = [];
    try {
        $st = $conn->prepare("SELECT EmployeeID, HolidayDate, Hours, Note, AdjustedBy, AdjustedAt FROM holiday_pay_adjustments WHERE HolidayDate BETWEEN ? AND ?");
        $st->bind_param("ss", $start, $end);
        $st->execute();
        foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $out[(int) $r['EmployeeID']][$r['HolidayDate']] = [
                'hours' => round((float) $r['Hours'], 2), 'note' => (string) ($r['Note'] ?? ''),
                'by' => (string) ($r['AdjustedBy'] ?? ''), 'at' => (string) $r['AdjustedAt'],
            ];
        }
    } catch (mysqli_sql_exception $e) {
        // migration not applied yet
    }
    return $out;
}

/**
 * Holiday hours per work week (payPeriodStart() => hours) that count toward the weekly
 * overtime threshold, or [] while HolidayPayCountsOT is off. Pass to weeklyOvertime().
 */
function holidayOvertimeCredit(array $policy, array $employeeHolidayPay): array
{
    if (empty($policy['countsOT'])) {
        return [];
    }
    $credit = [];
    foreach ($employeeHolidayPay['days'] ?? [] as $date => $d) {
        $wk = payPeriodStart($date);
        $credit[$wk] = ($credit[$wk] ?? 0.0) + (float) $d['hours'];
    }
    return $credit;
}
