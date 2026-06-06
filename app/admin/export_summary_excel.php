<?php
require_once '../auth/db.php';
require_once '../vendor/autoload.php';
require_once __DIR__ . '/../functions/hours.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

// === INPUTS ===
$startDate = $_POST['start'] ?? '';
$endDate = $_POST['end'] ?? '';
$employeeID = $_POST['emp'] ?? '';
$rounding = intval($_POST['rounding'] ?? 0);
$separatePages = intval($_POST['separate_pages'] ?? 0);

if (!$startDate || !$endDate) {
    header('Location: ../error.php?code=400&message=' . urlencode('Start and end dates are required.'));
    exit;
}

// === HELPER FUNCTIONS === (decimalToHM / roundToNearestMinutes from functions/hours.php)

function getPunches($conn, $start, $end, $emp = '') {
    $sql = "
        SELECT u.FirstName, u.LastName, tp.EmployeeID, tp.Date, tp.TimeIN, tp.TimeOUT, tp.LunchStart, tp.LunchEnd,
            GREATEST(COALESCE(tp.TotalHours, 0), 0) AS TotalHours
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
    return $stmt->get_result();
}

// === DATA FETCH ===
$result = getPunches($conn, $startDate, $endDate, $employeeID);

// === CREATE SPREADSHEET ===
$spreadsheet = new Spreadsheet();
// Keep the default sheet for now, we'll handle it below

$currentUser = '';
$sheet = null;
$rowNum = 2;
$totalHours = 0;
$rangeFormatted = date('m-d', strtotime($startDate)) . '_' . date('m-d', strtotime($endDate));
$sheetsCreated = 0;

while ($row = $result->fetch_assoc()) {
    $fullName = $row['FirstName'] . ' ' . $row['LastName'];

    // Create new sheet if needed
    if ($separatePages && $employeeID == '' && $currentUser !== $row['EmployeeID']) {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle(substr($fullName, 0, 31)); // Excel sheet name limit
        $rowNum = 2;
        $sheetsCreated++;

        // Headers
        $headers = ['Employee', 'Date', 'Time In', 'Time Out', 'Lunch Start', 'Lunch End', 'Rounded Hours', 'Hours (H:MM)'];
        $sheet->fromArray($headers, null, 'A1');

        $sheet->getStyle('A1:H1')->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0078D7']],
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
        ]);

        foreach (range('A', 'H') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $currentUser = $row['EmployeeID'];
    }

    // Default (single sheet) or first time through
    if (!$separatePages && !$sheet) {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Summary');
        $headers = ['Employee', 'Date', 'Time In', 'Time Out', 'Lunch Start', 'Lunch End', 'Rounded Hours'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:G1')->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0078D7']],
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
        ]);

        foreach (range('A', 'G') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    $roundedHours = roundToNearestMinutes((float) $row['TotalHours'], $rounding);
    $totalHours += $roundedHours;

    $sheet->setCellValue("A{$rowNum}", $fullName);
    $sheet->setCellValue("B{$rowNum}", date('m/d/Y', strtotime($row['Date'])));
    $sheet->setCellValue("C{$rowNum}", $row['TimeIN']);
    $sheet->setCellValue("D{$rowNum}", $row['TimeOUT']);
    $sheet->setCellValue("E{$rowNum}", $row['LunchStart']);
    $sheet->setCellValue("F{$rowNum}", $row['LunchEnd']);
    $sheet->setCellValue("G{$rowNum}", $roundedHours);
    $sheet->setCellValueExplicit("H{$rowNum}", decimalToHM($roundedHours), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $rowNum++;
}

// If we created separate sheets, remove the default empty sheet
if ($separatePages && $sheetsCreated > 0) {
    $spreadsheet->removeSheetByIndex(0);
}

// Add total row (last sheet only)
if ($sheet) {
    $sheet->setCellValue("F{$rowNum}", 'Total Hours');
    $sheet->setCellValue("G{$rowNum}", number_format($totalHours, 2));
    $sheet->setCellValueExplicit("H{$rowNum}", decimalToHM($totalHours), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->getStyle("F{$rowNum}:H{$rowNum}")->getFont()->setBold(true);
}

// Set active sheet to first
if ($spreadsheet->getSheetCount() > 0) {
    $spreadsheet->setActiveSheetIndex(0);
}

// Output Excel file
$employeeLabel = !empty($employeeID) && isset($row) ? preg_replace('/[^a-zA-Z0-9]/', '', $row['LastName']) : 'All';
$filename = "Payroll_{$employeeLabel}_{$rangeFormatted}.xlsx";

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"$filename\"");
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
