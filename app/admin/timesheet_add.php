<?php
// Include necessary files (e.g., database connection)
include_once '../auth/db.php';
// Admins only: this page had no sign-in check of its own
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin'])) {
    http_response_code(403);
    exit('Please sign in.');
}
require_once __DIR__ . '/../functions/check_permission.php';
requirePermission('edit_timesheets');

// Get parameters from URL
$employeeID = isset($_GET['emp']) ? $_GET['emp'] : null;
$from_raw = isset($_GET['from']) ? $_GET['from'] : null;
$to_raw = isset($_GET['to']) ? $_GET['to'] : null;

// Convert MM/DD/YYYY to YYYY-MM-DD for database queries
$from = null;
$to = null;

if ($from_raw) {
    $date_obj = DateTime::createFromFormat('m/d/Y', $from_raw);
    if ($date_obj) {
        $from = $date_obj->format('Y-m-d');
    }
}

if ($to_raw) {
    $date_obj = DateTime::createFromFormat('m/d/Y', $to_raw);
    if ($date_obj) {
        $to = $date_obj->format('Y-m-d');
    }
}

// If parameters are still missing after parsing, handle the error
if (!$employeeID || !$from || !$to) {
    echo "Missing or invalid parameters for timesheet add.";
    exit();
}

echo "This is the timesheet add page for Employee ID: " . htmlspecialchars($employeeID) . " from " . htmlspecialchars($from_raw) . " to " . htmlspecialchars($to_raw) . ".";
?>