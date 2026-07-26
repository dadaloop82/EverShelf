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
| [Health Bridge & Fuel Mode](Health) | Phone activity sync and bio-driven recipes |
| [Home Assistant](Home-Assistant) | HACS integration, sensors, services |
| [MCP](MCP) | Model Context Protocol server for AI agents |
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
- **Fuel Mode** — optional bio-driven recipes from Health Bridge activity + your profile
- Predicts what you'll need to buy before you run out
- Integrates with the **Bring!** shopping list app and **Home Assistant** (HACS)
- Supports a **BLE smart scale** for weight-based tracking
- Runs as a **Progressive Web App** installable on any device
- Optionally pairs with a dedicated **Android kiosk tablet app**

All data stays on your server. No cloud, no subscriptions.

---

## 🆕 What's New

### v1.7.13 (2026-05-16)
- **Fix:** Kiosk Settings button (⚙️) added to the web overlay — tapping the camera button no longer accidentally opens kiosk settings
- **Fix:** Opened-item expiry badge is now consistent with the top banner: low-risk items (jams, condiments) show amber ⚠️ "Check soon" instead of misleading red ⛔ "Expired"
- **Cooking Mode:** 3D wheel UI with perspective card flip, ghost steps (prev/next), float animation, and full `prefers-reduced-motion` support
- **CI:** `data/category_ai_cache.json` added to `.gitignore`
- **Critical fix (DB):** Fresh-install crash resolved — `transactions` schema was missing the `undone` column

### v1.7.12 (2026-05-13)
- "Use first" banner now shows opening date and location instead of a confusing calculated expiry
- "Use All / Done" in recipes no longer deletes the inventory row — uses exact quantity instead
- Scan page fully redesigned: 2× zoom, torch, camera flip, 3 input tabs, AI Number OCR, recent products chips
- Anomaly detection: false positives eliminated (untracked direction removed, minimum 5 txn + 7-day span)
- AI price estimation for each Bring! shopping item with real-time dashboard total badge
- Kiosk v1.6.0: BLE scale gateway is now built-in — no separate APK needed
- Complete i18n: 934 keys per language

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
└── evershelf-scale-gateway/    # Android BLE gateway app (Kotlin) — DEPRECATED, built into kiosk since v1.6.0
```

---

## 📄 License

MIT — free to use, modify, and distribute. See [LICENSE](https://github.com/dadaloop82/EverShelf/blob/main/LICENSE).

**Author:** Stimpfl Daniel — [evershelfproject@gmail.com](mailto:evershelfproject@gmail.com)
