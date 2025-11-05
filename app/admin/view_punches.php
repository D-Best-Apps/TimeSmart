<?php
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

date_default_timezone_set('America/Chicago');

$employeeList = $conn->query("SELECT ID, FirstName, LastName FROM users ORDER BY LastName");

$from = $_GET['from'] ?? date('Y-m-d', strtotime('monday this week'));
$to = $_GET['to'] ?? date('Y-m-d', strtotime('sunday this week'));
$employeeID = $_GET['emp'] ?? '';
$editMode = isset($_GET['mode']) && $_GET['mode'] === 'edit';
$pageTitle = "Timesheet Report";
$extraCSS = ["https://cdn.jsdelivr.net/npm/litepicker/dist/css/litepicker.css"];
require_once 'header.php';
?>


<div class="dashboard-container">
    <div class="container">
        <form method="GET" class="summary-filter">
            <div class="field">
                <label>Date Range From:
                    <input type="text" name="from" id="weekFrom" value="<?= htmlspecialchars(date('m/d/Y', strtotime($from))) ?>">
                </label>
            </div>
            <div class="field">
                <label>To:
                    <input type="text" name="to" id="weekTo" value="<?= htmlspecialchars(date('m/d/Y', strtotime($to))) ?>">
                </label>
            </div>
            <div class="field">
                <label>Employee:
                    <select name="emp" required>
                        <option value="">Select Employee</option>
                        <?php while ($emp = $employeeList->fetch_assoc()): ?>
                            <option value="<?= $emp['ID'] ?>" <?= ($emp['ID'] == $employeeID ? 'selected' : '') ?>>
                                <?= htmlspecialchars($emp['FirstName'] . ' ' . $emp['LastName']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </label>
            </div>
            <div class="buttons">
                <button type="submit">Submit</button>
                <a href="view_punches.php" class="btn-reset">Reset</a>
                <?php if (!empty($employeeID)): ?>
                    <?php if ($editMode): ?>
                        <a href="view_punches.php?emp=<?= urlencode($employeeID) ?>&from=<?= urlencode(date('m/d/Y', strtotime($from))) ?>&to=<?= urlencode(date('m/d/Y', strtotime($to))) ?>" class="btn-reset">Exit Edit Mode</a>
                    <?php else: ?>
                        <a href="view_punches.php?emp=<?= urlencode($employeeID) ?>&from=<?= urlencode(date('m/d/Y', strtotime($from))) ?>&to=<?= urlencode(date('m/d/Y', strtotime($to))) ?>&mode=edit" class="add-punch-btn">Enter Edit Mode</a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </form>

        <?php if (!empty($employeeID)): ?>
        <?php if ($editMode): ?>
            <button type="button" class="add-punch-btn" onclick="addNewPunch()">+ Add New Punch</button>
        <?php endif; ?>
        
        <form method="POST" action="save_punches.php">
            <input type="hidden" name="employeeID" value="<?= $employeeID ?>">
            <input type="hidden" name="from" value="<?= $from ?>">
            <input type="hidden" name="to" value="<?= $to ?>">
            <?php if ($editMode): ?>
            <input type="hidden" name="mode" value="edit">
            <?php endif; ?>

            <table class="timesheet-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Clock In</th>
                        <th>Clock In Location</th>
                        <th>Lunch Out</th>
                        <th>Lunch Out Location</th>
                        <th>Lunch In</th>
                        <th>Lunch In Location</th>
                        <th>Clock Out</th>
                        <th>Clock Out Location</th>
                        <th>Total</th>
                        <th>Reason for Adjustment</th>
                        <th><?= $editMode ? 'Actions' : 'Confirm' ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $start = new DateTime($from);
                    $end = new DateTime($to);
                    $interval = new DateInterval('P1D');
                    $range = new DatePeriod($start, $interval, $end->modify('+1 day'));

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
                    ?>
                    <tr>
                        <td><?= $day->format('m/d/Y') ?></td>
                        <td><input type="time" name="clockin[<?= $punch['id'] ?>]" value="<?= $clockIn ?>" step="60"></td>
                        <td>
                            <?php if (!empty($punch['LatitudeIN']) && !empty($punch['LongitudeIN'])) : ?>
                                <a href="https://www.google.com/maps?q=<?= $punch['LatitudeIN'] ?>,<?= $punch['LongitudeIN'] ?>" target="_blank" class="btn-view">View</a>
                            <?php endif; ?>
                        </td>
                        <td><input type="time" name="lunchout[<?= $punch['id'] ?>]" value="<?= $lunchOut ?>" step="60"></td>
                        <td>
                            <?php if (!empty($punch['LatitudeLunchStart']) && !empty($punch['LongitudeLunchStart'])) : ?>
                                <a href="https://www.google.com/maps?q=<?= $punch['LatitudeLunchStart'] ?>,<?= $punch['LongitudeLunchStart'] ?>" target="_blank" class="btn-view">View</a>
                            <?php endif; ?>
                        </td>
                        <td><input type="time" name="lunchin[<?= $punch['id'] ?>]" value="<?= $lunchIn ?>" step="60"></td>
                        <td>
                            <?php if (!empty($punch['LatitudeLunchEnd']) && !empty($punch['LongitudeLunchEnd'])) : ?>
                                <a href="https://www.google.com/maps?q=<?= $punch['LatitudeLunchEnd'] ?>,<?= $punch['LongitudeLunchEnd'] ?>" target="_blank" class="btn-view">View</a>
                            <?php endif; ?>
                        </td>
                        <td><input type="time" name="clockout[<?= $punch['id'] ?>]" value="<?= $clockOut ?>" step="60"></td>
                        <td>
                            <?php if (!empty($punch['LatitudeOut']) && !empty($punch['LongitudeOut'])) : ?>
                                <a href="https://www.google.com/maps?q=<?= $punch['LatitudeOut'] ?>,<?= $punch['LongitudeOut'] ?>" target="_blank" class="btn-view">View</a>
                            <?php endif; ?>
                        </td>
                        <td class="total-cell" id="total-<?= $punch['id'] ?>"><?= htmlspecialchars($punch['TotalHours'] ?? '0.00') ?></td>
                        <td>
                            <select name="reason[<?= $punch['id'] ?>]" class="reason-dropdown">
                                <option value="">Select reason...</option>
                                <option value="Forgot to punch">Forgot to punch</option>
                                <option value="Shift change">Shift change</option>
                                <option value="System error">System error</option>
                                <option value="Time correction">Time correction</option>
                                <option value="Late arrival">Late arrival</option>
                                <option value="Early departure">Early departure</option>
                                <option value="Manual update">Manual update</option>
                            </select>
                        </td>
                        <td>
                            <?php if ($editMode): ?>
                                <button type="button" class="delete-btn" onclick="deleteRow(this)" title="Delete this punch">✖</button>
                            <?php else: ?>
                                <button type="submit" name="confirm[]" value="<?= $punch['id'] ?>" class="confirm-btn">✔</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                    <tr>
                        <td><?= $day->format('m/d/Y') ?></td>
                        <td colspan="11">No punches for this day.</td>
                    </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="totals">
                <strong>Total Week:</strong> <span id="weekly-total">0.00h</span> |
                <strong>Overtime:</strong> <span id="weekly-overtime">0.00h</span>
            </div>
            
            <?php if ($editMode): ?>
                <div style="margin-top: 20px;">
                    <button type="submit" class="add-punch-btn" style="font-size: 16px; padding: 12px 24px;">Save All Changes</button>
                </div>
            <?php endif; ?>
        </form>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/litepicker/dist/bundle.js"></script>
<?php if ($editMode): ?>
<script src="../js/admin_timesheet_edit.js?v=1761329402"></script>
<?php else: ?>
<script src="../js/view_punches.js?v=1761329562"></script>
<?php endif; ?>


<?php require_once 'footer.php'; ?>
