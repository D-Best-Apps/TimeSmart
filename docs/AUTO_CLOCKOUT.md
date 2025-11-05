# Auto Clock-Out Feature

## Overview

The auto clock-out feature automatically clocks out employees who forget to clock out at the end of their shift. The system runs at midnight and clocks out anyone still marked as "In", setting their clock-out time retroactively to 5:00 PM.

## Quick Setup

Run the automated setup script:

```bash
cd /home/techadmin/Timeclock-
sudo ./deploy/scripts/setup_auto_clockout.sh
```

This interactive script will:
- ✓ Check if cron job is already installed
- ✓ Create log file with proper permissions
- ✓ Install the midnight cron job
- ✓ Run a test execution
- ✓ Display configuration and usage instructions

**That's it!** The auto clock-out feature is now active.

## How It Works

1. At midnight, the script queries for all users with `ClockStatus = 'In'`
2. For each user, it finds their open punch record (where `TimeOut IS NULL`)
3. Sets the `TimeOut` to 5:00 PM of the punch date
4. Calculates `TotalHours` including lunch deductions
5. Adds a note: "Auto-clocked out at 5:00 PM - forgot to clock out"
6. Updates the user's `ClockStatus` to 'Out'
7. Logs the change in `punch_changelog` with `ChangedBy = 'SYSTEM'`

## Manual Installation (Alternative)

If you prefer to set up manually without the setup script:

### 1. Test the Script

```bash
sudo /opt/Timeclock-D-Best/deploy/scripts/auto_clockout_wrapper.sh
```

### 2. Add Cron Job

```bash
sudo crontab -e
```

Add this line:
```cron
0 0 * * * /opt/Timeclock-D-Best/deploy/scripts/auto_clockout_wrapper.sh >> /var/log/timeclock-auto-clockout.log 2>&1
```

## Configuration

### Change Clock-Out Time

Edit the configuration in the PHP script:

```bash
nano /home/techadmin/Timeclock-/app/scripts/auto_clockout.php
```

Change line 16:
```php
define('AUTO_CLOCKOUT_TIME', '17:00:00'); // 5:00 PM
```

**Common times:**
- `'17:00:00'` - 5:00 PM (default)
- `'18:00:00'` - 6:00 PM
- `'16:00:00'` - 4:00 PM
- `'19:00:00'` - 7:00 PM

### Change Note Message

Edit line 17 in the same file:
```php
define('AUTO_CLOCKOUT_NOTE', 'Auto-clocked out at 5:00 PM - forgot to clock out');
```

### Change Schedule

Edit the cron job:
```bash
sudo crontab -e
```

Examples:
```cron
# Run at 1:00 AM instead of midnight
0 1 * * * /opt/Timeclock-D-Best/deploy/scripts/auto_clockout_wrapper.sh >> /var/log/timeclock-auto-clockout.log 2>&1

# Run at 11:59 PM
59 23 * * * /opt/Timeclock-D-Best/deploy/scripts/auto_clockout_wrapper.sh >> /var/log/timeclock-auto-clockout.log 2>&1
```

## Monitoring

### View Logs

```bash
# Watch logs in real-time
sudo tail -f /var/log/timeclock-auto-clockout.log

# View all logs
sudo cat /var/log/timeclock-auto-clockout.log

# View logs from specific date
sudo grep "2025-11-05" /var/log/timeclock-auto-clockout.log
```

### View Audit Trail (Database)

```sql
-- View all auto clock-outs
SELECT pc.*, u.FirstName, u.LastName
FROM punch_changelog pc
JOIN users u ON pc.EmployeeID = u.ID
WHERE pc.ChangedBy = 'SYSTEM'
ORDER BY pc.ChangeTime DESC
LIMIT 50;

-- View auto clock-outs for specific employee
SELECT * FROM punch_changelog
WHERE EmployeeID = 5 AND ChangedBy = 'SYSTEM'
ORDER BY ChangeTime DESC;

-- View auto clock-outs for date range
SELECT pc.*, u.FirstName, u.LastName
FROM punch_changelog pc
JOIN users u ON pc.EmployeeID = u.ID
WHERE pc.ChangedBy = 'SYSTEM'
  AND pc.Date >= '2025-11-01'
  AND pc.Date <= '2025-11-30'
ORDER BY pc.ChangeTime DESC;
```

### Check Cron Job Status

```bash
# View installed cron jobs
sudo crontab -l

# Check if auto clockout is scheduled
sudo crontab -l | grep auto_clockout

# View cron service status
sudo systemctl status cron  # Debian/Ubuntu
sudo systemctl status crond  # CentOS/RHEL
```

## Testing

### Manual Test

```bash
# Run the script manually (doesn't wait for midnight)
sudo /opt/Timeclock-D-Best/deploy/scripts/auto_clockout_wrapper.sh
```

Expected output:
```
[Auto ClockOut Wrapper] 2025-11-05 15:27:01 - Starting auto clock-out process
[2025-11-05 15:27:01] Starting auto clock-out process...
Found 3 employee(s) still clocked in:
  Processing: John Doe (ID: 5)...
    SUCCESS: Clocked out at 2025-11-05 17:00:00 with 8.5 hours
  ...

[2025-11-05 15:27:02] Auto clock-out complete.
  Processed: 3 employee(s)
  Errors: 0
```

If no one is clocked in:
```
No employees currently clocked in. Nothing to do.
```

### Verify Database Changes

```bash
# Check recent punches
mysql -h 172.17.0.1 -u timeclock -p'SecureNet25!' timeclock-D-Best -e "
  SELECT u.FirstName, u.LastName, u.ClockStatus,
         t.Date, t.TimeIN, t.TimeOUT, t.TotalHours, t.Note
  FROM users u
  LEFT JOIN timepunches t ON u.ID = t.EmployeeID
  WHERE t.Date = CURDATE()
  ORDER BY t.TimeIN DESC
  LIMIT 10;
"
```

## Troubleshooting

### Cron Job Not Running

**Check if cron service is running:**
```bash
sudo systemctl status cron  # Debian/Ubuntu
sudo systemctl status crond  # CentOS/RHEL

# Start if not running
sudo systemctl start cron
```

**Check cron logs:**
```bash
sudo grep -i cron /var/log/syslog  # Debian/Ubuntu
sudo grep -i cron /var/log/messages  # CentOS/RHEL
```

**Verify cron job is installed:**
```bash
sudo crontab -l | grep auto_clockout
```

### Container Not Running

```bash
# Check container status
sudo docker ps -a | grep Timeclock-D-Best

# Start if stopped
sudo docker start Timeclock-D-Best

# Check container logs
sudo docker logs Timeclock-D-Best --tail 50
```

### Script Errors

**Test database connection:**
```bash
sudo docker exec Timeclock-D-Best php -r "
  require '/var/www/html/auth/db.php';
  if (\$conn) { echo 'DB connected OK\n'; } else { echo 'DB connection failed\n'; }
"
```

**Check for PHP errors:**
```bash
sudo docker exec Timeclock-D-Best php -l /var/www/html/scripts/auto_clockout.php
```

### No Employees Processed

This is normal if everyone clocked out properly! Message:
```
No employees currently clocked in. Nothing to do.
```

### Check Specific Employee Status

```bash
# Check employee clock status
mysql -h 172.17.0.1 -u timeclock -p'SecureNet25!' timeclock-D-Best -e "
  SELECT ID, FirstName, LastName, ClockStatus 
  FROM users 
  WHERE ClockStatus = 'In';
"
```

## Disabling Auto Clock-Out

### Temporary Disable

Comment out the cron job:
```bash
sudo crontab -e
```

Add `#` in front of the line:
```cron
# 0 0 * * * /opt/Timeclock-D-Best/deploy/scripts/auto_clockout_wrapper.sh >> /var/log/timeclock-auto-clockout.log 2>&1
```

### Permanent Disable

Remove the cron job:
```bash
sudo crontab -e
# Delete the auto_clockout line and save
```

Or use this command:
```bash
sudo crontab -l | grep -v "auto_clockout_wrapper.sh" | sudo crontab -
```

## Files and Locations

| File | Location | Purpose |
|------|----------|---------|
| PHP Script | `app/scripts/auto_clockout.php` | Core logic for auto clock-out |
| Wrapper Script | `deploy/scripts/auto_clockout_wrapper.sh` | Docker execution wrapper |
| Setup Script | `deploy/scripts/setup_auto_clockout.sh` | Automated installation |
| Log File | `/var/log/timeclock-auto-clockout.log` | Execution logs |
| Documentation | `docs/AUTO_CLOCKOUT.md` | This file |

## Log Rotation

To prevent log file from growing too large, set up log rotation:

```bash
sudo nano /etc/logrotate.d/timeclock-auto-clockout
```

Add:
```
/var/log/timeclock-auto-clockout.log {
    daily
    rotate 30
    compress
    delaycompress
    missingok
    notifempty
    create 0644 root root
}
```

Test log rotation:
```bash
sudo logrotate -d /etc/logrotate.d/timeclock-auto-clockout
```

## Security and Audit

- **Attribution**: All auto clock-outs are logged with `ChangedBy = 'SYSTEM'`
- **Audit Trail**: Every change is recorded in `punch_changelog` table
- **No Data Loss**: Original clock-in time and GPS data are preserved
- **Read-Only Access**: Script only reads user table, updates punch records
- **Transparent**: Notes clearly indicate automatic clock-out

## Support

For issues or questions:

1. **Check logs**: `/var/log/timeclock-auto-clockout.log`
2. **Check database**: `punch_changelog` table for SYSTEM entries
3. **Test manually**: Run wrapper script to see real-time output
4. **Verify config**: Check PHP script configuration
5. **Check cron**: Ensure cron job is installed and service is running

## Advanced Configuration

### Different Times for Different Days

Edit cron for different schedules:
```cron
# 5 PM on weekdays, 3 PM on Fridays
0 0 * * 1-4 /opt/Timeclock-D-Best/deploy/scripts/auto_clockout_wrapper.sh
0 0 * * 5 /opt/Timeclock-D-Best/deploy/scripts/auto_clockout_wrapper.sh
```

Then modify PHP script to check day of week and adjust time accordingly.

### Email Notifications

Add email notification to the wrapper script to alert admins when auto clock-outs occur.

### Custom Logic

Edit `app/scripts/auto_clockout.php` to add custom logic:
- Skip certain employees
- Use different times based on shift
- Send alerts for multiple consecutive auto clock-outs
- etc.

## What Gets Updated

When auto clock-out runs, these database fields are modified:

**timepunches table:**
- `TimeOut` → Set to 5:00 PM
- `TotalHours` → Recalculated
- `Note` → Appended with auto clock-out message

**users table:**
- `ClockStatus` → Changed from 'In' to 'Out'

**punch_changelog table:**
- New row inserted with audit information

## Example Output

Successful execution with employees found:
```
[Auto ClockOut Wrapper] 2025-11-05 21:28:23 - Starting auto clock-out process
[2025-11-05 15:28:23] Starting auto clock-out process...
Found 10 employee(s) still clocked in:
  Processing: Chris Provence (ID: 2)...
    SUCCESS: Clocked out at 2025-11-05 17:00:00 with 8.13 hours
  Processing: Cody Sexton (ID: 3)...
    SUCCESS: Clocked out at 2025-11-05 17:00:00 with 8.00 hours
  ...
[2025-11-05 15:28:24] Auto clock-out complete.
  Processed: 10 employee(s)
  Errors: 0
[Auto ClockOut Wrapper] 2025-11-05 21:28:24 - Auto clock-out completed successfully
```

No employees to process:
```
[Auto ClockOut Wrapper] 2025-11-05 00:00:01 - Starting auto clock-out process
[2025-11-05 00:00:01] Starting auto clock-out process...
No employees currently clocked in. Nothing to do.
[Auto ClockOut Wrapper] 2025-11-05 00:00:01 - Auto clock-out completed successfully
```

## FAQ

**Q: What happens if someone clocked in at 6 PM and it's run at midnight?**
A: They'll be clocked out at 5 PM the same day, resulting in -1 hour or 0 hours. You may want to adjust the logic to handle this case.

**Q: Can I exclude certain employees?**
A: Yes, edit the PHP script and add a WHERE clause to exclude specific IDs or roles.

**Q: Does it work with lunch breaks?**
A: Yes, lunch time is properly deducted from total hours.

**Q: What if employee took lunch but didn't clock back in?**
A: The script preserves the LunchStart but LunchEnd will be NULL, so lunch time won't be deducted.

**Q: Can I run it multiple times?**
A: Yes, it's safe. It only affects employees with `ClockStatus = 'In'`, so running it twice won't duplicate anything.

**Q: Where can I see who was auto-clocked out?**
A: Check the `punch_changelog` table for entries with `ChangedBy = 'SYSTEM'`.
