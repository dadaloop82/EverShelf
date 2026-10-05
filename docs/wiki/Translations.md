# 🌍 Translations

EverShelf uses JSON translation files in the `translations/` folder. The app auto-detects the browser language on load and falls back to English.

---

## Currently Supported Languages

| Language | File | Status |
|----------|------|--------|
| 🇮🇹 Italian | `translations/it.json` | ✅ Complete (base language, 2184 keys) |
| 🇬🇧 English | `translations/en.json` | ✅ Complete — the fallback for every other locale |
| 🇩🇪 German | `translations/de.json` | ✅ Complete |
| 🇫🇷 French | `translations/fr.json` | ✅ Complete |
| 🇪🇸 Spanish | `translations/es.json` | ✅ Complete |
| 🇨🇳 Chinese (simplified) | `translations/zh.json` | ✅ Complete |

`python3 scripts/i18n-audit.py` reports the current count and exits `1` the moment a
locale falls behind; `python3 scripts/i18n-value-audit.py` lists the keys whose value is
still English.

---

## Adding a New Language

### 1. Copy the base file

```bash
cp translations/it.json translations/pt.json
```

### 2. Translate all values

Open `pt.json` in your editor and translate every **value** (leave the **keys** unchanged).

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
- Keep emojis (they are part of the UX design) — and **never** print an extra icon next
  to a translated label in code: the value already carries it. Use `iconLabel(icon, key)`
  in JS / `data-i18n` in HTML; `php scripts/test-i18n-icons.php` fails on a doubled icon
  in any of the six locales.
- Plurals: some keys have `_one` / `_many` variants — translate both

### 3. Register the language in the app

Open `assets/js/app.js` and add the code + native name to `_SUPPORTED_LANGS` (~line 1165):

```js
const _SUPPORTED_LANGS = { it: 'Italiano', en: 'English', de: 'Deutsch', fr: 'Français', es: 'Español', zh: '简体中文' };
```

That object drives both the detection allow-list and the language `<select>` in
*Settings → 🌐 Language* (`_populateLanguageSelector()`), so no other file needs editing.

### 4. Check the coverage

```bash
python3 scripts/i18n-audit.py          # keys used in code but missing in a locale → exit 1
python3 scripts/i18n-value-audit.py    # keys whose value is still English (report)
```

### 5. Test

Pick the new language in *Settings → 🌐 Language* (the page reloads), or set the stored
key by hand:

```js
localStorage.setItem('evershelf_lang', 'pt'); location.reload();
```

Missing keys show the raw key name in the UI (e.g. `nav.title`), and `t()` falls back to
English rather than to Italian for any non-Italian locale.

### 6. Submit a PR

Open a pull request with your new `translations/pt.json` and the updated `app.js` line.
See [Contributing](Contributing).

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

If a new feature adds keys to `it.json` (the base), the same keys must be added to the
other five files (`en`, `de`, `fr`, `es`, `zh`).

Locally:

```bash
python3 scripts/i18n-audit.py        # keys used in code but missing in a locale (exit 1)
python3 scripts/i18n-value-audit.py  # keys whose value is still English
php scripts/test-i18n-icons.php      # no label prints its icon twice, in any locale
```

In CI, the *Validate Translation Files* job checks that every file is valid JSON and that
no locale is missing a **top-level** section present in `it.json`. The full nested audit
(`i18n-audit.py`) runs locally until it is wired into CI, so run it before pushing.

---

## Language Detection Order

1. `localStorage.getItem('evershelf_lang')` — the language the user last picked
   (written by `changeLanguage()`; the setup wizard sets it on first run)
2. `navigator.language` / `navigator.languages` (browser preference, first two letters)
3. Fallback: `en` — also when the detected code is not in `_SUPPORTED_LANGS`

Users can change the language in **Settings → 🌐 Language** (the page reloads).
