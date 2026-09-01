# Physical inventory reconciliation / stocktake specification

Status: implemented and validated on `feature/inventory-reconciliation` on 2026-08-30.

Evidence baseline: `upstream/develop` at `d1913374a14b2f6e4db3ce8406adef133e741ec8` (EverShelf v1.7.94).

Implementation notes: the shipped count cards include catalog images and an optional, unchecked expected-zero catalog section below the normal snapshot list. Positive weight/volume remnants remain in the normal count snapshot even when the standard inventory page hides them. The isolated regression suite is `tests/reconciliation_test.php`.

## 1. Problem statement

EverShelf can add, use, waste, delete, move, and manually correct stock, but it has no durable workflow for comparing recorded inventory with a physical count. A stocktake must let a person count shelf-by-shelf without changing live inventory during counting, review discrepancies, apply all approved differences atomically, and retain an audit trail.

This is an additive feature. It must not reorganize EverShelf, reinterpret normal inventory activity, or make existing users participate in reconciliation.

## 2. User workflow

1. Open Inventory and tap Reconcile / Stocktake.
2. Select exactly one configured location.
3. Start a session. EverShelf snapshots expected stock for that location.
4. Walk the location, scanning a barcode or searching/selecting a product.
5. See product, unit, expected quantity, previous count if any, and an actual-quantity input.
6. Save and continue. A saved count changes only the reconciliation session.
7. Pause and resume the same session on any online device.
8. Review counted differences and a separate list of expected products not counted.
9. Explicitly confirm Apply.
10. The server rechecks concurrency, applies all adjustments in one SQLite transaction, and records immutable history.

## 3. Existing inventory model

### Current behavior

- `products` stores catalog identity: unique nullable barcode, name, brand, category, unit, package size (`default_quantity`), and package unit.
- `inventory` stores physical stock rows: `product_id`, free-form validated location key, `quantity REAL`, expiry, added/updated times, user-set-expiry flag, vacuum flag, and opened time.
- Quantity belongs to an inventory row, not directly to the product. A product's quantity at a location is the sum of its rows there; its total stock is the sum across all locations.
- One product can have multiple rows in the same location. `addToInventory()` merges only sealed rows with the same product, location, and expiry. Different expiry dates remain separate lots. Partial consumption can split a sealed row into sealed and opened rows.
- `listInventory()` returns positive rows joined to product metadata and normally hides small depleted crumbs by unit-specific thresholds. Recipe calls can request those residual rows.

### Recommended reconciliation behavior

Count at the product-plus-location aggregate because a barcode cannot identify a lot and a walking stocktake should not require lot-by-lot data entry. Preserve a row-level snapshot behind each aggregate so apply can detect concurrent changes and allocate a negative adjustment deterministically without destroying expiry/opened metadata.

Expected quantity must include every positive row at the location, including rows the normal inventory UI hides as depleted crumbs. The count UI should indicate when multiple rows/lots contribute to the total.

### Minimum implementation required

- Snapshot each contributing inventory row and its quantity/metadata at session start.
- Store one count item per session/product with an aggregate expected quantity and nullable counted quantity.
- Do not add uniqueness to the existing `inventory` table or merge existing rows.

## 4. Existing quantity-change flow

### Current behavior

| Operation | Frontend | API handler | Database effect |
|---|---|---|---|
| Add stock | add/scan product flow in `assets/js/app.js` | `inventory_add` → `addToInventory()` | Merge same sealed lot or insert row; add `transactions.type='in'`; shopping side effects |
| Consume/use | `quickUse()`, use page, recipe flows | `inventory_use` → `useFromInventory()` / `useFromInventoryCore()` | Deduct selected/opened row, possibly split opened stock; add `out`; depletion/shopping/health side effects |
| Waste/discard | `_inventoryWaste()`, inventory swipe/delete choices | `inventory_use` with `Buttato|reason` notes | Same deduction path; add `waste`; waste-learning side effects |
| Edit/correct | `editInventoryItem()` / `submitEditInventory()` | `inventory_update` → `updateInventory()` | Update row in an IMMEDIATE transaction; quantity delta writes `[Manual correction]` in/out transaction |
| Move | inventory edit | `inventory_update` | Change row location; paired `[Spostamento]` out/in transactions |
| Delete row | delete/discard UI | `inventory_delete` → `deleteInventory()` | Add out transaction for remaining quantity, then delete row |

`updateInventory()` already demonstrates `dbWithRetry()`, `dbBeginImmediate()`, `dbCommit()`, and `dbRollback()`. Normal API requests retry `database_busy` responses up to three times.

### Recommended reconciliation behavior

Do not call the public add/use/update handlers once per item. Their shopping, consumption, health, opened-package, and network side effects are wrong for an administrative correction, and multiple HTTP calls cannot provide atomic apply.

Create a reconciliation-specific server function that applies row changes and adjustment history directly inside one IMMEDIATE transaction. Reuse existing validation, PDO helpers, unit tolerances, cache invalidation, and response conventions—not the normal business side effects.

### Minimum implementation required

- One dedicated apply handler operating on all counted variances.
- Direct, narrowly scoped inventory row updates plus separate adjustment audit records.
- One smart-shopping cache invalidation after commit; no Bring!, consumption, waste-learning, recipe, or health logging side effects in MVP.

## 5. Existing barcode flow

### Current behavior

The main scan page runs `initScanner()`, which requests the camera and selects native `BarcodeDetector`, local ZBar WASM, local digit OCR, or legacy Quagga. `_tryConfirmBarcode()` validates/confirms a code, `_finalizeBarcode()` stops the active decode generation, and `onBarcodeDetected()` calls `_resolveBarcodeLookup()`.

Resolution first checks session/local browser caches, then `resolve_barcode`. The backend checks local products and external barcode sources. `_handleBarcodeResolve()` loads an existing product or background-saves an externally resolved product. A not-found code currently shows the manual-entry error and resumes scanning. The scan page also supports typed EAN and quick name search through `products_search`.

### Recommended reconciliation behavior

Reuse the entire camera/decode/checksum engine and existing local/backend product resolution. Add a tiny mode-aware result hook before the normal add/shopping result handler. The hook should pass a resolved product to reconciliation without invoking add/use behavior.

### Minimum implementation required

- A narrow barcode-consumer registration or reconciliation-mode conditional in `assets/js/app.js`.
- Reconciliation-specific handling in a new frontend file.
- No scanner fork, new camera request, new decoder, or duplicate barcode API.

## 6. Existing location model

### Current behavior

Locations are strings stored on inventory and transaction rows. Built-ins are `dispensa`, `frigo`, `freezer`, and `altro`. Custom display names are configured through `CUSTOM_LOCATIONS`, normalized to `custom_*` slugs, exposed through settings, and accepted by `validInventoryLocations()`. The frontend rebuilds location controls from the same built-ins plus configured custom locations.

### Recommended reconciliation behavior

A session covers exactly one immutable location key. Present built-ins through i18n and custom locations through their saved labels. Validate the selected key through `validInventoryLocations()` at start; preserve the label used for display without replacing the canonical key.

### Minimum implementation required

Reuse `validInventoryLocations()`, `inventoryCustomLocationMap()`, and frontend `LOCATIONS`. Store the canonical location on the session. Do not create a locations table.

## 7. Existing transaction model

### Current behavior

`transactions` is an append-oriented activity ledger with `in`, `out`, or `waste`, positive `quantity REAL`, location, notes, `undone`, and timestamp. It drives the History UI, anomaly/ghost calculations, consumption statistics, predictions, shopping suggestions, and undo. Some queries exclude paired `[Spostamento]` notes, but most interpret all out/waste as consumption and all in as purchasing/restocking.

### Recommended reconciliation behavior

Do not encode reconciliation deltas as ordinary in/out transactions: a shelf correction is neither consumption nor purchase and would corrupt statistics and shopping predictions. Add an `inventory_adjustments` audit table with a signed delta and reconciliation linkage. Where existing code computes ledger-expected stock for anomaly/ghost purposes, include the signed sum of adjustments; leave consumption statistics on `transactions` unchanged.

### Minimum implementation required

- Add adjustment history rather than changing the `transactions` CHECK constraint.
- Narrowly update the ledger-balance/anomaly/ghost queries that compare transactions to live stock.
- Keep the existing transaction list and undo behavior unchanged. Reconciliation history lives on the reconciliation page in MVP.

## 8. Existing undo/correction behavior

### Current behavior

Manual row edits write compensating in/out transactions with `[Manual correction]`. `transaction_undo` reverses a transaction within 24 hours, writes a counter-transaction, and marks the original undone. It assumes in/out/waste semantics and may recreate a generic row without expiry when restoring stock. Inventory anomaly banners compare live product totals to the transaction ledger and offer editing/dismissal, not a session-based count.

### Recommended reconciliation behavior

Existing undo is not semantically safe for a multi-item atomic stocktake. Applied sessions and their adjustments should be immutable. A mistaken stocktake is corrected through a new reconciliation, preserving both historical states. A future explicit reversal may create a new linked inverse session, never delete or rewrite the applied one.

### Minimum implementation required

- Exclude reconciliation adjustments from `transaction_undo` by keeping them in a separate table.
- Show applied history read-only.
- Defer one-click reversal from MVP.

## 9. Existing reusable functionality

- Camera acquisition, barcode decoders, checksum validation, vibration, pause/resume, and scan status UI.
- `resolve_barcode`, local product resolution, browser barcode caches, `products_search`, and quick name/manual product selection.
- Product/unit formatting: `formatQuantity()`, `formatQuantityParts()`, and unit labels.
- Dynamic locations: backend validation and frontend `LOCATIONS` metadata.
- `api()` authentication, CSRF headers, error parsing, and database-busy retry conventions.
- SQLite WAL, busy timeout, `dbWithRetry()`, and IMMEDIATE transaction helpers.
- Existing modal, toast, page navigation, touch control, translation, and CSS token conventions.
- `invalidateSmartShoppingCache()` after committed inventory changes.

Reuse does not mean calling normal inventory mutation endpoints during apply; it means sharing their validated primitives and conventions without importing unrelated side effects.

## 10. Proposed reconciliation workflow

Start snapshots all positive stock in one location. The session screen offers Scan and Search. Selecting a product opens a large count card with expected quantity, unit, actual input, variance preview, Save & Continue, and Skip. A repeat scan focuses the saved item instead of adding quantity. Search may select an expected product, a catalog product with expected zero, or route to the existing create-product flow.

Review has three groups: differences, counted with no difference, and not counted. Apply summarizes item count, positive/negative net adjustments, and uncounted items that will remain unchanged. A final explicit confirmation sends one apply request.

## 11. Session lifecycle

Use four states:

```text
in_progress -> review -> applied
      |           |
      +---------> cancelled
```

- `in_progress`: counts may be created/edited; no live inventory effect.
- `review`: explicit review checkpoint. Editing a count returns the session to `in_progress`.
- `applied`: immutable terminal state; apply timestamp and adjustments exist.
- `cancelled`: immutable terminal state; no adjustments exist.

Only one nonterminal session per location is recommended. Starting again should offer Resume or Cancel, not silently create competing sessions.

## 12. Proposed data model

Additive tables (names are recommendations):

### `inventory_reconciliations`

- `id INTEGER PRIMARY KEY`
- `location TEXT NOT NULL`
- `status TEXT NOT NULL CHECK (...)`
- `started_at`, `reviewed_at`, `applied_at`, `cancelled_at`
- optional `notes TEXT`

Partial unique index: one row per location where status is `in_progress` or `review`.

### `inventory_reconciliation_items`

- `id INTEGER PRIMARY KEY`
- `reconciliation_id` foreign key
- `product_id` foreign key
- `expected_quantity REAL NOT NULL`
- `counted_quantity REAL NULL`
- `counted_at`, `updated_at`
- unique `(reconciliation_id, product_id)`

`NULL` counted quantity means uncounted; numeric zero means counted and physically absent.

### `inventory_reconciliation_item_rows`

- `item_id` foreign key
- `inventory_id` snapshot identifier
- `expected_quantity REAL NOT NULL`
- snapshot `expiry_date`, `opened_at`, `updated_at`, and location

This table preserves the row composition behind the aggregate and supplies a concurrency fingerprint.

### `inventory_adjustments`

- `id INTEGER PRIMARY KEY`
- `reconciliation_id` and `reconciliation_item_id` foreign keys
- `product_id` and nullable affected `inventory_id`
- signed `delta REAL NOT NULL`
- `before_quantity`, `after_quantity`
- `created_at`

An item may have multiple adjustment rows when a negative variance spans lots. Sum of adjustment deltas equals counted minus expected.

## 13. Proposed API additions

Use existing `api/index.php` routing and authentication conventions, with handlers implemented in a new `api/lib/reconciliation.php`:

| Action | Method | Purpose |
|---|---|---|
| `reconciliation_list` | GET | List/resume sessions and compact applied history |
| `reconciliation_get` | GET | Session, items, progress, variance, row snapshot, history |
| `reconciliation_zero_items` | GET | Optional catalog products with no positive stock at the session location |
| `reconciliation_start` | POST | Validate location and atomically create snapshot |
| `reconciliation_count` | POST | Upsert nullable-to-number actual count for one product |
| `reconciliation_review` | POST | Move a session to review and return summary |
| `reconciliation_apply` | POST | Concurrency check and atomic idempotent apply |
| `reconciliation_cancel` | POST | Cancel a nonterminal session without inventory writes |

All POST actions join the existing CSRF write-action list. IDs are integers; quantities are finite, nonnegative REAL values within the existing upper bound. Error codes should distinguish `not_found`, `invalid_state`, `inventory_changed`, `already_applied`, `database_busy`, and validation errors.

## 14. Proposed frontend additions

- Add a Reconcile action to the Inventory page header or directly below the location tabs; do not add another bottom-nav item.
- Add a single `page-reconciliation` section to `index.html` covering location choice, count, review, and history states.
- Put feature logic in `assets/js/reconciliation.js`; expose only small page-init and barcode-result functions.
- Put feature styling in `assets/css/reconciliation.css`, using existing CSS variables and touch dimensions.
- Add one `showPage()` and one `refreshCurrentPage()` hook.
- Reuse existing modal/toast/loading/quantity/location/product helpers.
- Add translation keys to all current locale files, with Italian remaining the completeness baseline.

## 15. Scanner reuse strategy

### Current behavior

One shared scan page owns camera lifecycle and dispatches every confirmed code to the normal product action/shopping flow.

### Recommended reconciliation behavior

Register a temporary barcode result consumer when reconciliation launches scanning. `_finalizeBarcode()` and all decode engines remain untouched; `onBarcodeDetected()` delegates the confirmed code to the active consumer, which calls existing barcode resolution and returns to the count card. Unregister on exit so normal scanning resumes exactly as before.

### Minimum implementation required

A few lines in `assets/js/app.js` for consumer registration/dispatch and isolated reconciliation code in the new file. No changes under `assets/vendor/`, no second video element, and no second barcode API.

## 16. Location behavior

### Current behavior

Inventory can span built-in and configured custom string locations; normal use may fall back to stock in another location and preferentially consume opened stock anywhere.

### Recommended reconciliation behavior

Session scope is one fixed location. Reconciliation apply must never use normal cross-location fallback and must never alter another location. A separate session is required for each location.

### Minimum implementation required

Filter the snapshot and every apply query by the session's location. Reject attempts to pass a different location on count/apply requests.

## 17. Expected quantity semantics

### Current behavior

The authoritative live quantity is the sum of `inventory.quantity` rows. Transaction-derived expected balance is diagnostic and can diverge due to historical/manual corrections.

### Recommended reconciliation behavior

Expected quantity means the live row sum at session start for the selected product and location—not transaction-ledger balance and not package count inferred from `default_quantity`. Preserve the exact REAL value and its row composition.

### Minimum implementation required

Compute and store the snapshot in one read transaction at start. Display using the product's current unit formatter without rounding the stored value.

## 18. Counted quantity semantics

### Current behavior

EverShelf accepts REAL quantities; `pz` and `conf` may be fractional, and weight/volume units use the same base-unit quantity stored in inventory.

### Recommended reconciliation behavior

Counted quantity is the actual total in the same stored unit and same location as expected. It is entered explicitly; scanning identifies a product but never implies `+1`.

### Minimum implementation required

Use a nonnegative finite numeric input with `step="any"`, unit label, large increment/decrement controls where useful, and server-side range validation.

## 19. Zero vs uncounted

### Current behavior

Normal inventory APIs tend to omit zero rows, while form defaults can blur missing input and numeric zero.

### Recommended reconciliation behavior

Use database `NULL` and JSON `null` for uncounted. Use numeric `0` only after the user explicitly saves zero. Show distinct “Not counted” and “Counted: 0” states.

### Minimum implementation required

Never default the actual input to zero, never coalesce null to zero, and test zero across save, reload, review, and apply.

## 20. Items not counted

### Current behavior

There is no session completeness concept. Existing inventory remains until a normal mutation changes it.

### Recommended reconciliation behavior

Uncounted means untouched. Review must list uncounted expected products and the final confirmation must state that they will remain unchanged. Do not interpret absence of a scan as physical zero.

### Minimum implementation required

Apply only items with non-null counted quantity. Defer any “mark all remaining zero” bulk action.

## 21. Concurrent inventory changes

### Current behavior

SQLite WAL/busy timeout protects database writes, but a long-running client workflow has no optimistic version check. Normal inventory can change while a stocktake is open.

### Recommended reconciliation behavior

Use fail-closed optimistic concurrency. At apply, under `BEGIN IMMEDIATE`, compare every counted item's current row set, quantities, and `updated_at` values at the session location with its snapshot. If any differ, roll back with `inventory_changed`, list the conflicts, and require recount/refresh. Do not silently rebase the user's physical count.

### Minimum implementation required

Store row snapshots, requery counted products inside the apply transaction, and return structured conflict details. Uncounted products are untouched and need not block apply unless product deletion breaks referential integrity.

## 22. Duplicate barcode scans

### Current behavior

Scanner confirmation stops duplicate frame decoding, and normal use has server-side recent-transaction deduplication. There is no session-item duplicate concept.

### Recommended reconciliation behavior

A repeat scan opens the existing session item, displays its saved actual quantity, and provides Edit / Keep & Continue. It must not increment automatically or create a duplicate item.

### Minimum implementation required

Unique `(session, product)` storage plus frontend lookup/focus and a small duplicate-scan toast/haptic cue.

## 23. Unknown barcodes

### Current behavior

`resolve_barcode` tries local, catalog, and external sources. Externally found products may be saved to the catalog. A truly unknown code shows a manual-entry error and scanning resumes; the scan page offers quick name and full product creation.

### Recommended reconciliation behavior

Reuse the same resolution chain. If external data identifies the product, let existing catalog save complete, then add a session item with location expected quantity (possibly zero). If unresolved, offer Search by name, Create product with this barcode, or Continue; creating a catalog product still must not create live inventory.

### Minimum implementation required

Mode-specific post-resolution UI only. Reuse `product_save`, product form, and quick search rather than inventing reconciliation catalog endpoints.

## 24. Products without barcodes

### Current behavior

`products_search` searches name, brand, and barcode. The scan page's quick name entry can select existing products or create one without a barcode.

### Recommended reconciliation behavior

Provide a persistent Search / Select button beside Scan. Prefer expected items in the selected location, then catalog matches. Selecting a catalog-only product creates a count item with expected zero.

### Minimum implementation required

Reuse `products_search` and existing product rendering/escaping. Do not require assigning a barcode.

## 25. Fractional quantities and units

### Current behavior

Quantities and transactions are REAL. Units include pieces, packages, grams, kilograms, millilitres, and litres. Package size affects display/opened-package logic but does not change the stored quantity's unit.

### Recommended reconciliation behavior

Preserve the product's stored unit and allow decimals. Calculate variance as `round(counted - expected, 6)` server-side to control floating-point noise, treat absolute differences at or below `0.001` as no change, and format only at the UI boundary.

### Minimum implementation required

REAL columns, `step="any"`, finite/nonnegative validation, unit labels, six-decimal normalization, and tests for pieces, fractional packs, grams, and litres.

## 26. Apply behavior

For each counted item, compute `delta = counted - expected` after the concurrency check.

- `delta = 0`: record the count but create no adjustment.
- Negative delta: reduce only snapshot rows in the session location, ordered opened first, then nearest non-null expiry, then oldest row/id. Set exhausted rows to zero or delete only if existing conventions require; preserving zero until post-apply cleanup is lower risk.
- Positive delta with one current row: add to that row.
- Positive delta with multiple current rows, or no current row: insert a new row at the session location with null expiry/opened metadata so existing lot metadata is not falsified.

Record every affected row before/after and delta. Do not call consumption, waste, shopping-add, health, or HA webhook paths. Invalidate smart-shopping cache once after commit.

The positive-variance lot policy is a recommended MVP rule and remains a product decision (Q01).

## 27. Atomicity

`reconciliation_apply` runs through `dbWithRetry()` and `dbBeginImmediate()`. Inside that transaction it:

1. fetches and locks the nonterminal session through SQLite's write lock;
2. verifies state and concurrency for every counted item;
3. applies every row mutation;
4. inserts every adjustment audit row;
5. marks the session applied with a timestamp;
6. commits.

Any validation, conflict, or write failure calls `dbRollback()`. External/cache side effects occur only after commit and must be safe to retry.

## 28. Idempotency / double-submit protection

The session ID is the idempotency key. Inside the IMMEDIATE transaction, apply only when status is `review` and `applied_at IS NULL`. The state transition and adjustments commit together.

- A double tap serializes on SQLite; the second request sees `applied` and returns the original applied summary.
- A retry after a lost success response also returns the applied summary without new writes.
- An `in_progress`, `cancelled`, or invalid session returns `invalid_state`.
- A unique constraint on adjustment identity (for example reconciliation item plus affected row plus sequence) provides defense in depth.

## 29. Audit/history

Applied session history shows location, timestamps, counts, expected, actual, variance, and affected row before/after values. History is read-only and does not expose environment or infrastructure data.

The normal transaction log remains consumption/purchase history. Reconciliation history is rendered within the reconciliation feature so existing log semantics and undo controls remain unchanged.

Ledger diagnostics calculate expected stock as normal in minus out/waste plus signed reconciliation adjustments.

## 30. Reversibility

Do not offer existing 24-hour transaction undo for reconciliation. Applied data and adjustment records are immutable. MVP correction is a new stocktake based on current state. A later “reverse” action, if required, must create a new linked inverse reconciliation under the same concurrency and atomicity rules.

## 31. Pause/resume

Counts are saved server-side after each Save & Continue. Leaving the page, closing the PWA, or changing devices does not cancel the session. Starting a stocktake for a location with an open session offers Resume. The UI shows progress as counted expected items plus any newly found products.

Only one open session per location avoids ambiguity. Cancellation requires confirmation and retains the cancelled session summary without adjustments.

## 32. Offline/PWA implications

### Current behavior

The service worker caches the app shell. `assets/js/app.js` keeps inventory/product/settings caches and queues selected inventory/shopping POST actions in localStorage. Barcode resolution can find cached products offline. Queue replay is ordered but has no cross-operation transaction, server-issued session version, or robust apply idempotency for reconciliation.

### Recommended reconciliation behavior

MVP reconciliation is online-only. The scanner may decode offline, but starting, saving, reviewing, and applying a session require a server connection. If connectivity is lost, retain the visible unsaved input, show a clear offline message, and do not place reconciliation writes in the generic offline queue.

### Minimum implementation required

- Do not add reconciliation actions to `QUEUEABLE`.
- Disable Start/Save/Apply while `_offlineMode` is true.
- Add the new JS/CSS shell files and bump the service-worker cache version so the online-capable UI updates reliably.

## 33. Migration strategy

Follow the existing repeated-startup migration style in `migrateDB()`:

- `CREATE TABLE IF NOT EXISTS` for the four additive tables.
- `CREATE INDEX IF NOT EXISTS`, including session status/location and item uniqueness.
- Add no columns, constraints, renames, or rebuilds to existing tables for MVP.
- Keep foreign keys consistent with current product/inventory deletion behavior; preserve historical product labels if product deletion could otherwise remove audit meaning.
- Older code safely ignores the new tables. Rolling code back leaves inert tables/history in place; rollback must not drop data.

Migration tests must run twice against a copy of a current database and once against a fresh empty database.

## 34. Backward compatibility

Users who never open reconciliation see only a small additive Inventory action. Existing API responses, routes, normal transactions, scanner behavior, locations, inventory mutations, PWA, and database rows retain their meaning. No new dependency or configuration value is required.

Feature-specific tables and files are additive. Existing clients do not need to understand adjustment history. Reconciliation actions use current auth/CSRF protections.

## 35. Validation/testing strategy

### Current behavior

CI lints all API PHP, checks `assets/js/app.js` syntax, validates translation JSON/completeness, builds an image, starts it, and curls the homepage. There is no dedicated inventory test suite; `scripts/test-shopping-guards.php` is unrelated and can touch cache state.

### Recommended reconciliation behavior

Add focused PHP tests around reconciliation functions using an isolated temporary SQLite database/PDO, never the live `/appdata` database. Keep handler logic thin so lifecycle, zero/null, conflicts, allocation, atomic rollback, and repeat apply can be tested without HTTP. Add lightweight JavaScript syntax validation and manual UI testing rather than introducing a test framework solely for this feature.

### Minimum implementation required

- Existing PHP/JS/translation/Compose checks.
- Fresh and upgraded migration tests, including repeat migration.
- Session start/count/reload/cancel/apply tests.
- Forced mid-apply failure proving rollback.
- Two apply calls proving one adjustment set.
- Concurrent normal mutation proving conflict/no inventory write.
- Aggregate multi-row, zero, fractional, unknown/new product, and uncounted cases.
- Browser mobile-width smoke test and real-phone test before acceptance.

## 36. Regression checklist

- [ ] Existing database opens; `PRAGMA quick_check` passes.
- [ ] App and PWA start/update normally.
- [ ] Dashboard and Inventory list render all locations.
- [ ] Add inventory merges only matching sealed lots.
- [ ] Consume/use, use-all, opened-package split, and depletion still work.
- [ ] Waste/discard and reasons still work.
- [ ] Manual edit/correction and location move still write expected transactions.
- [ ] Barcode camera, typed EAN, lookup cache, external lookup, and unknown-code fallback work outside reconciliation.
- [ ] Shopping scan and normal scan modes resume correctly.
- [ ] Products without barcodes remain searchable/selectable.
- [ ] Built-in and custom locations remain valid.
- [ ] Transaction History and 24-hour undo remain unchanged.
- [ ] Anomaly/ghost ledger math includes applied adjustment deltas without counting them as consumption.
- [ ] Stats, predictions, waste, shopping, and health data exclude reconciliation deltas from ordinary activity.
- [ ] Existing inventory rows, expiry/opened metadata, database backup, and restore remain intact.
- [ ] Migrations are safe on fresh and existing databases and on repeated startup.
- [ ] Users who never reconcile observe no changed normal workflow.

## 37. Real-mobile test plan

Using the existing trusted LAN HTTPS deployment:

1. Open both `https://masterserver.home` and the approved IP URL on the phone; verify no certificate warning.
2. Start a location session and invoke the real rear camera.
3. Scan a real known barcode, verify expected quantity/unit, enter an actual count, and save.
4. Scan the same code again and verify it edits rather than increments/duplicates.
5. Search and count a product without a barcode.
6. Save explicit zero and distinguish it from an uncounted item.
7. Pause/close/reopen and resume the session.
8. Review differences and confirm uncounted items remain untouched.
9. Apply once, simulate/retry a second tap, and verify only one adjustment set.
10. Verify resulting live inventory, reconciliation history, normal inventory/history screens, and camera use afterward.

Desktop-only testing is insufficient.

## 38. Upstream mergeability

- Branch from `develop`; target an upstream PR to `develop`.
- Keep generic names and behavior; no household, hostname, IP, Caddy, or fork branding.
- Keep `docker-compose.dev.yml`, `AGENTS.md`, and `docs/bootstrap-log.md` out of the upstream feature PR unless the maintainer explicitly wants them.
- Use additive migrations and no new dependencies.
- Isolate new PHP/JS/CSS while keeping router, navigation, scanner, service-worker, and translation hooks narrow.
- Split the eventual implementation into reviewable milestones rather than one large rewrite.

## 39. Risks

| Risk | Mitigation |
|---|---|
| Long session overwrites newer inventory | Row-level snapshot plus fail-closed apply conflict |
| Aggregate count loses lot identity | Preserve row snapshot; deterministic negative allocation; isolate positive surplus row |
| Reconciliation pollutes consumption/purchase stats | Separate signed adjustment table |
| Double apply | Status guard under IMMEDIATE transaction and idempotent response |
| Zero mistaken for uncounted | Nullable counted quantity and explicit UI states |
| New feature duplicates scanner | One temporary result-consumer hook |
| Offline queue replays stale counts | Online-only MVP; never queue reconciliation actions |
| Large changes to monolithic files | New feature files and minimal integration hooks |
| Existing ledger diagnostics flag correct stock | Include signed adjustments only in stock-balance queries |
| Applied mistake has no casual undo | Immutable history and new corrective stocktake; optional inverse session later |

## 40. Open questions

- **Q01:** Confirm positive-variance allocation: add to a sole row, otherwise create a null-expiry adjustment row. Recommended for MVP.
- **Q02:** Should Review require every expected item to be counted, or allow apply with explicit uncounted-is-unchanged confirmation? Recommended: allow the latter.
- **Q03:** Is an explicit inverse-session reversal required at launch? Recommended: defer; correct with a new stocktake.
- **Q04:** Should reconciliation history later be merged into the normal History page? Recommended: keep separate for MVP.
- **Q05:** When external barcode lookup finds a product during a count, should catalog creation remain automatic as it is today? Recommended: reuse current behavior and make it clear that stock is not added until apply.

## 41. MVP scope

- One online session per location.
- Snapshot all positive rows, aggregate by product, retain row detail.
- Camera scan reuse plus search/select fallback.
- Explicit nullable counts with fractional support.
- Pause/resume, review, uncounted untouched, cancel.
- Atomic concurrency-checked, idempotent apply.
- Separate adjustment history and ledger-balance integration.
- Applied history page, i18n, mobile-first styling, isolated tests, and real-phone acceptance.

## 42. Deferred features

- Offline session creation/count/apply and offline conflict merging.
- Multi-location sessions.
- Bulk “uncounted = zero”.
- User/accounts, assignments, approvals, and role permissions.
- Blind count mode that hides expected quantity.
- Cycle-count schedules, reminders, printable sheets, CSV stocktake import/export.
- One-click inverse/reversal sessions.
- Lot-by-lot or expiry capture for positive variance.
- Analytics dashboards and normal History-page unification.

## 43. Acceptance criteria

- Counting, cancelling, and reviewing never modify live inventory.
- A session is scoped to one valid built-in or custom location.
- Known barcode, duplicate barcode, unknown barcode, and no-barcode flows behave as specified using existing scanner/search infrastructure.
- Zero and uncounted remain distinct through reload and apply.
- Fractional quantities retain precision and correct unit display.
- Uncounted expected items remain unchanged.
- Concurrent counted-product changes block the entire apply with no partial writes.
- Apply is atomic and a retry/double tap cannot duplicate adjustments.
- Applied history preserves expected, actual, variance, and row before/after data.
- Ordinary transactions, consumption/waste stats, shopping, undo, and scanner behavior remain unchanged.
- Fresh/existing/repeated migrations pass; PHP/JS/translations pass project checks.
- Real-phone HTTPS camera flow passes end-to-end.

## 44. Change-impact map

### Existing files expected to require narrow modification

| File | Why / exact responsibility | Approximate scope | Could most code live elsewhere? | Existing behavior at risk |
|---|---|---:|---|---|
| `api/database.php` | Add tables/indexes in migration | 50–90 additive lines | No; schema convention is here | Startup/migrations |
| `api/bootstrap.php` | Require reconciliation library | 1 line | Yes, all logic in new file | API bootstrap |
| `api/index.php` | Add CSRF action names and router cases; adjust only ledger-balance queries/helpers if needed | 20–60 lines plus focused balance expressions | Yes | Routing, anomaly/ghost math |
| `index.html` | Inventory entry button, reconciliation page, new CSS/JS includes | 80–150 additive lines | Logic/style yes | Page navigation, app shell |
| `assets/js/app.js` | Page init/refresh hook and temporary scanner result-consumer hook | 10–30 lines | Yes | Normal scan dispatch/navigation |
| `sw.js` | Cache-version bump and new shell assets | 3–6 lines | No | PWA update/offline shell |
| `translations/it.json` | Base reconciliation keys | Additive keys only | No | Completeness baseline |
| `translations/en.json`, `de.json`, `fr.json`, `es.json`, `zh.json` | Matching localized keys | Additive keys only | No | Translation completeness |

Prefer a new `assets/css/reconciliation.css`; no change to `assets/css/style.css` should be necessary beyond a shared utility only if proven missing.

### New upstream-candidate files

- `api/lib/reconciliation.php`: persistence, validation, lifecycle, concurrency, allocation, apply, and history handlers.
- `assets/js/reconciliation.js`: page controller, count/review UI, scan/search integration.
- `assets/css/reconciliation.css`: mobile-first isolated styles.
- Focused reconciliation tests under the project's existing `scripts/` convention unless upstream prefers a new `tests/` directory.

### Important files expected to remain untouched

- `Dockerfile`, upstream `docker-compose.yml`, `.htaccess`, `package.json`, and `CHANGELOG.md` during initial implementation.
- Scanner vendor code under `assets/vendor/` and `assets/js/core/`.
- Normal inventory add/use/waste/edit functions except a proven shared helper extraction that reduces—not expands—the diff.
- Recipe, shopping, AI, Home Assistant, Health Bridge, kiosk, MCP, Caddy, and deployment configuration.

### Fork-local files not automatically included upstream

- `docker-compose.dev.yml`
- `AGENTS.md`
- `docs/bootstrap-log.md`

## Decision register

| ID | State | Decision |
|---|---|---|
| D01 | CONFIRMED | EverShelf remains self-hosted and Docker remains the runtime. |
| D02 | CONFIRMED | PHP, Apache, SQLite/PDO, vanilla JavaScript, HTML, CSS, PWA, scanner, and i18n remain the architecture. |
| D03 | CONFIRMED | Feature work originates from `develop`; potential upstream PRs target `develop`. |
| D04 | CONFIRMED | Reconciliation is the first custom feature and is session-based. |
| D05 | CONFIRMED | Counting never immediately modifies live inventory. |
| D06 | CONFIRMED | Live inventory changes only on explicit Apply. |
| D07 | CONFIRMED | Existing barcode scanning is reused. |
| D08 | CONFIRMED | Mobile simplicity and large touch targets are priorities. |
| D09 | CONFIRMED | Existing EverShelf code changes only where required; no unrelated refactor. |
| D10 | CONFIRMED | The feature remains generic and suitable for possible upstream adoption. |
| D11 | CONFIRMED | Users who never reconcile retain essentially unchanged behavior. |
| D12 | RECOMMENDED | Each session covers exactly one location. |
| D13 | RECOMMENDED | Count product/location aggregates while preserving row-level snapshots. |
| D14 | RECOMMENDED | `NULL` is uncounted; numeric zero is an explicit physical count. |
| D15 | RECOMMENDED | Uncounted expected items remain unchanged. |
| D16 | RECOMMENDED | Any concurrent change to a counted product blocks the entire apply. |
| D17 | RECOMMENDED | Reconciliation uses a separate signed adjustment audit table, not ordinary in/out/waste transactions. |
| D18 | RECOMMENDED | Applied sessions are immutable; MVP corrections use a new session. |
| D19 | RECOMMENDED | Offline reconciliation is deferred; actions are never added to the generic offline queue. |
| D20 | RECOMMENDED | Duplicate scans reopen the saved count and never auto-increment. |
| D21 | RECOMMENDED | Apply has no consumption, waste, Bring!, health, or HA side effects. |
| D22 | ASSUMPTION | Product catalog creation during barcode resolution may continue, provided inventory stays unchanged until Apply. |
| D23 | TBD | Confirm the positive-variance lot allocation policy in Q01. |
| D24 | TBD | Confirm whether Review may apply with uncounted items explicitly left unchanged. |
