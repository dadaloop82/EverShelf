/** EverShelf physical inventory reconciliation UI. */

let _reconciliationState = null;
let _reconciliationLoadToken = 0;
let _reconciliationSearchTimer = null;

function reconciliationSessionState(result, previous = null) {
    return {
        session: result.session,
        items: result.items || [],
        showZeroItems: previous?.showZeroItems === true,
        zeroItemsLoaded: previous?.zeroItemsLoaded === true,
        zeroItemsLoading: false,
        zeroItems: previous?.zeroItems || [],
    };
}

function reconciliationText(key, fallback) {
    const value = typeof t === 'function' ? t(`reconciliation.${key}`) : '';
    return !value || value === `reconciliation.${key}` ? fallback : value;
}

function reconciliationIsOnline() {
    const appOffline = typeof _offlineMode !== 'undefined' && _offlineMode;
    return !appOffline && navigator.onLine !== false;
}

function reconciliationSetOfflineBanner() {
    const banner = document.getElementById('reconciliation-offline');
    if (banner) banner.hidden = reconciliationIsOnline();
}

function reconciliationRefresh() {
    initReconciliationPage(_reconciliationState?.session?.id || null);
}

function reconciliationLocationMeta(location) {
    if (typeof LOCATIONS !== 'undefined' && LOCATIONS[location]) return LOCATIONS[location];
    return { icon: '📦', label: location };
}

function reconciliationUnitLabel(unit) {
    const raw = String(unit || '');
    if (typeof getUnitDisplayLabel === 'function') return getUnitDisplayLabel(raw);
    const translated = typeof t === 'function' ? t(`units.${raw}`) : '';
    return translated && translated !== `units.${raw}` ? translated : raw;
}

function reconciliationQuantity(value, item) {
    if (typeof formatQuantity === 'function') {
        return formatQuantity(value, item.product_unit, item.product_default_quantity, item.product_package_unit);
    }
    return `${Number(value)} ${escapeHtml(reconciliationUnitLabel(item.product_unit))}`;
}

function reconciliationProductImageHtml(item) {
    const raw = String(item.product_image_url || item.image_url || '').trim();
    const valid = raw && (typeof _validProductImageUrl !== 'function' || _validProductImageUrl(raw));
    let safeUrl = '';
    if (valid) {
        try {
            const parsed = new URL(raw, window.location.href);
            if (parsed.protocol === 'http:' || parsed.protocol === 'https:' || parsed.protocol === 'data:') {
                safeUrl = escapeHtml(parsed.href);
            }
        } catch (_) { /* use fallback */ }
    }
    return `<div class="reconciliation-product-image"><span aria-hidden="true">📦</span>${safeUrl ? `<img src="${safeUrl}" alt="" loading="lazy" onerror="this.remove()">` : ''}</div>`;
}

function reconciliationErrorMessage(result) {
    const key = result?.error || 'request_failed';
    const fallbacks = {
        inventory_changed: 'Inventory changed while you were counting. Nothing was applied; review the highlighted conflict and recount.',
        no_counted_items: 'Count at least one product before review.',
        invalid_count: 'Enter a valid count of zero or more.',
        reconciliation_not_editable: 'This count can no longer be edited.',
        reconciliation_must_be_reviewed: 'Review the count before applying it.',
        product_not_found: 'This product no longer exists in the catalog.',
        request_failed: 'The request could not be completed.',
    };
    const translated = reconciliationText(`errors.${key}`, '');
    return translated || fallbacks[key] || key.replaceAll('_', ' ');
}

async function initReconciliationPage(sessionId = null) {
    reconciliationSetOfflineBanner();
    const content = document.getElementById('reconciliation-content');
    if (!content) return;
    const token = ++_reconciliationLoadToken;
    content.innerHTML = `<div class="reconciliation-loading"><div class="loading-spinner"></div><span>${escapeHtml(reconciliationText('loading', 'Loading count…'))}</span></div>`;

    if (!reconciliationIsOnline()) {
        reconciliationRenderOffline();
        return;
    }

    try {
        if (sessionId) {
            const result = await api('reconciliation_get', { id: sessionId });
            if (token !== _reconciliationLoadToken) return;
            if (!result?.success) throw result;
            _reconciliationState = reconciliationSessionState(result);
            reconciliationRenderSession();
            return;
        }

        const result = await api('reconciliation_list');
        if (token !== _reconciliationLoadToken) return;
        if (!result?.success) throw result;
        const rows = result.reconciliations || [];
        _reconciliationState = null;
        reconciliationRenderLanding(rows);
    } catch (error) {
        if (token !== _reconciliationLoadToken) return;
        content.innerHTML = `<div class="reconciliation-error">${escapeHtml(reconciliationErrorMessage(error))}</div>`;
    }
}

function reconciliationRenderOffline() {
    const content = document.getElementById('reconciliation-content');
    if (!content) return;
    content.innerHTML = `
        <div class="reconciliation-empty">
            <span class="reconciliation-empty-icon">📡</span>
            <h3>${escapeHtml(reconciliationText('online_only_title', 'Connection required'))}</h3>
            <p>${escapeHtml(reconciliationText('online_only_body', 'Physical counts are online-only and are never added to the offline write queue.'))}</p>
        </div>`;
}

function reconciliationRenderLanding(rows) {
    const content = document.getElementById('reconciliation-content');
    const locations = typeof LOCATIONS !== 'undefined' ? LOCATIONS : {
        dispensa: { icon: '🗄️', label: 'Pantry' },
        frigo: { icon: '🧊', label: 'Fridge' },
        freezer: { icon: '❄️', label: 'Freezer' },
        altro: { icon: '📦', label: 'Other' },
    };
    const activeRows = rows.filter(row => row.status === 'in_progress' || row.status === 'review');
    const activeByLocation = new Map(activeRows.map(row => [row.location, row]));
    const locationButtons = Object.entries(locations).map(([key, meta]) => {
        const active = activeByLocation.get(key);
        const action = active
            ? `showPage('reconciliation', ${Number(active.id)})`
            : `reconciliationStart('${escapeHtml(key)}')`;
        return `
        <button type="button" class="reconciliation-location${active ? ' has-active' : ''}" onclick="${action}">
            <span>${meta.icon}</span><strong>${escapeHtml(meta.label)}</strong>
            ${active ? `<small>${escapeHtml(reconciliationText('resume', 'Resume count'))}</small>` : ''}
        </button>`;
    }).join('');

    const history = rows.filter(row => row.status === 'applied' || row.status === 'cancelled');
    const historyHtml = history.length ? history.map(row => {
        const meta = reconciliationLocationMeta(row.location);
        const date = typeof formatDateTime === 'function' ? formatDateTime(row.started_at) : row.started_at;
        return `
            <button type="button" class="reconciliation-history-row" onclick="showPage('reconciliation', ${Number(row.id)})">
                <span class="reconciliation-history-icon">${meta.icon}</span>
                <span class="reconciliation-history-main">
                    <strong>${escapeHtml(meta.label)}</strong>
                    <small>${escapeHtml(date || '')} · ${Number(row.counted_items)}/${Number(row.total_items)}</small>
                </span>
                <span class="reconciliation-status status-${escapeHtml(row.status)}">${escapeHtml(reconciliationText(`status.${row.status}`, row.status))}</span>
            </button>`;
    }).join('') : `<p class="reconciliation-muted">${escapeHtml(reconciliationText('no_history', 'No previous physical counts.'))}</p>`;

    const activeHtml = activeRows.length ? `
        <section class="reconciliation-active-list">
            <h3>${escapeHtml(reconciliationText('active_counts', 'Active counts'))}</h3>
            ${activeRows.map(row => {
                const rowMeta = reconciliationLocationMeta(row.location);
                return `<button type="button" class="reconciliation-history-row" onclick="showPage('reconciliation', ${Number(row.id)})">
                    <span class="reconciliation-history-icon">${rowMeta.icon}</span>
                    <span class="reconciliation-history-main"><strong>${escapeHtml(rowMeta.label)}</strong><small>${Number(row.counted_items)}/${Number(row.total_items)}</small></span>
                    <span class="reconciliation-status status-${escapeHtml(row.status)}">${escapeHtml(reconciliationText(`status.${row.status}`, row.status))}</span>
                </button>`;
            }).join('')}
        </section>` : '';

    content.innerHTML = `
        <div class="reconciliation-intro">
            <span class="reconciliation-intro-icon">⚖️</span>
            <div>
                <h3>${escapeHtml(reconciliationText('start_title', 'Start a physical count'))}</h3>
                <p>${escapeHtml(reconciliationText('start_body', 'Choose one location. Counting does not change live stock until you review and apply.'))}</p>
            </div>
        </div>
        ${activeHtml}
        <div class="reconciliation-locations">${locationButtons}</div>
        <section class="reconciliation-history">
            <h3>${escapeHtml(reconciliationText('history', 'History'))}</h3>
            ${historyHtml}
        </section>`;
}

async function reconciliationStart(location) {
    if (!reconciliationIsOnline()) return reconciliationOfflineToast();
    const content = document.getElementById('reconciliation-content');
    if (content) content.classList.add('is-busy');
    try {
        const result = await api('reconciliation_start', {}, 'POST', { location });
        if (!result?.success) throw result;
        _reconciliationState = reconciliationSessionState(result);
        showPage('reconciliation', result.session.id, { skipHistory: true });
    } catch (error) {
        showToast(reconciliationErrorMessage(error), 'error');
    } finally {
        if (content) content.classList.remove('is-busy');
    }
}

function reconciliationRenderSession() {
    const content = document.getElementById('reconciliation-content');
    if (!content || !_reconciliationState) return;
    const session = _reconciliationState.session;
    const items = _reconciliationState.items || [];
    const meta = reconciliationLocationMeta(session.location);
    const editable = session.status === 'in_progress' || session.status === 'review';
    const review = session.status === 'review';
    const terminal = session.status === 'applied' || session.status === 'cancelled';
    const progress = session.total_items > 0
        ? Math.round((session.counted_items / session.total_items) * 100)
        : 0;

    const warning = review ? `
        <div class="reconciliation-review-warning">
            <strong>${escapeHtml(reconciliationText('review_title', 'Review before applying'))}</strong>
            <span>${escapeHtml(reconciliationText('uncounted_warning', '{n} uncounted products will remain unchanged.').replace('{n}', session.uncounted_items))}</span>
        </div>` : '';

    const terminalMessage = terminal ? `
        <div class="reconciliation-terminal status-${escapeHtml(session.status)}">
            <strong>${escapeHtml(reconciliationText(`terminal.${session.status}_title`, session.status === 'applied' ? 'Count applied' : 'Count cancelled'))}</strong>
            <span>${escapeHtml(reconciliationText(`terminal.${session.status}_body`, session.status === 'applied' ? 'The signed adjustments are stored in the audit ledger.' : 'Live inventory was not changed.'))}</span>
        </div>` : '';

    const tools = editable ? `
        <div class="reconciliation-tools">
            <button type="button" class="btn btn-primary" onclick="reconciliationStartScanner()">📷 ${escapeHtml(reconciliationText('scan', 'Scan product'))}</button>
            <div class="reconciliation-catalog-search">
                <input type="search" id="reconciliation-product-search" placeholder="${escapeHtml(reconciliationText('catalog_search', 'Find another catalog product…'))}" oninput="reconciliationSearchCatalog(this.value)" autocomplete="off">
                <div id="reconciliation-product-results" class="reconciliation-product-results"></div>
            </div>
        </div>` : '';

    const sortItems = source => review ? [...source].sort((a, b) => {
        const group = item => item.counted_quantity === null ? 2 : (Math.abs(Number(item.variance)) > 0.000001 ? 0 : 1);
        return group(a) - group(b) || String(a.product_name).localeCompare(String(b.product_name));
    }) : source;
    const zeroProductIds = new Set((_reconciliationState.zeroItems || []).map(item => Number(item.product_id)));
    const primaryItems = _reconciliationState.showZeroItems
        ? items.filter(item => !zeroProductIds.has(Number(item.product_id)))
        : items;
    const primaryList = primaryItems.length ? sortItems(primaryItems).map(item => reconciliationItemHtml(item, editable)).join('') : `
        <div class="reconciliation-empty compact">
            <span class="reconciliation-empty-icon">📭</span>
            <p>${escapeHtml(reconciliationText('empty_location', 'No stock was recorded here. Scan or search for anything you physically find.'))}</p>
        </div>`;

    let zeroList = '';
    if (editable && _reconciliationState.showZeroItems) {
        if (_reconciliationState.zeroItemsLoading) {
            zeroList = `<div class="reconciliation-zero-loading"><div class="loading-spinner"></div><span>${escapeHtml(reconciliationText('zero_loading', 'Loading zero-quantity items…'))}</span></div>`;
        } else {
            const sessionItemsByProduct = new Map(items.map(item => [Number(item.product_id), item]));
            const zeroItems = (_reconciliationState.zeroItems || []).map(item => sessionItemsByProduct.get(Number(item.product_id)) || item);
            const zeroCards = zeroItems.length
                ? sortItems(zeroItems).map(item => reconciliationItemHtml(item, editable)).join('')
                : `<div class="reconciliation-empty compact"><p>${escapeHtml(reconciliationText('zero_empty', 'No zero-quantity inventory items at this location.'))}</p></div>`;
            zeroList = `
                <section class="reconciliation-zero-group" data-reconciliation-group>
                    <div class="reconciliation-zero-divider"><span>${escapeHtml(reconciliationText('zero_section', 'Zero-quantity inventory'))}</span></div>
                    <p class="reconciliation-zero-hint">${escapeHtml(reconciliationText('zero_hint', 'These catalog products have no expected stock at this location.'))}</p>
                    <div class="reconciliation-zero-items">${zeroCards}</div>
                </section>`;
        }
    }

    const zeroToggle = editable ? `
        <label class="reconciliation-zero-toggle">
            <input type="checkbox" ${_reconciliationState.showZeroItems ? 'checked' : ''} onchange="reconciliationToggleZeroItems(this.checked)">
            <span><strong>${escapeHtml(reconciliationText('show_zero', 'Show zero-quantity items'))}</strong><small>${escapeHtml(reconciliationText('show_zero_hint', 'Optional: check for products the system thinks are empty.'))}</small></span>
        </label>` : '';

    let actions = '';
    if (session.status === 'in_progress') {
        actions = `
            <button type="button" class="btn btn-primary full-width" ${session.counted_items < 1 ? 'disabled' : ''} onclick="reconciliationReview()">
                ${escapeHtml(reconciliationText('review_button', 'Review count'))} →
            </button>
            <button type="button" class="btn reconciliation-cancel-btn" onclick="reconciliationCancel()">${escapeHtml(reconciliationText('cancel', 'Cancel count'))}</button>`;
    } else if (review) {
        actions = `
            <button type="button" class="btn btn-primary full-width" onclick="reconciliationApply()">✓ ${escapeHtml(reconciliationText('apply', 'Apply counted changes'))}</button>
            <p class="reconciliation-muted center">${escapeHtml(reconciliationText('edit_hint', 'Edit and save any count to return to counting mode.'))}</p>
            <button type="button" class="btn reconciliation-cancel-btn" onclick="reconciliationCancel()">${escapeHtml(reconciliationText('cancel', 'Cancel count'))}</button>`;
    } else {
        actions = `<button type="button" class="btn full-width" onclick="showPage('reconciliation', null)">${escapeHtml(reconciliationText('back_to_history', 'Back to counts'))}</button>`;
    }

    content.innerHTML = `
        <div class="reconciliation-session-head">
            <div class="reconciliation-session-location"><span>${meta.icon}</span><div><strong>${escapeHtml(meta.label)}</strong><small>#${Number(session.id)}</small></div></div>
            <span class="reconciliation-status status-${escapeHtml(session.status)}">${escapeHtml(reconciliationText(`status.${session.status}`, session.status))}</span>
        </div>
        <div class="reconciliation-progress-meta"><span>${escapeHtml(reconciliationText('progress', '{counted} of {total} counted').replace('{counted}', session.counted_items).replace('{total}', session.total_items))}</span><strong>${progress}%</strong></div>
        <div class="reconciliation-progress"><span style="width:${progress}%"></span></div>
        ${warning}${terminalMessage}${tools}${zeroToggle}
        <div class="reconciliation-list-filter">
            <input type="search" id="reconciliation-list-search" placeholder="${escapeHtml(reconciliationText('filter', 'Filter this count…'))}" oninput="reconciliationFilterItems(this.value)" autocomplete="off">
        </div>
        <div class="reconciliation-items" id="reconciliation-items">${primaryList}${zeroList}</div>
        <div class="reconciliation-actions">${actions}</div>`;
}

async function reconciliationToggleZeroItems(checked) {
    if (!_reconciliationState?.session?.id) return;
    _reconciliationState.showZeroItems = Boolean(checked);
    if (!checked || _reconciliationState.zeroItemsLoaded) {
        reconciliationRenderSession();
        return;
    }

    const sessionId = Number(_reconciliationState.session.id);
    _reconciliationState.zeroItemsLoading = true;
    reconciliationRenderSession();
    try {
        const result = await api('reconciliation_zero_items', { id: sessionId });
        if (!result?.success) throw result;
        if (Number(_reconciliationState?.session?.id) !== sessionId) return;
        _reconciliationState.zeroItems = result.items || [];
        _reconciliationState.zeroItemsLoaded = true;
    } catch (error) {
        if (Number(_reconciliationState?.session?.id) !== sessionId) return;
        _reconciliationState.showZeroItems = false;
        showToast(reconciliationErrorMessage(error), 'error');
    } finally {
        if (Number(_reconciliationState?.session?.id) === sessionId) {
            _reconciliationState.zeroItemsLoading = false;
            reconciliationRenderSession();
        }
    }
}

function reconciliationItemHtml(item, editable) {
    const counted = item.counted_quantity !== null;
    const variance = item.variance;
    const varianceClass = !counted || Math.abs(variance) < 0.000001 ? 'same' : (variance > 0 ? 'surplus' : 'shortage');
    const varianceText = !counted
        ? reconciliationText('uncounted', 'Uncounted')
        : (Math.abs(variance) < 0.000001
            ? reconciliationText('matches', 'Matches')
            : `${variance > 0 ? '+' : ''}${variance} ${reconciliationUnitLabel(item.product_unit)}`);
    const search = `${item.product_name} ${item.product_brand || ''}`.toLowerCase();
    const step = ['g', 'ml'].includes(item.product_unit) ? '1' : '0.01';
    const controls = editable ? `
        <div class="reconciliation-count-control">
            <input type="number" min="0" max="1000000000" step="${step}" id="reconciliation-count-${Number(item.product_id)}" value="${counted ? Number(item.counted_quantity) : ''}" placeholder="—" onkeydown="if(event.key==='Enter'){event.preventDefault();reconciliationSaveInline(${Number(item.product_id)})}">
            <span>${escapeHtml(reconciliationUnitLabel(item.product_unit))}</span>
            <button type="button" onclick="reconciliationSetZero(${Number(item.product_id)})">0</button>
            <button type="button" class="primary" onclick="reconciliationSaveInline(${Number(item.product_id)})">${escapeHtml(reconciliationText('save', 'Save'))}</button>
        </div>
        ${!counted ? `<button type="button" class="reconciliation-skip" onclick="reconciliationSkip(${Number(item.product_id)})">${escapeHtml(reconciliationText('skip', 'Skip for now'))} ↓</button>` : ''}` : `<div class="reconciliation-readonly-count">${counted ? reconciliationQuantity(item.counted_quantity, item) : escapeHtml(reconciliationText('uncounted', 'Uncounted'))}</div>`;

    return `
        <article class="reconciliation-item ${counted ? 'is-counted' : 'is-uncounted'}" data-search="${escapeHtml(search)}" id="reconciliation-item-${Number(item.product_id)}">
            <div class="reconciliation-item-main">
                ${reconciliationProductImageHtml(item)}
                <div class="reconciliation-item-copy">
                    <strong>${escapeHtml(item.product_name)}</strong>
                    ${item.product_brand ? `<small>${escapeHtml(item.product_brand)}</small>` : ''}
                    <span class="reconciliation-variance ${varianceClass}">${escapeHtml(varianceText)}</span>
                </div>
                <div class="reconciliation-expected"><span>${escapeHtml(reconciliationText('expected', 'Expected'))}${Number(item.snapshot_row_count) > 1 ? ` · ${escapeHtml(reconciliationText('lots', '{n} lots').replace('{n}', item.snapshot_row_count))}` : ''}</span><strong>${reconciliationQuantity(item.expected_quantity, item)}</strong></div>
            </div>
            ${controls}
        </article>`;
}

function reconciliationFilterItems(value) {
    const query = String(value || '').trim().toLowerCase();
    document.querySelectorAll('#reconciliation-items .reconciliation-item').forEach(card => {
        card.hidden = query !== '' && !String(card.dataset.search || '').includes(query);
    });
    document.querySelectorAll('[data-reconciliation-group]').forEach(group => {
        group.hidden = query !== '' && !group.querySelector('.reconciliation-item:not([hidden])');
    });
}

function reconciliationSkip(productId) {
    const cards = [...document.querySelectorAll('#reconciliation-items .reconciliation-item.is-uncounted:not([hidden])')];
    const currentIndex = cards.findIndex(card => card.id === `reconciliation-item-${productId}`);
    const next = cards[currentIndex + 1] || cards[0];
    if (next && next.id !== `reconciliation-item-${productId}`) {
        next.scrollIntoView({ behavior: 'smooth', block: 'center' });
        next.querySelector('input')?.focus({ preventScroll: true });
    }
}

async function reconciliationSaveInline(productId) {
    const input = document.getElementById(`reconciliation-count-${productId}`);
    if (!input || input.value === '') {
        showToast(reconciliationText('enter_count', 'Enter a count, including zero.'), 'warning');
        input?.focus();
        return;
    }
    await reconciliationSaveCount(productId, input.value, input);
}

async function reconciliationSetZero(productId) {
    const input = document.getElementById(`reconciliation-count-${productId}`);
    if (input) input.value = '0';
    await reconciliationSaveCount(productId, 0, input);
}

async function reconciliationSaveCount(productId, quantity, control = null) {
    if (!reconciliationIsOnline()) return reconciliationOfflineToast();
    if (!_reconciliationState?.session?.id) return;
    if (control) control.disabled = true;
    try {
        const result = await api('reconciliation_count', {}, 'POST', {
            session_id: _reconciliationState.session.id,
            product_id: Number(productId),
            counted_quantity: Number(quantity),
        });
        if (!result?.success) throw result;
        _reconciliationState = reconciliationSessionState(result, _reconciliationState);
        reconciliationRenderSession();
        showToast(reconciliationText('count_saved', 'Count saved.'), 'success');
    } catch (error) {
        showToast(reconciliationErrorMessage(error), 'error');
        if (control) control.disabled = false;
    }
}

function reconciliationSearchCatalog(value) {
    clearTimeout(_reconciliationSearchTimer);
    const results = document.getElementById('reconciliation-product-results');
    const query = String(value || '').trim();
    if (!results) return;
    if (query.length < 2) {
        results.innerHTML = '';
        return;
    }
    _reconciliationSearchTimer = setTimeout(async () => {
        try {
            const response = await api('products_search', { q: query });
            if (!response?.products) throw response;
            const products = response.products.slice(0, 8);
            results.innerHTML = products.length ? products.map(product => {
                const encoded = encodeURIComponent(JSON.stringify({
                    id: Number(product.id),
                    name: String(product.name || ''),
                    brand: String(product.brand || ''),
                    image_url: String(product.image_url || ''),
                    unit: String(product.unit || 'pz'),
                    default_quantity: Number(product.default_quantity || 1),
                    package_unit: String(product.package_unit || ''),
                }));
                return `
                <button type="button" data-product="${escapeHtml(encoded)}" onclick="reconciliationOpenCount(JSON.parse(decodeURIComponent(this.dataset.product)))">
                    <strong>${escapeHtml(product.name)}</strong><small>${escapeHtml(product.brand || product.barcode || '')}</small>
                </button>`;
            }).join('') : `<p>${escapeHtml(reconciliationText('no_products', 'No catalog products found.'))}</p>`;
        } catch (error) {
            results.innerHTML = `<p>${escapeHtml(reconciliationErrorMessage(error))}</p>`;
        }
    }, 250);
}

function reconciliationOpenCount(product) {
    const existing = (_reconciliationState?.items || []).find(item => Number(item.product_id) === Number(product.id));
    const item = existing || {
        product_id: Number(product.id),
        product_name: product.name,
        product_brand: product.brand || '',
        product_image_url: product.image_url || '',
        product_unit: product.unit || 'pz',
        product_default_quantity: product.default_quantity || 1,
        product_package_unit: product.package_unit || '',
        expected_quantity: 0,
        counted_quantity: null,
    };
    const modal = document.getElementById('modal-content');
    const overlay = document.getElementById('modal-overlay');
    if (!modal || !overlay) return;
    modal.innerHTML = `
        <div class="reconciliation-count-modal">
            <div class="modal-header"><h3>${escapeHtml(item.product_name)}</h3><button type="button" class="modal-close" onclick="closeModal()">✕</button></div>
            ${item.product_brand ? `<p>${escapeHtml(item.product_brand)}</p>` : ''}
            <div class="reconciliation-modal-expected">${escapeHtml(reconciliationText('expected', 'Expected'))}: <strong>${reconciliationQuantity(item.expected_quantity, item)}</strong></div>
            <label for="reconciliation-modal-count">${escapeHtml(reconciliationText('physical_count', 'Physical count'))}</label>
            <div class="reconciliation-modal-input"><input id="reconciliation-modal-count" type="number" min="0" step="any" value="${item.counted_quantity ?? ''}" autofocus><span>${escapeHtml(reconciliationUnitLabel(item.product_unit))}</span></div>
            <div class="reconciliation-modal-actions"><button type="button" class="btn" onclick="document.getElementById('reconciliation-modal-count').value='0'">0</button><button type="button" class="btn btn-primary" onclick="reconciliationSaveModal(${Number(item.product_id)})">${escapeHtml(reconciliationText('save', 'Save'))}</button></div>
        </div>`;
    overlay.style.display = 'flex';
    setTimeout(() => document.getElementById('reconciliation-modal-count')?.focus(), 50);
}

async function reconciliationSaveModal(productId) {
    const input = document.getElementById('reconciliation-modal-count');
    if (!input || input.value === '') {
        showToast(reconciliationText('enter_count', 'Enter a count, including zero.'), 'warning');
        return;
    }
    const value = input.value;
    closeModal();
    await reconciliationSaveCount(productId, value);
}

function reconciliationStartScanner() {
    if (!reconciliationIsOnline()) return reconciliationOfflineToast();
    const sessionId = _reconciliationState?.session?.id;
    if (!sessionId) return;
    window._evershelfBarcodeConsumer = async barcode => {
        showLoading(true);
        try {
            const result = await api('resolve_barcode', { barcode, read_only: 1 });
            const product = result?.source === 'local' ? result.product : null;
            window._evershelfBarcodeConsumer = null;
            stopScanner();
            showLoading(false);
            showPage('reconciliation', sessionId, { skipHistory: true });
            if (!product?.id) {
                showToast(reconciliationText('scan_catalog_only', 'Barcode is not in the local catalog. Add the product first, then count it.'), 'warning', 6000);
                return true;
            }
            setTimeout(() => reconciliationOpenCount(product), 250);
            return true;
        } catch (error) {
            window._evershelfBarcodeConsumer = null;
            stopScanner();
            showLoading(false);
            showPage('reconciliation', sessionId, { skipHistory: true });
            showToast(reconciliationErrorMessage(error), 'error');
            return true;
        }
    };
    showPage('scan');
    showToast(reconciliationText('scan_hint', 'Scan a product in this location.'), 'info');
}

async function reconciliationReview() {
    if (!reconciliationIsOnline()) return reconciliationOfflineToast();
    try {
        const result = await api('reconciliation_review', {}, 'POST', { session_id: _reconciliationState.session.id });
        if (!result?.success) throw result;
        _reconciliationState = reconciliationSessionState(result, _reconciliationState);
        reconciliationRenderSession();
        window.scrollTo(0, 0);
    } catch (error) {
        showToast(reconciliationErrorMessage(error), 'error');
    }
}

async function reconciliationApply() {
    if (!reconciliationIsOnline()) return reconciliationOfflineToast();
    const session = _reconciliationState?.session;
    if (!session) return;
    const message = reconciliationText(
        'apply_confirm',
        'Apply {counted} counted products now? {uncounted} uncounted products will remain unchanged.'
    ).replace('{counted}', session.counted_items).replace('{uncounted}', session.uncounted_items);
    if (!confirm(message)) return;
    showLoading(true);
    try {
        const result = await api('reconciliation_apply', {}, 'POST', { session_id: session.id });
        if (!result?.success) throw result;
        showToast(reconciliationText('applied_toast', 'Physical count applied.'), 'success');
        if (typeof loadInventory === 'function') loadInventory();
        await initReconciliationPage(session.id);
    } catch (error) {
        const messageText = reconciliationErrorMessage(error);
        showToast(messageText, error?.error === 'inventory_changed' ? 'warning' : 'error', 7000);
        await initReconciliationPage(session.id);
    } finally {
        showLoading(false);
    }
}

async function reconciliationCancel() {
    if (!reconciliationIsOnline()) return reconciliationOfflineToast();
    if (!confirm(reconciliationText('cancel_confirm', 'Cancel this count? Live inventory will not be changed.'))) return;
    try {
        const result = await api('reconciliation_cancel', {}, 'POST', { session_id: _reconciliationState.session.id });
        if (!result?.success) throw result;
        _reconciliationState = reconciliationSessionState(result, _reconciliationState);
        reconciliationRenderSession();
        showToast(reconciliationText('cancelled_toast', 'Count cancelled. Inventory was not changed.'), 'success');
    } catch (error) {
        showToast(reconciliationErrorMessage(error), 'error');
    }
}

function reconciliationOfflineToast() {
    reconciliationSetOfflineBanner();
    showToast(reconciliationText('online_only_body', 'Physical counts are online-only and are never queued.'), 'warning', 6000);
}

window.addEventListener('online', reconciliationSetOfflineBanner);
window.addEventListener('offline', reconciliationSetOfflineBanner);
