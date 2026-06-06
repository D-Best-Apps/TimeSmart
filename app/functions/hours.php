<?php
/**
 * functions/hours.php
 *
 * SINGLE SOURCE OF TRUTH for all worked-hours math, time conversions,
 * pay-period boundaries (Wed–Tue), and overtime (weekly > 40).
 *
 * Do NOT reimplement any of these elsewhere — include this file instead:
 *   require_once __DIR__ . '/hours.php';   // from app/functions/*
 *   require_once __DIR__ . '/../functions/hours.php';  // from app/admin/*
 *
 * Pure functions (no DB/session) except reconcileClockStatus($conn, ...).
 */

if (!defined('MAX_SHIFT_HOURS')) {
    // Spans longer than this (after overnight roll-over) are treated as garbage -> 0.0
    define('MAX_SHIFT_HOURS', 16);
}

/**
 * Canonical read clause for reports (documented for reuse — keep every report identical):
 *   SUM(GREATEST(COALESCE(tp.TotalHours,0),0)) AS TotalHours
 *   WHERE tp.TimeIN IS NOT NULL AND tp.TimeOut IS NOT NULL
 * GREATEST() drops any legacy negative rows; COALESCE() ignores still-open punches.
 */

/** Parse 'H:i:s' / 'H:i' (optionally prefixed with a date) to seconds since midnight, or null. */
function hms_to_seconds(?string $t): ?int {
    if ($t === null) return null;
    $t = trim($t);
    if ($t === '') return null;
    if (strpos($t, ' ') !== false) {           // strip any leading date component defensively
        $parts = explode(' ', $t);
        $t = end($parts);
    }
    $bits = explode(':', $t);
    if (count($bits) < 2) return null;
    $h = (int) $bits[0];
    $m = (int) $bits[1];
    $s = isset($bits[2]) ? (int) $bits[2] : 0;
    return $h * 3600 + $m * 60 + $s;
}

/**
 * THE canonical worked-hours calculation. Inputs are TIME strings ('H:i:s'/'H:i') or null.
 *   - null  => punch incomplete (missing clock-in or clock-out) = "open/unknown"
 *   - float => complete punch, >= 0.0, 2 dp
 * out <= in is treated as an overnight shift (+24h) unless the span exceeds MAX_SHIFT_HOURS,
 * in which case 0.0 (garbage guard). Lunch subtracted only when both ends present and end > start.
 */
function calculateTotalHours(?string $clockIn, ?string $lunchStart, ?string $lunchEnd, ?string $clockOut): ?float {
    $inSec  = hms_to_seconds($clockIn);
    $outSec = hms_to_seconds($clockOut);
    if ($inSec === null || $outSec === null) {
        return null;
    }
    $span = $outSec - $inSec;
    if ($span <= 0) {                  // overnight roll-over
        $span += 86400;
        if ($span > MAX_SHIFT_HOURS * 3600) {
            return 0.0;                // implausible -> garbage guard
        }
    }
    $ls = hms_to_seconds($lunchStart);
    $le = hms_to_seconds($lunchEnd);
    if ($ls !== null && $le !== null && $le > $ls) {
        $span -= ($le - $ls);
    }
    if ($span < 0) {
        $span = 0;
    }
    return round($span / 3600, 2);
}

/** 'H:i:s' -> decimal hours (2 dp). '' / null -> 0.0 */
function hmsToDecimal(?string $hms): float {
    $sec = hms_to_seconds($hms);
    return $sec === null ? 0.0 : round($sec / 3600, 2);
}

/** decimal hours -> 'H:MM' */
function decimalToHms(float $decimalHours): string {
    $neg = $decimalHours < 0;
    $totalMin = (int) round(abs($decimalHours) * 60);
    $h = intdiv($totalMin, 60);
    $m = $totalMin % 60;
    return ($neg ? '-' : '') . $h . ':' . str_pad((string) $m, 2, '0', STR_PAD_LEFT);
}

/** Alias for the name used throughout the admin reports. */
function decimalToHM(float $decimalHours): string {
    return decimalToHms($decimalHours);
}

/** Round decimal hours to the nearest $interval minutes (0 => just 2 dp). */
function roundToNearestMinutes(float $decimalHours, int $interval = 0): float {
    if ($interval <= 0) return round($decimalHours, 2);
    $totalMinutes   = $decimalHours * 60;
    $roundedMinutes = round($totalMinutes / $interval) * $interval;
    return round($roundedMinutes / 60, 2);
}

/** Wednesday on/before $date (pay period start). */
function payPeriodStart(string $date): string {
    $d = new DateTime($date);
    $dow = (int) $d->format('N');      // Mon=1 .. Sun=7 ; Wed=3
    $back = (($dow - 3) + 7) % 7;      // days since the most recent Wednesday
    if ($back > 0) {
        $d->modify("-{$back} days");
    }
    return $d->format('Y-m-d');
}

/** Tuesday ending the pay period that contains $date. */
function payPeriodEnd(string $date): string {
    return (new DateTime(payPeriodStart($date)))->modify('+6 days')->format('Y-m-d');
}

/** Legacy report helper names — aliases for the canonical pay-period functions. */
function getPayPeriodStart(string $date): string { return payPeriodStart($date); }
function getPayPeriodEnd(string $date): string { return payPeriodEnd($date); }

/**
 * List of Wed–Tue pay periods overlapping [$start,$end].
 * @return array<int,array{start:string,end:string}>
 */
function generatePayPeriods(string $start, string $end, bool $completeOnly = true): array {
    $periods = [];
    $cur   = new DateTime(payPeriodStart($start));
    $endDt = new DateTime($end);
    $today = new DateTime('today');
    while ($cur <= $endDt) {
        $pEndDt = (clone $cur)->modify('+6 days');
        if (!$completeOnly || $pEndDt < $today) {
            $periods[] = ['start' => $cur->format('Y-m-d'), 'end' => $pEndDt->format('Y-m-d')];
        }
        $cur->modify('+7 days');
    }
    return $periods;
}

/**
 * Weekly overtime on the Wed–Tue period. OT = max(0, periodHours - 40).
 * @param array<string,float> $dailyTotals  map 'Y-m-d' => decimal hours
 * @return array{weeks:array<string,array{hours:float,regular:float,ot:float}>,totalHours:float,totalRegular:float,totalOT:float}
 */
function weeklyOvertime(array $dailyTotals): array {
    $weeks = [];
    foreach ($dailyTotals as $date => $hours) {
        $key = payPeriodStart($date);
        $weeks[$key] = ($weeks[$key] ?? 0.0) + (float) $hours;
    }
    ksort($weeks);
    $out = ['weeks' => [], 'totalHours' => 0.0, 'totalRegular' => 0.0, 'totalOT' => 0.0];
    foreach ($weeks as $key => $h) {
        $h   = round($h, 2);
        $reg = min($h, 40.0);
        $ot  = max($h - 40.0, 0.0);
        $out['weeks'][$key] = ['hours' => $h, 'regular' => round($reg, 2), 'ot' => round($ot, 2)];
        $out['totalHours']   += $h;
        $out['totalRegular'] += $reg;
        $out['totalOT']      += $ot;
    }
    $out['totalHours']   = round($out['totalHours'], 2);
    $out['totalRegular'] = round($out['totalRegular'], 2);
    $out['totalOT']      = round($out['totalOT'], 2);
    return $out;
}

/**
 * Self-heal the denormalized users.ClockStatus from the latest open punch.
 * Returns the derived status ('In' | 'Lunch' | 'Out').
 */
function reconcileClockStatus(mysqli $conn, int $empID): string {
    $status = 'Out';
    $stmt = $conn->prepare("SELECT LunchStart, LunchEnd FROM timepunches WHERE EmployeeID = ? AND TimeOUT IS NULL ORDER BY Date DESC, TimeIN DESC LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $empID);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $stmt->bind_result($ls, $le);
            $stmt->fetch();
            $status = (!empty($ls) && empty($le)) ? 'Lunch' : 'In';
        }
        $stmt->close();
    }
    if ($up = $conn->prepare("UPDATE users SET ClockStatus = ? WHERE ID = ?")) {
        $up->bind_param('si', $status, $empID);
        $up->execute();
        $up->close();
    }
    return $status;
}
