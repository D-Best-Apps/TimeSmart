<?php
require_once 'header.php';
require_once __DIR__ . '/../functions/password_policy.php';

$msg = "";
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim($_POST['FirstName']);
    $lastName = trim($_POST['LastName']);
    $email = trim($_POST['Email']);
    $tagID = trim($_POST['TagID']);
    $jobTitle = trim($_POST['JobTitle']);
    $phone = trim($_POST['PhoneNumber']);
    $theme = $_POST['ThemePref'];

    if (!$firstName || !$lastName) {
        $errors[] = "First and Last Name are required.";
    }

    if (!empty($_POST['NewPassword']) || !empty($_POST['ConfirmPassword'])) {
        if ($_POST['NewPassword'] !== $_POST['ConfirmPassword']) {
            $errors[] = "Passwords do not match.";
        } elseif ($pwErrors = validatePassword($_POST['NewPassword'], $conn)) {
            $errors = array_merge($errors, $pwErrors);
        } else {
            $newPass = password_hash($_POST['NewPassword'], PASSWORD_BCRYPT, ['cost' => 14]);
            $stmt = $conn->prepare("UPDATE users SET Pass=? WHERE ID=?");
            $stmt->bind_param("si", $newPass, $empID);
            $stmt->execute();
        }
    }

    if (isset($_FILES['ProfilePhoto']) && $_FILES['ProfilePhoto']['error'] === UPLOAD_ERR_OK) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($_FILES['ProfilePhoto']['type'], $allowedTypes)) {
            $errors[] = "Invalid image type. Only JPG, PNG, WEBP allowed.";
        } elseif ($_FILES['ProfilePhoto']['size'] > 2 * 1024 * 1024) {
            $errors[] = "File must be under 2MB.";
        } else {
            $ext = pathinfo($_FILES['ProfilePhoto']['name'], PATHINFO_EXTENSION);
            $newName = "profile_$empID." . $ext;
            $uploadPath = "../uploads/" . $newName;
            move_uploaded_file($_FILES['ProfilePhoto']['tmp_name'], $uploadPath);
            $stmt = $conn->prepare("UPDATE users SET ProfilePhoto=? WHERE ID=?");
            $stmt->bind_param("si", $newName, $empID);
            $stmt->execute();
        }
    }

    if (empty($errors)) {
        $stmt = $conn->prepare("UPDATE users SET FirstName=?, LastName=?, Email=?, TagID=?, JobTitle=?, PhoneNumber=?, ThemePref=? WHERE ID=?");
        $stmt->bind_param("sssssssi", $firstName, $lastName, $email, $tagID, $jobTitle, $phone, $theme, $empID);
        $stmt->execute();
        $msg = "Profile updated successfully.";
    }
}

$stmt = $conn->prepare("SELECT * FROM users WHERE ID = ?");
$stmt->bind_param("i", $empID);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

$logStmt = $conn->prepare("SELECT IP, Timestamp FROM login_logs WHERE EmployeeID = ? ORDER BY Timestamp DESC LIMIT 5");
$logStmt->bind_param("i", $empID);
$logStmt->execute();
$logs = $logStmt->get_result();
?>
<link rel="stylesheet" href="../css/user_settings.css?v=2">


<script src="../js/user_settings.js"></script>
        <h1>User Settings Dashboard</h1>

        <?php if ($msg): ?>
            <div class="message"><?= $msg ?></div>
        <?php elseif ($errors): ?>
            <div class="error"><?= implode('<br>', $errors) ?></div>
        <?php endif; ?>

        <div class="tabs">
            <button id="profile-btn" onclick="switchTab('profile')">Profile</button>
            <button id="security-btn" onclick="switchTab('security')">Security</button>
            <button id="logs-btn" onclick="switchTab('logs')">Login History</button>
        </div>

        <form method="POST" enctype="multipart/form-data">
        <div class="tab-content" id="profile">
                <div><label>First Name</label><input type="text" name="FirstName" value="<?= htmlspecialchars($user['FirstName'] ?? '') ?>" required></div>
                <div><label>Last Name</label><input type="text" name="LastName" value="<?= htmlspecialchars($user['LastName'] ?? '') ?>" required></div>
                <div><label>Email</label><input type="email" name="Email" value="<?= htmlspecialchars($user['Email'] ?? '') ?>"></div>
                <div><label>Tag ID</label><input type="text" name="TagID" value="<?= htmlspecialchars($user['TagID'] ?? '') ?>"></div>
                <div><label>Job Title</label><input type="text" name="JobTitle" value="<?= htmlspecialchars($user['JobTitle'] ?? '') ?>"></div>
                <div><label>Phone Number</label><input type="text" name="PhoneNumber" value="<?= htmlspecialchars($user['PhoneNumber'] ?? '') ?>"></div>
                <div>
                    <label>Theme Preference</label>
                    <select name="ThemePref">
                        <option value="light" <?= $user['ThemePref'] === 'light' ? 'selected' : '' ?>>Light</option>
                        <option value="dark" <?= $user['ThemePref'] === 'dark' ? 'selected' : '' ?>>Dark</option>
                    </select>
                </div>
                <div class="full">
                    <label>Profile Photo</label>
                    <input type="file" name="ProfilePhoto" accept="image/*">
                    <?php if ($user['ProfilePhoto']): ?>
                        <img src="../uploads/<?= $user['ProfilePhoto'] ?>" alt="Profile" class="profile-preview">
                    <?php endif; ?>
                </div>

                <div class="actions full">
                    <button type="submit" class="btn-save">Save Changes</button>
                    <button type="reset" class="btn-cancel">Cancel</button>
                    <a href="dashboard.php" class="button btn-done">Done</a>
                </div>
        </div>

        <div class="tab-content" id="security">
            <p class="full" style="font-size:0.85rem; color:#9ca3af; margin:0 0 0.5rem;">
                Password requirements: <?= htmlspecialchars(passwordRequirementsText($conn)) ?>
            </p>
            <div class="password-box full">
                <label>New Password</label>
                <input type="password" name="NewPassword" id="NewPassword">
                <span class="toggle-password" onclick="togglePassword('NewPassword')">👁</span>
            </div>
            <div class="password-box full">
                <label>Confirm Password</label>
                <input type="password" name="ConfirmPassword" id="ConfirmPassword">
                <span class="toggle-password" onclick="togglePassword('ConfirmPassword')">👁</span>
            </div>

            <div class="actions full">
                <button type="submit" class="btn-save">Save Password</button>
            </div>
        </form>

            <div class="twofa-box full">
                <h3>Two-Factor Authentication</h3>
                <?php if ($user['TwoFAEnabled']): ?>
                    <p>✅ Two-factor authentication is <strong>on</strong>. At each login we email a one-time
                       code to <strong><?= htmlspecialchars($user['Email'] ?? '') ?: 'your address' ?></strong>.</p>
                    <p style="color:#666; font-size:0.9em;">
                        Make sure your email above is correct. To turn 2FA on or off, contact an administrator.
                    </p>
                <?php else: ?>
                    <p>❌ Two-factor authentication is <strong>off</strong>.</p>
                    <p style="color:#666; font-size:0.9em;">
                        2FA uses a code emailed to you at login. An administrator can enable it for your account.
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <div class="tab-content" id="logs">
            <div class="logs full">
                <h3>Recent Logins</h3>
                <?php while ($row = $logs->fetch_assoc()): ?>
                    <p><?= htmlspecialchars($row['Timestamp']) ?> — <?= htmlspecialchars($row['IP']) ?></p>
                <?php endwhile; ?>
            </div>
        </div>
<script src="../js/script.js"></script>
<?php require_once 'footer.php'; ?>
