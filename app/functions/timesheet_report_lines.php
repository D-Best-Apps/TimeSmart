<?php
/**
 * functions/timesheet_report_lines.php — approved time off and holiday hours as lines of
 * the Timesheet Report (admin/timesheet_report.php) and its Excel/PDF exports, between
 * the punches, so the report shows every paid hour and every absence.
 */
require_once __DIR__ . '/time_off_hours.php';
require_once __DIR__ . '/holiday_pay.php';

/**
 * One line per employee per day of approved time off (PTO, Sick, excused/unexcused
 * absences) and of holiday pay (while holiday pay is on and the day pays hours).
 * Line: Date, EmployeeID, FirstName, LastName, kind (PTO|Sick|Unpaid|Unexcused|Holiday),
 * label ("PTO", "PTO (part day)", "Unexcused absence", "Holiday: Thanksgiving"),
 * hours (null for an absence), note (an absence's reason, or "set by hand").
 * @return array{lines:list<array>,totals:array{PTO:float,Sick:float,Holiday:float},holidayPay:bool}
 */
function timesheetReportExtraLines(mysqli $conn, string $start, string $end, ?int $employeeID = null): array
{
    $lines = [];
    $totals = ['PTO' => 0.0, 'Sick' => 0.0, 'Holiday' => 0.0];
    $names = [];
    foreach ($conn->query("SELECT ID, FirstName, LastName FROM users")->fetch_all(MYSQLI_ASSOC) as $u) {
        $names[(int) $u['ID']] = $u;
    }

    $policy = holidayPayPolicy($conn);
    if ($policy['enabled']) {
        foreach (holidayPayByEmployee($conn, $start, $end) as $eid => $pay) {
            if ($employeeID !== null && $eid !== $employeeID) {
                continue;
            }
            foreach ($pay['days'] as $date => $day) {
                if ((float) $day['hours'] <= 0) {
                    continue;
                }
                $lines[] = [
                    'Date' => $date, 'EmployeeID' => $eid,
                    'FirstName' => $names[$eid]['FirstName'] ?? '', 'LastName' => $names[$eid]['LastName'] ?? '',
                    'kind' => 'Holiday', 'label' => 'Holiday: ' . $day['name'], 'hours' => (float) $day['hours'],
                    'note' => !empty($day['adjusted']) ? 'Hours set by an admin' . (!empty($day['note']) ? ': ' . $day['note'] : '') : '',
                ];
                $totals['Holiday'] += (float) $day['hours'];
            }
        }
    }

    foreach (fetchApprovedTimeOff($conn, $start, $end, $employeeID) as $req) {
        foreach (expandTimeOffToDays($req, $start, $end) as $dr) {
            $cat  = $dr['Category'];
            $paid = timeOffIsPaid($req);
            $label = ($paid && $dr['PartHours'] !== null)
                ? timeOffLabel($cat) . ' (part day)'
                : timeOffDescribe(['Category' => $cat, 'StartTime' => $dr['StartTime'], 'EndTime' => $dr['EndTime'], 'Hours' => $dr['PartHours']], false);
            $note = $paid ? '' : trim((string) ($dr['Notes'] ?? '')); // an absence: the reason
            if (!empty($dr['ByHand'])) {
                $note = trim($note . ($note !== '' ? ' · ' : '') . 'Hours set by an admin' . ($dr['ByHand']['note'] !== '' ? ': ' . $dr['ByHand']['note'] : ''));
            }
            $lines[] = [
                'Date' => $dr['Date'], 'EmployeeID' => (int) $req['EmployeeID'],
                'FirstName' => $req['FirstName'], 'LastName' => $req['LastName'],
                'kind' => $cat, 'label' => $label, 'hours' => $paid ? (float) $dr['Hours'] : null, 'note' => $note,
            ];
            if ($paid) {
                $totals[$cat === 'Sick' ? 'Sick' : 'PTO'] += (float) $dr['Hours'];
            }
        }
    }
    return ['lines' => $lines, 'totals' => $totals, 'holidayPay' => $policy['enabled']];
}

/**
 * Punch rows and time-off/holiday lines in one list, newest day first, then by employee
 * (last, first name), punches before the other lines. Each item: ['punch' => row] or
 * ['extra' => line].
 */
function timesheetReportMerge(array $punchRows, array $extraLines): array
{
    $items = [];
    foreach ($punchRows as $i => $r) {
        $items[] = ['key' => [$r['Date'], $r['LastName'], $r['FirstName'], (int) $r['EmployeeID'], 0, (string) ($r['TimeIN'] ?? ''), $i], 'punch' => $r];
    }
    foreach ($extraLines as $i => $x) {
        $items[] = ['key' => [$x['Date'], $x['LastName'], $x['FirstName'], (int) $x['EmployeeID'], 1, $x['kind'], $i], 'extra' => $x];
    }
    usort($items, function ($a, $b) {
        $c = strcmp($b['key'][0], $a['key'][0]); // date, newest first
        if ($c !== 0) {
            return $c;
        }
        return array_slice($a['key'], 1) <=> array_slice($b['key'], 1);
    });
    return $items;
}

/** Background colours for the time-off/holiday lines (as on the admin timesheet). */
const TIMESHEET_REPORT_LINE_COLORS = ['PTO' => 'E8F1FC', 'Sick' => 'FDECEA', 'Unpaid' => 'ECEFF1', 'Unexcused' => 'FFE0B2', 'Holiday' => 'E6F4EA'];
