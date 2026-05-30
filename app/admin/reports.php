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
                <h3>Overtime Report</h3>
                <p>Per-employee overtime by week (Friday week endings) for the selected period. Defaults to last month.</p>
                <a href="overtime.php">Open Overtime</a>
            </div>

            <div class="report-card">
                <h3>Missed Days Report</h3>
                <p>Payroll tally of missed work days per employee, by Wednesday&ndash;Tuesday pay period (days worked = hours &divide; 8).</p>
                <a href="missed_days.php">Open Missed Days</a>
            </div>

            <div class="report-card">
                <h3>Attendance Report</h3>
                <p>Day-by-day calendar grid showing each employee's Present, Incomplete, or Absent status (plus Sick/PTO) per day.</p>
                <a href="attendance.php">Open Attendance</a>
            </div>

            <div class="report-card">
                <h3>Tardies Report</h3>
                <p>Track late arrivals by pay period with scheduled start times. Separates tardies under and over 5 minutes.</p>
                <a href="tardies.php">Open Tardies</a>
            </div>

            <div class="report-card">
                <h3>Timesheet Report</h3>
                <p>View detailed punch times including clock in/out and lunch periods for all employees.</p>
                <a href="timesheet_report.php">Open Timesheet Report</a>
            </div>
        </div>

<?php require_once 'footer.php'; ?>
