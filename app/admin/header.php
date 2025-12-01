<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database connection if not already included
if (!isset($conn)) {
    require_once '../auth/db.php';
}

// Include permission check functions
require_once __DIR__ . '/../functions/check_permission.php';

// Check admin authentication
if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

// Get pending approvals count for badge
$pendingCount = 0;
$pendingQuery = $conn->query("SELECT COUNT(*) as count FROM pending_edits WHERE Status = 'Pending'");
if ($pendingQuery) {
    $pendingRow = $pendingQuery->fetch_assoc();
    $pendingCount = $pendingRow['count'];
}

// Page title and extra CSS should be set before including this header
if (!isset($pageTitle)) {
    $pageTitle = "Admin Portal";
}
if (!isset($extraCSS)) {
    $extraCSS = [];
}

// Active page detection
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($pageTitle) ?> - D-Best TimeClock</title>
    <link rel="icon" type="image/png" href="/images/D-Best.png">
    <link rel="apple-touch-icon" href="/images/D-Best.png">
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" type="image/png" href="../images/D-Best-favicon.png">
    <link rel="apple-touch-icon" href="../images/D-Best-favicon.png">
    <link rel="icon" type="image/webp" href="../images/D-Best-favicon.webp">
    <link rel="stylesheet" href="../css/admin.css">
    <?php foreach ($extraCSS as $css): ?>
    <link rel="stylesheet" href="<?= $css ?>" />
    <?php endforeach; ?>
</head>
<body>

<header>
    <img src="/images/D-Best.png" alt="D-Best Logo" class="logo">
    <h1><?= htmlspecialchars($pageTitle) ?></h1>
    <nav>
        <a href="dashboard.php"<?= $currentPage == 'dashboard.php' ? ' class="active"' : '' ?>>Dashboard</a>
        <?php if (checkPermission('edit_timesheets')): ?>
        <a href="view_punches.php"<?= $currentPage == 'view_punches.php' ? ' class="active"' : '' ?>>Timesheets</a>
        <?php endif; ?>
        <?php if (checkPermission('approve_edits')): ?>
        <a href="edits_timesheet.php"<?= $currentPage == 'edits_timesheet.php' ? ' class="active"' : '' ?>>Pending Approvals<?php if ($pendingCount > 0): ?> (<?= $pendingCount ?>)<?php endif; ?></a>
        <?php endif; ?>
        <a href="reports.php"<?= $currentPage == 'reports.php' ? ' class="active"' : '' ?>>Reports</a>
        <?php if (checkPermission('manage_users')): ?>
        <a href="manage_users.php"<?= $currentPage == 'manage_users.php' ? ' class="active"' : '' ?>>Users</a>
        <?php endif; ?>
        <?php if (checkPermission('manage_offices')): ?>
        <a href="manage_offices.php"<?= $currentPage == 'manage_offices.php' ? ' class="active"' : '' ?>>Offices</a>
        <?php endif; ?>
        <?php if (checkPermission('view_attendance')): ?>
        <a href="attendance.php"<?= $currentPage == 'attendance.php' ? ' class="active"' : '' ?>>Attendance</a>
        <?php endif; ?>
        <?php if (checkPermission('manage_admins')): ?>
        <a href="manage_admins.php"<?= $currentPage == 'manage_admins.php' ? ' class="active"' : '' ?>>Admins</a>
        <?php endif; ?>
        <?php if (checkPermission('manage_settings')): ?>
        <a href="settings.php"<?= $currentPage == 'settings.php' ? ' class="active"' : '' ?>>Settings</a>
        <?php endif; ?>
        <a href="../logout.php">Logout</a>
    </nav>
</header>

<div class="dashboard-container">
    <div class="container">
