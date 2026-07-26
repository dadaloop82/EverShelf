# EverShelf Health Bridge

Android companion app that reads **Health Connect** on the phone and syncs daily activity into EverShelf for **Fuel Mode** recipes.

## What it does

- Pairs with EverShelf via **QR** (Settings → Health) — URL + API token in one scan
- Reads Health Connect aggregates: steps, active/total kcal, workouts, sleep, distance, floors, resting heart rate
- Posts to `health_ingest` on your self-hosted EverShelf server (LAN / same Wi‑Fi)
- **Keep-alive**: foreground notification + optional battery-optimization exemption so OEMs don’t kill sync

## Download

Always the latest CI build:

**[evershelf-health-bridge.apk](https://github.com/dadaloop82/EverShelf/releases/download/health-bridge-latest/evershelf-health-bridge.apk)**

Release tag: `health-bridge-latest` (published from `main` by [`.github/workflows/build-health-bridge.yml`](../.github/workflows/build-health-bridge.yml)).

## Setup

1. In EverShelf open **Settings → Health**, enable health data, save your profile.
2. Tap **Download Health Bridge** (or use the link above) and install the APK (allow unknown sources if needed).
3. Generate the **pairing QR** in EverShelf.
4. Open the app, grant Health Connect permissions, scan the QR.
5. Sync — today’s activity appears under Settings → Health and drives Fuel Mode recipes.

## Build

Do **not** build on the EverShelf server. Use GitHub Actions (`workflow_dispatch` or push to `main` that touches the Android tree).

Local Android Studio builds are for development only; production APKs come from CI.

## Privacy

Health data stays on your phone + your EverShelf server. There is no cloud analytics path in Health Bridge.
