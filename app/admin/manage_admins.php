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

// Handle deletion
if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM admins WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    header("Location: manage_admins.php");
    exit;
}

// Fetch all admins
$admins = [];
$result = $conn->query("SELECT id, username, role FROM admins ORDER BY id");
if ($result) {
    $admins = $result->fetch_all(MYSQLI_ASSOC);
}
$pageTitle = "Manage Admins";
require_once 'header.php';
?>
<link rel="stylesheet" href="../css/manage_admins.css" />


    <div class="container">
        <div class="summary-filter">
            <div class="row">
                <div class="field">
                    <h2 style="margin-bottom: 1rem;">Admin Accounts</h2>
                </div>
                <div class="buttons">
                    <a href="add_admin.php" class="btn-reset" style="background-color: var(--primary-color); color: white;">+ Add Admin</a>
                </div>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($admins as $admin): ?>
                    <tr>
                        <td><?= htmlspecialchars($admin['id']) ?></td>
                        <td><?= htmlspecialchars($admin['username']) ?></td>
                        <td>
                            <?php
                            $roleDisplay = $admin['role'] === 'super_admin' ? 'Super Admin' : 'Reports Only';
                            $roleClass = $admin['role'] === 'super_admin' ? 'role-badge role-super' : 'role-badge role-reports';
                            ?>
                            <span class="<?= $roleClass ?>"><?= htmlspecialchars($roleDisplay) ?></span>
                        </td>
                        <td>
                            <a class="btn-reset" style="background-color: var(--primary-color); color: white; margin-right: 0.5rem;" href="edit_admin.php?id=<?= $admin['id'] ?>">Edit</a>
                            <a class="btn-reset" style="background-color: #dc3545; color: white;" href="?delete=<?= $admin['id'] ?>" onclick="return confirm('Are you sure you want to delete this admin?');">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php require_once 'footer.php'; ?>
