# DEVELOPMENT.md

This file provides detailed code architecture and development patterns for developers modifying the TimeSmart codebase. For server operations and management, see [CLAUDE.md](../CLAUDE.md).

## Table of Contents

- [Database Schema](#database-schema)
- [Authentication Flow](#authentication-flow)
- [Security Patterns](#security-patterns)
- [Two-Factor Authentication](#two-factor-authentication-totp)
- [Database Connection Pattern](#database-connection-pattern)
- [Time Calculation](#time-calculation)
- [Key Workflows](#key-workflows)
- [Critical Security Issues](#critical-security-issues)
- [Access Patterns](#access-patterns)
- [GPS Enforcement](#gps-enforcement)
- [Audit Trail](#audit-trail)
- [Template Pattern](#template-pattern)
- [Error Handling](#error-handling)
- [Development Workflow](#development-workflow)
- [Code Conventions](#code-conventions)

---

## Database Schema

**9 Tables (utf8mb4 charset):**

1. **users** - Employee records with bcrypt passwords, TagID (badge), ClockStatus, 2FA settings, GPS coordinates, profile photos
2. **admins** - Administrative accounts with bcrypt passwords, 2FA with JSON recovery codes
3. **timepunches** - Core time records with IN/OUT/Lunch times, GPS coordinates, IP addresses, calculated TotalHours
4. **pending_edits** - Employee timesheet edit requests (Pending/Approved/Rejected workflow)
5. **punch_changelog** - Audit trail for all punch modifications with EmployeeID, ChangedBy, OldValue/NewValue
6. **login_logs** - Security audit log with EmployeeID, IP, Timestamp
7. **settings** - Key-value configuration (e.g., EnforceGPS flag)
8. **Offices** - Office locations for multi-location support
9. **user_archive** - Archive table for deleted/deactivated user records

See `deploy/database/timeclock-schema.sql` for complete table definitions.

---

## Authentication Flow

### Admin Login
```
/admin/login.php → CSRF + cooldown check → bcrypt verify → 2FA check → /admin/verify_2fa.php → dashboard
```

### Employee Login
```
/user/login.php → Parse name (First Last or Last, First) → Lookup with attempt tracking → 2FA check → /user/verify_2fa.php → dashboard
```

### Session Variables
- `$_SESSION['admin']` - Admin username (post-2FA)
- `$_SESSION['EmployeeID']` - Employee ID (post-2FA)
- `$_SESSION['2fa_admin_username']` - Temporary during 2FA flow
- `$_SESSION['temp_user_id']` - Temporary during 2FA flow
- `$_SESSION['csrf']` - CSRF token (64-char hex)

---

## Security Patterns

### Session Hardening
```php
session_set_cookie_params([
    'lifetime' => 0,
    'secure' => true,        // HTTPS only
    'httponly' => true,      // Prevent JS access
    'samesite' => 'Strict',  // CSRF protection
]);
```

### CSRF Protection
- Token: `bin2hex(random_bytes(32))` stored in `$_SESSION['csrf']`
- Validation: `hash_equals($_SESSION['csrf'], $_POST['csrf'])`
- Embedded in all POST forms

### Password Hashing
- bcrypt with `password_hash($pass, PASSWORD_BCRYPT, ['cost' => 14])`
- Verification: `password_verify($input, $hash)`

### Prepared Statements
Standard pattern used in 95% of queries:
```php
$stmt = $conn->prepare("SELECT * FROM users WHERE ID = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
```

### Lockout Mechanisms
- Admin login: 5 failures → 15-minute cooldown
- 2FA attempts: 5 failures → 15-minute cooldown
- 350ms artificial delay on failures (timing attack mitigation)

---

## Two-Factor Authentication (TOTP)

**Implementation via `spomky-labs/otphp`:**

### Setup Flow
1. Generate secret: `TOTP::create()`
2. Generate QR code: `endroid/qr-code` library
3. User scans with authenticator app (Google Authenticator, Authy, etc.)
4. Verify 6-digit code
5. Store secret in `TwoFASecret` field, set `TwoFAEnabled = 1`
6. Generate backup recovery codes (JSON array)

### Verification
- Code normalization: `preg_replace('/\D+/', '', $input)`
- Constant-time comparison: `hash_equals()` for recovery codes
- Verification: `$totp->verify($code)`

### Recovery Codes
- Stored as JSON in `TwoFARecoveryCode` field
- One-time use (deleted after use)
- Constant-time lookup prevents timing attacks

---

## Database Connection Pattern

**Single connection point: `/auth/db.php`**
```php
require_once __DIR__ . '/../vendor/autoload.php';
$conn = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '{$_ENV['DB_TIMEZONE']}'");
```

Included in virtually every PHP file: `require '../auth/db.php';`

---

## Time Calculation

### Timezone
```php
date_default_timezone_set('America/Chicago');
```

### Hours Calculation
```php
$totalSeconds = strtotime($clockOut) - strtotime($clockIn);
if (!empty($lunchOut) && !empty($lunchIn)) {
    $totalSeconds -= (strtotime($lunchIn) - strtotime($lunchOut));
}
$totalHours = round($totalSeconds / 3600, 2);
```

---

## Key Workflows

### Clock In/Out
1. Badge scan or button click
2. `/functions/clock_action.php` or `/functions/clock_handler.php`
3. Validate employee + PIN
4. INSERT or UPDATE `timepunches` record
5. Update `users.ClockStatus` (In/Out/Lunch)
6. Calculate `TotalHours` on clock out

### Timesheet Edit Request
1. Employee views punch in `/user/dashboard.php`
2. Submit edit via `/user/submit_timesheet_edits.php`
3. INSERT into `pending_edits` with `Status='Pending'`
4. Admin reviews in `/admin/edits_timesheet.php`
5. On approval: UPDATE `timepunches`, INSERT `punch_changelog`
6. Delete from `pending_edits`

### Report Generation
1. Admin specifies date range + employee filter
2. Query `timepunches` with JOIN to `users`
3. Apply rounding rules if specified
4. Export as HTML, Excel (`phpoffice/phpspreadsheet`), or PDF (TCPDF)

---

## Critical Security Issues

### SQL Injection Vulnerability

**File:** `/functions/clock_handler.php`
```php
// VULNERABLE CODE - Uses string interpolation instead of prepared statements
$user = $conn->query("SELECT * FROM users WHERE ID = '$empID' AND Pass = '$pass'");
```

**Fix:** Replace with prepared statement:
```php
$stmt = $conn->prepare("SELECT * FROM users WHERE ID = ? AND Pass = ?");
$stmt->bind_param("ss", $empID, $pass);
$stmt->execute();
$result = $stmt->get_result();
```

### File Upload Security

**File:** `/user/settings.php`
Profile photos uploaded to webroot (`../uploads/`). Validates MIME type and size but potential for PHP execution if extension validation bypassed.

### Display Errors in Production

Multiple files have `ini_set('display_errors', 1);` which leaks system information.

---

## Access Patterns

### Public Pages
- `/index.php` - Status dashboard (shows all employee clock status)
- `/kiosk/index.php` - Badge scanning interface

### Admin Pages
- All require `$_SESSION['admin']` to be set
- Redirect to `/admin/login.php` if not authenticated

### Employee Pages
- All require `$_SESSION['EmployeeID']` to be set
- Redirect to `/user/login.php` if not authenticated

---

## GPS Enforcement

When `settings.EnforceGPS = 1`:
- Clock actions require GPS coordinates
- Stored in `timepunches` table: `LatitudeIN`, `LongitudeIN`, etc. (8 decimal precision)
- Also captures accuracy and IP address for each action

---

## Audit Trail

All timesheet modifications logged in `punch_changelog`:
- `EmployeeID`, `Date`, `ChangedBy` (admin username)
- `FieldChanged`, `OldValue`, `NewValue`
- `Reason` (justification text)
- `ChangeTime` (auto-timestamp)

---

## Template Pattern

### Employee Pages
- Include `/user/header.php` (DB connection, session check, avatar)
- Include `/user/footer.php` (closes divs, JS, docs links)

### Admin Pages
- Each page independently requires session check and `/auth/db.php`
- No shared header/footer templates

---

## Error Handling

Redirect pattern:
```php
header('Location: ../error.php?code=500&message=' . urlencode('Error description'));
```

`error.php` renders styled error page with code and message.

---

## Development Workflow

### Making Changes

With volume mounts, development is streamlined:

1. **Edit files** in `app/` on host machine
2. **Changes appear immediately** in browser (no rebuild!)
3. **Test** in browser
4. **Commit** when satisfied

```bash
cd /opt/Timeclock-YourCompany

# Edit files
nano app/admin/dashboard.php

# Test in browser (refresh page)

# Commit changes
git add app/admin/dashboard.php
git commit -m "Update dashboard UI"
git push
```

### No More docker cp!

**Old workflow (painful):**
```bash
# Edit file
nano /path/to/file.php
# Copy to container
docker cp file.php container:/var/www/html/
# Restart container
docker restart container
```

**New workflow (easy):**
```bash
# Edit file
nano app/file.php
# Refresh browser - done!
```

### Updating Production

```bash
# On production server
cd /opt/Timeclock-YourCompany
./deploy/scripts/update.sh

# Script will:
# 1. Backup database (prompt)
# 2. Git pull latest changes
# 3. Update composer if needed
# 4. Restart container
# 5. Show success message
```

### Configuration Changes

Config files require container restart:

```bash
# Edit nginx.conf
nano deploy/docker/nginx.conf

# Restart to apply
docker restart Timeclock-YourCompany
```

---

## Code Conventions

### PHP Standards
- PHP 8.3 compatible
- Use prepared statements for all database queries
- Include session check at top of authenticated pages
- Use `password_hash()` and `password_verify()` for passwords

### JavaScript
- Vanilla JS preferred (no jQuery dependency)
- AJAX for form submissions where appropriate
- Kiosk mode has dedicated JS in `/kiosk/kiosk.js`

### CSS
- Separate CSS files per page/feature in `/css/`
- No CSS framework (custom styles)

### File Organization
- Admin pages in `/admin/`
- Employee pages in `/user/`
- Shared functions in `/functions/`
- Database connection in `/auth/db.php`

---

## Related Documentation

- **[CLAUDE.md](../CLAUDE.md)** - Server operations and management
- **[INSTALLATION.md](INSTALLATION.md)** - Installation guide
- **[DEPLOYMENT.md](DEPLOYMENT.md)** - Deployment and maintenance
- **[CONFIGURATION.md](CONFIGURATION.md)** - Configuration reference
