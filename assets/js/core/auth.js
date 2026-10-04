/**
 * EverShelf core — API token storage and auth headers.
 */
const EVERSHELF_TOKEN_KEY = 'evershelf_api_token';

/**
 * z-index for the auth overlays. Must stay above every other overlay, in
 * particular the splash preloader (`#app-preloader`, 200000) and the network
 * error overlay (`#network-error-overlay`, 300000): pairing is asked *during*
 * startup, and `_initApp()` stops when it cannot authenticate, so the preloader
 * never goes away on its own. At 200 (`.modal-overlay`) the dialog was rendered
 * behind the splash and the app appeared to dead-end on "API token required".
 */
const EVERSHELF_AUTH_OVERLAY_Z = '400000';

function getApiToken() {
    return localStorage.getItem(EVERSHELF_TOKEN_KEY) || '';
}

function setApiToken(token) {
    const t = (token || '').trim();
    if (t) {
        localStorage.setItem(EVERSHELF_TOKEN_KEY, t);
    } else {
        localStorage.removeItem(EVERSHELF_TOKEN_KEY);
    }
}

function apiAuthHeaders() {
    const fromStorage = getApiToken();
    const fromSettingsField = document.getElementById('setting-settings-token')?.value.trim() || '';
    const token = fromSettingsField || fromStorage;
    if (!token) return {};
    return { 'X-API-Token': token };
}

/** Fetch API token from server when loading the UI from the same origin. */
async function ensureApiToken() {
    if (getApiToken()) return true;
    try {
        const res = await fetch('api/index.php?action=app_bootstrap', { cache: 'no-store' });
        if (!res.ok) return false;
        const data = await res.json();
        window._apiTokenRequired = !!data.api_token_required;
        window._pairingRequired = !!data.pairing_required;
        if (data.api_token) {
            setApiToken(data.api_token);
            window._pairingRequired = false;
            return true;
        }
        if (data.pairing_required) {
            _promptPairingCode();
        }
    } catch (_) { /* offline / network */ }
    return !!getApiToken();
}

const EVERSHELF_PAIRING_OVERLAY = 'api-pairing-overlay';

function _anyAuthOverlayOpen() {
    return !!(document.getElementById('api-token-overlay') || document.getElementById(EVERSHELF_PAIRING_OVERLAY));
}

/**
 * Ask for the one-time pairing code printed in the server log (docker logs / logs/).
 * The server never hands API_TOKEN to an anonymous request any more.
 */
function _promptPairingCode() {
    if (_anyAuthOverlayOpen()) return;
    const title = typeof t === 'function' ? t('startup.pairing_title') : '🔒 Pair this device';
    const hint  = typeof t === 'function' ? t('startup.pairing_hint') : 'Enter the pairing code shown in the server log (docker logs evershelf).';
    const btn   = typeof t === 'function' ? t('startup.pairing_btn') : 'Pair';
    const ph    = typeof t === 'function' ? t('startup.pairing_placeholder') : 'Pairing code';
    const overlay = document.createElement('div');
    overlay.id = EVERSHELF_PAIRING_OVERLAY;
    overlay.className = 'modal-overlay';
    overlay.style.display = 'flex';
    overlay.style.zIndex = EVERSHELF_AUTH_OVERLAY_Z;
    overlay.innerHTML = `
        <div class="modal-content" style="max-width:420px;padding:20px">
            <h3>${title}</h3>
            <p class="settings-hint">${hint}</p>
            <input type="text" id="api-pairing-input" class="form-input" autocomplete="one-time-code" placeholder="${ph}">
            <p class="settings-hint" id="api-pairing-error" style="color:#c0392b;display:none"></p>
            <button class="btn btn-primary full-width mt-2" id="api-pairing-save">${btn}</button>
        </div>`;
    document.body.appendChild(overlay);

    const submit = async () => {
        const input = document.getElementById('api-pairing-input');
        const errEl = document.getElementById('api-pairing-error');
        const code  = (input?.value || '').trim();
        if (!code) return;
        try {
            const res = await fetch('api/index.php?action=app_bootstrap&pairing_code=' + encodeURIComponent(code), { cache: 'no-store' });
            const data = await res.json();
            if (data.api_token) {
                setApiToken(data.api_token);
                overlay.remove();
                location.reload();
                return;
            }
        } catch (_) { /* fall through to the error below */ }
        if (errEl) {
            errEl.textContent = typeof t === 'function' ? t('startup.pairing_error') : 'Invalid or expired code. Check the server log for a new one.';
            errEl.style.display = '';
        }
    };
    document.getElementById('api-pairing-save').onclick = submit;
    document.getElementById('api-pairing-input').addEventListener('keydown', (e) => {
        if (e.key === 'Enter') submit();
    });
}

function _promptApiTokenIfNeeded() {
    if (!window._apiTokenRequired) return;
    if (getApiToken()) return;
    if (_anyAuthOverlayOpen()) return;
    const title = typeof t === 'function' ? t('startup.token_prompt_title') : '🔒 API Token';
    const hint  = typeof t === 'function' ? t('startup.token_prompt_hint') : 'Enter API_TOKEN from .env';
    const btn   = typeof t === 'function' ? t('startup.token_prompt_btn') : 'Continue';
    const ph    = typeof t === 'function' ? t('startup.token_prompt_placeholder') : 'API token';
    const overlay = document.createElement('div');
    overlay.id = 'api-token-overlay';
    overlay.className = 'modal-overlay';
    overlay.style.display = 'flex';
    overlay.style.zIndex = EVERSHELF_AUTH_OVERLAY_Z;
    overlay.innerHTML = `
        <div class="modal-content" style="max-width:420px;padding:20px">
            <h3>${title}</h3>
            <p class="settings-hint">${hint}</p>
            <input type="password" id="api-token-input" class="form-input" placeholder="${ph}">
            <button class="btn btn-primary full-width mt-2" id="api-token-save">${btn}</button>
        </div>`;
    document.body.appendChild(overlay);
    document.getElementById('api-token-save').onclick = () => {
        const v = document.getElementById('api-token-input').value.trim();
        if (v) {
            setApiToken(v);
            overlay.remove();
            location.reload();
        }
    };
}

window.getApiToken = getApiToken;
window.setApiToken = setApiToken;
window.apiAuthHeaders = apiAuthHeaders;
window.ensureApiToken = ensureApiToken;
window._promptApiTokenIfNeeded = _promptApiTokenIfNeeded;
window._promptPairingCode = _promptPairingCode;
