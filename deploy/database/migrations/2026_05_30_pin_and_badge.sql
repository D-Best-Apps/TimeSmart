-- Split the old single "TagID" into two distinct quick-clock identifiers:
--   * BadgeID  - the scannable barcode value (this is the renamed TagID; the
--                kiosk and the new main-screen badge box both look it up).
--   * PIN      - a typed 4-6 digit code for quick clock in/out.
--
-- PIN is UNIQUE on `users` (MySQL allows multiple NULLs, so it stays optional)
-- so a PIN resolves to exactly one employee. The columns are mirrored in the
-- positional `user-archive` copy (see admin/archive_user.php's INSERT ... SELECT *)
-- so the two tables stay name-aligned; the archive PIN is intentionally not unique
-- (archived rows may legitimately collide with re-used PINs).

ALTER TABLE `users`
  CHANGE `TagID` `BadgeID` varchar(50) DEFAULT NULL,
  ADD COLUMN `PIN` varchar(6) DEFAULT NULL AFTER `BadgeID`,
  ADD UNIQUE KEY `PIN` (`PIN`);

ALTER TABLE `user-archive`
  CHANGE `TagID` `BadgeID` varchar(50) DEFAULT NULL,
  ADD COLUMN `PIN` varchar(6) DEFAULT NULL AFTER `BadgeID`;
