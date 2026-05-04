# ⚖️ Scale Gateway

The EverShelf Scale Gateway is an Android app that bridges a Bluetooth LE smart scale to EverShelf, enabling automatic weight-based inventory tracking.

---

## How it works

```
Smart Scale
    │ (Bluetooth LE)
    ▼
Android device (Scale Gateway app)
    │ (WebSocket — ws://127.0.0.1:8765)
    ▼
EverShelf Server (scale_relay.php — SSE relay)
    │ (Server-Sent Events)
    ▼
EverShelf Web App (auto-fills weight in add/use forms)
```

The Gateway runs a local WebSocket server on port **8765**. The EverShelf server proxies scale readings to the browser via SSE, avoiding HTTPS→WS mixed-content issues.

---

## Download

**[⬇ Download latest APK](https://github.com/dadaloop82/EverShelf/releases/latest/download/evershelf-scale-gateway.apk)**

> Current version: **v2.1.0** — requires Android 7.0+

---

## Supported Scales

| Protocol | BLE Service | Notes |
|----------|------------|-------|
| Bluetooth SIG Weight Scale | `0x181D` / char `0x2A9D` | Most compatible |
| Bluetooth SIG Body Composition | `0x181B` / char `0x2A9C` | Weight + body fat |
| Generic fallback | Any notifiable characteristic | Auto-heuristic for 100+ models |

**Verified compatible models:**
- Xiaomi Mi Body Composition Scale 2
- Renpho Smart Body Fat Scale
- Any scale supported by [openScale](https://github.com/oliexdev/openScale/wiki/Supported-scales)

---

## Setup

### 1. Install

Download and install the APK. You may need to enable "Install from unknown sources" in Android Settings.

> **Kiosk users:** the Setup Wizard installs the gateway automatically in Step 5.

### 2. Launch the app

The gateway server starts immediately. Note the **Gateway URL** shown (e.g. `ws://192.168.1.100:8765`).

### 3. Configure EverShelf

In EverShelf **Settings → Scale**:
- Enable scale integration
- Enter the Gateway URL (or let auto-discovery find it)

> **Kiosk users:** this is done automatically during setup.

### 4. Connect your scale

Tap **"Cerca Bilance Bluetooth"** (Find Bluetooth Scales). Make sure your scale is powered on. Tap it in the list to pair and connect.

---

## Using the Scale in EverShelf

When scale integration is enabled:

1. Open the **Add** or **Use** form for any product with unit `g` or `ml`
2. A **"⚖️ Leggi bilancia"** button appears
3. Tap it — a live weight display appears with a stability indicator
4. Step on or place the product on the scale
5. When the reading stabilizes, a **5-second countdown** starts
6. The weight auto-fills the quantity field and the form submits

### Thresholds and de-duplication

- **10g threshold** — readings that haven't changed enough between products are ignored to prevent stale readings
- **12-second server-side dedup** — a second scale-triggered deduction of the same product within 12 seconds is rejected (guards against BLE multi-fire)
- **ml conversion** — when the product unit is `ml`, the weight in grams is accepted and a hint is shown: "weight in grams → will be converted to ml"

---

## Scale Status Indicator

The header of the EverShelf web app shows a real-time scale status icon (⚖️):

| State | Meaning |
|-------|---------|
| ⚖️ green | Connected and ready |
| ⚖️ amber | Searching / reconnecting |
| ⚖️ grey | Disconnected |
| ⚖️ red | Error |

---

## Update Notifications

Every 6 hours the gateway app checks GitHub releases. If a newer version is available, a banner appears with a one-tap download and install.

---

## Troubleshooting

### Scale not appearing in the Bluetooth list
- Make sure BLE is enabled on the Android device
- Step on/shake the scale to wake it up (most scales enter sleep mode quickly)
- Some scales only advertise while someone stands on them

### Weight not appearing in EverShelf
- Confirm the Gateway URL in EverShelf Settings matches the URL shown in the gateway app
- Check that the Android device and the EverShelf server are on the same network
- Tap "Disconnetti / Riconnetti" in the gateway app to refresh the WebSocket connection

### "Mixed content" error in browser
- Make sure you are accessing EverShelf over HTTPS (not plain HTTP)
- The SSE relay (`scale_relay.php`) handles the HTTP→WS bridging — ensure the relay script is reachable

---

## Building from Source

```bash
cd evershelf-scale-gateway
./gradlew assembleRelease
# APK: app/build/outputs/apk/release/app-release.apk
```

Requires Android Studio or JDK 17+ with the Android SDK.

---

## BLE Protocol Details

The gateway uses the following GATT profile order:

1. **Weight Scale** (`0x181D`) — standard weight only
2. **Body Composition** (`0x181B`) — weight + additional metrics
3. **Generic fallback** — subscribes to all notifiable characteristics and applies a heuristic parser that handles byte-order variations used by the majority of consumer smart scales

Weight values are extracted in kg, converted to grams, and broadcast over WebSocket as:

```json
{ "weight_g": 1234, "stable": true, "unit": "g" }
```
