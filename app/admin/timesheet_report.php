<?php
/**
 * Timesheet Report - Read-only detailed punch times
 * Accessible to reports_only role (requires view_reports permission)
 */

require_once '../auth/db.php'; // before hours.php is used: the week start comes from settings
require_once __DIR__ . '/../functions/hours.php';
require_once __DIR__ . '/../functions/timesheet_report_lines.php'; // time off + holiday lines

// Default to the current work week
$defaultStart = payPeriodStart(date('Y-m-d'));
$defaultEnd = payPeriodEnd(date('Y-m-d'));

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
$employeeList = $conn->query("SELECT ID, FirstName, CONCAT(LastName, IF(ArchivedAt IS NULL, '', ' (archived)')) AS LastName FROM users ORDER BY ArchivedAt IS NOT NULL, LastName, FirstName"); // archived marked, listed last

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
// Approved time off and holiday hours, listed between the punches (as in the exports)
$extras = timesheetReportExtraLines($conn, $startDate, $endDate, $employeeID !== '' ? (int) $employeeID : null);
$items  = timesheetReportMerge($rows, $extras['lines']);
$xTotals = $extras['totals'];
?>
<style>
    tr.ts-extra td { color: #222; }
    tr.ts-extra .ts-note { color: #555; font-size: .85em; margin-left: .4rem; }
    <?php foreach (TIMESHEET_REPORT_LINE_COLORS as $k => $c): ?>tr.ts-extra-<?= strtolower($k) ?> td { background: #<?= $c ?>; }
    <?php endforeach; ?>
</style>

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
                <?php if (count($items) > 0): ?>
                    <?php foreach ($items as $item):
                        if (isset($item['extra'])): $x = $item['extra']; ?>
                        <tr class="ts-extra ts-extra-<?= strtolower($x['kind']) ?>">
                            <td><?= htmlspecialchars(date('m/d/Y', strtotime($x['Date']))) ?></td>
                            <td><?= htmlspecialchars($x['FirstName'] . ' ' . $x['LastName']) ?></td>
                            <td colspan="4"><?= htmlspecialchars($x['label']) ?><?php if ($x['note'] !== ''): ?><span class="ts-note"><?= htmlspecialchars($x['note']) ?></span><?php endif; ?></td>
                            <td><?= $x['hours'] !== null ? number_format($x['hours'], 2) : '—' ?></td>
                        </tr>
                        <?php continue; endif;
                        $row = $item['punch']; ?>
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
                    <tr><td colspan="7">No time punches or time off found for the selected filters.</td></tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr class="summary-total">
                    <td colspan="6"><strong><?= array_sum($xTotals) > 0 ? 'Worked Hours' : 'Total Hours' ?></strong></td>
                    <td><strong><?= number_format($totalHours, 2) ?></strong></td>
                </tr>
                <?php if (array_sum($xTotals) > 0): ?>
                <?php foreach (['PTO' => 'PTO', 'Sick' => 'Sick', 'Holiday' => 'Holiday'] as $k => $lbl): if ($xTotals[$k] <= 0) continue; ?>
                <tr class="summary-total"><td colspan="6"><?= $lbl ?></td><td><?= number_format($xTotals[$k], 2) ?></td></tr>
                <?php endforeach; ?>
                <tr class="summary-total">
                    <td colspan="6"><strong>Total Paid Hours</strong></td>
                    <td><strong><?= number_format($totalHours + array_sum($xTotals), 2) ?></strong></td>
                </tr>
                <?php endif; ?>
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
