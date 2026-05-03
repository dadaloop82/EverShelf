FROM php:8.2-apache

# Install required PHP extensions + Tesseract OCR for offline expiry date reading
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    libcurl4-openssl-dev \
    libonig-dev \
    libgd-dev \
    tesseract-ocr \
    tesseract-ocr-ita \
    tesseract-ocr-eng \
    && docker-php-ext-install pdo_sqlite curl mbstring gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite and mod_headers
RUN a2enmod rewrite headers

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . /var/www/html/

# Create data directory with proper permissions
RUN mkdir -p /var/www/html/data/backups \
    && chown -R www-data:www-data /var/www/html/data \
    && chmod -R 775 /var/www/html/data

# Create .env from example if it doesn't exist (will be overridden by volume mount)
RUN [ ! -f /var/www/html/.env ] && cp /var/www/html/.env.example /var/www/html/.env || true

# Apache configuration: serve from app root
RUN echo '<Directory /var/www/html>\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/evershelf.conf \
    && a2enconf evershelf

# Expose port 80
EXPOSE 80

# Health check
HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
    CMD curl -f http://localhost/ || exit 1

CMD ["apache2-foreground"]
