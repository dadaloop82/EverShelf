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
/** Has a run or a mode CHOSEN that place? ('' is a real choice: the whole pantry.) */
let _auditScopePinned = false;
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
/** Is the sheet painting the LIVE viewfinder (the camera lives in the panel body, not full screen)? */
let _auditCamOn = false;
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
    // A place the household deleted must not come back as the scope of a move.
    const known = (key) => !key || (typeof LOCATIONS === 'undefined') || !!LOCATIONS[key];
    // The scope the stocktake itself PINNED (`_auditSetScope`, when a run starts or a mode is
    // entered) outranks the place the PANTRY has open. Reading the pantry's own filter mid-run
    // moved the ledger to another shelf, and a session for a new scope starts at zero: measured in
    // the simulation, «Inventario di Frigo: ✓ 4» became «Inventario di Dispensa: ✓ 1» on the next
    // answer and never went past it again — the report "rimane sempre a uno".
    if (_auditScopePinned && known(_auditScope)) return _auditScope;
    if (typeof currentLocation !== 'undefined' && currentLocation) return normalizeLocationKey(currentLocation);
    if (_auditScope && known(_auditScope)) return _auditScope;
    const stored = _auditStoredScope();
    return known(stored) ? stored : '';
}

/**
 * Remember it, so switching mode (or a search card opened by name) forgets no shelf.
 *
 * Pinning it is the point: from here on the stocktake is about THIS place, whatever the pantry
 * behind the overlay happens to be filtering. Closing the stocktake releases the pin
 * (`_auditStop`), so the next one starts from the place the household is standing in.
 */
function _auditSetScope(key) {
    _auditScope = key ? normalizeLocationKey(key) : '';
    _auditScopePinned = true;
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
 * How far the STOCKTAKE has got, in the one number the three ways share.
 *
 * `total` is the rows this run is about — the place being inventoried, or the whole pantry — and
 * `done` the ones the session answered: the SAME ledger the scope list counts, so «Frigo (37)» and
 * the bar can never disagree.
 *
 * It is deliberately NOT the open card's position, which is the bug that was reported: a card
 * opened by name or by barcode is a run of ONE for the wizard, so its own counter read «1 di 1» on
 * every box the household scanned and never moved. `inHand` adds the card being looked at (while it
 * is still unanswered), so the bar fills on the card in front of you instead of starting empty.
 */
function _auditProgress(inHand = false) {
    const scope = _auditScopeKey();
    const answered = (_auditSession && _auditSession.answered) || {};
    let rows = _auditItems().filter(it => !scope || normalizeLocationKey(it.location) === scope);
    // The pantry cache may not have arrived yet (a stocktake opened right after boot): the run in
    // hand is then the only truthful list, so the bar keeps counting on it instead of vanishing.
    if (!rows.length && _audit && (_audit.items || []).length) rows = _audit.items;
    const done = rows.filter(it => answered[String(it.id)]).length;
    const total = Math.max(rows.length, done);
    const shown = Math.max(0, Math.min(total, done + (inHand ? 1 : 0)));
    return { total, done, shown, percent: total ? Math.round((shown / total) * 100) : 0 };
}

/** What the answers WERE — the breakdown that sits beside the count, not a second number. */
function _auditTallyBits() {
    const c = (_auditSession && _auditSession.counts) || _auditEmptyCounts();
    const bits = [];
    if (c.there) bits.push(`✓ ${c.there}`);
    if (c.finished) bits.push(`🏁 ${c.finished}`);
    if (c.spoiled) bits.push(`🗑️ ${c.spoiled}`);
    if (c.skipped) bits.push(`⏭️ ${c.skipped}`);
    return bits;
}

/**
 * THE progress bar of the stocktake: one widget, painted by the three sheets, by EVERY card (a
 * proposed one and a single question alike) and by the camera.
 *
 * That is the point of it: switching between «Uno alla volta», «Ti dico io» and «Scansiona» — or
 * landing on the panel of an unreadable code — used to make the bar disappear, and a bar that only
 * the propose run has cannot show a stocktake being carried out by barcode. Same numbers, same
 * place, from the first card to the last, whichever way the answer came in.
 */
function _auditProgressHtml(inHand = false) {
    const p = _auditProgress(inHand);
    if (!p.total) return '';
    const bits = _auditTallyBits();
    const label = t('audit.count_of', { n: p.shown, total: p.total });
    return `<div class="audit-progress-wrap">
        <div class="audit-progress" role="progressbar" aria-valuemin="0" aria-valuemax="${p.total}"
             aria-valuenow="${p.shown}" aria-valuetext="${escapeAttr(label)}"><span style="width:${p.percent}%"></span></div>
        <p class="audit-count">${escapeHtml(label)}${bits.length
            ? `<span class="audit-progress-tally"> · ${escapeHtml(bits.join(' · '))}</span>` : ''}</p>
    </div>`;
}

/**
 * The tally every sheet carries: the place this stocktake is about, how far it got, and the two
 * doors out of a single answer («Riepilogo» and «↩ Torna alla card»).
 *
 * It NEVER disappears. A fresh session (nothing answered yet, no propose order) used to print no
 * line at all, so moving from one way to another looked like losing the run.
 */
function _auditSessionBarHtml() {
    const s = _auditSession;
    const c = (s && s.counts) || _auditEmptyCounts();
    const total = c.there + c.finished + c.spoiled + c.skipped;
    const left = _auditSessionRemaining();
    const place = _auditScopeLabel((s && s.scope) || _auditScopeKey());
    const label = place ? t('audit.session_place', { place }) : t('audit.session_all');
    return `<div class="audit-session-bar">
        <span class="audit-session-line">${escapeHtml(label)}</span>
        <span class="audit-session-actions">
            ${left ? `<button type="button" class="audit-session-btn" onclick="_auditResumeCard()">↩ ${escapeHtml(t('audit.resume_card', { n: left }))}</button>` : ''}
            ${total ? `<button type="button" class="audit-session-btn" onclick="_auditShowSummary()">${escapeHtml(t('audit.session_summary'))}</button>` : ''}
        </span>
        ${_auditProgressHtml()}
    </div>`;
}

/**
 * The three ways in, as TABS: they live at the top of EVERY view of the stocktake (the three
 * sheets, the card of the run, a card opened by name or by barcode, the picker of an unreadable
 * code), so the household can always see where it is and change its mind.
 *
 * They used to be painted only on the three sheets: the banner goes straight into the card of a
 * run, so the options vanished the moment the stocktake started («non vedo più né i bottoni né le
 * icone»).
 *
 * The icon and the label are two elements on purpose: on a narrow phone the three labels cannot
 * share one line three ways, and a tab whose words wrap pushes the card down. Below 480 px the CSS
 * drops `.audit-mode-label` and the tab becomes its icon — big, still a 52 px tap target — while
 * `title` and `aria-label` keep the meaning for a long-press and for a screen reader.
 */
function _auditModeSwitchHtml() {
    // The labels are resolved at the CALL SITE with a literal t-call: a key handed to a helper
    // through a variable is invisible to `scripts/i18n-audit.py`, which then reports the three
    // `audit.mode_*` strings as "defined but unused". (Never write a bare t-call with quotes
    // inside a comment either: the audit reads the source text, not the syntax.)
    const tab = (mode, icon, label) => {
        const on = _auditMode === mode;
        return `<button type="button" class="audit-mode${on ? ' is-active' : ''}" role="tab" aria-selected="${on}" aria-pressed="${on}"
            title="${escapeAttr(label)}" aria-label="${escapeAttr(label)}"
            onclick="_auditSetMode('${mode}')"><span class="audit-mode-icon" aria-hidden="true">${icon}</span><span class="audit-mode-label">${escapeHtml(label)}</span></button>`;
    };
    // The tab stays VISIBLE on a device without a camera (it wears the ✕ like every other camera
    // entry); tapping it says why instead of switching to a mode that cannot work.
    return `<div class="audit-modes" role="tablist">${tab('propose', '🃏', t('audit.mode_propose'))}${tab('search', '🔎', t('audit.mode_search'))}${tab('scan', '📷', t('audit.mode_scan'))}</div>`;
}

function _auditSetMode(mode) {
    // A device without a camera says so instead of switching to a mode that cannot work.
    if (mode === 'scan' && typeof _cameraUsable === 'function' && !_cameraUsable()) {
        if (typeof _explainCameraUnavailable === 'function') _explainCameraUnavailable();
        return;
    }
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

/* ── The stocktake lives on the SERVER ────────────────────────────────────────
 * A run started in front of the fridge and left half-done must be resumable from the phone in the
 * kitchen, and its tally ("3 there, 1 finished") is one fact for the whole house: the state of the
 * household is not a property of a browser. The blob lives in `app_settings` under `audit_state`
 * (`GET audit_state` / `POST audit_state_save` / `audit_state_clear`) and the SERVER owns the hour
 * — a run nobody touched is dropped on read, so no device can offer a stale resume.
 *
 * `localStorage` stays an offline MIRROR (a phone in a cellar still resumes what it did) and is
 * never the truth: the server's answer wins whenever it answered at all.
 */
let _auditServerState = null;   // the run the server handed us (null = there is none)
let _auditStateSeen = false;    // did the server answer? (only then the mirror is ignored)
let _auditStateSeenAt = 0;      // when it answered (the read is cached for a few seconds)
let _auditStateLoad = null;     // the in-flight read, shared: ten taps are one request
let _auditStateSaveTimer = 0;
let _auditStateDirty = false;

/** The blob this device would store: the SESSION plus the propose run's point reached. */
function _auditStateBlob() {
    const s = _auditSession;
    if (!s) return null;
    // The propose view owns the card order and the point reached; a search or scan card only
    // adds an answer to the same ledger.
    if (_audit && _audit.mode === 'propose') {
        s.order = (_audit.order || []).slice();
        s.index = _audit.index || 0;
        s.scope = _audit.scope || '';
        s.startedAt = _audit.startedAt || s.startedAt;
    }
    let order = (s.order || []).slice();
    let index = s.index || 0;
    // A stocktake done only by camera or by name has no card order — and that must not throw away
    // the run another device is holding for the SAME shelf (`↩ Riprendi l'inventario`). The order
    // is inherited; the answers and the tally are this device's, and they travel either way.
    const prev = _auditServerState;
    if (!order.length && prev && Array.isArray(prev.order) && prev.order.length
        && normalizeLocationKey(prev.scope || '') === normalizeLocationKey(s.scope || '')) {
        order = prev.order.slice();
        index = Number(prev.index) || 0;
    }
    return {
        v: 1,
        scope: s.scope || '',
        order,
        index,
        answered: s.answered || {},
        history: (s.history || []).slice(-50),
        counts: s.counts || _auditEmptyCounts(),
        startedAt: s.startedAt || Date.now(),
        updatedAt: Date.now(),
    };
}

/** Push the run to the server, coalesced: an answer is written once, not once per repaint. */
function _auditPushState(delay = 600) {
    _auditStateDirty = true;
    clearTimeout(_auditStateSaveTimer);
    _auditStateSaveTimer = setTimeout(() => { _auditFlushState(); }, delay);
}

function _auditFlushState() {
    clearTimeout(_auditStateSaveTimer);
    if (!_auditStateDirty) return;
    const blob = _auditStateBlob();
    _auditStateDirty = false;
    if (!blob) return;
    _auditServerState = blob;
    api('audit_state_save', {}, 'POST', { state: blob }).catch(() => { _auditStateDirty = true; });
}

/**
 * Ask the server for the household's run — awaited before the sheet paints, so «Riprendi» is a
 * fact and not a guess. A pending write is flushed first, and a late answer never overwrites a
 * newer blob this device already has.
 */
async function _auditLoadState(maxAgeMs = 0) {
    if (_auditStateDirty) _auditFlushState();
    if (_auditStateLoad) return _auditStateLoad;
    if (maxAgeMs > 0 && _auditStateSeen && (Date.now() - _auditStateSeenAt) < maxAgeMs) {
        return _auditServerState;
    }
    _auditStateLoad = (async () => {
        try {
            const r = await api('audit_state', {}, 'GET', null, {}, 4000);
            if (r && r.success) {
                // A run without a card order is still a ledger (a stocktake done by camera or by
                // name): its tally and its answers count, «Riprendi» simply has nothing to reopen.
                const next = (r.state && Number(r.state.updatedAt) > 0) ? r.state : null;
                const cur = _auditServerState;
                if (!cur || !next || Number(next.updatedAt || 0) >= Number(cur.updatedAt || 0)) {
                    _auditServerState = next;
                }
                _auditStateSeen = true;
                _auditStateSeenAt = Date.now();
            }
        } catch (_e) { /* offline: the mirror below is what this device has */ }
        return _auditServerState;
    })();
    try { await _auditStateLoad; } finally { _auditStateLoad = null; }
    return _auditServerState;
}

/**
 * Remember the point reached — the SESSION, not the sheet.
 *
 * Closing the overlay by mistake (or leaving for the camera) must not restart the stocktake: the
 * run is resolved against the pantry as it is when the user comes back, everything already
 * answered is skipped, and the tally of the answers given by search and by barcode survives too.
 */
function _auditPersist() {
    const blob = _auditStateBlob();
    if (!blob) return;
    // The offline mirror first (it cannot fail for lack of network), then the server.
    try { localStorage.setItem(AUDIT_RESUME_KEY, JSON.stringify(blob)); } catch (_e) { /* storage full/disabled */ }
    _auditPushState();
}

function _auditClearPersist() {
    clearTimeout(_auditStateSaveTimer);
    _auditStateDirty = false;
    _auditServerState = null;
    try { localStorage.removeItem(AUDIT_RESUME_KEY); } catch (_e) { /* ignore */ }
    // The server copy is what the OTHER device would resume: drop it there too (offline, it
    // expires on its own within the hour).
    api('audit_state_clear', {}, 'POST').catch(() => {});
}

/** The offline mirror — read only when the server could not answer at all. */
function _auditMirroredRun() {
    try {
        const raw = localStorage.getItem(AUDIT_RESUME_KEY);
        return raw ? JSON.parse(raw) : null;
    } catch (_e) {
        return null;
    }
}

/**
 * The paused run, resolved against the current pantry (rows can be moved, edited
 * or deleted meanwhile). Null when there is nothing worth resuming: older than an
 * hour, unreadable, every row already answered, or every row gone.
 */
function _auditSavedRun() {
    // The household's run comes from the SERVER; the mirror is only what an offline device has.
    const data = _auditServerState || (_auditStateSeen ? null : _auditMirroredRun());
    if (!data || data.v !== 1 || !Array.isArray(data.order) || !data.order.length || !data.updatedAt) {
        return null;
    }
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
async function _auditStartFromBanner() {
    const key = currentLocation ? normalizeLocationKey(currentLocation) : '';
    // The place the banner names is the scope of BOTH modes: the search list puts its rows first.
    _auditSetScope(key);
    // The household's run lives on the SERVER and this device may be brand new: ask before
    // deciding that this shelf has nothing to resume. Without this await the banner started a NEW
    // run on every device that had not seen the old one — which is exactly "rifammi l'inventario".
    try { await _auditLoadState(3000); } catch (_e) { /* paint with what we have */ }
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
/**
 * Open the stocktake sheet.
 *
 * The run may have been started on ANOTHER device (or on this one, in another browser): the server
 * is asked BEFORE the sheet paints, so the household is never told to begin a stocktake it already
 * half-done. The read is bounded (4 s) and falls back to this device's mirror, so a dead network
 * cannot block the procedure.
 */
async function _auditStart() {
    try { await _auditLoadState(3000); } catch (_e) { /* paint with what we have */ }
    _auditStartPaint();
}

function _auditStartPaint() {
    const items = _auditItems();
    // A run left half-done (here or on another device) still says how far it got: its tally and
    // its answers are adopted — NOT its order, so «Riprendi l'inventario» stays the door to the run
    // itself — otherwise the list counts the size of the pantry and the stocktake looks stuck.
    _auditAdoptRunCounts();
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
        : (_auditMode === 'scan'
            ? (_auditCamOn ? _auditScanLiveHtml() : _auditScanBodyHtml())
            : _auditScopesBodyHtml(items, saved));
    // No visible title: the panel says what it is with the mode switch and «Inventario di …», and
    // on a phone the first line belongs to them. The dialog keeps an accessible NAME instead.
    el.innerHTML = `<div class="audit-panel" role="dialog" aria-modal="true" aria-label="${escapeAttr(t('audit.title'))}">
        <div class="audit-head audit-head--bare">
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
    /**
     * What is LEFT, not how big the pantry is.
     *
     * The list used to count the rows on the shelf, so twenty products confirmed by camera or by
     * name left every number exactly where it was — the household could not see the stocktake
     * moving and read it as "the stocktake is not saving". A row this session already answered is
     * not a question any more, so it comes off the count (and a place with nothing left is a
     * COMPLETED line, not a button that opens an empty run).
     */
    const answered = (_auditSession && _auditSession.answered) || {};
    const remaining = (rows) => rows.filter(it => !answered[String(it.id)]).length;
    const byLoc = {};
    items.forEach(it => {
        if (!byLoc[it.location]) byLoc[it.location] = [];
        byLoc[it.location].push(it);
    });
    const scopes = [];
    if (saved) {
        // A run closed by mistake within the last hour continues where it stopped.
        scopes.push(`<button type="button" class="audit-scope audit-resume" onclick="_auditResume()"><span aria-hidden="true">↩</span> ${escapeHtml(t('audit.resume', { n: saved.items.length }))}</button>`);
    }
    const allLeft = remaining(items);
    scopes.push(allLeft
        ? `<button type="button" class="audit-scope" onclick="_auditBegin(null)">${escapeHtml(t('audit.scope_all'))} (${allLeft})</button>`
        : `<span class="audit-scope is-done" aria-disabled="true"><span aria-hidden="true">✓</span> ${escapeHtml(t('audit.scope_all'))} (0)</span>`);
    Object.keys(byLoc).forEach(loc => {
        const info = LOCATIONS[loc] || { icon: '📦', label: loc };
        const left = remaining(byLoc[loc]);
        const label = `${info.icon} ${escapeHtml(info.label || loc)} (${left})`;
        scopes.push(left
            ? `<button type="button" class="audit-scope" onclick="_auditBegin('${escapeHtml(loc)}')">${label}</button>`
            : `<span class="audit-scope is-done" aria-disabled="true"><span aria-hidden="true">✓</span> ${escapeHtml(info.label || loc)} (0)</span>`);
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

/**
 * Adopt the household's run into this device's LIST — its tally and its answers, never its order.
 *
 * The scope list counts what is left, so a device that has just loaded the app must know what the
 * run already answered or every place reads full again (the stocktake looks stuck). The order stays
 * out: «↩ Riprendi l'inventario (N rimasti)» is the door to the run, and it reads `_auditSavedRun`.
 */
function _auditAdoptRunCounts() {
    const st = _auditServerState;
    if (!st || !Number(st.updatedAt)) return;
    // A run THIS device has open owns the ledger: never replace it with what the server had.
    // "Open" is a card order OR any answer — a stocktake carried out only by barcode and by name
    // has no order, and adopting the server's blob over it was a second way for the tally to jump
    // back to «✓ 1» (another shelf, another device, the same hour).
    if (_auditSession && ((_auditSession.order || []).length
        || Object.keys(_auditSession.answered || {}).length)) return;
    const scope = normalizeLocationKey(st.scope || '');
    if (_auditSession && (_auditSession.scope || '') === scope) return;
    _auditSession = {
        scope,
        startedAt: Number(st.startedAt) || Number(st.updatedAt) || Date.now(),
        counts: { ..._auditEmptyCounts(), ...(st.counts && typeof st.counts === 'object' ? st.counts : {}) },
        answered: (st.answered && typeof st.answered === 'object') ? { ...st.answered } : {},
        history: [],   // the undo chain belongs to the run, not to the list
        order: [],     // see above: «Riprendi» is what opens the run
        index: 0,
    };
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
 * The two facts of a hit row, one per line: where it stands + how much, then WHEN it expires.
 *
 * The search list showed the place and the stock and nothing else, so two packs of the same
 * article — the rice in the pantry that expires in March and the one in the cupboard that expires
 * next month — were indistinguishable, and picking the wrong one answers the wrong question.
 * The date is the SAME fact the card carries, with the same words (`audit.expiry_on` /
 * `expiry_was` / `expiry_none`) and its own mark on its own line, and the stock and the date are
 * bold and a notch larger than the place line around them (`.audit-hit-facts` in `style.css`), so
 * a hit reads exactly like the card it opens. A row with no date says so instead of hiding it.
 */
function _auditHitFactsHtml(it) {
    const loc = (typeof LOCATIONS !== 'undefined' && LOCATIONS[normalizeLocationKey(it.location)])
        || { icon: '📦', label: it.location || '' };
    const qty = (typeof stripHtml === 'function' && typeof formatQuantity === 'function')
        ? stripHtml(formatQuantity(it.quantity, it.unit, it.default_quantity, it.package_unit, { approx: _isMedicalRow(it) }))
        : `${it.quantity || ''} ${it.unit || ''}`.trim();
    const hasExpiry = !!(it && it.expiry_date);
    const date = hasExpiry && typeof formatDate === 'function' ? formatDate(it.expiry_date) : '';
    const expired = hasExpiry && typeof daysUntilExpiry === 'function' && daysUntilExpiry(it.expiry_date) < 0;
    const expiryFact = hasExpiry
        ? t(expired ? 'audit.expiry_was' : 'audit.expiry_on', { date })
        : t('audit.expiry_none');
    return `<span class="audit-hit-facts">
                        <span class="audit-hit-fact audit-hit-fact-place"><span class="audit-hit-seg"><span aria-hidden="true">${escapeHtml(loc.icon || '📍')}</span> ${escapeHtml(loc.label || '')} ·</span><span class="audit-hit-seg"><span aria-hidden="true">📦</span> <strong class="audit-hit-qty">${escapeHtml(qty)}</strong></span></span>
                        <span class="audit-hit-fact audit-hit-fact-expiry"><span aria-hidden="true">⏳</span> <span class="audit-expiry${expired ? ' is-expired' : ''}">${escapeHtml(expiryFact)}</span></span>
                    </span>`;
}

/**
 * The name of a hit, with its BRAND on the next line.
 *
 * The row said the product and where it stands, and nothing about who makes it: «Fusilli Pasta di
 * Semola di Grano Duro» is the same sentence on a premium box and on the discount one, and the
 * household recognises the package by its brand before it reads the name. It is a line of its own
 * under the name — the pantry row and the card put it beside/under the name for the same reason —
 * and it is never trimmed to make room: only the tail can be ellipsised. A row with no brand
 * simply omits the line.
 */
function _auditHitTitleHtml(it) {
    const name = escapeHtml(it.display_name || it.name || '');
    const brand = String((it && it.brand) || '').trim();
    return `<span class="audit-hit-name">${name}</span>${brand
        ? `<span class="audit-hit-brand">${escapeHtml(brand)}</span>` : ''}`;
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
    const thumb = (typeof _productThumbHtml === 'function') ? _productThumbHtml(it) : '';
    const mark = inScope
        ? `<span class="audit-hit-mark is-here" title="${escapeAttr(t('audit.mark_here', { place: placeLabel || loc.label || '' }))}" aria-hidden="true">✓</span>`
        : `<span class="audit-hit-mark is-elsewhere" title="${escapeAttr(t('audit.mark_elsewhere', { place: placeLabel }))}" aria-hidden="true">✕</span>`;
    const thereLabel = t('audit.there_is');
    const moveLabel = t('audit.move_here');
    const action = inScope
        ? `<button type="button" class="audit-hit-quick" title="${escapeAttr(thereLabel)}" aria-label="${escapeAttr(thereLabel)}" onclick="_auditSearchQuickThere(${Number(it.id)})"><span aria-hidden="true">✓</span> <span class="audit-hit-action-label">${escapeHtml(thereLabel)}</span></button>`
        : `<button type="button" class="audit-hit-move" title="${escapeAttr(moveLabel)}" aria-label="${escapeAttr(moveLabel)}" onclick="_auditSearchMoveHere(${Number(it.id)})"><span aria-hidden="true">↪</span> <span class="audit-hit-action-label">${escapeHtml(moveLabel)}</span></button>`;
    return `<div class="audit-hit${inScope ? '' : ' is-elsewhere'}">
            <button type="button" class="audit-hit-open" onclick="_auditSearchPick(${Number(it.id)})">
                ${mark}
                <span class="audit-hit-thumb">${thumb}</span>
                <span class="audit-hit-text">
                    ${_auditHitTitleHtml(it)}
                    ${_auditHitFactsHtml(it)}
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
    // `_auditSearchPick` answers false when the row belongs to another shelf (it asks instead):
    // there is no card to confirm, and the shelf being inventoried is not touched.
    if (!_auditSearchPick(invId)) return;
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
    if (!scope) return false;
    const it = _auditItems().find(r => Number(r.id) === Number(invId));
    if (!it) return false;
    const placeLabel = _auditScopeLabel(scope);
    let res = null;
    try {
        res = await api('inventory_update', {}, 'POST', { id: it.id, location: scope });
    } catch (_e) {
        showToast(t('audit.move_failed'), 'error');
        return false;
    }
    if (!res || res.success === false) {
        const msg = (typeof _apiErrorText === 'function')
            ? _apiErrorText(res || {})
            : ((res && (res.message || res.error)) || t('audit.move_failed'));
        showToast(msg, 'error');
        return false;
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
    return true;
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
    // open of the overlay remembers it). A `pack` is a question INSIDE the stocktake — another
    // package of the same article — so it must not rewrite the way the household chose.
    if (mode !== 'pack') {
        _auditMode = mode === 'scan' ? 'scan' : 'search';
        try { localStorage.setItem(AUDIT_MODE_KEY, _auditMode); } catch (_e) { /* private mode */ }
    }
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

/** Tap a pantry row → the same card as the other mode, for that one product. `false` = asked instead. */
function _auditSearchPick(invId) {
    const it = _auditItems().find(r => Number(r.id) === Number(invId));
    if (!it) return false;
    // The search lists the packs of OTHER shelves too (marked ✕): opening one of those cards would
    // answer a shelf the household is not standing at. It gets the same question a scan gets.
    const scope = _auditScopeKey();
    if (scope && normalizeLocationKey(it.location) !== scope) {
        _auditElsewhereView(it, scope);
        return false;
    }
    _auditOneCardView(it, 'search', String(_auditSearchTerm || '').trim());
    return true;
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

/**
 * The live viewfinder, INSIDE the stocktake panel.
 *
 * It used to be a full-screen overlay (`position: fixed; inset: 0`): the sheet, the tabs and the
 * tally disappeared the moment the camera opened («puoi lasciarlo dentro al tab e non farlo a tutto
 * schermo?»). The video now sits in the panel's body, framed, with the status line and the running
 * tally under it — the household never loses the place it is inventorying.
 *
 * Two ✕, two meanings: the one ON the picture turns the camera off (the sheet stays, its tab shows
 * the «Apri la fotocamera» button), the panel's own ✕ closes the stocktake.
 */
function _auditScanLiveHtml() {
    const place = _auditScopeLabel(_auditScopeKey());
    const stopTitle = t('audit.scan_stop_camera');
    return `<div class="audit-cam">
            <video id="audit-scan-video" playsinline muted autoplay aria-hidden="true"></video>
            <div class="audit-scan-frame" aria-hidden="true"></div>
            <button type="button" class="audit-cam-stop" id="audit-scan-close"
                title="${escapeAttr(stopTitle)}" aria-label="${escapeAttr(stopTitle)}"
                onclick="_auditScanStop(true)">✕</button>
        </div>
        <p class="audit-scan-status" id="audit-scan-status" role="status" aria-live="polite"></p>
        <div class="audit-scan-progress" id="audit-scan-progress"></div>
        <p class="audit-hint">${escapeHtml(t('audit.scan_finder_hint'))}</p>
        ${place ? `<p class="audit-hint audit-search-scope-note"><span aria-hidden="true">📍</span> ${escapeHtml(t('audit.search_in_place', { place }))}</p>` : ''}`;
}

function _auditScanStatus(text) {
    const el = document.getElementById('audit-scan-status');
    if (el) el.textContent = text || '';
}

/** The tally of the three ways, printed under the viewfinder: proof the scan counts too. */
function _auditScanProgressPaint() {
    const el = document.getElementById('audit-scan-progress');
    // A shelf checked by barcode must SHOW itself moving: this is the same bar, and the same
    // number, the sheets and the cards print.
    if (el) el.innerHTML = _auditProgressHtml();
}

/**
 * Open the stocktake's own camera — INSIDE the panel, never over the whole screen.
 *
 * The stream starts immediately (the household is standing in front of the shelf) and the sheet is
 * repainted with the live view in its body: the header, the three tabs and the tally stay where
 * they are. The run is persisted before the stream opens, so answering a box or leaving the tab
 * never loses the stocktake.
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
    _auditCamOn = true;
    _auditScanActive = true;
    _auditStartPaint();          // the sheet, with the viewfinder in its body
    _auditScanStatus(t('audit.scan_finder_hint'));
    _auditScanProgressPaint();

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
 * Turn the camera off. `reopen` repaints the sheet (its tab then shows the «Apri la fotocamera»
 * button): the ✕ on the picture, Esc, a failure and the phone locking all land here, so the
 * household is never dropped out of the stocktake with no way back in.
 */
function _auditScanStop(reopen = true) {
    _auditScanGen++;
    clearTimeout(_auditScanTimer);
    _auditScanActive = false;
    _auditCamOn = false;
    const video = document.getElementById('audit-scan-video');
    if (video) {
        try { video.pause(); } catch (_e) { /* already stopped */ }
        video.srcObject = null;
    }
    if (_auditScanStream) {
        try { _auditScanStream.getTracks().forEach(tr => { try { tr.stop(); } catch (_e) { /* gone */ } }); } catch (_e) { /* gone */ }
        _auditScanStream = null;
    }
    if (reopen) _auditStart();
}

/** A phone that locks, a tab that hides: the stream stops, and the run reaches the server. */
document.addEventListener('visibilitychange', () => {
    if (!document.hidden) return;
    if (_auditScanActive) _auditScanStop(true);
    // A stocktake must not die with the tab: whatever has not reached the server yet goes now
    // (the debounced write may still be waiting when the page is frozen).
    if (_auditStateDirty) _auditFlushState();
});
/** Closing the tab is the same promise, kept at the last possible moment. */
window.addEventListener('pagehide', () => {
    if (_auditStateDirty) _auditFlushState();
});

/**
 * The OTHER packs of the same article, as a list under the facts.
 *
 * A barcode points at an ARTICLE, not at a package: «Brodo Il Dado Classico» had 6 pz and 30 pz on
 * the same shelf, and the scan opened the card of one of them with no way to reach the other —
 * «mi fa vedere solo una e non mi propone l'altra». The list names every pack the same product
 * stands on (place, stock, date) and a tap opens THAT row's card, inside the same stocktake.
 */
function _auditOtherPacks(it) {
    if (!it || !it.product_id) return [];
    return _auditItems()
        .filter(r => r && String(r.product_id) === String(it.product_id) && Number(r.id) !== Number(it.id))
        .sort((a, b) => String(a.expiry_date || '').localeCompare(String(b.expiry_date || '')));
}

function _auditOtherPacksHtml(it) {
    const packs = _auditOtherPacks(it);
    if (!packs.length) return '';
    const openTitle = t('audit.other_pack_open');
    const doneTitle = t('audit.already_there');
    const ledger = (_audit && _audit.answered) || {};
    const items = packs.map(r => {
        const loc = (typeof LOCATIONS !== 'undefined' && LOCATIONS[normalizeLocationKey(r.location)])
            || { icon: '📦', label: r.location || '' };
        const qty = (typeof stripHtml === 'function' && typeof formatQuantity === 'function')
            ? stripHtml(formatQuantity(r.quantity, r.unit, r.default_quantity, r.package_unit, { approx: _isMedicalRow(r) }))
            : `${r.quantity || ''} ${r.unit || ''}`.trim();
        const date = r.expiry_date && typeof formatDate === 'function' ? formatDate(r.expiry_date) : '';
        const label = `${loc.label || ''} · ${qty}${date ? ` · ${date}` : ''}`;
        // A pack this stocktake already answered says so ON the line: the household is looking at
        // the shelf, and «Amido di mais ×3» opens three cards — one of which may already be done.
        const done = !!ledger[String(r.id)];
        return `<li><button type="button" class="audit-pack-line${done ? ' is-answered' : ''}"
                title="${escapeAttr(done ? doneTitle : openTitle)}" aria-label="${escapeAttr((done ? doneTitle + ' · ' : '') + openTitle + ' · ' + label)}"
                onclick="_auditOpenPack(${Number(r.id)})">
                <span class="audit-pack-ico" aria-hidden="true">${loc.icon || '📦'}</span>
                ${done ? `<span class="audit-pack-done" aria-hidden="true">✓</span>` : ''}
                <span class="audit-pack-place">${escapeHtml(loc.label || '')}</span>
                <span class="audit-pack-qty">${escapeHtml(qty)}</span>
                ${date ? `<span class="audit-pack-date">${escapeHtml(date)}</span>` : ''}
                <span class="audit-pack-go" aria-hidden="true">›</span>
            </button></li>`;
    }).join('');
    return `<div class="audit-packs">
        <p class="audit-packs-title">${escapeHtml(t('audit.other_packs'))}</p>
        <ul class="audit-packs-list" role="list">${items}</ul>
    </div>`;
}

/** Open the card of another pack of the same article — the run it belongs to is not lost. */
function _auditOpenPack(invId) {
    const row = _auditItems().find(r => Number(r.id) === Number(invId));
    if (!row) return;
    _auditOneCardView(row, 'pack', row.display_name || row.name || '');
}

/**
 * The pantry row of a product the catalogue just named.
 *
 * `product_id` is the exact answer, and it is tried first. But the same ARTICLE can live in two
 * catalogue rows — one added by hand before the code was ever offered, one created when the
 * barcode was first scanned — so a tin of «Tonno ai semi di girasole» standing in the pantry
 * answered «non c'è nell'inventario»: the code pointed at the empty row, the stock sat on the
 * other. The name of the article is the second key, on the stored titles (singular, no brand),
 * the shelf being inventoried first.
 */
function _auditRowForProduct(product) {
    const pid = Number(product && product.id) || 0;
    const rows = _auditItems();
    const scope = _auditScopeKey();
    const inScope = (r) => !scope || normalizeLocationKey(r.location) === scope;
    if (pid) {
        const exact = rows.filter(r => Number(r.product_id) === pid);
        if (exact.length) return exact.find(inScope) || exact[0];
    }
    const pname = _auditFold(product && product.name);
    if (!pname) return null;
    const same = rows.filter(r => _auditFold(r.name) === pname);
    const hit = same.find(inScope) || same[0] || null;
    // The case is worth a line in `data/logs/app-*.log`: a barcode pointing at a catalogue row that
    // holds no stock while the same article IS on a shelf means two `products` rows for one
    // article, and the log is how that data problem is found instead of blamed on the scanner.
    if (hit && typeof remoteLog === 'function') {
        remoteLog('AUDIT', `scan: product ${pid || '?'} carries no stock, matched row ${hit.id} of product ${hit.product_id} by name «${product.name}»`);
    }
    return hit;
}

/**
 * A code was read: the camera closes and the ONE card of the stocktake opens.
 *
 * The lookup is the app's own catalogue resolution (`_resolveBarcodeLookup`, cached) — never the
 * shopping pipeline, never the medicine wizard, never an AI identification. Only a product with
 * usable stock is a stocktake row; a code the app cannot place does not stop the run, it ASKS:
 * the picker of «Ti dico io» opens with the code in hand (the household is holding the box, the
 * only moment the association can be made).
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
        // A code no catalogue knows is not a dead end: the household is HOLDING the box, which is
        // the only moment the association can be made. The camera closes and the search of "Ti
        // dico io" opens with the code on its head; picking a product writes the code onto it.
        _auditScanStatus(t('audit.scan_no_match'));
        _auditScanStop(false);
        _auditAssignView(clean);
        return;
    }
    const name = product.display_name || product.name || '';
    const row = _auditRowForProduct(product);
    if (!row) {
        // The code IS known but no shelf holds it — and a toast that says so is a dead end (the
        // household reads "it is not in the pantry" about a tin that is standing right there).
        // Same answer as an unknown code: close the camera and ask WHICH row it is, with the name
        // the catalogue gave us already in the field and its matches on screen.
        _auditScanStatus(t('audit.scan_not_stocked', { name }));
        _auditScanStop(false);
        _auditAssignView(clean, name);
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
 * inventoried wins, which is the row the household is standing in front of — unless the caller
 * already named the row (`preferRowId`, the "this one" of the barcode picker).
 *
 * **The stocktake never walks to another shelf on its own.** When the only row of that article
 * lives ELSEWHERE, opening its card was a silent jump: the card's head named «Verifica Dispensa
 * cucina» while the household was inventorying the living room, and answering it confirmed a shelf
 * it was not standing in front of — and there may well be packs of the same article here, recorded
 * nowhere. So an out-of-scope row is a QUESTION (`_auditElsewhereView`): «è qui?», answered by the
 * household; the place being inventoried does not change in either answer.
 */
function _auditScanCard(row, opts = {}) {
    const scope = _auditScopeKey();
    const rows = _auditItems().filter(r => Number(r.product_id) === Number(row.product_id));
    const picked = Number(opts.preferRowId) > 0
        ? rows.find(r => Number(r.id) === Number(opts.preferRowId))
        : null;
    const preferred = picked
        || rows.find(r => !scope || normalizeLocationKey(r.location) === scope)
        || rows[0] || row;
    const fresh = _auditItems().find(r => Number(r.id) === Number(preferred.id)) || preferred;
    // A row the household tapped by name (the picker prints the place on every line) is an explicit
    // choice; a row the SCANNER matched is not. Only the second one asks.
    if (!picked && scope && normalizeLocationKey(fresh.location) !== scope) {
        _auditElsewhereView(fresh, scope);
        return;
    }
    _auditOneCardView(fresh, 'scan', fresh.display_name || fresh.name || '');
}

/* ── The pack is on ANOTHER shelf: ask, never jump ───────────────────────────
 * Reported: inventorying «Mobile Soggiorno», scanning a product the ledger placed in «Dispensa
 * cucina» opened THAT shelf's card — «mi cambia tutto, mi scrive verifica dispensa cucina» — so the
 * household, standing in the living room, was asked to confirm a pantry it could not see. Two
 * things are true at once: the app's row IS somewhere else, and the box in their hands is here (and
 * there may well be packs here that the ledger never recorded). Only the household knows, so the
 * sheet asks one question on one screen: the product, where the ledger has it, and «Sposta qui» /
 * «No, lascia dov'è». The place being inventoried does not change in either answer — the session,
 * the tally and the pin stay on it — and moving the row opens its card HERE, so the stock and the
 * date can be corrected on the shelf the household is actually standing in front of.
 */

/** The question on screen, if any: `{ rowId, productId, scope }`. */
let _auditElsewhere = null;

function _auditElsewhereView(row, scope) {
    if (!row) return;
    _auditElsewhere = {
        rowId: Number(row.id) || 0,
        productId: Number(row.product_id) || 0,
        scope: scope || '',
    };
    const el = document.getElementById('audit-overlay');
    if (!el) return;
    const loc = (typeof LOCATIONS !== 'undefined' && LOCATIONS[normalizeLocationKey(row.location)])
        || { icon: '📦', label: row.location || '' };
    const scopeLabel = _auditScopeLabel(scope) || t('audit.title');
    const name = row.display_name || row.name || '';
    const brand = String(row.brand || '').trim();
    const qty = (typeof stripHtml === 'function' && typeof formatQuantity === 'function')
        ? stripHtml(formatQuantity(row.quantity, row.unit, row.default_quantity, row.package_unit, { approx: _isMedicalRow(row) }))
        : `${row.quantity || ''} ${row.unit || ''}`.trim();
    const hasExpiry = !!row.expiry_date;
    const date = hasExpiry && typeof formatDate === 'function' ? formatDate(row.expiry_date) : '';
    const expired = hasExpiry && typeof daysUntilExpiry === 'function' && daysUntilExpiry(row.expiry_date) < 0;
    const expiryFact = hasExpiry
        ? t(expired ? 'audit.expiry_was' : 'audit.expiry_on', { date })
        : t('audit.expiry_none');
    const thumb = (typeof _productThumbHtml === 'function') ? _productThumbHtml(row) : '';
    el.hidden = false;
    el.innerHTML = `<div class="audit-panel" role="dialog" aria-modal="true" aria-label="${escapeAttr(t('audit.title'))}">
        <div class="audit-head audit-head--bare">
            <button type="button" class="audit-close" onclick="_auditElsewhereDismiss()" aria-label="${escapeAttr(t('audit.close'))}">✕</button>
        </div>
        ${_auditModeSwitchHtml()}
        ${_auditProgressHtml()}
        <p class="audit-eyebrow">${escapeHtml(t('audit.session_place', { place: scopeLabel }))}</p>
        <div class="audit-product-row">
            <div class="audit-product-thumb">${thumb}</div>
            <div class="audit-product-main">
                <p class="audit-product-name">${escapeHtml(name)}</p>
                ${brand ? `<p class="audit-product-brand">${escapeHtml(brand)}</p>` : ''}
                <div class="audit-product-facts">
                    <p class="audit-fact-line"><span aria-hidden="true">${escapeHtml(loc.icon || '📦')}</span> ${escapeHtml(loc.label || '')} · <span aria-hidden="true">📦</span> ${escapeHtml(t('audit.stock_label'))}: <strong class="audit-fact-qty">${escapeHtml(qty)}</strong></p>
                    <p class="audit-fact-line audit-fact-line-expiry"><span aria-hidden="true">⏳</span> <span class="audit-expiry${expired ? ' is-expired' : ''}">${escapeHtml(expiryFact)}</span></p>
                </div>
            </div>
        </div>
        <p class="audit-hint audit-elsewhere-hint">${escapeHtml(t('audit.elsewhere_hint', {
            name: name || '',
            place: loc.label || '',
            scope: scopeLabel,
        }))}</p>
        <button type="button" class="audit-there" onclick="_auditElsewhereMoveHere()"><span aria-hidden="true">↪</span> ${escapeHtml(t('audit.move_here'))}</button>
        <div class="audit-foot">
            <button type="button" class="audit-skip" onclick="_auditElsewhereDismiss()">${escapeHtml(t('audit.elsewhere_leave'))}</button>
        </div>
    </div>`;
}

/** «Sposta qui»: the row comes into this shelf and its card opens here — the question answered. */
async function _auditElsewhereMoveHere() {
    const st = _auditElsewhere;
    if (!st || !st.rowId || !st.scope) return;
    _auditElsewhere = null;
    const moved = await _auditSearchMoveHere(st.rowId);   // the same write as the ✕ row of «Ti dico io»
    const row = _auditItems().find(r => Number(r.id) === Number(st.rowId));
    if (!moved) {
        // Refused (the toast says why): the question stays, the shelf does not disappear.
        if (row) _auditElsewhereView(row, st.scope);
        return;
    }
    if (!row) { _auditScanStart(); return; }
    // It stands here now: the card is the question the household lifted the box for.
    _auditOneCardView(Object.assign({}, row, { location: st.scope }), 'scan', row.display_name || row.name || '');
}

/** «No, lascia dov'è» (or ✕): nothing is written, and the stocktake goes back to where it came in. */
function _auditElsewhereDismiss() {
    _auditElsewhere = null;
    if (_auditMode === 'scan') { _auditScanStart(); return; }
    _auditStart();
}

/* ── An unknown code: the search of "Ti dico io", with the code in hand ─────────
 * A box no catalogue knows used to answer with one line of text and nothing else — while the
 * household was standing there holding the product the app had just failed to name. The code is
 * a variant of the article (the article is theirs), so the honest way out is to let them SAY
 * which product it is: the same search as the second mode, the same rows, and the pick writes
 * the code onto that product (`product_barcode_set`). The next read of that barcode resolves to
 * it — in this session and after a reload (`_barcodeRememberProduct`).
 *
 * ONE LINE PER PLACE, not per article: "Riso basmati" standing in the pantry, in the fridge and
 * in the living-room cupboard listed once, with the place of whichever row happened to come
 * first, hid the shelf the household was looking at. The picker shows every row (place + stock),
 * the place being inventoried first, and picking a line opens the card of THAT row.
 */

/** The code being associated (one at a time: the panel exists only while it is unknown). */
let _auditPendingCode = '';
/** How many rows the picker lists: a search, not the pantry (a product may fill it alone). */
const AUDIT_ASSIGN_MAX = 10;

/** The panel: the code that was read, one field, the matching rows. */
function _auditAssignView(code, knownName = '') {
    _auditPendingCode = String(code || '').trim();
    if (!_auditPendingCode) return;
    const known = String(knownName || '').trim();
    const el = document.getElementById('audit-overlay');
    if (!el) return;
    el.hidden = false;
    el.innerHTML = `<div class="audit-panel">
        <div class="audit-head">
            <h3>${escapeHtml(t('audit.assign_title'))}</h3>
            <button type="button" class="audit-close" onclick="_auditAssignCancel()" aria-label="${escapeAttr(t('audit.close'))}">✕</button>
        </div>
        ${_auditModeSwitchHtml()}
        ${_auditProgressHtml()}
        <p class="audit-hint">${escapeHtml(t('audit.assign_hint', { code: _auditPendingCode }))}</p>
        ${known ? `<p class="audit-hint">${escapeHtml(t('audit.assign_known_hint', { name: known }))}</p>` : ''}
        <p class="audit-hint audit-assign-note">${escapeHtml(t('audit.assign_places_note'))}</p>
        <input type="text" id="audit-assign-input" class="form-input audit-search-input"
               autocomplete="off" autocapitalize="sentences" spellcheck="false"
               value="${escapeAttr(known)}"
               placeholder="${escapeAttr(t('inventory.search_placeholder'))}"
               oninput="_auditAssignRender(this.value)"
               onkeydown="if(event.key==='Enter'){event.preventDefault();_auditAssignPickFirst();}">
        <div class="audit-search-results" id="audit-assign-results"></div>
        <button type="button" class="audit-done audit-scan-open" onclick="_auditScanStart()">
            <span aria-hidden="true">📷</span> ${escapeHtml(t('audit.scan_open'))}</button>
    </div>`;
    // The name the catalogue gave us is already the search: the row the household is standing in
    // front of is on screen, one tap away, instead of a blank field after a dead end.
    if (known) _auditAssignRender(known);
    const input = document.getElementById('audit-assign-input');
    if (input) setTimeout(() => { try { input.focus(); } catch (_e) { /* kiosk: no keyboard */ } }, 60);
}

/**
 * Every ROW the term matches — a product standing in three places is three lines.
 *
 * The order answers the question the household has in front of them: the place this stocktake is
 * about comes first (the shelf they are standing at), then the other shelves, then the whole-house
 * rows. Depleted traces are left out: they are not a place the product stands in.
 */
function _auditAssignMatches(term) {
    const q = _auditFold(term);
    if (!q) return [];
    const scope = _auditScopeKey();
    const here = [];
    const elsewhere = [];
    for (const it of _auditItems()) {
        if (!it || !it.product_id) continue;
        if (typeof isInventoryDepleted === 'function' && isInventoryDepleted(it)) continue;
        const hay = _auditFold(`${it.display_name || ''} ${it.name || ''} ${it.brand || ''}`);
        if (!hay.includes(q)) continue;
        if (!scope || normalizeLocationKey(it.location) === scope) here.push(it);
        else elsewhere.push(it);
    }
    return here.concat(elsewhere).slice(0, AUDIT_ASSIGN_MAX);
}

function _auditAssignRender(term) {
    const box = document.getElementById('audit-assign-results');
    if (!box) return;
    const q = String(term || '').trim();
    if (!q) { box.innerHTML = ''; return; }
    const rows = _auditAssignMatches(q);
    if (!rows.length) {
        box.innerHTML = `<p class="audit-hint">${escapeHtml(t('audit.search_none', { term: q }))}</p>`;
        return;
    }
    box.innerHTML = rows.map(it => {
        const thumb = (typeof _productThumbHtml === 'function') ? _productThumbHtml(it) : '';
        // The pick carries the ROW (place included): the card that opens is that shelf's.
        return `<div class="audit-hit is-assign">
            <div class="audit-hit-open">
                <span class="audit-hit-thumb">${thumb}</span>
                <span class="audit-hit-text">
                    ${_auditHitTitleHtml(it)}
                    ${_auditHitFactsHtml(it)}
                </span>
            </div>
            <button type="button" class="audit-hit-quick" title="${escapeAttr(t('audit.assign_action'))}" aria-label="${escapeAttr(t('audit.assign_action'))}" onclick="_auditAssignPick(${Number(it.id)})">
                <span aria-hidden="true">🔗</span> <span class="audit-hit-action-label">${escapeHtml(t('audit.assign_action'))}</span></button>
        </div>`;
    }).join('');
}

/** Enter picks the first row — the closest match, in the place being inventoried. */
function _auditAssignPickFirst() {
    const input = document.getElementById('audit-assign-input');
    const rows = _auditAssignMatches(input ? input.value : '');
    if (rows.length) _auditAssignPick(Number(rows[0].id));
}

/**
 * Write the code onto the chosen product, then continue the stocktake on THAT row.
 *
 * The picker hands over an inventory ROW (the place is part of the answer): the code is written on
 * the article (`product_barcode_set`, one field, on the product), and the card that opens is the
 * shelf the household picked — not "the first row of that product". A code another product already
 * owns is REFUSED and named, never merged behind a tap.
 */
async function _auditAssignPick(invId) {
    const code = _auditPendingCode;
    const row = _auditItems().find(r => Number(r.id) === Number(invId)) || null;
    if (!code || !row || !row.product_id) return;
    const productId = Number(row.product_id);
    const name = String(row.name || row.display_name || '');
    try {
        const res = await api('product_barcode_set', {}, 'POST', { id: productId, barcode: code });
        if (!res || !(res.success || res.ok)) {
            if (res && res.error === 'barcode_already_used') {
                showToast(t('audit.assign_taken', { name: res.existing_name || code }), 'error', 7000);
            } else {
                showToast(t('audit.assign_failed'), 'error');
            }
            // The code stays where it is, but the stocktake does not stop on it: the row the
            // household just pointed at is the question in front of them, and answering it is the
            // whole reason they lifted the box.
            _auditPendingCode = '';
            _auditScanCard(row, { preferRowId: Number(row.id) });
            return;
        }
        if (typeof _barcodeRememberProduct === 'function') _barcodeRememberProduct(code, row);
        showToast(t('audit.assign_done', { name: name || code }), 'success');
        _auditPendingCode = '';
        _auditScanCard(row, { preferRowId: Number(row.id) });
    } catch (_e) {
        showToast(t('audit.assign_failed'), 'error');
    }
}

/** ✕ leaves the association: the stocktake goes back to where it was, the code stays unknown. */
function _auditAssignCancel() {
    _auditPendingCode = '';
    _auditStart();
}

function _auditBegin(location, saved = null) {
    // The chosen scope is the place the search mode sorts by, so it outlives this run.
    _auditSetScope(location || '');
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
        // ONE procedure. Moving to another shelf (or to the whole pantry) inside the same stocktake
        // keeps the ledger this session already filled — the counts in the list come from it, so
        // the run must start from the SAME numbers (opening «Tutto (206)» and being asked 207
        // questions is the kind of disagreement that makes the count look fake). A session with
        // nothing answered is simply replaced by the one for the place being opened.
        const prev = _auditSession;
        const carriesLedger = prev && prev.answered && Object.keys(prev.answered).length > 0
            && (prev.scope || '') !== (location || '');
        if (carriesLedger) {
            prev.scope = location || '';
            prev.order = [];
            prev.index = 0;
        } else {
            _auditSessionFor(location);
        }
    }
    const session = _auditSession;
    // Same comparison on both sides: a row may store the slug while the scope is a label.
    const scopeRows = saved
        ? null
        : _auditItems().filter(it => !location || normalizeLocationKey(it.location) === location);
    // Continuing the same place never asks twice, and neither does starting one after answering
    // by name or by barcode: what this stocktake already answered is not a question any more
    // (`answered` is the one ledger the three ways write into).
    const items = saved
        ? saved.items
        : scopeRows.filter(it => !session.answered[String(it.id)]);
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
        if (scopeRows && scopeRows.length) {
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
 *   1. the product (photo, name, brand, then the place + stock on one line and the date on the
 *      NEXT one with its own mark) — facts, once, and the two the household checks against the
 *      shelf (stock, date) are bold and a notch larger than the line around them;
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
    // One question about one product: no «Salta» — the way out is another product. It is NOT an
    // excuse to hide the bar: the count is the stocktake's, not this card's (see `_auditProgress`).
    const isOne = isSearch || isScan;
    el.hidden = false;
    const qtyPlain = (typeof stripHtml === 'function' && typeof formatQuantity === 'function')
        ? stripHtml(formatQuantity(it.quantity, it.unit, it.default_quantity, it.package_unit, { approx: _isMedicalRow(it) }))
        : `${it.quantity || ''} ${it.unit || ''}`.trim();
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
    // A row this stocktake already answered is NOT a question any more. It still opens (a barcode
    // re-scanned, a pack line of «Anche in», a name picked again) and until now it looked exactly
    // like an open one: the same emerald «Confermo …», and answering it counted nothing and closed
    // the sheet — which reads as the app ignoring the tap. The card now says what happened, and its
    // only two doors are the honest ones: change that answer, or move on.
    const answeredKind = String((_audit.answered || {})[String(it.id)] || '');
    const answered = answeredKind !== '';
    const answeredAt = answered ? _auditAnsweredAt(it.id) : 0;
    el.innerHTML = `<div class="audit-panel" role="dialog" aria-modal="true" aria-label="${escapeAttr(t('audit.title'))}">
        <div class="audit-top">
            ${_auditProgressHtml(!answered)}
            <button type="button" class="audit-close" onclick="_auditStop()" aria-label="${escapeAttr(t('audit.close'))}">✕</button>
        </div>
        ${_auditModeSwitchHtml()}
        <p class="audit-eyebrow">${escapeHtml(isSearch
            ? t('audit.search_badge', { term: _audit.term || '' })
            : placeTitle)}</p>
        ${answered ? _auditAnsweredHtml(answeredKind, answeredAt) : ''}
        <div class="audit-product-row">
            <div class="audit-product-thumb">${thumb}</div>
            <div class="audit-product-main">
                <p class="audit-product-name">${escapeHtml(it.display_name || it.name || '')}</p>
                ${it.brand ? `<p class="audit-product-brand">${escapeHtml(it.brand)}</p>` : ''}
                <div class="audit-product-facts">
                    <p class="audit-fact-line"><span aria-hidden="true">${escapeHtml(locMeta.icon || '📍')}</span> ${escapeHtml(placeLabel)} · <span aria-hidden="true">📦</span> ${escapeHtml(t('audit.stock_label'))}: <strong class="audit-fact-qty">${escapeHtml(qtyPlain)}</strong></p>
                    <p class="audit-fact-line audit-fact-line-expiry"><span aria-hidden="true">⏳</span> <span class="audit-expiry${isExpired ? ' is-expired' : ''}">${escapeHtml(expiryFact)}</span></p>
                </div>
            </div>
        </div>
        ${_auditOtherPacksHtml(it)}
        ${answered ? `<div class="audit-answered-actions">
            <button type="button" class="audit-cell audit-answer-again" onclick="_auditReopen(${Number(it.id)})"><span aria-hidden="true">↩</span> ${escapeHtml(t('audit.answer_again'))}</button>
            <button type="button" class="audit-cell audit-answer-next" onclick="_auditNext()">${escapeHtml(t('audit.continue_next'))} <span aria-hidden="true">→</span></button>
        </div>` : `<button type="button" class="audit-there" onclick="_auditAnswer('there')"><span aria-hidden="true">✓</span> <span class="audit-there-full">${escapeHtml(confirmFull)}</span><span class="audit-there-short">${escapeHtml(confirmShort)}</span></button>
        <div class="audit-grid">
            <button type="button" class="audit-cell audit-finished" onclick="_auditAnswer('finished')"><span aria-hidden="true">🏁</span> ${escapeHtml(t('audit.exhausted'))}</button>
            ${_isMedicalRow(it)
                ? (it.opened_at
                    // Already open: the button has nothing left to do, and a button that opens
                    // what is already open is a lie the household pays for (a second opened_at,
                    // a PAO date moved for nothing).
                    ? `<button type="button" class="audit-cell audit-med-open is-disabled" disabled aria-disabled="true" title="${escapeAttr(t('audit.med_already_opened'))}"><span aria-hidden="true">📂</span> ${escapeHtml(t('audit.med_opened'))}</button>`
                    : `<button type="button" class="audit-cell audit-med-open" onclick="_auditMedOpen()"><span aria-hidden="true">📂</span> ${escapeHtml(t('audit.med_opened'))}</button>`)
                : `<button type="button" class="audit-cell audit-spoiled" onclick="_auditAnswer('spoiled')"><span aria-hidden="true">🗑️</span> ${escapeHtml(t('audit.spoiled'))}</button>`}
            <button type="button" class="audit-cell audit-edit" onclick="_auditEdit('quantity')"><span aria-hidden="true">✏️</span> ${escapeHtml(t('audit.change_qty'))}</button>
            <button type="button" class="audit-cell audit-edit" onclick="_auditEdit('location')"><span aria-hidden="true">📍</span> ${escapeHtml(t('audit.change_location'))}</button>
        </div>
        ${_auditScaleReady(it) ? `<button type="button" class="audit-scale-pill" onclick="_auditScale()"><span aria-hidden="true">⚖️</span> ${escapeHtml(t('audit.read_scale'))}</button>` : ''}`}
        <div class="audit-foot">
            ${(_audit.history.length && !answered)
                ? `<button type="button" class="audit-back" onclick="_auditUndo()">↩ ${escapeHtml(t('audit.undo_last'))}</button>`
                : ''}
            ${isSearch
                ? `<button type="button" class="audit-skip" onclick="_auditSearchBack()">🔎 ${escapeHtml(t('audit.search_another'))}</button>`
                : (isScan || answered
                    ? ''
                    : `<button type="button" class="audit-skip" onclick="_auditAnswer('skip')">${escapeHtml(t('audit.skip'))} →</button>`)}
        </div>
    </div>`;
    // The full question is the default; on a narrow screen (or a long place name) it becomes
    // the short one instead of growing into a paragraph.
    _auditFitConfirm(el);
    // «Aperto» is not one answer but two facts: THAT it is open, and SINCE WHEN. The second one
    // cannot be guessed — a box marked open three weeks late would get a PAO date three weeks too
    // far — so the card asks, in place, with the only answers a household can give at a glance.
    if (!answered && _isMedicalRow(it) && _audit.medDaysAsk) {
        const panel = el.querySelector('.audit-panel');
        if (panel) {
            panel.classList.add('is-med-days-ask');
            const ask = document.createElement('div');
            ask.className = 'audit-med-days-panel';
            const chip = (days, label) => `<button type="button" class="audit-cell audit-med-day" onclick="_auditMedOpen(${days})">${escapeHtml(label)}</button>`;
            ask.innerHTML = `<p class="audit-med-days-q">${escapeHtml(t('audit.med_days_q'))}</p>
                <div class="audit-med-days">
                    ${chip(0, t('audit.med_days_today'))}
                    ${[2, 7, 14, 30, 60, 90].map(n => chip(n, t('dashboard.days_short').replace('{n}', n))).join('')}
                </div>
                <button type="button" class="audit-skip" onclick="_auditMedDaysCancel()">${escapeHtml(t('btn.cancel'))}</button>`;
            panel.appendChild(ask);
        }
    }
}

/**
 * The line that says this row is already in the ledger — what the card is showing, and when.
 *
 * The kind is spelled out («già confermato» / «già segnato finito» / «già segnato buttato» /
 * «già saltato»): "already answered" is not the same news for a box that is there and for one that
 * was declared empty, and the household must tell the two apart at a glance. The clock is a bare
 * time (a time reads the same in six languages) taken from the answer's own timestamp.
 */
function _auditAnsweredHtml(kind, at) {
    const icon = kind === 'finished' ? '🏁' : (kind === 'spoiled' ? '🗑️' : (kind === 'skip' ? '⏭️' : '✓'));
    const label = kind === 'finished' ? t('audit.already_finished')
        : (kind === 'spoiled' ? t('audit.already_spoiled')
            : (kind === 'skip' ? t('audit.already_skipped') : t('audit.already_there')));
    let time = '';
    if (at > 0) {
        try {
            time = new Date(at).toLocaleTimeString(_collationLocale(), { hour: '2-digit', minute: '2-digit' });
        } catch (_e) { time = ''; }
    }
    return `<p class="audit-answered">
        <span class="audit-answered-ico" aria-hidden="true">${icon}</span>
        <span class="audit-answered-text">${escapeHtml(label)}</span>
        ${time ? `<span class="audit-answered-time">${escapeHtml(time)}</span>` : ''}
    </p>`;
}

/**
 * When a row was answered, from the answer that covered it (0 = unknown).
 *
 * The history is the only place that knows: an entry carries the moment of the tap. An answer that
 * arrived from ANOTHER device travelled as a ledger value (`answered: {id: kind}`) without a time,
 * so the line prints the fact and no clock instead of inventing one.
 */
function _auditAnsweredAt(invId) {
    const key = String(invId);
    const lists = [_audit && _audit.history, _auditSession && _auditSession.history];
    for (const list of lists) {
        if (!Array.isArray(list)) continue;
        for (let i = list.length - 1; i >= 0; i--) {
            const entry = list[i];
            if (entry && entry.item && String(entry.item.id) === key) return Number(entry.at) || 0;
        }
    }
    return 0;
}

/**
 * Move on without answering: the row in hand is already in the ledger.
 *
 * The forward door of an already-answered card. It is the same step an answer takes
 * (`_auditAdvancePastAnswered`), so a one-card view goes back to its sheet (the camera on the next
 * box, the search field empty) exactly as a real answer does — but it counts nothing.
 */
function _auditNext() {
    if (!_audit) return;
    _auditAdvancePastAnswered();
    _auditRender();
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
 *
 * ONE answer per ARTICLE PER PLACE. The pantry really does hold several packs of the same product
 * on the same shelf — 17 such groups in this household's own data (Amido di mais ×3, Limone ×3,
 * Riso basmati ×3, i dadi, il tonno…) — and one card per pack read as «poi mi richiede le stesse
 * cose che ho già confermato». The packs of a place are ONE question: «C'è» confirms them all,
 * «Finito»/«Andato a male» empties them all (one queued write per pack, because the API empties
 * one row per call). Another SHELF is another question, and stays one.
 */
/**
 * «Aperto» — the one question a medicine deserves in a stocktake.
 *
 * «Andato a male» is not a state a box of tablets has: what changes a medicine's date is the seal
 * being broken, and the server already knows the rule (`med_open` → opened_at + `med_pao_days`,
 * stored as a user date so a later rescan cannot undo it). The answer counts like any other — the
 * pack IS on the shelf, it is simply open now — so the ledger gets a «there» once the write is done.
 *
 * Called with no argument it ASKS since when (the chips), because a box marked open three weeks
 * late must not get a date three weeks too far.
 */
async function _auditMedOpen(days) {
    if (!_audit || _audit.finished) return;
    if (days === undefined) {
        _audit.medDaysAsk = true;
        _auditRender();
        return;
    }
    const it = _audit.items && _audit.items[_audit.index];
    if (!it) return;
    const invId = Number(it.id) || 0;
    if (!invId) return;
    _audit.medDaysAsk = false;
    let res = null;
    try {
        res = await api('med_open', { inventory_id: invId, days_ago: Number(days) || 0 });
    } catch (e) {
        showToast(t('audit.med_opened_error'), 'error');
        _auditRender();
        return;
    }
    const pao = Number(res && res.pao_days) || 0;
    if (pao > 0 && res && res.expiry_date) {
        it.opened_at = res.opened_at || it.opened_at;
        it.expiry_date = res.expiry_date;
        showToast(t('audit.med_opened_toast', { date: formatDate(res.expiry_date) }), 'success');
    } else {
        // No PAO on the product: the row is opened and the date is left alone (nothing is
        // invented), but the household is told what is missing and where to fix it.
        showToast(t('audit.med_opened_no_pao'), 'warning', 6000);
    }
    // Opening a box says HOW LONG IT KEEPS, not «yes, I have it»: counting a «there» here meant a
    // household that only marked a pack open had confirmed a stocktake answer it never gave.
    // Only the green button answers; this one just repaints the card with the new date.
    _auditRender();
}

/** Take the «da quanti giorni?» question away, leaving the pack exactly as it was. */
function _auditMedDaysCancel() {
    if (!_audit) return;
    _audit.medDaysAsk = false;
    _auditRender();
}

function _auditAnswer(kind) {
    if (!_audit || _audit.finished) return;
    _audit.medDaysAsk = false;   // any real answer closes the «da quanti giorni?» question
    const it = _audit.items[_audit.index];
    if (!it) return;
    // ONE answer per row per stocktake. The same product can reach a card twice — the barcode of a
    // box already answered, a row reopened from the queue — and a second answer would count twice in
    // the tally (and, for «finisce»/«butta», queue a second write). The session's `answered` map is
    // the ledger; `_auditUndo()` deletes the entry, so a real correction still goes through.
    if (_audit.answered && _audit.answered[String(it.id)]) {
        _auditAdvancePastAnswered();
        _auditRender();
        return;
    }
    const label = it.display_name || it.name;
    const entry = {
        item: { ...it },
        index: _audit.index,
        kind,
        // WHEN it was answered: an already-answered card prints its own clock («✓ Già confermato
        // 14:32»), and a stocktake carried on by another device needs the moment to say that.
        at: Date.now(),
        previousQuantity: parseFloat(it.quantity) || 0,
        row: null,
        rows: [],
        siblings: [],
    };
    // The other packs of the same article on the SAME shelf.
    const place = normalizeLocationKey(it.location);
    const siblings = _auditItems().filter(r => r
        && String(r.product_id) === String(it.product_id)
        && Number(r.id) !== Number(it.id)
        && normalizeLocationKey(r.location) === place
        && !(_audit.answered && _audit.answered[String(r.id)]));
    entry.siblings = siblings.map(r => Number(r.id));
    _audit.answered[String(it.id)] = kind;
    siblings.forEach(r => { _audit.answered[String(r.id)] = kind; });
    if (kind === 'finished' || kind === 'spoiled') {
        // One write per pack: `inventory_use` empties ONE row of that product in that place per
        // call, so two packs need two, and the whole shelf position really ends up empty.
        [it, ...siblings].forEach(r => {
            const row = {
                kind: kind === 'finished' ? 'use' : 'waste',
                product_id: r.product_id,
                location: r.location,
                label,
            };
            if (kind === 'spoiled') row.notes = _wasteNotesForReason('spoiled');
            entry.rows.push(row);
            _audit.queue.push(row);
        });
        entry.row = entry.rows[0];
        if (kind === 'finished') _audit.counts.finished++;
        else _audit.counts.spoiled++;
    } else if (kind === 'there') {
        _audit.counts.there++;
    } else {
        _audit.counts.skipped++;
    }
    _audit.history.push(entry);
    if (_audit.queue.length >= _auditBatchSize) _auditFlush();
    _auditAdvancePastAnswered();
    _auditPersist();
    _auditRender();
}

/** The next card this run still has a question for (a pack answered elsewhere is skipped). */
function _auditAdvancePastAnswered() {
    if (!_audit) return;
    _audit.index++;
    const answered = _audit.answered || {};
    while (_audit.index < _audit.items.length
        && answered[String(_audit.items[_audit.index].id)]) {
        _audit.index++;
    }
}

/**
 * Put the shelf back for ONE answer: revert the writes it already sent, drop the ones still in the
 * batch queue, remove its rows from the ledger and the tally. `true` when the shelf is as it was.
 *
 * Shared by «Indietro» (the last answer) and «Cambia risposta» (any answer of this stocktake): the
 * two must revert the same things, or a correction would leave half an answer behind. An answer
 * that covered several packs of the same article has several writes and several `answered` entries,
 * so the WHOLE answer is reverted, never half a shelf.
 */
async function _auditRevertEntry(entry) {
    const state = _audit;
    if (!state || !entry || !entry.item) return false;
    const rows = (Array.isArray(entry.rows) && entry.rows.length) ? entry.rows.filter(Boolean)
        : (entry.row ? [entry.row] : []);
    const stillQueued = rows.filter(r => state.queue.indexOf(r) >= 0);
    if (stillQueued.length !== rows.length) {
        // At least one write already reached the server: revert the sent ones, and keep the queued
        // ones out of the next flush.
        const sent = rows.filter(r => state.queue.indexOf(r) < 0);
        for (const row of sent) {
            if (!(await _auditUndoSent(entry, row))) return false;
        }
    }
    rows.forEach(r => {
        const at = state.queue.indexOf(r);
        if (at >= 0) state.queue.splice(at, 1);
    });
    const ledger = state.answered || {};
    delete ledger[String(entry.item.id)];
    (entry.siblings || []).forEach(id => { delete ledger[String(id)]; });
    if (state.counts[entry.kind] > 0) state.counts[entry.kind]--;
    return true;
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
    if (!(await _auditRevertEntry(entry))) {
        showToast(t('audit.undo_failed'), 'error');
        return;
    }
    state.history.pop();
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

/**
 * Answer AGAIN a row this stocktake already answered — the door of the already-answered card.
 *
 * A decision can be wrong: a box confirmed «c'è» that has in fact been eaten, or the other way
 * round. Until now the only way back was «Indietro», which reopens the LAST answer — no use for a
 * card opened by a barcode halfway through a shelf. This reverts the answer it finds (its queued
 * writes, or the ones already sent, through `_auditUndoSent`), takes it out of the ledger and the
 * tally, and puts the question back on screen. When the answer came from another device there is
 * no write of ours to revert — only the ledger entry, which is cleared the same way.
 */
async function _auditReopen(invId) {
    const state = _audit;
    if (!state) return;
    const key = String(invId);
    if (state.flushPromise) {
        try { await state.flushPromise; } catch (_e) { /* counted by the flush */ }
    }
    const lists = [state.history, _auditSession && _auditSession.history].filter(Array.isArray);
    let entry = null;
    let owner = null;
    for (const list of lists) {
        if (!Array.isArray(list)) continue;
        const found = list.find(e => e && e.item && String(e.item.id) === key);
        if (found) { entry = found; owner = list; break; }
    }
    if (entry) {
        if (!(await _auditRevertEntry(entry))) {
            showToast(t('audit.undo_failed'), 'error');
            return;
        }
        const at = owner.indexOf(entry);
        if (at >= 0) owner.splice(at, 1);
    } else {
        // The answer travelled from another device (the ledger did, the undo chain did not): there is
        // nothing of ours on the shelf, only the entry that says "answered".
        delete state.answered[key];
        if (_auditSession && _auditSession.answered) delete _auditSession.answered[key];
    }
    state.finished = false;
    const idx = state.items.findIndex(x => String(x.id) === key);
    if (idx >= 0) state.index = idx;
    _auditPersist();
    _auditRender();
    showToast(t('audit.reopen_done'), 'info');
}

/** Revert one already-sent write of an answer; true when the shelf is back as it was. */
async function _auditUndoSent(entry, row) {
    if (!row) return true;
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
    // Another PACK of the same article: back to the card the household was on, run intact — this
    // was a question inside the stocktake, not a stocktake of its own.
    if (state && state.mode === 'pack') {
        _audit = null;
        _auditPersist();
        if (!_auditSessionView()) _auditStart();
        return;
    }
    // ONE question, answered by name or by barcode: back to its sheet (the term still in the field,
    // the camera one tap away) with the answer counted in the same stocktake — never a summary of
    // a run of one.
    if (state && (state.mode === 'search' || state.mode === 'scan')) {
        const last = state.history.length ? state.history[state.history.length - 1] : null;
        const name = (last && (last.item.display_name || last.item.name)) || '';
        const errors = state.errors;
        const wasScan = state.mode === 'scan';
        _audit = null;
        // The product is answered: the field starts EMPTY again, or the next search begins with
        // the previous name in it (and its results under it) as if the confirmation had not
        // happened. The camera needs no reset: it simply opens on the next box.
        if (!wasScan) _auditSearchTerm = '';
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
                <h3>${escapeHtml(t('audit.summary_title'))}</h3>
            </div>
            <button type="button" class="audit-close" onclick="_auditEndSession(true)" aria-label="${escapeAttr(t('audit.close'))}">✕</button>
        </div>
        ${_auditProgressHtml()}
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
    // The place is the RUN's, not the pantry's: leaving the stocktake releases it, so the next one
    // starts from the place the household is standing in (`_auditScopeKey`).
    _auditScopePinned = false;
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
