<?php
// admin/manage_offices.php
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

// Permission check
require_once __DIR__ . '/../functions/check_permission.php';
requirePermission('manage_offices');

$offices_result = $conn->query("SELECT ID, OfficeName FROM Offices ORDER BY OfficeName");
$offices_data = [];
while ($row = $offices_result->fetch_assoc()) {
    $offices_data[] = $row;
}

// How many employees are assigned to each office name (for reassign-on-remove)
$officeUserCounts = [];
$cntRes = $conn->query("SELECT Office, COUNT(*) AS c FROM users WHERE Office IS NOT NULL AND Office <> '' GROUP BY Office");
while ($c = $cntRes->fetch_assoc()) {
    $officeUserCounts[$c['Office']] = (int) $c['c'];
}
$pageTitle = "Manage Offices";
require_once 'header.php';
?>


<div class="uman-container">
    <div class="uman-header">
        <h2>Manage Offices</h2>
        <button class="btn primary" onclick="document.getElementById('addOfficeModal').style.display='block'">+ Add Office</button>
    </div>

    <?php if (isset($_GET['renamed'])): ?>
        <div class="alert alert-success">
            Renamed to <strong><?= htmlspecialchars($_GET['renamed']) ?></strong>
            <?php $moved = (int) ($_GET['moved'] ?? 0); ?>
            — <?= $moved ?> <?= $moved === 1 ? 'employee' : 'employees' ?> updated.
        </div>
    <?php elseif (isset($_GET['removed'])): ?>
        <div class="alert alert-success">
            Removed <strong><?= htmlspecialchars($_GET['removed']) ?></strong>.
            <?php if (isset($_GET['reassigned'])): $moved = (int) ($_GET['moved'] ?? 0); ?>
                <?= $moved ?> <?= $moved === 1 ? 'employee' : 'employees' ?> reassigned to
                <strong><?= htmlspecialchars($_GET['reassigned']) ?></strong>.
            <?php endif; ?>
        </div>
    <?php elseif (!empty($_GET['error'])): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($_GET['error']) ?></div>
    <?php endif; ?>

    <table class="uman-table">
        <thead>
        <tr>
            <th>Office Name</th>
            <th>Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($offices_data as $office): ?>
            <?php $oc = $officeUserCounts[$office['OfficeName']] ?? 0; ?>
            <tr>
                <td>
                    <?= htmlspecialchars($office['OfficeName']) ?>
                    <span style="color:var(--muted-text); font-size:0.85rem;">
                        (<?= $oc ?> <?= $oc === 1 ? 'employee' : 'employees' ?>)
                    </span>
                </td>
                <td>
                    <button type="button" class="btn secondary small"
                            onclick="openRenameOffice(<?= (int)$office['ID'] ?>, <?= htmlspecialchars(json_encode($office['OfficeName']), ENT_QUOTES) ?>)">Rename</button>
                    <button type="button" class="btn danger small"
                            onclick="openRemoveOffice(<?= (int)$office['ID'] ?>, <?= htmlspecialchars(json_encode($office['OfficeName']), ENT_QUOTES) ?>, <?= $oc ?>)">Remove</button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Rename Office Modal -->
<div id="renameOfficeModal" class="modal">
    <div class="modal-content">
        <form action="rename_office.php" method="POST">
            <h3>Rename Office</h3>
            <p style="color:var(--muted-text); font-size:0.9rem; margin:0 0 1rem;">
                Renaming also updates every employee currently assigned to this office.
            </p>
            <input type="hidden" name="ID" id="renameOfficeId">
            <input type="text" name="OfficeName" id="renameOfficeName" placeholder="Office Name" required>
            <div class="modal-actions">
                <button type="button" class="btn" onclick="document.getElementById('renameOfficeModal').style.display='none'">Cancel</button>
                <button type="submit" class="btn primary">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Remove Office Modal -->
<div id="removeOfficeModal" class="modal">
    <div class="modal-content">
        <form action="remove_office.php" method="POST" id="removeOfficeForm">
            <h3>Remove Office</h3>
            <p id="removeOfficeMsg" style="margin:0 0 1rem;"></p>
            <input type="hidden" name="ID" id="removeOfficeId">
            <div id="reassignBlock" style="display:none; margin-bottom:1rem;">
                <label for="reassignTo" style="display:block; font-weight:600; margin-bottom:0.35rem;">Reassign employees to:</label>
                <select name="ReassignTo" id="reassignTo">
                    <?php foreach ($offices_data as $o): ?>
                        <option value="<?= htmlspecialchars($o['OfficeName']) ?>" data-id="<?= (int)$o['ID'] ?>"><?= htmlspecialchars($o['OfficeName']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn" onclick="document.getElementById('removeOfficeModal').style.display='none'">Cancel</button>
                <button type="submit" class="btn danger" id="removeOfficeConfirm">Remove</button>
            </div>
        </form>
    </div>
</div>

<script>
function openRenameOffice(id, name) {
    document.getElementById('renameOfficeId').value = id;
    document.getElementById('renameOfficeName').value = name;
    document.getElementById('renameOfficeModal').style.display = 'block';
}

function openRemoveOffice(id, name, count) {
    document.getElementById('removeOfficeId').value = id;
    var msg     = document.getElementById('removeOfficeMsg');
    var block   = document.getElementById('reassignBlock');
    var select  = document.getElementById('reassignTo');
    var confirm = document.getElementById('removeOfficeConfirm');

    // Show only OTHER offices as reassign targets
    var firstAvailable = null;
    Array.prototype.forEach.call(select.options, function (opt) {
        var isSelf = parseInt(opt.dataset.id, 10) === id;
        opt.hidden = isSelf;
        opt.disabled = isSelf;
        if (!isSelf && firstAvailable === null) firstAvailable = opt;
    });
    if (firstAvailable) select.value = firstAvailable.value;

    if (count > 0) {
        if (!firstAvailable) {
            // Nowhere to move people — block removal
            msg.innerHTML = '<strong>' + name + '</strong> has ' + count +
                ' employee(s), but there is no other office to move them to. Add another office first.';
            block.style.display = 'none';
            select.required = false;
            confirm.disabled = true;
        } else {
            msg.innerHTML = '<strong>' + name + '</strong> has ' + count +
                ' employee(s). Choose where to reassign them, then remove the office.';
            block.style.display = 'block';
            select.required = true;
            confirm.disabled = false;
        }
    } else {
        msg.innerHTML = 'Remove <strong>' + name + '</strong>? No employees are assigned to it.';
        block.style.display = 'none';
        select.required = false;
        confirm.disabled = false;
    }
    document.getElementById('removeOfficeModal').style.display = 'block';
}
</script>

<!-- Add Office Modal -->
<div id="addOfficeModal" class="modal">
    <div class="modal-content">
        <form action="add_office.php" method="POST">
            <h3>Add New Office</h3>
            <input type="text" name="OfficeName" placeholder="Office Name" required>
            <div class="modal-actions">
                <button type="button" class="btn" onclick="document.getElementById('addOfficeModal').style.display='none'">Cancel</button>
                <button type="submit" class="btn primary">Add Office</button>
            </div>
        </form>
    </div>
</div>


<?php require_once 'footer.php'; ?>
