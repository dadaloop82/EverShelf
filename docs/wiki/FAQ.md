# ❓ FAQ & Troubleshooting

---

## Installation

### The app shows a blank page after setup

- Open the browser console (F12 → Console) and check for errors
- Make sure PHP is running and `api/index.php` is reachable: visit `https://your-server/dispensa/api/index.php?action=get_settings` — it should return JSON
- Check your web server error log: `tail -f /var/log/apache2/error.log`

### `net::ERR_INCOMPLETE_CHUNKED_ENCODING 200 (OK)` on `assets/js/app.js`

The response was **cut mid-body**. It is a transport error, not an app error, and there are
two causes worth checking (the first one is handled by the shipped `.htaccess`):

1. **A file rewritten while it is being served.** `app.js` is 2.1 MB and Apache gzips it
   (`mod_deflate` → `Transfer-Encoding: chunked`, no `Content-Length`), so a body that stops
   early can only be reported this way. Apache **memory-maps** the file; if it is truncated or
   replaced under it — an editor saving it, `git checkout`, `scripts/bump-version.sh` — the
   mapped page stops being backed and the child dies with `Bus error (7)`
   (`AH00052` in `error.log`), taking down every response it was carrying. The repo's
   `.htaccess` sets `EnableMMAP off` for exactly this; a Docker/NPM/Unraid vhost that never
   reads the `.htaccess` should set it in the vhost too. Editing the served directory while a
   wall tablet boots is the usual trigger: deploy to a second directory and switch an alias.
2. **The network dropped mid-transfer** (Wi-Fi on a kiosk, a proxy timeout). Same message,
   no server log. A retry is enough.

Since 1.31.28 the shell is also revalidated instead of re-downloaded (`Cache-Control:
no-cache` + `304 Not Modified`), so a boot no longer streams 2.1 MB every time — which used
to multiply the chances of hitting either cause.

### Camera doesn't work / barcode scanner won't open

Camera access requires **HTTPS**. On plain HTTP, browsers block `getUserMedia()`.

- Set up HTTPS with Let's Encrypt, Caddy, or a self-signed certificate
- On Android, you can also add a security exception in Chrome: `chrome://flags/#allow-insecure-localhost`

### "Permission denied" error for the data directory

```bash
chmod 755 data/
chown -R www-data:www-data data/
```

### Docker bind mount: "Critical error — Data directory / Disk write test / Database connection"

When you replace the named volume evershelf_data with a host path (`./data:/var/www/html/data`), Docker creates the host directory as root if it doesn't exist, and the image's build-time chown www-data is shadowed. Fix on the host:

```bash
mkdir -p ./data
sudo chown -R 33:33 ./data   # 33 = www-data UID in the container
```

The same applies to a bind-mounted `.env`: if the file is owned by root, the app can read the configuration but **Settings cannot save it**. The app then keeps the changed keys in its own database (`app_settings.env_overrides`) and answers "saved". A later hand edit of `.env` wins over that copy — the stale entry is detected and dropped (since 1.16.1) — but the clean fix is to let the container own the file:

```bash
sudo chown 33:33 .env
```

Settings saves are written to `.env` whenever the web server can write it; the database fallback exists for read-only files and for Unraid-style `EVERSHELF_ENV_FILE` mounts.

### Are backups automatic? Where do they live?

- **Docker / Unraid:** yes. The image runs `api/cron_smart_shopping.php` every 5 minutes
  as `www-data` (see `/etc/cron.d/evershelf`); that job creates at most one database
  backup every 23 hours in `data/backups/` and keeps `BACKUP_RETENTION_DAYS` (default 3).
  Nothing to install on the host.
- **Bare metal:** schedule it yourself — `Installation → Backup` has the crontab lines.
- **Check it:** *Settings → 💾 Backup* shows the last backup and warns when it is older
  than 8 days; the settings checklist keeps a row until a fresh one exists. The panel
  also has a **Backup now** button.
- **Restore:** the same panel — it replaces the current database with the chosen file.

> Backups live **inside the data volume**. They protect against a bad restore or a wrong
> deletion, not against losing the volume (a template change, `docker compose down -v`, a
> disk failure): copy `data/backups/` off the volume, keep the appdata folder in your
> Unraid backup, or enable Google Drive in the same panel.

### Docker container exits immediately

```bash
docker compose logs evershelf
```

Usually a permission issue on the mounted `data/` volume. Try:

```bash
docker compose down
rm -rf data/
mkdir data/
docker compose up -d
```

---

### The app asks for an API token / a pairing code

Not an error: it is the token bootstrap. When `API_TOKEN` is set in `.env`, EverShelf
never returns it to an anonymous request, so the browser must prove it is allowed by
presenting a **pairing code**. On a device that is already paired, open
**Settings → System → Security** (or Info) — the live code is shown there with a
countdown. It is also printed in the server log:

```bash
grep -i "pairing code" logs/evershelf_*.log | tail -1           # bare metal
docker logs evershelf 2>&1 | grep -i "pairing code" | tail -1   # Docker
```

The code is 8 characters and valid for 30 minutes. You pair once per browser/device;
afterwards the token is stored in `localStorage` and you are not asked again.

- **No dialog appears and the splash stays on "API token required"?** Hard-refresh with
  `Ctrl+Shift+R`: a service worker from a version before 1.8.8 may still be serving the
  old `app.js`. (Before 1.8.8 the dialog was drawn *behind* the splash, so the app really
  did look stuck.)
- **The code expired?** Reload the page — a fresh one is printed automatically.
- **Lost the code / prefer not to pair?** Copy `API_TOKEN` from `.env` and paste it into
  **Settings → Security → Token**.
- **`grep` returns nothing even though the app asks for a code?** The web server must
  be able to write the log file. A log created by a root `cron` run is owned by root and
  silently rejects Apache's writes, so the code never lands in the file you grep. Fix it
  with `bash scripts/fix-permissions.sh` (or `chown -R www-data:www-data logs/`).
- **Trusted LAN only:** set `API_BOOTSTRAP_OPEN=true` in `.env` to go back to the old
  behaviour (the token is handed to any same-origin-looking request). Never do this on an
  internet-exposed host — see `SECURITY.md`.

## AI Features

### "AI not available" error

1. Check that `GEMINI_API_KEY` is set in `.env`
2. Verify the key is valid at [aistudio.google.com](https://aistudio.google.com)
3. Check that you haven't exceeded the free tier quota (15 req/min, 1500 req/day)
4. Look for errors in the PHP error log

This is usually a Gemini API timeout. The app streams results via SSE — if the server PHP timeout is too low, the stream is cut short. Increase `max_execution_time` in `php.ini`:

```ini
max_execution_time = 120
```

---

## Shopping List (Bring!)

### "Bring! not configured" message in the shopping tab

Add your Bring! credentials to `.env`:

```ini
BRING_EMAIL=your@email.com
BRING_PASSWORD=yourpassword
```

### Items aren't syncing to Bring!

- Verify your credentials are correct by logging into [getbring.com](https://web.getbring.com/)
- Check for rate-limit errors in the PHP error log — Bring! has API limits

---

## Scale Integration

### Scale readings don't appear in EverShelf

1. Confirm the gateway app is running and shows the WebSocket URL
2. Check the Gateway URL in EverShelf Settings matches exactly
3. Make sure both the Android device and the EverShelf server are on the same network
4. Look at the scale status indicator (⚖️) in the header — "disconnected" means no WebSocket connection

### Scale shows weight but form doesn't auto-fill

- The auto-fill only triggers for products with unit `g` or `ml`
- Make sure you tapped **"⚖️ Read Scale"** first to activate the scale modal
- The weight must stabilize (stay within 10g) for the countdown to start

### Bluetooth scale not appearing in the gateway app

- Wake up the scale (step on it or press its button)
- Make sure Bluetooth and Location permissions are granted to the gateway app (Location is required by Android for BLE scanning)
- Restart the gateway app

---

## Kiosk App

### Setup wizard can't find my server

- Make sure the tablet is on the same Wi-Fi network as the server
- Try entering the URL manually instead of using auto-discovery
- Check that the server responds on the expected port (80/443/8080/8443)

### Kiosk app update fails

The kiosk checks for a new release every 6 hours. Prefer the `kiosk-latest` tag (or
**Settings → EverShelf Kiosk → Scarica APK da questo server** on the LAN) — GitHub’s
“Latest” release is the web app and has no APK. If the install fails:

| Symptom | Fix |
|---------|-----|
| "Install from unknown sources" dialog | Enable the setting for the EverShelf Kiosk app in Android Settings |
| Signature conflict / must uninstall | CI must publish with the stable `KIOSK_KEYSTORE_*` secrets (debug-signed APKs break OTA) |
| Persistent failure after download | Force-stop the app, clear its data, and relaunch the update flow |
| Not enough space | Free up storage on the device |

### Exit button (✕) is not visible

Three buttons are always visible in the kiosk header overlay: **✕** (exit), **↻** (refresh), **⚙️** (settings). If the page failed to load entirely, tap **↻** first. If nothing is visible, restart the device.

### App is stuck in kiosk mode after a crash

Restart the device. Screen pinning is released on reboot.

---

## General

### The version shown in the app is outdated

The version is cached by the browser. Do a hard refresh:
- Desktop: `Ctrl+Shift+R` / `Cmd+Shift+R`
- Android: tap the ↻ button (kiosk) or clear site data in Chrome settings

### Transactions are missing from the log

The log shows the last 50 entries by default. Tap **"Load more"** to load more. Entries older than the database creation date won't appear.

### "Can only undo transactions within 24 hours"

The undo window is 24 hours. For older operations, manually correct the inventory via the Edit function on the affected product.

### Error reports keep creating duplicate GitHub issues

EverShelf uses a fingerprint to deduplicate — the same error from the same device won't create a new issue within 24 hours. If you're seeing duplicates, check the `data/rate_limits/` folder and clear old files.

---

## Getting Help

- **Open an issue:** [github.com/dadaloop82/EverShelf/issues](https://github.com/dadaloop82/EverShelf/issues)
- **Email:** [evershelfproject@gmail.com](mailto:evershelfproject@gmail.com)
- **Try the demo:** [evershelf.site/demo](https://evershelf.site/demo)

When reporting a bug, include:
1. EverShelf version (shown in the header as `v1.x.x`)
2. Browser and OS
3. Steps to reproduce
4. Any error messages from the browser console (F12)
