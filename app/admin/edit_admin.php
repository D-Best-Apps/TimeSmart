<?php
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

// Permission check
require_once __DIR__ . '/../functions/check_permission.php';
requirePermission('manage_admins');

$id = $_GET['id'] ?? null;
if (!$id || !is_numeric($id)) {
    header("Location: manage_admins.php");
    exit;
}

$error = "";
$success = "";

// Fetch admin info
$stmt = $conn->prepare("SELECT * FROM admins WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$admin = $result->fetch_assoc();

if (!$admin) {
    $error = "Admin not found.";
}

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $admin) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $role = $_POST['role'] ?? 'super_admin';

    // Validate role
    if (!in_array($role, ['super_admin', 'reports_only'])) {
        $role = 'super_admin';
    }

    if (empty($username)) {
        $error = "Username cannot be empty.";
    } else {
        if (!empty($password)) {
            $hashed = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $update = $conn->prepare("UPDATE admins SET username = ?, password = ?, role = ? WHERE id = ?");
            $update->bind_param("sssi", $username, $hashed, $role, $id);
        } else {
            $update = $conn->prepare("UPDATE admins SET username = ?, role = ? WHERE id = ?");
            $update->bind_param("ssi", $username, $role, $id);
        }

        if ($update->execute()) {
            $success = "Admin updated successfully.";
            // Refresh the admin data
            $stmt = $conn->prepare("SELECT * FROM admins WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            $admin = $result->fetch_assoc();
        } else {
            $error = "Failed to update admin.";
        }
    }
}
$pageTitle = "Edit Admin";
require_once 'header.php';
?>
<link rel="stylesheet" href="../css/edit_admin.css" />


    <div class="container">
        <?php if ($error): ?>
            <p style="color: red; margin-bottom: 1rem;"><?= htmlspecialchars($error) ?></p>
        <?php elseif ($success): ?>
            <p style="color: green; margin-bottom: 1rem;"><?= htmlspecialchars($success) ?></p>
        <?php endif; ?>

        <?php if ($admin): ?>
            <form method="POST" class="summary-filter">
                <div class="row">
                    <div class="field">
                        <label for="username">Username</label>
                        <input type="text" name="username" id="username" value="<?= htmlspecialchars($admin['username']) ?>" required>
                    </div>

                    <div class="field">
                        <label for="password">New Password <span style="font-weight: normal; color: #999;">(leave blank to keep current)</span></label>
                        <input type="text" name="password" id="password">
                    </div>

                    <div class="field">
                        <label for="role">Role</label>
                        <select name="role" id="role" required>
                            <option value="super_admin" <?= $admin['role'] === 'super_admin' ? 'selected' : '' ?>>Super Admin (Full Access)</option>
                            <option value="reports_only" <?= $admin['role'] === 'reports_only' ? 'selected' : '' ?>>Reports Only (View & Export Reports)</option>
                        </select>
                    </div>
                </div>

                <div class="buttons">
                    <button type="submit">Save Changes</button>
                    <a href="manage_admins.php" class="btn-reset">Cancel</a>
                </div>
            </form>
        <?php endif; ?>
    </div>

<?php require_once 'footer.php'; ?>
