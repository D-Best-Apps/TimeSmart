<?php 
session_start();
require '../auth/db.php';
date_default_timezone_set('America/Chicago');

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;

// Permission check
require_once __DIR__ . '/../functions/check_permission.php';
requirePermission('approve_edits');
}

// Fetch all pending edits with user info
$stmt = $conn->prepare("SELECT pe.*, u.FirstName, u.LastName FROM pending_edits pe 
                        JOIN users u ON pe.EmployeeID = u.ID 
                        WHERE pe.Status = 'Pending' 
                        ORDER BY pe.SubmittedAt DESC");
$stmt->execute();
$result = $stmt->get_result();

$edits = [];
while ($row = $result->fetch_assoc()) {
    $empID = $row['EmployeeID'];
    $date = $row['Date'];

    // Get original row from timepunches
    $origStmt = $conn->prepare("SELECT * FROM timepunches WHERE EmployeeID = ? AND Date = ?");
    $origStmt->bind_param("is", $empID, $date);
    $origStmt->execute();
    $original = $origStmt->get_result()->fetch_assoc();

    if (!$original) continue;

    // Check the pending_edits
    foreach (['TimeIN', 'LunchStart', 'LunchEnd', 'TimeOut'] as $field) {
        if (array_key_exists($field, $row) && !is_null($row[$field]) && $row[$field] !== '' && $row[$field] !== $original[$field]) {
            $edits[] = [
                'ID' => $row['ID'],
                'FirstName' => $row['FirstName'],
                'LastName' => $row['LastName'],
                'Date' => $date,
                'Field' => $field,
                'Original' => $original[$field] ?? '',
                'Requested' => $row[$field],
                'Note' => $row['Note'],
                'Reason' => $row['Reason'],
            ];
        }
    }
}

// Pending time-off requests
$torStmt = $conn->prepare("
    SELECT tor.*, u.FirstName, u.LastName
      FROM time_off_requests tor
      JOIN users u ON u.ID = tor.EmployeeID
     WHERE tor.Status = 'Pending'
     ORDER BY tor.SubmittedAt ASC
");
$torStmt->execute();
$timeOffRequests = $torStmt->get_result()->fetch_all(MYSQLI_ASSOC);

function formatTorDateRange(string $start, string $end): string {
    if ($start === $end) return date('m/d/Y', strtotime($start));
    return date('m/d/Y', strtotime($start)) . ' – ' . date('m/d/Y', strtotime($end));
}
function formatTorTimeRange(?string $start, ?string $end): string {
    if (!$start || !$end) return 'all day';
    return date('g:i a', strtotime($start)) . ' – ' . date('g:i a', strtotime($end));
}

$pageTitle = "Pending Approvals";
require_once 'header.php';
?>
<link rel="stylesheet" href="../css/edits.css" />


<nav>
    <a href="dashboard.php">Dashboard</a>
    <a href="view_punches.php">Timesheets</a>
    <a href="summary.php">Summary</a>
    <a href="reports.php">Reports</a>
    <a href="manage_users.php">Users</a>
    <a href="manage_offices.php">Offices</a>
    <a href="attendance.php">Attendance</a>
    <a href="manage_admins.php">Admins</a>
    <a href="../logout.php">Logout</a>
</nav>

<div class="dashboard-container">
    <h2 style="margin-top:0;">Timesheet Edit Requests</h2>
    <?php if (count($edits) === 0): ?>
        <p class="no-edits">✅ No pending time edits to review at the moment.</p>
    <?php else: ?>
        <form method="POST" action="process_edits.php">
            <table class="approval-table">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Date</th>
                        <th>Field</th>
                        <th>Original</th>
                        <th>Requested</th>
                        <th>Note</th>
                        <th>Reason</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($edits as $edit): ?>
                        <tr>
                            <td><?= htmlspecialchars($edit['FirstName'] . ' ' . $edit['LastName']) ?></td>
                            <td><?= htmlspecialchars($edit['Date']) ?></td>
                            <td><strong><?= htmlspecialchars($edit['Field']) ?></strong></td>
                            <td><?= htmlspecialchars($edit['Original']) ?></td>
                            <td style="color:#0078D7;"><strong><?= htmlspecialchars($edit['Requested']) ?></strong></td>
                            <td><?= htmlspecialchars($edit['Note']) ?: '-' ?></td>
                            <td class="note-box"><?= htmlspecialchars($edit['Reason']) ?></td>
                            <td class="action-buttons">
                                <button type="submit" class="approve-btn" name="action[<?= $edit['ID'] ?>][<?= $edit['Field'] ?>]" value="approve">Approve</button>
                                <button type="submit" class="reject-btn" name="action[<?= $edit['ID'] ?>][<?= $edit['Field'] ?>]" value="reject">Reject</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </form>
    <?php endif; ?>

    <h2 style="margin-top:2rem;">Time-Off Requests</h2>
    <?php if (count($timeOffRequests) === 0): ?>
        <p class="no-edits">✅ No pending time-off requests at the moment.</p>
    <?php else: ?>
        <form method="POST" action="process_time_off.php">
            <table class="approval-table">
                <thead>
                    <tr>
                        <th>Submitted</th>
                        <th>Employee</th>
                        <th>Category</th>
                        <th>Dates</th>
                        <th>Times</th>
                        <th>Notes</th>
                        <th>Reviewer Note</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($timeOffRequests as $tor): ?>
                        <tr>
                            <td><?= htmlspecialchars(date('m/d/Y', strtotime($tor['SubmittedAt']))) ?></td>
                            <td><?= htmlspecialchars($tor['FirstName'] . ' ' . $tor['LastName']) ?></td>
                            <td><strong><?= htmlspecialchars($tor['Category']) ?></strong></td>
                            <td><?= formatTorDateRange($tor['StartDate'], $tor['EndDate']) ?></td>
                            <td><?= formatTorTimeRange($tor['StartTime'], $tor['EndTime']) ?></td>
                            <td class="note-box"><?= nl2br(htmlspecialchars($tor['Notes'] ?? '')) ?: '-' ?></td>
                            <td>
                                <input type="text" name="review_note[<?= (int) $tor['ID'] ?>]" maxlength="500" placeholder="Optional" style="width:100%; padding:4px;">
                            </td>
                            <td class="action-buttons">
                                <button type="submit" class="approve-btn" name="action[<?= (int) $tor['ID'] ?>]" value="approve">Approve</button>
                                <button type="submit" class="reject-btn"  name="action[<?= (int) $tor['ID'] ?>]" value="reject">Reject</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </form>
    <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>
