<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../auth/db.php';
require_once __DIR__ . '/tcpdf/tcpdf.php';
require_once __DIR__ . '/../functions/time_off_hours.php';

$start = $_POST['start'] ?? '';
$end = $_POST['end'] ?? '';
$emp = $_POST['emp'] ?? '';
$rounding = intval($_POST['rounding'] ?? 0);
$separatePages = intval($_POST['separate_pages'] ?? 0);

if (!$start || !$end) {
    header('Location: ../error.php?code=400&message=' . urlencode('Start and end dates are required.'));
    exit;
}

function hmsToDecimal($time, $rounding = 0) {
    list($h, $m, $s) = explode(':', $time);
    $minutes = $h * 60 + $m + ($s / 60);
    if ($rounding > 0) {
        $minutes = round($minutes / $rounding) * $rounding;
    }
    return round($minutes / 60, 2);
}

function decimalToHM($decimalHours) {
    $totalMinutes = (int) round($decimalHours * 60);
    $h = intdiv($totalMinutes, 60);
    $m = $totalMinutes % 60;
    return sprintf('%d:%02d', $h, $m);
}

function fmtTime($t) {
    if (!$t || $t === '00:00:00') return '&mdash;';
    return date('g:i a', strtotime($t));
}

// Fetch punches
$sql = "
    SELECT u.FirstName, u.LastName, tp.EmployeeID, tp.Date,
           tp.TimeIN, tp.TimeOUT, tp.LunchStart, tp.LunchEnd,
           SEC_TO_TIME(
               TIME_TO_SEC(TIMEDIFF(tp.TimeOUT, tp.TimeIN)) -
               TIME_TO_SEC(TIMEDIFF(IFNULL(tp.LunchEnd, '00:00:00'), IFNULL(tp.LunchStart, '00:00:00')))
           ) AS TotalHours
    FROM timepunches tp
    JOIN users u ON u.ID = tp.EmployeeID
    WHERE tp.TimeIN IS NOT NULL AND tp.TimeOUT IS NOT NULL
      AND tp.Date BETWEEN ? AND ?
";
$params = [$start, $end];
if (!empty($emp)) {
    $sql .= " AND tp.EmployeeID = ?";
    $params[] = $emp;
}
$sql .= " ORDER BY tp.EmployeeID, tp.Date ASC";

$stmt = $conn->prepare($sql);
$types = str_repeat('s', count($params));
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// Organize rows by employee
$grouped = [];
while ($row = $result->fetch_assoc()) {
    $eid = (int) $row['EmployeeID'];
    $row['RoundedHours'] = hmsToDecimal($row['TotalHours'], $rounding);
    if (!isset($grouped[$eid])) {
        $grouped[$eid] = ['name' => $row['FirstName'] . ' ' . $row['LastName'], 'rows' => []];
    }
    $grouped[$eid]['rows'][] = $row;
}

// Per-employee weekly OT + clocked
$perEmpClocked = [];
$perEmpOT = [];
foreach ($grouped as $eid => $data) {
    $weekTotals = [];
    foreach ($data['rows'] as $r) {
        $d = new DateTime($r['Date']);
        $weekStart = (clone $d)->modify('monday this week')->format('Y-m-d');
        $weekTotals[$weekStart] = ($weekTotals[$weekStart] ?? 0) + $r['RoundedHours'];
    }
    $clocked = 0; $ot = 0;
    foreach ($weekTotals as $hrs) {
        $clocked += $hrs;
        $ot      += max(0, $hrs - 40);
    }
    $perEmpClocked[$eid] = $clocked;
    $perEmpOT[$eid]      = $ot;
}

// Approved time-off in the period
$timeOffTotals    = timeOffTotalsByEmployee($conn, $start, $end, !empty($emp) ? (int)$emp : null);
$timeOffRequests  = fetchApprovedTimeOff   ($conn, $start, $end, !empty($emp) ? (int)$emp : null);
$timeOffByEmpFull = [];
foreach ($timeOffRequests as $req) {
    $timeOffByEmpFull[(int)$req['EmployeeID']][] = $req;
}

// Ensure time-off-only employees appear in $grouped
foreach (array_keys($timeOffTotals) as $eid) {
    if (!isset($grouped[$eid])) {
        $nameStmt = $conn->prepare("SELECT FirstName, LastName FROM users WHERE ID = ?");
        $nameStmt->bind_param("i", $eid);
        $nameStmt->execute();
        if ($u = $nameStmt->get_result()->fetch_assoc()) {
            $grouped[$eid] = ['name' => $u['FirstName'] . ' ' . $u['LastName'], 'rows' => []];
        }
    }
}

// PDF setup
$pdf = new TCPDF();
$pdf->SetCreator('D-Best TimeSmart');
$pdf->SetAuthor('D-Best Technologies');
$pdf->SetTitle('Payroll Summary Report');
$pdf->SetMargins(15, 15, 15);
$pdf->SetFont('helvetica', '', 10);

// Cover page: per-employee summary (only when "All" was selected)
$showSummary = empty($emp) && count($grouped) > 0;
if ($showSummary) {
    $pdf->AddPage();

    $summaryList = [];
    foreach ($grouped as $eid => $data) {
        $clocked  = $perEmpClocked[$eid] ?? 0;
        $ot       = $perEmpOT[$eid]      ?? 0;
        $sick     = $timeOffTotals[$eid]['Sick'] ?? 0;
        $vacation = $timeOffTotals[$eid]['PTO']  ?? 0;
        $excessWeeks = ($sick + $vacation > 0)
            ? weeklyTimeOffExcess($conn, (int) $eid, $start, $end)
            : [];
        $excess = array_sum(array_column($excessWeeks, 'excess'));
        $summaryList[] = [
            'name'     => $data['name'],
            'clocked'  => $clocked,
            'ot'       => $ot,
            'sick'     => $sick,
            'vacation' => $vacation,
            'grand'    => $clocked + $sick + $vacation,
            'excess'   => $excess,
        ];
    }
    usort($summaryList, fn($a, $b) => strcasecmp($a['name'], $b['name']));

    $gClocked  = array_sum(array_column($summaryList, 'clocked'));
    $gOT       = array_sum(array_column($summaryList, 'ot'));
    $gSick     = array_sum(array_column($summaryList, 'sick'));
    $gVacation = array_sum(array_column($summaryList, 'vacation'));
    $gGrand    = array_sum(array_column($summaryList, 'grand'));
    $gExcess   = array_sum(array_column($summaryList, 'excess'));

    $summaryHtml  = '<h2 style="text-align:center; color:#0078D7;">Payroll Summary Report</h2>';
    $summaryHtml .= "<p style='text-align:center;'><strong>Date Range:</strong> "
                  . date("m/d/Y", strtotime($start)) . " to " . date("m/d/Y", strtotime($end)) . "</p>";
    $summaryHtml .= '<h3>Per Employee Total</h3>';
    $summaryHtml .= '<p style="font-size:9pt; color:#555;"><i>Excess = Sick/Vacation hours that need to be trimmed for the weekly total to stay at 40 or under.</i></p>';
    $summaryHtml .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; border-collapse: collapse;">
                        <thead style="background-color: #e6f0ff;">
                            <tr>
                                <th><b>Employee</b></th>
                                <th><b>Clocked</b></th>
                                <th><b>OT</b></th>
                                <th><b>Sick</b></th>
                                <th><b>Vacation</b></th>
                                <th><b>Grand Total</b></th>
                                <th><b>Excess</b></th>
                            </tr>
                        </thead>
                        <tbody>';
    foreach ($summaryList as $item) {
        $hasExcess = $item['excess'] > 0.001;
        $rowBg = $hasExcess ? " style='background-color:#fff3cd;'" : '';
        $excessCell = $hasExcess
            ? "<td style='text-align:right; color:#b02a37; font-weight:bold;'>" . decimalToHM($item['excess']) . " ⚠</td>"
            : "<td style='text-align:right;'>&mdash;</td>";
        $summaryHtml .= "<tr{$rowBg}>
                            <td>" . htmlspecialchars($item['name']) . "</td>
                            <td style='text-align:right;'>" . decimalToHM($item['clocked']) . "</td>
                            <td style='text-align:right;'>" . decimalToHM($item['ot']) . "</td>
                            <td style='text-align:right;'>" . decimalToHM($item['sick']) . "</td>
                            <td style='text-align:right;'>" . decimalToHM($item['vacation']) . "</td>
                            <td style='text-align:right;'><b>" . decimalToHM($item['grand']) . "</b></td>
                            {$excessCell}
                         </tr>";
    }
    $excessTotalCell = $gExcess > 0.001
        ? "<td style='text-align:right; color:#b02a37;'>" . decimalToHM($gExcess) . "</td>"
        : "<td style='text-align:right;'>&mdash;</td>";
    $summaryHtml .= "<tr style='font-weight:bold; background-color:#f1f1f1;'>
                        <td>Grand Total</td>
                        <td style='text-align:right;'>" . decimalToHM($gClocked) . "</td>
                        <td style='text-align:right;'>" . decimalToHM($gOT) . "</td>
                        <td style='text-align:right;'>" . decimalToHM($gSick) . "</td>
                        <td style='text-align:right;'>" . decimalToHM($gVacation) . "</td>
                        <td style='text-align:right;'>" . decimalToHM($gGrand) . "</td>
                        {$excessTotalCell}
                     </tr>";
    $summaryHtml .= '</tbody></table>';

    $pdf->writeHTML($summaryHtml, true, false, true, false, '');
}

// Per-employee detail pages
$first = true;
foreach ($grouped as $eid => $data) {
    // Build merged rows: clocked punches + approved time-off, sorted by date
    $merged = [];
    foreach ($data['rows'] as $r) {
        $merged[] = [
            'Date'   => $r['Date'],
            'Type'   => 'Punch',
            'In'     => $r['TimeIN'],
            'Out'    => $r['TimeOUT'],
            'Hours'  => $r['RoundedHours'],
        ];
    }
    foreach ($timeOffByEmpFull[$eid] ?? [] as $req) {
        foreach (expandTimeOffToDays($req, $start, $end) as $dr) {
            $merged[] = [
                'Date'  => $dr['Date'],
                'Type'  => $dr['Category'] === 'Sick' ? 'Sick' : 'Vacation',
                'In'    => $dr['StartTime'],
                'Out'   => $dr['EndTime'],
                'Hours' => $dr['Hours'],
            ];
        }
    }
    if (empty($merged)) continue;

    usort($merged, fn($a, $b) => strcmp($a['Date'], $b['Date']) ?: strcmp($a['Type'], $b['Type']));

    if ($first) {
        if (!$showSummary || $separatePages) {
            $pdf->AddPage();
        }
        $first = false;
    } elseif ($separatePages) {
        $pdf->AddPage();
    }

    $name = htmlspecialchars($data['name']);
    $clocked  = $perEmpClocked[$eid] ?? 0;
    $ot       = $perEmpOT[$eid]      ?? 0;
    $sick     = $timeOffTotals[$eid]['Sick'] ?? 0;
    $vacation = $timeOffTotals[$eid]['PTO']  ?? 0;
    $grand    = $clocked + $sick + $vacation;

    $html  = '<h2 style="text-align:center; color:#0078D7;">Payroll Summary Report</h2>';
    $html .= "<p><strong>Employee:</strong> {$name}<br>";
    $html .= "<strong>Date Range:</strong> " . date("m/d/Y", strtotime($start)) . " to " . date("m/d/Y", strtotime($end)) . "</p>";

    $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; border-collapse: collapse;">
                <thead style="background-color: #e6f0ff;">
                    <tr>
                        <th><b>Date</b></th>
                        <th><b>Type</b></th>
                        <th><b>In</b></th>
                        <th><b>Out</b></th>
                        <th><b>Hours</b></th>
                    </tr>
                </thead>
                <tbody>';
    foreach ($merged as $row) {
        $rowBg = '';
        if ($row['Type'] === 'Sick')     $rowBg = " style='background-color:#fdecea;'";
        if ($row['Type'] === 'Vacation') $rowBg = " style='background-color:#e8f1fc;'";

        $html .= "<tr{$rowBg}>
                    <td>" . date("m/d/Y", strtotime($row['Date'])) . "</td>
                    <td>" . htmlspecialchars($row['Type']) . "</td>
                    <td>" . fmtTime($row['In'])  . "</td>
                    <td>" . fmtTime($row['Out']) . "</td>
                    <td style='text-align:right;'>" . decimalToHM($row['Hours']) . "</td>
                  </tr>";
    }
    $html .= '</tbody></table>';

    // Summary block beneath the detail table
    $html .= "<table border='1' cellpadding='5' cellspacing='0' style='width:100%; border-collapse:collapse; margin-top:10px;'>
                <tr style='background-color:#e6f0ff; font-weight:bold;'>
                    <td>Clocked</td><td>OT</td><td>Sick</td><td>Vacation</td><td>Grand Total</td>
                </tr>
                <tr>
                    <td style='text-align:right;'>" . decimalToHM($clocked)  . "</td>
                    <td style='text-align:right;'>" . decimalToHM($ot)       . "</td>
                    <td style='text-align:right;'>" . decimalToHM($sick)     . "</td>
                    <td style='text-align:right;'>" . decimalToHM($vacation) . "</td>
                    <td style='text-align:right;'><b>" . decimalToHM($grand) . "</b></td>
                </tr>
              </table>";

    $pdf->writeHTML($html, true, false, true, false, '');
}

while (ob_get_level()) ob_end_clean();
$pdf->Output('payroll_summary.pdf', 'D');
exit;
