<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../auth/db.php';
require_once __DIR__ . '/tcpdf/tcpdf.php';
require_once __DIR__ . '/../functions/hours.php';

$start = $_POST['start'] ?? '';
$end = $_POST['end'] ?? '';
$emp = $_POST['emp'] ?? '';
$rounding = intval($_POST['rounding'] ?? 0);

if (!$start || !$end) {
    header('Location: ../error.php?code=400&message=' . urlencode('Start and end dates are required.'));
    exit;
}

// hmsToDecimal / roundToNearestMinutes / decimalToHM / payPeriodStart come from functions/hours.php

$sql = "
    SELECT u.ID AS EmpID, u.FirstName, u.LastName, tp.Date,
        SUM(GREATEST(COALESCE(tp.TotalHours, 0), 0)) AS DailyHours
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
$sql .= " GROUP BY u.ID, tp.Date ORDER BY u.LastName, u.FirstName, tp.Date";

$stmt = $conn->prepare($sql);
$types = str_repeat('s', count($params));
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$byEmployee = [];
while ($row = $result->fetch_assoc()) {
    $empId = (int) $row['EmpID'];
    $name = $row['FirstName'] . ' ' . $row['LastName'];
    $weekStart = payPeriodStart($row['Date']); // Wed–Tue pay period
    $hours = roundToNearestMinutes((float) $row['DailyHours'], $rounding);

    if (!isset($byEmployee[$empId])) {
        $byEmployee[$empId] = ['name' => $name, 'weeks' => []];
    }
    if (!isset($byEmployee[$empId]['weeks'][$weekStart])) {
        $byEmployee[$empId]['weeks'][$weekStart] = 0;
    }
    $byEmployee[$empId]['weeks'][$weekStart] += $hours;
}

foreach ($byEmployee as $empId => &$data) {
    ksort($data['weeks']);
    $data['totalHours'] = 0;
    $data['totalOT'] = 0;
    foreach ($data['weeks'] as $hours) {
        $data['totalHours'] += $hours;
        $data['totalOT'] += max(0, $hours - 40);
    }
}
unset($data);

$grandTotalHours = 0;
$grandTotalOT = 0;
foreach ($byEmployee as $data) {
    $grandTotalHours += $data['totalHours'];
    $grandTotalOT += $data['totalOT'];
}

$pdf = new TCPDF();
$pdf->SetCreator('D-Best TimeSmart');
$pdf->SetAuthor('D-Best Technologies');
$pdf->SetTitle('Overtime Report');
$pdf->SetMargins(15, 15, 15);
$pdf->SetFont('helvetica', '', 10);

$rangeLabelHeader = date('m/d/Y', strtotime($start)) . " to " . date('m/d/Y', strtotime($end));

if (empty($byEmployee)) {
    $pdf->AddPage();
    $html = '<h2 style="text-align:center; color:#0078D7;">Overtime Report</h2>';
    $html .= "<p><strong>Date Range:</strong> {$rangeLabelHeader}</p>";
    $html .= "<p>No time punches found for the selected period.</p>";
    $pdf->writeHTML($html, true, false, true, false, '');
} else {
    // One page per employee
    foreach ($byEmployee as $data) {
        $pdf->AddPage();
        $name = htmlspecialchars($data['name']);

        $html = '<h2 style="text-align:center; color:#0078D7;">Overtime Report</h2>';
        $html .= "<p><strong>Employee:</strong> {$name}<br>";
        $html .= "<strong>Date Range:</strong> {$rangeLabelHeader}<br>";
        $html .= "<em>Pay periods run Wednesday–Tuesday. Overtime = hours over 40 per pay period. Hours counted only for days within the range.</em></p>";

        $html .= '<table border="1" cellpadding="5" cellspacing="0" style="width:100%; border-collapse: collapse;">
                    <thead style="background-color: #e6f0ff;">
                        <tr>
                            <th><b>Pay Period (Wed start)</b></th>
                            <th><b>Total Hours</b></th>
                            <th><b>Overtime</b></th>
                        </tr>
                    </thead>
                    <tbody>';

        foreach ($data['weeks'] as $weekStart => $hours) {
            $ot = max(0, $hours - 40);
            $rowBg = $ot > 0 ? "style='background-color:#fff3cd;'" : '';
            $html .= "<tr $rowBg>
                        <td>" . date('m/d/Y', strtotime($weekStart)) . "</td>
                        <td style='text-align:right;'>" . decimalToHM($hours) . "</td>
                        <td style='text-align:right;'>" . decimalToHM($ot) . "</td>
                      </tr>";
        }
        $html .= "<tr style='font-weight:bold; background-color:#f1f1f1;'>
                    <td>Period Total</td>
                    <td style='text-align:right;'>" . decimalToHM($data['totalHours']) . "</td>
                    <td style='text-align:right;'>" . decimalToHM($data['totalOT']) . "</td>
                  </tr>";
        $html .= '</tbody></table>';

        $pdf->writeHTML($html, true, false, true, false, '');
    }

    // Final summary page (only when "All" was selected — i.e. more than one employee in play)
    if (empty($emp) && count($byEmployee) > 1) {
        $pdf->AddPage();
        $html = '<h2 style="text-align:center; color:#0078D7;">Overtime Report &mdash; Summary</h2>';
        $html .= "<p style='text-align:center;'><strong>Date Range:</strong> {$rangeLabelHeader}</p>";

        $summaryList = [];
        foreach ($byEmployee as $data) {
            $summaryList[] = $data;
        }
        usort($summaryList, fn($a, $b) => strcasecmp($a['name'], $b['name']));

        $html .= '<table border="1" cellpadding="6" cellspacing="0" style="width:100%; border-collapse: collapse;">
                    <thead style="background-color: #e6f0ff;">
                        <tr>
                            <th><b>Employee</b></th>
                            <th><b>Total Hours</b></th>
                            <th><b>Overtime</b></th>
                        </tr>
                    </thead>
                    <tbody>';
        foreach ($summaryList as $item) {
            $html .= "<tr>
                        <td>" . htmlspecialchars($item['name']) . "</td>
                        <td style='text-align:right;'>" . decimalToHM($item['totalHours']) . "</td>
                        <td style='text-align:right;'>" . decimalToHM($item['totalOT']) . "</td>
                      </tr>";
        }
        $html .= "<tr style='font-weight:bold; background-color:#d9edf7;'>
                    <td>Grand Total</td>
                    <td style='text-align:right;'>" . decimalToHM($grandTotalHours) . "</td>
                    <td style='text-align:right;'>" . decimalToHM($grandTotalOT) . "</td>
                  </tr>";
        $html .= '</tbody></table>';

        $pdf->writeHTML($html, true, false, true, false, '');
    }
}

while (ob_get_level()) ob_end_clean();
$rangeLabel = date('m-d', strtotime($start)) . '_' . date('m-d', strtotime($end));
$pdf->Output("Overtime_{$rangeLabel}.pdf", 'D');
exit;
