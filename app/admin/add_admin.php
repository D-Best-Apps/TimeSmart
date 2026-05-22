<?php
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: ../user/login.php?admin=1");
    exit;
}

require_once __DIR__ . '/../functions/check_permission.php';
requirePermission('manage_admins');

$error = "";
$success = "";

// Handle promotion form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userID = (int) ($_POST['user_id'] ?? 0);
    $role   = $_POST['role'] ?? 'super_admin';

    if (!in_array($role, ['super_admin', 'reports_only'], true)) {
        $role = 'super_admin';
    }

    if ($userID === 0) {
        $error = "Pick an employee to promote.";
    } else {
        // Verify user exists and is currently an employee
        $stmt = $conn->prepare("SELECT FirstName, LastName, Role FROM users WHERE ID = ? LIMIT 1");
        $stmt->bind_param("i", $userID);
        $stmt->execute();
        $u = $stmt->get_result()->fetch_assoc();
        if (!$u) {
            $error = "User not found.";
        } else {
            $upd = $conn->prepare("UPDATE users SET Role = ? WHERE ID = ?");
            $upd->bind_param("si", $role, $userID);
            $upd->execute();
            $success = htmlspecialchars($u['FirstName'] . ' ' . $u['LastName']) .
                       " is now " . ($role === 'super_admin' ? 'Super Admin' : 'Reports Only') . ".";
        }
    }
}

// Load the employee list for the picker (only Role = employee — admins already exist)
$employees = $conn->query("
    SELECT ID, FirstName, LastName, Email
      FROM users
     WHERE Role = 'employee'
     ORDER BY LastName, FirstName
")->fetch_all(MYSQLI_ASSOC);

$pageTitle = "Promote Employee to Admin";
require_once 'header.php';
?>
<link rel="stylesheet" href="../css/manage_admins.css" />

<div class="container">
    <h2>Promote Employee to Admin</h2>
    <p style="color:#555; font-size:0.9em;">
        Pick an existing employee and grant them an admin role. They'll continue to log in as themselves
        (full name + their current password); no second account is created.
    </p>

    <?php if ($error): ?>
        <p style="color:#b02a37; margin-bottom:1rem;"><?= htmlspecialchars($error) ?></p>
    <?php elseif ($success): ?>
        <p style="color:#1e7e34; margin-bottom:1rem;"><?= $success ?></p>
    <?php endif; ?>

    <form method="POST" class="summary-filter">
        <div class="row">
            <div class="field">
                <label for="user_id">Employee</label>
                <select name="user_id" id="user_id" required>
                    <option value="">— Pick an employee —</option>
                    <?php foreach ($employees as $emp): ?>
                        <option value="<?= (int) $emp['ID'] ?>">
                            <?= htmlspecialchars($emp['FirstName'] . ' ' . $emp['LastName']) ?>
                            <?= $emp['Email'] ? '— ' . htmlspecialchars($emp['Email']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="role">Role</label>
                <select name="role" id="role" required>
                    <option value="super_admin">Super Admin (Full Access)</option>
                    <option value="reports_only">Reports Only (View &amp; Export Reports)</option>
                </select>
            </div>
        </div>

        <div class="buttons">
            <button type="submit">Promote</button>
            <a href="manage_admins.php" class="btn-reset">Cancel</a>
        </div>
    </form>
</div>

<?php require_once 'footer.php'; ?>
