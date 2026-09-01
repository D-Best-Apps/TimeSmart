-- Migration: allow an approved time-off request to be cancelled
--
-- Until now the Status enum had no terminal state for "this was approved, but
-- it's not happening after all". Admins had no way to undo an approval, which
-- also meant the M365 calendar event lived on forever (and, with employee
-- invites enabled, stayed on the employee's own calendar).
--
-- 'Cancelled' is deliberately a separate status from 'Withdrawn' (employee
-- pulling a *pending* request) and 'Rejected' (admin declining a *pending*
-- request), so reporting can tell the three apart.
--
-- Every existing query filters on 'Approved' or 'Pending' explicitly, so
-- cancelled rows fall out of hours totals and overlap checks automatically.
--
-- Safe to run on any install — enum widening only, no data change.

ALTER TABLE `time_off_requests`
  MODIFY COLUMN `Status`
    ENUM('Pending','Approved','Rejected','Withdrawn','Cancelled')
    NOT NULL DEFAULT 'Pending';
