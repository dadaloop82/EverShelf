# 🏠 EverShelf

> Self-hosted pantry manager — inventory, barcode scan, AI recipes, less waste.

<div align="center">

**[Website](https://evershelf.site/)** · **[Demo](https://evershelf.site/demo)** · **[Wiki](https://github.com/dadaloop82/EverShelf/wiki)** · **[Changelog](CHANGELOG.md)**

[![MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE) [![PHP 8+](https://img.shields.io/badge/PHP-8.2+-blue.svg)](https://www.php.net/) [![Version](https://img.shields.io/badge/version-1.33.0-brightgreen.svg)](CHANGELOG.md) [![CI](https://github.com/dadaloop82/EverShelf/actions/workflows/ci.yml/badge.svg)](https://github.com/dadaloop82/EverShelf/actions/workflows/ci.yml)

</div>

```bash
git clone https://github.com/dadaloop82/EverShelf.git && cd EverShelf
cp .env.example .env && docker compose up -d    # → http://localhost:8080
```

No framework, no runtime build step: a PHP 8 router, one SQLite file and a vanilla-JS PWA.
Everything runs on your own hardware, and nothing about your pantry leaves it.

---

## 🚧 1.33 is a large refactor

This release rewrites the parts the household actually touches — the settings page, the idle
screen, every list, the medicine cabinet and the stocktake. The database migrates itself on the
first request (verified row by row, see [§8](#8-upgrade-safety-is-a-test-not-a-promise)) and no
setting is lost: updating is a pull and a restart. What changed, and why:

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

---

## 📰 What's new

<details open>
<summary><b>Last five releases</b></summary>

**v1.33.0 — the idle screen became a dashboard.** Four cards read from your own data (meal &
recipe, what to eat first, pantry charts, an idea of the day), EverShelf's palette on pure
black, a context pill with weather and plan, a footer with the vitals, burn-in drift, a card
that leaves upward and comes back from the top, a tap that flips instead of closing, its own
portrait scale — and under *Appearance* which cards to keep and how often they change
(15/20/30/45/60/90 s).

**v1.32.0 — the settings page is a control panel.** Six sections in a rail, four essentials
above them (token, pairing code, backup, version), no Save button: every control writes
itself, and the bar at the bottom is the receipt.

**v1.31.35 — a medicine's active ingredient wraps.** «CLORTETRACICLINA CLORIDRATO» was cut at
the edge of the cabinet row; it goes on the next line now, and the sheet's facts wrap instead
of scrolling sideways.

**v1.31.34 — the shopping list wears the same row as the Home.** Photo strip, one meta line
(stock · qty · price), one state pill, the same two actions — 132 px like every other list.

**v1.31.33 — the Home lists wait with the SHELFY skeleton.** «Expiring», «Opened» and
«Forgotten» show the breathing skeleton instead of a blank panel, and a slow first paint no
longer hides the animation behind the splash.

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

