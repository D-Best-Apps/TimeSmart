<?php
// functions/pending_count.php

/**
 * Returns the number of items that actually need admin review:
 *   - pending timesheet edits whose requested values differ from the current
 *     punch (no-op edits that match the existing punch are excluded, since the
 *     approvals page hides them), and
 *   - pending time-off requests.
 *
 * This is the single source of truth for the "Pending Approvals" badge and the
 * dashboard counters, so they never disagree with what the approvals page shows.
 *
 * @param mysqli $conn
 * @return int
 */
function getPendingApprovalCount($conn): int {
    $sql = "
        SELECT
          (SELECT COUNT(*) FROM pending_edits pe
             WHERE pe.Status = 'Pending'
               AND EXISTS (
                   SELECT 1 FROM timepunches tp
                    WHERE tp.EmployeeID = pe.EmployeeID
                      AND tp.Date = pe.Date
                      -- compare at minute precision: punches store seconds while
                      -- requests come from an HH:MM form, so raw comparisons would
                      -- count phantom edits that change nothing.
                      AND (
                          (pe.TimeIN     IS NOT NULL AND pe.TimeIN     <> '' AND NOT (TIME_FORMAT(pe.TimeIN,     '%H:%i') <=> TIME_FORMAT(tp.TimeIN,     '%H:%i'))) OR
                          (pe.LunchStart IS NOT NULL AND pe.LunchStart <> '' AND NOT (TIME_FORMAT(pe.LunchStart, '%H:%i') <=> TIME_FORMAT(tp.LunchStart, '%H:%i'))) OR
                          (pe.LunchEnd   IS NOT NULL AND pe.LunchEnd   <> '' AND NOT (TIME_FORMAT(pe.LunchEnd,   '%H:%i') <=> TIME_FORMAT(tp.LunchEnd,   '%H:%i'))) OR
                          (pe.TimeOut    IS NOT NULL AND pe.TimeOut    <> '' AND NOT (TIME_FORMAT(pe.TimeOut,    '%H:%i') <=> TIME_FORMAT(tp.TimeOut,    '%H:%i')))
                      )
               )
          )
          + (SELECT COUNT(*) FROM time_off_requests WHERE Status = 'Pending') AS count
    ";
    $res = $conn->query($sql);
    if ($res && $row = $res->fetch_assoc()) {
        return (int) $row['count'];
    }
    return 0;
}
