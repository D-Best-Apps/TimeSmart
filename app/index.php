<?php 
// For debugging: uncomment the following lines to display errors
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

require './auth/db.php'; 
date_default_timezone_set('America/Chicago'); 


// Check EnforceGPS from settings table
$gpsRequired = false;
$gpsQuery = $conn->prepare("SELECT SettingValue FROM settings WHERE SettingKey = 'EnforceGPS' LIMIT 1");
$gpsQuery->execute();
$gpsQuery->bind_result($value);
if ($gpsQuery->fetch()) {
    $gpsRequired = ($value === '1');
}
$gpsQuery->close();

// Home-page weather panel (null when no ZIP configured / lookup fails)
require_once __DIR__ . '/functions/weather.php';
$weather = getWeatherData($conn);
?>


<!DOCTYPE html>
<html>
<head>
    <title>D-Best TimeClock</title>
    <link rel="stylesheet" href="css/style.css?v=2">
    <link rel="icon" type="image/png" href="/images/D-Best.png">
    <link rel="apple-touch-icon" href="/images/D-Best.png">
    <link rel="icon" type="image/png" href="images/D-Best-favicon.png">
    <link rel="apple-touch-icon" href="images/D-Best-favicon.png">
    <link rel="manifest" href="/manifest.json">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#126ab3">
    <meta name="theme-color" content="#126ab3">
</head>
<body>

<!-- 🌐 Desktop Nav -->
<header class="topnav desktop-only">
  <div class="topnav-left">
    <img src="/images/D-Best.png" class="nav-logo" alt="Logo">
    <span class="nav-title">D-Best TimeSmart</span>
  </div>
  <div class="topnav-right">
    <a class="nav-date" href="https://xkcd.com" target="_blank" rel="noopener" title="Today's comic 😉"><?= date('F j, Y') ?></a>
    <a href="index.php">🏠 Home</a>
    <a href="./user/login.php">🔐 Login</a>
  </div>
</header>

<!-- 📱 Mobile Banner -->
<div class="mobile-banner mobile-only">
  <img src="/images/D-Best.png" alt="Logo" class="nav-logo">
  <span class="nav-title">D-Best TimeSmart</span>
</div>

<!-- 📱 Mobile Menu -->
<nav class="mobile-nav mobile-only">
  <a href="index.php">🏠 Home</a>
  <a href="./user/login.php">🔐 Login</a>
</nav>




<!-- 🔹 Page Content -->
<div class="wrapper">
    <div class="main">
      <div class="home-layout">
        <?php if ($weather): ?>
        <aside class="weather-col" aria-label="Local weather">
            <div class="weather-card">
                <div class="weather-place"><?= htmlspecialchars($weather['place']) ?></div>
                <div class="weather-now">
                    <span class="weather-now-icon"><?= $weather['current']['emoji'] ?></span>
                    <span class="weather-now-temp"><?= htmlspecialchars($weather['current']['temp']) ?>&deg;</span>
                </div>
                <div class="weather-now-label"><?= htmlspecialchars($weather['current']['label']) ?></div>
                <div class="weather-forecast">
                    <?php foreach ($weather['days'] as $day): ?>
                    <div class="weather-day" title="<?= htmlspecialchars($day['label']) ?>">
                        <span class="weather-day-name"><?= htmlspecialchars($day['name']) ?></span>
                        <span class="weather-day-icon"><?= $day['emoji'] ?></span>
                        <span class="weather-day-temps">
                            <strong><?= htmlspecialchars($day['hi']) ?>&deg;</strong>
                            <span class="weather-day-lo"><?= htmlspecialchars($day['lo']) ?>&deg;</span>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </aside>
        <?php endif; ?>
        <div class="status-col">
        <h2>Employee Status</h2>
        <table>
            <tr>
                <th>Name</th>
                <th>Status</th>
                <th>Time</th>
                <th>Date</th>
                <th>Notes</th>
            </tr>
            <?php
            // Optimized, secure query to get all users and their latest punch data in one go.
            $sql = "
                WITH LatestPunches AS (
                    SELECT
                        tp.EmployeeID,
                        tp.Date,
                        tp.TimeIN,
                        tp.LunchStart,
                        tp.LunchEnd,
                        tp.TimeOUT,
                        tp.Note,
                        ROW_NUMBER() OVER(PARTITION BY tp.EmployeeID ORDER BY tp.Date DESC, tp.TimeIN DESC) as rn
                    FROM timepunches tp
                )
                SELECT
                    u.ID,
                    u.FirstName,
                    u.LastName,
                    u.ClockStatus,
                    lp.Date AS PunchDate,
                    lp.TimeIN,
                    lp.LunchStart,
                    lp.LunchEnd,
                    lp.TimeOUT,
                    lp.Note AS PunchNote
                FROM users u
                LEFT JOIN LatestPunches lp ON u.ID = lp.EmployeeID AND lp.rn = 1
                ORDER BY u.LastName, u.FirstName;
            ";

            $result = $conn->query($sql);

            function formatTime12h($time) {
                return $time ? date("g:i A", strtotime($time)) : 'N/A';
            }

            while ($row = $result->fetch_assoc()):
                $fullName = $row['FirstName'] . ' ' . $row['LastName'];
                $status = $row['ClockStatus'];
                
                // Determine the most recent time from the single record
                $rawTime = $row['TimeOUT'] ?? $row['LunchEnd'] ?? $row['LunchStart'] ?? $row['TimeIN'];
                $lastTime = formatTime12h($rawTime);
                $lastDate = $row['PunchDate'] ? date("m/d/Y", strtotime($row['PunchDate'])) : 'N/A';
                $note = $row['PunchNote'] ?? '-';
            ?>
            <tr>
                <td><a href="#" onclick="openModal(<?= $row['ID'] ?>, '<?= htmlspecialchars($fullName) ?>')"><?= htmlspecialchars($fullName) ?></a></td>
                <td><span class="status <?= strtolower($status ?: 'out') ?>"><?= htmlspecialchars($status ?: 'Out') ?></span></td>
                <td><?= $lastTime ?></td>
                <td><?= $lastDate ?></td>
                <td><?= htmlspecialchars($note) ?></td>
            </tr>
            <?php endwhile; ?>
        </table>
        </div><!-- /.status-col -->
      </div><!-- /.home-layout -->
    </div>
</div>

<?php include 'functions/modal.html'; ?>

<script src="js/index.js"></script>






<script src="js/script.js"></script>
<footer>
  <p>D-Best TimeSmart &copy; <?= date('Y') ?>. All rights reserved.</p>
  <p style="margin-top: 0.3rem;">
    <a href="/docs/privacy.php" style="color:#e0e0e0; text-decoration:none; margin-right:15px;">Privacy Policy</a>
    <a href="/docs/terms.php" style="color:#e0e0e0; text-decoration:none;">Terms of Use</a>
    <a href="/docs/report.php" style="color:#e0e0e0; text-decoration:none;">Report Issues</a>
  </p>
</footer>

<script>
  let refreshTimeout;
  function resetRefreshTimer() {
    clearTimeout(refreshTimeout);
    refreshTimeout = setTimeout(() => location.reload(), 30000);
  }
  document.addEventListener('mousedown', resetRefreshTimer);
  document.addEventListener('mousemove', resetRefreshTimer);
  document.addEventListener('keypress', resetRefreshTimer);
  document.addEventListener('scroll', resetRefreshTimer);
  resetRefreshTimer();
</script>
</body>
</html>
