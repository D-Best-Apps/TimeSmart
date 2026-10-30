// Kiosk (iPad): one box takes a badge scan or a PIN from the on-screen keypad.
//
// A badge reader acts like a keyboard: it "types" the badge number fast and usually
// presses Enter. A PIN is tapped on the keypad and sent with ✓. Either goes to
// clock_action.php mode=kiosk_scan, which does the next step at once when there is
// only one (clock in, end lunch, clock out), or returns choices to show as buttons
// (mid-shift on a punched-lunch shift: Start Lunch or Clock Out).
document.addEventListener("DOMContentLoaded", () => {
  const tagInput = document.getElementById("tagInput");
  const keypad = document.getElementById("keypad");
  const clockPanel = document.getElementById("clockPanel");
  const welcomeName = document.getElementById("welcomeName");
  const clockStatus = document.getElementById("clockStatus");
  const actionButtons = clockPanel.querySelector(".action-buttons");
  const currentTime = document.getElementById("currentTime");
  const overlay = document.getElementById("overlay");
  const overlayMessage = document.getElementById("overlayMessage");
  const feedbackVideo = document.getElementById("feedbackVideo");
  const successBeep = document.getElementById("successBeep");
  const errorBeep = document.getElementById("errorBeep");

  const IDLE_CLEAR_MS = 10000;   // clear a half-typed PIN after this long
  const CHOICE_TIMEOUT_MS = 10000;
  const OVERLAY_MS = 2000;       // short, so the line keeps moving
  const SCAN_MIN_CHARS = 6;      // badge IDs are at least 6 characters
  const SCAN_MAX_GAP_MS = 50;    // a reader types faster than any person
  const SCAN_SETTLE_MS = 150;    // submit a reader burst that didn't end with Enter
  const STALE_GAP_MS = 1500;     // a keystroke after this long starts a fresh entry

  let pendingValue = '';   // badge/PIN being acted on (kept for the choice buttons)
  let queuedValue = '';    // next person's scan, arrived while a request was running
  let busy = false;
  let lastSource = '';     // 'keypad' | 'hw' — who typed the current entry
  let idleTimer = null, choiceTimer = null, overlayTimer = null, settleTimer = null;
  let typedTimes = [];     // timestamps of hardware keystrokes, for reader detection

  function updateClock() {
    currentTime.textContent = new Date().toLocaleTimeString('en-US', { timeZone: 'America/Chicago' });
  }
  updateClock();
  setInterval(updateClock, 1000);

  overlay.classList.add("hidden");
  overlay.style.display = "none";

  // Keep the box focused so a badge reader always has somewhere to type
  function focusInput() {
    if (document.activeElement !== tagInput) tagInput.focus({ preventScroll: true });
  }
  focusInput();
  document.addEventListener("pointerdown", (e) => {
    if (!e.target.closest("button")) setTimeout(focusInput, 0);
  });
  setInterval(focusInput, 2000);

  function clearEntry() {
    tagInput.value = '';
    typedTimes = [];
    lastSource = '';
    clearTimeout(settleTimer);
  }
  function touchIdle() {
    clearTimeout(idleTimer);
    idleTimer = setTimeout(() => { if (!busy) clearEntry(); }, IDLE_CLEAR_MS);
  }

  // --- Keypad (pointerdown + preventDefault: the box keeps focus, no iPad keyboard) ---
  keypad.addEventListener("pointerdown", (e) => {
    const btn = e.target.closest("button[data-key]");
    if (!btn) return;
    e.preventDefault();
    btn.classList.add("pressed");
    setTimeout(() => btn.classList.remove("pressed"), 120);
    if (navigator.vibrate) navigator.vibrate(10);
    const key = btn.dataset.key;
    if (key === "back") {
      tagInput.value = tagInput.value.slice(0, -1);
    } else if (key === "enter") {
      submit(tagInput.value);
      return;
    } else if (tagInput.value.length < 32) {
      tagInput.value += key;
    }
    typedTimes = []; // keypad taps are never a reader burst
    lastSource = 'keypad';
    touchIdle();
  });

  // --- Hardware keystrokes: a badge reader (or a real keyboard) ---
  tagInput.addEventListener("keydown", (e) => {
    if (e.key === "Enter") {
      e.preventDefault();
      submit(tagInput.value);
    }
  });
  tagInput.addEventListener("input", () => {
    // A badge scanned while a confirmation or someone's choice is showing starts a new entry
    if (!overlay.classList.contains("hidden")) hideOverlay();
    if (!clockPanel.classList.contains("hidden")) closeChoices();
    // A badge scanned after a half-typed keypad PIN, or after a long pause, starts a
    // fresh entry — otherwise the leftovers get glued to the front of the badge number
    const now = performance.now();
    const stale = typedTimes.length && now - typedTimes[typedTimes.length - 1] > STALE_GAP_MS;
    if ((lastSource === 'keypad' || stale) && tagInput.value.length > 1) {
      tagInput.value = tagInput.value.slice(-1);
      typedTimes = [];
    }
    lastSource = 'hw';
    typedTimes.push(now);
    touchIdle();
    // Reader that doesn't send Enter: a fast burst of 6+ characters, then a pause
    clearTimeout(settleTimer);
    settleTimer = setTimeout(() => {
      const t = typedTimes.slice(-tagInput.value.length);
      const fast = t.length >= SCAN_MIN_CHARS && t.every((v, i) => i === 0 || v - t[i - 1] <= SCAN_MAX_GAP_MS);
      if (fast && tagInput.value.length >= SCAN_MIN_CHARS) submit(tagInput.value);
    }, SCAN_SETTLE_MS);
  });

  function post(params) {
    return fetch("../functions/clock_action.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams(params)
    }).then(res => res.json());
  }

  function submit(value) {
    value = (value || '').trim();
    clearTimeout(settleTimer);
    if (!value) return;
    if (busy) {
      // Someone scanned while the previous person's punch was still saving: run it next
      queuedValue = value;
      clearEntry();
      return;
    }
    busy = true;
    pendingValue = value;
    clearEntry();
    post({ TagID: value, mode: "kiosk_scan" })
      .then(data => {
        if (data.success && data.needsChoice) {
          showChoices(data);
        } else {
          showResult(data);
        }
      })
      .catch(() => showOverlay(false, "❌ Network error — try again"))
      .finally(done);
  }

  function done() {
    busy = false;
    if (queuedValue) {
      const next = queuedValue;
      queuedValue = '';
      submit(next);
    }
  }

  function showResult(data) {
    const msg = data.firstName ? `${data.firstName}: ${data.message}` : data.message;
    pendingValue = '';
    showOverlay(!!data.success, msg);
  }

  // --- Choice panel (only when the next step is ambiguous) ---
  function showChoices(data) {
    welcomeName.textContent = `Hi, ${data.firstName}`;
    clockStatus.textContent = data.since ? `Clocked in since ${data.since}` : '';
    actionButtons.innerHTML = '';
    data.choices.forEach(({ action, label }, i) => {
      const btn = document.createElement("button");
      btn.type = "button";
      btn.textContent = label;
      btn.className = "action-btn" + (i === 0 ? " primary" : "");
      btn.addEventListener("pointerdown", (e) => { e.preventDefault(); choose(action); });
      actionButtons.appendChild(btn);
    });
    const cancel = document.createElement("button");
    cancel.type = "button";
    cancel.textContent = "Cancel";
    cancel.className = "cancel-button";
    cancel.addEventListener("pointerdown", (e) => { e.preventDefault(); resetKiosk(); });
    actionButtons.appendChild(cancel);

    // The box stays (hidden keypad only) so the next person's badge still lands in it
    keypad.classList.add("hidden");
    clockPanel.classList.remove("hidden");
    clearTimeout(choiceTimer);
    choiceTimer = setTimeout(resetKiosk, CHOICE_TIMEOUT_MS);
    focusInput();
  }

  function choose(action) {
    if (!pendingValue || busy) return;
    busy = true;
    clearTimeout(choiceTimer);
    post({ TagID: pendingValue, mode: "kiosk", action })
      .then(showResult)
      .catch(() => showOverlay(false, "❌ Failed to punch — try again"))
      .finally(done);
  }

  function closeChoices() {
    clearTimeout(choiceTimer);
    pendingValue = '';
    clockPanel.classList.add("hidden");
    keypad.classList.remove("hidden");
  }
  function resetKiosk() {
    closeChoices();
    clearEntry();
    focusInput();
  }
  window.resetKiosk = resetKiosk;

  // --- Feedback ---
  function showOverlay(success, msg) {
    // Close any choice panel but keep the box: the next person may already be typing
    closeChoices();
    focusInput();
    feedbackVideo.src = success ? "Success Check.webm" : "Alert 3.webm";
    feedbackVideo.load();
    feedbackVideo.play().catch(() => {});
    const beep = success ? successBeep : errorBeep;
    beep.currentTime = 0;
    beep.play().catch(() => {});

    overlayMessage.textContent = msg;
    overlay.classList.remove("hidden");
    overlay.style.display = "flex";
    clearTimeout(overlayTimer);
    overlayTimer = setTimeout(hideOverlay, success ? OVERLAY_MS : OVERLAY_MS + 1500);
  }
  function hideOverlay() {
    clearTimeout(overlayTimer);
    overlay.classList.add("hidden");
    overlay.style.display = "none";
    feedbackVideo.pause();
    focusInput();
  }
  // Tapping the confirmation dismisses it at once
  overlay.addEventListener("pointerdown", (e) => { e.preventDefault(); hideOverlay(); });
});
