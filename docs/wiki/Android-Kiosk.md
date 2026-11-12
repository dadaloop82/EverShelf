# 📺 Android Kiosk App

The EverShelf Kiosk app turns any Android tablet into a dedicated, locked-down kitchen display running EverShelf full-screen.

---

## Download

**[⬇ Download latest APK](https://github.com/dadaloop82/EverShelf/releases/download/kiosk-latest/evershelf-kiosk.apk)**

> Current version: **v1.7.34** (versionCode 38) — requires Android 7.0+
>
> Use the `kiosk-latest` tag (not GitHub’s “Latest” release — that tracks the web app and has no APK).
> On a phone already on the EverShelf LAN, Settings → EverShelf Kiosk → **Scarica APK da questo server** serves `releases/evershelf-kiosk.apk` from your instance.
>
> Native UI matches the EverShelf **emerald Corporate UI** (wizard, settings, splash; EN/IT/DE/FR/ES).

---

## Which version is running?

The version is printed on every boot, because a tablet on a wall has no console:

| Where | What it says | Where it comes from |
|---|---|---|
| Native **Toast** at launch (before the page loads) | `EverShelf Kiosk 1.7.37 (38)` | `KioskActivity.launchWebView()` → `appVersionName()` |
| **Boot log line** in `data/logs/kiosk-YYYY-MM-DD.log` | `[KIOSK] apk=1.7.37 (38) app=v1.31.28` | `remoteLog()` from the same function |
| **Server log** at every boot | `"app_version":"1.7.37 (38)"` in the `mem` / `Boot memory snapshot` report | `KioskActivity` → `ErrorReporter` |

The header carries `kiosk 1.7.37 (38)` next to the app version. It is the **first thing the header
gives up** when the row is narrow, which is why the native Toast at launch exists — and why the boot
**log** line carries both numbers, never a floating banner over the page (`_showKioskBootVersion()`
was removed on request: the header and the Toast are enough).

---

## What it does

- Displays the EverShelf web app in a **full-screen WebView** (no browser chrome)
- **Locks the screen** with Android's `startLockTask` — home, recents, and back buttons are blocked
- Runs the **built-in BLE scale gateway** as an integrated foreground service — no external app required
- Provides a **native TTS bridge** so Cooking Mode reads steps aloud via Android TextToSpeech
- Auto-detects your EverShelf server on the LAN with a **smart discovery scanner**
- Reports errors and install failures back to the developer automatically

---

## Setup Wizard (10 steps)

The wizard runs automatically on first launch. Steps already configured (the Gemini key,
the Bring! account) are skipped. Progress dots use the emerald brand palette; each step
fades in. Copy is localised (EN/IT/DE/FR/ES).

| # | Step | What it asks |
|---|------|--------------|
| 0 | **Language** | App and web-interface language: Italiano, English, Deutsch, Español, Français |
| 1 | **Welcome** | Brand intro, privacy (“data stays at home”), and what the wizard configures |
| 2 | **Permissions** | Camera, microphone, Bluetooth — each with a short rationale card |
| 3 | **Server URL** | EverShelf URL, LAN auto-discovery, connection test (`ping`) |
| 4 | **Pairing** | Dedicated screen: where to find the code (Settings → Security) + enter it once; token is handed to the web app so the browser overlay does not ask again |
| 5 | **Smart Scale** | Optional Bluetooth LE scale (power-on tip → scan → confirm a weight reading) |
| 6 | **Features** | Four explained toggles (screensaver, prices, meal-plan, zero-waste) plus the optional support answer (donate / nothing for now, with the donation QR) — all written to the server |
| 7 | **Gemini AI** | Optional AI key, with a “what Gemini unlocks” card |
| 8 | **Bring!** | Optional Bring! account, with a “what Bring! does” card |
| 9 | **Done** | Summary and launch the kiosk |

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

### Judging the screensaver without leaving it on

The clock screen can be shown on demand, and it changes nothing:

- `?screensaver` (or `?...&screensaver=1`) — show it right now for **60 seconds**;
- `?screensaver=90` — for 90 seconds (capped at 6 hours);
- **Settings → Generali → Salvaschermo → «▶️ Anteprima 30 s»** — the same thing from the
  touch screen, so nobody has to type a URL on the wall.

The idle screen is not a clock with a sentence: it is an **ambient kitchen dashboard** that
reads the household's own data, one card at a time (`assets/js/screensaver.js`):

| Card | What it shows |
|---|---|
| 🍽️ **Pasto** | The slot of the day and the meal plan, with a **real recipe from your archive** the pantry can cook now — nothing missing scores highest, an ingredient about to expire weighs more, plus ⏱️ / 🔥 / 🥩 and «Apri ricetta» |
| ⚠️ **Da consumare** | Up to three things to eat first (open past its window, expired, expiring), each row opening the product sheet; with nothing to do it celebrates the 30-day milestone |
| 📊 **Grafici** | The category pie and the health / variety / freshness rings, high contrast, plus one insight generated from the weakest number |
| 💡 **Idea del giorno** | Five dynamic templates: the shopping list, saved vs wasted, weather + what expires first, the medicine cabinet, the shape of the pantry |

Under **Aspetto** you choose which cards to keep and how often they change (15 · 20 · 30 ·
60 s, `SCREENSAVER_VIEWS` / `SCREENSAVER_INTERVAL`). A card with nothing to say is skipped,
never painted empty.

**On the wall the gestures are the point**: a **tap** flips the card (it does *not* close the
screen — an accidental brush must not take the clock away), a swipe left/right does the same,
a **swipe up** unlocks, and the **«Sblocca»** pill at the bottom centre is the explicit way
back (Esc does the same on a keyboard). The stage drifts a few pixels every 2.5 minutes
against burn-in.

**It always starts** after the timeout you set — on the pantry, the shopping list, the
scanner, with a SHELFY turn open: the screen is an overlay and «Sblocca» puts you back
exactly where you were (the cameras are stopped while it is up, and the scan page gets its
reader back when you unlock). The **only** exception is **cooking mode**, where the recipe is
the session and a clock over the steps interrupts the one thing that was asked for; leaving
it starts the clock again.

A forced run (`?screensaver[=N]`) is the same screen, pinned for the window: the top-right
**✕ «Esci»** prints the time left, and **⏭** names and shows the next card without waiting
for the rotation. The switch, the timeout and every other setting stay exactly as they were;
cooking mode is the only thing that keeps it away, here too. The event is written to the
client log as `[SCREENSAVER] forced for Ns by ?screensaver` / `preview Ns from Settings`.

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

Updates arrive **from your own server first**: the app asks
`GET /api/index.php?action=kiosk_update`, which reads `releases/kiosk-version.json` +
`releases/evershelf-kiosk.apk` from the instance and compares `version_code` with the installed
one. When it is newer, the native banner offers **⬇ Installa aggiornamento**, and the APK is
streamed by the app itself (`openTrustedConnection()`) — never by `DownloadManager`, which cannot
reach a self-signed HTTPS LAN host and waits forever for a network Android calls “internet”.
GitHub (`kiosk-latest`) is the fallback for a wall that is not on the LAN.

The check runs every **30 minutes** and once 4 s after the page boots; the version it is running
is always printed (see [Which version is running?](#which-version-is-running)).

**How the APK gets there**: `.github/workflows/build-kiosk.yml` runs on every push to **`main`**
that touches `evershelf-kiosk/**`. It builds the **release** APK with the project keystore (a
debug-signed APK cannot install over a release one), publishes the `kiosk-<version>` and
`kiosk-latest` GitHub releases, and commits `releases/evershelf-kiosk.apk` +
`releases/kiosk-version.json` back to `main` for LAN OTA. Bumping `versionCode` in
`evershelf-kiosk/app/build.gradle.kts` is therefore the only way an update reaches a wall.

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

### The app dies when it enters kiosk mode (screen pinning)
`1.7.34` booted and then died at the transition: the wall logged `killed-signal(2) status=9` — a
**SIGKILL**, with no Java trace and no tombstone — nine seconds after the page appeared.
`startLockTask()` without Device Owner shows the system's "pin the app?" confirmation, which pauses
the activity; asking for it while the first page was still loading (three times in one second) is
what ended the process.
`1.7.35` asks **once**, from `onPageFinished()`, never unpins to ask a permission (a page request
for a missing OS grant is denied with a Toast and retried at the next launch, before the pin), and
leaves breadcrumbs (`ErrorReporter.step()` → `last-run` on the next boot) that survive a kill — so
a wall that still dies names the step it died in instead of vanishing.

### The app dies while asking for permissions (fresh install / update)
Reported on a real wall: the files → microphone → camera dialogs appear, and granting the camera
ends the process; the media dialog stays on screen with no app behind it and only a tablet reboot
clears it. Permissions are the one thing Android does **not** restore together with the app's data,
so this only shows up after a reinstall.
`1.7.34` asks the camera **before** `startLockTask()` and before the WebView exists (a pinned
activity cannot host a system window), asks for the camera only, and starts even if the answer is
"deny". Until the tablet runs 1.7.34 the workaround is to grant them by hand first — Android
Settings → Apps → EverShelf Kiosk → Permissions → **Camera · Allow** — and only then launch the
app: with nothing left to ask, the crash has no window to happen in.

### App crashes on startup
- Force-stop the app, clear its data (Settings → Apps → EverShelf Kiosk → Clear data), and relaunch
- **The next launch reports why**: on Android 11+ `ErrorReporter.detectPreviousCrash()` reads
  `ActivityManager.getHistoricalProcessExitReasons()` and posts `crash-java` / `crash-native` /
  `anr` / `oom-kill` (with the trace) to the instance the moment the app starts again — so the
  server log names the death, and a crash that left no line at all was **native** (a Java throw
  is caught and saved by the app's own `UncaughtExceptionHandler`).
- With adb: `adb logcat -b crash -d` and `adb logcat -v time | grep -iE "AndroidRuntime|chromium|camera"`.

---

## Building from Source

```bash
cd evershelf-kiosk
./gradlew assembleRelease
# APK: app/build/outputs/apk/release/app-release.apk
```

Requires Android Studio or JDK 17+ with the Android SDK. A **signed** build needs
`evershelf-kiosk/keystore.properties` (or the `KIOSK_STORE_*` env vars) — never publish a
debug-signed APK: Android refuses to update over a different signature and the wall would have
to be uninstalled.

The normal path is CI, not a laptop: push to **`main`** touching `evershelf-kiosk/**` and
`build-kiosk.yml` builds, signs and publishes the APK (see
[Update Notifications](#update-notifications)).

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
