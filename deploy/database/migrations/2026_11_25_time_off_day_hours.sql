-- Migration: time-off hours set by hand for one day of an entry (admin timesheet, Edit).
-- A PTO or Sick entry pays its hours on each day it covers (Settings > Pay Rules > Time off).
-- An admin can set one day's hours on the timesheet without touching the rest of the entry,
-- e.g. 6 instead of 10 on the Tuesday of a week off. For an excused or unexcused absence
-- the hours are the hours missed (shown only; absences pay nothing).
--   RequestID  the time_off_requests entry
--   Date       the day (inside the entry's dates)
--   Hours      hours for that day; 0 = none that day
-- Safe to run on existing installs; new installs receive this via timeclock-schema.sql.

CREATE TABLE IF NOT EXISTS `time_off_day_hours` (
  `RequestID` int(11) NOT NULL,
  `Date` date NOT NULL,
  `Hours` decimal(5,2) NOT NULL,
  `Note` varchar(255) DEFAULT NULL,
  `AdjustedBy` varchar(100) DEFAULT NULL,
  `AdjustedAt` datetime NOT NULL,
  PRIMARY KEY (`RequestID`, `Date`),
  CONSTRAINT `time_off_day_hours_request` FOREIGN KEY (`RequestID`) REFERENCES `time_off_requests` (`ID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
