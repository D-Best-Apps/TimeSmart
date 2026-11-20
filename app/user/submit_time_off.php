<?php
session_start();
require '../auth/db.php';
require_once __DIR__ . '/../functions/time_off_email.php';
require_once __DIR__ . '/../functions/app_url.php';
require_once __DIR__ . '/../functions/time_off_hours.php'; // timeOffCategories(), timeOffLabel()
require_once __DIR__ . '/../functions/m365_calendar.php';  // m365HoursPhrase()
require_once __DIR__ . '/../functions/csrf.php';
date_default_timezone_set('America/Chicago');

if (!isset($_SESSION['EmployeeID'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: time_off.php");
    exit;
}

require_csrf('time_off.php?status=invalid&reason=' . urlencode('The page had been open too long. Nothing was sent; please try again.'));

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
$hoursRaw  = trim($_POST['Hours']     ?? '');
$partMode  = ($_POST['PartMode'] ?? '') === 'hours' ? 'hours' : 'times';
// Partial day: the PartDay box, or (older forms without it) times filled in
$partDay   = !empty($_POST['PartDay']) || (!isset($_POST['PartMode']) && ($startTime !== '' || $endTime !== ''));
$notes     = trim($_POST['Notes']     ?? '');

if ($notes !== '' && mb_strlen($notes) > 500) {
    $notes = mb_substr($notes, 0, 500);
}

function rejectInvalid(string $reason): void {
    header('Location: time_off.php?status=invalid&reason=' . urlencode($reason));
    exit;
}

// 1. Category whitelist (PTO, plus Sick when the company offers it)
$allowed = timeOffCategories(false);
if (!array_key_exists($category, $allowed)) {
    rejectInvalid(isset($allowed['Sick']) ? 'Choose Sick or PTO.' : 'Choose PTO.');
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

// 5-6. Partial-day validation: hours missed (0 < h <= 24, no times), or a time window
// (both or neither; end > start on single-day)
$hoursVal = null;
if (!$partDay) {
    $startTime = $endTime = '';
} elseif ($partMode === 'hours') {
    if (!is_numeric($hoursRaw) || (float) $hoursRaw <= 0 || (float) $hoursRaw > 24) {
        rejectInvalid('Enter the hours you will miss: more than 0 and at most 24.');
    }
    $hoursVal  = round((float) $hoursRaw, 2);
    $startTime = $endTime = '';
} elseif ($startTime === '' || $endTime === '') {
    rejectInvalid('Partial day requires both start time and end time, or choose Hours missed.');
}
$hasStartTime = $startTime !== '';
$hasEndTime   = $endTime   !== '';
if ($hasStartTime !== $hasEndTime) {
    rejectInvalid('Partial day requires both start time and end time.');
}
if ($hasStartTime && $hasEndTime) {
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $startTime) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $endTime)) {
        rejectInvalid('Times are not in a valid format.');
    }
    if ($endTime <= $startTime) { // the window repeats each day, so on every day
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
        (EmployeeID, Category, StartDate, EndDate, StartTime, EndTime, Hours, Notes, Status, SubmittedAt)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?)
");
$stmt->bind_param(
    "isssssdss",
    $sessionEmpID,
    $category,
    $startDate,
    $endDate,
    $startTimeVal,
    $endTimeVal,
    $hoursVal,
    $notesVal,
    $submittedAt
);
if (!$stmt->execute()) {
    error_log("submit_time_off: insert failed: " . $stmt->error);
    header('Location: ../error.php?code=500&message=' . urlencode('Could not save your request. Please try again.'));
    exit;
}

// Notify every admin who can act on this (best-effort)
$emailStatus = 'not_attempted';
$adminAddresses = notificationRecipients($conn, 'timeoff');

$empInfo = $conn->prepare("SELECT FirstName, LastName FROM users WHERE ID = ?");
$empInfo->bind_param("i", $sessionEmpID);
$empInfo->execute();
$emp = $empInfo->get_result()->fetch_assoc();
$empName = trim(($emp['FirstName'] ?? '') . ' ' . ($emp['LastName'] ?? ''));

if ($adminAddresses) {
    $datesLabel = $startDate === $endDate
        ? date('m/d/Y', strtotime($startDate))
        : date('m/d/Y', strtotime($startDate)) . ' &ndash; ' . date('m/d/Y', strtotime($endDate));
    $timesLabel = ($hasStartTime && $hasEndTime)
        ? date('g:i a', strtotime($startTime)) . ' &ndash; ' . date('g:i a', strtotime($endTime))
        : ($hoursVal !== null ? m365HoursPhrase($hoursVal, $startDate !== $endDate) . ' missed' : 'all day');

    $subject = "Time-Off Request Submitted &ndash; " . htmlspecialchars($empName);
    $body  = "<p>A new time-off request was submitted:</p>";
    $body .= "<ul>";
    $body .= "<li><strong>Employee:</strong> " . htmlspecialchars($empName) . " (ID {$sessionEmpID})</li>";
    $body .= "<li><strong>Category:</strong> " . htmlspecialchars(timeOffLabel($category)) . "</li>";
    $body .= "<li><strong>Dates:</strong> {$datesLabel}</li>";
    $body .= "<li><strong>Times:</strong> {$timesLabel}</li>";
    if ($notesVal !== null) {
        $body .= "<li><strong>Notes:</strong> " . nl2br(htmlspecialchars($notesVal)) . "</li>";
    }
    $body .= "</ul>";
    $reviewUrl = appUrl('/admin/edits_timesheet.php', $conn);
    $body .= "<p>Review in the admin panel: <a href=\"{$reviewUrl}\">Pending Approvals</a></p>";

    $emailStatus = sendTimeOffEmail($conn, $adminAddresses, $subject, $body);
} else {
    $emailStatus = 'error:incomplete_settings';
    error_log("submit_time_off: no admin recipients (check mail_admin_address and super_admin emails); admins not notified.");
}

header("Location: time_off.php?status=submitted&email_status=" . urlencode($emailStatus));
exit;
