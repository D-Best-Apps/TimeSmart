-- Migration: holiday pay (Settings -> Holidays).
--   holidays.Paid            this holiday earns holiday pay (when holiday pay is on)
--   HolidayPayEnabled        0/1, off until an admin turns it on
--   HolidayPayMode           'shift' = the employee's scheduled shift hours less lunch,
--                            'fixed' = HolidayPayFixedHours for everyone
--   HolidayPayFixedHours     hours for 'fixed', and for employees without a shift
--   HolidayPayDayOff         'none' = no pay when the holiday is on the employee's day off,
--                            'pay'  = pay it anyway
--   HolidayPayRequireAdjacent 0/1, must work the scheduled day before and after (time off counts)
-- Safe to run on existing installs; new installs receive this via timeclock-schema.sql.

ALTER TABLE `holidays`
  ADD COLUMN IF NOT EXISTS `Paid` tinyint(1) NOT NULL DEFAULT 1 AFTER `ObserveWeekday`;

INSERT IGNORE INTO `settings` (`SettingKey`, `SettingValue`) VALUES
  ('HolidayPayEnabled', '0'),
  ('HolidayPayMode', 'shift'),
  ('HolidayPayFixedHours', '8'),
  ('HolidayPayDayOff', 'none'),
  ('HolidayPayRequireAdjacent', '0');
