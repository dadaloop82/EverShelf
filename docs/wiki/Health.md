# Health Bridge & Fuel Mode

EverShelf can use **phone activity** (via Health Connect) to drive **Fuel Mode** recipes —
meals sized to your goal and today's burn, using only pantry stock.

## Concepts

| Piece | Role |
|-------|------|
| **Settings → Health** | Biological profile, master enable switch, bridge pairing / download |
| **EverShelf Health Bridge** | Android app (package `it.dadaloop.evershelf.health`) that reads Health Connect, keeps a local diary and POSTs daily aggregates to EverShelf |
| **Fuel Mode** | Recipe option that budgets kcal/protein from profile + today's activity |
| **Silent intake** | Pantry "use" and cooked recipes contribute estimated intake — no separate meal diary |
| **Remaining-day budget** | Fuel Mode subtracts today's logged intake and splits the leftover across meals still ahead |

## The app (2.0)

The bridge was rebuilt around five tabs:

| Tab | Contents |
|---|---|
| **Today** | Goal rings (steps / burned kcal), sleep + stages, exercise, water, distance, floors, heart rate, resting HR, HRV, SpO₂, breathing rate, weight, body fat, blood pressure, glucose, temperature, basal metabolism. Bridge status: paired, server online/offline, last sync, queued days, one-tap sync. |
| **Diary** | Every stored day with a **sent / queued / local** chip, its own note and the full metric list; a single day can be sent on its own. |
| **Charts** | 17 metrics over 7 / 30 / 90 days (Canvas line chart, min/avg/max, two series for blood pressure). |
| **Control** | Pairing, live server test, automatic sync + pace, Wi-Fi-only guard, keep-alive notification, battery optimisation, Health Connect permissions, queue, history import, local reset, log viewer. |
| **Info** | Repository, licence (**MIT**), author, OTA updates, automatic error reporting, manual report, local log (show / share / clear), privacy. |

### Languages and updates

The app ships **the same six locales as EverShelf** (it / en / de / fr / es / zh) at full
parity — the first-run wizard asks the language and formats every date and number with it.

Updates install **in place**: same `applicationId`, same signing key (SHA-256
`36d84a43…cb25` for every published build) and a higher `versionCode`. The build workflow
refuses to publish an APK whose `versionCode` is not greater than
`releases/health-bridge-version.json` or whose v2 certificate differs from the published
APK (`releases/evershelf-health-bridge.apk`), and the app repeats the same four proofs
(ZIP, package, versionCode, certificate) before opening `PackageInstaller`. So an update
never asks for an uninstall — the diary, the queue and the pairing survive.

### "Send only when EverShelf answers"

A phone in the street has internet and **no route** to a LAN server (or to the VPN that
reaches it), so every upload pass is:

1. read the phone → write the day to the local SQLite queue (`HealthDb`);
2. `health_bridge_hello` (5 s) — no answer means the day stays queued and the pass is a
   **successful deferral**, not a failure;
3. upload the queue oldest-first, one `health_ingest` per day (the server merges per date).

A returned connection schedules a pass (`NetworkCallback`), a reboot schedules WorkManager.
The optional **Wi-Fi-only** switch is an extra guard; the server test is still what decides.

### Updates (OTA)

1. the LAN server: `health_bridge_update` served from `releases/evershelf-health-bridge.apk`
   (works with no internet at all);
2. GitHub: release tag `health-bridge-latest`.

The APK is installed only after a ZIP + package name + **higher versionCode** + **same signing
certificate** check, through `PackageInstaller` (`INSTALL_REASON_USER`) with a result receiver
that logs `STATUS_*`.

### Diagnostics

The bridge reports through the public `report_error` action with `source=health_bridge`, so
the server writes its own river:

```
data/logs/health-YYYY-MM-DD.log      [ts] [ERROR] [health_bridge] sync_failed: …
data/error_reports.log               every report, GitHub off included
```

With `REPORT_ENABLED` + a GitHub token the backend opens or comments on an issue exactly like
the webapp and the kiosk (the bridge sends `version_guard_bypass=true` because it has its own
version line). On the phone the same lines live in `filesDir/health-bridge.log`, shirable from
**Info → Diagnostics**; a crash is persisted **before** the process dies and sent on the next
start.

## Download

[evershelf-health-bridge.apk](https://github.com/dadaloop82/EverShelf/releases/download/health-bridge-latest/evershelf-health-bridge.apk)

## Pairing and first run

1. Enable Health in EverShelf and save your profile.
2. Install the APK on an Android phone with Health Connect (Android 14+ has it built in).
3. Generate the pairing QR (URL + token) in **Settings → Health**.
4. The app opens its **setup wizard** — language first, then permissions, the pairing
   (QR / Wi-Fi discovery / manual), the sync options, the goals and the diagnostics switches —
   and only accepts the pairing when `health_bridge_hello` really answers.
5. At the end it offers **Import recent history** and **Sync now**, then lands on Today.

The wizard runs only on a phone that is not paired yet; later, *Setup / pairing* reopens it at
the pairing step and the control panel holds every option.

Details: [`evershelf-health-bridge/README.md`](https://github.com/dadaloop82/EverShelf/blob/main/evershelf-health-bridge/README.md)

## API

| Action | Auth | Purpose |
|--------|------|---------|
| `health_status` | API token | Profile + today's daily row + bridge state |
| `health_profile_save` | API token | Save biological profile / enable flag |
| `health_ingest` | `X-Health-Token` | Bridge posts daily metrics (merges per date; unknown fields stay in `raw_json`) |
| `health_bridge_token_create` | API token | Issue pairing token + QR payload |
| `health_bridge_qr` | API token | Render the pairing QR locally (`api/lib/qr.php`) |
| `health_bridge_hello` | public | Discovery + reachability probe (no token, no DB) |
| `health_bridge_update` | public | LAN OTA metadata (`releases/health-bridge-version.json`) |
| `health_unlink` | API token | Revoke bridge tokens |

See [API Reference](API-Reference) for request/response shapes.
