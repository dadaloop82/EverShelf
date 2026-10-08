# EverShelf Kiosk

Android kiosk app for wall-mounted kitchen tablets. Full-screen WebView wrapper with integrated BLE scale gateway — no external apps required.

> **Version:** 1.7.23 (versionCode 24) — emerald Corporate UI redesign (wizard / settings / splash)  
> **Package:** `it.dadaloop.evershelf.kiosk`  
> **Min SDK:** Android 7.0 (API 24)  
> **Download:** [kiosk-latest APK](https://github.com/dadaloop82/EverShelf/releases/download/kiosk-latest/evershelf-kiosk.apk) (not GitHub “Latest” — that is the web app)  
> **Signing:** CI refuses to publish without `KIOSK_KEYSTORE_*` secrets (stable signature required for OTA).

Pairs with the EverShelf web app **Corporate UI** (v1.7.57+) and later fixes (shopping spend guards v1.7.59+, Gemini usage guards v1.7.60+). The kiosk loads the same SPA; native bridges supply TTS, BLE scale, and kiosk lock.

---

## Features

### Kiosk Mode
- **Full-screen WebView** — immersive mode hides status bar and navigation bar
- **True kiosk lock** — screen pinning (`startLockTask`) blocks home/recent/back buttons
- **Exit button (✕)** — visible in header, requires confirmation dialog to exit kiosk
- **Hard refresh (↻)** — clears WebView cache to pick up web app updates instantly
- **SSL support** — accepts self-signed certificates for local HTTPS servers
- **Update notifications** — checks GitHub releases every 6 hours, shows auto-dismiss banner; webapp can call `checkForUpdates()` / `installUpdate(url)`
- **Settings activity** — change server URL, test connection, re-run setup wizard

### Native TTS bridge (cooking mode)
- **`_kioskBridge.speak(text, rate, pitch)`** — Android `TextToSpeech` on the **main thread** (required since v1.7.20; JS bridge runs on a background thread)
- **`_kioskBridge.stopSpeech()`** — stops current utterance
- **`_kioskBridge.isTtsReady()`** — returns `"true"` / `"false"` for voice readiness
- **Locale** — TTS language follows kiosk setup language (`kiosk_language` pref)
- **Web app behavior** — kiosk tablets prefer the native bridge over server-side TTS (`tts_engine: server`); cooking mode does not depend on Web Speech API voice packs

### BLE Scale Gateway (integrated, no external app)
- **Built-in BLE gateway** — `GatewayService` foreground service handles BLE scanning and connection automatically when a scale is configured
- **WebSocket server** — exposes scale data on `ws://127.0.0.1:8765`, fully protocol-compatible with the legacy standalone gateway app (no webapp JS changes needed)
- **Auto-start** — service starts automatically on kiosk launch if a scale device is configured
- **Auto-reconnect** — reconnects automatically after 8 seconds if the BLE link drops
- **Multi-protocol** — supports Bluetooth SIG Weight Scale (`0x181D`/`0x2A9D`), Body Composition (`0x181B`/`0x2A9C`), QN/Yolanda scales, and 100+ models via generic fallback heuristic

### Setup Wizard (9 steps, emerald Corporate UI)
0. **Language** — Italiano / English / Deutsch / Español / Français
1. **Welcome** — brand intro, privacy (“data stays at home”), what the wizard configures
2. **Permissions** — camera, microphone, BLE with rationale cards
3. **Server URL** — manual URL or LAN auto-discovery; Test uses public `ping`; optional pairing code + keep/reset of server settings
4. **Smart Scale** — optional BLE scale (power-on → scan with retries → confirm weight)
5. **Features** — screensaver, prices, meal-plan, zero-waste (explained; written to the server)
6. **Gemini AI** — optional key + “what it unlocks” card
7. **Bring!** — optional account + “what it does” card
8. **Done** — summary and launch full-screen kiosk

Native settings and the splash screen use the same emerald tokens (`Theme.EverShelf.Kiosk`).

---

## Architecture

```
KioskActivity (WebView — full-screen EverShelf)
    ├── SetupActivity (9-step wizard, shown on first launch only)
    ├── SettingsActivity (URL, scale status, screensaver, re-run wizard)
    ├── Immersive mode (SYSTEM_UI_FLAG_IMMERSIVE_STICKY)
    ├── Screen pinning (startLockTask / stopLockTask)
    ├── JS bridge (_kioskBridge)
    │       ├── exit, hardReload
    │       ├── speak, stopSpeech, isTtsReady
    │       ├── checkForUpdates, installUpdate
    │       ├── reconfigureScale, openSettings, setNativeSettingsVisible
    └── GatewayService (foreground service — BLE + WebSocket)
            ├── BleScaleManager   — BLE scanning, GATT, auto-reconnect
            ├── GatewayWebSocketServer — WebSocket server :8765
            └── ScaleProtocol     — multi-protocol BLE weight parser
```

The kiosk app is fully self-contained. No separate gateway app is required.

---

## Setup

1. Install the **EverShelf Kiosk** APK from [GitHub Releases](https://github.com/dadaloop82/EverShelf/releases/download/kiosk-latest/evershelf-kiosk.apk) (or Settings → EverShelf Kiosk → download from this server)
2. Launch the app — the setup wizard starts automatically
3. Choose your language
4. Read the welcome / privacy cards, then grant camera, microphone and Bluetooth when prompted
5. Enter your EverShelf server URL (e.g. `https://192.168.1.100/dispensa`) or use auto-discovery; Test connection, then pair if asked (code on a paired device under Settings → System → Security)
6. Optional: configure the BLE scale, feature toggles, Gemini, Bring!
7. Done — the web app loads in full-screen kiosk mode with Corporate UI styling

### Scale Configuration

BLE scale setup happens inside the kiosk app itself — **no external app needed**:

- During the **setup wizard (step 4)**, turn the scale on, scan for nearby BLE scales, tap yours (⭐ marks likely scales), then confirm a weight reading.
- From the **Settings screen**, you can restart the BLE service or reconfigure the scale device.

### Exiting Kiosk Mode

Tap the **✕** button in the header. A confirmation dialog appears — tap **"Exit"** to confirm.

---

## Permissions

| Permission | Purpose |
|---|---|
| `INTERNET` | Load EverShelf web app |
| `ACCESS_NETWORK_STATE` | Check connectivity |
| `ACCESS_WIFI_STATE` | LAN subnet detection for auto-discovery |
| `WAKE_LOCK` | Keep screen on |
| `CAMERA` | Barcode scanning, AI photo identification |
| `RECORD_AUDIO` | Voice input in chat assistant |
| `READ_MEDIA_IMAGES` / `READ_EXTERNAL_STORAGE` | Image access for AI scan |
| `REORDER_TASKS` | Bring kiosk to foreground |
| `BLUETOOTH` / `BLUETOOTH_ADMIN` | BLE (Android ≤ 11) |
| `BLUETOOTH_SCAN` / `BLUETOOTH_CONNECT` | BLE scan and connect (Android 12+) |
| `ACCESS_FINE_LOCATION` | Required for BLE scan on Android < 12 |
| `FOREGROUND_SERVICE` | Run BLE gateway as foreground service |
| `FOREGROUND_SERVICE_CONNECTED_DEVICE` | Service type for BLE (Android 14+) |

---

## Supported Scale Protocols

| Protocol | Service UUID | Notes |
|---|---|---|
| **Bluetooth SIG Weight Scale** | `0x181D` / char `0x2A9D` | Most compatible |
| **Bluetooth SIG Body Composition** | `0x181B` / char `0x2A9C` | Weight + body fat %, BMI |
| **QN/Yolanda** | Custom UUIDs | Xiaomi Mi Scale 2, Renpho, etc. |
| **Generic fallback** | Any notifiable characteristic | Auto-heuristic parsing for 100+ models |

### Verified compatible scales
- Xiaomi Mi Body Composition Scale 2
- Renpho Smart Body Fat Scale
- INEVIFIT Smart Body Fat Scale
- Any [openScale-compatible scale](https://github.com/oliexdev/openScale/wiki/Supported-scales)

---

## WebSocket Protocol

The built-in WebSocket server speaks the same protocol as the legacy standalone gateway app — the EverShelf webapp needs no changes.

**Server → client:**
```json
{"type":"status","state":"connected","device":"Mi Scale 2","battery":85}
{"type":"status","state":"disconnected"}
{"type":"weight","value":72.50,"unit":"kg","stable":true,"timestamp":1712345678000}
{"type":"pong"}
```

**Client → server:**
```json
{"type":"get_status"}
{"type":"get_weight"}
{"type":"ping"}
```

---

## Building

CI builds release APKs on push to `main` when `evershelf-kiosk/**` changes ([workflow](https://github.com/dadaloop82/EverShelf/actions/workflows/kiosk.yml)).

Local build:

```bash
cd evershelf-kiosk
./gradlew assembleDebug
# APK at app/build/outputs/apk/debug/app-debug.apk
```

For release:
```bash
./gradlew assembleRelease
```

---

## Requirements

- Android 7.0+ (API 24)
- Bluetooth LE support (for scale integration)
- Network access to EverShelf server

---

## License

MIT — see [LICENSE](../LICENSE)
