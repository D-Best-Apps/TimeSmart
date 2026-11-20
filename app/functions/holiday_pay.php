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
 *   - Holidays before an employee's first day on the clock earn nothing.
 * Holiday hours are reported in their own column and never count toward overtime
 * (they aren't hours worked). Working on a holiday is paid as usual, on top.
 */

require_once __DIR__ . '/settings_helper.php';
require_once __DIR__ . '/holidays.php';
require_once __DIR__ . '/pay_rules.php';      // shiftWindow(), shiftRunsOn(), listShifts()
require_once __DIR__ . '/time_off_hours.php'; // fetchApprovedTimeOff()

/** @return array{enabled:bool,mode:string,fixedHours:float,dayOff:string,requireAdjacent:bool} */
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
    ];
}

/**
 * A shift's scheduled paid hours: start to end, less the shift's lunch. For punched
 * lunches that's the "Expected lunch" on the shift editor (0 = none), since a full
 * shift includes one even though the clock doesn't deduct it.
 */
function shiftScheduledHours(array $shift): float
{
    [$s, $e] = shiftWindow($shift);
    $hours = ($e - $s) / 3600;
    if ($hours >= (float) ($shift['LunchMinWorkedHours'] ?? 0)) {
        $hours -= (int) ($shift['LunchMinutes'] ?? 0) / 60;
    }
    return round(max(0.0, $hours), 2);
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
 * @return array<int,array{hours:float,days:array<string,array{name:string,hours:float}>}>
 */
function holidayPayByEmployee(mysqli $conn, string $start, string $end): array
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
    $users = $conn->query("SELECT ID, ShiftID FROM users")->fetch_all(MYSQLI_ASSOC);

    $firstDay = [];
    $res = $conn->query("SELECT EmployeeID, MIN(Date) AS d FROM timepunches WHERE TimeIN IS NOT NULL GROUP BY EmployeeID");
    while ($r = $res->fetch_assoc()) {
        $firstDay[(int) $r['EmployeeID']] = $r['d'];
    }

    // Days worked (or on approved time off) around the holidays, for the before/after rule
    $worked = [];
    if ($policy['requireAdjacent']) {
        $from = (new DateTime(array_key_first($holidays)))->modify('-21 days')->format('Y-m-d');
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
    }

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

        foreach ($holidays as $date => $name) {
            if (!isset($firstDay[$eid]) || $firstDay[$eid] > $date) {
                continue; // not on the clock yet
            }
            if (!$runs($date) && $policy['dayOff'] === 'none') {
                continue; // the holiday falls on this employee's day off
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
            $hours = ($policy['mode'] === 'shift' && $shift) ? shiftScheduledHours($shift) : $policy['fixedHours'];
            if ($hours <= 0) {
                continue;
            }
            $out[$eid]['days'][$date] = ['name' => $name, 'hours' => $hours];
            $out[$eid]['hours'] = round(($out[$eid]['hours'] ?? 0) + $hours, 2);
        }
    }
    return $out;
}
