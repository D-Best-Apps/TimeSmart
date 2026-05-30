document.addEventListener("DOMContentLoaded", () => {
  const menuBtn = document.getElementById('menuBtn');
  const menuOverlay = document.getElementById('mobileMenuOverlay');
  const closeMenu = document.getElementById('closeMenu');
  const modal = document.getElementById('modal');
  const closeModal = document.getElementById('modalClose');
  const adjustPopup = document.getElementById('adjustPopup');
  const confirmPopup = document.getElementById('confirmPopup');
  const customPopup = document.getElementById('customPopup');
  const popupClose = document.getElementById('popupClose');

  // 🟦 Mobile menu toggle
  if (menuBtn && menuOverlay) {
    menuBtn.addEventListener('click', () => {
      menuOverlay.style.display = 'flex';
    });
  }

  if (closeMenu && menuOverlay) {
    closeMenu.addEventListener('click', () => {
      menuOverlay.style.display = 'none';
    });
  }

  // 🟥 Close buttons
  if (closeModal && modal) {
    closeModal.addEventListener('click', () => {
      modal.classList.add('hidden');
    });
  }

  if (popupClose && customPopup) {
    popupClose.addEventListener('click', () => {
      customPopup.classList.add('hidden');
    });
  }

  // 🕒 Quick clock (PIN / Badge) above the employee status board
  const quickClock = document.getElementById('quickClock');
  if (quickClock) {
    const feedback = document.getElementById('qcFeedback');

    const focusDefault = () => {
      const def = quickClock.dataset.default;
      const el = def === 'pin' ? document.getElementById('qcPin')
               : def === 'badge' ? document.getElementById('qcBadge')
               : null;
      if (el) el.focus();
    };
    focusDefault();

    quickClock.querySelectorAll('.qc-form').forEach((form) => {
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const input = form.querySelector('input[name="value"]');
        const value = (input.value || '').trim();
        if (!value) return;
        try {
          const res = await fetch('functions/clock_action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ mode: 'quickclock', method: form.dataset.method, value })
          });
          const data = await res.json();
          feedback.textContent = data.message || (data.success ? 'Done.' : 'Something went wrong.');
          feedback.className = 'qc-feedback ' + (data.success ? 'ok' : 'err');
          input.value = '';
          if (data.success) {
            // Refresh the board, then the cursor returns on reload for the next person
            setTimeout(() => location.reload(), 1600);
          } else {
            input.focus();
          }
        } catch (err) {
          feedback.textContent = '⚠️ Network error — please try again.';
          feedback.className = 'qc-feedback err';
          input.focus();
        }
      });
    });
  }

  // ❌ Click outside to close overlays/popups
  window.addEventListener('click', (e) => {
    if (e.target === modal && modal) modal.classList.add('hidden');
    if (e.target === adjustPopup && adjustPopup) adjustPopup.classList.add('hidden');
    if (e.target === confirmPopup && confirmPopup) confirmPopup.classList.add('hidden');
    if (e.target === customPopup && customPopup) customPopup.classList.add('hidden');
    if (e.target === menuOverlay && menuOverlay) menuOverlay.style.display = 'none';
  });
});
