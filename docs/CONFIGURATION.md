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

Using `spomky-labs/otphp`:

```php
use OTPHP\TOTP;

// Generate secret
$totp = TOTP::create();
$secret = $totp->getSecret();

// Generate QR code URL
$uri = $totp->getProvisioningUri('admin@timesmart.com', 'TimeSmart');

// Verify code
$totp = TOTP::create($secret);
$valid = $totp->verify($code, null, 30);  // 30 second window
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
