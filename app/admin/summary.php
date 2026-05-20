<?php
session_start();
require_once '../auth/db.php';

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

// Default to current week's Monday to Sunday
$defaultStart = date('Y-m-d', strtotime('monday this week'));
$defaultEnd = date('Y-m-d', strtotime('sunday this week'));

// Parse the 'dates' parameter
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
$separatePages = isset($_GET['separate_pages']) ? 1 : 0;

// Fetch employees for dropdown
$employeeList = $conn->query("SELECT ID, FirstName, LastName FROM users ORDER BY LastName");

// Build SQL query
$sql = "
    SELECT u.FirstName, u.LastName, tp.EmployeeID, tp.Date,
        SEC_TO_TIME(SUM(
            TIME_TO_SEC(TIMEDIFF(tp.TimeOUT, tp.TimeIN)) - 
            TIME_TO_SEC(TIMEDIFF(IFNULL(tp.LunchEnd, '00:00:00'), IFNULL(tp.LunchStart, '00:00:00')))
        )) AS TotalHours
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

$sql .= " GROUP BY tp.EmployeeID, tp.Date ORDER BY tp.Date DESC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    header('Location: ../error.php?code=500&message=' . urlencode('Query error: ' . $conn->error));
    exit;
}

$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// Helpers
function hmsToDecimal($hms) {
    list($h, $m, $s) = explode(':', $hms);
    return round($h + ($m / 60) + ($s / 3600), 2);
}
function roundToNearestMinutes($decimalHours, $interval = 0) {
    if ($interval <= 0) return $decimalHours;
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

$rows = [];
while ($row = $result->fetch_assoc()) {
    $decimal = hmsToDecimal($row['TotalHours']);
    $rounded = roundToNearestMinutes($decimal, $rounding);
    $row['DecimalHours'] = $rounded;
    $rows[] = $row;
}

// Reorganize for employee + week
$employeeWeeklyHours = [];
foreach ($rows as $row) {
    $empID = $row['EmployeeID'];
    $date = new DateTime($row['Date']);
    $weekStart = (clone $date)->modify('monday this week')->format('Y-m-d');

    if (!isset($employeeWeeklyHours[$empID])) {
        $employeeWeeklyHours[$empID] = [];
    }
    if (!isset($employeeWeeklyHours[$empID][$weekStart])) {
        $employeeWeeklyHours[$empID][$weekStart] = 0;
    }

    $employeeWeeklyHours[$empID][$weekStart] += $row['DecimalHours'];
}

// Accurate total regular/overtime
$totalRegular = 0;
$totalOvertime = 0;

foreach ($employeeWeeklyHours as $weeks) {
    foreach ($weeks as $hours) {
        $totalRegular += min($hours, 40);
        $totalOvertime += max($hours - 40, 0);
    }
}
$totalHoursDecimal = $totalRegular + $totalOvertime;

// Per-employee totals
$totalsPerEmployee = [];
if (empty($employeeID)) {
    foreach ($rows as $row) {
        $name = $row['FirstName'] . ' ' . $row['LastName'];
        $totalsPerEmployee[$name] = ($totalsPerEmployee[$name] ?? 0) + $row['DecimalHours'];
    }
}
$pageTitle = "Summary Reports";
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

                <div class="field" style="flex: 1; align-self: end;">
                    <label>
                        <input type="checkbox" name="separate_pages" value="1" <?= $separatePages ? 'checked' : '' ?>>
                        Separate pages per user
                    </label>
                </div>

                <div class="buttons" style="align-self: end;">
                    <button type="submit">Filter</button>
                    <a href="summary.php" class="btn-reset">Reset</a>
                </div>
            </div>
        </form>

        <hr>

        <h2>Summary (<?= date('m/d/Y', strtotime($startDate)) ?> to <?= date('m/d/Y', strtotime($endDate)) ?>)</h2>
        <table>
            <tr>
                <th>Employee</th>
                <th>Date</th>
                <th>Total Hours (Decimal)</th>
                <th>Total Hours (H:MM)</th>
            </tr>
            <?php if (count($rows)): ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['FirstName'] . ' ' . $row['LastName']) ?></td>
                        <td><?= htmlspecialchars(date('m/d/Y', strtotime($row['Date']))) ?></td>
                        <td><?= number_format($row['DecimalHours'], 2) ?></td>
                        <td><?= decimalToHM($row['DecimalHours']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="4">No time punches found for the selected filters.</td></tr>
            <?php endif; ?>
            <tr class="summary-total">
                <td colspan="2">Total Hours</td>
                <td><?= number_format($totalHoursDecimal, 2) ?></td>
                <td><?= decimalToHM($totalHoursDecimal) ?></td>
            </tr>
            <tr class="summary-regular">
                <td colspan="2">Regular Hours (≤ 40/week)</td>
                <td><?= number_format($totalRegular, 2) ?></td>
                <td><?= decimalToHM($totalRegular) ?></td>
            </tr>
            <tr class="summary-overtime">
                <td colspan="2">Overtime Hours (> 40/week)</td>
                <td><?= number_format($totalOvertime, 2) ?></td>
                <td><?= decimalToHM($totalOvertime) ?></td>
            </tr>
        </table>

        <?php if (empty($employeeID) && count($totalsPerEmployee)): ?>
            <div class="per-employee-summary">
                <h3>Per Employee Total</h3>
                <ul>
                    <?php foreach ($totalsPerEmployee as $name => $total): ?>
                        <li><strong><?= htmlspecialchars($name) ?></strong>: <?= number_format($total, 2) ?> hrs (<?= decimalToHM($total) ?>)</li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="summary-controls">
            <form method="POST" action="export_summary_excel.php">
                <input type="hidden" name="start" value="<?= $startDate ?>">
                <input type="hidden" name="end" value="<?= $endDate ?>">
                <input type="hidden" name="emp" value="<?= $employeeID ?>">
                <input type="hidden" name="rounding" value="<?= $rounding ?>">
                <input type="hidden" name="separate_pages" value="<?= $separatePages ?>">
                <button type="submit">Export to Excel</button>
            </form>

            <form method="POST" action="export_summary_pdf.php">
                <input type="hidden" name="start" value="<?= $startDate ?>">
                <input type="hidden" name="end" value="<?= $endDate ?>">
                <input type="hidden" name="emp" value="<?= $employeeID ?>">
                <input type="hidden" name="rounding" value="<?= $rounding ?>">
                <input type="hidden" name="separate_pages" value="<?= $separatePages ?>">
                <button type="submit">Export to PDF</button>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/litepicker/dist/bundle.js"></script>
<script src="../js/summary.js"></script>


<?php require_once 'footer.php'; ?>
