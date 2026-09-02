<?php
/**
 * Auto Clock-Out Script
 *
 * Closes every punch left open on a PAST day, setting the clock-out time to 5:00 PM.
 * Each forced-out is recorded in pending_edits as a review item (Source='auto_clockout').
 * Punches that cannot be computed (e.g. stuck on lunch) are clocked out but left with
 * TotalHours NULL and flagged as "needs time entry" rather than guessing.
 *
 * Only punches from the last AUTO_CLOCKOUT_MAX_AGE_DAYS days are closed; older orphans
 * are printed for a human, since inventing hours in an already-paid period is worse than
 * leaving the row open.
 *
 * It selects on `timepunches.Date < CURDATE()` rather than `users.ClockStatus = 'In'`:
 *   - today's in-progress punches are never touched, so the run time doesn't matter
 *     and a missed run is simply picked up by the next one (this host powers off
 *     overnight, which silently killed the old midnight schedule for months);
 *   - someone stranded mid-lunch is ClockStatus='Lunch', not 'In', and used to be
 *     skipped entirely — driving off the punch row catches them;
 *   - a stale ClockStatus can no longer hide an open punch.
 *
 * Cron: 15 5 * * * php /var/www/html/scripts/auto_clockout.php >> /var/log/auto_clockout.log 2>&1
 */

require_once __DIR__ . '/../functions/cli_guard.php'; // CLI only — never over HTTP
require_once __DIR__ . '/../auth/db.php';
require_once __DIR__ . '/../functions/hours.php'; // canonical calculateTotalHours / reconcileClockStatus
date_default_timezone_set('America/Chicago');

// Configuration
define('AUTO_CLOCKOUT_TIME', '17:00:00'); // 5:00 PM (time-only — stored directly in TimeOUT)
define('AUTO_CLOCKOUT_NOTE', 'Auto-clocked out at 5:00 PM - forgot to clock out');
define('AUTO_CLOCKOUT_INCOMPLETE_NOTE', 'Incomplete punch (e.g. open lunch) — needs time entry');
// Never invent hours in a pay period that has almost certainly been paid out. Anything
// older than this is reported for a human to settle instead of being closed silently.
define('AUTO_CLOCKOUT_MAX_AGE_DAYS', 14);

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

    // Every punch still open on a past day. Today's are deliberately excluded so this
    // is safe to run at any hour and self-heals a backlog of missed runs.
    $sql = "
        SELECT tp.ID, tp.EmployeeID, tp.Date, tp.TimeIN, tp.LunchStart, tp.LunchEnd,
               u.FirstName, u.LastName
        FROM timepunches tp
        JOIN users u ON u.ID = tp.EmployeeID
        WHERE tp.TimeOUT IS NULL
          AND tp.TimeIN IS NOT NULL
          AND tp.Date < CURDATE()
        ORDER BY tp.Date, tp.EmployeeID
    ";
    $result = $conn->query($sql);
    if (!$result) {
        echo "ERROR: Failed to query open punches: " . $conn->error . "\n";
        return false;
    }

    $openPunches = [];
    $tooOld = [];
    $cutoff = (new DateTime('today'))->modify('-' . AUTO_CLOCKOUT_MAX_AGE_DAYS . ' days')->format('Y-m-d');
    while ($row = $result->fetch_assoc()) {
        if ($row['Date'] < $cutoff) {
            $tooOld[] = $row;
        } else {
            $openPunches[] = $row;
        }
    }
    $result->free();

    if (!empty($tooOld)) {
        echo "Open punches older than " . AUTO_CLOCKOUT_MAX_AGE_DAYS . " days — NOT auto-closed, fix these by hand:\n";
        foreach ($tooOld as $row) {
            echo "  {$row['Date']}  {$row['FirstName']} {$row['LastName']} (ID: {$row['EmployeeID']}, punch {$row['ID']}) in at {$row['TimeIN']}\n";
        }
    }

    if (count($openPunches) === 0) {
        echo "No open punches to close from previous days.\n";
        // Still reconcile anyone the cache thinks is clocked in with no open punch.
        reconcileStrandedStatuses($conn);
        return true;
    }

    echo "Found " . count($openPunches) . " open punch(es) from previous days:\n";

    $touchedEmployees = [];

    foreach ($openPunches as $punch) {
        $punchID      = $punch['ID'];
        $employeeID   = $punch['EmployeeID'];
        $employeeName = $punch['FirstName'] . ' ' . $punch['LastName'];
        $date         = $punch['Date'];
        $clockIn      = $punch['TimeIN'];
        $lunchOut     = $punch['LunchStart'];
        $lunchIn      = $punch['LunchEnd'];
        $clockOut     = AUTO_CLOCKOUT_TIME; // TIME-only — no date component

        echo "  Processing: $employeeName (ID: $employeeID) on $date...\n";

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

            $conn->commit();
            $touchedEmployees[$employeeID] = true;

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

    // Reconcile once per employee, after all of their rows are closed.
    foreach (array_keys($touchedEmployees) as $employeeID) {
        reconcileClockStatus($conn, $employeeID);
    }
    reconcileStrandedStatuses($conn);

    echo "\n[" . date('Y-m-d H:i:s') . "] Auto clock-out complete.\n";
    echo "  Processed: $processedCount punch(es)\n";
    echo "  Flagged (needs entry): $incompleteCount\n";
    echo "  Errors: $errorCount\n";

    return true;
}

/**
 * Self-heal users whose cached ClockStatus says In/Lunch but who have no open punch
 * at all — otherwise they stay "clocked in" on the dashboard forever.
 */
function reconcileStrandedStatuses($conn) {
    $sql = "
        SELECT u.ID
        FROM users u
        WHERE u.ClockStatus IN ('In', 'Lunch')
          AND NOT EXISTS (
              SELECT 1 FROM timepunches tp
              WHERE tp.EmployeeID = u.ID AND tp.TimeOUT IS NULL
          )
    ";
    $result = $conn->query($sql);
    if (!$result) {
        return;
    }
    while ($row = $result->fetch_assoc()) {
        echo "  Reconciling stale ClockStatus for employee ID {$row['ID']}\n";
        reconcileClockStatus($conn, (int) $row['ID']);
    }
    $result->free();
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
