<?php
session_start();
require_once '../auth/db.php';
require_once __DIR__ . '/../functions/check_permission.php';
require_once __DIR__ . '/../functions/time_off_hours.php';
date_default_timezone_set('America/Chicago');

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}
requirePermission('approve_edits');

$requestID = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($requestID === 0) {
    header('Location: edits_timesheet.php');
    exit;
}

$stmt = $conn->prepare("
    SELECT tor.*, u.FirstName, u.LastName
      FROM time_off_requests tor
      JOIN users u ON u.ID = tor.EmployeeID
     WHERE tor.ID = ?
");
$stmt->bind_param("i", $requestID);
$stmt->execute();
$req = $stmt->get_result()->fetch_assoc();
if (!$req) {
    header('Location: edits_timesheet.php');
    exit;
}

require_once __DIR__ . '/../functions/csrf.php';

$existingTime  = !empty($req['StartTime']) && !empty($req['EndTime']);
$existingHours = !$existingTime && (float) ($req['Hours'] ?? 0) > 0; // part day entered as hours missed
$isPartDay     = $existingTime || $existingHours;
$hoursValue    = $existingHours ? rtrim(rtrim(number_format((float) $req['Hours'], 2, '.', ''), '0'), '.') : '';
$categories    = timeOffCategories(true, $req['Category']); // keeps Sick on a Sick entry even when Sick is off
$empFullName   = trim($req['FirstName'] . ' ' . $req['LastName']);

// Projection preview for this employee under the current values
$proj = projectedWeeklyHours(
    $conn,
    (int) $req['EmployeeID'],
    $req['StartDate'],
    $req['EndDate'],
    $req['StartTime'] ?: null,
    $req['EndTime']   ?: null,
    $requestID, // exclude this request itself — we want "what would it look like with the (possibly amended) version"
    null,
    isset($req['Hours']) ? (float) $req['Hours'] : null
);

$pageTitle = "Edit Time-Off Request";
require_once 'header.php';
?>
<style>
  .field { margin-bottom: 0.75rem; }
  .field label { font-weight: 600; display: block; margin-bottom: 0.25rem; }
  .row { display: flex; gap: 1rem; flex-wrap: wrap; }
  .row > div { flex: 1; min-width: 180px; }
  input[type=date], input[type=time], input[type=number], input[type=text], textarea {
    width: 100%; padding: 0.5rem; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box;
  }
  textarea { resize: vertical; min-height: 60px; }
  .partial-day { display: none; padding-left: 1rem; border-left: 3px solid #0078D7; margin-top: 0.5rem; }
  .partial-day.visible { display: block; }
  .category-options label, .part-mode label { display: inline-block; font-weight: normal; margin-right: 1.5rem; }
  .part-mode { margin-bottom: 0.5rem; }
  #Hours { max-width: 8rem; }
  .form-error { background-color: #f8d7da; border: 1px solid #f5c2c7; color: #842029; padding: 0.75rem 1rem; border-radius: 4px; margin-bottom: 1rem; }
  button[type=submit] {
    background-color: #0078D7; color: #fff; border: none; padding: 0.6rem 1.2rem;
    border-radius: 4px; cursor: pointer; font-size: 1rem; margin-top: 0.5rem;
  }
  button[type=submit]:hover { background-color: #005fa3; }
  .admin-banner {
    background-color: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460;
    padding: 0.75rem 1rem; border-radius: 4px; margin-bottom: 1rem;
  }
  .projection { padding: 0.5rem 0.75rem; background:#f5f5f5; border-left:3px solid #6c757d; margin-bottom:1rem; font-size:0.9em; }
  .projection .over { color:#b02a37; font-weight:bold; }
  .danger-zone { margin-top: 2rem; padding: 1rem; border: 1px solid #f5c2c7; border-radius: 4px; background: #fff5f5; }
  .danger-zone h3 { margin: 0 0 0.35rem; font-size: 1rem; color: #842029; }
  .danger-zone p { margin: 0 0 0.75rem; font-size: 0.9em; color: #6c757d; }
  .danger-zone button[type=submit] { background-color: #b02a37; }
  .danger-zone button[type=submit]:hover { background-color: #8b2029; }
</style>

<div class="dashboard-container">
  <div class="container">
    <div class="admin-banner">
      <strong>Admin override edit.</strong> This saves directly without an amendment-approval step.
      The employee will be emailed about the change. M365 calendar event will be replaced if one exists.
    </div>

    <?php if (($_GET['status'] ?? '') === 'invalid'): ?>
      <div class="form-error"><strong>Not saved:</strong> <?= htmlspecialchars($_GET['reason'] ?? 'Please check your inputs.') ?></div>
    <?php endif; ?>

    <h2>Edit: <?= htmlspecialchars($empFullName) ?> &mdash; <?= htmlspecialchars(timeOffLabel($req['Category'])) ?>
        <span style="font-size:0.7em; color:#888;">(Request ID <?= $requestID ?>, Status <?= htmlspecialchars($req['Status']) ?>)</span>
    </h2>

    <div class="projection">
      <strong>Projected weekly totals (with current values):</strong>
      <?php foreach ($proj as $w): $isOver = $w['projected'] > overtimeWeeklyHours(); ?>
        <div<?= $isOver ? ' class="over"' : '' ?>>
          wk of <?= date('m/d', strtotime($w['weekStart'])) ?>:
          <?= sprintf('%.2f', $w['projected']) ?> hrs / <?= overtimeWeeklyHours() ?><?= $isOver ? ' ⚠ over' : '' ?>
        </div>
      <?php endforeach; ?>
    </div>

    <form method="POST" action="update_time_off.php" id="adminTOEdit">
      <?= csrf_field() ?>
      <input type="hidden" name="RequestID" value="<?= $requestID ?>">

      <div class="field category-options">
        <label>Category:</label>
        <?php foreach ($categories as $cat => $label): ?>
        <label><input type="radio" name="Category" value="<?= htmlspecialchars($cat) ?>" <?= $req['Category'] === $cat ? 'checked' : '' ?> required> <?= htmlspecialchars($label) ?></label>
        <?php endforeach; ?>
      </div>

      <div class="row">
        <div class="field">
          <label for="StartDate">Start date</label>
          <input type="date" id="StartDate" name="StartDate" value="<?= htmlspecialchars($req['StartDate']) ?>" required>
        </div>
        <div class="field">
          <label for="EndDate">End date</label>
          <input type="date" id="EndDate" name="EndDate" value="<?= htmlspecialchars($req['EndDate']) ?>" required>
        </div>
      </div>

      <div class="field">
        <label style="font-weight:normal;">
          <input type="checkbox" id="partialDayToggle" name="PartDay" value="1" <?= $isPartDay ? 'checked' : '' ?>> Partial day
        </label>
        <div id="partialDayFields" class="partial-day <?= $isPartDay ? 'visible' : '' ?>">
          <div class="part-mode">
            <label><input type="radio" name="PartMode" value="times" <?= !$existingHours ? 'checked' : '' ?>> Times (start and end)</label>
            <label><input type="radio" name="PartMode" value="hours" <?= $existingHours ? 'checked' : '' ?>> Hours missed</label>
          </div>
          <div class="field" id="partHours">
            <label for="Hours">Hours missed (each day)</label>
            <input type="number" id="Hours" name="Hours" step="0.25" min="0.25" max="24" value="<?= htmlspecialchars($hoursValue) ?>">
          </div>
          <div class="row" id="partTimes">
            <div class="field">
              <label for="StartTime">Start time</label>
              <input type="time" id="StartTime" name="StartTime" value="<?= htmlspecialchars(substr($req['StartTime'] ?? '', 0, 5)) ?>">
            </div>
            <div class="field">
              <label for="EndTime">End time</label>
              <input type="time" id="EndTime" name="EndTime" value="<?= htmlspecialchars(substr($req['EndTime'] ?? '', 0, 5)) ?>">
            </div>
          </div>
        </div>
      </div>

      <div class="field">
        <label for="Notes">Notes</label>
        <textarea id="Notes" name="Notes" maxlength="500"><?= htmlspecialchars($req['Notes'] ?? '') ?></textarea>
      </div>

      <div class="field">
        <label for="AdminNote">Admin note (will be emailed to employee)</label>
        <textarea id="AdminNote" name="AdminNote" maxlength="500" placeholder="e.g., 'Adjusted to 2 hours per actual time used'"></textarea>
      </div>

      <?php if (canViewPrivateNotes($conn)): ?>
      <div class="field">
        <label for="AdminPrivateNote">Private note (internal — never emailed or shown to employee)</label>
        <textarea id="AdminPrivateNote" name="AdminPrivateNote" maxlength="500" placeholder="Admin-only note for this request"><?= htmlspecialchars($req['AdminPrivateNote'] ?? '') ?></textarea>
      </div>
      <?php endif; ?>

      <button type="submit">Save changes</button>
      <a href="edits_timesheet.php" style="margin-left:1rem;">Cancel</a>
    </form>

    <?php if ($req['Status'] === 'Approved'): ?>
    <div class="danger-zone">
      <h3>Cancel this approved time off</h3>
      <p>
        Marks the request <strong>Cancelled</strong> so the hours no longer count, removes the
        event from the PTO calendar (and from the employee's own calendar if they were invited),
        and emails the employee. Any pending amendment against it is cancelled too.
      </p>
      <form method="POST" action="cancel_time_off.php" id="adminTOCancel">
        <?= csrf_field() ?>
        <input type="hidden" name="RequestID" value="<?= $requestID ?>">
        <div class="field">
          <label for="CancelReason">Reason (emailed to employee)</label>
          <textarea id="CancelReason" name="CancelReason" maxlength="500" placeholder="e.g., 'Employee worked these days after all'"></textarea>
        </div>
        <button type="submit">Cancel this time off</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
(function() {
  const toggle = document.getElementById('partialDayToggle');
  const fields = document.getElementById('partialDayFields');
  const startTime = document.getElementById('StartTime');
  const endTime = document.getElementById('EndTime');
  const startDate = document.getElementById('StartDate');
  const endDate = document.getElementById('EndDate');
  const form = document.getElementById('adminTOEdit');
  const hours = document.getElementById('Hours');
  const partTimes = document.getElementById('partTimes');
  const partHours = document.getElementById('partHours');
  const isHoursMode = () => (form.querySelector('input[name=PartMode]:checked') || {}).value === 'hours';

  // Show the times or the hours box; a disabled box is neither checked nor sent.
  function syncPart() {
    const on = toggle.checked, h = isHoursMode();
    fields.classList.toggle('visible', on);
    partTimes.style.display = h ? 'none' : '';
    partHours.style.display = h ? '' : 'none';
    startTime.disabled = endTime.disabled = !on || h;
    hours.disabled = !on || !h;
  }
  toggle.addEventListener('change', syncPart);
  form.querySelectorAll('input[name=PartMode]').forEach((r) => r.addEventListener('change', syncPart));
  syncPart();

  const cancelForm = document.getElementById('adminTOCancel');
  if (cancelForm) {
    cancelForm.addEventListener('submit', (e) => {
      if (!confirm('Cancel this approved time off? The employee will be emailed and the calendar event removed.')) {
        e.preventDefault();
      }
    });
  }

  form.addEventListener('submit', (e) => {
    if (endDate.value < startDate.value) {
      e.preventDefault(); alert('End date cannot be before start date.'); return;
    }
    if (toggle.checked && isHoursMode()) {
      const h = parseFloat(hours.value);
      if (!(h > 0 && h <= 24)) { e.preventDefault(); alert('Enter the hours missed (more than 0, at most 24).'); return; }
    } else if (toggle.checked) {
      if (!startTime.value || !endTime.value) { e.preventDefault(); alert('Partial day needs both times.'); return; }
      if (startDate.value === endDate.value && endTime.value <= startTime.value) {
        e.preventDefault(); alert('End time must be after start time.'); return;
      }
    }
  });
})();
</script>

<?php require_once 'footer.php'; ?>
