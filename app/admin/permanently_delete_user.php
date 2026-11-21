<?php
// admin/permanently_delete_user.php
// Permanently deletes an ARCHIVED employee and every record of theirs: punches, time off,
// holiday-pay adjustments, edit requests, punch history and sign-in log. GET shows what
// will go and asks the admin to type the name; POST does it. Archive is the normal way
// to remove someone who has left (it keeps their hours); this is for mistakes and
// test accounts. Totals saved when payroll was closed (payroll_close_lines) are kept.
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
require_once __DIR__ . '/../functions/pay_rules.php'; // payrollLockedThrough()

$id = (int) ($_POST['ID'] ?? $_GET['ID'] ?? 0);
$st = $conn->prepare("SELECT ID, FirstName, LastName, ArchivedAt FROM users WHERE ID = ? AND ArchivedAt IS NOT NULL");
$st->bind_param("i", $id);
$st->execute();
$user = $st->get_result()->fetch_assoc();
$st->close();
if (!$user) {
    // Only archived employees can be deleted: archive first, from Manage Users.
    header("Location: archived_users.php");
    exit;
}
$fullName = trim($user['FirstName'] . ' ' . $user['LastName']);

/** How many rows of each kind belong to the employee. */
$count = function (string $sql) use ($conn, $id): int {
    $st = $conn->prepare($sql);
    $st->bind_param("i", $id);
    $st->execute();
    $n = (int) $st->get_result()->fetch_row()[0];
    $st->close();
    return $n;
};

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf('archived_users.php?msg=' . urlencode('The page had been open too long. Nothing was deleted; please try again.'));
    $typed = preg_replace('/\s+/', ' ', trim((string) ($_POST['ConfirmName'] ?? '')));
    if (strcasecmp($typed, $fullName) !== 0) {
        $error = 'The name you typed doesn\'t match. Nothing was deleted.';
    } else {
        $conn->begin_transaction();
        try {
            foreach ([
                "DELETE FROM pending_edits WHERE EmployeeID = ?",
                "DELETE FROM punch_changelog WHERE EmployeeID = ?",
                "DELETE FROM login_logs WHERE EmployeeID = ?",
                "DELETE FROM timepunches WHERE EmployeeID = ?",
                "DELETE FROM time_off_requests WHERE EmployeeID = ?",
                "DELETE FROM holiday_pay_adjustments WHERE EmployeeID = ?",
                "DELETE FROM users WHERE ID = ? AND ArchivedAt IS NOT NULL",
            ] as $sql) {
                $st = $conn->prepare($sql);
                $st->bind_param("i", $id);
                $st->execute();
                $st->close();
            }
            $conn->commit();
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            error_log('Permanent delete of user ' . $id . ' failed: ' . $e->getMessage());
            header("Location: archived_users.php?msg=" . urlencode("$fullName could not be deleted; nothing was changed."));
            exit;
        }
        foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) {
            $photo = __DIR__ . "/../avatars/{$id}_pro.$ext";
            if (is_file($photo)) {
                @unlink($photo);
            }
        }
        header("Location: archived_users.php?msg=" . urlencode("$fullName and all of their records were permanently deleted."));
        exit;
    }
}

$punches   = $count("SELECT COUNT(*) FROM timepunches WHERE EmployeeID = ?");
$timeOff   = $count("SELECT COUNT(*) FROM time_off_requests WHERE EmployeeID = ?");
$range     = $conn->prepare("SELECT MIN(Date), MAX(Date), COALESCE(SUM(GREATEST(COALESCE(TotalHours, 0), 0)), 0) FROM timepunches WHERE EmployeeID = ?");
$range->bind_param("i", $id);
$range->execute();
[$firstDate, $lastDate, $hours] = $range->get_result()->fetch_row();
$range->close();
$lock = payrollLockedThrough($conn);
$closedPunches = 0;
if ($lock !== null) {
    $st = $conn->prepare("SELECT COUNT(*) FROM timepunches WHERE EmployeeID = ? AND Date <= ?");
    $st->bind_param("is", $id, $lock);
    $st->execute();
    $closedPunches = (int) $st->get_result()->fetch_row()[0];
    $st->close();
}

$pageTitle = "Delete Employee Permanently";
require_once 'header.php';
?>

<div class="uman-container" style="max-width:720px;">
    <div class="uman-header">
        <h2>Delete <?= htmlspecialchars($fullName) ?> permanently?</h2>
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="panel del-warn" style="border-left:4px solid #c0392b;">
        <style>.del-warn p, .del-warn ul { margin: 0 0 .75rem; }</style>
        <p><strong>This can't be undone.</strong> It deletes the employee and every record of theirs:</p>
        <ul>
            <li><?= $punches ?> punch<?= $punches === 1 ? '' : 'es' ?><?php if ($punches > 0): ?>
                (<?= htmlspecialchars($firstDate) ?> to <?= htmlspecialchars($lastDate) ?>, <?= number_format((float) $hours, 2) ?> paid hours)<?php endif; ?></li>
            <li><?= $timeOff ?> time-off entr<?= $timeOff === 1 ? 'y' : 'ies' ?></li>
            <li>holiday-pay adjustments, timesheet edit requests, punch change history and sign-in log</li>
        </ul>
        <p>Reports, Payroll Totals and exports for those dates will no longer include their hours.
            <?php if ($closedPunches > 0): ?>
                <strong><?= $closedPunches ?> of the punches are in payroll already closed</strong> (through <?= htmlspecialchars($lock) ?>):
                the totals saved at close are kept, but today's figures for those periods will change.
            <?php endif; ?>
        </p>
        <p>To remove someone who has left, keep them archived instead: archived employees can't sign in or punch
            and their hours stay in the records.</p>

        <form method="POST" action="permanently_delete_user.php">
            <?= csrf_field() ?>
            <input type="hidden" name="ID" value="<?= (int) $user['ID'] ?>">
            <label for="ConfirmName">Type <strong><?= htmlspecialchars($fullName) ?></strong> to confirm</label>
            <input type="text" id="ConfirmName" name="ConfirmName" autocomplete="off" required>
            <div class="modal-actions" style="margin-top:1rem;">
                <a href="archived_users.php" class="btn">Cancel</a>
                <button type="submit" class="btn danger">Delete permanently</button>
            </div>
        </form>
    </div>
</div>

<?php require_once 'footer.php'; ?>
