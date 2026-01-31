#!/bin/bash
#
# D-BEST TimeSmart Installation Script
# Creates a new TimeSmart installation with volume-mounted application files
#

set -e  # Exit on error

# Color codes for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}"
echo "╔═══════════════════════════════════════╗"
echo "║   D-BEST TimeSmart Installation      ║"
echo "╔═══════════════════════════════════════╗"
echo -e "${NC}"

# ========================
# Prerequisites Check
# ========================
echo -e "${YELLOW}Checking prerequisites...${NC}"

if ! command -v docker &> /dev/null; then
    echo -e "${RED}✗ Error: Docker is not installed.${NC}"
    echo "Please install Docker first: https://docs.docker.com/engine/install/"
    exit 1
fi

if ! docker info > /dev/null 2>&1; then
    echo -e "${RED}✗ Error: Docker is not running.${NC}"
    echo "Please start Docker and try again."
    exit 1
fi

if ! command -v git &> /dev/null; then
    echo -e "${RED}✗ Error: Git is not installed.${NC}"
    echo "Please install git first: sudo apt-get install git"
    exit 1
fi

if ! command -v mysql &> /dev/null; then
    echo -e "${YELLOW}⚠ Warning: MySQL client not found.${NC}"
    echo "You'll need to create the database manually."
    MYSQL_AVAILABLE=false
else
    MYSQL_AVAILABLE=true
fi

echo -e "${GREEN}✓ Prerequisites check passed${NC}\n"

# ========================
# Collect Information
# ========================
echo -e "${BLUE}Please provide installation details:${NC}"

# Company name
read -p "Enter company name (e.g., Acme Corp): " company_name
if [ -z "$company_name" ]; then
    echo -e "${RED}✗ Company name cannot be empty${NC}"
    exit 1
fi

# Sanitize company name for directory/container name
SAFE_NAME=$(echo "$company_name" | sed 's/[^a-zA-Z0-9]/-/g')
INSTALL_DIR="Timeclock-$SAFE_NAME"
CONTAINER_NAME="Timeclock-$SAFE_NAME"

# Check if directory already exists
if [ -d "$INSTALL_DIR" ]; then
    echo -e "${YELLOW}⚠ Warning: Directory $INSTALL_DIR already exists${NC}"
    read -p "Do you want to continue and overwrite? (y/n): " continue_install
    if [ "$continue_install" != "y" ]; then
        echo "Installation cancelled."
        exit 0
    fi
fi

# Database configuration
echo -e "\n${BLUE}Database Configuration:${NC}"
read -p "Database host (default: 172.17.0.1): " db_host
db_host=${db_host:-172.17.0.1}

read -p "Database user (default: timeclock): " db_user
db_user=${db_user:-timeclock}

read -s -p "Database password: " db_pass
echo

if [ -z "$db_pass" ]; then
    echo -e "${RED}✗ Database password cannot be empty${NC}"
    exit 1
fi

DB_NAME="timeclock-$SAFE_NAME"

# Timezone
read -p "Database timezone (default: America/Chicago): " db_timezone
db_timezone=${db_timezone:-America/Chicago}

# ========================
# Clone Repository
# ========================
echo -e "\n${YELLOW}📥 Cloning TimeSmart repository...${NC}"

if [ -d "$INSTALL_DIR" ]; then
    rm -rf "$INSTALL_DIR"
fi

git clone https://github.com/D-Best-App/Timesmart.git "$INSTALL_DIR"
cd "$INSTALL_DIR"

echo -e "${GREEN}✓ Repository cloned${NC}"

# ========================
# Configure Docker Compose
# ========================
echo -e "\n${YELLOW}🐳 Configuring Docker container...${NC}"

# Replace placeholders in docker-compose.yml
sed -i "s/COMPANY_NAME_PLACEHOLDER/$CONTAINER_NAME/g" docker-compose.yml
sed -i "s|DB_HOST_PLACEHOLDER|$db_host|g" docker-compose.yml
sed -i "s/DB_NAME_PLACEHOLDER/$DB_NAME/g" docker-compose.yml
sed -i "s/DB_USER_PLACEHOLDER/$db_user/g" docker-compose.yml
sed -i "s/DB_PASS_PLACEHOLDER/$db_pass/g" docker-compose.yml
sed -i "s|America/Chicago|$db_timezone|g" docker-compose.yml

echo -e "${GREEN}✓ Docker configuration created${NC}"

# ========================
# Create Database
# ========================
if [ "$MYSQL_AVAILABLE" = true ]; then
    echo -e "\n${BLUE}Database Setup:${NC}"
    read -p "Create database '$DB_NAME'? (y/n): " create_db
    
    if [ "$create_db" = "y" ]; then
        echo -e "${YELLOW}📦 Creating database...${NC}"
        
        # Replace database name in schema
        sed "s/DB_NAME_PLACEHOLDER/$DB_NAME/g" deploy/database/schema.sql > /tmp/timeclock-schema-temp.sql
        
        # Create database
        if mysql -h "$db_host" -u "$db_user" -p"$db_pass" < /tmp/timeclock-schema-temp.sql 2>/dev/null; then
            rm /tmp/timeclock-schema-temp.sql
            echo -e "${GREEN}✓ Database created and initialized${NC}"
        else
            echo -e "${RED}✗ Failed to create database${NC}"
            echo "You can create it manually later using: deploy/database/schema.sql"
        fi
    else
        echo -e "${YELLOW}⚠ Skipping database creation${NC}"
        echo "Remember to create the database manually: $DB_NAME"
    fi
else
    echo -e "\n${YELLOW}⚠ MySQL client not available - skipping database creation${NC}"
    echo "Please create database '$DB_NAME' manually using: deploy/database/schema.sql"
fi

# ========================
# Start Container
# ========================
echo -e "\n${BLUE}Container Deployment:${NC}"
read -p "Start Docker container now? (y/n): " start_container

if [ "$start_container" = "y" ]; then
    echo -e "${YELLOW}🚀 Starting container...${NC}"
    
    docker compose up -d
    
    if [ $? -eq 0 ]; then
        echo -e "${GREEN}✓ Container started successfully${NC}"
        
        # Wait for container to be ready
        sleep 3
        
        # Install PHP dependencies
        echo -e "${YELLOW}📦 Installing PHP dependencies...${NC}"
        docker exec "$CONTAINER_NAME" composer install --no-dev --optimize-autoloader -d /var/www/html
        docker exec "$CONTAINER_NAME" chown -R www-data:www-data /var/www/html/vendor
        echo -e "${GREEN}✓ Dependencies installed${NC}"
        
        # Get container IP
        CONTAINER_IP=$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' "$CONTAINER_NAME")
        
        echo -e "\n${GREEN}╔═══════════════════════════════════════╗${NC}"
        echo -e "${GREEN}║      Installation Complete!          ║${NC}"
        echo -e "${GREEN}╚═══════════════════════════════════════╝${NC}"
        echo -e "${BLUE}Company:${NC} $company_name"
        echo -e "${BLUE}Container:${NC} $CONTAINER_NAME"
        echo -e "${BLUE}Database:${NC} $DB_NAME"
        echo -e "${BLUE}Container IP:${NC} $CONTAINER_IP"
        echo -e "${BLUE}Access URL:${NC} http://$CONTAINER_IP"
        echo ""
        echo -e "${YELLOW}Default credentials:${NC}"
        echo "  Admin: admin / password"
        echo "  (Change these immediately after first login!)"
        echo ""
        echo -e "${BLUE}Installation directory:${NC} $(pwd)"
        echo ""
        echo -e "${GREEN}Next steps:${NC}"
        echo "1. Access the application at http://$CONTAINER_IP"
        echo "2. Log in with default admin credentials"
        echo "3. Change the default password immediately"
        echo "4. Configure your settings in the admin panel"
        echo ""
        echo -e "${YELLOW}Note:${NC} Application files are volume-mounted from ./app/"
        echo "Any changes to files will be reflected immediately without rebuilding."
        echo ""
        echo "For backup configuration, see: deploy/scripts/backup.sh"
        echo "For documentation, see: docs/INSTALLATION.md"
    else
        echo -e "${RED}✗ Failed to start container${NC}"
        exit 1
    fi
else
    echo -e "${YELLOW}⚠ Skipping container startup${NC}"
    echo "You can start it later with: cd $INSTALL_DIR && docker compose up -d"
fi

echo ""
echo -e "${BLUE}For more information, visit:${NC} https://github.com/D-Best-App/Timesmart"
