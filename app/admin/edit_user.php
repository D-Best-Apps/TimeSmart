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
require_once __DIR__ . '/../functions/csrf.php';
require_csrf(csrf_error_url());
require_once __DIR__ . '/../functions/profile_photo.php';
require_once __DIR__ . '/../functions/pay_rules.php'; // listShifts()
require_once __DIR__ . '/../functions/time_off_hours.php'; // freezeTimeOffShifts()
require_once __DIR__ . '/../functions/pin_policy.php';

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
if ($user['ArchivedAt'] !== null) {
    // Editing would hand an archived employee a badge/PIN again; unarchive first
    header("Location: archived_users.php?msg=" . urlencode($user['FirstName'] . ' ' . $user['LastName'] . ' is archived. Unarchive them to edit their details.'));
    exit;
}

$offices_result = $conn->query("SELECT OfficeName FROM Offices ORDER BY OfficeName");
$offices_data = [];
while ($row = $offices_result->fetch_assoc()) {
    $offices_data[] = $row;
}
$shifts = listShifts($conn, true);

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
    $empNumber = trim($_POST['EmployeeNumber'] ?? '');
    $holidayHoursIn = trim($_POST['HolidayHours'] ?? '');

    // Email is optional (Add User doesn't ask for one), except that emailed 2FA codes need it
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        header('Location: ../error.php?code=400&message=' . urlencode('That email address doesn\'t look right.'));
        exit;
    }
    if ($email === '' && $enable2FA) {
        header('Location: ../error.php?code=400&message=' . urlencode('Require 2FA needs an email on file (the sign-in codes are emailed). Add an email or turn 2FA off.'));
        exit;
    }

    // Employee # (optional): the company's own number, unique, shown on Home
    if (strlen($empNumber) > 20) {
        header('Location: ../error.php?code=400&message=' . urlencode('Employee # can be at most 20 characters.'));
        exit;
    }
    if ($empNumber !== '') {
        $chk = $conn->prepare("SELECT ID FROM users WHERE EmployeeNumber = ? AND ID <> ?");
        $chk->bind_param("si", $empNumber, $id);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            header('Location: ../error.php?code=400&message=' . urlencode('Employee # ' . $empNumber . ' is already used by someone else.'));
            exit;
        }
        $chk->close();
    }
    // Holiday hours (optional): blank = the holiday-pay setting
    if ($holidayHoursIn !== '' && (!is_numeric($holidayHoursIn) || (float) $holidayHoursIn < 0 || (float) $holidayHoursIn > 24)) {
        header('Location: ../error.php?code=400&message=' . urlencode('Holiday hours must be a number from 0 to 24, or blank.'));
        exit;
    }

    // PIN, if given, must meet the PIN length setting (an unchanged PIN on file is kept)
    if ($pin !== '' && !isValidNewPin($pin, $conn) && $pin !== (string) ($user['PIN'] ?? '')) {
        header('Location: ../error.php?code=400&message=' . urlencode('PIN must be ' . pinRuleText($conn) . '.'));
        exit;
    }

    // Badge ID, if given, must meet the minimum length
    if ($badgeID !== '' && strlen($badgeID) < 6) {
        header('Location: ../error.php?code=400&message=' . urlencode('Badge ID must be at least 6 characters.'));
        exit;
    }

    // The kiosk takes a badge scan or a PIN in the same box, so a PIN may never equal
    // a badge number (this employee's or anyone else's), and the other way round.
    if ($badgeID !== '' && $badgeID === $pin) {
        header('Location: ../error.php?code=400&message=' . urlencode('PIN and Badge ID must be different.'));
        exit;
    }

    // Uniqueness checks (excluding this same user)
    if ($badgeID !== '') {
        $chk = $conn->prepare("SELECT ID FROM users WHERE (BadgeID = ? OR PIN = ?) AND ID <> ?");
        $chk->bind_param("ssi", $badgeID, $badgeID, $id);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            $chk->close();
            header('Location: ../error.php?code=400&message=' . urlencode('Badge ID already exists (as a badge or as a PIN).'));
            exit;
        }
        $chk->close();
    }
    if ($pin !== '') {
        $chk = $conn->prepare("SELECT ID FROM users WHERE (PIN = ? OR BadgeID = ?) AND ID <> ?");
        $chk->bind_param("ssi", $pin, $pin, $id);
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
    $email   = $email !== '' ? $email : null;

    if ($firstName && $lastName && $clockStatus && $office) {
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

        // Shift (blank = none). Only a shift that exists; an archived one can stay assigned.
        $shiftID = (int) ($_POST['ShiftID'] ?? 0);
        $shiftID = isset($shifts[$shiftID]) ? $shiftID : null;
        freezeTimeOffShifts($conn, [$id]); // past time off keeps the shift it was taken under
        $up = $conn->prepare("UPDATE users SET ShiftID = ? WHERE ID = ?");
        $up->bind_param("ii", $shiftID, $id);
        $up->execute();

        $empNumberVal    = $empNumber !== '' ? $empNumber : null;
        $holidayHoursVal = $holidayHoursIn !== '' ? round((float) $holidayHoursIn, 2) : null;
        $up = $conn->prepare("UPDATE users SET EmployeeNumber = ?, HolidayHours = ? WHERE ID = ?");
        $up->bind_param("sdi", $empNumberVal, $holidayHoursVal, $id);
        $up->execute();

        // Optional profile photo (also used on printed badges)
        [$photoOk, $photoRes] = save_profile_photo($_FILES['ProfilePhoto'] ?? null, (int) $id);
        if ($photoOk === false) {
            header('Location: ../error.php?code=400&message=' . urlencode($photoRes));
            exit;
        } elseif ($photoOk === true) {
            $up = $conn->prepare("UPDATE users SET ProfilePhoto=? WHERE ID=?");
            $up->bind_param("si", $photoRes, $id);
            $up->execute();
        }

        header("Location: manage_users.php");
        exit;
    } else {
        $error = "First name, last name, clock status and office are required.";
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

    <form method="POST" class="uman-form" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <label>First Name
            <input type="text" name="FirstName" value="<?= htmlspecialchars($user['FirstName'] ?? '') ?>" required>
        </label>

        <label>Last Name
            <input type="text" name="LastName" value="<?= htmlspecialchars($user['LastName'] ?? '') ?>" required>
        </label>

        <label>Email <small>(optional; needed for emailed 2FA codes and time-off notices)</small>
            <input type="email" name="Email" value="<?= htmlspecialchars($user['Email'] ?? '') ?>">
        </label>

        <label>Employee # <small>(your own employee number; shown on Home — not used to clock in)</small>
            <input type="text" name="EmployeeNumber" maxlength="20" value="<?= htmlspecialchars($user['EmployeeNumber'] ?? '') ?>" autocomplete="off">
        </label>

        <label>Badge ID <small>(scannable barcode value)</small>
            <input type="text" name="BadgeID" value="<?= htmlspecialchars($user['BadgeID'] ?? '') ?>" autocomplete="off" data-1p-ignore="true" data-lpignore="true">
        </label>

        <label>PIN <small>(<?= htmlspecialchars(pinRuleText($conn)) ?> for quick clock and kiosk; leave blank for none)</small>
            <input type="text" name="PIN" value="<?= htmlspecialchars($user['PIN'] ?? '') ?>" inputmode="numeric" pattern="<?= htmlspecialchars(pinInputPattern($conn, (string) ($user['PIN'] ?? ''))) ?>" maxlength="<?= PIN_MAX_LENGTH ?>" autocomplete="off" data-1p-ignore="true" data-lpignore="true">
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

        <label>Shift <small>(sets punch rounding and lunch — see Settings &rsaquo; Shifts)</small>
            <select name="ShiftID">
                <option value="">No shift (Pay Rules defaults)</option>
                <?php foreach ($shifts as $s): ?>
                    <?php if ($s['Archived'] && (int) $s['ID'] !== (int) ($user['ShiftID'] ?? 0)) continue; ?>
                    <option value="<?= (int) $s['ID'] ?>" <?= (int) $s['ID'] === (int) ($user['ShiftID'] ?? 0) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($s['Name'] . ' — ' . shiftTimeRange($s) . ($s['Archived'] ? ' (archived)' : '')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>Holiday hours <small>(hours of holiday pay for a full holiday, e.g. 8 for a part-timer; blank = the setting on Settings &rsaquo; Holidays)</small>
            <input type="number" name="HolidayHours" min="0" max="24" step="0.25" value="<?= ($user['HolidayHours'] ?? null) !== null ? htmlspecialchars(rtrim(rtrim((string) $user['HolidayHours'], '0'), '.')) : '' ?>" placeholder="Setting">
        </label>

        <label>Job Title
            <input type="text" name="JobTitle" value="<?= htmlspecialchars($user['JobTitle'] ?? '') ?>">
        </label>

        <label>Phone Number
            <input type="text" name="PhoneNumber" value="<?= htmlspecialchars($user['PhoneNumber'] ?? '') ?>">
        </label>

        <label>Photo <small>(for the badge; JPG/PNG/WEBP, under 2 MB)</small>
            <?php if (!empty($user['ProfilePhoto']) && file_exists(__DIR__ . '/../uploads/' . $user['ProfilePhoto'])): ?>
                <img src="../uploads/<?= htmlspecialchars($user['ProfilePhoto']) ?>" alt="Current photo" style="display:block; width:80px; height:80px; object-fit:cover; border-radius:8px; margin:0.3rem 0;">
            <?php endif; ?>
            <input type="file" name="ProfilePhoto" accept="image/png,image/jpeg,image/webp">
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
