// Admin Timesheet Edit JavaScript
// Handles add, delete, and calculate functionality for punch records

// Initialize date pickers
new Litepicker({
    element: document.getElementById('weekFrom'),
    singleMode: true,
    format: 'MM/DD/YYYY'
});
new Litepicker({
    element: document.getElementById('weekTo'),
    singleMode: true,
    format: 'MM/DD/YYYY'
});

// Counter for new punch rows
let newPunchCounter = 0;

// Convert time string (HH:MM) to minutes
function toMinutes(timeStr) {
    if (!timeStr) return null;
    const [h, m] = timeStr.split(':');
    return parseInt(h) * 60 + parseInt(m);
}

// Calculate total hours for a single row
function calculateRowTotal(row) {
    const inTime = row.querySelector('input[name^="clockin"]')?.value;
    const outTime = row.querySelector('input[name^="clockout"]')?.value;
    const lunchOut = row.querySelector('input[name^="lunchout"]')?.value;
    const lunchIn = row.querySelector('input[name^="lunchin"]')?.value;

    let totalMins = 0;
    const start = toMinutes(inTime);
    const end = toMinutes(outTime);

    if (start !== null && end !== null && end > start) {
        totalMins = end - start;
        const lOut = toMinutes(lunchOut);
        const lIn = toMinutes(lunchIn);
        if (lOut !== null && lIn !== null && lIn > lOut) {
            totalMins -= (lIn - lOut);
        }
        const hours = (totalMins / 60).toFixed(2);
        const totalCell = row.querySelector('.total-cell');
        if (totalCell) {
            totalCell.innerText = hours;
        }
        return parseFloat(hours);
    } else {
        const totalCell = row.querySelector('.total-cell');
        if (totalCell) {
            totalCell.innerText = "0.00";
        }
        return 0;
    }
}

// Update all row totals and weekly total
function updateTotals() {
    let weeklyTotal = 0;
    document.querySelectorAll('tbody tr').forEach(row => {
        const clockinInput = row.querySelector('input[name^="clockin"]');
        // Skip rows that are "No punches" messages or have disabled inputs (deleted rows)
        if (clockinInput && !clockinInput.disabled) {
            weeklyTotal += calculateRowTotal(row);
        }
    });

    const weeklyTotalEl = document.getElementById('weekly-total');
    const weeklyOvertimeEl = document.getElementById('weekly-overtime');

    if (weeklyTotalEl) {
        weeklyTotalEl.innerText = weeklyTotal.toFixed(2) + "h";
    }
    if (weeklyOvertimeEl) {
        weeklyOvertimeEl.innerText = (weeklyTotal > 40 ? (weeklyTotal - 40).toFixed(2) : "0.00") + "h";
    }
}

// Add event listeners to existing time inputs
function attachTimeInputListeners(row) {
    row.querySelectorAll('input[type="time"]').forEach(input => {
        input.removeEventListener('change', handleTimeChange); // Prevent duplicates
        input.addEventListener('change', handleTimeChange);
    });
}

// Handle time input changes
function handleTimeChange(event) {
    const row = event.target.closest('tr');
    if (row) {
        calculateRowTotal(row);
        updateTotals();
    }
}

// Add new punch row
function addNewPunch() {
    const tbody = document.querySelector('.timesheet-table tbody');
    if (!tbody) {
        alert('Error: Could not find table body');
        return;
    }

    // Increment counter for unique ID
    newPunchCounter++;
    const newId = 'new-' + newPunchCounter;

    // Get today's date or first date in range
    const fromInput = document.querySelector('input[name="from"]');
    let dateValue = new Date();
    if (fromInput && fromInput.value) {
        dateValue = new Date(fromInput.value);
    }
    const dateStr = (dateValue.getMonth() + 1).toString().padStart(2, '0') + '/' +
                    dateValue.getDate().toString().padStart(2, '0') + '/' +
                    dateValue.getFullYear();

    // Create new row
    const newRow = document.createElement('tr');
    newRow.innerHTML = `
        <td>
            <input type="date" name="date[${newId}]" value="${dateValue.toISOString().split('T')[0]}" required class="date-input">
        </td>
        <td><input type="time" name="clockin[${newId}]" value="" step="60"></td>
        <td></td>
        <td><input type="time" name="lunchout[${newId}]" value="" step="60"></td>
        <td></td>
        <td><input type="time" name="lunchin[${newId}]" value="" step="60"></td>
        <td></td>
        <td><input type="time" name="clockout[${newId}]" value="" step="60"></td>
        <td></td>
        <td class="total-cell">0.00</td>
        <td>
            <select name="reason[${newId}]" class="reason-dropdown">
                <option value="">Select reason...</option>
                <option value="Forgot to punch">Forgot to punch</option>
                <option value="Shift change">Shift change</option>
                <option value="System error">System error</option>
                <option value="Time correction">Time correction</option>
                <option value="Late arrival">Late arrival</option>
                <option value="Early departure">Early departure</option>
                <option value="Manual update">Manual update</option>
            </select>
        </td>
        <td>
            <button type="button" class="delete-btn" onclick="deleteRow(this)" title="Delete this punch">✖</button>
        </td>
    `;

    // Add row to table
    tbody.appendChild(newRow);

    // Attach event listeners to new inputs
    attachTimeInputListeners(newRow);

    // Update totals
    updateTotals();

    console.log('Added new punch row with ID:', newId);
}

// Delete a punch row
function deleteRow(button) {
    const row = button.closest('tr');
    if (!row) {
        alert('Error: Could not find row to delete');
        return;
    }

    // Get the punch ID from any input in the row
    const firstInput = row.querySelector('input[name^="clockin"]');
    if (!firstInput) {
        alert('Error: Could not find punch input');
        return;
    }

    // Extract ID from name attribute (e.g., "clockin[123]" or "clockin[new-1]")
    const nameMatch = firstInput.name.match(/\[([^\]]+)\]/);
    if (!nameMatch) {
        alert('Error: Could not extract punch ID');
        return;
    }

    const punchId = nameMatch[1];

    // If it's a new row (not yet saved), just remove it from DOM
    if (punchId.startsWith('new-')) {
        if (confirm('Remove this unsaved punch entry?')) {
            row.remove();
            updateTotals();
        }
        return;
    }

    // For existing punches, confirm and mark for deletion
    if (confirm('Are you sure you want to delete this punch record? This cannot be undone.')) {
        // Add hidden input to mark for deletion
        const deleteInput = document.createElement('input');
        deleteInput.type = 'hidden';
        deleteInput.name = 'delete[]';
        deleteInput.value = punchId;
        row.querySelector('form, tbody').closest('form').appendChild(deleteInput);

        // Visual feedback - grey out the row
        row.style.opacity = '0.5';
        row.style.textDecoration = 'line-through';

        // Disable all inputs in the row
        row.querySelectorAll('input, select, button').forEach(el => {
            el.disabled = true;
        });

        // Change button text
        button.textContent = 'Deleted';
        button.disabled = true;

        // Update totals
        updateTotals();
    }
}

// Handle form submission
function handleFormSubmit(event) {
    // Get all new punch rows
    const newRows = document.querySelectorAll('input[name^="clockin[new-"]');

    if (newRows.length > 0) {
        // Add confirm values for new punches
        newRows.forEach(input => {
            const nameMatch = input.name.match(/\[([^\]]+)\]/);
            if (nameMatch) {
                const punchId = nameMatch[1];
                const confirmInput = document.createElement('input');
                confirmInput.type = 'hidden';
                confirmInput.name = 'confirm[]';
                confirmInput.value = punchId;
                event.target.appendChild(confirmInput);
            }
        });
    }

    return true; // Allow form to submit
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Attach listeners to all existing time inputs
    document.querySelectorAll('tbody tr').forEach(row => {
        attachTimeInputListeners(row);
    });

    // Attach form submit handler
    const form = document.querySelector('form[action="save_punches.php"]');
    if (form) {
        form.addEventListener('submit', handleFormSubmit);
    }

    // Initial calculation
    updateTotals();

    console.log('Admin timesheet edit initialized');
});
