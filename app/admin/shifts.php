<?php
// admin/shifts.php — named shifts (start/end, rounding windows, lunch rule) that
// employees are assigned to. The rules engine is functions/pay_rules.php; the
// "Try a punch" box calls shift_preview.php, which runs the same engine.
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

date_default_timezone_set('America/Chicago');

const SHIFT_DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const SHIFT_INCREMENTS = [0 => 'exact minute', 5 => '5 minutes', 6 => '6 minutes', 10 => '10 minutes', 15 => '15 minutes'];
const SHIFT_COLORS = ['#0a4a6b', '#2e7d32', '#c62828', '#6a1b9a', '#ef6c00', '#00838f', '#5d4037', '#455a64'];

/** Defaults for a new shift: a typical 1st shift with a 30-minute auto lunch. */
function blankShift(): array {
    return [
        'ID' => 0, 'Name' => '', 'Color' => SHIFT_COLORS[0],
        'StartTime' => '07:00:00', 'EndTime' => '15:30:00', 'Days' => 'Mon,Tue,Wed,Thu,Fri',
        'EarlyInMinutes' => 15, 'LateInGraceMinutes' => 5, 'EarlyOutGraceMinutes' => 5, 'LateOutMinutes' => 10,
        'OutsidePolicy' => 'actual', 'RoundIncrement' => 15, 'RoundMode' => 'nearest',
        'LunchMode' => 'auto', 'LunchMinutes' => 30, 'LunchMinWorkedHours' => '6.00', 'Archived' => 0,
    ];
}

/** Validate a posted shift. Returns [row, errors]. */
function shiftFromPost(array $p): array {
    $s = blankShift();
    $errors = [];
    $s['ID']   = (int) ($p['ID'] ?? 0);
    $s['Name'] = trim((string) ($p['Name'] ?? ''));
    if ($s['Name'] === '' || mb_strlen($s['Name']) > 100) $errors[] = 'Give the shift a name (up to 100 characters).';
    $s['Color'] = preg_match('/^#[0-9a-fA-F]{6}$/', $p['Color'] ?? '') ? $p['Color'] : SHIFT_COLORS[0];
    foreach (['StartTime' => 'start', 'EndTime' => 'end'] as $k => $label) {
        $t = (string) ($p[$k] ?? '');
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $t)) {
            $errors[] = "Enter the shift {$label} time.";
        } else {
            $s[$k] = strlen($t) === 5 ? "{$t}:00" : $t;
        }
    }
    if ($s['StartTime'] === $s['EndTime']) $errors[] = 'The shift must end at a different time than it starts.';
    $days = array_values(array_intersect(SHIFT_DAYS, (array) ($p['Days'] ?? [])));
    if (!$days) $errors[] = 'Tick at least one day the shift runs.';
    $s['Days'] = implode(',', $days);
    foreach (['EarlyInMinutes', 'LateInGraceMinutes', 'EarlyOutGraceMinutes', 'LateOutMinutes'] as $k) {
        $v = (int) ($p[$k] ?? 0);
        if ($v < 0 || $v > 120) $errors[] = 'Rounding windows must be between 0 and 120 minutes.';
        $s[$k] = max(0, min(120, $v));
    }
    $s['OutsidePolicy']  = ($p['OutsidePolicy'] ?? '') === 'cap' ? 'cap' : 'actual';
    $s['RoundIncrement'] = array_key_exists((int) ($p['RoundIncrement'] ?? -1), SHIFT_INCREMENTS) ? (int) $p['RoundIncrement'] : 0;
    $s['RoundMode']      = isset(ROUND_MODES[$p['RoundMode'] ?? '']) ? $p['RoundMode'] : 'nearest';
    $s['LunchMode']      = isset(LUNCH_MODES[$p['LunchMode'] ?? '']) ? $p['LunchMode'] : 'punched';
    $s['LunchMinutes']   = (int) ($p['LunchMinutes'] ?? 30);
    if ($s['LunchMode'] !== 'punched' && ($s['LunchMinutes'] < 5 || $s['LunchMinutes'] > 120)) $errors[] = 'Lunch length must be between 5 and 120 minutes.';
    $minWorked = (float) ($p['LunchMinWorkedHours'] ?? 6);
    if ($minWorked < 0 || $minWorked > 16) $errors[] = 'The minimum hours before lunch is taken out must be between 0 and 16.';
    $s['LunchMinWorkedHours'] = number_format(max(0, min(16, $minWorked)), 2, '.', '');
    return [$s, $errors];
}

function shiftEmployeeCounts(mysqli $conn): array {
    $counts = [];
    if ($res = $conn->query("SELECT ShiftID, COUNT(*) AS c FROM users WHERE ShiftID IS NOT NULL GROUP BY ShiftID")) {
        while ($r = $res->fetch_assoc()) {
            $counts[(int) $r['ShiftID']] = (int) $r['c'];
        }
    }
    return $counts;
}

$admin   = $_SESSION['admin'];
$errors  = [];
$editing = null;      // shift row shown in the editor
$recalc  = null;      // recalculate preview/result

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf('shifts.php');
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        [$editing, $errors] = shiftFromPost($_POST);
        if (!$errors) {
            $cols = ['Name', 'Color', 'StartTime', 'EndTime', 'Days', 'EarlyInMinutes', 'LateInGraceMinutes',
                     'EarlyOutGraceMinutes', 'LateOutMinutes', 'OutsidePolicy', 'RoundIncrement', 'RoundMode',
                     'LunchMode', 'LunchMinutes', 'LunchMinWorkedHours'];
            $vals  = array_map(fn($c) => $editing[$c], $cols);
            $types = 'sssssiiiisissis';
            if ($editing['ID'] > 0) {
                $sql = "UPDATE shifts SET " . implode(' = ?, ', $cols) . " = ? WHERE ID = ?";
                $stmt = $conn->prepare($sql);
                $vals[] = $editing['ID'];
                $stmt->bind_param($types . 'i', ...$vals);
            } else {
                $sql = "INSERT INTO shifts (" . implode(', ', $cols) . ") VALUES (" . rtrim(str_repeat('?, ', count($cols)), ', ') . ")";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param($types, ...$vals);
            }
            $stmt->execute();
            $stmt->close();
            header('Location: shifts.php?saved=' . urlencode($editing['Name']));
            exit;
        }
    } elseif ($action === 'archive' || $action === 'unarchive') {
        $id = (int) ($_POST['ID'] ?? 0);
        $flag = $action === 'archive' ? 1 : 0;
        $stmt = $conn->prepare("UPDATE shifts SET Archived = ? WHERE ID = ?");
        $stmt->bind_param("ii", $flag, $id);
        $stmt->execute();
        $stmt->close();
        header('Location: shifts.php');
        exit;
    } elseif ($action === 'recalc') {
        $from = $_POST['from'] ?? '';
        $to   = $_POST['to'] ?? '';
        $sid  = ($_POST['shift'] ?? '') === '' ? null : (int) $_POST['shift'];
        $okDate = fn($d) => ($x = DateTime::createFromFormat('Y-m-d', $d)) && $x->format('Y-m-d') === $d;
        if (!$okDate($from) || !$okDate($to) || $from > $to) {
            $errors[] = 'Pick a valid date range to recalculate.';
        } elseif ((new DateTime($from))->diff(new DateTime($to))->days > 92) {
            $errors[] = 'Recalculate at most about three months at a time.';
        } else {
            $apply = !empty($_POST['apply']);
            if ($apply) {
                $conn->begin_transaction();
            }
            $summary = recomputeRange($conn, $from, $to, $sid, !$apply, $admin);
            if ($apply) {
                $conn->commit();
            }
            $recalc = ['from' => $from, 'to' => $to, 'shift' => $sid, 'applied' => $apply, 'summary' => $summary];
        }
    }
}

if ($editing === null && (isset($_GET['new']) || isset($_GET['edit']) || isset($_GET['copy']))) {
    $editing = blankShift();
    $srcID = (int) ($_GET['edit'] ?? $_GET['copy'] ?? 0);
    if ($srcID > 0) {
        $stmt = $conn->prepare("SELECT * FROM shifts WHERE ID = ?");
        $stmt->bind_param("i", $srcID);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $editing = $row;
            if (isset($_GET['copy'])) {
                $editing['ID'] = 0;
                $editing['Name'] = $row['Name'] . ' (copy)';
            }
        }
    }
}

$shifts = listShifts($conn, true);
$counts = shiftEmployeeCounts($conn);

$pageTitle = "Shifts";
require_once 'header.php';

$hm = fn($t) => substr((string) $t, 0, 5);
$sel = fn($a, $b) => (string) $a === (string) $b ? 'selected' : '';
?>
<style>
    .shift-editor { display:grid; grid-template-columns: minmax(0, 1fr) 320px; gap:1.5rem; align-items:start; }
    .shift-box { border:1px solid #dde3e8; border-radius:8px; padding:1rem 1.25rem; margin-bottom:1rem; background:#fff; }
    .shift-box h3 { margin:0 0 .75rem; font-size:1.05rem; }
    .shift-box .row { display:flex; flex-wrap:wrap; gap:.5rem 1rem; align-items:center; margin-bottom:.6rem; line-height:2; }
    .shift-box input[type=number] { width:4.5rem; }
    .shift-box input[type=time], .shift-box select { width:auto; }
    .shift-days label { display:inline-flex; align-items:center; gap:.25rem; margin-right:.5rem; }
    .shift-days input { width:auto; }
    .shift-colors label { display:inline-block; }
    .shift-colors input { display:none; }
    .shift-colors span { display:inline-block; width:22px; height:22px; border-radius:50%; border:3px solid transparent; cursor:pointer; }
    .shift-colors input:checked + span { border-color:#222; }
    .shift-try { position:sticky; top:1rem; background:#f4f8fb; }
    .shift-try .result { font-size:1.05rem; margin-top:.75rem; line-height:1.7; }
    .shift-try .result strong { font-size:1.3rem; }
    .muted { color:var(--muted-text, #666); font-size:.88rem; }
    .recalc-form { display:flex; flex-wrap:wrap; gap:.75rem; align-items:end; }
    .recalc-form label { display:flex; flex-direction:column; font-size:.9rem; }
    @media (max-width: 900px) { .shift-editor { grid-template-columns: 1fr; } .shift-try { position:static; } }
</style>

<div class="uman-container">
<?php if ($errors): ?>
    <div class="alert alert-danger"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
<?php elseif (isset($_GET['saved'])): ?>
    <div class="alert alert-success">Saved <strong><?= htmlspecialchars($_GET['saved']) ?></strong>. Changes apply to punches from now on — use <em>Recalculate</em> below to re-apply them to past days.</div>
<?php endif; ?>

<?php if ($editing !== null): ?>
    <div class="uman-header">
        <h2><?= $editing['ID'] ? 'Edit ' . htmlspecialchars($editing['Name']) : 'New Shift' ?></h2>
        <a href="shifts.php" class="btn secondary">Back to shifts</a>
    </div>

    <form method="POST" id="shiftForm" class="shift-editor">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="ID" value="<?= (int) $editing['ID'] ?>">
        <div>
            <div class="shift-box">
                <h3>1. Schedule</h3>
                <div class="row">
                    <label>Name <input type="text" name="Name" maxlength="100" required value="<?= htmlspecialchars($editing['Name']) ?>" placeholder="1st Shift" style="width:14rem;"></label>
                    <span class="shift-colors" aria-label="Color">
                        <?php foreach (SHIFT_COLORS as $c): ?>
                            <label><input type="radio" name="Color" value="<?= $c ?>" <?= strcasecmp($editing['Color'], $c) === 0 ? 'checked' : '' ?>><span style="background:<?= $c ?>"></span></label>
                        <?php endforeach; ?>
                    </span>
                </div>
                <div class="row">
                    Runs from <input type="time" name="StartTime" required value="<?= $hm($editing['StartTime']) ?>">
                    to <input type="time" name="EndTime" required value="<?= $hm($editing['EndTime']) ?>">
                    <span class="muted">(an end before the start means it runs overnight)</span>
                </div>
                <div class="row shift-days">
                    on
                    <?php $onDays = explode(',', $editing['Days']); foreach (SHIFT_DAYS as $d): ?>
                        <label><input type="checkbox" name="Days[]" value="<?= $d ?>" <?= in_array($d, $onDays, true) ? 'checked' : '' ?>><?= $d ?></label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="shift-box">
                <h3>2. Clock-in and clock-out rounding</h3>
                <div class="row">Clock-ins up to <input type="number" name="EarlyInMinutes" min="0" max="120" value="<?= (int) $editing['EarlyInMinutes'] ?>"> minutes <strong>early</strong> are paid from the shift start.</div>
                <div class="row">Clock-ins up to <input type="number" name="LateInGraceMinutes" min="0" max="120" value="<?= (int) $editing['LateInGraceMinutes'] ?>"> minutes <strong>late</strong> are also paid from the shift start.</div>
                <div class="row">Clock-outs up to <input type="number" name="EarlyOutGraceMinutes" min="0" max="120" value="<?= (int) $editing['EarlyOutGraceMinutes'] ?>"> minutes <strong>early</strong> are paid to the shift end.</div>
                <div class="row">Clock-outs up to <input type="number" name="LateOutMinutes" min="0" max="120" value="<?= (int) $editing['LateOutMinutes'] ?>"> minutes <strong>late</strong> are paid to the shift end.</div>
                <div class="row">
                    Any other punch is rounded
                    <select name="RoundMode">
                        <?php foreach (ROUND_MODES as $k => $label): ?><option value="<?= $k ?>" <?= $sel($editing['RoundMode'], $k) ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?>
                    </select>
                    <select name="RoundIncrement">
                        <?php foreach (SHIFT_INCREMENTS as $k => $label): ?><option value="<?= $k ?>" <?= $sel($editing['RoundIncrement'], $k) ?>><?= $label ?></option><?php endforeach; ?>
                    </select>.
                </div>
                <div class="row">
                    Time before the early window or after the late window:
                    <select name="OutsidePolicy">
                        <option value="actual" <?= $sel($editing['OutsidePolicy'], 'actual') ?>>is paid (rounded)</option>
                        <option value="cap" <?= $sel($editing['OutsidePolicy'], 'cap') ?>>is not paid unless an admin approves it</option>
                    </select>
                </div>
                <p class="muted">Admins approve extra time per punch with the <em>Pay outside shift</em> box on the timesheet. Punches on days the shift doesn't run, or more than <?= SHIFT_MATCH_HOURS ?> hours from its start, are only rounded.</p>
            </div>

            <div class="shift-box">
                <h3>3. Lunch</h3>
                <div class="row">
                    <select name="LunchMode" id="LunchMode">
                        <?php foreach (LUNCH_MODES as $k => $label): ?><option value="<?= $k ?>" <?= $sel($editing['LunchMode'], $k) ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="row" id="lunchAutoRow">
                    Take out <input type="number" name="LunchMinutes" min="5" max="120" value="<?= (int) $editing['LunchMinutes'] ?>"> minutes
                    when they're on the clock at least <input type="number" name="LunchMinWorkedHours" min="0" max="16" step="0.25" value="<?= htmlspecialchars(rtrim(rtrim($editing['LunchMinWorkedHours'], '0'), '.')) ?>"> hours.
                </div>
                <p class="muted" id="lunchHelp"></p>
            </div>

            <div class="buttons">
                <button type="submit" class="btn primary">Save Shift</button>
                <a href="shifts.php" class="btn secondary">Cancel</a>
            </div>
        </div>

        <aside class="shift-box shift-try">
            <h3>Try a punch</h3>
            <p class="muted" style="margin-top:0;">See what this shift pays, using the settings on the left (saved or not).</p>
            <div class="row">In <input type="time" id="tryIn" value="06:48"> Out <input type="time" id="tryOut" value="15:34"></div>
            <div class="row">Lunch <input type="time" id="tryLs"> to <input type="time" id="tryLe"></div>
            <div class="row"><label><input type="checkbox" id="tryWeekend" style="width:auto;"> On a day it doesn't run</label></div>
            <div class="result" id="tryResult">—</div>
        </aside>
    </form>

    <script>
    (function () {
        const form = document.getElementById('shiftForm');
        const out = document.getElementById('tryResult');
        const help = {
            punched: 'Only lunch they punch out for is taken out.',
            auto: 'Lunch is taken out every day, even with no lunch punch. If they do punch a longer break, the longer break is taken out instead.',
            auto_unless_punched: 'If they punch a lunch, that lunch is taken out as punched. If they don\'t, the set amount is taken out.'
        };
        function syncLunch() {
            const mode = document.getElementById('LunchMode').value;
            document.getElementById('lunchAutoRow').style.display = mode === 'punched' ? 'none' : '';
            document.getElementById('lunchHelp').textContent = help[mode] || '';
        }
        let timer = null;
        function preview() {
            clearTimeout(timer);
            timer = setTimeout(function () {
                const data = new FormData(form);
                ['tryIn', 'tryOut', 'tryLs', 'tryLe'].forEach(id => data.append(id, document.getElementById(id).value));
                data.append('tryWeekend', document.getElementById('tryWeekend').checked ? '1' : '');
                fetch('shift_preview.php', { method: 'POST', body: data, credentials: 'same-origin' })
                    .then(r => r.json())
                    .then(function (r) {
                        if (!r.ok) { out.textContent = r.error || 'Enter a clock-in and clock-out.'; return; }
                        out.innerHTML = 'Paid <strong>' + r.paidIn + ' – ' + r.paidOut + '</strong><br>' +
                            'Lunch taken out: ' + r.lunch + '<br>' +
                            'Paid hours: <strong>' + r.hours + '</strong> <span class="muted">(actual ' + r.raw + ')</span>' +
                            (r.notes.length ? '<br><span class="muted">' + r.notes.join('<br>') + '</span>' : '');
                    })
                    .catch(function () { out.textContent = 'Preview unavailable.'; });
            }, 200);
        }
        form.addEventListener('input', preview);
        form.addEventListener('change', function () { syncLunch(); preview(); });
        document.querySelectorAll('.shift-try input').forEach(el => el.addEventListener('input', preview));
        syncLunch();
        preview();
    })();
    </script>

<?php else: ?>
    <div class="uman-header">
        <h2>Shifts</h2>
        <a href="shifts.php?new=1" class="btn primary">+ Add Shift</a>
    </div>
    <p class="muted" style="margin:0 0 1rem;">
        A shift sets when an employee is scheduled and how their punches are rounded and their lunch taken out.
        Assign shifts on the <a href="manage_users.php">Users</a> page. Employees without a shift follow
        <a href="pay_rules.php">Pay Rules</a>. Original punch times are always kept; timesheets show them next to the paid times.
    </p>

    <?php if (!$shifts): ?>
        <p>No shifts yet. <a href="shifts.php?new=1">Add the first one</a>.</p>
    <?php else: ?>
    <table class="uman-table">
        <thead><tr><th>Shift</th><th>Hours</th><th>Days</th><th>Rounding</th><th>Lunch</th><th>Employees</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($shifts as $s): $n = $counts[(int) $s['ID']] ?? 0; ?>
            <tr<?= $s['Archived'] ? ' style="opacity:.6;"' : '' ?>>
                <td><?= shiftChip($s) ?><?= $s['Archived'] ? ' <span class="muted">(archived)</span>' : '' ?></td>
                <td style="white-space:nowrap;"><?= htmlspecialchars(shiftTimeRange($s)) ?></td>
                <td><?= htmlspecialchars(str_replace(',', ', ', $s['Days'])) ?></td>
                <td class="muted">
                    −<?= (int) $s['EarlyInMinutes'] ?>/+<?= (int) $s['LateInGraceMinutes'] ?> in,
                    −<?= (int) $s['EarlyOutGraceMinutes'] ?>/+<?= (int) $s['LateOutMinutes'] ?> out;
                    else <?= (int) $s['RoundIncrement'] ? (int) $s['RoundIncrement'] . ' min ' . htmlspecialchars($s['RoundMode']) : 'exact' ?>
                </td>
                <td class="muted"><?= $s['LunchMode'] === 'punched' ? 'punched' : (int) $s['LunchMinutes'] . ' min auto' . ($s['LunchMode'] === 'auto_unless_punched' ? ' unless punched' : '') ?></td>
                <td><?= $n ?></td>
                <td style="white-space:nowrap;">
                    <a class="btn secondary small" href="shifts.php?edit=<?= (int) $s['ID'] ?>">Edit</a>
                    <a class="btn secondary small" href="shifts.php?copy=<?= (int) $s['ID'] ?>">Duplicate</a>
                    <form method="POST" style="display:inline;" <?= (!$s['Archived'] && $n) ? 'onsubmit="return confirm(\'' . $n . ' employee(s) are still on this shift and will keep using it until you reassign them. Archive it anyway?\')"' : '' ?>>
                        <?= csrf_field() ?>
                        <input type="hidden" name="ID" value="<?= (int) $s['ID'] ?>">
                        <button type="submit" name="action" value="<?= $s['Archived'] ? 'unarchive' : 'archive' ?>" class="btn secondary small"><?= $s['Archived'] ? 'Restore' : 'Archive' ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <h2 style="margin-top:2rem;">Recalculate past days</h2>
    <p class="muted">Re-apply the current shift and pay rules to punches already recorded — for example after changing a shift
        mid-week. Preview first; applying is logged in each employee's change history.</p>
    <?php
        $rFrom = $recalc['from'] ?? payPeriodStart(date('Y-m-d'));
        $rTo   = $recalc['to'] ?? date('Y-m-d');
        $rShift = $recalc['shift'] ?? null;
    ?>
    <form method="POST" class="recalc-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="recalc">
        <label>From <input type="date" name="from" value="<?= htmlspecialchars($rFrom) ?>" required></label>
        <label>To <input type="date" name="to" value="<?= htmlspecialchars($rTo) ?>" required></label>
        <label>Employees
            <select name="shift">
                <option value="">Everyone</option>
                <?php foreach ($shifts as $s): ?><option value="<?= (int) $s['ID'] ?>" <?= $rShift === (int) $s['ID'] ? 'selected' : '' ?>>On <?= htmlspecialchars($s['Name']) ?></option><?php endforeach; ?>
            </select>
        </label>
        <button type="submit" class="btn secondary">Preview</button>
    </form>

    <?php if ($recalc !== null): ?>
        <?php if (!$recalc['summary']): ?>
            <p style="margin-top:1rem;">No punches change in that range.</p>
        <?php else: ?>
            <h3 style="margin-top:1.25rem;"><?= $recalc['applied'] ? '✅ Recalculated' : 'Preview — nothing saved yet' ?></h3>
            <table class="uman-table">
                <thead><tr><th>Employee</th><th>Punches changed</th><th>Paid hours before</th><th>After</th><th>Difference</th></tr></thead>
                <tbody>
                <?php foreach ($recalc['summary'] as $row): $diff = $row['after'] - $row['before']; ?>
                    <tr>
                        <td><?= htmlspecialchars($row['name']) ?></td>
                        <td><?= (int) $row['rows'] ?></td>
                        <td><?= number_format($row['before'], 2) ?></td>
                        <td><?= number_format($row['after'], 2) ?></td>
                        <td style="color:<?= $diff < 0 ? '#b02a37' : '#1e7e34' ?>;"><?= ($diff >= 0 ? '+' : '') . number_format($diff, 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (!$recalc['applied']): ?>
                <form method="POST" style="margin-top:.75rem;" onsubmit="return confirm('Re-apply the rules to these punches? Paid hours will change as shown.')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="recalc">
                    <input type="hidden" name="apply" value="1">
                    <input type="hidden" name="from" value="<?= htmlspecialchars($recalc['from']) ?>">
                    <input type="hidden" name="to" value="<?= htmlspecialchars($recalc['to']) ?>">
                    <input type="hidden" name="shift" value="<?= $recalc['shift'] === null ? '' : (int) $recalc['shift'] ?>">
                    <button type="submit" class="btn primary">Apply these changes</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>
