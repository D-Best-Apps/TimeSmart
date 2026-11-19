<?php
// Admin home: one date range (default: the current pay period) driving three panels.
//   Who's In        - live status for today: badge, shift, status, last punch, absence
//   Hours Summary   - paid hours per employee for the range, split regular / OT / PTO / Sick
//   Exceptions      - late in, out early, missed punches and no-shows in the range
// Raw punches are only read here; nothing is changed.
require_once '../auth/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}
require_once __DIR__ . '/../functions/check_permission.php';
require_once __DIR__ . '/../functions/hours.php';
require_once __DIR__ . '/../functions/pay_rules.php';
require_once __DIR__ . '/../functions/holidays.php';
require_once __DIR__ . '/../functions/time_off_hours.php';
require_once __DIR__ . '/../functions/holiday_pay.php';
requirePermission('view_dashboard');

$canEdit = checkPermission('edit_timesheets');
$policy  = payPolicy($conn);
$today   = date('Y-m-d');
$nowSec  = time() - strtotime('today');

// ---------- Date range and office filter ----------
$validDate = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)
    && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4));
[$periodStart, $periodEnd] = payPeriodRange($today, $policy);
$start = $validDate($_GET['start'] ?? null) ? $_GET['start'] : $periodStart;
$end   = $validDate($_GET['end'] ?? null) ? $_GET['end'] : $periodEnd;
if ($start > $end) {
    [$start, $end] = [$end, $start];
}
// Keep the exceptions scan bounded: at most ~3 months at a time
$maxEnd = (new DateTime($start))->modify('+92 days')->format('Y-m-d');
if ($end > $maxEnd) {
    $end = $maxEnd;
}
$spanDays = (int) (new DateTime($start))->diff(new DateTime($end))->days + 1;
$isDefaultRange = $start === $periodStart && $end === $periodEnd;

$offices = [];
if ($res = $conn->query("SELECT OfficeName FROM Offices ORDER BY OfficeName")) {
    while ($r = $res->fetch_assoc()) {
        $offices[] = $r['OfficeName'];
    }
}
$office = in_array($_GET['office'] ?? '', $offices, true) ? $_GET['office'] : '';

$rangeUrl = function (string $s, string $e) use ($office): string {
    $q = ['start' => $s, 'end' => $e];
    if ($office !== '') {
        $q['office'] = $office;
    }
    return 'home.php?' . http_build_query($q);
};
$shiftBy = fn(string $date, int $days) => (new DateTime($date))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
$prevUrl = $rangeUrl($shiftBy($start, -$spanDays), $shiftBy($end, -$spanDays));
$nextUrl = $rangeUrl($shiftBy($start, $spanDays), $shiftBy($end, $spanDays));

// ---------- Employees, shifts, punches, time off ----------
$sql = "SELECT ID, FirstName, LastName, BadgeID, ClockStatus, Office, ScheduledStartTime, ShiftID
          FROM users" . ($office !== '' ? " WHERE Office = ?" : "") . "
         ORDER BY LastName, FirstName";
$stmt = $conn->prepare($sql);
if ($office !== '') {
    $stmt->bind_param("s", $office);
}
$stmt->execute();
$employees = [];
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $u) {
    $employees[(int) $u['ID']] = $u;
}
$stmt->close();
$empIDs = array_keys($employees);

try {
    $shifts = listShifts($conn, true);
} catch (mysqli_sql_exception $e) {
    $shifts = []; // shifts migration not applied yet
}

/** The employee's schedule: a shift, the legacy per-user start time, or nothing. */
$scheduleOf = function (array $u) use ($shifts): ?array {
    $shift = $u['ShiftID'] !== null ? ($shifts[(int) $u['ShiftID']] ?? null) : null;
    if ($shift) {
        [$s, $e] = shiftWindow($shift);
        return ['shift' => $shift, 'start' => $s, 'end' => $e,
                'lateGrace' => (int) $shift['LateInGraceMinutes'], 'earlyGrace' => (int) $shift['EarlyOutGraceMinutes']];
    }
    if (!empty($u['ScheduledStartTime'])) {
        return ['shift' => null, 'start' => hms_to_seconds($u['ScheduledStartTime']), 'end' => null,
                'lateGrace' => 0, 'earlyGrace' => 0];
    }
    return null;
};
/** Is the employee scheduled to work on $date? Shift days (or Mon-Fri), never on a company holiday. */
$scheduledOn = function (?array $sched, string $date): bool {
    if (!$sched || isCompanyHoliday($date) !== null) {
        return false;
    }
    return $sched['shift'] ? shiftRunsOn($sched['shift'], $date) : isWorkday($date);
};

// Punches in the range (plus today, for the live board), grouped [emp][date][]
$from = min($start, $today);
$to   = max($end, $today);
$punches = [];
$stmt = $conn->prepare("SELECT EmployeeID, Date, TimeIN, LunchStart, LunchEnd, TimeOut, TotalHours
                          FROM timepunches
                         WHERE Date BETWEEN ? AND ?
                         ORDER BY Date, TimeIN");
$stmt->bind_param("ss", $from, $to);
$stmt->execute();
$res = $stmt->get_result();
while ($p = $res->fetch_assoc()) {
    $eid = (int) $p['EmployeeID'];
    if (isset($employees[$eid])) {
        $punches[$eid][$p['Date']][] = $p;
    }
}
$stmt->close();

// Latest punch per employee (may be before the range) and first-ever punch date
// (no-shows are only counted from an employee's first day on the clock)
$lastPunch = [];
$firstDay  = [];
$res = $conn->query("
    SELECT EmployeeID, Date, TimeIN, LunchStart, LunchEnd, TimeOut, FirstDay FROM (
        SELECT EmployeeID, Date, TimeIN, LunchStart, LunchEnd, TimeOut,
               MIN(Date) OVER (PARTITION BY EmployeeID) AS FirstDay,
               ROW_NUMBER() OVER (PARTITION BY EmployeeID ORDER BY Date DESC, TimeIN DESC) AS rn
          FROM timepunches
         WHERE TimeIN IS NOT NULL
    ) t WHERE rn = 1");
while ($res && $p = $res->fetch_assoc()) {
    $eid = (int) $p['EmployeeID'];
    $lastPunch[$eid] = $p;
    $firstDay[$eid]  = $p['FirstDay'];
}

// Approved time off as [emp][date] => [Category, StartTime, EndTime, Hours]
$timeOffDays = [];
foreach (fetchApprovedTimeOff($conn, $from, $to) as $req) {
    $eid = (int) $req['EmployeeID'];
    if (!isset($employees[$eid])) {
        continue;
    }
    // Full-day requests only cover working days; for "is this person off today" also
    // count the calendar days they asked for, so a weekend shift isn't marked absent.
    $hasTime = !empty($req['StartTime']) && !empty($req['EndTime']);
    for ($d = max($req['StartDate'], $from); $d <= min($req['EndDate'], $to); $d = $shiftBy($d, 1)) {
        $timeOffDays[$eid][$d][] = [
            'Category'  => $req['Category'],
            'StartTime' => $hasTime ? $req['StartTime'] : null,
            'EndTime'   => $hasTime ? $req['EndTime'] : null,
        ];
    }
}
$timeOffTotals = timeOffTotalsByEmployee($conn, $start, $end);

// ---------- Formatting helpers ----------
$fmtTime = fn(?string $t) => $t ? date('g:i A', strtotime($t)) : '';
$fmtSec  = fn(int $s) => date('g:i A', strtotime('today') + ($s % 86400));
$fmtDate = fn(string $d) => date('D n/j', strtotime($d));
$fmtHrs  = fn(float $h) => number_format($h, 2);
$timeOffLabel = function (array $entries): string {
    $parts = [];
    foreach ($entries as $t) {
        $label = $t['Category'] === 'Sick' ? 'Sick' : 'PTO';
        if ($t['StartTime']) {
            $label .= ' ' . str_replace(':00', '', date('g:ia', strtotime($t['StartTime'])))
                    . '–' . str_replace(':00', '', date('g:ia', strtotime($t['EndTime'])));
        }
        $parts[] = $label;
    }
    return implode(', ', array_unique($parts));
};
$isFullDayOff = function (array $entries): bool {
    foreach ($entries as $t) {
        if (!$t['StartTime']) {
            return true;
        }
    }
    return false;
};
$punchLink = function (int $eid, string $s, string $e) use ($canEdit): string {
    return $canEdit
        ? 'view_punches.php?' . http_build_query(['from' => $s, 'to' => $e, 'emp' => $eid])
        : 'summary.php?' . http_build_query(['dates' => "$s - $e", 'emp' => $eid]);
};

// ---------- Panel 1: Who's In (today) ----------
$holidayToday = isCompanyHoliday($today);
$board = [];
$statusCounts = ['In' => 0, 'Lunch' => 0, 'Out' => 0, 'Absent' => 0, 'Off' => 0];
foreach ($employees as $eid => $u) {
    $sched    = $scheduleOf($u);
    $todayOff = $timeOffDays[$eid][$today] ?? [];
    $clock    = in_array($u['ClockStatus'], ['In', 'Lunch'], true) ? $u['ClockStatus'] : 'Out';
    $status   = $clock;
    if ($clock === 'Out') {
        if ($todayOff && $isFullDayOff($todayOff)) {
            $status = 'Off';
        } elseif ($scheduledOn($sched, $today) && empty($punches[$eid][$today]) && !$todayOff
                  && $nowSec >= $sched['start'] && isset($firstDay[$eid]) && $firstDay[$eid] <= $today) {
            $status = 'Absent';
        }
    }
    $statusCounts[$status]++;

    $lp = $lastPunch[$eid] ?? null;
    $lastText = '';
    $lastKind = '';
    if ($lp) {
        foreach (['TimeOut' => 'Out', 'LunchEnd' => 'Lunch in', 'LunchStart' => 'Lunch out', 'TimeIN' => 'In'] as $col => $kind) {
            if (!empty($lp[$col])) {
                $lastText = $fmtTime($lp[$col]);
                $lastKind = $kind;
                break;
            }
        }
        if ($lp['Date'] !== $today) {
            $lastKind .= ' · ' . date('n/j', strtotime($lp['Date']));
        }
    }

    $absence = $todayOff ? $timeOffLabel($todayOff) : '';
    if ($holidayToday !== null) {
        $absence = trim($absence . ($absence ? ', ' : '') . $holidayToday);
    }

    $board[] = [
        'id' => $eid, 'name' => $u['LastName'] . ', ' . $u['FirstName'],
        'badge' => $u['BadgeID'] ?? '', 'sched' => $sched, 'status' => $status,
        'last' => $lastText, 'lastKind' => $lastKind, 'absence' => $absence,
    ];
}
$statusLabels = ['In' => 'In', 'Lunch' => 'On lunch', 'Out' => 'Out', 'Absent' => 'Absent', 'Off' => 'Time off'];

// ---------- Panel 2: Hours Summary (range) ----------
$showHoliday = holidayPayPolicy($conn)['enabled'];
$holidayPay  = $showHoliday ? holidayPayByEmployee($conn, $start, $end) : [];
$hours = [];
$totals = ['regular' => 0.0, 'ot' => 0.0, 'pto' => 0.0, 'sick' => 0.0, 'holiday' => 0.0, 'total' => 0.0];
foreach ($employees as $eid => $u) {
    $daily = [];
    $open  = 0;
    foreach ($punches[$eid] ?? [] as $date => $rows) {
        if ($date < $start || $date > $end) {
            continue;
        }
        foreach ($rows as $r) {
            if ($r['TotalHours'] !== null) {
                $daily[$date] = ($daily[$date] ?? 0.0) + max(0.0, (float) $r['TotalHours']);
            } elseif (!empty($r['TimeIN'])) {
                $open++;
            }
        }
    }
    $ot   = weeklyOvertime($daily, $policy['otWeekly'], $policy['otDaily']);
    $pto  = (float) ($timeOffTotals[$eid]['PTO'] ?? 0);
    $sick = (float) ($timeOffTotals[$eid]['Sick'] ?? 0);
    $hol  = (float) ($holidayPay[$eid]['hours'] ?? 0);
    if (!$daily && !$open && $pto == 0 && $sick == 0 && $hol == 0) {
        continue;
    }
    $row = [
        'id' => $eid, 'name' => $u['LastName'] . ', ' . $u['FirstName'], 'open' => $open,
        'regular' => $ot['totalRegular'], 'ot' => $ot['totalOT'], 'pto' => $pto, 'sick' => $sick,
        'holiday' => $hol, 'total' => $ot['totalHours'] + $pto + $sick + $hol,
    ];
    foreach (['regular', 'ot', 'pto', 'sick', 'holiday', 'total'] as $k) {
        $totals[$k] += $row[$k];
    }
    $hours[] = $row;
}

// ---------- Panel 3: Exceptions (range, up to today) ----------
$exceptions = [];
$exTypes = [
    'late'    => 'Late in',
    'early'   => 'Out early',
    'missing' => 'Missed punch',
    'lunch'   => 'Lunch punch',
    'absent'  => 'No-show',
];
$exCounts = array_fill_keys(array_keys($exTypes), 0);
$addEx = function (int $eid, string $date, string $type, string $time, string $detail) use (&$exceptions, &$exCounts, $employees) {
    $u = $employees[$eid];
    $exceptions[] = ['id' => $eid, 'name' => $u['LastName'] . ', ' . $u['FirstName'],
                     'date' => $date, 'type' => $type, 'time' => $time, 'detail' => $detail];
    $exCounts[$type]++;
};
$lastDay = min($end, $today);
foreach ($employees as $eid => $u) {
    $sched = $scheduleOf($u);
    for ($date = $start; $date <= $lastDay; $date = $shiftBy($date, 1)) {
        $rows  = $punches[$eid][$date] ?? [];
        $off   = !empty($timeOffDays[$eid][$date]);
        $onDay = $scheduledOn($sched, $date);

        if (!$rows) {
            if ($onDay && !$off && isset($firstDay[$eid]) && $date >= $firstDay[$eid]
                && ($date < $today || $nowSec >= $sched['start'])) {
                $addEx($eid, $date, 'absent', $fmtSec($sched['start']), 'Scheduled, no punches or time off');
            }
            continue;
        }

        $firstIn = null;
        $lastOut = null;
        $allClosed = true;
        foreach ($rows as $r) {
            $in  = hms_to_seconds($r['TimeIN']);
            $out = hms_to_seconds($r['TimeOut']);
            if ($in !== null && ($firstIn === null || $in < $firstIn)) {
                $firstIn = $in;
            }
            if ($in !== null && $out === null) {
                $allClosed = false;
                // An open punch is only a problem once the day (or the shift) is over
                $overdue = $date < $today
                    || ($sched && $sched['end'] !== null && $onDay && $nowSec > $sched['end'] + 3600);
                if ($overdue) {
                    $addEx($eid, $date, 'missing', $fmtTime($r['TimeIN']), 'Clocked in, no clock-out');
                }
            }
            if ($out !== null) {
                if ($in !== null && $out < $in) {
                    $out += 86400; // worked past midnight
                }
                $lastOut = max($lastOut ?? 0, $out);
            }
            if (!empty($r['LunchStart']) && empty($r['LunchEnd']) && $out !== null) {
                $addEx($eid, $date, 'lunch', $fmtTime($r['LunchStart']), 'Lunch out, no lunch in');
            } elseif (empty($r['LunchStart']) && !empty($r['LunchEnd'])) {
                $addEx($eid, $date, 'lunch', $fmtTime($r['LunchEnd']), 'Lunch in, no lunch out');
            }
        }

        if (!$onDay || $off) {
            continue; // not scheduled, or on (partial) time off: no late/early flags
        }
        // Inside the shift's grace window is on time (it's paid as the shift start/end)
        if ($firstIn !== null && $firstIn - $sched['start'] >= 60 * max(1, $sched['lateGrace'])) {
            $mins = intdiv($firstIn - $sched['start'], 60);
            $addEx($eid, $date, 'late', $fmtSec($firstIn), "{$mins} min after " . $fmtSec($sched['start']));
        }
        if ($sched['end'] !== null && $allClosed && $lastOut !== null && $sched['end'] - $lastOut >= 60 * max(1, $sched['earlyGrace'])
            && ($date < $today || $nowSec >= $sched['end'])) {
            $mins = intdiv($sched['end'] - $lastOut, 60);
            $addEx($eid, $date, 'early', $fmtSec($lastOut), "{$mins} min before " . $fmtSec($sched['end']));
        }
    }
}
usort($exceptions, fn($a, $b) => [$b['date'], $a['name']] <=> [$a['date'], $b['name']]);

$pageTitle = "Home";
$extraCSS = ["../css/home.css?v=3"];
require_once 'header.php';
?>

<form class="home-toolbar" method="get" action="home.php">
    <a class="btn small" href="<?= htmlspecialchars($prevUrl) ?>" title="Previous <?= $spanDays ?> days">&#9664;</a>
    <label class="sr-only" for="hStart">From</label>
    <input type="date" id="hStart" name="start" value="<?= htmlspecialchars($start) ?>">
    <span>to</span>
    <label class="sr-only" for="hEnd">To</label>
    <input type="date" id="hEnd" name="end" value="<?= htmlspecialchars($end) ?>">
    <a class="btn small" href="<?= htmlspecialchars($nextUrl) ?>" title="Next <?= $spanDays ?> days">&#9654;</a>
    <?php if (count($offices) > 1): ?>
    <label class="sr-only" for="hOffice">Office</label>
    <select id="hOffice" name="office">
        <option value="">All offices</option>
        <?php foreach ($offices as $o): ?>
        <option value="<?= htmlspecialchars($o) ?>"<?= $o === $office ? ' selected' : '' ?>><?= htmlspecialchars($o) ?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <button type="submit" class="btn primary small">Show</button>
    <?php if (!$isDefaultRange): ?>
    <a class="btn secondary small" href="<?= htmlspecialchars($rangeUrl($periodStart, $periodEnd)) ?>">This pay period</a>
    <?php endif; ?>
    <span class="spacer"></span>
    <span class="home-updated">Updated <?= date('g:i A') ?></span>
</form>

<div class="home-board">

<section class="home-panel whos-in" aria-labelledby="whosInTitle">
    <div class="home-panel-head">
        <h2 id="whosInTitle">Who's In <small>today, <?= date('D n/j') ?></small></h2>
        <div class="chip-filter" data-target="whosInTable">
            <button type="button" class="chip active" data-filter="">All <?= count($board) ?></button>
            <?php foreach ($statusLabels as $key => $label): if ($statusCounts[$key] === 0) continue; ?>
            <button type="button" class="chip st-<?= strtolower($key) ?>" data-filter="<?= $key ?>"><?= $label ?> <?= $statusCounts[$key] ?></button>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="home-panel-body">
        <table id="whosInTable">
            <thead>
                <tr><th>Name</th><th>Badge</th><th>Shift</th><th>Status</th><th>Last punch</th><th>Absence</th></tr>
            </thead>
            <tbody>
            <?php foreach ($board as $b): ?>
                <tr data-row="<?= $b['status'] ?>">
                    <td><a href="<?= htmlspecialchars($punchLink($b['id'], $periodStart, $periodEnd)) ?>"><?= htmlspecialchars($b['name']) ?></a></td>
                    <td><?= htmlspecialchars($b['badge']) ?></td>
                    <td>
                        <?php if ($b['sched'] && $b['sched']['shift']): ?>
                            <?= shiftChip($b['sched']['shift']) ?> <span class="muted"><?= htmlspecialchars(shiftTimeRange($b['sched']['shift'])) ?></span>
                        <?php elseif ($b['sched']): ?>
                            <span class="muted">Starts <?= $fmtSec($b['sched']['start']) ?></span>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="st st-<?= strtolower($b['status']) ?>"><?= $statusLabels[$b['status']] ?></span></td>
                    <td><?= htmlspecialchars($b['last']) ?><?php if ($b['lastKind']): ?> <span class="muted"><?= htmlspecialchars($b['lastKind']) ?></span><?php endif; ?></td>
                    <td><?= htmlspecialchars($b['absence']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$board): ?>
                <tr><td colspan="6" class="muted">No employees.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="home-panel" aria-labelledby="hoursTitle">
    <div class="home-panel-head">
        <h2 id="hoursTitle">Hours Summary <small><?= date('n/j', strtotime($start)) ?>–<?= date('n/j/Y', strtotime($end)) ?></small></h2>
    </div>
    <div class="home-panel-body">
        <table>
            <thead>
                <tr><th>Employee</th><th class="num">Regular</th><th class="num">OT</th><th class="num">PTO</th><th class="num">Sick</th><?php if ($showHoliday): ?><th class="num">Holiday</th><?php endif; ?><th class="num">Total</th></tr>
            </thead>
            <tbody>
            <?php foreach ($hours as $h): ?>
                <tr>
                    <td>
                        <a href="<?= htmlspecialchars($punchLink($h['id'], $start, $end)) ?>"><?= htmlspecialchars($h['name']) ?></a>
                        <?php if ($h['open']): ?><span class="open-tag" title="Open punches are counted once the employee clocks out">+ open</span><?php endif; ?>
                    </td>
                    <td class="num"><?= $fmtHrs($h['regular']) ?></td>
                    <td class="num<?= $h['ot'] > 0 ? ' ot' : '' ?>"><?= $h['ot'] > 0 ? $fmtHrs($h['ot']) : '' ?></td>
                    <td class="num"><?= $h['pto'] > 0 ? $fmtHrs($h['pto']) : '' ?></td>
                    <td class="num"><?= $h['sick'] > 0 ? $fmtHrs($h['sick']) : '' ?></td>
                    <?php if ($showHoliday): ?><td class="num"><?= $h['holiday'] > 0 ? $fmtHrs($h['holiday']) : '' ?></td><?php endif; ?>
                    <td class="num strong"><?= $fmtHrs($h['total']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$hours): ?>
                <tr><td colspan="<?= $showHoliday ? 7 : 6 ?>" class="muted">No hours in this range.</td></tr>
            <?php endif; ?>
            </tbody>
            <?php if ($hours): ?>
            <tfoot>
                <tr>
                    <th>Total (<?= count($hours) ?>)</th>
                    <th class="num"><?= $fmtHrs($totals['regular']) ?></th>
                    <th class="num"><?= $fmtHrs($totals['ot']) ?></th>
                    <th class="num"><?= $fmtHrs($totals['pto']) ?></th>
                    <th class="num"><?= $fmtHrs($totals['sick']) ?></th>
                    <?php if ($showHoliday): ?><th class="num"><?= $fmtHrs($totals['holiday']) ?></th><?php endif; ?>
                    <th class="num"><?= $fmtHrs($totals['total']) ?></th>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
        <p class="home-note">Paid hours after shift rules. A shift still in progress counts once the employee clocks out (marked <span class="open-tag">+ open</span>).</p>
    </div>
</section>

<section class="home-panel" aria-labelledby="exTitle">
    <div class="home-panel-head">
        <h2 id="exTitle">Exceptions <small><?= date('n/j', strtotime($start)) ?>–<?= date('n/j/Y', strtotime($lastDay < $start ? $start : $lastDay)) ?></small></h2>
        <div class="chip-filter" data-target="exTable">
            <button type="button" class="chip active" data-filter="">All <?= count($exceptions) ?></button>
            <?php foreach ($exTypes as $key => $label): if ($exCounts[$key] === 0) continue; ?>
            <button type="button" class="chip ex-<?= $key ?>" data-filter="<?= $key ?>"><?= $label ?> <?= $exCounts[$key] ?></button>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="home-panel-body">
        <table id="exTable">
            <thead>
                <tr><th>Employee</th><th>Date</th><th>Exception</th><th>Time</th><th>Detail</th></tr>
            </thead>
            <tbody>
            <?php foreach ($exceptions as $x): ?>
                <tr data-row="<?= $x['type'] ?>">
                    <td><a href="<?= htmlspecialchars($punchLink($x['id'], $x['date'], $x['date'])) ?>"><?= htmlspecialchars($x['name']) ?></a></td>
                    <td><?= $fmtDate($x['date']) ?></td>
                    <td><span class="ex ex-<?= $x['type'] ?>"><?= $exTypes[$x['type']] ?></span></td>
                    <td><?= htmlspecialchars($x['time']) ?></td>
                    <td class="muted"><?= htmlspecialchars($x['detail']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$exceptions): ?>
                <tr><td colspan="5" class="muted">No exceptions in this range.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <p class="home-note">Late and early are measured against each employee's shift, past its grace minutes. Days on approved time off, holidays and unscheduled days are skipped.</p>
    </div>
</section>

</div>

<script>
// Chip filters: show only the rows whose data-row matches; the choice survives the auto-reload
document.querySelectorAll('.chip-filter').forEach(function (group) {
    var table = document.getElementById(group.dataset.target);
    var key = 'homeFilter:' + group.dataset.target;
    function apply(chip) {
        group.querySelectorAll('.chip').forEach(function (c) { c.classList.toggle('active', c === chip); });
        table.querySelectorAll('tbody tr[data-row]').forEach(function (tr) {
            tr.hidden = chip.dataset.filter !== '' && tr.dataset.row !== chip.dataset.filter;
        });
        try { sessionStorage.setItem(key, chip.dataset.filter); } catch (e) {}
    }
    group.addEventListener('click', function (e) {
        var chip = e.target.closest('.chip');
        if (chip) apply(chip);
    });
    var saved = null;
    try { saved = sessionStorage.getItem(key); } catch (e) {}
    if (saved) {
        var chip = group.querySelector('.chip[data-filter="' + saved + '"]');
        if (chip) apply(chip);
    }
});

<?php if ($end >= $today && $start <= $today): ?>
// Live board: reload after a minute without interaction (keeps the chosen range)
(function () {
    var t;
    function arm() { clearTimeout(t); t = setTimeout(function () { location.reload(); }, 60000); }
    ['mousedown', 'keydown', 'scroll', 'touchstart'].forEach(function (ev) { document.addEventListener(ev, arm, { passive: true }); });
    arm();
})();
<?php endif; ?>
</script>

<?php require_once 'footer.php'; ?>
