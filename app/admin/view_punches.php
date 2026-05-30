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
requirePermission('edit_timesheets');

date_default_timezone_set('America/Chicago');

$employeeList = $conn->query("SELECT ID, FirstName, LastName FROM users ORDER BY LastName");

$from = $_GET['from'] ?? date('Y-m-d', strtotime('monday this week'));
$to = $_GET['to'] ?? date('Y-m-d', strtotime('sunday this week'));
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
    "../css/view_punches.css?v=2",
];
require_once 'header.php';
?>

<?php if (($_GET['success'] ?? '') === '1'): ?>
    <div class="alert alert-success" style="margin-bottom:1rem;">✅ Timesheet saved.</div>
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
    // Column layout depends on mode + whether locations are shown
    $dataCols = 4 + ($showLocations ? 4 : 0); // clock-in/lunch-out/lunch-in/clock-out (+ their locations)
    $emptyColspan = $dataCols + 1; // + total column (read-only); edit adds reason+action below
?>

<!-- Timesheet card -->
<div class="vp-card">
    <div class="vp-card-header">
        <h2><?= $editMode ? 'Edit Timesheet' : 'Timesheet' ?></h2>
        <div class="vp-actions">
            <?php if ($editMode): ?>
                <button type="button" class="btn secondary" onclick="addNewPunch()">+ Add Punch</button>
                <button type="submit" form="punchForm" class="btn primary">Save All Changes</button>
                <a href="view_punches.php?emp=<?= $empParam ?>&from=<?= $fromParam ?>&to=<?= $toParam ?>" class="btn secondary">Cancel</a>
            <?php else: ?>
                <a href="view_punches.php?emp=<?= $empParam ?>&from=<?= $fromParam ?>&to=<?= $toParam ?>&mode=edit" class="btn primary">✎ Edit Timesheet</a>
            <?php endif; ?>
        </div>
    </div>

    <form method="POST" action="save_punches.php" id="punchForm">
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
                    $result = $stmt->get_result();

                    if ($result->num_rows > 0):
                        while ($punch = $result->fetch_assoc()):
                            $clockIn   = !empty($punch['TimeIN'])      ? date('H:i', strtotime($punch['TimeIN']))     : '';
                            $clockOut  = !empty($punch['TimeOut'])     ? date('H:i', strtotime($punch['TimeOut']))    : '';
                            $lunchOut  = !empty($punch['LunchStart'])  ? date('H:i', strtotime($punch['LunchStart'])) : '';
                            $lunchIn   = !empty($punch['LunchEnd'])    ? date('H:i', strtotime($punch['LunchEnd']))   : '';
                            $rowTotal  = (float) ($punch['TotalHours'] ?? 0);
                            $weekTotal += $rowTotal;

                            // Read-only display helper
                            $disp = function ($t) { return $t !== '' ? '<span class="vp-ro">' . htmlspecialchars(date('g:i a', strtotime($t))) . '</span>' : '<span class="vp-ro empty">—</span>'; };
                ?>
                <tr>
                    <td><?= $day->format('m/d/Y') ?></td>

                    <td><?php if ($editMode): ?><input type="time" name="clockin[<?= $punch['id'] ?>]" value="<?= $clockIn ?>" step="60"><?php else: ?><?= $disp($clockIn) ?><?php endif; ?></td>
                    <?php if ($showLocations): ?><td><?= $locCell($punch['LatitudeIN'] ?? null, $punch['LongitudeIN'] ?? null) ?></td><?php endif; ?>

                    <td><?php if ($editMode): ?><input type="time" name="lunchout[<?= $punch['id'] ?>]" value="<?= $lunchOut ?>" step="60"><?php else: ?><?= $disp($lunchOut) ?><?php endif; ?></td>
                    <?php if ($showLocations): ?><td><?= $locCell($punch['LatitudeLunchStart'] ?? null, $punch['LongitudeLunchStart'] ?? null) ?></td><?php endif; ?>

                    <td><?php if ($editMode): ?><input type="time" name="lunchin[<?= $punch['id'] ?>]" value="<?= $lunchIn ?>" step="60"><?php else: ?><?= $disp($lunchIn) ?><?php endif; ?></td>
                    <?php if ($showLocations): ?><td><?= $locCell($punch['LatitudeLunchEnd'] ?? null, $punch['LongitudeLunchEnd'] ?? null) ?></td><?php endif; ?>

                    <td><?php if ($editMode): ?><input type="time" name="clockout[<?= $punch['id'] ?>]" value="<?= $clockOut ?>" step="60"><?php else: ?><?= $disp($clockOut) ?><?php endif; ?></td>
                    <?php if ($showLocations): ?><td><?= $locCell($punch['LatitudeOut'] ?? null, $punch['LongitudeOut'] ?? null) ?></td><?php endif; ?>

                    <td class="total-cell" id="total-<?= $punch['id'] ?>"><?= number_format($rowTotal, 2) ?></td>

                    <?php if ($editMode): ?>
                    <td>
                        <select name="reason[<?= $punch['id'] ?>]" class="reason-dropdown">
                            <option value="">Select reason...</option>
                            <?php foreach ($reasons as $r): ?>
                                <option value="<?= $r ?>"><?= $r ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <button type="button" class="delete-btn" onclick="deleteRow(this)" title="Delete this punch">✖</button>
                    </td>
                    <?php endif; ?>
                </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                <tr class="empty-day">
                    <td><?= $day->format('m/d/Y') ?></td>
                    <td colspan="<?= $editMode ? $emptyColspan + 2 : $emptyColspan ?>">No punches for this day.</td>
                </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <div class="totals">
            <strong>Total Week:</strong> <span id="weekly-total"><?= number_format($weekTotal, 2) ?>h</span> &nbsp;|&nbsp;
            <strong>Overtime:</strong> <span id="weekly-overtime"><?= number_format(max(0, $weekTotal - 40), 2) ?>h</span>
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
</script>
<script src="https://cdn.jsdelivr.net/npm/litepicker/dist/bundle.js"></script>
<?php if ($editMode): ?>
<script src="../js/admin_timesheet_edit.js?v=2"></script>
<?php else: ?>
<script src="../js/view_punches.js?v=2"></script>
<?php endif; ?>

<?php require_once 'footer.php'; ?>
