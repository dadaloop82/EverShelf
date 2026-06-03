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
- Secrets (`HA_TOKEN`, `TTS_TOKEN`, `GEMINI_API_KEY`) stay in `.env`; `get_settings` exposes only `*_set` flags.
- **`GH_ISSUE_TOKEN_ENC`** + **`GH_ISSUE_TOKEN_KEY`**: AES-256-GCM encrypted GitHub Issues token.

## Planned refactors

1. Split `api/index.php` handlers into `api/handlers/{products,inventory,ai,shopping}.php`
2. Split `assets/js/app.js` into ES modules under `assets/js/features/`
3. Optional `npm run build` to minify JS/CSS (see `package.json`)
