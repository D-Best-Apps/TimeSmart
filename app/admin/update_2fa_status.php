<?php
// admin/update_2fa_status.php
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    http_response_code(403);
    exit('Forbidden');
}
require_once __DIR__ . '/../functions/archived_users.php';
endSessionIfArchived($conn); // archived since signing in: sign out

// Lock/unlock and 2FA changes are user management: reports_only admins can't do them.
require_once __DIR__ . '/../functions/check_permission.php';
requirePermission('manage_users');

require_once __DIR__ . '/../functions/csrf.php';
require_csrf(csrf_error_url());

$id     = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$action = $_POST['action'] ?? '';

if ($id <= 0) {
    http_response_code(400);
    exit('Invalid user id');
}

switch ($action) {
    case 'enable':
        $stmt = $conn->prepare("UPDATE users SET TwoFAEnabled = 1 WHERE ID = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        break;

    case 'disable':
        // Disable 2FA and clear secrets/recovery codes
        $stmt = $conn->prepare("UPDATE users SET TwoFAEnabled = 0, TwoFASecret = NULL, TwoFARecoveryCode = NULL WHERE ID = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        break;

    case 'lock':
        // Lock the user from managing their own 2FA
        $stmt = $conn->prepare("UPDATE users SET LockOut = 1 WHERE ID = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        break;

    case 'unlock':
        // Allow the user to manage their own 2FA
        $stmt = $conn->prepare("UPDATE users SET LockOut = 0 WHERE ID = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        break;

    default:
        http_response_code(400);
        exit('Unknown action');
}

header('Location: manage_users.php');
