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
# the one-time pairing code printed in the server log (see below).
# Compared with hash_equals() to prevent timing attacks.
API_TOKEN=

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

> **Security note:** `get_settings` returns only **boolean flags** (`gemini_key_set: true/false`), never raw key values. Raw values are only accessible server-side.

---

## Protecting the API with a token

If your EverShelf instance is reachable from an untrusted network, set `API_TOKEN` to a strong random string:

```bash
# Generate a strong token
openssl rand -hex 32
```

```ini
API_TOKEN=a3f9b2c1d4e5...
```

From then on every API action requires it — the web UI sends `X-API-Token`, Home Assistant can use `?api_token=`. A missing or wrong token is rejected with HTTP 403.

### First run: the pairing code

The token is **never** handed to an anonymous request, so the browser cannot simply
ask for it: the first time the UI loads it shows a **pairing dialog** and you type a
one-time code printed in the server log.

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
`bash scripts/fix-permissions.sh` (or `chown -R www-data:www-data logs/`).

The code is 8 hex characters, valid for 30 minutes, and is **consumed** by the first
successful pairing (50 wrong attempts from one IP burn it immediately). You pair once
per browser/device — afterwards the token lives in `localStorage` and you are not asked
again. Unpair by clearing site data, or pair another device by waiting for the next
code.

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

Two ways to enable demo mode:

1. **Permanent:** Set `DEMO_MODE=true` in `.env`
2. **Per-session:** Append `?demo=1` to any URL (e.g. `https://evershelf.site/demo`)

In demo mode:
- All POST/write API calls return success without touching the database
- A "DEMO" badge appears in the header
- Gemini AI is treated as available (mock responses)
- Bring! write operations are silently no-op'd
- A mock pantry with sample data is loaded

---

## API Rate Limiting

EverShelf applies file-based rate limiting to protect AI endpoints:

| Tier | Limit | Endpoints |
|------|-------|-----------|
| Standard | 120 req/min | All general endpoints |
| AI | 15 req/min | `gemini_*`, `generate_recipe` |
| Strict | 5 req/min | `report_error` |

Rate limit state is stored in `data/rate_limits/`. To reset, delete the files in that directory.

---

## Database

EverShelf uses **SQLite** stored at `data/evershelf.db`. The file is created automatically on first run.

Schema migrations run automatically whenever `database.php` is loaded — no manual migration steps needed.

To back up the database:

```bash
cp data/evershelf.db data/backups/evershelf-$(date +%Y%m%d).db
```

Or use the included `backup.sh`:

```bash
./backup.sh
```
