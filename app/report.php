<?php
require_once __DIR__ . '/auth/db.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/functions/settings_helper.php';
date_default_timezone_set('America/Chicago');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Mirror the app's mail-password encryption so we can use the configured SMTP creds.
define('RPT_CIPHER', 'aes-256-cbc');
define('RPT_KEY', 'a_very_secret_key_for_encryption_32_chars');
function rpt_decrypt($data) {
    $parts = explode('::', base64_decode((string) $data), 2);
    if (count($parts) === 2) {
        return openssl_decrypt($parts[0], RPT_CIPHER, RPT_KEY, 0, $parts[1]);
    }
    return false;
}

$sent = false;
$error = '';
$name = $fromEmail = $subjectIn = $messageIn = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name      = trim($_POST['name'] ?? '');
    $fromEmail = trim($_POST['email'] ?? '');
    $subjectIn = trim($_POST['subject'] ?? '');
    $messageIn = trim($_POST['message'] ?? '');

    if ($name === '' || $messageIn === '') {
        $error = 'Please enter your name and a description of the issue.';
    } elseif ($fromEmail !== '' && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'That email address doesn\'t look valid.';
    } else {
        $adminAddr = getSettingValue('mail_admin_address', $conn);
        $server    = getSettingValue('mail_server', $conn);
        $username  = getSettingValue('mail_username', $conn);
        $passEnc   = getSettingValue('mail_password', $conn);
        $encryption = getSettingValue('mail_encryption', $conn);
        $port      = getSettingValue('mail_port', $conn);
        $fromAddr  = getSettingValue('mail_from_address', $conn);
        $fromName  = getSettingValue('mail_from_name', $conn) ?: 'D-Best TimeSmart';

        if (empty($adminAddr) || empty($server) || empty($fromAddr)) {
            $error = 'Issue reporting isn\'t configured yet. Please contact your administrator directly.';
        } else {
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = $server;
                $mail->SMTPAuth   = true;
                $mail->Username   = $username;
                $mail->Password   = rpt_decrypt($passEnc);
                $mail->SMTPSecure = $encryption;
                $mail->Port       = $port;

                $mail->setFrom($fromAddr, $fromName);
                $mail->addAddress($adminAddr);
                if ($fromEmail !== '') {
                    $mail->addReplyTo($fromEmail, $name);
                }

                $safeName = htmlspecialchars($name);
                $safeFrom = htmlspecialchars($fromEmail !== '' ? $fromEmail : 'not provided');
                $safeMsg  = nl2br(htmlspecialchars($messageIn));

                $mail->isHTML(true);
                $mail->Subject = 'TimeSmart Issue Report' . ($subjectIn !== '' ? ': ' . $subjectIn : '');
                $mail->Body = "<h2>Issue reported via TimeSmart</h2>"
                    . "<p><strong>From:</strong> {$safeName} ({$safeFrom})</p>"
                    . "<p><strong>Submitted:</strong> " . date('M j, Y g:i A') . "</p>"
                    . "<hr><p>{$safeMsg}</p>";
                $mail->AltBody = "Issue reported by {$name} ({$fromEmail})\n\n{$messageIn}";

                $mail->send();
                $sent = true;
                $name = $fromEmail = $subjectIn = $messageIn = '';
            } catch (Exception $e) {
                $error = 'Sorry — your report couldn\'t be sent right now. Please try again later.';
                error_log('Report-issue email failed: ' . $mail->ErrorInfo);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Report Issues - D-Best TimeClock</title>
  <link rel="stylesheet" href="css/style.css?v=10">
  <link rel="icon" type="image/png" href="/images/D-Best.png">
  <link rel="apple-touch-icon" href="/images/D-Best.png">
  <link rel="icon" type="image/png" href="images/D-Best-favicon.png">
  <link rel="apple-touch-icon" href="images/D-Best-favicon.png">
  <link rel="manifest" href="/manifest.json">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#126ab3">
</head>
<body>

<?php include __DIR__ . '/partials/public_header.php'; ?>

<div class="wrapper">
  <main class="main card" style="max-width: 640px; margin: auto;">
    <h1>Report an Issue</h1>

    <?php if ($sent): ?>
      <p style="background:#d4edda; color:#1d7a36; padding:1rem; border-radius:8px;">
        ✅ Thanks — your report was sent to the administrator. We'll take a look.
      </p>
      <p><a href="index.php">← Back to home</a></p>
    <?php else: ?>
      <p style="color:#555;">Spotted a problem or have a question? Send it straight to the administrator.</p>
      <?php if ($error): ?>
        <p style="background:#f8d7da; color:#c0392b; padding:0.8rem 1rem; border-radius:8px;"><?= htmlspecialchars($error) ?></p>
      <?php endif; ?>

      <form method="POST" class="report-form" style="display:flex; flex-direction:column; gap:1rem; margin-top:1rem;">
        <label>Your Name *
          <input type="text" name="name" required value="<?= htmlspecialchars($name) ?>"
                 style="width:100%; padding:0.7rem; border:1px solid #ccc; border-radius:6px;">
        </label>
        <label>Your Email <small>(optional, so we can reply)</small>
          <input type="email" name="email" value="<?= htmlspecialchars($fromEmail) ?>"
                 style="width:100%; padding:0.7rem; border:1px solid #ccc; border-radius:6px;">
        </label>
        <label>Subject <small>(optional)</small>
          <input type="text" name="subject" value="<?= htmlspecialchars($subjectIn) ?>"
                 style="width:100%; padding:0.7rem; border:1px solid #ccc; border-radius:6px;">
        </label>
        <label>What's going on? *
          <textarea name="message" required rows="6"
                    style="width:100%; padding:0.7rem; border:1px solid #ccc; border-radius:6px; resize:vertical;"><?= htmlspecialchars($messageIn) ?></textarea>
        </label>
        <div>
          <button type="submit" style="padding:0.7rem 1.6rem; background:#0078D7; color:#fff; border:none; border-radius:8px; font-size:1rem; cursor:pointer;">Send Report</button>
        </div>
      </form>
    <?php endif; ?>
  </main>
</div>

<footer>
  <p>D-Best TimeSmart &copy; <?= date('Y') ?>. All rights reserved.</p>
  <p style="margin-top: 0.3rem;">
    <a href="/privacy.php" style="color:#e0e0e0; text-decoration:none; margin-right:15px;">Privacy Policy</a>
    <a href="/terms.php" style="color:#e0e0e0; text-decoration:none;">Terms of Use</a>
    <a href="/report.php" style="color:#e0e0e0; text-decoration:none;">Report Issues</a>
  </p>
</footer>

<script src="js/script.js"></script>
</body>
</html>
