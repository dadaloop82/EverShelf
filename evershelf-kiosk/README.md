# EverShelf Kiosk

Android kiosk app that displays the EverShelf web interface in full-screen mode while running the Smart Scale BLE Gateway as a background service.

## Features

- **Full-screen WebView** — displays EverShelf in immersive kiosk mode (no status bar, no navigation)
- **Built-in Scale Gateway** — BLE connection to smart scales with WebSocket server on port 8765
- **Auto-reconnect** — automatically reconnects to the last connected scale
- **Foreground service** — gateway runs even when the screen is off
- **Camera pass-through** — allows barcode scanning from within the WebView
- **Error recovery** — shows retry page when the server is unreachable

## Setup

1. Install the APK on your Android tablet/phone
2. On first launch, grant Bluetooth and Location permissions
3. Tap the subtle ⚙️ icon in the top-right corner to configure the EverShelf server URL
4. In EverShelf settings, set the Scale Gateway URL to `ws://localhost:8765`

## Architecture

```
KioskActivity (WebView — full-screen EverShelf)
    ↕ binds to
ScaleGatewayService (foreground service)
    ├── BleScaleManager (BLE scanning + connection)
    │   └── ScaleProtocol (multi-protocol weight parser)
    └── GatewayWebSocketServer (port 8765)
            ↕ WebSocket
        WebView (EverShelf JavaScript connects to ws://localhost:8765)
```

## Building

```bash
cd evershelf-kiosk
./gradlew assembleDebug
# APK at app/build/outputs/apk/debug/app-debug.apk
```

## Requirements

- Android 7.0+ (API 24)
- Bluetooth Low Energy support
- Network access to EverShelf server
