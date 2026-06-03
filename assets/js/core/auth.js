/**
 * EverShelf core — API token storage and auth headers.
 */
const EVERSHELF_TOKEN_KEY = 'evershelf_api_token';

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
        if (data.api_token) {
            setApiToken(data.api_token);
            return true;
        }
    } catch (_) { /* offline / network */ }
    return !!getApiToken();
}

function _promptApiTokenIfNeeded() {
    if (!window._apiTokenRequired) return;
    if (getApiToken()) return;
    const existing = document.getElementById('api-token-overlay');
    if (existing) return;
    const title = typeof t === 'function' ? t('startup.token_prompt_title') : '🔒 API Token';
    const hint  = typeof t === 'function' ? t('startup.token_prompt_hint') : 'Enter API_TOKEN from .env';
    const btn   = typeof t === 'function' ? t('startup.token_prompt_btn') : 'Continue';
    const overlay = document.createElement('div');
    overlay.id = 'api-token-overlay';
    overlay.className = 'modal-overlay';
    overlay.style.display = 'flex';
    overlay.innerHTML = `
        <div class="modal-content" style="max-width:420px;padding:20px">
            <h3>${title}</h3>
            <p class="settings-hint">${hint}</p>
            <input type="password" id="api-token-input" class="form-input" placeholder="API token">
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
