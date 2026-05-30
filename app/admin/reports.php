<?php
$pageTitle = "Reports";
$extraCSS = ["../css/reports.css?v=3"];
require_once 'header.php';

// Live status stats shown at the top (formerly the admin dashboard).
// $pendingCount is already computed in header.php via getPendingApprovalCount().
$totalUsers = $clockedIn = $onLunch = $clockedOut = 0;
$statsResult = $conn->query("
    SELECT
        COUNT(*) AS totalUsers,
        SUM(IF(ClockStatus = 'In', 1, 0))    AS clockedIn,
        SUM(IF(ClockStatus = 'Lunch', 1, 0)) AS onLunch,
        SUM(IF(ClockStatus = 'Out', 1, 0))   AS clockedOut
    FROM users
");
if ($statsResult && $statsRow = $statsResult->fetch_assoc()) {
    $totalUsers = (int) ($statsRow['totalUsers'] ?? 0);
    $clockedIn  = (int) ($statsRow['clockedIn'] ?? 0);
    $onLunch    = (int) ($statsRow['onLunch'] ?? 0);
    $clockedOut = (int) ($statsRow['clockedOut'] ?? 0);
}
?>

<div class="info-stats">
    <?php if (checkPermission('approve_edits')): ?>
    <a href="edits_timesheet.php" class="info-card pending">
        <h3>Needs Approval</h3>
        <div class="count"><?= $pendingCount ?></div>
    </a>
    <?php else: ?>
    <div class="info-card pending">
        <h3>Needs Approval</h3>
        <div class="count"><?= $pendingCount ?></div>
    </div>
    <?php endif; ?>
    <div class="info-card users">
        <h3>Total Users</h3>
        <div class="count"><?= $totalUsers ?></div>
    </div>
    <div class="info-card in">
        <h3>Clocked In</h3>
        <div class="count"><?= $clockedIn ?></div>
    </div>
    <div class="info-card lunch">
        <h3>On Lunch</h3>
        <div class="count"><?= $onLunch ?></div>
    </div>
    <div class="info-card out">
        <h3>Clocked Out</h3>
        <div class="count"><?= $clockedOut ?></div>
    </div>
</div>

        <h2>Available Reports</h2>
        <div class="reports-container">
            <div class="report-card">
                <h3>Summary Report</h3>
                <p>View total hours, regular, and overtime hours across all employees or individually.</p>
                <a href="summary.php">Open Summary</a>
            </div>

            <div class="report-card">
                <h3>Timesheet Report</h3>
                <p>View detailed punch times including clock in/out and lunch periods for all employees.</p>
                <a href="timesheet_report.php">Open Timesheet Report</a>
            </div>

            <div class="report-card">
                <h3>Missed Days Report</h3>
                <p>Payroll tally of missed work days per employee, by Wednesday&ndash;Tuesday pay period (days worked = hours &divide; 8).</p>
                <a href="missed_days.php">Open Missed Days</a>
            </div>

            <div class="report-card">
                <h3>Attendance Report</h3>
                <p>Day-by-day calendar grid showing each employee's Present, Incomplete, or Absent status (plus Sick/PTO) per day.</p>
                <a href="attendance.php">Open Attendance</a>
            </div>

            <div class="report-card">
                <h3>Tardies Report</h3>
                <p>Track late arrivals by pay period with scheduled start times. Separates tardies under and over 5 minutes.</p>
                <a href="tardies.php">Open Tardies</a>
            </div>

            <div class="report-card">
                <h3>Overtime Report</h3>
                <p>Per-employee overtime by week (Friday week endings) for the selected period. Defaults to last month.</p>
                <a href="overtime.php">Open Overtime</a>
            </div>
        </div>

<?php require_once 'footer.php'; ?>
