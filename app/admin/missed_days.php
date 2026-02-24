<?php
// Default to current month
$defaultStart = date('Y-m-d', strtotime('first day of this month'));
$defaultEnd = date('Y-m-d', strtotime('last day of this month'));

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

// Helper function to convert HH:MM:SS to decimal hours
function hmsToDecimal($hms) {
    if (empty($hms)) return 0;
    list($h, $m, $s) = explode(':', $hms);
    return round($h + ($m / 60) + ($s / 3600), 2);
}

// Helper function to get pay period start (Wednesday) for a given date
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

// Helper function to get pay period end (Tuesday) for a given start date
function getPayPeriodEnd($startDate) {
    $dt = new DateTime($startDate);
    $dt->modify('+6 days');
    return $dt->format('Y-m-d');
}

// Generate all complete pay periods in the date range
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

$pageTitle = "Missed Days Report";
$extraCSS = ["https://cdn.jsdelivr.net/npm/litepicker/dist/css/litepicker.css", "../css/summary.css"];
require_once 'header.php';

// Fetch employees for dropdown
$employeeList = $conn->query("SELECT ID, FirstName, LastName FROM users ORDER BY LastName");

// Get list of employees to include
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

// Build report data for each employee and each pay period
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
?>


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
                            <?php
                            $employeeList->data_seek(0);
                            while ($emp = $employeeList->fetch_assoc()):
                            ?>
                                <option value="<?= $emp['ID'] ?>" <?= ($emp['ID'] == $employeeID ? 'selected' : '') ?>>
                                    <?= htmlspecialchars($emp['FirstName'] . ' ' . $emp['LastName']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </label>
                </div>

                <div class="buttons" style="align-self: end;">
                    <button type="submit">Filter</button>
                    <a href="missed_days.php" class="btn-reset">Reset</a>
                </div>
            </div>
        </form>

        <hr>

        <div style="background: #f0f8ff; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
            <strong>Note:</strong> This report shows missed work days based on Wednesday-to-Tuesday pay periods.
            A full work week is 40 hours (5 days). Days worked are calculated as floor(hours / 8).
            Incomplete pay periods (current week) are excluded. Pay periods with 0 hours worked (no clock-ins) are shown.
        </div>

        <h2>Missed Days Report (<?= date('m/d/Y', strtotime($startDate)) ?> to <?= date('m/d/Y', strtotime($endDate)) ?>)</h2>

        <?php if (count($employeeTotals) > 0): ?>
            <h3>Summary Totals by Employee</h3>
            <table style="margin-bottom: 30px;">
                <tr>
                    <th>Employee</th>
                    <th>Total Missed Days</th>
                </tr>
                <?php foreach ($employeeTotals as $empName => $totalMissed): ?>
                    <tr>
                        <td><?= htmlspecialchars($empName) ?></td>
                        <td style="text-align: center;"><strong><?= $totalMissed ?></strong></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>

        <h3>Detailed Breakdown by Pay Period</h3>
        <table>
            <tr>
                <th>Employee</th>
                <th>Pay Period</th>
                <th>Hours Worked</th>
                <th>Days Worked</th>
                <th>Missed Days</th>
            </tr>
            <?php if (count($reportData)): ?>
                <?php foreach ($reportData as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['employee']) ?></td>
                        <td><?= date('m/d/Y', strtotime($row['period_start'])) ?> - <?= date('m/d/Y', strtotime($row['period_end'])) ?></td>
                        <td><?= number_format($row['hours_worked'], 2) ?></td>
                        <td><?= $row['days_worked'] ?></td>
                        <td><?= $row['missed_days'] ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="5">No completed pay periods found for the selected filters.</td></tr>
            <?php endif; ?>
        </table>

        <div class="summary-controls">
            <form method="POST" action="export_missed_days_excel.php">
                <input type="hidden" name="start" value="<?= $startDate ?>">
                <input type="hidden" name="end" value="<?= $endDate ?>">
                <input type="hidden" name="emp" value="<?= $employeeID ?>">
                <button type="submit">Export to Excel</button>
            </form>

            <form method="POST" action="export_missed_days_pdf.php">
                <input type="hidden" name="start" value="<?= $startDate ?>">
                <input type="hidden" name="end" value="<?= $endDate ?>">
                <input type="hidden" name="emp" value="<?= $employeeID ?>">
                <button type="submit">Export to PDF</button>
            </form>
        </div>

<script src="https://cdn.jsdelivr.net/npm/litepicker/dist/bundle.js"></script>
<script>
const picker = new Litepicker({
    element: document.getElementById('dateRange'),
    singleMode: false,
    format: 'MM/DD/YYYY',
    delimiter: ' - ',
    numberOfMonths: 2,
    numberOfColumns: 2,
    autoApply: true,
    dropdowns: {
        minYear: 2020,
        maxYear: null,
        months: true,
        years: true
    }
});
</script>

<?php require_once 'footer.php'; ?>
