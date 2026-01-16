<?php
/**
 * Timesheet Report - Read-only detailed punch times
 * Accessible to reports_only role (requires view_reports permission)
 */

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

$pageTitle = "Timesheet Report";
$extraCSS = ["https://cdn.jsdelivr.net/npm/litepicker/dist/css/litepicker.css", "../css/summary.css"];
require_once 'header.php';

// Permission check - only requires view_reports (accessible to reports_only role)
requirePermission('view_reports');

// Fetch employees for dropdown
$employeeList = $conn->query("SELECT ID, FirstName, LastName FROM users ORDER BY LastName, FirstName");

// Build SQL query - fetch individual punch records (not grouped)
$sql = "
    SELECT u.FirstName, u.LastName, tp.EmployeeID, tp.Date,
           tp.TimeIN, tp.LunchStart, tp.LunchEnd, tp.TimeOut, tp.TotalHours
    FROM timepunches tp
    JOIN users u ON u.ID = tp.EmployeeID
    WHERE tp.Date BETWEEN ? AND ?
";

$params = [$startDate, $endDate];
$types = 'ss';

if ($employeeID !== '') {
    $sql .= " AND tp.EmployeeID = ?";
    $params[] = $employeeID;
    $types .= 'i';
}

$sql .= " ORDER BY tp.Date DESC, u.LastName, u.FirstName, tp.TimeIN";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    header('Location: ../error.php?code=500&message=' . urlencode('Query error: ' . $conn->error));
    exit;
}

$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// Helper function for time formatting
function formatTime($time) {
    return !empty($time) ? date('h:i A', strtotime($time)) : '--';
}

// Collect rows and calculate total
$rows = [];
$totalHours = 0;
while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
    $totalHours += ($row['TotalHours'] ?? 0);
}
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
                            <option value="">All Employees</option>
                            <?php while ($emp = $employeeList->fetch_assoc()): ?>
                                <option value="<?= $emp['ID'] ?>" <?= ($emp['ID'] == $employeeID ? 'selected' : '') ?>>
                                    <?= htmlspecialchars($emp['FirstName'] . ' ' . $emp['LastName']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </label>
                </div>

                <div class="buttons" style="align-self: end;">
                    <button type="submit">Filter</button>
                    <a href="timesheet_report.php" class="btn-reset">Reset</a>
                </div>
            </div>
        </form>

        <hr>

        <h2>Timesheet Report (<?= date('m/d/Y', strtotime($startDate)) ?> to <?= date('m/d/Y', strtotime($endDate)) ?>)</h2>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Employee</th>
                    <th>Clock In</th>
                    <th>Lunch Start</th>
                    <th>Lunch End</th>
                    <th>Clock Out</th>
                    <th>Total Hours</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($rows) > 0): ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars(date('m/d/Y', strtotime($row['Date']))) ?></td>
                            <td><?= htmlspecialchars($row['FirstName'] . ' ' . $row['LastName']) ?></td>
                            <td><?= formatTime($row['TimeIN']) ?></td>
                            <td><?= formatTime($row['LunchStart']) ?></td>
                            <td><?= formatTime($row['LunchEnd']) ?></td>
                            <td><?= formatTime($row['TimeOut']) ?></td>
                            <td><?= number_format($row['TotalHours'] ?? 0, 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7">No time punches found for the selected filters.</td></tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr class="summary-total">
                    <td colspan="6"><strong>Total Hours</strong></td>
                    <td><strong><?= number_format($totalHours, 2) ?></strong></td>
                </tr>
            </tfoot>
        </table>

        <?php if (checkPermission('export_reports')): ?>
        <div class="summary-controls">
            <form method="POST" action="export_timesheet_excel.php">
                <input type="hidden" name="start" value="<?= htmlspecialchars($startDate) ?>">
                <input type="hidden" name="end" value="<?= htmlspecialchars($endDate) ?>">
                <input type="hidden" name="emp" value="<?= htmlspecialchars($employeeID) ?>">
                <button type="submit">Export to Excel</button>
            </form>

            <form method="POST" action="export_timesheet_pdf.php">
                <input type="hidden" name="start" value="<?= htmlspecialchars($startDate) ?>">
                <input type="hidden" name="end" value="<?= htmlspecialchars($endDate) ?>">
                <input type="hidden" name="emp" value="<?= htmlspecialchars($employeeID) ?>">
                <button type="submit">Export to PDF</button>
            </form>
        </div>
        <?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/litepicker/dist/bundle.js"></script>
<script src="../js/timesheet_report.js"></script>

<?php require_once 'footer.php'; ?>
