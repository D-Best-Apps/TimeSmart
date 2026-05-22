<?php
/**
 * Admin login is unified with employee login under app/user/login.php.
 * This file exists only to redirect any old bookmarks / form posts.
 *
 * To grant admin access to a user, set their Role in app/admin/manage_admins.php
 * (or directly: UPDATE users SET Role = 'super_admin' WHERE FirstName = ? AND LastName = ?).
 */

header("Location: ../user/login.php?admin=1", true, 302);
exit;
