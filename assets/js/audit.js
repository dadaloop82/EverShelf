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
 * "Quasi finito" and "Salta" stay as the quiet answers. The writes are queued and
 * flushed every AUDIT_MODE_BATCH_SIZE items and at the end, so a broken connection
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

/**
 * Remember the point reached. Closing the overlay by mistake (or switching away)
 * must not restart the stocktake: the run is resolved against the pantry as it is
 * when the user comes back, and everything already answered is skipped.
 */
function _auditPersist() {
    if (!_audit) return;
    try {
        localStorage.setItem(AUDIT_RESUME_KEY, JSON.stringify({
            v: 1,
            scope: _audit.scope || null,
            order: _audit.order || [],
            answered: _audit.answered || {},
            history: _audit.history || [],
            counts: _audit.counts,
            startedAt: _audit.startedAt,
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
    return { there: 0, almost: 0, finished: 0, spoiled: 0, skipped: 0 };
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
 * The sentence the card reads out. Quantity, article, brand and place are the
 * facts being checked, so each one is emphasised in the sentence (the quantity
 * also gets its own size/colour via `.audit-qty`) instead of living in a muted
 * meta line. Values are escaped; only the markup around them is ours.
 */
function _auditSentence(it) {
    const locInfo = LOCATIONS[it.location] || { icon: '📦', label: it.location || '' };
    const qty = escapeHtml(stripHtml(formatQuantity(it.quantity, it.unit, it.default_quantity, it.package_unit)));
    const name = escapeHtml(it.display_name || it.name || '');
    const brand = escapeHtml(it.brand || '');
    const loc = `<strong class="audit-em audit-loc"><span aria-hidden="true">${escapeHtml(locInfo.icon || '')}</span> ${escapeHtml(locInfo.label || it.location || '')}</strong>`;
    let out = t(brand ? 'audit.sentence_brand' : 'audit.sentence');
    out = out.replace('{qty}', `<strong class="audit-qty">${qty}</strong>`);
    out = out.replace('{name}', `<strong class="audit-em audit-name">${name}</strong>`);
    out = out.replace('{brand}', `<strong class="audit-em audit-brand">${brand}</strong>`);
    out = out.replace('{location}', loc);
    return out;
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
    if (!key) {
        _auditStart();
        return;
    }
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
    if (!items.length) {
        showToast(t('audit.empty'), 'info');
        return;
    }
    const saved = _auditSavedRun();
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
    const el = document.getElementById('audit-overlay');
    if (!el) return;
    el.hidden = false;
    el.innerHTML = `<div class="audit-panel">
        <div class="audit-head">
            <h3>${escapeHtml(t('audit.title'))}</h3>
            <button type="button" class="audit-close" onclick="_auditStop()" aria-label="${escapeHtml(t('audit.close'))}">✕</button>
        </div>
        ${saved ? `<p class="audit-hint audit-resume-hint">${escapeHtml(t('audit.resume_hint'))}</p>` : ''}
        <p class="audit-hint">${escapeHtml(t('audit.scope_hint'))}</p>
        <div class="audit-scopes">${scopes.join('')}</div>
    </div>`;
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

function _auditBegin(location, saved = null) {
    // Same comparison on both sides: a row may store the slug while the scope is a label.
    const items = saved ? saved.items : _auditItems().filter(it => !location || normalizeLocationKey(it.location) === location);
    _audit = {
        items,
        index: 0,
        queue: [],
        errors: 0,
        counts: saved && saved.counts
            ? { ..._auditEmptyCounts(), ...saved.counts }
            : _auditEmptyCounts(),
        scope: saved ? (saved.scope || null) : (location || null),
        order: saved && Array.isArray(saved.order) ? saved.order.slice() : items.map(it => it.id),
        answered: saved && saved.answered ? { ...saved.answered } : {},
        history: saved && Array.isArray(saved.history) ? saved.history.slice() : [],
        startedAt: saved && saved.startedAt ? saved.startedAt : Date.now(),
        finished: false,
        flushPromise: null,
    };
    if (!_audit.items.length) {
        // Nothing matched the scope (a row moved between the banner and the tap): say it
        // instead of opening an empty wizard.
        _audit = null;
        _auditStop();
        showToast(t('audit.empty'), 'info');
        return;
    }
    _auditPersist();
    _auditRender();
}

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
    const total = _audit.items.length;
    const done = _audit.index;
    el.hidden = false;
    const qtyPlain = (typeof stripHtml === 'function' && typeof formatQuantity === 'function')
        ? stripHtml(formatQuantity(it.quantity, it.unit, it.default_quantity, it.package_unit))
        : `${it.quantity || ''} ${it.unit || ''}`.trim();
    const confirmLabel = t('audit.confirm_yes_qty').replace('{qty}', qtyPlain || String(it.quantity || ''));
    const locMeta = (typeof LOCATIONS !== 'undefined' && LOCATIONS[normalizeLocationKey(it.location)])
        || { icon: '📦', label: it.location || '' };
    const placeTitle = t('audit.verify_place').replace('{place}', locMeta.label || t('audit.title'));
    const thumb = (typeof _productThumbHtml === 'function') ? _productThumbHtml(it) : '';
    el.innerHTML = `<div class="audit-panel" role="dialog" aria-modal="true" aria-label="${escapeHtml(t('audit.title'))}">
        <div class="audit-progress"><span style="width:${Math.round((done / total) * 100)}%"></span></div>
        <div class="audit-head">
            <div class="audit-head-text">
                <p class="audit-eyebrow">${escapeHtml(placeTitle)}</p>
                <p class="audit-question"><span>${escapeHtml(t('audit.question'))}</span><b>${done + 1}/${total}</b></p>
            </div>
            <button type="button" class="audit-close" onclick="_auditStop()" aria-label="${escapeHtml(t('audit.close'))}">✕</button>
        </div>
        <div class="audit-product-row">
            <div class="audit-product-thumb">${thumb}</div>
            <div class="audit-product-main">
                <p class="audit-product-name">${escapeHtml(it.display_name || it.name || '')}${it.brand ? ` <span class="audit-product-brand">${escapeHtml(it.brand)}</span>` : ''}</p>
                <p class="audit-product-qty">${escapeHtml(t('audit.question'))}: <strong>${escapeHtml(qtyPlain)}</strong></p>
            </div>
        </div>
        <p class="audit-sentence" aria-live="polite">${_auditSentence(it)}</p>
        <div class="audit-answers">
            <button type="button" class="audit-answer audit-there" onclick="_auditAnswer('there')"><span aria-hidden="true">✓</span> ${escapeHtml(confirmLabel)}</button>
            <div class="audit-edit-row">
                <button type="button" class="audit-answer audit-almost" onclick="_auditAnswer('almost')"><span aria-hidden="true">⚠️</span> ${escapeHtml(t('audit.almost_out'))}</button>
                <button type="button" class="audit-answer audit-edit" onclick="_auditEdit('quantity')"><span aria-hidden="true">✏️</span> ${escapeHtml(t('audit.change_qty'))}</button>
            </div>
            ${_auditScaleReady(it) ? `<button type="button" class="audit-answer audit-scale" onclick="_auditScale()"><span aria-hidden="true">⚖️</span> ${escapeHtml(t('audit.read_scale'))}</button>` : ''}
            <div class="audit-edit-row">
                <button type="button" class="audit-answer audit-finished" onclick="_auditAnswer('finished')"><span aria-hidden="true">✕</span> ${escapeHtml(t('audit.exhausted'))}</button>
                <button type="button" class="audit-answer audit-spoiled" onclick="_auditAnswer('spoiled')"><span aria-hidden="true">🗑️</span> ${escapeHtml(t('audit.spoiled'))}</button>
            </div>
            <button type="button" class="audit-answer audit-edit" onclick="_auditEdit('location')"><span aria-hidden="true">📍</span> ${escapeHtml(t('audit.change_location'))}</button>
        </div>
        <div class="audit-foot">
            ${_audit.history.length ? `<button type="button" class="audit-back" onclick="_auditUndo()">↩ ${escapeHtml(t('audit.undo_last'))}</button>` : ''}
            <button type="button" class="audit-skip" onclick="_auditAnswer('skip')">${escapeHtml(t('audit.skip'))} →</button>
        </div>
    </div>`;
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
 *  - "almost" lowers the row to the unit's low-stock value (`inventory_update`);
 *  - "finished" is the real "usa tutto": `inventory_use` + use_all, so consumption
 *    stats, the shopping list and the depleted-product rules all react;
 *  - "spoiled" is the real "butta tutto": the same call with a waste reason, so it
 *    is counted as waste (and feeds the waste-learning hints).
 */
function _auditAnswer(kind) {
    if (!_audit || _audit.finished) return;
    const it = _audit.items[_audit.index];
    if (!it) return;
    const label = it.display_name || it.name;
    const entry = {
        item: { ...it },
        index: _audit.index,
        kind,
        previousQuantity: parseFloat(it.quantity) || 0,
        row: null,
    };
    _audit.answered[String(it.id)] = kind;
    if (kind === 'almost') {
        entry.row = { kind: 'update', id: it.id, quantity: _auditAlmostOutQty(it), label };
        _audit.queue.push(entry.row);
        _audit.counts.almost++;
    } else if (kind === 'finished') {
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
    // The run stays in memory (not on disk) so the summary can undo the last
    // answer; Done/Escape drops it.
    if (state) state.finished = true;
    _auditClearPersist();
    const el = document.getElementById('audit-overlay');
    if (!el) return;
    const c = state ? state.counts : _auditEmptyCounts();
    const errors = state ? state.errors : 0;
    el.hidden = false;
    el.innerHTML = `<div class="audit-panel">
        <div class="audit-head">
            <div class="audit-head-text">
                <p class="audit-eyebrow">${escapeHtml(t('audit.title'))}</p>
                <h3>${escapeHtml(t('audit.summary_title'))}</h3>
            </div>
            <button type="button" class="audit-close" onclick="_auditStop(true)" aria-label="${escapeHtml(t('audit.close'))}">✕</button>
        </div>
        <p class="audit-summary">${t('audit.summary_line')
            .replace('{there}', c.there)
            .replace('{almost}', c.almost)
            .replace('{finished}', c.finished)
            .replace('{spoiled}', c.spoiled)
            .replace('{skipped}', c.skipped)}</p>
        ${errors ? `<p class="audit-hint">${escapeHtml(t('audit.summary_errors').replace('{n}', errors))}</p>` : ''}
        ${state && state.history.length ? `<button type="button" class="audit-undo" onclick="_auditUndo()">↩ ${escapeHtml(t('audit.undo_last'))}</button>` : ''}
        <button type="button" class="audit-done" onclick="_auditStop(true)">${escapeHtml(t('audit.done'))}</button>
    </div>`;
}

/** Leave the overlay. `reload` refreshes the pantry with what was just written. */
function _auditStop(reload = false) {
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
document.addEventListener('keydown', (ev) => {
    if (!_audit) return;
    // The edit sheet sits ON TOP of the stocktake: while it is open the keys
    // belong to the form, never to the card underneath it.
    const modal = document.getElementById('modal-overlay');
    if (modal && modal.style.display === 'flex') return;
    const active = document.activeElement;
    if (active && ['INPUT', 'TEXTAREA', 'SELECT'].includes(active.tagName)) return;
    if (_audit.finished) {
        // The summary stays live so the last answer can still be undone.
        if (ev.key === 'Escape') _auditStop(true);
        else if (ev.key === 'Backspace') { ev.preventDefault(); _auditUndo(); }
        return;
    }
    if (ev.key === 'Escape') {
        _auditStop(true);
    } else if (ev.key === 'ArrowRight') {
        ev.preventDefault();
        _auditAnswer('there');
    } else if (ev.key === 'ArrowDown') {
        ev.preventDefault();
        _auditAnswer('almost');
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
