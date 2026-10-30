<?php
// admin/rounding_audit.php — actual vs paid time per employee, so the company can
// show its rounding is fair. US rules (FLSA, 29 CFR 785.48) allow rounding only if,
// over time, it doesn't consistently cut employees' pay; a neutral policy nets out
// close to zero across everyone. Auto-deducted lunch is a separate policy, shown in
// its own column and kept out of the rounding figure.
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

date_default_timezone_set('America/Chicago');

$okDate = fn($d) => is_string($d) && ($x = DateTime::createFromFormat('Y-m-d', $d)) && $x->format('Y-m-d') === $d;
if ($okDate($_GET['start'] ?? null) && $okDate($_GET['end'] ?? null) && $_GET['start'] <= $_GET['end']) {
    [$start, $end] = [$_GET['start'], $_GET['end']];
} else {
    // Default: the last 90 days — rounding fairness is judged over time, not one week
    [$start, $end] = [date('Y-m-d', strtotime('-90 days')), date('Y-m-d')];
}

$shifts = listShifts($conn, true);
$stmt = $conn->prepare("
    SELECT tp.EmployeeID, tp.Date, tp.LunchStart, tp.LunchEnd, tp.LunchDeductMin, tp.ShiftID,
           tp.RawHours, tp.TotalHours, u.FirstName, u.LastName
      FROM timepunches tp
      JOIN users u ON u.ID = tp.EmployeeID
     WHERE tp.Date BETWEEN ? AND ?
       AND tp.TimeIN IS NOT NULL AND tp.TimeOut IS NOT NULL
       AND tp.RawHours IS NOT NULL AND tp.TotalHours IS NOT NULL
     ORDER BY u.LastName, u.FirstName
");
$stmt->bind_param("ss", $start, $end);
$stmt->execute();
$punches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$blank = ['punches' => 0, 'actual' => 0.0, 'paid' => 0.0, 'lunch' => 0.0, 'net' => 0.0,
          'upN' => 0, 'upMin' => 0.0, 'downN' => 0, 'downMin' => 0.0];
$byEmp = [];
$grand = $blank;
foreach ($punches as $p) {
    $id = (int) $p['EmployeeID'];
    $byEmp[$id] ??= $blank + ['name' => $p['LastName'] . ', ' . $p['FirstName'], 'id' => $id];

    // Minutes taken out beyond what was punched, on an auto-lunch shift = auto lunch
    $auto = 0.0;
    $shift = $shifts[(int) $p['ShiftID']] ?? null;
    if ($shift && $shift['LunchMode'] !== 'punched') {
        // The punched lunch as the engine counted it (rounded like every punch), so the
        // rounding of lunch punches stays in "Rounding net" and only the top-up is auto lunch
        $punched = 0;
        if ($p['LunchStart'] && $p['LunchEnd']) {
            $inc  = (int) $shift['RoundIncrement'];
            $mode = (string) $shift['RoundMode'];
            $punched = max(0, intdiv(
                roundToIncrement(hms_to_seconds($p['LunchEnd']), $inc, $mode)
                - roundToIncrement(hms_to_seconds($p['LunchStart']), $inc, $mode), 60));
        }
        $auto = max(0, (int) $p['LunchDeductMin'] - $punched) / 60;
    }
    $actual = (float) $p['RawHours'];
    $paid   = (float) $p['TotalHours'];
    $net    = round($paid - $actual + $auto, 2); // + = employee paid more than worked

    $add = function (array &$t) use ($actual, $paid, $auto, $net): void {
        $t['punches']++;
        $t['actual'] += $actual;
        $t['paid']   += $paid;
        $t['lunch']  += $auto;
        $t['net']    += $net;
        if ($net > 0.004)  { $t['upN']++;   $t['upMin']   += $net * 60; }
        if ($net < -0.004) { $t['downN']++; $t['downMin'] += -$net * 60; }
    };
    $add($byEmp[$id]);
    $add($grand);
}

$fmtNet = fn(float $h) => ($h > 0.004 ? '+' : ($h < -0.004 ? '−' : '')) . number_format(abs($h), 2);

if (($_GET['format'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="rounding_audit_' . $start . '_to_' . $end . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Employee', 'Punches', 'Actual hours', 'Paid hours', 'Auto lunch hours', 'Rounding net hours',
                   'Punches rounded in employee favor', 'Minutes', 'Punches rounded against employee', 'Minutes']);
    foreach (array_merge(array_values($byEmp), [$grand + ['name' => 'TOTAL']]) as $r) {
        fputcsv($out, [$r['name'], $r['punches'], number_format($r['actual'], 2, '.', ''), number_format($r['paid'], 2, '.', ''),
            number_format($r['lunch'], 2, '.', ''), number_format($r['net'], 2, '.', ''),
            $r['upN'], (int) round($r['upMin']), $r['downN'], (int) round($r['downMin'])]);
    }
    fclose($out);
    exit;
}

$pageTitle = "Rounding Audit";
require_once 'header.php';
?>
<style>
    .ra-table { width:100%; border-collapse:collapse; }
    .ra-table th, .ra-table td { padding:.5rem .7rem; border-bottom:1px solid #e3e8ec; }
    .ra-table th { text-align:left; background:#f4f6f8; font-size:.9rem; }
    .ra-table .num { text-align:right; font-variant-numeric: tabular-nums; }
    .ra-table tfoot td { font-weight:700; border-top:2px solid #c9d2d9; }
    .ra-up { color:#1e7e34; } .ra-down { color:#b02a37; }
    .ra-filter { display:flex; flex-wrap:wrap; gap:.75rem; align-items:end; margin-bottom:1rem; }
    .ra-filter label { display:flex; flex-direction:column; font-size:.9rem; }
    .ra-filter input { width:auto; }
    .ra-verdict { padding:.75rem 1rem; border-radius:6px; margin-bottom:1rem; background:#eef3f6; }
</style>

<div class="uman-container">
    <div class="uman-header">
        <h2>Rounding Audit</h2>
        <a class="btn secondary" href="<?= htmlspecialchars('rounding_audit.php?' . http_build_query(['start' => $start, 'end' => $end, 'format' => 'csv'])) ?>">CSV</a>
    </div>

    <form method="GET" class="ra-filter">
        <label>From <input type="date" name="start" value="<?= htmlspecialchars($start) ?>"></label>
        <label>To <input type="date" name="end" value="<?= htmlspecialchars($end) ?>"></label>
        <button type="submit" class="btn primary">Show</button>
    </form>

    <?php if (!$byEmp): ?>
        <p>No completed punches in this range.</p>
    <?php else: ?>
        <?php $pct = $grand['actual'] > 0 ? $grand['net'] / $grand['actual'] * 100 : 0; ?>
        <div class="ra-verdict">
            Across <?= count($byEmp) ?> employees and <?= $grand['punches'] ?> punches, rounding
            <?php if (abs($grand['net']) < 0.005): ?>
                came out <strong>even</strong>.
            <?php else: ?>
                <?= $grand['net'] > 0 ? 'paid employees' : 'cost employees' ?>
                <strong class="<?= $grand['net'] > 0 ? 'ra-up' : 'ra-down' ?>"><?= number_format(abs($grand['net']), 2) ?> hours</strong>
                (<?= number_format(abs($pct), 2) ?>% of actual time) <?= $grand['net'] > 0 ? 'more than' : 'less than' ?> they worked.
            <?php endif; ?>
            <?php if ($grand['net'] < 0 && abs($pct) >= 0.5): ?>
                <br><strong>Review your shift windows:</strong> rounding that consistently cuts pay can violate wage-and-hour rules.
            <?php endif; ?>
        </div>

        <table class="ra-table">
            <thead>
                <tr>
                    <th>Employee</th><th class="num">Punches</th><th class="num">Actual</th><th class="num">Paid</th>
                    <th class="num">Auto lunch</th><th class="num">Rounding net</th>
                    <th class="num">In their favor</th><th class="num">Against them</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($byEmp as $r): ?>
                <tr>
                    <td><a href="view_punches.php?emp=<?= $r['id'] ?>&amp;from=<?= urlencode(date('m/d/Y', strtotime($start))) ?>&amp;to=<?= urlencode(date('m/d/Y', strtotime($end))) ?>"><?= htmlspecialchars($r['name']) ?></a></td>
                    <td class="num"><?= $r['punches'] ?></td>
                    <td class="num"><?= number_format($r['actual'], 2) ?></td>
                    <td class="num"><?= number_format($r['paid'], 2) ?></td>
                    <td class="num"><?= $r['lunch'] > 0 ? '−' . number_format($r['lunch'], 2) : '0.00' ?></td>
                    <td class="num <?= $r['net'] > 0.004 ? 'ra-up' : ($r['net'] < -0.004 ? 'ra-down' : '') ?>"><?= $fmtNet($r['net']) ?></td>
                    <td class="num"><?= $r['upN'] ?> × · <?= (int) round($r['upMin']) ?> min</td>
                    <td class="num"><?= $r['downN'] ?> × · <?= (int) round($r['downMin']) ?> min</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td>Total</td>
                    <td class="num"><?= $grand['punches'] ?></td>
                    <td class="num"><?= number_format($grand['actual'], 2) ?></td>
                    <td class="num"><?= number_format($grand['paid'], 2) ?></td>
                    <td class="num"><?= $grand['lunch'] > 0 ? '−' . number_format($grand['lunch'], 2) : '0.00' ?></td>
                    <td class="num"><?= $fmtNet($grand['net']) ?></td>
                    <td class="num"><?= $grand['upN'] ?> × · <?= (int) round($grand['upMin']) ?> min</td>
                    <td class="num"><?= $grand['downN'] ?> × · <?= (int) round($grand['downMin']) ?> min</td>
                </tr>
            </tfoot>
        </table>
        <p style="color:var(--muted-text); font-size:.85rem; margin-top:.75rem;">
            Hours. <em>Actual</em> is time between the real punches minus punched lunches; <em>Paid</em> is after shift rounding and lunch rules.
            <em>Rounding net</em> = Paid − Actual, not counting auto-deducted lunch. Positive means the employee was paid for more time than they punched.
            Time outside the shift that wasn't paid because it wasn't approved counts against the employee here.
        </p>
    <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>
