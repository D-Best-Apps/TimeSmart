<?php
// Cancel an already-approved time-off request.
// Unlike reject (a decision on a *pending* request) this undoes an approval:
// the row becomes 'Cancelled', the M365 calendar event is deleted, and the
// employee is emailed. With employee invites enabled, deleting the event makes
// Exchange send a cancellation, so it also disappears from their own calendar.
session_start();
require '../auth/db.php';
require_once __DIR__ . '/../functions/check_permission.php';
require_once __DIR__ . '/../functions/time_off_email.php';
require_once __DIR__ . '/../functions/m365_calendar.php';
date_default_timezone_set('America/Chicago');

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}
requirePermission('approve_edits');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: edits_timesheet.php");
    exit;
}

$admin     = $_SESSION['admin'];
$requestID = (int) ($_POST['RequestID'] ?? 0);
$reason    = trim($_POST['CancelReason'] ?? '');
$now       = date('Y-m-d H:i:s');

if ($requestID === 0) {
    header('Location: edits_timesheet.php');
    exit;
}
if ($reason !== '' && mb_strlen($reason) > 500) {
    $reason = mb_substr($reason, 0, 500);
}

$stmt = $conn->prepare("
    SELECT tor.*, u.FirstName, u.LastName, u.Email
      FROM time_off_requests tor
      JOIN users u ON u.ID = tor.EmployeeID
     WHERE tor.ID = ? AND tor.Status = 'Approved'
");
$stmt->bind_param("i", $requestID);
$stmt->execute();
$req = $stmt->get_result()->fetch_assoc();
if (!$req) {
    // Not found, or not in a cancellable state (already cancelled, still pending, …)
    header('Location: edit_time_off.php?id=' . $requestID . '&status=invalid&reason=' . urlencode('Only an approved request can be cancelled.'));
    exit;
}

$employeeName = trim($req['FirstName'] . ' ' . $req['LastName']);

// Fold the reason into the review-note trail so it shows on the request.
$existingReviewNote = $req['ReviewNote'] ?? '';
$mergedReviewNote = trim(
    ($existingReviewNote ? $existingReviewNote . "\n" : '')
    . "[" . date('m/d/Y') . " " . $admin . "] Cancelled"
    . ($reason !== '' ? ": " . $reason : "")
);

// Status guarded in the WHERE so a double-submit / race can't double-cancel.
$u = $conn->prepare("
    UPDATE time_off_requests
       SET Status = 'Cancelled', ReviewNote = ?, ReviewedAt = ?, ReviewedBy = ?
     WHERE ID = ? AND Status = 'Approved'
");
$u->bind_param("sssi", $mergedReviewNote, $now, $admin, $requestID);
if (!$u->execute() || $u->affected_rows === 0) {
    header('Location: edit_time_off.php?id=' . $requestID . '&status=invalid&reason=' . urlencode('Request was already changed by someone else.'));
    exit;
}

// Any still-pending amendment against this request is now moot.
$amend = $conn->prepare("
    UPDATE time_off_requests
       SET Status = 'Cancelled', ReviewedAt = ?, ReviewedBy = ?
     WHERE AmendsRequestID = ? AND Status = 'Pending'
");
$amend->bind_param("ssi", $now, $admin, $requestID);
$amend->execute();

// Remove the calendar event (best-effort — the cancellation is already committed)
$m365Failure = null;
if (!empty($req['M365EventId'])) {
    $config = m365GetConfig($conn);
    if ($config !== null && !empty($config['m365_calendar_mailbox'])) {
        $tok = m365GetToken($config);
        if ($tok['success']) {
            $del = m365DeleteMailboxEvent($config['m365_calendar_mailbox'], $tok['token'], $req['M365EventId']);
            if ($del['success']) {
                $sync = $conn->prepare("
                    UPDATE time_off_requests
                       SET M365EventId = NULL, M365SyncStatus = 'cancelled', M365SyncAt = ?
                     WHERE ID = ?
                ");
                $sync->bind_param("si", $now, $requestID);
                $sync->execute();
            } else {
                $m365Failure = $del['error'];
                $state = 'error:' . $del['error'];
                $sync = $conn->prepare("UPDATE time_off_requests SET M365SyncStatus = ?, M365SyncAt = ? WHERE ID = ?");
                $sync->bind_param("ssi", $state, $now, $requestID);
                $sync->execute();
                error_log("cancel_time_off: event delete failed for request {$requestID}: " . $del['error']);
            }
        } else {
            $m365Failure = $tok['error'];
            error_log("cancel_time_off: token fetch failed: " . $tok['error']);
        }
    }
}

// Notify the employee (best-effort)
$employeeEmail = trim($req['Email'] ?? '');
if ($employeeEmail !== '') {
    $datesLabel = $req['StartDate'] === $req['EndDate']
        ? date('m/d/Y', strtotime($req['StartDate']))
        : date('m/d/Y', strtotime($req['StartDate'])) . ' – ' . date('m/d/Y', strtotime($req['EndDate']));
    $timesLabel = (!empty($req['StartTime']) && !empty($req['EndTime']))
        ? date('g:i a', strtotime($req['StartTime'])) . ' – ' . date('g:i a', strtotime($req['EndTime']))
        : 'all day';

    $subject = "Your Approved Time Off was Cancelled";
    $body  = "<p>Hi " . htmlspecialchars($req['FirstName']) . ",</p>";
    $body .= "<p>An admin has <strong>cancelled</strong> time off that was previously approved:</p>";
    $body .= "<ul>";
    $body .= "<li><strong>Category:</strong> " . htmlspecialchars($req['Category']) . "</li>";
    $body .= "<li><strong>Dates:</strong> " . htmlspecialchars($datesLabel) . "</li>";
    $body .= "<li><strong>Times:</strong> " . htmlspecialchars($timesLabel) . "</li>";
    if ($reason !== '') {
        $body .= "<li><strong>Reason:</strong> " . nl2br(htmlspecialchars($reason)) . "</li>";
    }
    $body .= "<li><strong>Cancelled by:</strong> " . htmlspecialchars($admin) . "</li>";
    $body .= "</ul>";
    $body .= "<p>These hours no longer count toward your time off. If this looks wrong, reply to your manager.</p>";

    sendTimeOffEmail($conn, $employeeEmail, $subject, $body);
}

$query = '?status=cancelled';
if ($m365Failure !== null) {
    $query .= '&m365_sync=failed&details=' . urlencode($employeeName . ': ' . $m365Failure);
}
header('Location: edits_timesheet.php' . $query);
exit;
