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
require_once __DIR__ . '/../functions/pay_rules.php'; // recomputeDayHours (shift rounding / auto-lunch)
requirePermission('approve_edits');

require_once __DIR__ . '/../functions/csrf.php';
require_csrf('edits_timesheet.php?error=csrf');

$admin = $_SESSION['admin'];

/**
 * Set LunchEnd on the open-lunch punch behind an incomplete_lunch review item,
 * recompute TotalHours, log it, and close the item. Returns an error message or null.
 */
function resolveIncompleteLunch(mysqli $conn, array $edit, string $lunchEnd, string $admin, string $now): ?string {
    if (isPayrollLocked($conn, $edit['Date'])) {
        return 'payroll for ' . date('m/d/Y', strtotime($edit['Date'])) . ' is closed. Reopen it on Settings › Pay Rules first.';
    }
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
    recomputeDayHours($conn, (int) $edit['EmployeeID'], $edit['Date']);

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
// The time fields an employee request can change: pending_edits' column names, which
// are also what edits_timesheet.php posts ('TimeOut', not 'TimeOUT': that spelling
// never matched, so approved clock-outs used to be dropped).
const REQUEST_FIELDS = ['TimeIN', 'LunchStart', 'LunchEnd', 'TimeOut'];

/**
 * The fields of a request that actually change the punch, compared at minute
 * precision the way edits_timesheet.php lists them.
 */
function requestedFieldChanges(mysqli $conn, array $edit): array {
    $stmt = $conn->prepare("SELECT TimeIN, LunchStart, LunchEnd, TimeOut FROM timepunches WHERE EmployeeID = ? AND Date = ? ORDER BY TimeIN LIMIT 1");
    $stmt->bind_param("is", $edit['EmployeeID'], $edit['Date']);
    $stmt->execute();
    $punch = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $fields = [];
    foreach (REQUEST_FIELDS as $field) {
        if ($edit[$field] === null || $edit[$field] === '') continue;
        $orig = !empty($punch[$field]) ? date('H:i', strtotime($punch[$field])) : '';
        if (date('H:i', strtotime($edit[$field])) !== $orig) {
            $fields[] = $field;
        }
    }
    return $fields;
}

/**
 * Write a request's approved fields to the employee's punch for that day, recompute
 * paid hours once, and log it like a direct edit on the punch editor
 * (save_punches.php): each field's old and new value, then the paid hours if they moved.
 */
function applyApprovedFields(mysqli $conn, array $edit, array $fields, string $admin): void {
    if (!$fields) return;
    $employeeID = (int) $edit['EmployeeID'];
    $date = $edit['Date'];
    $why = 'Approved employee request: ' . $edit['Reason'];
    $dayHours = function () use ($conn, $employeeID, $date) {
        $q = $conn->prepare("SELECT SUM(TotalHours) FROM timepunches WHERE EmployeeID = ? AND Date = ?");
        $q->bind_param("is", $employeeID, $date);
        $q->execute();
        $v = $q->get_result()->fetch_column();
        $q->close();
        return $v;
    };
    $log = $conn->prepare("INSERT INTO punch_changelog (EmployeeID, Date, ChangedBy, FieldChanged, OldValue, NewValue, Reason) VALUES (?, ?, ?, ?, ?, ?, ?)");

    $hoursBefore = $dayHours();
    foreach ($fields as $field) {
        $requested = $edit[$field];
        $before = $conn->prepare("SELECT `$field` FROM timepunches WHERE EmployeeID = ? AND Date = ? ORDER BY TimeIN LIMIT 1");
        $before->bind_param("is", $employeeID, $date);
        $before->execute();
        $old = $before->get_result()->fetch_column();
        $old = $old === false ? null : $old;
        $before->close();

        $update = $conn->prepare("UPDATE timepunches SET `$field` = ? WHERE EmployeeID = ? AND Date = ?");
        $update->bind_param("sis", $requested, $employeeID, $date);
        $update->execute();
        $update->close();

        $log->bind_param("issssss", $employeeID, $date, $admin, $field, $old, $requested, $why);
        $log->execute();
    }

    // Recompute stored hours from the (now updated) times so the
    // canonical column never goes stale after an approved time edit.
    if ($fields !== ['Note']) {
        recomputeDayHours($conn, $employeeID, $date);
        $hoursAfter = $dayHours();
        if ((string) $hoursBefore !== (string) $hoursAfter) {
            $hoursField = 'TotalHours';
            $recomputed = 'Recomputed after edit';
            $log->bind_param("issssss", $employeeID, $date, $admin, $hoursField, $hoursBefore, $hoursAfter, $recomputed);
            $log->execute();
        }
    }
    $log->close();
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

            // Closed payroll period: an approval would change hours already paid
            // (A note doesn't change hours, so it can still be approved.)
            if ($decision === 'Approved' && $field !== 'Note' && isPayrollLocked($conn, $edit['Date'])) {
                header('Location: edits_timesheet.php?error=locked&details=' . urlencode('Payroll for ' . date('m/d/Y', strtotime($edit['Date'])) . ' is closed. Reopen it on Settings › Pay Rules to approve this change, or reject it.'));
                exit;
            }

            // An employee request is decided once (a double-submit or stale page is a no-op)
            if ($edit['Source'] === 'employee' && $edit['Status'] !== 'Pending') continue;

            if ($decision === 'Approved') {
                // '__request' approves every time the request changes (the page shows one
                // Approve per request); a single field name still works for one field.
                if ($field === '__request') {
                    $fields = requestedFieldChanges($conn, $edit);
                } elseif (in_array($field, REQUEST_FIELDS, true) || $field === 'Note') {
                    $fields = [$field];
                } else {
                    $fields = [];
                }
                $conn->begin_transaction();
                applyApprovedFields($conn, $edit, $fields, $admin);
                $conn->commit();
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
