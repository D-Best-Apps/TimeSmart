# ------------------------------
# Dockerfile (with Supervisor)
# ------------------------------
FROM php:8.3-fpm

# Install dependencies
RUN apt-get update && apt-get install -y \
    nginx \
    git \
    supervisor \
    libzip-dev \
    nano \
    unzip \
    libpng-dev \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mysqli zip gd

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy configs
COPY nginx.conf /etc/nginx/sites-available/default
RUN rm /etc/nginx/sites-enabled/default && ln -s /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY www.conf /usr/local/etc/php-fpm.d/www.conf

# Set working directory
WORKDIR /var/www/html

# Clone repo; the web root is the repo's app/ directory (composer.json lives there)
RUN find . -mindepth 1 -delete \
    && git clone --depth 1 https://github.com/D-Best-App/Timesmart.git /tmp/timesmart \
    && cp -a /tmp/timesmart/app/. . \
    && rm -rf /tmp/timesmart

# Install Composer deps
RUN composer install --no-dev --optimize-autoloader

# Permissions
RUN chown -R www-data:www-data /var/www/html

# Expose HTTP
EXPOSE 80

# Override default ENTRYPOINT to avoid php-fpm conflict
ENTRYPOINT []

# Launch Supervisor
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
