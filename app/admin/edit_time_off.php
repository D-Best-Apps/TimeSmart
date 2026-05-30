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

$existingTime = !empty($req['StartTime']) && !empty($req['EndTime']);
$empFullName  = trim($req['FirstName'] . ' ' . $req['LastName']);

// Projection preview for this employee under the current values
$proj = projectedWeeklyHours(
    $conn,
    (int) $req['EmployeeID'],
    $req['StartDate'],
    $req['EndDate'],
    $req['StartTime'] ?: null,
    $req['EndTime']   ?: null,
    $requestID  // exclude this request itself — we want "what would it look like with the (possibly amended) version"
);

$pageTitle = "Edit Time-Off Request";
require_once 'header.php';
?>
<style>
  .field { margin-bottom: 0.75rem; }
  .field label { font-weight: 600; display: block; margin-bottom: 0.25rem; }
  .row { display: flex; gap: 1rem; flex-wrap: wrap; }
  .row > div { flex: 1; min-width: 180px; }
  input[type=date], input[type=time], input[type=text], textarea {
    width: 100%; padding: 0.5rem; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box;
  }
  textarea { resize: vertical; min-height: 60px; }
  .partial-day { display: none; padding-left: 1rem; border-left: 3px solid #0078D7; margin-top: 0.5rem; }
  .partial-day.visible { display: block; }
  .category-options label { display: inline-block; font-weight: normal; margin-right: 1.5rem; }
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
</style>

<div class="dashboard-container">
  <div class="container">
    <div class="admin-banner">
      <strong>Admin override edit.</strong> This saves directly without an amendment-approval step.
      The employee will be emailed about the change. M365 calendar event will be replaced if one exists.
    </div>

    <h2>Edit: <?= htmlspecialchars($empFullName) ?> &mdash; <?= htmlspecialchars($req['Category']) ?>
        <span style="font-size:0.7em; color:#888;">(Request ID <?= $requestID ?>, Status <?= htmlspecialchars($req['Status']) ?>)</span>
    </h2>

    <div class="projection">
      <strong>Projected weekly totals (with current values):</strong>
      <?php foreach ($proj as $w): $isOver = $w['projected'] > 40; ?>
        <div<?= $isOver ? ' class="over"' : '' ?>>
          wk of <?= date('m/d', strtotime($w['weekStart'])) ?>:
          <?= sprintf('%.2f', $w['projected']) ?> hrs / 40<?= $isOver ? ' ⚠ over' : '' ?>
        </div>
      <?php endforeach; ?>
    </div>

    <form method="POST" action="update_time_off.php" id="adminTOEdit">
      <input type="hidden" name="RequestID" value="<?= $requestID ?>">

      <div class="field category-options">
        <label>Category:</label>
        <label><input type="radio" name="Category" value="Sick" <?= $req['Category'] === 'Sick' ? 'checked' : '' ?> required> Sick</label>
        <label><input type="radio" name="Category" value="PTO"  <?= $req['Category'] === 'PTO'  ? 'checked' : '' ?> required> PTO</label>
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

  toggle.addEventListener('change', () => {
    if (toggle.checked) fields.classList.add('visible');
    else { fields.classList.remove('visible'); startTime.value=''; endTime.value=''; }
  });

  form.addEventListener('submit', (e) => {
    if (endDate.value < startDate.value) {
      e.preventDefault(); alert('End date cannot be before start date.'); return;
    }
    if (toggle.checked) {
      if (!startTime.value || !endTime.value) { e.preventDefault(); alert('Partial day needs both times.'); return; }
      if (startDate.value === endDate.value && endTime.value <= startTime.value) {
        e.preventDefault(); alert('End time must be after start time.'); return;
      }
    }
  });
})();
</script>

<?php require_once 'footer.php'; ?>
