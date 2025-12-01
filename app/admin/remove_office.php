<?php
// admin/remove_office.php
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;

// Permission check
require_once __DIR__ . '/../functions/check_permission.php';
requirePermission('manage_offices');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ID'])) {
    $id = (int)$_POST['ID'];
    if ($id > 0) {
        $stmt = $conn->prepare("DELETE FROM Offices WHERE ID = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
    }
}

header("Location: manage_offices.php");
exit;
