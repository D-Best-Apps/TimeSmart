<?php
session_start();
if (!isset($_SESSION['admin'])) {
    header("Location: ../user/login.php?admin=1");
    exit;
}

require '../auth/db.php';

$adminUsername = $_SESSION['admin'];

// (Self-serve 2FA enable/disable now lives in the header profile menu.)

// Get count of items actually needing review (matches the header badge + approvals page)
require_once __DIR__ . '/../functions/pending_count.php';
$pendingCount = getPendingApprovalCount($conn);

// Additional stats
$totalUsers = 0;
$clockedIn = 0;
$onLunch = 0;
$clockedOut = 0;

$statsQuery = "
    SELECT
        COUNT(*) as totalUsers,
        SUM(IF(ClockStatus = 'In', 1, 0)) as clockedIn,
        SUM(IF(ClockStatus = 'Lunch', 1, 0)) as onLunch,
        SUM(IF(ClockStatus = 'Out', 1, 0)) as clockedOut
    FROM users
";
$statsResult = $conn->query($statsQuery);
if ($statsResult && $statsRow = $statsResult->fetch_assoc()) {
    $totalUsers = (int) ($statsRow['totalUsers'] ?? 0);
    $clockedIn = (int) ($statsRow['clockedIn'] ?? 0);
    $onLunch = (int) ($statsRow['onLunch'] ?? 0);
    $clockedOut = (int) ($statsRow['clockedOut'] ?? 0);
}

$pageTitle = "Admin Dashboard";
$extraCSS = ["../css/dashboard.css"];
require_once 'header.php';
?>

<div class="info-stats">
    <a href="edits_timesheet.php" class="info-card pending">
        <h3>Needs Approval</h3>
        <div class="count"><?= $pendingCount ?></div>
    </a>
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

<div class="dashboard">
        <div class="card">
            <h2>Timesheets</h2>
            <p>Review and manage employee time punches.</p>
            <a href="view_punches.php">Open</a>
        </div>
        <div class="card">
            <h2>Pending Approvals</h2>
            <p><?= $pendingCount ?> <?= $pendingCount === 1 ? 'item needs' : 'items need' ?> review.</p>
            <a href="edits_timesheet.php">Review</a>
        </div>
        <div class="card">
            <h2>Reports</h2>
            <p>View all available reports (summary, missed days, tardies, etc.).</p>
            <a href="reports.php">Open</a>
        </div>
        <div class="card">
            <h2>Users</h2>
            <p>Add, edit, or remove employee accounts.</p>
            <a href="manage_users.php">Open</a>
        </div>
        <div class="card">
            <h2>Offices</h2>
            <p>Add, edit, or remove office locations.</p>
            <a href="manage_offices.php">Open</a>
        </div>
        <div class="card">
            <h2>Attendance</h2>
            <p>View employee attendance calendar.</p>
            <a href="attendance.php">Open</a>
        </div>
        <div class="card">
            <h2>Admins</h2>
            <p>Create, edit, or remove admin accounts.</p>
            <a href="manage_admins.php">Open</a>
        </div>
        <div class="card">
            <h2>Settings</h2>
            <p>Configure system settings and preferences.</p>
            <a href="settings.php">Open</a>
        </div>
    </div>

<?php require_once 'footer.php'; ?>
