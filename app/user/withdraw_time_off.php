<?php
session_start();
require '../auth/db.php';
date_default_timezone_set('America/Chicago');

if (!isset($_SESSION['EmployeeID'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: time_off.php");
    exit;
}

$sessionEmpID = (int) $_SESSION['EmployeeID'];
$requestID    = (int) ($_POST['id'] ?? 0);
$now          = date('Y-m-d H:i:s');

if ($requestID === 0) {
    header('Location: time_off.php');
    exit;
}

// Guard: must be the owner and still Pending. Both clauses in WHERE so a
// race (admin already decided) results in 0 rows affected.
$stmt = $conn->prepare("
    UPDATE time_off_requests
       SET Status = 'Withdrawn', ReviewedAt = ?
     WHERE ID = ? AND EmployeeID = ? AND Status = 'Pending'
");
$stmt->bind_param("sii", $now, $requestID, $sessionEmpID);
$stmt->execute();

header("Location: time_off.php?status=withdrawn");
exit;
