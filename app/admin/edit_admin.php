<?php
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: ../user/login.php?admin=1");
    exit;
}

require_once __DIR__ . '/../functions/check_permission.php';
requirePermission('manage_admins');

$id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id === 0) {
    header("Location: manage_admins.php");
    exit;
}

$error = "";
$success = "";

// Load user
$stmt = $conn->prepare("SELECT ID, FirstName, LastName, Email, Role FROM users WHERE ID = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
if (!$user) {
    $error = "User not found.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user) {
    $role = $_POST['role'] ?? 'super_admin';
    if (!in_array($role, ['super_admin','reports_only','employee'], true)) {
        $role = 'super_admin';
    }
    $upd = $conn->prepare("UPDATE users SET Role = ? WHERE ID = ?");
    $upd->bind_param("si", $role, $id);
    if ($upd->execute()) {
        $success = "Role updated.";
        $user['Role'] = $role;
    } else {
        $error = "Failed to update role.";
    }
}

$pageTitle = "Change Role: " . ($user ? $user['FirstName'] . ' ' . $user['LastName'] : '');
require_once 'header.php';
?>
<link rel="stylesheet" href="../css/edit_admin.css" />

<div class="container">
    <?php if ($error): ?>
        <p style="color:#b02a37; margin-bottom:1rem;"><?= htmlspecialchars($error) ?></p>
    <?php elseif ($success): ?>
        <p style="color:#1e7e34; margin-bottom:1rem;"><?= htmlspecialchars($success) ?></p>
    <?php endif; ?>

    <?php if ($user): ?>
        <h2><?= htmlspecialchars($user['FirstName'] . ' ' . $user['LastName']) ?></h2>
        <p style="color:#555;">Email: <?= htmlspecialchars($user['Email'] ?? '') ?: '—' ?></p>
        <p style="color:#555; font-size:0.9em;">
            Password changes happen on the employee's own settings page. To reset their password,
            use the Users page.
        </p>

        <form method="POST" class="summary-filter">
            <div class="row">
                <div class="field">
                    <label for="role">Role</label>
                    <select name="role" id="role" required>
                        <option value="super_admin"  <?= $user['Role'] === 'super_admin'  ? 'selected' : '' ?>>Super Admin (Full Access)</option>
                        <option value="reports_only" <?= $user['Role'] === 'reports_only' ? 'selected' : '' ?>>Reports Only (View &amp; Export Reports)</option>
                        <option value="employee"     <?= $user['Role'] === 'employee'     ? 'selected' : '' ?>>Employee (no admin access)</option>
                    </select>
                </div>
            </div>

            <div class="buttons">
                <button type="submit">Save Role</button>
                <a href="manage_admins.php" class="btn-reset">Cancel</a>
            </div>
        </form>
    <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>
