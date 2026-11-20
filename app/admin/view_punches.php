<?php
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

// Permission check: only super admins can edit timesheets
require_once __DIR__ . '/../functions/check_permission.php';
require_once __DIR__ . '/../functions/settings_helper.php';
require_once __DIR__ . '/../functions/hours.php';
require_once __DIR__ . '/../functions/pay_rules.php';
require_once __DIR__ . '/../functions/csrf.php';
require_once __DIR__ . '/../functions/time_off_hours.php'; // approved PTO / Sick / absences per day
require_once __DIR__ . '/../functions/holiday_pay.php';    // holiday hours per employee
requirePermission('edit_timesheets');

/**
 * Approved time off and company holidays for one employee, per day, for the read-only
 * lines on the timesheet (never part of the punch form).
 * Each line: kind (PTO|Sick|Unpaid|Unexcused|Holiday), label, hours (null = not shown),
 * suffix (after the hours), note. Totals: PTO, Sick, Holiday hours.
 * @return array{days:array<string,list<array>>,totals:array{PTO:float,Sick:float,Holiday:float},holidayPay:bool,hasTimeOff:bool}
 */
function vpTimeOffAndHolidays(mysqli $conn, int $empID, string $start, string $end): array
{
    $days = [];
    $totals = ['PTO' => 0.0, 'Sick' => 0.0, 'Holiday' => 0.0];

    // Company holidays (paid or not); hours only while holiday pay is on
    $policy = holidayPayPolicy($conn);
    $holidayPay   = $policy['enabled'] ? (holidayPayByEmployee($conn, $start, $end)[$empID]['days'] ?? []) : [];
    $paidHolidays = $policy['enabled'] ? paidHolidaysInRange($start, $end) : [];
    for ($d = new DateTime($start), $fin = new DateTime($end); $d <= $fin; $d->modify('+1 day')) {
        $date = $d->format('Y-m-d');
        $name = $holidayPay[$date]['name'] ?? isCompanyHoliday($date);
        if ($name === null) {
            continue;
        }
        $line = ['kind' => 'Holiday', 'label' => 'Holiday: ' . $name, 'hours' => null, 'suffix' => '', 'note' => ''];
        if (isset($holidayPay[$date])) {
            $hp = $holidayPay[$date];
            $line['hours']  = (float) $hp['hours'];
            $line['suffix'] = 'holiday pay';
            if (!empty($hp['adjusted'])) {
                $line['note'] = 'Hours set by an admin' . (!empty($hp['note']) ? ': ' . $hp['note'] : '');
            }
            $totals['Holiday'] += (float) $hp['hours'];
        } elseif ($policy['enabled']) {
            $line['note'] = isset($paidHolidays[$date]) ? 'No holiday pay for this day' : 'Unpaid holiday';
        }
        $days[$date][] = $line;
    }

    // Approved time off, on the days it is charged
    $hasTimeOff = false;
    foreach (fetchApprovedTimeOff($conn, $start, $end, $empID) as $req) {
        foreach (expandTimeOffToDays($req, $start, $end) as $dr) {
            $hasTimeOff = true;
            $cat  = $dr['Category'];
            $paid = !in_array($cat, TIME_OFF_UNPAID, true);
            $desc = ['Category' => $cat, 'StartTime' => $dr['StartTime'], 'EndTime' => $dr['EndTime'], 'Hours' => $dr['PartHours']];
            $label = ($paid && $dr['PartHours'] !== null)
                ? timeOffLabel($cat) . ' (part day)'
                : timeOffDescribe($desc, false); // "Sick 9am–1pm", "Unexcused absence 2h"
            $days[$dr['Date']][] = [
                'kind'   => $cat,
                'label'  => $label,
                'hours'  => $paid ? (float) $dr['Hours'] : null,
                'suffix' => '',
                'note'   => trim((string) ($dr['Notes'] ?? '')),
            ];
            if ($paid) {
                $totals[$cat === 'Sick' ? 'Sick' : 'PTO'] += (float) $dr['Hours'];
            }
        }
    }
    return ['days' => $days, 'totals' => $totals, 'holidayPay' => $policy['enabled'], 'hasTimeOff' => $hasTimeOff];
}

date_default_timezone_set('America/Chicago');

$employeeList = $conn->query("SELECT ID, FirstName, CONCAT(LastName, IF(ArchivedAt IS NULL, '', ' (archived)')) AS LastName FROM users ORDER BY ArchivedAt IS NOT NULL, LastName, FirstName"); // archived marked, listed last

$from = $_GET['from'] ?? payPeriodStart(date('Y-m-d')); // current work week
$to = $_GET['to'] ?? payPeriodEnd(date('Y-m-d'));
$employeeID = $_GET['emp'] ?? '';
$editMode = isset($_GET['mode']) && $_GET['mode'] === 'edit';

// Location columns are only useful when GPS capture is enabled; otherwise they
// are always blank and just take up space, so hide them.
$showLocations = (getSettingValue('EnforceGPS', $conn) === '1');

$fromDisplay = htmlspecialchars(date('m/d/Y', strtotime($from)));
$toDisplay   = htmlspecialchars(date('m/d/Y', strtotime($to)));
$empParam    = urlencode($employeeID);
$fromParam   = urlencode(date('m/d/Y', strtotime($from)));
$toParam     = urlencode(date('m/d/Y', strtotime($to)));

$pageTitle = "Timesheets";
$extraCSS = [
    "https://cdn.jsdelivr.net/npm/litepicker/dist/css/litepicker.css",
    "../css/view_punches.css?v=3",
];
require_once 'header.php';
?>

<?php
// Pull the per-save notice first: a save can partly succeed (some rows stored, one
// row rejected), and showing "✅ Timesheet saved" above "⛔ Not saved" reads as a
// contradiction. When anything was rejected, lead with the rejection.
$notice = $_SESSION['punch_save_notice'] ?? null;
unset($_SESSION['punch_save_notice']);
?>

<?php if (($_GET['success'] ?? '') === '1' && empty($notice['skipped'])): ?>
    <div class="alert alert-success" style="margin-bottom:1rem;">✅ Timesheet saved.</div>
<?php elseif (($_GET['success'] ?? '') === '0'):
    $errMessages = [
        'missing_fields' => 'Missing required fields — please reselect the employee and date range, then try again.',
        'exception'      => 'A server error prevented the save. No changes were made. Please try again; if it keeps happening, contact support.',
        'csrf'           => 'This page had been open too long and the save was rejected for safety. No changes were made — reload the timesheet and re-enter them.',
    ];
    $errKey = $_GET['error'] ?? '';
    $errMsg = $errMessages[$errKey] ?? 'The timesheet could not be saved. No changes were made.'; ?>
    <div class="alert alert-error" style="margin-bottom:1rem; background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; padding:10px; border-radius:5px;">
        ⛔ <strong>Not saved:</strong> <?= htmlspecialchars($errMsg) ?>
    </div>
<?php endif; ?>

<?php if (!empty($notice)): ?>
    <?php if (!empty($notice['skipped'])): ?>
        <div class="alert alert-error" style="margin-bottom:1rem; background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; padding:10px; border-radius:5px;">
            ⛔ <strong>These rows were not saved (invalid data)</strong> — every other change on this timesheet was saved:
            <ul style="margin:0.3rem 0 0 1.2rem;">
                <?php foreach ($notice['skipped'] as $s): ?><li><?= htmlspecialchars($s) ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <?php if (!empty($notice['flagged'])): ?>
        <div class="alert" style="margin-bottom:1rem; background:#fff3cd; color:#856404; border:1px solid #ffeeba; padding:10px; border-radius:5px;">
            ⚠️ <strong>Saved but flagged for approval (anomalies):</strong>
            <ul style="margin:0.3rem 0 0 1.2rem;">
                <?php foreach ($notice['flagged'] as $f): ?><li><?= htmlspecialchars($f) ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
<?php endif; ?>

<!-- Filter card -->
<div class="vp-card">
    <div class="vp-card-header">
        <h2>Select Employee &amp; Date Range</h2>
    </div>
    <form method="GET" class="vp-filter">
        <div class="field">
            <label for="weekFrom">From</label>
            <input type="text" name="from" id="weekFrom" value="<?= $fromDisplay ?>" autocomplete="off">
        </div>
        <div class="field">
            <label for="weekTo">To</label>
            <input type="text" name="to" id="weekTo" value="<?= $toDisplay ?>" autocomplete="off">
        </div>
        <div class="field">
            <label for="emp">Employee</label>
            <select name="emp" id="emp" required>
                <option value="">Select Employee</option>
                <?php while ($emp = $employeeList->fetch_assoc()): ?>
                    <option value="<?= $emp['ID'] ?>" <?= ($emp['ID'] == $employeeID ? 'selected' : '') ?>>
                        <?= htmlspecialchars($emp['FirstName'] . ' ' . $emp['LastName']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>
        <?php if ($editMode): ?><input type="hidden" name="mode" value="edit"><?php endif; ?>
        <div class="field vp-filter-buttons" style="flex:0 0 auto;">
            <button type="submit" class="btn primary">Show Timesheet</button>
            <a href="view_punches.php" class="btn secondary">Reset</a>
        </div>
    </form>
</div>

<?php if (!empty($employeeID)): ?>
<?php
    // Shift rules: paid times are shown under the punched ones, and in edit mode each
    // punch gets "worked through lunch" / "pay outside shift" overrides.
    $empShift  = employeeShift($conn, (int) $employeeID);
    $payPolicy = payPolicy($conn);
    $hasRules  = $empShift !== null || $payPolicy['roundIncrement'] > 0;

    // Column layout depends on mode + whether locations are shown
    $dataCols = 4 + ($showLocations ? 4 : 0); // clock-in/lunch-out/lunch-in/clock-out (+ their locations)
    $emptyColspan = $dataCols + 1; // + total column (read-only); edit adds reason+action below

    // Approved time off + company holidays, shown as read-only lines in the day list
    $extras = vpTimeOffAndHolidays($conn, (int) $employeeID, date('Y-m-d', strtotime($from)), date('Y-m-d', strtotime($to)));
?>
<style>
    /* Time off / holiday lines: read-only, no inputs, never posted with the punch form */
    .timesheet-table tr.vp-extra td { background: #e8f1fc; color: #222; }
    .timesheet-table tr.vp-extra td.vp-extra-text { text-align: left; white-space: normal; }
    .timesheet-table tr.vp-extra-sick td { background: #fdecea; }
    .timesheet-table tr.vp-extra-unpaid td { background: #eceff1; }
    .timesheet-table tr.vp-extra-unexcused td { background: #ffe0b2; }
    .timesheet-table tr.vp-extra-holiday td { background: #e6f4ea; }
    .timesheet-table tr.vp-extra .vp-extra-note { color: #555; font-size: 0.8rem; margin-left: 0.4rem; }
</style>

<?php $lockedThrough = payrollLockedThrough($conn); ?>
<?php if ($editMode && $lockedThrough !== null && date('Y-m-d', strtotime($from)) <= $lockedThrough): ?>
    <div class="alert" style="margin-bottom:1rem; background:#eef3f6; padding:10px; border-radius:5px;">
        🔒 Payroll is closed through <strong><?= htmlspecialchars(date('m/d/Y', strtotime($lockedThrough))) ?></strong>, so those days can't be edited.
        A super admin can reopen it on <a href="pay_rules.php">Settings › Pay Rules</a>.
    </div>
<?php endif; ?>

<!-- Timesheet card -->
<div class="vp-card">
    <div class="vp-card-header">
        <h2><?= $editMode ? 'Edit Timesheet' : 'Timesheet' ?>
            <?php if ($empShift): ?><span style="font-size:.8rem; font-weight:normal; margin-left:.5rem;"><?= shiftChip($empShift) ?> <?= htmlspecialchars(shiftTimeRange($empShift)) ?></span><?php endif; ?>
        </h2>
        <div class="vp-actions">
            <?php if ($editMode): ?>
                <button type="button" class="btn secondary" onclick="addNewPunch()">+ Add Punch</button>
                <button type="submit" form="punchForm" class="btn primary">Save All Changes</button>
                <a href="view_punches.php?emp=<?= $empParam ?>&from=<?= $fromParam ?>&to=<?= $toParam ?>" class="btn secondary">Cancel</a>
            <?php else: ?>
                <a href="punch_history.php?emp=<?= $empParam ?>&from=<?= htmlspecialchars($from) ?>&to=<?= htmlspecialchars($to) ?>" class="btn secondary">📜 Change History</a>
                <?php if (checkPermission('approve_edits')): ?>
                <a href="record_time_off.php?emp=<?= $empParam ?>" class="btn secondary" title="Record PTO, sick time or an absence (with the reason) for this employee">🏖️ Record Time Off / Absence</a>
                <?php endif; ?>
                <a href="view_punches.php?emp=<?= $empParam ?>&from=<?= $fromParam ?>&to=<?= $toParam ?>&mode=edit" class="btn primary">✎ Edit Timesheet</a>
            <?php endif; ?>
        </div>
    </div>

    <form method="POST" action="save_punches.php" id="punchForm">
        <?= csrf_field() ?>
        <input type="hidden" name="employeeID" value="<?= htmlspecialchars($employeeID) ?>">
        <input type="hidden" name="from" value="<?= htmlspecialchars($from) ?>">
        <input type="hidden" name="to" value="<?= htmlspecialchars($to) ?>">
        <?php if ($editMode): ?><input type="hidden" name="mode" value="edit"><?php endif; ?>

        <div class="table-scroll">
        <table class="timesheet-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Clock In</th>
                    <?php if ($showLocations): ?><th>In Loc</th><?php endif; ?>
                    <th>Lunch Out</th>
                    <?php if ($showLocations): ?><th>Loc</th><?php endif; ?>
                    <th>Lunch In</th>
                    <?php if ($showLocations): ?><th>Loc</th><?php endif; ?>
                    <th>Clock Out</th>
                    <?php if ($showLocations): ?><th>Out Loc</th><?php endif; ?>
                    <th>Total</th>
                    <?php if ($editMode): ?>
                        <th>Reason for Adjustment</th>
                        <th>Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php
                $start = new DateTime($from);
                $end = new DateTime($to);
                $interval = new DateInterval('P1D');
                $range = new DatePeriod($start, $interval, $end->modify('+1 day'));
                $weekTotal = 0.0;

                $reasons = ['Forgot to punch','Shift change','System error','Time correction','Late arrival','Early departure','Manual update'];

                // Reusable cell renderer for a location map link
                $locCell = function ($lat, $lon) {
                    if (!empty($lat) && !empty($lon)) {
                        return '<a href="https://www.google.com/maps?q=' . htmlspecialchars($lat) . ',' . htmlspecialchars($lon) . '" target="_blank" class="btn-view">Map</a>';
                    }
                    return '<span class="vp-ro empty">—</span>';
                };

                foreach ($range as $day):
                    $date = $day->format('Y-m-d');

                    $stmt = $conn->prepare("SELECT * FROM timepunches WHERE EmployeeID = ? AND Date = ? ORDER BY TimeIN");
                    $stmt->bind_param("is", $employeeID, $date);
                    $stmt->execute();
                    $dayRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    // Flags (late, stayed past the shift, auto lunch) under today's rules
                    $dayRules = $hasRules ? applyShiftRules($dayRows, $empShift, $payPolicy, $date) : [];

                    if ($dayRows):
                        foreach ($dayRows as $punch):
                            $clockIn   = !empty($punch['TimeIN'])      ? date('H:i', strtotime($punch['TimeIN']))     : '';
                            $clockOut  = !empty($punch['TimeOut'])     ? date('H:i', strtotime($punch['TimeOut']))    : '';
                            $lunchOut  = !empty($punch['LunchStart'])  ? date('H:i', strtotime($punch['LunchStart'])) : '';
                            $lunchIn   = !empty($punch['LunchEnd'])    ? date('H:i', strtotime($punch['LunchEnd']))   : '';
                            $rowTotal  = (float) ($punch['TotalHours'] ?? 0);
                            $weekTotal += $rowTotal;

                            // Read-only display helper
                            $disp = function ($t) { return $t !== '' ? '<span class="vp-ro">' . htmlspecialchars(date('g:i a', strtotime($t))) . '</span>' : '<span class="vp-ro empty">—</span>'; };
                            // "→ 7:00 am" under a punch whose paid time differs from the punched minute
                            $paidNote = function ($raw, $paid) {
                                if ($raw === '' || empty($paid) || substr($paid, 0, 5) === $raw) return '';
                                return '<div class="vp-paid" title="Paid time">→ ' . htmlspecialchars(date('g:i a', strtotime($paid))) . '</div>';
                            };
                            $punchedLunchMin = ($lunchOut !== '' && $lunchIn !== '') ? max(0, intdiv(hms_to_seconds($lunchIn) - hms_to_seconds($lunchOut), 60)) : 0;
                            $rowFlags = array_diff($dayRules[$punch['id']]['flags'] ?? [], ['auto_lunch']);
                            $rowNotes = [];
                            if ((int) ($punch['LunchDeductMin'] ?? 0) > $punchedLunchMin) {
                                $rowNotes[] = 'Lunch −' . (int) $punch['LunchDeductMin'] . 'm' . ($punchedLunchMin ? '' : ' auto');
                            }
                            if (!empty($punch['LunchWaived'])) $rowNotes[] = 'Worked through lunch';
                            if (!empty($punch['OutsideApproved'])) $rowNotes[] = 'Outside time approved';
                            foreach ($rowFlags as $f) $rowNotes[] = PUNCH_FLAG_LABELS[$f] ?? $f;
                            if ($punch['RawHours'] !== null && $punch['TotalHours'] !== null && abs((float) $punch['RawHours'] - (float) $punch['TotalHours']) >= 0.005) {
                                $rowNotes[] = 'Actual ' . number_format((float) $punch['RawHours'], 2);
                            }
                            // Closed payroll: shown read-only (save_punches.php rejects changes anyway)
                            $rowLocked = $editMode && isPayrollLocked($conn, $date);
                ?>
                <tr>
                    <td><?= $day->format('m/d/Y') ?></td>

                    <td><?php if ($editMode): ?><input type="time" name="clockin[<?= $punch['id'] ?>]" value="<?= $clockIn ?>" step="60"<?= $rowLocked ? ' readonly' : '' ?>><?php else: ?><?= $disp($clockIn) ?><?php endif; ?><?= $paidNote($clockIn, $punch['PaidIn'] ?? null) ?></td>
                    <?php if ($showLocations): ?><td><?= $locCell($punch['LatitudeIN'] ?? null, $punch['LongitudeIN'] ?? null) ?></td><?php endif; ?>

                    <td><?php if ($editMode): ?><input type="time" name="lunchout[<?= $punch['id'] ?>]" value="<?= $lunchOut ?>" step="60"<?= $rowLocked ? ' readonly' : '' ?>><?php else: ?><?= $disp($lunchOut) ?><?php endif; ?></td>
                    <?php if ($showLocations): ?><td><?= $locCell($punch['LatitudeLunchStart'] ?? null, $punch['LongitudeLunchStart'] ?? null) ?></td><?php endif; ?>

                    <td><?php if ($editMode): ?><input type="time" name="lunchin[<?= $punch['id'] ?>]" value="<?= $lunchIn ?>" step="60"<?= $rowLocked ? ' readonly' : '' ?>><?php else: ?><?= $disp($lunchIn) ?><?php endif; ?></td>
                    <?php if ($showLocations): ?><td><?= $locCell($punch['LatitudeLunchEnd'] ?? null, $punch['LongitudeLunchEnd'] ?? null) ?></td><?php endif; ?>

                    <td><?php if ($editMode): ?><input type="time" name="clockout[<?= $punch['id'] ?>]" value="<?= $clockOut ?>" step="60"<?= $rowLocked ? ' readonly' : '' ?>><?php else: ?><?= $disp($clockOut) ?><?php endif; ?><?= $paidNote($clockOut, $punch['PaidOut'] ?? null) ?></td>
                    <?php if ($showLocations): ?><td><?= $locCell($punch['LatitudeOut'] ?? null, $punch['LongitudeOut'] ?? null) ?></td><?php endif; ?>

                    <td>
                        <div class="total-cell" id="total-<?= $punch['id'] ?>"><?= number_format($rowTotal, 2) ?></div>
                        <?php if ($rowNotes): ?><div class="vp-paid"><?= implode('<br>', array_map('htmlspecialchars', $rowNotes)) ?></div><?php endif; ?>
                    </td>

                    <?php if ($editMode && $rowLocked): ?>
                    <td class="vp-paid">🔒 Payroll closed
                        <input type="hidden" name="lunchwaived[<?= $punch['id'] ?>]" value="<?= (int) $punch['LunchWaived'] ?>">
                        <input type="hidden" name="outsideapproved[<?= $punch['id'] ?>]" value="<?= (int) $punch['OutsideApproved'] ?>">
                    </td>
                    <td></td>
                    <?php elseif ($editMode): ?>
                    <td>
                        <select name="reason[<?= $punch['id'] ?>]" class="reason-dropdown">
                            <option value="">Select reason...</option>
                            <?php foreach ($reasons as $r): ?>
                                <option value="<?= $r ?>"><?= $r ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($empShift): ?>
                        <div class="vp-overrides">
                            <?php if ($empShift['LunchMode'] !== 'punched'): ?>
                            <label><input type="checkbox" name="lunchwaived[<?= $punch['id'] ?>]" value="1" <?= !empty($punch['LunchWaived']) ? 'checked' : '' ?>> Worked through lunch</label>
                            <?php else: ?>
                            <input type="hidden" name="lunchwaived[<?= $punch['id'] ?>]" value="<?= (int) $punch['LunchWaived'] ?>">
                            <?php endif; ?>
                            <?php if ($empShift['OutsidePolicy'] === 'cap'): ?>
                            <label><input type="checkbox" name="outsideapproved[<?= $punch['id'] ?>]" value="1" <?= !empty($punch['OutsideApproved']) ? 'checked' : '' ?>> Pay outside shift</label>
                            <?php else: ?>
                            <input type="hidden" name="outsideapproved[<?= $punch['id'] ?>]" value="<?= (int) $punch['OutsideApproved'] ?>">
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                        <input type="hidden" name="lunchwaived[<?= $punch['id'] ?>]" value="<?= (int) $punch['LunchWaived'] ?>">
                        <input type="hidden" name="outsideapproved[<?= $punch['id'] ?>]" value="<?= (int) $punch['OutsideApproved'] ?>">
                        <?php endif; ?>
                    </td>
                    <td>
                        <button type="button" class="delete-btn" onclick="deleteRow(this)" title="Delete this punch">✖</button>
                    </td>
                    <?php endif; ?>
                </tr>
                        <?php endforeach; ?>
                    <?php elseif (empty($extras['days'][$date])): ?>
                <tr class="empty-day">
                    <td><?= $day->format('m/d/Y') ?></td>
                    <td colspan="<?= $editMode ? $emptyColspan + 2 : $emptyColspan ?>">No punches for this day.</td>
                </tr>
                    <?php endif; ?>
                    <?php foreach ($extras['days'][$date] ?? [] as $x):
                        $xText = $x['label'];
                        if ($x['hours'] !== null) {
                            $xText .= ' — ' . number_format($x['hours'], 2) . ' h' . ($x['suffix'] !== '' ? ' ' . $x['suffix'] : '');
                        } elseif ($x['note'] !== '' && $x['kind'] !== 'Holiday') {
                            $xText .= ' — ' . $x['note']; // an absence: the reason
                            $x['note'] = '';
                        }
                    ?>
                <tr class="vp-extra vp-extra-<?= htmlspecialchars(strtolower($x['kind'])) ?>">
                    <td><?= $day->format('m/d/Y') ?></td>
                    <td class="vp-extra-text" colspan="<?= $editMode ? $emptyColspan + 2 : $emptyColspan ?>">
                        <?= htmlspecialchars($xText) ?><?php if ($x['note'] !== ''): ?><span class="vp-extra-note"><?= htmlspecialchars($x['note']) ?></span><?php endif; ?>
                    </td>
                </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <?php if ($editMode && $hasRules): ?>
            <p class="vp-paid" style="margin:.5rem 0 0;">Totals update here as actual time while you edit. Shift rounding and lunch rules are applied when you save.</p>
        <?php endif; ?>
        <div class="totals">
            <strong>Total Week:</strong> <span id="weekly-total"><?= number_format($weekTotal, 2) ?>h</span> &nbsp;|&nbsp;
            <strong>Overtime:</strong> <span id="weekly-overtime"><?= number_format(max(0, $weekTotal - overtimeWeeklyHours()), 2) ?>h</span>
            <?php
            // Time off / holiday hours: separate from the worked total (which the edit
            // script recalculates from the punch inputs only)
            $xTotals = $extras['totals'];
            $hasTimeOff = $extras['hasTimeOff'];
            ?>
            <?php if ($hasTimeOff || $xTotals['PTO'] > 0): ?>&nbsp;|&nbsp; <strong>PTO:</strong> <?= number_format($xTotals['PTO'], 2) ?>h<?php endif; ?>
            <?php if ($hasTimeOff || $xTotals['Sick'] > 0): ?>&nbsp;|&nbsp; <strong>Sick:</strong> <?= number_format($xTotals['Sick'], 2) ?>h<?php endif; ?>
            <?php if ($extras['holidayPay'] && $xTotals['Holiday'] > 0): ?>&nbsp;|&nbsp; <strong>Holiday:</strong> <?= number_format($xTotals['Holiday'], 2) ?>h<?php endif; ?>
            <?php if (!$editMode && array_sum($xTotals) > 0): ?>&nbsp;|&nbsp; <strong>Total Paid:</strong> <?= number_format($weekTotal + array_sum($xTotals), 2) ?>h<?php endif; ?>
        </div>
    </form>
</div>
<?php else: ?>
<div class="vp-card">
    <p class="vp-empty">Select an employee above to view their timesheet.</p>
</div>
<?php endif; ?>

<script>
    // Tells the edit-mode "add punch" helper whether to render location cells
    window.VP_SHOW_LOCATIONS = <?= $showLocations ? 'true' : 'false' ?>;
    window.VP_OT_WEEKLY_HOURS = <?= json_encode(overtimeWeeklyHours()) ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/litepicker/dist/bundle.js"></script>
<?php if ($editMode): ?>
<script src="../js/admin_timesheet_edit.js?v=2"></script>
<?php else: ?>
<script src="../js/view_punches.js?v=2"></script>
<?php endif; ?>

<?php require_once 'footer.php'; ?>
