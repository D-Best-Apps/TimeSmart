-- Migration: company holidays are now edited on Settings -> Holidays instead of being
-- hard-coded. Starts every install with the six holidays the code used to define, so
-- nothing changes until an admin edits the list.
--   RuleType  fixed  = same month/day every year (Month, Day)
--             nth    = Nth weekday of a month (Month, Day = 1-4, Weekday 1=Mon..7=Sun)
--             last   = last weekday of a month (Month, Weekday)
--             easter = Easter Sunday
--             once   = one date only (OnceDate)
--   OffsetDays     days added to the rule's date (Day after Thanksgiving = +1, Good Friday = -2)
--   ObserveWeekday a holiday on Saturday is observed Friday, on Sunday the Monday after
--   PresetKey      which built-in preset this row came from (NULL for custom holidays)
-- Safe to run on existing installs; new installs receive this via timeclock-schema.sql.

CREATE TABLE IF NOT EXISTS `holidays` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `Name` varchar(100) NOT NULL,
  `RuleType` enum('fixed','nth','last','easter','once') NOT NULL,
  `Month` tinyint(4) DEFAULT NULL,
  `Day` tinyint(4) DEFAULT NULL,
  `Weekday` tinyint(4) DEFAULT NULL,
  `OffsetDays` smallint(6) NOT NULL DEFAULT 0,
  `OnceDate` date DEFAULT NULL,
  `ObserveWeekday` tinyint(1) NOT NULL DEFAULT 1,
  `PresetKey` varchar(40) DEFAULT NULL,
  PRIMARY KEY (`ID`),
  UNIQUE KEY `PresetKey` (`PresetKey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `holidays` (`Name`, `RuleType`, `Month`, `Day`, `Weekday`, `OffsetDays`, `ObserveWeekday`, `PresetKey`) VALUES
  ('New Year''s Day',   'fixed', 1,  1,    NULL, 0, 1, 'new_year'),
  ('Memorial Day',      'last',  5,  NULL, 1,    0, 1, 'memorial'),
  ('Independence Day',  'fixed', 7,  4,    NULL, 0, 1, 'independence'),
  ('Labor Day',         'nth',   9,  1,    1,    0, 1, 'labor'),
  ('Thanksgiving',      'nth',   11, 4,    4,    0, 1, 'thanksgiving'),
  ('Christmas Day',     'fixed', 12, 25,   NULL, 0, 1, 'christmas');
