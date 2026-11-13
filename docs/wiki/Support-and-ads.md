# Support and ads (optional monetisation)

EverShelf is free, self-hosted and open source, and it stays that way: nothing in
this page locks a feature, and no answer here changes what the app can do. It only
decides whether the app **asks** for help, and in which form.

> **Advertising is parked.** EverShelf does not run ads. The banner slots, the
> "keep the banners" / "both" answers and the whole provider plumbing are still in
> the codebase but commented out, and the UI offers exactly two answers: **donate**
> and **later** (the default). Jump to [Banner slots (parked)](#banner-slots-parked)
> if you ever want them back.

## What the household sees

Once, on startup — and only while nothing has been answered yet — a quiet dialog
explains that the project has real running costs and offers two options:

| Option | What it does | Default |
|---|---|---|
| 💙 **Donate with PayPal** | shows the donation link with a QR code to scan from a phone | |
| ⏳ **Later** | nothing for now: the app stays exactly as it is | ✅ |

The answer is stored in `.env` (`SUPPORT_MODE` + `SUPPORT_DECIDED` + the
`SUPPORT_ANSWERED_AT` clock) and can be changed any time under **Settings → Info →
❤️ Support EverShelf**, or from the **❤️ Support EverShelf** button at the bottom of
the settings page.

Once answered, the dialog behaves according to the answer: **a donation is never asked
again** (the QR has been shown and used — only Settings can change it), while **Later**
keeps it quiet for about six weeks and then lets it come back on a random start (one
boot in four): roughly one reminder every other month, never on a schedule — «a random
un mese sì e uno no». Closing the dialog with ✕ is not an answer, so that one returns at
the next start.

While nobody has answered, the answer already in effect is **"later"**: the dialog
preselects it, and the app behaves the same as before the question was ever asked.

### When the answer is a donation

The dialog then stops being a menu: the two cards step aside and it becomes a
thank-you — a warm line, the PayPal link, and a **large** QR code (240 px, 260 px in
this focused view) to scan from a phone. A discreet *“Change the choice”* link brings
the other answer back, so nothing is ever locked in without a way out.

Once answered, the dialog is done — it does not come back on its own, there is no
reminder and no cadence to tune. Closing it with ✕ is *not* an answer, so that one
case simply asks again at the next start; either option is final until the household
changes it in Settings.

## Configure PayPal (5 minutes)

1. Open [paypal.me](https://www.paypal.com/paypalme/) and claim your handle, or
   create a **Donate button** at [PayPal Buttons](https://www.paypal.com/donate/buttons).
2. Copy the link:
   * PayPal.me → `https://paypal.me/<your-handle>`
   * hosted button → `https://www.paypal.com/donate/?hosted_button_id=XXXXXXXXXX`
3. Fill the block in `.env`:

```ini
SUPPORT_PAYPAL_URL=https://paypal.me/your-handle
SUPPORT_PAYPAL_CURRENCY=EUR
SUPPORT_PAYPAL_AMOUNTS=5,10,25
SUPPORT_COST_CURRENCY=EUR
SUPPORT_COST_HOSTING=4.5      # per month
SUPPORT_COST_DOMAIN=12        # per year
SUPPORT_COST_DEVACCOUNT=25    # per year
SUPPORT_COST_API=5            # per month
```

What each piece does:

* `SUPPORT_PAYPAL_AMOUNTS` becomes the amount buttons under the QR — **only when the
  configured link accepts a pre-filled amount**. Three flavours are recognised:
  * `https://paypal.me/handle` → the amount is appended (`/10EUR`);
  * a legacy form URL with `cmd=_donations` → `&amount=…&currency_code=…` is added;
  * a URL that already carries an amount → left untouched, and the buttons are
    hidden so nobody can offer a second, contradictory amount.
  With no `SUPPORT_PAYPAL_URL` the donation card is simply hidden: the dialog still
  offers "later".
* `SUPPORT_COST_*` are the **real** running costs, disclosed in the dialog. A line
  left at `0` is not shown (you should not have to explain a cost you do not pay);
  the dialog also shows what they add up to per month, adding the yearly lines at a
  twelfth. Keeping these numbers true is the whole point of the card.
* The QR is generated **by the server itself** (`api/lib/qr.php`, ISO/IEC 18004 byte
  mode, no CDN and no external service): `?action=support_qr` returns an inline SVG
  or a PNG (`&format=png&scale=5`). It is one of the few public actions, because the
  Android kiosk wizard needs it *before* pairing — it only ever encodes the
  published URL (or one of the published amounts), never free text.

## Banner slots (parked)

Not used, and not planned: EverShelf carries no advertising. The feature is kept in
the tree, commented, because removing it outright would mean re-inventing the
configuration if it were ever wanted again.

What a re-enable touches (the comments in the code name the same list):

| Step | File |
|---|---|
| 1. Put `'ads'` and `'both'` back in `evershelfSupportModes()` | `api/lib/support.php` |
| 2. Un-comment the provider/banner helpers and the payload keys | `api/lib/support.php` |
| 3. Un-comment the `.env` mapping + provider clamp in `save_settings` | `api/index.php` |
| 4. Un-comment the `.env` block (`SUPPORT_BANNERS_AVAILABLE`, `ADS_*`) | `.env.example` |
| 5. Un-comment the `[data-ad-slot]` div on the dashboard | `index.html` |

> The idle screen has **no banner slot** any more: the 2.0.0 redesign renders one card at a
> time in `assets/js/screensaver.js` and there is no place a third-party banner could sit
> without covering the clock or the card. Bringing ads back means re-introducing a slot
> there too (and the CSS rule that went with it), not only un-commenting the dashboard one.

| 6. Un-comment the CSS block and the renderer + `online`/`offline` listeners | `assets/css/style.css`, `assets/js/core/support.js` |
| 7. Un-comment the two radio buttons and the two branches | `evershelf-kiosk` (`activity_setup.xml`, `SetupActivity.kt`) |
| 8. Put the banner assertions back | |

`_renderAdSlots()` still exists as a **no-op** on purpose: a client cached in a kiosk
WebView may call it, and a missing function would throw instead of doing nothing.
The `support.option_ads_*`, `support.option_both_*`, `support.status_ads`,
`support.status_both`, `support.status_banners_off` and `support.house_*` strings are
likewise kept in all six locales.

The design as it was, should it come back:

* **Providers.** `ADS_PROVIDER` chose what filled the slots: `house` (our own
  banner, no account needed), `adsense` (`ADSENSE_CLIENT` + one slot id per
  placement), `custom` (raw `ADS_CUSTOM_*_HTML` from `.env`), `none`.
* **Owner switch.** `SUPPORT_BANNERS_AVAILABLE` (off by default) decided whether the
  two banner answers were offered at all: while it was false they were rendered
  greyed out and unclickable, no slot was filled — not even for a stored banner
  answer — and the answer already in effect was "later".
* **No paywall, no surprise.** Nothing was blocked, hidden or degraded by the
  answer; a slot was never allowed to cover the dashboard, the shopping list or the
  screensaver clock, and the slots hid themselves the moment the browser reported it
  was offline (a third-party banner cannot be served without internet, and an empty
  box looks like a bug). A blocked script or an ad blocker was ignored, never an
  error, and an incomplete AdSense setup fell back to the house banner.
* **Markup is `.env`-only.** `ADS_CUSTOM_*_HTML` was deliberately not writable
  through the API, so it stayed owner configuration and could never be injected by a
  client.
* **Ad networks.** AdSense only pays per impression and needs an approved site with
  real traffic (plus a consent banner in the EU); EthicalAds / Carbon Ads are
  cookie-free and developer-oriented. None of that is in use here.

## How to check it without touching `.env`

Everything is configuration, and the app reads it from `get_settings`:

```bash
# What the client receives (the support payload)
curl -s 'http://127.0.0.1:8080/api/index.php?action=get_settings' | python3 -m json.tool | grep -A 20 '"support"'

# The QR as an image
curl -s 'http://127.0.0.1:8080/api/index.php?action=support_qr' | head -c 200
```

To re-ask the question on one device: **Settings → Info → ❤️ Support EverShelf** →
“Open the full choice”, or the **❤️ Support EverShelf** button at the bottom of the
settings page.

To reset an install to "never asked" (so the dialog comes back at the next start),
clear the two keys — in **both** places, because a read-only `.env` makes the app
store the answer as a database override, and an override wins over `.env`:

```bash
# 1. the file (authoritative when the app can write it)
sed -i 's/^SUPPORT_MODE=.*/SUPPORT_MODE=/; s/^SUPPORT_DECIDED=.*/SUPPORT_DECIDED=false/' .env

# 2. the fallback override, if any (inspect first with: sqlite3 data/evershelf.db
#    "select value from app_settings where key='env_overrides'")
php -r 'define("CRON_MODE", true); require "api/bootstrap.php";
        clearEnvOverrides(["SUPPORT_MODE", "SUPPORT_DECIDED"]);'
```

Making `.env` group-writable (`chmod g+w .env` or `scripts/fix-permissions.sh`)
keeps the whole answer in the file and removes the second step.

## Where the code lives

| Part | File |
|---|---|
| Decision, payload, donation URL, costs | `api/lib/support.php` |
| QR encoder (ISO/IEC 18004, byte mode) + SVG/PNG renderers | `api/lib/qr.php` |
| Endpoint `support_qr`, `support` inside `get_settings`, `save_settings` keys | `api/index.php` |
| Dialog + donation panel (`_renderAdSlots()` is a no-op) | `assets/js/core/support.js` |
| Markup (dialog, Settings card), the two parked slots as comments | `index.html` |
| Styles | `assets/css/style.css` (section “SUPPORT DIALOG (donation)”) |
| Strings (6 locales) | `translations/*.json` → `support` |
| Guard test | |
| Kiosk wizard step | `evershelf-kiosk` → SetupActivity “features” step |
