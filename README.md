# 🏠 EverShelf

> Self-hosted pantry manager — inventory, barcode scan, AI recipes, less waste.

<div align="center">

**[Website](https://evershelf.site/)** · **[Demo](https://evershelf.site/demo)** · **[Wiki](https://github.com/dadaloop82/EverShelf/wiki)** · **[Changelog](CHANGELOG.md)**

[![MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE) [![PHP 8+](https://img.shields.io/badge/PHP-8.2+-blue.svg)](https://www.php.net/) [![Version](https://img.shields.io/badge/version-2.2.9-brightgreen.svg)](CHANGELOG.md) [![CI](https://github.com/dadaloop82/EverShelf/actions/workflows/ci.yml/badge.svg)](https://github.com/dadaloop82/EverShelf/actions/workflows/ci.yml)

</div>

```bash
git clone https://github.com/dadaloop82/EverShelf.git && cd EverShelf
cp .env.example .env && docker compose up -d    # → http://localhost:8080
```

No framework, no runtime build step: a PHP 8 router, one SQLite file and a vanilla-JS PWA.
Everything runs on your own hardware, and nothing about your pantry leaves it.

---

## 🎉 EverShelf 2.x — the biggest update this project has ever had

This is not a patch release. EverShelf 2.x is the release where the app the household
actually touches was rebuilt from the inside: **every screen, every list, the settings
page, the idle screen, the medicine cabinet, the stocktake, the API and the upgrade
path** — and a demo with a real pantry in it, so you can see all of it before you
install anything.

The pantry you already have is safe: the database repairs and verifies itself on the
first request, and a second boot changes nothing. Everything you *look at* is new.

<p align="center">
  <img src="screenshots/hero-v2.gif" alt="EverShelf 2.x — Home, Pantry, Opened items, Shopping list and the product sheet" width="320">
</p>

> **Before:** eighteen settings tabs and a Save button, a clock with a canned sentence on
the wall, three different list layouts, and a demo that answered with an empty pantry.
>
> **After:** six settings sections that save themselves, a dashboard that reads your own
> data, one 132 px row in every list, a medicine cabinet that reads the pack instead of
> guessing — and a demo that opens on a real, seeded household.

| | EverShelf 1.x | EverShelf 2.x |
|---|---|---|
| **Settings** | 18 tabs in two strips, one *Save* | six sections, four essentials always visible, **no Save button** — every control writes itself |
| **Idle screen** | a big clock + a sentence from a list | four cards from your own data (a recipe you already cooked, what to eat first, charts, an idea of the day) |
| **Lists** | three different row designs | **one** 132 px row: photo strip, name + brand, stock, date, one pill, two actions |
| **Medicines** | — | opt-in cabinet, national catalogues imported locally, PAO on the pack, **no model in the loop** |
| **Stocktake** | one row at a time, one device | three ways, **one ledger**, resumable from any device |
| **AI** | Gemini only | Gemini · OpenAI · Llama, one provider switch |
| **Demo** | a “mock pantry” that did not exist | its own seeded database, English first, six languages, embeddable in a page |
| **Security** | token optional | token **minted on first boot**, pairing code, single-use OAuth state, webhook secret |

---

## 📸 A look at it

| | |
|---|---|
| <img src="screenshots/dashboard-v3.webp" width="460" alt="Home dashboard"> | <img src="screenshots/pantry-v3.webp" width="460" alt="Pantry list"> |
| **Home** — what to eat first, what is opened, one strip for the month | **Pantry** — one row grammar, photo strip, state pill, stock and date |
| <img src="screenshots/opened-items-v3.webp" width="460" alt="Opened items"> | <img src="screenshots/shopping-list-v3.webp" width="460" alt="Shopping list"> |
| **Opened** — the PAO clock, with *Use it in a recipe* inline | **Shopping list** — anti-waste quantities, swipe left to buy, right to skip |
| <img src="screenshots/product-sheet-v3.webp" width="460" alt="Product sheet"> | <img src="screenshots/use-item-v3.webp" width="460" alt="Use an item"> |
| **Product sheet** — photo, brand, packs, places, opened clock | **Use** — fractions, packs and *where it goes* after opening |
| <img src="screenshots/recipes-v3.webp" width="460" alt="Recipes"> | <img src="screenshots/recipe-detail-v3.webp" width="460" alt="Recipe detail"> |
| **Recipes** — generated from what is really in the pantry | **Recipe** — ingredients checked against the shelves |
| <img src="screenshots/cooking-mode-v3.webp" width="460" alt="Cooking mode"> | <img src="screenshots/scanning-v3.webp" width="460" alt="Barcode scanner"> |
| **Cooking mode** — steps, TTS, timers, hands-free (mic off until you ask) | **Scanner** — one lock box, the controls under the picture |
| <img src="screenshots/shelfy-chat-v3.webp" width="460" alt="SHELFY assistant"> | |
| **SHELFY** — voice or text, and it acts where you stand | |

---

## 🔄 Before → After, on the same screens

<details open>
<summary><b>Home / Dashboard</b> — counters and a list became a dashboard that tells you what to do</summary>

<img src="screenshots/compare-dashboard-v3.webp" alt="Dashboard: before (1.x) and after (2.x)" width="980">

</details>

<details>
<summary><b>Pantry list</b> — 88 tiles in a grid became one readable row grammar</summary>

<img src="screenshots/compare-pantry-v3.webp" alt="Pantry list: before (1.x) and after (2.x)" width="980">

</details>

<details>
<summary><b>Shopping list</b> — its own design became the same row as the Home</summary>

<img src="screenshots/compare-shopping-list-v3.webp" alt="Shopping list: before (1.x) and after (2.x)" width="980">

</details>

<details>
<summary><b>Product sheet and editing</b> — one hierarchy, photo and brand in the head, no wall of fields</summary>

<img src="screenshots/compare-product-sheet-v3.webp" alt="Product sheet: before (1.x) and after (2.x)" width="980">

</details>

<details>
<summary><b>Use an item</b> — fractions, packs and the place the rest went</summary>

<img src="screenshots/compare-use-item-v3.webp" alt="Use an item: before (1.x) and after (2.x)" width="980">

</details>

<details>
<summary><b>Opened items</b> — a yellow card became the PAO clock with the two actions inline</summary>

<img src="screenshots/compare-opened-items-v3.webp" alt="Opened items: before (1.x) and after (2.x)" width="980">

</details>

<details>
<summary><b>Recipes and recipe detail</b> — generated from the shelves, with the pantry checked</summary>

<img src="screenshots/compare-recipes-v3.webp" alt="Recipes: before (1.x) and after (2.x)" width="980">

<img src="screenshots/compare-recipe-detail-v3.webp" alt="Recipe detail: before (1.x) and after (2.x)" width="980">

</details>

<details>
<summary><b>Cooking mode, scanner and SHELFY</b> — the kitchen surfaces</summary>

<img src="screenshots/compare-cooking-mode-v3.webp" alt="Cooking mode: before (1.x) and after (2.x)" width="980">

<img src="screenshots/compare-scanning-v3.webp" alt="Scanner: before (1.x) and after (2.x)" width="980">

<img src="screenshots/compare-shelfy-chat-v3.webp" alt="SHELFY: before (1.x) and after (2.x)" width="980">

</details>

---

<details>
<summary><b>📖 The full story — the eight changes behind 2.x</b> (click)</summary>

### 1. Settings is a control panel, not a wall of fields

Eighteen tabs in two strips became **six sections** (🏠 General · 🍳 Cooking · 🤖 AI & voice ·
💊 Medicines · 🔔 Alerts & integrations · 🔒 System) in a sticky rail on a monitor and a
swipeable strip of pills on a phone. Above the rail, always visible, the **four essentials**:
the access token (masked, reveal/copy), the pairing code, the last backup with «Backup now»,
and the version. **There is no Save button any more** — every control writes itself
(checkboxes and selects at once, text 700 ms after the last keystroke) and the sticky bar at
the bottom is a receipt: *pending → saving → saved*, or the server's refusal, which it never
dresses up as «saved». Guides that need more than a paragraph live in a dialog.

### 2. The idle screen is an ambient kitchen dashboard

The kiosk used to show a big clock and a sentence picked from a static list. It is now **one
card at a time, read from your own data**: 🍽️ the meal of the day with a real recipe from your
archive the pantry can cook *now* (expiring ingredients weigh more), ⚠️ up to three things to
eat first (opened past its window, expired, expiring — each row opens the product), 📊 the
category pie with health / variety / freshness rings and one insight from the weakest number,
💡 a dynamic idea (shopping list, saved vs wasted, weather + what expires first, medicine
cabinet, shape of the pantry). A card with nothing to say is skipped, never painted empty.
Pure black for OLED walls, a few pixels of drift every 2.5 minutes against burn-in, and a tap
flips the card — **a tap never closes it** (a brush on a wall must not take the clock away):
«Unlock», a swipe up or Esc do. It starts **always** after the timeout you set, on any page
with any sheet open; **cooking mode is the only exception** (the recipe is the session).
Portrait tablets get their own scale, not a squeezed landscape.

### 3. One row grammar for pantry, Home and shopping

`[photo strip] [name + brand / stock · qty · price] [state] [✓] [⋮]`, 132 px in every list —
the pantry, «Expiring», «Expired», «Opened», «Forgotten» and the shopping list. One builder
for the state pill, one for the actions, and the two facts a row is read for (stock and date)
are the biggest things in it. A medicine is never a food surface: it has its own drawn mark,
its own shelf in the shopping list, its own tint — and its active ingredient wraps instead of
running off the screen.

### 4. EverShelf Med — an opt-in medicine cabinet that reads, never guesses

Off by default, with its own dedicated place and a closed set of categories. A box is
identified by a printed code (Italian AIC, German PZN, US NDC…) verified against a **local
catalogue** imported from the national open data — the reader **never calls a model**, and a
code the catalogue does not know is treated as a misread, never as a new medicine. Every
country rule lives in data, so adding a country means adding a file
([docs/wiki/Medicines-Country-Schemas.md](docs/wiki/Medicines-Country-Schemas.md)).

### 5. The quick stocktake: three ways, one ledger

«One at a time» (the app proposes), «I'll tell you» (you type a name) and «Barcode» (its own
camera inside the sheet) all count in the same session, and a run started at the fridge resumes
on any device — the state lives on the server, never in one browser. One answer covers every
pack of that article in that place, so the same name is never asked twice.

### 6. Text and vocabulary are data — six locales, enforced

Every user-facing string is a translation key in `translations/*.json` (IT · EN · DE · FR ·
ES · ZH) and every product word lives in `api/vocab/<lang>.json`. CI fails on a key that is
used but missing, on a generated file that no longer matches its JSON, and on vocabulary words
creeping back into the logic files.

### 7. Security and operations hardened

Every action authenticates (token + CSRF, and a *pairing code* hands the token to a new
device); an install with no token **mints one** on its first request instead of running open;
the Google Drive callback consumes a single-use `state`; the Telegram webhook compares the
secret Telegram echoes back and refuses to answer without one; `ALLOWED_HOSTS` guards against
DNS rebinding. Logs carry levels and two rivers (`app`, `kiosk`), rotate daily, and a reload
always names its author. Backups are `VACUUM INTO` files that a restore proves before
promoting them, scheduled by the container's cron.

### 8. Upgrade safety is a test, not a promise

`php scripts/check-upgrade-migration.php` builds a database with the **old** code, fills it
with realistic content and opens it with the **new** one: 48 checks prove that nothing is
lost, that the repairs are the intended ones, and that a second boot changes nothing. It is
the gate this release was developed against.

</details>

---

## 📰 What's new

<details open>
<summary><b>Last five releases</b></summary>

**v2.2.9 — the Health Bridge, rebuilt from zero.** The Android companion is now *EverShelf
Health Bridge*: five tabs (Today / Diary / Charts / Control / Info), every biometric Health
Connect exposes, 7/30/90-day charts drawn without a chart library, a diary that keeps the
household's notes — in the **same six languages as EverShelf** (it/en/de/fr/es/zh) — and a rule
that is now enforced by code: **data leaves the phone only when EverShelf ANSWERS**, so a day
spent outside the house waits in a local queue instead of being lost or retried in a storm. It
updates itself over the LAN first (the workflow now publishes the APK in `releases/`) and GitHub
second, and installs only an APK with the same signature and a higher `versionCode` — the
build refuses to publish anything that Android would make you uninstall first. Crashes and sync
failures are reported through `report_error` into their own river (`data/logs/health-*.log`)
and, when the instance is configured for it, into a GitHub issue like the rest of EverShelf.

**v2.2.8 — a translation that carries markup was printed as text.** In the «usa / finito tutto»
chooser the line «Finita **1 confezione** ({qty})» carries a `<strong>` by design, and it was wrapped
in `escapeHtml(...)`: the household read the tag instead of bold text. The scanner's no-camera notice
had the same defect with a `<br>`. Both are written raw now, with only the interpolated value
escaped — and `scripts/i18n-audit.py` grew a section **[5]** that fails on the pattern (a bare
«Peso &lt; 10 g» is not flagged).

**v2.2.7 — the AI generator, and a card that still proposed what the house cannot cook.** My own
regression: the guard added in 2.2.2 used a `const` written at the bottom of `api/index.php`, and a
`const` is a statement — the router dispatches the handler long before that line runs, so the
generator died with «Undefined constant "RECIPE_MIN_PANTRY_INGREDIENTS"» and produced no recipe at
all. The constant now lives at the top with the others, and the same request streams «✅ Ricetta
pronta!». And the idle screen no longer proposes a dish that is missing even ONE ingredient (a pizza
margherita whose «pizza alta regina» is finished): only a fully cookable recipe is a proposal, 9 of
the 26 archived ones on a real pantry; otherwise the rotation moves to the next view.

**v2.2.6 — «la lista dice esaurito cose che ho».** The row named «Latte» was a **yogurt**: its
`raw_name` was «Yougurth fior di latte», and because `yougurth` was a spelling the vocabulary had
never been taught, the genre in the middle of the title («fior di **latte**») decided the family — so
a yogurt joined the milk family and the list read «Latte · Esaurito» next to 6.6 packs of milk. The
family now comes from the genre that LEADS the title (the misspellings are data, a middle-of-title
genre can no longer overrule a known lead), an app-owned row is renamed to the family its article
belongs to today, and a migration heals the products already stored (2 of 9 disagreeing rows were
wrong; the compound families like «Pangrattato» were left alone).

**v2.2.5 — «hai tutti gli ingredienti» was not a fact.** The idle screen proposed recipes whose
ingredients were finished and printed «✅ Hai tutti gli ingredienti» over them: that chip came from
`missing === 0`, and zero meant both "everything is on a shelf" and "nothing was checked". The check
also **skipped** every ingredient whose quantity is not a number («q.b.», «80-90 g» — the sugar of
the Maxi-Pancake, the lemon of the Piadina), so with the sugar gone it still answered `missing 0`.
The answer is now three-valued — missing / unverifiable / verified — the ✅ is printed only when
*every* ingredient was found on a shelf, otherwise «⚠️ Manca: …» or «❔ Da verificare: …», and any
write into the pantry drops the shelf snapshot, so a product finished a second ago can no longer
read as present.

**v2.2.4 — a search does not outlive the moment.** While a word narrows the pantry the idle return to
the Home comes after **1 minute** instead of 2 and **clears the search**: the shelf comes back whole
and the next visit no longer finds an empty or wrong list (the search survives navigation on
purpose). Measured in a browser: «latte» → 4 rows and a 60 s clock → after 60 s the Home with an
empty field, then 150 rows again with the clock back at 2 minutes.

Older releases live in [CHANGELOG.md](CHANGELOG.md).

</details>


---

## ✨ Features

| | |
|---|---|
| **Inventory** | Scan (EAN, QR, DataMatrix), places, expiry estimates, opened packs, favourites, CSV import/export |
| **AI** | Gemini · OpenAI · Llama — identify a product, read an expiry, propose recipes, chat with SHELFY (voice or text) |
| **Shopping** | Smart list with anti-waste quantities, recipe → list with pantry deduction, optional [Bring!](https://www.getbring.com/) |
| **Cooking** | Step-by-step mode, TTS, timers, zero-waste tips, hands-free commands |
| **Calendar** | Subscribe to your expiries — an ICS/WebCal feed for Google/Apple Calendar |
| **Alerts** | Expiry push without Home Assistant: [ntfy](https://ntfy.sh), a JSON webhook or HA, with a test button |
| **Medicines** | Optional cabinet, national catalogues imported locally, PAO on the pack, no model in the loop |
| **PWA** | Installable, offline queue for writes, one household across phone / tablet / kiosk |

**Integrations:** [Home Assistant](https://github.com/dadaloop82/ha-evershelf) ·
[MCP server](mcp-server/README.md) · [Health Bridge](evershelf-health-bridge/README.md) ·
[Android kiosk](evershelf-kiosk/README.md)

**Languages:** Italiano · English · Deutsch · Français · Español · 中文 — the interface is
translated entirely, including the idle screen and every server message.

---

## 🧱 The repository in ten lines

```
index.html            the single page: every screen and dialog
assets/js/app.js      the frontend (one file, indexed by docs/INDEX-app-js.md)
assets/js/*.js        focused modules: audit (stocktake), screensaver, settings search, receipt
assets/css/           style.css + themes, surfaces, mobile (phone geometry), tablet (kiosk)
api/index.php         the backend: one switch(action) and its handlers
api/lib/*.php         domain libraries (AI, health, seasonal, medicines, security, support…)
api/database.php      schema + migrations, applied on the first request
data/                 SQLite database, caches and logs — never served over HTTP
translations/*.json   six locales; api/vocab/*.json holds the product vocabulary
```

The two monoliths are navigated with generated indexes (`docs/INDEX-app-js.md`,
`docs/INDEX-api-index.md`, `docs/INDEX-actions.md`) and described in
[docs/CODEBASE-MAP.md](docs/CODEBASE-MAP.md). Working on the code? Start from
[AGENTS.md](AGENTS.md) and [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

---

## 📦 Install

| | |
|---|---|
| **Docker** (recommended) | `cp .env.example .env && docker compose up -d` — data lives in `./data` |
| **Unraid** | [unraid/evershelf.xml](unraid/evershelf.xml) CA template · [docs/wiki/Unraid.md](docs/wiki/Unraid.md) |
| **Manual** | PHP 8.2 with `pdo_sqlite`, `curl`, `mbstring` → `php -S 0.0.0.0:8080` |
| **Kiosk** | Android wall tablet APK, self-updating over your LAN → [docs/wiki/Android-Kiosk.md](docs/wiki/Android-Kiosk.md) |
| **Health Bridge** | A phone or HA as a step source → [evershelf-health-bridge/README.md](evershelf-health-bridge/README.md) |

Configuration is one `.env` file (and the settings page, which writes it for you). The
complete reference is [docs/wiki/Configuration.md](docs/wiki/Configuration.md); the step-by-step
guides live in [docs/wiki/Installation.md](docs/wiki/Installation.md).

## 🔐 Privacy

Self-hosted: the database, the photos you take and the logs stay in `data/` on your machine.
The only network calls are the ones you enable — the AI provider you configure, Open Food
Facts for a barcode, your weather provider, your notification channel. Keys stay in `.env` and
are never sent to the browser: the app receives a masked hint, never the secret.

## 🤝 Contributing

Bugs and ideas are welcome — [docs/ROADMAP.md](docs/ROADMAP.md) says what is next and which
issues are up for grabs. Before opening a PR read [CONTRIBUTING.md](CONTRIBUTING.md) and
[SECURITY.md](SECURITY.md); CI runs the PHP/JS syntax checks, the generated-file guards and
the translation audit on every push.

## 📄 License & credits

MIT — see [LICENSE](LICENSE). Product data from
[Open Food Facts](https://world.openfoodfacts.org/) and other open data — the splash and
*Settings → Credits* name every source. EverShelf shows **no advertising**.

