<?php
// admin/manage_offices.php
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;

// Permission check
require_once __DIR__ . '/../functions/check_permission.php';
requirePermission('manage_offices');
}

$offices_result = $conn->query("SELECT ID, OfficeName FROM Offices ORDER BY OfficeName");
$offices_data = [];
while ($row = $offices_result->fetch_assoc()) {
    $offices_data[] = $row;
}
$pageTitle = "Manage Offices";
require_once 'header.php';
?>
<link rel="stylesheet" href="../css/manage_offices.css" />


<div class="uman-container">
    <div class="uman-header">
        <h2>Manage Offices</h2>
        <button class="btn primary" onclick="document.getElementById('addOfficeModal').style.display='block'">+ Add Office</button>
    </div>

    <table class="uman-table">
        <thead>
        <tr>
            <th>Office Name</th>
            <th>Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($offices_data as $office): ?>
            <tr>
                <td><?= htmlspecialchars($office['OfficeName']) ?></td>
                <td>
                    <form action="remove_office.php" method="POST" style="display:inline;">
                        <input type="hidden" name="ID" value="<?= (int)$office['ID'] ?>">
                        <button type="submit" class="btn danger small">Remove</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

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
