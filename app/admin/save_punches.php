<?php
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

// Permission check
require_once __DIR__ . '/../functions/check_permission.php';
requirePermission('edit_timesheets');

// A logged-in admin's session must not be usable by a page they merely visited.
require_once __DIR__ . '/../functions/csrf.php';
require_csrf('view_punches.php?success=0&error=csrf');

date_default_timezone_set('America/Chicago');

// Canonical worked-hours / period / OT helpers (single source of truth).
require_once __DIR__ . '/../functions/hours.php';

// Validate input
if (!isset($_POST['employeeID'], $_POST['from'], $_POST['to'])) {
    header("Location: view_punches.php?success=0&error=missing_fields");
    exit;
}

$employeeID = intval($_POST['employeeID']);
$from = $_POST['from'];
$to = $_POST['to'];

$skipped = []; // rows blocked by validation errors
$flagged = []; // rows stored but flagged as anomalies for approval

/**
 * Human label for a punch row in the save notices. The admin never sees punch IDs
 * anywhere in the UI, so reporting one back ("4679: ...") tells them nothing —
 * name the row by the date and times they can actually see on screen.
 */
function describePunchRow(?string $date, ?string $clockIn, ?string $clockOut): string {
    $t = function (?string $v) { return $v ? date('g:i a', strtotime($v)) : '—'; };
    $d = $date ? date('m/d/Y', strtotime($date)) : 'new row';
    return $d . ' (' . $t($clockIn) . ' – ' . $t($clockOut) . ')';
}

try {
    $conn->begin_transaction();
    // Handle deletions first
    if (isset($_POST['delete']) && is_array($_POST['delete'])) {
        foreach ($_POST['delete'] as $punchId) {
            $punchId = intval($punchId);
            
            // Get punch info for logging before deletion
            $stmt = $conn->prepare("SELECT * FROM timepunches WHERE id = ? AND EmployeeID = ?");
            $stmt->bind_param("ii", $punchId, $employeeID);
            $stmt->execute();
            $result = $stmt->get_result();
            $punch = $result->fetch_assoc();
            
            if ($punch) {
                $date = $punch['Date'];
                
                // Log the deletion in changelog
                $logStmt = $conn->prepare("INSERT INTO punch_changelog (EmployeeID, Date, ChangedBy, FieldChanged, OldValue, NewValue, Reason) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $adminUser = $_SESSION['admin'];
                $field = "DELETED";
                $oldValue = json_encode($punch);
                $newValue = "NULL";
                $reason = $_POST['reason'][$punchId] ?? 'Deleted by admin';
                $logStmt->bind_param("issssss", $employeeID, $date, $adminUser, $field, $oldValue, $newValue, $reason);
                $logStmt->execute();
                
                // Delete the punch record
                $deleteStmt = $conn->prepare("DELETE FROM timepunches WHERE id = ? AND EmployeeID = ?");
                $deleteStmt->bind_param("ii", $punchId, $employeeID);
                $deleteStmt->execute();
            }
        }
    }
    
    // Handle updates and new punches
    // Get all punch IDs from clockin array keys
    if (isset($_POST['clockin']) && is_array($_POST['clockin'])) {
        foreach ($_POST['clockin'] as $punchId => $clockInValue) {
            $clockIn  = ($_POST['clockin'][$punchId]  ?? null) ?: null;
            $lunchOut = ($_POST['lunchout'][$punchId] ?? null) ?: null;
            $lunchIn  = ($_POST['lunchin'][$punchId]  ?? null) ?: null;
            $clockOut = ($_POST['clockout'][$punchId] ?? null) ?: null;
            // "Reason for Adjustment" is audit metadata: it belongs in punch_changelog,
            // never in timepunches.Note. Note is employee-facing (user/dashboard.php shows
            // it, user/timesheet.php lets them edit it) and carries system messages from
            // the auto-clockout scripts, so writing an admin reason there both leaked the
            // audit trail into the employee's view and erased those messages. This form
            // no longer touches Note at all.
            $reason   = trim($_POST['reason'][$punchId] ?? '') ?: null;

            $isNew    = strpos((string) $punchId, 'new-') === 0;
            $existing = null;

            if ($isNew) {
                // Date comes from the row's date input; skip incomplete scaffolding rows.
                $date = $_POST['date'][$punchId] ?? null;
                if (!$date) {
                    continue;
                }
                if (!$clockIn && !$clockOut) {
                    continue; // empty row
                }
            } else {
                $punchId = intval($punchId);

                // In edit mode, all punches are auto-confirmed
                // Otherwise, only update if explicitly in the confirm array
                $editMode = isset($_POST['mode']) && $_POST['mode'] === 'edit';
                $shouldUpdate = true;

                if (!$editMode) {
                    // Not in edit mode - require explicit confirmation
                    if (isset($_POST['confirm']) && is_array($_POST['confirm'])) {
                        $shouldUpdate = in_array($punchId, $_POST['confirm']);
                    } else {
                        $shouldUpdate = false; // No confirmation provided
                    }
                }

                if (!$shouldUpdate) {
                    error_log("Skipping punch $punchId - not confirmed (editMode: " . ($editMode ? 'true' : 'false') . ")");
                    continue;
                }

                // Check for existing entry (with EmployeeID validation for security)
                $checkStmt = $conn->prepare("SELECT * FROM timepunches WHERE id = ? AND EmployeeID = ?");
                $checkStmt->bind_param("ii", $punchId, $employeeID);
                $checkStmt->execute();
                $existing = $checkStmt->get_result()->fetch_assoc();

                if (!$existing) {
                    // Punch not found (e.g. deleted above) - log for debugging
                    error_log("Save failed: Punch ID $punchId not found for Employee $employeeID (or EmployeeID mismatch)");
                    continue;
                }

                $date = $existing['Date'];

                // <input type="time"> posts H:i, the column stores H:i:s. Without this every
                // save would rewrite 06:52:34 -> 06:52:00 on fields nobody touched.
                $clockIn  = preserveSeconds($existing['TimeIN'],     $clockIn);
                $lunchOut = preserveSeconds($existing['LunchStart'], $lunchOut);
                $lunchIn  = preserveSeconds($existing['LunchEnd'],   $lunchIn);
                $clockOut = preserveSeconds($existing['TimeOut'],    $clockOut);
            }

            $totalHours = calculateTotalHours($clockIn, $lunchOut, $lunchIn, $clockOut);

            // DECIMAL(x,2) reads back as '9.70' where the computed float is 9.7 — compare
            // numerically so formatting alone never counts as a change.
            $sameHours = fn($a, $b) => ($a === null || $a === '') && ($b === null || $b === '')
                ? true
                : (($a !== null && $a !== '' && $b !== null && $b !== '') && abs((float) $a - (float) $b) < 0.005);

            // Untouched rows are left exactly as they are: no changelog noise, and no
            // pre-existing bad row (a kiosk double-tap, say) blocking edits made elsewhere.
            if ($existing
                && $clockIn  === $existing['TimeIN']
                && $lunchOut === $existing['LunchStart']
                && $lunchIn  === $existing['LunchEnd']
                && $clockOut === $existing['TimeOut']
                && $sameHours($totalHours, $existing['TotalHours'])) {
                continue;
            }

            // Data-integrity validation: block contradictory rows, flag plausible anomalies.
            $issues = validatePunch($clockIn, $lunchOut, $lunchIn, $clockOut);
            $rowErrors = array_values(array_filter($issues, fn($i) => $i['severity'] === 'error'));
            if (!empty($rowErrors)) {
                $skipped[] = describePunchRow($date, $clockIn, $clockOut) . ': ' . implode('; ', array_column($rowErrors, 'message'));
                continue; // do not store contradictory data
            }
            $rowAnoms = array_values(array_filter($issues, fn($i) => $i['severity'] === 'anomaly'));

            $adminUser = $_SESSION['admin'];

            if ($isNew) {
                // Insert new punch record
                $insertStmt = $conn->prepare("
                    INSERT INTO timepunches (EmployeeID, Date, TimeIN, LunchStart, LunchEnd, TimeOut, TotalHours)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $insertStmt->bind_param("isssssd", $employeeID, $date, $clockIn, $lunchOut, $lunchIn, $clockOut, $totalHours);
                $insertStmt->execute();

                $newPunchId = $conn->insert_id;

                // Log the creation in changelog
                $logStmt = $conn->prepare("INSERT INTO punch_changelog (EmployeeID, Date, ChangedBy, FieldChanged, OldValue, NewValue, Reason) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $field = "CREATED";
                $oldValue = "NULL";
                $newValue = "Punch ID: " . $newPunchId;
                $logStmt->bind_param("issssss", $employeeID, $date, $adminUser, $field, $oldValue, $newValue, $reason);
                $logStmt->execute();
            } else {
                // Log only the fields that actually changed
                $fields = [
                    "TimeIN"     => $clockIn,
                    "LunchStart" => $lunchOut,
                    "LunchEnd"   => $lunchIn,
                    "TimeOut"    => $clockOut,
                    "TotalHours" => $totalHours
                ];

                foreach ($fields as $field => $newVal) {
                    $oldVal = $existing[$field] ?? null;
                    $changed = $field === 'TotalHours'
                        ? !$sameHours($newVal, $oldVal)
                        : (string) $newVal !== (string) $oldVal;
                    if ($changed) {
                        $logStmt = $conn->prepare("INSERT INTO punch_changelog (EmployeeID, Date, ChangedBy, FieldChanged, OldValue, NewValue, Reason) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $logStmt->bind_param("issssss", $employeeID, $date, $adminUser, $field, $oldVal, $newVal, $reason);
                        $logStmt->execute();
                    }
                }

                // Update (with EmployeeID validation for security)
                $updateStmt = $conn->prepare("
                    UPDATE timepunches
                    SET TimeIN = ?, LunchStart = ?, LunchEnd = ?, TimeOut = ?, TotalHours = ?
                    WHERE id = ? AND EmployeeID = ?
                ");
                $updateStmt->bind_param("ssssdii", $clockIn, $lunchOut, $lunchIn, $clockOut, $totalHours, $punchId, $employeeID);
                $updateStmt->execute();
            }

            if (!empty($rowAnoms)) {
                $msg = implode('; ', array_column($rowAnoms, 'message'));
                queuePunchReview($conn, $employeeID, $date, $clockOut, "Anomaly ({$date}): {$msg} — needs approval", 'anomaly');
                $flagged[] = describePunchRow($date, $clockIn, $clockOut) . ': ' . $msg;
            }
        }
    }

    $conn->commit();

    // Surface validation results to the admin on the next page.
    if (!empty($skipped) || !empty($flagged)) {
        $_SESSION['punch_save_notice'] = ['skipped' => $skipped, 'flagged' => $flagged];
    }

    // Success
    header("Location: view_punches.php?emp=" . urlencode($employeeID) . "&from=" . urlencode($from) . "&to=" . urlencode($to) . "&success=1&mode=edit");
    exit;

} catch (Exception $e) {
    // Log or debug as needed
    if ($conn instanceof mysqli) { @$conn->rollback(); }
    error_log("Error in save_punches.php: " . $e->getMessage());
    header("Location: view_punches.php?emp=" . urlencode($employeeID) . "&from=" . urlencode($from) . "&to=" . urlencode($to) . "&success=0&error=exception");
    exit;
}
