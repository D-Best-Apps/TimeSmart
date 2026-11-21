<?php
// Shared time-off hour calculations.
// Used by the summary report (HTML + PDF), per-employee detail pages, and the
// employee timesheet view to surface approved Sick/PTO hours within a date range.

require_once __DIR__ . '/hours.php';     // canonical payPeriodStart/End + read-stored-hours conventions
require_once __DIR__ . '/holidays.php';  // canonical workday / observed-holiday calendar
require_once __DIR__ . '/pay_rules.php'; // listShifts(), shiftRunsOn(), shiftScheduledHours()

/**
 * Time-off categories and how they're shown. 'Unpaid' (excused) and 'Unexcused' are
 * absences an admin records (admin/record_time_off.php): they pay 0 hours, but the day
 * counts as accounted for, so it isn't flagged as a no-show. 'Unexcused' is shown as
 * such on the board and attendance.
 */
const TIME_OFF_LABELS = ['PTO' => 'PTO', 'Sick' => 'Sick', 'Unpaid' => 'Excused absence (unpaid)', 'Unexcused' => 'Unexcused absence'];
const TIME_OFF_UNPAID = ['Unpaid', 'Unexcused'];

function timeOffLabel(string $category): string
{
    return TIME_OFF_LABELS[$category] ?? $category;
}

/** Short label for tight spaces (board cells, calendar chips). */
function timeOffShortLabel(string $category): string
{
    return ['Unpaid' => 'Excused', 'Unexcused' => 'Unexcused'][$category] ?? $category;
}

/** Does this time-off request pay hours? (Sick and PTO do; absences don't.) */
function timeOffIsPaid(array $request): bool
{
    return !in_array($request['Category'] ?? '', TIME_OFF_UNPAID, true);
}

/** Is the Sick category offered (Settings -> Pay Rules -> Time off)? */
function timeOffSickEnabled(): bool
{
    return payRuleSetting('TimeOffSickEnabled', '1') !== '0';
}

/**
 * Categories to offer on a time-off form: [value => label]. Employees get PTO (and Sick
 * when it's on); admins also get the two absence types. $keep is the current value of
 * an entry being edited, kept even if it's no longer offered.
 */
function timeOffCategories(bool $forAdmin, ?string $keep = null): array
{
    $out = ['PTO' => TIME_OFF_LABELS['PTO']];
    if (timeOffSickEnabled() || $keep === 'Sick') {
        $out['Sick'] = TIME_OFF_LABELS['Sick'];
    }
    if ($forAdmin || in_array($keep, TIME_OFF_UNPAID, true)) {
        $out['Unpaid']    = TIME_OFF_LABELS['Unpaid'];
        $out['Unexcused'] = TIME_OFF_LABELS['Unexcused'];
    }
    return $out;
}

/**
 * How many hours a full day of time off pays (Settings -> Pay Rules -> Time off):
 *   'fixed'  TimeOffDayHours (default 8) on Mon-Fri
 *   'shift'  the employee's shift hours less lunch, on the shift's days; employees
 *            without a shift fall back to 'fixed'
 * @return array{mode:string,hours:float}
 */
function timeOffDayPolicy(): array
{
    $h = (float) payRuleSetting('TimeOffDayHours', '8');
    return [
        'mode'  => payRuleSetting('TimeOffDayHoursMode', 'fixed') === 'shift' ? 'shift' : 'fixed',
        'hours' => ($h > 0 && $h <= 24) ? $h : 8.0,
    ];
}

/** The employee's shift row for time-off purposes, or null. Uses $request['ShiftID'] when present. */
function timeOffEmployeeShift(array $request): ?array
{
    static $shifts = null;
    static $userShift = [];
    $conn = ($GLOBALS['conn'] ?? null) instanceof mysqli ? $GLOBALS['conn'] : null;
    if ($conn === null) {
        return null; // CLI tests without a database
    }
    if ($shifts === null) {
        try {
            $shifts = listShifts($conn, true);
        } catch (mysqli_sql_exception $e) {
            $shifts = [];
        }
    }
    if (array_key_exists('ShiftID', $request)) {
        $sid = $request['ShiftID'];
    } else {
        $eid = (int) ($request['EmployeeID'] ?? 0);
        if (!array_key_exists($eid, $userShift)) {
            $st = $conn->prepare("SELECT ShiftID FROM users WHERE ID = ?");
            $st->bind_param("i", $eid);
            $st->execute();
            $userShift[$eid] = $st->get_result()->fetch_row()[0] ?? null;
            $st->close();
        }
        $sid = $userShift[$eid];
    }
    return $sid !== null ? ($shifts[(int) $sid] ?? null) : null;
}

/**
 * How a request is charged, day by day:
 *   hoursPerDay  hours each charged day pays (0 for an unpaid absence)
 *   asGiven      true for a time window: every date in the range counts, weekends
 *                included (the employee picked specific hours on specific days)
 *   shift        with 'shift' mode, the employee's shift: full days and hours-per-day
 *                requests are charged on its days instead of Mon-Fri
 *   dayHours     a full day's hours for this employee (0 for a time window)
 * A part day entered as a number of hours (Hours, no times) is charged like a full day,
 * just for that many hours.
 * @return array{hoursPerDay:float,asGiven:bool,shift:?array,dayHours:float}
 */
function timeOffDayPlan(array $request, ?float $defaultDayHours = null): array
{
    $hasTime = !empty($request['StartTime']) && !empty($request['EndTime']);
    $shift = null;
    $dayHours = 0.0;
    if ($hasTime) {
        // The window repeats each day; an end before the start (older entries, before
        // multi-day requests were checked) pays nothing rather than negative hours.
        $hoursPerDay = max(0.0, (strtotime("1970-01-01 " . $request['EndTime']) - strtotime("1970-01-01 " . $request['StartTime'])) / 3600.0);
    } else {
        $policy = timeOffDayPolicy();
        if ($defaultDayHours === null && $policy['mode'] === 'shift') {
            $shift = timeOffEmployeeShift($request);
        }
        $dayHours = (float) ($defaultDayHours ?? ($shift && shiftScheduledHours($shift) > 0 ? shiftScheduledHours($shift) : $policy['hours']));
        $hoursPerDay = (float) ($request['Hours'] ?? 0) > 0 ? (float) $request['Hours'] : (float) $dayHours;
    }
    if (!timeOffIsPaid($request)) {
        $hoursPerDay = 0.0;
    }
    return ['hoursPerDay' => $hoursPerDay, 'asGiven' => $hasTime, 'shift' => $shift, 'dayHours' => $dayHours];
}

/** Is $date charged under $plan (timeOffDayPlan())? */
function timeOffChargedDay(array $plan, string $date): bool
{
    if ($plan['asGiven']) {
        return true;
    }
    if ($plan['shift']) {
        return shiftRunsOn($plan['shift'], $date) && timeOffHolidayCharged($date);
    }
    return isTimeOffDay($date);
}

/**
 * Hours an admin set by hand for single days of time-off entries (admin timesheet, Edit;
 * table time_off_day_hours): [RequestID => [date => [hours, note, by, at]]].
 * For PTO/Sick the hours are what the day pays (0 = nothing); for an absence, the hours
 * missed (shown only). Loaded once per request; pass $reload after changing the table.
 * [] before the migration, or without a database.
 */
function timeOffDayHours(bool $reload = false): array
{
    static $cache = null;
    if ($reload) {
        $cache = null;
    }
    if ($cache !== null) {
        return $cache;
    }
    $conn = ($GLOBALS['conn'] ?? null) instanceof mysqli ? $GLOBALS['conn'] : null;
    if ($conn === null) {
        return []; // CLI tests without a database
    }
    $cache = [];
    try {
        $res = $conn->query("SELECT RequestID, Date, Hours, Note, AdjustedBy, AdjustedAt FROM time_off_day_hours");
        foreach ($res->fetch_all(MYSQLI_ASSOC) as $r) {
            $cache[(int) $r['RequestID']][$r['Date']] = [
                'hours' => round((float) $r['Hours'], 2), 'note' => (string) ($r['Note'] ?? ''),
                'by' => (string) ($r['AdjustedBy'] ?? ''), 'at' => (string) $r['AdjustedAt'],
            ];
        }
    } catch (mysqli_sql_exception $e) {
        // migration not applied yet
    }
    return $cache;
}

/** The hours set by hand for this entry on $date, or null. */
function timeOffDayOverride(array $request, string $date): ?array
{
    $id = (int) ($request['ID'] ?? 0);
    if ($id <= 0 || $date < ($request['StartDate'] ?? '') || $date > ($request['EndDate'] ?? '')) {
        return null;
    }
    return timeOffDayHours()[$id][$date] ?? null;
}

/**
 * Hours a request pays on $date under $plan (timeOffDayPlan()): hoursPerDay on a charged
 * day, otherwise 0, except on a paid holiday that pays less than the employee's full day
 * (a 10-hour shift with 8 holiday hours): there a request covering that day pays the rest
 * of the day, at most the hours asked for. Only with holiday pay on; a time window is
 * always taken as given.
 * Hours an admin set for that day (timeOffDayHours()) win over all of this, on any day
 * of the entry; $byHand = false gives the hours by the rules alone.
 */
function timeOffHoursOnDay(array $plan, array $request, string $date, bool $byHand = true): float
{
    if ($byHand && ($o = timeOffDayOverride($request, $date)) !== null) {
        return timeOffIsPaid($request) ? $o['hours'] : 0.0;
    }
    if (timeOffChargedDay($plan, $date)) {
        return $plan['hoursPerDay'];
    }
    if ($plan['asGiven'] || $plan['hoursPerDay'] <= 0 || isCompanyHoliday($date) === null) {
        return 0.0;
    }
    $works = $plan['shift'] ? shiftRunsOn($plan['shift'], $date) : (int) date('N', strtotime($date)) < 6;
    if (!$works) {
        return 0.0;
    }
    $holiday = timeOffHolidayHours((int) ($request['EmployeeID'] ?? 0), $date);
    if ($holiday === null) {
        return 0.0;
    }
    return round(max(0.0, min($plan['hoursPerDay'], $plan['dayHours'] - $holiday)), 2);
}

/**
 * The holiday hours an employee is due for the paid holiday on $date: their own Holiday
 * hours, else the setting (shift hours less lunch, or the fixed hours), or an admin's
 * hand-set hours for that day. The before/after rule isn't applied: the rest of the day
 * is the same either way. Null while holiday pay is off, or without a database.
 */
function timeOffHolidayHours(int $employeeId, string $date): ?float
{
    static $policy = null, $users = [], $adjusted = [];
    $conn = ($GLOBALS['conn'] ?? null) instanceof mysqli ? $GLOBALS['conn'] : null;
    if ($conn === null || $employeeId <= 0) {
        return null;
    }
    require_once __DIR__ . '/holiday_pay.php';
    $policy ??= holidayPayPolicy($conn);
    if (!$policy['enabled']) {
        return null;
    }
    $key = $employeeId . '|' . $date;
    if (!array_key_exists($key, $adjusted)) {
        $adjusted[$key] = holidayPayAdjustments($conn, $date, $date)[$employeeId][$date]['hours'] ?? null;
    }
    if ($adjusted[$key] !== null) {
        return (float) $adjusted[$key];
    }
    if (!array_key_exists($employeeId, $users)) {
        try {
            $st = $conn->prepare("SELECT ID, ShiftID, HolidayHours FROM users WHERE ID = ?");
        } catch (mysqli_sql_exception $e) {
            $st = $conn->prepare("SELECT ID, ShiftID, NULL AS HolidayHours FROM users WHERE ID = ?");
        }
        $st->bind_param("i", $employeeId);
        $st->execute();
        $users[$employeeId] = $st->get_result()->fetch_assoc();
        $st->close();
    }
    $u = $users[$employeeId];
    if (!$u) {
        return null;
    }
    $shift = $u['ShiftID'] !== null ? (listShifts($conn, true)[(int) $u['ShiftID']] ?? null) : null;
    return holidayHoursFor($u, $shift, $policy);
}

/** A part day entered as hours, or a time window (not a full day)? */
function timeOffIsPartDay(array $request): bool
{
    return (!empty($request['StartTime']) && !empty($request['EndTime'])) || (float) ($request['Hours'] ?? 0) > 0;
}

/** "PTO", "PTO 2h", "Sick 9am–1pm": category plus the part-day detail. */
function timeOffDescribe(array $request, bool $short = true): string
{
    $label = $short ? timeOffShortLabel($request['Category']) : timeOffLabel($request['Category']);
    if (!empty($request['StartTime']) && !empty($request['EndTime'])) {
        $f = fn($t) => str_replace(':00', '', date('g:ia', strtotime($t)));
        return $label . ' ' . $f($request['StartTime']) . '–' . $f($request['EndTime']);
    }
    if ((float) ($request['Hours'] ?? 0) > 0) {
        return $label . ' ' . rtrim(rtrim(number_format((float) $request['Hours'], 2), '0'), '.') . 'h';
    }
    return $label;
}

/**
 * Compute hours for a single approved time-off request, intersected with [periodStart, periodEnd].
 *
 * Full-day requests pay a day's hours (timeOffDayPolicy()) per charged day: Mon-Fri, or
 * the employee's shift days in 'shift' mode. Observed company holidays are skipped,
 * because the office is closed and no PTO is consumed. A Thu-to-the-following-Tue
 * request is 3 days of PTO, not 6.
 * Exception: with holiday pay on, an unpaid holiday is charged like a workday
 * (timeOffHolidayCharged()), so PTO can cover a closure day the company doesn't pay,
 * and a paid holiday shorter than the employee's day is charged for the rest of the
 * day (timeOffHoursOnDay()).
 *
 * Partial-day requests with times (StartTime + EndTime) count the time delta for every
 * day in the range as given, weekends included. Partial days entered as hours (Hours)
 * count those hours on the same days a full day would.
 *
 * $defaultDayHours forces the full-day hours (tests); null = the setting.
 * Returns a float of decimal hours; caller can convert to H:MM.
 */
function timeOffHoursInPeriod(array $request, string $periodStart, string $periodEnd, ?float $defaultDayHours = null): float
{
    if (!timeOffIsPaid($request)) {
        return 0.0; // unpaid absence
    }
    $reqStart = $request['StartDate'] > $periodStart ? $request['StartDate'] : $periodStart;
    $reqEnd   = $request['EndDate']   < $periodEnd   ? $request['EndDate']   : $periodEnd;
    if ($reqStart > $reqEnd) {
        return 0.0;
    }
    $plan = timeOffDayPlan($request, $defaultDayHours);
    $hours = 0.0;
    for ($d = new DateTime($reqStart), $fin = new DateTime($reqEnd); $d <= $fin; $d->modify('+1 day')) {
        $hours += timeOffHoursOnDay($plan, $request, $d->format('Y-m-d'));
    }
    return $hours;
}

/**
 * Fetch all approved time-off requests for the period (optionally filtered by employee).
 * Returns rows with the full set of columns; caller decides how to bucket them by Category.
 * Returns requests whose date range overlaps [periodStart, periodEnd].
 */
function fetchApprovedTimeOff(mysqli $conn, string $periodStart, string $periodEnd, ?int $employeeID = null): array
{
    // ShiftID: the shift the entry was frozen to (0 = none), else the employee's current one
    $sql = "
        SELECT tor.*, u.FirstName, u.LastName,
               CASE WHEN tor.ShiftID IS NULL THEN u.ShiftID WHEN tor.ShiftID = 0 THEN NULL ELSE tor.ShiftID END AS ShiftID
          FROM time_off_requests tor
          JOIN users u ON u.ID = tor.EmployeeID
         WHERE tor.Status = 'Approved'
           AND NOT (tor.EndDate < ? OR tor.StartDate > ?)
    ";
    $params = [$periodStart, $periodEnd];
    $types  = 'ss';
    if ($employeeID !== null) {
        $sql .= " AND tor.EmployeeID = ?";
        $params[] = $employeeID;
        $types   .= 'i';
    }
    $sql .= " ORDER BY tor.EmployeeID, tor.StartDate";

    try {
        $stmt = $conn->prepare($sql);
    } catch (mysqli_sql_exception $e) {
        // time_off_requests.ShiftID not migrated yet: the current shift, as before
        $stmt = $conn->prepare(preg_replace('/CASE WHEN .*? END AS ShiftID/s', 'u.ShiftID', $sql));
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Call BEFORE changing employees' shifts (users.ShiftID): their time off dated before
 * today that still follows the current shift is frozen to it, so the change only
 * affects time off from today on. Without the column (not migrated) it does nothing.
 * @param int[] $employeeIDs
 */
function freezeTimeOffShifts(mysqli $conn, array $employeeIDs): void
{
    $today = date('Y-m-d');
    try {
        $st = $conn->prepare("
            UPDATE time_off_requests tor
              JOIN users u ON u.ID = tor.EmployeeID
               SET tor.ShiftID = COALESCE(u.ShiftID, 0)
             WHERE tor.EmployeeID = ? AND tor.ShiftID IS NULL AND tor.StartDate < ?
        ");
        foreach ($employeeIDs as $eid) {
            $eid = (int) $eid;
            $st->bind_param("is", $eid, $today);
            $st->execute();
        }
    } catch (mysqli_sql_exception $e) {
        error_log('freezeTimeOffShifts: ' . $e->getMessage());
    }
}

/**
 * Group approved time-off into per-employee Sick/PTO buckets for a period.
 * Returns: [employeeID => ['Sick' => float hours, 'PTO' => float hours]]
 */
function timeOffTotalsByEmployee(mysqli $conn, string $periodStart, string $periodEnd, ?int $employeeID = null, ?float $defaultDayHours = null): array
{
    $rows = fetchApprovedTimeOff($conn, $periodStart, $periodEnd, $employeeID);
    $totals = [];
    foreach ($rows as $r) {
        if (!timeOffIsPaid($r)) {
            continue; // absences pay nothing (and don't give the employee an all-zero payroll line)
        }
        $empId = (int) $r['EmployeeID'];
        if (!isset($totals[$empId])) {
            $totals[$empId] = ['Sick' => 0.0, 'PTO' => 0.0];
        }
        $hours = timeOffHoursInPeriod($r, $periodStart, $periodEnd, $defaultDayHours);
        $bucket = $r['Category'] === 'Sick' ? 'Sick' : 'PTO';
        $totals[$empId][$bucket] += $hours;
    }
    return $totals;
}

/**
 * For each work week overlapping [periodStart, periodEnd], compute how many
 * hours of time-off would need to be trimmed to keep the weekly total at the
 * overtime threshold (Admin > Pay Rules, default 40)
 * or under. This is the bookkeeper's "adjust this down" signal on the summary
 * report — it directly answers "how much PTO/Sick do I need to cut?"
 *
 * For each period: allowable_TO = max(0, threshold - clocked); excess = max(0, TO - allowable).
 * If clocked is already at/over the threshold from work, the entire TO is excess (because
 * TO can't legitimately push past it).
 *
 * Returns periods where excess > 0:
 *   [['weekStart' => 'YYYY-MM-DD', 'clocked' => float, 'timeOff' => float, 'excess' => float], ...]
 */
function weeklyTimeOffExcess(mysqli $conn, int $empID, string $periodStart, string $periodEnd, ?float $defaultDayHours = null): array {
    $result = [];
    $seen   = [];
    $cursor = new DateTime($periodStart);
    $endDt  = new DateTime($periodEnd);
    while ($cursor <= $endDt) {
        $wsStr = payPeriodStart($cursor->format('Y-m-d')); // work week (Admin > Pay Rules)
        $weStr = payPeriodEnd($cursor->format('Y-m-d'));
        if (isset($seen[$wsStr])) { $cursor->modify('+7 days'); continue; }
        $seen[$wsStr] = true;

        // Clocked hours that period (canonical stored column)
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(GREATEST(COALESCE(TotalHours, 0), 0)), 0) AS Total
              FROM timepunches
             WHERE EmployeeID = ? AND TimeIN IS NOT NULL AND TimeOut IS NOT NULL
               AND Date BETWEEN ? AND ?
        ");
        $stmt->bind_param("iss", $empID, $wsStr, $weStr);
        $stmt->execute();
        $clocked = (float) ($stmt->get_result()->fetch_assoc()['Total'] ?? 0);

        // Approved time-off that week
        $rows = fetchApprovedTimeOff($conn, $wsStr, $weStr, $empID);
        $timeOff = 0.0;
        foreach ($rows as $r) {
            $timeOff += timeOffHoursInPeriod($r, $wsStr, $weStr, $defaultDayHours);
        }

        $allowable = max(0.0, overtimeWeeklyHours() - $clocked);
        $excess    = max(0.0, $timeOff - $allowable);

        if ($excess > 0.001) {
            $result[] = [
                'weekStart' => $wsStr,
                'clocked'   => $clocked,
                'timeOff'   => $timeOff,
                'excess'    => $excess,
            ];
        }

        $cursor->modify('+7 days');
    }
    return $result;
}

/**
 * For a proposed time-off request (could be new or an amendment), compute the
 * projected weekly hour totals (clocked + already-approved time-off + this
 * request) for every work week the request touches. Used to warn the
 * employee / admin when approval would push the week into overtime.
 *
 * Returns: [
 *   [
 *     'weekStart' => 'YYYY-MM-DD' (first day of the work week),
 *     'weekEnd'   => 'YYYY-MM-DD' (last day),
 *     'clocked'   => float,
 *     'priorTimeOff' => float,   // approved Sick/PTO already on record (excludes $excludeRequestID)
 *     'thisRequest'  => float,   // hours this request contributes to this week
 *     'projected'    => float,
 *   ], ...
 * ]
 *
 * @param ?int $excludeRequestID When evaluating an amendment, pass the ORIGINAL
 *                               request's ID so its hours don't double-count.
 */
function projectedWeeklyHours(
    mysqli $conn,
    int $empID,
    string $reqStartDate,
    string $reqEndDate,
    ?string $reqStartTime = null,
    ?string $reqEndTime = null,
    ?int $excludeRequestID = null,
    ?float $defaultDayHours = null,
    ?float $reqHours = null
): array {
    // Build a synthetic request row so we can reuse timeOffHoursInPeriod() for the per-week slice.
    $synthetic = [
        'StartDate' => $reqStartDate,
        'EndDate'   => $reqEndDate,
        'StartTime' => $reqStartTime ?: null,
        'EndTime'   => $reqEndTime   ?: null,
        'Hours'     => $reqHours ?: null,
        'EmployeeID' => $empID,
        'Category'  => 'PTO',
    ];

    // Determine the set of work weeks the request spans
    $weeks = [];
    $d = new DateTime($reqStartDate);
    $end = new DateTime($reqEndDate);
    while ($d <= $end) {
        $key = payPeriodStart($d->format('Y-m-d'));
        if (!isset($weeks[$key])) {
            $weeks[$key] = [
                'weekStart' => $key,
                'weekEnd'   => payPeriodEnd($d->format('Y-m-d')),
            ];
        }
        $d->modify('+1 day');
    }

    foreach ($weeks as &$w) {
        // 1. Clocked hours that period (canonical stored column)
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(GREATEST(COALESCE(TotalHours, 0), 0)), 0) AS Total
              FROM timepunches
             WHERE EmployeeID = ? AND TimeIN IS NOT NULL AND TimeOut IS NOT NULL
               AND Date BETWEEN ? AND ?
        ");
        $stmt->bind_param("iss", $empID, $w['weekStart'], $w['weekEnd']);
        $stmt->execute();
        $w['clocked'] = (float) ($stmt->get_result()->fetch_assoc()['Total'] ?? 0);

        // 2. Prior approved Sick/PTO that week (excluding $excludeRequestID if given)
        $sql = "
            SELECT tor.*
              FROM time_off_requests tor
             WHERE tor.EmployeeID = ? AND tor.Status = 'Approved'
               AND NOT (tor.EndDate < ? OR tor.StartDate > ?)
        ";
        $params = [$empID, $w['weekStart'], $w['weekEnd']];
        $types  = "iss";
        if ($excludeRequestID !== null) {
            $sql .= " AND tor.ID <> ?";
            $params[] = $excludeRequestID;
            $types   .= "i";
        }
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $priorTimeOff = 0.0;
        foreach ($rows as $r) {
            $priorTimeOff += timeOffHoursInPeriod($r, $w['weekStart'], $w['weekEnd'], $defaultDayHours);
        }
        $w['priorTimeOff'] = $priorTimeOff;

        // 3. This request's contribution to this week
        $w['thisRequest'] = timeOffHoursInPeriod($synthetic, $w['weekStart'], $w['weekEnd'], $defaultDayHours);

        // 4. Projected total
        $w['projected'] = $w['clocked'] + $w['priorTimeOff'] + $w['thisRequest'];
    }
    unset($w);

    return array_values($weeks);
}

/**
 * Expand approved time-off requests into per-day rows for chronological merging
 * into a daily detail table. Returns array indexed numerically with keys:
 *   Date, Category ('Sick'|'PTO'|'Unpaid'), Hours (float; 0 for Unpaid), StartTime (or null), EndTime (or null)
 *
 * One row per day in the request's range, with hours-per-day applied. Full-day
 * requests emit rows for working days only, so the per-day rows always sum to
 * timeOffHoursInPeriod() for the same range — keep these two in step.
 */
function expandTimeOffToDays(array $request, string $periodStart, string $periodEnd, ?float $defaultDayHours = null): array
{
    $reqStart = $request['StartDate'] > $periodStart ? $request['StartDate'] : $periodStart;
    $reqEnd   = $request['EndDate']   < $periodEnd   ? $request['EndDate']   : $periodEnd;
    if ($reqStart > $reqEnd) {
        return [];
    }

    $plan = timeOffDayPlan($request, $defaultDayHours); // 0 hours for an absence: listed, pays nothing
    $hasTime = $plan['asGiven'];

    $rows = [];
    $d = new DateTime($reqStart);
    $end = new DateTime($reqEnd);
    while ($d <= $end) {
        // Full days (and hours-per-day) only land on charged days, plus the rest of a short
        // paid holiday; time windows are taken as given.
        $date = $d->format('Y-m-d');
        $byHand = timeOffDayOverride($request, $date);
        $dayHours = timeOffHoursOnDay($plan, $request, $date);
        if ($byHand === null && !timeOffChargedDay($plan, $date) && $dayHours <= 0) {
            $d->modify('+1 day');
            continue;
        }
        $partHours = !$hasTime && (float) ($request['Hours'] ?? 0) > 0 ? (float) $request['Hours'] : null;
        if ($byHand !== null && !timeOffIsPaid($request)) {
            $partHours = $byHand['hours'] > 0 ? $byHand['hours'] : null; // an absence: hours missed
        }
        $rows[] = [
            'Date'      => $date,
            'Category'  => $request['Category'],
            'Hours'     => $dayHours,
            'StartTime' => $hasTime ? $request['StartTime'] : null,
            'EndTime'   => $hasTime ? $request['EndTime']   : null,
            'PartHours' => $partHours,
            'Notes'     => $request['Notes'] ?? null,
            'RequestID' => (int) ($request['ID'] ?? 0),
            'RuleHours' => timeOffHoursOnDay($plan, $request, $date, false), // without hours set by hand
            'ByHand'    => $byHand,                                           // [hours, note, by, at] or null
        ];
        $d->modify('+1 day');
    }
    return $rows;
}
