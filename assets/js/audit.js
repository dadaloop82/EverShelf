/**
 * Quick audit mode (#285) — stocktaking one card at a time.
 *
 * An inventory drifts, and correcting it one product at a time is too slow to
 * ever be done. This is a focused UI: pick a scope, then each card reads what the
 * app believes ("Ci sono ancora 120 g di Formaggio Morbido di Barilla in Frigo")
 * and asks one question with real answers:
 *
 *   - "Sì, confermo"      nothing is written, the row is right;
 *   - "Cambio quantità"   opens the normal inventory edit sheet ON TOP of the
 *                         stocktake (manual input or the scale, as it already
 *                         works) with the quantity in view;
 *   - "Cambio posizione"  the same sheet, with the location selector in view;
 *   - "No, è esaurito"    the "usa tutto" action (`inventory_use` + use_all), so
 *                         consumption stats and the shopping list are updated;
 *   - "No, è deperito"    the "butta tutto" action (`inventory_use` + a waste
 *                         reason, notes "Buttato|spoiled"), so it counts as waste.
 *
 * "Salta" stays as the quiet answer. The writes are queued and flushed every
 * AUDIT_MODE_BATCH_SIZE items and at the end, so a broken connection
 * costs at most one batch, and the summary counts what changed; a change that did
 * not save is reported instead of disappearing.
 *
 * Kiosk friendly: no keyboard required, arrow keys as a bonus, big targets.
 */

let _audit = null;
/** Flush every N answered items; the .env value can lower or raise it. */
let _auditBatchSize = 5;

/** Where a paused run lives, and how long it may be resumed. */
const AUDIT_RESUME_KEY = 'evershelf_audit_resume';
const AUDIT_RESUME_TTL_MS = 60 * 60 * 1000; // one hour

/** Clamp what the settings page sends (1..50); called from loadSettings(). */
function _auditSetBatchSize(value) {
    const n = Math.round(Number(value) || 5);
    _auditBatchSize = Math.max(1, Math.min(50, n));
}

/* ── Two ways to run a stocktake ─────────────────────────────────────────────
 * The app can PROPOSE the products it knows (a card at a time — fast, and the only way to find
 * drift on rows nobody looks at), or the household can NAME them (an input, a search, and the
 * same card). The second answers the question you have in front of a shelf ("this is here, where
 * does the app think it is?"), so it lists only rows that really hold stock, puts the place this
 * stocktake is about FIRST, marks each hit ✓ (here) or ✕ (elsewhere, with «Sposta qui») and lets a
 * row be confirmed with one tap («✓ C'è») without opening the card. Both modes land on the same
 * card, so the answers, the batching and the undo are one implementation.
 */
let _auditMode = 'propose';          // 'propose' | 'search' | 'scan'
let _auditSearchTerm = '';
let _auditSearchTimer = 0;
const AUDIT_MODE_KEY = 'evershelf_audit_mode';
/** The place this stocktake is about ('' = the whole pantry). */
let _auditScope = '';
const AUDIT_SCOPE_KEY = 'evershelf_audit_scope';
/** How many rows the search lists: this place's own first, the other shelves after. */
const AUDIT_SEARCH_MAX_HERE = 8;
const AUDIT_SEARCH_MAX_ELSEWHERE = 4;
/**
 * The STOCKTAKE SESSION: one ledger for the three ways in.
 *
 * «Uno alla volta», «Ti dico io» and the barcode all answer the same question about the same
 * shelf, so they share `counts`, `answered` and `history` (and the propose run's `order`/`index`,
 * which is what «↩ Torna alla card» resumes). The view (`_audit`) is only what is on screen right
 * now; closing it, switching mode or opening the camera never touches this.
 */
let _auditSession = null;

/** Set while the stocktake's own camera is open (a stream that belongs to this procedure only). */
let _auditScanActive = false;
/** The MediaStream, the frame timer and the generation that cancels a pending read. */
let _auditScanStream = null;
let _auditScanTimer = 0;
let _auditScanGen = 0;

function _auditLoadMode() {
    try {
        const saved = localStorage.getItem(AUDIT_MODE_KEY);
        if (saved === 'search' || saved === 'propose' || saved === 'scan') _auditMode = saved;
    } catch (_e) { /* private mode: the default stands */ }
}

/** Case- and accent-insensitive needle for the search (`Caffè` matches `caffe`). */
function _auditFold(value) {
    return String(value || '')
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .trim();
}

/**
 * The place this stocktake is about — the ONE answer the whole mode reads.
 *
 * The run's own scope wins (the place picked in mode 1), then the place the pantry has open (the
 * banner names it), then the place used last time: "first the products in Frigo" must be said about
 * the same shelf the household is standing in front of.
 */
function _auditScopeKey() {
    if (_audit && _audit.scope) return normalizeLocationKey(_audit.scope);
    if (typeof currentLocation !== 'undefined' && currentLocation) return normalizeLocationKey(currentLocation);
    // A place the household deleted must not come back as the scope of a move.
    const known = (key) => !key || (typeof LOCATIONS === 'undefined') || !!LOCATIONS[key];
    if (_auditScope && known(_auditScope)) return _auditScope;
    const stored = _auditStoredScope();
    return known(stored) ? stored : '';
}

/** Remember it, so switching mode (or a search card opened by name) forgets no shelf. */
function _auditSetScope(key) {
    _auditScope = key ? normalizeLocationKey(key) : '';
    try { localStorage.setItem(AUDIT_SCOPE_KEY, _auditScope); } catch (_e) { /* private mode: memory only */ }
}

function _auditStoredScope() {
    try { return normalizeLocationKey(localStorage.getItem(AUDIT_SCOPE_KEY) || ''); } catch (_e) { return ''; }
}

/** The typed label of a place key ('' stays empty: the whole pantry is not a place). */
function _auditScopeLabel(key) {
    if (!key) return '';
    const info = (typeof LOCATIONS !== 'undefined' && LOCATIONS[key]) || null;
    return (info && info.label) || String(key);
}

/* ── The shared ledger of the three ways in ───────────────────────────────── */

/** A fresh session for a place. */
function _auditSessionNew(scope) {
    return {
        scope: scope || '',
        startedAt: Date.now(),
        counts: _auditEmptyCounts(),
        answered: {},
        history: [],
        order: [],
        index: 0,
    };
}

/**
 * The session of this place — created on first use, never replaced by a mode switch.
 *
 * A DIFFERENT place starts a new one: the tally belongs to a shelf, and carrying "3 confermati"
 * from the fridge into the pantry would be a lie.
 */
function _auditSessionFor(scope) {
    const key = scope || '';
    if (_auditSession && (_auditSession.scope || '') === key) return _auditSession;
    _auditSession = _auditSessionNew(key);
    return _auditSession;
}

/** How many cards of the propose run are still unanswered (for «↩ Torna alla card»). */
function _auditSessionRemaining() {
    const s = _auditSession;
    if (!s || !s.order.length) return 0;
    return s.order.filter(id => !(s.answered || {})[String(id)]).length;
}

/**
 * The tally every sheet carries: proof that the three ways feed ONE stocktake, plus the two doors
 * out of a single answer («Riepilogo» and «↩ Torna alla card»).
 */
function _auditSessionBarHtml() {
    const s = _auditSession;
    if (!s) return '';
    const c = s.counts || _auditEmptyCounts();
    const total = c.there + c.finished + c.spoiled + c.skipped;
    const left = _auditSessionRemaining();
    if (!total && !left) return '';
    const bits = [];
    if (c.there) bits.push(`✓ ${c.there}`);
    if (c.finished) bits.push(`🏁 ${c.finished}`);
    if (c.spoiled) bits.push(`🗑️ ${c.spoiled}`);
    if (c.skipped) bits.push(`⏭️ ${c.skipped}`);
    const place = _auditScopeLabel(s.scope || '');
    const label = place ? t('audit.session_place', { place }) : t('audit.session_all');
    return `<div class="audit-session-bar">
        <span class="audit-session-line">${escapeHtml(label)}${bits.length ? `: <strong>${escapeHtml(bits.join(' · '))}</strong>` : ''}</span>
        <span class="audit-session-actions">
            ${left ? `<button type="button" class="audit-session-btn" onclick="_auditResumeCard()">↩ ${escapeHtml(t('audit.resume_card', { n: left }))}</button>` : ''}
            ${total ? `<button type="button" class="audit-session-btn" onclick="_auditShowSummary()">${escapeHtml(t('audit.session_summary'))}</button>` : ''}
        </span>
    </div>`;
}

/** The mode switch: three ways in, the chosen one emerald-filled. */
function _auditModeSwitchHtml() {
    const btn = (mode, icon, key) => {
        const on = _auditMode === mode;
        return `<button type="button" class="audit-mode${on ? ' is-active' : ''}" aria-pressed="${on}"
            onclick="_auditSetMode('${mode}')"><span aria-hidden="true">${icon}</span> ${escapeHtml(t(key))}</button>`;
    };
    return `<div class="audit-modes">${btn('propose', '🃏', 'audit.mode_propose')}${btn('search', '🔎', 'audit.mode_search')}${btn('scan', '📷', 'audit.mode_scan')}</div>`;
}

function _auditSetMode(mode) {
    _auditMode = (mode === 'search' || mode === 'scan') ? mode : 'propose';
    try { localStorage.setItem(AUDIT_MODE_KEY, _auditMode); } catch (_e) { /* ignore */ }
    // Back to «Uno alla volta» with a session open means back to ITS card: the three ways are one
    // stocktake, not three.
    if (_auditMode === 'propose' && _auditSession && _auditSession.order.length && _auditSessionView()) return;
    // «Scansiona» IS the camera: choosing it opens the stream, not a sheet that asks to.
    if (_auditMode === 'scan') {
        _auditScanStart();
        return;
    }
    _auditStart();
}

/**
 * Remember the point reached — the SESSION, not the sheet.
 *
 * Closing the overlay by mistake (or leaving for the camera) must not restart the stocktake: the
 * run is resolved against the pantry as it is when the user comes back, everything already
 * answered is skipped, and the tally of the answers given by search and by barcode survives too.
 */
function _auditPersist() {
    const s = _auditSession;
    if (!s) return;
    // The propose view owns the card order and the point reached; a search or scan card only
    // adds an answer to the same ledger.
    if (_audit && _audit.mode === 'propose') {
        s.order = (_audit.order || []).slice();
        s.index = _audit.index || 0;
        s.scope = _audit.scope || '';
        s.startedAt = _audit.startedAt || s.startedAt;
    }
    // Nothing to resume without an order (a stocktake done entirely by name or by barcode is
    // written to the server already; its tally is a convenience, not a record).
    if (!s.order.length) return;
    try {
        localStorage.setItem(AUDIT_RESUME_KEY, JSON.stringify({
            v: 1,
            scope: s.scope || null,
            order: s.order,
            index: s.index || 0,
            answered: s.answered || {},
            history: s.history || [],
            counts: s.counts,
            startedAt: s.startedAt,
            updatedAt: Date.now(),
        }));
    } catch (_e) { /* storage disabled/full: the run simply cannot be resumed */ }
}

function _auditClearPersist() {
    try { localStorage.removeItem(AUDIT_RESUME_KEY); } catch (_e) { /* ignore */ }
}

/**
 * The paused run, resolved against the current pantry (rows can be moved, edited
 * or deleted meanwhile). Null when there is nothing worth resuming: older than an
 * hour, unreadable, every row already answered, or every row gone.
 */
function _auditSavedRun() {
    let data;
    try {
        const raw = localStorage.getItem(AUDIT_RESUME_KEY);
        if (!raw) return null;
        data = JSON.parse(raw);
    } catch (_e) {
        return null;
    }
    if (!data || data.v !== 1 || !Array.isArray(data.order) || !data.updatedAt) return null;
    if (Date.now() - Number(data.updatedAt) > AUDIT_RESUME_TTL_MS) {
        _auditClearPersist();
        return null;
    }
    const answered = (data.answered && typeof data.answered === 'object') ? data.answered : {};
    const all = _auditItems();
    // The pantry cache may not be loaded yet: keep the saved run for later instead
    // of throwing away a perfectly good stocktake.
    if (!all.length) return null;
    const byId = new Map(all.map(it => [it.id, it]));
    const items = data.order
        .filter(id => !answered[String(id)])
        .map(id => byId.get(id))
        .filter(Boolean);
    if (!items.length) {
        _auditClearPersist();
        return null;
    }
    return { ...data, answered, items };
}

/** The quantity that means "still here, but nearly gone" for this row's unit. */
function _auditAlmostOutQty(item) {
    const unit = String(item.unit || 'pz');
    if (unit === 'pz') return 1;
    if (unit === 'conf') return 0.5;
    if (unit === 'kg' || unit === 'l') return 0.1;
    return 100; // g / ml
}

function _auditItems() {
    return _inventoryVisibleItems(_allInventoryCache || currentInventory || []);
}

/** The counters a run starts with (and the summary falls back to). */
function _auditEmptyCounts() {
    return { there: 0, finished: 0, spoiled: 0, skipped: 0 };
}

/** The mass unit the scale can fill for this row, or '' when it cannot weigh it. */
function _auditWeighableUnit(it) {
    const unit = String(it.unit || 'pz').toLowerCase();
    if (unit === 'g' || unit === 'ml') return unit;
    if (unit === 'kg') return 'g';
    if (unit === 'l') return 'ml';
    if (unit === 'conf') {
        const pkg = String(it.package_unit || '').toLowerCase();
        return (pkg === 'g' || pkg === 'ml') ? pkg : '';
    }
    return '';
}

/** The scale is offered only when it is enabled AND really connected. */
function _auditScaleReady(it) {
    if (!_auditWeighableUnit(it)) return false;
    if (typeof getSettings !== 'function' || typeof _scaleConnected === 'undefined') return false;
    const s = getSettings() || {};
    return !!(s.scale_enabled && s.scale_gateway_url && _scaleConnected);
}

/**
 * One-tap weight correction: open the edit sheet on the quantity (the stocktake
 * stays underneath) and start reading the scale immediately. The sheet keeps its
 * own "Leggi dalla bilancia" button too.
 */
function _auditScale() {
    if (!_audit || _audit.finished) return;
    const it = _audit.items[_audit.index];
    if (!it || !_auditScaleReady(it)) return;
    _auditEdit('quantity');
    // readScaleForEdit() needs the sheet in the DOM; a row not in the cache is
    // fetched before the form is drawn.
    const start = (tries = 0) => {
        if (!document.getElementById('edit-qty')) {
            if (tries < 20) setTimeout(() => start(tries + 1), 50);
            return;
        }
        // Reading is the input here: keep the on-screen keyboard out of the way.
        const active = document.activeElement;
        if (active && typeof active.blur === 'function') active.blur();
        if (typeof readScaleForEdit === 'function') readScaleForEdit();
    };
    setTimeout(() => start(), 80);
}

/**
 * The pantry banner's CTA: start on the place the list is showing.
 *
 * Opening a place already answers "what do I want to check", so the scope picker would be one
 * question too many — the wizard starts on those rows. In the whole-house view the picker IS the
 * answer (a hundred products in one run is not a session), and a paused run for the same place
 * continues instead of restarting from zero.
 */
function _auditStartFromBanner() {
    const key = currentLocation ? normalizeLocationKey(currentLocation) : '';
    // The place the banner names is the scope of BOTH modes: the search list puts its rows first.
    _auditSetScope(key);
    if (!key) {
        _auditStart();
        return;
    }
    // A stocktake already open on this shelf continues where it stopped — whichever of the three
    // ways answered last.
    if (_auditSession && (_auditSession.scope || '') === key && _auditSessionView()) return;
    const saved = _auditSavedRun();
    if (saved && (saved.scope || '') === key) {
        _auditResume();
        return;
    }
    _auditBegin(key, null);
}

/** Entry point: resume a paused run, or choose the scope of a new one. */
function _auditStart() {
    const items = _auditItems();
    // A live session IS this page's saved run: the persisted copy would only duplicate it.
    const saved = (_auditSession && _auditSession.order.length) ? null : _auditSavedRun();
    const el = document.getElementById('audit-overlay');
    if (!el) return;
    _auditLoadMode();
    // A search or a scan reached from the whole-house view still knows which shelf it is about.
    _auditSetScope(_auditScopeKey());
    el.hidden = false;
    const body = _auditMode === 'search'
        ? _auditSearchBodyHtml()
        : (_auditMode === 'scan' ? _auditScanBodyHtml() : _auditScopesBodyHtml(items, saved));
    el.innerHTML = `<div class="audit-panel">
        <div class="audit-head">
            <h3>${escapeHtml(t('audit.title'))}</h3>
            <button type="button" class="audit-close" onclick="_auditStop()" aria-label="${escapeAttr(t('audit.close'))}">✕</button>
        </div>
        ${_auditModeSwitchHtml()}
        ${_auditSessionBarHtml()}
        ${body}
    </div>`;
    if (_auditMode === 'search') {
        const input = document.getElementById('audit-search-input');
        if (input) {
            input.value = _auditSearchTerm || '';
            setTimeout(() => { try { input.focus(); } catch (_e) { /* kiosk: no keyboard */ } }, 60);
        }
        _auditRenderSearchResults();
    }
}

/** Mode 1 — the products the app knows, one card at a time, scoped by place. */
function _auditScopesBodyHtml(items, saved) {
    // An empty pantry is a reason to stop proposing, not to stop a stocktake: the search mode
    // exists exactly for the products the app cannot list.
    if (!items.length) {
        return `<p class="audit-hint">${escapeHtml(t('audit.empty'))}</p>
            <button type="button" class="audit-scope" onclick="_auditSetMode('search')">🔎 ${escapeHtml(t('audit.mode_search'))}</button>`;
    }
    const byLoc = {};
    items.forEach(it => { byLoc[it.location] = (byLoc[it.location] || 0) + 1; });
    const scopes = [];
    if (saved) {
        // A run closed by mistake within the last hour continues where it stopped.
        scopes.push(`<button type="button" class="audit-scope audit-resume" onclick="_auditResume()"><span aria-hidden="true">↩</span> ${escapeHtml(t('audit.resume', { n: saved.items.length }))}</button>`);
    }
    scopes.push(`<button type="button" class="audit-scope" onclick="_auditBegin(null)">${escapeHtml(t('audit.scope_all'))} (${items.length})</button>`);
    Object.keys(byLoc).forEach(loc => {
        const info = LOCATIONS[loc] || { icon: '📦', label: loc };
        scopes.push(`<button type="button" class="audit-scope" onclick="_auditBegin('${escapeHtml(loc)}')">${info.icon} ${escapeHtml(info.label || loc)} (${byLoc[loc]})</button>`);
    });
    return `${saved ? `<p class="audit-hint audit-resume-hint">${escapeHtml(t('audit.resume_hint'))}</p>` : ''}
        <p class="audit-hint">${escapeHtml(t('audit.scope_hint'))}</p>
        <div class="audit-scopes">${scopes.join('')}</div>`;
}

/** Mode 2 — name the product: one field, the matches under it, the same card after a tap. */
function _auditSearchBodyHtml() {
    const scope = _auditScopeKey();
    const placeLabel = _auditScopeLabel(scope);
    return `<p class="audit-hint">${escapeHtml(t('audit.search_hint'))}</p>
        ${scope ? `<p class="audit-hint audit-search-scope-note"><span aria-hidden="true">📍</span> ${escapeHtml(t('audit.search_in_place', { place: placeLabel }))}</p>` : ''}
        <input type="text" id="audit-search-input" class="form-input audit-search-input"
               autocomplete="off" autocapitalize="sentences" spellcheck="false"
               placeholder="${escapeAttr(t('audit.search_placeholder'))}"
               oninput="_auditSearchInput(this.value)"
               onkeydown="if(event.key==='Enter'){event.preventDefault();_auditSearchPickFirst();}">
        <div class="audit-search-results" id="audit-search-results"></div>`;
}

/** Continue the paused run (the picker already told the user how many are left). */
function _auditResume() {
    const saved = _auditSavedRun();
    if (!saved) {
        _auditStart();
        return;
    }
    _auditBegin(saved.scope, saved);
}

/* ── Mode 2: the household names the product ───────────────────────────────── */

/** Type-as-you-search, debounced: the pantry is in memory. */
function _auditSearchInput(value) {
    _auditSearchTerm = String(value || '');
    clearTimeout(_auditSearchTimer);
    _auditSearchTimer = setTimeout(_auditRenderSearchResults, 120);
}

/**
 * What the typed name matches, split by the place this stocktake is about:
 *
 *   - `here`      the rows standing in THAT place — they come first and are what Enter picks;
 *   - `elsewhere` the same name on another shelf — the pack the household is holding in front of
 *                 the open place, marked ✕ with a way to bring it where it belongs.
 *
 * Only rows with real stock enter either list: a product the app knows with nothing on a shelf is
 * not a stocktake row (and a depleted trace is not a product), so the search never proposes
 * something that is not there.
 */
function _auditSearchMatches(term) {
    const q = _auditFold(term);
    const here = [];
    const elsewhere = [];
    if (!q) return { here, elsewhere };
    const scope = _auditScopeKey();
    // A row this stocktake ALREADY answered — by hand, by name or by barcode — is not a question
    // any more, and mode 1 filters it out for the same reason (`_auditBegin`).
    //
    // Here it was not filtered, and «✓ C'è» writes nothing (the shelf is right, that is the whole
    // point): nothing changed, so the row stayed in the list and every press counted another
    // confirmation in the session. One row could be confirmed five times.
    const session = (_auditSession && (_auditSession.scope || '') === (scope || '')) ? _auditSession : null;
    const answered = (session && session.answered) || (_audit && _audit.answered) || {};
    for (const it of _auditItems()) {
        if (answered[String(it.id)]) continue;
        if (typeof isInventoryDepleted === 'function' && isInventoryDepleted(it)) continue;
        const hay = _auditFold(`${it.display_name || ''} ${it.name || ''} ${it.brand || ''}`);
        if (!hay.includes(q)) continue;
        if (!scope || normalizeLocationKey(it.location) === scope) here.push(it);
        else elsewhere.push(it);
    }
    return {
        here: here.slice(0, AUDIT_SEARCH_MAX_HERE),
        elsewhere: elsewhere.slice(0, AUDIT_SEARCH_MAX_ELSEWHERE),
    };
}

/**
 * One hit of the search list.
 *
 * The row opens with the PLACE MARK — the one fact that matters here: ✓ it is where you are
 * inventorying, ✕ it is not — and the action on the right answers the same question: «✓ C'è» (the
 * quick confirmation, no card) for a row that belongs here, «Sposta qui» (a neutral correction)
 * for one that does not. The tap on the name/thumbnail still opens the full card.
 */
function _auditSearchRowHtml(it, scope) {
    const locKey = normalizeLocationKey(it.location);
    const inScope = !scope || locKey === scope;
    const placeLabel = _auditScopeLabel(scope);
    const loc = (typeof LOCATIONS !== 'undefined' && LOCATIONS[locKey])
        || { icon: '📦', label: it.location || '' };
    const qty = (typeof stripHtml === 'function' && typeof formatQuantity === 'function')
        ? stripHtml(formatQuantity(it.quantity, it.unit, it.default_quantity, it.package_unit))
        : `${it.quantity || ''} ${it.unit || ''}`.trim();
    const thumb = (typeof _productThumbHtml === 'function') ? _productThumbHtml(it) : '';
    const mark = inScope
        ? `<span class="audit-hit-mark is-here" title="${escapeAttr(t('audit.mark_here', { place: placeLabel || loc.label || '' }))}" aria-hidden="true">✓</span>`
        : `<span class="audit-hit-mark is-elsewhere" title="${escapeAttr(t('audit.mark_elsewhere', { place: placeLabel }))}" aria-hidden="true">✕</span>`;
    const action = inScope
        ? `<button type="button" class="audit-hit-quick" onclick="_auditSearchQuickThere(${Number(it.id)})"><span aria-hidden="true">✓</span> ${escapeHtml(t('audit.there_is'))}</button>`
        : `<button type="button" class="audit-hit-move" onclick="_auditSearchMoveHere(${Number(it.id)})">${escapeHtml(t('audit.move_here'))}</button>`;
    return `<div class="audit-hit${inScope ? '' : ' is-elsewhere'}">
            <button type="button" class="audit-hit-open" onclick="_auditSearchPick(${Number(it.id)})">
                ${mark}
                <span class="audit-hit-thumb">${thumb}</span>
                <span class="audit-hit-text">
                    <span class="audit-hit-name">${escapeHtml(it.display_name || it.name || '')}</span>
                    <span class="audit-hit-meta">${escapeHtml(loc.icon || '📍')} ${escapeHtml(loc.label || '')} · ${escapeHtml(qty)}</span>
                </span>
            </button>
            ${action}
        </div>`;
}

function _auditRenderSearchResults() {
    const box = document.getElementById('audit-search-results');
    if (!box) return;
    const term = String(_auditSearchTerm || '').trim();
    if (!term) { box.innerHTML = ''; return; }
    const { here, elsewhere } = _auditSearchMatches(term);
    if (!here.length && !elsewhere.length) {
        box.innerHTML = `<p class="audit-hint">${escapeHtml(t('audit.search_none', { term }))}</p>
            <button type="button" class="audit-scope audit-search-create" onclick="_auditSearchCreate()">
                <span aria-hidden="true">➕</span> ${escapeHtml(t('audit.search_create', { term }))}</button>`;
        return;
    }
    const scope = _auditScopeKey();
    const placeLabel = _auditScopeLabel(scope);
    // The chosen place comes first, and it SAYS so: a list that quietly reorders itself is a list
    // the household has to re-read every time.
    const head = scope
        ? `<p class="audit-hint audit-search-scope"><span aria-hidden="true">📍</span> ${escapeHtml(t('audit.search_scope', { place: placeLabel }))}</p>`
        : '';
    const otherHead = elsewhere.length
        ? `<p class="audit-hint audit-search-scope"><span aria-hidden="true">📦</span> ${escapeHtml(t('audit.search_elsewhere'))}</p>`
        : '';
    box.innerHTML = head
        + here.map(it => _auditSearchRowHtml(it, scope)).join('')
        + otherHead
        + elsewhere.map(it => _auditSearchRowHtml(it, scope)).join('');
}

/** Enter in the field: the first row of the place you are inventorying is the one you meant. */
function _auditSearchPickFirst() {
    const { here, elsewhere } = _auditSearchMatches(_auditSearchTerm);
    const first = here[0] || elsewhere[0];
    if (first) _auditSearchPick(first.id);
    else _auditRenderSearchResults();
}

/**
 * The quick answer: «✓ C'è» straight from the list, without opening the card.
 *
 * It is the same "there" answer the card records (nothing is written, the row is right) through the
 * same one-item run, so the toast and the return to the search stay one implementation.
 */
function _auditSearchQuickThere(invId) {
    // Pressed twice on the same product (a slow phone, a double tap, or the row still on screen):
    // the second press answers nothing, it only redraws. The session is the source of truth.
    const scope = _auditScopeKey();
    const session = (_auditSession && (_auditSession.scope || '') === (scope || '')) ? _auditSession : null;
    const answered = (session && session.answered) || (_audit && _audit.answered) || {};
    if (answered[String(invId)]) {
        _auditRenderSearchResults();
        return;
    }
    _auditSearchPick(invId);
    if (!_audit || _audit.finished) return;
    _auditAnswer('there');
}

/**
 * Bring a row of another shelf into the place being inventoried — the ✕ row's one action.
 *
 * `inventory_update` with the new location is the same write the edit sheet performs (it keeps the
 * ledger-neutral move trail), the live caches are patched so the row flips to ✓ at once, and the
 * pantry behind the overlay is re-read so the place you opened agrees.
 */
async function _auditSearchMoveHere(invId) {
    const scope = _auditScopeKey();
    if (!scope) return;
    const it = _auditItems().find(r => Number(r.id) === Number(invId));
    if (!it) return;
    const placeLabel = _auditScopeLabel(scope);
    let res = null;
    try {
        res = await api('inventory_update', {}, 'POST', { id: it.id, location: scope });
    } catch (_e) {
        showToast(t('audit.move_failed'), 'error');
        return;
    }
    if (!res || res.success === false) {
        const msg = (typeof _apiErrorText === 'function')
            ? _apiErrorText(res || {})
            : ((res && (res.message || res.error)) || t('audit.move_failed'));
        showToast(msg, 'error');
        return;
    }
    if (typeof _patchLiveInventoryItem === 'function') _patchLiveInventoryItem(it.id, { location: scope });
    if (_audit && !_audit.finished) {
        _audit.items.forEach(row => { if (row.id == it.id) row.location = scope; });
    }
    showToast(t('audit.move_done', { place: placeLabel }), 'success');
    _auditRenderSearchResults();
    if (typeof loadInventory === 'function') {
        loadInventory().then(() => _auditRenderSearchResults()).catch(() => { /* the patch already shows it */ });
    }
}

/**
 * One question about one product — the shape «Ti dico io» and the barcode share.
 *
 * The counters, the answers and the undo history belong to the SESSION, so a card opened by name or
 * by a scan is as much part of the same stocktake as a proposed one. `label` feeds the card's
 * eyebrow (the searched term, the scanned product).
 */
function _auditOneCardView(it, mode, label) {
    const scope = _auditScopeKey();
    const session = _auditSessionFor(scope);
    // The card belongs to the way it came in: closing it must return to THAT sheet (and the next
    // open of the overlay remembers it).
    _auditMode = mode === 'scan' ? 'scan' : 'search';
    try { localStorage.setItem(AUDIT_MODE_KEY, _auditMode); } catch (_e) { /* private mode */ }
    _audit = {
        items: [it],
        index: 0,
        queue: [],
        errors: 0,
        counts: session.counts,
        answered: session.answered,
        // The undo chain belongs to THIS question: the run's is the propose view's, not this one.
        history: [],
        // Keep the shelf this stocktake is about: going back to the sheet must not lose it.
        scope: scope || null,
        order: [it.id],
        startedAt: session.startedAt,
        finished: false,
        flushPromise: null,
        mode,
        term: label || '',
    };
    _auditRender();
}

/** Tap a pantry row → the same card as the other mode, for that one product. */
function _auditSearchPick(invId) {
    const it = _auditItems().find(r => Number(r.id) === Number(invId));
    if (!it) return;
    _auditOneCardView(it, 'search', String(_auditSearchTerm || '').trim());
}

/** Nothing matched at all: create it (the product form, with the typed name already in). */
function _auditSearchCreate() {
    const term = String(_auditSearchTerm || '').trim();
    _auditStop(false);
    if (typeof startManualEntry !== 'function') return;
    startManualEntry();
    const nameEl = document.getElementById('pf-name');
    if (nameEl) nameEl.value = term;
    if (typeof autoDetectCategory === 'function') { try { autoDetectCategory(); } catch (_e) { /* optional */ } }
}

/** Back to the search from a single-product card, with the term still there. */
function _auditSearchBack() {
    if (_audit && !_audit.finished) _auditFlush();
    _audit = null;
    _auditStart();
}

/* ── Mode 3: the barcode, on its OWN camera ────────────────────────────────────
 * A stocktake is a procedure performed in front of a shelf, not the shopping trip: this mode opens
 * its own stream on its own overlay and shares NO state with the scanner used for shopping,
 * medicines, receipts or the AI path (`_spesaMode`, the scan page, its panels and its identifier
 * never run here). What it DOES share is the decoding engine (`_ensureBarcodeEngines` +
 * `_scanDetectFrame`) and the catalogue lookup (`_resolveBarcodeLookup`): a second copy of either
 * would read the same code differently.
 *
 * The flow is the shortest one that works: choosing the mode opens the stream, the first code read
 * closes it and opens the same card the other two ways use, and answering that card opens the
 * stream again on the next box.
 */

/** The sheet behind the camera: the way out of a scan that is not going anywhere. */
function _auditScanBodyHtml() {
    const scope = _auditScopeKey();
    const place = _auditScopeLabel(scope);
    return `<p class="audit-hint">${escapeHtml(t('audit.scan_hint'))}</p>
        ${place ? `<p class="audit-hint audit-search-scope-note"><span aria-hidden="true">📍</span> ${escapeHtml(t('audit.search_in_place', { place }))}</p>` : ''}
        <button type="button" class="audit-done audit-scan-open" onclick="_auditScanStart()"><span aria-hidden="true">📷</span> ${escapeHtml(t('audit.scan_open'))}</button>`;
}

/** The overlay the stream lives in: built once, reused, never inside the audit panel. */
function _auditScanOverlay() {
    let el = document.getElementById('audit-scan-overlay');
    if (el) return el;
    el = document.createElement('div');
    el.id = 'audit-scan-overlay';
    el.className = 'audit-scan-overlay';
    el.hidden = true;
    el.innerHTML = `<video id="audit-scan-video" playsinline muted autoplay aria-hidden="true"></video>
        <div class="audit-scan-frame" aria-hidden="true"></div>
        <div class="audit-scan-chrome">
            <div class="audit-scan-head">
                <div class="audit-scan-titles">
                    <p class="audit-scan-eyebrow" id="audit-scan-eyebrow"></p>
                    <p class="audit-scan-title">${escapeHtml(t('audit.scan_finder_title'))}</p>
                </div>
                <button type="button" class="audit-scan-close" id="audit-scan-close" aria-label="${escapeAttr(t('audit.close') || 'Close')}">✕</button>
            </div>
            <p class="audit-scan-status" id="audit-scan-status" role="status" aria-live="polite"></p>
        </div>`;
    document.body.appendChild(el);
    const close = el.querySelector('#audit-scan-close');
    if (close) close.addEventListener('click', () => _auditScanStop(true));
    return el;
}

function _auditScanStatus(text) {
    const el = document.getElementById('audit-scan-status');
    if (el) el.textContent = text || '';
}

/** The tally of the three ways, printed under the viewfinder: proof the scan counts too. */
function _auditScanTallyText() {
    const c = (_auditSession && _auditSession.counts) || _auditEmptyCounts();
    const bits = [];
    if (c.there) bits.push(`✓ ${c.there}`);
    if (c.finished) bits.push(`🏁 ${c.finished}`);
    if (c.spoiled) bits.push(`🗑️ ${c.spoiled}`);
    if (c.skipped) bits.push(`⏭️ ${c.skipped}`);
    return bits.join(' · ');
}

/**
 * Open the stocktake's own camera.
 *
 * The stream starts immediately — the household is standing in front of the shelf — and the audit
 * panel goes away, because the viewfinder must fill the screen. The session stays: the tally keeps
 * counting whatever the next answer comes from.
 */
async function _auditScanStart() {
    if (typeof navigator === 'undefined' || !navigator.mediaDevices
        || typeof navigator.mediaDevices.getUserMedia !== 'function') {
        showToast(t('error.camera'), 'error');
        return;
    }
    _auditSetScope(_auditScopeKey());
    _auditMode = 'scan';
    try { localStorage.setItem(AUDIT_MODE_KEY, 'scan'); } catch (_e) { /* private mode */ }
    _auditPersist();
    if (_audit && !_audit.finished) _auditFlush();
    _audit = null;
    const panel = document.getElementById('audit-overlay');
    if (panel) {
        panel.hidden = true;
        panel.innerHTML = '';
    }
    const el = _auditScanOverlay();
    el.hidden = false;
    _auditScanActive = true;
    const eyebrow = document.getElementById('audit-scan-eyebrow');
    const place = _auditScopeLabel(_auditScopeKey());
    if (eyebrow) eyebrow.textContent = place ? t('audit.verify_place', { place }) : t('audit.title');
    _auditScanStatus(_auditScanTallyText() || t('audit.scan_finder_hint'));

    const gen = ++_auditScanGen;
    const video = document.getElementById('audit-scan-video');
    let constraints = { video: { facingMode: 'environment' } };
    try {
        if (typeof getCameraConstraints === 'function') constraints = getCameraConstraints();
    } catch (_e) { /* settings not loaded: the default above is enough */ }
    try {
        const stream = await navigator.mediaDevices.getUserMedia(constraints);
        // Closed (or answered) while the camera was waking up: never leave a track running.
        if (gen !== _auditScanGen) {
            stream.getTracks().forEach(tr => { try { tr.stop(); } catch (_e) { /* gone */ } });
            return;
        }
        _auditScanStream = stream;
        if (video) {
            video.srcObject = stream;
            video.muted = true;
            video.setAttribute('playsinline', '');
            video.setAttribute('webkit-playsinline', '');
            try { await video.play(); } catch (_e) { /* the loop waits for a frame */ }
        }
        if (typeof _ensureBarcodeEngines === 'function') await _ensureBarcodeEngines();
        if (gen !== _auditScanGen) return;
        _auditScanLoop(gen);
    } catch (err) {
        if (gen !== _auditScanGen) return;
        _auditScanStatus(t('error.camera'));
        showToast(t('error.camera'), 'error');
        // The sheet comes back with the reason on it, instead of a black screen.
        _auditScanStop(true);
    }
}

function _auditScanLoop(gen) {
    clearTimeout(_auditScanTimer);
    _auditScanTimer = setTimeout(() => { _auditScanTick(gen); }, 180);
}

/** One frame, one read. Exactly one pass lives at a time (`_auditScanGen`). */
async function _auditScanTick(gen) {
    if (gen !== _auditScanGen || !_auditScanActive) return;
    const video = document.getElementById('audit-scan-video');
    if (!video || !video.videoWidth) {
        _auditScanLoop(gen);
        return;
    }
    const frame = (typeof _scanDetectFrame === 'function') ? _scanDetectFrame(video) : null;
    let code = '';
    if (frame) code = await _auditScanReadFrame(frame.canvas);
    if (gen !== _auditScanGen) return;
    if (code) {
        _auditScanHit(code);
        return;
    }
    _auditScanLoop(gen);
}

/** The engines the app already ships (native first, ZBar after): no second decoder here. */
async function _auditScanReadFrame(canvas) {
    const native = (typeof _detectorNative !== 'undefined') ? _detectorNative : null;
    const zbar = (typeof _detectorZbar !== 'undefined') ? _detectorZbar : null;
    if (native) {
        try {
            const codes = await native.detect(canvas);
            if (codes && codes.length && codes[0].rawValue) return String(codes[0].rawValue);
        } catch (_e) { /* this canvas is unreadable for it: ZBar gets the same frame */ }
    }
    if (zbar) {
        try {
            const codes = await zbar.detect(canvas);
            if (codes && codes.length && codes[0].rawValue) return String(codes[0].rawValue);
        } catch (_e) { /* nothing to read in this frame */ }
    }
    return '';
}

/**
 * Close the camera. `reopen` brings the audit sheet back (the ✕, Esc, a failure, the phone locking):
 * the household is never dropped out of the stocktake with no way back in.
 */
function _auditScanStop(reopen = true) {
    _auditScanGen++;
    clearTimeout(_auditScanTimer);
    _auditScanActive = false;
    const video = document.getElementById('audit-scan-video');
    if (video) {
        try { video.pause(); } catch (_e) { /* already stopped */ }
        video.srcObject = null;
    }
    if (_auditScanStream) {
        try { _auditScanStream.getTracks().forEach(tr => { try { tr.stop(); } catch (_e) { /* gone */ } }); } catch (_e) { /* gone */ }
        _auditScanStream = null;
    }
    const el = document.getElementById('audit-scan-overlay');
    if (el) el.hidden = true;
    if (reopen) _auditStart();
}

/** A phone that locks, a tab that hides: the stream stops and the sheet says where we were. */
document.addEventListener('visibilitychange', () => {
    if (document.hidden && _auditScanActive) _auditScanStop(true);
});

/**
 * A code was read: the camera closes and the ONE card of the stocktake opens.
 *
 * The lookup is the app's own catalogue resolution (`_resolveBarcodeLookup`, cached) — never the
 * shopping pipeline, never the medicine wizard, never an AI identification. Only a product with
 * usable stock is a stocktake row; anything else keeps the camera running with a line that says so,
 * because a stocktake must not stop on a box the ledger does not know.
 */
async function _auditScanHit(code) {
    const clean = String(code || '').trim();
    if (!clean) return;
    _auditScanGen++;                 // this read owns the camera: no frame is read meanwhile
    clearTimeout(_auditScanTimer);
    if (navigator.vibrate) { try { navigator.vibrate(80); } catch (_e) { /* ignored */ } }
    _auditScanStatus(t('audit.scan_finder_found'));
    let product = null;
    try {
        const result = (typeof _resolveBarcodeLookup === 'function')
            ? await _resolveBarcodeLookup(clean)
            : await api('resolve_barcode', { barcode: clean });
        product = (result && result.found && result.product) ? result.product : null;
    } catch (_e) {
        product = null;
    }
    if (!_auditScanActive) return;
    if (!product || !product.id) {
        _auditScanStatus(t('audit.scan_no_match'));
        // Back to work: the next frame may be the one in focus.
        _auditScanLoop(++_auditScanGen);
        return;
    }
    const row = (typeof _inventoryRowForProduct === 'function')
        ? _inventoryRowForProduct(product.id)
        : (_auditItems().find(r => Number(r.product_id) === Number(product.id)) || null);
    if (!row) {
        showToast(t('audit.scan_not_stocked', { name: product.display_name || product.name || '' }), 'info', 6000);
        _auditScanStatus(t('audit.scan_finder_hint'));
        _auditScanLoop(++_auditScanGen);
        return;
    }
    // The answer is here: camera off, card on.
    _auditScanStop(false);
    _auditScanCard(row);
}

/**
 * The card of the scanned box: the same one the other two ways open.
 *
 * A product can stand in two places (a fridge pack and a pantry pack): the row of the place being
 * inventoried wins, which is the row the household is standing in front of.
 */
function _auditScanCard(row) {
    const scope = _auditScopeKey();
    const rows = _auditItems().filter(r => Number(r.product_id) === Number(row.product_id));
    const preferred = rows.find(r => !scope || normalizeLocationKey(r.location) === scope) || rows[0] || row;
    const fresh = _auditItems().find(r => Number(r.id) === Number(preferred.id)) || preferred;
    _auditOneCardView(fresh, 'scan', fresh.display_name || fresh.name || '');
}

/** The card offers the camera as the third way in — and the camera opens at once. */
function _auditSwitchToScan() {
    _auditSetScope(_auditScopeKey());
    if (_audit && !_audit.finished) _auditStop(false);
    _auditMode = 'scan';
    try { localStorage.setItem(AUDIT_MODE_KEY, 'scan'); } catch (_e) { /* ignore */ }
    _auditScanStart();
}

/** Mode 1's card offers the other way in without losing the run (closing keeps the point). */
function _auditSwitchToSearch() {
    // The scope of the run is the place the search list must put FIRST: capture it before the run
    // is dropped for the search sheet.
    _auditSetScope(_auditScopeKey());
    if (_audit && !_audit.finished) _auditStop(false);
    _auditMode = 'search';
    try { localStorage.setItem(AUDIT_MODE_KEY, 'search'); } catch (_e) { /* ignore */ }
    _auditStart();
}

function _auditBegin(location, saved = null) {
    // The chosen scope is the place the search mode sorts by, so it outlives this run.
    _auditSetScope(location || '');
    const sameScope = !!_auditSession && (_auditSession.scope || '') === (location || '');
    // A resumed run rebuilds the SESSION (the tally and the answers it already has); a new one
    // continues the session of this same place instead of zeroing it.
    if (saved) {
        _auditSession = {
            scope: saved.scope || '',
            startedAt: saved.startedAt || Date.now(),
            counts: { ..._auditEmptyCounts(), ...(saved.counts || {}) },
            answered: (saved.answered && typeof saved.answered === 'object') ? { ...saved.answered } : {},
            history: Array.isArray(saved.history) ? saved.history.slice() : [],
            order: Array.isArray(saved.order) ? saved.order.slice() : [],
            index: 0,
        };
    } else {
        _auditSessionFor(location);
    }
    const session = _auditSession;
    // Same comparison on both sides: a row may store the slug while the scope is a label.
    const scopeRows = saved
        ? null
        : _auditItems().filter(it => !location || normalizeLocationKey(it.location) === location);
    // Continuing the same place never asks twice: what this stocktake already answered by hand, by
    // name or by barcode stays answered.
    const items = saved
        ? saved.items
        : (sameScope ? scopeRows.filter(it => !session.answered[String(it.id)]) : scopeRows);
    _audit = {
        items,
        index: 0,
        queue: [],
        errors: 0,
        // The three ways share these three: one stocktake, one tally, one undo.
        counts: session.counts,
        answered: session.answered,
        history: session.history,
        scope: saved ? (saved.scope || null) : (location || null),
        order: saved && Array.isArray(saved.order) ? saved.order.slice() : items.map(it => it.id),
        startedAt: session.startedAt,
        finished: false,
        flushPromise: null,
        mode: 'propose',
    };
    if (!_audit.items.length) {
        // This place is already fully answered (by any of the three ways): the summary is the
        // answer, not an empty wizard.
        if (sameScope && scopeRows && scopeRows.length) {
            _audit = null;
            _auditFinish();
            return;
        }
        // Nothing matched the scope (a row moved between the banner and the tap): say it
        // instead of opening an empty wizard.
        _audit = null;
        _auditStop();
        showToast(t('audit.empty'), 'info');
        return;
    }
    session.order = _audit.order.slice();
    session.index = 0;
    _auditPersist();
    _auditRender();
}

/**
 * Back to the card of the live session — where the propose run had stopped.
 *
 * This is the door the other two ways use to return: after «✓ C'è» on a searched product, after a
 * scanned box, or when the household taps «Uno alla volta» again. The session's `order`/`index` is
 * what makes the return exact; a row that left the pantry meanwhile simply drops out.
 */
function _auditSessionView() {
    const session = _auditSession;
    if (!session || !session.order.length) return false;
    const byId = new Map(_auditItems().map(it => [String(it.id), it]));
    const items = session.order.map(id => byId.get(String(id))).filter(Boolean);
    if (!items.length) {
        session.order = [];
        return false;
    }
    let index = Math.min(Math.max(0, session.index | 0), items.length);
    while (index < items.length && session.answered[String(items[index].id)]) index++;
    _audit = {
        items,
        index,
        queue: [],
        errors: 0,
        counts: session.counts,
        answered: session.answered,
        history: session.history,
        scope: session.scope || null,
        order: items.map(it => it.id),
        startedAt: session.startedAt,
        finished: false,
        flushPromise: null,
        mode: 'propose',
    };
    if (index >= items.length) {
        // Every card was answered (by hand, by name or by barcode): the summary is the way out.
        _auditFinish();
        return true;
    }
    if (_auditMode !== 'propose') {
        _auditMode = 'propose';
        try { localStorage.setItem(AUDIT_MODE_KEY, 'propose'); } catch (_e) { /* ignore */ }
    }
    _auditRender();
    return true;
}

/** The «↩ Torna alla card» door of the sheets. */
function _auditResumeCard() {
    if (_auditSessionView()) return;
    _auditResume();
}

/**
 * The stocktake card: one primary answer, four exceptions, nothing else.
 *
 * The old card said the same thing four times (heading, a giant quantity badge, a spoken
 * sentence and again inside the primary button) and offered eight stacked commands in three
 * different colour languages (solid red, dashed purple, amber). A stocktake is read in half a
 * second and answered in one tap, so this is the whole hierarchy:
 *
 *   1. the product (photo, name, brand, place, stock on record) — facts, once;
 *   2. ONE emerald primary: `✓ Confermo {qty}`;
 *   3. a neutral 2×2 for when something is off (finished / spoiled / edit / move);
 *   4. one quiet link in the footer: «Salta» — a card the household cannot answer (a product
 *      that is not there, a shelf it does not want to touch) moves on without writing anything.
 *
 * The progress line is a real bar: it fills on the card you are LOOKING AT (`done + 1`), so the
 * first of fifteen shows ~7% instead of an empty grey line.
 */
function _auditRender() {
    const el = document.getElementById('audit-overlay');
    if (!el) return;
    if (!_audit) {
        el.hidden = true;
        el.innerHTML = '';
        return;
    }
    const it = _audit.items[_audit.index];
    if (!it) {
        _auditFinish();
        return;
    }
    const isSearch = _audit.mode === 'search';
    const isScan = _audit.mode === 'scan';
    // One question about one product: no progress bar, no «Salta» — the way out is another product.
    const isOne = isSearch || isScan;
    const total = _audit.items.length;
    const done = _audit.index;
    const percent = Math.max(0, Math.min(100, Math.round(((done + 1) / total) * 100)));
    el.hidden = false;
    const qtyPlain = (typeof stripHtml === 'function' && typeof formatQuantity === 'function')
        ? stripHtml(formatQuantity(it.quantity, it.unit, it.default_quantity, it.package_unit))
        : `${it.quantity || ''} ${it.unit || ''}`.trim();
    const countLabel = t('audit.count_of', { n: done + 1, total });
    const locMeta = (typeof LOCATIONS !== 'undefined' && LOCATIONS[normalizeLocationKey(it.location)])
        || { icon: '📦', label: it.location || '' };
    const placeLabel = locMeta.label || it.location || '';
    const placeTitle = t('audit.verify_place', { place: placeLabel || t('audit.title') });
    const thumb = (typeof _productThumbHtml === 'function') ? _productThumbHtml(it) : '';
    // The date is part of the question: "confermo 1500 ml in Frigo" without "che scade il …"
    // is half an answer. A missing date says so instead of leaving the family guessing.
    const hasExpiry = !!it.expiry_date;
    const expiryDate = hasExpiry && typeof formatDate === 'function' ? formatDate(it.expiry_date) : '';
    const isExpired = hasExpiry && typeof daysUntilExpiry === 'function' && daysUntilExpiry(it.expiry_date) < 0;
    const expiryFact = hasExpiry
        ? t(isExpired ? 'audit.expiry_was' : 'audit.expiry_on', { date: expiryDate })
        : t('audit.expiry_none');
    // The primary carries the whole question; `_auditFitConfirm()` swaps in the short one when
    // the sentence needs more than two lines (a phone, a big font, a long place name).
    const confirmFull = hasExpiry
        ? t(isExpired ? 'audit.confirm_sentence_expired' : 'audit.confirm_sentence',
            { qty: qtyPlain, place: placeLabel, date: expiryDate })
        : t('audit.confirm_sentence_no_expiry', { qty: qtyPlain, place: placeLabel });
    const confirmShort = t('audit.confirm_yes_qty', { qty: qtyPlain });
    el.innerHTML = `<div class="audit-panel" role="dialog" aria-modal="true" aria-label="${escapeAttr(t('audit.title'))}">
        <div class="audit-top">
            ${isOne ? '' : `<div class="audit-progress" role="progressbar" aria-valuemin="0" aria-valuemax="${total}"
                 aria-valuenow="${done + 1}" aria-valuetext="${escapeAttr(countLabel)}">
                <span style="width:${percent}%"></span>
            </div>
            <p class="audit-count">${escapeHtml(countLabel)}</p>`}
            <button type="button" class="audit-close" onclick="_auditStop()" aria-label="${escapeAttr(t('audit.close'))}">✕</button>
        </div>
        <p class="audit-eyebrow">${escapeHtml(isSearch
            ? t('audit.search_badge', { term: _audit.term || '' })
            : placeTitle)}</p>
        <div class="audit-product-row">
            <div class="audit-product-thumb">${thumb}</div>
            <div class="audit-product-main">
                <p class="audit-product-name">${escapeHtml(it.display_name || it.name || '')}</p>
                ${it.brand ? `<p class="audit-product-brand">${escapeHtml(it.brand)}</p>` : ''}
                <p class="audit-product-meta"><span aria-hidden="true">${escapeHtml(locMeta.icon || '📍')}</span> ${escapeHtml(placeLabel)} · <span aria-hidden="true">📦</span> ${escapeHtml(t('audit.stock_label'))}: <strong>${escapeHtml(qtyPlain)}</strong> · <span aria-hidden="true">⏳</span> <span class="audit-expiry${isExpired ? ' is-expired' : ''}">${escapeHtml(expiryFact)}</span></p>
            </div>
        </div>
        <button type="button" class="audit-there" onclick="_auditAnswer('there')"><span aria-hidden="true">✓</span> <span class="audit-there-full">${escapeHtml(confirmFull)}</span><span class="audit-there-short">${escapeHtml(confirmShort)}</span></button>
        <div class="audit-grid">
            <button type="button" class="audit-cell audit-finished" onclick="_auditAnswer('finished')"><span aria-hidden="true">🏁</span> ${escapeHtml(t('audit.exhausted'))}</button>
            <button type="button" class="audit-cell audit-spoiled" onclick="_auditAnswer('spoiled')"><span aria-hidden="true">🗑️</span> ${escapeHtml(t('audit.spoiled'))}</button>
            <button type="button" class="audit-cell audit-edit" onclick="_auditEdit('quantity')"><span aria-hidden="true">✏️</span> ${escapeHtml(t('audit.change_qty'))}</button>
            <button type="button" class="audit-cell audit-edit" onclick="_auditEdit('location')"><span aria-hidden="true">📍</span> ${escapeHtml(t('audit.change_location'))}</button>
        </div>
        ${_auditScaleReady(it) ? `<button type="button" class="audit-scale-pill" onclick="_auditScale()"><span aria-hidden="true">⚖️</span> ${escapeHtml(t('audit.read_scale'))}</button>` : ''}
        <div class="audit-foot">
            ${_audit.history.length
                ? `<button type="button" class="audit-back" onclick="_auditUndo()">↩ ${escapeHtml(t('audit.undo_last'))}</button>`
                : `<div class="audit-foot-links">
                    <button type="button" class="audit-back" onclick="_auditSwitchToSearch()">🔎 ${escapeHtml(t('audit.mode_search'))}</button>
                    <button type="button" class="audit-back" onclick="_auditSwitchToScan()">📷 ${escapeHtml(t('audit.mode_scan'))}</button>
                </div>`}
            ${isSearch
                ? `<button type="button" class="audit-skip" onclick="_auditSearchBack()">🔎 ${escapeHtml(t('audit.search_another'))}</button>`
                : (isScan
                    ? ''
                    : `<button type="button" class="audit-skip" onclick="_auditAnswer('skip')">${escapeHtml(t('audit.skip'))} →</button>`)}
        </div>
    </div>`;
    // The full question is the default; on a narrow screen (or a long place name) it becomes
    // the short one instead of growing into a paragraph.
    _auditFitConfirm(el);
}

/**
 * Keep the primary answer readable: the full sentence ("Confermi 1500 ml in Frigo che scade il
 * 05/11/2026?") must fit in at most two lines. It is MEASURED, not guessed from a breakpoint:
 * the same card is a phone, a wall tablet and a kiosk, and the household's language decides how
 * long the sentence gets.
 */
function _auditFitConfirm(root) {
    const btn = (root || document).querySelector('.audit-there');
    if (!btn) return;
    const full = btn.querySelector('.audit-there-full');
    if (!full) return;
    btn.classList.remove('is-compact');
    const style = window.getComputedStyle(full);
    const fontSize = parseFloat(style.fontSize) || 16;
    const lineHeight = parseFloat(style.lineHeight) || fontSize * 1.3;
    const lines = Math.round(full.getBoundingClientRect().height / lineHeight);
    if (lines > 2) btn.classList.add('is-compact');
}

/**
 * Open the normal inventory edit sheet on top of the stocktake — the audit
 * overlay stays underneath, so the run is not lost — and bring the field the
 * user asked for into view: the quantity (manual or from the scale, exactly as
 * the sheet already offers) or the location selector.
 */
function _auditEdit(mode) {
    if (!_audit || _audit.finished) return;
    const it = _audit.items[_audit.index];
    if (!it || typeof editInventoryItem !== 'function') return;
    editInventoryItem(it.id);
    const groupId = mode === 'location' ? 'edit-loc-group' : 'edit-qty-group';
    const focus = (tries = 0) => {
        const group = document.getElementById(groupId);
        if (!group) {
            // The sheet is drawn after a fetch when the row is not cached yet.
            if (tries < 20) setTimeout(() => focus(tries + 1), 50);
            return;
        }
        // The stocktake only needs the two corrections: the recipe shortcut would
        // open behind the run and dead-end it.
        document.querySelectorAll('#modal-content .btn-recipe-from-ingredient')
            .forEach(btn => { btn.style.display = 'none'; });
        try {
            group.scrollIntoView({ block: 'center', behavior: 'smooth' });
        } catch (_e) {
            group.scrollIntoView();
        }
        group.classList.add('audit-edit-focus');
        setTimeout(() => group.classList.remove('audit-edit-focus'), 1600);
        if (mode !== 'location') {
            const input = document.getElementById('edit-qty');
            if (input) { input.focus(); input.select(); }
        }
    };
    setTimeout(() => focus(), 30);
}

/**
 * The edit sheet saved a change over the current card: refresh what the sentence
 * shows from the live caches. A row that is no longer in the pantry (quantity 0,
 * or removed) drops out of the run instead of staying on screen as a ghost.
 */
function _auditItemSaved(id) {
    if (!_audit || _audit.finished) return;
    const idx = _audit.items.findIndex(it => it.id == id);
    if (idx < 0) return;
    const fresh = (typeof _findInventoryItem === 'function') ? _findInventoryItem(id) : null;
    if (fresh) {
        _audit.items[idx] = { ..._audit.items[idx], ...fresh };
    } else {
        _audit.items.splice(idx, 1);
        if (idx < _audit.index) _audit.index--;
    }
    _auditPersist();
    _auditRender();
}

/**
 * Register one answer and move on.
 *  - "there" writes nothing: the row is right;
 *  - "finished" is the real "usa tutto": `inventory_use` + use_all, so consumption
 *    stats, the shopping list and the depleted-product rules all react;
 *  - "spoiled" is the real "butta tutto": the same call with a waste reason, so it
 *    is counted as waste (and feeds the waste-learning hints).
 *
 * There is deliberately NO "quasi finito" answer: it wrote a quantity the household never said
 * (100 g, half a pack) and looked like a third way to confirm. Whoever has a little left says
 * the real amount through «Modifica quantità», and the shopping list follows the shelf.
 */
function _auditAnswer(kind) {
    if (!_audit || _audit.finished) return;
    const it = _audit.items[_audit.index];
    if (!it) return;
    // ONE answer per row per stocktake. The same product can reach a card twice — the barcode of a
    // box already answered, a row reopened from the queue — and a second answer would count twice in
    // the tally (and, for «finisce»/«butta», queue a second write). The session's `answered` map is
    // the ledger; `_auditUndo()` deletes the entry, so a real correction still goes through.
    if (_audit.answered && _audit.answered[String(it.id)]) {
        _audit.index++;
        _auditRender();
        return;
    }
    const label = it.display_name || it.name;
    const entry = {
        item: { ...it },
        index: _audit.index,
        kind,
        previousQuantity: parseFloat(it.quantity) || 0,
        row: null,
    };
    _audit.answered[String(it.id)] = kind;
    if (kind === 'finished') {
        entry.row = { kind: 'use', product_id: it.product_id, location: it.location, label };
        _audit.queue.push(entry.row);
        _audit.counts.finished++;
    } else if (kind === 'spoiled') {
        entry.row = {
            kind: 'waste',
            product_id: it.product_id,
            location: it.location,
            notes: _wasteNotesForReason('spoiled'),
            label,
        };
        _audit.queue.push(entry.row);
        _audit.counts.spoiled++;
    } else if (kind === 'there') {
        _audit.counts.there++;
    } else {
        _audit.counts.skipped++;
    }
    _audit.history.push(entry);
    if (_audit.queue.length >= _auditBatchSize) _auditFlush();
    _audit.index++;
    _auditPersist();
    _auditRender();
}

/**
 * Step back one card: reopen the last answered item and put the shelf back when
 * that answer already reached the server. A row still sitting in the batch queue
 * is simply dropped; a sent one is reverted (quantity update, or the transaction
 * undo for use-all/throw-all). The run is left untouched if the revert fails.
 */
async function _auditUndo() {
    const state = _audit;
    if (!state || !state.history.length) return;
    // A batch may still be in flight: wait so we know whether this row was sent.
    if (state.flushPromise) {
        try { await state.flushPromise; } catch (_e) { /* counted by the flush */ }
    }
    const entry = state.history[state.history.length - 1];
    const queuedAt = entry.row ? state.queue.indexOf(entry.row) : -1;
    if (queuedAt >= 0) {
        state.queue.splice(queuedAt, 1);
    } else if (entry.row && !(await _auditUndoSent(entry))) {
        showToast(t('audit.undo_failed'), 'error');
        return;
    }
    state.history.pop();
    delete state.answered[String(entry.item.id)];
    if (state.counts[entry.kind] > 0) state.counts[entry.kind]--;
    state.finished = false;
    let idx = state.items.findIndex(x => x.id == entry.item.id);
    if (idx < 0) {
        // The row left the run (finished, or edited away): put its snapshot back
        // where it was, which is right before the card now on screen.
        idx = Math.min(state.index, state.items.length);
        state.items.splice(idx, 0, entry.item);
    }
    state.index = idx;
    _auditPersist();
    _auditRender();
    showToast(t('audit.undo_done'), 'info');
}

/** Revert one already-sent answer; true when the shelf is back as it was. */
async function _auditUndoSent(entry) {
    const row = entry.row;
    // A write the server refused (duplicate, rate limit) changed nothing.
    if (row.applied === false) return true;
    try {
        if (row.kind === 'update') {
            const res = await api('inventory_update', {}, 'POST', {
                id: entry.item.id,
                quantity: entry.previousQuantity,
            });
            return !!(res && res.success !== false);
        }
        if ((row.kind === 'use' || row.kind === 'waste') && row.transactionId) {
            const res = await api('transaction_undo', {}, 'POST', { id: row.transactionId });
            // "already undone" is fine: the stock is back either way.
            return !!(res && (res.success === true || res.already_undone === true));
        }
        // A finish that only confirmed "already empty" changed no stock.
        return !!row.alreadyEmpty;
    } catch (_e) {
        return false;
    }
}

/** Apply the queued answers; a failure is counted, never swallowed silently. */
async function _auditFlush() {
    const state = _audit;
    if (!state) return;
    // An earlier batch may still be in flight: never let the caller (the summary,
    // the undo) believe the writes are done before they are.
    if (!state.queue.length) {
        if (state.flushPromise) await state.flushPromise;
        return;
    }
    const batch = state.queue.splice(0, state.queue.length);
    const send = async () => {
        for (const row of batch) {
            try {
                let res;
                if (row.kind === 'use') {
                    res = await api('inventory_use', {}, 'POST', {
                        product_id: row.product_id,
                        use_all: true,
                        location: row.location,
                    });
                } else if (row.kind === 'waste') {
                    res = await api('inventory_use', {}, 'POST', {
                        product_id: row.product_id,
                        use_all: true,
                        location: row.location,
                        notes: row.notes,
                    });
                } else {
                    res = await api('inventory_update', {}, 'POST', { id: row.id, quantity: row.quantity });
                }
                row.applied = !!(res && res.success !== false);
                if (res && res.transaction_id) row.transactionId = res.transaction_id;
                if (res && res.already_empty) row.alreadyEmpty = true;
                if (!res || res.success === false) state.errors++;
            } catch (_e) {
                row.applied = false;
                state.errors++;
            }
        }
        // Keep the transaction ids with the saved run: they are what makes undo
        // possible after the app is closed and the run resumed.
        if (_audit === state && !state.finished) _auditPersist();
    };
    state.flushPromise = (state.flushPromise || Promise.resolve()).then(send);
    await state.flushPromise;
}

async function _auditFinish() {
    const state = _audit;
    await _auditFlush();
    // ONE question, answered by name or by barcode: back to its sheet (the term still in the field,
    // the camera one tap away) with the answer counted in the same stocktake — never a summary of
    // a run of one.
    if (state && (state.mode === 'search' || state.mode === 'scan')) {
        const last = state.history.length ? state.history[state.history.length - 1] : null;
        const name = (last && (last.item.display_name || last.item.name)) || '';
        const errors = state.errors;
        const wasScan = state.mode === 'scan';
        _audit = null;
        _auditPersist();
        // A scanned answer puts the camera straight back on: one box after another is how a shelf
        // is checked. A searched one returns to the field with the term still in it.
        if (wasScan) _auditScanStart();
        else _auditStart();
        if (errors) showToast(t('audit.summary_errors').replace('{n}', errors), 'warning');
        else if (name) showToast(t('audit.search_saved', { name }), 'success');
        return;
    }
    // The run stays in memory (not on disk) so the summary can undo the last answer; Done drops it.
    if (state) state.finished = true;
    _auditClearPersist();
    _auditRenderSummary({
        counts: state ? state.counts : (_auditSession ? _auditSession.counts : _auditEmptyCounts()),
        errors: state ? state.errors : 0,
        canUndo: !!(state && state.history.length),
    });
}

/**
 * The summary — of a finished run, or of the session from any sheet.
 *
 * It is ONE renderer for both: the tally of the three ways is the same ledger, and «Fatto» closes
 * the stocktake wherever it was opened from.
 */
function _auditRenderSummary({ counts, errors = 0, canUndo = false }) {
    const el = document.getElementById('audit-overlay');
    if (!el) return;
    const c = counts || _auditEmptyCounts();
    el.hidden = false;
    el.innerHTML = `<div class="audit-panel">
        <div class="audit-head">
            <div class="audit-head-text">
                <p class="audit-eyebrow">${escapeHtml(t('audit.title'))}</p>
                <h3>${escapeHtml(t('audit.summary_title'))}</h3>
            </div>
            <button type="button" class="audit-close" onclick="_auditEndSession(true)" aria-label="${escapeAttr(t('audit.close'))}">✕</button>
        </div>
        <p class="audit-summary">${t('audit.summary_line')
            .replace('{there}', c.there)
            .replace('{finished}', c.finished)
            .replace('{spoiled}', c.spoiled)
            .replace('{skipped}', c.skipped)}</p>
        ${errors ? `<p class="audit-hint">${escapeHtml(t('audit.summary_errors').replace('{n}', errors))}</p>` : ''}
        ${canUndo ? `<button type="button" class="audit-undo" onclick="_auditUndo()">↩ ${escapeHtml(t('audit.undo_last'))}</button>` : ''}
        <button type="button" class="audit-done" onclick="_auditEndSession(true)">${escapeHtml(t('audit.done'))}</button>
    </div>`;
}

/** The session's tally, from any sheet («Riepilogo»). */
function _auditShowSummary() {
    const state = _audit;
    _auditRenderSummary({
        counts: _auditSession ? _auditSession.counts : _auditEmptyCounts(),
        errors: (state && state.errors) || 0,
        // Undo needs the view that owns the chain: a sheet has none.
        canUndo: !!(state && !state.finished && state.history.length),
    });
}

/**
 * Close the stocktake for good: the ledger goes, the writes stay.
 *
 * «Fatto» on the summary, its ✕ and the end of the run all land here, so a finished session can
 * never be reopened as a fresh-looking tally.
 */
function _auditEndSession(reload = true) {
    _auditSession = null;
    _auditScanStop(false);
    _auditClearPersist();
    _auditStop(reload);
}

/** Leave the overlay. `reload` refreshes the pantry with what was just written. */
function _auditStop(reload = false) {
    // A camera that outlives the sheet is a stream nobody is looking at: always release it.
    _auditScanStop(false);
    if (_audit && !_audit.finished) {
        // Closing mid-run keeps the point reached: it can be resumed within an hour.
        _auditPersist();
        _auditFlush();
    }
    _audit = null;
    const el = document.getElementById('audit-overlay');
    if (el) {
        el.hidden = true;
        el.innerHTML = '';
    }
    if (reload) loadInventory();
}

// Arrow keys for a tablet with a keyboard attached; a kiosk needs none of this.
// Rotating a tablet (or resizing a window) changes how many lines the question needs: re-fit
// the primary answer instead of leaving the wrong variant on screen until the next card.
let _auditFitTimer = 0;
window.addEventListener('resize', () => {
    if (!_audit) return;
    clearTimeout(_auditFitTimer);
    _auditFitTimer = setTimeout(() => {
        const el = document.getElementById('audit-overlay');
        if (el && !el.hidden) _auditFitConfirm(el);
    }, 120);
});

document.addEventListener('keydown', (ev) => {
    // The stocktake's own camera: Esc closes the stream and brings the sheet back.
    if (_auditScanActive) {
        if (ev.key === 'Escape') {
            ev.preventDefault();
            _auditScanStop(true);
        }
        return;
    }
    if (!_audit) return;
    // The edit sheet sits ON TOP of the stocktake: while it is open the keys
    // belong to the form, never to the card underneath it.
    const modal = document.getElementById('modal-overlay');
    if (modal && modal.style.display === 'flex') return;
    const active = document.activeElement;
    if (active && ['INPUT', 'TEXTAREA', 'SELECT'].includes(active.tagName)) return;
    if (_audit.finished) {
        // The summary stays live so the last answer can still be undone.
        if (ev.key === 'Escape') _auditEndSession(true);
        else if (ev.key === 'Backspace') { ev.preventDefault(); _auditUndo(); }
        return;
    }
    if (ev.key === 'Escape') {
        _auditStop(true);
    } else if (ev.key === 'ArrowRight') {
        ev.preventDefault();
        _auditAnswer('there');
    } else if (ev.key === 'ArrowLeft') {
        ev.preventDefault();
        _auditAnswer('finished');
    } else if (ev.key === 'ArrowUp') {
        ev.preventDefault();
        _auditAnswer('spoiled');
    } else if (ev.key === 'Backspace') {
        ev.preventDefault();
        _auditUndo();
    }
});
