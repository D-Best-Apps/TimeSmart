<?php
// admin/payroll_totals.php — one line per employee for a pay period: regular,
// overtime, PTO, sick and total hours in decimal hours, for keying into payroll
// (QuickBooks has no integration; this is the hand-off). Paid hours come from
// timepunches.TotalHours, so shift rounding and auto-lunch are already applied.
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/../functions/check_permission.php';
requirePermission('view_reports');

require_once __DIR__ . '/../functions/hours.php';
require_once __DIR__ . '/../functions/pay_rules.php';
require_once __DIR__ . '/../functions/time_off_hours.php';

date_default_timezone_set('America/Chicago');

$policy = payPolicy($conn);
$okDate = fn($d) => is_string($d) && ($x = DateTime::createFromFormat('Y-m-d', $d)) && $x->format('Y-m-d') === $d;

// Period: this one, the last one (the usual payroll run), or a custom range
$period = $_GET['period'] ?? 'previous';
[$curStart, $curEnd] = payPeriodRange(date('Y-m-d'), $policy);
if ($period === 'current') {
    [$start, $end] = [$curStart, $curEnd];
} elseif ($period === 'custom' && $okDate($_GET['start'] ?? null) && $okDate($_GET['end'] ?? null) && $_GET['start'] <= $_GET['end']) {
    [$start, $end] = [$_GET['start'], $_GET['end']];
} else {
    $period = 'previous'; // also the fallback for a missing or invalid custom range
    [$start, $end] = payPeriodRange((new DateTime($curStart))->modify('-1 day')->format('Y-m-d'), $policy);
}

// Paid hours per employee per day
$stmt = $conn->prepare("
    SELECT tp.EmployeeID, tp.Date, SUM(GREATEST(COALESCE(tp.TotalHours, 0), 0)) AS Hours
      FROM timepunches tp
     WHERE tp.TimeIN IS NOT NULL AND tp.TimeOut IS NOT NULL
       AND tp.Date BETWEEN ? AND ?
     GROUP BY tp.EmployeeID, tp.Date
");
$stmt->bind_param("ss", $start, $end);
$stmt->execute();
$daily = [];
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    $daily[(int) $r['EmployeeID']][$r['Date']] = (float) $r['Hours'];
}
$stmt->close();

// Punches that can't be paid yet: still open, or clocked out with hours awaiting review
$stmt = $conn->prepare("
    SELECT EmployeeID, COUNT(*) AS n
      FROM timepunches
     WHERE Date BETWEEN ? AND ? AND TimeIN IS NOT NULL AND (TimeOut IS NULL OR TotalHours IS NULL)
     GROUP BY EmployeeID
");
$stmt->bind_param("ss", $start, $end);
$stmt->execute();
$unresolved = [];
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    $unresolved[(int) $r['EmployeeID']] = (int) $r['n'];
}
$stmt->close();

$timeOff = timeOffTotalsByEmployee($conn, $start, $end);

$rows = [];
$grand = ['regular' => 0.0, 'ot' => 0.0, 'pto' => 0.0, 'sick' => 0.0, 'total' => 0.0];
$users = $conn->query("SELECT ID, FirstName, LastName, ShiftID FROM users ORDER BY LastName, FirstName");
while ($u = $users->fetch_assoc()) {
    $id = (int) $u['ID'];
    if (!isset($daily[$id]) && !isset($timeOff[$id]) && !isset($unresolved[$id])) {
        continue; // nothing to pay this period
    }
    $ot   = weeklyOvertime($daily[$id] ?? []);
    $pto  = round($timeOff[$id]['PTO'] ?? 0.0, 2);
    $sick = round($timeOff[$id]['Sick'] ?? 0.0, 2);
    $row = [
        'name'    => $u['LastName'] . ', ' . $u['FirstName'],
        'regular' => $ot['totalRegular'],
        'ot'      => $ot['totalOT'],
        'pto'     => $pto,
        'sick'    => $sick,
        'total'   => round($ot['totalHours'] + $pto + $sick, 2),
        'review'  => $unresolved[$id] ?? 0,
        'id'      => $id,
    ];
    foreach (['regular', 'ot', 'pto', 'sick', 'total'] as $k) {
        $grand[$k] += $row[$k];
    }
    $rows[] = $row;
}

// A range that cuts through a work week can't see that week's other hours, so its
// overtime may be understated.
$alignedStart = payPeriodStart($start) === $start;
$alignedEnd   = payPeriodEnd($end) === $end;
$periodLabel  = date('m/d/Y', strtotime($start)) . ' – ' . date('m/d/Y', strtotime($end));
$headers = ['Employee', 'Regular', 'Overtime', 'PTO', 'Sick', 'Total'];

$format = $_GET['format'] ?? '';
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="payroll_totals_' . $start . '_to_' . $end . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    foreach ($rows as $r) {
        fputcsv($out, [$r['name'], number_format($r['regular'], 2, '.', ''), number_format($r['ot'], 2, '.', ''),
            number_format($r['pto'], 2, '.', ''), number_format($r['sick'], 2, '.', ''), number_format($r['total'], 2, '.', '')]);
    }
    fputcsv($out, ['TOTAL', number_format($grand['regular'], 2, '.', ''), number_format($grand['ot'], 2, '.', ''),
        number_format($grand['pto'], 2, '.', ''), number_format($grand['sick'], 2, '.', ''), number_format($grand['total'], 2, '.', '')]);
    fclose($out);
    exit;
}
if ($format === 'xlsx') {
    require_once '../vendor/autoload.php';
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Payroll Totals');
    $sheet->setCellValue('A1', 'Payroll Totals ' . $periodLabel);
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
    $sheet->fromArray($headers, null, 'A3');
    $sheet->getStyle('A3:F3')->getFont()->setBold(true);
    $n = 4;
    foreach ($rows as $r) {
        $sheet->fromArray([$r['name'], $r['regular'], $r['ot'], $r['pto'], $r['sick'], $r['total']], null, "A{$n}");
        $n++;
    }
    $sheet->fromArray(['TOTAL', round($grand['regular'], 2), round($grand['ot'], 2), round($grand['pto'], 2), round($grand['sick'], 2), round($grand['total'], 2)], null, "A{$n}");
    $sheet->getStyle("A{$n}:F{$n}")->getFont()->setBold(true);
    $sheet->getStyle("B4:F{$n}")->getNumberFormat()->setFormatCode('0.00');
    foreach (range('A', 'F') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="payroll_totals_' . $start . '_to_' . $end . '.xlsx"');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
    exit;
}

$qs = fn(array $extra) => htmlspecialchars('payroll_totals.php?' . http_build_query(['period' => 'custom', 'start' => $start, 'end' => $end] + $extra));

$pageTitle = "Payroll Totals";
require_once 'header.php';
?>
<style>
    .pt-table { width:100%; border-collapse:collapse; }
    .pt-table th, .pt-table td { padding:.55rem .75rem; border-bottom:1px solid #e3e8ec; }
    .pt-table th { text-align:left; background:#f4f6f8; }
    .pt-table td.num, .pt-table th.num { text-align:right; font-variant-numeric: tabular-nums; }
    .pt-table tfoot td { font-weight:700; border-top:2px solid #c9d2d9; }
    .pt-filter { display:flex; flex-wrap:wrap; gap:.75rem; align-items:end; margin-bottom:1rem; }
    .pt-filter label { display:flex; flex-direction:column; font-size:.9rem; }
    .pt-filter input, .pt-filter select { width:auto; }
    .pt-warn { background:#fff3cd; border:1px solid #ffeeba; color:#856404; padding:.6rem .9rem; border-radius:6px; margin-bottom:1rem; }
</style>

<div class="uman-container">
    <div class="uman-header">
        <h2>Payroll Totals <span style="font-weight:normal; font-size:1rem;"><?= htmlspecialchars($periodLabel) ?></span></h2>
        <div>
            <a class="btn secondary" href="<?= $qs(['format' => 'xlsx']) ?>">Excel</a>
            <a class="btn secondary" href="<?= $qs(['format' => 'csv']) ?>">CSV</a>
            <button type="button" class="btn secondary" onclick="window.print()">Print</button>
        </div>
    </div>

    <form method="GET" class="pt-filter">
        <label>Period
            <select name="period" onchange="document.getElementById('ptCustom').style.display = this.value === 'custom' ? 'flex' : 'none'">
                <option value="previous" <?= $period === 'previous' ? 'selected' : '' ?>>Last pay period</option>
                <option value="current" <?= $period === 'current' ? 'selected' : '' ?>>This pay period</option>
                <option value="custom" <?= $period === 'custom' ? 'selected' : '' ?>>Pick dates</option>
            </select>
        </label>
        <span id="ptCustom" style="display:<?= $period === 'custom' ? 'flex' : 'none' ?>; gap:.75rem;">
            <label>From <input type="date" name="start" value="<?= htmlspecialchars($start) ?>"></label>
            <label>To <input type="date" name="end" value="<?= htmlspecialchars($end) ?>"></label>
        </span>
        <button type="submit" class="btn primary">Show</button>
    </form>

    <?php if (!$alignedStart || !$alignedEnd): ?>
        <div class="pt-warn">These dates cut through a work week (weeks run <?= htmlspecialchars(workWeekLabel()) ?>). Overtime only counts hours inside the dates shown, so it may be understated.</div>
    <?php endif; ?>
    <?php if ($unresolved): ?>
        <div class="pt-warn"><?= array_sum($unresolved) ?> punch(es) in this period aren't finished — still clocked in, or waiting on a missing lunch-end time — and are not counted yet. They're marked below; fix them on <a href="edits_timesheet.php">Pending Approvals</a> or the timesheet before running payroll.</div>
    <?php endif; ?>

    <?php if (!$rows): ?>
        <p>No hours or time off recorded for this period.</p>
    <?php else: ?>
    <table class="pt-table">
        <thead>
            <tr><th>Employee</th><th class="num">Regular</th><th class="num">Overtime</th><th class="num">PTO</th><th class="num">Sick</th><th class="num">Total</th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td>
                    <a href="view_punches.php?emp=<?= $r['id'] ?>&amp;from=<?= urlencode(date('m/d/Y', strtotime($start))) ?>&amp;to=<?= urlencode(date('m/d/Y', strtotime($end))) ?>"><?= htmlspecialchars($r['name']) ?></a>
                    <?php if ($r['review']): ?><span style="color:#b02a37; font-size:.85em;"> ⚠ <?= $r['review'] ?> unfinished</span><?php endif; ?>
                </td>
                <td class="num"><?= number_format($r['regular'], 2) ?></td>
                <td class="num"><?= $r['ot'] > 0 ? '<strong>' . number_format($r['ot'], 2) . '</strong>' : '0.00' ?></td>
                <td class="num"><?= number_format($r['pto'], 2) ?></td>
                <td class="num"><?= number_format($r['sick'], 2) ?></td>
                <td class="num"><strong><?= number_format($r['total'], 2) ?></strong></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td>Total (<?= count($rows) ?> employees)</td>
                <td class="num"><?= number_format($grand['regular'], 2) ?></td>
                <td class="num"><?= number_format($grand['ot'], 2) ?></td>
                <td class="num"><?= number_format($grand['pto'], 2) ?></td>
                <td class="num"><?= number_format($grand['sick'], 2) ?></td>
                <td class="num"><?= number_format($grand['total'], 2) ?></td>
            </tr>
        </tfoot>
    </table>
    <p class="muted" style="color:var(--muted-text); font-size:.85rem; margin-top:.75rem;">
        Decimal hours. Overtime is hours over <?= overtimeWeeklyHours() ?> per <?= htmlspecialchars(workWeekLabel()) ?> week.
        Worked hours are paid hours after shift rounding and lunch rules. PTO and sick are approved time off and don't count toward overtime.
    </p>
    <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>
