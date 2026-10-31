<?php
/**
 * Demo data for a fresh TimeSmart database: offices, three shifts, a dozen
 * employees with badges and PINs, ~3 weeks of punches ending today, time off,
 * and a couple of items waiting for approval.
 *
 * Dates are relative to today, so the demo always looks current, and paid hours
 * go through the real rules engine (recomputeDayHours), so reports match what
 * the app would produce. Logins and badges are listed in docs/DEMO.md.
 *
 * Refuses to run on a database that already has users: it is for demo/dev
 * databases only (deploy/dev/setup.ps1 runs it on a brand-new one).
 *
 *   php deploy/scripts/seed_demo.php          (DB_* environment variables set)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../app/auth/db.php';
require_once __DIR__ . '/../../app/functions/pay_rules.php';

const DEMO_PASSWORD = 'Demo123!';

$count = (int) $conn->query("SELECT COUNT(*) FROM users")->fetch_column();
if ($count > 0) {
    fwrite(STDERR, "✗ This database already has {$count} user(s). The demo seed only runs on an empty database.\n");
    exit(1);
}

mt_srand(1031); // same "random" variation every run
$rand = fn(int $min, int $max) => mt_rand($min, $max);

$conn->begin_transaction();

// ---------- Settings ----------
$settings = [
    'PayWeekStartDay' => '1',          // Monday-Sunday work week
    'OvertimeWeeklyHours' => '40',
    'PayPeriodType' => 'weekly',
    'DefaultRoundIncrement' => '0',
    'DefaultRoundMode' => 'nearest',
    'PasswordSecurityMode' => 'high',
    'QuickPinEnabled' => '1',
    'QuickBadgeEnabled' => '1',
    'KioskPinEnabled' => '1',
    'EnforceGPS' => '0',
    'RestrictByIP' => '0',
];
$st = $conn->prepare("INSERT INTO settings (SettingKey, SettingValue) VALUES (?, ?) ON DUPLICATE KEY UPDATE SettingValue = VALUES(SettingValue)");
foreach ($settings as $k => $v) {
    $st->bind_param("ss", $k, $v);
    $st->execute();
}

// ---------- Shifts ----------
$shiftDefs = [
    '1st'    => ['1st Shift', '#2e7d32', '07:00:00', '15:30:00', 15, 5, 5, 10, 'actual', 15, 'nearest', 'auto', 30, '6.00'],
    '2nd'    => ['2nd Shift', '#0a4a6b', '15:30:00', '00:00:00', 15, 5, 5, 10, 'cap',    15, 'nearest', 'punched', 30, '6.00'],
    'office' => ['Office',    '#6a1b9a', '08:00:00', '16:30:00', 10, 5, 5, 10, 'actual',  0, 'nearest', 'auto_unless_punched', 30, '6.00'],
];
$shiftIDs = [];
$st = $conn->prepare("INSERT INTO shifts (Name, Color, StartTime, EndTime, Days, EarlyInMinutes, LateInGraceMinutes, EarlyOutGraceMinutes, LateOutMinutes, OutsidePolicy, RoundIncrement, RoundMode, LunchMode, LunchMinutes, LunchMinWorkedHours) VALUES (?, ?, ?, ?, 'Mon,Tue,Wed,Thu,Fri', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
foreach ($shiftDefs as $key => $d) {
    $st->bind_param("ssssiiiisissis", ...$d);
    $st->execute();
    $shiftIDs[$key] = $conn->insert_id;
}

// ---------- Offices ----------
$st = $conn->prepare("INSERT INTO Offices (OfficeName, DefaultShiftID) VALUES (?, ?)");
foreach (['Plant' => $shiftIDs['1st'], 'Front Office' => $shiftIDs['office']] as $name => $sid) {
    $st->bind_param("si", $name, $sid);
    $st->execute();
}

// ---------- Employees ----------
// [first, last, role, office, shift key|null, badge, PIN, job title]
$people = [
    ['Pat',    'Admin',    'super_admin',  'Front Office', 'office', '900001', '9001', 'Plant Manager'],
    ['Robin',  'Reports',  'reports_only', 'Front Office', 'office', '900002', '9002', 'Payroll'],
    ['Alex',   'Rivera',   'employee', 'Plant', '1st',  '100001', '1001', 'Machine Operator'],
    ['Jamie',  'Chen',     'employee', 'Plant', '1st',  '100002', '1002', 'Machine Operator'],
    ['Morgan', 'Lee',      'employee', 'Plant', '1st',  '100003', '1003', 'Assembler'],
    ['Taylor', 'Brooks',   'employee', 'Plant', '1st',  '100004', '1004', 'Assembler'],
    ['Casey',  'Nguyen',   'employee', 'Plant', '1st',  '100005', '1005', 'Quality Tech'],
    ['Jordan', 'Patel',    'employee', 'Plant', '1st',  '100006', '1006', 'Forklift Operator'],
    ['Riley',  'Johnson',  'employee', 'Plant', '2nd',  '100007', '1007', 'Machine Operator'],
    ['Avery',  'Kim',      'employee', 'Plant', '2nd',  '100008', '1008', 'Assembler'],
    ['Quinn',  'Martinez', 'employee', 'Plant', '2nd',  '100009', '1009', 'Maintenance'],
    ['Sam',    'Ortiz',    'employee', 'Plant', null,   '100010', '1010', 'Shipping'],
];
$hash = password_hash(DEMO_PASSWORD, PASSWORD_BCRYPT, ['cost' => 10]);
$st = $conn->prepare("INSERT INTO users (FirstName, LastName, Email, Pass, BadgeID, PIN, ClockStatus, Office, JobTitle, Role, ShiftID, TwoFAEnabled, AdminOverride2FA, NotifyTimeOff, NotifyTimesheetEdits) VALUES (?, ?, ?, ?, ?, ?, 'Out', ?, ?, ?, ?, 0, 1, ?, ?)");
$emp = [];
foreach ($people as [$first, $last, $role, $office, $shiftKey, $badge, $pin, $title]) {
    $email  = strtolower("{$first}.{$last}@demo.example");
    $sid    = $shiftKey ? $shiftIDs[$shiftKey] : null;
    $notify = $role === 'super_admin' ? 1 : 0;
    $st->bind_param("sssssssssiii", $first, $last, $email, $hash, $badge, $pin, $office, $title, $role, $sid, $notify, $notify);
    $st->execute();
    $emp[] = ['id' => $conn->insert_id, 'name' => "$first $last", 'shift' => $shiftKey];
}

// ---------- Time off (skip punches on these days) ----------
$today = new DateTimeImmutable('today');
$monday = $today->modify('monday this week');
$d = fn(DateTimeImmutable $base, string $mod) => $base->modify($mod)->format('Y-m-d');
$timeOff = [
    // [employee index, category, start, end, status, notes]
    [4, 'Sick', $d($monday, '-7 days'),  $d($monday, '-6 days'),  'Approved', 'Flu'],
    [3, 'PTO',  $d($monday, '+7 days'),  $d($monday, '+8 days'),  'Approved', 'Family trip'],
    [5, 'PTO',  $d($monday, '+14 days'), $d($monday, '+18 days'), 'Pending',  'Vacation week'],
];
$off = [];
$st = $conn->prepare("INSERT INTO time_off_requests (EmployeeID, Category, StartDate, EndDate, Notes, Status, SubmittedAt, ReviewedAt, ReviewedBy) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
foreach ($timeOff as [$i, $cat, $s, $e, $status, $notes]) {
    $submitted = $d(new DateTimeImmutable($s), '-10 days') . ' 09:15:00';
    $reviewed  = $status === 'Approved' ? $d(new DateTimeImmutable($s), '-9 days') . ' 10:00:00' : null;
    $by        = $status === 'Approved' ? 'Pat Admin' : null;
    $st->bind_param("issssssss", $emp[$i]['id'], $cat, $s, $e, $notes, $status, $submitted, $reviewed, $by);
    $st->execute();
    for ($x = new DateTimeImmutable($s); $x <= new DateTimeImmutable($e); $x = $x->modify('+1 day')) {
        $off[$emp[$i]['id']][$x->format('Y-m-d')] = true;
    }
}

// ---------- Punches: the last three weeks through today ----------
$hm = fn(int $sec) => sprintf('%02d:%02d:%02d', intdiv(($sec % 86400 + 86400) % 86400, 3600), intdiv(($sec % 3600 + 3600) % 3600, 60), ($sec % 60 + 60) % 60);
$sched = [
    '1st'    => [7 * 3600, 15 * 3600 + 1800],
    '2nd'    => [15 * 3600 + 1800, 24 * 3600],
    'office' => [8 * 3600, 16 * 3600 + 1800],
    null     => [8 * 3600, 16 * 3600 + 1800],   // Sam: no shift, punches lunch
];
$nowSec = (int) date('G') * 3600 + (int) date('i') * 60;
$ins = $conn->prepare("INSERT INTO timepunches (EmployeeID, Date, TimeIN, LunchStart, LunchEnd, TimeOut, TotalHours) VALUES (?, ?, ?, ?, ?, ?, ?)");
$days = [];
$start = $today->modify('-20 days');
for ($day = $start; $day <= $today; $day = $day->modify('+1 day')) {
    if ((int) $day->format('N') >= 6) continue; // weekdays only
    $date = $day->format('Y-m-d');
    $isToday = $date === $today->format('Y-m-d');
    foreach ($emp as $idx => $e) {
        if (!empty($off[$e['id']][$date])) continue;
        if (!$isToday && $rand(1, 100) <= 3) continue; // the odd absence
        [$S, $E] = $sched[$e['shift']];
        if ($isToday && $nowSec < $S - 900) continue;  // shift hasn't started yet

        // Clock in: mostly a few minutes early, sometimes late
        $in = $S + ($rand(1, 100) <= 12 ? $rand(6, 22) * 60 : -$rand(1, 14) * 60) + $rand(0, 59);
        // Clock out: around the shift end, sometimes staying late
        $out = $E + ($rand(1, 100) <= 10 ? $rand(40, 95) * 60 : $rand(-4, 11) * 60) + $rand(0, 59);
        // Lunch punches: 2nd shift and Sam always, office half the time, 1st never (auto)
        $punchLunch = $e['shift'] === '2nd' || $e['shift'] === null || ($e['shift'] === 'office' && $rand(0, 1));
        $ls = $le = null;
        if ($punchLunch) {
            $lsSec = $S + 4 * 3600 + $rand(-20, 20) * 60 + $rand(0, 59);
            $ls = $lsSec;
            $le = $lsSec + $rand(26, 38) * 60 + $rand(0, 59);
        }

        if ($isToday) {
            // Today: still on the clock (shift in progress)
            if ($nowSec < $in) $in = $nowSec - 120;
            $tIn = $hm($in);
            $tLs = ($ls !== null && $nowSec > $ls) ? $hm($ls) : null;
            $tLe = ($tLs !== null && $nowSec > $le) ? $hm($le) : null;
            if ($nowSec < $E) {
                $null = null;
                $ins->bind_param("isssssd", $e['id'], $date, $tIn, $tLs, $tLe, $null, $null);
                $ins->execute();
                continue;
            }
            if ($tLs !== null && $tLe === null) $tLe = $hm($le);
        }

        $tIn = $hm($in);
        $tLs = $ls !== null ? $hm($ls) : null;
        $tLe = $le !== null ? $hm($le) : null;
        $tOut = $hm($out);
        $raw = calculateTotalHours($tIn, $tLs, $tLe, $tOut);
        $ins->bind_param("isssssd", $e['id'], $date, $tIn, $tLs, $tLe, $tOut, $raw);
        $ins->execute();
        $days[$e['id']][$date] = true;
    }
}

// ---------- Things for an admin to look at ----------
// Riley forgot to punch back in from lunch three days ago: hours wait for review
$riley = $emp[8]['id'];
$reviewDay = $today->modify('-3 weekdays')->format('Y-m-d');
$conn->query("UPDATE timepunches SET LunchEnd = NULL, TotalHours = NULL WHERE EmployeeID = {$riley} AND Date = '{$reviewDay}' AND TimeOut IS NOT NULL");
if ($conn->affected_rows > 0) {
    queuePunchReview($conn, $riley, $reviewDay, null, 'Clocked out with an unfinished lunch (no lunch-end punch) — needs time entry');
}

// Alex asks to fix yesterday's clock-out
$alex = $emp[2]['id'];
$yesterday = $today->modify('-1 weekday')->format('Y-m-d');
$st = $conn->prepare("INSERT INTO pending_edits (EmployeeID, Date, TimeOut, Note, Reason, Source, Status, SubmittedAt) VALUES (?, ?, '16:45:00', 'Stayed to finish the press changeover', 'Forgot to clock out at the end of overtime', 'employee', 'Pending', NOW())");
$st->bind_param("is", $alex, $yesterday);
$st->execute();

// ---------- Paid hours through the real rules engine ----------
foreach ($days as $empID => $dates) {
    foreach (array_keys($dates) as $date) {
        recomputeDayHours($conn, (int) $empID, $date);
    }
}
foreach ($emp as $e) {
    reconcileClockStatus($conn, (int) $e['id']);
}

$conn->commit();

$punches = (int) $conn->query("SELECT COUNT(*) FROM timepunches")->fetch_column();
echo "✓ Demo data: " . count($emp) . " employees, 3 shifts, {$punches} punches, " . count($timeOff) . " time-off requests.\n";
echo "  Password for every demo login: " . DEMO_PASSWORD . " (see docs/DEMO.md)\n";
