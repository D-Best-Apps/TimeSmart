<?php
// Shared PHPMailer wrapper for the time-off request workflow.
// Used by app/user/submit_time_off.php (notify admin on submit) and
// app/admin/process_time_off.php (notify employee on decision).

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

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

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $mailSettings['mail_server'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $mailSettings['mail_username'];
        $mail->Password   = $mailSettings['mail_password'];
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
