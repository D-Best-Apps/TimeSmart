# CLAUDE.md

This file provides guidance for operating and managing the TimeSmart application. For code architecture, security patterns, and development details, see [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md).

## Project Overview

D-BEST TimeSmart is a PHP-based employee timekeeping system with three main interfaces:
- **Admin Portal** (`/admin/`) - User management, timesheet approval, reporting
- **Employee Portal** (`/user/`) - Clock in/out, timesheet viewing, edit requests
- **Kiosk Mode** (`/kiosk/`) - Badge/NFC scanning for PIN-less clocking

**Tech Stack:** PHP 8.3, MySQL/MariaDB, Nginx, Docker, TOTP 2FA

## Common Commands

### Development Environment

This application runs in Docker with **volume-mounted** application files for easy development.

```bash
# Navigate to installation directory
cd /opt/Timeclock-<CompanyName>

# Update application (git pull + container restart)
./deploy/scripts/update.sh

# Find container IP address
docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' <container_name>

# Access container shell
docker exec -it <container_name> /bin/bash

# View logs
docker logs <container_name>

# Restart container (applies config changes)
docker restart <container_name>

# Restart services inside container
docker exec <container_name> supervisorctl restart all
```

**Development Workflow:**
1. Edit files in `app/` directory on host
2. Changes appear immediately in browser (no rebuild needed!)
3. Use `docker restart` only if changing config files

### Database Operations

```bash
# Connect to database (from host)
mysql -h <db_host> -u <db_user> -p<db_pass> <db_name>

# Import schema
mysql -h <db_host> -u <db_user> -p<db_pass> <db_name> < deploy/database/timeclock-schema.sql

# Backup database (manual)
mysqldump -h <db_host> -u <db_user> -p<db_pass> <db_name> > backup.sql

# Backup database (automated script)
./deploy/scripts/backup.sh
```

### Composer Dependencies

```bash
# Install dependencies
cd app/
composer install

# Update dependencies
composer update

# Required packages:
# - phpoffice/phpspreadsheet (Excel export)
# - spomky-labs/otphp (2FA TOTP)
# - endroid/qr-code (QR code generation)
# - vlucas/phpdotenv (environment variables)
```

### Installation & Deployment

```bash
# New installation (interactive)
bash <(curl -s https://raw.githubusercontent.com/D-Best-App/Timesmart/main/deploy/scripts/install.sh)

# Update existing installation
cd /opt/Timeclock-<CompanyName>
./deploy/scripts/update.sh

# Backup databases
./deploy/scripts/backup.sh

# Manual installation
git clone https://github.com/D-Best-App/Timesmart.git
cd Timesmart
# Edit docker-compose.yml with your settings
docker compose up -d
```

## Directory Structure

```
Timeclock-<CompanyName>/
├── app/                      # Application code (volume-mounted to /var/www/html)
│   ├── admin/               # Admin portal pages
│   ├── user/                # Employee portal pages
│   ├── kiosk/               # Badge scanning interface
│   ├── functions/           # Shared PHP functions
│   ├── auth/                # Database connection (db.php)
│   ├── css/                 # Stylesheets
│   ├── js/                  # JavaScript files
│   ├── images/              # Static assets
│   ├── vendor/              # Composer dependencies
│   └── index.php            # Public dashboard
│
├── deploy/                  # Deployment configuration
│   ├── docker/              # Dockerfile, nginx.conf, www.conf, supervisord.conf
│   ├── database/            # timeclock-schema.sql
│   └── scripts/             # install.sh, update.sh, backup.sh, remove.sh
│
├── docs/                    # Documentation
│   ├── INSTALLATION.md      # Installation guide
│   ├── DEPLOYMENT.md        # Operations guide
│   ├── CONFIGURATION.md     # Configuration reference
│   └── DEVELOPMENT.md       # Code architecture and development patterns
│
├── docker-compose.yml       # Container orchestration with volume mounts
├── CLAUDE.md                # This file (operations documentation)
├── README.md                # User-facing documentation
└── CHANGELOG.md             # Version history
```

## Configuration Management

### Environment Variables (docker-compose.yml)
- `DB_HOST` - Database server hostname (default: `172.17.0.1`)
- `DB_NAME` - Database name (format: `timeclock-companyname`)
- `DB_USER` - Database username
- `DB_PASS` - Database password
- `DB_TIMEZONE` - Timezone (default: `America/Chicago`)

Example configuration in `docker-compose.yml`:

```yaml
environment:
  DB_HOST: 172.17.0.1
  DB_NAME: timeclock-acme
  DB_USER: timeclock
  DB_PASS: secure_password
  DB_TIMEZONE: America/Chicago
```

### Runtime Settings Table
- `EnforceGPS` - Require GPS on clock actions (0/1)
- Queried via `/app/functions/get_setting.php`

### Configuration Files
- `docker-compose.yml` - Container config + environment variables
- `deploy/docker/nginx.conf` - Nginx web server config
- `deploy/docker/www.conf` - PHP-FPM pool config
- `deploy/docker/supervisord.conf` - Process management
- See [CONFIGURATION.md](docs/CONFIGURATION.md) for full reference

## Default Credentials

**Admin Login:** `/admin/login.php`
- Username: `admin`
- Password: `password` (bcrypt hash in schema)

**Employee Login:** `/user/login.php`
- Credentials created by admin via `/admin/add_user.php`

## Scripts Reference

All deployment scripts in `deploy/scripts/`:

### install.sh

Interactive installation wizard:
- Checks prerequisites (Docker, Git, MySQL)
- Prompts for company name, database credentials
- Clones repository
- Configures docker-compose.yml
- Creates database (optional)
- Starts container
- Shows access information

```bash
bash <(curl -s https://raw.githubusercontent.com/D-Best-App/Timesmart/main/deploy/scripts/install.sh)
```

### update.sh

Update existing installation:
- Checks git status
- Prompts for backup confirmation
- Pulls latest changes
- Updates composer dependencies if needed
- Restarts container
- Shows what changed

```bash
cd /opt/Timeclock-YourCompany
./deploy/scripts/update.sh
```

### backup.sh

Automated database backup:
- Backs up all `timeclock-*` databases
- Compresses backups (gzip)
- Rotates hourly backups (keeps last 8)
- Creates daily backups at 8 PM
- Can be scheduled via cron

```bash
# Configure credentials in script
sudo nano deploy/scripts/backup.sh

# Run manually
sudo ./deploy/scripts/backup.sh

# Schedule via cron (hourly)
crontab -e
# Add: 0 * * * * /opt/Timeclock-YourCompany/deploy/scripts/backup.sh
```

### remove.sh

Clean removal of installation:
- Stops and removes container
- Optionally removes database
- Optionally removes files

```bash
cd /opt/Timeclock-YourCompany
./deploy/scripts/remove.sh
```

## File Reference

**Application Code** (`app/`):
- `admin/` - Admin portal pages
- `user/` - Employee portal pages
- `kiosk/` - Badge scanning interface
- `functions/` - Shared PHP functions
- `auth/db.php` - Database connection
- `index.php` - Public status dashboard

**Deployment** (`deploy/`):
- `docker/Dockerfile` - Container image definition
- `docker/nginx.conf` - Web server config
- `docker/www.conf` - PHP-FPM config
- `docker/supervisord.conf` - Process manager
- `database/timeclock-schema.sql` - Database schema
- `scripts/*.sh` - Installation/management scripts

**Configuration**:
- `docker-compose.yml` - Container orchestration + env vars
- `.gitignore` - Excluded files (vendor/, logs/, etc.)

**Documentation**:
- `README.md` - User-facing documentation
- `CLAUDE.md` - This file (operations)
- `CHANGELOG.md` - Version history
- `docs/INSTALLATION.md` - Installation guide
- `docs/DEPLOYMENT.md` - Operations guide
- `docs/CONFIGURATION.md` - Configuration reference
- `docs/DEVELOPMENT.md` - Code architecture and development

## Getting Help

- **Installation Issues**: See [docs/INSTALLATION.md](docs/INSTALLATION.md)
- **Deployment/Updates**: See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)
- **Configuration**: See [docs/CONFIGURATION.md](docs/CONFIGURATION.md)
- **Code Architecture/Development**: See [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md)
- **GitHub Issues**: https://github.com/D-Best-App/Timesmart/issues
