<?php
// admin/remove_office.php
// Removes an office. If employees are still assigned to it, they must be
// reassigned to another office (passed as ReassignTo) — users.Office is free
// text, so we move them before deleting to avoid orphaning anyone.
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

$id         = (int) ($_POST['ID'] ?? 0);
$reassignTo = trim($_POST['ReassignTo'] ?? '');
if ($id <= 0) {
    header("Location: manage_offices.php");
    exit;
}

// Resolve the office being removed
$stmt = $conn->prepare("SELECT OfficeName FROM Offices WHERE ID = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
if (!$row) {
    header("Location: manage_offices.php?error=" . urlencode('Office not found.'));
    exit;
}
$oldName = $row['OfficeName'];

// How many employees are assigned to it?
$cntStmt = $conn->prepare("SELECT COUNT(*) AS c FROM users WHERE Office = ?");
$cntStmt->bind_param("s", $oldName);
$cntStmt->execute();
$assigned = (int) $cntStmt->get_result()->fetch_assoc()['c'];

if ($assigned > 0) {
    // Need a valid destination office, different from the one being removed
    if ($reassignTo === '') {
        header("Location: manage_offices.php?error=" . urlencode('Choose an office to reassign employees to before removing.'));
        exit;
    }
    if ($reassignTo === $oldName) {
        header("Location: manage_offices.php?error=" . urlencode('Reassign target must be a different office.'));
        exit;
    }
    $valStmt = $conn->prepare("SELECT ID FROM Offices WHERE OfficeName = ?");
    $valStmt->bind_param("s", $reassignTo);
    $valStmt->execute();
    if (!$valStmt->get_result()->fetch_assoc()) {
        header("Location: manage_offices.php?error=" . urlencode('Reassign target office not found.'));
        exit;
    }

    $conn->begin_transaction();
    try {
        $mv = $conn->prepare("UPDATE users SET Office = ? WHERE Office = ?");
        $mv->bind_param("ss", $reassignTo, $oldName);
        $mv->execute();
        $moved = $mv->affected_rows;

        $del = $conn->prepare("DELETE FROM Offices WHERE ID = ?");
        $del->bind_param("i", $id);
        $del->execute();

        $conn->commit();
        header("Location: manage_offices.php?removed=" . urlencode($oldName)
             . "&reassigned=" . urlencode($reassignTo) . "&moved=" . (int) $moved);
        exit;
    } catch (\Throwable $e) {
        $conn->rollback();
        error_log("remove_office failed: " . $e->getMessage());
        header("Location: manage_offices.php?error=" . urlencode('Removal failed. Please try again.'));
        exit;
    }
}

// No employees assigned — safe to delete directly
$del = $conn->prepare("DELETE FROM Offices WHERE ID = ?");
$del->bind_param("i", $id);
$del->execute();
header("Location: manage_offices.php?removed=" . urlencode($oldName));
exit;
