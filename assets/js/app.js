/**
 * EverShelf - Main Application JS
 * Complete pantry management with barcode scanning, AI identification,
 * Bring! shopping list integration, recipe generation, and TTS cooking mode.
 *
 * @author Stimpfl Daniel <evershelfproject@gmail.com>
 * @license MIT
 */

// ===== REMOTE LOGGING =====
// Global remote logger: captures all errors, warnings and key operations
const _remoteLogBuffer = [];
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
    fetch(`api/index.php?action=client_log`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ messages: msgs })
    }).catch(() => {});
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

// Catch unhandled errors
window.addEventListener('error', function(e) {
    remoteLog('UNCAUGHT', `${e.message} at ${e.filename}:${e.lineno}:${e.colno}`);
});
window.addEventListener('unhandledrejection', function(e) {
    remoteLog('UNHANDLED_PROMISE', e.reason);
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

function _scaleConnect(url) {
    if (_scaleEs) { try { _scaleEs.close(); } catch(e) {} _scaleEs = null; }
    if (_scaleReconnectTimer) { clearTimeout(_scaleReconnectTimer); _scaleReconnectTimer = null; }
    try {
        // Connect via the PHP SSE relay so the HTTPS page is not blocked by mixed-content
        _scaleEs = new EventSource('api/scale_relay.php?url=' + encodeURIComponent(url));
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
        // Refresh all scale UI elements immediately so buttons/live-box appear
        // without requiring a manual page refresh
        updateScaleReadButtons();
    } else if (msg.type === 'weight') {
        // Ignore negative weight values (tare artifacts, sensor noise)
        const rawValue = parseFloat(msg.value);
        if (rawValue < 0) return;

        // Ignore sub-gram jitter for stability decisions: only integer-gram changes matter.
        let effectiveStable = !!msg.stable;
        const grams = _scaleToGrams(rawValue, msg.unit);
        if (grams !== null) {
            if (effectiveStable) {
                _scaleLastStableGrams = grams;
            } else if (_scaleLastStableGrams !== null) {
                if (Math.round(grams) === Math.round(_scaleLastStableGrams)) {
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
    // Convert to grams for the < 10 g threshold check
    let gForCheck = isFinite(raw) ? raw : 0;
    if (rawUnit === 'kg')  gForCheck = raw * 1000;
    if (rawUnit === 'lbs' || rawUnit === 'lb') gForCheck = raw * 453.592;

    const valEl   = document.getElementById('scale-live-val');
    const lblEl   = document.getElementById('scale-live-label');

    if (isFinite(raw) && gForCheck < 10 && gForCheck > 0) {
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
            lblEl.textContent = '';
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
    if (_useConfMode && _useConfMode._activeUnit === 'sub') {
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

    if (val !== _scaleStabilityVal) {
        // New (different) weight → clear dismissal, restart stability wait
        _scaleStabilityVal = val;
        _scaleUserDismissed = false;
        _cancelScaleTimersOnly();
        _startScaleStabilityWait(() => {
            // Fill the input after 5 s of stable weight
            const inp = document.getElementById('use-quantity');
            if (inp) inp.value = val;
            // Start the 5-s confirm progress bar
            _startScaleAutoConfirm(() => {
                _scaleLastConfirmedGrams = grams;
                const form = document.querySelector('#page-use form');
                if (form) form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
            }, 'btn-use-submit');
        });
    } else if (!_scaleUserDismissed && !_scaleStabilityTimer && !_scaleAutoConfirmTimer) {
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

    // Reject if weight hasn't changed enough from last confirmed reading
    if (_scaleLastConfirmedGrams !== null && Math.abs(grams - _scaleLastConfirmedGrams) < 10) {
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
    el.className = `scale-status-indicator scale-status-${state}`;
    const labels = {
        connected:    `⚖️ ${t('scale.status_connected')}${_scaleDevice ? ': ' + _scaleDevice : ''}`,
        searching:    `⚖️ ${t('scale.status_searching')}`,
        disconnected: `⚖️ ${t('scale.status_disconnected')}`,
        error:        `⚖️ ${t('scale.status_error')}`,
    };
    el.title = labels[state] || '';
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
        btnUse.style.display = (ready && (_useNormalUnit === 'g' || _useNormalUnit === 'ml')) ? '' : 'none';
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
    fetch('api/scale_ping.php?url=' + encodeURIComponent(url), { signal: ac.signal })
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
    status.textContent = '🔍 Scanning local network for scale gateway…';

    try {
        const res  = await fetch('api/scale_discover.php', { signal: AbortSignal.timeout(8000) });
        const data = await res.json();

        if (data.error) {
            status.textContent = '❌ ' + data.error;
        } else if (data.found && data.found.length > 0) {
            const url = data.found[0];
            const urlEl = document.getElementById('setting-scale-url');
            if (urlEl) urlEl.value = url;
            status.textContent = '✅ Gateway found: ' + url + (data.found.length > 1 ? ' (+' + (data.found.length - 1) + ' more)' : '');
            status.style.color = 'var(--color-success, #059669)';
            // Auto-save
            const s = getSettings();
            s.scale_gateway_url = url;
            saveSettingsToStorage(s);
            scaleInit();
        } else {
            status.textContent = '❌ No gateway found on ' + (data.subnet || 'local network') + '. Make sure the Android app is running and on the same Wi-Fi.';
        }
    } catch(e) {
        status.textContent = '❌ Discovery failed: ' + (e.message || 'timeout');
    }

    btn.disabled = false;
    btn.textContent = '🔍 Auto';
}

// ===== i18n TRANSLATION SYSTEM =====
let _i18nStrings = null;   // current language translations (flat)
let _i18nFallback = null;  // Italian fallback (flat)
let _currentLang = localStorage.getItem('evershelf_lang') || navigator.language?.slice(0, 2) || 'it';
const _SUPPORTED_LANGS = { it: 'Italiano', en: 'English', de: 'Deutsch' };
if (!_SUPPORTED_LANGS[_currentLang]) _currentLang = 'it';

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
        // Always load Italian as fallback
        if (!_i18nFallback) {
            const fbRes = await fetch(`translations/it.json?v=${Date.now()}`);
            if (fbRes.ok) _i18nFallback = _flattenI18n(await fbRes.json());
        }
        if (lang === 'it') {
            _i18nStrings = _i18nFallback;
        } else {
            const res = await fetch(`translations/${encodeURIComponent(lang)}.json?v=${Date.now()}`);
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
function mapToLocalCategory(ofCategory, productName) {
    if (!ofCategory) {
        // No category tag — try to guess from product name
        return guessCategoryFromName(productName || '');
    }
    const cat = ofCategory.toLowerCase();
    // Direct match with our local keys
    for (const key of Object.keys(CATEGORY_ICONS)) {
        if (cat === key) return key;
    }
    
    // Handle specific Open Food Facts tags FIRST (before generic regex)
    // "plant-based-foods-and-beverages" is a catch-all — use product name to decide
    if (/plant-based-foods/.test(cat)) {
        return guessCategoryFromName(productName || '');
    }
    // "beverages-and-beverages-preparations" = actual beverages
    if (/^en:beverages/.test(cat)) return 'bevande';
    // sweeteners = condimenti
    if (/sweetener|dolcific/.test(cat)) return 'condimenti';
    
    // Specific tag patterns
    if (/dairy|lait|cheese|fromage|yoghurt|milk|latticin|latte/.test(cat)) return 'latticini';
    if (/meat|viande|carne|sausage|salum|prosciutt/.test(cat)) return 'carne';
    if (/fish|poisson|pesce|seafood|tuna|tonno|salmone/.test(cat)) return 'pesce';
    if (/fruit|frutta|juice|succo|apple|banana/.test(cat)) return 'frutta';
    if (/vegetable|verdur|legum|salad|insalat|tomato|pomodor/.test(cat)) return 'verdura';
    if (/pasta|rice|riso|noodle|spaghetti|penne|grain/.test(cat)) return 'pasta';
    if (/bread|pane|forno|biscott|toast|cracker|grissini|fette/.test(cat)) return 'pane';
    if (/frozen|surgelé|surgel|gelat/.test(cat)) return 'surgelati';
    if (/sauce|condiment|oil|olio|vinegar|aceto|mayo|ketchup|spice|salt|sugar|zuccher/.test(cat)) return 'condimenti';
    if (/snack|chip|crisp|chocolate|cioccolat|candy|biscuit|cookie|wafer|merendine|patatine/.test(cat)) return 'snack';
    if (/conserve|canned|can|pelati|passata|preserve|jam|marmellat|miele|honey/.test(cat)) return 'conserve';
    if (/cereal|muesli|granola|oat|fiocchi/.test(cat)) return 'cereali';
    if (/hygiene|soap|shampoo|igien|dentifricio|deodorant/.test(cat)) return 'igiene';
    if (/clean|detergent|pulizia|detersiv/.test(cat)) return 'pulizia';
    // Beverage check LAST (to avoid false matches on compound tags)
    if (/^(?!.*plant-based).*(beverage|drink|boisson|bevand|water|acqua|beer|birra|wine|vino|coffee|caffè|tea\b)/.test(cat)) return 'bevande';
    return 'altro';
}

// Guess a local category purely from product name
function guessCategoryFromName(name) {
    if (!name) return 'altro';
    const n = name.toLowerCase();
    // Pasta & Rice
    if (/spaghetti|penne|fusilli|rigatoni|linguine|orecchiette|farfalle|pasta\b|riso\b|basmati|carnaroli|arborio/.test(n)) return 'pasta';
    // Pane & Forno
    if (/pane\b|fette biscottate|grissini|cracker|toast|piadina|piadelle|focaccia|panini|sandwich|taralli/.test(n)) return 'pane';
    // Conserve
    if (/passata|pelati|pomodoro|sugo|polpa di pomod|marmellata|miele|legumi|ceci|fagioli|lenticchie|olive/.test(n)) return 'conserve';
    // Condimenti
    if (/olio\b|aceto|sale\b|pepe\b|zucchero|zuccher|farina|maionese|ketchup|senape|salsa/.test(n)) return 'condimenti';
    // Bevande
    if (/acqua|birra|vino|succo|spremuta|coca.cola|aranciata|caffè|tè\b|tea\b|latte\b/.test(n)) return 'bevande';
    // Latticini
    if (/latte\b|yogurt|formaggio|mozzarella|burro|panna|ricotta|mascarpone|gorgonzola|parmigiano|grana\b/.test(n)) return 'latticini';
    // Carne
    if (/pollo|manzo|maiale|vitello|tacchino|prosciutto|salame|bresaola|mortadella|wurstel|speck/.test(n)) return 'carne';
    // Pesce
    if (/tonno|salmone|merluzzo|pesce|sgombro|gamberi|acciughe/.test(n)) return 'pesce';
    // Frutta
    if (/mela|mele|banana|arancia|pera|fragola|uva|kiwi|limone|frutta/.test(n)) return 'frutta';
    // Verdura
    if (/insalata|zucchina|pomodor|cipolla|carota|spinaci|rucola|peperoni|melanzane|broccoli|patata/.test(n)) return 'verdura';
    // Surgelati
    if (/surgelat|frozen|findus|4.salti|gelato/.test(n)) return 'surgelati';
    // Snack
    if (/biscott|cioccolat|nutella|merendine|patatine|caramelle|wafer|sfornatini/.test(n)) return 'snack';
    // Cereali
    if (/cereali|muesli|fiocchi|granola|polenta/.test(n)) return 'cereali';
    // Igiene / Pulizia
    if (/sapone|shampoo|dentifricio|deodorante/.test(n)) return 'igiene';
    if (/detersivo|pulito|sgrassatore/.test(n)) return 'pulizia';
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
    else if (/parmigiano|grana|pecorino|provolone/.test(name)) days = 60;
    else if (/burro/.test(name)) days = 60;
    else if (/panna/.test(name)) days = 14;
    else if (/prosciutto\s+cotto|mortadella|wurstel/.test(name)) days = 7;
    else if (/prosciutto\s+crudo|salame|bresaola|speck/.test(name)) days = 30;
    else if (/nduja/.test(name)) days = 90;
    else if (/uova/.test(name)) days = 28;
    else if (/pane\s+fresco|pane\s+in\s+cassetta/.test(name)) days = 5;
    else if (/pane\s+confezionato|pan\s+carr|pancarrè/.test(name)) days = 14;
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
    else if (/patata|patate/.test(name)) days = 14;
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
        else if (/patata|patate/.test(name)) days = Math.max(days, 21);
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
        return 60;
    }

    if (/latte\s+(fresco|intero|parzial|scremato)/.test(name)) return 3;
    if (/latte\s+(uht|a\s+lunga)/.test(name)) return 7;
    // Long-life mountain/brand milks stored in pantry before use (UHT)
    if (/latte.*(montagna|alta\s+qual|parmalat|granarolo|esselunga|conservaz|microfiltrat)/i.test(name)) return 7;
    if (/\blatte\b/.test(name)) return 4;
    if (/\byogurt\b/.test(name)) return 5;
    if (/mozzarella|burrata|stracciatella/.test(name)) return 3;
    if (/philadelphia|spalmabile/.test(name)) return 7;
    if (/formaggio.*(fresco|ricotta|mascarpone|stracchino|crescenza)/.test(name)) return 5;
    if (/parmigiano|grana|pecorino|provolone/.test(name)) return 21;
    if (/formaggio/.test(name)) return 10;
    if (/\bburro\b/.test(name)) return 30;
    if (/\bpanna\b/.test(name)) return 4;
    if (/prosciutto\s+cotto|mortadella|wurstel/.test(name)) return 5;
    if (/prosciutto\s+crudo|salame|bresaola|speck|pancetta|nduja/.test(name)) return 7;
    if (/\b(pollo|tacchino|maiale|manzo|vitello|agnello)\b/.test(name)) return 2;
    if (/salmone|tonno\s+fresco|pesce(?!\s+in)/.test(name)) return 2;
    if (/\b(passata|pelati|polpa|sugo|salsa\s+di\s+pomodoro)\b/.test(name)) return 5;
    if (/insalata|rucola|spinaci|lattuga|crescione|germogli/.test(name)) return 2;
    if (/\b(succo|spremuta)\b/.test(name)) return 3;
    if (/\b(birra|beer)\b/.test(name)) return 3;
    if (/\bvino\b/.test(name)) return 5;
    if (/tonno\s+in\s+scatola|tonno\s+rio|sgombro\s+in/.test(name)) return 4;
    // Fruit opened/cut in fridge
    if (/\bavocado\b/.test(name)) return 2;
    if (/\b(banana|banane|fragola|lampone|pesca|albicocca|ciliegia|mango|papaya)\b/.test(name)) return 2;
    if (/\b(mela|pera|nettarina|prugna|kiwi|ananas|uva|melone|anguria)\b/.test(name)) return 3;
    if (/\b(arancia|mandarino|pompelmo|clementina|limone)\b/.test(name)) return 3;
    // Vegetables opened/cut in fridge
    if (/\b(zucchina|zucchine|melanzana|pomodor)\b/.test(name)) return 3;
    if (/\b(peperone|peperoni)\b/.test(name)) return 3;
    if (/\b(broccolo|broccoli|cavolfiore|cavolo)\b/.test(name)) return 3;
    if (/\bsedano\b|\bfinocchio\b/.test(name)) return 3;
    if (/\b(cipolla|cipolle|cipollotto|scalogno|porro)\b/.test(name)) return 4;
    if (/\b(carota|carote)\b/.test(name)) return 5;
    if (/\b(patata|patate|tubero)\b/.test(name)) return 3;
    if (/\baglio\b/.test(name)) return 10;

    // ── G: Fridge condiments ─────────────────────────────────────────────
    if (/maionese|mayo|mayon/.test(name)) return 90;
    if (/\bketchup\b/.test(name)) return 90;
    if (/\b(senape|mustard)\b/.test(name)) return 90;
    if (/salsa\s+di\s+soia|soy\s*sauce/.test(name)) return 90;
    if (/\b(tabasco|worcestershire|sriracha)\b/.test(name)) return 180;
    if (/confettura|marmellata/.test(name)) return 60;
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
let _scanZoomLevel = 1; // 1 or 2

async function toggleScanZoom() {
    _scanZoomLevel = _scanZoomLevel === 1 ? 2 : 1;
    const btn = document.getElementById('scan-zoom-btn');
    if (btn) btn.textContent = `x${_scanZoomLevel}`;
    if (scannerStream) {
        const track = scannerStream.getVideoTracks()[0];
        if (track) {
            const caps = track.getCapabilities ? track.getCapabilities() : {};
            if (caps.zoom) {
                // Hardware zoom (Android Chrome)
                const z = _scanZoomLevel === 2
                    ? Math.min(caps.zoom.max, caps.zoom.min * 2 || 2)
                    : caps.zoom.min;
                try { await track.applyConstraints({ advanced: [{ zoom: z }] }); } catch(e) {}
            } else {
                // Software zoom via CSS scale on the video element
                const video = document.getElementById('scanner-video');
                if (video) video.style.transform = _scanZoomLevel === 2 ? 'scale(2)' : 'scale(1)';
            }
        }
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
        try {
            _settingsCache = JSON.parse(localStorage.getItem('evershelf_settings') || '{}');
        } catch(e) { _settingsCache = {}; }
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
    localStorage.setItem('evershelf_settings', JSON.stringify(settings));
    // Persist to DB
    _settingsDirty = true;
    _debouncedSyncSettings();
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
        // Primary: load from server .env
        const serverSettings = await api('get_settings');
        const s = getSettings();
        const serverKeys = ['default_persons','pref_veloce','pref_pocafame','pref_scadenze',
            'pref_healthy','pref_opened','pref_zerowaste','dietary','appliances',
            'camera_facing','scale_enabled','scale_gateway_url',
            'meal_plan_enabled','tts_enabled','tts_url','tts_token',
            'tts_method','tts_auth_type','tts_content_type','tts_payload_key'];
        for (const key of serverKeys) {
            if (serverSettings[key] !== undefined && serverSettings[key] !== null && serverSettings[key] !== '') {
                s[key] = serverSettings[key];
            }
        }
        _settingsCache = s;
        localStorage.setItem('evershelf_settings', JSON.stringify(s));
        // Also load review_confirmed from DB
        const res = await api('app_settings_get');
        if (res.success && res.settings) {
            if (res.settings.review_confirmed) {
                _reviewConfirmedCache = res.settings.review_confirmed;
            }
        }
    } catch(e) { /* offline, use local */ }
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
        const serverKeys = ['gemini_key','bring_email','bring_password',
            'default_persons','pref_veloce','pref_pocafame','pref_scadenze',
            'pref_healthy','pref_opened','pref_zerowaste','dietary','appliances',
            'camera_facing','scale_enabled','scale_gateway_url',
            'meal_plan_enabled',
            'tts_enabled','tts_url','tts_token','tts_method','tts_auth_type',
            'tts_content_type','tts_payload_key'];
        let changed = false;
        for (const key of serverKeys) {
            if (serverSettings[key] !== undefined && serverSettings[key] !== null && serverSettings[key] !== '') {
                s[key] = serverSettings[key];
                changed = true;
            }
        }
        if (changed) {
            _settingsCache = s;
            localStorage.setItem('evershelf_settings', JSON.stringify(s));
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
        }
    } catch(e) { /* offline, use local */ }
    // Scale settings
    const scaleEnabledUiEl = document.getElementById('setting-scale-enabled');
    if (scaleEnabledUiEl) scaleEnabledUiEl.checked = !!s.scale_enabled;
    const scaleUrlUiEl = document.getElementById('setting-scale-url');
    if (scaleUrlUiEl) scaleUrlUiEl.value = s.scale_gateway_url || '';
    // Hide kiosk download banner if running inside Android WebView (kiosk mode)
    const kioskBanner = document.getElementById('kiosk-download-banner');
    if (kioskBanner && /; wv\)/.test(navigator.userAgent)) {
        kioskBanner.style.display = 'none';
    }
}

// ── Kiosk overlay: X (close) + ↻ (refresh) buttons ───────────────────
// Injected inside the header-content div, BEFORE the title.
// Only shown when _kioskBridge JS interface is available (Android WebView).
function _injectKioskOverlay() {
    if (typeof _kioskBridge === 'undefined') return;
    if (document.getElementById('_kiosk_overlay')) return;

    const headerContent = document.querySelector('.header-content');
    if (!headerContent) return;

    const wrap = document.createElement('div');
    wrap.id = '_kiosk_overlay';
    wrap.style.cssText = 'display:flex;gap:6px;align-items:center;margin-right:8px;flex-shrink:0;';

    const btnStyle = 'background:rgba(255,255,255,0.2);border:none;color:#fff;width:34px;height:34px;border-radius:50%;font-size:15px;cursor:pointer;display:flex;align-items:center;justify-content:center;-webkit-tap-highlight-color:transparent;touch-action:manipulation;';

    // Exit button
    const exitBtn = document.createElement('button');
    exitBtn.id = '_kiosk_exit_btn';
    exitBtn.textContent = '\u2715';
    exitBtn.title = 'Esci dal kiosk';
    exitBtn.style.cssText = btnStyle;
    exitBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        if (confirm(t('confirm.kiosk_exit'))) _kioskBridge.exit();
    });

    // Refresh button
    const refBtn = document.createElement('button');
    refBtn.id = '_kiosk_refresh_btn';
    refBtn.textContent = '\u21bb';
    refBtn.title = 'Aggiorna pagina';
    refBtn.style.cssText = btnStyle.replace('font-size:15px', 'font-size:18px');
    refBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        _kioskBridge.hardReload();
    });

    wrap.appendChild(exitBtn);
    wrap.appendChild(refBtn);
    headerContent.insertBefore(wrap, headerContent.firstChild);
}

function renderAppliances(appliances) {
    const container = document.getElementById('appliances-list');
    if (!appliances || appliances.length === 0) {
        container.innerHTML = '<p style="color:var(--text-muted);font-size:0.85rem;padding:8px 0">Nessun elettrodomestico aggiunto</p>';
        return;
    }
    container.innerHTML = appliances.map((a, i) => `
        <div class="appliance-item">
            <span>🔌 ${escapeHtml(a)}</span>
            <button class="appliance-remove" onclick="removeAppliance(${i})" title="Rimuovi">✕</button>
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
    showToast(`${name} aggiunto`, 'success');
}

function removeAppliance(idx) {
    const s = getSettings();
    if (!s.appliances) return;
    s.appliances.splice(idx, 1);
    saveSettingsToStorage(s);
    renderAppliances(s.appliances);
}

async function saveSettings() {
    const s = getSettings();
    s.gemini_key = document.getElementById('setting-gemini-key').value.trim();
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
    saveSettingsToStorage(s);
    
    // Save ALL settings to server .env
    try {
        const result = await api('save_settings', {}, 'POST', {
            gemini_key: s.gemini_key,
            bring_email: s.bring_email,
            bring_password: s.bring_password,
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
            tts_enabled: s.tts_enabled,
            tts_url: s.tts_url,
            tts_token: s.tts_token,
            tts_method: s.tts_method,
            tts_auth_type: s.tts_auth_type,
            tts_content_type: s.tts_content_type,
            tts_payload_key: s.tts_payload_key,
        });
        const statusEl = document.getElementById('settings-status');
        if (result.success) {
            statusEl.className = 'settings-status success';
            statusEl.textContent = `✅ ${t('settings.saved')}`;
        } else {
            statusEl.className = 'settings-status error';
            statusEl.textContent = `⚠️ ${t('settings.saved_local_error').replace('{error}', result.error || '')}`;
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
}

function switchSettingsTab(btn, tabId) {
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
async function api(action, params = {}, method = 'GET', body = null) {
    let url = `${API_BASE}?action=${action}`;
    if (method === 'GET') {
        Object.entries(params).forEach(([k, v]) => {
            url += `&${encodeURIComponent(k)}=${encodeURIComponent(v)}`;
        });
    }
    const opts = { method };
    if (body) {
        opts.headers = { 'Content-Type': 'application/json' };
        opts.body = JSON.stringify(body);
    }
    const res = await fetch(url, opts);
    if (!res.ok) {
        remoteLog('API_ERROR', `${action} HTTP ${res.status}`);
    }
    const data = await res.json();
    if (data && data.error) {
        remoteLog('API_FAIL', `${action}: ${data.error}`);
    }
    return data;
}

// ===== PAGE NAVIGATION =====
// Track current page for auto-refresh
let _currentPageId = 'dashboard';
let _currentPageParam = null;

// Refresh current page data without full navigation
function refreshCurrentPage() {
    switch(_currentPageId) {
        case 'dashboard': loadDashboard(); break;
        case 'inventory': loadInventory(); break;
        case 'shopping': loadShoppingList(); break;
        case 'products': loadAllProducts(); break;
        case 'recipe':   loadRecipeArchive(); break;
        case 'log':      loadLog(); break;
        // scan/ai/settings/chat: nessun dato live da ricaricare
    }
}

function showPage(pageId, param = null) {
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
        case 'dashboard': loadDashboard(); break;
        case 'inventory':
            if (param !== null) {
                currentLocation = param;
                filterLocation(param);
            }
            loadInventory();
            break;
        case 'scan': initScanner(); clearQuickNameResults(); updateSpesaBanner();
            // Pre-warm the embedding model the first time user visits scan page
            if (typeof window._getCategoryPipeline === 'function' && !window._categoryPipelineReady) {
                window._getCategoryPipeline(); // fire-and-forget
            }
            break;
        case 'products': loadAllProducts(); break;
        case 'shopping': loadShoppingList(); break;
        case 'recipe': loadRecipeArchive(); break;
        case 'log': loadLog(); break;
        case 'ai': initAICamera(); break;
        case 'settings': loadSettingsUI(); break;
        case 'chat': initChat(); break;
    }

    // Auto-refresh banner notifications while on dashboard (every 5 min)
    if (_bannerRefreshTimer) { clearInterval(_bannerRefreshTimer); _bannerRefreshTimer = null; }
    if (pageId === 'dashboard') {
        _bannerRefreshTimer = setInterval(() => loadBannerAlerts(), 5 * 60 * 1000);
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
    section.style.display = 'block';

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

// ===== DASHBOARD =====
async function loadDashboard() {
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
            document.getElementById(`stat-${loc}`).textContent = count;
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

        // Anti-waste section (load facts first so rotation has full dataset)
        await _awLoadFacts();
        _renderAntiWasteSection(
            statsData.used_30d      || 0, statsData.wasted_30d      || 0,
            statsData.used_prev_30d || 0, statsData.wasted_prev_30d || 0,
            statsData.used_prev_60d || 0, statsData.wasted_prev_60d || 0,
            navigator.onLine
        );
        _startAntiWasteAutoRefresh();

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
                const unitLabels = { 'ml': 'ml', 'g': 'g', 'pz': 'pz' };
                let qtyText = '';

                if (item.unit === 'conf') {
                    const pkgUnit = item.package_unit;
                    const pkgLabel = unitLabels[pkgUnit] || pkgUnit;
                    const wholeConf = Math.floor(qty + 0.001);
                    const frac = Math.round((qty - wholeConf) * 1000) / 1000;
                    const remainderAmt = frac * pkgSize;
                    const remainderText = formatSubRemainder(remainderAmt, pkgUnit);
                    if (wholeConf > 0 && remainderAmt >= 1) {
                        qtyText = `${wholeConf} conf (da ${pkgSize}${pkgLabel}) + ${remainderText}`;
                    } else if (wholeConf > 0) {
                        qtyText = `${wholeConf} conf (da ${pkgSize}${pkgLabel})`;
                    } else {
                        qtyText = remainderText;
                    }
                } else {
                    const unitLabel = unitLabels[item.unit] || item.unit || '';
                    const wholePackages = Math.floor(qty / pkgSize + 0.001);
                    const remainder = Math.round((qty - wholePackages * pkgSize) * 100) / 100;
                    if (wholePackages > 0 && remainder > 0.01) {
                        qtyText = `${wholePackages} × ${pkgSize}${unitLabel} + ${Math.round(remainder)}${unitLabel} ${t('inventory.qty_remainder_suffix')}`;
                    } else if (remainder > 0.01) {
                        qtyText = `${Math.round(remainder)}${unitLabel} / ${pkgSize}${unitLabel}`;
                    } else {
                        qtyText = `${qty}${unitLabel}`;
                    }
                }

                // Expiry badge
                const days = item.days_to_expiry;
                const isEdible = item.is_edible;
                let expiryBadge = '';
                if (days !== null && days !== undefined) {
                    let expiryClass, expiryText;
                    if (!isEdible) {
                        expiryClass = 'opened-expiry-spoiled';
                        expiryText = t('expiry.badge_expired');
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
    // Navigate to chat and auto-send a prompt about expiring products
    showPage('chat');
    setTimeout(() => {
        document.getElementById('chat-input').value = 'Suggeriscimi una ricetta veloce PER UNA PERSONA usando i prodotti che scadono prima! Ignora i prodotti in freezer (hanno scadenze molto lunghe), concentrati su frigo e dispensa.';
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
    const checkUnit = (unit === 'conf' && packageUnit) ? packageUnit : unit;
    const th = QTY_THRESHOLDS[checkUnit] || QTY_THRESHOLDS['pz'];
    return n > th.max;
}

function getReviewConfirmed() {
    return _reviewConfirmedCache || {};
}
let _reviewConfirmedCache = {};

function setReviewConfirmed(inventoryId) {
    const c = getReviewConfirmed();
    c[inventoryId] = Date.now();
    _reviewConfirmedCache = c;
    api('app_settings_save', {}, 'POST', { settings: { review_confirmed: c } }).catch(() => {});
}

// === ALERT BANNER SYSTEM (replaces old review table) ===
let _bannerQueue = [];   // array of { type, data } — 'review' or 'prediction'
let _bannerIndex = 0;
let _bannerEditPending = false;  // true when editing from banner → dismiss after save
let _bannerRefreshTimer = null;  // periodic refresh while on dashboard

/**
 * Load suspicious quantities + consumption predictions + expired + expiring soon,
 * merge into a single banner queue and show the first item.
 */
async function loadBannerAlerts() {
    _bannerQueue = [];
    _bannerIndex = 0;
    const banner = document.getElementById('alert-banner');
    if (!banner) { console.warn('[Banner] #alert-banner not found'); return; }

    try {
        const [invData, predData, anomalyData, finishedData] = await Promise.all([
            api('inventory_list'),
            api('consumption_predictions').catch(err => { console.warn('[Banner] predictions fetch failed:', err); return { predictions: [] }; }),
            api('inventory_anomalies').catch(err => { console.warn('[Banner] anomalies fetch failed:', err); return { anomalies: [] }; }),
            api('inventory_finished_items').catch(err => { console.warn('[Banner] finished_items fetch failed:', err); return { finished: [] }; }),
        ]);
        const items = invData.inventory || [];
        const confirmed = getReviewConfirmed();

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

            // Check effective expiry based on opened_at
            if (item.opened_at) {
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

            if (daysExpired === null) return; // not expired by any measure
            // Skip items the freezer bonus still considers safe — no need to alarm the user
            if (getExpiredSafety(item, daysExpired).level === 'ok') return;
            _bannerQueue.push({ type: 'expired', data: { ...item, days_expired: daysExpired } });
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

        // 6. Finished products: inventory hit 0, waiting for user confirmation
        const finished = finishedData.finished || [];
        finished.forEach(fin => {
            if (confirmed['fin_' + fin.product_id]) return;
            _bannerQueue.push({ type: 'finished', data: fin });
        });

        // Sort by priority (highest first)
        _bannerQueue.sort((a, b) => _bannerPriority(b) - _bannerPriority(a));

        console.log(`[Banner] queue ready: ${_bannerQueue.length} items (${items.length} inv, ${predictions.length} pred, ${Object.keys(confirmed).length} confirmed)`);

    } catch (e) {
        console.error('[Banner] loadBannerAlerts error:', e);
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
        case 'finished':
            return 600; // product ran out — confirm before removing from DB
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
        const daysText = item.days_expired === 0
            ? t('expiry.expired_today_long')
            : t('expiry.expired_ago_long').replace('{n}', item.days_expired);
        const safety = getExpiredSafety(item, item.days_expired);
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
        const expiredSuffix = safety.level === 'ok'
            ? t('expiry.expired_suffix_ok')
            : safety.level === 'warning'
                ? t('expiry.expired_suffix_warning')
                : t('expiry.expired_suffix');
        titleEl.textContent = `${item.name}${item.brand ? ' (' + item.brand + ')' : ''} ${expiredSuffix}`;
        const baseDetail = t('dashboard.banner_expired_detail').replace('{when}', daysText).replace('{qty}', qtyDisplay);
        detailEl.innerHTML = `${baseDetail} <span class="banner-safety-tip banner-safety-${safety.level}">${safety.icon} ${safety.tip}</span>`;
        let btns = '';
        if (safety.level !== 'danger') {
            btns += `<button class="btn-banner btn-banner-use" onclick="bannerQuickUse()">${t('dashboard.banner_expired_action_use')}</button>`;
        }
        btns += `<button class="btn-banner btn-banner-throw${safety.level === 'danger' ? ' btn-banner-throw-primary' : ''}" onclick="bannerThrowAway()">${t('dashboard.banner_expired_action_throw')}</button>`;
        btns += `<button class="btn-banner btn-banner-edit" onclick="editBannerExpiry()">${t('dashboard.banner_expired_action_edit')}</button>`;
        if (safety.level === 'danger') {
            btns += `<button class="btn-banner btn-banner-use btn-banner-use-danger" onclick="bannerQuickUse()">${t('dashboard.banner_expired_action_use')}</button>`;
        }
        btns += `<button class="btn-banner btn-banner-ok" onclick="dismissBannerExpired()">${t('dashboard.banner_review_dismiss')}</button>`;
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
        iconEl.textContent = '📦';
        const barcodeSuffix = fin.barcode && fin.barcode.length >= 3
            ? ` <span style="font-family:monospace;font-size:0.7em;opacity:0.6">…${escapeHtml(fin.barcode.slice(-3))}</span>`
            : '';
        titleEl.innerHTML = `${escapeHtml(fin.name)}${fin.brand ? ' (' + escapeHtml(fin.brand) + ')' : ''}${barcodeSuffix} — ${escapeHtml(t('dashboard.banner_finished_title'))}`;
        const expectedText = fin.expected_qty ? ' ' + t('dashboard.banner_finished_expected', { qty: fin.expected_qty, unit: fin.unit }) : '';
        detailEl.innerHTML = t('dashboard.banner_finished_zero') + expectedText + ' ' + t('dashboard.banner_finished_check');
        let btns = `<button class="btn-banner btn-banner-ok" onclick="confirmBannerFinished()">${t('dashboard.banner_finished_action_yes')}</button>`;
        btns += `<button class="btn-banner btn-banner-edit" onclick="notFinishedBannerAction()">${t('dashboard.banner_finished_action_no')}</button>`;
        actionsEl.innerHTML = btns;

    } else if (entry.type === 'anomaly') {
        const an = entry.data;
        const isPhantom = an.direction === 'phantom';
        const isUntracked = an.direction === 'untracked';
        banner.className = 'alert-banner banner-anomaly';
        iconEl.textContent = '🔍';
        if (isUntracked) {
            // More consumption recorded than entries — initial stock was never registered
            titleEl.textContent = `${an.name} — ${t('dashboard.banner_anomaly_untracked_title')}`;
            detailEl.innerHTML = t('dashboard.banner_anomaly_untracked_detail', { inv_qty: an.inv_qty, unit: an.unit });
        } else if (isPhantom) {
            titleEl.textContent = `${an.name} — ${t('dashboard.banner_anomaly_phantom_title')}`;
            detailEl.innerHTML = t('dashboard.banner_anomaly_phantom_detail', { inv_qty: an.inv_qty, unit: an.unit, expected_qty: an.expected_qty });
        } else {
            titleEl.textContent = `${an.name} — ${t('dashboard.banner_anomaly_ghost_title')}`;
            detailEl.innerHTML = t('dashboard.banner_anomaly_ghost_detail', { expected_qty: an.expected_qty, unit: an.unit, name: an.name, inv_qty: an.inv_qty });
        }
        let btns = `<button class="btn-banner btn-banner-edit" onclick="editBannerAnomaly()">${t('dashboard.banner_anomaly_action_edit')}</button>`;
        btns += `<button class="btn-banner btn-banner-ok" onclick="dismissBannerAnomaly()">${t('dashboard.banner_anomaly_action_dismiss')} (${an.inv_qty} ${an.unit})</button>`;
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
    showToast('✅ Confermato — il sistema ricalcolerà le previsioni dalle prossime registrazioni', 'success');
    dismissBannerItem();
}

function editBannerPrediction() {
    const entry = _bannerQueue[_bannerIndex];
    if (!entry || entry.type !== 'prediction') return;
    _bannerEditPending = true;
    editReviewItem(entry.data.inventory_id, entry.data.product_id);
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
    showToast('Anomalia ignorata', 'info');
    dismissBannerItem();
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
    api('inventory_use', {}, 'POST', {
        product_id: item.product_id,
        quantity: item.quantity,
        location: item.location,
        use_all: true,
        notes: 'Buttato'
    }).then(res => {
        if (res.success) {
            showToast(t('toast.thrown_away', { name: item.name }), 'success');
            loadDashboard();
        }
    }).catch(() => showToast(t('error.connection'), 'error'));
    dismissBannerItem();
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
            showToast(`📤 ${item.name} terminato!`, 'success');
            showLowStockBringPrompt(res, () => loadDashboard());
        } else {
            showToast(res.error || 'Errore', 'error');
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
        await api('inventory_confirm_finished', {}, 'POST', { product_id: productId });
    } catch(e) {}
    setReviewConfirmed('fin_' + productId);
    showToast(t('toast.product_finished_confirmed'), 'success');
    dismissBannerItem();
}

async function notFinishedBannerAction() {
    const entry = _bannerQueue[_bannerIndex];
    if (!entry || entry.type !== 'finished') return;
    const productId = entry.data.product_id;
    // Remove from this session's queue (will re-appear next load if still at qty=0)
    dismissBannerItem();
    showLoading(true);
    try {
        const data = await api('product_get', { id: productId });
        showLoading(false);
        if (data.product) {
            currentProduct = data.product;
            showAddForm();
        } else {
            showToast(t('error.not_found'), 'error');
        }
    } catch(e) {
        showLoading(false);
        showToast(t('error.connection'), 'error');
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
    const unitLabels = { 'pz': 'pz', 'g': 'g', 'ml': 'ml', 'conf': 'conf' };
    const label = unitLabels[unit] || unit || 'pz';

    // Special handling for conf with partial packages
    if (unit === 'conf' && packageUnit && defaultQty > 0) {
        const pkgLabel = unitLabels[packageUnit] || packageUnit;
        const wholeConf = Math.floor(n + 0.001);
        const fractionalConf = Math.round((n - wholeConf) * 1000) / 1000;

        if (fractionalConf < 0.01) {
            return `${wholeConf} conf <span class="conf-size-info">(da ${defaultQty}${pkgLabel})</span>`;
        }
        const remainderText = formatSubRemainder(fractionalConf * defaultQty, packageUnit);
        if (wholeConf > 0) {
            return `${wholeConf} conf <span class="conf-size-info">(da ${defaultQty}${pkgLabel})</span> + ${remainderText}`;
        }
        return remainderText;
    }

    let result;
    if (n === Math.floor(n)) result = `${Math.floor(n)} ${label}`;
    else if (unit === 'pz') result = `${_pzFractionLabel(n)} ${label}`;
    else result = `${n.toFixed(1)} ${label}`;
    return result;
}

// Structured quantity display for inventory cards.
// Returns { mainQty: '10', unitLabel: 'conf', packageDetail: 'da 36g', fraction: '¼' }
function formatQuantityParts(qty, unit, defaultQty, packageUnit) {
    const n = parseFloat(qty) || 0;
    const unitLabels = { 'pz': 'pz', 'g': 'g', 'ml': 'ml', 'conf': 'conf' };
    const label = unitLabels[unit] || unit || 'pz';

    // Special handling for conf with partial packages
    if (unit === 'conf' && packageUnit && defaultQty > 0) {
        const pkgLabel = unitLabels[packageUnit] || packageUnit;
        const wholeConf = Math.floor(n + 0.001);
        const fractionalConf = Math.round((n - wholeConf) * 1000) / 1000;

        if (fractionalConf < 0.01) {
            return { mainQty: `${wholeConf}`, unitLabel: 'conf', packageDetail: `da ${defaultQty}${pkgLabel}`, fraction: '' };
        }
        const remainderText = formatSubRemainder(fractionalConf * defaultQty, packageUnit);
        if (wholeConf > 0) {
            return { mainQty: `${wholeConf}`, unitLabel: 'conf', packageDetail: `da ${defaultQty}${pkgLabel}`, fraction: `+ ${remainderText}` };
        }
        return { mainQty: remainderText, unitLabel: '', packageDetail: '', fraction: '' };
    }

    let mainQty;
    if (n === Math.floor(n)) mainQty = `${Math.floor(n)}`;
    else if (unit === 'pz') mainQty = _pzFractionLabel(n);
    else mainQty = `${n.toFixed(1)}`;
    
    let packageDetail = '';
    let fraction = '';
    if (unit !== 'conf' && defaultQty && defaultQty > 1) {
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
    const catIcon = CATEGORY_ICONS[mapToLocalCategory(item.category, item.name)] || '📦';
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
    const filtered = currentInventory.filter(i =>
        i.name.toLowerCase().includes(q) ||
        (i.brand && i.brand.toLowerCase().includes(q)) ||
        (i.barcode && i.barcode.includes(q))
    );
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
    const imgHtml = product.image_url
        ? `<img src="${escapeHtml(product.image_url)}" alt="" onerror="this.parentElement.innerHTML='${catIcon}'">`
        : catIcon;
    const brandHtml = product.brand ? `<span class="qa-brand">(${escapeHtml(product.brand)})</span>` : '';
    return `
    <button class="quick-access-btn" onclick="quickAccessSelect(${product.product_id})">
        <div class="qa-img">${imgHtml}</div>
        <div class="qa-name">${escapeHtml(product.name)}</div>
        ${brandHtml}
    </button>`;
}

function quickAccessSelect(productId) {
    // Find the product in current inventory and show its detail
    const item = currentInventory.find(i => i.product_id === productId);
    if (item) {
        showItemDetail(item.id, item.product_id);
    } else {
        // Product not in current view (maybe different location), navigate to it
        quickUse(productId, currentLocation || 'dispensa');
    }
}

// ===== ITEM DETAIL MODAL =====
function showItemDetail(inventoryId, productId) {
    const item = currentInventory.find(i => i.id === inventoryId);
    if (!item) return;
    
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
            <button class="btn btn-danger flex-1" onclick="quickUse(${item.product_id}, '${item.location}')">📤 Usa</button>
            <button class="btn btn-primary flex-1" onclick="editInventoryItem(${inventoryId})">✏️ Modifica</button>
            <button class="btn btn-secondary" onclick="deleteInventoryItem(${inventoryId})" style="padding:12px">🗑️</button>
        </div>
    `;
    document.getElementById('modal-overlay').style.display = 'flex';
}

function closeModal() {
    document.getElementById('modal-overlay').style.display = 'none';
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

async function deleteInventoryItem(id) {
    if (confirm(t('confirm.remove_item'))) {
        await api('inventory_delete', {}, 'POST', { id });
        closeModal();
        showToast(t('toast.product_removed'), 'success');
        refreshCurrentPage();
    }
}

function recalcEditExpiry(locInputId, vacuumInputId, expiryInputId) {
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

function editInventoryItem(id) {
    const item = currentInventory.find(i => i.id === id);
    if (!item) {
        closeModal();
        showToast(t('error.not_found'), 'error');
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
    
    window._editingProduct = { name: item.name, category: item.category || '', _isOpened: !!item.opened_at };
    
    // Rebuild modal content for editing (don't close and reopen - just replace content)
    document.getElementById('modal-content').innerHTML = `
        <div class="modal-header">
            <h3>Modifica ${escapeHtml(item.name)}</h3>
            <button class="modal-close" onclick="closeModal()">✕</button>
        </div>
        <form class="form" onsubmit="submitEditInventory(event, ${id}, ${item.product_id})">
            <div class="form-group">
                <label>📦 ${t('inventory.label_quantity').replace('📦 ', '')}</label>
                <div class="qty-control">
                    <button type="button" class="qty-btn" onclick="adjustQty('edit-qty', -1)">−</button>
                    <input type="number" id="edit-qty" value="${item.quantity}" min="0" step="any" class="qty-input">
                    <button type="button" class="qty-btn" onclick="adjustQty('edit-qty', 1)">+</button>
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
                <label>📏 Unità di misura</label>
                <select id="edit-unit" class="form-input" onchange="onEditUnitChange()">
                    ${['pz','g','ml','conf'].map(u => `<option value="${u}" ${(item.unit||'pz') === u ? 'selected' : ''}>${u === 'pz' ? 'pz (pezzi)' : u === 'g' ? 'g (grammi)' : u === 'ml' ? 'ml (millilitri)' : u === 'conf' ? 'conf (confezioni)' : u}</option>`).join('')}
                </select>
            </div>
            <div class="form-group" id="edit-conf-size-group" style="display:${isConf ? 'block' : 'none'}">
                <label>📦 Ogni confezione contiene:</label>
                <div class="conf-size-inputs">
                    <input type="number" id="edit-conf-size" class="form-input conf-size-input" min="1" step="any" value="${confSizeVal}" placeholder="es. 300">
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
}

function onEditUnitChange() {
    const unit = document.getElementById('edit-unit').value;
    const confGroup = document.getElementById('edit-conf-size-group');
    if (confGroup) confGroup.style.display = unit === 'conf' ? 'block' : 'none';
}

async function submitEditInventory(e, id, productId) {
    e.preventDefault();
    const qty = parseFloat(document.getElementById('edit-qty').value);
    const loc = document.getElementById('edit-loc').value;
    const expiry = document.getElementById('edit-expiry').value || null;
    const unit = document.getElementById('edit-unit').value;
    
    const payload = { id, quantity: qty, location: loc, expiry_date: expiry, unit, product_id: productId,
        vacuum_sealed: document.getElementById('edit-vacuum')?.checked ? 1 : 0 };
    
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
    showToast('Aggiornato!', 'success');
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

async function initScanner() {
    const video = document.getElementById('scanner-video');
    const viewport = document.getElementById('scanner-viewport');
    const logEl = document.getElementById('scan-debug-log');
    if (logEl) logEl.textContent = '';
    
    const constraints = getCameraConstraints();
    scanLog(`Camera mode: ${getSettings().camera_facing || 'environment'}`);
    scanLog(`BarcodeDetector: ${_useBarcodeDetector ? 'YES (native)' : 'NO (Quagga fallback)'}`);
    scanLog(`Constraints: ${JSON.stringify(constraints.video)}`);
    
    try {
        stopScanner();
        
        const stream = await navigator.mediaDevices.getUserMedia(constraints);
        const track = stream.getVideoTracks()[0];
        const caps = track.getSettings ? track.getSettings() : {};
        scanLog(`Stream OK — track: ${track.label}`);
        scanLog(`Resolution: ${caps.width||'?'}x${caps.height||'?'}, facing: ${caps.facingMode||'N/A'}`);
        
        scannerStream = stream;
        video.srcObject = stream;
        await video.play();
        scanLog(`Video playing — videoWidth: ${video.videoWidth}, videoHeight: ${video.videoHeight}`);
        
        if (_useBarcodeDetector) {
            startNativeScanner(video);
        } else {
            startQuaggaScanner(video);
        }
        
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

// ===== NATIVE BarcodeDetector SCANNER =====
async function startNativeScanner(videoEl) {
    if (quaggaRunning) return;
    
    const scannerLine = document.querySelector('.scanner-line');
    const detector = new BarcodeDetector({
        formats: ['ean_13', 'ean_8', 'code_128', 'code_39', 'upc_a', 'upc_e']
    });
    
    let scanning = true;
    quaggaRunning = true;
    let frameCount = 0;
    let partialCount = 0;
    let lastDetected = '';
    let detectCount = 0;
    let detectionHistory = {};
    
    scanLog('Native BarcodeDetector started');
    
    function updateFeedback(state) {
        if (!scannerLine) return;
        scannerLine.classList.remove('scanning', 'detecting');
        if (state) scannerLine.classList.add(state);
    }
    
    async function scanFrame() {
        if (!scanning || !scannerStream) return;
        frameCount++;
        
        if (frameCount === 1) updateFeedback('scanning');
        
        try {
            const barcodes = await detector.detect(videoEl);
            
            if (barcodes.length > 0) {
                const code = barcodes[0].rawValue;
                const format = barcodes[0].format;
                partialCount++;
                scanLog(`Native detect #${partialCount} [f${frameCount}]: ${code} (${format})`);
                updateFeedback('detecting');
                
                if (!detectionHistory[code]) detectionHistory[code] = { count: 0 };
                detectionHistory[code].count++;
                
                if (code === lastDetected) {
                    detectCount++;
                } else {
                    lastDetected = code;
                    detectCount = 1;
                }
                
                if (detectCount >= 2 || detectionHistory[code].count >= 2) {
                    scanning = false;
                    quaggaRunning = false;
                    updateFeedback(null);
                    scanLog(`CONFIRMED: ${code} after ${frameCount} frames`);
                    onBarcodeDetected(code);
                    return;
                }
            } else {
                updateFeedback('scanning');
            }
        } catch (e) {
            scanLog(`Native detect error: ${e.message}`);
        }
        
        if (scanning) {
            if (frameCount % 30 === 0) {
                scanLog(`Native scanning... f${frameCount}, partials: ${partialCount}`);
            }
            requestAnimationFrame(scanFrame);
        }
    }
    
    requestAnimationFrame(scanFrame);
}

// ===== QUAGGA FALLBACK SCANNER =====
function startQuaggaScanner(videoEl) {
    if (quaggaRunning) return;
    
    const canvas = document.getElementById('scanner-canvas');
    const ctx = canvas.getContext('2d');
    const frontCam = isFrontCamera();
    const scannerLine = document.querySelector('.scanner-line');
    let frameCount = 0;
    let partialCount = 0;
    
    scanLog(`Quagga starting — frontCam: ${frontCam}`);
    
    let scanning = true;
    quaggaRunning = true;
    let lastDetected = '';
    let detectCount = 0;
    let detectionHistory = {};
    
    // Alternate between full frame and center-cropped for better detection
    let scanPass = 0; // 0=full, 1=center-crop, 2=full-enhanced, 3=center-enhanced
    
    function updateScannerFeedback(state) {
        if (!scannerLine) return;
        scannerLine.classList.remove('scanning', 'detecting');
        if (state) scannerLine.classList.add(state);
    }
    
    function getFrameDataUrl(pass) {
        const vw = videoEl.videoWidth;
        const vh = videoEl.videoHeight;
        
        if (pass % 2 === 0) {
            // Full frame
            canvas.width = vw;
            canvas.height = vh;
            ctx.drawImage(videoEl, 0, 0);
        } else {
            // Center crop: 60% of frame, focused on barcode area
            const cropW = Math.round(vw * 0.7);
            const cropH = Math.round(vh * 0.4);
            const sx = Math.round((vw - cropW) / 2);
            const sy = Math.round((vh - cropH) / 2);
            canvas.width = cropW;
            canvas.height = cropH;
            ctx.drawImage(videoEl, sx, sy, cropW, cropH, 0, 0, cropW, cropH);
        }
        
        // Apply enhancement on passes 2,3 or always for front cam
        if (frontCam || pass >= 2) {
            enhanceCanvasForBarcode(ctx, canvas.width, canvas.height);
        }
        
        return canvas.toDataURL('image/jpeg', 0.95);
    }
    
    function scanFrame() {
        if (!scanning || !scannerStream) return;
        frameCount++;
        scanPass = (scanPass + 1) % 4;
        
        const dataUrl = getFrameDataUrl(scanPass);
        
        if (frameCount === 1) {
            scanLog(`Frame #1 — video: ${videoEl.videoWidth}x${videoEl.videoHeight}`);
            updateScannerFeedback('scanning');
        }
        
        let callbackCalled = false;
        const safetyTimer = setTimeout(() => {
            if (!callbackCalled && scanning) {
                scanLog(`Quagga timeout on f${frameCount}, retrying...`);
                setTimeout(scanFrame, 100);
            }
        }, 5000);
        
        try {
            const imgSize = Math.max(canvas.width, canvas.height);
            Quagga.decodeSingle({
                src: dataUrl,
                numOfWorkers: 0,
                inputStream: { size: Math.min(imgSize, 800) },
                decoder: {
                    readers: [
                        'ean_reader',
                        'ean_8_reader',
                        'code_128_reader',
                        'code_39_reader',
                        'upc_reader',
                        'upc_e_reader'
                    ],
                    multiple: false
                },
                locate: true,
                locator: { patchSize: 'large', halfSample: false }
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
                    if (detectCount >= 2 || dominated.count >= 2) {
                        scanning = false;
                        quaggaRunning = false;
                        updateScannerFeedback(null);
                        scanLog(`CONFIRMED: ${code} after ${frameCount} frames (consec:${detectCount}, total:${dominated.count})`);
                        onBarcodeDetected(code);
                        return;
                    }
                } else {
                    updateScannerFeedback('scanning');
                }
                if (scanning) {
                    if (frameCount % 20 === 0) {
                        scanLog(`Scanning... f${frameCount}, partials: ${partialCount}, pass: ${scanPass}`);
                    }
                    setTimeout(scanFrame, 150);
                }
            });
        } catch (e) {
            callbackCalled = true;
            clearTimeout(safetyTimer);
            scanLog(`Quagga error: ${e.message}`);
            if (scanning) setTimeout(scanFrame, 500);
        }
    }
    
    setTimeout(scanFrame, 500);
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
    quaggaRunning = false;
    _scanZoomLevel = 1;
    if (scannerStream) {
        scannerStream.getTracks().forEach(t => t.stop());
        scannerStream = null;
    }
    const video = document.getElementById('scanner-video');
    if (video) video.srcObject = null;
    const zoomBtn = document.getElementById('scan-zoom-btn');
    if (zoomBtn) zoomBtn.textContent = 'x1';
    
    // Also stop AI camera
    if (aiStream) {
        aiStream.getTracks().forEach(t => t.stop());
        aiStream = null;
    }
    const aiVideo = document.getElementById('ai-video');
    if (aiVideo) aiVideo.srcObject = null;
}

async function onBarcodeDetected(barcode) {
    showLoading(true);
    
    // Vibrate if available
    if (navigator.vibrate) navigator.vibrate(100);
    
    try {
        // First check local DB
        const localResult = await api('search_barcode', { barcode });
        if (localResult.found) {
            currentProduct = localResult.product;
            // If product was saved with 'pz' but has weight info in notes, fix defaults
            if (currentProduct.unit === 'pz' && currentProduct.default_quantity <= 1 && currentProduct.notes) {
                const pesoMatch = currentProduct.notes.match(/Peso:\s*([^·]+)/);
                if (pesoMatch) {
                    const weightStr = pesoMatch[1].trim();
                    const detected = detectUnitAndQuantity(weightStr);
                    if (detected.unit !== 'pz') {
                        currentProduct.unit = detected.unit;
                        currentProduct.default_quantity = detected.quantity;
                        currentProduct.weight_info = weightStr;
                        if (detected.packageUnit) currentProduct.package_unit = detected.packageUnit;
                        if (detected.confCount) currentProduct._confCount = detected.confCount;
                        // Update product in DB for future scans
                        api('product_save', {}, 'POST', {
                            id: currentProduct.id,
                            barcode: currentProduct.barcode,
                            name: currentProduct.name,
                            brand: currentProduct.brand || '',
                            category: currentProduct.category || '',
                            image_url: currentProduct.image_url || '',
                            unit: detected.unit,
                            default_quantity: detected.quantity,
                            package_unit: detected.packageUnit || '',
                            notes: currentProduct.notes,
                        });
                    }
                }
            }
            // Extract weight_info from notes if available (stored as "Peso: 500 g · ...")
            if (!currentProduct.weight_info && currentProduct.notes) {
                const pesoMatch = currentProduct.notes.match(/Peso:\s*([^·]+)/);
                if (pesoMatch) currentProduct.weight_info = pesoMatch[1].trim();
            }
            // Detect confCount from weight_info for multipack pre-fill
            if (currentProduct.weight_info && currentProduct.unit === 'conf' && !currentProduct._confCount) {
                const detected = detectUnitAndQuantity(currentProduct.weight_info);
                if (detected.confCount) currentProduct._confCount = detected.confCount;
            }
            showLoading(false);
            stopScanner();
            showProductAction();
            return;
        }
        
        // Lookup in external DB
        const lookupResult = await api('lookup_barcode', { barcode });
        if (lookupResult.found && lookupResult.product) {
            const p = lookupResult.product;
            // Detect unit and quantity from quantity_info
            const detected = detectUnitAndQuantity(p.quantity_info);
            
            // Build rich notes with all available info
            const notesParts = [];
            if (p.quantity_info) notesParts.push(`Peso: ${p.quantity_info}`);
            if (p.nutriscore) notesParts.push(`Nutriscore: ${p.nutriscore.toUpperCase()}`);
            if (p.nova_group) notesParts.push(`NOVA: ${p.nova_group}`);
            if (p.ecoscore) notesParts.push(`Ecoscore: ${p.ecoscore.toUpperCase()}`);
            if (p.origin) notesParts.push(`Origine: ${p.origin}`);
            if (p.labels) notesParts.push(`Etichette: ${p.labels}`);
            
            // Save to local DB
            const saveResult = await api('product_save', {}, 'POST', {
                barcode: barcode,
                name: p.name || t('product.not_recognized'),
                brand: p.brand || '',
                category: p.category || '',
                image_url: p.image_url || '',
                unit: detected.unit,
                default_quantity: detected.quantity,
                package_unit: detected.packageUnit || '',
                notes: notesParts.join(' · '),
            });
            
            if (saveResult.id) {
                currentProduct = {
                    id: saveResult.id,
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
                showLoading(false);
                stopScanner();
                showProductAction();
                return;
            }
        }
        
        // Not found - ask user to add manually
        showLoading(false);
        stopScanner();
        showToast(t('error.not_found_manual'), 'error');
        startManualEntry(barcode);
        
    } catch (err) {
        showLoading(false);
        console.error('Barcode lookup error:', err);
        showToast(t('error.search'), 'error');
    }
}

function submitManualBarcode() {
    const input = document.getElementById('manual-barcode-input');
    const barcode = (input.value || '').trim();
    if (!barcode) {
        showToast(t('error.barcode_empty'), 'error');
        input.focus();
        return;
    }
    if (!/^\d{4,14}$/.test(barcode)) {
        showToast(t('error.barcode_format'), 'error');
        input.focus();
        return;
    }
    stopScanner();
    onBarcodeDetected(barcode);
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
            showToast('Prodotto creato!', 'success');

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
    document.getElementById('product-form-title').textContent = 'Nuovo Prodotto';
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
        if (qtyInput.dataset.manuallySet !== 'true') {
            unitSelect.value = catDefaults[cat].unit;
            qtyInput.value = catDefaults[cat].qty;
        }
    }
}

function onPfUnitChange() {
    const unit = document.getElementById('pf-unit').value;
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
            <input type="text" id="pf-bc-manual" class="form-input" placeholder="${t('scanner.barcode_manual_placeholder')}" inputmode="numeric" style="max-width:260px;display:inline-block">
            <button class="btn btn-primary" style="margin-top:8px;width:100%" onclick="
                const v = document.getElementById('pf-bc-manual').value.trim();
                if(v){ document.getElementById('pf-barcode').value=v; _updateBarcodeHint(); document.getElementById('modal-overlay').style.display='none'; }
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
                    detectionHistory[code] = (detectionHistory[code] || 0) + 1;
                    if (detectionHistory[code] >= 2) {
                        scanning = false;
                        stopStream();
                        overlayEl.style.display = 'none';
                        overlayEl.onclick = null;
                        document.getElementById('pf-barcode').value = code;
                        _updateBarcodeHint();
                        if (navigator.vibrate) navigator.vibrate(80);
                        showToast(`🔖 Barcode acquisito: ${code}`, 'success');
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
            showToast('Prodotto salvato!', 'success');
            showProductAction();
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
        allergensHtml = `<div class="product-allergens">⚠️ <strong>Allergeni:</strong> ${escapeHtml(currentProduct.allergens)}</div>`;
    }
    
    // Ingredients (collapsible)
    let ingredientsHtml = '';
    if (currentProduct.ingredients) {
        ingredientsHtml = `
            <details class="product-ingredients">
                <summary>📋 Ingredienti</summary>
                <p>${escapeHtml(currentProduct.ingredients)}</p>
            </details>
        `;
    }
    
    // Conservation
    let conservationHtml = '';
    if (currentProduct.conservation) {
        conservationHtml = `<div class="product-conservation">🧊 ${escapeHtml(currentProduct.conservation)}</div>`;
    }
    
    // LARGER product preview
    document.getElementById('action-product-preview').innerHTML = `
        ${currentProduct.image_url ?
            `<img src="${escapeHtml(currentProduct.image_url)}" alt="">` :
            `<span class="product-preview-emoji">${catIcon}</span>`
        }
        <div class="product-preview-info">
            <h3>${escapeHtml(currentProduct.name)}</h3>
            <p>${currentProduct.brand ? `<strong>${escapeHtml(currentProduct.brand)}</strong>` : ''}</p>
            ${currentProduct.weight_info ? `<p style="font-size:0.85rem;color:var(--text-light)">⚖️ ${escapeHtml(currentProduct.weight_info)}</p>` : ''}
            ${currentProduct.barcode ? `<p style="font-size:0.75rem;color:var(--text-muted)">📊 ${currentProduct.barcode}</p>` : ''}
        </div>
        <button type="button" class="btn-edit-inline" onclick="toggleActionEdit()" title="Modifica nome/marca">✏️</button>
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
        `<option value="${key}" ${mapToLocalCategory(currentProduct.category, currentProduct.name) === key ? 'selected' : ''}>${label}</option>`
    ).join('');
    
    editInfoEl.innerHTML = `
        <div class="edit-unknown-card ${isUnknown ? 'highlight' : ''}">
            <h4>${isUnknown ? '⚠️ Prodotto non riconosciuto' : '✏️ Modifica informazioni'}</h4>
            ${isUnknown ? '<p class="edit-unknown-hint">Inserisci il nome e le informazioni del prodotto</p>' : ''}
            <div class="edit-unknown-form">
                <div class="form-group">
                    <label>${t('edit.label_name')}</label>
                    <input type="text" id="edit-action-name" class="form-input" value="${escapeHtml(isUnknown ? '' : currentProduct.name)}" placeholder="Es: Latte intero, Pasta penne..." required>
                </div>
                <div class="form-group">
                    <label>🏪 Marca</label>
                    <input type="text" id="edit-action-brand" class="form-input" value="${escapeHtml(currentProduct.brand || '')}" placeholder="Es: Barilla, Mulino Bianco...">
                </div>
                <div class="form-group">
                    <label>📂 Categoria</label>
                    <select id="edit-action-category" class="form-input">
                        <option value="">-- Seleziona --</option>
                        ${categoryOptions}
                    </select>
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
    checkInventoryForProduct(currentProduct.id).then(inventoryItems => {
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
            `;
            // Secondary: catalog edit link below the buttons (one instance only)
            let catalogLink = document.getElementById('catalog-edit-link');
            if (!catalogLink) {
                catalogLink = document.createElement('div');
                catalogLink.id = 'catalog-edit-link';
                catalogLink.style.cssText = 'text-align:center;margin-top:6px';
                btnsContainer.after(catalogLink);
            }
            catalogLink.innerHTML = `<button type="button" class="btn-link-small" onclick="editProductFromAction()">⚙️ Modifica scheda prodotto (nome, marca, categoria…)</button>`;
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
    });
    
    // Update back button: go back to shopping if came from shopping list scan
    const backBtn = document.getElementById('action-back-btn');
    if (backBtn) backBtn.onclick = _spesaScanTarget ? () => { _spesaScanTarget = null; showPage('shopping'); } : () => showPage('scan');

    // Show "shopping target" banner if we came from the shopping list
    const banner = document.getElementById('shopping-scan-target-banner');
    if (banner && _spesaScanTarget) {
        const targetName = _spesaScanTarget.name;
        banner.style.display = 'block';
        banner.innerHTML = `
            <div class="shopping-scan-target-info">
                <span class="stb-label">🛒 ${t('shopping.scan_target_label')}</span>
                <span class="stb-name">${escapeHtml(targetName)}</span>
            </div>
            <div class="shopping-scan-target-actions">
                <button class="btn btn-success stb-btn" onclick="confirmShoppingItemFound()">✅ ${t('shopping.scan_target_found')}</button>
                <button class="btn btn-secondary stb-btn" onclick="_spesaScanTarget=null; document.getElementById('shopping-scan-target-banner').style.display='none'; document.getElementById('action-back-btn').onclick=()=>showPage('scan')">✕ ${t('btn.cancel')}</button>
            </div>
        `;
    } else if (banner) {
        banner.style.display = 'none';
    }

    showPage('action');
}

// Check if product exists in inventory
async function checkInventoryForProduct(productId) {
    try {
        const data = await api('inventory_list');
        return (data.inventory || []).filter(i => i.product_id == productId);
    } catch(e) {
        return [];
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
    const cat = mapToLocalCategory(currentProduct.category, currentProduct.name);
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
        showToast('Nessuna voce di inventario trovata', 'error');
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
            <h3>✏️ Quale modifica?</h3>
            <button class="modal-close" onclick="closeModal()">✕</button>
        </div>
        <p style="font-size:0.9rem;color:var(--text-muted);margin:0 0 12px">Scegli la posizione da modificare:</p>
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
            <h3>Modifica ${escapeHtml(item.name || currentProduct.name)}</h3>
            <button class="modal-close" onclick="closeModal()">✕</button>
        </div>
        <form class="form" onsubmit="submitActionEditInventory(event, ${inventoryId}, ${item.product_id})">
            <div class="form-group">
                <label>${t('add.quantity_label')}</label>
                <div class="qty-control">
                    <button type="button" class="qty-btn" onclick="adjustQty('action-edit-qty', -1)">−</button>
                    <input type="number" id="action-edit-qty" value="${item.quantity}" min="0" step="any" class="qty-input">
                    <button type="button" class="qty-btn" onclick="adjustQty('action-edit-qty', 1)">+</button>
                </div>
            </div>
            <div class="form-group">
                <label>${t('product.unit_label')}</label>
                <select id="action-edit-unit" class="form-input" onchange="onActionEditUnitChange()">
                    ${['pz','g','ml','conf'].map(u => `<option value="${u}" ${(item.unit||'pz') === u ? 'selected' : ''}>${u === 'pz' ? 'pz (pezzi)' : u === 'g' ? 'g (grammi)' : u === 'ml' ? 'ml (millilitri)' : u === 'conf' ? 'conf (confezioni)' : u}</option>`).join('')}
                </select>
            </div>
            <div class="form-group" id="action-edit-conf-group" style="display:${isConf ? 'block' : 'none'}">
                <label>📦 Ogni confezione contiene:</label>
                <div class="conf-size-inputs">
                    <input type="number" id="action-edit-conf-size" class="form-input conf-size-input" min="1" step="any" value="${confSizeVal}" placeholder="es. 300">
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
}

function onActionEditUnitChange() {
    const unit = document.getElementById('action-edit-unit').value;
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
        vacuum_sealed: document.getElementById('action-edit-vacuum')?.checked ? 1 : 0 };
    
    if (unit === 'conf') {
        payload.package_unit = document.getElementById('action-edit-conf-unit')?.value || '';
        payload.package_size = parseFloat(document.getElementById('action-edit-conf-size')?.value) || 0;
    } else {
        payload.package_unit = '';
        payload.package_size = 0;
    }
    
    await api('inventory_update', {}, 'POST', payload);
    closeModal();
    showToast('Aggiornato!', 'success');
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
            showToast('Prodotto non nell\'inventario', 'error');
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
                    <div class="qty-control">
                        <button type="button" class="qty-btn" onclick="adjustQty('throw-quantity', -1)">−</button>
                        <input type="number" id="throw-quantity" value="1" min="0.1" step="any" class="qty-input">
                        <button type="button" class="qty-btn" onclick="adjustQty('throw-quantity', 1)">+</button>
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

async function throwAll() {
    closeModal();
    showLoading(true);
    try {
        const result = await api('inventory_use', {}, 'POST', {
            product_id: currentProduct.id,
            use_all: true,
            location: '__all__',
            notes: 'Buttato'
        });
        showLoading(false);
        if (result.success) {
            showToast(t('toast.thrown_away', { name: currentProduct.name }), 'success');
            showPage('dashboard');
        } else {
            showToast(result.error || 'Errore', 'error');
        }
    } catch(e) {
        showLoading(false);
        showToast(t('error.connection'), 'error');
    }
}

async function throwPartial() {
    const qty = parseFloat(document.getElementById('throw-quantity').value) || 1;
    const loc = document.getElementById('throw-location').value;
    closeModal();
    showLoading(true);
    try {
        const result = await api('inventory_use', {}, 'POST', {
            product_id: currentProduct.id,
            quantity: qty,
            location: loc,
            notes: 'Buttato'
        });
        showLoading(false);
        if (result.success) {
            showToast(t('toast.thrown_away_partial', { qty, unit: currentProduct.unit || 'pz', name: currentProduct.name }), 'success');
            showPage('dashboard');
        } else {
            showToast(result.error || 'Errore', 'error');
        }
    } catch(e) {
        showLoading(false);
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
            notes: currentProduct.notes || '',
        });
        showLoading(false);
        if (result.success) {
            // Update current product in memory
            currentProduct.name = name;
            currentProduct.brand = brand;
            if (category) currentProduct.category = category;
            showToast(t('toast.product_updated'), 'success');
            // Refresh the action page with updated data
            showProductAction();
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
    // After rendering, fetch history-based expiry prediction
    if (currentProduct && currentProduct.id) {
        _fetchExpiryHistoryAndUpdate(currentProduct.id);
    }
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
    if (!currentProduct) return;
    const loc = document.getElementById('add-location')?.value || '';
    const isVacuum = document.getElementById('add-vacuum-sealed')?.checked;
    
    const baseDays = window._historyExpiryDays ?? estimateExpiryDays(currentProduct, loc);
    let days = isVacuum ? getVacuumExpiryDays(baseDays) : baseDays;
    
    window._addBaseExpiryDays = baseDays;
    
    const newDate = addDays(days);
    const newLabel = formatEstimatedExpiry(days);
    
    let suffix = '';
    if (window._historyExpiryDays) suffix = ' (da storico)';
    else if (loc === 'freezer' && isVacuum) suffix = ' ' + t('add.suffix_freezer_vacuum');
    else if (loc === 'freezer') suffix = ' (freezer)';
    else if (isVacuum) suffix = ' (sotto vuoto)';
    
    const expiryInput = document.getElementById('add-expiry');
    const estimateEl = document.querySelector('.expiry-estimate-label');
    const dateEl = document.querySelector('.expiry-estimate-date');
    if (expiryInput) expiryInput.value = newDate;
    if (estimateEl) estimateEl.innerHTML = `${t('add.estimated_expiry')} <strong>${newLabel}${suffix}</strong>`;
    if (dateEl) dateEl.textContent = formatDate(newDate);
}

async function _fetchExpiryHistoryAndUpdate(productId) {
    try {
        const res = await fetch(`api/index.php?action=expiry_history&product_id=${encodeURIComponent(productId)}`);
        const data = await res.json();
        if (data.avg_days && data.avg_days > 0 && data.count >= 1) {
            window._historyExpiryDays = data.avg_days;
            window._historyExpiryCount = data.count;
            // Update the displayed date and label
            const loc = document.getElementById('add-location')?.value || '';
            const isVacuum = document.getElementById('add-vacuum-sealed')?.checked;
            let days = isVacuum ? getVacuumExpiryDays(data.avg_days) : data.avg_days;
            const newDate = addDays(days);
            const newLabel = formatEstimatedExpiry(days);
            const suffix = ` <span class="history-badge" title="Media da ${data.count} insertiment${data.count === 1 ? 'o' : 'i'} precedent${data.count === 1 ? 'e' : 'i'}">📊 storico</span>`;
            const expiryInput = document.getElementById('add-expiry');
            const estimateEl = document.querySelector('.expiry-estimate-label');
            const dateEl = document.querySelector('.expiry-estimate-date');
            if (expiryInput) expiryInput.value = newDate;
            if (estimateEl) estimateEl.innerHTML = `${t('add.estimated_expiry')} <strong>${newLabel}${suffix}</strong>`;
            if (dateEl) dateEl.textContent = formatDate(newDate);
            window._addBaseExpiryDays = data.avg_days;
        }
    } catch (e) {
        // silently fall back to rule-based estimate
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
        if (window._historyExpiryDays) suffix = ` <span class="history-badge" title="Media da ${window._historyExpiryCount} inserimento/i precedente/i">📊 storico</span>`;
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
        // Show multi-batch section only in "new" mode (and only for conf unit)
        const mbSection = document.getElementById('multi-batch-section');
        if (mbSection) mbSection.style.display = (document.getElementById('add-unit')?.value === 'conf') ? 'block' : 'none';
    } else {
        detailDiv.innerHTML = `
            <div class="form-group">
                <label>📅 Quando scade?</label>
                <div class="expiry-input-row">
                    <input type="date" id="add-expiry" class="form-input" value="">
                    <button type="button" class="btn btn-accent btn-scan-expiry" onclick="scanExpiryWithAI()" title="${t('add.scan_expiry_title')}">📷</button>
                </div>
                <p class="form-hint">Inserisci la data di scadenza o scansionala</p>
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

async function submitAdd(e) {
    e.preventDefault();
    showLoading(true);
    
    try {
        const selectedUnit = document.getElementById('add-unit').value;
        const productUnit = currentProduct.unit || 'pz';
        
        // Validate conf fields
        if (selectedUnit === 'conf') {
            const confSize = parseFloat(document.getElementById('add-conf-size')?.value);
            if (!confSize || confSize <= 0) {
                showLoading(false);
                showToast('Specifica il contenuto di ogni confezione', 'error');
                document.getElementById('add-conf-size')?.focus();
                return;
            }
        }
        
        const result = await api('inventory_add', {}, 'POST', {
            product_id: currentProduct.id,
            quantity: parseFloat(document.getElementById('add-quantity').value) || 1,
            location: document.getElementById('add-location').value,
            expiry_date: document.getElementById('add-expiry').value || null,
            unit: selectedUnit !== productUnit ? selectedUnit : null,
            package_unit: selectedUnit === 'conf' ? (document.getElementById('add-conf-unit')?.value || null) : null,
            package_size: selectedUnit === 'conf' ? (parseFloat(document.getElementById('add-conf-size')?.value) || null) : null,
            vacuum_sealed: document.getElementById('add-vacuum-sealed')?.checked ? 1 : 0,
        });
        
        showLoading(false);
        if (result.success) {
            // Build quantity info for toast
            let qtyInfo = '';
            if (result.total_qty) {
                const u = result.unit || 'pz';
                const unitLabels = { 'pz': 'pz', 'g': 'g', 'ml': 'ml', 'conf': 'conf' };
                const uLabel = unitLabels[u] || u;
                if (u === 'conf' && result.package_unit && result.default_quantity > 0) {
                    const pkgLabel = unitLabels[result.package_unit] || result.package_unit;
                    qtyInfo = ` (totale: ${result.total_qty} ${uLabel} da ${result.default_quantity}${pkgLabel})`;
                } else {
                    qtyInfo = ` (totale: ${result.total_qty} ${uLabel})`;
                }
            }
            showToast(t('add.product_added').replace('{name}', currentProduct.name).replace('{qty}', qtyInfo), 'success');
            if (result.removed_from_bring) {
                setTimeout(() => showToast(t('toast.removed_from_shopping'), 'info'), 1500);
            } else if (shoppingItems.length > 0 && shoppingListUUID) {
                // PHP matching may have missed the item (custom name / no catalog match) —
                // try a client-side fuzzy remove using the already-loaded shoppingItems
                const match = _findSimilarItem(currentProduct.name, shoppingItems);
                if (match) {
                    api('bring_remove', {}, 'POST', {
                        name: match.name,
                        rawName: match.rawName || '',
                        listUUID: shoppingListUUID
                    }).then(r => {
                        if (r && r.success) {
                            shoppingItems = shoppingItems.filter(i => i !== match);
                            setTimeout(() => showToast(t('toast.removed_from_shopping'), 'info'), 1500);
                        }
                    }).catch(() => {});
                }
            }
            if (!spesaModeAfterAdd()) showPage('dashboard');

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
            showToast(result.error || 'Errore', 'error');
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
}

function renderUsePreview() {
    const catIcon = CATEGORY_ICONS[mapToLocalCategory(currentProduct?.category, currentProduct?.name)] || '📦';
    document.getElementById('use-product-preview').innerHTML = `
        ${currentProduct?.image_url ?
            `<img src="${escapeHtml(currentProduct.image_url)}" alt="">` :
            `<span style="font-size:2rem">${catIcon}</span>`
        }
        <div class="product-preview-info">
            <h3>${escapeHtml(currentProduct?.name || '')}</h3>
            <p>${currentProduct?.brand ? escapeHtml(currentProduct.brand) : ''}</p>
        </div>
    `;
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

    hintEl.innerHTML = t('use.expiry_warning').replace('{loc}', locLabel).replace('{date}', `<strong>${dateStr}</strong>`).replace('{when}', whenStr);
    hintEl.style.display = 'block';
}

function _isOpenedInventoryItem(item) {
    const q = parseFloat(item.quantity);
    const dq = parseFloat(item.default_quantity) || 0;
    if (item.unit === 'conf' && dq > 0) return q !== Math.floor(q);
    if (dq > 0) return Math.abs(q - Math.round(q / dq) * dq) > dq * 0.02;
    return false;
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
            infoEl.innerHTML = t('use.not_in_inventory');
            unitSwitch.style.display = 'none';
            _useConfMode = null;
            document.getElementById('use-expiry-hint').style.display = 'none';
            return;
        }

        // ── Suggerisci quale confezione usare per prima ──────────────────
        _renderUseExpiryHint(items);
        // ─────────────────────────────────────────────────────────────────

        // Auto-select the location with an opened package first (use from opened before sealed)
        const openedItem = items.find(_isOpenedInventoryItem);
        const firstLoc = openedItem ? openedItem.location : items[0].location;
        
        // Build location buttons only for locations where the product exists
        const productLocations = [...new Set(items.map(i => i.location))];
        const locSelector = document.getElementById('use-location-selector');

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
            
            // Default to sub-unit mode
            switchUseUnit('sub');
            // Trigger a live-box refresh with the latest reading if on scale
            if (_scaleLatestWeight) _scaleAutoFillUse(_scaleLatestWeight);
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
            qtyInput.value = 1;
            qtyInput.step = 'any';
            qtyInput.min = '0.01';
            document.getElementById('use-partial-hint').textContent = t('use.partial_hint');

            // Fraction buttons for pz unit
            const existingFrac = document.getElementById('pz-fraction-btns');
            if (existingFrac) existingFrac.remove();
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

    if (mode === 'sub') {
        subBtn.classList.add('active');
        confBtn.classList.remove('active');
        _useConfMode._activeUnit = 'sub';
        const step = getSubUnitStep(_useConfMode.packageUnit);
        qtyInput.value = step;
        qtyInput.step = step;
        qtyInput.min = step;
        hint.textContent = t('recipes.quantity_in_total', { unit: _useConfMode.subLabel, total: `${Math.round(_useConfMode.totalSub)}${_useConfMode.subLabel}` });
    } else {
        confBtn.classList.add('active');
        subBtn.classList.remove('active');
        _useConfMode._activeUnit = 'conf';
        qtyInput.value = 1;
        qtyInput.step = 0.5;
        qtyInput.min = 0.5;
        hint.textContent = t('recipes.packs_of_have', { size: `${_useConfMode.packageSize}${_useConfMode.subLabel}`, count: _useConfMode.totalConf.toFixed(1) });
    }
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
    input.value = Math.round(val * 1000) / 1000;
    // Sync fraction button highlight if visible
    const newVal = parseFloat(input.value);
    document.querySelectorAll('#pz-fraction-btns .frac-btn').forEach(b => {
        b.classList.toggle('active', parseFloat(b.dataset.frac) === newVal);
    });
}

function selectUseLocation(btn, loc) {
    btn.parentElement.querySelectorAll('.loc-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('use-location').value = loc;
}

// ── PREFERRED USE LOCATION ───────────────────────────────────────────────
// After 3+ consistent choices from the same location for a product,
// auto-selects it and hides the location picker (user can still tap "cambia").
const _PREF_LOC_KEY = '_prefUseLoc';
const _PREF_LOC_NEEDED = 3; // choices needed to confirm a preference

function _getPrefLocHistory(productId) {
    try {
        const all = JSON.parse(localStorage.getItem(_PREF_LOC_KEY) || '{}');
        return all[String(productId)] || [];
    } catch { return []; }
}

function _recordUseLocationChoice(productId, loc) {
    try {
        const all = JSON.parse(localStorage.getItem(_PREF_LOC_KEY) || '{}');
        const key = String(productId);
        const hist = all[key] || [];
        hist.push(loc);
        if (hist.length > 8) hist.splice(0, hist.length - 8); // keep last 8
        all[key] = hist;
        localStorage.setItem(_PREF_LOC_KEY, JSON.stringify(all));
    } catch { }
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
    if (unit === 'conf') return totalRemaining < 1; // only warn when less than 1 full pack remains (opened/partial)
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
    const exact = smartItems.find(sd => sd.name.toLowerCase() === bLower);
    if (exact) return exact;
    const bTokens = _nameTokens(bringName);
    if (bTokens.length === 0) return null;
    const bFirst = bTokens[0];
    // Rule 2: first token match
    const firstMatch = smartItems.find(sd => {
        const sdTokens = _nameTokens(sd.name);
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

function showLowStockBringPrompt(result, afterCallback) {
    const name = result.product_name || currentProduct?.name || '';
    // Generic shopping name (e.g. "Affettato" for "Mortadella IGP"). Falls back to
    // the specific name when shopping_name is not set (older API call), so behaviour
    // is unchanged for legacy callers.
    const shoppingName = result.product_shopping_name || name;
    const unit = result.product_unit || currentProduct?.unit || 'pz';
    const defaultQty = result.product_default_qty || parseFloat(currentProduct?.default_quantity) || 0;
    const totalRemaining = result.total_remaining;
    
    // ── Fully depleted: no need to ask — backend already added to Bring! ──
    // Skip the modal entirely and proceed to the next step (e.g. move modal).
    if (totalRemaining <= 0) {
        // Backend auto-adds to Bring! when fully depleted. If it failed (Bring not
        // configured, or product already on list), silently attempt it from JS.
        if (!result.added_to_bring && shoppingName) {
            // Fire-and-forget — don't block the callback
            // Use generic shopping name; specific name goes into specification.
            const spec = shoppingName !== name ? name + (result.product_brand ? ` · ${result.product_brand}` : '') : '';
            (async () => {
                try {
                    const payload = { items: [{ name: shoppingName, specification: spec }] };
                    if (shoppingListUUID) payload.listUUID = shoppingListUUID;
                    const data = await api('bring_add', {}, 'POST', payload);
                    if (data.success && data.added > 0) {
                        showToast('🛒 Prodotto finito → aggiunto a Bring!', 'info');
                    }
                } catch(_e) { /* silent */ }
            })();
        }
        if (afterCallback) afterCallback();
        return;
    }
    
    if (!isLowStock(totalRemaining, unit, defaultQty)) {
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
        const data = await api('bring_add', {}, 'POST', payload);
        if (data.success && data.added > 0) {
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

function clearMoveModalTimer() {
    if (_moveModalTimer) { clearTimeout(_moveModalTimer); _moveModalTimer = null; }
    if (_moveModalRAF) { cancelAnimationFrame(_moveModalRAF); _moveModalRAF = null; }
}

function startMoveModalCountdown(btnId, onExpire) {
    clearMoveModalTimer();
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

function showMoveAfterUseModal(product, fromLoc, remaining, openedId) {
    const otherLocs = Object.entries(LOCATIONS).filter(([k]) => k !== fromLoc);
    const locButtons = otherLocs.map(([k, v]) =>
        `<button type="button" class="loc-btn" onclick="clearMoveModalTimer();confirmMoveAfterUse(${product.id}, '${fromLoc}', '${k}', ${openedId || 0})">${v.icon} ${v.label}</button>`
    ).join('');
    const wasVacuum = !!product.vacuum_sealed;
    const vacuumRow = wasVacuum ? `
        <label style="display:flex;align-items:center;gap:8px;margin-top:12px;cursor:pointer">
            <input type="checkbox" id="move-vacuum-check" checked>
            <span>${t('move.vacuum_restore')}</span>
        </label>` : '';
    document.getElementById('modal-content').innerHTML = `
        <div class="modal-header">
            <h3>${t('move.title')}</h3>
            <button class="modal-close" onclick="clearMoveModalTimer();closeModal();showPage('dashboard')">✕</button>
        </div>
        <div style="padding:0 16px 16px">
            <p style="margin-bottom:12px">${t('move.question').replace('{thing}', openedId ? t('move.thing_opened') : t('move.thing_rest')).replace('{name}', `<strong>${escapeHtml(product.name)}</strong>`)}</p>
            <div class="location-selector">${locButtons}</div>
            ${vacuumRow}
            <button type="button" id="btn-move-stay" class="btn btn-secondary full-width move-countdown-btn" style="margin-top:12px" onclick="clearMoveModalTimer();closeModal();showPage('dashboard')">${t('move.stay_btn').replace('{location}', LOCATIONS[fromLoc]?.label || fromLoc)}</button>
        </div>
    `;
    document.getElementById('modal-overlay').style.display = 'flex';
    startMoveModalCountdown('btn-move-stay', () => { closeModal(); showPage('dashboard'); });
}

async function confirmMoveAfterUse(productId, fromLoc, toLoc, openedId) {
    clearMoveModalTimer();
    const newVacuum = document.getElementById('move-vacuum-check')?.checked ? 1 : 0;
    closeModal();
    showLoading(true);
    try {
        if (openedId) {
            // Move only the specific opened row — use opened shelf life
            const product = { name: currentProduct?.name || '', category: currentProduct?.category || '' };
            let days = estimateOpenedExpiryDays(product, toLoc);
            await api('inventory_update', {}, 'POST', {
                id: openedId,
                location: toLoc,
                expiry_date: addDays(days),
                product_id: productId,
                vacuum_sealed: newVacuum,
            });
            showToast(t('move.moved_toast').replace('{location}', LOCATIONS[toLoc]?.label || toLoc), 'success');
        } else {
            // Legacy: move whatever is at fromLoc
            const data = await api('inventory_list');
            const item = (data.inventory || []).find(i => i.product_id == productId && i.location === fromLoc && parseFloat(i.quantity) > 0);
            if (item) {
                const product = { name: item.name || '', category: item.category || '' };
                let days = estimateExpiryDays(product, toLoc);
                if (newVacuum) days = getVacuumExpiryDays(days);
                await api('inventory_update', {}, 'POST', {
                    id: item.id,
                    location: toLoc,
                    expiry_date: addDays(days),
                    product_id: productId,
                    vacuum_sealed: newVacuum,
                });
                showToast(`📦 Spostato in ${LOCATIONS[toLoc]?.label || toLoc}`, 'success');
            }
        }
    } catch (e) {
        console.error('Move error:', e);
    }
    showLoading(false);
    showPage('dashboard');
}

async function submitUseAll() {
    showLoading(true);
    try {
        const currentLoc = document.getElementById('use-location').value;
        const items = _useCurrentItems.filter(i => parseFloat(i.quantity) > 0);

        const openedAtCurrentLoc = items.find(i => i.location === currentLoc && _isOpenedInventoryItem(i));
        const allOpened = items.filter(_isOpenedInventoryItem);

        let useLocation;

        if (openedAtCurrentLoc) {
            // Opened package at the currently selected location → finish only the opened item.
            // The PHP backend fetches fractional (opened) rows first, so use_all on a specific
            // location will clear the opened row and leave sealed packages untouched.
            useLocation = currentLoc;
        } else if (allOpened.length === 1) {
            // One opened package somewhere else → almost certainly this is what the user means
            useLocation = allOpened[0].location;
        } else if (allOpened.length > 1) {
            // Multiple opened packages at different locations → ask the user
            showLoading(false);
            _showUseAllDisambiguation(allOpened, items);
            return;
        } else {
            // No opened packages anywhere → finish everything (original behaviour)
            useLocation = '__all__';
        }

        const isOpenedFinish = useLocation !== '__all__' && items.some(
            i => i.location === useLocation && _isOpenedInventoryItem(i)
        );

        const result = await api('inventory_use', {}, 'POST', {
            product_id: currentProduct.id,
            use_all: true,
            location: useLocation,
        });
        showLoading(false);
        if (result.success) {
            const toastMsg = isOpenedFinish
                ? `🔓 ${t('use.toast_opened_finished').replace('{name}', currentProduct.name)}`
                : `📤 ${currentProduct.name} terminato!`;
            showToast(toastMsg, 'success');
            if (result.added_to_bring) {
                setTimeout(() => showToast(t('use.toast_bring'), 'info'), 1500);
            }
            // Check low stock (product may exist at other locations)
            showLowStockBringPrompt(result, () => showPage('dashboard'));
        } else {
            showToast(result.error || 'Errore', 'error');
        }
    } catch (err) {
        showLoading(false);
        showToast(t('error.connection'), 'error');
    }
}

/**
 * Show a modal asking which opened package to mark as finished.
 * Called when multiple opened packages exist across different locations.
 */
function _showUseAllDisambiguation(openedItems, allItems) {
    const contentEl = document.getElementById('modal-content');
    const locButtons = openedItems.map(item => {
        const locInfo = LOCATIONS[item.location] || { icon: '📦', label: item.location };
        const qtyStr = formatQuantity(parseFloat(item.quantity), item.unit, item.default_quantity, item.package_unit);
        return `<button class="btn btn-warning full-width" style="justify-content:flex-start;gap:10px;text-align:left;margin-bottom:8px"
            onclick="closeModal(); _submitUseAllAt('${item.location}', true)">
            <span style="font-size:1.3rem">${locInfo.icon}</span>
            <span><strong>${locInfo.label}</strong> — 🔓 ${t('use.opened_badge')}<br>
            <small style="opacity:0.8">${qtyStr}</small></span>
        </button>`;
    }).join('');

    // Option to finish everything
    const totalQty = allItems.reduce((s, i) => s + parseFloat(i.quantity), 0);
    const unit = allItems[0]?.unit || 'pz';
    const defaultQty = allItems[0]?.default_quantity;
    const pkgUnit = allItems[0]?.package_unit;
    const totalStr = formatQuantity(totalQty, unit, defaultQty, pkgUnit);

    contentEl.innerHTML = `
        <div class="modal-header">
            <h3>${t('use.use_all')}</h3>
            <button class="modal-close" onclick="closeModal()">✕</button>
        </div>
        <p style="font-size:0.9rem;color:var(--text-muted);margin:0 0 14px">${t('use.disambiguation_hint')}</p>
        ${locButtons}
        <button class="btn btn-danger full-width" style="margin-top:4px"
            onclick="closeModal(); _submitUseAllAt('__all__', false)">
            🗑️ ${t('use.disambiguation_all').replace('{qty}', totalStr)}
        </button>
    `;
    document.getElementById('modal-overlay').style.display = 'flex';
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
            showToast(result.error || 'Errore', 'error');
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
    // Stop timers but KEEP _scaleLastConfirmedGrams: this prevents the scale from
    // re-triggering another auto-submit while the product is still on the plate.
    // (Calling _cancelScaleAutoConfirm(false) would reset the sentinel to null,
    //  allowing the same weight to start a new 10-second cycle immediately.)
    _cancelScaleTimersOnly();
    _scaleStabilityVal = null; // reset sentinel so a new DIFFERENT weight restarts correctly
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
                setTimeout(() => showToast('🛒 Prodotto finito → aggiunto a Bring!', 'info'), 1500);
            }
            // If there's remaining quantity, offer to move to another location
            const usedFrom = document.getElementById('use-location').value;
            _recordUseLocationChoice(currentProduct.id, usedFrom); // track for preferred-location feature
            const moveCallback = result.remaining > 0
                ? () => showMoveAfterUseModal(currentProduct, usedFrom, result.remaining, result.opened_id)
                : () => showPage('dashboard');
            // Check low stock → Bring! prompt
            showLowStockBringPrompt(result, moveCallback);
        } else if (result.duplicate) {
            // Silently ignore: this was a scale double-trigger, not a real error
        } else {
            showToast(result.error || 'Errore', 'error');
        }
    } catch (err) {
        showLoading(false);
        _useSubmitting = false;
        showToast(t('error.connection'), 'error');
    }
}

// ===== AI IDENTIFICATION =====
async function captureForAI() {
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
        showToast('Impossibile accedere alla fotocamera', 'error');
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
                resultDiv.innerHTML = `<p style="color:var(--warning)">⚠️ Chiave API Gemini non configurata.<br><small>Aggiungi GEMINI_API_KEY nel file .env sul server.</small></p>`;
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
        html += `<button class="btn btn-secondary full-width" onclick="saveAIProductDirect()">🆕 Non è nessuno di questi — salva come nuovo</button>`;
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
            if (p.quantity_info) notesParts.push(`Peso: ${p.quantity_info}`);
            if (p.nutriscore) notesParts.push(`Nutriscore: ${p.nutriscore.toUpperCase()}`);
            if (p.nova_group) notesParts.push(`NOVA: ${p.nova_group}`);
            if (p.ecoscore) notesParts.push(`Ecoscore: ${p.ecoscore.toUpperCase()}`);
            if (p.origin) notesParts.push(`Origine: ${p.origin}`);

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
            category: id.category || '',
            unit: 'pz',
            default_quantity: 1,
        });

        if (result.success || result.id) {
            currentProduct = { id: result.id, name: id.name, brand: id.brand || '', category: id.category || '', unit: 'pz', default_quantity: 1 };
            showLoading(false);
            showToast('Prodotto salvato!', 'success');
            showProductAction();
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
            html += `<p style="font-size:0.82rem;color:var(--text-muted);margin-bottom:6px">Seleziona la variante esatta o usa i dati AI:</p>`;
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
    showToast('✅ Campi compilati dall\'AI', 'success');
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
        showToast('Errore', 'error');
    }
}

// ===== SHOPPING LIST (BRING! INTEGRATION) =====
let shoppingListUUID = '';
let shoppingItems = [];
let suggestionItems = [];
let _spesaScanTarget = null; // { name, rawName, idx } when tapping item to scan

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
    document.getElementById('shopping-tabs')?.style.setProperty('display', 'flex');
}

// ===== LOCAL SHOPPING TAGS =====
function getShoppingTags(itemName) {
    try {
        const tags = JSON.parse(localStorage.getItem('shopping_tags') || '{}');
        return tags[itemName.toLowerCase()] || [];
    } catch { return []; }
}

function toggleShoppingTag(itemIdx, tag) {
    const item = shoppingItems[itemIdx];
    if (!item) return;
    const key = item.name.toLowerCase();
    try {
        const tags = JSON.parse(localStorage.getItem('shopping_tags') || '{}');
        const existing = tags[key] || [];
        const pos = existing.indexOf(tag);
        if (pos >= 0) existing.splice(pos, 1);
        else existing.push(tag);
        if (existing.length) tags[key] = existing;
        else delete tags[key];
        localStorage.setItem('shopping_tags', JSON.stringify(tags));

        // Sync urgente/presto tag to Bring specification so it's visible in the Bring app
        if (tag === 'urgente' && shoppingListUUID) {
            const isNowUrgent = existing.includes('urgente');
            const newSpec = isNowUrgent ? t('shopping.urgency_spec_critical') : '';
            api('bring_add', {}, 'POST', {
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
function openScanForItem(idx) {
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
        const r = await api('bring_remove', {}, 'POST', { name, rawName, listUUID: shoppingListUUID });
        if (r.success) {
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

/** Build a Bring specification string that encodes urgency + optional brand. */
function _urgencyToSpec(urgency, brand) {
    const urgencyLabels = { critical: t('shopping.urgency_spec_critical'), high: t('shopping.urgency_spec_high'), medium: '', low: '' };
    const urgLabel = urgencyLabels[urgency] || '';
    if (urgLabel && brand) return `${urgLabel} · ${brand}`;
    if (urgLabel) return urgLabel;
    return brand || '';
}

// ===== BRING! PURCHASED BLOCKLIST =====
// When an item disappears from Bring (user bought it), we block auto-re-add for 4h.
const _BRING_PURCHASED_TTL = 4 * 60 * 60 * 1000; // 4 hours

function _getBringPurchasedBlocklist() {
    try {
        const raw = localStorage.getItem('_bringPurchasedBlocklist');
        const map = raw ? JSON.parse(raw) : {};
        const now = Date.now();
        // Prune expired entries
        let changed = false;
        for (const key of Object.keys(map)) {
            if (now - map[key] > _BRING_PURCHASED_TTL) { delete map[key]; changed = true; }
        }
        if (changed) localStorage.setItem('_bringPurchasedBlocklist', JSON.stringify(map));
        return map;
    } catch(e) { return {}; }
}

function _markBringPurchased(names) {
    const map = _getBringPurchasedBlocklist();
    const now = Date.now();
    for (const n of names) map[n.toLowerCase()] = now;
    localStorage.setItem('_bringPurchasedBlocklist', JSON.stringify(map));
}

function _isBringPurchased(name, urgency) {
    // Critical items: blocked only 30 min (enough to put groceries away).
    // High: 90 min. Others: full 4 h.
    const ttl = urgency === 'critical' ? 30 * 60 * 1000
              : urgency === 'high'     ? 90 * 60 * 1000
              : _BRING_PURCHASED_TTL;
    const map = _getBringPurchasedBlocklist();
    const now = Date.now();
    return Object.keys(map).some(k => {
        const matches = _nameTokens(name)[0] === _nameTokens(k)[0] || k === name.toLowerCase();
        if (!matches) return false;
        return (now - map[k]) < ttl;
    });
}

async function autoAddCriticalItems() {
    // Time-based guard: run at most once every 5 minutes
    const lastRun = parseInt(localStorage.getItem('_autoAddedCriticalTs') || '0');
    if (Date.now() - lastRun < 5 * 60 * 1000) return;
    localStorage.setItem('_autoAddedCriticalTs', String(Date.now()));
    // Auto-add rules:
    // - critical: always
    // - high: always (PHP already applies strict criteria for high urgency)
    // - medium: when running out within 7 days (<1 week) for items used ≥3x/month
    const toAdd = smartShoppingItems.filter(i => {
        const imminentWeek = (i.days_left ?? 999) <= 7 && (i.uses_per_month || 0) >= 3;
        if (i.on_bring) return false;
        // For imminent items, do not honor local "purchased" blocklist too aggressively.
        // If they are predicted to finish within a week, keep Bring aligned automatically.
        if (!imminentWeek && _isBringPurchased(i.name, i.urgency)) return false;
        if (i.urgency === 'critical') return true;
        if (i.urgency === 'high') return true;
        if (i.urgency === 'medium' && (i.days_left ?? 999) <= 7 && (i.uses_per_month || 0) >= 3) return true;
        return false;
    });
    if (toAdd.length === 0) return;
    const itemsToAdd = toAdd.map(i => ({ name: i.name, specification: _urgencyToSpec(i.urgency, i.brand) }));
    try {
        const result = await api('bring_add', {}, 'POST', { items: itemsToAdd, listUUID: shoppingListUUID });
        if (result.success && result.added > 0) {
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
    // Clear all guards so the next run is unconditional
    localStorage.removeItem('_bringPurchasedBlocklist');
    localStorage.removeItem('_autoAddedCriticalTs');
    localStorage.removeItem('_bringCleanupTs');
    logOperation('force_sync_bring', {});
    // Reload everything from scratch
    await loadShoppingList();
    if (btn) { btn.disabled = false; btn.textContent = `🔄 ${t('shopping.force_sync')}`; }
    showToast(`🔄 ${t('shopping.sync_done')}`, 'success');
}

/**
 * One-time cleanup: remove items from Bring! that were auto-added but the algorithm no
 * longer considers relevant.  CONSERVATIVE: only removes items that match a known product
 * in our inventory with current_qty > 0 AND that no longer appear in smart predictions.
 * Items not matching any DB product are left untouched (likely manually added by user).
 */
async function cleanupObsoleteBringItems() {
    // Run at most once every 30 minutes
    const lastCleanup = parseInt(localStorage.getItem('_bringCleanupTs') || '0');
    if (Date.now() - lastCleanup < 30 * 60 * 1000) return;
    localStorage.setItem('_bringCleanupTs', String(Date.now()));
    if (!shoppingItems.length || !smartShoppingItems.length) return;

    // Load live inventory (has actual quantities unlike products_list)
    let invItems = [];
    try {
        const res = await api('inventory_list');
        invItems = res.inventory || [];
    } catch (e) { return; }

    // Build: every significant token of in-stock products → total qty
    // Any-token matching groups product families:
    // 'Passata di pomodoro' + 'Polpa di pomodoro' share 'pomodoro' → same need
    const stockByAnyToken = new Map();
    for (const inv of invItems) {
        const qty = parseFloat(inv.quantity || 0);
        if (qty <= 0) continue;
        for (const tok of _nameTokens(inv.name || '')) {
            stockByAnyToken.set(tok, (stockByAnyToken.get(tok) || 0) + qty);
        }
    }

    // Build: any matching token → smart item (critical/high only)
    const urgentSmartByToken = new Map();
    for (const si of smartShoppingItems) {
        if (si.urgency !== 'critical' && si.urgency !== 'high') continue;
        for (const tok of _nameTokens(si.name)) {
            if (!urgentSmartByToken.has(tok)) urgentSmartByToken.set(tok, si);
        }
    }

    const toRemove = [];
    for (const item of shoppingItems) {
        // Check if any significant token of this Bring item has stock in inventory
        const itemTokens = _nameTokens(item.name);
        const stockQty = itemTokens.reduce((sum, tok) => sum + (stockByAnyToken.get(tok) || 0), 0);

        // No inventory stock for any related product → nothing to remove
        if (stockQty <= 0) continue;

        // Check if smart shopping flags something with a matching token as urgently needed
        const urgSi = itemTokens.map(tok => urgentSmartByToken.get(tok)).find(Boolean);
        if (urgSi) {
            // Smart says something with this root token is urgent.
            // If the flagged product still has qty > 0, it's genuinely running low → keep.
            // If depleted (qty=0) but we have equivalent stock via another token → remove.
            if (urgSi.current_qty > 0) continue;
        }

        toRemove.push(item);
    }

    if (toRemove.length === 0) return;

    let removed = 0;
    const removedNames = [];
    for (const item of toRemove) {
        try {
            const r = await api('bring_remove', {}, 'POST', {
                name: item.name,
                rawName: item.rawName || '',
                listUUID: shoppingListUUID
            });
            if (r.success) { removed++; removedNames.push(item.name); }
        } catch (e) { /* ignore individual failures */ }
    }

    if (removed > 0) {
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
        log.push({ ts: new Date().toISOString(), action, details });
        // Keep last 200 entries
        if (log.length > 200) log.splice(0, log.length - 200);
        localStorage.setItem('_opLog', JSON.stringify(log));
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
    el.textContent = `Aggiornato ${d.getHours().toString().padStart(2,'0')}:${d.getMinutes().toString().padStart(2,'0')}`;
}

function startBgShoppingRefresh() {
    // No-op: server-side cron handles refresh every 5 minutes.
    // The JS fetches pre-computed cache on demand (instant response).
}

async function loadSmartShopping() {
    try {
        const data = await api('smart_shopping');
        if (data.success && data.items && data.items.length > 0) {
            const prevCriticalNames = new Set(
                smartShoppingItems.filter(i => i.urgency === 'critical').map(i => i.name)
            );
            smartShoppingItems = data.items;
            _smartShoppingLastFetch = Date.now();
            // If the set of critical items changed, reset autoAdd/cleanup timers so
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
        } else {
            smartShoppingItems = [];
            _smartShoppingLastFetch = Date.now();
            document.getElementById('smart-shopping-empty').style.display = 'block';
            document.getElementById('smart-shopping-content').style.display = 'none';
        }
    } catch (e) {
        console.error('Smart shopping error:', e);
        smartShoppingItems = [];
    }
    updateShoppingTabCounts();
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
    const isGeneric = shoppingName !== item.name;
    const variants = item.variants || [];

    // Build title line: generic name (and brand only if not grouped)
    let nameLine = `<div class="smart-item-name">${escapeHtml(shoppingName)}`;
    if (!isGeneric && item.brand) nameLine += ` <small class="smart-brand">${escapeHtml(item.brand)}</small>`;
    nameLine += `</div>`;

    // Build subtitle: specific product + brand when grouped, plus any variants
    let specificLine = '';
    if (isGeneric || variants.length > 0) {
        let specifics = [];
        specifics.push(item.name + (item.brand ? ` (${item.brand})` : ''));
        for (const v of variants) {
            specifics.push(v.name + (v.brand ? ` (${v.brand})` : ''));
        }
        specificLine = `<div class="smart-item-specific">${escapeHtml(specifics.join(' · '))}</div>`;
    }

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

        // Usage frequency badge
        let freqBadge = '';
        if (item.use_count >= 8) freqBadge = `<span class="smart-freq-badge freq-high">${t('shopping.freq_high')}</span>`;
        else if (item.use_count >= 4) freqBadge = `<span class="smart-freq-badge freq-med">${t('shopping.freq_regular')}</span>`;
        else if (item.use_count >= 2) freqBadge = `<span class="smart-freq-badge freq-low">${t('shopping.freq_occasional')}</span>`;

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
                        ${freqBadge}${predBadge}${expiryBadge}
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
                showToast(`🔄 ${data.migrated} nomi generalizzati in Bring!`, 'success');
                loadShoppingList(); // refresh the shopping list view
            } else {
                showToast('Tutti i nomi sono già aggiornati', 'info');
            }
        } else {
            if (statusEl) statusEl.textContent = '❌ ' + (data.error || 'Errore');
        }
    } catch(e) {
        if (statusEl) statusEl.textContent = '❌ Errore di connessione';
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
            const shoppingName = item.shopping_name || item.name;
            const isGeneric = shoppingName !== item.name;
            // When generic, use specific product name + brand as the specification
            const spec = isGeneric
                ? (item.name + (item.brand ? ` · ${item.brand}` : ''))
                : _urgencyToSpec(item.urgency, item.brand);
            itemsToAdd.push({
                name: shoppingName,
                specification: spec,
            });
        }
    });

    showLoading(true);
    try {
        const result = await api('bring_add', {}, 'POST', {
            items: itemsToAdd,
            listUUID: shoppingListUUID,
        });
        showLoading(false);
        if (result.success) {
            const msg = result.added > 0
                ? t('shopping.added_to_bring', { n: result.added }) + (result.skipped > 0 ? ` (${t('shopping.added_to_bring_skip', { n: result.skipped })})` : '')
                : t('shopping.all_on_bring');
            showToast(msg, result.added > 0 ? 'success' : 'info');
            // Reload to refresh badges
            loadShoppingList();
        } else {
            showToast(result.error || 'Errore', 'error');
        }
    } catch (e) {
        showLoading(false);
        showToast(t('error.connection'), 'error');
    }
}

// Load just the shopping count for dashboard stat card
async function loadShoppingCount() {
    try {
        const data = await api('bring_list');
        if (data.success && data.purchase) {
            document.getElementById('stat-spesa').textContent = data.purchase.length;
        } else {
            document.getElementById('stat-spesa').textContent = '-';
        }
    } catch {
        document.getElementById('stat-spesa').textContent = '-';
    }
    // Smart urgency badge: use cached data if fresh (< 2 min), else fetch
    if (smartShoppingItems.length > 0 && (Date.now() - _smartShoppingLastFetch) < 2 * 60 * 1000) {
        _updateSmartUrgencyBadge();
    } else {
        try {
            const smart = await api('smart_shopping');
            if (smart.success && smart.items) {
                smartShoppingItems = smart.items;
                _smartShoppingLastFetch = Date.now();
                _updateSmartUrgencyBadge();
            }
        } catch { /* ignore */ }
    }
}

/**
 * Sync local 'urgente' tag from Bring specification.
 * If a Bring item's specification contains 'urgente', ensure the local tag is set.
 * If a Bring item's specification is empty/cleared, remove the local urgente tag
 * UNLESS smart shopping considers it critical (to avoid losing urgency on stale specs).
 */
function _syncTagsFromBringSpec() {
    try {
        const tags = JSON.parse(localStorage.getItem('shopping_tags') || '{}');
        let changed = false;
        for (const item of shoppingItems) {
            const key = item.name.toLowerCase();
            const spec = (item.specification || '').toLowerCase();
            const existing = tags[key] || [];
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
        if (changed) localStorage.setItem('shopping_tags', JSON.stringify(tags));
    } catch (e) { /* ignore */ }
}

/**
 * After smart shopping loads, push urgency specifications to Bring for all matched items.
 * This makes urgency visible in the native Bring app via the item specification field.
 * Only updates if the spec has changed (to avoid unnecessary API calls).
 */
async function autoSyncUrgencySpecs() {
    if (!shoppingListUUID || !smartShoppingItems.length) return;
    const toUpdate = [];
    for (const item of shoppingItems) {
        const smartMatch = _matchBringToSmart(item.name, smartShoppingItems);
        if (!smartMatch) continue;
        const expectedSpec = _urgencyToSpec(smartMatch.urgency, '');
        const currentSpec = (item.specification || '').toLowerCase();
        // Only update if urgency marker changed (don't clobber user-set spec info that isn't urgency)
        const currentHasUrgencyMarker = currentSpec.includes('urgente') || currentSpec.includes('presto');
        const needsUpdate = expectedSpec && !currentHasUrgencyMarker;
        const needsClear = !expectedSpec && currentHasUrgencyMarker;
        // Also update if urgency level changed (e.g. medium→high or high→critical)
        const currentIsHigh = currentSpec.includes('urgente');
        const newIsHigh = (expectedSpec || '').toLowerCase().includes('urgente');
        const urgencyEscalated = expectedSpec && currentHasUrgencyMarker && (currentIsHigh !== newIsHigh);
        if (needsUpdate || needsClear || urgencyEscalated) {
            toUpdate.push({ name: item.name, specification: expectedSpec, update_spec: true });
            // Optimistically update local item so re-render is immediate
            item.specification = expectedSpec;
        }
    }
    if (toUpdate.length === 0) return;
    try {
        await api('bring_add', {}, 'POST', { items: toUpdate, listUUID: shoppingListUUID });
    } catch (e) { /* ignore - sync is best-effort */ }
}

async function loadShoppingList() {
    const statusEl = document.getElementById('bring-status');
    const currentEl = document.getElementById('shopping-current');
    const suggestionsEl = document.getElementById('shopping-suggestions');
    
    statusEl.style.display = 'block';
    statusEl.innerHTML = `<div class="bring-loading"><div class="loading-spinner"></div> ${t('shopping.bring_loading')}</div>`;
    currentEl.style.display = 'none';
    suggestionsEl.style.display = 'none';
    
    try {
        const data = await api('bring_list');
        statusEl.style.display = 'none';
        
        if (!data.success) {
            statusEl.style.display = 'block';
            statusEl.innerHTML = `<div class="bring-error">⚠️ ${escapeHtml(data.error || t('error.bring_connection'))}</div>`;
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
        shoppingItems = newItems;
        
        // Sync urgente local tags from Bring specification (items marked urgent by us or manually)
        _syncTagsFromBringSpec();
        renderShoppingItems();
        currentEl.style.display = 'block';
        
        // Load smart shopping predictions, then re-render to show badges + auto-add critical
        loadSmartShopping().then(() => {
            _syncOnBringFlags();          // sync on_bring against current Bring list before any logic reads it
            _syncTagsFromBringSpec();     // re-sync tags now that smart data is available
            autoSyncUrgencySpecs();       // push urgency specs to Bring for matched items
            renderSmartShopping();        // re-render smart tab with corrected on_bring flags
            updateShoppingTabCounts();    // update tab badges with corrected counts
            autoAddCriticalItems();
            cleanupObsoleteBringItems();
            renderShoppingItems();        // re-render shopping tab with urgency badges
        });

    } catch (err) {
        console.error('Bring! error:', err);
        statusEl.style.display = 'block';
        statusEl.innerHTML = `<div class="bring-error">${t('error.bring_connection')}</div>`;
    }
}

/** Return the spec text to show in the UI, stripping urgency markers (those are shown as badges). */
function _specDisplayText(spec) {
    if (!spec) return '';
    // Strip known urgency prefixes set by _urgencyToSpec (case-insensitive, then trim separator)
    const lower = spec.toLowerCase();
    for (const prefix of ['⚡ urgente', '🟠 presto']) {
        if (lower.startsWith(prefix)) {
            return spec.slice(prefix.length).replace(/^\s*[·\-]\s*/, '').trim();
        }
    }
    return spec;
}

/** Return the spec for price search, stripping urgency markers that would confuse the AI. */
function _cleanSpecForSearch(spec) {
    return _specDisplayText(spec);
}

async function renderShoppingItems() {
    const container = document.getElementById('shopping-items');
    const countEl = document.getElementById('shopping-count');

    countEl.textContent = shoppingItems.length;
    // Update tab count too
    const tabCount = document.getElementById('tab-count-acquisto');
    if (tabCount) tabCount.textContent = shoppingItems.length;
    
    if (shoppingItems.length === 0) {
        container.innerHTML = `<div class="empty-state" style="padding:20px"><div class="empty-state-icon">✅</div><p>${t('shopping.empty')}</p></div>`;
        return;
    }
    
    const s = getSettings();

    // Build section groups, sorted by urgency weight within each section
    const TAG_LABELS = { urgente: t('shopping.tag_urgent'), prio: t('shopping.tag_priority'), check: t('shopping.tag_check') };
    const urgencyMap = {
        critical: { icon: '🔴', label: t('shopping.urgency_critical'), cls: 'badge-critical' },
        high:     { icon: '🟠', label: t('shopping.urgency_high'),     cls: 'badge-high' },
        medium:   { icon: '🟡', label: t('shopping.urgency_medium_short'), cls: 'badge-medium' },
        low:      { icon: '🟢', label: t('shopping.urgency_low_short'), cls: 'badge-low' },
    };

    // Map each item to its section + urgency (strict first-token matching to avoid false positives)
    // Also derive urgency from Bring specification if smart matching fails
    const enriched = shoppingItems.map((item, idx) => {
        const smartData = _matchBringToSmart(item.name, smartShoppingItems);
        let urgency = smartData?.urgency || null;
        // Fallback: read urgency from Bring specification (set by our app when adding)
        if (!urgency && item.specification) {
            const spec = item.specification.toLowerCase();
            if (spec.includes('urgente')) urgency = 'critical';
            else if (spec.includes('presto')) urgency = 'high';
        }
        const sec = getItemSection(item.name);
        return { item, idx, smartData, urgency, sec };
    });

    // Group by section key, preserving SHOPPING_SECTIONS order
    const sectionMap = new Map();
    for (const e of enriched) {
        const key = e.sec.key;
        if (!sectionMap.has(key)) sectionMap.set(key, { sec: e.sec, items: [] });
        sectionMap.get(key).items.push(e);
    }

    // Sort items within each section: by urgency weight desc, then by use_count desc
    for (const [, group] of sectionMap) {
        group.items.sort((a, b) => {
            const wa = URGENCY_WEIGHT[a.urgency] || 0;
            const wb = URGENCY_WEIGHT[b.urgency] || 0;
            if (wb !== wa) return wb - wa;
            return (b.smartData?.use_count || 0) - (a.smartData?.use_count || 0);
        });
    }

    // Render sections in canonical order
    let html = '';
    for (const secDef of SHOPPING_SECTIONS) {
        const group = sectionMap.get(secDef.key);
        if (!group) continue;

        html += `<div class="shopping-section-divider"><span class="sec-icon">${secDef.icon}</span>${secDef.label}</div>`;

        for (const { item, idx, smartData, urgency } of group.items) {
            const catIcon = CATEGORY_ICONS[guessCategoryFromName(item.name)] || '🛒';
            const bgStyle = urgency && URGENCY_BG[urgency] ? ` style="background:${URGENCY_BG[urgency]}"` : '';
            const localTags = getShoppingTags(item.name);

            // Urgency badge
            let urgencyBadge = '';
            if (urgency && urgencyMap[urgency]) {
                const u = urgencyMap[urgency];
                urgencyBadge = `<span class="sinv-badge ${u.cls}">${u.icon} ${u.label}</span>`;
            }

            // Frequency badge
            let freqBadge = '';
            if (smartData && smartData.use_count >= 8) freqBadge = `<span class="sinv-badge badge-freq-high">📈 ${smartData.use_count}x</span>`;
            else if (smartData && smartData.use_count >= 4) freqBadge = `<span class="sinv-badge badge-freq-med">📊 ${smartData.use_count}x</span>`;
            else if (smartData && smartData.use_count >= 2) freqBadge = `<span class="sinv-badge badge-freq-low">📉 ${smartData.use_count}x</span>`;

            const localTagHtml = localTags.map(t =>
                `<span class="sinv-badge badge-local-tag" onclick="event.stopPropagation(); toggleShoppingTag(${idx}, '${t}')">${TAG_LABELS[t] || t} ✕</span>`
            ).join('');

            const tagMenu = `<div class="shopping-tag-menu" onclick="event.stopPropagation()">
                ${Object.entries(TAG_LABELS).map(([k, v]) =>
                    `<button class="sinv-badge badge-tag-add ${localTags.includes(k) ? 'active' : ''}" onclick="toggleShoppingTag(${idx}, '${k}')">${v}</button>`
                ).join('')}
            </div>`;

            html += `
            <div class="shopping-item" id="shop-item-${idx}" onclick="openScanForItem(${idx})" title="${t('shopping.tap_to_scan')}"${bgStyle}>
                <span class="shopping-item-icon">${catIcon}</span>
                <div class="shopping-item-body">
                    <div class="shopping-item-top">
                        <div class="shopping-item-info">
                            <div class="shopping-item-name-row">
                                <span class="shopping-item-name">${escapeHtml(item.name)}</span>
                                <span class="shopping-item-scan-hint">📷</span>
                            </div>
                            ${_specDisplayText(item.specification) ? `<div class="shopping-item-spec">${escapeHtml(_specDisplayText(item.specification))}</div>` : ''}
                            ${(urgencyBadge || freqBadge || localTagHtml) ? `<div class="shopping-item-badges">${urgencyBadge}${freqBadge}${localTagHtml}</div>` : ''}
                        </div>
                        <div class="shopping-item-right" onclick="event.stopPropagation()">
                            <button class="shopping-item-tag-btn" onclick="toggleShoppingTagMenu(this)" title="${t('shopping.tag_title')}">🏷️</button>
                            <button class="shopping-item-remove" onclick="removeBringItem(${idx})" title="${t('shopping.remove_title')}">✕</button>
                        </div>
                    </div>
                    <div class="shopping-tag-menu-container" style="display:none">${tagMenu}</div>
                </div>
            </div>`;
        }
    }

    container.innerHTML = html;
}

function toggleShoppingTagMenu(btn) {
    const container = btn.closest('.shopping-item-body').querySelector('.shopping-tag-menu-container');
    if (!container) return;
    const isOpen = container.style.display !== 'none';
    // Close all other menus first
    document.querySelectorAll('.shopping-tag-menu-container').forEach(c => c.style.display = 'none');
    container.style.display = isOpen ? 'none' : 'block';
}

async function removeBringItem(idx) {
    const item = shoppingItems[idx];
    if (!item) return;
    try {
        const data = await api('bring_remove', {}, 'POST', { 
            name: item.name, 
            rawName: item.rawName || '', 
            listUUID: shoppingListUUID 
        });
        if (data.success) {
            shoppingItems.splice(idx, 1);
            renderShoppingItems();
            showToast(t('toast.removed_from_list_short'), 'success');
            logOperation('bring_manual_remove', { name: item.name });
            // Update dashboard shopping count
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
        const data = await api('bring_suggest', {}, 'POST', {});
        
        btn.disabled = false;
        btn.innerHTML = `🤖 ${t('shopping.suggest_btn').replace('🤖 ', '')}`;
        
        if (!data.success) {
            showToast(data.error || t('shopping.suggest_error'), 'error');
            return;
        }
        
        suggestionItems = (data.suggestions || []).map(s => ({ ...s, selected: true }));
        
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
        const priorityBadge = {
            'alta': `<span class="priority-badge priority-high">${t('shopping.priority_high')}</span>`,
            'media': `<span class="priority-badge priority-med">${t('shopping.priority_medium')}</span>`,
            'bassa': `<span class="priority-badge priority-low">${t('shopping.priority_low')}</span>`,
        }[item.priority] || '';
        
        return `
        <div class="suggestion-item ${item.selected ? 'selected' : ''}" onclick="toggleSuggestion(${idx})">
            <div class="suggestion-check">${item.selected ? '☑️' : '⬜'}</div>
            <span class="shopping-item-icon">${catIcon}</span>
            <div class="suggestion-info">
                <div class="suggestion-name">${escapeHtml(item.name)}${item.specification ? ` <small>(${escapeHtml(item.specification)})</small>` : ''} ${priorityBadge}</div>
                <div class="suggestion-reason">${escapeHtml(item.reason)}</div>
            </div>
        </div>`;
    }).join('');
    
    updateSuggestionActionBtn();
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
        
        const data = await api('bring_add', {}, 'POST', { items, listUUID: shoppingListUUID });
        
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
            // Auto-fill the expiry date
            const expiryInput = document.getElementById('add-expiry');
            if (expiryInput) {
                expiryInput.value = result.expiry_date;
            }
            statusDiv.innerHTML = `<p style="color:var(--success);font-weight:600">✅ Data trovata: ${formatDate(result.expiry_date)}</p>`;
            
            // Close modal after delay
            setTimeout(() => closeExpiryScanner(), 1500);
        } else if (result.error === 'no_api_key') {
            statusDiv.innerHTML = `<p style="color:var(--warning)">⚠️ Chiave API Gemini non configurata.<br><small>Aggiungi GEMINI_API_KEY nel file .env sul server.</small></p>`;
        } else {
            statusDiv.innerHTML = `<p style="color:var(--danger)">❌ Non riesco a leggere la data. ${result.raw_text ? '<br><small>Letto: ' + escapeHtml(result.raw_text) + '</small>' : ''}</p>
                <button class="btn btn-secondary" onclick="retakeExpiry()" style="margin-top:8px">${t('btn.retry')}</button>`;
        }
    } catch (err) {
        console.error('Expiry AI error:', err);
        statusDiv.innerHTML = `<p style="color:var(--danger)">❌ ${t('error.network_retry')}</p>
            <button class="btn btn-secondary" onclick="retakeExpiry()" style="margin-top:8px">${t('btn.retry')}</button>`;
    }
}

function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
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
        document.getElementById('log-list').innerHTML = '<p style="text-align:center;color:var(--text-muted)">Caricamento...</p>';
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
                html += `<div class="log-detail">${typeLabel} ${tx.type !== 'bring' ? (tx.quantity + ' ' + (tx.unit || '')) + ' · ' : ''}${locStr}${notes} · ${timeStr}</div>`;
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
    if (!confirm(t('log.undo_confirm').replace('{action}', action).replace('{name}', name))) return;
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
}
function resetMealPlan() {
    const s = getSettings();
    s.meal_plan = JSON.parse(JSON.stringify(DEFAULT_MEAL_PLAN));
    saveSettingsToStorage(s);
    renderMealPlanEditor();
    showToast(t('meal_plan.reset_success'), 'success');
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
            html += `<div class="recipe-archive-card" onclick="viewArchivedRecipe(${archiveIdx})">`;
            html += `<div class="recipe-archive-card-header">`;
            html += `<span class="recipe-archive-meal">${mealIcon}</span>`;
            html += `<span class="recipe-archive-title">${escapeHtml(r.title)}</span>`;
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
    const entry = _recipeArchiveEntries[idx];
    if (!entry) return;
    _cachedRecipe = { meal: _normalizeMealId(entry.meal), recipe: entry.recipe };
    renderRecipe(entry.recipe);
    document.getElementById('recipe-overlay').style.display = 'flex';
    document.getElementById('recipe-ask').style.display = 'none';
    document.getElementById('recipe-loading').style.display = 'none';
    document.getElementById('recipe-result').style.display = '';
}

let _cachedRecipe = null;
let _generatedTodayTitles = []; // client-side list, robust vs race conditions
let _recipeVariationCount = {}; // { 'pranzo': 0, 'cena': 1, ... }
let _rejectedRecipeIngredients = []; // ingredient names from previously rejected recipes

function openRecipeDialog() {
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

let _recipeUseContext = null; // { idx, productId, btn, qtyNumber }
let _recipeUseConfMode = null;
let _recipeUseNormalUnit = 'pz';

async function useRecipeIngredient(idx, productId, location, qtyNumber, btn, recipeQty) {
    if (btn.disabled) return;
    if (!qtyNumber || qtyNumber <= 0) qtyNumber = 1;
    
    _recipeUseContext = { idx, productId, btn, qtyNumber, recipeQty };
    _recipeUseConfMode = null;
    
    // Fetch inventory to build the modal
    try {
        const data = await api('inventory_list');
        const items = (data.inventory || []).filter(i => i.product_id == productId);
        
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
        let defaultQtyValue = qtyNumber;
        
        if (isConf) {
            const totalConf = items.reduce((s, i) => s + parseFloat(i.quantity), 0);
            const totalSub = totalConf * pkgSize;
            const unitLabels = { 'ml': 'ml', 'g': 'g', 'pz': 'pz' };
            const subLabel = unitLabels[pkgUnit] || pkgUnit;
            _recipeUseConfMode = { packageSize: pkgSize, packageUnit: pkgUnit, totalSub, totalConf, subLabel, _activeUnit: 'sub' };
            
            // qtyNumber from recipe is in sub-units (g, ml)
            const step = getSubUnitStep(pkgUnit);
            defaultQtyValue = qtyNumber;
            
            qtySection = `
                <div class="use-unit-switch" style="display:flex;margin-bottom:8px">
                    <button type="button" class="use-unit-btn active" id="ruse-unit-sub" onclick="switchRecipeUseUnit('sub')">${subLabel}</button>
                    <button type="button" class="use-unit-btn" id="ruse-unit-conf" onclick="switchRecipeUseUnit('conf')">${t('recipes.packs_label')}</button>
                </div>
                <p id="ruse-hint" style="font-size:0.85rem;color:var(--text-muted);margin-bottom:8px">${t('recipes.quantity_in_total').replace('{unit}', subLabel).replace('{total}', Math.round(totalSub) + subLabel)}</p>
                <div class="qty-control">
                    <button type="button" class="qty-btn" onclick="adjustRecipeUseQty(-1)">−</button>
                    <input type="number" id="ruse-quantity" value="${defaultQtyValue}" min="${step}" step="${step}" class="qty-input"
                           oninput="_scaleRecipeAutoFillPaused=true; _cancelScaleAutoConfirm(false); var h=document.getElementById('ruse-scale-hint'); if(h) h.style.display='none';">
                    <button type="button" class="qty-btn" onclick="adjustRecipeUseQty(1)">+</button>
                </div>`;
        } else {
            _recipeUseNormalUnit = unit;
            const unitLabels = { 'pz': 'pz', 'g': 'g', 'ml': 'ml' };
            const unitLabel = unitLabels[unit] || unit;
            const inputMin = '0.1';
            qtySection = `
                <p style="font-size:0.85rem;color:var(--text-muted);margin-bottom:8px">${t('recipes.amount_label')} (${unitLabel}):</p>
                <div class="qty-control">
                    <button type="button" class="qty-btn" onclick="adjustRecipeUseQty(-1)">−</button>
                    <input type="number" id="ruse-quantity" value="${defaultQtyValue}" min="${inputMin}" step="any" class="qty-input"
                           oninput="_scaleRecipeAutoFillPaused=true; _cancelScaleAutoConfirm(false); var h=document.getElementById('ruse-scale-hint'); if(h) h.style.display='none';">
                    <button type="button" class="qty-btn" onclick="adjustRecipeUseQty(1)">+</button>
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
    
    let qty;
    if (useAll) {
        qty = 0; // API handles use_all
    } else {
        qty = parseFloat(document.getElementById('ruse-quantity').value) || 1;
        if (_recipeUseConfMode && _recipeUseConfMode._activeUnit === 'sub') {
            qty = qty / _recipeUseConfMode.packageSize;
        }
    }
    
    closeModal();
    btn.disabled = true;
    btn.textContent = '⏳...';
    
    try {
        const recipeTitle = _cachedRecipe?.recipe?.title || '';
        const result = await api('inventory_use', {}, 'POST', {
            product_id: productId,
            quantity: qty,
            use_all: useAll,
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
                // Persist used state to DB
                saveRecipeToArchive(_cachedRecipe.recipe);
            }
            
            showToast(t('recipes.ingredient_scaled_toast'), 'success');
            if (result.added_to_bring) {
                setTimeout(() => showToast(t('recipes.finished_added_bring_toast'), 'info'), 1500);
            }
            
            // Check low stock → Bring! prompt, then offer move
            const moveCallback = result.remaining > 0
                ? () => setTimeout(() => {
                    const ingData = _cachedRecipe?.recipe?.ingredients?.[_recipeUseContext?.idx];
                    const wasVacuum = !!(ingData?.vacuum_sealed);
                    showRecipeMoveModal(productId, location, result.remaining, result.opened_id, wasVacuum);
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
    const otherLocs = Object.entries(LOCATIONS).filter(([k]) => k !== fromLoc);
    const locButtons = otherLocs.map(([k, v]) =>
        `<button type="button" class="loc-btn" onclick="clearMoveModalTimer();confirmRecipeMove(${productId}, '${fromLoc}', '${k}', ${openedId || 0})">${v.icon} ${v.label}</button>`
    ).join('');
    const vacuumRow = wasVacuum ? `
        <label style="display:flex;align-items:center;gap:8px;margin-top:12px;cursor:pointer">
            <input type="checkbox" id="move-vacuum-check" checked>
            <span>${t('move.vacuum_restore')}</span>
        </label>` : '';
    document.getElementById('modal-content').innerHTML = `
        <div class="modal-header">
            <h3>${t('move.title')}</h3>
            <button class="modal-close" onclick="clearMoveModalTimer();closeModal()">✕</button>
        </div>
        <div style="padding:0 16px 16px">
            <p style="margin-bottom:12px">${t('move.question_short').replace('{thing}', openedId ? t('move.thing_opened') : t('move.thing_rest'))}</p>
            <div class="location-selector">${locButtons}</div>
            ${vacuumRow}
            <button type="button" id="btn-move-stay" class="btn btn-secondary full-width move-countdown-btn" style="margin-top:12px" onclick="clearMoveModalTimer();closeModal()">No, resta in ${LOCATIONS[fromLoc]?.label || fromLoc}</button>
        </div>
    `;
    document.getElementById('modal-overlay').style.display = 'flex';
    startMoveModalCountdown('btn-move-stay', () => { closeModal(); });
}

async function confirmRecipeMove(productId, fromLoc, toLoc, openedId) {
    clearMoveModalTimer();
    const newVacuum = document.getElementById('move-vacuum-check')?.checked ? 1 : 0;
    closeModal();
    try {
        if (openedId) {
            let days = estimateExpiryDays({ name: '', category: '' }, toLoc);
            if (newVacuum) days = getVacuumExpiryDays(days);
            await api('inventory_update', {}, 'POST', {
                id: openedId,
                location: toLoc,
                expiry_date: addDays(days),
                product_id: productId,
                vacuum_sealed: newVacuum,
            });
        } else {
            const data = await api('inventory_list');
            const item = (data.inventory || []).find(i => i.product_id == productId && i.location === fromLoc && parseFloat(i.quantity) > 0);
            if (item) {
                let days = estimateExpiryDays({ name: item.name || '', category: item.category || '' }, toLoc);
                if (newVacuum) days = getVacuumExpiryDays(days);
                await api('inventory_update', {}, 'POST', {
                    id: item.id,
                    location: toLoc,
                    expiry_date: addDays(days),
                    product_id: productId,
                    vacuum_sealed: newVacuum,
                });
            }
        }
        showToast(`📦 Spostato in ${LOCATIONS[toLoc]?.label || toLoc}`, 'success');
    } catch (e) {
        console.error('Recipe move error:', e);
    }
}

function renderRecipe(r) {
    let html = `<h2>${r.title}</h2>`;

    // Meta tags
    html += '<div class="recipe-meta">';
    html += `<span class="recipe-tag">${_mealLabel(r.meal)}</span>`;
    html += `<span class="recipe-tag">👥 ${r.persons} ${t('recipes.persons_short')}</span>`;
    if (r.prep_time) html += `<span class="recipe-tag">🔪 ${r.prep_time}</span>`;
    if (r.cook_time) html += `<span class="recipe-tag">🔥 ${r.cook_time}</span>`;
    if (r.tags) r.tags.forEach(t => { html += `<span class="recipe-tag">${t}</span>`; });
    html += '</div>';

    // Expiry note
    if (r.expiry_note) {
        html += `<div class="recipe-expiry-note">⚠️ ${r.expiry_note}</div>`;
    }

    // Ingredients
    html += `<h3>${t('recipes.ingredients_title')}</h3><ul class="recipe-ingredients">`;
    (r.ingredients || []).forEach((ing, idx) => {
        if (ing.from_pantry && ing.product_id) {
            const qtyNum = ing.qty_number || 0;
            const loc = (ing.location || 'dispensa').replace(/'/g, "\\'");
            const alreadyUsed = ing.used === true;
            html += `<li class="recipe-ingredient${alreadyUsed ? ' recipe-ing-used' : ''}" id="recipe-ing-${idx}">`;
            html += `<span class="recipe-ing-text"><strong>${ing.name}</strong>${ing.brand ? ' <em>(' + ing.brand + ')</em>' : ''}: ${ing.qty} ✅`;
            // Detail line: location + expiry
            let details = [];
            const ingredientLocLabels = Object.fromEntries(Object.entries(LOCATIONS).map(([k,v]) => [k, `${v.icon} ${v.label}`]));
            details.push(ingredientLocLabels[ing.location] || ('📍 ' + ing.location));
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
            html += `</span>`;
            if (alreadyUsed) {
                html += `<button class="btn-use-ingredient btn-used" disabled>${t('cooking.ingredient_used')}</button>`;
            } else {
                html += `<button class="btn-use-ingredient" onclick="useRecipeIngredient(${idx}, ${ing.product_id}, '${loc}', ${qtyNum}, this, '${(ing.qty || '').replace(/'/g, "&apos;")}')" title="${t('cooking.ingredient_deduct_title')}">${t('cooking.ingredient_use_btn')}</button>`;
            }
            html += `</li>`;
        } else {
            const pantryIcon = ing.from_pantry ? ' ✅' : ' 🛒';
            html += `<li class="recipe-ingredient"><span class="recipe-ing-text"><strong>${ing.name}</strong>: ${ing.qty}${pantryIcon}</span></li>`;
        }
    });
    html += '</ul>';

    // Steps
    html += `<h3>${t('recipes.steps_title')}</h3><ol>`;
    (r.steps || []).forEach(step => {
        const cleanStep = step.replace(/^Passo\s*\d+\s*:\s*/i, '');
        html += `<li>${cleanStep}</li>`;
    });
    html += '</ol>';

    // Nutrition note
    if (r.nutrition_note) {
        html += `<p style="color:var(--text-muted);font-size:0.85rem;margin-top:12px">💡 ${r.nutrition_note}</p>`;
    }

    document.getElementById('recipe-content').innerHTML = html;
}

// ===== COOKING MODE =====
let _cookingRecipe = null;
let _cookingStep = 0;
let _cookingTTS = true;
let _cookingVisited = new Set(); // indices of steps already seen

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
        clearAllCookingTimers();
    }
    _cookingTTS = true;
    document.getElementById('cooking-title').textContent = _cookingRecipe.title || '';
    document.getElementById('cooking-tts-btn').textContent = '🔊';
    document.getElementById('cooking-overlay').style.display = 'flex';
    document.body.classList.add('cooking-mode-active');
    try { screen.orientation?.lock('portrait'); } catch (_) { /* ignore */ }
    renderCookingStep();
    if (_cookingTTS) {
        const text = ((_cookingRecipe.steps || [])[_cookingStep] || '').replace(/^Passo\s*\d+\s*[:.]\s*/i, '');
        speakCookingStep(text);
    }
}
function closeCookingMode() {
    document.getElementById('cooking-overlay').style.display = 'none';
    document.body.classList.remove('cooking-mode-active');
    // NOTE: intentionally keep _cookingRecipe, _cookingStep, _cookingVisited
    // so the user can resume from the same step when they reopen
    try { screen.orientation?.unlock(); } catch (_) { /* ignore */ }
}

function restartCookingMode() {
    _cookingStep = 0;
    _cookingVisited = new Set();
    clearAllCookingTimers();
    renderCookingStep();
}

function renderCookingStep() {
    if (!_cookingRecipe) return;
    const steps = _cookingRecipe.steps || [];
    const step = steps[_cookingStep] || '';
    const cleanStep = step.replace(/^Passo\s*\d+\s*[:.]\s*/i, '');
    const total = steps.length;

    // Mark current step as visited
    _cookingVisited.add(_cookingStep);

    document.getElementById('cooking-step-num').textContent = `${_cookingStep + 1} / ${total}`;
    document.getElementById('cooking-step-text').textContent = cleanStep;

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

    // Show ALL unused from_pantry ingredients (not filtered by step text).
    // The AI often uses pronouns ("tagliarla", "aggiungile") instead of the ingredient
    // name, so text-matching would miss them. Better to always show what's available.
    const ings = (_cookingRecipe.ingredients || [])
        .map((ing, idx) => ({ ...ing, _idx: idx }))
        .filter(ing => ing.from_pantry && ing.product_id && ing.used !== true);

    const ingsEl = document.getElementById('cooking-step-ings');
    if (ings.length > 0) {
        const cookingLocLabels = Object.fromEntries(Object.entries(LOCATIONS).map(([k,v]) => [k, `${v.icon} ${v.label}`]));
        ingsEl.innerHTML = ings.map(ing => {
            const loc = (ing.location || 'dispensa').replace(/'/g, "\\'");
            const qtyNum = ing.qty_number || 0;
            // Build info chips: brand, location, expiry
            const chips = [];
            if (ing.brand) chips.push(`<span class="cooking-ing-chip">${escapeHtml(ing.brand)}</span>`);
            const locLabel = cookingLocLabels[ing.location] || (ing.location ? `📍 ${ing.location}` : `${LOCATIONS.dispensa.icon} ${LOCATIONS.dispensa.label}`);
            chips.push(`<span class="cooking-ing-chip">${locLabel}</span>`);
            if (ing.expiry_date) {
                const daysLeft = Math.round((new Date(ing.expiry_date) - new Date()) / 86400000);
                const expClass = daysLeft <= 3 ? 'exp-soon' : daysLeft <= 7 ? 'exp-close' : '';
                chips.push(`<span class="cooking-ing-chip ${expClass}">📅 ${t('cooking.expires_chip').replace('{date}', formatDate(ing.expiry_date))}</span>`);
            }
            return `<div class="cooking-ing-row">
                <div style="flex:1;min-width:0">
                    <span class="cooking-ing-name">📦 <strong>${escapeHtml(ing.name)}</strong>: ${escapeHtml(ing.qty)}</span>
                    <div class="cooking-ing-meta">${chips.join('')}</div>
                </div>
                <button class="cooking-use-btn" onclick="cookingUseIngredient(${ing._idx}, ${ing.product_id}, '${loc}', ${qtyNum}, this)">${t('cooking.ingredient_use_btn')}</button>
            </div>`;
        }).join('');
        ingsEl.style.display = 'flex';
    } else {
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

    // TTS: auto-speak is handled by navigateCookingStep() and startCookingMode() callers.
    // Use replayCookingTTS() to re-read the current step manually ("Rileggi" button).
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

async function _ttsViaProxy(req) {
    // Route through server-side proxy to avoid mixed-content / CORS issues
    return fetch('api/index.php?action=tts_proxy', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
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
    // Use custom TTS endpoint only when explicitly configured; otherwise always use browser TTS.
    // Do NOT gate on s.tts_enabled — the _cookingTTS toggle in cooking mode is the only gate.
    try {
        if (s.tts_engine === 'custom' && s.tts_url) {
            const req = _buildTtsRequest(text, s);
            await _ttsViaProxy(req);
        } else {
            _speakBrowser(text);
        }
    } catch(e) { /* silent — TTS is non-critical */ }
}

function replayCookingTTS() {
    if (!_cookingRecipe) return;
    const steps = _cookingRecipe.steps || [];
    const text = (steps[_cookingStep] || '').replace(/^Passo\s*\d+\s*[:.]\s*/i, '');
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
    if (serverSect) serverSect.style.display = engine === 'server' ? '' : 'none';
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
        sel.innerHTML = '<option value="">— Voce non supportata dal browser —</option>';
        return;
    }

    // Reset to loading state each time (settings page may be re-opened)
    sel.innerHTML = '<option value="">— Caricamento voci… —</option>';

    const populate = () => {
        const voices = window.speechSynthesis.getVoices();
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
                sel.innerHTML = '<option value="">— Nessuna voce disponibile su questo dispositivo —</option>';
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
    window.speechSynthesis.cancel();
    const utt = new SpeechSynthesisUtterance(text);
    utt.rate  = rate;
    utt.pitch = pitch;
    const voices = window.speechSynthesis.getVoices();
    const preferred = voices.find(v => v.name === s.tts_voice);
    if (preferred) {
        utt.voice = preferred;
        utt.lang  = preferred.lang;
    } else {
        utt.lang = _currentLang === 'de' ? 'de-DE' : _currentLang === 'en' ? 'en-US' : 'it-IT';
    }
    window.speechSynthesis.speak(utt);
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
            const s = getSettings();
            s.tts_rate  = parseFloat(document.getElementById('setting-tts-rate')?.value)  || 1;
            s.tts_pitch = parseFloat(document.getElementById('setting-tts-pitch')?.value) || 1;
            saveSettingsToStorage(s);
            _speakBrowser('Test vocale EverShelf. La sintesi vocale funziona correttamente.');
            if (statusEl) { statusEl.style.display = 'block'; statusEl.className = 'settings-status success'; statusEl.textContent = '✅ Riproduzione in corso — controlla l\'audio del dispositivo.'; }
            return;
        }
        if (!window.speechSynthesis) {
            if (statusEl) { statusEl.style.display = 'block'; statusEl.className = 'settings-status error'; statusEl.textContent = '❌ Web Speech API non supportata da questo browser.'; }
            return;
        }
        // Temporarily apply form values for the test
        const s = getSettings();
        const voiceName = document.getElementById('setting-tts-voice')?.value;
        s.tts_voice = voiceName || s.tts_voice;
        s.tts_rate = parseFloat(document.getElementById('setting-tts-rate')?.value) || 1;
        s.tts_pitch = parseFloat(document.getElementById('setting-tts-pitch')?.value) || 1;
        saveSettingsToStorage(s);
        _speakBrowser('Test vocale EverShelf. La sintesi vocale funziona correttamente.');
        if (statusEl) { statusEl.style.display = 'block'; statusEl.className = 'settings-status success'; statusEl.textContent = '✅ Riproduzione in corso — controlla l\'audio del dispositivo.'; }
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
        if (statusEl) { statusEl.style.display = 'block'; statusEl.className = 'settings-status error'; statusEl.textContent = '⚠️ URL endpoint mancante.'; }
        return;
    }
    if (statusEl) { statusEl.style.display = 'block'; statusEl.className = 'settings-status'; statusEl.textContent = '⏳ Invio in corso…'; }
    try {
        const req = _buildTtsRequest('Test vocale EverShelf', formSettings);
        const res = await _ttsViaProxy(req);
        const data = await res.json().catch(() => ({}));
        const httpCode = data.status || res.status;
        if (res.ok && httpCode >= 200 && httpCode < 300) {
            if (statusEl) { statusEl.className = 'settings-status success'; statusEl.textContent = `✅ Risposta ${httpCode} — controlla che l'altoparlante abbia parlato.`; }
        } else {
            const errDetail = data.error || data.body || res.statusText;
            if (statusEl) { statusEl.className = 'settings-status error'; statusEl.textContent = `⚠️ HTTP ${httpCode}: ${errDetail}`; }
        }
    } catch(e) {
        if (statusEl) { statusEl.className = 'settings-status error'; statusEl.textContent = `❌ Errore: ${e.message}`; }
    }
}

// ===== COOKING TIMER SYSTEM =====
let _cookingTimers = [];          // { id, label, total, seconds, running, interval }
let _cookingTimerIdCounter = 0;
let _cookingSuggestedSeconds = 0;
let _cookingSuggestedLabel = '';

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
    const fillers = new Set(['il','la','lo','le','gli','i','dell','della','dello','delle','degli','dei',
        'un','una','uno','del','al','alla','allo','alle','agli','ai','nel','nella','nello','nelle',
        'negli','nei','per','con','che','poi','e','o','non','se','in','di','a','da','fino','mentre',
        'quando','dopo','prima','circa','bene','ancora','subito','su','ad','ed','più','meno','tutto','tutta']);
    const timePatterns = [/mezz['']?\s*ora/i, /\bor[ae]\b/i, /\bmin(?:ut[oi])?\b/i, /\bsecond[oi]\b/i, /\bquarto\s+d['']?\s*ora/i];
    let timeIdx = text.length;
    for (const p of timePatterns) { const r = p.exec(text); if (r && r.index < timeIdx) timeIdx = r.index; }
    const beforeTime = (text.slice(0, timeIdx).trim() || text);
    const words = beforeTime.replace(/[.,!?;:'"()\[\]]/g, '').split(/\s+/).filter(w => w.length > 2 && !/^\d+$/.test(w));
    const meaningful = words.filter(w => !fillers.has(w.toLowerCase()));
    if (meaningful.length >= 1) return meaningful.slice(0, 3).join(' ');
    return `Passo ${stepNum + 1}`;
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
    const id = ++_cookingTimerIdCounter;
    _cookingTimers.push({ id, label, total: seconds, seconds, running: false, interval: null });
    renderTimersBar();
    toggleCookingTimerById(id); // auto-start
}

function removeCookingTimer(id) {
    const t = _cookingTimers.find(t => t.id === id);
    if (t && t.interval) clearInterval(t.interval);
    _cookingTimers = _cookingTimers.filter(t => t.id !== id);
    renderTimersBar();
    _updateScreenFlash();
}

function toggleCookingTimerById(id) {
    const t = _cookingTimers.find(t => t.id === id);
    if (!t) return;
    if (t.running) {
        clearInterval(t.interval);
        t.interval = null;
        t.running = false;
    } else {
        t.running = true;
        t.interval = setInterval(() => {
            t.seconds--;
            if (t.seconds === 10 && _cookingTTS) {
                speakCookingStep(t('cooking.timer_warning_tts').replace('{label}', t.label));
            }
            if (t.seconds === 0) _cookingTimerDoneById(id);
            _updateTimerCard(id);
        }, 1000);
    }
    _updateTimerCard(id);
}

function resetCookingTimerById(id) {
    const t = _cookingTimers.find(t => t.id === id);
    if (!t) return;
    clearInterval(t.interval);
    t.interval = null;
    t.running = false;
    t.seconds = t.total;
    _updateTimerCard(id);
}

function _cookingTimerDoneById(id) {
    if (navigator.vibrate) navigator.vibrate([300, 100, 300, 100, 300]);
    const timer = _cookingTimers.find(ti => ti.id === id);
    if (_cookingTTS && timer) speakCookingStep(t('cooking.timer_expired_tts').replace('{label}', timer.label));
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
        const text = (steps[_cookingStep] || '').replace(/^Passo\s*\d+\s*[:.]\s*/i, '');
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
    _cookingStep = next;
    renderCookingStep();
    if (_cookingTTS) {
        const text = ((_cookingRecipe.steps || [])[_cookingStep] || '').replace(/^Passo\s*\d+\s*[:.]\s*/i, '');
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
}

function regenerateRecipe() {
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

async function generateRecipe() {
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
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            document.getElementById('recipe-loading').style.display = 'none';
            document.getElementById('recipe-ask').style.display = '';
            if (data.error === 'no_api_key') {
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
            renderRecipe(recipe);
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
                showToast(t('error.connection'), 'error');
            }
        }

    } catch (err) {
        console.error('Recipe error:', err);
        document.getElementById('recipe-loading').style.display = 'none';
        document.getElementById('recipe-ask').style.display = '';
        showToast(t('error.connection'), 'error');
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
            dietary_restrictions: settings.dietary_restrictions || ''
        });
        
        // Remove typing indicator
        typingEl.remove();
        
        if (result.success) {
            chatHistory.push({ role: 'gemini', text: result.reply });
            appendChatBubble('gemini', formatChatReply(result.reply));
        } else {
            const errMsg = result.error === 'no_api_key' ? 'Configura la chiave API Gemini nelle impostazioni.' : (result.error || 'Errore nella risposta');
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

// ===== SCREENSAVER & INACTIVITY AUTO-REFRESH =====
let _inactivityTimer = null;
let _screensaverActive = false;
let _screensaverClockInterval = null;
let _screensaverFactInterval = null;
let _screensaverData = null; // cached data for fact generation
const SCREENSAVER_FACT_DURATION = 5 * 60 * 1000; // 5 minutes per fact
const INACTIVITY_TIMEOUT = 5 * 60 * 1000; // 5 minutes

function resetInactivityTimer() {
    if (_screensaverActive) return; // don't reset while screensaver is showing
    clearTimeout(_inactivityTimer);
    _inactivityTimer = setTimeout(activateScreensaver, INACTIVITY_TIMEOUT);
}

function activateScreensaver() {
    if (_screensaverActive) return;
    if (document.body.classList.contains('cooking-mode-active')) return;
    _screensaverActive = true;
    const overlay = document.getElementById('screensaver');
    overlay.style.display = 'flex';
    // Fade in
    requestAnimationFrame(() => overlay.classList.add('visible'));
    updateScreensaverClock();
    _screensaverClockInterval = setInterval(updateScreensaverClock, 1000);
    // Load data and start facts
    loadScreensaverData().then(() => {
        showNextScreensaverFact();
        _screensaverFactInterval = setInterval(showNextScreensaverFact, SCREENSAVER_FACT_DURATION);
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
    }, 400);
}

// Load all data needed for screensaver facts
async function loadScreensaverData() {
    try {
        const [statsRes, invRes, bringRes] = await Promise.all([
            api('stats'),
            api('inventory_list'),
            api('bring_list').catch(() => null)
        ]);
        _screensaverData = {
            stats: statsRes,
            inventory: invRes.inventory || [],
            shopping: bringRes && bringRes.success ? (bringRes.purchase || []) : []
        };
    } catch (e) {
        _screensaverData = { stats: {}, inventory: [], shopping: [] };
    }
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
    const greeting = hour < 12 ? 'Buongiorno' : hour < 18 ? 'Buon pomeriggio' : 'Buonasera';

    // Random item picker
    const rItem = (arr) => arr.length ? arr[Math.floor(Math.random() * arr.length)] : null;

    // All fact generators
    const facts = [];

    // --- Expired items facts ---
    if (expired.length > 0) {
        facts.push(() => `Hai ${expired.length} ${expired.length === 1 ? 'prodotto scaduto' : 'prodotti scaduti'} in dispensa. Controlla!`);
        facts.push(() => {
            const names = expired.slice(0, 3).map(i => i.name);
            return `Prodotti scaduti: ${names.join(', ')}${expired.length > 3 ? ` e altri ${expired.length - 3}` : ''}`;
        });
        const freezerExpired = expired.filter(i => i.location === 'freezer');
        if (freezerExpired.length > 0) {
            facts.push(() => {
                const item = rItem(freezerExpired);
                const safety = getExpiredSafety(item, Math.abs(daysUntilExpiry(item.expiry_date)));
                if (safety.level === 'ok' || safety.level === 'warning') {
                    return `${item.name} è scaduto, ma essendo in freezer potrebbe essere ancora buono! Controlla.`;
                }
                return `${item.name} in freezer è scaduto da troppo tempo. Meglio buttarlo.`;
            });
        }
        const frigoExpired = expired.filter(i => i.location === 'frigo');
        if (frigoExpired.length > 0) {
            facts.push(() => `Hai ${frigoExpired.length} ${frigoExpired.length === 1 ? 'prodotto scaduto' : 'prodotti scaduti'} in frigo!`);
        }
    }

    // --- Expiring soon facts ---
    if (expiringSoon.length > 0) {
        facts.push(() => {
            const item = expiringSoon[0];
            const days = daysUntilExpiry(item.expiry_date);
            if (days === 0) return `${item.name} scade oggi! Usalo subito.`;
            if (days === 1) return `${item.name} scade domani. Pensaci!`;
            return `${item.name} scade tra ${days} giorni.`;
        });
        if (expiringSoon.length > 1) {
            facts.push(() => `Hai ${expiringSoon.length} prodotti in scadenza ravvicinata.`);
        }
    }
    if (expiringThisWeek.length > 0) {
        facts.push(() => `Questa settimana scadono ${expiringThisWeek.length} prodotti. Pianifica i pasti di conseguenza!`);
        facts.push(() => {
            const item = rItem(expiringThisWeek);
            const days = daysUntilExpiry(item.expiry_date);
            const locLabel = LOCATIONS[item.location]?.label || item.location;
            return `${item.name} (${locLabel}) scade tra ${days} ${days === 1 ? 'giorno' : 'giorni'}.`;
        });
    }
    if (expiringThisMonth.length > 0) {
        facts.push(() => `In questo mese scadranno ${expiringThisMonth.length} prodotti.`);
    }

    // --- Shopping list facts ---
    if (shop.length > 0) {
        facts.push(() => `Hai ${shop.length} ${shop.length === 1 ? 'prodotto' : 'prodotti'} nella lista della spesa.`);
        facts.push(() => {
            const names = shop.slice(0, 4).map(i => i.name);
            return `Nella spesa: ${names.join(', ')}${shop.length > 4 ? '...' : ''}`;
        });
    }
    if (shop.length === 0) {
        facts.push(() => `La lista della spesa è vuota. Tutto a posto!`);
    }

    // --- Location-based facts ---
    if (inFrigo.length > 0) {
        facts.push(() => `Hai ${inFrigo.length} prodotti in frigo.`);
        facts.push(() => {
            const item = rItem(inFrigo);
            return `In frigo c'è: ${item.name}${item.brand ? ' (' + item.brand + ')' : ''}.`;
        });
    }
    if (inFreezer.length > 0) {
        facts.push(() => `Hai ${inFreezer.length} prodotti nel freezer.`);
        facts.push(() => {
            const item = rItem(inFreezer);
            return `Nel freezer c'è: ${item.name}. Non dimenticartelo!`;
        });
    }
    if (inDispensa.length > 0) {
        facts.push(() => `In dispensa ci sono ${inDispensa.length} prodotti.`);
    }

    // --- Category-based facts ---
    const catEntries = Object.entries(byCategory);
    if (catEntries.length > 0) {
        facts.push(() => {
            const sorted = catEntries.sort((a, b) => b[1].length - a[1].length);
            const top = sorted[0];
            const catLabel = top[0];
            const icon = CATEGORY_ICONS[catLabel] || '📦';
            return `La categoria più presente è ${icon} ${catLabel} con ${top[1].length} prodotti.`;
        });
        if (byCategory['carne'] && byCategory['carne'].length > 0) {
            facts.push(() => `Hai ${byCategory['carne'].length} prodotti di carne. 🥩`);
        }
        if (byCategory['latticini'] && byCategory['latticini'].length > 0) {
            facts.push(() => `Hai ${byCategory['latticini'].length} latticini in casa. 🥛`);
        }
        if (byCategory['verdura'] && byCategory['verdura'].length > 0) {
            facts.push(() => `Hai ${byCategory['verdura'].length} tipi di verdura. Ottimo per la salute! 🥬`);
        }
        if (byCategory['frutta'] && byCategory['frutta'].length > 0) {
            facts.push(() => `Hai ${byCategory['frutta'].length} tipi di frutta. 🍎`);
        }
        if (byCategory['bevande'] && byCategory['bevande'].length > 0) {
            facts.push(() => `Hai ${byCategory['bevande'].length} bevande disponibili. 🥤`);
        }
        if (byCategory['surgelati'] && byCategory['surgelati'].length > 0) {
            facts.push(() => `Hai ${byCategory['surgelati'].length} surgelati nel freezer. ❄️`);
        }
        if (byCategory['pasta'] && byCategory['pasta'].length > 0) {
            facts.push(() => `Hai ${byCategory['pasta'].length} tipi di pasta. 🍝 Che ne dici di una carbonara?`);
        }
        if (byCategory['conserve'] && byCategory['conserve'].length > 0) {
            facts.push(() => `Hai ${byCategory['conserve'].length} conserve in dispensa. 🥫`);
        }
        if (byCategory['snack'] && byCategory['snack'].length > 0) {
            facts.push(() => `Hai ${byCategory['snack'].length} snack. Resisti alla tentazione! 🍪`);
        }
        if (byCategory['condimenti'] && byCategory['condimenti'].length > 0) {
            facts.push(() => `Hai ${byCategory['condimenti'].length} condimenti a disposizione. 🧂`);
        }
    }

    // --- General inventory facts ---
    if (inv.length > 0) {
        facts.push(() => `Hai ${totalProducts} prodotti diversi in casa per un totale di ${Math.round(totalItems)} pezzi.`);
        facts.push(() => {
            const item = rItem(inv);
            return `Lo sapevi? Hai ${item.name} in ${LOCATIONS[item.location]?.label || item.location}.`;
        });
        facts.push(() => {
            const item = rItem(inv);
            const qty = formatQuantity(item.quantity, item.unit, item.default_quantity, item.package_unit);
            return `${item.name}: ne hai ${qty}.`;
        });
    }
    if (noExpiry.length > 0) {
        facts.push(() => `${noExpiry.length} prodotti non hanno una data di scadenza impostata.`);
    }
    if (withExpiry.length > 0) {
        // Find the one expiring furthest away
        const furthest = withExpiry.reduce((best, item) => {
            const d = daysUntilExpiry(item.expiry_date);
            return d > (best.d || 0) ? { item, d } : best;
        }, { d: 0 });
        if (furthest.item && furthest.d > 30) {
            facts.push(() => `Il prodotto con scadenza più lontana è ${furthest.item.name}: ${Math.round(furthest.d / 30)} mesi.`);
        }
    }

    // --- Quantity-based facts ---
    const highQtyItems = inv.filter(i => parseFloat(i.quantity) >= 5);
    if (highQtyItems.length > 0) {
        facts.push(() => {
            const item = rItem(highQtyItems);
            const qty = formatQuantity(item.quantity, item.unit, item.default_quantity, item.package_unit);
            return `Hai una bella scorta di ${item.name}: ${qty}!`;
        });
    }
    const lowQtyItems = inv.filter(i => parseFloat(i.quantity) <= 1 && parseFloat(i.quantity) > 0);
    if (lowQtyItems.length > 0) {
        facts.push(() => {
            const item = rItem(lowQtyItems);
            return `${item.name} sta per finire. Aggiungilo alla spesa?`;
        });
        facts.push(() => `Ci sono ${lowQtyItems.length} prodotti quasi finiti.`);
    }

    // --- Time-of-day greetings & suggestions ---
    facts.push(() => `${greeting}! Se vuoi che ti preparo una ricetta, tocca qui.`);
    facts.push(() => `${greeting}! La tua dispensa è sotto controllo. 😊`);
    if (hour >= 6 && hour < 10) {
        facts.push(() => `Buongiorno! Pronto per la colazione? ☕`);
        if (byCategory['pane']) facts.push(() => `Buongiorno! Hai del pane per la colazione. 🍞`);
        if (byCategory['latticini']) facts.push(() => `C'è del latte in frigo per il cappuccino? ☕🥛`);
    }
    if (hour >= 11 && hour < 14) {
        facts.push(() => `È quasi ora di pranzo! Cosa cuciniamo? 🍽️`);
        if (byCategory['pasta']) facts.push(() => `Ora di pranzo… Un bel piatto di pasta? 🍝`);
    }
    if (hour >= 17 && hour < 21) {
        facts.push(() => `Buona sera! Hai pensato alla cena? 🍽️`);
        if (byCategory['carne']) facts.push(() => `Per cena potresti usare la carne che hai. 🥩`);
        if (byCategory['pesce']) facts.push(() => `Che ne dici di pesce per cena? 🐟`);
    }
    if (hour >= 21 || hour < 6) {
        facts.push(() => `Buonanotte! Domani controlla le scadenze. 🌙`);
    }

    // --- Weekly stats ---
    const recentIn = stats.recent_in || 0;
    const recentOut = stats.recent_out || 0;
    if (recentIn > 0) {
        facts.push(() => `Questa settimana hai aggiunto ${recentIn} prodotti.`);
    }
    if (recentOut > 0) {
        facts.push(() => `Questa settimana hai consumato ${recentOut} prodotti.`);
    }
    if (recentIn > 0 && recentOut > 0) {
        facts.push(() => `Bilancio settimanale: +${recentIn} entrati, -${recentOut} usciti.`);
    }

    // --- Tips & curiosità (statici ma ruotano) ---
    facts.push(() => `💡 Lo sapevi? I prodotti in freezer durano molto più a lungo della data di scadenza.`);
    facts.push(() => `💡 Il pane congelato mantiene la fragranza per settimane.`);
    facts.push(() => `💡 Le uova si conservano fino a 3-4 settimane dopo la data preferita.`);
    facts.push(() => `💡 Lo yogurt chiuso in frigo dura spesso 1-2 settimane oltre la scadenza.`);
    facts.push(() => `💡 Per evitare sprechi, usa prima i prodotti con scadenza più vicina.`);
    facts.push(() => `💡 La carne in freezer può durare fino a 6 mesi senza problemi.`);
    facts.push(() => `💡 Le verdure fresche durano di più se conservate nel cassetto del frigo.`);
    facts.push(() => `💡 Controlla regolarmente la dispensa per evitare doppioni nella spesa.`);
    facts.push(() => `💡 I latticini vanno conservati nella parte più fredda del frigo.`);
    facts.push(() => `💡 Non ricongelare mai un alimento già scongelato. Cucinalo subito!`);
    facts.push(() => `💡 Un frigo ordinato ti fa risparmiare tempo e denaro.`);
    facts.push(() => `💡 Le conserve aperte vanno in frigo e consumate in pochi giorni.`);

    // --- Brand-based facts ---
    const brands = inv.filter(i => i.brand).map(i => i.brand);
    if (brands.length > 0) {
        const brandCount = {};
        brands.forEach(b => { brandCount[b] = (brandCount[b] || 0) + 1; });
        const topBrand = Object.entries(brandCount).sort((a, b) => b[1] - a[1])[0];
        facts.push(() => `Il marca più presente nella tua dispensa è ${topBrand[0]} con ${topBrand[1]} prodotti.`);
    }

    // --- Specific food combo facts ---
    if (byCategory['pasta'] && byCategory['condimenti']) {
        facts.push(() => `Hai pasta e condimenti: sei pronto per un primo piatto! 🍝`);
    }
    if (byCategory['pane'] && byCategory['carne']) {
        facts.push(() => `Pane e carne: un panino veloce è sempre una buona idea! 🥪`);
    }
    if (byCategory['verdura'] && byCategory['carne']) {
        facts.push(() => `Verdura e carne: hai tutto per un piatto equilibrato! 🥗🥩`);
    }

    // --- Empty states ---
    if (inv.length === 0) {
        facts.push(() => `La dispensa è vuota! Fai una bella spesa. 🛒`);
        facts.push(() => `Nessun prodotto registrato. Scansiona qualcosa per iniziare!`);
    }

    // --- Location distribution ---
    const locCount = Object.keys(byLocation).length;
    if (locCount > 1) {
        facts.push(() => {
            const parts = Object.entries(byLocation).map(([loc, items]) => 
                `${LOCATIONS[loc]?.icon || '📦'} ${items.length}`
            );
            return `Distribuzione: ${parts.join('  ·  ')}`;
        });
    }

    // --- Anti-waste knowledge facts ---
    const awFacts = _awGetFacts();
    for (const f of awFacts) { facts.push(() => f); }

    // Pick a random fact
    if (facts.length === 0) {
        return `${greeting}! La tua Dispensa ti aspetta.`;
    }
    return facts[Math.floor(Math.random() * facts.length)]();
}

// ===== SPESA MODE (long-press camera for continuous scanning) =====
let _spesaMode = false;
let _longPressTimer = null;
let _spesaSession = []; // { name, qty, unit } per ogni prodotto aggiunto

function initSpesaMode() {
    const btn = document.getElementById('btn-header-scan');
    if (!btn) return;

    btn.addEventListener('pointerdown', (e) => {
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
    btn.addEventListener('pointerleave', () => {
        if (_longPressTimer) {
            clearTimeout(_longPressTimer);
            _longPressTimer = null;
        }
    });
}

function startSpesaMode() {
    _spesaMode = true;
    _spesaSession = [];
    showToast('🛒 Modalità Spesa attivata!', 'success');
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
}

// Called after successful add — returns true if spesa mode handled navigation
function spesaModeAfterAdd() {
    if (!_spesaMode) return false;
    // Track this product in the session
    if (currentProduct) {
        _spesaSession.push({ name: currentProduct.name, category: currentProduct.category || '' });
        updateSpesaBanner();
    }
    showPage('scan');
    return true;
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

function initInactivityWatcher() {
    const events = ['pointerdown', 'pointermove', 'keydown', 'scroll', 'touchstart'];
    events.forEach(evt => {
        document.addEventListener(evt, () => {
            if (_screensaverActive) {
                dismissScreensaver();
            } else {
                resetInactivityTimer();
            }
        }, { passive: true });
    });
    resetInactivityTimer();
}

// ===== INITIALIZATION =====
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
const _setupData = { lang: _currentLang, gemini_key: '', bring_email: '', bring_password: '' };

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
        if (!s.gemini_key && !srv.gemini_key && !srv.gemini_key_set) missing.push(1);
        // Step 2 — Bring! credentials (check both localStorage and server .env)
        if ((!s.bring_email && !srv.bring_email) || (!s.bring_password && !srv.bring_password_set)) missing.push(2);
    }
    // Note: step 3 (done screen) gets appended automatically when there are missing steps

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
            title: '✅ ' + (_currentLang === 'it' ? 'Tutto pronto!' : _currentLang === 'de' ? 'Alles bereit!' : 'All set!'),
            desc: _currentLang === 'it' ? 'La configurazione è completata. Puoi sempre modificare queste impostazioni dalla pagina Configurazione.'
                 : _currentLang === 'de' ? 'Die Konfiguration ist abgeschlossen. Du kannst diese Einstellungen jederzeit ändern.'
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
    // Append the "done" step (3) at the end
    _setupPendingSteps.push(3);
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
        nextBtn.textContent = _currentLang === 'it' ? '🚀 Inizia!' : _currentLang === 'de' ? '🚀 Los geht\'s!' : '🚀 Start!';
    } else {
        nextBtn.textContent = _currentLang === 'it' ? 'Avanti →' : _currentLang === 'de' ? 'Weiter →' : 'Next →';
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

async function _initApp() {
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
        const missing = _getMissingSetupSteps(serverSettings);
        if (missing.length > 0) {
            showSetupWizard(missing);
        }
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
    syncSettingsFromDB();
    showPage('dashboard');
    initInactivityWatcher();
    initSpesaMode();
    initScreensaverShortcuts();
    startBgShoppingRefresh();
    scaleInit(); // connect to smart scale gateway if configured
    _injectKioskOverlay(); // kiosk X / refresh buttons (only when running inside Android WebView)

    // ── Background intervals ───────────────────────────────────────────────
    // 1) Ogni 5 min: ricarica la pagina corrente (scadenze, inventario, ecc.)
    setInterval(() => {
        if (!_screensaverActive) refreshCurrentPage();
    }, 5 * 60 * 1000);

    // 2) Ogni 2 min: aggiorna contatore lista spesa nel badge dashboard
    setInterval(() => {
        if (_screensaverActive) return;
        if (_currentPageId === 'shopping') {
            loadShoppingList();
        } else {
            loadShoppingCount();
        }
    }, 2 * 60 * 1000);

    // 3) Aggiorna immediatamente quando la tab torna visibile (es. torni da Bring! app)
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) refreshCurrentPage();
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
            api('bring_list').catch(() => null),
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

        const toAdd    = []; // new items not yet on Bring
        const toUpdate = []; // items on Bring that need spec updated
        const toRemove = []; // items on Bring that are no longer urgent (auto-added, now resolved)

        // Build set of auto-added item names so we can safely remove them if resolved
        const autoAdded = new Set(JSON.parse(localStorage.getItem('_bgAutoAdded') || '[]'));

        for (const si of smartItems) {
            const expectedSpec = _urgencyToSpec(si.urgency, '');
            const bringMatch = bringItems.find(bi => {
                if (bi.name.toLowerCase() === si.name.toLowerCase()) return true;
                const biFirst = _nameTokens(bi.name)[0];
                const siFirst = _nameTokens(si.name)[0];
                return biFirst && siFirst && biFirst === siFirst;
            });

            if (!bringMatch) {
                // Not on Bring — add if high/critical and not blocklisted
                if ((si.urgency === 'critical' || si.urgency === 'high') && !_isBringPurchased(si.name, si.urgency)) {
                    toAdd.push({ name: si.name, specification: expectedSpec });
                    autoAdded.add(si.name.toLowerCase());
                }
            } else {
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
            await api('bring_add', {}, 'POST', { items: allChanges, listUUID });
            logOperation('bg_bring_sync', { added: toAdd.map(i=>i.name), updated: toUpdate.map(i=>i.name) });
        }

        if (toRemove.length > 0) {
            await api('bring_remove', {}, 'POST', { items: toRemove.map(n => ({ name: n })), listUUID });
            logOperation('bg_bring_remove', { removed: toRemove });
        }

        // Update urgency badge on dashboard without re-rendering anything visible
        _updateSmartUrgencyBadge();

        // If shopping page is open, re-render it with fresh data
        if (_currentPageId === 'shopping') {
            _syncOnBringFlags();
            _syncTagsFromBringSpec();
            renderSmartShopping();
            renderShoppingItems();
            updateShoppingTabCounts();
        }

    } catch (e) { /* silent — best effort */ }
}

