<?php
// admin/holidays.php — the company holiday calendar (Settings -> Holidays).
// Holidays are stored as rules (functions/holidays.php works out each year's date),
// so the list carries over from year to year. On a holiday, full-day time off uses no
// PTO/Sick hours and the Home board doesn't flag no-shows or late arrivals.
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

const HOL_MONTHS   = [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const HOL_WEEKDAYS = [1 => 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
const HOL_ORDINALS = [1 => '1st', '2nd', '3rd', '4th', 'last' => 'Last'];

/** "4th Thursday of November", "Every December 25", "Easter − 2 days" ... */
function holidayRuleText(array $r): string
{
    $month = HOL_MONTHS[(int) $r['Month']] ?? '';
    $wd    = HOL_WEEKDAYS[(int) $r['Weekday']] ?? '';
    switch ($r['RuleType']) {
        case 'fixed':  $text = "Every {$month} " . (int) $r['Day']; break;
        case 'nth':    $text = (HOL_ORDINALS[(int) $r['Day']] ?? '') . " {$wd} of {$month}"; break;
        case 'last':   $text = "Last {$wd} of {$month}"; break;
        case 'easter': $text = 'Easter Sunday'; break;
        case 'once':   return 'One time: ' . date('D M j, Y', strtotime((string) $r['OnceDate']));
        default:       return '?';
    }
    $off = (int) $r['OffsetDays'];
    if ($off !== 0) {
        $text .= $off > 0 ? " + {$off} day" . ($off === 1 ? '' : 's') : " − " . abs($off) . ' day' . ($off === -1 ? '' : 's');
    }
    return $text;
}

function holidayDateText(?string $date): string
{
    return $date ? date('D M j', strtotime($date)) : '—';
}

$thisYear = (int) date('Y');
$errors = [];
$notice = $_GET['notice'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf('holidays.php');
    $action = $_POST['action'] ?? '';

    if ($action === 'add_presets') {
        $keys = array_values(array_intersect(array_keys(HOLIDAY_PRESETS), (array) ($_POST['presets'] ?? [])));
        $st = $conn->prepare("INSERT IGNORE INTO holidays (Name, RuleType, Month, Day, Weekday, OffsetDays, ObserveWeekday, PresetKey) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $added = 0;
        foreach ($keys as $key) {
            $r = holidayPresetRow($key);
            $st->bind_param("ssiiiiis", $r['Name'], $r['RuleType'], $r['Month'], $r['Day'], $r['Weekday'], $r['OffsetDays'], $r['ObserveWeekday'], $key);
            $st->execute();
            $added += $st->affected_rows;
        }
        header('Location: holidays.php?notice=' . urlencode($added ? "Added {$added} holiday" . ($added === 1 ? '' : 's') . '.' : 'Nothing selected.'));
        exit;
    }

    if ($action === 'add_custom') {
        $name    = trim((string) ($_POST['Name'] ?? ''));
        $kind    = (string) ($_POST['Kind'] ?? '');
        $observe = !empty($_POST['ObserveWeekday']) ? 1 : 0;
        $row = ['RuleType' => null, 'Month' => null, 'Day' => null, 'Weekday' => null, 'OnceDate' => null];
        if ($name === '' || mb_strlen($name) > 100) {
            $errors[] = 'Give the holiday a name (up to 100 characters).';
        }
        if ($kind === 'fixed') {
            $m = (int) ($_POST['FixedMonth'] ?? 0);
            $d = (int) ($_POST['FixedDay'] ?? 0);
            if (!checkdate($m, $d, 2024)) { // 2024: leap year, so Feb 29 is allowed
                $errors[] = 'Pick a real month and day.';
            }
            $row = ['RuleType' => 'fixed', 'Month' => $m, 'Day' => $d, 'Weekday' => null, 'OnceDate' => null];
        } elseif ($kind === 'weekday') {
            $which = (string) ($_POST['Which'] ?? '');
            $wd    = (int) ($_POST['Weekday'] ?? 0);
            $m     = (int) ($_POST['WeekdayMonth'] ?? 0);
            if (!isset(HOL_ORDINALS[$which]) || !isset(HOL_WEEKDAYS[$wd]) || !isset(HOL_MONTHS[$m])) {
                $errors[] = 'Pick which weekday and month.';
            }
            $row = $which === 'last'
                ? ['RuleType' => 'last', 'Month' => $m, 'Day' => null, 'Weekday' => $wd, 'OnceDate' => null]
                : ['RuleType' => 'nth', 'Month' => $m, 'Day' => (int) $which, 'Weekday' => $wd, 'OnceDate' => null];
        } elseif ($kind === 'once') {
            $date = (string) ($_POST['OnceDate'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
                $errors[] = 'Pick the date.';
            }
            $row = ['RuleType' => 'once', 'Month' => null, 'Day' => null, 'Weekday' => null, 'OnceDate' => $date];
            $observe = 0;
        } else {
            $errors[] = 'Choose how the date is set.';
        }
        if (!$errors) {
            $st = $conn->prepare("INSERT INTO holidays (Name, RuleType, Month, Day, Weekday, OffsetDays, OnceDate, ObserveWeekday) VALUES (?, ?, ?, ?, ?, 0, ?, ?)");
            $st->bind_param("ssiiisi", $name, $row['RuleType'], $row['Month'], $row['Day'], $row['Weekday'], $row['OnceDate'], $observe);
            $st->execute();
            header('Location: holidays.php?notice=' . urlencode("Added {$name}."));
            exit;
        }
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $st = $conn->prepare("SELECT Name FROM holidays WHERE ID = ?");
        $st->bind_param("i", $id);
        $st->execute();
        $name = $st->get_result()->fetch_column();
        if ($name !== false) {
            $st = $conn->prepare("DELETE FROM holidays WHERE ID = ?");
            $st->bind_param("i", $id);
            $st->execute();
            header('Location: holidays.php?notice=' . urlencode("Removed {$name}."));
            exit;
        }
        header('Location: holidays.php');
        exit;
    }
}

// ---------- Current list ----------
$tableReady = true;
try {
    $rules = $conn->query("SELECT * FROM holidays")->fetch_all(MYSQLI_ASSOC);
} catch (mysqli_sql_exception $e) {
    $tableReady = false; // migration not applied yet
    $rules = [];
}
holidayRules($conn, true);
$scheduled = [];
foreach ([$thisYear, $thisYear + 1] as $y) {
    foreach (holidayScheduleForYear($y) as $h) {
        $scheduled[$y][(int) $h['rule']['ID']] = $h['date'];
    }
}
foreach ($rules as &$r) {
    $r['_this'] = $scheduled[$thisYear][(int) $r['ID']] ?? null;
    $r['_next'] = $scheduled[$thisYear + 1][(int) $r['ID']] ?? null;
    $r['_sort'] = $r['_this'] ?? $r['_next'] ?? ($r['OnceDate'] ?? '9999');
    // Sort by month/day of the year so the list reads like a calendar
    $r['_sort'] = substr($r['_sort'], 5) . $r['_sort'];
}
unset($r);
usort($rules, fn($a, $b) => strcmp($a['_sort'], $b['_sort']));
$addedPresets = array_filter(array_column($rules, 'PresetKey'));
$available = array_diff(array_keys(HOLIDAY_PRESETS), $addedPresets);

// Next 12 months, observed dates
$upcoming = [];
$today = date('Y-m-d');
$until = date('Y-m-d', strtotime('+1 year'));
foreach ([$thisYear, $thisYear + 1] as $y) {
    foreach (companyHolidaysForYear($y) as $date => $label) {
        if ($date >= $today && $date <= $until) {
            $upcoming[$date] = $label;
        }
    }
}
ksort($upcoming);

$pageTitle = "Holidays";
require_once 'header.php';
?>
<style>
    .page-holidays .container { max-width: 1200px; }
    .hol-grid { display: grid; grid-template-columns: minmax(0, 3fr) minmax(0, 2fr); gap: 1.5rem; align-items: start; }
    @media (max-width: 900px) { .hol-grid { grid-template-columns: minmax(0, 1fr); } }
    .hol-table { width: 100%; border-collapse: collapse; font-size: .92rem; margin: 0; }
    .hol-table th, .hol-table td { padding: .45rem .6rem; border-bottom: 1px solid #eef1f4; text-align: left; }
    .hol-table th { color: var(--primary-color); background: #eef3f7; }
    .hol-table td:nth-child(3), .hol-table td:nth-child(4), .hol-table td:nth-child(5) { white-space: nowrap; }
    .hol-table .muted, .hol-muted { color: var(--muted-text); font-size: .85em; }
    .preset-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: .35rem .75rem; margin: .5rem 0 1rem; }
    .preset-list label { display: flex; gap: .5rem; align-items: baseline; font-weight: normal; cursor: pointer; }
    .preset-list input { width: auto; margin: 0; }
    .custom-form .row { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; margin: .4rem 0; }
    .custom-form select, .custom-form input[type="text"], .custom-form input[type="date"] { width: auto; margin: 0; }
    .custom-form input[name="Name"] { min-width: 240px; }
    .custom-form .kind { display: flex; gap: .5rem; align-items: center; font-weight: normal; }
    .custom-form .kind input { width: auto; margin: 0; }
    .notice { background: #e7f5ec; color: #146c43; padding: .6rem .9rem; border-radius: 6px; margin-bottom: 1rem; }
    .errors { background: #fdecea; color: #b02a37; padding: .6rem .9rem; border-radius: 6px; margin-bottom: 1rem; }
    .upcoming li { padding: .2rem 0; }
</style>

<p class="hol-muted" style="margin-top:0;">
    Days the company is closed. On a holiday, full-day time off doesn't use any PTO or Sick hours (like a weekend),
    and the Home board doesn't flag no-shows or late arrivals. Holidays don't add pay by themselves.
    A holiday that lands on a Saturday is observed the Friday before, and on a Sunday the Monday after,
    unless you turn that off for a custom holiday.
</p>

<?php if ($notice !== ''): ?><div class="notice"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="errors"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div><?php endif; ?>
<?php if (!$tableReady): ?>
    <div class="errors">The holidays table doesn't exist yet. Run the update (deploy/scripts/update.sh) to apply the database migration; until then the six standard holidays apply.</div>
<?php endif; ?>

<div class="hol-grid">
<div>
    <h2 style="margin-top:0;">Company holidays</h2>
    <table class="hol-table">
        <thead><tr><th>Holiday</th><th>Rule</th><th><?= $thisYear ?></th><th><?= $thisYear + 1 ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rules as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['Name']) ?></td>
                <td class="muted"><?= htmlspecialchars(holidayRuleText($r)) ?></td>
                <td><?= holidayDateText($r['_this']) ?></td>
                <td><?= holidayDateText($r['_next']) ?></td>
                <td>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Remove <?= htmlspecialchars(addslashes($r['Name']), ENT_QUOTES) ?> from the holiday list?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $r['ID'] ?>">
                        <button type="submit" class="btn secondary small">Remove</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rules && $tableReady): ?>
            <tr><td colspan="5" class="muted">No holidays. Add some below.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if ($tableReady && $available): ?>
    <h2 style="margin-top:2rem;">Add common holidays</h2>
    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_presets">
        <div class="preset-list">
            <?php foreach ($available as $key): $p = holidayPresetRow($key); ?>
            <label>
                <input type="checkbox" name="presets[]" value="<?= htmlspecialchars($key) ?>">
                <span><?= htmlspecialchars($p['Name']) ?> <span class="hol-muted"><?= holidayDateText(holidayObservedDate($p, $thisYear) >= $today ? holidayObservedDate($p, $thisYear) : holidayObservedDate($p, $thisYear + 1)) ?></span></span>
            </label>
            <?php endforeach; ?>
        </div>
        <button type="submit" class="btn primary">Add selected</button>
    </form>
    <?php endif; ?>

    <?php if ($tableReady): ?>
    <h2 style="margin-top:2rem;">Add a custom holiday</h2>
    <form method="POST" class="custom-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_custom">
        <div class="row">
            <label for="hName">Name</label>
            <input type="text" id="hName" name="Name" maxlength="100" placeholder="e.g. Company picnic, Plant shutdown" value="<?= htmlspecialchars($_POST['Name'] ?? '') ?>" required>
        </div>
        <div class="row">
            <label class="kind"><input type="radio" name="Kind" value="fixed" <?= ($_POST['Kind'] ?? 'fixed') === 'fixed' ? 'checked' : '' ?>> Same date every year:</label>
            <select name="FixedMonth" aria-label="Month"><?php foreach (HOL_MONTHS as $n => $m): ?><option value="<?= $n ?>"><?= $m ?></option><?php endforeach; ?></select>
            <select name="FixedDay" aria-label="Day"><?php for ($d = 1; $d <= 31; $d++): ?><option value="<?= $d ?>"><?= $d ?></option><?php endfor; ?></select>
        </div>
        <div class="row">
            <label class="kind"><input type="radio" name="Kind" value="weekday" <?= ($_POST['Kind'] ?? '') === 'weekday' ? 'checked' : '' ?>> A weekday of a month:</label>
            <select name="Which" aria-label="Which"><?php foreach (HOL_ORDINALS as $k => $o): ?><option value="<?= $k ?>"><?= $o ?></option><?php endforeach; ?></select>
            <select name="Weekday" aria-label="Weekday"><?php foreach (HOL_WEEKDAYS as $n => $w): ?><option value="<?= $n ?>"><?= $w ?></option><?php endforeach; ?></select>
            <span>of</span>
            <select name="WeekdayMonth" aria-label="Month"><?php foreach (HOL_MONTHS as $n => $m): ?><option value="<?= $n ?>"><?= $m ?></option><?php endforeach; ?></select>
        </div>
        <div class="row">
            <label class="kind"><input type="radio" name="Kind" value="once" <?= ($_POST['Kind'] ?? '') === 'once' ? 'checked' : '' ?>> One date only:</label>
            <input type="date" name="OnceDate" aria-label="Date" value="<?= htmlspecialchars($_POST['OnceDate'] ?? '') ?>">
        </div>
        <div class="row">
            <label class="kind"><input type="checkbox" name="ObserveWeekday" value="1" checked> On a weekend, observe it on the nearest weekday (Sat → Fri, Sun → Mon)</label>
        </div>
        <button type="submit" class="btn primary">Add holiday</button>
    </form>
    <?php endif; ?>
</div>

<div class="panel">
    <h3>Next 12 months</h3>
    <?php if ($upcoming): ?>
    <ul class="upcoming" style="margin:0; padding-left:1.1rem;">
        <?php foreach ($upcoming as $date => $label): ?>
        <li><strong><?= date('D M j, Y', strtotime($date)) ?></strong> — <?= htmlspecialchars($label) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php else: ?>
    <p class="hol-muted">No holidays in the next 12 months.</p>
    <?php endif; ?>
</div>
</div>

<script>
// Picking a value in a custom-holiday row selects that row's radio button
document.querySelectorAll('.custom-form .row').forEach(function (row) {
    var radio = row.querySelector('input[type=radio]');
    if (!radio) return;
    row.querySelectorAll('select, input[type=date]').forEach(function (el) {
        el.addEventListener('change', function () { radio.checked = true; });
    });
});
</script>

<?php require_once 'footer.php'; ?>
