<?php
/**
 * Export Timesheet Report to Excel
 * Requires export_reports permission
 */
session_start();
require_once '../auth/db.php';
require_once '../vendor/autoload.php';
require_once __DIR__ . '/../functions/check_permission.php';

// Check admin authentication
if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

// Permission check
requirePermission('export_reports');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

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

// === CREATE SPREADSHEET ===
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Timesheet Report');

// Headers
$headers = ['Date', 'Employee', 'Clock In', 'Lunch Start', 'Lunch End', 'Clock Out', 'Total Hours'];
$sheet->fromArray($headers, null, 'A1');

// Style header row
$sheet->getStyle('A1:G1')->applyFromArray([
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0078D7']],
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
]);

// Auto-size columns
foreach (range('A', 'G') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Helper function for time formatting
function formatTime($time) {
    return !empty($time) ? date('h:i A', strtotime($time)) : '--';
}

// Data rows
$rowNum = 2;
$totalHours = 0;

while ($row = $result->fetch_assoc()) {
    $sheet->setCellValue("A{$rowNum}", date('m/d/Y', strtotime($row['Date'])));
    $sheet->setCellValue("B{$rowNum}", $row['FirstName'] . ' ' . $row['LastName']);
    $sheet->setCellValue("C{$rowNum}", formatTime($row['TimeIN']));
    $sheet->setCellValue("D{$rowNum}", formatTime($row['LunchStart']));
    $sheet->setCellValue("E{$rowNum}", formatTime($row['LunchEnd']));
    $sheet->setCellValue("F{$rowNum}", formatTime($row['TimeOut']));
    $sheet->setCellValue("G{$rowNum}", number_format($row['TotalHours'] ?? 0, 2));

    $totalHours += ($row['TotalHours'] ?? 0);
    $rowNum++;
}

// Total row
$sheet->setCellValue("F{$rowNum}", 'Total Hours');
$sheet->setCellValue("G{$rowNum}", number_format($totalHours, 2));
$sheet->getStyle("F{$rowNum}:G{$rowNum}")->getFont()->setBold(true);

// === OUTPUT FILE ===
$rangeFormatted = date('m-d', strtotime($startDate)) . '_' . date('m-d', strtotime($endDate));
$filename = "Timesheet_Report_{$rangeFormatted}.xlsx";

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"$filename\"");

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
