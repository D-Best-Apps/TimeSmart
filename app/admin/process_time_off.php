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

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['action'])) {
    header("Location: edits_timesheet.php");
    exit;
}

$admin = $_SESSION['admin'];
$now   = date('Y-m-d H:i:s');
$reviewNotes = $_POST['review_note'] ?? [];
$privateNotes = $_POST['tor_private_note'] ?? [];
$canViewPrivate = canViewPrivateNotes($conn);
$m365Failures = [];

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

    // Load the (pending) request joined with the user and any original it amends
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

    $isAmendment   = !empty($req['AmendsRequestID']);
    $originalID    = $isAmendment ? (int) $req['AmendsRequestID'] : null;
    $employeeName  = trim(($req['FirstName'] ?? '') . ' ' . ($req['LastName'] ?? ''));

    $note = trim($reviewNotes[$requestID] ?? '');
    if ($note !== '' && mb_strlen($note) > 500) {
        $note = mb_substr($note, 0, 500);
    }
    $noteVal = $note !== '' ? $note : null;

    // Admin-only private note (never emailed). Only writable by permitted admins.
    $privVal = null;
    if ($canViewPrivate) {
        $priv = trim($privateNotes[$requestID] ?? '');
        if ($priv !== '' && mb_strlen($priv) > 500) {
            $priv = mb_substr($priv, 0, 500);
        }
        $privVal = $priv !== '' ? $priv : null;
    }

    // Mark the (amendment or normal) request row decided. Admins without
    // private-note access leave AdminPrivateNote untouched.
    if ($canViewPrivate) {
        $update = $conn->prepare("
            UPDATE time_off_requests
               SET Status = ?, ReviewedAt = ?, ReviewedBy = ?, ReviewNote = ?, AdminPrivateNote = ?
             WHERE ID = ? AND Status = 'Pending'
        ");
        $update->bind_param("sssssi", $decision, $now, $admin, $noteVal, $privVal, $requestID);
    } else {
        $update = $conn->prepare("
            UPDATE time_off_requests
               SET Status = ?, ReviewedAt = ?, ReviewedBy = ?, ReviewNote = ?
             WHERE ID = ? AND Status = 'Pending'
        ");
        $update->bind_param("ssssi", $decision, $now, $admin, $noteVal, $requestID);
    }
    if (!$update->execute() || $update->affected_rows === 0) {
        continue; // Race — another tab already decided this one
    }

    // === Amendment-approval path ===
    // 1. Copy the amendment's new values onto the original row
    // 2. Delete the old M365 event, create a new one against the original row
    if ($decision === 'Approved' && $isAmendment && $originalID !== null) {
        if ($canViewPrivate) {
            // Also carry the private note onto the original row so it stays visible
            // in the "Recent Approved Time-Off" list and the edit page.
            $copy = $conn->prepare("
                UPDATE time_off_requests
                   SET Category=?, StartDate=?, EndDate=?, StartTime=?, EndTime=?, Notes=?, AdminPrivateNote=?
                 WHERE ID = ? AND Status = 'Approved'
            ");
            $copy->bind_param(
                "sssssssi",
                $req['Category'], $req['StartDate'], $req['EndDate'],
                $req['StartTime'], $req['EndTime'], $req['Notes'], $privVal,
                $originalID
            );
        } else {
            $copy = $conn->prepare("
                UPDATE time_off_requests
                   SET Category=?, StartDate=?, EndDate=?, StartTime=?, EndTime=?, Notes=?
                 WHERE ID = ? AND Status = 'Approved'
            ");
            $copy->bind_param(
                "ssssssi",
                $req['Category'], $req['StartDate'], $req['EndDate'],
                $req['StartTime'], $req['EndTime'], $req['Notes'],
                $originalID
            );
        }
        $copy->execute();

        // Reload the (now updated) original to get its current M365EventId
        $origStmt = $conn->prepare("
            SELECT tor.*, u.FirstName, u.LastName, u.Email
              FROM time_off_requests tor
              JOIN users u ON u.ID = tor.EmployeeID
             WHERE tor.ID = ?
        ");
        $origStmt->bind_param("i", $originalID);
        $origStmt->execute();
        $origRow = $origStmt->get_result()->fetch_assoc();

        // Sync: delete the old event, create a new one
        $config = m365GetConfig($conn);
        if ($config !== null) {
            $tok = m365GetToken($config);
            if ($tok['success'] && !empty($config['m365_calendar_mailbox'])) {
                $event = m365BuildEvent($origRow, $employeeName, $config['m365_timezone']);
                $result = m365ReplaceMailboxEvent(
                    $config['m365_calendar_mailbox'],
                    $tok['token'],
                    $origRow['M365EventId'] ?? null,
                    $event
                );

                $newEventId   = $result['success'] ? $result['eventId'] : null;
                $newSyncState = $result['success']
                    ? 'sent'
                    : 'error:' . ($result['error'] ?? 'unknown');

                $syncUpdate = $conn->prepare("
                    UPDATE time_off_requests
                       SET M365EventId = ?, M365SyncStatus = ?, M365SyncAt = ?
                     WHERE ID = ?
                ");
                $syncUpdate->bind_param("sssi", $newEventId, $newSyncState, $now, $originalID);
                $syncUpdate->execute();

                if (!$result['success']) {
                    $m365Failures[] = htmlspecialchars($employeeName) . ' (amendment): ' . $result['error'];
                }
            } elseif (!$tok['success']) {
                error_log("process_time_off: amendment sync skipped — token failed: " . $tok['error']);
            }
        }
    }
    // === Normal new-request approval path ===
    elseif ($decision === 'Approved') {
        $sync = m365SyncApprovedRequest($conn, $req, $employeeName);

        $eventId   = $sync['success'] ? $sync['eventId'] : null;
        $syncState = $sync['success']
            ? 'sent'
            : (!empty($sync['skipped']) ? 'skipped' : 'error:' . ($sync['error'] ?? 'unknown'));

        $syncUpdate = $conn->prepare("
            UPDATE time_off_requests
               SET M365EventId = ?, M365SyncStatus = ?, M365SyncAt = ?
             WHERE ID = ?
        ");
        $syncUpdate->bind_param("sssi", $eventId, $syncState, $now, $requestID);
        $syncUpdate->execute();

        if (!$sync['success'] && empty($sync['skipped'])) {
            $m365Failures[] = htmlspecialchars($employeeName) . ': ' . $sync['error'];
        }
    }

    // Notify employee (best-effort)
    $employeeEmail = trim($req['Email'] ?? '');
    if ($employeeEmail !== '') {
        $datesLabel = formatDateRange($req['StartDate'], $req['EndDate']);
        $timesLabel = formatTimeRange($req['StartTime'], $req['EndTime']);
        $subjectKind = $isAmendment ? "Time-Off Amendment" : "Time-Off Request";

        $subject = "Your {$subjectKind} was {$decision}";
        $body  = "<p>Hi " . htmlspecialchars($req['FirstName']) . ",</p>";
        $body .= "<p>Your " . strtolower($subjectKind) . " has been <strong>{$decision}</strong>.</p>";
        $body .= "<ul>";
        $body .= "<li><strong>Category:</strong> " . htmlspecialchars($req['Category']) . "</li>";
        $body .= "<li><strong>Dates:</strong> " . htmlspecialchars($datesLabel) . "</li>";
        $body .= "<li><strong>Times:</strong> " . htmlspecialchars($timesLabel) . "</li>";
        if (!empty($req['Notes'])) {
            $body .= "<li><strong>Your notes:</strong> " . nl2br(htmlspecialchars($req['Notes'])) . "</li>";
        }
        if ($isAmendment && !empty($req['Reason'])) {
            $body .= "<li><strong>Your reason for the change:</strong> " . nl2br(htmlspecialchars($req['Reason'])) . "</li>";
        }
        if ($noteVal !== null) {
            $body .= "<li><strong>Reviewer note:</strong> " . nl2br(htmlspecialchars($noteVal)) . "</li>";
        }
        $body .= "<li><strong>Reviewed by:</strong> " . htmlspecialchars($admin) . "</li>";
        $body .= "</ul>";

        sendTimeOffEmail($conn, $employeeEmail, $subject, $body);
    }
}

$query = '';
if (!empty($m365Failures)) {
    $query = '?m365_sync=failed&details=' . urlencode(implode('; ', $m365Failures));
}
header("Location: edits_timesheet.php{$query}");
exit;
