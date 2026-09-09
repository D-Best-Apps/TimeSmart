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
        case 'cancelled':
            $statusMessage = '<div class="alert alert-info">Your approved time off has been cancelled and your manager has been notified.</div>';
            if (isset($_GET['m365_sync']) && $_GET['m365_sync'] === 'failed') {
                $statusMessage .= '<div class="alert alert-danger">The calendar event could not be removed automatically. Your manager has been told to delete it by hand.</div>';
            }
            break;
        case 'updated':
            $statusMessage = '<div class="alert alert-success">Your pending request has been updated.</div>';
            break;
        case 'amendment_submitted':
            $statusMessage = '<div class="alert alert-success">Your amendment has been submitted for approval. The original request stays active until the amendment is reviewed.</div>';
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

// Identify Approved rows that already have a Pending amendment — hide Edit on those
$pendingAmendmentOf = [];
$amendStmt = $conn->prepare("SELECT AmendsRequestID FROM time_off_requests WHERE EmployeeID = ? AND Status = 'Pending' AND AmendsRequestID IS NOT NULL");
$amendStmt->bind_param("i", $empID);
$amendStmt->execute();
$amendRes = $amendStmt->get_result();
while ($r = $amendRes->fetch_assoc()) {
    $pendingAmendmentOf[(int) $r['AmendsRequestID']] = true;
}

$today = date('Y-m-d');

function formatTimeRange(?string $start, ?string $end): string {
    if (!$start || !$end) return 'all day';
    return date('g:i a', strtotime($start)) . ' &ndash; ' . date('g:i a', strtotime($end));
}
function formatDateRange(string $start, string $end): string {
    if ($start === $end) return date('m/d/Y', strtotime($start));
    return date('m/d/Y', strtotime($start)) . ' &ndash; ' . date('m/d/Y', strtotime($end));
}
// Entity-free variant for attribute values / JS strings (formatDateRange emits &ndash;).
function plainDateRange(string $start, string $end): string {
    if ($start === $end) return date('m/d/Y', strtotime($start));
    return date('m/d/Y', strtotime($start)) . ' - ' . date('m/d/Y', strtotime($end));
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
  .status-Cancelled { color: #b02a37; font-weight: 600; }
  .withdraw-btn {
    background-color: #b02a37; color: #fff; border: none; padding: 0.3rem 0.7rem;
    border-radius: 3px; cursor: pointer; font-size: 0.85rem;
  }
  .withdraw-btn:hover { background-color: #8a1f2a; }
  .to-modal-backdrop {
    display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5);
    z-index: 1000; align-items: center; justify-content: center; padding: 1rem;
  }
  .to-modal-backdrop.visible { display: flex; }
  .to-modal {
    background: #fff; color: #222; border-radius: 6px; padding: 1.25rem;
    width: 100%; max-width: 460px; box-shadow: 0 8px 30px rgba(0,0,0,0.3);
  }
  .to-modal h3 { margin: 0 0 0.5rem; font-size: 1.1rem; }
  .to-modal p { margin: 0 0 0.75rem; font-size: 0.9rem; color: #555; }
  .to-modal label { display: block; font-weight: 600; margin-bottom: 0.25rem; }
  .to-modal textarea {
    width: 100%; padding: 0.5rem; border: 1px solid #ccc; border-radius: 4px;
    font-size: 1rem; box-sizing: border-box; resize: vertical; min-height: 70px;
  }
  .to-modal-actions { display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1rem; }
  .to-modal-actions button {
    border: none; padding: 0.5rem 1rem; border-radius: 4px; cursor: pointer; font-size: 0.95rem;
  }
  .to-modal-keep { background-color: #e0e0e0; color: #222; }
  .to-modal-confirm { background-color: #b02a37; color: #fff; }
  .to-modal-confirm:hover { background-color: #8a1f2a; }
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
          <?php
            $isAmendment = !empty($r['AmendsRequestID']);
            $hasOpenAmendment = isset($pendingAmendmentOf[(int) $r['ID']]);
          ?>
          <tr>
            <td><?= date('m/d/Y', strtotime($r['SubmittedAt'])) ?></td>
            <td>
              <?= htmlspecialchars($r['Category']) ?>
              <?php if ($isAmendment): ?>
                <span style="display:inline-block; padding:1px 6px; border-radius:3px; background-color:#fff3cd; color:#856404; font-size:0.75rem; margin-left:0.25rem;">amendment</span>
              <?php endif; ?>
            </td>
            <td><?= formatDateRange($r['StartDate'], $r['EndDate']) ?></td>
            <td><?= formatTimeRange($r['StartTime'], $r['EndTime']) ?></td>
            <td>
              <?= nl2br(htmlspecialchars($r['Notes'] ?? '')) ?: '&mdash;' ?>
              <?php if ($isAmendment && !empty($r['Reason'])): ?>
                <div style="margin-top:0.25rem; font-size:0.85rem; color:#555;"><em>Reason for change:</em> <?= nl2br(htmlspecialchars($r['Reason'])) ?></div>
              <?php endif; ?>
            </td>
            <td class="status-<?= htmlspecialchars($r['Status']) ?>"><?= htmlspecialchars($r['Status']) ?></td>
            <td><?= nl2br(htmlspecialchars($r['ReviewNote'] ?? '')) ?: '&mdash;' ?></td>
            <td style="white-space:nowrap;">
              <?php if ($r['Status'] === 'Pending'): ?>
                <a href="edit_time_off.php?id=<?= (int) $r['ID'] ?>" class="withdraw-btn" style="background-color:#0078D7; text-decoration:none; display:inline-block; margin-right:0.25rem;">Edit</a>
                <form method="POST" action="withdraw_time_off.php" style="margin:0; display:inline-block;">
                  <input type="hidden" name="id" value="<?= (int) $r['ID'] ?>">
                  <button type="submit" class="withdraw-btn" onclick="return confirm('Withdraw this request?');">Withdraw</button>
                </form>
              <?php elseif ($r['Status'] === 'Approved' && !$hasOpenAmendment): ?>
                <a href="edit_time_off.php?id=<?= (int) $r['ID'] ?>" class="withdraw-btn" style="background-color:#0078D7; text-decoration:none; display:inline-block; margin-right:0.25rem;">Edit</a>
                <?php if ($r['StartDate'] > $today): ?>
                  <button type="button" class="withdraw-btn cancel-to-btn"
                          data-id="<?= (int) $r['ID'] ?>"
                          data-label="<?= htmlspecialchars($r['Category'] . ', ' . plainDateRange($r['StartDate'], $r['EndDate']), ENT_QUOTES) ?>">Cancel</button>
                <?php endif; ?>
              <?php elseif ($r['Status'] === 'Approved' && $hasOpenAmendment): ?>
                <span style="color:#856404; font-size:0.85rem;">amendment pending</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<div class="to-modal-backdrop" id="cancelTOBackdrop">
  <div class="to-modal" role="dialog" aria-modal="true" aria-labelledby="cancelTOTitle">
    <form method="POST" action="cancel_time_off.php" id="cancelTOForm">
      <h3 id="cancelTOTitle">Cancel this time off</h3>
      <p>
        <strong id="cancelTOLabel"></strong><br>
        These hours will stop counting toward your time off, the calendar event is removed,
        and your manager is notified. You can submit a new request later if plans change.
      </p>
      <input type="hidden" name="RequestID" id="cancelTORequestID" value="">
      <div>
        <label for="CancelReason">Reason (required &mdash; sent to your manager)</label>
        <textarea id="CancelReason" name="CancelReason" maxlength="500"
                  placeholder="e.g., trip fell through, covering a shift instead" required></textarea>
      </div>
      <div class="to-modal-actions">
        <button type="button" class="to-modal-keep" id="cancelTOKeep">Keep it</button>
        <button type="submit" class="to-modal-confirm">Cancel this time off</button>
      </div>
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

  // Cancel-approved-time-off modal
  const backdrop  = document.getElementById('cancelTOBackdrop');
  const cancelId  = document.getElementById('cancelTORequestID');
  const cancelLbl = document.getElementById('cancelTOLabel');
  const reasonBox = document.getElementById('CancelReason');

  function closeCancelModal() {
    backdrop.classList.remove('visible');
    reasonBox.value = '';
    cancelId.value = '';
  }

  document.querySelectorAll('.cancel-to-btn').forEach((btn) => {
    btn.addEventListener('click', () => {
      cancelId.value = btn.dataset.id;
      cancelLbl.textContent = btn.dataset.label;
      backdrop.classList.add('visible');
      reasonBox.focus();
    });
  });

  document.getElementById('cancelTOKeep').addEventListener('click', closeCancelModal);
  backdrop.addEventListener('click', (e) => {
    if (e.target === backdrop) closeCancelModal();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && backdrop.classList.contains('visible')) closeCancelModal();
  });

  document.getElementById('cancelTOForm').addEventListener('submit', (e) => {
    if (reasonBox.value.trim() === '') {
      e.preventDefault();
      alert('Please give a reason so your manager knows why.');
    }
  });
})();
</script>

<?php require_once 'footer.php'; ?>
