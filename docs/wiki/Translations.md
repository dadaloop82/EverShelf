# 🌍 Translations

EverShelf uses JSON translation files in the `translations/` folder. The app auto-detects the browser language on load and falls back to English.

---

## Currently Supported Languages

| Language | File | Status |
|----------|------|--------|
| 🇮🇹 Italian | `translations/it.json` | ✅ Complete (base language) |
| 🇬🇧 English | `translations/en.json` | ✅ Complete |
| 🇩🇪 German | `translations/de.json` | ✅ Complete |

---

## Adding a New Language

### 1. Copy the base file

```bash
cp translations/it.json translations/fr.json
```

### 2. Translate all values

Open `fr.json` in your editor and translate every **value** (leave the **keys** unchanged).

```json
{
  "app": {
    "name": "EverShelf",
    "loading": "Chargement..."   ← translate this
  },
  "nav": {
    "title": "🏠 EverShelf",    ← keep emoji, translate text
    "home": "Accueil"
  }
}
```

**Rules:**
- Never change the key names (left side of `:`)
- Keep `{placeholder}` tokens unchanged — they are replaced at runtime
  - Example: `"toast.added": "Added {name} to {location}"` — keep `{name}` and `{location}`
- Keep HTML tags if present (rare): `<strong>`, `<br>`
- Keep emojis (they are part of the UX design)
- Plurals: some keys have `_one` / `_many` variants — translate both

### 3. Register the language in the app

Open `assets/js/app.js` and find the `SUPPORTED_LANGUAGES` constant (near the top):

```js
const SUPPORTED_LANGUAGES = ['it', 'en', 'de'];
```

Add your language code:

```js
const SUPPORTED_LANGUAGES = ['it', 'en', 'de', 'fr'];
```

### 4. Add the language to `translations/` badge list

Update the `README.md` badge:

```markdown
[![i18n](https://img.shields.io/badge/i18n-IT%20%7C%20EN%20%7C%20DE%20%7C%20FR-orange.svg)](translations/)
```

### 5. Test

Open the app with `?lang=fr` in the URL to force your language:

```
http://localhost:8080/?lang=fr
```

Check for missing keys — they will show the raw key name in the UI (e.g. `nav.title`).

### 6. Submit a PR

Open a pull request with your new `translations/fr.json` and the updated `app.js` line. See [Contributing](Contributing).

---

## Translation Key Structure

The file is a nested JSON object. Here are the main sections:

| Section | Description |
|---------|-------------|
| `app` | General app strings |
| `nav` | Navigation labels |
| `btn` | Button labels |
| `locations` | Storage location names |
| `categories` | Product category names |
| `dashboard` | Dashboard section titles |
| `inventory` | Inventory page strings |
| `use` | Use/consume form strings |
| `add` | Add product form strings |
| `scan` | Barcode scanner strings |
| `recipes` | Recipe page strings |
| `cooking` | Cooking mode strings |
| `shopping` | Shopping list strings |
| `log` | Transaction log strings |
| `settings` | Settings page strings |
| `scale` | Scale integration strings |
| `toast` | Toast notification messages |
| `error` | Error messages |
| `confirm` | Confirmation dialog strings |

---

## Updating Existing Translations

If a new feature adds keys to `it.json` (the base), you need to add the same keys to `en.json` and `de.json`.

The CI pipeline validates that all language files contain the same keys — a missing key will fail the build.

To check locally:

```bash
node -e "
const it = require('./translations/it.json');
const en = require('./translations/en.json');
// flatten and compare keys...
"
```

Or just open a PR — CI will flag any missing keys automatically.

---

## Language Detection Order

1. `?lang=xx` URL parameter (forces a specific language)
2. `localStorage.getItem('lang')` (last manually selected language)
3. `navigator.language` / `navigator.languages` (browser preference)
4. Fallback: `en`

Users can change the language in **Settings → Language**.
