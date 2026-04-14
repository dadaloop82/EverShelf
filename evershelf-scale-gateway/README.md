# EverShelf Scale Gateway

> Android gateway app that bridges Bluetooth LE smart scales with EverShelf via WebSocket.

---

## How it works

```
Smart Scale ──(BLE)──► Android Gateway App ──(WebSocket/LAN)──► EverShelf (browser)
```

The app runs a local WebSocket server (port **8765**) on your Android device. EverShelf connects to it over your home Wi-Fi and receives weight readings in real time.

---

## Supported scale protocols

| Protocol | Service UUID | Notes |
|---|---|---|
| **Bluetooth SIG Weight Scale** | `0x181D` / char `0x2A9D` | Most compatible; works with most smart scales |
| **Bluetooth SIG Body Composition** | `0x181B` / char `0x2A9C` | Reports weight + body fat %, BMI |
| **Generic fallback** | Any notifiable characteristic | Auto-heuristic parsing for 100+ models |

### Verified compatible scales (community list)
- Xiaomi Mi Body Composition Scale 2
- Renpho Smart Body Fat Scale
- INEVIFIT Smart Body Fat Scale
- Any OpenScale-compatible scale (see [openScale supported devices](https://github.com/oliexdev/openScale/wiki/Supported-scales))

> **Your scale (B09MRXVBV6):** If it implements the standard BLE Weight Scale or Body Composition profile (very likely for modern Amazon smart scales), the gateway will connect automatically. If not, check the [openScale wiki](https://github.com/oliexdev/openScale/wiki/Supported-scales) and open an issue.

---

## Download

Download the latest APK directly: **[evershelf-scale-gateway.apk](https://github.com/dadaloop82/EverShelf/releases/latest/download/evershelf-scale-gateway.apk)**

---

## Requirements

- Android **7.0** (API 24) or later
- Bluetooth LE (BLE) support
- Both the Android device and the device running EverShelf must be on the **same Wi-Fi network**

---

## Setup (step by step)

### 1. Install the APK
Download and install the APK from the Releases page. You may need to allow "Install from unknown sources" in Android settings.

### 2. Launch the app
The app starts the WebSocket gateway server immediately. You will see the **gateway URL** (e.g. `ws://192.168.1.100:8765`) at the top.

### 3. Connect your scale
Tap **"Cerca Bilance Bluetooth"** (Find Bluetooth Scales). Make sure your scale is turned on. Tap it in the list to connect.

### 4. Configure EverShelf
In EverShelf → ⚙️ Settings → **⚖️ Bilancia Smart**:
1. Enable the toggle
2. Paste the gateway URL shown in the Android app
3. Tap **"Testa connessione"** — you should see ✅

### 5. Use it
When adding or consuming a product with unit **g** or **ml**, a **"⚖️ Leggi dalla bilancia"** button appears. Tap it, place the product on the scale, and the weight is filled in automatically.

---

## WebSocket protocol reference

All messages are JSON. The server sends these to connected clients:

```json
// Scale status update
{"type":"status","state":"connected","device":"Mi Scale 2","battery":85}
{"type":"status","state":"disconnected"}

// Weight reading (broadcast continuously while scale is active)
{"type":"weight","value":72.50,"unit":"kg","stable":true,"timestamp":1712345678000}

// Response to ping
{"type":"pong"}
```

Clients can send:

```json
{"type":"get_status"}   // Request current status
{"type":"get_weight"}   // Request next stable weight reading
{"type":"ping"}         // Keep-alive
```

---

## Build from source

### Prerequisites
- Android Studio Hedgehog (2023.1) or later
- Java 8+

### Steps
```bash
# 1. Clone the repo
git clone https://github.com/dadaloop82/EverShelf.git
cd EverShelf/evershelf-scale-gateway

# 2. Download the Gradle wrapper (if not included)
gradle wrapper --gradle-version 8.4

# 3. Build debug APK
./gradlew assembleDebug

# APK is at: app/build/outputs/apk/debug/app-debug.apk
```

---

## Project structure

```
evershelf-scale-gateway/
├── app/src/main/
│   ├── kotlin/it/dadaloop/evershelf/scalegate/
│   │   ├── MainActivity.kt          — UI, orchestration
│   │   ├── BleScaleManager.kt       — BLE scanning & GATT connection
│   │   ├── ScaleProtocol.kt         — Parsing for all supported protocols
│   │   └── GatewayWebSocketServer.kt — WebSocket server (Java-WebSocket)
│   ├── res/layout/
│   │   ├── activity_main.xml
│   │   └── item_device.xml
│   └── AndroidManifest.xml
├── build.gradle.kts
└── settings.gradle.kts
```

---

## License

MIT — see [LICENSE](../LICENSE)
