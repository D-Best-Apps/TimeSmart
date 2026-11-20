<?php
require_once 'header.php';
require_once __DIR__ . '/../functions/time_off_hours.php'; // timeOffCategories()
require_once __DIR__ . '/../functions/csrf.php';

$requestID = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($requestID === 0) {
    header('Location: time_off.php');
    exit;
}

// Load the request — must belong to this employee and be Pending or Approved
$stmt = $conn->prepare("
    SELECT * FROM time_off_requests
     WHERE ID = ? AND EmployeeID = ? AND Status IN ('Pending','Approved') AND Category NOT IN ('Unpaid','Unexcused')
");
$stmt->bind_param("ii", $requestID, $empID);
$stmt->execute();
$req = $stmt->get_result()->fetch_assoc();
if (!$req) {
    // header.php has already sent the page header, so a redirect can't happen here
    echo '<div class="container" style="padding:1.5rem;"><p>That time-off entry can\'t be changed here'
       . ' (it may have been recorded by an admin, or already decided). <a href="time_off.php">Back to Time Off</a></p></div>';
    require_once 'footer.php';
    exit;
}

$isApproved = $req['Status'] === 'Approved';
$today = date('Y-m-d');
$existingTime  = !empty($req['StartTime']) && !empty($req['EndTime']);
$existingHours = !$existingTime && (float) ($req['Hours'] ?? 0) > 0; // part day entered as hours missed
$isPartDay     = $existingTime || $existingHours;
$hoursValue    = $existingHours ? rtrim(rtrim(number_format((float) $req['Hours'], 2, '.', ''), '0'), '.') : '';
// PTO, plus Sick when offered; a Sick request keeps Sick even if it has since been turned off
$categories    = timeOffCategories(false, $req['Category'] === 'Sick' ? 'Sick' : null);
?>
<link rel="stylesheet" href="../css/user_timesheet.css">
<style>
  .time-off-form .field { margin-bottom: 0.75rem; }
  .time-off-form label { font-weight: 600; display: block; margin-bottom: 0.25rem; }
  .time-off-form .row { display: flex; gap: 1rem; flex-wrap: wrap; }
  .time-off-form .row > div { flex: 1; min-width: 180px; }
  .time-off-form input[type=date],
  .time-off-form input[type=time],
  .time-off-form input[type=text],
  .time-off-form input[type=number],
  .time-off-form textarea {
    width: 100%; padding: 0.5rem; border: 1px solid #ccc; border-radius: 4px;
    font-size: 1rem; box-sizing: border-box;
  }
  .time-off-form textarea { resize: vertical; min-height: 60px; }
  .time-off-form .partial-day { display: none; padding-left: 1rem; border-left: 3px solid #0078D7; margin-top: 0.5rem; }
  .time-off-form .partial-day.visible { display: block; }
  .time-off-form .category-options label,
  .time-off-form .part-mode label { display: inline-block; font-weight: normal; margin-right: 1.5rem; }
  .time-off-form .part-mode { margin-bottom: 0.5rem; }
  .time-off-form #Hours { max-width: 8rem; }
  .time-off-form button[type=submit] {
    background-color: #0078D7; color: #fff; border: none; padding: 0.6rem 1.2rem;
    border-radius: 4px; cursor: pointer; font-size: 1rem; margin-top: 0.5rem;
  }
  .time-off-form button[type=submit]:hover { background-color: #005fa3; }
  .amendment-banner {
    background-color: #fff3cd; border: 1px solid #ffeeba; color: #856404;
    padding: 0.75rem 1rem; border-radius: 4px; margin-bottom: 1rem;
  }
</style>

<h2><?= $isApproved ? 'Amend' : 'Edit' ?> Time-Off Request</h2>

<?php if (($_GET['status'] ?? '') === 'invalid'): ?>
  <div class="alert alert-danger">Not saved: <?= htmlspecialchars($_GET['reason'] ?? 'Please check your inputs.') ?></div>
<?php endif; ?>

<?php if ($isApproved): ?>
  <div class="amendment-banner">
    <strong>This request is already approved.</strong> Your edits will be submitted as an
    <strong>amendment</strong> for admin re-approval. The original request stays in place
    (and its calendar event remains visible) until the amendment is approved or rejected.
  </div>
<?php endif; ?>

<div class="card">
  <form method="POST" action="submit_time_off_edit.php" class="time-off-form" id="timeOffEditForm">
    <?= csrf_field() ?>
    <input type="hidden" name="EmployeeID" value="<?= $empID ?>">
    <input type="hidden" name="RequestID" value="<?= $requestID ?>">
    <input type="hidden" name="OriginalStatus" value="<?= htmlspecialchars($req['Status']) ?>">

    <div class="field category-options">
      <label>Category:</label>
      <?php foreach ($categories as $cat => $label): ?>
      <label><input type="radio" name="Category" value="<?= htmlspecialchars($cat) ?>" <?= $req['Category'] === $cat ? 'checked' : '' ?> required> <?= htmlspecialchars($label) ?></label>
      <?php endforeach; ?>
    </div>

    <div class="row">
      <div class="field">
        <label for="StartDate">Start date</label>
        <input type="date" id="StartDate" name="StartDate"
               value="<?= htmlspecialchars($req['StartDate']) ?>"
               <?= !$isApproved ? 'min="' . $today . '"' : '' ?>
               required>
      </div>
      <div class="field">
        <label for="EndDate">End date</label>
        <input type="date" id="EndDate" name="EndDate"
               value="<?= htmlspecialchars($req['EndDate']) ?>"
               <?= !$isApproved ? 'min="' . $today . '"' : '' ?>
               required>
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
      <label for="Notes">Notes (optional)</label>
      <textarea id="Notes" name="Notes" maxlength="500" placeholder="e.g., doctor appointment, cashing in points"><?= htmlspecialchars($req['Notes'] ?? '') ?></textarea>
    </div>

    <?php if ($isApproved): ?>
    <div class="field">
      <label for="Reason">Reason for change <span style="color:#b02a37;">*</span></label>
      <textarea id="Reason" name="Reason" maxlength="500" required placeholder="Why are you changing this approved request? e.g., 'Appointment ran short, only needed 2 hours instead of 4'"></textarea>
    </div>
    <?php endif; ?>

    <button type="submit"><?= $isApproved ? 'Submit Amendment for Approval' : 'Save Changes' ?></button>
    <a href="time_off.php" style="margin-left:1rem;">Cancel</a>
  </form>
</div>

<script>
(function() {
  const toggle = document.getElementById('partialDayToggle');
  const fields = document.getElementById('partialDayFields');
  const startTime = document.getElementById('StartTime');
  const endTime = document.getElementById('EndTime');
  const startDate = document.getElementById('StartDate');
  const endDate = document.getElementById('EndDate');
  const form = document.getElementById('timeOffEditForm');
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

  startDate.addEventListener('change', () => {
    if (!endDate.value || endDate.value < startDate.value) {
      endDate.value = startDate.value;
    }
  });

  form.addEventListener('submit', (e) => {
    if (endDate.value && startDate.value && endDate.value < startDate.value) {
      e.preventDefault();
      alert('End date cannot be before start date.');
      return;
    }
    if (toggle.checked && isHoursMode()) {
      const h = parseFloat(hours.value);
      if (!(h > 0 && h <= 24)) {
        e.preventDefault();
        alert('Enter the hours you will miss (more than 0, at most 24).');
        return;
      }
    } else if (toggle.checked) {
      if (!startTime.value || !endTime.value) {
        e.preventDefault();
        alert('Partial day requires both a start time and an end time.');
        return;
      }
      if (startDate.value === endDate.value && endTime.value <= startTime.value) {
        e.preventDefault();
        alert('End time must be later than start time.');
        return;
      }
    }
  });
})();
</script>

<?php require_once 'footer.php'; ?>
