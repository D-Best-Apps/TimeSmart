-- Migration: time off keeps the shift it was taken under.
-- With "Hours per day of time off" set to the employee's shift (Settings > Pay Rules),
-- a day of PTO or Sick pays that shift's hours on that shift's days. It used the
-- employee's current shift, so changing someone's shift rewrote their past time off.
--   time_off_requests.ShiftID  NULL = follows the employee's current shift;
--                              otherwise the shift it was frozen to (0 = no shift).
-- Changing an employee's shift freezes their time off dated before today to the old one.
-- Safe to run on existing installs; new installs receive this via timeclock-schema.sql.

ALTER TABLE `time_off_requests`
  ADD COLUMN IF NOT EXISTS `ShiftID` int(11) DEFAULT NULL AFTER `Hours`;
