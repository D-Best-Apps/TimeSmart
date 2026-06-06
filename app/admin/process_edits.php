<?php
session_start();
require '../auth/db.php';

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

// Permission check
require_once __DIR__ . '/../functions/check_permission.php';
require_once __DIR__ . '/../functions/hours.php'; // canonical calculateTotalHours
requirePermission('approve_edits');

$admin = $_SESSION['admin'];
$now = date('Y-m-d H:i:s');
$canViewPrivate = canViewPrivateNotes($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    foreach ($_POST['action'] as $editID => $fieldActions) {
        foreach ($fieldActions as $field => $action) {
            $editID = intval($editID);
            $decision = ($action === 'approve') ? 'Approved' : 'Rejected';

            // Get edit info
            $stmt = $conn->prepare("SELECT * FROM pending_edits WHERE ID = ?");
            $stmt->bind_param("i", $editID);
            $stmt->execute();
            $edit = $stmt->get_result()->fetch_assoc();

            if (!$edit) continue;

            if ($decision === 'Approved') {
                // Update timepunches table
                $employeeID = $edit['EmployeeID'];
                $date = $edit['Date'];

                // Sanitize column name
                $allowedFields = ['TimeIN', 'LunchStart', 'LunchEnd', 'TimeOUT', 'Note'];
                if (in_array($field, $allowedFields)) {
                    $requested = $edit[$field];
                    $sql = "UPDATE timepunches SET `$field` = ? WHERE EmployeeID = ? AND Date = ?";
                    $update = $conn->prepare($sql);
                    $update->bind_param("sis", $requested, $employeeID, $date);
                    $update->execute();

                    // Recompute stored TotalHours from the (now updated) times so the
                    // canonical column never goes stale after an approved time edit.
                    if ($field !== 'Note') {
                        $rs = $conn->prepare("SELECT id, TimeIN, LunchStart, LunchEnd, TimeOut FROM timepunches WHERE EmployeeID = ? AND Date = ?");
                        $rs->bind_param("is", $employeeID, $date);
                        $rs->execute();
                        $punchRows = $rs->get_result()->fetch_all(MYSQLI_ASSOC);
                        $rs->close();
                        foreach ($punchRows as $pr) {
                            $th = calculateTotalHours($pr['TimeIN'], $pr['LunchStart'], $pr['LunchEnd'], $pr['TimeOut']);
                            $u = $conn->prepare("UPDATE timepunches SET TotalHours = ? WHERE id = ?");
                            $u->bind_param("di", $th, $pr['id']);
                            $u->execute();
                            $u->close();
                        }
                    }
                }
            }

            // Update pending_edits status. This marks the entire request as processed.
            // Only admins who can view private notes may set AdminPrivateNote; others
            // leave the column untouched.
            if ($canViewPrivate) {
                $priv = trim($_POST['private_note'][$editID] ?? '');
                $privVal = $priv !== '' ? $priv : null;
                $updateStatus = $conn->prepare("UPDATE pending_edits SET Status = ?, ReviewedAt = ?, ReviewedBy = ?, AdminPrivateNote = ? WHERE ID = ?");
                $updateStatus->bind_param("ssssi", $decision, $now, $admin, $privVal, $editID);
            } else {
                $updateStatus = $conn->prepare("UPDATE pending_edits SET Status = ?, ReviewedAt = ?, ReviewedBy = ? WHERE ID = ?");
                $updateStatus->bind_param("sssi", $decision, $now, $admin, $editID);
            }
            $updateStatus->execute();
        }
    }

    header("Location: edits_timesheet.php");
    exit;
} else {
    echo "Invalid access.";
}
