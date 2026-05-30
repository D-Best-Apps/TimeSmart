<?php
session_start();
require '../auth/db.php';
require_once __DIR__ . '/../functions/time_off_hours.php';
date_default_timezone_set('America/Chicago');

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

// Permission check
require_once __DIR__ . '/../functions/check_permission.php';
requirePermission('approve_edits');

// Whether this admin may see/write admin-only private notes
$canViewPrivate = canViewPrivateNotes($conn);

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
                'AdminPrivateNote' => $row['AdminPrivateNote'] ?? '',
            ];
        }
    }
}

// Pending time-off requests (includes amendments — left-joined original for diff display)
$torStmt = $conn->prepare("
    SELECT tor.*, u.FirstName, u.LastName,
           orig.Category   AS Orig_Category,
           orig.StartDate  AS Orig_StartDate,
           orig.EndDate    AS Orig_EndDate,
           orig.StartTime  AS Orig_StartTime,
           orig.EndTime    AS Orig_EndTime,
           orig.Notes      AS Orig_Notes
      FROM time_off_requests tor
      JOIN users u ON u.ID = tor.EmployeeID
      LEFT JOIN time_off_requests orig ON orig.ID = tor.AmendsRequestID
     WHERE tor.Status = 'Pending'
     ORDER BY tor.SubmittedAt ASC
");
$torStmt->execute();
$timeOffRequests = $torStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Recently approved time-off (last 60 days) so admin has a discovery path for edits
$recentStmt = $conn->prepare("
    SELECT tor.*, u.FirstName, u.LastName
      FROM time_off_requests tor
      JOIN users u ON u.ID = tor.EmployeeID
     WHERE tor.Status = 'Approved'
       AND tor.AmendsRequestID IS NULL
       AND tor.StartDate >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
     ORDER BY tor.StartDate DESC
     LIMIT 30
");
$recentStmt->execute();
$recentApprovedTimeOff = $recentStmt->get_result()->fetch_all(MYSQLI_ASSOC);

function formatTorDateRange(string $start, string $end): string {
    if ($start === $end) return date('m/d/Y', strtotime($start));
    return date('m/d/Y', strtotime($start)) . ' – ' . date('m/d/Y', strtotime($end));
}
function formatTorTimeRange(?string $start, ?string $end): string {
    if (!$start || !$end) return 'all day';
    return date('g:i a', strtotime($start)) . ' – ' . date('g:i a', strtotime($end));
}
function fmtHM(float $h): string {
    $m = (int) round($h * 60);
    return sprintf('%d:%02d', intdiv($m, 60), $m % 60);
}

// Pre-compute projected weekly hours for each pending time-off request so admins see the OT impact
$projections = [];
foreach ($timeOffRequests as $tor) {
    $exclude = !empty($tor['AmendsRequestID']) ? (int) $tor['AmendsRequestID'] : null;
    $projections[(int) $tor['ID']] = projectedWeeklyHours(
        $conn,
        (int) $tor['EmployeeID'],
        $tor['StartDate'],
        $tor['EndDate'],
        $tor['StartTime'] ?: null,
        $tor['EndTime']   ?: null,
        $exclude
    );
}

$pageTitle = "Pending Approvals";
require_once 'header.php';
?>
<link rel="stylesheet" href="../css/edits_timesheet.css?v=2" />


<div class="edits-page">
    <?php if (($_GET['m365_sync'] ?? '') === 'failed'): ?>
        <div style="background-color:#fff3cd; border:1px solid #ffeeba; color:#856404; padding:0.75rem 1rem; border-radius:4px; margin-bottom:1rem;">
            <strong>M365 calendar sync failed for one or more approvals.</strong><br>
            <?= htmlspecialchars($_GET['details'] ?? '') ?><br>
            <em>The approval went through. The calendar event was not created. Check <a href="settings.php#m365">M365 Settings</a> or the server error log.</em>
        </div>
    <?php endif; ?>

    <h2 style="margin-top:0;">Timesheet Edit Requests</h2>
    <?php if (count($edits) === 0): ?>
        <p class="no-edits">✅ No pending time edits to review at the moment.</p>
    <?php else: ?>
        <form method="POST" action="process_edits.php">
            <div class="table-scroll">
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
                        <?php if ($canViewPrivate): ?><th>Private Note</th><?php endif; ?>
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
                            <?php if ($canViewPrivate): ?>
                            <td>
                                <textarea name="private_note[<?= (int) $edit['ID'] ?>]" maxlength="2000" rows="2" placeholder="Admin-only — not shown to employee" style="width:160px; padding:4px;"><?= htmlspecialchars($edit['AdminPrivateNote']) ?></textarea>
                            </td>
                            <?php endif; ?>
                            <td class="action-buttons">
                                <button type="submit" class="approve-btn" name="action[<?= $edit['ID'] ?>][<?= $edit['Field'] ?>]" value="approve">Approve</button>
                                <button type="submit" class="reject-btn" name="action[<?= $edit['ID'] ?>][<?= $edit['Field'] ?>]" value="reject">Reject</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </form>
    <?php endif; ?>

    <h2 style="margin-top:2rem;">Time-Off Requests</h2>
    <?php if (count($timeOffRequests) === 0): ?>
        <p class="no-edits">✅ No pending time-off requests at the moment.</p>
    <?php else: ?>
        <form method="POST" action="process_time_off.php">
            <div class="table-scroll">
            <table class="approval-table">
                <thead>
                    <tr>
                        <th>Submitted</th>
                        <th>Employee</th>
                        <th>Category</th>
                        <th>Dates</th>
                        <th>Times</th>
                        <th>Notes</th>
                        <th>Projected wk</th>
                        <th>Reviewer Note</th>
                        <?php if ($canViewPrivate): ?><th>Private Note</th><?php endif; ?>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($timeOffRequests as $tor): ?>
                        <?php
                          $isAmendment = !empty($tor['AmendsRequestID']);
                          $rowProj = $projections[(int) $tor['ID']] ?? [];
                          $maxProj = 0;
                          foreach ($rowProj as $w) { if ($w['projected'] > $maxProj) $maxProj = $w['projected']; }
                          $overLimit = $maxProj > 40;
                          $bg = $overLimit ? '#ffd6d6' : ($isAmendment ? '#fff8e1' : '');
                        ?>
                        <tr<?= $bg ? ' style="background-color:' . $bg . ';"' : '' ?>>
                            <td><?= htmlspecialchars(date('m/d/Y', strtotime($tor['SubmittedAt']))) ?></td>
                            <td>
                                <?= htmlspecialchars($tor['FirstName'] . ' ' . $tor['LastName']) ?>
                                <?php if ($isAmendment): ?>
                                    <br><span style="display:inline-block; padding:1px 6px; border-radius:3px; background-color:#ffc107; color:#000; font-size:0.7rem; font-weight:bold;">AMENDMENT</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isAmendment && $tor['Orig_Category'] !== $tor['Category']): ?>
                                    <s style="color:#999;"><?= htmlspecialchars($tor['Orig_Category']) ?></s><br>
                                    <strong><?= htmlspecialchars($tor['Category']) ?></strong>
                                <?php else: ?>
                                    <strong><?= htmlspecialchars($tor['Category']) ?></strong>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                  $newDates = formatTorDateRange($tor['StartDate'], $tor['EndDate']);
                                  $origDates = $isAmendment ? formatTorDateRange($tor['Orig_StartDate'], $tor['Orig_EndDate']) : null;
                                ?>
                                <?php if ($isAmendment && $origDates !== $newDates): ?>
                                    <s style="color:#999;"><?= $origDates ?></s><br>
                                    <strong><?= $newDates ?></strong>
                                <?php else: ?>
                                    <?= $newDates ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                  $newTimes  = formatTorTimeRange($tor['StartTime'], $tor['EndTime']);
                                  $origTimes = $isAmendment ? formatTorTimeRange($tor['Orig_StartTime'], $tor['Orig_EndTime']) : null;
                                ?>
                                <?php if ($isAmendment && $origTimes !== $newTimes): ?>
                                    <s style="color:#999;"><?= $origTimes ?></s><br>
                                    <strong><?= $newTimes ?></strong>
                                <?php else: ?>
                                    <?= $newTimes ?>
                                <?php endif; ?>
                            </td>
                            <td class="note-box">
                                <?= nl2br(htmlspecialchars($tor['Notes'] ?? '')) ?: '-' ?>
                                <?php if ($isAmendment && !empty($tor['Reason'])): ?>
                                    <div style="margin-top:0.3rem; font-size:0.85rem; color:#555;"><em>Reason for change:</em> <?= nl2br(htmlspecialchars($tor['Reason'])) ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:0.85rem; white-space:nowrap;">
                                <?php foreach ($rowProj as $w): ?>
                                    <?php $isOver = $w['projected'] > 40; ?>
                                    <div<?= $isOver ? ' style="color:#b02a37; font-weight:bold;"' : '' ?>>
                                        wk of <?= date('m/d', strtotime($w['weekStart'])) ?>:
                                        <?= fmtHM($w['projected']) ?> / 40<?= $isOver ? ' ⚠' : '' ?>
                                    </div>
                                <?php endforeach; ?>
                            </td>
                            <td>
                                <input type="text" name="review_note[<?= (int) $tor['ID'] ?>]" maxlength="500" placeholder="Optional" style="width:100%; padding:4px;">
                            </td>
                            <?php if ($canViewPrivate): ?>
                            <td>
                                <textarea name="tor_private_note[<?= (int) $tor['ID'] ?>]" maxlength="500" rows="2" placeholder="Admin-only — not emailed" style="width:160px; padding:4px;"><?= htmlspecialchars($tor['AdminPrivateNote'] ?? '') ?></textarea>
                            </td>
                            <?php endif; ?>
                            <td class="action-buttons">
                                <button type="submit" class="approve-btn" name="action[<?= (int) $tor['ID'] ?>]" value="approve">Approve</button>
                                <button type="submit" class="reject-btn"  name="action[<?= (int) $tor['ID'] ?>]" value="reject">Reject</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </form>
    <?php endif; ?>

    <h2 style="margin-top:2rem;">Recent Approved Time-Off <span style="font-size:0.6em; color:#666;">(last 60 days &mdash; use to adjust hours after the fact)</span></h2>
    <?php if (count($recentApprovedTimeOff) === 0): ?>
        <p class="no-edits">No approved time-off in the last 60 days.</p>
    <?php else: ?>
        <div class="table-scroll">
        <table class="approval-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Category</th>
                    <th>Dates</th>
                    <th>Times</th>
                    <th>Notes</th>
                    <?php if ($canViewPrivate): ?><th>Private Note</th><?php endif; ?>
                    <th>M365 Synced</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentApprovedTimeOff as $tor): ?>
                    <tr>
                        <td><?= htmlspecialchars($tor['FirstName'] . ' ' . $tor['LastName']) ?></td>
                        <td><strong><?= htmlspecialchars($tor['Category']) ?></strong></td>
                        <td><?= formatTorDateRange($tor['StartDate'], $tor['EndDate']) ?></td>
                        <td><?= formatTorTimeRange($tor['StartTime'], $tor['EndTime']) ?></td>
                        <td class="note-box"><?= nl2br(htmlspecialchars($tor['Notes'] ?? '')) ?: '-' ?></td>
                        <?php if ($canViewPrivate): ?>
                        <td class="note-box" style="color:#555;"><?= nl2br(htmlspecialchars($tor['AdminPrivateNote'] ?? '')) ?: '-' ?></td>
                        <?php endif; ?>
                        <td style="font-size:0.85rem;"><?= $tor['M365SyncStatus'] === 'sent' ? '✓' : htmlspecialchars($tor['M365SyncStatus'] ?? '-') ?></td>
                        <td>
                            <a href="edit_time_off.php?id=<?= (int) $tor['ID'] ?>" class="btn small secondary">Edit</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>
