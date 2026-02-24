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

// === CREATE SPREADSHEET ===
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Tardies Report');

// Headers for detail section
$headers = ['Employee', 'Scheduled Time', 'Pay Period Start', 'Pay Period End', 'Tardies <5min', 'Tardies ≥5min', 'Total Tardies'];
$sheet->fromArray($headers, null, 'A1');

$sheet->getStyle('A1:G1')->applyFromArray([
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFC107']],
    'font' => ['bold' => true, 'color' => ['rgb' => '000000']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
]);

foreach (range('A', 'G') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Data rows
$rowNum = 2;
foreach ($reportData as $row) {
    $sheet->setCellValue("A{$rowNum}", $row['employee']);
    $sheet->setCellValue("B{$rowNum}", date('g:i A', strtotime($row['scheduled_time'])));
    $sheet->setCellValue("C{$rowNum}", date('m/d/Y', strtotime($row['period_start'])));
    $sheet->setCellValue("D{$rowNum}", date('m/d/Y', strtotime($row['period_end'])));
    $sheet->setCellValue("E{$rowNum}", $row['tardies_under_5']);
    $sheet->setCellValue("F{$rowNum}", $row['tardies_5_plus']);
    $sheet->setCellValue("G{$rowNum}", $row['total_tardies']);
    $rowNum++;
}

// Add spacing
$rowNum += 2;

// Summary section
$sheet->setCellValue("A{$rowNum}", 'SUMMARY TOTALS BY EMPLOYEE');
$sheet->getStyle("A{$rowNum}:D{$rowNum}")->applyFromArray([
    'font' => ['bold' => true, 'size' => 12],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E3F2FD']]
]);
$sheet->mergeCells("A{$rowNum}:D{$rowNum}");
$rowNum++;

$summaryHeaders = ['Employee', 'Total <5min', 'Total ≥5min', 'Total All'];
$sheet->fromArray($summaryHeaders, null, "A{$rowNum}");
$sheet->getStyle("A{$rowNum}:D{$rowNum}")->applyFromArray([
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0078D7']],
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
]);
$rowNum++;

$grandTotalUnder5 = 0;
$grandTotal5Plus = 0;

foreach ($employeeTotals as $empName => $totals) {
    $sheet->setCellValue("A{$rowNum}", $empName);
    $sheet->setCellValue("B{$rowNum}", $totals['under_5']);
    $sheet->setCellValue("C{$rowNum}", $totals['5_plus']);
    $sheet->setCellValue("D{$rowNum}", $totals['total']);
    
    $grandTotalUnder5 += $totals['under_5'];
    $grandTotal5Plus += $totals['5_plus'];
    $rowNum++;
}

// Grand total
$sheet->setCellValue("A{$rowNum}", 'GRAND TOTAL');
$sheet->setCellValue("B{$rowNum}", $grandTotalUnder5);
$sheet->setCellValue("C{$rowNum}", $grandTotal5Plus);
$sheet->setCellValue("D{$rowNum}", $grandTotalUnder5 + $grandTotal5Plus);
$sheet->getStyle("A{$rowNum}:D{$rowNum}")->getFont()->setBold(true);

// Output Excel file
$rangeFormatted = date('m-d-Y', strtotime($startDate)) . '_to_' . date('m-d-Y', strtotime($endDate));
$filename = "Tardies_Report_{$rangeFormatted}.xlsx";

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"$filename\"");
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
