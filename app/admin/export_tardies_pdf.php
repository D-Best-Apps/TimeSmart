<?php
require_once '../auth/db.php';
require_once __DIR__ . '/tcpdf/tcpdf.php';

$startDate = $_POST['start'] ?? '';
$endDate = $_POST['end'] ?? '';
$employeeID = $_POST['emp'] ?? '';

if (!$startDate || !$endDate) {
    header('Location: ../error.php?code=400&message=' . urlencode('Start and end dates are required.'));
    exit;
}

// === HELPER FUNCTIONS ===
function getPayPeriodStart($date) {
    $dt = new DateTime($date);
    $dayOfWeek = (int)$dt->format('w');

    if ($dayOfWeek >= 3) {
        $dt->modify('wednesday this week');
    } else {
        $dt->modify('wednesday last week');
    }

    return $dt->format('Y-m-d');
}

function getPayPeriodEnd($startDate) {
    $dt = new DateTime($startDate);
    $dt->modify('+6 days');
    return $dt->format('Y-m-d');
}

// === DATA FETCH ===
$sql = "
    SELECT 
        u.ID as EmployeeID,
        u.FirstName, 
        u.LastName,
        u.ScheduledStartTime,
        tp.Date,
        MIN(tp.TimeIN) as FirstClockIn,
        DAYOFWEEK(tp.Date) as DayOfWeek
    FROM timepunches tp
    JOIN users u ON u.ID = tp.EmployeeID
    WHERE tp.TimeIN IS NOT NULL
      AND tp.Date BETWEEN ? AND ?
      AND DAYOFWEEK(tp.Date) BETWEEN 2 AND 6
      AND u.ScheduledStartTime IS NOT NULL
";

$params = [$startDate, $endDate];
$types = 'ss';

if ($employeeID !== '') {
    $sql .= " AND tp.EmployeeID = ?";
    $params[] = $employeeID;
    $types .= 'i';
}

$sql .= " GROUP BY tp.EmployeeID, tp.Date
          HAVING FirstClockIn > u.ScheduledStartTime
          ORDER BY tp.Date ASC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    header('Location: ../error.php?code=500&message=' . urlencode('Query error: ' . $conn->error));
    exit;
}

$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// Process results
$employeePayPeriods = [];
$today = date('Y-m-d');

while ($row = $result->fetch_assoc()) {
    $empID = $row['EmployeeID'];
    $empName = $row['FirstName'] . ' ' . $row['LastName'];
    $date = $row['Date'];
    $scheduledTime = $row['ScheduledStartTime'];
    $actualTime = $row['FirstClockIn'];
    
    $scheduledDateTime = new DateTime($date . ' ' . $scheduledTime);
    $actualDateTime = new DateTime($date . ' ' . $actualTime);
    $lateMinutes = ($actualDateTime->getTimestamp() - $scheduledDateTime->getTimestamp()) / 60;
    
    if ($lateMinutes <= 0) {
        continue;
    }
    
    $periodStart = getPayPeriodStart($date);
    $periodEnd = getPayPeriodEnd($periodStart);

    if ($periodEnd > $today) {
        continue;
    }

    $periodKey = $periodStart . '_' . $periodEnd;

    if (!isset($employeePayPeriods[$empID])) {
        $employeePayPeriods[$empID] = [
            'name' => $empName,
            'scheduledTime' => $scheduledTime,
            'periods' => []
        ];
    }

    if (!isset($employeePayPeriods[$empID]['periods'][$periodKey])) {
        $employeePayPeriods[$empID]['periods'][$periodKey] = [
            'start' => $periodStart,
            'end' => $periodEnd,
            'tardies_under_5' => 0,
            'tardies_5_plus' => 0
        ];
    }

    if ($lateMinutes < 5) {
        $employeePayPeriods[$empID]['periods'][$periodKey]['tardies_under_5']++;
    } else {
        $employeePayPeriods[$empID]['periods'][$periodKey]['tardies_5_plus']++;
    }
}

// Calculate report data
$reportData = [];
$employeeTotals = [];

foreach ($employeePayPeriods as $empID => $empData) {
    $empTotalUnder5 = 0;
    $empTotal5Plus = 0;
    
    foreach ($empData['periods'] as $periodKey => $period) {
        $totalTardies = $period['tardies_under_5'] + $period['tardies_5_plus'];
        
        $reportData[] = [
            'employee' => $empData['name'],
            'scheduled_time' => $empData['scheduledTime'],
            'period_start' => $period['start'],
            'period_end' => $period['end'],
            'tardies_under_5' => $period['tardies_under_5'],
            'tardies_5_plus' => $period['tardies_5_plus'],
            'total_tardies' => $totalTardies
        ];
        
        $empTotalUnder5 += $period['tardies_under_5'];
        $empTotal5Plus += $period['tardies_5_plus'];
    }
    
    $employeeTotals[$empData['name']] = [
        'under_5' => $empTotalUnder5,
        '5_plus' => $empTotal5Plus,
        'total' => $empTotalUnder5 + $empTotal5Plus
    ];
}

// === CREATE PDF ===
$pdf = new TCPDF('L', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
$pdf->SetCreator('D-Best TimeSmart');
$pdf->SetAuthor('D-Best Technologies');
$pdf->SetTitle('Tardies Report');
$pdf->SetMargins(15, 15, 15);
$pdf->SetFont('helvetica', '', 9);
$pdf->AddPage();

// Header
$html = '<h2 style="text-align:center; color:#FFC107;">Tardies Report</h2>';
$html .= "<p style='text-align:center;'><strong>Date Range:</strong> " . date("m/d/Y", strtotime($startDate)) . " to " . date("m/d/Y", strtotime($endDate)) . "</p>";

$html .= '<p style="font-size:8px; background-color:#fff3cd; padding:8px; border:1px solid #ffc107;">
<strong>Note:</strong> This report tracks tardies based on scheduled start times for weekdays only (Mon-Fri). 
Tardies are grouped by pay period (Wednesday-Tuesday). Only employees with a scheduled start time are included. 
Incomplete pay periods are excluded.
</p>';

// Detail table
$html .= '<h3>Tardies by Pay Period</h3>';
$html .= '<table border="1" cellpadding="4" cellspacing="0" style="width: 100%; border-collapse: collapse; font-size:8px;">
            <thead style="background-color: #FFC107; color: black;">
                <tr>
                    <th><b>Employee</b></th>
                    <th><b>Scheduled</b></th>
                    <th><b>Pay Period Start</b></th>
                    <th><b>Pay Period End</b></th>
                    <th><b>&lt;5min</b></th>
                    <th><b>≥5min</b></th>
                    <th><b>Total</b></th>
                </tr>
            </thead>
            <tbody>';

foreach ($reportData as $row) {
    $html .= "<tr>
                <td>" . htmlspecialchars($row['employee']) . "</td>
                <td>" . date('g:i A', strtotime($row['scheduled_time'])) . "</td>
                <td>" . date('m/d/Y', strtotime($row['period_start'])) . "</td>
                <td>" . date('m/d/Y', strtotime($row['period_end'])) . "</td>
                <td style='text-align:center;'>" . $row['tardies_under_5'] . "</td>
                <td style='text-align:center;'>" . $row['tardies_5_plus'] . "</td>
                <td style='text-align:center;'><b>" . $row['total_tardies'] . "</b></td>
              </tr>";
}

$html .= '</tbody></table>';

// Summary table
$html .= '<br/><h3>Summary Totals by Employee</h3>';
$html .= '<table border="1" cellpadding="4" cellspacing="0" style="width: 60%; border-collapse: collapse; font-size:8px;">
            <thead style="background-color: #0078D7; color: white;">
                <tr>
                    <th><b>Employee</b></th>
                    <th><b>Total &lt;5min</b></th>
                    <th><b>Total ≥5min</b></th>
                    <th><b>Total All</b></th>
                </tr>
            </thead>
            <tbody>';

$grandTotalUnder5 = 0;
$grandTotal5Plus = 0;

foreach ($employeeTotals as $empName => $totals) {
    $html .= "<tr>
                <td>" . htmlspecialchars($empName) . "</td>
                <td style='text-align:center;'>" . $totals['under_5'] . "</td>
                <td style='text-align:center;'>" . $totals['5_plus'] . "</td>
                <td style='text-align:center;'><b>" . $totals['total'] . "</b></td>
              </tr>";
    
    $grandTotalUnder5 += $totals['under_5'];
    $grandTotal5Plus += $totals['5_plus'];
}

$grandTotal = $grandTotalUnder5 + $grandTotal5Plus;

$html .= "<tr style='font-weight:bold; background-color:#f1f1f1;'>
            <td><b>GRAND TOTAL</b></td>
            <td style='text-align:center;'><b>" . $grandTotalUnder5 . "</b></td>
            <td style='text-align:center;'><b>" . $grandTotal5Plus . "</b></td>
            <td style='text-align:center;'><b>" . $grandTotal . "</b></td>
          </tr>";

$html .= '</tbody></table>';

$pdf->writeHTML($html, true, false, true, false, '');

// Clean buffer and output PDF
while (ob_get_level()) ob_end_clean();
$pdf->Output('tardies_report.pdf', 'D');
exit;
