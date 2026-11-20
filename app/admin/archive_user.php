<?php
// admin/archive_user.php
// Archives an employee: they can't sign in or punch and drop out of active lists, but
// every punch, time-off entry, adjustment and log is kept (see functions/archived_users.php).
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

// Permission check
require_once __DIR__ . '/../functions/check_permission.php';
requirePermission('manage_users');
require_once __DIR__ . '/../functions/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: manage_users.php");
    exit;
}
require_csrf('manage_users.php?msg=' . urlencode('The page had been open too long. Nothing was archived; please try again.'));

$id = (int) ($_POST['ID'] ?? 0);
if ($id <= 0) {
    header("Location: manage_users.php");
    exit;
}
if ($id === (int) ($_SESSION['EmployeeID'] ?? 0)) {
    header("Location: manage_users.php?msg=" . urlencode("You can't archive yourself."));
    exit;
}

$st = $conn->prepare("SELECT FirstName, LastName, ClockStatus FROM users WHERE ID = ? AND ArchivedAt IS NULL");
$st->bind_param("i", $id);
$st->execute();
$user = $st->get_result()->fetch_assoc();
$st->close();
if (!$user) {
    header("Location: manage_users.php");
    exit;
}

// Badge and PIN move aside so they can be given to someone else (and can't punch);
// the email code and any sign-in lockout are cleared. Nothing else is touched.
$st = $conn->prepare("
    UPDATE users
       SET ArchivedAt = NOW(),
           ArchivedBadgeID = NULLIF(BadgeID, ''), ArchivedPIN = NULLIF(PIN, ''),
           BadgeID = NULL, PIN = NULL,
           EmailOTPHash = NULL, EmailOTPExpires = NULL
     WHERE ID = ? AND ArchivedAt IS NULL");
$st->bind_param("i", $id);
$st->execute();
$st->close();

// An open punch stays as it is: it shows on their timesheet for an admin to finish.
$name = trim($user['FirstName'] . ' ' . $user['LastName']);
$msg  = "$name archived. Their punches and time off are kept.";
$open = $conn->prepare("SELECT COUNT(*) FROM timepunches WHERE EmployeeID = ? AND TimeIN IS NOT NULL AND TimeOut IS NULL");
$open->bind_param("i", $id);
$open->execute();
if ((int) $open->get_result()->fetch_row()[0] > 0) {
    $msg .= " They still have an open punch (no clock-out); finish it on their timesheet.";
}
$open->close();

header("Location: manage_users.php?msg=" . urlencode($msg));
exit;
