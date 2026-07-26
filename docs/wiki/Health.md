# Health Bridge & Fuel Mode

EverShelf can use **phone activity** (via Health Connect) to drive **Fuel Mode** recipes — meals sized to your goal and today’s burn, using only pantry stock.

## Concepts

| Piece | Role |
|-------|------|
| **Settings → Health** | Biological profile, master enable switch, bridge pairing / download |
| **Health Bridge APK** | Android app that reads Health Connect and POSTs daily aggregates to EverShelf |
| **Fuel Mode** | Recipe option that budgets kcal/protein from profile + today’s activity |
| **Silent intake** | Pantry “use” and cooked recipes contribute estimated intake — no separate meal diary |
| **Remaining-day budget** | Fuel Mode subtracts today’s logged intake and splits the leftover across meals still ahead |

## Download

[evershelf-health-bridge.apk](https://github.com/dadaloop82/EverShelf/releases/download/health-bridge-latest/evershelf-health-bridge.apk)

## Pairing

1. Enable Health in EverShelf and save your profile.
2. Install the APK on an Android phone with Health Connect.
3. Generate the pairing QR (URL + token).
4. Scan from the app; grant permissions; sync.

Details: [`evershelf-health-bridge/README.md`](https://github.com/dadaloop82/EverShelf/blob/main/evershelf-health-bridge/README.md)

## API (overview)

| Action | Purpose |
|--------|---------|
| `health_status` | Profile + today’s daily row + bridge state |
| `health_profile_save` | Save biological profile / enable flag |
| `health_ingest` | Bridge posts daily metrics (token auth) |
| `health_bridge_token_create` | Issue pairing token + QR payload |
| `health_unlink` | Revoke bridge tokens |

See [API Reference](API-Reference) for request/response shapes.
