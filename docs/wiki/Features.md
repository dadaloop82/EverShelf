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

Tap **🍳 Ricette** → **Genera ricetta** to get a recipe using:
- Ingredients about to expire (prioritised)
- What's currently in your pantry
- Your language preference

Recipes stream live via Server-Sent Events so results appear as they are generated.

### AI Chat Assistant

Open **💬 Chat** to ask questions like:
- "Cosa posso fare con le uova e la pasta?"
- "Quanti giorni dura il prosciutto cotto aperto in frigo?"
- "Suggeriscimi uno spuntino veloce"

The assistant knows your current inventory.

### Shopping Suggestions with Tips

Smart shopping predictions include a short AI-generated practical tip per item (e.g. "Buy the 2 kg bag — it freezes well").

### Anomaly Explanation

When the dashboard shows a suspicious quantity banner, tap **🤖 Spiega** to get a plain-language explanation of why the discrepancy likely occurred and what to do about it.

### Model Fallback

All AI endpoints try `gemini-2.5-flash` first and automatically fall back to `gemini-2.0-flash` if unavailable.

---

## 🛒 Shopping List (Bring! Integration)

Configure `BRING_EMAIL` and `BRING_PASSWORD` in `.env` to enable.

### Features

- **View and manage** your Bring! list inside EverShelf
- **Auto-add on depletion** — when stock hits zero, the product is added to Bring! automatically
- **Auto-remove on scan** — scanning a product in removes it from the shopping list
- **Generic names** — products are grouped by type ("Latte", "Panna da cucina") not brand, keeping the list clean
- **Auto-migration** — items already on Bring! are silently renamed to their generic name on list load
- **Catalog coverage** — 100+ product types mapped to Bring! catalog keys for icons and categories in the Bring! app
- **AI fallback** — unknown product types use Gemini to determine the best generic name

---

## 🍳 Cooking Mode

Start cooking mode from any recipe by tapping **▶ Avvia cottura**.

### Features

- **Step-by-step guidance** — fullscreen, distraction-free interface
- **Text-to-Speech** — each step is read aloud automatically when you navigate; supports:
  - Browser Web Speech API (default)
  - Native Android TTS (kiosk app)
  - Custom REST endpoint (e.g. Home Assistant)
- **Built-in timers** — automatic timer suggestions based on recipe text; 10-second vocal countdown warning before expiry
- **Ingredient tracking** — mark ingredients as used; leftover quantities prompt a "move to another location" flow
- **Recipe completion** — "Buon appetito!" spoken on the last step

---

## 📊 Dashboard

### Inventory Overview

Three stat cards at the top show item counts for Pantry, Fridge, and Freezer with animated skeleton loading while data fetches.

### Expiry Alerts Banner

Priority-sorted notifications for:
- Expired products (with safety assessment — green ✅ safe, amber 👀 check, red 🚫 danger)
- Products expiring within 3 days

Actions per item: Use, Throw away, Edit, Dismiss. Swipe or tap arrows to navigate.

### Anomaly Banner

Highlights suspicious quantities (e.g. "You have 0 eggs but used 12 this month"). Actions:
- One-tap correction to the suggested quantity
- Inline edit with free-form quantity
- "🤖 Spiega" for AI explanation
- Dismiss (with current quantity shown: "La quantità è giusta (2 pz)")

### Anti-Waste Report

Shows your waste rate vs. the national average with an estimated annual kg of food wasted.

### Quick Recipe Bar

One-tap recipe suggestion using the ingredients closest to expiry.

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

The app auto-detects your browser language. Supported: 🇮🇹 Italian, 🇬🇧 English, 🇩🇪 German.

Change the language in **Settings → Language**.

See [Translations](Translations) to add a new language.

---

## ↩ Transaction History & Undo

**Settings → Storico** shows all inventory operations (adds, uses, throws).

- Any operation within the **last 24 hours** shows a red ↩ undo button
- Tapping ↩ shows a 5-second countdown confirmation before reversing the transaction
- The original stock is restored and a counter-transaction is logged

---

## 🔒 Security Features

- API keys never exposed to the browser (`get_settings` returns boolean flags only)
- `save_settings` protected by optional `SETTINGS_TOKEN` (validated with `hash_equals`)
- `DEMO_MODE=true` blocks all write operations at the PHP router level
- Parameterized SQL queries (PDO prepared statements) throughout
- Input validation on all inventory operations (quantity bounds, location whitelist)
- See [Configuration](Configuration) for details
