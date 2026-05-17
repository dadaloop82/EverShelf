# Changelog

All notable changes to EverShelf will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased] — Ideas & Roadmap

> Ideas collected during development. No priority or date implied.

- **Recipe scraps tips** — During cooking steps, detect "waste" generated (peels, cores, bones, eggshells, coffee grounds, citrus zest, etc.) and surface AI-powered tips on how to reuse them (compost, natural cleaner, broth, candied peel, etc.). Could be shown as an optional collapsible hint card below the step that generates the scrap.

## [1.7.18] - 2026-05-19

### Added
- **Dark mode** — New theme selector in Settings (Appearance card): **Off (Light)**, **On (Dark)**, **Auto (follows system)**. Applied immediately on page load to prevent white flash. Resolves [#78](https://github.com/dadaloop82/EverShelf/issues/78).
- **Export inventory** — New 📤 button in inventory page header opens a modal to download the inventory as **CSV** (UTF-8 with BOM, Excel-compatible) or open a **print-ready HTML page** (auto-triggers print dialog for PDF). Export card also available in Settings tab. Resolves [#64](https://github.com/dadaloop82/EverShelf/issues/64).
- `translations/de.json`: fixed missing `log.recipe_prefix` key.

## [1.7.17] - 2026-05-19

### Added
- **French translation (🇫🇷 Français)** — Complete `translations/fr.json` with all 1049 translation keys. Resolves [#77](https://github.com/dadaloop82/EverShelf/issues/77).
- **Spanish translation (🇪🇸 Español)** — Complete `translations/es.json` with all 1049 translation keys. Resolves [#77](https://github.com/dadaloop82/EverShelf/issues/77).
- Language selector in Settings now shows all 5 languages: 🇮🇹 Italiano, 🇬🇧 English, 🇩🇪 Deutsch, 🇫🇷 Français, 🇪🇸 Español.
- Default fallback language changed from Italian to English (for users with unsupported browser locale).
- Setup wizard "Done" screen and navigation buttons localised for French and Spanish.

## [1.7.16] - 2026-05-17

### Added
- **Barcode scan history** — Last 20 scanned products are stored server-side (SQLite `app_settings`) and shown as chips in the scan page (`#scan-recents-chips`). Tapping a chip selects the product directly — no need to scan again. Resolves [#68](https://github.com/dadaloop82/EverShelf/issues/68).
- **Full server-side user-data centralisation** — All user preferences previously siloed in `localStorage` per-device are now synced to the server via `app_settings_save` and loaded back at startup via `app_settings_get`. Affected data: shopping tags, pinned Bring! items, location preferences (use/move), auto-added Bring! entries, Bring! purchased blocklist, no-expiry dismissed products. Data is now shared across all devices (desktop, phone, kiosk, Android app).
- **One-time localStorage migration** — On first load, any data found in the old localStorage keys (`shopping_tags`, `_userPinnedBring`, `_prefUseLoc`, `_prefMoveLoc`, `_autoAddedBring`, `_bringPurchasedBlocklist`, `_noExpiryDismissed`, `evershelf_scan_recents`) is automatically migrated to the server and the local keys are removed.

## [1.7.15] - 2026-05-16

### Added
- **Full i18n audit** — Comprehensive sweep of all user-visible strings in `app.js` and `index.html`. 25+ new translation keys added across `it.json`, `en.json`, `de.json`, covering: vacuum toast, TTS voice controls, timer step labels, product note labels, error messages, expiry form, barcode hint, category select placeholder, cooking step fallback, `form.select_placeholder`, `btn.yes_short`/`no_short`, `add.vacuum_question`, `add.vacuum_saved`, `move.vacuum_seal_rest`, `cooking.step_fallback`, `error.prefix`/`unknown`, `product.select_variant`, and more.
- **Splash screen redesign** — Logo displayed prominently, spinner below, app version shown at the bottom; version label injected dynamically at boot time so it never gets out of sync. Minimum 3-second display duration enforced: `_splashStart` is recorded before `DOMContentLoaded`; the fade-out is delayed by the remaining time if the app loads faster than 3 s.
- **Demo GIF in README** — `assets/img/demo.gif` (processed at 2× speed, ~36 s) added to the `## 📸 Screenshots` section.
- **`pz`/`conf` unit labels translated** — "pz" now shows as "pcs" in English and "Stk" in German; "conf" shows as "pkg" / "Pkg". All `unitLabels` objects in JS now use `t('units.pz')` / `t('units.conf')`.

### Fixed
- **Camera button (📷) opened kiosk SettingsActivity on Android** — The native `btnSettings` ImageButton in the kiosk layout was positioned `top|end` with `alpha=0.12` (nearly invisible), sitting directly on top of the HTML scan button in the webapp header. Every tap on the 📷 button was intercepted by the native View and opened `SettingsActivity`. Fixed: moved `btnSettings` to `bottom|end` (above the bottom nav bar, `marginBottom=80dp`) and increased `alpha` to `0.28` so it is clearly separate from the header. Kiosk versionCode bumped to 16.
- **Camera button (📷) opened settings on Android Chrome/Brave** — `pointerleave` fired before `pointerup` when finger drifted slightly, cancelling the long-press timer and leaving the browser to dispatch a synthetic `click` that bubbled to an unintended handler. Fixed: added `setPointerCapture` (prevents `pointerleave` during touch) and `preventDefault` (blocks synthetic click); replaced `pointerleave` with `pointercancel` handler. Added `touch-action: manipulation` to `.header-scan-btn` CSS.
- **Logo white background on splash screen** — Re-processed both `logo.png` and `logo_icon.png` with fuzz 35% alpha extraction, removing the white background that was visible against the dark splash background (`#0f172a`).
- **Recipe button label** — Shortened to "Ricetta" / "Recipe" / "Rezept" for compact display in the inventory quick-action modal.
- **Quantity decimal precision** — `qtyNum` in recipe/cooking ingredient buttons and `conf` fallback display in inventory cards now limited to 1 decimal place (was showing 7+ decimal places from raw AI output, e.g. `0.25353223 conf`).
- **"Errore" / "Error" fallback strings** — All remaining Italian hardcoded `'Errore'` fallbacks in `showToast()` calls replaced with `t('error.generic')`. Italian fallback strings removed from buttons that already used `t()`.
- **README Italian phrases** — "La quantità è giusta (2 pz)", "🤖 Spiega", "Latte / Affettato / Panna da cucina", "Buon appetito!", "L'ho buttato" replaced with English equivalents in the README.
- **Appliance chips translated** — `renderAppliances()` now shows translated names (e.g. "Air fryer" in EN, "Heißluftfritteuse" in DE) for all known canonical Italian appliance names via `_applianceDisplayName()` lookup. `addApplianceQuick` toast no longer hardcoded Italian. Remove-button title translated.
- **Gemini API key not preserved on settings save** — `saveSettings()` was overwriting `s.gemini_key = ""` when the Gemini input field was empty (it is intentionally not pre-populated for security). Key is now preserved if the input is blank. `_geminiAvailable` is re-fetched from the server after every settings save so the recipe buttons reflect the real state immediately.

## [1.7.14] - 2026-05-16

### Added
- **In-app bug report form** — "Segnala un problema" now opens a modal form instead of redirecting to GitHub. Users can select type (Bug / Feature / Question), write title and description, optionally add reproduction steps. A GitHub issue is created directly with labels and app metadata attached.

### Fixed
- **Kiosk settings button** — "Apri configurazione kiosk" in webapp settings was showing a toast asking to tap a gear icon that no longer exists. Now calls `openNativeSettings()` bridge directly (opens Android SettingsActivity). Fallback for old APKs shows a proper "update the kiosk app" hint.
- **False update badge** — `manifest.json` version was `1.7.12` while the app header showed `v1.7.13`, causing the server to report an older deployed version and triggering a spurious update notification.
- **Kiosk settings gear disappeared** — Race condition where Kotlin's `onPageFinished` injects `#_kiosk_overlay` before JS runs; JS found the element already present and returned early without ever restoring the native gear button. Fixed: JS no longer hides the native gear on load; `closeModal()` restores it with `setNativeSettingsVisible(true)`.
- **`openNativeSettings()` fragile typeof check** — Android `@JavascriptInterface` methods are not always detected as `'function'` by typeof; replaced with try/catch.

## [1.7.13] - 2026-05-16

### Fixed
- **Fresh-install crash: `no such column: undone`** — The `transactions` table was created in `initializeDB()` without the `undone` column, but the composite index `idx_transactions_pid_type_undone` immediately referenced it, crashing every new installation at first DB access.  Added `undone INTEGER DEFAULT 0` to the transactions schema in `initializeDB()`.
- **Race condition: `duplicate column name: package_unit`** — Concurrent API requests on a new installation could all pass the `PRAGMA table_info` guard simultaneously and each try to `ALTER TABLE products ADD COLUMN package_unit`, with all but the first failing with a PDOException.  Wrapped all `ALTER TABLE … ADD COLUMN` calls in try/catch to silently ignore duplicate-column errors.

## [1.7.12] - 2026-05-13

### Fixed
- **"Use first" banner showed a calculated expiry date** — `_renderUseExpiryHint` was displaying a *calculated* shelf-life date (from opening date) instead of the actual one. When `opened_at` is set, the banner now shows "That one [in the fridge], opened X days ago — use it first!" using the new `use.expiry_warning_opened` translation key.
- **"Use All / Done" in recipes deleted the inventory row** — `submitRecipeUse(true)` was sending `use_all: true` to the API, which executed a direct `DELETE` on the inventory row without any confirmation. The function now calculates the exact quantity from the available items (`_recipeUseContext.items`) and sends a regular `inventory_use` with an explicit quantity.
- **Recipes: `qty_number` returned in grams for piece-counted (`pz`) items** — The AI prompt and PHP post-processing now instruct Gemini to express `qty_number` as whole pieces for ingredients with unit `pz` (sliced bread, crackers, etc.). The ingredient list in the prompt includes `[use whole PIECES]` for each `pz` product. The PHP fallback for `pz` items without `default_quantity` no longer divides by 100, but uses the AI-returned `qty_number` if it is a plausible count, otherwise defaults to 1.

### Added
- **Translation key `use.expiry_warning_opened`** — New key in `it.json`, `en.json`, `de.json` with `{loc}` (location) and `{when}` (days since opening) placeholders.

## [1.7.11] - 2026-05-12

### Added
- **Scan page redesign** — The scanner page has been completely redesigned for tablet and mobile:
  - **2× fixed zoom** — hardware zoom if available, otherwise automatic CSS `scale(2)`.
  - **Torch** — in-viewport button with toast feedback and visual state indicator.
  - **Camera flip** — front/back switch with persistence in settings.
  - **3 input tabs** — Barcode / Name / AI for quick access to each scanning mode.
  - **Recent products** — chips for the last 6 scanned products (localStorage), with category icon.
  - **Live code overlay** — partially detected barcode shown as overlay in the viewport during partial scan.
  - **Confirm overlay** — checkmark + product name displayed for 900 ms on successful recognition.
  - **Guide corners** — visual alignment frame for barcode centering.
  - **AI Number OCR** — after 4 s without a scan, a "Read numbers with AI" button appears; Gemini analyses the video frame and returns barcode digits even when the optical scanner fails.
- **PHP `gemini_number_ocr` endpoint** — New POST endpoint; accepts a base64 JPEG image, asks Gemini to locate the EAN-13 / EAN-8 code printed on the product, and returns the digits or `not_found`.

### Fixed
- **False consumption anomaly positives (e.g. "Mozzarella 3 pcs")** — Removed the `untracked` direction (consumption higher than recorded purchases), which was generating banners for every product with untracked purchase history. Only `phantom` and `missing` anomalies are now reported.
- **"~0 g/week" consumption prediction** — The model now requires a minimum of 5 transactions (was 3) and a time span of at least 7 days; predictions where consumption is < 15% of the baseline are skipped, eliminating false positives for products with few closely-spaced transactions.
- **Suggestion dropdown on the Name field (scan page)** — Removed `list="common-products"` from the input field; the datalist is no longer triggered on tablets.

## [1.7.10] - 2026-05-11

### Fixed
- **"Set expiry" banner did nothing** — `editBannerNoExpiry()` was calling `openEditInventoryModal()` which does not exist. Fixed to call `editInventoryItem()` (the correct function used by all other banner handlers). Added a prefetch of `inventory_list` because `currentInventory` is empty on the dashboard.
- **"Product not found" when opening modal from a banner** — `currentInventory` is always empty on the dashboard; the inventory fetch now happens before opening the modal (same pattern as `editReviewItem` and `weighBannerItem`).
- **Expired banner on opened UHT milk** — The banner was showing "Expired!" instead of "Opened too long". Items with `opened_at` now display "Opened X days ago in [location]" in both the title and the banner detail.
- **Generic milk shelf life 4 → 7 days** — Milk without qualifiers (e.g. "Milk") was treated as fresh (4 days). Fresh milk is still handled explicitly (`latte fresco/intero/parzial/scremato` → 3 days); the generic case now defaults to 7 days (UHT default). Fix applied in both PHP (`database.php`) and JS (`app.js`).
- **Stale `opened_at` on sealed packages after split** — When a use operation splits a row into "whole sealed packages + opened fraction", the sealed-packages row was not clearing `opened_at`. All 3 split code paths now execute `opened_at = NULL` on the sealed row.
- **`inventory_update` was not recording transactions** — The quantity-edit modal updated inventory without creating transaction records. The quantity difference is now automatically recorded as `in` or `out` with a `[Manual correction]` note, preventing false positives in the anomaly detector.
- **False consumption anomalies after restocking** — The prediction baseline was using only the restock quantity (`restockQty`), ignoring pre-existing stock, causing `actual > expected` systematically. New baseline: `current_qty + consumed_since_last_restock`, which correctly reflects the real situation regardless of prior stock levels.
- **Anomaly banner firing on almost all products** — Two fixes:
  1. `expected = 0` no longer generates a "more" anomaly (the model assumed you should have run out, but you restocked).
  2. "More than expected" threshold raised to 400% (was 30%); "less than expected" threshold remains at 30%.
- **Expired section showing already-discarded products** — The `expired` query was missing `AND i.quantity > 0`; discarded products (qty=0) with a past expiry kept appearing. Query fixed and orphan rows cleaned from the DB.
- **Hardcoded Italian string `scade il` in banner** — Replaced with the correct i18n key.
- **Docker: `SQLSTATE[HY000][14] unable to open database file`** — `_ensureDataDir()` in `database.php` now creates the `data/` directory if missing and attempts `chmod(0775)` if not writable, resolving the error on freshly mounted Docker volumes.

### Added
- **Complete i18n** — Added ~25 missing translation keys for kiosk UI, Gemini responses, banners, scanner, shopping, and appliances across all 3 language files (`it.json`, `en.json`, `de.json`). Total: 934 keys per language.

## [1.7.8] - 2026-05-10

### Added
- **Transfer to Recipes from chat** — When the Gemini Chef chat generates a recipe, a "📥 Transfer to Recipes" button appears. Pressing it triggers Gemini to convert the chat text into a complete structured JSON (title, meal, ingredients, steps); the backend enriches each ingredient with `product_id` and `location` via fuzzy-match (identical to `generateRecipe`); the recipe is saved and opens directly in the Recipes section with all "Use" buttons and full cooking mode.
- **"Open recipe" button** — After a successful transfer, the "📥 Transfer to Recipes" button transforms into "📖 Open recipe" (same DOM element), preventing overlap.
- **Create a recipe from an ingredient** — In the action panel of every inventory item, a "👨‍🍳 Create a recipe with this" button appears (teal, full width). Pressing it, Gemini generates a recipe using that ingredient as the star (same pipeline as `chatToRecipe`: inventory fuzzy-match enrichment, `meal=null`, 8192 token max).
- **Meal not auto-categorized** — Recipes generated from chat or from an ingredient are no longer auto-categorized (`meal` remains null); the meal tag in the UI is only shown when explicitly set.

### Fixed
- **Smart shopping: false "running low" alert** — If a product in grams/ml was nearly exhausted (e.g. Butter 30 g = 12%) but the same product was also available as a sealed package (Butter 1 pack = 99%), the system still flagged "running low". Now checks whether the `shopping_name` family has stock from other products; if so, the alert is suppressed.
- **Corrupted translation JSON** — The `action` section was duplicated in `de.json`, `en.json`, and `it.json`, causing JSON parse errors that blocked CI/CD. The spurious duplicate section has been removed.

## [1.7.7] - 2026-05-10

### Fixed
- **Smart shopping family suppression** — The `recentlyExhausted` logic (products finished < 14 days ago) was incorrectly bypassing the `shopping_name` family suppression, causing false positives: products like Vanilla Yogurt appeared urgent even with 2 kg of Yogurt in stock. `recentlyExhausted` now only bypasses the token-based loose match; family suppression by `shopping_name` always applies.
- **Shelf-life pre-warming in cron** — The cron now calls `prewarmShelfLifeCache()` every 5 minutes, pre-loading via Gemini AI the shelf life of opened inventory items (max 5 items per cycle) before the user views them. This eliminates the noticeable delay on first click of "Opened on…".

## [1.7.6] - 2026-05-10

### Fixed
- **`shopping_name` truncated (Piadina)** — The product "Piadine medie" had `shopping_name='Pi'` (truncated), preventing it from grouping correctly in its family. Fixed to `Piadina`.
- **Family merges in DB** — Grana Padano now under `Formaggio` (was a `Grana` singleton), Prosciutto cotto now under `Affettato`, Panna acida now under `Panna`.
- **`daily_rate` over the actual active period** — The daily consumption rate was using `first_in → now` as the window, diluting the rate with periods when the product was already exhausted (e.g. garlic exhausted at day 34 was calculated over 60+ days). Now uses `first_in → last_activity` (last purchase or last use), giving more accurate reorder predictions.
- **Stable anomaly dismiss key** — The dismiss key was using `product_id + round(expected)`, which changed with every new transaction, causing already-dismissed anomalies to reappear. Now uses `product_id + direction` (phantom/missing/untracked) — stable as long as the direction does not change.
- **Smart shopping: products exhausted < 14 days ago** — Products finished within the last 14 days are no longer suppressed by the token-coverage check or the shopping_name family check: if you just ran out, you probably want to restock regardless of equivalent stock on hand.
- **Chat pruning** — `chatSave()` now deletes messages beyond the 200 most recent after each save, preventing unbounded growth of the `chat_messages` table.


## [1.7.5] - 2026-05-10

### Added
- **Vacuum sealed prompt on item use** — After using a conf/weighted-unit item that still has remaining stock, a sliding popup asks "🔒 Messo sotto vuoto?" with Sì/No buttons and an 8-second auto-dismiss countdown bar. Default is Sì if the item was previously sealed, No otherwise. Works for all container units (conf, g, kg, ml, l) and any item previously marked as vacuum sealed.
- **Multi-function appliance awareness in recipes** — When the user sets a multi-function appliance (Cookeo, Bimby, Thermomix, Monsieur Cuisine, Instant Pot, Multicooker, Robot da cucina) in Settings, all Gemini recipe prompts (chat, recipe generation, weekly meal plan) now explicitly instruct the AI to consolidate as many cooking steps as possible into that single machine. Each appliance's available functions (rosolare, tritare, vapore, cuocere a pressione, etc.) are listed and the AI is required to indicate the specific mode/program at each step.
- **Server-side Bring! cleanup in cron** — `bringCleanupObsolete()` now runs every 5 minutes via cron without requiring any client page load. Items auto-added by the app (identified by `⚡`/`🟠`/`🛒` markers in their Bring! spec) are automatically removed when the smart shopping engine no longer flags them as needed. Works across all devices/clients.
- **`shopping_name` in `inventory_list` API** — The `inventory_list` endpoint now returns the `shopping_name` field from the products table, enabling family-based stock matching in the client-side cleanup fallback.

### Fixed
- **Bring! cleanup: false token match (Succo/Frutta)** — `bringCleanupObsolete` previously indexed smart items by product name tokens. "Pera Italiana **Succo** e polpa **frutta**" (shopping_name: "Pere") caused "Succo" and "Frutta" to be retained on Bring! indefinitely even when fully stocked. Now indexes **only** by `shopping_name` tokens.
- **Bring! cleanup: expired items with fresh family stock (Verdure)** — When a product is expired but its `shopping_name` family has ≥50% fresh stock from other products (e.g. Minestrone tradizione scaduto 01/05 but 590g fresh Verdure in freezer/pantry), it is no longer flagged as `critical` and is removed from the shopping list.
- **Bring! remove: catalog items not removed (Formaggio/Käse)** — `bringRemoveItem()` and `bringCleanupObsolete()` now try both the Italian display name and the Bring! internal German catalog key (e.g. `Käse` for `Formaggio`). Previously, catalog items with a German key were silently not removed.
- **Barcode scanner: EAN auto-submit on manual input** — Typing or pasting a valid 8/13-digit EAN in the manual barcode field now auto-submits immediately without needing to press a button. Checksum validation gives a warning toast for invalid codes without blocking entry.
- **Shopping list: `isExpiringSoon` false positives** — Products bought in bulk that expire naturally in 3 days (e.g. fresh produce) were flagged `medium` urgency on the shopping list despite having 100%+ stock. Now requires `pctLeft < 50%` before triggering.
- **Shopping list: expired batch with fresh restock suppressed** — Products with an expired batch AND a recent fresh restock (≥50% fresh stock) are no longer flagged `critical` for shopping. The expired-batch UI banner on the dashboard handles the disposal prompt instead.
- **Shopping list: cross-device cleanup** — Client-side `cleanupObsoleteBringItems()` now detects app-added items by their spec markers (`⚡`/`🟠`/`🛒`) instead of a per-device localStorage map, making cleanup work correctly on all clients including newly logged-in devices. Throttle reduced from 30 minutes to 3 minutes.
- **API fetch caching disabled** — All `api()` calls in the frontend now set `cache: 'no-store'` to prevent stale data from browser cache.
- **Shopping page multi-client sync** — Added 45-second polling on the shopping page so changes made on another device are reflected automatically.



### Added
- **AI price estimation for shopping list** — Each item on the Bring! shopping list now shows an estimated retail price badge (per unit and total). Prices are fetched from Gemini AI and cached server-side for 3 months (`PRICE_UPDATE_MONTHS`). The running estimated total is displayed both in the shopping tab and as a green pill badge on the dashboard stat card.
- **Dashboard price total badge** — The shopping stat card on the dashboard shows a green `ca. €X.XX` badge (top-right, same position as the old urgency badge). It updates in real-time as prices are calculated and persists across navigation via `sessionStorage`.
- **Background price refresh** — Prices are fetched silently every 2 minutes even when not on the shopping tab, keeping the dashboard badge current without user interaction.
- **Smart quantity estimation** — The price payload uses `smart_shopping` data (consumption patterns) to send the correct buy quantity per item; falls back to Bring! spec parsing, then to `qty=1, unit=conf` for manually-added items.

### Fixed
- **`stat-price-total` not visible on dashboard** — The total was only computed when `shoppingItems` was populated (i.e. shopping tab had been visited). Now uses `sessionStorage._pricetotal` as fallback so the badge is visible immediately on any page.
- **Price bar reloading on every tab switch** — `renderShoppingItems` now checks if ALL items are already cached with matching qty/unit; if so, it applies prices from cache instantly with no loading bar or API call.
- **`stat-price-total` real-time update** — Dashboard stat now increments as each individual item is priced (not only after the entire fetch completes).
- **Broken emoji in `log.title`** — Corrupted `\uFFFD` character in `it.json` and `de.json` replaced with `📒`.
- **`PRICE_CACHE_PATH` undefined crash** — Server-side constant was used inside functions that were called before the define; moved define to the very top of `api/index.php` (line 19). Affected: all `get_shopping_price` and `get_all_shopping_prices` calls from 16:33–16:40 on 2026-05-07.

## [1.7.1] - 2026-05-04

### Fixed
- **Destructive actions now require confirmation** — "Butta tutto" (`throwAll`) and "Finisci tutto" (`submitUseAll`) now display a confirmation modal before executing. The modal features a 5-second auto-confirm countdown bar (red) with an "Annulla" cancel button, matching the scale auto-confirm UX pattern already in use.
- **History undo button visibility** — The ↩ undo button in the transaction log was using `color: var(--text-muted)` making it nearly invisible. It now uses a red tint background + border (`#f87171`) with larger font size (1rem) for easy tap targeting.
- **History undo uses custom modal** — `undoTransactionEntry()` previously used the native browser `confirm()` dialog (broken in Android WebView kiosk mode). It now uses the same `_showDestructiveConfirm()` modal with countdown.



### Added
- **Demo mode (JS frontend)** — Full client-side demo experience: Gemini is treated as available, Bring! write operations silently no-op, and a mock pantry + shopping list is shown; activated via `?demo=1` URL param or `.env` `DEMO_MODE=true`; a "DEMO" badge is injected in the header and Settings is hidden to prevent accidental writes
- **Graceful Bring! no-key state** — When Bring! credentials are not configured the shopping tab shows a friendly localised message with a direct link to the Settings page instead of a raw API error
- **Use-quantity guard** — Consuming more than the quantity stocked at the selected location is now blocked before the API call; the quantity input shakes (CSS `input-shake` animation) and a toast shows `use.error_exceeds_stock`
- **Kiosk: smart auto-discovery rewrite** — `autoDiscover()` now uses `ExecutorCompletionService` + `NetworkInterface` (replaces deprecated `WifiManager`), 60 parallel threads, 600 ms TCP pre-check per host, real-time UI feedback every 120 ms, ports `[443, 80, 8080, 8443]`; VPN/cellular interfaces (tun, ppp, rmnet, pdp, ccmni, etc.) are filtered out and `wlan*`/`eth*` interfaces are prioritised
- **Kiosk: permissions button transform** — After permissions are granted, the button changes to "✅ Permessi concessi — Continua →" (green background, dark text) and advances to step 3 on tap, replacing the separate "permissions granted" card
- **Kiosk: gateway auto-pre-configuration** — On successful gateway install `finishSetup()` POSTs `scale_enabled=true` + `scale_gateway_url=ws://127.0.0.1:8765` to the server's `save_settings` endpoint so the webapp is scale-ready immediately after setup
- **Kiosk: ErrorReporter init at setup start** — `SetupActivity.onCreate()` now calls `ErrorReporter.init()` with any previously saved URL, ensuring errors in step 4 (gateway install) are reported even before the user confirms the server URL

### Fixed
- **Kiosk: wrong subnet scanned** — The previous implementation picked up VPN/tun interfaces and scanned a 10.x.x.x range instead of the device's actual Wi-Fi LAN; fixed by filtering interface names and preferring `wlan`/`eth`
- **Kiosk: port 443 missing from discovery** — HTTPS servers were never reachable during auto-discovery; ports list extended to `[443, 80, 8080, 8443]`
- **Kiosk: gateway install status=1 silent failure** — `PackageInstaller.STATUS_FAILURE` (status 1) showed an error card but never called `ErrorReporter`; `ErrorReporter.reportMessage()` is now called with status code, message, and package name
- **Screensaver toggle in web settings** — The screensaver row was missing a `<span class="toggle-slider">` inside the `<span class="toggle-switch">` wrapper, so no slider was rendered; corrected to use the same `toggle-row` / `toggle-switch` / `toggle-slider` structure as all other settings toggles
- **antiwaste.title translation** — IT and DE locale files were missing the `antiwaste.title` key, causing a raw key string to appear in the anti-waste section header; added to both `it.json` and `de.json`

### Kiosk (v1.4.0 → v1.5.0)
- `autoDiscover()` fully rewritten (CompletionService, NetworkInterface, TCP pre-check, real-time feedback, correct LAN subnet)
- Port 443 added to discovery scan
- Permissions button transforms after grant (`onPermissionsGranted()`)
- `ErrorReporter.init()` called at `SetupActivity.onCreate()`
- `ErrorReporter.reportMessage()` called on gateway install failure
- `finishSetup()` pre-configures gateway via `save_settings` API call

## [1.6.0] - 2026-05-03

### Added
- **Dashboard skeleton loading** — Stat cards (Dispensa / Frigo / Freezer) show an animated shimmer placeholder (`…`) instead of the jarring `0` flash that appeared for 3–5 seconds before data loaded; the loading class is applied before the API call and removed atomically when data arrives
- **Webapp startup preloader** — Full-screen spinner overlay during initial app load, fades out after the dashboard is ready
- **Webapp update notification** — A dismissible top banner alerts the user when a newer GitHub release is available (checked once every 6 hours, comparison based on `published_at`)
- **Native Android update banners** — Both Kiosk (v1.4.0) and Scale Gateway (v2.1.0) show a native top bar when a newer APK is available, with one-tap download and install

### Fixed
- **APK install conflict** — Replaced `ACTION_VIEW`-based APK install with the `PackageInstaller.Session` API (API 21+) in both Kiosk and Scale Gateway; the session-based approach correctly handles:
  - `STATUS_PENDING_USER_ACTION` → automatically launches the system confirmation dialog
  - `STATUS_SUCCESS` → success toast
  - `STATUS_FAILURE_CONFLICT` / `STATUS_FAILURE_INCOMPATIBLE` → `AlertDialog` offering to uninstall the old app (signature mismatch) before reinstalling
- **Cooking mode z-index** — Update banner and app header are now hidden when `body.cooking-mode-active` is set, and the cooking overlay z-index was raised to `99998` so it can no longer be obscured by UI chrome
- **Version-aware error reporting** — GitHub Issues are only created when the client is running the latest released version, avoiding noise from stale deployments; non-semver tag names (e.g. `"latest"`) are treated as "always up-to-date"
- **XOR-obfuscated GitHub token** — The PAT used for GitHub API calls is stored as an XOR-encoded hex string in both the PHP backend and Kotlin apps to prevent accidental exposure via secret scanning

### Kiosk (v1.3.0 → v1.4.0)
- FileProvider + `REQUEST_INSTALL_PACKAGES` permission added
- APK download destination moved to `getExternalFilesDir(null)` (no storage permission needed)
- `PackageInstaller` self-update with signature-conflict recovery
- BLE scale gateway update banner with download + install flow

### Scale Gateway (v2.0.0 → v2.1.0)
- Same FileProvider + permission + `PackageInstaller` changes as Kiosk
- Update banner for self-update
- CI workflow now triggers on `develop` branch (in addition to `main`)

## [Unreleased] - 2026-04-30

### Fixed
- **Low-qty banner false positive** — A "suspiciously low quantity" review alert is now suppressed for a partially-used inventory entry when one or more sibling entries for the same product (identified by barcode, or name+brand as fallback) exist in other locations with stock > 0. Prevents noise like "191 ml of milk" when 11 sealed packages are stored in the pantry.

### Changed
- **Non-alarmist expired banner** — Banner icon, CSS class, and title suffix now adapt to the `getExpiredSafety()` level:
  - `ok` (long-life products, freezer within margin): green banner, ✅ icon, "— Scaduto (ancora ok)"
  - `warning` (items that should be inspected): amber/yellow banner, 👀 icon, "— Scaduto (controlla)"
  - `danger` (raw meat, dairy, fish, etc.): unchanged red 🚫 banner and "— Scaduto!" title
- Added `expiry.expired_suffix_ok` and `expiry.expired_suffix_warning` i18n keys to all three language files (IT/EN/DE)
- Added `banner-expired-ok` and `banner-expired-warning` CSS variants (green / amber) in `style.css`

## [1.5.0] - 2026-04-28

### Added
- **Expired banner for opened products** — Products whose opened-product shelf-life has passed (e.g. fridge cream opened 6 days ago) now appear in the top notification banner, not just the dashboard list
- **Safety-aware expired banner** — Each expired banner item shows a contextual safety tip (from `getExpiredSafety()`); danger-level items (fridge dairy/meat/fish) get an intense red banner and "L'ho buttato" as the primary button; safe/warning items keep the original button order
- **AI model fallback** — All Gemini API endpoints (expiry scan, product identification, chat, recipe non-streaming, shopping name classifier) now try `gemini-2.5-flash` first and fall back to `gemini-2.0-flash` automatically, matching the resilience already in place for recipe streaming
- **Friendly AI quota message** — When the AI returns a quota/rate-limit error the user sees "Quota AI esaurita. Riprova tra qualche minuto." instead of the raw API error string
- **Cooking TTS auto-read** — Each recipe step is read aloud automatically when navigating forward or backward; the first step is also read when entering cooking mode
- **Cooking timer 10-second warning** — When a cooking timer reaches 10 seconds the TTS announces "Attenzione! [label]: mancano 10 secondi!"
- **Cooking recipe completion announcement** — "Ricetta completata! Buon appetito!" is spoken via TTS when the last step is confirmed

### Fixed
- **Cooking TTS gate** — `speakCookingStep()` was blocked by the global `tts_enabled` setting; the `_cookingTTS` toggle (🔊/🔇 button) is now the only gate; browser Web Speech API is used by default without requiring TTS configuration in Settings
- **Anomaly dismiss label** — The "La quantità è giusta" button now appends the current inventory quantity, e.g. "La quantità è giusta (2 pz)", so the action is unambiguous
- **i18n sync** — Added `timer_warning_tts`, `recipe_done_tts`, `error.ai_quota` keys to all three language files (IT/EN/DE)


### Added
- **Generic shopping names** — Products are grouped by type ("Latte", "Affettato", "Pasta") rather than brand; computed via an expanded keyword map with Google Gemini AI as fallback for unknown products
- **Bring! auto-migration** — Existing list items with old specific names are silently migrated to generic names on every list load, throttled to once per 10 minutes
- **Bring! catalog coverage** — All 93 shopping_name values now resolve to a German Bring! catalog key (icons and categories in the Bring! app); 24 aliases added to cover previously unmatched names
- **Auto-add to Bring! on depletion** — When a product reaches zero the app adds it to Bring! automatically using the generic shopping name, with the specific product name and brand in the specification field
- **Finished-product confirmation banner** — Instead of silently deleting zero-stock entries, a banner prompts the user to confirm; banner title includes the last 3 digits of the product barcode for easier identification
- **Anomaly detection banner** — Dashboard notifications for suspicious inventory/transaction mismatches and consumption prediction errors, with one-tap inline correction
- **SSE recipe streaming** — Recipe generation streams live via Server-Sent Events; Gemini agent feedback is shown in real time as it is generated
- **Smart alert banners** — Configurable expired-only mode with explanatory messages; banner buttons are fully internationalized

### Fixed
- **Scale double-deduction** — Multiple BLE stable readings of the same weight no longer fire duplicate `inventory_use` events; JS preserves the confirmation sentinel on submit and PHP rejects a second `out` transaction for the same product within 12 seconds
- **Kiosk native TTS** — CI workflow now builds the APK on `develop` branch too; the native Android `TextToSpeech` bridge bypasses Web Speech API voice-availability issues without requiring offline voice packs
- **TTS voice loading** — Retries for up to 10 seconds on page load; shows a message if no voices are available and offers a manual refresh button
- **Bring! migration** — Corrected two bugs: wrong removal API (`DELETE /item` → `PUT remove=item`) and wrong purchase key sent to Bring! (Italian shopping name → German catalog key), which previously created Italian/German duplicate entries
- **Gemini 429 rate limiting** — API calls are retried with exponential backoff; recipe requests are capped at 5 per minute with a dedicated rate-limit bucket

### Performance
- **Gemini calls centralized** — All Gemini API requests go through a single `callGemini()` helper with intelligent backoff; Gemini removed from the product-selection and bringSuggest flows in favour of fast offline logic

## [1.3.0] - 2026-04-18

### Added
- **Expired product banner** — Dashboard notifications for expired products with use, throw away, edit, and dismiss actions
- **Expiring soon banner** — Dashboard notifications for products expiring within 3 days with use, edit, and dismiss actions
- **Priority-sorted notifications** — Banner alerts sorted by urgency: expired > expiring > suspicious quantities > consumption predictions
- **Swipe navigation** — Touch swipe left/right to browse banner notifications, with dot indicators and arrow buttons
- **Quick-access buttons** — Inventory page shows 4 recently used and up to 8 most popular products for quick selection
- **Recent & popular products API** — New `recent_popular_products` endpoint
- **Auto-refresh** — Banner notifications refresh every 5 minutes while on the dashboard
- **Edit from expiry banner** — Correct expiry dates directly from expired/expiring notifications

### Fixed
- **Negative scale values** — BLE scale readings with negative weight are now ignored
- **Banner re-appearing after edit** — Editing from a banner now persists the confirmation so it doesn't reappear on dashboard reload
- **False consumption predictions** — Manual inventory edits (updated_at > last restock) now use the correct baseline for prediction calculations
- **Kiosk overlay blocking header** — Removed injected exit/refresh buttons from the web app header in kiosk mode

## [1.2.0] - 2026-04-13

### Changed
- **Project renamed** from "Dispensa Manager" to **EverShelf**
- Contact email updated to `evershelfproject@gmail.com`
- Docker service, container, and volume renamed to `evershelf`
- SQLite database renamed from `dispensa.db` to `evershelf.db`
- All localStorage keys migrated: `dispensa_*` → `evershelf_*`
- Apache config file renamed to `evershelf.conf`
- CI workflow Docker image/container names updated
- App name updated in all translations (it, en, de)
- Navigation title updated to EverShelf across all languages

### Added
- Version badge (`v1.2.0`) in the app header

### Fixed
- JS file truncation caused by `sed` in-place edit on large files
- Browser cache invalidation via bumped asset version strings (`?v=20260413a`)

## [1.0.0] - 2026-04-10

### Added
- Complete pantry inventory management (Pantry, Fridge, Freezer, Other)
- Barcode scanning with QuaggaJS
- Open Food Facts barcode lookup
- Google Gemini AI integration (product identification, expiry reading, recipes, chat)
- Bring! shopping list integration
- Smart shopping predictions with cron-based caching
- Cooking mode with step-by-step guidance and TTS support
- Opened product tracking with reduced shelf-life calculation
- Vacuum-sealed product support with extended expiry
- Waste vs. consumption tracking (30-day chart)
- Expired product safety assessment by category
- Weekly meal plan configuration
- DupliClick online grocery ordering integration
- PWA support (installable, mobile-first)
- Local database backup script
- Multi-device settings sync via SQLite

### Security
- Centralized `.env` configuration (secrets never in code)
- Removed all hardcoded credentials and personal data
- Input validation on inventory operations
- Parameterized SQL queries throughout
