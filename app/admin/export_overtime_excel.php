<?php
require_once '../auth/db.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

$startDate = $_POST['start'] ?? '';
$endDate = $_POST['end'] ?? '';
$employeeID = $_POST['emp'] ?? '';
$rounding = intval($_POST['rounding'] ?? 0);

if (!$startDate || !$endDate) {
    header('Location: ../error.php?code=400&message=' . urlencode('Start and end dates are required.'));
    exit;
}

function hmsToDecimal($hms) {
    list($h, $m, $s) = explode(':', $hms);
    return $h + ($m / 60) + ($s / 3600);
}
function roundToNearestMinutes($decimalHours, $interval = 0) {
    if ($interval <= 0) return round($decimalHours, 2);
    $totalMinutes = $decimalHours * 60;
    $roundedMinutes = round($totalMinutes / $interval) * $interval;
    return round($roundedMinutes / 60, 2);
}
function decimalToHM($decimalHours) {
    $totalMinutes = (int) round($decimalHours * 60);
    $h = intdiv($totalMinutes, 60);
    $m = $totalMinutes % 60;
    return sprintf('%d:%02d', $h, $m);
}
function weekEndingFriday($dateStr) {
    $d = new DateTime($dateStr);
    $dow = (int) $d->format('N');
    $daysUntilFriday = (5 - $dow + 7) % 7;
    $d->modify("+{$daysUntilFriday} days");
    return $d->format('Y-m-d');
}

$sql = "
    SELECT u.ID AS EmpID, u.FirstName, u.LastName, tp.Date,
        SEC_TO_TIME(SUM(
            TIME_TO_SEC(TIMEDIFF(tp.TimeOUT, tp.TimeIN)) -
            TIME_TO_SEC(TIMEDIFF(IFNULL(tp.LunchEnd, '00:00:00'), IFNULL(tp.LunchStart, '00:00:00')))
        )) AS DailyHours
    FROM timepunches tp
    JOIN users u ON u.ID = tp.EmployeeID
    WHERE tp.TimeIN IS NOT NULL AND tp.TimeOUT IS NOT NULL
      AND tp.Date BETWEEN ? AND ?
";
$params = [$startDate, $endDate];
if (!empty($employeeID)) {
    $sql .= " AND tp.EmployeeID = ?";
    $params[] = $employeeID;
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
    $weekEnd = weekEndingFriday($row['Date']);
    $hours = roundToNearestMinutes(hmsToDecimal($row['DailyHours']), $rounding);

    if (!isset($byEmployee[$empId])) {
        $byEmployee[$empId] = ['name' => $name, 'weeks' => []];
    }
    if (!isset($byEmployee[$empId]['weeks'][$weekEnd])) {
        $byEmployee[$empId]['weeks'][$weekEnd] = 0;
    }
    $byEmployee[$empId]['weeks'][$weekEnd] += $hours;
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

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Overtime');

$headers = ['Employee', 'Week Ending', 'Total Hours', 'Total (H:MM)', 'Overtime', 'OT (H:MM)'];
$sheet->fromArray($headers, null, 'A1');
$sheet->getStyle('A1:F1')->applyFromArray([
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0078D7']],
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
]);
foreach (range('A', 'F') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

$rowNum = 2;
foreach ($byEmployee as $data) {
    foreach ($data['weeks'] as $weekEnd => $hours) {
        $ot = max(0, $hours - 40);
        $sheet->setCellValue("A{$rowNum}", $data['name']);
        $sheet->setCellValue("B{$rowNum}", date('m/d/Y', strtotime($weekEnd)));
        $sheet->setCellValue("C{$rowNum}", $hours);
        $sheet->setCellValueExplicit("D{$rowNum}", decimalToHM($hours), DataType::TYPE_STRING);
        $sheet->setCellValue("E{$rowNum}", $ot);
        $sheet->setCellValueExplicit("F{$rowNum}", decimalToHM($ot), DataType::TYPE_STRING);
        if ($ot > 0) {
            $sheet->getStyle("A{$rowNum}:F{$rowNum}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF3CD']]
            ]);
        }
        $rowNum++;
    }
    $sheet->setCellValue("A{$rowNum}", $data['name'] . ' — Period Total');
    $sheet->setCellValue("C{$rowNum}", $data['totalHours']);
    $sheet->setCellValueExplicit("D{$rowNum}", decimalToHM($data['totalHours']), DataType::TYPE_STRING);
    $sheet->setCellValue("E{$rowNum}", $data['totalOT']);
    $sheet->setCellValueExplicit("F{$rowNum}", decimalToHM($data['totalOT']), DataType::TYPE_STRING);
    $sheet->getStyle("A{$rowNum}:F{$rowNum}")->applyFromArray([
        'font' => ['bold' => true],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F1F1F1']]
    ]);
    $rowNum++;
}

if (!empty($byEmployee)) {
    $sheet->setCellValue("A{$rowNum}", 'Grand Total');
    $sheet->setCellValue("C{$rowNum}", $grandTotalHours);
    $sheet->setCellValueExplicit("D{$rowNum}", decimalToHM($grandTotalHours), DataType::TYPE_STRING);
    $sheet->setCellValue("E{$rowNum}", $grandTotalOT);
    $sheet->setCellValueExplicit("F{$rowNum}", decimalToHM($grandTotalOT), DataType::TYPE_STRING);
    $sheet->getStyle("A{$rowNum}:F{$rowNum}")->applyFromArray([
        'font' => ['bold' => true],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9EDF7']]
    ]);
}

$rangeLabel = date('m-d', strtotime($startDate)) . '_' . date('m-d', strtotime($endDate));
$filename = "Overtime_{$rangeLabel}.xlsx";

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"$filename\"");
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
