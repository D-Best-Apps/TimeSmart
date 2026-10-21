<?php
// db.php in /var/www/timeclock/auth/
require_once __DIR__ . '/../vendor/autoload.php';
// Extract environment variables
$host = $_ENV['DB_HOST'];
$db   = $_ENV['DB_NAME'];
$user = $_ENV['DB_USER'];
$pass = $_ENV['DB_PASS'];
$tz   = $_ENV['DB_TIMEZONE'] ?? 'America/Chicago';

// PHP in the container defaults to UTC; keep PHP and MySQL on the same clock.
date_default_timezone_set($tz);

// Connect to MySQL
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die('❌ DB connection failed: ' . $conn->connect_error);
}

// Configure connection
$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '{$tz}'");
?>
