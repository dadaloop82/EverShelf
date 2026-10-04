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
  `api/index.php` (line **847**). See `docs/INDEX-actions.md` for action → handler.
- Frontend is one file `assets/js/app.js` (~25.6k lines, 913 top-level functions).
  See `docs/INDEX-app-js.md` for function → line.
- Backend is one file `api/index.php` (~19.5k lines, 410 functions).
  See `docs/INDEX-api-index.md`.

## Golden rules (conventions already in the codebase)

- **Language**: code, comments and docs are **English**. UI strings live in
  `translations/*.json` (IT/EN/DE/FR/ES/ZH) and are used via `t('key')` in JS /
  `data-i18n="key"` in HTML. Never hardcode a user-facing string.
- **Errors**: never emit HTML before JSON. `api/bootstrap.php` disables
  `display_errors`. Return `{"success":false,"error":"..."}`; wrap handlers in the
  existing global try/catch and `EverLog`.
- **Logging**: use `EverLog::info/warn/error/debug/exception/request` (`api/logger.php`).
  Do **not** use `error_log`/`var_dump`.
- **Security**: every POST action goes through the CSRF guard and the API-token
  check. New action → add it to the relevant allow-lists in
  `api/lib/security.php` (`evershelfPublicActions`, `evershelfMutatingGetActions`,
  `evershelfDemoReadOnlyActions`, …) only when intended.
- **Versioning**: bump version in **4 places** together: `index.html`
  (`.header-version` + preloader), `manifest.json`, `sw.js` cache name, and add a
  `CHANGELOG.md` entry. `auto-merge-to-main` + `create-release` read the version
  from `index.html`.
- **Commits**: Conventional-ish prefixes seen in history: `feat:`, `fix:`,
  `release:`, `merge:`. Work on **`develop`**; CI auto-merges `develop → main`.
- **Assets cache-busting**: when you edit `app.js`/`style.css`, bump the `?v=`
  query in `index.html` and `sw.js` `CACHE` name.

## Commands that matter

```bash
# PHP syntax check (same as CI)
find api -name '*.php' -exec php -l {} \;

# JS syntax check (same as CI)
node -c assets/js/app.js

# Translation files must be valid JSON and key-complete vs it.json
python3 -c "import json; json.load(open('translations/it.json'))"

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
| Add/change an HTTP endpoint | `api/index.php` switch (line 847) + a handler `function` below |
| Auth / CORS / demo mode | `api/lib/security.php` |
| Config / `.env` read+write | `api/lib/env.php`, `saveSettings()` (~8082) |
| DB schema & migrations | `api/database.php` (`initializeDB`, `migrateDB`) |
| AI providers (Gemini/OpenAI/Llama) | `api/lib/ai_provider.php`, `callGemini()` (~8302) |
| Shopping logic | `smartShopping()` (~15989), `shopping_guards.php`, `bring_*` fns |
| Seasonal produce | `api/lib/seasonal.php` + `data/seasonal_produce_it.json` |
| Health / Fuel mode | `api/lib/health.php` |
| Frontend API wrapper | `api()` in `assets/js/app.js` line **5073** |
| i18n helper | `t()` line ~1195, `loadTranslations()` ~1206 |
| PWA service worker | `sw.js` |

## Anti-patterns to avoid (already present — do not copy)

- Building HTML with string concatenation + `innerHTML` and inline `onclick`
  with un-escaped data (see the review file). Prefer the existing `escapeHtml`.
- Reading whole giant files to find one function — use the indexes / `grep -n`.
- Writing to `.env` without escaping values (comment/quote/newline safe).

## See also

- `docs/CODEBASE-MAP.md` — deep architecture, DB schema, data files, action catalog.
- `docs/REVIEW-2026-10.md` — **bugs, risks, optimizations, cleanup and feature ideas**.
- `docs/ARCHITECTURE.md`, `docs/CORPORATE-UI.md`, `SECURITY.md`, `CONTRIBUTING.md`.
