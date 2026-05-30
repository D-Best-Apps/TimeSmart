let resetUserId = null;

function showResetModal(id) {
    resetUserId = id;
    document.getElementById('resetModal').style.display = 'block';
}
function closeResetModal() {
    resetUserId = null;
    document.getElementById('resetModal').style.display = 'none';
}
document.getElementById('confirmResetBtn').addEventListener('click', () => {
    if (!resetUserId) return;
    fetch('reset_password.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id=' + encodeURIComponent(resetUserId)
    })
    .then(r => r.json())
    .then(data => {
        closeResetModal();
        if (data && data.success && data.temp_password) {
            alert(
                'Password reset.\n\nTemporary password: ' + data.temp_password +
                '\n\nGive this to the employee. Once they log in, they should change it in User Settings.'
            );
        } else {
            alert('Reset failed: ' + (data && data.error ? data.error : 'unknown error'));
        }
        window.location.reload();
    })
    .catch(() => {
        closeResetModal();
        alert('Reset failed — network error.');
    });
});

// 2FA Modal Logic
function open2FAModal(userId) {
    document.getElementById('2faUserId').value = userId;
    var codesField = document.getElementById('2faCodesUserId');
    if (codesField) codesField.value = userId;
    document.getElementById('modal2FA').style.display = 'block';
}
function close2FAModal() {
    document.getElementById('modal2FA').style.display = 'none';
}
function confirm2FA(action) {
    const labels = {
        enable: 'Require email 2FA for this user? They will be emailed a code at each login.',
        disable: 'Turn off 2FA and clear backup codes for this user?'
    };
    if (confirm(labels[action])) {
        const form = document.getElementById('form2FA');
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'action';
        input.value = action;
        form.appendChild(input);
        form.submit();
    }
}

// Archive User Modal
function showArchiveModal(userId) {
    document.getElementById('archiveUserId').value = userId;
    document.getElementById('archiveModal').style.display = 'block';
}

function closeArchiveModal() {
    document.getElementById('archiveModal').style.display = 'none';
}

function toggleActionsMenu(button) {
    const menu = button.parentElement;
    const menuContent = menu.querySelector('.actions-menu-content');
    const isActive = menu.classList.contains('active');

    // Close all other menus
    document.querySelectorAll('.actions-menu.active').forEach(otherMenu => {
        if (otherMenu !== menu) {
            otherMenu.classList.remove('active');
            otherMenu.querySelector('.actions-menu-content').classList.remove('up');
        }
    });

    if (isActive) {
        menu.classList.remove('active');
        menuContent.classList.remove('up');
    } else {
        menu.classList.add('active');
        const rect = menuContent.getBoundingClientRect();
        const viewportHeight = window.innerHeight;
        if (rect.bottom > viewportHeight) {
            menuContent.classList.add('up');
        }
    }
}

window.onclick = function(event) {
    if (!event.target.matches('.actions-menu button')) {
        document.querySelectorAll('.actions-menu.active').forEach(menu => {
            menu.classList.remove('active');
            menu.querySelector('.actions-menu-content').classList.remove('up');
        });
    }
}
