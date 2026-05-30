<?php
// functions/location_policy.php
//
// Helpers for punch-location policy:
//  - is_mobile_ua():     best-effort mobile detection from the User-Agent.
//  - ip_in_allowlist():  match a client IP against a list of IPs/CIDRs
//                        (IPv4 and IPv6), used by the "restrict to allowed IPs"
//                        toggle.

/**
 * Best-effort: is this request from a mobile device?
 * UA-based, so it's spoofable — acceptable for an honesty-based timeclock.
 */
function is_mobile_ua(?string $ua): bool {
    if (!$ua) {
        return false;
    }
    return (bool) preg_match(
        '/(android|iphone|ipod|ipad|iemobile|blackberry|opera mini|mobile|windows phone|webos)/i',
        $ua
    );
}

/**
 * True if $ip matches any entry in $listText. Entries are separated by
 * commas/whitespace/newlines and may be single IPs or CIDR ranges, e.g.:
 *   "203.0.113.7, 198.51.100.0/24, 2001:db8::/32"
 */
function ip_in_allowlist(string $ip, string $listText): bool {
    $entries = preg_split('/[\s,]+/', trim($listText), -1, PREG_SPLIT_NO_EMPTY);
    foreach ($entries as $entry) {
        if (strpos($entry, '/') !== false) {
            if (cidr_match($ip, $entry)) {
                return true;
            }
        } elseif (ip_equals($ip, $entry)) {
            return true;
        }
    }
    return false;
}

/** Exact IP comparison (normalizes via inet_pton so 1.2.3.4 == 01.02.03.04 forms match). */
function ip_equals(string $a, string $b): bool {
    $pa = @inet_pton($a);
    $pb = @inet_pton($b);
    return $pa !== false && $pb !== false && $pa === $pb;
}

/** CIDR membership test for IPv4 and IPv6. */
function cidr_match(string $ip, string $cidr): bool {
    if (strpos($cidr, '/') === false) {
        return ip_equals($ip, $cidr);
    }
    [$subnet, $bitsRaw] = explode('/', $cidr, 2);
    $bits = (int) $bitsRaw;

    $ipBin  = @inet_pton($ip);
    $subBin = @inet_pton($subnet);
    if ($ipBin === false || $subBin === false || strlen($ipBin) !== strlen($subBin)) {
        return false; // address-family mismatch or invalid
    }

    $maxBits = strlen($ipBin) * 8;
    if ($bits < 0 || $bits > $maxBits) {
        return false;
    }

    $fullBytes = intdiv($bits, 8);
    $remBits   = $bits % 8;

    if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subBin, 0, $fullBytes)) {
        return false;
    }
    if ($remBits > 0) {
        $mask = chr((0xff << (8 - $remBits)) & 0xff);
        if ((ord($ipBin[$fullBytes]) & ord($mask)) !== (ord($subBin[$fullBytes]) & ord($mask))) {
            return false;
        }
    }
    return true;
}
