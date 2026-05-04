# 🏠 EverShelf Wiki

Welcome to the **EverShelf** project wiki — your complete reference for installation, configuration, features, and development.

---

## 🚀 Try it now

> **[▶ Live Demo](https://evershelfproject.dadaloop.it/demo)** — no installation, no login, full AI enabled  
> **[🌐 Project Website](https://evershelfproject.dadaloop.it/)**

---

## 📚 Wiki Contents

| Page | Description |
|------|-------------|
| [Installation](Installation) | Docker, manual setup, HTTPS, web server config |
| [Configuration](Configuration) | `.env` reference — all options explained |
| [Features](Features) | Complete feature documentation |
| [API Reference](API-Reference) | All REST endpoints, parameters, and responses |
| [Android Kiosk](Android-Kiosk) | Tablet kiosk app setup and usage |
| [Scale Gateway](Scale-Gateway) | BLE smart scale integration |
| [Translations](Translations) | Adding and editing language files |
| [Contributing](Contributing) | Development workflow and PR process |
| [FAQ & Troubleshooting](FAQ) | Common issues and solutions |

---

## ✨ What is EverShelf?

EverShelf is a **self-hosted pantry management system** that runs entirely on your own server. It:

- Tracks food inventory across multiple storage locations (pantry, fridge, freezer, custom)
- Scans barcodes and uses **Google Gemini AI** to identify products from photos
- Suggests recipes based on what's in your pantry — especially items about to expire
- Predicts what you'll need to buy before you run out
- Integrates with the **Bring!** shopping list app
- Supports a **BLE smart scale** for weight-based tracking
- Runs as a **Progressive Web App** installable on any device
- Optionally pairs with a dedicated **Android kiosk tablet app**

All data stays on your server. No cloud, no subscriptions.

---

## 🆕 What's New

### v1.7.1 (2026-05-04)
- Destructive actions ("Butta tutto", "Finisci tutto") now require a **5-second countdown confirmation** before executing
- History undo button ↩ is now clearly visible (red tint, larger)
- Undo confirmation uses the in-app modal instead of the native browser `confirm()`

### v1.7.0 (2026-05-04)
- Smart auto-discovery rewrite (kiosk)
- Gateway auto-pre-configuration after install
- ErrorReporter init at setup start
- Graceful Bring! no-key state
- Use-quantity guard with shake animation
- Demo mode (`?demo=1`)

→ See the full [CHANGELOG](https://github.com/dadaloop82/EverShelf/blob/main/CHANGELOG.md)

---

## 📦 Repository Structure

```
EverShelf/
├── index.html                  # Single-page application entry point
├── manifest.json               # PWA manifest
├── .env.example                # Configuration template
├── api/
│   ├── index.php               # Main API router
│   ├── database.php            # SQLite schema + migrations
│   └── cron_smart_shopping.php # Background predictions job
├── assets/
│   ├── css/style.css
│   ├── js/app.js
│   └── img/
├── translations/               # i18n JSON files (it, en, de)
├── docs/openapi.yaml           # OpenAPI 3.0 spec
├── evershelf-kiosk/            # Android kiosk app (Kotlin)
└── evershelf-scale-gateway/    # Android BLE gateway app (Kotlin)
```

---

## 📄 License

MIT — free to use, modify, and distribute. See [LICENSE](https://github.com/dadaloop82/EverShelf/blob/main/LICENSE).

**Author:** Stimpfl Daniel — [evershelfproject@gmail.com](mailto:evershelfproject@gmail.com)
