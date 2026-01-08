# 🕒 D-BEST TimeSmart

**D-BEST TimeSmart** is a modern, web-based time clock application built for small businesses, schools, and organizations needing efficient time tracking and user management. It supports employees clocking in/out and offers powerful tools for administrators to manage users, view reports, and export data — all in a secure and user-friendly interface.

---

## ✅ Key Features

- **🕘 Employee Time Tracking**  
  Clock In, Lunch, Break, and Clock Out with GPS and device logging.
  
- **👥 User Management**  
  Add, update, or remove employee accounts and manage permissions.
  
- **📊 Admin Dashboard**  
  Get real-time overviews of employee activity and time logs.
  
- **📁 Reports & Exports**  
  Generate and export attendance data (PDF, Excel, summary reports).
  
- **🔐 Two-Factor Authentication (2FA)**  
  Built-in support for TOTP 2FA (Google Authenticator, Authy).
  
- **📱 Kiosk Mode**  
  Badge/NFC scanning for PIN-less time clock stations.

- **📄 Legal Pages**  
  Includes Privacy Policy and Terms of Use pages.

- **🔄 Volume-Mounted Development**  
  Edit code on host, changes appear instantly in container.

---

## 🧰 Prerequisites

Ensure the following are installed:

- **Docker** (version 20.10+)  
  Required for containerized deployment.
  
- **MySQL/MariaDB Server**  
  External database server (can be Docker or dedicated server).
  
- **MySQL Client** (optional)  
  For automatic database creation during installation.

- **Git**  
  Required to clone the repository.

---

## 🚀 Quick Installation

Run the official installation script to deploy a new instance:

```bash
bash <(curl -s https://raw.githubusercontent.com/D-Best-App/Timesmart/main/deploy/scripts/install.sh)
```

### 🧩 What the script does:

- Checks prerequisites (Docker, Git, MySQL client)
- Prompts for **Company Name** (sets container/database name)
- Asks for **Database Host, User, and Password**
- Clones full repository to `Timeclock-<CompanyName>`
- Configures `docker-compose.yml` with your settings
- Creates MySQL database and imports schema (optional)
- Starts Docker container with volume mounts
- Shows container IP and access information

**Installation takes ~2 minutes.**

See [docs/INSTALLATION.md](docs/INSTALLATION.md) for detailed installation instructions.

---

## 🌐 Accessing the App

After installation, the script displays the container IP address:

```bash
Container IP: 172.17.0.5
Access URL: http://172.17.0.5
```

Open in your browser:

```
http://<container_ip>
```

For permanent access, configure a reverse proxy (Nginx/Traefik) or use Cloudflare tunnel.

---

## 👨‍💼 Admin Access

- **URL**: `http://<container_ip>/admin/`
- **Default Credentials:**
  - Username: `admin`
  - Password: `password`

**⚠️ CRITICAL:** Change the default password immediately after first login!

It's highly recommended to enable Two-Factor Authentication (2FA) for all admin accounts.

---

## 👷 Employee Access

- **URL**: `http://<container_ip>/user/`
- Employee credentials must be created by an administrator
- Access via: Admin Panel → Manage Users → Add User

---

## 📚 Documentation

Comprehensive documentation for all users:

- **[INSTALLATION.md](docs/INSTALLATION.md)** - Installation guide
  - Prerequisites and system requirements
  - Quick start with automated installer
  - Detailed manual installation
  - Multi-company setup
  - Troubleshooting

- **[DEPLOYMENT.md](docs/DEPLOYMENT.md)** - Operations guide
  - Updating TimeSmart (`update.sh` script)
  - Backup and restore procedures
  - Container management
  - Production best practices
  - Monitoring and maintenance

- **[CONFIGURATION.md](docs/CONFIGURATION.md)** - Configuration reference
  - Environment variables
  - Docker configuration
  - PHP-FPM tuning
  - Nginx configuration
  - Database optimization
  - Security settings

- **[CLAUDE.md](CLAUDE.md)** - Developer documentation
  - Project architecture
  - Database schema
  - Security patterns
  - Development workflow

---

## 🔄 Updating TimeSmart

Updating is easy with the included update script:

```bash
cd /opt/Timeclock-<CompanyName>
./deploy/scripts/update.sh
```

The script will:
1. Prompt for database backup confirmation
2. Pull latest changes from GitHub
3. Update dependencies if needed
4. Restart container
5. Show what changed

**With volume mounts, changes appear immediately — no image rebuilds needed!**

---

## 🛡️ Backup & Restore

### Automated Backups

Configure the included backup script:

```bash
# Edit with your database credentials
sudo nano deploy/scripts/backup.sh

# Test backup
sudo ./deploy/scripts/backup.sh

# Schedule via cron (hourly)
crontab -e
# Add: 0 * * * * /opt/Timeclock-YourCompany/deploy/scripts/backup.sh
```

Backups are stored in `/var/sql-data/<company>/` with:
- Hourly backups (last 8 kept)
- Daily backups at 8 PM

### Manual Backup

```bash
mysqldump -h 172.17.0.1 -u timeclock -p timeclock-yourcompany | gzip > backup.sql.gz
```

See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) for restore procedures.

---

## 🏗️ Project Structure

```
Timeclock-<CompanyName>/
├── app/                      # Application code (volume-mounted)
│   ├── admin/               # Admin portal
│   ├── user/                # Employee portal
│   ├── kiosk/               # Badge scanning interface
│   ├── functions/           # Shared PHP functions
│   ├── auth/                # Database connection
│   └── vendor/              # Composer dependencies
├── deploy/                  # Deployment configuration
│   ├── docker/              # Dockerfile, nginx.conf, www.conf
│   ├── database/            # schema.sql
│   └── scripts/             # install.sh, update.sh, backup.sh
├── docs/                    # Documentation
│   ├── INSTALLATION.md
│   ├── DEPLOYMENT.md
│   └── CONFIGURATION.md
├── docker-compose.yml       # Container orchestration
├── CLAUDE.md                # Developer documentation
└── README.md                # This file
```

---

## 🔧 Development

TimeSmart uses **volume mounts** for easy development:

```bash
# Clone repository
git clone https://github.com/D-Best-App/Timesmart.git
cd Timesmart

# Configure docker-compose.yml

# Start container
docker compose up -d

# Edit files in app/ directory
nano app/admin/dashboard.php

# Changes appear immediately in browser!
# No rebuilds, no docker cp, no hassle.
```

See [CLAUDE.md](CLAUDE.md) for developer documentation.

---

## 🛠️ Troubleshooting

| Issue | Solution |
|-------|----------|
| `docker: command not found` | Install Docker: https://docs.docker.com/engine/install/ |
| `git: command not found` | Install Git: `sudo apt-get install git` |
| Database connection fails | Verify DB credentials in `docker-compose.yml` |
| Container not starting | Check logs: `docker logs Timeclock-<CompanyName>` |
| Changes not appearing | With volume mounts, changes are instant. Try hard refresh (Ctrl+F5) |
| Container IP not responding | Verify container is running: `docker ps` |

For more troubleshooting, see:
- [docs/INSTALLATION.md](docs/INSTALLATION.md) - Installation issues
- [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) - Container/deployment issues
- [docs/CONFIGURATION.md](docs/CONFIGURATION.md) - Configuration problems

---

## 🤝 Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

---

## 📝 License

This project is proprietary software owned by D-Best Technologies.

---

## 🆘 Support

- **GitHub Issues**: https://github.com/D-Best-App/Timesmart/issues
- **Documentation**: [docs/](docs/)
- **Developer Docs**: [CLAUDE.md](CLAUDE.md)

---

## 🎯 Quick Links

- [Installation Guide](docs/INSTALLATION.md)
- [Deployment Guide](docs/DEPLOYMENT.md)
- [Configuration Reference](docs/CONFIGURATION.md)
- [Developer Documentation](CLAUDE.md)
- [Changelog](CHANGELOG.md)

---

**Made with ❤️ by D-BEST App**
