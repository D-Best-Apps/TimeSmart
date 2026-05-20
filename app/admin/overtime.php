<?php
session_start();
require_once '../auth/db.php';

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

// Default period: previous calendar month
$defaultStart = date('Y-m-d', strtotime('first day of last month'));
$defaultEnd = date('Y-m-d', strtotime('last day of last month'));

if (isset($_GET['dates']) && strpos($_GET['dates'], ' - ') !== false) {
    list($startDate, $endDate) = explode(' - ', $_GET['dates']);
    $startDate = date('Y-m-d', strtotime($startDate));
    $endDate = date('Y-m-d', strtotime($endDate));
} else {
    $startDate = $defaultStart;
    $endDate = $defaultEnd;
}

$employeeID = (isset($_GET['emp']) && is_numeric($_GET['emp'])) ? (int)$_GET['emp'] : '';
$rounding = isset($_GET['rounding']) ? (int)$_GET['rounding'] : 0;

$employeeList = $conn->query("SELECT ID, FirstName, LastName FROM users ORDER BY LastName");

// Helpers
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
// Week ending Friday for a given date (Y-m-d). Friday=5 in date('N').
function weekEndingFriday($dateStr) {
    $d = new DateTime($dateStr);
    $dow = (int) $d->format('N');
    $daysUntilFriday = (5 - $dow + 7) % 7;
    $d->modify("+{$daysUntilFriday} days");
    return $d->format('Y-m-d');
}

// Fetch per-day totals within the selected period only
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
$types = 'ss';
if ($employeeID !== '') {
    $sql .= " AND tp.EmployeeID = ?";
    $params[] = $employeeID;
    $types .= 'i';
}
$sql .= " GROUP BY u.ID, tp.Date ORDER BY u.LastName, u.FirstName, tp.Date";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    header('Location: ../error.php?code=500&message=' . urlencode('Query error: ' . $conn->error));
    exit;
}
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// Group: employee -> week ending -> total hours (within period)
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

// Compute OT, period totals
foreach ($byEmployee as $empId => &$data) {
    ksort($data['weeks']);
    $data['totalHours'] = 0;
    $data['totalOT'] = 0;
    foreach ($data['weeks'] as $week => $hours) {
        $ot = max(0, $hours - 40);
        $data['totalHours'] += $hours;
        $data['totalOT'] += $ot;
    }
}
unset($data);

$grandTotalHours = 0;
$grandTotalOT = 0;
foreach ($byEmployee as $data) {
    $grandTotalHours += $data['totalHours'];
    $grandTotalOT += $data['totalOT'];
}

$pageTitle = "Overtime Report";
$extraCSS = ["https://cdn.jsdelivr.net/npm/litepicker/dist/css/litepicker.css", "../css/summary.css"];
require_once 'header.php';
?>


<div class="dashboard-container">
    <div class="container">
        <form method="GET" class="summary-filter">
            <div class="row">
                <div class="field" style="flex: 2;">
                    <label>Date Range:
                        <input type="text" name="dates" id="dateRange" class="date-input"
                               value="<?= date('m/d/Y', strtotime($startDate)) ?> - <?= date('m/d/Y', strtotime($endDate)) ?>" />
                    </label>
                </div>

                <div class="field" style="flex: 1;">
                    <label>Employee:
                        <select name="emp">
                            <option value="">All</option>
                            <?php while ($emp = $employeeList->fetch_assoc()): ?>
                                <option value="<?= $emp['ID'] ?>" <?= ($emp['ID'] == $employeeID ? 'selected' : '') ?>>
                                    <?= htmlspecialchars($emp['FirstName'] . ' ' . $emp['LastName']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </label>
                </div>

                <div class="field" style="flex: 1;">
                    <label>Round to Nearest (min):
                        <select name="rounding">
                            <?php foreach ([0, 5, 10, 15, 20, 25, 30] as $min): ?>
                                <option value="<?= $min ?>" <?= ($rounding == $min ? 'selected' : '') ?>>
                                    <?= $min ?> minutes
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <div class="buttons" style="align-self: end;">
                    <button type="submit">Filter</button>
                    <a href="overtime.php" class="btn-reset">Reset</a>
                </div>
            </div>
        </form>

        <hr>

        <h2>Overtime Report (<?= date('m/d/Y', strtotime($startDate)) ?> to <?= date('m/d/Y', strtotime($endDate)) ?>)</h2>
        <p style="color:#555; font-size:0.9em; margin-top:-10px;">
            Weeks end Friday. Overtime = hours over 40 per week. Hours are counted only for days within the selected period.
        </p>
        <table>
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Week Ending</th>
                    <th>Total Hours</th>
                    <th>Total (H:MM)</th>
                    <th>Overtime</th>
                    <th>OT (H:MM)</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($byEmployee)): ?>
                <tr><td colspan="6">No time punches found for the selected period.</td></tr>
            <?php else: ?>
                <?php $isFirstEmployee = true; ?>
                <?php foreach ($byEmployee as $empId => $data): ?>
                    <?php if (!$isFirstEmployee): ?>
                        <tr class="employee-spacer"><td colspan="6" style="height:18px; border-left:none; border-right:none; background-color:#fafafa;"></td></tr>
                    <?php endif; ?>
                    <?php $isFirstEmployee = false; ?>
                    <?php foreach ($data['weeks'] as $weekEnd => $hours): ?>
                        <?php $ot = max(0, $hours - 40); ?>
                        <tr<?= $ot > 0 ? ' style="background-color:#fff3cd;"' : '' ?>>
                            <td><?= htmlspecialchars($data['name']) ?></td>
                            <td><?= date('m/d/Y', strtotime($weekEnd)) ?></td>
                            <td><?= number_format($hours, 2) ?></td>
                            <td><?= decimalToHM($hours) ?></td>
                            <td><?= number_format($ot, 2) ?></td>
                            <td><?= decimalToHM($ot) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="summary-regular" style="font-weight:bold;">
                        <td colspan="2"><?= htmlspecialchars($data['name']) ?> &mdash; Period Total</td>
                        <td><?= number_format($data['totalHours'], 2) ?></td>
                        <td><?= decimalToHM($data['totalHours']) ?></td>
                        <td><?= number_format($data['totalOT'], 2) ?></td>
                        <td><?= decimalToHM($data['totalOT']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="summary-total">
                    <td colspan="2">Grand Total</td>
                    <td><?= number_format($grandTotalHours, 2) ?></td>
                    <td><?= decimalToHM($grandTotalHours) ?></td>
                    <td><?= number_format($grandTotalOT, 2) ?></td>
                    <td><?= decimalToHM($grandTotalOT) ?></td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>

        <div class="summary-controls">
            <form method="POST" action="export_overtime_excel.php">
                <input type="hidden" name="start" value="<?= $startDate ?>">
                <input type="hidden" name="end" value="<?= $endDate ?>">
                <input type="hidden" name="emp" value="<?= $employeeID ?>">
                <input type="hidden" name="rounding" value="<?= $rounding ?>">
                <button type="submit">Export to Excel</button>
            </form>

            <form method="POST" action="export_overtime_pdf.php">
                <input type="hidden" name="start" value="<?= $startDate ?>">
                <input type="hidden" name="end" value="<?= $endDate ?>">
                <input type="hidden" name="emp" value="<?= $employeeID ?>">
                <input type="hidden" name="rounding" value="<?= $rounding ?>">
                <button type="submit">Export to PDF</button>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/litepicker/dist/bundle.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    new Litepicker({
        element: document.getElementById('dateRange'),
        singleMode: false,
        numberOfMonths: 2,
        numberOfColumns: 2,
        format: 'MM/DD/YYYY',
        maxDays: 366,
        dropdowns: { minYear: 2020, maxYear: null, months: true, years: true },
        autoApply: true,
        tooltipText: { one: 'day', other: 'days' },
        tooltipNumber: totalDays => totalDays - 1
    });
});
</script>

<?php require_once 'footer.php'; ?>
