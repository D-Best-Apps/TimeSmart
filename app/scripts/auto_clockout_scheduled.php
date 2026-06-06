<?php
/**
 * Scheduled Auto Clock-Out Script for Gareth Pereira
 *
 * This script runs at 4:30PM on weekdays and automatically clocks out the owner.
 * The recorded time is randomized by up to 30 minutes (4:30 - 5:00 PM).
 *
 * Note: The midnight auto_clockout.php (safety net for ALL employees) still runs
 * separately to catch anyone else who forgets.
 *
 * Usage: php /path/to/auto_clockout_scheduled.php
 * Cron: 30 16 * * 1-5 (4:30PM Monday-Friday)
 */

require_once __DIR__ . '/../auth/db.php';
require_once __DIR__ . '/../functions/hours.php'; // canonical calculateTotalHours / reconcileClockStatus
date_default_timezone_set('America/Chicago');

// Configuration
define('AUTO_CLOCKOUT_BASE_TIME', '16:30:00');
define('AUTO_CLOCKOUT_VARY_MINUTES', 30);
define('AUTO_CLOCKOUT_NOTE', 'Auto clock-out (owner schedule)'); // changelog audit only; not shown on the punch
define('EMPLOYEE_FIRST_NAME', 'Gareth');
define('EMPLOYEE_LAST_NAME', 'Pereira');

/**
 * Add a random offset (0 to $maxMinutes) to a base time string
 */
function randomizeTime($baseTime, $maxMinutes) {
    $offset = random_int(0, $maxMinutes * 60);
    $timestamp = strtotime($baseTime) + $offset;
    return date('H:i:s', $timestamp);
}

/**
 * Log the auto clock-out action to punch_changelog
 */
function logAutoClockout($conn, $employeeID, $date, $clockOutTime) {
    $stmt = $conn->prepare("
        INSERT INTO punch_changelog
        (EmployeeID, Date, ChangedBy, FieldChanged, OldValue, NewValue, Reason)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $changedBy = 'SYSTEM';
    $field = 'TimeOut';
    $oldValue = 'NULL';
    $newValue = date('H:i:s', strtotime($clockOutTime));
    $reason = AUTO_CLOCKOUT_NOTE;

    $stmt->bind_param("issssss", $employeeID, $date, $changedBy, $field, $oldValue, $newValue, $reason);
    $result = $stmt->execute();
    $stmt->close();

    return $result;
}

/**
 * Main scheduled clock-out logic
 */
function scheduledClockOut($conn) {
    echo "[" . date('Y-m-d H:i:s') . "] Starting scheduled clock-out process...\n";

    // Find the employee by name
    $stmt = $conn->prepare("
        SELECT ID, FirstName, LastName, ClockStatus
        FROM users
        WHERE FirstName = ? AND LastName = ?
        LIMIT 1
    ");

    $firstName = EMPLOYEE_FIRST_NAME;
    $lastName = EMPLOYEE_LAST_NAME;
    $stmt->bind_param("ss", $firstName, $lastName);

    if (!$stmt->execute()) {
        echo "ERROR: Failed to query users table: " . $stmt->error . "\n";
        return false;
    }

    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user) {
        echo "ERROR: Employee not found: $firstName $lastName\n";
        return false;
    }

    $employeeID = $user['ID'];
    $employeeName = $user['FirstName'] . ' ' . $user['LastName'];
    $currentStatus = $user['ClockStatus'];

    echo "Found employee: $employeeName (ID: $employeeID)\n";
    echo "Current status: $currentStatus\n";

    if ($currentStatus !== 'In') {
        echo "Not currently clocked in. Nothing to do.\n";
        return true;
    }

    // Find their open punch record
    $today = date('Y-m-d');
    $punchStmt = $conn->prepare("
        SELECT ID, Date, TimeIN, LunchStart, LunchEnd
        FROM timepunches
        WHERE EmployeeID = ? AND TimeOUT IS NULL
        ORDER BY Date DESC, TimeIN DESC
        LIMIT 1
    ");

    $punchStmt->bind_param("i", $employeeID);

    if (!$punchStmt->execute()) {
        echo "ERROR: Failed to query timepunches: " . $punchStmt->error . "\n";
        return false;
    }

    $punchResult = $punchStmt->get_result();
    $punch = $punchResult->fetch_assoc();
    $punchStmt->close();

    if (!$punch) {
        echo "WARNING: No open punch record found. Reconciling ClockStatus.\n";
        reconcileClockStatus($conn, $employeeID);
        return true;
    }

    $punchID = $punch['ID'];
    $date = $punch['Date'];
    $clockIn = $punch['TimeIN'];
    $lunchOut = $punch['LunchStart'];
    $lunchIn = $punch['LunchEnd'];

    // Generate randomized clock-out time (TIME-only — stored directly in TimeOUT)
    $clockOutTime = randomizeTime(AUTO_CLOCKOUT_BASE_TIME, AUTO_CLOCKOUT_VARY_MINUTES);

    // Calculate total hours via the canonical shared function
    $totalHours = calculateTotalHours($clockIn, $lunchOut, $lunchIn, $clockOutTime);

    if ($totalHours === null) {
        echo "ERROR: Failed to calculate hours (invalid times)\n";
        return false;
    }

    try {
        $conn->begin_transaction();

        $updateStmt = $conn->prepare("
            UPDATE timepunches
            SET TimeOUT = ?, TotalHours = ?
            WHERE ID = ? AND EmployeeID = ?
        ");
        $updateStmt->bind_param("sdii", $clockOutTime, $totalHours, $punchID, $employeeID);
        $updateStmt->execute();
        $updateStmt->close();

        // Log to changelog (audit), then reconcile the denormalized status -> 'Out'
        logAutoClockout($conn, $employeeID, $date, $clockOutTime);
        reconcileClockStatus($conn, $employeeID);

        $conn->commit();
    } catch (Throwable $e) {
        @$conn->rollback();
        echo "ERROR: " . $e->getMessage() . "\n";
        return false;
    }

    echo "SUCCESS: Clocked out $employeeName at $clockOutTime with $totalHours hours\n";
    echo "\n[" . date('Y-m-d H:i:s') . "] Scheduled clock-out complete.\n";

    return true;
}

// --- MAIN EXECUTION ---
try {
    if (!$conn) {
        throw new Exception("Database connection failed");
    }

    scheduledClockOut($conn);

    $conn->close();
    exit(0);

} catch (Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
