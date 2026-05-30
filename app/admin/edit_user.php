<?php
require_once '../auth/db.php';
session_start();


ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

// Permission check
require_once __DIR__ . '/../functions/check_permission.php';
requirePermission('manage_users');

$id = $_GET['id'] ?? null;
if (!$id || !is_numeric($id)) {
    header("Location: manage_users.php");
    exit;
}

// Get user
$stmt = $conn->prepare("SELECT * FROM users WHERE ID = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if (!$user) {
    echo "User not found.";
    exit;
}

$offices_result = $conn->query("SELECT OfficeName FROM Offices ORDER BY OfficeName");
$offices_data = [];
while ($row = $offices_result->fetch_assoc()) {
    $offices_data[] = $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim($_POST['FirstName']);
    $lastName = trim($_POST['LastName']);
    $email = trim($_POST['Email']);
    $badgeID = trim($_POST['BadgeID'] ?? '');
    $pin = trim($_POST['PIN'] ?? '');
    $clockStatus = trim($_POST['ClockStatus']);
    $office = trim($_POST['Office']);
    $jobTitle = trim($_POST['JobTitle']);
    $phone = trim($_POST['PhoneNumber']);
    $password = $_POST['Password'];
    $enable2FA = isset($_POST['Enable2FA']) ? 1 : 0;

    // PIN, if given, must be 4-6 digits
    if ($pin !== '' && !preg_match('/^\d{4,6}$/', $pin)) {
        header('Location: ../error.php?code=400&message=' . urlencode('PIN must be 4 to 6 digits.'));
        exit;
    }

    // Uniqueness checks (excluding this same user)
    if ($badgeID !== '') {
        $chk = $conn->prepare("SELECT ID FROM users WHERE BadgeID = ? AND ID <> ?");
        $chk->bind_param("si", $badgeID, $id);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            $chk->close();
            header('Location: ../error.php?code=400&message=' . urlencode('Badge ID already exists.'));
            exit;
        }
        $chk->close();
    }
    if ($pin !== '') {
        $chk = $conn->prepare("SELECT ID FROM users WHERE PIN = ? AND ID <> ?");
        $chk->bind_param("si", $pin, $id);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            $chk->close();
            header('Location: ../error.php?code=400&message=' . urlencode('PIN already in use.'));
            exit;
        }
        $chk->close();
    }

    // Blank Badge ID / PIN stored as NULL so they don't collide on the UNIQUE keys
    $badgeID = $badgeID !== '' ? $badgeID : null;
    $pin     = $pin !== '' ? $pin : null;

    if ($firstName && $lastName && $email && $clockStatus && $office) {
        if (!empty($password)) {
            require_once __DIR__ . '/../functions/password_policy.php';
            $pwErrors = validatePassword($password, $conn);
            if ($pwErrors) {
                header('Location: ../error.php?code=400&message=' . urlencode(implode(' ', $pwErrors)));
                exit;
            }
            $hashed = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $conn->prepare("UPDATE users SET FirstName = ?, LastName = ?, Email = ?, BadgeID = ?, PIN = ?, ClockStatus = ?, Office = ?, Pass = ?, JobTitle = ?, PhoneNumber = ?, TwoFAEnabled = ? WHERE ID = ?");
            $stmt->bind_param("ssssssssssii", $firstName, $lastName, $email, $badgeID, $pin, $clockStatus, $office, $hashed, $jobTitle, $phone, $enable2FA, $id);
        } else {
            $stmt = $conn->prepare("UPDATE users SET FirstName = ?, LastName = ?, Email = ?, BadgeID = ?, PIN = ?, ClockStatus = ?, Office = ?, JobTitle = ?, PhoneNumber = ?, TwoFAEnabled = ? WHERE ID = ?");
            $stmt->bind_param("sssssssssii", $firstName, $lastName, $email, $badgeID, $pin, $clockStatus, $office, $jobTitle, $phone, $enable2FA, $id);
        }

        $stmt->execute();

        header("Location: manage_users.php");
        exit;
    } else {
        $error = "All fields except password are required.";
    }
}
$pageTitle = "Edit User";
$extraCSS = ["../css/edit_user.css"];
require_once 'header.php';
?>


<div class="uman-container">
    <h2>Edit User</h2>
    <?php if (!empty($error)): ?>
        <div class="alert error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="uman-form">
        <label>First Name
            <input type="text" name="FirstName" value="<?= htmlspecialchars($user['FirstName'] ?? '') ?>" required>
        </label>

        <label>Last Name
            <input type="text" name="LastName" value="<?= htmlspecialchars($user['LastName'] ?? '') ?>" required>
        </label>

        <label>Email
            <input type="text" name="Email" value="<?= htmlspecialchars($user['Email'] ?? '') ?>" required>
        </label>

        <label>Badge ID <small>(scannable barcode value)</small>
            <input type="text" name="BadgeID" value="<?= htmlspecialchars($user['BadgeID'] ?? '') ?>">
        </label>

        <label>PIN <small>(4-6 digits for quick clock; leave blank for none)</small>
            <input type="text" name="PIN" value="<?= htmlspecialchars($user['PIN'] ?? '') ?>" inputmode="numeric" pattern="\d{4,6}" maxlength="6" autocomplete="off">
        </label>

        <label>Clock Status
            <select name="ClockStatus" required>
                <option value="In" <?= $user['ClockStatus'] === 'In' ? 'selected' : '' ?>>In</option>
                <option value="Out" <?= $user['ClockStatus'] === 'Out' ? 'selected' : '' ?>>Out</option>
                <option value="Break" <?= $user['ClockStatus'] === 'Break' ? 'selected' : '' ?>>Break</option>
                <option value="Lunch" <?= $user['ClockStatus'] === 'Lunch' ? 'selected' : '' ?>>Lunch</option>
            </select>
        </label>

        <label>Office
            <select name="Office" required>
                <?php foreach ($offices_data as $office): ?>
                    <option value="<?= htmlspecialchars($office['OfficeName']) ?>" <?= ($user['Office'] === $office['OfficeName']) ? 'selected' : '' ?>><?= htmlspecialchars($office['OfficeName']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>Job Title
            <input type="text" name="JobTitle" value="<?= htmlspecialchars($user['JobTitle'] ?? '') ?>">
        </label>

        <label>Phone Number
            <input type="text" name="PhoneNumber" value="<?= htmlspecialchars($user['PhoneNumber'] ?? '') ?>">
        </label>

        <label>Password <small>(leave blank to keep current)</small>
            <input type="password" name="Password">
        </label>

        <div class="switch-wrapper">
            <span class="switch-label">Require 2FA (emailed code at login)</span>
            <label class="switch">
                <input type="checkbox" name="Enable2FA" <?= $user['TwoFAEnabled'] ? 'checked' : '' ?>>
                <span class="slider"></span>
            </label>
        </div>
        <p style="color:#666; font-size:0.85em; margin:0.25rem 0 1rem;">
            When on, this user must enter a one-time code emailed to <strong><?= htmlspecialchars($user['Email'] ?? '') ?: 'their address' ?></strong>
            each time they sign in. Requires an email on file. Manage admin roles on the <a href="manage_admins.php">Admins</a> page.
        </p>

        <div class="modal-actions">
            <a href="manage_users.php" class="btn danger">Cancel</a>
            <button type="submit" class="btn primary">Save Changes</button>
        </div>
    </form>
</div>


<?php require_once 'footer.php'; ?>
