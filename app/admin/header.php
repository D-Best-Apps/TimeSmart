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

// Get pending approvals count for badge (real timesheet edits + time-off requests)
require_once __DIR__ . '/../functions/pending_count.php';
$pendingCount = getPendingApprovalCount($conn);

// Current admin's name, avatar and 2FA status for the profile corner menu
$adminName   = 'Account';
$adminAvatar = '../images/default_avatar.png';
$is2FAEnabled = 0;
if (!empty($_SESSION['EmployeeID'])) {
    $meID = (int) $_SESSION['EmployeeID'];
    $meStmt = $conn->prepare("SELECT FirstName, TwoFAEnabled FROM users WHERE ID = ?");
    $meStmt->bind_param("i", $meID);
    $meStmt->execute();
    if ($me = $meStmt->get_result()->fetch_assoc()) {
        $adminName    = $me['FirstName'] ?: 'Account';
        $is2FAEnabled = (int) $me['TwoFAEnabled'];
    }
    foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) {
        $try = "../avatars/{$meID}_pro.$ext";
        if (file_exists($try)) {
            $adminAvatar = $try;
            break;
        }
    }
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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle) ?> - D-Best TimeClock</title>
    <link rel="icon" type="image/png" href="/images/D-Best.png">
    <link rel="apple-touch-icon" href="/images/D-Best.png">
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" type="image/png" href="../images/D-Best-favicon.png">
    <link rel="apple-touch-icon" href="../images/D-Best-favicon.png">
    <link rel="icon" type="image/webp" href="../images/D-Best-favicon.webp">
    <link rel="stylesheet" href="../css/admin.css?v=5">
    <style>
        /* Fallback so the new nav elements are never unstyled if admin.css is cached */
        .admin-profile-avatar { width: 30px; height: 30px; border-radius: 50%; object-fit: cover; }
        .nav-dropdown-menu.hidden { display: none; }
    </style>
    <?php foreach ($extraCSS as $css): ?>
    <link rel="stylesheet" href="<?= $css ?>" />
    <?php endforeach; ?>
</head>
<body class="admin-page page-<?= htmlspecialchars(basename($currentPage, '.php')) ?>">

<?php
// Pages that live under the Settings dropdown — used for active-state highlight
$settingsPages = ['settings.php', 'manage_users.php', 'manage_offices.php', 'manage_admins.php'];
$settingsActive = in_array($currentPage, $settingsPages, true);
$showSettingsMenu = checkPermission('manage_settings') || checkPermission('manage_users')
                 || checkPermission('manage_offices') || checkPermission('manage_admins');
?>
<header>
    <a href="reports.php" title="Admin home"><img src="/images/D-Best.png" alt="D-Best Logo" class="logo"></a>
    <h1><?= htmlspecialchars($pageTitle) ?></h1>
    <nav>
        <a href="reports.php"<?= $currentPage == 'reports.php' ? ' class="active"' : '' ?>>Reports</a>
        <?php if (checkPermission('edit_timesheets')): ?>
        <a href="view_punches.php"<?= $currentPage == 'view_punches.php' ? ' class="active"' : '' ?>>Timesheets</a>
        <?php endif; ?>
        <?php if (checkPermission('approve_edits')): ?>
        <a href="edits_timesheet.php"<?= $currentPage == 'edits_timesheet.php' ? ' class="active"' : '' ?>>Pending Approvals<?php if ($pendingCount > 0): ?> (<?= $pendingCount ?>)<?php endif; ?></a>
        <?php endif; ?>
        <?php if (checkPermission('manage_users')): ?>
        <a href="badges.php"<?= $currentPage == 'badges.php' ? ' class="active"' : '' ?>>🪪 Badges</a>
        <?php endif; ?>

        <?php if ($showSettingsMenu): ?>
        <div class="nav-dropdown">
            <button type="button" class="nav-dropdown-toggle<?= $settingsActive ? ' active' : '' ?>" onclick="toggleNavMenu('settingsMenu', this)">Settings ▾</button>
            <div id="settingsMenu" class="nav-dropdown-menu hidden">
                <?php if (checkPermission('manage_settings')): ?>
                <a href="settings.php"<?= $currentPage == 'settings.php' ? ' class="active"' : '' ?>>⚙️ General Settings</a>
                <?php endif; ?>
                <?php if (checkPermission('manage_users')): ?>
                <a href="manage_users.php"<?= $currentPage == 'manage_users.php' ? ' class="active"' : '' ?>>👥 Users</a>
                <?php endif; ?>
                <?php if (checkPermission('manage_offices')): ?>
                <a href="manage_offices.php"<?= $currentPage == 'manage_offices.php' ? ' class="active"' : '' ?>>🏢 Offices</a>
                <?php endif; ?>
                <?php if (checkPermission('manage_admins')): ?>
                <a href="manage_admins.php"<?= $currentPage == 'manage_admins.php' ? ' class="active"' : '' ?>>🛡️ Admins</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </nav>

    <div class="admin-profile">
        <button type="button" class="admin-profile-trigger" onclick="toggleNavMenu('profileMenu', this)">
            <img src="<?= htmlspecialchars($adminAvatar) ?>" alt="Avatar" class="admin-profile-avatar">
            <span class="admin-profile-name"><?= htmlspecialchars($adminName) ?></span> ▾
        </button>
        <div id="profileMenu" class="nav-dropdown-menu hidden">
            <?php if (!empty($_SESSION['EmployeeID'])): ?>
            <a href="../user/dashboard.php" title="Switch to your employee view">🏠 User Home</a>
            <a href="../user/settings.php">⚙️ User Settings</a>
            <a href="../user/timesheet.php">✏️ Edit Timesheet</a>
            <a href="../user/time_off.php">🏖️ Request Time Off</a>
            <?php endif; ?>
            <?php if (!empty($_SESSION['admin_role'])): ?>
            <a href="reports.php">🛡️ Admin Portal</a>
            <?php endif; ?>
            <a href="../logout.php">↩️ Logout</a>
        </div>
    </div>
</header>

<script>
function toggleNavMenu(id, trigger) {
    var menu = document.getElementById(id);
    if (!menu) return;
    // Close any other open menus first
    document.querySelectorAll('.nav-dropdown-menu').forEach(function (m) {
        if (m !== menu) m.classList.add('hidden');
    });
    menu.classList.toggle('hidden');
}

window.addEventListener('click', function (e) {
    document.querySelectorAll('.nav-dropdown-menu').forEach(function (menu) {
        if (menu.classList.contains('hidden')) return;
        var wrap = menu.closest('.nav-dropdown, .admin-profile');
        if (wrap && !wrap.contains(e.target)) menu.classList.add('hidden');
    });
});
</script>

<div class="dashboard-container">
    <div class="container">
