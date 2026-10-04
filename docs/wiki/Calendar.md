# 🗓️ Calendar Feed (ICS / WebCal)

EverShelf can publish the pantry expiries as a **calendar subscription**: instead
of opening the app to find out what expires, the deadlines appear in the calendar
the household already checks — Google Calendar, Apple Calendar, Thunderbird,
Nextcloud, Home Assistant, or anything that speaks ICS.

It is read-only, needs no cron job, changes no database table, and every
calendar client refreshes it on its own.

---

## Enabling it

1. Open **Settings → 🗓️ Calendar**.
2. Turn on **✅ Enable the calendar feed** and save.
   The first save **mints the secret automatically** — you never have to invent a
   key by hand.
3. Copy the **subscribe URL** shown below the switch.

> The URL looks like
> `https://your-server/api/index.php?action=calendar_ics&token=1f3c…`
> and it *is* the password: anyone holding it can read your expiry list. See
> [Security](#security) below.

---

## Subscribing

| Platform | Steps |
|---|---|
| **Google Calendar** | *Other calendars* **＋** → *From URL* → paste → *Add calendar* |
| **Apple Calendar** (macOS) | *File* → *New Calendar Subscription* → paste → set *Refresh* |
| **Apple Calendar** (iPhone/iPad) | Settings → Calendar → Accounts → *Add Account* → *Other* → *Add Subscribed Calendar* |
| **Thunderbird** | *New Calendar* → *On the Network* → paste → *Subscribe* |
| **Nextcloud** | Calendar app → **＋** → *New calendar* → *Subscription from URL* |
| **Home Assistant** | Add the built-in *Local Calendar* integration and point it at the same URL |

On a phone, the **🗓️ Open in Calendar** button in Settings hands the URL to the
calendar app in one tap (`webcal://`), and **📋 Copy link** puts it on the
clipboard for the desktop.

---

## What an event looks like

One **all-day event per in-stock item that has an expiry date**:

| Field | Value |
|---|---|
| Title | `Yogurt (Müller)` — expired items get a `⚠️ EXPIRED — ` prefix |
| Date | The expiry date, as an all-day event (no time zone guessing) |
| Description | Location, quantity, brand, expiry date |
| Category | `EverShelf` (so you can colour the calendar by category) |
| Reminder | A display alarm **1 day before**, so you can use it up in time |

Technical details, for the curious:

- Calendar name, description and the field labels above are translated to the
  language you pass as `?lang=` (`en` by default), read from the same
  `translations/*.json` files as the rest of the UI.
- `UID`s are stable (`evershelf-inv-<id>@<host>`), so moving an expiry date
  **updates** the existing event instead of duplicating it.
- `DTSTAMP` / `LAST-MODIFIED` are the moment the feed was generated (UTC), as
  RFC 5545 requires; clients key off the stable `UID` to decide what changed.
- `REFRESH-INTERVAL: PT6H` asks clients to re-poll every 6 hours.
- The response is `text/calendar; charset=utf-8`, `Cache-Control: private`,
  `X-Robots-Tag: noindex`, and every line is escaped and folded per RFC 5545
  (multi-byte UTF-8 safe), so names with commas, semicolons or emoji survive.

---

## Options

Configured in **Settings → 🗓️ Calendar** and stored in `.env`
(see [Configuration](Configuration)):

| Option | Default | Meaning |
|---|---|---|
| `ICS_ENABLED` | `false` | Master switch. When false the feed answers `404`. |
| `ICS_TOKEN` | — | Read-only secret in the URL. Minted/rotated by the UI. |
| `ICS_DAYS` | `30` | Days ahead to publish (1–365). |
| `ICS_PAST_DAYS` | `7` | How long already-expired items stay visible (0–60). |

Items with quantity `0` are never published: a finished product is not a deadline.

---

## Security

- The feed lives in `evershelfPublicActions()` because a calendar client can only
  GET a URL: it cannot send a token header, and it will keep calling that URL for
  years. Authentication therefore travels **in the URL**, as a dedicated
  **read-only** secret — separate from `API_TOKEN`, so a leaked subscription can
  never add, edit or delete anything.
- Comparison uses `hash_equals()` (constant time) and the token is never written to
  the log; the negative case logs only `ics_feed_unauthorized`.
- **♻️ Rotate link (revoke)** generates a new secret: every existing subscription
  stops working immediately and each device must be re-subscribed. Do this if you
  ever shared the URL in a screenshot, a group chat, or with a guest.
- The URL contains no API token, but it does reveal product names and expiry
  dates: treat it like a password, and prefer HTTPS.
- There is deliberately **no QR code**: the only QR generator the project would
  otherwise use is a third-party web service, and the subscribe URL is a
  long-lived credential (unlike the short-lived pairing code). It must not be
  handed to someone else's server.

---

## Troubleshooting

| Symptom | Cause |
|---|---|
| Calendar says *Could not fetch/parse* | The feed is disabled (`404`) — flip `ICS_ENABLED` in Settings and save. |
| *Forbidden* / nothing at all | Wrong or rotated token — copy the URL again from Settings. |
| Subscribed, but empty | No item has an expiry date inside the horizon; raise `ICS_DAYS`. |
| Events do not update | Calendar clients poll slowly (Google: up to 24 h). `REFRESH-INTERVAL` is a hint, not a promise. |
| Same item twice | An old subscription with a different token — remove the stale calendar. |

---

## API

| Action | Method | Notes |
|---|---|---|
| `calendar_ics` | GET | The feed. Public, requires `?token=`. Returns `text/calendar`. |
| `get_ics_settings` | GET | Status, horizon, subscribe URL, event count (UI only). |
| `rotate_ics_token` | POST | Mints a new secret and returns the new URL. |

```bash
curl -s "https://your-server/api/index.php?action=calendar_ics&token=$ICS_TOKEN" | head
```
