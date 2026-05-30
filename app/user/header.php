<?php
date_default_timezone_set('America/Chicago');
session_start();
require '../auth/db.php';

if (!isset($_SESSION['EmployeeID'])) {
    header("Location: login.php");
    exit;
}

$empID = $_SESSION['EmployeeID'];

// Fetch user data including ThemePref
$stmt = $conn->prepare("SELECT FirstName, LastName, ThemePref FROM users WHERE ID = ?");
$stmt->bind_param("i", $empID);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

$name = $user['FirstName'];
$fullName = $user['FirstName'] . ' ' . $user['LastName'];
$theme = $user['ThemePref'] ?? 'light'; // Default to light theme

// Avatar logic
$avatarPath = "../images/default_avatar.png";
$extensions = ['png', 'jpg', 'jpeg', 'webp'];
foreach ($extensions as $ext) {
    $try = "../avatars/{$empID}_pro.$ext";
    if (file_exists($try)) {
        $avatarPath = $try;
        break;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>User Dashboard</title>
    <link rel="stylesheet" href="../css/user.css?v=2">
    <link rel="stylesheet" href="../css/style.css?v=2">
    <link rel="icon" type="image/webp" href="../images/D-Best-favicon.webp">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="../js/user_header.js"></script>
</head>
<body data-theme="<?= $theme ?>">
    <?php include '../functions/modal.html'; ?>

<header class="topnav desktop-only">
  <a class="topnav-left" href="dashboard.php" title="Home" style="display:flex; align-items:center; gap:10px; text-decoration:none; color:inherit;">
    <img src="../images/D-Best-favicon.webp" class="nav-logo" alt="Logo">
    <span class="nav-title">D-Best TimeSmart</span>
  </a>
  <div class="topnav-right">
    <span class="nav-date"><?= date('F j, Y') ?></span>
    <div class="profile-dropdown">
      <div class="profile-trigger" onclick="toggleDropdown()">
        <img src="<?= $avatarPath ?>" alt="Avatar" class="profile-avatar">
        <span class="profile-name"><?= htmlspecialchars($name) ?></span> ▾
      </div>
      <div id="profileMenu" class="dropdown-menu hidden">
        <a href="dashboard.php">🏠 User Home</a>
        <a href="settings.php">⚙️ User Settings</a>
        <a href="timesheet.php">✏️ Edit Timesheet</a>
        <a href="time_off.php">🏖️ Request Time Off</a>
        <?php if (!empty($_SESSION['admin_role'])): ?>
        <a href="../admin/reports.php">🛡️ Admin Portal</a>
        <?php endif; ?>
        <a href="../logout.php">↩️ Logout</a>
      </div>
    </div>
  </div>
</header>

<nav class="mobile-nav mobile-only">
  <a href="dashboard.php">🏠 Home</a>
  <a href="timesheet.php">📄 Sheet</a>
  <a href="time_off.php">🏖️ Time Off</a>
  <?php if (!empty($_SESSION['admin_role'])): ?>
  <a href="../admin/reports.php">🛡️ Admin</a>
  <?php endif; ?>
  <a href="../logout.php">↩️ Logout</a>
  <a href="settings.php">⚙️</a>
</nav>

<div class="wrapper">
    <div class="main">
