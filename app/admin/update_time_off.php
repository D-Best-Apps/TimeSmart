<?php
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
if ($requestID === 0) {
    header('Location: edits_timesheet.php');
    exit;
}

$stmt = $conn->prepare("
    SELECT tor.*, u.FirstName, u.LastName, u.Email
      FROM time_off_requests tor
      JOIN users u ON u.ID = tor.EmployeeID
     WHERE tor.ID = ?
");
$stmt->bind_param("i", $requestID);
$stmt->execute();
$req = $stmt->get_result()->fetch_assoc();
if (!$req) {
    header('Location: edits_timesheet.php');
    exit;
}

// Validate inputs
$category  = $_POST['Category']  ?? '';
$startDate = $_POST['StartDate'] ?? '';
$endDate   = $_POST['EndDate']   ?? '';
$startTime = trim($_POST['StartTime'] ?? '');
$endTime   = trim($_POST['EndTime']   ?? '');
$notes     = trim($_POST['Notes']     ?? '');
$adminNote = trim($_POST['AdminNote'] ?? '');
$canViewPrivate = canViewPrivateNotes($conn);
$privateNote = $canViewPrivate ? trim($_POST['AdminPrivateNote'] ?? '') : null;

function rejectAdmin(int $rid, string $reason): void {
    header('Location: edit_time_off.php?id=' . $rid . '&status=invalid&reason=' . urlencode($reason));
    exit;
}

if (!in_array($category, ['Sick', 'PTO'], true)) rejectAdmin($requestID, 'Choose Sick or PTO.');
$dateRegex = '/^\d{4}-\d{2}-\d{2}$/';
if (!preg_match($dateRegex, $startDate) || !preg_match($dateRegex, $endDate)) rejectAdmin($requestID, 'Dates required.');
if ($endDate < $startDate) rejectAdmin($requestID, 'End date before start date.');
$hasStartTime = $startTime !== '';
$hasEndTime   = $endTime   !== '';
if ($hasStartTime !== $hasEndTime) rejectAdmin($requestID, 'Both or neither time.');
if ($hasStartTime && $hasEndTime) {
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $startTime) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $endTime)) {
        rejectAdmin($requestID, 'Times not valid.');
    }
    if ($startDate === $endDate && $endTime <= $startTime) rejectAdmin($requestID, 'End time must be after start time.');
}

if ($notes     !== '' && mb_strlen($notes)     > 500) $notes     = mb_substr($notes,     0, 500);
if ($adminNote !== '' && mb_strlen($adminNote) > 500) $adminNote = mb_substr($adminNote, 0, 500);
if ($privateNote !== null && mb_strlen($privateNote) > 500) $privateNote = mb_substr($privateNote, 0, 500);

$startTimeVal = $hasStartTime ? $startTime : null;
$endTimeVal   = $hasEndTime   ? $endTime   : null;
$notesVal     = $notes !== '' ? $notes : null;
$now          = date('Y-m-d H:i:s');

// Apply update directly
$existingReviewNote = $req['ReviewNote'] ?? '';
$mergedReviewNote = $adminNote !== ''
    ? trim(($existingReviewNote ? $existingReviewNote . "\n" : '') . "[" . date('m/d/Y') . " " . $admin . "] " . $adminNote)
    : $existingReviewNote;

// Admins without private-note access leave AdminPrivateNote untouched.
if ($canViewPrivate) {
    $privVal = ($privateNote !== null && $privateNote !== '') ? $privateNote : null;
    $u = $conn->prepare("
        UPDATE time_off_requests
           SET Category=?, StartDate=?, EndDate=?, StartTime=?, EndTime=?, Notes=?, ReviewNote=?, AdminPrivateNote=?, ReviewedAt=?, ReviewedBy=?
         WHERE ID=?
    ");
    $u->bind_param("ssssssssssi",
        $category, $startDate, $endDate, $startTimeVal, $endTimeVal, $notesVal, $mergedReviewNote, $privVal, $now, $admin, $requestID);
} else {
    $u = $conn->prepare("
        UPDATE time_off_requests
           SET Category=?, StartDate=?, EndDate=?, StartTime=?, EndTime=?, Notes=?, ReviewNote=?, ReviewedAt=?, ReviewedBy=?
         WHERE ID=?
    ");
    $u->bind_param("sssssssssi",
        $category, $startDate, $endDate, $startTimeVal, $endTimeVal, $notesVal, $mergedReviewNote, $now, $admin, $requestID);
}
$u->execute();

// If the request was Approved (so it has a calendar event), replace the M365 event
$wasApproved = $req['Status'] === 'Approved';
$m365Failure = null;
if ($wasApproved) {
    $config = m365GetConfig($conn);
    if ($config !== null && !empty($config['m365_calendar_mailbox'])) {
        $tok = m365GetToken($config);
        if ($tok['success']) {
            // Reload updated row
            $reload = $conn->query("SELECT * FROM time_off_requests WHERE ID = {$requestID}")->fetch_assoc();
            $employeeName = trim($req['FirstName'] . ' ' . $req['LastName']);
            $event = m365BuildEvent($reload, $employeeName, $config['m365_timezone']);
            $result = m365ReplaceMailboxEvent(
                $config['m365_calendar_mailbox'],
                $tok['token'],
                $req['M365EventId'] ?? null,
                $event
            );
            $newEventId   = $result['success'] ? $result['eventId'] : null;
            $newSyncState = $result['success'] ? 'sent' : 'error:' . ($result['error'] ?? 'unknown');
            $sync = $conn->prepare("UPDATE time_off_requests SET M365EventId=?, M365SyncStatus=?, M365SyncAt=? WHERE ID=?");
            $sync->bind_param("sssi", $newEventId, $newSyncState, $now, $requestID);
            $sync->execute();
            if (!$result['success']) $m365Failure = $result['error'];
        } else {
            $m365Failure = $tok['error'];
        }
    }
}

// Email employee about the admin adjustment
$employeeEmail = trim($req['Email'] ?? '');
if ($employeeEmail !== '') {
    $datesLabel = $startDate === $endDate
        ? date('m/d/Y', strtotime($startDate))
        : date('m/d/Y', strtotime($startDate)) . ' – ' . date('m/d/Y', strtotime($endDate));
    $timesLabel = ($hasStartTime && $hasEndTime)
        ? date('g:i a', strtotime($startTime)) . ' – ' . date('g:i a', strtotime($endTime))
        : 'all day';

    $subject = "Your Time-Off Request was Adjusted by Admin";
    $body  = "<p>Hi " . htmlspecialchars($req['FirstName']) . ",</p>";
    $body .= "<p>An admin has adjusted your time-off request. Updated details:</p>";
    $body .= "<ul>";
    $body .= "<li><strong>Category:</strong> " . htmlspecialchars($category) . "</li>";
    $body .= "<li><strong>Dates:</strong> " . htmlspecialchars($datesLabel) . "</li>";
    $body .= "<li><strong>Times:</strong> " . htmlspecialchars($timesLabel) . "</li>";
    if ($notesVal !== null)  $body .= "<li><strong>Notes:</strong> " . nl2br(htmlspecialchars($notesVal)) . "</li>";
    if ($adminNote !== '')   $body .= "<li><strong>Admin note:</strong> " . nl2br(htmlspecialchars($adminNote)) . "</li>";
    $body .= "<li><strong>Adjusted by:</strong> " . htmlspecialchars($admin) . "</li>";
    $body .= "</ul>";
    $body .= "<p>If this doesn't match your expectation, please contact the admin.</p>";

    sendTimeOffEmail($conn, $employeeEmail, $subject, $body);
}

$q = '?admin_edit=ok';
if ($m365Failure) $q = '?admin_edit=ok&m365_sync=failed&details=' . urlencode($m365Failure);
header("Location: edits_timesheet.php{$q}");
exit;
