# EverShelf — Codebase Map

> Deep, factual map of the repository to work without re-reading the monoliths.
> Line numbers are for the commit at the time of writing; regenerate the indexes
> with `bash scripts/gen-code-index.sh` after large edits.
>
> Counts and line numbers below were measured for **v1.9.1** (`fe318b9`, 2026-10-05)
> and are rounded.

## 1. Top-level layout

| Path | What it is | Size / notes |
|---|---|---|
| `index.html` | SPA shell, all pages as `<section>` + modals | ~2.6k lines |
| `assets/js/app.js` | **Entire frontend logic** (single file) | ~27.1k lines, 974 fns |
| `assets/js/core/auth.js` | API token helpers (`getApiToken`, `apiAuthHeaders`) | loaded before app.js |
| `assets/js/core/dom.js` | `escapeHtml` | loaded before app.js |
| `assets/css/style.css` | All styles | ~10.8k lines |
| `assets/css/corporate.css` | Corporate/"kiosk" theme overlay | ~640 lines |
| `assets/css/elegant.css` | Third layer: the "elegant" restyle **and** the dark-mode repairs it caused | ~740 lines; loaded **last**, deletes no selector |
| `api/index.php` | **Entire backend**: router + all handlers | ~19.6k lines, 409 fns |
| `api/bootstrap.php` | Shared init for HTTP + cron | requires every lib |
| `api/database.php` | SQLite schema + migrations | ~845 lines |
| `api/logger.php` | `EverLog` rotating file logger + `LoggingPDO` | |
| `api/lib/*.php` | Domain libs (see §4) | |
| `api/cron_*.php` | CLI jobs (smart shopping, mealie cache, barcode catalog) | run by cron |
| `api/scale_*.php` | Kitchen-scale gateway relay/discovery | |
| `translations/*.json` | 6 languages, nested keys | 2,184 leaf keys, 67 top-level groups |
| `data/` | Runtime DB + caches + logs (**HTTP denied** via `.htaccess`) | not all committed |
| `scripts/*` | Maintenance CLIs + the regression suite CI runs | |
| `mcp-server/` | Node MCP server exposing EverShelf API to agents | separate npm pkg |
| `evershelf-kiosk/`, `evershelf-health-bridge/` | Android apps (Kotlin/Gradle) | |
| `docs/`, `docs/wiki/` | Documentation | |
| `.github/workflows/` | CI: lint (PHP/JS/shell), PHP regression tests, docker build, i18n audit, auto-merge, release | |

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
   `evershelfRequireApiAuth` → demo-mode block → `switch ($action)` (line ~898).
6. Handlers read `php://input` JSON or `$_GET`, write DB, `echo json_encode`.

## 3. Frontend lifecycle

- `DOMContentLoaded` (~line 25800) wires everything; `showPage()` switches
  `<section class="page">` visibility; `api()` (line **5384**) is the single
  fetch wrapper (retries on SQLite `database_busy`, offline queue, error report).
- State held in module-level `let` vars (`shoppingItems`, `LOCATIONS`,
  `_currentLang`, `_scale*`, banner queue, …). No framework/reactivity.
- i18n via `t(key)` + `data-i18n` attributes; `loadTranslations()` fetches
  `translations/<lang>.json`. **Never** print a literal emoji beside a translated
  label: the values already carry their icon, so use `iconLabel(icon, key)` /
  `_stripLeadingEmoji()` (`scripts/test-i18n-icons.php` enforces it in all six locales).
- Dashboard: the alert blocks are capped per block (`DASHBOARD_EXPIRING_MAX` 3,
  `DASHBOARD_OPENED_MAX` 5, `DASHBOARD_ALERT_MAX` 5 for expired,
  `DASHBOARD_STALE_MAX` 3 with a deterministic 30-minute `_staleRotationPick`). The
  insight area rotates `_INSIGHT_PHASES` (8 panels, 60 s each) through
  `_applyInsightPhase()`, which skips any panel whose body is empty.
- Settings: `_initSettingsAccordions()` turns every heading-bearing `.settings-card`
  into an accordion (`_toggleSettingsCard`/`_setSettingsCardOpen`/`_closeSettingsCards`,
  one open at a time, `SETTINGS_ACCORDION_SKIP = ['settings-checklist']`), and
  `_openSettingsCardFor(el)` opens + flashes the card a checklist row points at.
  `SETTINGS_CHECKLIST` drives both the checklist card and the guided assistant.
- PWA: `sw.js` caches the app shell; `manifest.json` for install.

## 4. Library reference (`api/lib`)

| File | Responsibility | Key entry points |
|---|---|---|
| `env.php` | `.env` loader + DB-stored overrides; bridges `.env` into `getenv()`/`$_ENV` | `env()`, `loadEnv()`, `evershelfWriteEnvFile()`, `saveEnvOverrides()` |
| `constants.php` | Paths + **Gemini pricing** constants | `EVERSHELF_ROOT`, `*_CACHE_PATH`, `GEMINI_COST_*` |
| `security.php` | Auth, CORS, security headers, demo mode, SSRF allowlists, client IP, **outbound report redaction** | `evershelfRequireApiAuth()`, `evershelfSendSecurityHeaders()`, `evershelfClientIp()`, `evershelfScaleHostAllowed()`, `evershelfRedactSecrets()`, `evershelfReportContextJson()` |
| `pairing.php` | One-time pairing code for `app_bootstrap` token disclosure | `evershelfPairingEnsure()`, `evershelfPairingConsume()` |
| `github.php` | Encrypted GH Issues token helpers + `REPORT_ENABLED` opt-in | `_ghToken()`, `_ghReportsEnabled()`, used by `report_error`/`report_bug` |
| `ai_provider.php` | Provider abstraction (gemini/openai/llama) | `aiProviderConfigured()`, chat/vision calls |
| `mealie.php`, `mealie_setup.php` | Mealie recipe-manager integration | discover/install/configure/sync |
| `health.php` | Health/Fuel mode + Health Bridge | ingest, profile, daily rollups |
| `weather.php` | Weather fetch + geocode | `weather_get`, `weather_geocode` |
| `shopping_guards.php` | Anti-waste qty guards for shopping | used by `smartShopping` |
| `product_kind.php` | **Product genre (genere)**: the curated IT dictionary that leads every article title, the signature cache for similar products, the AI fallback | `productKindApply()`, `resolveProductKind()`, `applyProductKindPrefix()`, `evershelfShoppingPhraseMap()`/`evershelfShoppingKeywordMap()` (shared with `computeShoppingName()`) |
| `auto_favorite.php` | **Automatic favourites**: promote the products consumed N times in the window, never overrule a manual unstar | `maybeAutoFavorite()`, `rememberFavoriteOverride()`, `autoFavoriteSweep()`, `autoFavoriteCandidates()` |
| `shopping_sync.php` | **Shared Bring!/internal list sync** (markers, smart-item index, "still needed?" predicate) | `evershelfShoppingRowStillNeeded()`, `evershelfBuildShoppingSpec()`, `evershelfLoadSmartItemsForSync()` |
| `seasonal.php` | **IT produce calendar** + stale-stock; the review card returns a `tip_key` the client resolves | `seasonalReviewShopping()`, `seasonalIsAllYearCrop()`, `staleInventoryItems()` |
| `i18n.php` | Server-side `evershelfTr('key', $lang)` for responses PHP renders itself | used by the ICS feed, the notifier and seasonal tips |
| `notify.php` | **Outbound notifications**: ntfy + generic webhook fan-out (legacy HA notify service kept), i18n-aware event formatting, URL/topic/priority/body guards | `evershelfNotifyEvent()`, `evershelfNotifySend()`, `evershelfNotifyConfigured()`, `notifyTestAction()` (in `index.php`) |
| `healthcheck.php` | **Cron watchdog** (dead-man's switch): pings Healthchecks.io or an Uptime Kuma push URL at the end of every CLI job + records the last outcome per job | `evershelfHealthcheckPing()`, `evershelfHealthcheckSend()`, `evershelfHealthcheckConfigured()`, `evershelfHealthcheckStatus()`, `notifyHealthcheckTestAction()` (in `index.php`) |
| `calendar_ics.php` | **ICS/WebCal expiry feed** (RFC 5545 emit + token gate) | `evershelfIcsBuild()`, `calendarIcsFeed()`, `getIcsSettings()`, `rotateIcsToken()` |
| `recipe_shopping.php` | **Recipe → shopping list** with pantry deduction (recompute the gap on open) | `evershelfRecipeShoppingPlan()`, `recipeShoppingAdd()`, `evershelfBaseQty()` |
| `cron_log.php` | Rotates `data/cron.log` | |


## 5. Database schema (`data/evershelf.db`, SQLite/WAL)

Core tables (created in `initializeDB`):

```sql
products(id PK, barcode UNIQUE, name, brand, category, image_url, unit,
         default_quantity REAL, notes, shopping_name, created_at, updated_at,
         -- added by migrations: package_unit, is_favorite, nutriments_json,
         -- name_user_set, kind (genre), favorite_user_override, ...)
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
| `product_kind_cache.json` | genre resolved per product signature (similar products reuse it) |
| `shopping_total_cache.json` * | canonical list totals |
| `shopping_spend.json` | spend ledger |
| `ai_usage.json` * | token usage/cost |
| `opened_shelf_cache.json` | opened-pack shelf-life |
| `category_ai_cache.json`, `food_facts_cache.json` | AI caches |
| `seasonal_produce_it.json` * | static IT produce dataset |
| `audit_finished_missing.json`, `reported_issue_fps.json` | tool scratch |
| `cron.log*`, `error_reports.log`, `client_debug.log` | logs |
| `cron_health.json` | last outcome per CLI cron job, written by the watchdog (`api/lib/healthcheck.php`) |
| `rate_limits/` | per-IP rate-limit buckets |
| `weather_cache_*.json` | weather cache |

## 7. API surface

~180 actions. Full action→handler→line table: **`docs/INDEX-actions.md`**.
Groups:

- **Products**: `search_barcode`, `lookup_barcode`, `resolve_barcode`,
  `barcode_catalog_sync`, `stock_for_name`, `product_save/get/delete/merge`,
  `products_list/search`, `inventory_search`, `ai_product_suggest`,
  `guess_category`, `products_toggle_favorite`, and
  **`products_apply_auto_rules`** (POST, `dry_run` preview) which re-applies the
  two automatic product rules to the items already stored: the genre prefix in
  every title (`lib/product_kind.php`) and the promotion of the products the
  household keeps consuming to favourites (`lib/auto_favorite.php`).
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
  `bring_*` family (Bring! mirror). **`recipe_shopping_add`** turns a recipe into
  the list of what the pantry does not cover (POST, `dry_run` for the render).
- **Settings / ops**: `save_settings`, `get_settings`, `app_settings_get/save`,
  `db_cleanup`, `backup_now/list/delete/restore`, `gdrive_*`, `check_update`,
  `client_log`, `get_client_log`, `get_logs`, `gemini_usage`, `report_error`,
  `report_bug`, `migrate_units`, `export_inventory`, `import_inventory`.
- **Notifications (ntfy / webhook)**: `notify_test` (POST — pushes one message
  through every configured channel, ignores `NOTIFY_ENABLED`, reports per-channel
  HTTP status; the same fan-out runs on every `_fireHaWebhook()` event).
- **Cron watchdog (healthchecks.io / Uptime Kuma)**: `notify_healthcheck_test`
  (POST — pings the typed-or-stored URL once and reports the HTTP status; the
  stored state is untouched). The CLI jobs ping from cron via
  `evershelfHealthcheckPing()`; `get_settings` returns
  `notify_healthcheck_set/_jobs/_status` (never the URL).
- **Integrations**: `ha_*` (Home Assistant), `mealie_*`, `health_*`,
  `weather_get/geocode`, `scale_*` (separate PHP files).
- **Calendar (ICS)**: `calendar_ics` (**public**, guarded by its own read-only
  `ICS_TOKEN` — a calendar client can only GET a URL), `get_ics_settings`,
  `rotate_ics_token`.

## 8. Auth & trust model

- If `API_TOKEN` (or legacy `SETTINGS_TOKEN`) is **empty** (default), every
  action is open. If set, almost everything needs `X-API-Token`/`?api_token=`
  (or `Authorization: Bearer`). Public allow-list:
  `api/lib/security.php::evershelfPublicActions()`.
- CSRF: **every** POST requires `X-EverShelf-Request: 1`, whatever the action.
  Only the actions in
  `api/lib/security.php::evershelfCsrfExemptPostActions()` — native clients with
  no browser session to forge (`report_error`, `client_log`, `save_settings`,
  `health_ingest`, `ha_generate_recipe`) — may fall back to
  `Content-Type: application/json`. A `<form>` can send neither a custom header
  nor JSON, so both proofs are out of reach cross-site.
- Demo-mode block list + read-only allow-list also in `api/lib/security.php`.
- CORS is off by default; only emitted if `CORS_ORIGIN` is set.

## 9. Config & secrets

- Secrets live in `.env` (git-ignored). `save_settings` (~line 8082) rewrites
  `.env`, or falls back to DB overrides in `app_settings.env_overrides`.
- `.env.example` documents every knob with comments.
- `.env`, `data/`, `logs/` are denied by `.htaccess`.

## 10. CI/CD (`.github/workflows`)

- `ci.yml`: PHP lint (`php -l` on every file under `api/`), `node -c
  assets/js/app.js`, translation JSON validity + top-level key parity against
  `it.json`, a Docker build smoke test, then **auto-merge develop → main** and
  **create GH Release** using the version in `index.html` (the release body is the
  `## [x.y.z]` CHANGELOG section for that version, when it exists). Two details are
  worth remembering when writing the entry: `awk` collects the section up to the next
  `^## [0-9]` line — which the bracketed `## [1.9.0]` headings do **not** match, so the
  range runs to end-of-file — and CI then truncates it with `head -50`. Keep the
  headline summary in the first ~47 lines after the heading, or it never reaches the
  release page (fixing the workflow needs the same `workflow`-scoped PAT as below).
- The heavier gates — the `scripts/test-*.php` regression suite,
  `scripts/i18n-audit.py`, `shellcheck` and `node -c` on **every** JS file
  (including `mcp-server/`) — are listed in `AGENTS.md` and run **locally today**.
  Wiring them into CI needs a `workflow`-scoped PAT, so the patch waits in
  `todo/0001-ci-run-the-test-suite-lint-every-JS-file-shellcheck-.patch`.
- `build-kiosk.yml`, `build-health-bridge.yml`, `build-scale-gateway.yml`,
  `publish-docker.yml`, `security.yml`, dependabot.

## 11. Structural debt

- `api/index.php` and `assets/js/app.js` are monoliths; `docs/ARCHITECTURE.md`
  lists a planned split (`api/handlers/*`, `assets/js/features/*`). The router
  body still sits inside `if (!defined('CRON_MODE'))`, which confuses naive
  tooling (it reports `checkRateLimit()` as a 1300-line function).
- No static analysis configured yet (`phpstan.neon` / ESLint): both need a
  baseline pass before they can be enforced in CI.
- `ca.crt` at the repo root is a local, untracked public CA certificate offered
  for download in settings (never commit the private key).

