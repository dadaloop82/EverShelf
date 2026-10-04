# Security Policy

## Supported Versions

Only the latest released version of EverShelf receives security fixes.

| Version | Supported |
|---------|-----------|
| Latest (1.7.x) | ✅ |
| Older releases | ❌ |

## Reporting a Vulnerability

**Please do NOT open a public GitHub issue for security vulnerabilities.**

Report security issues privately via email:

**📧 evershelfproject@gmail.com**

Include:
- A description of the vulnerability
- Steps to reproduce
- Potential impact
- Your GitHub username (optional — for credit)

I aim to acknowledge reports within **48 hours** and release a fix within **7 days** for critical issues.

## Scope

EverShelf is a **self-hosted** application. The security model assumes:

- It runs on a trusted private network (home LAN)
- Access from the internet requires the user to set up their own authentication layer (e.g. reverse proxy with Authelia, Nginx `auth_basic`)

Out-of-scope issues:
- Vulnerabilities that require physical access to the server
- Issues only affecting users who have not followed the security recommendations in the README
- Denial-of-service attacks on the demo server

## Security Features

- API keys stored server-side in `.env`, never sent to the browser
- `get_settings` returns only boolean flags (`gemini_key_set`), never raw key values
- Optional `API_TOKEN` (legacy alias `SETTINGS_TOKEN`) protects every data read and
  write (`hash_equals` to prevent timing attacks). `DEMO_MODE=true` blocks all write
  operations at the router level
- **Token bootstrap requires pairing.** `app_bootstrap` never returns `API_TOKEN` to
  an anonymous request: the UI shows a dialog and the user types the one-time code
  printed in the server log (`grep -i "pairing code" logs/evershelf_*.log` or
  `docker logs evershelf 2>&1 | grep -i "pairing code"`, 30 min TTL, brute-force
  limited). If the `grep` returns nothing, the web user cannot write the log file — a
  log created by a root `cron` run is root-owned and silently rejects Apache's writes;
  run `scripts/fix-permissions.sh`. `API_BOOTSTRAP_OPEN=true` restores the old
  "trust any same-origin-looking request" behaviour and should only be used on a
  fully trusted LAN — the headers it relies on are client-controlled.
- **No authorisation decision is made from client-controlled headers.** Actions that
  run `docker` or rewrite `.env` (`mealie_install`, `mealie_configure`, …) and the
  scale gateway endpoints require the API token; the previous `Sec-Fetch-Site` /
  `Origin` bypass was removed.
- **Every POST is checked, and the check no longer accepts a header a form can
  set.** The CSRF guard ran against a hand-written list of 25 actions out of 134
  and treated `Content-Type: application/json` as proof of good faith, so a
  cross-site `<form enctype="text/plain">` — which sends that content type with
  an attacker-chosen body — could reach any action that was not on the list
  (`chat_save`, `tts_proxy`, `generate_recipe_stream`, the `health_*` writes, …).
  Now `X-EverShelf-Request: 1` is required on every POST and the content type
  fallback is limited to the five actions listed in
  `evershelfCsrfExemptPostActions()`, which belong to native clients with no
  browser session to forge (kiosk APK, Health Bridge, Home Assistant). The
  webapp, the MCP server and the kiosk send the header; anyone calling the API
  from a script has to add it.
- Baseline security headers are sent for every response
  (`X-Content-Type-Options`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy:
  same-origin`, `Permissions-Policy`, `Cross-Origin-Opener-Policy`, and HSTS over TLS).
  A full Content-Security-Policy still needs the inline event handlers to be
  converted to delegated listeners.
- Rate limiting honours `X-Forwarded-For` only from hosts listed in `TRUSTED_PROXIES`,
  so behind a reverse proxy each client keeps its own bucket and cannot spoof one.
  Public actions that create GitHub issues (`report_bug`) have a dedicated,
  much tighter bucket.
- Parameterized SQL queries (PDO prepared statements) throughout
- Input validation and length limits on all user-supplied fields
- `.env` and `data/` directories denied via web server config (see README)
- `tts_proxy` is restricted to an allowlist of hosts (`HA_URL`, `TTS_URL`,
  `TTS_ALLOWED_HOSTS` + the server's own LAN) and always requires authentication
  or a same-origin browser session — it can never be used as an open relay.
  TLS verification is on by default; `TTS_INSECURE_SSL=true` opts in to
  self-signed LAN certificates.
- `.env` writes (`save_settings`, Google Drive OAuth callback, Mealie setup) go
  through a single writer that validates key names, strips CR/LF/NUL so a crafted
  value cannot inject new keys or comment out existing lines, and preserves
  comments/order.
- Cache files are written with `LOCK_EX` so a web request and the cron cannot
  interleave and leave truncated JSON behind.

## Repository & release secrets

Real signing material must **never** be committed. If a keystore or password was
ever committed, treat it as compromised and rotate it:

```bash
# 1. Create a fresh keystore (do this locally, keep the file OFF git)
keytool -genkeypair -v -keystore evershelf.jks -alias evershelf \
        -keyalg RSA -keysize 2048 -validity 10000
# 2. Point the build at it via evershelf-kiosk/keystore.properties (gitignored)
#    storeFile=evershelf.jks
#    storePassword=<new password>
#    keyAlias=evershelf
#    keyPassword=<new password>
```

CI builds the APKs from GitHub Secrets (repository → Settings → Secrets):

| Secret | Used by | Notes |
|--------|---------|-------|
| `KIOSK_KEYSTORE_BASE64` | `build-kiosk.yml` | `base64 -w0 evershelf.jks` |
| `KIOSK_STORE_PASSWORD` / `KIOSK_KEY_ALIAS` / `KIOSK_KEY_PASSWORD` | `build-kiosk.yml` | |
| `HEALTH_KEYSTORE_BASE64` | `build-health-bridge.yml` | |
| `HEALTH_STORE_PASSWORD` / `HEALTH_KEY_ALIAS` / `HEALTH_KEY_PASSWORD` | `build-health-bridge.yml` | |

Without these secrets the workflows fall back to the Android debug keystore so
builds still succeed — but those APKs will not install as updates over a release
signed with the real key. If the exposed key was already distributed, rotating it
breaks OTA updates for existing installs (Android rejects a signature change);
notify users to reinstall once.
