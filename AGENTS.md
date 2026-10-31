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
  prefixes on the same title — neither when the genre is already there in another form
  (`Tarallini`/`Taralli`, `Pera Italiana`/`Pere`, `Kaffee`/`Caffè`, `Italia
  Zuccheri`/`Zucchero`) nor when the title already opens with the genre stored in
  `products.kind`: a maintenance pass must replay without drifting a single title
  (`productKindNameAlreadyHasKind()`, `productKindStartsWithWord()`). The same choke
  point also owns the *case* of the title: `productTitleCapitalize()` raises the first
  letter (`"latte fresco"` → `"Latte fresco"`) and nothing else — the rest of the case
  is kept (`NUTELLA` stays `NUTELLA`, while a brand spelling such as `iPhone` becomes
  `IPhone`: the rule is mechanical so it cannot disagree with itself) and a title opening
  with a digit or an emoji is left alone. It is applied in `mergeIncomingProductFields()`
  **outside** the genre guard, so `PRODUCT_KIND_PREFIX=false` switches off the prefix
  only, never the spelling, and it is idempotent, so the maintenance pass can replay it.
  `normalizeProductName()` (api/index.php) is **not** the storage normalizer: it
  lowercases a *copy* to compare two products in duplicate detection. One vocabulary,
  two consumers — `products.kind` (genre embedded in the title: precise, may legitimately
  stay empty) and `products.shopping_name` (the buyable word of the list/Bring!: historical,
  may be a raw token such as "Potato") are **two columns on purpose**, both reading that one
  dictionary through `productKindFromDictionary()`; never merge them and never add a second
  dictionary, or the two will disagree again.
  The same choke point also owns the **number**: the stored title is the *singular*
  (`productKindSingularizeName()`: `"Uova medie"` → `"Uovo medio"` — only the genre leading
  the title moves, the adjective run right after it follows, a variety name never does, and
  a title opening with a quantity is left alone), while the *plural* is derived where a
  count is shown, and only for units that count pieces (`productKindUnitCountsPieces()`,
  `productNameForPieces()`): the pantry read hands the client a `display_name` (`"3 Uova
  medie"`), so `500 g Pasta` stays a mass and never becomes `500 g Paste`. The two are one
  round trip (`singular → plural → singular`), which is what lets the maintenance pass
  replay. `products.shopping_name` is deliberately **not** rewritten by that pass: it is
  the buyable word of the list, and the shopping list buys `Grissini`, not one `Grissino`.
- **Automatic favourites respect the user.** `api/lib/auto_favorite.php` promotes only
  the absolute top `AUTO_FAVORITE_TOP_N` (default 3) products by consumption that also
  clear `AUTO_FAVORITE_MIN_USES` inside `AUTO_FAVORITE_WINDOW_DAYS`;
  `products.favorite_user_override` records a manual unstar so the rule never re-adds
  it. Favourites are only ever added, never removed by the rule.
- **Security**: every POST action goes through the CSRF guard and the API-token
  check. New action → add it to the relevant allow-lists in
  `api/lib/security.php` (`evershelfPublicActions`, `evershelfMutatingGetActions`,
  `evershelfDemoReadOnlyActions`, …) only when intended.
  **Never authorise on client-supplied headers** (`Origin`, `Referer`,
  `Sec-Fetch-Site` are forgeable).   `app_bootstrap` only hands out `API_TOKEN` after
  the one-time pairing code (`api/lib/pairing.php`) is presented; a paired device
  shows the live code under Settings → System → Security (`pairing_code` action);
  see `SECURITY.md`.
- **Auth overlays are drawn during startup, above everything.** Pairing is requested
  while `_initApp()` is still running, and `_initApp()` returns early when it cannot
  authenticate — so `#app-preloader` (z-index 200000) is never removed. The auth
  overlays use `EVERSHELF_AUTH_OVERLAY_Z` (`assets/js/core/auth.js`, 400000), which
  must stay above `#app-preloader` **and** `#network-error-overlay` (300000). At the
  `.modal-overlay` default of 200 the dialog is invisible behind the splash and the
  app dead-ends on "API token required".
- **The splash must not lie about the boot.** The rail icons are driven by
  `_preloaderStage(stage, state)` and the stage→check ownership in
  `_PRELOADER_STAGE_OF_CHECK`: a health check nobody owns leaves its icon blinking
  forever, and a stage whose `startup.stage_*` string is missing from any of the six
  locales prints its raw key. New check → give it a stage; new stage → add the markup row
  *and* the six translations. Keep the version chip's `id="preloader-version">vX.Y.Z`
 shape too: `scripts/bump-version.sh` rewrites it (
  locks all of it).
- **Versioning**: bump version with `scripts/bump-version.sh X.Y.Z` in **5 places**
  (`index.html` badges, `manifest.json`, `sw.js` cache name, `app.js` i18n token,
  `README.md` version badge), add a `CHANGELOG.md` entry and prepend the release to
  the README “What’s new” line (prose, so it stays manual).
  `auto-merge-to-main` + `create-release` read the version from `index.html`.
- **i18n**: never hardcode user-facing strings. Use `t('key')` (JS) /
  `data-i18n*` (HTML) and add the key to **all** locales (it/en/de/fr/es/zh).
  `scripts/i18n-audit.py` (run in CI) fails on used-but-missing keys; give `tl()`
  fallbacks in English. Optional UI strings from PHP should return a `hint_key`
  (+ `hint_args`), not a literal — and so should a whole payload (`tip_key`,
  `reasons[]`): the client resolves it with `t()`. The audit's JS regex is
  literally `\bt\(\s*['"]…`, so a key wrapped in a helper (the local
  `tr(key, fallback)` in `_localizeSmartReason`) or emitted **only** by PHP is
  invisible to it: write the resolution as a bare `t('key')` *and* lock the set
 asserts every
  `shopping.seasonal_tip_*` key PHP can return exists in all six locales.
- **Secrets**: never commit keystores, `.env`, or signing passwords. Android
  builds read `keystore.properties`/env; CI reads GitHub Secrets (see `SECURITY.md`).
- **Commits**: Conventional-ish prefixes seen in history: `feat:`, `fix:`,
  `release:`, `merge:`. Work on **`develop`**; CI auto-merges `develop → main`.
- **Assets cache-busting**: when you edit `app.js`/`style.css`, bump the `?v=`
  query in `index.html` and the `sw.js` `CACHE` name (it carries the version *and*
  the stamp letter, e.g. `evershelf-v1.11.13b`). `scripts/bump-version.sh` does both
  and, inside the same day, continues the stamp letter (`20261014a` → `20261014b`)
  instead of reusing it — a repeated `<today>a` leaves the browser on the old CSS.

## 4b. The vocabulary is DATA, the code is language-neutral

Product names, genres, brands, plural/singular forms, stop words, the shopping
dictionaries and the "what does this title mean" rules live in **`api/vocab/<lang>.json`**.
`api/lib/vocabulary.php` is the only algorithm that reads them (tokenizer, word/stem/phrase
match, number forms) and it contains **no product word and no rule about Italian**. The
browser runs the same engine (`assets/js/core/vocab.js`) over
**`assets/js/vocab.generated.js`**, generated from the JSON by
`php scripts/gen-vocab-js.php` (committed, offline-safe).
`php scripts/gen-food-facts-js.php` regenerates the offline screensaver facts the same way.

The same file also carries the **SHELFY capture lexicon** (`"capture"`: intents, spelled
numbers, units with their multiplier, fractions, "all of it", stop words) and the
**language metadata** (`"locale"`: the language's own name, its collation tag, its
recogniser tag). `_SUPPORTED_LANGS`, `_collationLocale()`, the `?lang=` deep link, the
date/number formatting, the TTS voice and the capture parser all read those two blocks —
no language name and no spoken synonym belongs in `app.js`.

Adding a language = dropping `api/vocab/<lang>.json` and regenerating. Adding a word =
editing JSON, never a function.

The offline screensaver sentences are text as well and follow the same rule:
**`data/food_facts_fallback.json`** → `assets/js/food_facts.generated.js`
(`php scripts/gen-food-facts-js.php`, in the service-worker shell).

 enforces it: the generated files must match their JSON, a
**ratchet** counts the vocabulary words still left in the logic files — `product_kind.php`,
`vocabulary.php` and `core/vocab.js` are at **0**, the rest may only go *down* — and the
browser engine must expose **every** top-level function it declares: a lost closing brace
in `core/vocab.js` once left nine of them nested (the file still parsed, so nothing
complained, and the piece-produce/prepared-salad/plural paths called `undefined`). A new
product name in `api/index.php`, `api/database.php` or `app.js` fails the build.

Remaining debt (next batch, same rule): the expiry heuristics in `api/database.php` /
`app.js` and the recipe ingredient groups.

## 4c. Validate before saying "done"

```bash
# PHP syntax check (same as CI)
find api -name '*.php' -exec php -l {} \;

# JS syntax check (CI runs `node -c` on app.js; the rest are local-only;
# mcp-server is ESM → --check, not -c)
node -c assets/js/app.js && node -c sw.js
for f in mcp-server/src/*.js; do node --check "$f"; done

# Android dependency check (build-only, debug APK, no secrets, nothing published).
# It runs on pull requests touching either Android project; dispatch it by hand to
# test a dependency bump on a branch before it reaches main:
#   gh workflow run android-deps-check.yml --ref <branch>

# Translation files must be valid JSON
python3 -c "import json; json.load(open('translations/it.json'))"

# i18n audit: keys used in code must exist in every locale (exits 1 on gaps)
python3 scripts/i18n-audit.py

# i18n value audit: keys whose value is still English (report; --strict to fail)
python3 scripts/i18n-value-audit.py

# Shell scripts
shellcheck -S warning backup.sh scripts/*.sh

# Import the Italian medicine catalogue (AIFA open data; the scan never calls a medicine API).
# Optional: fill the gaps from an ONLINE provider, off unless MED_LOOKUP_PROVIDER is set.
# It is consulted only for a code nothing local knows, and the answer is cached in the table.
# MED_LOOKUP_PROVIDER=medifontis MED_LOOKUP_URL=... MED_LOOKUP_KEY=...  (see .env.example)
# Run it ONCE, from a shell, after downloading the AIFA file (CSV or XML):
php scripts/import-medicines.php data/aifa_farmaci.csv --dry-run   # parse + header map, writes nothing
php scripts/import-medicines.php data/aifa_farmaci.csv             # import / refresh (AIC is the key)

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
| Config / `.env` read+write | `api/lib/env.php` (`env()`, `loadEnvOverrides()`: a fallback stored because `.env` was read-only carries the value it was saved against, so a later hand edit of the file wins and the stale entry is dropped — `settings_override_stale`), `saveSettings()` (~9293) |
| DB schema & migrations | `api/database.php` (`initializeDB`, `migrateDB`) |
| AI providers (Gemini/OpenAI/Llama) | `api/lib/ai_provider.php`, `callGemini()` (~8302) |
| Shopping logic | `smartShopping()` (~16000), `shopping_guards.php`, `shopping_sync.php` (shared Bring!/internal sync), `bring_*` fns |
| “Non lo compro” must survive the cron | `shopping_remove` with `purchased: false` stores a dated dismiss (`app_settings.shopping_dismissed`, key → YYYY-MM-DD, `SHOPPING_DISMISS_DAYS` default 14 or `dismiss_days` 1–365). Both auto-add paths (`internalShoppingAutoAddCritical`, `bringAutoAddCritical`) skip it and never clear it; `purchased: true` or an `inventory_add` that covers the need clears it (`shoppingClearDismissed*()`) |
| A shopping row is a FAMILY row | The payload carries `family_qty` (usable stock of the whole buyable generic, in the item's unit): the UI shows that, never the representative product's own quantity, or the row says “Esaurito” next to “Hai già 235 g”. The pantry hint (`_shoppingFamilyInventoryRows()`) counts the same `shopping_name` family only. Coverage: one whole pack/piece (`conf`/`pz`) or half the usual buy (`g`/`ml`) — `$coverageNeeded` in `smartShopping()` |
| A finished product must reach the list | `smartShopping()` family coverage uses **usable** stock only: fresh, above `EVERSHELF_TRACE_QTY` (single source of `productQtyThreshold()`), expanded to the same base family with `evershelfBaseQty()` and worth ≥ 1 pack (or ½ the usual buy for g/ml) — `evershelfFamilyUsableStockQty()` is the DB form used by the cleanup and the backfill; `bringAddDepletedProduct()` (explicit "usa tutto") always adds, stamped `🛒 Esaurito` |
| Recipe archive (chat / generate) | `recipesArchiveUpsert()` + `recipeArchiveContentHash()` in `api/index.php` / `api/database.php`; `recipes.content_hash` prevents clones; `useRecipeIngredient()` / scale X÷Y UI in `app.js` |
| Genre in the article title | `api/lib/product_kind.php` + `mergeIncomingProductFields()` (the single title choke point) + `products.kind`; dictionary shared with `computeShoppingName()` |
| Titles with HTML entities | A catalogue escapes a name once and an old import escaped it twice, which is what printed `Marmellata di &quot;Limone…&quot;` in the pantry and in the history. `productTitleDecodeEntities()` runs inside `productTitleCapitalize()` (the single title choke point) and decodes until the string stops changing (4 passes max), the client's `decodeHtmlEntities()` does the same, and `migrateDB` repairs the rows already stored escaped (`migration_decode_title_entities_v1`, products + transaction notes, logged as `titles_entities_decoded`). Never store an escaped title and never `escapeHtml()` twice. |
| Singular title ↔ plural pantry | `productKindSingularizeName()` (stored title), `productNameForPieces()` + `display_name` in `listInventory()`, `productKindUnitCountsPieces()` |
| Automatic favourites | `api/lib/auto_favorite.php` (`maybeAutoFavorite()` on every `inventory_use`), `products.favorite_user_override`, maintenance action `products_apply_auto_rules` |
| Correct a scanned title/brand | `showAddForm()` → `_renderAddProductPreview()` / `_commitAddProductRename()` in app.js (`name_user_set` locks the user name) + the AI card `_showAiMatchChoices()` |
| Add to pantry needs a catalog id | `submitAdd()` → `_ensureAddProductRow()` (creates the row with `name_user_set`), `_apiErrorText()` for `error_key` answers, `addToInventory()` in api/index.php |
| Duplicate products — never merge two different articles | `productNamesSameArticle()` (+ `productNameStem()`): the same name at another number is one row, but a shared **substring is not** ("Pomodoro" ⊄ "Passata di pomodoro"), and the loose head rule needs a barcode. A **loose vegetable never reuses/merges with the pantry's tin of the same word** (`productRowClashesWithLooseProduce()`: `isSoldByPieceProduct()` + the row's category) — used by `collectDuplicateProductIds()` (which DELETES a row) and `findDuplicateProductId()` — the AI-name ranking is `aiProductNameRelevance()` / `aiProductSuggestRank()`, the client drops candidates of another nature with `_aiMatchKeepSameNature()` |
| Which category does a name belong to | `guessCategoryFromName` (app.js) and `guessCategoryFromNamePHP` (api/index.php) are **one vocabulary written twice**: the PHP one is a PORT of the JS one (same order, same patterns — port every change to both). A bare "Pomodoro"/"Pomodorini"/"Carote" is produce (`verdura`/`frutta`, unit pieces), only the preparations are `conserve` ("Passata/Pelati/Sugo/Polpa di pomodoro"); stems, no trailing `\b` (`pomodor`, `carot`, `mel[ae]`), and accented words close with `(?![a-z])` because the JS `\b` is ASCII-only while PCRE's is not ("Caffè"). An 80-name probe list covering the whole grocery is locked by, which runs the app's own JS in node against the PHP guesser |
| Minimum quantity of a NEW pantry row | `inventoryMinAddQuantity()` (g/ml 20, kg/l 0.02, pz 1, conf 0.25) enforced in `addToInventory()` (`error_key error.quantity_below_min`), mirrored by `_ADD_MIN_QTY_BY_UNIT` / `_addMinQtyForUnit()` in app.js (`updateAddQtyStep()`, `onAddUnitChange()`, `submitAdd()`); tomatoes are piece produce in `isSoldByPieceProduct()` / `suggestCategoryUnitQty()` |
| Recorded spend (dashboard) | `spendAdd()` / `getSpendStats()` in api/index.php + `data/shopping_spend.json` (append-only, one amount per spesa session, asked when the session starts); every write is logged (`event api_spend_add`, with the resulting month total) because the ledger has no correction UI |
| "Spesa stimata" of the list (one figure only) | `_computeAllShoppingPrices()` is the only producer: the server-side resolution (`_resolveShoppingPriceItem()` from the smart cache) wins and the caller's quantity is only the fallback; one key `_shoppingListPriceHash()` and `data/shopping_total_cache.json` holds one bucket per key (`_pruneShoppingTotalEntries()`) |
| Cron watchdog / notifications | `api/lib/healthcheck.php`, `api/lib/notify.php`, `cron_*.php` |
| `.env` bootstrap / pairing | `api/lib/env.php`, `api/lib/pairing.php`, `app_bootstrap` in `api/index.php` |
| Seasonal produce | `api/lib/seasonal.php` + `data/seasonal_produce_it.json` |
| Health / Fuel mode | `api/lib/health.php` |
| Frontend API wrapper | `api()` in `assets/js/app.js` line **5384** |
| i18n helper | `t()` (~line 1471), `loadTranslations()` (~1482), `_urlLangOverride()` (line 1417: `?lang=` wins over localStorage); anything PHP hands to the UI is a key (`error_key`, `hint_key`, `urgency_label_key`) |
| Rate limits (429) | `checkRateLimit()` in `api/index.php` (line 111) + `data/rate_limits/`; the answer carries `error_key: error.rate_limited` + `retry_after` |
| The product modal (one hierarchy) | `showItemDetail` renders it: a stepper (`_itemDetailQtyInit` / `_itemDetailStep` / `_itemDetailQtyLabel`) feeds `_itemDetailConsume` → `inventory_use` with the chosen quantity, then **one** primary (solid emerald), **one** secondary (emerald tint) and neutral support buttons (recipe, discard, extend expiry); barcode and timestamps live in the collapsed `<details class="item-detail-more">`, never in the middle of the card. The row itself carries a quick "Usa" + the ⋮ that opens this modal (`_rowMenuButtons`): extending an expiry is rare and must not own the row. The quick "Usa" opens THIS sheet (`quickUse`), so the sheet must carry what the old USE page had: the **¼ · ½ · ¾ · 1** chips (`_itemDetailFractionOptions` / `_itemDetailFractionsHtml` / `_itemDetailSetQty`, neutral chips, hidden when only one value is reachable). The anomaly banner's primary is `alignBannerAnomaly` (writes the ledger quantity) — never a nameless "Giusta" — and **no banner hides its actions behind a ⋮**: every entry renders them inline (`.alert-banner-actions` wraps) and every entry carries a Modifica, |
| Capture (voice) — **SHELFY** | The assistant has a name, used in every string (caps), and **no voice of its own**: TTS is the recipes' feature (`_speakBrowser`), the capture never speaks. **It never navigates either**: `_shelfyRunConsume()` closes the sheet and shows the floating `.shelfy-confirm` card (`_shelfyOfferConsume()`: the avatar, “Registro {qty} di {name}”, an emerald bar draining right→left for 10 s, Modifica / ✕ Annulla). The bar emptying writes `inventory_use` (`_shelfyApplyConsume()`); **Modifica** stops the clock and opens the compact product sheet at that quantity (`showItemDetail(invId, productId, qty)`); “ho finito …” (use-all) opens that same sheet, where the slide-to-confirm stays the user's (`_shelfyOpenAdd()` never writes either). The legacy full-page `#page-use` is reached only by the guided flows that still drive its fields — **no capture path goes there**, which is what removed the ← Indietro header, the purple recipe button and “Tutte una parte” from the voice flow. Every countdown shows its seconds: `_shelfyArmButton()` paints `.shelfy-armed` (the mint fill) plus `.shelfy-arm-count` (the number), and any pointerdown/key cancels it so the tap is the user's; a promise of “10 seconds” with nothing moving reads as a frozen app. A second intent, **shop**, is listed *before* `add` in `vocabCaptureLexicon()`: “aggiungi le uova alla spesa” is a shopping line (`_shelfyAddShopping()` → `shopping_add`), “aggiungi il latte” is still a pantry add. `_openCaptureSheet()` shows the presentation once per device (`SHELFY_INTRO_KEY`, reopenable from `#shelfy-help-btn`), then `_captureStartVoice()` (ONE session per tap, `continuous = true`; the sentence is handed over **only** when the session our own silence deadline stopped — `_CAPTURE_SILENCE_MS` 2.5 s, flagged by `_captureVoice.stopRequested` — while a session the **browser** ended mid-sentence is **resumed** instead of submitted (`_CAPTURE_RESTARTS_MAX`, bounded), because submitting that one is what cut "Ho usato 2 arance" at "Ho usato 2" and "Aggiungi il latte" at "Aggiungi il"; the transcript is REBUILT from `ev.results` on every event and joined **per word, ignoring case** (`_captureJoinText`: Chrome re-delivers finished results, Chrome Android restarts the list per phrase with the phrase already cumulative, and the interim is capitalised while the final is not — appending printed "ho ho ho usato ho usto ho usato una", "ho consumato ho consumato ho consumato 250 …" and "Ho mangiato ho mangiato una mela", and comparing *characters* swallowed the last word: "aggiungi il" + "latte" → "aggiungi il atte"), interim included, finals + the interim text the browser never finalised, `_captureSpeechLang()` = device language → UI language). `_captureSubmit()` routes only through `_captureIsActionable()`, and `_captureNotUnderstood()` is the one ending for a sentence it cannot act on: it hides the "Ho capito" chips, says "non ho capito" and keeps the sheet open — **no capture path ever opens the "new product" form** (`startManualEntry()`: creating an article is the user's decision, never the end of a wrong parse), and the sentence it heard is the biggest text in the sheet (`.capture-transcript`, 1.06 rem, semibold, tinted block). `_captureParse()` reads fractions and eaten/drunk/used verbs; `_captureScoreName()` ranks by POSITION (the word leading the name is the product, a word buried in a longer name is a derived article → 0.55, no confident match). Ambiguity shows four candidates and the closest drains for **10 s** (`_shelfyArmButton`, any pointerdown cancels). **Its lexicon is DATA**: intent words, spelled numbers, units (with their multiplier: `etti` → g ×100), fractions, "all of it" and stop words live in `api/vocab/<lang>.json` → `"capture"` (the recogniser tag is in the same file's `"locale"`), reach the browser through `assets/js/vocab.generated.js` and are merged by `vocabCaptureLexicon()` in `assets/js/core/vocab.js` (current language first, all six shipped) — an Italian sentence works on an English UI; `_captureSpeechLang()` takes its tag from the data, never from a hardcoded map. Closing always hides the sheet **before** the cleanup (`_captureVoiceStop()`), and the test fails if a `_capture*()` call has no definition |
| SHELFY's avatar | `assets/img/shelfy/shelfy-avatar.png` (512, transparent), `-192` and `-96` — generated once from the artwork by removing the flat white sheet with a **flood fill from the border** (a threshold on the whole image would eat the robot's white panels; the fill only reaches the paper that touches the edge), then cropped, squared and resized with LANCZOS. It is in the `sw.js` shell and painted in five places: the **centre of the bottom nav** (`#nav-capture-btn`, `clamp(40px, 10.6vw, 54px)` inside the emerald circle), the capture sheet head (`.capture-head-avatar`, 26 px, *outside* the `data-i18n` h3 so `translatePage()` cannot wipe it), the intro hero (`.shelfy-hero-avatar`, 104 px, floating over the breathing ring — the 👋 wave is gone because the drawing already waves), the 10 s confirm card and the **foot of the splash** (`.preloader-shelfy`, 56 px under `.preloader-powered`, on its own breathing ring; decorative and `aria-hidden`, never a boot stage, and 44 px under `max-height: 620px`). Never replace it with a food emoji |
| Voice / TTS (one engine) | `_speakBrowser(text, opts)` is the only local voice: cooking steps (`speakCookingStep()`) and SHELFY share it; `_pickTtsVoice(voices, wantLang, selectedName)` is the pure rule (the Settings voice when it speaks that language → an offline voice of that language → any offline → the first). Never hardcode a language, never build a second `SpeechSynthesisUtterance` outside it |
| Dialogs: Esc + focus trap | `_closeTopOverlay()` + `_overlayFocusables()` (one capture-phase `keydown` for every `.modal-overlay`): Esc closes the dialog on top through `_OVERLAY_CLOSERS` (so state is cleaned — `_closeCaptureSheet` stops the microphone), Tab/Shift+Tab cycle inside it, and the focus returns to `_lastFocusedOutside`. The first-run setup wizard is never closed by Esc. Visible = `getClientRects()` (a fixed overlay has no offsetParent) |
| Alert rows open on tap (phone too) | `_bindAlertItemsHoldOpen()` → `_bindRowHoldOpen()`: a tap opens the sheet, a hold also works, a pointermove > 12 px cancels, a tap on a child button never opens, `tapToOpen: false` for lists where a tap means something else; all four lists incl. `stale-list` are bound |
| Expiry verdict (safety pill) | `getExpiredSafety()` in `app.js`: the expiry day itself is "Controlla" (server query is date-only), 1–2 days past are "Controlla" everywhere, liquids say "Non bere" (status.dont_drink / status.tip_drink_*) |
| Mobile layout (list rows) | `assets/css/mobile.css` (layer 4, loaded **last**, geometry only): at ≤640 px the text owns the row and badges/quantities wrap under it, so an alert name is not cut at 107 px and a shopping title does not collapse to 0 px (43 lines, one letter each). Do not put colours there — themes stay in charge|
| Tablet / kiosk layout | `assets/css/tablet.css` is NOT keyed on `min-width`: a desktop monitor is ≥768 px and was getting bold truncated two-column rows, while many Android kiosk WebViews report a CSS width under 768 and saw no change at all. It activates on `html.is-kiosk` (set by `_injectKioskOverlay()` and `_markDeviceChrome()`) or `html.is-touch-tablet` (`(hover: none) and (pointer: coarse)` **and** `max(innerWidth, screen.width) >= 600`), which `_markDeviceChrome()` re-evaluates on resize. mobile.css stays last. |
| Dates / numbers in the UI | `formatDate()` / `formatDateTime()` use `_collationLocale()` (it 19/10/2026, de 19.10.2026, zh 2026/10/19) and every date is formatted — never print the API's raw ISO string; the collation locale also drives number formatting and the TTS voice |
| Suggested purchase quantity | `smartResolvePlanDays()` (explicit request → `shopping_plan_days` → inferred cycle) + `smartPurchaseHorizonDays()` (shelf life of the real storage location, not the pantry) + `smartCapPeriodNeed()` |
| Settings page (sections, accordion, checklist) | `SETTINGS_GROUPS` + `switchSettingsGroup()`, `SETTINGS_CHECKLIST`, `_initSettingsAccordions()`, `_openSettingsCardFor()`, `_checklistNewsItems()` — `scripts/gen-code-index.sh` keeps the line numbers |
| Setup wizard / assistant ledger | `_getMissingSetupSteps()` + `_gdriveWizardNeeded()` + `_setupSeen()` in app.js; the ledger (`setup_done`, `setup_seen`) lives in `app_settings` and `get_settings` hands it to every device, so a new browser profile is not re-asked (issue #301); `GDRIVE_ENABLED=false` and `APP_LANG` hide the Drive/language steps |
| Opened too long (PAO) | `data/opened_shelf_life.json` → `api/lib/opened_shelf_life.php`: 32 macro-families × 3 storage places with `warn` / `critical` days (USDA FoodKeeper + EFSA), deterministic, **no AI and no network**. `getStats()` attaches `pao` (`days_open, warn, critical, state, score`) to every opened row; the client judges an opened row by **that clock, not the printed date** (`_openedPao()` / `shouldShowExpiredBanner()`), sorts the banner by `score = days ÷ critical` (`_bannerPriority`: critical 3000+, warn 1500+), renders the `opened` layout (photo, "aperto da N gg · consigliato X–Y gg", amber/red rail, **🗑️ Butta** + **🍳 Usa per ricetta** inline, *Finito/Modifica/È ancora buono* in the `⋮` that opens in place), and offers "e altri N prodotti aperti" → `_showOpenedTab()`. "È ancora buono" snoozes 3 days (`app_settings.opened_check_snoozed`) |
| Header: the scale | `#scale-status-indicator` (`.scale-pill`) is **hidden** when no gateway is configured, neutral while it searches, **mint with the live weight** (`#scale-pill-weight` + `#scale-pill-battery`) only when a reading is genuinely streaming. The Android bridge **replays its cached status + last weight to every new client**, so the first weight of a relay session is ignored (`_scaleWeightMsgCount`), a reading is live only if a second one arrives within `SCALE_LIVE_MS` (10 s, re-rendered by a 5 s tick), and "gateway reached" ≠ "scale on" (`scale-status-waiting`, `scale.status_waiting`). Tap → `_scaleTogglePopover()` (device, battery, big weight, `_scaleTare()` local baseline — the bridge is read-only, connect/disconnect) |
| Bottom nav | The centre is SHELFY (`#nav-capture-btn`) and it wears the assistant's **avatar** (`.nav-capture-avatar`, the same transparent PNG as its sheet), the slots are Home / Dispensa / **🍳 Ricette** (`data-page="recipe"`) / Spesa / Storico / Config. The recipes own a slot again (the dashboard CTA alone made them feel gone); the **scanner is not a nav slot** and stays one tap away (`showPage('scan')` from the dashboard, the product modal and the flows). Scanning a barcode of a product you already have opens the **product modal** (`_inventoryRowForProduct()`); a new one opens the add form prefilled from Open Food Facts |
| Shopping session (spesa) | `startSpesaMode()` opens the **camera first**; `endSpesaMode()` stops it and, only if `_spesaSession.length > 0`, asks the total with a **numeric keypad** (`_spesaFinishPrompt` / `_spesaKeypad` → `spend_add`) — `[Salva totale]` / `[Salta]`. The former "amount before scanning" dialog is gone, and so is the receipt **file upload** (the OCR code is parked, settings toggle hidden) |
| Credits / free APIs | `data/free_apis.json` → `assets/js/free_apis.generated.js` (`php scripts/gen-free-apis-js.php`, in the service-worker shell) so the **splash** can paint the row before there is a token, offline; `_renderPreloaderApis()` runs at `DOMContentLoaded` and paints **logos only** (no names: they wrapped onto three lines on a phone) **and only the ones that read at 22 px** — a horizontal wordmark (7:1) is a sliver in a square, so `data/free_apis.json` marks it `wide: true` (a wide box in the card, the dialog and the thanks page) and `splash: false` (kept off the splash); the line `startup.free_apis_lead` above them says what they are, and the logos light up in turn (`--i` staggers `preloader-api-glow`, off with `prefers-reduced-motion`), `_loadFreeApis()` paints the *Settings* card (`#settings-free-apis`) and the **dedicated thanks page** (`#settings-free-apis-page`, tab `tab-credits` with `_openCreditsPage()`), from the same generated file (falling back to `GET free_apis` only for a stale bundle). The card's primary button (`credits.why_button`, immediately above the support card) opens `_openFreeApisDialog()` — one block per service with **why** (`credits.api_*`), **where** (`credits.api_*_where`), licence and URL. **The overview carries no credits footer** (it stole a third of a phone screen above the nav bar). `_freeApiLogoHtml()` renders the logo with the monogram as the `is-fallback` fallback; a `logo` passes through only when it is a **local** `assets/img/*` path (never a remote pixel), and an entry with no file at all draws its `mark` (the national catalogue ships that way until its official asset is vendored). A source can be **CONDITIONAL** with a `when` gate (`{medical:true, country:["IT"]}` on AIFA): `_freeApiActive()` filters the splash, the Settings card, the thanks page and the why/where dialog by the ACTIVE country module, `_syncCreditSources()` repaints them when `med_status` arrives (and when the module is switched off), and the last active set is kept in `evershelf_credits_seen_v1` so the splash — painted before any token — is right offline too. The sentence per service is a literal `t('credits.api_*')` / `_where` resolved by id in `_freeApiUsage()` / `_freeApiWhere()`; the logos are the projects' own brand assets and `credits.logo_note` attributes them |
| Custom locations (add / rename / delete) | `CUSTOM_LOCATIONS` (`.env`, or the `app_settings.env_overrides` fallback) holds the **typed labels**; the slug (`inventorySlugifyCustomLocation` ↔ JS `slugifyCustomLocation`) is what `inventory.location` stores. **Every write is saved server-side before the UI paints it** (`_saveCustomLocations` → `save_settings`): the pantry validates a location on the server, so a place that exists only in the browser answers `Invalid location` on the first product — a refused save is reported and nothing is painted. `inventoryCustomLocationMap` also **adopts a `custom_*` slug that still holds stock but lost its label** (`inventoryOrphanCustomLocations` + `inventoryLabelFromSlug`), so a place with products is never invisible nor refuses new stock. Deleting refuses while stock is there (`settings.custom_locations.in_use`); **renaming** is `POST location_rename` → `locationRenameAction` (api/index.php), which moves `inventory` + `transactions` in one transaction, updates `app_settings.medical_locations`, then rewrites the label list (in place), and answers `error_key`s for reserved/duplicate/comma/too-long. Client: pencil in `renderCustomLocations` → `renameCustomLocation` / `_submitCustomLocationRename` (`location-rename-overlay`, Esc via `_OVERLAY_CLOSERS`). **The order and the icons live in ONE list** (`mergeCustomLocations`): Dispensa → the marked **medicine cabinet** (`MEDICAL_LOCATION_ICON` 💊, resolved by `isMedicalLocation` from `medical_locations` in either stored form) → the fridges → the other custom places (`CUSTOM_LOCATION_ICON` 📦); the rail, the pantry tabs, the add/use pickers, the Settings list and `_medLocationChoices` all read that list, and `med_status.medical_locations` hands it to a new device at boot. The **pantry filter is a dropdown** (`#location-select` + `_syncLocationFilterUi`) with the cabinet as its own pill, shown when the cabinet itself or "Tutti" is open; opening a place clears a leftover search/tag filter, the search runs on `currentInventory` (that place), and `recent_popular_products?location=` takes the quick-access rail from that place's own history and stock. **The pantry shows the place you opened**: `_syncInventoryTitle` puts its icon + name in the page header (`inventory.title` comes back with "Tutti", `data-i18n` restored) and the cabinet pill is hidden while another place is open — it sits before the dropdown, so it used to be the first thing on screen after opening the fridge. A picker marks **exactly one** place by `data-loc` (`markLocationButton` + `resolveLocationKey`, which accepts a slug or the typed label): matching the button TEXT lit up "Dispensa" and "Dispensa cucina" together, and a manual pick records `dataset.userPicked` so no history/AI hint may move it afterwards. The **dashboard** lists the same places as ONE dropdown (`#dashboard-location-select`, `renderLocationRail`: a way in that resets after the pick, so the same place can be chosen again) with the marked cabinet as its own 💊 pill **outside** the list. **The expiry estimate follows the KIND of place, not its name** (`storageKindOfLocation`: pantry / fridge / freezer, read from the built-in key, from the label the built-ins carry in the ACTIVE language and from the words the CSV import knows; PHP port `storageKindOfLocationPHP`): "Dispensa cucina" is dated exactly like "Dispensa", and a pick between two places of the same kind NEVER moves the date (add form `recalculateAddExpiry` and both edit modals `recalcEditExpiry` recompute only when the kind changes, or on purpose with the vacuum toggle), while the AI hint may not apply days that belong to another kind (`sameKind`) |
| Product photo on a row | `_productThumbHtml()` — 44×44 neutrale (`var(--bg-app)`, hairline, radius 8, `object-fit: contain`) with the **category emoji under the picture**, revealed by `is-icon` when the URL is missing or fails (`onerror`) and 📦 when the category is unknown: never a broken image, never an empty square. **A medicine never wears a food category emoji**: `_isMedicalRow()` swaps the fallback for the drawn mark `_medIconMarkup()` (`assets/img/icons/medicine.svg`, medical tint `#6366f1`, in the service-worker shell) — also in the plain emoji slots (`_productCatIconHtml()`: detail sheet, action hero, add preview, products list), in the pantry badge (`badge-medical` = `medical.row_tag`, so the food `badge-category` and its "altro → guess a category" repair never touch a box) and in the medicine's own category group (`renderGroupedByCategory()` puts medical rows under "Farmaco" with the pill header); a PLACE is drawn by `_locIconMarkup()` (the cabinet = the pill, the rest keep 🗄️/🧊/❄️/📦; a native `<option>` keeps the 💊 character, an image cannot go in an option label). Used by the four dashboard rows, by `_bannerIconSet()` for the notice strip, and by `.inv-image` in the pantry (same frame; the detail modal keeps its own). `getStats()` **must** keep selecting `p.image_url` in both the expiring and expired queries, or the Home rows have nothing to show |
| UI grammar of an action strip | One emerald (`--brand-primary`) for the answers that write the outcome (`Usa`, `Conferma`, `Sì è finito`, `Esegui`, `Iniziamo`), neutral secondaries (`--surface-bg` + `--surface-border` + `--text-muted`) for everything else: no pastel per-button colours. **Red only for what loses stock** (throw away, finish, a correction that takes quantity away): the history's ↩ confirm is `btn-danger` when it undoes an *addition* but `_showDestructiveConfirm(..., {tone:'positive'})` → `btn-primary`, emerald bar and **no countdown** when it puts a consumption back. A toast never prints a slash between number and unit (`1/pz`): `showToast()` normalises it. Every banner action stays **inline** (wrapping), never behind a ⋮. A safety verdict that only asks for a look (`Controlla`) is a neutral tag; `Va bene` / `Non mangiare` / `Buttalo` keep their colour, |
| Dashboard panels & limits (live) | **Any mutation refreshes the dashboard itself**: `_dashboardLiveRefresh(invId)` fades the row out (`_fadeAlertRow` → `.alert-item.is-leaving`, 220 ms) and rebuilds the blocks on a debounce, wired into the edit save, `extendInventoryExpiry()`, a consumption, a deletion and `refreshAppDataAfterMutation()` — never ask the user to reload |
| Dashboard panels & limits | `DASHBOARD_*_MAX`, `_dashboardAlertCap()`, `_INSIGHT_PHASES` / `_applyInsightPhase()`; bars are drawn at 0% and filled from `data-target` on reveal; "Dimenticati" is the `DASHBOARD_STALE_MAX` (5) rows with the nearest expiry among the stock nobody uses (`forgottenExpiringItems()`, `mode=expiry`) |
| Emoji-before-translated-label bug | `iconLabel` / `_stripLeadingEmoji`|
| HTML printed as text (screensaver fact, alert banner, chooser modal) | `formatQuantity` returns `<span class="conf-size-info">`; text-only callers use `_formatQtyPlain` / `stripHtml`|
| Splash / boot rail | `#app-preloader` in `index.html` + `_preloaderStage` / `_PRELOADER_STAGE_KEYS` / `_PRELOADER_STAGE_OF_CHECK` in `app.js`. **Three household phases** (`system` → `pantry` → `ready`), each paced ≥ `_PRELOADER_STAGE_DWELL_MS` (~380 ms); floor `_SPLASH_MIN_MS` ≈ 1.2 s. Light canvas (no dark flashbang); quiet `startup.powered_by_open_data` line (API logos live in Settings → Crediti). `_bootDebug` (`?debug=1`) still shows the per-check ticker. Hand-off: `is-lifting` then fade-out. Version chip `#preloader-version` keeps the `vX.Y.Z` shape for `scripts/bump-version.sh`. | The splash closes with **SHELFY's avatar** under `.preloader-powered` (`.preloader-shelfy`: 56 px on a breathing emerald ring, `aria-hidden`, no stage of its own — it is a greeting, never a boot claim; 44 px under `max-height: 620px`).
| EverShelf Med (opt-in medicines) | Own settings SECTION (`data-group=medical`, `#tab-medical`) + one-time boot question (`med_setup_done`); `api/lib/medical.php` + `data/medical_categories.json` (**8 closed categories**, six locales, ATC prefixes for Phase 2 — never free tags; the prefixes are DATA and the form ticks the chip they resolve to (`_medCategoryFromAtc()`): a topical NSAID (Fastum gel, `M02AA15`) is `pain` via `M02A`, `S01*` is `eyes`, `D08/D03/D06/D09` are `firstaid`, `A02A/A03/A06` join `stomach`, `R05*` the whole cough group joins `cold`, `M03*` `muscles`) and `data/medical_atc.json` (Phase-2 ATC map): `med_status`, `med_symptom_search` (longest phrase first, medical rows only, tags → ATC fallback, **no AI and no dosage**) — and the **reader never calls a model either**: a box is identified by a printed code verified against the catalogue (`gemini_*` is banned in this module, the shelf-life AI hint skips a medical row, and fails on any `gemini_med_*` / `_medReadLabelAi` reference), `med_classify` (tags from the closed list + active ingredient + `med_pao_days`), `med_open` (Segna aperto → expiry = today + PAO, `expiry_user_set=1`, logged `med_open`). The module needs a **dedicated location** (pick or create → `medical_locations`) painted with its own tint; OFF by default (`MED_ENABLED` / the switch), nothing opens before `med_disclaimer_accepted`, a medical row never enters `for_recipe=1` or the recipe action, an **expiring** medicine stays in the expiry list with the tint while an **expired** one moves to its own block with **Smaltito**, **Food and medicines never share a place**: `inventoryLocationDomainError` refuses a food product in the cabinet and a medicine outside it on every write (`addToInventory`, `updateInventory`, the CSV import) and `med_classify` moves the stock of a product that becomes a medicine into the cabinet; the client only offers the legal places (`_renderLocationSelector(.., 'medical'|'food')`, `_locationsForDomain`, and for the edit screens `_locationsForEdit` + `_editDomainIsMedical`: a food's "📍 Posizione" never draws the cabinet, a medicine only its own, and a single legal place hides the whole block). **In Italy the number is the AIC and it travels two ways**: printed (`A040313076`, Code 39; or the bare 9 digits of Code 32; or AI `710` of the FMD DataMatrix) **and** carried by a Code 39 of the bollino, while the lot (`16G87N`), the pack's progressive number with its check digit (`0140581421`) and the DataMatrix that joins them are **packaging** — a barcode read is accepted only when the country module resolves it AND the catalogue contains it (`_medConfirmRead`, so a serial that starts with an `A` is thrown away) — `_medDetectCode`/`medicalDetectCode` answer the AIC first, `_medCodeIgnored`/`medicalCodeIgnored` throw a packaging code away, and the scan then **OCRs the label** (`_medReadLabelOcr`, the bundled Tesseract in a second parameter set, `_medOcrFindAic`; the label remains the reader that always works — first frames + every 2 s, bounded to 45 attempts, the printed `A`+9 form inside the rectangle `#scan-med-frame` (`_medGuideRect`), which prints the expected `A000000000` from `schema.ocr.sample`, **plus one rotating BAND of the whole frame per attempt** (`_medFrameBands`, because aiming is a skill and a box held half out of the rectangle is not a wrong box) — and the band pass runs **psm 6, never psm 7**: measured on a reproduction of a real bollino, a half-frame band through the single-line mode answers `'32'` or `''`, while psm 6 reads `A040313076`; a **psm 13 (raw line)** pass on the guide is added for the same reason. **One region per attempt, rotating every 900 ms** (`_medPassCursor`): six OCR jobs in a row made an attempt 3-5 s long on a phone and the household read that as *it does not read*. The **status chip belongs to the READER**: a packaging barcode is decoded on every frame, so it announces itself once per 8 s and never over a line the OCR wrote in the last 2.5 s (`_medLastOcrStatusAt`), and its sentence must fit the chip (`nowrap` + ellipsis: 60 chars max, six locales). The guide crop **grows** beyond the rectangle (`padX/padY` added, never subtracted) and the canvas is 1400 px — plus the `💊 Leggi AIC` button `#scan-med-ocr-btn`; when the printed letter is lost the page's digit runs are offered to the CATALOGUE (`_medOcrDigitCandidates` → `_medTryLooseReads`, only with a dataset loaded, at most four, each one verified like any other read); **a packaging barcode is never the drug number** — the SSN label carries several indistinguishable Code 39s, so only the AIC-shaped read and the FMD DataMatrix AI 710 get in, both proved against the catalogue). **The scanner has TWO panels** (`_setScanMode` / `#page-scan.is-medical-scan`): the place the pantry shows decides (`_scanModeForLocation` — the marked cabinet → medicines, anything else → food), and neither panel answers with the other world's codes: the medicine one hides AI/name/shopping and refuses an EAN with `medical.scan_not_medicine`, the food one hands a drug code over with the inline `#scan-med-cross` notice (*Apri 💊 farmaci* → `_medGoToCabinetScan`). A letter-carrying read in the medicine panel goes to the OCR, then to the form — never to a catalogue. The **catalogue is local**: `api/lib/medicines.php` + the `medicines` table (created by `initializeDB`) filled by `php scripts/import-medicines.php --country=IT <csv|xml>` from the AIFA open data (headers by NAME, one row per AIC, `--dry-run`/`--truncate`) — no medicine API is ever called; `GET med_lookup?aic=…|q=…` names the box, `_medShowCatalogueMatch` paints the card and **`_medUseMatch`** fills the field only on the household's click; `med_status.dataset` + `#med-dataset-block` say how many medicines are loaded (or that none are). A recognised AIC becomes a **medicine form**, not the food form (`_medFormSetMode` sets `is-medicine-form` on `#page-product-form`: `pf-food-only` hidden, no "is it a medicine?" switch, medical wording, the read code in `#pf-med-banner`, one closed-taxonomy chip required as the category) **before** asking any catalogue, and `med_status?lang=` falls back to the client's UI language so the country schema is never missing on an Italian UI. `data/medical_code_schemas.json` is **v3, one block per country** (`codes`, `ignored`, `ocr` with the printed `sample`, `fmd_national_ai`, `dataset`, and a `scan` summary): Italy is the BOTH-carriers case (the printed AIC and its Code 39), Germany prints the PZN (`PZN 00000000`), the US prints the NDC (`00000-0000-00`, catalogue = openFDA JSON, key = any 6.20 char national number), France's CIP-13 IS the EAN-13, Spain/Austria use AI 710. A country with no schema is **BLOCKED**: `medicalScanSupported` + `_medScanSupported` make `_medBlockedPopup` say so, offer the hand entry and point at `docs/wiki/Medicines-Country-Schemas.md` (the contributor guide) — never scan a label the app cannot read. Every code definition in `data/medical_code_schemas.json` carries a `pattern` matched against the **whole** read (`_medCodeMatches` in the browser, `medicalCodeMatches` in PHP — two ports, one rule): the AIC is `^A?\d{9}$` (both printed forms), the Pharmacode `^\d{3,6}$` inside 3…131070, and an alphanumeric read that matches nothing — `16G87N`, the lot/serial fragment of the box's EU FMD DataMatrix — is **not** claimed (digit-stripping it to `1687` is what made a 4-digit "Pharmacode" out of a code nobody had read); a letter-carrying code never reaches the five food catalogues, the household is asked once (`_medOfferMedicalFallback`); a row is medical when the product flag says so **or** when its location matches `medical_locations` in either form (`_medLocationKey`: slug or typed label), and the **product form** carries the `pf-medical-group` toggle (chips + ingredient + PAO) which saves through `med_classify`. A scanned box is **stocked from the medicine form itself** (`_medStockAddDirect()`): quantity, the printed expiry and sealed/opened are answered there and the row goes **straight into the cabinet** — no pantry step, no place question (a medicine has one legal place) and no second quantity — and the form/page speak about the cabinet, never about the pantry (`medical.form_save`, `medical.add_title`, `#add-location-group` hidden, `medical.action_add_sub`/`medical.action_use_sub`, no recipe button on a medicine's action page, `recalculateAddExpiry()` returns early for a medical row). **One screen, always**: a missing cabinet is created on the spot (`_medEnsureCabinet()` → the default `medical.default_location_name`, `medical.cabinet_created`), a refused write keeps the form with every answer and says `medical.stock_failed` (never a second form), and success lands on the CABINET LIST with the new row in it (`showPage('inventory', cabinet)`). The package code is not shown on a medicine (`#pf-barcode-group` hidden: the code was already read and verified), and the pack size (“how many it should hold”) is the catalogue's, shown by `medical.pieces_hint` with the quantity prefilled from it. The stock questions follow the household’s order: **sealed / already opened first** (it decides what the number means), then `medical.form_qty_label` — on a SEALED box the quantity IS the pack (`_medFormStockPrefill()` fills it from `_medPackSize()`, editable) — and only when OPENED a second field, `medical.pack_qty_label` (“Confezione intera”), so the app knows the stock AND the pack it belongs to; the pack is stored as `products.default_quantity` (the pack size the rest of the app reads: fractions, “sta per finire”, shopping list) while `inventory.quantity` stays the stock. `_medPackSize()` (core/med.js) reads the CONTAINER, not the first number: it takes countable pieces first, otherwise the g/ml measure, **skipping a ratio** (`25 mg/g`, `mg/ml`) and keeping the largest — “Fastum gel 2,5% 25 mg/g tubo 50 g” is 50 g, never 25 mg. The expiry of a box is a **month + a year**: `_medExpiryFillPickers()` / `_medExpiryIso()` / `_medExpiryPick()` ask "scadenza MM/AAAA" (`#pf-med-expiry-month`/`-year`, hidden `#<prefix>-expiry`) and store the **last day of that month**, because that is what the pack prints; the twelve month names are DATA (`months.*` in `translations/*.json`, six locales — never hardcoded), painted by `_medExpiryPaintMonthOptions()` from `translatePage()` as soon as the strings load, so the `<select>` is populated before any form opens. The ADD step survives only as the fallback of a refused write (`#add-med-stock`, `_renderAddMedStock`, same month+year pickers): **sigillata o già aperta** (an opened pack calls `med_open`, so the expiry becomes today + the PAO) and **quante ne restano** as fractions of the pack the catalogue describes (`_medPackCount` in `core/med.js` reads "10 buste" from the row's notes; a liquid names no pieces) |
| Optional support (donations only — **no ads**) | `api/lib/support.php` (decision, payload, donation link, disclosed costs), `api/lib/qr.php` (ISO QR encoder → `?action=support_qr`), `assets/js/core/support.js` (dialog, donation panel; `_renderAdSlots` is a parked no-op), `.env` keys `SUPPORT_*` — the banner slots and the `ADS_*` / `SUPPORT_BANNERS_AVAILABLE` keys are **commented out on purpose**doc `docs/wiki/Support-and-ads.md` |
| PWA service worker | `sw.js` |
| Unraid / containers | `unraid/evershelf.xml` (CA template: the `data` path is the database), `docs/wiki/Unraid.md`, `EVERSHELF_ENV_FILE` → `evershelfEnvFile()` in `api/lib/env.php` |
| Backup + the scheduler that runs it | `createLocalBackup` / `restoreLocalBackup` / `listLocalBackups` in `api/index.php` (`VACUUM INTO` → `.part` → rename, retention, `data/backup_last_ts.json`); `docker/evershelf-cron` (`/etc/cron.d/evershelf`, every 5 min as `www-data`) runs `api/cron_smart_shopping.php`, which backs up at most once every 23 h when `BACKUP_ENABLED=true` (default); *Settings → 💾 Backup* shows the last one and warns after `_BACKUP_STALE_DAYS` (8); failures carry `error_key`, restore carries `message_key`, |
| A medicine read is CONFIRMED, never assumed | `_medConfirmRead()` opens the form (never a card over the camera) and the catalogue card carries a **"Non e questo?"** chip row: `med_lookup?near=1` -> `medicineFindNeighbours()` (real rows sharing the leading digits, ranked by `medicineLevenshtein`): up to **four** neighbours with the name/dosage/pack printed on the box (`_medNeighboursHtml()` / `_medUseCode()`, in `#pf-med-match`), the read first at distance 0; `_medConfirmRead(reading.aic)` is the only exit from the reader — `A040313076` read as `...72` is one digit and the reader cannot tell. Without a catalogue the card says so instead of inventing neighbours. The form fills itself from an exact catalogue row — name, company, notes, closed category (`_medFillFromCatalogue()`, `_medCategoryFromAtc()`), empty fields only — and a box the catalogue cannot name is **not added at all**: `_medStartManualEntry()` looks the code up FIRST and a refusal keeps the scanner running (`medical.only_catalogue`, or `medical.scan_needs_catalog` when no dataset is imported). No typed title, no name read off the label, no "Aggiungi a mano": `_medConfirmedAic` — set only by `_medFillFromCatalogue()` — is what `submitProduct()` requires, so the only ways in are a `medicines` row or a product that already exists (`pf-id`); the name field is read-only in medicine mode (`#pf-med-name-hint`, `medical.name_from_catalogue`) and the title carries no "new" (`medical.form_title`). Over the viewfinder **nothing** is drawn: the guide frame fills with the digits and the existing status chip shows the pass result; every OCR call goes through `_medOcrRun()` (one worker, one job at a time — interleaved calls returned nothing), `_medOcrSelfTest()` proves the engine on the device, and the code can be **typed** (`#scan-med-code-input` + `_medSubmitCode()`, same verification). No "Leggi AIC" / "Aggiungi a mano" buttons. The guide frame fills with the digits already recognised (`_medPreviewSample()`), the catalogue card carries its **source** (`medical.match_source`: "AIFA open data · N farmaci") with ingredient/form/pack/company/ATC/class, and the catalogue **downloads and refreshes itself** (`med_catalog_sync`: the module `dataset_sync` declares the source, Italy = the AIFA confezioni export, 160k packs, streamed to a temp file, ~30 s, 4 MB; automatic on module activation, daily at most, monthly, and from the cron; Settings keeps "Aggiorna dal sito AIFA" and the manual `POST med_catalog_import`) — a scan is **verified**: an AIC the catalogue does not contain is a misread digit, `_medConfirmRead()` rejects it and keeps reading (`medical.scan_code_invalid`, `_medRejectedReads`) |
| Medicine label reader (feedback + hardening) | `_medReadLabelOcr()` runs THREE passes on the guide rectangle (`#scan-med-frame`): single line, the same crop binarized with a LOCAL (Bradley, integral-image) threshold (`_binarizeScanCanvas()`), a contrast-stretched + sharpened copy (`_contrastScanCanvas()`), single block, then a 1.6× wider band; the OCR alphabet is `0-9A` (`_tesseractConfigure('aic')`) and `_medOcrFindAic()` repairs the letter-spacing and the `1/l`, `0/O` confusions of OCR-B — only inside a run that starts with the prefix letter, so a pack serial can never become a drug number. `_medOcrFeedback()` paints `#scan-med-read` (“Letto «…» + the crop), because a silent failure cannot be diagnosed by the household; the loop never stops, it slows to `_MED_OCR_IDLE_MS` (4 s) |
| Medicine read diagnostic (TEMPORARY) | `MED_DEBUG=true` in `.env` → `med_status.debug` hands the flag to the client, `_medDebugPass()` / `_medDebugPanel()` post `POST med_debug` (token + CSRF, 403 without the flag) and the server writes `data/med_debug/events.jsonl` + `frames/*.jpg` (20 events/request, 700 kB/image, pruned to 400 events and 40 pictures; the folder is git-ignored). It is how "it reads nothing" is seen instead of guessed: region, psm, video size, the text of each pass and the crop it came from. Turn it off with `MED_DEBUG=false` — the whole path goes quiet |
| Medicine code: which number identifies the drug | **Split core + country module.** Core, country-neutral: `api/lib/medical.php` (`medicalGs1Parse()`, `medicalCountryModule()`, `medicalLocalCode()`, `medicalCodeIgnored()`, `medicalOcrPatterns()`) and `assets/js/core/med.js` (`_medGs1Parse()`, `_medRegisterCountryModule()`, `_medLocalCode()`, `_medDetectCode()`, `_medOcrFindAic()`) — they read `data/medical_code_schemas.json` and ask the module. **Italy only**: `api/lib/med/italy.php` (`medicalItalyAicFromCode()`, `medicalItalyColumnAliases()`) + `assets/js/med/italy.js` (registered by the schema's `"module": "italy"`), because the AIC has three printed forms, a Code 39 form and a place in AI 710. The scan takes the AIC-shaped read first (a Code 39 of the bollino) and otherwise OCRs the label with `_medReadLabelOcr()` |
| Medicine catalogue (names) | `api/lib/medicines.php` (`medicines` table, `medicineUpsert/FindByAic/Search`, `medicinesImportFile($db,$file,$opts,$aliases)` — the COLUMN NAMES come from the country module) + `scripts/import-medicines.php <csv\|xml\|json> --country=IT` (one importer, one module per country; JSON = the openFDA NDC shape; `--dry-run`, `--truncate`, `--limit`; fixtures in `scripts/fixtures/aifa_sample.*`) + `GET med_lookup` + `_medShowCatalogueMatch()`/`_medUseMatch()` in the medicine form, `#med-dataset-block` in Settings. Never an API call |

| Kiosk (Android WebView) | `evershelf-kiosk/` — `KioskActivity.kt` (WebView surface colour, URL normalisation on resume, 5-min page watchdog), `themes.xml` (`windowBackground`), `SetupActivity.kt` (pairing wizard) |

| OCR preprocessing (why not simply B/W) | Tesseract binarizes internally (Otsu), so a pure black-and-white copy is not a free win: one GLOBAL threshold on a phone photo of a glossy blister lets the lamp gradient decide the cut. The crop is upscaled ×3 first, then `_binarizeScanCanvas()` uses a **local** Bradley threshold (integral image, window = 1/8 of the short side, margin 0.15) and `_contrastScanCanvas()` does a 2%-98% histogram stretch + 3×3 sharpen; only two of the six rotating passes are preprocessed, the other four keep the raw pixels, so a clean label is never harmed. `_medDebugPass()` records the `prep` of each pass. Measured on a synthetic 40→240 gradient strip: the old global cut found 7140/168 ink pixels (paper eaten on the dark side, digits lost on the bright one), the local one 240/240 (= exactly the 480 strokes). |

## Anti-patterns to avoid (already present — do not copy)

- **One undefined element in the scan panel stops the camera.** `_setScanMode()` runs right
  before `initScanner()` in `showPage('scan')`, so a `ReferenceError` inside it leaves a frozen
  frame and NO reader at all — while every symptom says "the OCR does not read". A removed
  button (`ocrBtn`, v1.28.0) did exactly that for a release. Keep the body inside the `try`
  wrapper (`_setScanModeInner`) and check the console before blaming the engine.

- **A missing `}` does not fail — it nests.** Five functions were parsed inside
  `dismissBannerItemAfterAction()` and the banner threw "is not defined" while `node -c`
 stayed green. `php now brace-matches every shipped script
  and checks that each inline handler exists at top level.

- Building HTML with string concatenation + `innerHTML` and inline `onclick`
  with un-escaped data. Prefer the existing `escapeHtml`.
- Reading whole giant files to find one function — use the indexes / `grep -n`.
- Writing to `.env` without escaping values (comment/quote/newline safe).

## See also

- `docs/CODEBASE-MAP.md` — deep architecture, DB schema, data files, action catalog.
- `docs/ROADMAP.md` — the product-facing roadmap: what is next, the effort involved, and
  which issues are open for grabs (the pinned #290 is the community entry point).
- `docs/OPTIMIZATION-BACKLOG.md` — prioritized bugs, perf, CI, and technical debt.
- `docs/ARCHITECTURE.md`, `docs/CORPORATE-UI.md`, `SECURITY.md`, `CONTRIBUTING.md`.
- `.clinerules` — the short digest of this file, loaded automatically by Cline.
- `.clineignore` — hides generated/binary/runtime/vendor files from the agent's
  context (gitignore syntax). Update it when a new generated folder appears.

> Audit / review notes are **internal working documents**. They are kept in the
> git-ignored `todo/` folder at the repo root and are intentionally never pushed:
> this repository is public and everything committed must be in English.
>
> This includes the CI improvements that need a `workflow`-scoped PAT to push:
> they live on the local branch `ci-tests-lint` and as
> `todo/0001-ci-run-the-test-suite-lint-every-JS-file-shellcheck-.patch`.
> Apply with `git am todo/0001-ci-*.patch` once a PAT with `workflow` scope is set.
