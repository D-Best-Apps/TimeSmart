<?php
// Absolute URLs for links that leave the browser.
//
// A relative "/admin/edits_timesheet.php" is meaningless once it lands in a mail
// client — it renders as "http://admin/edits_timesheet.php" or as a dead link —
// so every emailed link has to carry the scheme and host of this installation.

require_once __DIR__ . '/settings_helper.php';

/**
 * Base URL of this installation, with no trailing slash.
 *
 * Prefers the app_base_url setting (Settings → Mail Server Settings), which is
 * the only source that works from cron/CLI where there is no request to read.
 * Falls back to the current request, honouring the reverse proxy's forwarded
 * scheme since the container itself only ever speaks plain HTTP.
 * Returns '' when neither is available, so callers can fall back to the path.
 */
function appBaseUrl(?mysqli $conn = null): string {
    $configured = $conn ? trim((string) getSettingValue('app_base_url', $conn)) : '';
    if ($configured !== '') {
        if (!preg_match('#^https?://#i', $configured)) {
            $configured = 'https://' . $configured;
        }
        return rtrim($configured, '/');
    }

    // No setting configured: rebuild it from the request. The Host header is
    // client-supplied, so this is a convenience fallback, not a trusted value —
    // set app_base_url on any install that sends mail.
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return '';
    }

    $forwardedProto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    if ($forwardedProto === 'https' || $forwardedProto === 'http') {
        $scheme = $forwardedProto;
    } else {
        $scheme = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    }

    return $scheme . '://' . $host;
}

/**
 * Absolute URL for an app-root-relative path, e.g. '/admin/edits_timesheet.php'.
 * Degrades to the bare path when the base URL is unknown.
 */
function appUrl(string $path, ?mysqli $conn = null): string {
    $base = appBaseUrl($conn);
    if ($path === '' || $path[0] !== '/') {
        $path = '/' . $path;
    }
    return $base === '' ? $path : $base . $path;
}
