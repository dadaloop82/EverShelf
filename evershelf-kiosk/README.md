# EverShelf Kiosk

Android kiosk app for wall-mounted kitchen tablets. A pure full-screen WebView wrapper that displays the EverShelf web interface in immersive mode — no BLE, no gateway, just the web app locked to screen.

> **Version:** 1.2.0 (versionCode 3)  
> **Package:** `it.dadaloop.evershelf.kiosk`

## Features

- **Full-screen WebView** — immersive mode hides status bar and navigation bar
- **True kiosk lock** — screen pinning (`startLockTask`) blocks home/recent/back buttons
- **3-step setup wizard** — Welcome → Server URL (with connection test) → Scale Gateway detection
- **Gateway auto-launch** — starts [EverShelf Scale Gateway](../evershelf-scale-gateway/) in background on boot
- **Exit button (✕)** — visible in header, requires confirmation dialog to exit kiosk
- **Hard refresh (↻)** — clears WebView cache to pick up web app updates instantly
- **Camera & microphone** — runtime permission handling for barcode scanning and voice
- **SSL support** — accepts self-signed certificates for local HTTPS servers
- **Splash screen** — branded 1.5-second splash on startup
- **Update notifications** — checks GitHub releases every 6 hours, shows auto-dismiss banner
- **Settings activity** — change server URL, test connection, re-run setup wizard
- **Error recovery** — retry page when server is unreachable

## Architecture

```
KioskActivity (WebView — full-screen EverShelf)
    ├── Setup wizard (3 steps, shown on first launch only)
    ├── Immersive mode (SYSTEM_UI_FLAG_IMMERSIVE_STICKY)
    ├── Screen pinning (startLockTask / stopLockTask)
    ├── JS bridge (_kioskBridge: exit, hardReload)
    ├── Header injection (✕ exit + ↻ refresh buttons)
    └── Gateway launcher (launches gateway APK in background)
            ↓ FLAG_ACTIVITY_NEW_TASK
        EverShelf Scale Gateway (separate app, runs in background)
```

The kiosk app does **not** contain any BLE or scale code. Scale functionality is handled entirely by the separate [EverShelf Scale Gateway](../evershelf-scale-gateway/) app.

## Setup

1. Install both APKs on your Android tablet:
   - **EverShelf Kiosk** (this app)
   - **[EverShelf Scale Gateway](../evershelf-scale-gateway/)** (optional, for smart scale support)
2. Launch the kiosk app — the setup wizard starts automatically
3. Enter your EverShelf server URL (e.g. `https://192.168.1.100/dispensa`)
4. The wizard tests the connection and detects the gateway app
5. Done — the web app loads in full-screen kiosk mode

### Exiting Kiosk Mode

Tap the **✕** button in the header (left of the title). A confirmation dialog appears — tap "Esci" to exit.

### Triple-tap (Developer)

Triple-tap the setup wizard title to access hidden settings.

## Permissions

| Permission | Purpose |
|---|---|
| `INTERNET` | Load EverShelf web app |
| `ACCESS_NETWORK_STATE` | Check connectivity |
| `ACCESS_WIFI_STATE` | WiFi status |
| `WAKE_LOCK` | Keep screen on |
| `CAMERA` | Barcode scanning, AI photo identification |
| `RECORD_AUDIO` | Voice input in chat assistant |
| `READ_MEDIA_IMAGES` / `READ_EXTERNAL_STORAGE` | Image access for AI scan |
| `REORDER_TASKS` | Bring kiosk to foreground after gateway launch |

## Building

```bash
cd evershelf-kiosk
./gradlew assembleDebug
# APK at app/build/outputs/apk/debug/app-debug.apk
```

For release builds:
```bash
./gradlew assembleRelease
```

## Requirements

- Android 7.0+ (API 24)
- Network access to EverShelf server
- EverShelf Scale Gateway app (optional, for smart scale support)

## License

GPLv3 — see [LICENSE](../LICENSE)
