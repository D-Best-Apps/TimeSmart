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

if ($sessionEmpID !== $postEmpID || $postEmpID === 0) {
    header('Location: ../error.php?code=400&message=' . urlencode('Invalid submission.'));
    exit;
}

$category  = $_POST['Category']  ?? '';
$startDate = $_POST['StartDate'] ?? '';
$endDate   = $_POST['EndDate']   ?? '';
$startTime = trim($_POST['StartTime'] ?? '');
$endTime   = trim($_POST['EndTime']   ?? '');
$notes     = trim($_POST['Notes']     ?? '');

if ($notes !== '' && mb_strlen($notes) > 500) {
    $notes = mb_substr($notes, 0, 500);
}

function rejectInvalid(string $reason): void {
    header('Location: time_off.php?status=invalid&reason=' . urlencode($reason));
    exit;
}

// 1. Category whitelist
if (!in_array($category, ['Sick', 'PTO'], true)) {
    rejectInvalid('Choose Sick or PTO.');
}

// 2-3. Date parsing and ordering
$dateRegex = '/^\d{4}-\d{2}-\d{2}$/';
if (!preg_match($dateRegex, $startDate) || !preg_match($dateRegex, $endDate)) {
    rejectInvalid('Start and end date are required.');
}
if (strtotime($startDate) === false || strtotime($endDate) === false) {
    rejectInvalid('Dates are not valid.');
}
if ($endDate < $startDate) {
    rejectInvalid('End date cannot be before start date.');
}

// 4. No past dates
if ($startDate < date('Y-m-d')) {
    rejectInvalid('Start date cannot be in the past.');
}

// 5-6. Partial-day validation (both or neither; end > start on single-day)
$hasStartTime = $startTime !== '';
$hasEndTime   = $endTime   !== '';
if ($hasStartTime !== $hasEndTime) {
    rejectInvalid('Partial day requires both start time and end time.');
}
if ($hasStartTime && $hasEndTime) {
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $startTime) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $endTime)) {
        rejectInvalid('Times are not in a valid format.');
    }
    if ($startDate === $endDate && $endTime <= $startTime) {
        rejectInvalid('End time must be later than start time.');
    }
}

// 7. Overlap with existing Pending/Approved requests
$overlapStmt = $conn->prepare("
    SELECT ID FROM time_off_requests
    WHERE EmployeeID = ?
      AND Status IN ('Pending','Approved')
      AND NOT (EndDate < ? OR StartDate > ?)
    LIMIT 1
");
$overlapStmt->bind_param("iss", $sessionEmpID, $startDate, $endDate);
$overlapStmt->execute();
if ($overlapStmt->get_result()->num_rows > 0) {
    header('Location: time_off.php?status=overlap');
    exit;
}

// Insert
$startTimeVal = $hasStartTime ? $startTime : null;
$endTimeVal   = $hasEndTime   ? $endTime   : null;
$notesVal     = $notes !== '' ? $notes     : null;
$submittedAt  = date('Y-m-d H:i:s');

$stmt = $conn->prepare("
    INSERT INTO time_off_requests
        (EmployeeID, Category, StartDate, EndDate, StartTime, EndTime, Notes, Status, SubmittedAt)
    VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending', ?)
");
$stmt->bind_param(
    "isssssss",
    $sessionEmpID,
    $category,
    $startDate,
    $endDate,
    $startTimeVal,
    $endTimeVal,
    $notesVal,
    $submittedAt
);
if (!$stmt->execute()) {
    error_log("submit_time_off: insert failed: " . $stmt->error);
    header('Location: ../error.php?code=500&message=' . urlencode('Could not save your request. Please try again.'));
    exit;
}

// Notify admin (best-effort)
$emailStatus = 'not_attempted';
$adminAddress = '';
if ($row = $conn->query("SELECT SettingValue FROM settings WHERE SettingKey = 'mail_admin_address' LIMIT 1")->fetch_assoc()) {
    $adminAddress = $row['SettingValue'] ?? '';
}

$empInfo = $conn->prepare("SELECT FirstName, LastName FROM users WHERE ID = ?");
$empInfo->bind_param("i", $sessionEmpID);
$empInfo->execute();
$emp = $empInfo->get_result()->fetch_assoc();
$empName = trim(($emp['FirstName'] ?? '') . ' ' . ($emp['LastName'] ?? ''));

if ($adminAddress !== '') {
    $datesLabel = $startDate === $endDate
        ? date('m/d/Y', strtotime($startDate))
        : date('m/d/Y', strtotime($startDate)) . ' &ndash; ' . date('m/d/Y', strtotime($endDate));
    $timesLabel = ($hasStartTime && $hasEndTime)
        ? date('g:i a', strtotime($startTime)) . ' &ndash; ' . date('g:i a', strtotime($endTime))
        : 'all day';

    $subject = "Time-Off Request Submitted &ndash; " . htmlspecialchars($empName);
    $body  = "<p>A new time-off request was submitted:</p>";
    $body .= "<ul>";
    $body .= "<li><strong>Employee:</strong> " . htmlspecialchars($empName) . " (ID {$sessionEmpID})</li>";
    $body .= "<li><strong>Category:</strong> " . htmlspecialchars($category) . "</li>";
    $body .= "<li><strong>Dates:</strong> {$datesLabel}</li>";
    $body .= "<li><strong>Times:</strong> {$timesLabel}</li>";
    if ($notesVal !== null) {
        $body .= "<li><strong>Notes:</strong> " . nl2br(htmlspecialchars($notesVal)) . "</li>";
    }
    $body .= "</ul>";
    $body .= "<p>Review in the admin panel: <a href=\"/admin/edits_timesheet.php\">Pending Approvals</a></p>";

    $emailStatus = sendTimeOffEmail($conn, $adminAddress, $subject, $body);
} else {
    $emailStatus = 'error:incomplete_settings';
    error_log("submit_time_off: mail_admin_address not configured; admin not notified.");
}

header("Location: time_off.php?status=submitted&email_status=" . urlencode($emailStatus));
exit;
