<?php
session_start();
require '../auth/db.php';
require_once __DIR__ . '/../functions/check_permission.php';
require_once __DIR__ . '/../functions/time_off_email.php';
date_default_timezone_set('America/Chicago');

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}
requirePermission('approve_edits');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['action'])) {
    header("Location: edits_timesheet.php");
    exit;
}

$admin = $_SESSION['admin'];
$now   = date('Y-m-d H:i:s');
$reviewNotes = $_POST['review_note'] ?? [];

function formatDateRange(string $start, string $end): string {
    if ($start === $end) return date('m/d/Y', strtotime($start));
    return date('m/d/Y', strtotime($start)) . ' – ' . date('m/d/Y', strtotime($end));
}
function formatTimeRange(?string $start, ?string $end): string {
    if (!$start || !$end) return 'all day';
    return date('g:i a', strtotime($start)) . ' – ' . date('g:i a', strtotime($end));
}

foreach ($_POST['action'] as $requestID => $action) {
    $requestID = (int) $requestID;
    if ($requestID === 0) continue;
    if ($action !== 'approve' && $action !== 'reject') continue;

    $decision = ($action === 'approve') ? 'Approved' : 'Rejected';

    // Load the request (must still be Pending — guard against races)
    $stmt = $conn->prepare("
        SELECT tor.*, u.FirstName, u.LastName, u.Email
          FROM time_off_requests tor
          JOIN users u ON u.ID = tor.EmployeeID
         WHERE tor.ID = ? AND tor.Status = 'Pending'
    ");
    $stmt->bind_param("i", $requestID);
    $stmt->execute();
    $req = $stmt->get_result()->fetch_assoc();
    if (!$req) continue;

    $note = trim($reviewNotes[$requestID] ?? '');
    if ($note !== '' && mb_strlen($note) > 500) {
        $note = mb_substr($note, 0, 500);
    }
    $noteVal = $note !== '' ? $note : null;

    $update = $conn->prepare("
        UPDATE time_off_requests
           SET Status = ?, ReviewedAt = ?, ReviewedBy = ?, ReviewNote = ?
         WHERE ID = ? AND Status = 'Pending'
    ");
    $update->bind_param("ssssi", $decision, $now, $admin, $noteVal, $requestID);
    if (!$update->execute() || $update->affected_rows === 0) {
        continue; // Another tab already decided this one
    }

    // Notify employee (best-effort; never block on email failure)
    $employeeEmail = trim($req['Email'] ?? '');
    if ($employeeEmail !== '') {
        $datesLabel = formatDateRange($req['StartDate'], $req['EndDate']);
        $timesLabel = formatTimeRange($req['StartTime'], $req['EndTime']);

        $subject = "Your Time-Off Request was {$decision}";
        $body  = "<p>Hi " . htmlspecialchars($req['FirstName']) . ",</p>";
        $body .= "<p>Your time-off request has been <strong>{$decision}</strong>.</p>";
        $body .= "<ul>";
        $body .= "<li><strong>Category:</strong> " . htmlspecialchars($req['Category']) . "</li>";
        $body .= "<li><strong>Dates:</strong> " . htmlspecialchars($datesLabel) . "</li>";
        $body .= "<li><strong>Times:</strong> " . htmlspecialchars($timesLabel) . "</li>";
        if (!empty($req['Notes'])) {
            $body .= "<li><strong>Your notes:</strong> " . nl2br(htmlspecialchars($req['Notes'])) . "</li>";
        }
        if ($noteVal !== null) {
            $body .= "<li><strong>Reviewer note:</strong> " . nl2br(htmlspecialchars($noteVal)) . "</li>";
        }
        $body .= "<li><strong>Reviewed by:</strong> " . htmlspecialchars($admin) . "</li>";
        $body .= "</ul>";

        sendTimeOffEmail($conn, $employeeEmail, $subject, $body);
    }
}

header("Location: edits_timesheet.php");
exit;
