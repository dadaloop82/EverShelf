# 🏠 EverShelf Wiki

Welcome to the **EverShelf** project wiki — your complete reference for installation, configuration, features, and development.

---

## 🚀 Try it now

> **[▶ Live Demo](https://evershelf.site/demo)** — no installation, no login, full AI enabled  
> **[🌐 Project Website](https://evershelf.site/)**

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
| [Calendar Feed](Calendar) | ICS/WebCal subscription of expiries for Google/Apple Calendar |
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
- **Notifies you about expiring products** without Home Assistant — [ntfy](https://ntfy.sh) or any JSON webhook
- **Subscribes your calendar to the expiries** — ICS/WebCal feed for Google/Apple Calendar
- Supports a **BLE smart scale** for weight-based tracking
- Runs as a **Progressive Web App** installable on any device
- Optionally pairs with a dedicated **Android kiosk tablet app**

All data stays on your server. No cloud, no subscriptions.

---

## 🆕 What's New

### v1.9.1 (2026-10-05)
- **Settings collapse into sub-sections** — one card open at a time, the second-level
  strip wraps instead of scrolling, and the checklist lists only what still needs a
  decision (unconfigured options + *Novità*)
- **Dashboard** — new *overview*, *freshness* and *trend* insight panels, and the spend
  panel now explains a zero instead of hiding
- **Push notifications without Home Assistant** — [ntfy](https://ntfy.sh) or any JSON
  webhook, with a test button that reports the HTTP status
- **Cron watchdog** — a stopped cron job is reported instead of staying silent
- Setup checklist + guided assistant for every configurable option

### v1.9.0 (2026-10-05)
- **Calendar feed (ICS / WebCal)** — subscribe to the pantry expiries in Google/Apple Calendar
- **Recipe → shopping list** — recomputed against the pantry, so you buy only the gap
- **Seasonal produce** — the smart list hides what the current month cannot supply
- **Third CSS layer** (`assets/css/elegant.css`) — a restyle that deletes no selector
- **Client-log hardening** — the public log sink is redacted and bounded

→ See the full [CHANGELOG](https://github.com/dadaloop82/EverShelf/blob/main/CHANGELOG.md)

---

## 📦 Repository Structure

```
EverShelf/
├── index.html                  # Single-page application entry point
├── manifest.json               # PWA manifest
├── sw.js                       # Service worker (offline shell + cache)
├── .env.example                # Configuration template
├── api/
│   ├── index.php               # Main API router + all handlers
│   ├── bootstrap.php           # Shared init (env, security, DB, logger)
│   ├── database.php            # SQLite schema + migrations
│   ├── lib/                    # Domain helpers (security, notify, seasonal, health…)
│   └── cron_smart_shopping.php # Background predictions job
├── assets/
│   ├── css/                    # style.css → corporate.css → elegant.css
│   ├── js/app.js               # SPA logic (assets/js/core/ = auth, dom)
│   └── img/
├── translations/               # i18n JSON files (it, en, de, fr, es, zh)
├── docs/                       # openapi.yaml, code indexes, wiki sources
├── scripts/                    # CI test suite + maintenance CLIs
├── evershelf-kiosk/            # Android kiosk app (Kotlin)
└── evershelf-health-bridge/    # Android Health Connect bridge app (Kotlin)
```

---

## 📄 License

MIT — free to use, modify, and distribute. See [LICENSE](https://github.com/dadaloop82/EverShelf/blob/main/LICENSE).

**Author:** Stimpfl Daniel — [evershelfproject@gmail.com](mailto:evershelfproject@gmail.com)
