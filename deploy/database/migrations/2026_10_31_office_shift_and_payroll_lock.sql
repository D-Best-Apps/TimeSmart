-- Migration: default shift per office, and payroll period locking
--   Offices.DefaultShiftID  pre-selects the shift when adding an employee to that office
--   PayrollLockedThrough    punches dated on/before this day can't be edited (payroll was run)
-- Safe to run on existing installs; new installs receive this via timeclock-schema.sql.

ALTER TABLE `Offices`
  ADD COLUMN IF NOT EXISTS `DefaultShiftID` int(11) DEFAULT NULL AFTER `AllowedIPs`;

INSERT IGNORE INTO `settings` (`SettingKey`, `SettingValue`) VALUES
  ('PayrollLockedThrough', NULL);
