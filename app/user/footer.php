    </div>
</div>

<footer>
  <p>D-Best TimeSmart &copy; <?= date('Y') ?>. All rights reserved.</p>
  <p style="margin-top: 0.3rem;">
    <a href="/privacy.php" style="color:#e0e0e0; text-decoration:none; margin-right:15px;">Privacy Policy</a>
    <a href="/terms.php" style="color:#e0e0e0; text-decoration:none;">Terms of Use</a>
    <a href="/report.php" style="color:#e0e0e0; text-decoration:none;">Report Issues</a>
  </p>
</footer>

<script>
function toggleDropdown() {
    document.getElementById("profileMenu").classList.toggle("hidden");
}

window.addEventListener('click', function(e) {
    const menu = document.getElementById("profileMenu");
    const trigger = document.querySelector(".profile-trigger");
    if (menu && !menu.classList.contains("hidden") && trigger && !trigger.contains(e.target)) {
        menu.classList.add("hidden");
    }
});
</script>
</body>
</html>
