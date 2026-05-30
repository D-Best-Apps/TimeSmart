<?php
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

// Permission check
require_once __DIR__ . '/../functions/check_permission.php';
requirePermission('manage_users');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim($_POST['FirstName'] ?? '');
    $lastName  = trim($_POST['LastName'] ?? '');
    $badgeID   = trim($_POST['BadgeID'] ?? '');
    $pin       = trim($_POST['PIN'] ?? '');
    $office    = trim($_POST['Office'] ?? '');
    $password  = $_POST['Password'] ?? '';
    $scheduledStartTime = trim($_POST['ScheduledStartTime'] ?? '');

    // Basic validation
    if (!$firstName || !$lastName || !$office || !$password) {
        header('Location: ../error.php?code=400&message=' . urlencode('First name, last name, office, and password are required.'));
        exit;
    }

    // PIN, if given, must be 4-6 digits
    if ($pin !== '' && !preg_match('/^\d{4,6}$/', $pin)) {
        header('Location: ../error.php?code=400&message=' . urlencode('PIN must be 4 to 6 digits.'));
        exit;
    }

    // Badge ID, if given, must meet the minimum length
    if ($badgeID !== '' && strlen($badgeID) < 6) {
        header('Location: ../error.php?code=400&message=' . urlencode('Badge ID must be at least 6 characters.'));
        exit;
    }

    // Convert HH:MM to HH:MM:SS format for database
    if (!empty($scheduledStartTime)) {
        $scheduledStartTime .= ':00';
    } else {
        $scheduledStartTime = NULL;
    }

    // Check for duplicate Badge ID if provided
    if ($badgeID !== '') {
        $stmt = $conn->prepare("SELECT ID FROM users WHERE BadgeID = ?");
        $stmt->bind_param("s", $badgeID);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $stmt->close();
            header('Location: ../error.php?code=400&message=' . urlencode('Badge ID already exists.'));
            exit;
        }
        $stmt->close();
    }

    // Check for duplicate PIN if provided
    if ($pin !== '') {
        $stmt = $conn->prepare("SELECT ID FROM users WHERE PIN = ?");
        $stmt->bind_param("s", $pin);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $stmt->close();
            header('Location: ../error.php?code=400&message=' . urlencode('PIN already in use.'));
            exit;
        }
        $stmt->close();
    }

    // Enforce password policy
    require_once __DIR__ . '/../functions/password_policy.php';
    $pwErrors = validatePassword($password, $conn);
    if ($pwErrors) {
        header('Location: ../error.php?code=400&message=' . urlencode(implode(' ', $pwErrors)));
        exit;
    }

    // Hash the password securely
   $hashed = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    // Store blank Badge ID / PIN as NULL so they don't collide on the UNIQUE keys
    $badgeID = $badgeID !== '' ? $badgeID : null;
    $pin     = $pin !== '' ? $pin : null;

    // Insert user with default 2FA disabled and admin override ON
    $stmt = $conn->prepare("
        INSERT INTO users (FirstName, LastName, BadgeID, PIN, Office, ClockStatus, Pass, TwoFAEnabled, AdminOverride2FA, ScheduledStartTime)
        VALUES (?, ?, ?, ?, ?, 'Out', ?, 0, 1, ?)
    ");
    $stmt->bind_param("sssssss", $firstName, $lastName, $badgeID, $pin, $office, $hashed, $scheduledStartTime);

    if ($stmt->execute()) {
        header("Location: manage_users.php");
        exit;
    } else {
        header('Location: ../error.php?code=500&message=' . urlencode('Error: ' . $stmt->error));
        exit;
    }
}
?>
