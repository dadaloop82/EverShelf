# 📺 Android Kiosk App

The EverShelf Kiosk app turns any Android tablet into a dedicated, locked-down kitchen display running EverShelf full-screen.

---

## Download

**[⬇ Download latest APK](https://github.com/dadaloop82/EverShelf/releases/latest/download/evershelf-kiosk.apk)**

> Current version: **v1.5.0** — requires Android 7.0+

---

## What it does

- Displays the EverShelf web app in a **full-screen WebView** (no browser chrome)
- **Locks the screen** with Android's `startLockTask` — home, recents, and back buttons are blocked
- Runs the **Scale Gateway** app in the background automatically on startup
- Provides a **native TTS bridge** so Cooking Mode reads steps aloud via Android TextToSpeech
- Auto-detects your EverShelf server on the LAN with a **smart discovery scanner**
- Reports errors and install failures back to the developer automatically

---

## Setup Wizard (6 steps)

The wizard runs automatically on first launch.

### Step 1 — Language
Select the app and web interface language (Italian, English, German).

### Step 2 — Welcome
Overview of what the wizard will configure.

### Step 3 — Permissions
Grant camera, microphone, and storage permissions needed by the web app.

The button transforms from "Concedi permessi" to **"✅ Permessi concessi — Continua →"** (green) once all permissions are granted.

### Step 4 — Server URL
Enter your EverShelf server URL (e.g. `https://192.168.1.100/dispensa`).

**Or tap "Rileva automaticamente"** to let the wizard scan your LAN:
- 60 parallel threads, TCP pre-check, ports 80/443/8080/8443
- Only scans your actual Wi-Fi/Ethernet subnet (VPN and cellular interfaces ignored)
- Real-time feedback as hosts are tested

### Step 5 — Scale Gateway
If you have a BLE smart scale, install and configure the Scale Gateway:
1. Tap **"Installa Gateway"** — the APK is downloaded from GitHub and installed via `PackageInstaller`
2. If installation fails, a diagnostic dialog shows: status code, error message, APK size, Android version, and device model — plus a "Riprova" button
3. On success, the wizard automatically writes `scale_enabled=true` and `scale_gateway_url=ws://127.0.0.1:8765` to your EverShelf server

### Step 6 — Screensaver
Choose whether the screen should go dark after inactivity.

### Summary
All done — the web app loads in full-screen kiosk mode.

---

## Exiting Kiosk Mode

Tap the **✕** button in the header (top-left). A confirmation dialog appears.

---

## Hard Refresh

Tap the **↻** button in the header to clear the WebView cache and reload the latest version of the web app.

---

## Update Notifications

Every 6 hours the app checks GitHub releases. If a newer version is available, a banner appears with a one-tap download and install flow.

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

### "Impossibile installare il gateway"
- Make sure "Install from unknown sources" is enabled for the kiosk app in Android Settings → Apps → Special app access
- Check that there is enough free storage (the APK is ~15 MB)
- The diagnostic dialog shows the exact failure code — include it when opening an issue

### "Server non trovato" during auto-discovery
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
| `REQUEST_INSTALL_PACKAGES` | Install the Scale Gateway APK |
| `ACCESS_WIFI_STATE` | LAN auto-discovery |
| `REORDER_TASKS` | Bring app to foreground after gateway launch |
