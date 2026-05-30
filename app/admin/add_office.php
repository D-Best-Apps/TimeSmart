<?php
// admin/add_office.php
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

// Permission check
require_once __DIR__ . '/../functions/check_permission.php';
requirePermission('manage_offices');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['OfficeName'])) {
    $officeName = trim($_POST['OfficeName']);
    $entries = preg_split('/[\s,]+/', trim($_POST['AllowedIPs'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
    $allowedIPs = empty($entries) ? null : implode(', ', $entries);
    if (!empty($officeName)) {
        $stmt = $conn->prepare("INSERT INTO Offices (OfficeName, AllowedIPs) VALUES (?, ?)");
        $stmt->bind_param("ss", $officeName, $allowedIPs);
        $stmt->execute();
    }
}

header("Location: manage_offices.php");
exit;
