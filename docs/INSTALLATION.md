# D-BEST TimeSmart Installation Guide

This guide walks you through installing D-BEST TimeSmart, a PHP-based employee timekeeping system.

## Table of Contents

- [Prerequisites](#prerequisites)
- [Quick Start](#quick-start)
- [Detailed Installation](#detailed-installation)
- [Publish over HTTPS](#publish-over-https)
- [Post-Installation](#post-installation)
- [Multi-Company Setup](#multi-company-setup)
- [Manual Installation](#manual-installation)
- [Troubleshooting](#troubleshooting)

## Prerequisites

### Required Software

1. **Docker** (version 20.10 or higher)
   ```bash
   # Check Docker version
   docker --version
   
   # Install Docker (Ubuntu/Debian)
   curl -fsSL https://get.docker.com | sh
   sudo usermod -aG docker $USER
   # Log out and back in for group changes to take effect
   ```

2. **Git**
   ```bash
   # Check Git version
   git --version
   
   # Install Git (Ubuntu/Debian)
   sudo apt-get update
   sudo apt-get install git
   ```

3. **MySQL/MariaDB** (external database server)
   - Can be a separate server or another Docker container
   - Must be accessible from Docker containers
   - Default Docker bridge network IP: `172.17.0.1`

4. **MySQL Client** (optional, for automatic database creation)
   ```bash
   sudo apt-get install mysql-client
   ```

### System Requirements

- **RAM**: Minimum 512MB, recommended 1GB+
- **Storage**: 500MB for application + space for database
- **Network**: Internet access for initial installation
- **Ports**: Docker containers use bridge networking (no host ports required by default)

## Quick Start

On a fresh Ubuntu server (a 1 GB droplet is plenty; on 512 MB add 2 GB of swap first):

```bash
# Prerequisites: Docker, Git, and MariaDB if the database lives on this server
curl -fsSL https://get.docker.com | sh
sudo apt-get install -y git mariadb-server

# Run the installer from the folder that should hold the install
cd /opt
sudo bash <(curl -s https://raw.githubusercontent.com/D-Best-App/Timesmart/main/deploy/scripts/install.sh)
```

The installer:
1. Checks prerequisites
2. Asks for the company name, timezone, and where the database lives
3. Clones the repository into `Timeclock-<Company>` and writes its `.env`
4. Creates the database (and, for MariaDB on this server, a user of its own such as
   `tc_acme_corp` with a generated password, reachable only from Docker networks)
5. Builds the image if needed and starts the container on the `timesmart-net` network
6. Creates the first super admin, or loads the demo data
7. Optionally schedules the auto clock-out job

Then [publish it over HTTPS](#publish-over-https). Sign-in sets secure cookies, so it
does not work over plain HTTP.

## Detailed Installation

If you prefer to understand each step or need custom configuration:

### Step 1: Clone Repository

```bash
cd /opt
git clone https://github.com/D-Best-App/Timesmart.git Timeclock-YourCompany
cd Timeclock-YourCompany
```

### Step 2: Configure Database

Create the database from the schema, and record the bundled migrations as applied
(the schema already contains them; otherwise the first `update.sh` replays them):

```bash
DB=timeclock-YourCompany
{ sed "s/DB_NAME_PLACEHOLDER/$DB/g" deploy/database/timeclock-schema.sql
  echo "USE \`$DB\`;"
  for f in deploy/database/migrations/*.sql; do
    echo "INSERT IGNORE INTO schema_migrations (filename) VALUES ('$(basename "$f")');"
  done
} | sudo mariadb

sudo mariadb -e "CREATE USER 'timeclock'@'172.%' IDENTIFIED BY 'a-long-random-password';
                 GRANT ALL PRIVILEGES ON \`$DB\`.* TO 'timeclock'@'172.%';"
```

Containers reach a database on the Docker host over the bridge network, so MariaDB must
listen on more than `127.0.0.1` (`bind-address = 0.0.0.0` in
`/etc/mysql/mariadb.conf.d/60-timesmart.cnf`). Keep port 3306 closed to the internet:
`ufw allow from 172.16.0.0/12 to any port 3306 proto tcp` with ufw enabled.

### Step 3: Configure `.env`

`docker-compose.yml` is the same for every install and stays unmodified, so updates
pull cleanly. Per-install values go in `.env` (git-ignored):

```bash
cp .env.example .env
chmod 600 .env
nano .env
```

```ini
CONTAINER_NAME='Timeclock-YourCompany'
DB_HOST='host.docker.internal'      # MariaDB on this Docker host
DB_NAME='timeclock-YourCompany'
DB_USER='timeclock'
DB_PASS='a-long-random-password'
DB_TIMEZONE='America/Chicago'
```

Keep the single quotes: Compose then reads the value literally, so `$` and `#` are safe.

### Step 4: Start Container

```bash
# Shared network for the app and its reverse proxy / tunnel (once per server)
docker network create --subnet 172.19.0.0/16 timesmart-net

# Builds the image from deploy/docker on first run, then starts it
docker compose up -d
docker exec Timeclock-YourCompany composer install --no-dev --optimize-autoloader -d /var/www/html
docker exec Timeclock-YourCompany chown -R www-data:www-data /var/www/html/vendor
```

### Step 5: Create the First Admin (or Demo Data)

```bash
# A real first super admin (prompts are easiest via install.sh; this is the same script)
read -s -p "Password: " ADMIN_PASS; echo
docker compose run --rm -T -v "$PWD":/repo -w /repo \
  -e ADMIN_FIRST=Jane -e ADMIN_LAST=Smith -e ADMIN_EMAIL=jane@example.com -e ADMIN_PASS="$ADMIN_PASS" \
  app php -d variables_order=EGPCS deploy/scripts/create_admin.php

# ...or demo data instead (empty database only; logins in docs/DEMO.md)
docker compose run --rm -T -v "$PWD":/repo -w /repo app php -d variables_order=EGPCS deploy/scripts/seed_demo.php
```

## Publish over HTTPS

The container publishes no host ports. Anything on the `timesmart-net` network reaches
it at `http://Timeclock-YourCompany:80`:

- **Cloudflare Tunnel** (no open ports, and Cloudflare Access can sit in front): create a
  tunnel with a public hostname pointing at `http://Timeclock-YourCompany:80`, then run
  the connector on the same network:

  ```yaml
  # /opt/cloudflared/docker-compose.yml (TUNNEL_TOKEN=... in /opt/cloudflared/.env)
  services:
    cloudflared:
      image: cloudflare/cloudflared:latest
      restart: unless-stopped
      command: tunnel --no-autoupdate run
      env_file: .env
      networks: [timesmart-net]
  networks:
    timesmart-net:
      external: true
  ```

- **Nginx Proxy Manager / Caddy**: attach the proxy container to `timesmart-net` and
  forward the hostname to `Timeclock-YourCompany:80`.

`deploy/docker/nginx.conf` takes the client IP from `CF-Connecting-IP` for requests from
Cloudflare ranges and the `172.17.0.0/16` / `172.19.0.0/16` Docker networks, which is
why the installer creates `timesmart-net` on `172.19.0.0/16`.

## Post-Installation

### First Login

Sign in at `https://<your hostname>/user/login.php` with the admin's **full name**
(`First Last`) and password. There are no default credentials.

### Security Setup

1. **Mail server**: Admin Panel → Settings → mail. Needed for emailed sign-in codes
   (2FA) and time-off notices.
2. **Two-factor sign-in**: enable it for admins once mail works. Codes arrive by email.
3. **More admins**: Admin Panel → Manage Admins promotes existing employees.

### Basic Configuration

1. **Company Settings**
   - Set company name, timezone
   - Configure GPS enforcement if needed

2. **Create Employees**
   - Admin Panel → Manage Users → Add User
   - Set up employee accounts, PINs, badge IDs

3. **Test Clock Functions**
   - Have test employee clock in/out
   - Verify time calculations
   - Test kiosk mode if using badges

### Optional: Setup Backups

Configure automatic database backups:

```bash
# Edit backup script with your credentials
sudo nano deploy/scripts/backup.sh

# Set DB_USER, DB_PASS, DB_HOST, BACKUP_BASE

# Test backup
sudo deploy/scripts/backup.sh

# Schedule hourly backups via cron
crontab -e

# Add line:
0 * * * * /opt/Timeclock-YourCompany/deploy/scripts/backup.sh
```

Backups are stored in:
- Hourly backups: `/var/sql-data/<company>/` (last 8 kept)
- Daily backups: `/var/sql-data/<company>/daily-backup/` (8 PM backups)

## Multi-Company Setup

To run multiple TimeSmart instances on one server:

### Method 1: Using Install Script

```bash
# Run installer multiple times
bash <(curl -s https://raw.githubusercontent.com/D-Best-App/Timesmart/main/deploy/scripts/install.sh)

# Each installation:
# - Creates separate directory: Timeclock-CompanyA, Timeclock-CompanyB
# - Creates separate container: Timeclock-CompanyA, Timeclock-CompanyB
# - Creates separate database: timeclock-companya, timeclock-companyb
# - Shares the timesmart-net network; the proxy routes each hostname to its container
```

### Method 2: Manual Multi-Company

```bash
# Company A
git clone https://github.com/D-Best-App/Timesmart.git Timeclock-CompanyA
cd Timeclock-CompanyA
cp .env.example .env   # set CONTAINER_NAME, DB_NAME, ... for CompanyA
docker compose up -d

# Company B
git clone https://github.com/D-Best-App/Timesmart.git Timeclock-CompanyB
cd Timeclock-CompanyB
cp .env.example .env   # set CONTAINER_NAME, DB_NAME, ... for CompanyB
docker compose up -d
```

Each company gets:
- Isolated container with unique IP
- Separate database
- Independent configuration
- Shared database server (cost-effective)

## Manual Installation

If you prefer not to use Docker:

### Requirements

- PHP 8.3+ with extensions: mysqli, pdo_mysql, zip, gd
- Nginx or Apache
- Composer for dependencies

### Steps

1. **Clone repository**
   ```bash
   git clone https://github.com/D-Best-App/Timesmart.git
   ```

2. **Install dependencies**
   ```bash
   cd Timesmart/app
   composer install --no-dev --optimize-autoloader
   ```

3. **Configure web server**
   - Document root: `Timesmart/app/`
   - PHP-FPM or mod_php
   - See `deploy/docker/nginx.conf` for reference

4. **Set environment variables**
   ```bash
   export DB_HOST=localhost
   export DB_NAME=timeclock
   export DB_USER=timeclock
   export DB_PASS=password
   export DB_TIMEZONE=America/Chicago
   ```

5. **Create database**
   ```bash
   mysql -u root -p < deploy/database/timeclock-schema.sql
   ```

6. **Set permissions**
   ```bash
   chown -R www-data:www-data app/
   chmod -R 755 app/
   ```

## Troubleshooting

### Container Won't Start

```bash
# Check logs
docker logs Timeclock-YourCompany

# Common issues:
# - Database connection failed: Check DB_HOST, credentials
# - "set CONTAINER_NAME in .env": .env is missing or incomplete (see .env.example)
# - "network timesmart-net not found": docker network create --subnet 172.19.0.0/16 timesmart-net
# - Permission denied: Run with sudo or add user to docker group
```

### Can't Access Application

```bash
# Verify container is running
docker ps

# Get container IP
docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' Timeclock-YourCompany

# Test connectivity
ping <container_ip>
curl http://<container_ip>

# Check firewall
sudo ufw status
```

### Database Connection Failed

```bash
# From host, test database connection
mysql -h 172.17.0.1 -u timeclock -p

# Common issues:
# - MySQL not allowing connections from Docker network
# - Check MySQL bind-address (should be 0.0.0.0 or Docker bridge IP)
# - Grant permissions:
#   GRANT ALL ON timeclock-yourcompany.* TO 'timeclock'@'172.17.%' IDENTIFIED BY 'password';
```

### Changes Not Appearing

With volume mounts, changes should appear immediately. If not:

```bash
# Restart container
docker restart Timeclock-YourCompany

# Clear Cloudflare cache if using CDN
# In browser, append ?nocache=1 to URL
```

### Permission Errors in Container

```bash
# Fix ownership inside container
docker exec Timeclock-YourCompany chown -R www-data:www-data /var/www/html
```

## Next Steps

- Read [DEPLOYMENT.md](DEPLOYMENT.md) for update procedures
- Read [CONFIGURATION.md](CONFIGURATION.md) for advanced settings
- Read [DEVELOPMENT.md](DEVELOPMENT.md) for code architecture
- Read [CLAUDE.md](../CLAUDE.md) for operations documentation

## Getting Help

- **GitHub Issues**: https://github.com/D-Best-App/Timesmart/issues
- **Documentation**: https://github.com/D-Best-App/Timesmart/tree/main/docs
