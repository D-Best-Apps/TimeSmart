<?php
// admin/unarchive_user.php
// Brings an archived employee back: clears ArchivedAt and puts their badge ID and PIN
// back unless someone else has been given them since.
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
    header("Location: archived_users.php");
    exit;
}
require_csrf('archived_users.php?msg=' . urlencode('The page had been open too long. Nothing was changed; please try again.'));

$id = (int) ($_POST['ID'] ?? 0);
$st = $conn->prepare("SELECT FirstName, LastName, ArchivedBadgeID, ArchivedPIN FROM users WHERE ID = ? AND ArchivedAt IS NOT NULL");
$st->bind_param("i", $id);
$st->execute();
$user = $st->get_result()->fetch_assoc();
$st->close();
if (!$user) {
    header("Location: archived_users.php");
    exit;
}

// A badge ID or PIN reused while they were archived stays with its new owner.
// Badge IDs and PINs share one namespace at the kiosk, so check both columns.
$taken = function (?string $value) use ($conn, $id): bool {
    if ($value === null || $value === '') {
        return false;
    }
    $chk = $conn->prepare("SELECT 1 FROM users WHERE (BadgeID = ? OR PIN = ?) AND ID <> ? LIMIT 1");
    $chk->bind_param("ssi", $value, $value, $id);
    $chk->execute();
    $hit = (bool) $chk->get_result()->fetch_row();
    $chk->close();
    return $hit;
};
$badge = $user['ArchivedBadgeID'];
$pin   = $user['ArchivedPIN'];
$notes = [];
if ($taken($badge)) {
    $notes[] = 'badge ID';
    $badge = null;
}
if ($taken($pin)) {
    $notes[] = 'PIN';
    $pin = null;
}

$st = $conn->prepare("
    UPDATE users
       SET ArchivedAt = NULL, BadgeID = ?, PIN = ?, ArchivedBadgeID = NULL, ArchivedPIN = NULL
     WHERE ID = ? AND ArchivedAt IS NOT NULL");
$st->bind_param("ssi", $badge, $pin, $id);
$st->execute();
$st->close();

$msg = trim($user['FirstName'] . ' ' . $user['LastName']) . ' is active again.';
if ($notes) {
    $msg .= ' Their ' . implode(' and ', $notes) . (count($notes) > 1 ? ' were' : ' was')
          . ' given to someone else while they were archived; set a new one on Edit.';
}
header("Location: manage_users.php?msg=" . urlencode($msg));
exit;
