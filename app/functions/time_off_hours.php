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
