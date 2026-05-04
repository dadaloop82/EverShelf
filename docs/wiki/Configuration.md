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

# Protect the save_settings endpoint with a token
# If set, the Settings UI will prompt for this value before saving
# Validated with hash_equals() to prevent timing attacks
SETTINGS_TOKEN=

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

## Protecting Settings with a Token

If your EverShelf instance is accessible from untrusted networks, set `SETTINGS_TOKEN` to a strong random string:

```bash
# Generate a strong token
openssl rand -hex 32
```

```ini
SETTINGS_TOKEN=a3f9b2c1d4e5...
```

Users will be prompted for this token before any Settings save. If the token doesn't match, the request is rejected with HTTP 403.

---

## Demo Mode

Two ways to enable demo mode:

1. **Permanent:** Set `DEMO_MODE=true` in `.env`
2. **Per-session:** Append `?demo=1` to any URL (e.g. `https://evershelfproject.dadaloop.it/demo`)

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
