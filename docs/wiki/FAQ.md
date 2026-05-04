# ❓ FAQ & Troubleshooting

---

## Installation

### The app shows a blank page after setup

- Open the browser console (F12 → Console) and check for errors
- Make sure PHP is running and `api/index.php` is reachable: visit `https://your-server/dispensa/api/index.php?action=get_settings` — it should return JSON
- Check your web server error log: `tail -f /var/log/apache2/error.log`

### Camera doesn't work / barcode scanner won't open

Camera access requires **HTTPS**. On plain HTTP, browsers block `getUserMedia()`.

- Set up HTTPS with Let's Encrypt, Caddy, or a self-signed certificate
- On Android, you can also add a security exception in Chrome: `chrome://flags/#allow-insecure-localhost`

### "Permission denied" error for the data directory

```bash
chmod 755 data/
chown -R www-data:www-data data/
```

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

## AI Features

### AI features don't work / "AI non disponibile"

1. Check that `GEMINI_API_KEY` is set in `.env`
2. Verify the key is valid at [aistudio.google.com](https://aistudio.google.com)
3. Check that you haven't exceeded the free tier quota (15 req/min, 1500 req/day)
4. Look for errors in the PHP error log

### Recipe generation stops midway

This is usually a Gemini API timeout. The app streams results via SSE — if the server PHP timeout is too low, the stream is cut short. Increase `max_execution_time` in `php.ini`:

```ini
max_execution_time = 120
```

---

## Shopping List (Bring!)

### "Bring! non configurato" message in the shopping tab

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
- Make sure you tapped "⚖️ Leggi bilancia" first to activate the scale modal
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

### Gateway install fails with an error dialog

The dialog shows the exact failure code. Common causes:

| Code | Cause | Fix |
|------|-------|-----|
| `STATUS_FAILURE` (1) | Generic install failure — often OEM restriction | Enable "Install from unknown sources" for the kiosk app in Android Settings |
| `STATUS_FAILURE_CONFLICT` (3) | Signature mismatch with existing install | Uninstall the old gateway app, then retry |
| `STATUS_FAILURE_STORAGE` (6) | Not enough storage | Free up space on the device |

### Exit button (✕) is not visible

The ✕ button is injected into the header by the kiosk app. If the web app's header is covered or the page failed to load, try the hard refresh (↻) button. If neither is visible, triple-tap the page title area to access the developer settings.

### App is stuck in kiosk mode after a crash

Restart the device. Screen pinning is released on reboot.

---

## General

### The version shown in the app is outdated

The version is cached by the browser. Do a hard refresh:
- Desktop: `Ctrl+Shift+R` / `Cmd+Shift+R`
- Android: tap the ↻ button (kiosk) or clear site data in Chrome settings

### Transactions are missing from the log

The log shows the last 50 entries by default. Tap "Carica altri" to load more. Entries older than the database creation date won't appear.

### "Can only undo transactions within 24 hours"

The undo window is 24 hours. For older operations, manually correct the inventory via the Edit function on the affected product.

### Error reports keep creating duplicate GitHub issues

EverShelf uses a fingerprint to deduplicate — the same error from the same device won't create a new issue within 24 hours. If you're seeing duplicates, check the `data/rate_limits/` folder and clear old files.

---

## Getting Help

- **Open an issue:** [github.com/dadaloop82/EverShelf/issues](https://github.com/dadaloop82/EverShelf/issues)
- **Email:** [evershelfproject@gmail.com](mailto:evershelfproject@gmail.com)
- **Try the demo:** [evershelfproject.dadaloop.it/demo](https://evershelfproject.dadaloop.it/demo)

When reporting a bug, include:
1. EverShelf version (shown in the header as `v1.x.x`)
2. Browser and OS
3. Steps to reproduce
4. Any error messages from the browser console (F12)
