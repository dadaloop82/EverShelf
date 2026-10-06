# AGENTS.md — EverShelf working guide

> Purpose: let an AI agent (or a new dev) be productive **without** loading the
> two giant files (`api/index.php`, `assets/js/app.js`) in full. Read this first,
> then jump to just the lines you need using the generated indexes.

## What this project is

Self-hosted **pantry manager** (PHP 8 + SQLite + vanilla-JS PWA). No framework,
no build step required at runtime (minify is optional). Everything is served from
the repo root: `index.html` (SPA) talks to `api/index.php` (single router).

Repo root = `/var/www/html/dispensa` (git remote: `dadaloop82/EverShelf`).

## The 30-second mental model

```
Browser (index.html + assets/js/app.js)
        │  fetch  api/index.php?action=<name>   (JSON, POST/GET)
        ▼
api/index.php   → switch($action) → handler fn → SQLite (data/evershelf.db)
        ▲                                         ▲
        │                                          │
  api/lib/*.php (env, security, ai, health, weather, seasonal…)
  api/cron_smart_shopping.php (CLI cron) ──────────┘
```

- **All** HTTP actions are dispatched by one `switch ($action)` in
  `api/index.php` (line **898**). See `docs/INDEX-actions.md` for action → handler.
- Frontend is one file `assets/js/app.js` (~26.2k lines, 933 top-level functions).
  See `docs/INDEX-app-js.md` for function → line.
- Backend is one file `api/index.php` (~19.5k lines, 408 functions).
  See `docs/INDEX-api-index.md`.

## Golden rules (conventions already in the codebase)

- **Language**: code, comments and docs are **English**. UI strings live in
  `translations/*.json` (IT/EN/DE/FR/ES/ZH) and are used via `t('key')` in JS /
  `data-i18n="key"` in HTML. Never hardcode a user-facing string.
- **Errors**: never emit HTML before JSON. `api/bootstrap.php` disables
  `display_errors`. Return `{"success":false,"error":"..."}`; wrap handlers in the
  existing global try/catch and `EverLog`.
- **Logging**: use `EverLog::info/warn/error/debug/exception/request` (`api/logger.php`).
  Do **not** use `error_log`/`var_dump`. Put the human-readable words in the message
  and the stable machine key in the context:
  `EverLog::warn('API pairing code', ['event' => 'api_pairing_code', …])`. The pairing
  code must stay greppable with the command the docs advertise
  (`grep -i "pairing code" logs/evershelf_*.log`) — a snake_case-only message made
  that command silently return nothing.
- **Product titles lead with their genre.** `products.name` is user data and the app
  now owns the first word of it: a new/renamed product goes through
  `mergeIncomingProductFields()` → `productKindApply()` (`api/lib/product_kind.php`),
  which prefixes the genre the label usually omits (`"Fiori di latte"` →
  `"Yogurt Fiori di latte"`) and stores it in `products.kind`. Cheap first — the
  curated dictionary (`evershelfShoppingPhraseMap`/`...KeywordMap`, the *same*
  dictionaries `computeShoppingName()` uses) — and the AI only when the genre word
  does not lead the name, i.e. when the dictionary cannot tell a yoghurt from a
  cheese. One paid word per signature (`data/product_kind_cache.json`), never two
  prefixes on the same title.
- **Automatic favourites respect the user.** `api/lib/auto_favorite.php` promotes the
  products consumed `AUTO_FAVORITE_MIN_USES` times in `AUTO_FAVORITE_WINDOW_DAYS`;
  `products.favorite_user_override` records a manual unstar so the rule never re-adds
  it. Favourites are only ever added, never removed by the rule.
- **Security**: every POST action goes through the CSRF guard and the API-token
  check. New action → add it to the relevant allow-lists in
  `api/lib/security.php` (`evershelfPublicActions`, `evershelfMutatingGetActions`,
  `evershelfDemoReadOnlyActions`, …) only when intended.
  **Never authorise on client-supplied headers** (`Origin`, `Referer`,
  `Sec-Fetch-Site` are forgeable). `app_bootstrap` only hands out `API_TOKEN` after
  the one-time pairing code (`api/lib/pairing.php`) is presented; see `SECURITY.md`.
- **Auth overlays are drawn during startup, above everything.** Pairing is requested
  while `_initApp()` is still running, and `_initApp()` returns early when it cannot
  authenticate — so `#app-preloader` (z-index 200000) is never removed. The auth
  overlays use `EVERSHELF_AUTH_OVERLAY_Z` (`assets/js/core/auth.js`, 400000), which
  must stay above `#app-preloader` **and** `#network-error-overlay` (300000). At the
  `.modal-overlay` default of 200 the dialog is invisible behind the splash and the
  app dead-ends on "API token required".
- **Versioning**: bump version in **4 places** together with `scripts/bump-version.sh`
  (`index.html` badges, `manifest.json`, `sw.js` cache name, `app.js` i18n token),
  and add a `CHANGELOG.md` entry. `auto-merge-to-main` + `create-release` read the
  version from `index.html`.
- **i18n**: never hardcode user-facing strings. Use `t('key')` (JS) /
  `data-i18n*` (HTML) and add the key to **all** locales (it/en/de/fr/es/zh).
  `scripts/i18n-audit.py` (run in CI) fails on used-but-missing keys; give `tl()`
  fallbacks in English. Optional UI strings from PHP should return a `hint_key`
  (+ `hint_args`), not a literal — and so should a whole payload (`tip_key`,
  `reasons[]`): the client resolves it with `t()`. The audit's JS regex is
  literally `\bt\(\s*['"]…`, so a key wrapped in a helper (the local
  `tr(key, fallback)` in `_localizeSmartReason`) or emitted **only** by PHP is
  invisible to it: write the resolution as a bare `t('key')` *and* lock the set
  with a PHP test — `scripts/test-seasonal-match.php` asserts every
  `shopping.seasonal_tip_*` key PHP can return exists in all six locales.
- **Secrets**: never commit keystores, `.env`, or signing passwords. Android
  builds read `keystore.properties`/env; CI reads GitHub Secrets (see `SECURITY.md`).
- **Commits**: Conventional-ish prefixes seen in history: `feat:`, `fix:`,
  `release:`, `merge:`. Work on **`develop`**; CI auto-merges `develop → main`.
- **Assets cache-busting**: when you edit `app.js`/`style.css`, bump the `?v=`
  query in `index.html` and `sw.js` `CACHE` name.

## Commands that matter

```bash
# PHP syntax check (same as CI)
find api -name '*.php' -exec php -l {} \;

# JS syntax check (CI runs `node -c` on app.js; the rest are local-only;
# mcp-server is ESM → --check, not -c)
node -c assets/js/app.js && node -c sw.js
for f in mcp-server/src/*.js; do node --check "$f"; done

# PHP regression tests — local-only until the CI patch lands (see the note at
# the end of this file), so run them yourself before pushing
php scripts/test-shopping-guards.php
php scripts/test-internal-shopping-cleanup.php
php scripts/test-notify.php
php scripts/test-healthcheck.php
php scripts/test-settings-nav.php      # settings sections ↔ tabs ↔ panels ↔ locales
php scripts/test-setup-assistant.php    # SETTINGS_CHECKLIST ↔ tabs ↔ wizard steps
php scripts/test-i18n-icons.php         # no label prints its icon twice (all locales)
php scripts/test-html-in-text.php       # no HTML reaches a text-only surface (screensaver facts)
php scripts/test-dashboard-panels.php   # dashboard rotation: phases ↔ sections ↔ bar fills, no orphan flags
php scripts/test-product-kind-prefix.php # genre leading every article title (dictionary → cache → AI, no double prefix)
php scripts/test-auto-favorite.php      # used-often products become favourites; a manual unstar always wins

# Translation files must be valid JSON
python3 -c "import json; json.load(open('translations/it.json'))"

# i18n audit: keys used in code must exist in every locale (exits 1 on gaps)
python3 scripts/i18n-audit.py

# i18n value audit: keys whose value is still English (report; --strict to fail)
python3 scripts/i18n-value-audit.py

# Shell scripts (local-only for now, like the test suite)
shellcheck -S warning backup.sh scripts/*.sh

# Bump the version in the 4 touchpoints at once (+ cache-busting stamp)
scripts/bump-version.sh 1.9.2

# Regenerate the cheap code indexes (do this after big edits)
bash scripts/gen-code-index.sh

# Run locally without Docker (needs the php extensions from the Dockerfile)
php -S 127.0.0.1:8080            # then open http://127.0.0.1:8080

# Optional minify (not required at runtime)
npm run build
```

## Where things live (quick map)

| Want to… | Go to |
|---|---|
| Add/change an HTTP endpoint | `api/index.php` switch (line 898) + a handler `function` below |
| Auth / CORS / demo mode | `api/lib/security.php` |
| Config / `.env` read+write | `api/lib/env.php`, `saveSettings()` (~8082) |
| DB schema & migrations | `api/database.php` (`initializeDB`, `migrateDB`) |
| AI providers (Gemini/OpenAI/Llama) | `api/lib/ai_provider.php`, `callGemini()` (~8302) |
| Shopping logic | `smartShopping()` (~16000), `shopping_guards.php`, `shopping_sync.php` (shared Bring!/internal sync), `bring_*` fns |
| Genre in the article title | `api/lib/product_kind.php` + `mergeIncomingProductFields()` (the single title choke point) + `products.kind`; dictionary shared with `computeShoppingName()` — guard test `scripts/test-product-kind-prefix.php` |
| Automatic favourites | `api/lib/auto_favorite.php` (`maybeAutoFavorite()` on every `inventory_use`), `products.favorite_user_override`, maintenance action `products_apply_auto_rules` — guard test `scripts/test-auto-favorite.php` |
| Cron watchdog / notifications | `api/lib/healthcheck.php`, `api/lib/notify.php`, `cron_*.php` |
| `.env` bootstrap / pairing | `api/lib/env.php`, `api/lib/pairing.php`, `app_bootstrap` in `api/index.php` |
| Seasonal produce | `api/lib/seasonal.php` + `data/seasonal_produce_it.json` |
| Health / Fuel mode | `api/lib/health.php` |
| Frontend API wrapper | `api()` in `assets/js/app.js` line **5384** |
| i18n helper | `t()` line ~1198, `loadTranslations()` ~1209 |
| Settings page (sections, accordion, checklist) | `SETTINGS_GROUPS` + `switchSettingsGroup()`, `SETTINGS_CHECKLIST` (~25820), `_initSettingsAccordions()` (~5222), `_openSettingsCardFor()` (~5288), `_checklistNewsItems()` (~25907) |
| Dashboard panels & limits | `DASHBOARD_*_MAX` (~6623), `_dashboardAlertCap()`, `_staleRotationPick()`, `_INSIGHT_PHASES` (~6529) / `_applyInsightPhase()` (~6544); bars are drawn at 0% and filled from `data-target` on reveal — guard test `scripts/test-dashboard-panels.php` |
| Emoji-before-translated-label bug | `iconLabel()` / `_stripLeadingEmoji()`; guard test `scripts/test-i18n-icons.php` |
| HTML printed as text (screensaver fact, alert banner, chooser modal) | `formatQuantity()` returns `<span class="conf-size-info">`; text-only callers use `_formatQtyPlain()` / `stripHtml()`; guard test `scripts/test-html-in-text.php` |
| PWA service worker | `sw.js` |

## Anti-patterns to avoid (already present — do not copy)

- Building HTML with string concatenation + `innerHTML` and inline `onclick`
  with un-escaped data. Prefer the existing `escapeHtml`.
- Reading whole giant files to find one function — use the indexes / `grep -n`.
- Writing to `.env` without escaping values (comment/quote/newline safe).

## See also

- `docs/CODEBASE-MAP.md` — deep architecture, DB schema, data files, action catalog.
- `docs/ARCHITECTURE.md`, `docs/CORPORATE-UI.md`, `SECURITY.md`, `CONTRIBUTING.md`.

> Audit / review notes are **internal working documents**. They are kept in the
> git-ignored `todo/` folder at the repo root and are intentionally never pushed:
> this repository is public and everything committed must be in English.
>
> This includes the CI improvements that need a `workflow`-scoped PAT to push:
> they live on the local branch `ci-tests-lint` and as
> `todo/0001-ci-run-the-test-suite-lint-every-JS-file-shellcheck-.patch`.
> Apply with `git am todo/0001-ci-*.patch` once a PAT with `workflow` scope is set.
