/**
 * Receipt OCR (#114) — one photo of a supermarket receipt, an editable review
 * table, then the pantry.
 *
 * The server only *proposes* rows (`parse_receipt` never writes): the user
 * corrects or removes lines and presses confirm, and only then does each row
 * become a catalogue product plus an `inventory_add`. A receipt is a photo of
 * someone else's data — it must never touch the pantry on its own.
 *
 * The image comes from a file input with `capture="environment"`, which opens
 * the camera on Android/iOS and the gallery on desktop: no second video stream
 * competing with the scanner.
 */

let _receipt = null;
let _receiptBusy = false;

/** Entry point from the scan page. */
function _receiptStart() {
    if (_receiptBusy) return;
    let input = document.getElementById('receipt-file');
    if (!input) {
        input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/*';
        input.setAttribute('capture', 'environment');
        input.id = 'receipt-file';
        input.hidden = true;
        input.addEventListener('change', _receiptOnFile);
        document.body.appendChild(input);
    }
    input.value = '';
    input.click();
}

/** Send the photo and open the review table. */
async function _receiptOnFile(ev) {
    const file = ev.target?.files?.[0];
    if (!file) return;
    _receiptBusy = true;
    _receiptOverlay(`<div class="audit-panel">
        <div class="audit-head"><h3>${escapeHtml(t('receipt.title'))}</h3></div>
        <p class="audit-hint">${escapeHtml(t('receipt.reading'))}</p>
    </div>`);
    try {
        const dataUrl = await new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(String(reader.result || ''));
            reader.onerror = () => reject(new Error('read_failed'));
            reader.readAsDataURL(file);
        });
        const base64 = dataUrl.split(',')[1] || '';
        const lang = (typeof _currentLang === 'string' && _currentLang) ? _currentLang : 'en';
        const res = await api('parse_receipt', {}, 'POST', { image: base64, lang });
        if (!res || res.success !== true) {
            _receiptOverlay('');
            showToast(t(res?.error_key || 'error.receipt_failed'), 'error');
            return;
        }
        const items = Array.isArray(res.items) ? res.items : [];
        if (!items.length) {
            _receiptOverlay('');
            showToast(t('receipt.empty'), 'info');
            return;
        }
        _receiptReview(items);
    } catch (_e) {
        _receiptOverlay('');
        showToast(t('error.receipt_failed'), 'error');
    } finally {
        _receiptBusy = false;
    }
}

function _receiptOverlay(html) {
    const el = document.getElementById('receipt-overlay');
    if (!el) return;
    el.hidden = !html;
    el.innerHTML = html;
}

/** Editable table: the model's rows are a draft, not an answer. */
function _receiptReview(items) {
    _receipt = { items: items.map(it => ({
        name: String(it.name || ''),
        brand: String(it.brand || ''),
        qty: Number(it.qty) || 1,
        unit: ['pz', 'g', 'ml', 'conf'].includes(it.unit) ? it.unit : 'pz',
        price: Number(it.price) || 0,
    })) };
    _receiptRender();
}

function _receiptEdit(index, field, value) {
    if (!_receipt?.items?.[index]) return;
    if (field === 'qty' || field === 'price') {
        _receipt.items[index][field] = Number(value) || 0;
    } else {
        _receipt.items[index][field] = String(value);
    }
}

function _receiptRemove(index) {
    if (!_receipt?.items) return;
    _receipt.items.splice(index, 1);
    if (!_receipt.items.length) {
        _receiptCancel();
        showToast(t('receipt.empty'), 'info');
        return;
    }
    _receiptRender();
}

function _receiptCancel() {
    _receipt = null;
    _receiptOverlay('');
}

/** Draw the review table. */
function _receiptRender() {
    const rows = _receipt.items.map((it, i) => `
        <tr>
            <td><input type="text" class="form-input receipt-name" value="${escapeAttr(it.name)}"
                       oninput="_receiptEdit(${i}, 'name', this.value)"></td>
            <td><input type="number" class="form-input receipt-qty" min="0.01" step="0.01" value="${it.qty}"
                       oninput="_receiptEdit(${i}, 'qty', this.value)"></td>
            <td><select class="form-input receipt-unit" onchange="_receiptEdit(${i}, 'unit', this.value)">
                ${['pz', 'g', 'ml', 'conf'].map(u => `<option value="${u}"${u === it.unit ? ' selected' : ''}>${escapeHtml(u)}</option>`).join('')}
            </select></td>
            <td><input type="number" class="form-input receipt-price" min="0" step="0.01" value="${it.price}"
                       oninput="_receiptEdit(${i}, 'price', this.value)"></td>
            <td><button type="button" class="audit-close" aria-label="${escapeAttr(t('receipt.remove'))}"
                        onclick="_receiptRemove(${i})">✕</button></td>
        </tr>`).join('');
    _receiptOverlay(`<div class="audit-panel" role="dialog" aria-modal="true">
        <div class="audit-head">
            <h3>${escapeHtml(t('receipt.review_title'))}</h3>
            <button type="button" class="audit-close" onclick="_receiptCancel()" aria-label="${escapeAttr(t('receipt.cancel'))}">✕</button>
        </div>
        <p class="audit-hint">${escapeHtml(t('receipt.hint'))}</p>
        <div class="receipt-table-wrap">
            <table class="receipt-table">
                <thead><tr>
                    <th>${escapeHtml(t('receipt.col_name'))}</th>
                    <th>${escapeHtml(t('receipt.col_qty'))}</th>
                    <th>${escapeHtml(t('receipt.col_unit'))}</th>
                    <th>${escapeHtml(t('receipt.col_price'))}</th>
                    <th></th>
                </tr></thead>
                <tbody>${rows}</tbody>
            </table>
        </div>
        <div class="receipt-actions">
            <button type="button" class="btn-secondary" onclick="_receiptCancel()">${escapeHtml(t('receipt.cancel'))}</button>
            <button type="button" class="audit-done" onclick="_receiptConfirm()">${escapeHtml(t('receipt.confirm').replace('{n}', String(_receipt.items.length)))}</button>
        </div>
    </div>`);
}

/** Write the confirmed rows: a catalogue product each, then the pantry row. */
async function _receiptConfirm() {
    if (!_receipt?.items?.length || _receiptBusy) return;
    _receiptBusy = true;
    let ok = 0;
    let failed = 0;
    for (const row of _receipt.items) {
        const name = String(row.name || '').trim();
        if (!name) {
            failed++;
            continue;
        }
        try {
            const saved = await api('product_save', {}, 'POST', {
                barcode: null,
                name,
                brand: String(row.brand || '').trim(),
                category: mapToLocalCategory('', name, row.brand || ''),
                unit: row.unit,
                default_quantity: row.qty,
                package_unit: '',
                notes: '',
                // The user reviewed this title: a later catalogue hit must not rename it.
                name_user_set: 1,
            });
            const productId = saved?.id || saved?.existing_id;
            if (!productId) {
                failed++;
                continue;
            }
            const added = await api('inventory_add', {}, 'POST', {
                product_id: productId,
                quantity: row.qty,
                location: 'dispensa',
            });
            if (added?.success) ok++;
            else failed++;
        } catch (_e) {
            failed++;
        }
    }
    _receiptBusy = false;
    _receiptCancel();
    showToast(t('receipt.done').replace('{ok}', ok).replace('{ko}', failed), failed ? 'info' : 'success');
    if (ok) loadInventory();
}
