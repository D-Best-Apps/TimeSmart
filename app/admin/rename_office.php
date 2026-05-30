<?php
// admin/rename_office.php
// Renames an office and cascades the new name to every user assigned to the old
// name (users.Office stores the office name as text, so a rename must propagate).
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

// Permission check
require_once __DIR__ . '/../functions/check_permission.php';
requirePermission('manage_offices');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: manage_offices.php");
    exit;
}

$id      = (int) ($_POST['ID'] ?? 0);
$newName = trim($_POST['OfficeName'] ?? '');

if ($id <= 0 || $newName === '') {
    header("Location: manage_offices.php?error=" . urlencode('Office name is required.'));
    exit;
}

// Current name
$stmt = $conn->prepare("SELECT OfficeName FROM Offices WHERE ID = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
if (!$row) {
    header("Location: manage_offices.php?error=" . urlencode('Office not found.'));
    exit;
}
$oldName = $row['OfficeName'];

// No-op rename
if ($oldName === $newName) {
    header("Location: manage_offices.php");
    exit;
}

// Reject a name that already belongs to another office
$dup = $conn->prepare("SELECT ID FROM Offices WHERE OfficeName = ? AND ID <> ?");
$dup->bind_param("si", $newName, $id);
$dup->execute();
if ($dup->get_result()->fetch_assoc()) {
    header("Location: manage_offices.php?error=" . urlencode('Another office already uses that name.'));
    exit;
}

// Rename the office and cascade to all users on the old name, atomically.
$conn->begin_transaction();
try {
    $u1 = $conn->prepare("UPDATE Offices SET OfficeName = ? WHERE ID = ?");
    $u1->bind_param("si", $newName, $id);
    $u1->execute();

    $u2 = $conn->prepare("UPDATE users SET Office = ? WHERE Office = ?");
    $u2->bind_param("ss", $newName, $oldName);
    $u2->execute();
    $moved = $u2->affected_rows;

    $conn->commit();
    header("Location: manage_offices.php?renamed=" . urlencode($newName) . "&moved=" . (int) $moved);
    exit;
} catch (\Throwable $e) {
    $conn->rollback();
    error_log("rename_office failed: " . $e->getMessage());
    header("Location: manage_offices.php?error=" . urlencode('Rename failed. Please try again.'));
    exit;
}
