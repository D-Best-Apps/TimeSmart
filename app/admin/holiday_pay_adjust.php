<?php
// admin/holiday_pay_adjust.php — holiday hours for one holiday, employee by employee
// (Settings -> Holidays -> Hours by employee).
// The rules (Settings -> Holidays -> Holiday pay, and each employee's own Holiday hours)
// give everyone their hours; here an admin can set a different number for one person
// on this holiday — half a holiday after an absence, a full one the rules skipped, or 0.
// Stored in holiday_pay_adjustments; Payroll Totals and Home pick them up.
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/../functions/check_permission.php';
requirePermission('manage_pay_rules');
require_once __DIR__ . '/../functions/csrf.php';
require_once __DIR__ . '/../functions/holidays.php';
require_once __DIR__ . '/../functions/holiday_pay.php';
require_once __DIR__ . '/../functions/pay_rules.php';

$date = (string) ($_GET['date'] ?? $_POST['date'] ?? '');
$d = DateTime::createFromFormat('Y-m-d', $date);
if (!$d || $d->format('Y-m-d') !== $date) {
    header('Location: holidays.php');
    exit;
}
$policy   = holidayPayPolicy($conn);
$holidays = paidHolidaysInRange($date, $date);
$name     = $holidays[$date] ?? null;
$locked   = isPayrollLocked($conn, $date);
$errors   = [];
$notice   = $_GET['notice'] ?? '';

$fmt = fn($h) => rtrim(rtrim(number_format((float) $h, 2, '.', ''), '0'), '.');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $name !== null) {
    require_csrf('holiday_pay_adjust.php?date=' . urlencode($date));
    if ($locked) {
        $errors[] = 'Payroll is closed for this date. Reopen it on Settings › Pay Rules first.';
    } else {
        $rule = holidayPayByEmployee($conn, $date, $date, false);
        $admin = (string) $_SESSION['admin'];
        $now = date('Y-m-d H:i:s');
        $set = $conn->prepare("INSERT INTO holiday_pay_adjustments (EmployeeID, HolidayDate, Hours, Note, AdjustedBy, AdjustedAt) VALUES (?, ?, ?, ?, ?, ?)
                               ON DUPLICATE KEY UPDATE Hours = VALUES(Hours), Note = VALUES(Note), AdjustedBy = VALUES(AdjustedBy), AdjustedAt = VALUES(AdjustedAt)");
        $del = $conn->prepare("DELETE FROM holiday_pay_adjustments WHERE EmployeeID = ? AND HolidayDate = ?");
        $changed = 0;
        foreach ((array) ($_POST['hours'] ?? []) as $eid => $val) {
            $eid  = (int) $eid;
            $val  = trim((string) $val);
            $note = mb_substr(trim((string) ($_POST['note'][$eid] ?? '')), 0, 255);
            $ruleHours = (float) ($rule[$eid]['days'][$date]['hours'] ?? 0);
            if ($val === '' || (abs((float) $val - $ruleHours) < 0.005 && $note === '' && is_numeric($val))) {
                $del->bind_param("is", $eid, $date);
                $del->execute();
                $changed += $del->affected_rows;
                continue;
            }
            if (!is_numeric($val) || (float) $val < 0 || (float) $val > 24) {
                $errors[] = 'Hours must be a number from 0 to 24 (or blank for the rules).';
                continue;
            }
            $h = round((float) $val, 2);
            $set->bind_param("isdsss", $eid, $date, $h, $note, $admin, $now);
            $set->execute();
            $changed++;
        }
        if (!$errors) {
            header('Location: holiday_pay_adjust.php?date=' . urlencode($date) . '&notice=' . urlencode('Holiday hours saved.'));
            exit;
        }
    }
}

$rows = [];
if ($name !== null) {
    $ruleHours = holidayPayByEmployee($conn, $date, $date, false);
    $adjusted  = holidayPayAdjustments($conn, $date, $date);
    try {
        $shifts = listShifts($conn, true);
    } catch (mysqli_sql_exception $e) {
        $shifts = [];
    }
    $users = $conn->query("SELECT ID, FirstName, LastName, ShiftID, HolidayHours, Office FROM users ORDER BY LastName, FirstName")->fetch_all(MYSQLI_ASSOC);
    foreach ($users as $u) {
        $eid = (int) $u['ID'];
        $shift = $u['ShiftID'] !== null ? ($shifts[(int) $u['ShiftID']] ?? null) : null;
        $rows[] = [
            'id' => $eid, 'name' => $u['LastName'] . ', ' . $u['FirstName'], 'office' => $u['Office'],
            'shift' => $shift, 'own' => $u['HolidayHours'],
            'rule' => (float) ($ruleHours[$eid]['days'][$date]['hours'] ?? 0),
            'adj' => $adjusted[$eid][$date] ?? null,
        ];
    }
}

$pageTitle = "Holiday Hours";
require_once 'header.php';
?>
<style>
    .hpa-table { width: 100%; border-collapse: collapse; font-size: .92rem; margin: 0; }
    .hpa-table th, .hpa-table td { padding: .4rem .6rem; border-bottom: 1px solid #eef1f4; text-align: left; vertical-align: middle; }
    .hpa-table th { color: var(--primary-color); background: #eef3f7; }
    .hpa-table td.num, .hpa-table th.num { text-align: right; white-space: nowrap; }
    .hpa-table input[type=number] { width: 6rem; margin: 0; }
    .hpa-table input[type=text] { width: 100%; min-width: 14rem; margin: 0; }
    .hpa-muted { color: var(--muted-text); font-size: .85em; }
    .hpa-set { background: #fff8e1; }
    .notice { background: #e7f5ec; color: #146c43; padding: .6rem .9rem; border-radius: 6px; margin-bottom: 1rem; }
    .errors { background: #fdecea; color: #b02a37; padding: .6rem .9rem; border-radius: 6px; margin-bottom: 1rem; }
</style>

<p><a href="holidays.php">&larr; Holidays</a></p>

<?php if ($name === null): ?>
    <div class="errors"><?= htmlspecialchars(date('D M j, Y', strtotime($date))) ?> isn't a paid company holiday<?= $policy['enabled'] ? '' : ' (holiday pay is off)' ?>.</div>
<?php else: ?>
    <h2 style="margin-top:0;"><?= htmlspecialchars($name) ?> — <?= date('l, M j, Y', strtotime($date)) ?></h2>
    <p class="hpa-muted">
        "By the rules" is what the holiday-pay settings and each employee's own Holiday hours give.
        Type a number to pay someone differently on this holiday (e.g. half of it), 0 for none, or clear the box to go back to the rules.
        <?php if (!$policy['enabled']): ?><strong>Holiday pay is off</strong>, so none of this is paid until it's turned on.<?php endif; ?>
    </p>
    <?php if ($notice !== ''): ?><div class="notice"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
    <?php if ($errors): ?><div class="errors"><?= implode('<br>', array_map('htmlspecialchars', array_unique($errors))) ?></div><?php endif; ?>
    <?php if ($locked): ?><div class="errors">Payroll is closed for this date, so these hours can't be changed. Reopen it on <a href="pay_rules.php">Settings › Pay Rules</a> first.</div><?php endif; ?>

    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="date" value="<?= htmlspecialchars($date) ?>">
        <div style="overflow-x:auto;">
        <table class="hpa-table">
            <thead><tr><th>Employee</th><th>Shift</th><th class="num">By the rules</th><th class="num">Pay</th><th>Note</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr class="<?= $r['adj'] ? 'hpa-set' : '' ?>">
                    <td><?= htmlspecialchars($r['name']) ?></td>
                    <td><?= $r['shift'] ? htmlspecialchars($r['shift']['Name']) : '<span class="hpa-muted">—</span>' ?><?php if ($r['own'] !== null): ?> <span class="hpa-muted">(own: <?= $fmt($r['own']) ?> h)</span><?php endif; ?></td>
                    <td class="num"><?= $fmt($r['rule']) ?></td>
                    <td class="num"><input type="number" name="hours[<?= $r['id'] ?>]" min="0" max="24" step="0.25" value="<?= $r['adj'] ? htmlspecialchars($fmt($r['adj']['hours'])) : '' ?>" placeholder="<?= $fmt($r['rule']) ?>" <?= $locked ? 'disabled' : '' ?>></td>
                    <td>
                        <input type="text" name="note[<?= $r['id'] ?>]" maxlength="255" value="<?= htmlspecialchars($r['adj']['note'] ?? '') ?>" placeholder="Why (optional)" <?= $locked ? 'disabled' : '' ?>>
                        <?php if ($r['adj']): ?><div class="hpa-muted">Set by <?= htmlspecialchars($r['adj']['by']) ?>, <?= date('n/j/Y g:i A', strtotime($r['adj']['at'])) ?></div><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php if (!$locked): ?>
        <p><button type="submit" class="btn primary">Save holiday hours</button></p>
        <?php endif; ?>
    </form>
<?php endif; ?>

<?php require_once 'footer.php'; ?>
