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

// Helper functions
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

$pageTitle = "Tardies Report";
$extraCSS = ["https://cdn.jsdelivr.net/npm/litepicker/dist/css/litepicker.css", "../css/summary.css"];
require_once 'header.php';

// Fetch employees for dropdown
$employeeList = $conn->query("SELECT ID, FirstName, LastName FROM users ORDER BY LastName");

// Build SQL query - Get first clock in per day for weekdays only
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

// Process results and group by employee and pay period
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

// Calculate totals per employee and create report data
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

$grandTotalUnder5 = 0;
$grandTotal5Plus = 0;
foreach ($reportData as $row) {
    $grandTotalUnder5 += $row['tardies_under_5'];
    $grandTotal5Plus += $row['tardies_5_plus'];
}
$grandTotal = $grandTotalUnder5 + $grandTotal5Plus;
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
                    <a href="tardies.php" class="btn-reset">Reset</a>
                </div>
            </div>
        </form>

        <hr>

        <div style="background: #fff3cd; padding: 15px; border-radius: 5px; margin-bottom: 20px; border: 1px solid #ffc107;">
            <strong>Note:</strong> This report tracks tardies based on scheduled start times for weekdays only (Mon-Fri).
            Tardies are grouped by pay period (Wednesday-Tuesday). Only employees with a scheduled start time are included.
            Incomplete pay periods are excluded.
        </div>

        <h2>Tardies by Pay Period (<?= date('m/d/Y', strtotime($startDate)) ?> to <?= date('m/d/Y', strtotime($endDate)) ?>)</h2>
        <table>
            <tr>
                <th>Employee</th>
                <th>Scheduled Time</th>
                <th>Pay Period</th>
                <th>Tardies &lt;5 min</th>
                <th>Tardies ≥5 min</th>
                <th>Total Tardies</th>
            </tr>
            <?php if (count($reportData)): ?>
                <?php foreach ($reportData as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['employee']) ?></td>
                        <td><?= date('g:i A', strtotime($row['scheduled_time'])) ?></td>
                        <td><?= date('m/d/Y', strtotime($row['period_start'])) ?> - <?= date('m/d/Y', strtotime($row['period_end'])) ?></td>
                        <td style="text-align: center;"><?= $row['tardies_under_5'] ?></td>
                        <td style="text-align: center;"><?= $row['tardies_5_plus'] ?></td>
                        <td style="text-align: center;"><strong><?= $row['total_tardies'] ?></strong></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="6">No tardies found for the selected filters and date range.</td></tr>
            <?php endif; ?>
        </table>

        <?php if (count($employeeTotals) > 0): ?>
            <h3 style="margin-top: 30px;">Summary Totals by Employee</h3>
            <table>
                <tr>
                    <th>Employee</th>
                    <th>Total Tardies &lt;5 min</th>
                    <th>Total Tardies ≥5 min</th>
                    <th>Total All Tardies</th>
                </tr>
                <?php foreach ($employeeTotals as $empName => $totals): ?>
                    <tr>
                        <td><?= htmlspecialchars($empName) ?></td>
                        <td style="text-align: center;"><?= $totals['under_5'] ?></td>
                        <td style="text-align: center;"><?= $totals['5_plus'] ?></td>
                        <td style="text-align: center;"><strong><?= $totals['total'] ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="summary-total">
                    <td><strong>GRAND TOTAL</strong></td>
                    <td style="text-align: center;"><strong><?= $grandTotalUnder5 ?></strong></td>
                    <td style="text-align: center;"><strong><?= $grandTotal5Plus ?></strong></td>
                    <td style="text-align: center;"><strong><?= $grandTotal ?></strong></td>
                </tr>
            </table>
        <?php endif; ?>

        <div class="summary-controls">
            <form method="POST" action="export_tardies_excel.php">
                <input type="hidden" name="start" value="<?= $startDate ?>">
                <input type="hidden" name="end" value="<?= $endDate ?>">
                <input type="hidden" name="emp" value="<?= $employeeID ?>">
                <button type="submit">Export to Excel</button>
            </form>

            <form method="POST" action="export_tardies_pdf.php">
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
