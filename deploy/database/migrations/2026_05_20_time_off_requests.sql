-- Migration: digital time-off request workflow
-- Adds the time_off_requests table used by app/user/time_off.php (employee submission + history),
-- app/admin/edits_timesheet.php (admin queue), and app/admin/process_time_off.php (decision handler).
-- Safe to run on existing installs (IF NOT EXISTS). New installs receive this via timeclock-schema.sql.

CREATE TABLE IF NOT EXISTS `time_off_requests` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `EmployeeID` int(11) NOT NULL,
  `Category` enum('Sick','PTO') NOT NULL,
  `StartDate` date NOT NULL,
  `EndDate` date NOT NULL,
  `StartTime` time DEFAULT NULL,
  `EndTime` time DEFAULT NULL,
  `Notes` varchar(500) DEFAULT NULL,
  `Status` enum('Pending','Approved','Rejected','Withdrawn') NOT NULL DEFAULT 'Pending',
  `SubmittedAt` datetime NOT NULL,
  `ReviewedAt` datetime DEFAULT NULL,
  `ReviewedBy` varchar(100) DEFAULT NULL,
  `ReviewNote` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`ID`),
  KEY `idx_tor_status` (`Status`),
  KEY `idx_tor_emp_status` (`EmployeeID`, `Status`),
  KEY `idx_tor_dates` (`StartDate`, `EndDate`),
  CONSTRAINT `fk_tor_employee` FOREIGN KEY (`EmployeeID`) REFERENCES `users` (`ID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
