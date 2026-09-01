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
│   │   ├── reconciliation.php # Physical-count session lifecycle + atomic apply
│   │   └── cron_log.php    # data/cron.log rotation
│   └── scale_*.php         # Scale gateway helpers (auth + SSRF guards)
├── assets/
│   ├── css/
│   │   └── reconciliation.css # Isolated mobile stocktake UI
│   ├── js/
│   │   ├── core/           # auth.js, dom.js (loaded before app.js)
│   │   ├── reconciliation.js # Stocktake page + shared-scanner consumer
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

## Physical inventory reconciliation

Reconciliation is an isolated session workflow layered over the existing product and inventory tables:

- `inventory_reconciliations` stores one location and lifecycle state (`in_progress`, `review`, `applied`, or `cancelled`). A partial unique index allows only one active session per location.
- `inventory_reconciliation_items` stores immutable product display/unit metadata, expected aggregate quantity, and nullable physical count.
- `inventory_reconciliation_item_rows` fingerprints the contributing inventory rows, quantities, expiry/opened state, and update timestamps for optimistic concurrency checks.
- `inventory_adjustments` stores signed row-level deltas. These adjustments participate in ledger/anomaly balance calculations but remain separate from purchase, consumption, waste, and undo history.

Counting and review never mutate live stock. Apply uses one SQLite `BEGIN IMMEDIATE` transaction, validates all counted snapshots, updates only counted products, writes the adjustment audit, and commits the session atomically. The frontend reuses the existing barcode camera/decoder through a temporary result consumer rather than owning another scanner.

## Planned refactors

1. Split `api/index.php` handlers into `api/handlers/{products,inventory,ai,shopping}.php`
2. Split `assets/js/app.js` into ES modules under `assets/js/features/`
3. Optional `npm run build` to minify JS/CSS (see `package.json`)
