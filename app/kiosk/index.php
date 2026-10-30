<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no"/>
  <meta name="apple-mobile-web-app-capable" content="yes">
  <title>Kiosk TimeSmart</title>
  <link rel="stylesheet" href="kiosk.css?v=2" />
  <link rel="icon" type="image/png" href="/images/D-Best.png">
    <link rel="apple-touch-icon" href="/images/D-Best.png">
  <link rel="icon" type="image/png" href="../images/D-Best-favicon.png">
    <link rel="apple-touch-icon" href="../images/D-Best-favicon.png">
    <link rel="manifest" href="/manifest.json">
</head>
<body>
  <div class="container">
    <div class="header-row">
  <img src="../images/D-Best.png" class="logo" alt="Logo">
  <h1>Kiosk TimeSmart</h1>
</div>

    <div id="currentTime">--:--:--</div>

    <!-- SCAN / PIN ENTRY: one box takes a badge scan (a reader "types" it) or a PIN from the keypad.
         inputmode="none" keeps the iPad keyboard closed while the box keeps focus for the reader. -->
    <div id="scanBox">
      <input type="text" id="tagInput" placeholder="Scan badge or enter PIN" autofocus
             inputmode="none" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
             aria-label="Badge or PIN" />
      <div class="keypad" id="keypad" aria-label="PIN keypad">
        <button type="button" data-key="1">1</button>
        <button type="button" data-key="2">2</button>
        <button type="button" data-key="3">3</button>
        <button type="button" data-key="4">4</button>
        <button type="button" data-key="5">5</button>
        <button type="button" data-key="6">6</button>
        <button type="button" data-key="7">7</button>
        <button type="button" data-key="8">8</button>
        <button type="button" data-key="9">9</button>
        <button type="button" data-key="back" class="key-back" aria-label="Delete">⌫</button>
        <button type="button" data-key="0">0</button>
        <button type="button" data-key="enter" class="key-enter" aria-label="Enter">✓</button>
      </div>
    </div>

    <!-- CHOICE PANEL: only when the next step is ambiguous (e.g. mid-shift: lunch or go home) -->
    <div id="clockPanel" class="hidden">
      <div class="info-row">
        <h2 id="welcomeName">Welcome</h2>
        <p id="clockStatus"></p>
      </div>
      <div class="action-buttons"></div>
    </div>

    <!-- FEEDBACK OVERLAY -->
    <div id="overlay" class="hidden">
      <video id="feedbackVideo" autoplay muted playsinline></video>
      <p id="overlayMessage"></p>
    </div>

    <!-- AUDIO CUES -->
    <audio id="successBeep" src="success.mp3" preload="auto"></audio>
    <audio id="errorBeep" src="error.mp3" preload="auto"></audio>
  </div>

  <script src="kiosk.js?v=2"></script>
</body>
</html>
