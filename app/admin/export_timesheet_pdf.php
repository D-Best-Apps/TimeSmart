<?php
/**
 * Export Timesheet Report to PDF
 * Requires export_reports permission
 */
session_start();
require_once '../auth/db.php';
require_once __DIR__ . '/tcpdf/tcpdf.php';
require_once __DIR__ . '/../functions/check_permission.php';

// Check admin authentication
if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

// Permission check
requirePermission('export_reports');

// === INPUTS ===
$startDate = $_POST['start'] ?? '';
$endDate = $_POST['end'] ?? '';
$employeeID = $_POST['emp'] ?? '';

if (!$startDate || !$endDate) {
    header('Location: ../error.php?code=400&message=' . urlencode('Start and end dates are required.'));
    exit;
}

// === FETCH DATA ===
$sql = "
    SELECT u.FirstName, u.LastName, tp.EmployeeID, tp.Date,
           tp.TimeIN, tp.LunchStart, tp.LunchEnd, tp.TimeOut, tp.TotalHours
    FROM timepunches tp
    JOIN users u ON u.ID = tp.EmployeeID
    WHERE tp.Date BETWEEN ? AND ?
";
$params = [$startDate, $endDate];
$types = 'ss';

if (!empty($employeeID)) {
    $sql .= " AND tp.EmployeeID = ?";
    $params[] = $employeeID;
    $types .= 'i';
}

$sql .= " ORDER BY tp.Date DESC, u.LastName, u.FirstName, tp.TimeIN";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// Collect rows and total
$rows = [];
$totalHours = 0;

while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
    $totalHours += ($row['TotalHours'] ?? 0);
}

// Helper function for time formatting
function formatTime($time) {
    return !empty($time) ? date('h:i A', strtotime($time)) : '--';
}

// === PDF SETUP ===
$pdf = new TCPDF();
$pdf->SetCreator('TimeClock System');
$pdf->SetAuthor('D-Best Technologies');
$pdf->SetTitle('Timesheet Report');
$pdf->SetMargins(10, 15, 10);
$pdf->SetFont('helvetica', '', 9);
$pdf->AddPage('L'); // Landscape for 7 columns

// === BUILD HTML TABLE ===
$html = '<h2 style="text-align:center; color:#0078D7;">Timesheet Report</h2>';
$html .= '<p><strong>Date Range:</strong> ' . date("m/d/Y", strtotime($startDate)) . ' to ' . date("m/d/Y", strtotime($endDate)) . '</p>';

$html .= '<table border="1" cellpadding="5" cellspacing="0" style="width: 100%; border-collapse: collapse;">
            <thead style="background-color: #e6f0ff;">
                <tr>
                    <th><b>Date</b></th>
                    <th><b>Employee</b></th>
                    <th><b>Clock In</b></th>
                    <th><b>Lunch Start</b></th>
                    <th><b>Lunch End</b></th>
                    <th><b>Clock Out</b></th>
                    <th><b>Total Hours</b></th>
                </tr>
            </thead>
            <tbody>';

foreach ($rows as $r) {
    $date = date("m/d/Y", strtotime($r['Date']));
    $name = htmlspecialchars($r['FirstName'] . ' ' . $r['LastName']);
    $clockIn = formatTime($r['TimeIN']);
    $lunchStart = formatTime($r['LunchStart']);
    $lunchEnd = formatTime($r['LunchEnd']);
    $clockOut = formatTime($r['TimeOut']);
    $hours = number_format($r['TotalHours'] ?? 0, 2);

    $html .= "<tr>
                <td>$date</td>
                <td>$name</td>
                <td>$clockIn</td>
                <td>$lunchStart</td>
                <td>$lunchEnd</td>
                <td>$clockOut</td>
                <td style='text-align:right;'>$hours</td>
              </tr>";
}

$html .= "<tr style='font-weight:bold; background-color:#f1f1f1;'>
            <td colspan='6'><b>Total Hours</b></td>
            <td style='text-align:right;'><b>" . number_format($totalHours, 2) . "</b></td>
          </tr>";

$html .= '</tbody></table>';

$pdf->writeHTML($html, true, false, true, false, '');

// === OUTPUT PDF ===
while (ob_get_level()) ob_end_clean();
$pdf->Output('timesheet_report.pdf', 'D');
exit;
