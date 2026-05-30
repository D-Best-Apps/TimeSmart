<?php
// admin/update_office_ips.php
// Save an office's allowed-IP allowlist (used by the per-office punch IP restriction).
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/../functions/check_permission.php';
requirePermission('manage_offices');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: manage_offices.php");
    exit;
}

$id  = (int) ($_POST['ID'] ?? 0);
$ips = trim($_POST['AllowedIPs'] ?? '');
if ($id <= 0) {
    header("Location: manage_offices.php");
    exit;
}

// Normalize to a tidy comma-separated list; store NULL when blank (unrestricted).
$entries = preg_split('/[\s,]+/', $ips, -1, PREG_SPLIT_NO_EMPTY);
$value = empty($entries) ? null : implode(', ', $entries);

$stmt = $conn->prepare("UPDATE Offices SET AllowedIPs = ? WHERE ID = ?");
$stmt->bind_param("si", $value, $id);
$stmt->execute();

header("Location: manage_offices.php?ips_saved=1");
exit;
