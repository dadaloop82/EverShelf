FROM php:8.2-apache-bookworm

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

# Health check
HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
    CMD curl -f http://localhost/ || exit 1

CMD ["apache2-foreground"]
