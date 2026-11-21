<?php
/**
 * functions/timesheet_time_off.php — time off and holidays changed from the admin
 * timesheet (admin/view_punches.php, Edit; saved by admin/save_punches.php):
 *   xhours[hol|DATE]        holiday hours for this employee on that paid holiday
 *                           (holiday_pay_adjustments, the same as Holiday hours by employee)
 *   xhours[tor|ID|DATE]     one day of a time-off entry (time_off_day_hours): what the day
 *                           pays for PTO/Sick, the hours missed for an absence
 *   blank = back to the rules. xnote[...] is the why; xorig/xorignote are the values the
 *   page was drawn with, so only changed lines are saved.
 *   addto[date|cat|hours|note]  time off or an absence on one day, saved as approved
 * Every change is logged in punch_changelog (Change History) and refused inside a closed payroll.
 */
require_once __DIR__ . '/time_off_hours.php';
require_once __DIR__ . '/holiday_pay.php';
require_once __DIR__ . '/pay_rules.php';

/**
 * Save the time-off / holiday parts of a timesheet post. Runs inside the caller's
 * transaction. Problems are added to $skipped (shown above the timesheet).
 * @return int[] IDs of time-off entries added (for syncTimesheetTimeOff() after commit)
 */
function saveTimesheetTimeOff(mysqli $conn, int $employeeID, string $from, string $to, string $admin, array &$skipped): array
{
    $isDate = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)
        && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4));
    $fmt = fn($h) => rtrim(rtrim(number_format((float) $h, 2, '.', ''), '0'), '.');
    $now = date('Y-m-d H:i:s');
    $log = $conn->prepare("INSERT INTO punch_changelog (EmployeeID, Date, ChangedBy, FieldChanged, OldValue, NewValue, Reason) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $writeLog = function (string $date, string $field, string $old, string $new, string $why) use ($log, $employeeID, $admin) {
        $field = mb_substr($field, 0, 50); // punch_changelog.FieldChanged
        $log->bind_param("issssss", $employeeID, $date, $admin, $field, $old, $new, $why);
        $log->execute();
    };

    // --- Hours on existing lines -------------------------------------------------------
    $policy = holidayPayPolicy($conn);
    $changedDayHours = false;
    foreach ((array) ($_POST['xhours'] ?? []) as $field => $val) {
        $field    = (string) $field;
        $val      = trim((string) $val);
        $note     = mb_substr(trim((string) ($_POST['xnote'][$field] ?? '')), 0, 255);
        $orig     = trim((string) ($_POST['xorig'][$field] ?? ''));
        $origNote = trim((string) ($_POST['xorignote'][$field] ?? ''));
        $same = ($val === '' && $orig === '') || ($val !== '' && $orig !== '' && is_numeric($val) && abs((float) $val - (float) $orig) < 0.005);
        if ($same && $note === $origNote) {
            continue; // untouched
        }
        $parts = explode('|', $field);
        $date  = (string) end($parts);
        if (!$isDate($date)) {
            continue;
        }
        $when = date('m/d/Y', strtotime($date));
        if (isPayrollLocked($conn, $date)) {
            $skipped[] = "$when: payroll for this date is closed";
            continue;
        }
        if ($val !== '' && (!is_numeric($val) || (float) $val < 0 || (float) $val > 24)) {
            $skipped[] = "$when: hours must be a number from 0 to 24 (or blank for the rules)";
            continue;
        }
        $hours = $val === '' ? null : round((float) $val, 2);
        $why   = $note !== '' ? $note : 'Set on the timesheet';

        if ($parts[0] === 'hol' && count($parts) === 2) {
            $holidays = $policy['enabled'] ? paidHolidaysInRange($date, $date) : [];
            if (!isset($holidays[$date])) {
                $skipped[] = "$when: not a paid company holiday" . ($policy['enabled'] ? '' : ' (holiday pay is off)');
                continue;
            }
            if ($hours === null) {
                $st = $conn->prepare("DELETE FROM holiday_pay_adjustments WHERE EmployeeID = ? AND HolidayDate = ?");
                $st->bind_param("is", $employeeID, $date);
            } else {
                $st = $conn->prepare("INSERT INTO holiday_pay_adjustments (EmployeeID, HolidayDate, Hours, Note, AdjustedBy, AdjustedAt) VALUES (?, ?, ?, ?, ?, ?)
                                      ON DUPLICATE KEY UPDATE Hours = VALUES(Hours), Note = VALUES(Note), AdjustedBy = VALUES(AdjustedBy), AdjustedAt = VALUES(AdjustedAt)");
                $noteVal = $note !== '' ? $note : null;
                $st->bind_param("isdsss", $employeeID, $date, $hours, $noteVal, $admin, $now);
            }
            $st->execute();
            $writeLog($date, 'Holiday hours: ' . $holidays[$date],
                $orig !== '' ? $fmt($orig) : 'by the rules', $hours !== null ? $fmt($hours) : 'by the rules', $why);
        } elseif ($parts[0] === 'tor' && count($parts) === 3) {
            $rid = (int) $parts[1];
            $st = $conn->prepare("SELECT ID, Category, StartDate, EndDate FROM time_off_requests WHERE ID = ? AND EmployeeID = ? AND Status = 'Approved'");
            $st->bind_param("ii", $rid, $employeeID);
            $st->execute();
            $req = $st->get_result()->fetch_assoc();
            if (!$req || $date < $req['StartDate'] || $date > $req['EndDate']) {
                $skipped[] = "$when: that time off was changed or cancelled meanwhile; reload the timesheet";
                continue;
            }
            $paid = timeOffIsPaid($req);
            if (!$paid && $hours !== null && $hours <= 0) {
                $hours = null; // an absence of 0 hours missed: back to the entry
            }
            if ($hours === null) {
                $st = $conn->prepare("DELETE FROM time_off_day_hours WHERE RequestID = ? AND Date = ?");
                $st->bind_param("is", $rid, $date);
            } else {
                $st = $conn->prepare("INSERT INTO time_off_day_hours (RequestID, Date, Hours, Note, AdjustedBy, AdjustedAt) VALUES (?, ?, ?, ?, ?, ?)
                                      ON DUPLICATE KEY UPDATE Hours = VALUES(Hours), Note = VALUES(Note), AdjustedBy = VALUES(AdjustedBy), AdjustedAt = VALUES(AdjustedAt)");
                $noteVal = $note !== '' ? $note : null;
                $st->bind_param("isdsss", $rid, $date, $hours, $noteVal, $admin, $now);
            }
            $st->execute();
            $changedDayHours = true;
            $writeLog($date, timeOffShortLabel($req['Category']) . ($paid ? ' hours' : ' hours missed'),
                $orig !== '' ? $fmt($orig) : 'by the entry', $hours !== null ? $fmt($hours) : 'by the entry', $why);
        }
    }
    if ($changedDayHours) {
        timeOffDayHours(true);
    }

    // --- Time off added on one day -----------------------------------------------------
    $add  = (array) ($_POST['addto'] ?? []);
    $date = trim((string) ($add['date'] ?? ''));
    if ($date === '') {
        return [];
    }
    $cat   = (string) ($add['cat'] ?? '');
    $hIn   = trim((string) ($add['hours'] ?? ''));
    $note  = trim((string) ($add['note'] ?? ''));
    $when  = $isDate($date) ? date('m/d/Y', strtotime($date)) : $date;
    $fromD = date('Y-m-d', strtotime($from));
    $toD   = date('Y-m-d', strtotime($to));
    $problem = null;
    if (!$isDate($date) || $date < $fromD || $date > $toD) {
        $problem = 'pick a day on this timesheet';
    } elseif (!isset(timeOffCategories(true)[$cat])) {
        $problem = 'pick the type of time off';
    } elseif ($hIn !== '' && (!is_numeric($hIn) || (float) $hIn <= 0 || (float) $hIn > 24)) {
        $problem = 'hours must be more than 0 and at most 24, or blank for a full day';
    } elseif (in_array($cat, TIME_OFF_UNPAID, true) && $note === '') {
        $problem = 'give the reason for the absence';
    } elseif (mb_strlen($note) > 500) {
        $problem = 'keep the reason under 500 characters';
    } elseif (isPayrollLocked($conn, $date)) {
        $problem = 'payroll for this date is closed';
    }
    if ($problem === null) {
        $ov = $conn->prepare("SELECT Category, Status FROM time_off_requests WHERE EmployeeID = ? AND Status IN ('Pending','Approved')
                               AND StartDate <= ? AND EndDate >= ? LIMIT 1");
        $ov->bind_param("iss", $employeeID, $date, $date);
        $ov->execute();
        if ($o = $ov->get_result()->fetch_assoc()) {
            $problem = $o['Status'] === 'Pending'
                ? 'there is already a pending time-off request that day (approve or change it on Pending Approvals)'
                : 'there is already ' . timeOffLabel($o['Category']) . ' that day; change its hours on that line instead';
        }
    }
    if ($problem !== null) {
        $skipped[] = "Time off on $when not added: $problem";
        return [];
    }

    $hours   = $hIn !== '' ? round((float) $hIn, 2) : null;
    $notes   = $note !== '' ? $note : null;
    $review  = 'Recorded by ' . $admin . ' on the timesheet';
    $st = $conn->prepare("INSERT INTO time_off_requests
        (EmployeeID, Category, StartDate, EndDate, StartTime, EndTime, Hours, Notes, Status, SubmittedAt, ReviewedAt, ReviewedBy, ReviewNote)
        VALUES (?, ?, ?, ?, NULL, NULL, ?, ?, 'Approved', ?, ?, ?, ?)");
    $st->bind_param("isssdsssss", $employeeID, $cat, $date, $date, $hours, $notes, $now, $now, $admin, $review);
    $st->execute();
    $newID = (int) $conn->insert_id;

    // A day the entry wouldn't normally charge (off the shift's days, a weekend, a holiday):
    // the admin picked this day, so it counts, with the hours given or a full day's.
    foreach (fetchApprovedTimeOff($conn, $date, $date, $employeeID) as $row) {
        if ((int) $row['ID'] !== $newID) {
            continue;
        }
        $plan = timeOffDayPlan($row);
        if (!timeOffChargedDay($plan, $date)) {
            $dayHours = timeOffIsPaid($row) ? ($hours ?? $plan['dayHours']) : ($hours ?? 0.0);
            $o = $conn->prepare("INSERT INTO time_off_day_hours (RequestID, Date, Hours, Note, AdjustedBy, AdjustedAt) VALUES (?, ?, ?, ?, ?, ?)");
            $why = 'Added on the timesheet';
            $o->bind_param("isdsss", $newID, $date, $dayHours, $why, $admin, $now);
            $o->execute();
            timeOffDayHours(true);
        }
    }
    $writeLog($date, 'Time off added', '', timeOffDescribe(['Category' => $cat, 'Hours' => $hours], false), $note !== '' ? $note : 'Added on the timesheet');
    return [$newID];
}

/** After the save is committed: put time off added on the timesheet on the Outlook calendar. */
function syncTimesheetTimeOff(mysqli $conn, array $requestIDs): void
{
    if (!$requestIDs) {
        return;
    }
    require_once __DIR__ . '/m365_calendar.php';
    foreach ($requestIDs as $id) {
        $id = (int) $id;
        $row = $conn->query("SELECT tor.*, u.FirstName, u.LastName FROM time_off_requests tor JOIN users u ON u.ID = tor.EmployeeID WHERE tor.ID = $id")->fetch_assoc();
        if (!$row) {
            continue;
        }
        try {
            $sync = m365SyncApprovedRequest($conn, $row, $row['FirstName'] . ' ' . $row['LastName']);
            $state = $sync['success'] ? 'sent' : (!empty($sync['skipped']) ? 'skipped' : 'error:' . ($sync['error'] ?? 'unknown'));
            $eventId = $sync['success'] ? $sync['eventId'] : null;
            $now = date('Y-m-d H:i:s');
            $up = $conn->prepare("UPDATE time_off_requests SET M365EventId = ?, M365SyncStatus = ?, M365SyncAt = ? WHERE ID = ?");
            $up->bind_param("sssi", $eventId, $state, $now, $id);
            $up->execute();
        } catch (Throwable $e) {
            error_log('syncTimesheetTimeOff: ' . $e->getMessage());
        }
    }
}
