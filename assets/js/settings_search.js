/**
 * Settings search — find a setting by name, description, synonym or `.env` key.
 *
 * The index is built from three sources that already exist: the generated
 * registry (`settings.schema.generated.js`, one entry per control), the live
 * settings page (what a control shows is already translated in the DOM), and
 * the keys that only live in `.env` (listed by the generator with a reason, so
 * nothing is unfindable).
 *
 * No new translations are needed for the results: label and description are
 * read through `t()` from the same keys the page uses.
 */

let _settingsIndex = null;
let _settingsIndexLang = '';
let _settingsHits = [];
let _settingsHitIndex = 0;

/** Accent- and case-insensitive form used for matching. */
function _settingsNorm(value) {
    return String(value || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase();
}

/** Put the search field at the top of the settings page (once). */
function _settingsSearchInit() {
    const page = document.getElementById('page-settings');
    if (!page || document.getElementById('settings-search')) {
        // Already there: the language may have changed, so rebuild lazily.
        _settingsIndex = null;
        return;
    }
    const wrap = document.createElement('div');
    wrap.className = 'settings-search';
    const placeholder = escapeHtml(t('settings_search.placeholder'));
    wrap.innerHTML = `
        <div class="settings-search-box">
            <span class="settings-search-icon" aria-hidden="true">🔍</span>
            <input type="search" id="settings-search" class="form-input"
                   placeholder="${placeholder}" aria-label="${placeholder}"
                   aria-describedby="settings-search-hint" autocomplete="off">
            <button type="button" id="settings-search-clear" class="search-clear-btn" hidden
                    aria-label="${escapeAttr(t('settings_search.clear'))}">×</button>
            <kbd class="settings-search-kbd" aria-hidden="true">Ctrl K</kbd>
        </div>
        <p class="settings-hint" id="settings-search-hint">${escapeHtml(t('settings_search.hint'))}</p>
        <div id="settings-search-results" class="settings-search-results" hidden></div>`;
    // Its own slot at the top of the page (index.html), so the field is the first
    // thing on the page instead of an element inserted before an unknown child.
    const mount = document.getElementById('settings-search-mount');
    if (mount) mount.appendChild(wrap);
    else page.insertBefore(wrap, page.firstElementChild);

    const input = document.getElementById('settings-search');
    input.addEventListener('input', _settingsSearchRender);
    input.addEventListener('keydown', _settingsSearchKey);
    document.getElementById('settings-search-clear')
        .addEventListener('click', () => _settingsSearchClear(true));
    _settingsIndex = null;
}

/** One flat list: controls (jumpable) + `.env`-only keys (informative). */
function _settingsBuildIndex() {
    const schema = window.EVERSHELF_SETTINGS_SCHEMA || {};
    const entries = [];
    (schema.entries || []).forEach(entry => {
        const label = entry.label ? t(entry.label) : '';
        const hint = entry.hint ? t(entry.hint) : (entry.card_hint ? t(entry.card_hint) : '');
        const card = entry.card ? t(entry.card) : '';
        const keywords = entry.keywords ? t(entry.keywords) : '';
        const el = document.getElementById(entry.id);
        const group = el ? el.closest('.form-group') : null;
        const domText = group && group.innerText ? group.innerText : '';
        entries.push({
            id: entry.id, env: entry.env || '', tab: entry.tab, group: entry.group,
            type: entry.type, label, hint, card, env_only: false,
            hay: _settingsNorm([label, hint, card, keywords, entry.env, entry.id, domText].join(' ')),
        });
    });
    (schema.env_only || []).forEach(row => {
        const hint = t('settings_search.env_only');
        entries.push({
            id: '', env: row.env, tab: '', group: '', type: 'env',
            label: row.env, hint, card: '', env_only: true,
            hay: _settingsNorm(row.env + ' ' + hint),
        });
    });
    _settingsIndex = entries;
    _settingsIndexLang = document.documentElement.lang || '';
    return entries;
}

function _settingsSearchRender() {
    const input = document.getElementById('settings-search');
    const box = document.getElementById('settings-search-results');
    const clear = document.getElementById('settings-search-clear');
    if (!input || !box) return;
    const query = input.value.trim();
    if (clear) clear.hidden = query === '';
    if (query === '') {
        box.hidden = true;
        box.innerHTML = '';
        _settingsHits = [];
        return;
    }
    const lang = document.documentElement.lang || '';
    const entries = (_settingsIndex && lang === _settingsIndexLang) ? _settingsIndex : _settingsBuildIndex();
    const tokens = _settingsNorm(query).split(/\s+/).filter(Boolean);
    // Word-boundary matching: 'tema' must not match 'sistemazione', while
    // 'gemini_api_key' and 'scon' still work (prefix of a word).
    const patterns = tokens.map(token => new RegExp('(^|[^a-z0-9])' + token.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
    const hits = entries.filter(entry => patterns.every(re => re.test(entry.hay))).slice(0, 25);
    _settingsHits = hits;
    _settingsHitIndex = 0;
    box.hidden = false;
    if (!hits.length) {
        box.innerHTML = `<p class="settings-search-empty">${escapeHtml(t('settings_search.empty'))}</p>`;
        return;
    }
    box.innerHTML = `<p class="settings-search-count">${escapeHtml(
        t('settings_search.results').replace('{n}', String(hits.length))
    )}</p>` + hits.map((hit, i) => `
        <button type="button" class="settings-search-hit${i === 0 ? ' is-active' : ''}" data-hit="${i}">
            <span class="settings-search-hit-label">${escapeHtml(hit.label || hit.env)}${hit.env ? ` <code>${escapeHtml(hit.env)}</code>` : ''}</span>
            <span class="settings-search-hit-hint">${escapeHtml(hit.hint || hit.card || '')}</span>
        </button>`).join('');
    box.querySelectorAll('[data-hit]').forEach(btn => {
        btn.addEventListener('click', () => _settingsSearchJump(hits[Number(btn.dataset.hit)]));
    });
}

/** Open the tab of a result, scroll to its field and flash it. */
function _settingsSearchJump(hit) {
    if (!hit) return;
    if (hit.env_only) {
        showToast(t('settings_search.env_only') + ' · ' + hit.env, 'info');
        return;
    }
    if (typeof switchSettingsGroup === 'function' && hit.group) switchSettingsGroup(hit.group, true);
    const tabBtn = document.querySelector(`.settings-tabs .settings-tab[data-tab="${hit.tab}"]`);
    if (tabBtn) tabBtn.click();
    const el = document.getElementById(hit.id);
    const group = el ? el.closest('.form-group') : null;
    if (group) {
        group.classList.add('settings-field-hit');
        if (group.scrollIntoView) group.scrollIntoView({ block: 'center', behavior: 'smooth' });
        setTimeout(() => group.classList.remove('settings-field-hit'), 2600);
    }
    if (el && typeof el.focus === 'function') el.focus({ preventScroll: true });
    _settingsSearchClear(false);
}

function _settingsSearchClear(clearInput = false) {
    const input = document.getElementById('settings-search');
    const box = document.getElementById('settings-search-results');
    const clear = document.getElementById('settings-search-clear');
    if (clearInput && input) {
        input.value = '';
        if (input.focus) input.focus();
    }
    if (clear) clear.hidden = true;
    if (box) {
        box.hidden = true;
        box.innerHTML = '';
    }
    _settingsHits = [];
}

function _settingsSearchKey(ev) {
    if (ev.key === 'Escape') {
        ev.preventDefault();
        _settingsSearchClear(true);
        return;
    }
    if (!_settingsHits.length) return;
    if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
        ev.preventDefault();
        _settingsHitIndex = (_settingsHitIndex + (ev.key === 'ArrowDown' ? 1 : -1) + _settingsHits.length) % _settingsHits.length;
        document.querySelectorAll('.settings-search-hit').forEach((btn, i) => {
            btn.classList.toggle('is-active', i === _settingsHitIndex);
        });
        const active = document.querySelectorAll('.settings-search-hit')[_settingsHitIndex];
        if (active && active.scrollIntoView) active.scrollIntoView({ block: 'nearest' });
    } else if (ev.key === 'Enter') {
        ev.preventDefault();
        _settingsSearchJump(_settingsHits[_settingsHitIndex]);
    }
}

// `/` or Ctrl/Cmd+K opens the search while the settings page is on screen.
document.addEventListener('keydown', (ev) => {
    if (typeof _currentPageId === 'undefined' || _currentPageId !== 'settings') return;
    const tag = (ev.target && ev.target.tagName) || '';
    const typing = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (ev.target && ev.target.isContentEditable);
    if (!typing && (ev.key === '/' || ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 'k'))) {
        ev.preventDefault();
        const input = document.getElementById('settings-search');
        if (input && input.focus) input.focus();
    }
});
