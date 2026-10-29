-- Migration: add users.ScheduledStartTime
-- admin/add_user.php writes this column and the tardies reports (admin/tardies.php and
-- its Excel/PDF exports) read it, but it was never added to timeclock-schema.sql or a
-- migration. On a fresh install, Add User failed with "Unknown column 'ScheduledStartTime'".
-- Safe to run on existing installs where the column was added by hand; new installs
-- receive it via timeclock-schema.sql.

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `ScheduledStartTime` TIME DEFAULT NULL AFTER `NotifyTimesheetEdits`;

-- add_user.php used to store a blank start time as '' rather than NULL. If the column
-- was hand-added as a string type, those rows would count every clock-in as a tardy
-- (the reports filter on IS NOT NULL). CAST keeps this a no-op on a TIME column,
-- where a midnight start reads as '00:00:00', never ''.
UPDATE `users`
   SET `ScheduledStartTime` = NULL
 WHERE CAST(`ScheduledStartTime` AS CHAR) = '';
