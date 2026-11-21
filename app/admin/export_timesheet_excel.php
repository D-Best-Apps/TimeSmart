<?php
/**
 * Export Timesheet Report to Excel
 * Requires export_reports permission
 */
session_start();
require_once '../auth/db.php';
require_once '../vendor/autoload.php';
require_once __DIR__ . '/../functions/check_permission.php';
require_once __DIR__ . '/../functions/employee_number.php'; // EmployeeNumberColumn
require_once __DIR__ . '/../functions/timesheet_report_lines.php'; // time off + holiday lines

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
$punchRows = $result->fetch_all(MYSQLI_ASSOC);
// Approved time off and holiday hours, listed between the punches (as on the report page)
$extras = timesheetReportExtraLines($conn, $startDate, $endDate, !empty($employeeID) ? (int) $employeeID : null);
$items  = timesheetReportMerge($punchRows, $extras['lines']);

// === CREATE SPREADSHEET ===
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Timesheet Report');

// Headers
$headers = ['Date', 'Employee', 'Clock In', 'Lunch Start', 'Lunch End', 'Clock Out', 'Total Hours'];
$sheet->fromArray($headers, null, 'A1');
$empNoCol = new EmployeeNumberColumn($conn);
$empNoCol->header($sheet, 1);

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

foreach ($items as $item) {
    if (isset($item['extra'])) {
        // Time off / holiday line: what it is across the time columns, its hours in Total Hours
        $x = $item['extra'];
        $sheet->setCellValue("A{$rowNum}", date('m/d/Y', strtotime($x['Date'])));
        $sheet->setCellValue("B{$rowNum}", $x['FirstName'] . ' ' . $x['LastName']);
        $empNoCol->row($sheet, $rowNum, (int) $x['EmployeeID']);
        $sheet->setCellValue("C{$rowNum}", $x['label'] . ($x['note'] !== '' ? ' — ' . $x['note'] : ''));
        $sheet->mergeCells("C{$rowNum}:F{$rowNum}");
        $sheet->setCellValue("G{$rowNum}", $x['hours'] !== null ? number_format($x['hours'], 2) : '--');
        $sheet->getStyle("A{$rowNum}:G{$rowNum}")->getFill()->setFillType(Fill::FILL_SOLID)
              ->getStartColor()->setRGB(TIMESHEET_REPORT_LINE_COLORS[$x['kind']] ?? 'FFFFFF');
        $rowNum++;
        continue;
    }
    $row = $item['punch'];
    $sheet->setCellValue("A{$rowNum}", date('m/d/Y', strtotime($row['Date'])));
    $sheet->setCellValue("B{$rowNum}", $row['FirstName'] . ' ' . $row['LastName']);
    $empNoCol->row($sheet, $rowNum, (int) $row['EmployeeID']);
    $sheet->setCellValue("C{$rowNum}", formatTime($row['TimeIN']));
    $sheet->setCellValue("D{$rowNum}", formatTime($row['LunchStart']));
    $sheet->setCellValue("E{$rowNum}", formatTime($row['LunchEnd']));
    $sheet->setCellValue("F{$rowNum}", formatTime($row['TimeOut']));
    $sheet->setCellValue("G{$rowNum}", number_format($row['TotalHours'] ?? 0, 2));

    $totalHours += ($row['TotalHours'] ?? 0);
    $rowNum++;
}

// Total rows: worked hours, then time off and holiday hours and the paid total when there are any
$xTotals = $extras['totals'];
$sheet->setCellValue("F{$rowNum}", array_sum($xTotals) > 0 ? 'Worked Hours' : 'Total Hours');
$sheet->setCellValue("G{$rowNum}", number_format($totalHours, 2));
$sheet->getStyle("F{$rowNum}:G{$rowNum}")->getFont()->setBold(true);
if (array_sum($xTotals) > 0) {
    foreach (['PTO', 'Sick', 'Holiday'] as $k) {
        if ($xTotals[$k] > 0) {
            $rowNum++;
            $sheet->setCellValue("F{$rowNum}", $k);
            $sheet->setCellValue("G{$rowNum}", number_format($xTotals[$k], 2));
        }
    }
    $rowNum++;
    $sheet->setCellValue("F{$rowNum}", 'Total Paid Hours');
    $sheet->setCellValue("G{$rowNum}", number_format($totalHours + array_sum($xTotals), 2));
    $sheet->getStyle("F{$rowNum}:G{$rowNum}")->getFont()->setBold(true);
}

// === OUTPUT FILE ===
$rangeFormatted = date('m-d', strtotime($startDate)) . '_' . date('m-d', strtotime($endDate));
$filename = "Timesheet_Report_{$rangeFormatted}.xlsx";

$empNoCol->apply('B');

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"$filename\"");

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
