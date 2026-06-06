<?php
/**
 * Auto Clock-Out Script
 *
 * Runs at midnight and clocks out any employees still clocked in, setting their
 * clock-out time to 5:00 PM. Each forced-out is recorded in pending_edits as a
 * review item (Source='auto_clockout'). Punches that cannot be computed (e.g.
 * stuck on lunch) are clocked out but left with TotalHours NULL and flagged as
 * "needs time entry" rather than guessing.
 *
 * Cron: 0 0 * * * php /var/www/html/scripts/auto_clockout.php >> /var/log/auto_clockout.log 2>&1
 */

require_once __DIR__ . '/../auth/db.php';
require_once __DIR__ . '/../functions/hours.php'; // canonical calculateTotalHours / reconcileClockStatus
date_default_timezone_set('America/Chicago');

// Configuration
define('AUTO_CLOCKOUT_TIME', '17:00:00'); // 5:00 PM (time-only — stored directly in TimeOUT)
define('AUTO_CLOCKOUT_NOTE', 'Auto-clocked out at 5:00 PM - forgot to clock out');
define('AUTO_CLOCKOUT_INCOMPLETE_NOTE', 'Incomplete punch (e.g. open lunch) — needs time entry');

/**
 * Log the auto clock-out action to punch_changelog (audit trail).
 */
function logAutoClockout($conn, $employeeID, $date, $clockOutTime, $reason) {
    $stmt = $conn->prepare("
        INSERT INTO punch_changelog
        (EmployeeID, Date, ChangedBy, FieldChanged, OldValue, NewValue, Reason)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $changedBy = 'SYSTEM';
    $field = 'TimeOut';
    $oldValue = 'NULL';
    $newValue = date('H:i:s', strtotime($clockOutTime));
    $stmt->bind_param("issssss", $employeeID, $date, $changedBy, $field, $oldValue, $newValue, $reason);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

/**
 * Create a pending_edits review item for a system forced-out / incomplete punch.
 * Surfaces in the admin approval queue via Source='auto_clockout'.
 */
function insertForcedOutReview($conn, $employeeID, $date, $clockOutTime, $reason) {
    $stmt = $conn->prepare("
        INSERT INTO pending_edits
        (EmployeeID, Date, TimeOut, Note, Reason, Source, Status, SubmittedAt)
        VALUES (?, ?, ?, ?, ?, 'auto_clockout', 'Pending', NOW())
    ");
    $note = $reason;
    $stmt->bind_param("issss", $employeeID, $date, $clockOutTime, $note, $reason);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

/**
 * Main auto clock-out logic
 */
function autoClockoutEmployees($conn) {
    $processedCount = 0;
    $incompleteCount = 0;
    $errorCount = 0;

    echo "[" . date('Y-m-d H:i:s') . "] Starting auto clock-out process...\n";

    $stmt = $conn->prepare("SELECT ID, FirstName, LastName FROM users WHERE ClockStatus = 'In'");
    if (!$stmt->execute()) {
        echo "ERROR: Failed to query users table: " . $stmt->error . "\n";
        return false;
    }
    $result = $stmt->get_result();
    $usersStillIn = [];
    while ($user = $result->fetch_assoc()) {
        $usersStillIn[] = $user;
    }
    $stmt->close();

    if (count($usersStillIn) === 0) {
        echo "No employees currently clocked in. Nothing to do.\n";
        return true;
    }

    echo "Found " . count($usersStillIn) . " employee(s) still clocked in:\n";

    foreach ($usersStillIn as $user) {
        $employeeID = $user['ID'];
        $employeeName = $user['FirstName'] . ' ' . $user['LastName'];
        echo "  Processing: $employeeName (ID: $employeeID)...\n";

        // Find their open punch record (TimeOut IS NULL)
        $punchStmt = $conn->prepare("
            SELECT ID, Date, TimeIN, LunchStart, LunchEnd
            FROM timepunches
            WHERE EmployeeID = ? AND TimeOUT IS NULL
            ORDER BY Date DESC, TimeIN DESC
            LIMIT 1
        ");
        $punchStmt->bind_param("i", $employeeID);
        if (!$punchStmt->execute()) {
            echo "    ERROR: Failed to query timepunches: " . $punchStmt->error . "\n";
            $errorCount++;
            continue;
        }
        $punch = $punchStmt->get_result()->fetch_assoc();
        $punchStmt->close();

        if (!$punch) {
            echo "    WARNING: No open punch record found. Reconciling ClockStatus.\n";
            reconcileClockStatus($conn, $employeeID); // self-heal the denormalized cache
            continue;
        }

        $punchID  = $punch['ID'];
        $date     = $punch['Date'];
        $clockIn  = $punch['TimeIN'];
        $lunchOut = $punch['LunchStart'];
        $lunchIn  = $punch['LunchEnd'];
        $clockOut = AUTO_CLOCKOUT_TIME; // TIME-only — no date component (fixes prior datetime bug)

        // An open lunch (LunchStart set, LunchEnd missing) cannot be computed -> flag, don't guess.
        $openLunch  = (!empty($lunchOut) && empty($lunchIn));
        $totalHours = $openLunch ? null : calculateTotalHours($clockIn, $lunchOut, $lunchIn, $clockOut);
        $incomplete = ($totalHours === null);

        try {
            $conn->begin_transaction();

            if ($incomplete) {
                // Clock them out for status correctness, but leave hours NULL for manual entry.
                $note = "\n" . AUTO_CLOCKOUT_INCOMPLETE_NOTE;
                $upd = $conn->prepare("
                    UPDATE timepunches
                    SET TimeOUT = ?, TotalHours = NULL, Note = CONCAT(COALESCE(Note, ''), ?)
                    WHERE ID = ? AND EmployeeID = ?
                ");
                $upd->bind_param("ssii", $clockOut, $note, $punchID, $employeeID);
                $upd->execute();
                $upd->close();
                insertForcedOutReview($conn, $employeeID, $date, $clockOut, AUTO_CLOCKOUT_INCOMPLETE_NOTE);
                logAutoClockout($conn, $employeeID, $date, $clockOut, AUTO_CLOCKOUT_INCOMPLETE_NOTE);
            } else {
                $note = "\n" . AUTO_CLOCKOUT_NOTE;
                $upd = $conn->prepare("
                    UPDATE timepunches
                    SET TimeOUT = ?, TotalHours = ?, Note = CONCAT(COALESCE(Note, ''), ?)
                    WHERE ID = ? AND EmployeeID = ?
                ");
                $upd->bind_param("sdsii", $clockOut, $totalHours, $note, $punchID, $employeeID);
                $upd->execute();
                $upd->close();
                insertForcedOutReview($conn, $employeeID, $date, $clockOut, AUTO_CLOCKOUT_NOTE);
                logAutoClockout($conn, $employeeID, $date, $clockOut, AUTO_CLOCKOUT_NOTE);
            }

            // Reconcile status from the (now closed) punch row -> 'Out'
            reconcileClockStatus($conn, $employeeID);

            $conn->commit();

            if ($incomplete) {
                echo "    FLAGGED: Clocked out at $clockOut, left for manual entry (incomplete punch)\n";
                $incompleteCount++;
            } else {
                echo "    SUCCESS: Clocked out at $clockOut with $totalHours hours\n";
                $processedCount++;
            }
        } catch (Throwable $e) {
            @$conn->rollback();
            echo "    ERROR: " . $e->getMessage() . "\n";
            $errorCount++;
            continue;
        }
    }

    echo "\n[" . date('Y-m-d H:i:s') . "] Auto clock-out complete.\n";
    echo "  Processed: $processedCount employee(s)\n";
    echo "  Flagged (needs entry): $incompleteCount\n";
    echo "  Errors: $errorCount\n";

    return true;
}

// --- MAIN EXECUTION ---
try {
    if (!$conn) {
        throw new Exception("Database connection failed");
    }
    autoClockoutEmployees($conn);
    $conn->close();
    exit(0);
} catch (Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
