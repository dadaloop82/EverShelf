FROM php:8.5-apache-bookworm

# Install required PHP extensions + Tesseract OCR for offline expiry date reading
RUN apt-get update && apt-get upgrade -y && apt-get install -y \
    libsqlite3-dev \
    sqlite3 \
    libcurl4-openssl-dev \
    libonig-dev \
    libgd-dev \
    libzip-dev \
    libicu-dev \
    ca-certificates \
    cron \
    curl \
    tesseract-ocr \
    tesseract-ocr-ita \
    tesseract-ocr-eng \
    && docker-php-ext-install pdo_sqlite curl mbstring gd zip intl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite and mod_headers
RUN a2enmod rewrite headers

# OPcache + PHP limits (see docker/php-evershelf.ini)
RUN docker-php-ext-enable opcache 2>/dev/null || true
COPY docker/php-evershelf.ini /usr/local/etc/php/conf.d/zz-evershelf.ini

# Set working directory
WORKDIR /var/www/html

# Offline category classification. The ~23 MB MiniLM weights are gitignored, so a
# published image used to ship without them and quietly fell back to the jsdelivr
# CDN, while a developer's local image carried them: same tag, two different
# artefacts. They are fetched here instead, from a layer of their own (only the
# script is copied first), so both images end up identical and an application
# change never re-downloads the weights.
COPY scripts/install-transformers-model.sh /var/www/html/scripts/
RUN bash /var/www/html/scripts/install-transformers-model.sh

# Copy application files
COPY . /var/www/html/

# Cron inside the container. The daily backup, the smart-shopping cache, the
# barcode sync and the retention passes only happen when
# api/cron_smart_shopping.php runs: without a scheduler here, BACKUP_ENABLED=true
# is a promise the container never kept (a Docker/Unraid upgrade could not fall
# back to a backup). The file is /etc/cron.d/evershelf — root:root 0644, or cron
# refuses it — and the job runs as www-data, not root.
COPY docker/evershelf-cron /etc/cron.d/evershelf
RUN chmod 0644 /etc/cron.d/evershelf

# Create data directory with proper permissions
RUN mkdir -p /var/www/html/data/backups \
    && chown -R www-data:www-data /var/www/html/data \
    && chmod -R 775 /var/www/html/data

# Create .env from example if it doesn't exist (will be overridden by volume mount)
RUN [ ! -f /var/www/html/.env ] && cp /var/www/html/.env.example /var/www/html/.env || true

# Apache configuration (vhost conf is versioned in docker/apache-evershelf.conf)
COPY docker/apache-evershelf.conf /etc/apache2/conf-available/evershelf.conf
RUN a2enconf evershelf

# Expose port 80
EXPOSE 80

# Bind-mount entrypoint: Synology / Unraid host paths shadow the image chown,
# so www-data cannot create evershelf.db until we fix ownership at start.
COPY docker/entrypoint.sh /usr/local/bin/evershelf-entrypoint.sh
RUN chmod 0755 /usr/local/bin/evershelf-entrypoint.sh

# Health check
HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
    CMD curl -f http://localhost/ || exit 1

ENTRYPOINT ["evershelf-entrypoint.sh"]

# Cron daemon + Apache in one container. -f keeps cron attached so its own
# messages reach `docker logs` (the image has no syslog daemon); Apache stays the
# long-running process the container lifecycle follows.
CMD ["sh", "-c", "cron -f -L 15 & exec apache2-foreground"]
