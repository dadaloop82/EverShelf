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
| [SHELFY](SHELFY) | The voice assistant: what it understands, the 10 s confirmation, adding words |
| [API Reference](API-Reference) | All REST endpoints, parameters, and responses |
| [Health Bridge & Fuel Mode](Health) | Phone activity sync and bio-driven recipes |
| [Home Assistant](Home-Assistant) | HACS integration, sensors, services |
| [Calendar Feed](Calendar) | ICS/WebCal subscription of expiries for Google/Apple Calendar |
| [MCP](MCP) | Model Context Protocol server for AI agents |
| [Android Kiosk](Android-Kiosk) | Tablet kiosk app setup and usage |
| [Scale Gateway](Scale-Gateway) | BLE smart scale integration |
| [EverShelf Med](Medicines) | Opt-in medicine cabinet: separate domains, offline symptom map, no dosage |
| [Medicine code schemas](Medicines-Country-Schemas) | How a country is added: the printed code, the catalogue, the OCR sample |
| [Support and ads](Support-and-ads) | Optional donations — and why the ad slots stay parked (never a paywall) |
| [Translations](Translations) | Adding and editing language files |
| [Contributing](Contributing) | Development workflow, PR process, and how to pick a task |
| [Roadmap](https://github.com/dadaloop82/EverShelf/blob/develop/docs/ROADMAP.md) | What is next, with an effort tag, and what is open for grabs (also the docs folder) |
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

**v1.33.0 — the idle screen is a dashboard.** Four cards read from your own data (meal &
recipe, what to eat first, pantry charts, an idea of the day), on pure black with EverShelf's
palette, a weather/plan pill under the clock and a footer with the vitals. A tap flips the
card instead of closing it, a card leaves upward and the next comes down from the top, and
*Settings → General → Appearance* chooses which cards to keep and how often they change.

**v1.32.0 — the settings page is a control panel.** Six sections in a rail, four essentials
above them (token, pairing code, backup, version) and **no Save button**: every control writes
itself and the bar at the bottom is the receipt.

**v1.31.35 — a medicine's active ingredient wraps** instead of being cut at the edge of the
cabinet row, and the sheet's facts wrap instead of scrolling sideways.

→ The full history is in the [CHANGELOG](https://github.com/dadaloop82/EverShelf/blob/main/CHANGELOG.md);
the branch that brought these is described in the [README](https://github.com/dadaloop82/EverShelf#-this-branch-is-a-large-refactor).

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
