# 📺 Android Kiosk App

The EverShelf Kiosk app turns any Android tablet into a dedicated, locked-down kitchen display running EverShelf full-screen.

---

## Download

**[⬇ Download latest APK](https://github.com/dadaloop82/EverShelf/releases/download/kiosk-latest/evershelf-kiosk.apk)**

> Current version: **v1.7.23** (versionCode 24) — requires Android 7.0+
>
> Use the `kiosk-latest` tag (not GitHub’s “Latest” release — that tracks the web app and has no APK).
> On a phone already on the EverShelf LAN, Settings → EverShelf Kiosk → **Scarica APK da questo server** serves `releases/evershelf-kiosk.apk` from your instance.
>
> Native UI matches the EverShelf **emerald Corporate UI** (wizard, settings, splash; EN/IT/DE/FR/ES).

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
the Bring! account) are skipped. Progress dots use the emerald brand palette; each step
fades in. Copy is localised (EN/IT/DE/FR/ES).

| # | Step | What it asks |
|---|------|--------------|
| 0 | **Language** | App and web-interface language: Italiano, English, Deutsch, Español, Français |
| 1 | **Welcome** | Brand intro, privacy (“data stays at home”), and what the wizard configures |
| 2 | **Permissions** | Camera, microphone, Bluetooth — each with a short rationale card |
| 3 | **Server URL** | EverShelf URL, LAN auto-discovery, connection test (`ping`), optional pairing + keep/reset of server settings |
| 4 | **Smart Scale** | Optional Bluetooth LE scale (power-on tip → scan → confirm a weight reading) |
| 5 | **Features** | Four explained toggles: screensaver, prices, meal-plan, zero-waste (written to the server) |
| 6 | **Gemini AI** | Optional AI key, with a “what Gemini unlocks” card |
| 7 | **Bring!** | Optional Bring! account, with a “what Bring! does” card |
| 8 | **Done** | Summary and launch the kiosk |

### Step 2 — Permissions

The button transforms from **"Grant permissions"** to **"✅ Permissions granted"** once all
permissions are granted. BLE is skipped automatically if you do not use a scale.

### Step 3 — Server URL

Enter your EverShelf server URL (e.g. `https://192.168.1.100/dispensa`).

**Or tap "Auto-discover"** to let the wizard scan your LAN:
- 60 parallel threads, TCP pre-check, ports 80/443/8080/8443
- Only scans your actual Wi-Fi/Ethernet subnet (VPN and cellular interfaces ignored)
- Real-time feedback as hosts are tested

**Test connection** uses the public `ping` action (same as discovery — no API token required).
If the server needs pairing, enter the code from **Settings → System → Security** on a
device that is already paired (or from the server log). After pairing, the wizard can
**keep** or **reset** the settings already stored on the server.

### Step 4 — Smart Scale

If you have a Bluetooth LE smart scale, configure it here:
1. Tap **"Yes, I have a scale"** — turn the scale on, then continue
2. The app scans for nearby BLE devices (connect retries up to 3×)
3. Tap your scale in the list (devices most likely to be scales are marked with ⭐)
4. Confirm a live weight reading when asked
5. On finish, the app writes `scale_enabled=true` and `scale_gateway_url=ws://127.0.0.1:8765` to the server

The BLE gateway runs as a built-in foreground service — **no external APK needed**.

### Step 5 — Features

Four toggles with short explanations, pushed to the EverShelf server so every device sees
the same choices: **screensaver** (clock overlay after inactivity), **price tracking**,
**meal-plan** and **zero-waste tips**.

### Steps 6–7 — Gemini AI and Bring! (optional)

Both can be skipped and configured later in the web app; the wizard writes what you enter
straight into the server settings (`gemini_key` + API token on save).

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
