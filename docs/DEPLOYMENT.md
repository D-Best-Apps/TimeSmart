# D-BEST TimeSmart Deployment Guide

This guide covers updating, maintaining, and managing TimeSmart installations.

## Table of Contents

- [Updating TimeSmart](#updating-timesmart)
- [Backup and Restore](#backup-and-restore)
- [Container Management](#container-management)
- [Production Best Practices](#production-best-practices)
- [Monitoring and Maintenance](#monitoring-and-maintenance)
- [Disaster Recovery](#disaster-recovery)

## Updating TimeSmart

### Automated Update (Recommended)

Use the included update script:

```bash
# Navigate to installation directory
cd /opt/Timeclock-YourCompany

# Run update script
./deploy/scripts/update.sh
```

The script will:
1. Check current version
2. Prompt for database backup confirmation
3. Pull latest changes from GitHub
4. Update Composer dependencies if needed
5. Restart container
6. Show update summary

### Manual Update

If you prefer manual control:

```bash
cd /opt/Timeclock-YourCompany

# 1. Backup database first (IMPORTANT!)
./deploy/scripts/backup.sh

# 2. Stash any local changes
git status
git stash  # if you have local modifications

# 3. Pull latest changes
git pull origin main

# 4. Update dependencies (if composer.json changed)
cd app
composer install --no-dev --optimize-autoloader
cd ..

# 5. Restart container
docker restart Timeclock-YourCompany

# 6. Verify update
docker logs Timeclock-YourCompany
```

### Rolling Back

If an update causes issues:

```bash
# View commit history
git log --oneline

# Roll back to previous commit
git reset --hard <commit-hash>

# Restart container
docker restart Timeclock-YourCompany

# Restore database if needed
mysql -h <db_host> -u <db_user> -p <db_name> < backup.sql
```

### Volume Mounts Advantage

With volume mounts, code changes are immediately reflected:

- ✅ No need to rebuild Docker images
- ✅ No need for `docker cp` commands
- ✅ Instant updates during development
- ✅ Easy to test changes before committing

Just edit files in `app/` and reload your browser!

## Backup and Restore

### Automated Backups

Configure the backup script:

```bash
# 1. Edit backup script
sudo nano deploy/scripts/backup.sh

# Set these variables:
DB_USER="timeclock"
DB_PASS="your_password"
DB_HOST="172.17.0.1"
BACKUP_BASE="/var/sql-data"

# 2. Test backup
sudo ./deploy/scripts/backup.sh

# 3. Verify backup created
ls -lh /var/sql-data/*/

# 4. Schedule via cron (hourly)
crontab -e

# Add line:
0 * * * * /opt/Timeclock-YourCompany/deploy/scripts/backup.sh
```

### Backup Retention

The backup script maintains:
- **Hourly backups**: Last 8 hourly backups
- **Daily backups**: One backup per day at 8 PM
- **Compressed**: Gzip compressed to save space

### Manual Backup

```bash
# Quick backup
mysqldump -h 172.17.0.1 -u timeclock -p timeclock-yourcompany | gzip > backup-$(date +%Y%m%d-%H%M%S).sql.gz

# Backup with full options
mysqldump -h 172.17.0.1 -u timeclock -p \
  --single-transaction \
  --routines \
  --triggers \
  --events \
  timeclock-yourcompany | gzip > backup.sql.gz
```

### Restore from Backup

```bash
# 1. Stop container (optional, prevents data changes during restore)
docker stop Timeclock-YourCompany

# 2. Decompress backup
gunzip backup.sql.gz

# 3. Restore database
mysql -h 172.17.0.1 -u timeclock -p timeclock-yourcompany < backup.sql

# 4. Start container
docker start Timeclock-YourCompany

# 5. Verify application works
curl http://<container_ip>
```

### Backup Application Files

Database is most critical, but also backup:

```bash
# Backup entire installation
tar -czf timeclock-backup-$(date +%Y%m%d).tar.gz /opt/Timeclock-YourCompany/

# Restore application files
tar -xzf timeclock-backup-20250124.tar.gz -C /opt/
```

## Container Management

### Starting and Stopping

```bash
# Start container
docker start Timeclock-YourCompany

# Stop container
docker stop Timeclock-YourCompany

# Restart container
docker restart Timeclock-YourCompany

# View container status
docker ps -a
```

### Using Docker Compose

```bash
cd /opt/Timeclock-YourCompany

# Start all services
docker compose up -d

# Stop all services
docker compose down

# View logs
docker compose logs -f

# Restart services
docker compose restart
```

### Viewing Logs

```bash
# View all logs
docker logs Timeclock-YourCompany

# Follow logs in real-time
docker logs -f Timeclock-YourCompany

# Last 100 lines
docker logs --tail 100 Timeclock-YourCompany

# Logs since timestamp
docker logs --since 2025-01-24T10:00:00 Timeclock-YourCompany
```

### Accessing Container Shell

```bash
# Enter container
docker exec -it Timeclock-YourCompany /bin/bash

# Inside container:
cd /var/www/html
ls -la
php -v
nginx -t
supervisorctl status

# Exit
exit
```

### Checking Resource Usage

```bash
# Container stats
docker stats Timeclock-YourCompany

# Disk usage
docker system df

# Container size
docker ps -s
```

## Production Best Practices

### Security Hardening

1. **Change Default Credentials Immediately**
   ```bash
   # Admin password changed via web interface
   # Database password set in docker-compose.yml
   ```

2. **Enable Two-Factor Authentication**
   - All admin accounts should use 2FA
   - Configure in Admin Panel → Security

3. **Use Strong Database Passwords**
   ```yaml
   # In docker-compose.yml
   DB_PASS: <64-character random string>
   ```

4. **Restrict File Permissions**
   ```bash
   chmod 600 docker-compose.yml  # Contains passwords
   chmod 700 deploy/scripts/backup.sh  # May contain credentials
   ```

5. **Keep System Updated**
   ```bash
   # Update Docker regularly
   sudo apt-get update && sudo apt-get upgrade docker-ce
   
   # Pull latest TimeSmart updates
   ./deploy/scripts/update.sh
   ```

### Reverse Proxy Setup

Use Nginx or Traefik as reverse proxy for:
- SSL/TLS termination
- Domain names instead of IPs
- Multiple containers on one server

Example Nginx config:

```nginx
server {
    listen 80;
    server_name timeclock.example.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name timeclock.example.com;
    
    ssl_certificate /etc/letsencrypt/live/timeclock.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/timeclock.example.com/privkey.pem;
    
    location / {
        proxy_pass http://172.17.0.5:80;  # Container IP
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

### Cloudflare Configuration

If using Cloudflare:

1. **SSL/TLS Mode**: Full or Full (Strict)
2. **Rocket Loader**: OFF (causes issues with some JS)
3. **Auto Minify**: ON for CSS and HTML, OFF for JS
4. **Caching**: Standard (use query parameters for cache busting)

The nginx.conf includes Cloudflare IP ranges for proper `X-Real-IP` handling.

### Database Optimization

```sql
-- Monitor table sizes
SELECT 
    table_name AS 'Table',
    ROUND(((data_length + index_length) / 1024 / 1024), 2) AS 'Size (MB)'
FROM information_schema.tables
WHERE table_schema = 'timeclock-yourcompany'
ORDER BY (data_length + index_length) DESC;

-- Optimize tables periodically
OPTIMIZE TABLE timepunches;
OPTIMIZE TABLE login_logs;
OPTIMIZE TABLE punch_changelog;

-- Archive old data (older than 2 years)
-- Create archive database first
CREATE DATABASE timeclock_archive;

-- Move old punches
INSERT INTO timeclock_archive.timepunches 
SELECT * FROM timeclock-yourcompany.timepunches 
WHERE Date < DATE_SUB(NOW(), INTERVAL 2 YEAR);

DELETE FROM timeclock-yourcompany.timepunches 
WHERE Date < DATE_SUB(NOW(), INTERVAL 2 YEAR);
```

## Monitoring and Maintenance

### Health Checks

```bash
#!/bin/bash
# health-check.sh

CONTAINER="Timeclock-YourCompany"
IP=$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' "$CONTAINER")

# Check container is running
if ! docker ps | grep -q "$CONTAINER"; then
    echo "ERROR: Container not running"
    exit 1
fi

# Check HTTP response
if ! curl -s -o /dev/null -w "%{http_code}" "http://$IP" | grep -q "200"; then
    echo "ERROR: Application not responding"
    exit 1
fi

echo "OK: Application healthy"
```

### Log Monitoring

```bash
# Watch for errors
docker logs -f Timeclock-YourCompany | grep -i error

# Check PHP errors
docker exec Timeclock-YourCompany tail -f /var/log/php-fpm-error.log

# Check Nginx errors
docker exec Timeclock-YourCompany tail -f /var/log/nginx/error.log
```

### Database Monitoring

```sql
-- Active connections
SHOW PROCESSLIST;

-- Table locks
SHOW OPEN TABLES WHERE In_use > 0;

-- Slow queries (configure slow_query_log)
SELECT * FROM mysql.slow_log ORDER BY start_time DESC LIMIT 10;
```

### Disk Space

```bash
# Check backup directory
du -sh /var/sql-data/*

# Find large files
find /var/sql-data -type f -size +100M

# Clean old backups (older than 30 days)
find /var/sql-data -name "*.sql.gz" -mtime +30 -delete
```

## Disaster Recovery

### Complete System Failure

1. **Restore from backup on new server**
   ```bash
   # Install Docker, Git, MySQL client
   
   # Clone repository
   git clone https://github.com/D-Best-App/Timesmart.git Timeclock-YourCompany
   cd Timeclock-YourCompany
   
   # Configure docker-compose.yml
   # Start container
   docker compose up -d
   
   # Restore database
   gunzip < backup.sql.gz | mysql -h <db_host> -u <db_user> -p <db_name>
   ```

2. **Verify application**
   - Test login
   - Check recent punches
   - Verify reports
   - Test clock functions

### Database Corruption

```bash
# Try repair
mysql -h <db_host> -u <db_user> -p <db_name> -e "REPAIR TABLE timepunches"

# If repair fails, restore from backup
mysql -h <db_host> -u <db_user> -p <db_name> < backup.sql
```

### Container Issues

```bash
# Remove and recreate container
docker compose down
docker compose up -d

# Or remove image and pull fresh
docker compose down
docker rmi dbest25/timesmart:latest
docker compose up -d
```

## Troubleshooting

### Container Keeps Restarting

```bash
# Check logs for errors
docker logs Timeclock-YourCompany

# Common issues:
# - Database connection failed
# - PHP-FPM or Nginx crashed
# - Permission issues

# Fix permissions
docker exec Timeclock-YourCompany chown -R www-data:www-data /var/www/html
```

### High Resource Usage

```bash
# Check what's using resources
docker stats Timeclock-YourCompany

# Adjust PHP-FPM settings in deploy/docker/www.conf:
pm.max_children = 5        # Reduce if low memory
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
```

### Network Issues

```bash
# Restart Docker network
docker network prune

# Restart container
docker restart Timeclock-YourCompany

# Check IP address
docker inspect Timeclock-YourCompany | grep IPAddress
```

## Next Steps

- Read [INSTALLATION.md](INSTALLATION.md) for initial setup
- Read [CONFIGURATION.md](CONFIGURATION.md) for advanced settings
- Read [DEVELOPMENT.md](DEVELOPMENT.md) for code architecture
- Read [CLAUDE.md](../CLAUDE.md) for operations documentation
