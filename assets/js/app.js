/**
 * EverShelf - Main Application JS
 * Complete pantry management with barcode scanning, AI identification,
 * Bring! shopping list integration, recipe generation, and TTS cooking mode.
 *
 * @author Stimpfl Daniel <evershelfproject@gmail.com>
 * @license MIT
 */

// ===== REMOTE LOGGING + ERROR REPORTING =====
// Two-tier system:
//  1. remoteLog() — batched INFO/WARN/ERROR → existing client_log endpoint (debug tail)
//  2. reportError() — immediate single POST → report_error endpoint → GitHub Issue

const _remoteLogBuffer = [];
const _OFFLINE_LOGS_KEY   = '_evershelf_offline_logs';  // buffered log msgs while offline
const _OFFLINE_ERRORS_KEY = '_evershelf_offline_errors'; // buffered error reports while offline
let _remoteLogTimer = null;
const _origConsoleError = console.error.bind(console);
const _origConsoleWarn = console.warn.bind(console);

function remoteLog(level, ...args) {
    const msg = args.map(a => {
        if (a instanceof Error) return `${a.name}: ${a.message}`;
        if (typeof a === 'object') try { return JSON.stringify(a); } catch { return String(a); }
        return String(a);
    }).join(' ');
    _remoteLogBuffer.push(`[${level}] ${msg}`);
    if (!_remoteLogTimer) {
        _remoteLogTimer = setTimeout(flushRemoteLog, 2000);
    }
}

function flushRemoteLog() {
    _remoteLogTimer = null;
    if (_remoteLogBuffer.length === 0) return;
    const msgs = _remoteLogBuffer.splice(0);
    // If offline, buffer for flush on reconnect instead of losing them
    const isOfflineNow = (typeof _serverOffline !== 'undefined' && _serverOffline) ||
                         (typeof _networkDown    !== 'undefined' && _networkDown)    ||
                         (typeof _offlineMode    !== 'undefined' && _offlineMode);
    if (isOfflineNow) { _bufferOfflineLogs(msgs); return; }
    fetch(`api/index.php?action=client_log`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ messages: msgs })
    }).catch(() => { _bufferOfflineLogs(msgs); }); // store if request itself fails
}

function _bufferOfflineLogs(msgs) {
    try {
        const pending = JSON.parse(localStorage.getItem(_OFFLINE_LOGS_KEY) || '[]');
        pending.push(...msgs);
        if (pending.length > 500) pending.splice(0, pending.length - 500);
        localStorage.setItem(_OFFLINE_LOGS_KEY, JSON.stringify(pending));
    } catch(e) {}
}

// Override console.error and console.warn to also send remotely
console.error = function(...args) {
    _origConsoleError(...args);
    remoteLog('ERROR', ...args);
};
console.warn = function(...args) {
    _origConsoleWarn(...args);
    remoteLog('WARN', ...args);
};

// ── Error reporter: creates/updates GitHub Issues ────────────────────────────
// Rate-limit client-side: max 1 report per fingerprint per page session.
const _reportedFingerprints = new Set();

function reportError(payload) {
    // Build fingerprint to deduplicate within the same page session
    const fp = `${payload.source}:${payload.type}:${String(payload.message).slice(0, 120)}`;
    if (_reportedFingerprints.has(fp)) return;
    _reportedFingerprints.add(fp);

    const body = Object.assign({
        source:     'pwa',
        version:    document.querySelector('.header-version')?.textContent?.trim() || '',
        url:        location.href,
        user_agent: navigator.userAgent,
        ts:         new Date().toISOString(),
    }, payload);

    // When offline, buffer for replay when reconnected (→ GitHub issue on restore)
    const isOfflineNow = (typeof _serverOffline !== 'undefined' && _serverOffline) ||
                         (typeof _networkDown    !== 'undefined' && _networkDown)    ||
                         (typeof _offlineMode    !== 'undefined' && _offlineMode);
    if (isOfflineNow) { _bufferOfflineError(body); return; }

    fetch('api/index.php?action=report_error', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(body),
    }).catch(() => { _bufferOfflineError(body); }); // store if request itself fails
    // Note: the server will also skip issue creation if this version is not the latest.
}

function _bufferOfflineError(body) {
    try {
        const pending = JSON.parse(localStorage.getItem(_OFFLINE_ERRORS_KEY) || '[]');
        pending.push(body);
        if (pending.length > 50) pending.splice(0, pending.length - 50);
        localStorage.setItem(_OFFLINE_ERRORS_KEY, JSON.stringify(pending));
    } catch(e) {}
}

// ── Webapp update notification ───────────────────────────────────────────────
// Checks both the deployed webapp version and the latest GitHub release.
// Fires on tab focus and every 5 minutes.
const _loadedVersion = (document.querySelector('.header-version')?.textContent?.trim() || '').replace(/^v/, '');

// ── Broken image fallback ─────────────────────────────────────────────────────
// External product images (Open Food Facts, etc.) are unavailable when offline.
// Replace any broken <img> with a neutral grey placeholder so the layout stays intact.
document.addEventListener('error', (e) => {
    if (e.target.tagName !== 'IMG' || e.target.dataset.offlineErr) return;
    e.target.dataset.offlineErr = '1';
    // 60x60 grey placeholder SVG with a '?' glyph
    e.target.src = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='60' height='60'%3E%3Crect width='60' height='60' rx='8' fill='%231e293b'/%3E%3Ctext x='30' y='38' text-anchor='middle' fill='%2364748b' font-size='24' font-family='sans-serif'%3E%3F%3C/text%3E%3C/svg%3E";
    e.target.style.opacity = '0.45';
}, true);

// ── Gemini AI availability ────────────────────────────────────────────────────
// Set to true in _initApp / syncSettingsFromDB once server confirms key is set.
// All AI entry points call _requireGemini() before opening camera / API calls.
let _geminiAvailable = false;
let _demoMode = false;

function _requireGemini() {
    if (_geminiAvailable) return true;
    showToast(
        '🤖 ' + t('error.no_api_key'),
        'warning',
        6000
    );
    return false;
}

// Update Gemini button visual state to signal no key configured
function _updateGeminiButtonState() {
    const btn = document.querySelector('.header-gemini-btn');
    if (!btn) return;
    if (_geminiAvailable) {
        btn.classList.remove('header-btn-no-ai');
        btn.removeAttribute('title');
        btn.setAttribute('title', t('gemini.chat_title'));
    } else {
        btn.classList.add('header-btn-no-ai');
        btn.setAttribute('title', t('gemini.not_configured'));
    }
}

function _applyDemoModeUI() {
    if (!_demoMode) return;
    // In demo mode Gemini is always "available" — no real key needed
    _geminiAvailable = true;
    _updateGeminiButtonState();
    // Hide the settings ⚙️ nav button
    document.querySelectorAll('.nav-btn[data-page="settings"]').forEach(btn => {
        btn.style.display = 'none';
    });
    // Prevent the setup wizard from showing
    const wizard = document.getElementById('setup-wizard');
    if (wizard) wizard.style.display = 'none';
    // Show a small demo badge in the header
    const headerLeft = document.getElementById('header-left');
    if (headerLeft && !document.getElementById('_demo_badge')) {
        const badge = document.createElement('span');
        badge.id = '_demo_badge';
        badge.textContent = 'DEMO';
        badge.style.cssText = 'font-size:0.6rem;font-weight:800;letter-spacing:0.08em;background:rgba(251,191,36,0.35);color:#fef3c7;border:1px solid rgba(251,191,36,0.5);border-radius:4px;padding:2px 5px;white-space:nowrap;';
        headerLeft.appendChild(badge);
    }
}

function _semverGt(a, b) {
    // Returns true if version string a is strictly greater than b (e.g. "1.7.25" > "1.7.23")
    const pa = a.split('.').map(Number);
    const pb = b.split('.').map(Number);
    for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
        const na = pa[i] || 0, nb = pb[i] || 0;
        if (na !== nb) return na > nb;
    }
    return false;
}

function _checkWebappUpdate() {
    const STORAGE_KEY  = '_evershelf_update_checked_at';
    const SEEN_KEY     = '_evershelf_update_seen_ts';
    const TTL_MS       = 5 * 60 * 1000;
    const now = Date.now();
    const lastCheck = parseInt(localStorage.getItem(STORAGE_KEY) || '0', 10);
    if (now - lastCheck < TTL_MS) return;
    localStorage.setItem(STORAGE_KEY, String(now));

    fetch('api/index.php?action=check_update', { method: 'GET' })
        .then(r => r.ok ? r.json() : null)
        .then(data => {
            if (!data) return;
            // Already showing — don't stack
            if (document.getElementById('_header_update_pill')) return;

            // ── Check 1: server has a newer version deployed since this page loaded ──
            const serverVer     = (data.webapp_version || '').replace(/^v/, '');
            const deployChanged = serverVer && _loadedVersion && _semverGt(serverVer, _loadedVersion);

            // ── Check 2: a newer GitHub release not yet acknowledged ──
            const publishedAt  = data.published_at || '';
            const seenTs       = localStorage.getItem(SEEN_KEY) || '';
            const latestTag    = (data.latest_tag || '').replace(/^v/, '');
            const releaseNewer = publishedAt && publishedAt !== seenTs &&
                                 /^\d+\.\d+/.test(latestTag) &&
                                 _loadedVersion && _semverGt(latestTag, _loadedVersion);

            if (!deployChanged && !releaseNewer) return;

            // ── Show update badge alongside the title (title stays intact) ──
            const badge = document.getElementById('header-update-badge');
            if (!badge) return;

            const versionLabel = deployChanged
                ? (serverVer ? `v${serverVer}` : t('update.new_version'))
                : (latestTag ? `v${latestTag}` : t('update.new_version'));

            const hideBadge = () => {
                badge.style.display = 'none';
                badge.innerHTML = '';
                if (!deployChanged) localStorage.setItem(SEEN_KEY, publishedAt);
            };

            badge.innerHTML =
                `<span class="header-update-badge-label">⬆️ ${versionLabel}</span>` +
                `<button class="header-update-btn" onclick="window.location.reload()">${t('update.btn')}</button>` +
                `<button class="header-update-close" id="_header_update_close">✕</button>`;
            badge.style.display = 'inline-flex';

            document.getElementById('_header_update_close').onclick = (e) => {
                e.stopPropagation();
                hideBadge();
            };
            // Auto-hide after 60 s without marking as seen
            setTimeout(() => { if (badge.style.display !== 'none') hideBadge(); }, 60000);
        })
        .catch(() => {});
}

// ── Global uncaught error handler ────────────────────────────────────────────
window.addEventListener('error', function(e) {
    const msg = e.message || String(e.error);
    // Ignore benign third-party noise
    if (/Script error/i.test(msg)) return;
    remoteLog('UNCAUGHT', `${msg} at ${e.filename}:${e.lineno}:${e.colno}`);
    reportError({
        type:    'uncaught-error',
        message: msg,
        stack:   e.error?.stack || '',
        context: { filename: e.filename, lineno: e.lineno, colno: e.colno },
    });
});

window.addEventListener('unhandledrejection', function(e) {
    const reason = e.reason;
    const msg  = reason instanceof Error ? reason.message : String(reason);
    const stack = reason instanceof Error ? (reason.stack || '') : '';
    remoteLog('UNHANDLED_PROMISE', msg);
    reportError({
        type:    'unhandled-promise',
        message: msg,
        stack:   stack,
    });
});

// ===== CONFIGURATION =====
const API_BASE = 'api/index.php';

// ===== SMART SCALE GATEWAY =====
// Connects to the Android BLE-WebSocket gateway and provides auto weight reading.

let _scaleEs = null;           // EventSource for the SSE relay
let _scaleConnected = false;
let _scaleDevice = null;
let _scaleBattery = null;
let _scaleReconnectTimer = null;
let _scaleWeightCallback = null; // pending on-demand weight request callback
let _scaleLatestWeight = null;   // last received weight message
let _scaleAutoConfirmTimer = null; // countdown timer for auto-confirm after stable weight
let _scaleAutoConfirmRAF   = null; // rAF handle for auto-confirm progress bar animation
let _scaleStabilityTimer   = null; // setTimeout: wait 5 s stable before starting confirm bar
let _scaleStabilityRAF     = null; // rAF handle for stability progress bar in the live box
let _scaleStabilityVal     = null; // value we are currently timing for stability
let _scaleUserDismissed    = false; // user tapped or edited → don't retrigger for same value
let _scaleRecipeAutoFillPaused = false; // pause flag for recipe-use modal only
let _scaleLastConfirmedGrams = null; // grams of last auto-confirmed weight (to detect product change)
let _scaleLastStableGrams = null; // last accepted stable reading in grams (for jitter filtering)

function _scaleToGrams(value, unit) {
    if (!isFinite(value)) return null;
    const u = (unit || 'g').toLowerCase();
    if (u === 'kg') return value * 1000;
    if (u === 'lbs' || u === 'lb') return value * 453.592;
    if (u === 'oz') return value * 28.3495;
    return value; // g / ml treated as grams-equivalent for stability filtering
}

function scaleInit() {
    const s = getSettings();
    const indicator = document.getElementById('scale-status-indicator');
    if (!s.scale_enabled || !s.scale_gateway_url) {
        if (indicator) indicator.style.display = 'none';
        if (_scaleEs) { try { _scaleEs.close(); } catch(e) {} _scaleEs = null; }
        return;
    }
    if (indicator) indicator.style.display = '';
    _scaleConnect(s.scale_gateway_url);
}

function _scaleAuthQuery() {
    const tok = typeof getApiToken === 'function' ? getApiToken() : '';
    return tok ? '&api_token=' + encodeURIComponent(tok) : '';
}

function _scaleConnect(url) {
    if (_scaleEs) { try { _scaleEs.close(); } catch(e) {} _scaleEs = null; }
    if (_scaleReconnectTimer) { clearTimeout(_scaleReconnectTimer); _scaleReconnectTimer = null; }
    try {
        // EventSource cannot send custom headers — pass api_token in query string
        _scaleEs = new EventSource('api/scale_relay.php?url=' + encodeURIComponent(url) + _scaleAuthQuery());
        _scaleEs.onopen  = () => _scaleUpdateStatus('searching');
        _scaleEs.onmessage = (evt) => {
            try { _scaleOnMessage(JSON.parse(evt.data)); } catch(e) {}
        };
        _scaleEs.onerror = () => {
            _scaleConnected = false;
            _scaleDevice = null;
            _scaleUpdateStatus('disconnected');
            // EventSource auto-reconnects; no manual timer needed
        };
    } catch(e) {
        _scaleUpdateStatus('error');
    }
}

function _scaleOnMessage(msg) {
    if (msg.type === 'status') {
        _scaleConnected = msg.state === 'connected';
        _scaleDevice = msg.device || null;
        _scaleBattery = msg.battery ?? null;
        _scaleUpdateStatus(_scaleConnected ? 'connected' : 'searching');
        // Update protocol info in settings diagnostic
        if (msg.protocol) {
            const protoEl = document.getElementById('scale-diag-proto');
            if (protoEl) protoEl.textContent = `📡 ${msg.protocol}`;
        }
        // Refresh all scale UI elements immediately so buttons/live-box appear
        // without requiring a manual page refresh
        updateScaleReadButtons();
    } else if (msg.type === 'weight') {
        // Ignore negative weight values (tare artifacts, sensor noise)
        const rawValue = parseFloat(msg.value);
        if (rawValue < 0) return;

        // Ignore sub-2g jitter for stability decisions: changes below 2g are considered noise.
        const SCALE_NOISE_G = 2;
        let effectiveStable = !!msg.stable;
        const grams = _scaleToGrams(rawValue, msg.unit);
        if (grams !== null) {
            if (effectiveStable) {
                _scaleLastStableGrams = grams;
            } else if (_scaleLastStableGrams !== null) {
                if (Math.abs(grams - _scaleLastStableGrams) < SCALE_NOISE_G) {
                    effectiveStable = true;
                }
            }
            if (effectiveStable) {
                _scaleLastStableGrams = grams;
            }
        }

        const liveMsg = effectiveStable === msg.stable ? msg : { ...msg, stable: effectiveStable };
        _scaleLatestWeight = liveMsg;
        // Update live reading modal overlay if visible (scale-read modal)
        const live = document.getElementById('scale-reading-live');
        if (live) live.textContent = `${msg.value} ${msg.unit || 'kg'}${liveMsg.stable ? ' ✓' : ' …'}`;
        // Also update edit-form inline scale reading if visible
        const editLive = document.getElementById('edit-scale-reading');
        if (editLive) editLive.textContent = `${msg.value} ${msg.unit || 'kg'}${liveMsg.stable ? ' ✓' : ' …'}`;
        // Update settings diagnostic live weight
        const diagW = document.getElementById('scale-diag-weight');
        if (diagW) diagW.textContent = `${parseFloat(msg.value).toFixed(1)} ${msg.unit || 'g'}`;
        // Always update the persistent live box on the use page (every message, stable or not)
        _scaleUpdateLiveBox(liveMsg);
        // If weight is NOT stable: stop any running timer/bar but keep the sentinel value.
        // The sentinel is reset only when a genuinely different stable value arrives.
        if (!liveMsg.stable) {
            _cancelScaleTimersOnly();
        }
        // Fulfil pending callback on stable reading
        if (liveMsg.stable && _scaleWeightCallback) {
            const cb = _scaleWeightCallback;
            _scaleWeightCallback = null;
            cb(liveMsg);
        }
        // Drive stability logic on use page
        if (liveMsg.stable && _currentPageId === 'use') {
            _scaleAutoFillUse(liveMsg);
        }
        // Same for recipe-use modal
        if (liveMsg.stable && document.getElementById('ruse-quantity') && !_scaleRecipeAutoFillPaused) {
            _scaleAutoFillRecipeUse(liveMsg);
        }
    }
}

/**
 * Returns the liquid density (g/ml) for a product based on its name/category.
 * Used to convert scale grams → ml for products stored in ml.
 */
function _scaleDensityForProduct(product) {
    const n   = (product?.name     || '').toLowerCase();
    const cat = (product?.category || '').toLowerCase();
    // Oils (lighter than water)
    if (/olio.oliva|olive.oil/.test(n))               return 0.91;
    if (/olio.girasole|sunflower.oil/.test(n))         return 0.92;
    if (/\bolio\b|\boil\b/.test(n))                   return 0.92;
    // Spirits / alcohol (lighter than water)
    if (/vodka|whisky|whiskey|grappa|rum|gin\b/.test(n)) return 0.94;
    // Vinegar, wine, beer (close to water)
    if (/aceto|vinegar/.test(n))                      return 1.01;
    if (/\bvino\b|\bwine\b|\bbirra\b|\bbeer\b/.test(n)) return 1.00;
    // Milk & dairy liquids
    if (/\blatte\b|\bmilk\b/.test(n))                 return 1.03;
    if (/panna|cream/.test(n))                        return 1.01;
    if (/yogurt/.test(n))                             return 1.05;
    // Juice
    if (/succo|juice|spremuta/.test(n))               return 1.04;
    // Honey / syrups (dense)
    if (/miele|honey|sciroppo|syrup/.test(n))         return 1.40;
    // Water / sparkling
    if (/\bacqua\b|\bwater\b/.test(n))                return 1.00;
    // Category-level fallbacks
    if (/latticin/.test(cat))                         return 1.03;
    if (/condiment/.test(cat))                        return 0.92; // likely oil-based
    if (/bevand/.test(cat))                           return 1.00;
    return 1.00; // safe default (water)
}

/**
 * Update the persistent live-weight box on the use page (called on every weight message).
 * Shows raw scale reading in real time regardless of stability or unit compatibility.
 */
function _scaleUpdateLiveBox(msg) {
    const box    = document.getElementById('scale-live-box');
    if (!box) return;
    const s = getSettings();
    const active = s.scale_enabled && s.scale_gateway_url && _scaleConnected &&
                   _currentPageId === 'use';
    box.style.display = active ? '' : 'none';
    if (!active) return;

    const raw     = parseFloat(msg.value);
    const rawUnit = (msg.unit || 'kg').toLowerCase();
    // Convert to grams for the < 2 g threshold check
    let gForCheck = isFinite(raw) ? raw : 0;
    if (rawUnit === 'kg')  gForCheck = raw * 1000;
    if (rawUnit === 'lbs' || rawUnit === 'lb') gForCheck = raw * 453.592;

    const valEl   = document.getElementById('scale-live-val');
    const lblEl   = document.getElementById('scale-live-label');

    if (isFinite(raw) && gForCheck < 2 && gForCheck > 0) {
        // Weight too low — show red flashing warning
        box.classList.add('scale-low-weight');
        if (valEl) valEl.textContent = `${raw} ${msg.unit || 'kg'}`;
        if (lblEl) lblEl.textContent = t('scale.low_weight');
    } else {
        box.classList.remove('scale-low-weight');
        const stIcon = msg.stable ? ' ✓' : ' …';
        // Show converted ML if target unit is ml (instead of raw grams)
        let displayVal = `${isFinite(raw) ? raw : '—'} ${msg.unit || 'kg'}`;
        let targetUnit = null;
        if (_useConfMode && _useConfMode._activeUnit === 'sub') {
            targetUnit = (_useConfMode.packageUnit || '').toLowerCase();
        } else {
            targetUnit = _useNormalUnit;
        }
        if (targetUnit === 'ml' && rawUnit !== 'ml' && isFinite(raw) && raw > 0) {
            let grams = raw;
            if (rawUnit === 'kg') grams = raw * 1000;
            else if (rawUnit === 'lbs' || rawUnit === 'lb') grams = raw * 453.592;
            else if (rawUnit === 'oz') grams = raw * 28.3495;
            const density = _scaleDensityForProduct(currentProduct);
            const ml = Math.round(grams / density);
            displayVal = `${ml} ml`;
        }
        if (valEl) valEl.textContent = displayVal + stIcon;
        if (lblEl) {
            const targetLbl = getUnitDisplayLabel(getActiveUseUnitLabel());
            lblEl.textContent = targetLbl ? ((t('qty.enter_in') || 'Inserimento in') + ' ' + targetLbl) : '';
        }
    }
}

/**
 * Auto-fill: called on every STABLE weight message while on the use page.
 * - Updates the live box (conversion hint)
 * - After 5 s of stable unchanged value: fills the input and starts the confirm progress bar
 * - If value changes: resets the 5-s stability wait
 * - If user dismissed (touch/edit): does nothing for the same value; resets on value change
 */
function _scaleAutoFillUse(msg) {
    if (!msg) return;

    // Determine target unit
    let unit;
    if (_useConfMode) {
        // Scale reads weight (g/ml) — only auto-fill in sub-unit mode.
        // If the user chose "Confezioni", respect that (fraction buttons / manual qty).
        if (_useConfMode._activeUnit === 'conf') return;
        unit = (_useConfMode.packageUnit || '').toLowerCase();
    } else {
        unit = _useNormalUnit;
    }
    if (unit !== 'g' && unit !== 'ml') return; // pz / conf-unit: ignore

    const rawVal = parseFloat(msg.value);
    if (!isFinite(rawVal) || rawVal <= 0) return;
    const srcUnit = (msg.unit || '').toLowerCase();

    // Normalise to grams
    let grams;
    let scaleAlreadyMl = false;
    if      (srcUnit === 'g')                       grams = rawVal;
    else if (srcUnit === 'kg')                      grams = rawVal * 1000;
    else if (srcUnit === 'lbs' || srcUnit === 'lb') grams = rawVal * 453.592;
    else if (srcUnit === 'oz')                      grams = rawVal * 28.3495;
    else if (srcUnit === 'ml')  { grams = rawVal; scaleAlreadyMl = true; }
    else                                            grams = rawVal;

    // Reject if raw grams < 10 (piatto vuoto / tara / rumore)
    if (grams < 10) {
        _cancelScaleStabilityWait(); // stop bar only; keep sentinel & userDismissed
        return;
    }

    // Reject if weight hasn't changed enough from last confirmed reading (same product still on scale)
    if (_scaleLastConfirmedGrams !== null && Math.abs(grams - _scaleLastConfirmedGrams) < 10) {
        return;
    }

    // Convert to target unit
    let val;
    let hintExtra = '';
    if (unit === 'g') {
        if (scaleAlreadyMl) {
            const density = _scaleDensityForProduct(currentProduct);
            val = Math.round(grams * density);
            if (density !== 1.00) hintExtra = ' ' + t('scale.density_hint', { density });
        } else {
            val = Math.round(grams);
        }
    } else {
        if (scaleAlreadyMl) {
            val = Math.round(grams);
        } else {
            const density = _scaleDensityForProduct(currentProduct);
            val = Math.round(grams / density);
            if (density !== 1.00) hintExtra = ' ' + t('scale.density_hint', { density });
        }
    }

    // Reject if converted value < 10 (density edge case)
    if (val < 10) {
        _cancelScaleStabilityWait();
        return;
    }
    if (!_scaleUserDismissed && !_scaleStabilityTimer && !_scaleAutoConfirmTimer) {
        // Same value, not dismissed, no timer running (e.g. after brief !stable interruption)
        // → restart stability wait so it eventually completes
        _cancelScaleTimersOnly();
        _startScaleStabilityWait(() => {
            const inp = document.getElementById('use-quantity');
            if (inp) inp.value = val;
            _startScaleAutoConfirm(() => {
                _scaleLastConfirmedGrams = grams;
                const form = document.querySelector('#page-use form');
                if (form) form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
            }, 'btn-use-submit');
        });
    }
    // Same value + dismissed → do nothing (user explicitly dismissed this value)
    // Same value + timer running → do nothing (already counting down)
}

/**
 * Auto-fill ruse-quantity input from a stable scale reading (recipe-use modal).
 */
function _scaleAutoFillRecipeUse(msg) {
    if (!msg) return;
    let unit;
    if (_recipeUseConfMode && _recipeUseConfMode._activeUnit === 'sub') {
        unit = (_recipeUseConfMode.packageUnit || '').toLowerCase();
    } else {
        unit = _recipeUseNormalUnit;
    }
    if (unit !== 'g' && unit !== 'ml') return;

    const rawVal = parseFloat(msg.value);
    if (!isFinite(rawVal) || rawVal <= 0) return;
    const srcUnit = (msg.unit || '').toLowerCase();

    let grams;
    let scaleAlreadyMl = false;
    if      (srcUnit === 'g')                       grams = rawVal;
    else if (srcUnit === 'kg')                      grams = rawVal * 1000;
    else if (srcUnit === 'lbs' || srcUnit === 'lb') grams = rawVal * 453.592;
    else if (srcUnit === 'oz')                      grams = rawVal * 28.3495;
    else if (srcUnit === 'ml')  { grams = rawVal; scaleAlreadyMl = true; }
    else                                            grams = rawVal;

    let val;
    let hintExtra = '';
    if (unit === 'g') {
        if (scaleAlreadyMl) {
            const density = _scaleDensityForProduct(currentProduct);
            val = Math.round(grams * density);
            if (density !== 1.00) hintExtra = ' ' + t('scale.density_hint', { density });
        } else {
            val = Math.round(grams);
        }
    } else {
        if (scaleAlreadyMl) {
            val = Math.round(grams);
        } else {
            const density = _scaleDensityForProduct(currentProduct);
            val = Math.round(grams / density);
            if (density !== 1.00) hintExtra = ' ' + t('scale.density_hint', { density });
        }
    }

    // Update live box in modal — show the already-converted value in the target unit
    const livVal   = document.getElementById('ruse-scale-live-val');
    const livLabel = document.getElementById('ruse-scale-live-label');
    const livStatus = document.getElementById('ruse-scale-live-status');
    if (livVal) {
        // val is already converted to target unit (g or ml); show it directly
        if (val >= 10) {
            livVal.textContent = `${val} ${unit}`;
        } else {
            // val not usable yet — show raw reading
            livVal.textContent = `${msg.value} ${msg.unit || 'kg'}`;
        }
    }
    if (livStatus) livStatus.textContent = msg.stable ? t('scale.stable') : '…';

    // Update live hint in modal with the raw scale reading always
    const hint = document.getElementById('ruse-scale-hint');
    if (hint) {
        hint.textContent = `⚖️ Bilancia: ${msg.value} ${msg.unit || 'kg'}${msg.stable ? ' ✓' : ' …'}`;
        if (unit === 'ml' && srcUnit !== 'ml') {
            hint.textContent += ' ' + t('scale.ml_hint');
        }
        hint.style.display = '';
    }

    if (val < 10) {
        _cancelScaleStabilityWait(); // stop bar only; keep sentinel
        if (livLabel) livLabel.textContent = t('scale.weight_too_low');
        return;
    }

    // Reject if weight hasn't changed enough from last confirmed reading.
    // Threshold: 5g — gives enough time to tare after opening the modal.
    if (_scaleLastConfirmedGrams !== null && Math.abs(grams - _scaleLastConfirmedGrams) < 5) {
        return;
    }

    if (val !== _scaleStabilityVal) {
        _scaleStabilityVal = val;
        _scaleUserDismissed = false;
        _cancelScaleTimersOnly();
        if (livLabel) livLabel.textContent = t('scale.weight_detected');
        // Hide confirm bar when new value arrives
        const confirmWrap = document.getElementById('ruse-scale-confirm-wrap');
        if (confirmWrap) confirmWrap.style.display = 'none';
        _startScaleStabilityWait(() => {
            const inp = document.getElementById('ruse-quantity');
            if (inp) inp.value = val;
            if (hint) {
                hint.textContent = `⚖️ Peso bilancia: ${val} ${unit}${hintExtra}`;
                hint.style.display = '';
            }
            if (livLabel) livLabel.textContent = t('scale.auto_confirm', { val, unit });
            if (livVal) livVal.style.color = '#22c55e';
            const confirmWrap2 = document.getElementById('ruse-scale-confirm-wrap');
            if (confirmWrap2) { confirmWrap2.style.display = ''; }
            const confirmBar = document.getElementById('ruse-scale-confirm-bar');
            if (confirmBar) confirmBar.style.width = '100%';
            _startScaleAutoConfirm(() => {
                _scaleLastConfirmedGrams = grams;
                if (livVal) livVal.style.color = '';
                submitRecipeUse(false);
            }, 'btn-ruse-submit');
        });
    } else if (!_scaleUserDismissed && !_scaleStabilityTimer && !_scaleAutoConfirmTimer) {
        _cancelScaleTimersOnly();
        if (livLabel) livLabel.textContent = t('scale.weight_detected');
        _startScaleStabilityWait(() => {
            const inp = document.getElementById('ruse-quantity');
            if (inp) inp.value = val;
            if (livLabel) livLabel.textContent = t('scale.auto_confirm', { val, unit });
            if (livVal) livVal.style.color = '#22c55e';
            const confirmWrap3 = document.getElementById('ruse-scale-confirm-wrap');
            if (confirmWrap3) confirmWrap3.style.display = '';
            const confirmBar2 = document.getElementById('ruse-scale-confirm-bar');
            if (confirmBar2) confirmBar2.style.width = '100%';
            _startScaleAutoConfirm(() => {
                _scaleLastConfirmedGrams = grams;
                if (livVal) livVal.style.color = '';
                submitRecipeUse(false);
            }, 'btn-ruse-submit');
        });
    }
}

/** Cancel auto-confirm countdown on any screen press (touch = dismiss). */
function _cancelScaleAutoConfirmOnTouch() {
    _cancelScaleAutoConfirm(true);
}

/**
 * Cancel timers, animations and button styles — does NOT touch _scaleStabilityVal
 * or _scaleUserDismissed. Use this when weight goes unstable so the sentinel
 * is preserved and the same value can resume counting when stability returns.
 */
function _cancelScaleTimersOnly() {
    if (_scaleAutoConfirmTimer) { clearTimeout(_scaleAutoConfirmTimer); _scaleAutoConfirmTimer = null; }
    if (_scaleAutoConfirmRAF)   { cancelAnimationFrame(_scaleAutoConfirmRAF); _scaleAutoConfirmRAF = null; }
    _cancelScaleStabilityWait();
    const useBtn  = document.getElementById('btn-use-submit');
    const ruseBtn = document.getElementById('btn-ruse-submit');
    if (useBtn)  useBtn.style.background = '';
    if (ruseBtn) ruseBtn.style.background = '';
    // Reset modal confirm bar and live val colour
    const confirmBar = document.getElementById('ruse-scale-confirm-bar');
    const livVal     = document.getElementById('ruse-scale-live-val');
    const confirmWrap = document.getElementById('ruse-scale-confirm-wrap');
    if (confirmBar) { confirmBar.style.width = '100%'; }
    if (confirmWrap) confirmWrap.style.display = 'none';
    if (livVal) livVal.style.color = '';
    const livLabel = document.getElementById('ruse-scale-live-label');
    if (livLabel && livLabel.textContent.startsWith('✅')) {
        livLabel.textContent = t('scale.cancelled_replace');
    }
    document.removeEventListener('pointerdown', _cancelScaleAutoConfirmOnTouch, true);
}

/**
 * Full cancel: stops timers AND updates state flags.
 * @param {boolean} fromTouch  true = user tapped → set userDismissed
 *                             false = programmatic (page nav, closeModal, oninput) → reset sentinel
 */
function _cancelScaleAutoConfirm(fromTouch) {
    _cancelScaleTimersOnly();
    if (fromTouch) {
        _scaleUserDismissed = true;
    } else {
        _scaleStabilityVal = null;
        _scaleLastConfirmedGrams = null;
    }
}

/** Stop the stability wait and reset its progress bar(s). */
function _cancelScaleStabilityWait() {
    if (_scaleStabilityTimer) { clearTimeout(_scaleStabilityTimer); _scaleStabilityTimer = null; }
    if (_scaleStabilityRAF)   { cancelAnimationFrame(_scaleStabilityRAF); _scaleStabilityRAF = null; }
    const bar  = document.getElementById('scale-live-progress-bar');
    const bar2 = document.getElementById('ruse-scale-progress-bar');
    if (bar)  bar.style.width = '0%';
    if (bar2) bar2.style.width = '0%';
}

/**
 * Start a 10-second stability wait with an animated progress bar.
 * Updates both #scale-live-progress-bar (use page) and #ruse-scale-progress-bar (recipe modal).
 * Calls onStable() when weight unchanged for 5 s.
 */
function _startScaleStabilityWait(onStable) {
    _cancelScaleStabilityWait();
    const duration = 5000;
    const start = performance.now();
    const bar  = document.getElementById('scale-live-progress-bar');
    const bar2 = document.getElementById('ruse-scale-progress-bar');

    function tick() {
        const pct = Math.min(100, ((performance.now() - start) / duration) * 100);
        if (bar)  bar.style.width = pct + '%';
        if (bar2) bar2.style.width = pct + '%';
        if (pct < 100) { _scaleStabilityRAF = requestAnimationFrame(tick); }
    }
    _scaleStabilityRAF = requestAnimationFrame(tick);

    _scaleStabilityTimer = setTimeout(() => {
        _scaleStabilityTimer = null;
        if (_scaleStabilityRAF) { cancelAnimationFrame(_scaleStabilityRAF); _scaleStabilityRAF = null; }
        if (bar)  bar.style.width = '0%';
        if (bar2) bar2.style.width = '0%';
        onStable();
    }, duration);
}
function _startScaleAutoConfirm(onConfirm, btnId) {
    if (_scaleAutoConfirmTimer) { clearTimeout(_scaleAutoConfirmTimer); _scaleAutoConfirmTimer = null; }
    if (_scaleAutoConfirmRAF)   { cancelAnimationFrame(_scaleAutoConfirmRAF); _scaleAutoConfirmRAF = null; }
    const btn = btnId ? document.getElementById(btnId) : null;
    const baseBg = btn ? getComputedStyle(btn).backgroundColor : '';
    // Also update the modal countdown bar if present
    const ruseCountdownBar = document.getElementById('ruse-scale-confirm-bar');
    const duration = 5000;
    const start = performance.now();

    function tick() {
        const elapsed = performance.now() - start;
        const pct = Math.min(100, (elapsed / duration) * 100);
        // Reverse (countdown): button fill shrinks from right to left
        if (btn) {
            btn.style.background =
                `linear-gradient(to left, rgba(255,255,255,0.35) ${100 - pct}%, rgba(255,255,255,0) ${100 - pct}%), ${baseBg}`;
        }
        // Modal countdown progress bar shrinks
        if (ruseCountdownBar) ruseCountdownBar.style.width = (100 - pct) + '%';
        if (elapsed < duration) { _scaleAutoConfirmRAF = requestAnimationFrame(tick); }
    }
    _scaleAutoConfirmRAF = requestAnimationFrame(tick);

    _scaleAutoConfirmTimer = setTimeout(() => {
        _scaleAutoConfirmTimer = null;
        if (btn) btn.style.background = '';
        if (ruseCountdownBar) ruseCountdownBar.style.width = '0%';
        document.removeEventListener('pointerdown', _cancelScaleAutoConfirmOnTouch, true);
        onConfirm();
    }, duration);

    document.addEventListener('pointerdown', _cancelScaleAutoConfirmOnTouch, true);
}

/**
 * Update the scale status indicator icon/class.
 */
function _scaleUpdateStatus(state) {
    const el = document.getElementById('scale-status-indicator');
    if (!el) return;
    el.className = `header-btn scale-status-indicator scale-status-${state}`;
    const labels = {
        connected:    `⚖️ ${t('scale.status_connected')}${_scaleDevice ? ': ' + _scaleDevice : ''}`,
        searching:    `⚖️ ${t('scale.status_searching')}`,
        disconnected: `⚖️ ${t('scale.status_disconnected')}`,
        error:        `⚖️ ${t('scale.status_error')}`,
    };
    el.title = labels[state] || '';
    // Update settings live-diagnostic panel
    const diag = document.getElementById('scale-live-diag');
    if (!diag) return;
    diag.style.display = state === 'connected' ? '' : 'none';
    if (state === 'connected') {
        const devEl = document.getElementById('scale-diag-device');
        const batEl = document.getElementById('scale-diag-battery');
        if (devEl) devEl.textContent = _scaleDevice || t('settings.scale.unknown_device');
        if (batEl) batEl.textContent = _scaleBattery != null ? `🔋 ${_scaleBattery}%` : '';
        const weightEl = document.getElementById('scale-diag-weight');
        if (weightEl && _scaleLatestWeight) {
            weightEl.textContent = `${parseFloat(_scaleLatestWeight.value).toFixed(1)} ${_scaleLatestWeight.unit || 'g'}`;
        }
    }
}

/**
 * Show a brief toast with the current scale connection status when the icon is tapped.
 */
function _scaleShowInfo() {
    const state = _scaleConnected ? 'connected' : 'disconnected';
    const msgs = {
        connected:    `⚖️ ${t('scale.status_connected')}${_scaleDevice ? ': ' + _scaleDevice : ''}${_scaleBattery != null ? ' 🔋' + _scaleBattery + '%' : ''}`,
        searching:    `⚖️ ${t('scale.status_searching')}`,
        disconnected: `⚖️ ${t('scale.status_disconnected')}`,
        error:        `⚖️ ${t('scale.status_error')}`,
    };
    const el = document.getElementById('scale-status-indicator');
    const cls = el ? [...el.classList].find(c => c.startsWith('scale-status-') && c !== 'scale-status-indicator') : null;
    const key = cls ? cls.replace('scale-status-', '') : state;
    showToast(msgs[key] || msgs[state], key === 'connected' ? 'success' : 'info');
}

/**
 * Show the scale reading modal and wait for a stable weight, then populate the input.
 * @param {string} targetInputId  — ID of the <input> to fill
 * @param {Function} getUnit      — function that returns the current unit string ('g', 'ml', 'kg')
 */
function readScaleWeight(targetInputId, getUnit) {
    if (!_scaleConnected) {
        showToast('⚖️ ' + t('scale.not_connected'), 'error');
        return;
    }
    const unit = typeof getUnit === 'function' ? getUnit() : getUnit;
    _scaleShowReadingModal(targetInputId, unit);
    _scaleWeightCallback = (msg) => {
        let val = parseFloat(msg.value);
        const srcUnit = (msg.unit || 'kg').toLowerCase();
        // Convert to target unit
        if (srcUnit === 'kg' && unit === 'g')   val = Math.round(val * 1000);
        if (srcUnit === 'g'  && unit === 'kg')  val = +(val / 1000).toFixed(3);
        if (srcUnit === 'lbs'|| srcUnit === 'lb') {
            val = val * 453.592;
            if (unit === 'kg') val = +(val / 1000).toFixed(2); else val = Math.round(val);
        }
        if (srcUnit === 'kg' && unit === 'ml')  val = Math.round(val * 1000); // approximate (water density)
        const inp = document.getElementById(targetInputId);
        if (inp) { inp.value = val; inp.dispatchEvent(new Event('input')); }
        closeModal();
        showToast(`⚖️ ${val} ${unit}`, 'success');
    };
    // Weight data streams continuously via SSE; _scaleWeightCallback fires on the next stable reading
}

/**
 * Inline scale reading for the edit-inventory modal.
 * Shows a live weight display inside the form and fills edit-qty on stable reading.
 */
function readScaleForEdit() {
    if (!_scaleConnected) { showToast('⚖️ ' + t('scale.not_connected'), 'error'); return; }
    const section = document.getElementById('edit-scale-section');
    const btn = document.getElementById('btn-scale-edit');
    if (section) section.style.display = '';
    if (btn) btn.style.display = 'none';

    _scaleWeightCallback = (msg) => {
        const editQty = document.getElementById('edit-qty');
        const editUnit = document.getElementById('edit-unit');
        if (!editQty || !editUnit) return;

        let unit = editUnit.value;
        const isConf = unit === 'conf';
        let confSize = 0;
        if (isConf) confSize = parseFloat(document.getElementById('edit-conf-size')?.value) || 0;

        let raw = parseFloat(msg.value);
        const srcUnit = (msg.unit || 'kg').toLowerCase();
        let grams;
        if      (srcUnit === 'kg')                      grams = raw * 1000;
        else if (srcUnit === 'lbs' || srcUnit === 'lb') grams = raw * 453.592;
        else if (srcUnit === 'oz')                      grams = raw * 28.3495;
        else                                            grams = raw; // g or ml

        let val;
        if (isConf && confSize > 0) {
            val = Math.round((grams / confSize) * 100) / 100;
        } else {
            val = Math.round(grams);
        }

        editQty.value = val;
        editQty.dispatchEvent(new Event('input'));
        if (section) section.style.display = 'none';
        if (btn) btn.style.display = '';
        showToast(`⚖️ ${val} ${unit}`, 'success');
    };
}

function _scaleShowReadingModal(targetInputId, unit) {
    document.getElementById('modal-content').innerHTML = `
        <div class="modal-header">
            <h3>⚖️ ${t('scale.reading_title')}</h3>
            <button class="modal-close" onclick="closeModal(); _scaleWeightCallback = null;">✕</button>
        </div>
        <div style="padding:16px;text-align:center">
            <p style="margin-bottom:16px">${t('scale.place_on_scale')}</p>
            <div id="scale-reading-live" class="scale-reading-live">— — —</div>
            <p style="margin-top:8px;font-weight:700;color:var(--primary)">${escapeHtml((t('qty.enter_in') || 'Inserimento in') + ' ' + getUnitDisplayLabel(unit))}</p>
            <p class="settings-hint" style="margin-top:12px">${t('scale.waiting_stable')}</p>
        </div>
    `;
    document.getElementById('modal-overlay').style.display = 'flex';
}

/**
 * Show/hide "⚖️ Leggi dalla bilancia" buttons based on current settings and unit.
 * Called after unit change or when navigating to the add/use form.
 */
function updateScaleReadButtons() {
    const s = getSettings();
    const ready = s.scale_enabled && s.scale_gateway_url;

    const btnAdd = document.getElementById('btn-scale-add');
    if (btnAdd) {
        const addUnit = document.getElementById('add-unit')?.value;
        btnAdd.style.display = (ready && (addUnit === 'g' || addUnit === 'ml')) ? '' : 'none';
    }
    const btnUse = document.getElementById('btn-scale-use');
    if (btnUse) {
        const canUseByWeight = _useNormalUnit === 'g' || _useNormalUnit === 'ml' ||
                               (_useConfMode && (_useConfMode.packageUnit === 'g' || _useConfMode.packageUnit === 'ml'));
        btnUse.style.display = (ready && canUseByWeight) ? '' : 'none';
    }
    // Live box: visible when scale enabled + connected + on use page + compatible unit
    const liveBox = document.getElementById('scale-live-box');
    if (liveBox) {
        const isWeightUnit = (_useNormalUnit === 'g' || _useNormalUnit === 'ml') ||
                             (_useConfMode && (_useConfMode.packageUnit === 'g' || _useConfMode.packageUnit === 'ml'));
        liveBox.style.display = (ready && _scaleConnected && _currentPageId === 'use' && isWeightUnit) ? '' : 'none';
    }
}

function onScaleEnabledChange() {
    const s = getSettings();
    const el = document.getElementById('setting-scale-enabled');
    s.scale_enabled = el ? el.checked : false;
    saveSettingsToStorage(s);
    scaleInit();
    updateScaleReadButtons();
}

function testScaleConnection() {
    const urlEl = document.getElementById('setting-scale-url');
    const statusEl = document.getElementById('scale-test-status');
    if (!urlEl || !statusEl) return;
    const url = urlEl.value.trim();
    if (!url) { showToast(t('scale.no_url'), 'error'); return; }

    statusEl.textContent = t('scale.testing');
    statusEl.className = 'settings-status';
    statusEl.style.display = 'block';

    const ac = new AbortController();
    const timeout = setTimeout(() => {
        ac.abort();
        statusEl.textContent = '❌ ' + t('scale.timeout');
        statusEl.className = 'settings-status error';
    }, 8000);
    fetch('api/scale_ping.php?url=' + encodeURIComponent(url) + _scaleAuthQuery(), { signal: ac.signal })
        .then(r => r.json())
        .then(data => {
            clearTimeout(timeout);
            if (data.ok) {
                statusEl.textContent = '✅ ' + t('scale.connected_ok');
                statusEl.className = 'settings-status success';
            } else {
                statusEl.textContent = '❌ ' + (data.error || t('scale.error_connect'));
                statusEl.className = 'settings-status error';
            }
        })
        .catch(e => {
            clearTimeout(timeout);
            if (e.name !== 'AbortError') {
                statusEl.textContent = '❌ ' + t('scale.error_connect');
                statusEl.className = 'settings-status error';
            }
        });
}

async function discoverScaleGateway() {
    const btn    = document.getElementById('btn-scale-discover');
    const status = document.getElementById('scale-discover-status');
    if (!btn || !status) return;

    btn.disabled = true;
    btn.textContent = '⏳';
    status.style.display = 'block';
    status.textContent = t('settings.scale.discover_scanning');

    try {
        const res  = await fetch('api/scale_discover.php', { signal: AbortSignal.timeout(8000), headers: { ...(typeof apiAuthHeaders === 'function' ? apiAuthHeaders() : {}) } });
        const data = await res.json();

        if (data.error) {
            status.textContent = '❌ ' + data.error;
        } else if (data.found && data.found.length > 0) {
            const url = data.found[0];
            const urlEl = document.getElementById('setting-scale-url');
            if (urlEl) urlEl.value = url;
            status.textContent = t('settings.scale.discover_found', {
                url,
                more: data.found.length > 1 ? ' (+' + (data.found.length - 1) + ' more)' : '',
            });
            status.style.color = 'var(--color-success, #059669)';
            // Auto-save
            const s = getSettings();
            s.scale_gateway_url = url;
            saveSettingsToStorage(s);
            scaleInit();
        } else {
            status.textContent = t('settings.scale.discover_not_found', { subnet: data.subnet || 'local network' });
        }
    } catch(e) {
        status.textContent = t('settings.scale.discover_failed', { error: e.message || 'timeout' });
    }

    btn.disabled = false;
    btn.textContent = t('settings.scale.discover_auto');
}

// ===== i18n TRANSLATION SYSTEM =====
const _I18N_VERSION = '20260606n'; // bump when translations change
let _i18nStrings = null;   // current language translations (flat)
let _i18nFallback = null;  // Italian fallback (flat)
let _i18nLoadedVersion = null;
let _currentLang = localStorage.getItem('evershelf_lang') || navigator.language?.slice(0, 2) || 'en';
const _SUPPORTED_LANGS = { it: 'Italiano', en: 'English', de: 'Deutsch', fr: 'Français', es: 'Español' };
if (!_SUPPORTED_LANGS[_currentLang]) _currentLang = 'en';

// Apply theme IMMEDIATELY to prevent flash of unstyled content
(function _earlyTheme() {
    try {
        // Use dedicated key (server-synced); fall back to old full-settings object for back-compat
        let mode = localStorage.getItem('evershelf_dark_mode');
        if (!mode) {
            const s = JSON.parse(localStorage.getItem('evershelf_settings') || '{}');
            mode = s.dark_mode || 'auto';
        }
        const h = new Date().getHours();
        const dark = mode === 'on' || (mode === 'auto' && (h >= 20 || h < 7));
        document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
    } catch(e) {}
})();

// Flatten nested JSON: { a: { b: "x" } } → { "a.b": "x" }
function _flattenI18n(obj, prefix = '') {
    const result = {};
    for (const [k, v] of Object.entries(obj)) {
        const key = prefix ? `${prefix}.${k}` : k;
        if (v && typeof v === 'object' && !Array.isArray(v)) {
            Object.assign(result, _flattenI18n(v, key));
        } else {
            result[key] = v;
        }
    }
    return result;
}

// Translation function: t('toast.thrown_away', {name: 'Latte'})
function t(key, params) {
    let str = (_i18nStrings && _i18nStrings[key]) || (_i18nFallback && _i18nFallback[key]) || key;
    if (params) {
        for (const [k, v] of Object.entries(params)) {
            str = str.replace(new RegExp(`\\{${k}\\}`, 'g'), v);
        }
    }
    return str;
}

// Load translations from JSON files
async function loadTranslations(lang) {
    lang = lang || _currentLang;
    try {
        const bust = _I18N_VERSION;
        // Reload fallback when version changes (avoids stale cached keys)
        if (!_i18nFallback || _i18nLoadedVersion !== bust) {
            const fbRes = await fetch(`translations/it.json?v=${bust}`);
            if (fbRes.ok) _i18nFallback = _flattenI18n(await fbRes.json());
            _i18nLoadedVersion = bust;
        }
        if (lang === 'it') {
            _i18nStrings = _i18nFallback;
        } else {
            const res = await fetch(`translations/${encodeURIComponent(lang)}.json?v=${bust}`);
            if (res.ok) _i18nStrings = _flattenI18n(await res.json());
            else _i18nStrings = _i18nFallback;
        }
        _currentLang = lang;
        localStorage.setItem('evershelf_lang', lang);
        _applyI18nToLabels();
        translatePage();
    } catch (e) {
        console.warn('i18n: Failed to load translations for', lang, e);
        _i18nStrings = _i18nFallback;
    }
}

// Update LOCATIONS / SHOPPING_SECTIONS labels from translations
function _applyI18nToLabels() {
    if (!_i18nStrings) return;
    for (const key of Object.keys(LOCATIONS)) {
        const tKey = `locations.${key}`;
        if (_i18nStrings[tKey]) LOCATIONS[key].label = _i18nStrings[tKey];
    }
    for (const key of Object.keys(CATEGORY_LABELS)) {
        const tKey = `categories.${key}`;
        const translated = _i18nStrings[tKey];
        if (translated) {
            const icon = CATEGORY_ICONS[key] || '📦';
            CATEGORY_LABELS[key] = `${icon} ${translated}`;
        }
    }
    const pfCat = document.getElementById('pf-category');
    if (pfCat) {
        const curVal = pfCat.value;
        pfCat.innerHTML = `<option value="" data-i18n="categories.select">${t('categories.select')}</option>` +
            Object.entries(CATEGORY_LABELS).map(([k, label]) => `<option value="${k}">${label}</option>`).join('');
        if (curVal) pfCat.value = curVal;
    }
    for (const sec of SHOPPING_SECTIONS) {
        const tKey = `shopping_sections.${sec.key}`;
        if (_i18nStrings[tKey]) sec.label = _i18nStrings[tKey];
    }
}

// Translate all elements with data-i18n attributes
function translatePage() {
    document.querySelectorAll('[data-i18n]').forEach(el => {
        const key = el.getAttribute('data-i18n');
        if (key) el.textContent = t(key);
    });
    document.querySelectorAll('[data-i18n-html]').forEach(el => {
        const key = el.getAttribute('data-i18n-html');
        if (key) el.innerHTML = t(key);
    });
    document.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
        const key = el.getAttribute('data-i18n-placeholder');
        if (key) el.placeholder = t(key);
    });
    document.querySelectorAll('[data-i18n-title]').forEach(el => {
        const key = el.getAttribute('data-i18n-title');
        if (key) el.title = t(key);
    });
    // Update HTML lang attribute
    document.documentElement.lang = _currentLang;
    // Populate language selector if present
    _populateLanguageSelector();
}

// Populate the language selector dropdown
function _populateLanguageSelector() {
    const sel = document.getElementById('setting-language');
    if (!sel) return;
    sel.innerHTML = '';
    for (const [code, name] of Object.entries(_SUPPORTED_LANGS)) {
        const opt = document.createElement('option');
        opt.value = code;
        opt.textContent = name;
        if (code === _currentLang) opt.selected = true;
        sel.appendChild(opt);
    }
}

// Change language and reload the page
function changeLanguage(lang) {
    if (lang === _currentLang) return;
    localStorage.setItem('evershelf_lang', lang);
    location.reload();
}

// ===== DARK MODE =====
function _applyTheme() {
    const s = getSettings();
    const mode = s.dark_mode || 'auto';
    let isDark;
    if (mode === 'on') {
        isDark = true;
    } else if (mode === 'off') {
        isDark = false;
    } else {
        // auto: dark from 20:00 to 07:00 (time-based, not system preference)
        const h = new Date().getHours();
        isDark = h >= 20 || h < 7;
    }
    document.documentElement.setAttribute('data-theme', isDark ? 'dark' : 'light');
}

function _setThemeMode(mode) {
    const s = getSettings();
    s.dark_mode = mode;
    saveSettingsToStorage(s);
    _applyTheme();
    // Persist dark_mode to server .env immediately (no need to send the full
    // settings payload — save_settings only updates keys present in the body
    // and keeps all other .env values intact).
    const token = document.getElementById('setting-settings-token')?.value.trim() || (typeof getApiToken === 'function' ? getApiToken() : '');
    const headers = token ? { 'X-API-Token': token } : {};
    api('save_settings', {}, 'POST', { dark_mode: mode }, headers).catch(() => {});
}

// Listen to system theme changes (for 'auto' mode)
// Re-evaluate auto theme every 5 minutes (catches 20:00 dark / 07:00 light transitions)
setInterval(() => {
    if ((getSettings().dark_mode || 'auto') === 'auto') _applyTheme();
}, 5 * 60 * 1000);

// ===== EXPORT INVENTORY =====
function exportInventory(format) {
    const tok = typeof getApiToken === 'function' ? getApiToken() : '';
    const tokParam = tok ? `&api_token=${encodeURIComponent(tok)}` : '';
    const url = `api/index.php?action=export_inventory&format=${encodeURIComponent(format)}&_t=${Date.now()}${tokParam}`;
    if (format === 'csv') {
        // Direct download via <a> trick
        const a = document.createElement('a');
        a.href = url;
        a.download = `evershelf-inventory-${new Date().toISOString().slice(0,10)}.csv`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    } else {
        // Open print-ready HTML in new tab
        window.open(url, '_blank', 'noopener');
    }
}

function _showExportModal() {
    const html = `
        <div class="modal-header">
            <h3>📤 ${t('export.title')}</h3>
            <button class="modal-close" onclick="closeModal()">✕</button>
        </div>
        <div style="padding:16px;display:flex;flex-direction:column;gap:12px">
            <p style="color:var(--text-light);font-size:0.9rem">${t('export.hint')}</p>
            <button class="btn btn-primary full-width" onclick="exportInventory('csv');closeModal()">
                📊 ${t('export.btn_csv')}
            </button>
            <button class="btn btn-outline full-width" onclick="exportInventory('html');closeModal()">
                🖨️ ${t('export.btn_pdf')}
            </button>
        </div>`;
    document.getElementById('modal-content').innerHTML = html;
    document.getElementById('modal-overlay').style.display = 'flex';
}

const LOCATIONS = {
    'dispensa': { icon: '🗄️', label: t('locations.dispensa') },
    'frigo': { icon: '🧊', label: t('locations.frigo') },
    'freezer': { icon: '❄️', label: t('locations.freezer') },
    'altro': { icon: '📦', label: t('locations.altro') },
};
const CATEGORY_ICONS = {
    'latticini': '🥛', 'carne': '🥩', 'pesce': '🐟', 'frutta': '🍎',
    'verdura': '🥬', 'pasta': '🍝', 'pane': '🍞', 'surgelati': '🧊',
    'bevande': '🥤', 'condimenti': '🧂', 'snack': '🍪', 'conserve': '🥫',
    'cereali': '🌾', 'igiene': '🧴', 'pulizia': '🧹', 'altro': '📦'
};

// Auto-detect location based on category and product name
const CATEGORY_LOCATION = {
    'latticini': 'frigo', 'carne': 'frigo', 'pesce': 'frigo',
    'frutta': 'frigo', 'verdura': 'frigo', 'surgelati': 'freezer',
    'pasta': 'dispensa', 'pane': 'dispensa', 'bevande': 'dispensa',
    'condimenti': 'dispensa', 'snack': 'dispensa', 'conserve': 'dispensa',
    'cereali': 'dispensa', 'igiene': 'altro', 'pulizia': 'altro', 'altro': 'dispensa'
};

// Shopping section (reparto) map — groups categories into grocery departments
const SHOPPING_SECTIONS = [
    { key: 'frutta_verdura', icon: '🥬', label: t('shopping_sections.frutta_verdura'), cats: new Set(['frutta','verdura']) },
    { key: 'carne_pesce',    icon: '🥩', label: t('shopping_sections.carne_pesce'),    cats: new Set(['carne','pesce']) },
    { key: 'latticini',      icon: '🥛', label: t('shopping_sections.latticini'),      cats: new Set(['latticini']) },
    { key: 'pane_dolci',     icon: '🍞', label: t('shopping_sections.pane_dolci'),     cats: new Set(['pane','snack','cereali']) },
    { key: 'pasta',          icon: '🍝', label: t('shopping_sections.pasta'),          cats: new Set(['pasta']) },
    { key: 'conserve',       icon: '🥫', label: t('shopping_sections.conserve'),       cats: new Set(['conserve','condimenti']) },
    { key: 'surgelati',      icon: '❄️',  label: t('shopping_sections.surgelati'),      cats: new Set(['surgelati']) },
    { key: 'bevande',        icon: '🥤', label: t('shopping_sections.bevande'),        cats: new Set(['bevande']) },
    { key: 'pulizia_igiene', icon: '🧴', label: t('shopping_sections.pulizia_igiene'), cats: new Set(['igiene','pulizia']) },
    { key: 'altro',          icon: '📦', label: t('shopping_sections.altro'),          cats: new Set(['altro']) },
];

function getItemSection(name) {
    const cat = guessCategoryFromName(name) || 'altro';
    for (const s of SHOPPING_SECTIONS) { if (s.cats.has(cat)) return s; }
    return SHOPPING_SECTIONS[SHOPPING_SECTIONS.length - 1];
}

const URGENCY_WEIGHT = { critical: 4, high: 3, medium: 2, low: 1 };
const URGENCY_BG = {
    critical: 'rgba(194,65,12,0.14)',
    high:     'rgba(234,88,12,0.09)',
    medium:   'rgba(245,158,11,0.07)',
    low:      'rgba(34,197,94,0.05)',
};

// Map Open Food Facts categories to local categories
function mapToLocalCategory(ofCategory, productName, productBrand = '') {
    if (!ofCategory) {
        return guessCategoryFromName(productName || '', productBrand || '');
    }
    const cat = ofCategory.toLowerCase();
    // Direct match with our local keys — but NOT 'altro': fall through to name guess
    for (const key of Object.keys(CATEGORY_ICONS)) {
        if (cat === key && key !== 'altro') return key;
    }

    // Handle specific Open Food Facts tags FIRST (before generic regex)
    // "plant-based-foods-and-beverages" is a catch-all — use product name to decide
    if (/plant-based-foods/.test(cat)) {
        return guessCategoryFromName(productName || '', productBrand || '');
    }
    // "beverages-and-beverages-preparations" = actual beverages
    if (/^en:beverages/.test(cat)) return 'bevande';
    // sweeteners = condimenti
    if (/sweetener|dolcific/.test(cat)) return 'condimenti';
    // food-additives, cooking-helpers, flavourings = condimenti
    if (/food-additive|cooking-helper|aromi|flavour/.test(cat)) return 'condimenti';
    // breakfasts = cereali
    if (/breakfast/.test(cat)) return 'cereali';
    // dried-products = conserve
    if (/dried-product/.test(cat)) return 'conserve';

    // Specific tag patterns
    if (/dairi|dairy|lait|cheese|fromage|yoghurt|milk|latticin|latte\b|egg\b|uova\b|uovo\b|poultry-egg/.test(cat)) return 'latticini';
    if (/meat|viande|carne|sausage|salum|prosciutt/.test(cat)) return 'carne';
    if (/fish|poisson|pesce|seafood|tuna|tonno|salmone/.test(cat)) return 'pesce';
    if (/fruit|frutta|juice|succo|apple|banana/.test(cat)) return 'frutta';
    if (/salad|insalat/.test(cat)) {
        return _isPreparedSaladName(productName, productBrand) ? 'pasta' : 'verdura';
    }
    if (/vegetable|verdur|legum|tomato|pomodor/.test(cat)) return 'verdura';
    if (/pasta|rice|riso|noodle|spaghetti|penne|grain/.test(cat)) return 'pasta';
    if (/bread|pane|forno|biscott|toast|cracker|grissini|fette/.test(cat)) return 'pane';
    if (/frozen|surgelé|surgel|gelat/.test(cat)) return 'surgelati';
    if (/sauce|condiment|oil|olio|vinegar|aceto|mayo|ketchup|spice|salt|sugar|zuccher/.test(cat)) return 'condimenti';
    if (/snack|chip|crisp|chocolate|cioccolat|candy|biscuit|cookie|wafer|merendine|patatine/.test(cat)) return 'snack';
    if (/preserve|jam|marmellat|miele|honey|canned|pelati|passata/.test(cat)) return 'conserve';
    if (/cereal|muesli|granola|oat|fiocchi/.test(cat)) return 'cereali';
    if (/hygiene|soap|shampoo|igien|dentifricio|deodorant/.test(cat)) return 'igiene';
    if (/clean|detergent|pulizia|detersiv/.test(cat)) return 'pulizia';
    // Beverage check LAST (to avoid false matches on compound tags)
    if (/^(?!.*plant-based).*(beverage|drink|boisson|bevand|water|acqua|beer|birra|wine|vino|coffee|caffè|tea\b)/.test(cat)) return 'bevande';
    // Last resort: try product name before giving up
    const nameGuess = guessCategoryFromName(productName || '', productBrand || '');
    if (nameGuess !== 'altro') return nameGuess;
    return 'altro';
}

/** Prepared rice/pasta salads — not fresh leafy salad (verdura). */
function _isPreparedSaladName(name, brand = '') {
    const n = (name || '').toLowerCase();
    const b = (brand || '').toLowerCase();
    if (/insalata\s+di\s+(riso|pasta|farro|orzo|couscous|quinoa|bulgur|cereali|legumi)\b/.test(n)) return true;
    if (/\b(riso|pasta)\s+con\b/.test(n) && /\binsalata\b/.test(n)) return true;
    if (/\binsalata\b/.test(n) && /\b(ponti|rio mare|orogel|findus|star)\b/.test(b)) return true;
    return false;
}

// Guess a local category purely from product name
function guessCategoryFromName(name, brand = '') {
    if (!name) return 'altro';
    const n = name.toLowerCase();
    if (_isPreparedSaladName(n, brand)) return 'pasta';
    // ── Known Italian brand names → direct category (fast-path before regex)
    // "Uno" only if it starts the name (Bahlsen biscuits, not the Italian word)
    if (/^uno\b/.test(n)) return 'snack';
    const _brandRx = [
        [/\b(baiocchi|macine|tarallucci|tegolini|pavesini|plasmon|loacker|manner|digestive|oreo|hanuta|ringo|abbracci|gocciole|pan di stelle|oro saiwa|kinder|ferrero rocher|raffaello|bounty|twix|snickers|pringles|fonzies|tuc\b|ritz\b|mulino bianco|gran cereale|gocciole|saiwa|togo|principe|oro ciok|kit ?kat)\b/, 'snack'],
        [/\b(barilla|de cecco|garofalo|la molisana|rummo|voiello|divella|agnesi|buitoni)\b/, 'pasta'],
        [/\b(galbani|granarolo|yomo|danone|muller|müller|pr[eé]sident|santa lucia|jocca|fiorfiore)\b/, 'latticini'],
        [/\b(mutti|cirio)\b/, 'conserve'],
        [/\b(san pellegrino|levissima|ferrarelle|lete|nestea|lipton|nescaf[eé]|lavazza|illy\b|kimbo|segafredo)\b/, 'bevande'],
    ];
    for (const [rx, cat] of _brandRx) { if (rx.test(n)) return cat; }
    // Pasta & Rice
    if (/spaghetti|penne|fusilli|rigatoni|linguine|orecchiette|farfalle|pasta\b|riso\b|basmati|carnaroli|arborio|gnocchi|lasagne|tagliatelle|maccheroni|bucatini|pennette|sedani|tortiglioni|calamarata|spaghettini|vermicelli/.test(n)) return 'pasta';
    // Pane & Forno
    if (/pane\b|bauletto|fette biscottate|grissini|cracker|toast|piadina|piadelle|focaccia|panini\b|sandwich|taralli|pancarr[eè]|baguette|ciabatta|rosetta|tramezzino|tortilla|pita\b|pangrattato|pane grattugiato|pan.*carr[eè]/.test(n)) return 'pane';
    // Latticini (before bevande to avoid latte→bevande)
    if (/latte\b|yogurt|y[o]?gurt|yaourt|yougurt|yoghurt|formaggio|mozzarella|burro\b|panna\b|ricott|mascarpone|gorgonzola|parmigiano|grana\b|uova\b|uovo\b|egg\b|burrata|scamorza|provolone|pecorino|fontina|taleggio|stracchino|crescenza|brie\b|camembert|emmental|asiago|feta\b|provola|caciotta|caprino|philadelphia|skyr|kefir|labneh/.test(n)) return 'latticini';
    // Conserve — controllo tonno\b PRIMA di condimenti (che ha olio\b)
    if (/passata|pelati|pomodoro\b|pomodori|pomodorini|ciliegino|sugo\b|polpa di pomod|marmellata|miele\b|zagara|legumi|ceci\b|fagioli\b|lenticchie|olive\b|tonno\b|sgombro in scatola|concentrato|brodo\b|dado\b|besciamella|datterini|passato di/.test(n)) return 'conserve';
    // Condimenti (include spezie, farine, zucchero, aromi, lieviti)
    if (/olio\b|aceto|sale\b|pepe\b|zucchero|zuccher|farina\b|maionese|ketchup|senape|salsa\b|paprika|curry\b|cannella|noce moscata|origano|rosmarino|timo\b|basilico|prezzemolo|curcuma|cumino|cardamomo|vaniglia|lievito|bicarbonato|amido\b|maizena|semola|pesto\b|tahini|miso\b|colatura|soia.*salsa|worcester|tabasco|aroma\b|aromi\b|arome\b|estratto.*vaniglia|estratto.*limone|polenta\b|semolino\b|cacao amaro|cacao.*polvere|purea|pure\b|pur[ée]e/.test(n)) return 'condimenti';
    // Bevande (after latticini to avoid latte conflict)
    if (/acqua\b|birra\b|vino\b|succo|spremuta|coca.cola|aranciata|caff[eè]\b|kaffee|kafè|t[eè]\b|tea\b|tisana|camomilla|infuso|energy drink|bevanda|limonata|aranciate|sprite|pepsi|fanta|san pellegrino|ciobar|ovomaltine|zuppalatte|cioccolata.*calda|latte.*cioccolato/.test(n)) return 'bevande';
    // Carne (include salumi)
    if (/pollo\b|manzo|maiale|vitello|tacchino|prosciutto|salame\b|bresaola|mortadella|wurstel|speck\b|pancetta|nduja|guanciale|cotechino|salsiccia|agnello|cinghiale|polpette|arrosto|bistecca|cotoletta|lonza|braciola|schinken|scamorza affumicat|spianata/.test(n)) return 'carne';
    // Pesce
    if (/tonno\b|salmone|merluzzo|pesce\b|sgombro\b|gamberi|acciughe|baccal[aà]|vongole|cozze|calamari|surimi|alici|branzino|orata\b|sardine|trota|dentice|seppia|polpo|filetto.*pesce|pesce.*filetto/.test(n)) return 'pesce';
    // Frutta
    if (/mela\b|mele\b|banana|arancia|pera\b|fragola|uva\b|kiwi\b|limone|frutta\b|mandarino|clementina|pompelmo|avocado|mango\b|ananas|melone|anguria|susina|prugna|ciliegia|albicocca|pesca\b|nettarina|fico\b|melograno|papaya|maracuja|cocco\b|dattero|lampone|mirtillo|ribes|more\b/.test(n)) return 'frutta';
    // Verdura
    if (/insalata|zucchina|zucchine|pomodor|cipolla|carota|spinaci|rucola|peperoni|melanzane|broccoli|patata|finocchio|sedano|porro|scalogno|cavolo|cavolfiore|asparagi|funghi|courgette|lattuga|bietola|radicchio|carciofo|fagiolini|piselli|mais\b|zucca\b|aglio\b|cetriolo|rapa\b|barbabietola|cime di rapa|pak choi|bok choy|verza|cavolo nero/.test(n)) return 'verdura';
    // Surgelati
    if (/surgelat|frozen|findus|4.salti|gelato|minestrone surgelato|potato wedge|potato.*wedge/.test(n)) return 'surgelati';
    // Snack & Dolci
    if (/biscott|cioccolat|nutella|merendine\b|merendina|patatine|caramelle|wafer|cialda|cialdine|sfornatini|torta\b|pandoro|panettone|colomba|cornetto|brioche|croissant|dolc|dessert|tiramis[uù]|cantucci|amaretti|savoiardi|pralin|confetti dolci|chicchi.*cacao|cacao.*chicchi|risofrolle|sfogliatine|ossi di morto|canestrelli|snack/.test(n)) return 'snack';
    // Cereali
    if (/cereali|muesli|fiocchi|granola|porridge|avena|mix energia|misto cereal|farro\b|orzo\b|quinoa/.test(n)) return 'cereali';
    // Igiene personale
    if (/sapone|shampoo|dentifricio|deodorante|carta igienica|fazzoletti|cotton fioc|assorbente|rasoio|schiuma da barba|gel doccia|balsamo\b|lozione/.test(n)) return 'igiene';
    // Pulizia casa
    if (/detersivo|pulito|sgrassatore|candeggina|ammorbidente|anticalcare|bucato|piatti\b|lavatrice|lavastoviglie|detergente/.test(n)) return 'pulizia';
    return 'altro';
}

// ─────────────────────────────────────────────────────────────────────────────
// Embedding-based category classifier (async, @xenova/transformers)
// ─────────────────────────────────────────────────────────────────────────────

// Canonical descriptions for each local category (used as embedding anchors).
const _CATEGORY_DESCRIPTIONS = {
    latticini:  'latte yogurt formaggio burro panna mozzarella latticini dairy',
    carne:      'carne pollo manzo maiale vitello prosciutto salame bresaola meat',
    pesce:      'pesce tonno salmone merluzzo gamberi seafood fish',
    frutta:     'frutta mela banana arancia pera fragola uva kiwi fruit',
    verdura:    'verdura insalata zucchina carota cipolla spinaci tomato vegetables',
    pasta:      'pasta spaghetti penne fusilli riso risotto noodles rice',
    pane:       'pane fette biscottate grissini cracker toast bread bakery',
    surgelati:  'surgelati congelato frozen gelato ice cream',
    bevande:    'acqua birra vino succo caffè tè bevande drinks beverages',
    condimenti: 'olio aceto sale zucchero farina ketchup maionese senape spezie condiments',
    snack:      'biscotti cioccolato patatine snack caramelle wafer merendine',
    conserve:   'conserve pelati passata marmellata miele legumi ceci beans canned',
    cereali:    'cereali muesli granola fiocchi d\'avena oat breakfast cereal',
    igiene:     'sapone shampoo dentifricio deodorante igiene personale hygiene',
    pulizia:    'detersivo detergente pulizia casa sgrassatore cleaning',
    altro:      'prodotto generico varie altro miscellaneous',
};

// In-memory cache: productName → category (avoids re-embedding the same product)
const _embeddingCache = new Map();

/**
 * Cosine similarity between two Float32Array vectors.
 */
function _cosineSim(a, b) {
    let dot = 0, na = 0, nb = 0;
    for (let i = 0; i < a.length; i++) {
        dot += a[i] * b[i];
        na  += a[i] * a[i];
        nb  += b[i] * b[i];
    }
    return dot / (Math.sqrt(na) * Math.sqrt(nb) + 1e-9);
}

/**
 * Mean-pool a [1, tokens, dims] tensor → Float32Array of length dims.
 */
function _meanPool(tensor) {
    const [, tokens, dims] = tensor.dims;
    const data = tensor.data;
    const out  = new Float32Array(dims);
    for (let t = 0; t < tokens; t++) {
        for (let d = 0; d < dims; d++) {
            out[d] += data[t * dims + d];
        }
    }
    for (let d = 0; d < dims; d++) out[d] /= tokens;
    return out;
}

/**
 * Async: returns the best-matching category key for `productName`.
 * Returns null if the model is unavailable or similarity is too low.
 * THRESHOLD 0.30 — below this the regex fallback is more reliable.
 */
async function classifyCategoryByEmbedding(productName) {
    if (!productName) return null;
    const key = productName.toLowerCase().trim();
    if (_embeddingCache.has(key)) return _embeddingCache.get(key);

    if (typeof window._getCategoryPipeline !== 'function') return null;
    const pipe = await window._getCategoryPipeline();
    if (!pipe) return null;

    try {
        const labels = Object.keys(_CATEGORY_DESCRIPTIONS);
        const texts  = [key, ...labels.map(l => _CATEGORY_DESCRIPTIONS[l])];

        // Embed all texts in one batched call for efficiency
        const output  = await pipe(texts, { pooling: 'mean', normalize: true });
        const vectors = labels.map((_, i) => {
            const t = output[i + 1];
            // output[i] may be a Tensor or already a plain array-like
            return t.dims ? _meanPool(t) : new Float32Array(t.data ?? t);
        });
        const queryVec = output[0].dims
            ? _meanPool(output[0])
            : new Float32Array(output[0].data ?? output[0]);

        let bestLabel = null, bestSim = 0;
        for (let i = 0; i < labels.length; i++) {
            const sim = _cosineSim(queryVec, vectors[i]);
            if (sim > bestSim) { bestSim = sim; bestLabel = labels[i]; }
        }

        const result = (bestSim >= 0.30 && bestLabel !== 'altro') ? bestLabel : null;
        _embeddingCache.set(key, result);
        return result;
    } catch (e) {
        console.warn('[EverShelf] Embedding classify error:', e);
        return null;
    }
}

// Determine safety level for expired products
// Returns { level: 'danger'|'warning'|'ok', icon, label, tip }
function getExpiredSafety(item, daysExpired) {
    const cat = mapToLocalCategory(item.category || '', item.name || '');
    const loc = (item.location || '').toLowerCase();
    const inFreezer = loc === 'freezer';
    const inFrigo = loc === 'frigo';

    // === FREEZER: il congelamento allunga molto la vita ===
    // Carne/pesce in freezer: +3 mesi. Verdura/frutta: +6 mesi. Pane: +2 mesi.
    // Latticini in freezer: +1-2 mesi. Tutto il resto: +3-6 mesi.
    if (inFreezer) {
        const highRiskFreezer = ['carne', 'pesce'];
        const medRiskFreezer = ['latticini', 'pane'];
        const produceRiskFreezer = ['verdura', 'frutta'];

        let bonusDays;
        if (highRiskFreezer.includes(cat)) bonusDays = 90;       // +3 mesi
        else if (produceRiskFreezer.includes(cat)) bonusDays = 180; // +6 mesi
        else if (medRiskFreezer.includes(cat)) bonusDays = 60;    // +2 mesi
        else bonusDays = 120;                                      // +4 mesi default

        const effectiveDays = daysExpired - bonusDays;

        if (effectiveDays <= 0) {
            return { level: 'ok', icon: '✅', label: t('status.ok'), tip: t('status.tip_freezer_ok').replace('{n}', bonusDays - daysExpired) };
        }
        if (effectiveDays <= 30) {
            return { level: 'warning', icon: '👀', label: t('status.check'), tip: t('status.tip_freezer_check') };
        }
        return { level: 'danger', icon: '🗑️', label: t('status.discard'), tip: t('status.tip_freezer_danger') };
    }

    // === FRIGO e DISPENSA ===
    const highRisk = ['latticini', 'carne', 'pesce', 'verdura', 'frutta'];
    const medRisk = ['pane', 'surgelati'];

    if (highRisk.includes(cat)) {
        if (inFrigo && daysExpired <= 2) {
            return { level: 'warning', icon: '👀', label: t('status.check'), tip: t('status.tip_highRisk_check') };
        }
        return { level: 'danger', icon: '🗑️', label: t('status.discard'), tip: t('status.tip_highRisk_danger') };
    }

    if (medRisk.includes(cat)) {
        if (daysExpired <= 7) {
            return { level: 'warning', icon: '👀', label: t('status.check'), tip: t('status.tip_medRisk_check1') };
        }
        if (daysExpired <= 30) {
            return { level: 'warning', icon: '👀', label: t('status.check'), tip: t('status.tip_medRisk_check2') };
        }
        return { level: 'danger', icon: '🗑️', label: t('status.discard'), tip: t('status.tip_medRisk_danger') };
    }

    // LOW RISK - lunga conservazione (pasta, conserve, condimenti, cereali, snack)
    if (daysExpired <= 30) {
        return { level: 'ok', icon: '✅', label: t('status.ok'), tip: t('status.tip_lowRisk_ok') };
    }
    if (daysExpired <= 180) {
        return { level: 'warning', icon: '👀', label: t('status.check'), tip: t('status.tip_lowRisk_check') };
    }
    return { level: 'danger', icon: '🗑️', label: t('status.discard'), tip: t('status.tip_lowRisk_danger') };
}

/** True when an expired/opened item deserves a dashboard banner (not merely informational). */
function shouldShowExpiredBanner(item) {
    const days = item.days_expired ?? Math.abs(item.days_to_expiry ?? 0);
    if (getExpiredSafety(item, days).level === 'ok') return false;
    if (item.opened_at && item.is_edible === true) return false;
    return true;
}

// Localized labels for local categories
const CATEGORY_LABELS = {
    'latticini': `🥛 ${t('categories.latticini')}`, 'carne': `🥩 ${t('categories.carne')}`, 'pesce': `🐟 ${t('categories.pesce')}`,
    'frutta': `🍎 ${t('categories.frutta')}`, 'verdura': `🥬 ${t('categories.verdura')}`, 'pasta': `🍝 ${t('categories.pasta')}`,
    'pane': `🍞 ${t('categories.pane')}`, 'surgelati': `🧊 ${t('categories.surgelati')}`, 'bevande': `🥤 ${t('categories.bevande')}`,
    'condimenti': `🧂 ${t('categories.condimenti')}`, 'snack': `🍪 ${t('categories.snack')}`, 'conserve': `🥫 ${t('categories.conserve')}`,
    'cereali': `🌾 ${t('categories.cereali')}`, 'igiene': `🧴 ${t('categories.igiene')}`, 'pulizia': `🧹 ${t('categories.pulizia')}`,
    'altro': `📦 ${t('categories.altro')}`
};

// Detect best unit/quantity from Open Food Facts quantity_info string
// Returns the actual package weight/volume as default (e.g. 700g → unit:'g', quantity:700)
function detectUnitAndQuantity(quantityInfo) {
    if (!quantityInfo) return { unit: 'pz', quantity: 1, weightInfo: '' };
    const q = quantityInfo.toLowerCase().trim();
    // Match multi-pack patterns like "6 x 1l", "4 x 125g" → confezioni
    const multiMatch = q.match(/(\d+)\s*x\s*([\d.,]+)\s*(ml|l|g|kg|cl)/i);
    if (multiMatch) {
        const count = parseInt(multiMatch[1]);
        let perUnitVal = parseFloat(multiMatch[2].replace(',', '.'));
        let perUnitUnit = multiMatch[3].toLowerCase();
        if (perUnitUnit === 'cl') { perUnitUnit = 'ml'; perUnitVal *= 10; }
        if (perUnitUnit === 'kg') { perUnitUnit = 'g'; perUnitVal *= 1000; }
        if (perUnitUnit === 'l') { perUnitUnit = 'ml'; perUnitVal *= 1000; }
        return { unit: 'conf', quantity: perUnitVal, packageUnit: perUnitUnit, confCount: count, weightInfo: quantityInfo };
    }
    // Match single package patterns like "500 g", "1 l", "750 ml", "1.5 kg"
    const match = q.match(/([\d.,]+)\s*(kg|g|l|ml|cl)/i);
    if (match) {
        let unit = match[2].toLowerCase();
        let val = parseFloat(match[1].replace(',', '.'));
        if (unit === 'cl') { unit = 'ml'; val *= 10; }
        if (unit === 'kg') { unit = 'g'; val *= 1000; }
        if (unit === 'l') { unit = 'ml'; val *= 1000; }
        return { unit, quantity: val, weightInfo: quantityInfo };
    }
    return { unit: 'pz', quantity: 1, weightInfo: quantityInfo };
}

// Estimate expiry days based on category/product type
const EXPIRY_DAYS = {
    'latticini': 7, 'carne': 4, 'pesce': 3, 'frutta': 7, 'verdura': 7,
    'pasta': 730, 'pane': 4, 'surgelati': 180, 'bevande': 365, 'condimenti': 365,
    'snack': 180, 'conserve': 730, 'cereali': 365, 'igiene': 1095, 'pulizia': 1095, 'altro': 180
};

// More specific expiry by product name keywords
function estimateExpiryDays(product, location) {
    const name = (product.name || '').toLowerCase();
    const cat = (product.category || '').toLowerCase();
    const loc = (location || '').toLowerCase();
    
    let days;
    
    // Specific product overrides
    if (/latte\s+(fresco|intero|parzial|scremato)/.test(name)) days = 7;
    else if (/latte\s+uht|latte\s+a\s+lunga/.test(name)) days = 90;
    else if (/yogurt/.test(name)) days = 21;
    else if (/mozzarella|burrata|stracciatella/.test(name)) days = 5;
    else if (/formaggio\s+(fresco|ricotta|mascarpone|stracchino|crescenza)/.test(name)) days = 10;
    else if (/parmigiano|grana|pecorino|provolone|asiago|fontina|emmental|gruyere|scamorza|groviera/.test(name)) days = 60;
    else if (/burro/.test(name)) days = 60;
    else if (/panna/.test(name)) days = 14;
    else if (/prosciutto\s+cotto|mortadella|wurstel/.test(name)) days = 7;
    else if (/prosciutto\s+crudo|salame|bresaola|speck/.test(name)) days = 30;
    else if (/nduja/.test(name)) days = 90;
    else if (/uova/.test(name)) days = 28;
    else if (/pane\s+fresco|pane\s+in\s+cassetta/.test(name)) days = 5;
    else if (/pane\s+confezionato|pan\s+carr|pancarrè/.test(name)) days = 14;
    else if (/insalata\s+di\s+(riso|pasta|farro|orzo|couscous)/.test(name)) days = 7;
    else if (/insalata|rucola|spinaci\s+freschi/.test(name)) days = 5;
    else if (/pollo|tacchino|maiale|manzo|vitello|sovracosci|cosci/.test(name)) days = 3;
    else if (/salmone|tonno\s+fresco|pesce/.test(name) && !/tonno\s+in\s+scatola|tonno\s+rio/.test(name)) days = 2;
    else if (/tonno\s+in\s+scatola|tonno\s+rio|sgombro\s+in/.test(name)) days = 1095;
    else if (/surgelat|frozen|findus|4\s*salti/.test(name)) days = 180;
    else if (/gelato/.test(name)) days = 365;
    else if (/succo|spremuta/.test(name)) days = 7;
    else if (/birra|vino/.test(name)) days = 365;
    else if (/acqua/.test(name)) days = 365;
    else if (/mela|mele\b/.test(name)) days = 7;
    else if (/arancia|arance|mandarini|agrumi/.test(name)) days = 7;
    else if (/banana|banane/.test(name)) days = 5;
    else if (/pera|pere\b|fragola|fragole|uva|kiwi/.test(name)) days = 5;
    else if (/carota|carote|zucchina|zucchine|peperoni|melanzane/.test(name)) days = 7;
    else if (/broccoli|cavolfiore|cavolo|spinaci|bietola/.test(name)) days = 5;
    else if (/cipolla|cipolle/.test(name)) days = 10;
    else if (/patata|patate/.test(name)) days = 30; // whole tubers in a bag, pantry: 3-5 weeks
    else if (/biscott|cracker|grissini|fette\s+biscott/.test(name)) days = 180;
    else if (/nutella|marmellata|miele/.test(name)) days = 365;
    else if (/passata|pelati|pomodor/.test(name)) days = 730;
    else if (/olio|aceto/.test(name)) days = 548;
    else {
        // Fallback to category
        days = 180; // generic default
        for (const [key, d] of Object.entries(EXPIRY_DAYS)) {
            if (cat.includes(key)) { days = d; break; }
        }
    }
    
    // Fridge extends shelf life for produce and short-lived items (sealed only)
    if (loc === 'frigo') {
        // Specific fridge-friendly produce overrides
        if (/mela|mele/.test(name)) days = Math.max(days, 28);
        else if (/arancia|arance|agrumi|mandarini|limone|limoni/.test(name)) days = Math.max(days, 21);
        else if (/carota|carote/.test(name)) days = Math.max(days, 21);
        else if (/cipolla/.test(name)) days = Math.max(days, 14);
        else if (/patata|patate/.test(name)) days = Math.max(days, 30);
        else if (/pera|pere/.test(name)) days = Math.max(days, 21);
        else if (/kiwi/.test(name)) days = Math.max(days, 28);
        else if (/uva/.test(name)) days = Math.max(days, 14);
        else if (/fragola|fragole/.test(name)) days = Math.max(days, 7);
        else if (/peperoni/.test(name)) days = Math.max(days, 14);
        else if (/zucchina|zucchine/.test(name)) days = Math.max(days, 14);
        else if (/melanzane/.test(name)) days = Math.max(days, 14);
        else if (/broccoli|cavolfiore|cavolo/.test(name)) days = Math.max(days, 10);
        // General fridge bonus: fruits and vegs that aren't already long
        else if (days <= 7 && (/frutta|fruit/.test(cat) || /verdur|vegetable|plant-based/.test(cat))) {
            days = Math.round(days * 2); // ~double shelf life in fridge
        }
    }
    
    // Freezer extends shelf life significantly
    if (loc === 'freezer' && days < 180) {
        // Fresh meat/fish: 3-6 months in freezer
        if (days <= 4) days = 120;
        // Short-lived (cheese, dairy, bread): 2-3 months
        else if (days <= 14) days = 75;
        // Medium (yogurt, cured meats): 3-4 months
        else if (days <= 30) days = 120;
        // Already long-lasting: at least 6 months
        else days = Math.max(days, 180);
    }
    
    return days;
}

function formatEstimatedExpiry(days) {
    if (days <= 7) return t('expiry.days_approx').replace('{n}', days);
    if (days <= 30) return t('expiry.weeks_approx').replace('{n}', Math.round(days / 7));
    if (days <= 365) return t('expiry.months_approx').replace('{n}', Math.round(days / 30));
    return t('expiry.years_approx').replace('{n}', Math.round(days / 365));
}

/**
 * Estimate shelf life in days for an OPENED product.
 * Much shorter than sealed shelf life — based on typical "once opened, consume within X days".
 */
function estimateOpenedExpiryDays(product, location) {
    const name = (product.name || '').toLowerCase();
    const cat = (product.category || '').toLowerCase();
    const loc = (location || '').toLowerCase();

    // ── A: Non-perishables — check BEFORE location ──────────────────────
    if (/\bsale\b|\bsel\s+mar|\bsalt\b/.test(name) && !/\b(salmone|salame|salsa)\b/.test(name)) return 9999;
    if (/\bzucchero\b|\bsugar\b/.test(name)) return 9999;
    if (/\bmiele\b/.test(name)) return 9999;
    if (/\baceto\b/.test(name)) return 9999;
    if (/\bbicarbonato\b|\blievito\s+chimico\b/.test(name)) return 9999;

    // ── B: Spirits ───────────────────────────────────────────────────────
    if (/\b(sambuca|rum\b|brandy|whiskey|whisky|vodka|gin\b|grappa|amaro|aperol|campari|limoncello|cognac|porto|marsala|baileys|amaretto|vermouth)\b/.test(name)) return 730;

    // ── C: Long-life regardless of location ─────────────────────────────
    if (/\b(aroma|estratto|essenza|vanilli|colorante)\b/.test(name)) return 730;
    if (/\b(t[eè]\b|tea\b|tisana|camomilla|verbena|infuso|rooibos)\b/.test(name)) return 730;
    if (/\b(caff[eè]|coffee|nespresso)\b/.test(name)) return 365;
    if (/\bolio\b/.test(name)) return 365;
    if (/salsa\s+di\s+soia|soy\s*sauce/.test(name)) return 90; // soy sauce fine opened anywhere
    // Dry bread products (grated, breadcrumbs) — not fresh bread
    if (/grattugiat|pangratt|panko|bread\s*crumb|briciole\s+di\s+pane/.test(name)) return 90;
    if (loc !== 'frigo') {
        if (/\b(pasta|spaghetti|penne|rigatoni|fusilli|farfalle|tagliatelle|linguine|bucatini|lasagn|tortiglioni)\b/.test(name)) return 365;
        if (/\b(riso|risotto|orzo|farro|quinoa|couscous)\b/.test(name) && !/\b(pronto|cotto)\b/.test(name)) return 365;
        if (/\b(polenta|semola|maizena|amido|farina)\b/.test(name)) return 180;
    }

    // ── D: Freezer ───────────────────────────────────────────────────────
    if (loc === 'freezer') return 90;

    // ── E: Pantry fallbacks ───────────────────────────────────────────────
    if (loc !== 'frigo') {
        if (/\b(biscott[io]|cookies|wafer|tarall[io]|crackers?)\b/.test(name)) return 60;
        if (/\b(muesli|cereali|corn\s*flakes|granola|fiocchi)\b/.test(name)) return 60;
        if (/\b(confettura|marmellata)\b/.test(name)) return 90;
        if (/\b(nutella|cioccolat)\b/.test(name)) return 90;
        if (/\bpane\b/.test(name)) return 4;
        // Dairy opened outside fridge: spoils very quickly at room temperature
        if (/\bpanna\b/.test(name)) return 3;
        if (/\b(yogurt|yaourt|yoghurt)\b/.test(name)) return 2;
        if (/\blatte\b/.test(name)) return 1;
        if (/\bformaggio\b/.test(name)) return 2;
        return 60;
    }

    if (/latte\s+(fresco|intero|parzial|scremato)/.test(name)) return 3;
    if (/latte\s+(uht|a\s+lunga)/.test(name)) return 7;
    // Long-life mountain/brand milks stored in pantry before use (UHT)
    if (/latte.*(montagna|alta\s+qual|parmalat|granarolo|esselunga|conservaz|microfiltrat)/i.test(name)) return 7;
    if (/\blatte\b/.test(name)) return 7; // generic: default to UHT (most common in IT households)
    if (/\b(yogurt|yaourt|yoghurt)\b/.test(name)) return 5;
    if (/mozzarella|burrata|stracciatella/.test(name)) return 3;
    if (/philadelphia|spalmabile/.test(name)) return 7;
    // Specific hard cheeses that contain 'fresco' in their commercial name (e.g. Asiago fresco)
    // must be matched BEFORE the generic 'formaggio fresco' catch-all
    if (/parmigiano|grana|pecorino|provolone|asiago|fontina|emmental|gruyere|scamorza|groviera/.test(name)) return 28;
    if (/formaggio.*(fresco|ricotta|mascarpone|stracchino|crescenza)/.test(name)) return 5;
    if (/formaggio/.test(name)) return 10;
    if (/\bburro\b/.test(name)) return 30;
    if (/\bpanna\b/.test(name)) return 4;
    if (/prosciutto\s+cotto|mortadella|wurstel/.test(name)) return 5;
    if (/prosciutto\s+crudo|salame|bresaola|speck|pancetta|nduja/.test(name)) return 7;
    if (/\b(pollo|tacchino|maiale|manzo|vitello|agnello)\b/.test(name)) return 2;
    if (/salmone|tonno\s+fresco|pesce(?!\s+in)/.test(name)) return 2;
    if (/\b(passata|pelati|polpa|sugo|salsa\s+di\s+pomodoro)\b/.test(name)) return 5;
    if (/insalata\s+di\s+(riso|pasta|farro|orzo|couscous)/.test(name)) return 7;
    if (/insalata|rucola|spinaci|lattuga|crescione|germogli/.test(name)) return 4;
    if (/\b(succo|spremuta)\b/.test(name)) return 3;
    if (/\b(birra|beer)\b/.test(name)) return 3;
    if (/\bvino\b/.test(name)) return 5;
    if (/tonno\s+in\s+scatola|tonno\s+rio|sgombro\s+in/.test(name)) return 4;
    // Fruit in fridge (opened pack, not necessarily cut)
    if (/\bavocado\b/.test(name)) return 3;
    if (/\b(fragola|fragole|lampone|lamponi|mirtillo|mirtilli|mora|more)\b/.test(name)) return 4;
    if (/\b(banana|banane|pesca|pesche|albicocca|albicocche|ciliegia|ciliegie|mango|papaya)\b/.test(name)) return 4;
    if (/\b(mela|mele|pera|pere|nettarina|prugna|kiwi|ananas|uva|melone|anguria)\b/.test(name)) return 5;
    if (/\b(arancia|arance|mandarino|mandarini|pompelmo|clementina|limone|limoni)\b/.test(name)) return 7;
    // Vegetables in fridge (opened pack)
    if (/\b(zucchina|zucchine|melanzana|melanzane|pomodor)\b/.test(name)) return 5;
    if (/\b(peperone|peperoni)\b/.test(name)) return 5;
    if (/\b(broccolo|broccoli|cavolfiore|cavolo)\b/.test(name)) return 4;
    if (/\bsedano\b|\bfinocchio\b/.test(name)) return 5;
    if (/\b(cipolla|cipolle|cipollotto|scalogno|porro)\b/.test(name)) return 6;
    if (/\b(carota|carote)\b/.test(name)) return 7;
    if (/\b(patata|patate|tubero)\b/.test(name)) return 4;
    if (/\baglio\b/.test(name)) return 14;

    // ── F.extra: Bread in fridge (opened) ────────────────────────────────
    // Thin flatbreads (piadina, crescia, tigella) get mold very quickly
    if (/\b(piadina|piadelle?|crescia|tigella)\b/.test(name)) return 2;
    // Packaged sliced bread — preservatives help a bit
    if (/\b(bauletto|pancarr[eè]|pan\s+carr[eè]?|tramezzin)\b/.test(name)) return 4;
    // Generic bread in fridge
    if (/\bpane\b/.test(cat)) return 3;

    // ── G: Fridge condiments ─────────────────────────────────────────────
    if (/maionese|mayo|mayon/.test(name)) return 90;
    if (/\bketchup\b/.test(name)) return 90;
    if (/\b(senape|mustard)\b/.test(name)) return 90;
    if (/salsa\s+di\s+soia|soy\s*sauce/.test(name)) return 90;
    if (/\b(tabasco|worcestershire|sriracha)\b/.test(name)) return 180;
    if (/confettura|marmellata/.test(name)) return 180;
    if (/nutella|cioccolat/.test(name)) return 60;

    // ── H: Category fallbacks ────────────────────────────────────────────
    if (/dairy|latticin/.test(cat)) return 5;
    if (/meat|carne/.test(cat)) return 3;
    if (/fish|pesce/.test(cat)) return 2;
    if (/fruit|frutta/.test(cat)) return 7;
    if (/verdur|vegetable/.test(cat)) return 5;
    if (/conserve/.test(cat)) return 7;
    if (/condimenti|sauce/.test(cat)) return 30;
    if (/bevand|beverage/.test(cat)) return 5;

    return 5; // safe default for fridge
}

function addDays(days) {
    const d = new Date();
    d.setDate(d.getDate() + days);
    return d.toISOString().split('T')[0];
}

// Guess location from product name keywords (fallback if no category)
function guessLocationFromName(name) {
    const n = (name || '').toLowerCase();
    // Frigo keywords
    if (/latte|yogurt|formaggio|mozzarella|burro|panna|uova|prosciutto|salame|wurstel|ricotta|mascarpone|gorgonzola|insalata|rucola|spinaci|pollo|manzo|maiale|salmone|tonno fresco|bresaola/.test(n)) return 'frigo';
    // Freezer keywords
    if (/surgel|frozen|gelato|ghiaccioli|bastoncini|findus|4 salti|pizza surgel|verdure surgel|minestrone surg/.test(n)) return 'freezer';
    // Dispensa keywords
    if (/pasta|riso|farina|zucchero|sale|olio|aceto|biscott|cracker|grissini|caffè|tè|the |tea |tonno|pelati|passata|legumi|ceci|fagioli|lenticchie|cereali|muesli|marmell|nutella|miele|cioccolat/.test(n)) return 'dispensa';
    return null; // unknown
}

function guessLocation(product) {
    // 1. Category-based
    if (product.category) {
        const cat = product.category.toLowerCase().replace(/^en:/, '').split(',')[0].trim();
        // Check our map
        for (const [key, loc] of Object.entries(CATEGORY_LOCATION)) {
            if (cat.includes(key)) return loc;
        }
        // Open Food Facts categories
        if (/dairy|lait|cheese|fromage|yoghurt|milk|latticin/i.test(cat)) return 'frigo';
        if (/meat|viande|carne|fish|poisson|pesce/i.test(cat)) return 'frigo';
        if (/frozen|surgelé|surgel/i.test(cat)) return 'freezer';
        if (/fruit|vegetable|verdur|frutta/i.test(cat)) return 'frigo';
        if (/beverage|drink|boisson|bevand/i.test(cat)) return 'dispensa';
        if (/pasta|cereal|grain|bread|biscuit|snack|sauce|condiment|conserv|can/i.test(cat)) return 'dispensa';
    }
    // 2. Name-based fallback
    const nameLoc = guessLocationFromName(product.name);
    if (nameLoc) return nameLoc;
    // 3. Default
    return 'dispensa';
}

// ===== STATE =====
let currentProduct = null;
let currentInventory = [];
let _actionInventoryItems = [];
let currentLocation = '';
let scannerStream = null;
let quaggaRunning = false;
let aiStream = null;
let _scanZoomLevel = 2; // always 2x
let _torchActive = false;
let _aiVisualGen = 0; // bumped on each new scan session — stale AI responses are ignored
let _scannerPaused = false; // pause decode loops without stopping the camera stream
let _scannerGen = 0;
let _detectorNative = null;
let _detectorZbar = null;
let _barcodeEnginesReady = null;
let _tesseractWorker = null;
let _tesseractInitPromise = null;
const _LOCAL_OCR_DELAY_MS = 2500;    // local digit OCR before AI
const _SCAN_FORMATS = ['ean_13', 'ean_8', 'code_128', 'code_39', 'upc_a', 'upc_e'];

// Apply fixed 2x zoom (hardware if available, CSS fallback)
async function _applyFixedZoom() {
    if (!scannerStream) return;
    const track = scannerStream.getVideoTracks()[0];
    if (!track) return;
    const caps = track.getCapabilities ? track.getCapabilities() : {};
    if (caps.zoom && caps.zoom.max >= 2) {
        const z = Math.min(caps.zoom.max, caps.zoom.min * 2);
        try { await track.applyConstraints({ advanced: [{ zoom: z }] }); scanLog(`HW zoom: ${z}`); } catch(e) {}
    } else {
        const video = document.getElementById('scanner-video');
        if (video) video.style.transform = 'scale(2)';
        scanLog('SW zoom: scale(2)');
    }
}

async function toggleTorch() {
    if (!scannerStream) return;
    const track = scannerStream.getVideoTracks()[0];
    if (!track) return;
    const caps = track.getCapabilities ? track.getCapabilities() : {};
    if (!caps.torch) { showToast(t('scan.torch_unavailable'), 'info'); return; }
    _torchActive = !_torchActive;
    try {
        await track.applyConstraints({ advanced: [{ torch: _torchActive }] });
        const btn = document.getElementById('scan-torch-btn');
        if (btn) btn.classList.toggle('torch-on', _torchActive);
        showToast(_torchActive ? t('scan.torch_on') : t('scan.torch_off'), 'info');
    } catch(e) { showToast(t('scan.torch_unavailable'), 'info'); _torchActive = false; }
}

async function flipCamera() {
    const s = getSettings();
    const current = s.camera_facing || 'environment';
    const next = current === 'environment' ? 'user' : 'environment';
    s.camera_facing = next;
    _settingsCache = s;
    _saveSettingToServer({ camera_facing: next });
    showToast(next === 'user' ? t('scan.flip_front') : t('scan.flip_back'), 'info');
    stopScanner();
    setTimeout(() => initScanner(), 150);
}

// ===== SCAN TAB SWITCHING =====
function switchScanTab(tab) {
    ['barcode','name','ai'].forEach(id => {
        const btn = document.getElementById(`scan-tab-${id}`);
        const content = document.getElementById(`scan-tabcontent-${id}`);
        const active = id === tab;
        if (btn) btn.classList.toggle('active', active);
        if (content) content.style.display = active ? '' : 'none';
    });
    // Spesa: no manual barcode field — never focus that input
    if (tab === 'barcode') {
        if (_spesaMode) return;
        const el = document.getElementById('manual-barcode-input');
        if (el) setTimeout(() => el.focus(), 80);
    } else if (tab === 'name') {
        const el = document.getElementById('quick-product-name');
        if (el) setTimeout(() => el.focus(), 80);
    }
}

// ===== SCAN HISTORY (server-synced via app_settings key "scan_history") =====
const _SCAN_HISTORY_MAX = 20;

function addToScanRecents(product) {
    if (!product || !product.id) return;
    let list = (_scanHistoryCache || []).filter(r => r.id !== product.id);
    list.unshift({ id: product.id, barcode: product.barcode || '', name: product.name, brand: product.brand || '', category: product.category || '', ts: Date.now() });
    if (list.length > _SCAN_HISTORY_MAX) list = list.slice(0, _SCAN_HISTORY_MAX);
    _scanHistoryCache = list;
    _saveToServer('scan_history', list);
}

async function updateScanRecents() {
    let list = (_scanHistoryCache || []).slice(0, 6);
    if (_spesaMode && list.length > 0) {
        try {
            const data = await api('inventory_list');
            const stocked = new Set((data.inventory || []).filter(i => parseFloat(i.quantity) > 0).map(i => i.product_id));
            list = list.filter(r => stocked.has(r.id));
        } catch (_) { /* keep list on error */ }
    }
    const wrap = document.getElementById('scan-recents');
    const chips = document.getElementById('scan-recents-chips');
    if (!wrap || !chips) return;
    if (list.length === 0) { wrap.style.display = 'none'; return; }
    wrap.style.display = 'flex';
    chips.innerHTML = list.map(r => {
        const icon = CATEGORY_ICONS[mapToLocalCategory(r.category, r.name)] || '📦';
        const label = escapeHtml(r.name) + (r.brand ? ` <span style="color:var(--text-muted);font-weight:400">${escapeHtml(r.brand)}</span>` : '');
        return `<button class="scan-recent-chip" onclick="_selectRecentProduct(${r.id})" title="${escapeHtml(r.name)}">
            <span class="scan-recent-chip-icon">${icon}</span>${label}
        </button>`;
    }).join('');
}

async function _productHasLiveStock(productId) {
    try {
        const data = await api('inventory_list');
        return (data.inventory || []).some(i => i.product_id == productId && parseFloat(i.quantity) > 0);
    } catch (_) {
        return true;
    }
}

async function _selectRecentProduct(productId) {
    showLoading(true);
    try {
        if (_spesaMode && !(await _productHasLiveStock(productId))) {
            showLoading(false);
            showToast(t('error.not_in_inventory'), 'error');
            return;
        }
        const data = await api('product_get', { id: productId });
        if (data.product) {
            currentProduct = data.product;
            if (!currentProduct.weight_info && currentProduct.notes) {
                const m = currentProduct.notes.match(/Peso:\s*([^·]+)/);
                if (m) currentProduct.weight_info = m[1].trim();
            }
            showLoading(false);
            stopScanner();
            showProductAction();
        } else {
            showLoading(false);
            showToast(t('error.not_found'), 'error');
        }
    } catch(e) {
        showLoading(false);
        showToast(t('error.connection'), 'error');
    }
}

// ===== SCAN LIVE CODE / CONFIRM OVERLAY =====
let _liveCodeTimer = null;
function _showScanLiveCode(code) {
    const el = document.getElementById('scan-live-code');
    if (!el) return;
    el.textContent = code;
    el.style.display = 'block';
    clearTimeout(_liveCodeTimer);
    _liveCodeTimer = setTimeout(() => { if (el) el.style.display = 'none'; }, 1500);
}
function _hideScanLiveCode() {
    const el = document.getElementById('scan-live-code');
    if (el) { el.style.display = 'none'; clearTimeout(_liveCodeTimer); }
}

function _showScanConfirm(name) {
    const overlay = document.getElementById('scan-confirm-overlay');
    const nameEl = document.getElementById('scan-confirm-name');
    if (!overlay) return;
    if (nameEl) nameEl.textContent = name || '';
    overlay.style.display = 'flex';
    setTimeout(() => { if (overlay) overlay.style.display = 'none'; }, 900);
}

// ===== AI NUMBER OCR (Gemini reads printed barcode digits) =====
let _numOcrRunning = false;
async function _tryGeminiNumberOCR(options = {}) {
    const { chainToVisual = false } = options;
    if (_numOcrRunning || !_requireGemini()) return;
    const video = document.getElementById('scanner-video');
    if (!video || !video.videoWidth) { showToast(t('error.camera'), 'error'); return; }
    _numOcrRunning = true;
    _updateScanAiButton();
    const btn = document.getElementById('scan-num-ocr-btn');
    if (btn) { btn.disabled = true; btn.textContent = t('scan.num_ocr_searching'); }
    _setScanStatus(t('scan.status_ocr_searching'), 'retry', t('scan.method_ai_ocr'));
    try {
        const canvas = document.createElement('canvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        canvas.getContext('2d').drawImage(video, 0, 0);
        const imageBase64 = canvas.toDataURL('image/jpeg', 0.88).split(',')[1];
        const result = await api('gemini_number_ocr', {}, 'POST', { image: imageBase64 });
        if (result.barcode) {
            scanLog(`AI OCR: found barcode ${result.barcode}`);
            showToast(t('scan.num_ocr_found').replace('{code}', result.barcode), 'success');
            onBarcodeDetected(result.barcode);
        } else {
            scanLog('AI OCR: barcode digits not found');
            if (chainToVisual && scannerStream && _scannerAiAllowed()) {
                scanLog('AI OCR failed — switching to visual product identification');
                _setScanStatus(t('scan.status_ai_visual_searching'), 'retry', t('scan.method_ai_vision'));
                await _tryGeminiVisualBarcode();
            } else {
                showToast(t('scan.num_ocr_not_found'), 'warning');
                _setScanStatus(t('scan.status_scanning'), '', '');
                resumeScanner();
            }
        }
    } catch(e) {
        scanLog(`AI OCR error: ${e.message}`);
        if (chainToVisual && scannerStream && _scannerAiAllowed()) {
            _setScanStatus(t('scan.status_ai_visual_searching'), 'retry', t('scan.method_ai_vision'));
            await _tryGeminiVisualBarcode();
        } else {
            _setScanStatus(t('scan.status_scanning'), '', '');
            resumeScanner();
        }
        showToast(t('error.connection'), 'error');
    } finally {
        _numOcrRunning = false;
        _updateScanAiButton();
        if (btn) { btn.disabled = false; btn.textContent = t('scan.num_ocr_btn'); }
    }
}

// ===== AI VISUAL PRODUCT IDENTIFICATION (manual button only) =====
let _aiBarcodeVisualRunning = false;
let _aiDetectedProductDraft = null;
let _aiInventoryCandidates = [];
let _aiFinishedCandidates = [];
let _aiCatalogCandidates = [];

function _showScanAiOverlay(msg) {
    const el = document.getElementById('scan-ai-overlay');
    const msgEl = document.getElementById('scan-ai-overlay-msg');
    if (el) el.style.display = 'flex';
    if (msgEl) msgEl.textContent = msg || '';
}
function _hideScanAiOverlay() {
    const el = document.getElementById('scan-ai-overlay');
    if (el) el.style.display = 'none';
}

/** Invalidate in-flight AI responses when starting a new scan session. */
function _resetAiFallbackForNewScan() {
    _aiVisualGen++;
    _hideScanAiOverlay();
    _updateScanAiButton();
}

/** Manual AI button — shown only while scanner is active and Gemini is online. */
function _updateScanAiButton() {
    const btn = document.getElementById('scan-ai-manual-btn');
    if (!btn) return;
    btn.textContent = t('scan.ai_manual_btn');
    const show = _currentPageId === 'scan' && !!scannerStream && _scannerAiAllowed() && !_aiBarcodeVisualRunning && !_numOcrRunning;
    btn.style.display = show ? '' : 'none';
    btn.disabled = !!(_aiBarcodeVisualRunning || _numOcrRunning || _aiDetectedProductDraft);
}

function _clearAiMatchPanel() {
    const result = document.getElementById('scan-result');
    if (!result) return;
    result.style.display = 'none';
    result.innerHTML = '';
    _aiDetectedProductDraft = null;
    _aiInventoryCandidates = [];
    _aiFinishedCandidates = [];
    _aiCatalogCandidates = [];
    _updateScanAiButton();
}
function _renderAiCandidateThumb(item) {
    const catIcon = CATEGORY_ICONS[mapToLocalCategory(item.category, item.name)] || '📦';
    if (item.image_url) {
        return `<img src="${escapeHtml(item.image_url)}" alt="" class="scan-ai-candidate-img" onerror="this.outerHTML='<span class=\\'scan-ai-candidate-icon\\'>${catIcon}</span>'">`;
    }
    return `<span class="scan-ai-candidate-icon">${catIcon}</span>`;
}
function _renderAiCandidateRow(item, idx, kind) {
    const locInfo = item.locations
        ? String(item.locations).split(',').map(l => (LOCATIONS[l.trim()] || { label: l.trim() }).label).join(', ')
        : (LOCATIONS[item.location]?.label || item.location || '');
    let meta = item.brand || '';
    if (kind === 'stock' && item.total_qty != null) {
        meta = [meta, `${parseFloat(item.total_qty)} ${item.unit || ''}`.trim(), locInfo].filter(Boolean).join(' · ');
    } else if (kind === 'finished') {
        const locLabel = locInfo ? `${locInfo}` : '';
        const qtyHint = item.expected_qty ? `${item.expected_qty} ${item.unit || ''}`.trim() : '';
        meta = [meta, t('scan.ai_match_finished_badge'), locLabel, qtyHint].filter(Boolean).join(' · ');
    } else {
        meta = [meta, item.barcode ? `…${String(item.barcode).slice(-4)}` : ''].filter(Boolean).join(' · ');
    }
    return `
        <button class="scan-ai-candidate-item" type="button" onclick="_selectAiProductCandidate('${kind}', ${idx})">
            <span class="scan-ai-candidate-thumb">${_renderAiCandidateThumb(item)}</span>
            <span class="scan-ai-candidate-info">
                <span class="scan-ai-candidate-name">${escapeHtml(item.name || '')}</span>
                <span class="scan-ai-candidate-meta">${escapeHtml(meta)}</span>
            </span>
            <span class="scan-ai-candidate-cta">${t('scan.ai_match_use_btn')}</span>
        </button>
    `;
}
function _renderAiCandidateSection(title, items, kind) {
    if (!items || !items.length) return '';
    const rows = items.map((it, i) => _renderAiCandidateRow(it, i, kind)).join('');
    return `
        <div class="scan-ai-match-list-wrap">
            <div class="scan-ai-match-list-title">${escapeHtml(title)}</div>
            <div class="scan-ai-match-list">${rows}</div>
        </div>
    `;
}
function _showAiMatchChoices(aiProduct) {
    const result = document.getElementById('scan-result');
    if (!result) return;
    const aiName = aiProduct?.name || t('product.not_recognized');
    const aiBrand = aiProduct?.brand || '';
    const catIcon = CATEGORY_ICONS[mapToLocalCategory(aiProduct?.category || '', aiName)] || '📦';
    const inStock = (_aiInventoryCandidates || []).filter(i => parseFloat(i.total_qty) > 0);
    const finished = _spesaMode ? [] : (_aiFinishedCandidates || []);
    const catalog = _spesaMode ? [] : (_aiCatalogCandidates || []);
    const hasMatches = inStock.length + finished.length + catalog.length > 0;
    const addLabel = t('scan.ai_match_add_btn').replace('{name}', aiName);

    result.innerHTML = `
        <div class="scan-ai-match-box">
            <div class="scan-ai-hero">
                <div class="scan-ai-hero-icon">${catIcon}</div>
                <div class="scan-ai-hero-text">
                    <div class="scan-ai-match-title">${t('scan.ai_match_title')}</div>
                    <div class="scan-ai-hero-name">${escapeHtml(aiName)}</div>
                    ${aiBrand ? `<div class="scan-ai-hero-brand">${escapeHtml(aiBrand)}</div>` : ''}
                </div>
            </div>

            <button class="btn btn-success btn-large scan-ai-add-btn" type="button" onclick="_confirmAiDetectedProduct()">
                ✅ ${escapeHtml(addLabel)}
            </button>
            <p class="scan-ai-action-hint">${escapeHtml(t('scan.ai_match_action_hint'))}</p>

            ${hasMatches ? `<p class="scan-ai-or-divider">${escapeHtml(t('scan.ai_match_or_similar'))}</p>` : `<div class="scan-ai-match-empty">${t('scan.ai_match_none')}</div>`}
            ${_renderAiCandidateSection(t('scan.ai_match_existing'), inStock, 'stock')}
            ${_renderAiCandidateSection(t('scan.ai_match_finished'), finished, 'finished')}
            ${_renderAiCandidateSection(t('scan.ai_match_catalog'), catalog, 'catalog')}
        </div>
    `;
    result.style.display = 'block';
    setTimeout(() => result.scrollIntoView({ behavior: 'smooth', block: 'center' }), 80);
}
async function _fetchAiMatchCandidates(aiProduct) {
    const q = (aiProduct?.name || '').trim();
    if (q.length < 2) {
        return { in_stock: [], finished: [], catalog: [] };
    }
    try {
        const res = await api('ai_product_suggest', {}, 'POST', { q, brand: aiProduct?.brand || '', limit: 5 });
        if (!res?.success) return { in_stock: [], finished: [], catalog: [] };
        return res;
    } catch (_) {
        return { in_stock: [], finished: [], catalog: [] };
    }
}

function _normalizeAiMatchName(name) {
    return String(name || '').trim().toLowerCase().replace(/\s+/g, ' ');
}

/** Prefer an existing catalog row (especially with barcode) over creating a duplicate from AI guesses. */
function _findBestAiCatalogMatch(aiProduct) {
    const aiName = _normalizeAiMatchName(aiProduct?.name);
    if (!aiName) return null;
    const candidates = [
        ...(_aiCatalogCandidates || []),
        ...(_aiInventoryCandidates || []),
        ...(_aiFinishedCandidates || []),
    ];
    let best = null;
    let bestScore = 0;
    for (const item of candidates) {
        if (!item?.id) continue;
        const itemName = _normalizeAiMatchName(item.name);
        if (!itemName) continue;
        let score = 0;
        if (itemName === aiName) score = 100;
        else if (itemName.includes(aiName) || aiName.includes(itemName)) score = 80;
        else {
            const aiTokens = aiName.split(' ').filter(Boolean);
            const shared = aiTokens.filter(t => itemName.includes(t)).length;
            if (shared >= 2) score = 60 + shared;
            else if (shared === 1 && aiTokens.length === 1) score = 50;
        }
        if (item.barcode) score += 15;
        if (score > bestScore) {
            bestScore = score;
            best = item;
        }
    }
    return bestScore >= 50 ? best : null;
}

async function _openAiSavedProduct(saveResult, draft, { showMergedToast = false } = {}) {
    const productId = saveResult?.id || saveResult?.existing_id;
    if (!productId) return false;
    const full = await api('product_get', { id: productId }).catch(() => null);
    currentProduct = full?.product || {
        id: productId,
        barcode: saveResult?.barcode || '',
        name: draft?.name || t('product.not_recognized'),
        brand: draft?.brand || '',
        category: mapToLocalCategory(draft?.category || '', draft?.name || '', draft?.brand || ''),
        image_url: '',
        unit: 'pz',
        default_quantity: 1,
        package_unit: '',
        _confCount: 0,
        weight_info: '',
    };
    if (currentProduct.notes) {
        const pesoMatch = currentProduct.notes.match(/Peso:\s*([^·]+)/);
        if (pesoMatch) currentProduct.weight_info = pesoMatch[1].trim();
    }
    addToScanRecents(currentProduct);
    _clearAiMatchPanel();
    showLoading(false);
    if (showMergedToast || saveResult?.merged) {
        showToast(t('scan.ai_match_merged_existing'), 'info');
    }
    setTimeout(() => showProductAction(), 250);
    return true;
}

async function _confirmAiDetectedProduct() {
    const p = _aiDetectedProductDraft;
    if (!p) return;
    showLoading(true);
    try {
        const localCategory = mapToLocalCategory(p.category || '', p.name || '', p.brand || '');

        const catalogMatch = _findBestAiCatalogMatch(p);
        if (catalogMatch?.barcode) {
            const resolved = await api('resolve_barcode', { barcode: String(catalogMatch.barcode).replace(/\D/g, '') }).catch(() => null);
            if (resolved?.found && resolved.product?.id) {
                currentProduct = resolved.product;
                if (currentProduct.notes) {
                    const pesoMatch = currentProduct.notes.match(/Peso:\s*([^·]+)/);
                    if (pesoMatch) currentProduct.weight_info = pesoMatch[1].trim();
                }
                addToScanRecents(currentProduct);
                _clearAiMatchPanel();
                showLoading(false);
                showToast(t('scan.ai_match_merged_existing'), 'info');
                setTimeout(() => showProductAction(), 250);
                return;
            }
        }
        if (catalogMatch?.id && !catalogMatch?.barcode) {
            const full = await api('product_get', { id: catalogMatch.id }).catch(() => null);
            if (full?.product) {
                currentProduct = full.product;
                if (currentProduct.notes) {
                    const pesoMatch = currentProduct.notes.match(/Peso:\s*([^·]+)/);
                    if (pesoMatch) currentProduct.weight_info = pesoMatch[1].trim();
                }
                addToScanRecents(currentProduct);
                _clearAiMatchPanel();
                showLoading(false);
                showToast(t('scan.ai_match_merged_existing'), 'info');
                setTimeout(() => showProductAction(), 250);
                return;
            }
        }

        const saveResult = await api('product_save', {}, 'POST', {
            barcode: null,
            name: p.name || t('product.not_recognized'),
            brand: p.brand || '',
            category: localCategory,
            image_url: '',
            unit: 'pz',
            default_quantity: 1,
            package_unit: '',
            notes: '',
        });

        if (saveResult?.success && (saveResult.id || saveResult.existing_id)) {
            await _openAiSavedProduct(saveResult, p, { showMergedToast: !!saveResult.merged });
            return;
        }

        if (saveResult?.error === 'barcode_already_used' && saveResult.existing_id) {
            if (await _openAiSavedProduct({ ...saveResult, id: saveResult.existing_id, merged: true }, p, { showMergedToast: true })) {
                return;
            }
        }

        showLoading(false);
        const errKey = saveResult?.error;
        const msg = errKey === 'offline' ? t('error.network')
            : errKey === 'unauthorized' ? t('error.connection')
            : errKey === 'save_failed' ? t('error.save')
            : (errKey || saveResult?.message || t('error.save'));
        console.error('[AI add] product_save', saveResult);
        showToast(msg, 'error');
    } catch (err) {
        showLoading(false);
        console.error('[AI add]', err);
        showToast(err?.message || t('error.connection'), 'error');
    }
}
async function _selectAiProductCandidate(kind, idx) {
    const lists = { stock: _aiInventoryCandidates, finished: _aiFinishedCandidates, catalog: _aiCatalogCandidates };
    const p = (lists[kind] || [])[idx];
    if (!p?.id) return;
    showLoading(true);
    try {
        const full = await api('product_get', { id: p.id });
        if (!full?.product) {
            showLoading(false);
            showToast(t('error.not_found'), 'error');
            return;
        }
        currentProduct = full.product;
        if (currentProduct.notes) {
            const pesoMatch = currentProduct.notes.match(/Peso:\s*([^·]+)/);
            if (pesoMatch) currentProduct.weight_info = pesoMatch[1].trim();
        }
        currentProduct._confCount = 0;
        const hasStock = kind === 'stock' ? await _productHasLiveStock(p.id) : false;
        addToScanRecents(currentProduct);
        _clearAiMatchPanel();
        showLoading(false);
        if (kind !== 'stock' || !hasStock) {
            if (_spesaMode || kind === 'stock') {
                showToast(t('error.not_in_inventory'), 'info');
            } else if (kind === 'finished') {
                showToast(t('scan.ai_match_finished_hint'), 'info');
            }
            setTimeout(() => showAddForm(), 250);
            return;
        }
        setTimeout(() => showProductAction(), 250);
    } catch (err) {
        showLoading(false);
        console.error('[AI select]', err);
        showToast(err?.message || t('error.connection'), 'error');
    }
}
async function _triggerManualAiScan() {
    if (!_scannerAiAllowed()) {
        showToast(t('error.no_api_key'), 'error');
        return;
    }
    if (_aiBarcodeVisualRunning || _numOcrRunning) return;
    _clearAiMatchPanel();
    if (_spesaMode) {
        await _tryGeminiVisualBarcode();
    } else {
        await _tryGeminiNumberOCR({ chainToVisual: true });
    }
}

async function _tryGeminiVisualBarcode() {
    if (_aiBarcodeVisualRunning || !_requireGemini()) return;
    const video = document.getElementById('scanner-video');
    if (!video || !video.videoWidth) return;

    // ★ Capture the frame BEFORE stopping the stream — after stopScanner() the
    //   video element is blanked and drawImage would send a black image to Gemini.
    const canvas = document.createElement('canvas');
    canvas.width  = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0);
    const imageBase64 = canvas.toDataURL('image/jpeg', 0.88).split(',')[1];
    if (!imageBase64) { scanLog('AI visual: failed to capture frame'); return; }

    _aiBarcodeVisualRunning = true;
    _updateScanAiButton();
    const myGen = _aiVisualGen;
    pauseScanner(); // keep camera stream alive — only pause decode while AI processes
    _setScanStatus(t('scan.status_ai_visual_searching'), 'retry', t('scan.method_ai_vision'));
    _showScanAiOverlay(t('scan.ai_overlay_msg'));

    try {
        const result = await api('gemini_barcode_visual', {}, 'POST', {
            image: imageBase64,
            lang: _currentLang || 'it',
        });

        if (myGen !== _aiVisualGen) {
            scanLog('AI visual: stale response ignored');
            _hideScanAiOverlay();
            resumeScanner();
            return;
        }

        if (result.found && result.product) {
            const p = result.product;
            scanLog(`AI visual: found "${p.name}" (${p.brand})`);
            _hideScanAiOverlay();
            showToast(t('scan.ai_fallback_found'), 'success');
            _aiDetectedProductDraft = {
                name: p.name || t('product.not_recognized'),
                brand: p.brand || '',
                category: mapToLocalCategory(p.category || '', p.name || '', p.brand || ''),
            };
            const matchRes = await _fetchAiMatchCandidates(_aiDetectedProductDraft);
            if (myGen !== _aiVisualGen) {
                scanLog('AI visual: stale match response ignored');
                _hideScanAiOverlay();
                resumeScanner();
                return;
            }
            _aiInventoryCandidates = matchRes.in_stock || [];
            _aiFinishedCandidates = matchRes.finished || [];
            _aiCatalogCandidates = matchRes.catalog || [];
            _showAiMatchChoices(_aiDetectedProductDraft);
            resumeScanner();
        } else {
            if (myGen !== _aiVisualGen) {
                scanLog('AI visual: stale miss ignored');
                _hideScanAiOverlay();
                resumeScanner();
                return;
            }
            scanLog('AI visual: product not identified');
            _hideScanAiOverlay();
            showToast(t('scan.ai_not_recognized'), 'warning');
            _setScanStatus(t('scan.status_scanning'), '', '');
            resumeScanner();
        }
    } catch (e) {
        if (myGen !== _aiVisualGen) {
            scanLog('AI visual: stale error ignored');
            _hideScanAiOverlay();
            resumeScanner();
            return;
        }
        scanLog(`AI visual error: ${e.message}`);
        _hideScanAiOverlay();
        showToast(t('scan.ai_not_recognized'), 'warning');
        _setScanStatus(t('scan.status_scanning'), '', '');
        resumeScanner();
    } finally {
        _aiBarcodeVisualRunning = false;
        _updateScanAiButton();
    }
}

// ===== CAMERA HELPER =====
function getCameraConstraints(extraVideo = {}) {
    const s = getSettings();
    const mode = s.camera_facing || 'environment';
    // Front cameras on older devices often have lower resolution — don't over-request
    const isFront = (mode === 'user');
    const videoConstraints = {
        width: { ideal: isFront ? 640 : 1280 },
        height: { ideal: isFront ? 480 : 720 },
        ...extraVideo
    };
    if (mode === 'environment' || mode === 'user') {
        videoConstraints.facingMode = mode;
    } else {
        // Specific deviceId selected
        videoConstraints.deviceId = { exact: mode };
    }
    return { video: videoConstraints };
}

function isFrontCamera() {
    const s = getSettings();
    return (s.camera_facing || 'environment') === 'user';
}

async function enumerateCameras() {
    try {
        // Need a temporary stream to get device labels
        const tempStream = await navigator.mediaDevices.getUserMedia({ video: true });
        const devices = await navigator.mediaDevices.enumerateDevices();
        tempStream.getTracks().forEach(t => t.stop());
        return devices.filter(d => d.kind === 'videoinput');
    } catch(e) {
        return [];
    }
}

// ===== SETTINGS / CONFIG =====
let _settingsCache = null;
let _settingsDirty = false;

function getSettings() {
    if (!_settingsCache) {
        // Settings come from server — do NOT read from localStorage (per-device storage).
        // _settingsCache is populated by _applySyncedSettings() on app init.
        _settingsCache = {};
    }
    const s = _settingsCache;
    // Build recipe_prefs array from individual booleans
    s.recipe_prefs = [];
    if (s.pref_veloce) s.recipe_prefs.push('veloce');
    if (s.pref_pocafame) s.recipe_prefs.push('pocafame');
    if (s.pref_scadenze) s.recipe_prefs.push('scadenze');
    if (s.pref_healthy) s.recipe_prefs.push('salutare');
    if (s.pref_opened) s.recipe_prefs.push('opened');
    if (s.pref_zerowaste) s.recipe_prefs.push('zerowaste');
    s.dietary_restrictions = s.dietary || '';
    return s;
}

function saveSettingsToStorage(settings) {
    _settingsCache = settings;
    // Cache dark_mode in localStorage ONLY as a hint for the pre-render _earlyTheme() IIFE
    // (prevents flash before server fetch). Authoritative value is in server .env.
    try { localStorage.setItem('evershelf_dark_mode', settings.dark_mode || 'auto'); } catch(_) {}
    // Persist user-prefs subset to DB
    _settingsDirty = true;
    _debouncedSyncSettings();
}

/** Save one or more settings directly to server .env (partial update). */
async function _saveSettingToServer(data) {
    try {
        const result = await api('save_settings', {}, 'POST', data);
        if (result && result.success === false) {
            showToast(result.error || t('error.settings_save'), 'error');
            return false;
        }
        return true;
    } catch (e) {
        showToast(t('error.settings_save') || 'Impossibile salvare le impostazioni', 'error');
        return false;
    }
}

const _debouncedSyncSettings = debounce(function() {
    if (!_settingsDirty) return;
    _settingsDirty = false;
    const s = getSettings();
    // Don't sync secrets or device-specific settings to shared DB
    const shared = {
        default_persons: s.default_persons,
        pref_veloce: s.pref_veloce,
        pref_pocafame: s.pref_pocafame,
        pref_scadenze: s.pref_scadenze,
        pref_healthy: s.pref_healthy,
        pref_opened: s.pref_opened,
        pref_zerowaste: s.pref_zerowaste,
        dietary: s.dietary,
        appliances: s.appliances,
    };
    api('app_settings_save', {}, 'POST', { settings: { user_prefs: shared } }).catch(() => {});
}, 1000);

function debounce(fn, ms) {
    let t; return function(...args) { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
}

async function syncSettingsFromDB() {
    try {
        // Primary: load from server .env (only when not already done via _applySyncedSettings)
        const serverSettings = await api('get_settings');
        _applySyncedSettings(serverSettings);
        // Load all server-persisted user data from SQLite app_settings
        const res = await api('app_settings_get');
        if (res.success && res.settings) {
            const srv = res.settings;

            if (srv.review_confirmed) _reviewConfirmedCache = srv.review_confirmed;

            // meal_plan is stored in SQLite app_settings so all devices stay in sync
            if (srv.meal_plan) {
                const s = getSettings();
                s.meal_plan = srv.meal_plan;
                _settingsCache = s;
                if (document.getElementById('meal-plan-grid')) renderMealPlanEditor();
            }
            // tts_voice preference (best-effort cross-device — falls back if voice unavailable)
            if (srv.tts_voice) {
                const s = getSettings();
                if (!s.tts_voice) { s.tts_voice = srv.tts_voice; _settingsCache = s; }
            }

            // ── User data previously stored in localStorage, now server-synced ──
            if (srv.scan_history)        _scanHistoryCache       = srv.scan_history;
            if (srv.shopping_tags)       _shoppingTagsCache      = srv.shopping_tags;
            if (srv.pinned_bring)        _pinnedBringCache       = srv.pinned_bring;
            if (srv.pref_use_loc)        _prefUseLocCache        = srv.pref_use_loc;
            if (srv.pref_move_loc)       _prefMoveLocCache       = srv.pref_move_loc;
            if (srv.auto_added_bring)    _autoAddedBringCache    = srv.auto_added_bring;
            if (srv.bring_blocklist)     _bringBlocklistCache    = srv.bring_blocklist;
            if (srv.no_expiry_dismissed) _noExpiryDismissedCache = srv.no_expiry_dismissed;
            if (srv.family_sibling_confirmed) _familySiblingConfirmedCache = srv.family_sibling_confirmed;
            if (srv.shopping_plan_days != null && srv.shopping_plan_days !== '') {
                _shoppingPlanDaysCache = parseInt(srv.shopping_plan_days, 10) || null;
            }

            // ── One-time migration: if server has nothing yet, seed from old localStorage ──
            if (!srv.shopping_tags) {
                try { const v = localStorage.getItem('shopping_tags'); if (v) { _shoppingTagsCache = JSON.parse(v); _saveToServer('shopping_tags', _shoppingTagsCache); localStorage.removeItem('shopping_tags'); } } catch(_) {}
            }
            if (!srv.pinned_bring) {
                try { const v = localStorage.getItem('_userPinnedBring'); if (v) { _pinnedBringCache = JSON.parse(v); _saveToServer('pinned_bring', _pinnedBringCache); localStorage.removeItem('_userPinnedBring'); } } catch(_) {}
            }
            if (!srv.pref_use_loc) {
                try { const v = localStorage.getItem('_prefUseLoc'); if (v) { _prefUseLocCache = JSON.parse(v); _saveToServer('pref_use_loc', _prefUseLocCache); localStorage.removeItem('_prefUseLoc'); } } catch(_) {}
            }
            if (!srv.pref_move_loc) {
                try { const v = localStorage.getItem('_prefMoveLoc'); if (v) { _prefMoveLocCache = JSON.parse(v); _saveToServer('pref_move_loc', _prefMoveLocCache); localStorage.removeItem('_prefMoveLoc'); } } catch(_) {}
            }
            if (!srv.auto_added_bring) {
                try { const v = localStorage.getItem('_autoAddedBring'); if (v) { _autoAddedBringCache = JSON.parse(v); _saveToServer('auto_added_bring', _autoAddedBringCache); localStorage.removeItem('_autoAddedBring'); } } catch(_) {}
            }
            if (!srv.bring_blocklist) {
                try { const v = localStorage.getItem('_bringPurchasedBlocklist'); if (v) { _bringBlocklistCache = JSON.parse(v); _saveToServer('bring_blocklist', _bringBlocklistCache); localStorage.removeItem('_bringPurchasedBlocklist'); } } catch(_) {}
            }
            if (!srv.no_expiry_dismissed) {
                try { const v = localStorage.getItem('_noExpiryDismissed'); if (v) { _noExpiryDismissedCache = JSON.parse(v); _saveToServer('no_expiry_dismissed', _noExpiryDismissedCache); localStorage.removeItem('_noExpiryDismissed'); } } catch(_) {}
            }
            if (!srv.scan_history) {
                try { const v = localStorage.getItem('evershelf_scan_recents'); if (v) { _scanHistoryCache = JSON.parse(v); _saveToServer('scan_history', _scanHistoryCache); localStorage.removeItem('evershelf_scan_recents'); } } catch(_) {}
            }
        }
    } catch(e) { /* offline — in-memory caches stay at their defaults */ }
}

/**
 * Apply server settings object into in-memory cache (_settingsCache).
 * Called both from _initApp (to reuse an already-fetched response) and syncSettingsFromDB.
 */
function _applySyncedSettings(serverSettings) {
    if (!serverSettings) return;
    _geminiAvailable = !!(serverSettings.gemini_key_set);
    _demoMode = !!serverSettings.demo_mode;
    _updateGeminiButtonState();
    _applyDemoModeUI();
    const s = getSettings();
    const serverKeys = ['default_persons','pref_veloce','pref_pocafame','pref_scadenze',
        'pref_healthy','pref_opened','pref_zerowaste','dietary','appliances',
        'camera_facing','scale_enabled','scale_gateway_url',
        'meal_plan_enabled','tts_enabled','tts_url','tts_token',
        'tts_method','tts_auth_type','tts_content_type','tts_payload_key',
        'tts_engine','tts_rate','tts_pitch','tts_auth_header_name','tts_auth_header_value','tts_extra_fields',
        'screensaver_enabled','screensaver_timeout',
        'price_enabled','price_country','price_currency','price_update_months',
        'zerowaste_tips_enabled',
        'shopping_enabled','shopping_mode','shopping_smart_suggestions',
        'shopping_forecast','shopping_auto_add_threshold',
        'dark_mode',
        'barcode_ai_fallback',
        // Home Assistant
        'ha_enabled','ha_url','ha_tts_entity','ha_webhook_id','ha_webhook_events',
        'ha_notify_service','ha_expiry_days'];
    let changed = false;
    for (const key of serverKeys) {
        if (serverSettings[key] !== undefined && serverSettings[key] !== null && serverSettings[key] !== '') {
            s[key] = serverSettings[key];
            changed = true;
        }
    }
    if (changed) {
        _settingsCache = s;
        // Update localStorage hint for _earlyTheme() IIFE on next load
        try { localStorage.setItem('evershelf_dark_mode', s.dark_mode || 'auto'); } catch(_) {}
    }
}

let _infoTabTimer  = null;
let _backupTabTimer = null;

/**
 * Load the Info tab: Gemini token usage + cost, log size, DB size, log level.
 * Called on tab click; auto-refreshes every 30s while the tab is open.
 */
// ── Backup Tab ────────────────────────────────────────────────────────────────

async function _loadBackupTab() {
    if (_backupTabTimer) { clearInterval(_backupTabTimer); _backupTabTimer = null; }
    await _renderBackupTab();
    // Pull server settings to populate inputs if not yet loaded
    try {
        const ss = await api('get_settings');
        if (ss) {
            const bkRetEl = document.getElementById('setting-backup-retention-days');
            if (bkRetEl) { bkRetEl.value = ss.backup_retention_days || 3; bkRetEl.dataset.loaded = '1'; }
            const gdriveEnEl = document.getElementById('setting-gdrive-enabled');
            if (gdriveEnEl) gdriveEnEl.checked = !!ss.gdrive_enabled;
            const gdriveFolderEl = document.getElementById('setting-gdrive-folder-id');
            if (gdriveFolderEl) { gdriveFolderEl.value = ss.gdrive_folder_id || ''; gdriveFolderEl.dataset.loaded = '1'; }
            const gdriveRetEl = document.getElementById('setting-gdrive-retention-days');
            if (gdriveRetEl) { gdriveRetEl.value = ss.gdrive_retention_days || 30; gdriveRetEl.dataset.loaded = '1'; }
            // Pre-fill client_id (never show secret back)
            if (ss.gdrive_client_id_set) {
                const ciEl = document.getElementById('setting-gdrive-client-id');
                if (ciEl && !ciEl.value) ciEl.placeholder = '● ● ● already configured ● ● ●';
            }
            // OAuth token status
            const oauthStatusEl = document.getElementById('gdrive-oauth-token-status');
            if (oauthStatusEl) {
                oauthStatusEl.textContent = ss.gdrive_refresh_token_set
                    ? ('✅ ' + (t('settings.backup.gdrive_oauth_authorized') || 'Authorized'))
                    : ('⚠️ ' + (t('settings.backup.gdrive_oauth_not_authorized') || 'Not authorized yet'));
                oauthStatusEl.style.color = ss.gdrive_refresh_token_set ? '#15803d' : '#b45309';
            }
            // Redirect URI for OAuth setup — always http://localhost for self-hosted compat
            // (can be overridden server-side via GDRIVE_REDIRECT_URI env var)
            const rdEl = document.getElementById('gdrive-redirect-uri-display');
            if (rdEl) rdEl.textContent = 'http://localhost';
        }
    } catch(e) { /* non-critical */ }
}

async function _renderBackupTab() {
    const lastInfoEl = document.getElementById('backup-last-info');
    const listEl     = document.getElementById('backup-list-container');
    try {
        const data = await api('backup_list');
        if (!data || !data.success) {
            if (lastInfoEl) lastInfoEl.innerHTML = '<span style="color:#ef4444">Error loading backup info</span>';
            return;
        }
        // Last backup info
        if (lastInfoEl) {
            if (data.last_backup_ts) {
                const secsAgo = Math.floor(Date.now() / 1000) - data.last_backup_ts;
                let ago;
                if (secsAgo < 120)           ago = secsAgo < 5 ? t('time.just_now') : t('time.seconds_ago', { n: secsAgo });
                else if (secsAgo < 3600)     ago = t('time.minutes_ago', { n: Math.floor(secsAgo / 60) });
                else if (secsAgo < 86400)    ago = t('time.hours_ago', { n: Math.floor(secsAgo / 3600) });
                else                         ago = t('time.days_ago', { n: Math.floor(secsAgo / 86400) });
                const name = data.last_backup_file || '';
                lastInfoEl.innerHTML = `<strong>${t('settings.backup.last_backup') || 'Ultimo backup'}</strong>: ${ago} <span style="color:#94a3b8;font-size:0.78rem">(${name})</span>`;
            } else {
                lastInfoEl.innerHTML = `<em style="color:#f59e0b">${t('settings.backup.no_backup_yet') || 'Nessun backup ancora'}</em>`;
            }
        }
        // Backup list
        if (listEl) {
            if (!data.backups || data.backups.length === 0) {
                listEl.innerHTML = `<p class="settings-hint" style="text-align:center;padding:12px">${t('settings.backup.list_empty') || 'Nessun backup disponibile'}</p>`;
            } else {
                const rows = data.backups.map(b => {
                    const d = new Date(b.created_at);
                    const dateStr = d.toLocaleString();
                    return `<div style="display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid var(--border-color,#e2e8f0);font-size:0.83rem">
                        <span style="flex:1;color:var(--text-primary)">${b.filename}</span>
                        <span style="color:#94a3b8;white-space:nowrap">${b.size_kb} KB · ${dateStr}</span>
                        <button class="btn btn-small btn-secondary" onclick="_backupRestore('${b.filename}')" style="flex-shrink:0" title="${t('settings.backup.restore_btn') || 'Ripristina'}">${t('settings.backup.restore_btn') || '↩ Ripristina'}</button>
                        <button class="btn btn-small btn-danger" onclick="_backupDelete('${b.filename}')" style="flex-shrink:0" title="${t('settings.backup.delete_btn') || 'Elimina'}">🗑</button>
                    </div>`;
                }).join('');
                listEl.innerHTML = `<p style="font-size:0.78rem;color:#94a3b8;margin-bottom:6px">${t('settings.backup.retention_info') || ''} ${data.retention_days} ${t('settings.backup.retention_days') || 'gg'}</p>${rows}`;
            }
        }
    } catch(e) {
        if (lastInfoEl) lastInfoEl.innerHTML = '<span style="color:#ef4444">Error: ' + e.message + '</span>';
    }
}

async function _backupNow() {
    const btn = document.getElementById('btn-backup-now');
    const statusEl = document.getElementById('backup-status');
    if (btn) btn.disabled = true;
    if (statusEl) { statusEl.className = 'settings-status'; statusEl.textContent = t('settings.backup.backing_up') || '⏳ Backup in corso…'; statusEl.style.display = 'block'; }
    try {
        const r = await api('backup_now');
        if (r && r.success) {
            if (statusEl) { statusEl.className = 'settings-status success'; statusEl.textContent = `✅ ${r.filename} (${r.size_kb} KB)`; }
            await _renderBackupTab();
        } else {
            if (statusEl) { statusEl.className = 'settings-status error'; statusEl.textContent = `❌ ${r?.error || 'Error'}`; }
        }
    } catch(e) {
        if (statusEl) { statusEl.className = 'settings-status error'; statusEl.textContent = `❌ ${e.message}`; }
    } finally {
        if (btn) btn.disabled = false;
        if (statusEl) setTimeout(() => { statusEl.style.display = 'none'; }, 5000);
    }
}

async function _backupDelete(filename) {
    if (!confirm(`${t('settings.backup.delete_confirm') || 'Eliminare il backup'} ${filename}?`)) return;
    const r = await api('backup_delete', {}, 'POST', { filename });
    if (r && r.success) await _renderBackupTab();
    else alert(`❌ ${r?.error || 'Error deleting backup'}`);
}

async function _backupRestore(filename) {
    if (!confirm(`${t('settings.backup.restore_confirm') || 'Ripristinare il backup'} "${filename}"?\n\n⚠️ ATTENZIONE: tutti i dati attuali verranno SOSTITUITI. Questa azione è irreversibile.`)) return;
    const statusEl = document.getElementById('backup-status');
    if (statusEl) { statusEl.className = 'settings-status'; statusEl.textContent = '⏳ Ripristino in corso…'; statusEl.style.display = 'block'; }
    try {
        const r = await api('backup_restore', {}, 'POST', { filename });
        if (r && r.success) {
            alert(`✅ ${r.message || 'Ripristino completato!'}\n\nLa pagina verrà ricaricata.`);
            location.reload();
        } else {
            if (statusEl) { statusEl.className = 'settings-status error'; statusEl.textContent = `❌ ${r?.error || 'Error'}`; }
        }
    } catch(e) {
        if (statusEl) { statusEl.className = 'settings-status error'; statusEl.textContent = `❌ ${e.message}`; }
    }
}

async function _gdriveTest() {
    const btn = document.getElementById('btn-gdrive-test');
    const statusEl = document.getElementById('gdrive-test-status');
    if (btn) btn.disabled = true;
    if (statusEl) { statusEl.className = 'settings-status'; statusEl.textContent = '⏳ Test connessione…'; statusEl.style.display = 'block'; }
    try {
        // Save current settings first so the server has the latest JSON/folder
        await saveSettings();
        const r = await api('gdrive_test');
        if (r && r.success) {
            if (statusEl) { statusEl.className = 'settings-status success'; statusEl.textContent = `✅ ${t('settings.backup.gdrive_ok') || 'Connessione riuscita!'}`; }
        } else {
            if (statusEl) { statusEl.className = 'settings-status error'; statusEl.textContent = `❌ ${r?.error || 'Error'}`; }
        }
    } catch(e) {
        if (statusEl) { statusEl.className = 'settings-status error'; statusEl.textContent = `❌ ${e.message}`; }
    } finally {
        if (btn) btn.disabled = false;
        if (statusEl) setTimeout(() => { statusEl.style.display = 'none'; }, 6000);
    }
}

async function _gdrivePushNow() {
    const btn = document.getElementById('btn-gdrive-push');
    const statusEl = document.getElementById('gdrive-test-status');
    if (btn) btn.disabled = true;
    if (statusEl) { statusEl.className = 'settings-status'; statusEl.textContent = t('settings.backup.gdrive_pushing') || '⏳ Upload in corso…'; statusEl.style.display = 'block'; }
    try {
        await saveSettings();
        const r = await api('gdrive_push');
        if (r && r.success) {
            if (statusEl) { statusEl.className = 'settings-status success'; statusEl.textContent = `✅ ${r.filename} → Drive (purged: ${r.purged_remote || 0})`; }
        } else {
            if (statusEl) { statusEl.className = 'settings-status error'; statusEl.textContent = `❌ ${r?.error || 'Error'}`; }
        }
    } catch(e) {
        if (statusEl) { statusEl.className = 'settings-status error'; statusEl.textContent = `❌ ${e.message}`; }
    } finally {
        if (btn) btn.disabled = false;
        if (statusEl) setTimeout(() => { statusEl.style.display = 'none'; }, 6000);
    }
}

async function _gdriveAuthorize() {
    const btn = document.getElementById('btn-gdrive-authorize');
    if (btn) btn.disabled = true;
    try {
        await saveSettings();
        const r = await api('gdrive_oauth_url');
        if (r && r.success) {
            window.open(r.url, '_blank', 'width=600,height=700,noopener');
            // Store redirect_uri used so gdrive_oauth_exchange can match it
            window._gdriveLastRedirectUri = r.redirect_uri || 'http://localhost';
            // Show manual code input section
            const codeSection = document.getElementById('gdrive-code-section');
            if (codeSection) codeSection.style.display = '';
            const statusEl = document.getElementById('gdrive-oauth-token-status');
            if (statusEl) {
                statusEl.textContent = t('settings.backup.gdrive_oauth_window_opened') || '🔑 Authorization page opened — authorize and paste the URL below';
                statusEl.style.color = '#2563eb';
            }
        } else {
            alert('❌ ' + (r?.error || 'Failed to get OAuth URL'));
        }
    } catch(e) {
        alert('❌ ' + e.message);
    } finally {
        if (btn) btn.disabled = false;
    }
}

async function _gdriveSubmitCode() {
    const inputEl = document.getElementById('gdrive-code-input');
    const btn     = document.getElementById('btn-gdrive-submit-code');
    const raw     = (inputEl?.value || '').trim();
    if (!raw) { alert(t('settings.backup.gdrive_code_empty') || 'Paste the URL or code first'); return; }

    // Accept either a full URL (extract code param) or just the bare code
    let code = raw;
    try {
        const u = new URL(raw);
        const c = u.searchParams.get('code');
        if (c) code = c;
    } catch(e) { /* not a URL, use as-is */ }

    if (btn) btn.disabled = true;
    try {
        const r = await api('gdrive_oauth_exchange', null, 'POST', {
            code,
            redirect_uri: window._gdriveLastRedirectUri || 'http://localhost'
        });
        if (r && r.success) {
            const statusEl = document.getElementById('gdrive-oauth-token-status');
            if (statusEl) {
                statusEl.textContent = '✅ ' + (t('settings.backup.gdrive_oauth_authorized') || 'Authorized');
                statusEl.style.color = '#15803d';
            }
            const codeSection = document.getElementById('gdrive-code-section');
            if (codeSection) codeSection.style.display = 'none';
            if (inputEl) inputEl.value = '';
        } else {
            alert('❌ ' + (r?.error || 'Code exchange failed'));
        }
    } catch(e) {
        alert('❌ ' + e.message);
    } finally {
        if (btn) btn.disabled = false;
    }
}

async function _loadInfoTab() {
    // Cancel any previous auto-refresh
    if (_infoTabTimer)   { clearInterval(_infoTabTimer);   _infoTabTimer  = null; }
    if (_backupTabTimer) { clearInterval(_backupTabTimer); _backupTabTimer = null; }
    await _renderInfoTab();
    // Auto-refresh every 30s while Info tab is visible
    _infoTabTimer = setInterval(_renderInfoTab, 30_000);
}

async function _renderInfoTab() {
    const aiEl  = document.getElementById('info-ai-content');
    const sysEl = document.getElementById('info-system-content');
    if (!aiEl && !sysEl) return;

    try {
        const d = await api('gemini_usage');
        const s = getSettings();

        // ── Locale & helpers ─────────────────────────────────────────────────
        const langMap = {it:'it-IT', en:'en-US', de:'de-DE', fr:'fr-FR', es:'es-ES'};
        const locale  = langMap[s.language] || langMap[navigator.language?.slice(0,2)] || 'it-IT';
        const [yr, mo] = (d.month || '').split('-');
        const monthLabel = new Intl.DateTimeFormat(locale, {month:'long', year:'numeric'})
            .format(new Date(parseInt(yr), parseInt(mo)-1, 1));

        // Cost → user currency
        const toCurr = (usd) => {
            if (!usd) return '—';
            const c = s.price_currency || 'EUR';
            let v = usd, sym = '$';
            if      (c === 'EUR') { v = usd * 0.92; sym = '€'; }
            else if (c === 'GBP') { v = usd * 0.79; sym = '£'; }
            else if (c === 'CHF') { v = usd * 0.90; sym = 'CHF '; }
            else if (c === 'CAD') { v = usd * 1.36; sym = 'CA$'; }
            else if (c === 'AUD') { v = usd * 1.54; sym = 'A$'; }
            else if (c === 'BRL') { v = usd * 5.20; sym = 'R$'; }
            else if (c === 'JPY') { v = usd * 155;  sym = '¥'; }
            else if (c === 'SEK') { v = usd * 10.4; sym = 'kr'; }
            else if (c === 'NOK') { v = usd * 10.6; sym = 'kr'; }
            else if (c === 'DKK') { v = usd * 6.85; sym = 'kr'; }
            else if (c === 'PLN') { v = usd * 3.98; sym = 'zł'; }
            const decimals = (c === 'JPY') ? 1 : 4;
            return sym + v.toFixed(decimals);
        };
        const fmtTok   = n => n >= 1_000_000 ? (n/1_000_000).toFixed(2)+'M'
                            : n >= 1_000 ? Math.round(n/1_000)+'K' : String(n||0);
        const fmtBytes = b => b > 1048576 ? (b/1048576).toFixed(1)+' MB'
                            : b > 1024 ? Math.round(b/1024)+' KB' : (b||0)+' B';
        const fmtDate  = ts => ts ? new Intl.DateTimeFormat(locale, {day:'2-digit', month:'short', hour:'2-digit', minute:'2-digit'}).format(new Date(ts*1000)) : '—';
        const pill = (val, label, color='') =>
            `<div style="background:var(--bg-secondary);border:1px solid var(--border-color,#e2e8f0);border-radius:10px;padding:8px 14px;min-width:70px;text-align:center${color ? ';border-color:'+color : ''}">
                <div style="font-size:1.1rem;font-weight:700;color:${color||'var(--text-primary,#1e293b)'}">${val}</div>
                <div style="font-size:0.7rem;color:var(--text-secondary,#64748b);margin-top:2px">${label}</div>
            </div>`;
        const sectionHeader = (label) =>
            `<div style="font-size:0.78rem;font-weight:600;color:var(--text-secondary);margin-bottom:8px;text-transform:uppercase;letter-spacing:.04em">${label}</div>`;

        // ── AI Usage card ────────────────────────────────────────────────────
        if (aiEl) {
            const ms = d.month_stats || {};
            const ys = d.year_stats  || {};

            const hintEl = aiEl.closest('.settings-card')?.querySelector('.info-ai-subtitle');
            if (hintEl) hintEl.textContent = t('settings.info.ai_overview');

            const msIn  = ms.input_tokens  || 0;
            const msOut = ms.output_tokens || 0;
            const ysIn  = ys.input_tokens  || 0;
            const ysOut = ys.output_tokens || 0;

            // Month section
            const actionRows = Object.entries(ms.by_action || {})
                .sort((a,b) => b[1]-a[1]).slice(0, 8)
                .map(([k,v]) => `<tr><td style="padding:3px 12px 3px 0;color:var(--text-secondary);font-size:0.82rem">${k}</td><td style="font-variant-numeric:tabular-nums;font-size:0.82rem"><strong>${v}</strong> ${t('settings.info.calls_unit')}</td></tr>`).join('');
            const modelRows  = Object.entries(ms.by_model  || {})
                .map(([m,mv]) => `<tr><td style="padding:3px 12px 3px 0;color:var(--text-secondary);font-size:0.82rem">${m}</td><td style="font-variant-numeric:tabular-nums;font-size:0.82rem"><strong>${fmtTok((mv.in||0)+(mv.out||0))}</strong></td></tr>`).join('');

            const monthHtml = `
                <div style="background:var(--bg-secondary);border-radius:10px;padding:12px;margin-bottom:10px">
                    ${sectionHeader(monthLabel)}
                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                        ${pill(ms.calls || 0, t('settings.info.ai_calls'))}
                        ${pill('~'+fmtTok(msIn+msOut), t('settings.info.total_tokens'))}
                        ${pill('~'+toCurr(ms.cost_usd), t('settings.info.est_cost'), '#15803d')}
                    </div>
                    ${actionRows ? `<details style="margin-top:8px"><summary style="font-size:0.82rem;cursor:pointer;color:var(--text-secondary)">${t('settings.info.by_action')}</summary><table style="margin-top:6px;border-collapse:collapse">${actionRows}</table></details>` : ''}
                    ${modelRows  ? `<details style="margin-top:4px"><summary style="font-size:0.82rem;cursor:pointer;color:var(--text-secondary)">${t('settings.info.by_model')}</summary><table style="margin-top:6px;border-collapse:collapse">${modelRows}</table></details>` : ''}
                </div>`;

            // Year section
            const yearHtml = `
                <div style="background:var(--bg-secondary);border-radius:10px;padding:12px;margin-bottom:10px">
                    ${sectionHeader(t('settings.info.year_label').replace('{year}', d.year))}
                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                        ${pill('~'+(ys.calls || 0), t('settings.info.ai_calls'))}
                        ${pill('~'+fmtTok(ysIn+ysOut), t('settings.info.total_tokens'))}
                        ${pill('~'+toCurr(ys.cost_usd), t('settings.info.est_cost'), '#15803d')}
                    </div>
                </div>`;

            aiEl.innerHTML = monthHtml + yearHtml
                + `<p class="settings-hint" style="margin-top:4px">${t('settings.info.pricing_note')}</p>`;
        }

        // ── Inventory card ───────────────────────────────────────────────────
        const invEl = document.getElementById('info-inv-content');
        if (invEl && d.db) {
            const db = d.db;
            invEl.innerHTML = `
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    ${pill(db.inventory_active, t('settings.info.inv_active'))}
                    ${pill(db.products_total,   t('settings.info.inv_products'))}
                    ${pill(db.expiring_soon,     t('settings.info.inv_expiring'), db.expiring_soon > 0 ? '#d97706' : '')}
                    ${pill(db.expired,           t('settings.info.inv_expired'),  db.expired > 0 ? '#dc2626' : '')}
                    ${pill(db.finished,          t('settings.info.inv_finished'))}
                </div>`;
        }

        // ── Activity card ────────────────────────────────────────────────────
        const actEl = document.getElementById('info-act-content');
        if (actEl && d.db) {
            const db = d.db;
            actEl.innerHTML = `
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    ${pill(db.tx_month,       t('settings.info.act_tx_month'))}
                    ${pill(db.restock_month,  t('settings.info.act_restock'))}
                    ${pill(db.use_month,      t('settings.info.act_use'))}
                    ${pill(db.products_month, t('settings.info.act_new_products'))}
                    ${pill(db.tx_year,        t('settings.info.act_tx_year'))}
                </div>`;
        }

        // ── System card ──────────────────────────────────────────────────────
        if (sysEl) {
            const db = d.db || {};
            const lvlColors = {DEBUG:'#1e40af//#dbeafe', INFO:'#15803d//#dcfce7', WARN:'#854d0e//#fef9c3', ERROR:'#991b1b//#fee2e2'};
            const [lvlFg, lvlBg] = (lvlColors[d.log_level] || '#64748b//#f1f5f9').split('//');

            sysEl.innerHTML = `
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
                    ${pill(fmtBytes(db.bytes),    t('settings.info.db_size'))}
                    ${pill(fmtBytes(d.log_bytes), t('settings.info.log_size'))}
                    ${pill(`<span style="background:${lvlBg};color:${lvlFg};padding:2px 6px;border-radius:5px;font-size:0.78rem">${d.log_level||'INFO'}</span>`, t('settings.info.log_level'))}
                </div>
                <table style="border-collapse:collapse;width:100%;font-size:0.85rem">
                    <tr style="border-top:1px solid var(--border-color,#e2e8f0)">
                        <td style="padding:7px 0;color:var(--text-secondary)">${t('settings.info.price_cache')}</td>
                        <td style="padding:7px 0;font-weight:600;text-align:right">${(d.caches?.price||0)} ${t('settings.info.cache_entries')}</td>
                    </tr>
                    <tr style="border-top:1px solid var(--border-color,#e2e8f0)">
                        <td style="padding:7px 0;color:var(--text-secondary)">${t('settings.info.last_backup')}</td>
                        <td style="padding:7px 0;font-weight:600;text-align:right">${d.last_backup_ts ? fmtDate(d.last_backup_ts)+' · '+fmtBytes(d.last_backup_bytes) : '—'}</td>
                    </tr>
                </table>`;
        }
    } catch(e) {
        ['info-ai-content','info-inv-content','info-act-content','info-system-content'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.innerHTML = `<p class="settings-hint">${t('error.generic')}</p>`;
        });
    }
}


/**
 * Populate the About section with the current app version from the server.
 */
async function _loadAboutSection() {
    const el = document.getElementById('about-version-label');
    if (!el) return;
    try {
        const res = await api('check_update');
        const manifest = await fetch('manifest.json?_=' + Date.now()).then(r => r.json()).catch(() => ({}));
        const local = manifest.version || '—';
        const latest = res.latest_tag ? res.latest_tag.replace(/^v/, '') : null;
        el.textContent = 'v' + local + (latest && latest !== local ? ' → v' + latest + ' available' : '');
    } catch(e) {
        el.textContent = '—';
    }
}

/**
 * Manually triggered bug report from the About section in Settings.
 * Collects basic info and submits via the existing report_error endpoint.
 */
function reportBugManual() {
    const mc = document.getElementById('modal-content');
    if (!mc) return;

    mc.innerHTML = `
        <div class="modal-header">
            <h3>${t('about.report_bug_modal_title')}</h3>
            <button class="modal-close" onclick="closeModal()">✕</button>
        </div>
        <div style="padding:16px 20px 20px">
            <div style="margin-bottom:14px">
                <div class="bug-type-pills">
                    <button type="button" class="bug-type-pill active" data-btype="bug">🐛 ${t('about.report_type_bug')}</button>
                    <button type="button" class="bug-type-pill" data-btype="feature">💡 ${t('about.report_type_feature')}</button>
                    <button type="button" class="bug-type-pill" data-btype="question">❓ ${t('about.report_type_question')}</button>
                </div>
            </div>
            <div style="margin-bottom:12px">
                <label class="settings-label" style="display:block;margin-bottom:4px">${t('about.report_field_title')} *</label>
                <input type="text" id="bug-form-title" maxlength="150" autocomplete="off"
                    placeholder="${t('about.report_field_title_ph')}"
                    style="width:100%;box-sizing:border-box;padding:9px 11px;border:1.5px solid #cbd5e1;border-radius:8px;font-size:0.95rem;background:#fff;color:#1e293b;outline:none">
            </div>
            <div style="margin-bottom:12px">
                <label class="settings-label" style="display:block;margin-bottom:4px">${t('about.report_field_desc')} *</label>
                <textarea id="bug-form-desc" rows="4" maxlength="3000"
                    placeholder="${t('about.report_field_desc_ph')}"
                    style="width:100%;box-sizing:border-box;padding:9px 11px;border:1.5px solid #cbd5e1;border-radius:8px;font-size:0.95rem;resize:vertical;background:#fff;color:#1e293b;outline:none;font-family:inherit"></textarea>
            </div>
            <div id="bug-form-steps-group" style="margin-bottom:12px">
                <label class="settings-label" style="display:block;margin-bottom:4px">${t('about.report_field_steps')}</label>
                <textarea id="bug-form-steps" rows="3" maxlength="2000"
                    placeholder="${t('about.report_field_steps_ph')}"
                    style="width:100%;box-sizing:border-box;padding:9px 11px;border:1.5px solid #cbd5e1;border-radius:8px;font-size:0.95rem;resize:vertical;background:#fff;color:#1e293b;outline:none;font-family:inherit"></textarea>
            </div>
            <p class="settings-hint" style="margin:0 0 14px;font-size:0.78rem">
                ${t('about.report_auto_info').replace('{version}', _loadedVersion || '—').replace('{lang}', _currentLang || '—')}
            </p>
            <div style="display:flex;gap:8px;justify-content:flex-end">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">${t('btn.cancel')}</button>
                <button type="button" class="btn btn-primary" id="bug-form-submit" onclick="_submitBugReport()">
                    ${t('about.report_send_btn')}
                </button>
            </div>
            <div id="bug-form-status" style="display:none;margin-top:10px;text-align:center;font-size:0.88rem;padding:8px;border-radius:6px"></div>
        </div>
    `;
    document.getElementById('modal-overlay').style.display = 'flex';

    // Pill click: switch type, show/hide steps field
    mc.querySelectorAll('.bug-type-pill').forEach(pill => {
        pill.addEventListener('click', () => {
            mc.querySelectorAll('.bug-type-pill').forEach(p => p.classList.remove('active'));
            pill.classList.add('active');
            const stepsGroup = document.getElementById('bug-form-steps-group');
            if (stepsGroup) stepsGroup.style.display = (pill.dataset.btype === 'bug') ? '' : 'none';
        });
    });
}

async function _submitBugReport() {
    const submitBtn = document.getElementById('bug-form-submit');
    const statusEl  = document.getElementById('bug-form-status');
    const titleEl   = document.getElementById('bug-form-title');
    const descEl    = document.getElementById('bug-form-desc');
    const stepsEl   = document.getElementById('bug-form-steps');
    const activePill = document.querySelector('#modal-content .bug-type-pill.active');

    const title = titleEl?.value.trim() || '';
    const desc  = descEl?.value.trim()  || '';
    const steps = stepsEl?.value.trim() || '';
    const type  = activePill?.dataset.btype || 'bug';

    // Inline validation
    if (!title) {
        titleEl.style.borderColor = '#dc2626';
        titleEl.focus();
        return;
    }
    if (!desc) {
        descEl.style.borderColor = '#dc2626';
        descEl.focus();
        return;
    }

    submitBtn.disabled = true;
    statusEl.style.display = '';
    statusEl.style.background = '#f1f5f9';
    statusEl.style.color = '#64748b';
    statusEl.textContent = t('about.report_bug_sending');

    try {
        const res = await api('report_bug', null, 'POST', {
            type,
            title,
            description: desc,
            steps,
            user_agent: navigator.userAgent,
            url: location.href,
            version: _loadedVersion || '',
            lang: _currentLang || 'it',
        });

        if (res.ok) {
            statusEl.style.background = '#dcfce7';
            statusEl.style.color = '#15803d';
            const issueRef = res.issue ? ` (#${res.issue})` : '';
            statusEl.textContent = t('about.report_bug_sent') + issueRef;
            submitBtn.style.display = 'none';
            setTimeout(() => closeModal(), 3500);
        } else {
            throw new Error(res.error || 'error');
        }
    } catch(e) {
        statusEl.style.background = '#fee2e2';
        statusEl.style.color = '#dc2626';
        statusEl.textContent = t('about.report_bug_error');
        submitBtn.disabled = false;
    }
}

async function loadSettingsUI() {
    const s = getSettings();
    document.getElementById('setting-gemini-key').value = s.gemini_key || '';
    document.getElementById('setting-bring-email').value = s.bring_email || '';
    document.getElementById('setting-bring-password').value = s.bring_password || '';
    document.getElementById('setting-default-persons').value = s.default_persons || 1;
    document.getElementById('setting-pref-veloce').checked = !!s.pref_veloce;
    document.getElementById('setting-pref-pocafame').checked = !!s.pref_pocafame;
    document.getElementById('setting-pref-scadenze').checked = !!s.pref_scadenze;
    document.getElementById('setting-pref-healthy').checked = !!s.pref_healthy;
    document.getElementById('setting-pref-opened').checked = !!s.pref_opened;
    document.getElementById('setting-pref-zerowaste').checked = !!s.pref_zerowaste;
    const ssEl = document.getElementById('setting-screensaver-enabled');
    if (ssEl) ssEl.checked = s.screensaver_enabled === true;
    const ssTimeout = document.getElementById('setting-screensaver-timeout');
    if (ssTimeout) ssTimeout.value = String(s.screensaver_timeout || 5);
    document.getElementById('setting-dietary').value = s.dietary || '';
    // Camera
    const cameraSelect = document.getElementById('setting-camera-facing');
    if (cameraSelect) cameraSelect.value = s.camera_facing || 'environment';
    loadCameraDevices();
    renderAppliances(s.appliances || []);
    const mealPlanEnabled = s.meal_plan_enabled !== false;
    const mpEnabledEl = document.getElementById('setting-meal-plan-enabled');
    if (mpEnabledEl) mpEnabledEl.checked = mealPlanEnabled;
    const mpConfigSection = document.getElementById('meal-plan-config-section');
    if (mpConfigSection) mpConfigSection.style.display = mealPlanEnabled ? '' : 'none';
    const mpLegendCard = document.getElementById('meal-plan-legend-card');
    if (mpLegendCard) mpLegendCard.style.display = mealPlanEnabled ? '' : 'none';
    renderMealPlanEditor();
    // Render legend
    const legend = document.querySelector('.mplan-legend');
    if (legend) {
        legend.innerHTML = getMealPlanTypes().map(mpt =>
            `<span class="mplan-badge" style="opacity:0.85">${mpt.icon} ${mpt.label}</span>`
        ).join('');
    }
    // TTS settings — init defaults on first load
    if (!s._tts_initialized) {
        s.tts_url = s.tts_url || '';
        s.tts_token = s.tts_token || '';
        s.tts_payload_key = s.tts_payload_key || 'message';
        s.tts_method = s.tts_method || 'POST';
        s.tts_auth_type = s.tts_auth_type || 'bearer';
        s.tts_content_type = s.tts_content_type || 'application/json';
        s.tts_enabled = s.tts_enabled !== undefined ? s.tts_enabled : false;
        // Default engine: 'server' if a URL was already configured, else 'browser'
        if (!s.tts_engine) s.tts_engine = s.tts_url ? 'server' : 'browser';
        s.tts_voice = s.tts_voice || '';
        s.tts_rate = s.tts_rate !== undefined ? s.tts_rate : 1;
        s.tts_pitch = s.tts_pitch !== undefined ? s.tts_pitch : 1;
        s._tts_initialized = true;
        saveSettingsToStorage(s);
    }
    // HA settings — init defaults on first load
    if (!s._ha_initialized) {
        s.ha_enabled = s.ha_enabled || false;
        s.ha_url = s.ha_url || '';
        s.ha_tts_entity = s.ha_tts_entity || '';
        s.ha_webhook_id = s.ha_webhook_id || '';
        s.ha_webhook_events = s.ha_webhook_events || 'expiry,shopping_add,stock_update';
        s.ha_notify_service = s.ha_notify_service || '';
        s.ha_expiry_days = s.ha_expiry_days || 3;
        s._ha_initialized = true;
        saveSettingsToStorage(s);
    }
    const ttsEnabledEl = document.getElementById('setting-tts-enabled');
    if (ttsEnabledEl) ttsEnabledEl.checked = s.tts_enabled === true;
    const ttsEngineEl = document.getElementById('setting-tts-engine');
    if (ttsEngineEl) { ttsEngineEl.value = s.tts_engine || 'browser'; onTtsEngineChange(ttsEngineEl.value); }
    const ttsRateEl = document.getElementById('setting-tts-rate');
    if (ttsRateEl) { ttsRateEl.value = s.tts_rate || 1; document.getElementById('tts-rate-label').textContent = parseFloat(s.tts_rate || 1).toFixed(1); }
    const ttsPitchEl = document.getElementById('setting-tts-pitch');
    if (ttsPitchEl) { ttsPitchEl.value = s.tts_pitch || 1; document.getElementById('tts-pitch-label').textContent = parseFloat(s.tts_pitch || 1).toFixed(1); }
    _initBrowserTtsVoices(s.tts_voice || '');
    const ttsUrlEl = document.getElementById('setting-tts-url');
    if (ttsUrlEl) ttsUrlEl.value = s.tts_url || '';
    const ttsMethEl = document.getElementById('setting-tts-method');
    if (ttsMethEl) ttsMethEl.value = s.tts_method || 'POST';
    const ttsAuthTypeEl = document.getElementById('setting-tts-auth-type');
    if (ttsAuthTypeEl) { ttsAuthTypeEl.value = s.tts_auth_type || 'bearer'; onTtsAuthTypeChange(ttsAuthTypeEl.value); }
    const ttsTokenEl = document.getElementById('setting-tts-token');
    if (ttsTokenEl) ttsTokenEl.value = s.tts_token || '';
    const ttsAuthHdrNameEl = document.getElementById('setting-tts-auth-header-name');
    if (ttsAuthHdrNameEl) ttsAuthHdrNameEl.value = s.tts_auth_header_name || '';
    const ttsAuthHdrValEl = document.getElementById('setting-tts-auth-header-value');
    if (ttsAuthHdrValEl) ttsAuthHdrValEl.value = s.tts_auth_header_value || '';
    const ttsCtEl = document.getElementById('setting-tts-content-type');
    if (ttsCtEl) ttsCtEl.value = s.tts_content_type || 'application/json';
    const ttsPayloadKeyEl = document.getElementById('setting-tts-payload-key');
    if (ttsPayloadKeyEl) ttsPayloadKeyEl.value = s.tts_payload_key || 'message';
    const ttsExtraEl = document.getElementById('setting-tts-extra-fields');
    if (ttsExtraEl) ttsExtraEl.value = s.tts_extra_fields || '';
    
    // Load server-side settings as primary source
    try {
        const serverSettings = await api('get_settings');
        // Merge all server settings into local cache (server wins)
        const serverKeys = ['bring_email',
            'default_persons','pref_veloce','pref_pocafame','pref_scadenze',
            'pref_healthy','pref_opened','pref_zerowaste','dietary','appliances',
            'camera_facing','scale_enabled','scale_gateway_url',
            'meal_plan_enabled',
            'tts_enabled','tts_url','tts_token','tts_method','tts_auth_type',
            'tts_content_type','tts_payload_key',
            'price_enabled','price_country','price_currency','price_update_months',
            'shopping_enabled','shopping_mode','shopping_smart_suggestions',
            'shopping_forecast','shopping_auto_add_threshold',
            'ha_enabled','ha_url','ha_tts_entity','ha_webhook_id','ha_webhook_events',
            'ha_notify_service','ha_expiry_days'];
        // Note: gemini_key is never sent from server; settings_token_set is metadata only
        const settingsTokenRequired = !!(serverSettings.api_token_required || serverSettings.settings_token_set);
        const tokenHintEl = document.getElementById('settings-token-status-hint');
        if (tokenHintEl) tokenHintEl.style.display = settingsTokenRequired ? 'block' : 'none';
        if (settingsTokenRequired && typeof setApiToken === 'function') {
            const fieldTok = document.getElementById('setting-settings-token')?.value.trim();
            if (fieldTok) setApiToken(fieldTok);
        }
        let changed = false;
        for (const key of serverKeys) {
            if (serverSettings[key] !== undefined && serverSettings[key] !== null && serverSettings[key] !== '') {
                s[key] = serverSettings[key];
                changed = true;
            }
        }
        if (changed) {
            _settingsCache = s;
            // Re-populate UI with merged values
            document.getElementById('setting-gemini-key').value = s.gemini_key || '';
            document.getElementById('setting-bring-email').value = s.bring_email || '';
            document.getElementById('setting-bring-password').value = s.bring_password || '';
            document.getElementById('setting-default-persons').value = s.default_persons || 1;
            document.getElementById('setting-pref-veloce').checked = !!s.pref_veloce;
            document.getElementById('setting-pref-pocafame').checked = !!s.pref_pocafame;
            document.getElementById('setting-pref-scadenze').checked = !!s.pref_scadenze;
            document.getElementById('setting-pref-healthy').checked = !!s.pref_healthy;
            document.getElementById('setting-pref-opened').checked = !!s.pref_opened;
            document.getElementById('setting-pref-zerowaste').checked = !!s.pref_zerowaste;
            document.getElementById('setting-dietary').value = s.dietary || '';
            if (cameraSelect) cameraSelect.value = s.camera_facing || 'environment';
            renderAppliances(s.appliances || []);
            if (ttsEnabledEl) ttsEnabledEl.checked = s.tts_enabled === true;
            if (ttsUrlEl) ttsUrlEl.value = s.tts_url || '';
            if (ttsTokenEl) ttsTokenEl.value = s.tts_token || '';
            if (ttsMethEl) ttsMethEl.value = s.tts_method || 'POST';
            if (ttsAuthTypeEl) ttsAuthTypeEl.value = s.tts_auth_type || 'bearer';
            if (ttsCtEl) ttsCtEl.value = s.tts_content_type || 'application/json';
            if (ttsPayloadKeyEl) ttsPayloadKeyEl.value = s.tts_payload_key || 'message';
            if (scaleEnabledUiEl) scaleEnabledUiEl.checked = !!s.scale_enabled;
            if (scaleUrlUiEl) scaleUrlUiEl.value = s.scale_gateway_url || '';
            const mpEnabledUp = s.meal_plan_enabled !== false;
            if (mpEnabledEl) mpEnabledEl.checked = mpEnabledUp;
            if (mpConfigSection) mpConfigSection.style.display = mpEnabledUp ? '' : 'none';
            if (mpLegendCard) mpLegendCard.style.display = mpEnabledUp ? '' : 'none';
            // Price settings (server merge)
            if (priceEnabledEl) {
                priceEnabledEl.checked = !!s.price_enabled;
                const pSub = document.getElementById('price-settings-sub');
                if (pSub) pSub.style.display = s.price_enabled ? '' : 'none';
            }
            if (priceCountryEl) priceCountryEl.value = s.price_country || 'Italia';
            if (priceCurrencyEl) priceCurrencyEl.value = s.price_currency || 'EUR';
            if (priceMonthsEl) priceMonthsEl.value = s.price_update_months || 3;
            // Shopping settings (server merge)
            _applyShoppingSettingsUI(s);
            // HA settings (server merge)
            _applyHaSettingsUI(s);
        }
    } catch(e) { /* offline, use local */ }
    // Price settings
    const priceEnabledEl = document.getElementById('setting-price-enabled');
    if (priceEnabledEl) {
        priceEnabledEl.checked = !!s.price_enabled;
        const priceSubEl = document.getElementById('price-settings-sub');
        if (priceSubEl) priceSubEl.style.display = s.price_enabled ? '' : 'none';
        priceEnabledEl.onchange = function() {
            const sub = document.getElementById('price-settings-sub');
            if (sub) sub.style.display = this.checked ? '' : 'none';
        };
    }
    const priceCountryEl = document.getElementById('setting-price-country');
    if (priceCountryEl) priceCountryEl.value = s.price_country || 'Italia';
    const priceCurrencyEl = document.getElementById('setting-price-currency');
    if (priceCurrencyEl) priceCurrencyEl.value = s.price_currency || 'EUR';
    const priceMonthsEl = document.getElementById('setting-price-update-months');
    if (priceMonthsEl) priceMonthsEl.value = s.price_update_months || 3;
    // Scale settings
    const scaleEnabledUiEl = document.getElementById('setting-scale-enabled');
    if (scaleEnabledUiEl) scaleEnabledUiEl.checked = !!s.scale_enabled;
    const scaleUrlUiEl = document.getElementById('setting-scale-url');
    if (scaleUrlUiEl) scaleUrlUiEl.value = s.scale_gateway_url || '';
    // Backup settings pre-fill (populated fully when _loadBackupTab() is called)
    const bkRetEl = document.getElementById('setting-backup-retention-days');
    if (bkRetEl && !bkRetEl.dataset.loaded) bkRetEl.value = s.backup_retention_days || 3;
    const gdriveEnUiEl = document.getElementById('setting-gdrive-enabled');
    if (gdriveEnUiEl) gdriveEnUiEl.checked = !!s.gdrive_enabled;
    const gdriveFolderUiEl = document.getElementById('setting-gdrive-folder-id');
    if (gdriveFolderUiEl && !gdriveFolderUiEl.dataset.loaded) gdriveFolderUiEl.value = s.gdrive_folder_id || '';
    const gdriveRetUiEl = document.getElementById('setting-gdrive-retention-days');
    if (gdriveRetUiEl && !gdriveRetUiEl.dataset.loaded) gdriveRetUiEl.value = s.gdrive_retention_days || 30;
    // Shopping settings
    _applyShoppingSettingsUI(s);
    // Hide kiosk download banner if running inside Android WebView (kiosk mode)
    const kioskBanner = document.getElementById('kiosk-download-banner');
    if (kioskBanner && /; wv\)/.test(navigator.userAgent)) {
        kioskBanner.style.display = 'none';
    }
    // In kiosk mode: replace WebSocket scale config with native BLE reconfigure panel
    const isKiosk = typeof _kioskBridge !== 'undefined';
    const scaleGwDl   = document.getElementById('scale-gateway-download-section');
    const scaleWsEl   = document.getElementById('scale-websocket-section');
    const scaleTestEl = document.getElementById('scale-test-section');
    const scaleKiosk  = document.getElementById('scale-kiosk-panel');
    if (isKiosk) {
        if (scaleGwDl)   scaleGwDl.style.display   = 'none';
        if (scaleWsEl)   scaleWsEl.style.display    = 'none';
        if (scaleTestEl) scaleTestEl.style.display  = 'none';
        if (scaleKiosk)  scaleKiosk.style.display   = '';
        // Auto-set URL to localhost gateway (always port 8765 in kiosk)
        if (scaleUrlUiEl && !scaleUrlUiEl.value) scaleUrlUiEl.value = 'ws://localhost:8765';
        // Show kiosk self-update panel
        const updatePanel = document.getElementById('kiosk-update-panel');
        if (updatePanel) updatePanel.style.display = '';
        // Show kiosk native settings shortcut panel
        const nativePanel = document.getElementById('kiosk-native-settings-panel');
        if (nativePanel) nativePanel.style.display = '';
    }

    // Dark mode setting
    const dmEl = document.getElementById('setting-dark-mode');
    if (dmEl) dmEl.value = s.dark_mode || 'auto';
    // Zero-waste tips setting
    const zwEl = document.getElementById('setting-zerowaste-tips');
    if (zwEl) zwEl.checked = s.zerowaste_tips_enabled === true;

    // Populate About section version
    _loadAboutSection();
}

// ── Kiosk: trigger native BLE scale reconfiguration wizard ────────────
function _kioskReconfigureScale() {
    if (typeof _kioskBridge === 'undefined') return;
    if (typeof _kioskBridge.reconfigureScale === 'function') {
        try { _kioskBridge.reconfigureScale(); } catch(e) {}
    } else {
        // Kiosk APK is outdated — show update notice
        const notice = document.getElementById('kiosk-needs-update-notice');
        if (notice) notice.style.display = '';
        showToast(t('settings.kiosk_update_required'), 'warning');
    }
}

// ── Kiosk: open native SettingsActivity (server URL, BLE, screensaver) ──
function _openKioskNativeSettings() {
    if (typeof _kioskBridge === 'undefined') return;
    // Use try/catch directly: Android @JavascriptInterface methods are not always
    // detected as 'function' by typeof, so we just call and catch if unavailable.
    try {
        _kioskBridge.openNativeSettings();
    } catch(e) {
        // Older APK without openNativeSettings bridge — inform user to update
        showToast(t('settings.kiosk.native_update_hint'), 'warning', 4000);
    }
}

// ── Kiosk: manual update check ────────────────────────────────────────
let _kioskPendingApkUrl = '';

/** Called by Kotlin with JSON: { has_update, current, latest, apk_url, error } */
window._kioskUpdateResult = function(result) {
    const btn    = document.getElementById('btn-kiosk-check-update');
    const status = document.getElementById('kiosk-update-status');
    const installBtn = document.getElementById('btn-kiosk-install-update');
    const verLabel   = document.getElementById('kiosk-update-version-label');
    if (!status) return;

    if (btn) { btn.disabled = false; btn.textContent = t('kiosk.check_btn'); }

    if (result.error && !result.has_update) {
        status.style.display = '';
        status.style.background = 'rgba(239,68,68,0.1)';
        status.style.border = '1px solid rgba(239,68,68,0.3)';
        status.style.color = '';
        status.innerHTML = `❌ ${t('error.prefix')}: ${result.error}`;
        return;
    }

    const current = result.current || '?';
    const latest  = result.latest  || '?';
    if (verLabel) verLabel.textContent = t('kiosk.version_installed').replace('{v}', current);

    if (result.has_update) {
        _kioskPendingApkUrl = result.apk_url || '';
        status.style.display = '';
        status.style.background = 'rgba(245,158,11,0.1)';
        status.style.border = '1px solid rgba(245,158,11,0.35)';
        status.style.color = '';
        status.innerHTML = t('kiosk.update_available').replace('{latest}', latest).replace('{current}', current);
        if (installBtn) installBtn.style.display = '';
    } else {
        _kioskPendingApkUrl = '';
        status.style.display = '';
        status.style.background = 'rgba(52,211,153,0.1)';
        status.style.border = '1px solid rgba(52,211,153,0.3)';
        status.style.color = '';
        status.innerHTML = t('kiosk.up_to_date').replace('{v}', current);
        if (installBtn) installBtn.style.display = 'none';
    }
};

function _kioskCheckForUpdates() {
    if (typeof _kioskBridge === 'undefined' || typeof _kioskBridge.checkForUpdates !== 'function') {
        // Kiosk is present but old — trigger download via installUpdate which exists since v1.3
        const status = document.getElementById('kiosk-update-status');
        const installBtn = document.getElementById('btn-kiosk-install-update');
        if (status) {
            status.style.display = '';
            status.style.background = 'rgba(245,158,11,0.1)';
            status.style.border = '1px solid rgba(245,158,11,0.35)';
            status.innerHTML = t('kiosk.too_old');
        }
        // Pre-set the pending URL and show the install button (installUpdate works in old APKs too)
        _kioskPendingApkUrl = 'https://github.com/dadaloop82/EverShelf/releases/download/kiosk-latest/evershelf-kiosk.apk';
        if (installBtn) installBtn.style.display = '';
        return;
    }
    const btn    = document.getElementById('btn-kiosk-check-update');
    const status = document.getElementById('kiosk-update-status');
    const installBtn = document.getElementById('btn-kiosk-install-update');
    if (btn)        { btn.disabled = true; btn.textContent = t('kiosk.checking'); }
    if (status)     { status.style.display = 'none'; }
    if (installBtn) { installBtn.style.display = 'none'; }
    _kioskPendingApkUrl = '';
    try { _kioskBridge.checkForUpdates(); } catch(e) {
        if (btn) { btn.disabled = false; btn.textContent = t('kiosk.check_btn'); }
        showToast('❌ ' + t('kiosk.error_check'), 'error');
    }
}

function _kioskInstallUpdate() {
    if (!_kioskPendingApkUrl) return;
    if (typeof _kioskBridge === 'undefined') return;
    if (typeof _kioskBridge.installUpdate !== 'function') {
        // Old APK without installUpdate — show instructions
        const status = document.getElementById('kiosk-update-status');
        if (status) {
            status.style.display = '';
            status.style.background = 'rgba(239,68,68,0.1)';
            status.style.border = '1px solid rgba(239,68,68,0.3)';
            status.innerHTML = t('kiosk.manual_install') +
                `<br><code style="font-size:0.75rem;word-break:break-all">
https://github.com/dadaloop82/EverShelf/releases/download/kiosk-latest/evershelf-kiosk.apk
                </code>`;
        }
        return;
    }
    const installBtn = document.getElementById('btn-kiosk-install-update');
    if (installBtn) { installBtn.disabled = true; installBtn.textContent = t('kiosk.starting_download'); }
    try { _kioskBridge.installUpdate(_kioskPendingApkUrl); } catch(e) {
        if (installBtn) { installBtn.disabled = false; installBtn.textContent = t('kiosk.install_btn'); }
        showToast('❌ ' + t('kiosk.error_start_install'), 'error');
    }
}

// ── Kiosk overlay: X (close) + ↻ (refresh) buttons ───────────────────
// Injected into #header-left (left zone of the 3-column header).
// Only shown when _kioskBridge JS interface is available (Android WebView).
function _injectKioskOverlay() {
    if (typeof _kioskBridge === 'undefined') return;

    // Always mark header as kiosk-mode (idempotent).
    const appHeader = document.querySelector('.app-header');
    if (appHeader) appHeader.classList.add('kiosk-mode');

    // Permanently hide the native Android settings button.
    // Kiosk configuration is accessible ONLY through the web settings page (⚙️ below).
    try { _kioskBridge.setNativeSettingsVisible(false); } catch (_) {}

    const btnStyle = 'background:rgba(255,255,255,0.2);border:none;color:#fff;width:34px;height:34px;border-radius:50%;font-size:15px;cursor:pointer;display:flex;align-items:center;justify-content:center;-webkit-tap-highlight-color:transparent;touch-action:manipulation;';

    // If the Kotlin onPageFinished already injected #_kiosk_overlay (with ✕ and ↻),
    // just add the ⚙️ button if missing (do not duplicate the other buttons).
    const existing = document.getElementById('_kiosk_overlay');
    if (existing) {
        if (!document.getElementById('_kiosk_settings_btn')) {
            const sBtn = document.createElement('button');
            sBtn.id = '_kiosk_settings_btn';
            sBtn.textContent = '⚙️';
            sBtn.title = t('settings.title') || 'Impostazioni';
            sBtn.style.cssText = btnStyle;
            sBtn.addEventListener('click', (e) => { e.stopPropagation(); showPage('settings'); });
            existing.appendChild(sBtn);
        }
        return;
    }

    const headerLeft = document.getElementById('header-left');
    if (!headerLeft) return;

    const wrap = document.createElement('div');
    wrap.id = '_kiosk_overlay';
    wrap.style.cssText = 'display:flex;gap:6px;align-items:center;';

    // Exit button
    const exitBtn = document.createElement('button');
    exitBtn.id = '_kiosk_exit_btn';
    exitBtn.textContent = '\u2715';
    exitBtn.title = t('kiosk.exit_title');
    exitBtn.style.cssText = btnStyle;
    exitBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        if (confirm(t('confirm.kiosk_exit'))) _kioskBridge.exit();
    });

    // Refresh button
    const refBtn = document.createElement('button');
    refBtn.id = '_kiosk_refresh_btn';
    refBtn.textContent = '\u21bb';
    refBtn.title = t('kiosk.refresh_title');
    refBtn.style.cssText = btnStyle.replace('font-size:15px', 'font-size:18px');
    refBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        _kioskBridge.hardReload();
    });

    // Settings button — only web settings, native button is permanently hidden
    const settingsBtn = document.createElement('button');
    settingsBtn.id = '_kiosk_settings_btn';
    settingsBtn.textContent = '⚙️';
    settingsBtn.title = t('settings.title') || 'Impostazioni';
    settingsBtn.style.cssText = btnStyle;
    settingsBtn.addEventListener('click', (e) => { e.stopPropagation(); showPage('settings'); });

    wrap.appendChild(exitBtn);
    wrap.appendChild(refBtn);
    wrap.appendChild(settingsBtn);
    headerLeft.appendChild(wrap);
}

const _APPLIANCE_KEY_MAP = {
    'forno': 'settings.appliances.oven',
    'oven': 'settings.appliances.oven',
    'backofen': 'settings.appliances.oven',
    'microonde': 'settings.appliances.microwave',
    'microwave': 'settings.appliances.microwave',
    'mikrowelle': 'settings.appliances.microwave',
    'friggitrice ad aria': 'settings.appliances.air_fryer',
    'air fryer': 'settings.appliances.air_fryer',
    'heißluftfritteuse': 'settings.appliances.air_fryer',
    'macchina del pane': 'settings.appliances.bread_maker',
    'macchina pane': 'settings.appliances.bread_maker',
    'bread maker': 'settings.appliances.bread_maker',
    'bread machine': 'settings.appliances.bread_maker',
    'brotbackmaschine': 'settings.appliances.bread_maker',
    'brotbackautomat': 'settings.appliances.bread_maker',
    'bimby/moulinex cookeo': 'settings.appliances.bimby',
    'moulinex cookeo': 'settings.appliances.bimby',
    'bimby/cookeo': 'settings.appliances.bimby',
    'bimby': 'settings.appliances.bimby',
    'thermomix': 'settings.appliances.bimby',
    'thermomix/cookeo': 'settings.appliances.bimby',
    'planetaria': 'settings.appliances.mixer',
    'stand mixer': 'settings.appliances.mixer',
    'küchenmaschine': 'settings.appliances.mixer',
    'vaporiera': 'settings.appliances.steamer',
    'steamer': 'settings.appliances.steamer',
    'dampfgarer': 'settings.appliances.steamer',
    'pentola a pressione': 'settings.appliances.pressure_cooker',
    'pentola pressione': 'settings.appliances.pressure_cooker',
    'pressure cooker': 'settings.appliances.pressure_cooker',
    'schnellkochtopf': 'settings.appliances.pressure_cooker',
    'tostapane': 'settings.appliances.toaster',
    'toaster': 'settings.appliances.toaster',
    'frullatore/mixer': 'settings.appliances.blender',
    'frullatore': 'settings.appliances.blender',
    'blender': 'settings.appliances.blender',
    'mixer': 'settings.appliances.blender',
};

function _applianceDisplayName(name) {
    const key = _APPLIANCE_KEY_MAP[name.toLowerCase().trim()];
    if (!key) return name;
    // Strip leading emoji/symbols from the translated button label (e.g. "🔥 Oven" → "Oven")
    return t(key).replace(/^[^\p{L}]+/u, '').trim() || name;
}

function renderAppliances(appliances) {
    const container = document.getElementById('appliances-list');
    if (!appliances || appliances.length === 0) {
        container.innerHTML = `<p style="color:var(--text-muted);font-size:0.85rem;padding:8px 0">${t('appliances.empty')}</p>`;
        return;
    }
    container.innerHTML = appliances.map((a, i) => `
        <div class="appliance-item">
            <span>🔌 ${escapeHtml(_applianceDisplayName(a))}</span>
            <button class="appliance-remove" onclick="removeAppliance(${i})" title="${t('btn.delete')}">✕</button>
        </div>
    `).join('');
}

async function loadCameraDevices() {
    const select = document.getElementById('setting-camera-facing');
    if (!select) return;
    const s = getSettings();
    const current = s.camera_facing || 'environment';
    // Remove old device-specific options (keep first 2: environment, user)
    while (select.options.length > 2) select.remove(2);
    const cameras = await enumerateCameras();
    cameras.forEach(cam => {
        const opt = document.createElement('option');
        opt.value = cam.deviceId;
        opt.textContent = cam.label || `Camera ${cam.deviceId.slice(0, 8)}…`;
        select.appendChild(opt);
    });
    select.value = current;
}

function addAppliance() {
    const input = document.getElementById('new-appliance-input');
    const name = (input.value || '').trim();
    if (!name) return;
    const s = getSettings();
    if (!s.appliances) s.appliances = [];
    if (s.appliances.some(a => a.toLowerCase() === name.toLowerCase())) {
        showToast(t('error.appliance_exists'), 'error');
        return;
    }
    s.appliances.push(name);
    saveSettingsToStorage(s);
    renderAppliances(s.appliances);
    input.value = '';
    showToast(t('toast.appliance_added'), 'success');
}

function addApplianceQuick(name) {
    const s = getSettings();
    if (!s.appliances) s.appliances = [];
    if (s.appliances.some(a => a.toLowerCase() === name.toLowerCase())) {
        showToast(t('error.already_exists'), 'error');
        return;
    }
    s.appliances.push(name);
    saveSettingsToStorage(s);
    renderAppliances(s.appliances);
    showToast(t('toast.appliance_added'), 'success');
}

function removeAppliance(idx) {
    const s = getSettings();
    if (!s.appliances) return;
    s.appliances.splice(idx, 1);
    saveSettingsToStorage(s);
    renderAppliances(s.appliances);
}

function _applyShoppingSettingsUI(s) {
    const enabledEl = document.getElementById('setting-shopping-enabled');
    if (enabledEl) enabledEl.checked = s.shopping_enabled !== false;
    const mode = s.shopping_mode || 'internal';
    document.querySelectorAll('input[name="shopping-mode"]').forEach(r => { r.checked = (r.value === mode); });
    const bringSection = document.getElementById('bring-subsection');
    if (bringSection) bringSection.style.display = mode === 'bring' ? '' : 'none';
    const suggestEl = document.getElementById('setting-shopping-smart-suggestions');
    if (suggestEl) suggestEl.checked = s.shopping_smart_suggestions !== false;
    const forecastEl = document.getElementById('setting-shopping-forecast');
    if (forecastEl) forecastEl.checked = s.shopping_forecast !== false;
    const autoAddEl = document.getElementById('setting-shopping-auto-add');
    if (autoAddEl) autoAddEl.value = s.shopping_auto_add_threshold || 0;
}

function onShoppingEnabledChange() {
    const s = getSettings();
    s.shopping_enabled = document.getElementById('setting-shopping-enabled').checked;
    saveSettingsToStorage(s);
    _saveSettingToServer({ shopping_enabled: s.shopping_enabled });
}

function onShoppingModeChange(value) {
    const bringSection = document.getElementById('bring-subsection');
    if (bringSection) bringSection.style.display = value === 'bring' ? '' : 'none';
    const s = getSettings();
    s.shopping_mode = value;
    saveSettingsToStorage(s);
    _saveSettingToServer({ shopping_mode: value }).then((ok) => {
        if (ok) showToast(value === 'bring' ? 'Bring! attivato' : 'Lista spesa interna attivata', 'success');
    });
}

async function saveSettings() {
    const s = getSettings();
    // Only update gemini_key if user actually typed something; preserve existing key otherwise
    const _newGeminiKey = document.getElementById('setting-gemini-key').value.trim();
    if (_newGeminiKey) s.gemini_key = _newGeminiKey;
    s.bring_email = document.getElementById('setting-bring-email').value.trim();
    s.bring_password = document.getElementById('setting-bring-password').value.trim();
    s.default_persons = parseInt(document.getElementById('setting-default-persons').value) || 1;
    s.pref_veloce = document.getElementById('setting-pref-veloce').checked;
    s.pref_pocafame = document.getElementById('setting-pref-pocafame').checked;
    s.pref_scadenze = document.getElementById('setting-pref-scadenze').checked;
    s.pref_healthy = document.getElementById('setting-pref-healthy').checked;
    s.pref_opened = document.getElementById('setting-pref-opened').checked;
    s.pref_zerowaste = document.getElementById('setting-pref-zerowaste').checked;
    s.dietary = document.getElementById('setting-dietary').value.trim();
    // Camera
    s.camera_facing = document.getElementById('setting-camera-facing').value;
    // Screensaver
    const ssEl = document.getElementById('setting-screensaver-enabled');
    if (ssEl) s.screensaver_enabled = ssEl.checked;
    const ssTimeoutEl = document.getElementById('setting-screensaver-timeout');
    if (ssTimeoutEl) s.screensaver_timeout = parseInt(ssTimeoutEl.value, 10) || 5;
    // Dark mode
    const dmSaveEl = document.getElementById('setting-dark-mode');
    if (dmSaveEl) { s.dark_mode = dmSaveEl.value; _applyTheme(); }
    // Zero-waste tips
    const zwSaveEl = document.getElementById('setting-zerowaste-tips');
    if (zwSaveEl) s.zerowaste_tips_enabled = zwSaveEl.checked;
    // Meal plan enabled toggle
    const mpEnabledEl = document.getElementById('setting-meal-plan-enabled');
    if (mpEnabledEl) s.meal_plan_enabled = mpEnabledEl.checked;
    // TTS settings
    const ttsEnabledEl = document.getElementById('setting-tts-enabled');
    if (ttsEnabledEl) s.tts_enabled = ttsEnabledEl.checked;
    const ttsUrlEl2 = document.getElementById('setting-tts-url');
    if (ttsUrlEl2) s.tts_url = ttsUrlEl2.value.trim();
    const ttsEngineEl2 = document.getElementById('setting-tts-engine');
    if (ttsEngineEl2) s.tts_engine = ttsEngineEl2.value;
    const ttsVoiceEl2 = document.getElementById('setting-tts-voice');
    if (ttsVoiceEl2) s.tts_voice = ttsVoiceEl2.value;
    const ttsRateEl2 = document.getElementById('setting-tts-rate');
    if (ttsRateEl2) s.tts_rate = parseFloat(ttsRateEl2.value) || 1;
    const ttsPitchEl2 = document.getElementById('setting-tts-pitch');
    if (ttsPitchEl2) s.tts_pitch = parseFloat(ttsPitchEl2.value) || 1;
    const ttsMethEl2 = document.getElementById('setting-tts-method');
    if (ttsMethEl2) s.tts_method = ttsMethEl2.value;
    const ttsAuthTypeEl2 = document.getElementById('setting-tts-auth-type');
    if (ttsAuthTypeEl2) s.tts_auth_type = ttsAuthTypeEl2.value;
    const ttsTokenEl2 = document.getElementById('setting-tts-token');
    if (ttsTokenEl2) s.tts_token = ttsTokenEl2.value.trim();
    const ttsAuthHdrNameEl2 = document.getElementById('setting-tts-auth-header-name');
    if (ttsAuthHdrNameEl2) s.tts_auth_header_name = ttsAuthHdrNameEl2.value.trim();
    const ttsAuthHdrValEl2 = document.getElementById('setting-tts-auth-header-value');
    if (ttsAuthHdrValEl2) s.tts_auth_header_value = ttsAuthHdrValEl2.value.trim();
    const ttsCtEl2 = document.getElementById('setting-tts-content-type');
    if (ttsCtEl2) s.tts_content_type = ttsCtEl2.value;
    const ttsPayloadKeyEl2 = document.getElementById('setting-tts-payload-key');
    if (ttsPayloadKeyEl2) s.tts_payload_key = ttsPayloadKeyEl2.value.trim() || 'message';
    const ttsExtraEl2 = document.getElementById('setting-tts-extra-fields');
    if (ttsExtraEl2) s.tts_extra_fields = ttsExtraEl2.value.trim();
    // Scale settings
    const scaleEnabledEl = document.getElementById('setting-scale-enabled');
    if (scaleEnabledEl) s.scale_enabled = scaleEnabledEl.checked;
    const scaleUrlEl = document.getElementById('setting-scale-url');
    if (scaleUrlEl) s.scale_gateway_url = scaleUrlEl.value.trim();
    // Price settings
    const priceEnabledSaveEl = document.getElementById('setting-price-enabled');
    if (priceEnabledSaveEl) s.price_enabled = priceEnabledSaveEl.checked;
    const priceCountrySaveEl = document.getElementById('setting-price-country');
    if (priceCountrySaveEl) s.price_country = priceCountrySaveEl.value;
    const priceCurrencySaveEl = document.getElementById('setting-price-currency');
    if (priceCurrencySaveEl) s.price_currency = priceCurrencySaveEl.value;
    const priceMonthsSaveEl = document.getElementById('setting-price-update-months');
    if (priceMonthsSaveEl) s.price_update_months = parseInt(priceMonthsSaveEl.value, 10) || 3;
    // Backup settings
    const backupEnabledEl = document.getElementById('setting-backup-enabled');
    if (backupEnabledEl) s.backup_enabled = backupEnabledEl.checked;
    const backupRetentionEl = document.getElementById('setting-backup-retention-days');
    if (backupRetentionEl) s.backup_retention_days = parseInt(backupRetentionEl.value, 10) || 3;
    const gdriveEnabledEl = document.getElementById('setting-gdrive-enabled');
    if (gdriveEnabledEl) s.gdrive_enabled = gdriveEnabledEl.checked;
    const gdriveFolderEl = document.getElementById('setting-gdrive-folder-id');
    if (gdriveFolderEl) s.gdrive_folder_id = gdriveFolderEl.value.trim();
    const gdriveRetentionEl = document.getElementById('setting-gdrive-retention-days');
    if (gdriveRetentionEl) s.gdrive_retention_days = parseInt(gdriveRetentionEl.value, 10) || 30;
    // Shopping settings
    const shoppingEnabledEl = document.getElementById('setting-shopping-enabled');
    if (shoppingEnabledEl) s.shopping_enabled = shoppingEnabledEl.checked;
    const shoppingModeEl = document.querySelector('input[name="shopping-mode"]:checked');
    if (shoppingModeEl) s.shopping_mode = shoppingModeEl.value;
    const shoppingSuggestEl = document.getElementById('setting-shopping-smart-suggestions');
    if (shoppingSuggestEl) s.shopping_smart_suggestions = shoppingSuggestEl.checked;
    const shoppingForecastEl = document.getElementById('setting-shopping-forecast');
    if (shoppingForecastEl) s.shopping_forecast = shoppingForecastEl.checked;
    const shoppingAutoAddEl = document.getElementById('setting-shopping-auto-add');
    if (shoppingAutoAddEl) s.shopping_auto_add_threshold = parseInt(shoppingAutoAddEl.value, 10) || 0;
    // OAuth fields
    const gdriveClientIdEl = document.getElementById('setting-gdrive-client-id');
    if (gdriveClientIdEl && gdriveClientIdEl.value.trim()) s.gdrive_client_id = gdriveClientIdEl.value.trim();
    const gdriveClientSecretEl = document.getElementById('setting-gdrive-client-secret');
    if (gdriveClientSecretEl && gdriveClientSecretEl.value.trim()) s.gdrive_client_secret = gdriveClientSecretEl.value.trim();
    saveSettingsToStorage(s);
    
    // Save ALL settings to server .env
    try {
        const settingsToken = document.getElementById('setting-settings-token')?.value.trim() || (typeof getApiToken === 'function' ? getApiToken() : '');
        if (settingsToken && typeof setApiToken === 'function') setApiToken(settingsToken);
        const tokenHeader = settingsToken ? { 'X-API-Token': settingsToken } : (typeof apiAuthHeaders === 'function' ? apiAuthHeaders() : {});
        const result = await api('save_settings', {}, 'POST', {
            ...(s.gemini_key ? { gemini_key: s.gemini_key } : {}),
            bring_email: s.bring_email,
            ...(s.bring_password ? { bring_password: s.bring_password } : {}),
            default_persons: s.default_persons,
            pref_veloce: s.pref_veloce,
            pref_pocafame: s.pref_pocafame,
            pref_scadenze: s.pref_scadenze,
            pref_healthy: s.pref_healthy,
            pref_opened: s.pref_opened,
            pref_zerowaste: s.pref_zerowaste,
            dietary: s.dietary,
            appliances: s.appliances,
            camera_facing: s.camera_facing,
            scale_enabled: s.scale_enabled,
            scale_gateway_url: s.scale_gateway_url,
            meal_plan_enabled: s.meal_plan_enabled,
            screensaver_enabled: s.screensaver_enabled,
            screensaver_timeout: s.screensaver_timeout || 5,
            zerowaste_tips_enabled: s.zerowaste_tips_enabled,
            tts_enabled: s.tts_enabled,
            tts_url: s.tts_url,
            tts_token: s.tts_token,
            tts_method: s.tts_method,
            tts_auth_type: s.tts_auth_type,
            tts_content_type: s.tts_content_type,
            tts_payload_key: s.tts_payload_key,
            tts_engine: s.tts_engine || '',
            tts_rate: s.tts_rate || 1,
            tts_pitch: s.tts_pitch || 1,
            tts_auth_header_name: s.tts_auth_header_name || '',
            tts_auth_header_value: s.tts_auth_header_value || '',
            tts_extra_fields: s.tts_extra_fields || '',
            price_enabled: s.price_enabled,
            price_country: s.price_country,
            price_currency: s.price_currency,
            price_update_months: s.price_update_months,
            recipe_retention_days: s.recipe_retention_days || 7,
            transaction_retention_days: s.transaction_retention_days || 90,
            vacuum_expiry_extension_days: s.vacuum_expiry_extension_days || 30,
            backup_enabled: s.backup_enabled !== false,
            backup_retention_days: s.backup_retention_days || 3,
            gdrive_enabled: !!s.gdrive_enabled,
            gdrive_folder_id: s.gdrive_folder_id || '',
            gdrive_retention_days: s.gdrive_retention_days || 30,
            ...(s.gdrive_client_id     ? { gdrive_client_id:     s.gdrive_client_id }     : {}),
            ...(s.gdrive_client_secret ? { gdrive_client_secret: s.gdrive_client_secret } : {}),
            shopping_enabled:            s.shopping_enabled !== false,
            shopping_mode:               s.shopping_mode || 'internal',
            shopping_smart_suggestions:  s.shopping_smart_suggestions !== false,
            shopping_forecast:           s.shopping_forecast !== false,
            shopping_auto_add_threshold: s.shopping_auto_add_threshold || 0,
            dark_mode:                   s.dark_mode || 'auto',
            barcode_ai_fallback:          !!s.barcode_ai_fallback,
            // Home Assistant
            ha_enabled:         !!s.ha_enabled,
            ha_url:             s.ha_url || '',
            ...(s.ha_token ? { ha_token: s.ha_token } : {}),
            ha_tts_entity:      s.ha_tts_entity || '',
            ha_webhook_id:      s.ha_webhook_id || '',
            ha_webhook_events:  s.ha_webhook_events || '',
            ha_notify_service:  s.ha_notify_service || '',
            ha_expiry_days:     s.ha_expiry_days || 3,
        }, tokenHeader);
        const statusEl = document.getElementById('settings-status');
        if (result.success) {
            statusEl.className = 'settings-status success';
            statusEl.textContent = `✅ ${t('settings.saved')}`;
        } else {
            statusEl.className = 'settings-status error';
            const errMsg = result.error === 'unauthorized'
                ? '🔒 Token non valido o mancante'
                : `⚠️ ${t('settings.saved_local_error').replace('{error}', result.error || '')}`;
            statusEl.textContent = errMsg;
        }
        statusEl.style.display = 'block';
        setTimeout(() => statusEl.style.display = 'none', 4000);
    } catch(e) {
        const statusEl = document.getElementById('settings-status');
        statusEl.className = 'settings-status success';
        statusEl.textContent = `✅ ${t('settings.saved_local')}`;
        statusEl.style.display = 'block';
        setTimeout(() => statusEl.style.display = 'none', 4000);
    }
    // Re-sync _geminiAvailable after save (key may have been set/confirmed on server)
    try {
        const refreshed = await api('get_settings');
        if (refreshed && refreshed.gemini_key_set !== undefined) {
            _geminiAvailable = !!(refreshed.gemini_key_set);
            _updateGeminiButtonState();
        }
    } catch(e) {}
    // Persist meal_plan and tts_voice to SQLite for cross-device sync
    try {
        const appData = {};
        if (s.meal_plan) appData.meal_plan = s.meal_plan;
        if (s.tts_voice)  appData.tts_voice  = s.tts_voice;
        if (Object.keys(appData).length) await api('app_settings_save', {}, 'POST', { settings: appData });
    } catch(e) {}
    // Re-init screensaver watcher in case it was just enabled
    initInactivityWatcher();
}

function switchSettingsTab(btn, tabId) {
    // Stop info-tab auto-refresh when leaving that tab
    if (tabId !== 'tab-info' && _infoTabTimer) {
        clearInterval(_infoTabTimer);
        _infoTabTimer = null;
    }
    document.querySelectorAll('.settings-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.settings-panel').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById(tabId).classList.add('active');
}

function togglePasswordVisibility(inputId) {
    const input = document.getElementById(inputId);
    input.type = input.type === 'password' ? 'text' : 'password';
}

// ===== API HELPER =====
async function api(action, params = {}, method = 'GET', body = null, extraHeaders = {}) {
    // In demo mode, all shopping write operations are no-ops
    if (_demoMode) {
        const BRING_WRITE_ACTIONS = ['bring_add', 'bring_remove', 'bring_migrate_names', 'bring_set_spec',
                                      'shopping_add', 'shopping_remove'];
        if (BRING_WRITE_ACTIONS.includes(action)) {
            return { success: true, added: 0, removed: 0, skipped: 0, _demo: true };
        }
        // shopping_list / bring_list return the in-memory demo list
        if (action === 'shopping_list' || action === 'bring_list') {
            return { success: true, purchase: shoppingItems, listUUID: 'demo-list', _demo: true };
        }
    }
    // In offline mode, serve from cache / queue writes
    if (_offlineMode) {
        return _handleOfflineApi(action, params, body);
    }
    let url = `${API_BASE}?action=${action}`;
    if (method === 'GET') {
        Object.entries(params).forEach(([k, v]) => {
            url += `&${encodeURIComponent(k)}=${encodeURIComponent(v)}`;
        });
    }
    const opts = { method, cache: 'no-store' };
    const authHdrs = typeof apiAuthHeaders === 'function' ? apiAuthHeaders() : {};
    if (body) {
        opts.headers = { 'Content-Type': 'application/json', 'X-EverShelf-Request': '1', ...authHdrs, ...extraHeaders };
        opts.body = JSON.stringify(body);
    } else {
        opts.headers = { ...authHdrs, ...extraHeaders };
    }
    let res;
    try {
        res = await fetch(url, opts);
        // Server responded → reset failure counter and hide overlay if it was showing
        if (_networkDown) _hideNetworkOverlay(true);
        _networkFailCount = 0;
    } catch (fetchErr) {
        // Network-level failure (no route to host, Wi-Fi down, etc.)
        _networkFailCount++;
        if (_networkFailCount >= _NETWORK_FAIL_THRESHOLD) {
            _showNetworkOverlay();
        }
        throw fetchErr;
    }
    if (!res.ok) {
        remoteLog('API_ERROR', `${action} HTTP ${res.status}`);
        if (res.status === 401) {
            window._apiTokenRequired = true;
            if (typeof _promptApiTokenIfNeeded === 'function') _promptApiTokenIfNeeded();
        }
        // Report HTTP 5xx as server errors (not 4xx which are usually user errors)
        if (res.status >= 500) {
            reportError({
                type:    'api-server-error',
                message: `API ${action} returned HTTP ${res.status}`,
                context: { action, status: res.status },
            });
        }
    }
    const data = await res.json();
    // Keep local caches fresh for offline use (only ever written when server responds successfully)
    if (action === 'inventory_list' && data && Array.isArray(data.inventory)) {
        _offlineCacheSet(data.inventory);
    }
    if (action === 'get_settings' && data && data.success !== false) {
        window._apiTokenRequired = !!data.api_token_required;
        if (data.api_token_required && typeof _promptApiTokenIfNeeded === 'function') {
            _promptApiTokenIfNeeded();
        }
        _offlineCacheSetSettings(data);
    }
    if (action === 'products_list' && data && Array.isArray(data.products)) {
        _offlineProductsSet(data.products);
    }
    if (data && data.error) {
        remoteLog('API_FAIL', `${action}: ${data.error}`);
    }
    return data;
}

// ===== PAGE NAVIGATION =====
// Track current page for auto-refresh
let _currentPageId = 'dashboard';
let _currentPageParam = null;
let _pageHistory = [{ pageId: 'dashboard', param: null }];

function goBack(fallbackPage = 'dashboard') {
    if (_pageHistory.length > 1) {
        // Drop current page and navigate to the previous entry without re-adding history.
        _pageHistory.pop();
        const prev = _pageHistory[_pageHistory.length - 1] || { pageId: fallbackPage, param: null };
        showPage(prev.pageId, prev.param, { skipHistory: true });
        return;
    }
    showPage(fallbackPage, null, { skipHistory: true });
}

// Refresh current page data without full navigation
function refreshCurrentPage() {
    switch(_currentPageId) {
        case 'dashboard': loadDashboard(); break;
        case 'inventory': loadInventory(); break;
        case 'shopping':
            loadShoppingList._bgCall = true;
            loadShoppingList();
            break;
        case 'products': loadAllProducts(); break;
        case 'recipe':   loadRecipeArchive(); break;
        case 'log':      loadLog(); break;
        // scan/ai/settings/chat: nessun dato live da ricaricare
    }
}

function showPage(pageId, param = null, options = {}) {
    const skipHistory = !!options.skipHistory;
    if (!skipHistory) {
        const last = _pageHistory[_pageHistory.length - 1];
        const sameAsLast = !!last && last.pageId === pageId && (last.param ?? null) === (param ?? null);
        if (!sameAsLast) {
            _pageHistory.push({ pageId, param });
            if (_pageHistory.length > 80) _pageHistory.shift();
        }
    }

    _currentPageId = pageId;
    _currentPageParam = param;
    // Hide all pages
    document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
    // Show target page
    const page = document.getElementById(`page-${pageId}`);
    if (page) page.classList.add('active');

    // Clear search inputs when navigating away
    const invSearch = document.getElementById('inventory-search');
    if (invSearch) invSearch.value = '';
    const prodSearch = document.getElementById('products-search');
    if (prodSearch) prodSearch.value = '';
    
    // Update nav
    document.querySelectorAll('.nav-btn').forEach(b => b.classList.remove('active'));
    const navBtn = document.querySelector(`.nav-btn[data-page="${pageId}"]`);
    if (navBtn) navBtn.classList.add('active');
    
    // Page-specific init
    switch(pageId) {
        case 'dashboard':
            loadDashboard();
            break;
        case 'inventory':
            if (param !== null) {
                currentLocation = param;
                filterLocation(param);
            }
            loadInventory();
            break;
        case 'scan': _dismissFamilySiblingPrompt(); _resetAiFallbackForNewScan(); initScanner(); clearQuickNameResults(); updateSpesaBanner(); updateScanRecents(); _applySpesaScanUI();
            // Pre-warm the embedding model the first time user visits scan page
            if (typeof window._getCategoryPipeline === 'function' && !window._categoryPipelineReady) {
                window._getCategoryPipeline(); // fire-and-forget
            }
            break;
        case 'products': loadAllProducts(); break;
        case 'shopping':
            _getShoppingInventoryCache();
            _initShoppingPageInteractionTracking();
            loadShoppingList();
            break;
        case 'recipe': loadRecipeArchive(); break;
        case 'log': loadLog(); break;
        case 'ai': initAICamera(); break;
        case 'settings': loadSettingsUI(); break;
        case 'chat': if (_requireGemini()) initChat(); break;
    }

    // Auto-refresh banner notifications while on dashboard (every 5 min)
    if (_bannerRefreshTimer) { clearInterval(_bannerRefreshTimer); _bannerRefreshTimer = null; }
    if (pageId === 'dashboard') {
        _bannerRefreshTimer = setInterval(() => loadBannerAlerts(), 5 * 60 * 1000);
    }

    // Background sync Bring list (every 5 min, skip while user is scrolling the list)
    if (_shoppingPollTimer) { clearInterval(_shoppingPollTimer); _shoppingPollTimer = null; }
    if (pageId === 'shopping') {
        _shoppingPollTimer = setInterval(() => {
            const idleMs = Date.now() - (loadShoppingList._lastUserInteraction || 0);
            if (idleMs < 2 * 60 * 1000) return;
            loadShoppingList._bgCall = true;
            loadShoppingList();
        }, 5 * 60 * 1000);
    }
    
    // Stop scanner when leaving scan page
    if (pageId !== 'scan' && pageId !== 'ai') {
        stopScanner();
    }
    
    // Scroll to top
    window.scrollTo(0, 0);
}

// ===== ANTI-WASTE SECTION =====

const WASTE_BENCHMARKS = {
    it: { avgWasteRate: 22, avgKgMonth: 5.4, costPerKg: 8.2, currency: '€', countryKey: 'antiwaste.country_it', rangeMin: 8,  rangeMax: 36 },
    de: { avgWasteRate: 20, avgKgMonth: 6.5, costPerKg: 7.7, currency: '€', countryKey: 'antiwaste.country_de', rangeMin: 7,  rangeMax: 34 },
    en: { avgWasteRate: 30, avgKgMonth: 9.2, costPerKg: 8.5, currency: '$', countryKey: 'antiwaste.country_en', rangeMin: 12, rangeMax: 50 },
};
const _AW_KG_PER_EVENT = 0.5;
let _awRefreshTimer = null;
let _awFactTimer    = null;
let _awBadgeTimer   = null;

// ── Embedded fallback facts (used when offline / API not yet loaded) ──
const AW_FACTS_FALLBACK = {
    it: [
        "Nel 2024 ogni italiano spreca ~554 g di cibo a settimana (Waste Watcher 2024)",
        "Lo spreco domestico in Italia vale oltre €7,5 miliardi l'anno",
        "La frutta fresca è l'alimento più sprecato in Italia: ~22g/persona/settimana",
        "Nel mondo si sprecano ~1,05 miliardi di tonnellate di cibo ogni anno (UNEP 2024)",
        "Il 19% del cibo globale disponibile al consumo viene buttato (UNEP 2024)",
        "Le famiglie sono responsabili del 60% dello spreco alimentare totale",
        "Lo spreco alimentare conta per l'8-10% delle emissioni globali di gas serra",
        "Se fosse un Paese, lo spreco alimentare sarebbe il 3° emettitore di CO₂ al mondo",
        "Lo spreco alimentare consuma il 25% dell'acqua dolce usata in agricoltura",
        "Un'area grande quanto la Cina viene coltivata per cibo mai mangiato",
        "Lo spreco alimentare costa al mondo ~€1.000 miliardi l'anno",
        "Il lunedì è il giorno in cui gli italiani buttano più cibo (residui del weekend)",
        "Solo il 30% degli italiani sa distinguere 'da consumarsi entro' da 'preferibilmente entro'",
        "Il ricorso al congelatore riduce lo spreco domestico del 20%",
        "1 kg di pane sprecato = 1.300 litri d'acqua consumati inutilmente",
        "Sprecare 1 hamburger = stessa acqua di una doccia da 90 minuti",
        "Lo spreco alimentare pro capite in Italia è ~29 kg/anno (domestico)",
        "Il 42% degli italiani dichiara di sprecare meno grazie all'aumento dei prezzi",
        "Solo il 15% degli italiani chiede la 'doggy bag' al ristorante",
        "Un quarto del cibo sprecato basterebbe a sfamare tutti gli affamati del mondo",
        "La Legge Gadda (166/2016) è tra le norme anti-spreco più avanzate d'Europa",
        "Il Sud Italia spreca in media l'8% in più rispetto al Nord",
        "Nel 2024 oltre 780 milioni di persone hanno sofferto la fame nel mondo (FAO)",
        "Educare i bambini a scuola riduce lo spreco familiare del 15%",
        "Il packaging intelligente potrebbe ridurre lo spreco del 15%",
    ],
    de: [
        "Deutsche Haushalte werfen pro Person rund 82 kg Lebensmittel pro Jahr weg (Destatis 2024)",
        "Weltweit werden ~1,05 Milliarden Tonnen Lebensmittel pro Jahr verschwendet (UNEP 2024)",
        "19% des global verfügbaren Lebensmittelangebots landet im Müll (UNEP 2024)",
        "Haushalte verursachen 60% der gesamten Lebensmittelverschwendung",
        "Lebensmittelverschwendung ist für 8-10% der globalen Treibhausgase verantwortlich",
        "Wäre Lebensmittelverschwendung ein Land, wäre es der 3. größte CO₂-Emittent",
        "25% des in der Landwirtschaft genutzten Süßwassers wird für nie gegessenes Essen verbraucht",
        "1 kg verschwendetes Rindfleisch ≈ 27 kg CO₂-Emissionen",
        "Das Einfrieren reduziert Haushaltsabfälle um bis zu 20%",
        "Nur ein Viertel der verschwendeten Lebensmittel würde alle Hungernden ernähren",
        "Schlaue Verpackungen könnten den Lebensmittelabfall um 15% senken",
    ],
    en: [
        "~1.05 billion tonnes of food are wasted globally every year (UNEP 2024)",
        "19% of food available for human consumption is wasted globally (UNEP 2024)",
        "Households account for 60% of all food waste globally",
        "Food waste represents 8-10% of global greenhouse gas emissions",
        "If food waste were a country, it would be the world's 3rd largest CO₂ emitter",
        "25% of freshwater used in farming grows food that is never eaten",
        "Food waste costs the world ~$1 trillion per year",
        "30–40% of the US food supply is wasted each year (USDA 2021)",
        "Americans spend ~$1,800/year on food they never eat",
        "Just a quarter of wasted food would feed all the world's hungry",
        "Smart packaging could cut food waste by 15%",
        "1 kg of wasted bread = 1,300 litres of water wasted",
        "Wasting one hamburger uses as much water as a 90-minute shower",
        "Teaching children about food waste reduces household waste by 15%",
        "In 2024, over 780 million people faced hunger despite global food abundance (FAO)",
    ],
};

// Live facts cache (loaded from API daily, falls back to embedded)
let _awLiveFacts = null;
const _AW_FACTS_LS_KEY = 'aw_facts_v2';
const _AW_FACTS_TS_KEY = 'aw_facts_ts_v2';

/** Load facts from localStorage cache or fetch from server (once per day). */
async function _awLoadFacts() {
    const cached = localStorage.getItem(_AW_FACTS_LS_KEY);
    const ts = parseInt(localStorage.getItem(_AW_FACTS_TS_KEY) || '0');
    const age = Date.now() - ts;

    // Use localStorage cache if < 24 h old
    if (cached && age < 86_400_000) {
        try { _awLiveFacts = JSON.parse(cached); return; } catch {}
    }

    // Try fetching from server if online
    if (!navigator.onLine) return;
    try {
        const data = await api('food_facts');
        if (data && data.it && data.it.length > 0) {
            _awLiveFacts = data;
            localStorage.setItem(_AW_FACTS_LS_KEY, JSON.stringify(data));
            localStorage.setItem(_AW_FACTS_TS_KEY, String(Date.now()));
        }
    } catch {}
}

/** Return current facts array for the active language. */
function _awGetFacts() {
    const src = _awLiveFacts || AW_FACTS_FALLBACK;
    return src[_currentLang] || src['it'] || AW_FACTS_FALLBACK['it'];
}

/** Fetch fresh stats and re-render the anti-waste section. */
function _awFetchAndRender() {
    if (!navigator.onLine) { _updateAwLiveDot(false); return; }
    api('stats').then(s => {
        _renderAntiWasteSection(
            s.used_30d      || 0, s.wasted_30d      || 0,
            s.used_prev_30d || 0, s.wasted_prev_30d || 0,
            s.used_prev_60d || 0, s.wasted_prev_60d || 0,
            true
        );
    }).catch(() => _updateAwLiveDot(false));
}

/** Update just the live indicator dot without re-rendering the whole card. */
function _updateAwLiveDot(online) {
    const dot = document.querySelector('.aw-live-dot');
    if (!dot) return;
    dot.className = 'aw-live-dot ' + (online ? 'aw-live-on' : 'aw-live-off');
    dot.title = online ? t('antiwaste.live_on') : t('antiwaste.live_off');
}

/** Start/stop the 60-second auto-refresh based on connectivity. */
function _startAntiWasteAutoRefresh() {
    clearInterval(_awRefreshTimer);
    if (navigator.onLine) _awRefreshTimer = setInterval(_awFetchAndRender, 60_000);
}

/**
 * Start badge rotation: shows only as many badges as fit in one row (auto-measured),
 * cycles through all with a fade every 5 minutes.
 * Call AFTER the row is already in the DOM with the initial slice rendered.
 */
function _startBadgeRotation(allBadges, maxVisible) {
    clearInterval(_awBadgeTimer);
    const row = document.getElementById('aw-badges-row');
    if (!row || allBadges.length <= maxVisible) return;

    let start = 0;
    const render = () => {
        const slice = [];
        for (let i = 0; i < maxVisible; i++) {
            slice.push(allBadges[(start + i) % allBadges.length]);
        }
        row.innerHTML = slice.join('');
    };

    const rotate = () => {
        if (!row.isConnected) { clearInterval(_awBadgeTimer); return; }
        row.style.opacity = '0';
        setTimeout(() => {
            start = (start + 1) % allBadges.length;
            render();
            row.style.opacity = '1';
        }, 380);
    };

    // Rotate every 5 minutes
    _awBadgeTimer = setInterval(rotate, 5 * 60_000);
}

/** Build one trend mini-card. */
function _awTrendCard(rate, label, maxRate) {
    if (rate === null) {
        return `<div class="aw-tcard aw-tcard-empty">
            <span class="aw-tc-label">${label}</span>
            <span class="aw-tc-rate">–</span>
            <div class="aw-tc-minibar"><div style="width:0"></div></div>
        </div>`;
    }
    const cls    = rate <= 8 ? 'good' : rate <= 20 ? 'ok' : 'bad';
    const barPct = Math.max(4, Math.round((rate / Math.max(maxRate, 5)) * 100));
    return `<div class="aw-tcard aw-tcard-${cls}">
        <span class="aw-tc-label">${label}</span>
        <span class="aw-tc-rate">${rate}%</span>
        <div class="aw-tc-minibar"><div style="width:${barPct}%"></div></div>
    </div>`;
}

/** Arrow between two consecutive trend values. */
function _awTrendArrow(prev, curr) {
    if (prev === null || curr === null) return null;
    const d = curr - prev;
    if (d <= -3) return { sym: '↓', cls: 'aw-arrow-good' };
    if (d >= 3)  return { sym: '↑', cls: 'aw-arrow-bad'  };
    return           { sym: '→', cls: 'aw-arrow-ok'   };
}

function _renderAntiWasteSection(used30, wasted30, usedP30, wastedP30, usedP60, wastedP60, isOnline = navigator.onLine) {
    const section = document.getElementById('waste-chart-section');
    const total30 = used30 + wasted30;
    if (total30 === 0) { section.style.display = 'none'; return; }
    // Show only if the alternation phase allows it (or before alternation starts)
    section.style.display = (!_insightPhase || _insightPhase === 'waste') ? 'block' : 'none';

    const bm      = WASTE_BENCHMARKS[_currentLang] || WASTE_BENCHMARKS['it'];
    const country = t(bm.countryKey);
    const myRate  = Math.round((wasted30 / total30) * 100);
    const avgRate = bm.avgWasteRate;

    // Grade
    let grade, gradeClass;
    if      (myRate <= 3)  { grade = 'A+'; gradeClass = 'ap'; }
    else if (myRate <= 8)  { grade = 'A';  gradeClass = 'a';  }
    else if (myRate <= 15) { grade = 'B';  gradeClass = 'b';  }
    else if (myRate <= 25) { grade = 'C';  gradeClass = 'c';  }
    else                   { grade = 'D';  gradeClass = 'd';  }

    // Savings vs average
    const avgWastedEvents = total30 * (avgRate / 100);
    const savedEvents     = Math.max(0, avgWastedEvents - wasted30);
    const savedKg         = +(savedEvents * _AW_KG_PER_EVENT).toFixed(1);
    const savedMoney      = Math.round(savedKg * bm.costPerKg);
    const savedCO2        = +(savedKg * 2.5).toFixed(1);

    // Status
    let statusMsg, statusCls;
    if (myRate < avgRate) {
        statusMsg = t('antiwaste.better').replace('{country}', country).replace('{diff}', avgRate - myRate);
        statusCls = 'aw-status-good';
    } else if (myRate > avgRate) {
        statusMsg = t('antiwaste.worse').replace('{country}', country);
        statusCls = 'aw-status-bad';
    } else {
        statusMsg = t('antiwaste.on_par').replace('{country}', country);
        statusCls = 'aw-status-ok';
    }

    // Single stacked bar: avg always fills 88% of track width; you fills proportionally inside
    const scale   = Math.max(myRate, avgRate, 1);
    const avgPct  = 88; // avg always = reference width
    const youPct  = +((myRate / scale) * 88).toFixed(1); // your bar, same scale
    const youLabel = t('antiwaste.you').split(' ')[0]; // "Tu" / "You" / "Du"

    // Annual totals for comparison bar
    const myAnnualKg  = Math.round(wasted30 * _AW_KG_PER_EVENT * 12);
    const avgAnnualKg = Math.round(bm.avgKgMonth * 12);
    const annualInfo  = t('antiwaste.annual_info')
        .replace('{you}', myAnnualKg)
        .replace('{avg}', avgAnnualKg);

    // Build all badge objects (shown 4 at a time, rotated every 5 min)
    const diffPct = avgRate - myRate;
    const allBadges = [];
    allBadges.push(`<span class="aw-badge aw-badge-rate">
        <span class="aw-badge-icon">📊</span>
        <span class="aw-badge-body"><b>${myRate}%</b><small>${t('antiwaste.badge_rate')}</small></span>
    </span>`);
    if (wasted30 > 0) allBadges.push(`<span class="aw-badge aw-badge-wasted">
        <span class="aw-badge-icon">🗑️</span>
        <span class="aw-badge-body"><b>${wasted30}</b><small>${t('antiwaste.badge_wasted')}</small></span>
    </span>`);
    if (savedMoney > 0) allBadges.push(`<span class="aw-badge aw-badge-money">
        <span class="aw-badge-icon">💰</span>
        <span class="aw-badge-body"><b>${bm.currency}${savedMoney}/m</b><small>${t('antiwaste.badge_saved_money')}</small></span>
    </span>`);
    if (savedCO2 > 0) allBadges.push(`<span class="aw-badge aw-badge-co2">
        <span class="aw-badge-icon">🌍</span>
        <span class="aw-badge-body"><b>−${savedCO2} kg</b><small>CO₂</small></span>
    </span>`);
    if (diffPct > 0) allBadges.push(`<span class="aw-badge aw-badge-better">
        <span class="aw-badge-icon">✅</span>
        <span class="aw-badge-body"><b>−${diffPct}%</b><small>${t('antiwaste.badge_better')}</small></span>
    </span>`);

    // Initial render: show all badges (row uses nowrap so they overflow off-screen, no wrapping)
    // We'll measure and trim in requestAnimationFrame below.
    const initBadges = allBadges.join('');

    // Facts
    const facts   = _awGetFacts();
    const factIdx = Math.floor(Math.random() * facts.length);

    const liveCls = isOnline ? 'aw-live-on'          : 'aw-live-off';
    const liveTip = isOnline ? t('antiwaste.live_on') : t('antiwaste.live_off');

    section.innerHTML = `
        <div class="aw-header">
            <div class="aw-title-row">
                <span class="aw-live-dot ${liveCls}" title="${liveTip}"></span>
                <h3 class="aw-title">${t('antiwaste.title')}</h3>
            </div>
            <span class="aw-grade aw-grade-${gradeClass}" title="${t('antiwaste.grade_label')}">${grade}</span>
        </div>

        <div class="aw-cmp-wrap">
            <div class="aw-cmp-bar-track">
                <div id="aw-bar-avg" class="aw-cmp-bar-fill-avg"></div>
                <div id="aw-bar-you" class="aw-cmp-bar-fill-you"></div>
            </div>
            <div class="aw-cmp-legend">
                <span class="aw-cmp-legend-you">▮ ${youLabel} <strong>${myRate}%</strong></span>
                <span class="aw-cmp-legend-avg">${country} <strong>${avgRate}%</strong> ▮</span>
            </div>
            <p class="aw-status-inline ${statusCls}">${statusMsg} &nbsp;·&nbsp; ${annualInfo}</p>
        </div>

        ${allBadges.length > 0 ? `<div id="aw-badges-row" class="aw-savings-row">${initBadges}</div>` : ''}

        <div class="aw-fact-rotator">
            <span class="aw-fact-icon">💡</span>
            <span id="aw-fact-text" class="aw-fact-text">${facts[factIdx]}</span>
        </div>

        <div class="aw-source">${(_awLiveFacts && _awLiveFacts.source) || t('antiwaste.source')}</div>
    `;

    // After DOM insertion: animate bars + measure how many badges actually fit in one row
    requestAnimationFrame(() => {
        // Animate comparison bars
        const barYou = document.getElementById('aw-bar-you');
        const barAvg = document.getElementById('aw-bar-avg');
        if (barYou) { barYou.style.width = youPct + '%'; setTimeout(() => barYou.classList.add('loaded'), 100); }
        if (barAvg) { barAvg.style.width = avgPct + '%'; setTimeout(() => barAvg.classList.add('loaded'), 100); }

        // Measure how many badges fit in one row
        const row = document.getElementById('aw-badges-row');
        if (!row || !allBadges.length) return;

        const GAP = 6; // matches CSS gap
        const rowW = row.offsetWidth;

        // Measure each badge width by reading the rendered children
        const kids = [...row.children];
        let totalW = 0;
        let fit = 0;
        for (const el of kids) {
            const bw = el.offsetWidth;
            if (fit > 0) totalW += GAP;
            totalW += bw;
            if (totalW > rowW + 1) break; // +1 for sub-pixel rounding
            fit++;
        }
        fit = Math.max(1, fit);

        // Trim visible row to the fit count
        row.innerHTML = allBadges.slice(0, fit).join('');

        // Start rotation only if there are more badges than fit
        _startBadgeRotation(allBadges, fit);
    });

    // Fact rotation (every 6 s)
    if (_awFactTimer) clearInterval(_awFactTimer);
    if (facts.length > 1) {
        let idx = factIdx;
        _awFactTimer = setInterval(() => {
            const el = document.getElementById('aw-fact-text');
            if (!el) { clearInterval(_awFactTimer); return; }
            el.classList.add('aw-fact-fade');
            setTimeout(() => {
                idx = (idx + 1) % facts.length;
                el.textContent = facts[idx];
                el.classList.remove('aw-fact-fade');
            }, 420);
        }, 5 * 60_000);
    }
}

// ===== NUTRITION ANALYSIS SECTION =====
// Alternates with waste-chart-section every hour (randomised offset)

// Colour palette for pie slices (matches category colours)
const _NUTR_COLORS = {
    'frutta':    '#4ade80', 'verdura':   '#22d3ee',
    'carne':     '#f87171', 'pesce':     '#60a5fa',
    'latticini': '#fbbf24', 'pasta':     '#a78bfa',
    'pane':      '#fb923c', 'cereali':   '#f472b6',
    'bevande':   '#34d399', 'condimenti':'#94a3b8',
    'surgelati': '#818cf8', 'conserve':  '#e879f9',
    'snack':     '#fcd34d', 'altro':     '#64748b',
};

let _nutriData = null;      // cached result from last inventory fetch
let _insightFlipTimer = null; // setInterval handle for waste/nutrition alternation

/**
 * Compute nutrition-related metrics from the current inventory array.
 * Returns null if not enough data.
 */
function _buildNutritionData(inventory) {
    if (!inventory || inventory.length === 0) return null;

    // Category distribution (product count)
    const catCounts = {};
    for (const item of inventory) {
        const cat = mapToLocalCategory(item.category || '', item.name || '');
        catCounts[cat] = (catCounts[cat] || 0) + 1;
    }
    const total = Object.values(catCounts).reduce((s, v) => s + v, 0);

    // Sorted slices for pie
    const slices = Object.entries(catCounts)
        .sort((a, b) => b[1] - a[1])
        .map(([cat, count]) => ({
            cat,
            count,
            pct: Math.round(count / total * 100),
            color: _NUTR_COLORS[cat] || '#64748b',
            icon:  CATEGORY_ICONS[cat] || '📦',
        }));

    // Health score 0-100 based on category mix
    // + points for fruit/veg/fish; - for snacks/sweets
    const healthyCats   = ['frutta','verdura','pesce','carne'];
    const unhealthyCats = ['snack','bevande'];
    const healthyCount  = healthyCats.reduce((s, c) => s + (catCounts[c] || 0), 0);
    const unhealthyCount= unhealthyCats.reduce((s, c) => s + (catCounts[c] || 0), 0);
    const healthScore   = Math.min(100, Math.max(0,
        Math.round(50 + (healthyCount / Math.max(total, 1)) * 50 - (unhealthyCount / Math.max(total, 1)) * 30)
    ));

    // Variety score: number of distinct categories / max(16)
    const varietyScore = Math.min(100, Math.round(Object.keys(catCounts).length / 16 * 100));

    // Freshness score: % products with expiry date set
    const withExpiry = inventory.filter(i => i.expiry_date).length;
    const freshnessScore = Math.round(withExpiry / Math.max(total, 1) * 100);

    // Balance: fraction of fresh (frigo+freezer) vs shelf-stable (dispensa)
    const fresh = inventory.filter(i => i.location === 'frigo' || i.location === 'freezer').length;
    const fresh_pct = Math.round(fresh / Math.max(total, 1) * 100);

    return { slices, total, healthScore, varietyScore, freshnessScore, fresh_pct };
}

/**
 * Render the nutrition analysis card into #nutrition-section.
 */
function _renderNutritionSection(inventory) {
    const section = document.getElementById('nutrition-section');
    if (!section) return;
    const data = _buildNutritionData(inventory);
    if (!data) { section.style.display = 'none'; return; }
    _nutriData = data;

    const { slices, total, healthScore, varietyScore, freshnessScore, fresh_pct } = data;
    const top5 = slices.slice(0, 5);

    // Build conic-gradient for pie
    let deg = 0;
    const stops = top5.map(s => {
        const end = deg + s.pct * 3.6;
        const stop = `${s.color} ${deg.toFixed(1)}deg ${end.toFixed(1)}deg`;
        deg = end;
        return stop;
    });
    if (deg < 360) stops.push(`#334155 ${deg.toFixed(1)}deg 360deg`);
    const gradient = `conic-gradient(from 0deg, ${stops.join(', ')})`;

    // Score colour
    const scoreColor = healthScore >= 70 ? '#4ade80' : healthScore >= 45 ? '#fbbf24' : '#f87171';
    const scoreLabel = healthScore >= 70 ? t('nutrition.score_excellent') : healthScore >= 45 ? t('nutrition.score_good') : t('nutrition.score_improve');

    section.innerHTML = `
    <div class="nutr-card">
        <div class="aw-header">
            <div class="aw-title-row">
                <span class="aw-live-dot aw-live-on"></span>
                <h3 class="aw-title">${t('nutrition.title')}</h3>
            </div>
            <span class="aw-grade" style="background:${scoreColor};font-size:.75rem;padding:4px 10px">${scoreLabel}</span>
        </div>

        <div class="nutr-body">
            <!-- 3D animated pie -->
            <div class="nutr-pie-wrap">
                <div class="nutr-pie-3d" id="nutr-pie" style="background:${gradient}"></div>
                <div class="nutr-pie-center">
                    <span class="nutr-pie-total">${total}</span>
                    <span class="nutr-pie-label">${t('nutrition.products_count')}</span>
                </div>
            </div>

            <!-- Legend -->
            <div class="nutr-legend">
                ${top5.map(s => `
                <div class="nutr-leg-row">
                    <span class="nutr-leg-dot" style="background:${s.color}"></span>
                    <span class="nutr-leg-icon">${s.icon}</span>
                    <span class="nutr-leg-name">${t('categories.' + s.cat) || s.cat}</span>
                    <span class="nutr-leg-pct">${s.pct}%</span>
                </div>`).join('')}
            </div>
        </div>

        <!-- Score bar row -->
        <div class="nutr-scores">
            ${_nutrScoreBar(t('nutrition.label_health'), healthScore, '#4ade80')}
            ${_nutrScoreBar(t('nutrition.label_variety'), varietyScore, '#60a5fa')}
            ${_nutrScoreBar(t('nutrition.label_fresh'), fresh_pct, '#22d3ee')}
        </div>

        <div class="aw-source">${t('nutrition.source').replace('{n}', total)}</div>
    </div>`;

    // Trigger pie animation after render
    requestAnimationFrame(() => {
        const pie = document.getElementById('nutr-pie');
        if (pie) setTimeout(() => pie.classList.add('nutr-pie-ready'), 60);
    });
}

function _nutrScoreBar(label, val, color) {
    return `<div class="nutr-score-row">
        <span class="nutr-score-label">${label}</span>
        <div class="nutr-score-track">
            <div class="nutr-score-fill" style="width:0%;background:${color}" data-target="${val}"></div>
        </div>
        <span class="nutr-score-val">${val}%</span>
    </div>`;
}

// ===== MONTHLY STATS SECTION =====
// Third panel in the insight rotation (waste → nutrition → monthly → waste …)

function _renderMonthlyStatsSection(data) {
    const section = document.getElementById('monthly-stats-section');
    if (!section) return;
    if (!data || !data.success || data.items_consumed === 0) {
        section.innerHTML = '';
        section.style.display = 'none';
        return;
    }

    // Month label from 'YYYY-MM' → formatted locale string
    const [yr, mo] = data.month.split('-').map(Number);
    const localeMap = { de: 'de-DE', fr: 'fr-FR', es: 'es-ES', en: 'en-GB', it: 'it-IT' };
    const locale = localeMap[_currentLang] || 'it-IT';
    const monthLabel = new Date(yr, mo - 1, 1).toLocaleDateString(locale, { month: 'long', year: 'numeric' });
    const prevLabel  = new Date(yr, mo - 2, 1).toLocaleDateString(locale, { month: 'long' });

    // Trend vs previous month
    let trendHTML = '';
    const prev = data.items_consumed_prev;
    const curr = data.items_consumed;
    if (prev > 0) {
        const diff = Math.round((curr - prev) / prev * 100);
        if (diff < -2) {
            trendHTML = `<span class="aw-arrow-good">↓ ${t('stats_monthly.trend_down').replace('{pct}', Math.abs(diff)).replace('{prev}', prevLabel)}</span>`;
        } else if (diff > 2) {
            trendHTML = `<span class="aw-arrow-bad">↑ ${t('stats_monthly.trend_up').replace('{pct}', diff).replace('{prev}', prevLabel)}</span>`;
        } else {
            trendHTML = `<span class="aw-arrow-ok">→ ${t('stats_monthly.trend_same')}</span>`;
        }
    }

    // Top category bars
    const top = (data.top_categories || []).slice(0, 4);
    const maxCnt = top.length ? Math.max(...top.map(c => c.count)) : 1;
    const catBars = top.map(c => {
        const color = _NUTR_COLORS[c.cat] || '#64748b';
        const barPct = Math.round(c.count / maxCnt * 100);
        // t() returns the key itself when not found — guard against it
        const catKey = 'categories.' + c.cat;
        const label  = t(catKey) !== catKey ? t(catKey) : c.cat.replace(/-/g, ' ');
        return `<div class="ms-cat-row">
            <span class="ms-cat-name">${escapeHtml(label)}</span>
            <div class="ms-cat-bar-wrap">
                <div class="ms-cat-bar" style="background:${color}" data-target="${barPct}"></div>
            </div>
            <span class="ms-cat-cnt">${c.count}</span>
        </div>`;
    }).join('');

    // Badges
    const badges = [];
    if (data.items_added > 0)
        badges.push(`<span class="aw-badge"><span class="aw-badge-icon">📦</span><span class="aw-badge-body"><b>${data.items_added}</b><small>${t('stats_monthly.added')}</small></span></span>`);
    if (data.items_wasted > 0) {
        let wastedBadgeText = `<b>${data.items_wasted}</b><small>${t('stats_monthly.wasted')}</small>`;
        if (data.wasted_value_eur > 0) {
            const sym = getSettings().price_currency === 'USD' ? '$' : (getSettings().price_currency === 'GBP' ? '£' : '€');
            wastedBadgeText = `<b>${data.items_wasted}</b><small>${t('stats_monthly.wasted')} · ${sym}${data.wasted_value_eur.toFixed(2)}</small>`;
        }
        badges.push(`<span class="aw-badge aw-badge-wasted"><span class="aw-badge-icon">🗑️</span><span class="aw-badge-body">${wastedBadgeText}</span></span>`);
    }
    if (data.top_products?.length > 0)
        badges.push(`<span class="aw-badge aw-badge-better"><span class="aw-badge-icon">⭐</span><span class="aw-badge-body"><b>${escapeHtml(data.top_products[0].name)}</b><small>${t('stats_monthly.top_used')}</small></span></span>`);

    section.innerHTML = `
    <div class="nutr-card">
        <div class="aw-header">
            <div class="aw-title-row">
                <span class="aw-live-dot aw-live-on"></span>
                <h3 class="aw-title">${t('stats_monthly.title')}</h3>
            </div>
            <span class="aw-grade" style="background:#6366f1;font-size:.75rem;padding:4px 10px">${monthLabel}</span>
        </div>

        <div class="ms-main-row">
            <div class="ms-main-num">${curr}</div>
            <div class="ms-main-info">
                <div class="ms-main-label">${t('stats_monthly.consumed')}</div>
                <div class="ms-trend">${trendHTML}</div>
            </div>
        </div>

        ${top.length > 0 ? `
        <div class="ms-cats-section">
            <div class="ms-cats-title">${t('stats_monthly.top_cats')}</div>
            ${catBars}
        </div>` : ''}

        ${badges.length > 0 ? `<div class="aw-savings-row ms-badges-row">${badges.join('')}</div>` : ''}

        <div class="aw-source">${t('stats_monthly.source')}</div>
    </div>`;

    // Show only if it's the active phase (mirrors _applyInsightPhase logic)
    section.style.display = (_insightPhase === 'monthly') ? 'block' : 'none';
}

// ===== MACROS SECTION (#118) =====
/**
 * Render the macronutrient breakdown panel into #macros-section.
 */
function _renderMacrosSection(data) {
    const section = document.getElementById('macros-section');
    if (!section) return;
    if (!data || !data.success || data.total_items === 0) {
        section.innerHTML = '';
        section.style.display = 'none';
        return;
    }

    const { totals, ratios, total_items } = data;
    const macros = [
        { key: 'carbohydrates', label: t('nutrition.macros_carbs'),    color: '#a78bfa', value: totals.carbohydrates, unit: 'g', pct: ratios.carbohydrates },
        { key: 'fat',           label: t('nutrition.macros_fat'),      color: '#fbbf24', value: totals.fat,           unit: 'g', pct: ratios.fat },
        { key: 'proteins',      label: t('nutrition.macros_proteins'), color: '#4ade80', value: totals.proteins,      unit: 'g', pct: ratios.proteins },
        { key: 'fiber',         label: t('nutrition.macros_fiber'),    color: '#34d399', value: totals.fiber,         unit: 'g', pct: null },
    ];

    const bars = macros.map(m => {
        const barPct = m.pct !== null ? m.pct : Math.min(100, Math.round((m.value / Math.max(totals.carbohydrates + totals.fat + totals.proteins, 1)) * 100));
        return `<div class="macro-row">
            <span class="macro-label">${m.label}</span>
            <div class="macro-bar-wrap">
                <div class="macro-bar-fill" style="background:${m.color}" data-target="${barPct}"></div>
            </div>
            <span class="macro-val">${m.value.toLocaleString(_currentLang === 'de' ? 'de-DE' : 'it-IT')}${m.unit}${m.pct !== null ? ` <small>(${m.pct}%)</small>` : ''}</span>
        </div>`;
    }).join('');

    section.innerHTML = `
    <div class="nutr-card">
        <div class="aw-header">
            <div class="aw-title-row">
                <span class="aw-live-dot aw-live-on"></span>
                <h3 class="aw-title">${t('nutrition.macros_title')}</h3>
            </div>
            <span class="aw-grade" style="background:#0ea5e9;font-size:.75rem;padding:4px 10px">${totals.energy_kcal.toLocaleString()} kcal</span>
        </div>
        <div class="macro-bars">${bars}</div>
        <div class="aw-source">${t('nutrition.macros_source').replace('{n}', total_items)}</div>
    </div>`;

    section.style.display = (_insightPhase === 'macros') ? 'block' : 'none';
}

/**
 * Start the waste ↔ nutrition ↔ monthly stats alternation on the dashboard.
 */
let _insightPhase = null; // 'waste' | 'nutrition' | 'monthly' | 'macros'
const _INSIGHT_PHASES = ['waste', 'nutrition', 'monthly', 'macros'];

function _startInsightAlternation() {
    clearInterval(_insightFlipTimer);
    // Pick initial panel cycling through 3 phases based on current 60-second slot
    const idx = Math.floor(Date.now() / 60_000) % _INSIGHT_PHASES.length;
    _insightPhase = _INSIGHT_PHASES[idx];
    _applyInsightPhase();
    // Advance every 60 seconds (1 minute per panel)
    _insightFlipTimer = setInterval(() => {
        _insightPhase = _INSIGHT_PHASES[(_INSIGHT_PHASES.indexOf(_insightPhase) + 1) % _INSIGHT_PHASES.length];
        _applyInsightPhase();
    }, 60_000);
}

function _applyInsightPhase() {
    const wasteEl   = document.getElementById('waste-chart-section');
    const nutrEl    = document.getElementById('nutrition-section');
    const monthlyEl = document.getElementById('monthly-stats-section');
    const macrosEl  = document.getElementById('macros-section');
    if (!wasteEl || !nutrEl) return;

    // Map of which panels actually have rendered content
    const hasContent = {
        'waste':     wasteEl.innerHTML.trim()    !== '',
        'nutrition': nutrEl.innerHTML.trim()     !== '',
        'monthly':   !!monthlyEl && monthlyEl.innerHTML.trim() !== '',
        'macros':    !!macrosEl  && macrosEl.innerHTML.trim()  !== '',
    };

    // If the intended phase has no content, advance to the next one that does
    let phase = _insightPhase;
    for (let i = 0; i < _INSIGHT_PHASES.length; i++) {
        if (hasContent[phase]) break;
        phase = _INSIGHT_PHASES[(_INSIGHT_PHASES.indexOf(phase) + 1) % _INSIGHT_PHASES.length];
    }

    const showWaste   = phase === 'waste';
    const showNutr    = phase === 'nutrition';
    const showMonthly = phase === 'monthly';
    const showMacros  = phase === 'macros';

    // Fade-swap all four panels
    const els = [wasteEl, nutrEl, ...(monthlyEl ? [monthlyEl] : []), ...(macrosEl ? [macrosEl] : [])];
    els.forEach(el => { el.style.opacity = '0'; el.style.transition = 'opacity .6s'; });
    setTimeout(() => {
        wasteEl.style.display   = showWaste   ? 'block' : 'none';
        nutrEl.style.display    = showNutr    ? 'block' : 'none';
        if (monthlyEl) monthlyEl.style.display = showMonthly ? 'block' : 'none';
        if (macrosEl)  macrosEl.style.display  = showMacros  ? 'block' : 'none';
        requestAnimationFrame(() => {
            els.forEach(el => { el.style.opacity = '1'; });
            if (showNutr) {
                nutrEl.querySelectorAll('.nutr-score-fill').forEach(bar => {
                    bar.style.width = (bar.dataset.target || 0) + '%';
                });
            }
            if (showMonthly && monthlyEl) {
                monthlyEl.querySelectorAll('.ms-cat-bar').forEach(bar => {
                    bar.style.transition = 'width 0.6s ease';
                    bar.style.width = (bar.dataset.target || 0) + '%';
                });
            }
            if (showMacros && macrosEl) {
                macrosEl.querySelectorAll('.macro-bar-fill').forEach(bar => {
                    bar.style.transition = 'width 0.6s ease';
                    bar.style.width = (bar.dataset.target || 0) + '%';
                });
            }
        });
    }, 620);
}

// ===== DASHBOARD =====
async function loadDashboard() {
    // Show shimmer on stat cards while loading
    ['stat-dispensa', 'stat-frigo', 'stat-freezer'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.classList.add('stat-loading');
    });

    try {
        const [summaryData, statsData] = await Promise.all([
            api('inventory_summary'),
            api('stats')
        ]);
        
        // Update stat cards
        const summary = summaryData.summary || [];
        let total = 0;
        ['dispensa', 'frigo', 'freezer'].forEach(loc => {
            const s = summary.find(x => x.location === loc);
            const count = s ? s.product_count : 0;
            const el = document.getElementById(`stat-${loc}`);
            el.textContent = count;
            el.classList.remove('stat-loading');
            total += count;
        });
        // Add non-standard locations
        summary.forEach(s => {
            if (!['dispensa', 'frigo', 'freezer'].includes(s.location)) {
                total += s.product_count;
            }
        });
        // Load shopping list count from Bring!
        loadShoppingCount();
        // Show last known total instantly, then refresh from server
        _loadCanonicalTotalFromSession();
        _applyShoppingTotalDisplay();
        
        // Quick recipe button - show when there are expiring products
        const recipeBar = document.getElementById('quick-recipe-bar');
        if (statsData.expiring_soon && statsData.expiring_soon.length > 0) {
            recipeBar.style.display = 'block';
        } else {
            recipeBar.style.display = 'none';
        }
        
        // Expiring items
        const expiringSection = document.getElementById('alert-expiring');
        const expiringList = document.getElementById('expiring-list');
        if (statsData.expiring_soon && statsData.expiring_soon.length > 0) {
            expiringSection.style.display = 'block';
            expiringList.innerHTML = statsData.expiring_soon.map(item => {
                const days = daysUntilExpiry(item.expiry_date);
                let badgeText, badgeClass;
                if (days === 0) { badgeText = t('expiry.today'); badgeClass = 'today'; }
                else if (days === 1) { badgeText = t('expiry.tomorrow'); badgeClass = 'expiring'; }
                else if (days <= 7) { badgeText = t('expiry.days').replace('{days}', days); badgeClass = 'expiring'; }
                else if (days <= 30) { badgeText = t('expiry.days_compact').replace('{n}', days); badgeClass = 'expiring-soon'; }
                else { const m = Math.round(days/30); badgeText = m <= 1 ? t('expiry.days_compact').replace('{n}', days) : t('expiry.months_approx').replace('{n}', m); badgeClass = 'expiring-later'; }
                const qtyDisplay = formatQuantity(item.quantity, item.unit, item.default_quantity, item.package_unit);
                return `
                <div class="alert-item alert-item-clickable" onclick="showAlertItemDetail(${item.id}, ${item.product_id})">
                    <div class="alert-item-info">
                        <span class="alert-item-name">${escapeHtml(item.name)}</span>
                        ${item.brand ? `<span class="alert-item-brand">${escapeHtml(item.brand)}</span>` : ''}
                    </div>
                    <div class="alert-item-badges">
                        <span class="alert-item-qty">📦 ${qtyDisplay}</span>
                        <span class="alert-item-badge ${badgeClass}">${badgeText}</span>
                    </div>
                </div>`;
            }).join('');
        } else {
            expiringSection.style.display = 'none';
        }
        
        // Expired items — items in the freezer that are still within the safety window are hidden
        const expiredSection = document.getElementById('alert-expired');
        const expiredList = document.getElementById('expired-list');
        const visibleExpired = (statsData.expired || []).filter(item => {
            const days = Math.abs(daysUntilExpiry(item.expiry_date));
            return getExpiredSafety(item, days).level !== 'ok';
        });
        if (visibleExpired.length > 0) {
            expiredSection.style.display = 'block';
            expiredList.innerHTML = visibleExpired.map(item => {
                const days = Math.abs(daysUntilExpiry(item.expiry_date));
                let daysText;
                if (days === 0) daysText = t('expiry.expired_today');
                else if (days === 1) daysText = t('expiry.expired_yesterday');
                else daysText = t('expiry.expired_days').replace('{days}', days);
                const safety = getExpiredSafety(item, days);
                const locIcon = item.location === 'freezer' ? '❄️' : item.location === 'frigo' ? '🧊' : '';
                const qtyDisplayExp = formatQuantity(item.quantity, item.unit, item.default_quantity, item.package_unit);
                return `
                <div class="alert-item expired-item alert-item-clickable" onclick="showAlertItemDetail(${item.id}, ${item.product_id})">
                    <div class="alert-item-info">
                        <span class="alert-item-name">${locIcon ? locIcon + ' ' : ''}${escapeHtml(item.name)}</span>
                        ${item.brand ? `<span class="alert-item-brand">${escapeHtml(item.brand)}</span>` : ''}
                        <span class="alert-item-qty">📦 ${qtyDisplayExp}</span>
                    </div>
                    <div class="alert-item-badges">
                        <span class="alert-item-badge expired">${daysText}</span>
                        <span class="safety-badge safety-${safety.level}" title="${safety.tip}">${safety.icon} ${safety.label}</span>
                    </div>
                </div>`;
            }).join('');
        } else {
            expiredSection.style.display = 'none';
        }
        
        // Banner alerts (suspicious quantities + consumption predictions)
        loadBannerAlerts();

        // Anti-waste section + Nutrition section + Monthly stats: load in parallel
        const [, invForNutr, monthlyData, macroData] = await Promise.all([
            _awLoadFacts(),
            api('inventory_list').then(d => d.inventory || []).catch(() => []),
            api('monthly_stats').catch(() => null),
            api('macro_stats').catch(() => null),
        ]);
        _renderAntiWasteSection(
            statsData.used_30d      || 0, statsData.wasted_30d      || 0,
            statsData.used_prev_30d || 0, statsData.wasted_prev_30d || 0,
            statsData.used_prev_60d || 0, statsData.wasted_prev_60d || 0,
            navigator.onLine
        );
        _startAntiWasteAutoRefresh();

        // Nutrition section — built from the full inventory list
        _renderNutritionSection(invForNutr);

        // Monthly stats panel
        _renderMonthlyStatsSection(monthlyData);

        // Macronutrient panel (#118)
        _renderMacrosSection(macroData);

        _startInsightAlternation();

        // Opened (partially used products with known package capacity)
        const openedSection = document.getElementById('alert-opened');
        const openedList = document.getElementById('opened-list');
        if (statsData.opened && statsData.opened.length > 0) {
            // Sorted server-side by days_to_expiry ASC
            openedSection.style.display = 'block';
            const MAX_SHOWN = 20;
            const visible = statsData.opened.slice(0, MAX_SHOWN);
            const extra = statsData.opened.length - visible.length;
            openedList.innerHTML = visible.map(item => {
                const locInfo = LOCATIONS[item.location] || { icon: '📦', label: item.location };
                const qty = parseFloat(item.quantity);
                const pkgSize = parseFloat(item.default_quantity);
                const unitLabels = { 'ml': 'ml', 'g': 'g', 'pz': t('units.pz') };
                let qtyText = '';

                if (item.unit === 'conf') {
                    const pkgUnit = item.package_unit;
                    const pkgLabel = (pkgUnit && pkgUnit !== '') ? (unitLabels[pkgUnit] || pkgUnit) : '';
                    const wholeConf = Math.floor(qty + 0.001);
                    const frac = Math.round((qty - wholeConf) * 1000) / 1000;
                    const remainderAmt = pkgSize > 0 ? frac * pkgSize : 0;
                    // Only show remainder if it rounds to at least 1 unit
                    const remainderText = remainderAmt >= 0.5 ? formatSubRemainder(remainderAmt, pkgUnit) : '';
                    if (wholeConf > 0 && remainderText) {
                        qtyText = `${wholeConf} ${t('units.conf') || 'conf'}${pkgLabel ? ` (${t('units.from') || 'da'} ${pkgSize}${pkgLabel})` : ''} + ${remainderText}`;
                    } else if (wholeConf > 0) {
                        qtyText = `${wholeConf} ${t('units.conf') || 'conf'}${pkgLabel ? ` (${t('units.from') || 'da'} ${pkgSize}${pkgLabel})` : ''}`;
                    } else if (remainderText) {
                        qtyText = remainderAmt >= 1 ? remainderText : t('inventory.qty_trace') || '< 1' + (pkgLabel || '');
                    } else {
                        qtyText = `${Math.round(qty * 10) / 10} ${t('units.conf') || 'conf'}`;
                    }
                } else {
                    const unitLabel = unitLabels[item.unit] || item.unit || '';
                    if (!pkgSize || pkgSize <= 0) {
                        // No package size — just show raw quantity
                        qtyText = `${qty}${unitLabel}`;
                    } else {
                        const wholePackages = Math.floor(qty / pkgSize + 0.001);
                        const remainder = Math.round((qty - wholePackages * pkgSize) * 100) / 100;
                        if (wholePackages > 0 && remainder >= 1) {
                            qtyText = `${wholePackages} × ${pkgSize}${unitLabel} + ${Math.round(remainder)}${unitLabel} ${t('inventory.qty_remainder_suffix')}`;
                        } else if (remainder >= 1) {
                            qtyText = `${Math.round(remainder)}${unitLabel} / ${pkgSize}${unitLabel}`;
                        } else {
                            qtyText = `${qty}${unitLabel}`;
                        }
                    }
                }

                // Expiry badge
                const days = item.days_to_expiry;
                const isEdible = item.is_edible;
                let expiryBadge = '';
                if (days !== null && days !== undefined) {
                    let expiryClass, expiryText;
                    if (!isEdible) {
                        // Only show the red ⛔ badge for items that are genuinely dangerous.
                        // For conserve/condiments classified as safe, use a gentler amber badge.
                        const spoiledSafety = getExpiredSafety(item, Math.abs(item.days_to_expiry ?? 1));
                        if (spoiledSafety.level === 'ok') {
                            expiryClass = 'opened-expiry-soon';
                            expiryText = '\u26A0\uFE0F ' + t('expiry.badge_check_soon');
                        } else {
                            expiryClass = 'opened-expiry-spoiled';
                            expiryText = t('expiry.badge_expired');
                        }
                    } else if (days > 365) {
                        expiryClass = 'opened-expiry-ok';
                        expiryText = t('expiry.badge_stable');
                    } else if (days === 0) {
                        expiryClass = 'opened-expiry-today';
                        expiryText = t('expiry.badge_today');
                    } else if (days <= 2) {
                        expiryClass = 'opened-expiry-urgent';
                        expiryText = t('expiry.badge_expiring_short').replace('{n}', days);
                    } else if (days <= 5) {
                        expiryClass = 'opened-expiry-soon';
                        expiryText = t('expiry.badge_expiring_short').replace('{n}', days);
                    } else {
                        expiryClass = 'opened-expiry-ok';
                        expiryText = t('expiry.badge_ok_still').replace('{n}', days);
                    }
                    const vacuumNote = item.vacuum_sealed ? ' 🔒' : '';
                    expiryBadge = `<span class="alert-item-badge opened-expiry ${expiryClass}">${expiryText}${vacuumNote}</span>`;
                }

                return `
                <div class="alert-item alert-item-clickable${!isEdible ? ' alert-item-spoiled' : ''}" onclick="showAlertItemDetail(${item.id}, ${item.product_id})">
                    <div class="alert-item-info">
                        <span class="alert-item-name">${escapeHtml(item.name)}</span>
                        ${item.brand ? `<span class="alert-item-brand">${escapeHtml(item.brand)}</span>` : ''}
                    </div>
                    <div class="alert-item-badges">
                        <span class="alert-item-qty">${locInfo.icon} ${locInfo.label}</span>
                        <span class="alert-item-badge opened">${qtyText}</span>
                        ${expiryBadge}
                    </div>
                </div>`;
            }).join('') + (extra > 0 ? `<div class="alert-more-note">${t('dashboard.more_opened').replace('{n}', extra)}</div>` : '');
        } else {
            openedSection.style.display = 'none';
        }
        
    } catch (err) {
        console.error('Dashboard load error:', err);
        // Remove shimmer even on error so numbers don't disappear forever
        ['stat-dispensa', 'stat-frigo', 'stat-freezer'].forEach(id => {
            const el = document.getElementById(id);
            if (el) { el.classList.remove('stat-loading'); if (el.textContent === '') el.textContent = '-'; }
        });
    }
}

function openedFraction(item) {
    const qty = parseFloat(item.quantity);
    const pkgSize = parseFloat(item.default_quantity);
    if (item.unit === 'conf') {
        return qty - Math.floor(qty + 0.001);
    }
    return (qty - Math.floor(qty / pkgSize + 0.001) * pkgSize) / pkgSize;
}

function quickRecipeSuggestion() {
    if (!_requireGemini()) return;
    // Navigate to chat and auto-send a prompt about expiring products
    showPage('chat');
    setTimeout(() => {
        document.getElementById('chat-input').value = t('chat.quick_recipe_prompt') || 'Suggeriscimi una ricetta veloce PER UNA PERSONA usando i prodotti che scadono prima! Ignora i prodotti in freezer (hanno scadenze molto lunghe), concentrati su frigo e dispensa.';
        sendChatMessage();
    }, 500);
}

// === SUSPICIOUS QUANTITY THRESHOLDS ===
const QTY_THRESHOLDS = {
    'pz':   { min: 0.3,  max: 50 },
    'conf': { min: 0.3,  max: 50 },
    'g':    { min: 3,    max: 10000 },
    'ml':   { min: 3,    max: 10000 },
};

function isSuspiciousQty(qty, unit) {
    const n = parseFloat(qty);
    if (isNaN(n) || n <= 0) return false;
    const th = QTY_THRESHOLDS[unit] || QTY_THRESHOLDS['pz'];
    return n < th.min || n > th.max;
}

function isSuspiciousDefaultQty(defaultQty, unit, packageUnit) {
    const n = parseFloat(defaultQty);
    if (!n || n <= 0) return false;
    const checkUnit = ((unit === 'conf' || unit === 'pz') && packageUnit) ? packageUnit : unit;
    const th = QTY_THRESHOLDS[checkUnit] || QTY_THRESHOLDS['pz'];
    return n > th.max;
}

function getReviewConfirmed() {
    return _reviewConfirmedCache || {};
}
let _reviewConfirmedCache = {};
// ===== SERVER-SYNCED APP DATA CACHES =====
// Loaded at startup from app_settings (SQLite). Reads are synchronous (from cache).
// Writes update cache + fire-and-forget to server via app_settings_save.
let _shoppingTagsCache     = {};
let _pinnedBringCache      = {};
let _prefUseLocCache       = {};
let _prefMoveLocCache      = {};
let _autoAddedBringCache   = {};
let _bringBlocklistCache   = {};
let _noExpiryDismissedCache = {};
let _familySiblingConfirmedCache = {};
let _scanHistoryCache      = [];
let _shoppingPlanDaysCache = null; // null = auto (days until month end)
function _saveToServer(key, value) {
    api('app_settings_save', {}, 'POST', { settings: { [key]: value } }).catch(() => {});
}

function setReviewConfirmed(inventoryId) {
    const c = getReviewConfirmed();
    c[inventoryId] = Date.now();
    _reviewConfirmedCache = c;
    api('app_settings_save', {}, 'POST', { settings: { review_confirmed: c } }).catch(() => {});
}

/** Return map of product IDs the user has marked as "no expiry needed". */
function _getNoExpiryDismissed() {
    return _noExpiryDismissedCache || {};
}
/** Permanently mark a product as "no expiry needed" for this browser. */
function _dismissNoExpiry(productId) {
    const m = Object.assign({}, _noExpiryDismissedCache || {});
    m[String(productId)] = Date.now();
    _noExpiryDismissedCache = m;
    _saveToServer('no_expiry_dismissed', m);
}

// === ALERT BANNER SYSTEM (replaces old review table) ===
let _bannerQueue = [];   // array of { type, data } — 'review' or 'prediction'
let _bannerIndex = 0;
let _bannerLoading = false; // guard against concurrent calls
let _bannerEditPending = false;  // true when editing from banner → dismiss after save
let _bannerRefreshTimer = null;  // periodic refresh while on dashboard
let _shoppingPollTimer  = null;  // periodic refresh while on shopping page (multi-client sync)

/**
 * Load suspicious quantities + consumption predictions + expired + expiring soon,
 * merge into a single banner queue and show the first item.
 */
async function loadBannerAlerts() {
    if (_bannerLoading) return;
    _bannerLoading = true;
    _bannerQueue = [];
    _bannerIndex = 0;
    const banner = document.getElementById('alert-banner');
    if (!banner) { _bannerLoading = false; console.warn('[Banner] #alert-banner not found'); return; }

    try {
        const [invData, predData, anomalyData, dupLossData, finishedData, statsData] = await Promise.all([
            api('inventory_list'),
            api('consumption_predictions').catch(err => { console.warn('[Banner] predictions fetch failed:', err); return { predictions: [] }; }),
            api('inventory_anomalies').catch(err => { console.warn('[Banner] anomalies fetch failed:', err); return { anomalies: [] }; }),
            api('inventory_duplicate_loss_checks').catch(err => { console.warn('[Banner] duplicate loss checks fetch failed:', err); return { checks: [] }; }),
            api('inventory_finished_items').catch(err => { console.warn('[Banner] finished_items fetch failed:', err); return { finished: [] }; }),
            api('stats').catch(() => ({ opened: [] })),
        ]);
        const items = (invData.inventory || []).filter(i => parseFloat(i.quantity) > 0);
        const invById = Object.fromEntries(items.map(i => [i.id, i]));
        const openedStatsById = Object.fromEntries((statsData.opened || []).map(o => [o.id, o]));
        const confirmed = getReviewConfirmed();
        // Track item IDs already queued to prevent the same item appearing in multiple types
        const _queuedItemIds = new Set();

        // 1. Expired products (highest priority) - derived from inventory
        // Also considers opened_at: if item is opened and its opened-shelf-life has passed, it's expired too
        items.forEach(item => {
            if (!item.expiry_date && !item.opened_at) return;
            if (confirmed['exp_' + item.id]) return;

            let daysExpired = null;

            // Check raw expiry date
            if (item.expiry_date) {
                const rawDays = daysUntilExpiry(item.expiry_date);
                if (rawDays < 0) daysExpired = Math.abs(rawDays);
            }

            // Check effective expiry based on opened_at (trust server when still edible)
            if (item.opened_at) {
                const serverOi = openedStatsById[item.id];
                if (!(serverOi && serverOi.is_edible)) {
                    const openDays = estimateOpenedExpiryDays(item, item.location);
                    const openedTs = new Date(item.opened_at).getTime();
                    const effectiveExpiry = new Date(openedTs + openDays * 86400000);
                    const today = new Date(); today.setHours(0, 0, 0, 0);
                    const openedDiff = Math.round((effectiveExpiry.getTime() - today.getTime()) / 86400000);
                    if (openedDiff < 0) {
                        const openedExpiredDays = Math.abs(openedDiff);
                        if (daysExpired === null || openedExpiredDays > daysExpired) daysExpired = openedExpiredDays;
                    }
                }
            }

            if (daysExpired === null) return; // not expired by any measure
            const serverOi = openedStatsById[item.id];
            const bannerItem = { ...(serverOi ? { ...item, ...serverOi } : item), days_expired: daysExpired };
            if (!shouldShowExpiredBanner(bannerItem)) return;
            _bannerQueue.push({ type: 'expired', data: bannerItem });
            _queuedItemIds.add(item.id);
        });

        // 1b. Opened items the SERVER considers not edible (is_edible=false from stats).
        // The client-side getExpiredSafety check above uses conservative thresholds (e.g.
        // conserve are 'ok' for 30 days past), but the server uses product-specific AI shelf
        // life. Trust the server: any opened item with is_edible=false that isn't already
        // queued goes into the banner as expired — unless safety is still 'ok'.
        const openedNotEdible = (statsData.opened || []).filter(oi =>
            !oi.is_edible && !_queuedItemIds.has(oi.id) && !confirmed['exp_' + oi.id] && invById[oi.id]
        );
        openedNotEdible.forEach(oi => {
            const live = invById[oi.id];
            const daysOI = Math.abs(oi.days_to_expiry ?? 0);
            const bannerItem = { ...live, ...oi, days_expired: daysOI };
            if (!shouldShowExpiredBanner(bannerItem)) return;
            _bannerQueue.push({ type: 'expired', data: bannerItem });
            _queuedItemIds.add(oi.id);
        });

        // 2. Suspicious quantities ("expiring soon" shown only in dashboard sections, not in banner)
        // Group items by product identity to detect sibling entries in other locations.
        // A "low quantity" alert is suppressed when other stock of the same product exists
        // (e.g. 191 ml of milk in the fridge is fine if there are 11 sealed packages in the pantry).
        const _productKey = item => item.barcode || `${item.name}||${item.brand || ''}`;
        const _productGroups = {};
        items.forEach(item => {
            const k = _productKey(item);
            if (!_productGroups[k]) _productGroups[k] = [];
            _productGroups[k].push(item);
        });

        items.forEach(item => {
            if (_queuedItemIds.has(item.id)) return; // already in expired
            if (confirmed[item.id]) return;
            const t_ = QTY_THRESHOLDS[item.unit] || QTY_THRESHOLDS['pz'];
            const qty = parseFloat(item.quantity);
            let isLow  = !isNaN(qty) && qty > 0 && qty < t_.min;
            let isHigh = !isNaN(qty) && qty > t_.max;

            // For conf unit: evaluate thresholds on total sub-unit volume when possible,
            // not on raw package count. "400 conf" with no package size is uninterpretable
            // (could be grams entered with the wrong unit) — skip the high check.
            if (item.unit === 'conf') {
                const pkgSize = parseFloat(item.default_quantity);
                if (pkgSize > 0 && item.package_unit) {
                    const totalSub = qty * pkgSize;
                    const subTh = QTY_THRESHOLDS[item.package_unit] || QTY_THRESHOLDS['pz'];
                    isLow  = totalSub > 0 && totalSub < subTh.min;
                    isHigh = totalSub > subTh.max;
                } else {
                    // No package size known — can't judge quantity; suppress high-qty noise
                    isHigh = false;
                }
            }

            const suspDq = isSuspiciousDefaultQty(item.default_quantity, item.unit, item.package_unit);

            if (!isLow && !isHigh && !suspDq) return;

            // Suppress low-qty warning when sibling entries for the same product exist
            // in other locations — the user is simply tracking a partial/opened unit.
            if (isLow && !isHigh && !suspDq) {
                const siblings = (_productGroups[_productKey(item)] || []).filter(s => s.id !== item.id && parseFloat(s.quantity) > 0);
                if (siblings.length > 0) return;
            }

            let warning;
            if (suspDq && !isLow && !isHigh) warning = '📦 Conf. sospetta';
            else if (isLow) warning = '⬇️ Troppo poco';
            else warning = '⬆️ Troppo';
            _bannerQueue.push({ type: 'review', data: { ...item, warning, _isLow: isLow } });
            _queuedItemIds.add(item.id);
        });

        // 4. Consumption predictions that don't match actual quantity
        const predictions = predData.predictions || [];
        predictions.forEach(pred => {
            if (confirmed['pred_' + pred.inventory_id]) return;
            _bannerQueue.push({ type: 'prediction', data: pred });
        });

        // 5. Inventory anomalies (qty doesn't match transaction history)
        const anomalies = anomalyData.anomalies || [];
        anomalies.forEach(an => {
            if (confirmed['an_' + an.dismiss_key]) return;
            _bannerQueue.push({ type: 'anomaly', data: an });
        });

        // 6. Potentially lost products due to rapid duplicate "out" events
        const dupChecks = dupLossData.checks || [];
        dupChecks.forEach(ch => {
            if (confirmed['dup_' + ch.dismiss_key]) return;
            _bannerQueue.push({ type: 'dup_loss_check', data: ch });
        });

        // 7. Unresolved ghosts: always show while server reports ledger/stock mismatch
        const finished = finishedData.finished || [];
        finished.forEach(fin => {
            _bannerQueue.push({ type: 'finished', data: fin });
        });

        // 8. Products with no expiry date set (and not permanently dismissed)
        // Warn for ALL food/drink items — only skip igiene/pulizia (non-food).
        // Items are capped at 8 per load (opened packages first) to avoid banner overflow.
        const noExpiryDismissed = _getNoExpiryDismissed();
        const NON_FOOD_CATS = ['igiene', 'pulizia'];
        const noExpiryItems = [];
        items.forEach(item => {
            if (_queuedItemIds.has(item.id)) return; // already in expired or review
            if (item.expiry_date) return;               // already has expiry
            if (parseFloat(item.quantity) <= 0) return; // no stock
            const pid = String(item.product_id || item.id);
            if (noExpiryDismissed[pid]) return;         // user said "no expiry needed"
            const guessedCat = guessCategoryFromName(item.name || '');
            const cat = (item.category || '').toLowerCase();
            // Skip non-food categories
            if (NON_FOOD_CATS.includes(guessedCat) ||
                NON_FOOD_CATS.some(c => cat.includes(c))) return;
            noExpiryItems.push(item);
        });
        // Sort: opened packages first (more urgent), then alphabetically
        noExpiryItems.sort((a, b) => {
            if (!!a.opened_at !== !!b.opened_at) return a.opened_at ? -1 : 1;
            return (a.name || '').localeCompare(b.name || '');
        });
        noExpiryItems.slice(0, 8).forEach(item => {
            _bannerQueue.push({ type: 'no_expiry', data: item });
        });

        // Drop expired banners that are still safe (ok) — opened items stay in dashboard list only
        _bannerQueue = _bannerQueue.filter(entry =>
            entry.type !== 'expired' || shouldShowExpiredBanner(entry.data)
        );

        // Sort by priority (highest first)
        _bannerQueue.sort((a, b) => _bannerPriority(b) - _bannerPriority(a));

        console.log(`[Banner] queue ready: ${_bannerQueue.length} items (${items.length} inv, ${predictions.length} pred, ${Object.keys(confirmed).length} confirmed)`);

    } catch (e) {
        console.error('[Banner] loadBannerAlerts error:', e);
    } finally {
        _bannerLoading = false;
    }

    if (_bannerQueue.length > 0) {
        _bannerIndex = 0;
        renderBannerItem();
        initBannerSwipe();
    } else {
        banner.style.display = 'none';
    }
}

/**
 * Compute a numeric priority score for a banner item.
 * Higher = more important = shown first.
 *
 * Priority tiers:
 *   1000+ : expired (longer ago = higher)
 *   500-799: anomalies (data discrepancies)
 *   200-499: suspicious quantities (low stock > high stock > package)
 *   100-199: consumption predictions (higher deviation% = higher)
 */
function _bannerPriority(entry) {
    switch (entry.type) {
        case 'expired': {
            const d = entry.data.days_expired || 0;
            // Expired longer = more urgent; base 1000 + days (capped)
            return 1000 + Math.min(d, 500);
        }
        case 'review': {
            const w = entry.data.warning || '';
            // Low stock is more urgent than too-much
            if (w.includes('Troppo poco')) return 400;
            if (w.includes('Troppo')) return 300;
            return 200; // package suspicion
        }
        case 'prediction': {
            const dev = entry.data.deviation_pct || 0;
            // Higher deviation = more important, capped at 99
            return 100 + Math.min(dev, 99);
        }
        case 'anomaly': {
            // Phantom (inflated qty) = 250, Missing = 260 (slightly higher, means data is clearly wrong)
            return entry.data.direction === 'missing' ? 260 : 250;
        }
        case 'dup_loss_check':
            return 700; // high-priority check: likely double-consume loss
        case 'finished':
            return 600; // product ran out — confirm before removing from DB
        case 'no_expiry':
            return 30; // low priority: informational, show after everything else
        default:
            return 0;
    }
}

function renderBannerItem() {
    const banner = document.getElementById('alert-banner');
    if (!banner || _bannerQueue.length === 0) { if (banner) banner.style.display = 'none'; return; }
    if (_bannerIndex >= _bannerQueue.length) _bannerIndex = 0;

    const entry = _bannerQueue[_bannerIndex];
    const iconEl    = document.getElementById('alert-banner-icon');
    const titleEl   = document.getElementById('alert-banner-title');
    const detailEl  = document.getElementById('alert-banner-detail');
    const actionsEl = document.getElementById('alert-banner-actions');
    const counterEl = document.getElementById('alert-banner-counter');
    const s = getSettings();
    const hasScale = s.scale_enabled && s.scale_gateway_url && _scaleConnected;

    if (entry.type === 'expired') {
        const item = entry.data;
        const qtyDisplay = formatQuantity(item.quantity, item.unit, item.default_quantity, item.package_unit);
        const isOpenedExpiry = !!item.opened_at;
        const safety = getExpiredSafety(item, item.days_expired);

        let daysText, suffix;
        if (isOpenedExpiry) {
            const todayMs = new Date(); todayMs.setHours(0, 0, 0, 0);
            const daysSinceOpened = Math.round((todayMs - new Date(item.opened_at)) / 86400000);
            daysText = daysSinceOpened === 0
                ? t('expiry.opened_today_long')
                : t('expiry.opened_ago_long').replace('{n}', daysSinceOpened);
            suffix = safety.level === 'ok'
                ? t('expiry.opened_suffix_ok')
                : safety.level === 'warning'
                    ? t('expiry.opened_suffix_warning')
                    : t('expiry.opened_suffix');
        } else {
            daysText = item.days_expired === 0
                ? t('expiry.expired_today_long')
                : t('expiry.expired_ago_long').replace('{n}', item.days_expired);
            suffix = safety.level === 'ok'
                ? t('expiry.expired_suffix_ok')
                : safety.level === 'warning'
                    ? t('expiry.expired_suffix_warning')
                    : t('expiry.expired_suffix');
        }

        if (safety.level === 'danger') {
            banner.className = 'alert-banner banner-expired banner-expired-danger';
            iconEl.textContent = '🚫';
        } else if (safety.level === 'warning') {
            banner.className = 'alert-banner banner-expired banner-expired-warning';
            iconEl.textContent = '👀';
        } else {
            banner.className = 'alert-banner banner-expired banner-expired-ok';
            iconEl.textContent = '✅';
        }
        titleEl.textContent = `${item.name}${item.brand ? ' (' + item.brand + ')' : ''} ${suffix}`;

        let baseDetail;
        if (isOpenedExpiry) {
            const locLabel = (LOCATIONS[item.location]
                ? LOCATIONS[item.location].icon + ' ' + LOCATIONS[item.location].label
                : (item.location || ''));
            baseDetail = t('dashboard.banner_opened_detail')
                .replace('{when}', daysText)
                .replace('{location}', escapeHtml(locLabel))
                .replace('{qty}', qtyDisplay);
        } else {
            baseDetail = t('dashboard.banner_expired_detail').replace('{when}', daysText).replace('{qty}', qtyDisplay);
            const locationTag = item.location ? ` · <strong>${escapeHtml(item.location)}</strong>` : '';
            const expiryTag = item.expiry_date ? ` · ${escapeHtml(item.expiry_date)}` : '';
            baseDetail += locationTag + expiryTag;
        }
        detailEl.innerHTML = `${baseDetail} <span class="banner-safety-tip banner-safety-${safety.level}">${safety.icon} ${safety.tip}</span>`;
        let btns = '';
        btns += `<button class="btn-banner btn-banner-finish" onclick="bannerFinishAll()">${t('dashboard.banner_expired_action_finished')}</button>`;
        if (!isOpenedExpiry && safety.level !== 'danger') {
            btns += `<button class="btn-banner btn-banner-use" onclick="bannerQuickUse()">${t('dashboard.banner_expired_action_use')}</button>`;
        }
        btns += `<button class="btn-banner btn-banner-throw" onclick="bannerThrowAway()">${t('dashboard.banner_expired_action_throw')}</button>`;
        // "Modifica" — opens full edit modal (includes date correction)
        btns += `<button class="btn-banner btn-banner-edit2" onclick="editReviewItem(${item.id}, ${item.product_id})">${t('dashboard.banner_expired_action_modify')}</button>`;
        if (isOpenedExpiry && !item.vacuum_sealed) {
            // Offer to re-seal with vacuum — extends shelf life
            btns += `<button class="btn-banner btn-banner-vacuum" onclick="bannerMarkVacuum()">${t('dashboard.banner_expired_action_vacuum')}</button>`;
        }
        if (!isOpenedExpiry && safety.level === 'danger') {
            btns += `<button class="btn-banner btn-banner-use btn-banner-use-danger" onclick="bannerQuickUse()">${t('dashboard.banner_expired_action_use')}</button>`;
        }
        if (!isOpenedExpiry) {
            btns += `<button class="btn-banner btn-banner-ok" onclick="dismissBannerExpired()">${t('dashboard.banner_review_dismiss')}</button>`;
        }
        actionsEl.innerHTML = btns;

    } else if (entry.type === 'review') {
        const item = entry.data;
        // For conf unit with known package size, display the sub-unit total (e.g., 800g)
        // instead of a raw conf count that could be confused with "N confezioni".
        let qtyDisplay;
        if (item.unit === 'conf' && parseFloat(item.default_quantity) > 0 && item.package_unit) {
            const totalSub = Math.round(parseFloat(item.quantity) * parseFloat(item.default_quantity));
            qtyDisplay = `${totalSub} ${item.package_unit}`;
        } else {
            qtyDisplay = formatQuantity(item.quantity, item.unit, item.default_quantity, item.package_unit);
        }
        const suspDq = isSuspiciousDefaultQty(item.default_quantity, item.unit, item.package_unit);
        const isLow  = !!item._isLow; // set when banner item was built
        const t_ = QTY_THRESHOLDS[item.unit] || QTY_THRESHOLDS['pz'];
        banner.className = 'alert-banner';
        iconEl.textContent = '⚠️';
        let titleText, detailText;
        if (suspDq && !isLow) {
            titleText = `${t('dashboard.banner_review_unusual_pkg_title')}: ${item.name}${item.brand ? ' (' + item.brand + ')' : ''}`;
            detailText = t('dashboard.banner_review_unusual_pkg_detail', { qty: item.default_quantity, unit: item.package_unit });
        } else if (isLow) {
            titleText = `${t('dashboard.banner_review_low_qty_title')}: ${item.name}${item.brand ? ' (' + item.brand + ')' : ''}`;
            detailText = t('dashboard.banner_review_low_qty_detail', { qty: qtyDisplay });
        } else {
            titleText = `${t('dashboard.banner_review_high_qty_title')}: ${item.name}${item.brand ? ' (' + item.brand + ')' : ''}`;
            detailText = t('dashboard.banner_review_high_qty_detail', { qty: qtyDisplay });
        }
        titleEl.textContent = titleText;
        detailEl.textContent = detailText;
        let btns = `<button class="btn-banner btn-banner-ok" onclick="confirmBannerReview()">${t('dashboard.banner_review_action_ok')}</button>`;
        if (isLow) {
            btns += `<button class="btn-banner btn-banner-finish" onclick="bannerFinishAll()">${t('dashboard.banner_review_action_finish')}</button>`;
        }
        btns += `<button class="btn-banner btn-banner-edit" onclick="editBannerReview()">${t('dashboard.banner_review_action_edit')}</button>`;
        if (hasScale) {
            btns += `<button class="btn-banner btn-banner-weigh" onclick="weighBannerItem()">${t('dashboard.banner_review_action_weigh')}</button>`;
        }
        actionsEl.innerHTML = btns;

    } else if (entry.type === 'prediction') {
        const pred = entry.data;
        const dir = pred.direction || 'less';
        const dailyRate = parseFloat(pred.daily_rate) || 0;
        const daysSince = parseInt(pred.days_since_restock) || 0;
        banner.className = 'alert-banner banner-prediction';
        iconEl.textContent = '📊';
        titleEl.textContent = `${t('dashboard.banner_prediction_title')}: ${pred.name}${pred.brand ? ' (' + pred.brand + ')' : ''}`;
        let rateText = '';
        if (dailyRate > 0) {
            rateText = dailyRate >= 1
                ? t('dashboard.banner_prediction_rate_day', { n: Math.round(dailyRate), unit: pred.unit })
                : t('dashboard.banner_prediction_rate_week', { n: Math.round(dailyRate * 7), unit: pred.unit });
        }
        const timeText = daysSince > 0 ? ` — ${t('dashboard.banner_prediction_days_ago', { n: daysSince })}` : '';
        let diffText;
        if (dir === 'more') {
            diffText = t('dashboard.banner_prediction_more', { expected: pred.expected_qty, unit: pred.unit, time: timeText, actual: pred.actual_qty });
        } else {
            diffText = t('dashboard.banner_prediction_less', { expected: pred.expected_qty, unit: pred.unit, time: timeText, actual: pred.actual_qty });
        }
        detailEl.innerHTML = rateText ? `${rateText}: ${diffText}` : diffText.charAt(0).toUpperCase() + diffText.slice(1);
        let btns = `<button class="btn-banner btn-banner-confirm" onclick="confirmBannerPrediction()">${t('dashboard.banner_prediction_action_confirm', { qty: pred.actual_qty, unit: pred.unit })}</button>`;
        btns += `<button class="btn-banner btn-banner-edit" onclick="editBannerPrediction()">${t('dashboard.banner_prediction_action_edit')}</button>`;
        if (hasScale) {
            btns += `<button class="btn-banner btn-banner-weigh" onclick="weighBannerItem()">${t('dashboard.banner_prediction_action_weigh')}</button>`;
        }
        actionsEl.innerHTML = btns;

    } else if (entry.type === 'finished') {
        const fin = entry.data;
        banner.className = 'alert-banner banner-finished';
        iconEl.textContent = fin.vanished ? '👻' : '📦';
        const barcodeSuffix = fin.barcode && fin.barcode.length >= 3
            ? ` <span style="font-family:monospace;font-size:0.7em;opacity:0.6">…${escapeHtml(fin.barcode.slice(-3))}</span>`
            : '';
        titleEl.innerHTML = `${escapeHtml(fin.name)}${fin.brand ? ' (' + escapeHtml(fin.brand) + ')' : ''}${barcodeSuffix} — ${escapeHtml(t('dashboard.banner_finished_title'))}`;
        const expectedText = fin.expected_qty ? ' ' + t('dashboard.banner_finished_expected', { qty: fin.expected_qty, unit: fin.unit }) : '';
        const baseText = fin.vanished ? t('dashboard.banner_finished_vanished') : t('dashboard.banner_finished_zero');
        detailEl.innerHTML = baseText + expectedText + ' ' + t('dashboard.banner_finished_check');
        let btns = `<button class="btn-banner btn-banner-ok" onclick="confirmBannerFinished()">${t('dashboard.banner_finished_action_yes')}</button>`;
        btns += `<button class="btn-banner btn-banner-edit" onclick="notFinishedBannerAction()">${t('dashboard.banner_finished_action_restore', { qty: fin.expected_qty, unit: fin.unit })}</button>`;
        actionsEl.innerHTML = btns;

    } else if (entry.type === 'anomaly') {
        const an = entry.data;
        const isPhantom = an.direction === 'phantom';
        banner.className = 'alert-banner banner-anomaly';
        iconEl.textContent = '🔍';
        if (isPhantom) {
            titleEl.textContent = `${an.name} — ${t('dashboard.banner_anomaly_phantom_title')}`;
            detailEl.innerHTML = t('dashboard.banner_anomaly_phantom_detail', { inv_qty: an.inv_qty, unit: an.unit, expected_qty: an.expected_qty });
        } else {
            titleEl.textContent = `${an.name} — ${t('dashboard.banner_anomaly_ghost_title')}`;
            detailEl.innerHTML = t('dashboard.banner_anomaly_ghost_detail', { expected_qty: an.expected_qty, unit: an.unit, name: an.name, inv_qty: an.inv_qty });
        }
        let btns = `<button class="btn-banner btn-banner-edit" onclick="editBannerAnomaly()">${t('dashboard.banner_anomaly_action_edit')}</button>`;
        btns += `<button class="btn-banner btn-banner-ok" onclick="dismissBannerAnomaly()">${t('dashboard.banner_anomaly_action_dismiss')} (${an.inv_qty} ${an.unit})</button>`;
        if (_geminiAvailable) {
            btns += `<button class="btn-banner btn-banner-ai" onclick="explainBannerAnomaly()" title="${t('dashboard.banner_explain_title')}">\ud83e\udd16 ${t('dashboard.banner_explain_btn')}</button>`;
        }
        actionsEl.innerHTML = btns;

    } else if (entry.type === 'dup_loss_check') {
        const ch = entry.data;
        banner.className = 'alert-banner banner-dup-loss';
        iconEl.textContent = '🧪';

        const locInfo = LOCATIONS[ch.location] || { icon: '📦', label: ch.location || '—' };
        const locText = `${locInfo.icon} ${locInfo.label}`;
        const qtyPair = `${ch.q1} + ${ch.q2}`;

        titleEl.textContent = t('dashboard.banner_dup_loss_title').replace('{name}', ch.name);
        detailEl.textContent = t('dashboard.banner_dup_loss_detail')
            .replace('{location}', locText)
            .replace('{seconds}', Math.round(ch.dt_sec || 0))
            .replace('{qty_pair}', qtyPair);

        let btns = '';
        if (ch.inventory_id && ch.inventory_id > 0) {
            btns += `<button class="btn-banner btn-banner-edit" onclick="editReviewItem(${ch.inventory_id}, ${ch.product_id})">${t('dashboard.banner_dup_loss_action_fix')}</button>`;
        } else {
            btns += `<button class="btn-banner btn-banner-edit" onclick="openDuplicateLossCheck(${ch.product_id})">${t('dashboard.banner_dup_loss_action_open')}</button>`;
        }
        btns += `<button class="btn-banner btn-banner-ok" onclick="dismissDuplicateLossCheck()">${t('dashboard.banner_dup_loss_action_done')}</button>`;
        actionsEl.innerHTML = btns;

    } else if (entry.type === 'no_expiry') {
        const item = entry.data;
        banner.className = 'alert-banner banner-no-expiry';
        iconEl.textContent = '📅';
        titleEl.textContent = t('dashboard.banner_no_expiry_title').replace('{name}', item.name + (item.brand ? ' (' + item.brand + ')' : ''));
        detailEl.textContent = t('dashboard.banner_no_expiry_detail');
        const pid = item.product_id || item.id;
        let btns = `<button class="btn-banner btn-banner-edit" onclick="editBannerNoExpiry()">${t('dashboard.banner_no_expiry_action_set')}</button>`;
        btns += `<button class="btn-banner btn-banner-ok" onclick="confirmNoExpiryNeeded(${pid})">${t('dashboard.banner_no_expiry_action_dismiss')}</button>`;
        actionsEl.innerHTML = btns;
    }

    if (_bannerQueue.length > 1) {
        let dots = `<span class="banner-nav-arrow" onclick="bannerPrev()">‹</span>`;
        dots += _bannerQueue.map((_, i) =>
            `<span class="banner-dot${i === _bannerIndex ? ' active' : ''}" onclick="_bannerIndex=${i};renderBannerItem()"></span>`
        ).join('');
        dots += `<span class="banner-nav-arrow" onclick="bannerNext()">›</span>`;
        counterEl.innerHTML = dots;
    } else {
        counterEl.innerHTML = '';
    }
    banner.style.display = '';
}

function dismissBannerItem() {
    _bannerQueue.splice(_bannerIndex, 1);
    if (_bannerQueue.length === 0) {
        document.getElementById('alert-banner').style.display = 'none';
        return;
    }
    if (_bannerIndex >= _bannerQueue.length) _bannerIndex = 0;
    renderBannerItem();
}

function confirmBannerReview() {
    const entry = _bannerQueue[_bannerIndex];
    if (!entry || entry.type !== 'review') return;
    setReviewConfirmed(entry.data.id);
    showToast(t('toast.quantity_confirmed'), 'success');
    dismissBannerItem();
}

function confirmNoExpiryNeeded(productId) {
    _dismissNoExpiry(productId);
    showToast(t('dashboard.banner_no_expiry_toast_dismissed'), 'success');
    dismissBannerItem();
}

function editBannerNoExpiry() {
    const entry = _bannerQueue[_bannerIndex];
    if (!entry || entry.type !== 'no_expiry') return;
    _bannerEditPending = true;
    api('inventory_list').then(data => {
        currentInventory = data.inventory || [];
        editInventoryItem(entry.data.id);
    });
}

function editBannerReview() {
    const entry = _bannerQueue[_bannerIndex];
    if (!entry || entry.type !== 'review') return;
    _bannerEditPending = true;
    editReviewItem(entry.data.id, entry.data.product_id);
}

function confirmBannerPrediction() {
    const entry = _bannerQueue[_bannerIndex];
    if (!entry || entry.type !== 'prediction') return;
    setReviewConfirmed('pred_' + entry.data.inventory_id);
    showToast(t('dashboard.banner_prediction_confirmed'), 'success');
    dismissBannerItem();
}

function editBannerPrediction() {
    const entry = _bannerQueue[_bannerIndex];
    if (!entry || entry.type !== 'prediction') return;
    _bannerEditPending = true;
    editReviewItem(entry.data.inventory_id, entry.data.product_id);
}

async function explainBannerAnomaly() {
    if (!_requireGemini()) return;
    const entry = _bannerQueue[_bannerIndex];
    if (!entry || entry.type !== 'anomaly') return;
    const an = entry.data;

    // Show loading inline in the banner detail area
    const detailEl = document.getElementById('alert-banner-detail');
    if (!detailEl) return;
    const originalHtml = detailEl.innerHTML;
    detailEl.innerHTML = `<em style="opacity:0.7">${t('dashboard.banner_analyzing')}</em>`;

    // Disable the Spiega button to prevent double calls
    const explainBtn = document.querySelector('#alert-banner .btn-banner-ai');
    if (explainBtn) explainBtn.disabled = true;

    try {
        const result = await api('gemini_anomaly_explain', {}, 'POST', {
            name:         an.name,
            inv_qty:      an.inv_qty,
            expected_qty: an.expected_qty,
            diff:         an.diff,
            direction:    an.direction,
            unit:         an.unit,
            lang:         _currentLang,
        });

        if (result.success && result.explanation) {
            detailEl.innerHTML = `<span style="font-size:0.85rem">\ud83e\udd16 ${escapeHtml(result.explanation)}</span>`;
        } else {
            detailEl.innerHTML = originalHtml;
            showToast(t('dashboard.banner_anomaly_explain_fail'), 'error');
        }
    } catch (e) {
        detailEl.innerHTML = originalHtml;
        showToast(t('error.generic'), 'error');
    }
}

function editBannerAnomaly() {
    const entry = _bannerQueue[_bannerIndex];
    if (!entry || entry.type !== 'anomaly') return;
    _bannerEditPending = true;
    editReviewItem(entry.data.inventory_id, entry.data.product_id);
}

function dismissBannerAnomaly() {
    const entry = _bannerQueue[_bannerIndex];
    if (!entry || entry.type !== 'anomaly') return;
    const key = entry.data.dismiss_key;
    setReviewConfirmed('an_' + key);
    api('dismiss_anomaly', {}, 'POST', { dismiss_key: key }).catch(() => {});
    showToast(t('dashboard.banner_anomaly_dismissed'), 'info');
    dismissBannerItem();
}

function dismissDuplicateLossCheck() {
    const entry = _bannerQueue[_bannerIndex];
    if (!entry || entry.type !== 'dup_loss_check') return;
    const key = entry.data.dismiss_key;
    setReviewConfirmed('dup_' + key);
    showToast(t('dashboard.banner_dup_loss_toast_done'), 'success');
    dismissBannerItem();
}

async function openDuplicateLossCheck(productId) {
    showLoading(true);
    try {
        const data = await api('product_get', { id: productId });
        if (data.product) {
            currentProduct = data.product;
            showProductAction();
        } else {
            showToast(t('error.not_found'), 'error');
        }
    } catch (e) {
        showToast(t('error.connection'), 'error');
    } finally {
        showLoading(false);
    }
}

function weighBannerItem() {
    const entry = _bannerQueue[_bannerIndex];
    if (!entry) return;
    _bannerEditPending = true;
    const item = entry.data;
    const targetId = entry.type === 'prediction' ? item.inventory_id : item.id;
    // Navigate to edit form and auto-start scale reading
    api('inventory_list').then(data => {
        currentInventory = data.inventory || [];
        editInventoryItem(targetId);
        setTimeout(() => readScaleForEdit(), 200);
    });
}

function editReviewItem(inventoryId, productId) {
    api('inventory_list').then(data => {
        currentInventory = data.inventory || [];
        editInventoryItem(inventoryId);
    });
}

// --- Banner handlers for expired & expiring ---
function bannerQuickUse() {
    const entry = _bannerQueue[_bannerIndex];
    if (!entry) return;
    const item = entry.data;
    quickUse(item.product_id, item.location);
    dismissBannerItem();
}

function bannerThrowAway() {
    const entry = _bannerQueue[_bannerIndex];
    if (!entry) return;
    const item = entry.data;
    // Populate currentProduct so the shared showThrowForm / throwAll / throwPartial work
    currentProduct = {
        id: item.product_id,
        name: item.name,
        brand: item.brand || '',
        image_url: item.image_url || null,
        category: item.category || '',
        unit: item.unit || 'pz',
        default_quantity: item.default_quantity || 0,
        package_unit: item.package_unit || ''
    };
    showThrowForm();
}

async function bannerMarkVacuum() {
    const entry = _bannerQueue[_bannerIndex];
    if (!entry || entry.type !== 'expired') return;
    const item = entry.data;
    if (item.vacuum_sealed) return; // already sealed

    // Calculate new expiry: opened_at + opened_shelf_life_days_with_vacuum
    let newExpiry = null;
    if (item.opened_at) {
        // estimateOpenedExpiryDays returns days without vacuum; add 50% for vacuum sealed
        const baseDays = estimateOpenedExpiryDays(
            { name: item.name, category: item.category || '' },
            item.location
        );
        const vacuumDays = Math.round(baseDays * 1.5);
        const d = new Date(item.opened_at);
        d.setDate(d.getDate() + vacuumDays);
        newExpiry = d.toISOString().slice(0, 10);
    }

    const body = { id: item.id, vacuum_sealed: 1 };
    if (newExpiry) body.expiry_date = newExpiry;

    try {
        const res = await api('inventory_update', {}, 'POST', body);
        if (res.success || res.ok) {
            showToast(t('toast.vacuum_sealed', { name: item.name }), 'success');
            dismissBannerItem();
            loadDashboard();
        } else {
            showToast(res.error || t('error.generic'), 'error');
        }
    } catch(e) {
        showToast(t('error.connection'), 'error');
    }
}

function bannerFinishAll() {
    const entry = _bannerQueue[_bannerIndex];
    if (!entry) return;
    const item = entry.data;
    dismissBannerItem();
    api('inventory_use', {}, 'POST', {
        product_id: item.product_id,
        use_all: true,
        location: '__all__',
    }).then(res => {
        if (res.success) {
            const msg = res.already_empty
                ? t('toast.already_exhausted_confirmed').replace('{name}', item.name)
                : t('toast.finished_all').replace('{name}', item.name);
            showToast(msg, 'success');
            showLowStockBringPrompt(res, () => loadDashboard());
        } else {
            showToast(res.error || t('error.generic'), 'error');
        }
    }).catch(() => showToast(t('error.connection'), 'error'));
}

function editBannerExpiry() {
    const entry = _bannerQueue[_bannerIndex];
    if (!entry || (entry.type !== 'expired' && entry.type !== 'expiring')) return;
    _bannerEditPending = true;
    editReviewItem(entry.data.id, entry.data.product_id);
}

function dismissBannerExpired() {
    const entry = _bannerQueue[_bannerIndex];
    if (!entry || entry.type !== 'expired') return;
    setReviewConfirmed('exp_' + entry.data.id);
    dismissBannerItem();
}

function dismissBannerExpiring() {
    const entry = _bannerQueue[_bannerIndex];
    if (!entry || entry.type !== 'expiring') return;
    setReviewConfirmed('exps_' + entry.data.id);
    dismissBannerItem();
}

async function confirmBannerFinished() {
    const entry = _bannerQueue[_bannerIndex];
    if (!entry || entry.type !== 'finished') return;
    const productId = entry.data.product_id;
    try {
        const res = await api('inventory_confirm_finished', {}, 'POST', { product_id: productId });
        if (res.bring?.added || res.bring?.updated) {
            showToast(t('toast.finished_to_bring'), 'info');
            loadShoppingList();
        }
    } catch(e) {}
    showToast(t('toast.product_finished_confirmed'), 'success');
    dismissBannerItem();
    if (typeof loadDashboard === 'function') loadDashboard();
}

async function notFinishedBannerAction() {
    const entry = _bannerQueue[_bannerIndex];
    if (!entry || entry.type !== 'finished') return;
    const fin = entry.data;
    const qty = fin.expected_qty;
    const customQty = prompt(
        t('dashboard.banner_finished_restore_prompt', { name: fin.name, qty, unit: fin.unit }),
        String(qty)
    );
    if (customQty === null) return;
    const parsed = parseFloat(String(customQty).replace(',', '.'));
    if (isNaN(parsed) || parsed <= 0) {
        showToast(t('error.invalid_quantity'), 'error');
        return;
    }
    showLoading(true);
    try {
        await api('inventory_restore_ghost', {}, 'POST', {
            product_id: fin.product_id,
            quantity: parsed,
            location: fin.location || 'dispensa',
        });
        showToast(t('toast.ghost_restored', { name: fin.name, qty: parsed, unit: fin.unit }), 'success');
        dismissBannerItem();
        if (typeof loadDashboard === 'function') loadDashboard();
    } catch (e) {
        showToast(t('error.connection'), 'error');
    } finally {
        showLoading(false);
    }
}

// --- Banner swipe navigation ---
let _bannerTouchStartX = 0;
let _bannerTouchStartY = 0;
let _bannerSwiping = false;

function initBannerSwipe() {
    const banner = document.getElementById('alert-banner');
    if (!banner || banner._swipeInit) return;
    banner._swipeInit = true;

    banner.addEventListener('touchstart', e => {
        if (_bannerQueue.length <= 1) return;
        const touch = e.touches[0];
        _bannerTouchStartX = touch.clientX;
        _bannerTouchStartY = touch.clientY;
        _bannerSwiping = true;
    }, { passive: true });

    banner.addEventListener('touchend', e => {
        if (!_bannerSwiping || _bannerQueue.length <= 1) return;
        _bannerSwiping = false;
        const touch = e.changedTouches[0];
        const dx = touch.clientX - _bannerTouchStartX;
        const dy = touch.clientY - _bannerTouchStartY;
        // Only horizontal swipes (at least 40px, and more horizontal than vertical)
        if (Math.abs(dx) < 40 || Math.abs(dy) > Math.abs(dx)) return;
        if (dx < 0) bannerNext();
        else bannerPrev();
    }, { passive: true });
}

function bannerNext() {
    if (_bannerQueue.length <= 1) return;
    const banner = document.getElementById('alert-banner');
    banner.classList.remove('banner-slide-left', 'banner-slide-right');
    void banner.offsetWidth; // force reflow
    _bannerIndex = (_bannerIndex + 1) % _bannerQueue.length;
    banner.classList.add('banner-slide-left');
    renderBannerItem();
}

function bannerPrev() {
    if (_bannerQueue.length <= 1) return;
    const banner = document.getElementById('alert-banner');
    banner.classList.remove('banner-slide-left', 'banner-slide-right');
    void banner.offsetWidth;
    _bannerIndex = (_bannerIndex - 1 + _bannerQueue.length) % _bannerQueue.length;
    banner.classList.add('banner-slide-right');
    renderBannerItem();
}

// Group items by local category and render with category headers
function renderGroupedByCategory(items, compact = false) {
    const catGroups = {};
    items.forEach(item => {
        const localCat = mapToLocalCategory(item.category, item.name);
        if (!catGroups[localCat]) catGroups[localCat] = [];
        catGroups[localCat].push(item);
    });
    
    // Sort categories: use CATEGORY_ICONS key order
    const catOrder = Object.keys(CATEGORY_ICONS);
    const sortedCats = Object.keys(catGroups).sort((a, b) => {
        const ia = catOrder.indexOf(a);
        const ib = catOrder.indexOf(b);
        return (ia === -1 ? 999 : ia) - (ib === -1 ? 999 : ib);
    });
    
    let html = '';
    for (const cat of sortedCats) {
        const catItems = catGroups[cat];
        const label = CATEGORY_LABELS[cat] || '📦 Altro';
        html += `<div class="cat-group-header">${label} <span class="cat-group-count">${catItems.length}</span></div>`;
        html += catItems.map(item => compact ? renderDashItem(item) : renderInventoryItem(item)).join('');
    }
    return html;
}

function renderDashItem(item) {
    const catIcon = CATEGORY_ICONS[mapToLocalCategory(item.category, item.name)] || '📦';
    const days = daysUntilExpiry(item.expiry_date);
    const isExpired = days < 0;
    const isExpiring = !isExpired && days <= 7;
    const parts = formatQuantityParts(item.quantity, item.unit, item.default_quantity, item.package_unit);
    
    let expiryLabel = '';
    if (item.expiry_date) {
        if (days < 0) expiryLabel = t('expiry.badge_expired_ago').replace('{n}', Math.abs(days));
        else if (days === 0) expiryLabel = t('expiry.badge_today');
        else if (days === 1) expiryLabel = t('expiry.badge_tomorrow_long');
        else if (days <= 7) expiryLabel = t('expiry.badge_days').replace('{n}', days);
        else expiryLabel = formatDate(item.expiry_date);
    }
    
    return `
    <div class="inventory-item compact-item" onclick="dashItemTap(${item.id}, ${item.product_id})">
        <div class="inv-image">
            ${item.image_url ? `<img src="${escapeHtml(item.image_url)}" alt="" onerror="this.parentElement.innerHTML='${catIcon}'">` : catIcon}
        </div>
        <div class="inv-info">
            <div class="inv-name">${escapeHtml(item.name)}</div>
            ${item.brand ? `<div class="inv-brand">${escapeHtml(item.brand)}</div>` : ''}
        </div>
        <div class="inv-qty-right">
            <span class="inv-qty-value">${parts.mainQty} <small>${parts.unitLabel}</small></span>
            ${parts.packageDetail ? `<span class="inv-qty-pkg-detail">${parts.packageDetail}</span>` : ''}
            ${expiryLabel ? `<span class="inv-expiry-small ${isExpired ? 'expired' : isExpiring ? 'expiring' : ''}">${expiryLabel}</span>` : ''}
        </div>
    </div>`;
}

function dashItemTap(inventoryId, productId) {
    // Load full inventory so modal works
    api('inventory_list').then(data => {
        currentInventory = data.inventory || [];
        showItemDetail(inventoryId, productId);
    });
}

function showAlertItemDetail(inventoryId, productId) {
    // Load full inventory so modal works (same pattern as dashItemTap)
    api('inventory_list').then(data => {
        currentInventory = data.inventory || [];
        showItemDetail(inventoryId, productId);
    });
}

function formatSubRemainder(amt, pkgUnit) {
    const uL = { 'g': 'g', 'ml': 'ml' };
    if (pkgUnit === 'ml' || pkgUnit === 'g') return `${Math.round(amt)}${uL[pkgUnit] || pkgUnit}`;
    return `${Math.round(amt * 10) / 10}${uL[pkgUnit] || pkgUnit}`;
}

function _pzFractionLabel(n) {
    const whole = Math.floor(n);
    const frac = Math.round((n - whole) * 4) / 4; // nearest quarter
    const fracMap = { 0.25: '¼', 0.5: '½', 0.75: '¾' };
    const fracStr = fracMap[frac] || '';
    if (whole === 0) return fracStr || '0';
    return `${whole}${fracStr}`;
}

function formatQuantity(qty, unit, defaultQty, packageUnit) {
    if (!qty && qty !== 0) return '';
    const n = parseFloat(qty);
    const unitLabels = { 'pz': t('units.pz'), 'g': 'g', 'ml': 'ml', 'conf': t('units.conf') };
    const label = unitLabels[unit] || unit || t('units.pz');

    // Special handling for conf with partial packages
    if (unit === 'conf' && packageUnit && defaultQty > 0) {
        const pkgLabel = unitLabels[packageUnit] || packageUnit;
        const wholeConf = Math.floor(n + 0.001);
        const fractionalConf = Math.round((n - wholeConf) * 1000) / 1000;

        if (fractionalConf < 0.01) {
            return `${wholeConf} ${t('units.conf') || 'conf'} <span class="conf-size-info">(${t('units.from') || 'da'} ${defaultQty}${pkgLabel})</span>`;
        }
        const remainderText = formatSubRemainder(fractionalConf * defaultQty, packageUnit);
        if (wholeConf > 0) {
            return `${wholeConf} ${t('units.conf') || 'conf'} <span class="conf-size-info">(${t('units.from') || 'da'} ${defaultQty}${pkgLabel})</span> + ${remainderText}`;
        }
        return remainderText;
    }

    let result;
    if (n === Math.floor(n)) result = `${Math.floor(n)} ${label}`;
    else if (unit === 'pz') result = `${_pzFractionLabel(n)} ${label}`;
    else result = `${n.toFixed(1)} ${label}`;
    return result;
}

/** Human-readable unit label for quantity inputs (pz, g, ml, conf…). */
function getUnitDisplayLabel(unit) {
    const u = (unit || 'pz').toLowerCase();
    const map = {
        pz: t('units.pz') || 'pz',
        g: 'g',
        ml: 'ml',
        conf: t('units.conf') || 'conf',
        kg: 'kg',
        l: 'l',
    };
    return map[u] || u;
}

/** Wrap qty input with a visible unit badge if missing. */
function ensureQtyUnitBadge(inputId) {
    const input = document.getElementById(inputId);
    if (!input) return null;
    let badge = document.getElementById(inputId + '-unit');
    if (badge) return badge;
    const control = input.closest('.qty-control');
    if (!control) return null;
    let wrap = control.closest('.qty-control-with-unit');
    if (!wrap) {
        wrap = document.createElement('div');
        wrap.className = 'qty-control-with-unit';
        control.parentNode.insertBefore(wrap, control);
        wrap.appendChild(control);
    }
    badge = document.createElement('span');
    badge.className = 'qty-unit-badge';
    badge.id = inputId + '-unit';
    badge.setAttribute('aria-live', 'polite');
    badge.textContent = '—';
    wrap.appendChild(badge);
    return badge;
}

function setQtyInputUnitLabel(inputId, unit, muted = false) {
    const badge = ensureQtyUnitBadge(inputId) || document.getElementById(inputId + '-unit');
    if (!badge) return;
    badge.textContent = getUnitDisplayLabel(unit);
    badge.classList.toggle('qty-unit-muted', !!muted);
    badge.title = (t('qty.unit_for_input') || 'Unità di misura') + ': ' + badge.textContent;
}

function getActiveUseUnitLabel() {
    if (_useConfMode) {
        if (_useConfMode._activeUnit === 'conf') return 'conf';
        return _useConfMode.subLabel || _useConfMode.packageUnit || 'g';
    }
    return _useNormalUnit || 'pz';
}

function getActiveRecipeUseUnitLabel() {
    if (_recipeUseConfMode) {
        if (_recipeUseConfMode._activeUnit === 'conf') return 'conf';
        return _recipeUseConfMode.subLabel || _recipeUseConfMode.packageUnit || 'g';
    }
    return _recipeUseNormalUnit || 'pz';
}

function syncUseQtyUnitBadge() {
    setQtyInputUnitLabel('use-quantity', getActiveUseUnitLabel());
}

function syncRecipeUseQtyUnitBadge() {
    setQtyInputUnitLabel('ruse-quantity', getActiveRecipeUseUnitLabel());
}

// Structured quantity display for inventory cards.
// Returns { mainQty: '10', unitLabel: 'conf', packageDetail: 'da 36g', fraction: '¼' }
function formatQuantityParts(qty, unit, defaultQty, packageUnit) {
    const n = parseFloat(qty) || 0;
    const unitLabels = { 'pz': t('units.pz'), 'g': 'g', 'ml': 'ml', 'conf': t('units.conf') };
    const label = unitLabels[unit] || unit || t('units.pz');

    // Special handling for conf with partial packages
    if (unit === 'conf' && packageUnit && defaultQty > 0) {
        const pkgLabel = unitLabels[packageUnit] || packageUnit;
        const wholeConf = Math.floor(n + 0.001);
        const fractionalConf = Math.round((n - wholeConf) * 1000) / 1000;

        if (fractionalConf < 0.01) {
            return { mainQty: `${wholeConf}`, unitLabel: t('units.conf') || 'conf', packageDetail: `${t('units.from') || 'da'} ${defaultQty}${pkgLabel}`, fraction: '' };
        }
        const remainderText = formatSubRemainder(fractionalConf * defaultQty, packageUnit);
        if (wholeConf > 0) {
            return { mainQty: `${wholeConf}`, unitLabel: t('units.conf') || 'conf', packageDetail: `${t('units.from') || 'da'} ${defaultQty}${pkgLabel}`, fraction: `+ ${remainderText}` };
        }
        return { mainQty: remainderText, unitLabel: '', packageDetail: '', fraction: '' };
    }

    let mainQty;
    if (n === Math.floor(n)) mainQty = `${Math.floor(n)}`;
    else if (unit === 'pz') mainQty = _pzFractionLabel(n);
    else mainQty = `${n.toFixed(1)}`;
    
    let packageDetail = '';
    let fraction = '';
    // pz = piece count only; default_quantity may hold legacy avg weight — ignore for display
    if (unit !== 'conf' && unit !== 'pz' && defaultQty && defaultQty > 1) {
        const d = parseFloat(defaultQty);
        const ratio = n / d;
        const remainder = ratio - Math.floor(ratio);
        if (remainder >= 0.1 && remainder <= 0.9) {
            if (remainder < 0.38) fraction = '¼';
            else if (remainder < 0.62) fraction = '½';
            else fraction = '¾';
        }
    }
    
    return { mainQty, unitLabel: label, packageDetail, fraction };
}

// Show package fraction: only ¼, ½, ¾ when there's a partial package.
// Returns '' if quantity maps to whole packages or fraction is not meaningful.
function formatPackageFraction(qty, defaultQty) {
    if (!defaultQty || defaultQty <= 0) return '';
    const n = parseFloat(qty);
    const d = parseFloat(defaultQty);
    if (isNaN(n) || isNaN(d) || d <= 0 || d === 1) return '';
    
    const ratio = n / d;
    const remainder = ratio - Math.floor(ratio);
    
    // Only show if there IS a fractional part
    if (remainder < 0.1 || remainder > 0.9) return '';
    
    let frac = '';
    if (remainder < 0.38) frac = '¼';
    else if (remainder < 0.62) frac = '½';
    else frac = '¾';
    
    return `<span class="pkg-fraction">${frac}</span>`;
}

// ===== INVENTORY =====
async function loadInventory() {
    try {
        const data = await api('inventory_list', currentLocation ? { location: currentLocation } : {});
        currentInventory = data.inventory || [];
        renderInventory(currentInventory);
        loadQuickAccess();
    } catch (err) {
        console.error('Inventory load error:', err);
    }
}

function renderInventoryItem(item) {
    const catKey = mapToLocalCategory(item.category, item.name);
    const catIcon = CATEGORY_ICONS[catKey] || '📦';
    const catLabel = t('categories.' + catKey) || catKey;
    const catBadge = `<span class="inv-badge badge-category" data-cat="${catKey}" data-itemname="${escapeHtml(item.name)}">${catIcon} ${catLabel}</span>`;
    const locInfo = LOCATIONS[item.location] || { icon: '📦', label: item.location };
    const days = daysUntilExpiry(item.expiry_date);
    const isExpired = days < 0;
    const isExpiring = !isExpired && days <= 7;
    const parts = formatQuantityParts(item.quantity, item.unit, item.default_quantity, item.package_unit);
    
    let expiryBadge = '';
    if (item.expiry_date) {
        let expiryText;
        if (isExpired) expiryText = t('expiry.badge_expired_ago').replace('{n}', Math.abs(days));
        else if (days === 0) expiryText = t('expiry.badge_today');
        else if (days === 1) expiryText = t('expiry.badge_tomorrow');
        else if (days <= 7) expiryText = t('expiry.badge_days').replace('{n}', days);
        else expiryText = formatDate(item.expiry_date);
        expiryBadge = `<span class="inv-badge ${isExpired ? 'badge-expired' : isExpiring ? 'badge-expiry' : ''}">${expiryText}</span>`;
    }
    
    const vacuumBadge = item.vacuum_sealed ? `<span class="vacuum-badge">${t('inventory.vacuum_badge')}</span>` : '';
    const openedBadge = item.opened_at ? `<span class="opened-badge">${t('inventory.opened_badge')}</span>` : '';
    
    return `
    <div class="inventory-item" onclick="showItemDetail(${item.id}, ${item.product_id})">
        <div class="inv-image">
            ${item.image_url ? `<img src="${escapeHtml(item.image_url)}" alt="" onerror="this.parentElement.innerHTML='${catIcon}'">` : catIcon}
        </div>
        <div class="inv-info">
            <div class="inv-name">${escapeHtml(item.name)}</div>
            ${item.brand ? `<div class="inv-brand">${escapeHtml(item.brand)}</div>` : ''}
            <div class="inv-meta">
                <span class="inv-badge badge-location">${locInfo.icon} ${locInfo.label}</span>
                ${catBadge}
                ${expiryBadge}
                ${openedBadge}
                ${vacuumBadge}
            </div>
        </div>
        <div class="inv-qty-col">
            <span class="inv-qty-number">${parts.mainQty}</span>
            <span class="inv-qty-unit">${parts.unitLabel}${parts.packageDetail ? ` <span class="inv-qty-pkg">${parts.packageDetail}</span>` : ''}</span>
            ${parts.fraction ? `<span class="inv-qty-frac">${parts.fraction}</span>` : ''}
        </div>
    </div>`;
}

function renderInventory(items) {
    const container = document.getElementById('inventory-list');
    if (items.length === 0) {
        container.innerHTML = `<div class="empty-state"><div class="empty-state-icon">📭</div><p>${t('inventory.empty_text')}</p></div>`;
        return;
    }
    container.innerHTML = renderGroupedByCategory(items, false);
    _refineCategoryBadgesAsync();
}

/**
 * After rendering, find all badges still showing 'altro' and ask the server
 * (Gemini-backed, cached) for a better category. Updates the DOM in place.
 */
async function _refineCategoryBadgesAsync() {
    if (!_geminiAvailable) return; // AI not available — keep 'altro' label
    const badges = Array.from(document.querySelectorAll('.badge-category[data-cat="altro"]'));
    for (const badge of badges) {
        const name = badge.dataset.itemname;
        if (!name) continue;
        try {
            const res = await api('guess_category', { name });
            const cat = res.category;
            if (cat && cat !== 'altro') {
                badge.dataset.cat = cat;
                badge.textContent = (CATEGORY_ICONS[cat] || '📦') + ' ' + (t('categories.' + cat) || cat);
            }
        } catch (_) { /* network error — leave as 'altro' */ }
    }
}

function filterLocation(loc) {
    currentLocation = loc;
    document.querySelectorAll('.location-tabs .tab').forEach(t => {
        t.classList.toggle('active', t.dataset.loc === loc);
    });
    loadInventory();
}

function filterInventory() {
    const q = document.getElementById('inventory-search').value.toLowerCase().trim();
    const qas = document.getElementById('quick-access-section');
    if (!q) {
        if (qas) qas.style.display = '';
        renderInventory(currentInventory);
        return;
    }
    if (qas) qas.style.display = 'none';
    // Category inferred from the search term itself (e.g. "biscotti" → "snack")
    const queryCat = guessCategoryFromName(q);
    const filtered = currentInventory.filter(i => {
        if (i.name.toLowerCase().includes(q)) return true;
        if (i.brand && i.brand.toLowerCase().includes(q)) return true;
        if (i.barcode && i.barcode.includes(q)) return true;
        const itemCat = mapToLocalCategory(i.category, i.name);
        // Match category key directly (e.g. "snack", "latticini")
        if (itemCat.includes(q)) return true;
        // Match category label (e.g. "dolci" matches "Snack & Dolci", "riso" matches "Pasta & Riso")
        if ((CATEGORY_LABELS[itemCat] || '').toLowerCase().includes(q)) return true;
        // Match by inferred category: "biscotti" → queryCat="snack" → all snack items
        if (queryCat !== 'altro' && itemCat === queryCat) return true;
        return false;
    });
    renderInventory(filtered);
}

// ===== QUICK ACCESS: RECENT & POPULAR =====
async function loadQuickAccess() {
    const section = document.getElementById('quick-access-section');
    if (!section) return;
    try {
        const data = await api('recent_popular_products');
        const recent = data.recent || [];
        const popular = data.popular || [];
        const recentIds = data.recent_ids || [];

        const recentGroup = document.getElementById('quick-recent-group');
        const popularGroup = document.getElementById('quick-popular-group');
        const recentGrid = document.getElementById('quick-recent-grid');
        const popularGrid = document.getElementById('quick-popular-grid');

        // Render recent (max 4)
        if (recent.length > 0) {
            recentGrid.innerHTML = recent.slice(0, 4).map(p => renderQuickAccessBtn(p)).join('');
            recentGroup.style.display = '';
        } else {
            recentGroup.style.display = 'none';
        }

        // Render popular (max 8), excluding products already in recent
        const filteredPopular = popular.filter(p => !recentIds.includes(parseInt(p.product_id)));
        if (filteredPopular.length > 0) {
            popularGrid.innerHTML = filteredPopular.slice(0, 8).map(p => renderQuickAccessBtn(p)).join('');
            popularGroup.style.display = '';
        } else {
            popularGroup.style.display = 'none';
        }

        section.style.display = (recent.length > 0 || filteredPopular.length > 0) ? '' : 'none';
    } catch (e) {
        console.warn('[QuickAccess] load failed:', e);
        section.style.display = 'none';
    }
}

function renderQuickAccessBtn(product) {
    const catIcon = CATEGORY_ICONS[mapToLocalCategory(product.category, product.name)] || '📦';
    const exhausted = parseFloat(product.stock_qty ?? 1) <= 0.001;
    const imgHtml = product.image_url
        ? `<img src="${escapeHtml(product.image_url)}" alt="" onerror="this.parentElement.innerHTML='${catIcon}'">`
        : catIcon;
    const brandHtml = product.brand ? `<span class="qa-brand">(${escapeHtml(product.brand)})</span>` : '';
    const exhaustedBadge = exhausted ? `<span class="qa-exhausted">${t('use.exhausted_badge')}</span>` : '';
    return `
    <button class="quick-access-btn${exhausted ? ' quick-access-exhausted' : ''}" onclick="quickAccessSelect(${product.product_id})">
        <div class="qa-img">${imgHtml}</div>
        <div class="qa-name">${escapeHtml(product.name)}</div>
        ${brandHtml}
        ${exhaustedBadge}
    </button>`;
}

async function _finishExhaustedProduct(productId) {
    closeModal();
    showLoading(true);
    try {
        const res = await api('inventory_confirm_finished', {}, 'POST', { product_id: productId });
        showLoading(false);
        if (!res.success) {
            showToast(res.error || t('error.generic'), 'error');
            return;
        }
        const name = res.product_name || currentProduct?.name || '';
        showToast(t('toast.product_finished_confirmed'), 'success');
        if (res.bring?.added || res.bring?.updated) {
            setTimeout(() => showToast(t('toast.finished_to_bring'), 'info'), 1200);
            loadShoppingList?.();
        }
        if (typeof loadDashboard === 'function') loadDashboard();
        else refreshCurrentPage();
    } catch (e) {
        showLoading(false);
        showToast(t('error.connection'), 'error');
    }
}

async function _showExhaustedProductModal(productId) {
    let product = (currentProduct && currentProduct.id == productId) ? currentProduct : null;
    if (!product) {
        try {
            const data = await api('product_get', { id: productId });
            product = data.product;
        } catch (_) {}
    }
    if (!product) {
        showToast(t('error.not_found'), 'error');
        return;
    }
    currentProduct = product;
    document.getElementById('modal-content').innerHTML = `
        <div class="modal-header">
            <h3>${escapeHtml(product.name)}</h3>
            <button class="modal-close" onclick="closeModal()">✕</button>
        </div>
        <p style="color:var(--text-muted);margin:8px 0 16px">${t('use.already_exhausted_modal')}</p>
        <div style="display:flex;flex-direction:column;gap:10px">
            <button type="button" class="btn btn-large btn-warning full-width" onclick="_finishExhaustedProduct(${productId})">
                ${t('use.mark_exhausted_btn')}
            </button>
            <button type="button" class="btn btn-large btn-primary full-width" onclick="closeModal();showPage('scan')">
                ${t('use.readd_btn')}
            </button>
            <button type="button" class="btn btn-secondary full-width" onclick="closeModal()">${t('confirm.cancel')}</button>
        </div>
    `;
    document.getElementById('modal-overlay').style.display = 'flex';
}

function quickAccessSelect(productId) {
    const item = currentInventory.find(i => i.product_id === productId);
    if (item) {
        showItemDetail(item.id, item.product_id);
        return;
    }
    api('inventory_list').then(data => {
        currentInventory = data.inventory || [];
        const live = currentInventory.find(i => i.product_id == productId && parseFloat(i.quantity) > 0);
        if (live) {
            showItemDetail(live.id, productId);
        } else {
            _showExhaustedProductModal(productId);
        }
    }).catch(() => _showExhaustedProductModal(productId));
}

// ===== ITEM DETAIL MODAL =====
function showItemDetail(inventoryId, productId) {
    const item = currentInventory.find(i => i.id === inventoryId);
    if (!item) {
        if (productId) _showExhaustedProductModal(productId);
        else showToast(t('error.not_in_inventory'), 'error');
        return;
    }
    
    const locInfo = LOCATIONS[item.location] || { icon: '📦', label: item.location };
    const catIcon = CATEGORY_ICONS[mapToLocalCategory(item.category, item.name)] || '📦';
    
    document.getElementById('modal-content').innerHTML = `
        <div class="modal-header">
            <h3>${escapeHtml(item.name)}</h3>
            <button class="modal-close" onclick="closeModal()">✕</button>
        </div>
        <div class="product-preview-small" style="margin-bottom:12px">
            ${item.image_url ?
                `<img src="${escapeHtml(item.image_url)}" alt="" style="width:60px;height:60px;border-radius:10px;object-fit:cover">` :
                `<span style="font-size:2.5rem">${catIcon}</span>`
            }
            <div class="product-preview-info">
                <h3>${escapeHtml(item.name)}</h3>
                <p>${item.brand ? escapeHtml(item.brand) : ''}</p>
            </div>
        </div>
        <div class="modal-detail">
            <div class="modal-detail-row">
                <span class="modal-detail-label">${t('inventory.label_position')}</span>
                <span class="modal-detail-value">${locInfo.icon} ${locInfo.label}</span>
            </div>
            <div class="modal-detail-row">
                <span class="modal-detail-label">${t('inventory.label_quantity')}</span>
                <span class="modal-detail-value">${formatQuantity(item.quantity, item.unit, item.default_quantity, item.package_unit)}</span>
            </div>
            ${item.expiry_date ? `
            <div class="modal-detail-row">
                <span class="modal-detail-label">${t('inventory.label_expiry')}</span>
                <span class="modal-detail-value">${formatDate(item.expiry_date)}</span>
            </div>` : ''}
            ${item.vacuum_sealed ? `
            <div class="modal-detail-row">
                <span class="modal-detail-label">${t('inventory.label_storage')}</span>
                <span class="modal-detail-value">${t('inventory.vacuum_badge')}</span>
            </div>` : ''}
            ${item.opened_at ? `
            <div class="modal-detail-row">
                <span class="modal-detail-label">${t('inventory.label_status')}</span>
                <span class="modal-detail-value">${t('inventory.opened_since').replace('{date}', formatDateTime(item.opened_at))}</span>
            </div>` : ''}
            ${item.barcode ? `
            <div class="modal-detail-row">
                <span class="modal-detail-label">🔖 Barcode</span>
                <span class="modal-detail-value">${item.barcode}</span>
            </div>` : ''}
            <div class="modal-detail-row">
                <span class="modal-detail-label">${t('inventory.label_added')}</span>
                <span class="modal-detail-value">${formatDateTime(item.added_at)}</span>
            </div>
        </div>
        <div class="modal-actions">
            <button class="btn btn-danger flex-1" onclick="quickUse(${item.product_id}, '${item.location}')">📤 ${t('btn.use')}</button>
            <button class="btn btn-primary flex-1" onclick="editInventoryItem(${inventoryId})">✏️ ${t('btn.edit_item')}</button>
            <button class="btn btn-accent flex-1" data-name="${escapeHtml(item.name)}" onclick="closeModal();generateRecipeForIngredient(this.dataset.name)">🍳 ${t('action.create_recipe_btn')}</button>
            <button class="btn btn-secondary" onclick="deleteInventoryItem(${inventoryId})" style="padding:12px">🗑️</button>
        </div>
    `;
    document.getElementById('modal-overlay').style.display = 'flex';
}

function closeModal() {
    document.getElementById('modal-overlay').style.display = 'none';
    clearMoveModalTimer();
    _cancelScaleAutoConfirm(false);
    _scaleRecipeAutoFillPaused = false;
    _scaleUserDismissed = false;
    _scaleWeightCallback = null;
    _bannerEditPending = false;
}

async function quickUse(productId, location) {
    closeModal();
    showLoading(true);
    try {
        currentProduct = { id: productId };
        // Get product info
        const data = await api('product_get', { id: productId });
        if (data.product) {
            currentProduct = data.product;
            // Extract weight_info from notes if available
            if (!currentProduct.weight_info && currentProduct.notes) {
                const pesoMatch = currentProduct.notes.match(/Peso:\s*([^·]+)/);
                if (pesoMatch) currentProduct.weight_info = pesoMatch[1].trim();
            }
        }
        document.getElementById('use-location').value = location;
        // Mark active location button
        document.querySelectorAll('#page-use .loc-btn').forEach(b => b.classList.remove('active'));
        const locBtns = document.querySelectorAll('#page-use .loc-btn');
        locBtns.forEach(b => {
            if (b.textContent.toLowerCase().includes(location)) b.classList.add('active');
        });
        
        renderUsePreview();

        // Reset scale state so the stale weight already on the scale doesn't
        // immediately trigger an auto-fill. Only a weight *change* (≥10 g) after
        // the page opens should be treated as a new product being placed.
        _cancelScaleAutoConfirm(false); // stops timers, clears _scaleStabilityVal & _scaleLastConfirmedGrams
        if (_scaleLatestWeight) {
            const _baselineG = _scaleToGrams(parseFloat(_scaleLatestWeight.value), _scaleLatestWeight.unit);
            if (_baselineG !== null && _baselineG >= 10) _scaleLastConfirmedGrams = _baselineG;
            _scaleLatestWeight = null; // prevent immediate call inside loadUseInventoryInfo
        }

        loadUseInventoryInfo();
        showLoading(false);
        showPage('use');
    } catch (err) {
        showLoading(false);
        console.error('quickUse error:', err);
        showToast(t('error.loading'), 'error');
    }
}

const WASTE_REASON_KEYS = ['expired', 'spoiled', 'wrong_location', 'kept_too_long', 'bought_too_much', 'forgotten', 'bad_quality', 'other'];

function _wasteNotesForReason(reason) {
    return 'Buttato|' + reason;
}

function _showWasteReasonModal(productLabel, onPick) {
    const buttons = WASTE_REASON_KEYS.map(r =>
        `<button type="button" class="btn btn-large full-width" style="margin-bottom:8px;text-align:left" data-waste-reason="${r}">${escapeHtml(t('waste.reason_' + r))}</button>`
    ).join('');
    document.getElementById('modal-content').innerHTML = `
        <div class="modal-header">
            <h3>${t('waste.reason_title')}</h3>
            <button class="modal-close" onclick="closeModal()">✕</button>
        </div>
        <p style="color:var(--text-muted);margin:8px 0 16px">${escapeHtml(productLabel)}</p>
        <p style="color:var(--text-muted);margin:0 0 12px;font-size:0.9rem">${t('waste.reason_subtitle')}</p>
        <div style="display:flex;flex-direction:column;gap:0">${buttons}
            <button type="button" class="btn btn-secondary full-width" style="margin-top:8px" onclick="closeModal()">${t('confirm.cancel')}</button>
        </div>
    `;
    document.getElementById('modal-overlay').style.display = 'flex';
    document.querySelectorAll('[data-waste-reason]').forEach(btn => {
        btn.addEventListener('click', () => {
            const reason = btn.getAttribute('data-waste-reason');
            closeModal();
            onPick(reason);
        });
    });
}

function _inventoryWaste(payload, productLabel) {
    return new Promise((resolve, reject) => {
        _showWasteReasonModal(productLabel || '', async (reason) => {
            showLoading(true);
            try {
                const result = await api('inventory_use', {}, 'POST', { ...payload, notes: _wasteNotesForReason(reason) });
                resolve(result);
            } catch (e) {
                reject(e);
            } finally {
                showLoading(false);
            }
        });
    });
}

async function deleteInventoryItem(id) {
    const item = currentInventory.find(i => i.id === id);
    const unit = item ? (item.unit || 'pz') : 'pz';
    const qty  = item ? (parseFloat(item.quantity) || 0) : 0;
    const canDiscardOne = item && (unit === 'pz' || unit === 'conf') && qty > 1;

    if (!canDiscardOne) {
        // Simple case: confirm → delete the whole row
        if (confirm(t('confirm.remove_item'))) {
            await api('inventory_delete', {}, 'POST', { id });
            closeModal();
            showToast(t('toast.product_removed'), 'success');
            refreshCurrentPage();
        }
        return;
    }

    // Show a choice modal: 1 piece vs everything
    const qtyDisplay = formatQuantity(qty, unit, item.default_quantity, item.package_unit);
    document.getElementById('modal-content').innerHTML = `
        <div class="modal-header">
            <h3>${t('use.throw_title')}</h3>
            <button class="modal-close" onclick="closeModal()">✕</button>
        </div>
        <p style="color:var(--text-muted);margin:8px 0 16px">${escapeHtml(item.name)} · ${qtyDisplay}</p>
        <div style="display:flex;flex-direction:column;gap:10px">
            <button class="btn btn-large btn-warning full-width" onclick="_discardOnePiece(${id})">
                ${t('confirm.discard_one')}
            </button>
            <button class="btn btn-large btn-danger full-width" onclick="_discardAllFromModal(${id})">
                ${t('use.throw_all', { qty: qtyDisplay })}
            </button>
            <button class="btn btn-secondary full-width" onclick="closeModal()">
                ${t('confirm.cancel')}
            </button>
        </div>
    `;
    document.getElementById('modal-overlay').style.display = 'flex';
}

async function _discardOnePiece(inventoryId) {
    const item = currentInventory.find(i => i.id === inventoryId);
    if (!item) { closeModal(); return; }
    closeModal();
    try {
        await _inventoryWaste({
            product_id: item.product_id,
            quantity: 1,
            location: item.location,
        }, item.name);
        showToast(t('toast.thrown_away_partial', { qty: 1, unit: item.unit || 'pz', name: item.name }), 'success');
        refreshCurrentPage();
    } catch(e) {
        showToast(t('error.connection'), 'error');
    }
}

async function _discardAllFromModal(inventoryId) {
    const item = currentInventory.find(i => i.id === inventoryId);
    if (!item) { closeModal(); return; }
    closeModal();
    try {
        await _inventoryWaste({
            product_id: item.product_id,
            use_all: true,
            location: item.location,
        }, item.name);
        showToast(t('toast.thrown_away', { name: item.name }), 'success');
        refreshCurrentPage();
    } catch(e) {
        showToast(t('error.connection'), 'error');
    }
}

/** Track manual expiry edits — auto-recalc on location/vacuum must not overwrite user dates. */
function _initExpiryManualTracking(inputId, item) {
    const el = document.getElementById(inputId);
    if (!el) return;
    if (item?.expiry_user_set) el.dataset.manuallySet = 'true';
    else delete el.dataset.manuallySet;
    if (el.dataset.expiryTrackBound) return;
    el.dataset.expiryTrackBound = '1';
    const mark = () => {
        if (el.value) el.dataset.manuallySet = 'true';
        else delete el.dataset.manuallySet;
    };
    el.addEventListener('input', mark);
    el.addEventListener('change', mark);
}

function _isExpiryManuallySet(inputId) {
    return document.getElementById(inputId)?.dataset.manuallySet === 'true';
}

function _expiryUserSetPayload(inputId) {
    return _isExpiryManuallySet(inputId) ? 1 : 0;
}

function recalcEditExpiry(locInputId, vacuumInputId, expiryInputId) {
    if (_isExpiryManuallySet(expiryInputId)) return;
    const product = window._editingProduct;
    if (!product) return;
    const loc = document.getElementById(locInputId)?.value || '';
    const isVacuum = document.getElementById(vacuumInputId)?.checked;
    // Use opened shelf life if item is already opened
    let days = product._isOpened
        ? estimateOpenedExpiryDays(product, loc)
        : estimateExpiryDays(product, loc);
    if (isVacuum) days = getVacuumExpiryDays(days);
    const newDate = addDays(days);
    const expiryInput = document.getElementById(expiryInputId);
    if (expiryInput) expiryInput.value = newDate;
}

function editInventoryItem(id, _retried) {
    const item = currentInventory.find(i => i.id === id);
    if (!item) {
        if (!_retried) {
            api('inventory_list').then(data => {
                currentInventory = data.inventory || [];
                editInventoryItem(id, true);
            });
            return;
        }
        closeModal();
        showToast(t('error.not_found'), 'error');
        loadBannerAlerts();
        return;
    }
    
    const isConf = (item.unit || 'pz') === 'conf';
    const confSizeVal = (isConf && item.default_quantity > 0) ? item.default_quantity : '';
    const confUnitVal = (isConf && item.package_unit) ? item.package_unit : 'g';
    
    // Determine if scale is available for this item's unit
    const s = getSettings();
    const effectiveUnit = isConf ? (item.package_unit || 'g') : (item.unit || 'pz');
    const scaleEditReady = s.scale_enabled && s.scale_gateway_url && _scaleConnected &&
        (effectiveUnit === 'g' || effectiveUnit === 'ml');
    
    window._editingProduct = {
        name: item.name,
        category: item.category || '',
        _isOpened: !!item.opened_at,
        _prevUnit: item.unit || 'pz',
        default_quantity: item.default_quantity,
        package_unit: item.package_unit || '',
    };
    
    // Rebuild modal content for editing (don't close and reopen - just replace content)
    document.getElementById('modal-content').innerHTML = `
        <div class="modal-header">
            <h3>${t('edit.title').replace('{name}', escapeHtml(item.name))}</h3>
            <button class="modal-close" onclick="closeModal()">✕</button>
        </div>
        <form class="form" onsubmit="submitEditInventory(event, ${id}, ${item.product_id})">
            <div class="form-group">
                <label>📦 ${t('inventory.label_quantity').replace('📦 ', '')}</label>
                <div class="qty-control-with-unit">
                    <div class="qty-control">
                        <button type="button" class="qty-btn" onclick="adjustQty('edit-qty', -1)">−</button>
                        <input type="number" id="edit-qty" value="${item.quantity}" min="0" step="any" class="qty-input">
                        <button type="button" class="qty-btn" onclick="adjustQty('edit-qty', 1)">+</button>
                    </div>
                    <span class="qty-unit-badge" id="edit-qty-unit" aria-live="polite">${escapeHtml(getUnitDisplayLabel(item.unit || 'pz'))}</span>
                </div>
                ${scaleEditReady ? `
                <div id="edit-scale-section" style="display:none;text-align:center;padding:10px;background:linear-gradient(135deg,#f3e8ff,#ede9fe);border-radius:10px;margin-top:8px">
                    <div style="font-size:1.8rem;font-weight:bold;color:#5b21b6" id="edit-scale-reading">— — —</div>
                    <div style="font-size:0.78rem;color:#7c6cb0;margin-top:2px">${t('scale.place_on_scale')}</div>
                </div>
                <button type="button" id="btn-scale-edit" class="btn btn-secondary scale-read-btn" style="margin-top:8px;width:100%"
                    onclick="readScaleForEdit()">⚖️ ${t('scale.read_btn')}</button>
                ` : ''}
            </div>
            <div class="form-group">
                <label>${t('product.unit_label')}</label>
                <select id="edit-unit" class="form-input" onchange="onEditUnitChange()">
                    ${['pz','g','ml','conf'].map(u => `<option value="${u}" ${(item.unit||'pz') === u ? 'selected' : ''}>${u === 'pz' ? 'pz (' + t('units.pieces') + ')' : u === 'g' ? 'g (' + t('units.grams') + ')' : u === 'ml' ? 'ml (' + t('units.millilitres') + ')' : u === 'conf' ? 'conf (' + t('units.boxes') + ')' : u}</option>`).join('')}
                </select>
            </div>
            <div class="form-group" id="edit-conf-size-group" style="display:${isConf ? 'block' : 'none'}">
                <label>${t('product.conf_size_label')}</label>
                <div class="conf-size-inputs">
                    <input type="number" id="edit-conf-size" class="form-input conf-size-input" min="1" step="any" value="${confSizeVal}" placeholder="${t('product.conf_size_placeholder')}">
                    <select id="edit-conf-unit" class="form-input conf-size-unit">
                        ${['g','ml'].map(u => `<option value="${u}" ${confUnitVal === u ? 'selected' : ''}>${u}</option>`).join('')}
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>${t('inventory.label_position')}</label>
                <div class="location-selector">
                    ${Object.entries(LOCATIONS).map(([k, v]) => `
                        <button type="button" class="loc-btn ${item.location === k ? 'active' : ''}" 
                            onclick="this.parentElement.querySelectorAll('.loc-btn').forEach(b=>b.classList.remove('active'));this.classList.add('active');document.getElementById('edit-loc').value='${k}';recalcEditExpiry('edit-loc','edit-vacuum','edit-expiry')">${v.icon} ${v.label}</button>
                    `).join('')}
                </div>
                <input type="hidden" id="edit-loc" value="${item.location}">
            </div>
            <div class="form-group">
                <label>${t('inventory.label_expiry')}</label>
                <input type="date" id="edit-expiry" value="${item.expiry_date || ''}" class="form-input">
            </div>
            <div class="form-group">
                <label class="toggle-row">
                    ${t('add.vacuum_label')}
                    <span class="toggle-switch">
                        <input type="checkbox" id="edit-vacuum" ${item.vacuum_sealed ? 'checked' : ''} onchange="recalcEditExpiry('edit-loc','edit-vacuum','edit-expiry')">
                        <span class="toggle-slider"></span>
                    </span>
                </label>
            </div>
            <button type="submit" class="btn btn-large btn-primary full-width">${t('btn.save')}</button>
        </form>
    `;
    document.getElementById('modal-overlay').style.display = 'flex';
    _initExpiryManualTracking('edit-expiry', item);
    setQtyInputUnitLabel('edit-qty', item.unit || 'pz');
}

function onEditUnitChange() {
    const unit = document.getElementById('edit-unit').value;
    setQtyInputUnitLabel('edit-qty', unit);
    const confGroup = document.getElementById('edit-conf-size-group');
    if (confGroup) confGroup.style.display = unit === 'conf' ? 'block' : 'none';
    if (unit === 'conf') {
        const sizeInput = document.getElementById('edit-conf-size');
        const confUnitSel = document.getElementById('edit-conf-unit');
        if (sizeInput && !parseFloat(sizeInput.value)) {
            const ep = window._editingProduct || {};
            const defQty = parseFloat(ep.default_quantity) || 0;
            const pkgUnit = ep.package_unit || '';
            const prevUnit = ep._prevUnit || '';
            if (defQty > 0 && pkgUnit) {
                sizeInput.value = defQty;
                if (confUnitSel) confUnitSel.value = pkgUnit;
            } else if (defQty > 0 && ['g', 'ml'].includes(prevUnit)) {
                sizeInput.value = defQty;
                if (confUnitSel) confUnitSel.value = prevUnit;
            }
        }
    }
}

async function submitEditInventory(e, id, productId) {
    e.preventDefault();
    const qty = parseFloat(document.getElementById('edit-qty').value);
    const loc = document.getElementById('edit-loc').value;
    const expiry = document.getElementById('edit-expiry').value || null;
    const unit = document.getElementById('edit-unit').value;

    if (unit === 'conf') {
        const confSize = parseFloat(document.getElementById('edit-conf-size')?.value);
        if (!confSize || confSize <= 0) {
            showToast(t('product.conf_size_required'), 'error');
            document.getElementById('edit-conf-size')?.focus();
            return;
        }
    }

    // Safety guard: warn if quantity is unreasonably large to prevent unit-confusion errors
    // (e.g. user types "183" thinking it's ml, but the field expects conf units)
    const _largeQtyLimits = { conf: 50, pz: 200, g: 10000, ml: 10000 };
    const _largeQtyLimit = _largeQtyLimits[unit] ?? 500;
    if (qty > _largeQtyLimit) {
        if (!confirm(t('edit.confirm_large_qty').replace('{qty}', qty).replace('{unit}', unit))) return;
    }

    const payload = { id, quantity: qty, location: loc, expiry_date: expiry, unit, product_id: productId,
        vacuum_sealed: document.getElementById('edit-vacuum')?.checked ? 1 : 0,
        expiry_user_set: _expiryUserSetPayload('edit-expiry') };
    
    // Add package info if conf
    if (unit === 'conf') {
        payload.package_unit = document.getElementById('edit-conf-unit')?.value || '';
        payload.package_size = parseFloat(document.getElementById('edit-conf-size')?.value) || 0;
    } else {
        // Clear package info if not conf
        payload.package_unit = '';
        payload.package_size = 0;
    }
    
    await api('inventory_update', {}, 'POST', payload);
    closeModal();
    showToast(t('toast.updated'), 'success');
    if (_bannerEditPending) {
        _bannerEditPending = false;
        // Mark the item as confirmed so it does NOT reappear in the banner
        const entry = _bannerQueue[_bannerIndex];
        if (entry) {
            if (entry.type === 'review') setReviewConfirmed(entry.data.id);
            else if (entry.type === 'prediction') setReviewConfirmed('pred_' + entry.data.inventory_id);
            else if (entry.type === 'expired') setReviewConfirmed('exp_' + entry.data.id);
            else if (entry.type === 'expiring') setReviewConfirmed('exps_' + entry.data.id);
        }
        dismissBannerItem();
    }
    refreshCurrentPage();
}

// ===== SCAN DEBUG LOG =====
let _scanDebugVisible = false;
let _scanLogBuffer = [];
let _scanLogTimer = null;

function scanLog(msg) {
    const el = document.getElementById('scan-debug-log');
    if (el) {
        const _scanLocale = _currentLang === 'de' ? 'de-DE' : _currentLang === 'en' ? 'en-GB' : 'it-IT';
        const ts = new Date().toLocaleTimeString(_scanLocale, {hour:'2-digit',minute:'2-digit',second:'2-digit',fractionalSecondDigits:1});
        el.textContent += `[${ts}] ${msg}\n`;
        el.scrollTop = el.scrollHeight;
    }
    console.log('[ScanDebug]', msg);
    // Buffer for remote send
    _scanLogBuffer.push(msg);
    if (!_scanLogTimer) {
        _scanLogTimer = setTimeout(flushScanLog, 2000);
    }
}

function flushScanLog() {
    _scanLogTimer = null;
    if (_scanLogBuffer.length === 0) return;
    const msgs = _scanLogBuffer.splice(0).map(m => `[SCAN] ${m}`);
    _remoteLogBuffer.push(...msgs);
    if (!_remoteLogTimer) {
        _remoteLogTimer = setTimeout(flushRemoteLog, 2000);
    }
}

function toggleScanDebug() {
    const el = document.getElementById('scan-debug-log');
    if (!el) return;
    _scanDebugVisible = !_scanDebugVisible;
    el.style.display = _scanDebugVisible ? 'block' : 'none';
}

// ===== BARCODE SCANNER =====
let _useBarcodeDetector = ('BarcodeDetector' in window);

function _scannerAiAllowed() {
    return _geminiAvailable && !_offlineMode && navigator.onLine;
}

function pauseScanner() {
    _scannerPaused = true;
    _updateScanAiButton();
}

function resumeScanner() {
    if (!scannerStream) {
        initScanner();
        return;
    }
    _scannerPaused = false;
    const video = document.getElementById('scanner-video');
    if (video && video.paused) video.play().catch(() => {});
    if (!quaggaRunning) {
        _startBestScanner(video);
    }
    _setScanStatus(t('scan.status_scanning'), '', '');
    _updateScanAiButton();
}

async function initScanner() {
    const video = document.getElementById('scanner-video');
    const viewport = document.getElementById('scanner-viewport');
    const logEl = document.getElementById('scan-debug-log');
    if (logEl) logEl.textContent = '';
    
    const constraints = getCameraConstraints();
    scanLog(`Camera mode: ${getSettings().camera_facing || 'environment'}`);
    scanLog(`BarcodeDetector: ${_useBarcodeDetector ? 'YES (native)' : 'will use ZBar WASM'}`);
    scanLog(`ZBar polyfill: ${typeof barcodeDetectorPolyfill !== 'undefined' ? 'YES' : 'NO'}`);
    scanLog(`Gemini available: ${_geminiAvailable}`);
    scanLog(`AI manual button: ${_scannerAiAllowed() ? 'YES' : 'NO'}`);
    scanLog(`Constraints: ${JSON.stringify(constraints.video)}`);
    
    try {
        stopScanner();
        _resetAiFallbackForNewScan();
        _clearAiMatchPanel();
        
        const stream = await navigator.mediaDevices.getUserMedia(constraints);
        const track = stream.getVideoTracks()[0];
        const caps = track.getSettings ? track.getSettings() : {};
        scanLog(`Stream OK — track: ${track.label}`);
        scanLog(`Resolution: ${caps.width||'?'}x${caps.height||'?'}, facing: ${caps.facingMode||'N/A'}`);
        
        scannerStream = stream;
        video.srcObject = stream;
        await video.play();
        scanLog(`Video playing — videoWidth: ${video.videoWidth}, videoHeight: ${video.videoHeight}`);

        // Apply fixed 2x zoom
        await _applyFixedZoom();

        _invalidBarcodeCount = 0;
        _setScanStatus(t('scan.status_ready'), '', '');

        await _ensureBarcodeEngines();
        _scannerPaused = false;
        _startBestScanner(video);
        setTimeout(() => _ensureTesseractWorker().catch(() => {}), 1200);
        _updateScanAiButton();
        
    } catch (err) {
        scanLog(`CAMERA ERROR: ${err.name}: ${err.message}`);
        console.error('Camera error:', err);
        document.getElementById('scan-result').style.display = 'block';
        document.getElementById('scan-result').innerHTML = `
            <p style="color: var(--danger)">${t('error.camera')}</p>
            <p style="font-size:0.85rem; color: var(--text-light); margin-top:8px">${t('scanner.camera_error_hint')}</p>
        `;
    }
}

// ===== EAN-13 / EAN-8 CHECKSUM VALIDATOR =====
function validateEANChecksum(code) {
    const s = String(code).replace(/\D/g, '');
    if (s.length !== 13 && s.length !== 8) return false;
    const digits = s.split('').map(Number);
    const last = digits.pop();
    const sum = digits.reduce((acc, d, i) => {
        return acc + d * (s.length === 13 ? (i % 2 === 0 ? 1 : 3) : (i % 2 === 0 ? 3 : 1));
    }, 0);
    const check = (10 - (sum % 10)) % 10;
    return check === last;
}

// ===== SCAN STATUS BAR =====
let _invalidBarcodeCount = 0;

function _setScanStatus(msg, state, method) {
    const msgEl = document.getElementById('scan-status-msg');
    const methodEl = document.getElementById('scan-status-method');
    if (msgEl) {
        msgEl.textContent = msg || '';
        msgEl.className = 'scan-status-msg' + (state ? ' state-' + state : '');
    }
    if (methodEl && method !== undefined) methodEl.textContent = method || '';
}

// ===== BARCODE ENGINE INIT (Native + ZBar WASM) =====
let _zbarVendorPromise = null;

/** Lazy-load ZBar WASM (skipped at page load on kiosk WebView to reduce OOM risk). */
function _loadZbarVendor() {
    if (typeof barcodeDetectorPolyfill !== 'undefined') return Promise.resolve();
    if (_zbarVendorPromise) return _zbarVendorPromise;
    _zbarVendorPromise = new Promise((resolve, reject) => {
        const done = () => {
            if (typeof barcodeDetectorPolyfill !== 'undefined') resolve();
            else reject(new Error('ZBar polyfill unavailable'));
        };
        const loadPoly = () => {
            const s2 = document.createElement('script');
            s2.src = 'assets/vendor/zbar/polyfill.js?v=20260606a';
            s2.onload = done;
            s2.onerror = () => reject(new Error('ZBar polyfill load failed'));
            document.head.appendChild(s2);
        };
        if (window.zbarWasm) {
            loadPoly();
            return;
        }
        const s1 = document.createElement('script');
        s1.src = 'assets/vendor/zbar/index.js?v=20260606a';
        s1.onload = () => {
            if (window.zbarWasm && zbarWasm.setModuleArgs) {
                zbarWasm.setModuleArgs({ locateFile: (file) => 'assets/vendor/zbar/' + file });
            }
            loadPoly();
        };
        s1.onerror = () => reject(new Error('ZBar WASM load failed'));
        document.head.appendChild(s1);
    }).catch(err => {
        _zbarVendorPromise = null;
        throw err;
    });
    return _zbarVendorPromise;
}

function _startBestScanner(videoEl) {
    if (_detectorNative || _detectorZbar) {
        startUnifiedScanner(videoEl);
    } else if (typeof Quagga !== 'undefined') {
        scanLog('Legacy Quagga fallback — no native/ZBar engine');
        startQuaggaScanner(videoEl);
    } else {
        scanLog('No barcode engine available');
        _setScanStatus(t('error.camera'), 'invalid', '');
    }
}

function _ensureBarcodeEngines() {
    if (_barcodeEnginesReady) return _barcodeEnginesReady;
    _barcodeEnginesReady = (async () => {
        _detectorNative = null;
        _detectorZbar = null;
        if ('BarcodeDetector' in window) {
            try {
                const supported = await BarcodeDetector.getSupportedFormats();
                const formats = _SCAN_FORMATS.filter(f => supported.includes(f));
                if (formats.length) {
                    _detectorNative = new BarcodeDetector({ formats });
                    _useBarcodeDetector = true;
                }
            } catch (e) {
                scanLog(`Native BarcodeDetector init failed: ${e.message}`);
            }
        }
        if (typeof barcodeDetectorPolyfill === 'undefined') {
            try { await _loadZbarVendor(); } catch (e) {
                scanLog(`ZBar vendor load failed: ${e.message}`);
            }
        }
        if (typeof barcodeDetectorPolyfill !== 'undefined') {
            try {
                _detectorZbar = new barcodeDetectorPolyfill.BarcodeDetectorPolyfill({ formats: _SCAN_FORMATS });
            } catch (e) {
                scanLog(`ZBar engine init failed: ${e.message}`);
            }
        }
        scanLog(`Engines ready — native: ${!!_detectorNative}, ZBar: ${!!_detectorZbar}`);
        if (_detectorZbar) {
            try {
                const warm = document.createElement('canvas');
                warm.width = warm.height = 8;
                await _detectorZbar.detect(warm);
            } catch (_) {}
        }
    })();
    return _barcodeEnginesReady;
}

const _EAN_FORMATS = new Set(['ean_13', 'ean_8', 'upc_a', 'upc_e', 'ean_reader', 'ean_8_reader', 'upc_reader', 'upc_e_reader']);

function _isEanFormat(format) {
    return format && (_EAN_FORMATS.has(format) || String(format).includes('ean') || String(format).includes('upc'));
}

let _scanLastCode = '';
let _scanConsecCount = 0;

function _finalizeBarcode(code, method) {
    _scannerGen++;
    quaggaRunning = false;
    const scannerLine = document.querySelector('.scanner-line');
    if (scannerLine) scannerLine.classList.remove('scanning', 'detecting');
    scanLog(`CONFIRMED: ${code} via ${method}`);
    _hideScanLiveCode();
    _setScanStatus(t('scan.status_confirmed'), 'confirmed', method);
    onBarcodeDetected(code);
}

function _tryConfirmBarcode(code, format, method) {
    const digits = String(code || '').replace(/\D/g, '');
    if (!digits || digits.length < 8) return false;

    const isEan = _isEanFormat(format) || digits.length === 13 || digits.length === 8;
    if (isEan && (digits.length === 13 || digits.length === 8)) {
        if (!validateEANChecksum(digits)) {
            _invalidBarcodeCount++;
            scanLog(`Invalid EAN checksum (${method}): ${digits}`);
            _setScanStatus(t('scan.status_invalid').replace('{code}', digits), 'invalid', method);
            _scanLastCode = '';
            _scanConsecCount = 0;
            return false;
        }
        _finalizeBarcode(digits, method);
        return true;
    }

    if (digits === _scanLastCode) _scanConsecCount++;
    else { _scanLastCode = digits; _scanConsecCount = 1; }
    _showScanLiveCode(digits);
    _setScanStatus(t('scan.status_partial').replace('{code}', digits), 'partial', method);
    if (_scanConsecCount >= 2) {
        _finalizeBarcode(digits, method);
        return true;
    }
    return false;
}

function _extractEanCandidates(text) {
    const digits = String(text || '').replace(/\D/g, '');
    const found = [];
    for (const len of [13, 8]) {
        if (digits.length < len) continue;
        for (let i = 0; i <= digits.length - len; i++) {
            const candidate = digits.slice(i, i + len);
            if (validateEANChecksum(candidate) && !found.includes(candidate)) found.push(candidate);
        }
    }
    return found;
}

// Build preprocessed crop frames for ZBar (rotates through variants)
function _buildScanCropFrame(videoEl, frameCount) {
    const vw = videoEl.videoWidth;
    const vh = videoEl.videoHeight;
    if (!vw || !vh) return null;
    const canvas = document.getElementById('scanner-canvas');
    const ctx = canvas.getContext('2d');
    const variants = [
        { x: 0, y: 0, w: 1, h: 1, scale: 0.85, enhance: false },
        { x: 0.075, y: 0.28, w: 0.85, h: 0.44, scale: 1, enhance: true },
        { x: 0.1, y: 0.18, w: 0.8, h: 0.55, scale: 1, enhance: true },
        { x: 0.05, y: 0.35, w: 0.9, h: 0.35, scale: 1.2, enhance: true },
    ];
    const v = variants[frameCount % variants.length];
    const sw = Math.round(vw * v.w);
    const sh = Math.round(vh * v.h);
    const sx = Math.round(vw * v.x);
    const sy = Math.round(vh * v.y);
    canvas.width = Math.round(sw * v.scale);
    canvas.height = Math.round(sh * v.scale);
    ctx.drawImage(videoEl, sx, sy, sw, sh, 0, 0, canvas.width, canvas.height);
    if (v.enhance || _offlineMode || !navigator.onLine || isFrontCamera()) {
        enhanceCanvasForBarcode(ctx, canvas.width, canvas.height);
    }
    return canvas;
}

function _captureDigitStrip(videoEl) {
    const vw = videoEl.videoWidth;
    const vh = videoEl.videoHeight;
    if (!vw || !vh) return null;
    const canvas = document.getElementById('scanner-canvas');
    const ctx = canvas.getContext('2d');
    const cropW = Math.round(vw * 0.9);
    const cropH = Math.round(vh * 0.5);
    const sx = Math.round((vw - cropW) / 2);
    const sy = Math.round(vh * 0.2);
    const scale = 2;
    canvas.width = cropW * scale;
    canvas.height = cropH * scale;
    ctx.imageSmoothingEnabled = false;
    ctx.drawImage(videoEl, sx, sy, cropW, cropH, 0, 0, canvas.width, canvas.height);
    enhanceCanvasForBarcode(ctx, canvas.width, canvas.height);
    return canvas;
}

async function _ensureTesseractWorker() {
    if (_tesseractWorker) return _tesseractWorker;
    if (_tesseractInitPromise) return _tesseractInitPromise;
    _tesseractInitPromise = (async () => {
        if (typeof Tesseract === 'undefined') {
            await new Promise((resolve, reject) => {
                const s = document.createElement('script');
                s.src = 'assets/vendor/tesseract/tesseract.min.js';
                s.onload = resolve;
                s.onerror = () => reject(new Error('Tesseract load failed'));
                document.head.appendChild(s);
            });
        }
        const base = 'assets/vendor/tesseract';
        const worker = await Tesseract.createWorker('eng', 1, {
            workerPath: `${base}/worker.min.js`,
            corePath: `${base}/tesseract-core.wasm.js`,
            langPath: `${base}/lang`,
            gzip: true,
        });
        await worker.setParameters({
            tessedit_char_whitelist: '0123456789',
            tessedit_pageseg_mode: '7',
        });
        _tesseractWorker = worker;
        scanLog('Local digit OCR engine ready');
        return worker;
    })().catch(e => {
        scanLog(`Local OCR init failed: ${e.message}`);
        _tesseractInitPromise = null;
        return null;
    });
    return _tesseractInitPromise;
}

async function _tryLocalEanDigitOcr(videoEl) {
    if (_scannerPaused || !scannerStream) return false;
    const worker = await _ensureTesseractWorker();
    if (!worker) return false;
    const canvas = _captureDigitStrip(videoEl);
    if (!canvas) return false;
    _setScanStatus(t('scan.status_digit_ocr'), 'retry', t('scan.method_local_ocr'));
    try {
        const { data } = await worker.recognize(canvas);
        const candidates = _extractEanCandidates(data && data.text);
        if (candidates.length) {
            scanLog(`Local OCR: found ${candidates[0]} (from "${(data.text || '').trim()}")`);
            showToast(t('scan.local_ocr_found').replace('{code}', candidates[0]), 'success');
            return _tryConfirmBarcode(candidates[0], 'ean_13', 'OCR');
        }
        scanLog(`Local OCR: no valid EAN in "${(data && data.text) || ''}"`);
    } catch (e) {
        scanLog(`Local OCR error: ${e.message}`);
    }
    return false;
}

// ===== UNIFIED SCANNER (Native + ZBar + local digit OCR) =====
function startUnifiedScanner(videoEl) {
    const gen = ++_scannerGen;
    quaggaRunning = true;
    _scanLastCode = '';
    _scanConsecCount = 0;
    const startTime = Date.now();
    let frameCount = 0;
    let digitOcrBusy = false;
    let lastDigitOcrAt = 0;
    const scannerLine = document.querySelector('.scanner-line');

    scanLog('Unified scanner started');

    function feedback(state) {
        if (!scannerLine) return;
        scannerLine.classList.remove('scanning', 'detecting');
        if (state) scannerLine.classList.add(state);
    }

    async function loop() {
        if (gen !== _scannerGen || !scannerStream) {
            quaggaRunning = false;
            return;
        }
        if (_scannerPaused) {
            requestAnimationFrame(loop);
            return;
        }

        frameCount++;
        if (frameCount === 1) {
            feedback('scanning');
            _setScanStatus(t('scan.status_scanning'), '', _detectorNative ? 'Native+ZBar' : 'ZBar');
        }

        // 1) Native BarcodeDetector — every frame (fastest when available)
        if (_detectorNative) {
            try {
                const codes = await _detectorNative.detect(videoEl);
                if (codes && codes.length > 0) {
                    feedback('detecting');
                    if (_tryConfirmBarcode(codes[0].rawValue, codes[0].format, 'Native')) return;
                }
            } catch (e) {
                if (frameCount === 1) scanLog(`Native detect error: ${e.message}`);
            }
        }

        // 2) ZBar WASM on live video — every frame offline, every 2 online
        const zbarEvery = (_offlineMode || !navigator.onLine) ? 1 : 2;
        if (_detectorZbar && frameCount % zbarEvery === 0) {
            try {
                const codes = await _detectorZbar.detect(videoEl);
                if (codes && codes.length > 0) {
                    feedback('detecting');
                    if (_tryConfirmBarcode(codes[0].rawValue, codes[0].format, 'ZBar')) return;
                }
            } catch (e) {
                if (frameCount <= 2) scanLog(`ZBar video detect error: ${e.message}`);
            }
        }

        // 3) ZBar on enhanced crop variants — every 3 frames
        if (_detectorZbar && frameCount % 3 === 0) {
            const crop = _buildScanCropFrame(videoEl, frameCount);
            if (crop) {
                try {
                    const codes = await _detectorZbar.detect(crop);
                    if (codes && codes.length > 0) {
                        feedback('detecting');
                        if (_tryConfirmBarcode(codes[0].rawValue, codes[0].format, 'ZBar+')) return;
                    }
                } catch (_) {}
            }
        }

        // 4) Local digit OCR (numbers under barcode) — after delay, works offline
        const elapsed = Date.now() - startTime;
        if (elapsed > _LOCAL_OCR_DELAY_MS && !digitOcrBusy && (Date.now() - lastDigitOcrAt) > 1800) {
            lastDigitOcrAt = Date.now();
            digitOcrBusy = true;
            _tryLocalEanDigitOcr(videoEl).finally(() => { digitOcrBusy = false; });
        }

        feedback('scanning');
        if (frameCount % 60 === 0) scanLog(`Scanning f${frameCount} (${Math.round(elapsed / 1000)}s)`);
        requestAnimationFrame(loop);
    }

    requestAnimationFrame(loop);
}

// ===== QUAGGA LEGACY SCANNER (only when native + ZBar unavailable) =====
function startQuaggaScanner(videoEl, isPrimary = true) {
    if (quaggaRunning) return;
    
    const canvas = document.getElementById('scanner-canvas');
    const ctx = canvas.getContext('2d');
    const frontCam = isFrontCamera();
    const scannerLine = document.querySelector('.scanner-line');
    let frameCount = 0;
    let partialCount = 0;
    
    scanLog(`Fallback scanner starting — frontCam: ${frontCam}`);
    
    let scanning = true;
    quaggaRunning = true;
    let lastDetected = '';
    let detectCount = 0;
    let detectionHistory = {};
    
    // Alternate between full frame and center-cropped for better detection
    let scanPass = 0; // 0=full, 1=center-crop
    
    function updateScannerFeedback(state) {
        if (!scannerLine) return;
        scannerLine.classList.remove('scanning', 'detecting');
        if (state) scannerLine.classList.add(state);
    }
    
    function getFrameDataUrl(pass) {
        const vw = videoEl.videoWidth;
        const vh = videoEl.videoHeight;
        
        if (pass % 2 === 0) {
            // Full frame (scaled down for speed)
            const scale = 0.75;
            canvas.width = Math.round(vw * scale);
            canvas.height = Math.round(vh * scale);
            ctx.drawImage(videoEl, 0, 0, canvas.width, canvas.height);
        } else {
            // Center crop: 70% wide, 40% tall — focused on barcode area
            const cropW = Math.round(vw * 0.7);
            const cropH = Math.round(vh * 0.4);
            const sx = Math.round((vw - cropW) / 2);
            const sy = Math.round((vh - cropH) / 2);
            canvas.width = cropW;
            canvas.height = cropH;
            ctx.drawImage(videoEl, sx, sy, cropW, cropH, 0, 0, cropW, cropH);
        }
        
        // Apply enhancement for front cam, offline, or low-light conditions
        if (frontCam || _offlineMode || !navigator.onLine) {
            enhanceCanvasForBarcode(ctx, canvas.width, canvas.height);
        }
        
        return canvas.toDataURL('image/jpeg', 0.85);
    }
    
    const offlineBoost = _offlineMode || !navigator.onLine;
    const frameInterval = offlineBoost ? 40 : 60;

    function scanFrame() {
        if (!scanning || !scannerStream) return;
        if (_scannerPaused) {
            setTimeout(scanFrame, frameInterval);
            return;
        }
        frameCount++;
        scanPass = (scanPass + 1) % 2;
        
        const dataUrl = getFrameDataUrl(scanPass);
        
        if (frameCount === 1) {
            scanLog(`Frame #1 — video: ${videoEl.videoWidth}x${videoEl.videoHeight}`);
            updateScannerFeedback('scanning');
            if (isPrimary) _setScanStatus(t('scan.status_scanning'), '', 'Quagga');
        }
        
        let callbackCalled = false;
        const safetyTimer = setTimeout(() => {
            if (!callbackCalled && scanning) {
                scanLog(`Quagga timeout on f${frameCount}, retrying...`);
                setTimeout(scanFrame, 50);
            }
        }, 2000);
        
        try {
            const imgSize = Math.max(canvas.width, canvas.height);
            Quagga.decodeSingle({
                src: dataUrl,
                numOfWorkers: 0,
                inputStream: { size: Math.min(imgSize, 640) },
                decoder: {
                    readers: [
                        'ean_reader',
                        'ean_8_reader',
                        'upc_reader',
                        'upc_e_reader',
                        'code_128_reader',
                        'code_39_reader'
                    ],
                    multiple: false
                },
                locate: true,
                locator: { patchSize: offlineBoost ? 'large' : 'medium', halfSample: !offlineBoost }
            }, function(result) {
                callbackCalled = true;
                clearTimeout(safetyTimer);
                if (result && result.codeResult) {
                    const code = result.codeResult.code;
                    const format = result.codeResult.format;
                    partialCount++;
                    const passName = ['full','crop','full+enh','crop+enh'][scanPass];
                    scanLog(`Partial #${partialCount} [f${frameCount} ${passName}]: ${code} (${format})`);
                    updateScannerFeedback('detecting');
                    
                    if (!detectionHistory[code]) detectionHistory[code] = { count: 0, lastFrame: 0 };
                    detectionHistory[code].count++;
                    detectionHistory[code].lastFrame = frameCount;
                    
                    if (code === lastDetected) {
                        detectCount++;
                    } else {
                        lastDetected = code;
                        detectCount = 1;
                    }
                    
                    const dominated = detectionHistory[code];
                    const passName2 = ['full','crop'][scanPass];
                    // EAN/UPC: confirm on first hit (checksum validated)
                    const highConf = ['ean_reader','ean_8_reader','upc_reader','upc_e_reader'].includes(format);
                    if (highConf || detectCount >= 2 || dominated.count >= 2) {
                        // Validate EAN/UPC checksum — Quagga can occasionally return false positives
                        if (highConf && !validateEANChecksum(code)) {
                            _invalidBarcodeCount++;
                            scanLog(`Invalid EAN checksum: ${code} (retry #${_invalidBarcodeCount})`);
                            _setScanStatus(t('scan.status_invalid').replace('{code}', code), 'invalid', 'Quagga');
                            lastDetected = ''; detectCount = 0; // reset confidence
                            if (scanning) setTimeout(scanFrame, frameInterval);
                            return;
                        }
                        scanning = false;
                        quaggaRunning = false;
                        updateScannerFeedback(null);
                        scanLog(`CONFIRMED: ${code} [${passName2}] f${frameCount} consec:${detectCount} total:${dominated.count}`);
                        _hideScanLiveCode();
                        _setScanStatus(t('scan.status_confirmed'), 'confirmed');
                        onBarcodeDetected(code);
                        return;
                    }
                    _showScanLiveCode(code);
                    _setScanStatus(t('scan.status_partial').replace('{code}', code), 'partial');
                } else {
                    updateScannerFeedback('scanning');
                }
                if (scanning) {
                    if (frameCount % 20 === 0) {
                        scanLog(`Scanning... f${frameCount}, partials: ${partialCount}`);
                    }
                    setTimeout(scanFrame, frameInterval);
                }
            });
        } catch (e) {
            callbackCalled = true;
            clearTimeout(safetyTimer);
            scanLog(`Quagga error: ${e.message}`);
            if (scanning) setTimeout(scanFrame, offlineBoost ? 200 : 500);
        }
    }
    
    setTimeout(scanFrame, offlineBoost ? 80 : 200);
}

// Enhance low-quality camera frames for better barcode recognition
function enhanceCanvasForBarcode(ctx, w, h) {
    const imageData = ctx.getImageData(0, 0, w, h);
    const d = imageData.data;
    // Convert to high-contrast grayscale
    for (let i = 0; i < d.length; i += 4) {
        // Luminance
        let gray = 0.299 * d[i] + 0.587 * d[i+1] + 0.114 * d[i+2];
        // Increase contrast
        gray = ((gray - 128) * 1.5) + 128;
        gray = gray < 0 ? 0 : gray > 255 ? 255 : gray;
        // Threshold to make bars more distinct
        gray = gray < 140 ? 0 : 255;
        d[i] = d[i+1] = d[i+2] = gray;
    }
    ctx.putImageData(imageData, 0, 0);
}

function stopScanner() {
    _scannerPaused = false;
    quaggaRunning = false;
    _scanZoomLevel = 2; // always 2x on next start
    _torchActive = false;
    const aiBtn = document.getElementById('scan-ai-manual-btn');
    if (aiBtn) aiBtn.style.display = 'none';
    if (scannerStream) {
        scannerStream.getTracks().forEach(t => t.stop());
        scannerStream = null;
    }
    const video = document.getElementById('scanner-video');
    if (video) { video.srcObject = null; video.style.transform = ''; }
    // Reset torch button
    const tb = document.getElementById('scan-torch-btn');
    if (tb) tb.classList.remove('torch-on');
    // Hide live code
    _hideScanLiveCode();
    _clearAiMatchPanel();
    // Also stop AI camera
    if (aiStream) {
        aiStream.getTracks().forEach(t => t.stop());
        aiStream = null;
    }
    const aiVideo = document.getElementById('ai-video');
    if (aiVideo) aiVideo.srcObject = null;
}

const _barcodeSessionCache = new Map();

function _barcodeCacheKey(barcode) {
    return String(barcode || '').replace(/\D/g, '');
}

/** Fix unit/qty from stored notes; fire-and-forget DB update when needed. */
function _applyLocalBarcodeProductFixes(product) {
    if (!product) return;
    if (product.unit === 'pz' && product.default_quantity === 0 && product.notes) {
        const pesoMatch = product.notes.match(/Peso:\s*([^·]+)/);
        if (pesoMatch) {
            const weightStr = pesoMatch[1].trim();
            const detected = detectUnitAndQuantity(weightStr);
            if (detected.unit !== 'pz') {
                product.unit = detected.unit;
                product.default_quantity = detected.quantity;
                product.weight_info = weightStr;
                if (detected.packageUnit) product.package_unit = detected.packageUnit;
                if (detected.confCount) product._confCount = detected.confCount;
                api('product_save', {}, 'POST', {
                    id: product.id,
                    barcode: product.barcode,
                    name: product.name,
                    brand: product.brand || '',
                    category: product.category || '',
                    image_url: product.image_url || '',
                    unit: detected.unit,
                    default_quantity: detected.quantity,
                    package_unit: detected.packageUnit || '',
                    notes: product.notes,
                }).catch(() => {});
            }
        }
    }
    if (!product.weight_info && product.notes) {
        const pesoMatch = product.notes.match(/Peso:\s*([^·]+)/);
        if (pesoMatch) product.weight_info = pesoMatch[1].trim();
    }
    if (product.weight_info && product.unit === 'conf' && !product._confCount) {
        const detected = detectUnitAndQuantity(product.weight_info);
        if (detected.confCount) product._confCount = detected.confCount;
    }
}

function _externalBarcodeNotes(p) {
    const notesParts = [];
    if (p.quantity_info) notesParts.push(`${t('product.weight_label')}: ${p.quantity_info}`);
    if (p.nutriscore) notesParts.push(`Nutriscore: ${p.nutriscore.toUpperCase()}`);
    if (p.nova_group) notesParts.push(`NOVA: ${p.nova_group}`);
    if (p.ecoscore) notesParts.push(`Ecoscore: ${p.ecoscore.toUpperCase()}`);
    if (p.origin) notesParts.push(`${t('product.origin_label')}: ${p.origin}`);
    if (p.labels) notesParts.push(`${t('product.labels_label')}: ${p.labels}`);
    return notesParts.join(' · ');
}

function _currentProductFromExternal(p, barcode, saveId) {
    const detected = detectUnitAndQuantity(p.quantity_info);
    return {
        id: saveId,
        barcode: barcode,
        name: p.name || t('product.not_recognized'),
        brand: p.brand || '',
        category: p.category || '',
        image_url: p.image_url || '',
        unit: detected.unit,
        default_quantity: detected.quantity,
        package_unit: detected.packageUnit || '',
        _confCount: detected.confCount || 0,
        weight_info: p.quantity_info || '',
        nutriscore: p.nutriscore || '',
        ingredients: p.ingredients || '',
        allergens: p.allergens || '',
        conservation: p.conservation || '',
        origin: p.origin || '',
        nova_group: p.nova_group || '',
        ecoscore: p.ecoscore || '',
        labels: p.labels || '',
        stores: p.stores || '',
    };
}

async function _confirmShoppingScanMatch(product) {
    if (!_shoppingBoughtFlow || !_spesaScanTarget?.name || !product?.name) return true;
    const target = _spesaScanTarget.name;
    const scanned = product.name;
    const shoppingName = product.shopping_name || scanned;
    if (_findSimilarItem(scanned, [{ name: target }])) return true;
    if (_productMatchesShoppingFamily(scanned, target)) return true;
    if (_productMatchesShoppingFamily(shoppingName, target)) return true;
    const msg = t('shopping.scan_mismatch_prompt')
        .replace('{expected}', target)
        .replace('{scanned}', scanned);
    return window.confirm(msg);
}

async function _finishBarcodeResolved(barcode) {
    showLoading(false);
    if (_shoppingBoughtFlow && _spesaScanTarget && currentProduct) {
        const ok = await _confirmShoppingScanMatch(currentProduct);
        if (!ok) {
            showToast(t('shopping.scan_mismatch_cancelled'), 'info');
            _setScanStatus(t('scan.status_scanning'), '', '');
            return;
        }
    }
    addToScanRecents(currentProduct);
    _showScanConfirm(currentProduct.name);
    stopScanner();
    const delay = (_spesaMode || _shoppingBoughtFlow) ? 120 : 300;
    const next = (_spesaMode || _shoppingBoughtFlow) ? showAddForm : showProductAction;
    setTimeout(() => next(), delay);
}

async function _resolveBarcodeLookup(barcode) {
    const key = _barcodeCacheKey(barcode);
    if (_barcodeSessionCache.has(key)) {
        return _barcodeSessionCache.get(key);
    }
    const result = await api('resolve_barcode', { barcode: key });
    _barcodeSessionCache.set(key, result);
    return result;
}

async function _handleBarcodeResolve(result, barcode) {
    const code = _barcodeCacheKey(barcode);
    if (!result?.found) return false;

    if (result.source === 'local' && result.product) {
        currentProduct = result.product;
        _applyLocalBarcodeProductFixes(currentProduct);
        _finishBarcodeResolved(code);
        return true;
    }

    if (result.product) {
        const p = result.product;
        const detected = detectUnitAndQuantity(p.quantity_info);
        const saveResult = await api('product_save', {}, 'POST', {
            barcode: code,
            name: p.name || t('product.not_recognized'),
            brand: p.brand || '',
            category: mapToLocalCategory(p.category || '', p.name || '', p.brand || ''),
            image_url: p.image_url || '',
            unit: detected.unit,
            default_quantity: detected.quantity,
            package_unit: detected.packageUnit || '',
            notes: _externalBarcodeNotes(p),
        });
        if (saveResult?.id || saveResult?.success) {
            currentProduct = _currentProductFromExternal(p, code, saveResult.id);
            _finishBarcodeResolved(code);
            return true;
        }
        if (saveResult?.error === 'barcode_already_used' && saveResult.existing_id) {
            const full = await api('product_get', { id: saveResult.existing_id }).catch(() => null);
            if (full?.product) {
                currentProduct = full.product;
                _applyLocalBarcodeProductFixes(currentProduct);
                _finishBarcodeResolved(code);
                return true;
            }
        }
        if (!saveResult?.id && !saveResult?.success) {
            console.error('[barcode save]', saveResult);
            const retry = await api('product_save', {}, 'POST', {
                barcode: code,
                name: p.name || t('product.not_recognized'),
                brand: p.brand || '',
                category: mapToLocalCategory(p.category || '', p.name || '', p.brand || ''),
                unit: 'pz',
                default_quantity: 1,
                image_url: p.image_url || '',
            }).catch(() => null);
            if (retry?.id || retry?.success) {
                currentProduct = _currentProductFromExternal(p, code, retry.id);
                _finishBarcodeResolved(code);
                return true;
            }
            if (retry?.error === 'barcode_already_used' && retry.existing_id) {
                const full = await api('product_get', { id: retry.existing_id }).catch(() => null);
                if (full?.product) {
                    currentProduct = full.product;
                    _applyLocalBarcodeProductFixes(currentProduct);
                    _finishBarcodeResolved(code);
                    return true;
                }
            }
        }
    }
    return false;
}

async function onBarcodeDetected(barcode) {
    _dismissFamilySiblingPrompt();
    _resetAiFallbackForNewScan();
    showLoading(true);

    if (navigator.vibrate) navigator.vibrate(100);

    try {
        const code = _barcodeCacheKey(barcode);
        const result = await _resolveBarcodeLookup(code);
        if (await _handleBarcodeResolve(result, code)) return;

        showLoading(false);
        showToast(t('error.not_found_manual'), 'error');
        _setScanStatus(t('scan.status_scanning'), '', '');
        resumeScanner();
    } catch (err) {
        showLoading(false);
        console.error('Barcode lookup error:', err);
        showToast(t('error.search'), 'error');
        resumeScanner();
    }
}

function submitManualBarcode() {
    const input = document.getElementById('manual-barcode-input');
    autoSubmitEAN(input, true);
}

// Auto-submit when user finishes typing a valid EAN-13 or EAN-8
function autoSubmitEAN(inputEl, force = false) {
    const raw = (inputEl.value || '').replace(/\D/g, '');
    inputEl.value = raw; // strip non-digits live
    if (!raw) return;
    const isComplete = raw.length === 13 || raw.length === 8;
    const isValid = isComplete && validateEANChecksum(raw);
    if (isValid) {
        // Auto-submit on valid EAN
        stopScanner();
        onBarcodeDetected(raw);
        return;
    }
    if (force) {
        if (!raw) { showToast(t('error.barcode_empty'), 'error'); inputEl.focus(); return; }
        if (!/^\d{4,14}$/.test(raw)) { showToast(t('error.barcode_format'), 'error'); inputEl.focus(); return; }
        if (isComplete && !isValid) {
            showToast(t('error.barcode_checksum'), 'error'); inputEl.focus(); return;
        }
        stopScanner();
        onBarcodeDetected(raw);
    }
}

// ===== QUICK NAME ENTRY (for loose/unpackaged products) =====
async function submitQuickName() {
    const input = document.getElementById('quick-product-name');
    const name = (input.value || '').trim();
    if (!name || name.length < 2) {
        showToast(t('error.min_chars'), 'error');
        input.focus();
        return;
    }
    
    _dismissFamilySiblingPrompt();
    stopScanner();
    showLoading(true);
    
    try {
        // Search local products DB
        const localData = await api('products_search', { q: name });
        const localProducts = (localData.products || []).slice(0, 5);
        
        showLoading(false);
        
        if (localProducts.length > 0) {
            // Show results to pick from + option to create new
            showQuickNameResults(name, localProducts);
        } else {
            // No local results — create new product directly
            await createQuickProduct(name);
        }
    } catch (err) {
        showLoading(false);
        console.error('Quick name search error:', err);
        showToast(t('error.search_short'), 'error');
    }
}

function showQuickNameResults(searchName, products) {
    const container = document.querySelector('.quick-name-entry');
    
    // Remove any previous results
    const oldResults = container.querySelector('.quick-name-results');
    if (oldResults) oldResults.remove();
    
    const resultsDiv = document.createElement('div');
    resultsDiv.className = 'quick-name-results';
    
    // Existing products
    products.forEach(p => {
        const catIcon = CATEGORY_ICONS[mapToLocalCategory(p.category, p.name)] || '📦';
        const item = document.createElement('div');
        item.className = 'quick-name-result-item';
        item.innerHTML = `
            <span class="qnr-icon">${catIcon}</span>
            <div class="qnr-info">
                <div class="qnr-name">${escapeHtml(p.name)}</div>
                <div class="qnr-detail">${p.brand ? escapeHtml(p.brand) + ' · ' : ''}${p.barcode ? '📊 ' + p.barcode : t('product.no_barcode')}</div>
            </div>
        `;
        item.onclick = () => selectQuickProduct(p);
        resultsDiv.appendChild(item);
    });
    
    // "Create new" button
    const newItem = document.createElement('div');
    newItem.className = 'quick-name-result-item qnr-new';
    newItem.innerHTML = `
        <span class="qnr-icon">➕</span>
        <div class="qnr-info">
            <div class="qnr-name">${t('scan.create_named').replace('{name}', '"' + escapeHtml(searchName) + '"')}</div>
            <div class="qnr-detail">${t('scan.new_without_barcode')}</div>
        </div>
    `;
    newItem.onclick = () => createQuickProduct(searchName);
    resultsDiv.appendChild(newItem);
    
    container.appendChild(resultsDiv);
}

function selectQuickProduct(product) {
    currentProduct = {
        id: product.id,
        barcode: product.barcode || '',
        name: product.name,
        brand: product.brand || '',
        category: product.category || '',
        image_url: product.image_url || '',
        unit: product.unit || 'pz',
        default_quantity: product.default_quantity || 1,
    };
    // Extract weight_info from notes if available
    if (product.notes) {
        const pesoMatch = product.notes.match(/Peso:\s*([^·]+)/);
        if (pesoMatch) currentProduct.weight_info = pesoMatch[1].trim();
    }
    clearQuickNameResults();
    // Clear the search input
    const qInput = document.getElementById('quick-product-name');
    if (qInput) qInput.value = '';
    showProductAction();
}

async function createQuickProduct(name) {
    showLoading(true);
    
    // Auto-detect category from name (sync regex first)
    const category = guessCategoryFromName(name);
    
    try {
        const result = await api('product_save', {}, 'POST', {
            name: name,
            brand: '',
            category: category,
            unit: 'pz',
            default_quantity: 1,
        });
        
        if (result.success || result.id) {
            currentProduct = {
                id: result.id,
                name: name,
                brand: '',
                category: category,
                unit: 'pz',
                default_quantity: 1,
            };
            showLoading(false);
            clearQuickNameResults();
            showToast(t('toast.product_created'), 'success');

            // If regex gave 'altro', try embedding in background and silently update
            if (category === 'altro' && typeof classifyCategoryByEmbedding === 'function') {
                classifyCategoryByEmbedding(name).then(async embCat => {
                    if (!embCat || !result.id) return;
                    try {
                        await api('product_save', {}, 'POST', {
                            id: result.id,
                            name: name,
                            brand: '',
                            category: embCat,
                            unit: 'pz',
                            default_quantity: 1,
                        });
                        if (currentProduct && currentProduct.id === result.id) {
                            currentProduct.category = embCat;
                        }
                    } catch (_) { /* silent */ }
                });
            }

            showProductAction();
        } else {
            showLoading(false);
            showToast(result.error || t('error.save'), 'error');
        }
    } catch (err) {
        showLoading(false);
        console.error('Quick product creation error:', err);
        showToast(t('error.connection'), 'error');
    }
}

function clearQuickNameResults() {
    const container = document.querySelector('.quick-name-entry');
    if (container) {
        const results = container.querySelector('.quick-name-results');
        if (results) results.remove();
    }
    const input = document.getElementById('quick-product-name');
    if (input) input.value = '';
}

function startManualEntry(barcode = '') {
    stopScanner();
    // Reset form
    document.getElementById('pf-id').value = '';
    document.getElementById('pf-name').value = '';
    document.getElementById('pf-brand').value = '';
    document.getElementById('pf-category').value = '';
    document.getElementById('pf-unit').value = 'pz';
    document.getElementById('pf-defqty').value = '1';
    document.getElementById('pf-notes').value = '';
    document.getElementById('pf-barcode').value = barcode || '';
    document.getElementById('pf-image').value = '';
    document.getElementById('pf-image-preview').style.display = 'none';
    document.getElementById('product-form-title').textContent = t('product.title_new');
    const pfAiRow = document.getElementById('pf-ai-fill-row');
    if (pfAiRow) pfAiRow.style.display = 'block';

    // Show barcode hint when no barcode was passed
    _updateBarcodeHint();
    document.getElementById('pf-barcode').addEventListener('input', _updateBarcodeHint);
    
    // Remove datalist/autocomplete suggestions for new products (they cause confusion)
    document.getElementById('pf-name').removeAttribute('list');
    document.getElementById('pf-brand').removeAttribute('list');
    
    // Reset conf-size-row visibility
    const pfConfRow = document.getElementById('pf-conf-size-row');
    if (pfConfRow) pfConfRow.style.display = 'none';
    document.getElementById('pf-conf-size').value = '';
    document.getElementById('pf-conf-unit').value = 'g';
    
    // Reset manual-edit tracking flags
    document.getElementById('pf-category').dataset.manuallySet = 'false';
    document.getElementById('pf-defqty').dataset.manuallySet = 'false';
    
    // Track if user manually changes the quantity field
    const qtyInput = document.getElementById('pf-defqty');
    qtyInput.removeEventListener('input', markQtyManuallySet);
    qtyInput.addEventListener('input', markQtyManuallySet);
    
    // Auto-detect name → category when typing
    const nameInput = document.getElementById('pf-name');
    nameInput.removeEventListener('input', autoDetectCategory);
    nameInput.addEventListener('input', autoDetectCategory);
    
    showPage('product-form');
}

function markQtyManuallySet() {
    document.getElementById('pf-defqty').dataset.manuallySet = 'true';
}

function autoDetectCategory() {
    const name = document.getElementById('pf-name').value.toLowerCase();
    if (name.length < 3) return;
    
    const catSelect = document.getElementById('pf-category');
    // Don't override if user already manually selected something
    if (catSelect.dataset.manuallySet === 'true') return;
    
    // Keywords → category mapping
    const keyword2cat = {
        'latte': 'latticini', 'yogurt': 'latticini', 'formaggio': 'latticini', 'mozzarella': 'latticini',
        'burro': 'latticini', 'panna': 'latticini', 'ricotta': 'latticini', 'mascarpone': 'latticini',
        'gorgonzola': 'latticini', 'parmigiano': 'latticini', 'grana': 'latticini', 'burrata': 'latticini',
        'stracchino': 'latticini', 'uova': 'latticini',
        'pollo': 'carne', 'manzo': 'carne', 'maiale': 'carne', 'vitello': 'carne', 'tacchino': 'carne',
        'prosciutto': 'carne', 'salame': 'carne', 'bresaola': 'carne', 'mortadella': 'carne',
        'wurstel': 'carne', 'macinato': 'carne', 'speck': 'carne',
        'salmone': 'pesce', 'tonno': 'pesce', 'sgombro': 'pesce', 'pesce': 'pesce', 'merluzzo': 'pesce',
        'mela': 'frutta', 'mele': 'frutta', 'banana': 'frutta', 'arancia': 'frutta', 'pera': 'frutta',
        'fragola': 'frutta', 'uva': 'frutta', 'kiwi': 'frutta', 'limone': 'frutta',
        'insalata': 'verdura', 'pomodor': 'verdura', 'zucchin': 'verdura', 'patat': 'verdura',
        'cipoll': 'verdura', 'carota': 'verdura', 'spinaci': 'verdura', 'rucola': 'verdura',
        'peperoni': 'verdura', 'melanzane': 'verdura', 'broccoli': 'verdura',
        'pasta': 'pasta', 'spaghetti': 'pasta', 'penne': 'pasta', 'fusilli': 'pasta', 'riso': 'pasta',
        'farina': 'pasta', 'rigatoni': 'pasta', 'farfalle': 'pasta',
        'pane': 'pane', 'fette biscottate': 'pane', 'pancarrè': 'pane', 'pan carrè': 'pane',
        'grissini': 'pane', 'crackers': 'pane', 'cracker': 'pane',
        'surgelat': 'surgelati', 'findus': 'surgelati', 'gelato': 'surgelati',
        'acqua': 'bevande', 'succo': 'bevande', 'birra': 'bevande', 'vino': 'bevande',
        'coca cola': 'bevande', 'aranciata': 'bevande', 'tè': 'bevande', 'caffè': 'bevande',
        'olio': 'condimenti', 'aceto': 'condimenti', 'sale': 'condimenti', 'pepe': 'condimenti',
        'maionese': 'condimenti', 'ketchup': 'condimenti', 'senape': 'condimenti', 'zucchero': 'condimenti',
        'biscott': 'snack', 'cioccolat': 'snack', 'nutella': 'snack', 'merendine': 'snack',
        'patatine': 'snack', 'caramelle': 'snack',
        'pelati': 'conserve', 'passata': 'conserve', 'legumi': 'conserve', 'ceci': 'conserve',
        'fagioli': 'conserve', 'lenticchie': 'conserve', 'marmellata': 'conserve', 'miele': 'conserve',
        'cereali': 'cereali', 'muesli': 'cereali', 'fiocchi': 'cereali',
    };
    
    for (const [keyword, cat] of Object.entries(keyword2cat)) {
        if (name.includes(keyword)) {
            catSelect.value = cat;
            onCategoryChange(true);
            return;
        }
    }

    // ── Embedding fallback: async, only when keywords didn't match ──────────
    // Kick off model load (no-op if already loaded/loading) and update the
    // select once the result is ready.  Only runs when pipeline is available.
    if (typeof classifyCategoryByEmbedding === 'function') {
        classifyCategoryByEmbedding(document.getElementById('pf-name').value).then(embCat => {
            if (!embCat) return;
            // Re-check manuallySet — user might have picked something while awaiting
            const sel = document.getElementById('pf-category');
            if (!sel || sel.dataset.manuallySet === 'true') return;
            sel.value = embCat;
            onCategoryChange(true);
        });
    }
}

function onCategoryChange(fromAutoDetect = false) {
    const cat = document.getElementById('pf-category').value;
    const unitSelect = document.getElementById('pf-unit');
    const qtyInput = document.getElementById('pf-defqty');
    
    // If user manually changed category via dropdown, don't auto-fill qty/unit
    if (!fromAutoDetect) {
        // Mark qty as "set" so future auto-detects won't overwrite either
        qtyInput.dataset.manuallySet = 'true';
        return;
    }
    
    // Auto-detect from name: suggest default unit/qty based on category
    // BUT only if user hasn't manually changed the quantity field
    const catDefaults = {
        'latticini': { unit: 'pz', qty: 1 },
        'carne': { unit: 'g', qty: 500 },
        'pesce': { unit: 'g', qty: 300 },
        'frutta': { unit: 'g', qty: 1000 },
        'verdura': { unit: 'g', qty: 500 },
        'pasta': { unit: 'g', qty: 500 },
        'pane': { unit: 'pz', qty: 1 },
        'surgelati': { unit: 'g', qty: 450 },
        'bevande': { unit: 'ml', qty: 1000 },
        'condimenti': { unit: 'pz', qty: 1 },
        'snack': { unit: 'g', qty: 250 },
        'conserve': { unit: 'g', qty: 400 },
        'cereali': { unit: 'g', qty: 500 },
        'igiene': { unit: 'pz', qty: 1 },
        'pulizia': { unit: 'pz', qty: 1 },
    };
    
    if (catDefaults[cat]) {
        // Only auto-fill unit/qty if user hasn't manually touched them
        const unitManuallySet = unitSelect.dataset.manuallySet === 'true';
        if (qtyInput.dataset.manuallySet !== 'true' && !unitManuallySet) {
            unitSelect.value = catDefaults[cat].unit;
            qtyInput.value = catDefaults[cat].qty;
        } else if (qtyInput.dataset.manuallySet !== 'true' && unitManuallySet) {
            qtyInput.value = catDefaults[cat].qty;
        }
    }
}

function onPfUnitChange() {
    const unitEl = document.getElementById('pf-unit');
    if (unitEl) unitEl.dataset.manuallySet = 'true';
    const unit = unitEl?.value;
    const confRow = document.getElementById('pf-conf-size-row');
    if (confRow) confRow.style.display = unit === 'conf' ? 'block' : 'none';
}

function _updateBarcodeHint() {
    const hint = document.getElementById('pf-barcode-hint');
    const val = (document.getElementById('pf-barcode')?.value || '').trim();
    if (hint) hint.style.display = val ? 'none' : 'block';
}

/**
 * Open a temporary camera modal to scan a barcode and fill the pf-barcode field.
 * Uses BarcodeDetector if available, otherwise shows manual-input fallback.
 */
async function scanBarcodeForForm() {
    const overlayEl = document.getElementById('modal-overlay');
    const contentEl = document.getElementById('modal-content');

    let stream = null;
    let scanning = true;

    const stopStream = () => {
        scanning = false;
        if (stream) stream.getTracks().forEach(t => t.stop());
        stream = null;
    };

    const closeScanner = () => {
        stopStream();
        overlayEl.style.display = 'none';
    };

    contentEl.innerHTML = `
        <div class="modal-header">
            <h3>${t('scanner.title_barcode')}</h3>
            <button class="modal-close" onclick="document.getElementById('modal-overlay').style.display='none'">✕</button>
        </div>
        <div style="position:relative;width:100%;background:#000;border-radius:10px;overflow:hidden;aspect-ratio:4/3">
            <video id="pf-bc-video" autoplay playsinline muted style="width:100%;height:100%;object-fit:cover"></video>
            <div class="scanner-line scanning" style="position:absolute;left:0;right:0;top:50%;transform:translateY(-50%);height:2px;background:rgba(59,130,246,0.8)"></div>
        </div>
        <p style="text-align:center;margin-top:12px;color:var(--text-muted);font-size:0.88rem">${t('scanner.barcode_hint')}</p>
        <div style="margin-top:10px;text-align:center">
            <input type="text" id="pf-bc-manual" class="form-input" placeholder="${t('scanner.barcode_manual_placeholder')}" inputmode="numeric" maxlength="14" style="max-width:260px;display:inline-block" oninput="
                const raw=(this.value||'').replace(/\\D/g,''); this.value=raw;
                if((raw.length===13||raw.length===8)&&validateEANChecksum(raw)){
                    stopStream();
                    document.getElementById('pf-barcode').value=raw;
                    _updateBarcodeHint();
                    document.getElementById('modal-overlay').style.display='none';
                    if(navigator.vibrate)navigator.vibrate(80);
                }
            ">
            <button class="btn btn-primary" style="margin-top:8px;width:100%" onclick="
                const v = (document.getElementById('pf-bc-manual').value||'').replace(/\\D/g,'');
                if(v){ stopStream(); document.getElementById('pf-barcode').value=v; _updateBarcodeHint(); document.getElementById('modal-overlay').style.display='none'; }
            ">${t('scanner.barcode_use_btn')}</button>
        </div>
    `;
    overlayEl.style.display = 'flex';

    // Attach close handler (clicking backdrop)
    overlayEl.onclick = (e) => { if (e.target === overlayEl) { stopStream(); overlayEl.style.display = 'none'; overlayEl.onclick = null; } };

    try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
        const video = document.getElementById('pf-bc-video');
        video.srcObject = stream;
        await video.play();

        if (!('BarcodeDetector' in window)) {
            // No native API — just let user type manually
            return;
        }

        const detector = new BarcodeDetector({ formats: ['ean_13','ean_8','code_128','code_39','upc_a','upc_e'] });
        const detectionHistory = {};

        const scanFrame = async () => {
            if (!scanning || !stream) return;
            try {
                const barcodes = await detector.detect(video);
                if (barcodes.length > 0) {
                    const code = barcodes[0].rawValue;
                    const fmt = barcodes[0].format;
                    detectionHistory[code] = (detectionHistory[code] || 0) + 1;
                    // EAN/UPC: confirm immediately (checksum-validated by detector)
                    const highConf = ['ean_13','ean_8','upc_a','upc_e'].includes(fmt);
                    if (highConf || detectionHistory[code] >= 2) {
                        scanning = false;
                        stopStream();
                        overlayEl.style.display = 'none';
                        overlayEl.onclick = null;
                        document.getElementById('pf-barcode').value = code;
                        _updateBarcodeHint();
                        if (navigator.vibrate) navigator.vibrate(80);
                        showToast(t('scan.barcode_acquired', { code }), 'success');
                        return;
                    }
                }
            } catch (_) {}
            if (scanning) requestAnimationFrame(scanFrame);
        };
        requestAnimationFrame(scanFrame);

    } catch (err) {
        // Camera not available — user can still type manually
        const videoEl = document.getElementById('pf-bc-video');
        if (videoEl) videoEl.style.display = 'none';
    }
}

async function submitProduct(e) {
    e.preventDefault();
    showLoading(true);
    
    const pfUnit = document.getElementById('pf-unit').value;
    const productData = {
        id: document.getElementById('pf-id').value || null,
        name: document.getElementById('pf-name').value,
        brand: document.getElementById('pf-brand').value,
        category: document.getElementById('pf-category').value,
        unit: pfUnit,
        default_quantity: pfUnit === 'conf' ? (parseFloat(document.getElementById('pf-conf-size')?.value) || 1) : (parseFloat(document.getElementById('pf-defqty').value) || 1),
        package_unit: pfUnit === 'conf' ? (document.getElementById('pf-conf-unit')?.value || '') : '',
        notes: document.getElementById('pf-notes').value,
        barcode: document.getElementById('pf-barcode').value || null,
        image_url: document.getElementById('pf-image').value || '',
    };
    
    try {
        const result = await api('product_save', {}, 'POST', productData);
        if (result.success) {
            currentProduct = { ...productData, id: result.id };
            showLoading(false);
            showToast(result.merged ? t('scan.ai_match_merged_existing') : t('toast.product_saved'), result.merged ? 'info' : 'success');
            showProductAction();
        } else if (result.error === 'barcode_already_used' && result.existing_id) {
            const full = await api('product_get', { id: result.existing_id }).catch(() => null);
            if (full?.product) {
                currentProduct = full.product;
                showLoading(false);
                showToast(t('scan.ai_match_merged_existing'), 'info');
                showProductAction();
            } else {
                showLoading(false);
                showToast(t('error.save'), 'error');
            }
        } else {
            showLoading(false);
            showToast(result.error || t('error.save'), 'error');
        }
    } catch (err) {
        showLoading(false);
        showToast(t('error.connection'), 'error');
    }
}

// ===== PRODUCT ACTION (IN/OUT) =====
function showProductAction() {
    if (!currentProduct) return;
    
    const catIcon = CATEGORY_ICONS[mapToLocalCategory(currentProduct.category, currentProduct.name)] || '📦';
    const nutriscoreColors = { a: '#1e8f4e', b: '#60ac0e', c: '#eeae0e', d: '#ff6f1e', e: '#e63e11' };
    
    let detailsHtml = '';
    
    // Weight / quantity info
    if (currentProduct.weight_info) {
        detailsHtml += `<div class="product-detail-tag">⚖️ ${escapeHtml(currentProduct.weight_info)}</div>`;
    }
    
    // Nutriscore badge
    if (currentProduct.nutriscore) {
        const ns = currentProduct.nutriscore.toLowerCase();
        const nsColor = nutriscoreColors[ns] || '#999';
        detailsHtml += `<div class="product-detail-tag" style="background:${nsColor};color:#fff;font-weight:600">Nutri-Score ${ns.toUpperCase()}</div>`;
    }
    
    // NOVA group
    if (currentProduct.nova_group) {
        const novaLabels = { '1': t('nova.1'), '2': t('nova.2'), '3': t('nova.3'), '4': t('nova.4') };
        detailsHtml += `<div class="product-detail-tag">🏭 NOVA ${currentProduct.nova_group}${novaLabels[currentProduct.nova_group] ? ' - ' + novaLabels[currentProduct.nova_group] : ''}</div>`;
    }
    
    // Ecoscore
    if (currentProduct.ecoscore) {
        const es = currentProduct.ecoscore.toLowerCase();
        const esColor = nutriscoreColors[es] || '#999';
        detailsHtml += `<div class="product-detail-tag" style="background:${esColor};color:#fff;font-weight:600">🌍 Eco-Score ${es.toUpperCase()}</div>`;
    }
    
    // Origin
    if (currentProduct.origin) {
        detailsHtml += `<div class="product-detail-tag">📍 ${escapeHtml(currentProduct.origin)}</div>`;
    }
    
    // Labels (bio, DOP, etc.)
    if (currentProduct.labels) {
        detailsHtml += `<div class="product-detail-tag">🏷️ ${escapeHtml(currentProduct.labels)}</div>`;
    }
    
    // Allergens
    let allergensHtml = '';
    if (currentProduct.allergens) {
        allergensHtml = `<div class="product-allergens">⚠️ <strong>${escapeHtml(t('product.allergens_label'))}</strong> ${escapeHtml(currentProduct.allergens)}</div>`;
    }
    
    // Ingredients (collapsible)
    let ingredientsHtml = '';
    if (currentProduct.ingredients) {
        ingredientsHtml = `
            <details class="product-ingredients">
                <summary>${escapeHtml(t('product.ingredients_summary'))}</summary>
                <p>${escapeHtml(currentProduct.ingredients)}</p>
            </details>
        `;
    }
    
    // Conservation
    let conservationHtml = '';
    if (currentProduct.conservation) {
        conservationHtml = `<div class="product-conservation">🧊 ${escapeHtml(currentProduct.conservation)}</div>`;
    }
    
    // Hero card preview (matches page-use style)
    document.getElementById('action-product-preview').innerHTML = `
        ${currentProduct.image_url ?
            `<img src="${escapeHtml(currentProduct.image_url)}" alt="">` :
            `<span class="use-hero-icon">${catIcon}</span>`
        }
        <div class="use-hero-body">
            <div class="use-hero-name">${escapeHtml(currentProduct.name)}</div>
            ${currentProduct.brand ? `<div class="use-hero-brand">${escapeHtml(currentProduct.brand)}</div>` : ''}
            <div class="use-hero-meta">
                ${currentProduct.weight_info ? `<span class="use-meta-pill use-pill-qty">⚖️ ${escapeHtml(currentProduct.weight_info)}</span>` : ''}
                ${currentProduct.barcode ? `<span class="use-meta-pill action-pill-barcode">📊 ${currentProduct.barcode}</span>` : ''}
            </div>
        </div>
        <button type="button" class="btn-edit-inline" onclick="toggleActionEdit()" title="${t('product.edit_name_brand')}">✏️</button>
    `;
    
    // Check if product needs editing (unknown name, missing info)
    const isUnknown = !currentProduct.name || 
        /sconosciuto|unknown|^$/i.test(currentProduct.name.trim()) ||
        currentProduct.name.trim().length < 2;
    
    // Edit product info section
    let editInfoEl = document.getElementById('action-edit-info');
    if (!editInfoEl) {
        editInfoEl = document.createElement('div');
        editInfoEl.id = 'action-edit-info';
        const preview = document.getElementById('action-product-preview');
        preview.parentElement.insertBefore(editInfoEl, preview.nextSibling);
    }
    
    // Always build the edit form, but only show it auto-opened for unknown products
    const categoryOptions = Object.entries(CATEGORY_LABELS).map(([key, label]) => 
        `<option value="${key}" ${mapToLocalCategory(currentProduct.category, currentProduct.name, currentProduct.brand) === key ? 'selected' : ''}>${label}</option>`
    ).join('');
    
    editInfoEl.innerHTML = `
        <div class="edit-unknown-card ${isUnknown ? 'highlight' : ''}">
            <h4>${isUnknown ? '⚠️ ' + t('product.unknown_product') : '✏️ ' + t('product.edit_info')}</h4>
            ${isUnknown ? `<p class="edit-unknown-hint">${escapeHtml(t('edit.unknown_hint'))}</p>` : ''}
            <div class="edit-unknown-form">
                <div class="form-group">
                    <label>${t('edit.label_name')}</label>
                    <input type="text" id="edit-action-name" class="form-input" value="${escapeHtml(isUnknown ? '' : currentProduct.name)}" placeholder="Es: Latte intero, Pasta penne..." required>
                </div>
                <div class="form-group">
                    <label>${t('product.brand_label')}</label>
                    <input type="text" id="edit-action-brand" class="form-input" value="${escapeHtml(currentProduct.brand || '')}" placeholder="Es: Barilla, Mulino Bianco...">
                </div>
                <div class="form-group">
                    <label>${t('product.category_label')}</label>
                    <select id="edit-action-category" class="form-input">
                        <option value="">${t('form.select_placeholder')}</option>
                        ${categoryOptions}
                    </select>
                </div>
                <div class="form-group">
                    <label>${t('product.notes_label')}</label>
                    <textarea id="edit-action-notes" class="form-input" rows="2" placeholder="${escapeHtml(t('product.notes_placeholder') || '')}">${escapeHtml(currentProduct.notes || '')}</textarea>
                </div>
                <button type="button" class="btn btn-primary full-width" onclick="saveEditedProductInfo()">${t('btn.save_info')}</button>
            </div>
        </div>
    `;
    editInfoEl.style.display = isUnknown ? 'block' : 'none';
    if (isUnknown) {
        setTimeout(() => document.getElementById('edit-action-name')?.focus(), 100);
    }
    
    // Show extra product info section below preview
    let extraInfoEl = document.getElementById('action-product-details');
    if (!extraInfoEl) {
        const container = document.getElementById('action-product-preview').parentElement;
        extraInfoEl = document.createElement('div');
        extraInfoEl.id = 'action-product-details';
        const actionBtns = document.getElementById('action-buttons-container');
        actionBtns.parentElement.insertBefore(extraInfoEl, actionBtns);
    }
    
    if (detailsHtml || allergensHtml || ingredientsHtml || conservationHtml) {
        extraInfoEl.innerHTML = `
            <div class="product-details-card">
                ${detailsHtml ? `<div class="product-detail-tags">${detailsHtml}</div>` : ''}
                ${allergensHtml}
                ${ingredientsHtml}
                ${conservationHtml}
            </div>
        `;
        extraInfoEl.style.display = 'block';
    } else {
        extraInfoEl.style.display = 'none';
        extraInfoEl.innerHTML = '';
    }
    
    // === CHECK INVENTORY FOR THIS PRODUCT ===
    checkInventoryForProduct(currentProduct.id, currentProduct.name).then(({ items: inventoryItems, related: relatedItems }) => {
        _actionInventoryItems = inventoryItems;
        const statusBar = document.getElementById('action-inventory-status');
        const btnsContainer = document.getElementById('action-buttons-container');
        
        if (inventoryItems.length > 0) {
            // Product IS in inventory - show status and 3 buttons
            statusBar.style.display = 'block';
            let totalQty = 0;
            const unit = inventoryItems[0].unit || 'pz';
            const defQty = inventoryItems[0].default_quantity || 0;
            const pkgUnit = inventoryItems[0].package_unit || '';
            const invHtml = inventoryItems.map(inv => {
                const locInfo = LOCATIONS[inv.location] || { icon: '📦', label: inv.location };
                const qtyStr = formatQuantity(inv.quantity, inv.unit, inv.default_quantity, inv.package_unit);
                const pkgF = formatPackageFraction(inv.quantity, inv.default_quantity);
                totalQty += parseFloat(inv.quantity);
                let expiryStr = '';
                if (inv.expiry_date) {
                    const d = daysUntilExpiry(inv.expiry_date);
                if (d < 0) expiryStr = ` · ${t('expiry.badge_expired_ago').replace('{n}', Math.abs(d))}`;
                    else if (d <= 3) expiryStr = ` · ${t('expiry.badge_expires_red').replace('{n}', d)}`;
                    else if (d <= 7) expiryStr = ` · ${t('expiry.badge_expires_yellow').replace('{n}', d)}`;
                    else expiryStr = ` · 📅 ${formatDate(inv.expiry_date)}`;
                }
                const vacuumIcon = inv.vacuum_sealed ? ' 🫙' : '';
                return `<div class="inv-status-item inv-status-item-clickable" onclick="editActionInventoryItem(${inv.id})"><span>${locInfo.icon} ${locInfo.label}${vacuumIcon}${expiryStr}</span><span class="inv-status-qty">${qtyStr}${pkgF ? ' ' + pkgF : ''} ✏️</span></div>`;
            }).join('');
            
            const totalStr = formatQuantity(totalQty, unit, defQty, pkgUnit);
            const totalFrac = formatPackageFraction(totalQty, defQty);
            
            statusBar.innerHTML = `
                <div class="inv-status-header">
                    <span class="inv-status-title">${t('action.have_title')}</span>
                    <div class="inv-status-total-col">
                        <span class="inv-status-total">${totalStr}</span>
                        ${totalFrac ? `<span class="inv-status-total-frac">${totalFrac}</span>` : ''}
                    </div>
                </div>
                <div class="inv-status-items">${invHtml}</div>
            `;
            
            btnsContainer.className = 'action-buttons-4col';
            btnsContainer.innerHTML = `
                <button class="btn btn-huge btn-success" onclick="showAddForm()">
                    <span class="btn-icon">📥</span>
                    <span class="btn-text">${t('action.add_btn')}<br><small>${t('action.add_more_sub')}</small></span>
                </button>
                <button class="btn btn-huge btn-danger" onclick="showUseForm()">
                    <span class="btn-icon">📤</span>
                    <span class="btn-text">${t('action.use_btn')}<br><small>${t('action.use_qty_sub')}</small></span>
                </button>
                <button class="btn btn-huge btn-throw" onclick="showThrowForm()">
                    <span class="btn-icon">🗑️</span>
                    <span class="btn-text">${t('action.throw_btn')}<br><small>${t('action.throw_sub')}</small></span>
                </button>
                <button class="btn btn-huge btn-edit" onclick="openInventoryEdit()">
                    <span class="btn-icon">✏️</span>
                    <span class="btn-text">${t('product.modify_details')}<br><small>${t('action.edit_sub')}</small></span>
                </button>
                <button class="btn btn-recipe-from-ingredient" data-name="${escapeHtml(currentProduct.name)}" onclick="generateRecipeForIngredient(this.dataset.name)">
                    👨‍🍳 ${t('action.create_recipe_btn') || 'Crea una ricetta'}
                </button>
            `;
            // Secondary: catalog edit link below the buttons (one instance only)
            let catalogLink = document.getElementById('catalog-edit-link');
            if (!catalogLink) {
                catalogLink = document.createElement('div');
                catalogLink.id = 'catalog-edit-link';
                catalogLink.style.cssText = 'text-align:center;margin-top:6px';
                btnsContainer.after(catalogLink);
            }
            catalogLink.innerHTML = `<button type="button" class="btn-link-small" onclick="editProductFromAction()">${t('product.edit_catalog')}</button>`;
        } else {
            // Product NOT in inventory - show only AGGIUNGI
            statusBar.style.display = 'none';
            btnsContainer.className = 'action-buttons';
            btnsContainer.innerHTML = `
                <button class="btn btn-huge btn-success" onclick="showAddForm()" style="flex:1">
                    <span class="btn-icon">📥</span>
                    <span class="btn-text">${t('action.add_btn')}<br><small>${t('action.add_sub')}</small></span>
                </button>
            `;
            // Remove catalog-edit link if left over from a previous product
            const orphan = document.getElementById('catalog-edit-link');
            if (orphan) orphan.remove();
        }

        // === RELATED STOCK (same generic family, different product/brand) ===
        const relatedEl = document.getElementById('action-related-stock');
        if (relatedEl) {
            if (relatedItems.length > 0) {
                // Group by product name+brand and sum quantities
                const grouped = {};
                for (const ri of relatedItems) {
                    const key = ri.product_id;
                    if (!grouped[key]) grouped[key] = { item: ri, qty: 0 };
                    grouped[key].qty += parseFloat(ri.quantity) || 0;
                }
                const parts = Object.values(grouped).map(({ item, qty }) => {
                    const qtyStr = formatQuantity(qty, item.unit, item.default_quantity, item.package_unit);
                    const locIcon = (LOCATIONS[item.location] || { icon: '📦' }).icon;
                    const label = item.name + (item.brand ? ` (${item.brand})` : '');
                    return `<span class="related-stock-item">${escapeHtml(label)}: <strong>${qtyStr}</strong> ${locIcon}</span>`;
                }).join('');
                relatedEl.innerHTML = `<div class="action-related-stock-card">🔍 ${t('action.related_stock_title')}: ${parts}</div>`;
                relatedEl.style.display = 'block';
            } else {
                relatedEl.style.display = 'none';
                relatedEl.innerHTML = '';
            }
        }
    });
    
    // Update back button: go back to shopping if came from shopping list scan
    const backBtn = document.getElementById('action-back-btn');
    if (backBtn) backBtn.onclick = () => goBack();

    // Show "shopping target" banner if we came from the shopping list
    const banner = document.getElementById('shopping-scan-target-banner');
    if (banner && _spesaScanTarget) {
        const targetName = _spesaScanTarget.name;
        banner.style.display = 'block';
        if (_spesaScanTarget.mode === 'bought') {
            banner.innerHTML = `
            <div class="shopping-scan-target-info">
                <span class="stb-label">✅ ${escapeHtml(t('shopping.btn_bought'))}</span>
                <span class="stb-name">${escapeHtml(targetName)}</span>
                <span class="stb-hint">${escapeHtml(t('shopping.bought_banner_hint'))}</span>
            </div>
            <div class="shopping-scan-target-actions">
                <button class="btn btn-secondary stb-btn" onclick="_shoppingBoughtFlow=false;_spesaScanTarget=null;document.getElementById('shopping-scan-target-banner').style.display='none';showPage('shopping')">✕ ${t('btn.cancel')}</button>
            </div>`;
        } else {
            banner.innerHTML = `
            <div class="shopping-scan-target-info">
                <span class="stb-label">🛒 ${t('shopping.scan_target_label')}</span>
                <span class="stb-name">${escapeHtml(targetName)}</span>
            </div>
            <div class="shopping-scan-target-actions">
                <button class="btn btn-success stb-btn" onclick="confirmShoppingItemFound()">✅ ${t('shopping.scan_target_found')}</button>
                <button class="btn btn-secondary stb-btn" onclick="_spesaScanTarget=null; document.getElementById('shopping-scan-target-banner').style.display='none'; document.getElementById('action-back-btn').onclick=()=>goBack()">✕ ${t('btn.cancel')}</button>
            </div>`;
        }
    } else if (banner) {
        banner.style.display = 'none';
    }

    showPage('action');
}

// Check if product exists in inventory
async function checkInventoryForProduct(productId, productName) {
    try {
        const data = await api('inventory_list');
        const all = data.inventory || [];
        const exact = all.filter(i => i.product_id == productId);

        // Find inventory items from the same generic family (same shopping_name or first token)
        const firstToken = (_nameTokens(productName || '')[0] || '').toLowerCase();
        const sNameFromExact = exact.length > 0 ? (exact[0].shopping_name || '').toLowerCase() : '';
        const matchToken = firstToken || sNameFromExact;
        const related = matchToken ? all.filter(i => {
            if (i.product_id == productId) return false;
            const iFirst = (_nameTokens(i.name || '')[0] || '').toLowerCase();
            const iSName = (i.shopping_name || '').toLowerCase();
            return iFirst === matchToken || iSName === matchToken ||
                   (sNameFromExact && (iFirst === sNameFromExact || iSName === sNameFromExact));
        }) : [];

        return { items: exact, related };
    } catch(e) {
        return { items: [], related: [] };
    }
}

// === EDIT PRODUCT FROM ACTION PAGE ===
function editProductFromAction() {
    if (!currentProduct) return;
    // Pre-fill the product form with current product data
    document.getElementById('pf-id').value = currentProduct.id || '';
    document.getElementById('pf-name').value = currentProduct.name || '';
    document.getElementById('pf-brand').value = currentProduct.brand || '';
    document.getElementById('pf-barcode').value = currentProduct.barcode || '';
    document.getElementById('pf-image').value = '';
    document.getElementById('pf-notes').value = currentProduct.notes || '';
    document.getElementById('pf-unit').value = currentProduct.unit || 'pz';
    document.getElementById('pf-defqty').value = currentProduct.default_quantity || 1;
    document.getElementById('product-form-title').textContent = t('product.title_edit');
    const pfAiRow = document.getElementById('pf-ai-fill-row');
    if (pfAiRow) pfAiRow.style.display = 'none';
    // Keep barcode hint hidden in edit mode
    const pfBcHint = document.getElementById('pf-barcode-hint');
    if (pfBcHint) pfBcHint.style.display = 'none';

    // Restore datalist for editing (was removed for new products)
    document.getElementById('pf-name').setAttribute('list', 'common-products');
    document.getElementById('pf-brand').setAttribute('list', 'common-brands');

    // Set category
    const cat = mapToLocalCategory(currentProduct.category, currentProduct.name, currentProduct.brand);
    document.getElementById('pf-category').value = cat;
    document.getElementById('pf-category').dataset.manuallySet = 'true';
    document.getElementById('pf-defqty').dataset.manuallySet = 'true';

    // Image preview - not shown in edit mode
    const preview = document.getElementById('pf-image-preview');
    preview.style.display = 'none';

    // Conf size row
    const pfConfRow = document.getElementById('pf-conf-size-row');
    if (currentProduct.unit === 'conf' && pfConfRow) {
        pfConfRow.style.display = 'block';
        document.getElementById('pf-conf-size').value = currentProduct.default_quantity || '';
        document.getElementById('pf-conf-unit').value = currentProduct.package_unit || 'g';
    } else if (pfConfRow) {
        pfConfRow.style.display = 'none';
    }

    showPage('product-form');
}

// === EDIT INVENTORY ITEM FROM ACTION PAGE ===
// === OPEN INVENTORY EDIT — picks item or shows location picker ===
function openInventoryEdit() {
    const items = _actionInventoryItems;
    if (!items || items.length === 0) {
        showToast(t('error.no_inventory_entry') || 'Nessuna voce di inventario trovata', 'error');
        return;
    }
    if (items.length === 1) {
        editActionInventoryItem(items[0].id);
        return;
    }
    // Multiple locations → let user pick which one to edit
    const contentEl = document.getElementById('modal-content');
    contentEl.innerHTML = `
        <div class="modal-header">
            <h3>✏️ ${t('edit.choose_location_title')}</h3>
            <button class="modal-close" onclick="closeModal()">✕</button>
        </div>
        <p style="font-size:0.9rem;color:var(--text-muted);margin:0 0 12px">${t('edit.choose_location_hint')}</p>
        <div style="display:flex;flex-direction:column;gap:8px">
            ${items.map(inv => {
                const locInfo = LOCATIONS[inv.location] || { icon: '📦', label: inv.location };
                const qtyStr = formatQuantity(inv.quantity, inv.unit, inv.default_quantity, inv.package_unit);
                let expiryStr = '';
                if (inv.expiry_date) {
                    const d = daysUntilExpiry(inv.expiry_date);
                    expiryStr = ` · ${d < 0 ? t('expiry.badge_expired_bare') : '📅 ' + formatDate(inv.expiry_date)}`;
                }
                const vacuumStr = inv.vacuum_sealed ? ' 🫙' : '';
                return `<button class="btn btn-secondary full-width" style="justify-content:flex-start;gap:10px;text-align:left"
                    onclick="editActionInventoryItem(${inv.id})">
                    <span style="font-size:1.3rem">${locInfo.icon}</span>
                    <span><strong>${locInfo.label}</strong>${vacuumStr}<br>
                    <small style="color:var(--text-muted)">${qtyStr}${expiryStr}</small></span>
                </button>`;
            }).join('')}
        </div>
    `;
    document.getElementById('modal-overlay').style.display = 'flex';
}

function editActionInventoryItem(inventoryId) {
    const item = _actionInventoryItems.find(i => i.id === inventoryId);
    if (!item) return;
    
    const isConf = (item.unit || 'pz') === 'conf';
    const confSizeVal = (isConf && item.default_quantity > 0) ? item.default_quantity : '';
    const confUnitVal = (isConf && item.package_unit) ? item.package_unit : 'g';
    
    window._editingProduct = { name: item.name || currentProduct.name, category: item.category || currentProduct.category || '' };
    
    document.getElementById('modal-content').innerHTML = `
        <div class="modal-header">
            <h3>${t('edit.title').replace('{name}', escapeHtml(item.name || currentProduct.name))}</h3>
            <button class="modal-close" onclick="closeModal()">✕</button>
        </div>
        <form class="form" onsubmit="submitActionEditInventory(event, ${inventoryId}, ${item.product_id})">
            <div class="form-group">
                <label>${t('add.quantity_label')}</label>
                <div class="qty-control-with-unit">
                    <div class="qty-control">
                        <button type="button" class="qty-btn" onclick="adjustQty('action-edit-qty', -1)">−</button>
                        <input type="number" id="action-edit-qty" value="${item.quantity}" min="0" step="any" class="qty-input">
                        <button type="button" class="qty-btn" onclick="adjustQty('action-edit-qty', 1)">+</button>
                    </div>
                    <span class="qty-unit-badge" id="action-edit-qty-unit" aria-live="polite">${escapeHtml(getUnitDisplayLabel(item.unit || 'pz'))}</span>
                </div>
            </div>
            <div class="form-group">
                <label>${t('product.unit_label')}</label>
                <select id="action-edit-unit" class="form-input" onchange="onActionEditUnitChange()">
                    ${['pz','g','ml','conf'].map(u => `<option value="${u}" ${(item.unit||'pz') === u ? 'selected' : ''}>${u === 'pz' ? 'pz (' + t('units.pieces') + ')' : u === 'g' ? 'g (' + t('units.grams') + ')' : u === 'ml' ? 'ml (' + t('units.millilitres') + ')' : u === 'conf' ? 'conf (' + t('units.boxes') + ')' : u}</option>`).join('')}
                </select>
            </div>
            <div class="form-group" id="action-edit-conf-group" style="display:${isConf ? 'block' : 'none'}">
                <label>${t('product.conf_size_label')}</label>
                <div class="conf-size-inputs">
                    <input type="number" id="action-edit-conf-size" class="form-input conf-size-input" min="1" step="any" value="${confSizeVal}" placeholder="${t('product.conf_size_placeholder')}">
                    <select id="action-edit-conf-unit" class="form-input conf-size-unit">
                        ${['g','ml'].map(u => `<option value="${u}" ${confUnitVal === u ? 'selected' : ''}>${u}</option>`).join('')}
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>${t('inventory.label_position')}</label>
                <div class="location-selector">
                    ${Object.entries(LOCATIONS).map(([k, v]) => `
                        <button type="button" class="loc-btn ${item.location === k ? 'active' : ''}" 
                            onclick="this.parentElement.querySelectorAll('.loc-btn').forEach(b=>b.classList.remove('active'));this.classList.add('active');document.getElementById('action-edit-loc').value='${k}';recalcEditExpiry('action-edit-loc','action-edit-vacuum','action-edit-expiry')">${v.icon} ${v.label}</button>
                    `).join('')}
                </div>
                <input type="hidden" id="action-edit-loc" value="${item.location}">
            </div>
            <div class="form-group">
                <label>${t('inventory.label_expiry')}</label>
                <input type="date" id="action-edit-expiry" value="${item.expiry_date || ''}" class="form-input">
            </div>
            <div class="form-group">
                <label class="toggle-row">
                    ${t('add.vacuum_label')}
                    <span class="toggle-switch">
                        <input type="checkbox" id="action-edit-vacuum" ${item.vacuum_sealed ? 'checked' : ''} onchange="recalcEditExpiry('action-edit-loc','action-edit-vacuum','action-edit-expiry')">
                        <span class="toggle-slider"></span>
                    </span>
                </label>
            </div>
            <div class="modal-actions" style="margin-top:12px">
                <button type="submit" class="btn btn-large btn-primary flex-1">${t('btn.save')}</button>
                <button type="button" class="btn btn-secondary" onclick="deleteActionInventoryItem(${inventoryId})" style="padding:12px">🗑️</button>
            </div>
        </form>
    `;
    document.getElementById('modal-overlay').style.display = 'flex';
    _initExpiryManualTracking('action-edit-expiry', item);
    setQtyInputUnitLabel('action-edit-qty', item.unit || 'pz');
}

function onActionEditUnitChange() {
    const unit = document.getElementById('action-edit-unit').value;
    setQtyInputUnitLabel('action-edit-qty', unit);
    const confGroup = document.getElementById('action-edit-conf-group');
    if (confGroup) confGroup.style.display = unit === 'conf' ? 'block' : 'none';
}

async function submitActionEditInventory(e, id, productId) {
    e.preventDefault();
    const qty = parseFloat(document.getElementById('action-edit-qty').value);
    const loc = document.getElementById('action-edit-loc').value;
    const expiry = document.getElementById('action-edit-expiry').value || null;
    const unit = document.getElementById('action-edit-unit').value;
    
    const payload = { id, quantity: qty, location: loc, expiry_date: expiry, unit, product_id: productId,
        vacuum_sealed: document.getElementById('action-edit-vacuum')?.checked ? 1 : 0,
        expiry_user_set: _expiryUserSetPayload('action-edit-expiry') };
    
    if (unit === 'conf') {
        payload.package_unit = document.getElementById('action-edit-conf-unit')?.value || '';
        payload.package_size = parseFloat(document.getElementById('action-edit-conf-size')?.value) || 0;
    } else {
        payload.package_unit = '';
        payload.package_size = 0;
    }
    
    await api('inventory_update', {}, 'POST', payload);
    closeModal();
    showToast(t('toast.updated'), 'success');
    showProductAction(); // Refresh the action page
}

async function deleteActionInventoryItem(id) {
    if (confirm(t('confirm.remove_item'))) {
        await api('inventory_delete', {}, 'POST', { id });
        closeModal();
        showToast(t('toast.product_removed'), 'success');
        showProductAction(); // Refresh the action page
    }
}

// === THROW AWAY FORM ===
function showThrowForm() {
    // Open a modal to ask how much to throw away
    api('inventory_list').then(data => {
        const items = (data.inventory || []).filter(i => i.product_id == currentProduct.id);
        if (items.length === 0) {
            showToast(t('error.not_in_inventory'), 'error');
            return;
        }
        
        const totalQty = items.reduce((sum, i) => sum + parseFloat(i.quantity), 0);
        const unit = items[0].unit || 'pz';
        const defQty = items[0].default_quantity || 0;
        const pkgUnit = items[0].package_unit || '';
        const qtyDisplay = formatQuantity(totalQty, unit, defQty, pkgUnit);
        
        let locOptionsHtml = items.map(inv => {
            const locInfo = LOCATIONS[inv.location] || { icon: '📦', label: inv.location };
            return `<div class="inv-status-item"><span>${locInfo.icon} ${locInfo.label}</span><span class="inv-status-qty">${formatQuantity(inv.quantity, inv.unit, inv.default_quantity, inv.package_unit)}</span></div>`;
        }).join('');
        
        document.getElementById('modal-content').innerHTML = `
            <div class="modal-header">
                <h3>${t('use.throw_title')}</h3>
                <button class="modal-close" onclick="closeModal()">✕</button>
            </div>
            <div class="product-preview-small" style="margin-bottom:12px">
                ${currentProduct.image_url ?
                    `<img src="${escapeHtml(currentProduct.image_url)}" alt="" style="width:50px;height:50px;border-radius:10px;object-fit:cover">` :
                    `<span style="font-size:2rem">${CATEGORY_ICONS[mapToLocalCategory(currentProduct.category, currentProduct.name)] || '📦'}</span>`
                }
                <div class="product-preview-info">
                    <h3>${escapeHtml(currentProduct.name)}</h3>
                    <p>Disponibile: <strong>${qtyDisplay}</strong></p>
                </div>
            </div>
            <div class="inventory-status-bar" style="margin-bottom:16px">
                <div class="inv-status-items">${locOptionsHtml}</div>
            </div>
            <div style="display:flex;flex-direction:column;gap:10px">
                <button class="btn btn-large btn-danger full-width" onclick="throwAll()">
                    ${t('use.throw_all', { qty: qtyDisplay })}
                </button>
                <div style="text-align:center;color:var(--text-muted);font-size:0.85rem">${t('use.throw_qty_hint')}</div>
                <div class="form-group">
                    <label>📍 Da dove?</label>
                    <div class="location-selector" id="throw-location-selector">
                        ${items.map((inv, idx) => {
                            const locInfo = LOCATIONS[inv.location] || { icon: '📦', label: inv.location };
                            return `<button type="button" class="loc-btn ${idx === 0 ? 'active' : ''}" onclick="selectThrowLocation(this, '${inv.location}')">${locInfo.icon} ${locInfo.label} (${formatQuantity(inv.quantity, inv.unit, inv.default_quantity, inv.package_unit)})</button>`;
                        }).join('')}
                    </div>
                    <input type="hidden" id="throw-location" value="${items[0].location}">
                </div>
                <div class="form-group">
                    <label>${t('use.throw_qty_label')}</label>
                    <div class="qty-control-with-unit">
                        <div class="qty-control">
                            <button type="button" class="qty-btn" onclick="adjustQty('throw-quantity', -1)">−</button>
                            <input type="number" id="throw-quantity" value="1" min="0.1" step="any" class="qty-input">
                            <button type="button" class="qty-btn" onclick="adjustQty('throw-quantity', 1)">+</button>
                        </div>
                        <span class="qty-unit-badge" id="throw-quantity-unit" aria-live="polite">${escapeHtml(getUnitDisplayLabel(items[0]?.unit || 'pz'))}</span>
                    </div>
                </div>
                <button class="btn btn-large btn-warning full-width" onclick="throwPartial()">
                    ${t('use.throw_partial_btn')}
                </button>
            </div>
        `;
        document.getElementById('modal-overlay').style.display = 'flex';
    });
}

function selectThrowLocation(btn, loc) {
    btn.parentElement.querySelectorAll('.loc-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('throw-location').value = loc;
}

/**
 * Show a destructive-action confirmation modal with a 5-second auto-confirm countdown.
 * The user can tap "Annulla" to cancel or "Conferma" (or wait) to proceed.
 * @param {string}   title     — Modal title
 * @param {string}   msg       — Explanatory text
 * @param {Function} onConfirm — Called when confirmed (by user or countdown)
 * @param {string}   [confirmLabel] — Override confirm button label
 */
function _showDestructiveConfirm(title, msg, onConfirm, confirmLabel) {
    const DURATION = 5000;
    const btnLabel = confirmLabel || t('confirm.proceed') || 'Conferma';
    const cancelLabel = t('confirm.cancel') || 'Annulla';
    let rafHandle = null;
    let timerHandle = null;
    let resolved = false;

    const overlayEl = document.getElementById('modal-overlay');
    const contentEl = document.getElementById('modal-content');
    const confirmBtnId = '_destConfirmBtn_' + Date.now();
    const barId       = '_destConfirmBar_' + Date.now();

    contentEl.innerHTML = `
        <div class="modal-header">
            <h3>${escapeHtml(title)}</h3>
        </div>
        <p style="margin:12px 0 18px;color:var(--text-muted);font-size:0.95rem">${escapeHtml(msg)}</p>
        <div style="height:4px;background:var(--border);border-radius:2px;overflow:hidden;margin-bottom:16px">
            <div id="${barId}" style="height:100%;width:100%;background:var(--danger);transition:none"></div>
        </div>
        <div style="display:flex;gap:10px">
            <button class="btn btn-secondary" style="flex:1" id="_destCancelBtn">${escapeHtml(cancelLabel)}</button>
            <button class="btn btn-danger" style="flex:1" id="${confirmBtnId}">${escapeHtml(btnLabel)}</button>
        </div>
    `;
    overlayEl.style.display = 'flex';

    function cleanup() {
        if (rafHandle)   cancelAnimationFrame(rafHandle);
        if (timerHandle) clearTimeout(timerHandle);
        rafHandle = timerHandle = null;
    }
    function doConfirm() {
        if (resolved) return;
        resolved = true;
        cleanup();
        closeModal();
        onConfirm();
    }
    function doCancel() {
        if (resolved) return;
        resolved = true;
        cleanup();
        closeModal();
    }

    document.getElementById(confirmBtnId).addEventListener('click', doConfirm);
    document.getElementById('_destCancelBtn').addEventListener('click', doCancel);

    // Countdown animation
    const barEl = document.getElementById(barId);
    const start = performance.now();
    function tick() {
        const pct = Math.min(100, (performance.now() - start) / DURATION * 100);
        if (barEl) barEl.style.width = (100 - pct) + '%';
        if (pct < 100) { rafHandle = requestAnimationFrame(tick); }
    }
    rafHandle = requestAnimationFrame(tick);
    timerHandle = setTimeout(doConfirm, DURATION);
}

async function throwAll() {
    const name = currentProduct ? currentProduct.name : '';
    _showDestructiveConfirm(
        t('use.throw_all_confirm_title') || '🗑️ Butta tutto',
        (t('use.throw_all_confirm_msg') || 'Vuoi davvero buttare via tutto il prodotto?') + (name ? `\n"${name}"` : ''),
        async () => {
            try {
                const result = await _inventoryWaste({
                    product_id: currentProduct.id,
                    use_all: true,
                    location: '__all__',
                }, name);
                if (result.success) {
                    showToast(t('toast.thrown_away', { name: currentProduct.name }), 'success');
                    showPage('dashboard');
                } else {
                    showToast(result.error || t('error.generic'), 'error');
                }
            } catch(e) {
                showToast(t('error.connection'), 'error');
            }
        },
        t('use.throw_all_confirm_btn') || '🗑️ Sì, butta'
    );
}

async function throwPartial() {
    const qty = parseFloat(document.getElementById('throw-quantity').value) || 1;
    const loc = document.getElementById('throw-location').value;
    closeModal();
    try {
        const result = await _inventoryWaste({
            product_id: currentProduct.id,
            quantity: qty,
            location: loc,
        }, currentProduct.name);
        if (result.success) {
            showToast(t('toast.thrown_away_partial', { qty, unit: currentProduct.unit || 'pz', name: currentProduct.name }), 'success');
            showPage('dashboard');
        } else {
            showToast(result.error || t('error.generic'), 'error');
        }
    } catch(e) {
        showToast(t('error.connection'), 'error');
    }
}

function toggleActionEdit() {
    const el = document.getElementById('action-edit-info');
    if (!el) return;
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
    if (el.style.display === 'block') {
        setTimeout(() => document.getElementById('edit-action-name')?.focus(), 100);
    }
}

async function saveEditedProductInfo() {
    const name = (document.getElementById('edit-action-name')?.value || '').trim();
    if (!name) {
        showToast(t('product.name_required'), 'error');
        document.getElementById('edit-action-name')?.focus();
        return;
    }
    const brand = (document.getElementById('edit-action-brand')?.value || '').trim();
    const category = document.getElementById('edit-action-category')?.value || '';
    const notes = (document.getElementById('edit-action-notes')?.value || '').trim();
    
    showLoading(true);
    try {
        const result = await api('product_save', {}, 'POST', {
            id: currentProduct.id,
            barcode: currentProduct.barcode || null,
            name: name,
            brand: brand,
            category: category || currentProduct.category || '',
            image_url: currentProduct.image_url || '',
            unit: currentProduct.unit || 'pz',
            default_quantity: currentProduct.default_quantity || 1,
            notes: notes,
        });
        showLoading(false);
        if (result.success) {
            // Update current product in memory
            currentProduct.name = name;
            currentProduct.brand = brand;
            currentProduct.notes = notes;
            if (category) currentProduct.category = category;
            showToast(result.merged ? t('scan.ai_match_merged_existing') : t('toast.product_updated'), result.merged ? 'info' : 'success');
            // Refresh the action page with updated data
            showProductAction();
        } else if (result.error === 'barcode_already_used' && result.existing_id) {
            const full = await api('product_get', { id: result.existing_id }).catch(() => null);
            if (full?.product) {
                currentProduct = full.product;
                showToast(t('scan.ai_match_merged_existing'), 'info');
                showProductAction();
            } else {
                showToast(t('error.save'), 'error');
            }
        } else {
            showToast(result.error || t('error.save'), 'error');
        }
    } catch (err) {
        showLoading(false);
        showToast(t('error.connection'), 'error');
    }
}

// ===== ADD TO INVENTORY =====
function showAddForm() {
    const catIcon = CATEGORY_ICONS[mapToLocalCategory(currentProduct.category, currentProduct.name)] || '📦';
    document.getElementById('add-product-preview').innerHTML = `
        ${currentProduct.image_url ?
            `<img src="${escapeHtml(currentProduct.image_url)}" alt="">` :
            `<span style="font-size:2rem">${catIcon}</span>`
        }
        <div class="product-preview-info">
            <h3>${escapeHtml(currentProduct.name)}</h3>
            <p>${currentProduct.brand ? escapeHtml(currentProduct.brand) : ''}</p>
            ${currentProduct.weight_info ? `<p style="font-size:0.8rem;color:var(--text-light)">${escapeHtml(currentProduct.weight_info)}</p>` : ''}
        </div>
    `;
    
    // Set unit selector
    const unit = currentProduct.unit || 'pz';
    const unitSelect = document.getElementById('add-unit');
    unitSelect.value = unit;
    
    document.getElementById('add-quantity').value = unit === 'conf' ? (currentProduct._confCount || currentProduct.last_qty || 1) : (currentProduct.default_quantity || 1);
    document.getElementById('add-quantity').dataset.manuallySet = 'false';

    if (_spesaScanTarget?.buyQty) {
        const bq = _spesaScanTarget.buyQty;
        if (bq.unit === 'conf') {
            unitSelect.value = 'conf';
            document.getElementById('add-quantity').value = bq.qty;
        } else if (bq.unit === 'pz' || bq.unit === unit) {
            unitSelect.value = bq.unit;
            document.getElementById('add-quantity').value = bq.qty;
        }
    }
    
    // Show/hide conf size row and pre-fill
    const confRow = document.getElementById('add-conf-size-row');
    if (confRow) {
        confRow.style.display = unit === 'conf' ? 'block' : 'none';
        if (unit === 'conf' && currentProduct.package_unit && currentProduct.default_quantity > 0) {
            document.getElementById('add-conf-size').value = currentProduct.default_quantity;
            document.getElementById('add-conf-unit').value = currentProduct.package_unit;
        } else if (unit === 'conf' && ['g', 'ml', 'kg', 'l'].includes(currentProduct.unit) && currentProduct.default_quantity > 0) {
            // Product was defined in weight/volume — that quantity IS the package size
            document.getElementById('add-conf-size').value = currentProduct.default_quantity;
            document.getElementById('add-conf-unit').value = currentProduct.unit;
        } else if (unit === 'conf') {
            document.getElementById('add-conf-size').value = '';
            document.getElementById('add-conf-unit').value = 'g';
        }
    }
    
    // Track manual edits to quantity in add form
    const addQtyInput = document.getElementById('add-quantity');
    addQtyInput.removeEventListener('input', markAddQtyManuallySet);
    addQtyInput.addEventListener('input', markAddQtyManuallySet);
    
    // Show weight info if product has it
    const weightInfoEl = document.getElementById('add-weight-info');
    if (currentProduct.weight_info) {
        weightInfoEl.textContent = `📦 Confezione: ${currentProduct.weight_info}`;
        weightInfoEl.style.display = 'block';
    } else {
        weightInfoEl.style.display = 'none';
    }
    
    // Set qty step based on selected unit
    updateAddQtyStep();
    
    // Auto-detect location
    const autoLoc = guessLocation(currentProduct);
    document.getElementById('add-location').value = autoLoc;
    
    // Highlight correct location button
    document.querySelectorAll('#page-add .loc-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('#page-add .loc-btn').forEach(b => {
        const btnText = b.textContent.toLowerCase();
        if (btnText.includes(autoLoc)) b.classList.add('active');
    });
    
    // Show the purchase-type selector  
    const expirySection = document.getElementById('add-expiry-section');
    const estimatedDays = estimateExpiryDays(currentProduct, autoLoc);
    const estimatedDate = addDays(estimatedDays);
    const estimateLabel = formatEstimatedExpiry(estimatedDays);
    
    let expirySuffix = autoLoc === 'freezer' ? ' (freezer)' : '';
    
    // Reset vacuum sealed toggle
    const vacuumCb = document.getElementById('add-vacuum-sealed');
    if (vacuumCb) {
        vacuumCb.checked = false;
        document.getElementById('add-vacuum-hint').style.display = 'none';
    }
    // Reset historical expiry for this product; will be fetched async
    window._historyExpiryDays = null;
    window._historyExpiryCount = 0;
    // Reset extra batches from previous add
    window._addExtraBatches = [];
    // Store base expiry for vacuum recalculation
    window._addBaseExpiryDays = estimatedDays;
    
    expirySection.innerHTML = `
        <label>${t('add.purchase_type_label')}</label>
        <div class="purchase-type-selector">
            <button type="button" class="purchase-type-btn active" onclick="selectPurchaseType(this, 'new')">
                ${t('add.new_btn')}
            </button>
            <button type="button" class="purchase-type-btn" onclick="selectPurchaseType(this, 'existing')">
                ${t('add.existing_btn')}
            </button>
        </div>
        <div id="expiry-detail" class="expiry-detail">
            <div class="expiry-estimate">
                <span class="expiry-estimate-label">${t('add.estimated_expiry')} <strong>${estimateLabel}${expirySuffix}</strong></span>
                <span class="expiry-estimate-date">${formatDate(estimatedDate)}</span>
            </div>
            <div class="expiry-input-row">
                <input type="date" id="add-expiry" class="form-input" value="${estimatedDate}">
                <button type="button" class="btn btn-accent btn-scan-expiry" onclick="scanExpiryWithAI()" title="${t('add.scan_expiry_title')}">📷</button>
            </div>
            <p class="form-hint">${t('add.hint_modify')}</p>
        </div>
        <div id="multi-batch-section" style="display:${unit === 'conf' ? 'block' : 'none'}">
            <div id="multi-batch-container"></div>
            <button type="button" class="btn btn-outline btn-small full-width" style="margin-top:8px" onclick="addExpiryBatch()">
                📦 + Lotto con scadenza diversa
            </button>
        </div>
    `;
    
    showPage('add');
    updateScaleReadButtons();
    _initExpiryManualTracking('add-expiry');
    // History first (≥3 samples → average of last 3); AI only if history is insufficient
    (async () => {
        let hasHistory = false;
        if (currentProduct?.id) {
            hasHistory = await _fetchExpiryHistoryAndUpdate(currentProduct.id);
        }
        if (_geminiAvailable && currentProduct && !currentProduct._aiHintFetched && !hasHistory) {
            _applyAIProductHint();
        }
    })();
}

function toggleVacuumSealed() {
    const cb = document.getElementById('add-vacuum-sealed');
    if (cb) cb.checked = !cb.checked;
    onVacuumSealedChange();
}

function onVacuumSealedChange() {
    const hint = document.getElementById('add-vacuum-hint');
    if (hint) hint.style.display = document.getElementById('add-vacuum-sealed')?.checked ? 'block' : 'none';
    recalculateAddExpiry();
}

function recalculateAddExpiry() {
    if (_isExpiryManuallySet('add-expiry')) return;
    if (!currentProduct) return;
    const loc = document.getElementById('add-location')?.value || '';
    const isVacuum = document.getElementById('add-vacuum-sealed')?.checked;
    
    const baseDays = window._historyExpiryDays ?? estimateExpiryDays(currentProduct, loc);
    let days = isVacuum ? getVacuumExpiryDays(baseDays) : baseDays;
    
    window._addBaseExpiryDays = baseDays;
    
    const newDate = addDays(days);
    const newLabel = formatEstimatedExpiry(days);
    
    let suffix = '';
    if (window._historyExpiryDays) suffix = t('product.from_history');
    else if (loc === 'freezer' && isVacuum) suffix = ' ' + t('add.suffix_freezer_vacuum');
    else if (loc === 'freezer') suffix = ' (freezer)';
    else if (isVacuum) suffix = ' ' + t('add.suffix_vacuum');
    
    const expiryInput = document.getElementById('add-expiry');
    const estimateEl = document.querySelector('.expiry-estimate-label');
    const dateEl = document.querySelector('.expiry-estimate-date');
    if (expiryInput) expiryInput.value = newDate;
    if (estimateEl) estimateEl.innerHTML = `${t('add.estimated_expiry')} <strong>${newLabel}${suffix}</strong>`;
    if (dateEl) dateEl.textContent = formatDate(newDate);
}

const _EXPIRY_HISTORY_MIN_SAMPLES = 3;

async function _fetchExpiryHistoryAndUpdate(productId) {
    window._historyExpiryDays = null;
    window._historyExpiryCount = 0;
    try {
        const res = await fetch(`api/index.php?action=expiry_history&product_id=${encodeURIComponent(productId)}`, {
            headers: { ...(typeof apiAuthHeaders === 'function' ? apiAuthHeaders() : {}) },
        });
        const data = await res.json();
        const minSamples = data.min_samples || _EXPIRY_HISTORY_MIN_SAMPLES;
        window._historyExpiryCount = data.count || 0;
        if (data.avg_days && data.avg_days > 0 && (data.count || 0) >= minSamples) {
            window._historyExpiryDays = data.avg_days;
            if (_aiProductHintController) {
                _aiProductHintController.abort();
                _aiProductHintController = null;
            }
            document.getElementById('ai-hint-loading')?.remove();
            if (!_isExpiryManuallySet('add-expiry')) {
                const loc = document.getElementById('add-location')?.value || '';
                const isVacuum = document.getElementById('add-vacuum-sealed')?.checked;
                let days = isVacuum ? getVacuumExpiryDays(data.avg_days) : data.avg_days;
                const newDate = addDays(days);
                const newLabel = formatEstimatedExpiry(days);
                const suffix = ` <span class="history-badge" title="${t('add.history_badge_tip').replace('{n}', String(data.count))}">${t('product.history_badge')}</span>`;
                const expiryInput = document.getElementById('add-expiry');
                const estimateEl = document.querySelector('.expiry-estimate-label');
                const dateEl = document.querySelector('.expiry-estimate-date');
                if (expiryInput) expiryInput.value = newDate;
                if (estimateEl) estimateEl.innerHTML = `${t('add.estimated_expiry')} <strong>${newLabel}${suffix}</strong>`;
                if (dateEl) dateEl.textContent = formatDate(newDate);
            }
            window._addBaseExpiryDays = data.avg_days;
            return true;
        }
    } catch (e) {
        // silently fall back to rule-based estimate
    }
    return false;
}

// ===== AI PRODUCT HINT: shelf-life + storage suggestion =====
let _aiProductHintController = null;
async function _applyAIProductHint() {
    if (!currentProduct) return;
    if (window._historyExpiryDays && (window._historyExpiryCount || 0) >= _EXPIRY_HISTORY_MIN_SAMPLES) return;
    // Abort any in-flight request for a previous product
    if (_aiProductHintController) _aiProductHintController.abort();
    _aiProductHintController = new AbortController();

    // Show a subtle loading indicator near the estimate label
    const estimateEl = document.querySelector('.expiry-estimate-label');
    if (estimateEl) {
        const oldHtml = estimateEl.innerHTML;
        estimateEl.dataset.aiOriginal = oldHtml;
        estimateEl.innerHTML += ' <span id="ai-hint-loading" style="font-size:0.75rem;opacity:0.7">🤖…</span>';
    }

    try {
        const data = await api('gemini_product_hint', {}, 'POST', {
            name:     currentProduct.name,
            category: currentProduct.category || '',
            lang:     _currentLang,
        });

        // Remove loading indicator
        document.getElementById('ai-hint-loading')?.remove();

        if (!data.success || !data.location || !data.expiry_days) return;
        // Mark so we don't re-fetch on the same product
        currentProduct._aiHintFetched = true;

        const curLoc  = document.getElementById('add-location')?.value;
        const locChanged = data.location !== curLoc;

        // Update location if AI suggests a different one (and user hasn't manually picked)
        if (locChanged) {
            document.getElementById('add-location').value = data.location;
            // Update active loc-btn
            document.querySelectorAll('#page-add .loc-btn').forEach(b => {
                const onclick = b.getAttribute('onclick') || '';
                const locMatch = onclick.match(/'([^']+)'\s*\)/);
                if (locMatch) b.classList.toggle('active', locMatch[1] === data.location);
            });
        }

        // Update expiry only if we have no historical data (history takes priority)
        if (!window._historyExpiryDays && !_isExpiryManuallySet('add-expiry')) {
            window._addBaseExpiryDays = data.expiry_days;
            const newDate  = addDays(data.expiry_days);
            const newLabel = formatEstimatedExpiry(data.expiry_days);
            const expiryInput = document.getElementById('add-expiry');
            const dateEl      = document.querySelector('.expiry-estimate-date');
            if (expiryInput) expiryInput.value = newDate;
            if (dateEl) dateEl.textContent = formatDate(newDate);
            const aiSuffix = ` <span class="history-badge" style="background:rgba(99,102,241,0.15);color:#6366f1" title="${escapeHtml(data.reason || '')}">🤖 AI</span>`;
            if (estimateEl) estimateEl.innerHTML = `${t('add.estimated_expiry')} <strong>${newLabel}</strong>${aiSuffix}`;
        } else if (estimateEl && estimateEl.dataset.aiOriginal) {
            // Restore original if history already set
            estimateEl.innerHTML = estimateEl.dataset.aiOriginal;
        }

        // Show a toast only if location changed
        if (locChanged) {
            const locLabels = { dispensa: t('location.dispensa') || 'Dispensa', frigo: t('location.frigo') || 'Frigo', freezer: t('location.freezer') || 'Freezer' };
            showToast(t('ai.conservation_hint', { location: locLabels[data.location] || data.location }), 'info', 4000);
        }
    } catch (e) {
        document.getElementById('ai-hint-loading')?.remove();
        if (estimateEl && estimateEl.dataset.aiOriginal) estimateEl.innerHTML = estimateEl.dataset.aiOriginal;
        // silent — AI hint is best-effort
    }
}

function getVacuumExpiryDays(baseDays) {
    // Vacuum sealing extends shelf life significantly
    if (baseDays <= 7) return Math.round(baseDays * 3);       // very fresh: 3x (e.g., 3→9, 7→21)
    if (baseDays <= 14) return Math.round(baseDays * 3);       // fresh cheese/dairy: 3x (10→30)
    if (baseDays <= 30) return Math.round(baseDays * 2.5);     // short: 2.5x (e.g., 21→52)
    if (baseDays <= 90) return Math.round(baseDays * 2.5);     // medium (cheese ~60d): 2.5x (60→150)
    return Math.round(baseDays * 1.5);                         // long-lasting: 1.5x
}

function onAddUnitChange() {
    updateAddQtyStep();
    const unit = document.getElementById('add-unit').value;
    const qtyInput = document.getElementById('add-quantity');
    
    // Show/hide conf size row
    const confRow = document.getElementById('add-conf-size-row');
    if (confRow) {
        const isConf = unit === 'conf';
        confRow.style.display = isConf ? 'block' : 'none';
        // Pre-fill from currentProduct if available
        if (isConf && currentProduct) {
            const sizeInput = document.getElementById('add-conf-size');
            const unitSelect = document.getElementById('add-conf-unit');
            if (currentProduct.package_unit && currentProduct.default_quantity > 1) {
                sizeInput.value = currentProduct.default_quantity;
                unitSelect.value = currentProduct.package_unit;
            } else if (['g', 'ml', 'kg', 'l'].includes(currentProduct.unit) && currentProduct.default_quantity > 0) {
                // Product was defined in weight/volume — that quantity IS the package size
                sizeInput.value = currentProduct.default_quantity;
                unitSelect.value = currentProduct.unit;
            } else {
                sizeInput.value = '';
                unitSelect.value = 'g';
            }
        }
        // Scroll into view so the user sees the new field
        if (isConf) setTimeout(() => confRow.scrollIntoView({ behavior: 'smooth', block: 'nearest' }), 100);
    }

    // Show/hide multi-batch section (only for conf unit)
    const mbSection = document.getElementById('multi-batch-section');
    if (mbSection) mbSection.style.display = unit === 'conf' ? 'block' : 'none';
    
    // If switching units, suggest a sensible quantity
    // BUT only if the user hasn't manually changed the quantity in this form
    if (qtyInput.dataset.manuallySet === 'true') return; // User already edited qty, don't overwrite
    
    const currentQty = parseFloat(qtyInput.value) || 1;
    
    // Convert between related units if logical
    if (unit === 'g' && currentQty <= 10) qtyInput.value = currentProduct.weight_info ? parseFloat(currentProduct.weight_info) || 250 : 250;
    if (unit === 'ml' && currentQty <= 10) qtyInput.value = 500;
    if (unit === 'pz' && currentQty > 100) qtyInput.value = 1;
    if (unit === 'conf' && currentQty > 10) qtyInput.value = 1;

    // Show/hide scale read button based on new unit
    updateScaleReadButtons();
    setQtyInputUnitLabel('add-quantity', unit, true);
}

function updateAddQtyStep() {
    const qtyInput = document.getElementById('add-quantity');
    const unit = document.getElementById('add-unit').value;
    qtyInput.step = 'any';
    if (unit === 'g' || unit === 'ml') {
        qtyInput.min = '1';
    } else {
        qtyInput.min = '1';
    }
    setQtyInputUnitLabel('add-quantity', unit, true);
}

function markAddQtyManuallySet() {
    document.getElementById('add-quantity').dataset.manuallySet = 'true';
}

function adjustAddQty(delta) {
    const qtyInput = document.getElementById('add-quantity');
    qtyInput.dataset.manuallySet = 'true'; // +/- buttons count as manual edit
    const unit = document.getElementById('add-unit').value;
    let val = parseFloat(qtyInput.value) || 0;
    let step;
    if (unit === 'g' || unit === 'ml') {
        step = val < 50 ? 1 : (val < 500 ? 10 : 50);
    } else {
        step = 1;
    }
    val = Math.max(parseFloat(qtyInput.min) || 0.1, val + delta * step);
    // Round nicely
    if (step >= 1) val = Math.round(val);
    else val = Math.round(val * 10) / 10;
    qtyInput.value = val;
}

function selectPurchaseType(btn, type) {
    btn.parentElement.querySelectorAll('.purchase-type-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    
    // Reset extra batches when switching purchase type
    window._addExtraBatches = [];
    const mbContainer = document.getElementById('multi-batch-container');
    if (mbContainer) mbContainer.innerHTML = '';

    const detailDiv = document.getElementById('expiry-detail');
    
    // Save current quantity before switching, so we can preserve it
    const currentQty = document.getElementById('add-quantity').value;
    
    if (type === 'new') {
        // Recalculate fresh expiry based on current location/vacuum
        const loc = document.getElementById('add-location')?.value || '';
        const isVacuum = document.getElementById('add-vacuum-sealed')?.checked;
        const baseDays = window._historyExpiryDays ?? estimateExpiryDays(currentProduct, loc);
        let days = isVacuum ? getVacuumExpiryDays(baseDays) : baseDays;
        const estimatedDate = addDays(days);
        const estimateLabel = formatEstimatedExpiry(days);
        let suffix = '';
        if (window._historyExpiryDays) suffix = ` <span class="history-badge" title="${t('add.history_badge_tip').replace('{n}', window._historyExpiryCount)}">${t('product.history_badge')}</span>`;
        else if (loc === 'freezer' && isVacuum) suffix = ' ' + t('add.suffix_freezer_vacuum');
        else if (loc === 'freezer') suffix = ' ' + t('add.suffix_freezer');
        else if (isVacuum) suffix = ' ' + t('add.suffix_vacuum');
        
        detailDiv.innerHTML = `
            <div class="expiry-estimate">
                <span class="expiry-estimate-label">${t('add.estimated_expiry')} <strong>${estimateLabel}${suffix}</strong></span>
                <span class="expiry-estimate-date">${formatDate(estimatedDate)}</span>
            </div>
            <div class="expiry-input-row">
                <input type="date" id="add-expiry" class="form-input" value="${estimatedDate}">
                <button type="button" class="btn btn-accent btn-scan-expiry" onclick="scanExpiryWithAI()" title="${t('add.scan_expiry_title')}">📷</button>
            </div>
            <p class="form-hint">${t('add.hint_modify')}</p>
        `;
        // Restore quantity - switching purchase type should NOT change it
        document.getElementById('add-quantity').value = currentQty;
        _initExpiryManualTracking('add-expiry');
        // Show multi-batch section only in "new" mode (and only for conf unit)
        const mbSection = document.getElementById('multi-batch-section');
        if (mbSection) mbSection.style.display = (document.getElementById('add-unit')?.value === 'conf') ? 'block' : 'none';
    } else {
        detailDiv.innerHTML = `
            <div class="form-group">
                <label>${t('inventory.label_expiry')}</label>
                <div class="expiry-input-row">
                    <input type="date" id="add-expiry" class="form-input" value="">
                    <button type="button" class="btn btn-accent btn-scan-expiry" onclick="scanExpiryWithAI()" title="${t('add.scan_expiry_title')}">📷</button>
                </div>
                <p class="form-hint">${t('product.expiry_hint')}</p>
            </div>
            <div class="form-group">
                <label>${t('add.remaining_label')}</label>
                <p class="form-hint" style="margin-bottom:6px">${t('add.remaining_hint')}</p>
                <div class="remaining-options">
                    <button type="button" class="remaining-btn" onclick="setRemainingPct(1)">${t('add.remaining_full')}</button>
                    <button type="button" class="remaining-btn" onclick="setRemainingPct(0.75)">🟡 ¾</button>
                    <button type="button" class="remaining-btn" onclick="setRemainingPct(0.5)">${t('add.remaining_half')}</button>
                    <button type="button" class="remaining-btn" onclick="setRemainingPct(0.25)">🔴 ¼</button>
                </div>
            </div>
        `;
        _initExpiryManualTracking('add-expiry');
        // DON'T auto-set remaining percentage - keep the quantity the user already entered
        // Hide multi-batch section in "existing" mode
        const mbSection = document.getElementById('multi-batch-section');
        if (mbSection) mbSection.style.display = 'none';
    }
}

function setRemainingPct(pct) {
    document.querySelectorAll('.remaining-btn').forEach(b => b.classList.remove('active'));
    event.target.classList.add('active');
    const baseQty = currentProduct.default_quantity || 1;
    const unit = currentProduct.unit || 'pz';
    let adjustedQty;
    if (unit === 'pz' || unit === 'conf') {
        adjustedQty = Math.max(1, Math.round(baseQty * pct));
    } else {
        adjustedQty = Math.round(baseQty * pct * 10) / 10;
    }
    document.getElementById('add-quantity').value = adjustedQty;
}

// ===== MULTI-EXPIRY BATCHES (for conf products with different expiry dates) =====
window._addExtraBatches = [];

function addExpiryBatch() {
    const loc = document.getElementById('add-location')?.value || '';
    const baseDays = window._historyExpiryDays ?? estimateExpiryDays(currentProduct, loc);
    const estimatedDate = addDays(baseDays);
    window._addExtraBatches.push({ qty: 1, expiry: estimatedDate });
    _rebuildMultiBatchUI();
}

function removeExpiryBatch(i) {
    window._addExtraBatches.splice(i, 1);
    _rebuildMultiBatchUI();
}

function adjustBatchQty(i, delta) {
    window._addExtraBatches[i].qty = Math.max(1, (window._addExtraBatches[i].qty || 1) + delta);
    _rebuildMultiBatchUI();
}

function _rebuildMultiBatchUI() {
    const container = document.getElementById('multi-batch-container');
    if (!container) return;
    if (window._addExtraBatches.length === 0) {
        container.innerHTML = '';
        return;
    }
    container.innerHTML = window._addExtraBatches.map((b, i) => `
        <div class="multi-batch-row">
            <div class="multi-batch-qty">
                <button type="button" class="qty-btn" onclick="adjustBatchQty(${i}, -1)">−</button>
                <input type="number" class="qty-input" value="${b.qty}" min="1" step="1" style="width:60px"
                    onchange="window._addExtraBatches[${i}].qty = parseInt(this.value)||1">
                <button type="button" class="qty-btn" onclick="adjustBatchQty(${i}, 1)">+</button>
                <span class="multi-batch-unit">conf</span>
            </div>
            <input type="date" class="form-input multi-batch-date" value="${b.expiry}"
                onchange="window._addExtraBatches[${i}].expiry = this.value">
            <button type="button" class="btn-icon-sm" onclick="removeExpiryBatch(${i})" title="Rimuovi">✕</button>
        </div>
    `).join('');
}

function selectLocation(btn, loc) {
    btn.parentElement.querySelectorAll('.loc-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('add-location').value = loc;
    recalculateAddExpiry();
}

const _RECENT_ADD_DEDUP_MS = 30000;
let _recentInventoryAdds = [];

function _pruneRecentInventoryAdds() {
    const cutoff = Date.now() - _RECENT_ADD_DEDUP_MS;
    _recentInventoryAdds = _recentInventoryAdds.filter(r => r.ts >= cutoff);
}

function _findRecentInventoryAdd(productId, location) {
    _pruneRecentInventoryAdds();
    let best = null;
    for (const r of _recentInventoryAdds) {
        if (r.productId === productId && r.location === location && (!best || r.ts > best.ts)) {
            best = r;
        }
    }
    return best;
}

function _recordRecentInventoryAdd(entry) {
    _pruneRecentInventoryAdds();
    _recentInventoryAdds.push(entry);
}

function _formatQtyPlain(qty, unit, defaultQty, packageUnit) {
    return formatQuantity(qty, unit, defaultQty, packageUnit).replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim();
}

function _formatRecentAddWhen(ts) {
    const secs = Math.max(1, Math.round((Date.now() - ts) / 1000));
    if (secs < 5) return t('time.just_now');
    return t('time.seconds_ago').replace('{n}', String(secs));
}

function _confirmRecentDuplicateAdd(recent, productName, addQty, unit, defQty, pkgUnit) {
    const totalStr = _formatQtyPlain(recent.totalQty, recent.unit, recent.defaultQty, recent.packageUnit);
    const addStr = _formatQtyPlain(addQty, unit, defQty, pkgUnit);
    const msg = t('add.duplicate_recent_confirm')
        .replace('{name}', productName)
        .replace('{when}', _formatRecentAddWhen(recent.ts))
        .replace('{total}', totalStr)
        .replace('{qty}', addStr);
    return confirm(msg);
}

async function submitAdd(e) {
    e.preventDefault();

    const selectedUnit = document.getElementById('add-unit').value;
    const productUnit = currentProduct.unit || 'pz';

    if (selectedUnit === 'conf') {
        const confSize = parseFloat(document.getElementById('add-conf-size')?.value);
        if (!confSize || confSize <= 0) {
            showToast(t('product.conf_size_required'), 'error');
            document.getElementById('add-conf-size')?.focus();
            return;
        }
    }

    const location = document.getElementById('add-location').value;
    const addQty = parseFloat(document.getElementById('add-quantity').value) || 1;
    const pkgUnit = selectedUnit === 'conf' ? (document.getElementById('add-conf-unit')?.value || null) : null;
    const pkgSize = selectedUnit === 'conf' ? (parseFloat(document.getElementById('add-conf-size')?.value) || null) : null;
    const unitForAdd = selectedUnit !== productUnit ? selectedUnit : productUnit;

    const recent = _findRecentInventoryAdd(currentProduct.id, location);
    if (recent) {
        const ok = _confirmRecentDuplicateAdd(
            recent,
            currentProduct.name,
            addQty,
            unitForAdd,
            pkgSize || currentProduct.default_quantity || recent.defaultQty,
            pkgUnit || currentProduct.package_unit || recent.packageUnit
        );
        if (!ok) {
            if (_spesaMode) showPage('scan');
            return;
        }
    }

    showLoading(true);

    try {
        const result = await api('inventory_add', {}, 'POST', {
            product_id: currentProduct.id,
            quantity: parseFloat(document.getElementById('add-quantity').value) || 1,
            location: document.getElementById('add-location').value,
            expiry_date: document.getElementById('add-expiry').value || null,
            expiry_user_set: _expiryUserSetPayload('add-expiry'),
            unit: selectedUnit !== productUnit ? selectedUnit : null,
            package_unit: selectedUnit === 'conf' ? (document.getElementById('add-conf-unit')?.value || null) : null,
            package_size: selectedUnit === 'conf' ? (parseFloat(document.getElementById('add-conf-size')?.value) || null) : null,
            vacuum_sealed: document.getElementById('add-vacuum-sealed')?.checked ? 1 : 0,
        });
        
        showLoading(false);
        if (result.success) {
            if (result.canonical_product_id && result.canonical_product_id !== currentProduct.id) {
                currentProduct.id = result.canonical_product_id;
            }
            _recordRecentInventoryAdd({
                productId: currentProduct.id,
                location,
                qty: addQty,
                unit: result.unit || unitForAdd,
                defaultQty: result.default_quantity,
                packageUnit: result.package_unit,
                totalQty: result.total_qty,
                ts: Date.now(),
            });
            // Build quantity info for toast
            let qtyInfo = '';
            if (result.total_qty) {
                const u = result.unit || 'pz';
                const unitLabels = { 'pz': t('units.pz'), 'g': 'g', 'ml': 'ml', 'conf': t('units.conf') };
                const uLabel = unitLabels[u] || u;
                if (u === 'conf' && result.package_unit && result.default_quantity > 0) {
                    const pkgLabel = unitLabels[result.package_unit] || result.package_unit;
                    qtyInfo = ` (totale: ${result.total_qty} ${uLabel} da ${result.default_quantity}${pkgLabel})`;
                } else {
                    qtyInfo = ` (totale: ${result.total_qty} ${uLabel})`;
                }
            }
            const mergedNote = result.catalog_merged ? ` — ${t('scan.ai_match_merged_existing')}` : '';
            showToast(t('add.product_added').replace('{name}', currentProduct.name).replace('{qty}', qtyInfo) + mergedNote, result.catalog_merged ? 'info' : 'success');
            if (await shoppingBoughtAfterAdd(result)) return;
            if (!(await spesaModeAfterAdd(result))) {
                if (result.removed_from_bring) {
                    _applyShoppingListRemovals(result.removed_names || []);
                    setTimeout(() => showToast(t('toast.removed_from_shopping'), 'info'), 1500);
                } else if (shoppingListUUID) {
                    const generic = currentProduct.shopping_name || currentProduct.name;
                    const match = _findSimilarItem(generic, shoppingItems) || _findSimilarItem(currentProduct.name, shoppingItems);
                    api('shopping_remove', {}, 'POST', {
                        name: match?.name || generic,
                        rawName: match?.rawName || '',
                        listUUID: shoppingListUUID,
                    }).then(r => {
                        if (r?.success) {
                            _applyShoppingListRemovals([match?.name || generic, match?.rawName].filter(Boolean));
                            setTimeout(() => showToast(t('toast.removed_from_shopping'), 'info'), 1500);
                        }
                    }).catch(() => {});
                }
                showPage('dashboard');
            } else if (result.removed_from_bring) {
                setTimeout(() => showToast(t('toast.removed_from_shopping'), 'info'), 1500);
            }

            // Submit extra batches (different expiry dates) in the background, silently
            if ((window._addExtraBatches || []).length > 0) {
                const loc = document.getElementById('add-location')?.value || result.location || 'dispensa';
                const selectedUnit = document.getElementById('add-unit').value;
                const productUnit = currentProduct.unit || 'pz';
                const confUnit = document.getElementById('add-conf-unit')?.value || null;
                const confSize = parseFloat(document.getElementById('add-conf-size')?.value) || null;
                for (const batch of window._addExtraBatches) {
                    if (!batch.qty || batch.qty <= 0) continue;
                    api('inventory_add', {}, 'POST', {
                        product_id: currentProduct.id,
                        quantity: batch.qty,
                        location: loc,
                        expiry_date: batch.expiry || null,
                        unit: selectedUnit !== productUnit ? selectedUnit : null,
                        package_unit: selectedUnit === 'conf' ? confUnit : null,
                        package_size: selectedUnit === 'conf' ? confSize : null,
                    }).catch(() => {});
                }
                window._addExtraBatches = [];
            }
        } else {
            showToast(result.error || t('error.generic'), 'error');
        }
    } catch (err) {
        showLoading(false);
        showToast(t('error.connection'), 'error');
    }
}

// ===== USE FROM INVENTORY =====
let _useSubmitting = false; // double-submit guard
function showUseForm() {
    renderUsePreview();
    _useConfMode = null; // reset
    _useSubmitting = false;
    _scaleUserDismissed = false;
    _scaleStabilityVal  = null;
    _scaleLatestWeight  = null; // clear stale weight from previous product
    _cancelScaleAutoConfirm(false);
    document.getElementById('use-quantity').value = 1;
    document.getElementById('use-location').value = 'dispensa';
    document.getElementById('use-unit-switch').style.display = 'none';

    
    // Reset location buttons
    document.querySelectorAll('#page-use .loc-btn').forEach(b => b.classList.remove('active'));
    document.querySelector('#page-use .loc-btn').classList.add('active');
    
    loadUseInventoryInfo();
    showPage('use');
    updateScaleReadButtons();
    syncUseQtyUnitBadge();
}

function renderUsePreview() {
    const catIcon = CATEGORY_ICONS[mapToLocalCategory(currentProduct?.category, currentProduct?.name)] || '📦';
    const imgHtml = currentProduct?.image_url
        ? `<img src="${escapeHtml(currentProduct.image_url)}" alt="">`
        : `<span class="use-hero-icon">${catIcon}</span>`;
    document.getElementById('use-product-preview').innerHTML = `
        ${imgHtml}
        <div class="use-hero-body">
            <div class="use-hero-name">${escapeHtml(currentProduct?.name || '')}</div>
            ${currentProduct?.brand ? `<div class="use-hero-brand">${escapeHtml(currentProduct.brand)}</div>` : ''}
            <div class="use-hero-meta" id="use-hero-meta"></div>
        </div>
    `;
}

/**
 * Fill the hero-card meta row with expiry badge + quantity pill.
 * Called from loadUseInventoryInfo() once inventory data is available.
 */
function _updateUseHeroMeta(items) {
    const metaEl = document.getElementById('use-hero-meta');
    if (!metaEl) return;
    const pills = [];

    // ── Expiry badge ───────────────────────────────────────────────────
    const withExpiry = items.filter(i => i.expiry_date && parseFloat(i.quantity) > 0.01);
    if (withExpiry.length > 0) {
        withExpiry.sort((a, b) => new Date(a.expiry_date + 'T12:00:00') - new Date(b.expiry_date + 'T12:00:00'));
        const soonest = withExpiry[0];
        const expDate = new Date(soonest.expiry_date + 'T12:00:00');
        const today = new Date(); today.setHours(0,0,0,0);
        const days = Math.round((expDate - today) / 86400000);
        const locale = _currentLang === 'de' ? 'de-DE' : _currentLang === 'en' ? 'en-GB' : 'it-IT';
        const dateStr = expDate.toLocaleDateString(locale, { day: '2-digit', month: '2-digit', year: '2-digit' });

        let cls, label;
        if (days < 0)       { cls = 'use-pill-expired'; label = `${t('expiry.badge_expired_ago').replace('{n}', Math.abs(days))} (${dateStr})`; }
        else if (days === 0){ cls = 'use-pill-soon';    label = t('expiry.badge_today'); }
        else if (days <= 3) { cls = 'use-pill-soon';    label = `${t('expiry.badge_expiring_short').replace('{n}', days)} (${dateStr})`; }
        else if (days <= 7) { cls = 'use-pill-warn';    label = `${t('expiry.badge_expiring_short').replace('{n}', days)} (${dateStr})`; }
        else                { cls = 'use-pill-ok';      label = `📅 ${dateStr}`; }
        pills.push(`<span class="use-meta-pill ${cls}">${label}</span>`);
    }

    // ── Quantity + location count pill ────────────────────────────────
    if (items.length > 0) {
        const totalQty = items.reduce((s, i) => s + parseFloat(i.quantity || 0), 0);
        const unit = items[0]?.unit;
        const qtyStr = stripHtml(formatQuantity(totalQty, unit, items[0]?.default_quantity, items[0]?.package_unit));
        const locCount = new Set(items.map(i => i.location)).size;
        const locSuffix = locCount > 1 ? ` · ${locCount} ${t('use.locations_short')}` : '';
        pills.push(`<span class="use-meta-pill use-pill-qty">📦 ${escapeHtml(qtyStr)}${locSuffix}</span>`);
    }

    metaEl.innerHTML = pills.join('');
}

// Conf-mode tracking for USE form
let _useConfMode = null; // null = normal, { packageSize, packageUnit, totalSub, unit } = conf mode active
let _useNormalUnit = 'pz'; // unit when not in conf mode
let _useCurrentItems = []; // cached inventory items for the current product on the use page

/**
 * Mostra un suggerimento giallo sotto le info inventario quando ci sono più
 * confezioni con scadenze diverse (o in posti diversi con scadenze diverse).
 * Es: "⚠️ Usa prima quella in Frigo — scade il 12/04 (tra 3 giorni)!"
 */
function _renderUseExpiryHint(items) {
    const hintEl = document.getElementById('use-expiry-hint');

    // Parse YYYY-MM-DD as local noon to avoid timezone edge cases on some engines.
    const parseLocalExpiryDate = (dateStr) => {
        if (!dateStr) return null;
        const m = String(dateStr).match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (!m) return null;
        return new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]), 12, 0, 0, 0);
    };

    // Ignore tiny residual quantities to avoid misleading hints on near-zero leftovers.
    const withExpiry = items.filter(i => i.expiry_date && parseFloat(i.quantity) > 0.01);

    // Serve almeno 2 item con scadenze diverse (o locazioni diverse con scadenze)
    if (withExpiry.length < 2) { hintEl.style.display = 'none'; return; }

    const dates = withExpiry.map(i => i.expiry_date);
    const uniqueDates = new Set(dates);
    const uniqueLocs  = new Set(withExpiry.map(i => i.location));

    // Mostra hint se scadenze diverse OPPURE stessa scadenza ma luoghi diversi
    if (uniqueDates.size < 2 && uniqueLocs.size < 2) { hintEl.style.display = 'none'; return; }

    // Trova il più vicino alla scadenza
    withExpiry.sort((a, b) => {
        const da = parseLocalExpiryDate(a.expiry_date);
        const db = parseLocalExpiryDate(b.expiry_date);
        return (da ? da.getTime() : Infinity) - (db ? db.getTime() : Infinity);
    });
    const soonest = withExpiry[0];
    const expDate = parseLocalExpiryDate(soonest.expiry_date);
    if (!expDate || Number.isNaN(expDate.getTime())) { hintEl.style.display = 'none'; return; }

    const today = new Date(); today.setHours(0,0,0,0);
    const diffDays = Math.round((expDate - today) / 86400000);

    const locInfo  = LOCATIONS[soonest.location] || { icon: '📦', label: soonest.location };
    const dateStr  = expDate.toLocaleDateString(_currentLang === 'de' ? 'de-DE' : _currentLang === 'en' ? 'en-GB' : 'it-IT', { day: '2-digit', month: '2-digit' });

    let whenStr;
    if (diffDays < 0)       whenStr = t('use.when_expired').replace('{n}', -diffDays);
    else if (diffDays === 0) whenStr = t('use.when_today');
    else if (diffDays === 1) whenStr = t('use.when_tomorrow');
    else                     whenStr = t('use.when_days').replace('{n}', diffDays);

    const locLabel = uniqueLocs.size > 1
        ? ` (${locInfo.icon} ${locInfo.label})`
        : '';

    if (soonest.opened_at) {
        // The soonest "expiry" is a calculated date from when the item was opened — show days-open instead
        const todayBase = new Date(); todayBase.setHours(0, 0, 0, 0);
        const openedDays = Math.round((todayBase - new Date(soonest.opened_at)) / 86400000);
        const whenOpenedStr = openedDays <= 0
            ? t('expiry.opened_today_long')
            : t('expiry.opened_ago_long').replace('{n}', openedDays);
        hintEl.innerHTML = t('use.expiry_warning_opened').replace('{loc}', locLabel).replace('{when}', whenOpenedStr);
    } else {
        hintEl.innerHTML = t('use.expiry_warning').replace('{loc}', locLabel).replace('{date}', `<strong>${dateStr}</strong>`).replace('{when}', whenStr);
    }
    hintEl.style.display = 'block';
}

function _isOpenedInventoryItem(item) {
    if (item.opened_at) return true;
    const q = parseFloat(item.quantity);
    const dq = parseFloat(item.default_quantity) || 0;
    if (item.unit === 'conf' && dq > 0) return q !== Math.floor(q);
    if (dq > 0) return Math.abs(q - Math.round(q / dq) * dq) > dq * 0.02;
    return false;
}

function _useAllTotalQty(items) {
    return items.reduce((s, i) => s + parseFloat(i.quantity || 0), 0);
}

function _locationHasOpenedPackage(items, location) {
    return items.some(i => i.location === location && _isOpenedInventoryItem(i));
}

async function loadUseInventoryInfo() {
    try {
        const data = await api('inventory_list');
        const items = (data.inventory || []).filter(i => i.product_id == currentProduct.id);
        _useCurrentItems = items; // cache for submitUseAll context detection
        const infoEl = document.getElementById('use-inventory-info');
        const unitSwitch = document.getElementById('use-unit-switch');
        
        if (items.length === 0) {
            infoEl.innerHTML = `<p>${t('use.already_exhausted_hint')}</p>
                <button type="button" class="btn btn-large btn-warning full-width mt-2" onclick="_finishExhaustedProduct(${currentProduct.id})">${t('use.mark_exhausted_btn')}</button>`;
            unitSwitch.style.display = 'none';
            _useConfMode = null;
            _useCurrentItems = [];
            document.getElementById('use-expiry-hint').style.display = 'none';
            return;
        }

        // ── Hero card meta: expiry badge + qty pill ──────────────────
        _updateUseHeroMeta(items);

        // ── Suggerisci quale confezione usare per prima ──────────────────
        _renderUseExpiryHint(items);
        // ─────────────────────────────────────────────────────────────────

        // Auto-select the location with an opened package first (use from opened before sealed)
        const openedItem = items.find(_isOpenedInventoryItem);
        const firstLoc = openedItem ? openedItem.location : items[0].location;
        
        // Build location buttons only for locations where the product exists
        const productLocations = [...new Set(items.map(i => i.location))];
        const locSelector = document.getElementById('use-location-selector');
        // Hide the location row when the product is in only one location (nothing to choose)
        const locGroup = document.getElementById('use-location-group');
        if (locGroup) locGroup.style.display = productLocations.length > 1 ? '' : 'none';

        // Prefer the remembered location (if confirmed), else use the opened-package heuristic
        const prefLoc = _getPreferredUseLocation(currentProduct.id);
        const activeLoc = (prefLoc && productLocations.includes(prefLoc)) ? prefLoc : firstLoc;
        document.getElementById('use-location').value = activeLoc;

        // Builder for the full set of location buttons
        const buildLocButtons = (active) => productLocations.map(loc => {
            const locInfo = LOCATIONS[loc] || { icon: '📦', label: loc };
            const locItems = items.filter(i => i.location === loc);
            const locQty = locItems.reduce((s, i) => s + parseFloat(i.quantity), 0);
            const u = locItems[0].unit || 'pz';
            const qtyLabel = formatQuantity(locQty, u, locItems[0].default_quantity, locItems[0].package_unit);
            const openedBadge = _locationHasOpenedPackage(items, loc)
                ? ` <span class="loc-opened-badge">🔓 ${t('use.opened_badge')}</span>`
                : '';
            return `<button type="button" class="loc-btn ${loc === active ? 'active' : ''}${openedBadge ? ' loc-btn-opened' : ''}" onclick="selectUseLocation(this, '${loc}')">${locInfo.icon} ${locInfo.label}${openedBadge}<br><small>${qtyLabel}</small></button>`;
        }).join('');

        if (prefLoc && productLocations.includes(prefLoc) && productLocations.length > 1) {
            // Confirmed preference → show collapsed row + hidden full picker
            const locInfo = LOCATIONS[prefLoc] || { icon: '📦', label: prefLoc };
            locSelector.innerHTML = `
                <div class="pref-loc-info" id="pref-loc-info">
                    <span class="pref-loc-name">${locInfo.icon} ${locInfo.label}</span>
                    <button type="button" class="btn-link pref-loc-change" onclick="_expandUseLocationSelector()">${t('use.change')}</button>
                </div>
                <div id="pref-loc-full" style="display:none">${buildLocButtons(activeLoc)}</div>
            `;
        } else {
            locSelector.innerHTML = buildLocButtons(activeLoc);
        }


        const unit = items[0].unit || 'pz';
        const pkgSize = parseFloat(items[0].default_quantity) || 0;
        const pkgUnit = items[0].package_unit || '';
        const isConf = unit === 'conf' && pkgSize > 0 && pkgUnit;

        if (isConf) {
            // --- CONF MODE: show sub-unit controls ---
            const totalConf = items.reduce((s, i) => s + parseFloat(i.quantity), 0);
            const totalSub = totalConf * pkgSize;
            const unitLabels = { 'ml': 'ml', 'g': 'g', 'pz': 'pz' };
            const subLabel = unitLabels[pkgUnit] || pkgUnit;

            _useConfMode = { packageSize: pkgSize, packageUnit: pkgUnit, totalSub, totalConf, subLabel };

            // Show inventory info with sub-unit total
            infoEl.innerHTML = `<strong>${t('use.available')}</strong> ` + items.map(i => {
                const loc = LOCATIONS[i.location] || { icon: '📦', label: i.location };
                const confQty = parseFloat(i.quantity);
                const subQty = Math.round(confQty * pkgSize);
                const confDisplay = confQty === Math.floor(confQty) ? Math.floor(confQty) : confQty.toFixed(1);
                return `${loc.icon} ${loc.label}: ${confDisplay} conf (${subQty}${subLabel})`;
            }).join(' · ');

            // Show unit switch
            unitSwitch.style.display = 'flex';
            document.getElementById('use-unit-sub').textContent = subLabel;

            // If scale is active, prefer grams for weighing — unless user explicitly chose conf.
            const _scaleActiveNow = getSettings().scale_enabled && getSettings().scale_gateway_url && _scaleConnected;
            const _pref = _useConfMode._userPref;
            switchUseUnit(_pref === 'conf' ? 'conf' : (_scaleActiveNow ? 'sub' : 'conf'));

            // Fraction shortcut buttons for conf mode (½, 1, 2 packages)
            const existingConfFrac = document.getElementById('conf-fraction-btns');
            if (existingConfFrac) existingConfFrac.remove();
            const confFracDiv = document.createElement('div');
            confFracDiv.id = 'conf-fraction-btns';
            confFracDiv.className = 'pz-fraction-btns';
            const maxConf = Math.min(4, Math.ceil(_useConfMode.totalConf));
            const confFracs = [0.25, 0.5, 1];
            if (maxConf >= 2) confFracs.push(2);
            confFracDiv.innerHTML = `<div class="fraction-btn-row">${
                confFracs.filter(f => f <= _useConfMode.totalConf + 0.01).map(f => {
                    const label = f === 0.25 ? '¼' : f === 0.5 ? '½' : f;
                    return `<button type="button" class="frac-btn${f === 1 ? ' active' : ''}" data-frac="${f}" onclick="setConfFraction(${f})">${label} ${t('units.conf') || 'conf'}</button>`;
                }).join('')
            }</div>`;
            document.querySelector('#page-use .use-partial').appendChild(confFracDiv);

            // Trigger a live-box refresh with the latest reading if on scale
            if (_scaleLatestWeight) _scaleAutoFillUse(_scaleLatestWeight);
            syncUseQtyUnitBadge();
        } else {
            // --- NORMAL MODE ---
            _useConfMode = null;
            _useNormalUnit = unit;
            unitSwitch.style.display = 'none';
            // Trigger a live-box refresh with the latest reading if on scale
            if (_scaleLatestWeight) _scaleAutoFillUse(_scaleLatestWeight);
            
            infoEl.innerHTML = `<strong>${t('use.available')}</strong> ` + items.map(i => {
                const loc = LOCATIONS[i.location] || { icon: '📦', label: i.location };
                const qLabel = formatQuantity(parseFloat(i.quantity), i.unit, i.default_quantity, i.package_unit);
                return `${loc.icon} ${loc.label}: ${qLabel}`;
            }).join(' · ');
            
            const qtyInput = document.getElementById('use-quantity');
            const activeLocQty = items.filter(i => i.location === activeLoc).reduce((s, i) => s + parseFloat(i.quantity || 0), 0);
            qtyInput.step = 'any';
            qtyInput.min = '1';
            if (unit === 'g' || unit === 'ml') {
                qtyInput.value = activeLocQty > 0 ? Math.max(1, Math.round(activeLocQty / 2)) : getSubUnitStep(unit);
                document.getElementById('use-partial-hint').textContent = t('use.partial_hint');
                _appendUseWeightFractionButtons(activeLocQty, unit);
            } else {
                qtyInput.value = 1;
                document.getElementById('use-partial-hint').textContent = t('use.partial_hint');
            }

            // Fraction buttons for pz unit
            const existingFrac = document.getElementById('pz-fraction-btns');
            if (existingFrac) existingFrac.remove();
            const existingWf = document.getElementById('weight-fraction-btns');
            if (existingWf && unit !== 'g' && unit !== 'ml') existingWf.remove();
            if (unit === 'pz') {
                const fracDiv = document.createElement('div');
                fracDiv.id = 'pz-fraction-btns';
                fracDiv.className = 'pz-fraction-btns';
                fracDiv.innerHTML = `
                    <p class="form-hint">${t('use.partial_piece_hint')}</p>
                    <div class="fraction-btn-row">
                        <button type="button" class="frac-btn" data-frac="0.25" onclick="setPzFraction(0.25)">¼ ${t('use.piece')}</button>
                        <button type="button" class="frac-btn" data-frac="0.5" onclick="setPzFraction(0.5)">½ ${t('use.piece')}</button>
                        <button type="button" class="frac-btn" data-frac="0.75" onclick="setPzFraction(0.75)">¾ ${t('use.piece')}</button>
                        <button type="button" class="frac-btn active" data-frac="1" onclick="setPzFraction(1)">${t('use.one_whole')}</button>
                    </div>`;
                document.querySelector('#page-use .use-partial').appendChild(fracDiv);
            }
            syncUseQtyUnitBadge();
        }
    } catch(e) {
        console.error(e);
    }
}

function switchUseUnit(mode) {
    const subBtn = document.getElementById('use-unit-sub');
    const confBtn = document.getElementById('use-unit-conf');
    const qtyInput = document.getElementById('use-quantity');
    const hint = document.getElementById('use-partial-hint');

    if (_useConfMode) _useConfMode._userPref = mode;

    // Show/hide fraction buttons depending on mode
    const confFracBtns = document.getElementById('conf-fraction-btns');
    const pzFracBtns   = document.getElementById('pz-fraction-btns');

    if (mode === 'sub') {
        subBtn.classList.add('active');
        confBtn.classList.remove('active');
        _useConfMode._activeUnit = 'sub';
        const step = getSubUnitStep(_useConfMode.packageUnit);
        qtyInput.value = step;
        qtyInput.step = 'any';
        qtyInput.min = 1;
        hint.textContent = t('recipes.quantity_in_total', { unit: _useConfMode.subLabel, total: `${Math.round(_useConfMode.totalSub)}${_useConfMode.subLabel}` });
        if (confFracBtns) confFracBtns.style.display = 'none';
    } else {
        confBtn.classList.add('active');
        subBtn.classList.remove('active');
        _useConfMode._activeUnit = 'conf';
        qtyInput.value = Math.min(1, _useConfMode.totalConf); // start at 1 or max if < 1
        qtyInput.step = 'any';
        qtyInput.min = 0.25;
        hint.textContent = t('recipes.packs_of_have', { size: `${_useConfMode.packageSize}${_useConfMode.subLabel}`, count: _useConfMode.totalConf.toFixed(1) });
        if (confFracBtns) confFracBtns.style.display = '';
    }
    syncUseQtyUnitBadge();
}

function setConfFraction(f) {
    const input = document.getElementById('use-quantity');
    if (!input) return;
    input.value = Math.min(f, _useConfMode?.totalConf ?? f);
    document.querySelectorAll('#conf-fraction-btns .frac-btn').forEach(b =>
        b.classList.toggle('active', parseFloat(b.dataset.frac) === f)
    );
}

function getSubUnitStep(pkgUnit) {
    switch (pkgUnit) {
        case 'ml': return 50;
        case 'g': return 10;
        default: return 1;
    }
}

function adjustUseQty(direction) {
    _scaleUserDismissed = true;
    _cancelScaleTimersOnly();
    const input = document.getElementById('use-quantity');
    let val = parseFloat(input.value) || 0;
    let step;
    if (_useConfMode && _useConfMode._activeUnit === 'sub') {
        step = getSubUnitStep(_useConfMode.packageUnit);
    } else if (_useConfMode && _useConfMode._activeUnit === 'conf') {
        step = 0.5;
    } else {
        // Unit-aware step for normal mode
        const u = _useNormalUnit || 'pz';
        if (u === 'g' || u === 'ml') {
            step = val < 50 ? 1 : (val < 500 ? 10 : 50);
        } else {
            step = 0.5; // pz: allow half-piece steps
        }
    }
    val = Math.max(step, val + direction * step);
    val = Math.round(val * 1000) / 1000;

    // Cap at max available at selected location (in current unit)
    const selectedLoc = document.getElementById('use-location')?.value;
    if (selectedLoc && _useCurrentItems.length > 0) {
        const locItems = _useCurrentItems.filter(i => i.location === selectedLoc);
        const maxQtyAtLoc = locItems.reduce((s, i) => s + parseFloat(i.quantity || 0), 0);
        if (maxQtyAtLoc > 0) {
            // Convert to sub-unit for comparison if needed
            const maxInCurrentUnit = (_useConfMode && _useConfMode._activeUnit === 'sub')
                ? maxQtyAtLoc * _useConfMode.packageSize
                : maxQtyAtLoc;
            val = Math.min(val, Math.round(maxInCurrentUnit * 1000) / 1000);
        }
    }

    input.value = val;
    // Sync fraction button highlight if visible
    const newVal = parseFloat(input.value);
    document.querySelectorAll('#pz-fraction-btns .frac-btn').forEach(b => {
        b.classList.toggle('active', parseFloat(b.dataset.frac) === newVal);
    });
}

function _useQtyAtSelectedLocation() {
    const selectedLoc = document.getElementById('use-location')?.value;
    if (!selectedLoc || !_useCurrentItems.length) return 0;
    return _useCurrentItems
        .filter(i => i.location === selectedLoc)
        .reduce((s, i) => s + parseFloat(i.quantity || 0), 0);
}

function setUseWeightFraction(frac) {
    const input = document.getElementById('use-quantity');
    if (!input) return;
    const stock = _useQtyAtSelectedLocation();
    if (stock <= 0) return;
    const val = frac >= 1 ? stock : Math.max(1, Math.round(stock * frac));
    input.value = Math.min(Math.round(val * 1000) / 1000, stock);
    _scaleUserDismissed = true;
    _cancelScaleTimersOnly();
    document.querySelectorAll('#weight-fraction-btns .frac-btn, #pz-fraction-btns .frac-btn').forEach(b =>
        b.classList.toggle('active', parseFloat(b.dataset.frac) === frac)
    );
}

function _appendUseWeightFractionButtons(stock, unit) {
    const existingWf = document.getElementById('weight-fraction-btns');
    if (existingWf) existingWf.remove();
    if (stock <= 0 || (unit !== 'g' && unit !== 'ml')) return;
    const fracDiv = document.createElement('div');
    fracDiv.id = 'weight-fraction-btns';
    fracDiv.className = 'pz-fraction-btns';
    const uLabel = unit;
    fracDiv.innerHTML = `<p class="form-hint">${t('use.partial_piece_hint')}</p>
        <div class="fraction-btn-row">${
        [0.25, 0.5, 0.75, 1].map(f => {
            const label = f === 0.25 ? '¼' : f === 0.5 ? '½' : f === 0.75 ? '¾' : t('use.one_whole');
            const amt = f >= 1 ? Math.round(stock) : Math.max(1, Math.round(stock * f));
            return `<button type="button" class="frac-btn${f === 0.5 ? ' active' : ''}" data-frac="${f}" onclick="setUseWeightFraction(${f})">${label} (${amt}${uLabel})</button>`;
        }).join('')
    }</div>`;
    document.querySelector('#page-use .use-partial').appendChild(fracDiv);
}

function selectUseLocation(btn, loc) {
    btn.parentElement.querySelectorAll('.loc-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('use-location').value = loc;
    const unit = _useNormalUnit || _useCurrentItems[0]?.unit || 'pz';
    if (unit === 'g' || unit === 'ml') {
        const stock = _useQtyAtSelectedLocation();
        _appendUseWeightFractionButtons(stock, unit);
        const input = document.getElementById('use-quantity');
        if (input && stock > 0) input.value = Math.max(1, Math.round(stock / 2));
    }
}

// ── PREFERRED USE LOCATION ───────────────────────────────────────────────
// After 3+ consistent choices from the same location for a product,
// auto-selects it and hides the location picker (user can still tap "cambia").
const _PREF_LOC_NEEDED = 1; // choices needed to confirm a preference

// ── PREFERRED MOVE-AFTER-USE LOCATION ────────────────────────────────────
// Tracks where the user puts the remainder after using a product.
// After _PREF_MOVE_NEEDED consistent choices, the modal is skipped entirely.
const _PREF_MOVE_NEEDED = 1;
let _pendingMoveCtx = null; // { productId, fromLoc, openedId } — set before showing modal

function _getMoveLocHistory(productId, fromLoc) {
    const all = _prefMoveLocCache || {};
    return all[`${productId}|${fromLoc}`] || [];
}

function _recordMoveLocChoice(productId, fromLoc, toLoc) {
    const all = Object.assign({}, _prefMoveLocCache || {});
    const key = `${productId}|${fromLoc}`;
    const hist = (all[key] || []).slice();
    hist.push(toLoc);
    if (hist.length > 8) hist.splice(0, hist.length - 8);
    all[key] = hist;
    _prefMoveLocCache = all;
    _saveToServer('pref_move_loc', all);
}

function _getPreferredMoveLoc(productId, fromLoc) {
    const hist = _getMoveLocHistory(productId, fromLoc);
    if (hist.length < _PREF_MOVE_NEEDED) return null;
    const recent = hist.slice(-5);
    const counts = {};
    for (const loc of recent) counts[loc] = (counts[loc] || 0) + 1;
    const [topLoc, topCount] = Object.entries(counts).sort((a, b) => b[1] - a[1])[0];
    return topCount >= _PREF_MOVE_NEEDED ? topLoc : null;
}

function _getPrefLocHistory(productId) {
    const all = _prefUseLocCache || {};
    return all[String(productId)] || [];
}

function _recordUseLocationChoice(productId, loc) {
    const all = Object.assign({}, _prefUseLocCache || {});
    const key = String(productId);
    const hist = (all[key] || []).slice();
    hist.push(loc);
    if (hist.length > 8) hist.splice(0, hist.length - 8);
    all[key] = hist;
    _prefUseLocCache = all;
    _saveToServer('pref_use_loc', all);
}

function _getPreferredUseLocation(productId) {
    const hist = _getPrefLocHistory(productId);
    if (hist.length < _PREF_LOC_NEEDED) return null;
    const recent = hist.slice(-5); // look at last 5
    const counts = {};
    for (const loc of recent) counts[loc] = (counts[loc] || 0) + 1;
    const [topLoc, topCount] = Object.entries(counts).sort((a, b) => b[1] - a[1])[0];
    return topCount >= _PREF_LOC_NEEDED ? topLoc : null;
}

function _expandUseLocationSelector() {
    document.getElementById('pref-loc-info')?.style.setProperty('display', 'none');
    document.getElementById('pref-loc-full')?.style.removeProperty('display');
}
// ────────────────────────────────────────────────────────────────────────

function setPzFraction(frac) {
    document.getElementById('use-quantity').value = frac;
    document.querySelectorAll('#pz-fraction-btns .frac-btn').forEach(b => {
        b.classList.toggle('active', parseFloat(b.dataset.frac) === frac);
    });
}

// ===== LOW STOCK → BRING! PROMPT =====
function isLowStock(totalRemaining, unit, defaultQty) {
    if (totalRemaining <= 0) return true; // fully depleted → definitely needs restocking
    if (unit === 'pz') return totalRemaining <= 1; // only 1 piece left
    if (unit === 'conf') return totalRemaining < 0.25; // warn when less than 25% of a package remains
    // Weight/volume: use percentage of default_qty or fixed threshold
    if (defaultQty > 0) return totalRemaining <= defaultQty * 0.25;
    // Fallback fixed thresholds
    if (unit === 'g' || unit === 'ml') return totalRemaining <= 100;
    return false;
}

/**
 * Return the significant tokens of a product name for similarity matching.
 * Strips stopwords and short tokens.
 */
function _nameTokens(name) {
    const stop = new Set(['di','del','della','dei','degli','delle','da','in','con','per','su','a','e','il','lo','la','i','gli','le','un','uno','una','al','alle','agli','allo']);
    return (name || '').toLowerCase()
        .replace(/[^a-z\u00c0-\u024f\s]/gi, ' ')
        .split(/\s+/)
        .filter(t => t.length > 2 && !stop.has(t));
}

/**
 * Check whether `name` matches any item in `list` (array of {name}).
 * Returns the matching item or null.
 * A match = at least one significant token in common.
 * NOTE: intentionally loose — use _matchBringToSmart for display/urgency matching.
 */
function _findSimilarItem(name, list) {
    const tokens = _nameTokens(name);
    if (tokens.length === 0) return null;
    return (list || []).find(item => {
        const iTokens = _nameTokens(item.name || '');
        return tokens.some(t => iTokens.includes(t));
    }) || null;
}

/**
 * Strict matching: find the smart item that corresponds to a Bring item by name.
 * Rules (in order):
 *  1. Exact case-insensitive match.
 *  2. First significant token of both names must be identical
 *     ("Latte" → "Latte Parzialmente Scremato" ✓; "Frutta" ≠ "Muesli Frutta Secca" ✗).
 *  3. For multi-token Bring names: all Bring tokens appear in the smart item tokens.
 * This avoids false positives when a generic word ("frutta", "noci") appears as a
 * secondary word inside an unrelated long product name.
 */
function _matchBringToSmart(bringName, smartItems) {
    const bLower = bringName.toLowerCase();
    const exact = smartItems.find(sd =>
        sd.name.toLowerCase() === bLower ||
        (sd.shopping_name || '').toLowerCase() === bLower
    );
    if (exact) return exact;
    const bTokens = _nameTokens(bringName);
    if (bTokens.length === 0) return null;
    const bFirst = bTokens[0];
    const firstMatch = smartItems.find(sd => {
        const groupName = (sd.shopping_name || sd.name).toLowerCase();
        if (groupName === bLower) return true;
        const sdTokens = _nameTokens(sd.shopping_name || sd.name);
        return sdTokens.length > 0 && sdTokens[0] === bFirst;
    });
    if (firstMatch) return firstMatch;
    // Rule 3: multi-token full subset
    if (bTokens.length >= 2) {
        const allMatch = smartItems.find(sd => {
            const sdTokens = _nameTokens(sd.name);
            return bTokens.every(t => sdTokens.includes(t));
        });
        if (allMatch) return allMatch;
    }
    return null;
}

/**
 * Show a small auto-dismissing bottom bar asking the user if the opened product
 * was put under vacuum seal. Auto-confirms after DURATION ms with the default value
 * (if it was already vacuum sealed → default yes, otherwise → default no).
 * @param {number} openedId  - inventory row ID of the opened item
 * @param {number|boolean} wasVacuumSealed - previous vacuum_sealed state (0/1)
 */
function _showVacuumPrompt(openedId, wasVacuumSealed) {
    const DURATION = 8000;
    const defaultYes = !!wasVacuumSealed;

    const old = document.getElementById('_vacuum-prompt');
    if (old) old.remove();

    const bar = document.createElement('div');
    bar.id = '_vacuum-prompt';
    bar.style.cssText = [
        'position:fixed', 'bottom:80px', 'left:50%', 'transform:translateX(-50%)',
        'z-index:9999', 'background:#1e293b', 'color:#fff', 'border-radius:14px',
        'padding:12px 16px', 'display:flex', 'align-items:center', 'gap:10px',
        'box-shadow:0 4px 24px rgba(0,0,0,0.5)', 'max-width:360px',
        'width:calc(100% - 32px)', 'box-sizing:border-box', 'overflow:hidden'
    ].join(';');
    bar.innerHTML = `
        <span style="flex:1;font-size:0.9rem;line-height:1.3">${t('add.vacuum_question')}</span>
        <button id="_vac-yes" style="background:#22c55e;color:#fff;border:none;border-radius:8px;padding:7px 14px;font-weight:700;cursor:pointer;white-space:nowrap">${t('btn.yes_short')}</button>
        <button id="_vac-no" style="background:#475569;color:#fff;border:none;border-radius:8px;padding:7px 14px;font-weight:700;cursor:pointer;white-space:nowrap">${t('btn.no_short')}</button>
        <div id="_vac-bar" style="position:absolute;bottom:0;left:0;height:3px;background:#60a5fa;border-radius:0;width:100%"></div>
    `;
    document.body.appendChild(bar);

    let dismissed = false;
    let rafH = null;
    let timerH = null;

    function dismiss(vacuum) {
        if (dismissed) return;
        dismissed = true;
        if (timerH) clearTimeout(timerH);
        if (rafH) cancelAnimationFrame(rafH);
        bar.remove();
        api('inventory_update', {}, 'POST', { id: openedId, vacuum_sealed: vacuum ? 1 : 0 })
            .then(() => { if (vacuum) showToast(t('add.vacuum_saved'), 'success'); })
            .catch(() => {});
    }

    bar.querySelector('#_vac-yes').addEventListener('click', () => dismiss(true));
    bar.querySelector('#_vac-no').addEventListener('click', () => dismiss(false));

    const barEl = bar.querySelector('#_vac-bar');
    const start = performance.now();
    function tick() {
        if (dismissed) return;
        const pct = Math.min(100, (performance.now() - start) / DURATION * 100);
        if (barEl) barEl.style.width = (100 - pct) + '%';
        if (pct < 100) rafH = requestAnimationFrame(tick);
    }
    rafH = requestAnimationFrame(tick);
    timerH = setTimeout(() => dismiss(defaultYes), DURATION);
}

function showLowStockBringPrompt(result, afterCallback) {
    const name = result.product_name || currentProduct?.name || '';
    // Generic shopping name (e.g. "Affettato" for "Mortadella IGP"). Falls back to
    // the specific name when shopping_name is not set (older API call), so behaviour
    // is unchanged for legacy callers.
    const shoppingName = result.product_shopping_name || name;
    const unit = result.product_unit || currentProduct?.unit || 'pz';
    const defaultQty = result.product_default_qty || parseFloat(currentProduct?.default_quantity) || 0;
    const totalRemaining = result.total_remaining;
    // If the backend provided a family-wide total (all products sharing the same
    // shopping_name and unit, e.g. "Uova Sfoglia Gialla" + "Uova biologiche"),
    // use that for the low-stock check so that a second scanned package of eggs
    // prevents a false "running out" warning.
    const familyTotal = (result.total_family_remaining !== undefined)
        ? result.total_family_remaining
        : totalRemaining;
    
    // ── Fully depleted: no need to ask — backend already added to Bring! ──
    // Skip the modal entirely and proceed to the next step (e.g. move modal).
    if (totalRemaining <= 0) {
        // Backend auto-adds to Bring! when fully depleted. If it failed (Bring not
        // configured, or product already on list), silently attempt it from JS.
        if (!result.added_to_bring && shoppingName) {
            // Fire-and-forget — don't block the callback
            // Use generic shopping name; specific name + 🛒 marker in spec so cron cleanup can auto-remove.
            const spec = (shoppingName !== name ? name + (result.product_brand ? ` · ${result.product_brand}` : '') : name) + ' · 🛒 Esaurito';
            (async () => {
                try {
                    const payload = { items: [{ name: shoppingName, specification: spec }] };
                    if (shoppingListUUID) payload.listUUID = shoppingListUUID;
                    const data = await api('shopping_add', {}, 'POST', payload);
                    if (data.success && data.added > 0) {
                        showToast(t('toast.finished_to_bring'), 'info');
                    }
                } catch(_e) { /* silent */ }
            })();
        }
        if (afterCallback) afterCallback();
        return;
    }
    
    if (!isLowStock(familyTotal, unit, defaultQty)) {
        if (afterCallback) afterCallback();
        return;
    }
    
    // Format remaining for display
    let remainLabel = '';
    if (unit === 'conf' && result.product_package_unit) {
        const subTotal = Math.round(totalRemaining * defaultQty);
        remainLabel = `${subTotal}${result.product_package_unit}`;
    } else {
        const unitLabels = { pz: 'pz', g: 'g', ml: 'ml', conf: 'conf' };
        remainLabel = `${Number.isInteger(totalRemaining) ? totalRemaining : totalRemaining.toFixed(1)} ${unitLabels[unit] || unit}`;
    }

    // --- Deduplication check ---
    // 1. Already on Bring! list (shoppingItems)?
    const alreadyOnBring = _findSimilarItem(shoppingName, shoppingItems) || _findSimilarItem(name, shoppingItems);
    if (alreadyOnBring) {
        // Already present (same or similar item). Just inform and continue.
        showToast(t('shopping.already_in_list', { name: escapeHtml(alreadyOnBring.name) }), 'info');
        if (afterCallback) afterCallback();
        return;
    }

    // 2. In smart shopping predictions?
    const smartMatch = _findSimilarItem(shoppingName, smartShoppingItems) || _findSimilarItem(name, smartShoppingItems);
    const smartUrgencyLabel = {
        critical: t('shopping.urgency_critical'), high: t('shopping.urgency_high'),
        medium: t('shopping.urgency_medium'), low: t('shopping.urgency_low')
    };
    let smartNote = '';
    if (smartMatch) {
        const lbl = smartUrgencyLabel[smartMatch.urgency] || '';
        const _smartMsg = t('shopping.smart_already_predicted').replace('{name}', escapeHtml(smartMatch.name)).replace('{urgency}', lbl ? ` (${lbl})` : '');
        smartNote = `<div style="margin-bottom:12px;padding:8px 10px;background:rgba(249,115,22,0.1);border-radius:8px;border-left:3px solid #f97316;font-size:0.85rem">
            ${_smartMsg}
        </div>`;
    }

    // _lowStockName = generic name that goes into Bring! (e.g. "Affettato")
    // _lowStockSpec = specific product name used as specification (e.g. "Mortadella IGP")
    window._lowStockAfterCallback = afterCallback;
    window._lowStockName = shoppingName;
    window._lowStockSpec = shoppingName !== name
        ? name + (result.product_brand ? ` · ${result.product_brand}` : '')
        : name;
    
    document.getElementById('modal-content').innerHTML = `
        <div class="modal-header">
            <h3>⚠️ Sta per finire!</h3>
            <button class="modal-close" onclick="closeLowStockPrompt()">✕</button>
        </div>
        <div style="padding:0 16px 16px">
            <p style="margin-bottom:12px">${t('lowstock.message').replace('{name}', `<strong>${escapeHtml(name)}</strong>`).replace('{qty}', `<strong>${remainLabel}</strong>`)}</p>
            ${smartNote}
            <p style="margin-bottom:16px">${t('lowstock.question')}</p>
            <button type="button" class="btn btn-large btn-success full-width" onclick="addLowStockToBring()">
                ${t('lowstock.yes')}
            </button>
            <button type="button" class="btn btn-secondary full-width" style="margin-top:8px" onclick="closeLowStockPrompt()">
                ${t('lowstock.no')}
            </button>
        </div>
    `;
    document.getElementById('modal-overlay').style.display = 'flex';
}

async function addLowStockToBring() {
    closeModal();
    try {
        // Use the generic shopping name (e.g. "Affettato") set by showLowStockBringPrompt.
        // _lowStockSpec holds the specific product name (e.g. "Mortadella IGP · Marca").
        const bringName = window._lowStockName || '';
        const spec = window._lowStockSpec || '';
        window._lowStockName = null;
        window._lowStockSpec = null;
        const payload = { items: [{ name: bringName, specification: spec }] };
        if (shoppingListUUID) payload.listUUID = shoppingListUUID;
        const data = await api('shopping_add', {}, 'POST', payload);
        if (data.success && data.added > 0) {
            // Pin as user-added so cleanup never auto-removes it
            const pinned = Object.assign({}, _pinnedBringCache || {});
            pinned[bringName.toLowerCase()] = Date.now();
            _pinnedBringCache = pinned;
            _saveToServer('pinned_bring', pinned);
            showToast(t('shopping.added_to_bring').replace('{n}', data.added), 'success');
        } else if (data.success && data.skipped > 0) {
            showToast(t('shopping.already_in_list_short'), 'info');
        }
    } catch (e) {
        showToast(t('error.bring_add'), 'error');
    }
    const cb = window._lowStockAfterCallback;
    window._lowStockAfterCallback = null;
    if (cb) cb();
}

function closeLowStockPrompt() {
    closeModal();
    const cb = window._lowStockAfterCallback;
    window._lowStockAfterCallback = null;
    if (cb) cb();
}

let _moveModalTimer = null;
let _moveModalRAF = null;
let _moveModalTouchHandler = null;

function clearMoveModalTimer() {
    if (_moveModalTimer) { clearTimeout(_moveModalTimer); _moveModalTimer = null; }
    if (_moveModalRAF) { cancelAnimationFrame(_moveModalRAF); _moveModalRAF = null; }
    if (_moveModalTouchHandler) {
        document.getElementById('modal-content')?.removeEventListener('pointerdown', _moveModalTouchHandler, true);
        _moveModalTouchHandler = null;
    }
}

function startMoveModalCountdown(btnId, onExpire) {
    clearMoveModalTimer();
    // Any touch inside the modal cancels the auto-close countdown
    _moveModalTouchHandler = () => clearMoveModalTimer();
    document.getElementById('modal-content')?.addEventListener('pointerdown', _moveModalTouchHandler, { capture: true, once: true });
    const duration = 15000;
    const start = performance.now();
    const btn = document.getElementById(btnId);
    if (!btn) return;
    function tick() {
        const elapsed = performance.now() - start;
        const pct = Math.max(0, 100 - (elapsed / duration) * 100);
        btn.style.background = `linear-gradient(to right, rgba(45,80,22,0.2) ${pct}%, transparent ${pct}%)`;
        if (elapsed < duration) {
            _moveModalRAF = requestAnimationFrame(tick);
        }
    }
    _moveModalRAF = requestAnimationFrame(tick);
    _moveModalTimer = setTimeout(() => {
        clearMoveModalTimer();
        onExpire();
    }, duration);
}

function showMoveAfterUseModal(product, fromLoc, remaining, openedId, openedVacuumSealed, unit) {
    // Store context so _saveVacuumAndStay can record the choice
    _pendingMoveCtx = { productId: product.id, fromLoc, openedId };

    // If a preference is established, skip the modal entirely and auto-apply
    const prefMoveLoc = _getPreferredMoveLoc(product.id, fromLoc);
    if (prefMoveLoc) {
        if (prefMoveLoc === fromLoc) {
            // Preference: stay in place — silent, no modal
            _saveVacuumAndStay(openedId || 0);
        } else {
            // Preference: move to another location — apply silently
            confirmMoveAfterUse(product.id, fromLoc, prefMoveLoc, openedId || 0, !!(openedVacuumSealed ?? product.vacuum_sealed));
        }
        return;
    }

    const otherLocs = Object.entries(LOCATIONS).filter(([k]) => k !== fromLoc);
    const locButtons = otherLocs.map(([k, v]) =>
        `<button type="button" class="loc-btn" onclick="clearMoveModalTimer();confirmMoveAfterUse(${product.id}, '${fromLoc}', '${k}', ${openedId || 0})">${v.icon} ${v.label}</button>`
    ).join('');
    // Show vacuum checkbox for any container-type unit or if the item was previously vacuum sealed.
    // Pre-checked when it was already sealed (semi-automatic: if you sealed it last time, you likely will again).
    const wasVacuum = !!(openedVacuumSealed ?? product.vacuum_sealed);
    // Always offer vacuum sealing: any leftover food can be vacuum sealed regardless of unit type.
    const vacuumRow = `
        <label style="display:flex;align-items:center;gap:8px;margin-top:12px;cursor:pointer">
            <input type="checkbox" id="move-vacuum-check" ${wasVacuum ? 'checked' : ''}>
            <span>${wasVacuum ? t('move.vacuum_restore') : t('move.vacuum_seal_rest')}</span>
        </label>`;
    document.getElementById('modal-content').innerHTML = `
        <div class="modal-header">
            <h3>${t('move.title')}</h3>
            <button class="modal-close" onclick="clearMoveModalTimer();_saveVacuumAndStay(${openedId || 0})">✕</button>
        </div>
        <div style="padding:0 16px 16px">
            <p style="margin-bottom:12px">${t('move.question').replace('{thing}', openedId ? t('move.thing_opened') : t('move.thing_rest')).replace('{name}', `<strong>${escapeHtml(product.name)}</strong>`)}</p>
            <div class="location-selector">${locButtons}</div>
            ${vacuumRow}
            <button type="button" id="btn-move-stay" class="btn btn-secondary full-width move-countdown-btn" style="margin-top:12px" onclick="clearMoveModalTimer();_saveVacuumAndStay(${openedId || 0});">${t('move.stay_btn').replace('{location}', LOCATIONS[fromLoc]?.label || fromLoc)}</button>
        </div>
    `;
    document.getElementById('modal-overlay').style.display = 'flex';
    startMoveModalCountdown('btn-move-stay', () => { _saveVacuumAndStay(openedId || 0); });
}

/** Save vacuum state when user chooses to keep the item at the current location. */
async function _saveVacuumAndStay(openedId) {
    // Record the "stay" preference before closing
    if (_pendingMoveCtx) {
        _recordMoveLocChoice(_pendingMoveCtx.productId, _pendingMoveCtx.fromLoc, _pendingMoveCtx.fromLoc);
        _pendingMoveCtx = null;
    }
    closeModal();
    if (openedId) {
        const isVacuum = document.getElementById('move-vacuum-check')?.checked ? 1 : 0;
        try {
            await api('inventory_update', {}, 'POST', { id: openedId, vacuum_sealed: isVacuum });
            if (isVacuum) showToast(t('add.vacuum_saved'), 'success');
        } catch (_) {}
    }
    showPage('dashboard');
}

async function confirmMoveAfterUse(productId, fromLoc, toLoc, openedId, forcedVacuum) {
    clearMoveModalTimer();
    const newVacuum = forcedVacuum !== undefined ? (forcedVacuum ? 1 : 0) : (document.getElementById('move-vacuum-check')?.checked ? 1 : 0);
    // Record preference
    if (_pendingMoveCtx && _pendingMoveCtx.productId === productId) {
        _recordMoveLocChoice(productId, fromLoc, toLoc);
        _pendingMoveCtx = null;
    }
    closeModal();
    showLoading(true);
    try {
        const invData = await api('inventory_list');
        const invRows = invData.inventory || [];
        if (openedId) {
            const item = invRows.find(i => i.id == openedId);
            const product = { name: currentProduct?.name || item?.name || '', category: currentProduct?.category || item?.category || '' };
            const payload = {
                id: openedId,
                location: toLoc,
                product_id: productId,
                vacuum_sealed: newVacuum,
            };
            if (!item?.expiry_user_set) {
                payload.expiry_date = addDays(estimateOpenedExpiryDays(product, toLoc));
            }
            await api('inventory_update', {}, 'POST', payload);
            showToast(t('move.moved_toast').replace('{location}', LOCATIONS[toLoc]?.label || toLoc), 'success');
        } else {
            const item = invRows.find(i => i.product_id == productId && i.location === fromLoc && parseFloat(i.quantity) > 0);
            if (item) {
                const product = { name: item.name || '', category: item.category || '' };
                const payload = {
                    id: item.id,
                    location: toLoc,
                    product_id: productId,
                    vacuum_sealed: newVacuum,
                };
                if (!item.expiry_user_set) {
                    let days = estimateExpiryDays(product, toLoc);
                    if (newVacuum) days = getVacuumExpiryDays(days);
                    payload.expiry_date = addDays(days);
                }
                await api('inventory_update', {}, 'POST', payload);
                showToast(t('move.moved_simple', { location: LOCATIONS[toLoc]?.label || toLoc }), 'success');
            }
        }
    } catch (e) {
        console.error('Move error:', e);
    }
    showLoading(false);
    showPage('dashboard');
}

async function submitUseAll() {
    const name = currentProduct ? currentProduct.name : '';
    const items0 = _useCurrentItems ? _useCurrentItems.filter(i => parseFloat(i.quantity) > 0) : [];
    if (items0.length === 0) {
        if (currentProduct?.id) _showExhaustedProductModal(currentProduct.id);
        else showToast(t('use.not_in_inventory'), 'error');
        return;
    }

    const unit = items0[0]?.unit || 'pz';
    const totalQty = _useAllTotalQty(items0);
    const isConfMulti = unit === 'conf' && totalQty > 1.001;

    // Opened packages and/or conf disambiguation (one conf vs all)
    const allOpened = items0.filter(_isOpenedInventoryItem);
    if (allOpened.length >= 1 || isConfMulti) {
        _showUseAllDisambiguation(allOpened, items0, { showOneConf: isConfMulti });
        return;
    }

    // No opened packages → if there is only one item row, it's unambiguous — skip confirm
    if (items0.length === 1) {
        _doSubmitUseAll();
        return;
    }

    // Multiple rows, no opened packages → standard destructive confirm
    const qtyStr = stripHtml(formatQuantity(totalQty, unit, items0[0]?.default_quantity, items0[0]?.package_unit));
    _showDestructiveConfirm(
        t('use.use_all_confirm_title') || '✅ Finisci tutto',
        `${t('use.use_all_confirm_msg') || 'Conferma che hai finito tutto il prodotto:'} "${name}" (${qtyStr})`,
        _doSubmitUseAll,
        t('use.use_all_confirm_btn') || '✅ Sì, finito'
    );
}

async function _doSubmitUseAll() {
    // Called only when there are no opened packages (submitUseAll already handles disambiguation)
    showLoading(true);
    try {
        const result = await api('inventory_use', {}, 'POST', {
            product_id: currentProduct.id,
            use_all: true,
            location: '__all__',
        });
        showLoading(false);
        if (result.success) {
            showToast(t('toast.finished_all', { name: currentProduct.name }), 'success');
            if (result.added_to_bring) {
                setTimeout(() => showToast(t('use.toast_bring'), 'info'), 1500);
            }
            showLowStockBringPrompt(result, () => showPage('dashboard'));
        } else {
            showToast(result.error || t('error.generic'), 'error');
        }
    } catch (err) {
        showLoading(false);
        showToast(t('error.connection'), 'error');
    }
}

/**
 * Ask whether the user finished one package or everything.
 * Shown for opened packages and/or conf products with qty > 1.
 */
function _showUseAllDisambiguation(openedItems, allItems, options = {}) {
    const { showOneConf = false } = options;
    const contentEl = document.getElementById('modal-content');
    const preferLoc = document.getElementById('use-location')?.value
        || openedItems[0]?.location
        || allItems[0]?.location
        || 'dispensa';

    const locButtons = openedItems.map(item => {
        const locInfo = LOCATIONS[item.location] || { icon: '📦', label: item.location };
        const qtyStr = stripHtml(formatQuantity(parseFloat(item.quantity), item.unit, item.default_quantity, item.package_unit));
        return `<button class="btn btn-warning full-width" style="justify-content:flex-start;gap:10px;text-align:left;margin-bottom:8px"
            onclick="closeModal(); _confirmThenSubmitUseAllAt('${item.location}', true)">
            <span style="font-size:1.3rem">${locInfo.icon}</span>
            <span><strong>${escapeHtml(locInfo.label)}</strong> — 🔓 ${t('use.opened_badge')}<br>
            <small style="opacity:0.8">${escapeHtml(qtyStr)}</small></span>
        </button>`;
    }).join('');

    const totalQty = _useAllTotalQty(allItems);
    const unit = allItems[0]?.unit || 'pz';
    const totalStr = stripHtml(formatQuantity(totalQty, unit, allItems[0]?.default_quantity, allItems[0]?.package_unit));
    const oneStr = stripHtml(formatQuantity(1, unit, allItems[0]?.default_quantity, allItems[0]?.package_unit));

    const oneConfBtn = showOneConf ? `
        <button class="btn btn-primary full-width" style="justify-content:flex-start;gap:10px;text-align:left;margin-bottom:8px"
            onclick="closeModal(); _submitUseOneConf('${preferLoc}')">
            <span style="font-size:1.3rem">📦</span>
            <span>${t('use.disambiguation_one_conf').replace('{qty}', escapeHtml(oneStr))}</span>
        </button>` : '';

    contentEl.innerHTML = `
        <div class="modal-header">
            <h3>${t('use.use_all')}</h3>
            <button class="modal-close" onclick="closeModal()">✕</button>
        </div>
        <p style="font-size:0.9rem;color:var(--text-muted);margin:0 0 14px">${t('use.disambiguation_hint')}</p>
        ${locButtons}
        ${oneConfBtn}
        <button class="btn btn-danger full-width" style="margin-top:4px"
            onclick="closeModal(); _confirmThenSubmitUseAllAt('__all__', false)">
            🗑️ ${t('use.disambiguation_all').replace('{qty}', escapeHtml(totalStr))}
        </button>
    `;
    document.getElementById('modal-overlay').style.display = 'flex';
}

async function _submitUseOneConf(location) {
    showLoading(true);
    try {
        const result = await api('inventory_use', {}, 'POST', {
            product_id: currentProduct.id,
            quantity: 1,
            location,
            use_all: false,
        });
        showLoading(false);
        if (result.success) {
            showToast(t('use.toast_one_conf_finished').replace('{name}', currentProduct.name), 'success');
            if (result.added_to_bring) {
                setTimeout(() => showToast(t('use.toast_bring'), 'info'), 1500);
            }
            _recordUseLocationChoice(currentProduct.id, location);
            const _vacUnit = result.product_unit || currentProduct?.unit || '';
            const moveCallback = result.remaining > 0
                ? () => showMoveAfterUseModal(currentProduct, location, result.remaining, result.opened_id, result.opened_vacuum_sealed ?? 0, _vacUnit)
                : () => showPage('dashboard');
            showLowStockBringPrompt(result, moveCallback);
        } else if (!result.duplicate) {
            showToast(result.error || t('error.generic'), 'error');
        }
    } catch (err) {
        showLoading(false);
        showToast(t('error.connection'), 'error');
    }
}

function _confirmThenSubmitUseAllAt(location, isOpenedOnly) {
    const name = currentProduct ? currentProduct.name : '';
    const items = _useCurrentItems ? _useCurrentItems.filter(i => parseFloat(i.quantity) > 0) : [];
    if (isOpenedOnly) {
        // Finisce solo la confezione aperta — azione leggera, nessun confirm necessario
        _submitUseAllAt(location, true);
        return;
    }
    // Finisce tutto — richiede confirm distruttivo
    const totalQty = items.reduce((s, i) => s + parseFloat(i.quantity || 0), 0);
    const unit = items[0]?.unit || 'pz';
    const qtyStr = stripHtml(formatQuantity(totalQty, unit, items[0]?.default_quantity, items[0]?.package_unit));
    _showDestructiveConfirm(
        t('use.use_all_confirm_title') || '✅ Finisci tutto',
        `${t('use.use_all_confirm_msg') || 'Conferma che hai finito tutto il prodotto:'} "${name}" (${qtyStr})`,
        () => _submitUseAllAt('__all__', false),
        t('use.use_all_confirm_btn') || '✅ Sì, finito'
    );
}

async function _submitUseAllAt(location, isOpenedOnly) {
    showLoading(true);
    try {
        const result = await api('inventory_use', {}, 'POST', {
            product_id: currentProduct.id,
            use_all: true,
            location,
        });
        showLoading(false);
        if (result.success) {
            const toastMsg = isOpenedOnly
                ? `🔓 ${t('use.toast_opened_finished').replace('{name}', currentProduct.name)}`
                : `📤 ${currentProduct.name} terminato!`;
            showToast(toastMsg, 'success');
            if (result.added_to_bring) {
                setTimeout(() => showToast(t('use.toast_bring'), 'info'), 1500);
            }
            showLowStockBringPrompt(result, () => showPage('dashboard'));
        } else {
            showToast(result.error || t('error.generic'), 'error');
        }
    } catch (err) {
        showLoading(false);
        showToast(t('error.connection'), 'error');
    }
}

async function submitUse(e) {
    e.preventDefault();
    if (_useSubmitting) return; // prevent double-submit from scale auto-confirm
    _useSubmitting = true;
    _cancelScaleTimersOnly();
    _scaleStabilityVal = null;
    showLoading(true);
    try {
        let qty = parseFloat(document.getElementById('use-quantity').value) || 1;
        let displayQty = qty;
        let displayUnit = '';
        
        // Convert sub-unit to conf if needed
        if (_useConfMode && _useConfMode._activeUnit === 'sub') {
            displayUnit = _useConfMode.subLabel;
            qty = qty / _useConfMode.packageSize; // convert to conf
        } else if (_useConfMode && _useConfMode._activeUnit === 'conf') {
            displayUnit = 'conf';
        }

        // ── Validate: cannot use more than available at selected location ─────────
        const selectedLoc = document.getElementById('use-location').value;
        const locItems = _useCurrentItems.filter(i => i.location === selectedLoc);
        const maxQtyAtLoc = locItems.reduce((s, i) => s + parseFloat(i.quantity || 0), 0);
        if (maxQtyAtLoc > 0 && qty > maxQtyAtLoc + 0.001) {
            showLoading(false);
            _useSubmitting = false;
            showToast(t('use.error_exceeds_stock'), 'error');
            // Shake the input to make it obvious
            const inp = document.getElementById('use-quantity');
            inp.classList.add('input-shake');
            setTimeout(() => inp.classList.remove('input-shake'), 600);
            return;
        }
        // ─────────────────────────────────────────────────────────────────────────
        
        const result = await api('inventory_use', {}, 'POST', {
            product_id: currentProduct.id,
            quantity: qty,
            location: document.getElementById('use-location').value,
        });
        showLoading(false);
        _useSubmitting = false;
        if (result.success) {
            const usedText = displayUnit ? `${displayQty}${displayUnit}` : displayQty;
            showToast(t('use.toast_used').replace('{qty}', usedText).replace('{name}', currentProduct.name), 'success');
            if (result.added_to_bring) {
                setTimeout(() => showToast(t('toast.finished_to_bring'), 'info'), 1500);
            }
            // If there's remaining quantity, offer to move to another location
            const usedFrom = document.getElementById('use-location').value;
            _recordUseLocationChoice(currentProduct.id, usedFrom); // track for preferred-location feature
            const _vacUnit = result.product_unit || currentProduct?.unit || '';
            const moveCallback = result.remaining > 0
                ? () => showMoveAfterUseModal(currentProduct, usedFrom, result.remaining, result.opened_id, result.opened_vacuum_sealed ?? 0, _vacUnit)
                : () => showPage('dashboard');
            // Check low stock → Bring! prompt, then move/vacuum modal
            showLowStockBringPrompt(result, moveCallback);
        } else if (result.duplicate) {
            showToast(result.error || t('use.duplicate_recent'), 'info');
            loadUseInventoryInfo();
        } else {
            showToast(result.error || t('error.generic'), 'error');
        }
    } catch (err) {
        showLoading(false);
        _useSubmitting = false;
        showToast(t('error.connection'), 'error');
    }
}

// ===== AI IDENTIFICATION =====
async function captureForAI() {
    if (!_requireGemini()) return;
    stopScanner();
    showPage('ai');
}

async function initAICamera() {
    const video = document.getElementById('ai-video');
    const captureDiv = document.getElementById('ai-capture');
    const previewDiv = document.getElementById('ai-preview');
    const captureBtn = document.getElementById('ai-capture-btn');
    const retakeBtn = document.getElementById('ai-retake-btn');
    const resultDiv = document.getElementById('ai-result');
    
    captureDiv.style.display = 'block';
    previewDiv.style.display = 'none';
    captureBtn.style.display = 'block';
    retakeBtn.style.display = 'none';
    resultDiv.style.display = 'none';
    
    try {
        if (aiStream) {
            aiStream.getTracks().forEach(t => t.stop());
        }
        aiStream = await navigator.mediaDevices.getUserMedia(getCameraConstraints());
        video.srcObject = aiStream;
        await video.play();
    } catch (err) {
        console.error('AI Camera error:', err);
        showToast(t('error.camera'), 'error');
    }
}

function takePhotoForAI() {
    const video = document.getElementById('ai-video');
    const canvas = document.getElementById('ai-canvas');
    const img = document.getElementById('ai-image');
    
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(video, 0, 0);
    
    const dataUrl = canvas.toDataURL('image/jpeg', 0.85);
    img.src = dataUrl;
    
    // Stop camera
    if (aiStream) {
        aiStream.getTracks().forEach(t => t.stop());
        aiStream = null;
    }
    video.srcObject = null;
    
    document.getElementById('ai-capture').style.display = 'none';
    document.getElementById('ai-preview').style.display = 'block';
    document.getElementById('ai-capture-btn').style.display = 'none';
    document.getElementById('ai-retake-btn').style.display = 'block';

    // Immediately start analysis
    analyzeWithAI();
}

function retakePhotoAI() {
    document.getElementById('ai-result').style.display = 'none';
    initAICamera();
}

async function analyzeWithAI() {
    const resultDiv = document.getElementById('ai-result');
    resultDiv.style.display = 'block';
    resultDiv.innerHTML = `<div style="text-align:center;padding:20px"><div class="loading-spinner" style="margin:0 auto 12px"></div><p>${t('scanner.ai_identifying')}</p></div>`;

    const canvas = document.getElementById('ai-canvas');
    const base64 = canvas.toDataURL('image/jpeg', 0.7).split(',')[1];

    try {
        const result = await api('gemini_identify', {}, 'POST', { image: base64 });

        if (!result.success) {
            if (result.error === 'no_api_key') {
                resultDiv.innerHTML = `<p style="color:var(--warning)">${t('ai.no_api_key').replace(/\n/g, '<br>')}</p>`;
            } else if (/resource.?exhaust|quota|rate.?limit/i.test(result.error || '')) {
                resultDiv.innerHTML = `<p style="color:var(--warning)">⏳ ${t('error.ai_quota')}</p>
                    <button class="btn btn-secondary full-width mt-2" onclick="retakePhotoAI()">${t('btn.retry')}</button>`;
            } else {
                resultDiv.innerHTML = `<p style="color:var(--danger)">❌ ${escapeHtml(result.error || t('error.identification'))}</p>
                    <button class="btn btn-secondary full-width mt-2" onclick="retakePhotoAI()">${t('btn.retry')}</button>`;
            }
            return;
        }

        const id = result.identified;
        const matches = result.off_matches || [];

        // Search local DB for existing products that match the AI identification
        let localMatches = [];
        try {
            const nameWords = (id.name || '').split(/\s+/).filter(w => w.length > 2);
            const searches = [api('products_search', { q: id.name })];
            if (id.brand) searches.push(api('products_search', { q: id.brand }));
            const results = await Promise.all(searches);
            const seen = new Set();
            results.forEach(r => {
                (r.products || []).forEach(p => {
                    if (!seen.has(p.id)) {
                        seen.add(p.id);
                        localMatches.push(p);
                    }
                });
            });
        } catch(e) { /* ignore search errors */ }

        let html = `<h4>🤖 Prodotto identificato</h4>`;
        html += `<div class="ai-identified-card">`;
        html += `<strong>${escapeHtml(id.name)}</strong>`;
        if (id.brand) html += ` <span style="color:var(--text-muted)">- ${escapeHtml(id.brand)}</span>`;
        if (id.description) html += `<p style="font-size:0.85rem;color:var(--text-light);margin:4px 0 0">${escapeHtml(id.description)}</p>`;
        html += `</div>`;

        // Show existing local products first
        if (localMatches.length > 0) {
            html += `<h4 style="margin-top:16px">${t('product.already_in_pantry')}</h4>`;
            html += `<div class="ai-matches-list">`;
            localMatches.forEach((p, idx) => {
                html += `<div class="ai-match-item" onclick="selectLocalMatch(${p.id})">`;
                if (p.image_url) {
                    html += `<img src="${escapeHtml(p.image_url)}" alt="" class="ai-match-img" onerror="this.style.display='none'">`;
                }
                html += `<div class="ai-match-info">`;
                html += `<strong>${escapeHtml(p.name)}</strong>`;
                if (p.brand) html += `<br><small>${escapeHtml(p.brand)}</small>`;
                if (p.default_quantity && p.unit) html += `<br><small style="color:var(--text-muted)">${p.default_quantity} ${p.unit}</small>`;
                html += `</div>`;
                if (p.barcode) html += `<span class="ai-match-barcode">${p.barcode}</span>`;
                html += `</div>`;
            });
            html += `</div>`;
        }

        if (matches.length > 0) {
            html += `<h4 style="margin-top:16px">📦 Prodotti corrispondenti</h4>`;
            html += `<div class="ai-matches-list">`;
            matches.forEach((m, idx) => {
                html += `<div class="ai-match-item" onclick="selectAIMatch(${idx})">`;
                if (m.image_url) {
                    html += `<img src="${m.image_url}" alt="" class="ai-match-img" onerror="this.style.display='none'">`;
                }
                html += `<div class="ai-match-info">`;
                html += `<strong>${escapeHtml(m.name)}</strong>`;
                if (m.brand) html += `<br><small>${escapeHtml(m.brand)}</small>`;
                if (m.quantity_info) html += `<br><small style="color:var(--text-muted)">${escapeHtml(m.quantity_info)}</small>`;
                html += `</div>`;
                html += `<span class="ai-match-barcode">${m.barcode}</span>`;
                html += `</div>`;
            });
            html += `</div>`;
        }

        // Option to save as-is without barcode
        html += `<div style="margin-top:16px; border-top: 1px solid var(--bg-light); padding-top: 12px">`;
        html += `<button class="btn btn-secondary full-width" onclick="saveAIProductDirect()">${t('scanner.save_new_btn')}</button>`;
        html += `</div>`;

        resultDiv.innerHTML = html;

        // Store data for later use
        window._aiIdentified = id;
        window._aiMatches = matches;

    } catch (err) {
        console.error('AI identify error:', err);
        resultDiv.innerHTML = `<p style="color:var(--danger)">❌ ${t('error.connection')}</p>
            <button class="btn btn-secondary full-width mt-2" onclick="retakePhotoAI()">${t('btn.retry')}</button>`;
    }
}

async function selectLocalMatch(productId) {
    showLoading(true);
    try {
        const result = await api('product_get', { id: productId });
        if (result.product) {
            currentProduct = result.product;
            showLoading(false);
            showProductAction();
        } else {
            showLoading(false);
            showToast(t('error.not_found'), 'error');
        }
    } catch (err) {
        showLoading(false);
        showToast(t('error.connection'), 'error');
    }
}

async function selectAIMatch(idx) {
    const match = window._aiMatches[idx];
    if (!match) return;

    showLoading(true);

    try {
        // Use the barcode to do a full lookup (gets all details)
        const localResult = await api('search_barcode', { barcode: match.barcode });
        if (localResult.found) {
            currentProduct = localResult.product;
            showLoading(false);
            showProductAction();
            return;
        }

        // Full lookup via OpenFoodFacts
        const lookupResult = await api('lookup_barcode', { barcode: match.barcode });
        if (lookupResult.found && lookupResult.product) {
            const p = lookupResult.product;
            const detected = detectUnitAndQuantity(p.quantity_info);

            const notesParts = [];
            if (p.quantity_info) notesParts.push(`${t('product.weight_label')}: ${p.quantity_info}`);
            if (p.nutriscore) notesParts.push(`Nutriscore: ${p.nutriscore.toUpperCase()}`);
            if (p.nova_group) notesParts.push(`NOVA: ${p.nova_group}`);
            if (p.ecoscore) notesParts.push(`Ecoscore: ${p.ecoscore.toUpperCase()}`);
            if (p.origin) notesParts.push(`${t('product.origin_label')}: ${p.origin}`);

            const saveResult = await api('product_save', {}, 'POST', {
                barcode: match.barcode,
                name: p.name || match.name,
                brand: p.brand || match.brand || '',
                category: p.category || '',
                image_url: p.image_url || match.image_url || '',
                unit: detected.unit,
                default_quantity: detected.quantity,
                notes: notesParts.join(' · '),
            });

            if (saveResult.id) {
                currentProduct = {
                    id: saveResult.id,
                    barcode: match.barcode,
                    name: p.name || match.name,
                    brand: p.brand || match.brand || '',
                    category: p.category || '',
                    image_url: p.image_url || match.image_url || '',
                    unit: detected.unit,
                    default_quantity: detected.quantity,
                    weight_info: p.quantity_info || '',
                };
                showLoading(false);
                showProductAction();
                return;
            }
        }

        // Fallback: save with basic info from match
        const saveResult = await api('product_save', {}, 'POST', {
            barcode: match.barcode,
            name: match.name,
            brand: match.brand || '',
            category: match.category || '',
            image_url: match.image_url || '',
            unit: 'pz',
            default_quantity: 1,
        });

        if (saveResult.id) {
            currentProduct = { id: saveResult.id, barcode: match.barcode, name: match.name, brand: match.brand || '', category: match.category || '', image_url: match.image_url || '', unit: 'pz', default_quantity: 1 };
            showLoading(false);
            showProductAction();
        } else {
            showLoading(false);
            showToast(t('error.save'), 'error');
        }
    } catch (err) {
        showLoading(false);
        console.error('AI match select error:', err);
        showToast(t('error.connection'), 'error');
    }
}

async function saveAIProductDirect() {
    const id = window._aiIdentified;
    if (!id) return;

    showLoading(true);
    try {
        const result = await api('product_save', {}, 'POST', {
            name: id.name,
            brand: id.brand || '',
            category: mapToLocalCategory(id.category || '', id.name || '', id.brand || ''),
            unit: 'pz',
            default_quantity: 1,
        });

        if (result.success || result.id) {
            currentProduct = {
                id: result.id,
                name: id.name,
                brand: id.brand || '',
                category: mapToLocalCategory(id.category || '', id.name || '', id.brand || ''),
                unit: 'pz',
                default_quantity: 1,
            };
            showLoading(false);
            showToast(result.merged ? t('scan.ai_match_merged_existing') : t('toast.product_saved'), result.merged ? 'info' : 'success');
            showProductAction();
        } else if (result.error === 'barcode_already_used' && result.existing_id) {
            const full = await api('product_get', { id: result.existing_id }).catch(() => null);
            if (full?.product) {
                currentProduct = full.product;
                showLoading(false);
                showToast(t('scan.ai_match_merged_existing'), 'info');
                showProductAction();
            } else {
                showLoading(false);
                showToast(t('error.save'), 'error');
            }
        } else {
            showLoading(false);
            showToast(result.error || t('error.save'), 'error');
        }
    } catch (err) {
        showLoading(false);
        showToast(t('error.connection'), 'error');
    }
}

// ===== AI PHOTO FILL FOR PRODUCT FORM =====
let _pfAiStream = null;

async function captureForAIFormFill() {
    if (!_requireGemini()) return;
    document.getElementById('modal-content').innerHTML = `
        <div class="modal-header">
            <h3>📷 ${t('scan.ai_identify')}</h3>
            <button class="modal-close" onclick="closePfAiScanner()">✕</button>
        </div>
        <div class="expiry-scanner">
            <div id="pfai-cam-container" style="position:relative;border-radius:10px;overflow:hidden;background:#000;aspect-ratio:4/3">
                <video id="pfai-video" autoplay playsinline style="width:100%;height:100%;object-fit:cover"></video>
                <canvas id="pfai-canvas" style="display:none"></canvas>
                <div style="position:absolute;inset:0;border:2px dashed rgba(255,255,255,0.4);border-radius:10px;pointer-events:none"></div>
            </div>
            <div id="pfai-preview-container" style="display:none;border-radius:10px;overflow:hidden;aspect-ratio:4/3">
                <img id="pfai-preview-img" src="" alt="" style="width:100%;height:100%;object-fit:cover">
            </div>
            <div id="pfai-status" style="display:none;text-align:center;padding:12px">
                <div class="loading-spinner" style="margin:0 auto 8px"></div>
                <p>${t('scanner.ai_identifying')}</p>
            </div>
            <div id="pfai-result" style="display:none"></div>
            <p class="form-hint" style="text-align:center;margin:6px 0;font-size:0.8rem" id="pfai-hint">${t('scanner.product_label_hint')}</p>
            <div style="display:flex;gap:8px;margin-top:8px">
                <button class="btn btn-large btn-accent" style="flex:1" id="pfai-capture-btn" onclick="pfAiCapture()">${t('scanner.capture_btn')}</button>
                <button class="btn btn-large btn-secondary" style="flex:1;display:none" id="pfai-retake-btn" onclick="pfAiRetake()">${t('scanner.retake_btn')}</button>
            </div>
        </div>
    `;
    document.getElementById('modal-overlay').style.display = 'flex';

    try {
        _pfAiStream = await navigator.mediaDevices.getUserMedia(getCameraConstraints());
        const video = document.getElementById('pfai-video');
        video.srcObject = _pfAiStream;
        await video.play();
    } catch (err) {
        document.getElementById('pfai-cam-container').innerHTML =
            `<p style="color:var(--danger);text-align:center;padding:20px">${t('error.camera')}</p>`;
    }
}

function closePfAiScanner() {
    if (_pfAiStream) { _pfAiStream.getTracks().forEach(t => t.stop()); _pfAiStream = null; }
    closeModal();
}

function pfAiCapture() {
    const video = document.getElementById('pfai-video');
    const canvas = document.getElementById('pfai-canvas');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0);
    const dataUrl = canvas.toDataURL('image/jpeg', 0.85);
    document.getElementById('pfai-preview-img').src = dataUrl;

    if (_pfAiStream) { _pfAiStream.getTracks().forEach(t => t.stop()); _pfAiStream = null; }
    video.srcObject = null;

    document.getElementById('pfai-cam-container').style.display = 'none';
    document.getElementById('pfai-preview-container').style.display = 'block';
    document.getElementById('pfai-capture-btn').style.display = 'none';
    document.getElementById('pfai-retake-btn').style.display = 'inline-flex';
    document.getElementById('pfai-hint').style.display = 'none';

    _pfAiAnalyze(canvas.toDataURL('image/jpeg', 0.7).split(',')[1]);
}

function pfAiRetake() {
    document.getElementById('pfai-cam-container').style.display = 'block';
    document.getElementById('pfai-preview-container').style.display = 'none';
    document.getElementById('pfai-capture-btn').style.display = 'inline-flex';
    document.getElementById('pfai-retake-btn').style.display = 'none';
    document.getElementById('pfai-status').style.display = 'none';
    document.getElementById('pfai-result').style.display = 'none';
    document.getElementById('pfai-hint').style.display = 'block';

    navigator.mediaDevices.getUserMedia(getCameraConstraints()).then(stream => {
        _pfAiStream = stream;
        const video = document.getElementById('pfai-video');
        video.srcObject = stream;
        video.play();
    });
}

async function _pfAiAnalyze(base64) {
    const statusEl = document.getElementById('pfai-status');
    const resultEl = document.getElementById('pfai-result');
    statusEl.style.display = 'block';
    resultEl.style.display = 'none';

    try {
        const result = await api('gemini_identify', {}, 'POST', { image: base64 });

        statusEl.style.display = 'none';
        resultEl.style.display = 'block';

        if (!result.success) {
            if (/resource.?exhaust|quota|rate.?limit/i.test(result.error || '')) {
                resultEl.innerHTML = `<p style="color:var(--warning);text-align:center">⏳ ${t('error.ai_quota')}</p>
                    <button class="btn btn-secondary full-width" onclick="pfAiRetake()">${t('btn.retry')}</button>`;
            } else {
                resultEl.innerHTML = `<p style="color:var(--danger);text-align:center">❌ ${escapeHtml(result.error || t('error.identification'))}</p>
                    <button class="btn btn-secondary full-width" onclick="pfAiRetake()">${t('btn.retry')}</button>`;
            }
            return;
        }

        const id = result.identified;
        const matches = result.off_matches || [];

        let html = `<div class="ai-identified-card" style="margin-bottom:10px">
            <strong>${escapeHtml(id.name)}</strong>`;
        if (id.brand) html += ` <span style="color:var(--text-muted)">— ${escapeHtml(id.brand)}</span>`;
        if (id.description) html += `<p style="font-size:0.82rem;color:var(--text-light);margin:4px 0 0">${escapeHtml(id.description)}</p>`;
        html += `</div>`;

        if (matches.length > 0) {
            html += `<p style="font-size:0.82rem;color:var(--text-muted);margin-bottom:6px">${t('product.select_variant')}</p>`;
            html += `<div class="ai-matches-list" style="max-height:160px;overflow-y:auto;margin-bottom:10px">`;
            matches.forEach((m, idx) => {
                html += `<div class="ai-match-item" onclick="_pfAiFillFromMatch(${idx})">`;
                if (m.image_url) html += `<img src="${escapeHtml(m.image_url)}" alt="" class="ai-match-img" onerror="this.style.display='none'">`;
                html += `<div class="ai-match-info"><strong>${escapeHtml(m.name)}</strong>`;
                if (m.brand) html += `<br><small>${escapeHtml(m.brand)}</small>`;
                if (m.quantity_info) html += `<br><small style="color:var(--text-muted)">${escapeHtml(m.quantity_info)}</small>`;
                html += `</div><span class="ai-match-barcode">${escapeHtml(m.barcode)}</span></div>`;
            });
            html += `</div>`;
        }

        html += `<button class="btn btn-primary full-width" onclick="_pfAiFillFromAI()">${matches.length > 0 ? t('ai.use_data_no_barcode') : t('ai.use_data')}</button>`;
        resultEl.innerHTML = html;

        window._pfAiIdentified = id;
        window._pfAiMatches = matches;

    } catch (err) {
        statusEl.style.display = 'none';
        resultEl.style.display = 'block';
        resultEl.innerHTML = `<p style="color:var(--danger);text-align:center">❌ ${t('error.connection')}</p>
            <button class="btn btn-secondary full-width" onclick="pfAiRetake()">${t('btn.retry')}</button>`;
    }
}

function _pfAiFillFields(name, brand, category, barcode, imageUrl, quantityInfo) {
    if (name) document.getElementById('pf-name').value = name;
    if (brand) document.getElementById('pf-brand').value = brand;
    if (category) {
        const cat = mapToLocalCategory(category, name || '');
        document.getElementById('pf-category').value = cat;
        document.getElementById('pf-category').dataset.manuallySet = 'true';
        onCategoryChange(true);
    }
    if (barcode) document.getElementById('pf-barcode').value = barcode;
    if (imageUrl) {
        document.getElementById('pf-image').value = imageUrl;
        const preview = document.getElementById('pf-image-preview');
        document.getElementById('pf-image-img').src = imageUrl;
        preview.style.display = 'block';
    }
    if (quantityInfo) {
        const detected = detectUnitAndQuantity(quantityInfo);
        document.getElementById('pf-unit').value = detected.unit;
        document.getElementById('pf-defqty').value = detected.quantity;
        document.getElementById('pf-defqty').dataset.manuallySet = 'true';
        onPfUnitChange();
    }
    // Trigger auto-detect for remaining empty fields
    if (name && !category) autoDetectCategory();
    closePfAiScanner();
    showToast(t('ai.fields_filled'), 'success');
}

function _pfAiFillFromAI() {
    const id = window._pfAiIdentified;
    if (!id) return;
    _pfAiFillFields(id.name, id.brand, id.category, '', '', '');
}

async function _pfAiFillFromMatch(idx) {
    const match = window._pfAiMatches[idx];
    if (!match) return;
    closePfAiScanner();
    showLoading(true);
    try {
        const lookupResult = await api('lookup_barcode', { barcode: match.barcode });
        if (lookupResult.found && lookupResult.product) {
            const p = lookupResult.product;
            _pfAiFillFields(p.name || match.name, p.brand || match.brand, p.category || '', match.barcode, p.image_url || match.image_url, p.quantity_info || '');
            showLoading(false);
            return;
        }
    } catch (e) {}
    showLoading(false);
    _pfAiFillFields(match.name, match.brand, match.category, match.barcode, match.image_url, '');
}

// ===== ALL PRODUCTS =====
async function loadAllProducts() {
    try {
        const data = await api('products_list');
        renderProductsList(data.products || []);
    } catch (err) {
        console.error(err);
    }
}

async function searchAllProducts() {
    const q = document.getElementById('products-search').value;
    if (q.length < 2) {
        loadAllProducts();
        return;
    }
    const data = await api('products_search', { q });
    renderProductsList(data.products || []);
}

function renderProductsList(products) {
    const container = document.getElementById('products-list');
    if (products.length === 0) {
        container.innerHTML = `<div class="empty-state"><div class="empty-state-icon">📦</div><p>${t('inventory.empty_db')}</p></div>`;
        return;
    }
    container.innerHTML = products.map(p => {
        const catIcon = CATEGORY_ICONS[mapToLocalCategory(p.category, p.name)] || '📦';
        return `
        <div class="product-item" onclick="selectProductForAction(${p.id})">
            <div class="inv-image">
                ${p.image_url ? `<img src="${escapeHtml(p.image_url)}" alt="" onerror="this.parentElement.innerHTML='${catIcon}'">` : catIcon}
            </div>
            <div class="inv-info">
                <div class="inv-name">${escapeHtml(p.name)}</div>
                ${p.brand ? `<div class="inv-brand">${escapeHtml(p.brand)}</div>` : ''}
                <div class="inv-meta">
                    ${p.barcode ? `<span class="inv-badge" style="background:#f3f4f6;color:#374151">📊 ${p.barcode}</span>` : ''}
                    <span class="inv-badge" style="background:#f3f4f6;color:#374151">${catIcon} ${p.category || 'Non categorizzato'}</span>
                </div>
            </div>
        </div>`;
    }).join('');
}

async function selectProductForAction(productId) {
    showLoading(true);
    try {
        const data = await api('product_get', { id: productId });
        if (data.product) {
            currentProduct = data.product;
            showLoading(false);
            // Clear search inputs after selecting a product
            const psInput = document.getElementById('products-search');
            if (psInput) psInput.value = '';
            const invInput = document.getElementById('inventory-search');
            if (invInput) invInput.value = '';
            showProductAction();
        } else {
            showLoading(false);
            showToast(t('error.not_found'), 'error');
        }
    } catch (err) {
        showLoading(false);
        showToast(t('error.generic'), 'error');
    }
}

// ===== SHOPPING LIST (BRING! INTEGRATION) =====
let shoppingListUUID = '';
let shoppingItems = [];
let suggestionItems = [];
let _spesaScanTarget = null; // { name, rawName, idx } when tapping item to scan

// Inventory cache for "already at home" hints in the shopping list.
// Loaded once per shopping page visit and reused for all item hints.
let _shoppingInventoryCache = null;
async function _getShoppingInventoryCache() {
    if (_shoppingInventoryCache !== null) return _shoppingInventoryCache;
    try {
        const data = await api('inventory_list');
        _shoppingInventoryCache = data.inventory || [];
    } catch(e) {
        _shoppingInventoryCache = [];
    }
    return _shoppingInventoryCache;
}

// ===== SHOPPING TABS =====
function switchShoppingTab(tab) {
    document.querySelectorAll('.shopping-tab').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-panel-shopping').forEach(p => p.classList.remove('active'));
    document.getElementById(`tab-${tab}`)?.classList.add('active');
    document.getElementById(`tab-panel-${tab}`)?.classList.add('active');
}

function updateShoppingTabCounts() {
    const acquistoCount = shoppingItems.length;
    const previsioneCount = smartShoppingItems.filter(i => !i.on_bring).length;
    const acqEl = document.getElementById('tab-count-acquisto');
    const prevEl = document.getElementById('tab-count-previsione');
    if (acqEl) acqEl.textContent = acquistoCount;
    if (prevEl) prevEl.textContent = previsioneCount;
    // Unified list: tabs hidden; suggestions shown inline below the Bring list
    document.getElementById('shopping-tabs')?.style.setProperty('display', 'none');
    _updateShoppingFooter();
}

// ===== LOCAL SHOPPING TAGS (server-synced) =====
function getShoppingTags(itemName) {
    const tags = _shoppingTagsCache || {};
    return tags[itemName.toLowerCase()] || [];
}

function toggleShoppingTag(itemIdx, tag) {
    const item = shoppingItems[itemIdx];
    if (!item) return;
    try {
        const key = item.name.toLowerCase();
        const tags = Object.assign({}, _shoppingTagsCache || {});
        const existing = (tags[key] || []).slice();
        const pos = existing.indexOf(tag);
        if (pos >= 0) existing.splice(pos, 1);
        else existing.push(tag);
        if (existing.length) tags[key] = existing;
        else delete tags[key];
        _shoppingTagsCache = tags;
        _saveToServer('shopping_tags', tags);

        // Sync urgente/presto tag to Bring specification so it's visible in the Bring app
        if (tag === 'urgente' && shoppingListUUID) {
            const isNowUrgent = existing.includes('urgente');
            const newSpec = isNowUrgent ? t('shopping.urgency_spec_critical') : '';
            api('shopping_add', {}, 'POST', {
                items: [{ name: item.name, specification: newSpec, update_spec: true }],
                listUUID: shoppingListUUID,
            }).catch(() => {});
            // Update local item spec for immediate re-render
            item.specification = newSpec;
        }

        renderShoppingItems();
    } catch (e) { console.error('toggleShoppingTag', e); }
}

// ===== SCAN FROM SHOPPING LIST =====
function _stockBaseForGap(qty, unit, defQty, pkgUnit) {
    const q = parseFloat(qty) || 0;
    if (q <= 0) return 0;
    const u = unit || 'conf';
    const def = parseFloat(defQty) || 0;
    const pu = (pkgUnit || '').toLowerCase();
    if (u !== 'conf' || def <= 0) return q;
    if (pu === 'g' || pu === 'ml') return q * def;
    if (pu === 'kg' || pu === 'l' || pu === 'lt') return q * def * 1000;
    return q;
}

/** Recompute suggested purchase qty for the current plan-days horizon (client-side). */
function _sanitizePieceMonthly(amount, useCount, usesPerMonth, unit) {
    if (amount <= 0 || unit !== 'pz') return amount;
    if (usesPerMonth > 0) {
        const cap = usesPerMonth * 1.2;
        if (amount > cap) return cap;
    }
    if (useCount > 0 && (amount / useCount) > 6) {
        return Math.min(amount, useCount * 3);
    }
    return Math.min(amount, 120);
}

function _sanitizePieceDailyRate(daily, useCount, usesPerMonth, unit) {
    if (daily <= 0 || unit !== 'pz') return daily;
    if (useCount > 0 && usesPerMonth > 0) {
        const cap = (usesPerMonth / 30) * 1.2;
        daily = Math.min(daily, Math.max(0.2, cap));
    }
    return Math.min(daily, 1.5);
}

function _periodNeedForPlanDays(monthly, daily, planDays, usesPerMonth, unit) {
    if (unit === 'pz' && usesPerMonth >= 0.5) {
        let avgPerEvent = 1;
        if (monthly > 0.001) {
            avgPerEvent = Math.min(1.2, Math.max(0.5, monthly / Math.max(0.5, usesPerMonth)));
        }
        const eventNeed = usesPerMonth * (planDays / 30) * avgPerEvent;
        if (daily > 0.001) return Math.min(eventNeed, daily * planDays);
        if (monthly > 0.001) return Math.min(eventNeed, monthly * (planDays / 30));
        return eventNeed;
    }
    if (daily > 0.001) return daily * planDays;
    if (monthly > 0.001) return monthly * (planDays / 30);
    return 0;
}

function _maxSuggestedPieces(planDays) {
    return Math.max(2, Math.min(30, Math.ceil(planDays * 2.5)));
}

/** Ceil discrete counts (conf/pz always round up). */
function _ceilDiscreteQty(need) {
    if (need <= 0) return 0;
    return Math.max(1, Math.ceil(need));
}

function _suggestedConfQty(needBase, defQty, pkgUnit, maxPkgs = 6) {
    const pu = (pkgUnit || '').toLowerCase();
    if (defQty > 0 && (pu === 'g' || pu === 'ml')) {
        const pkgs = _ceilDiscreteQty(needBase / defQty);
        return { suggested_qty: Math.max(1, Math.min(maxPkgs, pkgs)), suggested_unit: 'conf' };
    }
    const qty = _ceilDiscreteQty(needBase);
    return { suggested_qty: Math.max(1, Math.min(maxPkgs, qty)), suggested_unit: 'conf' };
}

function _computeSuggestedQtyForPlanDays(smartData) {
    if (!smartData) return null;
    const planDays = getShoppingPlanDays();
    const useCount = parseInt(smartData.use_count, 10) || 0;
    const usesPerMonth = parseFloat(smartData.uses_per_month) || 0;
    const unit = smartData.unit || 'conf';
    let daily = parseFloat(smartData.daily_rate) || 0;
    let monthly = parseFloat(smartData.monthly_usage) || 0;
    if (unit === 'pz') {
        monthly = _sanitizePieceMonthly(monthly, useCount, usesPerMonth, unit);
        daily = _sanitizePieceDailyRate(daily, useCount, usesPerMonth, unit);
    }
    const defQty = parseFloat(smartData.default_qty) || 0;
    const pkgUnit = (smartData.package_unit || '').toLowerCase();
    const stock = parseFloat(smartData.current_qty) || 0;
    const onBring = !!smartData.on_bring;
    const urgency = smartData.urgency || '';

    let periodNeed = _periodNeedForPlanDays(monthly, daily, planDays, usesPerMonth, unit);

    if (periodNeed <= 0 && daily <= 0 && monthly <= 0) {
        return smartData.suggested_qty > 0
            ? { suggested_qty: smartData.suggested_qty, suggested_unit: smartData.suggested_unit || unit, suggested_approx: !!smartData.suggested_approx }
            : null;
    }

    const stockBase = _stockBaseForGap(stock, unit, defQty, pkgUnit);
    let needBase = Math.max(0, periodNeed - stockBase);
    if (needBase <= 0 && onBring && periodNeed > 0 && stock <= 0) {
        needBase = periodNeed;
    } else if (needBase <= 0 && onBring && (urgency === 'critical' || urgency === 'high')) {
        needBase = Math.max(periodNeed * 0.5, defQty > 0 ? defQty : 1);
    }

    if (needBase <= 0) {
        if (onBring) {
            return { suggested_qty: 1, suggested_unit: unit === 'pz' ? 'pz' : 'conf', suggested_approx: true };
        }
        return null;
    }

    let suggestedQty = null;
    let suggestedUnit = unit;
    let suggestedApprox = planDays !== getShoppingPlanDaysDefault() || daily <= 0;

    if (unit === 'conf') {
        const conf = _suggestedConfQty(needBase, defQty, pkgUnit);
        suggestedQty = conf.suggested_qty;
        suggestedUnit = conf.suggested_unit;
    } else if (pkgUnit && defQty > 0) {
        suggestedQty = Math.max(1, Math.min(6, _ceilDiscreteQty(needBase / defQty)));
        suggestedUnit = 'conf';
        suggestedApprox = true;
    } else if ((unit === 'g' || unit === 'ml') && defQty > 0) {
        const pkgs = Math.max(1, Math.min(6, Math.ceil(needBase / defQty)));
        suggestedQty = pkgs * defQty;
        suggestedUnit = unit;
        suggestedApprox = true;
    } else if (unit === 'g' || unit === 'ml') {
        if (needBase >= 30) {
            let rounded;
            if (needBase < 500) rounded = Math.max(100, Math.round(needBase / 100) * 100);
            else if (needBase < 2000) rounded = Math.max(250, Math.round(needBase / 250) * 250);
            else rounded = Math.max(500, Math.round(needBase / 500) * 500);
            suggestedQty = rounded;
            suggestedUnit = unit;
            suggestedApprox = true;
        }
    } else if (unit === 'pz') {
        const rounded = _ceilDiscreteQty(needBase);
        suggestedQty = Math.max(1, Math.min(_maxSuggestedPieces(planDays), rounded));
        suggestedUnit = 'pz';
    }

    if (suggestedQty == null) return null;
    return { suggested_qty: suggestedQty, suggested_unit: suggestedUnit, suggested_approx: suggestedApprox };
}

function _effectiveSmartQty(smartData) {
    const computed = _computeSuggestedQtyForPlanDays(smartData);
    if (computed) return computed;
    if (smartData?.suggested_qty > 0) {
        return {
            suggested_qty: smartData.suggested_qty,
            suggested_unit: smartData.suggested_unit || smartData.unit,
            suggested_approx: !!smartData.suggested_approx,
        };
    }
    return null;
}

function _shoppingSuggestedToAddQty(smartData) {
    const eff = _effectiveSmartQty(smartData);
    if (!eff?.suggested_qty || eff.suggested_qty <= 0) {
        return { qty: 1, unit: smartData?.unit || 'conf' };
    }
    const sq = eff.suggested_qty;
    const su = eff.suggested_unit || smartData?.unit || 'pz';
    if (su === 'conf') return { qty: sq, unit: 'conf' };
    if (su === 'pz') return { qty: sq, unit: 'pz' };
    const defQty = parseFloat(smartData.default_qty) || 0;
    if (smartData.unit === 'conf' && defQty > 0 && (su === 'g' || su === 'ml')) {
        return { qty: Math.max(1, Math.ceil(sq / defQty)), unit: 'conf' };
    }
    return { qty: sq, unit: su };
}

function _shoppingBuyQtyDisplay(smartData) {
    if (!smartData) return null;
    const eff = _effectiveSmartQty(smartData);
    if (!eff) return null;
    const label = _formatSuggestQty(eff.suggested_qty, eff.suggested_unit || smartData.unit);
    if (!label) return null;
    const approx = eff.suggested_approx ? '~' : '';
    return { label: approx + label, monthHint: '', approx: !!eff.suggested_approx };
}

function markShoppingItemBought(idx) {
    loadShoppingList._lastUserInteraction = Date.now();
    const item = shoppingItems[idx];
    if (!item) return;
    const smartData = _matchBringToSmart(item.name, smartShoppingItems);
    _spesaScanTarget = {
        name: item.name,
        rawName: item.rawName || '',
        idx,
        mode: 'bought',
        buyQty: _shoppingSuggestedToAddQty(smartData),
        smartData,
    };
    _shoppingBoughtFlow = true;
    showPage('scan');
    showToast(t('shopping.bought_scan_toast').replace('{name}', _shoppingListGenericTitle(item, smartData)), 'info');
}

/** Step 1: bought at store — remove from list; add to pantry at home via spesa mode later. */
async function markShoppingItemPurchasedLater(idx) {
    loadShoppingList._lastUserInteraction = Date.now();
    const item = shoppingItems[idx];
    if (!item) return;
    const smartData = _matchBringToSmart(item.name, smartShoppingItems);
    const displayName = _shoppingListGenericTitle(item, smartData);
    const panel = document.getElementById('tab-panel-acquisto');
    const scrollY = panel?.scrollTop || 0;
    try {
        const data = await api('shopping_remove', {}, 'POST', {
            name: item.name,
            rawName: item.rawName || '',
            listUUID: shoppingListUUID,
        });
        if (data.success || data._offline) {
            _markBringPurchased([item.name]);
            shoppingItems.splice(idx, 1);
            _offlineShoppingCacheSet({ items: shoppingItems, listUUID: shoppingListUUID });
            renderShoppingItems();
            if (panel) panel.scrollTop = scrollY;
            showToast(t('shopping.bought_later_toast').replace('{name}', displayName), 'info');
            loadShoppingCount();
        }
    } catch (err) {
        showToast(t('shopping.remove_error'), 'error');
    }
}

function openScanForItem(idx) {
    loadShoppingList._lastUserInteraction = Date.now(); // user is actively using the list
    const item = shoppingItems[idx];
    if (!item) return;
    _spesaScanTarget = { name: item.name, rawName: item.rawName || '', idx };
    showPage('scan');
    showToast(t('shopping.scan_toast').replace('{name}', item.name), 'info');
}

async function confirmShoppingItemFound() {
    if (!_spesaScanTarget) return;
    const { name, rawName } = _spesaScanTarget;
    _spesaScanTarget = null;
    document.getElementById('shopping-scan-target-banner').style.display = 'none';
    try {
        const r = await api('shopping_remove', {}, 'POST', { name, rawName, listUUID: shoppingListUUID });
        if (r.success) {
            _markBringPurchased([name]); // prevent background sync from re-adding before barcode scan
            const idx = shoppingItems.findIndex(i => i.name.toLowerCase() === name.toLowerCase());
            if (idx >= 0) shoppingItems.splice(idx, 1);
            showToast(t('shopping.item_removed').replace('{name}', name), 'success');
            logOperation('bring_found', { name });
            loadShoppingCount();
        }
    } catch (e) { console.error('confirmShoppingItemFound', e); }
    showPage('shopping');
}

// ===== AUTO-ADD CRITICAL ITEMS TO BRING! =====

/** Build a Bring specification string — urgency only (list items stay generic, no brand). */
function _urgencyToSpec(urgency) {
    const urgencyLabels = {
        critical: t('shopping.urgency_spec_critical'),
        high:     t('shopping.urgency_spec_high'),
        medium:   t('shopping.urgency_spec_medium'),
        low:      t('shopping.urgency_spec_low'),
    };
    return urgencyLabels[urgency] || '';
}

/** Generic label for a shopping-list row (never a specific product or brand). */
function _shoppingListGenericTitle(item, smartData) {
    if (smartData?.shopping_name) return smartData.shopping_name.trim();
    return _resolveShoppingDisplayName(item, smartData);
}

/**
 * Track items auto-added by autoAddCriticalItems so the cleanup
 * function only ever removes those, never manually-added ones.
 */
function _getAutoAddedBring() {
    const map = Object.assign({}, _autoAddedBringCache || {});
    const now = Date.now();
    let changed = false;
    for (const k of Object.keys(map)) {
        if (now - map[k] > 30 * 24 * 60 * 60 * 1000) { delete map[k]; changed = true; }
    }
    if (changed) {
        _autoAddedBringCache = map;
        _saveToServer('auto_added_bring', map);
    }
    return map;
}
function _markAutoAddedBring(names) {
    const map = _getAutoAddedBring();
    const now = Date.now();
    for (const n of names) map[n.toLowerCase()] = now;
    _autoAddedBringCache = map;
    _saveToServer('auto_added_bring', map);
}
function _unmarkAutoAddedBring(names) {
    const map = _getAutoAddedBring();
    for (const n of names) delete map[n.toLowerCase()];
    _autoAddedBringCache = map;
    _saveToServer('auto_added_bring', map);
}

// ===== BRING! PURCHASED BLOCKLIST (server-synced) =====
// When an item disappears from Bring (user bought it), we block auto-re-add for 4h.
const _BRING_PURCHASED_TTL = 72 * 60 * 60 * 1000; // 72 h — match server blocklist

function _getBringPurchasedBlocklist() {
    const map = Object.assign({}, _bringBlocklistCache || {});
    const now = Date.now();
    // Prune expired entries
    let changed = false;
    for (const key of Object.keys(map)) {
        if (now - map[key] > _BRING_PURCHASED_TTL) { delete map[key]; changed = true; }
    }
    if (changed) {
        _bringBlocklistCache = map;
        _saveToServer('bring_blocklist', map);
    }
    return map;
}

function _markBringPurchased(names) {
    const map = _getBringPurchasedBlocklist();
    const now = Date.now();
    for (const n of names) map[n.toLowerCase()] = now;
    _bringBlocklistCache = map;
    _saveToServer('bring_blocklist', map);
}

function _isBringPurchased(name, urgency) {
    const ttl = _BRING_PURCHASED_TTL;
    const map = _getBringPurchasedBlocklist();
    const now = Date.now();
    return Object.keys(map).some(k => {
        const matches = _nameTokens(name)[0] === _nameTokens(k)[0] || k === name.toLowerCase();
        if (!matches) return false;
        return (now - map[k]) < ttl;
    });
}

/** Drop smart-shopping rows the user already bought (blocklist from spesa). */
function _filterPurchasedSmartItems(items) {
    if (!Array.isArray(items) || !items.length) return items || [];
    return items.filter(item => {
        const names = [item.shopping_name, item.name].filter(Boolean);
        return !names.some(n => _isBringPurchased(n, item.urgency));
    });
}

async function autoAddCriticalItems() {
    // Time-based guard: run at most once every 5 minutes
    const lastRun = parseInt(localStorage.getItem('_autoAddedCriticalTs') || '0');
    if (Date.now() - lastRun < 5 * 60 * 1000) return;
    localStorage.setItem('_autoAddedCriticalTs', String(Date.now()));
    const toAdd = smartShoppingItems.filter(i => {
        if (i.on_bring) return false;
        const gName = i.shopping_name || i.name;
        if (_isBringPurchased(gName, i.urgency)) return false;
        return ['critical', 'high', 'medium', 'low'].includes(i.urgency);
    });
    if (toAdd.length === 0) return;
    const itemsToAdd = toAdd.map(i => ({
        name: i.shopping_name || i.name,
        specification: _urgencyToSpec(i.urgency),
    }));
    try {
        const result = await api('shopping_add', {}, 'POST', { items: itemsToAdd, listUUID: shoppingListUUID });
        if (result.success && result.added > 0) {
            // Track these as auto-added so cleanupObsoleteBringItems can safely remove them later
            _markAutoAddedBring(itemsToAdd.map(i => i.name));
            showToast(t('shopping.add_urgent_toast', { n: result.added }), 'success');
            logOperation('bring_auto_add', { added: itemsToAdd.map(i => i.name) });
            loadShoppingList();
        }
    } catch (e) { /* ignore */ }
}

/**
 * Manually force a full Bring! sync: clears the purchased blocklist and all
 * auto-add/cleanup timers, then re-adds all urgent items from scratch.
 * Triggered by the user pressing "Forza sincronizzazione Bring!".
 */
async function forceSyncBring() {
    const btn = document.getElementById('btn-force-sync');
    if (btn) { btn.disabled = true; btn.textContent = `⏳ ${t('shopping.syncing')}`; }
    // Clear auto-add/cleanup guards so the next run is unconditional.
    // Do NOT clear _userPinnedBring — items the user manually added must stay protected.
    _bringBlocklistCache = {}; _saveToServer('bring_blocklist', {});
    localStorage.removeItem('_autoAddedCriticalTs');
    localStorage.removeItem('_bringCleanupTs');
    _autoAddedBringCache = {}; _saveToServer('auto_added_bring', {});
    logOperation('force_sync_bring', {});
    // Reload everything from scratch
    await loadShoppingList();
    await syncShoppingPriceTotal(true);
    if (btn) { btn.disabled = false; btn.textContent = `🔄 ${t('shopping.force_sync')}`; }
    showToast(`🔄 ${t('shopping.sync_done')}`, 'success');
}

// ─────────────────────────────────────────────────────────────────
// SHOPPING LIST PRICE ESTIMATION
// ─────────────────────────────────────────────────────────────────
let _pricesFetching = false;
/** In-memory price cache: survives list re-renders in the same session */
let _cachedPrices = {};
/** Canonical shopping total — same figure on dashboard, Spesa page and screensaver */
let _canonicalShoppingTotal = null;
let _priceTotalFetchPromise = null;
/** Throttle server price fetches (ms) */
const _PRICE_FETCH_MIN_MS = 15 * 60 * 1000;
let _lastPriceFetchAt = 0;
let _lastPricePayloadHash = '';

function _formatShoppingTotalLabel(total, currency) {
    const sym = _currencySymbol(currency || getSettings().price_currency || 'EUR');
    return `ca. ${sym}${Number(total).toFixed(2)}`;
}

function _setCanonicalShoppingTotal(total, currency, meta = {}) {
    if (total == null || isNaN(Number(total))) return;
    const cur = currency || getSettings().price_currency || 'EUR';
    const label = dataTotalLabelOrFormat(meta.totalLabel, total, cur);
    _canonicalShoppingTotal = {
        total: Number(total),
        label,
        currency: cur,
        ts: Date.now(),
        itemCount: meta.itemCount ?? shoppingItems.length,
        pricedCount: meta.pricedCount ?? 0,
    };
    try {
        sessionStorage.setItem('_pricetotal', label);
        sessionStorage.setItem('_pricetotal_ts', String(_canonicalShoppingTotal.ts));
        sessionStorage.setItem('_pricetotal_items', String(_canonicalShoppingTotal.itemCount));
    } catch { /* quota */ }
    _applyShoppingTotalDisplay();
}

function dataTotalLabelOrFormat(totalLabel, total, currency) {
    if (totalLabel && typeof totalLabel === 'string') {
        return totalLabel.startsWith('ca.') ? totalLabel : `ca. ${totalLabel}`;
    }
    return _formatShoppingTotalLabel(total, currency);
}

function _loadCanonicalTotalFromSession() {
    if (_canonicalShoppingTotal) return;
    try {
        const label = sessionStorage.getItem('_pricetotal');
        const ts = parseInt(sessionStorage.getItem('_pricetotal_ts') || '0', 10);
        if (!label || !ts) return;
        const num = parseFloat(label.replace(/[^\d.,]/g, '').replace(',', '.'));
        _canonicalShoppingTotal = {
            total: isNaN(num) ? 0 : num,
            label,
            ts,
            itemCount: parseInt(sessionStorage.getItem('_pricetotal_items') || '0', 10),
            currency: getSettings().price_currency || 'EUR',
            pricedCount: 0,
        };
    } catch { /* ignore */ }
}

/** Push canonical total to dashboard, shopping bar and screensaver. */
function _applyShoppingTotalDisplay() {
    const s = getSettings();
    const dash = document.getElementById('stat-price-total');
    const shop = document.getElementById('price-total-value');
    if (!s.price_enabled || !_canonicalShoppingTotal) {
        if (dash) dash.style.display = 'none';
        return;
    }
    const { label } = _canonicalShoppingTotal;
    if (dash) { dash.textContent = label; dash.style.display = ''; }
    if (shop) shop.textContent = label;
    if (_screensaverActive) updateScreensaverShopping();
}

function _clearCanonicalShoppingTotal() {
    _canonicalShoppingTotal = null;
    try {
        sessionStorage.removeItem('_pricetotal');
        sessionStorage.removeItem('_pricetotal_ts');
        sessionStorage.removeItem('_pricetotal_items');
    } catch { /* quota */ }
    const dash = document.getElementById('stat-price-total');
    if (dash) dash.style.display = 'none';
    const shop = document.getElementById('price-total-value');
    if (shop) shop.textContent = '–';
    if (_screensaverActive) updateScreensaverShopping();
}

/**
 * Fetch server-side shopping total and refresh every UI surface.
 * Ensures shoppingItems is loaded first when needed.
 */
async function syncShoppingPriceTotal(forceRefresh = false) {
    const s = getSettings();
    if (!s.price_enabled || !_geminiAvailable) return;
    if (_priceTotalFetchPromise && !forceRefresh) return _priceTotalFetchPromise;

    const payloadHash = JSON.stringify(_buildPricePayload());
    const now = Date.now();
    if (!forceRefresh && payloadHash === _lastPricePayloadHash && _canonicalShoppingTotal
        && (now - _lastPriceFetchAt) < _PRICE_FETCH_MIN_MS) {
        _applyPriceBadgesFromCache();
        _applyShoppingTotalDisplay();
        return;
    }

    _priceTotalFetchPromise = (async () => {
        if (!shoppingItems.length) {
            try {
                const data = await api('shopping_list');
                if (data.success) shoppingItems = data.purchase || [];
            } catch { /* offline */ }
        }
        if (!shoppingItems.length) {
            _clearCanonicalShoppingTotal();
            return;
        }
        await fetchAllPrices(forceRefresh);
        _lastPriceFetchAt = Date.now();
        _lastPricePayloadHash = payloadHash;
    })().finally(() => { _priceTotalFetchPromise = null; });

    return _priceTotalFetchPromise;
}

/**
 * Build the items payload for the price API from the current shoppingItems array.
 * Tries to parse quantity/unit from the Bring! specification field.
 */
function _buildPricePayload() {
    return shoppingItems.map((item) => {
        const smart = _matchBringToSmart(item.name, smartShoppingItems);
        const eff = _effectiveSmartQty(smart);
        if (eff?.suggested_qty > 0) {
            const su = eff.suggested_unit || smart?.unit || 'conf';
            const defQty = parseFloat(smart?.default_qty) || 0;
            const pkgUnit = smart?.package_unit || '';
            return {
                name: item.name,
                quantity: eff.suggested_qty,
                unit: String(su).toLowerCase(),
                default_quantity: defQty,
                package_unit: pkgUnit,
            };
        }
        if (smart?.suggested_qty > 0) {
            const su = smart.suggested_unit || smart.unit || 'conf';
            const defQty = parseFloat(smart.default_qty) || 0;
            const pkgUnit = smart.package_unit || '';
            return {
                name: item.name,
                quantity: smart.suggested_qty,
                unit: String(su).toLowerCase(),
                default_quantity: defQty,
                package_unit: pkgUnit,
            };
        }
        if (smart) {
            const unit = smart.unit || 'conf';
            const defQty = parseFloat(smart.default_qty) || 0;
            const pkgUnit = smart.package_unit || '';
            if (unit === 'conf' && defQty > 0 && pkgUnit) {
                return {
                    name: item.name,
                    quantity: 1,
                    unit: 'conf',
                    default_quantity: defQty,
                    package_unit: pkgUnit,
                };
            }
            if (unit === 'pz') {
                const gramsPerPiece = defQty >= 20 ? defQty : 200;
                return {
                    name: item.name,
                    quantity: 2,
                    unit: 'pz',
                    default_quantity: gramsPerPiece,
                    package_unit: 'g',
                };
            }
            if ((unit === 'g' || unit === 'ml') && defQty > 0) {
                return {
                    name: item.name,
                    quantity: defQty,
                    unit,
                    default_quantity: defQty,
                    package_unit: pkgUnit,
                };
            }
        }
        return { name: item.name, quantity: 1, unit: 'conf', default_quantity: 0, package_unit: '' };
    });
}

/** Format inventory qty for human-readable pantry hints (conf → grams/ml when known). */
function _formatInvQtyDisplay(qty, unit, defaultQty = 0, packageUnit = '') {
    const q = parseFloat(qty) || 0;
    if (q <= 0) return '';
    const u = unit || 'pz';
    const pkg = (packageUnit || '').toLowerCase();
    const def = parseFloat(defaultQty) || 0;
    if (u === 'conf' && def > 0 && (pkg === 'g' || pkg === 'ml')) {
        return `${Math.round(q * def)} ${pkg}`;
    }
    if (u === 'conf' && def > 0) {
        return `${Math.round(q * 10) / 10} conf (${Math.round(q * def)} ${pkg || 'g'})`;
    }
    return `${Math.round(q * 10) / 10} ${u}`;
}

function _shoppingFamilyInventoryRows(item, smartData, invItems) {
    const shoppingName = (smartData?.shopping_name || item.name || '').toLowerCase();
    const firstTok = (_nameTokens(item.name)[0] || '').toLowerCase();
    return invItems.filter(i => {
        if (parseFloat(i.quantity) <= 0) return false;
        const iShopping = (i.shopping_name || '').toLowerCase();
        if (shoppingName && iShopping === shoppingName) {
            return _productMatchesShoppingFamily(i.name, shoppingName);
        }
        const iFirst = (_nameTokens(i.name || '')[0] || '').toLowerCase();
        return firstTok && iFirst === firstTok;
    });
}

/** Exclude mis-tagged products (e.g. passata stored under shopping_name "Pomodori"). */
function _inferShoppingGeneric(name) {
    const lower = (name || '').toLowerCase();
    const phrases = [
        ['passata di pomodoro', 'passata'], ['passata pomodoro', 'passata'],
        ['polpa di pomodoro', 'polpa di pomodoro'], ['polpa pomodoro', 'polpa di pomodoro'],
        ['sugo al pomodoro', 'sugo'], ['sugo di pomodoro', 'sugo'], ['salsa di pomodoro', 'sugo'],
        ['datterini pelati', 'pelati'], ['pomodori pelati', 'pelati'], ['pomodoro pelato', 'pelati'],
        ['pelati', 'pelati'], ['passata', 'passata'],
        ['tè al limone', 'tè al limone'], ['te al limone', 'tè al limone'],
        ['panna da cucina', 'panna da cucina'],
        ['pomodorini', 'pomodorini'],
        ['pomodoro', 'pomodori'], ['pomodori', 'pomodori'],
    ];
    for (const [phrase, gen] of phrases) {
        if (lower.includes(phrase)) return gen;
    }
    return (_nameTokens(name)[0] || lower).toLowerCase();
}

function _productMatchesShoppingFamily(productName, shoppingName) {
    const sn = (shoppingName || '').toLowerCase().trim();
    if (!sn) return false;
    const productGen = _inferShoppingGeneric(productName);
    const targetGen = _inferShoppingGeneric(shoppingName);
    if (productGen === targetGen) return true;
    const nameLower = (productName || '').toLowerCase().trim();
    return nameLower === sn || nameLower.startsWith(sn + ' ');
}

/** Shopping labels too vague to show alone (use product name / spec instead). */
const _VAGUE_SHOPPING_LABELS = new Set(['misto', 'bevande', 'ver', 'verdure', 'conserva', 'surgelati', 'condimenti']);

function _resolveShoppingDisplayName(item, smartData) {
    const raw = (item.name || '').trim();
    const rawLower = raw.toLowerCase();
    if (!_VAGUE_SHOPPING_LABELS.has(rawLower)) return raw;
    const specText = _specDisplayText(item.specification || '');
    if (specText) {
        const head = specText.split(' · ')[0].trim();
        if (head && head.toLowerCase() !== rawLower) return head;
    }
    if (smartData?.name && smartData.name.toLowerCase() !== rawLower) return smartData.name;
    return raw;
}

function _dedupeShoppingByGeneric(enriched) {
    const seen = new Map();
    const out = [];
    for (const e of enriched) {
        const key = (e.smartData?.shopping_name || e.item.name).toLowerCase();
        if (seen.has(key)) {
            const prev = seen.get(key);
            if (!prev.duplicateNames.includes(e.item.name)) prev.duplicateNames.push(e.item.name);
            continue;
        }
        e.duplicateNames = [];
        seen.set(key, e);
        out.push(e);
    }
    return out;
}

/**
 * Build HTML for a price badge column.
 * @param {Object} entry  — API response (price_per_unit, unit_label, estimated_total, source_note)
 * @param {string} sym    — currency symbol like "€"
 */
function _buildPriceBadgeHTML(entry, sym) {
    const hasTotal = entry.estimated_total != null;
    const isApprox = !hasTotal || (entry.source_note || '').startsWith('~');
    const mainLabel = (isApprox ? '~' : '')
        + (hasTotal
            ? `${sym}${entry.estimated_total.toFixed(2)}`
            : `${sym}${entry.price_per_unit.toFixed(2)}`);
    const unitLabel = entry.unit_label || '';
    const unitLine = unitLabel && entry.price_per_unit != null
        ? `${sym}${entry.price_per_unit.toFixed(2)}/${unitLabel}`
        : '';
    const title = entry.source_note || '';
    const approxClass = isApprox ? ' price-col-approx' : '';
    return `<div class="price-col-main${approxClass}" title="${escapeHtml(title)}">${mainLabel}</div>`
         + (unitLine ? `<div class="price-col-unit">${unitLine}</div>` : '');
}

/**
 * Apply price badges from in-memory cache (_cachedPrices) to the current DOM.
 * Returns { total, count } of items successfully applied.
 * Skips entries whose cached qty/unit no longer matches current suggested qty.
 */
function _applyPriceBadgesFromCache() {
    const s = getSettings();
    const sym = _currencySymbol(s.price_currency || 'EUR');
    let total = 0, count = 0;
    // Build a quick name→{quantity,unit} map from current smart data
    const qtyMap = {};
    for (const p of _buildPricePayload()) qtyMap[p.name] = p;
    shoppingItems.forEach((item, idx) => {
        const badge = document.getElementById(`price-badge-${idx}`);
        if (!badge) return;
        const entry = _cachedPrices[item.name];
        if (!entry) return;
        // Validate qty/unit — if smart data changed, treat as uncached
        const current = qtyMap[item.name];
        if (current && (entry._qty !== current.quantity || entry._unit !== current.unit)) return;
        badge.innerHTML = _buildPriceBadgeHTML(entry, sym);
        if (entry.estimated_total != null) { total += entry.estimated_total; count++; }
    });
    return { total, count };
}

/**
 * Apply price badges to shopping items in the DOM (legacy batch variant).
 * @param {Object} prices  — name → price entry from API
 * @param {string} currency — currency symbol fallback
 */
function _applyPriceBadges(prices, currency) {
    const sym = _currencySymbol(currency);
    shoppingItems.forEach((item, idx) => {
        const badge = document.getElementById(`price-badge-${idx}`);
        if (!badge) return;
        const entry = prices[item.name];
        if (!entry || entry.error) {
            badge.innerHTML = `<span class="price-col-error">–</span>`;
            return;
        }
        badge.innerHTML = _buildPriceBadgeHTML(entry, _currencySymbol(entry.currency || currency));
    });
}

function _currencySymbol(currency) {
    const map = {
        EUR: '€', USD: '$', GBP: '£', CHF: 'CHF ',
        CAD: 'CA$', AUD: 'A$', BRL: 'R$', JPY: '¥',
        SEK: 'kr', NOK: 'kr', DKK: 'kr', PLN: 'zł',
        CZK: 'Kč', HUF: 'Ft', RON: 'lei',
    };
    return map[currency?.toUpperCase()] || currency || '€';
}

/**
 * Fetch prices for all shopping list items, one by one (real-time updates).
 * Uses _cachedPrices for items already fetched this session (no API call needed).
 * @param {boolean} forceRefresh — bypass all caches, re-fetch everything
 */
async function fetchAllPrices(forceRefresh = false) {
    // Disable buttons immediately — even if we bail early, they stay disabled until
    // the active fetch finishes and re-enables them in its finally block.
    const fetchBtn = document.getElementById('btn-fetch-prices');
    const refreshBtn = document.getElementById('btn-price-refresh');
    if (_pricesFetching) {
        // Already running — don't stack calls, just leave the active fetch to finish
        return;
    }
    if (fetchBtn) fetchBtn.disabled = true;
    if (refreshBtn) { refreshBtn.disabled = true; refreshBtn.textContent = '⏳'; }
    if (!shoppingItems.length) {
        if (fetchBtn) fetchBtn.disabled = false;
        if (refreshBtn) { refreshBtn.disabled = false; refreshBtn.textContent = '🔄'; }
        _clearCanonicalShoppingTotal();
        return;
    }

    const s = getSettings();
    if (!s.price_enabled) {
        if (fetchBtn) fetchBtn.disabled = false;
        if (refreshBtn) { refreshBtn.disabled = false; refreshBtn.textContent = '🔄'; }
        return;
    }
    if (!_geminiAvailable) {
        // AI not configured — prices cannot be estimated without Gemini
        if (fetchBtn) fetchBtn.disabled = false;
        if (refreshBtn) { refreshBtn.disabled = false; refreshBtn.textContent = '🔄'; }
        return;
    }

    _pricesFetching = true;

    const priceBar  = document.getElementById('shopping-price-bar');
    const loadingBar = document.getElementById('price-loading-bar');
    const loadingInner = loadingBar ? loadingBar.querySelector('.price-loading-inner') : null;
    const totalEl   = document.getElementById('price-total-value');

    if (priceBar) priceBar.style.display = 'block';

    const sym = _currencySymbol(s.price_currency || 'EUR');

    // Show cached badges instantly while the server call is in flight
    _applyPriceBadgesFromCache();
    if (totalEl && forceRefresh) totalEl.textContent = t('shopping.price_loading');
    if (loadingBar) loadingBar.style.display = 'block';
    if (loadingInner) { loadingInner.style.transition = 'none'; loadingInner.style.width = '5%'; }

    const lang     = s.language    || 'it';
    const country  = s.price_country  || 'Italia';
    const currency = s.price_currency || 'EUR';

    // Send qty/unit aligned to plan-days (server uses these for price totals)
    const itemsPayload = _buildPricePayload();

    let serverTotal = null;
    try {
        const data = await api('get_all_shopping_prices', {}, 'POST', {
            items: itemsPayload,
            country, currency, lang,
            force_refresh: forceRefresh,
        });

        if (data && data.success) {
            const prices = data.prices || {};
            // Apply each item's result to badges and update in-memory cache
            shoppingItems.forEach((item, idx) => {
                const entry = prices[item.name];
                const badge = document.getElementById(`price-badge-${idx}`);
                if (entry && !entry.error && entry.price_per_unit != null) {
                    // Store with server-resolved qty/unit for correct cache validation
                    _cachedPrices[item.name] = {
                        ...entry,
                        _qty:  entry._resolved_qty  ?? 1,
                        _unit: entry._resolved_unit ?? 'conf',
                        _cached_at: Date.now(),
                    };
                    if (badge) badge.innerHTML = _buildPriceBadgeHTML(entry, sym);
                } else {
                    if (badge) badge.innerHTML = `<span class="price-col-error">–</span>`;
                }
            });

            // Server is the single source of truth for the total (all UI surfaces)
            serverTotal = data.total ?? null;
            const pricedCount = Object.values(prices).filter(e => e && !e.error && e.price_per_unit != null).length;
            if (serverTotal != null) {
                _setCanonicalShoppingTotal(serverTotal, currency, {
                    totalLabel: data.total_label,
                    itemCount: shoppingItems.length,
                    pricedCount,
                });
            }
        }
    } catch (_err) {
        if (_canonicalShoppingTotal && totalEl) {
            totalEl.textContent = _canonicalShoppingTotal.label;
        } else {
            const { total: ct, count: cc } = _applyPriceBadgesFromCache();
            if (cc > 0 && totalEl) totalEl.textContent = `ca. ${sym}${ct.toFixed(2)}`;
        }
    } finally {
        _pricesFetching = false;
        try {
            sessionStorage.setItem('_pricecache', JSON.stringify(_cachedPrices));
            sessionStorage.setItem('_pricecachets', String(Date.now()));
        } catch { /* quota */ }
        if (loadingBar) { if (loadingInner) loadingInner.style.width = '100%'; setTimeout(() => { loadingBar.style.display = 'none'; }, 300); }
        if (fetchBtn) fetchBtn.disabled = false;
        if (refreshBtn) { refreshBtn.disabled = false; refreshBtn.textContent = '🔄'; }
        _applyShoppingTotalDisplay();
    }
}

/**
 * One-time cleanup: remove items from Bring! that were auto-added but the algorithm no
 * longer considers relevant.  CONSERVATIVE: only removes items that match a known product
 * in our inventory with current_qty > 0 AND that no longer appear in smart predictions.
 * Items not matching any DB product are left untouched (likely manually added by user).
 */
async function cleanupObsoleteBringItems() {
    // Rate-limit: run at most once every 3 minutes
    const lastCleanup = parseInt(localStorage.getItem('_bringCleanupTs') || '0');
    if (Date.now() - lastCleanup < 3 * 60 * 1000) return;
    localStorage.setItem('_bringCleanupTs', String(Date.now()));
    if (!shoppingItems.length || !smartShoppingItems.length) return;

    // Detect items added automatically by the app (via autoAddCriticalItems).
    // We rely ONLY on the explicit _autoAddedBring registry (exact name match).
    // Do NOT use spec markers: autoSyncUrgencySpecs stamps urgency markers (⚡/🟠/🛒)
    // on ALL matched items regardless of who added them, making marker-based detection
    // unreliable and causing accidental removal of user-added items.
    const _autoAdded = _getAutoAddedBring();
    const isAppAdded = (item) => !!(_autoAdded[item.name.toLowerCase()]);

    // Build shopping_name family → total stock from smart_shopping (server already computed this)
    // If smart says a family is NOT needed, it already excluded them.
    const smartShoppingNames = new Set(
        smartShoppingItems.flatMap(si => [
            si.name?.toLowerCase(),
            si.shopping_name?.toLowerCase()
        ].filter(Boolean))
    );
    const smartShoppingFirstToks = new Map();
    for (const si of smartShoppingItems) {
        for (const tok of _nameTokens(si.name || '')) {
            if (!smartShoppingFirstToks.has(tok)) smartShoppingFirstToks.set(tok, si);
        }
        if (si.shopping_name) {
            for (const tok of _nameTokens(si.shopping_name)) {
                if (!smartShoppingFirstToks.has(tok)) smartShoppingFirstToks.set(tok, si);
            }
        }
    }

    // Load live inventory from server for stock check
    let invItems = [];
    try {
        const res = await api('inventory_list');
        invItems = res.inventory || [];
    } catch (e) { return; }

    // stock by any token (name) + by shopping_name
    const stockByTok = new Map();
    const stockBySName = new Map();
    for (const inv of invItems) {
        const qty = parseFloat(inv.quantity || 0);
        const expiry = inv.expiry_date;
        const expired = expiry && new Date(expiry) < new Date();
        if (qty <= 0 || expired) continue;
        for (const tok of _nameTokens(inv.name || '')) {
            stockByTok.set(tok, (stockByTok.get(tok) || 0) + qty);
        }
        const sn = (inv.shopping_name || '').toLowerCase().trim();
        if (sn) stockBySName.set(sn, (stockBySName.get(sn) || 0) + qty);
    }

    const toRemove = [];
    const _pinned = _pinnedBringCache || {};
    for (const item of shoppingItems) {
        const nameLower = item.name.toLowerCase();
        const itemToks = _nameTokens(item.name);
        const itemFirst = itemToks[0];

        // Never remove items explicitly pinned by the user
        if (_pinned[nameLower]) continue;

        // Only remove items the app put there
        if (!isAppAdded(item)) continue;

        // Find matching smart item
        const smartSi = itemFirst ? smartShoppingFirstToks.get(itemFirst) : undefined;

        // Smart still flags this as critical or high → keep it
        if (smartSi && (smartSi.urgency === 'critical' || smartSi.urgency === 'high')) continue;
        // Smart says medium AND low stock → keep it
        if (smartSi && smartSi.urgency === 'medium' && (smartSi.pct_left ?? 100) < 60) continue;
        // Smart has it with 0 qty → keep it (user genuinely needs it)
        if (smartSi && (smartSi.current_qty ?? 0) <= 0) continue;

        // If the item IS still in smart_shopping (but not urgent) AND has no local stock at all,
        // give benefit of the doubt and keep it.
        // If the item is NOT in smart_shopping at all → trust the server: it's covered → remove.
        if (smartSi) {
            // Still in smart_shopping (low urgency): verify some stock exists before removing
            const hasStock = itemToks.some(tok => (stockByTok.get(tok) || 0) > 0)
                || (stockBySName.get(nameLower) || 0) > 0;
            if (!hasStock) continue;
        }
        // else: not in smart_shopping at all → server decided it's covered → safe to remove

        // All guards passed: app-added and not urgently needed → remove from Bring!
        toRemove.push(item);
    }

    if (toRemove.length === 0) return;

    let removed = 0;
    const removedNames = [];
    for (const item of toRemove) {
        try {
            const r = await api('shopping_remove', {}, 'POST', {
                name: item.name,
                rawName: item.rawName || '',
                listUUID: shoppingListUUID
            });
            if (r.success) { removed++; removedNames.push(item.name); }
        } catch (e) { /* ignore individual failures */ }
    }

    if (removed > 0) {
        _unmarkAutoAddedBring(removedNames);
        showToast(t('shopping.removed_sufficient', { removed }), 'info');
        logOperation('bring_cleanup', { removed: removedNames });
        loadShoppingList();
    }
}

/**
 * Log an app operation (not a food transaction) for auditing/debugging.
 * Stored in localStorage under '_opLog', capped at 200 entries.
 */
function logOperation(action, details) {
    try {
        const log = JSON.parse(localStorage.getItem('_opLog') || '[]');
        const now = Date.now();
        log.push({ ts: new Date(now).toISOString(), action, details });
        // Prune: keep only last 200 entries AND entries newer than 30 days
        const cutoff = now - 30 * 24 * 60 * 60 * 1000;
        const pruned = log.filter(e => new Date(e.ts).getTime() >= cutoff);
        const final = pruned.length > 200 ? pruned.slice(pruned.length - 200) : pruned;
        localStorage.setItem('_opLog', JSON.stringify(final));
    } catch (e) { /* ignore */ }
}

// Build a better search query from item name + specification
function buildSearchQuery(item) {
    // Only use the item name for search - specification confuses the search engine
    // The AI on the backend will use the specification to pick the right product
    return item.name;
}

// Parse weight/quantity from specification (e.g. "200g" -> 0.2 kg, "500 ml" -> 0.5, "2 pz" -> 2 units)
function parseQtyFromSpec(spec) {
    if (!spec) return null;
    const s = spec.toLowerCase().trim();
    // Match weight/volume: 200g, 0.5kg, 500 g, 1,5 kg, 200 gr
    const m = s.match(/(\d+[.,]?\d*)\s*(g|gr|kg|ml|cl|l|lt)/i);
    if (m) {
        let val = parseFloat(m[1].replace(',', '.'));
        const unit = m[2].toLowerCase();
        if (unit === 'g' || unit === 'gr') return { kg: val / 1000, label: val + 'g', type: 'weight' };
        if (unit === 'kg') return { kg: val, label: (val * 1000) + 'g', type: 'weight' };
        if (unit === 'ml') return { kg: val / 1000, label: val + 'ml', type: 'weight' };
        if (unit === 'cl') return { kg: val / 100, label: val * 10 + 'ml', type: 'weight' };
        if (unit === 'l' || unit === 'lt') return { kg: val, label: (val * 1000) + 'ml', type: 'weight' };
    }
    // Match unit count: 2 pz, 3 pezzi, 5, 2x, ~5 pz
    const pzMatch = s.match(/~?(\d+)\s*(pz|pezzi|x|$)/i);
    if (pzMatch) {
        const count = parseInt(pzMatch[1]);
        if (count > 0 && count <= 50) return { count, label: count + ' pz', type: 'units' };
    }
    return null;
}

// Estimate price when product is sold per-kg/per-L or per-unit and user wants a certain quantity
function estimateItemPrice(product, spec) {
    if (!product.priceUm) return null;
    const umStr = String(product.priceUm);
    const pm = umStr.match(/(\d+[.,]?\d*)/);
    if (!pm) return null;
    const pricePerUnit = parseFloat(pm[1].replace(',', '.'));
    if (!pricePerUnit || pricePerUnit <= 0) return null;
    
    const qty = parseQtyFromSpec(spec);
    if (!qty) return null;
    
    if (qty.type === 'weight') {
        const estimated = pricePerUnit * qty.kg;
        if (estimated <= 0 || estimated > 500) return null;
        return { estimated: Math.round(estimated * 100) / 100, qtyLabel: qty.label };
    } else if (qty.type === 'units') {
        // For unit items: estimate per-item cost from the product price
        // If product is per-kg and we want N pieces, estimate ~200-300g per piece
        const avgWeightPerPiece = 0.25; // ~250g per piece (fruit/veg average)
        const estimated = pricePerUnit * avgWeightPerPiece * qty.count;
        if (estimated <= 0 || estimated > 500) return null;
        return { estimated: Math.round(estimated * 100) / 100, qtyLabel: qty.label };
    }
    return null;
}

// ===== SMART SHOPPING =====
let smartShoppingItems = [];
let smartShoppingFilter = 'all';
let _smartShoppingLastFetch = 0;      // timestamp of last successful fetch
let _bgShoppingInterval = null; // kept for compatibility, cron handles refresh server-side

/** Update dashboard badge from already-cached data */
function _updateSmartUrgencyBadge() {
    const urgentEl = document.getElementById('stat-urgent');
    if (!urgentEl) return;
    const urgent = smartShoppingItems.filter(i => i.urgency === 'critical' || i.urgency === 'high').length;
    if (urgent > 0) {
        urgentEl.textContent = `⚠ ${urgent}`;
        urgentEl.style.display = '';
    } else {
        urgentEl.style.display = 'none';
    }
}

function _updateDashboardPriceTotal() {
    _loadCanonicalTotalFromSession();
    _applyShoppingTotalDisplay();
}

/**
 * Sync the on_bring flag for every smartShoppingItem against the current shoppingItems list.
 * The server cache can be up to 10 min old so on_bring may be stale — this corrects it
 * client-side using strict first-token matching: a Bring item matches a smart item only when
 * the first significant token of the Bring item's name equals the first significant token of
 * the smart item's name (or exact name match). This avoids false positives like
 * "Frutta" (fresh fruit on Bring) matching "Muesli Frutta Secca" (a different product).
 */
function _syncOnBringFlags() {
    for (const si of smartShoppingItems) {
        const siLower = si.name.toLowerCase();
        const siFirst = _nameTokens(si.name)[0];
        const siShoppingLower = (si.shopping_name || '').toLowerCase();
        const siShoppingFirst = si.shopping_name ? _nameTokens(si.shopping_name)[0] : null;
        si.on_bring = !!(
            shoppingItems.find(bi => bi.name.toLowerCase() === siLower) ||
            (siShoppingLower && shoppingItems.find(bi => bi.name.toLowerCase() === siShoppingLower)) ||
            (siFirst && shoppingItems.find(bi => _nameTokens(bi.name)[0] === siFirst)) ||
            (siShoppingFirst && shoppingItems.find(bi => _nameTokens(bi.name)[0] === siShoppingFirst))
        );
    }
}

function _renderSmartLastUpdate() {
    const el = document.getElementById('smart-last-update');
    if (!el || !_smartShoppingLastFetch) return;
    const d = new Date(_smartShoppingLastFetch);
    el.textContent = t('shopping.smart_last_update').replace('{time}', `${d.getHours().toString().padStart(2,'0')}:${d.getMinutes().toString().padStart(2,'0')}`);
}

function startBgShoppingRefresh() {
    // No-op: server-side cron handles refresh every 5 minutes.
}

function getShoppingPlanDaysDefault() {
    const now = new Date();
    const lastDay = new Date(now.getFullYear(), now.getMonth() + 1, 0).getDate();
    return Math.max(1, lastDay - now.getDate() + 1);
}

function getShoppingPlanDays() {
    const def = getShoppingPlanDaysDefault();
    if (_shoppingPlanDaysCache == null || _shoppingPlanDaysCache === '') return def;
    const d = parseInt(_shoppingPlanDaysCache, 10);
    return (isNaN(d) || d < 1) ? def : Math.min(31, d);
}

function isShoppingPlanDaysCustom() {
    return _shoppingPlanDaysCache != null && _shoppingPlanDaysCache !== ''
        && parseInt(_shoppingPlanDaysCache, 10) !== getShoppingPlanDaysDefault();
}

function setShoppingPlanDays(days, persist = true) {
    const d = Math.max(1, Math.min(31, parseInt(days, 10) || getShoppingPlanDaysDefault()));
    _shoppingPlanDaysCache = d;
    if (persist) _saveToServer('shopping_plan_days', d);
    _renderShoppingPlanDaysBar();
}

function resetShoppingPlanDays() {
    _shoppingPlanDaysCache = null;
    _saveToServer('shopping_plan_days', null);
    _renderShoppingPlanDaysBar();
    applyShoppingPlanDays();
}

async function applyShoppingPlanDays() {
    const input = document.getElementById('shopping-plan-days-input');
    if (input) {
        const v = parseInt(input.value, 10);
        if (v >= 1 && v <= 31) setShoppingPlanDays(v, true);
    }
    _cachedPrices = {};
    _lastPricePayloadHash = '';
    renderShoppingItems._lastSig = '';
    await loadSmartShopping(true);
    renderShoppingItems(true);
    syncShoppingPriceTotal(true);
}

const _debouncedApplyShoppingPlanDays = debounce(() => applyShoppingPlanDays(), 400);

function _previewShoppingPlanDaysBar() {
    const input = document.getElementById('shopping-plan-days-input');
    if (!input) return;
    const v = parseInt(input.value, 10);
    if (v < 1 || v > 31) return;
    const label = document.getElementById('shopping-plan-days-label');
    const def = getShoppingPlanDaysDefault();
    if (label) {
        label.innerHTML = t('shopping.plan_days_label').replace('{days}', `<strong>${v}</strong>`);
    }
    const hint = document.getElementById('shopping-plan-days-hint');
    if (hint) {
        hint.textContent = v === def
            ? t('shopping.plan_days_auto').replace('{days}', def)
            : t('shopping.plan_days_custom').replace('{days}', v);
    }
}
const _debouncedShoppingPlanDaysPreview = debounce(_previewShoppingPlanDaysBar, 300);

function _initShoppingPageInteractionTracking() {
    const page = document.getElementById('page-shopping');
    if (!page || page._shopInteractionInit) return;
    page._shopInteractionInit = true;
    const markActive = () => { loadShoppingList._lastUserInteraction = Date.now(); };
    page.addEventListener('touchstart', markActive, { passive: true });
    page.addEventListener('scroll', markActive, { passive: true, capture: true });
    const panel = document.getElementById('tab-panel-acquisto');
    if (panel) panel.addEventListener('scroll', markActive, { passive: true });
    window.addEventListener('scroll', () => {
        if (_currentPageId === 'shopping') markActive();
    }, { passive: true });
}

function _shoppingScrollEl() {
    const panel = document.getElementById('tab-panel-acquisto');
    if (panel && panel.scrollHeight > panel.clientHeight + 8) return panel;
    return document.documentElement;
}

function _shoppingScrollY() {
    const el = _shoppingScrollEl();
    if (el === document.documentElement) {
        return window.scrollY || document.documentElement.scrollTop || 0;
    }
    return el.scrollTop || 0;
}

function _restoreShoppingScroll(y) {
    const el = _shoppingScrollEl();
    if (el === document.documentElement) {
        window.scrollTo(0, y);
    } else {
        el.scrollTop = y;
    }
}

function _shoppingRenderSignature() {
    const days = getShoppingPlanDays();
    const itemsSig = shoppingItems.map(i => `${i.name}|${i.specification || ''}`).join('§');
    const smartSig = smartShoppingItems.map(i => {
        const eff = _effectiveSmartQty(i);
        return `${i.shopping_name || i.name}:${eff?.suggested_qty ?? ''}:${eff?.suggested_unit ?? ''}:${i.urgency}:${i.on_bring}`;
    }).join('§');
    const collapsed = JSON.stringify(_shoppingSectionsCollapsedGet());
    return `${days}::${itemsSig}::${smartSig}::${collapsed}::${!!getSettings().price_enabled}`;
}

function _shopRowVisualHtml(item, smartData) {
    const inv = _shoppingInventoryCache || [];
    const rows = inv.length ? _shoppingFamilyInventoryRows(item, smartData, inv) : [];
    const imageUrl = rows.find(r => r.image_url)?.image_url || smartData?.image_url || '';
    const cat = smartData?.category || rows[0]?.category || '';
    const catKey = mapToLocalCategory(cat, smartData?.shopping_name || item?.name || '');
    const icon = CATEGORY_ICONS[catKey] || '🛒';
    const bg = imageUrl
        ? `<div class="shop-row-visual-bg" style="background-image:url('${escapeHtml(imageUrl)}')"></div>`
        : `<div class="shop-row-visual-bg shop-row-visual-bg--cat cat-${catKey}"></div>`;
    const img = imageUrl
        ? `<img class="shop-row-visual-img" src="${escapeHtml(imageUrl)}" alt="" loading="lazy" onerror="this.style.display='none'">`
        : '';
    return `<div class="shop-row-visual" aria-hidden="true">${bg}${img}<span class="shop-row-visual-icon">${icon}</span></div>`;
}

function _shopRowVisualHtmlFromSmart(item) {
    const name = item.shopping_name || item.name;
    const catKey = mapToLocalCategory(item.category || '', name);
    const icon = CATEGORY_ICONS[catKey] || '🛒';
    const imageUrl = item.image_url || '';
    const bg = imageUrl
        ? `<div class="shop-row-visual-bg" style="background-image:url('${escapeHtml(imageUrl)}')"></div>`
        : `<div class="shop-row-visual-bg shop-row-visual-bg--cat cat-${catKey}"></div>`;
    const img = imageUrl
        ? `<img class="shop-row-visual-img" src="${escapeHtml(imageUrl)}" alt="" loading="lazy" onerror="this.style.display='none'">`
        : '';
    return `<div class="shop-row-visual" aria-hidden="true">${bg}${img}<span class="shop-row-visual-icon">${icon}</span></div>`;
}

function _renderShoppingPlanDaysBar() {
    const bar = document.getElementById('shopping-plan-days-bar');
    if (!bar) return;
    const days = getShoppingPlanDays();
    const def = getShoppingPlanDaysDefault();
    const input = document.getElementById('shopping-plan-days-input');
    if (input && document.activeElement !== input) input.value = days;
    const label = document.getElementById('shopping-plan-days-label');
    if (label) {
        label.innerHTML = t('shopping.plan_days_label').replace('{days}', `<strong>${days}</strong>`);
    }
    const hint = document.getElementById('shopping-plan-days-hint');
    if (hint) {
        hint.textContent = days === def
            ? t('shopping.plan_days_auto').replace('{days}', def)
            : t('shopping.plan_days_custom').replace('{days}', days);
    }
    const resetBtn = document.getElementById('shopping-plan-days-reset');
    if (resetBtn) resetBtn.style.display = isShoppingPlanDaysCustom() ? '' : 'none';
}

async function loadSmartShopping(forceRefresh = false) {
    try {
        const planDays = getShoppingPlanDays();
        const params = { plan_days: planDays };
        if (forceRefresh) params.force = 1;
        const data = await api('smart_shopping', params);
        if (data.success && data.items && data.items.length > 0) {
            const prevCriticalNames = new Set(
                smartShoppingItems.filter(i => i.urgency === 'critical').map(i => i.name)
            );
            smartShoppingItems = _filterPurchasedSmartItems(data.items);
            _offlineSmartCacheSet(smartShoppingItems, planDays);
            _smartShoppingLastFetch = Date.now();
            // NOTE: do NOT clear _cachedPrices here — qty validation (_qty/_unit metadata)
            // handles stale entries automatically item by item.
            // they run with fresh data on next shopping page load
            const newCriticalNames = new Set(data.items.filter(i => i.urgency === 'critical').map(i => i.name));
            const criticalChanged = [...prevCriticalNames].some(n => !newCriticalNames.has(n)) ||
                                    [...newCriticalNames].some(n => !prevCriticalNames.has(n));
            if (criticalChanged) {
                localStorage.removeItem('_autoAddedCriticalTs');
                localStorage.removeItem('_bringCleanupTs');
            }
            renderSmartShopping();
            _renderSmartLastUpdate();
            _updateSmartUrgencyBadge();
            document.getElementById('smart-shopping-empty').style.display = 'none';
            document.getElementById('smart-shopping-content').style.display = 'block';
            if (data.plan_days_default) _renderShoppingPlanDaysBar();
        } else {
            smartShoppingItems = [];
            _smartShoppingLastFetch = Date.now();
            document.getElementById('smart-shopping-empty').style.display = 'block';
            document.getElementById('smart-shopping-content').style.display = 'none';
        }
    } catch (e) {
        console.error('Smart shopping error:', e);
        const cached = _offlineSmartCacheGet();
        if (cached?.items?.length) {
            smartShoppingItems = _filterPurchasedSmartItems(cached.items);
            _syncOnBringFlags();
            renderShoppingItems();
        } else {
            smartShoppingItems = [];
        }
    }
    updateShoppingTabCounts();
    if (document.getElementById('page-shopping')?.classList.contains('active')) {
        const sig = smartShoppingItems.map(i => {
            const eff = _effectiveSmartQty(i);
            return `${i.shopping_name || i.name}:${eff?.suggested_qty ?? ''}:${eff?.suggested_unit ?? ''}:${getShoppingPlanDays()}`;
        }).join('|');
        if (sig !== loadSmartShopping._lastRenderSig) {
            loadSmartShopping._lastRenderSig = sig;
            renderShoppingItems();
        } else {
            _applyPriceBadgesFromCache();
            _applyShoppingTotalDisplay();
        }
    }
}

function filterSmart(filter) {
    smartShoppingFilter = filter;
    document.querySelectorAll('.smart-filter').forEach(b => b.classList.remove('active'));
    document.querySelector(`.smart-filter[data-filter="${filter}"]`)?.classList.add('active');
    renderSmartShopping();
}

function renderSmartShopping() {
    const container = document.getElementById('smart-items');
    const countEl = document.getElementById('smart-count');
    const actionsEl = document.getElementById('smart-actions');

    let items = smartShoppingItems;
    if (smartShoppingFilter !== 'all') {
        items = items.filter(i => i.urgency === smartShoppingFilter);
    }

    countEl.textContent = items.length;

    if (items.length === 0) {
        container.innerHTML = `<div class="empty-state" style="padding:16px"><p>${t('shopping.empty_category')}</p></div>`;
        actionsEl.style.display = 'none';
        return;
    }

    const urgencyConfig = {
        critical: { color: '#ef4444', bg: 'rgba(239,68,68,0.08)', icon: '🔴', label: t('shopping.urgency_critical') },
        high:     { color: '#f97316', bg: 'rgba(249,115,22,0.08)', icon: '🟠', label: t('shopping.urgency_high') },
        medium:   { color: '#eab308', bg: 'rgba(234,179,8,0.08)',  icon: '🟡', label: t('shopping.urgency_medium') },
        low:      { color: '#22c55e', bg: 'rgba(34,197,94,0.08)',  icon: '🟢', label: t('shopping.urgency_low') },
    };

    // Group by section
    const smartSectionMap = new Map();
    items.forEach(item => {
        const sec = getItemSection(item.name);
        if (!smartSectionMap.has(sec.key)) smartSectionMap.set(sec.key, { sec, items: [] });
        smartSectionMap.get(sec.key).items.push(item);
    });

    let smartHtml = '';
    for (const secDef of SHOPPING_SECTIONS) {
        const group = smartSectionMap.get(secDef.key);
        if (!group) continue;
        smartHtml += `<div class="shopping-section-divider"><span class="sec-icon">${secDef.icon}</span>${secDef.label}</div>`;
        for (const item of group.items) {
            smartHtml += renderSmartItem(item, items);
        }
    }
    container.innerHTML = smartHtml;

    // Show/hide add button based on checkable items
    const hasCheckable = items.some(i => !i.on_bring);
    actionsEl.style.display = hasCheckable ? 'block' : 'none';
}

function renderSmartItem(item) {
    const urgencyConfig = {
        critical: { color: '#ef4444', bg: 'rgba(239,68,68,0.08)', icon: '🔴', label: t('shopping.urgency_critical') },
        high:     { color: '#f97316', bg: 'rgba(249,115,22,0.08)', icon: '🟠', label: t('shopping.urgency_high') },
        medium:   { color: '#eab308', bg: 'rgba(234,179,8,0.08)',  icon: '🟡', label: t('shopping.urgency_medium') },
        low:      { color: '#22c55e', bg: 'rgba(34,197,94,0.08)',  icon: '🟢', label: t('shopping.urgency_low') },
    };
    const u = urgencyConfig[item.urgency] || urgencyConfig.low;
    const catIcon = CATEGORY_ICONS[mapToLocalCategory(item.category, item.name)] || '📦';
    const globalIdx = smartShoppingItems.indexOf(item);

    // Generic vs specific name logic
    const shoppingName = item.shopping_name || item.name;

    let nameLine = `<div class="smart-item-name">${escapeHtml(shoppingName)}</div>`;
    const specificLine = '';

        // Stock bar
        const pct = Math.min(100, Math.max(0, item.pct_left));
        const barColor = pct <= 15 ? '#ef4444' : pct <= 30 ? '#f97316' : pct <= 50 ? '#eab308' : '#22c55e';

        // Quantity display
        let qtyText = '';
        if (item.current_qty > 0) {
            qtyText = `${item.current_qty} ${item.unit}`;
            if (item.pct_left < 100) qtyText += ` (${pct}%)`;
        } else {
            qtyText = t('shopping.out_of_stock');
        }

        // Usage frequency badge — uses per month, not raw transaction count
        let freqBadge = '';
        const usesMonth = Math.round(parseFloat(item.uses_per_month) || 0);
        if (usesMonth >= 4) freqBadge = `<span class="smart-freq-badge freq-high">📈 ~${usesMonth}/mese</span>`;
        else if (usesMonth >= 2) freqBadge = `<span class="smart-freq-badge freq-med">📊 ~${usesMonth}/mese</span>`;
        else if (usesMonth >= 1) freqBadge = `<span class="smart-freq-badge freq-low">📉 ~${usesMonth}/mese</span>`;

        // Suggested purchase quantity badge
        let suggestBadge = '';
        const sqtyFormatted = _formatSuggestQty(item.suggested_qty, item.suggested_unit || item.unit);
        if (!item.on_bring && sqtyFormatted) {
            const approx = !!item.suggested_approx;
            const tKey = approx ? 'shopping.suggest_buy_approx' : 'shopping.suggest_buy';
            const tTip = approx ? 'shopping.suggest_buy_approx_tip' : 'shopping.suggest_buy_tip';
            const suggestLabel = t(tKey).replace('{qty} {unit}', sqtyFormatted);
            const suggestLabelFinal = suggestLabel.includes('{qty}')
                ? t(tKey).replace('{qty}', item.suggested_qty).replace('{unit}', item.suggested_unit || item.unit)
                : suggestLabel;
            const extraClass = approx ? ' freq-suggest-approx' : '';
            suggestBadge = `<span class="smart-freq-badge freq-suggest${extraClass}" title="${t(tTip)}">${suggestLabelFinal}</span>`;
        }

        // Days left prediction
        let predBadge = '';
        if (item.days_left <= 3 && item.days_left > 0 && item.current_qty > 0) {
            predBadge = `<span class="smart-pred-badge pred-urgent">${t('expiry.badge_days_left').replace('{n}', item.days_left)}</span>`;
        } else if (item.days_left <= 7 && item.days_left > 0 && item.current_qty > 0) {
            predBadge = `<span class="smart-pred-badge pred-soon">${t('expiry.badge_days_left').replace('{n}', item.days_left)}</span>`;
        }

        // Expiry badge
        let expiryBadge = '';
        if (item.days_to_expiry < 0 && item.current_qty > 0) {
            expiryBadge = `<span class="smart-pred-badge pred-urgent">${t('expiry.badge_expired_bare')}</span>`;
        } else if (item.days_to_expiry <= 3 && item.days_to_expiry >= 0 && item.current_qty > 0) {
            expiryBadge = `<span class="smart-pred-badge pred-urgent">${t('expiry.badge_expires_warn').replace('{n}', item.days_to_expiry)}</span>`;
        }

        return `
        <div class="smart-item" style="border-left: 3px solid ${u.color}; background: ${u.bg}">
            <div class="smart-item-top">
                ${!item.on_bring ? `<input type="checkbox" class="smart-check" data-idx="${globalIdx}">` : ''}
                <span class="smart-item-icon">${catIcon}</span>
                <div class="smart-item-info">
                    ${nameLine}
                    ${specificLine}
                    <div class="smart-item-reasons">${item.reasons.map(r => `<span>${escapeHtml(r)}</span>`).join(' · ')}</div>
                    <div class="smart-item-badges">
                        <span class="smart-urgency-badge" style="color:${u.color}">${u.icon} ${u.label}</span>
                        ${freqBadge}${predBadge}${expiryBadge}${suggestBadge}
                        ${item.is_opened ? `<span class="smart-freq-badge freq-low">${t('inventory.opened_badge')}</span>` : ''}
                        ${item.on_bring ? `<span class="smart-bring-badge">${t('shopping.bring_badge')}</span>` : ''}
                    </div>
                </div>
                <div class="smart-item-stock">
                    <span class="smart-qty">${qtyText}</span>
                    ${item.current_qty > 0 ? `<div class="smart-stock-bar"><div class="smart-stock-fill" style="width:${pct}%;background:${barColor}"></div></div>` : ''}
                </div>
            </div>
        </div>`;
}

async function migrateBringNames(btn) {
    const statusEl = document.getElementById('bring-migrate-status');
    if (btn) btn.disabled = true;
    if (statusEl) { statusEl.style.display = 'inline'; statusEl.textContent = '⏳ In corso…'; }
    try {
        const data = await api('bring_migrate_names', {}, 'POST', {});
        if (data.success) {
            const msg = t('shopping.migration_done', { migrated: data.migrated, skipped: data.skipped }) + (data.errors ? `, ${data.errors} errori` : '');
            if (statusEl) statusEl.textContent = msg;
            if (data.migrated > 0) {
                showToast(t('shopping.bring_names_migrated', { n: data.migrated }), 'success');
                loadShoppingList(); // refresh the shopping list view
            } else {
                showToast(t('shopping.names_already_updated'), 'info');
            }
        } else {
            if (statusEl) statusEl.textContent = '❌ ' + (data.error || t('error.unknown'));
        }
    } catch(e) {
        if (statusEl) statusEl.textContent = '❌ ' + t('scale.error_connect');
    }
    if (btn) btn.disabled = false;
}

async function addSmartToBring() {
    const checks = document.querySelectorAll('.smart-check:checked');
    if (checks.length === 0) {
        showToast(t('error.select_items'), 'info');
        return;
    }

    const itemsToAdd = [];
    checks.forEach(cb => {
        const idx = parseInt(cb.dataset.idx);
        const item = smartShoppingItems[idx];
        if (item) {
            itemsToAdd.push({
                name: item.shopping_name || item.name,
                specification: _buildSmartSpec(item),
            });
        }
    });

    showLoading(true);
    try {
        const result = await api('shopping_add', {}, 'POST', {
            items: itemsToAdd,
            listUUID: shoppingListUUID,
        });
        showLoading(false);
        if (result.success) {
            const msg = result.added > 0
                ? t('shopping.added_to_bring', { n: result.added }) + (result.skipped > 0 ? ` (${t('shopping.added_to_bring_skip', { n: result.skipped })})` : '')
                : t('shopping.all_on_bring');
            showToast(msg, result.added > 0 ? 'success' : 'info');
            // Mark all manually-added items as user-pinned so cleanupObsoleteBringItems never removes them
            if (result.added > 0) {
                const pinned = Object.assign({}, _pinnedBringCache || {});
                const now = Date.now();
                for (const it of itemsToAdd) pinned[it.name.toLowerCase()] = now;
                _pinnedBringCache = pinned;
                _saveToServer('pinned_bring', pinned);
            }
            // Reload to refresh badges
            loadShoppingList();
        } else {
            showToast(result.error || t('error.generic'), 'error');
        }
    } catch (e) {
        showLoading(false);
        showToast(t('error.connection'), 'error');
    }
}

// Load just the shopping count for dashboard stat card
async function loadShoppingCount() {
    const el = document.getElementById('stat-spesa');
    if (el) el.classList.add('stat-loading');
    try {
        const data = await api('shopping_list');
        if (el) {
            if (data.success && data.purchase) {
                el.textContent = data.purchase.length;
            } else {
                el.textContent = '-';
            }
            el.classList.remove('stat-loading');
        }
    } catch {
        if (el) {
            el.textContent = '-';
            el.classList.remove('stat-loading');
        }
    }
    // Smart urgency badge: always fetch fresh data from server (no browser-side gate)
    try {
        const smart = await api('smart_shopping');
        if (smart.success && smart.items) {
            smartShoppingItems = smart.items;
            _smartShoppingLastFetch = Date.now();
            _updateSmartUrgencyBadge();
        }
    } catch { /* ignore */ }
    syncShoppingPriceTotal(false);
}

/**
 * Sync local 'urgente' tag from Bring specification.
 * If a Bring item's specification contains 'urgente', ensure the local tag is set.
 * If a Bring item's specification is empty/cleared, remove the local urgente tag
 * UNLESS smart shopping considers it critical (to avoid losing urgency on stale specs).
 */
function _syncTagsFromBringSpec() {
    try {
        const tags = Object.assign({}, _shoppingTagsCache || {});
        let changed = false;
        for (const item of shoppingItems) {
            const key = item.name.toLowerCase();
            const spec = (item.specification || '').toLowerCase();
            const existing = (tags[key] || []).slice();
            const hasUrgente = existing.includes('urgente');
            const smartMatch = _matchBringToSmart(item.name, smartShoppingItems);
            const smartIsCritical = smartMatch && (smartMatch.urgency === 'critical' || smartMatch.urgency === 'high');
            if ((spec.includes('urgente') || spec.includes('presto') || smartIsCritical) && !hasUrgente) {
                existing.push('urgente');
                tags[key] = existing;
                changed = true;
            } else if (!spec.includes('urgente') && !spec.includes('presto') && !smartIsCritical && hasUrgente) {
                existing.splice(existing.indexOf('urgente'), 1);
                if (existing.length) tags[key] = existing;
                else delete tags[key];
                changed = true;
            }
        }
        if (changed) {
            _shoppingTagsCache = tags;
            _saveToServer('shopping_tags', tags);
        }
    } catch (e) { /* ignore */ }
}

/**
 * After smart shopping loads, push urgency specifications to Bring for all matched items.
 * This makes urgency visible in the native Bring app via the item specification field.
 * Only updates if the spec has changed (to avoid unnecessary API calls).
 */
/**
 * Format a suggested purchase quantity into a human-readable string.
 * - conf/pz: returned as-is ("2 conf", "3 pz")
 * - g ≥ 1000 → kg ("1.5 kg")
 * - ml ≥ 1000 → l ("2 l")
 * Returns null if qty is null/zero (badge should be hidden).
 */
function _formatSuggestQty(qty, unit) {
    if (!qty || qty <= 0) return null;
    if (unit === 'conf') return `${qty} conf`;
    if (unit === 'pz') return `${qty} pz`;
    if (unit === 'g' && qty >= 1000) {
        const kg = qty / 1000;
        return `${Number.isInteger(kg) ? kg : parseFloat(kg.toFixed(1))} kg`;
    }
    if (unit === 'ml' && qty >= 1000) {
        const l = qty / 1000;
        return `${Number.isInteger(l) ? l : parseFloat(l.toFixed(1))} l`;
    }
    return `${qty} ${unit}`;
}

/**
 * Build the full Bring! specification string for a matched smart item.
 * Combines urgency label + suggested quantity so both appear in the Bring app.
 * Returns empty string for low/medium urgency items with no useful extra info.
 */
function _buildSmartSpec(smartMatch) {
    return _urgencyToSpec(smartMatch.urgency);
}

/** True if Bring spec still contains product/brand text beyond urgency markers. */
function _specNeedsGenericCleanup(spec) {
    return !!_specDisplayText(spec || '').trim();
}

async function autoSyncUrgencySpecs() {
    if (!shoppingListUUID || !smartShoppingItems.length) return;
    const toUpdate = [];
    for (const item of shoppingItems) {
        const smartMatch = _matchBringToSmart(item.name, smartShoppingItems);
        if (!smartMatch) continue;
        const targetSpec    = _buildSmartSpec(smartMatch);
        const currentSpec   = (item.specification || '').trim();
        if (targetSpec.toLowerCase() === currentSpec.toLowerCase() && !_specNeedsGenericCleanup(currentSpec)) continue;
        const apiName = smartMatch.shopping_name || item.name;
        toUpdate.push({ name: apiName, specification: targetSpec, update_spec: true });
        item.specification = targetSpec;
    }
    if (toUpdate.length === 0) return;
    try {
        await api('shopping_add', {}, 'POST', { items: toUpdate, listUUID: shoppingListUUID });
    } catch (e) { /* ignore - sync is best-effort */ }
}

async function loadShoppingList() {
    const statusEl = document.getElementById('bring-status');
    const currentEl = document.getElementById('shopping-current');
    const suggestionsEl = document.getElementById('shopping-suggestions');

    // Track last user interaction timestamp to avoid disrupting active use
    if (!loadShoppingList._lastUserInteraction) loadShoppingList._lastUserInteraction = 0;

    // Background refresh: ALWAYS do a silent update — never show spinner or rebuild DOM
    const isBackgroundCall = loadShoppingList._bgCall === true;
    loadShoppingList._bgCall = false;
    if (isBackgroundCall) {
        try {
            const data = await api('shopping_list');
            if (data.success) {
                const newItems = data.purchase || [];
                const newNames = new Set(newItems.map(i => i.name.toLowerCase()));
                const prevNames = new Set((shoppingItems || []).map(i => i.name.toLowerCase()));
                const hasChanges = newItems.length !== shoppingItems.length ||
                    [...newNames].some(n => !prevNames.has(n)) ||
                    [...prevNames].some(n => !newNames.has(n));
                if (hasChanges) {
                    shoppingItems = newItems;
                    _offlineShoppingCacheSet({ items: shoppingItems, listUUID: shoppingListUUID });
                    for (const name of Object.keys(_cachedPrices)) {
                        if (!newNames.has(name.toLowerCase())) delete _cachedPrices[name];
                    }
                    _syncTagsFromBringSpec();
                    renderShoppingItems();
                    syncShoppingPriceTotal(false);
                } else {
                    loadShoppingCount();
                }
            }
        } catch(_e) {}
        return;
    }
    
    statusEl.style.display = 'block';
    statusEl.innerHTML = `<div class="bring-loading"><div class="loading-spinner"></div> ${t('shopping.bring_loading')}</div>`;
    currentEl.style.display = 'none';
    suggestionsEl.style.display = 'none';

    // ── Demo mode: show placeholder list, skip all Bring! API calls ──────────
    if (_demoMode) {
        statusEl.style.display = 'none';
        shoppingListUUID = 'demo-list';
        shoppingItems = [
            { name: 'Latte', specification: '🟠 presto · 1L', rawName: 'Latte' },
            { name: 'Pane', specification: '', rawName: 'Pane' },
            { name: 'Uova', specification: '⚡ urgente', rawName: 'Uova' },
            { name: 'Pasta', specification: '500g', rawName: 'Pasta' },
            { name: 'Pomodori', specification: '1kg', rawName: 'Pomodori' },
        ];
        renderShoppingItems();
        currentEl.style.display = 'block';
        loadSmartShopping().then(() => {
            _syncOnBringFlags();
            renderSmartShopping();
            updateShoppingTabCounts();
            renderShoppingItems();
        });
        return;
    }
    
    try {
        const data = await api('shopping_list');
        statusEl.style.display = 'none';
        
        if (!data.success) {
            statusEl.style.display = 'block';
            const isMissingCreds = data.error && data.error.toLowerCase().includes('credenziali bring');
            if (isMissingCreds) {
                statusEl.innerHTML = `<div class="bring-error">🔑 ${t('shopping.bring_not_configured') || 'Bring! non è configurato. Aggiungi email e password nelle <a href="#" onclick="showPage(\'settings\');return false;">impostazioni</a>.'}</div>`;
            } else {
                statusEl.innerHTML = `<div class="bring-error">⚠️ ${escapeHtml(data.error || t('error.bring_connection'))}</div>`;
            }
            return;
        }
        
        shoppingListUUID = data.listUUID;
        // Detect items removed from Bring since last load (= just purchased by user)
        const prevNames = new Set((shoppingItems || []).map(i => i.name.toLowerCase()));
        const newItems  = data.purchase || [];
        const newNames  = new Set(newItems.map(i => i.name.toLowerCase()));
        if (prevNames.size > 0) {
            const removedNames = [...prevNames].filter(n => !newNames.has(n));
            if (removedNames.length) _markBringPurchased(removedNames);
        }
        if (data.recently?.length) {
            _markBringPurchased(data.recently.map(i => i.name).filter(Boolean));
        }
        shoppingItems = newItems;
        _offlineShoppingCacheSet({ items: shoppingItems, listUUID: shoppingListUUID });
        // Evict removed items from price cache so stale prices don't reappear
        for (const name of Object.keys(_cachedPrices)) {
            if (!newNames.has(name.toLowerCase())) delete _cachedPrices[name];
        }
        
        // Sync urgente local tags from Bring specification (items marked urgent by us or manually)
        _syncTagsFromBringSpec();
        renderShoppingItems();
        currentEl.style.display = 'block';
        
        // Load smart shopping predictions, then re-render once with qty/urgency
        loadSmartShopping().then(() => {
            _syncOnBringFlags();
            _syncTagsFromBringSpec();
            autoSyncUrgencySpecs();
            renderSmartShopping();
            updateShoppingTabCounts();
            autoAddCriticalItems();
            cleanupObsoleteBringItems();
        });

    } catch (err) {
        console.error('Bring! error:', err);
        const cached = _offlineShoppingCacheGet();
        if (cached?.items?.length) {
            shoppingItems = cached.items;
            shoppingListUUID = cached.listUUID || shoppingListUUID || '';
            statusEl.style.display = 'none';
            renderShoppingItems();
            currentEl.style.display = 'block';
            showToast(t('shopping.offline_list'), 'info');
            const smartCached = _offlineSmartCacheGet();
            if (smartCached?.items?.length) {
                smartShoppingItems = _filterPurchasedSmartItems(smartCached.items);
                _syncOnBringFlags();
            }
            return;
        }
        statusEl.style.display = 'block';
        statusEl.innerHTML = `<div class="bring-error">${t('error.bring_connection')}</div>`;
    }
}

/** Return the spec text to show in the UI, stripping urgency markers (those are shown as badges). */
function _specDisplayText(spec) {
    if (!spec) return '';
    let s = spec;
    const lower = spec.toLowerCase();
    for (const prefix of ['⚡ urgente', '🟠 presto', '🟡 a breve', '🔵 previsione']) {
        if (lower.startsWith(prefix)) {
            s = spec.slice(prefix.length).replace(/^\s*[·\-]\s*/, '').trim();
            break;
        }
    }
    // Legacy: qty pushed to Bring before v1.7.51
    s = s.replace(/🛒\s*(Compra|Almeno|Buy|At least):\s*[^·]+/gi, '')
         .replace(/^\s*[·\-]\s*|\s*[·\-]\s*$/g, '').trim();
    return s;
}

/** Return the spec for price search, stripping urgency markers that would confuse the AI. */
function _cleanSpecForSearch(spec) {
    return _specDisplayText(spec);
}

const URGENCY_BORDER = {
    critical: '#ef4444',
    high: '#f97316',
    medium: '#eab308',
    low: '#22c55e',
};

function _updateShoppingFooter() {
    const footer = document.getElementById('shopping-footer');
    const statsEl = document.getElementById('shopping-footer-stats');
    if (!footer || !statsEl) return;
    const n = shoppingItems.length;
    if (n === 0) {
        footer.style.display = 'none';
        return;
    }
    let totalQty = 0;
    for (const item of shoppingItems) {
        const smart = _matchBringToSmart(item.name, smartShoppingItems);
        const eff = _effectiveSmartQty(smart);
        const sq = eff?.suggested_qty;
        totalQty += (sq > 0 ? sq : 1);
    }
    statsEl.textContent = t('shopping.footer_stats')
        .replace('{items}', n)
        .replace('{qty}', Math.round(totalQty * 10) / 10);
    footer.style.display = 'flex';
}

function showPriceDetail(idx) {
    const item = shoppingItems[idx];
    if (!item) return;
    const entry = _cachedPrices[item.name];
    if (!entry || entry.error) {
        showToast(t('shopping.price_not_available'), 'info');
        return;
    }
    const sym = _currencySymbol(entry.currency || getSettings().price_currency || 'EUR');
    const main = entry.estimated_total != null
        ? `${sym}${entry.estimated_total.toFixed(2)}`
        : `${sym}${(entry.price_per_unit || 0).toFixed(2)}`;
    const note = entry.source_note || t('shopping.price_ai_estimate');
    const cachedAt = entry._cached_at ? new Date(entry._cached_at).toLocaleString() : '';
    const ageLine = cachedAt ? t('shopping.price_cached_at').replace('{date}', cachedAt) : '';
    const body = `${main}\n\n${note}${ageLine ? '\n\n' + ageLine : ''}`;
    alert(body);
}

async function addSmartItemQuick(globalIdx) {
    const item = smartShoppingItems[globalIdx];
    if (!item || item.on_bring) return;
    loadShoppingList._lastUserInteraction = Date.now();
    const apiName = item.shopping_name || item.name;
    const spec = _urgencyToSpec(item.urgency);
    const panel = document.getElementById('tab-panel-acquisto');
    const scrollY = panel?.scrollTop || 0;

    try {
        const r = await api('shopping_add', {}, 'POST', {
            items: [{ name: apiName, specification: spec }],
            listUUID: shoppingListUUID,
        });
        if (r.success || r._offline) {
            item.on_bring = true;
            const exists = shoppingItems.some(i => i.name.toLowerCase() === apiName.toLowerCase());
            if (!exists) {
                shoppingItems.push({ name: apiName, specification: spec, rawName: apiName });
            }
            _offlineShoppingCacheSet({ items: shoppingItems, listUUID: shoppingListUUID });
            _syncOnBringFlags();
            renderShoppingItems();
            if (panel) panel.scrollTop = scrollY;
            loadShoppingCount();
            updateShoppingTabCounts();
            showToast(t('shopping.added_one_inline').replace('{name}', apiName), 'success');
        }
    } catch (e) {
        showToast(t('error.network'), 'error');
    }
}

function _shopRowQtyHtml(smartData) {
    const buyQty = _shoppingBuyQtyDisplay(smartData);
    if (!buyQty) return '';
    return `<span class="shop-row-qty">${escapeHtml(buyQty.label)}</span>`;
}

function _shopRowInnerHtml({ displayName, qtyHtml, priceCell, visualHtml }) {
    return `
    <div class="shop-row-swipe-zones">
        <div class="shop-row-zone shop-row-zone-home">${escapeHtml(t('shopping.swipe_home'))}</div>
        <div class="shop-row-zone shop-row-zone-scan">${escapeHtml(t('shopping.swipe_scan'))}</div>
    </div>
    <div class="shop-row-swipe-bg shop-row-swipe-bg-left">${escapeHtml(t('shopping.swipe_remove'))}</div>
    <div class="shop-row-content">
        <div class="shop-row-main">
            ${visualHtml || ''}
            <div class="shop-row-text">
                <div class="shop-row-title">
                    <span class="shop-row-title-name">${escapeHtml(displayName)}</span>
                    ${qtyHtml}
                </div>
            </div>
            ${priceCell || ''}
        </div>
        <div class="shop-row-swipe-hint">${escapeHtml(t('shopping.swipe_hint_two'))}</div>
    </div>`;
}

function _initShopRowSwipe(container) {
    if (!container) return;
    if (container._swipeHandlers) {
        container.removeEventListener('touchstart', container._swipeHandlers.start);
        container.removeEventListener('touchmove', container._swipeHandlers.move);
        container.removeEventListener('touchend', container._swipeHandlers.end);
        container.removeEventListener('touchcancel', container._swipeHandlers.end);
    }

    let startX = 0, startY = 0, activeRow = null, activeContent = null, dragging = false;

    const onStart = (e) => {
        loadShoppingList._lastUserInteraction = Date.now();
        const row = e.target.closest('.shop-row');
        if (!row) return;
        activeRow = row;
        activeContent = row.querySelector('.shop-row-content');
        if (!activeContent) return;
        const t0 = e.touches[0];
        startX = t0.clientX;
        startY = t0.clientY;
        dragging = true;
        row._swipeMoved = false;
        activeContent.style.transition = 'none';
    };

    const onMove = (e) => {
        if (!dragging || !activeContent || !activeRow) return;
        const t0 = e.touches[0];
        const dx = t0.clientX - startX;
        const dy = Math.abs(t0.clientY - startY);
        if (dy > 24 && Math.abs(dx) < 20) {
            dragging = false;
            activeContent.style.transform = '';
            return;
        }
        if (Math.abs(dx) > 8) {
            e.preventDefault();
            activeRow._swipeMoved = true;
        }
        const w = activeRow.clientWidth || 300;
        const half = w / 2;
        if (activeRow.classList.contains('shop-row-suggest')) {
            const max = half;
            const clamped = Math.max(0, Math.min(max, dx));
            activeContent.style.transform = `translateX(${clamped}px)`;
            activeRow.classList.toggle('swipe-right', clamped > max * 0.35);
        } else if (dx > 0) {
            const clamped = Math.min(w, dx);
            activeContent.style.transform = `translateX(${clamped}px)`;
            activeRow.classList.toggle('zone-home', clamped >= half * 0.28 && clamped < half * 0.88);
            activeRow.classList.toggle('zone-scan', clamped >= half * 0.88);
        } else {
            const clamped = Math.max(-100, dx);
            activeContent.style.transform = `translateX(${clamped}px)`;
            activeRow.classList.toggle('swipe-left', clamped < -40);
        }
    };

    const onEnd = (e) => {
        if (!dragging || !activeRow || !activeContent) return;
        dragging = false;
        activeContent.style.transition = 'transform 0.2s ease';
        const t0 = e.changedTouches[0];
        const dx = t0.clientX - startX;
        const dy = Math.abs(t0.clientY - startY);
        const w = activeRow.clientWidth || 300;
        const half = w / 2;
        activeContent.style.transform = '';
        activeRow.classList.remove('swipe-right', 'swipe-left', 'zone-home', 'zone-scan');

        if (dy > 50) { activeRow = null; activeContent = null; return; }

        if (activeRow.classList.contains('shop-row-suggest')) {
            const sidx = parseInt(activeRow.dataset.suggestIdx, 10);
            if (dx > half * 0.35 && !isNaN(sidx)) addSmartItemQuick(sidx);
        } else {
            const idx = parseInt(activeRow.dataset.idx, 10);
            if (!isNaN(idx)) {
                if (dx >= half * 0.88) markShoppingItemBought(idx);
                else if (dx >= half * 0.35) markShoppingItemPurchasedLater(idx);
                else if (dx <= -72) removeBringItem(idx);
            }
        }
        activeRow = null;
        activeContent = null;
    };

    container._swipeHandlers = { start: onStart, move: onMove, end: onEnd };
    container.addEventListener('touchstart', onStart, { passive: true });
    container.addEventListener('touchmove', onMove, { passive: false });
    container.addEventListener('touchend', onEnd, { passive: true });
    container.addEventListener('touchcancel', onEnd, { passive: true });

    // Suggest rows: tap = add (no swipe on main list needed for suggest)
    container.querySelectorAll('.shop-row-suggest').forEach(row => {
        row.addEventListener('click', (e) => {
            if (row._swipeMoved) { row._swipeMoved = false; return; }
            const sidx = parseInt(row.dataset.suggestIdx, 10);
            if (!isNaN(sidx)) addSmartItemQuick(sidx);
        });
    });
}

function _initShoppingPullRefresh() {
    const page = document.getElementById('page-shopping');
    if (!page || page._pullInit) return;
    page._pullInit = true;
    let startY = 0;
    let pulling = false;
    let lastPullRefresh = 0;
    page.addEventListener('touchstart', (e) => {
        if (_shoppingScrollY() <= 4) {
            startY = e.touches[0].clientY;
            pulling = true;
        }
    }, { passive: true });
    page.addEventListener('touchmove', (e) => {
        if (!pulling) return;
        if (_shoppingScrollY() > 8) { pulling = false; return; }
        const dy = e.touches[0].clientY - startY;
        if (dy > 140 && Date.now() - lastPullRefresh > 45000) {
            pulling = false;
            lastPullRefresh = Date.now();
            loadShoppingList._lastUserInteraction = Date.now();
            showToast(t('shopping.refreshing'), 'info');
            loadShoppingList();
            loadSmartShopping(true);
        }
    }, { passive: true });
    page.addEventListener('touchend', () => { pulling = false; }, { passive: true });
}

function _renderShoppingSectionHead(secKey, secDef, count, collapsed) {
    const chev = collapsed ? '▸' : '▾';
    return `<button type="button" class="shop-section-head" onclick="toggleShoppingSection('${secKey}')" aria-expanded="${!collapsed}">
        <span class="shop-section-label"><span class="sec-icon">${secDef.icon}</span>${escapeHtml(secDef.label)}</span>
        <span class="shop-section-meta"><span class="shop-section-cnt">${count}</span><span class="shop-section-chev">${chev}</span></span>
    </button>`;
}

function _renderShoppingSuggestionsInline() {
    const items = smartShoppingItems.filter(i => !i.on_bring);
    if (!items.length) return '';
    const secKey = '__suggest__';
    const collapsed = !!_shoppingSectionsCollapsedGet()[secKey];
    let html = _renderShoppingSectionHead(secKey, { icon: '💡', label: t('shopping.section_suggested') }, items.length, collapsed);
    if (collapsed) return html;
    html += '<div class="shop-section-body">';
    for (const item of items.slice(0, 25)) {
        const globalIdx = smartShoppingItems.indexOf(item);
        const name = item.shopping_name || item.name;
        const qtyHtml = _shopRowQtyHtml(item);
        const border = URGENCY_BORDER[item.urgency] || URGENCY_BORDER.low;
        const visualHtml = _shopRowVisualHtmlFromSmart(item);
        html += `<div class="shop-row shop-row-suggest" data-suggest-idx="${globalIdx}" style="border-left-color:${border}">
            <div class="shop-row-swipe-bg shop-row-swipe-bg-right">${escapeHtml(t('shopping.swipe_add'))}</div>
            <div class="shop-row-content">
                <div class="shop-row-main">
                    ${visualHtml}
                    <div class="shop-row-text">
                        <div class="shop-row-title">
                            <span class="shop-row-title-name">${escapeHtml(name)}</span>
                            ${qtyHtml}
                        </div>
                    </div>
                </div>
                <div class="shop-row-swipe-hint">${escapeHtml(t('shopping.swipe_add_hint'))}</div>
            </div>
        </div>`;
    }
    if (items.length > 25) {
        html += `<div class="shop-row-more">${t('shopping.more_suggestions').replace('{n}', items.length - 25)}</div>`;
    }
    html += '</div>';
    return html;
}

async function renderShoppingItems(force = false) {
    const container = document.getElementById('shopping-items');
    const countEl = document.getElementById('shopping-count');
    const sig = _shoppingRenderSignature();
    if (!force && sig === renderShoppingItems._lastSig) {
        _applyPriceBadgesFromCache();
        _applyShoppingTotalDisplay();
        return;
    }

    const scrollY = _shoppingScrollY();
    const collapsedMap = _shoppingSectionsCollapsedGet();
    const priceEnabled = getSettings().price_enabled;

    if (shoppingItems.length === 0) {
        countEl.textContent = 0;
        const tabCountEmpty = document.getElementById('tab-count-acquisto');
        if (tabCountEmpty) tabCountEmpty.textContent = 0;
        container.innerHTML = `<div class="empty-state" style="padding:20px"><div class="empty-state-icon">✅</div><p>${t('shopping.empty')}</p></div>`;
        container.innerHTML += _renderShoppingSuggestionsInline();
        _renderShoppingPlanDaysBar();
        _updateShoppingFooter();
        _initShopRowSwipe(container);
        _initShoppingPullRefresh();
        return;
    }

    const enrichedRaw = shoppingItems.map((item, idx) => {
        const smartData = _matchBringToSmart(item.name, smartShoppingItems);
        let urgency = smartData?.urgency || null;
        if (!urgency && item.specification) {
            const spec = item.specification.toLowerCase();
            if (spec.includes('urgente')) urgency = 'critical';
            else if (spec.includes('presto')) urgency = 'high';
        }
        const sec = getItemSection(item.name);
        return { item, idx, smartData, urgency, sec };
    });
    const enriched = _dedupeShoppingByGeneric(enrichedRaw);
    const pantryRows = enriched;

    countEl.textContent = enriched.length;
    const tabCount = document.getElementById('tab-count-acquisto');
    if (tabCount) tabCount.textContent = enriched.length;

    const sectionMap = new Map();
    for (const e of enriched) {
        const key = e.sec.key;
        if (!sectionMap.has(key)) sectionMap.set(key, { sec: e.sec, items: [] });
        sectionMap.get(key).items.push(e);
    }

    for (const [, group] of sectionMap) {
        group.items.sort((a, b) => {
            const wa = URGENCY_WEIGHT[a.urgency] || 0;
            const wb = URGENCY_WEIGHT[b.urgency] || 0;
            if (wb !== wa) return wb - wa;
            return (b.smartData?.use_count || 0) - (a.smartData?.use_count || 0);
        });
    }

    let html = '';
    for (const secDef of SHOPPING_SECTIONS) {
        const group = sectionMap.get(secDef.key);
        if (!group) continue;
        const collapsed = !!collapsedMap[secDef.key];
        html += _renderShoppingSectionHead(secDef.key, secDef, group.items.length, collapsed);
        if (collapsed) continue;
        html += '<div class="shop-section-body">';

        for (const { item, idx, smartData, urgency } of group.items) {
            const displayName = _shoppingListGenericTitle(item, smartData);
            const qtyHtml = _shopRowQtyHtml(smartData);
            const visualHtml = _shopRowVisualHtml(item, smartData);
            const borderColor = urgency ? (URGENCY_BORDER[urgency] || 'transparent') : 'transparent';
            const priceCell = priceEnabled
                ? `<div class="shop-row-price" id="price-badge-${idx}" onclick="event.stopPropagation();showPriceDetail(${idx})"><span class="price-col-loading">…</span></div>`
                : '';

            html += `
            <div class="shop-row" id="shop-item-${idx}" data-idx="${idx}" style="border-left-color:${borderColor}">
                ${_shopRowInnerHtml({ displayName, qtyHtml, priceCell, visualHtml })}
            </div>`;
        }
        html += '</div>';
    }

    html += _renderShoppingSuggestionsInline();
    container.innerHTML = html;
    renderShoppingItems._lastSig = sig;
    _renderShoppingPlanDaysBar();
    _updateShoppingFooter();
    _initShopRowSwipe(container);
    _initShoppingPullRefresh();
    requestAnimationFrame(() => {
        _restoreShoppingScroll(scrollY);
    });

    _getShoppingInventoryCache().then(invItems => {
        for (const { item, idx, smartData, urgency } of pantryRows) {
            const matches = _shoppingFamilyInventoryRows(item, smartData, invItems);
            if (matches.length === 0) continue;
            if (urgency === 'critical' || urgency === 'high') continue;
            const parts = [];
            const byKey = {};
            for (const m of matches) {
                const label = _formatInvQtyDisplay(m.quantity, m.unit, m.default_quantity, m.package_unit);
                if (!label) continue;
                byKey[label] = (byKey[label] || 0) + 1;
            }
            for (const [label, n] of Object.entries(byKey)) {
                parts.push(n > 1 ? `${label} (${n})` : label);
            }
            if (!parts.length) continue;
            const itemEl = document.getElementById(`shop-item-${idx}`);
            const textEl = itemEl?.querySelector('.shop-row-text');
            if (!textEl || textEl.querySelector('.shopping-pantry-hint')) continue;
            const hintEl = document.createElement('div');
            hintEl.className = 'shop-row-sub shopping-pantry-hint';
            hintEl.textContent = t('shopping.pantry_hint').replace('{qty}', parts.join(', '));
            textEl.appendChild(hintEl);
        }
    });

    const s2 = getSettings();
    if (s2.price_enabled && shoppingItems.length > 0) {
        document.getElementById('shopping-price-bar').style.display = 'block';
        document.getElementById('btn-fetch-prices').style.display = 'inline-flex';
        _applyPriceBadgesFromCache();
        _applyShoppingTotalDisplay();
        syncShoppingPriceTotal(false);
    } else {
        document.getElementById('shopping-price-bar').style.display = 'none';
        document.getElementById('btn-fetch-prices').style.display = 'none';
    }
}

function toggleShoppingTagMenu(btn) {
    loadShoppingList._lastUserInteraction = Date.now(); // user is actively using the list
    const container = btn.closest('.shopping-item-body').querySelector('.shopping-tag-menu-container');
    if (!container) return;
    const isOpen = container.style.display !== 'none';
    // Close all other menus first
    document.querySelectorAll('.shopping-tag-menu-container').forEach(c => c.style.display = 'none');
    container.style.display = isOpen ? 'none' : 'block';
}

async function removeBringItem(idx) {
    loadShoppingList._lastUserInteraction = Date.now();
    const item = shoppingItems[idx];
    if (!item) return;
    const panel = document.getElementById('tab-panel-acquisto');
    const scrollY = panel?.scrollTop || 0;
    try {
        const data = await api('shopping_remove', {}, 'POST', {
            name: item.name,
            rawName: item.rawName || '',
            listUUID: shoppingListUUID,
        });
        if (data.success || data._offline) {
            _markBringPurchased([item.name]);
            shoppingItems.splice(idx, 1);
            _offlineShoppingCacheSet({ items: shoppingItems, listUUID: shoppingListUUID });
            renderShoppingItems();
            if (panel) panel.scrollTop = scrollY;
            showToast(t('toast.removed_from_list_short'), 'success');
            logOperation('bring_manual_remove', { name: item.name });
            loadShoppingCount();
        }
    } catch (err) {
        showToast(t('shopping.remove_error'), 'error');
    }
}

async function generateSuggestions() {
    const btn = document.getElementById('btn-suggest');
    const suggestionsEl = document.getElementById('shopping-suggestions');
    
    btn.disabled = true;
    btn.innerHTML = `<div class="loading-spinner" style="display:inline-block;width:18px;height:18px;margin-right:8px;vertical-align:middle"></div> ${t('shopping.suggest_loading')}`;
    suggestionsEl.style.display = 'none';
    
    try {
        const data = await api('shopping_suggest', {}, 'POST', {});
        
        btn.disabled = false;
        btn.innerHTML = `🤖 ${t('shopping.suggest_btn').replace('🤖 ', '')}`;
        
        if (!data.success) {
            showToast(data.error || t('shopping.suggest_error'), 'error');
            return;
        }
        
        suggestionItems = (data.suggestions || []).map(s => ({ ...s, selected: true }))
            // Exclude items already present in the current Bring shopping list
            .filter(s => {
                const sFirst = _nameTokens(s.name)[0];
                const sLower = s.name.toLowerCase();
                return !shoppingItems.some(bi => {
                    const bLower = bi.name.toLowerCase();
                    const bFirst = _nameTokens(bi.name)[0];
                    return bLower === sLower || (sFirst && bFirst && bFirst === sFirst);
                });
            });
        
        // Show seasonal tip
        const tipEl = document.getElementById('seasonal-tip');
        if (data.seasonal_tip) {
            tipEl.style.display = 'block';
            tipEl.innerHTML = `🌿 <em>${escapeHtml(data.seasonal_tip)}</em>`;
        } else {
            tipEl.style.display = 'none';
        }
        
        renderSuggestions();
        suggestionsEl.style.display = 'block';
        document.getElementById('suggestion-actions').style.display = 'block';
        
        // Scroll to suggestions
        suggestionsEl.scrollIntoView({ behavior: 'smooth', block: 'start' });

        // AI enrich suggestions in background (best-effort)
        if (_geminiAvailable && suggestionItems.length > 0) {
            _enrichSuggestionsWithAI();
        }
        
    } catch (err) {
        btn.disabled = false;
        btn.innerHTML = `🤖 ${t('shopping.suggest_btn').replace('🤖 ', '')}`;
        console.error('Suggestion error:', err);
        showToast(t('error.connection'), 'error');
    }
}

function renderSuggestions() {
    const container = document.getElementById('suggestion-items');
    
    const priorityOrder = { 'alta': 0, 'media': 1, 'bassa': 2 };
    const sorted = [...suggestionItems].sort((a, b) => (priorityOrder[a.priority] || 2) - (priorityOrder[b.priority] || 2));
    
    container.innerHTML = sorted.map((item, idx) => {
        const catIcon = CATEGORY_ICONS[item.category] || '🛒';
        const isAi = item.source === 'ai';
        const priorityBadge = {
            'alta': `<span class="priority-badge priority-high">${t('shopping.priority_high')}</span>`,
            'media': `<span class="priority-badge priority-med">${t('shopping.priority_medium')}</span>`,
            'bassa': `<span class="priority-badge priority-low">${t('shopping.priority_low')}</span>`,
        }[item.priority] || '';
        const aiBadge = isAi ? `<span class="priority-badge priority-ai">🤖 AI</span>` : '';
        
        return `
        <div class="suggestion-item ${item.selected ? 'selected' : ''}" onclick="toggleSuggestion(${idx})" data-suggestion-name="${escapeHtml(item.name)}">
            <div class="suggestion-check">${item.selected ? '☑️' : '⬜'}</div>
            <span class="shopping-item-icon">${catIcon}</span>
            <div class="suggestion-info">
                <div class="suggestion-name">${escapeHtml(item.name)}${item.specification ? ` <small>(${escapeHtml(item.specification)})</small>` : ''} ${priorityBadge}${aiBadge}</div>
                <div class="suggestion-reason">${escapeHtml(item.reason)}</div>
            </div>
        </div>`;
    }).join('');
    
    updateSuggestionActionBtn();
}

async function _enrichSuggestionsWithAI() {
    try {
        const items = suggestionItems.map(s => ({
            name:     s.name,
            reason:   s.reason   || '',
            category: s.category || '',
            priority: s.priority || 'media',
        }));
        const data = await api('gemini_shopping_enrich', {}, 'POST', { items, lang: _currentLang });
        if (!data.success || !Array.isArray(data.items)) return;

        // For each item that has a tip, find its DOM element and append the tip
        data.items.forEach(enriched => {
            if (!enriched.tip) return;
            const nameAttr = enriched.name.replace(/"/g, '&quot;');
            const el = document.querySelector(`#suggestion-items [data-suggestion-name="${nameAttr}"]`);
            if (!el) return;
            const infoDiv = el.querySelector('.suggestion-info');
            if (!infoDiv) return;
            // Avoid duplicate tips
            if (infoDiv.querySelector('.suggestion-ai-tip')) return;
            const tipEl = document.createElement('div');
            tipEl.className = 'suggestion-ai-tip';
            tipEl.innerHTML = `💡 <em>${escapeHtml(enriched.tip)}</em>`;
            infoDiv.appendChild(tipEl);
        });
    } catch (e) {
        // best-effort — silently ignore
    }
}

function toggleSuggestion(idx) {
    const priorityOrder = { 'alta': 0, 'media': 1, 'bassa': 2 };
    const sorted = [...suggestionItems].sort((a, b) => (priorityOrder[a.priority] || 2) - (priorityOrder[b.priority] || 2));
    const actualItem = sorted[idx];
    // Find in original array
    const origIdx = suggestionItems.indexOf(actualItem);
    if (origIdx >= 0) {
        suggestionItems[origIdx].selected = !suggestionItems[origIdx].selected;
    }
    renderSuggestions();
}

function updateSuggestionActionBtn() {
    const selected = suggestionItems.filter(s => s.selected);
    const btn = document.querySelector('#suggestion-actions .btn-success');
    if (btn) {
        const nItems = selected.length;
        btn.textContent = `✅ ${nItems === 1 ? t('shopping.bring_add_one') : t('shopping.bring_add_many').replace('{n}', nItems)}`;
        btn.disabled = nItems === 0;
    }
}

async function addSelectedSuggestions() {
    const selected = suggestionItems.filter(s => s.selected);
    if (selected.length === 0) {
        showToast(t('error.select_items'), 'error');
        return;
    }
    
    const btn = document.querySelector('#suggestion-actions .btn-success');
    btn.disabled = true;
    btn.innerHTML = `<div class="loading-spinner" style="display:inline-block;width:18px;height:18px;margin-right:8px;vertical-align:middle"></div> ${t('shopping.bring_adding')}`;
    
    try {
        const items = selected.map(s => {
            return { name: s.name };
        });
        
        const data = await api('shopping_add', {}, 'POST', { items, listUUID: shoppingListUUID });
        
        if (data.success) {
            let msg = data.added === 1 ? t('shopping.bring_added_one') : t('shopping.bring_added_many').replace('{n}', data.added);
            if (data.skipped > 0) msg += ` ${t('shopping.bring_skipped').replace('{n}', data.skipped)}`;
            showToast(msg, 'success');
            // Refresh list
            await loadShoppingList();
            // Update dashboard shopping count
            loadShoppingCount();
            // Clear suggestions
            document.getElementById('shopping-suggestions').style.display = 'none';
            suggestionItems = [];
        } else {
            showToast(data.error || t('error.generic'), 'error');
        }
    } catch (err) {
        showToast(t('error.connection'), 'error');
    }
    
    btn.disabled = false;
    btn.innerHTML = `✅ ${t('shopping.bring_add_selected')}`;
}

// ===== UTILITY FUNCTIONS =====

// ===== SCAN EXPIRY DATE WITH CAMERA + GEMINI AI =====
let expiryStream = null;

async function scanExpiryWithAI() {
    if (!_requireGemini()) return;
    // Create modal for camera capture
    document.getElementById('modal-content').innerHTML = `
        <div class="modal-header">
            <h3>${t('add.scan_expiry_title')}</h3>
            <button class="modal-close" onclick="closeExpiryScanner()">✕</button>
        </div>
        <div class="expiry-scanner">
            <div id="expiry-cam-container" style="height:180px;overflow:hidden;border-radius:10px;position:relative">
                <video id="expiry-video" autoplay playsinline style="width:100%;position:absolute;top:50%;left:50%;transform:translate(-50%,-50%) scale(2);transform-origin:center center"></video>
                <canvas id="expiry-canvas" style="display:none"></canvas>
                <div style="position:absolute;inset:0;border:2px dashed rgba(255,255,255,0.5);border-radius:10px;pointer-events:none"></div>
            </div>
            <div id="expiry-preview-container" style="display:none;height:180px;overflow:hidden;border-radius:10px">
                <img id="expiry-preview-img" src="" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:10px">
            </div>
            <p class="form-hint" style="text-align:center;margin:6px 0;font-size:0.8rem">${t('scanner.expiry_label_hint')}</p>
            <div id="expiry-scan-status" style="display:none;text-align:center;padding:8px">
                <div class="loading-spinner" style="margin:0 auto 6px"></div>
                <p>${t('scanner.ai_analyzing')}</p>
            </div>
            <div class="expiry-scanner-actions">
                <button class="btn btn-large btn-accent full-width" id="expiry-capture-btn" onclick="captureExpiry()">${t('scanner.capture_photo_btn')}</button>
                <button class="btn btn-large btn-secondary full-width" id="expiry-retake-btn" onclick="retakeExpiry()" style="display:none">${t('scanner.retake_btn')}</button>
            </div>
        </div>
    `;
    document.getElementById('modal-overlay').style.display = 'flex';
    
    // Start camera
    try {
        expiryStream = await navigator.mediaDevices.getUserMedia(getCameraConstraints());
        const video = document.getElementById('expiry-video');
        video.srcObject = expiryStream;
        await video.play();
    } catch (err) {
        console.error('Expiry camera error:', err);
        document.getElementById('expiry-cam-container').innerHTML = `
            <p style="color:var(--danger);text-align:center;padding:20px">⚠️ Impossibile accedere alla fotocamera</p>
        `;
    }
}

function closeExpiryScanner() {
    if (expiryStream) {
        expiryStream.getTracks().forEach(t => t.stop());
        expiryStream = null;
    }
    closeModal();
}

function captureExpiry() {
    const video = document.getElementById('expiry-video');
    const canvas = document.getElementById('expiry-canvas');
    const img = document.getElementById('expiry-preview-img');
    
    // Crop to center 50% (matching the 2x zoom view) for better AI accuracy
    const sw = video.videoWidth / 2;
    const sh = video.videoHeight / 2;
    const sx = (video.videoWidth - sw) / 2;
    const sy = (video.videoHeight - sh) / 2;
    canvas.width = sw;
    canvas.height = sh;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(video, sx, sy, sw, sh, 0, 0, sw, sh);
    
    const dataUrl = canvas.toDataURL('image/jpeg', 0.85);
    img.src = dataUrl;
    
    // Stop camera
    if (expiryStream) {
        expiryStream.getTracks().forEach(t => t.stop());
        expiryStream = null;
    }
    video.srcObject = null;
    
    document.getElementById('expiry-cam-container').style.display = 'none';
    document.getElementById('expiry-preview-container').style.display = 'block';
    document.getElementById('expiry-capture-btn').style.display = 'none';
    document.getElementById('expiry-retake-btn').style.display = 'block';
    
    // Auto-analyze
    analyzeExpiryImage(dataUrl);
}

function retakeExpiry() {
    document.getElementById('expiry-cam-container').style.display = 'block';
    document.getElementById('expiry-preview-container').style.display = 'none';
    document.getElementById('expiry-capture-btn').style.display = 'block';
    document.getElementById('expiry-retake-btn').style.display = 'none';
    document.getElementById('expiry-scan-status').style.display = 'none';
    
    // Restart camera
    navigator.mediaDevices.getUserMedia(getCameraConstraints()).then(stream => {
        expiryStream = stream;
        const video = document.getElementById('expiry-video');
        video.srcObject = stream;
        video.play();
    }).catch(err => console.error(err));
}

async function analyzeExpiryImage(dataUrl) {
    const statusDiv = document.getElementById('expiry-scan-status');
    statusDiv.style.display = 'block';
    statusDiv.innerHTML = `<div class="loading-spinner" style="margin:0 auto 8px"></div><p>${t('scanner.ai_analyzing')}</p>`;
    
    try {
        // Remove data:image/jpeg;base64, prefix
        const base64 = dataUrl.split(',')[1];
        
        const result = await api('gemini_expiry', {}, 'POST', { image: base64 });
        
        if (result.success && result.expiry_date) {
            // Auto-fill the expiry date (treat as user-provided)
            const expiryInput = document.getElementById('add-expiry');
            if (expiryInput) {
                expiryInput.value = result.expiry_date;
                expiryInput.dataset.manuallySet = 'true';
            }
            statusDiv.innerHTML = `<p style="color:var(--success);font-weight:600">✅ ${t('scanner.expiry_found')}: ${formatDate(result.expiry_date)}</p>`;
            
            // Close modal after delay
            setTimeout(() => closeExpiryScanner(), 1500);
        } else if (result.error === 'no_api_key') {
            statusDiv.innerHTML = `<p style="color:var(--warning)">${t('ai.no_api_key').replace(/\n/g, '<br>')}</p>`;
        } else {
            statusDiv.innerHTML = `<p style="color:var(--danger)">❌ ${t('scanner.expiry_read_fail')} ${result.raw_text ? '<br><small>' + t('scanner.expiry_raw_label') + ': ' + escapeHtml(result.raw_text) + '</small>' : ''}</p>
                <button class="btn btn-secondary" onclick="retakeExpiry()" style="margin-top:8px">${t('btn.retry')}</button>`;
        }
    } catch (err) {
        console.error('Expiry AI error:', err);
        statusDiv.innerHTML = `<p style="color:var(--danger)">❌ ${t('error.network_retry')}</p>
            <button class="btn btn-secondary" onclick="retakeExpiry()" style="margin-top:8px">${t('btn.retry')}</button>`;
    }
}

function stripHtml(str) {
    if (!str) return '';
    return str.replace(/<[^>]*>/g, '');
}

function formatDate(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr + 'T00:00:00');
    const _loc1 = _currentLang === 'de' ? 'de-DE' : _currentLang === 'en' ? 'en-GB' : 'it-IT';
    return d.toLocaleDateString(_loc1, { day: '2-digit', month: 'short', year: 'numeric' });
}

function formatDateTime(dtStr) {
    if (!dtStr) return '';
    const d = new Date(dtStr.replace(' ', 'T'));
    const _loc2 = _currentLang === 'de' ? 'de-DE' : _currentLang === 'en' ? 'en-GB' : 'it-IT';
    return d.toLocaleDateString(_loc2, { day: '2-digit', month: 'short' }) + ' ' + 
           d.toLocaleTimeString(_loc2, { hour: '2-digit', minute: '2-digit' });
}

function daysUntilExpiry(dateStr) {
    if (!dateStr) return Infinity;
    const expiry = new Date(dateStr + 'T00:00:00');
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    return Math.round((expiry - today) / 86400000);
}

function adjustQty(inputId, delta) {
    const input = document.getElementById(inputId);
    let val = parseFloat(input.value) || 0;
    val = Math.max(0.1, val + delta);
    input.value = Math.round(val * 10) / 10;
}

function showLoading(show) {
    document.getElementById('loading').style.display = show ? 'flex' : 'none';
}

function showToast(message, type = '') {
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.className = 'toast show ' + type;
    setTimeout(() => {
        toast.className = 'toast';
    }, 3000);
}

// ===== LOG =====
let _logOffset = 0;
const LOG_PAGE_SIZE = 50;

async function loadLog(more = false) {
    if (!more) {
        _logOffset = 0;
        document.getElementById('log-list').innerHTML = '<p style="text-align:center;color:var(--text-muted)">' + t('app.loading') + '</p>';
    }

    try {
        const result = await api(`transactions_list&limit=${LOG_PAGE_SIZE}&offset=${_logOffset}`);
        const txns = result.transactions || [];

        let html = '';
        if (!more && txns.length === 0) {
            html = `<p style="text-align:center;color:var(--text-muted)">${t('log.empty')}</p>`;
        } else {
            let lastDate = more ? '' : null;
            const _logLocale = _currentLang === 'de' ? 'de-DE' : _currentLang === 'en' ? 'en-GB' : 'it-IT';
            txns.forEach(tx => {
                const dt = new Date(tx.created_at + 'Z');
                const dateStr = dt.toLocaleDateString(_logLocale, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
                const timeStr = dt.toLocaleTimeString(_logLocale, { hour: '2-digit', minute: '2-digit' });

                if (dateStr !== lastDate) {
                    html += `<div class="log-date-header">${dateStr}</div>`;
                    lastDate = dateStr;
                }

                let icon, typeLabel, colorClass;
                if (tx.type === 'bring') {
                    icon = '🛒';
                    typeLabel = t('log.type_bring');
                    colorClass = 'log-bring';
                } else if (tx.type === 'in') {
                    icon = '➕';
                    typeLabel = t('log.type_added');
                    colorClass = 'log-in';
                } else {
                    icon = '➖';
                    typeLabel = tx.type === 'waste' ? t('log.type_waste') : t('log.type_used');
                    colorClass = 'log-out';
                }
                const brand = tx.brand ? ` <em>(${tx.brand})</em>` : '';
                const loc = tx.location || '';
                const locLabels = Object.fromEntries(Object.entries(LOCATIONS).map(([k,v]) => [k, `${v.icon} ${v.label}`]));
                const locStr = tx.type === 'bring' ? '' : (locLabels[loc] || ('📍 ' + loc));
                const isAnnotation = (tx.notes || '').includes('[Annullato]');
                const isRecipeNote = !isAnnotation && (tx.notes || '').startsWith('Ricetta:');
                const notes = tx.notes && !isAnnotation && !isRecipeNote ? ` · ${tx.notes}` : '';
                const recipeNote = isRecipeNote ? `<div class="log-recipe-note">🍳 ${escapeHtml(tx.notes)}</div>` : '';
                const undone = tx.undone == 1 || isAnnotation;

                // Can undo if within 24h, not already undone, not a bring entry, not a counter-transaction
                const ageMs = Date.now() - new Date(tx.created_at + 'Z').getTime();
                const canUndo = !undone && tx.type !== 'bring' && ageMs < 86400000;

                html += `<div class="log-entry ${colorClass}${undone ? ' log-undone' : ''}" id="log-entry-${tx.id}">`;
                html += `<span class="log-icon">${icon}</span>`;
                html += `<div class="log-info">`;
                html += `<div class="log-product"><strong>${escapeHtml(tx.name)}</strong>${brand}${undone ? ` <span class="log-undone-badge">${t('log.undone_badge')}</span>` : ''}</div>`;
                const txQtyStr = tx.type !== 'bring'
                    ? formatQuantity(parseFloat(tx.quantity), tx.unit, tx.default_quantity, tx.package_unit) + ' · '
                    : '';
                html += `<div class="log-detail">${typeLabel} ${txQtyStr}${locStr}${notes} · ${timeStr}</div>`;
                html += recipeNote;
                html += `</div>`;
                if (canUndo) {
                    html += `<button class="btn-log-undo" onclick="undoTransactionEntry(${tx.id}, '${escapeHtml(tx.type)}', '${escapeHtml(tx.name || '')}')" title="${t('log.undo_title')}">↩</button>`;
                }
                html += `</div>`;
            });
        }

        if (more) {
            document.getElementById('log-list').insertAdjacentHTML('beforeend', html);
        } else {
            document.getElementById('log-list').innerHTML = html;
        }

        _logOffset += txns.length;
        document.getElementById('log-load-more').style.display = txns.length >= LOG_PAGE_SIZE ? '' : 'none';

    } catch (err) {
        console.error('Log load error:', err);
        if (!more) document.getElementById('log-list').innerHTML = `<p style="text-align:center;color:var(--danger)">${t('log.load_error')}</p>`;
    }
}

async function undoTransactionEntry(id, type, name) {
    const action = type === 'in' ? t('log.undo_action_remove') : t('log.undo_action_restore');
    const msg = t('log.undo_confirm').replace('{action}', action).replace('{name}', name);
    _showDestructiveConfirm(
        t('log.undo_title') || '↩ Annulla operazione',
        msg,
        () => _doUndoTransaction(id, type, name)
    );
}

async function _doUndoTransaction(id, type, name) {
    try {
        const res = await api('transaction_undo', {}, 'POST', { id });
        if (res.success) {
            showToast(t('log.undo_success').replace('{name}', res.name || name), 'success');
            // Mark the entry visually without reloading all
            const el = document.getElementById(`log-entry-${id}`);
            if (el) {
                el.classList.add('log-undone');
                const undoBtn = el.querySelector('.btn-log-undo');
                if (undoBtn) undoBtn.remove();
                const nameEl = el.querySelector('.log-product strong');
                if (nameEl && !el.querySelector('.log-undone-badge')) {
                    nameEl.insertAdjacentHTML('afterend', ` <span class="log-undone-badge">${t('log.undone_badge')}</span>`);
                }
            }
        } else if (res.already_undone) {
            showToast(t('log.already_undone'), 'info');
        } else if (res.too_old) {
            showToast(t('log.too_old'), 'error');
        } else {
            showToast(res.error || t('log.undo_error'), 'error');
        }
    } catch (e) {
        showToast(t('error.network'), 'error');
    }
}

// ===== WEEKLY MEAL PLAN =====

/**
/**
 * Keywords to check in inventory names for each meal plan type.
 * Mirror of PHP $typeKeywords in api/index.php.
 */
const MEAL_PLAN_TYPE_KEYWORDS = {
    pesce:     ['tonno','salmone','merluzzo','branzino','orata','sardine','acciughe','alici','gamberi','cozze','vongole','polpo','calamari','seppia','sgombro','trota','baccalà','dentice','spigola','pesce'],
    carne:     ['pollo','manzo','maiale','vitello','agnello','tacchino','salsiccia','hamburger','bistecca','cotoletta','pancetta','speck','carne','arrosto','filetto','lonza','braciola'],
    pasta:     ['pasta','spaghetti','penne','rigatoni','fusilli','tagliatelle','lasagne','farfalle','orecchiette','bucatini','linguine','maccheroni','gnocchi','pennette','bavette'],
    riso:      ['riso','basmati','arborio','carnaroli','parboiled'],
    legumi:    ['fagioli','ceci','lenticchie','piselli','fave','lupini','soia','legumi','borlotti','cannellini','azuki'],
    uova:      ['uova','uovo'],
    formaggio: ['formaggio','parmigiano','mozzarella','ricotta','pecorino','grana','gorgonzola','scamorza','fontina','emmental','asiago','provola','provolone','taleggio','stracchino'],
    pizza:     ['farina','lievito','pizza','focaccia'],
    affettati: ['prosciutto','salame','bresaola','mortadella','speck','coppa','affettati','wurstel','piadina'],
    verdure:   ['zucchine','zucchina','melanzane','peperoni','spinaci','cavolfiore','broccoli','carote','zucca','bietole','cavolo','carciofi','asparagi','lattuga','rucola','radicchio','finocchio','cipolla','porri','verdure'],
    zuppa:     ['brodo','zuppa','minestra','minestrone','orzo','farro','fagioli','ceci','lenticchie'],
    insalata:  ['insalata','lattuga','rucola','spinaci','radicchio','misticanza','valeriana','songino'],
    pane:      ['pane','pancarrè','baguette','toast','tramezzino','crackers','grissini','ciabatta'],
    dolce:     ['cioccolato','cacao','zucchero','miele','marmellata','nutella','savoiardi','biscotti','panna'],
};

/**
 * Check if today's meal plan type has at least one ingredient in the inventory.
 * Returns true if available (or type is unknown/libero), false if definitely missing.
 */
async function _checkMealPlanIngredientAvailable(typeId) {
    if (!typeId || typeId === 'libero') return true;
    const keywords = MEAL_PLAN_TYPE_KEYWORDS[typeId];
    if (!keywords || keywords.length === 0) return true;
    try {
        const data = await api('inventory_list');
        const items = (data.inventory || []).filter(i => parseFloat(i.quantity) > 0);
        for (const item of items) {
            const nameLower = (item.name + ' ' + (item.brand || '')).toLowerCase();
            for (const kw of keywords) {
                if (nameLower.includes(kw)) return true;
            }
        }
        return false;
    } catch {
        return true; // on error, assume available to avoid blocking UI
    }
}

/**
 * All selectable meal categories per slot.
 * id must be URL-safe; icon + label shown in UI.
 */
const MEAL_PLAN_TYPE_DEFS = [
    { id: 'pasta',      icon: '🍝', i18nKey: 'meal_plan_types.pasta' },
    { id: 'riso',       icon: '🍚', i18nKey: 'meal_plan_types.riso' },
    { id: 'carne',      icon: '🥩', i18nKey: 'meal_plan_types.carne' },
    { id: 'pesce',      icon: '🐟', i18nKey: 'meal_plan_types.pesce' },
    { id: 'legumi',     icon: '🫘', i18nKey: 'meal_plan_types.legumi' },
    { id: 'uova',       icon: '🥚', i18nKey: 'meal_plan_types.uova' },
    { id: 'formaggio',  icon: '🧀', i18nKey: 'meal_plan_types.formaggio' },
    { id: 'pizza',      icon: '🍕', i18nKey: 'meal_plan_types.pizza' },
    { id: 'affettati',  icon: '🥓', i18nKey: 'meal_plan_types.affettati' },
    { id: 'verdure',    icon: '🥦', i18nKey: 'meal_plan_types.verdure' },
    { id: 'zuppa',      icon: '🍲', i18nKey: 'meal_plan_types.zuppa' },
    { id: 'insalata',   icon: '🥗', i18nKey: 'meal_plan_types.insalata' },
    { id: 'pane',       icon: '🥪', i18nKey: 'meal_plan_types.pane' },
    { id: 'dolce',      icon: '🍰', i18nKey: 'meal_plan_types.dolce' },
    { id: 'libero',     icon: '🎲', i18nKey: 'meal_plan_types.libero' },
];

function getMealPlanTypes() {
    return MEAL_PLAN_TYPE_DEFS.map(mpt => ({ ...mpt, label: t(mpt.i18nKey) }));
}

function getMealPlanTypeMap() {
    const map = {};
    getMealPlanTypes().forEach(mpt => { map[mpt.id] = mpt; });
    return map;
}

function getWeekDaysShortLabels() {
    return [
        t('days.mon_short'),
        t('days.tue_short'),
        t('days.wed_short'),
        t('days.thu_short'),
        t('days.fri_short'),
        t('days.sat_short'),
        t('days.sun_short'),
    ];
}

/** Default weekly plan as requested. */
const DEFAULT_MEAL_PLAN = {
    1: { pranzo: 'pasta',   cena: 'pesce' },
    2: { pranzo: 'riso',    cena: 'carne' },
    3: { pranzo: 'legumi',  cena: 'uova' },
    4: { pranzo: 'pasta',   cena: 'pesce' },
    5: { pranzo: 'riso',    cena: 'formaggio' },
    6: { pranzo: 'legumi',  cena: 'pizza' },
    0: { pranzo: 'carne',   cena: 'affettati' },  // 0 = Sunday (getDay())
};

function getMealPlan() {
    const s = getSettings();
    return s.meal_plan || DEFAULT_MEAL_PLAN;
}

/** Return today's planned meal type for a given slot ('pranzo'|'cena'), or null. */
function getTodayMealPlanType(slot) {
    const s = getSettings();
    if (s.meal_plan_enabled === false) return null;
    const dow = new Date().getDay(); // 0=Sun,1=Mon,...,6=Sat
    const plan = getMealPlan();
    return plan[dow]?.[slot] || null;
}

/** Toggle handler for the enable/disable switch in settings. */
function onMealPlanEnabledChange(el) {
    const s = getSettings();
    s.meal_plan_enabled = el.checked;
    saveSettingsToStorage(s);
    const mpConfigSection = document.getElementById('meal-plan-config-section');
    if (mpConfigSection) mpConfigSection.style.display = el.checked ? '' : 'none';
    const mpLegendCard = document.getElementById('meal-plan-legend-card');
    if (mpLegendCard) mpLegendCard.style.display = el.checked ? '' : 'none';
    // Close picker if open
    const picker = document.getElementById('meal-plan-picker');
    if (picker) picker.style.display = 'none';
}

function onPriceCountryChange() {
    // Auto-suggest currency based on country
    const countryEl = document.getElementById('setting-price-country');
    const currencyEl = document.getElementById('setting-price-currency');
    if (!countryEl || !currencyEl) return;
    const map = {
        'USA': 'USD', 'UK': 'GBP', 'Switzerland': 'CHF', 'Canada': 'CAD',
        'Australia': 'AUD', 'Brazil': 'BRL', 'Japan': 'JPY', 'Sweden': 'SEK',
        'Norway': 'NOK', 'Denmark': 'DKK', 'Poland': 'PLN',
    };
    const suggested = map[countryEl.value];
    if (suggested) currencyEl.value = suggested;
    // Default to EUR for EU countries
}

/**
 * Render the weekly meal plan editor into #meal-plan-grid.
 * Each cell shows the current type badge + a picker dropdown.
 */
function renderMealPlanEditor() {
    const container = document.getElementById('meal-plan-grid');
    if (!container) return;
    const plan = getMealPlan();
    // JS getDay: 0=Sun … but we display Mon-Sun (1..6,0)
    const dayOrder = [1,2,3,4,5,6,0];
    const today = new Date().getDay();
    const mealPlanTypeMap = getMealPlanTypeMap();
    const weekDaysShort = getWeekDaysShortLabels();

    const header = `<div class="mplan-header">
        <span class="mplan-col-header">🌤️ ${t('meal_types.pranzo')}</span>
        <span class="mplan-col-header">🌙 ${t('meal_types.cena')}</span>
    </div>`;

    const rows = dayOrder.map((dow, i) => {
        const pranzo = plan[dow]?.pranzo || 'libero';
        const cena   = plan[dow]?.cena   || 'libero';
        const pt = mealPlanTypeMap[pranzo] || mealPlanTypeMap.libero;
        const ct = mealPlanTypeMap[cena]   || mealPlanTypeMap.libero;
        const todayClass = dow === today ? ' mplan-row-today' : '';
        return `<div class="mplan-row${todayClass}">
            <div class="mplan-day-name">${weekDaysShort[i]}</div>
            <span class="mplan-badge mplan-badge-pranzo" onclick="openMealPlanPicker(${dow},'pranzo',this)">${pt.icon} ${pt.label}</span>
            <span class="mplan-badge mplan-badge-cena" onclick="openMealPlanPicker(${dow},'cena',this)">${ct.icon} ${ct.label}</span>
        </div>`;
    }).join('');

    container.innerHTML = header + rows;
}

let _mplanPickerTarget = null; // {dow, slot, badgeEl}
function openMealPlanPicker(dow, slot, badgeEl) {
    // Close any open picker first
    closeMealPlanPicker();
    _mplanPickerTarget = { dow, slot, badgeEl };
    const picker = document.getElementById('meal-plan-picker');
    if (!picker) return;
    const plan = getMealPlan();
    const current = plan[dow]?.[slot] || 'libero';
    picker.innerHTML = getMealPlanTypes().map(mpt =>
        `<button class="mplan-pick-btn${mpt.id === current ? ' active' : ''}" onclick="selectMealPlanType(${dow},'${slot}','${mpt.id}')">${mpt.icon} ${mpt.label}</button>`
    ).join('');
    // Position vertically near the badge, centered horizontally (CSS handles centering)
    const rect = badgeEl.getBoundingClientRect();
    const pickerEl = picker;
    // Show first to measure height
    pickerEl.style.display = 'flex';
    const pickerH = pickerEl.offsetHeight || 160;
    const spaceBelow = window.innerHeight - rect.bottom - 8;
    const top = spaceBelow >= pickerH
        ? rect.bottom + 8
        : Math.max(8, rect.top - pickerH - 8);
    pickerEl.style.top = top + 'px';
    // Close on outside tap
    setTimeout(() => document.addEventListener('click', _mplanPickerOutside, { once: true }), 0);
}
function _mplanPickerOutside(e) {
    const picker = document.getElementById('meal-plan-picker');
    if (picker && !picker.contains(e.target)) closeMealPlanPicker();
}
function closeMealPlanPicker() {
    const picker = document.getElementById('meal-plan-picker');
    if (picker) picker.style.display = 'none';
    _mplanPickerTarget = null;
    document.removeEventListener('click', _mplanPickerOutside);
}
function selectMealPlanType(dow, slot, typeId) {
    const s = getSettings();
    if (!s.meal_plan) s.meal_plan = JSON.parse(JSON.stringify(DEFAULT_MEAL_PLAN));
    if (!s.meal_plan[dow]) s.meal_plan[dow] = {};
    s.meal_plan[dow][slot] = typeId;
    saveSettingsToStorage(s);
    closeMealPlanPicker();
    renderMealPlanEditor();
    // Persist to server for cross-device sync
    api('app_settings_save', {}, 'POST', { settings: { meal_plan: s.meal_plan } }).catch(() => {});
}
function resetMealPlan() {
    const s = getSettings();
    s.meal_plan = JSON.parse(JSON.stringify(DEFAULT_MEAL_PLAN));
    saveSettingsToStorage(s);
    renderMealPlanEditor();
    showToast(t('meal_plan.reset_success'), 'success');
    api('app_settings_save', {}, 'POST', { settings: { meal_plan: s.meal_plan } }).catch(() => {});
}

// ===== RECIPE GENERATION =====
const MEAL_TYPE_DEFS = [
    { id: 'colazione',  icon: '☀️', i18nKey: 'meal_types.colazione',  from: 6,  to: 11 },
    { id: 'pranzo',     icon: '🍽️', i18nKey: 'meal_types.pranzo',     from: 11, to: 14 },
    { id: 'merenda',    icon: '🍪', i18nKey: 'meal_types.merenda',    from: 14, to: 17 },
    { id: 'cena',       icon: '🌙', i18nKey: 'meal_types.cena',       from: 17, to: 6  },
    { id: 'dolce',      icon: '🍰', i18nKey: 'meal_types.dolce',      from: -1, to: -1 },
    { id: 'succo',      icon: '🧃', i18nKey: 'meal_types.succo',      from: -1, to: -1 },
];

function getMealTypes() {
    return MEAL_TYPE_DEFS.map(m => ({ ...m, label: t(m.i18nKey) }));
}

function getMealSubTypes() {
    return {
        dolce: [
            { id: 'torta',      icon: '🎂', label: t('meal_sub.dolce_torta') },
            { id: 'crema',      icon: '🍮', label: t('meal_sub.dolce_crema') },
            { id: 'crumble',    icon: '🥧', label: t('meal_sub.dolce_crumble') },
            { id: 'biscotti',   icon: '🍪', label: t('meal_sub.dolce_biscotti') },
            { id: 'frutta',     icon: '🍓', label: t('meal_sub.dolce_frutta') },
        ],
        succo: [
            { id: 'dolce',        icon: '🍑', label: t('meal_sub.succo_dolce') },
            { id: 'energizzante', icon: '⚡', label: t('meal_sub.succo_energizzante') },
            { id: 'detox',        icon: '🥬', label: t('meal_sub.succo_detox') },
            { id: 'rinfrescante', icon: '🧊', label: t('meal_sub.succo_rinfrescante') },
            { id: 'vitaminico',   icon: '🍊', label: t('meal_sub.succo_vitaminico') },
        ]
    };
}

function getMealLabels() {
    const labels = {};
    getMealTypes().forEach(m => { labels[m.id] = `${m.icon} ${m.label}`; });
    return labels;
}

function getMealType() {
    const hour = new Date().getHours();
    for (const m of MEAL_TYPE_DEFS) {
        if (m.from < m.to) { if (hour >= m.from && hour < m.to) return m.id; }
        else { if (hour >= m.from || hour < m.to) return m.id; }
    }
    return 'cena';
}

function _normalizeMealId(rawMeal) {
    if (!rawMeal) return '';
    let meal = String(rawMeal).trim().toLowerCase();
    meal = meal.replace(/^meal_types?\./, '');
    if (meal === 'lunch') return 'pranzo';
    if (meal === 'dinner') return 'cena';
    return meal;
}

function _mealLabel(rawMeal) {
    const mealId = _normalizeMealId(rawMeal);
    const labels = getMealLabels();
    if (labels[mealId]) return labels[mealId];
    const translated = mealId ? t(`meal_types.${mealId}`) : '';
    if (translated && translated !== `meal_types.${mealId}`) return translated;
    return mealId || String(rawMeal || '');
}

function getSelectedMealType() {
    const checked = document.querySelector('input[name="recipe-meal"]:checked');
    return checked ? checked.value : getMealType();
}

// ===== RECIPE ARCHIVE (DB-backed) =====
let _recipeArchiveCache = null;

async function getRecipeArchive() {
    if (_recipeArchiveCache !== null) return _recipeArchiveCache;
    try {
        const res = await api('recipes_list');
        if (res.success) {
            _recipeArchiveCache = res.recipes || [];
            return _recipeArchiveCache;
        }
    } catch(e) { console.warn('Failed to load recipes from DB:', e); }
    return [];
}

async function saveRecipeToArchive(recipe) {
    const today = new Date().toISOString().slice(0, 10);
    try {
        await api('recipes_save', {}, 'POST', { date: today, meal: recipe.meal, recipe });
        // Invalidate cache and refresh the archive list
        _recipeArchiveCache = null;
        loadRecipeArchive();
    } catch(e) { console.error('Failed to save recipe:', e); }
}

async function getTodayRecipeTitles() {
    const archive = await getRecipeArchive();
    const today = new Date().toISOString().slice(0, 10);
    return archive
        .filter(e => e.date === today && e.recipe && e.recipe.title)
        .map(e => e.recipe.title);
}

let _recipeArchiveEntries = [];

async function loadRecipeArchive() {
    const container = document.getElementById('recipe-archive');
    if (!container) return;
    const archive = await getRecipeArchive();
    _recipeArchiveEntries = archive;
    
    if (archive.length === 0) {
        container.innerHTML = `<div class="empty-state" style="padding:20px"><div class="empty-state-icon">🍳</div><p>${t('recipes.archive_empty')}</p></div>`;
        return;
    }
    
    // Group by date
    const byDate = {};
    for (const entry of archive) {
        if (!byDate[entry.date]) byDate[entry.date] = [];
        byDate[entry.date].push(entry);
    }
    
    let html = '';
    let flatIdx = 0;
    const today = new Date().toISOString().slice(0, 10);
    const yesterday = new Date(Date.now() - 86400000).toISOString().slice(0, 10);
    
    for (const [date, entries] of Object.entries(byDate)) {
        const _mealLocale = _currentLang === 'de' ? 'de-DE' : _currentLang === 'en' ? 'en-GB' : 'it-IT';
        let dateLabel = new Date(date + 'T12:00:00').toLocaleDateString(_mealLocale, { weekday: 'long', day: 'numeric', month: 'long' });
        if (date === today) dateLabel = t('date.today');
        else if (date === yesterday) dateLabel = t('date.yesterday');
        
        html += `<div class="recipe-archive-day">`;
        html += `<div class="recipe-archive-date">${escapeHtml(dateLabel)}</div>`;
        
        for (const entry of entries) {
            const r = entry.recipe;
            const mealIcon = _mealLabel(r.meal || entry.meal);
            const tags = (r.tags || []).slice(0, 3).join(', ');
            // Find this entry's index in the flat archive array
            const archiveIdx = archive.indexOf(entry);
            const favBadge = entry.is_favorite ? `<span class="recipe-fav-badge" title="${t('recipes.favorite')}">★</span>` : '';
            html += `<div class="recipe-archive-card${entry.is_favorite ? ' recipe-archive-card-fav' : ''}" onclick="viewArchivedRecipe(${archiveIdx})">`;
            html += `<div class="recipe-archive-card-header">`;
            html += `<span class="recipe-archive-meal">${mealIcon}</span>`;
            html += `<span class="recipe-archive-title">${escapeHtml(r.title)}</span>`;
            html += favBadge;
            html += `</div>`;
            html += `<div class="recipe-archive-card-meta">`;
            if (r.prep_time) html += `<span>🔪 ${r.prep_time}</span>`;
            if (r.cook_time) html += `<span>🔥 ${r.cook_time}</span>`;
            html += `<span>👥 ${r.persons}</span>`;
            if (tags) html += `<span>${tags}</span>`;
            html += `</div></div>`;
            flatIdx++;
        }
        html += `</div>`;
    }
    
    container.innerHTML = html;
}

function viewArchivedRecipe(idx) {
    const pick = _recipeArchiveEntries[idx];
    if (!pick) return;
    _recipeArchiveCache = null;
    getRecipeArchive().then(archive => {
        const entry = archive.find(e => e.id === pick.id) || pick;
        _cachedRecipe = { meal: _normalizeMealId(entry.meal), recipe: entry.recipe, id: entry.id, is_favorite: !!entry.is_favorite };
        return renderRecipe(entry.recipe);
    }).then(() => {
        document.getElementById('recipe-overlay').style.display = 'flex';
        document.getElementById('recipe-ask').style.display = 'none';
        document.getElementById('recipe-loading').style.display = 'none';
        document.getElementById('recipe-result').style.display = '';
    });
}

let _cachedRecipe = null;
let _recipeShoppingSuggestions = [];
let _generatedTodayTitles = []; // client-side list, robust vs race conditions
let _recipeVariationCount = {}; // { 'pranzo': 0, 'cena': 1, ... }
let _rejectedRecipeIngredients = []; // ingredient names from previously rejected recipes

function openRecipeDialog() {
    if (!_requireGemini()) return;
    const meal = getMealType();
    const settings = getSettings();
    document.getElementById('recipe-overlay').style.display = 'flex';

    // Build meal selector radios
    const mealGrid = document.getElementById('recipe-meal-grid');
    if (mealGrid) {
        mealGrid.innerHTML = getMealTypes().map(m => {
            const checked = m.id === meal ? ' checked' : '';
            return `<label class="recipe-meal-chip"><input type="radio" name="recipe-meal" value="${m.id}"${checked}> ${m.icon} ${m.label}</label>`;
        }).join('');
    }
    updateRecipeMealTitle();

    // Show today's meal plan hint
    _renderMealPlanHint(meal);

    // Check for cached recipe matching current meal type
    if (_cachedRecipe && _cachedRecipe.meal === meal && _cachedRecipe.recipe) {
        document.getElementById('recipe-ask').style.display = 'none';
        document.getElementById('recipe-loading').style.display = 'none';
        renderRecipe(_cachedRecipe.recipe);
        document.getElementById('recipe-result').style.display = '';
        return;
    }

    // Pre-fill persons from settings
    document.getElementById('recipe-persons').value = settings.default_persons || 1;
    
    // Pre-select option chips from settings
    const prefMap = {
        'veloce': 'recipe-opt-veloce',
        'pocafame': 'recipe-opt-pocafame', 
        'scadenze': 'recipe-opt-scadenze',
        'salutare': 'recipe-opt-healthy',
        'opened': 'recipe-opt-opened',
        'zerowaste': 'recipe-opt-zerowaste'
    };
    Object.entries(prefMap).forEach(([key, id]) => {
        const cb = document.getElementById(id);
        if (cb) cb.checked = settings.recipe_prefs && settings.recipe_prefs.includes(key);
    });
    
    document.getElementById('recipe-ask').style.display = '';
    document.getElementById('recipe-loading').style.display = 'none';
    document.getElementById('recipe-result').style.display = 'none';
}

// Toggle recipe option chip
function toggleRecipeOption(btn) {
    btn.classList.toggle('active');
}

function closeRecipeDialog() {
    document.getElementById('recipe-overlay').style.display = 'none';
}

function adjustRecipePersons(delta) {
    const input = document.getElementById('recipe-persons');
    let val = parseInt(input.value) || 1;
    val = Math.max(1, Math.min(20, val + delta));
    input.value = val;
}

let _recipeUseContext = null; // { idx, productId, btn, qtyNumber, items }
let _recipeUseConfMode = null;
let _recipeUseNormalUnit = 'pz';

function _parseRecipeQtyString(qtyStr) {
    const m = (qtyStr || '').match(/(\d+(?:[.,]\d+)?)\s*(g|gr|gramm|kg|ml|l|litri|cl|pz|pezz|conf)/i);
    if (!m) return { val: 0, unit: '' };
    let val = parseFloat(m[1].replace(',', '.'));
    const ru = m[2].toLowerCase();
    let unit = '';
    if (ru.startsWith('g')) unit = 'g';
    else if (ru === 'kg') { unit = 'g'; val *= 1000; }
    else if (ru === 'ml') unit = 'ml';
    else if (ru === 'cl') { unit = 'ml'; val *= 10; }
    else if (ru === 'l' || ru.startsWith('litr')) { unit = 'ml'; val *= 1000; }
    else if (ru.startsWith('pz') || ru.startsWith('pezz')) unit = 'pz';
    else if (ru.startsWith('conf')) unit = 'conf';
    return { val, unit };
}

function _normalizeRecipeIngQtyNumber(ing) {
    const parsed = _parseRecipeQtyString(ing.qty || '');
    const recipeVal = parsed.val;
    const recipeUnit = parsed.unit;
    const unit = ing.inventory_unit || 'pz';
    const pkgSize = parseFloat(ing.default_quantity) || 0;
    const pkgUnit = (ing.package_unit || '').toLowerCase();
    const isConfSub = unit === 'conf' && pkgSize > 0 && (pkgUnit === 'g' || pkgUnit === 'ml');
    let useQty = parseFloat(ing.qty_number) || 0;
    const stockPieces = parseFloat(ing.inventory_qty_total ?? ing.inventory_qty) || 0;

    if (unit === 'pz') {
        useQty = _recipeResolvePieceQty(useQty, recipeVal, recipeUnit, stockPieces);
        ing.qty_number = Math.round(useQty * 1000) / 1000;
        ing.qty = _recipeFormatPieceQtyLabel(useQty);
        return useQty;
    }

    if (isConfSub && recipeVal > 0 && recipeUnit === pkgUnit) {
        useQty = recipeVal;
        ing.qty_number = Math.round(useQty * 1000) / 1000;
        ing.qty = Math.round(useQty) + ' ' + pkgUnit;
    } else if (isConfSub && useQty > 0 && useQty <= (parseFloat(ing.inventory_qty_total ?? ing.inventory_qty) || 0)) {
        // Legacy: qty_number stored as conf count — convert to sub-units only if not matching recipe string
        if (!(recipeVal > 0 && recipeUnit === pkgUnit)) {
            useQty = Math.round(useQty * pkgSize);
            ing.qty_number = useQty;
            ing.qty = useQty + ' ' + pkgUnit;
        }
    }
    return useQty;
}

function _recipeRoundPieceQty(n) {
    return Math.max(0.25, Math.round(n * 4) / 4);
}

function _recipeFormatPieceQtyLabel(n) {
    const whole = Math.floor(n);
    const frac = Math.round((n - whole) * 4) / 4;
    const fracMap = { 0.25: '¼', 0.5: '½', 0.75: '¾' };
    const fracStr = fracMap[frac] || '';
    if (whole === 0) return (fracStr || '0') + ' pz';
    return whole + fracStr + ' pz';
}

/** Piece inventory only — never derive count from default_quantity / grams. */
function _recipeResolvePieceQty(rawQty, recipeVal, recipeUnit, stockPieces) {
    stockPieces = Math.max(0, stockPieces);
    if (recipeUnit === 'pz' && recipeVal > 0) {
        return _recipeRoundPieceQty(Math.min(recipeVal, stockPieces > 0 ? stockPieces : recipeVal));
    }
    if (rawQty >= 0.25 && rawQty <= Math.min(stockPieces > 0 ? stockPieces : 50, 50)) {
        return _recipeRoundPieceQty(rawQty);
    }
    if (rawQty >= 20 && (stockPieces <= 0 || rawQty > stockPieces)) {
        return _recipeRoundPieceQty(Math.min(1, stockPieces > 0 ? stockPieces : 1));
    }
    if (recipeVal >= 0.25 && recipeVal <= 50 && !['g', 'ml', 'kg', 'l'].includes(recipeUnit)) {
        return _recipeRoundPieceQty(Math.min(recipeVal, stockPieces > 0 ? stockPieces : recipeVal));
    }
    return _recipeRoundPieceQty(Math.min(1, stockPieces > 0 ? stockPieces : 1));
}

function _recipeGetServingCapForIngredient(name, unit, persons) {
    if (!persons || persons <= 0) return null;
    const n = (name || '').toLowerCase().replace(/\s+/g, ' ');
    if (unit === 'pz') {
        if (/\b(cipoll\w*|porr\w*|scalog\w*)\b/.test(n)) return persons;
        if (/\b(peperon\w*|melanzan\w*|zucchin\w*|finocchi\w*|melone)\b/.test(n)) return persons;
        if (/\b(limon\w*|aranc\w*|limett\w*)\b/.test(n)) return Math.max(1, Math.ceil(0.5 * persons));
        if (/\b(dado|brodo)\b/.test(n)) return Math.min(persons, 1);
        if (/\b(baulett\w*|panin\w*|toast|piadin\w*|grissin\w*)\b/.test(n)) return Math.min(2, persons);
        return null;
    }
    if (unit === 'g' || unit === 'ml') {
        if (/\b(spinac\w*|bietol\w*|rucol\w*|lattug\w*|valerian\w*|songin\w*|misticanz\w*|indivi\w*|radicchi\w*|cicori\w*)\b/.test(n)) return 150 * persons;
        if (/\b(minestr\w*|verdure)\b/.test(n)) return 200 * persons;
        if (/\b(pane\s*gratt|grattugi\w*|pangratt)\b/.test(n)) return 30 * persons;
        if (/\b(zucchin\w*|melanzan\w*|peperon\w*|carot\w*|sedan\w*|finocchi\w*|cavolf\w*|broccol\w*|zucc\w*|pomodor\w*|verdur\w*)\b/.test(n)) return 150 * persons;
    }
    return null;
}

function _recipeClampQtyForServings(ing, persons) {
    if (!persons || persons <= 0) return;
    const unit = ing.inventory_unit || 'pz';
    let qty = parseFloat(ing.qty_number) || 0;
    if (qty <= 0) return;
    const cap = _recipeGetServingCapForIngredient(ing.name, unit, persons);
    if (cap === null || qty <= cap) return;
    ing.qty_number = Math.round(cap * 100) / 100;
    if (unit === 'pz') ing.qty = _recipeFormatPieceQtyLabel(cap);
    else if (unit === 'g' || unit === 'ml') ing.qty = Math.round(cap) + ' ' + unit;
    delete ing.use_all_suggested;
    if (ing.stock_have != null) ing.stock_remain = Math.max(0, Math.round((ing.stock_have - cap) * 100) / 100);
}

function _recipeGetClosedProductBaseQty(ing) {
    const unit = ing.inventory_unit || 'pz';
    const pkgSize = parseFloat(ing.default_quantity) || 0;
    const pkgUnit = (ing.package_unit || '').toLowerCase();
    // Countable items: one piece = one unit — ignore default_quantity weight in grams
    if (unit === 'pz') return 1;
    if (unit === 'conf' && pkgSize > 0 && (pkgUnit === 'g' || pkgUnit === 'ml')) {
        return pkgSize;
    }
    if (unit === 'conf' && pkgSize > 0) {
        return pkgSize;
    }
    if (pkgSize > 0 && (unit === 'g' || unit === 'ml')) {
        return pkgSize;
    }
    if (unit === 'conf') {
        return 1;
    }
    return 0;
}

function _recipeShouldUseAllRemainder(remainDisp, ing, stockDisp = 0) {
    if (remainDisp <= 0) return false;
    const packageBase = _recipeGetClosedProductBaseQty(ing);
    if (packageBase <= 0) return false;
    const pct = remainDisp / packageBase;
    if (pct < 0.05) return true;
    if (stockDisp > 0 && stockDisp < packageBase && pct < 0.10) return true;
    return false;
}

function _computeRecipeIngStockHint(ing, totalStockQty) {
    _normalizeRecipeIngQtyNumber(ing);
    const unit = ing.inventory_unit || 'pz';
    const pkgSize = parseFloat(ing.default_quantity) || 0;
    const pkgUnit = (ing.package_unit || '').toLowerCase();
    const isConfSub = unit === 'conf' && pkgSize > 0 && (pkgUnit === 'g' || pkgUnit === 'ml');
    let useQty = parseFloat(ing.qty_number) || 0;
    let stockDisp, useDisp, dispUnit;

    if (isConfSub) {
        stockDisp = totalStockQty * pkgSize;
        useDisp = useQty;
        dispUnit = pkgUnit;
    } else {
        stockDisp = totalStockQty;
        useDisp = useQty;
        dispUnit = unit;
    }

    let remainDisp = Math.max(0, stockDisp - useDisp);
    let useAll = false;
    if (stockDisp > 0 && useDisp > 0 && _recipeShouldUseAllRemainder(remainDisp, ing, stockDisp)) {
        useAll = true;
        useDisp = stockDisp;
        remainDisp = 0;
        ing.use_all_suggested = true;
        if (isConfSub) {
            ing.qty_number = Math.round(useDisp * 10) / 10;
            ing.qty = Math.round(useDisp) + ' ' + pkgUnit;
        } else {
            ing.qty_number = Math.round(totalStockQty * 1000) / 1000;
            ing.qty = unit === 'pz'
                ? _recipeFormatPieceQtyLabel(totalStockQty)
                : Math.round(totalStockQty) + ' ' + unit;
        }
    } else {
        delete ing.use_all_suggested;
    }

    ing.stock_have = Math.round(stockDisp * 100) / 100;
    ing.stock_remain = Math.round(remainDisp * 100) / 100;
    ing.stock_unit = dispUnit;
    ing.package_base = _recipeGetClosedProductBaseQty(ing);
    return { stockDisp, remainDisp, dispUnit, useAll };
}

function _formatRecipeStockQty(n, dispUnit, ing) {
    if (dispUnit === 'g' || dispUnit === 'ml') {
        const rounded = n < 10 ? Math.round(n * 10) / 10 : Math.round(n);
        return rounded + ' ' + dispUnit;
    }
    return stripHtml(formatQuantity(n, ing.inventory_unit || 'pz', ing.default_quantity, ing.package_unit));
}

function _recipeIngStockHintHtml(ing) {
    if (!ing.from_pantry || ing.stock_have == null) return '';
    const have = _formatRecipeStockQty(ing.stock_have, ing.stock_unit || ing.inventory_unit, ing);
    const remain = _formatRecipeStockQty(ing.stock_remain, ing.stock_unit || ing.inventory_unit, ing);
    let line = t('recipes.ing_stock_line').replace('{have}', have).replace('{remain}', remain);
    if (ing.use_all_suggested) line += ' · ' + t('recipes.ing_use_all_note');
    return line;
}

async function enrichRecipeIngredientsStock(recipe) {
    if (!recipe || !recipe.ingredients) return recipe;
    try {
        const data = await api('inventory_list');
        const inv = data.inventory || [];
        for (const ing of recipe.ingredients) {
            if (!ing.from_pantry || !ing.product_id) continue;
            const rows = inv.filter(i => i.product_id == ing.product_id);
            const activeRows = rows.filter(i => parseFloat(i.quantity) > 0);
            if (!activeRows.length) {
                ing.from_pantry = false;
                delete ing.product_id;
                delete ing.stock_have;
                delete ing.stock_remain;
                continue;
            }
            const totalStock = activeRows.reduce((s, i) => s + parseFloat(i.quantity), 0);
            ing.inventory_qty_total = totalStock;
            const opened = activeRows.find(_isOpenedInventoryItem);
            const pick = opened || activeRows.find(r => r.location === ing.location) || activeRows[0];
            if (pick) {
                ing.inventory_unit = pick.unit;
                ing.default_quantity = pick.default_quantity;
                ing.package_unit = pick.package_unit;
            }
            _computeRecipeIngStockHint(ing, totalStock);
            _recipeClampQtyForServings(ing, Math.max(1, parseInt(recipe.persons, 10) || 1));
        }
    } catch (e) {
        console.warn('enrichRecipeIngredientsStock:', e);
    }
    return recipe;
}

function _updateRecipeStockHintsAfterScale(ratio) {
    const ings = _cachedRecipe?.recipe?.ingredients || [];
    document.querySelectorAll('#recipe-content .recipe-ingredient[data-ing-idx]').forEach(li => {
        const idx = parseInt(li.dataset.ingIdx, 10);
        const ing = ings[idx];
        if (!ing || ing.stock_have == null) return;
        const baseUse = parseFloat(li.dataset.baseQty || '0');
        if (baseUse <= 0) return;
        const unit = ing.inventory_unit || 'pz';
        const pkgSize = parseFloat(ing.default_quantity) || 0;
        const pkgUnit = (ing.package_unit || '').toLowerCase();
        const isConfSub = unit === 'conf' && pkgSize > 0 && (pkgUnit === 'g' || pkgUnit === 'ml');
        const stockDisp = ing.stock_have;
        let useDisp = baseUse * ratio;
        let remainDisp = Math.max(0, stockDisp - useDisp);
        let useAll = false;
        if (stockDisp > 0 && useDisp > 0 && _recipeShouldUseAllRemainder(remainDisp, ing, stockDisp)) {
            useAll = true;
            useDisp = stockDisp;
            remainDisp = 0;
        }
        const stockEl = li.querySelector('.recipe-ing-stock');
        if (!stockEl) return;
        const have = _formatRecipeStockQty(stockDisp, ing.stock_unit || unit, ing);
        const remain = _formatRecipeStockQty(remainDisp, ing.stock_unit || unit, ing);
        let line = t('recipes.ing_stock_line').replace('{have}', have).replace('{remain}', remain);
        if (useAll) line += ' · ' + t('recipes.ing_use_all_note');
        stockEl.textContent = line;
    });
}

async function useRecipeIngredient(idx, productId, location, qtyNumber, btn, recipeQty) {
    if (btn.disabled) return;
    if (!qtyNumber || qtyNumber <= 0) qtyNumber = 1;
    
    _recipeUseContext = { idx, productId, btn, qtyNumber, recipeQty };
    _recipeUseConfMode = null;

    // Reset scale state: set the current weight as baseline so only a *change*
    // of ≥5g after the modal opens triggers auto-fill (allows time to tare).
    _cancelScaleAutoConfirm(false);
    _scaleRecipeAutoFillPaused = false;
    if (_scaleLatestWeight) {
        const _baseline = _scaleToGrams(parseFloat(_scaleLatestWeight.value), _scaleLatestWeight.unit);
        if (_baseline !== null && _baseline >= 5) _scaleLastConfirmedGrams = _baseline;
    }

    // Fetch inventory to build the modal
    try {
        const data = await api('inventory_list');
        const items = (data.inventory || []).filter(i => i.product_id == productId);
        _recipeUseContext.items = items; // cache for "use all" quantity lookup
        
        if (items.length === 0) {
            showToast(t('error.not_in_inventory'), 'error');
            return;
        }
        
        const unit = items[0].unit || 'pz';
        const pkgSize = parseFloat(items[0].default_quantity) || 0;
        const pkgUnit = items[0].package_unit || '';
        const isConf = unit === 'conf' && pkgSize > 0 && pkgUnit;
        
        // Find opened package location
        const openedItem = items.find(_isOpenedInventoryItem);
        const defaultLoc = openedItem ? openedItem.location : (items.find(i => i.location === location) ? location : items[0].location);
        
        // Build location buttons
        const productLocations = [...new Set(items.map(i => i.location))];
        const locButtons = productLocations.map(loc => {
            const locInfo = LOCATIONS[loc] || { icon: '📦', label: loc };
            const locItems = items.filter(i => i.location === loc);
            const locQty = locItems.reduce((s, i) => s + parseFloat(i.quantity), 0);
            const qtyLabel = formatQuantity(locQty, unit, pkgSize, pkgUnit);
            const openedBadge = _locationHasOpenedPackage(items, loc)
                ? ` <span class="loc-opened-badge">🔓 ${t('use.opened_badge')}</span>`
                : '';
            return `<button type="button" class="loc-btn ${loc === defaultLoc ? 'active' : ''}${openedBadge ? ' loc-btn-opened' : ''}" onclick="selectRecipeUseLoc(this, '${loc}')">${locInfo.icon} ${locInfo.label}${openedBadge}<br><small>${qtyLabel}</small></button>`;
        }).join('');
        
        // Build quantity controls
        let qtySection = '';
        let defaultQtyValue = Math.round(qtyNumber * 10) / 10;
        
        if (isConf) {
            const totalConf = items.reduce((s, i) => s + parseFloat(i.quantity), 0);
            const totalSub = totalConf * pkgSize;
            const unitLabels = { 'ml': 'ml', 'g': 'g', 'pz': 'pz' };
            const subLabel = unitLabels[pkgUnit] || pkgUnit;
            _recipeUseConfMode = { packageSize: pkgSize, packageUnit: pkgUnit, totalSub, totalConf, subLabel, _activeUnit: 'sub' };
            
            // qtyNumber from recipe is in sub-units (g, ml)
            const step = getSubUnitStep(pkgUnit);
            defaultQtyValue = (pkgUnit === 'g' || pkgUnit === 'ml') ? Math.round(qtyNumber) : Math.round(qtyNumber * 10) / 10;
            
            qtySection = `
                <div class="use-unit-switch" style="display:flex;margin-bottom:8px">
                    <button type="button" class="use-unit-btn active" id="ruse-unit-sub" onclick="switchRecipeUseUnit('sub')">${subLabel}</button>
                    <button type="button" class="use-unit-btn" id="ruse-unit-conf" onclick="switchRecipeUseUnit('conf')">${t('recipes.packs_label')}</button>
                </div>
                <p id="ruse-hint" style="font-size:0.85rem;color:var(--text-muted);margin-bottom:8px">${t('recipes.quantity_in_total').replace('{unit}', subLabel).replace('{total}', Math.round(totalSub) + subLabel)}</p>
                <div class="qty-control-with-unit">
                    <div class="qty-control">
                        <button type="button" class="qty-btn" onclick="adjustRecipeUseQty(-1)">−</button>
                        <input type="number" id="ruse-quantity" value="${defaultQtyValue}" min="${step}" step="${step}" class="qty-input"
                               oninput="_scaleRecipeAutoFillPaused=true; _cancelScaleAutoConfirm(false); var h=document.getElementById('ruse-scale-hint'); if(h) h.style.display='none';">
                        <button type="button" class="qty-btn" onclick="adjustRecipeUseQty(1)">+</button>
                    </div>
                    <span class="qty-unit-badge" id="ruse-quantity-unit" aria-live="polite">${escapeHtml(subLabel)}</span>
                </div>`;
        } else {
            _recipeUseNormalUnit = unit;
            const unitLabels = { 'pz': t('units.pz'), 'g': 'g', 'ml': 'ml' };
            const unitLabel = unitLabels[unit] || unit;
            const inputMin = '0.1';
            qtySection = `
                <p style="font-size:0.85rem;color:var(--text-muted);margin-bottom:8px">${t('recipes.amount_label')}:</p>
                <div class="qty-control-with-unit">
                    <div class="qty-control">
                        <button type="button" class="qty-btn" onclick="adjustRecipeUseQty(-1)">−</button>
                        <input type="number" id="ruse-quantity" value="${defaultQtyValue}" min="${inputMin}" step="any" class="qty-input"
                               oninput="_scaleRecipeAutoFillPaused=true; _cancelScaleAutoConfirm(false); var h=document.getElementById('ruse-scale-hint'); if(h) h.style.display='none';">
                        <button type="button" class="qty-btn" onclick="adjustRecipeUseQty(1)">+</button>
                    </div>
                    <span class="qty-unit-badge" id="ruse-quantity-unit" aria-live="polite">${escapeHtml(unitLabel)}</span>
                </div>`;
        }
        
        // Scale live UI: show only when scale is connected and unit is g or ml
        const availInfo = items.map(i => {
            const loc = LOCATIONS[i.location] || { icon: '📦', label: i.location };
            return `${loc.icon} ${formatQuantity(i.quantity, i.unit, i.default_quantity, i.package_unit)}`;
        }).join(' · ');

        const showScaleLive = _scaleConnected && (unit === 'g' || unit === 'ml' ||
            (_recipeUseConfMode && ((_recipeUseConfMode.packageUnit || '').toLowerCase() === 'g' || (_recipeUseConfMode.packageUnit || '').toLowerCase() === 'ml')));
        const scaleLiveSection = showScaleLive ? `
            <div id="ruse-scale-live-box" class="scale-live-box" style="flex-direction:column;align-items:stretch;border-color:var(--color-accent,#7c3aed)">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
                    <span class="scale-live-icon">⚖️</span>
                    <span id="ruse-scale-live-val" class="scale-live-val" style="color:var(--color-accent,#7c3aed)">— —</span>
                    <span id="ruse-scale-live-status" style="font-size:0.75rem;color:var(--text-muted);margin-left:auto"></span>
                </div>
                <div style="height:4px;background:var(--border);border-radius:2px;overflow:hidden;margin-bottom:4px">
                    <div id="ruse-scale-progress-bar" style="height:100%;width:0%;background:var(--color-accent,#7c3aed);transition:none;border-radius:2px"></div>
                </div>
                <div style="height:4px;background:var(--border);border-radius:2px;overflow:hidden;display:none" id="ruse-scale-confirm-wrap">
                    <div id="ruse-scale-confirm-bar" style="height:100%;width:100%;background:#22c55e;transition:none;border-radius:2px"></div>
                </div>
                <div id="ruse-scale-live-label" class="scale-live-label" style="margin-top:3px">${t('recipes.scale_wait_stable')}</div>
            </div>` : '';

        document.getElementById('modal-content').innerHTML = `
            <div class="modal-header">
                <h3>📤 ${t('recipes.use_ingredient_title')}</h3>
                <button class="modal-close" onclick="closeModal()">✕</button>
            </div>
            <div style="padding:0 16px 16px">
                <p style="margin-bottom:4px;font-weight:600">${escapeHtml(items[0].name)}</p>
                ${recipeQty ? `<p style="margin-bottom:8px;background:var(--bg-elevated,rgba(124,58,237,0.12));border-left:3px solid var(--color-accent,#7c3aed);border-radius:6px;padding:6px 10px;font-size:0.9rem">📋 ${t('recipes.recipe_qty_label')}: <strong>${escapeHtml(recipeQty)}</strong></p>` : ''}
                <p style="font-size:0.82rem;color:var(--text-muted);margin-bottom:12px">📦 ${availInfo}</p>
                ${scaleLiveSection}
                <div class="form-group">
                    <label>📍 ${t('recipes.from_where_label')}</label>
                    <div class="location-selector">${locButtons}</div>
                    <input type="hidden" id="ruse-location" value="${defaultLoc}">
                </div>
                <div class="form-group">
                    <label>${t('recipes.amount_label')}?</label>
                    ${qtySection}
                    <small id="ruse-scale-hint" style="display:none; color: var(--color-accent, #7c3aed); margin-top:4px"></small>
                </div>
                <button type="button" id="btn-ruse-submit" class="btn btn-large btn-danger full-width move-countdown-btn" onclick="submitRecipeUse(false)" style="margin-top:8px">
                    📤 ${t('recipes.use_amount_btn')}
                </button>
                <button type="button" class="btn btn-large btn-secondary full-width" style="margin-top:8px" onclick="submitRecipeUse(true)">
                    🗑️ ${t('recipes.use_all_btn')}
                </button>
            </div>
        `;
        document.getElementById('modal-overlay').style.display = 'flex';
        syncRecipeUseQtyUnitBadge();
        
    } catch (err) {
        console.error('useRecipeIngredient error:', err);
        showToast(t('recipes.load_error'), 'error');
    }
}

function selectRecipeUseLoc(btn, loc) {
    btn.parentElement.querySelectorAll('.loc-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('ruse-location').value = loc;
}

function switchRecipeUseUnit(mode) {
    if (!_recipeUseConfMode) return;
    const subBtn = document.getElementById('ruse-unit-sub');
    const confBtn = document.getElementById('ruse-unit-conf');
    const qtyInput = document.getElementById('ruse-quantity');
    const hint = document.getElementById('ruse-hint');
    
    if (mode === 'sub') {
        subBtn.classList.add('active');
        confBtn.classList.remove('active');
        _recipeUseConfMode._activeUnit = 'sub';
        const step = getSubUnitStep(_recipeUseConfMode.packageUnit);
        qtyInput.value = _recipeUseContext.qtyNumber || step;
        qtyInput.step = step;
        qtyInput.min = step;
        hint.textContent = t('recipes.quantity_in_total').replace('{unit}', _recipeUseConfMode.subLabel).replace('{total}', Math.round(_recipeUseConfMode.totalSub) + _recipeUseConfMode.subLabel);
    } else {
        confBtn.classList.add('active');
        subBtn.classList.remove('active');
        _recipeUseConfMode._activeUnit = 'conf';
        qtyInput.value = 1;
        qtyInput.step = 0.5;
        qtyInput.min = 0.5;
        hint.textContent = t('recipes.packs_of_have').replace('{size}', `${_recipeUseConfMode.packageSize}${_recipeUseConfMode.subLabel}`).replace('{count}', _recipeUseConfMode.totalConf.toFixed(1));
    }
    syncRecipeUseQtyUnitBadge();
}

function adjustRecipeUseQty(direction) {
    const input = document.getElementById('ruse-quantity');
    let val = parseFloat(input.value) || 0;
    let step;
    if (_recipeUseConfMode && _recipeUseConfMode._activeUnit === 'sub') {
        step = getSubUnitStep(_recipeUseConfMode.packageUnit);
    } else if (_recipeUseConfMode && _recipeUseConfMode._activeUnit === 'conf') {
        step = 0.5;
    } else {
        const u = _recipeUseNormalUnit || 'pz';
        if (u === 'g' || u === 'ml') {
            step = val < 50 ? 1 : (val < 500 ? 10 : 50);
        } else {
            step = 1;
        }
    }
    val = Math.max(step, val + direction * step);
    input.value = Math.round(val * 1000) / 1000;
}

async function submitRecipeUse(useAll) {
    if (!_recipeUseContext) return;
    const { idx, productId, btn } = _recipeUseContext;
    const location = document.getElementById('ruse-location').value;
    const cachedItems = _recipeUseContext.items || [];
    const locItems = cachedItems.filter(i => i.location === location && parseFloat(i.quantity) > 0);
    const unit = cachedItems[0]?.unit || 'pz';
    const pkgSize = parseFloat(cachedItems[0]?.default_quantity) || 0;
    const pkgUnit = cachedItems[0]?.package_unit || '';
    
    let qty;
    if (useAll) {
        qty = locItems.reduce((s, i) => s + parseFloat(i.quantity || 0), 0) || 0;
        if (qty <= 0) {
            qty = parseFloat(document.getElementById('ruse-quantity').value) || 1;
        }
    } else {
        qty = parseFloat(document.getElementById('ruse-quantity').value) || 1;
        if (_recipeUseConfMode && _recipeUseConfMode._activeUnit === 'sub') {
            qty = qty / _recipeUseConfMode.packageSize;
        }
    }

    const totalAtLoc = locItems.reduce((s, i) => s + parseFloat(i.quantity || 0), 0);
    const consumesAllAtLoc = totalAtLoc > 0 && qty >= totalAtLoc * 0.999;
    if (useAll || consumesAllAtLoc) {
        const locInfo = LOCATIONS[location] || { label: location };
        const qtyLabel = stripHtml(formatQuantity(qty, unit, pkgSize, pkgUnit));
        const confirmed = await new Promise(resolve => {
            document.getElementById('modal-content').innerHTML = `
                <div class="modal-header">
                    <h3>${escapeHtml(t('recipes.use_all_stock_confirm_title'))}</h3>
                    <button type="button" class="modal-close" onclick="closeModal()">✕</button>
                </div>
                <p style="margin:12px 0 18px;color:var(--text-muted)">${escapeHtml(t('recipes.use_all_stock_confirm_msg', { qty: qtyLabel, unit, location: locInfo.label }))}</p>
                <div style="display:flex;flex-direction:column;gap:10px">
                    <button type="button" class="btn btn-large btn-danger full-width" id="ruse-stock-confirm">${escapeHtml(t('recipes.use_all_stock_confirm_btn'))}</button>
                    <button type="button" class="btn btn-secondary full-width" id="ruse-stock-cancel">${escapeHtml(t('confirm.cancel'))}</button>
                </div>`;
            document.getElementById('modal-overlay').style.display = 'flex';
            document.getElementById('ruse-stock-confirm').onclick = () => { closeModal(); resolve(true); };
            document.getElementById('ruse-stock-cancel').onclick = () => { closeModal(); resolve(false); };
        });
        if (!confirmed) return;
    }
    
    closeModal();
    btn.disabled = true;
    btn.textContent = '⏳...';
    
    try {
        const recipeTitle = _cachedRecipe?.recipe?.title || '';
        const result = await api('inventory_use', {}, 'POST', {
            product_id: productId,
            quantity: qty,
            location: location,
            notes: recipeTitle ? `Ricetta: ${recipeTitle}` : '',
        });
        
        if (result.success) {
            const li = document.getElementById(`recipe-ing-${idx}`);
            if (li) li.classList.add('recipe-ing-used');
            btn.textContent = t('cooking.ingredient_used');
            btn.classList.add('btn-used');
            
            if (_cachedRecipe && _cachedRecipe.recipe && _cachedRecipe.recipe.ingredients && _cachedRecipe.recipe.ingredients[idx]) {
                _cachedRecipe.recipe.ingredients[idx].used = true;
                saveRecipeToArchive(_cachedRecipe.recipe);
            }
            
            showToast(t('recipes.ingredient_scaled_toast'), 'success');
            if (result.location_fallback && result.requested_location && result.used_location) {
                const usedLoc = LOCATIONS[result.used_location] || { label: result.used_location };
                const reqLoc = LOCATIONS[result.requested_location] || { label: result.requested_location };
                setTimeout(() => showToast(
                    t('recipes.location_fallback_toast', { location: usedLoc.label, requested: reqLoc.label }),
                    'info'
                ), 800);
            }
            if (result.added_to_bring) {
                setTimeout(() => showToast(t('recipes.finished_added_bring_toast'), 'info'), 1500);
            }
            
            const usedLocation = result.used_location || location;
            const moveCallback = result.remaining > 0
                ? () => setTimeout(() => {
                    const itemAtLoc = cachedItems.find(i => i.location === usedLocation);
                    const wasVacuum = !!(itemAtLoc?.vacuum_sealed);
                    showRecipeMoveModal(productId, usedLocation, result.remaining, result.opened_id, wasVacuum);
                  }, 300)
                : null;
            setTimeout(() => showLowStockBringPrompt(result, moveCallback), 300);
        } else {
            btn.disabled = false;
            btn.textContent = t('cooking.ingredient_use_btn');
            showToast(result.error || t('error.generic'), 'error');
        }
    } catch (err) {
        console.error('Recipe use error:', err);
        btn.disabled = false;
        btn.textContent = t('cooking.ingredient_use_btn');
        showToast(t('error.connection'), 'error');
    }
    _recipeUseContext = null;
}

function showRecipeMoveModal(productId, fromLoc, remaining, openedId, wasVacuum) {
    // Set context for recording the choice
    _pendingMoveCtx = { productId, fromLoc, openedId };

    // If a preference exists, skip the modal entirely
    const prefMoveLoc = _getPreferredMoveLoc(productId, fromLoc);
    if (prefMoveLoc) {
        if (prefMoveLoc === fromLoc) {
            closeModal();
        } else {
            confirmRecipeMove(productId, fromLoc, prefMoveLoc, openedId, wasVacuum);
        }
        _pendingMoveCtx = null;
        return;
    }

    const otherLocs = Object.entries(LOCATIONS).filter(([k]) => k !== fromLoc);
    const locButtons = otherLocs.map(([k, v]) =>
        `<button type="button" class="loc-btn" onclick="clearMoveModalTimer();confirmRecipeMove(${productId}, '${fromLoc}', '${k}', ${openedId || 0})">${v.icon} ${v.label}</button>`
    ).join('');
    const vacuumRow = `
        <label style="display:flex;align-items:center;gap:8px;margin-top:12px;cursor:pointer">
            <input type="checkbox" id="move-vacuum-check" ${wasVacuum ? 'checked' : ''}>
            <span>${wasVacuum ? t('move.vacuum_restore') : t('move.vacuum_seal_rest')}</span>
        </label>`;
    document.getElementById('modal-content').innerHTML = `
        <div class="modal-header">
            <h3>${t('move.title')}</h3>
            <button class="modal-close" onclick="clearMoveModalTimer();closeModal()">✕</button>
        </div>
        <div style="padding:0 16px 16px">
            <p style="margin-bottom:12px">${t('move.question_short').replace('{thing}', openedId ? t('move.thing_opened') : t('move.thing_rest'))}</p>
            <div class="location-selector">${locButtons}</div>
            ${vacuumRow}
            <button type="button" id="btn-move-stay" class="btn btn-secondary full-width move-countdown-btn" style="margin-top:12px" onclick="clearMoveModalTimer();_recipeMoveCancelStay(${productId}, '${fromLoc}', ${openedId || 0})">${t('move.stay_btn').replace('{location}', LOCATIONS[fromLoc]?.label || fromLoc)}</button>
        </div>
    `;
    document.getElementById('modal-overlay').style.display = 'flex';
    startMoveModalCountdown('btn-move-stay', () => { _recipeMoveCancelStay(productId, fromLoc, openedId || 0); });
}

function _recipeMoveCancelStay(productId, fromLoc, openedId) {
    _recordMoveLocChoice(productId, fromLoc, fromLoc);
    _pendingMoveCtx = null;
    closeModal();
}

async function confirmRecipeMove(productId, fromLoc, toLoc, openedId, forcedVacuum) {
    clearMoveModalTimer();
    _recordMoveLocChoice(productId, fromLoc, toLoc);
    _pendingMoveCtx = null;
    const newVacuum = forcedVacuum !== undefined ? (forcedVacuum ? 1 : 0) : (document.getElementById('move-vacuum-check')?.checked ? 1 : 0);
    closeModal();
    try {
        const invData = await api('inventory_list');
        const invRows = invData.inventory || [];
        if (openedId) {
            const item = invRows.find(i => i.id == openedId);
            const product = { name: item?.name || '', category: item?.category || '' };
            const payload = {
                id: openedId,
                location: toLoc,
                product_id: productId,
                vacuum_sealed: newVacuum,
            };
            if (!item?.expiry_user_set) {
                let days = estimateExpiryDays(product, toLoc);
                if (newVacuum) days = getVacuumExpiryDays(days);
                payload.expiry_date = addDays(days);
            }
            await api('inventory_update', {}, 'POST', payload);
        } else {
            const item = invRows.find(i => i.product_id == productId && i.location === fromLoc && parseFloat(i.quantity) > 0);
            if (item) {
                const payload = {
                    id: item.id,
                    location: toLoc,
                    product_id: productId,
                    vacuum_sealed: newVacuum,
                };
                if (!item.expiry_user_set) {
                    let days = estimateExpiryDays({ name: item.name || '', category: item.category || '' }, toLoc);
                    if (newVacuum) days = getVacuumExpiryDays(days);
                    payload.expiry_date = addDays(days);
                }
                await api('inventory_update', {}, 'POST', payload);
            }
        }
        showToast(t('move.moved_simple', { location: LOCATIONS[toLoc]?.label || toLoc }), 'success');
    } catch (e) {
        console.error('Recipe move error:', e);
    }
}

/**
 * Extract tools/appliances from recipe steps text when tools_needed is absent (old cached recipes).
 * Returns an array of localised tool names found in the steps.
 */
function _extractToolsFromSteps(steps) {
    const text = (steps || []).join(' ').toLowerCase();
    // Map: regex keyword → display name per language
    const patterns = [
        { re: /\bforn[oi]\b|oven|backofen/,            it: 'Forno',           en: 'Oven',              de: 'Backofen' },
        { re: /\bmicroond[ea]\b|microwave|mikrowelle/,  it: 'Microonde',       en: 'Microwave',         de: 'Mikrowelle' },
        { re: /\bfrullator[ei]\b|blender|mixer\b|pimer|frullatore a immersione|stabmixer/,
                                                        it: 'Frullatore',      en: 'Blender',           de: 'Mixer' },
        { re: /\bfritteuse\b|friggitrici[ae]\b|air\s*fry|friggitric[ae]\b|friggi\b/, it: 'Friggitrice', en: 'Air fryer', de: 'Fritteuse' },
        { re: /\bpentola\s+a\s+pressione\b|pressure\s+cook|schnellkochtopf|cookeo|instant\s*pot/, it: 'Pentola a pressione', en: 'Pressure cooker', de: 'Schnellkochtopf' },
        { re: /\bbimby\b|thermomix\b|monsieur\s+cuisine/,it: 'Bimby/Thermomix', en: 'Thermomix',        de: 'Thermomix' },
        { re: /\bimpastatric[ae]\b|planetari[ao]\b|stand\s*mixer|knetmaschine/, it: 'Impastatrice', en: 'Stand mixer', de: 'Knetmaschine' },
        { re: /\bvapore\b|steamer\b|dampfgarer\b/,      it: 'Vaporiera',       en: 'Steamer',           de: 'Dampfgarer' },
        { re: /\bslow\s*cook|cottura\s+lenta\b|schongarer/, it: 'Slow cooker', en: 'Slow cooker',       de: 'Schongarer' },
        { re: /\bgrill[eo]?\b|griglia\b|grillpfanne/,   it: 'Griglia',         en: 'Grill',             de: 'Grill' },
        { re: /\bmacchina\s+del\s+pane\b|bread\s*machine|brotbackautomat/, it: 'Macchina del pane', en: 'Bread machine', de: 'Brotbackautomat' },
        { re: /\bessiccator[ei]\b|dehydrator\b|dörrgerät/, it: 'Essiccatore',  en: 'Dehydrator',        de: 'Dörrgerät' },
    ];
    const lang = _currentLang || 'it';
    const found = [];
    for (const p of patterns) {
        if (p.re.test(text)) found.push(p[lang] || p.it);
    }
    return found;
}

// ===== RECIPE FAVORITES & PORTION RESCALER =====
let _recipeBasePersons = 1;
let _recipeCurrentPersons = 1;

/**
 * Toggle favorite status for the currently displayed archived recipe (#124).
 */
async function toggleRecipeFavorite(btn) {
    if (!_cachedRecipe || !_cachedRecipe.id) return;
    const res = await api('recipes_toggle_favorite', {}, 'POST', { id: _cachedRecipe.id });
    if (!res.success) return;
    _cachedRecipe.is_favorite = res.is_favorite;
    btn.classList.toggle('active', res.is_favorite);
    btn.textContent = res.is_favorite ? '★' : '☆';
    btn.title = res.is_favorite ? t('recipes.unfavorite') : t('recipes.favorite');
    // Invalidate archive cache so the star shows on next open
    _recipeArchiveCache = null;
}

/**
 * Scale recipe ingredient quantities (#123).
 * Delta: +1 or -1. Min 1, max 20 persons.
 */
function scaleRecipePersons(delta) {
    const newPersons = Math.max(1, Math.min(20, _recipeCurrentPersons + delta));
    if (newPersons === _recipeCurrentPersons) return;
    _recipeCurrentPersons = newPersons;
    const display = document.getElementById('recipe-persons-display');
    if (display) display.textContent = `👥 ${newPersons} ${t('recipes.persons_short')}`;

    const ratio = _recipeBasePersons > 0 ? (newPersons / _recipeBasePersons) : 1;
    document.querySelectorAll('#recipe-content .recipe-ingredient').forEach(li => {
        const baseQty   = parseFloat(li.dataset.baseQty || '0');
        const baseStr   = li.dataset.baseQtyStr || '';
        const qtySpan   = li.querySelector('.recipe-ing-qty');
        if (!qtySpan) return;

        if (baseQty > 0) {
            // Extract unit suffix from baseStr: e.g. "200 g" → "g", "2 uova" → "uova"
            const m = baseStr.match(/^(\d+(?:[.,]\d+)?)\s*(.*)/);
            const unitSuffix = m ? m[2].trim() : '';
            const scaled = baseQty * ratio;
            // Round sensibly: integers for whole counts, 1 decimal for fractional
            const rounded = scaled < 10 ? (Math.round(scaled * 10) / 10) : Math.round(scaled);
            qtySpan.textContent = unitSuffix ? `${rounded} ${unitSuffix}` : String(rounded);
        }
    });
    _updateRecipeStockHintsAfterScale(ratio);
}

async function addRecipeShoppingSuggestions() {
    const items = (_recipeShoppingSuggestions || []).filter(s => s && s.name);
    if (!items.length) return;
    try {
        const payload = {
            items: items.map(s => ({
                name: s.name,
                specification: s.qty ? `Da ricetta · ${s.qty}` : 'Da ricetta',
            })),
            listUUID: typeof shoppingListUUID !== 'undefined' ? shoppingListUUID : undefined,
        };
        const data = await api('shopping_add', {}, 'POST', payload);
        if (data.success) {
            showToast(t('recipes.shopping_suggestions_added'), 'success');
            if (typeof loadShoppingCount === 'function') loadShoppingCount();
        } else {
            showToast(data.error || t('error.bring_add'), 'error');
        }
    } catch (e) {
        console.error('addRecipeShoppingSuggestions:', e);
        showToast(t('error.bring_add'), 'error');
    }
}

async function renderRecipe(r) {
    await enrichRecipeIngredientsStock(r);
    // Reset regen choice panel (hide choice, show button)
    const regenChoice = document.getElementById('recipe-regen-choice');
    const regenBtn = document.getElementById('recipe-regen-btn');
    if (regenChoice) regenChoice.style.display = 'none';
    if (regenBtn) regenBtn.style.display = '';

    // Store base persons for the rescaler (#123)
    _recipeBasePersons = r.persons || 1;
    _recipeCurrentPersons = _recipeBasePersons;

    const isFav = !!(_cachedRecipe && _cachedRecipe.is_favorite);

    let html = `<h2>${escapeHtml(r.title)}</h2>`;

    // Meta tags + star (#124) + persons rescaler (#123)
    html += '<div class="recipe-meta">';
    if (r.meal) html += `<span class="recipe-tag">${_mealLabel(r.meal)}</span>`;
    html += `<span class="recipe-tag recipe-persons-ctrl">
        <button class="btn-persons-adj" onclick="scaleRecipePersons(-1)">−</button>
        <span id="recipe-persons-display">👥 ${r.persons} ${t('recipes.persons_short')}</span>
        <button class="btn-persons-adj" onclick="scaleRecipePersons(+1)">+</button>
    </span>`;
    if (r.prep_time) html += `<span class="recipe-tag">🔪 ${r.prep_time}</span>`;
    if (r.cook_time) html += `<span class="recipe-tag">🔥 ${r.cook_time}</span>`;
    if (r.tags) r.tags.forEach(tag => { html += `<span class="recipe-tag">${escapeHtml(tag)}</span>`; });
    // Favorite star button (#124) — visible only for archived recipes (have an id)
    if (_cachedRecipe && _cachedRecipe.id) {
        html += `<button class="btn-recipe-fav${isFav ? ' active' : ''}" onclick="toggleRecipeFavorite(this)" title="${isFav ? t('recipes.unfavorite') : t('recipes.favorite')}">${isFav ? '★' : '☆'}</button>`;
    }
    html += '</div>';

    // Expiry note
    if (r.expiry_note) {
        html += `<div class="recipe-expiry-note">⚠️ ${escapeHtml(r.expiry_note)}</div>`;
    }

    // Tools/appliances banner (shown only when specific equipment is needed)
    const tools = (r.tools_needed && r.tools_needed.length > 0)
        ? r.tools_needed.filter(t => t && t.trim())
        : _extractToolsFromSteps(r.steps);
    if (tools.length > 0) {
        html += `<div class="recipe-tools-banner">🔧 <strong>${escapeHtml(t('recipes.tools_title'))}:</strong> ${tools.map(tool => `<span class="recipe-tool-chip">${escapeHtml(tool)}</span>`).join('')}</div>`;
    }

    // Optional shopping suggestions (ingredients removed because not in pantry)
    const shopSug = r.shopping_suggestions || [];
    if (shopSug.length > 0) {
        const items = shopSug.map(s => `<li><strong>${escapeHtml(s.name)}</strong>${s.qty ? ': ' + escapeHtml(s.qty) : ''}</li>`).join('');
        html += `<div class="recipe-shopping-suggestions" id="recipe-shopping-suggestions">
            <p>🛒 ${escapeHtml(t('recipes.shopping_suggestions_intro'))}</p>
            <ul>${items}</ul>
            <button type="button" class="btn btn-sm btn-success" onclick="addRecipeShoppingSuggestions()">${escapeHtml(t('recipes.shopping_suggestions_add'))}</button>
        </div>`;
        _recipeShoppingSuggestions = shopSug;
    } else {
        _recipeShoppingSuggestions = [];
    }

    // Ingredients
    html += `<h3>${t('recipes.ingredients_title')}</h3><ul class="recipe-ingredients">`;
    (r.ingredients || []).forEach((ing, idx) => {
        if (ing.from_pantry && ing.product_id) {
            const loc = (ing.location || 'dispensa').replace(/'/g, "\\'");
            const alreadyUsed = ing.used === true;
            const qtyNum = Math.round((ing.qty_number || 0) * 10) / 10;
            html += `<li class="recipe-ingredient${alreadyUsed ? ' recipe-ing-used' : ''}" id="recipe-ing-${idx}" data-ing-idx="${idx}" data-base-qty="${ing.qty_number || 0}" data-base-qty-str="${escapeHtml(ing.qty || '')}">`;
            html += `<span class="recipe-ing-text"><strong class="recipe-ing-name" onclick="openIngredientDetail(${ing.product_id}, '${loc}')" title="${escapeHtml(t('btn.edit'))}">${escapeHtml(ing.name)}</strong>${ing.brand ? ' <em>(' + escapeHtml(ing.brand) + ')</em>' : ''}: <span class="recipe-ing-qty">${escapeHtml(ing.qty)}</span>${ing.use_all_suggested ? ' ♻️' : ''} ✅`;
            // Detail line: location + expiry
            let details = [];
            const ingredientLocLabels = Object.fromEntries(Object.entries(LOCATIONS).map(([k,v]) => [k, `${v.icon} ${v.label}`]));
            details.push(ingredientLocLabels[ing.location] || ('📍 ' + ing.location));
            if (ing.location === 'freezer') {
                details.push(t('recipes.frozen_badge') || '❄️ Surgelato');
            }
            if (ing.expiry_date) {
                const exp = new Date(ing.expiry_date);
                const now = new Date(); now.setHours(0,0,0,0);
                const diffDays = Math.round((exp - now) / 86400000);
                if (diffDays < 0) details.push(t('expiry.badge_expired_ago').replace('{n}', Math.abs(diffDays)));
                else if (diffDays <= 3) details.push(t('expiry.badge_expires_red').replace('{n}', diffDays));
                else if (diffDays <= 7) details.push(t('expiry.badge_expires_yellow').replace('{n}', diffDays));
                else details.push('📅 ' + exp.toLocaleDateString(_currentLang === 'de' ? 'de-DE' : _currentLang === 'en' ? 'en-GB' : 'it-IT'));
            }
            if (details.length) html += `<br><small class="recipe-ing-detail">${details.join(' · ')}</small>`;
            const stockLine = _recipeIngStockHintHtml(ing);
            if (stockLine) html += `<br><small class="recipe-ing-stock">${escapeHtml(stockLine)}</small>`;
            html += `</span>`;
            if (alreadyUsed) {
                html += `<button class="btn-use-ingredient btn-used" disabled>${t('cooking.ingredient_used')}</button>`;
            } else {
                html += `<button class="btn-use-ingredient" onclick="useRecipeIngredient(${idx}, ${ing.product_id}, '${loc}', ${qtyNum}, this, '${(ing.qty || '').replace(/'/g, "&apos;")}')" title="${t('cooking.ingredient_deduct_title')}">${t('cooking.ingredient_use_btn')}</button>`;
            }
            html += `</li>`;
        }
        // Non-pantry ingredients are stripped server-side; nothing to render here.
    });
    html += '</ul>';

    // Cooking mode action between ingredients and steps
    html += `<button class="btn btn-large btn-cooking full-width mt-2" onclick="startCookingMode()">${t('recipes.start_cooking')}</button>`;

    // Steps
    html += `<h3>${t('recipes.steps_title')}</h3><ol>`;
    (r.steps || []).forEach(step => {
        const appliance = _stepAppliance(step);
        html += `<li>${escapeHtml(_stepStr(step))}${appliance ? ` <span class="recipe-step-appliance">${escapeHtml(appliance)}</span>` : ''}</li>`;
    });
    html += '</ol>';

    // Nutritional values grid
    if (r.nutrition && (r.nutrition.kcal || r.nutrition.protein_g || r.nutrition.carbs_g || r.nutrition.fat_g)) {
        const n = r.nutrition;
        html += `<div class="recipe-nutrition-block">
            <h4 class="recipe-section-heading">📊 ${t('recipes.nutrition_title')}</h4>
            <div class="recipe-nutrition-grid">
                <div class="recipe-nutrition-item">
                    <span class="recipe-nutrition-icon">🔥</span>
                    <span class="recipe-nutrition-value">${n.kcal ?? '—'}</span>
                    <span class="recipe-nutrition-label">${t('recipes.nutrition_kcal')}</span>
                </div>
                <div class="recipe-nutrition-item">
                    <span class="recipe-nutrition-icon">🥩</span>
                    <span class="recipe-nutrition-value">${n.protein_g ?? '—'} g</span>
                    <span class="recipe-nutrition-label">${t('recipes.nutrition_protein')}</span>
                </div>
                <div class="recipe-nutrition-item">
                    <span class="recipe-nutrition-icon">🍞</span>
                    <span class="recipe-nutrition-value">${n.carbs_g ?? '—'} g</span>
                    <span class="recipe-nutrition-label">${t('recipes.nutrition_carbs')}</span>
                </div>
                <div class="recipe-nutrition-item">
                    <span class="recipe-nutrition-icon">🫒</span>
                    <span class="recipe-nutrition-value">${n.fat_g ?? '—'} g</span>
                    <span class="recipe-nutrition-label">${t('recipes.nutrition_fat')}</span>
                </div>
            </div>
            <p class="recipe-nutrition-note">${t('recipes.nutrition_per_serving')}</p>
        </div>`;
    }

    // Storage info
    if (r.storage && (r.storage.where || r.storage.tips)) {
        const s = r.storage;
        const daysLabel = s.days > 0
            ? t('recipes.storage_days').replace('{n}', s.days)
            : t('recipes.storage_immediately');
        html += `<div class="recipe-storage-card">
            <h4 class="recipe-section-heading">📦 ${t('recipes.storage_title')}</h4>
            <div class="recipe-storage-row">
                ${s.where ? `<span class="recipe-storage-badge">${escapeHtml(s.where)}</span>` : ''}
                ${s.days > 0 ? `<span class="recipe-storage-badge recipe-storage-days">${escapeHtml(daysLabel)}</span>` : `<span class="recipe-storage-badge recipe-storage-now">${escapeHtml(daysLabel)}</span>`}
            </div>
            ${s.tips ? `<p class="recipe-storage-tips">${escapeHtml(s.tips)}</p>` : ''}
        </div>`;
    }

    // Nutrition note (legacy / AI extra note)
    if (r.nutrition_note) {
        html += `<p class="recipe-nutrition-footnote">💡 ${escapeHtml(r.nutrition_note)}</p>`;
    }

    document.getElementById('recipe-content').innerHTML = html;
}

// ===== COOKING MODE =====
let _cookingRecipe = null;
let _cookingStep = 0;
let _cookingTTS = true;
let _cookingVisited = new Set(); // indices of steps already seen

// Safely extract step text regardless of whether it's a string or an object.
// Also handles JSON-encoded step objects emitted by older AI generations
// (e.g. {"instruction":"…","appliance_function":"…"}).
const _stepStr = s => {
    if (typeof s === 'string' && s.trimStart().startsWith('{')) {
        try { s = JSON.parse(s); } catch(e) {}
    }
    const text = (s !== null && typeof s === 'object')
        ? (s.instruction ?? s.text ?? s.description ?? s.step ?? '')
        : (s ?? '');
    return String(text).replace(/^Passo\s*\d+\s*[:.]\s*/i, '').replace(/^Step\s*\d+\s*[:.]\s*/i, '');
};
// Returns the appliance/function hint for a step, or null if absent/Nessuno.
const _stepAppliance = s => {
    if (typeof s === 'string' && s.trimStart().startsWith('{')) {
        try { s = JSON.parse(s); } catch(e) {}
    }
    if (s !== null && typeof s === 'object' && s.appliance_function) {
        const a = s.appliance_function.trim();
        if (a && a.toLowerCase() !== 'nessuno' && a.toLowerCase() !== 'none') return a;
    }
    return null;
};

let _cookingWheelBound = false;
let _cookingWheelTouchStartY = null;
let _cookingWheelLastNavTs = 0;
let _cookingWheelLastDelta = 0;
let _cookingWheelTiltResetTimer = null;

function _layoutCookingWheelCards() {
    const wheelEl = document.getElementById('cooking-wheel');
    const centerEl = document.getElementById('cooking-step-text');
    const prevEl = document.getElementById('cooking-step-prev');
    const nextEl = document.getElementById('cooking-step-next');
    if (!wheelEl || !centerEl || !prevEl || !nextEl) return;

    const wheelH = wheelEl.clientHeight;
    if (!wheelH) return;
    const centerH = centerEl.offsetHeight;
    const centerTop = Math.max(0, (wheelH - centerH) / 2);
    const centerBottom = centerTop + centerH;
    const pad = 8;
    const gap = Math.max(10, Math.round(wheelH * 0.045));

    const placeGhost = (el, isPrev) => {
        el.style.bottom = 'auto';

        if (el.classList.contains('is-empty')) {
            el.style.maxHeight = '0px';
            return;
        }

        // Measure natural height before clamping to available slot.
        el.style.maxHeight = 'none';
        const naturalH = Math.min(el.scrollHeight + 10, Math.round(wheelH * 0.42));

        const available = isPrev
            ? (centerTop - gap - pad)
            : (wheelH - centerBottom - gap - pad);

        if (available <= 20) {
            el.style.maxHeight = '0px';
            el.style.opacity = '0';
            return;
        }

        const ghostH = Math.max(28, Math.min(naturalH, available));
        el.style.maxHeight = `${Math.round(ghostH)}px`;
        el.style.opacity = '';

        const top = isPrev
            ? Math.max(pad, centerTop - gap - ghostH)
            : Math.min(wheelH - pad - ghostH, centerBottom + gap);
        el.style.top = `${Math.round(top)}px`;
    };

    placeGhost(prevEl, true);
    placeGhost(nextEl, false);
}

function startCookingMode() {
    const recipe = _cachedRecipe && _cachedRecipe.recipe ? _cachedRecipe.recipe : null;
    if (!recipe || !(recipe.steps || []).length) {
        showToast(t('recipes.no_steps'), 'info');
        return;
    }
    // Resume if same recipe; otherwise start fresh
    const isSame = _cookingRecipe && _cookingRecipe.title === recipe.title;
    if (!isSame) {
        _cookingRecipe = JSON.parse(JSON.stringify(recipe));
        _cookingStep = 0;
        _cookingVisited = new Set();
        _dismissedZeroWasteTips = new Set();
        clearAllCookingTimers();
    }
    _cookingTTS = true;
    document.getElementById('cooking-title').textContent = _cookingRecipe.title || '';
    document.getElementById('cooking-tts-btn').textContent = '🔊';
    // Unlock the AudioContext now while we have a user gesture (the Start button tap)
    _ensureAudioUnlocked();
    // Tools bar
    const toolsBar = document.getElementById('cooking-tools-bar');
    if (toolsBar) {
        const tools = (_cookingRecipe.tools_needed && _cookingRecipe.tools_needed.length > 0)
            ? _cookingRecipe.tools_needed.filter(t => t && t.trim())
            : _extractToolsFromSteps(_cookingRecipe.steps);
        if (tools.length > 0) {
            toolsBar.innerHTML = '🔧 ' + tools.map(t => `<span class="cooking-tool-chip">${t}</span>`).join('');
            toolsBar.style.display = '';
        } else {
            toolsBar.style.display = 'none';
            toolsBar.innerHTML = '';
        }
    }
    document.getElementById('cooking-overlay').style.display = 'flex';
    document.body.classList.add('cooking-mode-active');
    // Hide kiosk overlay — it lives outside <body> with z-index:2147483647 and would overlap cooking UI
    const _kioskOvl = document.getElementById('_kiosk_overlay');
    if (_kioskOvl) _kioskOvl.style.display = 'none';
    _bindCookingWheelControls();
    const wheelEl = document.getElementById('cooking-wheel');
    if (wheelEl) setTimeout(() => wheelEl.focus(), 20);
    try { screen.orientation?.lock('portrait').catch(() => {}); } catch (_) { /* ignore */ }
    renderCookingStep();
    if (_cookingTTS) {
        const text = _stepStr((_cookingRecipe.steps || [])[_cookingStep]);
        speakCookingStep(text);
    }
}
function closeCookingMode() {
    document.getElementById('cooking-overlay').style.display = 'none';
    document.body.classList.remove('cooking-mode-active');
    // Restore kiosk overlay
    const _kioskOvl = document.getElementById('_kiosk_overlay');
    if (_kioskOvl) _kioskOvl.style.display = 'flex';
    // NOTE: intentionally keep _cookingRecipe, _cookingStep, _cookingVisited
    // so the user can resume from the same step when they reopen
    try { screen.orientation?.unlock().catch(() => {}); } catch (_) { /* ignore */ }
}

function restartCookingMode() {
    _cookingStep = 0;
    _cookingWheelLastDelta = 0;
    _cookingVisited = new Set();
    _dismissedZeroWasteTips = new Set();
    clearAllCookingTimers();
    renderCookingStep();
}

function _setCookingWheelTilt(clientX, clientY) {
    const wheelEl = document.getElementById('cooking-wheel');
    if (!wheelEl) return;
    const rect = wheelEl.getBoundingClientRect();
    if (!rect.width || !rect.height) return;

    const nx = ((clientX - rect.left) / rect.width) - 0.5;
    const ny = ((clientY - rect.top) / rect.height) - 0.5;
    const tiltY = Math.max(-1, Math.min(1, nx)) * 7;
    const tiltX = Math.max(-1, Math.min(1, -ny)) * 4;
    const glow = 0.32 + (Math.min(1, Math.abs(nx) + Math.abs(ny)) * 0.45);

    wheelEl.style.setProperty('--wheel-tilt-x', `${tiltX.toFixed(2)}deg`);
    wheelEl.style.setProperty('--wheel-tilt-y', `${tiltY.toFixed(2)}deg`);
    wheelEl.style.setProperty('--wheel-glow', glow.toFixed(2));
}

function _resetCookingWheelTilt() {
    const wheelEl = document.getElementById('cooking-wheel');
    if (!wheelEl) return;
    wheelEl.style.setProperty('--wheel-tilt-x', '0deg');
    wheelEl.style.setProperty('--wheel-tilt-y', '0deg');
    wheelEl.style.setProperty('--wheel-glow', '0.45');
}

function _pulseCookingWheel() {
    const wheelEl = document.getElementById('cooking-wheel');
    if (!wheelEl) return;
    wheelEl.classList.remove('snap');
    void wheelEl.offsetWidth;
    wheelEl.classList.add('snap');
    setTimeout(() => wheelEl.classList.remove('snap'), 320);
}

function _cookingStepFeedback() {
    _pulseCookingWheel();
    if (navigator.vibrate) {
        try { navigator.vibrate([10, 16, 10]); } catch (_) { /* ignore */ }
    }
}

function _bindCookingWheelControls() {
    const wheelEl = document.getElementById('cooking-wheel');
    if (!wheelEl || _cookingWheelBound) return;

    wheelEl.addEventListener('wheel', (e) => {
        if (!document.body.classList.contains('cooking-mode-active')) return;
        if (Math.abs(e.deltaY) < 8) return;
        e.preventDefault();
        const now = Date.now();
        if (now - _cookingWheelLastNavTs < 240) return;
        _cookingWheelLastNavTs = now;
        navigateCookingStep(e.deltaY > 0 ? 1 : -1);
    }, { passive: false });

    wheelEl.addEventListener('touchstart', (e) => {
        const t = e.touches && e.touches[0] ? e.touches[0] : null;
        _cookingWheelTouchStartY = t ? t.clientY : null;
        if (t) _setCookingWheelTilt(t.clientX, t.clientY);
    }, { passive: true });

    wheelEl.addEventListener('touchmove', (e) => {
        const t = e.touches && e.touches[0] ? e.touches[0] : null;
        if (t) _setCookingWheelTilt(t.clientX, t.clientY);
    }, { passive: true });

    wheelEl.addEventListener('touchend', (e) => {
        if (_cookingWheelTouchStartY === null) return;
        const endY = e.changedTouches && e.changedTouches[0] ? e.changedTouches[0].clientY : _cookingWheelTouchStartY;
        const delta = _cookingWheelTouchStartY - endY;
        _cookingWheelTouchStartY = null;
        if (Math.abs(delta) < 42) return;
        const now = Date.now();
        if (now - _cookingWheelLastNavTs < 240) return;
        _cookingWheelLastNavTs = now;
        navigateCookingStep(delta > 0 ? 1 : -1);
        if (_cookingWheelTiltResetTimer) clearTimeout(_cookingWheelTiltResetTimer);
        _cookingWheelTiltResetTimer = setTimeout(_resetCookingWheelTilt, 80);
    }, { passive: true });

    wheelEl.addEventListener('mousemove', (e) => {
        if (!document.body.classList.contains('cooking-mode-active')) return;
        _setCookingWheelTilt(e.clientX, e.clientY);
    });

    wheelEl.addEventListener('mouseleave', () => {
        _resetCookingWheelTilt();
    });

    window.addEventListener('resize', () => {
        if (!document.body.classList.contains('cooking-mode-active')) return;
        _layoutCookingWheelCards();
    });

    wheelEl.addEventListener('keydown', (e) => {
        if (!document.body.classList.contains('cooking-mode-active')) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            navigateCookingStep(1);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            navigateCookingStep(-1);
        }
    });

    _cookingWheelBound = true;
}

function _animateCookingWheelTransition() {
    const wheelEl = document.getElementById('cooking-wheel');
    if (!wheelEl) return;
    wheelEl.classList.remove('turn-next', 'turn-prev');
    if (_cookingWheelLastDelta === 0) return;

    // Force style recalculation so repeated class toggles retrigger CSS animation.
    void wheelEl.offsetWidth;
    wheelEl.classList.add(_cookingWheelLastDelta > 0 ? 'turn-next' : 'turn-prev');

    setTimeout(() => {
        wheelEl.classList.remove('turn-next', 'turn-prev');
    }, 380);
}

function renderCookingStep() {
    if (!_cookingRecipe) return;
    const steps = _cookingRecipe.steps || [];
    const step = steps[_cookingStep] || '';
    const cleanStep = _stepStr(step);
    const total = steps.length;

    // Mark current step as visited
    _cookingVisited.add(_cookingStep);

    document.getElementById('cooking-step-num').textContent = `${_cookingStep + 1} / ${total}`;
    document.getElementById('cooking-step-text').textContent = cleanStep;

    const prevEl = document.getElementById('cooking-step-prev');
    const nextEl = document.getElementById('cooking-step-next');
    if (prevEl) {
        if (_cookingStep > 0) {
            prevEl.textContent = _stepStr(steps[_cookingStep - 1]);
            prevEl.classList.remove('is-empty');
        } else {
            prevEl.textContent = '';
            prevEl.classList.add('is-empty');
        }
    }
    if (nextEl) {
        if (_cookingStep < total - 1) {
            nextEl.textContent = _stepStr(steps[_cookingStep + 1]);
            nextEl.classList.remove('is-empty');
        } else {
            nextEl.textContent = '';
            nextEl.classList.add('is-empty');
        }
    }
    requestAnimationFrame(_layoutCookingWheelCards);
    _animateCookingWheelTransition();
    _cookingWheelLastDelta = 0;

    // Progress dots
    const dotsEl = document.getElementById('cooking-progress-dots');
    if (dotsEl) {
        dotsEl.innerHTML = Array.from({ length: total }, (_, i) => {
            let cls = 'cprog-dot';
            if (i === _cookingStep) cls += ' current';
            else if (_cookingVisited.has(i)) cls += ' visited';
            return `<span class="${cls}"></span>`;
        }).join('');
    }

    // Ingredients are shown in the recipe view only, not in cooking mode.
    const ingsEl = document.getElementById('cooking-step-ings');
    if (ingsEl) {
        ingsEl.innerHTML = '';
        ingsEl.style.display = 'none';
    }

    // Navigation button states
    const prevBtn = document.getElementById('cooking-prev');
    const nextBtn = document.getElementById('cooking-next');
    prevBtn.disabled = _cookingStep === 0;
    nextBtn.textContent = _cookingStep === total - 1 ? t('cooking.finish') : t('cooking.next');

    // Timer: detect duration in step text and show suggestion
    setupCookingTimerSuggestion(cleanStep);

    // Zero-waste tip for this step
    _renderZeroWasteTip(_cookingStep);

    // TTS: auto-speak is handled by navigateCookingStep() and startCookingMode() callers.
    // Use replayCookingTTS() to re-read the current step manually ("Rileggi" button).
}

// ===== ZERO-WASTE TIPS =====
let _dismissedZeroWasteTips = new Set(); // dismissed tip indices for this cooking session

function _renderZeroWasteTip(stepIdx) {
    const tipEl = document.getElementById('cooking-zerowaste-tip');
    if (!tipEl) return;
    // Check setting
    const s = getSettings();
    if (!s.zerowaste_tips_enabled) { tipEl.style.display = 'none'; return; }
    // Already dismissed for this step in this session
    if (_dismissedZeroWasteTips.has(stepIdx)) { tipEl.style.display = 'none'; return; }
    // Find tip for current step
    const tips = (_cookingRecipe && _cookingRecipe.zero_waste_tips) || [];
    const tip = tips.find(t => t.step === stepIdx);
    if (!tip) { tipEl.style.display = 'none'; return; }
    // Populate and show
    const scrapEl = document.getElementById('cooking-zerowaste-scrap');
    const textEl  = document.getElementById('cooking-zerowaste-text');
    if (scrapEl) scrapEl.textContent = tip.scrap || '';
    if (textEl)  textEl.textContent  = tip.tip  || '';
    tipEl.style.display = 'flex';
}

function _dismissZeroWasteTip() {
    _dismissedZeroWasteTips.add(_cookingStep);
    const tipEl = document.getElementById('cooking-zerowaste-tip');
    if (tipEl) tipEl.style.display = 'none';
}

function _buildTtsRequest(text, s) {
    const url = s.tts_url || '';
    const method = s.tts_method || 'POST';
    const authType = s.tts_auth_type || 'bearer';
    const token = s.tts_token || '';
    const payloadKey = s.tts_payload_key || 'message';
    const contentType = s.tts_content_type || 'application/json';
    let extraFields = {};
    try { extraFields = JSON.parse(s.tts_extra_fields || '{}'); } catch(e) { /* invalid JSON, ignore */ }
    const headers = { 'Content-Type': contentType };
    if (authType === 'bearer' && token) {
        headers['Authorization'] = `Bearer ${token}`;
    } else if (authType === 'header' && s.tts_auth_header_name) {
        headers[s.tts_auth_header_name] = s.tts_auth_header_value || '';
    }
    const payload = { [payloadKey]: text, ...extraFields };
    let body;
    if (contentType === 'application/json') {
        body = JSON.stringify(payload);
    } else if (contentType === 'application/x-www-form-urlencoded') {
        body = new URLSearchParams(Object.entries(payload).map(([k, v]) => [k, String(v)])).toString();
    } else {
        body = text;
    }
    return { url, method, headers, body };
}

/**
 * Build a proxy request to call Home Assistant tts.speak service.
 * Requires HA URL, bearer token and entity_id (media player) in settings.
 */
function _buildHaTtsRequest(text, s) {
    const haUrl = (s.ha_url || '').replace(/\/$/, '');
    const url     = haUrl + '/api/services/tts/speak';
    const headers = { 'Content-Type': 'application/json' };
    const body = JSON.stringify({
        entity_id: s.ha_tts_entity || '',
        message: text,
    });
    return { url, method: 'POST', headers, body };
}

async function _ttsViaProxy(req) {
    // Route through server-side proxy to avoid mixed-content / CORS issues
    return fetch('api/index.php?action=tts_proxy', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-EverShelf-Request': '1',
            ...(typeof apiAuthHeaders === 'function' ? apiAuthHeaders() : {}),
        },
        body: JSON.stringify({
            url: req.url,
            method: req.method,
            headers: req.headers,
            payload: req.body
        })
    });
}

async function speakCookingStep(text) {
    if (!text) return;
    const s = getSettings();
    // Respect the user's explicit engine choice.
    // Do NOT gate on s.tts_enabled — the _cookingTTS toggle in cooking mode is the only gate.
    // If the preferred engine fails, always fall back to browser TTS.
    const fallback = () => _speakBrowser(text);
    try {
        // 1. Browser engine — always use Web Speech API / kiosk bridge directly
        if (!s.tts_engine || s.tts_engine === 'browser') {
            _speakBrowser(text);
        // 2. HA TTS — if HA is enabled and a media player entity is configured
        } else if (s.ha_enabled && s.ha_tts_entity && s.ha_url) {
            try {
                const req = _buildHaTtsRequest(text, s);
                await _ttsViaProxy(req);
            } catch(e) { fallback(); }
        // 3. Generic external endpoint ('server' or legacy 'custom' engine)
        } else if ((s.tts_engine === 'server' || s.tts_engine === 'custom') && s.tts_url) {
            try {
                const req = _buildTtsRequest(text, s);
                await _ttsViaProxy(req);
            } catch(e) { fallback(); }
        } else {
            _speakBrowser(text);
        }
    } catch(e) { /* silent — TTS is non-critical */ }
}

function replayCookingTTS() {
    if (!_cookingRecipe) return;
    const steps = _cookingRecipe.steps || [];
    const text = _stepStr(steps[_cookingStep]);
    if (text) speakCookingStep(text);
}

function onTtsAuthTypeChange(type) {
    const tokenGroup = document.getElementById('tts-token-group');
    const headerGroup = document.getElementById('tts-custom-header-group');
    if (tokenGroup) tokenGroup.style.display = type === 'bearer' ? '' : 'none';
    if (headerGroup) headerGroup.style.display = type === 'header' ? '' : 'none';
}

function onTtsEngineChange(engine) {
    const browserSect = document.getElementById('tts-browser-section');
    const serverSect = document.getElementById('tts-server-section');
    if (browserSect) browserSect.style.display = engine === 'browser' ? '' : 'none';
    if (serverSect) serverSect.style.display = (engine === 'server' || engine === 'custom') ? '' : 'none';
}

// ===== HOME ASSISTANT PANEL =====

function onHaEnabledChange() {
    const enabled = document.getElementById('setting-ha-enabled')?.checked;
    const cfg = document.getElementById('ha-config-section');
    if (cfg) cfg.style.display = enabled ? '' : 'none';
}

function _applyHaSettingsUI(s) {
    const haEnabled = document.getElementById('setting-ha-enabled');
    if (haEnabled) { haEnabled.checked = !!s.ha_enabled; onHaEnabledChange(); }
    const haUrl = document.getElementById('setting-ha-url');
    if (haUrl) haUrl.value = s.ha_url || '';
    // Never pre-fill token (write-only field); only show placeholder if already set
    const haTokenEl = document.getElementById('setting-ha-token');
    if (haTokenEl) haTokenEl.placeholder = s.ha_token_set ? '••••••••••••' : 'eyJhbGci...';
    const haEntity = document.getElementById('setting-ha-tts-entity');
    if (haEntity) haEntity.value = s.ha_tts_entity || '';
    const haWebhook = document.getElementById('setting-ha-webhook-id');
    if (haWebhook) haWebhook.value = s.ha_webhook_id || '';
    const haNotify = document.getElementById('setting-ha-notify-service');
    if (haNotify) haNotify.value = s.ha_notify_service || '';
    const haExpiry = document.getElementById('setting-ha-expiry-days');
    if (haExpiry) haExpiry.value = s.ha_expiry_days || 3;
    // Checkboxes for events
    const events = (s.ha_webhook_events || '').split(',').map(e => e.trim());
    const cbExpiry = document.getElementById('ha-event-expiry');
    if (cbExpiry) cbExpiry.checked = events.includes('expiry');
    const cbShopping = document.getElementById('ha-event-shopping');
    if (cbShopping) cbShopping.checked = events.includes('shopping_add');
    const cbStock = document.getElementById('ha-event-stock');
    if (cbStock) cbStock.checked = events.includes('stock_update');
}

function _loadHaTab() {
    const s = getSettings();
    _applyHaSettingsUI(s);
    _renderHaSensorYaml();
}

function _renderHaSensorYaml() {
    const el = document.getElementById('ha-sensor-yaml');
    if (!el) return;
    const base = (window.location.origin + window.location.pathname).replace(/\/$/, '').replace(/\/index\.html$/, '');
    el.textContent = `# Add to configuration.yaml (Home Assistant)
# Restart HA after editing.

sensor:
  - platform: rest
    name: "EverShelf Overview"
    unique_id: evershelf_overview
    resource: "${base}/api/?action=ha_sensor"
    scan_interval: 300
    value_template: "{{ value_json.state }}"
    json_attributes:
      - expiring_soon
      - expiring_3d
      - expired_items
      - total_items
      - shopping_items
      - expiring_list
      - expired_list
      - low_stock_list
      - next_expiry_name
      - next_expiry_date
      - days_to_next_expiry
      - last_updated
    unit_of_measurement: "items"
    device_class: null

  - platform: rest
    name: "EverShelf Expired Items"
    unique_id: evershelf_expired
    resource: "${base}/api/?action=ha_sensor&sensor=expired"
    scan_interval: 600
    value_template: "{{ value_json.state }}"
    unit_of_measurement: "items"

  - platform: rest
    name: "EverShelf Shopping Count"
    unique_id: evershelf_shopping
    resource: "${base}/api/?action=ha_sensor&sensor=shopping"
    scan_interval: 180
    value_template: "{{ value_json.state }}"
    unit_of_measurement: "items"

  # Full product inventory — all items with complete details
  - platform: rest
    name: "EverShelf Products"
    unique_id: evershelf_products
    resource: "${base}/api/?action=ha_sensor&sensor=product"
    scan_interval: 600
    value_template: "{{ value_json.state }}"
    json_attributes:
      - items
      - last_updated
    unit_of_measurement: "items"`;
}

function copyHaSensorYaml() {
    const el = document.getElementById('ha-sensor-yaml');
    if (!el) return;
    navigator.clipboard.writeText(el.textContent).then(() => {
        showToast(t('settings.ha.sensor_copied'));
    }).catch(() => {
        showToast(t('error.copy_failed'));
    });
}

async function testHaConnection() {
    const statusEl = document.getElementById('ha-test-status');
    const haUrl = document.getElementById('setting-ha-url')?.value.trim();
    const haToken = document.getElementById('setting-ha-token')?.value.trim();
    const s = getSettings();
    const tokenToUse = haToken || (s.ha_token_set ? '__server__' : '');

    if (!haUrl) {
        if (statusEl) { statusEl.style.display = 'block'; statusEl.className = 'settings-status error'; statusEl.textContent = t('settings.ha.error_no_url'); }
        return;
    }
    if (statusEl) { statusEl.style.display = 'block'; statusEl.className = 'settings-status'; statusEl.textContent = t('settings.ha.testing'); }

    try {
        const result = await api('ha_test', {}, 'POST', { url: haUrl, token: tokenToUse });
        if (result.ok) {
            if (statusEl) { statusEl.className = 'settings-status success'; statusEl.textContent = '✅ ' + t('settings.ha.test_ok').replace('{version}', result.version || 'HA'); }
        } else {
            if (statusEl) { statusEl.className = 'settings-status error'; statusEl.textContent = '❌ ' + t('settings.ha.test_fail').replace('{error}', result.error || result.http_code || ''); }
        }
    } catch(e) {
        if (statusEl) { statusEl.className = 'settings-status error'; statusEl.textContent = '❌ ' + e.message; }
    }
}

function applyHaTtsPreset() {
    const s = getSettings();
    const haUrl = (document.getElementById('setting-ha-url')?.value || s.ha_url || '').replace(/\/$/, '');
    const entity = document.getElementById('setting-ha-tts-entity')?.value || s.ha_tts_entity || '';

    if (!haUrl) {
        showToast(t('settings.ha.error_no_url'));
        return;
    }

    // Switch to TTS tab and fill fields
    const ttsTab = document.querySelector('[data-tab="tab-tts"]');
    if (ttsTab) ttsTab.click();

    const engineEl = document.getElementById('setting-tts-engine');
    if (engineEl) { engineEl.value = 'server'; onTtsEngineChange('server'); }

    const urlEl = document.getElementById('setting-tts-url');
    if (urlEl) urlEl.value = haUrl + '/api/services/tts/speak';

    const methodEl = document.getElementById('setting-tts-method');
    if (methodEl) methodEl.value = 'POST';

    const authTypeEl = document.getElementById('setting-tts-auth-type');
    if (authTypeEl) { authTypeEl.value = 'bearer'; onTtsAuthTypeChange('bearer'); }

    const tokenEl = document.getElementById('setting-tts-token');
    if (tokenEl) {
        const haToken = document.getElementById('setting-ha-token')?.value.trim() || s.ha_token || '';
        tokenEl.value = haToken;
    }

    const payloadKeyEl = document.getElementById('setting-tts-payload-key');
    if (payloadKeyEl) payloadKeyEl.value = 'message';

    const ctEl = document.getElementById('setting-tts-content-type');
    if (ctEl) ctEl.value = 'application/json';

    const extraEl = document.getElementById('setting-tts-extra-fields');
    if (extraEl) extraEl.value = entity ? JSON.stringify({ entity_id: entity }) : '';

    showToast(t('settings.ha.tts_preset_applied'));
}

function showHaWebhookHelp() {
    const msg = t('settings.ha.webhook_help');
    showToast(msg, 8000);
}

async function saveHaSettings() {
    const s = getSettings();
    const haEnabled = document.getElementById('setting-ha-enabled')?.checked || false;
    const haUrl = document.getElementById('setting-ha-url')?.value.trim() || '';
    const haToken = document.getElementById('setting-ha-token')?.value.trim() || '';
    const haTtsEntity = document.getElementById('setting-ha-tts-entity')?.value.trim() || '';
    const haWebhookId = document.getElementById('setting-ha-webhook-id')?.value.trim() || '';
    const haNotify = document.getElementById('setting-ha-notify-service')?.value.trim() || '';
    const haExpiryDays = parseInt(document.getElementById('setting-ha-expiry-days')?.value, 10) || 3;

    const events = [];
    if (document.getElementById('ha-event-expiry')?.checked) events.push('expiry');
    if (document.getElementById('ha-event-shopping')?.checked) events.push('shopping_add');
    if (document.getElementById('ha-event-stock')?.checked) events.push('stock_update');
    const haEvents = events.join(',');

    s.ha_enabled = haEnabled;
    s.ha_url = haUrl;
    if (haToken) s.ha_token = haToken;
    s.ha_tts_entity = haTtsEntity;
    s.ha_webhook_id = haWebhookId;
    s.ha_webhook_events = haEvents;
    s.ha_notify_service = haNotify;
    s.ha_expiry_days = haExpiryDays;
    saveSettingsToStorage(s);

    const statusEl = document.getElementById('ha-save-status');
    try {
        const settingsToken = document.getElementById('setting-settings-token')?.value.trim() || (typeof getApiToken === 'function' ? getApiToken() : '');
        if (settingsToken && typeof setApiToken === 'function') setApiToken(settingsToken);
        const tokenHeader = settingsToken ? { 'X-API-Token': settingsToken } : (typeof apiAuthHeaders === 'function' ? apiAuthHeaders() : {});
        const result = await api('save_settings', {}, 'POST', {
            ha_enabled: haEnabled,
            ha_url: haUrl,
            ...(haToken ? { ha_token: haToken } : {}),
            ha_tts_entity: haTtsEntity,
            ha_webhook_id: haWebhookId,
            ha_webhook_events: haEvents,
            ha_notify_service: haNotify,
            ha_expiry_days: haExpiryDays,
        }, tokenHeader);
        if (statusEl) {
            statusEl.style.display = 'block';
            statusEl.className = result.success ? 'settings-status success' : 'settings-status error';
            statusEl.textContent = result.success ? '✅ ' + t('settings.saved') : '❌ ' + (result.error || t('settings.saved_local_error'));
            setTimeout(() => { if (statusEl) statusEl.style.display = 'none'; }, 4000);
        }
    } catch(e) {
        if (statusEl) {
            statusEl.style.display = 'block';
            statusEl.className = 'settings-status success';
            statusEl.textContent = '✅ ' + t('settings.saved_local');
            setTimeout(() => { if (statusEl) statusEl.style.display = 'none'; }, 4000);
        }
    }
}

/** Populate voice selector from Web Speech API. Called on settings load and on voiceschanged. */
function _initBrowserTtsVoices(selectedVoice) {
    const sel = document.getElementById('setting-tts-voice');
    if (!sel) return;

    // Inside the EverShelf Kiosk Android app the native TTS bridge handles
    // speech — no Web Speech API voice list needed.
    if (typeof _kioskBridge !== 'undefined' && typeof _kioskBridge.speak === 'function') {
        sel.innerHTML = '<option value="">— Voce nativa Android (kiosk) —</option>';
        return;
    }

    if (!window.speechSynthesis) {
        sel.innerHTML = `<option value="">— ${t('settings.tts.voice_not_supported')} —</option>`;
        return;
    }

    // Reset to loading state each time (settings page may be re-opened)
    sel.innerHTML = `<option value="">— ${t('settings.tts.voices_loading')} —</option>`;

    const populate = () => {
        let voices = [];
        try {
            voices = (window.speechSynthesis.getVoices() || []).filter(v => {
                try { return v != null && typeof v.lang === 'string' && v.lang.length > 0; }
                catch (_) { return false; }
            });
        } catch (_) { return false; }
        if (!voices.length) return false;
        // Italian voices first, then others
        const it = voices.filter(v => v.lang.startsWith('it'));
        const others = voices.filter(v => !v.lang.startsWith('it'));
        const sorted = [...it, ...others];
        sel.innerHTML = sorted.map(v =>
            `<option value="${v.name}" ${v.name === selectedVoice ? 'selected' : ''}>${v.name} (${v.lang})${v.localService ? '' : ' ☁️'}</option>`
        ).join('');
        // Auto-select first Italian voice if no preference set
        if (!selectedVoice) {
            const paola = sorted.find(v => v.name === 'Paola');
            const firstIt = sorted.find(v => v.lang.startsWith('it'));
            if (paola) sel.value = paola.name;
            else if (firstIt) sel.value = firstIt.name;
        }
        return true;
    };

    // Try immediately (voices already cached from previous call)
    if (populate()) return;

    // onvoiceschanged fires in Firefox / some Chrome versions
    window.speechSynthesis.onvoiceschanged = () => { populate(); };

    // Polling fallback: Chrome/WebView loads voices async (up to ~3s on desktop, longer on Android)
    let tries = 0;
    const interval = setInterval(() => {
        tries++;
        if (populate()) {
            clearInterval(interval);
        } else if (tries >= 50) { // 50 × 200ms = 10s
            clearInterval(interval);
            if (!window.speechSynthesis.getVoices().length) {
                sel.innerHTML = `<option value="">— ${t('settings.tts.voices_none')} —</option>`;
            }
        }
    }, 200);
}

/** Speak text using the browser Web Speech API (offline).
 *  When running inside the EverShelf Kiosk Android app the native TTS bridge
 *  is preferred — it bypasses Web Speech API voice limitations on Android. */
function _speakBrowser(text) {
    const s = getSettings();
    const rate  = parseFloat(s.tts_rate)  || 1;
    const pitch = parseFloat(s.tts_pitch) || 1;

    // ── Native Android TTS bridge (kiosk WebView) ──────────────────────
    if (typeof _kioskBridge !== 'undefined' && typeof _kioskBridge.speak === 'function') {
        try { _kioskBridge.speak(text, rate, pitch); } catch(_e) { /* silent */ }
        return;
    }

    // ── Web Speech API (desktop / mobile browser) ──────────────────────
    if (!window.speechSynthesis) return;

    const _doSpeak = () => {
        window.speechSynthesis.cancel();
        const utt = new SpeechSynthesisUtterance(text);
        utt.rate  = rate;
        utt.pitch = pitch;
        const voices = window.speechSynthesis.getVoices();
        // 1. User-selected voice by name
        const preferred = s.tts_voice ? voices.find(v => v.name === s.tts_voice) : null;
        if (preferred) {
            utt.voice = preferred;
            utt.lang  = preferred.lang;
        } else {
            // Prefer offline (localService) voices to avoid silent failure when no internet.
            // Priority: local Italian → any Italian → local any-lang → first available → lang-only
            const itLocal  = voices.find(v => v.lang && v.lang.startsWith('it') && v.localService);
            const itCloud  = voices.find(v => v.lang && v.lang.startsWith('it'));
            const anyLocal = voices.find(v => v.localService);
            const chosen   = itLocal || itCloud || anyLocal || voices[0];
            if (chosen) {
                utt.voice = chosen;
                utt.lang  = chosen.lang;
            } else {
                // No voices loaded yet — set lang and let the browser decide
                utt.lang = _currentLang === 'de' ? 'de-DE' : _currentLang === 'en' ? 'en-US' : 'it-IT';
            }
        }
        // Chrome quirks:
        // 1. cancel() + immediate speak() is silently dropped → 50 ms gap fixes it
        // 2. speechSynthesis gets paused after tab backgrounding; cancel() does NOT
        //    clear the paused state — need an explicit resume() before speak()
        setTimeout(() => {
            if (window.speechSynthesis.paused) window.speechSynthesis.resume();
            window.speechSynthesis.speak(utt);
        }, 50);
    };

    // If voices haven't loaded yet (async in Chrome/Android), wait once then speak
    if (!window.speechSynthesis.getVoices().length) {
        const _onReady = () => {
            window.speechSynthesis.onvoiceschanged = null;
            _doSpeak();
        };
        window.speechSynthesis.onvoiceschanged = _onReady;
        // Safety timeout: fire anyway after 500 ms if onvoiceschanged never fires
        setTimeout(() => {
            if (window.speechSynthesis.onvoiceschanged === _onReady) {
                window.speechSynthesis.onvoiceschanged = null;
                _doSpeak();
            }
        }, 500);
    } else {
        _doSpeak();
    }
}

function testSound() {
    const statusEl = document.getElementById('tts-test-status');
    _ensureAudioUnlocked();
    _playCookingTimerSound('done');
    if (statusEl) {
        statusEl.style.display = 'block';
        statusEl.className = 'settings-status success';
        statusEl.textContent = '🔔 Suono inviato — hai sentito un beep?';
    }
}

async function testTTS() {
    const statusEl = document.getElementById('tts-test-status');
    const enabled = document.getElementById('setting-tts-enabled')?.checked;
    if (!enabled) {
        if (statusEl) { statusEl.style.display = 'block'; statusEl.className = 'settings-status error'; statusEl.textContent = '⚠️ TTS non attivo — attiva il toggle prima di testare.'; }
        return;
    }
    const engine = document.getElementById('setting-tts-engine')?.value || 'browser';
    if (engine === 'browser') {
        // Kiosk native TTS bridge takes priority over Web Speech API
        if (typeof _kioskBridge !== 'undefined' && typeof _kioskBridge.speak === 'function') {
            // Diagnostic: check if Android TTS engine is ready
            const ready = typeof _kioskBridge.isTtsReady === 'function' ? _kioskBridge.isTtsReady() : 'unknown';
            if (ready === 'false') {
                if (statusEl) { statusEl.style.display = 'block'; statusEl.className = 'settings-status error'; statusEl.textContent = '❌ Android TTS non inizializzato — riavvia l\'app kiosk o installa un motore TTS dal Play Store.'; }
                return;
            }
            const s = getSettings();
            s.tts_rate  = parseFloat(document.getElementById('setting-tts-rate')?.value)  || 1;
            s.tts_pitch = parseFloat(document.getElementById('setting-tts-pitch')?.value) || 1;
            saveSettingsToStorage(s);
            if (statusEl) { statusEl.style.display = 'block'; statusEl.className = 'settings-status'; statusEl.textContent = '⏳ Invio al motore TTS Android...'; }
            // Register callbacks: Android will call these after speak completes/fails
            let _ttsTestTimer = null;
            window._kioskTtsDone = (uid) => {
                clearTimeout(_ttsTestTimer);
                window._kioskTtsDone = null; window._kioskTtsError = null;
                if (statusEl) { statusEl.className = 'settings-status success'; statusEl.textContent = '✅ ' + t('settings.tts.test_ok_kiosk'); }
            };
            window._kioskTtsError = (uid, code) => {
                clearTimeout(_ttsTestTimer);
                window._kioskTtsDone = null; window._kioskTtsError = null;
                const msg = code == -1 ? 'sintesi non riuscita' : code == -2 ? 'lingua non supportata' : code == -3 ? 'servizio non disponibile' : ('codice ' + code);
                if (statusEl) { statusEl.className = 'settings-status error'; statusEl.textContent = '❌ Errore TTS Android (' + msg + ') — installa o aggiorna Google Text-to-Speech dal Play Store.'; }
            };
            // Timeout: if Android doesn't callback within 10s, ask user if they heard the voice
            // (speech can take 6-8 s; UtteranceProgressListener may not fire on all firmware)
            _ttsTestTimer = setTimeout(() => {
                window._kioskTtsDone = null; window._kioskTtsError = null;
                if (!statusEl) return;
                statusEl.className = 'settings-status';
                statusEl.style.display = 'block';
                statusEl.innerHTML =
                    '<strong>🔊 ' + t('settings.tts.heard_question') + '</strong><br>' +
                    '<div style="display:flex;gap:8px;margin-top:8px">' +
                    '<button onclick="window._ttsTestYes()" style="flex:1;padding:8px;background:#15803d;color:#fff;border:none;border-radius:6px;font-size:0.95rem;cursor:pointer">✅ ' + t('settings.tts.heard_yes') + '</button>' +
                    '<button onclick="window._ttsTestNo()" style="flex:1;padding:8px;background:#dc2626;color:#fff;border:none;border-radius:6px;font-size:0.95rem;cursor:pointer">❌ ' + t('settings.tts.heard_no') + '</button>' +
                    '</div>';
                window._ttsTestYes = () => {
                    window._ttsTestYes = null; window._ttsTestNo = null;
                    if (statusEl) { statusEl.className = 'settings-status success'; statusEl.innerHTML = '✅ ' + t('settings.tts.test_ok'); }
                };
                window._ttsTestNo = () => {
                    window._ttsTestYes = null; window._ttsTestNo = null;
                    if (statusEl) { statusEl.className = 'settings-status error'; statusEl.innerHTML = '❌ ' + t('settings.tts.test_fail_steps'); }
                };
            }, 10000);
            _speakBrowser('Test vocale EverShelf. La sintesi vocale funziona correttamente.');
            return;
        }
        if (!window.speechSynthesis) {
            if (statusEl) { statusEl.style.display = 'block'; statusEl.className = 'settings-status error'; statusEl.textContent = '❌ Web Speech API non supportata da questo browser.'; }
            return;
        }
        // ── Audio beep test (AudioContext — works even if TTS is broken) ─────
        _ensureAudioUnlocked();
        _playCookingTimerSound('done');
        // Temporarily apply form values for the test
        const s = getSettings();
        const voiceName = document.getElementById('setting-tts-voice')?.value;
        s.tts_voice = voiceName || s.tts_voice;
        s.tts_rate  = parseFloat(document.getElementById('setting-tts-rate')?.value)  || 1;
        s.tts_pitch = parseFloat(document.getElementById('setting-tts-pitch')?.value) || 1;
        saveSettingsToStorage(s);
        // Diagnostic: surface problems before attempting TTS
        const voices = window.speechSynthesis.getVoices();
        if (!voices.length) {
            if (statusEl) { statusEl.style.display = 'block'; statusEl.className = 'settings-status error'; statusEl.textContent = '❌ Nessuna voce disponibile — installa un pacchetto vocale nelle impostazioni di sistema.'; }
            return;
        }
        // Warn if only cloud voices are available (won't work offline)
        const itLocal  = voices.find(v => v.lang && v.lang.startsWith('it') && v.localService);
        const anyLocal = voices.find(v => v.localService);
        if (!itLocal && !anyLocal) {
            if (statusEl) { statusEl.style.display = 'block'; statusEl.className = 'settings-status error'; statusEl.textContent = '❌ Solo voci cloud disponibili — la sintesi vocale offline richiede una voce locale installata sul dispositivo (es. Google Text-to-Speech → Scarica voci offline).'; }
            return;
        }
        // onerror callback: update status if speak() fails
        const _ttsErrHandler = (evt) => {
            if (statusEl) { statusEl.style.display = 'block'; statusEl.className = 'settings-status error'; statusEl.textContent = '❌ Errore TTS: ' + (evt.error || 'sconosciuto') + ' — prova a riavviare il browser o a cambiare voce.'; }
        };
        // Temporarily hook onerror via a custom utterance
        const testUtt = new SpeechSynthesisUtterance('Test vocale EverShelf. La sintesi vocale funziona correttamente.');
        testUtt.rate  = s.tts_rate;
        testUtt.pitch = s.tts_pitch;
        const chosenVoice = s.tts_voice ? voices.find(v => v.name === s.tts_voice) : null;
        const fallbackVoice = itLocal || voices.find(v => v.lang && v.lang.startsWith('it')) || anyLocal || voices[0];
        const testVoice = chosenVoice || fallbackVoice;
        if (testVoice) { testUtt.voice = testVoice; testUtt.lang = testVoice.lang; }
        testUtt.onerror = _ttsErrHandler;
        testUtt.onstart = () => {
            if (statusEl) { statusEl.style.display = 'block'; statusEl.className = 'settings-status success'; statusEl.textContent = '✅ Voce attiva: ' + (testVoice ? testVoice.name + ' (' + testVoice.lang + (testVoice.localService ? ', offline' : ', cloud') + ')' : 'default'); }
        };
        window.speechSynthesis.cancel();
        setTimeout(() => {
            if (window.speechSynthesis.paused) window.speechSynthesis.resume();
            window.speechSynthesis.speak(testUtt);
            // If onstart doesn't fire within 2s, show a warning
            setTimeout(() => {
                if (statusEl && statusEl.className.includes('success')) return; // already started
                if (!statusEl?.className.includes('error')) {
                    statusEl.style.display = 'block';
                    statusEl.className = 'settings-status error';
                    statusEl.textContent = '❌ Nessuna risposta dalla voce — se il beep era udibile, il TTS è bloccato. Prova a ricaricare la pagina o a cambiare voce.';
                }
            }, 2000);
        }, 50);
        if (statusEl) { statusEl.style.display = 'block'; statusEl.className = 'settings-status'; statusEl.textContent = '🔊 Beep + TTS in corso...'; }
        return;
    }
    // Server engine
    let extraFields = {};
    try { extraFields = JSON.parse((document.getElementById('setting-tts-extra-fields')?.value || '{}').trim() || '{}'); } catch(e) { /* ignore */ }
    const formSettings = {
        tts_url: (document.getElementById('setting-tts-url')?.value || '').trim(),
        tts_method: document.getElementById('setting-tts-method')?.value || 'POST',
        tts_auth_type: document.getElementById('setting-tts-auth-type')?.value || 'bearer',
        tts_token: (document.getElementById('setting-tts-token')?.value || '').trim(),
        tts_auth_header_name: (document.getElementById('setting-tts-auth-header-name')?.value || '').trim(),
        tts_auth_header_value: (document.getElementById('setting-tts-auth-header-value')?.value || '').trim(),
        tts_content_type: document.getElementById('setting-tts-content-type')?.value || 'application/json',
        tts_payload_key: (document.getElementById('setting-tts-payload-key')?.value || '').trim() || 'message',
        tts_extra_fields: document.getElementById('setting-tts-extra-fields')?.value || ''
    };
    if (!formSettings.tts_url) {
        if (statusEl) { statusEl.style.display = 'block'; statusEl.className = 'settings-status error'; statusEl.textContent = t('settings.tts.url_missing'); }
        return;
    }
    if (statusEl) { statusEl.style.display = 'block'; statusEl.className = 'settings-status'; statusEl.textContent = t('settings.tts.test_sending'); }
    try {
        const req = _buildTtsRequest('Test vocale EverShelf', formSettings);
        const res = await _ttsViaProxy(req);
        const data = await res.json().catch(() => ({}));
        const httpCode = data.status || res.status;
        if (res.ok && httpCode >= 200 && httpCode < 300) {
            if (statusEl) { statusEl.className = 'settings-status success'; statusEl.textContent = t('settings.tts.test_ok').replace('{code}', httpCode); }
        } else {
            const errDetail = data.error || data.body || res.statusText;
            if (statusEl) { statusEl.className = 'settings-status error'; statusEl.textContent = `⚠️ HTTP ${httpCode}: ${errDetail}`; }
        }
    } catch(e) {
        if (statusEl) { statusEl.className = 'settings-status error'; statusEl.textContent = `❌ ${t('error.prefix')}: ${e.message}`; }
    }
}

// ===== COOKING TIMER SYSTEM =====
let _cookingTimers = [];          // { id, label, total, seconds, running, interval }
let _cookingTimerIdCounter = 0;
let _cookingSuggestedSeconds = 0;
let _cookingSuggestedLabel = '';
let _sharedAudioCtx = null;       // pre-unlocked AudioContext (created on user gesture)

/**
 * Pre-unlock the shared AudioContext during a user gesture.
 * Call this from any click/touch handler so that the context is already
 * in 'running' state when the timer fires (potentially outside a gesture).
 */
function _ensureAudioUnlocked() {
    try {
        const Ctx = window.AudioContext || window.webkitAudioContext;
        if (!Ctx) return;
        if (!_sharedAudioCtx || _sharedAudioCtx.state === 'closed') {
            _sharedAudioCtx = new Ctx();
        }
        if (_sharedAudioCtx.state === 'suspended') {
            _sharedAudioCtx.resume().catch(() => {});
        }
    } catch (_) { /* ignore */ }
}

function _playCookingTimerSound(type = 'done') {
    try {
        const Ctx = window.AudioContext || window.webkitAudioContext;
        if (!Ctx) return;
        // Use the pre-unlocked shared context; fall back to a new one if closed
        let ctx = (_sharedAudioCtx && _sharedAudioCtx.state !== 'closed') ? _sharedAudioCtx : null;
        if (!ctx) { ctx = new Ctx(); }
        const pattern = type === 'warning'
            ? [{ f: 880, d: 0.08, o: 0.00 }, { f: 1046, d: 0.10, o: 0.14 }]
            : [
                { f: 740, d: 0.10, o: 0.00 },
                { f: 988, d: 0.12, o: 0.18 },
                { f: 1318, d: 0.14, o: 0.38 }
            ];

        const doPlay = () => {
            const now = ctx.currentTime;
            for (const p of pattern) {
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = 'sine';
                osc.frequency.value = p.f;
                gain.gain.setValueAtTime(0.0001, now + p.o);
                gain.gain.exponentialRampToValueAtTime(0.12, now + p.o + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.0001, now + p.o + p.d);
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start(now + p.o);
                osc.stop(now + p.o + p.d + 0.02);
            }
        };

        if (ctx.state === 'suspended') {
            ctx.resume().then(doPlay).catch(() => {});
        } else {
            doPlay();
        }
    } catch (_) { /* ignore */ }
}

function _notifyCookingTimer(type, label) {
    const key = type === 'warning' ? 'cooking.timer_warning_tts' : 'cooking.timer_expired_tts';
    const msg = t(key).replace('{label}', label || t('cooking.timer'));

    // Always play the beep (uses pre-unlocked shared AudioContext)
    _playCookingTimerSound(type === 'warning' ? 'warning' : 'done');

    // Timer alerts always speak — they are alarms, not step narration.
    // Do NOT gate on _cookingTTS; that toggle is for step-by-step reading only.
    // Also include the kiosk native TTS bridge which works even when
    // window.speechSynthesis is absent on older Android WebView.
    const s = getSettings();
    const hasBrowserTts = typeof window !== 'undefined' && 'speechSynthesis' in window;
    const hasCustomTts  = s.tts_engine === 'custom' && !!s.tts_url;
    const hasKioskTts   = typeof _kioskBridge !== 'undefined' && typeof _kioskBridge.speak === 'function';
    if (hasBrowserTts || hasCustomTts || hasKioskTts) {
        speakCookingStep(msg);
    }
}

/**
 * Parse time durations from step text.
 * Returns total seconds or 0 if no time found.
 */
function _parseStepTimer(text) {
    const t = text.toLowerCase();
    let totalSec = 0;
    if (/mezz['']?\s*ora/i.test(t)) totalSec += 30 * 60;
    if (/un\s+quarto\s+d['']?\s*ora/i.test(t)) totalSec += 15 * 60;
    if (/un['']?\s*ora(?!\s*e)/i.test(t) && !/\d\s*or[ae]/i.test(t)) totalSec += 60 * 60;
    if (totalSec > 0) return totalSec;
    const reOre = /(\d+(?:[.,]\d+)?)\s*or[ae]/gi;
    const reMin = /(\d+(?:[.,]\d+)?)\s*min(?:ut[oi])?/gi;
    const reSec = /(\d+(?:[.,]\d+)?)\s*second[oi]/gi;
    let m;
    while ((m = reOre.exec(t)) !== null) totalSec += parseFloat(m[1].replace(',', '.')) * 3600;
    while ((m = reMin.exec(t)) !== null) totalSec += parseFloat(m[1].replace(',', '.')) * 60;
    while ((m = reSec.exec(t)) !== null) totalSec += parseFloat(m[1].replace(',', '.'));
    if (totalSec === 0 && /(?:un\s+paio\s+di|qualche|pochi)\s+minut/i.test(t)) totalSec = 2 * 60;
    if (totalSec === 0 && /qualche\s+second/i.test(t)) totalSec = 15;
    return Math.round(totalSec);
}

function _formatTimerDisplay(sec) {
    const abs = Math.abs(sec);
    const m = Math.floor(abs / 60);
    const s = abs % 60;
    const sign = sec < 0 ? '+' : '';
    return `${sign}${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
}

/** Extract a short 2-3 word label from the step text for the timer. */
function _extractTimerLabel(text, stepNum) {
    const raw = String(text || '');
    const fillers = new Set(['il','la','lo','le','gli','i','dell','della','dello','delle','degli','dei',
        'un','una','uno','del','al','alla','allo','alle','agli','ai','nel','nella','nello','nelle',
        'negli','nei','per','con','che','poi','e','o','non','se','in','di','a','da','fino','mentre',
        'quando','dopo','prima','circa','bene','ancora','subito','su','ad','ed','piu','meno','tutto','tutta',
        'the','and','for','mit','und','zum','zur']);
    const applianceWords = new Set(['moulinex','cookeo','bimby','forno','airfryer','friggitrice','microonde','tm5','tm6']);
    const timePatterns = [/mezz['']?\s*ora/i, /\bor[ae]\b/i, /\bmin(?:ut[oi])?\b/i, /\bsecond[oi]\b/i, /\bquarto\s+d['']?\s*ora/i];

    let timeIdx = raw.length;
    for (const p of timePatterns) {
        const r = p.exec(raw);
        if (r && r.index < timeIdx) timeIdx = r.index;
    }

    let beforeTime = (raw.slice(0, timeIdx).trim() || raw)
        .replace(/\([^)]*\)/g, ' ')
        .replace(/[.,!?;:'"\[\]]/g, ' ')
        .replace(/^\s*(poi|quindi|allora|infine|then|dann)\s+/i, '')
        .replace(/\s+/g, ' ')
        .trim();

    if (!beforeTime) return t('cooking.step_fallback').replace('{n}', stepNum + 1);

    const actionRules = [
        { re: /\b(rosolatur\w*|rosola\w*|soffrigg\w*)\b/i, label: 'Rosolatura' },
        { re: /\b(stuf\w*)\b/i, label: 'Stufare' },
        { re: /\b(boll\w*|sobboll\w*)\b/i, label: 'Bollitura' },
        { re: /\b(cuoc\w*|cottur\w*)\b/i, label: 'Cottura' },
        { re: /\b(tost\w*)\b/i, label: 'Tostatura' },
        { re: /\b(mescol\w*|mischi\w*)\b/i, label: 'Mescola' },
        { re: /\b(ripos\w*)\b/i, label: 'Riposo' },
        { re: /\b(marin\w*)\b/i, label: 'Marinatura' },
        { re: /\b(preriscald\w*|accend\w*|scald\w*)\b/i, label: 'Preriscalda' }
    ];

    const hasAppliance = /\b(moulinex|cookeo|bimby|forno|airfryer|friggitrice|microonde|tm5|tm6)\b/i.test(beforeTime);
    let actionLabel = '';
    for (const rule of actionRules) {
        if (rule.re.test(beforeTime)) {
            actionLabel = rule.label;
            break;
        }
    }

    // Remove the leading verb chunk and appliance references, then keep only compact object words.
    let objectPart = beforeTime
        .replace(/^(?:fai|lascia|metti|porta|tieni|poi|quindi)\s+/i, '')
        .replace(/^(?:rosola\w*|soffrigg\w*|stuf\w*|boll\w*|sobboll\w*|cuoc\w*|tost\w*|mescol\w*|mischi\w*|ripos\w*|marin\w*|preriscald\w*|accend\w*|scald\w*)\s+/i, '')
        .replace(/\b(?:nel|nella|nello|nei|in|su|sul|sulla|dentro|con)\b\s+(?:il|lo|la|i|gli|le)?\s*(?:moulinex|cookeo|bimby|forno|airfryer|friggitrice|microonde|tm5|tm6)\b/gi, ' ')
        .replace(/\b(moulinex|cookeo|bimby|forno|airfryer|friggitrice|microonde|tm5|tm6)\b/gi, ' ')
        .replace(/\s+/g, ' ')
        .trim();

    const objectWords = objectPart
        .split(/\s+/)
        .map(w => w.toLowerCase())
        .filter(w => w.length > 2 && !/^\d+$/.test(w) && !fillers.has(w) && !applianceWords.has(w));

    const shortObject = objectWords.slice(0, 2).join(' ');

    let label = '';
    if (actionLabel) {
        label = shortObject ? `${actionLabel} ${shortObject}` : actionLabel;
        if (actionLabel === 'Preriscalda' && hasAppliance) label = 'Preriscalda';
    } else {
        const fallback = beforeTime
            .split(/\s+/)
            .map(w => w.toLowerCase())
            .filter(w => w.length > 2 && !/^\d+$/.test(w) && !fillers.has(w) && !applianceWords.has(w))
            .slice(0, 3)
            .join(' ');
        label = fallback || t('cooking.step_fallback').replace('{n}', stepNum + 1);
    }

    label = label.replace(/\s+/g, ' ').trim();
    if (!label) return t('cooking.step_fallback').replace('{n}', stepNum + 1);

    // Keep timer chips compact and readable.
    const maxLen = 30;
    if (label.length > maxLen) label = label.slice(0, maxLen).trim() + '…';
    return label.charAt(0).toUpperCase() + label.slice(1);
}

function setupCookingTimerSuggestion(stepText) {
    const seconds = _parseStepTimer(stepText);
    const suggestEl = document.getElementById('cooking-timer-suggest');
    if (seconds <= 0) {
        suggestEl.style.display = 'none';
        _cookingSuggestedSeconds = 0;
        _cookingSuggestedLabel = '';
        return;
    }
    _cookingSuggestedSeconds = seconds;
    _cookingSuggestedLabel = _extractTimerLabel(stepText, _cookingStep);
    document.getElementById('cooking-timer-suggest-text').textContent =
        `⏱️ ${_formatTimerDisplay(seconds)}  ·  ${_cookingSuggestedLabel}`;
    suggestEl.style.display = 'flex';
}

function addSuggestedCookingTimer() {
    if (_cookingSuggestedSeconds <= 0) return;
    addCookingTimer(_cookingSuggestedSeconds, _cookingSuggestedLabel);
    document.getElementById('cooking-timer-suggest').style.display = 'none';
    _cookingSuggestedSeconds = 0;
}

function addCookingTimer(seconds, label) {
    _ensureAudioUnlocked(); // unlock AudioContext on this user gesture
    const id = ++_cookingTimerIdCounter;
    _cookingTimers.push({ id, label, total: seconds, seconds, running: false, interval: null });
    renderTimersBar();
    toggleCookingTimerById(id); // auto-start
}

function removeCookingTimer(id) {
    const timer = _cookingTimers.find(ti => ti.id === id);
    if (timer && timer.interval) clearInterval(timer.interval);
    _cookingTimers = _cookingTimers.filter(ti => ti.id !== id);
    renderTimersBar();
    _updateScreenFlash();
}

function toggleCookingTimerById(id) {
    const timer = _cookingTimers.find(ti => ti.id === id);
    if (!timer) return;
    if (timer.running) {
        clearInterval(timer.interval);
        timer.interval = null;
        timer.running = false;
    } else {
        timer.running = true;
        timer.interval = setInterval(() => {
            timer.seconds = Math.max(0, timer.seconds - 1);

            if (timer.seconds === 10) {
                _notifyCookingTimer('warning', timer.label);
            }

            if (timer.seconds === 0) {
                _cookingTimerDoneById(id);
                return;
            }

            _updateTimerCard(id);
        }, 1000);
    }
    _updateTimerCard(id);
}

function resetCookingTimerById(id) {
    const timer = _cookingTimers.find(ti => ti.id === id);
    if (!timer) return;
    clearInterval(timer.interval);
    timer.interval = null;
    timer.running = false;
    timer.seconds = timer.total;
    _updateTimerCard(id);
}

function _cookingTimerDoneById(id) {
    if (navigator.vibrate) navigator.vibrate([300, 100, 300, 100, 300]);
    const timer = _cookingTimers.find(ti => ti.id === id);
    if (!timer) return;

    clearInterval(timer.interval);
    timer.interval = null;
    timer.running = false;
    timer.seconds = 0;

    // Show the done state in the card before removing it
    _updateTimerCard(id);
    _updateScreenFlash();
    _notifyCookingTimer('done', timer.label);
    // Keep the done card visible for 3 s so the user sees which timer finished
    setTimeout(() => removeCookingTimer(id), 3000);
}

function _updateTimerCard(id) {
    const t = _cookingTimers.find(t => t.id === id);
    if (!t) return;
    const card = document.getElementById(`ctimer-${id}`);
    if (!card) { renderTimersBar(); return; }
    const sec = t.seconds;
    const dispEl = card.querySelector('.ctimer-display');
    const toggleBtn = card.querySelector('.ctimer-toggle');
    dispEl.textContent = _formatTimerDisplay(sec);
    if (sec <= 0) {
        dispEl.className = 'ctimer-display ctimer-done';
    } else if (sec <= 30) {
        dispEl.className = 'ctimer-display ctimer-warning';
    } else {
        dispEl.className = 'ctimer-display';
    }
    toggleBtn.textContent = t.running ? '⏸' : '▶';
    toggleBtn.classList.toggle('running', t.running);
    _updateScreenFlash();
}

/** Update the full-screen colour flash based on the worst active timer state. */
function _updateScreenFlash() {
    const flashEl = document.getElementById('cooking-flash-overlay');
    if (!flashEl) return;
    let hasDone = false, hasWarning = false;
    for (const t of _cookingTimers) {
        if (t.seconds <= 0) { hasDone = true; break; }
        if (t.seconds <= 30 && t.running) hasWarning = true;
    }
    if (hasDone) {
        flashEl.className = 'cooking-flash-overlay flash-done';
    } else if (hasWarning) {
        flashEl.className = 'cooking-flash-overlay flash-warning';
    } else {
        flashEl.className = 'cooking-flash-overlay';
    }
}

function renderTimersBar() {
    const bar = document.getElementById('cooking-timers-bar');
    if (!bar) return;
    if (_cookingTimers.length === 0) {
        bar.style.display = 'none';
        bar.innerHTML = '';
        return;
    }
    bar.style.display = 'flex';
    bar.innerHTML = _cookingTimers.map(t => {
        const sec = t.seconds;
        const doneClass = sec <= 0 ? ' ctimer-done' : sec <= 30 ? ' ctimer-warning' : '';
        const runClass = t.running ? ' running' : '';
        return `<div class="cooking-timer-card" id="ctimer-${t.id}">
            <span class="ctimer-label">${escapeHtml(t.label)}</span>
            <span class="ctimer-display${doneClass}">${_formatTimerDisplay(sec)}</span>
            <div class="ctimer-btns">
                <button class="ctimer-btn ctimer-toggle${runClass}" onclick="toggleCookingTimerById(${t.id})">${t.running ? '⏸' : '▶'}</button>
                <button class="ctimer-btn ctimer-reset" onclick="resetCookingTimerById(${t.id})">↩</button>
                <button class="ctimer-btn ctimer-remove" onclick="removeCookingTimer(${t.id})">✕</button>
            </div>
        </div>`;
    }).join('');
}

function clearAllCookingTimers() {
    _cookingTimers.forEach(t => { if (t.interval) clearInterval(t.interval); });
    _cookingTimers = [];
    _cookingTimerIdCounter = 0;
    _cookingSuggestedSeconds = 0;
    _cookingSuggestedLabel = '';
    const bar = document.getElementById('cooking-timers-bar');
    if (bar) { bar.style.display = 'none'; bar.innerHTML = ''; }
    _updateScreenFlash();
}
// ===== END COOKING TIMER SYSTEM =====

function toggleCookingTTS() {
    _cookingTTS = !_cookingTTS;
    const btn = document.getElementById('cooking-tts-btn');
    btn.textContent = _cookingTTS ? '🔊' : '🔇';
    if (_cookingTTS) {
        const steps = _cookingRecipe?.steps || [];
        const text = _stepStr(steps[_cookingStep]);
        speakCookingStep(text);
    }
}

function navigateCookingStep(delta) {
    if (!_cookingRecipe) return;
    const total = (_cookingRecipe.steps || []).length;
    const next = _cookingStep + delta;
    if (next < 0) return;
    if (next >= total) {
        // All steps done: mark all visited, announce completion, then close overlay
        for (let i = 0; i < total; i++) _cookingVisited.add(i);
        if (_cookingTTS) {
            const doneText = t('cooking.recipe_done_tts').replace('{title}', _cookingRecipe.title || '');
            speakCookingStep(doneText);
        }
        closeCookingMode();
        return;
    }
    _cookingWheelLastDelta = delta;
    _cookingStep = next;
    renderCookingStep();
    _cookingStepFeedback();
    if (_cookingTTS) {
        const text = _stepStr((_cookingRecipe.steps || [])[_cookingStep]);
        speakCookingStep(text);
    }
}

function cookingUseIngredient(idx, productId, location, qtyNumber, btn) {
    // Reuse the same modal used in the recipe dialog
    useRecipeIngredient(idx, productId, location, qtyNumber, btn);
    // Mark ingredient as used so it's hidden from further steps
    if (_cookingRecipe && _cookingRecipe.ingredients && _cookingRecipe.ingredients[idx]) {
        _cookingRecipe.ingredients[idx].used = true;
    }
    setTimeout(() => renderCookingStep(), 400);
}
// ===== END COOKING MODE =====

function updateRecipeMealTitle() {
    const meal = getSelectedMealType();
    const mealLabels = getMealLabels();
    document.getElementById('recipe-meal-title').textContent = mealLabels[meal] || t('recipes.dialog_title');
    _renderMealPlanHint(meal);
    _renderMealSubTypes(meal);
}

function _renderMealSubTypes(mealId) {
    const container = document.getElementById('recipe-subtype-group');
    if (!container) return;
    const subs = getMealSubTypes()[mealId];
    if (!subs) {
        container.style.display = 'none';
        container.innerHTML = '';
        return;
    }
    container.style.display = '';
    container.innerHTML = subs.map((s, i) =>
        `<label class="recipe-meal-chip recipe-subtype-chip"><input type="radio" name="recipe-subtype" value="${s.id}"${i === 0 ? ' checked' : ''}> ${s.icon} ${s.label}</label>`
    ).join('');
}

function getSelectedSubType() {
    const checked = document.querySelector('input[name="recipe-subtype"]:checked');
    return checked ? checked.value : '';
}

/** Show/hide the meal-plan badge hint + top banner in the recipe dialog. */
function onMealPlanChipChange(cb) {
    const show = cb.checked;
    const banner = document.getElementById('recipe-mealplan-banner');
    const hint   = document.getElementById('recipe-mealplan-hint');
    if (banner) banner.style.display = show ? 'flex' : 'none';
    if (hint)   hint.style.display   = show ? 'flex' : 'none';
}

function _renderMealPlanHint(mealSlot) {
    const el = document.getElementById('recipe-mealplan-hint');
    const banner = document.getElementById('recipe-mealplan-banner');
    const chipWrap = document.getElementById('recipe-opt-mealplan-wrap');
    const chipLabel = document.getElementById('recipe-opt-mealplan-label');
    const chipCb = document.getElementById('recipe-opt-mealplan');
    // mealSlot = 'pranzo' or 'cena' (from getMealType/getSelectedMealType)
    const typeId = (mealSlot === 'pranzo' || mealSlot === 'cena')
        ? getTodayMealPlanType(mealSlot)
        : null;
    if (!typeId || typeId === 'libero') {
        if (el) el.style.display = 'none';
        if (banner) banner.style.display = 'none';
        if (chipWrap) chipWrap.style.display = 'none';
        return;
    }
    const mpt = getMealPlanTypeMap()[typeId];
    if (!mpt) {
        if (el) el.style.display = 'none';
        if (banner) banner.style.display = 'none';
        if (chipWrap) chipWrap.style.display = 'none';
        return;
    }
    if (el) {
        el.innerHTML = `<span class="mplan-hint-badge">${mpt.icon} ${mpt.label}</span> <span class="mplan-hint-label">${t('meal_plan.suggested_by')}</span>`;
        el.style.display = 'flex';
    }
    if (banner) {
        const slotLabel = mealSlot === 'pranzo' ? '🌤️ ' + t('meal_types.pranzo') : '🌙 ' + t('meal_types.cena');
        banner.innerHTML = `<span style="opacity:0.75;font-weight:500">${slotLabel}</span><span style="opacity:0.45">·</span><span>${mpt.icon} ${mpt.label}</span>`;
        banner.style.display = 'flex';
    }
    // Show the meal-plan chip (active by default, user can uncheck to ignore the plan)
    if (chipWrap) {
        chipWrap.style.display = '';
        if (chipLabel) chipLabel.textContent = `${mpt.icon} ${mpt.label}`;
        if (chipCb) chipCb.checked = true;
    }

    // Async: check if the required ingredient is actually in inventory.
    // If not, disable the chip and warn the user.
    _checkMealPlanIngredientAvailable(typeId).then(available => {
        if (!available && chipWrap && chipWrap.style.display !== 'none') {
            if (chipCb) { chipCb.checked = false; chipCb.disabled = true; }
            if (chipLabel) chipLabel.textContent = `${mpt.icon} ${mpt.label} ⚠️ ${t('meal_plan.not_available') || 'non disponibile'}`;
            chipWrap.style.opacity = '0.5';
            if (banner) banner.style.display = 'none';
        }
    }).catch(() => {/* ignore */});
}

function showRegenChoice() {
    document.getElementById('recipe-regen-btn').style.display = 'none';
    document.getElementById('recipe-regen-choice').style.display = '';
}

function cancelRegenChoice() {
    document.getElementById('recipe-regen-choice').style.display = 'none';
    document.getElementById('recipe-regen-btn').style.display = '';
}

function doRegenerateReplace() {
    cancelRegenChoice();
    _doRegenerate();
}

async function doRegenerateSave() {
    if (_cachedRecipe && _cachedRecipe.recipe) {
        await saveRecipeToArchive(_cachedRecipe.recipe);
    }
    cancelRegenChoice();
    _doRegenerate();
}

function _doRegenerate() {
    // Collect main ingredients from the rejected recipe to exclude them
    if (_cachedRecipe && _cachedRecipe.recipe && _cachedRecipe.recipe.ingredients) {
        const mainIngs = _cachedRecipe.recipe.ingredients
            .filter(i => i.from_pantry)
            .map(i => i.name);
        _rejectedRecipeIngredients = [...new Set([..._rejectedRecipeIngredients, ...mainIngs])];
    }
    _cachedRecipe = null;
    const meal = getSelectedMealType();
    _recipeVariationCount[meal] = (_recipeVariationCount[meal] || 0) + 1;
    document.getElementById('recipe-result').style.display = 'none';
    document.getElementById('recipe-loading').style.display = 'none';
    document.getElementById('recipe-ask').style.display = '';
}

function regenerateRecipe() {
    showRegenChoice();
}

async function generateRecipe() {
    if (!_requireGemini()) return;
    const meal = getSelectedMealType();
    const persons = parseInt(document.getElementById('recipe-persons').value) || 1;
    const settings = getSettings();

    // Reset rejected ingredients on first generation (not regeneration)
    if ((_recipeVariationCount[meal] || 0) === 0) {
        _rejectedRecipeIngredients = [];
    }

    // Determine meal plan type for today's selected slot,
    // but only if the user has NOT unchecked the meal-plan chip
    const mealPlanChipWrap = document.getElementById('recipe-opt-mealplan-wrap');
    const mealPlanCb = document.getElementById('recipe-opt-mealplan');
    const mealPlanChipActive = !mealPlanChipWrap || mealPlanChipWrap.style.display === 'none' || (mealPlanCb && mealPlanCb.checked);
    const mealPlanType = mealPlanChipActive && (meal === 'pranzo' || meal === 'cena')
        ? (getTodayMealPlanType(meal) || null)
        : null;

    // Gather active options from checkboxes
    const options = [];
    const optMap = {
        'recipe-opt-veloce': 'veloce',
        'recipe-opt-pocafame': 'pocafame',
        'recipe-opt-scadenze': 'scadenze',
        'recipe-opt-healthy': 'salutare',
        'recipe-opt-opened': 'opened',
        'recipe-opt-zerowaste': 'zerowaste'
    };
    Object.entries(optMap).forEach(([id, key]) => {
        const cb = document.getElementById(id);
        if (cb && cb.checked) options.push(key);
    });

    document.getElementById('recipe-ask').style.display = 'none';
    document.getElementById('recipe-loading').style.display = '';
    document.getElementById('recipe-result').style.display = 'none';
    const loadingMsg = document.getElementById('recipe-loading-msg');

    try {
        const payload = {
            meal,
            persons,
            lang: _currentLang,
            sub_type: getMealSubTypes()[meal] ? getSelectedSubType() : '',
            options,
            appliances: settings.appliances || [],
            dietary_restrictions: settings.dietary_restrictions || '',
            today_recipes: [...new Set([...await getTodayRecipeTitles(), ..._generatedTodayTitles])],
            meal_plan_type: mealPlanType,
            variation: _recipeVariationCount[meal] || 0,
            rejected_ingredients: _rejectedRecipeIngredients,
        };

        const response = await fetch('api/index.php?action=generate_recipe_stream', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-EverShelf-Request': '1',
                ...(typeof apiAuthHeaders === 'function' ? apiAuthHeaders() : {}),
            },
            body: JSON.stringify(payload)
        });

        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            document.getElementById('recipe-loading').style.display = 'none';
            document.getElementById('recipe-ask').style.display = '';
            if (response.status === 401) {
                window._apiTokenRequired = true;
                if (typeof _promptApiTokenIfNeeded === 'function') _promptApiTokenIfNeeded();
                showToast(t('startup.token_required') || 'Token API richiesto', 'warning');
            } else if (data.error === 'no_api_key') {
                showToast(t('error.no_api_key'), 'warning');
            } else {
                showToast(data.error || t('recipes.generate_error'), 'error');
            }
            return;
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';
        let recipe = null;
        let errorEvent = null;

        while (true) {
            const { done, value } = await reader.read();
            if (done) break;
            buffer += decoder.decode(value, { stream: true });
            const lines = buffer.split('\n');
            buffer = lines.pop();
            for (const line of lines) {
                if (!line.startsWith('data: ')) continue;
                try {
                    const event = JSON.parse(line.slice(6));
                    if (event.type === 'status' && loadingMsg) {
                        loadingMsg.textContent = event.message;
                    } else if (event.type === 'recipe') {
                        recipe = event.recipe;
                    } else if (event.type === 'error') {
                        errorEvent = event;
                    }
                } catch (_) { /* ignore malformed SSE lines */ }
            }
        }

        if (recipe) {
            await renderRecipe(recipe);
            if (recipe.title) _generatedTodayTitles.push(recipe.title);
            await saveRecipeToArchive(recipe);
            _cachedRecipe = { meal, recipe };
            document.getElementById('recipe-loading').style.display = 'none';
            document.getElementById('recipe-result').style.display = '';
        } else {
            document.getElementById('recipe-loading').style.display = 'none';
            document.getElementById('recipe-ask').style.display = '';
            if (errorEvent) {
                if (errorEvent.error === 'no_api_key') {
                    showToast(t('error.no_api_key'), 'warning');
                } else {
                    const detail = errorEvent.detail ? ` (${errorEvent.detail})` : '';
                    showToast((errorEvent.error || t('recipes.generate_error')) + detail, 'error');
                }
            } else {
                // Stream closed without recipe or error event — likely a server crash mid-stream
                showToast(t('recipes.stream_interrupted'), 'error');
            }
        }

    } catch (err) {
        console.error('Recipe error:', err);
        document.getElementById('recipe-loading').style.display = 'none';
        document.getElementById('recipe-ask').style.display = '';
        // Show the actual JS error (e.g. NetworkError, AbortError, TypeError)
        const errMsg = err?.message || String(err);
        showToast(`${t('error.connection')}: ${errMsg}`, 'error');
    }
}

// ===== GEMINI CHAT =====
let chatHistory = [];
let chatInventoryContext = null;
let _chatSavedCount = 0; // track how many messages already saved to DB

function initChat() {
    // Load chat history from DB
    api('chat_list').then(res => {
        if (res.success && res.messages && res.messages.length > 0) {
            chatHistory = res.messages.map(m => ({ role: m.role, text: m.text }));
            _chatSavedCount = chatHistory.length;
            renderChatHistory();
        } else {
            _chatSavedCount = 0;
        }
    }).catch(() => { _chatSavedCount = 0; });
    // Always reload fresh inventory context
    loadChatContext();
    // Focus input
    setTimeout(() => {
        const input = document.getElementById('chat-input');
        if (input) input.focus();
    }, 300);
}

async function loadChatContext() {
    try {
        const data = await api('inventory_list');
        chatInventoryContext = data.inventory || [];
    } catch(e) { chatInventoryContext = []; }
}

function sendChatSuggestion(text) {
    document.getElementById('chat-input').value = text;
    sendChatMessage();
}

/** Returns true if a chat reply looks like it contains a recipe with ingredients */
function _looksLikeRecipe(text) {
    // Must have an "Ingredienti" section header AND a step/preparation section
    const hasIngredients = /ingredi[e|ë]nti/i.test(text);
    const hasPreparation = /preparazi[o|ó]ne|procedimento|istruzioni|passaggi|how to|steps|zubereitung/i.test(text);
    const hasStepNumbers = /^\d+[\.\)]/m.test(text);
    return hasIngredients && (hasPreparation || hasStepNumbers);
}

async function chatTransferToRecipes(btn, replyText) {
    btn.disabled = true;
    btn.textContent = '⏳ ' + (t('chat.transferring') || 'Trasferimento in corso...');
    const resetBtn = () => {
        btn.disabled = false;
        btn.textContent = '📥 ' + (t('chat.transfer_to_recipes') || 'Trasferisci a Ricette');
    };
    try {
        const settings = getSettings();
        const result = await api('chat_to_recipe', {}, 'POST', {
            text: replyText,
            lang: settings.lang || 'it'
        });
        if (!result || !result.success || !result.recipe) {
            resetBtn();
            showToast('⚠️ ' + _recipeApiErrorMessage(result), 'error');
            return;
        }
        const recipe = result.recipe;
        // renderRecipe expects `persons`; Gemini might return `servings`
        if (!recipe.persons && recipe.servings) recipe.persons = recipe.servings;
        if (!recipe.persons) recipe.persons = 2;
        await saveRecipeToArchive(recipe);
        _cachedRecipe = { meal: recipe.meal || '', recipe };
        await renderRecipe(recipe);
        // Transform the transfer button into "Apri la ricetta"
        btn.disabled = false;
        btn.textContent = '📖 ' + (t('chat.open_recipe') || 'Apri la ricetta');
        btn.onclick = () => {
            document.getElementById('recipe-overlay').style.display = 'flex';
            document.getElementById('recipe-ask').style.display = 'none';
            document.getElementById('recipe-loading').style.display = 'none';
            document.getElementById('recipe-result').style.display = '';
        };
        showToast('✅ ' + (t('chat.transferred') || 'Aggiunta alle Ricette!'), 'success');
    } catch (err) {
        console.error('[chatTransferToRecipes]', err);
        resetBtn();
        showToast('⚠️ ' + (err.message || t('error.connection')), 'error');
    }
}

async function openIngredientDetail(productId, location) {
    try {
        const res = await api('inventory_list');
        const items = res.inventory || res;
        // Find by product_id + location; fallback to any row with that product_id
        let item = items.find(i => i.product_id === productId && i.location === location);
        if (!item) item = items.find(i => i.product_id === productId);
        if (!item) { showToast(t('error.not_found'), 'error'); return; }
        currentInventory = items;
        editInventoryItem(item.id);
    } catch(e) {
        showToast(t('error.connection'), 'error');
    }
}

function _recipeApiErrorMessage(result) {
    if (!result) return t('error.generic');
    if (result.error === 'parse_error') return t('recipes.parse_error');
    if (result.error === 'no_api_key') return t('error.no_api_key');
    return result.error || t('error.generic');
}

async function generateRecipeForIngredient(ingredientName) {
    if (!_requireGemini()) return;
    document.getElementById('recipe-overlay').style.display = 'flex';
    document.getElementById('recipe-ask').style.display = 'none';
    document.getElementById('recipe-loading').style.display = '';
    document.getElementById('recipe-result').style.display = 'none';
    const loadingMsg = document.getElementById('recipe-loading-msg');
    if (loadingMsg) loadingMsg.textContent = '👨‍🍳 ' + (t('recipes.loading_msg') || 'Sto preparando la ricetta...');
    try {
        const result = await api('recipe_from_ingredient', {}, 'POST', { ingredient: ingredientName, lang: _currentLang });
        if (!result || !result.success || !result.recipe) {
            document.getElementById('recipe-overlay').style.display = 'none';
            showToast('⚠️ ' + _recipeApiErrorMessage(result), 'error');
            return;
        }
        const recipe = result.recipe;
        if (!recipe.persons && recipe.servings) recipe.persons = recipe.servings;
        if (!recipe.persons) recipe.persons = 2;
        await saveRecipeToArchive(recipe);
        _cachedRecipe = { meal: recipe.meal || '', recipe };
        await renderRecipe(recipe);
        document.getElementById('recipe-loading').style.display = 'none';
        document.getElementById('recipe-result').style.display = '';
    } catch (err) {
        console.error('[generateRecipeForIngredient]', err);
        document.getElementById('recipe-overlay').style.display = 'none';
        showToast('⚠️ ' + t('error.connection'), 'error');
    }
}

async function sendChatMessage() {
    const input = document.getElementById('chat-input');
    const text = input.value.trim();
    if (!text) return;
    
    input.value = '';
    
    // Hide welcome if first message
    const welcome = document.querySelector('.chat-welcome');
    if (welcome) welcome.style.display = 'none';
    
    // Add user message
    chatHistory.push({ role: 'user', text });
    appendChatBubble('user', text);
    saveChatHistory();
    
    // Show typing indicator
    const typingEl = appendChatBubble('gemini', '<div class="chat-typing"><span></span><span></span><span></span></div>', true);
    scrollChatBottom();
    
    // Disable send
    const btn = document.getElementById('btn-chat-send');
    btn.disabled = true;
    
    try {
        const settings = getSettings();
        const result = await api('gemini_chat', {}, 'POST', {
            message: text,
            history: chatHistory.slice(0, -1).slice(-20), // last 20 messages for context
            appliances: settings.appliances || [],
            dietary_restrictions: settings.dietary_restrictions || '',
            lang: _currentLang
        });
        
        // Remove typing indicator
        typingEl.remove();
        
        if (result.success) {
            chatHistory.push({ role: 'gemini', text: result.reply });
            appendChatBubble('gemini', formatChatReply(result.reply));
            // If reply looks like a recipe, add transfer button as SEPARATE element below bubble
            if (_looksLikeRecipe(result.reply)) {
                const replyText = result.reply;
                const container = document.getElementById('chat-messages');
                const transferBtn = document.createElement('button');
                transferBtn.className = 'btn-chat-use-recipe';
                transferBtn.textContent = '📥 ' + (t('chat.transfer_to_recipes') || 'Trasferisci a Ricette');
                transferBtn.onclick = () => chatTransferToRecipes(transferBtn, replyText);
                container.appendChild(transferBtn);
                scrollChatBottom();
            }
        } else {
            const errMsg = result.error === 'no_api_key' ? t('error.no_api_key') : (result.error || t('error.generic'));
            appendChatBubble('gemini', `⚠️ ${escapeHtml(errMsg)}`);
        }
    } catch(err) {
        typingEl.remove();
        appendChatBubble('gemini', '⚠️ ' + t('error.connection'));
    }
    
    btn.disabled = false;
    saveChatHistory();
    scrollChatBottom();
}

function appendChatBubble(role, html, isRaw = false) {
    const container = document.getElementById('chat-messages');
    const bubble = document.createElement('div');
    bubble.className = `chat-bubble chat-${role}`;
    if (isRaw) {
        bubble.innerHTML = html;
    } else if (role === 'user') {
        bubble.textContent = html;
    } else {
        bubble.innerHTML = html;
    }
    container.appendChild(bubble);
    scrollChatBottom();
    return bubble;
}

function formatChatReply(text) {
    // Convert markdown-like formatting
    let html = escapeHtml(text);
    // Bold **text**
    html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    // Italic *text*
    html = html.replace(/\*(.+?)\*/g, '<em>$1</em>');
    // Lists
    html = html.replace(/^- (.+)$/gm, '<li>$1</li>');
    html = html.replace(/(<li>.*<\/li>)/s, '<ul>$1</ul>');
    // Numbered lists  
    html = html.replace(/^(\d+)\. (.+)$/gm, '<li>$2</li>');
    // Line breaks
    html = html.replace(/\n/g, '<br>');
    // Clean up consecutive ul tags
    html = html.replace(/<\/ul>\s*<br>\s*<ul>/g, '');
    return html;
}

function renderChatHistory() {
    const container = document.getElementById('chat-messages');
    if (chatHistory.length === 0) return;
    
    // Hide welcome
    const welcome = container.querySelector('.chat-welcome');
    if (welcome) welcome.style.display = 'none';
    
    chatHistory.forEach(msg => {
        if (msg.role === 'user') {
            appendChatBubble('user', msg.text);
        } else {
            appendChatBubble('gemini', formatChatReply(msg.text));
        }
    });
    scrollChatBottom();
}

function scrollChatBottom() {
    const container = document.getElementById('chat-messages');
    setTimeout(() => container.scrollTop = container.scrollHeight, 50);
}

function clearChat() {
    chatHistory = [];
    api('chat_clear', {}, 'POST').catch(() => {});
    const container = document.getElementById('chat-messages');
    container.innerHTML = `
        <div class="chat-welcome">
            <svg class="gemini-icon-lg" viewBox="0 0 24 24" width="48" height="48" fill="#6366f1"><path d="M12 0C12 6.627 6.627 12 0 12c6.627 0 12 5.373 12 12 0-6.627 5.373-12 12-12-6.627 0-12-5.373-12-12z"/></svg>
            <h3>${t('chat.welcome')}</h3>
            <p>${t('chat.welcome_desc')}</p>
            <div class="chat-suggestions">
                <button class="chat-suggestion" onclick="sendChatSuggestion(t('chat.suggestion_snack_text'))">${t('chat.suggestion_snack')}</button>
                <button class="chat-suggestion" onclick="sendChatSuggestion(t('chat.suggestion_juice_text'))">${t('chat.suggestion_juice')}</button>
                <button class="chat-suggestion" onclick="sendChatSuggestion(t('chat.suggestion_light_text'))">${t('chat.suggestion_light')}</button>
                <button class="chat-suggestion" onclick="sendChatSuggestion(t('chat.suggestion_expiry_text'))">${t('chat.suggestion_expiry')}</button>
            </div>
        </div>
    `;
    showToast(t('chat.cleared'), 'success');
}

function saveChatHistory() {
    // Keep last 50 messages max
    if (chatHistory.length > 50) {
        const trimmed = chatHistory.length - 50;
        chatHistory = chatHistory.slice(-50);
        _chatSavedCount = Math.max(0, _chatSavedCount - trimmed);
    }
    // Only save messages that haven't been saved yet (prevent duplicates)
    const unsaved = chatHistory.slice(_chatSavedCount);
    if (unsaved.length === 0) return;
    api('chat_save', {}, 'POST', { messages: unsaved }).then(() => {
        _chatSavedCount = chatHistory.length;
    }).catch(() => {});
}

// ===== NETWORK ERROR OVERLAY + OFFLINE MODE =====
// ─────────────────────────────────────────────────────────────────────────────
// State
let _networkDown          = false; // true while overlay is visible
let _networkFailCount     = 0;     // consecutive TypeError failures in api()
let _offlineMode          = false; // true = overlay hidden, banner showing, cache reads/write queue
let _offlineBannerTimer   = null;  // auto-enter offline mode after delay
let _continueBtnTimer     = null;  // show "Continue offline" button after 3 s
const _NETWORK_FAIL_THRESHOLD  = 3;
const _OFFLINE_MODE_DELAY_MS   = 8000; // auto-enter offline mode after 8 s of overlay
const _OFFLINE_CACHE_KEY       = '_evershelf_inv_cache';
const _OFFLINE_SETTINGS_KEY    = '_evershelf_settings_cache';
const _OFFLINE_PRODUCTS_KEY    = '_evershelf_products_cache';
const _OFFLINE_QUEUE_KEY       = '_evershelf_op_queue';
const _OFFLINE_SHOPPING_KEY    = '_evershelf_shopping_cache';
const _OFFLINE_SMART_KEY       = '_evershelf_smart_shopping_cache';
const _SHOPPING_SECTIONS_KEY   = '_shop_sec_collapsed';

// ─── Local cache helpers ────────────────────────────────────────────────────
function _offlineCacheGet() {
    try { return JSON.parse(localStorage.getItem(_OFFLINE_CACHE_KEY)) || null; } catch { return null; }
}
function _offlineCacheSet(inventory) {
    try { localStorage.setItem(_OFFLINE_CACHE_KEY, JSON.stringify(inventory)); } catch(e) {}
}
function _offlineCacheGetSettings() {
    try { return JSON.parse(localStorage.getItem(_OFFLINE_SETTINGS_KEY)) || null; } catch { return null; }
}
function _offlineCacheSetSettings(settings) {
    try { localStorage.setItem(_OFFLINE_SETTINGS_KEY, JSON.stringify(settings)); } catch(e) {}
}
function _offlineProductsGet() {
    try { return JSON.parse(localStorage.getItem(_OFFLINE_PRODUCTS_KEY)) || null; } catch { return null; }
}
function _offlineProductsSet(products) {
    try { localStorage.setItem(_OFFLINE_PRODUCTS_KEY, JSON.stringify(products)); } catch(e) {}
}
function _offlineProductFromInventoryItem(item) {
    if (!item || !item.barcode) return null;
    return {
        id: item.product_id,
        barcode: item.barcode,
        name: item.name || '',
        brand: item.brand || '',
        category: item.category || '',
        image_url: item.image_url || '',
        unit: item.unit || 'pz',
        default_quantity: item.default_quantity ?? 1,
        package_unit: item.package_unit || '',
        notes: item.notes || '',
    };
}
function _offlineSearchBarcode(barcode) {
    const code = String(barcode || '').trim();
    if (!code) return { found: false, _offline: true };
    const products = _offlineProductsGet() || [];
    const fromProducts = products.find(p => String(p.barcode || '') === code);
    if (fromProducts) return { found: true, product: fromProducts, _offline: true };
    const inv = _offlineCacheGet() || [];
    const fromInv = inv.find(i => String(i.barcode || '') === code);
    if (fromInv) {
        const product = _offlineProductFromInventoryItem(fromInv);
        if (product) return { found: true, product, _offline: true };
    }
    return { found: false, _offline: true };
}
function _offlineQueueGet() {
    try { return JSON.parse(localStorage.getItem(_OFFLINE_QUEUE_KEY)) || []; } catch { return []; }
}
function _offlineQueueSet(q) {
    try { localStorage.setItem(_OFFLINE_QUEUE_KEY, JSON.stringify(q)); } catch(e) {}
}

function _offlineShoppingCacheGet() {
    try { return JSON.parse(localStorage.getItem(_OFFLINE_SHOPPING_KEY)) || null; } catch { return null; }
}
function _offlineShoppingCacheSet(data) {
    try { localStorage.setItem(_OFFLINE_SHOPPING_KEY, JSON.stringify({ ...data, ts: Date.now() })); } catch(e) {}
}
function _offlineSmartCacheGet() {
    try { return JSON.parse(localStorage.getItem(_OFFLINE_SMART_KEY)) || null; } catch { return null; }
}
function _offlineSmartCacheSet(items, planDays) {
    try {
        localStorage.setItem(_OFFLINE_SMART_KEY, JSON.stringify({
            items,
            plan_days: planDays ?? getShoppingPlanDays(),
            ts: Date.now(),
        }));
    } catch(e) {}
}

function _shoppingSectionsCollapsedGet() {
    try { return JSON.parse(localStorage.getItem(_SHOPPING_SECTIONS_KEY)) || {}; } catch { return {}; }
}
function _shoppingSectionsCollapsedSet(map) {
    try { localStorage.setItem(_SHOPPING_SECTIONS_KEY, JSON.stringify(map)); } catch(e) {}
}
function toggleShoppingSection(secKey) {
    const m = _shoppingSectionsCollapsedGet();
    m[secKey] = !m[secKey];
    _shoppingSectionsCollapsedSet(m);
    renderShoppingItems();
}
function _offlineQueuePush(action, body) {
    const q = _offlineQueueGet();
    if (q.length >= 100) q.shift(); // cap at 100
    q.push({ action, body: body ? { ...body } : null, ts: Date.now() });
    _offlineQueueSet(q);
    _renderOfflineBanner();
}

// ─── Offline API handler: called by api() when _offlineMode is true ─────────
function _handleOfflineApi(action, params, body) {
    // ─── Reads: computed from or served directly from local cache ────────────
    if (action === 'inventory_list') {
        let inv = _offlineCacheGet() || [];
        // Client-side location filter so per-location views work correctly
        if (params && params.location) inv = inv.filter(i => i.location === params.location);
        return { success: true, inventory: inv, _offline: true };
    }
    if (action === 'inventory_summary') {
        const inv = _offlineCacheGet() || [];
        const byLoc = {};
        inv.forEach(item => {
            const loc = item.location || 'other';
            if (!byLoc[loc]) byLoc[loc] = { location: loc, product_count: 0 };
            byLoc[loc].product_count++;
        });
        return { success: true, summary: Object.values(byLoc), _offline: true };
    }
    if (action === 'stats') {
        const inv  = _offlineCacheGet() || [];
        const now  = Date.now();
        const MS   = 86400000;
        const expiring_soon = inv.filter(i => {
            if (!i.expiry_date || !(parseFloat(i.quantity) > 0)) return false;
            const d = Math.floor((new Date(i.expiry_date).getTime() - now) / MS);
            return d >= 0 && d <= 7;
        });
        const expired = inv.filter(i => {
            if (!i.expiry_date || !(parseFloat(i.quantity) > 0)) return false;
            return new Date(i.expiry_date).getTime() < now;
        });
        return {
            success: true, _offline: true,
            expiring_soon, expired,
            // Mark is_edible:true so the banner's server-trust check produces no false positives offline.
            // The client-side opened-shelf-life check in loadBannerAlerts() already handles genuinely
            // expired opened items using estimateOpenedExpiryDays().
            opened: inv.filter(i => i.opened_at && parseFloat(i.quantity) > 0).map(i => ({ ...i, is_edible: true })),
            used_30d: 0, wasted_30d: 0, used_prev_30d: 0, wasted_prev_30d: 0,
            used_prev_60d: 0, wasted_prev_60d: 0,
        };
    }
    if (action === 'get_settings') {
        const cached = _offlineCacheGetSettings();
        if (cached) return { ...cached, _offline: true };
        return { success: false, _offline: true };
    }
    if (action === 'search_barcode' || action === 'resolve_barcode') {
        const found = _offlineSearchBarcode(params && params.barcode);
        if (found.found) return { ...found, source: 'local' };
        return { found: false, source: 'offline' };
    }
    if (action === 'products_search') {
        const q = String((params && params.q) || '').trim().toLowerCase();
        if (!q || q.length < 2) return { success: true, products: [], _offline: true };
        const products = (_offlineProductsGet() || []).filter(p => {
            const hay = `${p.name || ''} ${p.brand || ''} ${p.barcode || ''}`.toLowerCase();
            return hay.includes(q);
        }).slice(0, 20);
        return { success: true, products, _offline: true };
    }
    if (action === 'shopping_list' || action === 'bring_list') {
        const cached = _offlineShoppingCacheGet();
        if (cached?.items?.length) {
            return { success: true, purchase: cached.items, listUUID: cached.listUUID || 'offline', recently: [], _offline: true };
        }
        if (shoppingItems.length) {
            return { success: true, purchase: shoppingItems, listUUID: shoppingListUUID || 'offline', recently: [], _offline: true };
        }
        return { success: true, purchase: [], listUUID: '', recently: [], _offline: true };
    }
    if (action === 'smart_shopping') {
        const cached = _offlineSmartCacheGet();
        if (cached?.items?.length) {
            return { success: true, items: cached.items, _offline: true };
        }
        if (smartShoppingItems.length) {
            return { success: true, items: smartShoppingItems, _offline: true };
        }
        return { success: true, items: [], _offline: true };
    }
    // Safe empty responses for read-only endpoints that can't be served from cache
    const EMPTY_READS = {
        'recent_popular_products': { success: true, recent: [], popular: [], recent_ids: [], _offline: true },
        'consumption_predictions': { success: true, predictions: [], _offline: true },
        'inventory_anomalies':     { success: true, anomalies: [], _offline: true },
        'inventory_finished_items':{ success: true, finished: [], _offline: true },
        'recipe_archive':          { success: true, recipes: [], _offline: true },
        'food_facts':              { success: false, _offline: true },
        'notifications':           { success: true, notifications: [], _offline: true },
    };
    if (EMPTY_READS[action]) return EMPTY_READS[action];

    // ─── Writes: queue and apply optimistic update to cache ──────────────────
    const QUEUEABLE = ['inventory_update', 'inventory_use', 'inventory_delete',
                       'inventory_add', 'inventory_confirm_finished', 'inventory_restore_ghost',
                       'shopping_remove', 'shopping_add'];
    if (QUEUEABLE.includes(action)) {
        _offlineQueuePush(action, body);
        _applyOptimisticUpdate(action, body);
        _applyShoppingOptimisticUpdate(action, body);
        return { success: true, _offline: true, _queued: true };
    }
    // Everything else (AI, Bring!, etc.): return offline error
    return { success: false, error: 'offline', _offline: true };
}

// Optimistically update the cached inventory so the UI reflects the change immediately
function _applyOptimisticUpdate(action, body) {
    if (!body) return;
    const cache = _offlineCacheGet();
    if (!cache) return;
    let changed = false;
    if (action === 'inventory_update' && body.id) {
        const idx = cache.findIndex(i => i.id === body.id);
        if (idx >= 0) { Object.assign(cache[idx], body); changed = true; }
    } else if (action === 'inventory_use' && body.id) {
        const idx = cache.findIndex(i => i.id === body.id);
        if (idx >= 0) {
            const used = parseFloat(body.qty ?? body.amount ?? 0);
            cache[idx].quantity = Math.max(0, parseFloat(cache[idx].quantity ?? 0) - used);
            changed = true;
        }
    } else if (action === 'inventory_delete' && body.id) {
        const idx = cache.findIndex(i => i.id === body.id);
        if (idx >= 0) { cache.splice(idx, 1); changed = true; }
    } else if (action === 'inventory_add') {
        cache.push({ ...body, id: -(Date.now()), _offline: true });
        changed = true;
    }
    if (changed) _offlineCacheSet(cache);
}

function _applyShoppingOptimisticUpdate(action, body) {
    if (!body) return;
    if (action === 'shopping_remove') {
        const name = (body.name || '').toLowerCase();
        if (!name) return;
        shoppingItems = (shoppingItems || []).filter(i => i.name.toLowerCase() !== name);
        _offlineShoppingCacheSet({ items: shoppingItems, listUUID: shoppingListUUID });
        _markBringPurchased([body.name]);
        renderShoppingItems();
        loadShoppingCount();
    } else if (action === 'shopping_add' && body.items?.length) {
        for (const it of body.items) {
            if (!shoppingItems.some(i => i.name.toLowerCase() === (it.name || '').toLowerCase())) {
                shoppingItems.push({ name: it.name, rawName: it.rawName || it.name, specification: it.specification || '' });
            }
        }
        _offlineShoppingCacheSet({ items: shoppingItems, listUUID: shoppingListUUID });
    }
}

// ─── Offline mode: banner + cache reads/write queue ─────────────────────────
function _enterOfflineMode() {
    if (_offlineMode) return;
    _offlineMode = true;
    clearTimeout(_offlineBannerTimer);
    clearTimeout(_continueBtnTimer);
    // Hide the full overlay (no "restored" animation)
    _hideNetworkOverlay(false);
    // Unblock the UI and show a subtle offline indicator
    document.body.classList.add('offline-mode');
    _renderOfflineBanner(true); // loading state
    // Load page content from the local cache, then update banner to "ready"
    const p = refreshCurrentPage();
    const afterLoad = () => _renderOfflineBanner(false);
    if (p && typeof p.then === 'function') p.then(afterLoad).catch(afterLoad);
    else setTimeout(afterLoad, 600);
}

async function _exitOfflineMode() {
    _offlineMode = false; // first — so api() calls inside sync go to server
    document.body.classList.remove('offline-mode');
    const q = _offlineQueueGet();
    if (q.length > 0) {
        const synced = await _syncOfflineQueue();
        if (synced > 0) {
            showToast(t('error.offline_synced').replace('{n}', synced), 'success');
            refreshCurrentPage();
        }
    }
    const banner = document.getElementById('offline-banner');
    if (banner) banner.style.display = 'none';
}

function _renderOfflineBanner(loading = false) {
    const banner = document.getElementById('offline-banner');
    const textEl = banner ? banner.querySelector('.offline-banner-text') : null;
    if (!banner || !textEl) return;
    const q = _offlineQueueGet();
    if (q.length > 0) {
        textEl.innerHTML = t('error.offline_ops_pending').replace('{n}', q.length);
    } else if (loading) {
        textEl.innerHTML = `<span class="offline-banner-dot"></span>${t('error.offline_reading_cache')}`;
    } else {
        const n = (_offlineCacheGet() || []).filter(i => parseFloat(i.quantity) > 0).length;
        const msg = t('error.offline_cache_ready').replace('{n}', n);
        textEl.innerHTML = msg;
    }
    banner.style.display = '';
}

async function _syncOfflineQueue() {
    const q = _offlineQueueGet();
    if (q.length === 0) return 0;
    let synced = 0;
    const failed = [];
    const tempIdMap = {}; // negative temp id → real server id
    for (const op of q) {
        if (!op.action) continue;
        let body = op.body ? { ...op.body } : {};
        // Resolve any temp IDs from earlier add operations in this batch
        if (typeof body.id === 'number' && body.id < 0 && tempIdMap[body.id]) {
            body = { ...body, id: tempIdMap[body.id] };
        }
        try {
            const result = await api(op.action, {}, 'POST', body);
            if (result && result._offline) { failed.push(op); continue; }
            if (op.action === 'inventory_add' && result?.id && op.body?.id < 0) {
                tempIdMap[op.body.id] = result.id;
            }
            synced++;
        } catch(_) { failed.push(op); }
    }
    _offlineQueueSet(failed);
    return synced;
}

// ─── Overlay show / hide ────────────────────────────────────────────────────
function _showNetworkOverlay() {
    if (_networkDown) return;
    if (_offlineMode) return; // Already in offline mode — don't interrupt the user
    _networkDown      = true;
    _networkFailCount = 0;
    const el = document.getElementById('network-error-overlay');
    if (!el) return;
    el.classList.remove('restored', 'checking');
    const titleEl    = document.getElementById('net-error-title');
    const subtitleEl = document.getElementById('net-error-subtitle');
    const iconEl     = document.getElementById('net-error-icon');
    const statusEl   = document.getElementById('net-error-status');
    const contBtn    = document.getElementById('net-error-continue-btn');
    if (titleEl)    titleEl.textContent    = t('error.offline_title');
    if (subtitleEl) subtitleEl.textContent = t('error.offline_subtitle');
    if (iconEl)     iconEl.textContent     = '📡';
    if (statusEl)   statusEl.textContent   = '';
    if (contBtn)  { contBtn.style.display = 'none'; contBtn.classList.remove('visible'); }
    el.style.display = 'flex';
    requestAnimationFrame(() => el.classList.add('visible'));
    // Show "Continue offline" button after 3 s
    clearTimeout(_continueBtnTimer);
    _continueBtnTimer = setTimeout(() => {
        if (!_networkDown) return;
        if (contBtn) {
            contBtn.textContent = t('error.offline_continue');
            contBtn.style.display = '';
            requestAnimationFrame(() => contBtn.classList.add('visible'));
        }
    }, 3000);
    // Auto-enter offline mode after 8 s if user hasn't acted
    clearTimeout(_offlineBannerTimer);
    _offlineBannerTimer = setTimeout(() => {
        if (_networkDown && !_offlineMode) _enterOfflineMode();
    }, _OFFLINE_MODE_DELAY_MS);
}

function _hideNetworkOverlay(showRestoredMsg) {
    clearTimeout(_continueBtnTimer);
    clearTimeout(_offlineBannerTimer);
    _networkDown      = false;
    _networkFailCount = 0;
    const el      = document.getElementById('network-error-overlay');
    const contBtn = document.getElementById('net-error-continue-btn');
    if (contBtn) { contBtn.style.display = 'none'; contBtn.classList.remove('visible'); }
    if (!el) return;
    if (showRestoredMsg) {
        el.classList.add('restored');
        el.classList.remove('checking');
        const titleEl  = document.getElementById('net-error-title');
        const iconEl   = document.getElementById('net-error-icon');
        const statusEl = document.getElementById('net-error-status');
        if (titleEl)  titleEl.textContent  = t('error.offline_restored');
        if (iconEl)   iconEl.textContent   = '✅';
        if (statusEl) statusEl.textContent = '';
        setTimeout(() => {
            el.classList.remove('visible');
            setTimeout(() => { el.style.display = 'none'; el.classList.remove('restored', 'checking'); }, 450);
        }, 1800);
    } else {
        el.classList.remove('visible');
        setTimeout(() => { el.style.display = 'none'; el.classList.remove('restored', 'checking'); }, 450);
    }
}

// Ping the server; if reachable call _handleServerRestored() via heartbeat
async function _networkPingOnce() {
    const el       = document.getElementById('network-error-overlay');
    const iconEl   = document.getElementById('net-error-icon');
    const statusEl = document.getElementById('net-error-status');
    if (el && el.classList.contains('visible')) {
        el.classList.add('checking');
        if (iconEl)   iconEl.textContent   = '🔄';
        if (statusEl) statusEl.textContent = t('error.offline_checking');
    }
    try {
        const res = await fetch(`${API_BASE}?action=ping&_t=${Date.now()}`, {
            method: 'GET', cache: 'no-store',
            signal: AbortSignal.timeout(4000),
        });
        if (res.ok) {
            // Let heartbeat confirm the state authoritatively
            _heartbeatRetry();
        } else { throw new Error('not-ok'); }
    } catch (_) {
        if (el)       el.classList.remove('checking');
        if (iconEl)   iconEl.textContent   = '📡';
        if (statusEl) statusEl.textContent = '';
    }
}

// Browser-native online/offline events
window.addEventListener('offline', () => _showNetworkOverlay());
window.addEventListener('online',  () => {
    clearTimeout(_offlineBannerTimer); // don't auto-enter offline mode if we just came back
    _networkPingOnce();
});

// ===== SCREENSAVER & INACTIVITY AUTO-REFRESH =====
let _inactivityTimer = null;
let _screensaverActive = false;

// ── Auto-home: always-on 2-minute idle return to dashboard ──
let _autoHomeTimer = null;
const AUTO_HOME_MS = 2 * 60 * 1000; // 2 minutes

function _resetAutoHomeTimer() {
    if (_currentPageId === 'shopping') return;
    clearTimeout(_autoHomeTimer);
    _autoHomeTimer = setTimeout(_triggerAutoHome, AUTO_HOME_MS);
}

function _cancelAutoHomeTimer() {
    clearTimeout(_autoHomeTimer);
    _autoHomeTimer = null;
}

function _triggerAutoHome() {
    if (_screensaverActive) return;
    if (document.body.classList.contains('cooking-mode-active')) return;
    if (_currentPageId === 'dashboard') return;
    // Stay on shopping / scan-add flow while user is in the store
    if (_currentPageId === 'shopping') return;
    if (_currentPageId === 'scan' && (_shoppingBoughtFlow || _spesaScanTarget || _spesaMode)) return;
    if (_currentPageId === 'add' && (_shoppingBoughtFlow || _spesaScanTarget)) return;
    const modal = document.getElementById('modal-overlay');
    if (modal && modal.style.display === 'flex') return;
    showPage('dashboard');
}
let _screensaverClockInterval = null;
let _screensaverFactInterval = null;
let _screensaverData = null; // cached data for fact generation
const SCREENSAVER_FACT_DURATION = 5 * 60 * 1000; // 5 minutes per fact

function _screensaverTimeoutMs() {
    const mins = parseInt(getSettings().screensaver_timeout || 5, 10);
    return (isNaN(mins) || mins < 1 ? 5 : mins) * 60 * 1000;
}

function resetInactivityTimer() {
    if (_screensaverActive) return;
    if (_currentPageId === 'shopping') return;
    clearTimeout(_inactivityTimer);
    _inactivityTimer = setTimeout(activateScreensaver, _screensaverTimeoutMs());
}

function activateScreensaver() {
    if (_screensaverActive) return;
    if (_currentPageId === 'shopping') return;
    if (document.body.classList.contains('cooking-mode-active')) return;
    _cancelAutoHomeTimer();
    _screensaverActive = true;
    const overlay = document.getElementById('screensaver');
    overlay.style.display = 'flex';
    // Fade in
    requestAnimationFrame(() => overlay.classList.add('visible'));
    updateScreensaverClock();
    _screensaverClockInterval = setInterval(updateScreensaverClock, 1000);
    updateScreensaverShopping();
    syncShoppingPriceTotal(false).then(() => updateScreensaverShopping());
    // Load data and start fact/nutrition rotation
    loadScreensaverData().then(() => {
        _startScreensaverRotation();
    });
}

function updateScreensaverClock() {
    const now = new Date();
    const _ssLocale = _currentLang === 'de' ? 'de-DE' : _currentLang === 'en' ? 'en-GB' : 'it-IT';
    const time = now.toLocaleTimeString(_ssLocale, { hour: '2-digit', minute: '2-digit' });
    const date = now.toLocaleDateString(_ssLocale, { weekday: 'long', day: 'numeric', month: 'long' });
    const el = document.getElementById('screensaver-clock');
    if (el) el.innerHTML = `${time}<div class="screensaver-date">${date}</div>`;
    updateScreensaverMealPlan();
}

/** Show/hide the planned meal type badge on the screensaver based on current time slot. */
function updateScreensaverShopping() {
    const el = document.getElementById('screensaver-shopping');
    if (!el) return;
    const s = getSettings();
    const itemCount = shoppingItems.length;
    if (itemCount === 0) { el.style.display = 'none'; return; }

    const countCol = `<div class="ss-shop-col">
        <div class="ss-shop-value">${itemCount}</div>
        <div class="ss-shop-label">🛒 articoli</div>
    </div>`;

    let priceCol = '';
    if (s.price_enabled) {
        _loadCanonicalTotalFromSession();
        const label = _canonicalShoppingTotal?.label;
        if (label) {
            priceCol = `<div class="ss-shop-sep"></div>
            <div class="ss-shop-col">
                <div class="ss-shop-value">${escapeHtml(label.replace(/^ca\.\s*/, ''))}</div>
                <div class="ss-shop-label">💰 ${escapeHtml(t('shopping.price_total_short') || 'spesa stimata')}</div>
            </div>`;
        }
    }

    el.innerHTML = `<div class="screensaver-shopping-card">${countCol}${priceCol}</div>`;
    el.style.display = 'block';
}

function updateScreensaverMealPlan() {
    const el = document.getElementById('screensaver-mealplan');
    if (!el) return;
    const s = getSettings();
    if (s.meal_plan_enabled === false) { el.style.display = 'none'; return; }
    const hour = new Date().getHours();
    // Before 15:00 show pranzo, from 15:00 onwards show cena
    const slot = hour < 15 ? 'pranzo' : 'cena';
    const typeId = getTodayMealPlanType(slot);
    if (!typeId || typeId === 'libero') { el.style.display = 'none'; return; }
    const mpt = getMealPlanTypeMap()[typeId];
    if (!mpt) { el.style.display = 'none'; return; }
    const slotLabel = slot === 'pranzo' ? '🌤️ ' + t('meal_types.pranzo') : '🌙 ' + t('meal_types.cena');
    el.innerHTML = `<span class="screensaver-mealplan-badge">${slotLabel} · ${mpt.icon} ${mpt.label}</span>`;
    el.style.display = 'block';
}

function dismissScreensaver(targetPage) {
    if (!_screensaverActive) return;
    clearInterval(_screensaverClockInterval);
    clearInterval(_screensaverFactInterval);
    clearInterval(_ssRotationTimer);
    const nutrEl = document.getElementById('screensaver-nutrition');
    if (nutrEl) { nutrEl.style.display = 'none'; nutrEl.innerHTML = ''; }
    const factEl = document.getElementById('screensaver-fact');
    if (factEl) { factEl.classList.remove('visible'); }
    const overlay = document.getElementById('screensaver');
    overlay.classList.remove('visible');
    setTimeout(() => {
        overlay.style.display = 'none';
        _screensaverActive = false;
        _screensaverData = null;
        if (targetPage) {
            showPage(targetPage);
        } else {
            refreshCurrentPage();
        }
        resetInactivityTimer();
        _resetAutoHomeTimer();
    }, 400);
}

// Handle for screensaver rotation timer
let _ssRotationTimer = null;
let _ssSlot = 0; // 0=fact, 1=nutrition, 2=fact, 3=nutrition …

/**
 * Start the screensaver content rotation:
 * Every SCREENSAVER_FACT_DURATION ms flip between fact text and nutrition charts.
 */
function _startScreensaverRotation() {
    clearInterval(_ssRotationTimer);
    _ssSlot = 0;
    _showScreensaverSlot(0);
    _screensaverFactInterval = _ssRotationTimer = setInterval(() => {
        _ssSlot = (_ssSlot + 1) % 4; // 4 steps: fact, nutr, fact, nutr (with repeats for more facts)
        _showScreensaverSlot(_ssSlot);
    }, SCREENSAVER_FACT_DURATION);
}

function _showScreensaverSlot(slot) {
    const factEl  = document.getElementById('screensaver-fact');
    const nutrEl  = document.getElementById('screensaver-nutrition');
    if (!factEl || !nutrEl) return;
    const showNutr = slot % 2 === 1; // odd slots = nutrition
    // Fade out both
    factEl.classList.remove('visible');
    nutrEl.style.opacity = '0';
    nutrEl.style.transition = 'opacity 1.5s ease';
    setTimeout(() => {
        if (!_screensaverActive) return;
        if (showNutr) {
            factEl.style.display = 'none';
            nutrEl.style.display = 'flex';
            _renderScreensaverNutrition();
            requestAnimationFrame(() => { nutrEl.style.opacity = '1'; });
        } else {
            nutrEl.style.display = 'none';
            factEl.style.display = '';
            factEl.textContent = generateScreensaverFact();
            requestAnimationFrame(() => { factEl.classList.add('visible'); });
        }
    }, 1600);
}

/**
 * Render animated 3D-style pie charts inside the screensaver.
 * Shows: category distribution, health score, freshness.
 */
function _renderScreensaverNutrition() {
    const el = document.getElementById('screensaver-nutrition');
    if (!el) return;
    // Use cached nutrition data from dashboard if available, else build from screensaver inventory
    const inv = (_screensaverData && _screensaverData.inventory) || [];
    const data = (_nutriData && _nutriData.slices) ? _nutriData : _buildNutritionData(inv);
    if (!data) { el.style.display = 'none'; return; }

    const { slices, total, healthScore, varietyScore, freshnessScore, fresh_pct } = data;
    const top4 = slices.slice(0, 4);

    // Build conic-gradient
    let deg = 0;
    const stops = top4.map(s => {
        const end = deg + s.pct * 3.6;
        const stop = `${s.color} ${deg.toFixed(1)}deg ${end.toFixed(1)}deg`;
        deg = end;
        return stop;
    });
    if (deg < 360) stops.push(`rgba(255,255,255,0.08) ${deg.toFixed(1)}deg 360deg`);
    const gradient = `conic-gradient(from 0deg, ${stops.join(', ')})`;

    // Three mini donut charts: categories, health, freshness
    const healthColor = healthScore >= 70 ? '#4ade80' : healthScore >= 45 ? '#fbbf24' : '#f87171';
    const freshColor  = freshnessScore >= 70 ? '#22d3ee' : freshnessScore >= 40 ? '#60a5fa' : '#94a3b8';
    const varColor    = varietyScore   >= 70 ? '#a78bfa' : varietyScore   >= 40 ? '#fbbf24' : '#64748b';

    el.innerHTML = `
    <div class="ss-nutr-wrap">
        <div class="ss-nutr-title">${t('nutrition.today_title')}</div>
        <div class="ss-nutr-charts">
            <!-- Main category pie -->
            <div class="ss-nutr-chart-block">
                <div class="ss-pie3d" id="ss-pie-main" style="--pie-bg:${gradient}"></div>
                <div class="ss-nutr-chart-label">${t('nutrition.products_n').replace('{n}', total)}</div>
                <div class="ss-nutr-legend">
                    ${top4.map(s => `<div class="ss-leg-row"><span style="background:${s.color}" class="ss-leg-dot"></span><span>${s.icon} ${t('categories.' + s.cat) || s.cat}</span><span class="ss-leg-pct">${s.pct}%</span></div>`).join('')}
                </div>
            </div>
            <!-- Score donuts -->
            <div class="ss-nutr-scores-col">
                ${_ssDonut(t('nutrition.label_health'), healthScore, healthColor)}
                ${_ssDonut(t('nutrition.label_variety'), varietyScore, varColor)}
                ${_ssDonut(t('nutrition.label_fresh'), fresh_pct, freshColor)}
            </div>
        </div>
    </div>`;

    // Trigger animations
    requestAnimationFrame(() => {
        const pie = document.getElementById('ss-pie-main');
        if (pie) setTimeout(() => pie.classList.add('ss-pie3d-ready'), 80);
        el.querySelectorAll('.ss-donut-ring').forEach(ring => {
            const val = parseInt(ring.dataset.val || 0);
            setTimeout(() => { ring.style.setProperty('--val', val); ring.classList.add('ss-donut-ready'); }, 200);
        });
    });
}

function _ssDonut(label, val, color) {
    return `<div class="ss-donut-wrap">
        <div class="ss-donut-ring" data-val="${val}" style="--color:${color};--val:0">
            <span class="ss-donut-text">${val}%</span>
        </div>
        <div class="ss-donut-label">${label}</div>
    </div>`;
}

// Load all data needed for screensaver facts
async function loadScreensaverData() {
    try {
        const [statsRes, invRes, bringRes, smartRes] = await Promise.all([
            api('stats'),
            api('inventory_list'),
            api('shopping_list').catch(() => null),
            api('smart_shopping').catch(() => null)
        ]);
        _screensaverData = {
            stats: statsRes,
            inventory: invRes.inventory || [],
            shopping: bringRes && bringRes.success ? (bringRes.purchase || []) : []
        };
        // Keep smartShoppingItems fresh so _screensaverAutoAddItems has current data
        if (smartRes && Array.isArray(smartRes.items)) {
            smartShoppingItems = smartRes.items;
        }
    } catch (e) {
        _screensaverData = { stats: {}, inventory: [], shopping: [] };
    }
    // Silently add critical/high-urgency items to Bring! while screensaver is showing
    _screensaverAutoAddItems();
}

/**
 * Silently adds critical and high-urgency shopping items to Bring! when the
 * screensaver activates. No toast shown — the shopping panel count updates
 * automatically after the add. Rate-limited to once per 30 minutes per session.
 */
async function _screensaverAutoAddItems() {
    const RATE_MS = 30 * 60 * 1000;
    const lastRun = parseInt(sessionStorage.getItem('_ssAutoAddTs') || '0');
    if (Date.now() - lastRun < RATE_MS) return;

    const toAdd = smartShoppingItems.filter(i => {
        if (i.on_bring) return false;
        const gName = i.shopping_name || i.name;
        if (_isBringPurchased(gName, i.urgency)) return false;
        return i.urgency === 'critical' || i.urgency === 'high';
    });
    if (toAdd.length === 0) return;

    sessionStorage.setItem('_ssAutoAddTs', String(Date.now()));
    const itemsToAdd = toAdd.map(i => ({
        name: i.shopping_name || i.name,
        specification: _urgencyToSpec(i.urgency),
    }));
    try {
        const result = await api('shopping_add', {}, 'POST', { items: itemsToAdd, listUUID: shoppingListUUID });
        if (result.success && result.added > 0) {
            _markAutoAddedBring(itemsToAdd.map(i => i.name));
            logOperation('bring_auto_add_screensaver', { added: itemsToAdd.map(i => i.name) });
            // Refresh bring list silently then update screensaver counter
            loadShoppingList._bgCall = true;
            loadShoppingList().then(() => syncShoppingPriceTotal(false));
        }
    } catch (e) { /* ignore */ }
}

// Show next random fact with fade in/out
function showNextScreensaverFact() {
    const el = document.getElementById('screensaver-fact');
    if (!el) return;
    el.classList.remove('visible');
    setTimeout(() => {
        el.textContent = generateScreensaverFact();
        el.classList.add('visible');
    }, 1600);
}

// Generate a dynamic fact from available data
function generateScreensaverFact() {
    const d = _screensaverData || { stats: {}, inventory: [], shopping: [] };
    const inv = d.inventory;
    const stats = d.stats;
    const shop = d.shopping;
    const now = new Date();
    const hour = now.getHours();

    // Pre-compute useful data
    const expired = stats.expired || [];
    const expiringSoon = stats.expiring_soon || [];
    const totalProducts = stats.total_products || inv.length;
    const totalItems = stats.total_items || 0;

    const byLocation = {};
    const byCategory = {};
    const withExpiry = [];
    const noExpiry = [];
    const expiringThisWeek = [];
    const expiringThisMonth = [];
    const inFreezer = [];
    const inFrigo = [];
    const inDispensa = [];

    for (const item of inv) {
        // by location
        const loc = item.location || 'altro';
        if (!byLocation[loc]) byLocation[loc] = [];
        byLocation[loc].push(item);
        if (loc === 'freezer') inFreezer.push(item);
        else if (loc === 'frigo') inFrigo.push(item);
        else if (loc === 'dispensa') inDispensa.push(item);

        // by category
        const cat = mapToLocalCategory(item.category, item.name);
        if (!byCategory[cat]) byCategory[cat] = [];
        byCategory[cat].push(item);

        // expiry
        if (item.expiry_date) {
            withExpiry.push(item);
            const days = daysUntilExpiry(item.expiry_date);
            if (days >= 0 && days <= 7) expiringThisWeek.push(item);
            if (days >= 0 && days <= 30) expiringThisMonth.push(item);
        } else {
            noExpiry.push(item);
        }
    }

    // Greeting based on time
    const greeting = hour < 12 ? t('facts.greeting_morning') : hour < 18 ? t('facts.greeting_afternoon') : t('facts.greeting_evening');

    // Random item picker
    const rItem = (arr) => arr.length ? arr[Math.floor(Math.random() * arr.length)] : null;

    // All fact generators
    const facts = [];

    // --- Expired items facts ---
    if (expired.length > 0) {
        facts.push(() => expired.length === 1 ? t('facts.expired_one') : t('facts.expired_many').replace('{n}', expired.length));
        facts.push(() => {
            const names = expired.slice(0, 3).map(i => i.name);
            const extra = expired.length > 3 ? ` ${t('facts.expired_list_more').replace('{n}', expired.length - 3)}` : '';
            return t('facts.expired_list').replace('{names}', names.join(', ') + extra);
        });
        const freezerExpired = expired.filter(i => i.location === 'freezer');
        if (freezerExpired.length > 0) {
            facts.push(() => {
                const item = rItem(freezerExpired);
                const safety = getExpiredSafety(item, Math.abs(daysUntilExpiry(item.expiry_date)));
                if (safety.level === 'ok' || safety.level === 'warning') {
                    return t('facts.freezer_expired_ok').replace('{name}', item.name);
                }
                return t('facts.freezer_expired_old').replace('{name}', item.name);
            });
        }
        const frigoExpired = expired.filter(i => i.location === 'frigo');
        if (frigoExpired.length > 0) {
            facts.push(() => frigoExpired.length === 1 ? t('facts.fridge_expired_one') : t('facts.fridge_expired_many').replace('{n}', frigoExpired.length));
        }
    }

    // --- Expiring soon facts ---
    if (expiringSoon.length > 0) {
        facts.push(() => {
            const item = expiringSoon[0];
            const days = daysUntilExpiry(item.expiry_date);
            if (days === 0) return t('facts.expiring_today').replace('{name}', item.name);
            if (days === 1) return t('facts.expiring_tomorrow').replace('{name}', item.name);
            return t('facts.expiring_days').replace('{name}', item.name).replace('{days}', days);
        });
        if (expiringSoon.length > 1) {
            facts.push(() => t('facts.expiring_many').replace('{n}', expiringSoon.length));
        }
    }
    if (expiringThisWeek.length > 0) {
        facts.push(() => t('facts.expiring_this_week').replace('{n}', expiringThisWeek.length));
        facts.push(() => {
            const item = rItem(expiringThisWeek);
            const days = daysUntilExpiry(item.expiry_date);
            const locLabel = LOCATIONS[item.location]?.label || item.location;
            return t('facts.expiring_item_loc').replace('{name}', item.name).replace('{loc}', locLabel).replace('{days}', days).replace('{dayslabel}', days === 1 ? t('facts.day') : t('facts.days'));
        });
    }
    if (expiringThisMonth.length > 0) {
        facts.push(() => t('facts.expiring_this_month').replace('{n}', expiringThisMonth.length));
    }

    // Shopping list count/items are already visible in the shopping panel on the screensaver.
    // Items are added automatically by _screensaverAutoAddItems — no manual-action text needed.
    if (shop.length === 0) {
        facts.push(() => t('facts.shopping_empty'));
    }

    // --- Location-based facts ---
    if (inFrigo.length > 0) {
        facts.push(() => {
            const item = rItem(inFrigo);
            return t('facts.in_fridge').replace('{name}', item.name + (item.brand ? ' (' + item.brand + ')' : ''));
        });
    }
    if (inFreezer.length > 0) {
        facts.push(() => {
            const item = rItem(inFreezer);
            return t('facts.in_freezer').replace('{name}', item.name);
        });
    }

    // --- Category-based facts ---
    const catEntries = Object.entries(byCategory);
    if (catEntries.length > 0) {
        facts.push(() => {
            const sorted = catEntries.sort((a, b) => b[1].length - a[1].length);
            const top = sorted[0];
            const catLabel = top[0];
            const icon = CATEGORY_ICONS[catLabel] || '📦';
            return t('facts.top_category').replace('{icon}', icon).replace('{cat}', t('categories.' + catLabel) || catLabel).replace('{n}', top[1].length);
        });
        if (byCategory['carne'] && byCategory['carne'].length > 0) {
            facts.push(() => t('facts.cat_meat').replace('{n}', byCategory['carne'].length));
        }
        if (byCategory['latticini'] && byCategory['latticini'].length > 0) {
            facts.push(() => t('facts.cat_dairy').replace('{n}', byCategory['latticini'].length));
        }
        if (byCategory['verdura'] && byCategory['verdura'].length > 0) {
            facts.push(() => t('facts.cat_veggies').replace('{n}', byCategory['verdura'].length));
        }
        if (byCategory['frutta'] && byCategory['frutta'].length > 0) {
            facts.push(() => t('facts.cat_fruit').replace('{n}', byCategory['frutta'].length));
        }
        if (byCategory['bevande'] && byCategory['bevande'].length > 0) {
            facts.push(() => t('facts.cat_drinks').replace('{n}', byCategory['bevande'].length));
        }
        if (byCategory['surgelati'] && byCategory['surgelati'].length > 0) {
            facts.push(() => t('facts.cat_frozen').replace('{n}', byCategory['surgelati'].length));
        }
        if (byCategory['pasta'] && byCategory['pasta'].length > 0) {
            facts.push(() => t('facts.cat_pasta').replace('{n}', byCategory['pasta'].length));
        }
        if (byCategory['conserve'] && byCategory['conserve'].length > 0) {
            facts.push(() => t('facts.cat_canned').replace('{n}', byCategory['conserve'].length));
        }
        if (byCategory['snack'] && byCategory['snack'].length > 0) {
            facts.push(() => t('facts.cat_snacks').replace('{n}', byCategory['snack'].length));
        }
        if (byCategory['condimenti'] && byCategory['condimenti'].length > 0) {
            facts.push(() => t('facts.cat_condiments').replace('{n}', byCategory['condimenti'].length));
        }
    }

    // --- General inventory facts ---
    if (inv.length > 0) {
        facts.push(() => {
            const item = rItem(inv);
            return t('facts.item_random').replace('{name}', item.name).replace('{loc}', LOCATIONS[item.location]?.label || item.location);
        });
        facts.push(() => {
            const item = rItem(inv);
            const qty = formatQuantity(item.quantity, item.unit, item.default_quantity, item.package_unit);
            return t('facts.item_qty').replace('{name}', item.name).replace('{qty}', qty);
        });
    }
    if (noExpiry.length > 0) {
        facts.push(() => t('facts.no_expiry_count').replace('{n}', noExpiry.length));
    }
    if (withExpiry.length > 0) {
        // Find the one expiring furthest away
        const furthest = withExpiry.reduce((best, item) => {
            const d = daysUntilExpiry(item.expiry_date);
            return d > (best.d || 0) ? { item, d } : best;
        }, { d: 0 });
        if (furthest.item && furthest.d > 30) {
            facts.push(() => t('facts.furthest_expiry').replace('{name}', furthest.item.name).replace('{months}', Math.round(furthest.d / 30)));
        }
    }

    // --- Quantity-based facts ---
    const highQtyItems = inv.filter(i => parseFloat(i.quantity) >= 5);
    if (highQtyItems.length > 0) {
        facts.push(() => {
            const item = rItem(highQtyItems);
            const qty = formatQuantity(item.quantity, item.unit, item.default_quantity, item.package_unit);
            return t('facts.high_qty').replace('{name}', item.name).replace('{qty}', qty);
        });
    }
    const lowQtyItems = inv.filter(i => parseFloat(i.quantity) <= 1 && parseFloat(i.quantity) > 0);
    if (lowQtyItems.length > 0) {
        facts.push(() => {
            const item = rItem(lowQtyItems);
            return t('facts.low_qty_item').replace('{name}', item.name);
        });
        facts.push(() => t('facts.low_qty_count').replace('{n}', lowQtyItems.length));
    }

    // --- Time-of-day greetings & suggestions ---
    if (hour >= 6 && hour < 10) {
        if (byCategory['pane']) facts.push(() => t('facts.morning_bread'));
        if (byCategory['latticini']) facts.push(() => t('facts.morning_milk'));
        if (byCategory['frutta']) facts.push(() => t('facts.morning_fruit'));
    }
    if (hour >= 11 && hour < 14) {
        if (byCategory['pasta']) facts.push(() => t('facts.noon_pasta'));
        if (byCategory['verdura']) facts.push(() => t('facts.noon_salad').replace('{n}', byCategory['verdura'].length));
    }
    if (hour >= 17 && hour < 21) {
        if (byCategory['carne']) facts.push(() => t('facts.evening_meat'));
        if (byCategory['pesce']) facts.push(() => t('facts.evening_fish'));
        if (expiringThisWeek.length > 0) facts.push(() => t('facts.evening_expiring').replace('{n}', expiringThisWeek.length));
    }
    if (hour >= 21 || hour < 6) {
        if (expiringSoon.length > 0) facts.push(() => t('facts.night_reminder').replace('{names}', expiringSoon.slice(0,2).map(i=>i.name).join(', ')));
    }

    // --- Weekly stats ---
    const recentIn = stats.recent_in || 0;
    const recentOut = stats.recent_out || 0;
    if (recentIn > 0 && recentOut > 0) {
        facts.push(() => t('facts.weekly_balance').replace('{in}', recentIn).replace('{out}', recentOut));
    } else if (recentIn > 0) {
        facts.push(() => t('facts.weekly_added').replace('{n}', recentIn));
    } else if (recentOut > 0) {
        facts.push(() => t('facts.weekly_consumed').replace('{n}', recentOut));
    }

    // --- Tips & curiosità (statici ma ruotano) ---
    facts.push(() => t('facts.tip_freezer'));
    facts.push(() => t('facts.tip_bread'));
    facts.push(() => t('facts.tip_fifo'));
    facts.push(() => t('facts.tip_meat'));
    facts.push(() => t('facts.tip_no_refreeze'));
    facts.push(() => t('facts.tip_fridge'));
    facts.push(() => t('facts.tip_canned'));

    // --- Brand-based facts ---
    const brands = inv.filter(i => i.brand).map(i => i.brand);
    if (brands.length > 0) {
        const brandCount = {};
        brands.forEach(b => { brandCount[b] = (brandCount[b] || 0) + 1; });
        const topBrand = Object.entries(brandCount).sort((a, b) => b[1] - a[1])[0];
        facts.push(() => t('facts.top_brand').replace('{brand}', topBrand[0]).replace('{n}', topBrand[1]));
    }

    // --- Specific food combo facts ---
    if (byCategory['pasta'] && byCategory['condimenti']) {
        facts.push(() => t('facts.combo_pasta'));
    }
    if (byCategory['pane'] && byCategory['carne']) {
        facts.push(() => t('facts.combo_sandwich'));
    }
    if (byCategory['verdura'] && byCategory['carne']) {
        facts.push(() => t('facts.combo_balanced'));
    }

    // --- Empty states ---
    if (inv.length === 0) {
        facts.push(() => t('facts.pantry_empty'));
        facts.push(() => t('facts.pantry_empty_scan'));
    }

    // --- Location distribution ---
    const locCount = Object.keys(byLocation).length;
    if (locCount > 1) {
        facts.push(() => {
            const parts = Object.entries(byLocation).map(([loc, items]) => 
                `${LOCATIONS[loc]?.icon || '📦'} ${items.length}`
            );
            return t('facts.location_distribution').replace('{parts}', parts.join('  ·  '));
        });
    }

    // --- Anti-waste knowledge facts ---
    const awFacts = _awGetFacts();
    for (const f of awFacts) { facts.push(() => f); }

    // Pick a random fact
    if (facts.length === 0) {
        return t('facts.pantry_waiting').replace('{greeting}', greeting);
    }
    return facts[Math.floor(Math.random() * facts.length)]();
}

// ===== SPESA MODE (long-press camera for continuous scanning) =====
let _spesaMode = false;
let _shoppingBoughtFlow = false;
let _longPressTimer = null;
let _spesaSession = []; // { name, qty, unit } per ogni prodotto aggiunto

function initSpesaMode() {
    const btn = document.getElementById('btn-header-scan');
    if (!btn) return;

    btn.addEventListener('pointerdown', (e) => {
        e.preventDefault(); // prevent browser-generated synthetic click + 300ms delay
        btn.setPointerCapture(e.pointerId); // ensure pointerup always fires on this element even if finger drifts
        _longPressTimer = setTimeout(() => {
            _longPressTimer = null;
            startSpesaMode();
        }, 600);
    });
    btn.addEventListener('pointerup', () => {
        if (_longPressTimer) {
            clearTimeout(_longPressTimer);
            _longPressTimer = null;
            // Short press — normal scan
            showPage('scan');
        }
    });
    btn.addEventListener('pointercancel', () => {
        // OS cancelled gesture (e.g. home swipe) — discard timer, do nothing
        if (_longPressTimer) {
            clearTimeout(_longPressTimer);
            _longPressTimer = null;
        }
    });
    // Note: no pointerleave handler needed — setPointerCapture prevents it from firing during touch
}

function startSpesaMode() {
    _spesaMode = true;
    _spesaSession = [];
    showToast(t('scan.mode_shopping_activated'), 'success');
    showPage('scan');
    updateSpesaBanner();
}

function endSpesaMode() {
    _spesaMode = false;
    updateSpesaBanner();
    stopScanner();
    showPage('dashboard');
}

function updateSpesaBanner() {
    const banner = document.getElementById('spesa-mode-banner');
    if (!banner) return;
    banner.style.display = _spesaMode ? 'flex' : 'none';
    const statEl = banner.querySelector('.spesa-stat');
    if (statEl) statEl.textContent = _spesaBannerStat();
    _applySpesaScanUI();
}

/** Spesa mode: keep tabs + normal camera size — only hide manual barcode input. */
function _applySpesaScanUI() {
    const page = document.getElementById('page-scan');
    if (page) page.classList.toggle('spesa-scan-layout', _spesaMode);

    const spesaBtn = document.getElementById('scan-spesa-btn');
    if (spesaBtn) spesaBtn.style.display = _spesaMode ? 'none' : '';

    const barcodeContent = document.getElementById('scan-tabcontent-barcode');
    let hint = document.getElementById('spesa-scan-barcode-hint');

    if (_spesaMode) {
        if (barcodeContent && !hint) {
            hint = document.createElement('p');
            hint.id = 'spesa-scan-barcode-hint';
            hint.className = 'spesa-scan-barcode-hint';
            hint.setAttribute('data-i18n', 'scan.spesa_camera_hint');
            hint.textContent = t('scan.spesa_camera_hint');
            barcodeContent.appendChild(hint);
        }
        if (hint) hint.style.display = '';
        const active = document.activeElement;
        if (active && active.id === 'manual-barcode-input') active.blur();
        if (_currentPageId === 'scan') switchScanTab('barcode');
        return;
    }

    if (hint) hint.style.display = 'none';
    if (_currentPageId === 'scan') switchScanTab('barcode');
    _updateScanAiButton();
}

/** Drop purchased items from in-memory Bring list (by Italian or catalog name). */
function _applyShoppingListRemovals(removedNames) {
    if (!removedNames?.length) return;
    const keys = new Set();
    for (const n of removedNames) {
        const low = String(n || '').trim().toLowerCase();
        if (low) keys.add(low);
        const tok = _nameTokens(low)[0];
        if (tok) keys.add(tok);
    }
    if (!keys.size) return;
    shoppingItems = (shoppingItems || []).filter(item => {
        const nameLow = (item.name || '').toLowerCase();
        const rawLow = (item.rawName || '').toLowerCase();
        const first = _nameTokens(nameLow)[0] || '';
        return !keys.has(nameLow) && !keys.has(rawLow) && !(first && keys.has(first));
    });
    loadShoppingCount();
}

async function shoppingBoughtAfterAdd(addResult) {
    if (!_shoppingBoughtFlow) return false;
    _shoppingBoughtFlow = false;
    const targetName = _spesaScanTarget?.name || currentProduct?.name || '';
    _spesaScanTarget = null;
    const banner = document.getElementById('shopping-scan-target-banner');
    if (banner) banner.style.display = 'none';

    if (currentProduct) {
        _shoppingInventoryCache = null;
        await _spesaRemovePurchasedFromList(currentProduct, addResult);
        const addLoc = document.getElementById('add-location')?.value || 'dispensa';
        _showFamilySiblingSuggest(currentProduct.id, addLoc);
    }
    if (addResult?.removed_from_bring) {
        setTimeout(() => showToast(t('toast.removed_from_shopping'), 'info'), 800);
    }
    showPage('shopping');
    loadShoppingList();
    return true;
}

// Called after successful add — returns true if spesa mode handled navigation
async function spesaModeAfterAdd(addResult) {
    if (!_spesaMode) return false;
    if (currentProduct) {
        _spesaSession.push({
            name: currentProduct.name,
            category: currentProduct.category || '',
            product_id: currentProduct.id,
        });
        updateSpesaBanner();
        _shoppingInventoryCache = null;
        await _spesaRemovePurchasedFromList(currentProduct, addResult);
        const addLoc = document.getElementById('add-location')?.value || 'dispensa';
        _showFamilySiblingSuggest(currentProduct.id, addLoc);
    }
    showPage('scan');
    return true;
}

/** Remove matching shopping-list / Bring entry after a spesa-mode purchase. */
async function _spesaRemovePurchasedFromList(product, addResult) {
    const namesToMark = [product.name];
    if (product.shopping_name) namesToMark.push(product.shopping_name);

    if (addResult?.removed_names?.length) {
        _applyShoppingListRemovals(addResult.removed_names);
        namesToMark.push(...addResult.removed_names);
    }

    const generic = product.shopping_name || product.name;
    if (!addResult?.removed_from_bring) {
        try {
            const match = _findSimilarItem(generic, shoppingItems) || _findSimilarItem(product.name, shoppingItems);
            const r = await api('shopping_remove', {}, 'POST', {
                name: match?.name || generic,
                rawName: match?.rawName || '',
                listUUID: shoppingListUUID || undefined,
            });
            if (r?.success) {
                _applyShoppingListRemovals([match?.name || generic, match?.rawName].filter(Boolean));
                if (match?.name) namesToMark.push(match.name);
            }
        } catch (_) { /* best effort */ }
    }

    _markBringPurchased(namesToMark);
    loadShoppingList._bgCall = true;
    loadShoppingList();
}

const _FAMILY_SIBLING_CONFIRM_TTL = 24 * 60 * 60 * 1000;

function _familySiblingConfirmKey(family, location) {
    return `${String(family || '').trim().toLowerCase()}|${location || 'dispensa'}`;
}

function _getFamilySiblingConfirmed() {
    const map = Object.assign({}, _familySiblingConfirmedCache || {});
    const now = Date.now();
    let changed = false;
    for (const key of Object.keys(map)) {
        if (now - map[key] > _FAMILY_SIBLING_CONFIRM_TTL) { delete map[key]; changed = true; }
    }
    if (changed) {
        _familySiblingConfirmedCache = map;
        _saveToServer('family_sibling_confirmed', map);
    }
    return map;
}

function _isFamilySiblingRecentlyConfirmed(family, location) {
    if (!family) return false;
    const map = _getFamilySiblingConfirmed();
    const ts = map[_familySiblingConfirmKey(family, location)];
    return !!ts && (Date.now() - ts) < _FAMILY_SIBLING_CONFIRM_TTL;
}

function _recordFamilySiblingConfirmed(family, location) {
    if (!family) return;
    const map = _getFamilySiblingConfirmed();
    map[_familySiblingConfirmKey(family, location)] = Date.now();
    _familySiblingConfirmedCache = map;
    _saveToServer('family_sibling_confirmed', map);
}

let _familySiblingDismissTimer = null;

function _dismissFamilySiblingPrompt() {
    clearTimeout(_familySiblingDismissTimer);
    _familySiblingDismissTimer = null;
    const el = document.getElementById('_family-sibling-prompt');
    if (el) el.remove();
}

function _formatFamilySiblingDate(dtStr) {
    if (!dtStr) return '';
    const d = new Date(String(dtStr).replace(' ', 'T'));
    if (isNaN(d.getTime())) return '';
    const loc = _currentLang === 'de' ? 'de-DE' : _currentLang === 'en' ? 'en-GB' : 'it-IT';
    return d.toLocaleDateString(loc, { day: '2-digit', month: 'long', year: 'numeric' });
}

/** Parse "20g" / "500 ml" from product name when package size missing in catalog. */
function _inferPackageSizeFromName(name) {
    const m = (name || '').match(/\b(\d+(?:[.,]\d+)?)\s*(g|ml|kg|l|lt)\b/i);
    if (!m) return null;
    let val = parseFloat(String(m[1]).replace(',', '.'));
    const u = m[2].toLowerCase();
    if (u === 'kg') { val *= 1000; return { qty: val, unit: 'g' }; }
    if (u === 'l' || u === 'lt') { val *= 1000; return { qty: val, unit: 'ml' }; }
    return { qty: val, unit: u };
}

/** Human-readable stock for spesa family-sibling check (e.g. "4 conf (da 20g)"). */
function _formatFamilySiblingStockLine(s) {
    let defQty = parseFloat(s.default_quantity) || 0;
    let pkgUnit = s.package_unit || '';
    const inferred = _inferPackageSizeFromName(s.name);
    if (inferred && (!defQty || (s.unit === 'conf' && defQty < inferred.qty))) {
        defQty = inferred.qty;
        pkgUnit = inferred.unit;
    }
    const unit = s.unit || 'pz';
    const qty = parseFloat(s.stock_qty) || 0;
    const parts = formatQuantityParts(qty, unit, defQty, pkgUnit);
    if (parts.unitLabel) {
        let line = `${parts.mainQty} ${parts.unitLabel}`;
        if (parts.packageDetail) line += ` (${parts.packageDetail})`;
        if (parts.fraction) line += ` ${parts.fraction}`;
        return line;
    }
    return formatQuantity(qty, unit, defQty, pkgUnit).replace(/<[^>]*>/g, '');
}

/** Optional hint: same-family product in the same location (non-blocking). */
function _showFamilySiblingSuggest(productId, location) {
    _dismissFamilySiblingPrompt();
    const loc = location || 'dispensa';
    const earlyFamily = (currentProduct?.shopping_name || '').trim();
    if (earlyFamily && _isFamilySiblingRecentlyConfirmed(earlyFamily, loc)) return;
    api('family_sibling_suggest', {}, 'POST', { product_id: productId, location: loc }).then(async data => {
        if (!data?.success || !data.sibling) return;
        const s = data.sibling;
        if (_isFamilySiblingRecentlyConfirmed(s.family, loc)) return;
        if (!(await _productHasLiveStock(s.product_id))) return;
        const locKey = s.location || loc;
        const locInfo = LOCATIONS[locKey] || LOCATIONS.altro;
        const stockLine = _formatFamilySiblingStockLine(s);
        const purchaseRaw = s.last_purchase_at || s.added_at;
        const purchaseDate = _formatFamilySiblingDate(purchaseRaw);
        const productLine = s.brand ? `${s.name} (${s.brand})` : s.name;
        const catIcon = CATEGORY_ICONS[mapToLocalCategory(s.category, s.name)] || '📦';
        const metaParts = [
            `${locInfo.icon} ${locInfo.label}`,
            purchaseDate ? purchaseDate : '',
        ].filter(Boolean);
        const thumbHtml = s.image_url
            ? `<img src="${escapeHtml(s.image_url)}" alt="" class="family-sibling-prompt-img" onerror="this.outerHTML='<span class=\\'family-sibling-prompt-icon\\'>${catIcon}</span>'">`
            : `<span class="family-sibling-prompt-icon">${catIcon}</span>`;

        const bar = document.createElement('div');
        bar.id = '_family-sibling-prompt';
        bar.className = 'family-sibling-prompt';
        bar.innerHTML = `
            <div class="family-sibling-prompt-body">
                <div class="family-sibling-prompt-thumb">${thumbHtml}</div>
                <div class="family-sibling-prompt-info">
                    <div class="family-sibling-prompt-title">${escapeHtml(t('shopping.family_sibling_check', { name: productLine }))}</div>
                    <div class="family-sibling-prompt-stock">${escapeHtml(t('shopping.family_sibling_stock', { qty: stockLine }))}</div>
                    <div class="family-sibling-prompt-meta">${escapeHtml(metaParts.join(' · '))}</div>
                    <div class="family-sibling-prompt-question">${escapeHtml(t('shopping.family_sibling_question'))}</div>
                </div>
            </div>
            <div class="family-sibling-prompt-actions">
                <button type="button" class="family-sibling-prompt-yes" id="_fam-sib-yes">${escapeHtml(t('shopping.family_sibling_yes'))}</button>
                <button type="button" class="family-sibling-prompt-no" id="_fam-sib-no">${escapeHtml(t('shopping.family_sibling_no'))}</button>
            </div>
        `;
        document.body.appendChild(bar);

        bar.querySelector('#_fam-sib-yes').addEventListener('click', () => {
            _recordFamilySiblingConfirmed(s.family, locKey);
            _dismissFamilySiblingPrompt();
        });
        bar.querySelector('#_fam-sib-no').addEventListener('click', () => {
            _dismissFamilySiblingPrompt();
            if (s.inventory_id) editInventoryItem(s.inventory_id);
            else if (s.product_id) api('product_get', { id: s.product_id }).then(p => {
                if (p?.product) { currentProduct = p.product; showPage('add'); }
            }).catch(() => {});
        });
        _familySiblingDismissTimer = setTimeout(_dismissFamilySiblingPrompt, 90000);
    }).catch(() => {});
}

function _spesaBannerStat() {
    const n = _spesaSession.length;
    if (n === 0) return t('shopping.session_empty');
    const cats = {};
    _spesaSession.forEach(p => { const c = p.category || 'altro'; cats[c] = (cats[c]||0)+1; });
    const topCat = Object.entries(cats).sort((a,b)=>b[1]-a[1])[0];
    const names = _spesaSession.map(p => p.name);
    const unique = [...new Set(names)];
    const dupes = names.length - unique.length;
    const phrases = [
        n === 1 ? t('kiosk_session.first_item').replace('{name}', _spesaSession[0].name) : null,
        n >= 2 && n < 5 ? t('kiosk_session.items_two_four').replace('{n}', n) : null,
        n >= 5 && n < 10 ? t('kiosk_session.items_five_nine').replace('{n}', n) : null,
        n >= 10 && n < 20 ? t('kiosk_session.items_ten_twenty').replace('{n}', n) : null,
        n >= 20 ? t('kiosk_session.items_twenty_plus').replace('{n}', n) : null,
        dupes > 0 ? (dupes === 1 ? t('kiosk_session.duplicates_one') : t('kiosk_session.duplicates_many').replace('{n}', dupes)) : null,
        topCat && topCat[1] > 1 ? t('kiosk_session.top_category').replace('{cat}', topCat[0]).replace('{count}', topCat[1]) : null,
    ].filter(Boolean);
    return phrases[n % phrases.length] || t('kiosk_session.items_fallback').replace('{n}', n).replace('{plural}', n===1?'o':'i');
}

function _initScreensaverShortcutBtn(btnId, targetPage, longPressFn) {
    const btn = document.getElementById(btnId);
    if (!btn) return;
    let ssLongPress = null;
    btn.addEventListener('pointerdown', (e) => {
        e.stopPropagation();
        if (longPressFn) {
            ssLongPress = setTimeout(() => {
                ssLongPress = null;
                dismissScreensaver(targetPage);
                setTimeout(longPressFn, 500);
            }, 600);
        }
    });
    btn.addEventListener('pointerup', (e) => {
        e.stopPropagation();
        if (longPressFn && ssLongPress) {
            clearTimeout(ssLongPress);
            ssLongPress = null;
        }
        dismissScreensaver(targetPage);
    });
    btn.addEventListener('pointerleave', (e) => {
        e.stopPropagation();
        if (ssLongPress) {
            clearTimeout(ssLongPress);
            ssLongPress = null;
        }
    });
    ['click', 'touchstart', 'touchend'].forEach(evt => {
        btn.addEventListener(evt, (e) => e.stopPropagation(), { passive: false });
    });
}

function initScreensaverShortcuts() {
    _initScreensaverShortcutBtn('screensaver-scan-btn', 'scan', () => startSpesaMode());
    _initScreensaverShortcutBtn('screensaver-recipe-btn', 'recipe', null);
}

let _inactivityListenersAttached = false;

function initInactivityWatcher() {
    if (!_inactivityListenersAttached) {
        const events = ['pointerdown', 'pointermove', 'keydown', 'scroll', 'touchstart'];
        events.forEach(evt => {
            document.addEventListener(evt, () => {
                if (_screensaverActive) {
                    dismissScreensaver();
                    return;
                }
                // Auto-home: always reset regardless of screensaver setting
                _resetAutoHomeTimer();
                // Screensaver: only if enabled
                if (getSettings().screensaver_enabled) {
                    resetInactivityTimer();
                }
            }, { passive: true });
        });
        _inactivityListenersAttached = true;
    }
    // Always start auto-home timer; screensaver only if enabled
    _resetAutoHomeTimer();
    if (getSettings().screensaver_enabled) {
        resetInactivityTimer();
    }
}

// ===== INITIALIZATION =====
const _splashStart = Date.now();
document.addEventListener('DOMContentLoaded', () => {
    // Load translations first, then initialize the app
    loadTranslations(_currentLang).then(() => {
        _initApp();
    }).catch(() => {
        _initApp(); // fallback: initialize even if translations fail
    });
});

// ===== SETUP WIZARD =====
let _setupStep = 0;
let _setupPendingSteps = [];
const _setupData = { lang: _currentLang, gemini_key: '', bring_email: '', bring_password: '', gdrive_folder_id: '', gdrive_client_id: '', gdrive_client_secret: '' };

/**
 * Returns indices of setup steps that still need configuration.
 * Accepts optional serverSettings fetched from the API so server-side
 * credentials (stored in .env) are also considered.
 */
function _getMissingSetupSteps(serverSettings) {
    const missing = [];
    const s = getSettings();
    const srv = serverSettings || {};
    const setupDone = localStorage.getItem('evershelf_setup_done');

    // Step 0 — language: missing only if never set at all (fresh install)
    if (!localStorage.getItem('evershelf_lang') && !setupDone) {
        missing.push(0);
    }
    // Steps 1 & 2 only show on first run (before setup is completed/skipped)
    if (!setupDone) {
        // Step 1 — Gemini API key (check both localStorage and server .env)
        if (!s.gemini_key && !srv.gemini_key_set) missing.push(1);
        // Step 2 — Bring! credentials (check both localStorage and server .env)
        if ((!s.bring_email && !srv.bring_email) || (!s.bring_password && !srv.bring_password_set)) missing.push(2);
        // Step 3 — Google Drive backup (always optional on first run, skippable)
        if (!srv.gdrive_refresh_token_set && !srv.gdrive_folder_id) missing.push(3);
    }
    // Note: step 4 (done screen) gets appended automatically when there are missing steps

    return missing;
}

function _setupSteps() {
    return [
        {
            title: '🌐 ' + t('settings.language.label'),
            desc: t('settings.language.hint'),
            render: () => {
                let html = '<div class="setup-lang-grid">';
                for (const [code, name] of Object.entries(_SUPPORTED_LANGS)) {
                    const sel = code === _setupData.lang ? ' selected' : '';
                    html += `<button class="setup-lang-btn${sel}" onclick="_setupSelectLang('${code}')">${name}</button>`;
                }
                html += '</div>';
                return html;
            }
        },
        {
            title: '🤖 Google Gemini AI',
            desc: t('settings.gemini.hint'),
            render: () => `
                <div class="form-group">
                    <label>${t('settings.gemini.key_label')}</label>
                    <input type="text" id="setup-gemini-key" class="form-input" placeholder="AIza..." value="${_setupData.gemini_key}">
                    <p style="color:#999;font-size:0.8rem;margin-top:8px">
                        <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">→ Get a free API key from Google AI Studio</a>
                    </p>
                </div>
                <span class="setup-skip-link" onclick="_setupSkipStep()">${t('btn.cancel')} — ${_currentLang === 'it' ? 'configura dopo' : 'configure later'}</span>
            `
        },
        {
            title: '🛒 Bring! Shopping List',
            desc: t('settings.bring.hint'),
            render: () => `
                <div class="form-group">
                    <label>${t('settings.bring.email_label')}</label>
                    <input type="email" id="setup-bring-email" class="form-input" placeholder="email@example.com" value="${_setupData.bring_email}">
                </div>
                <div class="form-group">
                    <label>${t('settings.bring.password_label')}</label>
                    <input type="password" id="setup-bring-password" class="form-input" placeholder="Password" value="${_setupData.bring_password}">
                </div>
                <span class="setup-skip-link" onclick="_setupSkipStep()">${t('btn.cancel')} — ${_currentLang === 'it' ? 'configura dopo' : 'configure later'}</span>
            `
        },
        {
            title: '☁️ Google Drive Backup',
            desc: t('settings.backup.gdrive_wizard_hint') || 'Optional: automatically back up to Google Drive daily.',
            render: () => `
                <details style="margin-bottom:14px;background:var(--bg-secondary,#f8fafc);border-radius:8px;padding:10px 14px">
                    <summary style="cursor:pointer;font-weight:600;font-size:0.85rem;color:var(--text-primary)">${t('settings.backup.gdrive_oauth_how_to') || '📋 Setup guide'}</summary>
                    <ol style="margin:10px 0 0 16px;font-size:0.8rem;color:var(--text-secondary);line-height:1.8">${t('settings.backup.gdrive_oauth_steps') || ''}</ol>
                </details>
                <div class="form-group">
                    <label>${t('settings.backup.gdrive_folder_id') || 'Folder ID Drive'}</label>
                    <input type="text" id="setup-gdrive-folder" class="form-input" placeholder="1ABCdef_xyz…" value="${_setupData.gdrive_folder_id}">
                </div>
                <div class="form-group">
                    <label>${t('settings.backup.gdrive_client_id') || 'Client ID'}</label>
                    <input type="text" id="setup-gdrive-client-id" class="form-input" placeholder="1234567890-abc….apps.googleusercontent.com" value="${_setupData.gdrive_client_id}">
                </div>
                <div class="form-group">
                    <label>${t('settings.backup.gdrive_client_secret') || 'Client Secret'}</label>
                    <input type="password" id="setup-gdrive-client-secret" class="form-input" placeholder="GOCSPX-…" value="${_setupData.gdrive_client_secret}">
                </div>
                <p class="settings-hint" style="font-size:0.78rem">${t('settings.backup.gdrive_redirect_uri_label') || 'Redirect URI:'} <code>http://localhost</code></p>
                <span class="setup-skip-link" onclick="_setupSkipStep()">${t('settings.backup.gdrive_skip') || 'Skip — configure later in Settings'}</span>
            `
        },
        {
            title: '✅ ' + (_currentLang === 'it' ? 'Tutto pronto!' : _currentLang === 'de' ? 'Alles bereit!' : _currentLang === 'fr' ? 'Tout est prêt !' : _currentLang === 'es' ? '¡Todo listo!' : 'All set!'),
            desc: _currentLang === 'it' ? 'La configurazione è completata. Puoi sempre modificare queste impostazioni dalla pagina Configurazione.'
                 : _currentLang === 'de' ? 'Die Konfiguration ist abgeschlossen. Du kannst diese Einstellungen jederzeit ändern.'
                 : _currentLang === 'fr' ? 'La configuration est terminée. Vous pouvez toujours modifier ces paramètres depuis la page Paramètres.'
                 : _currentLang === 'es' ? 'La configuración está completa. Puedes cambiar estos ajustes desde la página Ajustes.'
                 : 'Setup is complete. You can always change these settings from the Settings page.',
            render: () => {
                let summary = '<div style="text-align:center;font-size:2.5rem;margin:12px 0">🎉</div>';
                return summary;
            }
        }
    ];
}

function showSetupWizard(pendingSteps) {
    _setupPendingSteps = pendingSteps || _getMissingSetupSteps();
    if (_setupPendingSteps.length === 0) return;
    // Append the "done" step (4) at the end
    _setupPendingSteps.push(4);
    _setupStep = 0;
    // Pre-fill _setupData from existing settings so we don't lose them
    const s = getSettings();
    if (s.gemini_key) _setupData.gemini_key = s.gemini_key;
    if (s.bring_email) _setupData.bring_email = s.bring_email;
    if (s.bring_password) _setupData.bring_password = s.bring_password;
    document.getElementById('setup-wizard').style.display = '';
    _renderSetupStep();
}

function _renderSetupStep() {
    const allSteps = _setupSteps();
    const totalPending = _setupPendingSteps.length;
    const realIndex = _setupPendingSteps[_setupStep];
    const step = allSteps[realIndex];

    // Progress dots (based on pending steps only)
    const dotsHtml = _setupPendingSteps.map((_, i) => {
        let cls = 'setup-dot';
        if (i < _setupStep) cls += ' done';
        if (i === _setupStep) cls += ' active';
        return `<div class="${cls}"></div>`;
    }).join('');
    document.getElementById('setup-progress').innerHTML = dotsHtml;

    // Body
    document.getElementById('setup-body').innerHTML = `<h3>${step.title}</h3><p>${step.desc}</p>${step.render()}`;

    // Buttons
    const prevBtn = document.getElementById('setup-prev');
    const nextBtn = document.getElementById('setup-next');
    prevBtn.style.display = _setupStep > 0 ? '' : 'none';
    prevBtn.textContent = t('btn.back');

    if (_setupStep === totalPending - 1) {
        nextBtn.textContent = _currentLang === 'it' ? '🚀 Inizia!' : _currentLang === 'de' ? '🚀 Los geht\'s!' : _currentLang === 'fr' ? '🚀 Allons-y !' : _currentLang === 'es' ? '🚀 ¡Empezar!' : '🚀 Start!';
    } else {
        nextBtn.textContent = _currentLang === 'it' ? 'Avanti →' : _currentLang === 'de' ? 'Weiter →' : _currentLang === 'fr' ? 'Suivant →' : _currentLang === 'es' ? 'Siguiente →' : 'Next →';
    }
}

function _setupSelectLang(lang) {
    _setupData.lang = lang;
    document.querySelectorAll('.setup-lang-btn').forEach(b => b.classList.remove('selected'));
    event.target.classList.add('selected');
}

function _setupSkipStep() {
    _setupStep++;
    _renderSetupStep();
}

function _setupCollectCurrent() {
    const realIndex = _setupPendingSteps[_setupStep];
    if (realIndex === 1) {
        const el = document.getElementById('setup-gemini-key');
        if (el) _setupData.gemini_key = el.value.trim();
    } else if (realIndex === 2) {
        const email = document.getElementById('setup-bring-email');
        const pass = document.getElementById('setup-bring-password');
        if (email) _setupData.bring_email = email.value.trim();
        if (pass) _setupData.bring_password = pass.value.trim();
    } else if (realIndex === 3) {
        const folderEl = document.getElementById('setup-gdrive-folder');
        const clientIdEl = document.getElementById('setup-gdrive-client-id');
        const clientSecretEl = document.getElementById('setup-gdrive-client-secret');
        if (folderEl) _setupData.gdrive_folder_id = folderEl.value.trim();
        if (clientIdEl) _setupData.gdrive_client_id = clientIdEl.value.trim();
        if (clientSecretEl) _setupData.gdrive_client_secret = clientSecretEl.value.trim();
    }
}

function setupWizardNav(dir) {
    _setupCollectCurrent();
    const totalPending = _setupPendingSteps.length;
    const realIndex = _setupPendingSteps[_setupStep];

    if (dir === 1 && _setupStep === totalPending - 1) {
        _finishSetup();
        return;
    }

    // If language changed, apply it
    if (realIndex === 0 && dir === 1 && _setupData.lang !== _currentLang) {
        localStorage.setItem('evershelf_lang', _setupData.lang);
        localStorage.setItem('evershelf_setup_step', String(_setupStep + 1));
        localStorage.setItem('evershelf_setup_pending', JSON.stringify(_setupPendingSteps));
        localStorage.setItem('evershelf_setup_data', JSON.stringify(_setupData));
        location.reload();
        return;
    }

    _setupStep = Math.max(0, Math.min(totalPending - 1, _setupStep + dir));
    _renderSetupStep();
}

async function _finishSetup() {
    // Save settings
    const s = getSettings();
    if (_setupData.gemini_key) s.gemini_key = _setupData.gemini_key;
    if (_setupData.bring_email) s.bring_email = _setupData.bring_email;
    if (_setupData.bring_password) s.bring_password = _setupData.bring_password;
    saveSettingsToStorage(s);

    // Save server-side settings (.env) — only send non-empty values to avoid overwriting existing config
    const envPayload = {};
    if (_setupData.gemini_key) envPayload.gemini_key = _setupData.gemini_key;
    if (_setupData.bring_email) envPayload.bring_email = _setupData.bring_email;
    if (_setupData.bring_password) envPayload.bring_password = _setupData.bring_password;
    if (_setupData.gdrive_folder_id) envPayload.gdrive_folder_id = _setupData.gdrive_folder_id;
    if (_setupData.gdrive_client_id) { envPayload.gdrive_client_id = _setupData.gdrive_client_id; envPayload.gdrive_enabled = true; }
    if (_setupData.gdrive_client_secret) envPayload.gdrive_client_secret = _setupData.gdrive_client_secret;
    try {
        if (Object.keys(envPayload).length > 0) {
            await api('save_settings', {}, 'POST', envPayload);
        }
    } catch(e) { /* will work locally */ }

    localStorage.setItem('evershelf_setup_done', '1');
    localStorage.removeItem('evershelf_setup_step');
    localStorage.removeItem('evershelf_setup_data');
    document.getElementById('setup-wizard').style.display = 'none';
}

// ===== SERVER HEARTBEAT =====
// Polls the lightweight ?action=ping endpoint every 20 s (online) / 5 s (offline).
// When the server is unreachable:  shows the #offline-banner, blocks the UI via
// body.server-offline, and retries faster until the server responds again.

let _serverOffline = false;
let _heartbeatTimer = null;
const _HB_INTERVAL_ONLINE  = 20_000; // ms — normal polling interval
const _HB_INTERVAL_OFFLINE =  5_000; // ms — faster retry when unreachable

async function _runHeartbeat() {
    const ac = new AbortController();
    const tid = setTimeout(() => ac.abort(), 7000); // 7 s hard timeout
    try {
        const res = await fetch(`${API_BASE}?action=ping`, {
            cache: 'no-store',
            signal: ac.signal,
        });
        clearTimeout(tid);
        _setServerOffline(!res.ok);
    } catch (e) {
        clearTimeout(tid);
        _setServerOffline(true);
    }
}

function _setServerOffline(offline) {
    if (offline === _serverOffline) {
        // State unchanged — reschedule at the appropriate interval and return
        _heartbeatTimer = setTimeout(_runHeartbeat,
            offline ? _HB_INTERVAL_OFFLINE : _HB_INTERVAL_ONLINE);
        return;
    }
    _serverOffline = offline;
    document.body.classList.toggle('server-offline', offline);
    if (offline) {
        if (!_offlineMode) {
            // Show the full-screen network overlay (also auto-enters offline mode after 8 s)
            _showNetworkOverlay();
        }
        // In offline mode the banner is already managed by _renderOfflineBanner()
    } else {
        // Server came back: exit offline mode (sync queue, refresh) then hide overlay
        _handleServerRestored();
    }
    _heartbeatTimer = setTimeout(_runHeartbeat,
        offline ? _HB_INTERVAL_OFFLINE : _HB_INTERVAL_ONLINE);
}

/** Flush log messages and error reports that were buffered while offline. */
async function _flushOfflineReports() {
    try {
        const logs = JSON.parse(localStorage.getItem(_OFFLINE_LOGS_KEY) || '[]');
        if (logs.length > 0) {
            localStorage.removeItem(_OFFLINE_LOGS_KEY);
            await fetch('api/index.php?action=client_log', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ messages: logs })
            });
        }
    } catch(e) {}
    try {
        const errors = JSON.parse(localStorage.getItem(_OFFLINE_ERRORS_KEY) || '[]');
        if (errors.length > 0) {
            localStorage.removeItem(_OFFLINE_ERRORS_KEY);
            for (const errBody of errors) {
                await fetch('api/index.php?action=report_error', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(errBody)
                }).catch(() => {});
            }
        }
    } catch(e) {}
}

/** Async handler called when the server comes back online. */
async function _handleServerRestored() {
    if (_offlineMode) {
        await _exitOfflineMode();
    }
    // Now hide the overlay with the "restored" message (if still visible)
    if (_networkDown) {
        _hideNetworkOverlay(true);
    }
    // Flush all logs and error reports buffered while offline → GitHub issues when applicable
    _flushOfflineReports().catch(() => {});
    showToast(t('error.server_restored'), 'success');
    refreshCurrentPage();
}

/** Called by the banner "Retry" button to trigger an immediate check. */
function _heartbeatRetry() {
    clearTimeout(_heartbeatTimer);
    _runHeartbeat();
}

// ── Startup / Splash health check ────────────────────────────────────────────
/**
 * Run a comprehensive server-side diagnostic during the splash screen.
 * Shows a real-time progress bar + current check label.
 * Returns true if the app can proceed, false if a critical check failed.
 */
async function _runStartupCheck() {
    let _curPct = 0;
    function setProgress(pct, label, state) {
        _curPct = pct;
        if (barEl) {
            barEl.style.width = pct + '%';
            barEl.className = 'preloader-bar' + (state === 'error' ? ' bar-error' : state === 'warn' ? ' bar-warn' : '');
        }
        if (!label) return;
        const ticker = document.getElementById('check-ticker');
        if (!ticker) return;
        const sc = state === 'error' ? 'state-error' : state === 'warn' ? 'state-warn' : 'state-ok';
        // Strip emoji from label — colors convey the state
        const cleanLabel = label.replace(/[\u{1F000}-\u{1FFFF}\u{2600}-\u{27BF}\u{2700}-\u{27BF}✅❌⚠️🔄]/gu, '').trim().replace(/^[-–—\s]+/, '');
        let el = ticker.querySelector('.preloader-status-text');
        if (!el) {
            el = document.createElement('div');
            el.className = 'preloader-status-text';
            ticker.appendChild(el);
        }
        // Direct update — checks fire every 40ms, any fade would hide most labels
        el.className = `preloader-status-text ${sc}`;
        el.textContent = cleanLabel;
    }

    const spinnerEl  = document.getElementById('preloader-spinner');
    const wrapEl     = document.getElementById('preloader-progress-wrap');
    const barEl      = document.getElementById('preloader-bar');
    const labelEl    = document.getElementById('preloader-check-label');
    const warningsEl = document.getElementById('preloader-warnings');
    const errorEl    = document.getElementById('preloader-error-msg');
    const retryBtn   = document.getElementById('preloader-retry-btn');

    if (!wrapEl) return true; // preloader already removed

    const tl = (key, fallback) => {
        const full = 'startup.' + key;
        const v = typeof t === 'function' ? t(full) : full;
        return (v === full) ? fallback : v;
    };

    // Switch from spinner to progress bar
    if (spinnerEl) spinnerEl.style.display = 'none';
    wrapEl.style.display = '';

    // Auto-provision API token for same-origin browser sessions
    if (typeof ensureApiToken === 'function') {
        setProgress(5, tl('token_autoconfig', 'Configurazione accesso...'), 'ok');
        await ensureApiToken();
    }

    // Phase 1: animate 0→15% while fetching (so it never looks stuck)
    setProgress(0, tl('connecting', 'Connessione al server...'));
    let _fetchDone = false;
    const slowAnim = setInterval(() => {
        if (!_fetchDone && _curPct < 13) {
            _curPct++;
            if (barEl) barEl.style.width = _curPct + '%';
        }
    }, 100);

    // Make the request
    let result = null;
    try {
        const ctrl = new AbortController();
        const tid  = setTimeout(() => ctrl.abort(), 12000);
        const resp = await fetch('api/index.php?action=health_check', {
            signal: ctrl.signal,
            headers: { ...(typeof apiAuthHeaders === 'function' ? apiAuthHeaders() : {}) },
        });
        clearTimeout(tid);
        result = await resp.json();
        if (result.public && result.api_token_required && typeof getApiToken === 'function' && !getApiToken()) {
            window._apiTokenRequired = true;
            if (typeof _promptApiTokenIfNeeded === 'function') _promptApiTokenIfNeeded();
            setProgress(100, tl('token_required', 'Token API richiesto'), 'warn');
            return false;
        }
        if (result.public && result.api_token_required && typeof getApiToken === 'function' && getApiToken()) {
            const resp2 = await fetch('api/index.php?action=health_check', { headers: apiAuthHeaders() });
            result = await resp2.json();
        }
    } catch(e) {
        clearInterval(slowAnim);
        _showStartupErrorPopup(
            tl('error_network', 'Impossibile contattare il server'),
            tl('error_network_detail', 'Il browser non riesce a raggiungere il server PHP.\n\nPossibili cause:\n• Il server Apache/PHP non è in esecuzione\n• Problema di rete o firewall\n• URL dell\'app non corretta\n\nControlla che il server sia avviato e riprova.'),
            errorEl, retryBtn
        );
        setProgress(100, tl('error_network', 'Server non raggiungibile'), 'error');
        return false;
    }
    clearInterval(slowAnim);
    _fetchDone = true;

    // ── Ordered check definitions (must match PHP keys) ───────────────────────
    const CHECKS = [
        // PHP runtime
        { key: 'php_version',       label: 'PHP',                                           critical: true  },
        { key: 'ext_pdo_sqlite',    label: 'PDO SQLite',                                    critical: true  },
        { key: 'ext_curl',          label: 'cURL',                                          critical: true  },
        { key: 'ext_json',          label: 'JSON',                                          critical: true  },
        { key: 'ext_mbstring',      label: 'mbstring',                                      critical: true  },
        { key: 'ext_openssl',       label: 'OpenSSL',                                       critical: false },
        { key: 'ext_fileinfo',      label: 'Fileinfo',                                      critical: false },
        { key: 'ext_zip',           label: 'ZIP',                                           critical: false },
        { key: 'ext_intl',          label: 'Intl',                                          critical: false },
        { key: 'php_memory',        label: tl('check_php_memory',  'Memoria PHP'),          critical: false },
        { key: 'php_max_exec',      label: tl('check_php_timeout', 'Timeout PHP'),          critical: false },
        { key: 'php_upload',        label: tl('check_php_upload',  'Upload PHP'),           critical: false },
        // Filesystem
        { key: 'data_dir',          label: tl('check_data_dir',    'Cartella dati'),        critical: true  },
        { key: 'data_rate_limits',  label: tl('check_rate_limits', 'Rate limits dir'),      critical: false },
        { key: 'data_backups',      label: tl('check_backups',     'Backup dir'),           critical: false },
        { key: 'data_write_test',   label: tl('check_write_test',  'Test scrittura'),       critical: true  },
        { key: 'disk_space',        label: tl('check_disk_space',  'Spazio disco'),         critical: false },
        // Database
        { key: 'db_legacy',         label: tl('check_db_legacy',   'DB legacy'),            critical: false },
        { key: 'db_connect',        label: tl('check_db_connect',  'Connessione DB'),       critical: true  },
        { key: 'db_tables',         label: tl('check_db_tables',   'Tabelle DB'),           critical: true  },
        { key: 'db_integrity',      label: tl('check_db_integrity','Integrità DB'),         critical: true  },
        { key: 'db_wal',            label: tl('check_db_wal',      'WAL mode'),             critical: false },
        { key: 'db_size',           label: tl('check_db_size',     'Dimensione DB'),        critical: false },
        { key: 'db_row_count',      label: tl('check_db_rows',     'Dati inventario'),      critical: false },
        // Config & optional features
        { key: 'env_file',          label: tl('check_env',         'File .env'),            critical: false },
        { key: 'gemini_key',        label: tl('check_gemini',      'Gemini AI key'),        critical: false },
        { key: 'bring_credentials', label: tl('check_bring_creds', 'Bring! credenziali'),   critical: false },
        { key: 'bring_token',       label: tl('check_bring_token', 'Bring! token'),         critical: false },
        { key: 'tts_url',           label: tl('check_tts',         'TTS URL'),              critical: false },
        { key: 'scale_gateway',     label: tl('check_scale',       'Scale gateway'),        critical: false },
        // Network
        { key: 'curl_ssl',          label: tl('check_curl_ssl',    'cURL SSL'),             critical: false },
        { key: 'internet',          label: tl('check_internet',    'Internet'),             critical: false },
    ];

    const checks   = result.checks || {};
    const warnings = [];
    const errors   = [];
    const total    = CHECKS.filter(d => checks[d.key] !== undefined).length;
    let   done     = 0;

    // Phase 2: step through each check with animated label
    for (const def of CHECKS) {
        const c = checks[def.key];
        if (c === undefined) continue; // not returned by server (feature not enabled)

        done++;
        const pct    = 15 + Math.round((done / total) * 83); // 15→98%
        const isOk   = c.ok === true;
        const isOpt  = c.optional === true || !def.critical;
        const isFresh = c.fresh === true;

        // Build label with value
        let lbl = def.label;
        if (c.value)            lbl += ` (${c.value})`;
        if (isFresh)            lbl += ` — ${tl('fresh_install', 'nuovo impianto')}`;
        if (!isOk && c.error)   lbl += ` — ${c.error}`;
        if (!isOk && c.missing?.length) lbl += ` — mancanti: ${c.missing.join(', ')}`;

        setProgress(pct, lbl, isOk ? 'ok' : isOpt ? 'warn' : 'error');

        if (!isOk && !isFresh) {
            (isOpt ? warnings : errors).push({ def, c });
        }

        await new Promise(r => setTimeout(r, 40));
    }

    // ── Errors → red bar + blocking popup ────────────────────────────────────
    if (errors.length > 0) {
        setProgress(100, tl('critical_error_short', 'Errore critico'), 'error');
        await new Promise(r => setTimeout(r, 300));
        const errLines = errors.map(e => {
            const hint = e.c.hint || (e.c.error ? e.c.error : null);
            return `❌ ${e.def.label}${hint ? '\n   → ' + hint : ''}`;
        }).join('\n\n');
        _showStartupErrorPopup(
            tl('critical_error_short', 'Errore critico'),
            tl('critical_error_intro', 'L\'app non può avviarsi a causa dei seguenti problemi:') + '\n\n' + errLines,
            errorEl, retryBtn
        );
        return false;
    }

    // ── Warnings → amber bar + warning popup auto-close 5s ───────────────────
    if (warnings.length > 0) {
        setProgress(100, `${warnings.length} ${tl('warnings_found', 'avvisi')}`, 'warn');
        await new Promise(r => setTimeout(r, 200));

        // Build warning popup (auto-close 5s)
        _showStartupWarningPopup(warnings, warningsEl, tl);

        // Wait for user to read (5s) then proceed
        await new Promise(r => setTimeout(r, 5200));

        // Hide warning popup
        warningsEl.style.display = 'none';
    } else {
        setProgress(100, tl('all_ok', 'Sistema OK'), 'ok');
        await new Promise(r => setTimeout(r, 600));
    }

    // ── Final step: sync local offline cache (inventory + settings) ──────────
    // This ensures the offline copy is always fresh at startup while connected.
    // The bar already shows 100%; we just update the label for a moment.
    try {
        setProgress(100, tl('syncing_local', 'Sincronizzazione dati locali...'), 'ok');
        const authH = typeof apiAuthHeaders === 'function' ? apiAuthHeaders() : {};
        const [invData, settingsData, productsData] = await Promise.all([
            fetch('api/index.php?action=inventory_list', { headers: authH }).then(r => r.json()).catch(() => null),
            fetch('api/index.php?action=get_settings', { headers: authH }).then(r => r.json()).catch(() => null),
            fetch('api/index.php?action=products_list', { headers: authH }).then(r => r.json()).catch(() => null),
        ]);
        if (invData && Array.isArray(invData.inventory)) _offlineCacheSet(invData.inventory);
        if (settingsData && settingsData.success !== false) _offlineCacheSetSettings(settingsData);
        if (productsData && Array.isArray(productsData.products)) _offlineProductsSet(productsData.products);
        setProgress(100, tl('sync_done', 'Dati locali aggiornati'), 'ok');
        await new Promise(r => setTimeout(r, 400));
    } catch(e) {
        // Non-critical — app continues normally; cache may be stale or empty
    }

    wrapEl.style.display = 'none';
    return true;
}

/** Builds and shows the warning popup with countdown (auto-closes after 5s). */
function _showStartupWarningPopup(warnings, container, tl) {
    const lines = warnings.map(w => {
        const hint = w.c.hint || null;
        return `<div class="startup-warn-item">
            <span class="startup-warn-icon">⚠️</span>
            <div class="startup-warn-body">
                <strong>${w.def.label}</strong>
                ${hint ? `<p>${hint}</p>` : ''}
            </div>
        </div>`;
    }).join('');

    container.innerHTML = `
        <div class="startup-popup startup-popup-warn">
            <div class="startup-popup-header">
                <span>⚠️ ${warnings.length} ${tl('warnings_found', 'avviso/i rilevato/i')}</span>
                <span class="startup-popup-countdown" id="startup-countdown">5</span>
            </div>
            <div class="startup-popup-body">${lines}</div>
            <div class="startup-popup-bar-wrap"><div class="startup-popup-bar" id="startup-popup-bar"></div></div>
        </div>`;
    container.style.display = '';

    // Animate countdown bar
    const barEl = document.getElementById('startup-popup-bar');
    const cntEl = document.getElementById('startup-countdown');
    if (barEl) {
        barEl.style.transition = 'none';
        barEl.style.width = '100%';
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                barEl.style.transition = 'width 5s linear';
                barEl.style.width = '0%';
            });
        });
    }
    let secs = 4;
    const t = setInterval(() => {
        if (cntEl) cntEl.textContent = secs;
        secs--;
        if (secs < 0) clearInterval(t);
    }, 1000);
}

/** Shows a blocking error in the preloader (no auto-close). */
function _showStartupErrorPopup(title, detail, errorEl, retryBtn) {
    if (!errorEl) return;
    errorEl.innerHTML = `<strong>${title}</strong>\n${detail}`;
    errorEl.style.display = '';
    if (retryBtn) retryBtn.style.display = '';
}

/** Retry button handler in the startup error screen. */
function _startupRetry() {
    location.reload();
}

/** Start the heartbeat loop (called once from _initApp). */
function startHeartbeat() {
    _runHeartbeat(); // immediate first probe
}

async function _initApp() {
    // ── Startup health check (runs during splash, blocks app if critical) ──────
    const _startupOk = await _runStartupCheck();
    if (!_startupOk) return; // preloader stays visible with error; app does not start

    // Check for setup wizard resume (after language change)
    const resumeStep = localStorage.getItem('evershelf_setup_step');
    const resumeData = localStorage.getItem('evershelf_setup_data');
    const resumePending = localStorage.getItem('evershelf_setup_pending');
    if (resumeStep && resumePending) {
        try { Object.assign(_setupData, JSON.parse(resumeData)); } catch(e) {}
        try { _setupPendingSteps = JSON.parse(resumePending); } catch(e) {}
        _setupStep = parseInt(resumeStep) || 0;
        localStorage.removeItem('evershelf_setup_step');
        localStorage.removeItem('evershelf_setup_data');
        localStorage.removeItem('evershelf_setup_pending');
        document.getElementById('setup-wizard').style.display = '';
        _renderSetupStep();
    } else {
        // Fetch server settings first so .env credentials (Bring!, Gemini)
        // are taken into account before deciding which wizard steps to show.
        let serverSettings = {};
        try { serverSettings = await api('get_settings'); } catch(e) {}
        _geminiAvailable = !!(serverSettings.gemini_key_set);
        _demoMode = !!serverSettings.demo_mode;
        _updateGeminiButtonState();
        _applyDemoModeUI();
        const missing = _getMissingSetupSteps(serverSettings);
        if (missing.length > 0 && !_demoMode) {
            showSetupWizard(missing);
        }
        // Reuse the already-fetched serverSettings to avoid a second get_settings request
        _applySyncedSettings(serverSettings);
    }

    // Migrate old session-based flags to time-based
    if (sessionStorage.getItem('_autoAddedCritical')) {
        sessionStorage.removeItem('_autoAddedCritical');
    }
    // One-time reset of bg sync timestamp so first load always triggers a sync
    if (!localStorage.getItem('_bgBringSyncReset_v1')) {
        localStorage.removeItem('_bgBringSyncTs');
        localStorage.setItem('_bgBringSyncReset_v1', '1');
    }
    // syncSettingsFromDB only needs to fetch app_settings_get for review flags now
    syncSettingsFromDB().then(() => {
        scaleInit(); // connect to smart scale gateway if configured (needs settings)
        initInactivityWatcher();
    });
    showPage('dashboard');
    initSpesaMode();
    initScreensaverShortcuts();
    startBgShoppingRefresh();
    startHeartbeat();
    // ── Recover any pending offline operations left over from a previous session ──
    // This handles the case where the user refreshed the page while offline ops
    // were queued — the queue survives in localStorage but _offlineMode is false.
    (() => {
        const startupQueue = _offlineQueueGet();
        if (startupQueue.length === 0) return;
        setTimeout(async () => {
            const synced = await _syncOfflineQueue();
            await _flushOfflineReports().catch(() => {});
            if (synced > 0) {
                showToast(t('error.offline_synced').replace('{n}', synced), 'success');
                refreshCurrentPage();
            } else {
                // All ops failed to sync — keep them for next attempt
                showToast(t('error.offline_ops_pending').replace('{n}', _offlineQueueGet().length), 'warning');
            }
        }, 1200);
    })();
    _injectKioskOverlay(); // kiosk X / refresh buttons (only when running inside Android WebView)

    // Sync version label in preloader (in case HTML is stale)
    const preloaderVer = document.getElementById('preloader-version');
    if (preloaderVer) {
        const ver = document.querySelector('.header-version')?.textContent?.trim() || '';
        if (ver) preloaderVer.textContent = ver;
    }

    // Hide preloader — enforce minimum 3 s splash regardless of load speed
    const preloader = document.getElementById('app-preloader');
    if (preloader) {
        const elapsed = Date.now() - _splashStart;
        const minDelay = Math.max(0, 3000 - elapsed);
        setTimeout(() => {
            preloader.classList.add('fade-out');
            setTimeout(() => preloader.remove(), 380);
        }, minDelay);
    }

    // Defer update check: fire 6 s after app is ready so it doesn't compete
    // with initial API calls and the PHP worker isn't blocked during startup.
    setTimeout(_checkWebappUpdate, 6000);

    if ('serviceWorker' in navigator && !window.location.pathname.includes('/demo')) {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    }

    // ── Background intervals ───────────────────────────────────────────────
    // 1) Ogni 5 min: ricarica la pagina corrente (scadenze, inventario, ecc.)
    setInterval(() => {
        if (!_screensaverActive) refreshCurrentPage();
    }, 5 * 60 * 1000);

    // 2) Ogni 5 min: contatore spesa (non ricaricare la lista se l'utente sta navigando)
    setInterval(() => {
        if (_currentPageId === 'shopping') {
            const idleMs = Date.now() - (loadShoppingList._lastUserInteraction || 0);
            if (idleMs >= 2 * 60 * 1000) {
                loadShoppingList._bgCall = true;
                loadShoppingList();
            }
        } else {
            loadShoppingCount();
            const _s = getSettings();
            if (_s.price_enabled && !_pricesFetching) {
                syncShoppingPriceTotal(false);
            }
        }
    }, 5 * 60 * 1000);

    // 3) Tab visibile: refresh leggero solo se l'utente non sta usando la lista
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            if (_currentPageId === 'shopping') {
                const idleMs = Date.now() - (loadShoppingList._lastUserInteraction || 0);
                if (idleMs >= 90 * 1000) {
                    loadShoppingList._bgCall = true;
                    loadShoppingList();
                }
            } else {
                refreshCurrentPage();
            }
            _checkWebappUpdate();
        }
    });

    // 4) Background Bring sync ogni 5 min — completamente autonomo, non dipende
    //    dalla pagina corrente. Aggiunge urgenti, aggiorna spec, rimuove risolti.
    _backgroundBringSync();
    setInterval(() => { if (!_screensaverActive) _backgroundBringSync(); }, 5 * 60 * 1000);

    // 5) Anti-waste live refresh — starts/stops based on connectivity.
    window.addEventListener('online',  () => { _updateAwLiveDot(true);  _startAntiWasteAutoRefresh(); });
    window.addEventListener('offline', () => { _updateAwLiveDot(false); clearInterval(_awRefreshTimer); });
    // ─────────────────────────────────────────────────────────────────────
}

/**
 * Background sync — runs every 5 min regardless of current page.
 * Fully autonomous: fetches fresh data, syncs Bring urgency specs,
 * adds missing urgent items, removes obsolete auto-added items.
 * Never depends on page navigation or user interaction.
 */
async function _backgroundBringSync() {
    const lastRun = parseInt(localStorage.getItem('_bgBringSyncTs') || '0');
    if (Date.now() - lastRun < 5 * 60 * 1000) return;
    localStorage.setItem('_bgBringSyncTs', String(Date.now()));

    try {
        const [bringData, smartData] = await Promise.all([
            api('shopping_list').catch(() => null),
            api('smart_shopping').catch(() => null),
        ]);

        if (!bringData?.success || !smartData?.success) return;

        const listUUID = bringData.listUUID;
        const bringItems = bringData.purchase || [];
        const smartItems = smartData.items || [];

        if (!listUUID || !smartItems.length) return;

        // Always update local caches with fresh data
        smartShoppingItems = smartItems;
        _smartShoppingLastFetch = Date.now();
        shoppingListUUID = listUUID;
        shoppingItems = bringItems;

        if (bringData.recently?.length) {
            _markBringPurchased(bringData.recently.map(i => i.name).filter(Boolean));
        }

        const toAdd    = []; // new items not yet on Bring
        const toUpdate = []; // items on Bring that need spec updated
        const toRemove = []; // items on Bring that are no longer urgent (auto-added, now resolved)

        // Build set of auto-added item names so we can safely remove them if resolved
        const autoAdded = new Set(JSON.parse(localStorage.getItem('_bgAutoAdded') || '[]'));

        for (const si of smartItems) {
            const expectedSpec = _urgencyToSpec(si.urgency);
            const bringMatch = bringItems.find(bi => {
                if (bi.name.toLowerCase() === si.name.toLowerCase()) return true;
                const biFirst = _nameTokens(bi.name)[0];
                const siFirst = _nameTokens(si.name)[0];
                return biFirst && siFirst && biFirst === siFirst;
            });

            if (!bringMatch) {
                if (['critical', 'high', 'medium', 'low'].includes(si.urgency) && !_isBringPurchased(si.name, si.urgency)) {
                    toAdd.push({ name: si.name, specification: expectedSpec });
                    autoAdded.add(si.name.toLowerCase());
                }
            } else if (!_isBringPurchased(si.name, si.urgency)) {
                // Already on Bring — sync urgency spec unconditionally
                const currentSpec = (bringMatch.specification || '').toLowerCase();
                const hasUrgencyMarker = currentSpec.includes('urgente') || currentSpec.includes('presto');
                const expectedLower = (expectedSpec || '').toLowerCase();
                const specChanged = expectedSpec
                    ? !currentSpec.includes(expectedLower.split(' ')[1] || expectedLower) // marker changed
                    : hasUrgencyMarker; // need to clear

                if (specChanged) {
                    toUpdate.push({ name: bringMatch.name, specification: expectedSpec, update_spec: true });
                    bringMatch.specification = expectedSpec;
                }
            }
        }

        // Remove items auto-added by us that are no longer urgent (resolved)
        for (const bi of bringItems) {
            const nameLower = bi.name.toLowerCase();
            if (!autoAdded.has(nameLower)) continue; // not auto-added by us, skip
            const stillUrgent = smartItems.some(si => {
                if (si.name.toLowerCase() === nameLower) return si.urgency === 'high' || si.urgency === 'critical';
                const siFirst = _nameTokens(si.name)[0];
                const biFirst = _nameTokens(bi.name)[0];
                return siFirst && biFirst && siFirst === biFirst && (si.urgency === 'high' || si.urgency === 'critical');
            });
            if (!stillUrgent) {
                toRemove.push(bi.name);
                autoAdded.delete(nameLower);
            }
        }

        // Persist updated auto-added set
        localStorage.setItem('_bgAutoAdded', JSON.stringify([...autoAdded]));

        const allChanges = [...toAdd, ...toUpdate];
        if (allChanges.length > 0) {
            await api('shopping_add', {}, 'POST', { items: allChanges, listUUID });
            logOperation('bg_bring_sync', { added: toAdd.map(i=>i.name), updated: toUpdate.map(i=>i.name) });
        }

        if (toRemove.length > 0) {
            await api('shopping_remove', {}, 'POST', { items: toRemove.map(n => ({ name: n })), listUUID });
            logOperation('bg_bring_remove', { removed: toRemove });
        }

        // Update urgency badge on dashboard without re-rendering anything visible
        _updateSmartUrgencyBadge();

        // If shopping page is open, re-render only when data actually changed
        if (_currentPageId === 'shopping') {
            _syncOnBringFlags();
            _syncTagsFromBringSpec();
            renderSmartShopping();
            updateShoppingTabCounts();
            const newSig = _shoppingRenderSignature();
            if (newSig !== _backgroundBringSync._lastSig) {
                _backgroundBringSync._lastSig = newSig;
                renderShoppingItems();
            } else {
                _applyPriceBadgesFromCache();
                _applyShoppingTotalDisplay();
            }
        }

    } catch (e) { /* silent — best effort */ }
}

