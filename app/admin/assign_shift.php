<?php
// admin/assign_shift.php — bulk "assign shift" from the Users page.
// The new shift applies to punches from now on; past days keep their paid hours
// until an admin runs Shifts > Recalculate.
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/../functions/check_permission.php';
requirePermission('manage_users');

require_once __DIR__ . '/../functions/csrf.php';
require_csrf('manage_users.php');

$return  = (string) ($_POST['return'] ?? '');
$back    = 'manage_users.php' . ($return !== '' ? '?shift=' . urlencode($return) . '&' : '?');
$userIDs = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['UserIDs'] ?? [])))));
$shiftID = (int) ($_POST['ShiftID'] ?? 0);

if ($shiftID > 0) {
    $chk = $conn->prepare("SELECT ID FROM shifts WHERE ID = ? AND Archived = 0");
    $chk->bind_param("i", $shiftID);
    $chk->execute();
    $chk->store_result();
    if ($chk->num_rows === 0) {
        header('Location: ' . $back . 'assigned=0');
        exit;
    }
    $chk->close();
} else {
    $shiftID = null;
}

$updated = 0;
if ($userIDs) {
    $stmt = $conn->prepare("UPDATE users SET ShiftID = ? WHERE ID = ?");
    foreach ($userIDs as $uid) {
        $stmt->bind_param("ii", $shiftID, $uid);
        $stmt->execute();
        $updated += $stmt->affected_rows > 0 ? 1 : 0;
    }
    $stmt->close();
}

header('Location: ' . $back . 'assigned=' . $updated);
exit;
