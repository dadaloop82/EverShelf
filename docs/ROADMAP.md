# 🗺️ EverShelf roadmap

Where the project is going, what is already done, and — most importantly — **what you
can pick up today**. Every item links to its issue: the issue is where the design is
discussed, this page only keeps the overview honest.

> This is engineering judgement, not a promise schedule. Priorities move when a
> contributor shows up, or when a user reports something that hurts more than the
> next feature.

**How to take a task**

1. Comment on the issue (“I'd like to work on this”) so nobody duplicates the work.
2. Fork, branch from `develop` (`git checkout -b feature/whatever`), see
   [CONTRIBUTING.md](../CONTRIBUTING.md) and the
   [Contributing wiki page](wiki/Contributing.md).
3. Open a PR against `develop`. Partial work is fine — say what is missing, we finish
   it together.

👉 The standing invitation (and the short list of what needs no code at all) is pinned in
[#290 — Contributing to EverShelf](https://github.com/dadaloop82/EverShelf/issues/290).
📋 The same items, with their **status / priority / type**, live on the
[roadmap board](https://github.com/users/dadaloop82/projects/2) — useful to see what is
already claimed before you start.

Labels you will see: `good first issue` (small, self-contained, guided),
`help wanted` (we want it and have no bandwidth), `roadmap` (agreed direction, maybe
far), `feature` / `enhancement` / `bug` / `i18n` / `docs`.

---

## ✅ Shipped (so we stop re-planning it)

| Feature | Issue | Since |
|---|---|---|
| Macronutrient panel (carbs / protein / fat / kcal from Open Food Facts) | [#118](https://github.com/dadaloop82/EverShelf/issues/118) | 1.10.0 (`_renderMacrosSection`) |
| Financial waste report (€ lost this month, `wasted_value_eur`) | [#117](https://github.com/dadaloop82/EverShelf/issues/117) | 1.10.0 (`monthly_stats`) |
| Recipe favourites + portion rescaler | #123, #124 | 1.10.0 |
| Telegram bot (`/lista`, `/scadenze`) | [#102](https://github.com/dadaloop82/EverShelf/issues/102) | 1.11.3 (rest of the issue is still open) |
| AI-identified product could not reach the pantry (“Product ID required”) | [#282](https://github.com/dadaloop82/EverShelf/issues/282) | 1.11.14 |
| A scanned tomato is never the pantry's jar of passata (duplicates, AI name→category, AI unit/quantity, pantry floor) | — | 1.13.0 |
| The vocabulary is **data** (`api/vocab/<lang>.json`) — genres, brands, plurals; no product names in the code | — | 1.13.0 |
| Optional support layer — **donations only**, no advertising | — | 1.12.0 |
| SHELFY acts on the page you are on (floating 10 s confirmation, compact sheet, avatar) + “…alla spesa” intent | — | 1.29.5 |

## 🔜 Next (agreed, waiting for hands)

Ordered by value ÷ effort as we see it today; the effort tag is a rough guess for
someone who already knows this codebase (S ≤ a weekend, M ≤ a few evenings, L = a
small project).

### 1. Receipt ingestion — one photo instead of 20 scans · **M–L**
Issue: [#114](https://github.com/dadaloop82/EverShelf/issues/114) (extended design in the
comments — prices, review table, no auto-write).

Grocy makes you scan item by item on the way home; a receipt has 15–30 lines. Point
the camera at the receipt, get an editable review list, confirm once.

* Reuses what exists: Gemini/OpenAI vision, the scan screen's frame capture,
  `inventory_add`, the internal shopping list.
* The new part: a `/parse_receipt` prompt returning `[{name, qty, unit, price}]`, a
  review table (remove / fix rows), and price capture into the existing price cache.
* **Never** write straight to the pantry: the review step is the feature, not a
  detail. Unreadable lines are dropped, not invented.

### 2. Weekly visual meal planner (+ external recipe books) · **M**
Issue: [#283](https://github.com/dadaloop82/EverShelf/issues/283)
A week is how families actually plan. EverShelf already has `meal_plan` and a Mealie
import; what is missing is the grid and the loop back into shopping.

* 7×2 (lunch / dinner) calendar, drag a recipe into a slot, see whether the pantry
  covers it.
* Missing ingredients → internal list or Bring! in one tap (reuses the recipe →
  shopping plumbing and its pantry deduction).
* Bidirectional sync with Mealie, plus **Tandoor Recipes** as a second source.
* Keep it offline-friendly: the grid is local state until it is saved.

### 3. Parent / child products (umbrella + aliases) · **M**
Issue: [#284](https://github.com/dadaloop82/EverShelf/issues/284)
Buying a different brand should not fragment the pantry: “Spaghetti” is the parent,
Barilla / Rummo / De Cecco are children with their own barcode.

* New `parent_product_id` (or an alias table) + a second barcode slot.
* Stock, use and shopping then roll up to the parent, while the title keeps the
  brand the household actually bought.
* Migration + one merge helper; must not break `normalizeProductName()` duplicate
  detection or the `kind` / `shopping_name` split.

### 4. Freezer lifecycle (freeze / thaw) · **M**
Issue: [#286](https://github.com/dadaloop82/EverShelf/issues/286)
The anti-waste logic cannot ignore the freezer.

* A **frozen** state that swaps the countdown for a configurable shelf life (e.g.
  fresh meat + 180 days), with a per-category default.
* A **thaw** action that sets `expiry_date = now + 48 h` and says so: “Thawed — eat
  within 48 hours”, by notification as well.
* Sits next to the existing vacuum-seal expiry extension, so the two must not fight
  over the same `expiry_date`.

### 5. Quick audit mode (stocktaking in two minutes) · **S–M**
Issue: [#285](https://github.com/dadaloop82/EverShelf/issues/285)
Inventories drift. A touch view that shows **one item at a time** as a sentence —
“Ci sono ancora 120 g di Formaggio Morbido di Barilla in Frigo” — and asks one
question with real answers: *Sì, confermo*, *Cambio quantità* / *Cambio posizione*
(the normal edit sheet opens on top of the run, manual or from the scale), *No, è
esaurito* (the use-all action) and *No, è deperito* (the throw-all action), plus
*quasi finito* and *salta* for the rest. You can walk a shelf or the fridge without
the scanner.

* One screen, large targets, works on the kiosk with a finger and on a phone.
* A run closed by mistake resumes from the point reached for one hour; when the
  scale is connected, *Leggi dalla bilancia* fills the quantity in one tap.
* *Indietro* rewinds the last answer with a real undo — including from the summary,
  so the last article can be fixed after the run has ended.
* Writes through the existing `inventory_update` / `inventory_use` paths, so
  consumption stats, the shopping list and the waste learning keep working; batches
  the changes and reports them at the end.
* No new backend action needed.

### 6. Household nutrition profiles for the AI chef · **M**
Issue: [#287](https://github.com/dadaloop82/EverShelf/issues/287)
The Health Bridge already knows about diets and goals; the recipe generator should
know about **everyone at the table**.

* Per-member profiles: allergies, intolerances, vegetarian / vegan / keto, calorie
  target, and who is actually eating tonight.
* The constraints are applied to the recipe prompt **and** validated on the answer:
  an allergy is a hard filter — the model is not trusted with it, and a dish that
  cannot be verified is flagged instead of served.
* Same choke point as the health pipeline, so one place owns the rules.

### 7. Thermal label printing (ESC/POS + Brother) · **M** (ESC/POS **S**)
Issue: [#288](https://github.com/dadaloop82/EverShelf/issues/288)
For meal prep and portions pulled out of the freezer: one tap on the kiosk prints a
sticker with the name, the date and a QR code that later deducts that portion.

* The QR encoder already ships in `api/lib/qr.php` (ISO/IEC 18004), so no new
  dependency; the label is composed server-side and sent to the printer.
* ESC/POS over TCP 9100 is the easy half; Brother QL needs raster commands and is the
  long half — the two can ship separately.
* Scanning the printed QR resolves the product + portion and calls the existing use
  action.

### 8. Multi-store and inflation tracking · **M–L**
Issue: [#289](https://github.com/dadaloop82/EverShelf/issues/289)
Instead of only estimating a replacement cost: know what *you* paid, where.

* Add a store dimension to purchases (name, optional location) and keep a price
  history per product × store.
* Then two answers become possible: “this basket costs less at X”, and “this product
  went up 18 % since March”.
* Builds on `shopping_price_cache.json`, `PRICE_SOURCE` and the monthly stats, so the
  UI work is mostly charts we already know how to draw.

## 🧊 Parked (feature built, deliberately switched off)

* **Ad banner slots** (dashboard + screensaver) and the *keep the banners* / *both*
  answers. The code is in the tree, commented, with a re-enabling checklist in the
  wiki: [Support and ads](wiki/Support-and-ads.md#banner-slots-parked). EverShelf
  ships without advertising; the support dialog offers a donation or nothing.

## 💡 Wanted, needs an owner

Open issues we would happily hand over — say the word in the issue:

| Idea | Issue | Why it is a good first step |
|---|---|---|
| Custom tags per product (`gluten-free`, `bulk-buy`, …) | [#97](https://github.com/dadaloop82/EverShelf/issues/97) | self-contained: one column, one filter bar |
| Monthly PDF report (inventory, consumption, waste) | [#103](https://github.com/dadaloop82/EverShelf/issues/103) | server-side only, no UI risk |
| Public REST API v1 + outbound webhooks | [#106](https://github.com/dadaloop82/EverShelf/issues/106) | integration-facing, spec already exists |
| Voice input (add items by speaking) | [#115](https://github.com/dadaloop82/EverShelf/issues/115) | browser API + one AI prompt |
| Voice commands in cooking mode | [#125](https://github.com/dadaloop82/EverShelf/issues/125) | the TTS half already ships |
| Geofencing: remind me near the supermarket | [#121](https://github.com/dadaloop82/EverShelf/issues/121) | client-side only, GPS never leaves the device |
| Actionable push notifications (use / throw from the notification) | [#122](https://github.com/dadaloop82/EverShelf/issues/122) | builds on the existing web-push inbox |
| Video walkthrough for the README | [#69](https://github.com/dadaloop82/EverShelf/issues/69) | no code at all |
| A new language (translation) | [#93](https://github.com/dadaloop82/EverShelf/issues/93) | one JSON file, no setup |

## 🔭 Longer term

Collected ideas that are bigger than a feature branch (see
[OPTIMIZATION-BACKLOG.md](OPTIMIZATION-BACKLOG.md) for the technical debt side):

* real-money ledger (spend per month, per store), multi-user roles and permissions,
  multi-list UX, deeper recipe ↔ pantry matching, offline conflict resolution.
* Heavy items kept deliberately out of the near term: AGP 9 / SDK 37 migration for the
  Android apps.

---

*Something missing from this page? Open an issue with the `enhancement` label — a
well-argued idea is a contribution too.*
