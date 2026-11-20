<?php
/**
 * functions/payroll_totals.php
 *
 * The figures behind admin/payroll_totals.php: one line per employee for a date range
 * (regular, overtime, PTO, sick, holiday, total), and the copy saved when payroll is
 * closed.
 *
 * Why save a copy: closing payroll locks punches and time-off entries, but PTO, Sick and
 * Holiday hours are worked out from the holiday list and holiday-pay settings each time.
 * Adding or removing a holiday, or turning holiday pay on, would otherwise change the
 * totals of a period that has already been paid. Payroll Totals shows the saved copy
 * for a closed period and marks employees whose current figures differ.
 * Tables: payroll_closes, payroll_close_lines (migration 2026_11_22_payroll_close_totals).
 */

require_once __DIR__ . '/hours.php';          // weeklyOvertime()
require_once __DIR__ . '/time_off_hours.php'; // timeOffTotalsByEmployee()
require_once __DIR__ . '/holiday_pay.php';    // holidayPayPolicy(), holidayPayByEmployee()

/** Hour columns of a payroll line, in report order. */
const PAYROLL_HOUR_KEYS = ['regular', 'ot', 'pto', 'sick', 'holiday', 'total'];

/**
 * Current payroll totals for [$start, $end].
 * @return array{rows:array<int,array>,grand:array<string,float>,unresolved:array<int,int>,showHoliday:bool}
 *   rows keyed by employee ID: name, regular, ot, pto, sick, holiday, holidayDays, total, review, id
 */
function payrollTotals(mysqli $conn, string $start, string $end): array
{
    // Paid hours per employee per day
    $stmt = $conn->prepare("
        SELECT tp.EmployeeID, tp.Date, SUM(GREATEST(COALESCE(tp.TotalHours, 0), 0)) AS Hours
          FROM timepunches tp
         WHERE tp.TimeIN IS NOT NULL AND tp.TimeOut IS NOT NULL
           AND tp.Date BETWEEN ? AND ?
         GROUP BY tp.EmployeeID, tp.Date
    ");
    $stmt->bind_param("ss", $start, $end);
    $stmt->execute();
    $daily = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $daily[(int) $r['EmployeeID']][$r['Date']] = (float) $r['Hours'];
    }
    $stmt->close();

    // Punches that can't be paid yet: still open, or clocked out with hours awaiting review
    $stmt = $conn->prepare("
        SELECT EmployeeID, COUNT(*) AS n
          FROM timepunches
         WHERE Date BETWEEN ? AND ? AND TimeIN IS NOT NULL AND (TimeOut IS NULL OR TotalHours IS NULL)
         GROUP BY EmployeeID
    ");
    $stmt->bind_param("ss", $start, $end);
    $stmt->execute();
    $unresolved = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $unresolved[(int) $r['EmployeeID']] = (int) $r['n'];
    }
    $stmt->close();

    $timeOff = timeOffTotalsByEmployee($conn, $start, $end);
    $showHoliday = holidayPayPolicy($conn)['enabled'];
    $holidayPay  = $showHoliday ? holidayPayByEmployee($conn, $start, $end) : [];

    $rows = [];
    $grand = array_fill_keys(PAYROLL_HOUR_KEYS, 0.0);
    $users = $conn->query("SELECT ID, FirstName, LastName FROM users ORDER BY LastName, FirstName");
    while ($u = $users->fetch_assoc()) {
        $id = (int) $u['ID'];
        if (!isset($daily[$id]) && !isset($timeOff[$id]) && !isset($unresolved[$id]) && !isset($holidayPay[$id])) {
            continue; // nothing to pay this period
        }
        $ot   = weeklyOvertime($daily[$id] ?? []);
        $pto  = round($timeOff[$id]['PTO'] ?? 0.0, 2);
        $sick = round($timeOff[$id]['Sick'] ?? 0.0, 2);
        $hol  = round($holidayPay[$id]['hours'] ?? 0.0, 2);
        $row = [
            'name'        => $u['LastName'] . ', ' . $u['FirstName'],
            'regular'     => $ot['totalRegular'],
            'ot'          => $ot['totalOT'],
            'pto'         => $pto,
            'sick'        => $sick,
            'holiday'     => $hol,
            'holidayDays' => $holidayPay[$id]['days'] ?? [],
            'total'       => round($ot['totalHours'] + $pto + $sick + $hol, 2),
            'review'      => $unresolved[$id] ?? 0,
            'id'          => $id,
        ];
        foreach (PAYROLL_HOUR_KEYS as $k) {
            $grand[$k] += $row[$k];
        }
        $rows[$id] = $row;
    }
    return ['rows' => $rows, 'grand' => $grand, 'unresolved' => $unresolved, 'showHoliday' => $showHoliday];
}

/**
 * Save the totals for [$start, $end] as closed. Returns the close ID, or null when the
 * tables don't exist yet (migration not applied): the lock still applies without them.
 */
function savePayrollClose(mysqli $conn, string $start, string $end, array $totals, ?int $byID, ?string $byName): ?int
{
    try {
        $conn->begin_transaction();
        $holidayPay = $totals['showHoliday'] ? 1 : 0;
        $now = date('Y-m-d H:i:s');
        $st = $conn->prepare("INSERT INTO payroll_closes (PeriodStart, PeriodEnd, ClosedAt, ClosedByID, ClosedByName, HolidayPay) VALUES (?, ?, ?, ?, ?, ?)");
        $st->bind_param("sssisi", $start, $end, $now, $byID, $byName, $holidayPay);
        $st->execute();
        $closeID = (int) $conn->insert_id;
        $st = $conn->prepare("INSERT INTO payroll_close_lines (CloseID, EmployeeID, EmployeeName, Regular, Overtime, PTO, Sick, Holiday, Total, Unfinished, HolidayDays)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($totals['rows'] as $r) {
            $days = $r['holidayDays'] ? json_encode($r['holidayDays']) : null;
            $st->bind_param("iisddddddis", $closeID, $r['id'], $r['name'], $r['regular'], $r['ot'], $r['pto'], $r['sick'], $r['holiday'], $r['total'], $r['review'], $days);
            $st->execute();
        }
        $conn->commit();
        return $closeID;
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        error_log('Payroll close totals not saved: ' . $e->getMessage());
        return null;
    }
}

/**
 * The most recent saved close for exactly [$start, $end], in payrollTotals()' shape plus
 * 'close' (the payroll_closes row), or null.
 */
function loadPayrollClose(mysqli $conn, string $start, string $end): ?array
{
    try {
        $st = $conn->prepare("SELECT * FROM payroll_closes WHERE PeriodStart = ? AND PeriodEnd = ? ORDER BY ClosedAt DESC, ID DESC LIMIT 1");
        $st->bind_param("ss", $start, $end);
        $st->execute();
        $close = $st->get_result()->fetch_assoc();
        if (!$close) {
            return null;
        }
        $st = $conn->prepare("SELECT * FROM payroll_close_lines WHERE CloseID = ? ORDER BY EmployeeName");
        $id = (int) $close['ID'];
        $st->bind_param("i", $id);
        $st->execute();
        $lines = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (mysqli_sql_exception $e) {
        return null; // migration not applied yet
    }
    $rows = [];
    $grand = array_fill_keys(PAYROLL_HOUR_KEYS, 0.0);
    $unresolved = [];
    foreach ($lines as $l) {
        $eid = (int) $l['EmployeeID'];
        $row = [
            'name' => $l['EmployeeName'], 'regular' => (float) $l['Regular'], 'ot' => (float) $l['Overtime'],
            'pto' => (float) $l['PTO'], 'sick' => (float) $l['Sick'], 'holiday' => (float) $l['Holiday'],
            'holidayDays' => json_decode((string) $l['HolidayDays'], true) ?: [],
            'total' => (float) $l['Total'], 'review' => (int) $l['Unfinished'], 'id' => $eid,
        ];
        foreach (PAYROLL_HOUR_KEYS as $k) {
            $grand[$k] += $row[$k];
        }
        if ($row['review']) {
            $unresolved[$eid] = $row['review'];
        }
        $rows[$eid] = $row;
    }
    $showHoliday = (int) $close['HolidayPay'] === 1 || $grand['holiday'] > 0;
    return ['rows' => $rows, 'grand' => $grand, 'unresolved' => $unresolved, 'showHoliday' => $showHoliday, 'close' => $close];
}

/**
 * Employees whose current figures differ from the saved ones:
 * [employeeID => ['regular' => [saved, now], ...only the columns that changed]].
 */
function payrollCloseChanges(array $saved, array $live): array
{
    $changes = [];
    foreach (array_unique(array_merge(array_keys($saved['rows']), array_keys($live['rows']))) as $eid) {
        foreach (PAYROLL_HOUR_KEYS as $k) {
            $a = (float) ($saved['rows'][$eid][$k] ?? 0);
            $b = (float) ($live['rows'][$eid][$k] ?? 0);
            if (abs($a - $b) >= 0.005) {
                $changes[$eid][$k] = [$a, $b];
            }
        }
    }
    return $changes;
}
