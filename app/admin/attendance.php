<?php
session_start();
require_once '../auth/db.php';
require_once './tcpdf/tcpdf.php';
require_once __DIR__ . '/../functions/time_off_hours.php';

if (!isset($_SESSION['admin'])) {
    header("Location: ../user/login.php?admin=1");
    exit;
}

// Permission check
require_once __DIR__ . '/../functions/check_permission.php';
requirePermission('view_attendance');

date_default_timezone_set('America/Chicago');

$startDate = $_GET['start'] ?? date('Y-m-d', strtotime('monday this week'));
$endDate = $_GET['end'] ?? date('Y-m-d', strtotime('friday this week'));
$selectedEmp = $_GET['emp'] ?? 'all';
$exportPDF = isset($_GET['export']) && $_GET['export'] === 'pdf';

$employees = [];
$empResult = $conn->query("SELECT ID, FirstName, LastName FROM users ORDER BY LastName");
while ($row = $empResult->fetch_assoc()) {
    $employees[$row['ID']] = $row['FirstName'] . ' ' . $row['LastName'];
}

// Aggregate per employee per day so multiple sessions (multiple punch rows on the
// same date) collapse into one row: first clock-in, last clock-out, summed hours.
$query = "SELECT EmployeeID, Date,
                 MIN(TimeIN)  AS TimeIN,
                 MAX(TimeOUT) AS TimeOUT,
                 COUNT(TimeOUT) AS Completed,
                 SUM(
                     CASE WHEN TimeIN IS NOT NULL AND TimeOUT IS NOT NULL
                          THEN TIME_TO_SEC(TIMEDIFF(TimeOUT, TimeIN))
                               - TIME_TO_SEC(TIMEDIFF(IFNULL(LunchEnd,'00:00:00'), IFNULL(LunchStart,'00:00:00')))
                          ELSE 0 END
                 ) AS WorkSec
          FROM timepunches WHERE Date BETWEEN ? AND ?";
if ($selectedEmp !== 'all') $query .= " AND EmployeeID = ?";
$query .= " GROUP BY EmployeeID, Date";
$stmt = $conn->prepare($query);
if ($selectedEmp !== 'all') $stmt->bind_param("ssi", $startDate, $endDate, $selectedEmp);
else $stmt->bind_param("ss", $startDate, $endDate);
$stmt->execute();
$result = $stmt->get_result();

$attendance = [];
while ($row = $result->fetch_assoc()) {
    $eid = $row['EmployeeID'];
    $date = $row['Date'];
    $in = $row['TimeIN'];
    $out = $row['TimeOUT'];
    $status = 'Absent';
    $hours = '';
    if ((int) $row['Completed'] > 0) {
        $status = 'Present';
        $hours = round(max(0, (int) $row['WorkSec']) / 3600, 2);
    } elseif ($in || $out) {
        $status = 'Incomplete';
    }
    $attendance[$eid][$date] = [
        'status'     => $status,
        'TimeIN'     => $in,
        'TimeOUT'    => $out,
        'hours'      => $hours,
        'timeOffHrs' => 0,
        'timeOffCat' => null,
    ];
}

// Overlay approved Sick/PTO. If there are no punches for that day, the time-off
// becomes the day's status; if there are punches, the time-off augments the day's
// total hours and is shown as a secondary label.
$timeOffRows = fetchApprovedTimeOff($conn, $startDate, $endDate, $selectedEmp !== 'all' ? (int) $selectedEmp : null);
foreach ($timeOffRows as $req) {
    foreach (expandTimeOffToDays($req, $startDate, $endDate) as $dr) {
        $eid = (int) $req['EmployeeID'];
        $d   = $dr['Date'];
        $cat = ($dr['Category'] === 'Sick') ? 'Sick' : 'PTO';
        $hrs = $dr['Hours'];
        if (!isset($attendance[$eid][$d]) || $attendance[$eid][$d]['status'] === 'Absent') {
            $attendance[$eid][$d] = [
                'status'     => $cat,
                'TimeIN'     => null,
                'TimeOUT'    => null,
                'hours'      => $hrs,
                'timeOffHrs' => $hrs,
                'timeOffCat' => $cat,
            ];
        } else {
            // Has a punch — combine
            $attendance[$eid][$d]['timeOffHrs'] += $hrs;
            $attendance[$eid][$d]['timeOffCat'] = $cat;
            if (is_numeric($attendance[$eid][$d]['hours'])) {
                $attendance[$eid][$d]['hours'] = round($attendance[$eid][$d]['hours'] + $hrs, 2);
            }
        }
    }
}

$dates = [];
$cur = strtotime($startDate);
while ($cur <= strtotime($endDate)) {
    $dates[] = date('Y-m-d', $cur);
    $cur = strtotime('+1 day', $cur);
}

$totalPresent = $totalIncomplete = $totalAbsent = $totalSick = $totalPTO = 0;
foreach (($selectedEmp === 'all' ? array_keys($employees) : [$selectedEmp]) as $eid) {
    foreach ($dates as $date) {
        $status = $attendance[$eid][$date]['status'] ?? 'Absent';
        if      ($status === 'Present')    $totalPresent++;
        elseif  ($status === 'Incomplete') $totalIncomplete++;
        elseif  ($status === 'Sick')       $totalSick++;
        elseif  ($status === 'PTO')        $totalPTO++;
        else                                $totalAbsent++;
    }
}

if ($exportPDF) {
    $pdf = new TCPDF();
    $pdf->AddPage('L', 'A4');
    $pdf->SetCreator('D-Best TimeSmart');
    $pdf->SetAuthor('D-Best TimeSmart');
    $pdf->SetTitle('Attendance Report');

    $html = "<h2>Attendance Report</h2>";
    $html .= "<strong>From:</strong> $startDate &nbsp;&nbsp; <strong>To:</strong> $endDate<br>";
    if ($selectedEmp !== 'all') {
        $html .= "<strong>Employee:</strong> " . htmlspecialchars($employees[$selectedEmp]) . "<br>";
    }

    $html .= "
    <table style='margin: 10px 0; width: 100%; text-align: center; font-size: 12px;'>
        <tr>
            <td style='background-color:#d4edda; padding: 10px;'><strong>Present</strong><br>$totalPresent</td>
            <td style='background-color:#fff3cd; padding: 10px;'><strong>Incomplete</strong><br>$totalIncomplete</td>
            <td style='background-color:#fdecea; padding: 10px;'><strong>Sick</strong><br>$totalSick</td>
            <td style='background-color:#e8f1fc; padding: 10px;'><strong>PTO</strong><br>$totalPTO</td>
            <td style='background-color:#f8d7da; padding: 10px;'><strong>Absent</strong><br>$totalAbsent</td>
        </tr>
    </table>";

    $html .= "<style>
        table { border-collapse: collapse; width: 100%; font-size: 10px; }
        th, td { border: 1px solid #000; padding: 4px; text-align: center; }
        .present { background-color: #d4edda; }
        .incomplete { background-color: #fff3cd; }
        .sick { background-color: #fdecea; }
        .pto { background-color: #e8f1fc; }
        .absent { background-color: #f8d7da; }
    </style>";

    $html .= "<table><tr><th>Employee</th>";
    foreach ($dates as $d) {
        $html .= "<th>" . date('D m/d', strtotime($d)) . "</th>";
    }
    $html .= "</tr>";

    $empIDs = ($selectedEmp === 'all') ? array_keys($employees) : [$selectedEmp];
    foreach ($empIDs as $eid) {
        $html .= "<tr><td>" . htmlspecialchars($employees[$eid]) . "</td>";
        foreach ($dates as $date) {
            $e = $attendance[$eid][$date] ?? ['status' => 'Absent', 'hours' => null, 'TimeIN' => null, 'TimeOUT' => null, 'timeOffHrs' => 0, 'timeOffCat' => null];
            $label = $e['status'];
            if ($e['hours']) $label .= "<br>{$e['hours']} hrs";
            // If they worked AND had time-off, surface the time-off split below the hours
            if (!empty($e['timeOffHrs']) && $e['status'] === 'Present' && !empty($e['timeOffCat'])) {
                $label .= "<br><small>incl. {$e['timeOffHrs']}h {$e['timeOffCat']}</small>";
            }
            $class = strtolower($e['status']);
            $html .= "<td class=\"$class\">$label</td>";
        }
        $html .= "</tr>";
    }
    $html .= "</table>";

    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Output('attendance_report.pdf', 'D');
    exit;
}
$pageTitle = "Attendance Report";
$extraCSS = ["https://cdn.jsdelivr.net/npm/litepicker/dist/css/litepicker.css", "../css/attendance.css?v=2"];
require_once 'header.php';
?>

<style>
  /* Override the unreadable white-on-light-gray header */
  .container table thead th {
    background-color: #f1f1f1 !important;
    color: #000 !important;
    font-weight: bold !important;
  }
</style>

<div class="container">

    <form method="get" class="att-filter">
        <div class="att-field">
            <label>From</label>
            <input type="date" name="start" value="<?= $startDate ?>">
        </div>
        <div class="att-field">
            <label>To</label>
            <input type="date" name="end" value="<?= $endDate ?>">
        </div>
        <div class="att-field">
            <label>Employee</label>
            <select name="emp">
                <option value="all">All Employees</option>
                <?php foreach ($employees as $id => $name): ?>
                    <option value="<?= $id ?>" <?= $selectedEmp == $id ? 'selected' : '' ?>><?= $name ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="att-field att-actions">
            <button type="submit">Filter</button>
            <button type="submit" name="export" value="pdf">📄 Export PDF</button>
        </div>
    </form>

    <div class="status-cards">
        <div class="card present-card">
            <h3>Present</h3>
            <p><?= $totalPresent ?></p>
        </div>
        <div class="card incomplete-card">
            <h3>Incomplete</h3>
            <p><?= $totalIncomplete ?></p>
        </div>
        <div class="card" style="background-color:#fdecea;">
            <h3>Sick</h3>
            <p><?= $totalSick ?></p>
        </div>
        <div class="card" style="background-color:#e8f1fc;">
            <h3>PTO</h3>
            <p><?= $totalPTO ?></p>
        </div>
        <div class="card absent-card">
            <h3>Absent</h3>
            <p><?= $totalAbsent ?></p>
        </div>
    </div>

    <div class="legend">
        <strong>Legend:</strong>
        <span class="green"></span> Present
        <span class="yellow"></span> Incomplete
        <span style="background-color:#fdecea; display:inline-block; width:14px; height:14px; border:1px solid #ccc; vertical-align:middle;"></span> Sick
        <span style="background-color:#e8f1fc; display:inline-block; width:14px; height:14px; border:1px solid #ccc; vertical-align:middle;"></span> PTO
        <span class="red"></span> Absent
    </div>

    <table>
        <thead>
            <tr>
                <th>Employee</th>
                <?php foreach ($dates as $date): ?>
                    <th><?= date('D m/d', strtotime($date)) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach (($selectedEmp === 'all' ? array_keys($employees) : [$selectedEmp]) as $eid): ?>
                <tr>
                    <td><?= htmlspecialchars($employees[$eid]) ?></td>
                    <?php foreach ($dates as $d): ?>
                        <?php
                        $e = $attendance[$eid][$d] ?? ['status' => 'Absent', 'hours' => null, 'TimeIN' => null, 'TimeOUT' => null, 'timeOffHrs' => 0, 'timeOffCat' => null];
                        $label = $e['status'];
                        if ($e['hours']) $label .= "<br>{$e['hours']} hrs";
                        if (!empty($e['timeOffHrs']) && $e['status'] === 'Present' && !empty($e['timeOffCat'])) {
                            $label .= "<br><small style='color:#555;'>incl. {$e['timeOffHrs']}h {$e['timeOffCat']}</small>";
                        }
                        $class = strtolower($e['status']);
                        $style = '';
                        if ($e['status'] === 'Sick') $style = ' style="background-color:#fdecea;"';
                        elseif ($e['status'] === 'PTO') $style = ' style="background-color:#e8f1fc;"';
                        $tip = '';
                        if ($e['TimeIN'] || $e['TimeOUT']) {
                            $tip = "<div class='tooltiptext'>IN: {$e['TimeIN']}<br>OUT: {$e['TimeOUT']}</div>";
                            echo "<td class='$class tooltip'$style>$label$tip</td>";
                        } else {
                            echo "<td class='$class'$style>$label</td>";
                        }
                        ?>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once 'footer.php'; ?>
