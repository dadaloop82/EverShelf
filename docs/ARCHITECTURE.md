# EverShelf — Architecture (modular layout)

```
dispensa/
├── api/
│   ├── bootstrap.php       # Shared init: env, security, DB, logger
│   ├── index.php           # HTTP handlers + router (split planned per domain)
│   ├── database.php        # SQLite schema & migrations
│   ├── logger.php          # Rotating file logger (logs/)
│   ├── cron_smart_shopping.php  # CLI cron (uses bootstrap + index handlers)
│   ├── lib/
│   │   ├── env.php         # .env loader
│   │   ├── constants.php   # Paths & pricing constants
│   │   ├── security.php    # API auth, CORS, demo mode, scale allowlist
│   │   ├── github.php      # Encrypted GitHub Issues token
│   │   └── cron_log.php    # data/cron.log rotation
│   └── scale_*.php         # Scale gateway helpers (auth + SSRF guards)
├── assets/
│   ├── js/
│   │   ├── core/           # auth.js, dom.js (loaded before app.js)
│   │   └── app.js          # SPA logic (domain modules: future split)
│   └── vendor/             # Offline CDN fallbacks (quagga, transformers)
├── data/                   # Runtime data (.htaccess: deny all)
├── logs/                   # Application logs (.htaccess: deny all)
└── scripts/                # migrate-env-security, fix-permissions, encrypt-gh-token
```

## Security model

- **`API_TOKEN`** (or legacy **`SETTINGS_TOKEN`**): when set, every API action requires `X-API-Token` header or `?api_token=` (Home Assistant).
- **Token bootstrap requires pairing.** `app_bootstrap` never returns `API_TOKEN` to an
  anonymous request: the UI shows a dialog and the user types the one-time **pairing code**
  printed in the server log (`grep -i "pairing code" logs/evershelf_*.log`, 30 min TTL,
  50 attempts/IP, then the code is burned). `API_BOOTSTRAP_OPEN=true` re-exposes the token
  to any request that looks same-origin — trusted LANs only. See `SECURITY.md`.
- **No authorisation decision comes from a client-controlled header.** `Origin`,
  `Referer` and `Sec-Fetch-Site` are forgeable, so the old same-origin bypass is gone from
  every action, including those that run `docker` or rewrite `.env`.
- The pairing dialog is rendered **during** startup, so the auth overlays
  (`EVERSHELF_AUTH_OVERLAY_Z` in `assets/js/core/auth.js`) must stay above the splash
  preloader (200000) and the network-error overlay (300000). At the `.modal-overlay`
  default of 200 the dialog hides behind the splash and `_initApp()` — which returns early
  when it cannot authenticate — never removes it: the app dead-ends on "API token required".
- Secrets (`HA_TOKEN`, `TTS_TOKEN`, `GEMINI_API_KEY`) stay in `.env`; `get_settings` exposes only `*_set` flags.
- **`GH_ISSUE_TOKEN_ENC`** + **`GH_ISSUE_TOKEN_KEY`**: AES-256-GCM encrypted GitHub Issues token.
  Opening issues is a second opt-in (`REPORT_ENABLED=true`) and every outbound
  payload is redacted and capped in `api/lib/security.php` before it is sent.

## Planned refactors

1. Split `api/index.php` handlers into `api/handlers/{products,inventory,ai,shopping}.php`
2. Split `assets/js/app.js` into ES modules under `assets/js/features/`
3. Optional `npm run build` to minify JS/CSS (see `package.json`)
