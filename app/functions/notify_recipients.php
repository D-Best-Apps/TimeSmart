<?php
// Who receives each kind of admin notification.
//
// Recipients come from two places, combined and deduplicated:
//   1. Admin users who ticked the box for this notification type (users.Notify*).
//   2. A comma-separated list of extra addresses in the settings table, for shared
//      mailboxes like hr@ that aren't user accounts.
// Configured in app/admin/settings.php under "Notification Recipients".

// type => [users column, settings key for the extra-address list]
const NOTIFY_TYPES = [
    'timeoff'         => ['NotifyTimeOff',         'notify_timeoff_extra'],
    'timesheet_edits' => ['NotifyTimesheetEdits',  'notify_timesheet_edits_extra'],
];

/**
 * Resolve the recipient list for a notification type.
 * Returns a deduplicated (case-insensitive) list of valid addresses; may be empty,
 * which callers must treat as "notify nobody", not as an error.
 */
function notificationRecipients(mysqli $conn, string $type): array {
    if (!isset(NOTIFY_TYPES[$type])) {
        error_log("notificationRecipients: unknown notification type '{$type}'.");
        return [];
    }
    [$column, $settingKey] = NOTIFY_TYPES[$type];

    $addresses = [];

    // Opted-in admins. The column name comes from the constant above, never from
    // user input, so interpolating it here is safe.
    $sql = "SELECT Email FROM users
             WHERE `{$column}` = 1
               AND Role IN ('super_admin', 'reports_only')
               AND Email IS NOT NULL AND Email <> ''";
    if ($res = $conn->query($sql)) {
        while ($row = $res->fetch_assoc()) {
            $addresses[] = $row['Email'];
        }
    }

    // Extra addresses, comma-separated.
    $stmt = $conn->prepare("SELECT SettingValue FROM settings WHERE SettingKey = ? LIMIT 1");
    $stmt->bind_param("s", $settingKey);
    $stmt->execute();
    if ($row = $stmt->get_result()->fetch_assoc()) {
        foreach (explode(',', (string)($row['SettingValue'] ?? '')) as $extra) {
            $addresses[] = $extra;
        }
    }

    $unique = [];
    foreach ($addresses as $address) {
        $address = trim((string) $address);
        if ($address === '' || !filter_var($address, FILTER_VALIDATE_EMAIL)) continue;
        // First occurrence wins, so an admin's own record keeps its casing.
        $unique[strtolower($address)] ??= $address;
    }

    return array_values($unique);
}

/**
 * Admin users eligible to appear as checkboxes on the settings page, with their
 * current opt-in state for each notification type.
 */
function notificationAdminUsers(mysqli $conn): array {
    $cols = [];
    foreach (NOTIFY_TYPES as [$column, $_]) {
        $cols[] = "`{$column}`";
    }
    $sql = "SELECT ID, FirstName, LastName, Email, Role, " . implode(', ', $cols) . "
              FROM users
             WHERE Role IN ('super_admin', 'reports_only')
             ORDER BY Role DESC, LastName, FirstName";

    $users = [];
    if ($res = $conn->query($sql)) {
        while ($row = $res->fetch_assoc()) {
            $users[] = $row;
        }
    }
    return $users;
}
