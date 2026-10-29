-- Migration: shifts, punch rounding, auto-lunch and pay rules
-- See docs/SHIFT_ROUNDING_PLAN.md. Raw punch times are never rewritten; the rules
-- engine (app/functions/pay_rules.php) stores the paid times beside them and writes
-- paid hours to TotalHours, which every report already reads.
-- Safe to run on existing installs: no employee has a shift until one is assigned,
-- and the defaults below reproduce today's behavior exactly (Wed-Tue week, OT > 40,
-- no rounding). New installs receive this via timeclock-schema.sql.

CREATE TABLE IF NOT EXISTS `shifts` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `Name` varchar(100) NOT NULL,
  `Color` varchar(7) NOT NULL DEFAULT '#0a4a6b',
  `StartTime` time NOT NULL,
  `EndTime` time NOT NULL,
  `Days` set('Mon','Tue','Wed','Thu','Fri','Sat','Sun') NOT NULL DEFAULT 'Mon,Tue,Wed,Thu,Fri',
  `EarlyInMinutes` smallint(6) NOT NULL DEFAULT 0,
  `LateInGraceMinutes` smallint(6) NOT NULL DEFAULT 0,
  `EarlyOutGraceMinutes` smallint(6) NOT NULL DEFAULT 0,
  `LateOutMinutes` smallint(6) NOT NULL DEFAULT 0,
  `OutsidePolicy` enum('actual','cap') NOT NULL DEFAULT 'actual',
  `RoundIncrement` tinyint(4) NOT NULL DEFAULT 0,
  `RoundMode` enum('nearest','up','down') NOT NULL DEFAULT 'nearest',
  `LunchMode` enum('punched','auto','auto_unless_punched') NOT NULL DEFAULT 'punched',
  `LunchMinutes` smallint(6) NOT NULL DEFAULT 30,
  `LunchMinWorkedHours` decimal(4,2) NOT NULL DEFAULT 6.00,
  `Archived` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `ShiftID` int(11) DEFAULT NULL AFTER `ScheduledStartTime`;

ALTER TABLE `timepunches`
  ADD COLUMN IF NOT EXISTS `ShiftID` int(11) DEFAULT NULL AFTER `TotalHours`,
  ADD COLUMN IF NOT EXISTS `PaidIn` time DEFAULT NULL AFTER `ShiftID`,
  ADD COLUMN IF NOT EXISTS `PaidOut` time DEFAULT NULL AFTER `PaidIn`,
  ADD COLUMN IF NOT EXISTS `LunchDeductMin` smallint(6) DEFAULT NULL AFTER `PaidOut`,
  ADD COLUMN IF NOT EXISTS `LunchWaived` tinyint(1) NOT NULL DEFAULT 0 AFTER `LunchDeductMin`,
  ADD COLUMN IF NOT EXISTS `OutsideApproved` tinyint(1) NOT NULL DEFAULT 0 AFTER `LunchWaived`,
  ADD COLUMN IF NOT EXISTS `RawHours` decimal(5,2) DEFAULT NULL AFTER `OutsideApproved`;

-- Until the engine has run on a row, its paid hours are its raw hours.
UPDATE `timepunches` SET `RawHours` = `TotalHours` WHERE `RawHours` IS NULL;

-- Pay rules. INSERT IGNORE keeps anything an admin already saved.
INSERT IGNORE INTO `settings` (`SettingKey`, `SettingValue`) VALUES
  ('PayWeekStartDay', '3'),
  ('PayPeriodType', 'weekly'),
  ('PayPeriodAnchor', NULL),
  ('OvertimeWeeklyHours', '40'),
  ('OvertimeDailyHours', '0'),
  ('DefaultRoundIncrement', '0'),
  ('DefaultRoundMode', 'nearest');
