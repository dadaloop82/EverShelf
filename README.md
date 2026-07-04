# 🏠 EverShelf

> **Self-hosted pantry management system** — Track your food inventory, scan barcodes, get AI-powered recipe suggestions, and reduce waste.

---

<div align="center">

### 🚀 Try the live demo — no installation required!

**[▶ Open Live Demo](https://evershelfproject.dadaloop.it/demo)**
&nbsp;·&nbsp;
[🌐 Project Website](https://evershelfproject.dadaloop.it/)
&nbsp;·&nbsp;
[📖 Wiki](https://github.com/dadaloop82/EverShelf/wiki)

*The demo runs with mock pantry data. AI features are fully enabled. All write operations are safely sandboxed.*

</div>

---

[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.0+-blue.svg)](https://www.php.net/)
[![SQLite](https://img.shields.io/badge/SQLite-3-blue.svg)](https://www.sqlite.org/)
[![Docker](https://img.shields.io/badge/Docker-Ready-2496ED.svg)](Dockerfile)
[![i18n](https://img.shields.io/badge/i18n-IT%20%7C%20EN%20%7C%20DE%20%7C%20FR%20%7C%20ES-orange.svg)](translations/)
[![Version](https://img.shields.io/badge/version-1.7.54-brightgreen.svg)](CHANGELOG.md)
[![GitHub stars](https://img.shields.io/github/stars/dadaloop82/EverShelf?style=social)](https://github.com/dadaloop82/EverShelf/stargazers)
[![Last commit](https://img.shields.io/github/last-commit/dadaloop82/EverShelf/main)](https://github.com/dadaloop82/EverShelf/commits/main)
[![Contributors](https://img.shields.io/github/contributors/dadaloop82/EverShelf)](https://github.com/dadaloop82/EverShelf/graphs/contributors)
[![GitHub Discussions](https://img.shields.io/github/discussions/dadaloop82/EverShelf)](https://github.com/dadaloop82/EverShelf/discussions)
[![CI](https://github.com/dadaloop82/EverShelf/actions/workflows/ci.yml/badge.svg)](https://github.com/dadaloop82/EverShelf/actions/workflows/ci.yml)

[![ko-fi](https://ko-fi.com/img/githubbutton_sm.svg)](https://ko-fi.com/J3J01ZNETZ)

---

> **⚠️ Name disambiguation:** There is an unrelated iOS app also called **EverShelf**, developed and published by [Joshumi Technologies LLC](https://evershelf.joshumi.com/) on the [Apple App Store](https://apps.apple.com/app/evershelf/id6759439940). That application is a **completely separate, independent product** with no affiliation, association, or collaboration with this open-source project. This repository has no connection to Joshumi Technologies LLC, its products, or its services.

---

### 🆕 Release 1.7.54 (2026-07-04) — Scansione & UX spesa

| Funzione | Descrizione |
|----------|-------------|
| **Scansione più veloce** | Precaricamento ZBar/Tesseract, Native-first, cache barcode persistente, form Aggiungi subito (save in background) |
| **Auto-submit Aggiungi** | Dopo 30 s di inattività nel form, invio automatico con countdown visivo |
| **Fix modifica inventario** | Swipe a destra → modifica funziona di nuovo (ID numerici/stringa) |
| **Cron** | Fix `CRON_LOG_PATH` — previsioni smart shopping e catalogo barcode di nuovo attivi ogni 5 min |

### 🆕 Release 1.7.53 (2026-07-04) — Mealie

EverShelf ora integra **[Mealie](https://mealie.io)**, il gestore ricette open source self-hosted:

| Funzione | Descrizione |
|----------|-------------|
| **Ricette dalla dispensa** | Cerca tra le tue ricette Mealie quelle compatibili con ciò che hai in casa (con fallback Gemini in modalità Auto) |
| **Cache offline** | Sincronizza il ricettario in locale; funziona anche senza rete verso Mealie |
| **Installazione guidata** | Impostazioni → Ricette → Discovery / Installa Mealie (Docker) con token e URL configurati automaticamente |
| **Lista spesa da ricetta** | Ingredienti mancanti → suggerimento o aggiunta automatica a Bring! / lista interna (configurabile) |

Variabili `.env`: `MEALIE_URL`, `MEALIE_API_TOKEN`, `RECIPE_SOURCE` (auto/mealie/gemini), `MEALIE_OFFLINE`, `RECIPE_SHOPPING_MODE` (off/suggest/auto).

### 🆕 Release 1.7.52 (2026-07-04)

- Inventory swipe gestures (#80), price sparklines (#81), Mealie import API, MCP HTTP transport.

### 🆕 Release 1.7.51 (2026-07-04)

- **Bug fix #206** — `product_save` INSERT column order aligned with UPDATE (new products no longer have shifted fields).
- **Bug fix #207** — Docker image now includes PHP `zip` and `intl` extensions.
- **Shopping list UX** — Larger mobile rows, product icons/backgrounds, plan-days quantities, scroll preserved on refresh, fewer auto-updates.
- **Settings** — `.env` write fallback via SQLite when file permissions block saves; Bring can be disabled from Settings again.
- **MCP server (beta)** — [`mcp-server/`](mcp-server/) companion for Claude Desktop / Cursor / HA LLM (inventory, expiry, shopping, recipes).
- **PWA** — Basic service worker caches app shell for offline UI (shopping offline queue already supported).

### 🆕 Release 1.7.46 (2026-06-06)

- **Offline barcode DB** — Weekly sync from all free sources (OFF, OPF, OBF, OPFF, UPCitemdb); lookup works offline after sync.
- **Barcode save/lookup fixes** — Piadina EAN 8030582017181 and similar products resolve reliably; save no longer fails silently after scan.

### 🆕 Release 1.7.45 (2026-06-06)

- **Duplicate products → one stock count** — If the catalog has duplicate entries for the same item, adding inventory merges them automatically (2 packages = quantity 2 on one product).

### 🆕 Release 1.7.44 (2026-06-06)

- **AI product insert** — No more UNIQUE barcode errors or wrong title/category when adding an AI-recognized product; merges with existing catalog/OFF entries instead of duplicating.

### 🆕 Release 1.7.43 (2026-06-06)

- Banner fixes: no false expiry for grated bread; edit button loads inventory; stale zero-qty alerts filtered out.

### 🆕 Release 1.7.42 (2026-06-11)

- **Stable shopping total** — Each list item is priced as one typical purchase (no more inflated totals from 14-day restock quantities or €/kg × piece count).
- **Waste reason picker** — When discarding food, choose why (expired, wrong storage, bought too much, …); EverShelf learns and adjusts restock suggestions and storage hints.
- **Fewer SQLite lock errors** — `inventory_use` and `shopping_add` retry on `SQLITE_BUSY`; smart shopping gets a longer PHP time limit on large pantries.
- **Android kiosk** — Locale string escaping fix; setup wizard JSON save fix (CI build).

See [CHANGELOG.md](CHANGELOG.md) for full details.

---

## ✨ Features

### 🏠 NEW — Home Assistant Integration

EverShelf has a **native Home Assistant integration** available on HACS.  
Connect your pantry to your smart home in minutes — no YAML, no manual sensor setup.

[![Install via HACS](https://my.home-assistant.io/badges/hacs_repository.svg)](https://my.home-assistant.io/redirect/hacs_repository/?owner=dadaloop82&repository=ha-evershelf&category=integration)
&nbsp;
[![Add Integration](https://my.home-assistant.io/badges/config_flow_start.svg)](https://my.home-assistant.io/redirect/config_flow_start/?domain=evershelf)

**What you get:**

| | |
|---|---|
| **16 sensors** | Expiry counts, stock levels by location (pantry / fridge / freezer), shopping list total, AI API usage, last backup timestamp, days to next expiry |
| **6 binary sensors** | Expired items, expiring items, expiring today, shopping list active, backup overdue, Bring! connected |
| **5 action buttons** | Refresh data, Refresh prices, **Suggest Recipe** (AI — result as HA notification), Sync smart shopping, Clear expired rows |
| **Shopping list todo** | Bidirectional sync — add, remove, check off items directly from HA |
| **Expiry calendar** | Every product's expiry date as a native HA calendar event — works with the calendar card and any calendar automation |
| **Quick-add text entity** | Type a product name in HA to instantly add it to the shopping list (great for voice assistants / Assist) |
| **6 services** | `add_to_shopping`, `mark_used`, `refresh`, `suggest_recipe`, `refresh_prices`, `clear_expired` |
| **Auto-discovery** | Detected automatically via Zeroconf/mDNS when `avahi-daemon` runs on the EverShelf host |
| **5 languages** | English, Italian, German, French, Spanish |

> **Requires a self-hosted EverShelf instance.** The integration talks directly to your server — no cloud involved.  
> Full documentation: [ha-evershelf on GitHub](https://github.com/dadaloop82/ha-evershelf)

---

### 🤖 NEW — MCP Server (AI Agent Integration)

EverShelf ships a **[Model Context Protocol](https://modelcontextprotocol.io/) server** so Claude Desktop, Cursor, Home Assistant LLM, Open WebUI, and other MCP hosts can query and update your pantry in natural language.

| Tool | What it does |
|------|----------------|
| `get_inventory` | List stock by location |
| `get_expiring_soon` | Items expiring in N days |
| `get_pantry_stats` | Dashboard totals |
| `get_shopping_list` | Current shopping list |
| `get_smart_shopping` | AI restock predictions |
| `add_shopping_items` | Add to shopping list |
| `use_inventory_item` | Record consumption |
| `suggest_recipe` | AI recipe from pantry |

Setup: [`mcp-server/README.md`](mcp-server/README.md) — Node.js 18+, configure `EVERSHELF_URL` + optional `EVERSHELF_TOKEN`.

Example: *"What's expiring this week?"* → *"Suggest a dinner from the fridge"* → *"Add milk to the shopping list"* → *"I used 2 eggs"*.

---

### 📦 Inventory Management
- **Export inventory** — Download the full inventory as a UTF-8 CSV (Excel-compatible) or open a print-ready page to save as PDF; export button always visible in the inventory page header
- **Barcode scanning** — Scan products with your phone camera using QuaggaJS; last 20 scanned products saved as tappable chips so you can re-select them without rescanning
- **AI identification** — Take a photo and let Google Gemini identify the product, with suggestions from your existing inventory; gracefully shows a friendly message when AI quota is exhausted instead of a raw API error
- **Smart locations** — Track items across Pantry, Fridge, Freezer, and custom locations
- **Expiry tracking** — Automatic shelf-life estimation based on product type and storage
- **Opened product tracking** — Reduced shelf-life calculation when packages are opened; opened-product expiry is now also checked when building banner alerts (not just the dashboard section)
- **Vacuum-sealed support** — Extended expiry dates for vacuum-sealed items; products sealed under vacuum are only flagged as expired after a configurable grace period past the printed date (`VACUUM_EXPIRY_EXTENSION_DAYS`, default 30 days, configurable in `.env`)
- **Anomaly detection** — Banner alerts for suspicious quantities and consumption predictions with inline correction; dismiss button now shows the current inventory quantity so the action is unambiguous ("Quantity is correct (2 pcs)")

### 🤖 AI-Powered (Google Gemini)
- **Expiry date reading** — Photograph a label and extract the expiry date automatically
- **Product identification** — Point your camera at any product for instant recognition
- **Existing product matching** — AI scan shows matching products already in your pantry before suggesting new ones
- **Storage & shelf-life hint** — When adding a new product, Gemini suggests the optimal storage location and shelf-life in the background; shown as an inline AI badge next to the expiry estimate
- **Recipe generation** — Get personalized recipes based on what's in your pantry; streams live via Server-Sent Events so results appear as they are generated. With **Mealie** configured, EverShelf picks from your saved cookbook first (offline cache supported) before falling back to Gemini in Auto mode
- **Recipe stock hints** — Each pantry ingredient shows how much you have and what remains after use; when the leftover would be less than 5% of the full sealed package (10% for an already-opened partial pack), the recipe automatically uses everything on hand to avoid waste
- **Smart chat assistant** — Ask questions about your inventory, get cooking tips
- **Shopping suggestions with tips** — AI-powered purchase recommendations, each enriched with a short practical buying/storing tip
- **Anomaly explanation** — "Explain" button on anomaly banners explains in plain language why a discrepancy likely occurred and what to do
- **Model fallback** — All AI endpoints try `gemini-2.5-flash` first and fall back to `gemini-2.0-flash` automatically
- **Graceful no-key state** — When no Gemini key is configured, AI entry points show a friendly message; the header button is visually greyed with an amber dot

### 🛒 Shopping List
- **Bring! integration** — Sync with the [Bring!](https://www.getbring.com/) shopping list app
- **Generic shopping names** — Products are grouped by type (e.g. "Milk", "Cold cuts", "Cooking cream") rather than brand, keeping the Bring! list clean and consolidated
- **Smart predictions** — Know what you'll need before you run out
- **Auto-add on depletion** — When a product reaches zero the app adds it to Bring! automatically, no confirmation needed
- **Auto-remove on scan** — Products are removed from the shopping list when scanned in  - **Auto-migration** — Items already on the Bring! list are silently renamed to their generic name in the background (throttled, runs on list load)
  - **Catalog coverage** — All product types resolve to a German Bring! catalog key for icon and category display in the Bring! app

### 🍳 Cooking Mode
- **♻️ Zero-waste tips** — For each cooking step that generates reusable scraps (peels, cooking water, egg whites, cheese rinds, bread crusts, vegetable tops, etc.), a dismissible ♻️ tip card appears with a practical reuse idea; tips are generated by Gemini as part of the recipe at no extra API cost; opt-in toggle in Settings (default OFF)
- **Step-by-step guidance** — Follow recipes with a hands-free cooking interface
- **Text-to-Speech** — Voice readout of recipe steps; supports browser Web Speech API, native Android TTS (kiosk), or a custom REST endpoint (Home Assistant, etc.); retries voice loading for up to 10 seconds with a fallback refresh button; TTS activates automatically without requiring the global TTS setting to be enabled
- **Auto-read on navigate** — Each step is read aloud automatically when you tap Next or Previous; the first step is read when entering cooking mode
- **Timer voice alerts** — 10-second countdown warning spoken aloud before each timer expires; expiry announced vocally when time is up
- **Recipe completion** — "Bon appétit!" announced via TTS when the last step is confirmed
- **Built-in timer** — Automatic timer suggestions based on recipe instructions
- **Ingredient tracking** — Mark ingredients as used during cooking; leftover quantities prompt a "move to another location" flow

### 📊 Dashboard
- **Waste tracking** — Monitor consumed vs. wasted products over 30 days
- **Anti-waste report** — Personalised waste rate vs. national average with annual kg estimate; shown above the expiring-items list
- **Expiry alerts** — Visual warnings for expired and soon-to-expire items
- **Opened products panel** — Tracks partially-used items; expiry is recalculated from the opening date using AI (Gemini) + per-category rule fallback; whole sealed packages always keep their original manufacturer expiry; conf items with mixed whole + fractional units are shown as two separate entries
- **Freezer shelf-life** — Granular per-product estimates (USDA/EFSA): fish 120 d, poultry 270 d, whole red-meat cuts 365 d, mince 120 d, vegetables/fruit 270 d, generic 180 d; AI + cache still take priority over rules
- **Safety ratings** — Smart assessment of expired product safety (by category and location); expired unsafe items shown with a red danger banner and a discard action as the primary action
- **Expired product banner** — Products that have passed their effective shelf-life (including opened-product reduced expiry) appear in the top notification banner; icon, colour and title adapt to the actual safety level (✅ green for safe, 👀 amber to check, 🚫 red for danger); high-risk items get a prominent discard action
- **Quick recipe bar** — One-tap recipe suggestion using expiring products
- **Anomaly banner** — Scrollable banner with suspicious quantities and consumption prediction mismatches, with one-tap correction or inline edit
- **Expired/expiring alerts** — Priority-sorted banner notifications for expired and soon-to-expire products with use, throw, edit, and dismiss actions
- **Swipe navigation** — Touch swipe or tap arrows/dots to browse banner notifications
- **Quick-access buttons** — Recently used and most popular products shown on the inventory page for fast access

### 🌙 Appearance
- **Dark mode** — Three modes: Light, Dark, and Auto (time-based: dark from 20:00 to 07:00, light otherwise); applies immediately without page reload; auto mode re-evaluates every 5 minutes, so night/day transitions happen automatically even on always-on kiosk displays; theme is applied before the first render to prevent a white flash
- **Global settings tab** — A dedicated **⚙️ General** tab groups all system-wide settings (language, currency, theme, screensaver, zero-waste tips, export) at the top of the Settings panel

### �️ Database Maintenance
- **Automatic cleanup** — Recipes older than `RECIPE_RETENTION_DAYS` (default 7) and transactions older than `TRANSACTION_RETENTION_DAYS` (default 7) are deleted automatically on every cron cycle; SQLite `VACUUM` runs after each cleanup to keep the file compact
- **Manual cleanup** — Trigger immediately via `GET /api/?action=db_cleanup`
- **Compact by default** — Fresh installs stay small; large accumulated databases shrink back to a few hundred KB within one cron cycle

### �📱 Progressive Web App
- **Mobile-first design** — Optimized for phones, works on tablets and desktop
- **Installable** — Add to home screen for a native app experience
- **Multi-device** — All user data (shopping tags, pinned items, location preferences, scan history) is stored server-side in SQLite and shared across every device on the same instance; no data is siloed in a single browser's localStorage
### 📶 Offline Mode
- **Automatic detection** — Full-screen overlay appears immediately on network loss; shows a "Continue offline" button after 3 s, and auto-enters offline mode after 8 s
- **Local inventory cache** — Inventory is synced to `localStorage` at every startup and on each successful API call; the offline view always reflects the last known state
- **Write queue** — Add, use, update and delete operations performed while offline are queued locally and synced to the server automatically on reconnect (including after a page refresh)
- **Optimistic UI** — Queued writes are applied immediately to the local cache so the interface stays responsive
- **Offline-computed stats** — Expiring and expired items are derived client-side from the cache; dashboard stat cards show real counts instead of zeros
- **AI/network sections hidden** — Anti-waste chart, nutrition analysis, recipe generator, price fetching, and Gemini chat are hidden in offline mode; the inventory, history, and manually-managed shopping list remain fully functional
- **Broken image fallback** — External product images (Open Food Facts, etc.) that fail to load are replaced with a neutral grey placeholder, keeping the layout intact
- **Startup recovery** — If the page is refreshed while operations are queued, they are detected and synced automatically on the next successful startup
- **Buffered error reporting** — `remoteLog` and `reportError` calls made while offline are stored locally and flushed to the server (and to GitHub issues) when the connection is restored
### ⚖️ Smart Scale Integration (Add-on)
- **Bluetooth gateway** — Connects a BLE smart scale to EverShelf via local WebSocket
- **SSE relay** — Server-side relay avoids mixed-content (HTTPS→WS) issues
- **Auto-discovery** — Server scans LAN to find the gateway automatically
- **Auto weight reading** — When adding/using a product with unit g/ml, weight fills automatically
- **10g threshold** — Ignores readings that haven't changed enough between products  - **Duplicate-reading prevention** — Server-side 12-second dedup window rejects a second scale-triggered deduction of the same product, guarding against BLE multi-fire- **ml conversion hint** — Shows "weight in grams → will be converted to ml" when product unit is ml
- **Stability + auto-confirm** — 10s stable wait + 5s countdown before confirming
- **Real-time status** — Scale connection indicator always visible in the header
- **Multi-protocol** — Supports Bluetooth SIG Weight Scale, Body Composition, Xiaomi Mi Scale 2 and 100+ models
- **Built into kiosk (v1.6.0+)** — BLE gateway runs as an integrated foreground service inside the [EverShelf Kiosk](evershelf-kiosk/) app; no separate APK needed.

### 📺 Android Kiosk Mode (Add-on)
- **Dedicated tablet app** — Full-screen WebView wrapper for wall-mounted kitchen tablets
- **True kiosk lock** — Screen pinning blocks home/recent buttons
- **Setup wizard** — 6-step guided configuration (language, welcome, permissions, server URL, BLE scale scan, screensaver, summary)
- **Smart auto-discovery** — Scans the LAN in parallel (60 threads, TCP pre-check, ports 80/443/8080/8443) with real-time UI feedback; correctly identifies the device's Wi-Fi/Ethernet subnet (VPN and cellular interfaces are filtered out)
- **Built-in BLE scale gateway** — `GatewayService` foreground service; BLE scanning + WebSocket server `:8765` run directly inside the kiosk app. Select your scale in step 5 of the wizard — no external app required
- **Scale auto-configuration** — After selecting the BLE device, the wizard writes `scale_enabled` and `scale_gateway_url=ws://127.0.0.1:8765` to the server automatically
- **Camera & mic permissions** — Full hardware access for barcode scanning and voice; grant button transforms to a green confirmation after granting
- **Native TTS bridge** — Cooking mode voice readout uses the Android TextToSpeech engine directly, bypassing Web Speech API voice limitations; no offline voice packs required
- **Hard refresh** — ↻ button clears WebView cache to pick up web app updates
- **Update notifications** — Checks GitHub releases every 6h, shows banner when updates available
- **SSL support** — Accepts self-signed certificates
- **Android kiosk app** — [`evershelf-kiosk/`](evershelf-kiosk/) — downloadable APK

---

## 🚀 Quick Start

### Prerequisites
- **Web server** with PHP 8.0+ (Apache or Nginx)
- **PHP extensions**: `pdo_sqlite`, `curl`, `mbstring`, `json`
- **HTTPS** recommended (required for camera access on mobile)

### Installation

#### Option A: Docker (recommended)

```bash
# 1. Clone the repository
git clone https://github.com/dadaloop82/EverShelf.git
cd EverShelf

# 2. Create configuration file
cp .env.example .env
nano .env

# 3. Start with Docker Compose
docker compose up -d

# → Open http://localhost:8080
```

#### Option B: Manual

```bash
# 1. Clone the repository
git clone https://github.com/dadaloop82/EverShelf.git
cd EverShelf

# 2. Create configuration file
cp .env.example .env

# 3. Set permissions
chmod 755 data/
chmod 664 data/.gitkeep
chown -R www-data:www-data data/

# 4. Edit your configuration
nano .env
```

### Configuration (.env)

```ini
# Required for AI features (get a key at https://aistudio.google.com/app/apikey)
GEMINI_API_KEY=your_api_key_here

# Optional: Bring! shopping list integration
BRING_EMAIL=your_email@example.com
BRING_PASSWORD=your_password

# Optional: Text-to-Speech for cooking mode
TTS_URL=http://your-home-assistant:8123/api/events/tts_speak
TTS_TOKEN=your_long_lived_token
TTS_ENABLED=true

# Optional: DB retention and cleanup (applied automatically each cron cycle)
RECIPE_RETENTION_DAYS=7        # delete recipe plans older than N days
TRANSACTION_RETENTION_DAYS=90   # delete stock transactions older than N days (min 30 enforced)

# Optional: Vacuum-sealed expiry grace period
VACUUM_EXPIRY_EXTENSION_DAYS=30 # extra days before vacuum-sealed items are flagged expired

# Optional: Gemini cost rates (USD per million tokens, for the Info tab cost estimate)
GEMINI_COST_25F_IN=0.15
GEMINI_COST_25F_OUT=0.60
GEMINI_COST_20F_IN=0.10
GEMINI_COST_20F_OUT=0.40

# Optional: Security — protect all API endpoints
# Set a strong random string; clients send it as X-API-Token header (or ?api_token= for HA)
API_TOKEN=

# Optional: Legacy alias for API_TOKEN (settings save only)
SETTINGS_TOKEN=

# Optional: Demo mode — block all write operations at the router level
DEMO_MODE=false

# Optional: Logging
# LOG_LEVEL sets the minimum severity written to disk (DEBUG / INFO / WARN / ERROR)
# DEBUG also logs every SQL query executed against the database
LOG_LEVEL=INFO
LOG_ROTATE_HOURS=24   # hours before opening a new log file (default: 24)
LOG_MAX_FILES=14      # maximum number of rotated files to keep (default: 14)
```

### Web Server Configuration

<details>
<summary><strong>Apache (.htaccess)</strong></summary>

The app works out of the box with Apache if placed in the web root or a subdirectory. Make sure `mod_rewrite` is enabled and `AllowOverride All` is set.

```apache
<Directory /var/www/html/evershelf>
    AllowOverride All
    Require all granted
</Directory>
```

</details>

<details>
<summary><strong>Nginx</strong></summary>

```nginx
server {
    listen 80;
    server_name your-server.local;
    root /var/www/html/evershelf;
    index index.html;

    location /api/ {
        try_files $uri $uri/ =404;
        location ~ \.php$ {
            fastcgi_pass unix:/run/php/php8.2-fpm.sock;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            include fastcgi_params;
        }
    }

    # Deny access to sensitive files
    location ~ /\.env { deny all; }
    location ~ /data/ { deny all; }
    location ~ /backup\.sh { deny all; }
}
```

</details>

### HTTPS Setup (Recommended)

Camera access requires HTTPS on most mobile browsers. Options:
- **Let's Encrypt** with Certbot (for public-facing servers)
- **Self-signed certificate** (for local network only)
- **Reverse proxy** (e.g., Caddy, Traefik) with automatic TLS

### Cron Job (Optional)

Set up a cron job for smart shopping predictions:

```bash
# Run every 5 minutes
*/5 * * * * php /path/to/evershelf/api/cron_smart_shopping.php >> /path/to/evershelf/data/cron.log 2>&1
```

### Backup (Optional)

The included `backup.sh` creates local daily backups of your database:

```bash
# Run daily at 3 AM
0 3 * * * /path/to/evershelf/backup.sh
```

### Google Drive Backup (Optional)

EverShelf supports automatic daily backups to Google Drive via OAuth 2.0. This works on any server, including private IP / local network setups (no public domain required).

**Setup:**

1. Go to [console.cloud.google.com](https://console.cloud.google.com) and select or create a project.
2. Enable the **Google Drive API** (`APIs & Services → Enable APIs → Google Drive API`).
3. Go to `APIs & Services → Credentials → Create Credentials → OAuth client ID`.
4. Application type: **Web application**.
5. Add **`http://localhost`** as an Authorized Redirect URI (this is the key — it works even without a real domain).
6. Copy **Client ID** and **Client Secret** into EverShelf Settings → Backup.
7. Enter your **Google Drive Folder ID** (the last part of the folder URL).
8. Click **Authorize with Google** and sign in.
9. The browser will redirect to `http://localhost` and may show a connection error — **this is expected**. Copy the full URL from the address bar (e.g. `http://localhost/?code=4%2F0A...`) and paste it into the field that appears in EverShelf, then click **Submit**.

> **Note:** While the OAuth app is in *Testing* status in Google Cloud Console, you must add your Google account as a test user under `APIs & Services → OAuth consent screen → Test users`.

---

## 🏗️ Architecture

```
evershelf/
├── index.html              # Single-page application (SPA)
├── manifest.json           # PWA manifest
├── .env.example            # Configuration template
├── backup.sh               # Local database backup script
├── LICENSE                 # MIT License
│
├── api/
│   ├── index.php           # Main API router (all endpoints)
│   ├── database.php        # SQLite schema, migrations, helpers
│   └── cron_smart_shopping.php  # Background job for predictions
│
├── assets/
│   ├── css/style.css       # All application styles
│   ├── js/app.js           # All application logic
│   └── img/                # Static images
│
└── data/                   # Runtime data (gitignored)
    ├── evershelf.db         # SQLite database (auto-created)
    ├── backups/            # Local DB backups
    └── *.json              # Token/cache files

evershelf-scale-gateway/    # ⚖️ Android BLE gateway [DEPRECATED — integrated into kiosk v1.6.0+]
    ├── README.md           # Deprecation notice + legacy docs
    └── app/src/            # Kotlin Android source (WebSocket + BLE)

evershelf-kiosk/            # 📺 Android kiosk app (add-on)
    ├── README.md           # Setup & feature docs
    └── app/src/            # Kotlin Android source (WebView wrapper)
```

### API Endpoints

| Category | Action | Method | Description |
|----------|--------|--------|-------------|
| **Products** | `search_barcode` | GET | Find product by barcode |
| | `lookup_barcode` | GET | Look up barcode on Open Food Facts |
| | `product_save` | POST | Create or update a product |
| | `products_list` | GET | List all products |
| **Inventory** | `inventory_list` | GET | List inventory items |
| | `inventory_add` | POST | Add product to inventory |
| | `inventory_use` | POST | Use/consume from inventory |
| | `inventory_summary` | GET | Count by location |
| **AI** | `gemini_identify` | POST | Identify product from photo |
| | `gemini_expiry` | POST | Read expiry date from photo |
| | `gemini_chat` | POST | Chat with AI assistant |
| | `generate_recipe` | POST | Generate recipe from inventory |
| | `gemini_product_hint` | POST | Storage location + shelf-life hint |
| | `gemini_shopping_enrich` | POST | Enrich shopping suggestions with tips |
| | `gemini_anomaly_explain` | POST | Plain-language anomaly explanation |
| **Shopping** | `bring_list` | GET | Get Bring! shopping list |
| | `bring_add` | POST | Add items to Bring! |
| | `smart_shopping` | GET | Smart shopping predictions |
| **Settings** | `get_settings` | GET | Get server configuration |
| | `save_settings` | POST | Update server configuration |

---

## 🔒 Security Notes

- **Credentials** are stored in `.env` (server-side, never committed to Git)
- **Database** stays local — never pushed to remote repositories
- **Apache/Nginx hardening** — `.env`, `data/`, and `logs/` are blocked from direct HTTP access
- **API token** — set `API_TOKEN` in `.env` to require `X-API-Token` on all API calls (Home Assistant: `?api_token=`)
- **API keys are never exposed to the browser** — `get_settings` returns only boolean flags (`gemini_key_set`, `ha_token_set`, …)
- **GitHub Issues token** — stored encrypted as `GH_ISSUE_TOKEN_ENC` + `GH_ISSUE_TOKEN_KEY` (see `scripts/encrypt-gh-token.php`)
- **Settings write protection** — `save_settings` requires the same API token when configured; validated with `hash_equals`
- **Demo / public mode** — set `DEMO_MODE=true` to block all write operations at the PHP router level before any business logic runs
- The API uses **parameterized SQL queries** (PDO prepared statements) against injection
- **Input validation** on all inventory operations (quantity bounds, location whitelist)
- Consider adding **reverse-proxy authentication** (e.g. Authelia, Nginx `auth_basic`) if the server is accessible from the internet

---

## 🛠️ Development

```bash
# Run PHP's built-in server for local development
php -S localhost:8080 -t /path/to/evershelf

# Check PHP syntax
php -l api/index.php
php -l api/database.php
```

The application uses no build tools — edit files directly and refresh.

---

## 📋 Roadmap

Feature requests, bug reports and planned work are tracked in the [**EverShelf Roadmap**](https://github.com/users/dadaloop82/projects/2) GitHub Project.

---

## 🌐 Translations

The app supports multiple languages via JSON translation files in the `translations/` folder.

| Language | Status |
|----------|--------|
| 🇮🇹 Italian (it) | ✅ Complete (base) |
| 🇬🇧 English (en) | ✅ Complete |
| 🇩🇪 German (de) | ✅ Complete |
| 🇫🇷 French (fr) | ✅ Complete |
| 🇪🇸 Spanish (es) | ✅ Complete |

**Want to add your language?** See the [Translation Guide](CONTRIBUTING.md#-adding-translations) — just copy `translations/it.json`, translate the values, and submit a PR!

---

## 🤝 Contributing

Contributions are welcome! See [CONTRIBUTING.md](CONTRIBUTING.md) for detailed guidelines.

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/my-feature`)
3. Commit your changes (`git commit -m 'Add my feature'`)
4. Push to the branch (`git push origin feature/my-feature`)
5. Open a Pull Request

### Easiest way to start — translate EverShelf into your language

Translations are just JSON files. No coding, no setup — fork → edit → PR.

```
translations/
├── it.json   ✅ Italian (base)
├── en.json   ✅ English
├── de.json   ✅ German
├── fr.json   ✅ French
├── es.json   ✅ Spanish
├── pt.json   ❌ Portuguese — wanted!
├── nl.json   ❌ Dutch — wanted!
└── ...       ❌ Your language here!
```

👉 See [issue #93](https://github.com/dadaloop82/EverShelf/issues/93) to claim a language.

### Other ways to contribute

| What | Skill needed |
|---|---|
| 🐛 Report a bug | None |
| 📖 Improve the wiki | Markdown |
| 🌍 Add a translation | JSON editing |
| 🎨 Fix a CSS/UI issue | CSS / HTML |
| ⚙️ Implement a feature | PHP / JS |
| ⭐ Star the repo | Clicking |

👉 Browse [`help wanted`](https://github.com/dadaloop82/EverShelf/labels/help%20wanted) issues for good starting points.

Read [CONTRIBUTING.md](CONTRIBUTING.md) for the full guide (branch naming, code style, how to run locally).

---

## 💬 Community

Join the conversation in [GitHub Discussions](https://github.com/dadaloop82/EverShelf/discussions):
- **Vote on upcoming features** — tell us what to build next
- **Show your setup** — share your kitchen kiosk
- **Ask questions** — get help from the community

---

## 📄 License

This project is licensed under the **MIT License** — see the [LICENSE](LICENSE) file for details.

---

## 👨‍💻 Author

**Stimpfl Daniel** — [evershelfproject@gmail.com](mailto:evershelfproject@gmail.com)

- Website: [evershelfproject.dadaloop.it](https://evershelfproject.dadaloop.it/)
- GitHub: [@dadaloop82](https://github.com/dadaloop82)

---

## 📸 Screenshots

<div align="center">

![EverShelf demo — barcode scan, inventory management and AI recipe generation](assets/img/demo.gif)

</div>

For a live walkthrough with real data and full AI enabled, visit the **[live demo](https://evershelfproject.dadaloop.it/demo)** — no installation required.

> Want to contribute additional screenshots? See [CONTRIBUTING.md](CONTRIBUTING.md) — PRs welcome!
