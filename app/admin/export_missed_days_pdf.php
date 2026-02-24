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
function hmsToDecimal($hms) {
    if (empty($hms)) return 0;
    list($h, $m, $s) = explode(':', $hms);
    return round($h + ($m / 60) + ($s / 3600), 2);
}

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

function generatePayPeriods($startDate, $endDate) {
    $periods = [];
    $today = date('Y-m-d');
    
    $currentPeriodStart = getPayPeriodStart($startDate);
    
    while ($currentPeriodStart <= $endDate) {
        $currentPeriodEnd = getPayPeriodEnd($currentPeriodStart);
        
        if ($currentPeriodEnd <= $today) {
            $periods[] = [
                'start' => $currentPeriodStart,
                'end' => $currentPeriodEnd
            ];
        }
        
        $dt = new DateTime($currentPeriodStart);
        $dt->modify('+7 days');
        $currentPeriodStart = $dt->format('Y-m-d');
    }
    
    return $periods;
}

// === DATA FETCH ===
$employeeListData = [];
if ($employeeID !== '') {
    $stmt = $conn->prepare("SELECT ID, FirstName, LastName FROM users WHERE ID = ?");
    $stmt->bind_param("i", $employeeID);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $employeeListData[] = $row;
    }
} else {
    $result = $conn->query("SELECT ID, FirstName, LastName FROM users ORDER BY LastName, FirstName");
    while ($row = $result->fetch_assoc()) {
        $employeeListData[] = $row;
    }
}

// Generate all pay periods
$payPeriods = generatePayPeriods($startDate, $endDate);

// Build report data
$reportData = [];
foreach ($employeeListData as $emp) {
    $empID = $emp['ID'];
    $empName = $emp['FirstName'] . ' ' . $emp['LastName'];
    
    foreach ($payPeriods as $period) {
        $periodStart = $period['start'];
        $periodEnd = $period['end'];
        
        $sql = "
            SELECT 
                SEC_TO_TIME(SUM(
                    TIME_TO_SEC(TIMEDIFF(tp.TimeOUT, tp.TimeIN)) -
                    TIME_TO_SEC(TIMEDIFF(IFNULL(tp.LunchEnd, '00:00:00'), IFNULL(tp.LunchStart, '00:00:00')))
                )) AS TotalHours
            FROM timepunches tp
            WHERE tp.EmployeeID = ?
              AND tp.TimeIN IS NOT NULL 
              AND tp.TimeOUT IS NOT NULL
              AND tp.Date BETWEEN ? AND ?
        ";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iss", $empID, $periodStart, $periodEnd);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        $hoursWorked = $row['TotalHours'] ? hmsToDecimal($row['TotalHours']) : 0;
        $daysWorked = floor($hoursWorked / 8);
        $missedDays = max(0, 5 - $daysWorked);
        
        $reportData[] = [
            'employee' => $empName,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'hours_worked' => $hoursWorked,
            'days_worked' => $daysWorked,
            'missed_days' => $missedDays
        ];
    }
}

// Calculate per-employee totals
$employeeTotals = [];
foreach ($reportData as $row) {
    $empName = $row['employee'];
    if (!isset($employeeTotals[$empName])) {
        $employeeTotals[$empName] = 0;
    }
    $employeeTotals[$empName] += $row['missed_days'];
}
ksort($employeeTotals);

// === CREATE PDF ===
$pdf = new TCPDF('L', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
$pdf->SetCreator('TimeClock System');
$pdf->SetAuthor('D-Best Technologies');
$pdf->SetTitle('Missed Days Report');
$pdf->SetMargins(15, 15, 15);
$pdf->SetFont('helvetica', '', 10);
$pdf->AddPage();

// Header
$html = '<h2 style="text-align:center; color:#0078D7;">Missed Days Report</h2>';
$html .= "<p style='text-align:center;'><strong>Date Range:</strong> " . date("m/d/Y", strtotime($startDate)) . " to " . date("m/d/Y", strtotime($endDate)) . "</p>";

$html .= '<p style="font-size:9px; background-color:#f0f8ff; padding:8px; border:1px solid #ccc;">
<strong>Note:</strong> This report shows missed work days based on Wednesday-to-Tuesday pay periods. 
A full work week is 40 hours (5 days). Days worked are calculated as floor(hours / 8). 
Incomplete pay periods are excluded. Pay periods with 0 hours worked (no clock-ins) are shown.
</p>';

// Summary section
$html .= '<h3>Summary Totals by Employee</h3>';
$html .= '<table border="1" cellpadding="4" cellspacing="0" style="width: 50%; border-collapse: collapse; margin-bottom: 20px;">
            <thead style="background-color: #0078D7; color: white;">
                <tr>
                    <th><b>Employee</b></th>
                    <th><b>Total Missed Days</b></th>
                </tr>
            </thead>
            <tbody>';

foreach ($employeeTotals as $empName => $totalMissed) {
    $html .= "<tr>
                <td>" . htmlspecialchars($empName) . "</td>
                <td style='text-align:center;'><b>" . $totalMissed . "</b></td>
              </tr>";
}

$html .= '</tbody></table>';

// Detailed section
$html .= '<h3>Detailed Breakdown by Pay Period</h3>';
$html .= '<table border="1" cellpadding="6" cellspacing="0" style="width: 100%; border-collapse: collapse;">
            <thead style="background-color: #0078D7; color: white;">
                <tr>
                    <th><b>Employee</b></th>
                    <th><b>Pay Period Start</b></th>
                    <th><b>Pay Period End</b></th>
                    <th><b>Hours Worked</b></th>
                    <th><b>Days Worked</b></th>
                    <th><b>Missed Days</b></th>
                </tr>
            </thead>
            <tbody>';

foreach ($reportData as $row) {
    $html .= "<tr>
                <td>" . htmlspecialchars($row['employee']) . "</td>
                <td>" . date('m/d/Y', strtotime($row['period_start'])) . "</td>
                <td>" . date('m/d/Y', strtotime($row['period_end'])) . "</td>
                <td style='text-align:right;'>" . number_format($row['hours_worked'], 2) . "</td>
                <td style='text-align:center;'>" . $row['days_worked'] . "</td>
                <td style='text-align:center;'>" . $row['missed_days'] . "</td>
              </tr>";
}

$html .= '</tbody></table>';

$pdf->writeHTML($html, true, false, true, false, '');

// Clean buffer and output PDF
while (ob_get_level()) ob_end_clean();
$pdf->Output('missed_days_report.pdf', 'D');
exit;
