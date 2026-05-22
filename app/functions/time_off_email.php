<?php
// Shared PHPMailer wrapper for the time-off request workflow.
// Used by app/user/submit_time_off.php (notify admin on submit) and
// app/admin/process_time_off.php (notify employee on decision).

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Match the encryption used by app/admin/settings.php encrypt_data/decrypt_data.
// mail_password in the settings table is stored AES-256-CBC encrypted.
if (!defined('TIME_OFF_MAIL_ENC_KEY')) {
    define('TIME_OFF_MAIL_ENC_KEY', 'a_very_secret_key_for_encryption_32_chars');
    define('TIME_OFF_MAIL_ENC_CIPHER', 'aes-256-cbc');
}

function timeOffDecryptMailPassword(?string $encrypted): ?string {
    if (!$encrypted) return null;
    $parts = explode('::', base64_decode($encrypted), 2);
    if (count($parts) !== 2) return null;
    [$enc, $iv] = $parts;
    $val = openssl_decrypt($enc, TIME_OFF_MAIL_ENC_CIPHER, TIME_OFF_MAIL_ENC_KEY, 0, $iv);
    return $val !== false ? $val : null;
}

/**
 * Send a transactional email using mail_* settings from the settings table.
 * Returns a string status: 'sent', 'error:<reason>', or 'error:incomplete_settings'.
 * Never throws — callers must not depend on email succeeding.
 */
function sendTimeOffEmail(mysqli $conn, string $toAddress, string $subject, string $bodyHtml): string
{
    if ($toAddress === '') {
        return 'error:no_recipient';
    }

    $mailSettings = [];
    $result = $conn->query("SELECT SettingKey, SettingValue FROM settings WHERE SettingKey LIKE 'mail_%'");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $mailSettings[$row['SettingKey']] = $row['SettingValue'];
        }
    }

    $required = ['mail_server', 'mail_port', 'mail_username', 'mail_password',
                 'mail_from_address', 'mail_from_name', 'mail_encryption'];
    foreach ($required as $key) {
        if (empty($mailSettings[$key])) {
            error_log("time_off_email: mail settings incomplete (missing {$key}); email skipped.");
            return 'error:incomplete_settings';
        }
    }

    $decryptedPassword = timeOffDecryptMailPassword($mailSettings['mail_password']);
    if ($decryptedPassword === null) {
        error_log("time_off_email: mail_password decrypt failed; email skipped.");
        return 'error:password_decrypt_failed';
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $mailSettings['mail_server'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $mailSettings['mail_username'];
        $mail->Password   = $decryptedPassword;
        $mail->SMTPSecure = $mailSettings['mail_encryption'];
        $mail->Port       = (int) $mailSettings['mail_port'];

        $mail->setFrom($mailSettings['mail_from_address'], $mailSettings['mail_from_name']);
        $mail->addAddress($toAddress);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $bodyHtml;

        $mail->send();
        return 'sent';
    } catch (Exception $e) {
        error_log("time_off_email: send failed: " . $mail->ErrorInfo);
        return 'error:' . $mail->ErrorInfo;
    }
}
