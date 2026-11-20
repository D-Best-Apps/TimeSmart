<?php
session_start();
require '../auth/db.php';
require_once __DIR__ . '/../functions/time_off_email.php';
require_once __DIR__ . '/../functions/app_url.php';
require_once __DIR__ . '/../functions/time_off_hours.php'; // timeOffCategories(), timeOffLabel()
require_once __DIR__ . '/../functions/m365_calendar.php';  // m365HoursPhrase()
require_once __DIR__ . '/../functions/csrf.php';
require_once __DIR__ . '/../functions/pay_rules.php'; // isPayrollLocked()
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
require_csrf('edit_time_off.php?id=' . $requestID . '&status=invalid&reason=' . urlencode('The page had been open too long. Nothing was saved; please try again.'));

// Load and lock the row — must belong to this employee, must be Pending or Approved
$stmt = $conn->prepare("SELECT * FROM time_off_requests WHERE ID = ? AND EmployeeID = ? AND Status IN ('Pending','Approved') AND Category NOT IN ('Unpaid','Unexcused')");
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
$hoursRaw  = trim($_POST['Hours']     ?? '');
$partMode  = ($_POST['PartMode'] ?? '') === 'hours' ? 'hours' : 'times';
// Partial day: the PartDay box, or (older forms without it) times filled in
$partDay   = !empty($_POST['PartDay']) || (!isset($_POST['PartMode']) && ($startTime !== '' || $endTime !== ''));
$notes     = trim($_POST['Notes']     ?? '');
$reason    = trim($_POST['Reason']    ?? '');

if ($notes !== '' && mb_strlen($notes) > 500)  $notes  = mb_substr($notes, 0, 500);
if ($reason !== '' && mb_strlen($reason) > 500) $reason = mb_substr($reason, 0, 500);

function rejectEdit(int $rid, string $reason): void {
    header('Location: edit_time_off.php?id=' . $rid . '&status=invalid&reason=' . urlencode($reason));
    exit;
}

// PTO, plus Sick when offered; a Sick request may stay Sick even if Sick has been turned off
$allowed = timeOffCategories(false, $req['Category'] === 'Sick' ? 'Sick' : null);
if (!array_key_exists($category, $allowed)) {
    rejectEdit($requestID, isset($allowed['Sick']) ? 'Choose Sick or PTO.' : 'Choose PTO.');
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
$hoursVal = null;
if (!$partDay) {
    $startTime = $endTime = ''; // full day
} elseif ($partMode === 'hours') {
    if (!is_numeric($hoursRaw) || (float) $hoursRaw <= 0 || (float) $hoursRaw > 24) {
        rejectEdit($requestID, 'Enter the hours you will miss: more than 0 and at most 24.');
    }
    $hoursVal  = round((float) $hoursRaw, 2);
    $startTime = $endTime = ''; // hours missed, no time window
} elseif ($startTime === '' || $endTime === '') {
    rejectEdit($requestID, 'Partial day requires both start time and end time, or choose Hours missed.');
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
    if ($endTime <= $startTime) { // the window repeats each day, so on every day
        rejectEdit($requestID, 'End time must be later than start time.');
    }
}
if (($isApproved && isPayrollLocked($conn, $req['StartDate'])) || isPayrollLocked($conn, $startDate)) {
    rejectEdit($requestID, 'Payroll has already been run for those days. Ask an admin to make the change.');
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
           SET Category=?, StartDate=?, EndDate=?, StartTime=?, EndTime=?, Hours=?, Notes=?, SubmittedAt=?
         WHERE ID = ? AND EmployeeID = ? AND Status = 'Pending'
    ");
    $u->bind_param("sssssdssii", $category, $startDate, $endDate, $startTimeVal, $endTimeVal, $hoursVal, $notesVal, $now, $requestID, $sessionEmpID);
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
        (EmployeeID, Category, StartDate, EndDate, StartTime, EndTime, Hours, Notes, Reason,
         Status, SubmittedAt, AmendsRequestID)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?, ?)
");
$ins->bind_param(
    "isssssdsssi",
    $sessionEmpID, $category, $startDate, $endDate, $startTimeVal, $endTimeVal, $hoursVal,
    $notesVal, $reasonVal, $now, $requestID
);
if (!$ins->execute()) {
    error_log("submit_time_off_edit: amendment insert failed: " . $ins->error);
    header('Location: ../error.php?code=500&message=' . urlencode('Could not submit your amendment.'));
    exit;
}

// Notify every admin who can act on this
$adminAddresses = notificationRecipients($conn, 'timeoff');

$empInfo = $conn->prepare("SELECT FirstName, LastName FROM users WHERE ID = ?");
$empInfo->bind_param("i", $sessionEmpID);
$empInfo->execute();
$emp = $empInfo->get_result()->fetch_assoc();
$empName = trim(($emp['FirstName'] ?? '') . ' ' . ($emp['LastName'] ?? ''));

$emailStatus = 'not_attempted';
if ($adminAddresses) {
    $origDates = $req['StartDate'] === $req['EndDate']
        ? date('m/d/Y', strtotime($req['StartDate']))
        : date('m/d/Y', strtotime($req['StartDate'])) . ' &ndash; ' . date('m/d/Y', strtotime($req['EndDate']));
    $newDates = $startDate === $endDate
        ? date('m/d/Y', strtotime($startDate))
        : date('m/d/Y', strtotime($startDate)) . ' &ndash; ' . date('m/d/Y', strtotime($endDate));
    $origTimes = ($req['StartTime'] && $req['EndTime'])
        ? date('g:i a', strtotime($req['StartTime'])) . ' &ndash; ' . date('g:i a', strtotime($req['EndTime']))
        : ((float) ($req['Hours'] ?? 0) > 0
            ? m365HoursPhrase((float) $req['Hours'], $req['StartDate'] !== $req['EndDate']) . ' missed'
            : 'all day');
    $newTimes = ($hasStartTime && $hasEndTime)
        ? date('g:i a', strtotime($startTime)) . ' &ndash; ' . date('g:i a', strtotime($endTime))
        : ($hoursVal !== null ? m365HoursPhrase($hoursVal, $startDate !== $endDate) . ' missed' : 'all day');

    $subject = "Time-Off Amendment Submitted &ndash; " . htmlspecialchars($empName);
    $body  = "<p><strong>{$empName}</strong> has submitted an amendment to an approved time-off request.</p>";
    $body .= "<table cellpadding='4' cellspacing='0' border='1' style='border-collapse:collapse;'>";
    $body .= "<tr><th></th><th>Original (approved)</th><th>Requested change</th></tr>";
    $body .= "<tr><td>Category</td><td>" . htmlspecialchars(timeOffLabel($req['Category'])) . "</td><td>" . htmlspecialchars(timeOffLabel($category)) . "</td></tr>";
    $body .= "<tr><td>Dates</td><td>{$origDates}</td><td>{$newDates}</td></tr>";
    $body .= "<tr><td>Times</td><td>{$origTimes}</td><td>{$newTimes}</td></tr>";
    $body .= "<tr><td>Notes</td><td>" . nl2br(htmlspecialchars($req['Notes'] ?? '')) . "</td><td>" . nl2br(htmlspecialchars($notesVal ?? '')) . "</td></tr>";
    $body .= "</table>";
    $body .= "<p><strong>Reason for change:</strong> " . nl2br(htmlspecialchars($reasonVal ?? '')) . "</p>";
    $reviewUrl = appUrl('/admin/edits_timesheet.php', $conn);
    $body .= "<p>Review in the admin panel: <a href=\"{$reviewUrl}\">Pending Approvals</a></p>";

    $emailStatus = sendTimeOffEmail($conn, $adminAddresses, $subject, $body);
}

header("Location: time_off.php?status=amendment_submitted&email_status=" . urlencode($emailStatus));
exit;
