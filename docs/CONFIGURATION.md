# D-BEST TimeSmart Configuration Guide

Comprehensive configuration reference for TimeSmart.

## Table of Contents

- [Environment Variables](#environment-variables)
- [Docker Configuration](#docker-configuration)
- [PHP-FPM Configuration](#php-fpm-configuration)
- [Nginx Configuration](#nginx-configuration)
- [Database Configuration](#database-configuration)
- [Application Settings](#application-settings)
- [Security Settings](#security-settings)

## Environment Variables

Environment variables are set in `docker-compose.yml`:

### Database Connection

| Variable | Description | Default | Example |
|----------|-------------|---------|---------|
| `DB_HOST` | Database server hostname/IP | - | `172.17.0.1` |
| `DB_NAME` | Database name | - | `timeclock-acme` |
| `DB_USER` | Database username | - | `timeclock` |
| `DB_PASS` | Database password | - | `SecurePass123!` |
| `DB_TIMEZONE` | MySQL timezone | `America/Chicago` | `America/New_York` |

**Usage:**
```yaml
# In docker-compose.yml
environment:
  DB_HOST: 172.17.0.1
  DB_NAME: timeclock-acme
  DB_USER: timeclock
  DB_PASS: your_secure_password
  DB_TIMEZONE: America/Chicago
```

**Accessing in PHP:**
```php
// In app/auth/db.php
$host = $_ENV['DB_HOST'];
$db   = $_ENV['DB_NAME'];
$user = $_ENV['DB_USER'];
$pass = $_ENV['DB_PASS'];
$tz   = $_ENV['DB_TIMEZONE'] ?? 'America/Chicago';
```

### Supported Timezones

Common US timezones:
- `America/New_York` (Eastern)
- `America/Chicago` (Central)
- `America/Denver` (Mountain)
- `America/Los_Angeles` (Pacific)
- `America/Anchorage` (Alaska)
- `America/Honolulu` (Hawaii)

[Full list](https://www.php.net/manual/en/timezones.php)

## Docker Configuration

### Container Settings

File: `docker-compose.yml`

```yaml
services:
  app:
    # Base image (PHP 8.3 + Nginx + Supervisor)
    image: dbest25/timesmart:latest
    
    # Unique container name
    container_name: Timeclock-YourCompany
    
    # Restart policy
    restart: unless-stopped  # or: always, on-failure, no
    
    # Volume mounts (for development)
    volumes:
      - ./app:/var/www/html:rw                                       # Application files
      - ./deploy/docker/nginx.conf:/etc/nginx/sites-available/default:ro  # Nginx config
      - ./deploy/docker/www.conf:/usr/local/etc/php-fpm.d/www.conf:ro     # PHP-FPM config
    
    # Environment variables (see above)
    environment:
      DB_HOST: 172.17.0.1
      # ...
    
    # Network mode
    network_mode: bridge  # or: host (shares host network)
    
    # Optional: Expose on host port
    # ports:
    #   - "8080:80"  # host:container
```

### Network Modes

**Bridge Mode** (default, recommended):
- Container gets unique IP (e.g., 172.17.0.5)
- Access via container IP only
- Multiple containers don't conflict
- Requires reverse proxy for domain names

**Host Mode**:
- Container uses host network directly
- Access via server IP
- Port conflicts possible
- Simpler setup for single container

### Volume Mounts

With volume mounts, changes to files in `app/` are immediately reflected:

```bash
# Edit file on host
nano app/admin/dashboard.php

# Refresh browser - changes appear instantly!
# No need to restart container or rebuild image
```

**Read-only vs Read-write:**
- `rw` - Container can modify files (application code)
- `ro` - Container cannot modify files (config files)

## PHP-FPM Configuration

File: `deploy/docker/www.conf`

### Process Manager Settings

```ini
[www]
; Process manager type
pm = dynamic

; Maximum child processes
pm.max_children = 10          # Increase for high traffic

; Processes to start
pm.start_servers = 3

; Minimum idle processes
pm.min_spare_servers = 2

; Maximum idle processes
pm.max_spare_servers = 5

; Kill process after N requests (prevents memory leaks)
pm.max_requests = 500
```

**Tuning Guidelines:**
- Low traffic (< 10 concurrent users): Default settings
- Medium traffic (10-50 users): `max_children = 20`
- High traffic (50+ users): `max_children = 50+`

**Memory calculation:**
```
Available Memory = 1GB
PHP memory_limit = 256MB
Max children = 1GB / 256MB = ~4 (safe), ~10 (aggressive)
```

### PHP Settings

```ini
; File uploads
php_value[upload_max_filesize] = 10M
php_value[post_max_size] = 10M

; Execution limits
php_value[max_execution_time] = 60
php_value[memory_limit] = 256M

; Session settings
php_value[session.save_handler] = files
php_value[session.save_path] = /tmp

; Error logging
php_admin_value[error_log] = /var/log/php-fpm-error.log
php_admin_flag[log_errors] = on
```

### Security Settings

```ini
; Disable dangerous functions
php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,popen

; Restrict file access
php_admin_value[open_basedir] = /var/www/html:/tmp
```

## Nginx Configuration

File: `deploy/docker/nginx.conf`

### Basic Settings

```nginx
server {
    listen 80;
    server_name localhost;
    root /var/www/html;
    index index.php index.html;
    
    # PHP handling
    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_pass php-fpm;  # 127.0.0.1:9000
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
    
    # Deny access to .ht files
    location ~ /\.ht {
        deny all;
    }
}
```

### Cloudflare Real IP Configuration

**Important**: If using Cloudflare, Nginx needs to trust CF IPs:

```nginx
# Trust Cloudflare IPs
set_real_ip_from 173.245.48.0/20;
# ... (see nginx.conf for full list)

real_ip_header CF-Connecting-IP;
real_ip_recursive on;
```

Without this, PHP sees Cloudflare IPs instead of actual client IPs.

### Performance Tuning

```nginx
# Gzip compression
gzip on;
gzip_types text/css application/javascript text/plain application/json;
gzip_min_length 1000;

# Client body size (file uploads)
client_max_body_size 10M;

# Timeouts
client_body_timeout 12;
client_header_timeout 12;
keepalive_timeout 15;
send_timeout 10;

# Buffer sizes
client_body_buffer_size 10K;
client_header_buffer_size 1k;
```

## Database Configuration

### MySQL Settings

For optimal performance with TimeSmart:

```ini
# /etc/mysql/my.cnf or /etc/mysql/mysql.conf.d/mysqld.cnf

[mysqld]
# Character set
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci

# Connection settings
max_connections = 50
connect_timeout = 10

# Query cache (if MySQL < 8.0)
query_cache_type = 1
query_cache_size = 64M

# Buffer pool (InnoDB)
innodb_buffer_pool_size = 256M  # 50-70% of available RAM

# Logging
slow_query_log = 1
slow_query_log_file = /var/log/mysql/slow-query.log
long_query_time = 2  # Log queries > 2 seconds

# Timezone (must match DB_TIMEZONE)
default-time-zone = '-06:00'  # America/Chicago (UTC-6)
```

### Database User Permissions

```sql
-- Create timeclock user
CREATE USER 'timeclock'@'172.17.%' IDENTIFIED BY 'secure_password';

-- Grant permissions to all timeclock-* databases
GRANT ALL PRIVILEGES ON `timeclock-%`.* TO 'timeclock'@'172.17.%';

-- Apply changes
FLUSH PRIVILEGES;

-- Verify
SHOW GRANTS FOR 'timeclock'@'172.17.%';
```

### Database Indexes

Critical indexes for performance:

```sql
-- timepunches table
CREATE INDEX idx_employee_date ON timepunches(EmployeeID, Date);
CREATE INDEX idx_date ON timepunches(Date);

-- login_logs table
CREATE INDEX idx_employee ON login_logs(EmployeeID);
CREATE INDEX idx_timestamp ON login_logs(Timestamp);

-- punch_changelog table
CREATE INDEX idx_employee ON punch_changelog(EmployeeID);
CREATE INDEX idx_date ON punch_changelog(Date);
```

## Application Settings

### Runtime Settings

Stored in `settings` table:

| Setting | Type | Description | Default |
|---------|------|-------------|---------|
| `EnforceGPS` | boolean | Require GPS for clock actions | 0 (disabled) |
| `PinMinLength` | int 3–6 | Fewest digits for a new quick-clock/kiosk PIN (Settings → General). PINs are at most 6 digits; the clocks accept any 3–6 digit PIN on file, so raising this never locks out existing shorter PINs | 4 |
| `notify_timeoff_extra` | string | Extra addresses (comma-separated) notified on time-off requests, on top of opted-in admins | empty |
| `notify_timesheet_edits_extra` | string | Extra addresses (comma-separated) notified on timesheet edit requests | empty |
| `mail_admin_address` | string | Target of the **Save & Send Test Email** button only — not a notification recipient | empty |
| `PayWeekStartDay` | int 1–7 | First day of the work week (ISO: Mon=1 … Sun=7). Overtime and report weeks follow it | 3 (Wednesday) |
| `PayPeriodType` | `weekly` / `biweekly` | Pay frequency; sets the Payroll Totals report's default range | weekly |
| `PayPeriodAnchor` | date | First day of any one bi-weekly pay period (must fall on `PayWeekStartDay`) | empty |
| `OvertimeWeeklyHours` | decimal | Overtime after this many paid hours per work week | 40 |
| `OvertimeDailyHours` | decimal | Daily overtime threshold, 0 = off. Engine support only — not on the Pay Rules page yet | 0 |
| `DefaultRoundIncrement` | int | Round every punch of employees **without** a shift to this many minutes, 0 = off | 0 |
| `DefaultRoundMode` | `nearest` / `up` / `down` | Direction for `DefaultRoundIncrement` | nearest |
| `TimeOffDayHoursMode` | `fixed` / `shift` | What a full day of time off pays: `fixed` = `TimeOffDayHours` on Mon–Fri; `shift` = the employee's shift hours less lunch, charged on the shift's days (a week off on a Mon–Thu 10-hour shift is 4 × 10). Employees without a shift use `TimeOffDayHours`. Changing an employee's shift leaves their time off dated before today on the old shift (`time_off_requests.ShiftID`) | fixed |
| `TimeOffDayHours` | decimal | Hours for a full day of time off (`fixed`, and employees without a shift) | 8 |
| `TimeOffSickEnabled` | boolean | Offer the Sick category when requesting/recording time off. Off for companies without separate sick time; past Sick entries are unchanged | 1 |
| `PayrollLockedThrough` | date | Punches dated on/before this day can't be edited, approved or recalculated. Set by **Close payroll** on Payroll Totals, which also saves that period's totals (`payroll_closes`, `payroll_close_lines`): Payroll Totals then shows the saved figures for the period and marks employees whose current figures differ (e.g. after a holiday change); `&live=1` shows the current ones. Moved back or cleared on Pay Rules | empty |

The pay-rule settings (including the Time Off section) are edited on **Settings → Pay Rules** (super admins only).

### Shifts, Rounding and Auto-Lunch

Shifts are managed on **Settings → Shifts** and assigned per employee (`users.ShiftID`) on the
Users page. A shift sets its start/end, days, rounding windows around the start and end, an
increment for other punches, and a lunch rule (punched, auto-deduct, or auto-deduct unless
punched). See [SHIFT_ROUNDING_PLAN.md](SHIFT_ROUNDING_PLAN.md) for the design.

- Raw punches (`TimeIN`, `LunchStart`, `LunchEnd`, `TimeOut`) are never rewritten. The engine
  (`app/functions/pay_rules.php`) stores `PaidIn`, `PaidOut`, `LunchDeductMin` and `RawHours`
  beside them and writes **paid** hours to `TotalHours`, which every report reads.
- Every write path re-applies the rules to the whole employee-day (`recomputeDayHours()`).
- Editing a shift or a pay rule affects new punches only. **Shifts → Recalculate** re-applies
  the current rules to a past date range (preview first; each change is logged).
- Per punch, an admin can tick **Worked through lunch** (`LunchWaived`) or **Pay outside shift**
  (`OutsideApproved`) on the timesheet.
- With no shift and `DefaultRoundIncrement` = 0, paid hours equal actual hours, exactly as before.
- On a shift whose lunch mode is **Take lunch out automatically**, the kiosk and the employee
  dashboard offer only Clock In / Clock Out, and the second badge scan clocks out.
- Each office can have a default shift (`Offices.DefaultShiftID`, set on the Offices page). It
  pre-selects the shift when adding an employee to that office; existing employees are unchanged.
- **Reports → Rounding Audit** compares actual and paid time per employee (auto-deducted lunch
  shown separately), to show the rounding policy is neutral over time.

Test the engine without a database: `php deploy/scripts/test_pay_rules.php`.

### Holidays

Company holidays are edited on **Settings → Holidays** (`admin/holidays.php`, super admins) and
stored in the `holidays` table as rules, so the list carries over every year: a fixed date
(Dec 25), the Nth or last weekday of a month (4th Thursday of November), Easter-based
(Good Friday), or a single date. Common US holidays can be ticked from a list; others are added
as custom holidays. Every install starts with New Year's Day, Memorial Day, Independence Day,
Labor Day, Thanksgiving and Christmas Day.

- A holiday on a Saturday is observed the Friday before, on a Sunday the Monday after (can be
  turned off per custom holiday). When two holidays land on the same weekday, the earlier one
  moves back a day (Christmas Eve moves to Thursday when Christmas is observed Friday).
- **Move** (next to each year's date) observes that one year's holiday on another date, e.g. the
  Friday after Thanksgiving instead of Thursday (`holiday_moves`: holiday, year, date; other
  years keep the rule). Not allowed inside a closed payroll.
- On a holiday, full-day time off uses no PTO/Sick hours and the admin Home board doesn't flag
  no-shows or late arrivals. Exception: while holiday pay is on, a holiday switched to Unpaid is
  charged to full-day PTO like a workday, so employees can cover the closure day with PTO.
  And when a paid holiday pays less than the employee's full day (a 10-hour shift with 8
  holiday hours), a PTO/Sick request that covers the holiday pays the rest of the day (2 hours),
  at most the hours asked for. The holiday hours used are the employee's own Holiday hours, the
  setting, or an admin's hand-set hours for that day. Nothing is charged unless they ask for it.
- An employee whose dates overlap an absence an admin recorded (excused or unexcused) is told to
  ask an admin to change that absence to PTO, rather than being sent to withdraw their own request.
- **Holiday pay** (same page, off by default) adds a Holiday column to Payroll Totals (screen,
  Excel, CSV), the Home board's Hours Summary and timesheets. Holiday hours count in the total;
  by default not toward overtime, or with `HolidayPayCountsOT` = 1 toward the weekly threshold
  (a 10-hour holiday means overtime after 30 worked hours that week; the holiday hours stay in
  the Holiday column; Payroll Totals, Home, the Overtime and Summary reports and their exports, and
  the admin timesheets all use this rule, plus the daily limit). Working on a holiday is paid on top. Each holiday can be switched Paid/Unpaid.
  Settings: `HolidayPayEnabled`, `HolidayPayMode` (`shift` = the employee's scheduled shift hours
  less lunch, `fixed` = `HolidayPayFixedHours`, also used for employees without a shift; for a
  punched-lunch shift the lunch is the shift's **Expected lunch**, 0 = none),
  `HolidayPayDayOff` (`none`/`pay` when the holiday is on the employee's day off) and
  `HolidayPayRequireAdjacent` (must work the scheduled day before and after; approved time off
  counts). An employee's own **Holiday hours** (`users.HolidayHours`, Edit User; e.g. 8 for a
  part-timer) replace the mode for them. One employee's hours for one holiday — half a
  holiday, a full one the rules skipped, or 0 — are set on their timesheet (**Edit Timesheet**,
  the hours box on the holiday line, with a note; `holiday_pay_adjustments`); that wins over
  every rule and shows as "set by hand" on Payroll Totals. **Hours by employee** (links under
  the holiday lists, `admin/holiday_pay_adjust.php`) lists who gets what for one holiday, each
  name linking to that week's timesheet. Holidays before an employee's first punch are never paid, nor holidays with no punch
  or approved PTO/Sick in the 14 days before them (company holidays not counted), which leaves
  out people who have left but aren't archived yet and long unpaid leave. Holidays after the day
  an employee was archived aren't paid either. Logic:
  `app/functions/holiday_pay.php`.
- The calendar logic is `app/functions/holidays.php` (`isCompanyHoliday()`, `isWorkday()`;
  `isTimeOffDay()` for what full-day time off is charged on).

### Time Off Recorded by Admins

`admin/record_time_off.php` (menu **Time Off**, super admins) records time off for an employee,
saved as already approved: PTO, Sick (when offered), an **excused absence** (unpaid,
`time_off_requests.Category = 'Unpaid'`) or an **unexcused absence** (`'Unexcused'`); for both
absences the reason (`Notes`) is required. Past and future dates are allowed, except days inside
a closed payroll; overlapping entries are refused. PTO/Sick pay hours like an approved request;
absences pay 0 hours but keep the day from being flagged as a no-show. On the Home board an
unexcused absence shows the employee as Absent and is listed under Exceptions → Unexcused; the
Attendance report shows each type in its own color. Employees see these entries on their Time
Off page but can't edit or cancel an absence. For holiday pay's before/after rule, an absence
doesn't count as a day worked.

A part day can be entered as start/end times, or as a number of **hours missed**
(`time_off_requests.Hours`, no times): those hours are charged on the same days a full day would
be. Approved time off and company holidays are listed on the admin and employee timesheets.

**Adjusting on the timesheet.** In **Edit Timesheet** (admins who can edit timesheets), each
time-off and paid-holiday line has an hours box, saved with **Save All Changes**:
- PTO/Sick: what that one day pays (0 = nothing); the rest of the entry is unchanged
  (`time_off_day_hours`: entry, date, hours, note). An excused/unexcused absence: the hours
  missed that day (shown only; absences pay nothing).
- Holiday: that employee's holiday hours for that day (`holiday_pay_adjustments`).
- Blank = back to the rules. **Entry ✎** opens the whole entry (dates, type, cancel).
- **Add time off or an absence** (below the table, or **+ Time off / absence** on an empty
  day) records one day, saved as approved; Hours blank = a full day. A day the entry wouldn't
  normally charge (a weekend, off the shift's days) counts when it's picked here.
Each change is logged in Change History and refused inside a closed payroll. Logic:
`app/functions/timesheet_time_off.php`.

### Employee Number

`users.EmployeeNumber` (Add/Edit User, optional, unique) is the company's own employee number.
Once any employee has one, the Home board's Who's In panel shows an **Emp #** column, to every
admin, in place of the badge column. It is not a clock-in credential; PINs and badge IDs are
never shown on the board. Payroll Totals Excel and CSV files carry it in an **Employee #** column, left
blank for anyone whose number is the same as their PIN.

### Notification Recipients

Who gets notified when an employee submits a request is configured in the admin portal
under **Settings → Notification Recipients**, not by a single address.

Each admin (`super_admin` or `reports_only`) has a per-type opt-in stored on their user row:

| Column | Notifies on |
|--------|-------------|
| `users.NotifyTimeOff` | New time-off requests, amendments, and employee cancellations |
| `users.NotifyTimesheetEdits` | Timesheet adjustment requests |

The final recipient list is the opted-in admins plus the matching `notify_*_extra`
setting, deduplicated case-insensitively and validated. Resolved by
`notificationRecipients()` in `app/functions/notify_recipients.php`. An empty list
means nobody is notified — it is not an error, but it is logged.

Employee-facing mail (approval, denial, admin cancellation) always goes to the
employee and ignores these settings.

**Modify via SQL:**
```sql
-- Enable GPS enforcement
UPDATE settings SET SettingValue = '1' WHERE SettingName = 'EnforceGPS';

-- Disable GPS enforcement
UPDATE settings SET SettingValue = '0' WHERE SettingName = 'EnforceGPS';
```

**Access in PHP:**
```php
require_once '../functions/get_setting.php';
$enforceGPS = getSetting('EnforceGPS') == '1';
```

### Adding Custom Settings

```sql
-- Add new setting
INSERT INTO settings (SettingName, SettingValue) 
VALUES ('YourSettingName', 'default_value');

-- Retrieve in PHP
$value = getSetting('YourSettingName');
```

## Security Settings

### Session Configuration

File: `app/auth/db.php` and other pages

```php
session_set_cookie_params([
    'lifetime' => 0,              // Expire on browser close
    'path' => '/',
    'domain' => '',
    'secure' => true,             // HTTPS only
    'httponly' => true,           // No JavaScript access
    'samesite' => 'Strict',       // CSRF protection
]);
```

**Notes:**
- `secure => true` requires HTTPS (disable for testing on HTTP)
- `httponly => true` prevents XSS attacks
- `samesite => Strict` prevents CSRF attacks

### Password Hashing

```php
// Hash password (bcrypt, cost 14)
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 14]);

// Verify password
if (password_verify($input, $hash)) {
    // Authenticated
}
```

**Cost guidelines:**
- 10 = Fast (0.1s), less secure
- 12 = Medium (0.4s), good balance
- 14 = Slow (1.5s), more secure (current)
- 16 = Very slow (6s), maximum security

### Two-Factor Authentication

2FA emails a 6-digit login code (`app/functions/email_otp.php`), so it needs outgoing email
configured. Codes are stored hashed and expire after 10 minutes:

```php
require_once __DIR__ . '/../functions/email_otp.php';

sendEmailOtp($conn, $userId, $email, $firstName);   // after the password check
$valid = verifyEmailOtp($conn, $userId, $code);     // on verify_2fa.php
```

### CSRF Protection

```php
// Generate token (on form page)
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// Include in form
echo '<input type="hidden" name="csrf" value="' . $_SESSION['csrf'] . '">';

// Verify token (on form handler)
if (!hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    die('CSRF token invalid');
}
```

### SQL Injection Prevention

**Always use prepared statements:**

```php
// GOOD
$stmt = $conn->prepare("SELECT * FROM users WHERE ID = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

// BAD (vulnerable to SQL injection)
$result = $conn->query("SELECT * FROM users WHERE ID = '$id'");
```

### File Upload Security

```php
// Validate file type
$allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $_FILES['file']['tmp_name']);

if (!in_array($mimeType, $allowedTypes)) {
    die('Invalid file type');
}

// Validate file size
$maxSize = 10 * 1024 * 1024;  // 10MB
if ($_FILES['file']['size'] > $maxSize) {
    die('File too large');
}

// Generate safe filename
$ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
$filename = bin2hex(random_bytes(16)) . '.' . $ext;

// Store outside webroot if possible
move_uploaded_file($_FILES['file']['tmp_name'], '/var/uploads/' . $filename);
```

## Configuration Best Practices

1. **Never commit credentials** to Git
   - Use .gitignore for docker-compose.yml with real passwords
   - Keep a docker-compose.yml.example with placeholders

2. **Use strong passwords**
   - Database: 32+ random characters
   - Admin: 14+ mixed characters + 2FA

3. **Keep software updated**
   - PHP, MySQL, Docker, OS packages
   - Run updates regularly

4. **Monitor logs**
   - Check for errors daily
   - Alert on critical issues

5. **Test configuration changes**
   - Test in dev environment first
   - Backup before making changes
   - Have rollback plan

## Troubleshooting

### Configuration not applying

```bash
# Restart container after config changes
docker restart Timeclock-YourCompany

# Or recreate container
docker compose down
docker compose up -d

# Verify mounts
docker inspect Timeclock-YourCompany | grep -A 10 Mounts
```

### Permission denied errors

```bash
# Fix ownership
docker exec Timeclock-YourCompany chown -R www-data:www-data /var/www/html

# Fix config file permissions
sudo chmod 644 deploy/docker/nginx.conf
sudo chmod 644 deploy/docker/www.conf
```

### Database connection issues

```bash
# Test from host
mysql -h 172.17.0.1 -u timeclock -p

# Check MySQL allows connections
# In /etc/mysql/my.cnf:
bind-address = 0.0.0.0  # or 172.17.0.1

# Grant permissions from Docker network
GRANT ALL ON `timeclock-%`.* TO 'timeclock'@'172.17.%';
```

## Next Steps

- Read [INSTALLATION.md](INSTALLATION.md) for setup instructions
- Read [DEPLOYMENT.md](DEPLOYMENT.md) for maintenance
- Read [DEVELOPMENT.md](DEVELOPMENT.md) for code architecture
- Read [CLAUDE.md](../CLAUDE.md) for operations
