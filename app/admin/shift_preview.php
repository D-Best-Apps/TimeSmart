<?php
// admin/shift_preview.php — JSON for the shift editor's "Try a punch" box. Runs the
// real rules engine on the (possibly unsaved) shift settings posted from the form.
// Read-only: nothing is stored.
require_once '../auth/db.php';
session_start();

header('Content-Type: application/json');

require_once __DIR__ . '/../functions/check_permission.php';
if (!isset($_SESSION['admin']) || !checkPermission('manage_pay_rules')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not allowed.']);
    exit;
}

require_once __DIR__ . '/../functions/pay_rules.php';

$time = function ($v): ?string {
    $v = trim((string) $v);
    return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? "{$v}:00" : null;
};

$in  = $time($_POST['tryIn'] ?? '');
$out = $time($_POST['tryOut'] ?? '');
if ($in === null || $out === null) {
    echo json_encode(['ok' => false, 'error' => 'Enter a clock-in and clock-out.']);
    exit;
}
$ls = $time($_POST['tryLs'] ?? '');
$le = $time($_POST['tryLe'] ?? '');

$p = $_POST;
$shift = [
    'ID' => 0,
    'StartTime' => $time($p['StartTime'] ?? '') ?? '07:00:00',
    'EndTime'   => $time($p['EndTime'] ?? '') ?? '15:30:00',
    'Days' => 'Mon,Tue,Wed,Thu,Fri,Sat,Sun',
    'EarlyInMinutes'       => max(0, (int) ($p['EarlyInMinutes'] ?? 0)),
    'LateInGraceMinutes'   => max(0, (int) ($p['LateInGraceMinutes'] ?? 0)),
    'EarlyOutGraceMinutes' => max(0, (int) ($p['EarlyOutGraceMinutes'] ?? 0)),
    'LateOutMinutes'       => max(0, (int) ($p['LateOutMinutes'] ?? 0)),
    'OutsidePolicy'  => ($p['OutsidePolicy'] ?? '') === 'cap' ? 'cap' : 'actual',
    'RoundIncrement' => max(0, (int) ($p['RoundIncrement'] ?? 0)),
    'RoundMode'      => isset(ROUND_MODES[$p['RoundMode'] ?? '']) ? $p['RoundMode'] : 'nearest',
    'LunchMode'      => isset(LUNCH_MODES[$p['LunchMode'] ?? '']) ? $p['LunchMode'] : 'punched',
    'LunchMinutes'   => max(0, (int) ($p['LunchMinutes'] ?? 30)),
    'LunchMinWorkedHours' => (float) ($p['LunchMinWorkedHours'] ?? 6),
];
// A fixed Monday; "a day it doesn't run" previews an empty schedule instead.
$date = '2026-11-02';
if (!empty($p['tryWeekend'])) {
    $shift['Days'] = '';
}

$row = ['id' => 1, 'TimeIN' => $in, 'LunchStart' => $ls, 'LunchEnd' => $le, 'TimeOut' => $out, 'LunchWaived' => 0, 'OutsideApproved' => 0];
$r = applyShiftRules([$row], $shift, payPolicy($conn), $date)[1];

if ($r['TotalHours'] === null) {
    echo json_encode(['ok' => false, 'error' => $ls && !$le ? 'Enter when lunch ended.' : 'Those times can\'t be paid as entered.']);
    exit;
}

$clock = fn($t) => date('g:i a', strtotime($t));
echo json_encode([
    'ok'      => true,
    'paidIn'  => $clock($r['PaidIn']),
    'paidOut' => $clock($r['PaidOut']),
    'lunch'   => $r['LunchDeductMin'] ? $r['LunchDeductMin'] . ' min' : 'none',
    'hours'   => number_format($r['TotalHours'], 2),
    'raw'     => number_format((float) $r['RawHours'], 2),
    'notes'   => array_values(array_map(fn($f) => PUNCH_FLAG_LABELS[$f] ?? $f, $r['flags'])),
]);
