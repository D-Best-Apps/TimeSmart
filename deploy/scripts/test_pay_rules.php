<?php
/**
 * CLI tests for the shift rounding / auto-lunch engine (app/functions/pay_rules.php)
 * and the configurable work week / overtime in app/functions/hours.php.
 *
 * No database needed. Run before each release:
 *   php deploy/scripts/test_pay_rules.php
 *   docker exec <container> php /var/www/html/../deploy/scripts/test_pay_rules.php   (if mounted)
 *
 * Exit code 0 = all passed.
 */

require_once __DIR__ . '/../../app/functions/pay_rules.php';

$failures = 0;
$count = 0;
function check(string $name, $expected, $actual): void {
    global $failures, $count;
    $count++;
    if ($expected === $actual) {
        echo "  ok   {$name}\n";
        return;
    }
    $failures++;
    echo "  FAIL {$name}\n       expected " . var_export($expected, true) . "\n       got      " . var_export($actual, true) . "\n";
}

/** One row per [in, lunchStart, lunchEnd, out, extra flags]. */
function rows(array ...$punches): array {
    $rows = [];
    foreach ($punches as $i => $p) {
        $rows[] = [
            'id' => $i + 1,
            'TimeIN' => $p[0], 'LunchStart' => $p[1] ?? null, 'LunchEnd' => $p[2] ?? null, 'TimeOut' => $p[3] ?? null,
            'LunchWaived' => $p[4]['waived'] ?? 0, 'OutsideApproved' => $p[4]['approved'] ?? 0,
        ];
    }
    return $rows;
}

function shift(array $over = []): array {
    return $over + [
        'ID' => 1, 'Name' => '1st', 'Color' => '#0a4a6b',
        'StartTime' => '07:00:00', 'EndTime' => '15:30:00', 'Days' => 'Mon,Tue,Wed,Thu,Fri',
        'EarlyInMinutes' => 15, 'LateInGraceMinutes' => 5, 'EarlyOutGraceMinutes' => 5, 'LateOutMinutes' => 10,
        'OutsidePolicy' => 'actual', 'RoundIncrement' => 15, 'RoundMode' => 'nearest',
        'LunchMode' => 'auto', 'LunchMinutes' => 30, 'LunchMinWorkedHours' => '6.00',
    ];
}

$noRules = ['roundIncrement' => 0, 'roundMode' => 'nearest'];
$MON = '2026-11-02'; // a Monday
$SAT = '2026-11-07';

/** Paid summary for row $id: "PaidIn-PaidOut lunch total" */
function paid(array $result, int $id = 1): string {
    $r = $result[$id];
    $t = fn($v) => $v === null ? '-' : substr($v, 0, 5);
    return $t($r['PaidIn']) . '-' . $t($r['PaidOut']) . ' L' . ($r['LunchDeductMin'] ?? '-') . ' ' . ($r['TotalHours'] === null ? 'null' : number_format($r['TotalHours'], 2));
}

echo "No shift, no rounding = calculateTotalHours() exactly\n";
$cases = [
    ['06:52:34', null, null, '15:31:10'],
    ['07:00:00', '11:02:00', '11:37:30', '15:30:00'],
    ['22:00:00', '02:00:00', '02:30:00', '06:30:00'],   // overnight
    ['08:00:00', null, null, '08:00:00'],               // zero / garbage guard
    ['20:00:00', null, null, '19:00:00'],               // 23h overnight -> garbage
    ['05:00:00', null, null, '21:30:00'],               // 16.5h same day is paid, not garbage
];
foreach ($cases as $c) {
    $res = applyShiftRules(rows($c), null, $noRules, $MON);
    check("parity {$c[0]}-{$c[3]}", calculateTotalHours($c[0], $c[1], $c[2], $c[3]), $res[1]['TotalHours']);
}

echo "Worked example from docs/SHIFT_ROUNDING_PLAN.md (7:00-3:30, auto 30m lunch)\n";
$s = shift();
check('6:48-3:34',  '07:00-15:30 L30 8.00', paid(applyShiftRules(rows(['06:48:00', null, null, '15:34:00']), $s, $noRules, $MON)));
check('7:04-3:31',  '07:00-15:30 L30 8.00', paid(applyShiftRules(rows(['07:04:00', null, null, '15:31:00']), $s, $noRules, $MON)));
check('7:12-3:30',  '07:15-15:30 L30 7.75', paid(applyShiftRules(rows(['07:12:00', null, null, '15:30:00']), $s, $noRules, $MON)));
check('6:50-5:05',  '07:00-17:00 L30 9.50', paid(applyShiftRules(rows(['06:50:00', null, null, '17:05:00']), $s, $noRules, $MON)));
check('6:55-11:00 (under 6h, no lunch)', '07:00-11:00 L0 4.00', paid(applyShiftRules(rows(['06:55:00', null, null, '11:00:00']), $s, $noRules, $MON)));

echo "Windows and outside policy\n";
check('very early, actual -> rounded', '06:30-15:30 L30 8.50', paid(applyShiftRules(rows(['06:31:00', null, null, '15:30:00']), $s, $noRules, $MON)));
$cap = shift(['OutsidePolicy' => 'cap']);
check('very early, cap -> shift start', '07:00-15:30 L30 8.00', paid(applyShiftRules(rows(['06:31:00', null, null, '15:30:00']), $cap, $noRules, $MON)));
check('stay late, cap -> shift end',   '07:00-15:30 L30 8.00', paid(applyShiftRules(rows(['07:00:00', null, null, '17:05:00']), $cap, $noRules, $MON)));
check('stay late, cap but approved',   '07:00-17:00 L30 9.50', paid(applyShiftRules(rows(['07:00:00', null, null, '17:05:00', ['approved' => 1]]), $cap, $noRules, $MON)));
check('left early -> rounded',         '07:00-14:00 L30 6.50', paid(applyShiftRules(rows(['07:00:00', null, null, '14:04:00']), $s, $noRules, $MON)));
$r = applyShiftRules(rows(['07:00:00', null, null, '17:05:00']), $cap, $noRules, $MON);
check('cap flags unpaid time', true, in_array('late_out_unpaid', $r[1]['flags'], true));

echo "Rounding modes\n";
check('late, round up',   '07:15-15:30 L30 7.75', paid(applyShiftRules(rows(['07:06:00', null, null, '15:30:00']), shift(['RoundMode' => 'up']), $noRules, $MON)));
check('late, nearest',    '07:00-15:30 L30 8.00', paid(applyShiftRules(rows(['07:06:00', null, null, '15:30:00']), $s, $noRules, $MON)));
check('no shift, default 15 nearest', '07:00-15:30 L0 8.50', paid(applyShiftRules(rows(['06:53:00', null, null, '15:37:00']), null, ['roundIncrement' => 15, 'roundMode' => 'nearest'], $MON)));

echo "Lunch modes\n";
check('auto, punched 45m counts (no top-up)', '07:00-15:30 L45 7.75',
    paid(applyShiftRules(rows(['07:00:00', '11:00:00', '11:45:00', '15:30:00']), $s, $noRules, $MON)));
check('auto, punched 15m topped up to 30', '07:00-15:30 L30 8.00',
    paid(applyShiftRules(rows(['07:00:00', '11:00:00', '11:15:00', '15:30:00']), $s, $noRules, $MON)));
$aup = shift(['LunchMode' => 'auto_unless_punched']);
check('auto-unless-punched, punched 15m kept', '07:00-15:30 L15 8.25',
    paid(applyShiftRules(rows(['07:00:00', '11:00:00', '11:15:00', '15:30:00']), $aup, $noRules, $MON)));
check('auto-unless-punched, not punched -> 30', '07:00-15:30 L30 8.00',
    paid(applyShiftRules(rows(['07:00:00', null, null, '15:30:00']), $aup, $noRules, $MON)));
check('worked through lunch (waived)', '07:00-15:30 L0 8.50',
    paid(applyShiftRules(rows(['07:00:00', null, null, '15:30:00', ['waived' => 1]]), $s, $noRules, $MON)));
check('punched mode, no lunch punch', '07:00-15:30 L0 8.50',
    paid(applyShiftRules(rows(['07:00:00', null, null, '15:30:00']), shift(['LunchMode' => 'punched']), $noRules, $MON)));

echo "Quick-clock days (clock out / in for lunch = two rows)\n";
$two = applyShiftRules(rows(['06:50:00', null, null, '11:02:00'], ['11:31:00', null, null, '15:33:00']), $s, $noRules, $MON);
check('row 1 snapped in, rounded out', '07:00-11:00 L0 4.00', paid($two, 1));
check('row 2 rounded in, snapped out', '11:30-15:30 L0 4.00', paid($two, 2));
$two = applyShiftRules(rows(['07:00:00', null, null, '11:00:00'], ['11:15:00', null, null, '15:30:00']), $s, $noRules, $MON);
check('15m gap topped up to 30 on longest row', '11:15-15:30 L15 4.00', paid($two, 2));

echo "Unscheduled day / off-shift call-in: rounded, not snapped, lunch rule still applies\n";
$r = applyShiftRules(rows(['06:48:00', null, null, '15:34:00']), $s, $noRules, $SAT);
check('Saturday', '06:45-15:30 L30 8.25', paid($r));
check('Saturday flagged', true, in_array('unscheduled', $r[1]['flags'], true));
check('Monday 2nd-shift call-in', '15:00-23:30 L30 8.00', paid(applyShiftRules(rows(['15:02:00', null, null, '23:31:00']), $s, $noRules, $MON)));

echo "Overnight shift (10p-6:30a)\n";
$night = shift(['StartTime' => '22:00:00', 'EndTime' => '06:30:00']);
check('21:50-06:34', '22:00-06:30 L30 8.00', paid(applyShiftRules(rows(['21:50:00', null, null, '06:34:00']), $night, $noRules, $MON)));
$nightCap = shift(['StartTime' => '22:00:00', 'EndTime' => '06:30:00', 'OutsidePolicy' => 'cap']);
$r = applyShiftRules(rows(['00:30:00', null, null, '06:31:00']), $nightCap, $noRules, $MON);
check('00:30 clock-in is late, not early (cap)', '00:30-06:30 L30 5.50', paid($r));
check('00:30 flagged late', true, in_array('late_in', $r[1]['flags'], true));
$midnight = shift(['StartTime' => '00:00:00', 'EndTime' => '08:30:00']);
check('23:50 for a midnight shift snaps to 00:00', '00:00-08:30 L30 8.00', paid(applyShiftRules(rows(['23:50:00', null, null, '08:33:00']), $midnight, $noRules, $MON)));

echo "Rows that can't be paid yet\n";
$r = applyShiftRules(rows(['07:00:00', '11:00:00', null, '15:30:00'], ['16:00:00']), $s, $noRules, $MON);
check('unfinished lunch -> null for review', null, $r[1]['TotalHours']);
check('still open -> null', null, $r[2]['TotalHours']);

echo "Work week and overtime settings\n";
check('default week Wed', '2026-10-28', payPeriodStart('2026-11-02'));
payRuleSetting('PayWeekStartDay', '3', '7');
check('Sunday week', '2026-11-01', payPeriodStart('2026-11-02'));
check('Sunday label', 'Sunday–Saturday', workWeekLabel());
payRuleSetting('PayWeekStartDay', '3', '1');
check('Monday week', '2026-11-02', payPeriodStart('2026-11-02'));
check('Monday label short', 'Mon–Sun', workWeekLabel(true));
$week = ['2026-11-02' => 10, '2026-11-03' => 10, '2026-11-04' => 10, '2026-11-05' => 10, '2026-11-06' => 4];
$ot = weeklyOvertime($week);
check('weekly OT > 40', [44.0, 40.0, 4.0], [$ot['totalHours'], $ot['totalRegular'], $ot['totalOT']]);
$ot = weeklyOvertime($week, 40, 8);
check('daily OT > 8 not double counted', [44.0, 36.0, 8.0], [$ot['totalHours'], $ot['totalRegular'], $ot['totalOT']]);
payRuleSetting('OvertimeWeeklyHours', '40', '38');
$ot = weeklyOvertime($week);
check('weekly threshold setting', 6.0, $ot['totalOT']);

echo "Pay periods\n";
payRuleSetting('PayWeekStartDay', '3', '1'); // Monday weeks
$bi = ['periodType' => 'biweekly', 'periodAnchor' => '2026-11-02'];
check('bi-weekly, anchor day', ['2026-11-02', '2026-11-15'], payPeriodRange('2026-11-02', $bi));
check('bi-weekly, last day', ['2026-11-02', '2026-11-15'], payPeriodRange('2026-11-15', $bi));
check('bi-weekly, next period', ['2026-11-16', '2026-11-29'], payPeriodRange('2026-11-16', $bi));
check('bi-weekly, before anchor', ['2026-10-19', '2026-11-01'], payPeriodRange('2026-10-25', $bi));
check('weekly follows work week', ['2026-11-02', '2026-11-08'], payPeriodRange('2026-11-05', ['periodType' => 'weekly', 'periodAnchor' => null]));

echo "\n{$count} checks, {$failures} failed\n";
exit($failures ? 1 : 0);
