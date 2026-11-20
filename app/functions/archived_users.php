<?php
/**
 * functions/archived_users.php
 *
 * Archived employees stay in `users` with ArchivedAt set (see admin/archive_user.php).
 * Every punch, time-off entry, holiday-pay adjustment and log of theirs is kept, so
 * reports and payroll for dates they worked don't change. They can't sign in or punch,
 * and lists of current employees (pickers, Home, the public board, cron jobs) leave
 * them out with `ArchivedAt IS NULL`. While archived, their badge ID and PIN sit in
 * ArchivedBadgeID / ArchivedPIN so the unique BadgeID / PIN can go to someone else.
 */

/**
 * End the session of someone archived (or deleted) since they signed in.
 * Call after session_start() on pages and handlers a signed-in user reaches.
 */
function endSessionIfArchived(mysqli $conn, string $loginUrl = 'login.php'): void
{
    static $checked = false;
    $id = (int) ($_SESSION['EmployeeID'] ?? 0);
    if ($checked || $id <= 0) {
        return;
    }
    $st = $conn->prepare("SELECT 1 FROM users WHERE ID = ? AND ArchivedAt IS NULL");
    $st->bind_param("i", $id);
    $st->execute();
    $active = (bool) $st->get_result()->fetch_row();
    $st->close();
    if ($active) {
        $checked = true;
        return;
    }
    $_SESSION = [];
    session_destroy();
    header('Location: ' . $loginUrl);
    exit;
}

