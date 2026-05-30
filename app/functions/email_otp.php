<?php
// functions/email_otp.php
//
// Email-based two-factor login codes. When users.TwoFAEnabled = 1, login sends
// a short-lived 6-digit code to the user's email; they enter it on verify_2fa.php.
// Codes are stored hashed (password_hash) with an expiry and cleared on success.

require_once __DIR__ . '/time_off_email.php'; // sendTimeOffEmail() — generic transactional mailer

if (!defined('EMAIL_OTP_TTL_SECONDS')) {
    define('EMAIL_OTP_TTL_SECONDS', 600); // 10 minutes
}

/**
 * Generate a fresh OTP, store its hash + expiry on the user, and email it.
 *
 * @return bool true if the email was accepted by the mailer, false otherwise.
 *              (The code is stored regardless so a later resend/verify still works
 *              if the mail transport hiccups.)
 */
function sendEmailOtp(mysqli $conn, int $userId, string $email, string $firstName): bool {
    $code    = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $hash    = password_hash($code, PASSWORD_DEFAULT);
    $expires = date('Y-m-d H:i:s', time() + EMAIL_OTP_TTL_SECONDS);

    $stmt = $conn->prepare("UPDATE users SET EmailOTPHash = ?, EmailOTPExpires = ? WHERE ID = ?");
    $stmt->bind_param("ssi", $hash, $expires, $userId);
    $stmt->execute();
    $stmt->close();

    $mins = (int) round(EMAIL_OTP_TTL_SECONDS / 60);
    $safeName = htmlspecialchars($firstName !== '' ? $firstName : 'there', ENT_QUOTES);
    $subject = "Your TimeSmart login code: {$code}";
    $body  = "<p>Hi {$safeName},</p>";
    $body .= "<p>Your one-time login code is:</p>";
    $body .= "<p style=\"font-size:1.6rem; font-weight:bold; letter-spacing:3px;\">{$code}</p>";
    $body .= "<p>It expires in {$mins} minutes. If you didn't try to sign in, you can ignore this email.</p>";

    $status = sendTimeOffEmail($conn, $email, $subject, $body);
    if ($status !== 'sent') {
        error_log("email_otp: send to user {$userId} returned '{$status}'");
        return false;
    }
    return true;
}

/**
 * Verify a submitted code against the stored hash and expiry.
 * On success the stored OTP is cleared so it can't be reused.
 */
function verifyEmailOtp(mysqli $conn, int $userId, string $submitted): bool {
    $submitted = preg_replace('/\D+/', '', $submitted);
    if ($submitted === '') {
        return false;
    }

    $stmt = $conn->prepare("SELECT EmailOTPHash, EmailOTPExpires FROM users WHERE ID = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || empty($row['EmailOTPHash']) || empty($row['EmailOTPExpires'])) {
        return false;
    }
    if (strtotime($row['EmailOTPExpires']) < time()) {
        return false; // expired
    }
    if (!password_verify($submitted, $row['EmailOTPHash'])) {
        return false;
    }

    clearEmailOtp($conn, $userId);
    return true;
}

/** Clear any pending OTP for a user. */
function clearEmailOtp(mysqli $conn, int $userId): void {
    $stmt = $conn->prepare("UPDATE users SET EmailOTPHash = NULL, EmailOTPExpires = NULL WHERE ID = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->close();
}
