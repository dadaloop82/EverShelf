# EverShelf — Codebase Map

> Deep, factual map of the repository to work without re-reading the monoliths.
> Line numbers are for the commit at the time of writing; regenerate the indexes
> with `bash scripts/gen-code-index.sh` after large edits.

## 1. Top-level layout

| Path | What it is | Size / notes |
|---|---|---|
| `index.html` | SPA shell, all pages as `<section>` + modals | ~2.3k lines |
| `assets/js/app.js` | **Entire frontend logic** (single file) | ~25.6k lines, 913 fns |
| `assets/js/core/auth.js` | API token helpers (`getApiToken`, `apiAuthHeaders`) | loaded before app.js |
| `assets/js/core/dom.js` | `escapeHtml` | loaded before app.js |
| `assets/css/style.css` | All styles | ~10.4k lines |
| `assets/css/corporate.css` | Corporate/"kiosk" theme overlay | ~640 lines |
| `api/index.php` | **Entire backend**: router + all handlers | ~19.5k lines, 410 fns |
| `api/bootstrap.php` | Shared init for HTTP + cron | requires every lib |
| `api/database.php` | SQLite schema + migrations | ~845 lines |
| `api/logger.php` | `EverLog` rotating file logger + `LoggingPDO` | |
| `api/lib/*.php` | Domain libs (see §4) | |
| `api/cron_*.php` | CLI jobs (smart shopping, mealie cache, barcode catalog) | run by cron |
| `api/scale_*.php` | Kitchen-scale gateway relay/discovery | |
| `translations/*.json` | 6 languages, nested keys | ~62 top-level keys |
| `data/` | Runtime DB + caches + logs (**HTTP denied** via `.htaccess`) | not all committed |
| `scripts/*` | Maintenance CLIs (i18n sync, backfills, GH triage, env migration) | |
| `mcp-server/` | Node MCP server exposing EverShelf API to agents | separate npm pkg |
| `evershelf-kiosk/`, `evershelf-health-bridge/` | Android apps (Kotlin/Gradle) | |
| `docs/`, `docs/wiki/` | Documentation | |
| `.github/workflows/` | CI: lint, docker build, i18n check, auto-merge, release | |

## 2. Request lifecycle (backend)

1. Apache rewrites `/api/<x>` → `api/index.php?action=<x>` (`.htaccess`).
2. `api/bootstrap.php` runs: env → constants → github → security → mealie →
   cron_log → logger → database → shopping_guards → health → weather →
   ai_provider → **seasonal**.
3. Early, DB-free short-circuits in `api/index.php`: `ping`,
   `health_bridge_hello`, `kiosk_update`/`getKioskUpdate`, `get_logs`,
   `gemini_usage`, `health_check`.
4. `getDB()` (SQLite, WAL, busy_timeout 20s) then global exception handlers.
5. Rate limit (`checkRateLimit`) → CSRF guard for write actions →
   `evershelfRequireApiAuth` → demo-mode block → `switch ($action)` (line ~847).
6. Handlers read `php://input` JSON or `$_GET`, write DB, `echo json_encode`.

## 3. Frontend lifecycle

- `DOMContentLoaded` (~line 24731) wires everything; `showPage()` switches
  `<section class="page">` visibility; `api()` (line **5073**) is the single
  fetch wrapper (retries on SQLite `database_busy`, offline queue, error report).
- State held in module-level `let` vars (`shoppingItems`, `LOCATIONS`,
  `_currentLang`, `_scale*`, banner queue, …). No framework/reactivity.
- i18n via `t(key)` + `data-i18n` attributes; `loadTranslations()` fetches
  `translations/<lang>.json`.
- PWA: `sw.js` caches the app shell; `manifest.json` for install.

## 4. Library reference (`api/lib`)

| File | Responsibility | Key entry points |
|---|---|---|
| `env.php` | `.env` loader + DB-stored overrides | `env()`, `loadEnv()`, `saveEnvOverrides()`, `clearEnvOverrides()` |
| `constants.php` | Paths + **Gemini pricing** constants | `EVERSHELF_ROOT`, `*_CACHE_PATH`, `GEMINI_COST_*` |
| `security.php` | Auth, CORS, demo mode, SSRF allowlists | `evershelfRequireApiAuth()`, `evershelfSendCorsHeaders()`, `evershelfScaleHostAllowed()` |
| `github.php` | Encrypted GH Issues token helpers | used by `report_error`/`report_bug` |
| `ai_provider.php` | Provider abstraction (gemini/openai/llama) | `aiProviderConfigured()`, chat/vision calls |
| `mealie.php`, `mealie_setup.php` | Mealie recipe-manager integration | discover/install/configure/sync |
| `health.php` | Health/Fuel mode + Health Bridge | ingest, profile, daily rollups |
| `weather.php` | Weather fetch + geocode | `weather_get`, `weather_geocode` |
| `shopping_guards.php` | Anti-waste qty guards for shopping | used by `smartShopping` |
| `seasonal.php` | **IT produce calendar** + stale-stock | `seasonalReviewShopping()`, `staleInventoryItems()` |
| `cron_log.php` | Rotates `data/cron.log` | |


## 5. Database schema (`data/evershelf.db`, SQLite/WAL)

Core tables (created in `initializeDB`):

```sql
products(id PK, barcode UNIQUE, name, brand, category, image_url, unit,
         default_quantity REAL, notes, shopping_name, created_at, updated_at,
         -- added by migrations: package_unit, is_favorite, nutriments_json, ...)
inventory(id PK, product_id FK->products ON DELETE CASCADE, location,
          quantity REAL, expiry_date DATE, added_at, updated_at,
          -- added by migrations: vacuum_sealed, opened_at, ...)
transactions(id PK, product_id FK, type CHECK IN('in','out','waste'),
             quantity, location, notes, undone INT, created_at)
barcode_cache, shopping_templates, shopping_list(id, name UNIQUE lower, raw_name,
             specification, added_at, sort_order), app_settings(key PK, value),
recipes(id, date, meal, recipe_json, UNIQUE(date,meal), is_favorite),
chat_messages(id, role, text, created_at), health_daily, ...
```

Indexes: `idx_products_barcode`, `idx_inventory_product`, `idx_inventory_location`,
`idx_transactions_product`, `idx_transactions_date`, and composite
`idx_transactions_type_date`, `idx_transactions_pid_type_undone`.

Migrations are idempotent and guarded (`PRAGMA table_info` / `sqlite_master`);
some one-shot data migrations are gated by flags in `app_settings`.

## 6. Runtime data files (`data/`)

Most are user/runtime state and git-ignored (`*` = committed static/tracked).

| File | Purpose |
|---|---|
| `evershelf.db` | main DB |
| `backups/` | DB snapshots |
| `bring_token.json`, `bring_catalog.json` | Bring! auth + catalog |
| `smart_shopping_cache.json` | computed shopping plan |
| `shopping_price_cache.json` | AI price estimates |
| `shopping_name_cache.json` * | name→generic mapping |
| `shopping_total_cache.json` * | canonical list totals |
| `shopping_spend.json` | spend ledger |
| `ai_usage.json` * | token usage/cost |
| `opened_shelf_cache.json` | opened-pack shelf-life |
| `category_ai_cache.json`, `food_facts_cache.json` | AI caches |
| `seasonal_produce_it.json` * | static IT produce dataset |
| `audit_finished_missing.json`, `reported_issue_fps.json` | tool scratch |
| `cron.log*`, `error_reports.log`, `client_debug.log` | logs |
| `rate_limits/` | per-IP rate-limit buckets |
| `weather_cache_*.json` | weather cache |

## 7. API surface

~180 actions. Full action→handler→line table: **`docs/INDEX-actions.md`**.
Groups:

- **Products**: `search_barcode`, `lookup_barcode`, `resolve_barcode`,
  `barcode_catalog_sync`, `stock_for_name`, `product_save/get/delete/merge`,
  `products_list/search`, `inventory_search`, `ai_product_suggest`,
  `guess_category`.
- **Inventory**: `inventory_list/add/use/update/delete`, `inventory_summary`,
  `family_sibling_suggest`, `inventory_finished_items`,
  `inventory_confirm_finished`, `inventory_restore_ghost`,
  `recent_popular_products`.
- **Transactions / stats**: `transactions_list`, `transaction_undo`, `stats`,
  `monthly_stats`, `spend_add`, `spend_stats`, `consumption_predictions`,
  `inventory_anomalies`, `inventory_duplicate_loss_checks`, `dismiss_anomaly`,
  `macro_stats`, `expiry_history`, `food_facts`, `opened_shelf_life`.
- **AI**: `gemini_identify`, `gemini_expiry`, `gemini_chat`, `generate_recipe`,
  `generate_recipe_stream`, `chat_to_recipe`, `recipe_from_ingredient`,
  `gemini_product_hint`, `gemini_shopping_enrich`, `gemini_anomaly_explain`,
  `gemini_number_ocr`, `gemini_barcode_visual`, `tts_proxy`, `ai_test`.
- **Shopping**: `shopping_list/add/remove/suggest`, `smart_shopping`,
  `seasonal_shopping_review`, `stale_inventory_items`, templates, and the
  `bring_*` family (Bring! mirror).
- **Settings / ops**: `save_settings`, `get_settings`, `app_settings_get/save`,
  `db_cleanup`, `backup_now/list/delete/restore`, `gdrive_*`, `check_update`,
  `client_log`, `get_client_log`, `get_logs`, `gemini_usage`, `report_error`,
  `report_bug`, `migrate_units`, `export_inventory`, `import_inventory`.
- **Integrations**: `ha_*` (Home Assistant), `mealie_*`, `health_*`,
  `weather_get/geocode`, `scale_*` (separate PHP files).

## 8. Auth & trust model

- If `API_TOKEN` (or legacy `SETTINGS_TOKEN`) is **empty** (default), every
  action is open. If set, almost everything needs `X-API-Token`/`?api_token=`
  (or `Authorization: Bearer`). Public allow-list:
  `api/lib/security.php::evershelfPublicActions()`.
- CSRF: POST write-actions require `X-EverShelf-Request: 1` or
  `Content-Type: application/json`.
- Demo-mode block list + read-only allow-list also in `api/lib/security.php`.
- CORS is off by default; only emitted if `CORS_ORIGIN` is set.

## 9. Config & secrets

- Secrets live in `.env` (git-ignored). `save_settings` (~line 8082) rewrites
  `.env`, or falls back to DB overrides in `app_settings.env_overrides`.
- `.env.example` documents every knob with comments.
- `.env`, `data/`, `logs/` are denied by `.htaccess`.

## 10. CI/CD (`.github/workflows`)

- `ci.yml`: PHP lint, `node -c`, Docker build smoke test, translations check,
  then **auto-merge develop → main** and **create GH Release** using the version
  in `index.html`.
- `build-kiosk.yml`, `build-health-bridge.yml`, `build-scale-gateway.yml`,
  `publish-docker.yml`, `security.yml`, dependabot.

## 11. Structural debt

- `api/index.php` and `assets/js/app.js` are monoliths; `docs/ARCHITECTURE.md`
  lists a planned split (`api/handlers/*`, `assets/js/features/*`).
- Android keystores + signed APKs committed in-tree (see review §Security).

