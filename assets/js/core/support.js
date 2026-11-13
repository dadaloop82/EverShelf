// ===== SUPPORT: the one-off donation question ===============================
// The project is free but has running costs, so the app asks once — elegantly,
// and only once — whether the household wants to help. Everything the dialog
// shows comes from get_settings (`support`), built by api/lib/support.php: the
// client never invents a link, an amount or a cost figure.
//
// Two rules keep this honest:
//   * every user-visible string goes through a literal translation call, so the
//     i18n audit (scripts/i18n-audit.py) can see the key — hence the label maps
//     below instead of building the key name at runtime;
//   * nothing is blocked, hidden or degraded by the answer. The choice only
//     decides whether the donation card is shown.
//
// The answers are exactly two: "donate" (the PayPal card with its QR) and "later"
// (nothing for now — the default). Advertising is **not** part of this question:
// the banner slots and the "ads"/"both" answers they needed are parked, and the
// parked code is kept (commented) at the bottom of this file.
//
// Loaded as assets/js/core/support.js, before app.js: it only *defines* functions
// here and touches the app globals (_serverSettings, t(), escapeHtml(), showToast(),
// _saveSettingToServer, API_BASE) when one of them is called at runtime.

/** Icon per answer; the labels live in SUPPORT_MODE_LABELS (keys stay literal). */
const SUPPORT_MODE_ICONS = { donate: '💙', later: '⏳' };

const SUPPORT_MODE_LABELS = {
    donate: { title: () => t('support.option_donate_title'), desc: () => t('support.option_donate_desc') },
    later:  { title: () => t('support.option_later_title'),  desc: () => t('support.option_later_desc') },
    // PARKED (not used — EverShelf does not run ads): the two banner answers.
    // ads:  { title: () => t('support.option_ads_title'),  desc: () => t('support.option_ads_desc') },
    // both: { title: () => t('support.option_both_title'), desc: () => t('support.option_both_desc') },
};

/** Cost rows: same literal-key trick as above, and the same order as .env. */
const SUPPORT_COST_LABELS = {
    hosting:    () => t('support.cost_hosting'),
    domain:     () => t('support.cost_domain'),
    devaccount: () => t('support.cost_devaccount'),
    api:        () => t('support.cost_api'),
};

/** The `support` object of the last get_settings payload. */
function _supportPayload() {
    const payload = _serverSettings && _serverSettings.support;
    return (payload && typeof payload === 'object') ? payload : {};
}

/*
 * PARKED (not used — EverShelf does not run ads): the banner answers and the
 * greyed-out state they needed. With the slots gone there is nothing to disable,
 * so `_supportModeDisabled()` answers "false" for good and the effective mode is
 * simply the stored one.
 *
 * function _supportBannersAvailable() {
 *     return _supportPayload().banners_available === true;
 * }
 * function _supportModeNeedsBanners(mode) {
 *     return mode === 'ads' || mode === 'both';
 * }
 * function _supportModeDisabled(mode) {
 *     return _supportModeNeedsBanners(mode) && !_supportBannersAvailable();
 * }
 */

/** Nothing is unavailable any more: every offered answer can be picked. */
function _supportModeDisabled() {
    return false;
}

/**
 * The answer that is really in effect. A banner answer stored before the slots were
 * parked reads as "later", because that is what the app does — the Settings card
 * must never claim something the app is not doing.
 */
function _supportEffectiveMode() {
    const mode = _supportMode();
    return (mode === 'donate' || mode === 'later') ? mode : 'later';
}

/** The answer already in effect while nobody chose: "later" (the server states it). */
function _supportDefaultMode() {
    return _supportPayload().default_mode || 'later';
}

/**
 * True while the answer is donating. The dialog then drops the option cards and
 * becomes a thank-you with the QR: the household already decided, and there is
 * nothing else to offer any more.
 */
function _supportDonationFocus() {
    return _supportMode() === 'donate';
}

/** Set when the household asked to see the other answers again. */
let _supportOptionsRevealed = false;

/** The stored answer ('donate' | 'ads' | 'both' | 'later' | ''). */
function _supportMode() {
    return String(_supportPayload().mode || '');
}

/** True once the dialog was answered — "later" included. */
function _supportAnswered() {
    return _supportPayload().answered === true || _supportMode() !== '';
}

/*
 * PARKED (not used — EverShelf does not run ads): whether the banner slots are
 * filled right now. Always false, and the client keeps the function so a cached
 * payload/renderer cannot break.
 *
 * function _supportAdsEnabled() {
 *     return _demoMode ? false : _supportPayload().ads_enabled === true;
 * }
 */

/** Trim trailing zeros so 10.00 reads as 10 (PayPal accepts both). */
function _supportAmountText(value) {
    return String(value).replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '');
}

/** 3 -> "3 €", 2.5 -> "2.5 CHF": short enough for a chip. */
function _supportFormatMoney(value, currency) {
    const code = String(currency || 'EUR');
    const text = _supportAmountText(Number(value) || 0);
    if (code === 'EUR') return text + ' €';
    if (code === 'USD') return '$ ' + text;
    if (code === 'GBP') return '£ ' + text;
    return text + ' ' + code;
}

/**
 * Donation link for one amount, adapting to the configured PayPal flavour
 * (paypal.me gets the amount in the path, a donate button gets query values).
 */
function _supportDonateUrl(amount) {
    const base = String(_supportPayload().paypal_url || '');
    if (!base) return '';
    const value = Number(amount) || 0;
    if (value <= 0) return base;
    if (base.indexOf('paypal.me/') !== -1) {
        return base.replace(/\/+$/, '') + '/' + _supportAmountText(value) + String(_supportPayload().currency || 'EUR');
    }
    if (base.indexOf('amount=') !== -1) return base;
    return base + (base.indexOf('?') !== -1 ? '&' : '?') + 'amount=' + _supportAmountText(value)
        + '&currency_code=' + String(_supportPayload().currency || 'EUR');
}

/**
 * One line describing the current answer, for the Settings card. "ads"/"both" are
 * gone with the banner slots; a value left over from them reads as "later" (see
 * `_supportEffectiveMode()`), so their labels have been removed too.
 */
const SUPPORT_STATUS_LABELS = {
    '':     () => t('support.status_unanswered'),
    donate: () => t('support.status_donate'),
    later:  () => t('support.status_later'),
};

/**
 * The disclosed costs: one row per line the owner actually pays, plus the monthly
 * equivalent. Rows left empty in .env are simply not shown, so the list can never
 * claim a cost that does not exist.
 */
function _renderSupportCosts() {
    const box = document.getElementById('support-costs');
    if (!box) return;
    const costs = _supportPayload().costs || {};
    const rows = Array.isArray(costs.rows) ? costs.rows : [];
    if (rows.length === 0) {
        box.innerHTML = '';
        box.style.display = 'none';
        return;
    }
    const currency = costs.currency || 'EUR';
    let html = `<div class="support-costs-title">${escapeHtml(t('support.costs_title'))}</div><ul class="support-costs-list">`;
    for (const row of rows) {
        const label = SUPPORT_COST_LABELS[row.key];
        if (!label) continue;   // a new .env row without a translation is skipped, not printed raw
        const period = row.period === 'year' ? t('support.costs_per_year') : t('support.costs_per_month');
        html += '<li>'
            + `<span class="support-cost-label">${escapeHtml(label())}</span>`
            + `<span class="support-cost-amount">${escapeHtml(_supportFormatMoney(row.amount, currency))}`
            + `<em>/${escapeHtml(period)}</em></span></li>`;
    }
    html += '</ul>';
    if (Number(costs.monthly) > 0) {
        html += `<p class="support-costs-total">${escapeHtml(t('support.costs_total', {
            amount: _supportFormatMoney(costs.monthly, currency),
        }))}</p>`;
    }
    box.innerHTML = html;
    box.style.display = '';
}

/**
 * The answer cards (donation / later). One renderer for the dialog and the Settings
 * card, so the two can never show a different set of options.
 *
 * `disabled` is kept in the markup path because it is what a parked answer would
 * need again; today `_supportModeDisabled()` is always false, so no card is ever
 * greyed out.
 */
function _supportOptionCardsHtml() {
    // What the app is doing right now: the stored answer, or the default ("later").
    const current = _supportEffectiveMode() || _supportDefaultMode();
    const defaultMode = _supportDefaultMode();
    let html = '';
    for (const key of Object.keys(SUPPORT_MODE_ICONS)) {
        const label = SUPPORT_MODE_LABELS[key];
        const disabled = _supportModeDisabled(key);
        const active = !disabled && current === key;
        const chip = disabled ? t('support.chip_unavailable')
            : (key === defaultMode ? t('support.chip_default') : '');
        html += `<button type="button" class="support-option${active ? ' is-active' : ''}${disabled ? ' is-disabled' : ''}"`
            + ` data-support-mode="${key}"`
            + (disabled ? ' disabled aria-disabled="true"' : '')
            + ` onclick="supportChoose('${key}')">`
            + `<span class="support-option-icon">${SUPPORT_MODE_ICONS[key]}</span>`
            + '<span class="support-option-body">'
            + `<span class="support-option-title">${escapeHtml(label.title())}</span>`
            + `<span class="support-option-desc">${escapeHtml(label.desc())}</span>`
            // The badge sits under the text, not beside it: next to the label it
            // squeezed the title onto two lines and made the choice hard to read.
            + (chip ? `<span class="support-option-chip${disabled ? ' is-muted' : ''}">${escapeHtml(chip)}</span>` : '')
            + '</span>'
            + '<span class="support-option-check">✓</span>'
            + '</button>';
    }
    return html;
}

/** Paint both option lists (dialog + Settings card) and the status line. */
function _renderSupportOptions() {
    const html = _supportOptionCardsHtml();
    for (const id of ['support-options', 'settings-support-options']) {
        const box = document.getElementById(id);
        if (box) box.innerHTML = html;
    }
    const status = document.getElementById('settings-support-status');
    if (status) {
        const key = _supportEffectiveMode();
        const label = SUPPORT_STATUS_LABELS[key] || SUPPORT_STATUS_LABELS[''];
        status.textContent = label();
    }
    const donateBtn = document.getElementById('btn-support-donate');
    if (donateBtn) donateBtn.style.display = _supportPayload().paypal_url ? '' : 'none';
    _renderSupportFocus();
}

/**
 * The focused view: a donation answer hides the answer cards and shows the
 * thank-you (plus a discreet way back, so the choice stays changeable without
 * editing .env). The Settings card keeps its list either way — only the dialog
 * narrows down.
 */
function _renderSupportFocus() {
    const focus = _supportDonationFocus();
    const modal = document.getElementById('support-modal');
    const dialog = modal ? modal.querySelector('.support-dialog') : null;
    const inDialog = !!(dialog && modal.style.display === 'flex');
    const cardsHidden = focus && !_supportOptionsRevealed;
    const options = document.getElementById('support-options');
    // Only the dialog's own list is hidden: #settings-support-options is untouched.
    if (options) options.style.display = cardsHidden ? 'none' : '';
    const thanks = document.getElementById('support-thanks');
    // The thanks belongs to the decided state: once "Cambia scelta" brings the menu
    // back, the warm line steps aside with the cards.
    if (thanks) thanks.style.display = (focus && !_supportOptionsRevealed) ? '' : 'none';
    const change = document.getElementById('support-change');
    if (change) change.style.display = (cardsHidden && inDialog) ? '' : 'none';
    const donate = document.getElementById('support-donate');
    if (donate) donate.classList.toggle('is-focused', focus);
}

/** "Cambia scelta": bring the other answers back into the dialog. */
function _supportRevealOptions() {
    _supportOptionsRevealed = true;
    _renderSupportFocus();
    const options = document.getElementById('support-options');
    if (options && options.scrollIntoView) {
        options.scrollIntoView({ block: 'nearest' });
    }
}

// ── The dialog ───────────────────────────────────────────────────────────────
/** Open the dialog. `force` comes from the Settings card (re-read the choice). */
function openSupportModal(force) {
    if (!force && _demoMode) return;
    _supportOptionsRevealed = false;
    _renderSupportCosts();
    _renderSupportOptions();
    const donate = document.getElementById('support-donate');
    if (_supportDonationFocus()) {
        // Already decided to donate: open straight on the QR + thank-you, which is
        // the only thing this dialog is for now.
        _supportShowDonate();
    } else if (donate) {
        // The donation panel only opens when it is asked for: re-reading the choice
        // should not flash a QR code.
        donate.style.display = 'none';
    }
    document.getElementById('support-modal').style.display = 'flex';
    _renderSupportFocus();
}

function closeSupportModal() {
    const modal = document.getElementById('support-modal');
    if (modal) modal.style.display = 'none';
}

/**
 * Record the answer in .env and reflect it immediately: both option lists are
 * repainted and the donation panel opens when the household chose to give. The
 * answer stands until somebody changes it on purpose in Settings — this dialog is
 * not a recurring reminder.
 */
async function supportChoose(mode) {
    if (!SUPPORT_MODE_LABELS[mode]) return;
    // A stale card for a parked answer must not record anything.
    if (_supportModeDisabled(mode)) return;
    const modal = document.getElementById('support-modal');
    const fromCard = !(modal && modal.style.display === 'flex');
    // The clock of the answer travels with it: the question stays away for a month
    // (and comes back on a random boot after that, never on a schedule).
    const answeredAt = Math.floor(Date.now() / 1000);
    const saved = await _saveSettingToServer({
        support_mode: mode,
        support_decided: true,
        support_answered_at: String(answeredAt),
    });
    const payload = _supportPayload();
    _serverSettings = _serverSettings || {};
    _serverSettings.support = Object.assign({}, payload, {
        mode: mode,
        answered: true,
        answered_at: answeredAt,
        // Banner slots are parked: nothing to switch on, whatever was picked.
        ads_enabled: false,
    });
    _renderSupportOptions();
    _renderSupportCosts();
    if (saved) showToast('✅ ' + t('support.saved'), 'success');
    if (mode === 'donate') {
        // The household wants to give now: show the QR without another click.
        _supportShowDonate();
        return;
    }
    if (!fromCard) closeSupportModal();
}

/** «Later» keeps the question away for this long… */
const SUPPORT_REMINDER_DAYS = 45;
/** …and past it, one start in four brings it back: ~ every other month, never a schedule. */
const SUPPORT_REMINDER_CHANCE = 0.25;

/**
 * Called once per startup (after the settings are known and after the first-run
 * wizard) — and again when the wizard finishes, so a fresh install still gets the
 * question without two dialogs stacked on each other.
 *
 * The question is asked while nothing was decided. Closing it with ✕ is not an answer,
 * so it returns at the next start. After that:
 *   * **donate** — never asked again, on any start: the household has already seen the
 *     QR and done its part. It only changes if somebody opens it in Settings;
 *   * **later** — quiet for a month and a half, then back on a random start (one boot
 *     in four): about one reminder every other month, and never on a date the family
 *     can predict («a random un mese sì e uno no»).
 */
function _maybeShowSupportPrompt() {
    if (_demoMode) return;
    if (_supportAnswered()) {
        if (_supportEffectiveMode() === 'donate') return;
        const payload = _supportPayload();
        const answeredAt = Number(payload.answered_at || 0);
        if (!answeredAt) {
            // An answer stored before this clock existed (SUPPORT_ANSWERED_AT): start it
            // now, once, so the reminder has a date to count from. Written through the
            // normal save path, so a read-only `.env` keeps it in app_settings.
            try {
                const ts = Math.floor(Date.now() / 1000);
                if (_serverSettings && _serverSettings.support) _serverSettings.support.answered_at = ts;
                _saveSettingToServer({ support_answered_at: String(ts) }).catch(() => {});
            } catch (_) { /* the dialog simply stays away */ }
            return;
        }
        const days = (Date.now() / 1000 - answeredAt) / 86400;
        if (days < SUPPORT_REMINDER_DAYS) return;
        if (Math.random() > SUPPORT_REMINDER_CHANCE) return;
    }
    const wizard = document.getElementById('setup-wizard');
    if (wizard && wizard.style.display !== 'none') return;
    // A modal already open (an alert, the item chooser) wins: asking later is fine,
    // interrupting the user twice is not.
    const overlay = document.getElementById('modal-overlay');
    if (overlay && overlay.style.display === 'flex') return;
    openSupportModal(false);
}

// ── Donation panel (QR + amount chips) ───────────────────────────────────────
// Last link shown (one amount of it), so "copy link" copies exactly what the QR
// encodes — not a recomputed guess.
let _supportCurrentDonateUrl = '';

/** Show the donation panel, opening the dialog around it when needed. */
function _supportOpenDonate() {
    if (!_supportPayload().paypal_url) {
        showToast(t('support.donate_unconfigured'), 'error');
        return;
    }
    openSupportModal(true);
    _supportShowDonate();
}

function _supportShowDonate(amount) {
    const panel = document.getElementById('support-donate');
    if (!panel) return;
    panel.style.display = '';
    const selected = Number(amount) || 0;
    _supportRenderAmounts(selected);
    _supportSetAmount(selected);
    const status = document.getElementById('support-donate-status');
    if (status) status.textContent = '';
}

/** Amount chips; without SUPPORT_PAYPAL_AMOUNTS only the plain link is offered. */
function _supportRenderAmounts(selected) {
    const box = document.getElementById('support-amounts');
    if (!box) return;
    const payload = _supportPayload();
    const amounts = Array.isArray(payload.amounts) ? payload.amounts : [];
    // A hosted donate button ignores a pre-filled amount (PayPal shows its own
    // presets and an editable field), so no amount buttons are offered there: a
    // chip would promise a figure the donation page never receives.
    if (payload.amounts_supported === false) {
        box.innerHTML = `<span class="support-amounts-label">${escapeHtml(t('support.donate_amount_paypal'))}</span>`;
        box.style.display = 'flex';
        return;
    }
    if (amounts.length === 0) {
        box.innerHTML = '';
        box.style.display = 'none';
        return;
    }
    const currency = payload.currency || 'EUR';
    let html = `<span class="support-amounts-label">${escapeHtml(t('support.donate_amount_label'))}</span>`;
    html += `<button type="button" class="support-amount${selected ? '' : ' is-active'}" onclick="_supportSetAmount(0)">${escapeHtml(t('support.donate_amount_free'))}</button>`;
    for (const amount of amounts) {
        const isActive = Number(selected) === Number(amount);
        html += `<button type="button" class="support-amount${isActive ? ' is-active' : ''}" onclick="_supportSetAmount(${Number(amount)})">${escapeHtml(_supportFormatMoney(amount, currency))}</button>`;
    }
    box.innerHTML = html;
    box.style.display = 'flex';
}

/**
 * Point the QR, the link and the copyable URL at one amount. The QR is rendered by
 * the server (api/index.php?action=support_qr, see api/lib/qr.php) so the web app
 * and the native kiosk wizard show the very same code.
 */
function _supportSetAmount(amount) {
    const url = _supportDonateUrl(amount);
    _supportCurrentDonateUrl = url;
    const qr = document.getElementById('support-qr');
    if (qr) qr.src = `${API_BASE}?action=support_qr&amount=${encodeURIComponent(amount || 0)}&scale=6`;
    const link = document.getElementById('support-donate-link');
    if (link) link.href = url;
    const code = document.getElementById('support-donate-url');
    if (code) code.textContent = url;
    _supportRenderAmounts(amount);
}

/** Copy the donation link (clipboard API with a textarea fallback for plain HTTP). */
async function _supportCopyLink() {
    const url = _supportCurrentDonateUrl || _supportDonateUrl(0);
    if (!url) return;
    let copied = false;
    try {
        await navigator.clipboard.writeText(url);
        copied = true;
    } catch (e) {
        try {
            const field = document.createElement('textarea');
            field.value = url;
            field.setAttribute('readonly', '');
            field.style.position = 'fixed';
            field.style.opacity = '0';
            document.body.appendChild(field);
            field.select();
            copied = document.execCommand('copy');
            document.body.removeChild(field);
        } catch (e2) { copied = false; }
    }
    const status = document.getElementById('support-donate-status');
    if (status) status.textContent = copied ? t('support.donate_copied') : url;
    if (copied) showToast('✅ ' + t('support.donate_copied'), 'success');
}

// ── Banner slots (PARKED — EverShelf does not run ads) ───────────────────────
// The dashboard and screensaver slots, the house banner and the AdSense loader are
// kept here for reference only: the project does not run advertising, so nothing in
// the UI fills a slot any more and the `[data-ad-slot]` divs are commented out in
// index.html. `_renderAdSlots()` survives as a no-op so a client cached in a kiosk
// WebView (which may still call it) cannot throw.
//
// To bring the banners back: restore the renderer below, the two .ad-slot divs in
// index.html, the CSS block, the payload keys in api/lib/support.php and the .env
// keys — the checklist is in docs/wiki/Support-and-ads.md ("Banner slots (parked)").
function _renderAdSlots() {
    // Intentionally empty: no slot is ever filled.
}

/*
 * let _adsenseScriptLoaded = false;
 *
 * /** Fill the two banner slots (dashboard + screensaver) or hide them. *\/
 * function _renderAdSlots() {
 *     const slots = document.querySelectorAll('[data-ad-slot]');
 *     if (typeof navigator !== 'undefined' && navigator.onLine === false) {
 *         slots.forEach(el => {
 *             el.innerHTML = '';
 *             el.style.display = 'none';
 *         });
 *         return;
 *     }
 *     slots.forEach(el => {
 *         _renderAdSlot(el, el.dataset.adSlot);
 *     });
 * }
 *
 * function _renderAdSlot(el, kind) {
 *     const payload = _supportPayload();
 *     if (!_supportAdsEnabled()) {
 *         el.innerHTML = '';
 *         el.style.display = 'none';
 *         return;
 *     }
 *     const provider = payload.provider || 'house';
 *     if (provider === 'none') {
 *         el.innerHTML = '';
 *         el.style.display = 'none';
 *         return;
 *     }
 *     if (provider === 'adsense') {
 *         const client = String(payload.adsense_client || '');
 *         const slot = String(kind === 'screensaver' ? (payload.adsense_slot_screensaver || '') : (payload.adsense_slot_app || ''));
 *         // An incomplete AdSense setup falls back to the house banner instead of
 *         // leaving an empty box on the dashboard.
 *         if (!/^ca-pub-\d{6,}$/.test(client) || !/^\d{3,}$/.test(slot)) {
 *             _renderHouseAd(el, kind);
 *             return;
 *         }
 *         _loadAdsenseScript(client);
 *         el.innerHTML = `<ins class="adsbygoogle" style="display:block" data-ad-client="${escapeAttr(client)}"`
 *             + ` data-ad-slot="${escapeAttr(slot)}" data-ad-format="auto" data-full-width-responsive="true"></ins>`;
 *         el.style.display = 'block';
 *         try { (window.adsbygoogle = window.adsbygoogle || []).push({}); } catch (e) { /* an ad blocker is not an error *\/ }
 *         return;
 *     }
 *     if (provider === 'custom') {
 *         // Owner-supplied markup from .env (Amazon Associates, EthicalAds, Ezoic…).
 *         // Never written through the API, so it is configuration, not user input.
 *         const html = String(kind === 'screensaver' ? (payload.custom_screensaver_html || '') : (payload.custom_app_html || ''));
 *         if (!html) {
 *             el.innerHTML = '';
 *             el.style.display = 'none';
 *             return;
 *         }
 *         el.innerHTML = html;
 *         el.style.display = 'block';
 *         return;
 *     }
 *     _renderHouseAd(el, kind);
 * }
 *
 * /**
 *  * The house banner: no third party, no account, works from day one.
 *  *\/
 * function _renderHouseAd(el, kind) {
 *     const hasDonate = !!_supportPayload().paypal_url;
 *     const cta = hasDonate ? t('support.house_cta') : t('support.house_cta_settings');
 *     el.innerHTML = `<div class="house-ad${kind === 'screensaver' ? ' house-ad-screensaver' : ''}">`
 *         + '<img class="house-ad-logo" src="assets/img/logo/logo_icon.png" alt="">'
 *         + '<div class="house-ad-text">'
 *         + `<strong>${escapeHtml(t('support.house_title'))}</strong>`
 *         + `<span>${escapeHtml(t('support.house_text'))}</span>`
 *         + '</div>'
 *         + `<button type="button" class="btn btn-small btn-accent" onclick="${hasDonate ? '_supportOpenDonate()' : 'openSupportModal(true)'}">${escapeHtml(cta)}</button>`
 *         + '</div>';
 *     el.style.display = 'block';
 * }
 *
 * /** Load the AdSense library once, and only when a slot actually needs it. *\/
 * function _loadAdsenseScript(client) {
 *     if (_adsenseScriptLoaded) return;
 *     _adsenseScriptLoaded = true;
 *     const script = document.createElement('script');
 *     script.async = true;
 *     script.crossOrigin = 'anonymous';
 *     script.src = 'https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' + encodeURIComponent(client);
 *     document.head.appendChild(script);
 * }
 *
 * // The banner slots used to follow the connection: hidden the moment the browser
 * // reported it was offline, filled again as soon as it was back.
 * if (typeof window !== 'undefined' && typeof window.addEventListener === 'function') {
 *     window.addEventListener('online', () => {
 *         if (typeof _renderAdSlots === 'function') _renderAdSlots();
 *     });
 *     window.addEventListener('offline', () => {
 *         if (typeof _renderAdSlots === 'function') _renderAdSlots();
 *     });
 * }
 */
