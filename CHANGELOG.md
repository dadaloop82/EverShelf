# Changelog

All notable changes to EverShelf will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.29.5] - 2026-10-31

### Changed
- **SHELFY acts where you are.** “Ho mangiato una mela” no longer navigates to the legacy
  full-page *Usa / Consuma* (← Indietro, the purple recipe button, “Tutte una parte”): the
  sheet closes, a floating card shows what will be registered with a 10 s bar draining
  right→left, **Modifica** stops the clock and opens the compact product sheet at that
  quantity, **✕ Annulla** writes nothing. Emptying a row still keeps its slide-to-confirm,
  on that same sheet.
- **Countdowns show their seconds.** Every SHELFY countdown prints the remaining time on
  the control (`.shelfy-arm-count`) and the fill is the clock (mint, right→left).
- **A face for the assistant.** The centre of the bottom nav, the capture sheet, the intro
  and the confirmation card show SHELFY's avatar (`assets/img/shelfy/`, transparent PNG,
  in the service-worker shell).
- **“…alla spesa” is a shopping line.** A new `shop` intent in the vocabulary data:
  *Aggiungi le uova alla spesa* adds to the list, *Aggiungi il latte* still stocks the pantry.
- **Natural example chips** in the capture sheet and the intro, in all six locales.
- **Tablet / kiosk CSS** (`tablet.css`) activates on `html.is-kiosk` / `html.is-touch-tablet`
  instead of a bare `min-width`, so desktop monitors keep the base layout.

### Fixed
- **`&quot;` in titles.** `productTitleDecodeEntities()` and the client's
  `decodeHtmlEntities()` decode repeatedly (double-escaped `&amp;quot;` included), and a
  migration repairs rows already stored escaped (`migration_decode_title_entities_v1`),
  so the pantry and the history stop printing the raw entity.
- **History: the ↩ confirm is emerald for a restock.** Undoing a consumption puts stock
  back — it is no longer painted danger-red, and it no longer fires on a countdown.
- **`1/pz` in a toast.** `showToast()` normalises a stray slash between a number and its unit.
- **`scripts/bump-version.sh`** now rewrites the `sw.js` CACHE when the stamp suffix grew
  past one letter (`[a-z]*`) — it silently skipped the line before, leaving clients on the
  previous cache.

## [1.29.4] - 2026-10-29

### Changed
- **Splash preloader (startup audit).** Three readable phases (`system` → `pantry` → `ready`)
  paced ~380 ms each (~1.2 s floor); household language instead of PHP/files/DB jargon; light
  canvas + soft cross-fade (no dark→white flashbang); free-API logos removed from splash
  (quiet Open Data line; full credits stay in Settings).

## [1.29.3] - 2026-10-29

### Changed
- **Food “Usa” sheet restored.** Typed quantity (e.g. `2.5`), live scale fill for g/ml, and
  equal-height primary vs danger “Usa tutto” — same capability as recipe-use, styled for the app.
- **Medicine Usa / Modifica / Butta** are cabinet-specific: no vacuum, scale or recipe; insert-time
  fields (categories, ingredient, pack, month/year expiry, PAO) surface on edit; discard uses
  smaltimento wording.

### Fixed
- **Cooking mode bip-bip-bip.** Removed the always-on SpeechRecognition hands-free mic that
  restarted every few seconds and played Chrome’s start chime over the TTS.

## [1.29.2] - 2026-10-29

### Added
- **Italy Farmadati PARAF/EAN (`9xxxxxxxx`).** Codes like `989022227` are not AIFA AICs: Italy
  modules detect them (`parafCode` / `paraf_code`), `med_lookup` returns `paraf:true` without
  inventing catalogue rows, and the UI shows a confirm dialog explaining the proprietary
  Farmadati Italia Srl registry before opening an empty editable medicine form so the household
  can stock a new cabinet product. Core stays country-neutral (thin optional hook only).

## [1.29.1] - 2026-10-29

### Changed
- **Medicine OCR ask card is the message.** No more truncated status chip over the camera before
  the white “cannot read” card; the locked `A` prefix sits beside a digits-only AIC field.
- **Unknown AIC → name search.** When a typed/OCR code is not in the catalogue, ask for the
  commercial name and offer **4 AIFA packs** (rewrite + “4 more”), with **form + pack size** on
  each row so two CONNETTIVINA PLUS packs are distinguishable.
- **Name search is forgiving.** Marketing suffixes AIFA never stored (`Bio`), stems, pack strengths
  (`1000`), and brand typos (`conettivina`, `tachiprina`) still find real packs; ranking prefers
  the commercial name over a shared ingredient.
- **Near-match prefers the 6-digit family.** `A029788027` offers Destrometorfano Sella packs
  (gocce `041`, …) instead of unrelated Sella neighbours at distance 2.
- **Scan overlay aims at the printed AIC.** No barcode-style rectangle; large OCR-B ghost digits
  at 70% opacity and “frame A + 9 digits, not the barcode”; short status strings that wrap on a
  phone; partial reads no longer reset to `A000000000`.
- **Opened medicine: ¼ · ½ · ¾ chips** set remaining quantity from the full pack.

### Fixed
- Ghost AIC preview stopped updating after empty OCR passes; longer partials are kept.

## [1.29.0] - 2026-10-28

### Changed
- **Cabinet list shows what matters for a box.** Name, smaller brand, expiry date, application
  category, residual quantity and sealed/opened — no location badge and no redundant “Farmaco”
  tag (you already opened the cabinet).
- **Medicine titles are never prefixed with “Farmaco”.** The food genre pipeline treated the AI
  word as a grocery kind and wrote `Farmaco tachipirina`; medicines skip the prefix, the word
  is in `umbrella_labels`, and existing rows are healed on classify / maintenance.
- **Skin creams get a category chip.** Topical corticosteroids (`D07*`, e.g. Euclorina Eritemi e
  Dermatiti) and antifungals (`D01*`) map to **firstaid**, with dermatitis/erythema/eczema words
  added to that category’s symptom list — the form used to leave every chip empty for those ATC.
- **Medicine AIC OCR is leaner.** One thin strip + `psm 7/13` first (whitelist `A0-9`), smaller
  default crop (720 px / ~100 kpx), 8 s job timeout, and pageseg mode is not re-set every pass.
  Ink/boost and off-guide strips rotate in only after misses — fewer wasted seconds on a phone.
- **Uncertain AIC: pick a real pack on the camera, never invent one.** `_medOpenFamily()` no longer
  opens an empty product form (“crea un farmaco”); the household chooses among catalogue rows over
  the scanner, or types the AIC. Empty `_medStartManualEntry('')` is refused. `MED_DEBUG` stays
  off (`false`); no `data/med_debug/` artefacts to clear.

### Fixed
- **Medicine save: the category toast was a lie.** `med_classify` / `med_open` were called with
  the payload in the GET-params slot of `api()`, so on POST the body stayed empty, PHP saw
  `product_id=0` and answered `medical.classify_error` (“Non ho potuto salvare la categoria”)
  while the product itself had already been written. The calls now put the JSON in the body; the
  official ATC of the catalogue row is stored with them, and the server accepts a full AIFA code
  (`N02BE01`) against the household prefixes (`N02B`) instead of demanding an exact match. The
  follow-on “salvato ma non nell’armadietto” was the same bug cascading: without `is_medical=1`
  the cabinet answered `error.food_in_cabinet`. Classify now blocks the stock step on failure.
- **`medical.only_catalogue` no longer mentions the pantry.** The refusal is about the national
  database (AIFA), not about “already in dispensa”; spray/device packs whose MINSAN starts with
  `9` (Rinorex, Rinoreal, …) are not in the AIFA medicines export at all.

### Changed
- **The application category is ticked from the ATC, and the map now covers what a household
  actually has.** “Fastum gel” (`M02AA15`, a topical NSAID) arrived with **no category** because
  `data/medical_categories.json` only listed the oral ones (`M01A`). The ATC prefixes are DATA, and
  the form ticks the closed chip they resolve to (`_medCategoryFromAtc()`), so the map was widened:
  `M02A` → **pain**, `S01` → **eyes** (not only `S01X`), `D08/D03/D06/D09` → **firstaid** (it had
  none), `A02A/A03/A06` join `stomach`, `R05*`/`R06`/`M03` replace their narrower prefixes. The
  same prefixes feed the offline «cosa ho in casa per…» search (`med_symptom_search`), and
  `data/medical_atc.json` (the Phase-2 map) gains `M02A` and `A02A` so both files agree. An ATC the
  closed taxonomy does not know (an antibiotic, `J01*`) leaves the chips empty on purpose: the app
  never invents a household category.
- **A medicine box asks its state first, and the pack is read from the container — not from the
  dose.** “Fastum gel 2,5% 25 mg/g … tubo 50 g” was shown as **25 mg**: the form took the first
  number on the box, which is a dose per gram, not what the tube holds. `_medPackSize()`
  (`core/med.js`) now reads the container: countable pieces first (“10 compresse”), otherwise the
  g/ml measure, **skipping anything written as a ratio** (`25 mg/g`, `mg/ml`) and keeping the
  largest — so the tube is **50 g**. The block is re-ordered as the household asked: **sealed or
  already opened** first (it decides what the number means), then “Quanto ne hai” — on a sealed box
  the quantity IS the pack, detected and editable — and, **only when the box is opened**, a second
  field “Confezione intera”. The pack is stored as `products.default_quantity` (the pack size the
  rest of the app already reads: fractions, “sta per finire”, shopping list) while
  `inventory.quantity` stays the stock, so expiry alerts and the shopping list keep working
  unchanged. Two new keys (`medical.pack_detected`, `medical.pack_qty_label` + hint) in the six
  locales.
- **One screen for a medicine, and it ends in the cabinet.** The flow used to be able to fall back
  to the pantry step (asking the same quantity a second time) whenever the stock write was refused.
  Now a missing cabinet is **created on the spot** (`_medEnsureCabinet()` → the default
  `medical.default_location_name`, `medical.cabinet_created`), a failed write **keeps the form with
  every answer** and says `medical.stock_failed` (tap Save again), and a success lands on the
  **cabinet list with the new row in it** (`showPage('inventory', cabinet)`) instead of the
  dashboard. The **package code is no longer shown** on a medicine (`#pf-barcode-group` hidden: if
  the form is open, the code was already read and verified), and the pack size — “how many it should
  hold” — is the catalogue's (`medical.pieces_hint`), with the quantity prefilled from it, so the
  only question left is “how many you have”. Editing a catalogue entry ends in the same place
  (the cabinet list, or the dashboard when no cabinet exists) instead of the pantry step.
- **The label reader preprocesses the crop properly, instead of making “one black-and-white copy”.**
  Tesseract binarizes internally (Otsu), so the old single **global** threshold (58 % between min and
  max) let the lamp gradient of a glossy blister decide the cut: measured on a synthetic strip with a
  40→240 gradient it turned 7140 px of the dark side into “ink” and lost the digits on the bright one
  (168 px). The first two passes now get
  * a **local Bradley threshold** (`_binarizeScanCanvas()`, integral image, window = 1/8 of the short
    side, margin 0.15 → **240/240** ink pixels on the same strip, i.e. exactly the 480 strokes, split
    evenly across the gradient), and
  * a **contrast stretch (2 %–98 %) + 3×3 sharpen** (`_contrastScanCanvas()`) for grey ink under poor
    light.
  The other four passes keep the raw pixels, so a clean label is never harmed by over-processing, and
  `_medDebugPass()` now records the `prep` of each pass instead of a `binarize` boolean.
- **No “new medicine”: a box is added only when the catalogue knows it.** The medicine form no
  longer carries the word at all (`medical.form_title` is “💊 Farmaco”, not “Nuovo farmaco”), and
  nothing can be invented: `_medStartManualEntry()` asks the local catalogue **before** any form
  opens (`_medLookupCatalogue()`), a code it cannot name keeps the scanner running
  (`medical.only_catalogue`, or `medical.scan_needs_catalog` when no dataset is imported), the
  name field is **read-only** in medicine mode with `#pf-med-name-hint`
  (`medical.name_from_catalogue`), `submitProduct()` refuses a medical row without
  `_medConfirmedAic` (set only by `_medFillFromCatalogue()`), the manual switch on a product that
  does not exist yet is refused, and the blocked-country dialog lost its “Aggiungi a mano”.
  **Gone with it**: the name read off the label (`_medReadLabelName()` / `_medPickNameLine()` /
  `_medLabelNameFill()`), the “stable but unknown → open an editable form” branch in
  `_medConfirmRead()` (it now says `medical.only_catalogue`) and the three keys that fed them
  (`medical.match_needs_catalog`, `medical.read_unknown_hint`, `medical.scan_unsupported_manual`).
  The two ways in are a `medicines` row the app recognised (or a neighbour the household picked on
  the “Non è questo?” card) and a product that already exists.
- **Scanning a medicine no longer asks where to put it — nor the quantity twice.** A recognised
  box is stocked from the medicine form itself (`_medStockAddDirect()`): the answers on that one
  screen — how many, the printed expiry, sealed/opened — write the pantry row **straight into the
  cabinet the module owns**, and the flow ends on the dashboard with a confirmation. The pantry
  step, with its "📍 Dove lo metti?", is gone from the medicine flow; it survives only as the
  fallback of a refused write (no cabinet configured, a server answer), with every answer already
  filled in.
- **A box's expiry is a month and a year.** `#pf-med-expiry` is no longer a `type=date`: the month
  is a picker with its **name in the UI language** (`_medExpiryMonthName()`), the year is a
  **typed field** (`inputmode="numeric"`, so a phone opens the keypad and a desktop types it), and
  the stored value is the **last day of that month** (`_medExpiryIso()`) — which is what the print
  means. The same pair replaces the date field of a medicine on the pantry step
  (`_medAddExpiryHtml()`), where the "nuovo acquisto / già in dispensa" question — a pantry
  concept — is gone as well.
- **No food or pantry wording left in the medicine flow**: the submit button says what the tap
  does (`medical.form_save`, "Salva nell'armadietto"), the pantry step of a medicine is titled
  "Aggiungi all'armadietto" and **hides the place picker** (`#add-location-group`: a medicine has
  one legal place), the action page of a medical row says "nell'armadietto dei farmaci" instead
  of "in dispensa/frigo" and offers **no recipe button at all** (`medical.action_add_sub`,
  `medical.action_use_sub`), and no rule, hint or toggle can estimate a date on a medicine
  (`recalculateAddExpiry()` returns early for a medical row).

### Fixed
- **The month list of a medicine's expiry was always empty.** The markup names those controls
  `pf-med-expiry-month` / `pf-med-expiry-year` (hidden `pf-med-expiry`), while the helpers build
  every id from a prefix (`#<prefix>-expiry-month`) and were called with `'pf'`: the lookup found
  nothing, so the `<select>` stayed empty, the hidden ISO date was never written and the save
  answered "inserisci la scadenza". The prefix is `'pf-med'` at every call site now (the pantry
  step keeps `'add'`, which always matched), the two prefixes live in
  `_MED_EXPIRY_FORM_PREFIXES`, and `_medExpiryFillPickers()` **warns in the console** when a
  prefix does not match the markup instead of leaving a dead control on a phone. Verified with a
  harness that reads the ids **from `index.html`**.
- **The month of a medicine's expiry could not be chosen at all.** The list was painted by JS only,
  so whenever that painter did not run (a box added **by hand** through the "è un farmaco?"
  switch, a form reopened) the `<select>` was empty — and, in a flex row with `min-width: 0`,
  invisible: nothing to tap, nothing to type. The **twelve names now live in the language file**
  (`months.january` … `months.december`, the six locales — nothing hardcoded in the markup or in the
  code) and `_medExpiryPaintMonthOptions()` paints them from `translatePage()`, i.e. as soon as the
  strings are loaded: the control is ready **before** any form opens, whichever path gets there.
  `_medExpiryFillPickers()` only decides *what is selected* (and fills an empty list as a last line
  of defence), the month keeps a floor width (`min-width: 130px`), the year is a **typed field**
  whose hint is a translation too, and `keep` never throws away what the household already chose. A
  year that has already gone answers *data nel passato* (`_medExpiryErrorKey()`) instead of
  "inserisci la scadenza".
- **A medicine no longer wears the food icon.** Every row fell back to its food **category**
  emoji when the URL had no picture — and a medicine is stored as `altro`, so a box of tablets was
  drawn as 📦, the same mark a custom place and an unidentified product use (a mis-saved category
  could even put a 🥛 on it). EverShelf Med now has its own drawn mark,
  `assets/img/icons/medicine.svg` (a capsule with a cross, in the module's medical tint
  `#6366f1`, shipped in the service-worker shell) and one rule for drawing it:
  * `_productThumbHtml()` uses it as the fallback of a medical row (pantry, dashboard, banner),
    with the medical tint on the frame;
  * `_productCatIconHtml()` covers the plain emoji slots (detail sheet, action hero, add preview,
    products list);
  * the pantry badge says **Farmaco** (`medical.row_tag`) instead of the food category, and the
    "altro → let the AI guess a category" repair never touches a box;
  * grouped lists put medicines under their own **Farmaco** group with the pill as the header;
  * `_locIconMarkup()` draws the **place**: the cabinet is the pill in the dashboard pill, the
    pantry tab and every place picker (native `<option>`s keep the 💊 character, an image cannot
    go in an option label).

## [1.28.7] - 2026-10-28

### Fixed
- **A scanned medicine asked where to put it: “Dispensa / Frigo” instead of the cabinet.** The box
  was saved with `products.is_medical = 0` (the row in the household’s DB: AIC
  `A012745093`, the catalogue notes filled in, no flag), so `_isMedicalRow()` said “food” and the
  pantry step offered the fridge. Three guarantees now make that impossible:
  * `submitProduct()` reads the medicine answer from the page MODE **or** the switch **or** the
    medical chips, and in medicine mode it always calls `med_classify` — the DB flag is written
    even when the taxonomy chips are empty — and a failure is said out loud instead of being
    swallowed by `.catch()`;
  * `_isMedicalRow()` also treats a row carrying `med_tags` / `med_ingredient` as a medicine, so a
    classification written by any path counts;
  * saving a medicine no longer goes through the product ACTION page: it lands **straight on the
    pantry step**, whose place is pinned to the cabinet (`_renderLocationSelector(.., ‘medical’)`),
    with quantity, printed date and sealed/opened already answered — one tap. A medicine with no
    cabinet configured now says so (`medical.no_cabinet`) instead of quietly falling back to the
    pantry, which the server refuses anyway.

## [1.28.6] - 2026-10-28

### Removed
- **The whole PHP guard suite, the temporary diagnostics and the dead maintenance CLIs.** 71 files in
  `scripts/test-*.php`, `scripts/fixtures/`, the one-off ops/GitHub helpers
  (`audit-*`, `backfill-finished-shopping`, `sync-shopping-bring`, `triage-open-issues`,
  `github-issue-triage`, `reply-issue-200`, `delete-feature-issue-comments`,
  `fix-mangled-kind-titles`, `merge-duplicate-products`, `migrate-env-security`,
  `re-enrich-recipe`) and the 130 KB of fixtures only they read. The CI job `unit-tests` is gone
  with them, and `auto-merge-to-main` no longer waits for it. **The regression net is gone on
  purpose: `git revert <this commit>` brings it back in one command.**
- **`docs/openapi.yaml`** — a 39-path, five-week-old spec that had drifted from the router
  (`med_lookup`, `support_qr`, `location_rename`, `recipe_archive` and `app_bootstrap` were all
  missing) while three documents called it “the full OpenAPI 3.1 spec”. `docs/wiki/API-Reference.md`
  is the API document; the references that promised the stale file are corrected.
- **The medicine read diagnostic** (`data/med_debug/`, `MED_DEBUG=true`): the investigation it
  existed for is closed, so the folder is deleted, the flag is back to `false` in `.env` and the
  ignore entries are gone.
- **Dead code**: `_medRenderNeighbours()` (no caller since the confirmed read became a link), and
  the “guard test `scripts/test-….php`” promises in `api/*`, `assets/js/app.js`, the generators
  and the country schema, which described files that no longer exist.

### Changed
- `AGENTS.md`, `.clinerules`, `CONTRIBUTING`/`docs/wiki/Contributing.md`, `docs/CODEBASE-MAP.md`,
  `docs/CORPORATE-UI.md`, `docs/OPTIMIZATION-BACKLOG.md` and the wiki pages no longer instruct
  anybody to run a suite that is not there: only the syntax checks, the i18n audits, `shellcheck`
  and the generated-artefact checks remain.

## [1.28.5] - 2026-10-28

### Fixed
- **`A029788027` was not an unknown medicine: it was a misread pack.** The formulary has
  `029788015`, `029788041` and `029788054` — all *Destrometorfano Bromidrato Sella* (compresse,
  gocce, sciroppo) — because an AIC is **6 digits of the medicinal product + 3 of the pack**, and
  the digits an OCR loses are the last ones. A read with no exact row is now checked against its
  **pack family** (`_medFamilyRows()`): when the family exists the form opens on the correction
  card (“Il codice non è esatto: quale confezione hai in mano?”) with the real names and pack
  sizes, and the wrong number is **never stored** (`_medOpenFamily()` leaves the barcode empty).
  Only a read whose prefix is unknown too keeps the old “twice identical → open with a warning”
  path.

### Added
- **An OPTIONAL online catalogue provider, off by default.** `MED_LOOKUP_PROVIDER` /
  `MED_LOOKUP_URL` / `MED_LOOKUP_KEY` (see `.env.example`): when a household sets them, a code the
  local table knows nowhere is asked to the provider (Medifontis’ documented `GET /v1/drugs`, or
  an own endpoint) and the answer is **written into the local table**, so the next scan is instant
  and offline. Evaluation of why it is not the default: Medifontis indexes the **same AIFA open
  data** we already import (`sourceUrl: confezioni_fornitura.csv`, ~160k packs), so it adds no
  coverage; it adds a key, a quota, a network failure in a pharmacy with no signal, and it sends
  the AIC of what someone just scanned to a third party — a health signal. The catalogue stays the
  authority a scan is verified against.

## [1.28.4] - 2026-10-28

### Fixed
- **After twenty seconds the reader now ASKS, in words that fit.** The card that stops the camera
  said it in the status chip — a `nowrap` + ellipsis line — so what the household saw over a
  black frame was a truncated, illegible string. The frozen viewport is now covered by a real
  card (`#scan-med-ask`: an icon, “Non riesco a leggere il codice” and a sentence that WRAPS,
  “Scrivilo qui sotto come è stampato sulla scatola (es. A040313076), oppure riprova la
  scansione”) in six locales, and the chip is left empty. The camera chrome (guide, HUD, torch)
  disappears, the input takes the full width and the two answers sit under it:
  **Conferma** and **Riprova la scansione**. The attempts stop as well as the stream: `_scannerGen`
  is bumped, so a pass the worker was still chewing on can no longer open a form behind the card.

## [1.28.3] - 2026-10-28

### Added
- **A medicine box is now asked what a box is: how much, until when, sealed or opened** — on the
  medicine form itself, in the medical block. Quantity is counted in the unit the pack is
  measured in (`_medFormStockPrefill()`: "10 compresse" → **10 pz**, "1 flacone 200 ml" →
  **200 ml**, "tubo 30 g" → **30 g**, a typed value is never overwritten), the date is the one
  **printed on the box** (mandatory, a past one refused, ≤ 3 days warned) and the pack is
  **sealed or already opened** (which lets the server start its own clock). The answers travel
  into the pantry row — `unit`/`default_quantity` on the product, the date and the opened state
  pre-filled in the add step — so stocking a box is one tap and not a second interrogation.
  Editing the catalogue entry never asks the date again (the stock row owns its expiry).

### Fixed
- **A medicine smaller than the pantry floor is no longer refused.** The 20 g/ml floor exists for
  "2 g typed by mistake"; it made a 10 ml eye-drop bottle unfillable. Both the client and
  `addToInventory()` skip it for a medical row.

## [1.28.2] - 2026-10-28

### Changed
- **The medicine form stopped talking about numbers.** A confirmed read opened with
  “AIC · A042386348” over a name the catalogue had already put in the field: the
  banner is gone (markup, JS and CSS), the “Riconosciuto…” line is gone (it repeated
  the field above), and what is left is **one link**, “Non è questo?”, without the card
  around it — the alternatives are still one tap away when a digit was read wrong. The
  packaging code of a box is announced by a toast, never printed on the form.

## [1.28.1] - 2026-10-28 · branch `refactor_25_10_2026`

### Fixed
- **The camera never started: `_setScanMode()` threw on every scan page open.** v1.28.0 removed
  the "💊 Leggi AIC" button and left the identifier behind (`ocrBtn`) in the panel setup. The
  function threw `ReferenceError`, so `showPage('scan')` never reached **`initScanner()`**: the
  camera, the barcode decoder and the label OCR never ran, the status chip never changed and the
  household saw a frozen frame — which reads exactly as "it does not read anything". The dead
  line (and the CSS that sized its column) is gone, and `_setScanMode()` now runs its body inside
  a `try` (`_setScanModeInner()`): a cosmetic detail can never cost the scanner again. Guard:
  `scripts/test-medical.php` fails if that identifier ever comes back, if the wrapper
  disappears, or if `initScanner()` stops following `_setScanMode()` in the scan branch.
- **The reader answers faster, and a certain read stops asking questions.** From the household's
  own successful scan: the pass that read the code was the **binarized strip** (0.8 s) and it
  arrived only after eight passes and two AI calls. The rotation is five regions now, the
  binarized one first, the region that worked last time goes first on the next attempt, and
  the AI fallback waits for ~8 passes and runs at most once every 12 s. A **confirmed** AIC
  shows one line ("riconosciuto A…") and a **"Non è questo?"** link that reveals the
  neighbours on demand: the alternatives belonged to a doubtful read, and showing them next to
  a code the catalogue confirms only made the household wonder. The **active ingredient** now
  fills its own field (it lived only inside the notes), and the add step **refuses an expiry
  date in the past** and warns when it is three days away — nothing is written before that
  check. The "an opened pack counts its expiry from the opening" line is gone: it explained a
  rule nobody asked about on that screen.
- **The thin `0` after the A: a read is now REPAIRED as a candidate, and the catalogue decides.**
  The household's label (`A042386348`) was read `A42386348` by every pass — a correct line with
  one character lost — so the pattern never matched and nothing happened. A read of `A` + 8
  digits now yields the `0`-restored number as a **candidate**, which goes through the same
  catalogue verification as any read (`_medTryLooseReads` → `_medConfirmRead`): verified on the
  household's own read, `A42386348` resolves to AIC `042386348` = Brufen Analgesico 400 mg
  12 compresse (Mylan). The AI never guesses a medicine: a variant that is not in the AIFA rows
  opens nothing — and the reader never asks a model: the catalogue is the only authority.
- **One label attempt at a time.** The scan loop and the barcode kick could run together, the
  jobs interleaved in the worker and every one of them timed out (the 20 s storm in the log) —
  which also made the reader believe the device was slow and shrink the pictures. The size is
  now lowered only when a pass FINISHES slowly.
- **No more worker restarts, and a slow device gets smaller pictures.** Every timeout used to
  terminate the OCR worker, so the next attempt reloaded the 3 MB model — the household's log
  shows a restart loop where NOTHING could ever finish (12 s timeouts on a 102k-pixel strip). 
  Now a timeout only releases the queue, the deadline is 20 s, and the pictures get smaller
  (640 px / 60k) only when a pass FINISHES slowly — the timeouts were the queue storm, not the
  device. Each pass logs **how long it took**, so the real speed is measured instead of guessed.
- **The printed `A` must START a token.** The log showed the reader returning `AIC 014058142`: 
  the pack's progressive number promoted to a drug code because the tail of the word printed
  above it (`AAA`) sat right before the digits. A letter `A` in front now rejects the match,
  while any other letter (the previous word of the line, after the separators are compacted)
  is accepted — the case `…compresseA040313076` still reads.
- **A medicine now has a real expiry, and no estimate.** Food gets a shelf-life estimate because
  nobody prints one; a medicine box prints `scadenza MM/AAAA`, so the add step for a medical row
  shows **an empty date field and asks for that date** (`add.expiry_from_pack`), refuses a past
  one, warns when it is three days away, and **refuses to save without it**
  (`add.expiry_required`). The history average and the AI shelf-life hint are skipped for a
  medicine (they would invent a date), and switching "appena comprato"/"ce l'avevo già" keeps
  the date the household typed instead of re-estimating it. The expiry camera button stays
  food-only: the medicines module never calls a model.
- **A code the catalogue does not have is no longer a dead end.** The household's box read
  `A029788027` three times, identically, and the AIFA rows have no such pack: the app refused
  it (rightly — a wrong digit must not open a box) and the screen simply kept scanning, which
  reads as "it did nothing". The SECOND identical read now opens the form **with that code** and
  says it: "A029788027 non è nel catalogo AIFA: controlla il codice sulla scatola o correggilo
  qui" (chip + toast, six locales). Nothing is invented: the code is what the camera read, the
  name stays empty until the household fills it.
- **Twenty seconds, and the camera really stops.** The nudge used to be a toast over a live
  preview — invisible in practice. Now `stopScanner()` releases the camera, the code row lights
  up and takes the focus, and a **"Riprendi"** button restarts the reader when the household
  wants another go.
- **The medicine form lost its noise.** A confirmed box now shows the code in the banner and one
  line ("Riconosciuto A042386348"), with **"Non è questo?" as a small footnote at the end** of the
  card instead of a button in the middle of the information; the "Farmaco riconosciuto / il nome
  arriva dal catalogo…" texts and the "Fonte: AIFA · N farmaci" line are gone (three keys
  removed from the six locales). The **"Scade dopo l'apertura (giorni)" field is gone too**: it
  asked the household to compute a rule the pack never prints. A PAO already stored in Settings
  is preserved, and the closed category + the active ingredient still fill themselves from the
  catalogue row (ATC → category, `PF-MED-INGREDIENT` ← principio attivo).
- **Ten seconds without a read, and the household is asked for the code.** The log of one scan of
  the Brufen box shows 25 `job timed out` and passes coming back with `"A"`, `"A 2"`, `""`: on
  that phone the bundled engine cannot finish a pass, and the camera loop was busy waiting for
  it. After ten seconds the code row is **highlighted, focused, and says so** in six locales
  ("scrivilo qui sotto, es. A042386348"); after three timeouts in the same session the local
  engine is **not asked again** — it wedges — while the barcode keeps trying, so the camera stays
  responsive and the field is the answer. The number is verified by the catalogue exactly like a
  read: nothing is guessed.
- **The BARCODE comes first again, magnified.** The household's log showed the decoder
  hallucinating on a dense Code 39 (`18FJXD`, `1836XD` for a bollino that prints
  `A042386348`): at 720p, with the sticker filling a third of the frame, each module is ~2 px.
  The medicine panel now decodes a **2× crop of the guide rectangle** (native detector + ZBar,
  every other frame) **before** any OCR: that is the pharmacist's path — the number that comes
  back IS the catalogue key, no guesswork — and the rear camera asks for 1920×1080 instead of
  1280×720. The OCR stays as the second path (binarized strip first). A worker that stops
  answering is replaced after two timeouts without a successful pass in between: the 20 s
  `job timed out` storms in the log were a wedged engine, not a slow device.
- **The medicine reader NEVER calls the AI.** A vision-model fallback (`gemini_med_aic`) was
  added for devices too slow for the local engine and has been **removed on the household's
  instruction**: a medicine is identified by a printed code verified against the catalogue,
  never by a model. The shelf-life AI hint is skipped on a medical row too, so nothing about a
  drug ever leaves the database. Guard: `scripts/test-medical.php` fails if any `gemini_*` /
  `_medReadLabelAi` reference comes back.
- **The phone needed half a second per 10k pixels: the reader now sends STRIPS.** The read
  diagnostic caught it: on the household's phone every `recognize()` exceeded 7 s (a 400k-pixel
  crop), while the engine's self-test (77k) passed — the frames themselves were perfect. The
  unit of work is now a **thin strip of the guide rectangle** (~1/3 of its height, 900 px wide,
  ~100k pixels, three overlapping strips covering the whole label, plus the 1.5× one and a
  binarized copy), the whole-frame pass is gone (0.9 Mpx bought nothing), the pixel budget is
  180k and a job may take 12 s. The guide rectangle itself is taller (18% → 26% of the viewport)
  with the hint "inquadra l'etichetta intera": the household aimed at a letterbox and the
  printed line kept falling out of it.
- **Two undeclared variables: the label reader had NEVER run.** `_medFullPassAt` (shipped in
  v1.28.0) and `_medOcrQueue` (lost in this cycle's timeout refactor) were used but never
  declared, so `_medReadLabelOcr()` threw `ReferenceError` before reading anything: the OCR
  produced **no log line, no frame, no feedback**, and the scan loop swallowed the error. Both
  are declared now, a failed attempt always says why (`Medicine OCR attempt failed: …`), and
  `scripts/test-medical.php` gained a **static declaration check** over every `_med*` symbol
  the app uses (declarations may live in app.js, `core/med.js` or `med/italy.js`) — the check
  fails on the exact bug that cost this round, and on the next one.
- **The phone's own video is portrait, and the reader now knows it.** The remote client log showed
  `videoWidth: 720, videoHeight: 1280`: the bands were computed for a landscape frame and the
  canvas could reach 0.7 Mpx, which `tesseract.js` chews on for tens of seconds. The bands are
  now 40% of the height with a 0.4 Mpx **pixel budget**, the side cap is 900 px again, and every
  OCR job has a **7 s deadline** that releases the queue and replaces the worker after two
  timeouts — a `recognize()` that never settled used to hold `medOcrBusy` forever, which is
  exactly 'two minutes and nothing happens'. The reader also says WHY it bailed out (module
  off, no pattern, no frame) and the scan panel logs which mode it is in.
- **TEMPORARY diagnostic for the medicine reader (`MED_DEBUG=true`).** `POST med_debug` records,
  per OCR pass, the region/psm/video geometry, the text it got and — bounded to a dozen small
  JPEGs per session — the crop itself, under `data/med_debug/` (400 events / 40 pictures max,
  nothing leaves the server, 403 the moment the flag is off). It is the tool used to diagnose
  the household's own phone: turn `MED_DEBUG=false`, or delete the folder, when the
  investigation is over.
- **The medicine reader can read a printed AIC again, and a barcode is a way in again.** The
  scanner had been left with ONE path — the OCR of the printed block — because an earlier
  release refused every barcode in the medicine panel. On a real Italian box that means a single
  point of failure: one missed digit and the reader reports nothing at all, which is exactly what
  "l'OCR non funziona più" looked like. Now:
  - the **AIC itself, printed as a Code 39 on the bollino** (`A040313076`), is accepted again, and
    so is AI `710` of the FMD DataMatrix. Both go through the same verification as any read
    (`_medConfirmRead()`): a number the AIFA catalogue does not contain — the lot `16G87N`, the
    pack's progressive number `0140581421`, a serial that happens to start with an `A` — is
    refused and the reader keeps going, instead of blindfolding the whole scanner;
  - Italy's schema declares `national_code: "both"` / `barcodes: true`, and the pack's
    progressive number is now listed under `ignored` with its own label
    (`medical.code_progressive`), so the household is told *what* was read and discarded;
  - the OCR regained the hand of a human holding the box: **one rotating band of the whole frame
    per attempt** (`_medFrameBands()`, half the frame tall, 50 % overlap) beside the guide
    rectangle, so a printed block that sits outside the drawn box is still found;
  - the modes were measured, not guessed, on a reproduction of a real Italian bollino
    (Tachipirina orosolubile, `A040313076`, lot `16G87N`, progressive `014058142`): a half-frame
    **band read with the single-line mode (psm 7) returns `'32'` — or nothing at all on a
    smaller frame — because a band holds several lines; with psm 6 the same band reads
    `A040313076`**. The band pass therefore switched to psm 6, and a **psm 13 (raw line)** pass
    was added on the guide rectangle: it is the pass that returned the printed block clean even
    on a blurred, downscaled frame (`44040313076`, repaired to the AIC by the prefix tolerance);
  - **the reader now answers in about a second instead of five**, and the chip belongs to it: on a
    phone ONE attempt used to run six OCR jobs in a row (3-5 s) while the lot barcode rewrote the
    status every 1.5 s with a 125-character sentence the chip cut to "… non è il numero …". Now
    an attempt is **one region**, rotating through the rectangle and three half-frame bands, with
    the two winning regions first (measured on a 1280x720 phone frame with the sticker at 40% of
    the width: the block segmentation on the rectangle and the middle band carried the AIC),
    every 900 ms; a packaging code announces itself once per 8 s and never over a line the reader
    just wrote; the message fits the chip in six locales; the crop now **grows** beyond the drawn
    rectangle instead of shrinking inside it (an AIC line wider than the frame lost a character at
    each end), and the canvas is 1400 px so a box read from 30 cm has thick enough characters;
  - the printed `A` that the camera turns into a `4` (or `@`, `&`) is **recovered**, and a page
    whose letter is lost entirely hands its digit runs to the catalogue
    (`_medOcrDigitCandidates()` → `_medTryLooseReads()`, four at most, each one verified). Both
    forgiving paths are **closed when no catalogue is loaded**: there is nothing to prove a
    number with, so the strict rule stays.
- **Moving a product to another place no longer re-dates it.** The suggested expiry follows the
  **kind** of place — pantry, fridge, freezer — and never its name, so "Dispensa cucina" is
  dated exactly like "Dispensa" (`storageKindOfLocation()`, ported to PHP as
  `storageKindOfLocationPHP()`; the kind is read from the built-in key, from the label the
  built-ins carry in the active language and from the words the CSV import already knew — no new
  vocabulary, six locales covered). Beyond that, a pick between two places of the **same** kind
  now leaves the date alone instead of silently replacing it with a rule estimate: the add form
  (`recalculateAddExpiry()`) and both edit modals (`recalcEditExpiry()`) recompute only when the
  kind changes, or on purpose (the vacuum toggle, "appena comprato"), and the AI hint may no
  longer apply days that belong to a place the household did not choose. A custom freezer
  ("Freezer garage") is a freezer for the estimate and for the safety verdict too.

### Added
- **The add step asks a medicine box its own two questions.** Food asks "quanto?" and "quando
  scade?"; a box also asks **sigillata o già aperta** — an opened pack calls `med_open`, so its
  expiry becomes today + the storage life after opening (`med_pao_days`) — and **quante ne
  restano**, as fractions of the pack the catalogue describes ("10 buste" → tutta / ¾ / ½ / ¼,
  from `_medPackCount()`). A liquid ("1 flacone 200 ml") names no pieces: the fraction row stays
  hidden and the quantity field is the answer. Nine new keys in all six locales.

## [1.28.0] - 2026-10-27 · branch `refactor_25_10_2026`

### Added
- **A medicine box is now read the way Italy prints it: the AIC, not the barcode.** The Italian
  pack carries no drug barcode — the SSN label is a **Pharmacode/Laetus** (a packaging code of
  the line) and the 2D DataMatrix carries a lot/serial — while the number that identifies the
  drug is **printed** (`A040313076`, Code 39; or 9 bare digits in the older Code 32 form; or
  AI **710** of the FMD DataMatrix). The scanner now follows that: the AIC is the only medicine
  code, the packaging codes are listed as `ignored` in
  `data/medical_code_schemas.json` and are **never** sent to a catalogue, and the label is
  OCR'd instead (the bundled Tesseract, second parameter set: letters + digits, block
  segmentation). In the medicine panel the label is the SOURCE, not a fallback: the read starts
  on the first usable frames, repeats every 2 s (bounded to 45 attempts) and takes **only the
  printed `A` + 9 digits** — a bare 9-digit run is the pack serial printed under the SSN barcode
  and is never accepted. The panel draws **one rectangle** (the OCR's field of view) with the
  expected shape `A000000000` in a monospaced face, and hides the particle cloud and the
  sweeping line of the barcode hunt. Above all, **a barcode is no longer a drug number in that
  panel**: the SSN label carries several Code 39s (AIC on top, pack serial at the bottom) that
  are indistinguishable by shape, so only the EU FMD DataMatrix (AI 710) is accepted; anything
  else sends the scanner to the printed block. A **💊 Leggi AIC** button forces a pass.
  Every path that used to claim a Pharmacode as the medicine number is gone, including the dead
  `_looksLikeMedicineCode()` heuristic.
- **The Italian rule is a MODULE, not a corner of the core.** `api/lib/medical.php` and
  `assets/js/core/med.js` are now country-neutral: they load the schema, walk the GS1 element
  string, apply the `codes`/`ignored`/`ocr` lists and ask the country module one question —
  *is this read one of YOUR drug codes?* The AIC (three printed forms + AI 710) and the AIFA
  column names live in `api/lib/med/italy.php` and `assets/js/med/italy.js`, registered by the
  `"module": "italy"` the schema declares. A country that only needs a regex (PZN, CN, NDC)
  needs no module at all; dropping Italy leaves a scanner that still reads the others.
- **A local AIFA catalogue, imported once.** Because no free, unlimited, key-less REST API
  exists for Italian medicines, the app never calls one: `php scripts/import-medicines.php
  <file.csv|xml> --country=IT` loads the AIFA open data into a `medicines` table (AIC as the
  key, headers recognised by NAME from the country module, `--dry-run`/`--truncate`/`--limit`,
  CSV and XML, fixtures in `scripts/fixtures/aifa_sample.*`). `GET med_lookup` answers by AIC or by name from that table
  (authenticated, read-only, no network), and the medicine form shows the match — name, active
  ingredient, form, pack, company — with a **"Usa questo nome"** button: the household
  confirms, nothing is auto-filled. Settings → 💊 Farmaci says how many medicines are loaded,
  and says it out loud when none are (an unknown AIC then means "no catalogue", not "no such
  drug").
- **The FMD DataMatrix is decoded.** `medicalGs1Parse()` / `_medGs1Parse()` (core, GS1
  standard) walk the element string — fixed-length AIs, variable ones ending at FNC1 — and
  return GTIN, expiry, batch, serial and AI `71x`; the Italian module reads the AIC out of
  AI 710, so a modern EU box is answered with its drug number instead of its lot.

- **The label standard is a CONTRIBUTABLE per-country schema, and the scanner is BLOCKED where it
  is missing.** `data/medical_code_schemas.json` is now v3: every country declares `codes`
  (what identifies the drug), `ignored` (the packaging codes to throw away), `ocr` (the printed
  pattern + the `sample` the guide frame shows + the `hint_key` for that country), `fmd` +
  `fmd_national_ai`, `dataset` (open data? which importer?) and a `scan` summary the UI reads. Two
  more countries are wired: **Germany** (PZN printed in clear text and as Code 39, `PZN 00000000`;
  the IFA catalogue is licensed, so the name is typed) and the **United States** (NDC printed,
  `00000-0000-00`, with the **openFDA NDC Directory** as a free JSON catalogue — the importer now
  reads JSON too, and the catalogue key is the national number whatever its length). France keeps
  the EAN-13 (the CIP-13 *is* the EAN), Spain and Austria use the FMD/AI 710, and a country with no
  schema gets a **dialog** that says the label standard is missing, offers the hand entry and
  points at the new contributor guide `docs/wiki/Medicines-Country-Schemas.md`.
### Fixed
- **The pack serial is no longer read as the drug number.** An SSN label carries a second Code 39
  with the pack's progressive number (`014058142`), which has the same shape as an old Code 32
  AIC: the scan stored it as "A014058142" and the catalogue, of course, knew nothing about it.
  Italy now accepts **only the printed `A` + 9 digits** (and AI 710 of the FMD DataMatrix) — in the
  barcode path too — so a bare 9-digit read is refused. When such a barcode is read the medicine
  panel says it in the status line (`medical.scan_code_ignored`) and goes back to the label, the
  digit-under-the-bars OCR does not run in that panel at all, and the label reader reads **only**
  the guide rectangle (no full-frame second chance, which could catch another printed number).
  Italy now declares **`scan.barcodes: false`**, so the medicine panel runs **no barcode engine
  whatsoever** (native detector, ZBar, Quagga and the digits OCR are skipped); Germany and the
  US keep their barcode path, because there the Code 39/EAN *is* the drug number.
- **Fixed: the quarter/half/three-quarter buttons are back, in the product sheet.** When "Usa" started opening the modal instead of the old USE page, the fraction shortcuts stayed behind and a piece could only be consumed one at a time. The sheet now carries **¼ · ½ · ¾ · 1** chips (plus **2** when there are two or more) for pieces and packs, and ¼·½·¾·pack for weights when the pack size is known — one tap, the stepper follows.
- **Fixed: the label reader could go blind, and now proves it works.** Removing the resolution re-negotiation (it restarted the camera on some phones) and serialising the OCR jobs — the label reader and the name reader shared one worker and could answer each other's job, which returns nothing — plus one synthetic `A040313076` image at panel open: if the engine itself is broken on the device, the status says so instead of the frame never filling. What each pass read is one line in the existing status chip (`Letto «…»`).
- **The two buttons are gone**: "Leggi AIC" and "Aggiungi a mano" are replaced by **one field** to type the code and Conferma — same verification as a scan, and the catalogue names the box.
- **The medicine catalogue downloads and refreshes itself.** Switching the cabinet on is the consent: the app fetches the free AIFA *confezioni* export (160 000 packs: AIC, name, company, pack, ATC, active ingredients) by itself, once a day at most and again every 30 days — also from the cron. Settings → Farmaci keeps *Aggiorna dal sito AIFA*.
- **A scanned AIC is verified before it is used.** A code the formulary does not contain is a misread digit: the scanner says so and keeps reading instead of opening the wrong box (`A040313076` read as `A040313072`: `...72` is not a medicine).
- **Fixed: nothing is drawn over the camera any more** — the read box is gone too; the guide frame fills in with the digits as they are recognised, and that is the whole feedback.
- **Fixed: the white box over the scanner is gone.** The medicine hint was a bordered white card of three lines above the camera and the read line was another one: both are now one line of dark glass, and the hint is a single sentence.
- **Fixed: the form now fills itself.** A recognised AIC writes the **name, the company, the notes and the closed category** from the catalogue (empty fields only: a typed value always wins). With no catalogue loaded the card says so instead of staying silent — and the **name printed on the box is read** by one extra OCR pass, with a notice saying it came from the label.
- **Fixed: no card over the camera.** The four-code confirmation that popped up over the viewfinder is gone: a read opens the medicine form as before, and the codes one digit away are **“Non è questo?” chips on the catalogue card**, next to the name they would change. The camera keeps one line of text, nothing else.
- **The guide frame fills in with the digits being recognised** (`A0403130···`, emerald when complete) — the placeholder is no longer decorative.
- **Fixed: why the reader struggles.** One pass now reads the **whole frame** as sparse text every few seconds (a box outside the rectangle was silently unreadable), the tight band is widened to 1.5×, and the read line says when there is **no OCR engine** or, with `?debug=1`, the frame size and the exact crop rectangle.
- **Fixed: a medicine is confirmed, not assumed.** An OCR read of `A040313076` that came back as `A040313072` was accepted silently — one digit, the wrong box. The reader now asks the local catalogue for the **real codes next to the read** (`med_lookup?near=1`, ranked by edit distance) and the scan page shows up to **four** of them, each with the name, dosage and pack printed on its box: one tap, and a wrong digit is visible before anything is stored. Without a catalogue the card says so instead of inventing neighbours.
- **The guide frame fills in with the digits already recognised** (`A0403130···`), and the name card says **where the name comes from** (`Fonte: AIFA open data · N farmaci`) with the active ingredient, form, pack, company, ATC and class.
- **The AIFA file can be loaded from Settings** (Farmaci → *Importa il file del catalogo*): the same import as the CLI, without SSH. The free national dataset is a **file** — no key-less medicine API exists — so it is read once, locally, and deleted after the import; no scan ever leaves the server.
- **Fixed: the label reader can be watched, and it reads a real label.** A one-line report under
  the camera says what it actually saw (“Letto «…»”, plus the crop when nothing matched), so
  “it does not read” and “it read something else” can be told apart at a glance. The crop is
  binarized and blown up, the alphabet is cut to `0-9A`, the block is retried with a wider
  page segmentation and a 1.6× wider band, a letter-spaced `A 0 1 2 3 4 5 6 7 8` and the classic
  `A0l2345678` confusions are repaired (inside an A-code only), and the loop keeps reading at
  4 s instead of stopping after 45 passes.

### Changed
- **Two scan panels, never mixed.** The household said it plainly: a food panel that answers with
  drug codes (and a drug panel that asks a food catalogue) is confusion. `#page-scan` now has two
  modes chosen by the PLACE the pantry is showing — open the medicine cabinet and the scanner IS
  the medicine panel: title *Scansiona farmaci*, only **💊 Leggi AIC** and **Aggiungi a mano**, no
  AI vision, no food name search, no shopping session, no favourites rail (`is-medical-scan`
  hides every food control in CSS and JS hides the medicine ones outside that mode). Any other
  place keeps the food panel. A drug code read in the food panel shows one inline notice with
  *Apri 💊 farmaci*, which puts the pantry on the cabinet and opens the medicine panel in the same
  tap; an EAN read in the medicine panel is refused with `medical.scan_not_medicine` instead of
  being sent to five food catalogues. The old "is it a medicine?" dialog is gone, with its
  four translation keys.
- **The name catalogue is optional, and it says so.** The medicine form no longer announces a
  database to download when nothing has been imported: it simply shows no catalogue card, the
  code is stored and the household types the name. Settings → 💊 Farmaci still explains the
  optional AIFA import (`medical.dataset_empty`), and the card only appears when there is
  something to offer.
- **The medicine form's catalogue note and the credits page tell the truth again**: the name
  can now come from the AIFA import (`medical.no_catalogue`, `medical.sources_note`,
  `credits.medicines_note`, `credits.source_aifa_what`, `credits.source_pharmacode_what`,
  `credits.source_fmd_what`), and the identifying-data disclaimer stays on screen. 21 new keys
  in six locales.

## [1.27.0] - 2026-10-27 · branch `refactor_25_10_2026`

### Added
- **Settings now answers "which free API do we use, why, and in which context" in one tap.**
  A primary button sits in the free-API card, immediately above the support card and its
  buttons: it opens a dialog with one block per service the app really queries — the official
  logo, **why** we call it (`credits.api_*`), **where** in the app it happens
  (`credits.api_*_where`), the licence and the project's own URL, plus the note that no free
  catalogue covers medicines. Everything is painted from the generated
  `assets/js/free_apis.generated.js` (offline, before any token) so the dialog and the
  **Crediti** page can never disagree; its footer still leads to the full thanks page.
- **A custom storage location can be renamed**, not only added and deleted: a pencil next to
  the ✕ opens a small dialog (`location-rename-overlay`, Esc closes it) that posts
  `location_rename`. The new name is **one server action, because a location is three rows**:
  the label in `CUSTOM_LOCATIONS` (.env, or the SQLite fallback when the file is read-only,
  read back before answering), the slug on `inventory.location`, and the label stored in
  `app_settings.medical_locations` by the medicine wizard. Stock and history move with the
  slug inside one transaction, so the pantry tab never points at a place that no longer
  exists; refusals (reserved builtin, duplicate, comma, > 40 chars) answer with an `error_key`
  translated in all six locales.

### Changed
- **The dashboard's places are one dropdown, and the medicine cabinet keeps its own 💊 pill.**
  A row of pills stops working at the fourth place — the exact reason the pantry filter became
  a `<select>`. The Home block now follows the same grammar: `#dashboard-location-select` lists
  Dispensa, the fridges and every custom place with its product count (and a ⚠ where there is
  something expired or expiring, since a native list has no red dot), and picking one opens the
  pantry **on that place**. The control is a way in, not a filter, so it resets after the pick:
  the same place can be chosen twice. The marked cabinet is deliberately **outside** the list as
  an always-visible 💊 pill — a cabinet buried in a dropdown is a cabinet nobody opens — and the
  `＋` that leads to Settings → Luoghi stays next to them.
- **A food's screen no longer offers the medicine cabinet (and a medicine offers no food place).**
  The edit form's "📍 Posizione" drew every location the instance has, so a yoghurt could be
  sent to 💊 *Armadietto farmaci* and a box of pills to the fridge — and the server answered
  `error.food_in_cabinet` / `error.medicine_needs_cabinet` on save. The group now comes from
  `_locationsForEdit()` (the row's own place plus the legal ones) and the domain is decided by
  the **product** (`_editDomainIsMedical()`, falling back to where the row sits): a flagged
  medicine only ever sees the cabinet, a food never sees it. When a row has a single legal place
  — a medicine already in its cabinet — the whole block is removed instead of drawing one dead
  button (`.form-group[hidden]`, because `display: flex` would otherwise defeat `hidden`). The
  same domain rule now guards the "Da dove?" picker when a settings change repaints it.
- **The pantry shows the place you opened — header included.** Opening the fridge left the page
  header reading "Dispensa" and, right above the list, the **medicine-cabinet pill** (it sits
  before the dropdown): the one thing the household had just chosen was the only thing not
  written on screen. `_syncInventoryTitle()` now puts the opened place in the header (its icon
  and name, from the one location list) and restores `inventory.title` — `data-i18n` and all, so
  a later language pass cannot leave a stale name — when the filter goes back to "Tutti". The
  cabinet pill is removed from the strip while **another** place is open and comes back where it
  is the way in: the cabinet itself, or "Tutti" (never buried in a menu).
- **A medicine box's lot code is no longer read as its AIC.** The report's code `16G87N` is the
  lot/serial part of the box's EU FMD DataMatrix, not a national medicine code: the detector
  stripped its letters, got `1687`, and happily claimed a "4-digit Pharmacode", opening the
  medicine form for a code nobody had read. The rules now live in
  `data/medical_code_schemas.json` as a `pattern` per code, matched against the **whole** read by
  both detectors (`_medCodeMatches()` in the browser, `medicalCodeMatches()` on the server — two
  ports, one rule): the AIC is `^A?\d{9}$`, so both forms the box can print are recognised (the
  official 9 digits, and `A` + 9 digits in Code 39, e.g. `A017837016`), the Pharmacode is a
  numeric `^\d{3,6}$` inside 3…131070, and a read with letters in it that matches nothing is
  **not** claimed. Such a code never reaches the five food catalogues either (its digits are not
  a product code — that was a wrong lookup waiting to resolve somebody else's product): the
  household is asked once, with the code it heard. A real Italian drug catalogue exists (AIFA
  publishes the medicines dataset as open data; third-party REST aggregators expose it), and the
  schema note says so — wiring one in stays a product decision, because the module deliberately
  claims no drug and works offline.
- **The medicine cabinet always sits right after Dispensa, and wears a 💊 instead of the
  cardboard box.** A custom location used to be appended in the order it was typed, with the
  same 📦 as a cellar: the cabinet ended up last in the dashboard rail, in the pantry tabs and
  in the add/use pickers, looking like any other place. The one ordered list
  (`mergeCustomLocations`) is now Dispensa → the marked medicine cabinet → the fridges → the
  other custom places, `MEDICAL_LOCATION_ICON` / `CUSTOM_LOCATION_ICON` are declared once, and
  the Settings card and the medical picker read the same list (the picker used to invent its
  own order and `LOCATION_META`, which never existed). `med_status` now also returns
  `medical_locations`, so a brand-new device paints the cabinet correctly at boot instead of
  only after someone opens the medical settings.
- **The pantry's location filter is a dropdown, and the medicine cabinet keeps its own pill.**
  A linear row of tabs stops working at the fourth place: `#location-tabs` now holds a native
  `<select>` (`#location-select`, `inventory.location_filter`) with every place plus "Tutti",
  and the marked cabinet stays **outside** the list as an always-visible 💊 pill — a cabinet
  buried in a menu is a cabinet nobody opens. While the cabinet is open the select shows a
  disabled entry with its name, so it never claims "Tutti" over the cabinet's stock.
- **Adding a medicine is not adding food any more.** Scanning a recognised code opened the
  ordinary product form — "Scatta foto e identifica con AI", the food category, unit/package,
  tags and a **Famiglia** field that suggested "Spaghetti" — plus a switch asking "is it a
  medicine?" right after the app had answered that itself. The form now switches to a
  **medicine mode** (`#page-product-form.is-medicine-form`): a banner states the code it read
  (AIC/Pharmacode/PZN, from the country schema) and that no drug catalogue exists, so name and
  maker are typed; the food-only fields (`pf-food-only`) disappear, the "is it a medicine?"
  switch goes away, the labels become **Nome del farmaco / Casa farmaceutica / Codice della
  scatola**, and the **closed medical taxonomy** is the category (at least one chip is
  required — it answers "what do I have for…" — and the product is stored as a box in
  `altro`, never in a food category). The add step drops the "sotto vuoto" option for a
  medicine. Nine new keys in six locales; the guard test runs the shipped switch in node.
- **The splash prints the logos of the free APIs only, no names** — and only the ones that
  read at that size. The row under the boot rail wrapped onto three lines on a phone and read
  like a menu; three of the six marks are **horizontal wordmarks** (Open Products / Beauty /
  Pet Food Facts are 7:1), which a 22 px square squashes into an invisible sliver — that was
  the "the last three logos are missing" report. The shape is now **data**
  (`wide` / `splash` in `data/free_apis.json`): the splash paints only the three readable
  marks, while the Settings card, the dialog and the thanks page give a wordmark a wide box
  instead of a sliver, with its name. A line above them says what they are
  (`startup.free_apis_lead`, six locales), and the logos **light up one by one** (`--i`
  staggers a glow animation; `prefers-reduced-motion` switches it off). The files stay local:
  no third-party request behind a thank-you.
- **The splash lasts at least 3 s on every boot.** The warm-reload shortcut (250/500 ms) is
  gone: a fast server no longer makes the boot rail flash past, and the same 3 s floor applies
  in debug and production.

### Fixed
- **Opening a location showed the whole house.** In the pantry, "Ultimi usati" and "Più usati"
  were built from every transaction of every place, and the search looked at all locations —
  so "Dispensa cucina" answered with products that are not in it. `recent_popular_products`
  now takes `location` (history **and** stock of that place) and the rail follows the filter;
  the search runs on the rows of the place on screen (`currentInventory`), and switching place
  **clears** a leftover search query and tag filter — a query typed somewhere else is what
  made five products in "Dispensa cucina" look like one.
- **Food and medicines can no longer share a place.** Food cannot be added (or moved) into
  the medicine cabinet, and a medicine cannot be placed anywhere else. The client only offers
  the legal places (`_renderLocationSelector(…, kind)` for the add form, `_locationsForDomain()`
  for both move dialogs) and the server refuses the rest on **every door**:
  `inventoryLocationDomainError()` is asked by `addToInventory`, `updateInventory` and the CSV
  import, and answers `error.food_in_cabinet`, `error.medicine_needs_cabinet` or
  `error.medicine_location_missing` (six locales). A product that *becomes* a medicine
  (`med_classify`) has its stock moved into the cabinet, so the rule never depends on the
  order of the actions; the marked place is read in both stored forms (typed label or slug).
- **A second database connection turned a move into "database is locked".** Adopting a custom
  place that lost its label used to call `getDB()`, which builds a NEW PDO and runs the
  migrations on it: the connection stayed open for the rest of the request and the next
  `UPDATE` answered `database_busy`. The orphan lookup now receives the request's connection
  (`inventoryOrphanCustomLocations(?PDO $db)`) and never opens one.
- **Adding a product lit up TWO locations ("Dispensa" and "Dispensa cucina").** The add form
  picked the button by asking whether its *text* contained the location key: "dispensa" is a
  substring of "dispensa cucina", so both pills turned green — and a custom place matched
  nothing at all, because its label is not its slug. Every location button now carries its
  own key (`data-loc`) and the selection goes through one resolver that accepts a slug **or**
  the typed label (`resolveLocationKey()` → `markLocationButton()`): exactly one place, the
  right one. The same rule covers the history hint and the AI hint, and **the place the
  household picked wins**: `selectLocation()` records the choice (`dataset.userPicked`) and no
  hint may move it afterwards. A medicine still defaults to its cabinet — now as a real key
  instead of the stored label.
- **"Invalid location" when adding a product to a place you had just created.** The Settings
  card kept a new custom location **in the browser only** — the page-wide "Salva" was what
  wrote it — while the pantry validates the location **server-side** against
  `CUSTOM_LOCATIONS`: the first product saved there answered `Invalid location`. Creating a
  place now persists it before showing it (add, remove, the medical wizard and the first-run
  setup share one writer, `_saveCustomLocations()`), and a refused save is reported instead of
  leaving a phantom place in the pickers. The toast no longer says "premi Salva" and the card
  no longer mentions the removed "Altro" location (six locales). The server also **adopts a
  custom place that still holds stock but lost its label** (`inventoryOrphanCustomLocations()`
  → `inventoryLabelFromSlug()`), so the existing `Dispensa cucina` reappears as "Dispensa
  cucina" — readable, and it accepts products again — instead of being an invisible slug.
- **The Italian medicine label from a real photo is recognised.** Three defects stacked on the
  same path: (1) `_tryConfirmBarcode()` dropped every code shorter than 8 digits, so the
  documented Pharmacode/Laetus (3–7 digits) never reached the medicine detection; (2) the
  AIC — `A017837016`, `A` + 9 digits, Code 39 — lost its `A` to the digit-only cache key and
  was only checked **after** five food-catalogue requests had answered "not found" (offline:
  an error toast); (3) the country schema fell back only on `APP_LANG`, so an Italian UI over
  an instance that never set it got **no schema at all**. Now a national code
  (`_medNationalCode()`) is answered before any request, keeps the box's own code, and
  `med_status?lang=` falls back to the UI language. Verified against the three codes printed
  on the report's photo (`A017837016`, `A034548040`, `A012745182`).
- **A medicine cabinet marked with the typed label is a medicine row again.** The wizard stored
  "Armadietto Farmaci" while `inventory.location` holds `custom_armadietto_farmaci`, and the
  two were compared raw: `_medLocationKey()` now normalises both forms (and the picker offers
  the slug), so the tint, the block and the recipe exclusion apply to the real cabinet.

### Guard
- `scripts/test-location-rail.php` (rewritten): runs the shipped `renderLocationRail()` and
  `_dashboardLocationGo()` in node against stubs and locks the new grammar — the places are one
  `<select>` (one option each, count + ⚠), the cabinet is **not** an option and keeps its 💊 pill
  with its own count and red dot, a custom place gets its own option, a hostile name is escaped,
  the `＋` is the last control and picking a place opens the pantry on it and resets the select.
- `scripts/test-medical.php` gained the code-format block: every national definition carries a
  whole-code `pattern`, `16G87N` is **not** a medicine code, the AIC is recognised as
  `A017837016` and as the bare 9 digits, a number above 131070 is not claimed, a letter-carrying
  code never reaches the food catalogues, and the shipped JS detector is run in node and
  compared with the PHP one over a probe list (including the EAN that must stay a food path).
- `scripts/test-medical.php` gained the edit-screen block: the position group is built from
  `_locationsForEdit()` in both edit forms (never from every location), a single legal place hides
  the block, and the shipped `_editDomainIsMedical()` + `_locationsForEdit()` run in node — food
  never gets the cabinet button, a medicine only its cabinet, a food flagged as food keeps a way
  OUT of the cabinet, and the legacy `altro` key still reads as the pantry.
- `scripts/test-location-order.php` grew the strip behaviour: it runs the shipped
  `_syncLocationFilterUi()` + `_syncInventoryTitle()` in node and locks that opening a place
  hides the cabinet pill and writes "🧊 Frigo" in the header, while "Tutti" brings both the page
  name and the pill back (the `[hidden]` rule is checked in CSS too).
- `scripts/test-location-rename.php` (new): the action is dispatched, the handler moves stock
  + history + the medicine marking in one transaction, rewrites the label list in place,
  writes/reads back the env value, refuses with `error_key`s, and the client paints a pencil,
  a dialog and calls the action; every label exists in six locales.
- `scripts/test-credits.php`: the splash body must not print `preloader-api-name`, the free-API
  card opens the dialog above the support card, and the five `credits.why_*` keys exist in all
  six locales. `scripts/test-preloader-stages.php` now locks the 3 s floor and the absence of
  the warm-boot shortcut; `scripts/test-medical.php` locks the national-code short-circuit,
  the relaxed length floor and the UI-language country fallback.
- `scripts/test-location-order.php` (new): runs the shipped `mergeCustomLocations()` in node
  and locks the one order (Dispensa → 💊 cabinet → fridges → rest) and the one pair of icons,
  whatever order the places were typed in and whether the marking was stored as a label or a
  slug.

## [Unreleased] — Ideas & Roadmap

> Ideas collected during development. No priority or date implied.

- **G1–G3 / G6–G7** — Real money ledger, multi-user roles, multi-list UX, recipe matching depth, offline conflict resolution.
- **Heavy items** — Receipt OCR, voice commands, geofencing, AGP 9 / SDK 37 (separate projects).
- **CI INDEX drift job** — `regen-code-index` in `.github/workflows/ci.yml` needs a `workflow`-scoped PAT to push; until then run `bash scripts/gen-code-index.sh` locally (see `todo/CI-INDEX-NOTE.md`).

## [1.26.0] - 2026-10-27 · branch `refactor_25_10_2026`

### Added
- **The three open barcode sources the app really queries are now in the credits, with their
  logos**: **Open Products Facts** (parapharmacy, devices, supplements — the family that
  recognises most over-the-counter packs), **Open Beauty Facts** (cosmetics/hygiene) and
  **Open Pet Food Facts**. The resolver has been calling them for a while (`opf`, `obf`,
  `opff` in the lookup chain) while the credits listed only Open Food Facts, Open Prices and
  Open-Meteo: the brand assets are vendored from the projects' own static server
  (`assets/img/apis/open-{products,beauty,pet-food}-facts.svg`), the licence is ODbL/CC-BY-SA
  and each row says what it is used for and where, in the six locales.
- They are in the service-worker shell too, so the splash and the credits page paint offline.

### Fixed
- **A commercial trial endpoint was in the default lookup chain.** `api.upcitemdb.com/prod/trial`
  was called on every miss — no credit, no opt-in, a barcode leaving for a third party by
  default. It is now behind `UPC_LOOKUP_ENABLED` (default `false`, documented in `.env.example`
  and in the settings allowlist), so the free, credited sources answer first.

### Guard
- `scripts/test-credits.php` now fails when the resolver queries a barcode host that is not in
  `data/free_apis.json` (compared by registrable domain, so `it.openfoodfacts.org` counts as
  `world.openfoodfacts.org`) and when the trial endpoint comes back as a silent default. The
  rule “every source that answers a scan is credited” is now enforced, not remembered.

## [1.25.1] - 2026-10-27 · branch `refactor_25_10_2026`

### Added
- **The credit list now names its sources, where the free APIs are.** The medical code schemas
  are built from documents that are not called at runtime: the **GS1** prefix list, the
  **Pharmacode/Laetus** article, the German **PZN** article and the **EU FMD** delegated
  regulation (CC BY-SA where it applies) — plus **AIFA** marked *Phase 2, not used yet*. They
  are rows in `data/free_apis.json` (`sources`), generated into `EVERSHELF_DATA_SOURCES` and
  painted on the **Crediti** page right after the API cards, each with its licence and link.
- The first row of that block is the module itself: **“EverShelf Med — nessuna API esterna”**,
  so nobody has to wonder why medicines never appear among the services.

### Notes
- No medicine API is used, in any country: `api/lib/medical.php` makes no network call and the
  module only talks to our own endpoints (`med_status`, `med_symptom_search`, `med_classify`,
  `med_open`). A logo would have to belong to a service that exists.

## [1.25.0] - 2026-10-27 · branch `refactor_25_10_2026`

### Added
- **Per-country barcode schemas, as data.** `data/medical_code_schemas.json` now says what a
  medicine pack looks like in 17 countries, with the sources in the file: GS1 prefix ranges
  (Italy `800–839`, Germany `400–440`, France `300–379`, Spain `840–849`, UK `500–509`,
  US `000–139`), national formats (`pharmacode` for the Italian **SSN** label — an integer
  3–131070 read from bars, not decimal digits — `pzn` 8 digits in Code 39 for Germany,
  `pzn_in_ean` `908888…` for Austria, `cn`, `ndc`, `aic`) and whether the EU FMD DataMatrix
  applies. A contributor adds a country by editing the JSON: no code, no migration.
- **The scanner asks the schema, not a guess.** `_medDetectCode()`/`medicalDetectCode()` answer
  `high` (Italy/Germany: recognised, straight to the medical form), `probable` (partial
  countries: the app asks) or nothing at all.
- **Country picker** in *Settings → Farmaci* (`med_country`, stored in `app_settings`; country
  names come from `Intl.DisplayNames`, so there is no 17th translation table to maintain).

### Changed — blocked where we have no data
- **A country with no schema claims nothing.** The scan no longer pretends: it says there is
  no schema for that country and leaves the box to the manual path (💊 in the product form).
  That is the “block it where you could not get the information” rule, applied to recognition.
- **Honest gap, documented:** the EU FMD **DataMatrix** is declared (`fmd: true`) but not yet
  decoded — browser barcode engines rarely expose `data_matrix`, and its GS1 element string
  (GTIN + expiry + batch + serial) needs its own tests. Decoding it is the next step that
  would prefill the expiry date straight from the box.

## [1.24.1] - 2026-10-27 · branch `refactor_25_10_2026`

### Changed
- **The recipes have their nav slot back.** Round 14 traded the "🍳 Ricette" tab for "📷 Scanner"
  on the argument that the dashboard already carries the orange recipe CTA — in use that made
  the recipes feel *gone*. The bottom bar is Home / Dispensa / **Ricette** / SHELFY / Spesa /
  Storico / Config again, and the **scanner keeps no slot**: it is still one tap away from the
  dashboard, the product modal and the spesa flow (all of them call `showPage('scan')`), which
  is how it was actually reached.

## [1.24.0] - 2026-10-27 · branch `refactor_25_10_2026`

### Fixed
- **Scanning a medicine box did nothing.** Italian boxes carry an **EAN-13** (often `80…`, GS1
  Italy) — and the medical path explicitly excluded 13-digit codes, so the lookup ended on Open
  Food Facts (which has no medicines), fell to the generic "not found" toast and the camera
  resumed. With the module on, an unknown code is now a **decision, not a dead end**:
  - Pharmacode/Laetus/short code → straight to the manual entry, **medicine switch already on**
    and the 8 category chips ready;
  - EAN-13/EAN-8 the food catalogues miss → one question: *"È un farmaco o un integratore?"*
    → **💊 Sì** (medical form) / **No** (normal product) / [Annulla] (camera resumes, via Esc too).
- The scanned code is kept on the box, so the same pack scanned again is still identifiable
  even though no catalogue answered.

### Changed — honest about what is *not* there
- **No medicine catalogue is queried, in any country.** The module states it in
  *Settings → Farmaci* (`medical.no_catalogue`: the barcode — SSN label included — stays
  attached to the box, the name is typed) and the **credits page** now closes the list with
  what no free API covers (`credits.medicines_note`): Open Food Facts has no medicines, national
  datasets (AIFA) arrive only in Phase 2, filtered to OTC/SOP. The missing service belongs in a
  credit list.
- **No country gate, on purpose:** with nothing looked up there is nothing to gate — the module
  works everywhere. Phase 2 (a national catalogue) is when the gate becomes mandatory, and the
  wiki says so: `docs/wiki/Medicines.md` documents the EAN vs SSN/Pharmacode reality and the
  scanner's behaviour in a table.

## [1.23.2] - 2026-10-27 · branch `refactor_25_10_2026`

### Changed
- **The five paragraphs of the notice are resolved with literal `t()` calls.** They were
  built as `t('medical.' + k)`, which works at runtime but is invisible to
  `scripts/i18n-audit.py` (its JS regex only sees a bare key): a paragraph that silently
  lost a translation would not have failed the build. The keys are now written out, and
  the guard test locks all five in the six locales.

## [1.23.1] - 2026-10-27 · branch `refactor_25_10_2026`

### Fixed
- **Enabling the medicine cabinet did nothing.** The first-run "Attiva" and the Settings switch
  saved through `_saveSettingToServer()`, which posts to **`save_settings`** — the `.env`
  writer, whose key map has no `med_enabled`, no `med_disclaimer_accepted`, no
  `medical_locations`. The keys were silently dropped, the screen landed on Settings, and the
  switch was still off because the server had never been told. The module now writes through a
  dedicated `_medSaveInstanceSettings()` → **`app_settings_save`** (and `med_setup_done` was
  added to the writable allow-list); after saving, the UI **re-reads the server** so the switch
  can never disagree with what was stored, and a failed save says so instead of pretending.

### Changed
- **The first run is a real three-step flow:** question → **full notice** → **the place**.
  Step 2 is no longer a terse line: five paragraphs that say what the app is (an inventory),
  what it never does (no diagnosis, no therapy, no dose), that the symptom search is a reminder
  of what you bought (not an opinion for you, your allergies, your other medicines or a child),
  never a substitute for advice and never for an emergency, what the dates mean (printed date +
  your after-opening days, expired boxes go to the pharmacy), and that the data stays on your
  server and the module can be switched off without deleting the rows. The consent checkbox
  gates the step (the button starts disabled).
- **Step 3 requires the dedicated location**: pick an existing one or create it right there
  ("Armadietto farmaci" prefilled). Finishing stores enable + consent + location + setup_done
  in one save and opens the module with a confirmation toast.
- **"↺ Rivedi l'introduzione"** in *Settings → Farmaci*: the question can be reopened at any
  time (it forgets the answer on the server, not the data). The one-time question now follows
  the **server** (`med_setup_done`) instead of this browser's localStorage, so a reset shows it
  again on every device.

## [1.23.0] - 2026-10-27 · branch `refactor_25_10_2026`

### Added
- **The product form asks the question the scanner could not answer.** A medicine box scanned
  into the catalog used to become a normal product that had to be re-classified later, in
  another section. The form now carries **"💊 È un farmaco / integratore"** (only while the
  module is on): turning it on shows the **8 closed categories** as chips, the active
  ingredient and the **PAO days** in place, and the add step lands in the **dedicated
  location** instead of guessing the pantry. The fields are stored through `med_classify`
  (the one action that owns those columns, with the tag allow-list and the PAO bounds), so
  `product_save` stays untouched and there is no second dictionary of medical fields.
- Opening a product for editing shows its own medical fields (toggle on, chips selected,
  ingredient and PAO filled), so correcting a box is the same gesture as creating it.

## [1.22.2] - 2026-10-27 · branch `refactor_25_10_2026`

### Fixed
- **The opened banner threw `_openedQueueSiblings is not defined` on every dashboard paint.**
  `dismissBannerItemAfterAction()` was missing its closing `}`, so the five functions below it
  (`_openedQueueSiblings`, `_showOpenedTab`, `bannerUseInRecipe`, `bannerSnoozeOpened`,
  `_bannerMenuToggle`) were parsed **inside** it: `node -c` passed, the file loaded, and the
  notice crashed at runtime — "🗑️ Butta / 🍳 Usa per ricetta / ⋮" on an opened product did
  nothing. The stray brace that used to close the outer function was removed with it.
- **Same class of bug in `receipt.js`** (`_receiptCancel()` swallowed `_receiptRender` and
  `_receiptConfirm`): fixed too, even though the receipt flow is parked.

### Added
- **`scripts/test-js-structure.php`** — the guard that was missing: no top-level `function`
  may be swallowed by a missing brace (brace matching over every shipped script), and every
  inline handler in the markup must resolve to a real top-level function, binding or browser
  API. This is the trap `AGENTS.md` warned about for `core/vocab.js`; now it cannot happen
  silently in `app.js` either.

## [1.22.1] - 2026-10-27 · branch `refactor_25_10_2026`

### Fixed
- **The medicine cabinet dialogs had no surface.** The first-run question (and the mandatory
  notice and the symptom search) were built with `class="modal-card"` — a class that does not
  exist in the stylesheets (the app uses `.modal-content`, which is what paints
  `background: var(--bg-card)`, radius and padding). The overlay dimmed the page while the
  dialog itself was transparent, so the text floated over the dashboard. The three dialogs now
  use the real class; one shared primitive, no new CSS.
- **And above the splash.** The first-run question is drawn while `#app-preloader`
  (z-index 200000) may still be on screen and `#network-error-overlay` sits at 300000,
  so the overlay's default 200 could hide it behind the app. The three medical dialogs
  now sit at 300500 — above both, below the auth overlays (400000) that own pairing.

## [1.22.0] - 2026-10-27 · branch `refactor_25_10_2026`

### Added
- **💊 Farmaci is a section of its own** (`Settings → 💊 Farmaci`, next to *Cucina*), and the
  module is **asked once at boot** when it has never been configured: *"Vuoi gestire anche i
  farmaci?"* → **Attiva** (with the mandated notice) or **Non ora**, which is stored as a
  decision and never asks again. Off by default either way.
- **A dedicated location, with its own colour.** The module cannot be enabled into the pantry:
  pick the location that already holds the medicines or **create one** ("Armadietto farmaci"),
  and that location gets its own pill/tint because it holds only that. Medical rows carry a
  💊 tag and a tint (`.alert-item.is-medical`), never a new palette.
- **A closed taxonomy of 8 household categories** (`data/medical_categories.json`): *Febbre e
  dolore, Stomaco e digestione, Raffreddore/tosse/gola, Allergie, Ferite e primo soccorso,
  Occhi e vista, Crampi e muscoli, Integratori e vitamine* — multi-select on the box, never
  free text (a free tag would rot the offline search). Each category carries the words the
  household actually says in the six shipped locales and the ATC prefixes it will map to when
  the AIFA import lands.
- **Simplified PAO per medicine.** "Scade dopo l'apertura: [30] giorni" + **🔓 Segna aperto**
  on each box: the operational expiry becomes *today + N* (`expiry_user_set = 1`, so a rescan
  cannot undo it) and the notice fires from that date. Only the boxes with a number set are
  rewritten; the printed date still rules until the seal is broken.
- **The scanner knows the difference.** A Pharmacode/Laetus or a short code the food
  catalogues cannot know is **not an error**: with the module on, the app opens the manual
  entry prefilled and says *"Codice rilevato. Inserisci il nome del farmaco e scegli a cosa
  serve."*; EAN-13/EAN-8 stay on the food path, and a known medicine opens the product modal.
- **Medicines keep their place in the expiry lists** (expiring rows with the 💊 tint) while an
  **expired** box still leaves the "Scaduti" block for its own one with **🗑️ Smaltito** —
  special waste, never "save it by cooking it".

### Notes
- **Free APIs**: already surfaced in the **preloader** strip and in *Settings → 💚 Crediti*
  (with what each service gives us, where we use it and its licence), fed by
  `data/free_apis.json`; nothing new was needed here.
- **Phase 2 (parked, documented)**: AIFA open data filtered to OTC/SOP classes only (a 2–3 MB
  local catalogue instead of the whole hospital dataset), Pharmacode → AIC decode on the
  scanner, duplicate-active-ingredient warning and the leaflet link. Nothing of this phase
  invents drug data.

## [1.21.0] - 2026-10-27 · branch `refactor_25_10_2026`

### Added
- **💊 EverShelf Med — the medicine cabinet, opt-in.** Off by default (`MED_ENABLED` or the
  *Settings → Salute* switch): a household that only counts tomatoes never sees a single
  medicine string. Turning it on **without accepting the notice** opens the notice instead of
  enabling anything — the acceptance is stored once per instance (`med_disclaimer_accepted`),
  so a new phone is not re-asked, and the medical UI does not open without it.
- **Two domains, never mixed.** A location can be marked as medical (*Settings → Salute*), and
  `products.is_medical` marks a single box. Those rows are **excluded from the recipe flow**
  (`inventory_list?for_recipe=1` filters them server-side) and an **expired medicine leaves the
  “Scaduti” block** — it is not food to save by cooking: it gets its own block with
  **🗑️ Smaltito** (special waste, “portali in farmacia”).
- **“What do I have for…?” — deterministic and offline.** `data/medical_atc.json` maps ATC groups
  to labels and to the words a household actually says, in **all six locales**; `medicalMatchSymptom()`
  tries the **longest phrase first** (“mal di testa” before “testa”), `med_symptom_search` returns
  only the medical rows that match, and **no AI, no network, no dosage** is involved. The
  disclaimer is the first block of the answer, and the copy never states a dose (guard-tested).
- **Classification without a fake catalogue.** The AIFA/AIC import is a later step (and inventing
  drug data is not an option), so the household teaches the module: each box in a medical
  location gets an **ATC group chosen from the local map** (never free text) and its **active
  ingredient** (`med_classify`, logged as `med_classify`). That is what the symptom search reads.
- **SHELFY asks the map, not the model.** When the capture cannot act on a sentence and the module
  is on, “ho un forte mal di testa” is handed to the local symptom map; if it matches, the capture
  sheet closes and the answer opens in its own sheet — the sentence is never turned into a product.

### Docs
- `data/medical_atc.json` is **data**, like the vocabulary: a new symptom group is a JSON row plus
  six label keys. Guard test: `scripts/test-medical.php` (42 checks: opt-in, disclaimer, domain
  separation, matcher, safety copy, schema, wiring).

## [1.20.0] - 2026-10-27 · branch `refactor_25_10_2026`

### Fixed
- **The scale no longer lies about being connected.** The Android bridge answers every new
  relay client by **replaying its cached status** (`connected`, device `QN-KS`, battery) **and
  the last weight** — with the physical scale switched off. The app trusted that one message
  and painted a green pill with “448.5 g” that nobody had just weighed (verified by dumping
  the relay: status + one weight, then silence for 12 s). Rules now: the **first** reading of
  a relay session is the cached replay and is ignored (never shown, never used to autofill a
  form); a reading is **live** only when a second one arrives and the last is younger than
  `SCALE_LIVE_MS` (10 s); **“gateway reached” alone is not “scale on”** — the pill stays neutral
  with an amber pulse and says *waiting for the scale* (`scale.status_waiting`, six locales).
  The live window also expires by itself (5 s tick), so a replay that stopped cannot leave a
  weight on screen.

### Added
- **The scale is a hardware indicator, not a ghost icon** (audit, round 14). It was a white
  glyph on a white header with a 6 px dot, and its feedback was a toast that covered the
  buttons. Now: **hidden** when no gateway is configured; a neutral pill with a crossed-Bluetooth
  look while it searches; a **mint pill with the live weight** (`⚖️ 245 g · 🔋`) when a real
  reading streams. Tapping opens a **popover** under the icon — device, battery, the weight in
  large, **Tara** (local baseline, since the bridge is read-only) and **Disconnetti/Connetti**
  — instead of a bottom toast.
- **The scanner owns a nav slot.** The bottom bar had a “Ricette” tab while the dashboard
  already carries the orange recipe CTA; the slot now reads **📷 Scanner**, so the camera is
  under the thumb on a phone or a kiosk. Recipes keep their CTA and SHELFY.
- **Scanning something you already have opens the product modal** (consume / consume all /
  throw) instead of the old full-page product sheet: a barcode at the fridge is an action, not
  a description to read.

### Changed
- **The shopping session scans first and asks for the total at the end.** Tapping the cart used
  to open the camera **and** the “how much did you spend?” dialog at once — a form on top of the
  live video, covering the first product. Now: cart → continuous scanning → *Fine spesa* →
  optional **numeric keypad** with the total (only when something was actually loaded),
  `[Salva totale]` / `[Salta]`.
- **The receipt file-upload is gone** (the `Scontrino` button on the scan page, and its settings
  toggle is parked/hidden): nobody browses a file manager on a wall tablet while emptying the
  bags, and a crumpled thermal receipt is exactly what local text extraction gets wrong. The
  OCR code stays in the repo, unreachable, for the day a camera path lands.

### Tests
- New `scripts/test-spesa-flow.php` (24 checks): camera first, amount only at the end, keypad
  present, `spend_add` still the ledger entry, no file input on the scan page, six locales.
- `scripts/test-ui-palette.php` grew to **75 checks**: the scale pill/popover, “a gateway replay
  cannot paint a live pill”, the scanner tab replacing the recipes tab, the ✓-opens-the-modal
  rule, and the barcode→modal rule. `scripts/test-receipt-ocr.php` now asserts the entry point
  is **absent** and the toggle parked.

## [1.19.1] - 2026-10-27 · branch `refactor_25_10_2026`

### Fixed
- **No more flashbang at boot.** The splash is `#0c1222` and the app is white: swapping one
  for the other in a frame blinded a dark kitchen. The launch is now three soft steps — the
  content fades (200 ms), the canvas lifts to the app colour (`is-lifting`), then the element
  fades out in **300 ms** and is removed after the transition (it used to be removed 60 ms
  early, cutting the fade).
- **The boot check list is for developers, not for the household.** Thirty lines of
  "Controllo PHP", "PHP upload (16M)", "Config sessione"… and 40 ms of artificial wait each
  (plus a forced 3 s minimum splash) are gone from normal boots: the rail still settles — so
  a real failure stays visible with its popup — and the only line is **"Sistema pronto"** for
  a beat. The full list is back with `?debug=1` (or `localStorage.evershelf_debug = '1'`),
  where the 3 s pacing and the per-check labels still apply.
- **One surface for "consume", not two.** The row's **✓ Usa** closed the sheet and navigated
  to the legacy full-page USE form (the old red "Usa TUTTO", the green bar, the purple
  recipe button) while the same row's **⋮** opened the clean product modal — the same
  decision drawn twice. The ✓ now opens **the same modal** (stepper + primary), and
  **"Ho consumato tutto"** keeps the slide-to-confirm dialog **over the modal** instead of
  navigating (`_loadUseProduct()` separates the data load from the page). The guided page is
  left to SHELFY and the scale, which drive its fields.

### Changed
- **The credits left the overview.** That footer took a third of a phone screen right above
  the nav bar, stealing space from the food lists: the thanks now live in **Settings → 💚
  Crediti** (and in the 🔌 card), while the **splash** keeps showing the marks at boot.

### Tests
- `scripts/test-preloader-stages.php` locks the debug gate (`?debug=1`, debug-only 40 ms and
  3 s pacing), the `is-lifting` sequence and the 300 ms fade;
  `scripts/test-ui-palette.php` locks "✓ Usa opens the modal" and "consume everything stays in
  the modal"; `scripts/test-credits.php` now asserts the overview is **free** of the credits
  footer.

## [1.19.0] - 2026-10-27 · branch `refactor_25_10_2026`

### Added
- **The official logos of the free services, everywhere — and a page that thanks them
  properly.** The credits now use the projects' **own brand assets**, vendored under
  `assets/img/apis/` (no third-party request at runtime, and a missing file falls back to the
  monogram instead of a broken image):
  Open Food Facts (`off-logo-icon-light.svg`), Open Prices and Open-Meteo (their published
  app icons). They appear in **four** places, all fed by the same `data/free_apis.json` →
  `assets/js/free_apis.generated.js`:
  1. the **splash**, under the boot rail, painted before any token exists and offline;
  2. at the **bottom of the overview** (`#app-footer-dashboard`);
  3. the **Settings card** (🔌 API gratuite);
  4. a **dedicated thanks page** — *Settings → 💚 Crediti* — where each service gets a
     block with its logo, what it gives us, **where and for what we call it**, the licence
     and the link, plus the note that the logos belong to their projects and are used only
     to identify them.
- **`scripts/test-credits.php`** (new, 62 checks) locks it all: the list, the logo files
  really being images and never a remote URL, the four surfaces, the generated file in the
  service-worker shell, one "where" sentence per service, and the copy in all six locales.

### Changed
- `data/free_apis.json` gained a `logo` field (version 2) and the `logo_note` attribution;
  the generator and `GET free_apis` pass the logo through only when it is a **local**
  `assets/img/*` path, so a thank-you can never become a tracking pixel.
- The Settings tab strip gained a tab (`tab-credits`, section *system*); the search schema
  was regenerated (`php scripts/gen-settings-schema.php`).

## [1.18.1] - 2026-10-27 · branch `refactor_25_10_2026`

### Added
- **The splash credits the free services too.** The boot screen now shows the marks of the
  open data providers under the boot rail — the same list as the footer and the Settings
  card. It is painted from **`assets/js/free_apis.generated.js`** (generated from
  `data/free_apis.json` by `php scripts/gen-free-apis-js.php`, in the service-worker shell):
  at splash time there is no token yet and the app must boot offline, so the row cannot be a
  fetch. Marks and names only, decorative for screen readers (`aria-hidden`) and quiet on a
  short window (names drop below 560 px height); the accessible credit with the licences
  stays in *Settings → 🔌 API gratuite*, and the Settings card now reads the same generated
  file first, falling back to `GET free_apis` only if the bundle is stale.

### Tests
- `scripts/test-vocabulary.php` now locks `free_apis.generated.js` in sync with
  `data/free_apis.json` (same rule as the vocabulary and the anti-waste facts), and
  `scripts/test-preloader-stages.php` asserts the row, its style, the generated script in the
  page and in the service-worker shell, and that the painting happens at `DOMContentLoaded`
  from the data (never a hardcoded list).

## [1.18.0] - 2026-10-27 · branch `refactor_25_10_2026`

### Added
- **The post-opening clock (PAO) is deterministic — and it is DATA.** The printed date on a
  package only speaks for a sealed package; once it is opened the clock that matters is the
  post-opening stability. It used to be answered by an **AI call** (paid, non-deterministic,
  sometimes hallucinating “60 days for yoghurt”) on top of a 200-line regex chain. Now
  `data/opened_shelf_life.json` carries **32 macro-families** with two thresholds per storage
  place — `warn` (quality starts to go) and `critical` (almost certainly spoiled) — sourced
  from USDA FoodKeeper + EFSA/EC guidance, with the keywords of the six languages in the
  data and `api/lib/opened_shelf_life.php` as the only reader (`openedShelfLifeThresholds()`,
  `openedShelfLifeState()`, `openedShelfLifeSummary()`). No model call, no network.
- **The “opened too long” notice has its own state machine.** `getStats()` now ships a `pao`
  block on every opened row (`days_open`, `warn`, `critical`, `state`, `score`), and the
  banner judges an opened row **by that clock instead of the printed date**: a wine open for
  17 days in the fridge (4 / 10) is now *critical* — “probabilmente andato” — where it used to
  show a generic “Controlla” because the bottle “expires” in 2027. Amber rail for `warn`, red
  for `critical`.
- **Priority is a severity score** (`days open ÷ critical`): a critical opened row outranks
  everything (3000+), a warning one comes next (1500+), then a printed-date danger. The notice
  names the one that matters most and adds a discreet **“e altri N prodotti aperti da
  verificare”** that jumps to the *Aperti* tab (a carousel of dots made the second product
  invisible).
- **A recovery action, not only a bin.** The opened notice answers the two decisions that must
  be fast: **🗑️ Butta** (red primary) and **🍳 Usa per ricetta** (neutral, SHELFY asks the
  recipe engine for a dish that uses it up). *Finito*, *Modifica* and **“È ancora buono”**
  (snooze for 3 days, stored in `app_settings.opened_check_snoozed`) live behind a compact
  **⋮** that opens **in place** — the primary actions are never hidden, the rest is one tap
  deep, and the strip keeps the same one-colour grammar.
- **Credits for the free services, in the app.** `data/free_apis.json` lists what this
  instance actually talks to — **Open Food Facts** (ODbL · CC-BY-SA), **Open Prices** (ODbL),
  **Open-Meteo** (CC BY 4.0) — with the licence each one asks to be credited with, served by
  `GET free_apis` and rendered twice: a horizontal **footer** (names in a row, each with what
  it gives us) and a **Settings → 🔌 API gratuite** card with a thank-you line. The monogram is
  neutral on purpose: official logos are trademarks and are not shipped in the repo.

### Changed
- **A false positive fixed before it shipped:** shelled nuts were matching the “frutta”
  keyword of fresh produce (critical after 25 days) and breadcrumbs were matching fresh bread
  (critical after 148 days). Two extra families — `nuts_dried` and `breadcrumbs` — and a
  `gnocc*` stem were added after checking the real pantry.

### Tests
- `scripts/test-opened-shelf-life.php` (new, 125 checks): the table schema, the thresholds the
  audit asked for (wine 4/10, milk 3/6, sauces 3/7, soft cheese 4/8, mayonnaise 30/60,
  biscuits 20/45, default 5/10), **23 real product names mapped to the right family** (with
  the two false positives above), the state machine boundaries, the `pao` summary, the server
  and client wiring, and the ten new keys in all six locales with their placeholders.

## [1.17.0] - 2026-10-27 · branch `refactor_25_10_2026`

### Added
- **The product photo leads every row.** A packaging is recognised in milliseconds (the
  colours of a biscuit box, the silhouette of a milk bottle); a title like “Vino bianco
  Trebbiano IGT Rubicone (Vesoletto)” is read word by word. Home, the banner and the pantry
  now open with the same **44×44** thumbnail in a neutral frame (light surface, hairline
  border, 8 px radius, `object-fit: contain` so a transparent barcode PNG is not cropped),
  rendered by one helper — `_productThumbHtml()` — and by `_bannerIconSet()` for the notice
  strip. The fallback is **semantic and never empty**: the category emoji (🍎 🥛 🥫 …,
  📦 when the category is unknown) sits under the picture and is revealed when the URL is
  missing or fails to load, so a product nobody has photographed still has a face. The
  pantry's `.inv-image` was brought to the same frame; the detail modal is untouched.
  `getStats()` now ships `p.image_url` for the “in scadenza” and “scaduti” lists (the rows
  had no photo to show), while the opened/forgotten lists already selected it.

### Changed
- **One action colour in the banner.** The strip kept four pastel tones ([Finito] sky,
  [Buttato] pink, [Modifica] azure) that made the notice look like a different app from the
  rows underneath: now only the answers that write the outcome (`Usa`, `Conferma`,
  `Sì, è finito`) are solid emerald, everything else is a neutral secondary. Every action
  stays **inline** — hiding them behind a ⋮ is the regression this strip was rebuilt for.
- **SHELFY’s “Iniziamo” is emerald.** The purple `#7C3AED` left over from the old accent is
  gone: the assistant’s primary is the app’s primary (`#059669`), one tap away from the
  “Esegui” of the same sheet.
- **“Controlla” is a neutral tag, not a yellow badge.** The safety verdict that asks for a
  look no longer competes with the expiry badge it refers to; `Va bene`, `Non mangiare` and
  `Buttalo` keep their colour — those answer the question the user actually asked.
- **The example pills wrap instead of scrolling.** `.capture-hints` goes `flex-wrap: wrap`
  with `justify-content: center`: the last pill was cut in half by the edge of the sheet.

### Tests
- `scripts/test-product-thumb.php` (new) locks the component, the 44×44/`contain`/neutral
  frame, the emoji fallback (and the 📦 default), the escaped URL, the banner and pantry
  wiring and both `getStats()` queries; `scripts/test-ui-palette.php` now asserts the banner
  grammar (emerald answers + neutral secondaries) instead of the pastel classes.

## [1.16.39] - 2026-10-27 · branch `refactor_25_10_2026`

### Fixed
- **SHELFY no longer swallows the last word.** Two shapes did it. The transcript join
  compared *characters*, so a one-letter overlap was enough to eat the start of the next
  word: `"aggiungi il"` + `"latte"` became `"aggiungi il atte"` (the product then did not
  exist) — the join is now **word-level**. And Chrome on Android closes the recognition
  session at the first final phrase even with `continuous = true`: `onend` submitted right
  there, so `"Ho usato 2 arance"` arrived as `"Ho usato 2"`. A session the **browser**
  ended mid-sentence is now **resumed** (`_CAPTURE_RESTARTS_MAX`, bounded) and keeps the
  same sentence; only the session **our own silence deadline** stopped may hand the
  sentence over (`_captureVoice.stopRequested`). The deadline itself goes from 1.8 s to
  2.5 s, because a natural pause between `"ho usato"` and `"2 arance"` was closing it.
- **No more doubled phrases.** Chrome capitalises the interim result and lowercases the
  final one: `"Ho mangiato"` + `"ho mangiato una mela"` printed `"Ho mangiato ho mangiato
  una mela"`, because the raw-string comparison saw two different prefixes. The join
  ignores case now, and a word the browser was still spelling out (`"la"` → `"latte"`) is
  one word, not two.
- **A sentence it cannot act on says so — it never opens “Nuovo prodotto”.** Both
  fallbacks (an add intent with no catalogue match, a spoken name with only a derived
  article in the pantry) closed the sheet and opened the empty new-product form, for a
  product that does not exist. They now say “I did not understand” (`_captureNotUnderstood()`,
  which also removes the “Ho capito” chips) and leave the sheet open for a retry. Creating
  an article stays the user's decision.
- **`capture.no_product` no longer promises an add form** (all six locales): it reads
  “I did not understand: {name} is not in the pantry. Try the exact name.”

### Changed
- **The sentence SHELFY heard is the biggest text in the sheet.** It is what the user
  checks before confirming an action, and it was a 15 px italic footnote: now a highlighted
  block, 1.06 rem and semibold (bold once final), with `overflow-wrap` so a long word
  cannot escape the card.

### Tests
- `scripts/test-capture-parse.php` locks it: the join in node (case, word boundary,
  restarted phrase, shorter echo, repeated number), a fake recogniser that closes the
  session mid-sentence (nothing may be submitted at that moment, the resumed session must
  deliver `"Ho usato 2 arance"` once), no capture path may call `startManualEntry()`, and
  the transcript CSS must stay 1 rem+ / semibold on a tinted background.

## [1.16.38] - 2026-10-26 · branch `refactor_25_10_2026`

### Fixed
- **SHELFY no longer interrupts you mid-sentence.** The browser marks a phrase *final* at
  the first short pause, and the loop submitted that chunk: “Ho usato 200 grammi di pasta”
  arrived as “Ho usato”, the sheet closed and — with no product in the parse — the add form
  for an article that does not exist opened. The recogniser now runs **one session per tap**
  (`continuous = true`) closed by our own silence deadline (`_CAPTURE_SILENCE_MS`, 1.8 s,
  armed by the first result so taking a breath is not an error), finals and the
  never-finalised interim text are glued into one sentence, and **only `onend` submits** —
  no restart loop, so no start chime either. New guard drives the real
  `_captureStartVoice()` against a fake recogniser that emits exactly that sequence: the
  first final must not be submitted and the whole sentence must arrive once.
- **One sentence, not “ho ho ho usato…” (twice over).** The Web Speech API is not
  consistent: Chrome re-delivers the finished results on later events, and Chrome on
  Android **restarts `ev.results` at every phrase** with the phrase itself already
  cumulative. Appending printed “ho ho ho usato ho usto ho usato una” on the first shape
  and “ho consumato ho consumato ho consumato 250 ho consumato 250 grammi …” on the second,
  for a single sentence. The transcript is now rebuilt from the event and **joined by its
  overlap** (`_captureJoinText`: a longer text that already starts with what we heard
  replaces it, a restarted one is stitched once, a shorter echo never erases anything) and
  the interim belongs to the same sentence, so it cannot survive as a duplicate. The
  fake-recogniser guard replays all three shapes and asserts “ho consumato 250 grammi di
  pasta” arrives exactly once.
- **No “Ho capito” written above “non ho capito”.** The chips *are* the interpretation:
  they are shown only for a sentence that carries an action. A bare “ho usato” hides them
  and the status explains that SHELFY did not understand, with the transcript still on
  screen and the sheet open for a retry.
- **A sentence with no product is not an action.** `_captureSubmit()` now goes through
  `_captureIsActionable()`: a bare “ho usato” / “ho finito” (or an empty transcript) says
  *not understood* and keeps the sheet open for a retry, instead of opening an empty add
  form (recipe questions stay valid). Locked in node: bare intent → not actionable, spoken
  product → actionable.
- **SHELFY could not be closed and heard nothing.** A refactor deleted
  `_captureVoiceStop()` while three callers kept calling it: the `ReferenceError` stopped
  `_closeCaptureSheet()` at its first line, so the dialog stayed on screen (and Esc did
  nothing), and it stopped `_captureStartVoice()` at its first line, so the recogniser
  never started — no transcript, and the microphone never filled with the voice.
  The function is back, closing now hides the sheet **before** any cleanup can fail
  (a cosmetic error can never trap the user again), and `scripts/test-capture-parse.php`
  locks both: every `_capture*()` call must have a definition, and the sheet's closer
  must hide first.

### Changed
- **The USE confirm drains again — 10 s, then SHELFY confirms.** When the agent opens the
  “Usa” sheet from “ho usato …” it arms *Usa questa quantità* with the reverse progress bar
  (`_shelfyArmButton`, `.shelfy-armed` → `shelfy-drain`, `--shelfy-ms: 10000`): any touch
  anywhere cancels it and leaves the decision to you, otherwise the quantity is consumed.
  The page's own 15 s idle confirm is cleared first, so a single bar runs and it also works
  for grams and millilitres. “Ho finito …” (use-all) and *Aggiungi* still wait for a tap —
  emptying a row or creating an article is not something the agent does on its own. The
  toast now says the 10 seconds (`capture.qty_hint`, all six locales), which is what the
  intro already promised.
- **The capture lexicon is DATA, one schema per language.** The intent words, spelled
  numbers, units (with their multiplier: `etti` → g ×100), fractions, "all of it" and
  stop words left `app.js` and now live in `api/vocab/<lang>.json` under `"capture"`
  (intent, numbers, units, fractions, all_words, stop_words) —
  the same vocabulary file the product titles already read, generated for the browser by
  `scripts/gen-vocab-js.php`. `assets/js/core/vocab.js` merges the six shipped languages
  (current one first) through `vocabCaptureLexicon()`, so an Italian sentence still works
  on an English UI and vice versa. Every language now carries the same coverage —
  Italian, English, German, French, Spanish and Chinese — and no product word, unit or
  article is left in the code: the guard test parses 38 sentences from
  `scripts/fixtures/capture-cases.json` (one per intent per language, units, fractions,
  "all of it") on **all six interface languages** and fails if a locale loses a section
  of the schema.
- **Language metadata is data too.** Each `api/vocab/<lang>.json` gained a `"locale"`
  block — the language's own name (`Italiano`, `Deutsch`, `简体中文`…), its collation tag
  and its recogniser tag. `_SUPPORTED_LANGS` and `_collationLocale()` in `app.js` now
  read it (`vocabLanguages()` / `vocabCollationTag()`): the language picker, the `?lang=`
  deep link, date and number formatting and the TTS voice all come from the JSON, so
  `app.js` no longer carries a translated word of its own.
- **The offline anti-waste facts moved out of the code.** The screensaver fallback
  sentences (IT/EN/DE) were a literal in `app.js`; they now live in
  `data/food_facts_fallback.json` and reach the browser as
  `assets/js/food_facts.generated.js` (`php scripts/gen-food-facts-js.php`, offline,
  in the service-worker shell). `scripts/test-vocabulary.php` also stopped skipping its
  own generated-file check (the assertions sat after the script's `exit`) and now fails
  when either generated file drifts from its JSON, when a language file loses its
  `locale`/`capture` block, or when a language name / anti-waste sentence reappears in
  `app.js`.


### Changed
- **SHELFY has no voice of its own and never confirms by itself.** The spoken read-back,
  the speaker toggle and the 10-second auto-confirm on the USE / ADD buttons are gone: the
  agent opens the panel with the quantity already set and **the tap is the confirmation**.
  The recipes keep their voice — that is a different feature and it stays untouched.
- **“Ho usato un’arancia” no longer opens the orange juice.** The matcher scored any name
  *containing* the word at 0.78, enough to pass as a confident match; now **position
  decides**: the word (or its plural) leading the name is the product (0.9+), a word buried
  in a longer name is a derived article (0.55, below the threshold, so SHELFY asks). Four
  candidates, the closest draining for **10 seconds** and taken if nothing is touched; a tie
  (within 0.08) asks too. When only a derived article exists, it proposes adding what was
  actually said instead of opening the juice.
- **Eaten and drunk are consumption too**: mangiato/mangiata/mangiati/mangiate,
  bevuto/bevuta/bevuti/bevute, assaggiato, drunk/drank/tasted, getrunken, bu/bue/bues,
  goûté, bebido/bebida/probado, 喝/吃完 — in the six languages the parser already reads.
- **The presentation has a face**: an avatar that floats, a hand that waves and a breathing
  ring (still with `prefers-reduced-motion`) instead of a wall of text.

## [1.16.36] - 2026-10-25 · branch `refactor_25_10_2026`

### Changed
- **The assistant is called SHELFY in the chat too.** The chat header still said “Gemini
  Chef” and the scan overlay “Gemini Vision”: those are the *assistant’s* name, not the
  provider’s, so they now say “SHELFY” and “AI Vision” / “AI OCR” (it/en/de/fr/es/zh). The
  Settings pages keep the provider’s real name (`Google Gemini`, `API Key Gemini`) because
  there the name is the thing being configured — renaming it would be a lie.
- Two dead keys left the locales (`nav.gemini`, `gemini.chat_title`): the navigator item
  went away in 1.16.22.

## [1.16.35] - 2026-10-25 · branch `refactor_25_10_2026`

### Added
- **Esc closes the dialog on top, and Tab stays inside it** (audit I3 + I4, the last two
  open accessibility items). One `keydown` handler covers every `.modal-overlay` — present
  and future — instead of the two ad-hoc Escape handlers that used to exist: the top
  dialog is closed through its own state-cleaning closer
  (`_OVERLAY_CLOSERS`: `closeModal`, `_closeCaptureSheet`, `_shelfyCloseIntro`,
  `closeSupportModal`, `closeRecipeDialog`), the focus returns to the element that opened
  it (`_lastFocusedOutside`), and `Tab` / `Shift+Tab` cycle inside the dialog instead of
  walking into the page behind it. The first-run setup wizard is deliberately **not**
  dismissible with Esc: it is a guided flow, not a notice.
- `#modal-overlay` is announced as a dialog (`role="dialog" aria-modal="true"`), like the
  wizard, the support modal and SHELFY's sheet already were.
- Visibility is decided by `getClientRects()`, not `offsetParent`: a `position: fixed`
  overlay has no offset parent even when it is right there on screen.

## [1.16.34] - 2026-10-25 · branch `refactor_25_10_2026`

### Changed
- The two voice error messages now name the two real causes instead of saying “not allowed”:
  *“Microfono non consentito: controlla i permessi del browser e apri l’app in HTTPS”* and
  *“Questo browser non ha il riconoscimento vocale: scrivi qui sotto (SHELFY capisce lo
  stesso)”*, in all six locales — a microphone needs a secure context, and a LAN install on
  plain `http://192.168.x.x` is the usual reason a phone hears nothing.

## [1.16.33] - 2026-10-25 · branch `refactor_25_10_2026`

### Fixed
- **Nothing was being recognised, and the app beeped every few seconds.** The listening
  session was restarted whenever the recogniser ended (to “survive the timeouts”), and each
  restart makes the browser play its start chime — right in the middle of the sentence —
  while throwing away the interim transcript already spoken. One utterance per tap now:
  `continuous = false`, no restart loop, and **if the browser ends without finalising, the
  interim text is the sentence.**
- **The keyboard no longer opens under the voice.** `_openCaptureSheet()` focused the text
  field on open, which pops the soft keyboard over the sheet the user opened to speak; it
  now blurs it (only the no-recognition fallback focuses the field).
- **The recogniser listens to the language the user speaks.** It used the interface
  language, so an English UI made an Italian sentence unrecognisable even though the parser
  understands all six: the device language wins when supported, the interface language is
  the fallback.
- **One microphone client at a time.** The level meter (getUserMedia) started together with
  the recogniser; on several phones two simultaneous consumers leave the recogniser hearing
  nothing. The meter now starts on the first result — it is cosmetic.

## [1.16.32] - 2026-10-25 · branch `refactor_25_10_2026`

### Changed
- **No banner hides its actions behind a ⋮ any more.** The last ones folded Finito /
  Buttato / Correggi into a menu because the strip “only fits one row”: there is room, and
  a hidden button is a button nobody presses. Every entry renders its actions inline (they
  wrap on a phone) and **every entry carries a Modifica** — expired, review, prediction,
  finished, anomaly and the quantity anomaly. The expired strip went from seven buttons to
  the four that matter (Usa, Finito, Buttato, Modifica): extend and vacuum live in the
  product sheet, “ignore” was the ✕.
- **The dashboard updates itself after every change.** Correcting an expiry date in “Salva
  lo spreco” left the row on screen until a reload: the row now fades out where it is
  (`is-leaving`, 220 ms) and the blocks are rebuilt from the server on a debounced
  `_dashboardLiveRefresh()`, wired into the edit save, an extended expiry, a consumption,
  a deletion and the shared post-mutation hook.
- **The assistant has a name: SHELFY.** It presents itself once per device (“Ciao, sono
  SHELFY”, what it does, the phrases it understands, how the confirmation works), can be
  reopened with the `?` in its sheet, and every status line now speaks in its name.
- **SHELFY opens the panel it is about to change, with the quantity already set**: “ho usato
  1 arancia” → the USE panel of the arancia with 1 piece; “249 grammi di orecchiette” → 249 g;
  “ho finito tutti i fusilli” → the USE panel with “use it all”; “aggiungi 1 litro di latte”
  → the add form with 1000 ml; fractions (mezza, un quarto, tre quarti) included.
- **The confirm is a reverse scrollbar**: the button drains for **10 seconds** and then acts;
  any touch or key press stops it, and pressing the button itself confirms immediately.
- **Four candidates when the name is ambiguous**, each with picture (or category icon), name,
  brand, place and quantity; the closest match drains for **15 seconds** and is taken if
  nothing is chosen.

## [1.16.31] - 2026-10-25 · branch `refactor_25_10_2026`

### Fixed
- **A product past its expiry date can no longer go unmentioned.** Reported with
  “Preparato solubile Ovomaltine”, expired 5 days earlier and never listed: the server
  returned it in `stats.expired` all along, and the dashboard dropped it because the
  *verdict* (`getExpiredSafety`) answered “ok / Va bene” for anything in the low-risk
  group within 30 days. A safety opinion was being used as a visibility filter. The
  expired list is now filtered by the date only (a depleted trace still isn’t stock), and
  the pill keeps saying whether the row is still usable — “Va bene” on a past-date item
  is information, not a reason to hide it.
- **Drinks are no longer “fine for a month”.** Beverages and soluble preparations
  (`bevande`: juice, milk, cocoa…) fell in the low-risk group, so a carton past its date
  said “Va bene” for 30 days. They now get “Controlla” up to 30 days (with the drink
  wording) and “Non bere” after that.
- **The newest expiry is listed first** (the block used to sort oldest-first and cap at
  five rows, so a box from six months ago could push out the product that expired
  yesterday).

## [1.16.30] - 2026-10-25 · branch `refactor_25_10_2026`

### Changed
- **The capture microphone has a real animated border.** A static edge (and a quiet
  box-shadow) was not enough to read “this is tappable”, and the button keeps
  `overflow: hidden` for the level fill, so the border could not be drawn on the button
  itself — a pseudo-element inside it would be clipped. The button now sits in
  `.capture-mic-wrap`, which draws a **turning gradient ring** (a 3px slice of a conic
  gradient, masked to a ring, `capture-ring-turn` 3.6 s linear) plus a **breathing halo**
  (`capture-mic-halo`, out of phase with the ring). While the app records, both speed up
  to 1.1 s; with `prefers-reduced-motion` the ring is static and the halo stays lit. The
  button also gained a 2px emerald edge and a `:focus-visible` outline.
- The wrapper is hidden with the button when the browser has no speech recognition, so
  a ring never spins around nothing.

## [1.16.29] - 2026-10-25 · branch `refactor_25_10_2026`

### Changed
- **The anomaly banner asks one question and offers two answers**: “✓ Conferma 2000 ml”
  (solid emerald, writes the ledger value) and “Modifica quantità” (neutral, opens the
  product sheet, where the real figure can be typed — or the item marked as finished /
  discarded). “Finito”, “Buttato”, “Correggi”, “Ignora” and “🤖 Spiega” left the strip:
  the first two are ordinary product actions, “Ignora” duplicated the ✕, and the sentence
  above already says why. Six pastel buttons for one number is a control panel, not a
  notice.
- **The primary never wraps**: “Aggiorna a 3500 / ml” broke in two lines and pulled the
  whole row out of alignment; the label is “Conferma N unit” with `white-space: nowrap`.
- **✕ now really dismisses**: closing an anomaly persisted nothing, so the same strip came
  back on the next refresh. It records the dismissal (`dismiss_anomaly` + review flag) and
  — unlike the old “Ignora” — never posts `inventory_confirm_finished`.
- The title uses the amber of its rail (`#92400E`), the description stays slate: one
  accent per strip.

### Removed
- `finishBannerAnomaly()`, `throwBannerAnomaly()`, `explainBannerAnomaly()`,
  `editBannerAnomaly()`, `dismissBannerAnomaly()` and their translation keys: dead code
  once the strip has two choices, because the product sheet already does all of it.

## [1.16.28] - 2026-10-25 · branch `refactor_25_10_2026`

### Changed
- **One voice for the whole app.** The capture sheet built its own
  `SpeechSynthesisUtterance` (locale only, rate 1.02), so it sounded different from the
  cooking steps. It now speaks through `_speakBrowser()` — the same on-device engine, the
  same Settings voice, rate and pitch, the same kiosk native bridge — and stays local: no
  cloud TTS endpoint is ever called from the capture, whatever the recipes engine is set
  to.
- **The voice is picked for the language of the text.** `_pickTtsVoice()` is the one rule:
  the voice chosen in Settings when it speaks that language, else an offline
  (`localService`) voice of that language, else any offline voice, else the first. The old
  fallback preferred any Italian voice, so a German or French interface could read its own
  language with an Italian engine; Settings → TTS test had the same hardcoded Italian and
  now follows the interface language too.

### Fixed
- Closing the capture sheet cancelled `speechSynthesis`, cutting the confirmation sentence
  (“Hai usato 1 mela. Confermi?”) a fraction of a second after it started.

## [1.16.27] - 2026-10-25 · branch `refactor_25_10_2026`

### Changed
- **The anomaly banner shows its actions inline, each with its own colour**: “Aggiorna a
  2000 ml” (solid emerald), “Finito” (emerald tint), “Buttato” (soft red), “Correggi”
  (sky), “Ignora” (neutral). Collapsing them behind a ⋮ made the ⋮ look like a broken
  list of centred words.
- **“Dimenticati” is the same card as “In scadenza”**: the theme still painted it purple
  with a violet frame, and inside the hub it had lost its left padding, which clipped the
  hint. Same surface now, one 4px rail per tab (amber expiring, sky opened, emerald
  forgotten).
- **“Dimenticati” ranks by expiry**: the 5 products closest to their expiry date among
  the rows that were never used or idle for 21+ days, each with the date and its
  countdown (`forgottenExpiringItems()`, `stale_inventory_items&mode=expiry`). The
  30-minute rotation is gone: a sealed box of salt no longer outranks tomorrow's yoghurt.
- **The capture mic breathes at rest** (slow aura + hover): it now reads as a button that
  wants to be tapped.
- **The capture session listens long enough**: `continuous = true` with restarts while the
  sheet is open, “Ti ascolto ancora…” instead of “non ho capito” after the first pause, and
  the error only after several silent seconds.
- **The capture understands more**: fractions (“mezza mela”, “un quarto di torta”), weights
  written without a space (“300gr”), canonical units (`gr` → `g`) and “ho finito tutti i
  fusilli” (`use_all`). A dictated quantity opens the product sheet with the stepper
  already on that number; “ho finito” opens the same sheet, where “Ho consumato tutto” is
  the confirmation — the sentence alone never writes.
- **The capture talks back**: on-device TTS reads what it understood and asks for the
  confirmation, only for dictated sentences, with a speaker toggle in the sheet.
- **“Cosa posso dire”** lists the whole lexicon as tappable examples, in all six locales.

## [1.16.26] - 2026-10-25 · branch `refactor_25_10_2026`

### Changed
- **The product modal has one hierarchy.** Four saturated buttons (orange *Usa*,
  green *Usato tutto*, purple *Crea ricetta*, red *Butta*) competed in one row; the
  frequent action is now a stepper (“Quanto ne hai consumato?”) with a solid emerald
  “Registra consumo”, an emerald-tinted “Ho consumato tutto” under it, and the support
  actions (recipe, discard, extend expiry) as neutral grey buttons.
- **Barcode and timestamps left the middle of the card**: they live in a collapsed
  *Dettagli* footer, one tap away.
- **Rows lead with consumption, not with the calendar**: the permanent “Estendi
  scadenza” pill is gone (it moved into the detail modal); the row carries a quick
  “Usa” and the ⋮ that opens the modal.
- **The anomaly banner offers a named action**: “Aggiorna a 2000 ml” (writes the ledger
  value into the pantry row) instead of the unreadable “Giusta”, with ✕ / *Ignora* to
  leave it alone; the remaining actions moved into the ⋮ menu, which now renders as
  real buttons instead of naked centred words.
- **The capture sheet follows the emerald palette**: “Esegui” is the emerald primary,
  the suggestion chips are solid neutral pills (a dashed border reads as “drop zone /
  disabled”) and the microphone shows a sonar ring while it records.
- The “Altro” buttons left the add/use forms for good: the location was removed in
  1.16.19 and its key is only a legacy alias now.

### Fixed
- Two buttons still offered the removed “Altro” location, pointing at a translation
  key that no longer existed (caught by `scripts/i18n-audit.py`).

## [1.16.25] - 2026-10-25 · branch `refactor_25_10_2026`

### Round 3: the waste block holds food, the alert strip holds errors
- **A data error is not wasted food.** The anomaly banner (“Quantità insolitamente
  alta: … 250000 g, confermi?”) lived *inside* “Salva lo spreco”. It now sits in its own
  dismissible strip **above** the hub (`#alert-banner` → `.system-alert`, neutral surface
  with an amber left rail, ✕ to dismiss). The hub is shown only when there is expired
  food — an inventory anomaly can no longer make it look like something to cook.
- **No more full-width “Controlla” bar.** The safety verdict was a `inline-flex` chip
  inside a stretching column, so it drew a yellow bar as tall as a row. It now rides on
  the metadata line (`alert-item-meta-row`, wrap when narrow) at 12 px: one slim row per
  product, like the lower lists.
- **No more “cabins”.** Every semantic block resets all four sides to the 1 px hairline
  and keeps the colour only as a 4 px left rail (danger / warning / info / brand); the
  system strip stays neutral whatever kind it is, so the amber and orange gradients from
  the theme no longer come back through `.banner-anomaly` / `.banner-expired-*`.
- **The calendar icon is a real target.** “Estendi scadenza” is a 40 px pill with a dark
  icon, 8 px away from the ⋮ and with its label visible from 520 px up — no more
  guessing which of the two a thumb will hit.

Guard tests: `scripts/test-urgency-hub.php` (66 checks — the banner is outside the hub,
above it, and an anomaly alone no longer opens the food hub) and
`scripts/test-ui-palette.php` (53 checks — the four-side reset, the 4 px rail, the meta
row, the compact verdict, the extend pill and the neutral system strip).

## [1.16.24] - 2026-10-25 · branch `refactor_25_10_2026`

### Banners now say **where** the product is
The anomaly banner asked *“Quantità insolitamente alta: Pasta Fusilli (Barilla) — hai
250000 g, confermi?”* without saying which shelf to look at — and a product can sit in
more than one place.
- `inventory_anomalies` groups the locations too (`GROUP_CONCAT(DISTINCT i.location)`)
  and returns `locations` (every place it is) plus `location` (the first, for old
  clients).
- One helper builds the line — `Dove: 🗄️ Dispensa · 🧊 Frigo` — resolving the keys
  through the client's location map, so custom locations read with their icon/label and
  a stale `altro` still lands on dispensa.
- The line appears on **every** banner entry that points at an inventory row: anomaly
  (both directions), review, consumption prediction, finished/vanished and the
  “no expiry” reminder. `dashboard.banner_location` in six locales, styled at the
  13 px muted level of the row metadata — one more line, not another frame.

Guard test `scripts/test-urgency-hub.php` (64 checks) locks the SQL, the payload
fields, the three helpers, the five call sites and the key in all six locales.

## [1.16.23] - 2026-10-25 · branch `refactor_25_10_2026`

### The capture sheet starts listening by itself
Opening the sheet (a tap on the centre button) now starts the microphone immediately —
the tap **is** the gesture the browser needs, so asking for a second one was pointless.
- The recogniser is created and started **synchronously inside that gesture**, before
  any `await` (Safari and stricter browsers refuse a recogniser started later); the
  level meter, which is only cosmetic, starts right after and is not awaited.
- The idle line no longer says “tap the microphone”: it reads “Parla pure: ti ascolto.
  Oppure scrivi qui sotto.” in the six locales.
- Where the Web Speech API is missing (or the microphone is denied) the text field
  takes over and only then receives focus — focusing it during voice would pop the
  keyboard over the sheet.

Guard test `scripts/test-capture-parse.php` (171 checks) locks the auto-start, the
start-before-await order and the reworded idle line.

## [1.16.22] - 2026-10-25 · branch `refactor_25_10_2026`

### The centre button: no label, bigger, alive, and no brand
- The text label under the centre button is gone: the microphone **is** the label
  (the accessible name stays `capture.title`, six locales).
- It is bigger than the other tabs (48–64 px, icon 24–32 px, lifted 18 px) so the
  primary action reads as the primary action.
- It **always** breathes: a 4.2 s scale+glow cycle with a halo one step behind, and
  the halo/breathing stop under `prefers-reduced-motion`. It used to be still — a
  still button in the middle of a bar looks like decoration.
- Every trace of the old product branding is gone from the shell: the dead
  `.nav-gemini-*` rules were removed from `style.css` (the indigo sparkle, its
  no-AI greying and its offline hiding). The capture button stays available offline
  on purpose — “use” and “add” work without a server.
- The glyph is a plain stroke microphone (capsule, arc, stem), not a sparkle: the
  AI chat keeps its own Gemini mark on the chat page, where it belongs.

Guard test: `scripts/test-ui-palette.php` (47 checks) now also asserts no label, the
bigger clamp, both keyframes, the reduced-motion guard, no `nav-gemini` anywhere and
that the button is not hidden offline.

## [1.16.21] - 2026-10-25 · branch `refactor_25_10_2026`

### The capture sheet finally talks back
The sheet looked dead: a mic button with no feedback, no transcript, no
interpretation — so a wrong parse was indistinguishable from a broken app. Now:
- the mic **fills with the incoming audio level** (`getUserMedia` + `AnalyserNode`,
  RMS into `--level`) and five bars move with it: “it is listening” is visible even
  with the sound off;
- the transcript appears **while it is still guessing** (grey italic) and becomes
  solid when final;
- before routing, the sheet shows what it understood as chips — intent · product ·
  quantity — so a wrong read is visible as a wrong read;
- an ambiguous product is **asked**, not guessed: the closest pantry rows appear as
  one-tap chips and the text stays editable;
- the microphone and the recogniser are released on close/end (no hot mic).

**Internal multilingual model, no external AI.** A lexicon per intent (consume /
add / recipe / throw) in all six UI languages, scanned together so an Italian
sentence works on an English UI; Unicode-aware word boundaries (`\p{L}`) so
“äpfel”, “caffè” and “cucino” match while “tomates” no longer loses “ate”; longest
word first so “buttato” wins over “butta”; spelled numbers 1–10 in six languages
(“due mele” → 2); units as standalone tokens (the “l” of “apfel” is not a litre);
and a similarity scorer (exact > plural/stem > prefix > substring > token overlap)
that ranks the pantry rows — ≥ 0.75 routes straight, otherwise the sheet asks.

Guard test `scripts/test-capture-parse.php` — **168 checks**: 18 sentences across
the six languages (intent + product + quantity), the ranking, the meter/interim/
chips wiring, “no `api()`/`fetch()` in the parser” and the lexicon coverage.

## [1.16.20] - 2026-10-25 · branch `refactor_25_10_2026`

### UI review round 2 — the three structural fixes and the palette
- **“Dimenticati” escaped the stock hub.** The stale list is fetched asynchronously and
  finished *after* the tab was chosen, then set its own panel visible again — so it
  reappeared under whichever tab was active (the “le liste sotto sono ancora entrambe
  visibili” report). The loader now re-applies the tab selection like the other two do;
  exactly one panel is visible whichever request finishes last.
- **No more duplicated food in “Salva lo spreco”.** The expired products are rows of
  the hub, and the anomaly banner repeated the same product a few pixels above:
  `loadBannerAlerts()` now drops expired entries whose product is already listed and
  keeps the banner for what the list does not show (anomalies, predictions, reviews).
- **The six pastel buttons are gone.** `[Finito][Usa][Buttato][Modifica][Estendi][Chiudi]`
  collapsed into one primary action (preferring “Usa”) plus the same 40 px ⋮ menu the
  stock rows use; the other actions live in a neutral panel under the row. Handlers
  untouched, all seven banner kinds render through the same helper.
- **“Fresh & Crisp Slate” palette**: canvas `#F8FAFC`, white cards, hairline `#E2E8F0`,
  text `#0F172A` / muted `#64748B`, one brand emerald `#059669`; semantic states as
  light background + dark text + accent line (danger `#FEE2E2/#991B1B/#EF4444`, warning
  `#FEF3C7/#92400E/#F59E0B`, info `#E0F2FE/#075985/#0284C7`). Blocks are white with a
  soft shadow and a **4 px left accent line** instead of coloured frames; the header is
  a white surface with dark icons instead of the deep forest-green bar.
- **Three text levels**: product title 15 px/600, metadata 13 px/400 muted, badge
  12 px/600 — the 10-11 px “grey noise” lines are gone.
- **One relative-date format**: `tra 6 gg` / `36 gg fa` everywhere on the dashboard
  (`_relativeDaysAhead()` / `_relativeDaysAgo()`, six locales), replacing the mixed
  “6 giorni”, “8gg”, “Da 36g”.
- **Centre button**: microphone glyph and “Ascolta”/“Listen” label, emerald fill — the
  primary action is voice.

Guard tests: `scripts/test-stock-hub.php` (43), `scripts/test-urgency-hub.php` (51) and
the new `scripts/test-ui-palette.php` (42) lock the behaviour and the palette.

## [1.16.19] - 2026-10-25 · branch `refactor_25_10_2026`

### The “Altro” location is gone
“Altro” was a generic bucket: whatever did not fit a real place ended up there, so
stock hid instead of living where it belongs. It is removed as a **location** —
Dispensa, Frigo, Freezer plus the custom ones are the real places now.
- **Nothing is lost:** a one-shot migration moves every row that still pointed at
  `altro` to the first location (`dispensa`), and the client shows a notice once per
  device that names the count and the destination: *“La locazione «Altro» non esiste
  più: N prodotti sono stati spostati in «Dispensa».”* (`location_altro.removed`,
  six locales). The flag lives in `app_settings` (`migration_location_altro_v1`), so
  the migration runs exactly once and cannot re-count on the next boot.
- `get_settings` exposes the record (`location_altro_migration`) so a device that was
  offline during the upgrade still learns it once; a device paired afterwards is not
  told about a migration that happened before it existed.
- The inventory filter tab, the add-location form, the import hints and the location
  pickers no longer offer it; `settings.custom_locations.builtin_hint` no longer
  names it either.
- Old data that still says `altro` (a stale cache, an old export) is read as
  `dispensa` through `normalizeLocationKey()` — the same destination as the
  migration — so a product never shows a location that does not exist.
- The **category** `altro` is a different thing (the classifier's fallback for an
  unknown name) and stays untouched; the guard test checks both sides.

Guard test `scripts/test-location-altro-removed.php` (34 checks): a real SQLite
database migrates `altro` → `dispensa`, records `moved: 1`, is one-shot on the next
boot, keeps other locations intact; the client no longer offers the location, keeps
the legacy alias, and the six translations carry the notice.

## [1.16.18] - 2026-10-25 · branch `refactor_25_10_2026`

### The dashboard becomes a triage screen (UI pass, blocks 1-7 + voice)
Five differently coloured frames in one scroll looked equally urgent, six micro
buttons sat on every row, and the analytics pushed "what expires tomorrow" below the
fold. Each change below is one block of the UI audit and has its own guard test.

**One calm surface (new `assets/css/surfaces.css`)**
- Neutral card chrome everywhere, one semantic tone per block as a 3 px rail + a dot
  in the title, badges carry the colour. Loaded after the themes, before
  `mobile.css` (which stays last for phone geometry), with tokens for dark mode.
- Secondaries meet a 40 px touch minimum; the orange gradient CTA is a calm accent.

**Locations are a rail, the shopping count is a badge**
- The fixed 2×2 grid (Dispensa/Frigo/Freezer/Spesa) is a snapping row of dynamic
  pills: icon, name, count, a red dot where something is expired or expiring, empty
  locations dimmed, and a dashed “+” that opens Settings → Luoghi di conservazione.
- The purchase flow left the stocking states: the list size is a badge on the Spesa
  tab, the euro estimate stays in the shopping page header.

**Urgency hub** — the anomaly banner, the expired list and the recipe CTA are one
container with a header (“Salva lo spreco”, count, 🍳 Ricetta) and one place that
decides whether it is worth showing.

**Stock hub** — “in scadenza / aperti / dimenticati” are three tabs of one block with
live counters, instead of three stacked lists. Rows keep a single primary action and
a 40 px ⋮ opening the full sheet (extend stays as a calendar glyph).

**Analytics moved to Storico** — anti-waste, nutrition, macros, spend, freshness and
monthly charts live in a collapsible “📊 Statistiche” block at the top of Storico; the
dashboard keeps one line: “N usati · M buttati negli ultimi 30 giorni”.

**Capture button** — the centre of the nav is a sparkle, not a brand. It opens a sheet
that accepts a spoken (Web Speech API, on-device) or typed sentence and routes it to
the flow that already does the job: use → use form, add → add form prefilled, recipe →
chat. Nothing is applied without confirmation; a missing mic degrades to typing.

## [1.16.17] - 2026-10-25 · branch `refactor_25_10_2026`

### Deep audit pass — the settings API stops leaking, the CI starts testing
The full audit is `todo/AUDIT-2026-10-25.md` (internal, lettered sections A–M).

**Security**
- `app_settings_get` returned **every** row of `app_settings`, including
  `env_overrides` — the fallback copy of environment values that holds the real
  `GEMINI_API_KEY`, `BRING_PASSWORD`, TTS token and, on some installs, `API_TOKEN`
  when `.env` is read-only (issue #298). It is also on the DEMO read-only allowlist.
  Both handlers now share one allowlist (`evershelfClientSettingsKeys()`): reading
  hides the internals (`hidden` is reported), writing refuses them
  (`error.settings_key_not_allowed`), all-or-nothing, with a 1 MB body cap
  (`error.settings_too_large`).
- The three new error keys exist in all six locales; `app_settings_save` answers the
  same `success:false` contract as the rest of the API.
- Guard test: `scripts/test-app-settings-api.php` (59 checks) also asserts that every
  key the frontend writes is on the allowlist.

**Backups**
- `createLocalBackup()` now runs `PRAGMA quick_check` on the `.part` file **before**
  the rename: a truncated snapshot can no longer be promoted to “the backup” and
  discovered on restore day (`error.backup_integrity`, `event=backup_integrity_failed`).
  Guard test: `scripts/test-backup-integrity.php`.

**Performance**
- Five indexes for the hot read paths (`inventory.added_at`, `products.lower(name)`,
  the three shopping key expressions), created idempotently in `initializeDB()` and
  `migrateDB()`; `scripts/test-db-indexes.php` proves with `EXPLAIN QUERY PLAN` that
  they are used and that no statement scans the tables wholesale.
- One full `get_settings` round-trip removed from every boot (`syncSettingsFromDB()`
  reuses the payload `_initApp()` already fetched) and the web-push DDL is gone from
  that path.

**Accessibility / UX**
- `#toast` is `role="status" aria-live="polite"`, so “Salvato”/“Errore” are announced;
  `#setup-wizard` and `#support-modal` are real dialogs (`role`, `aria-modal`,
  `aria-labelledby` pointing at their translated heading).
- The offline banner tooltip says when the server last answered, so a kiosk can tell
  “no network” from “stale data”.
- The offline queue keeps its 100-operation cap but no longer drops work silently:
  the drop is logged and shown once per session (`error.offline_queue_full`).

**Translations**
- The Mealie wizard (~59 keys) and the recipe preferences (~25 keys) were never
  translated: French and Spanish users saw the whole panel in English. Both blocks are
  now translated in fr and es, plus the recipe input hints in de. Identical-to-English
  values: 497 → 339, and every remaining one is a brand, a token, a URL example, a unit
  or a country name.

**CI**
- New jobs: **PHP Regression Tests** (all 55 `scripts/test-*.php` — they never ran in
  CI), **Shell Scripts** (`shellcheck`), **Generated Files** (settings schema + code
  indexes drift), and the JS lint now covers `sw.js`, `core/*.js`, the other browser
  scripts and the ESM `mcp-server`.
- The “translation completeness” step compared only top-level JSON keys, so it could
  never fail: it now runs `scripts/i18n-audit.py`.
- Pull requests against `develop` run the same gates, and `auto-merge-to-main` waits
  for all of them.

## [1.16.16] - 2026-10-25

### The setup wizard obeys the server (not every new browser)
Reported as issue #301: an instance fully configured through a **read-only `.env`**
still opened the first-run wizard on every new device, and it started with the
Google Drive step even though `GDRIVE_ENABLED=false`.
- The Drive step now disappears when Drive is switched off **on purpose**:
  `get_settings` gained `gdrive_enabled_set`, so an explicit `false` is no longer
  indistinguishable from "never decided". Left unset, the step stays for a fresh
  install (`_gdriveWizardNeeded()`), and the Settings → Backup row reports it as
  decided instead of "Da decidere".
- The setup **is an instance decision now**: `evershelf_setup_done` and the
  assistant's `evershelf_setup_seen` ledger are stored in `app_settings`
  (`setup_done` / `setup_seen`) and handed back by `get_settings`, so a phone, a
  new browser profile or the Kiosk WebView stops asking what the household already
  answered. Entries merge by highest `askVersion`, so a genuinely new option is
  still asked once.
- The language step is skipped when the operator decided the instance language
  (`APP_LANG`, now exposed by `get_settings`).
- Guard test: `php scripts/test-setup-wizard-config.php` runs the shipped
  `_getMissingSetupSteps()` / `_gdriveWizardNeeded()` / `_setupSeen()` in node.

## [1.16.15] - 2026-10-21

### The smaller lists follow the phone pass
- The lists that still cut their text are readable too: quick-name search results,
  the **AI match candidates** (you could not see which article was picked), the receipt
  review rows, and the spend bar labels pinned at 78 px (“Latticin…”).
- No behaviour change, no new keys: `assets/css/mobile.css` only relaxes the truncation.

## [1.16.14] - 2026-10-21

### The lists are readable on a phone
Measured at a 360 px viewport with the app's own CSS (Chromium), before the fix:
`alert-item-name` was cut at **107 px** (“Formaggio spalmabile Philadelphia, Class…”),
the quantity line wrapped into **six** lines, inventory and product names were clipped,
recipe titles were clipped and — worst — a shopping row collapsed its title to **0 px**,
wrapping **one letter per line (43 lines)** because the name is `flex: 1` beside a nowrap
quantity, an urgency badge and a price cell.

- **New layer `assets/css/mobile.css`** (loaded **last**, after the themes, geometry only):
  at ≤640 px the text owns the row and the badges/quantities move under it; alert names,
  inventory/product names and recipe titles wrap instead of truncating; row buttons never
  break mid-word. At ≤400 px the decorative shopping thumbnail and the swipe hint give
  their space to the text.
- **Measured after**: 0 truncated or squeezed elements at 320, 350, 390 and 414 px on all
  eight row types (dashboard alerts incl. the 4-button “unused” row, inventory, shopping,
  suggestions, products, log, recipes, smart items).
- The layer is precached by `sw.js` and is the documented fourth CSS layer
  (`docs/CODEBASE-MAP.md`, `docs/CORPORATE-UI.md`, `docs/ARCHITECTURE.md`).
  Guard: `scripts/test-mobile-layout.php`.

## [1.16.13] - 2026-10-21

### The dismiss also covers the real-time sync
- Verifying v1.16.12 on the live pantry showed a dismissed product coming back through a
  *different* door: a stock change calls `bringQuickSyncProduct()` /
  `shoppingSyncProductFromCache()`, which insert straight into the list without going
  through the cron. Both paths check `shoppingIsDismissed()` now (the log carries
  `shopping_dismissed_skip`), so “non lo compro” holds whatever moves the stock.
- The rows that had already been re-added before the fix were removed once with the same
  rule (app-managed rows of a dismissed family only — user rows are never touched).
  Guard: `scripts/test-shopping-dismiss.php`, which now also drives
  `bringQuickSyncProduct()`.

## [1.16.12] - 2026-10-21

### “Non lo compro” now survives the 5-minute cron
- **A removed-not-bought item stays off the list.** An external client (spesaPoli) sent
  `shopping_remove` with `purchased: false` and six products were back within five
  minutes: the purchase blocklist alone was not enough — `smartItemNeedsRestockOverride()`
  lets a *depleted* product through by design, and
  `internalShoppingAutoAddCritical()` cleared the block when it re-added the row.
- **A dated dismiss** (`app_settings.shopping_dismissed`: key → `YYYY-MM-DD`) is stored for
  every `purchased: false`, honoured by both auto-add paths (internal list and Bring!
  mirror) and **never cleared by the cron**. The window is `SHOPPING_DISMISS_DAYS`
  (default 14, 1–365) or a per-call `dismiss_days` (1–365).
- **A purchase lifts it**: `shopping_remove` with `purchased: true`, or adding the product
  to the pantry once the shopping need is covered, clears the dismiss for the whole family.
  Choosing it again by hand (“usa tutto”) still works — an explicit action is not a
  prediction.
- Documented for integrators in `docs/wiki/API-Reference.md` (`shopping_remove`), with the
  env key in `.env.example`. Guard: `scripts/test-shopping-dismiss.php`.

## [1.16.11] - 2026-10-21

### Dates follow the country, everywhere
- **Italy reads gg/mm/aaaa.** `formatDate()` printed a month NAME (“19 ott 2026”), and the
  dashboard banner and the import preview printed the raw ISO string from the API
  (“scadeva il 2026-10-19”). Every date goes through `formatDate()` now: it 19/10/2026,
  de 19.10.2026, fr/es/en-GB gg/mm/yyyy, zh 2026/10/19.
- **One locale map.** Eight hand-written `_currentLang === 'de' ? 'de-DE' : … : 'it-IT'`
  ternaries gave French and Spanish users the ITALIAN format — Italian month names in the
  log and in the screensaver, and an Italian voice in the text-to-speech. They all use
  `_collationLocale()` now.
  Guard: `scripts/test-locale-dates.php`.

## [1.16.10] - 2026-10-21

### The row stops contradicting itself (“Esaurito … ma ne hai 235 g”)
- **A family row reports the family’s stock.** The shopping payload now carries
  `family_qty` (usable stock of the whole buyable generic, in the item’s unit) and the row
  shows it instead of the representative product’s own quantity. 16 rows in a real pantry
  said “Esaurito” while `pct_left` (family) said 20–90%; they now read “235 g (47%)”.
- **“Hai già X in dispensa” speaks about the same family.** The hint matched any product
  starting with the same word, so a row marked *Zucchero di canna · Esaurito* proudly
  announced 235 g of *Zucchero grezzo* — a different buyable article. It now uses the
  `shopping_name` family only.
- **Raw cane sugar is cane sugar.** `zucchero grezzo di canna` / `zucchero grezzo` map to
  **Zucchero di canna** in `api/vocab/it.json` (it used to fall back to plain `Zucchero`,
  i.e. white sugar). `assets/js/vocab.generated.js` regenerated.
- **Coverage reads “one whole pack”**: for pieces/packs a family covers a restock when it
  still holds **one** full unit (the average purchase is not used — a legacy gram log on a
  conf product would make coverage impossible), weights/volumes keep “half the usual buy”.
  Guard: `scripts/test-shopping-restock.php` (family_qty, the hint matcher, the fixture).

## [1.16.9] - 2026-10-21

### A tap works on the phone, and the last alert list reacts too
- **Tap-to-open on touch.** `_bindRowHoldOpen()` deliberately ignored the synthetic click
  on touch/pen and waited for a **900 ms hold**, so on Android a tap on any dashboard alert
  (scaduti, in scadenza, aperti) did nothing at all. A tap now opens the row; the hold still
  works, a scroll (pointermove > 12 px) still cancels, a tap on the row button never opens
  the sheet, and the synthetic click that follows a tap is swallowed. Lists where a tap has
  another meaning opt out with `tapToOpen: false` (the shopping “suggested” rows, which
  swipe and would add items by accident).
- **“Aperti da troppo tempo” is clickable.** Those rows were plain divs without
  `alert-item-clickable`/`data-alert-inv-id` and were never bound, so nothing happened even
  on desktop. They now open the same detail sheet as the other three lists.
  Guard: `scripts/test-tap-open.php` (a fake DOM drives pointerdown/up/click, hold, scroll,
  mouse and a tap on a child button).

## [1.16.8] - 2026-10-21

### Quantities follow the window you chose — and a date is not a death sentence
- **The planning window is honoured.** Settings → Spesa said “quantità calcolate per 25
  giorni” (`app_settings.shopping_plan_days`) but `smartResolvePlanDays()` ignored the stored
  value and used the *inferred* cycle (7 days here), so the cron computed every quantity for
  a week: 5 lemons a month became “2 pz”. The stored window now wins over the inference (an
  explicit `plan_days` request still wins over both; “auto” keeps the inferred cycle).
- **Perishables are planned against the shelf life of where they are actually kept.**
  `smartPurchaseHorizonDays()` estimated every perishable as if it sat in the pantry, so
  lemons lived 7 days instead of the 21 the fridge gives them — and the anti-waste cap then
  asked for half the lemons the household eats. Produce and meat use their real location now;
  bread keeps its 4-day horizon, so the “never a month of salad” rule still holds.
- **A product expiring TODAY is not expired.** The dashboard query compared
  `julianday('now')` (which carries the clock) with the date, so at 00:01 of its own
  best-before day the milk was already listed as expired with a 🚫 verdict. The comparison is
  date-only now.
- **The verdict is proportionate, and drinks are drunk.** Day 0 is “Controlla”, one or two
  days past the date are “Controlla” in the pantry too (a quality issue, not a danger), and a
  liquid past its date says “Non bere” instead of “Non mangiare” — three new tips and a new
  label in all six locales. Guard: `scripts/test-expiry-and-plan-days.php`.

## [1.16.7] - 2026-10-21

### The two automations stopped fighting over the same row
- **Add → prune → add no longer happens.** `internalShoppingPruneCovered()` removes rows
  whose family covers the plan window, while `internalShoppingAutoAddCritical()` adds back
  what the smart cache predicts. When the two disagreed the cron added a row and deleted it
  again every 5 minutes, so the list never settled and the finished products (marmellata,
  yogurt, caffè…) appeared and vanished on their own. The prune now leaves alone both the
  rows the cache still predicts and the rows the user marked as finished (`🛒 Esaurito`).
  Guard: the prune check in `scripts/test-shopping-restock.php`.

## [1.16.6] - 2026-10-21

### A finished product gets on the shopping list again
- **The family rule stopped hiding real shortfalls.** A depleted article was dropped as soon
  as *any* other product sharing its `shopping_name` had stock — adding grams, packs and pieces
  together, and counting expired batches and 0.5 g smears. In a real pantry that hid 74
  finished products with purchase history: “360 g” of lemon jam kept every jar of jam off the
  list, an expired pack kept butter off, a smear kept tomatoes off. Coverage now needs a
  *usable* purchase: fresh, above `EVERSHELF_TRACE_QTY`, expanded to the same base family with
  the package size (`evershelfBaseQty()`: 3 packs × 1 l = 3 l) and worth at least one pack (or
  half the usual bought quantity for g/ml). Guard: `scripts/test-shopping-restock.php`.
- **“Usa tutto” always lands on the list.** `bringAddDepletedProduct()` no longer skips the add
  when another product of the family is still stocked: an explicit action is not a prediction,
  and the row is stamped `🛒 Esaurito`, so the cleanup keeps it. The generic lookup still stops
  duplicates.
- **An expired batch with fresh stock no longer leaks a row** with no suggested quantity
  (“3 packs at home, one expired”): the product is skipped and the dashboard banner keeps
  showing the expiry.
- `EVERSHELF_TRACE_QTY` is now the single source of the trace thresholds
  (`productQtyThreshold()` reads it), and `scripts/backfill-finished-shopping.php` applies the
  same usable-stock rule before re-adding a finished product in bulk.

## [1.16.5] - 2026-10-20

### The app opens in the language the link asked for — and the vocabulary answers again
- **`?lang=` deep link.** `…/?lang=de` (also `?lang=zh-CN`, `?lang=fr_FR`) opens the app
  already translated: the URL beats the stored preference for that boot, the value is
  stored, and choosing another language from the picker rewrites `?lang=` instead of
  reloading into the old one (`_urlLangOverride()`; guard test `test-i18n-boot.php`).
  No external script has to reach into `localStorage` before `app.js` runs any more.
- **A rate-limited request speaks the UI language.** The 429 answer now carries a
  stable `error_key` (`error.rate_limited`, six locales) and `retry_after`, and `api()`
  resolves the key before any caller prints `data.error` — de/fr/es/zh no longer read
  the English prose.
- **The add form's units are translated.** The `pz`/`conf`/`g`/`ml` options carry
  `data-i18n`, the badge follows the select (`syncAddQtyUnitBadge()`, called when the
  form opens and after a language change) and the dynamically built unit selects print
  `t('units.pz')` / `t('units.conf')` instead of the Italian tokens.
- **The urgency prefix is recognised by its marker, not by its word.**
  `_specDisplayText()` drops the `⚡/🟠/🟡/🔵` segment whatever language wrote the row
  (the label is localized when the sync pushes it), and the shopping API exposes
  `urgency_label_key` next to `urgency_label`, so a client can translate it.
- **Fixed: `assets/js/core/vocab.js` had lost two closing braces** in
  `vocabCategoryOfName()`. The file still parsed, but the nine functions below it
  (`vocabLooksProcessed`, `vocabSameLemma`, `vocabIsPieceProduce`, `vocabNumberForms`,
  `vocabHasPackSize` …) stayed nested inside it and were `undefined` in the browser:
  the piece-produce, prepared-salad, processed-food and singular/plural paths called
  into the void. `scripts/test-vocabulary.php` now proves that every top-level function
  of the file is defined once it is evaluated — which is also why the JS probes of
  `test-ai-product-match.php` had been silently skipping.

## [1.16.4] - 2026-10-18

### The quick stocktake reads a sentence — and its answers really act
- **The card says the facts.** Instead of a muted meta line, each card reads a sentence
  — “Ci sono ancora **120 g** di **Formaggio Morbido** di **Barilla** in **🧊 Frigo**” —
  with the quantity in a large accent pill and the article, brand and place in bold: the
  four things being checked are the four things you see, in all six locales
  (`audit.sentence` / `audit.sentence_brand`, placeholders `{qty}`, `{name}`, `{brand}`,
  `{location}`; values escaped, only the markup is ours).
- **Five real answers instead of three.** *Sì, confermo* writes nothing (the row is
  right). *Cambio quantità* opens the normal inventory edit sheet **on top of the
  stocktake** — the run stays underneath, so nothing is lost — with the quantity field
  in view, manual or from the scale as before (`edit-qty-group`). *Cambio posizione*
  opens the same sheet with the location selector in view (`edit-loc-group`); after a
  save the card refreshes from the live pantry, and a row set to 0 drops out. *No, è
  esaurito* is the real “usa tutto” (`inventory_use` + `use_all`): consumption stats,
  the shopping list and the depleted-product rules react. *No, è deperito* is the real
  “butta tutto” (`inventory_use` + `notes: "Buttato|spoiled"`): it counts as waste and
  feeds the waste-learning hints. *Quasi finito* and *Salta* stay as the quiet answers,
  and the summary counts `{finished}` and `{spoiled}` separately.
- **A run closed by mistake resumes within the hour.** The point reached is stored in
  `localStorage` (`evershelf_audit_resume`, one-hour TTL): the scope picker shows *↩
  Riprendi l’inventario (N rimasti)* above the scopes, every answer is persisted as it
  is given, the saved rows are reconciled with the live pantry (deleted/moved rows are
  harmless) and a completed run clears the entry. After an hour the pause is dropped.
- **“Leggi dalla bilancia” on the card.** When the scale is enabled, configured and
  really connected (`_scaleConnected`) and the row has a weighable unit (g/ml, or a
  pack measured in g/ml), the card shows a one-tap *⚖️ Leggi dalla bilancia*: it opens
  the edit sheet and starts the live reading (`readScaleForEdit`), which fills the
  quantity; the sheet keeps its own button too.
- **↩ Indietro, with a real undo.** After the first card, a quiet *Indietro* button (and
  `Backspace` on a keyboard) rewinds one answer; the summary keeps the finished run in
  memory until *Fatto*, so the **last** article can still be fixed from there. A write
  still sitting in the batch queue is simply dropped, an already-sent quantity update is
  put back to the previous quantity, and an `inventory_use` (esaurito/deperito) is
  reverted through `transaction_undo` with the `transaction_id` returned by the write
  (persisted with the paused run). If the revert fails the run is not rewound and the
  user is told instead of being shown a lie.
- **The keyboard cannot fire under the edit sheet.** The arrow shortcuts (↑ is now
  *deperito*) are ignored while `#modal-overlay` is open or a field has focus.
- **`scripts/test-quick-audit.php`** grew to 73 checks: the sentence and its four
  placeholders in every locale, the five decisions, the two edit-sheet anchors, the
  use-all/throw-all payloads, the resume TTL and answered-row skipping, the scale
  availability rules, the queue/sent/transaction-undo paths of *Indietro*, the batched
  updates and the keyboard guard.

## [1.16.3] - 2026-10-18

### The backup panel speaks the UI language (follow-up to #290)
- **No more English inside a translated UI.** The restore endpoint answered with
  English prose (“Restore complete — reload the page…”) and the panel printed it; the
  delete/OAuth failures and the Drive push line carried hardcoded English
  (`Error deleting backup`, `Code exchange failed`, `purged: N`), and the retention
  unit had an Italian `'gg'` fallback. Every backup/Drive failure now returns an
  `error_key`, the client resolves it with `t()` (`_apiErrorText()`), and restore
  returns `message_key` while the panel shows its own literal key.
- **14 new keys in all six locales** — `error.backup_no_dir`, `backup_no_db`,
  `backup_copy_failed`, `backup_invalid_name`, `backup_not_found`,
  `backup_delete_failed`, `backup_restore_failed`, `gdrive_disabled`,
  `gdrive_folder_missing`, `gdrive_not_configured`, `gdrive_not_authorized`,
  `gdrive_code_missing`, plus the dynamic `settings.backup.gdrive_error` (`{error}`)
  and `settings.backup.gdrive_pushed` (`{file}`, `{purged}`).
- **The panel's HTML fallbacks are English** (the universal fallback language), so the
  source no longer shows Italian text that looks hardcoded. The labels were already
  wired with `data-i18n` and render in the selected language — verified in the real DOM
  (English “Backup Now” / German “Jetzt sichern”).
- **`scripts/test-backup-i18n.php`** locks backend → `error_key` → bare `t('…')` → six
  locales (with placeholders), asserts English ≠ Italian for every label, and fails if a
  hardcoded English fallback is reintroduced in the backup panel.

## [1.16.2] - 2026-10-18

### The daily backup actually runs (issue #290)
- **The image now runs its own scheduler.** `docker/evershelf-cron` is installed as
  `/etc/cron.d/evershelf` and executes `api/cron_smart_shopping.php` every 5 minutes as
  `www-data`; the container starts the daemon before Apache. Until now
  `BACKUP_ENABLED` defaulted to `true` but no scheduler existed in the image, so on
  Docker/Unraid the "automatic daily backup" never happened — the panel promised it and
  the UI never warned. Bare-metal installs keep using their host crontab.
- **A backup is a consistent snapshot.** `createLocalBackup()` uses `VACUUM INTO`
  (reading through the WAL) into a `.part` file and renames it, instead of a plain
  `copy()` that could catch a write in the middle; older SQLite builds fall back to a
  checkpoint + copy. A failed run never leaves a half file behind.
- **The UI is honest and warns.** `get_settings` exposes `backup_last_ts`, the Settings
  checklist keeps a backup row until a fresh one exists, and the backup panel shows how
  old the last backup is and warns when it is older than 8 days (or when none ever
  ran). The automatic-backup toggle is now really on the page —
  `setting-backup-enabled` was read by the code but never rendered. New strings in all
  six locales.
- **Docs:** Installation, Configuration, Unraid and the FAQ now say that Docker backups
  are automatic, where the files live (`data/backups/`, **inside** the volume — copy
  them off-volume for a real backup) and how to restore one.
  `scripts/test-docker-cron.php` locks the whole chain (image → cron → backup → UI →
  six locales → docs).

## [1.16.1] - 2026-10-18

### A hand-edited `.env` wins again (issue #298)
- **Stale database fallbacks no longer shadow the file.** When Settings cannot write
  `.env` (a Docker bind mount owned by root, a read-only mount) it stores the changed keys
  in `app_settings.env_overrides`. That copy used to win forever, so changing
  `OPENAI_MODEL` in `.env` and restarting the container changed nothing — the old model
  kept being used and tested. Each fallback now records the `.env` value it was saved
  against: when the file no longer matches, the hand edit wins and the stale entry is
  dropped (legacy entries are judged by the file's mtime). The log event
  `settings_override_stale` names the keys that were dropped, never their values.
- **The AI connection test says what it called.** The answer carries the model actually
  used — also on failure — and the failure is logged (`ai_test_failed`) with provider,
  model and the provider's own message. The panel wraps the full error instead of
  clipping it, and a value cleared in `.env` no longer survives in the client cache to be
  written back by the next save.
- **FAQ:** Docker bind-mount permissions (contributed by **@prein**, #297) plus the
  `.env` ownership note (`chown 33:33 .env`) that lets Settings write the file directly.
- **`scripts/test-env-overrides.php`** now covers both sides: the fallback still wins
  while `.env` is untouched (issue #261) and loses as soon as the file is edited, in the
  new and in the legacy format.

## [1.16.0] - 2026-10-17

### Installable from Unraid, with a container-safe `.env`
- **`EVERSHELF_ENV_FILE`.** The app now reads *and writes* its `.env` from the path in this
  environment variable (default unchanged: `<app>/.env`), creating it from `.env.example`
  when the folder is empty. A container can therefore keep the configuration on a mounted
  folder that survives updates — and the Settings page can save into it.
- **The Unraid template is versioned in the repo** (`unraid/evershelf.xml`, CA template
  contributed by **@DevlinDelFuego**). Two corrections were needed before shipping it: the
  **data volume** was missing (`/var/www/html/data` holds the SQLite database — without it an
  update started from an empty pantry) and `.env` was mounted as a *file*, which Docker turns
  into a directory when the file does not exist yet. It now mounts the folder (`/config`) and
  passes `EVERSHELF_ENV_FILE=/config/.env`.
- **`docs/wiki/Unraid.md`**: install, the two mandatory paths, permissions (`chown -R 33:33`),
  the pairing code from `docker logs`, backup of both paths and a troubleshooting table.
- **`scripts/test-unraid-template.php`**: guard test — XML well-formed, published image, port
  80, both persistent paths, the env override, that `evershelfEnvFile()` honours it, that no
  writer keeps a hardcoded `.env` path, and that the wiki/README explain it.

## [1.15.3] - 2026-10-17

### The tablet stops “resetting” itself
- **A warm reload no longer pays the 3 s splash.** The boot rail exists to tell the user what
  the app is checking; on a reload inside a session that already ran (language change, the
  kiosk ↻, an update) it made every refresh look like a reset. The first boot of a session
  keeps the full rail, a warm one keeps only the fade (`sessionStorage` flag).
- **Kiosk: the HTTP cache is used again** (`LOAD_DEFAULT` instead of `LOAD_NO_CACHE`), so a
  reload no longer refetches every asset and the blank WebView surface stays visible for a
  fraction of the time. Cache busting is already handled by the `?v=` stamps and the service
  worker.
- **Kiosk: a page watchdog.** Every 5 minutes the WebView is asked for a value; if the answer
  does not arrive within 6 s (a hung renderer keeps showing the last frame forever) the page
  is reloaded **once** and the event is reported as `page-watchdog-reload`. It is started in
  `onResume` and stopped in `onPause`, so nothing probes while the tablet is in background.

## [1.15.2] - 2026-10-17

### The white flash on the tablet
- **The root canvas was white.** `body` painted the app background but `<html>` did not, so
  every frame in which the WebView surface was not painted yet (renderer restart, start of
  a navigation, surface re-attach after resume) showed white. `html` now paints `var(--bg)`
  too, and the Android `theme-color` follows the light/dark theme instead of staying fixed
  on the light accent.
- **Kiosk**: the WebView surface is now the splash colour (`#0C1222`) instead of the default
  white (`webView.setBackgroundColor`), and `onResume` no longer reloads the whole app when
  the WebView canonicalised the URL (`host:8080` → `host:8080/`) — that reload was the other
  half of the “it resets for a moment” feeling. **Needs a new APK**; the CSS/JS half reaches
  the tablet as soon as the web app is updated.

## [1.15.1] - 2026-10-17

### A save is a decision: the checklist empties itself
- **“Da decidere” no longer repeats an answered question.** An option the user answered
  (“not now”) at the current `askVersion` is a decision, not a pending task: it leaves the
  list instead of sitting there forever (bumping `askVersion` asks again, as before).
- **Saving a configuration removes it from both lists.** Before the request the page
  snapshots which `ask` options were unconfigured; after the save it acknowledges the ones
  that are now configured (they leave “Da decidere”), plus every “Novità” row in the tab
  the user just saved (they saw it and saved). Rows in other tabs keep their news, so
  saving the AI key cannot clear an option nobody looked at.
- Guarded by `scripts/test-settings-checklist.php` (21 checks): the shipped functions are
  extracted from `app.js` and run in node against a stub checklist — answered option out of
  “Da decidere”, just-configured option acknowledged, news of the saved tab cleared, news of
  another tab kept, non-`ask` rows never acknowledged.

## [1.15.0] - 2026-10-17

### Find a setting by typing what it does
- **A search field at the top of Settings.** It matches the label, the description, the
  synonyms ("etichette" finds *Tag*, "stocktake" finds *Inventario veloce*), the tab name
  and the `.env` key (`GEMINI_API_KEY` works), accent- and case-insensitively. A result
  opens the right tab, scrolls to the field and flashes it; `/` or `Ctrl/⌘+K` focuses the
  field, arrows and `Enter` pick a result, `Esc` clears it.
- **One registry for the whole page.** `scripts/gen-settings-schema.php` reads the settings
  markup and writes `assets/js/settings.schema.generated.js` + `api/lib/settings_schema.php`:
  **114 controls**, each with its tab, section, i18n label, description and `.env` key. The
  page still renders its own HTML — the registry is what makes the search, the descriptions
  and the coverage test possible without a rewrite.
- **Nothing is unreachable.** The 48 `.env` keys that have no control (tokens, logging,
  support, tuning) are listed in `api/lib/settings_env_allowlist.php` with a reason and stay
  searchable as “server setting”, so all **129 keys** are one search away.
- **A guard test for it**: `scripts/test-settings-schema.php` (27 checks) proves the registry
  is in sync with the page, every control has a label and a description, every string exists
  in all six locales, every tab belongs to a known section, and every `.env` key is either a
  control or a documented server-only key.
- Fixed on the way: the product-rules card had a duplicated *Famiglie* block (duplicate
  `id`), which made the family toggle ambiguous.

## [1.14.0] - 2026-10-17

### Product tags, and nothing disappears when a refresh fails
- **Custom tags on the catalogue (#97).** Free-form labels ("senza glutine", "bio",
  "bimbi") live in two relational tables (`product_tags`, `product_tag_links`), so
  "which products carry X?" is an index lookup and renaming a tag is one row. The pills
  sit on the inventory card and double as the filter bar above the list (AND across the
  selected tags); the product form has a comma-separated field with autocomplete from
  the tags already in use; `inventory_list` and `product_get` ship the labels with the
  row, so the client needs no second round trip. The option is a normal setting
  (`TAGS_ENABLED`, default on, toggle under Settings → product rules).
- **Quick stocktake (#285).** An *Inventario veloce* button on the pantry page walks the
  pantry one card at a time — scope: everything or one location — with three big answers:
  *there is* (nothing is written, the row is right), *almost out* (the unit's low-stock
  value: 1 pz / 0.5 conf / 100 g·ml / 0.1 kg·l) and *finished* (0). Answers are queued and
  written through `inventory_update` every `AUDIT_MODE_BATCH_SIZE` items (default 5) and at
  the end, and the summary counts what changed; a change that did not save is reported
  instead of disappearing. Arrow keys work too, but a kiosk needs no keyboard. Both the
  feature and the batch size are normal settings (`AUDIT_MODE_ENABLED`,
  `AUDIT_MODE_BATCH_SIZE`, under Settings → product rules).
- **Parent / child products (#284).** A product can now name the generic article it belongs
  to (`products.parent_id`, one level on purpose): *Spaghetti Barilla n.5* keeps its own
  brand, barcode, price and stock while the pantry also shows how much the family holds
  (`Famiglia Spaghetti: 4 conf in 2 varianti`). The family is set from the product form
  (autocomplete over the umbrellas already in use; a name that does not exist yet becomes
  the umbrella), and `product_family` returns the umbrella with its children. The
  duplicate consolidator now refuses to merge a child — as a seed or as a candidate — so
  an explicit variant can never be deleted by the automatic cleanup. Off with
  `PRODUCT_VARIANTS_ENABLED=false` (settings toggle, `.env.example`).
- **Receipt OCR (#114).** A *Scontrino* button on the scan page takes one photo (a file
  input with `capture="environment"`: the camera on a phone, the gallery on a desktop)
  and `parse_receipt` asks the vision model for the purchased lines — name, brand,
  quantity, unit and price — in the app language. The answer is untrusted: every row goes
  through `receiptSanitizeItem()` (known unit, finite quantity, bounded price, no blank
  name) and the list is capped at `RECEIPT_OCR_MAX_ITEMS`. The **endpoint never writes**:
  an editable review table opens, rows can be corrected or removed, and only *Add N to the
  pantry* creates the catalogue row (`name_user_set`) and the `inventory_add`, counting
  what was saved and what failed. Off with `RECEIPT_OCR_ENABLED=false`; it runs in the AI
  rate-limit bucket.
- **A failed refresh no longer blanks the screen.** `inventory_list` answering with a
  JSON error (a rate limit, a restart) used to replace the pantry with an empty list:
  the payload is validated now and, when it is missing, what is on screen stays. The
  shopping list keeps its content while refreshing, and both ignore an answer that
  belongs to an older request (`_requestId`).
- **The smart-shopping cache declares its schema.** A cache written before the
  empty-stock rule existed is rebuilt instead of served, and Bring! never suggests a
  product that is still in the pantry.

## [1.13.0] - 2026-10-16

### A scanned tomato is not a jar of passata — and the vocabulary is data
- **A loose vegetable never becomes the pantry's tin of the same word.** The duplicate
  consolidator merged any two barcode-less rows whose names merely shared a substring
  ("pomodoro" ⊂ "passata di pomodoro") and kept the row with the barcode: the tomato the
  user had just scanned was deleted into the jar. The documented "at least one row has a
  barcode" gate is enforced, the loose head-prefix rule is word-bounded and asks the
  curated dictionary whether the extra words name *another* product (a variety merges,
  "Latte" vs "Latte di mandorla" does not), and the same rule now guards the reuse path.
- **The name→category guessers are one vocabulary again.** They had drifted into two
  different ones (26 of 83 probe names disagreed: formaggio, farina, zucchero, biscotti,
  funghi, miele, detersivo… were 'altro' on the server; "Marmellata di albicocche" was
  frutta; "Carote" was a beverage in the browser because of an ASCII `\b`). The PHP is now
  a port of the JS and both read the same data.
- **The AI also says how the product is counted.** Both identification prompts return a
  plausible `unit` (pz/g/ml/conf), `quantity` and the pack size on the label; the answer
  is whitelisted server-side and the add form opens on it (two tomatoes → 2 pz, cut
  cheese → 500 g, a jar → its 700 g pack) instead of a hardcoded "pz/1".
- **A new pantry row below the floor is refused.** `inventory_add` rejects < 20 g/ml
  (0.02 kg/l, 1 pz, ¼ conf) — the reported "2 g instead of 2 pieces" that
  `isInventoryDepleted()` hid the moment it was written. Client and server share the
  floor; tomatoes are piece produce; the refusal is a translated `error_key`.
- **"Spesa stimata" is one number.** The server resolves the basket from the smart cache
  (the caller's quantity is only the fallback), one cache key per basket and a cache that
  keeps a few baskets instead of one evicting slot: the app, the kiosk and the Home
  Assistant sensor finally agree.
- **Every recorded spend leaves a trace.** `spend_add` logs the amount and the resulting
  month total (the ledger has no correction UI, so "the month jumped" must be answerable).

### The vocabulary is DATA (multilingual by construction)
- `api/vocab/<lang>.json` holds product genres, brands, piece produce, preparations,
  number/agreement forms, plural suffix pairs, stop words, synonyms and the shopping
  dictionaries. `api/lib/vocabulary.php` is the only algorithm that reads them — no
  product word and no Italian rule in the code, and **no regex on vocabulary**.
- The browser runs the same engine (`assets/js/core/vocab.js`) over
  `assets/js/vocab.generated.js`, generated from the JSON (`scripts/gen-vocab-js.php`)
  and precached by the service worker. Adding a language = adding a JSON file.
- `scripts/test-vocabulary.php` locks it: the generated file must match the JSON, and a
  ratchet counts the vocabulary words still in the logic files (the migrated ones are at
  zero and may only go down). Guard tests added for the AI match, the quantity floor and
  the canonical shopping total.



### Optional support: a donation, or nothing for now
- **The project can now be supported without becoming a paid product.** Once, at
  startup, and only while nothing has been decided, the app opens a quiet dialog:
  the real running costs (domain, server, developer account, AI credits), then two
  answers — **💙 donate with PayPal** and **⏳ later**. Nothing is blocked, hidden or
  degraded by the answer: it only decides whether the donation card is shown.
  Closing the dialog with ✕ is not an answer; either choice is final until the
  household changes it in **Settings → Info → ❤️ Support EverShelf** (no recurring
  reminder, no countdown) — or with the **❤️ Support EverShelf** button right below
  *Salva Configurazione*.
- **No advertising anywhere in the app.** The banner slots (dashboard +
  screensaver), the *keep the banners* / *both* answers and the whole provider
  plumbing were built and then **parked**: the code stays in the tree commented out
  (`api/lib/support.php`, `assets/js/core/support.js`, `assets/css/style.css`), the
  two `[data-ad-slot]` divs are commented out of `index.html`, `_renderAdSlots()` is
  a no-op kept only so a client cached in a kiosk WebView cannot throw, and the
  dialog offers just the two answers above. "later" is the default, a stored
  banner answer reads as "later", and the payload no longer carries a provider, an
  AdSense id or any banner markup — so nothing can be shown by surprise. The
  step-by-step re-enabling checklist, and the design as it was, live in
  `docs/wiki/Support-and-ads.md`.
- **Donations**: the dialog shows the PayPal link with a QR code to scan from a
  phone. `SUPPORT_PAYPAL_URL` accepts a `paypal.me` handle, a legacy donation form
  or a hosted donate button; the link adapts by itself where PayPal allows it
  (`…/10EUR` on `paypal.me`, `?amount=10&currency_code=EUR` on the legacy form).
  A hosted button (`?hosted_button_id=…`) **ignores** `amount=` — the page shows its
  own presets and an editable field — so no amount buttons are offered there and the
  dialog says the amount is chosen on PayPal, instead of promising a figure PayPal
  never receives. Costs are disclosed line by line from `.env` (`SUPPORT_COST_*`)
  with the monthly equivalent computed on the spot, so the numbers cannot drift from
  reality. Walkthrough: `docs/wiki/Support-and-ads.md`.
- **The QR is drawn by the server** — `api/index.php?action=support_qr`, byte-mode
  encoder in **`api/lib/qr.php`** (ISO/IEC 18004, versions 1–40, all four EC levels,
  automatic version and mask selection), SVG for browsers and PNG for the native
  kiosk. No CDN and no external image service: it works on a LAN-only install. The
  endpoint is public on purpose (the kiosk wizard shows the code *before* it can
  pair, so it never holds a token) and can only render the published amounts, so it
  cannot be turned into a generic QR renderer. Guard tests:
  `scripts/test-qr-encoder.php` (17 frozen vectors, byte-identical to python-qrcode
  for forced masks and verified with a real decoder) and
  `scripts/test-support-monetization.php`.
- **Kiosk wizard**: the *Features* step now carries the same two answers plus the
  donation QR (fetched from `?action=support_qr&format=png`, shown only when the
  answer is the donation; the two banner radio buttons stay commented out in
  `activity_setup.xml`). The choice travels through `save_settings` with the other
  feature flags, so the web dialog does not ask again on that server.
- New state in `.env`: `SUPPORT_MODE`, `SUPPORT_DECIDED`, `SUPPORT_PAYPAL_*`,
  `SUPPORT_COST_*` — all documented in `.env.example`. The parked keys
  (`SUPPORT_BANNERS_AVAILABLE`, `ADS_PROVIDER`, `ADSENSE_*`, `ADS_CUSTOM_*`) stay
  documented there too, commented, so bringing the slots back is a code change and
  not a configuration one. `save_settings` accepts the answer (clamped to the two
  modes) and has no banner mapping at all.
- **Settings UI**: the chosen answer is now a filled green card with white text (a
  border-only highlight left the label unreadable next to the “already on” badge,
  which also stole the width the title needed — it now sits under the text), and
  **❤️ Sostieni EverShelf** is a full-width button right below *Salva
  Configurazione* that opens the very same dialog as “Apri la scelta completa”, so
  the choice is one tap away from any settings tab.
- **Choosing a donation turns the dialog into a thank-you**: the answer cards
  step aside, a warm line appears, and the PayPal QR is now **large** (240 px, 260 px
  in that view) so it scans from across the room. A discreet *“Change the choice”*
  link brings the other answer back, so nothing is locked in without a way out.
- Guard hardening: `scripts/test-support-monetization.php` checks the *shared*
  script scope of `core/*.js` + `app.js` (a name declared twice across them is a
  fatal SyntaxError that `node -c`, one file at a time, cannot see — how a
  `let _supportDonateUrl` next to `function _supportDonateUrl()` once killed the
  module), that every helper the module calls exists, that `index.html` uses a
  single `?v=` stamp — and, since the slots were parked, that **no** banner field
  travels in the payload, that the banner helpers are gone, that the two slot divs
  live only inside HTML comments and that no live line renders a slot;
  `index.html` itself is served `no-cache` now, so a stale shell can no longer point
  at the previous asset stamp.
- i18n: the `support.*` namespace in all six locales (the banner-only strings stay,
  dormant, for the parked feature), and the new module lives in
  `assets/js/core/support.js` (loaded like `core/auth.js`) so it stays out of the
  29k-line `app.js`.
## [1.11.14] - 2026-10-14

### Fixes
- **An AI-identified product could not be added to the pantry — the answer was "Product ID required"** (issue #282). The insert is keyed by `product_id`, and the add form could be reached with a draft that had no catalog row behind it. `submitAdd()` now materialises the row first (`_ensureAddProductRow()` → `product_save` with `name_user_set`, so the title the user confirmed is the stored one) and only then posts `inventory_add`; a draft with no title at all is refused with a translated message instead of a 400.
- The two flows that accepted a bare `success` without an id before opening the add form (`createQuickProduct()`, `saveAIProductDirect()`) now require a real row id; the `barcode_already_used` owner branch still resolves the stored row first.
- Backend errors are translatable: `addToInventory` / `useFromInventory` answer a stable `error_key` (`error.product_id_required`) and log `api_inventory_add_missing_product_id` / `api_inventory_use_missing_product_id`; the client resolves the key through `_apiErrorText()` instead of printing the raw English string. New key in all six locales.
- Guard test: `scripts/test-inventory-add-guard.php`.

## [1.11.13] - 2026-10-14

### Recipes
- The per-ingredient **Usa** button was the smallest control on the screen (0.78 rem): it is now **1.55 rem** (double) with a 48 px minimum height and generous padding, so it reads like the primary action it is — including the transient ⏳ state. The passive **✔ Scalato** label stays a little smaller (1.05 rem) so it does not squeeze the ingredient name on a phone.

### Fixes / tooling
- `scripts/bump-version.sh`: the `sw.js` cache pattern never matched a cache name carrying the asset-stamp letter, so `evershelf-v1.11.12d` survived the 1.11.13 bump and the browser kept serving the stale stylesheet. The pattern now accepts the optional letter and writes `<version><stamp letter>` (`evershelf-v1.11.13b`).
- The default asset stamp no longer collides inside the same day: it continues from the stamp already in `index.html` (`20261014a` → `20261014b`) instead of silently reusing `<today>a`, which made a CSS-only release keep the same `?v=`.

## [1.11.12] - 2026-10-14

### Alerts: one-word verdicts
- Expired/opened rows and the dashboard banner no longer print a full sentence: a coloured pill badge (**Va bene / Controlla / Non mangiare / Buttalo**) with the advice in the `title` tooltip. `.safety-pill-*` replaces the old `.banner-safety-*` text rules; `status.dont_eat` added to all six locales.

### Seasonality
- **Removing an out-of-season product now sticks.** `shopping_remove` accepts `seasonal_dismiss` and stores the family in `app_settings.seasonal_dismissed` until its next peak month (`seasonalMarkDismissed` / `seasonalIsDismissed`); smart shopping *and* the internal auto-add cron skip it, so it cannot reappear on the next run.
- Unknown/empty pantry categories no longer exempt a produce from the calendar check (a mistagged "Peperoni" was able to come back).
- **Suggestions are now evidence-based instead of calendar filler**: the review card scores purchases of the produce/family (100+), its real consumption (90+), then the kind-level habit as a lower-ranked fallback; a produce the household never buys or eats is not suggested at all. Every card shows *why* (`shopping.seasonal_reason_bought` / `_often_eats` / `_same_kind`), locked by `scripts/test-seasonal-match.php` in all six locales.

### Bring! is optional (and now invisible)
- When the internal list is the active mode, no button, toast, log entry or error message names Bring! anymore: suggestion buttons, low-stock prompt (plus its previously hardcoded modal title), add/limit toasts, activity-log label, finished-product toast and connection errors all switch to the list wording (`shopping.list_add_*`, `shopping.added_to_list`, `shopping.all_on_list`, `lowstock.yes_list`, `log.type_list`, `recipes.finished_added_list_toast`, `error.add_to_list`).

### UI
- Bigger tap targets: dashboard action buttons (`Usa`, `Ricetta`, `Modifica`, `Butta`) and the item-detail actions grew from 0.72/0.82 rem to 0.82/0.95 rem with ≥34/52 px height; seasonal **Togli/Aggiungi** buttons follow.

### i18n
- New keys in all six locales, no hardcoded Italian left in the low-stock modal, `aria-label="Close"` wired to `btn.close`, dead `dashboard.banner_advice_format` removed (and its assertion dropped from `scripts/test-i18n-icons.php`). `scripts/i18n-audit.py`: 0 missing keys, 0 unwired attributes.

### Tooling & docs
- `scripts/bump-version.sh` also rewrites the **README version badge**, which had silently drifted to 1.11.6; the “What’s new” line stays manual (prose) and the script reminds you.
- Context/agent tooling committed: `.clinerules` (always-on digest of `AGENTS.md`) and `.clineignore` (hides generated, binary, runtime and vendor files — ~94% of the repo — from the AI context).
- `README`, `CHANGELOG`, `docs/OPTIMIZATION-BACKLOG.md` and `TODO.md` realigned with the release.

## [1.11.11] - 2026-10-10

### Scan screen redesign
- Hero camera viewport with favourites + recent chips **above** the stream; layout fills the viewport so action buttons stay visible without scrolling.
- Removed Barcode / Nome / AI tabs; compact action bar: **Identifica AI**, **Cerca**, **Manuale** (larger tap targets).
- Recognition HUD: seeking particle cloud that follows barcode-like regions; green lock box only after a confirmed code; AI match opens as a full-page sheet over the camera.
- Continuous autofocus + center focus point; valid EAN confirms on first hit; digit OCR on a narrow strip.
- Speculative `resolve_barcode` while digits accumulate + warm offline product cache on scan open.
- Camera idle shows solid black (no grey ▶ poster); video muted / absolute fill.

### Product titles
- UI-tab labels (`Snacks & Sweets`, `Vegetables`, `Dairy`…) are no longer used as title genres (they orphaned stock from barcode scans).
- Maintenance helper `scripts/fix-mangled-kind-titles.php` strips poisoned prefixes and merges barcode-less stock onto the barcode twin.

## [1.11.10] - 2026-10-09

### Recipes archive
- Chat / free-slot imports no longer overwrite each other: many `libero` recipes per day are allowed; scheduled slots (pranzo/cena/…) still replace one row per day.
- Every archive row has a **`content_hash`** (title + ingredient names + steps). Re-saving the same recipe (chat import again, marking ingredients used, quantity drift) **updates** the existing row instead of cloning it. Same-title `libero` near-duplicates are collapsed on migrate.
- Migration drops the old `UNIQUE(date, meal)` constraint, backfills hashes, and deletes duplicate rows (keeps favourite, else newest). Guard: `scripts/test-recipe-archive.php`.
- Chat → recipe titles: never leave placeholder `"libero"`; derive a real dish name from the chat text / first ingredient when the AI returns an empty or placeholder title.

### Recipe “Usa ingrediente” UI
- Needed amount is shown large (“Serve per la ricetta”).
- With a connected scale: live **X / Y unit** compare, progress toward the recipe target, and clear under/ok/over feedback (i18n in all six locales).

## [1.11.9] - 2026-10-08

- **Kiosk pairing UX:** dedicated wizard step explains where to find the code (Settings → Security / server log) and collects it on its own screen (no AlertDialog).
- Native API token is handed to the WebView (`_kioskBridge.getApiToken` + `localStorage`) so the web app does **not** ask for pairing again after setup.
- Save-failed toast no longer leaves you stuck: if the server rejected the save for a missing token, the wizard returns to the pairing step.
- Kiosk **1.7.24** (versionCode 25).

## [1.11.8] - 2026-10-08

### Web app
- Settings → EverShelf Kiosk: **Scarica APK da questo server** (`releases/evershelf-kiosk.apk` on the LAN) plus GitHub `kiosk-latest`. The old “Latest release” URL 404’d because app tags own GitHub Latest and carry no APK.
- Kiosk OTA panel always keeps a direct local download; install no longer dies on `typeof` bridge checks; stuck “Avvio download…” resets after 8s.
- **Pairing code** is visible on a paired device under Settings → System → Security (and Info): large code, countdown, copy / refresh. Pairing dialog and docs no longer point only at `docker logs`.

### Android kiosk
- CI no longer marks versioned kiosk releases as GitHub `--latest`; refuses debug-signed APKs so OTA no longer forces uninstall.
- **1.7.22:** connection Test uses public `ping` (same as discovery); wizard pairs then offers keep/reset of server settings; `save_settings` sends `gemini_key` + API token; BLE connect retries up to 3×.
- **1.7.23 — full UI redesign:** emerald Corporate UI across setup wizard, settings and splash; welcome/privacy/feature cards with real explanations; progress dots use brand drawables; all new copy in EN/IT/DE/FR/ES; no leftover purple accents.

### Docs
- Wiki / README / SECURITY / ARCHITECTURE / CODEBASE-MAP aligned for pairing-in-Settings, `kiosk-latest`, and the emerald wizard.

## [1.11.7] - 2026-10-06

- Recipe detail no longer shows the empty «Mancano dalla dispensa» stub when the pantry already covers the dish (auto mode left the loading placeholder stuck). Panel appears only when there are real gaps; default `RECIPE_SHOPPING_MODE` is `off`.
- Undo of a location move (`[Spostamento]`) now reverses **both** ledger halves together — undoing only the “in” half used to wipe the stock and trigger a false “è finito?” banner.

## [1.11.6] - 2026-10-06

Home Assistant sensor/calendar parity for [ha-evershelf 1.3.2](https://github.com/dadaloop82/ha-evershelf/releases/tag/v1.3.2).

- `ha_sensor` exposes `shopping_mode` (`internal`/`bring`) and `expiry_filter=attention`.
- `expiring_today` / `expiring_3d` use the same attention rules as the dashboard.
- `ha_calendar?attention=1` returns only alert-worthy expiries; full feed still available by default (with `needs_attention` on each event).

## [1.11.5] - 2026-10-06

Shopping-list correctness + Home Assistant expiry parity (Bring stays dark when disabled).

**Shopping**

- Family restock converts mixed `g`/`conf` stock and TX into pack-equivalents (no more “24 jars of passata”).
- Conf suggestions hard-capped at 3–6 packs per trip (`SHOPPING_GUARD_MAX_CONF_PACKS`).
- Dictionary trailing genres (`piadine`/`avocados`/`muesli`→`Cereali`); ingredient phrases like `farina di riso` only match at title lead.
- Off-season ice cream pruned May–Sep; seasonal tip cards deduped by buyable family.
- Internal list marks `on_bring` so suggestions never repeat rows already on Spesa.
- Locked user titles (`name_user_set`) own name + `shopping_name` on repurchase/rescan.

**Bring!**

- Hard gate: `bringAuth` / `bringRequest` and `bring_*` actions no-op when `SHOPPING_MODE=internal` (even with leftover credentials).
- Bring catalog back-translation unused for shopping names in internal mode.

**Home Assistant**

- Expiry webhook/sensor uses the same urgency rules as the dashboard (silent on low-risk “best before”).
- `expiry` ↔ `expiry_alert` alias so `HA_WEBHOOK_EVENTS=expiry` matches the cron event name.

## [1.11.4] - 2026-10-06

Free opt-in features (each with Settings + guided “da decidere” until configured).

**Prices**

- Open Prices (Open Food Facts) as free barcode price source; `PRICE_SOURCE=auto|open_prices|ai`.
- `price_enabled_set` so the checklist asks once before enabling estimates.

**Web Push (PWA)**

- Optional browser push: VAPID keys minted locally, empty-payload wake + `webpush_inbox`.
- Subscribe / test from Notifiche; requires HTTPS.

**UX polish**

- Toast undo after simple inventory delete; torch also tries `fillLightMode`.
- Wider `refreshAppDataAfterMutation()` (favourites, discard, vacuum).
- Code indexes: run `bash scripts/gen-code-index.sh` locally (CI INDEX job awaits a workflow-scoped PAT; see Unreleased).

## [1.11.3] - 2026-10-06

Audit-driven shopping, notify, and UX batch (see `todo/AUDIT-2026-10-06.md`).

**Shopping**

- Generic list matching by shopping group; normalize/prune/dedupe on load and cron; audit script.
- `SHOPPING_REMOVE_ON_BUY=trip` removes list rows on purchase; **Spesa fatta** clears the list.
- Smart `period_usage` caps; `need_qty` / `need_unit` columns; regression tests.

**Notify & integrations**

- ntfy/webhook events: `shopping_remove`, `shopping_trip_complete`, `weekly_digest` + cron.
- Telegram webhook: `/lista`, `/scadenze` (optional `TELEGRAM_*` in `.env`).

**UI & cooking**

- `refreshAppDataAfterMutation()` after key flows; list subtitle under generic title.
- Cooking scrap reuse hints (peels, cores, shells); price cache when AI is off.

**Ops**

- `EVERSHELF_DISABLE_HTTPS_REDIRECT`; mcp-server npm audit fix (proxy-addr).

## [1.11.2] - 2026-10-06

**Fixes**

- **Extend expiry updates the UI.** After `inventory_update` for a new expiry date, the client
  recalculates days-to-expiry from the date, marks the review as confirmed for the banner
  queue, reloads banner alerts, and calls `refreshCurrentPage()` so dashboard cards and
  inventory lists do not stay stale.

**Docs**

- Added `docs/OPTIMIZATION-BACKLOG.md` — prioritized bugs, optimizations, and feature ideas.

## [1.11.1] - 2026-10-06

Faster barcode identity, fewer bogus singular titles, a short favourites rail, and
a shopping list that actually remembers what ran out.

**Highlights**

- **Barcode lookup is snappier without calling the AI.** Parallel OFF mirrors abort
  as soon as the first one answers; GTIN-14 / EAN-8 / UPC-A variants are tried as
  candidates; the camera path confirms UPC-A in one good frame, brings ZBar in
  earlier, and uses an adaptive threshold on the crop so shiny packs decode faster.
  AI stays behind the manual button / `BARCODE_AI_FALLBACK`.
- **Package collectives stay plural.** `Ceci`, `Fagioli`, `Lenticchie`, `Piselli`,
  `Cereali`, `Spinaci`, `Gnocchi`, `Grissini`, `Taralli`, `Sardine`, `Pelati` are
  invariant — one tin still holds many beans, so the title never becomes `Cece`.
- **Automatic favourites are the top 3 only.** Reaching `AUTO_FAVORITE_MIN_USES` is
  no longer enough; only the absolute top `AUTO_FAVORITE_TOP_N` (default 3) most-used
  products get the star. A manual unstar still always wins.
- **Shopping list catches finished and almost-finished products.** Trace crumbs
  count as empty, depleted products the household has bought/used come back at
  least as medium urgency, near-empty stock (≤25 %) is flagged even for non-staples,
  and loose any-token "already covered" matching no longer hides a finished staple
  behind a loosely related cousin on the shelf.

## [1.11.0] - 2026-10-06

An article is now named the way a catalog names it, and counted the way a pantry
counts it: the title stays singular, the shelf says how many there are.

**Highlights**

- **The stored title is the singular.** `Fette biscottate Integrali` becomes
  `Fetta biscottata integrale`, `Uova medie` becomes `Uovo medio` — the catalog, the
  search and the maintenance pass always speak of *one* article, so the same product
  cannot look like two. Only the genre that **leads** the title moves (a title that
  merely contains it, `Bucce cotte di pomodoro`, is left alone), the adjectives right
  after it follow the move (`Uova medie` → `Uovo medio`), a variety name never does
  (`Tarallini` is not `Taralli`, `Mela rossa Gala` keeps its `Gala`), and a title
  opening with a quantity is not touched at all (`6 UOVA PASTA GIALLA`): the number
  must not jump behind the genre. Package collectives (`Ceci`, `Fagioli`, …) and
  mass nouns the dictionary does not number are invariant — the app never invents a
  plural (or a bogus singular) it was not given.
- **The pantry shows the plural, counting pieces only.** The list is rendered from a
  `display_name` the API derives at read time (`3 Uova medie`, `2 Mozzarelle`), so three
  eggs read like three eggs while `500 g Pasta` stays a mass and never becomes
  `500 g Paste`, and millilitres, kilos and unknown units are never guessed.
- **Certifications keep their capitals.** `Igp`, `i.g.p.` and `igp` are all written back
  as `IGP`, `Dop` as `DOP`, `Evo` as `EVO`, inside brackets or not, HTML entities
  included; an unknown all-caps token is read as a sigla and kept (`XYZ`), while `BIO`
  stays a word and follows the sentence case like everything else.
- **The buyable word is not a title.** The maintenance pass leaves
  `products.shopping_name` exactly as it is: the shopping list buys `Grissini`, not one
  `Grissino`, so renaming the title does not rename the Bring! item.

**Internals**

- `productKindSingularizeName()` / `productNameForPieces()` and the curated
  `evershelfProductKindNumberForms()` / `...AgreementForms()` directories live in
  `api/lib/product_kind.php`: one vocabulary shared with the genre resolver, read
  through one lookup, with the two directions asserted to be a round trip
  (singular → plural → singular) so the pass can replay without drifting a title.
  `scripts/test-product-number.php` locks the number rules, `productTitleSiglaForm()`
  the certifications, and `scripts/test-product-kind-prefix.php` the genre, the case
  and the single dictionary.

## [1.10.1] - 2026-10-05

The splash screen now shows what the app is doing instead of a bare spinner: a rail
of stage icons that starts grey, blinks while a stage is being checked, and lights up
with an aura in its own colour when it passes — amber or red when it does not. The
version is printed large enough to be read out when opening a bug report.

**Added**

- **Boot rail on the splash.** Seven icons — connection & access, PHP runtime, storage
  & permissions, database, configuration, network, app start — driven by
  `_preloaderStage()`: grey (`is-pending`) → blinking (`is-active`) → coloured with a
  glow (`is-done`), with a lit connector behind every settled stage. The ~30 flat
  `health_check` keys are grouped by `_PRELOADER_STAGE_OF_CHECK`, so a failed stage
  can never look green (`is-warn` / `is-error` override the accent). On phones the
  per-icon labels collapse into the caption line naming the running stage.
- **Version chip on the splash.** Previously a 0.68rem grey footnote; it is now a
  bordered pill next to the logo, and `bump-version.sh` keeps rewriting it (the
  `preloader-version">v<semver>` marker is unchanged, and a guard test locks both
  sides of that contract).
- **Every article title starts with a capital letter.** The name is shaped at the one
  choke point (`mergeIncomingProductFields()` → `productTitleCapitalize()`), so a scan,
  an import, a catalog title, the AI and a hand-typed rename all land as `Latte fresco`,
  never `latte fresco`. Only the first letter is raised: the rest of the word keeps its
  case (`NUTELLA` stays `NUTELLA`; a brand spelling such as `iPhone` becomes `IPhone`,
  because the rule is mechanical and cannot disagree with itself), a title that opens
  with a digit or an emoji is left alone, and the rule stays on when the genre prefix is
  switched off (`PRODUCT_KIND_PREFIX=false`) because it is applied outside that guard.
  Titles already stored are fixed by the same pass
  (`settings → Nomi e preferiti automatici → Applica agli articoli esistenti`).
- **`scripts/test-preloader-stages.php`** — asserts the drawn rail, `_PRELOADER_STAGE_KEYS`,
  the stage ownership of every health check and the six locales stay in sync, plus the
  CSS state rules (grey → blink → aura, reduced-motion) and the version markup.

## [1.10.0] - 2026-10-05

Two things the pantry should do by itself: say what every article actually is, and
keep the products you really use where you can reach them.

**Highlights**

- **The genre leads the title.** A scanned, imported or hand-typed product now
  carries its genre in front of the name — `Fiori di latte` becomes
  `Yogurt Fiori di latte` (or `Formaggio …`, depending on what it is) — because the
  label rarely says what the product *is*. The genre is resolved cheapest-first:
  the curated Italian dictionary that already drives the shopping/Bring! names,
  then the signature cache so similar products reuse it, then **one** AI word
  (cached per name and per signature, still capped by `GEMINI_CLASSIFY_DAILY_MAX`),
  then the localized app category. A title that already leads with a genre — or with
  something similar (`Tarallini` vs `Taralli`, `Pera Italiana` vs `Pere`, `Kaffee` vs
  `Caffè`, `Italia Zuccheri` vs `Zucchero`) — is never touched twice, and neither is a
  title that already opens with the genre stored on the product, so the pass can be
  re-run as often as you like without a title drifting.
  `settings → Nomi e preferiti automatici → Applica agli articoli esistenti` renames
  the articles already stored (with a preview first). Off with
  `PRODUCT_KIND_PREFIX=false`.
- **Products you use often become favourites.** Consuming a product
  `AUTO_FAVORITE_MIN_USES` times (default 3) within `AUTO_FAVORITE_WINDOW_DAYS`
  (default 90, the same window `recent_popular_products` reports) promotes it to the
  top of the inventory. The rule only ever *adds* favourites, and unstarring one by
  hand records a veto (`products.favorite_user_override`) so the automation never
  puts it back.
- **The scan step no longer decides by itself.** A barcode label or an AI guess can
  be wrong, and the add-to-pantry step is the last moment you see it: the identified
  **title and brand are now tappable there** (and on the AI match card) so you can
  correct them before saving. The correction is stored with `products.name_user_set`,
  which is what stops the next rescan of the same barcode from putting the wrong name
  back; the stored title still comes back through the API with the genre prefix, the
  capital letter and the singular form applied.

**Schema**

- `products.kind` — the genre resolved for that article (also makes the maintenance
  pass idempotent and free of extra AI calls).
- `products.favorite_user_override` — set when you un-star a favourite by hand.

**New action**

- `products_apply_auto_rules` (POST, `dry_run` supported) — applies both rules to
  the products already in the pantry; returns what it renamed/promoted plus a few
  samples.

**Internals**

- The curated dictionaries (phrase map, keyword map, stop words, tokenizer) moved
  from `computeShoppingName()` into `api/lib/product_kind.php` so the shopping name
  and the genre resolver can never drift apart, and `computeShoppingName()` now calls
  `productKindFromDictionary()` instead of re-reading the two maps itself: **one**
  vocabulary and **one** lookup, so on any product the dictionary knows the buyable
  name and the genre leading the title are the same string by construction (asserted
  over the whole live pantry, 397 products). `products.shopping_name` and
  `products.kind` stay two separate columns on purpose: the first is the buyable word
  of the list/Bring! (historical, may be a raw token), the second the genre inside the
  title (precise, and allowed to stay empty).
  `scripts/test-product-kind-prefix.php` locks that down together with the new
  i18n keys and the UI wiring. `scripts/test-auto-favorite.php` runs the favourite
  rules on an in-memory database (threshold, window, veto, sweep).

## [1.9.4] - 2026-10-05

Two UI bugs that no test was watching: the home page drew every chart empty, and a
few screens printed an HTML tag instead of a quantity.

**Highlights**

- **The dashboard charts are back** — macros, nutrition score, monthly categories and
  the spend comparison sat at zero since **v1.9.1**. `_applyInsightPhase()` reveals one
  panel per rotation and fills its bars from their `data-target`, but it asked
  `showNutr`/`showMonthly`/`showMacros`/`showSpend` whether that panel was the current
  one: the commit that gave the dashboard three more panels deleted those constants and
  left the `if (show… )` branches behind, so every rotation tick threw
  `ReferenceError: showNutr is not defined` inside the `requestAnimationFrame()`
  callback. The exception aborted the whole reveal step, which is why *no* chart was
  drawn — not only the nutrition one — while the numbers printed around them looked
  fine. The branches now test the phase itself.
- **No HTML tags on text-only surfaces** — `formatQuantity()` appends
  `<span class="conf-size-info">(da 36g)</span>` for `conf` units, and the screensaver
  facts, the home alert banner and the item action modal print with
  `textContent`/`escapeHtml()`, where the tag showed up as literal text
  (`Lenticchie: ne hai 2 conf <span class="conf-size-info">(da 250g)</span>`). They go
  through `_formatQtyPlain()` / `stripHtml()` now.

**Upgrading from 1.9.3**

- Nothing to do: no schema change and no new setting. The charts fill in again on the
  next dashboard rotation.
- The spend comparison shows what you actually recorded: remember the
  "🧾 Quanto hai speso?" prompt when a shopping session starts — "Non ora" records
  nothing, so a month you never entered stays at `0`.
- Two guard tests join the suite and run like the rest of it:
  `php scripts/test-dashboard-panels.php` and `php scripts/test-html-in-text.php`.

### Fixed
- **The insight rotation never filled a bar since v1.9.1** —
  `_applyInsightPhase()` branches on `phase === 'nutrition' | 'monthly' | 'macros' |
  'spend'` now, exactly like the panel visibility code above it, so the
  `ReferenceError` is gone and each bar receives the width/height its `data-target`
  asks for. `scripts/test-dashboard-panels.php` (341 lines, 53 assertions) fails on any
  part of that contract: an undeclared identifier used as a guard **anywhere** in
  `app.js` (the generic half of the crash class), a reveal branch testing the deleted
  flags, a phase listed in `_INSIGHT_PHASES` without a section, a reveal branch and a
  renderer, or a bar emitted without a `data-target` — or never filled from it. The
  `data-target` rule is deliberate: bars are painted at `0%` and animated in by the CSS
  transition, so a bar rendered pre-filled would hide a broken rotation instead of
  showing it.
- **Quantity helpers leaked markup into text-only surfaces** — the two screensaver
  facts and the home alert banner now build their strings with `_formatQtyPlain()`, and
  the item action modal strips the markup with `stripHtml()` before escaping it.
  `scripts/test-html-in-text.php` (251 lines, 18 assertions) locks the class: a
  text-only call site may not hand a bare `formatQuantity()` result to
  `escapeHtml()`/`textContent`/`title`, `generateScreensaverFact()` may only build its
  `{qty}` values with a text-only helper, and the fact element itself is written with
  `textContent`, never with `innerHTML`.

## [1.9.3] - 2026-10-05

One bug, and it explains why settings on an install with a read-only `.env` kept
"disappearing": they were stored, and then never read back.

**Highlights**

- **Settings stored in the database are read again** — when `.env` cannot be written
  by the web server (Docker, or a `.env` owned by root), `save_settings` stores the
  value in SQLite and answers `success — stored: database`. But bootstrap.php read
  `DISPLAY_ERRORS` through `env()` *before* `api/database.php` was loaded, and
  `loadEnvOverrides()` cached that "database not ready" answer for the rest of the
  request — so from then on every `env()` call in that request ignored the database.
  Custom storage units, dietary preferences and the rest looked lost after an
  update, and adding one again appeared to save nothing although the row WAS written
  every single time.

  Closes [#261](https://github.com/dadaloop82/EverShelf/issues/261).

**Upgrading from 1.9.2**

- Nothing to do, and nothing to re-enter: the values saved during the broken window
  are still in the database, they simply start being read again.
- `save_settings` now reads its own fallback back before answering, so a store that
  cannot be read fails loudly (`settings_not_persisted`) instead of showing a green
  toast.
- The database fallback lives in `data/evershelf.db`: if you run EverShelf in Docker
  and your `.env` is not writable, keep `data/` on a volume or the overrides go away
  with the container.

Below is the long form of the fix, plus what the new test locks down.

### Fixed
- **Settings stored in the DB fallback were ignored for the rest of the request
  (#261)** — `loadEnvOverrides()` no longer caches the "the database is not loaded
  yet" answer that bootstrap triggers on every HTTP request. It also means the
  fallback now behaves exactly like a `.env` write: the same `env()` call, the same
  effective value, in the request that saved it and in every request after it.
  CLI and cron were never affected — a `CRON_MODE` process short-circuits the early
  `env('DISPLAY_ERRORS')` — which is why the watchdog, the notifier and the
  smart-shopping jobs kept reading their overrides while the web UI did not.
- **`save_settings` no longer claims a success it cannot prove (#261)** — after the
  fallback stores the value it is read back through `env()`; a mismatch returns
  `settings_not_persisted` and logs `settings_fallback_readback_failed` instead of
  leaving the user with a green toast and an old value. `scripts/test-env-overrides.php`
  replays the bootstrap order in a child process and fails when the value written by
  the fallback cannot be read back afterwards, so this cannot silently regress. The
  same test asserts the structural rule that let it happen: the "database is not
  loaded yet" answer must never enter the static cache.

## [1.9.2] - 2026-10-05

Two bug reports from the tracker: EverShelf answered in the wrong language, and
one API branch could take the whole backend down.

**Highlights**

- **Photo identification answers in the app language** — the `gemini_identify`
  prompt was hardcoded in Italian, so a user whose UI is in English photographed
  toilet paper and got `Carta igienica` with an Italian description back. The
  prompt is now assembled from one fragment set per locale
  (`api/lib/ai_prompts.php`), both scan entry points send the UI language, and
  the Open Food Facts lookup is asked in that locale instead of a hardcoded `it`.

  Closes [#260](https://github.com/dadaloop82/EverShelf/issues/260).
- **The API can no longer die on a function that moves** — `ping`,
  `app_bootstrap` and `health_check` answer *before* the router, so they may only
  call functions PHP has already hoisted. A declaration placed inside a block is
  not hoisted, and that shape produced 61 `Call to undefined function
  checkRateLimit()` crashes in one 20-minute window. `checkRateLimit()` now sits
  at the top of `api/index.php`, and `scripts/test-api-hoisting.php` fails on any
  function declared inside a block, or called before it exists.

  Closes [#246](https://github.com/dadaloop82/EverShelf/issues/246).

**Upgrading from 1.9.1**

- Nothing to do: no schema change and no new setting. The next photo is simply
  identified in the language the app is set to (Settings → 🌍 Language), and the
  scan does not care whether the UI was reloaded in the meantime.
- The two new checks are plain PHP scripts and run like the rest of the suite:
  `php scripts/test-api-hoisting.php` and `php scripts/test-ai-language.php`.

Below is the long form of the two fixes, plus what the new tests lock down.

### Fixed
- **`gemini_identify` ignored the UI language (#260)** — both scan entry points
  now send `lang`, the handler normalizes it with the same `recipeNormalizeLang()`
  the recipe endpoints use, and the prompt, the description, the confidence label
  and the Open Food Facts response (`lc`, `product_name_<lang>`) all follow it.
  The `category` field deliberately keeps the canonical Italian tokens, because
  `mapToLocalCategory()` matches them against the app's category keys.
- **`checkRateLimit()` could be undefined for the early API branches (#246)** —
  the function sits at the top of `api/index.php`, above every call site, and the
  new test locks that invariant with a self-tested tokenizer scan, so it cannot
  pass by accident. The same test also fails when an early branch calls a function
  that is declared nowhere, which is how a one-character typo in `ping` would
  otherwise reach production unnoticed, and it reports the exact line of the
  offending call so the fix is obvious.

## [1.9.1] - 2026-10-05

Everything below landed on `develop` after v1.9.0 was published and ships as **v1.9.1**.
The badges in `index.html`, `manifest.json` and the service-worker cache all say 1.9.1, so
the tag is no longer blocked by a mismatched version string.

**Highlights**

- **Alerts without Home Assistant** — a free [ntfy](https://ntfy.sh) topic and/or any JSON
  webhook (n8n, Node-RED, Gotify, a Discord/Slack bridge) configured in *Settings → 🔔
  Notifiche*, with a test button that reports the HTTP status of every channel. Expiry push
  used to be locked behind the Home Assistant integration: no HA, no phone notification.
- **Cron watchdog** — every CLI job pings a dead-man's-switch URL (Healthchecks.io or the
  Uptime Kuma push style), so a scheduled job that stops running raises an alert on a
  channel the household already reads, instead of leaving a smart list that quietly goes
  stale while the UI still looks fine.
- **Settings, reorganised** — the endless horizontally scrolling tab strip collapses into
  four sub-sections with their own sub-navigation, and every card becomes a collapsible
  sub-section whose heading and hint stay visible while collapsed.
- **Dashboard** — three more rotating panels (overview, freshness, trends) beside the
  existing insights, so the home page says something useful without being scrolled.
- **Setup checklist and guided assistant** — a fresh install is walked through the steps it
  still has to do (server, cron, notifications, backup…) instead of leaving the user to
  find every switch alone.
- **ICS calendar feed** — *Settings → 🗓️ Calendar* publishes the pantry as a WebCal
  subscription: one all-day event per item that has an expiry date, a ⚠️ EXPIRED prefix for
  the ones already gone, a reminder the day before, stable `UID`s, and a rotatable token.
- **Recipe → shopping list** — a recipe's ingredients land on the shopping list with the
  pantry already deducted, so only what is actually missing is asked for.
- **Seasonal produce** — shopping and recipes can be filtered against the current season
  (`data/seasonal_produce_it.json`), with every tip key translated in all six locales.
- **Android kiosk** — the setup wizard is now 9 steps (language first, permissions and
  server discovery included), and the kiosk update check polls every 30 minutes while
  throttling the GitHub API to once every 6 hours.
- **Docs and tests** — API reference and OpenAPI schema for the notification, calendar and
  recipe-shopping endpoints, an updated wiki/architecture/kiosk guide, and the checks that
  keep i18n keys, settings navigation and the shopping guards honest.

**Upgrading from 1.9.0**

- Nothing to do: the SQLite migrations run on the first request, and the new `NOTIFY_*`
  variables are written from *Settings* like any other option.
- Existing Home Assistant automations keep working: the notification events keep their
  names (`expiry_alert`, `shopping_add`, `stock_update`) and still fire from the same choke
  point. `NOTIFY_ENABLED` gates the two new channels only, it does not touch the HA notify
  service, which keeps its own `HA_ENABLED` + `HA_NOTIFY_SERVICE` switch.
- The calendar feed is off until you generate a token, and rotating that token invalidates
  the old subscription URL immediately.

Every item below is the long form of the list above.

### Added
- **Outbound notifications without Home Assistant (ntfy + generic webhook)** —
  push alerts used to be locked behind the HA integration: no Home Assistant, no
  phone notification. *Settings → 🔔 Notifiche* now configures a free
  [ntfy](https://ntfy.sh) topic and/or any JSON webhook (n8n, Node-RED, Gotify, a
  Discord/Slack bridge) and can **send a test notification** that reports the HTTP
  status of every channel — replacing a failure mode nobody could debug, a silent
  non-delivery discovered days later.
  - Events keep the names the HA automations already use (`expiry_alert`,
    `shopping_add`, `stock_update`) and fire from the same `_fireHaWebhook()` choke
    point, so an existing automation keeps working untouched. `NOTIFY_ENABLED` is
    the master switch for the two new channels *only*; the legacy HA notify service
    keeps its own `HA_ENABLED` + `HA_NOTIFY_SERVICE` gate. Message text is built
    server-side in `NOTIFY_LANGUAGE` (it/en/de/fr/es/zh), so one household can read
    notifications in one language while the UI follows the browser.
  - The ntfy topic is validated as a URL path segment (letters, digits, `-`, `_`,
    max 64) and the 🎲 button generates a 16-char unguessable one, because the topic
    *is* the credential on a public server. The body is clamped to 3600 bytes on a
    UTF-8 boundary, CR/LF is stripped from every header value (Title, Tags,
    Authorization, custom token header) so a product name cannot inject a header,
    and only `http(s)` targets are ever contacted. `NOTIFY_INSECURE_SSL` (opt-in)
    covers LAN servers with a self-signed certificate; the URL is never logged
    because it may carry the topic.
  - Tokens are write-only end to end: `get_settings` returns `*_token_set`
    booleans, the field shows `••••••••` when one is stored and an empty field means
    "leave the stored value alone". A non-`Authorization` token header (e.g.
    `X-Api-Key`) is sent verbatim, `Authorization` as `Bearer …`.
  - New `api/lib/notify.php`, action `notify_test` (POST, demo-blocked) and
    `scripts/test-notify.php` (44 assertions: priority clamps, UTF-8 clamp
    boundary, header sanitising, URL scheme guard, the language-aware message
    builders, `evershelfNotifyConfigured()`). 60 new i18n keys in all six locales;
    the daily cron expiry push goes through the same fan-out.

- **Cron watchdog — a stopped cron job finally says so** — a CLI job that stops
  running leaves no trace: the smart list quietly goes stale and the UI still
  looks fine. Every job now pings a dead-man's-switch URL, so *the absence of a
  ping* becomes an alert on a channel the household already reads. One optional
  setting (`NOTIFY_HEALTHCHECK_URL`) covers both free services that speak the
  same idea, and the URL shape picks the protocol:
  - no query string → **Healthchecks.io** style: the state is a path suffix
    (`…/<uuid>`, `…/<uuid>/fail`, `…/<uuid>/start`) and the detail text is the
    POST body, so it shows up as the check's log.
  - with a query string → **Uptime Kuma** push style
    (`…/api/push/<token>?…&status=up|down&msg=<detail>`, GET).
  - `NOTIFY_HEALTHCHECK_URL_<JOB>` overrides the shared URL per job
    (`smart_shopping`, `barcode_catalog`, `mealie_cache`); each job sends
    `start` at the beginning and one terminal `ok`/`fail`, and `mealie_cache`
    stays silent while Mealie is unconfigured so an unused feature never raises
    an alarm.
  - The URL **is** the credential (whoever reads it can silence the alarm), so it
    is validated as an `http(s)` URL, never logged, never returned by the API and
    never put into an error string — and a value that is set but not a URL is
    ignored with a warning instead of silently falling back. `NOTIFY_INSECURE_SSL`
    is the only way to reach a self-signed LAN Uptime Kuma.
  - The last outcome of every job is recorded in `data/cron_health.json` (best
    effort, `start` excluded) and returned by `get_settings` as
    `notify_healthcheck_status`, so the last run of every job is visible even
    before a URL is configured; job health and ping delivery are tracked
    separately, because a failing sync whose alert was delivered fine must not
    read as healthy.
  - **Settings → 🔔 Notifiche → ⏱️ Cron watchdog** is where it is configured: the URL
    field is write-only (masked `••••••••` when stored, empty never overwrites),
    **⏱️ Test the ping** pings the typed-or-stored URL and reports the HTTP status,
    **🗑️ Remove URL** deletes it, and the card lists the last run of every job with
    its age, its health and whether the ping was delivered. 22 new keys in all six
    locales; asset/i18n stamp → `20261005e`.
  - New `api/lib/healthcheck.php`, action `notify_healthcheck_test` (POST — pings
    the typed-or-stored URL and reports the HTTP status without touching the
    recorded state; the URL may come in the JSON body the panel posts *or* as a
    plain form field), `notify_healthcheck_*` in `get_settings`, and
    `scripts/test-healthcheck.php`.

- **Three more dashboard panels, and the spend card explains itself.** The rotation
  carried waste → nutrition → monthly → spend → macros and had nothing to say about the
  pantry as a whole; the spend panel silently hid itself whenever nothing was logged
  (so "the tracked spend is always zero" looked like a bug rather than a feature you
  have to feed). The rotation is eight panels now — overview, waste, trend, nutrition,
  freshness, monthly, spend, macros (`_INSIGHT_PHASES`) — with:
  - **overview** (`/dashboard.tile_*`): products, expiring, expired, open packages as
    tiles, plus where the stock actually is ("🗄️ Dispensa 12 · 🧊 Frigo 5…");
  - **freshness** (`dashboard.freshness_title`, `dashboard.score_tracked`): how many
    products have a tracked expiry, how varied the pantry is, how much of it is fresh
    (fridge + freezer) and the leading category;
  - **trend** (`dashboard.trend_*`): products used and thrown away in the last 30 days,
    each compared with the 30 before it;
  - **spend** never disappears: with nothing recorded it says so and explains where the
    number comes from (*close a shopping session from continuous scanning and enter the
    amount*), showing the current list total when the price module has one
    (`stats_spend.empty_hint`).
  - 13 new keys in all six locales, locked by `scripts/test-i18n-icons.php`.

- **A setup checklist and a guided assistant, so a new option cannot be missed.**
  Configurable options only ever lived inside the settings page: to discover one you
  had to open the right tab, and nothing ever told you that notifications — the whole
  point of an app pinging you — were still unconfigured. A single registry
  (`SETTINGS_CHECKLIST` in `assets/js/app.js`) now describes every option, the tab it
  lives in and whether it is set, and two things read it:
  - a **checklist card at the top of Settings** — one compact line while everything is
    configured — that lists only what needs a decision (the unconfigured options plus a
    *Novità* group, see below) with a **Configura →** button per row that opens the right
    tab, the right card and flashes it;
  - the **guided assistant** (the first-run wizard) now asks about notifications and
    the cron watchdog too, with a 🎲 topic generator and buttons that prove the
    configuration *before* it is saved (**send a real test notification**, **ping the
    watchdog**). Every asked step is recorded in `evershelf_setup_seen` together with
    the item's `askVersion`, so the question is asked **once** — bump that number to
    re-ask after a genuinely new option — while the checklist keeps showing whatever is
    still missing. **▶️ Rivedi le opzioni** re-runs the assistant on demand over every
    open option, not only the never-asked ones. This also fixes the wizard appearing
    only on a truly fresh install (`evershelf_setup_done`): every later start consults
    the registry.
  - The wizard's closing step is no longer the hardcoded index 4 (the list grew to
    six), and `get_settings` returns `ics_enabled` so the calendar row is not stuck on
    "not configured".
  - 12 new keys in all six locales; asset/i18n stamp → `20261005g`;
    `scripts/test-setup-assistant.php` (275 assertions: registry ↔ real tabs, wizard
    steps, all six locales, the ask-once ledger, no hardcoded closing index).

### Changed
- **The settings page opens collapsed: every card is a sub-section now.** Two panels
  (*Notifiche*, *Home Assistant*) hold about ten cards each and all of them were open at
  once, so the option you came for sat somewhere below the fold; the second level was
  also still a horizontally scrolling pill strip, where the last sub-sections of a
  section had no visible existence. Now:
  - every card with a heading becomes a **collapsible sub-section**: its heading and its
    first hint line stay visible while collapsed, the rest (fields, toggles, test
    buttons) opens on click, and **only one card is open at a time** — clicking a second
    header closes the first (`_initSettingsAccordions()`, `_toggleSettingsCard()`,
    `_closeSettingsCards()`, `.settings-card-head` / `.settings-card-body`). The `h4`
    stays where it is, so a checklist jump still finds the card *and now opens it*
    (`_openSettingsCardFor()`); the checklist card itself is exempt, and picking an AI
    provider or switching to Bring! opens the card it reveals instead of showing an
    apparently empty panel;
  - the second level reads as a **sub-section list**: wrapped, indented under its
    section and labelled (*Sottosezioni* / *Sub-sections* / *Unterabschnitte*), so all of
    them are on screen instead of scrolling sideways.
  - The checklist card now lists **only what needs a decision** — unconfigured options
    plus a *Novità* group for an option whose `askVersion` moved on since you saw it
    (`_checklistNewsItems()`). Nine rows where six said "Configured" was noise.
  - `scripts/test-settings-nav.php` grows the accordion + sub-section locks (and the four
    assertions that had ended up *after* its own `exit()`, so they now actually run);
    `scripts/test-setup-assistant.php` locks the two-group rendering, and the new
    `settings.subsections_label` / `settings.checklist.group_todo` / `group_news` keys
    exist in all six locales. Asset/i18n stamp → `20261005i`.

- **The dashboard shows fewer rows, one fact per line, and buttons side by side.**
  Every block stacked its buttons vertically (name, brand, quantity, location and
  four action buttons, each on its own line) and the top banner glued every fact into
  one sentence — "Scaduto da 106 giorni · hai ancora 15 conf (da 37g). · dispensa ·
  2026-06-21 👀 Scaduto da oltre un mese, controllare integrità confezione" — which
  says nothing a reader can act on. Now:
  - **name and brand share one line** (`.alert-item-head`, built by `_alertItemHead()`)
    and so do **location and quantity** (`_alertItemMeta()` → "🗄️ Dispensa · ne hai
    ancora 15 conf"), through the new `dashboard.still_have_qty` key;
  - **buttons sit in a wrapping row** (`.alert-item-badges` is a `flex-wrap` row with
    compact buttons instead of a `flex-direction: column` stack), so two or three fit
    per line wherever the row has room — the same layout for expired, upcoming, opened
    and rarely-used stock;
  - **expired stock is judged, not just labelled**: `status.tip_*` are full sentences
    ("Fuori data da oltre un mese: apri, annusa e assaggia…") rendered as
    `{label} — {tip}` (`dashboard.banner_advice_format`) under each row and in the
    banner, whose detail is now one fact per line (`.banner-fact` / `.banner-verdict`)
    plus the expiry date (`dashboard.banner_sold_date`) — eat / check / discard is
    always spelled out, and the label is the verdict ("Ancora buono", "Controlla",
    "Butta via") instead of a bare "OK";
  - **only the most urgent rows are shown**: 3 upcoming expiries
    (`DASHBOARD_EXPIRING_MAX`), 5 opened packages (`DASHBOARD_OPENED_MAX`, now sorted
    by `opened_at` so the *longest* open come first instead of the server's
    days-to-expiry order), 3 rarely-used items (`DASHBOARD_STALE_MAX`);
  - **the rarely-used block rotates**: it fetches a pool of 24 and shows a
    deterministic slice that changes every 30 minutes (`_staleRotationPick()` seeds a
    shuffle with the half-hour slot; `_startStaleRotation()` re-renders on the tick),
    so the same window shows the same items and the next one shows different ones;
  - **🍳 Ricetta veloce moved under the "Scaduti" card** it cooks from, and "Estendi"
    is now **"Estendi Scadenza"** (`dashboard.banner_expired_action_extend`, all six
    locales).
  - Removed while passing through: the TTS test toast printed the raw `{code}`
    placeholder, a finished-product toast was a hardcoded Italian string (now
    `toast.finished_all`), and the Fuel badge glued "· target … kcal / ≥…g prot" onto
    the layout (now `recipes.fuel_badge_target`). Asset/i18n stamp → `20261005h`.

- **The settings page now navigates in two levels: four sections, then their tabs.**
  Sixteen tab buttons shared a single scrolling strip — *Generali, API, Spesa,
  Ricette, Salute, Piano, Cucina, Camera, Sicurezza, Voce, HA, Notifiche, Bilancia,
  Backup, Calendario, Info* — mixing unrelated panels (Home Assistant sat between the
  voice settings and the shopping list), and the Kiosk download banner, the two Kiosk
  panels and the About card lived *outside* `.settings-panels`, so they were painted
  below every single tab, whatever you were configuring. The strip is grouped in
  **🤖 App & AI**, **🍳 Cucina**, **🔔 Avvisi e servizi**, **⚙️ Sistema** and only the
  tabs of the active section are shown, so the page opens on four buttons instead of
  sixteen; the Kiosk cards and About moved into *Info*, next to the version and the
  bug-report button they belong with.
  - `switchSettingsGroup()`, `_syncSettingsGroupForTab()` and `_restoreSettingsNav()`
    in `assets/js/app.js`: a tab opened from anywhere (deep link, "configure" button,
    restore) brings its section forward, the tab strip scrolls the selection into
    view, and the section + tab you last used reopen on the next visit
    (`evershelf_settings_group` / `evershelf_settings_tab`). `.click()` is used to
    switch tabs so each tab keeps its own loader (`_loadNotifyTab`, `_loadHaTab`…).
  - `scripts/test-settings-nav.php` (58 assertions) locks HTML, JS and translations
    together: every tab names a section the JS knows, every panel has exactly one tab
    and every tab exactly one panel, the page opens on a tab of the highlighted
    section, the four labels exist in all six locales, and the Kiosk/About blocks
    really live inside `#tab-info` (checked through the DOM, not a substring).
    Dropping a single `data-group` fails it.
  - Asset/i18n stamp → `20261005f`; 5 new keys in all six locales.

### Fixed
- **Labels no longer print their icon twice.** Lots of the UI showed two identical
  pictographs in a row — the guided assistant opened on "⏱️ ⏱️ Cron watchdog",
  "🔔 🔔 Push notifications" and "🎲 🎲 Genera topic", every settings save toasted
  "✅ ✅ Configurazione salvata!", and the trash bin, the pen and the "✕ Annulla"
  buttons of the product/edit dialogs doubled themselves. The cause was the same
  everywhere: a translation value that already carries its emoji
  (`settings.notify.title = "🔔 Push notifications"`) with code that has to add an
  icon of its own. Those sites now go through the existing
  `iconLabel(icon, key)` / `_stripLeadingEmoji()` helpers, which strip the emoji
  already inside the translation (wizard steps, save toasts, `product.edit_info`,
  `btn.cancel`, `use.disambiguation_all`, `use.toast_opened_finished`,
  `recipes.opt_fuel`), and the checklist row/tab markup was verified to keep one icon
  only. New `scripts/test-i18n-icons.php` (23 assertions) fails on the whole class:
  it walks `app.js` for an emoji immediately before a `t('key')` whose value starts
  with the same emoji in any locale (and `index.html` for an icon element followed by
  a label that repeats it), and it locks the new dashboard copy — verdict format,
  expiry-date line, "you still have X", fuel target — plus the short verdict labels
  in all six locales.

## [1.9.0] - 2026-10-05

What shipped between the `1.8.10` tag and this one: the calendar feed, recipe →
shopping with the pantry deducted, the seasonal-produce filters, the third CSS
layer, and the hardening of the public client-log sink.

### Security
- **The public client-log sink wrote whatever a client sent, verbatim and
  unbounded.** `client_log` is a public action, and its `messages` array went
  straight into `data/client_debug.log` — the file the in-app log viewer
  (`get_client_log`) serves and that backups copy around. A client that logs its
  own request URLs (the documented auth accepts `?api_token=…`) or its headers
  wrote that token into the
  file, and one request could append up to `post_max_size` (32 MB in the shipped
  image) to a log the rotation only checks *before* writing. Lines are now redacted
  with `evershelfRedactSecrets()` and capped at 1 KB, at most 100 per request; a
  non-array `messages` is ignored instead of reaching `foreach`.

### Added
- **Recipe → shopping list, minus what the pantry already holds** — a recipe used
  to hand you a frozen list the AI wrote when it was generated (no quantities, and
  wrong the next day). The 🛒 panel under the ingredients now recomputes the gap
  every time the recipe is opened, against the pantry as it is *right now*:
  `need − have = to buy`, so a 500 g recipe with 200 g left asks for 300 g. The
  rows are tickable and default to the gaps; **Add the missing ones** writes them
  through `shoppingAddItemsCore()`, the same core the manual add uses, so Bring!
  sync, the blocklist, generic-name normalisation and the HA webhook are unchanged.
  - Matching is by `product_id` first, then the shopping-family key, then name
    tokens (`evershelfNameTokens()`, now shared with `shopping_sync.php`); stock
    held in another unit (3 ricotta tubs vs "250 g asked") counts as *covered*, and
    free staples (water, salt, pepper, oil) are never nagged about in strict pantry
    mode. An ingredient already on the list is reported as *listed*, never added twice.
  - New action `recipe_shopping_add` (POST, authenticated + CSRF, blocked in demo),
    accepting a full recipe or a `recipe_id`, with `dry_run` for the page render,
    `selected[]` for the ticked rows and `only_missing` for "ignore what I have";
    new `api/lib/recipe_shopping.php` (`evershelfRecipeShoppingPlan()`,
    `recipeShoppingAdd()`) and `scripts/test-recipe-shopping.php` (57 assertions on
    the isolated fixture: unit families, container expansion, g/ml/pz maths,
    staples, covered/partial/listed states, dry-run purity, "only the gap" inserts).
  - The panel follows the existing *recipe shopping mode* setting: *ask* (default),
    *add automatically*, *off*. 11 new i18n keys in all six locales.

- **Calendar feed of expiries (ICS / WebCal)** — the pantry deadlines now show up
  where the household already looks. *Settings → 🗓️ Calendar* turns the feed on,
  picks the horizon (`ICS_DAYS`, default 30) and keeps recently missed dates
  visible with a ⚠️ prefix (`ICS_PAST_DAYS`, default 7); the same tab shows the
  subscribe URL, a copy button and *Open in Calendar* (`webcal://` on phones).
  Every in-stock row with an expiry date becomes an all-day `VEVENT` with a stable
  UID (`evershelf-inv-<id>@<host>`), location/quantity/brand in the description
  and a `-P1D` reminder alarm. Text is escaped and folded per RFC 5545
  (multi-byte safe, no line over 73 octets), served as
  `text/calendar; charset=utf-8` with `X-Robots-Tag: noindex`.
  - `GET api/index.php?action=calendar_ics&token=…` sits in
    `evershelfPublicActions()` because a calendar client can only GET a URL: it
    authenticates with a dedicated read-only `ICS_TOKEN` (`hash_equals`, never
    logged, 403/404 on mismatch) that Settings mints and *Rotate link* revokes.
  - New `api/lib/calendar_ics.php`, `api/lib/i18n.php` (the server-side
    `evershelfTr()` the feed's own labels use) and `scripts/test-calendar-ics.php`
    (escaping, folding, all-day date maths, feed shape). `scripts/i18n-audit.py`
    now counts `evershelfTr()` keys, so PHP-rendered strings are audited too.
  - No QR code, deliberately: the only QR generator in the repo is the
    third-party `api.qrserver.com` and the subscribe URL is a long-lived
    credential — unlike the short-lived pairing code it must not leave the server.

### Changed
- **The seasonal review tip is translated in all six locales.** The card's tip was
  built from two hardcoded `it`/`en` tables inside `api/lib/seasonal.php` — a
  user-facing string the translation audit could not see — so de/fr/es/zh users read
  English. The card now returns a stable key (`shopping.seasonal_tip_<month>`) that
  `assets/js/app.js` resolves through `t()`, the same "PHP returns a key, the client
  translates it" contract as `hint_key`, and the wording lives in
  `translations/*.json`. The out-of-season block also explains itself now
  (`seasonal_out_note`): the smart list skips out-of-season fresh produce and brings
  it back when its season does.

- **A third CSS layer re-skins the UI without touching the other two.**
  `assets/css/elegant.css` loads after `style.css` and `corporate.css` and only
  re-skins what they already lay out: rounder geometry, a two-layer soft elevation,
  a floating translucent bottom bar, a translucent header, roomier gutters and a
  fluid type scale. It deletes no selector, so removing its single `<link>` restores
  the previous look. It is also where the restyle's dark-mode regressions are
  repaired — the opened-product tints, `.inv-opened-section` and `--primary` used as
  a foreground — which neither existing layer answers.

### Fixed
- **The seasonal review card asked to remove what the smart list kept suggesting.**
  The card flagged any catalogue entry the month marks `off`, while the list also
  exempts the crops that are on the shelf all year: in October the card told you to
  drop the onions the list was still proposing. Both apply
  `seasonalIsAllYearCrop()` now. Two matcher bugs surfaced with it:
  `mel[ae]`, `zucc[ah]` and `rap[ae]` matched as head-noun *prefixes*, so
  "Melanzane", "Zucchine" and "Rapanelli" counted as all-year crops — the summer
  produce the winter list exists to hide was exempt from hiding — and the candidate
  scorer accepted a two-letter tail, so "Zucchero" became "zucca" through the alias
  "zucche" + "ro". Short stems are matched as whole head nouns now, and only
  single-letter inflections ("avocados" → "avocado") pass the tail guard.
  `scripts/test-seasonal-match.php` grows from 57 to 86 assertions, including a
  card-vs-list consistency lock and a check that every key PHP can emit exists in
  all six locales (the i18n audit only sees keys used in JS).

- **The ⚙️ Config tab was clipped on every phone portrait.** The bottom bar
  reserved a hard `min-width: 56px` per tab (62px for the Gemini FAB), so seven
  tabs needed ~398px of row — 462px with the German labels ("Einstellungen") —
  against the 338px a 360px phone offers. The row overflowed, and because `body
  { overflow-x: hidden }` there was nothing to scroll: the last tabs (Storico and
  Config) simply were not there. The tabs now share the row
  (`flex: 1 1 auto; min-width: 0`, so widths stay as they were where there is
  room), the icons, logo and FAB scale with the viewport, and below 540px the
  labels give way to icon-only tabs that keep their accessible name through
  `aria-label`/`data-i18n-aria` (existing `nav.*` keys — no new translations).
  That also disambiguates the two tabs that both showed 📋: *Storico* is 🕘 now.

## [1.8.10] - 2026-10-04

Second half of the 2026-10-04 audit: what a `docker build` bakes into the image,
how the backup copies the database, and what the API lets out — the CSRF gate on
write actions and the error reports that used to leave carrying whatever a client
sent.

### Security
- **`docker build` no longer bakes the machine it runs on into the image.**
  `.dockerignore` listed five `data/` files, so `COPY .` dragged in the whole
  runtime directory: the pantry database, the API/pairing tokens, every cache and
  `data/mealie/docker-compose.yml`, which holds the Mealie admin password in
  clear text. Everything mutable under `data/` is excluded now, while the three
  tracked files the app ships (`data/.htaccess`, `data/.gitkeep`,
  `data/seasonal_produce_it.json`) are re-included explicitly. Two of the old
  rules also did nothing for nested files — a pattern without a slash in
  `.dockerignore` only matches at the context root — so `*.apk` and
  `__pycache__/` left a 6.7 MB local kiosk APK and a `.pyc` in the image.
- **An image built on a developer machine carried twelve files git does not
  track.** Diffing the built image against `git ls-files` one path at a time
  found the Health Bridge **signing keystore**
  (`evershelf-health-bridge/evershelf.jks`), a local certificate (`ca.crt`),
  1.5 MB of rotated `data/cron.log.*`, three `logs/evershelf_*.log` application
  logs, two hand-made logo copies (`assets/img/logo/logo*_backup.png`), a stray
  `scripts/__pycache__/*.pyc` and whatever APK the developer last built
  (`releases/evershelf-kiosk.apk`, 6.7 MB). None of them is in the repository and
  none is needed to run, and all are excluded now: the only untracked files left
  in the image are the `.env` the Dockerfile creates from `.env.example` and the
  four MiniLM weights it downloads itself. The context drops from ~114 MB to
  ~30 MB; `releases/` stays, because `getKioskUpdate()` answers from
  `releases/kiosk-version.json` (`*.apk` is ignored on purpose — bind-mount your
  own builds).
- **`Dockerfile`, `.dockerignore`, `docs/`, `.github/` and every `*.md` left the
  build context.** Nothing reads them at runtime, and `COPY .` was publishing
  them from the web root, where `/Dockerfile` and `/CHANGELOG.md` are free
  reconnaissance for a scanner. `docker/` stays: the Dockerfile copies
  `php-evershelf.ini` and `apache-evershelf.conf` out of it, and the Mealie and
  Avahi code reads `docker/docker-compose.mealie.yml` and
  `docker/avahi-evershelf.xml` from disk — but the directory is denied in
  `.htaccess` now, so those two files are no longer served from the web root on
  a bare install either. Ignoring `docker/` outright (the first version of this)
  makes **every** build fail at step 5/16 — `COPY docker/php-evershelf.ini` →
  `COPY failed: file not found in build context or excluded by .dockerignore`.

### Fixed
- **A published image and a locally built one are no longer two different
  artefacts under the same tag.** The MiniLM weights behind offline category
  classification are gitignored, so GHCR images shipped without them and quietly
  fell back to the jsdelivr CDN, while an image built on a developer machine
  carried them. The Dockerfile now fetches the model in a layer of its own, and
  `scripts/install-transformers-model.sh` retries transient CDN failures that
  would otherwise take an image build down with them.
- **`backup.sh` could lose the newest transactions.** The database runs in WAL
  mode and the script snapshotted it with `cp`, so everything still sitting in
  `evershelf.db-wal` stayed out of the backup (and a write in flight could make
  the copy inconsistent). It now uses SQLite's online backup — `sqlite3 .backup`,
  or a PHP `PRAGMA wal_checkpoint(FULL)` when the CLI is missing — writes to a
  `.part` file and renames it, so an interrupted run cannot leave a half-written
  backup behind for the retention step to keep. The Docker image ships the
  `sqlite3` CLI, so the fast path is always available there.
- **The documented cron had silently done nothing since June.** `INSTALL_DIR`
  was resolved as `dirname "$0"/..`, the *parent* of the script's directory, so
  the documented `0 3 * * * /var/www/html/dispensa/backup.sh` looked for
  `/var/www/html/data/evershelf.db`, found no database and exited 0. That `/..`
  arrived with `d33b0ca` (2026-06-03): before it, the same line used
  `dirname "$0"` and worked. The three `dispensa_*.db` snapshots from
  2026-04-13 are the last ones a cron ever wrote — everything in
  `data/backups/` since then came from the backup button in the UI. The path is
  the script's own directory now, which is right both for a git checkout
  (`<install>/dispensa/backup.sh`) and for the container
  (`/var/www/html/backup.sh`).

- **The CSRF guard checked 25 actions out of 134.** The list was hand-written and
  the proof it asked for was a header *or* a JSON content type, so two holes
  stayed open. Anything not on the list (`chat_save`, `tts_proxy`,
  `generate_recipe_stream`, the `health_*` writes, …) had no check at all, and
  `Content-Type: application/json` is not proof of anything: a cross-site
  `<form enctype="text/plain">` sends exactly that content type with a body the
  attacker chooses, which is the standard way to reach an endpoint that trusts
  it. Every POST now has to carry `X-EverShelf-Request: 1` — a header a form
  cannot set, and one that a cross-origin `fetch` can only add after a CORS
  preflight the server never grants. The content-type fallback survives only for
  the five actions in `evershelfCsrfExemptPostActions()`, which belong to clients
  with no browser session to forge: the kiosk APK (`report_error`,
  `save_settings`), the public log sink (`client_log`), the Health Bridge
  (`health_ingest`) and the Home Assistant integration (`ha_generate_recipe`).
  Rejection is a `403 csrf_rejected`, logged.

- **One error report could publish the API token on a public tracker.**
  `report_error` is a public action, and `_createOrCommentGithubIssue()` copied the
  client's `location.href`, `user_agent`, message, stack trace and `context` object
  verbatim into an issue. The documented auth accepts `?api_token=…`, and the PWA
  attaches `location.href` to every error, so an error raised while the app was
  opened on (or redirected to) such a URL put the token in the issue body and in
  `data/error_reports.log` — the file that gets copied around by backups. The
  `context` field was also the largest body `post_max_size` allows: the shipped
  `php-evershelf.ini` sets 32 MB, so a single report could write ~32 MB into one
  log line and one issue.
  Reports are now redacted and capped at every sink. `evershelfRedactSecrets()`
  strips `key=value` / `"key": "value"` / `Key: value` pairs whose name looks like a
  credential (`api_token`, `access_token`, `token`, `password`, `secret`,
  `api_key`, `authorization`, `key`, …), `user:pass@` userinfo in URLs, and tokens
  recognised by shape (`ghp_…`, `github_pat_…`, `AIza…`, `GOCSPX-…`, `sk-…`,
  `xox…`); `evershelfReportContextJson()` recurses through the context under a 4 KB
  budget (1 KB per string, 50 keys per level, 5 levels deep) and always emits valid
  JSON — invalid UTF-8 and a non-array `context`, which used to be a `TypeError` on
  the way in, are handled too.
- **Publishing to GitHub is a second, explicit opt-in: `REPORT_ENABLED`.**
  A token in `.env` used to be enough to open issues on a public repository, so a
  shared `.env` could publish whatever the redaction had not anticipated.
  `REPORT_ENABLED=false` is the default and stops both `report_error` and
  `report_bug` before any search or API call (`report_error` answers
  `{"ok":true,"skipped":"reporting_disabled"}`); `data/error_reports.log` is still
  written, redacted, so diagnostics are never lost.
  **Upgrade note:** set `REPORT_ENABLED=true` alongside `GH_ISSUE_TOKEN` to keep
  receiving automatic issues.
  `scripts/test-report-redaction.php` pins this down (the leak, the secret shapes,
  the caps, the false positives, the gates).

### Changed
- **Every first-party client sends the CSRF header, and one of them did not.**
  `assets/js/app.js` already set it whenever a call carried a body; `chat_clear`
  is a POST with no body, so it would have started failing with the new guard —
  the wrapper now adds the header for any non-GET method, and the four log/error
  calls that bypass the wrapper send it explicitly. `mcp-server` and the kiosk
  (`ErrorReporter`, `SettingsActivity`, `SetupActivity`) send it too; installed
  kiosk APKs predate this release and keep working through the exempt list, but a
  script or a fork that posts an AI or inventory action now needs the header.
  `scripts/test-csrf-guard.php` pins the rule down: the rejected content types,
  the kept exemptions, that the guard runs before the early-exit branches, and
  that every exempt action exists.
- Web app manifest: the icon set is rebuilt at the sizes a browser actually asks
  for — 192×192 and 512×512 `any` plus a dedicated 192/512 **maskable** pair, so
  Android no longer crops the transparent logo to the launcher shape. The two
  odd-sized entries (557×507, 74×64) are gone. `screenshots` stays absent: the
  available captures are landscape-only, so Chromium's rich install UI remains
  off instead of shipping a wrong `form_factor`.
- `docs/wiki/Configuration.md` no longer tells people to back the database up with
  a raw `cp` (and why that is wrong); `docs/wiki/Installation.md` documents the
  WAL-safe behaviour and `BACKUP_RETENTION_DAYS`.

### Added
- `scripts/i18n-audit.py` check `[4]`: it fails when a manifest icon is missing,
  declares a MIME type that does not match its file, declares `sizes` that differ
  from the real PNG pixels (read from the IHDR chunk, no Pillow needed), or
  leaves 192/512 uncovered for both the `any` and the `maskable` purpose.

## [1.8.9] - 2026-10-04

Localisation hotfix: the strings a user reads but that never changed language —
tooltips, input placeholders, `aria-label`s, a few status messages and the web
app manifest.

### Fixed
- **HTML attributes hardcoded in Italian.** 24 `title`/`placeholder`/`aria-label`
  values had no `data-i18n*` override (settings tabs, search/scan/price buttons,
  Bring! and health fields, Google Drive code field, empty-list hints…), so they
  stayed Italian in every locale.
- **Placeholders that were already translated but never connected.** The Home
  Assistant URL / token / media-player / notify-service inputs and the TTS
  extra-fields box now use their existing keys, so a German user sees
  `notify.mobile_app_mein_handy` instead of the Italian example.
- **Nine strings injected by `app.js` had no key at all** and stayed in the
  language they were written in: the backup load/error messages, the Bring!
  rename status and its error tail, the TTS voice/beep statuses and the setup
  wizard's AI Studio link.
- The setup wizard no longer picks between `'configura dopo'` and
  `'configure later'` with a hardcoded `_currentLang === 'it'` ternary.

### Changed
- Web app manifest: the declared icon was `image/svg+xml` for a PNG file with
  `sizes: "any"`; both icons now declare their real pixel size, and the file
  gains `lang` and a language-neutral description.
- 16 new keys added to all six locales; asset/i18n stamp → `20261004g`.

### Added
- `scripts/i18n-audit.py` check `[3]`: it fails when a `title`, `placeholder` or
  `aria-label` has no `data-i18n*` override and is not an explicitly
  language-neutral technical example — the regression gate for the bug above.

## [1.8.8] - 2026-10-04

Security and reliability pass over the whole stack: the API token is no longer
disclosed on request, the shopping-list logic lives in one place, and the gaps
found by the 2026-10-04 audit are closed.

> **Upgrading with `API_TOKEN` set?** Your browser will ask for a one-time
> **pairing code** (printed in the server log) the first time it loads the UI.
> That is the new, intended behaviour — see below.

### Security
- **`app_bootstrap` no longer hands out `API_TOKEN` "because you asked nicely".**
  It used to return the token to any client sending `Sec-Fetch-Site: same-origin`
  — a header every HTTP client can forge. The token is now disclosed only after
  presenting a one-time **pairing code** (8 hex chars, 30 min TTL, burned after 50
  failed attempts):

  ```bash
  grep -i "pairing code" logs/evershelf_*.log | tail -1   # bare metal
  docker logs evershelf 2>&1 | grep -i "pairing code" | tail -1   # Docker
  ```

  `API_BOOTSTRAP_OPEN=true` restores the old behaviour for fully trusted LANs.
- **No authorisation decision is made from client-controlled headers anymore**
  (`Origin`, `Referer`, `Sec-Fetch-Site` are forgeable). The same-origin bypass was
  removed from every action, including the ones that run `docker` or rewrite `.env`.
- `X-Forwarded-For` is honoured only when the peer is listed in `TRUSTED_PROXIES`,
  so rate limiting and the pairing attempt counter cannot be evaded by spoofing it.
- `mealieWriteEnvKeys()` delegates to the validating `.env` writer (key validation,
  CR/LF/NUL stripping, comment preservation) instead of writing raw values.
- `LOCK_EX` on every `file_put_contents()` call-site under `api/`: concurrent
  requests can no longer interleave writes to the same JSON state file.
- `sw.js` is **network-first** and pre-caches the ZBar/Quagga bundles — the old
  cache-first worker could pin a stale `app.js` indefinitely, defeating the
  `Cache-Control: no-cache` headers.
- Secrets and build artifacts untracked and added to `.gitignore`.

### Fixed
- **Startup dead-end on "API token required".** The pairing dialog is a
  `.modal-overlay` (z-index 200) and was rendered *behind* the splash preloader
  (200000) and the network-error overlay (300000). Since `_initApp()` aborts when it
  cannot authenticate, the preloader was never removed: the app hung on the splash
  with the code field invisible underneath. The auth overlays now sit at z-index
  400000 and the splash shows an explicit *"pair this device"* message instead of a
  generic token prompt.
- **The pairing code looked like it was never printed.** `EverLog` wrote the message
  as the snake_case token `api_pairing_code`, so the documented
  `grep "pairing code"` (with a space) matched nothing. The message is now the
  literal words `API pairing code`, the stable key is preserved as `ctx.event`, and
  README/SECURITY document the exact command.
- **Shopping list: items still in abundance stayed on the list.**
  `bringCleanupObsolete()` and `internalShoppingCleanupObsolete()` each carried their
  own copy of the spec/marker/cleanup logic and had drifted apart. They now share
  `evershelfSmartItemsIndex()`, `evershelfShoppingRowStillNeeded()`,
  `evershelfBuildShoppingSpec()` and a single `EVERSHELF_SYNC_MARKERS` constant, and
  the family-stock guard is applied consistently on all four paths. A round-trip lock
  in the regression test asserts that every spec the auto-add can build is recognised
  by the cleanup — the exact property whose violation caused the bug.
- `loadEnv()` now `putenv()`s every key in addition to filling `$_ENV`, so code using
  `getenv()` sees the same values as `env()`.
- Uncaught errors return JSON instead of a truncated HTML page (`Throwable` is caught
  in the router) and the `.env` cache is reloaded after a write.
- `document.write` removed from `index.html` (ZBar loads via `appendChild`).
- Inventory depletion: only true crumbs (≤2 g/ml) count as depleted, so usable
  leftovers are no longer hidden or wiped (1.8.5 regression guard).

### Changed
- Smart-shopping reasons are i18n codes resolved by `_localizeSmartReason()`; the last
  hardcoded Italian strings (screensaver counter, setup-wizard buttons, startup hints)
  are gone.
- Inventory gains an `inventory(expiry_date)` index; the per-family stock query is
  memoised once per request.

### Added
- `scripts/i18n-audit.py` (used-but-missing keys, run in CI) and
  `scripts/i18n-value-audit.py` (values still identical to English).
- `TRUSTED_PROXIES` support in `evershelfClientIp()` and `EVERSHELF_CANONICAL_HOST`
  for the forced-HTTPS redirect.
- Regression tests: `scripts/test-shopping-guards.php` (12 assertions) and
  `scripts/test-internal-shopping-cleanup.php` (16 assertions).

### Maintenance
- CI runs the PHP test suite, `node --check` on every JS/ESM file (including `sw.js`
  and `mcp-server/src/**`) and `shellcheck -S warning`. Pushing the workflow edit
  still needs a `workflow`-scoped token.
- The last hardcoded user-facing string in the auth overlays (the API-token input
  placeholder) is now the `startup.token_prompt_placeholder` key, present in all six
  locales like every other string.
- `data/api_pairing.json` untracked on `main` too: a `git merge` keeps files that only
  one side has, so the runtime state committed by accident in 1.8.7 survived the first
  remediation there. It is runtime state, never shipped.
- `npm audit` findings resolved; Dependabot coverage widened.
- `docs/INDEX-*.md` regenerated and `docs/CODEBASE-MAP.md` line references refreshed.

## [1.8.7] - 2026-09-20

### Added
- **Seasonal shopping (free data)** — Italian produce calendar (SeasonalItaly MIT) suggests in-season fruit/veg to add and flags out-of-season items already on the list so you can remove them in one tap.
- **Dashboard: unused stock** — top 3 pantry items unused for a while, with actions: recipe, use, edit, or discard. **Opened / partial packs are ranked first**; sealed staples (salt, sugar…) are deprioritized.

## [1.8.6] - 2026-09-20

### Added
- **Shelf-life learning from your choices** — recovers past *Buttato* reasons and labelled expiry packs (live inventory + local DB backups). On the next add, estimates use your median shelf life and shorten by 1 day per prior expired/spoiled throw (shown as −Nd). *Estendi* and future waste/finish also feed the model. Wrong-location waste can suggest the preferred storage place.

### Fixed
- Recipe ingredient rows open the Use panel more reliably (larger hit target).

## [1.8.5] - 2026-09-18

### Fixed
- **Products vanishing from inventory** — depletion threshold for “trace / finished” was 20 g/ml, so usable leftovers (and false “è finito?” banners) could hide or wipe stock. Now only true crumbs (≤2 g/ml) are treated as depleted. Vanished-product banners put **restore** before **finished** so a hasty tap does not write off a full pack (as happened with orange honey).
- **Inventory long-press removed** — swipe left already opens Use/Discard, swipe right opens Edit; the extra hold-to-open on inventory rows is gone (scroll stays free).

### Changed
- **Recipe ingredients → Use** — tapping a pantry-linked ingredient name in a chat/generated recipe opens the Use panel directly.

## [1.8.4] - 2026-09-16

### Fixed
- **Chat → Recipes wrong ingredients** — pantry matching no longer swaps foods on weak cues (e.g. *Riso Carnaroli* → any *Riso…*, *Noci* → bread with walnuts, *Asiago* → unrelated rows). Distinctive tokens win; short substring hits on long names are rejected. `chat_to_recipe` now receives the pantry name list and must copy ingredient names from the chat text.

## [1.8.3] - 2026-09-12

### Fixed
- **List scroll vs open** — inventory / product / alert rows no longer open on a light tap while scrolling. On touch, hold ~0.9s to open; short tap + drag scrolls. Also stopped capturing the pointer until a horizontal swipe is confirmed (that was blocking scroll).

## [1.8.2] - 2026-09-10

### Changed
- **Comprato** — suppresses auto-re-add until that product is **finished again** (not just until next month). Cleared automatically when you mark it finished / it goes to the shopping list as depleted.
- **Rimuovi** — still only suppresses for the rest of the calendar month.

## [1.8.1] - 2026-09-10

### Changed
- **Shopping Comprato / Rimuovi** — auto-re-add is suppressed only for the **calendar month** of the operation (not 15 days). On the 1st of the next month those items can be suggested again.

## [1.8.0] - 2026-09-10

### Fixed
- **Finished banners that kept returning** — products already finished (honey crumbs, ghost milk, aligned basil traces) are reconciled/dismissed server-side; ✕ on “è finito?” now confirms finished instead of only hiding until refresh.
- **Banner jumps to first alert** — refreshing the dashboard / chart no longer resets the alert carousel; it stays on the banner you were viewing.

### Changed
- Finished detection uses the **ledger gap vs stock**, so matching crumbs (e.g. 0.1 conf = 0.1 conf) are cleared silently without asking again.

## [1.7.99] - 2026-09-10

### Fixed
- **“è finito?” after Finito** — confirming finished is stored server-side so the banner does not come back (e.g. eggs) until you restock.

### Changed
- **All banner buttons shortened** in every language (Finito / Buttato / Correggi / Giusta / Lascia / Spiega / …).

## [1.7.98] - 2026-09-10

### Fixed
- **Finished banner after “Finito”** — undoing a use was double-counted in the ledger (ghost fractions like **0.233 conf** milk), so “è finito?” came back even after you finished the pack. Repair migration + undo no longer inserts a second ledger row; **Finito** also clears residual ledger gaps.

## [1.7.97] - 2026-09-10

### Fixed
- **“Product gone” crumbs** — leftover traces (e.g. 2.5 g honey) no longer show as inventory anomalies without a way to clear them; they go to the finished banner with **Finito / Buttato**.
- **Anomaly dismiss** — “Giusta” now persists (`a_*_missing|phantom` keys) and aligns the ledger to the shown quantity instead of only hiding the banner.
- **Finished reconciliation** — clearing stock no longer double-counts crumbs in the ledger.

### Changed
- Anomaly banner actions shortened and include **Finito / Buttato** (all languages).

## [1.7.96] - 2026-09-10

### Fixed
- **Extend expiry on opened items** — “Estendi” now always adds **7 days** and is trusted over opened-shelf-life estimates (`expiry_user_set`), so the banner does not keep coming back (e.g. breadcrumbs / oregano).
- **Missing Estendi** — shown on all checkable expired/opened banners (not only produce).

### Changed
- Shorter banner actions in all languages: **Finito / Buttato / Modifica / Estendi / Sottovuoto** (and equivalents).

## [1.7.95] - 2026-09-10

### Fixed
- **Anti-waste shopping qty** — perishables (zucchini, tomatoes, carrots, …) are capped to finishable shelf life, not a month of produce. Fresh tomatoes no longer inherit the 730-day “passata” shelf life.
- **Shopping horizon** — default is **30 days** (or your inferred shopping-trip cycle when known), not “days until month end”.
- **Piece-bag floors** — historical bag size (e.g. 9 carrots) no longer overrides the edible-window use rate.
- **Depleted food on the list** — medium/low depleted items now auto-sync to the shopping list (unless you removed/blocked them); existing rows refresh qty specs on each cron.
- **Ghost anomaly on finished crumbs** — “less stock than expected” no longer appears for trace leftovers already treated as depleted.

### Changed
- Shopping plan UI: Auto = 30d / inferred cycle (was “month end”); max 30 days.

## [1.7.94] - 2026-08-23

### Fixed
- **App startup blocked on kiosk** — Merge corruption in `app.js` swallowed the `_i18nFallback` declaration; `loadTranslations()` / `t()` threw on boot and the preloader never progressed.
- **Spesa scan after spend prompt** — Closing the spend modal via overlay or ✕ now clears the scan gate and resumes the scanner (centralized in `closeModal()`).

## [1.7.93] - 2026-08-23

### Added
- **Spesa session list** — Under “Identify with AI”, editable list of products added this trip (name, brand, qty); manual barcode/name/AI tabs hidden in shopping mode.
- **Inventory swipe: Use / Discard chooser** — swipe left opens a picker: **Use** or **Discard** (quantity + waste reason). Tap still opens Use; swipe right remains Edit.

### Changed
- **Spesa spend prompt** — Barcode scan starts only after you save or skip the optional spend amount.
- **Spesa AI** — Manual “Identify with AI” button only; removed automatic AI timers/countdowns.
- **Spesa auto-add** — Idle countdown on the add form shortened from 30s to 27s.
- **Inventory opened section** — Opened products listed first (after favourites) with green / orange / red row backgrounds by expiry.

### Fixed
- **Shopping-list feedback in spesa mode** — “Removed from list” / “Take more…” toasts use server flags and updated IT copy.
- **Spesa scan flow** — No family-sibling interrupt mid-scan; duplicate-add cancel keeps you on the form; barcode cache cleared on mismatch retry.

## [1.7.92] - 2026-08-19

### Fixed
- **i18n hardcoded strings** — Removed Italian fallbacks and hardcoded UI text from `app.js`, `index.html`, and API error responses; all user-facing strings now go through translation keys in **it, en, de, fr, es, zh** (1853 keys each).

## [1.7.91] - 2026-08-19

### Fixed
- **Depleted products stuck in Opened alerts** — Trace leftovers (e.g. 19 g butter) were hidden from the inventory list but still shown under Opened; tapping them opened “already exhausted” and **Mark finished** did nothing because `confirmFinished` only deleted zero-qty rows. Crumbs are now cleared (logged as out + removed), Opened filters them out, and alert detail loads depleted stock for Use / Finish actions.

## [1.7.90] - 2026-08-19

### Fixed
- **`spend_stats` PHP crash** — `getSpendStats()` had an unused `PDO $db` parameter but the router called it with no args, causing `ArgumentCountError` on every dashboard load (auto-reports #231–#233).

## [1.7.89] - 2026-08-19

### Added
- **Shopping mode from header** — 🛒 button in the top bar (next to the camera) toggles continuous scan without long-press.
- **AI fallback in shopping mode** — if the barcode is not read within 5 s, a cancellable 5 s countdown runs before Gemini Vision identification starts.
- **Add-form exit confirmation** — leaving the add page with an unsaved product prompts to save or discard (6 languages).
- **Optional spend tracking** — at the end of a shopping session you can record the total amount; monthly panel on the dashboard with trend.
- **Gemini in bottom nav** — elevated center tab right after Recipes; removed from the header to free space on mobile.

### Fixed
- **Shopping modal vs AI** — the AI countdown no longer starts over the “How much did you spend?” prompt; Cancel truly blocks AI; at most one fallback per scanned product.
- **False “Unusual package” banners** — piece goods (`pz`) with label weight (400 g bread, 500 g nectarines) no longer trigger package alerts.
- **Milk quantity banners** — corrected ml vs pack interpretation in review thresholds.

### Changed
- Simplified header camera tooltip (`scan.hint_short`); shopping/AI/add-form labels in **it, en, de, fr, es, zh**.
- PWA cache bump (`evershelf-v9`).

## [1.7.88] - 2026-08-10

### Changed
- **Project website** — all docs/README/wiki/OpenAPI links now point to **[https://evershelf.site/](https://evershelf.site/)** (replacing `evershelfproject.dadaloop.it`).

## [1.7.87] - 2026-08-09

### Changed
- **Shopping list uses anti-waste qty** — list row badges recompute from the edible shelf-life horizon; specs synced/added include `Compra:`/`Almeno:` with that capped qty; ♻️ hint on perishable rows; remaining need after partial buy also respects the edible window.
- **README** — Shopping List section documents anti-waste purchase qty and partial restock behaviour.

## [1.7.86] - 2026-08-09

### Added
- **Anti-waste purchase horizon** — for perishables (produce, fresh foods with shelf life ≤21 days), shopping suggested qty uses `min(plan_days, shelf_life)` so items like zucchini are sized to what you can finish before they spoil, not the full month. Softens “bag/bunch” floors when capped; shows reason “Anti-waste: finishable in ~N days”.

## [1.7.85] - 2026-08-08

### Fixed
- **Shopping list stays after partial restock** — buying less than the planned need (e.g. 3 L of milk when ~12 L are needed) no longer removes the item; the list keeps the generic name and updates remaining qty (“still need N”).
- **Generic shopping titles** — new list rows store `shopping_name` (e.g. Latte) instead of brand-specific product titles.
- **Conf/ml quantity math** — package stock is compared in package counts (not ml×packs), so suggestions like “Compra: 9 conf” are realistic again.

## [1.7.84] - 2026-08-08

### Fixed
- **Spesa / continuous scan confirmation** — scanning in continuous mode again opens the add form (quantity, expiry, location) before saving; the camera reopens after confirm. Silent auto-add without the popup was reverted.

## [1.7.83] - 2026-08-05

### Added
- **Simplified Chinese (`zh`)** — full UI translation by [@duetonever](https://github.com/duetonever) (#228, refs #93), language picker entry `简体中文`, and `zh-CN` date/number formatting. Recipe AI status strings and language rule also support Chinese (plus French/Spanish recipe status locales).

## [1.7.82] - 2026-08-05

### Security
- **mcp-server npm overrides** — bump transitive `hono` (≥4.12.34), `ip-address` (≥10.4.0), and `fast-uri` (≥3.1.5) to clear Dependabot alerts (CORS ReDoS, SSRF/trust-boundary issues in address parsing, host confusion).

## [1.7.81] - 2026-08-05

### Fixed
- **Recipes with finished salad / crumbs** — leftovers below the cookable threshold (e.g. **0.2 pz** Iceberg) were still sent to the AI and rounded up to ¼. New recipes now exclude unfinished crumbs; piece rounding no longer invents a usable quarter from scraps.

## [1.7.80] - 2026-07-31

### Fixed
- **Recipe ingredients vs steps (butter)** — residual stock below the inventory “crumb” threshold (e.g. 19 g butter ≤ 20 g) was hidden from `inventory_list`, so the client unlinked the ingredient while steps still said to use it. Recipe enrich now includes residual stock, re-links orphans, and no longer drops pantry links for crumbs; step scrub only allows pantry-linked ingredient words; `chat_to_recipe` runs full post-process.

### Changed
- **Share recipe** — replaced the large “Condividi ricetta” button with a discreet share icon at the top-right of the recipe title.

## [1.7.79] - 2026-07-30

### Fixed
- **Shopping list qty “1 apple / 1 orange”** — near month-end the plan horizon collapsed to 1–2 days, so piece goods (fruit/veg) always suggested **1 pz**. Default horizon is now **at least 7 days**; empty/on-list piece items use **average past purchase size** (or a small pack from use frequency). Piece suggestions can go up to ~12 per line (packages still capped at 3).

## [1.7.78] - 2026-07-30

### Added
- **AI master switch + exclusive providers** (#205) — Settings → API Keys: enable/disable all AI; choose exactly one of **Gemini**, **OpenAI (cloud)**, or **Llama** (local/remote OpenAI-compatible: Ollama, llama.cpp, vLLM…). Connection test button reports latency in ms.
- Documented in the main **README** (feature table + `.env` examples) and Installation wiki.

### Changed
- OpenAI cloud vs Llama are separate providers (`OPENAI_*` vs `LLAMA_*`); legacy `AI_PROVIDER=ollama|vllm|local` maps to `llama`.

## [1.7.77] - 2026-07-30

### Added
- **Pre-built Docker images on GHCR** (#209) — `ghcr.io/dadaloop82/evershelf` (`latest`, semver tags, multi-arch amd64/arm64). `docker compose pull` works; local `build:` still available for contributors.
- **OpenAI-compatible AI routing** (#205, MVP) — first cut of `/v1/chat/completions` routing for non-Gemini backends (expanded in **1.7.78** with exclusive Gemini / OpenAI / Llama + master switch + latency test).

## [1.7.76] - 2026-07-30

### Added
- **Custom storage locations** (#79) — Settings → Generali: add places beyond Pantry/Fridge/Freezer/Other (stored as `CUSTOM_LOCATIONS` in `.env`). They appear in inventory tabs and location pickers.
- **Shopping/restocking templates** (#101) — Shopping page → Templates: create named product bundles and apply to the shopping list or inventory in one tap.

### Fixed
- **Recipe steps vs ingredients** — cooking steps no longer keep mentioning foods (e.g. butter) that were stripped from the ingredients list or never in the pantry; prompts tightened + post-process scrub.

## [1.7.75] - 2026-07-30

### Added
- **Edit from Use / discard** (#215) — pencil on the Use page hero (and discard modal) opens the same inventory edit sheet as elsewhere.
- **Pin favourite products** (#98) — star on each inventory row; favourites stay in a **Favourites** section at the top of the list. State is stored on the product and survives restocks.

## [1.7.74] - 2026-07-30

### Added
- **Weather influence for “A ritmo mio”** — optional setting (off by default) under Recipes. Pick a city via Open-Meteo geocoding (no API key); when Fuel Mode generates a recipe, local conditions (hot/cold/rain…) bias dish style. Preview in settings; badge on the recipe result. Attribution: Open-Meteo (CC BY 4.0). Documented in README under Health Bridge & Fuel Mode.

## [1.7.73] - 2026-07-30

### Added
- **Continuous barcode scanning** (#217) — tap **Continuous** on the scan page (or long-press the header camera). Each barcode is added automatically with smart location/expiry defaults, then the camera reopens for the next item. Tap **Done** to exit. Unknown products still open the full add form.

### Fixed
- **Same product, different best-before dates** (#214) — sealed stock rows now merge only when location **and** expiry date match. Two packs of the same barcode with different BB dates stay as separate inventory rows (also applies to multi-batch add and CSV import).

## [1.7.72] - 2026-07-30

### Added
- **CSV inventory import** — 📥 button next to Export on the inventory list (and in Settings). Upload a CSV matching the export schema, review validation + preview, then confirm twice before writing. Unknown columns / invalid rows are rejected; importable rows upsert products and add stock. Fully localized (en/it/de/fr/es).

## [1.7.71] - 2026-07-29

### Added
- **Share recipe** — from the recipe view, share ingredients (scaled to persons) and steps via the system share sheet, WhatsApp, or clipboard.

## [1.7.70] - 2026-07-29

### Fixed
- **Recipe “Use ALL”** — now uses the same checkbox + slide-to-confirm safety as the main Use page (previously only a one-tap danger button).

## [1.7.69] - 2026-07-28

### Fixed
- **HA recipes → Ricette archive** — `ha_generate_recipe` now saves the generated recipe into the EverShelf Ricette tab (same upsert as the app: one recipe per meal per day). Pass `save: false` to skip.

## [1.7.68] - 2026-07-28

### Added
- **`ha_generate_recipe` API** — Home Assistant (and other clients) can generate a full pantry recipe with the same options as the app (`meal`, `persons`, `options` / fuel·veloce·scadenze·…, `meal_plan_type`). Returns structured JSON with `title`, `main_ingredients`, `summary`, plus the full `recipe` object. Pair with HA integration ≥ 1.3.0 (`evershelf.generate_recipe` + event `evershelf_recipe_generated`).

## [1.7.67] - 2026-07-28

### Fixed
- **Smart shopping false “Presto/Urgente”** — depleted product variants (e.g. “Uova”) no longer stay Urgent when another product in the same shopping family still has stock (e.g. 9 “uova medie”). Family coverage now always applies; the 30-day “recently exhausted” exception was wrongly keeping buy suggestions.
- **Shopping list cleanup** — internal list removes stale ⚡/🟠 auto-rows when the family is stocked again (Uova/Cipolla/Burro no longer linger as Urgente).
- **Use ALL / Throw ALL / recipe deplete** — same checkbox + slide-to-confirm safety on banner finish-all, discard-all, throw-all, throw/use quantities that wipe a location, and recipe “use all”.

## [1.7.66] - 2026-07-27

### Fixed
- **“Use ALL / Finished” mis-taps** — a single inventory row used to skip confirmation entirely (likely how a full pack could be wiped in one tap). Now always requires an acknowledge checkbox + slide-to-confirm, with a large warning that ALL stock will be removed.

## [1.7.65] - 2026-07-26

### Changed
- **Fuel Mode meal budget** — subtracts today’s silent intake (cooked recipes + pantry uses) and redistributes remaining kcal/protein across meals still ahead, so “at my pace” recipes don’t overload the day.

## [1.7.64] - 2026-07-26

### Added
- **i18n completeness** — Health / Fuel Mode / Mealie / shopping keys filled for DE, FR, ES; English is now the universal `t()` fallback (never Italian for other locales).

### Security
- **mcp-server** — `fast-uri` → 3.1.4; `@hono/node-server` → 2.0.12 (overrides); Node engine ≥20. Clears Dependabot / Trivy alerts on the lockfile.

### Fixed
- **Scanner OOM (#216)** — cap barcode enhance canvas size and catch `getImageData` RangeError on high-res Android cameras.
- **Gemini 2.5 for new keys (#212)** — drop `gemini-2.5-flash` from the fallback chain (blocked for new Google AI users); prefer 3.5 / 3.1-lite.
- **SQLite busy auto-reports (#218)** — client retries `database_busy` (503) briefly and no longer opens GitHub issues for that expected case.
- **Hardcoded Italian UI** — shopping-mode toasts, uncategorized label, “from recipe” specs, Fuel badge fallbacks now use i18n keys.

### Changed
- **README** — Health Bridge & Fuel Mode featured as the primary NEW highlight (Home Assistant remains documented below).

## [1.7.63] - 2026-07-26

### Added
- **Health Bridge keep-alive** — persistent notification + battery-optimization exemption so OEMs don’t kill background sync (APK 1.0.3+).
- **Fuel Mode as bio-driven generation** — “at my pace” builds the meal from profile goal + today’s activity + pantry; auto-enabled when Health is on; generate button switches to Fuel-mode wording.
- **Silent intake from EverShelf only** — recipe cook (once per recipe/day) and pantry “use” (estimated kcal); no manual meal diary.

### Changed
- Removed the manual “I ate this” meal-diary action (no extra food diary).

## [1.7.62] - 2026-07-26

### Added
- **Settings → Health** — dedicated tab for personal/biological data with master enable switch (greys out when off).
- **Health Bridge Android app** — multilingual setup wizard; QR pairing passes EverShelf URL + token; Health Connect sync. APK built/released by GitHub Actions (`health-bridge-latest`).

## [1.7.61] - 2026-07-26

### Added
- **Fuel Mode (“at my pace”)** — Optional recipe option that builds a deterministic meal calorie/protein budget from a health profile + today’s activity (manual entry initially; Health Bridge later). APIs: `health_status`, `health_ingest`, `health_profile_save`, `health_bridge_token_create`, `health_unlink`. Recipe result shows target vs estimated nutrition match.

### Fixed
- **Recipe expiry “expires today”** — Calendar-day `days_left` (no `julianday('now')` time skew); prompts include real expiry dates and prefer the soonest lot; freezer not treated as urgent.

## [1.7.60] - 2026-07-25

### Fixed
- **Critical Gemini cost leak (`classify_category`)** — Smart shopping / Bring sync / family matching called `computeShoppingName()` on every cron cycle (~every 5 minutes). That path hit Gemini for multi-word names, with a fat Bring catalog prompt (~990 input tokens/call), no `thinkingBudget: 0`, and a cache that often failed to persist (empty/truncated answers). Result: **1,000+ classify calls/month** with almost no user action. Settings also under-reported cost using old 2.5 Flash rates while traffic used `gemini-3.5-flash`.
- **What we changed to stop it**
  - Gemini shopping-name classification is **off by default** and **hard-blocked in CLI/cron**; AI runs only when saving a product (HTTP) if a name is still needed.
  - Durable cache with file lock, negative-cache TTL for misses, purge of truncated junk entries; daily hard cap (default 40).
  - Slim one-word prompt (no 200-item catalog dump); `thinkingBudget: 0` injected for all Gemini payloads; classifiers prefer **`gemini-2.5-flash-lite`**.
  - Usage accounting records thinking tokens; Info tab costs use real per-model rates (including 3.5 Flash).
  - Shelf-life prewarm reduced to 1 item per cron cycle.
- **Shopping urgency noise** — “Urgent” / “Soon” limited to frequent staples with real consumption that are empty or nearly empty; medium/low predictions are no longer auto-added to the list.
- **Eggs / undo restocks** — `[Undone]` / `[Annullato]` transactions no longer count as purchases; high/critical and expired-only stock are not hidden from predictions.

### Added
- **Clear search** — × button on inventory and catalog search bars to wipe the query in one tap.

## [1.7.59] - 2026-07-17

### Fixed
- **Inflated “estimated spend”** — Location moves (`[Spostamento]`) no longer count as consumption. Short-history daily rates use calendar days (min 7), not a 1–2 day activity burst. Hard caps: ≤250 g/day (or 2 L/day), ≤3 packs per suggested line, ≤€25 per priced line. Regression tests: `php scripts/test-shopping-guards.php`.
- **Inventory edit save** — `BEGIN IMMEDIATE` / commit helpers so quantity, package size, and vacuum seal persist (PDO transaction mismatch).
- **Inventory search** — Search query is preserved when opening/editing a product.
- **Package size wipe** — Non-`conf` edits no longer send `package_size: 0` and clear `default_quantity`.
- **Scale button** — Removed duplicate ⚖️ icon on “Read from scale” in the edit modal.
- **Button contrast** — Secondary/default buttons use a tinted surface + stronger border (no white-on-white on cards/modals).

### Added
- **Rename by tapping the title** — In the edit modal, tap the product name to rename inline (no extra name/brand fields). Custom titles lock via `products.name_user_set` so barcode rescans keep them.
- **`api/lib/shopping_guards.php`** — Central guards for consumption stats, suggested qty, and price totals.

## [1.7.58] - 2026-07-16

### Fixed
- **Shopping list without Bring!** — Depleting a product now always targets the EverShelf built-in list (`shoppingAddDepletedProduct`). Bring! credentials are no longer required for auto-add on finish.
- **Hidden list rows** — The 15-day purchase/remove blocklist no longer hides rows already stored in `shopping_list` when `SHOPPING_MODE=internal` (blocklist only gates auto re-add).
- **Finished → buy again** — Finishing a product clears that family's blocklist entry so it can reappear on the list immediately.
- **Family stock** — Auto-add skips a generic name when another product in the same `shopping_name` family still has stock.
- **German “Butter” generic** — Maps to *Burro* / *Butter* (was truncated to “Bur”).

### Changed
- **Shopping architecture** — EverShelf owns the shopping list; Bring! is an optional mirror (`SHOPPING_MODE=bring`). Settings copy and toasts say “shopping list”, not “Bring!”.
- **API** — Inventory use responses include `added_to_shopping` (legacy `added_to_bring` kept as alias).

## [1.7.57] - 2026-07-08

### Added
- **Product detail sheet** — Tap an inventory row for a 2×2 action grid: Use, Used all, Create recipe, Discard; edit in header.
- **Use form idle countdown** — 15 s reverse progress on submit for piece/package units (grams/ml require manual entry).
- **Corporate UI guide** — [`docs/CORPORATE-UI.md`](docs/CORPORATE-UI.md) documents tokens, button intents, and list interaction patterns.

### Changed
- **Inventory nav label** — “List” / “Lista” (and equivalents) instead of “Pantry” / “Dispensa” for the list page; location tab names unchanged.
- **Swipe left** — Opens Use quantity screen instead of consuming one unit immediately.
- **Grams/ml defaults** — Empty quantity field on Use page; user must enter amount (no auto-fill or idle submit).

## [1.7.56] - 2026-07-07

### Added
- **Inventory swipe guide** — Banner above the list explains swipe left = use one, swipe right = edit (all location tabs).
- **Per-row swipe cues** — Edge labels (← Use / Edit →) and centered hint on each inventory row.

### Changed
- **Inventory swipe input** — Pointer events (mouse drag + touch); row padding adjusted for edge labels.

## [1.7.55] - 2026-07-06

### Added
- **Shopping list urgency API** — `shopping_list` and `ha_shopping_items` return `urgency`, `urgent`, `urgency_label`, `urgency_color` per row (from smart shopping cache + spec markers).
- **Urgent row styling** — Critical/high items get colored left border, tinted background, and badge on the shopping page.
- **Internal list auto-add** — Cron runs `internalShoppingAutoAddCritical()` when `SHOPPING_MODE=internal` (Bring code unchanged).
- **15-day shopping blocklist** — `SHOPPING_REMOVED_BLOCK_DAYS` (default 15): `shopping_remove` and *Bought* suppress cron/UI re-add; `shopping_add` respects blocklist.

### Changed
- **Bring disabled by default** — `SHOPPING_MODE=internal`; setup wizard skips Bring credentials; UI labels are mode-aware (not Bring-branded).
- **Internal list deduplication** — Merges generic + specific duplicates (e.g. *Milk* + *Fresh mozzarella*) on load and after add.
- **Pantry search** — Relevance scoring (name/shopping_name first); no longer matches all dairy when searching *milk*/*latte*; flat sorted results; searches all locations when filtering.
- **Shopping footer count** — Piece count uses packages, not raw grams/ml (fixes inflated totals).

### Fixed
- **Internal list after purchase** — `shoppingRemoveProductFromList()` updates `shopping_list` when not using Bring; shopping flow always calls client remove in internal mode.
- **Expired alerts** — Depleted stock excluded from expired section; 0.9 *pz* displays as 1, not "0 pcs".

## [1.7.54] - 2026-07-04

### Added
- **Add form idle auto-submit** — After 30 s inactivity the Add button submits with reverse progress bar; any interaction resets the timer.
- **Barcode scan persistent cache** — Resolved barcodes stored in `localStorage` (up to 500 entries) for instant repeat scans.
- **Scan engine preload** — ZBar WASM and Tesseract preloaded at app start, in shopping mode, and when opening Scan.

### Changed
- **Faster barcode scan** — Native `BarcodeDetector` first (ZBar after 700 ms); delayed OCR when native is active; optimistic lookup (form immediately, `product_save` in background); shopping fast path (no overlay).
- **Shopping mode** — Identification shown in status bar instead of full-screen spinner.

### Fixed
- **Inventory edit (swipe)** — ID comparison uses `==` instead of `===` (SQLite returns strings): edit screen opens again.
- **Smart shopping cron** — Added `CRON_LOG_PATH` in `constants.php`; 5-minute job no longer fatals.

## [1.7.53] - 2026-07-04

### Added
- **Mealie integration** — Self-hosted recipe book linked to EverShelf: pantry-based recipe pick, offline cache, automatic sync.
- **Mealie guided setup** — Settings → Recipes: Docker discovery, install via `docker compose`, URL/token configuration with UI progress.
- **Configurable recipe engine** — `RECIPE_SOURCE`: Auto (Mealie → Gemini), Mealie only, Gemini only.
- **Recipes → shopping list** — `RECIPE_SHOPPING_MODE`: off / manual confirm / auto-add missing ingredients to Bring! or internal list.
- **Recipe settings reorganized** — Tabbed layout: Engine / Mealie / Shopping / Preferences; collapsible Mealie advanced options.

### Changed
- **Mealie setup API** — Same-origin access for discovery/install/configure (LAN browser users without manual API token copy).
- **Shopping list swipe** — Thresholds and gestures fixed; *Bought · at home* removes from list only (no scan).
- **Inventory tap** — Tap opens Use screen with quantity; swipe left/right for quick use and edit.
- **PWA service worker** — Dynamic base path (`/dispensa/` etc.); SW disabled on LAN IPs with self-signed certs.

### Fixed
- **Mealie configure** — API token obtained automatically with default credentials when the instance is already running.
- **Compose write** — `docker-compose.yml` written under `data/mealie/` (writable by www-data).

## [1.7.52] - 2026-07-04

### Added
- **Inventory swipe (#80)** — Swipe left: quick use 1 unit (with undo toast); swipe right: edit item.
- **Price sparklines (#81)** — Mini SVG trend in shopping price column (last 5 cache values).
- **Mealie integration** — `mealie_list`, `mealie_import`, `mealie_status` API + MCP tools (requires `MEALIE_URL` + `MEALIE_API_TOKEN`).
- **Mealie offline cache** — sync recipes to `data/mealie_cache.json`; `RECIPE_SOURCE` (gemini/mealie/auto) and `MEALIE_OFFLINE` (online/offline/auto) in settings.
- **MCP HTTP transport** — Remote Streamable HTTP server (`npm run start:http` in `mcp-server/`).

### Fixed
- Shopping list section: «Cipolla Dorata» no longer classified as fish (`orata` regex false positive); uses smart category when available.

## [1.7.51] - 2026-07-04

### Added
- **MCP server (beta)** — `mcp-server/` companion for Claude Desktop, Cursor, HA LLM: inventory, expiry, shopping, recipes (`docs/wiki/MCP.md`).
- **PWA service worker** — Basic app-shell cache (`sw.js`) for offline UI load.
- **Offline barcode catalog** — Weekly cron sync from free sources; lookup works offline after sync.

### Fixed
- **#206** — `product_save` INSERT column order matched UPDATE/`productSaveParams()` (new products no longer have shifted DB fields).
- **#207** — Docker image includes PHP `zip` and `intl` extensions (`libzip-dev`, `libicu-dev`).
- **Settings `.env` write** — Fallback to SQLite `env_overrides` when `.env` is not writable; `.env` permissions should be `660` for www-data.

### Changed
- **Shopping list** — Compact mobile UI, swipe actions, plan-days horizon, generic product names, price aligned to suggested qty, scroll preserved on refresh, reduced auto-polling.
- **Piece consumption** — Event-based estimates for `pz` items (e.g. onions); quantities respect conf/pz units in display and pricing.

## [1.7.50] - 2026-07-03

### Changed
- **Compact shopping list (mobile)** — Quantity next to title, urgency via colored border only, clearer prices, **Bought** / **Remove** buttons without emoji, collapsible sections, inline suggestions below the list, footer items/pieces count, swipe right = Bought, pull-to-refresh.
- **Offline supermarket** — List + forecast cache in localStorage; remove and Bought queued offline.
- **Prices** — Estimate aligned to `suggested_qty` (monthly quantity); tap price for AI estimate detail.
- **Bought scan** — Warning when barcode does not match the list item.
- **Idle** — No automatic return to home while on the shopping list (or Bought scan/add flow).

## [1.7.49] - 2026-06-06

### Changed
- **Shopping list refresh** — Prominent quantities (prior-month consumption), **Bought** with barcode scan and pantry add, smaller prices, mobile-first UI, Bring sync every 45s.

## [1.7.48] - 2026-06-06

### Fixed
- **Products that "vanish"** — Fridge/pantry moves logged (`[Move]`); automatic fallback when deducting from wrong location; mandatory confirm when a recipe consumes all stock of an ingredient.

## [1.7.47] - 2026-06-06

### Fixed
- **Recipe parse_error (e.g. piadina)** — Robust JSON parsing for Gemini recipe responses (balanced braces, truncated JSON repair, object-shaped steps). JSON mode enabled for all recipe endpoints.

## [1.7.46] - 2026-06-06

### Added
- **Offline barcode catalog** — Local `barcode_catalog` table synced weekly from free databases (Open Food Facts IT/world/v0, Open Products/Beauty/Pet Facts, UPCitemdb). Works without internet after sync. Configure via `BARCODE_OFFLINE_ENABLED`, `BARCODE_OFFLINE_SYNC_DAYS`, `BARCODE_LOOKUP_TIMEOUT` in `.env`.

### Fixed
- **Barcode 8030582017181 / save errors** — Broader free-source lookup, offline catalog first, save retry on scan, safe duplicate merge (no merge when two different barcodes), `lookup_barcode`/`search_barcode` check local + offline catalog.

## [1.7.45] - 2026-06-06

### Fixed
- **Duplicate catalog products** — Adding stock now auto-merges duplicate catalog entries (same barcode, same name, or similar AI/OFF name) so two packages accumulate on one product instead of splitting inventory across duplicate rows.

## [1.7.44] - 2026-06-06

### Fixed
- **AI product insert (UNIQUE barcode / wrong title)** — Saving an AI-identified product no longer creates duplicate catalog rows or overwrites Open Food Facts data with generic AI guesses. Barcode conflicts merge into the existing product; categories are normalized server-side; the scan flow prefers catalog matches that already have a barcode.

## [1.7.43] - 2026-06-06

### Fixed
- **False expired banner for dry bread products** — Grated bread / breadcrumbs (`pane grattugiato`, panko, etc.) no longer inherit fresh-bread 4-day opened shelf life; they use 90 days in pantry.
- **Banner "Edit" → product not found** — Expired-banner edit now loads inventory first (same as other banner types); `editInventoryItem` retries once from the server and refreshes stale alerts if the row is gone.
- **Stale banner alerts** — Skip zero-quantity inventory rows; server-side `opened` stats are cross-checked against live stock before showing.
- **Opened products still OK** — Banner skips opened items with safety level `ok` or still edible per server stats (they remain visible in the dashboard "Opened" section only).
- **Use page for weight products (g/ml)** — Fraction buttons (¼, ½, ¾, all) with gram/ml amounts; default quantity is half of stock instead of 1 g; duplicate-use guard no longer blocks a different amount silently.
- **“Finished” on already-empty products** — Marking depleted items (e.g. from Recent shortcuts) no longer fails silently; confirms exhausted state, adds to Bring!/shopping, explains product is still in catalog. `use_all` on empty inventory reconciles instead of fake success.

## [1.7.42] - 2026-06-11

### Added
- **Waste reason picker** — Discarding a product prompts for why (expired, spoiled, wrong storage, kept too long, bought too much, forgotten, bad quality, other) in IT/EN/DE/FR/ES.
- **Waste learning** — Reasons are stored per product in `app_settings.waste_learning`; caps smart-shopping suggested quantities, surfaces preferred storage location, and tightens expiry alerts after repeated spoilage.
- **`scripts/github-issue-triage.php`** — Reopens wrongly closed feature backlog items; closes resolved auto-report bugs with English comments.

### Fixed
- **Inflated shopping total** — Price each Bring!/shopping line as **one retail purchase**; convert AI €/kg prices to estimated piece weight (200 g default) instead of multiplying by piece count; cap smart-shopping conf/pz suggestions used for pricing context.
- **SQLite database locked (#201–#202)** — `inventory_use` and `shopping_add` (including Bring mode) wrapped in `dbWithRetry()`.
- **Smart shopping timeout (#203–#204)** — `set_time_limit(120)` on `smartShopping()` / `smartShoppingCached()` for large inventories.
- **Android kiosk CI** — Escaped apostrophes in locale `strings.xml` (de/es/fr/it); fixed Kotlin JSON string escaping in `SetupActivity.finishSetup()`.
- **GitHub triage** — `triage-open-issues.php` no longer bulk-closes enhancement/feature backlog; reopened #98 (pin products) and #125 (cooking voice commands) where not yet implemented.

## [1.7.41] - 2026-06-08

### Fixed
- **Docker/Traefik server unreachable** — PHP 8.2 deprecation notices (`LoggingPDO::prepare`) were emitted as HTML before JSON, breaking `fetch().json()` on the startup health check; API bootstrap now suppresses HTML error output in production.
- **Traefik HTTPS redirect loop** — `.htaccess` skips the HTTPS redirect when `X-Forwarded-Proto: https` is already set (compatible with Traefik `sslheader` middleware); no need to disable `.htaccess` manually.
- **LoggingPDO PHP 8.2** — `#[\ReturnTypeWillChange]` on `prepare()` to eliminate deprecation noise in error logs.

## [1.7.40] - 2026-06-08

### Added
- **Qty unit badges** — Quantity inputs show the active unit (g, ml, conf, pz, …) on use, add, recipe-use, edit and throw modals; scale live label “Inserimento in …”.
- **Recipe shopping suggestions** — AI recipes can list optional missing ingredients with one-tap add to Bring!/shopping list.
- **Recipe frozen badge** — Freezer items flagged in pantry lines and recipe UI; prompt rule for cooking from frozen.
- **Health check `db_writable`** — Startup diagnostic detects non-writable SQLite file (common Docker volume issue).
- **`scripts/triage-open-issues.php`** — Maintenance helper to comment/close GitHub issues via encrypted token.
- **Ops CLI scripts** — `audit-finished-shopping.php`, `backfill-finished-shopping.php`, `sync-shopping-bring.php`, `install-transformers-model.sh` (offline Xenova classifier bootstrap).

### Fixed
- **SQLite database locked** — `PRAGMA busy_timeout` 10s + `dbWithRetry()` on `inventory_update` under cron/PWA contention.
- **Barcode duplicate on save** — `saveProduct` merges or returns 409 instead of HTTP 500 on UNIQUE barcode.
- **EverLog CLI crash** — Safe cast of `REQUEST_METHOD` when null (kiosk/cron).
- **Shopping scan crash** — `currentPage` → `_currentPageId` in `_applySpesaScanUI`.
- **Recipe quantities** — Piece products use 1 pc base; serving caps for onions, leafy greens, minestrone; pantry-only post-processing; conf/g display fixes.
- **Smart shopping purchased block** — Server-side blocklist + shopping mode sync prevents cron from re-adding bought items.

### Changed
- **Docker behind Traefik** — Apache `SetEnvIf X-Forwarded-Proto https HTTPS=on` to avoid redirect loops.

## [1.7.39] - 2026-06-06

### Added
- **`resolve_barcode` API** — Single round-trip: local catalog lookup plus **parallel** external search (Open Food Facts IT/world, UPC Item DB, Open Products Facts, Open Beauty Facts via `curl_multi`). Results are stored in SQLite `barcode_cache` for instant repeat scans.
- **Shopping scan barcode fast path** — In shopping mode, a successful scan opens the **add form directly** (skips the intermediate action page).
- **Session barcode cache** — In-memory cache avoids duplicate API calls when scanning many items in one trip.
- **Manual expiry flag (`expiry_user_set`)** — User-entered expiry dates are kept when changing location, vacuum seal, or moving stock; only auto-estimated dates are recalculated.
- **Family sibling 24h dedup** — After confirming a similar in-stock product is OK, the check prompt is suppressed for the same `shopping_name` family for 24 hours (synced via `family_sibling_confirmed` in app settings).
- **Family sibling stock line** — Shopping scan prompt shows readable stock (e.g. `4 conf (20g each)`); new `family_sibling_check` / `family_sibling_stock` strings in IT/EN/DE/FR/ES.
- **Quick-edit product notes** — Notes field in the inline name/brand editor on the product action page.

### Fixed
- **Kiosk / WebView stability** — Guard `$_SERVER['REQUEST_METHOD']` when null; fix JS temporal-dead-zone crashes (`setProgress`, `enriched` → `enrichedRaw`, `duplicateNames`); lazy-load ZBar WASM so kiosk startup no longer OOM-crashes.
- **Empty barcode SQL error** — Multiple products with `barcode = ''` violated SQLite UNIQUE; empty strings are normalized to `NULL` (migration included).
- **Shopping scan ghost products** — Finished/catalog AI candidates and scan recents no longer show zero-stock items in shopping mode; `family_sibling_suggest` requires live inventory quantity.
- **Insalata di riso misclassification** — Prepared rice salads (e.g. Ponti) map to `pasta` instead of fresh `verdura`; server and client rules aligned.
- **Family sibling prompt readability** — Quantity and question text use high-contrast colours on the dark overlay.
- **Move after use / recipe move** — Respects manually set expiry (`expiry_user_set`); purchased items marked on blocklist after shopping add.

### Changed
- **Barcode lookup** — Replaced sequential API waterfall (up to ~15s) with parallel fetch (~1–2s first hit); 30-minute negative cache for unknown codes.
- **Local barcode search** — Automatically tries EAN-13 / UPC-A variant barcodes.

## [1.7.38] - 2026-06-04

### Fixed
- **Finished products on shopping list** — Depleted items are now added to Bring! under their generic `shopping_name` (e.g. “Affettato”). If the generic is already on the list, the specific variant is appended to the specification instead of being skipped. Confirming a ghost/finished product from the dashboard banner also triggers this flow.
- **Unstable shopping total** — Dashboard, Shopping tab, Home Assistant and screensaver now share one **weekly canonical total** (`PRICE_UPDATE_WEEKS=1`). Totals use **1 package per list item** (no more day-to-day swings from smart-shopping suggested quantities). AI prices are fetched only for items missing from cache; manual 🔄 refresh forces an update.
- **Screensaver price mismatch** — Screensaver waits for the canonical total sync before displaying the amount, matching the other surfaces.

### Changed
- **Shopping list UI** — Generic list entries show the group name with specific finished variants underneath (same pattern as smart shopping suggestions).

## [1.7.37] - 2026-06-04

### Fixed
- **Recipe pantry false positives** — Generated recipes no longer mark ingredients as ✅ in pantry when the product is not in stock or the name does not strictly match an inventory item (score ≥ 80, no generic alias expansion like *formaggio* → any cheese). AI prompt now receives the full in-stock list and explicit rules forbidding invented ingredient names.
- **`renderRecipe` crash** — Restored missing `qtyNum` variable when reopening archived recipes with pantry ingredients (ReferenceError on the "Use ingredient" button).

### Changed
- **`re-enrich-recipe.php`** — Re-applies strict pantry matching before stock hints when fixing archived recipes.

## [1.7.36] - 2026-06-04

### Added
- **Recipe ingredient stock hints** — Pantry ingredients in generated and archived recipes now show a small line under each item: how much you have in stock and how much would remain after use. Quantities are summed across all storage locations.
- **Zero-waste use-all rule** — When the leftover would be less than **5% of the full sealed package** (or **10%** when less than one full unit is left on an opened pack), the recipe quantity is automatically bumped to use everything on hand (♻️ badge + note in all 5 languages).
- **Ghost product detection** — Dashboard anomaly banner now surfaces products that vanished from inventory (ledger says stock should exist but no rows remain), with a restore prompt and quantity input.
- **`inventory_restore_ghost` API** — Restores a vanished product row from the banner without losing transaction history.
- **`product_merge` API** — Merges duplicate product records (inventory, transactions, aliases) into a single canonical product.
- **Maintenance scripts** — `scripts/sync-i18n.py` (5-language key sync), `scripts/re-enrich-recipe.php` (re-apply stock hints to archived recipes), `scripts/merge-duplicate-products.php` (batch duplicate merge).

### Fixed
- **Unified shopping total** — Dashboard, Shopping page and screensaver now share one canonical server-side total (`shopping_total_cache`); background refresh runs during screensaver too.
- **Recipe stream auth** — `generate_recipe_stream` and other direct `fetch()` calls now send the API token consistently, fixing 401 errors during recipe generation.
- **Home Assistant auth compatibility** — HA integration endpoints accept the configured API token without breaking legacy setups.
- **Security hardening** — API bootstrap modularised; scale SSE relay and sensitive routes require auth; env migration script for legacy installs.
- **Dashboard banner i18n** — Fixed raw translation keys (`dashboard.banner_*`) showing in the UI; full sync across IT/EN/DE/FR/ES with cache bust.
- **Ghost banner permanently hidden** — Removed incorrect `fin_*` hide logic that suppressed vanished-product alerts after a false "finished" confirmation.
- **`deleteInventory` / `use_all` dedup** — Inventory deletions now log transactions; duplicate `use_all` within 60 s is deduplicated; `confirmFinished` reconciles ledger mismatches.
- **Duplicate product prevention** — `saveProduct` blocks creating a second product with the same normalised name.
- **Recipe qty normalization** — conf+weight ingredients (e.g. ceci, basilico) now keep recipe amounts in grams/ml instead of copying the inventory conf count; use-all percentage is calculated on the sealed package size, not current stock.

## [1.7.35] - 2026-06-02

### Fixed
- **Barcode scanner accepts invalid codes** — Manual barcode input with an incorrect EAN checksum now blocks the lookup and shows an error (previously showed a warning but proceeded anyway). The native `BarcodeDetector` path now also validates EAN-8/EAN-13/UPC checksum before confirming a scan, consistent with the Quagga fallback which already did this check.
- **Recipe persons +/− buttons stopped working in the generation dialog** — A duplicate `adjustRecipePersons` function added for the post-generation rescaler was overriding the one that updated the persons input in the recipe setup dialog. The rescaler is now named `scaleRecipePersons` to avoid the conflict.

## [1.7.34] - 2026-05-30

### Added
- **AI visual barcode fallback** — When the barcode scanner fails to read a barcode within 5 seconds, EverShelf can now automatically capture a camera frame and send it to Gemini Vision to visually identify the product (name, brand, category). On success the product is saved and the inventory form opens just as if a barcode had been scanned. A new toggle in **Settings → Camera** (`AI visual identification (5s fallback)`) lets users enable or disable this feature at any time. Requires Gemini API key configured. Disabled by default.

## [1.7.33] - 2026-05-29

### Fixed
- **HA sensor `shopping_total` always null** — `haInventorySensor` was reading `shopping_total_cache.json` with a 1-hour TTL (cache populated only by the JS frontend, so it was often empty). Extended TTL to 24 hours and added an inline fallback: when the cache is absent or stale, the sensor now computes the total directly from `shopping_price_cache.json` without any AI calls. Queries `shopping_list` joined to `products` for the canonical `shopping_name`, then looks up both v3 and legacy v0 cache key formats to maximise hit rate. Works in both internal and Bring shopping modes.
- **HA `ha_refresh_prices` using non-existent columns** — `haInventorySensor` and `haRefreshPrices` were querying `quantity`, `unit`, `checked` from `shopping_list` — columns that do not exist in that table (schema: `id, name, raw_name, specification, added_at, sort_order`). Changed to `SELECT name` with `shopping_name` join and default `qty=1 / unit=pz`.


## [1.7.32] - 2026-05-29

### Changed
- **Smarter expiry u2192 shopping list logic** — The "expiring soon" threshold is now 7 days (was 3), giving enough time to plan the next shopping trip. Items expiring soon are only flagged for restocking when the user is a **regular buyer** (`isRegular`) and either stock is low (<50%) or the consumption rate predicts the item will expire before being used. Non-regular products keep the old 3-day safety-net. Expired items are now only added to the shopping list when `isRegular || buyCount >= 2` — products that expired unused without ever being a staple no longer pollute the list; the expiry banner handles them.


## [1.7.31] - 2026-05-29

### Fixed
- **New pack merges into opened pack on add** — `addToInventory` was looking for ANY existing row for the same product+location and adding the new quantity to it. This caused a newly purchased sealed pack to be silently merged with an already-opened pack, collapsing two physically distinct containers into one row and corrupting the `opened_at` timestamp. The fix now searches only for a **sealed** (unopened) row (`opened_at IS NULL`) to merge into. If only opened rows exist, a new sealed row is created instead — keeping the two packs separate and allowing the anomaly model and shelf-life tracker to work correctly.


## [1.7.30] - 2026-05-29

### Fixed
- **False consumption anomaly with multi-row stock** — The anomaly detection banner was evaluating each inventory row in isolation. Products split across multiple rows (e.g. one opened pack with 1 pz + one sealed pack with 6 pz) incorrectly triggered a "consumed faster than expected" warning because only the opened row (1 pz) was compared against the model. The check now aggregates the total quantity across all rows for the same product before deciding to flag an anomaly. If the combined total ≥ expected remaining, the anomaly is suppressed.


## [1.7.29] - 2026-05-29

### Added
- **Buy-cycle consumption prediction** — Products that are never tracked per-use (salt, spices, cleaning supplies, etc.) now use the average time between restocks as a proxy for consumption rate. When a product has ≥ 3 purchase events and no individual `out` events, EverShelf calculates the average buy cycle (`(lastBuy - firstBuy) / (buyCount - 1)`) and estimates how many days of stock remain in the current cycle. The product appears in the smart shopping list with a reason like "Finisce tra ~12gg (ciclo medio 75gg)" before it runs out, rather than only after. These products are now also treated as `isRegular` so all stock-level urgency checks apply correctly.


## [1.7.28] - 2026-05-30

### Fixed
- **Duplicate auto-reported issues** — The GitHub issue reporter was relying solely on the GitHub Search API for deduplication. Because search indexing has a several-minutes lag, rapid error recurrences each created a new issue before the previous one was indexed, producing ~50 duplicate issues. The reporter now uses a local file cache (`data/reported_issue_fps.json`, with `/tmp/` fallback when `data/` is not writable) as the primary deduplication store. A 30-minute per-fingerprint comment throttle is also applied to prevent flooding an existing issue. GitHub Search is used only on first run or after a cache miss. Closes [#134](https://github.com/dadaloop82/EverShelf/issues/134) (and all duplicates #135–#183).

## [1.7.27] - 2026-05-29

### Added
- **HA sensor enrichment** — All HA sensor attributes that list products now include full product details: `location`, `brand`, `category`, `days_remaining`, `opened_at`, `vacuum_sealed`, `default_quantity`, `package_unit`, `product_id`, `inventory_id`. Applies to `expiring_list`, the new `expired_list`, and the new `low_stock_list`.
- **HA `expired_list` attribute** — `sensor.evershelf_overview` now exposes `expired_list` (full details for all expired items, not just a count).
- **HA `low_stock_list` attribute** — New attribute listing all items with quantity ≤ 1 with full product info.
- **HA `sensor=product` endpoint** — New `GET /api/?action=ha_sensor&sensor=product` returns the full inventory with all product details. Optional filters: `&id=N`, `&name=...`, `&location=...`.
- **Inventory edit safety guard** — Confirm dialog when saving a quantity that is unusually large for its unit (e.g. 183 conf), preventing accidental data loss from unit-confusion typos.
- **Bread shelf-life in fridge** — Opened shelf-life rules added for piadina/crescia (2 days), packaged sliced bread/bauletto (4 days), and generic bread (3 days).

### Fixed
- **Recipe AI ingredient substitution** — Added explicit rule to both recipe prompts preventing Gemini from substituting ingredient forms (e.g. fresh tomatoes ↔ passata, fresh milk ↔ UHT ↔ cream, flour 00 ↔ wholemeal).
- **HA cron webhook payload** — Expiry alert webhook items now include full product details (brand, category, location, days_remaining, opened_at, vacuum_sealed) instead of only name/qty/unit/expiry_date.

### Docs
- `docs/wiki/Home-Assistant.md` — Documented new `sensor=product` endpoint, full product schema table, enriched webhook payload example, and Lovelace/automation template examples using `location` and `days_remaining`.

## [1.7.26] - 2026-05-26

### Added
- **Monthly stats panel** — Third rotating card in the insight banner (anti-waste → nutrition → monthly stats, 1 minute each). Shows products consumed this month with a trend vs. the previous calendar month (↑/↓/→ with % delta), animated horizontal category bars, and badges for items added, wasted, and top-used product. Falls back gracefully when the current month has no transactions. Closes [#100](https://github.com/dadaloop82/EverShelf/issues/100).
- **Extended smart-shopping horizon for staples** — Items consumed ≥ 4 times/month now get a 28-day look-ahead window; ≥ 2 times/month get 21 days. Frequently used staples no longer disappear from the smart list between restocks. Closes [#98](https://github.com/dadaloop82/EverShelf/issues/98).

### Fixed
- **TTS test interactive confirmation** — Test timeout raised from 4 s to 10 s; instead of an error, the UI shows a YES/NO prompt ("Did you hear it?") so users can confirm or report failure explicitly.
- **`end()` PHP 8 reference error** — `_offFetchProduct()` passed the result of `??` directly to `end()`, which requires a variable. Fixed with a temporary variable.
- **Database migration crash on fresh installs** — `migrateDB()` tried to rename the `transactions` table before it existed. A `sqlite_master` guard now calls `initializeDB()` and returns early when the schema is absent. Closes [#131](https://github.com/dadaloop82/EverShelf/issues/131), [#133](https://github.com/dadaloop82/EverShelf/issues/133).
- **Health-check crash on empty database** — `db_row_count` query was executed even when the `inventory` table was missing, causing a fatal PDO error. The query is now skipped until the schema is fully initialised. Closes [#132](https://github.com/dadaloop82/EverShelf/issues/132).
- **Insight banner stuck on one panel** — Rotation interval was 1 hour (effectively invisible); now 60 seconds. `_applyInsightPhase` also now skips empty panels instead of always falling back to the anti-waste card, so the rotation works correctly even when a panel has no data.
- **Untranslated OpenFoodFacts category labels** — Categories stored as OFF slugs (`en:plant-based-foods-and-beverages`, `en:dairies`, …) were shown raw. A new `_normalizeCat()` PHP function maps ~60 OFF slugs to Italian app categories; counts are re-aggregated after normalisation so `en:dairies` + `en:milk` both contribute to `latticini`.

## [1.7.25] - 2026-05-25

### Added
- **Home Assistant integration** — Full bidirectional HA support: inventory sensor (`sensor.evershelf_*`) exposes item counts, expiring items, shopping total, opened items and next-expiry info. Webhooks fire on inventory changes (add/use/shopping). Daily cron alert notifies via HA for items expiring within the configured threshold. TTS announces cooking steps through HA Media Player. New Settings tab 🏠 with connection test, TTS preset (Piper, Google, Nabu Casa), webhook config, and YAML snippet for `configuration.yaml`. Resolves [#111](https://github.com/dadaloop82/EverShelf/issues/111).
- **Offline mode** — Full offline-first support. Full-screen overlay on network loss; "Continue offline" button after 3 s, auto-enter after 8 s. Inventory and settings are synced to `localStorage` at startup and cached on every successful API call. Writes (add/use/update/delete) are queued and synced on reconnect with optimistic UI updates. Pending operations survive page refresh and are re-synced automatically at next startup. AI/network-dependent sections (anti-waste chart, nutrition analysis, recipe generator, price fetching, Gemini chat) are hidden in offline mode. `remoteLog` and `reportError` are buffered offline and flushed on restore. Broken external images replaced with a grey placeholder.
- **Offline-computed dashboard** — While offline, `inventory_summary` and `stats` (expiring/expired/opened) are derived client-side from the local cache so all dashboard stat cards and expiry alerts show accurate data.

### Fixed
- **Offline banner flood** — Opened items in the offline `stats` response lacked `is_edible`; `!undefined` evaluated to `true`, causing every opened item to be shown as "not edible" in the dashboard banner. Field is now set to `true` (client-side shelf-life check already handles genuinely expired items).
- **Version update badge showing older versions** — `_checkWebappUpdate` used `latestTag !== _loadedVersion` (inequality only), so running a newer dev build triggered an "update available" badge for an older GitHub release. Now uses `_semverGt(latest, current)` so only genuinely newer releases trigger the badge.
- **Bring! items re-appearing after manual purchase removal** — `removeBringItem` and `confirmShoppingItemFound` now call `_markBringPurchased` immediately, and `autoAddCriticalItems` respects the blocklist for depleted items.
- **Barcode lookup false "not found"** — New `_offFetchProduct()` tries three barcode candidates (given, UPC-A↔EAN-13 conversion) across two Open Food Facts locales with auto-retry.
- **Partial throw from expired-items banner** — "Butta" now opens the throw modal (qty + location) instead of silently deleting the entire inventory row.
- **Related stock display when scanning branded products** — When scanning a product, the action page now shows a green card listing any inventory items from the same generic family already at home.

## [1.7.24] - 2026-05-21

### Fixed
- **Dark mode resets to Auto on every reload** — `dark_mode` was never saved to `.env` (missing from `saveSettings` and `getServerSettings`). It is now fully server-side like all other settings; `localStorage` retains only a pre-render hint for the flash-prevention IIFE.
- **Cooking timer — no sound or speech on Android kiosk** — Three independent root causes fixed: (1) `AudioContext` was created fresh outside a user gesture, starting in `suspended` state and failing silently; a shared pre-unlocked context (`_sharedAudioCtx`) is now created during user gestures (`startCookingMode`, `addCookingTimer`). (2) The `_cookingTTS` gate (for step narration) was incorrectly blocking timer alarm speech — timer alerts now always speak regardless of that flag. (3) `_kioskBridge.speak()` (native Android TTS) was never considered as a fallback when `window.speechSynthesis` is absent in the WebView.
- **Scale use ignored for conf products** — `_scaleAutoFillUse()` returned early when `_activeUnit !== 'sub'`, but conf products default to `conf` mode. The function now auto-switches to sub mode before processing the weight reading. Scale button (`btnUse`) is also now visible for conf products that have a g/ml package unit.
- **Kiosk — native settings button reappearing unexpectedly** — `closeModal()` was calling `setNativeSettingsVisible(true)`, restoring the native Android settings button after every modal close. `_injectKioskOverlay()` now permanently hides the native button; scattered per-modal show/hide calls removed; a ⚙️ web button opens the in-app settings page.
- **SQLite database locked during inventory update** — `updateInventory()` made 3–4 separate write statements without a transaction; a concurrent cron job could acquire the write lock between them, causing a `database is locked` PDO error. All writes are now wrapped in `beginTransaction()`/`commit()`, with the Bring! HTTP sync deferred to after `commit()`. Closes [#109](https://github.com/dadaloop82/EverShelf/issues/109), [#110](https://github.com/dadaloop82/EverShelf/issues/110).
- **Depleted-item urgency incorrect** — Items with zero quantity were assigned urgency based on recency of use rather than consumption frequency. Urgency is now computed from `usesPerMonth` only, so frequently-used depleted items are correctly flagged as urgent.
- **0.5 conf use and decimal display** — Default mode on the use-quantity page is now conf for conf products; fraction buttons (½, ¼, ¾) work correctly; conf decimals are shown in the transaction history log.
- **Bring! health check token warning** — Token validity warning was shown even for valid tokens; health check is now restored with correct token-format detection.
- **Recipe quantities for conf+weight products** — Quantities are now calculated correctly when a conf product has a gram-based package unit.
- **Shopping settings not syncing across clients** — `shopping_*` keys were missing from `serverKeys` in `_applySyncedSettings`; shopping settings were client-local. All shopping keys now sync from server on load.

### Added
- **Native shopping list** — Built-in shopping list (no Bring! required) as an alternative mode (`SHOPPING_MODE=internal`). Resolves [#105](https://github.com/dadaloop82/EverShelf/issues/105).
- **Google Drive backup via localhost OAuth** — GDrive backup no longer requires a public domain; the OAuth redirect flow uses `http://localhost` via a temporary local server, compatible with self-hosted setups. Resolves [#107](https://github.com/dadaloop82/EverShelf/issues/107).

### Changed
- **All settings fully server-centralised** — Removed remaining `localStorage` usage for user preferences; all settings are now read from and written to `.env` via the API. Preferences are shared across all devices (desktop, phone, kiosk) automatically.

## [1.7.23] - 2026-05-18

### Added
- **⚙️ Generali tab** — new first tab in Settings groups all global settings: language, currency, theme, screensaver, zero-waste tips, inventory export. Old Language tab removed.
- **DB auto-cleanup** — `RECIPE_RETENTION_DAYS` (default 7) and `TRANSACTION_RETENTION_DAYS` (default 7) added to `.env`; old rows are deleted automatically every cron cycle, followed by `VACUUM` to compact the database. Manual trigger: `GET /api/?action=db_cleanup`.
- **Vacuum-sealed expiry grace period** — `VACUUM_EXPIRY_EXTENSION_DAYS` (default 30): vacuum-sealed products are only flagged as expired N days *after* the printed date, preventing false alarms on long-lasting items like cured meats.
- **Gemini AI usage tracking** — monthly and yearly token/cost stats now shown in Settings → ℹ️ Info tab, using tracked data from `data/ai_usage.json`. Cost rates configurable via `GEMINI_COST_25F_IN/OUT` and `GEMINI_COST_20F_IN/OUT` in `.env`.

### Changed
- **Auto theme is now time-based** — "Automatico" mode switches to dark at 20:00 and back to light at 07:00, based on server/device clock (not OS preference). Re-evaluates every 5 minutes; ideal for always-on kiosk displays.
- **`dispensa.db` auto-deleted** — if the legacy empty `dispensa.db` file appears alongside `evershelf.db`, it is now removed automatically by the health check.
- **ZeroWaste tips and screensaver timeout** — these settings were not being persisted to `.env` on save (missing from POST payload); fixed.

## [1.7.22] - 2026-05-17

### Fixed
- **DB name corrected** — `health_check` now looks for `evershelf.db` (was wrongly looking for `dispensa.db`). Auto-migration included: if `evershelf.db` is missing but `dispensa.db` exists, it is renamed automatically on startup.
- **Removed legacy `data/dispensa.db`** — the old database file has been deleted; only `evershelf.db` is used.
- **Conditional checks** — Bring!, TTS, Scale and Internet checks only run when the respective feature is enabled in `.env` (no more false ❌/⚠️ for unconfigured features).
- **Backups check** — no longer checks if `data/backups/` is writable by www-data (cron writes as root). Now checks that backup files actually exist and the most recent one is recent.
- **Bring! token check** — reads `data/bring_token.json` file instead of looking for a non-existent `BRING_ACCESS_TOKEN` env var.

### Changed
- **Warning popup with 5s countdown** — when non-critical checks fail at startup, a styled popup appears showing each warning with its label and a plain-language hint explaining the problem. A countdown bar auto-closes the popup after 5 seconds, then the app starts normally.
- **Error blocking popup** — when critical checks fail, a clear blocking panel shows with title "Errore critico", each failed check listed with its explanation hint, and a Retry button. The app does not start.
- **`db_legacy` check added** — warns (optional) if the old `dispensa.db` file is still present alongside `evershelf.db`.
- **32 total checks** — added `db_legacy`, `tts_url`, `scale_gateway` to the check set (conditional).
- **Hint messages** — every check now has an Italian-language `hint` field explaining what is wrong and how to fix it.

## [1.7.21] - 2026-05-20

### Changed
- **Startup health check** — Complete redesign from a banner checklist to a **real-time progress bar**. The bar fills smoothly as each of 29 diagnostic checks runs, with the current check name shown below in real time. Warnings (⚠️) are displayed as amber badges that remain visible for 2 seconds before the app proceeds. Critical failures turn the bar red and show a detailed error block with a Retry button.
- **29 comprehensive checks**: PHP version, 8 PHP extensions (pdo_sqlite, curl, json, mbstring, openssl, fileinfo, zip, intl), PHP memory/timeout/upload config, data directory, rate_limits dir, backups dir, disk write test, free disk space, SQLite connection, required tables, integrity (PRAGMA quick_check), WAL mode, DB size, inventory row count, .env file, Gemini AI key, Bring! credentials, Bring! token, cURL SSL, internet reachability.
- Warnings now clearly visible: each non-critical failure shows as a named amber badge (e.g. "⚠️ Bring! token") that cannot be missed.

## [1.7.20] - 2026-05-20

### Added
- **Startup health check** — During the splash screen, the app now runs a comprehensive server-side diagnostic before loading: PHP version, required extensions (pdo_sqlite, curl, mbstring, json), `data/` directory writability, SQLite database connection and table integrity, `.env` file presence, Gemini AI key and Bring! token. Results are displayed as an animated checklist (✅ / ⚠️ / ❌). Critical failures (DB, extensions, data dir) block the app with a clear error message and a "Retry" button — the app never starts silently broken. Non-critical warnings (missing Gemini key, Bring! token) are shown as amber items but do not block startup.
- New `?action=health_check` PHP endpoint (early-exit, no rate-limit, no auth).
- New translation keys `startup.*` in all 5 languages (IT, EN, DE, FR, ES).

## [1.7.19] - 2026-05-19

### Added
- **Zero-waste tips during cooking** — When cooking mode is active, a ♻️ card appears below each step that generates reusable scraps (peels, cooking water, egg whites, cheese rinds, bread crusts, vegetable tops, etc.). Gemini generates the tips as part of the recipe JSON at no extra API cost. Tips are dismissible per-step and reset on recipe restart. Opt-in toggle in Settings → Zero-waste tips (default OFF). Resolves [#76](https://github.com/dadaloop82/EverShelf/issues/76).
- New translation keys `cooking.zerowaste_*` and `settings.zerowaste.*` in all 5 languages (IT, EN, DE, FR, ES).

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
- **Safety-aware expired banner** — Each expired banner item shows a contextual safety tip (from `getExpiredSafety()`); danger-level items (fridge dairy/meat/fish) get an intense red banner and **Discard** as the primary button; safe/warning items keep the original button order
- **AI model fallback** — All Gemini API endpoints (expiry scan, product identification, chat, recipe non-streaming, shopping name classifier) now try `gemini-2.5-flash` first and fall back to `gemini-2.0-flash` automatically, matching the resilience already in place for recipe streaming
- **Friendly AI quota message** — When the AI returns a quota/rate-limit error the user sees "Quota AI esaurita. Riprova tra qualche minuto." instead of the raw API error string
- **Cooking TTS auto-read** — Each recipe step is read aloud automatically when navigating forward or backward; the first step is also read when entering cooking mode
- **Cooking timer 10-second warning** — When a cooking timer reaches 10 seconds the TTS announces "Attenzione! [label]: mancano 10 secondi!"
- **Cooking recipe completion announcement** — "Recipe complete! Enjoy your meal!" is spoken via TTS when the last step is confirmed

### Fixed
- **Cooking TTS gate** — `speakCookingStep()` was blocked by the global `tts_enabled` setting; the `_cookingTTS` toggle (🔊/🔇 button) is now the only gate; browser Web Speech API is used by default without requiring TTS configuration in Settings
- **Anomaly dismiss label** — The "Quantity is correct" button now appends the current inventory quantity, e.g. "Quantity is correct (2 pcs)", so the action is unambiguous
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
