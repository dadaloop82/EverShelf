# ✨ Features

A complete walkthrough of EverShelf's features.

---

## 📦 Inventory Management

### Adding Products

- Tap **➕** to open the add form
- Search by name or scan a barcode
- Select storage location: Pantry, Fridge, Freezer, or a custom location
- Enter quantity and expiry date (or let AI estimate it)
- Mark as vacuum-sealed or opened for adjusted shelf-life calculation

### Barcode Scanning

Tap the barcode icon to open the camera scanner (QuaggaJS). The app:
1. Checks your local database first
2. Falls back to [Open Food Facts](https://world.openfoodfacts.org/) for unknown barcodes
3. Pre-fills the product form with name, brand, category

### AI Product Identification

Point the camera at any product — Gemini identifies it and:
- Shows matching products **already in your pantry** first
- Suggests a new product entry with pre-filled fields
- Provides a storage location hint and estimated shelf-life

### Storage Locations

| Location | Icon | Notes |
|----------|------|-------|
| Pantry | 🏠 | Room temperature |
| Fridge | ❄️ | Refrigerated |
| Freezer | 🧊 | Frozen |
| Custom | 📦 | Any name you choose |

### Opened Product Tracking

When you partially use a product and mark it as "opened":
- Shelf-life is recalculated from the opening date
- Uses AI (Gemini) + per-category rules (e.g. fish: 2 days, milk: 3 days)
- Whole sealed packages always keep their original manufacturer expiry
- Products with mixed whole + fractional units show as two separate entries

### Vacuum-Sealed Support

Mark any product as vacuum-sealed to extend its estimated expiry date (typically 2–3× the normal shelf-life).

---

## 🤖 AI Features (Google Gemini)

All AI features require a `GEMINI_API_KEY` in `.env`. They degrade gracefully when the key is missing or quota is exceeded.

### Expiry Date Reading

Photograph the label on a product — Gemini extracts the expiry date and fills the field automatically.

### Product Identification

Camera-based identification with pantry matching. See [Adding Products](#adding-products) above.

### Storage & Shelf-life Hint

When adding a new product, a background Gemini call suggests:
- Optimal storage location
- Estimated shelf-life in days

Shown as an inline AI badge next to the expiry estimate. Does not block the form.

### Recipe Generation

Tap **🍳 Recipes** → **Generate Recipe** to get a recipe using:
- Ingredients about to expire (prioritised)
- What's currently in your pantry
- Your language preference

Recipes stream live via Server-Sent Events so results appear as they are generated.

Every recipe also answers "what do I still have to buy?". The 🛒 panel under the
ingredients compares the recipe against the pantry *at the moment you open it* and
lists only the gaps — 500 g of pasta with 200 g left asks for 300 g, and stock held
in another unit (3 ricotta tubs against "250 g asked") counts as covered. Tick or
untick the rows and press **Add the missing ones**: they land on the shopping list
through the same path as a manual add, so Bring!, the blocklist and the Home
Assistant webhook all keep working. Salt, pepper, oil and water are treated as free
staples and never nagged about; something already on the list is marked as such
instead of being added twice. Settings → recipe shopping mode chooses between
*ask*, *add automatically* and *off*.

### AI Chat Assistant

Open **💬 Chat** to ask questions like:
- "What can I make with eggs and pasta?"
- "How long does cooked ham last once opened in the fridge?"
- "Suggest a quick snack"

The assistant knows your current inventory.

### Shopping Suggestions with Tips

Smart shopping predictions include a short AI-generated practical tip per item (e.g. "Buy the 2 kg bag — it freezes well").

### Anomaly Explanation

When the dashboard shows a suspicious quantity banner, tap **🤖 Explain** to get a plain-language explanation of why the discrepancy likely occurred and what to do about it.

### Model Fallback

AI endpoints walk `gemini-3.5-flash` → `gemini-3.1-flash-lite` → `gemini-2.5-flash-lite` on quota or unavailable-model errors (`gemini-2.5-flash` is omitted — blocked for many new Google AI keys).

---

## 🫀 Health Bridge & Fuel Mode

- **Health Bridge** Android APK syncs Health Connect daily metrics to EverShelf (QR pairing from Settings → Health).
- **Fuel Mode** sizes recipes to your profile goal + today’s activity + pantry stock.
- Intake is inferred from pantry use and cooked recipes only (no separate meal diary).

See [Health](Health) and the [Health Bridge README](https://github.com/dadaloop82/EverShelf/blob/main/evershelf-health-bridge/README.md).

---

## 🛒 Shopping List (Bring! Integration)

Configure `BRING_EMAIL` and `BRING_PASSWORD` in `.env` to enable.

### Features

- **View and manage** your Bring! list inside EverShelf
- **Auto-add on depletion** — when stock hits zero, the product is added to Bring! automatically
- **Auto-remove on scan** — scanning a product in removes it from the shopping list
- **Generic names** — products are grouped by type ("Milk", "Cooking cream") not brand, keeping the list clean
- **Auto-migration** — items already on Bring! are silently renamed to their generic name on list load
- **Catalog coverage** — 100+ product types mapped to Bring! catalog keys for icons and categories in the Bring! app
- **AI fallback** — unknown product types use Gemini to determine the best generic name

---

## 🍳 Cooking Mode

Start cooking mode from any recipe by tapping **▶ Start Cooking**.

### Features

- **Step-by-step guidance** — fullscreen, distraction-free interface
- **Text-to-Speech** — each step is read aloud automatically when you navigate; supports:
  - Browser Web Speech API (default)
  - Native Android TTS (kiosk app)
  - Custom REST endpoint (e.g. Home Assistant)
- **Built-in timers** — automatic timer suggestions based on recipe text; 10-second vocal countdown warning before expiry
- **Ingredient tracking** — mark ingredients as used; leftover quantities prompt a "move to another location" flow
- **Recipe completion** — "Enjoy your meal!" spoken on the last step

---

## 📊 Dashboard

### Insight panels (rotating)

The area under the banners cycles through **eight panels**, one per minute, and skips
any panel that has nothing to say — the rotation never lands on an empty card.

| Panel | What it shows |
|-------|---------------|
| **Overview** | products, expiring, expired and open packages as tiles, plus where the stock actually is (`🗄️ Pantry 12 · 🧊 Fridge 5…`) |
| **Waste** | waste rate vs. the national average and the estimated yearly kg |
| **Trend** | products used and thrown away in the last 30 days, each compared with the 30 before |
| **Nutrition** | macro split of what you used |
| **Freshness** | how many products have a tracked expiry, how varied the pantry is, how much of it is fresh (fridge + freezer), leading category |
| **Monthly** | this month against the last |
| **Spend** | what you spent — and when nothing is recorded it says so and explains where the number comes from, instead of hiding |
| **Macros** | Fuel Mode targets against what you consumed |

### Inventory Overview

Three stat cards at the top show item counts for Pantry, Fridge, and Freezer with animated skeleton loading while data fetches.

### Expiry Alerts Banner

Priority-sorted notifications for:
- Expired products (with safety assessment — green ✅ safe, amber 👀 check, red 🚫 danger)
- Products expiring within 3 days

Every row is **one fact per line**: name and brand, then location · quantity · note,
then the verdict with a short tip (`{verdict} — {tip}`, e.g. *👀 Check — past its date
by over a month: open, smell, taste*). Actions (Use, Throw away, Edit, Extend, Dismiss)
sit **side by side** in a wrapping row — never stacked in a column.

| Block | Rows shown | Order |
|-------|-----------|-------|
| Expiring soon | 3 | closest expiry first |
| Expired | 5 | most overdue first |
| Opened | 5 | open the longest first |
| Unused for too long | 3 | a different slice every 30 minutes |

Blocks with more products than the limit show an **“…and N more…”** line that opens the
full list instead of stretching the dashboard.

### Anomaly Banner

Highlights suspicious quantities (e.g. "You have 0 eggs but used 12 this month"). Actions:
- One-tap correction to the suggested quantity
- Inline edit with free-form quantity
- "🤖 Explain" for AI explanation
- Dismiss (with current quantity shown: "The quantity is correct (2 pcs)")

### Quick Recipe Bar

One-tap recipe suggestion using the ingredients closest to expiry. It sits **below** the
expiry alerts, so a suggested recipe never pushes the products that need attention off
the screen.

---

## 🗓️ Calendar Feed (ICS / WebCal)

Export the pantry deadlines to a real calendar — the same one that already holds
the dentist appointment.

- **Settings → 🗓️ Calendar**: switch it on, save, and the subscribe URL is minted
  for you (no key to invent).
- One **all-day event per in-stock item with an expiry date**, with location,
  quantity and brand in the description, and a reminder the day before.
- Expired items stay visible for a few days with a `⚠️ EXPIRED` prefix, so a
  forgotten jar does not silently disappear.
- Works with **Google Calendar** (*From URL*), **Apple Calendar** (*New Calendar
  Subscription*), Thunderbird, Nextcloud, Home Assistant…
- **♻️ Rotate link** revokes every existing subscription instantly.

Full details: [Calendar Feed](Calendar).

---

## 📲 Push Notifications (ntfy / generic webhook)

Get the alerts on your phone **without Home Assistant** — set it up in
**Settings → 🔔 Notifiche**.

- **ntfy** (recommended) — free, no account: generate a secret topic with the 🎲
  button and subscribe to that same topic in the [ntfy app](https://ntfy.sh) for
  Android, iOS or the web.
- **Generic webhook** — a JSON `POST` to n8n, Node-RED, Gotify, a Discord/Slack
  bridge, or your own script, optionally with a bearer-style token header.
- **Events** — products about to expire (daily check), items added to the shopping
  list, and stock updates. Same event names as the Home Assistant webhooks, so an
  existing automation keeps working untouched.
- **Message language** — the text is generated server-side (`NOTIFY_LANGUAGE`), so a
  household can read notifications in one language while the UI follows the browser.
- **Test button** — pushes a notification through every configured channel and
  reports its HTTP status, so a wrong URL or topic shows up right away.
- Safety rails: the topic is validated as a URL path segment, messages are clamped to
  3600 bytes on a UTF-8 boundary, headers cannot be injected, only `http(s)` targets
  are contacted, and stored tokens are write-only (the field shows `••••••••`).

Full details: [Configuration](Configuration) → *Push Notifications*.

---

## 📱 Progressive Web App (PWA)

EverShelf is installable as a PWA on any device:

1. Open in Chrome/Safari/Edge
2. Tap **"Add to Home Screen"** (browser menu)
3. Launch from the home screen like a native app

Features:
- Offline-capable shell (assets cached)
- Full-screen mode on mobile
- Multi-device: all data syncs via the shared server

---

## 🔔 Update Notifications

When a new EverShelf release is published on GitHub, a small pill appears in the header. Click it to see the changelog. Checked on load and every 30 minutes.

---

## 🌍 Multi-language

The app auto-detects your browser language. Supported: 🇮🇹 Italian, 🇬🇧 English, 🇩🇪 German, 🇫🇷 French, 🇪🇸 Spanish, 🇨🇳 Chinese.

Change the language in **Settings → Language**.

See [Translations](Translations) to add a new language.

---

## ↩ Transaction History & Undo

**Settings → Storico** shows all inventory operations (adds, uses, throws).

- Any operation within the **last 24 hours** shows a red ↩ undo button
- Tapping ↩ shows a 5-second countdown confirmation before reversing the transaction
- The original stock is restored and a counter-transaction is logged

---

## ⚙️ Settings

The settings page is organised in **two levels**, so a panel with ten cards no longer
buries the option you came for:

- **Four sections** across the top (App & AI · Cucina · Avvisi e servizi · Sistema); only
  the **sub-sections** of the active one are listed, wrapped instead of scrolling
  sideways. The section you used last reopens next time.
- **Every card is collapsed** to its heading and first hint line: click to open the
  fields, toggles and test buttons, and **only one card is open at a time**. Jumping
  from the checklist opens the card it points at, and picking an AI provider opens the
  card holding that provider's credentials.
- The **checklist card** at the top lists only what needs a decision — the options that
  are still unconfigured, plus a *Novità* group when an option gained a new question
  since you last saw it. It stays one compact line when there is nothing to decide.
- **▶️ Review the options** re-runs the guided assistant over the open options; ntfy
  notifications and the cron watchdog can be tested *inside* the wizard, before saving.

Full detail: [Configuration](Configuration).

---

## 🔒 Security Features

- API keys never exposed to the browser (`get_settings` returns boolean flags only)
- `save_settings` protected by optional `SETTINGS_TOKEN` (validated with `hash_equals`)
- `DEMO_MODE=true` blocks all write operations at the PHP router level
- Parameterized SQL queries (PDO prepared statements) throughout
- Input validation on all inventory operations (quantity bounds, location whitelist)
- See [Configuration](Configuration) for details
