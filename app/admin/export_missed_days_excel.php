<?php
require_once '../auth/db.php';
require_once '../vendor/autoload.php';

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

// === CREATE SPREADSHEET ===
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Missed Days Report');

$rowNum = 1;

// Summary section header
$sheet->setCellValue("A{$rowNum}", 'SUMMARY TOTALS BY EMPLOYEE');
$sheet->getStyle("A{$rowNum}:B{$rowNum}")->applyFromArray([
    'font' => ['bold' => true, 'size' => 12],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E3F2FD']]
]);
$sheet->mergeCells("A{$rowNum}:B{$rowNum}");
$rowNum++;

// Summary headers
$summaryHeaders = ['Employee', 'Total Missed Days'];
$sheet->fromArray($summaryHeaders, null, "A{$rowNum}");
$sheet->getStyle("A{$rowNum}:B{$rowNum}")->applyFromArray([
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0078D7']],
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
]);
$rowNum++;

// Summary data
foreach ($employeeTotals as $empName => $totalMissed) {
    $sheet->setCellValue("A{$rowNum}", $empName);
    $sheet->setCellValue("B{$rowNum}", $totalMissed);
    $rowNum++;
}

// Add spacing
$rowNum += 2;

// Detailed section header
$sheet->setCellValue("A{$rowNum}", 'DETAILED BREAKDOWN BY PAY PERIOD');
$sheet->getStyle("A{$rowNum}:F{$rowNum}")->applyFromArray([
    'font' => ['bold' => true, 'size' => 12],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E3F2FD']]
]);
$sheet->mergeCells("A{$rowNum}:F{$rowNum}");
$rowNum++;

// Detail headers
$headers = ['Employee', 'Pay Period Start', 'Pay Period End', 'Hours Worked', 'Days Worked', 'Missed Days'];
$sheet->fromArray($headers, null, "A{$rowNum}");
$sheet->getStyle("A{$rowNum}:F{$rowNum}")->applyFromArray([
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0078D7']],
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
]);
$rowNum++;

// Detail data rows
foreach ($reportData as $row) {
    $sheet->setCellValue("A{$rowNum}", $row['employee']);
    $sheet->setCellValue("B{$rowNum}", date('m/d/Y', strtotime($row['period_start'])));
    $sheet->setCellValue("C{$rowNum}", date('m/d/Y', strtotime($row['period_end'])));
    $sheet->setCellValue("D{$rowNum}", number_format($row['hours_worked'], 2));
    $sheet->setCellValue("E{$rowNum}", $row['days_worked']);
    $sheet->setCellValue("F{$rowNum}", $row['missed_days']);
    $rowNum++;
}

// Auto-size columns
foreach (range('A', 'F') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Output Excel file
$rangeFormatted = date('m-d-Y', strtotime($startDate)) . '_to_' . date('m-d-Y', strtotime($endDate));
$filename = "Missed_Days_Report_{$rangeFormatted}.xlsx";

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"$filename\"");
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
