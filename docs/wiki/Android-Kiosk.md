# 📺 Android Kiosk App

The EverShelf Kiosk app turns any Android tablet into a dedicated, locked-down kitchen display running EverShelf full-screen.

---

## Download

**[⬇ Download latest APK](https://github.com/dadaloop82/EverShelf/releases/latest/download/evershelf-kiosk.apk)**

> Current version: **v1.7.20** (versionCode 21) — requires Android 7.0+

---

## What it does

- Displays the EverShelf web app in a **full-screen WebView** (no browser chrome)
- **Locks the screen** with Android's `startLockTask` — home, recents, and back buttons are blocked
- Runs the **built-in BLE scale gateway** as an integrated foreground service — no external app required
- Provides a **native TTS bridge** so Cooking Mode reads steps aloud via Android TextToSpeech
- Auto-detects your EverShelf server on the LAN with a **smart discovery scanner**
- Reports errors and install failures back to the developer automatically

---

## Setup Wizard (9 steps)

The wizard runs automatically on first launch. Steps already configured (the Gemini key,
the Bring! account) are skipped, and the progress dots at the top show where you are.

| # | Step | What it asks |
|---|------|--------------|
| 0 | **Language** | App and web-interface language: Italiano, English, Deutsch, Español, Français |
| 1 | **Welcome** | Overview of what the wizard will configure |
| 2 | **Permissions** | Runtime permissions needed by the web app: camera + microphone (storage/media on older Androids) |
| 3 | **Server URL** | Your EverShelf URL, or LAN auto-discovery |
| 4 | **Smart Scale** | Optional Bluetooth LE scale |
| 5 | **Features** | Four toggles: screensaver, price tracking, meal-plan and zero-waste mode |
| 6 | **Gemini AI** | Optional AI key, so scan/recipe features work out of the box |
| 7 | **Bring!** | Optional Bring! shopping-list account |
| 8 | **Done** | Launch the kiosk |

### Step 2 — Permissions

The button transforms from **"Grant permissions"** to **"✅ Permissions granted — Continue →"** (green) once all permissions are granted.

### Step 3 — Server URL

Enter your EverShelf server URL (e.g. `https://192.168.1.100/dispensa`).

**Or tap "Auto-discover"** to let the wizard scan your LAN:
- 60 parallel threads, TCP pre-check, ports 80/443/8080/8443
- Only scans your actual Wi-Fi/Ethernet subnet (VPN and cellular interfaces ignored)
- Real-time feedback as hosts are tested

### Step 4 — Smart Scale

If you have a Bluetooth LE smart scale, configure it here:
1. Tap **"Yes, I have a scale"** — the app scans for nearby BLE devices
2. Tap your scale in the list (devices most likely to be scales are marked with ⭐)
3. On selection, the app automatically writes `scale_enabled=true` and `scale_gateway_url=ws://127.0.0.1:8765` to your EverShelf server

The BLE gateway runs as a built-in foreground service — **no external APK needed**.

### Step 5 — Features

Four toggles that are pushed to the web app: **screensaver** (screen goes dark after
inactivity, the web app draws the clock overlay), **price tracking**, **meal-plan** and
**zero-waste mode**.

### Steps 6–7 — Gemini AI and Bring! (optional)

Both can be skipped and configured later in the web app; the wizard writes what you enter
straight into the server settings.

### Step 8 — Summary

All done — the web app loads in full-screen kiosk mode.

---

## On-screen Buttons

| Button | Where | Action |
|--------|-------|--------|
| **✕** | Web overlay, injected into `#header-left` | Exit kiosk mode (confirmation dialog → `_kioskBridge.exit()`) |
| **↻** | Web overlay, injected into `#header-left` | Hard-refresh — clears the WebView cache and reloads the app |
| **⚙️** | Web overlay, injected into `#header-left` | Open the **web** Settings page (`showPage('settings')`) |

The **native** Android gear (`btnSettings`, `alpha 0.28`, bottom-right) is permanently
hidden as soon as the overlay is injected (`_kioskBridge.setNativeSettingsVisible(false)`),
so kiosk configuration happens inside the web app. An earlier layout left the native
button on top of the header, where it swallowed the 📷 scan tap — which is why it is now
faint, moved above the bottom nav bar, and switched off.

The native *SettingsActivity* (server URL, BLE scale, screensaver) is still reachable
from the web app: *Settings → ℹ️ Info → Kiosk* → **Apri configurazione kiosk**
(`_openKioskNativeSettings()` → `_kioskBridge.openNativeSettings()`). The same card can
re-run the BLE scale wizard (`reconfigureScale`), check for an APK update, or show the
download link. An older APK that does not expose those bridges gets a "please update
the kiosk" notice instead.

---

## Exiting Kiosk Mode

Tap the **✕** button in the top-left overlay. A confirmation dialog appears.

---

## Hard Refresh

Tap the **↻** button in the top-left overlay to clear the WebView cache and reload the latest version of the web app.

---

## Update Notifications

The app polls for updates every **30 minutes**, while the real GitHub API call is
throttled to **once every 6 hours**. If a newer version is available, a banner appears
with a one-tap download and install flow (`REQUEST_INSTALL_PACKAGES`).

---

## Native TTS Bridge

When Cooking Mode reads recipe steps, the kiosk app:
1. Intercepts the TTS call from the web app via a JavaScript bridge
2. Uses the Android `TextToSpeech` engine directly
3. Falls back to the browser Web Speech API if the bridge is unavailable

No internet connection required for TTS. No extra voice packs to install.

---

## SSL / Self-signed Certificates

The WebView accepts self-signed certificates automatically. No configuration needed for local HTTPS servers.

---

## Troubleshooting

### "Nessun server EverShelf trovato automaticamente"
- Make sure your tablet and server are on the same Wi-Fi network
- Ensure the server is not on a VPN-only interface
- Try entering the URL manually

### Screen pinning / back button not working
- Screen pinning requires the app to be set as Device Owner or the user to confirm the pin prompt
- Some Android skins (Samsung, Xiaomi) may require additional accessibility permissions

### App crashes on startup
- Force-stop the app, clear its data (Settings → Apps → EverShelf Kiosk → Clear data), and relaunch

---

## Building from Source

```bash
cd evershelf-kiosk
./gradlew assembleRelease
# APK: app/build/outputs/apk/release/app-release.apk
```

Requires Android Studio or JDK 17+ with the Android SDK.

---

## Permissions

| Permission | Purpose |
|-----------|---------|
| `INTERNET` | Load the EverShelf web app |
| `CAMERA` | Barcode scanning and AI photo identification |
| `RECORD_AUDIO` | Voice input in AI chat |
| `WAKE_LOCK` | Keep the screen on |
| `REQUEST_INSTALL_PACKAGES` | Over-the-air kiosk self-updates (installs new APK from GitHub releases) |
| `ACCESS_WIFI_STATE` | LAN auto-discovery |
| `REORDER_TASKS` | Bring the kiosk app to foreground when needed |
