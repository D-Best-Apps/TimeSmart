<?php
/**
 * Shared public-site top navigation (home board, legal pages, report form).
 * Uses absolute (/) paths so it works identically from any page at the web root.
 * The logo + company name link back to the home board; there's no separate
 * "Home" button (the brand serves that purpose) for a consistent header.
 */
?>
<style>
  .brand { display: flex; align-items: center; gap: 10px; text-decoration: none; color: inherit; }
  .topnav .brand:hover { background: transparent; }
</style>

<!-- 🌐 Desktop Nav -->
<header class="topnav desktop-only">
  <a class="brand topnav-left" href="/index.php" title="Home">
    <img src="/images/D-Best.png" class="nav-logo" alt="Logo">
    <span class="nav-title">D-Best TimeSmart</span>
  </a>
  <div class="topnav-right">
    <span class="nav-date"><?= date('F j, Y') ?></span>
    <a href="/user/login.php">🔐 Login</a>
  </div>
</header>

<!-- 📱 Mobile Banner -->
<div class="mobile-banner mobile-only">
  <a class="brand" href="/index.php" title="Home">
    <img src="/images/D-Best.png" alt="Logo" class="nav-logo">
    <span class="nav-title">D-Best TimeSmart</span>
  </a>
</div>

<!-- 📱 Mobile Menu -->
<nav class="mobile-nav mobile-only">
  <a href="/user/login.php">🔐 Login</a>
</nav>
