<?php
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;

// Permission check
require_once __DIR__ . '/../functions/check_permission.php';
requirePermission('edit_timesheets');
}

date_default_timezone_set('America/Chicago');

// Helper to calculate total hours
function calculateTotalHours($clockIn, $lunchOut, $lunchIn, $clockOut) {
    if (!$clockIn || !$clockOut) return null;

    $start = strtotime($clockIn);
    $end = strtotime($clockOut);
    if ($end <= $start) return null;

    $total = ($end - $start) / 3600;

    if ($lunchOut && $lunchIn) {
        $lStart = strtotime($lunchOut);
        $lEnd = strtotime($lunchIn);
        if ($lEnd > $lStart) {
            $total -= ($lEnd - $lStart) / 3600;
        }
    }

    return round($total, 2);
}

// Validate input
if (!isset($_POST['employeeID'], $_POST['from'], $_POST['to'])) {
    header("Location: view_punches.php?success=0&error=missing_fields");
    exit;
}

$employeeID = intval($_POST['employeeID']);
$from = $_POST['from'];
$to = $_POST['to'];

try {
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
            $clockIn = $_POST['clockin'][$punchId] ?? null;
            $lunchOut = $_POST['lunchout'][$punchId] ?? null;
            $lunchIn = $_POST['lunchin'][$punchId] ?? null;
            $clockOut = $_POST['clockout'][$punchId] ?? null;
            $reason = trim($_POST['reason'][$punchId] ?? '');
            
            $clockIn = $clockIn ?: null;
            $lunchOut = $lunchOut ?: null;
            $lunchIn = $lunchIn ?: null;
            $clockOut = $clockOut ?: null;
            $reason = $reason ?: null;
            $totalHours = calculateTotalHours($clockIn, $lunchOut, $lunchIn, $clockOut);
            
            // Check if this is a new punch (ID starts with "new-")
            if (strpos($punchId, 'new-') === 0) {
                // This is a new punch - INSERT
                
                // Get date from the date input field
                $date = $_POST['date'][$punchId] ?? null;
                if (!$date) {
                    continue; // Skip if no date provided
                }
                
                // Validate that we have at least clock in or clock out
                if (!$clockIn && !$clockOut) {
                    continue; // Skip empty rows
                }
                
                // Insert new punch record
                $insertStmt = $conn->prepare("
                    INSERT INTO timepunches (EmployeeID, Date, TimeIN, LunchStart, LunchEnd, TimeOut, Note, TotalHours)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $insertStmt->bind_param("issssssd", $employeeID, $date, $clockIn, $lunchOut, $lunchIn, $clockOut, $reason, $totalHours);
                $insertStmt->execute();
                
                $newPunchId = $conn->insert_id;
                
                // Log the creation in changelog
                $logStmt = $conn->prepare("INSERT INTO punch_changelog (EmployeeID, Date, ChangedBy, FieldChanged, OldValue, NewValue, Reason) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $adminUser = $_SESSION['admin'];
                $field = "CREATED";
                $oldValue = "NULL";
                $newValue = "Punch ID: " . $newPunchId;
                $logStmt->bind_param("issssss", $employeeID, $date, $adminUser, $field, $oldValue, $newValue, $reason);
                $logStmt->execute();
                
            } else {
                // This is an existing punch - UPDATE
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
                $result = $checkStmt->get_result();
                $existing = $result->fetch_assoc();

                if ($existing) {
                    $date = $existing['Date'];
                    // Log changes
                    $fields = [
                        "TimeIN" => $clockIn,
                        "LunchStart" => $lunchOut,
                        "LunchEnd" => $lunchIn,
                        "TimeOut" => $clockOut,
                        "Note" => $reason,
                        "TotalHours" => $totalHours
                    ];

                    foreach ($fields as $field => $newVal) {
                        $oldVal = $existing[$field] ?? null;
                        if ($newVal != $oldVal) {
                            $logStmt = $conn->prepare("INSERT INTO punch_changelog (EmployeeID, Date, ChangedBy, FieldChanged, OldValue, NewValue, Reason) VALUES (?, ?, ?, ?, ?, ?, ?)");
                            $adminUser = $_SESSION['admin'];
                            $logStmt->bind_param("issssss", $employeeID, $date, $adminUser, $field, $oldVal, $newVal, $reason);
                            $logStmt->execute();
                        }
                    }

                    // Update (with EmployeeID validation for security)
                    $updateStmt = $conn->prepare("
                        UPDATE timepunches
                        SET TimeIN = ?, LunchStart = ?, LunchEnd = ?, TimeOut = ?, Note = ?, TotalHours = ?
                        WHERE id = ? AND EmployeeID = ?
                    ");
                    $updateStmt->bind_param("sssssdii", $clockIn, $lunchOut, $lunchIn, $clockOut, $reason, $totalHours, $punchId, $employeeID);
                    $updateStmt->execute();
                } else {
                    // Punch not found - log for debugging
                    error_log("Save failed: Punch ID $punchId not found for Employee $employeeID (or EmployeeID mismatch)");
                }
            }
        }
    }

    // Success
    header("Location: view_punches.php?emp=" . urlencode($employeeID) . "&from=" . urlencode($from) . "&to=" . urlencode($to) . "&success=1&mode=edit");
    exit;

} catch (Exception $e) {
    // Log or debug as needed
    error_log("Error in save_punches.php: " . $e->getMessage());
    header("Location: view_punches.php?emp=" . urlencode($employeeID) . "&from=" . urlencode($from) . "&to=" . urlencode($to) . "&success=0&error=exception");
    exit;
}
