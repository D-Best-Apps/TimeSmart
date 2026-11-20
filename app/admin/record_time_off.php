<?php
// admin/record_time_off.php — an admin records time off or an absence for an employee:
// PTO, Sick (when the company offers it), or an excused or unexcused absence with the
// reason (e.g. a no-show they called about later). Past or future dates. Saved as
// already approved, so it shows straight away on the Home board, Attendance, Payroll
// Totals and the employee's own Time Off page.
// PTO/Sick pay hours the same way as an approved employee request; an absence pays
// nothing but keeps the day from being flagged as a no-show. Part of a day is either a
// time window (StartTime/EndTime) or a number of hours missed (Hours, no times).
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/../functions/check_permission.php';
requirePermission('approve_edits');
require_once __DIR__ . '/../functions/csrf.php';
require_once __DIR__ . '/../functions/time_off_hours.php';
require_once __DIR__ . '/../functions/pay_rules.php';     // isPayrollLocked()
require_once __DIR__ . '/../functions/m365_calendar.php'; // m365SyncApprovedRequest()
require_once __DIR__ . '/../functions/time_off_email.php';

$admin = (string) $_SESSION['admin'];
$canViewPrivate = canViewPrivateNotes($conn);
$errors = [];
$isDate = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)
    && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4));
$isTime = fn($v) => is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $v);
// m365HoursPhrase() (m365_calendar.php): "2 hours", "1.5 hours per day" for a part day entered as hours

$categories = timeOffCategories(true); // no Sick when the company has no sick time
// Archived employees stay pickable (e.g. a leaver's last days), marked and listed last
$employees = $conn->query("SELECT ID, FirstName, LastName, Email, ArchivedAt FROM users ORDER BY ArchivedAt IS NOT NULL, LastName, FirstName")->fetch_all(MYSQLI_ASSOC);
$empById = array_column($employees, null, 'ID');

// Form values: POST on a failed save, else prefilled from the link (?emp=&date=&type=)
$f = [
    'EmployeeID' => (int) ($_POST['EmployeeID'] ?? $_GET['emp'] ?? 0),
    'Category'   => (string) ($_POST['Category'] ?? $_GET['type'] ?? 'PTO'),
    'StartDate'  => (string) ($_POST['StartDate'] ?? $_GET['date'] ?? date('Y-m-d')),
    'EndDate'    => (string) ($_POST['EndDate'] ?? $_GET['date'] ?? date('Y-m-d')),
    'PartDay'    => !empty($_POST['PartDay']),
    'PartMode'   => ($_POST['PartMode'] ?? '') === 'hours' ? 'hours' : 'times',
    'Hours'      => trim((string) ($_POST['Hours'] ?? '')),
    'StartTime'  => (string) ($_POST['StartTime'] ?? ''),
    'EndTime'    => (string) ($_POST['EndTime'] ?? ''),
    'Notes'      => trim((string) ($_POST['Notes'] ?? '')),
    'Private'    => trim((string) ($_POST['AdminPrivateNote'] ?? '')),
    'Email'      => !empty($_POST['EmailEmployee']),
];
$badCategory = !isset($categories[$f['Category']]); // unknown, or Sick while the company has no sick time
if ($badCategory) {
    $f['Category'] = 'PTO';
}
if (!$isDate($f['StartDate'])) $f['StartDate'] = date('Y-m-d');
if (!$isDate($f['EndDate']))   $f['EndDate'] = $f['StartDate'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf('record_time_off.php');
    $emp = $empById[$f['EmployeeID']] ?? null;
    if (!$emp) {
        $errors[] = 'Choose the employee.';
    }
    if ($badCategory) {
        $errors[] = 'Choose the type of time off.';
    }
    if ($f['EndDate'] < $f['StartDate']) {
        $errors[] = 'The end date is before the start date.';
    } elseif ((new DateTime($f['StartDate']))->diff(new DateTime($f['EndDate']))->days > 366) {
        $errors[] = 'Record at most a year at a time.';
    }
    $startTime = $endTime = $hours = null;
    if ($f['PartDay'] && $f['PartMode'] === 'hours') {
        if (!is_numeric($f['Hours']) || (float) $f['Hours'] <= 0 || (float) $f['Hours'] > 24) {
            $errors[] = 'For hours missed, enter a number of hours more than 0 and at most 24.';
        } else {
            $hours = round((float) $f['Hours'], 2);
        }
    } elseif ($f['PartDay']) {
        if (!$isTime($f['StartTime']) || !$isTime($f['EndTime'])) {
            $errors[] = 'For part of a day, enter both the start and end time.';
        } elseif ($f['EndTime'] <= $f['StartTime']) {
            $errors[] = 'The end time must be after the start time.';
        } else {
            $startTime = strlen($f['StartTime']) === 5 ? $f['StartTime'] . ':00' : $f['StartTime'];
            $endTime   = strlen($f['EndTime']) === 5 ? $f['EndTime'] . ':00' : $f['EndTime'];
        }
    }
    if (in_array($f['Category'], TIME_OFF_UNPAID, true) && $f['Notes'] === '') {
        $errors[] = 'Give the reason for the absence.';
    }
    if (mb_strlen($f['Notes']) > 500 || mb_strlen($f['Private']) > 500) {
        $errors[] = 'Keep the reason and note under 500 characters.';
    }
    if (!$errors && isPayrollLocked($conn, $f['StartDate'])) {
        $errors[] = 'Payroll is closed through ' . date('m/d/Y', strtotime((string) payrollLockedThrough($conn)))
                  . '. Reopen it on Settings → Pay Rules to change those days.';
    }
    if (!$errors) {
        $ov = $conn->prepare("SELECT ID, Category, StartDate, EndDate, Status FROM time_off_requests
                               WHERE EmployeeID = ? AND Status IN ('Pending','Approved')
                                 AND NOT (EndDate < ? OR StartDate > ?) LIMIT 1");
        $ov->bind_param("iss", $f['EmployeeID'], $f['StartDate'], $f['EndDate']);
        $ov->execute();
        if ($o = $ov->get_result()->fetch_assoc()) {
            $what = timeOffLabel($o['Category']) . ' ' . date('m/d', strtotime($o['StartDate']))
                  . ($o['EndDate'] !== $o['StartDate'] ? '–' . date('m/d', strtotime($o['EndDate'])) : '');
            $first = htmlspecialchars($emp['FirstName']);
            $errors[] = $o['Status'] === 'Pending'
                ? "{$first} already asked for time off on those days ({$what}, pending). Approve or change it on Pending Approvals instead."
                : "{$first} already has {$what} on those days. <a href=\"edit_time_off.php?id=" . (int) $o['ID'] . "\">Edit that entry</a> instead.";
        }
    }

    if (!$errors) {
        $now = date('Y-m-d H:i:s');
        $notes = $f['Notes'] !== '' ? $f['Notes'] : null;
        $private = ($canViewPrivate && $f['Private'] !== '') ? $f['Private'] : null;
        $reviewNote = 'Recorded by ' . $admin;
        $st = $conn->prepare("INSERT INTO time_off_requests
            (EmployeeID, Category, StartDate, EndDate, StartTime, EndTime, Hours, Notes, Status, SubmittedAt, ReviewedAt, ReviewedBy, ReviewNote, AdminPrivateNote)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Approved', ?, ?, ?, ?, ?)");
        $st->bind_param("isssssdssssss", $f['EmployeeID'], $f['Category'], $f['StartDate'], $f['EndDate'], $startTime, $endTime,
                        $hours, $notes, $now, $now, $admin, $reviewNote, $private);
        $st->execute();
        $newID = $conn->insert_id;
        $name = $emp['FirstName'] . ' ' . $emp['LastName'];

        // Shared Outlook calendar, the same as an approved employee request (when M365 is set up)
        $row = $conn->query("SELECT * FROM time_off_requests WHERE ID = " . (int) $newID)->fetch_assoc();
        $sync = m365SyncApprovedRequest($conn, $row, $name);
        $syncState = $sync['success'] ? 'sent' : (!empty($sync['skipped']) ? 'skipped' : 'error:' . ($sync['error'] ?? 'unknown'));
        $eventId = $sync['success'] ? $sync['eventId'] : null;
        $up = $conn->prepare("UPDATE time_off_requests SET M365EventId = ?, M365SyncStatus = ?, M365SyncAt = ? WHERE ID = ?");
        $up->bind_param("sssi", $eventId, $syncState, $now, $newID);
        $up->execute();

        if ($f['Email'] && trim((string) $emp['Email']) !== '') {
            $dates = date('m/d/Y', strtotime($f['StartDate'])) . ($f['EndDate'] !== $f['StartDate'] ? ' – ' . date('m/d/Y', strtotime($f['EndDate'])) : '');
            $body = '<p>Hi ' . htmlspecialchars($emp['FirstName']) . ',</p><p>' . htmlspecialchars($admin)
                  . ' recorded time off for you:</p><ul><li><strong>Type:</strong> ' . htmlspecialchars(timeOffLabel($f['Category']))
                  . '</li><li><strong>Dates:</strong> ' . htmlspecialchars($dates) . '</li>'
                  . ($startTime ? '<li><strong>Time:</strong> ' . date('g:i A', strtotime($startTime)) . ' – ' . date('g:i A', strtotime($endTime)) . '</li>' : '')
                  . ($hours ? '<li><strong>Hours:</strong> ' . htmlspecialchars(m365HoursPhrase($hours, $f['StartDate'] !== $f['EndDate'])) . '</li>' : '')
                  . ($notes ? '<li><strong>Reason:</strong> ' . nl2br(htmlspecialchars($notes)) . '</li>' : '')
                  . '</ul><p>You can see it on your Time Off page.</p>';
            sendTimeOffEmail($conn, $emp['Email'], 'Time off recorded: ' . timeOffLabel($f['Category']) . ' ' . $dates, $body);
        }

        $msg = timeOffLabel($f['Category']) . " recorded for {$name}."
             . (!$sync['success'] && empty($sync['skipped']) ? ' (The Outlook calendar update failed: ' . ($sync['error'] ?? 'unknown') . ')' : '');
        header('Location: record_time_off.php?saved=' . urlencode($msg));
        exit;
    }
}

// Recently recorded by admins
$recent = $conn->query("
    SELECT tor.ID, tor.Category, tor.StartDate, tor.EndDate, tor.StartTime, tor.EndTime, tor.Hours, tor.Notes, tor.Status, tor.ReviewedBy, tor.ReviewedAt,
           u.FirstName, u.LastName
      FROM time_off_requests tor
      JOIN users u ON u.ID = tor.EmployeeID
     WHERE tor.ReviewNote LIKE 'Recorded by %'
     ORDER BY tor.ReviewedAt DESC
     LIMIT 15")->fetch_all(MYSQLI_ASSOC);

$pageTitle = "Record Time Off";
require_once 'header.php';
?>
<style>
    .rto-form { max-width: 640px; }
    .rto-form .field { margin-bottom: 1rem; }
    .rto-form .field > label.title { display: block; font-weight: 600; margin-bottom: .3rem; }
    .rto-form .inline { display: flex; flex-wrap: wrap; gap: .5rem 1rem; align-items: center; }
    .rto-form .inline label { display: flex; gap: .4rem; align-items: center; font-weight: normal; margin: 0; }
    .rto-form .inline input[type=radio], .rto-form .inline input[type=checkbox] { width: auto; margin: 0; }
    .rto-form input[type=date], .rto-form input[type=time], .rto-form input[type=number], .rto-form select { width: auto; margin: 0; }
    .rto-form .hint { color: var(--muted-text); font-size: .85rem; margin: .25rem 0 0; }
    .rto-errors { background: #fdecea; color: #b02a37; padding: .6rem .9rem; border-radius: 6px; margin-bottom: 1rem; }
    .rto-errors a { color: inherit; }
    .rto-ok { background: #e7f5ec; color: #146c43; padding: .6rem .9rem; border-radius: 6px; margin-bottom: 1rem; }
    .rto-table { width: 100%; border-collapse: collapse; font-size: .9rem; margin: 0; }
    .rto-table th, .rto-table td { padding: .4rem .6rem; border-bottom: 1px solid #eef1f4; text-align: left; }
    .rto-table th { background: #eef3f7; color: var(--primary-color); }
</style>

<p style="color:var(--muted-text); margin-top:0;">
    Record time off or an absence for an employee, for past or future days. It's saved as approved.
    <?= isset($categories['Sick']) ? 'PTO and Sick are' : 'PTO is' ?> paid like an approved request. An excused or
    unexcused absence pays nothing, but the day no longer shows as a no-show. Employees see these on their Time Off page.
</p>

<?php if (!empty($_GET['saved'])): ?><div class="rto-ok"><?= htmlspecialchars($_GET['saved']) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="rto-errors"><?= implode('<br>', $errors) /* built above from fixed text and escaped names */ ?></div><?php endif; ?>

<form method="POST" class="rto-form">
    <?= csrf_field() ?>
    <div class="field">
        <label class="title" for="rtoEmp">Employee</label>
        <select id="rtoEmp" name="EmployeeID" required>
            <option value="">Choose…</option>
            <?php foreach ($employees as $e): ?>
            <option value="<?= (int) $e['ID'] ?>" <?= (int) $e['ID'] === $f['EmployeeID'] ? 'selected' : '' ?>><?= htmlspecialchars($e['LastName'] . ', ' . $e['FirstName'] . ($e['ArchivedAt'] !== null ? ' (archived)' : '')) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label class="title">Type</label>
        <div class="inline">
            <?php foreach ($categories as $cat => $label): ?>
            <label><input type="radio" name="Category" value="<?= htmlspecialchars($cat) ?>" <?= $f['Category'] === $cat ? 'checked' : '' ?>> <?= htmlspecialchars($label) ?></label>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="field">
        <label class="title">Dates</label>
        <div class="inline">
            <input type="date" name="StartDate" aria-label="From" value="<?= htmlspecialchars($f['StartDate']) ?>" required>
            <span>to</span>
            <input type="date" name="EndDate" aria-label="To" value="<?= htmlspecialchars($f['EndDate']) ?>" required>
        </div>
        <?php $dayPolicy = timeOffDayPolicy(); ?>
        <p class="hint">Full days count working days only (weekends and company holidays are skipped),
            <?= htmlspecialchars($dayPolicy['mode'] === 'shift'
                ? "the employee's shift hours each (" . m365HoursPhrase($dayPolicy['hours'], false) . ' without a shift)'
                : m365HoursPhrase($dayPolicy['hours'], false) . ' each') ?>.</p>
    </div>
    <div class="field">
        <div class="inline">
            <label><input type="checkbox" name="PartDay" value="1" id="rtoPart" <?= $f['PartDay'] ? 'checked' : '' ?>> Part of the day, as</label>
            <label><input type="radio" name="PartMode" value="times" <?= $f['PartMode'] === 'times' ? 'checked' : '' ?>> Times</label>
            <label><input type="radio" name="PartMode" value="hours" <?= $f['PartMode'] === 'hours' ? 'checked' : '' ?>> Hours missed</label>
        </div>
        <div class="inline" id="rtoTimes" style="margin-top:.4rem;">
            <input type="time" name="StartTime" aria-label="From time" value="<?= htmlspecialchars($f['StartTime']) ?>">
            <span>to</span>
            <input type="time" name="EndTime" aria-label="To time" value="<?= htmlspecialchars($f['EndTime']) ?>">
        </div>
        <div class="inline" id="rtoHours" style="margin-top:.4rem;">
            <input type="number" name="Hours" aria-label="Hours missed" step="0.25" min="0.25" max="24" value="<?= htmlspecialchars($f['Hours']) ?>" style="width:6rem;">
            <span>hours missed (each day)</span>
        </div>
        <p class="hint">Hours missed is for part of a day without set times, e.g. came in 2 hours late.</p>
    </div>
    <div class="field">
        <label class="title" for="rtoNotes">Reason <span id="rtoReq" style="font-weight:normal; color:var(--muted-text);">(required for an absence)</span></label>
        <textarea id="rtoNotes" name="Notes" rows="2" maxlength="500" placeholder="e.g. Called in, family emergency"><?= htmlspecialchars($f['Notes']) ?></textarea>
        <p class="hint">The employee can see this.</p>
    </div>
    <?php if ($canViewPrivate): ?>
    <div class="field">
        <label class="title" for="rtoPrivate">Private note <span style="font-weight:normal; color:var(--muted-text);">(admins only, optional)</span></label>
        <textarea id="rtoPrivate" name="AdminPrivateNote" rows="2" maxlength="500"><?= htmlspecialchars($f['Private']) ?></textarea>
    </div>
    <?php endif; ?>
    <div class="field">
        <div class="inline"><label><input type="checkbox" name="EmailEmployee" value="1" <?= $f['Email'] ? 'checked' : '' ?>> Email the employee about it</label></div>
    </div>
    <button type="submit" class="btn primary">Record time off</button>
</form>

<h2 style="margin-top:2rem;">Recently recorded by admins</h2>
<?php if (!$recent): ?>
<p style="color:var(--muted-text);">Nothing yet.</p>
<?php else: ?>
<div style="overflow-x:auto;">
<table class="rto-table">
    <thead><tr><th>Employee</th><th>Type</th><th>Dates</th><th>Reason</th><th>Recorded</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($recent as $r): ?>
        <tr>
            <td><?= htmlspecialchars($r['LastName'] . ', ' . $r['FirstName']) ?></td>
            <td><?= htmlspecialchars(timeOffLabel($r['Category'])) ?><?= $r['Status'] !== 'Approved' ? ' <span style="color:var(--muted-text);">(' . htmlspecialchars($r['Status']) . ')</span>' : '' ?></td>
            <td style="white-space:nowrap;">
                <?= date('m/d/Y', strtotime($r['StartDate'])) ?><?= $r['EndDate'] !== $r['StartDate'] ? ' – ' . date('m/d/Y', strtotime($r['EndDate'])) : '' ?>
                <?php if ($r['StartTime']): ?><br><span style="color:var(--muted-text);"><?= date('g:i A', strtotime($r['StartTime'])) ?> – <?= date('g:i A', strtotime($r['EndTime'])) ?></span>
                <?php elseif ((float) $r['Hours'] > 0): ?><br><span style="color:var(--muted-text);"><?= htmlspecialchars(m365HoursPhrase((float) $r['Hours'], $r['EndDate'] !== $r['StartDate'])) ?></span><?php endif; ?>
            </td>
            <td><?= nl2br(htmlspecialchars($r['Notes'] ?? '')) ?: '—' ?></td>
            <td style="white-space:nowrap;"><?= htmlspecialchars($r['ReviewedBy'] ?? '') ?><br><span style="color:var(--muted-text);"><?= $r['ReviewedAt'] ? date('m/d g:i A', strtotime($r['ReviewedAt'])) : '' ?></span></td>
            <td><?php if ($r['Status'] === 'Approved'): ?><a class="btn small secondary" href="edit_time_off.php?id=<?= (int) $r['ID'] ?>">Edit</a><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

<script>
// Part of a day is a time window or a number of hours missed: show the boxes for the
// chosen one, and tick "Part of the day" when a time or hours are entered.
(function () {
    var part = document.getElementById('rtoPart');
    var times = document.getElementById('rtoTimes');
    var hours = document.getElementById('rtoHours');
    function sync() {
        var m = document.querySelector('.rto-form input[name=PartMode]:checked');
        var isHours = !!m && m.value === 'hours';
        times.style.display = isHours ? 'none' : '';
        hours.style.display = isHours ? '' : 'none';
        // A disabled box isn't checked or sent, so the hidden one can't block saving
        times.querySelectorAll('input').forEach(function (el) { el.disabled = isHours; });
        hours.querySelectorAll('input').forEach(function (el) { el.disabled = !isHours; });
    }
    document.querySelectorAll('.rto-form input[name=PartMode]').forEach(function (el) {
        el.addEventListener('change', function () { part.checked = true; sync(); });
    });
    document.querySelectorAll('#rtoTimes input, #rtoHours input').forEach(function (el) {
        el.addEventListener('change', function () { if (el.value) part.checked = true; });
    });
    sync();
})();
</script>

<?php require_once 'footer.php'; ?>
