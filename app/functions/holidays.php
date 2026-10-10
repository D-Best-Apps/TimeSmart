<?php
/**
 * functions/holidays.php
 *
 * SINGLE SOURCE OF TRUTH for the company holiday calendar and for what counts
 * as a working day.
 *
 * D-Best observes six paid holidays. The office is closed, so full-day time off
 * does not consume PTO/Sick hours on these days — the same way it doesn't on a
 * Saturday or Sunday. Without this, a vacation spanning Christmas and New Year's
 * billed the employee 8 hours for each, which is what made week-over-40 warnings
 * fire on requests that were nowhere near 40 working hours.
 *
 * The dates are computed from rules rather than stored in a table, so there is
 * nothing to populate each January. To change the list, edit the $rules array in
 * companyHolidaysForYear() — that is the only place the holidays are defined.
 *
 * Weekend observance follows the usual US convention: a holiday landing on a
 * Saturday is observed the preceding Friday, on a Sunday the following Monday.
 * That keeps every holiday on a weekday, which is the only place it can matter
 * for hour math (a holiday on a weekend is already a non-working day).
 *
 * Pure functions — no DB, no session.
 */

/**
 * The six observed company holidays for a calendar year.
 *
 * @return array<string,string> observed 'Y-m-d' => label, ascending by date.
 *                              Observance shifting can push a date into an
 *                              adjacent year (New Year's Day on a Saturday is
 *                              observed Dec 31 of the year before), so callers
 *                              should not assume every key is inside $year —
 *                              use isCompanyHoliday(), which handles that.
 */
function companyHolidaysForYear(int $year): array
{
    $rules = [
        "New Year's Day"   => "$year-01-01",
        'Memorial Day'     => "last monday of may $year",
        'Independence Day' => "$year-07-04",
        'Labor Day'        => "first monday of september $year",
        'Thanksgiving'     => "fourth thursday of november $year",
        'Christmas Day'    => "$year-12-25",
    ];

    $observed = [];
    foreach ($rules as $label => $spec) {
        $observed[observedHolidayDate($spec)] = $label;
    }
    ksort($observed);
    return $observed;
}

/**
 * Shift a holiday onto the weekday it is observed on.
 * Saturday -> the Friday before; Sunday -> the Monday after; otherwise unchanged.
 *
 * @param string $spec Any date string DateTime understands ('2026-12-25',
 *                     'fourth thursday of november 2026', ...).
 */
function observedHolidayDate(string $spec): string
{
    $d   = new DateTime($spec);
    $dow = (int) $d->format('N'); // Mon=1 .. Sun=7
    if ($dow === 6) {
        $d->modify('-1 day');
    } elseif ($dow === 7) {
        $d->modify('+1 day');
    }
    return $d->format('Y-m-d');
}

/**
 * Is $date ('Y-m-d') an observed company holiday? Returns the holiday's label
 * (for display) or null.
 *
 * Checks the neighbouring years as well, because observance shifting can move a
 * holiday across a year boundary.
 */
function isCompanyHoliday(string $date): ?string
{
    static $byYear = [];
    $year = (int) substr($date, 0, 4);
    foreach ([$year - 1, $year, $year + 1] as $y) {
        if (!isset($byYear[$y])) {
            $byYear[$y] = companyHolidaysForYear($y);
        }
        if (isset($byYear[$y][$date])) {
            return $byYear[$y][$date];
        }
    }
    return null;
}

/** Is $date ('Y-m-d') a day the company works? Mon–Fri and not an observed holiday. */
function isWorkday(string $date): bool
{
    $dow = (int) (new DateTime($date))->format('N');
    return $dow < 6 && isCompanyHoliday($date) === null;
}

/**
 * Number of working days in [$start, $end] inclusive (Mon–Fri, holidays removed).
 * Returns 0 when $start is after $end.
 */
function countWorkdays(string $start, string $end): int
{
    $d   = new DateTime($start);
    $fin = new DateTime($end);
    $n   = 0;
    while ($d <= $fin) {
        if (isWorkday($d->format('Y-m-d'))) {
            $n++;
        }
        $d->modify('+1 day');
    }
    return $n;
}
