# EverShelf — Architecture (modular layout)

```
dispensa/
├── index.html                  # SPA shell (i18n badges, CSS/JS ?v= cache-busting)
├── sw.js / manifest.json       # PWA service worker (CACHE name carries the version)
├── api/
│   ├── bootstrap.php           # Shared init: env, security, DB, logger
│   ├── index.php               # HTTP handlers + the single `switch ($action)` router
│   ├── database.php            # SQLite schema & migrations
│   ├── logger.php              # Rotating file logger (logs/)
│   ├── cron_smart_shopping.php # CLI cron (uses bootstrap + index handlers)
│   ├── cron_barcode_catalog.php# CLI cron: offline barcode catalog refresh
│   ├── cron_mealie_cache.php   # CLI cron: Mealie recipe cache
│   ├── lib/
│   │   ├── env.php             # .env loader
│   │   ├── constants.php       # Paths & pricing constants
│   │   ├── security.php        # API auth, CORS, CSRF, demo mode, redaction
│   │   ├── pairing.php         # One-time pairing code → API token
│   │   ├── ai_provider.php     # Gemini / OpenAI / Llama fan-out
│   │   ├── health.php          # Health & Fuel mode
│   │   ├── weather.php         # Optional weather enrichment
│   │   ├── seasonal.php        # Seasonal produce (+ data/seasonal_produce_it.json)
│   │   ├── shopping_guards.php # Spend limits & cooldowns on the shopping list
│   │   ├── shopping_sync.php   # Shared Bring! / internal list sync
│   │   ├── recipe_shopping.php # Recipe ingredients → shopping list (pantry deducted)
│   │   ├── calendar_ics.php    # ICS/WebCal expiry feed (RFC 5545 + token gate)
│   │   ├── notify.php          # ntfy + generic webhook fan-out (legacy HA kept)
│   │   ├── healthcheck.php     # Cron watchdog (dead-man's switch ping)
│   │   ├── barcode_catalog.php # Offline barcode catalog builder
│   │   ├── mealie.php / mealie_setup.php # Mealie recipe integration + wizard
│   │   ├── github.php          # Encrypted GitHub Issues token
│   │   ├── i18n.php            # Server-side translations
│   │   └── cron_log.php        # data/cron.log rotation
│   └── scale_*.php             # Scale gateway helpers (auth + SSRF guards)
├── assets/
│   ├── css/
│   │   ├── style.css           # Base layout
│   │   ├── corporate.css       # Layer 2 — corporate look
│   │   └── elegant.css         # Layer 3 — elegant refinements (loaded last; drop the <link> to revert)
│   ├── js/
│   │   ├── core/               # auth.js, dom.js (loaded before app.js)
│   │   └── app.js              # SPA logic (domain modules: future split)
│   └── vendor/                 # Offline CDN fallbacks (quagga, tesseract, transformers, zbar)
├── translations/               # it, en, de, fr, es, zh (+ _SUPPORTED_LANGS in app.js)
├── data/                       # Runtime data (.htaccess: deny all)
├── logs/                       # Application logs (.htaccess: deny all)
├── mcp-server/                 # MCP bridge (ESM Node, talks to the same REST API)
├── evershelf-kiosk/            # Android kiosk + BLE scale gateway
├── evershelf-health-bridge/    # Android Health Connect → EverShelf bridge
├── docker/                     # Dockerfile + compose (the alternative install path)
├── .github/workflows/          # CI: PHP/JS lint, smoke build, auto-merge, release
├── package.json                # Optional minify build (`npm run build`)
├── backup.sh                   # SQLite + .env backup helper
├── releases/                   # Release artifacts (kiosk/health-bridge APKs)
├── docs/                       # Architecture, wiki, generated code indexes, openapi.yaml
└── scripts/                    # bump-version, gen-code-index, i18n audits, test-*.php
```

## Security model

- **`API_TOKEN`** (or legacy **`SETTINGS_TOKEN`**): when set, every API action requires `X-API-Token` header or `?api_token=` (Home Assistant).
- **Token bootstrap requires pairing.** `app_bootstrap` never returns `API_TOKEN` to an
  anonymous request: the UI shows a dialog and the user types the one-time **pairing code**.
  An already-paired device shows the live code under **Settings → System → Security**
  (and Info); it is also printed in the server log
  (`grep -i "pairing code" logs/evershelf_*.log`, 30 min TTL, 50 attempts/IP, then the
  code is burned). `API_BOOTSTRAP_OPEN=true` re-exposes the token to any request that
  looks same-origin — trusted LANs only. See `SECURITY.md`.
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
