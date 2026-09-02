<?php
/**
 * functions/csrf.php
 *
 * CSRF protection for state-changing admin/employee POST endpoints.
 *
 * Every form that mutates data must carry csrf_field() in its markup, and the
 * handler that receives it must call require_csrf() before touching the database.
 * Without it, any page a logged-in admin visits can silently POST to (say)
 * save_punches.php in their session and rewrite payroll.
 *
 * Shares $_SESSION['csrf'] with the login pages, which define their own local
 * helpers against the same key — one token per session, whoever mints it first.
 */

/** Current session CSRF token, creating one on first use. */
function csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Hidden input carrying the token. Drop this inside every mutating <form>. */
function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="'
        . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/** Whether the submitted token matches the session's. Timing-safe. */
function csrf_valid(): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $sent = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return !empty($_SESSION['csrf']) && is_string($sent) && hash_equals($_SESSION['csrf'], $sent);
}

/**
 * Reject the request unless it carries a valid token.
 *
 * @param string|null $redirect Where to send a rejected browser POST. Null (or a
 *                              non-GET-able handler) gets a bare 403 instead.
 */
function require_csrf(?string $redirect = null): void {
    // Only unsafe methods can be forged into a state change; a GET of a handler is
    // just a mistake, and the handler's own input checks will turn it away.
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }
    if (csrf_valid()) {
        return;
    }
    error_log('CSRF rejection: ' . ($_SERVER['REQUEST_URI'] ?? '?')
        . ' from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    if ($redirect !== null) {
        header('Location: ' . $redirect);
        exit;
    }
    http_response_code(403);
    exit('Invalid or expired request. Please reload the page and try again.');
}
