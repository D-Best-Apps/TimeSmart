<?php
require_once 'header.php';

$requestID = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($requestID === 0) {
    header('Location: time_off.php');
    exit;
}

// Load the request — must belong to this employee and be Pending or Approved
$stmt = $conn->prepare("
    SELECT * FROM time_off_requests
     WHERE ID = ? AND EmployeeID = ? AND Status IN ('Pending','Approved')
");
$stmt->bind_param("ii", $requestID, $empID);
$stmt->execute();
$req = $stmt->get_result()->fetch_assoc();
if (!$req) {
    header('Location: time_off.php?status=invalid&reason=' . urlencode('Request not found or not editable.'));
    exit;
}

$isApproved = $req['Status'] === 'Approved';
$today = date('Y-m-d');
$existingTime = !empty($req['StartTime']) && !empty($req['EndTime']);
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
  .time-off-form textarea {
    width: 100%; padding: 0.5rem; border: 1px solid #ccc; border-radius: 4px;
    font-size: 1rem; box-sizing: border-box;
  }
  .time-off-form textarea { resize: vertical; min-height: 60px; }
  .time-off-form .partial-day { display: none; padding-left: 1rem; border-left: 3px solid #0078D7; margin-top: 0.5rem; }
  .time-off-form .partial-day.visible { display: block; }
  .time-off-form .category-options label { display: inline-block; font-weight: normal; margin-right: 1.5rem; }
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

<?php if ($isApproved): ?>
  <div class="amendment-banner">
    <strong>This request is already approved.</strong> Your edits will be submitted as an
    <strong>amendment</strong> for admin re-approval. The original request stays in place
    (and its calendar event remains visible) until the amendment is approved or rejected.
  </div>
<?php endif; ?>

<div class="card">
  <form method="POST" action="submit_time_off_edit.php" class="time-off-form" id="timeOffEditForm">
    <input type="hidden" name="EmployeeID" value="<?= $empID ?>">
    <input type="hidden" name="RequestID" value="<?= $requestID ?>">
    <input type="hidden" name="OriginalStatus" value="<?= htmlspecialchars($req['Status']) ?>">

    <div class="field category-options">
      <label>Category:</label>
      <label><input type="radio" name="Category" value="Sick" <?= $req['Category'] === 'Sick' ? 'checked' : '' ?> required> Sick</label>
      <label><input type="radio" name="Category" value="PTO"  <?= $req['Category'] === 'PTO'  ? 'checked' : '' ?> required> PTO</label>
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
        <input type="checkbox" id="partialDayToggle" <?= $existingTime ? 'checked' : '' ?>> Partial day (specify a time window)
      </label>
      <div id="partialDayFields" class="partial-day <?= $existingTime ? 'visible' : '' ?>">
        <div class="row">
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

  toggle.addEventListener('change', () => {
    if (toggle.checked) {
      fields.classList.add('visible');
    } else {
      fields.classList.remove('visible');
      startTime.value = '';
      endTime.value = '';
    }
  });

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
    if (toggle.checked) {
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
