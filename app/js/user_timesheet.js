document.getElementById("editForm").addEventListener("submit", function(e) {
  // Only the punch rows of this form: time off / holiday lines have no inputs
  const rows = Array.from(this.querySelectorAll("tbody tr"));
  let valid = true;

  rows.forEach(row => {
    const reason = row.querySelector('[name$="[Reason]"]');
    if (!reason) return;

    const fields = ["TimeIN", "LunchStart", "LunchEnd", "TimeOut"];
    let changed = false;

    fields.forEach(field => {
      const input = row.querySelector(`[name$="[${field}]"]`);
      if (input && input.value !== input.dataset.original) {
        changed = true;
      }
    });

    if (changed && reason.value.trim() === "") {
      reason.style.borderColor = "red";
      reason.placeholder = "Required for edited rows";
      valid = false;
    } else {
      reason.style.borderColor = "";
    }
  });

  if (!valid) {
    e.preventDefault();
    showPopup("⚠️ Please provide a reason for each time change.");
  }
});

function showPopup(message) {
  document.getElementById("popupMessage").textContent = message;
  document.getElementById("popupFeedback").classList.remove("hidden");
}

function closePopup() {
  document.getElementById("popupFeedback").classList.add("hidden");
}
