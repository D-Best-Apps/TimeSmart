<?php
// admin/payroll_totals.php — one line per employee for a pay period: regular,
// overtime, PTO, sick, holiday (when holiday pay is on) and total hours in decimal hours, for keying into payroll
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
require_once __DIR__ . '/../functions/holiday_pay.php';
require_once __DIR__ . '/../functions/payroll_totals.php'; // payrollTotals(), saved closes

require_once __DIR__ . '/../functions/csrf.php';

date_default_timezone_set('America/Chicago');

// "Close payroll": lock every punch dated on/before the period end and save the
// period's totals as closed (super admins)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'close') {
    require_csrf('payroll_totals.php');
    requirePermission('manage_pay_rules', 'payroll_totals.php');
    $isDate = fn($v) => is_string($v) && ($x = DateTime::createFromFormat('Y-m-d', $v)) && $x->format('Y-m-d') === $v;
    $through = (string) ($_POST['through'] ?? '');
    $from    = (string) ($_POST['start'] ?? '');
    // Only finished days: today's punches are still coming in, and live clock-outs are never blocked
    if ($isDate($through) && $through < date('Y-m-d')) {
        if (!$isDate($from) || $from > $through) {
            $from = payPeriodRange($through, payPolicy($conn))[0];
        }
        $current = payrollLockedThrough($conn);
        if ($current === null || $through > $current) {
            setPayrollLockedThrough($conn, $through);
            savePayrollClose($conn, $from, $through, payrollTotals($conn, $from, $through),
                isset($_SESSION['EmployeeID']) ? (int) $_SESSION['EmployeeID'] : null, (string) ($_SESSION['admin'] ?? ''));
        }
    }
    header('Location: payroll_totals.php?' . http_build_query(['period' => 'custom', 'start' => $from, 'end' => $through, 'closed' => 1]));
    exit;
}

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

// Closed periods show the totals saved at close (functions/payroll_totals.php explains
// why); ?live=1 shows today's figures instead.
$lockedThrough = payrollLockedThrough($conn);
$isClosed = $lockedThrough !== null && $end <= $lockedThrough;
$live  = payrollTotals($conn, $start, $end);
$saved = $isClosed ? loadPayrollClose($conn, $start, $end) : null;
$showLive = !$saved || isset($_GET['live']);
$data = $showLive ? $live : $saved;
$changes = $saved ? payrollCloseChanges($saved, $live) : [];
$rows        = array_values($data['rows']);
$grand       = $data['grand'];
$unresolved  = $data['unresolved'];
$showHoliday = $data['showHoliday'];
$colNames = ['regular' => 'Regular', 'ot' => 'Overtime', 'pto' => 'PTO', 'sick' => 'Sick', 'holiday' => 'Holiday', 'total' => 'Total'];
$changeText = function (int $eid) use ($changes, $colNames): string {
    $parts = [];
    foreach ($changes[$eid] ?? [] as $k => [$was, $now]) {
        $parts[] = $colNames[$k] . ': ' . number_format($was, 2) . ' at close, ' . number_format($now, 2) . ' now';
    }
    return implode(PHP_EOL, $parts);
};

// A range that cuts through a work week can't see that week's other hours, so its
// overtime may be understated.
$alignedStart = payPeriodStart($start) === $start;
$alignedEnd   = payPeriodEnd($end) === $end;
$periodLabel  = date('m/d/Y', strtotime($start)) . ' – ' . date('m/d/Y', strtotime($end));
// Hour columns in report order; Holiday only when holiday pay is on
$cols = ['regular' => 'Regular', 'ot' => 'Overtime', 'pto' => 'PTO', 'sick' => 'Sick'];
if ($showHoliday) {
    $cols['holiday'] = 'Holiday';
}
$cols['total'] = 'Total';
$headers = array_merge(['Employee'], array_values($cols));
$lastCol = chr(ord('A') + count($cols)); // spreadsheet column of Total

$format = $_GET['format'] ?? '';
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="payroll_totals_' . $start . '_to_' . $end . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    $csvRow = fn(string $label, array $r) => array_merge([$label], array_map(fn($k) => number_format($r[$k], 2, '.', ''), array_keys($cols)));
    foreach ($rows as $r) {
        fputcsv($out, $csvRow($r['name'], $r));
    }
    fputcsv($out, $csvRow('TOTAL', $grand));
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
    $sheet->getStyle("A3:{$lastCol}3")->getFont()->setBold(true);
    $xlsRow = fn(string $label, array $r) => array_merge([$label], array_map(fn($k) => round($r[$k], 2), array_keys($cols)));
    $n = 4;
    foreach ($rows as $r) {
        $sheet->fromArray($xlsRow($r['name'], $r), null, "A{$n}");
        $n++;
    }
    $sheet->fromArray($xlsRow('TOTAL', $grand), null, "A{$n}");
    $sheet->getStyle("A{$n}:{$lastCol}{$n}")->getFont()->setBold(true);
    $sheet->getStyle("B4:{$lastCol}{$n}")->getNumberFormat()->setFormatCode('0.00');
    foreach (range('A', $lastCol) as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="payroll_totals_' . $start . '_to_' . $end . '.xlsx"');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
    exit;
}

$canClose  = checkPermission('manage_pay_rules') && !$isClosed && $end < date('Y-m-d');

// Links keep the range, and the current-figures view when that's what's shown
$qs = fn(array $extra) => htmlspecialchars('payroll_totals.php?' . http_build_query(['period' => 'custom', 'start' => $start, 'end' => $end]
    + ($saved && $showLive ? ['live' => 1] : []) + $extra));
$qsView = fn(bool $live) => htmlspecialchars('payroll_totals.php?' . http_build_query(['period' => 'custom', 'start' => $start, 'end' => $end] + ($live ? ['live' => 1] : [])));

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

    <?php if (isset($_GET['closed']) && $isClosed): ?>
        <div class="alert alert-success">Payroll closed through <?= htmlspecialchars(date('m/d/Y', strtotime($lockedThrough))) ?>. Punches up to that date can no longer be edited, approved or recalculated.</div>
    <?php endif; ?>
    <?php if ($saved && !$showLive): ?>
        <div class="pt-warn" style="background:#eef3f6; border-color:#d5e0e8; color:inherit;">
            🔒 This period is closed. These are the totals as closed on <?= htmlspecialchars(date('m/d/Y g:i A', strtotime($saved['close']['ClosedAt']))) ?><?= ($saved['close']['ClosedByName'] ?? '') !== '' ? ' by ' . htmlspecialchars($saved['close']['ClosedByName']) : '' ?>; the Excel and CSV downloads match them.
        </div>
        <?php if ($changes): ?>
        <div class="pt-warn">
            <?= count($changes) ?> employee<?= count($changes) === 1 ? ' has' : 's have' ?> different figures now than when payroll was closed
            (<?= htmlspecialchars(implode('; ', array_map(fn($eid) => $saved['rows'][$eid]['name'] ?? $live['rows'][$eid]['name'] ?? ('#' . $eid), array_slice(array_keys($changes), 0, 10)))) ?><?= count($changes) > 10 ? '; …' : '' ?>),
            usually because a holiday or a holiday-pay setting changed since. They're marked ⚠ (hover for what changed).
            The closed totals are what was paid; <a href="<?= $qsView(true) ?>">see the current figures</a> to work out an adjustment.
        </div>
        <?php endif; ?>
    <?php elseif ($saved): ?>
        <div class="pt-warn">
            Showing <strong>current</strong> figures for a closed period<?= $changes ? '; ' . count($changes) . ' employee' . (count($changes) === 1 ? ' differs' : 's differ') . ' from the totals saved at close (marked ⚠)' : ', which match the totals saved at close' ?>.
            <a href="<?= $qsView(false) ?>">Back to the closed totals</a>
        </div>
    <?php elseif ($isClosed): ?>
        <div class="pt-warn" style="background:#eef3f6; border-color:#d5e0e8; color:inherit;">
            🔒 This period is closed (payroll closed through <?= htmlspecialchars(date('m/d/Y', strtotime($lockedThrough))) ?>), so its punches and time off can't change.
            These dates have no saved totals (closed as a different date range, or before totals were saved), so these are current figures:
            a later holiday or holiday-pay change can still move PTO, Sick and Holiday hours here.
        </div>
    <?php endif; ?>

    <?php if (!$alignedStart || !$alignedEnd): ?>
        <div class="pt-warn">These dates cut through a work week (weeks run <?= htmlspecialchars(workWeekLabel()) ?>). Overtime only counts hours inside the dates shown, so it may be understated.</div>
    <?php endif; ?>
    <?php if ($unresolved && !$showLive): ?>
        <div class="pt-warn"><?= array_sum($unresolved) ?> punch(es) were unfinished when payroll was closed and were paid at 0 hours. They're marked below.</div>
    <?php elseif ($unresolved): ?>
        <div class="pt-warn"><?= array_sum($unresolved) ?> punch(es) in this period aren't finished — still clocked in, or waiting on a missing lunch-end time — and are not counted yet. They're marked below; fix them on <a href="edits_timesheet.php">Pending Approvals</a> or the timesheet before running payroll.</div>
    <?php endif; ?>

    <?php if (!$rows): ?>
        <p>No hours or time off recorded for this period.</p>
    <?php else: ?>
    <table class="pt-table">
        <thead>
            <tr><th>Employee</th><th class="num">Regular</th><th class="num">Overtime</th><th class="num">PTO</th><th class="num">Sick</th><?php if ($showHoliday): ?><th class="num">Holiday</th><?php endif; ?><th class="num">Total</th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td>
                    <a href="view_punches.php?emp=<?= $r['id'] ?>&amp;from=<?= urlencode(date('m/d/Y', strtotime($start))) ?>&amp;to=<?= urlencode(date('m/d/Y', strtotime($end))) ?>"><?= htmlspecialchars($r['name']) ?></a>
                    <?php if ($r['review']): ?><span style="color:#b02a37; font-size:.85em;"> ⚠ <?= $r['review'] ?> unfinished</span><?php endif; ?>
                    <?php if (isset($changes[$r['id']])): ?><span style="color:#856404; font-size:.85em; cursor:help;" title="<?= htmlspecialchars($changeText($r['id'])) ?>"> ⚠ changed since close</span><?php endif; ?>
                </td>
                <td class="num"><?= number_format($r['regular'], 2) ?></td>
                <td class="num"><?= $r['ot'] > 0 ? '<strong>' . number_format($r['ot'], 2) . '</strong>' : '0.00' ?></td>
                <td class="num"><?= number_format($r['pto'], 2) ?></td>
                <td class="num"><?= number_format($r['sick'], 2) ?></td>
                <?php if ($showHoliday): ?>
                <td class="num" title="<?= htmlspecialchars(implode(PHP_EOL, array_map(fn($d, $h) => date('D n/j', strtotime($d)) . ' ' . $h['name'] . ': ' . number_format($h['hours'], 2) . (!empty($h['adjusted']) ? ' (set by hand' . (!empty($h['note']) ? ': ' . $h['note'] : '') . ')' : ''), array_keys($r['holidayDays']), $r['holidayDays']))) ?>"><?= number_format($r['holiday'], 2) ?></td>
                <?php endif; ?>
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
                <?php if ($showHoliday): ?><td class="num"><?= number_format($grand['holiday'], 2) ?></td><?php endif; ?>
                <td class="num"><?= number_format($grand['total'], 2) ?></td>
            </tr>
        </tfoot>
    </table>
    <p class="muted" style="color:var(--muted-text); font-size:.85rem; margin-top:.75rem;">
        Decimal hours. Overtime is hours over <?= overtimeWeeklyHours() ?> per <?= htmlspecialchars(workWeekLabel()) ?> week.
        Worked hours are paid hours after shift rounding and lunch rules. PTO and sick are approved time off and don't count toward overtime.
        <?php if ($showHoliday): ?>Holiday is holiday pay (<a href="holidays.php">Settings → Holidays</a>); <?= holidayPayPolicy($conn)['countsOT'] ? 'it counts toward the overtime threshold, so worked hours in a holiday week reach overtime sooner' : "it doesn't count toward overtime either" ?>. Hover a holiday figure for the days.<?php endif; ?>
    </p>
    <?php if ($canClose): ?>
    <form method="POST" style="margin-top:1rem;"
          onsubmit="return confirm(<?= htmlspecialchars(json_encode(($unresolved ? array_sum($unresolved) . ' punch(es) are still unfinished and will be left at 0 hours. ' : '') . 'Close payroll through ' . date('m/d/Y', strtotime($end)) . '? Punches up to that date will be locked.'), ENT_QUOTES) ?>)">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="close">
        <input type="hidden" name="start" value="<?= htmlspecialchars($start) ?>">
        <input type="hidden" name="through" value="<?= htmlspecialchars($end) ?>">
        <button type="submit" class="btn primary">🔒 Close payroll through <?= htmlspecialchars(date('m/d/Y', strtotime($end))) ?></button>
        <span style="color:var(--muted-text); font-size:.85rem; margin-left:.5rem;">After you've entered these totals in payroll. Locks the hours so they can't change afterwards.</span>
    </form>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>
