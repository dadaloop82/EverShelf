# 📦 Installation

EverShelf runs on any server with PHP 8.0+ and SQLite. Docker is the recommended approach for the fastest setup.

---

## Prerequisites

| Requirement | Minimum | Notes |
|-------------|---------|-------|
| PHP | 8.0+ | Extensions: `pdo_sqlite`, `curl`, `mbstring`, `json` |
| Web server | Apache 2.4+ or Nginx | Apache `.htaccess` included |
| SQLite | 3.x | Bundled with PHP on most distros |
| HTTPS | Recommended | Required for camera access on mobile browsers |
| RAM | 256 MB | 512 MB+ recommended if using AI features |

---

## Option A: Docker (recommended)

The fastest way to get started.

```bash
# 1. Clone the repository
git clone https://github.com/dadaloop82/EverShelf.git
cd EverShelf

# 2. Create your configuration
cp .env.example .env
nano .env          # set GEMINI_API_KEY and other options

# 3. Start
docker compose up -d

# 4. Open in browser
# → http://localhost:8080
```

The Docker image:
- Uses PHP-Apache on Debian Bookworm slim
- Auto-creates the `data/` directory with correct permissions
- Exposes port `8080` by default (configurable in `docker-compose.yml`)
- Persists data in a named Docker volume

### Changing the port

Edit `docker-compose.yml`:

```yaml
ports:
  - "8080:80"   # change 8080 to your desired host port
```

### Using HTTPS with Docker

Add a reverse proxy (e.g. Traefik, Caddy, or Nginx Proxy Manager) in front of the container for automatic TLS.

---

## Option B: Manual (Apache)

```bash
# 1. Clone into your web root
git clone https://github.com/dadaloop82/EverShelf.git /var/www/html/dispensa
cd /var/www/html/dispensa

# 2. Create configuration
cp .env.example .env
nano .env

# 3. Set permissions on the data directory
chmod 755 data/
chown -R www-data:www-data data/
```

Make sure `mod_rewrite` is enabled:

```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

Apache virtual host (or add to `.htaccess` which is already included):

```apache
<VirtualHost *:443>
    ServerName evershelf.local
    DocumentRoot /var/www/html/dispensa

    <Directory /var/www/html/dispensa>
        AllowOverride All
        Require all granted
    </Directory>

    # Hide sensitive paths
    <LocationMatch "^/(data|\.env|backup\.sh)">
        Require all denied
    </LocationMatch>

    SSLEngine on
    SSLCertificateFile    /etc/ssl/certs/evershelf.crt
    SSLCertificateKeyFile /etc/ssl/private/evershelf.key
</VirtualHost>
```

---

## Option C: Manual (Nginx)

```nginx
server {
    listen 443 ssl;
    server_name evershelf.local;
    root /var/www/html/dispensa;
    index index.html;

    ssl_certificate     /etc/ssl/certs/evershelf.crt;
    ssl_certificate_key /etc/ssl/private/evershelf.key;

    location /api/ {
        try_files $uri $uri/ =404;
        location ~ \.php$ {
            fastcgi_pass unix:/run/php/php8.2-fpm.sock;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            include fastcgi_params;
        }
    }

    # Block sensitive files
    location ~ /\.env        { deny all; }
    location ~ /data/        { deny all; }
    location ~ /backup\.sh   { deny all; }

    location / {
        try_files $uri $uri/ /index.html;
    }
}
```

---

## HTTPS Setup

Camera and microphone access (barcode scanning, voice) **require HTTPS** on all modern mobile browsers.

### Self-signed certificate (local network)

```bash
openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
  -keyout /etc/ssl/private/evershelf.key \
  -out /etc/ssl/certs/evershelf.crt \
  -subj "/CN=evershelf.local" \
  -addext "subjectAltName=IP:192.168.1.100,DNS:evershelf.local"
```

Android will show a certificate warning — tap "Advanced → Proceed" once. The kiosk app accepts self-signed certificates automatically.

### Let's Encrypt (public server)

```bash
sudo apt install certbot python3-certbot-apache
sudo certbot --apache -d evershelf.yourdomain.com
```

### Caddy (automatic TLS)

```
evershelf.yourdomain.com {
    root * /var/www/html/dispensa
    php_fastcgi unix//run/php/php8.2-fpm.sock
    file_server
    respond /data/* 403
    respond /.env 403
}
```

---

## Cron Job (optional)

For smart shopping predictions to stay up to date:

```bash
# Edit crontab
crontab -e

# Add (runs every 5 minutes)
*/5 * * * * php /var/www/html/dispensa/api/cron_smart_shopping.php >> /var/www/html/dispensa/data/cron.log 2>&1
```

---

## Backup (optional)

```bash
# Edit crontab
crontab -e

# Daily backup at 3 AM
0 3 * * * /var/www/html/dispensa/backup.sh
```

The `backup.sh` script copies `data/evershelf.db` to `data/backups/` with a timestamp.

---

## Updating

```bash
cd /var/www/html/dispensa
git pull origin main
# Database migrations run automatically on next page load
```

With Docker:

```bash
docker compose pull
docker compose up -d
```

---

## Post-installation

Once the app is running, open it in your browser and:

1. Go to **Settings** (⚙️ icon in the header)
2. Enter your **Gemini API key** (get one free at [aistudio.google.com](https://aistudio.google.com/app/apikey))
3. Optionally configure Bring!, TTS, and scale settings
4. Add your first product via the ➕ button or barcode scan

See [Configuration](Configuration) for the full list of settings.
