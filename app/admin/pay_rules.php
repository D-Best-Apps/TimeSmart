<?php
// admin/pay_rules.php — company-wide pay rules: work week, pay period, overtime,
// and default rounding for employees without a shift. These change what people
// are paid, so they live on their own page (super admins only), not General Settings.
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/../functions/check_permission.php';
requirePermission('manage_pay_rules');

require_once __DIR__ . '/../functions/csrf.php';
require_once __DIR__ . '/../functions/pay_rules.php';

$weekdays = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
$increments = [0 => 'Off (exact minute)', 5 => '5 minutes', 6 => '6 minutes (1/10 hour)', 10 => '10 minutes', 15 => '15 minutes (1/4 hour)'];
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reopen') {
    require_csrf('pay_rules.php');
    // Move the payroll lock back to a chosen day, or clear it
    $back = trim((string) ($_POST['ReopenThrough'] ?? ''));
    $d = DateTime::createFromFormat('Y-m-d', $back);
    $current = payrollLockedThrough($conn);
    if ($back === '') {
        setPayrollLockedThrough($conn, null);
    } elseif ($d && $d->format('Y-m-d') === $back && $current !== null && $back < $current) {
        setPayrollLockedThrough($conn, $back);
    }
    header('Location: pay_rules.php?reopened=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf('pay_rules.php');

    $weekStart  = (int) ($_POST['PayWeekStartDay'] ?? 3);
    $periodType = ($_POST['PayPeriodType'] ?? 'weekly') === 'biweekly' ? 'biweekly' : 'weekly';
    $anchor     = trim($_POST['PayPeriodAnchor'] ?? '');
    $otWeekly   = (float) ($_POST['OvertimeWeeklyHours'] ?? 40);
    $increment  = (int) ($_POST['DefaultRoundIncrement'] ?? 0);
    $roundMode  = $_POST['DefaultRoundMode'] ?? 'nearest';

    $errors = [];
    if (!isset($weekdays[$weekStart]))                       $errors[] = 'Pick the day the work week starts.';
    if ($otWeekly <= 0 || $otWeekly > 80)                    $errors[] = 'Weekly overtime must be between 1 and 80 hours.';
    if (!isset($increments[$increment]))                     $errors[] = 'Pick a rounding increment from the list.';
    if (!isset(ROUND_MODES[$roundMode]))                     $errors[] = 'Pick a rounding direction from the list.';
    if ($periodType === 'biweekly') {
        $d = DateTime::createFromFormat('Y-m-d', $anchor);
        if (!$d || $d->format('Y-m-d') !== $anchor) {
            $errors[] = 'Bi-weekly pay needs the first day of any one pay period.';
        } elseif ((int) $d->format('N') !== $weekStart) {
            $errors[] = 'The bi-weekly start date must fall on a ' . $weekdays[$weekStart] . ', the first day of the work week.';
        }
    }

    if ($errors) {
        $message = '<div class="alert alert-danger">' . implode('<br>', array_map('htmlspecialchars', $errors)) . '</div>';
    } else {
        $values = [
            'PayWeekStartDay'       => (string) $weekStart,
            'PayPeriodType'         => $periodType,
            'PayPeriodAnchor'       => $periodType === 'biweekly' ? $anchor : '',
            'OvertimeWeeklyHours'   => rtrim(rtrim(number_format($otWeekly, 2, '.', ''), '0'), '.'),
            'DefaultRoundIncrement' => (string) $increment,
            'DefaultRoundMode'      => $roundMode,
        ];
        $stmt = $conn->prepare("INSERT INTO settings (SettingKey, SettingValue) VALUES (?, ?) ON DUPLICATE KEY UPDATE SettingValue = VALUES(SettingValue)");
        foreach ($values as $key => $value) {
            $stmt->bind_param("ss", $key, $value);
            $stmt->execute();
        }
        $stmt->close();
        header('Location: pay_rules.php?saved=1');
        exit;
    }
}

$policy = payPolicy($conn);
// Re-show what was typed after a validation error
$form = $_SERVER['REQUEST_METHOD'] === 'POST' ? [
    'weekStartDay'   => (int) ($_POST['PayWeekStartDay'] ?? 3),
    'periodType'     => $_POST['PayPeriodType'] ?? 'weekly',
    'periodAnchor'   => $_POST['PayPeriodAnchor'] ?? '',
    'otWeekly'       => (float) ($_POST['OvertimeWeeklyHours'] ?? 40),
    'roundIncrement' => (int) ($_POST['DefaultRoundIncrement'] ?? 0),
    'roundMode'      => $_POST['DefaultRoundMode'] ?? 'nearest',
] : $policy;
if (isset($_GET['reopened'])) {
    $message = '<div class="alert alert-success">Payroll lock updated.</div>';
}
if (isset($_GET['saved'])) {
    $message = '<div class="alert alert-success">Pay rules saved. They apply to punches from now on; use <a href="shifts.php">Shifts &rsaquo; Recalculate</a> to re-apply them to past days.</div>';
}

$fmtHours = fn(float $h) => rtrim(rtrim(number_format($h, 2, '.', ''), '0'), '.');
$endDay   = $weekdays[$form['weekStartDay'] === 1 ? 7 : $form['weekStartDay'] - 1] ?? '';

$pageTitle = "Pay Rules";
require_once 'header.php';
?>

<form method="POST" class="settings-form">
    <?= csrf_field() ?>
    <?= $message ?>

    <h2>Work Week &amp; Pay Period</h2>
    <div class="field">
        <label for="PayWeekStartDay">Work week starts on</label>
        <select id="PayWeekStartDay" name="PayWeekStartDay">
            <?php foreach ($weekdays as $n => $name): ?>
                <option value="<?= $n ?>" <?= $form['weekStartDay'] === $n ? 'selected' : '' ?>><?= $name ?></option>
            <?php endforeach; ?>
        </select>
        <p>Overtime is counted per work week, and reports group hours by it.</p>
    </div>
    <div class="field">
        <label for="PayPeriodType">Pay frequency</label>
        <select id="PayPeriodType" name="PayPeriodType" onchange="document.getElementById('anchorField').style.display = this.value === 'biweekly' ? '' : 'none'">
            <option value="weekly" <?= $form['periodType'] === 'weekly' ? 'selected' : '' ?>>Weekly</option>
            <option value="biweekly" <?= $form['periodType'] === 'biweekly' ? 'selected' : '' ?>>Every two weeks</option>
        </select>
        <p>Sets the date range the Payroll Totals report opens with.</p>
    </div>
    <div class="field" id="anchorField" style="<?= $form['periodType'] === 'biweekly' ? '' : 'display:none' ?>">
        <label for="PayPeriodAnchor">First day of any one pay period</label>
        <input type="date" id="PayPeriodAnchor" name="PayPeriodAnchor" value="<?= htmlspecialchars((string) $form['periodAnchor']) ?>">
        <p>Any past or future pay period start works; every other period is counted from it.</p>
    </div>

    <h2>Overtime</h2>
    <div class="field">
        <label for="OvertimeWeeklyHours">Overtime after this many hours per week</label>
        <input type="number" id="OvertimeWeeklyHours" name="OvertimeWeeklyHours" min="1" max="80" step="0.25" value="<?= htmlspecialchars($fmtHours((float) $form['otWeekly'])) ?>" style="max-width:8rem;">
    </div>

    <h2>Rounding for Employees Without a Shift</h2>
    <div class="field">
        <label for="DefaultRoundIncrement">Round every punch</label>
        <div style="display:flex; gap:.5rem; flex-wrap:wrap;">
            <select name="DefaultRoundMode" aria-label="Rounding direction" style="max-width:14rem;">
                <?php foreach (ROUND_MODES as $k => $label): ?>
                    <option value="<?= $k ?>" <?= $form['roundMode'] === $k ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="DefaultRoundIncrement" name="DefaultRoundIncrement" style="max-width:14rem;">
                <?php foreach ($increments as $k => $label): ?>
                    <option value="<?= $k ?>" <?= (int) $form['roundIncrement'] === $k ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <p>Employees with a shift use that shift's rules instead — set those on <a href="shifts.php">Shifts</a>.</p>
    </div>

    <p style="background:#eef3f6; padding:.75rem 1rem; border-radius:6px;">
        <strong>In short:</strong> the work week runs <?= htmlspecialchars(($weekdays[$form['weekStartDay']] ?? '') . '–' . $endDay) ?>.
        Overtime is paid after <?= htmlspecialchars($fmtHours((float) $form['otWeekly'])) ?> hours a week.
        Employees are paid <?= $form['periodType'] === 'biweekly' ? 'every two weeks' : 'weekly' ?>.
        <?= (int) $form['roundIncrement'] > 0
            ? 'Punches without a shift are rounded ' . htmlspecialchars(ROUND_MODES[$form['roundMode']] ?? '') . ' ' . (int) $form['roundIncrement'] . ' minutes.'
            : 'Punches without a shift are not rounded.' ?>
    </p>

    <div class="buttons">
        <button type="submit" class="btn primary">Save Pay Rules</button>
    </div>
</form>

<?php $lockedThrough = payrollLockedThrough($conn); ?>
<form method="POST" class="settings-form" style="margin-top:2rem;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="reopen">
    <h2>Closed Payroll</h2>
    <?php if ($lockedThrough === null): ?>
        <p>No payroll has been closed yet. Close a pay period from the <a href="payroll_totals.php">Payroll Totals</a> report once it's been paid.</p>
    <?php else: ?>
        <p>Payroll is closed through <strong><?= htmlspecialchars(date('l, m/d/Y', strtotime($lockedThrough))) ?></strong>.
           Punches up to that day can't be edited, approved or recalculated.</p>
        <div class="field">
            <label for="ReopenThrough">To correct a closed day, move the lock back to</label>
            <input type="date" id="ReopenThrough" name="ReopenThrough" max="<?= htmlspecialchars((new DateTime($lockedThrough))->modify('-1 day')->format('Y-m-d')) ?>" style="max-width:12rem;">
            <p>Leave blank to remove the lock entirely. Changing paid hours after payroll was run means correcting them in payroll too.</p>
        </div>
        <div class="buttons">
            <button type="submit" class="btn secondary" onclick="return confirm('Reopen closed payroll days for editing?')">Reopen</button>
        </div>
    <?php endif; ?>
</form>

<?php require_once 'footer.php'; ?>
