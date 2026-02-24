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
date_default_timezone_set('America/Chicago');

// Configuration
define('AUTO_CLOCKOUT_BASE_TIME', '16:30:00');
define('AUTO_CLOCKOUT_VARY_MINUTES', 30);
define('AUTO_CLOCKOUT_NOTE', '*');
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
 * Calculate total hours between clock in and clock out, minus lunch time
 */
function calculateTotalHours($clockIn, $lunchOut, $lunchIn, $clockOut) {
    if (empty($clockIn) || empty($clockOut)) {
        return null;
    }

    $start = strtotime($clockIn);
    $end = strtotime($clockOut);

    if ($end <= $start) {
        return 0.0;
    }

    $totalSeconds = ($end - $start);

    if (!empty($lunchOut) && !empty($lunchIn)) {
        $lStart = strtotime($lunchOut);
        $lEnd = strtotime($lunchIn);
        if ($lEnd > $lStart) {
            $totalSeconds -= ($lEnd - $lStart);
        }
    }

    return round($totalSeconds / 3600, 2);
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
        echo "WARNING: No open punch record found. Updating ClockStatus only.\n";
        $updateStatusStmt = $conn->prepare("UPDATE users SET ClockStatus = 'Out' WHERE ID = ?");
        $updateStatusStmt->bind_param("i", $employeeID);
        $updateStatusStmt->execute();
        $updateStatusStmt->close();
        return true;
    }

    $punchID = $punch['ID'];
    $date = $punch['Date'];
    $clockIn = $punch['TimeIN'];
    $lunchOut = $punch['LunchStart'];
    $lunchIn = $punch['LunchEnd'];

    // Generate randomized clock-out time
    $clockOutTime = randomizeTime(AUTO_CLOCKOUT_BASE_TIME, AUTO_CLOCKOUT_VARY_MINUTES);
    $clockOutDateTime = $date . ' ' . $clockOutTime;

    // Calculate total hours
    $totalHours = calculateTotalHours($clockIn, $lunchOut, $lunchIn, $clockOutDateTime);

    if ($totalHours === null) {
        echo "ERROR: Failed to calculate hours (invalid times)\n";
        return false;
    }

    // Update the punch record
    $updateStmt = $conn->prepare("
        UPDATE timepunches
        SET TimeOUT = ?,
            TotalHours = ?
        WHERE ID = ? AND EmployeeID = ?
    ");

    $updateStmt->bind_param("sdii", $clockOutDateTime, $totalHours, $punchID, $employeeID);

    if (!$updateStmt->execute()) {
        echo "ERROR: Failed to update punch record: " . $updateStmt->error . "\n";
        $updateStmt->close();
        return false;
    }
    $updateStmt->close();

    // Update ClockStatus to 'Out'
    $statusStmt = $conn->prepare("UPDATE users SET ClockStatus = 'Out' WHERE ID = ?");
    $statusStmt->bind_param("i", $employeeID);

    if (!$statusStmt->execute()) {
        echo "WARNING: Failed to update ClockStatus: " . $statusStmt->error . "\n";
    }
    $statusStmt->close();

    // Log to changelog
    logAutoClockout($conn, $employeeID, $date, $clockOutDateTime);

    echo "SUCCESS: Clocked out $employeeName at $clockOutDateTime with $totalHours hours\n";
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
