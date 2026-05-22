<?php
// Microsoft Graph integration for posting approved time-off requests to a
// shared M365 Group calendar (the "PTO Calendar").
// Uses client credentials (app-only) auth. Configuration is stored in the
// settings table; the client secret is AES-encrypted using the same scheme
// as mail_password (see app/admin/settings.php encrypt_data/decrypt_data).

const M365_ENCRYPTION_KEY = 'a_very_secret_key_for_encryption_32_chars';
const M365_CIPHER_METHOD  = 'aes-256-cbc';

/**
 * Decrypt the client secret stored in settings (same scheme as mail_password).
 */
function m365DecryptSecret(string $encrypted): ?string {
    $parts = explode('::', base64_decode($encrypted), 2);
    if (count($parts) !== 2) return null;
    [$enc, $iv] = $parts;
    $val = openssl_decrypt($enc, M365_CIPHER_METHOD, M365_ENCRYPTION_KEY, 0, $iv);
    return $val !== false ? $val : null;
}

/**
 * Load and validate M365 config from the settings table.
 * Returns null if disabled or missing required fields. Returns the config
 * (with client secret decrypted) on success.
 */
function m365GetConfig(mysqli $conn): ?array {
    $keys = ['m365_enabled', 'm365_tenant_id', 'm365_client_id',
             'm365_client_secret', 'm365_group_id', 'm365_calendar_mailbox', 'm365_timezone'];
    $config = [];
    foreach ($keys as $k) {
        $stmt = $conn->prepare("SELECT SettingValue FROM settings WHERE SettingKey = ? LIMIT 1");
        $stmt->bind_param("s", $k);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $config[$k] = $row['SettingValue'] ?? '';
    }
    if (empty($config['m365_enabled']) || $config['m365_enabled'] === '0') {
        return null;
    }
    foreach (['m365_tenant_id', 'm365_client_id', 'm365_client_secret'] as $required) {
        if (empty($config[$required])) return null;
    }
    // One of these must be set — prefer the shared-mailbox path
    if (empty($config['m365_calendar_mailbox']) && empty($config['m365_group_id'])) {
        return null;
    }
    $secret = m365DecryptSecret($config['m365_client_secret']);
    if ($secret === null || $secret === '') return null;
    $config['m365_client_secret'] = $secret;
    if (empty($config['m365_timezone'])) $config['m365_timezone'] = 'America/Chicago';
    return $config;
}

/**
 * Acquire an access token from Azure AD via client credentials flow.
 * Returns ['success' => bool, 'token' => string?, 'error' => string?].
 */
function m365GetToken(array $config): array {
    $url = "https://login.microsoftonline.com/" . rawurlencode($config['m365_tenant_id']) . "/oauth2/v2.0/token";
    $body = http_build_query([
        'client_id'     => $config['m365_client_id'],
        'client_secret' => $config['m365_client_secret'],
        'scope'         => 'https://graph.microsoft.com/.default',
        'grant_type'    => 'client_credentials',
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cErr     = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['success' => false, 'error' => 'cURL error: ' . $cErr];
    }
    $data = json_decode($response, true);
    if ($httpCode !== 200 || empty($data['access_token'])) {
        $reason = $data['error_description'] ?? $data['error'] ?? "HTTP {$httpCode}";
        return ['success' => false, 'error' => 'Token: ' . $reason];
    }
    return ['success' => true, 'token' => $data['access_token']];
}

/**
 * Build the Graph event payload for a time_off_requests row.
 * - Single day with times → partial-day event with the supplied window
 * - Otherwise → all-day event (multi-day partial requests collapse to all-day)
 * Notes deliberately NOT included in event body — per "no notes" decision.
 */
function m365BuildEvent(array $request, string $employeeName, string $timezone): array {
    $category    = ($request['Category'] === 'Sick') ? 'Sick' : 'PTO';
    $subject     = "{$employeeName} — {$category}";
    $isSingleDay = ($request['StartDate'] === $request['EndDate']);
    $hasTime     = !empty($request['StartTime']) && !empty($request['EndTime']);

    $base = [
        'subject'    => $subject,
        'body'       => ['contentType' => 'text', 'content' => $subject],
        'showAs'     => 'oof',
        'categories' => [$category],
    ];

    if ($hasTime && $isSingleDay) {
        $startTime = substr($request['StartTime'], 0, 8);
        $endTime   = substr($request['EndTime'],   0, 8);
        if (strlen($startTime) === 5) $startTime .= ':00';
        if (strlen($endTime)   === 5) $endTime   .= ':00';
        return $base + [
            'isAllDay' => false,
            'start' => [
                'dateTime' => $request['StartDate'] . 'T' . $startTime,
                'timeZone' => $timezone,
            ],
            'end' => [
                'dateTime' => $request['EndDate'] . 'T' . $endTime,
                'timeZone' => $timezone,
            ],
        ];
    }

    // All-day. Graph wants an exclusive end date (next day at midnight).
    $endDt = new DateTime($request['EndDate']);
    $endDt->modify('+1 day');
    return $base + [
        'isAllDay' => true,
        'start' => [
            'dateTime' => $request['StartDate'] . 'T00:00:00.0000000',
            'timeZone' => $timezone,
        ],
        'end' => [
            'dateTime' => $endDt->format('Y-m-d') . 'T00:00:00.0000000',
            'timeZone' => $timezone,
        ],
    ];
}

/**
 * POST an event to a group's calendar.
 * Returns ['success' => true, 'eventId' => string] or ['success' => false, 'error' => string].
 */
function m365CreateGroupEvent(string $groupId, string $accessToken, array $eventData): array {
    $url = "https://graph.microsoft.com/v1.0/groups/" . rawurlencode($groupId) . "/events";
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($eventData),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cErr     = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['success' => false, 'error' => 'cURL error: ' . $cErr];
    }
    $data = json_decode($response, true);
    if ($httpCode >= 200 && $httpCode < 300 && !empty($data['id'])) {
        return ['success' => true, 'eventId' => $data['id']];
    }
    $reason = $data['error']['message'] ?? "HTTP {$httpCode}";
    return ['success' => false, 'error' => 'Create event: ' . $reason];
}

/**
 * DELETE an event from a shared mailbox / user calendar by event ID.
 * Treats 404 (already gone) as success. Other failures return success=false.
 * Used by the amendment flow when an approved request is edited.
 */
function m365DeleteMailboxEvent(string $mailboxUpn, string $accessToken, string $eventId): array {
    $url = "https://graph.microsoft.com/v1.0/users/" . rawurlencode($mailboxUpn) . "/events/" . urlencode($eventId);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cErr     = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['success' => false, 'error' => 'cURL error: ' . $cErr];
    }
    if ($httpCode === 404) {
        return ['success' => true, 'note' => 'event already absent'];
    }
    if ($httpCode >= 200 && $httpCode < 300) {
        return ['success' => true];
    }
    $data = json_decode($response, true);
    $reason = $data['error']['message'] ?? "HTTP {$httpCode}";
    return ['success' => false, 'error' => 'Delete event: ' . $reason];
}

/**
 * Replace an existing M365 event: delete the old one and create a new one
 * with the supplied event data. Returns the new event ID on success.
 * Used by the amendment-approval flow. Falls through to create even if the
 * delete fails (e.g., the old event was already removed manually) — we'd
 * rather have a duplicate to clean up than no event at all.
 */
function m365ReplaceMailboxEvent(string $mailboxUpn, string $accessToken, ?string $oldEventId, array $newEventData): array {
    if ($oldEventId) {
        $del = m365DeleteMailboxEvent($mailboxUpn, $accessToken, $oldEventId);
        if (!$del['success']) {
            error_log("m365_calendar: delete-before-replace failed (continuing): " . $del['error']);
        }
    }
    return m365CreateMailboxEvent($mailboxUpn, $accessToken, $newEventData);
}

/**
 * POST an event to a shared mailbox / user calendar.
 * Returns ['success' => true, 'eventId' => string] or ['success' => false, 'error' => string].
 */
function m365CreateMailboxEvent(string $mailboxUpn, string $accessToken, array $eventData): array {
    $url = "https://graph.microsoft.com/v1.0/users/" . rawurlencode($mailboxUpn) . "/calendar/events";
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($eventData),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cErr     = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['success' => false, 'error' => 'cURL error: ' . $cErr];
    }
    $data = json_decode($response, true);
    if ($httpCode >= 200 && $httpCode < 300 && !empty($data['id'])) {
        return ['success' => true, 'eventId' => $data['id']];
    }
    $reason = $data['error']['message'] ?? "HTTP {$httpCode}";
    return ['success' => false, 'error' => 'Create event: ' . $reason];
}

/**
 * Top-level: sync an approved time-off request to the configured M365 calendar.
 * Dispatches to the shared-mailbox endpoint if m365_calendar_mailbox is set
 * (recommended path), otherwise falls back to the group calendar endpoint.
 * Returns ['success' => bool, 'eventId' => ?string, 'error' => ?string, 'skipped' => ?bool].
 * Never throws — callers must not depend on success.
 */
function m365SyncApprovedRequest(mysqli $conn, array $request, string $employeeName): array {
    $config = m365GetConfig($conn);
    if ($config === null) {
        return ['success' => false, 'skipped' => true, 'error' => 'M365 integration disabled or not configured'];
    }
    $token = m365GetToken($config);
    if (!$token['success']) {
        error_log("m365_calendar: token fetch failed: " . $token['error']);
        return ['success' => false, 'error' => $token['error']];
    }
    $event = m365BuildEvent($request, $employeeName, $config['m365_timezone']);

    if (!empty($config['m365_calendar_mailbox'])) {
        $result = m365CreateMailboxEvent($config['m365_calendar_mailbox'], $token['token'], $event);
    } else {
        $result = m365CreateGroupEvent($config['m365_group_id'], $token['token'], $event);
    }
    if (!$result['success']) {
        error_log("m365_calendar: create event failed: " . $result['error']);
    }
    return $result;
}

/**
 * Used by the settings page "Test Connection" button.
 * Probes the configured calendar (shared mailbox if set, otherwise group)
 * with a read call to confirm tokens + permissions + scope are aligned.
 */
function m365TestConnection(mysqli $conn): array {
    $config = m365GetConfig($conn);
    if ($config === null) {
        return ['success' => false, 'error' => 'M365 integration is disabled or required fields are blank.'];
    }
    $token = m365GetToken($config);
    if (!$token['success']) {
        return ['success' => false, 'error' => $token['error']];
    }

    if (!empty($config['m365_calendar_mailbox'])) {
        $upn = $config['m365_calendar_mailbox'];
        $url = "https://graph.microsoft.com/v1.0/users/" . rawurlencode($upn) . '/calendar?$select=id,name,owner';
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token['token']],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response ?: '[]', true);
        if ($httpCode !== 200) {
            $reason = $data['error']['message'] ?? "HTTP {$httpCode}";
            return ['success' => false, 'error' => 'Mailbox calendar lookup failed: ' . $reason];
        }
        return [
            'success'    => true,
            'group_name' => $data['name'] ?? '(calendar)',
            'group_mail' => $data['owner']['address'] ?? $upn,
        ];
    }

    // Fallback: group calendar
    $url = "https://graph.microsoft.com/v1.0/groups/" . rawurlencode($config['m365_group_id']) . '?$select=displayName,id,mail';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token['token']],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response ?: '[]', true);
    if ($httpCode !== 200) {
        $reason = $data['error']['message'] ?? "HTTP {$httpCode}";
        return ['success' => false, 'error' => 'Group lookup failed: ' . $reason];
    }
    return [
        'success'    => true,
        'group_name' => $data['displayName'] ?? '(unnamed)',
        'group_mail' => $data['mail'] ?? null,
    ];
}
