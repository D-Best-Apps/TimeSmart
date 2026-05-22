<?php
// Shared time-off hour calculations.
// Used by the summary report (HTML + PDF), per-employee detail pages, and the
// employee timesheet view to surface approved Sick/PTO hours within a date range.

/**
 * Compute hours for a single approved time-off request, intersected with [periodStart, periodEnd].
 * Full-day requests count as $defaultDayHours per day (default 8).
 * Partial-day requests (StartTime + EndTime set) count as the time delta per day.
 * Returns a float of decimal hours; caller can convert to H:MM.
 */
function timeOffHoursInPeriod(array $request, string $periodStart, string $periodEnd, int $defaultDayHours = 8): float
{
    $reqStart = $request['StartDate'] > $periodStart ? $request['StartDate'] : $periodStart;
    $reqEnd   = $request['EndDate']   < $periodEnd   ? $request['EndDate']   : $periodEnd;
    if ($reqStart > $reqEnd) {
        return 0.0;
    }

    $hasTime = !empty($request['StartTime']) && !empty($request['EndTime']);
    if ($hasTime) {
        $startSec = strtotime("1970-01-01 " . $request['StartTime']);
        $endSec   = strtotime("1970-01-01 " . $request['EndTime']);
        $hoursPerDay = ($endSec - $startSec) / 3600.0;
    } else {
        $hoursPerDay = (float) $defaultDayHours;
    }

    $d1 = new DateTime($reqStart);
    $d2 = new DateTime($reqEnd);
    $days = (int) $d2->diff($d1)->days + 1;

    return $days * $hoursPerDay;
}

/**
 * Fetch all approved time-off requests for the period (optionally filtered by employee).
 * Returns rows with the full set of columns; caller decides how to bucket them by Category.
 * Returns requests whose date range overlaps [periodStart, periodEnd].
 */
function fetchApprovedTimeOff(mysqli $conn, string $periodStart, string $periodEnd, ?int $employeeID = null): array
{
    $sql = "
        SELECT tor.*, u.FirstName, u.LastName
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

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Group approved time-off into per-employee Sick/PTO buckets for a period.
 * Returns: [employeeID => ['Sick' => float hours, 'PTO' => float hours]]
 */
function timeOffTotalsByEmployee(mysqli $conn, string $periodStart, string $periodEnd, ?int $employeeID = null, int $defaultDayHours = 8): array
{
    $rows = fetchApprovedTimeOff($conn, $periodStart, $periodEnd, $employeeID);
    $totals = [];
    foreach ($rows as $r) {
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
 * For each Mon–Sun week overlapping [periodStart, periodEnd], compute how many
 * hours of time-off would need to be trimmed to keep the weekly total at 40
 * or under. This is the bookkeeper's "adjust this down" signal on the summary
 * report — it directly answers "how much PTO/Sick do I need to cut?"
 *
 * For each week: allowable_TO = max(0, 40 - clocked); excess = max(0, TO - allowable).
 * If clocked is already at/over 40 from work, the entire TO is excess (because
 * TO can't legitimately push past 40).
 *
 * Returns weeks where excess > 0:
 *   [['weekStart' => 'YYYY-MM-DD', 'clocked' => float, 'timeOff' => float, 'excess' => float], ...]
 */
function weeklyTimeOffExcess(mysqli $conn, int $empID, string $periodStart, string $periodEnd, int $defaultDayHours = 8): array {
    $result = [];
    $seen   = [];
    $cursor = new DateTime($periodStart);
    $endDt  = new DateTime($periodEnd);
    while ($cursor <= $endDt) {
        $ws = (clone $cursor)->modify('monday this week');
        $we = (clone $ws)->modify('+6 days');
        $wsStr = $ws->format('Y-m-d');
        if (isset($seen[$wsStr])) { $cursor->modify('+7 days'); continue; }
        $seen[$wsStr] = true;
        $weStr = $we->format('Y-m-d');

        // Clocked hours that week
        $stmt = $conn->prepare("
            SELECT COALESCE(SEC_TO_TIME(SUM(
                       TIME_TO_SEC(TIMEDIFF(TimeOut, TimeIN))
                     - TIME_TO_SEC(TIMEDIFF(IFNULL(LunchEnd,'00:00:00'), IFNULL(LunchStart,'00:00:00')))
                   )), '00:00:00') AS Hms
              FROM timepunches
             WHERE EmployeeID = ? AND TimeIN IS NOT NULL AND TimeOut IS NOT NULL
               AND Date BETWEEN ? AND ?
        ");
        $stmt->bind_param("iss", $empID, $wsStr, $weStr);
        $stmt->execute();
        $hms = $stmt->get_result()->fetch_assoc()['Hms'] ?? '00:00:00';
        $p = explode(':', $hms);
        $clocked = $p[0] + ($p[1] / 60) + ($p[2] / 3600);

        // Approved time-off that week
        $rows = fetchApprovedTimeOff($conn, $wsStr, $weStr, $empID);
        $timeOff = 0.0;
        foreach ($rows as $r) {
            $timeOff += timeOffHoursInPeriod($r, $wsStr, $weStr, $defaultDayHours);
        }

        $allowable = max(0.0, 40 - $clocked);
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
 * request) for every Mon–Sun week the request touches. Used to warn the
 * employee / admin when approval would push the week over 40 hours.
 *
 * Returns: [
 *   [
 *     'weekStart' => 'YYYY-MM-DD' (Monday),
 *     'weekEnd'   => 'YYYY-MM-DD' (Sunday),
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
    int $defaultDayHours = 8
): array {
    // Build a synthetic request row so we can reuse timeOffHoursInPeriod() for the per-week slice.
    $synthetic = [
        'StartDate' => $reqStartDate,
        'EndDate'   => $reqEndDate,
        'StartTime' => $reqStartTime ?: null,
        'EndTime'   => $reqEndTime   ?: null,
    ];

    // Determine the set of Mon–Sun weeks the request spans
    $weeks = [];
    $d = new DateTime($reqStartDate);
    $end = new DateTime($reqEndDate);
    while ($d <= $end) {
        $weekStart = (clone $d)->modify('monday this week');
        $weekEnd   = (clone $weekStart)->modify('+6 days');
        $key = $weekStart->format('Y-m-d');
        if (!isset($weeks[$key])) {
            $weeks[$key] = [
                'weekStart' => $weekStart->format('Y-m-d'),
                'weekEnd'   => $weekEnd->format('Y-m-d'),
            ];
        }
        $d->modify('+1 day');
    }

    foreach ($weeks as &$w) {
        // 1. Clocked hours that week
        $stmt = $conn->prepare("
            SELECT COALESCE(SEC_TO_TIME(SUM(
                       TIME_TO_SEC(TIMEDIFF(TimeOut, TimeIN))
                     - TIME_TO_SEC(TIMEDIFF(IFNULL(LunchEnd,'00:00:00'), IFNULL(LunchStart,'00:00:00')))
                   )), '00:00:00') AS Hms
              FROM timepunches
             WHERE EmployeeID = ? AND TimeIN IS NOT NULL AND TimeOut IS NOT NULL
               AND Date BETWEEN ? AND ?
        ");
        $stmt->bind_param("iss", $empID, $w['weekStart'], $w['weekEnd']);
        $stmt->execute();
        $hms = $stmt->get_result()->fetch_assoc()['Hms'] ?? '00:00:00';
        $parts = explode(':', $hms);
        $w['clocked'] = $parts[0] + ($parts[1] / 60) + ($parts[2] / 3600);

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
 *   Date, Category ('Sick'|'PTO'), Hours (float), StartTime (or null), EndTime (or null)
 * One row per day in the request's range, with hours-per-day applied.
 */
function expandTimeOffToDays(array $request, string $periodStart, string $periodEnd, int $defaultDayHours = 8): array
{
    $reqStart = $request['StartDate'] > $periodStart ? $request['StartDate'] : $periodStart;
    $reqEnd   = $request['EndDate']   < $periodEnd   ? $request['EndDate']   : $periodEnd;
    if ($reqStart > $reqEnd) {
        return [];
    }

    $hasTime = !empty($request['StartTime']) && !empty($request['EndTime']);
    if ($hasTime) {
        $startSec = strtotime("1970-01-01 " . $request['StartTime']);
        $endSec   = strtotime("1970-01-01 " . $request['EndTime']);
        $hoursPerDay = ($endSec - $startSec) / 3600.0;
    } else {
        $hoursPerDay = (float) $defaultDayHours;
    }

    $rows = [];
    $d = new DateTime($reqStart);
    $end = new DateTime($reqEnd);
    while ($d <= $end) {
        $rows[] = [
            'Date'      => $d->format('Y-m-d'),
            'Category'  => $request['Category'],
            'Hours'     => $hoursPerDay,
            'StartTime' => $hasTime ? $request['StartTime'] : null,
            'EndTime'   => $hasTime ? $request['EndTime']   : null,
            'Notes'     => $request['Notes'] ?? null,
        ];
        $d->modify('+1 day');
    }
    return $rows;
}
