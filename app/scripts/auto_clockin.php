<?php
/**
 * Auto Clock-In Script for Gareth Pereira
 *
 * This script runs at 8AM on weekdays and automatically clocks in the owner
 * if they're not already clocked in.
 *
 * Usage: php /path/to/auto_clockin.php
 * Cron: 0 8 * * 1-5 (8AM Monday-Friday)
 */

require_once __DIR__ . '/../auth/db.php';
date_default_timezone_set('America/Chicago');

// Configuration
define('AUTO_CLOCKIN_TIME', '08:00:00');
define('AUTO_CLOCKIN_NOTE', 'Auto-clocked in at 8:00 AM');
define('EMPLOYEE_FIRST_NAME', 'Gareth');
define('EMPLOYEE_LAST_NAME', 'Pereira');

/**
 * Log the auto clock-in action to punch_changelog
 */
function logAutoClockIn($conn, $employeeID, $date, $clockInTime) {
    $stmt = $conn->prepare("
        INSERT INTO punch_changelog
        (EmployeeID, Date, ChangedBy, FieldChanged, OldValue, NewValue, Reason)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $changedBy = 'SYSTEM';
    $field = 'TimeIN';
    $oldValue = 'NULL';
    $newValue = date('H:i:s', strtotime($clockInTime));
    $reason = AUTO_CLOCKIN_NOTE;

    $stmt->bind_param("issssss", $employeeID, $date, $changedBy, $field, $oldValue, $newValue, $reason);
    $result = $stmt->execute();
    $stmt->close();

    return $result;
}

/**
 * Main auto clock-in logic
 */
function autoClockInEmployee($conn) {
    echo "[" . date('Y-m-d H:i:s') . "] Starting auto clock-in process...\n";

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

    // Check if already clocked in
    if ($currentStatus === 'In') {
        echo "Already clocked in. Nothing to do.\n";
        return true;
    }

    // Check if already has a punch record for today
    $today = date('Y-m-d');
    $checkStmt = $conn->prepare("
        SELECT ID FROM timepunches
        WHERE EmployeeID = ? AND Date = ?
        LIMIT 1
    ");
    $checkStmt->bind_param("is", $employeeID, $today);
    $checkStmt->execute();
    $existingPunch = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if ($existingPunch) {
        echo "Already has a punch record for today (ID: {$existingPunch['ID']}). Skipping.\n";
        return true;
    }

    // Create the clock-in datetime
    $clockInDateTime = $today . ' ' . AUTO_CLOCKIN_TIME;

    // Insert new punch record
    $insertStmt = $conn->prepare("
        INSERT INTO timepunches (EmployeeID, Date, TimeIN, Note)
        VALUES (?, ?, ?, ?)
    ");

    $note = AUTO_CLOCKIN_NOTE;
    $insertStmt->bind_param("isss", $employeeID, $today, $clockInDateTime, $note);

    if (!$insertStmt->execute()) {
        echo "ERROR: Failed to create punch record: " . $insertStmt->error . "\n";
        $insertStmt->close();
        return false;
    }
    $insertStmt->close();

    // Update ClockStatus to 'In'
    $statusStmt = $conn->prepare("UPDATE users SET ClockStatus = 'In' WHERE ID = ?");
    $statusStmt->bind_param("i", $employeeID);

    if (!$statusStmt->execute()) {
        echo "WARNING: Failed to update ClockStatus: " . $statusStmt->error . "\n";
    }
    $statusStmt->close();

    // Log to changelog for audit trail
    logAutoClockIn($conn, $employeeID, $today, $clockInDateTime);

    echo "SUCCESS: Clocked in $employeeName at $clockInDateTime\n";
    echo "\n[" . date('Y-m-d H:i:s') . "] Auto clock-in complete.\n";

    return true;
}

// --- MAIN EXECUTION ---
try {
    if (!$conn) {
        throw new Exception("Database connection failed");
    }

    autoClockInEmployee($conn);

    $conn->close();
    exit(0);

} catch (Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
