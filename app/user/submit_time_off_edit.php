<?php
session_start();
require '../auth/db.php';
require_once __DIR__ . '/../functions/time_off_email.php';
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
$postEmpID    = (int) ($_POST['EmployeeID'] ?? 0);
$requestID    = (int) ($_POST['RequestID']  ?? 0);

if ($sessionEmpID !== $postEmpID || $requestID === 0) {
    header('Location: ../error.php?code=400&message=' . urlencode('Invalid submission.'));
    exit;
}

// Load and lock the row — must belong to this employee, must be Pending or Approved
$stmt = $conn->prepare("SELECT * FROM time_off_requests WHERE ID = ? AND EmployeeID = ? AND Status IN ('Pending','Approved')");
$stmt->bind_param("ii", $requestID, $sessionEmpID);
$stmt->execute();
$req = $stmt->get_result()->fetch_assoc();
if (!$req) {
    header('Location: time_off.php?status=invalid&reason=' . urlencode('Request not editable.'));
    exit;
}

$isApproved = $req['Status'] === 'Approved';

// Parse + validate new values
$category  = $_POST['Category']  ?? '';
$startDate = $_POST['StartDate'] ?? '';
$endDate   = $_POST['EndDate']   ?? '';
$startTime = trim($_POST['StartTime'] ?? '');
$endTime   = trim($_POST['EndTime']   ?? '');
$notes     = trim($_POST['Notes']     ?? '');
$reason    = trim($_POST['Reason']    ?? '');

if ($notes !== '' && mb_strlen($notes) > 500)  $notes  = mb_substr($notes, 0, 500);
if ($reason !== '' && mb_strlen($reason) > 500) $reason = mb_substr($reason, 0, 500);

function rejectEdit(int $rid, string $reason): void {
    header('Location: edit_time_off.php?id=' . $rid . '&status=invalid&reason=' . urlencode($reason));
    exit;
}

if (!in_array($category, ['Sick', 'PTO'], true)) {
    rejectEdit($requestID, 'Choose Sick or PTO.');
}
$dateRegex = '/^\d{4}-\d{2}-\d{2}$/';
if (!preg_match($dateRegex, $startDate) || !preg_match($dateRegex, $endDate)) {
    rejectEdit($requestID, 'Start and end dates are required.');
}
if ($endDate < $startDate) {
    rejectEdit($requestID, 'End date cannot be before start date.');
}
// No past-date check on Approved edits — bookkeeper amendment use case explicitly needs to adjust past windows.
if (!$isApproved && $startDate < date('Y-m-d')) {
    rejectEdit($requestID, 'Start date cannot be in the past.');
}
$hasStartTime = $startTime !== '';
$hasEndTime   = $endTime   !== '';
if ($hasStartTime !== $hasEndTime) {
    rejectEdit($requestID, 'Partial day requires both start time and end time.');
}
if ($hasStartTime && $hasEndTime) {
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $startTime) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $endTime)) {
        rejectEdit($requestID, 'Times are not valid.');
    }
    if ($startDate === $endDate && $endTime <= $startTime) {
        rejectEdit($requestID, 'End time must be later than start time.');
    }
}
if ($isApproved && $reason === '') {
    rejectEdit($requestID, 'A reason for the change is required when amending an approved request.');
}

$startTimeVal = $hasStartTime ? $startTime : null;
$endTimeVal   = $hasEndTime   ? $endTime   : null;
$notesVal     = $notes  !== '' ? $notes  : null;
$reasonVal    = $reason !== '' ? $reason : null;
$now          = date('Y-m-d H:i:s');

if (!$isApproved) {
    // Pending → edit in place (no amendment row, no admin email — admin sees updated values in queue)
    $u = $conn->prepare("
        UPDATE time_off_requests
           SET Category=?, StartDate=?, EndDate=?, StartTime=?, EndTime=?, Notes=?, SubmittedAt=?
         WHERE ID = ? AND EmployeeID = ? AND Status = 'Pending'
    ");
    $u->bind_param("sssssssii", $category, $startDate, $endDate, $startTimeVal, $endTimeVal, $notesVal, $now, $requestID, $sessionEmpID);
    $u->execute();
    header('Location: time_off.php?status=updated');
    exit;
}

// Approved → create an amendment row that admin must approve
// Block if there's already a pending amendment for this request (one open amendment at a time)
$openAmend = $conn->prepare("SELECT ID FROM time_off_requests WHERE AmendsRequestID = ? AND Status = 'Pending' LIMIT 1");
$openAmend->bind_param("i", $requestID);
$openAmend->execute();
if ($openAmend->get_result()->num_rows > 0) {
    header('Location: time_off.php?status=invalid&reason=' . urlencode('You already have a pending amendment for this request. Wait for it to be reviewed or withdraw it first.'));
    exit;
}

$ins = $conn->prepare("
    INSERT INTO time_off_requests
        (EmployeeID, Category, StartDate, EndDate, StartTime, EndTime, Notes, Reason,
         Status, SubmittedAt, AmendsRequestID)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?, ?)
");
$ins->bind_param(
    "issssssssi",
    $sessionEmpID, $category, $startDate, $endDate, $startTimeVal, $endTimeVal,
    $notesVal, $reasonVal, $now, $requestID
);
if (!$ins->execute()) {
    error_log("submit_time_off_edit: amendment insert failed: " . $ins->error);
    header('Location: ../error.php?code=500&message=' . urlencode('Could not submit your amendment.'));
    exit;
}

// Notify admin
$adminAddress = '';
if ($row = $conn->query("SELECT SettingValue FROM settings WHERE SettingKey = 'mail_admin_address' LIMIT 1")->fetch_assoc()) {
    $adminAddress = $row['SettingValue'] ?? '';
}

$empInfo = $conn->prepare("SELECT FirstName, LastName FROM users WHERE ID = ?");
$empInfo->bind_param("i", $sessionEmpID);
$empInfo->execute();
$emp = $empInfo->get_result()->fetch_assoc();
$empName = trim(($emp['FirstName'] ?? '') . ' ' . ($emp['LastName'] ?? ''));

$emailStatus = 'not_attempted';
if ($adminAddress !== '') {
    $origDates = $req['StartDate'] === $req['EndDate']
        ? date('m/d/Y', strtotime($req['StartDate']))
        : date('m/d/Y', strtotime($req['StartDate'])) . ' &ndash; ' . date('m/d/Y', strtotime($req['EndDate']));
    $newDates = $startDate === $endDate
        ? date('m/d/Y', strtotime($startDate))
        : date('m/d/Y', strtotime($startDate)) . ' &ndash; ' . date('m/d/Y', strtotime($endDate));
    $origTimes = ($req['StartTime'] && $req['EndTime'])
        ? date('g:i a', strtotime($req['StartTime'])) . ' &ndash; ' . date('g:i a', strtotime($req['EndTime']))
        : 'all day';
    $newTimes = ($hasStartTime && $hasEndTime)
        ? date('g:i a', strtotime($startTime)) . ' &ndash; ' . date('g:i a', strtotime($endTime))
        : 'all day';

    $subject = "Time-Off Amendment Submitted &ndash; " . htmlspecialchars($empName);
    $body  = "<p><strong>{$empName}</strong> has submitted an amendment to an approved time-off request.</p>";
    $body .= "<table cellpadding='4' cellspacing='0' border='1' style='border-collapse:collapse;'>";
    $body .= "<tr><th></th><th>Original (approved)</th><th>Requested change</th></tr>";
    $body .= "<tr><td>Category</td><td>" . htmlspecialchars($req['Category']) . "</td><td>" . htmlspecialchars($category) . "</td></tr>";
    $body .= "<tr><td>Dates</td><td>{$origDates}</td><td>{$newDates}</td></tr>";
    $body .= "<tr><td>Times</td><td>{$origTimes}</td><td>{$newTimes}</td></tr>";
    $body .= "<tr><td>Notes</td><td>" . nl2br(htmlspecialchars($req['Notes'] ?? '')) . "</td><td>" . nl2br(htmlspecialchars($notesVal ?? '')) . "</td></tr>";
    $body .= "</table>";
    $body .= "<p><strong>Reason for change:</strong> " . nl2br(htmlspecialchars($reasonVal ?? '')) . "</p>";
    $body .= "<p>Review in the admin panel: <a href=\"/admin/edits_timesheet.php\">Pending Approvals</a></p>";

    $emailStatus = sendTimeOffEmail($conn, $adminAddress, $subject, $body);
}

header("Location: time_off.php?status=amendment_submitted&email_status=" . urlencode($emailStatus));
exit;
