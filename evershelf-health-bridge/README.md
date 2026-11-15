# EverShelf Health Bridge

Android companion app that reads **Health Connect** on the phone and syncs daily
activity + biometrics into EverShelf for **Fuel Mode** recipes, the diary and the charts.

> Package `it.dadaloop.evershelf.health` · minSdk 28 (Android 9) · targetSdk 35
> Version **2.0.2** (versionCode 22) · **six languages**, the same as EverShelf
> (it / en / de / fr / es / zh)
> Signing: CI refuses to publish a build without the `HEALTH_KEYSTORE_*` secrets, so an
> OTA update always carries the same signature as the installed app.

## Languages

The app ships **the same six locales as EverShelf** — Italiano, English, Deutsch, Français,
Español, 中文 — in `res/values/` + `values-it|de|fr|es|zh/`. The first-run wizard asks the
language (it can be changed there at any time) and every date, number and month name is
formatted with that locale. A string that is missing falls back to English, never to a raw
key; the six files are kept at full parity (244 strings each).

## Updates install IN PLACE — no uninstall, no reinstall

An EverShelf Health Bridge update is meant to land on top of the installed app and keep the
diary, the queue and the pairing. That needs three things, and all three are guaranteed:

| Requirement | How |
|---|---|
| Same app identity | `applicationId = it.dadaloop.evershelf.health` is never renamed |
| Same signing key | CI signs with `HEALTH_KEYSTORE_BASE64`; the certificate is **SHA-256 `36d84a43…cb25`** for every published build (1.0.5 → 2.0.x) |
| Higher `versionCode` | bumped in `app/build.gradle.kts` at every build |

The workflow enforces the last two before publishing anything: `Guard — the APK must update
the published app in place` reads `releases/health-bridge-version.json` (refusing a
`versionCode` that is not greater) and compares the APK's v2 signing certificate with the one
in `releases/evershelf-health-bridge.apk`. A mismatch fails the build with a message that
names the cause, instead of shipping an APK that would ask the household to uninstall.

In the app the same check runs again before `PackageInstaller` is opened: ZIP header, package
name, higher `versionCode` and the **same signing certificate**. Nothing else ever reaches
the installer.

## Download

Always the latest CI build:

**[evershelf-health-bridge.apk](https://github.com/dadaloop82/EverShelf/releases/download/health-bridge-latest/evershelf-health-bridge.apk)**

Release tag: `health-bridge-latest` (published from `main` by
[`.github/workflows/build-health-bridge.yml`](../.github/workflows/build-health-bridge.yml)).
The same APK is also committed to `releases/evershelf-health-bridge.apk` so a LAN-only
install (no internet at all) can update itself from its own server.

## Screens

| Tab | What it does |
|---|---|
| **Today** | Goal rings (steps / burned kcal) and the day's real facts: sleep and its stages, exercise, water, distance, floors, heart rate (avg + resting), HRV, SpO₂, breathing rate, weight, body fat, blood pressure, glucose, temperature, basal metabolism. Bridge status: paired, server online/offline, last sync, queued days, one-tap sync. |
| **Diary** | Every day the bridge has seen, with a **sent / queued / local** chip, its own note and the full metric list. A single day can be sent on its own. |
| **Charts** | History for 17 metrics over 7 / 30 / 90 days, drawn with the Canvas (no chart library): line + soft area, min/avg/max summary, two series for blood pressure. The store is filled from Health Connect the first time a range is opened, then it is instant. |
| **Control** | The control panel: pairing, a live server test, automatic sync and its pace, Wi-Fi-only guard, persistent notification / keep-alive, battery optimisation, Health Connect permissions, the queue, history import, local data reset, log viewer. |
| **Info** | Project identity (repository, **MIT** licence, author), OTA updates, automatic error reporting, a manual report, the local log (show / share / clear) and the privacy statement. |

## The one rule: send only when EverShelf ANSWERS

A phone in the street has internet and no route to a LAN server (or to the VPN that
reaches it). So every upload pass is:

1. **read the phone** and write the day to the local SQLite store (`HealthDb`);
2. **ask the server** (`health_bridge_hello`, 5 s) — if it does not answer, the day stays
   queued and the pass *succeeds*: nothing is lost, nothing is retried in a storm;
3. **upload the queue**, oldest first, until the server acknowledges each day.

Network came back? `NetworkCallback` schedules a pass. Phone rebooted? WorkManager does.
The optional **Wi-Fi-only** switch is an extra guard on metered connections — it never
replaces the server test, which is the actual decision.

Queued days live on the phone, so "outside the house, no VPN" loses nothing; the next pass
sends them all (one `health_ingest` per day, the server merges per date).

## Metrics

Read-only, via Health Connect; every metric is optional and a missing permission only
removes that line:

steps · active/total calories · basal metabolic rate · exercise sessions (+ type) · sleep
(+ deep/light/REM/awake) · weight · height · body fat · lean mass · hydration · heart rate
(avg/min/max) · resting heart rate · HRV (rMSSD) · oxygen saturation · respiratory rate ·
blood pressure · blood glucose · body temperature · distance · floors · speed · power ·
elevation gained.

The server stores the daily aggregates it knows and keeps the whole snapshot in
`health_daily.raw_json`, so a metric the UI grew later is already there.

## First run — the setup wizard

Nothing has to be hunted through menus: on a phone that is not paired yet the app opens a
**setup wizard**, the same way EverShelf does, and asks everything in order — language first.

| Step | Asks for | Where it is stored |
|---|---|---|
| 1 · Language | Italiano / English / Deutsch / Français / Español / 中文 (the current one is ticked) | `app_language`, applied immediately (the step is preserved) |
| 2 · Welcome | what the bridge does and the rule it follows | — |
| 3 · Health Connect | the read permissions (all of them, or only the ones you want) | Android |
| 4 · Link EverShelf | QR scan, **Find EverShelf on Wi-Fi**, or URL + `es_health_…`; Next is accepted only when `health_bridge_hello` really answers | `url` + `token` |
| 5 · Sync options | automatic sync, how often (15–240 min), Wi-Fi-only guard, persistent notification, keep-alive | `auto_sync`, `sync_interval_min`, `wifi_only`, `notifications`, `keepalive` |
| 6 · Goals | steps and burned-calorie targets that drive the Today rings | `steps_goal`, `kcal_goal` |
| 7 · Privacy & diagnostics | automatic error reporting + the battery-optimisation exemption | `auto_report` |
| 8 · Ready | a summary of the pairing and of the choices, **Import recent history** and **Sync now** | — (finishes into Today) |

Every control writes itself the moment it is touched (there is no Save button), so closing the
wizard halfway keeps what was already chosen — and the same values are visible and editable
later in the **Control** panel. The wizard is shown only while the app is **not configured**;
afterwards, *Setup / pairing* in the menu (and *Pair / change server* in Control) reopens it at
the pairing step.

## Updates (OTA)

The Info tab (and a 6-hour throttled check at startup) asks, in this order:

1. **your server** — `GET api/index.php?action=health_bridge_update` (works with no
   internet at all, and the APK is served from the same origin);
2. **GitHub** — `releases/tags/health-bridge-latest` (asset URL must belong to the
   EverShelf repository).

Before anything reaches the installer the APK is proved to be ours: a ZIP header, our
package name, a **higher `versionCode`** and the **same signing certificate** as the
installed app. A mismatch is deleted and reported, never offered.

CI runs the same two checks BEFORE publishing (`releases/health-bridge-version.json` for the
`versionCode`, the v2 certificate of `releases/evershelf-health-bridge.apk` for the key), so a
build that could not install over the installed app never becomes a release at all.

The install runs through `PackageInstaller` with `INSTALL_REASON_USER` (the path Android
14+ accepts for self-update) and a result receiver that logs `STATUS_*` — so a refused
update is a log line, not a silent failure.

## Errors and crashes

- `data/logs/health-YYYY-MM-DD.log` on the server (its own river, next to `app` and
  `kiosk`), written through the public `report_error` action with `source=health_bridge`.
- A GitHub issue when the instance is configured with a GitHub token (`REPORT_ENABLED`) —
  the same deduplicating pipeline the rest of EverShelf uses. The bridge sends
  `context.version_guard_bypass = true` on purpose: that guard compares against the
  *webapp* release tag, and the bridge has its own version line.
- On the phone: `filesDir/health-bridge.log`, readable and shirable from **Info →
  Diagnostics**, and a crash written to SharedPreferences **before** the process dies,
  then sent on the next start.

## Why the old app "closed by itself"

Three real defects, all fixed here:

1. **A foreground service started from `BOOT_COMPLETED`.** Android 15 refuses a `dataSync`
   FGS launched from boot (`ForegroundServiceStartNotAllowedException`), and an exception
   in a receiver is a crash. Boot now schedules WorkManager only; the keep-alive service is
   started exclusively from a visible screen, and a refusal is logged and ignored.
2. **1016 simultaneous requests** in the network discovery (254 hosts × 2 schemes × 2
   paths). Now 24 at a time with a 15 s ceiling.
3. **A rebuilt Activity on every rotation or locale change** (state lost, then a crash on
   the stale binding) and a reader that returned an almost empty payload on the first
   failing metric. The host declares every `configChanges`, and each metric read is
   isolated.

## Build

Do **not** build on the EverShelf server. Use GitHub Actions
(`workflow_dispatch` or a push to `main` that touches the Android tree).

Local Android Studio builds are for development only; production APKs come from CI.

## Privacy

Health data stays on the phone and on your EverShelf server. There is no cloud account,
no advertising SDK and no analytics; uploads happen only when the server answers.

## Project

- Repository: <https://github.com/dadaloop82/EverShelf>
- Licence: **MIT** (same as EverShelf)
- Author: **dadaloop82**
- Built on Health Connect, AndroidX and Material 3.
