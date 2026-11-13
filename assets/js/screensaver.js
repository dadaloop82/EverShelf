/**
 * EverShelf — the idle screen: an "ambient kitchen dashboard".
 *
 * One card at a time, read from the household's own data — never a sentence from an
 * encyclopedia:
 *
 *   meal       the slot of the day + the plan, with a real recipe from the archive the
 *              pantry can cook NOW (expiring ingredients score higher)
 *   alerts     up to three things that need eating (opened too long, expired, expiring),
 *              each row opening the product sheet — or the positive milestone when there
 *              is nothing to do
 *   nutrition  the category pie + health / variety / freshness rings, with one insight
 *              generated from the weakest score
 *   fact       a dynamic idea (stock, savings, weather + pantry, cabinet, shopping list)
 *
 * A view with nothing to say is skipped (`null` from its builder), so an empty pantry or a
 * day with no plan never paints an empty card — the rotation walks to the next view.
 *
 * Interaction: a tap flips the view, a swipe left/right does the same, a swipe up (or the
 * «Sblocca» pill, or Esc) goes back to the app. The idle clock in app.js owns the timing,
 * the pin, the force parameter and the dismissal; this file owns what is on the screen.
 */

const SS_VIEWS = ['meal', 'alerts', 'nutrition', 'fact'];
const SS_DEFAULT_INTERVAL_SEC = 30;
const SS_MIN_INTERVAL_SEC = 10;
const SS_MAX_INTERVAL_SEC = 300;
/** The whole stage drifts a few pixels: a wall showing the same clock for hours burns in. */
const SS_PIXEL_SHIFT_MS = 150000;
const SS_PIXEL_SHIFT_PX = 5;
const SS_DATA_TTL_MS = 4 * 60 * 1000;
const SS_TAP_SLOP = 14;
/** How long the outgoing card takes to leave — must match `.ss-card` in style.css. */
const SS_CARD_OUT_MS = 420;
const SS_SWIPE_MIN = 55;

let _ssRunning = false;
let _ssData = null;
let _ssView = '';
let _ssRotate = null;
let _ssShift = null;
let _ssShiftIdx = 0;
let _ssGesture = null;
let _ssHintTimer = null;
/** The recipe the meal card showed last: never the same dish twice in a row. */
let _ssLastRecipeId = 0;
/** Recipes voted on in this session: the card says «grazie» instead of asking again. */
const _ssRatedNow = new Set();

/* ── settings ───────────────────────────────────────────────────────────────── */

/**
 * What the household chose for the idle screen: which views and how often it changes.
 * Missing/blank `screensaver_views` means "all of them" (the default an install gets).
 */
function _ssSettings() {
    const s = (typeof getSettings === 'function') ? (getSettings() || {}) : {};
    const raw = String(s.screensaver_views || '')
        .split(',')
        .map(v => v.trim().toLowerCase())
        .filter(Boolean);
    const picked = raw.length ? SS_VIEWS.filter(v => raw.includes(v)) : SS_VIEWS.slice();
    const n = parseInt(s.screensaver_interval, 10);
    const secs = Number.isFinite(n)
        ? Math.min(SS_MAX_INTERVAL_SEC, Math.max(SS_MIN_INTERVAL_SEC, n))
        : SS_DEFAULT_INTERVAL_SEC;
    return {
        views: picked.length ? picked : SS_VIEWS.slice(),
        intervalMs: secs * 1000,
        // `weather_enabled` arrives as a boolean from the form and as '1'/'true' from an .env
        weather: [true, 1, '1', 'true'].includes(s.weather_enabled),
    };
}
/* ── data ───────────────────────────────────────────────────────────────────── */

/** Everything the four views read, fetched once per activation (and every few minutes). */
async function _ssLoad() {
    const cfg = _ssSettings();
    const [statsRes, invRes] = await Promise.all([
        (typeof api === 'function' ? api('stats') : Promise.resolve(null)).catch(() => null),
        (typeof api === 'function' ? api('inventory_list') : Promise.resolve(null)).catch(() => null),
    ]);
    let weather = null;
    if (cfg.weather && typeof api === 'function') {
        try {
            const w = await api('weather_get');
            if (w && w.success !== false && w.weather) weather = w.weather;
        } catch (_) { /* offline: the header simply has no weather */ }
    }
    let recipes = [];
    try {
        if (typeof getRecipeArchive === 'function') recipes = await getRecipeArchive();
    } catch (_) { /* a pantry works without a recipe book */ }
    // The archive is also the index `viewArchivedRecipe()` reads: keep them the same array.
    if (Array.isArray(recipes) && recipes.length && typeof _recipeArchiveEntries !== 'undefined') {
        _recipeArchiveEntries = recipes;
    }
    try {
        if (typeof _recipeLiveProductIdSet === 'function') await _recipeLiveProductIdSet();
    } catch (_) { /* "missing" stays 0: a recipe is then proposed as-is */ }

    _ssData = {
        stats: (statsRes && statsRes.success !== false) ? statsRes : {},
        inventory: (invRes && Array.isArray(invRes.inventory)) ? invRes.inventory : [],
        weather,
        recipes: Array.isArray(recipes) ? recipes : [],
        at: Date.now(),
    };
    return _ssData;
}

function _ssStats() { return (_ssData && _ssData.stats) || {}; }
function _ssInventory() { return (_ssData && _ssData.inventory) || []; }

/** A quantity the way the pantry prints it, without any markup. */
function _ssQty(row) {
    try {
        if (typeof _formatQtyPlain === 'function') {
            const txt = _formatQtyPlain(row.quantity, row.unit, row.default_quantity, row.package_unit);
            if (txt) return txt;
        }
    } catch (_) { /* fall through to the plain pair */ }
    const q = Number(row.quantity);
    if (!Number.isFinite(q)) return '';
    const n = Math.round(q * 100) / 100;
    return `${n}${row.unit ? ' ' + row.unit : ''}`;
}

function _ssDays(dateStr) {
    if (!dateStr) return null;
    try {
        if (typeof daysUntilExpiry === 'function') return daysUntilExpiry(dateStr);
    } catch (_) { /* fall through */ }
    const d = new Date(String(dateStr).slice(0, 10) + 'T00:00:00');
    if (isNaN(d.getTime())) return null;
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    return Math.round((d - today) / 86400000);
}

function _ssDaysOpen(row) {
    if (!row || !row.opened_at) return null;
    const d = new Date(row.opened_at);
    if (isNaN(d.getTime())) return null;
    return Math.max(0, Math.floor((Date.now() - d.getTime()) / 86400000));
}

function _ssIsMedical(row) {
    if (!row) return false;
    if (Number(row.is_medical) === 1) return true;
    return typeof _isMedicalRow === 'function' ? !!_isMedicalRow(row) : false;
}

function _ssRowIcon(row) {
    try {
        if (_ssIsMedical(row) && typeof _medIconMarkup === 'function') return _medIconMarkup();
        if (typeof _productCatIconHtml === 'function') return _productCatIconHtml(row.category || '');
    } catch (_) { /* emoji fallback below */ }
    return '📦';
}

/** "Scade fra 3 gg" / "Scade domani" / "Scade oggi" / "Scaduta da 2 gg" — compact. */
function _ssExpiryText(days) {
    if (days === null) return '';
    if (days < 0) return t('screensaver.alerts_expired', { n: Math.abs(days) });
    if (days === 0) return t('screensaver.alerts_today');
    if (days === 1) return t('screensaver.alerts_tomorrow');
    return t('screensaver.alerts_days', { n: days });
}


/* ── view: meal ─────────────────────────────────────────────────────────────── */

function _ssMealSlot(hour) {
    const h = Number.isFinite(hour) ? hour : new Date().getHours();
    if (h < 11) return 'colazione';
    if (h < 15) return 'pranzo';
    if (h < 18) return 'merenda';
    return 'cena';
}

function _ssSlotIcon(slot) {
    return { colazione: '☀️', pranzo: '🌤️', merenda: '🍎', cena: '🌙' }[slot] || '🍽️';
}


/** "15 min" from prep + cook, when the recipe carries them ("15 min", "20"…). */
function _ssMinutes(recipe) {
    const pick = (v) => {
        const m = String(v || '').match(/\d+/);
        return m ? parseInt(m[0], 10) : 0;
    };
    const total = pick(recipe.prep_time) + pick(recipe.cook_time);
    return total > 0 ? String(total) : String(recipe.prep_time || recipe.cook_time || '—');
}

/**
 * A recipe from the household's OWN archive that the pantry can cook right now.
 *
 * The slot of the day is not a filter any more: filing «Crostini di pan bauletto con pomodoro e
 * stracchino» under «colazione» because the clock says nine is a guess, and a savoury crostini is
 * not what anyone eats at nine. What the app really knows is what this household has already
 * cooked and liked, so the card proposes THAT: nothing missing scores highest, an ingredient about
 * to expire weighs more, a recipe rated well weighs more, one cooked yesterday weighs less and
 * the card never shows the same dish twice in a row.
 */
function _ssPickRecipe() {
    const recipes = (_ssData && _ssData.recipes) || [];
    if (!recipes.length) return null;
    const stats = _ssStats();
    const expiringIds = new Set(
        [...(stats.expiring_soon || []), ...(stats.opened || []), ...(stats.expired || [])]
            .map(r => String((r && r.product_id) || ''))
            .filter(Boolean)
    );
    let best = null;
    recipes.forEach((entry, idx) => {
        const r = (entry && entry.recipe) ? entry.recipe : entry;
        if (!r || !Array.isArray(r.ingredients) || !r.ingredients.length) return;
        const info = (typeof _recipeArchiveMissingInfo === 'function')
            ? _recipeArchiveMissingInfo(r)
            : { missing: 0, pantry: 0, uncookable: false, items: [] };
        if (info.uncookable || info.missing > 1) return;
        const expiring = r.ingredients.filter(i => i && i.product_id && expiringIds.has(String(i.product_id)));
        const rating = Number(entry.rating) || 0;
        const ageDays = _ssAgeDays(entry);
        let score = 100 - info.missing * 25;
        score += expiring.length * 10;
        if (rating >= 4) score += 12;                        // a dish this household liked
        else if (rating > 0 && rating <= 2) score -= 10;     // one it did not
        if (ageDays !== null) {
            if (ageDays >= 21) score += 8;                   // not cooked for a while: fresh again
            else if (ageDays <= 2) score -= 6;               // the same dish two days running
        }
        if (entry.is_favorite) score += 6;
        if (entry.id && entry.id === _ssLastRecipeId) score -= 40;  // never twice in a row
        if (!best || score > best.score) {
            best = { score, idx, entry, recipe: r, info, expiring, ageDays, rating };
        }
    });
    return best;
}

/** How many days ago an archived recipe was cooked (null when the archive does not say). */
function _ssAgeDays(entry) {
    const raw = entry && (entry.date || entry.savedAt);
    if (!raw) return null;
    let ts;
    if (typeof raw === 'number') {
        ts = raw;
    } else {
        const d = new Date(String(raw).slice(0, 10) + 'T12:00:00');
        ts = d.getTime();
    }
    if (!Number.isFinite(ts)) return null;
    return Math.max(0, Math.floor((Date.now() - ts) / 86400000));
}

function _ssAgeText(days) {
    if (days === null) return '';
    if (days <= 0) return t('screensaver.made_today');
    if (days === 1) return t('screensaver.made_yesterday');
    if (days < 30) return t('screensaver.made_days_ago', { n: days });
    return t('screensaver.made_months_ago', { n: Math.max(1, Math.round(days / 30)) });
}

function _ssViewMeal() {
    const pick = _ssPickRecipe();
    // Nothing the pantry can cook: no card at all — the rotation walks to the next view rather
    // than painting an empty recipe.
    if (!pick) return null;

    const age = _ssAgeText(pick.ageDays);
    const eyebrow = `<span>🍳 ${escapeHtml(t('screensaver.meal_from_archive'))}</span>`
        + (age ? `<span class="ss-eyebrow-sub">· ${escapeHtml(age)}</span>` : '');

    const r = pick.recipe;
    const nutr = r.nutrition || {};
    const facts = [];
    if (pick.info.missing === 0) {
        facts.push(`<span class="ss-chip is-ok">✅ ${escapeHtml(t('screensaver.meal_all'))}</span>`);
    } else if (pick.info.items.length) {
        facts.push(`<span class="ss-chip is-warn">⚠️ ${escapeHtml(t('screensaver.meal_missing', { items: pick.info.items.map(i => i.name).join(', ') }))}</span>`);
    }
    if (pick.expiring.length) {
        facts.push(`<span class="ss-chip is-warn">${escapeHtml(t('screensaver.meal_expiring', { items: pick.expiring.map(i => i.name).join(', ') }))}</span>`);
    }
    const meta = [];
    if (r.prep_time || r.cook_time) meta.push(`<span class="ss-chip">⏱️ ${escapeHtml(t('screensaver.minutes', { min: _ssMinutes(r) }))}</span>`);
    if (nutr.kcal) meta.push(`<span class="ss-chip">🔥 ${escapeHtml(t('screensaver.calories', { kcal: Math.round(nutr.kcal) }))}</span>`);
    if (nutr.protein_g) meta.push(`<span class="ss-chip">🥩 ${escapeHtml(t('screensaver.protein', { g: Math.round(nutr.protein_g) }))}</span>`);

    // «Fatta 12 giorni fa: ti era piaciuta?» — the household answers with a thumb and the
    // archive learns (`recipes_rate`), which is what makes the next choice better.
    const recipeId = Number(pick.entry && pick.entry.id) || 0;
    let ask = '';
    if (recipeId > 0) {
        if (_ssRatedNow.has(recipeId)) {
            ask = `<div class="ss-ask is-done">⭐ ${escapeHtml(t('screensaver.rate_thanks'))}</div>`;
        } else if (!pick.rating) {
            ask = `<div class="ss-ask">
                       <span class="ss-ask-q">${escapeHtml(t('screensaver.rate_question'))}</span>
                       <button type="button" class="ss-thumb" data-ss-rate="5" data-ss-id="${recipeId}"
                               aria-label="${escapeAttr(t('screensaver.rate_yes'))}"
                               title="${escapeAttr(t('screensaver.rate_yes'))}">👍</button>
                       <button type="button" class="ss-thumb" data-ss-rate="1" data-ss-id="${recipeId}"
                               aria-label="${escapeAttr(t('screensaver.rate_no'))}"
                               title="${escapeAttr(t('screensaver.rate_no'))}">👎</button>
                   </div>`;
        }
    }

    return {
        view: 'meal', accent: 'emerald', recipeId,
        html: `<div class="ss-eyebrow">${eyebrow}</div>
               <h2 class="ss-title">${escapeHtml(r.title || '')}</h2>
               ${facts.length ? `<div class="ss-meta">${facts.join('')}</div>` : ''}
               ${meta.length ? `<div class="ss-meta">${meta.join('')}</div>` : ''}
               ${ask}
               <button type="button" class="ss-action" data-ss-recipe="${pick.idx}">🍳 ${escapeHtml(t('screensaver.meal_open'))}</button>`,
    };
}


/* ── view: alerts (what needs eating, three rows at most) ───────────────────── */

/**
 * The three things that most need eating: an opened pack past its window, something
 * already expired, something about to expire. Nothing is invented: an empty result means
 * the household really has nothing to do, and then the card celebrates instead.
 */
function _ssAlertRows() {
    const stats = _ssStats();
    const seen = new Set();
    const rows = [];
    const push = (row, tone, badge) => {
        if (!row || rows.length >= 3) return;
        const key = String(row.id || `${row.product_id}|${row.location}|${row.expiry_date}`);
        if (seen.has(key)) return;
        seen.add(key);
        rows.push({ row, tone, badge });
    };

    // 1) Opened and past the recommended window (the PAO clock of the product).
    (stats.opened || []).forEach(row => {
        const days = _ssDaysOpen(row);
        if (days === null) return;
        const pao = row.pao || (typeof _openedPao === 'function' ? _openedPao(row) : null);
        const state = pao && pao.state;
        if (state !== 'warn' && state !== 'critical' && days < 3) return;
        push(row, state === 'critical' ? 'coral' : 'amber', t('screensaver.alerts_opened', { n: days }));
    });

    // 2) Already expired.
    (stats.expired || []).forEach(row => {
        const days = _ssDays(row.expiry_date);
        push(row, 'coral', days === null ? t('screensaver.alerts_today') : t('screensaver.alerts_expired', { n: Math.abs(days) }));
    });

    // 3) Expiring within three days: today/tomorrow first (the API sorts by date).
    (stats.expiring_soon || []).forEach(row => {
        const days = _ssDays(row.expiry_date);
        if (days === null || days > 3) return;
        push(row, 'amber', _ssExpiryText(days));
    });

    return rows;
}

function _ssViewAlerts() {
    const rows = _ssAlertRows();
    const stats = _ssStats();

    if (!rows.length) {
        // Nothing to act on: the milestone the Home shows, from the same counters.
        const used = Number(stats.used_30d) || 0;
        const wasted = Number(stats.wasted_30d) || 0;
        const sub = (used + wasted) > 0
            ? t('screensaver.alerts_ok_sub', { used, wasted })
            : t('screensaver.alerts_ok_none');
        return {
            view: 'alerts', accent: 'emerald',
            html: `<div class="ss-eyebrow">🌱 ${escapeHtml(t('screensaver.alerts_ok_title'))}</div>
                   <p class="ss-sub" style="margin-top:12px">${escapeHtml(sub)}</p>`,
        };
    }

    const anyCritical = rows.some(r => r.tone === 'coral');
    const list = rows.map(({ row, tone, badge }) => `
        <button type="button" class="ss-row" data-tone="${tone}"
                data-ss-open="${escapeAttr(String(row.id || ''))}"
                data-ss-product="${escapeAttr(String(row.product_id || ''))}">
            <span class="ss-row-icon" aria-hidden="true">${_ssRowIcon(row)}</span>
            <span class="ss-row-info">
                <span class="ss-row-name">${escapeHtml(row.name || '')}</span>
                <span class="ss-row-meta">${escapeHtml(_ssQty(row))}</span>
            </span>
            <span class="ss-row-badge">${escapeHtml(badge)}</span>
        </button>`).join('');

    return {
        view: 'alerts', accent: anyCritical ? 'coral' : 'amber',
        html: `<div class="ss-eyebrow">⚠️ ${escapeHtml(t('screensaver.alerts_title'))}</div>
               <div class="ss-rows">${list}</div>`,
    };
}


/* ── view: nutrition (the pie and the three scores, plus one insight) ───────── */

function _ssViewNutrition() {
    if (typeof _buildNutritionData !== 'function') return null;
    const inv = _ssInventory();
    const cached = (typeof _nutriData !== 'undefined' && _nutriData && _nutriData.slices) ? _nutriData : null;
    const data = cached || _buildNutritionData(inv);
    if (!data || !Array.isArray(data.slices) || !data.slices.length) return null;

    const { slices, total, healthScore, varietyScore, fresh_pct } = data;
    const top = slices.slice(0, 4);
    let deg = 0;
    const stops = top.map(s => {
        const end = deg + s.pct * 3.6;
        const stop = `${s.color} ${deg.toFixed(1)}deg ${end.toFixed(1)}deg`;
        deg = end;
        return stop;
    });
    if (deg < 360) stops.push(`#1b2320 ${deg.toFixed(1)}deg 360deg`);

    const ring = (label, pct, color) => `
        <div class="ss-ring-wrap">
            <div class="ss-ring" style="--pct:${Number(pct) || 0};--ring-color:${color}">${Number(pct) || 0}%</div>
            <span class="ss-ring-label">${escapeHtml(label)}</span>
        </div>`;

    const legend = top.map(s => `
        <div class="ss-leg">
            <span class="ss-leg-dot" style="background:${s.color}"></span>
            <span>${s.icon || ''} ${escapeHtml(typeof categoryText === 'function' ? categoryText(s.cat) : String(s.cat || ''))}</span>
            <b>${Number(s.pct) || 0}%</b>
        </div>`).join('');

    // One insight, generated from the weakest number — never a generic sentence.
    let insight;
    if ((Number(fresh_pct) || 0) < 40) insight = t('screensaver.insight_fresh', { pct: Number(fresh_pct) || 0 });
    else if ((Number(varietyScore) || 0) < 50) insight = t('screensaver.insight_variety', { pct: Number(varietyScore) || 0 });
    else if ((Number(healthScore) || 0) < 50) insight = t('screensaver.insight_health', { pct: Number(healthScore) || 0 });
    else insight = t('screensaver.insight_ok', { n: Number(total) || inv.length, fresh: Number(fresh_pct) || 0 });

    return {
        view: 'nutrition', accent: 'sky',
        html: `<div class="ss-eyebrow">📊 ${escapeHtml(t('screensaver.nutrition_title'))}</div>
               <div class="ss-nutr">
                   <div class="ss-pie" style="background:conic-gradient(from 0deg, ${stops.join(', ')})"></div>
                   <div class="ss-rings">
                       ${ring(t('nutrition.label_health'), healthScore, '#10b981')}
                       ${ring(t('nutrition.label_variety'), varietyScore, '#a78bfa')}
                       ${ring(t('nutrition.label_fresh'), fresh_pct, '#38bdf8')}
                   </div>
               </div>
               <div class="ss-legend">${legend}</div>
               <p class="ss-sub" style="margin-top:14px">${escapeHtml(insight)}</p>`,
    };
}


/* ── view: fact (an idea built from the household's own numbers) ────────────── */

let _ssFactTurn = 0;

/** Every template returns data or null; the turn picks the next one that has any. */
function _ssFactCandidates() {
    const stats = _ssStats();
    const inv = _ssInventory();
    const out = [];

    // 1) The shopping list: how much and what is urgent.
    const items = (typeof shoppingItems !== 'undefined' && Array.isArray(shoppingItems)) ? shoppingItems : [];
    if (items.length) {
        const names = items.map(i => (i && (i.name || i.display_name)) || '').filter(Boolean);
        const price = (typeof _canonicalShoppingTotal !== 'undefined' && _canonicalShoppingTotal && _canonicalShoppingTotal.label)
            ? String(_canonicalShoppingTotal.label).replace(/^ca\.\s*/, '') : '';
        const line = items.length === 1
            ? t('screensaver.fact_shopping_one', { n: items.length, price: price || '—', a: names[0] || '—' })
            : t('screensaver.fact_shopping', { n: items.length, price: price || '—', a: names[0] || '—', b: names[1] || '—' });
        out.push({ icon: '🛒', text: line });
    }

    // 2) Anti-waste: what was eaten against what was thrown away in the last 30 days.
    const used = Number(stats.used_30d) || 0;
    const wasted = Number(stats.wasted_30d) || 0;
    if (used + wasted > 0) {
        const pct = Math.round((used / (used + wasted)) * 100);
        out.push({ icon: '🌱', text: t('screensaver.fact_waste', { used, wasted, pct }) });
    }

    // 3) Weather + the thing that expires first: what to cook today.
    const w = _ssData && _ssData.weather;
    const soon = (stats.expiring_soon || [])[0];
    if (w && soon) {
        out.push({
            icon: _ssWeatherIcon(w.bucket),
            text: t('screensaver.fact_weather', {
                temp: Math.round(Number(w.temp_c)),
                city: w.city || '',
                item: soon.name || '',
                when: _ssExpiryText(_ssDays(soon.expiry_date)).toLowerCase(),
            }),
        });
    }

    // 4) The medicine cabinet, when the module is on.
    const meds = inv.filter(r => _ssIsMedical(r));
    if (meds.length) {
        const soonMeds = meds.filter(r => {
            const d = _ssDays(r.expiry_date);
            return d !== null && d >= 0 && d <= 30;
        }).length;
        out.push({
            icon: '💊',
            text: soonMeds
                ? t('screensaver.fact_med', { n: meds.length, m: soonMeds })
                : t('screensaver.fact_med_ok', { n: meds.length }),
        });
    }

    // 5) The shape of the pantry itself.
    const places = Number(stats.locations) || 0;
    if (inv.length) {
        const noExpiry = inv.filter(r => !r.expiry_date).length;
        out.push({ icon: '📦', text: t('screensaver.fact_stock', { n: inv.length, places: places || 1, m: noExpiry }) });
    }

    return out;
}

function _ssViewFact() {
    const candidates = _ssFactCandidates();
    if (!candidates.length) return null;
    const pick = candidates[_ssFactTurn % candidates.length];
    _ssFactTurn++;
    return {
        view: 'fact', accent: 'emerald',
        html: `<div class="ss-eyebrow">💡 ${escapeHtml(t('screensaver.fact_eyebrow'))}</div>
               <p class="ss-title" style="font-weight:600;line-height:1.35">${pick.icon} ${escapeHtml(pick.text)}</p>`,
    };
}

function _ssWeatherIcon(bucket) {
    return {
        clear: '☀️', sunny: '☀️', mild: '🌤️', cloudy: '☁️', rain: '🌧️',
        rainy: '🌧️', snow: '❄️', cold: '❄️', hot: '🌡️', storm: '⛈️', fog: '🌫️',
    }[String(bucket || '').toLowerCase()] || '🌤️';
}


/* ── head, footer, paint ────────────────────────────────────────────────────── */

/**
 * The name of the place, short enough for a pill: «Bolzano, Trentino-Alto Adige, Italia» is a
 * mailing address, not a label — the first part is the city the household thinks in.
 */
function _ssShortCity(city) {
    const label = String(city || '').trim();
    if (label.length <= 18) return label;
    return (label.split(',')[0] || '').trim() || label;
}

/** The clock, the date and the context pill (weather and/or the plan of the moment). */
function _ssPaintHead() {
    const clock = document.getElementById('screensaver-clock');
    if (clock) {
        const now = new Date();
        const loc = (typeof _collationLocale === 'function') ? _collationLocale() : undefined;
        const time = now.toLocaleTimeString(loc, { hour: '2-digit', minute: '2-digit' });
        const date = now.toLocaleDateString(loc, { weekday: 'long', day: 'numeric', month: 'long' });
        clock.innerHTML = `${escapeHtml(time)}<div class="ss-date">${escapeHtml(date)}</div>`;
    }
    const ctx = document.getElementById('screensaver-context');
    if (!ctx) return;
    const parts = [];
    const w = _ssData && _ssData.weather;
    if (w && w.temp_c != null) {
        parts.push(`<span>${_ssWeatherIcon(w.bucket)} ${escapeHtml(t('screensaver.context_weather', {
            temp: Math.round(Number(w.temp_c)),
            city: _ssShortCity(w.city),
        }))}</span>`);
    }
    const slot = _ssMealSlot();
    const typeId = (typeof getTodayMealPlanType === 'function') ? getTodayMealPlanType(slot) : null;
    const type = (typeId && typeId !== 'libero' && typeof getMealPlanTypeMap === 'function') ? getMealPlanTypeMap()[typeId] : null;
    if (type) parts.push(`<span>${_ssSlotIcon(slot)} ${escapeHtml(type.label)}</span>`);
    ctx.innerHTML = parts.join(' <span class="ss-context-sep">·</span> ');
    ctx.hidden = parts.length === 0;
}

/** The vital numbers: the list, what is expiring, what is open. One line. */
function _ssRefreshFooter() {
    const foot = document.getElementById('screensaver-foot');
    if (!foot) return;
    const stats = _ssStats();
    const bits = [];
    const shopCount = (typeof shoppingItems !== 'undefined' && Array.isArray(shoppingItems)) ? shoppingItems.length : 0;
    if (shopCount > 0) {
        const price = (typeof _canonicalShoppingTotal !== 'undefined' && _canonicalShoppingTotal && _canonicalShoppingTotal.label)
            ? String(_canonicalShoppingTotal.label).replace(/^ca\.\s*/, '') : '';
        bits.push(`<span class="ss-kpi"><span class="ss-kpi-dot" style="background:#10b981"></span>🛒 <b>${shopCount}</b> ${escapeHtml(t('screensaver.kpi_shopping'))}${price ? ' · ' + escapeHtml(price) : ''}</span>`);
    }
    const expiring = (stats.expiring_soon || []).length;
    if (expiring) {
        bits.push(`<span class="ss-kpi"><span class="ss-kpi-dot" style="background:#f59e0b"></span>⚠️ <b>${expiring}</b> ${escapeHtml(t('screensaver.kpi_expiring'))}</span>`);
    }
    const opened = (stats.opened || []).length;
    if (opened) {
        bits.push(`<span class="ss-kpi"><span class="ss-kpi-dot" style="background:#38bdf8"></span>📦 <b>${opened}</b> ${escapeHtml(t('screensaver.kpi_opened'))}</span>`);
    }
    foot.innerHTML = bits.join('');
    foot.hidden = bits.length === 0;
}

/**
 * Run `fn` after the next paint, with a timeout as the safety net: a hidden wall never
 * calls `requestAnimationFrame`, and a card stuck at opacity 0 is a blank screen.
 */
function _ssNextFrame(fn) {
    let done = false;
    const run = () => {
        if (done) return;
        done = true;
        try { fn(); } catch (e) { /* cosmetic only */ }
    };
    requestAnimationFrame(run);
    setTimeout(run, 150);
}

/**
 * Swap the card: the old one dissolves going UP and the new one comes back DOWN from the top,
 * its blocks settling one after the other. Two phases on purpose (`is-out` → swap → `is-pre`
 * removed on the next frame): a single class swap would make the browser interpolate from a
 * state it never painted, and the card would just pop.
 */
function _ssPaint(built) {
    _ssView = built.view;
    if (built.recipeId) _ssLastRecipeId = built.recipeId;
    const core = document.getElementById('screensaver-core');
    if (!core) return;
    const html = `<section class="ss-card" data-view="${escapeAttr(built.view)}" data-accent="${escapeAttr(built.accent || 'emerald')}">${built.html}</section>`;
    const settle = () => {
        core.innerHTML = html;
        // The new card is laid out in its "before" pose, then released on the next frame: that
        // is what turns the change into a movement instead of a cut.
        core.classList.add('is-pre');
        void core.offsetWidth;
        core.classList.remove('is-out');
        core.classList.remove('is-pre');
        // A card that inherits the previous scroll offset would open on its middle.
        const cardEl = core.querySelector('.ss-card');
        if (cardEl) cardEl.scrollTop = 0;
        _ssRefreshFooter();
        _ssPaintHead();
        if (typeof _syncScreensaverPinUi === 'function') _syncScreensaverPinUi();
    };
    // Nothing to dissolve into on the very first card: it simply arrives from the top.
    if (!core.querySelector('.ss-card')) { settle(); return; }
    core.classList.remove('is-pre');
    core.classList.add('is-out');
    setTimeout(settle, SS_CARD_OUT_MS);
    // Safety net: a renderer that never runs a transition must still end up with a VISIBLE
    // card. Cheap, and an invisible wall is the one bug nobody can work around.
    setTimeout(() => {
        if (!core.querySelector('.ss-card')) settle();
        core.classList.remove('is-out', 'is-pre');
    }, SS_CARD_OUT_MS + 700);
}


/* ── rotation ───────────────────────────────────────────────────────────────── */

const SS_BUILDERS = {
    meal: () => _ssViewMeal(),
    alerts: () => _ssViewAlerts(),
    nutrition: () => _ssViewNutrition(),
    fact: () => _ssViewFact(),
};

function _ssBuild(viewId) {
    const fn = SS_BUILDERS[viewId];
    if (typeof fn !== 'function') return null;
    try { return fn(); } catch (e) { return null; }
}

/** Show the next (dir 1) or previous (dir -1) view that has something to say. */
function _ssShowNext(dir) {
    const cfg = _ssSettings();
    const order = cfg.views.length ? cfg.views : SS_VIEWS.slice();
    const from = _ssView ? order.indexOf(_ssView) : -1;
    for (let step = 1; step <= order.length; step++) {
        const jump = dir >= 0 ? step : -step;
        const id = order[((from + jump) % order.length + order.length) % order.length];
        if (id === _ssView) continue;
        const built = _ssBuild(id);
        if (!built) continue;
        _ssPaint(built);
        return true;
    }
    // A single enabled view (or one with data): repaint it instead of leaving a blank card.
    const only = _ssBuild(order[0]);
    if (only) { _ssPaint(only); return true; }
    return false;
}

function _ssNextView() {
    _ssShowNext(1);
    _ssRestartRotation();
}

function _ssPrevView() {
    _ssShowNext(-1);
    _ssRestartRotation();
}

/**
 * The label of the view the ⏭ control will show (the pin bar of a forced run).
 * Four literal translation calls, not one key built from a variable: the audit only sees
 * the former, and a key it cannot see is a key that can go missing.
 */
function _ssViewLabel(id) {
    switch (id) {
        case 'meal': return t('screensaver.view_meal');
        case 'alerts': return t('screensaver.view_alerts');
        case 'nutrition': return t('screensaver.view_nutrition');
        case 'fact': return t('screensaver.view_fact');
        default: return '';
    }
}

function _ssNextViewLabel() {
    const cfg = _ssSettings();
    const order = cfg.views.length ? cfg.views : SS_VIEWS.slice();
    const from = _ssView ? order.indexOf(_ssView) : -1;
    for (let step = 1; step <= order.length; step++) {
        const id = order[((from + step) % order.length + order.length) % order.length];
        if (id === _ssView) continue;
        return _ssViewLabel(id);
    }
    return _ssViewLabel(order[0]);
}

function _ssRestartRotation() {
    const cfg = _ssSettings();
    clearInterval(_ssRotate);
    _ssRotate = setInterval(() => {
        // A long run refreshes its data in the background instead of showing a stale shelf.
        if (_ssData && Date.now() - _ssData.at > SS_DATA_TTL_MS) {
            _ssLoad().then(() => { _ssPaintHead(); _ssRefreshFooter(); }).catch(() => {});
        }
        _ssShowNext(1);
    }, cfg.intervalMs);
}


/* ── interaction: tap = next, swipe = next/prev, swipe up = unlock ───────────── */

function _ssOnPointerDown(ev) {
    if (ev.target && ev.target.closest && ev.target.closest('button, a')) {
        _ssGesture = null;   // a control does its own thing
        return;
    }
    _ssGesture = { x: ev.clientX, y: ev.clientY, t: Date.now() };
}

function _ssOnPointerUp(ev) {
    const g = _ssGesture;
    _ssGesture = null;
    if (!g) return;
    const dx = ev.clientX - g.x;
    const dy = ev.clientY - g.y;
    const dt = Date.now() - g.t;
    // Swipe up: back to the app (the same door as «Sblocca»).
    if (dy <= -SS_SWIPE_MIN && Math.abs(dy) > Math.abs(dx) * 1.2) {
        if (typeof dismissScreensaver === 'function') dismissScreensaver();
        return;
    }
    // Swipe sideways: previous / next view.
    if (Math.abs(dx) > SS_SWIPE_MIN && Math.abs(dx) > Math.abs(dy) * 1.4) {
        if (dx > 0) _ssPrevView(); else _ssNextView();
        return;
    }
    // A tap anywhere: next view. It must NOT close the screen (an accidental brush on the
    // wall must not take the clock away) — «Sblocca» and the swipe up are the way out.
    if (Math.abs(dx) <= SS_TAP_SLOP && Math.abs(dy) <= SS_TAP_SLOP && dt < 700) {
        _ssNextView();
    }
}

function _ssOnKey(ev) {
    if (ev.key === 'Escape' && typeof dismissScreensaver === 'function') {
        ev.preventDefault();
        dismissScreensaver();
    }
}

/** The card actions: «Apri ricetta» and the rows of the alerts card. */
function _ssOnClick(ev) {
    const target = ev.target;
    if (!target || !target.closest) return;
    const recipeEl = target.closest('[data-ss-recipe]');
    if (recipeEl) {
        _ssOpenRecipe(parseInt(recipeEl.getAttribute('data-ss-recipe'), 10));
        return;
    }
    const rateEl = target.closest('[data-ss-rate]');
    if (rateEl) {
        _ssRate(parseInt(rateEl.getAttribute('data-ss-id'), 10) || 0,
                parseInt(rateEl.getAttribute('data-ss-rate'), 10) || 0);
        return;
    }
    const rowEl = target.closest('[data-ss-open]');
    if (rowEl) {
        _ssOpenRow(parseInt(rowEl.getAttribute('data-ss-open'), 10) || 0,
                   parseInt(rowEl.getAttribute('data-ss-product'), 10) || 0);
    }
}

function _ssOpenRecipe(idx) {
    if (!Number.isFinite(idx) || idx < 0) return;
    if (typeof dismissScreensaver === 'function') dismissScreensaver();
    setTimeout(() => {
        try {
            if (typeof showPage === 'function') showPage('recipe');
            if (typeof viewArchivedRecipe === 'function') viewArchivedRecipe(idx);
        } catch (e) { /* the archive page is still one tap away */ }
    }, 260);
}

/**
 * «Ti era piaciuta?» → `recipes_rate` (5 or 1). It never blocks the wall: a vote that cannot
 * reach the server is simply not remembered, and the card keeps rotating.
 */
async function _ssRate(id, value) {
    if (!id || !value) return;
    const rating = Math.max(1, Math.min(5, value));
    _ssRatedNow.add(id);
    // The card thanks immediately: a tap on a wall must answer at once, not after a round trip.
    if (_ssView === 'meal') {
        const built = _ssBuild('meal');
        if (built) _ssPaint(built);
    }
    try {
        await api('recipes_rate', {}, 'POST', { id, rating });
        const entry = ((_ssData && _ssData.recipes) || []).find(e => Number(e && e.id) === Number(id));
        if (entry) entry.rating = rating;
    } catch (e) { /* the vote is a courtesy, not a transaction */ }
}

function _ssOpenRow(invId, productId) {
    if (!invId) return;
    if (typeof dismissScreensaver === 'function') dismissScreensaver();
    setTimeout(() => {
        try {
            if (typeof showItemDetail === 'function') showItemDetail(invId, productId || undefined);
        } catch (e) { /* the pantry is one tap away anyway */ }
    }, 260);
}

function _ssBind() {
    const ov = document.getElementById('screensaver');
    if (!ov || ov.dataset.ssBound === '1') return;
    ov.dataset.ssBound = '1';
    ov.addEventListener('pointerdown', _ssOnPointerDown);
    ov.addEventListener('pointerup', _ssOnPointerUp);
    ov.addEventListener('click', _ssOnClick);
    document.addEventListener('keydown', _ssOnKey);
}

function _ssUnbind() {
    const ov = document.getElementById('screensaver');
    if (!ov) return;
    ov.removeEventListener('pointerdown', _ssOnPointerDown);
    ov.removeEventListener('pointerup', _ssOnPointerUp);
    ov.removeEventListener('click', _ssOnClick);
    document.removeEventListener('keydown', _ssOnKey);
    delete ov.dataset.ssBound;
}


/* ── pixel drift (burn-in) ──────────────────────────────────────────────────── */

function _ssStartPixelShift() {
    clearInterval(_ssShift);
    const offsets = [[0, 0], [SS_PIXEL_SHIFT_PX, 0], [SS_PIXEL_SHIFT_PX, SS_PIXEL_SHIFT_PX], [0, SS_PIXEL_SHIFT_PX]];
    _ssShiftIdx = 0;
    _ssShift = setInterval(() => {
        const stage = document.getElementById('screensaver-stage');
        if (!stage) return;
        _ssShiftIdx = (_ssShiftIdx + 1) % offsets.length;
        const [x, y] = offsets[_ssShiftIdx];
        stage.style.transform = `translate(${x}px, ${y}px)`;
    }, SS_PIXEL_SHIFT_MS);
}

/* ── first-run hint (once per browser session) ──────────────────────────────── */

function _ssMaybeHint() {
    let seen = false;
    try { seen = sessionStorage.getItem('evershelf_ss_hint') === '1'; } catch (_) {}
    if (seen) return;
    try { sessionStorage.setItem('evershelf_ss_hint', '1'); } catch (_) {}
    const stage = document.getElementById('screensaver-stage');
    if (!stage) return;
    const old = document.getElementById('screensaver-hint');
    if (old) old.remove();
    const hint = document.createElement('div');
    hint.id = 'screensaver-hint';
    hint.textContent = t('screensaver.tap_hint');
    stage.appendChild(hint);
    _ssHintTimer = setTimeout(() => { hint.remove(); _ssHintTimer = null; }, 9000);
}

/* ── lifecycle ──────────────────────────────────────────────────────────────── */

async function _ssEngineStart() {
    if (_ssRunning) return;
    _ssRunning = true;
    _ssBind();
    _ssPaintHead();
    await _ssLoad().catch(() => {});
    _ssView = '';
    if (!_ssShowNext(1)) {
        const core = document.getElementById('screensaver-core');
        if (core) core.innerHTML = '';
    }
    _ssRestartRotation();
    _ssStartPixelShift();
    _ssMaybeHint();
    _ssRefreshFooter();
    // The price of the list is computed elsewhere: once it lands, the footer says so.
    if (typeof syncShoppingPriceTotal === 'function') {
        try { syncShoppingPriceTotal(false).then(_ssRefreshFooter).catch(() => {}); } catch (_) {}
    }
}

function _ssEngineStop() {
    _ssRunning = false;
    clearInterval(_ssRotate);
    _ssRotate = null;
    clearInterval(_ssShift);
    _ssShift = null;
    clearTimeout(_ssHintTimer);
    _ssHintTimer = null;
    _ssUnbind();
    _ssGesture = null;
    _ssView = '';
    _ssFactTurn = 0;
    const stage = document.getElementById('screensaver-stage');
    if (stage) stage.style.transform = '';
    const core = document.getElementById('screensaver-core');
    if (core) {
        core.innerHTML = '';
        core.classList.remove('is-out', 'is-pre');
    }
    const hint = document.getElementById('screensaver-hint');
    if (hint) hint.remove();
}

window._ssEngineStart = _ssEngineStart;
window._ssEngineStop = _ssEngineStop;
window._ssNextView = _ssNextView;
window._ssRefreshFooter = _ssRefreshFooter;

