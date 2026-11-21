<?php
/**
 * Create the first super admin on a fresh install (install.sh runs it).
 *
 * The details come from environment variables rather than arguments, so the
 * password never shows up in the process list or shell history:
 *
 *   ADMIN_FIRST, ADMIN_LAST, ADMIN_EMAIL, ADMIN_PASS   (plus the usual DB_*)
 *
 *   php deploy/scripts/create_admin.php
 *
 * Refuses to run when a super admin already exists; later admins are promoted
 * on the admin portal's Manage Admins page.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../app/auth/db.php';

$first = trim((string) getenv('ADMIN_FIRST'));
$last  = trim((string) getenv('ADMIN_LAST'));
$email = trim((string) getenv('ADMIN_EMAIL'));
$pass  = (string) getenv('ADMIN_PASS');

if ($first === '' || $last === '' || $pass === '') {
    fwrite(STDERR, "✗ ADMIN_FIRST, ADMIN_LAST and ADMIN_PASS are required.\n");
    exit(1);
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "✗ ADMIN_EMAIL is not a valid address.\n");
    exit(1);
}

$admins = (int) $conn->query("SELECT COUNT(*) FROM users WHERE Role = 'super_admin' AND ArchivedAt IS NULL")->fetch_column();
if ($admins > 0) {
    fwrite(STDERR, "✗ A super admin already exists; promote others on Manage Admins.\n");
    exit(1);
}

$hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
$emailOrNull = $email === '' ? null : $email;
$st = $conn->prepare("INSERT INTO users (FirstName, LastName, Email, Pass, ClockStatus, Role, TwoFAEnabled, AdminOverride2FA)
                      VALUES (?, ?, ?, ?, 'Out', 'super_admin', 0, 1)");
$st->bind_param("ssss", $first, $last, $emailOrNull, $hash);
$st->execute();

echo "✓ Super admin created: {$first} {$last} (sign in with that full name)\n";
