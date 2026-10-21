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

require_once __DIR__ . '/../functions/csrf.php';
require_csrf('edits_timesheet.php?error=csrf');

$admin = $_SESSION['admin'];

/**
 * Set LunchEnd on the open-lunch punch behind an incomplete_lunch review item,
 * recompute TotalHours, log it, and close the item. Returns an error message or null.
 */
function resolveIncompleteLunch(mysqli $conn, array $edit, string $lunchEnd, string $admin, string $now): ?string {
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $lunchEnd)) {
        return 'enter the time the employee came back from lunch.';
    }
    $lunchEnd .= ':00';

    $stmt = $conn->prepare("SELECT id, TimeIN, LunchStart, TimeOut FROM timepunches
                             WHERE EmployeeID = ? AND Date = ? AND LunchStart IS NOT NULL AND LunchEnd IS NULL
                             ORDER BY LunchStart LIMIT 1");
    $stmt->bind_param("is", $edit['EmployeeID'], $edit['Date']);
    $stmt->execute();
    $punch = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$punch) {
        return 'that punch no longer has an unfinished lunch — reload the page.';
    }

    $leS = hms_to_seconds($lunchEnd);
    if ($leS <= hms_to_seconds($punch['LunchStart'])) {
        return 'lunch end must be after lunch start (' . date('g:i a', strtotime($punch['LunchStart'])) . ').';
    }
    if ($punch['TimeOut'] !== null && $leS > hms_to_seconds($punch['TimeOut'])) {
        return 'lunch end must be before clock-out (' . date('g:i a', strtotime($punch['TimeOut'])) . ').';
    }

    $total = calculateTotalHours($punch['TimeIN'], $punch['LunchStart'], $lunchEnd, $punch['TimeOut']);

    $conn->begin_transaction();
    $u = $conn->prepare("UPDATE timepunches SET LunchEnd = ?, TotalHours = ? WHERE id = ?");
    $u->bind_param("sdi", $lunchEnd, $total, $punch['id']);
    $u->execute();
    $u->close();

    $log = $conn->prepare("INSERT INTO punch_changelog (EmployeeID, Date, ChangedBy, FieldChanged, OldValue, NewValue, Reason) VALUES (?, ?, ?, 'LunchEnd', NULL, ?, 'Unfinished lunch resolved from review queue')");
    $log->bind_param("isss", $edit['EmployeeID'], $edit['Date'], $admin, $lunchEnd);
    $log->execute();
    $log->close();

    $s = $conn->prepare("UPDATE pending_edits SET Status = 'Approved', LunchEnd = ?, ReviewedAt = ?, ReviewedBy = ? WHERE ID = ?");
    $s->bind_param("sssi", $lunchEnd, $now, $admin, $edit['ID']);
    $s->execute();
    $s->close();
    $conn->commit();
    return null;
}
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

            // Unfinished lunch: the only resolution is entering when the employee came
            // back. Approve/Reject alone would close the item and leave the day at 0 hours.
            if ($edit['Source'] === 'incomplete_lunch') {
                if ($field !== '__lunchend' || $edit['Status'] !== 'Pending') continue;
                $err = resolveIncompleteLunch($conn, $edit, trim($_POST['lunch_end'][$editID] ?? ''), $admin, $now);
                if ($err !== null) {
                    header('Location: edits_timesheet.php?error=lunchend&details=' . urlencode($err));
                    exit;
                }
                continue;
            }

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
