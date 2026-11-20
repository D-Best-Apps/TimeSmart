-- Migration: closing payroll (Payroll Totals -> Close payroll) saves the totals as closed.
-- The lock already stopped punch and time-off edits, but PTO, Sick and Holiday hours are
-- worked out from the holiday list and holiday-pay settings each time, so a later
-- holiday change moved the figures of a period already paid. Payroll Totals now shows
-- these saved figures for a closed period and marks any that differ from today's.
--   payroll_closes       one row per close: the dates, when and by whom
--   payroll_close_lines  one row per employee: the hours as they were at close
-- Safe to run on existing installs; new installs receive this via timeclock-schema.sql.

CREATE TABLE IF NOT EXISTS `payroll_closes` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `PeriodStart` date NOT NULL,
  `PeriodEnd` date NOT NULL,
  `ClosedAt` datetime NOT NULL,
  `ClosedByID` int(11) DEFAULT NULL,
  `ClosedByName` varchar(255) DEFAULT NULL,
  `HolidayPay` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`ID`),
  KEY `Period` (`PeriodStart`, `PeriodEnd`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `payroll_close_lines` (
  `CloseID` int(11) NOT NULL,
  `EmployeeID` int(11) NOT NULL,
  `EmployeeName` varchar(255) NOT NULL,
  `Regular` decimal(8,2) NOT NULL DEFAULT 0.00,
  `Overtime` decimal(8,2) NOT NULL DEFAULT 0.00,
  `PTO` decimal(8,2) NOT NULL DEFAULT 0.00,
  `Sick` decimal(8,2) NOT NULL DEFAULT 0.00,
  `Holiday` decimal(8,2) NOT NULL DEFAULT 0.00,
  `Total` decimal(8,2) NOT NULL DEFAULT 0.00,
  `Unfinished` int(11) NOT NULL DEFAULT 0,
  `HolidayDays` text DEFAULT NULL,
  PRIMARY KEY (`CloseID`, `EmployeeID`),
  CONSTRAINT `payroll_close_lines_close` FOREIGN KEY (`CloseID`) REFERENCES `payroll_closes` (`ID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
