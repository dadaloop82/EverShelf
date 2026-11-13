<?php
/**
 * EverShelf — optional support layer (donations only).
 *
 * The project is free, self-hosted and open source, but it has real running costs
 * (domain, VPS, store/developer accounts, AI credits). Instead of a paywall the app
 * asks the household **once**, at startup, whether it wants to help, and then
 * respects the answer — no nagging, nothing blocked or degraded:
 *
 *   donate  → the donation link with its QR code
 *   later   → nothing now (and the answer already in effect), and it stands until
 *             it is changed on purpose
 *
 * Advertising is **not** part of that question any more. The banner slots, the
 * "ads"/"both" answers and the provider plumbing are parked: the code is still here,
 * commented out, in case the project ever wants them back. See the "Banner slots
 * (parked)" section of docs/wiki/Support-and-ads.md before re-enabling anything.
 *
 * The question is asked once, while nothing was decided, and never comes back on
 * its own: no recurring reminder, no countdown. Everything the dialog shows is
 * configuration, not code — the PayPal link, the amounts and the costs to disclose
 * all come from .env (see the "Support & optional monetisation" block in
 * .env.example and docs/wiki/Support-and-ads.md).
 */

/**
 * The answers the dialog offers, in display order.
 * The client renders `support.option_<mode>_title` / `_desc` for each of them.
 *
 * The banner answers ("ads", "both") were removed on purpose: the project does not
 * run advertising. Their cards, UI strings and payload fields are parked — see the
 * commented block below and "Banner slots (parked)" in the wiki.
 */
function evershelfSupportModes(): array
{
    return ['donate', 'later'];
}

/*
 * ─── PARKED: banner slots (not used — EverShelf does not run ads) ─────────────
 *
 * Kept for reference only. To bring the banners back you would have to:
 *   1. restore 'ads' and 'both' in evershelfSupportModes();
 *   2. restore the provider/banner helpers below and the banner keys in
 *      evershelfSupportPayload();
 *   3. restore the .env keys (SUPPORT_BANNERS_AVAILABLE, ADS_*) in .env.example
 *      and in the save_settings allow-list of api/index.php;
 *   4. restore the .ad-slot divs in index.html, the CSS block, and the banner
 *      renderer in assets/js/core/support.js;
 *   5. restore the two extra answers in the Android kiosk wizard;
 *   6. put the banner answers back in the support decision payload.
 *
 * /** Banner providers: our own house ad, Google AdSense, raw .env markup, or none. *\/
 * function evershelfSupportAdProviders(): array
 * {
 *     return ['house', 'adsense', 'custom', 'none'];
 * }
 *
 * /**
 *  * The two answers that involve banners. They are the ones disabled while
 *  * `SUPPORT_BANNERS_AVAILABLE` is off, and the ones that switch the slots on.
 *  *\/
 * function evershelfSupportBannerModes(): array
 * {
 *     return ['ads', 'both'];
 * }
 */

/** Normalise a mode coming from .env or the API. '' means "never asked". */
function evershelfSupportNormalizeMode(string $mode): string
{
    $mode = strtolower(trim($mode));
    return in_array($mode, evershelfSupportModes(), true) ? $mode : '';
}

/** The stored answer ('' while the household has never answered). */
function evershelfSupportMode(): string
{
    return evershelfSupportNormalizeMode((string)env('SUPPORT_MODE', ''));
}

/**
 * Save-time clamp: an answer that is not one of the offered ones is stored as
 * "later". The dialog only shows "donate" and "later", so this catches a hand-made
 * request (or a value left over from the parked banner answers) — and it keeps the
 * stored state honest instead of recording a choice the app would then ignore.
 */
function evershelfSupportClampMode(string $mode): string
{
    $mode = evershelfSupportNormalizeMode($mode);
    return $mode === 'donate' ? 'donate' : 'later';
}

/**
 * True once the dialog was answered. "later" counts as an answer: the question is
 * asked while nothing was decided and never comes back on its own, so either
 * answer — including "later" — closes it for good until the household changes it
 * on purpose in Settings.
 */
function evershelfSupportAnswered(): bool
{
    return env('SUPPORT_DECIDED', 'false') === 'true' && evershelfSupportMode() !== '';
}

/*
 * ─── PARKED: banner slots (not used — EverShelf does not run ads) ─────────────
 *
 * /** Banner provider for the two slots, defaulting to our own house ad. *\/
 * function evershelfSupportAdProvider(): string
 * {
 *     $provider = strtolower(trim((string)env('ADS_PROVIDER', 'house')));
 *     return in_array($provider, evershelfSupportAdProviders(), true) ? $provider : 'house';
 * }
 *
 * /**
 *  * Are the banner slots shown? Only when the household actually chose banners (or
 *  * "both"), the banners are available at all, and a provider is configured — the
 *  * default answer is "later", so an undecided install stays clean.
 *  *\/
 * function evershelfSupportAdsEnabled(): bool
 * {
 *     if (!evershelfSupportBannersAvailable()) {
 *         return false;
 *     }
 *     if (!in_array(evershelfSupportMode(), ['ads', 'both'], true)) {
 *         return false;
 *     }
 *     return evershelfSupportAdProvider() !== 'none';
 * }
 *
 * /**
 *  * Whether the banners are offered as an answer at all (owner switch,
 *  * `SUPPORT_BANNERS_AVAILABLE`, off by default)…
 *  *\/
 * function evershelfSupportBannersAvailable(): bool
 * {
 *     return env('SUPPORT_BANNERS_AVAILABLE', 'false') === 'true';
 * }
 */

/**
 * Always false: the banner slots are parked, so no slot is ever filled. Kept as a
 * function because the payload and the client still speak about it — and because a
 * cached client asking for it must get an answer rather than a fatal error.
 */
function evershelfSupportAdsEnabled(): bool
{
    return false;
}

/** The donation link as configured ('' = donations not configured yet). */
function evershelfSupportPaypalUrl(): string
{
    return trim((string)env('SUPPORT_PAYPAL_URL', ''));
}

/** Currency used for the donation link (ISO code, e.g. EUR). */
function evershelfSupportCurrency(): string
{
    $currency = strtoupper(trim((string)env('SUPPORT_PAYPAL_CURRENCY', 'EUR')));
    return preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'EUR';
}

/**
 * The suggested amounts (SUPPORT_PAYPAL_AMOUNTS, comma separated). Empty when the
 * owner prefers the donation page's own amount selector.
 */
function evershelfSupportAmounts(): array
{
    $raw = (string)env('SUPPORT_PAYPAL_AMOUNTS', '');
    $amounts = [];
    foreach (explode(',', $raw) as $part) {
        $value = (float)str_replace(',', '.', trim($part));
        if ($value > 0 && $value <= 10000) {
            $amounts[] = round($value, 2);
        }
    }
    return array_values(array_unique($amounts));
}

/**
 * Whether the configured donation page accepts a pre-filled amount.
 *
 * A `paypal.me` link does (`/10EUR`), and so does the legacy donation form
 * (`amount=`). A hosted donate button (`?hosted_button_id=…`) does **not**: PayPal
 * shows its own presets plus an editable field and ignores the query parameter —
 * offering "10 €" buttons there would promise something the page does not do.
 */
function evershelfSupportAmountsSupported(): bool
{
    $url = evershelfSupportPaypalUrl();
    if ($url === '') {
        return false;
    }
    return stripos($url, 'hosted_button_id') === false;
}

/*
 * ─── PARKED: banner availability (not used — EverShelf does not run ads) ──────
 *
 * /**
 *  * Whether the banners are offered as an answer at all.
 *  *
 *  * Owner switch (`SUPPORT_BANNERS_AVAILABLE`, off by default): while it is false the
 *  * two banner answers are shown greyed out and cannot be picked, and no banner is
 *  * ever rendered — even if a mode was stored before the switch was flipped. It is
 *  * also the reason the pre-selected answer is "later" instead of "ads".
 *  *\/
 * function evershelfSupportBannersAvailable(): bool
 * {
 *     return env('SUPPORT_BANNERS_AVAILABLE', 'false') === 'true';
 * }
 */

/**
 * The answer that is already in effect while nobody has chosen. It is "later": the
 * only other answer is the donation, and that one has to be asked for explicitly.
 */
function evershelfSupportDefaultMode(): string
{
    return 'later';
}

/**
 * Build the donation URL for one amount, adapting to whichever PayPal flavour the
 * owner configured:
 *   * paypal.me        -> https://paypal.me/<who>/<amount><CUR>
 *   * legacy form      -> appends amount + currency_code
 *   * hosted button is returned untouched, because PayPal ignores `amount=` there
 *   * anything else is returned untouched too
 *
 * @param float $amount 0 = the plain link from .env
 */
function evershelfSupportDonateUrl(float $amount = 0.0): string
{
    $url = evershelfSupportPaypalUrl();
    if ($url === '' || $amount <= 0 || !evershelfSupportAmountsSupported()) {
        return $url;
    }
    $currency = evershelfSupportCurrency();
    $formatted = rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
    if (stripos($url, 'paypal.me/') !== false) {
        return rtrim($url, '/') . '/' . $formatted . $currency;
    }
    if (stripos($url, 'amount=') !== false) {
        return $url;
    }
    return $url . (str_contains($url, '?') ? '&' : '?') . 'amount=' . $formatted . '&currency_code=' . $currency;
}

/**
 * The running costs shown in the dialog, straight from .env so the numbers can
 * never drift from reality. Rows left at 0 are omitted (an owner who does not pay
 * for something should not have to explain it); the monthly equivalent adds the
 * monthly lines plus a twelfth of the yearly ones.
 */
function evershelfSupportCosts(): array
{
    $currency = strtoupper(trim((string)env('SUPPORT_COST_CURRENCY', 'EUR')));
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        $currency = 'EUR';
    }
    $definition = [
        ['key' => 'hosting',    'env' => 'SUPPORT_COST_HOSTING',    'period' => 'month'],
        ['key' => 'domain',     'env' => 'SUPPORT_COST_DOMAIN',     'period' => 'year'],
        ['key' => 'devaccount', 'env' => 'SUPPORT_COST_DEVACCOUNT', 'period' => 'year'],
        ['key' => 'api',        'env' => 'SUPPORT_COST_API',        'period' => 'month'],
    ];
    $rows = [];
    $monthly = 0.0;
    foreach ($definition as $row) {
        $amount = (float)str_replace(',', '.', (string)env($row['env'], '0'));
        if ($amount <= 0) {
            continue;
        }
        $rows[] = ['key' => $row['key'], 'amount' => round($amount, 2), 'period' => $row['period']];
        $monthly += $row['period'] === 'month' ? $amount : $amount / 12;
    }
    return [
        'currency' => $currency,
        'rows'     => $rows,
        'monthly'  => round($monthly, 2),
    ];
}

/**
 * Everything the client needs to draw the dialog. It travels inside get_settings
 * (no extra endpoint, no extra request at startup).
 *
 * `ads_enabled` is still sent as `false`: it is the honest answer while the banner
 * slots are parked, and it keeps a client cached in a kiosk WebView from rendering
 * anything. The banner provider keys are gone — see the parked block at the top of
 * this file if they ever come back.
 */
function evershelfSupportPayload(): array
{
    return [
        'mode'            => evershelfSupportMode(),
        'answered'        => evershelfSupportAnswered(),
        // When the household last answered (unix seconds, 0 = an answer stored before the
        // clock existed): the client uses it to decide whether a month has passed.
        'answered_at'     => (int)env('SUPPORT_ANSWERED_AT', '0'),
        'ads_enabled'     => evershelfSupportAdsEnabled(),
        'default_mode'    => evershelfSupportDefaultMode(),
        'paypal_url'      => evershelfSupportPaypalUrl(),
        'currency'        => evershelfSupportCurrency(),
        'amounts'         => evershelfSupportAmounts(),
        'amounts_supported' => evershelfSupportAmountsSupported(),
        'costs'           => evershelfSupportCosts(),
    ];
}
