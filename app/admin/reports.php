<?php
$pageTitle = "Reports Dashboard";
$extraCSS = ["../css/reports.css"];
require_once 'header.php';
?>


        <h2>Available Reports</h2>
        <div class="reports-container">
            <div class="report-card">
                <h3>Summary Report</h3>
                <p>View total hours, regular, and overtime hours across all employees or individually.</p>
                <a href="summary.php">Open Summary</a>
            </div>

            <div class="report-card">
                <h3>Timesheet Editor</h3>
                <p>View and edit detailed punch logs including lunch and break periods per employee.</p>
                <a href="view_punches.php">Open Timesheet Editor</a>
            </div>

            <div class="report-card">
                <h3>Missed Days Report</h3>
                <p>Track missed work days by pay period (Wednesday-Tuesday) with hours converted to days worked.</p>
                <a href="missed_days.php">Open Missed Days</a>
            </div>

            <div class="report-card">
                <h3>Attendance Report</h3>
                <p>View employee attendance calendar with present, incomplete, and absent status per day.</p>
                <a href="attendance.php">Open Attendance</a>
            </div>

            <div class="report-card">
                <h3>Tardies Report</h3>
                <p>Track late arrivals by pay period with scheduled start times. Separates tardies under and over 5 minutes.</p>
                <a href="tardies.php">Open Tardies</a>
            </div>

            <div class="report-card">
                <h3>Export History <em>(Coming Soon)</em></h3>
                <p>View previously exported PDF or Excel reports with download links and filters.</p>
                <a href="#">Not Available</a>
            </div>

            <div class="report-card">
                <h3>Timesheet Report</h3>
                <p>View detailed punch times including clock in/out and lunch periods for all employees.</p>
                <a href="timesheet_report.php">Open Timesheet Report</a>
            </div>
        </div>

<?php require_once 'footer.php'; ?>
