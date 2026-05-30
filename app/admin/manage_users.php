<?php
// admin/manage_users.php
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

// Permission check
require_once __DIR__ . '/../functions/check_permission.php';
requirePermission('manage_users');

/*
 * Fetch data for page
 */
$users_result = $conn->query("
    SELECT ID, FirstName, LastName, BadgeID, PIN, ClockStatus, Office, TwoFAEnabled, LockOut
    FROM users
    ORDER BY LastName
");

$users_data = [];
while ($row = $users_result->fetch_assoc()) {
    $users_data[] = $row;
}

$offices_result = $conn->query("SELECT OfficeName FROM Offices ORDER BY OfficeName");
$offices_data = [];
while ($row = $offices_result->fetch_assoc()) {
    $offices_data[] = $row;
}
$pageTitle = "Manage Users";
$extraCSS = ["../css/manage_users.css"];
require_once 'header.php';
?>


<div class="uman-container">
    <div class="uman-header">
        <h2>User Management</h2>
        <div class="toolbar" style="margin-bottom:0;">
            <button class="btn primary" onclick="document.getElementById('addUserModal').style.display='block'">+ Add User</button>
            <a href="archived_users.php" class="btn secondary">View Archived Users</a>
            <?php
            require_once __DIR__ . '/../functions/settings_helper.php';
            if (getSettingValue('QuickBadgeEnabled', $conn) === '1'):
            ?>
            <a href="badges.php" target="_blank" class="btn secondary">🪪 Print Badges</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="panel">
        <h3>🔐 Two-Factor Backup Codes</h3>
        <p style="margin:0 0 0.85rem; color:var(--muted-text); font-size:0.9rem;">
            Generate one-time recovery codes an employee can use to sign in if they lose access to their authenticator app.
            For a single employee, use <strong>Actions → 2FA Options</strong> in the table below.
        </p>
        <form method="POST" action="generate_backup_codes.php">
            <input type="hidden" name="mode" value="all">
            <button type="submit" class="btn primary">Generate for All Users</button>
        </form>
    </div>

    <table class="uman-table">
        <thead>
        <tr>
            <th>Badge ID</th>
            <th>PIN</th>
            <th>Full Name</th>
            <th>Clock Status</th>
            <th>Office</th>
            <th>2FA</th>
            <th>
                <span class="tooltip">Lockout
                    <span class="tooltiptext">
                        Lock: User account is locked.<br>
                        Unlock: User account is unlocked.
                    </span>
                </span>
            </th>
            <th>Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($users_data as $user): ?>
            <tr>
                <td><?= htmlspecialchars($user['BadgeID'] ?? '') ?></td>
                <td><?= !empty($user['PIN']) ? '••••' : '' ?></td>
                <td><?= htmlspecialchars(($user['FirstName'] ?? '') . ' ' . ($user['LastName'] ?? '')) ?></td>
                <td><?= htmlspecialchars($user['ClockStatus'] ?? 'Out') ?></td>
                <td><?= htmlspecialchars($user['Office'] ?? 'N/A') ?></td>
                <td><?= !empty($user['TwoFAEnabled']) ? '✅ Enabled' : '❌ Disabled' ?></td>
                <td>
                    <form method="POST" action="update_2fa_status.php" style="display:inline;">
                        <input type="hidden" name="id" value="<?= (int)$user['ID'] ?>">
                        <?php if (!empty($user['LockOut'])): ?>
                            <input type="hidden" name="action" value="unlock">
                            <button type="submit" class="btn small primary">Unlock</button>
                        <?php else: ?>
                            <input type="hidden" name="action" value="lock">
                            <button type="submit" class="btn small warning">Lock</button>
                        <?php endif; ?>
                    </form>
                </td>
                <td>
                    <div class="actions-menu">
                        <button class="btn small primary" onclick="toggleActionsMenu(this)">Actions ▾</button>
                        <div class="actions-menu-content">
                            <button class="btn" onclick="location.href='edit_user.php?id=<?= (int)$user['ID'] ?>'">Edit</button>
                            <button class="btn" onclick="showResetModal(<?= (int)$user['ID'] ?>)">Reset</button>
                            <button class="btn" onclick="open2FAModal(<?= (int)$user['ID'] ?>)">2FA Options</button>
                            <button class="btn" onclick="showArchiveModal(<?= (int)$user['ID'] ?>)">Archive</button>
                        </div>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Add User Modal -->
<div id="addUserModal" class="modal">
    <div class="modal-content">
        <form action="add_user.php" method="POST">
            <h3>Add New User</h3>
            <input type="text" name="FirstName" placeholder="First Name" required>
            <input type="text" name="LastName" placeholder="Last Name" required>
            <input type="text" name="BadgeID" placeholder="Badge ID (optional)">
            <input type="text" name="PIN" placeholder="PIN — 4-6 digits (optional)" inputmode="numeric" pattern="\d{4,6}" maxlength="6">
            <select name="Office" required>
                <option value="">Select Office</option>
                <?php foreach ($offices_data as $office): ?>
                    <option value="<?= htmlspecialchars($office['OfficeName']) ?>"><?= htmlspecialchars($office['OfficeName']) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="password" name="Password" placeholder="Initial Password" required>
            <div class="modal-actions">
                <button type="button" class="btn" onclick="document.getElementById('addUserModal').style.display='none'">Cancel</button>
                <button type="submit" class="btn primary">Add User</button>
            </div>
        </form>
    </div>
</div>

<!-- Reset Password Modal -->
<div id="resetModal" class="modal">
    <div class="modal-content">
        <h3>Reset Password</h3>
        <p>This will replace the user's password with a one-time temporary password, clear any login lockout, and show the temp password on the next screen so you can read it off to the employee. They should change it in <strong>User Settings</strong> after logging in.</p>
        <div class="modal-actions">
            <button class="btn" onclick="closeResetModal()">Cancel</button>
            <button class="btn primary" id="confirmResetBtn">Reset &amp; Show Temp Password</button>
        </div>
    </div>
</div>

<!-- 2FA Modal -->
<div id="modal2FA" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <span class="close-btn" onclick="close2FAModal()">&times;</span>
        <h3 style="text-align: center; margin-bottom: 0.5rem;">🔐 2FA Management</h3>
        <p style="text-align: center; font-size: 0.95rem; color: #444;">
            When enabled, this user must enter a one-time code emailed to them each time they sign in.
            Requires an email address on file.
        </p>

        <form id="form2FA" method="POST" action="update_2fa_status.php">
            <input type="hidden" name="id" id="2faUserId">

            <div class="modal-actions" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.75rem; margin-top: 1.5rem;">
                <button type="button" class="btn small primary" onclick="confirm2FA('enable')">✅ Require Email 2FA</button>
                <button type="button" class="btn small danger" onclick="confirm2FA('disable')">❌ Turn Off 2FA</button>
            </div>
        </form>

        <hr style="margin:1.25rem 0;">
        <p style="text-align:center; font-size:0.9rem; color:#444; margin-bottom:0.75rem;">
            Backup codes let this user sign in if they lose their authenticator.
        </p>
        <form id="form2FACodes" method="POST" action="generate_backup_codes.php" style="text-align:center;">
            <input type="hidden" name="mode" value="single">
            <input type="hidden" name="userID" id="2faCodesUserId">
            <button type="submit" class="btn small secondary" onclick="return confirm('Generate new backup codes for this user? Any existing codes will be replaced.');">🔑 Generate Backup Codes</button>
        </form>
    </div>
</div>

<script src="../js/manage_users.js?v=1.4"></script>

<!-- Archive User Modal -->
<div id="archiveModal" class="modal">
    <div class="modal-content">
        <h3>Archive User</h3>
        <p>Are you sure you want to archive this user? This will remove them from the active user list, but their data will be kept in the archive.</p>
        <div class="modal-actions">
            <button class="btn" onclick="closeArchiveModal()">Cancel</button>
            <form id="archiveForm" action="archive_user.php" method="POST" style="display:inline;">
                <input type="hidden" name="ID" id="archiveUserId">
                <button type="submit" class="btn primary">Yes, Archive</button>
            </form>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
