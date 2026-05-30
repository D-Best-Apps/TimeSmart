<?php
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: ../user/login.php?admin=1");
    exit;
}

require_once __DIR__ . '/../functions/check_permission.php';
requirePermission('manage_admins');

// Demote (Role → employee)
if (isset($_GET['demote'])) {
    $id = (int) $_GET['demote'];
    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE users SET Role = 'employee' WHERE ID = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
    }
    header("Location: manage_admins.php?status=demoted");
    exit;
}

// Promote / change role (POST from edit_admin.php)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_role'])) {
    $id   = (int) ($_POST['user_id'] ?? 0);
    $role = $_POST['role'] ?? '';
    if ($id > 0 && in_array($role, ['employee','reports_only','super_admin'], true)) {
        $stmt = $conn->prepare("UPDATE users SET Role = ? WHERE ID = ?");
        $stmt->bind_param("si", $role, $id);
        $stmt->execute();
    }
    header("Location: manage_admins.php?status=updated");
    exit;
}

// Fetch all users that currently have an admin role
$rows = [];
$result = $conn->query("
    SELECT ID, FirstName, LastName, Role
      FROM users
     WHERE Role <> 'employee'
     ORDER BY LastName, FirstName
");
if ($result) $rows = $result->fetch_all(MYSQLI_ASSOC);

$pageTitle = "Manage Admins";
require_once 'header.php';
?>

<div class="container">
    <div class="summary-filter">
        <div class="row">
            <div class="field">
                <h2 style="margin-bottom: 0.5rem;">Admin Accounts</h2>
                <p style="color:#555; font-size:0.9em; margin-top:0;">
                    Admins log in via the same form as employees (full name + their password). Promote any employee
                    to <strong>super_admin</strong> or <strong>reports_only</strong> here. Demoting sets their role
                    back to employee — their punch/timesheet access is unchanged.
                </p>
            </div>
            <div class="buttons">
                <a href="add_admin.php" class="btn primary">+ Promote Employee</a>
            </div>
        </div>
    </div>

    <?php if (($_GET['status'] ?? '') === 'updated'): ?>
        <div class="alert alert-success" style="margin-bottom:1rem;">Role updated.</div>
    <?php elseif (($_GET['status'] ?? '') === 'demoted'): ?>
        <div class="alert alert-info" style="margin-bottom:1rem;">User demoted to employee.</div>
    <?php endif; ?>

    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Role</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($rows) === 0): ?>
                <tr><td colspan="3">No admin users yet.</td></tr>
            <?php else: foreach ($rows as $u): ?>
                <?php
                  $roleDisplay = $u['Role'] === 'super_admin' ? 'Super Admin' : 'Reports Only';
                  $roleClass   = $u['Role'] === 'super_admin' ? 'role-badge role-super' : 'role-badge role-reports';
                ?>
                <tr>
                    <td><?= htmlspecialchars($u['FirstName'] . ' ' . $u['LastName']) ?></td>
                    <td><span class="<?= $roleClass ?>"><?= htmlspecialchars($roleDisplay) ?></span></td>
                    <td style="white-space: nowrap;">
                        <div style="display: inline-flex; gap: 0.5rem; align-items: center;">
                            <a class="btn primary small" href="edit_admin.php?id=<?= (int) $u['ID'] ?>">Change Role</a>
                            <a class="btn danger small" href="?demote=<?= (int) $u['ID'] ?>" onclick="return confirm('Demote <?= htmlspecialchars($u['FirstName'] . ' ' . $u['LastName'], ENT_QUOTES) ?> back to regular employee? (Their employee account stays.)');">Demote</a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?php require_once 'footer.php'; ?>
