<?php
// Employee-initiated cancellation of their own APPROVED time off.
// Distinct from withdraw_time_off.php (which pulls a still-Pending request):
// this undoes an approval, so it also removes the M365 calendar event and
// notifies the admin. Restricted to time off that has not started yet — a
// past or in-progress window has to go through the amendment flow so a
// bookkeeper reviews hours that may already have been reported.
session_start();
require '../auth/db.php';
require_once __DIR__ . '/../functions/time_off_email.php';
require_once __DIR__ . '/../functions/app_url.php';
require_once __DIR__ . '/../functions/m365_calendar.php';
date_default_timezone_set('America/Chicago');

if (!isset($_SESSION['EmployeeID'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: time_off.php");
    exit;
}

$sessionEmpID = (int) $_SESSION['EmployeeID'];
$requestID    = (int) ($_POST['RequestID'] ?? 0);
$reason       = trim($_POST['CancelReason'] ?? '');
$now          = date('Y-m-d H:i:s');
$today        = date('Y-m-d');

if ($requestID === 0) {
    header('Location: time_off.php');
    exit;
}
if ($reason === '') {
    header('Location: time_off.php?status=invalid&reason=' . urlencode('A reason is required to cancel approved time off.'));
    exit;
}
if (mb_strlen($reason) > 500) {
    $reason = mb_substr($reason, 0, 500);
}

// Must be the owner's own approved request.
$stmt = $conn->prepare("
    SELECT tor.*, u.FirstName, u.LastName
      FROM time_off_requests tor
      JOIN users u ON u.ID = tor.EmployeeID
     WHERE tor.ID = ? AND tor.EmployeeID = ? AND tor.Status = 'Approved'
");
$stmt->bind_param("ii", $requestID, $sessionEmpID);
$stmt->execute();
$req = $stmt->get_result()->fetch_assoc();
if (!$req) {
    header('Location: time_off.php?status=invalid&reason=' . urlencode('Only your own approved time off can be cancelled.'));
    exit;
}

// Already started (or finished) — the employee can't undo it themselves.
if ($req['StartDate'] <= $today) {
    header('Location: time_off.php?status=invalid&reason=' . urlencode('This time off has already started. Use Edit to request a change, or ask an admin to cancel it.'));
    exit;
}

$employeeName = trim($req['FirstName'] . ' ' . $req['LastName']);

// Fold the reason into the review-note trail so the history shows who pulled it.
$existingReviewNote = $req['ReviewNote'] ?? '';
$mergedReviewNote = trim(
    ($existingReviewNote ? $existingReviewNote . "\n" : '')
    . "[" . date('m/d/Y') . " " . $employeeName . "] Cancelled by employee: " . $reason
);
if (mb_strlen($mergedReviewNote) > 500) {
    $mergedReviewNote = mb_substr($mergedReviewNote, 0, 500);
}

// Status guarded in the WHERE so a race with an admin decision can't double-cancel.
$u = $conn->prepare("
    UPDATE time_off_requests
       SET Status = 'Cancelled', ReviewNote = ?, ReviewedAt = ?, ReviewedBy = ?
     WHERE ID = ? AND EmployeeID = ? AND Status = 'Approved'
");
$u->bind_param("sssii", $mergedReviewNote, $now, $employeeName, $requestID, $sessionEmpID);
if (!$u->execute() || $u->affected_rows === 0) {
    header('Location: time_off.php?status=invalid&reason=' . urlencode('That request was just changed by someone else. Reload and try again.'));
    exit;
}

// Any still-pending amendment against this request is now moot.
$amend = $conn->prepare("
    UPDATE time_off_requests
       SET Status = 'Cancelled', ReviewedAt = ?, ReviewedBy = ?
     WHERE AmendsRequestID = ? AND EmployeeID = ? AND Status = 'Pending'
");
$amend->bind_param("ssii", $now, $employeeName, $requestID, $sessionEmpID);
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
                error_log("user/cancel_time_off: event delete failed for request {$requestID}: " . $del['error']);
            }
        } else {
            $m365Failure = $tok['error'];
            error_log("user/cancel_time_off: token fetch failed: " . $tok['error']);
        }
    }
}

// Notify every admin (best-effort)
$adminAddresses = notificationRecipients($conn, 'timeoff');

$emailStatus = 'not_attempted';
if ($adminAddresses) {
    $datesLabel = $req['StartDate'] === $req['EndDate']
        ? date('m/d/Y', strtotime($req['StartDate']))
        : date('m/d/Y', strtotime($req['StartDate'])) . ' &ndash; ' . date('m/d/Y', strtotime($req['EndDate']));
    $timesLabel = (!empty($req['StartTime']) && !empty($req['EndTime']))
        ? date('g:i a', strtotime($req['StartTime'])) . ' &ndash; ' . date('g:i a', strtotime($req['EndTime']))
        : 'all day';

    $subject = "Time Off Cancelled by Employee &ndash; " . htmlspecialchars($employeeName);
    $body  = "<p><strong>" . htmlspecialchars($employeeName) . "</strong> has cancelled time off that was previously approved. No action is needed &mdash; the hours no longer count.</p>";
    $body .= "<ul>";
    $body .= "<li><strong>Category:</strong> " . htmlspecialchars($req['Category']) . "</li>";
    $body .= "<li><strong>Dates:</strong> " . $datesLabel . "</li>";
    $body .= "<li><strong>Times:</strong> " . $timesLabel . "</li>";
    $body .= "<li><strong>Reason given:</strong> " . nl2br(htmlspecialchars($reason)) . "</li>";
    $body .= "</ul>";
    if (!empty($req['M365EventId'])) {
        $body .= $m365Failure === null
            ? "<p>The calendar event has been removed.</p>"
            : "<p><strong>Note:</strong> the calendar event could not be removed automatically (" . htmlspecialchars($m365Failure) . ") &mdash; it may need to be deleted by hand.</p>";
    }
    $reviewUrl = appUrl("/admin/edit_time_off.php?id={$requestID}", $conn);
    $body .= "<p>View in the admin panel: <a href=\"{$reviewUrl}\">this request</a></p>";

    $emailStatus = sendTimeOffEmail($conn, $adminAddresses, $subject, $body);
}

$query = '?status=cancelled&email_status=' . urlencode($emailStatus);
if ($m365Failure !== null) {
    $query .= '&m365_sync=failed';
}
header('Location: time_off.php' . $query);
exit;
