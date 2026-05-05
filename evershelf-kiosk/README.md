# EverShelf Kiosk

Android kiosk app for wall-mounted kitchen tablets. Full-screen WebView wrapper with integrated BLE scale gateway — no external apps required.

> **Version:** 1.6.0 (versionCode 10)
> **Package:** `it.dadaloop.evershelf.kiosk`
> **Min SDK:** Android 7.0 (API 24)

---

## Features

### Kiosk Mode
- **Full-screen WebView** — immersive mode hides status bar and navigation bar
- **True kiosk lock** — screen pinning (`startLockTask`) blocks home/recent/back buttons
- **Exit button (✕)** — visible in header, requires confirmation dialog to exit kiosk
- **Hard refresh (↻)** — clears WebView cache to pick up web app updates instantly
- **SSL support** — accepts self-signed certificates for local HTTPS servers
- **Update notifications** — checks GitHub releases every 6 hours, shows auto-dismiss banner
- **Native TTS bridge** — cooking mode voice readout uses Android TextToSpeech directly
- **Settings activity** — change server URL, test connection, re-run setup wizard

### BLE Scale Gateway (integrated, no external app)
- **Built-in BLE gateway** — `GatewayService` foreground service handles BLE scanning and connection automatically when a scale is configured
- **WebSocket server** — exposes scale data on `ws://127.0.0.1:8765`, fully protocol-compatible with the legacy standalone gateway app (no webapp JS changes needed)
- **Auto-start** — service starts automatically on kiosk launch if a scale device is configured
- **Auto-reconnect** — reconnects automatically after 8 seconds if the BLE link drops
- **Multi-protocol** — supports Bluetooth SIG Weight Scale (`0x181D`/`0x2A9D`), Body Composition (`0x181B`/`0x2A9C`), QN/Yolanda scales, and 100+ models via generic fallback heuristic

### Setup Wizard (6 steps)
1. **Language** — choose Italian / English / German
2. **Welcome** — intro and privacy information
3. **Permissions** — camera, microphone, BLE permissions with in-wizard grant flow
4. **Server URL** — enter your EverShelf URL; auto-discovery scans the LAN (60 parallel threads, ports 80/443/8080/8443)
5. **Smart Scale** — optional: scan for BLE scales and select yours from the discovered device list (mandatory before proceeding if you choose "yes")
6. **Screensaver** — toggle display sleep after inactivity

---

## Architecture

```
KioskActivity (WebView — full-screen EverShelf)
    ├── SetupActivity (6-step wizard, shown on first launch only)
    ├── SettingsActivity (URL, scale status, re-run wizard)
    ├── Immersive mode (SYSTEM_UI_FLAG_IMMERSIVE_STICKY)
    ├── Screen pinning (startLockTask / stopLockTask)
    ├── JS bridge (_kioskBridge: exit, hardReload)
    └── GatewayService (foreground service — BLE + WebSocket)
            ├── BleScaleManager   — BLE scanning, GATT, auto-reconnect
            ├── GatewayWebSocketServer — WebSocket server :8765
            └── ScaleProtocol     — multi-protocol BLE weight parser
```

The kiosk app is fully self-contained. No separate gateway app is required.

---

## Setup

1. Install the **EverShelf Kiosk** APK on your Android tablet
2. Launch the app — the setup wizard starts automatically
3. Choose your language
4. Grant camera, microphone and Bluetooth permissions when prompted
5. Enter your EverShelf server URL (e.g. `https://192.168.1.100/dispensa`) or use auto-discovery
6. If you have a Bluetooth scale: tap **"Sì, ho una bilancia"**, wait for the BLE scan, then tap your scale in the list
7. Done — the web app loads in full-screen kiosk mode

### Scale Configuration

BLE scale setup happens inside the kiosk app itself — **no external app needed**:

- During the **setup wizard (step 5)**, the app scans for nearby BLE scales and shows them in a list. Devices most likely to be scales are marked with ⭐.
- Tap a device to select it. The selection is saved and the "Next" button becomes enabled.
- From the **Settings screen**, you can restart the BLE service or reconfigure the scale device.

### Exiting Kiosk Mode

Tap the **✕** button in the header. A confirmation dialog appears — tap "Esci" to exit.

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

