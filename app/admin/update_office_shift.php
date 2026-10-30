<?php
// admin/update_office_shift.php
// Set an office's default shift: pre-selected when an employee is added to that office.
// Existing employees are not changed (reassign them from the Users page).
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/../functions/check_permission.php';
requirePermission('manage_offices');

require_once __DIR__ . '/../functions/csrf.php';
require_csrf('manage_offices.php');

$id      = (int) ($_POST['ID'] ?? 0);
$shiftID = (int) ($_POST['DefaultShiftID'] ?? 0);
if ($id <= 0) {
    header("Location: manage_offices.php");
    exit;
}

if ($shiftID > 0) {
    $chk = $conn->prepare("SELECT ID FROM shifts WHERE ID = ? AND Archived = 0");
    $chk->bind_param("i", $shiftID);
    $chk->execute();
    $chk->store_result();
    $shiftID = $chk->num_rows > 0 ? $shiftID : null;
    $chk->close();
} else {
    $shiftID = null;
}

$stmt = $conn->prepare("UPDATE Offices SET DefaultShiftID = ? WHERE ID = ?");
$stmt->bind_param("ii", $shiftID, $id);
$stmt->execute();

header("Location: manage_offices.php?shift_saved=1");
exit;
