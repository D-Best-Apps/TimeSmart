<?php
/**
 * Reset a user's password. Generates a random temporary password, stores its
 * bcrypt hash on users.Pass, clears the LockOut flag, and returns the plaintext
 * temp password in the JSON response so the admin can read it off and tell
 * the employee. The employee should change it themselves via User Settings
 * after they log in.
 *
 * Called from app/js/manage_users.js (the Reset Password modal).
 */
session_start();
require '../auth/db.php';
require_once __DIR__ . '/../functions/check_permission.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requirePermission('manage_users');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
if ($id === 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing user id']);
    exit;
}

// Generate a memorable but unguessable temp password
$tempPassword = 'TimeSmart' . random_int(1000, 9999);
$hash = password_hash($tempPassword, PASSWORD_BCRYPT, ['cost' => 12]);

$stmt = $conn->prepare("UPDATE users SET Pass = ?, LockOut = 0 WHERE ID = ?");
$stmt->bind_param("si", $hash, $id);
if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
    exit;
}

if ($stmt->affected_rows === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'User not found']);
    exit;
}

echo json_encode([
    'success'       => true,
    'temp_password' => $tempPassword,
    'message'       => 'Password reset. Tell the employee to use this temporary password and change it in their User Settings.',
]);
