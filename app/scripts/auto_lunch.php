<?php
/**
 * Auto Lunch Script for Gareth Pereira
 *
 * Handles automatic lunch clock-out and clock-in.
 * Called with an action argument: "out" or "in"
 *
 * Lunch Out: Records LunchStart at 11:00 AM + random 0-10 minutes
 * Lunch In:  Records LunchEnd at 11:45 AM + random 0-10 minutes
 *
 * Usage:
 *   php auto_lunch.php out   (cron at 0 11 * * 1-5)
 *   php auto_lunch.php in    (cron at 45 11 * * 1-5)
 */

require_once __DIR__ . '/../auth/db.php';
date_default_timezone_set('America/Chicago');

// Configuration
define('LUNCH_OUT_BASE_TIME', '11:00:00');
define('LUNCH_IN_BASE_TIME', '11:45:00');
define('LUNCH_VARY_MINUTES', 10);
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
 * Log a lunch action to punch_changelog
 */
function logLunchAction($conn, $employeeID, $date, $field, $timeValue) {
    $stmt = $conn->prepare("
        INSERT INTO punch_changelog
        (EmployeeID, Date, ChangedBy, FieldChanged, OldValue, NewValue, Reason)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $changedBy = 'SYSTEM';
    $oldValue = 'NULL';
    $newValue = date('H:i:s', strtotime($timeValue));
    $reason = '*';

    $stmt->bind_param("issssss", $employeeID, $date, $changedBy, $field, $oldValue, $newValue, $reason);
    $result = $stmt->execute();
    $stmt->close();

    return $result;
}

/**
 * Find the employee
 */
function findEmployee($conn) {
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
        return null;
    }

    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    return $user;
}

/**
 * Handle lunch clock-out
 */
function lunchOut($conn) {
    echo "[" . date('Y-m-d H:i:s') . "] Starting auto lunch-out process...\n";

    $user = findEmployee($conn);
    if (!$user) {
        echo "ERROR: Employee not found\n";
        return false;
    }

    $employeeID = $user['ID'];
    $employeeName = $user['FirstName'] . ' ' . $user['LastName'];
    echo "Found employee: $employeeName (ID: $employeeID)\n";
    echo "Current status: {$user['ClockStatus']}\n";

    if ($user['ClockStatus'] !== 'In') {
        echo "Not currently clocked in. Skipping lunch-out.\n";
        return true;
    }

    // Find today's open punch record
    $today = date('Y-m-d');
    $stmt = $conn->prepare("
        SELECT ID, LunchStart FROM timepunches
        WHERE EmployeeID = ? AND Date = ? AND TimeOUT IS NULL
        ORDER BY TimeIN DESC LIMIT 1
    ");
    $stmt->bind_param("is", $employeeID, $today);
    $stmt->execute();
    $punch = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$punch) {
        echo "ERROR: No open punch record found for today.\n";
        return false;
    }

    if (!empty($punch['LunchStart'])) {
        echo "Lunch already started (LunchStart: {$punch['LunchStart']}). Skipping.\n";
        return true;
    }

    // Set LunchStart with randomization
    $lunchOutTime = randomizeTime(LUNCH_OUT_BASE_TIME, LUNCH_VARY_MINUTES);
    $lunchOutDateTime = $today . ' ' . $lunchOutTime;

    $updateStmt = $conn->prepare("UPDATE timepunches SET LunchStart = ? WHERE ID = ?");
    $updateStmt->bind_param("si", $lunchOutDateTime, $punch['ID']);

    if (!$updateStmt->execute()) {
        echo "ERROR: Failed to set LunchStart: " . $updateStmt->error . "\n";
        $updateStmt->close();
        return false;
    }
    $updateStmt->close();

    // Update ClockStatus to 'Out'
    $statusStmt = $conn->prepare("UPDATE users SET ClockStatus = 'Out' WHERE ID = ?");
    $statusStmt->bind_param("i", $employeeID);
    $statusStmt->execute();
    $statusStmt->close();

    logLunchAction($conn, $employeeID, $today, 'LunchStart', $lunchOutDateTime);

    echo "SUCCESS: Lunch out for $employeeName at $lunchOutDateTime\n";
    return true;
}

/**
 * Handle lunch clock-in (return from lunch)
 */
function lunchIn($conn) {
    echo "[" . date('Y-m-d H:i:s') . "] Starting auto lunch-in process...\n";

    $user = findEmployee($conn);
    if (!$user) {
        echo "ERROR: Employee not found\n";
        return false;
    }

    $employeeID = $user['ID'];
    $employeeName = $user['FirstName'] . ' ' . $user['LastName'];
    echo "Found employee: $employeeName (ID: $employeeID)\n";
    echo "Current status: {$user['ClockStatus']}\n";

    // Find today's punch record with LunchStart set but no LunchEnd
    $today = date('Y-m-d');
    $stmt = $conn->prepare("
        SELECT ID, LunchStart, LunchEnd FROM timepunches
        WHERE EmployeeID = ? AND Date = ? AND TimeOUT IS NULL AND LunchStart IS NOT NULL
        ORDER BY TimeIN DESC LIMIT 1
    ");
    $stmt->bind_param("is", $employeeID, $today);
    $stmt->execute();
    $punch = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$punch) {
        echo "No punch record with lunch-out found. Skipping.\n";
        return true;
    }

    if (!empty($punch['LunchEnd'])) {
        echo "Lunch already ended (LunchEnd: {$punch['LunchEnd']}). Skipping.\n";
        return true;
    }

    // Set LunchEnd with randomization
    $lunchInTime = randomizeTime(LUNCH_IN_BASE_TIME, LUNCH_VARY_MINUTES);
    $lunchInDateTime = $today . ' ' . $lunchInTime;

    $updateStmt = $conn->prepare("UPDATE timepunches SET LunchEnd = ? WHERE ID = ?");
    $updateStmt->bind_param("si", $lunchInDateTime, $punch['ID']);

    if (!$updateStmt->execute()) {
        echo "ERROR: Failed to set LunchEnd: " . $updateStmt->error . "\n";
        $updateStmt->close();
        return false;
    }
    $updateStmt->close();

    // Update ClockStatus back to 'In'
    $statusStmt = $conn->prepare("UPDATE users SET ClockStatus = 'In' WHERE ID = ?");
    $statusStmt->bind_param("i", $employeeID);
    $statusStmt->execute();
    $statusStmt->close();

    logLunchAction($conn, $employeeID, $today, 'LunchEnd', $lunchInDateTime);

    echo "SUCCESS: Lunch in for $employeeName at $lunchInDateTime\n";
    return true;
}

// --- MAIN EXECUTION ---
$action = $argv[1] ?? '';

if (!in_array($action, ['out', 'in'])) {
    echo "Usage: php auto_lunch.php [out|in]\n";
    exit(1);
}

try {
    if (!$conn) {
        throw new Exception("Database connection failed");
    }

    if ($action === 'out') {
        lunchOut($conn);
    } else {
        lunchIn($conn);
    }

    $conn->close();
    exit(0);

} catch (Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
