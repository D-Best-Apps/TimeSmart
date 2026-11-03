<?php
/**
 * Demo data for a fresh TimeSmart database: offices, three shifts, a dozen
 * employees with badges and PINs, ~3 weeks of punches ending today (late
 * arrivals, overtime, a no-show), time off and timesheet change requests in
 * every status, past admin corrections with reasons, and last-but-one week's
 * payroll closed.
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
    // Casey's and Quinn's badge numbers are the two physical demo badges (what the reader sends)
    ['Casey',  'Nguyen',   'employee', 'Plant', '1st',  '0024986116', '1005', 'Quality Tech'],
    ['Jordan', 'Patel',    'employee', 'Plant', '1st',  '100006', '1006', 'Forklift Operator'],
    ['Riley',  'Johnson',  'employee', 'Plant', '2nd',  '100007', '1007', 'Machine Operator'],
    ['Avery',  'Kim',      'employee', 'Plant', '2nd',  '100008', '1008', 'Assembler'],
    ['Quinn',  'Martinez', 'employee', 'Plant', '2nd',  '0022479364', '1009', 'Maintenance'],
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
    // [employee index, category, start, end, status, notes, review note, partial-day start/end]
    [4,  'Sick', $d($monday, '-7 days'),  $d($monday, '-6 days'),  'Approved',  'Flu', null],
    [2,  'PTO',  $d($monday, '-14 days'), $d($monday, '-14 days'), 'Approved',  'Long weekend', 'Enjoy!'],
    [6,  'PTO',  $d($monday, '-11 days'), $d($monday, '-11 days'), 'Approved',  'School conference', null, '12:00:00', '15:30:00'],
    [10, 'Sick', $d($monday, '-4 days'),  $d($monday, '-4 days'),  'Approved',  'Stomach bug', 'Feel better'],
    [3,  'PTO',  $d($monday, '+7 days'),  $d($monday, '+8 days'),  'Approved',  'Family trip', null],
    [5,  'PTO',  $d($monday, '+14 days'), $d($monday, '+18 days'), 'Pending',   'Vacation week', null],
    [11, 'PTO',  $d($monday, '+4 days'),  $d($monday, '+4 days'),  'Pending',   'Moving to a new apartment', null],
    [9,  'PTO',  $d($monday, '+21 days'), $d($monday, '+22 days'), 'Pending',   'Brother\'s wedding, out of state', null],
    [7,  'PTO',  $d($monday, '+14 days'), $d($monday, '+15 days'), 'Rejected',  'Deer season opener', 'Two people are already off that week and we need you on the forklift. Can you take the week after?'],
    [6,  'PTO',  $d($monday, '+28 days'), $d($monday, '+29 days'), 'Withdrawn', 'Concert trip', null],
];
$off = [];
$partialOff = [];  // [employee ID][date] => time the employee left for a partial day off
$st = $conn->prepare("INSERT INTO time_off_requests (EmployeeID, Category, StartDate, EndDate, StartTime, EndTime, Notes, Status, SubmittedAt, ReviewedAt, ReviewedBy, ReviewNote) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
foreach ($timeOff as $row) {
    [$i, $cat, $s, $e, $status, $notes, $reviewNote] = $row;
    $startTime = $row[7] ?? null;
    $endTime   = $row[8] ?? null;
    $empID     = $emp[$i]['id'];
    // Sick days are called in that morning; PTO is asked for ~10 days ahead (never in the future)
    $asked     = $cat === 'Sick' ? new DateTimeImmutable($s) : min(new DateTimeImmutable($s . ' -10 days'), $today->modify('-1 day'));
    $submitted = $asked->format('Y-m-d') . ($cat === 'Sick' ? ' 06:10:00' : ' 09:15:00');
    $decided   = in_array($status, ['Approved', 'Rejected'], true);
    $reviewed  = $decided ? ($cat === 'Sick' ? $asked->format('Y-m-d') . ' 07:30:00' : min($asked->modify('+1 day'), $today)->format('Y-m-d') . ' 10:00:00') : null;
    $by        = $decided ? 'Pat Admin' : null;
    $st->bind_param("isssssssssss", $empID, $cat, $s, $e, $startTime, $endTime, $notes, $status, $submitted, $reviewed, $by, $reviewNote);
    $st->execute();
    if ($status !== 'Approved') continue;
    for ($x = new DateTimeImmutable($s); $x <= new DateTimeImmutable($e); $x = $x->modify('+1 day')) {
        if ($startTime !== null) {
            $partialOff[$empID][$x->format('Y-m-d')] = $startTime;
        } else {
            $off[$empID][$x->format('Y-m-d')] = true;
        }
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
$latePct  = [5 => 35, 8 => 35];         // Taylor and Riley are often late (Tardies report)
$stayLate = [2 => 70, 7 => 70];         // Alex and Jordan stay late most days (Overtime report)
$noShow   = [$emp[11]['id'] => $d($monday, '-12 days')]; // Sam: no punches, no time off (Missed Days)
$start = $today->modify('-20 days');
for ($day = $start; $day <= $today; $day = $day->modify('+1 day')) {
    $date = $day->format('Y-m-d');
    $isToday = $date === $today->format('Y-m-d');
    // Jordan does the Saturday morning inventory count
    if ($day->format('N') === '6' && !$isToday) {
        $jordan = $emp[7]['id'];
        $tIn = $hm(6 * 3600 - $rand(0, 8) * 60 + $rand(0, 59));
        $tOut = $hm(11 * 3600 + $rand(0, 20) * 60 + $rand(0, 59));
        $null = null;
        $raw = calculateTotalHours($tIn, null, null, $tOut);
        $ins->bind_param("isssssd", $jordan, $date, $tIn, $null, $null, $tOut, $raw);
        $ins->execute();
        $days[$jordan][$date] = true;
    }
    if ((int) $day->format('N') >= 6) continue; // otherwise weekdays only
    foreach ($emp as $idx => $e) {
        if (!empty($off[$e['id']][$date])) continue;
        if (($noShow[$e['id']] ?? null) === $date) continue;
        if (!$isToday && $rand(1, 100) <= 3) continue; // the odd absence
        [$S, $E] = $sched[$e['shift']];
        if ($isToday && $nowSec < $S - 900) continue;  // shift hasn't started yet

        // Clock in: mostly a few minutes early, sometimes late
        $in = $S + ($rand(1, 100) <= ($latePct[$idx] ?? 12) ? $rand(6, 22) * 60 : -$rand(1, 14) * 60) + $rand(0, 59);
        // Clock out: around the shift end, sometimes staying late
        $out = isset($stayLate[$idx])
            ? $E + ($rand(1, 100) <= $stayLate[$idx] ? $rand(45, 110) * 60 : $rand(-4, 11) * 60) + $rand(0, 59)
            : $E + ($rand(1, 100) <= 10 ? $rand(40, 95) * 60 : $rand(-4, 11) * 60) + $rand(0, 59);
        // Partial day off: leaves at the time-off start
        if (isset($partialOff[$e['id']][$date])) {
            [$h, $m] = explode(':', $partialOff[$e['id']][$date]);
            $out = (int) $h * 3600 + (int) $m * 60 - $rand(0, 5) * 60 + $rand(0, 59);
        }
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

// ---------- Corrections an admin already made (Punch History / audit trail) ----------
// Each one changes the punch and logs it the way admin/save_punches.php does:
// the field with its old and new value and the reason, then the recomputed paid hours.
$punchRow = function (int $empID, string $date) use ($conn): ?array {
    $q = $conn->prepare("SELECT * FROM timepunches WHERE EmployeeID = ? AND Date = ? ORDER BY TimeIN LIMIT 1");
    $q->bind_param("is", $empID, $date);
    $q->execute();
    return $q->get_result()->fetch_assoc() ?: null;
};
$log = $conn->prepare("INSERT INTO punch_changelog (EmployeeID, Date, ChangedBy, ChangeTime, FieldChanged, OldValue, NewValue, Reason) VALUES (?, ?, 'Pat Admin', ?, ?, ?, ?, ?)");
$changeTime = function (string $date) use ($today): string {
    $when = min((new DateTimeImmutable($date))->modify('+1 weekday'), $today);
    return $when->format('Y-m-d') . ' 08:' . sprintf('%02d', mt_rand(5, 55)) . ':00';
};
$adjustments = [
    // [employee index, date, field, new value (null = keep, just log), reason, what the punch said before]
    [3,  $d($monday, '-13 days'), 'TimeIN',          '07:00:00', 'Badge reader at the east door was down; lead confirmed start time', ['TimeIN' => '07:26:00']],
    [4,  $d($monday, '-11 days'), 'LunchWaived',     '1',        'Worked through lunch: press 4 down, supervisor approved', []],
    [11, $d($monday, '-11 days'), 'LunchEnd',        null,       'Forgot to punch back in from lunch', []],
    [7,  $d($monday, '-9 days'),  'CREATED',         null,       'Saturday inventory count, added from the sign-in sheet', []],
    [8,  $d($monday, '-7 days'),  'TimeOut',         '00:00:00', 'Clocked out early by mistake, kept working to end of shift', ['TimeOut' => '22:31:00']],
    [2,  $d($monday, '-6 days'),  'TimeOut',         '17:30:00', 'Stayed for the press changeover after clocking out', ['TimeOut' => '15:33:00']],
    [9,  $d($monday, '-6 days'),  'CREATED',         null,       'Forgot badge, worked full shift (verified by lead)', []],
    // Quinn stayed 50 minutes past the shift, which 2nd shift doesn't pay unless approved
    [10, $d($monday, '-5 days'),  'OutsideApproved', '1',        'Stayed to finish the mold change: overtime approved', ['TimeOut' => '00:50:00']],
    [6,  $d($monday, '-3 days'),  'TimeIN',          '06:58:00', 'Forgot to clock in before the safety meeting', ['TimeIN' => '07:41:00']],
    [11, $d($monday, '-3 days'),  'DELETED',         null,       'Duplicate punch: badge scanned twice', []],
];
$adjusted = 0;
foreach ($adjustments as [$i, $date, $field, $new, $reason, $prior]) {
    $empID = $emp[$i]['id'];
    $row = $punchRow($empID, $date);
    if (!$row || $row['TimeOut'] === null) continue; // the odd absence landed on this day
    foreach ($prior as $f => $v) {
        $set = $conn->prepare("UPDATE timepunches SET `{$f}` = ? WHERE id = ?");
        $set->bind_param("si", $v, $row['id']);
        $set->execute();
    }
    $row = $punchRow($empID, $date);
    recomputeDayHours($conn, $empID, $date);
    $before = $punchRow($empID, $date)['TotalHours'];
    $when = $changeTime($date);

    if ($field === 'CREATED') {
        [$old, $newLog] = ['NULL', 'Punch ID: ' . $row['id']];
    } elseif ($field === 'DELETED') {
        // The duplicate was a second, zero-length punch right after clock-in
        $dup = $row;
        unset($dup['id']);
        $dup['TimeOut'] = $dup['TimeIN'];
        $dup['TotalHours'] = '0.00';
        [$old, $newLog] = [json_encode($dup), 'NULL'];
    } elseif ($new === null) {
        // The punch already has the corrected time; the log shows it was entered by an admin
        [$old, $newLog] = [null, $row[$field]];
    } else {
        $old = (string) $row[$field];
        $newLog = $new;
        $set = $conn->prepare("UPDATE timepunches SET `{$field}` = ? WHERE id = ?");
        $set->bind_param("si", $new, $row['id']);
        $set->execute();
    }
    $log->bind_param("issssss", $empID, $date, $when, $field, $old, $newLog, $reason);
    $log->execute();

    recomputeDayHours($conn, $empID, $date);
    $after = $punchRow($empID, $date)['TotalHours'];
    if ((string) $before !== (string) $after && !in_array($field, ['CREATED', 'DELETED'], true)) {
        $f = 'TotalHours';
        $why = 'Recomputed after edit';
        $log->bind_param("issssss", $empID, $date, $when, $f, $before, $after, $why);
        $log->execute();
    }
    $adjusted++;
}

// ---------- Timesheet change requests from employees ----------
// Pending ones are on Pending Approvals; decided ones show on the employee's dashboard.
$yesterday = $today->modify('-1 weekday')->format('Y-m-d');
$requests = [
    // [employee index, date, [field => requested value], note, reason, status, private note, what the punch says]
    [2,  $yesterday,              ['TimeOut' => '16:45:00'], 'Clocked out, then stayed to finish the press changeover', 'Forgot to clock back in after the changeover', 'Pending', null, ['TimeOut' => '15:31:00']],
    [3,  $d($monday, '-4 days'),  ['TimeIN' => '06:50:00'], 'Here at 6:50 for the line meeting; badge did not read until later', 'Wrong clock-in time', 'Pending', null, ['TimeIN' => '07:14:00']],
    [9,  $d($monday, '-3 days'),  ['LunchStart' => '20:30:00', 'LunchEnd' => '21:00:00'], 'Line was down so I took lunch late', 'Wrong lunch times', 'Pending', null, []],
    [11, $d($monday, '-5 days'),  ['TimeOut' => '17:20:00'], 'Late truck, stayed to load it', 'Forgot to clock out', 'Pending', null, ['TimeOut' => '16:31:00']],
    [5,  $d($monday, '-10 days'), ['TimeIN' => '07:00:00'], 'Was here at 7, the line at the clock was long', 'Wrong clock-in time', 'Approved', null, ['TimeIN' => '07:09:00']],
    [10, $d($monday, '-11 days'), ['TimeOut' => '01:00:00'], 'Stayed late to clean up', 'Forgot to clock out', 'Rejected', 'Door log shows Quinn left at 12:10', []],
    // A note alone needs no approval: it is saved to the punch and logged as the employee's
    [4,  $monday->format('Y-m-d'), [], 'Left 30 minutes early for a dentist appointment, cleared with my lead', null, null, null, []],
    [6,  $d($monday, '-6 days'),  [], 'Covered for Jamie at the inspection station', null, null, null, []],
];
$st = $conn->prepare("INSERT INTO pending_edits (EmployeeID, Date, TimeIN, LunchStart, LunchEnd, TimeOut, Note, Reason, Source, Status, SubmittedAt, ReviewedAt, ReviewedBy, AdminPrivateNote) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'employee', ?, ?, ?, ?, ?)");
$selfNote = $conn->prepare("INSERT INTO punch_changelog (EmployeeID, Date, ChangedBy, ChangeTime, FieldChanged, OldValue, NewValue, Reason) VALUES (?, ?, 'Employee (self note)', ?, 'Note', '', ?, 'Employee note — no approval required')");
foreach ($requests as [$i, $date, $times, $note, $reason, $status, $private, $prior]) {
    $empID = $emp[$i]['id'];
    $row = $punchRow($empID, $date);
    if (!$row || $row['TimeOut'] === null) continue;
    foreach ($prior as $f => $v) {
        $set = $conn->prepare("UPDATE timepunches SET `{$f}` = ? WHERE id = ?");
        $set->bind_param("si", $v, $row['id']);
        $set->execute();
    }
    if (!$times) {
        $set = $conn->prepare("UPDATE timepunches SET Note = ? WHERE id = ?");
        $set->bind_param("si", $note, $row['id']);
        $set->execute();
        $when = $date . ' 15:4' . mt_rand(0, 9) . ':00';
        $selfNote->bind_param("isss", $empID, $date, $when, $note);
        $selfNote->execute();
        continue;
    }
    $tIn = $times['TimeIN'] ?? null;
    $tLs = $times['LunchStart'] ?? null;
    $tLe = $times['LunchEnd'] ?? null;
    $tOut = $times['TimeOut'] ?? null;
    $submitted = min((new DateTimeImmutable($date))->modify('+1 day'), new DateTimeImmutable('now'))->format('Y-m-d') . ' 07:4' . mt_rand(0, 9) . ':00';
    $decided = $status !== 'Pending';
    $reviewed = $decided ? (new DateTimeImmutable($submitted))->modify('+3 hours')->format('Y-m-d H:i:s') : null;
    $by = $decided ? 'Pat Admin' : null;
    $st->bind_param("issssssssssss", $empID, $date, $tIn, $tLs, $tLe, $tOut, $note, $reason, $status, $submitted, $reviewed, $by, $private);
    $st->execute();
    if ($status === 'Approved') {
        // What admin/process_edits.php does on approval: write the requested values
        foreach ($times as $field => $value) {
            $set = $conn->prepare("UPDATE timepunches SET `{$field}` = ? WHERE EmployeeID = ? AND Date = ?");
            $set->bind_param("sis", $value, $empID, $date);
            $set->execute();
        }
        $set = $conn->prepare("UPDATE timepunches SET Note = ? WHERE EmployeeID = ? AND Date = ?");
        $set->bind_param("sis", $note, $empID, $date);
        $set->execute();
    }
}

// ---------- Paid hours through the real rules engine ----------
foreach ($days as $empID => $dates) {
    foreach (array_keys($dates) as $date) {
        recomputeDayHours($conn, (int) $empID, $date);
    }
}
foreach ($emp as $e) {
    reconcileClockStatus($conn, (int) $e['id']);
}

// The week before last is paid and closed; last week is still open for review
setPayrollLockedThrough($conn, $d($monday, '-8 days'));

$conn->commit();

$punches = (int) $conn->query("SELECT COUNT(*) FROM timepunches")->fetch_column();
$edits = (int) $conn->query("SELECT COUNT(*) FROM pending_edits")->fetch_column();
echo "✓ Demo data: " . count($emp) . " employees, 3 shifts, {$punches} punches, " . count($timeOff) . " time-off requests,\n";
echo "  {$edits} timesheet change requests, {$adjusted} admin corrections; payroll closed through " . $d($monday, '-8 days') . ".\n";
echo "  Password for every demo login: " . DEMO_PASSWORD . " (see docs/DEMO.md)\n";
