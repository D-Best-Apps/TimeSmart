-- Migration: time-off and holiday options (client requests, Nov 2026).
--   users.EmployeeNumber         the company's own employee # (shown on Home; not used to clock in)
--   users.HolidayHours           holiday pay hours for this employee (blank = the holiday-pay setting)
--   time_off_requests.Category   gains 'Unexcused': an unexcused absence, unpaid, shown as unexcused
--   time_off_requests.Hours      hours per day for a part day entered as a number of hours
--                                (no start/end time); NULL = full day or a time window
--   holiday_moves                observe one year's holiday on another date (Settings -> Holidays)
--   holiday_pay_adjustments      holiday hours set by hand for one employee on one holiday
--   TimeOffDayHoursMode          'fixed' = full-day time off pays TimeOffDayHours;
--                                'shift' = the employee's shift hours less lunch, on the shift's days
--   TimeOffDayHours              hours for a full day of time off ('fixed', and employees without a shift)
--   TimeOffSickEnabled           0/1, offer the Sick category
--   HolidayPayCountsOT           0/1, holiday hours count toward the weekly overtime threshold
-- Safe to run on existing installs; new installs receive this via timeclock-schema.sql.

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `EmployeeNumber` varchar(20) DEFAULT NULL AFTER `PIN`,
  ADD COLUMN IF NOT EXISTS `HolidayHours` decimal(5,2) DEFAULT NULL AFTER `ShiftID`;
ALTER TABLE `users`
  ADD UNIQUE KEY IF NOT EXISTS `EmployeeNumber` (`EmployeeNumber`);

ALTER TABLE `time_off_requests`
  MODIFY COLUMN `Category` enum('Sick','PTO','Unpaid','Unexcused') NOT NULL,
  ADD COLUMN IF NOT EXISTS `Hours` decimal(5,2) DEFAULT NULL AFTER `EndTime`;

CREATE TABLE IF NOT EXISTS `holiday_moves` (
  `HolidayID` int(11) NOT NULL,
  `Year` smallint(6) NOT NULL,
  `MovedTo` date NOT NULL,
  PRIMARY KEY (`HolidayID`, `Year`),
  CONSTRAINT `holiday_moves_holiday` FOREIGN KEY (`HolidayID`) REFERENCES `holidays` (`ID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `holiday_pay_adjustments` (
  `EmployeeID` int(11) NOT NULL,
  `HolidayDate` date NOT NULL,
  `Hours` decimal(5,2) NOT NULL,
  `Note` varchar(255) DEFAULT NULL,
  `AdjustedBy` varchar(100) DEFAULT NULL,
  `AdjustedAt` datetime NOT NULL,
  PRIMARY KEY (`EmployeeID`, `HolidayDate`),
  CONSTRAINT `holiday_pay_adjustments_employee` FOREIGN KEY (`EmployeeID`) REFERENCES `users` (`ID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `settings` (`SettingKey`, `SettingValue`) VALUES
  ('TimeOffDayHoursMode', 'fixed'),
  ('TimeOffDayHours', '8'),
  ('TimeOffSickEnabled', '1'),
  ('HolidayPayCountsOT', '0');
