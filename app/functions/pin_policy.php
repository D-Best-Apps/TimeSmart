<?php
// Quick-clock / kiosk PIN length rules.
//
// New PINs must be at least PinMinLength digits (Settings, 3-6, default 4) and at most
// PIN_MAX_LENGTH. The clocks themselves accept any PIN_ABS_MIN..PIN_MAX_LENGTH digit value,
// so raising the minimum later never locks out employees who already have a shorter PIN.

require_once __DIR__ . '/settings_helper.php';

const PIN_ABS_MIN    = 3;
const PIN_MAX_LENGTH = 6;
const PIN_DEFAULT_MIN = 4;

/** Minimum digits for a new PIN (Settings → PIN length). */
function pinMinLength(mysqli $conn): int {
    $v = (int) (getSettingValue('PinMinLength', $conn) ?? PIN_DEFAULT_MIN);
    return ($v >= PIN_ABS_MIN && $v <= PIN_MAX_LENGTH) ? $v : PIN_DEFAULT_MIN;
}

/** Is $pin acceptable as a new PIN? */
function isValidNewPin(string $pin, mysqli $conn): bool {
    return preg_match('/^\d{' . pinMinLength($conn) . ',' . PIN_MAX_LENGTH . '}$/', $pin) === 1;
}

/** Could $value be a PIN at the clock? (any PIN that may be on file) */
function looksLikePin(string $value): bool {
    return preg_match('/^\d{' . PIN_ABS_MIN . ',' . PIN_MAX_LENGTH . '}$/', $value) === 1;
}

/** "4 to 6 digits" / "6 digits" */
function pinRuleText(mysqli $conn): string {
    $min = pinMinLength($conn);
    return $min === PIN_MAX_LENGTH ? "{$min} digits" : "{$min} to " . PIN_MAX_LENGTH . " digits";
}

/** HTML pattern attribute value for PIN inputs; the PIN already on file is always accepted. */
function pinInputPattern(mysqli $conn, string $currentPin = ''): string {
    $rule = '\d{' . pinMinLength($conn) . ',' . PIN_MAX_LENGTH . '}';
    return looksLikePin($currentPin) ? $currentPin . '|' . $rule : $rule;
}
