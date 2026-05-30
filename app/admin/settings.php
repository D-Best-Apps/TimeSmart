<?php
session_start();
require_once '../auth/db.php';
require_once '../vendor/autoload.php'; // For PHPMailer
require_once __DIR__ . '/../functions/m365_calendar.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

// Permission check
require_once __DIR__ . '/../functions/check_permission.php';
requirePermission('manage_settings');

// --- Encryption/Decryption Functions ---
// For demonstration, a simple key. In production, this should be a strong, securely stored key.
define('ENCRYPTION_KEY', 'a_very_secret_key_for_encryption_32_chars'); // 32 bytes for AES-256
define('CIPHER_METHOD', 'aes-256-cbc');

function encrypt_data($data) {
    $iv_len = openssl_cipher_iv_length(CIPHER_METHOD);
    $iv = openssl_random_pseudo_bytes($iv_len);
    $encrypted = openssl_encrypt($data, CIPHER_METHOD, ENCRYPTION_KEY, 0, $iv);
    return base64_encode($encrypted . '::' . $iv);
}

function decrypt_data($data) {
    $parts = explode('::', base64_decode($data), 2);
    if (count($parts) === 2) {
        list($encrypted_data, $iv) = $parts;
        return openssl_decrypt($encrypted_data, CIPHER_METHOD, ENCRYPTION_KEY, 0, $iv);
    }
    return false;
}
// --- End Encryption/Decryption Functions ---


// Fetch all settings from the database
$settings = [];
$result = $conn->query("SELECT * FROM settings");
while ($row = $result->fetch_assoc()) {
    $settings[$row['SettingKey']] = $row['SettingValue'];
}

// Decrypt mail_password for display and use
if (isset($settings['mail_password']) && !empty($settings['mail_password'])) {
    $decrypted_password = decrypt_data($settings['mail_password']);
    if ($decrypted_password !== false) {
        $settings['mail_password_display'] = $decrypted_password; // For displaying in the form
    } else {
        $settings['mail_password_display'] = 'Error decrypting password';
        error_log("Error decrypting mail_password from DB in admin/settings.php");
    }
} else {
    $settings['mail_password_display'] = '';
}

// Decrypt M365 client secret for editing
$settings['m365_client_secret_display'] = '';
if (!empty($settings['m365_client_secret'])) {
    $dec = decrypt_data($settings['m365_client_secret']);
    if ($dec !== false) {
        $settings['m365_client_secret_display'] = $dec;
    } else {
        $settings['m365_client_secret_display'] = 'Error decrypting M365 secret';
        error_log("Error decrypting m365_client_secret from DB in admin/settings.php");
    }
}


$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Update settings
    $settingsToUpdate = [
        'mail_server', 'mail_port', 'mail_username',
        'mail_from_address', 'mail_from_name', 'mail_encryption', 'mail_admin_address',
        'm365_tenant_id', 'm365_client_id', 'm365_calendar_mailbox', 'm365_timezone'
    ];

    foreach ($settingsToUpdate as $key) {
        if (isset($_POST[$key])) {
            $value = $_POST[$key];
            $stmt = $conn->prepare("INSERT INTO settings (SettingKey, SettingValue) VALUES (?, ?) ON DUPLICATE KEY UPDATE SettingValue = ?");
            $stmt->bind_param("sss", $key, $value, $value);
            $stmt->execute();
            $settings[$key] = $value;
        }
    }

    // m365_enabled is a checkbox — store '1' or '0'
    $m365EnabledVal = !empty($_POST['m365_enabled']) ? '1' : '0';
    $stmt = $conn->prepare("INSERT INTO settings (SettingKey, SettingValue) VALUES (?, ?) ON DUPLICATE KEY UPDATE SettingValue = ?");
    $key = 'm365_enabled';
    $stmt->bind_param("sss", $key, $m365EnabledVal, $m365EnabledVal);
    $stmt->execute();
    $settings['m365_enabled'] = $m365EnabledVal;

    // Security & punch policy checkboxes
    $enforceGpsVal = !empty($_POST['EnforceGPS']) ? '1' : '0';
    $stmt = $conn->prepare("INSERT INTO settings (SettingKey, SettingValue) VALUES (?, ?) ON DUPLICATE KEY UPDATE SettingValue = ?");
    $key = 'EnforceGPS';
    $stmt->bind_param("sss", $key, $enforceGpsVal, $enforceGpsVal);
    $stmt->execute();
    $settings['EnforceGPS'] = $enforceGpsVal;

    $pwModeVal = !empty($_POST['PasswordHigh']) ? 'high' : 'low';
    $stmt = $conn->prepare("INSERT INTO settings (SettingKey, SettingValue) VALUES (?, ?) ON DUPLICATE KEY UPDATE SettingValue = ?");
    $key = 'PasswordSecurityMode';
    $stmt->bind_param("sss", $key, $pwModeVal, $pwModeVal);
    $stmt->execute();
    $settings['PasswordSecurityMode'] = $pwModeVal;

    // Handle mail_password separately for encryption
    if (isset($_POST['mail_password']) && !empty($_POST['mail_password'])) {
        $encrypted_password = encrypt_data($_POST['mail_password']);
        $stmt = $conn->prepare("INSERT INTO settings (SettingKey, SettingValue) VALUES (?, ?) ON DUPLICATE KEY UPDATE SettingValue = ?");
        $key = 'mail_password';
        $stmt->bind_param("sss", $key, $encrypted_password, $encrypted_password);
        $stmt->execute();
        $settings['mail_password'] = $encrypted_password; // Update in settings array
        $settings['mail_password_display'] = $_POST['mail_password']; // Update display value
    }

    // Handle M365 client secret separately for encryption
    if (isset($_POST['m365_client_secret']) && !empty($_POST['m365_client_secret'])) {
        $encrypted_secret = encrypt_data($_POST['m365_client_secret']);
        $stmt = $conn->prepare("INSERT INTO settings (SettingKey, SettingValue) VALUES (?, ?) ON DUPLICATE KEY UPDATE SettingValue = ?");
        $key = 'm365_client_secret';
        $stmt->bind_param("sss", $key, $encrypted_secret, $encrypted_secret);
        $stmt->execute();
        $settings['m365_client_secret'] = $encrypted_secret;
        $settings['m365_client_secret_display'] = $_POST['m365_client_secret'];
    }


    // Test email functionality
    if (isset($_POST['test_email'])) {
        $mail = new PHPMailer(true);
        try {
            //Server settings
            $mail->isSMTP();
            $mail->Host       = $settings['mail_server'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $settings['mail_username'];
            // Use the decrypted password for sending test email
            $mail->Password   = $settings['mail_password_display']; // This is the decrypted one
            $mail->SMTPSecure = $settings['mail_encryption'];
            $mail->Port       = $settings['mail_port'];

            //Recipients
            $mail->setFrom($settings['mail_from_address'], $settings['mail_from_name']);
            $mail->addAddress($settings['mail_admin_address']);

            // Content
            $mail->isHTML(true);
            $mail->Subject = 'Test Email from TimeSmart';
            $mail->Body    = 'This is a test email to verify your mail server settings.';

            $mail->send();
            $message = '<div class="alert alert-success">Test email sent successfully!</div>';
        } catch (Exception $e) {
            $message = '<div class="alert alert-danger">Test email could not be sent. Mailer Error: ' . $mail->ErrorInfo . '</div>';
        }
    } elseif (isset($_POST['test_m365'])) {
        $testResult = m365TestConnection($conn);
        if ($testResult['success']) {
            $mailLine = !empty($testResult['group_mail'])
                ? ' (mail: ' . htmlspecialchars($testResult['group_mail']) . ')'
                : '';
            $message = '<div class="alert alert-success">M365 connection OK. Found group: <strong>'
                     . htmlspecialchars($testResult['group_name']) . '</strong>' . $mailLine . '</div>';
        } else {
            $message = '<div class="alert alert-danger">M365 test failed: '
                     . htmlspecialchars($testResult['error']) . '</div>';
        }
    } else {
        $message = '<div class="alert alert-success">Settings saved successfully!</div>';
    }
}

$pageTitle = "Settings";
require_once 'header.php';
?>


<div class="dashboard-container">
    <div class="container">
        <?= $message ?>
        <form method="POST" class="settings-form">
            <h2>Security &amp; Punch Policy</h2>
            <div class="field">
                <label>
                    <input type="checkbox" name="EnforceGPS" value="1" <?= ($settings['EnforceGPS'] ?? '') === '1' ? 'checked' : '' ?>>
                    Require GPS for all punches
                </label>
                <p style="color:#666; font-size:0.85em; margin:0.25rem 0 0;">
                    When on, employees must share location to clock in/out.
                </p>
            </div>
            <div class="field">
                <label>
                    <input type="checkbox" name="PasswordHigh" value="1" <?= ($settings['PasswordSecurityMode'] ?? '') === 'high' ? 'checked' : '' ?>>
                    High-security passwords
                </label>
                <p style="color:#666; font-size:0.85em; margin:0.25rem 0 0;">
                    On: at least 7 characters and 3 of 4 character types — uppercase, lowercase, number, symbol (recommended for online installs).
                    Off: at least 2 characters, any (for offline / air-gapped installs).
                </p>
            </div>
            <div class="buttons">
                <button type="submit">Save Settings</button>
            </div>
            <hr style="margin: 2rem 0;">

            <h2>Mail Server Settings</h2>
            <div class="field">
                <label for="mail_server">Mail Server (SMTP Host):</label>
                <input type="text" id="mail_server" name="mail_server" value="<?= htmlspecialchars($settings['mail_server'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="mail_port">SMTP Port:</label>
                <input type="number" id="mail_port" name="mail_port" value="<?= htmlspecialchars($settings['mail_port'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="mail_username">SMTP Username:</label>
                <input type="text" id="mail_username" name="mail_username" value="<?= htmlspecialchars($settings['mail_username'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="mail_password">SMTP Password:</label>
                <input type="password" id="mail_password" name="mail_password" value="<?= htmlspecialchars($settings['mail_password_display'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="mail_encryption">SMTP Encryption:</label>
                <select id="mail_encryption" name="mail_encryption">
                    <option value="ssl" <?= ($settings['mail_encryption'] ?? '') == 'ssl' ? 'selected' : '' ?>>SSL</option>
                    <option value="tls" <?= ($settings['mail_encryption'] ?? '') == 'tls' ? 'selected' : '' ?>>TLS</option>
                </select>
            </div>
            <div class="field">
                <label for="mail_from_address">From Email Address:</label>
                <input type="email" id="mail_from_address" name="mail_from_address" value="<?= htmlspecialchars($settings['mail_from_address'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="mail_from_name">From Name:</label>
                <input type="text" id="mail_from_name" name="mail_from_name" value="<?= htmlspecialchars($settings['mail_from_name'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="mail_admin_address">Admin Email Address (for notifications):</label>
                <input type="email" id="mail_admin_address" name="mail_admin_address" value="<?= htmlspecialchars($settings['mail_admin_address'] ?? '') ?>">
            </div>
            <div class="buttons">
                <button type="submit">Save Settings</button>
                <button type="submit" name="test_email" value="1">Save &amp; Send Test Email</button>
            </div>
            <hr style="margin: 2rem 0;">

            <h2 id="m365">M365 PTO Calendar Sync</h2>
            <p style="color:#555; font-size:0.9em;">
                When a time-off request is approved, an event is posted to a Microsoft 365
                <strong>shared mailbox</strong> calendar. Requires an Azure AD app registration with the
                <code>Calendars.ReadWrite</code> application permission (admin consent granted), plus an
                Exchange Application Access Policy and RBAC role scoped to that mailbox.
                See <a href="../../docs/M365_SETUP.md" target="_blank">docs/M365_SETUP.md</a> for the full setup.
                <br><em>Note: Unified Group calendars are not supported here — use a shared mailbox.</em>
            </p>

            <div class="field">
                <label>
                    <input type="checkbox" name="m365_enabled" value="1" <?= ($settings['m365_enabled'] ?? '') === '1' ? 'checked' : '' ?>>
                    Enable M365 calendar sync on approval
                </label>
            </div>

            <div class="field">
                <label for="m365_tenant_id">Tenant ID (Directory ID):</label>
                <input type="text" id="m365_tenant_id" name="m365_tenant_id"
                       value="<?= htmlspecialchars($settings['m365_tenant_id'] ?? '') ?>"
                       placeholder="e.g. 11111111-2222-3333-4444-555555555555">
            </div>

            <div class="field">
                <label for="m365_client_id">Client ID (Application ID):</label>
                <input type="text" id="m365_client_id" name="m365_client_id"
                       value="<?= htmlspecialchars($settings['m365_client_id'] ?? '') ?>"
                       placeholder="from Azure App registrations">
            </div>

            <div class="field">
                <label for="m365_client_secret">Client Secret:</label>
                <input type="password" id="m365_client_secret" name="m365_client_secret"
                       value="<?= htmlspecialchars($settings['m365_client_secret_display'] ?? '') ?>"
                       placeholder="from Certificates &amp; secrets">
            </div>

            <div class="field">
                <label for="m365_calendar_mailbox">PTO Calendar Mailbox (UPN):</label>
                <input type="text" id="m365_calendar_mailbox" name="m365_calendar_mailbox"
                       value="<?= htmlspecialchars($settings['m365_calendar_mailbox'] ?? '') ?>"
                       placeholder="e.g. ptocalendar@yourdomain.com">
                <p style="color:#666; font-size:0.85em; margin:0.25rem 0 0;">
                    The shared mailbox whose calendar receives PTO events (primary SMTP address).
                </p>
            </div>

            <div class="field">
                <label for="m365_timezone">Calendar Time Zone (IANA):</label>
                <input type="text" id="m365_timezone" name="m365_timezone"
                       value="<?= htmlspecialchars($settings['m365_timezone'] ?? 'America/Chicago') ?>">
            </div>

            <div class="buttons">
                <button type="submit">Save Settings</button>
                <button type="submit" name="test_m365" value="1">Save &amp; Test M365 Connection</button>
            </div>
        </form>
    </div>
</div>


<?php require_once 'footer.php'; ?>
