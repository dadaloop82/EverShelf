# 🏠 EverShelf

> Self-hosted pantry manager — inventory, barcode scan, AI recipes, less waste.

<div align="center">

**[Website](https://evershelf.site/)** · **[Demo](https://evershelf.site/demo)** · **[Wiki](https://github.com/dadaloop82/EverShelf/wiki)** · **[Changelog](CHANGELOG.md)**

[![MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE) [![PHP 8+](https://img.shields.io/badge/PHP-8.0+-blue.svg)](https://www.php.net/) [![Version](https://img.shields.io/badge/version-1.7.94-brightgreen.svg)](CHANGELOG.md) [![CI](https://github.com/dadaloop82/EverShelf/actions/workflows/ci.yml/badge.svg)](https://github.com/dadaloop82/EverShelf/actions/workflows/ci.yml)

</div>

```bash
git clone https://github.com/dadaloop82/EverShelf.git && cd EverShelf
cp .env.example .env && docker compose up -d    # → http://localhost:8080
```

<details>
<summary><b>✨ Features</b></summary>

| | |
|---|---|
| **Inventory** | Scan, locations, expiry, opened packs, favourites, CSV import/export |
| **AI** | Gemini · OpenAI · Llama — identify, OCR expiry, recipes, chat |
| **Shopping** | Smart list, anti-waste qty, optional [Bring!](https://www.getbring.com/) |
| **Cooking** | Steps, TTS, timers, zero-waste tips |
| **PWA** | Offline queue, installable, multi-device |

**Integrations:** [Home Assistant](https://github.com/dadaloop82/ha-evershelf) · [MCP](mcp-server/README.md) · [Health Bridge](evershelf-health-bridge/README.md) · [Kiosk](evershelf-kiosk/README.md)

**Languages:** IT · EN · DE · FR · ES · ZH — details in [wiki → Features](https://github.com/dadaloop82/EverShelf/wiki/Features)

</details>

<details>
<summary><b>📰 What's new</b></summary>

**v1.7.94** — Hotfix: kiosk startup (i18n) + spesa scan resume after spend modal  **v1.7.93** — Spesa mode UX + inventory swipe Use/Discard + opened section  **v1.7.92** — i18n cleanup: all UI via translation keys (1853 × 6 langs)  
**v1.7.91** — fix depleted crumbs (e.g. 19 g butter) stuck in Opened alerts  
**v1.7.90** — `spend_stats` dashboard crash fix  

→ [CHANGELOG.md](CHANGELOG.md)

</details>

<details>
<summary><b>⚙️ Install & config</b></summary>

**Docker** (recommended): `docker compose pull && docker compose up -d`

**Manual:** clone → `cp .env.example .env` → `chmod 755 data/` → point web server at repo root.

**Minimum `.env`:**

```ini
AI_ENABLED=true
AI_PROVIDER=gemini
GEMINI_API_KEY=your_key
```

**Optional:** `API_TOKEN`, `SHOPPING_MODE=bring`, cron every 5 min on `api/cron_smart_shopping.php`

→ [Wiki: Installation](https://github.com/dadaloop82/EverShelf/wiki/Installation) · [Configuration](https://github.com/dadaloop82/EverShelf/wiki/Configuration)

</details>

<details>
<summary><b>🔌 Integrations & API</b></summary>

| | Link |
|---|---|
| Home Assistant | [ha-evershelf](https://github.com/dadaloop82/ha-evershelf) |
| MCP agents | [mcp-server/README.md](mcp-server/README.md) |
| Health / Fuel Mode | [evershelf-health-bridge/README.md](evershelf-health-bridge/README.md) |
| Android kiosk + scale | [evershelf-kiosk/README.md](evershelf-kiosk/README.md) |
| REST API | [Wiki → API](https://github.com/dadaloop82/EverShelf/wiki/API-Reference) |

</details>

<details>
<summary><b>🏗️ Architecture & security</b></summary>

`index.html` SPA · `api/index.php` · `assets/js/app.js` · SQLite in `data/`

→ [ARCHITECTURE.md](docs/ARCHITECTURE.md) · [CORPORATE-UI.md](docs/CORPORATE-UI.md) · [SECURITY.md](SECURITY.md)

</details>

<details>
<summary><b>🤝 Contributing</b></summary>

[CONTRIBUTING.md](CONTRIBUTING.md) · [Issues](https://github.com/dadaloop82/EverShelf/issues) · [Discussions](https://github.com/dadaloop82/EverShelf/discussions) · [Roadmap](https://github.com/users/dadaloop82/projects/2)

Add a language: copy `translations/en.json`, translate values, open a PR.

</details>

<details>
<summary><b>📸 Demo</b></summary>

<div align="center">

![Demo](assets/img/demo.gif)

[Live demo](https://evershelf.site/demo) — no install, full AI.

</div>

</details>

---

MIT · [Stimpfl Daniel](https://evershelf.site/) · [@dadaloop82](https://github.com/dadaloop82)

*Unrelated iOS app “EverShelf” by Joshumi Technologies — no affiliation.*
