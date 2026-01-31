# D-BEST TimeSmart Installation Guide

This guide walks you through installing D-BEST TimeSmart, a PHP-based employee timekeeping system.

## Table of Contents

- [Prerequisites](#prerequisites)
- [Quick Start](#quick-start)
- [Detailed Installation](#detailed-installation)
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

For a standard installation, run the automated installer:

```bash
# Run the installation script
bash <(curl -s https://raw.githubusercontent.com/D-Best-App/Timesmart/main/deploy/scripts/install.sh)
```

The script will:
1. Check prerequisites
2. Ask for company name and database credentials
3. Clone the repository
4. Configure Docker
5. Create the database (optional)
6. Start the container
7. Display access information

**That's it!** Skip to [Post-Installation](#post-installation) after the installer completes.

## Detailed Installation

If you prefer to understand each step or need custom configuration:

### Step 1: Clone Repository

```bash
# Choose installation location
cd /opt  # or wherever you want to install

# Clone repository
git clone https://github.com/D-Best-App/Timesmart.git Timeclock-YourCompany
cd Timeclock-YourCompany
```

### Step 2: Configure Database

Create a database for TimeSmart:

```bash
# Connect to MySQL
mysql -h <db_host> -u <db_user> -p

# Create database
CREATE DATABASE `timeclock-yourcompany` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
EXIT;

# Import schema
mysql -h <db_host> -u <db_user> -p timeclock-yourcompany < deploy/database/timeclock-schema.sql
```

The schema includes:
- 8 tables for users, punches, audit logs, settings
- Default admin user (admin/password)
- Sample configuration

### Step 3: Configure Docker Compose

Edit `docker-compose.yml` to set your configuration:

```yaml
services:
  app:
    container_name: Timeclock-YourCompany  # Change this
    environment:
      DB_HOST: 172.17.0.1                  # Your database host
      DB_NAME: timeclock-yourcompany       # Your database name
      DB_USER: timeclock                   # Database user
      DB_PASS: your_secure_password        # Database password
      DB_TIMEZONE: America/Chicago         # Your timezone
```

### Step 4: Start Container

```bash
# Start container in background
docker compose up -d

# Check container status
docker ps

# View logs
docker logs Timeclock-YourCompany
```

### Step 5: Get Access Information

```bash
# Get container IP address
docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' Timeclock-YourCompany

# Access the application
# http://<container_ip>/
```

## Post-Installation

### First Login

1. **Access Admin Portal**: `http://<container_ip>/admin/`
2. **Default Credentials**:
   - Username: `admin`
   - Password: `password`

### Security Setup (CRITICAL)

⚠️ **IMMEDIATELY after first login:**

1. **Change Default Password**
   - Admin Panel → Settings → Change Password
   - Use a strong password (14+ characters)

2. **Enable Two-Factor Authentication (2FA)**
   - Admin Panel → Security Settings → Enable 2FA
   - Scan QR code with Google Authenticator or Authy
   - Save recovery codes securely

3. **Review Admin Accounts**
   - Admin Panel → Manage Admins
   - Remove any unused accounts

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

### Optional: Expose on Network

By default, containers use bridge networking (only accessible via container IP). To expose on a port:

```yaml
# In docker-compose.yml, uncomment:
ports:
  - "8080:80"  # Access via http://server_ip:8080
```

Then restart:
```bash
docker compose down
docker compose up -d
```

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
# - Gets unique container IP
```

### Method 2: Manual Multi-Company

```bash
# Company A
git clone https://github.com/D-Best-App/Timesmart.git Timeclock-CompanyA
cd Timeclock-CompanyA
# Configure docker-compose.yml for CompanyA
docker compose up -d

# Company B
git clone https://github.com/D-Best-App/Timesmart.git Timeclock-CompanyB
cd Timeclock-CompanyB
# Configure docker-compose.yml for CompanyB
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
# - Port already in use: Change port in docker-compose.yml
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
