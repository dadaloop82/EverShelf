# ⚙️ Configuration

EverShelf is configured via a `.env` file in the project root. Copy `.env.example` to `.env` and edit it — the app reads this file on every API call.

**Never commit `.env` to Git.** It is already in `.gitignore`.

---

## Full `.env` Reference

```ini
# ─────────────────────────────────────────────
# AI — Google Gemini
# ─────────────────────────────────────────────

# Your Gemini API key (required for all AI features)
# Get one free at: https://aistudio.google.com/app/apikey
GEMINI_API_KEY=

# ─────────────────────────────────────────────
# Shopping List — Bring! Integration
# ─────────────────────────────────────────────

# Your Bring! account credentials
# Leave blank to disable Bring! integration
BRING_EMAIL=
BRING_PASSWORD=

# ─────────────────────────────────────────────
# Text-to-Speech (for Cooking Mode)
# ─────────────────────────────────────────────

# URL to a TTS endpoint (e.g. Home Assistant event endpoint)
TTS_URL=

# Bearer token for the TTS endpoint
TTS_TOKEN=

# Set to true to enable server-side TTS (the browser Web Speech API is always used as fallback)
TTS_ENABLED=false

# ─────────────────────────────────────────────
# Security
# ─────────────────────────────────────────────

# When set, EVERY API action requires this token: header `X-API-Token`, or
# `?api_token=` for Home Assistant. The web UI gets it once per browser through
# the pairing code (Settings → Security on a paired device, or server log — see below).
# Compared with hash_equals() to prevent timing attacks.
#
# LEAVE IT EMPTY and EverShelf generates one for you on the first request: the value is
# written here (or to the app_settings fallback when .env is read-only), enforced from
# that same request, and the pairing code that lets a device in is printed in the log
# right after it. The marker data/api_token_autogen.json makes sure a token is never
# minted twice — a rotating token would lock every device out.
API_TOKEN=

# true = keep the old behaviour and do NOT generate a token (a fully trusted LAN, or a
# reverse proxy that authenticates for you). Everyone who can reach the port then reads
# and writes the pantry.
ALLOW_OPEN_LAN=false

# The names this server answers to — the DNS-rebinding guard. Comma list, *.example.lan
# allowed, empty = off. A browser cannot protect a LAN install here: a page on evil.example
# can point its own DNS at your address and CORS / Sec-Fetch-Site are then satisfied.
# ALLOWED_HOSTS=evershelf.local,192.168.1.10
ALLOWED_HOSTS=

# Legacy alias, still honoured when API_TOKEN is empty. Use API_TOKEN for new installs.
SETTINGS_TOKEN=

# true = hand API_TOKEN to any same-origin-looking request (pre-1.8.8 behaviour).
# Default false. A "same-origin" request is trivially forgeable, so only enable it
# on a fully trusted LAN. See SECURITY.md.
API_BOOTSTRAP_OPEN=false

# ─────────────────────────────────────────────
# Demo / Public Mode
# ─────────────────────────────────────────────

# Set to true to block ALL write operations at the PHP router level
# Useful for public demos or read-only kiosk deployments
# Also activatable per-request via ?demo=1 URL parameter
DEMO_MODE=false

# ─────────────────────────────────────────────
# Scale Gateway
# ─────────────────────────────────────────────

# Enable the BLE scale integration
SCALE_ENABLED=false

# WebSocket URL of the Scale Gateway app running on the same device
# Default for Android kiosk: ws://127.0.0.1:8765
SCALE_GATEWAY_URL=ws://127.0.0.1:8765

# ─────────────────────────────────────────────
# GitHub Error Reporting
# ─────────────────────────────────────────────

# GitHub Issues token, AES-256-GCM encrypted (see SECURITY.md). Plain:
# GH_ISSUE_TOKEN=ghp_…, or encrypted via scripts/encrypt-gh-token.php.
GH_ISSUE_TOKEN=
GH_ISSUE_TOKEN_ENC=
GH_ISSUE_TOKEN_KEY=

# Publish error/bug reports as GitHub issues. Off by default: issues are opened
# on a PUBLIC repository, so this is an opt-in on top of the token above.
# data/error_reports.log is written either way, with credentials redacted and
# the context capped at 4 KB.
REPORT_ENABLED=false

# ─────────────────────────────────────────────
# Idle screen (kiosk / wall tablet)
# ─────────────────────────────────────────────

# Show the ambient dashboard after a period of inactivity.
SCREENSAVER_ENABLED=false

# MINUTES of inactivity before it appears (1-60, default 5). Older installations
# wrote this in SECONDS (300 = five minutes): a value above 60 is still read as
# seconds and converted, so an existing .env keeps working unchanged.
SCREENSAVER_TIMEOUT=5

# Which cards it shows, in a comma list: meal, alerts, nutrition, fact.
# Empty = all of them. A card with nothing to say is skipped by itself.
SCREENSAVER_VIEWS=meal,alerts,nutrition,fact

# Seconds between two cards (10-300, default 30). The old card dissolves upward
# and the next one comes down from the top.
SCREENSAVER_INTERVAL=30

# ─────────────────────────────────────────────
# Calendar feed (ICS / WebCal)
# ─────────────────────────────────────────────

# Subscribe to the pantry expiries from Google Calendar, Apple Calendar,
# Thunderbird, Nextcloud… One all-day event per in-stock item with an expiry date.
# Enable it from Settings → 🗓️ Calendar; that is also where ICS_TOKEN is minted
# and where the *Rotate link* button revokes existing subscriptions.
ICS_ENABLED=false

# Read-only secret appended to the feed URL (?action=calendar_ics&token=…).
# A calendar client can only GET a URL — it cannot send X-API-Token — so this is
# the feed's own credential. Compared with hash_equals(), never logged; rotating
# it invalidates every existing subscription.
ICS_TOKEN=

# How many days ahead to publish (1–365, default 30).
ICS_DAYS=30

# Keep items that expired up to N days ago visible (0–60, default 7): they are
# still actionable, and a calendar that silently drops a deadline lies.
ICS_PAST_DAYS=7
```

---

## Settings UI

Most settings can also be configured from the browser via **Settings → ⚙️**:

| Setting | `.env` key | Notes |
|---------|-----------|-------|
| Gemini API key | `GEMINI_API_KEY` | Stored server-side, never exposed to browser |
| Bring! email | `BRING_EMAIL` | — |
| Bring! password | `BRING_PASSWORD` | — |
| TTS URL | `TTS_URL` | — |
| TTS token | `TTS_TOKEN` | — |
| TTS enabled | `TTS_ENABLED` | — |
| Scale enabled | `SCALE_ENABLED` | — |
| Scale gateway URL | `SCALE_GATEWAY_URL` | — |
| Settings token | `SETTINGS_TOKEN` | Write-only; current value never shown |
| Notifications on/off | `NOTIFY_ENABLED` | Master switch for the ntfy/webhook channels (Settings → 🔔 Notifiche) |
| ntfy topic | `NTFY_TOPIC` | Secret: anyone who knows it can read and publish |
| ntfy / webhook tokens | `NTFY_TOKEN`, `NOTIFY_WEBHOOK_TOKEN` | Write-only; the field shows `••••••••` when a token is stored |
| Generic webhook URL | `NOTIFY_WEBHOOK_URL` | n8n, Node-RED, Gotify, Discord/Slack bridge… |
| Idle screen on/off, timeout, cards, pace | `SCREENSAVER_ENABLED`, `SCREENSAVER_TIMEOUT`, `SCREENSAVER_VIEWS`, `SCREENSAVER_INTERVAL` | Settings → 🏠 General → Appearance; the timeout is in **minutes** |

### Where to find what

The settings page is organised in **two levels**: *sections* on top, and under them
the **sub-sections** of the selected section. Only the sub-sections of the active
section are listed — wrapped, so none of them hides off-screen — and the section you
used last reopens the next time you open the page.

| Section | Sub-sections |
|---------|--------------|
| 🤖 App & AI | Generali, API, Voce (TTS), Fotocamera |
| 🍳 Cucina | Spesa, Ricette, Piano, Salute, Cucina (elettrodomestici) |
| 🔔 Avvisi e servizi | Notifiche, Home Assistant, Calendario, Bilancia |
| ⚙️ Sistema | Sicurezza, Backup, Info |

Inside a sub-section every card is **collapsed**: its heading and its first hint line
stay visible, the rest (fields, toggles, test buttons) opens on click and **only one
card is open at a time**. Two panels (*Notifiche*, *Home Assistant*) hold about ten
cards each, and with all of them open the option you came for was somewhere below the
fold. Jumping from the checklist opens the card it points at, and picking an AI
provider opens the credential card of that provider.

The Kiosk download banner, the Kiosk update panel and the About card (version,
bug report, changelog) live inside **Info**. They used to sit outside the panels,
so they were painted below every single tab.
 fails if a tab declares a section the JS does not
know, if a panel has no tab (or two), if the page opens on a tab of a section
that is not highlighted, if the sub-section list starts scrolling sideways again, or
if those blocks move back out of *Info*.

> **Security note:** `get_settings` returns only **boolean flags** (`gemini_key_set: true/false`), never raw key values. Raw values are only accessible server-side.

### Setup checklist and guided assistant

The first card on the settings page lists **only what needs a decision**: the options
that are still unconfigured, and — separately, under *Novità* — an option that gained
something since you last saw it (an `askVersion` bump that the assistant has not asked
about yet). A list where six of nine rows said "Configured" was noise. It stays a
single compact line while there is nothing to decide and opens by itself as soon as
something needs attention.

**▶️ Review the options** re-runs the guided assistant (`_setupSteps()`): it walks
through the open options — ntfy notifications and the cron watchdog can be tested from
inside the wizard, before anything is saved — and can be left at any step.

An option is asked **once**. The ledger is stored per item (keyed by the item's
`askVersion` in `SETTINGS_CHECKLIST`) and it belongs to the **instance**, not to the
browser that answered: the client keeps a copy in `localStorage` and writes it to
`app_settings.setup_seen` — plus `app_settings.setup_done` once the wizard is finished —
and `get_settings` hands both back to every device. A new phone, a fresh browser profile
or the Kiosk WebView therefore no longer re-asks what the household already answered
(issue #301). The *Novità* group covers what the ledger cannot: an option that was
already configured but gained a new question. Adding a new integration means adding one
entry to that registry — the card and the assistant pick it up automatically, and raising
`askVersion` asks again.

The **first-run wizard** only offers what the server has not already decided: the AI key
(`GEMINI_API_KEY`), the Bring! login, the language step (`APP_LANG`) and the Google Drive
step — `GDRIVE_ENABLED=false` hides it, because switching the feature off is a decision,
while the `.env` default (key absent) still offers it as a discovery step.

 fails if a row points at a tab that does not exist,
if an `ask` item names a wizard step outside `_setupSteps()`, if a label is missing in
any of the six locales, if the card starts listing configured options again, or if the
wizard goes back to a hardcoded closing step.
 runs the shipped `_getMissingSetupSteps` in node
and fails if a new device is asked again on a configured instance, if an explicit
`GDRIVE_ENABLED=false` keeps the Drive step, or if the instance ledger stops being read
and written.

---

## Push Notifications (ntfy / generic webhook)

EverShelf can push alerts to your phone **without Home Assistant**: configure it in
**Settings → 🔔 Notifiche** (or by hand in `.env`, see `.env.example`). Both channels
run off the same events the Home Assistant webhooks use, so an existing HA setup
keeps working unchanged.

| Field | `.env` key | Notes |
|-------|-----------|-------|
| Enable notifications | `NOTIFY_ENABLED` | Master switch for the two channels below only |
| Events | `NOTIFY_EVENTS` | `expiry_alert`, `shopping_add`, `stock_update` |
| Message language | `NOTIFY_LANGUAGE` | `it`/`en`/`de`/`fr`/`es`/`zh`; empty → English |
| ntfy server | `NTFY_URL` | `https://ntfy.sh` or your self-hosted instance |
| ntfy topic | `NTFY_TOPIC` | Letters, digits, `-`, `_` (max 64); the 🎲 button generates one |
| ntfy token | `NTFY_TOKEN` | Only for servers that require `Authorization: Bearer` |
| ntfy priority | `NTFY_PRIORITY` | `1`–`5` or `min`/`low`/`default`/`high`/`urgent` |
| ntfy tags | `NTFY_TAGS` | Comma-separated, rendered as an icon on the notification |
| Webhook URL | `NOTIFY_WEBHOOK_URL` | Any endpoint that accepts a JSON `POST` |
| Webhook token / header | `NOTIFY_WEBHOOK_TOKEN`, `NOTIFY_WEBHOOK_HEADER` | Default header: `Authorization` |
| Accept self-signed TLS | `NOTIFY_INSECURE_SSL` | LAN servers with a self-signed certificate only |

**Quick start (ntfy)** — install the [ntfy app](https://ntfy.sh), then:

1. **Settings → 🔔 Notifiche** → enable notifications and switch on **ntfy**.
2. Tap 🎲 to generate a secret topic and subscribe to that same topic in the app.
3. **Send a test notification** — every configured channel is reported with its HTTP
   status, so a wrong URL or topic is visible immediately instead of at 3 a.m.
4. **Save settings.**

The body of every message is clamped to 3600 bytes on a UTF-8 boundary, priorities
outside `1`–`5` fall back to the default, and only `http(s)` targets are ever
contacted. Stored tokens are **write-only**: leave the password field empty to keep
the current value.

---

## Cron watchdog (Healthchecks.io / Uptime Kuma)

EverShelf's scheduled work runs as CLI cron jobs (`cron_smart_shopping.php`,
`cron_barcode_catalog.php`, `cron_mealie_cache.php`). A cron that stops running
leaves no trace: the data simply stops moving and the UI still looks fine. The
watchdog closes that blind spot with a *dead-man's switch* — every job pings an
external URL when it finishes, so what raises the alarm is the **missing** ping.

**Settings → 🔔 Notifiche → ⏱️ Cron watchdog**: paste the ping URL, press **Test the
ping** to prove the connection works (the answer is the HTTP status), then **Save**.
The stored URL is a secret — like a token it is shown as `••••••••`, is never sent
back by the API, and **🗑️ Remove URL** deletes it. The same card lists the last run
of every job (`Smart shopping — 2 h ago — ok`), which stays readable even before a
URL is configured.

| Field | `.env` key | Notes |
|-------|-----------|-------|
| Ping URL | `NOTIFY_HEALTHCHECK_URL` | `https://hc-ping.com/<uuid>` or `https://kuma.example/api/push/<token>` |
| Per-job override | `NOTIFY_HEALTHCHECK_URL_SMART_SHOPPING`, `…_BARCODE_CATALOG`, `…_MEALIE_CACHE` | Wins over the shared URL for that job only |
| Accept self-signed TLS | `NOTIFY_INSECURE_SSL` | Shared with the ntfy / webhook channels |

The **URL shape picks the protocol**: with no query string it is treated as
Healthchecks.io (the state is a path suffix — `…/<uuid>/start`, `…/<uuid>/fail` —
and the detail text is the POST body, so it shows up in the check's log); with a
query string it is treated as an Uptime Kuma push URL (`…&status=up|down&msg=…`,
`GET`). Create the check on either service and copy its ping URL:
[Healthchecks.io](https://healthchecks.io) (free tier, e-mail / Slack / ntfy alerts)
or a self-hosted [Uptime Kuma](https://github.com/louislam/uptime-kuma) *Push*
monitor.

Each job sends `start` when it begins and one terminal `ok`/`fail` when it ends, and
`mealie_cache` stays silent while Mealie is unconfigured so an unused feature never
raises an alarm. The last outcome per job is recorded in `data/cron_health.json`
(job health and ping delivery are tracked separately: a failed sync whose alert was
delivered still reads as failed). The ping URL **is** a credential — whoever knows
it can silence the alarm — so it is never logged, never returned by the API and
never put into an error message.

---

## Protecting the API with a token

`API_TOKEN` is what stands between your pantry and anyone else on the network, so
EverShelf no longer relies on you remembering to set it: **an install with no token mints
one on the first request it answers.** You can also set it yourself, which is the better
answer when you already have a secret store:

```bash
# Generate a strong token
openssl rand -hex 32
```

```ini
API_TOKEN=a3f9b2c1d4e5...
```

From then on every API action requires it — the web UI sends `X-API-Token`, Home Assistant can use `?api_token=`. A missing or wrong token is rejected with HTTP 401.

`ALLOW_OPEN_LAN=true` keeps an unauthenticated install on purpose (a fully trusted LAN, or
a reverse proxy that authenticates for you). `ALLOWED_HOSTS` is the companion guard for the
other direction — DNS rebinding, which no browser check can see (see `SECURITY.md`).

### First run: the pairing code

The token is **never** handed to an anonymous request, so the browser cannot simply
ask for it: the first time the UI loads it shows a **pairing dialog**. On a device that
is already paired, the live code is under **Settings → System → Security** (and Info),
with copy / refresh. It is also printed in the server log:

```bash
grep -i "pairing code" logs/evershelf_*.log | tail -1           # bare metal
docker logs evershelf 2>&1 | grep -i "pairing code" | tail -1   # Docker
```

```text
[2026-10-04 09:56:51] [WARN ] [rid=2cf680af] [-] API pairing code {"event":"api_pairing_code","code":"a1b2c3d4","ttl_seconds":1800}
```

> The code above is a placeholder — your log line carries the real value.

If the `grep` returns nothing at all, the web server cannot write the log file: a log
created by a root `cron` run is owned by root and silently rejects Apache's writes, so
the code never reaches the file you grep. Fix it with
`bash scripts/fix-permissions.sh` (or `chown -R www-data:www-data logs/`). Prefer the
in-app Security panel when you already have one paired device.

The code is 8 hex characters, valid for 30 minutes (50 wrong attempts from one IP burn
it immediately). You pair once per browser/device — afterwards the token lives in
`localStorage` and you are not asked again. Unpair by clearing site data, or open
Security on a paired device to show a fresh code for the next tablet.

If the dialog does not show up, hard-refresh (`Ctrl+Shift+R`): an older service worker
may still be serving a stale `app.js`.

### `API_BOOTSTRAP_OPEN` — opting out

```ini
API_BOOTSTRAP_OPEN=true
```

Restores the pre-1.8.8 behaviour: any request that *looks* same-origin receives the
token, so the UI never asks for a code. The headers it relies on (`Origin`,
`Sec-Fetch-Site`) are set by the client and can be forged — use it only on a fully
trusted LAN, never on a host exposed to the internet.

### Pasting the token manually

Instead of pairing you can copy `API_TOKEN` from `.env` and paste it into
**Settings → Security → Token**, or set it once from the browser console:

```js
localStorage.setItem('evershelf_api_token', '<API_TOKEN>');
location.reload();
```

---

## Demo Mode

A demo is a **showcase**, not an installation: it answers from its own seeded
database, never from the household's pantry, and it is the one mode a marketing site
is meant to embed.

Three ways in — any one of them is enough:

| How | Scope |
| --- | --- |
| `DEMO_MODE=true` in `.env` | the whole deployment |
| `?demo=1` on any URL | that URL and its API calls (the client repeats the flag; leave the URL and the demo ends) |
| serving the app under `DEMO_BASE_PATH` (default `/demo`) | every request under that path, e.g. `https://evershelf.site/demo` |

What it does:

- **Its own database.** `data/demo/evershelf-demo-<lang>.db`, created on the first
  request from `api/lib/demo_seed.json` (30 products, a month of history, a shopping
  list) and rebuilt once a day so the expiry dates are always relative to today. The
  household's `data/evershelf.db` is never opened in demo mode.
- **Language.** English by default; `?lang=it|en|de|fr|es|zh` opens the demo in that
  language, and the demo database of that language is used — product names included.
  The marketing site passes it on the iframe URL.
- **Read-only.** Every write answers `{"success":true,"demo_mode":true}` without
  touching the data; AI generation (`gemini_*`, recipe generation) answers
  `403 demo_mode`.
- **No pairing, no token.** `API_TOKEN` is neither required nor ever generated by a
  demo request, so the showcase opens without a code.
- **No questions.** The demo database carries every answer the first run would ask for
  (`setup_done`, `setup_seen` at the current version of every assistant question,
  `med_setup_done`): the setup wizard and «💊 Farmaci in casa?» never open over a demo.
- **It describes itself.** `get_settings` blanks the household's rooms, coordinates,
  Bring! e-mail, TTS/AI endpoints, HA, Mealie and personal preferences, the spend ledger
  is `data/demo/shopping_spend-<lang>.json`, and shopping always uses the internal list.
- **Embeddable.** `Content-Security-Policy: frame-ancestors` replaces
  `X-Frame-Options` (`.htaccess` does the same for the static `index.html`, keyed on
  `?demo=1` and on the `/demo` mount), so a site can put the demo in an iframe:

  ```html
  <iframe src="https://evershelf.example/?demo=1&lang=en"
          allow="camera; microphone; fullscreen"
          style="width:100%;height:100vh;border:0"></iframe>
  ```

  `?demo=1&lang=…` works on **any** install (nothing to deploy); a dedicated mount is
  `https://evershelf.example/demo/?lang=en` (`DEMO_BASE_PATH`). `allow="camera;
  microphone"` is what lets the demo use the scanner inside the frame; narrow the origin
  list with `DEMO_FRAME_ANCESTORS` (e.g. `https://www.socly.it`).
- A translated **DEMO** badge appears in the header and the settings page is hidden.

---

## API Rate Limiting

EverShelf applies file-based rate limiting to protect AI endpoints:

| Tier | Limit | Endpoints |
|------|-------|-----------|
| Standard | 120 req/min | All general endpoints |
| AI | 15 req/min | `gemini_*`, `generate_recipe` |
| Strict | 5 req/min | `generate_recipe_stream` |
| Error reports | 20 req/min | `report_error`, `check_update` |
| Bug reports | 2 req/hour | `report_bug` (creates a public issue) |

Rate limit state is stored in `data/rate_limits/`. To reset, delete the files in that directory.

---

## Database

EverShelf uses **SQLite** stored at `data/evershelf.db`. The file is created automatically on first run.

Schema migrations run automatically whenever `database.php` is loaded — no manual migration steps needed.

**Backups are automatic.** The Docker image runs `api/cron_smart_shopping.php` every 5
minutes as `www-data`; that job creates at most one database backup every 23 hours in
`data/backups/` and keeps `BACKUP_RETENTION_DAYS` (default 3, `BACKUP_ENABLED=false`
turns it off). Bare-metal installs schedule the same script themselves — see
[Installation → Backup](Installation#backup). *Settings → 💾 Backup* shows the last
backup, warns when it is older than 8 days, and restores one.

> `data/backups/` sits **inside** the data volume: it protects against a bad restore or
> a wrong deletion, not against losing the volume. Copy that folder off the volume or
> enable Google Drive in the same panel.

To take one by hand (the in-app button does the same through `VACUUM INTO`):

```bash
sqlite3 data/evershelf.db ".backup 'data/backups/evershelf-$(date +%Y%m%d).db'"
```

Do not use a plain `cp` for this: the database runs in WAL mode, so the newest
transactions may still be in `data/evershelf.db-wal` and would be missing from
the copy. SQLite's `.backup` reads the database *through* the WAL and stays
consistent even while EverShelf is writing.

Or use the included `backup.sh`, which does exactly that (falling back to a PHP
WAL checkpoint when the `sqlite3` CLI is not installed):

```bash
./backup.sh
```

### Maintenance helpers in `scripts/`

| Script | What it does |
|---|---|
| `php scripts/check-upgrade-migration.php` | Builds a database with the previous release and opens it with this one: 48 checks that nothing is lost. Run it before updating a long-lived install. |
| `php scripts/fix-expiry-anchors.php` | Dry run by default. Repairs the best-before dates of an older install that were counted from the wrong day (the first pass anchored on *today* instead of the day the row entered the pantry, and a non-perishable became the year 2054). `--apply` writes, after a `VACUUM INTO` backup. |
| `php scripts/consolidate-duplicate-products.php` | `--dry-run` first. Merges the product rows created before the brand-is-a-variant rule (skips any group whose stocked rows disagree on the unit). |
| `php scripts/import-medicines.php <file>` | Imports the national medicine catalogue (AIFA CSV/XML for Italy, openFDA JSON for the US). |
| `./scripts/fix-permissions.sh` | Re-applies ownership/permissions to `data/`, `logs/` and `.env` after a manual install (`WEB_USER=…` to change the owner). |
| `./scripts/install-transformers-model.sh` | Vendors the optional MiniLM weights under `assets/vendor/transformers/` (the embedding classifier; without them the app falls back to the name heuristics). |
