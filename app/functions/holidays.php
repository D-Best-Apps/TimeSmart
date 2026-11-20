<?php
/**
 * functions/holidays.php
 *
 * SINGLE SOURCE OF TRUTH for the company holiday calendar and for what counts
 * as a working day.
 *
 * On a company holiday the office is closed, so full-day time off does not consume
 * PTO/Sick hours (the same as a Saturday or Sunday), and the admin Home board does
 * not flag no-shows or late arrivals. Holidays add no pay by themselves; holiday pay
 * (functions/holiday_pay.php) does. With holiday pay on, a holiday marked Unpaid is a
 * closure day the company doesn't pay, so full-day PTO is charged for it (isTimeOffDay()).
 *
 * The list is edited on Settings -> Holidays (admin/holidays.php) and stored in the
 * `holidays` table as rules, not dates, so there is nothing to re-enter each January:
 *   fixed  - same month/day every year            (Christmas: 12/25)
 *   nth    - Nth weekday of a month               (Thanksgiving: 4th Thursday of November)
 *   last   - last weekday of a month              (Memorial Day: last Monday of May)
 *   easter - Easter Sunday, usually with an offset (Good Friday: Easter - 2 days)
 *   once   - a single date                        (a plant shutdown day)
 * OffsetDays shifts the rule's date (Day after Thanksgiving = Thanksgiving + 1).
 *
 * Weekend observance (ObserveWeekday, default on) follows the usual US convention: a
 * holiday landing on a Saturday is observed the Friday before, on a Sunday the Monday
 * after. One-time dates are never moved.
 *
 * Until the holidays migration has run (or with no database connection), the six
 * standard holidays below apply, which is what this file used to hard-code.
 */

/**
 * Built-in holidays offered on the Holidays page, in calendar order.
 * key => [Name, RuleType, Month, Day (date, or N for nth), Weekday (1=Mon..7=Sun), OffsetDays, ObserveWeekday]
 */
const HOLIDAY_PRESETS = [
    'new_year'          => ["New Year's Day",                 'fixed',  1,  1,    null, 0,  1],
    'mlk'               => ['Martin Luther King Jr. Day',      'nth',    1,  3,    1,    0,  1],
    'presidents'        => ["Presidents' Day",                 'nth',    2,  3,    1,    0,  1],
    'good_friday'       => ['Good Friday',                     'easter', null, null, null, -2, 0],
    'easter_monday'     => ['Easter Monday',                   'easter', null, null, null, 1,  0],
    'memorial'          => ['Memorial Day',                    'last',   5,  null, 1,    0,  1],
    'juneteenth'        => ['Juneteenth',                      'fixed',  6,  19,   null, 0,  1],
    'independence'      => ['Independence Day',                'fixed',  7,  4,    null, 0,  1],
    'labor'             => ['Labor Day',                       'nth',    9,  1,    1,    0,  1],
    'columbus'          => ["Columbus Day / Indigenous Peoples' Day", 'nth', 10, 2, 1,  0,  1],
    'veterans'          => ['Veterans Day',                    'fixed',  11, 11,   null, 0,  1],
    'thanksgiving'      => ['Thanksgiving',                    'nth',    11, 4,    4,    0,  1],
    'day_after_thanks'  => ['Day after Thanksgiving',          'nth',    11, 4,    4,    1,  1],
    'christmas_eve'     => ['Christmas Eve',                   'fixed',  12, 24,   null, 0,  1],
    'christmas'         => ['Christmas Day',                   'fixed',  12, 25,   null, 0,  1],
    'new_years_eve'     => ["New Year's Eve",                  'fixed',  12, 31,   null, 0,  1],
];

/** The six holidays every install starts with (and the fallback before the migration). */
const HOLIDAY_DEFAULT_KEYS = ['new_year', 'memorial', 'independence', 'labor', 'thanksgiving', 'christmas'];

/** A preset as a holidays-table row. */
function holidayPresetRow(string $key): array
{
    [$name, $type, $month, $day, $weekday, $offset, $observe] = HOLIDAY_PRESETS[$key];
    return [
        'ID' => 0, 'Name' => $name, 'RuleType' => $type, 'Month' => $month, 'Day' => $day,
        'Weekday' => $weekday, 'OffsetDays' => $offset, 'OnceDate' => null,
        'ObserveWeekday' => $observe, 'PresetKey' => $key,
    ];
}

/**
 * The holiday rules in effect (holidays-table rows). Cached per request; pass
 * $refresh after changing the table.
 */
function holidayRules(?mysqli $conn = null, bool $refresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$refresh) {
        return $cache;
    }
    $conn ??= ($GLOBALS['conn'] ?? null) instanceof mysqli ? $GLOBALS['conn'] : null;
    $rows = null;
    if ($conn) {
        try {
            $res = $conn->query("SELECT * FROM holidays ORDER BY ID");
            $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : null;
        } catch (mysqli_sql_exception $e) {
            $rows = null; // holidays migration not applied yet
        }
    }
    $cache = $rows ?? array_map('holidayPresetRow', HOLIDAY_DEFAULT_KEYS);
    holidayCalendarReset();
    return $cache;
}

/** Easter Sunday for $year (Gregorian; anonymous/Meeus algorithm). */
function easterSunday(int $year): string
{
    $a = $year % 19;
    $b = intdiv($year, 100);
    $c = $year % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31);
    $day = (($h + $l - 7 * $m + 114) % 31) + 1;
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

/**
 * The date a holiday rule falls on in $year, before weekend observance, or null
 * (a one-time date in another year, Feb 29 in a non-leap year, a bad row).
 */
function holidayRuleDate(array $rule, int $year): ?string
{
    $month   = (int) ($rule['Month'] ?? 0);
    $day     = (int) ($rule['Day'] ?? 0);
    $weekday = (int) ($rule['Weekday'] ?? 0);
    switch ($rule['RuleType'] ?? '') {
        case 'fixed':
            if (!checkdate($month, $day, $year)) return null;
            $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
            break;
        case 'nth':
            if ($month < 1 || $month > 12 || $day < 1 || $day > 5 || $weekday < 1 || $weekday > 7) return null;
            $first = new DateTime(sprintf('%04d-%02d-01', $year, $month));
            $first->modify('+' . ((($weekday - (int) $first->format('N')) + 7) % 7 + 7 * ($day - 1)) . ' days');
            if ((int) $first->format('n') !== $month) return null; // no 5th such weekday this month
            $date = $first->format('Y-m-d');
            break;
        case 'last':
            if ($month < 1 || $month > 12 || $weekday < 1 || $weekday > 7) return null;
            $last = new DateTime(sprintf('%04d-%02d-01', $year, $month));
            $last->modify('last day of this month');
            $last->modify('-' . (((int) $last->format('N') - $weekday + 7) % 7) . ' days');
            $date = $last->format('Y-m-d');
            break;
        case 'easter':
            $date = easterSunday($year);
            break;
        case 'once':
            $date = (string) ($rule['OnceDate'] ?? '');
            return (int) substr($date, 0, 4) === $year ? $date : null;
        default:
            return null;
    }
    $offset = (int) ($rule['OffsetDays'] ?? 0);
    if ($offset !== 0) {
        $date = (new DateTime($date))->modify(($offset > 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');
    }
    return $date;
}

/**
 * Shift a holiday onto the weekday it is observed on.
 * Saturday -> the Friday before; Sunday -> the Monday after; otherwise unchanged.
 *
 * @param string $spec Any date string DateTime understands.
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

/** The observed date of one holiday rule in $year, or null. */
function holidayObservedDate(array $rule, int $year): ?string
{
    $date = holidayRuleDate($rule, $year);
    if ($date === null) {
        return null;
    }
    $observe = (int) ($rule['ObserveWeekday'] ?? 1) === 1 && ($rule['RuleType'] ?? '') !== 'once';
    return $observe ? observedHolidayDate($date) : $date;
}

/**
 * Every holiday's observed date in $year: [['rule' => holidays row, 'date' => 'Y-m-d'], ...].
 *
 * When two holidays end up on the same weekday, the one that comes first on the
 * calendar moves back to the previous free weekday:
 *   Christmas on a Saturday is observed Friday Dec 24, so Christmas Eve moves to Thu Dec 23;
 *   Christmas Eve on a Sunday would be observed Monday Dec 25 (Christmas), so it moves to Fri Dec 22;
 *   New Year's Eve and New Year's Day work the same way.
 * Holidays that don't follow weekend observance, one-time dates, and two holidays on the
 * very same date keep their day and share it.
 *
 * The neighbouring years are worked out too, because a moved holiday can cross the year
 * boundary (New Year's Day 2028 is observed Friday Dec 31, 2027). Dates can therefore
 * fall just outside $year.
 */
function holidayScheduleForYear(int $year): array
{
    $entries = [];
    foreach ([$year - 1, $year, $year + 1] as $y) {
        foreach (holidayRules() as $rule) {
            $natural = holidayRuleDate($rule, $y);
            if ($natural === null) {
                continue;
            }
            $observed = holidayObservedDate($rule, $y);
            $entries[] = ['rule' => $rule, 'year' => $y, 'natural' => $natural, 'date' => $observed];
        }
    }
    // Later holidays claim their day first; an earlier one that lands on a claimed day moves back
    usort($entries, fn($a, $b) => strcmp($b['natural'], $a['natural']));
    $taken = []; // observed date => natural date of the holiday that holds it
    $out = [];
    foreach ($entries as $e) {
        $date = $e['date'];
        $canMove = (int) ($e['rule']['ObserveWeekday'] ?? 1) === 1 && ($e['rule']['RuleType'] ?? '') !== 'once';
        if ($canMove && isset($taken[$date]) && $taken[$date] !== $e['natural']) {
            $d = new DateTime($date);
            do {
                $d->modify('-1 day');
            } while ((int) $d->format('N') >= 6 || isset($taken[$d->format('Y-m-d')]));
            $date = $d->format('Y-m-d');
        }
        $taken[$date] ??= $e['natural'];
        if ($e['year'] === $year) {
            $out[] = ['rule' => $e['rule'], 'date' => $date];
        }
    }
    usort($out, fn($a, $b) => strcmp($a['date'], $b['date']));
    return $out;
}

/**
 * The observed company holidays for a calendar year.
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
    $observed = [];
    foreach (holidayScheduleForYear($year) as $h) {
        $date = $h['date'];
        $observed[$date] = isset($observed[$date]) ? $observed[$date] . ' / ' . $h['rule']['Name'] : $h['rule']['Name'];
    }
    ksort($observed);
    return $observed;
}

/** Per-request cache of companyHolidaysForYear(), cleared when the rules reload. */
function &holidayCalendarCache(): array
{
    static $byYear = [];
    return $byYear;
}

/** Per-request cache of holidayPaidDates(), cleared with the calendar cache. */
function &holidayPaidCache(): array
{
    static $byYear = [];
    return $byYear;
}

function holidayCalendarReset(): void
{
    $cache = &holidayCalendarCache();
    $cache = [];
    $paid = &holidayPaidCache();
    $paid = [];
}

/** Observed holiday dates for $year => true when any holiday on that date is Paid. */
function holidayPaidDates(int $year): array
{
    $out = [];
    foreach (holidayScheduleForYear($year) as $h) {
        $out[$h['date']] = ($out[$h['date']] ?? false) || (int) ($h['rule']['Paid'] ?? 1) === 1;
    }
    return $out;
}

/** Is holiday pay turned on (Settings -> Holidays)? Cached per request; off without a database. */
function holidayPayEnabled(?mysqli $conn = null): bool
{
    static $on = null;
    if ($on !== null) {
        return $on;
    }
    $conn ??= ($GLOBALS['conn'] ?? null) instanceof mysqli ? $GLOBALS['conn'] : null;
    $on = false;
    if ($conn) {
        try {
            $res = $conn->query("SELECT SettingValue FROM settings WHERE SettingKey = 'HolidayPayEnabled'");
            $on = $res && ($row = $res->fetch_row()) && $row[0] === '1';
        } catch (mysqli_sql_exception $e) {
            $on = false;
        }
    }
    return $on;
}

/**
 * Does full-day time off on $date use PTO/Sick hours?
 * Weekends never do. Holidays don't either, except that with holiday pay on, an
 * unpaid holiday (a closure day the company doesn't pay) does: an employee can cover
 * it with PTO, and someone on vacation that week is paid for it from PTO.
 */
function isTimeOffDay(string $date): bool
{
    if ((int) (new DateTime($date))->format('N') >= 6) {
        return false;
    }
    holidayRules(); // load (and reset the caches) once per request
    $paid = &holidayPaidCache();
    $year = (int) substr($date, 0, 4);
    foreach ([$year - 1, $year, $year + 1] as $y) {
        $paid[$y] ??= holidayPaidDates($y);
        if (isset($paid[$y][$date])) {
            return !$paid[$y][$date] && holidayPayEnabled();
        }
    }
    return true;
}

/** Number of days in [$start, $end] inclusive that full-day time off is charged for (isTimeOffDay). */
function countTimeOffDays(string $start, string $end): int
{
    $n = 0;
    for ($d = new DateTime($start), $fin = new DateTime($end); $d <= $fin; $d->modify('+1 day')) {
        if (isTimeOffDay($d->format('Y-m-d'))) {
            $n++;
        }
    }
    return $n;
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
    holidayRules(); // load (and reset the calendar cache) once per request
    $byYear = &holidayCalendarCache();
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
