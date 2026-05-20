<?php
require_once 'header.php';

// Status messages from redirect
$statusMessage = '';
if (isset($_GET['status'])) {
    switch ($_GET['status']) {
        case 'submitted':
            $statusMessage = '<div class="alert alert-success">Your time-off request has been submitted for approval.</div>';
            break;
        case 'overlap':
            $statusMessage = '<div class="alert alert-danger">You already have a pending or approved request that overlaps these dates. Withdraw or wait for the existing request before resubmitting.</div>';
            break;
        case 'withdrawn':
            $statusMessage = '<div class="alert alert-info">Your request has been withdrawn.</div>';
            break;
        case 'invalid':
            $reason = $_GET['reason'] ?? 'Please check your inputs.';
            $statusMessage = '<div class="alert alert-danger">Submission rejected: ' . htmlspecialchars($reason) . '</div>';
            break;
    }
}
if (isset($_GET['email_status']) && strpos($_GET['email_status'], 'error:') === 0) {
    $err = substr($_GET['email_status'], 6);
    if ($err !== 'incomplete_settings' && $err !== 'no_recipient') {
        $statusMessage .= '<div class="alert alert-danger">Admin notification email failed: ' . htmlspecialchars($err) . '</div>';
    }
}

// Load this employee's request history
$stmt = $conn->prepare("SELECT * FROM time_off_requests WHERE EmployeeID = ? ORDER BY SubmittedAt DESC");
$stmt->bind_param("i", $empID);
$stmt->execute();
$history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$today = date('Y-m-d');

function formatTimeRange(?string $start, ?string $end): string {
    if (!$start || !$end) return 'all day';
    return date('g:i a', strtotime($start)) . ' &ndash; ' . date('g:i a', strtotime($end));
}
function formatDateRange(string $start, string $end): string {
    if ($start === $end) return date('m/d/Y', strtotime($start));
    return date('m/d/Y', strtotime($start)) . ' &ndash; ' . date('m/d/Y', strtotime($end));
}
?>
<link rel="stylesheet" href="../css/user_timesheet.css">
<style>
  .time-off-form .field { margin-bottom: 0.75rem; }
  .time-off-form label { font-weight: 600; display: block; margin-bottom: 0.25rem; }
  .time-off-form .row { display: flex; gap: 1rem; flex-wrap: wrap; }
  .time-off-form .row > div { flex: 1; min-width: 180px; }
  .time-off-form input[type=date],
  .time-off-form input[type=time],
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
  .history-table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
  .history-table th, .history-table td { border: 1px solid #ddd; padding: 0.5rem; text-align: left; vertical-align: top; }
  .history-table th { background-color: #e6f0ff; }
  .status-Pending { color: #b8860b; font-weight: 600; }
  .status-Approved { color: #1e7e34; font-weight: 600; }
  .status-Rejected { color: #b02a37; font-weight: 600; }
  .status-Withdrawn { color: #6c757d; font-weight: 600; }
  .withdraw-btn {
    background-color: #b02a37; color: #fff; border: none; padding: 0.3rem 0.7rem;
    border-radius: 3px; cursor: pointer; font-size: 0.85rem;
  }
  .withdraw-btn:hover { background-color: #8a1f2a; }
</style>

<?= $statusMessage ?>

<h2>Request Time Off</h2>

<div class="card">
  <form method="POST" action="submit_time_off.php" class="time-off-form" id="timeOffForm">
    <input type="hidden" name="EmployeeID" value="<?= $empID ?>">

    <div class="field category-options">
      <label>Category:</label>
      <label><input type="radio" name="Category" value="Sick" required> Sick</label>
      <label><input type="radio" name="Category" value="PTO" required> PTO</label>
    </div>

    <div class="row">
      <div class="field">
        <label for="StartDate">Start date</label>
        <input type="date" id="StartDate" name="StartDate" min="<?= $today ?>" required>
      </div>
      <div class="field">
        <label for="EndDate">End date</label>
        <input type="date" id="EndDate" name="EndDate" min="<?= $today ?>" required>
      </div>
    </div>

    <div class="field">
      <label style="font-weight:normal;">
        <input type="checkbox" id="partialDayToggle"> Partial day (specify a time window)
      </label>
      <div id="partialDayFields" class="partial-day">
        <div class="row">
          <div class="field">
            <label for="StartTime">Start time</label>
            <input type="time" id="StartTime" name="StartTime">
          </div>
          <div class="field">
            <label for="EndTime">End time</label>
            <input type="time" id="EndTime" name="EndTime">
          </div>
        </div>
      </div>
    </div>

    <div class="field">
      <label for="Notes">Notes (optional)</label>
      <textarea id="Notes" name="Notes" maxlength="500" placeholder="e.g., doctor appointment, cashing in points, may run longer than 2 hours"></textarea>
    </div>

    <button type="submit">Submit Request</button>
  </form>
</div>

<h2 style="margin-top: 2rem;">My Time Off</h2>

<?php if (empty($history)): ?>
  <p>You have not submitted any time-off requests yet.</p>
<?php else: ?>
  <div class="table-responsive">
    <table class="history-table">
      <thead>
        <tr>
          <th>Submitted</th>
          <th>Category</th>
          <th>Dates</th>
          <th>Times</th>
          <th>Notes</th>
          <th>Status</th>
          <th>Reviewer Note</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($history as $r): ?>
          <tr>
            <td><?= date('m/d/Y', strtotime($r['SubmittedAt'])) ?></td>
            <td><?= htmlspecialchars($r['Category']) ?></td>
            <td><?= formatDateRange($r['StartDate'], $r['EndDate']) ?></td>
            <td><?= formatTimeRange($r['StartTime'], $r['EndTime']) ?></td>
            <td><?= nl2br(htmlspecialchars($r['Notes'] ?? '')) ?: '&mdash;' ?></td>
            <td class="status-<?= htmlspecialchars($r['Status']) ?>"><?= htmlspecialchars($r['Status']) ?></td>
            <td><?= nl2br(htmlspecialchars($r['ReviewNote'] ?? '')) ?: '&mdash;' ?></td>
            <td>
              <?php if ($r['Status'] === 'Pending'): ?>
                <form method="POST" action="withdraw_time_off.php" style="margin:0;">
                  <input type="hidden" name="id" value="<?= (int) $r['ID'] ?>">
                  <button type="submit" class="withdraw-btn" onclick="return confirm('Withdraw this request?');">Withdraw</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<script>
(function() {
  const toggle = document.getElementById('partialDayToggle');
  const fields = document.getElementById('partialDayFields');
  const startTime = document.getElementById('StartTime');
  const endTime = document.getElementById('EndTime');
  const startDate = document.getElementById('StartDate');
  const endDate = document.getElementById('EndDate');
  const form = document.getElementById('timeOffForm');

  toggle.addEventListener('change', () => {
    if (toggle.checked) {
      fields.classList.add('visible');
    } else {
      fields.classList.remove('visible');
      startTime.value = '';
      endTime.value = '';
    }
  });

  // Auto-mirror StartDate -> EndDate if EndDate is empty or earlier
  startDate.addEventListener('change', () => {
    if (!endDate.value || endDate.value < startDate.value) {
      endDate.value = startDate.value;
    }
    endDate.min = startDate.value;
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
