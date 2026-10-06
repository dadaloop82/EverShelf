# EverShelf — optimization, bugs & ideas backlog

> Living backlog (2026-10-06). Priorities are engineering judgement, not a promise
> schedule. A fuller local copy with session notes may live in git-ignored `todo/`.
> Free-feature ideas from `todo/IDEAS-2026-10-05-free-services.md` are referenced
> where they overlap — do not duplicate that catalog here.

## P0 — user-visible correctness

| ID | Area | Problem | Direction |
|----|------|---------|-----------|
| UI-REFRESH | Frontend | After many mutations (extend expiry, use, merge, favourite toggle, shopping add/remove, Bring sync) only a toast appears; dashboard, banner, inventory and product list stay stale until manual navigation. | Centralise post-mutation refresh: always `loadBannerAlerts()` + `refreshCurrentPage()` (or invalidate server-backed caches first). Audit `api(...).then` success paths in `app.js`. Add a lightweight guard test that greps for success handlers missing refresh on inventory/shopping actions. |
| BANNER-EXT | Dashboard / banner | Extending expiry from dashboard or inventory did not clear the expiry banner or alert cards (fixed partially in 1.11.2 — verify all entry points). | Same as UI-REFRESH; ensure `_bannerQueue` rebuild respects `setReviewConfirmed('exp_*')` and new `days_to_expiry`. |
| SHOP-GAPS | Smart shopping | Depleted / near-empty items still missed in edge cases (multi-location, family `shopping_name` mismatch, recently un-favourited). | Keep `scripts/test-shopping-guards.php` extended with real DB fixtures; log trace crumbs in `smartShopping()` behind `EverLog::debug`. |
| SHOP-MATCH | Shopping list | Loose token matching and “covered with 0 stock” left bought items on the list (fixed 2026-10-06: generic key matcher, prune on load). | Add regression tests; optional spesa “remove on any buy” setting. See `todo/AUDIT-2026-10-06.md`. |

## P1 — quality & performance

| ID | Area | Problem | Direction |
|----|------|---------|-----------|
| SCAN-PERF | Barcode | Camera path improved in 1.11.1; low light and damaged codes still slow. | Optional torch hint, expose decode timing in dev overlay, cache last-good GTIN per session. |
| PRODUCT-KIND | Titles | Dictionary + AI genre prefix still wrong on niche brands / multilingual packs. | Expand phrase maps from production misses; keep one dictionary for `kind` and `shopping_name`. |
| OFF-LIMIT | API | Open Food Facts rate limits under heavy scan bursts. | Respect `Retry-After`, stagger mirrors, surface “lookup busy” in UI. |
| SQLITE-LOCK | Backend | Long `products_apply_auto_rules` or maintenance pass vs concurrent writes. | WAL is on; consider busy timeout and queue maintenance to cron only. |
| I18N-DRIFT | Locales | Keys only in PHP or wrapped helpers skip `scripts/i18n-audit.py`. | More PHP tests like `test-seasonal-match.php`; bare `t('key')` in JS wrappers where feasible. |

## P2 — Android / CI

| ID | Area | Problem | Direction |
|----|------|---------|-----------|
| DEPS-PIN | Gradle | Dependabot reopened AGP 9 / core-ktx 1.19 / HC 1.2 — builds break until coordinated upgrade. | `.github/dependabot.yml` ignores widened; plan one branch: AGP 9 + compileSdk 37 + Kotlin 2.4 together. |
| KIOSK-OTA | Release | OTA commit on main can race auto-merge. | Soft-fail already; document manual `workflow_dispatch` after merge. |
| HEALTH-VER | Health Bridge | Version skew between app and server feature flags. | Settings health tab shows bridge semver + compatibility matrix. |

## P3 — features (ideas promoted from bugs / audits)

These overlap **IDEAS-2026-10-05** — implement in that doc’s order where noted.

| ID | Idea | Notes |
|----|------|-------|
| NTFY-A1 | ntfy.sh first-class notify channel | Cheapest “phone in pocket” win; see IDEAS A1. Partially shipped for expiry — extend to shopping/low-stock. |
| TELEGRAM-A2 | Telegram bot `/lista`, `/scadenze`, photo→scan | Household members who won’t install PWA; see IDEAS A2. |
| UNDO-UI | Undo after “use all” / accidental delete | User expectation from banner flows; ledger exists for some paths — unify. |
| RECIPE-SCRAP | Reuse tips for peels/cores (CHANGELOG Unreleased) | Optional step card; AI optional. |
| SEASONAL-UX | Seasonal review dismiss sticks but dashboard chip stale | Same refresh pattern as UI-REFRESH. |
| SPEND-MODE | Spesa session list vs server reconciliation after crash | Persist session id; resume partial trip. |
| FORK-PR | Reconcile community PRs (#234 reconciliation, #235 i18n) | Branches `fork-recon`, `fork-i18n` exist locally — rebase on develop, run test suite, merge or close with comment. |

## P4 — housekeeping

- README version badge and “What’s new” should track `index.html` / `CHANGELOG` (automate in CI or bump script).
- Root `TODO.md` is Italian scratch — point to this file for backlog; keep TODO for session checkboxes only.
- **Security:** rotate any PAT pasted in chat; never commit `.env`.
- Regenerate `docs/INDEX-*.md` after large `app.js` / `index.php` edits.

## Done recently (context)

See **[1.11.1]** and **[1.11.2]** in `CHANGELOG.md`: barcode speed, package collectives invariant, top-3 auto-favourites, smart shopping depleted rules, Dependabot/Android CI stabilisation, expiry extend UI refresh.
