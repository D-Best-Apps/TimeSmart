<?php
/**
 * Auto Clock-Out Script
 *
 * This script runs at midnight and automatically clocks out any employees
 * who are still clocked in, setting their clock-out time to 5:00 PM retroactively.
 *
 * Usage: php /path/to/auto_clockout.php
 * Cron: 0 0 * * * php /var/www/html/scripts/auto_clockout.php >> /var/log/auto_clockout.log 2>&1
 */

require_once __DIR__ . '/../auth/db.php';
date_default_timezone_set('America/Chicago');

// Configuration
define('AUTO_CLOCKOUT_TIME', '17:00:00'); // 5:00 PM
define('AUTO_CLOCKOUT_NOTE', 'Auto-clocked out at 5:00 PM - forgot to clock out');

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
    // Extract just the time part (HH:MM:SS) for the NewValue column
    $newValue = date('H:i:s', strtotime($clockOutTime));
    $reason = AUTO_CLOCKOUT_NOTE;

    $stmt->bind_param("issssss", $employeeID, $date, $changedBy, $field, $oldValue, $newValue, $reason);
    $result = $stmt->execute();
    $stmt->close();

    return $result;
}

/**
 * Main auto clock-out logic
 */
function autoClockoutEmployees($conn) {
    $processedCount = 0;
    $errorCount = 0;
    $results = [];

    echo "[" . date('Y-m-d H:i:s') . "] Starting auto clock-out process...\n";

    // Find all users still clocked in
    $stmt = $conn->prepare("
        SELECT ID, FirstName, LastName, ClockStatus
        FROM users
        WHERE ClockStatus = 'In'
    ");

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

    // Process each employee
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

        $punchResult = $punchStmt->get_result();
        $punch = $punchResult->fetch_assoc();
        $punchStmt->close();

        if (!$punch) {
            echo "    WARNING: No open punch record found. Updating ClockStatus only.\n";
            // Update ClockStatus anyway to fix inconsistent state
            $updateStatusStmt = $conn->prepare("UPDATE users SET ClockStatus = 'Out' WHERE ID = ?");
            $updateStatusStmt->bind_param("i", $employeeID);
            $updateStatusStmt->execute();
            $updateStatusStmt->close();
            continue;
        }

        $punchID = $punch['ID'];
        $date = $punch['Date'];
        $clockIn = $punch['TimeIN'];
        $lunchOut = $punch['LunchStart'];
        $lunchIn = $punch['LunchEnd'];

        // Construct the full clock-out datetime (date + 5PM time)
        $clockOutDateTime = $date . ' ' . AUTO_CLOCKOUT_TIME;

        // Calculate total hours
        $totalHours = calculateTotalHours($clockIn, $lunchOut, $lunchIn, $clockOutDateTime);

        if ($totalHours === null) {
            echo "    ERROR: Failed to calculate hours (invalid times)\n";
            $errorCount++;
            continue;
        }

        // Update the punch record with clock-out time
        $updateStmt = $conn->prepare("
            UPDATE timepunches
            SET TimeOUT = ?,
                TotalHours = ?,
                Note = CONCAT(COALESCE(Note, ''), ?)
            WHERE ID = ? AND EmployeeID = ?
        ");

        $note = "\n" . AUTO_CLOCKOUT_NOTE;
        $updateStmt->bind_param("sdsii", $clockOutDateTime, $totalHours, $note, $punchID, $employeeID);

        if (!$updateStmt->execute()) {
            echo "    ERROR: Failed to update punch record: " . $updateStmt->error . "\n";
            $errorCount++;
            $updateStmt->close();
            continue;
        }
        $updateStmt->close();

        // Update ClockStatus to 'Out'
        $statusStmt = $conn->prepare("UPDATE users SET ClockStatus = 'Out' WHERE ID = ?");
        $statusStmt->bind_param("i", $employeeID);

        if (!$statusStmt->execute()) {
            echo "    WARNING: Failed to update ClockStatus: " . $statusStmt->error . "\n";
        }
        $statusStmt->close();

        // Log to changelog for audit trail
        logAutoClockout($conn, $employeeID, $date, $clockOutDateTime);

        echo "    SUCCESS: Clocked out at $clockOutDateTime with $totalHours hours\n";
        $processedCount++;

        $results[] = [
            'employee' => $employeeName,
            'id' => $employeeID,
            'date' => $date,
            'clockOut' => $clockOutDateTime,
            'hours' => $totalHours
        ];
    }

    echo "\n[" . date('Y-m-d H:i:s') . "] Auto clock-out complete.\n";
    echo "  Processed: $processedCount employee(s)\n";
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
