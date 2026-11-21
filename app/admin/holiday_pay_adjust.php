<?php
// admin/holiday_pay_adjust.php — who gets what for one holiday (Settings -> Holidays ->
// Hours by employee). Read-only: the hours come from the holiday-pay settings and each
// employee's own Holiday hours; a different number for one person (half a holiday, a full
// one the rules skipped, or 0) is set on that person's timesheet (Edit), which stores it
// in holiday_pay_adjustments. Each name links to the timesheet for the holiday's week.
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/../functions/check_permission.php';
requirePermission('manage_pay_rules');
require_once __DIR__ . '/../functions/holidays.php';
require_once __DIR__ . '/../functions/holiday_pay.php';
require_once __DIR__ . '/../functions/hours.php';
require_once __DIR__ . '/../functions/pay_rules.php';

$date = (string) ($_GET['date'] ?? '');
$d = DateTime::createFromFormat('Y-m-d', $date);
if (!$d || $d->format('Y-m-d') !== $date) {
    header('Location: holidays.php');
    exit;
}
$policy   = holidayPayPolicy($conn);
$holidays = paidHolidaysInRange($date, $date);
$name     = $holidays[$date] ?? null;

$fmt = fn($h) => rtrim(rtrim(number_format((float) $h, 2, '.', ''), '0'), '.');

$rows = [];
$total = 0.0;
if ($name !== null) {
    $paid      = holidayPayByEmployee($conn, $date, $date);
    $ruleHours = holidayPayByEmployee($conn, $date, $date, false);
    $adjusted  = holidayPayAdjustments($conn, $date, $date);
    try {
        $shifts = listShifts($conn, true);
    } catch (mysqli_sql_exception $e) {
        $shifts = [];
    }
    $users = $conn->query("SELECT ID, FirstName, LastName, ShiftID, HolidayHours, ArchivedAt FROM users ORDER BY LastName, FirstName")->fetch_all(MYSQLI_ASSOC);
    foreach ($users as $u) {
        $eid = (int) $u['ID'];
        $archivedOn = $u['ArchivedAt'] !== null ? substr($u['ArchivedAt'], 0, 10) : null;
        if ($archivedOn !== null && $archivedOn < $date && !isset($adjusted[$eid][$date])) {
            continue; // archived before this holiday
        }
        $hours = (float) ($paid[$eid]['days'][$date]['hours'] ?? 0);
        $total += $hours;
        $rows[] = [
            'id' => $eid, 'name' => $u['LastName'] . ', ' . $u['FirstName'] . ($archivedOn !== null ? ' (archived)' : ''),
            'shift' => $u['ShiftID'] !== null ? ($shifts[(int) $u['ShiftID']] ?? null) : null,
            'own' => $u['HolidayHours'], 'hours' => $hours,
            'rule' => (float) ($ruleHours[$eid]['days'][$date]['hours'] ?? 0),
            'adj' => $adjusted[$eid][$date] ?? null,
        ];
    }
}
// The holiday's work week, for the timesheet links
$wkFrom = date('m/d/Y', strtotime(payPeriodStart($date)));
$wkTo   = date('m/d/Y', strtotime(payPeriodEnd($date)));

$pageTitle = "Holiday Hours";
require_once 'header.php';
?>
<style>
    .hpa-table { width: 100%; border-collapse: collapse; font-size: .92rem; margin: 0; }
    .hpa-table th, .hpa-table td { padding: .4rem .6rem; border-bottom: 1px solid #eef1f4; text-align: left; vertical-align: middle; }
    .hpa-table th { color: var(--primary-color); background: #eef3f7; }
    .hpa-table td.num, .hpa-table th.num { text-align: right; white-space: nowrap; }
    .hpa-table tfoot td { font-weight: 600; border-top: 2px solid #dbe6ef; }
    .hpa-muted { color: var(--muted-text); font-size: .85em; }
    .hpa-set { background: #fff8e1; }
    .errors { background: #fdecea; color: #b02a37; padding: .6rem .9rem; border-radius: 6px; margin-bottom: 1rem; }
</style>

<p><a href="holidays.php">&larr; Holidays</a></p>

<?php if ($name === null): ?>
    <div class="errors"><?= htmlspecialchars(date('D M j, Y', strtotime($date))) ?> isn't a paid company holiday<?= $policy['enabled'] ? '' : ' (holiday pay is off)' ?>.</div>
<?php else: ?>
    <h2 style="margin-top:0;"><?= htmlspecialchars($name) ?> — <?= date('l, M j, Y', strtotime($date)) ?></h2>
    <p class="hpa-muted">
        Holiday hours each employee gets, from the holiday-pay settings and each employee's own Holiday hours.
        To pay someone differently on this holiday (e.g. half of it, or none), click their name to open
        that week's timesheet, then <strong>Edit Timesheet</strong> and change the hours on the holiday line.
        <?php if (!$policy['enabled']): ?><strong>Holiday pay is off</strong>, so none of this is paid until it's turned on.<?php endif; ?>
    </p>

    <div style="overflow-x:auto;">
    <table class="hpa-table">
        <thead><tr><th>Employee</th><th>Shift</th><th class="num">Holiday hours</th><th>Note</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr class="<?= $r['adj'] ? 'hpa-set' : '' ?>">
                <td><a href="view_punches.php?emp=<?= $r['id'] ?>&from=<?= urlencode($wkFrom) ?>&to=<?= urlencode($wkTo) ?>" title="Open this week's timesheet"><?= htmlspecialchars($r['name']) ?></a></td>
                <td><?= $r['shift'] ? htmlspecialchars($r['shift']['Name']) : '<span class="hpa-muted">—</span>' ?><?php if ($r['own'] !== null): ?> <span class="hpa-muted">(own: <?= $fmt($r['own']) ?> h)</span><?php endif; ?></td>
                <td class="num"><?= $fmt($r['hours']) ?></td>
                <td>
                    <?php if ($r['adj']): ?>
                        Set by hand<?= $r['adj']['note'] !== '' ? ': ' . htmlspecialchars($r['adj']['note']) : '' ?>
                        <span class="hpa-muted">(rules: <?= $fmt($r['rule']) ?> h; <?= htmlspecialchars($r['adj']['by']) ?>, <?= date('n/j/Y', strtotime($r['adj']['at'])) ?>)</span>
                    <?php elseif ($r['hours'] <= 0): ?>
                        <span class="hpa-muted">No holiday pay under the rules</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="2">Total</td><td class="num"><?= $fmt($total) ?></td><td></td></tr></tfoot>
    </table>
    </div>
<?php endif; ?>

<?php require_once 'footer.php'; ?>
