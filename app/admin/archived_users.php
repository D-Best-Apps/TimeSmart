<?php
// admin/archived_users.php
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

// Permission check
require_once __DIR__ . '/../functions/check_permission.php';
requirePermission('manage_users');
require_once __DIR__ . '/../functions/csrf.php';

$archived_users_data = $conn->query("
    SELECT u.ID, u.FirstName, u.LastName, u.Email, u.Office, u.ArchivedAt,
           (SELECT MAX(tp.Date) FROM timepunches tp WHERE tp.EmployeeID = u.ID) AS LastPunch
      FROM users u
     WHERE u.ArchivedAt IS NOT NULL
     ORDER BY u.ArchivedAt DESC, u.LastName, u.FirstName
")->fetch_all(MYSQLI_ASSOC);
$msg = isset($_GET['msg']) ? (string) $_GET['msg'] : '';
$pageTitle = "Archived Users";
require_once 'header.php';
?>


<div class="uman-container">
    <div class="uman-header">
        <h2>Archived Users</h2>
        <div class="toolbar" style="margin-bottom:0;">
            <a href="manage_users.php" class="btn secondary">Back to Users</a>
        </div>
    </div>

    <?php if ($msg !== ''): ?>
        <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <p style="color:var(--muted-text); font-size:0.9rem;">
        Archived employees can't sign in or punch and are left out of active lists. Their punches,
        time off and logs are kept, so reports and payroll for dates they worked still include them.
        Unarchive brings them back as they were.
    </p>

    <table class="uman-table">
        <thead>
        <tr>
            <th>Full Name</th>
            <th>Email</th>
            <th>Office</th>
            <th>Last Punch</th>
            <th>Archived At</th>
            <th>Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if (!$archived_users_data): ?>
            <tr><td colspan="6" style="text-align:center;">No archived users.</td></tr>
        <?php endif; ?>
        <?php foreach ($archived_users_data as $user): ?>
            <tr>
                <td><?= htmlspecialchars($user['FirstName'] . ' ' . $user['LastName']) ?></td>
                <td><?= htmlspecialchars($user['Email'] ?? '') ?></td>
                <td><?= htmlspecialchars($user['Office'] ?? '') ?></td>
                <td><?= htmlspecialchars($user['LastPunch'] ?? '—') ?></td>
                <td><?= htmlspecialchars($user['ArchivedAt']) ?></td>
                <td>
                    <form action="unarchive_user.php" method="POST" style="display:inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="ID" value="<?= (int)$user['ID'] ?>">
                        <button type="submit" class="btn primary small">Unarchive</button>
                    </form>
                    <a href="permanently_delete_user.php?ID=<?= (int)$user['ID'] ?>" class="btn danger small">Delete Permanently…</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>


<?php require_once 'footer.php'; ?>
