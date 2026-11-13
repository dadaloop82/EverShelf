/**
 * EverShelf Med — the CORE of the medicine scanner (country-NEUTRAL).
 *
 * Nothing Italian lives here. This file knows:
 *   - the per-country schema (`data/medical_code_schemas.json`, served by `med_status`): the
 *     `codes` a country declares, the `ignored` PACKAGING codes, the `ocr` patterns, the GS1
 *     range;
 *   - the GS1 element string of a 2D DataMatrix (a standard, not a national rule);
 *   - the registry of COUNTRY MODULES: a country that needs more than a regex declares
 *     `"module": "<slug>"` in the schema and ships `assets/js/med/<slug>.js`, which registers
 *     its own `localCode()` — no national rule is written here.
 *
 * The question "is this read one of your drug codes?" is answered by the module, never here —
 * so adding a country is a data block plus one small file, and dropping a country leaves a
 * scanner that still recognises the patterns of the others (a PZN, a CN, an NDC…).
 *
 * Loaded before app.js (index.html + the service-worker shell).
 */

/** The registered country modules, keyed by the `module` slug of the schema. `globalThis` so
 *  the same file runs in the browser and in the node guard test (no `window` shim there). */
globalThis.EVERSHELF_MED_MODULES = globalThis.EVERSHELF_MED_MODULES || {};

/**
 * Register one country module.
 *
 * @param {string} slug the value of `"module"` in data/medical_code_schemas.json
 * @param {{cc:string, localCode:function(string):({code:string,type:string,label_key:string,aic?:string}|null), parafCode?:function(string):({code:string,type:string,label_key:string}|null)}} module
 */
function _medRegisterCountryModule(slug, module) {
    const key = String(slug || '').toLowerCase().trim();
    if (!key || !module || typeof module.localCode !== 'function') return;
    globalThis.EVERSHELF_MED_MODULES[key] = module;
}

/** The module of the ACTIVE country (the schema says which), or null. */
function _medCountryModule() {
    const schema = _medStatus && _medStatus.schema;
    const slug = schema && schema.module ? String(schema.module).toLowerCase() : '';
    if (!slug) return null;
    return globalThis.EVERSHELF_MED_MODULES[slug] || null;
}

/** Strip the decoration a label or a scanner adds around a code. */
function _medCompactCode(code) {
    return String(code || '').toUpperCase().trim().replace(/[\s\-._/]+/g, '');
}

/**
 * The GS1 element string of a 2D DataMatrix (EU FMD), parsed into its AIs.
 *
 * Country-neutral: it knows the standard, not what a country means by a number. A box carries
 * GTIN (`01`), expiry (`17`), batch (`10`) and serial (`21`); AI `71x` is the national
 * reimbursement number, whose CONTENT belongs to the country module.
 *
 * Parsed by walking the string, because the AIs have different lengths: `01`/`02` take 14
 * characters, `11`/`15`/`17` take 6, and a variable-length AI (`10`, `21`, `30`, `37`, `71x`)
 * ends at the GS separator (FNC1, `\x1d`). An AI we do not know with no separator ends the
 * walk; a read that yields no AI at all is not a GS1 element string. Same algorithm as
 * `medicalGs1Parse()` in api/lib/medical.php (two ports, one rule).
 *
 * @returns {{fmd:boolean, ais:Object, gtin:string|null, expiry:string|null, batch:string|null, serial:string|null}}
 */
function _medGs1Parse(read) {
    const out = { fmd: false, ais: {}, gtin: null, expiry: null, batch: null, serial: null };
    let s = String(read || '').trim();
    if (!s) return out;
    s = s.replace(/\\u001d|\\x1d/g, '\x1d');
    const fixed = { '01': 14, '02': 14, '11': 6, '15': 6, '17': 6, '20': 2 };
    const variable = ['10', '21', '30', '37'];
    let pos = 0;
    while (pos < s.length) {
        if (s[pos] === '\x1d') { pos++; continue; }
        const three = s.substr(pos, 3);
        if (/^71\d$/.test(three)) {
            let end = s.indexOf('\x1d', pos + 3);
            if (end === -1) end = s.length;
            const value = s.substring(pos + 3, end);
            if (value) out.ais['71'] = value.toUpperCase();
            pos = end;
            continue;
        }
        const two = s.substr(pos, 2);
        if (Object.prototype.hasOwnProperty.call(fixed, two)) {
            const value = s.substr(pos + 2, fixed[two]);
            if (value.length < fixed[two]) break;
            out.ais[two] = value;
            pos += 2 + fixed[two];
            continue;
        }
        if (variable.indexOf(two) !== -1) {
            let end = s.indexOf('\x1d', pos + 2);
            if (end === -1) end = s.length;
            const value = s.substring(pos + 2, end);
            if (value) out.ais[two] = value;
            pos = end;
            continue;
        }
        break;
    }
    if (!Object.keys(out.ais).length) return out;
    out.gtin = out.ais['01'] || null;
    out.expiry = out.ais['17'] || null;
    out.batch = out.ais['10'] || null;
    out.serial = out.ais['21'] || null;
    out.fmd = out.gtin !== null || out.ais['71'] !== undefined || out.expiry !== null;
    return out;
}

/**
 * Is this read one of the DRUG CODES of the active country? Answered by its module.
 *
 * @returns {{code:string,type:string,label_key:string,aic?:string}|null}
 */
function _medLocalCode(code) {
    const module = _medCountryModule();
    if (!module) return null;
    let result = null;
    try { result = module.localCode(String(code || '')); } catch (e) { result = null; }
    return (result && result.code) ? result : null;
}

/**
 * Is this read a country-specific non-catalogue pack code (Italy: Farmadati PARAF 9xxxxxxxx)?
 *
 * Optional module hook — countries without it answer null. The core never interprets the shape.
 *
 * @returns {{code:string,type:string,label_key:string}|null}
 */
function _medParafCode(code) {
    const module = _medCountryModule();
    if (!module || typeof module.parafCode !== 'function') return null;
    let result = null;
    try { result = module.parafCode(String(code || '')); } catch (e) { result = null; }
    return (result && result.code) ? result : null;
}


/**
 * Is this read one of the PACKAGING codes the schema tells us to throw away?
 *
 * A packaging code (a label of the production line, a lot, a serial) says nothing about which
 * drug the box contains. The schema lists them under `ignored` with the action to take instead
 * (`ocr`: read the drug code printed on the label).
 */
function _medCodeIgnored(code) {
    const schema = _medStatus.schema;
    const c = _medCompactCode(code);
    if (!schema || !c || !Array.isArray(schema.ignored)) return false;
    // The country's own drug code is never a packaging code.
    if (_medLocalCode(c)) return false;
    for (const def of schema.ignored) {
        if (!def || !def.pattern) continue;
        let re = null;
        try { re = new RegExp(def.pattern); } catch (e) { re = null; }
        if (re && re.test(c)) return true;
    }
    return false;
}

/** The label of an ignored code (`medical.code_line`), so the UI can name what it read. */
function _medIgnoredLabelKey(code) {
    const schema = _medStatus.schema;
    const c = _medCompactCode(code);
    if (!schema || !c || !Array.isArray(schema.ignored)) return '';
    if (_medLocalCode(c)) return '';
    for (const def of schema.ignored) {
        if (!def || !def.pattern) continue;
        let re = null;
        try { re = new RegExp(def.pattern); } catch (e) { re = null; }
        if (re && re.test(c)) return String(def.label_key || '');
    }
    return '';
}

/**
 * How far two printed codes are (edit distance). Only used to ORDER the candidates of a doubtful
 * read: an OCR read that differs by one digit is "close", the same read twice is distance 0.
 */
function _medEditDistance(a, b) {
    const s1 = String(a || '');
    const s2 = String(b || '');
    if (!s1.length) return s2.length;
    if (!s2.length) return s1.length;
    let prev = new Array(s2.length + 1);
    let cur = new Array(s2.length + 1);
    for (let j = 0; j <= s2.length; j++) prev[j] = j;
    for (let i = 1; i <= s1.length; i++) {
        cur[0] = i;
        for (let j = 1; j <= s2.length; j++) {
            const cost = s1.charAt(i - 1) === s2.charAt(j - 1) ? 0 : 1;
            cur[j] = Math.min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + cost);
        }
        const swap = prev; prev = cur; cur = swap;
    }
    return prev[s2.length];
}

/**
 * The candidates of a doubtful read, closest first, at most `limit`.
 *
 * This is the answer to "the OCR read a wrong last digit": the household gets the codes that are
 * one edit away from what the camera saw — real rows of the local catalogue, each with the name
 * that is printed on the box — and confirms with one tap. The read itself, when the catalogue
 * knows it, stays first (distance 0), so an easy case is still a single tap.
 *
 * @param {string} read the number the reader produced (canonical, no prefix letter)
 * @param {Array<{aic: string}>} rows catalogue rows to choose from
 * @param {number} [limit]
 * @returns {Array<{aic: string, distance: number, row: object|null}>}
 */
function _medChoiceCandidates(read, rows, limit = 4) {
    // No national rule here: the country module says what the read means (Italy drops the printed
    // `A`, the US keeps the NDC groups), and the core only ranks what comes back.
    const raw = String(read || '').replace(/[^0-9A-Z]/gi, '').toUpperCase();
    const local = (typeof _medLocalCode === 'function') ? _medLocalCode(raw) : null;
    const code = local ? String(local.aic || local.code || raw) : raw;
    const out = [];
    const seen = {};
    for (const row of (Array.isArray(rows) ? rows : [])) {
        const aic = String((row && row.aic) || '').replace(/[^0-9A-Z]/gi, '').toUpperCase();
        if (!aic || seen[aic]) continue;
        seen[aic] = true;
        out.push({ aic: aic, distance: _medEditDistance(code, aic), row: row });
    }
    // The read itself is always offered, even when the catalogue does not know it: it is what
    // the camera saw, and hiding it would hide the evidence.
    if (code && !seen[code]) {
        out.unshift({ aic: code, distance: 0, row: null });
    }
    out.sort((a, b) => (a.distance - b.distance) || (a.aic < b.aic ? -1 : a.aic > b.aic ? 1 : 0));
    return out.slice(0, Math.max(1, limit));
}

/** The shape the label prints (e.g. `A000000000`): the guide frame shows it, from the data. */
function _medOcrSample() {
    const schema = _medStatus && _medStatus.schema;
    const sample = schema && schema.ocr && schema.ocr.sample ? String(schema.ocr.sample) : '';
    return sample;
}

/** The OCR patterns of the country block: what the label prints, written down as data. */
function _medOcrPatterns() {
    const schema = _medStatus.schema;
    const list = schema && schema.ocr && Array.isArray(schema.ocr.patterns) ? schema.ocr.patterns : [];
    return list.map(String).filter(Boolean);
}

/**
 * Is a national catalogue loaded on this instance?
 *
 * The READER needs to know, because the forgiving paths below (a prefix letter that did not
 * survive the camera, and a bare run of nine digits) are only ever allowed when something can
 * PROVE the number: the catalogue rows. With no catalogue an unproven read would be stored as a
 * medicine, which is exactly the mistake the strict path exists to avoid. Same flag the client
 * already shows in Settings (`med_status.dataset.loaded`).
 */
function _medCatalogueLoaded() {
    return !!(_medStatus && _medStatus.dataset && _medStatus.dataset.loaded);
}

/**
 * The drug code inside a page of OCR text.
 *
 * The patterns are DATA (`schema.ocr.patterns`): a country that prints a different number only
 * changes its block in `data/medical_code_schemas.json`. OCR puts spaces between the groups of
 * a printed code (`A 040 313 076`), so every pattern runs twice: on the page as read, and on a
 * copy with the separators removed. Validating the hit is the country module's job
 * (`_medLocalCode`), so this function never decides what a drug code looks like.
 *
 * @returns {string|null} the canonical drug number, if the page carries one
 */
function _medOcrFindAic(text, patterns) {
    const source = String(text || '');
    if (!source) return null;
    // OCR of an OCR-B line inserts spaces between the digits and confuses `1`/`l`/`I` and
    // `0`/`O`. Both are repaired ONLY inside a candidate that still starts with the country's
    // prefix letter, so the pack serial can never be promoted to a drug number.
    const compact = source.replace(/[\s.\-_:|/]+/g, '').replace(/^a/i, 'A');
    const confusable = compact
        .replace(/A[0-9lIO]{9}/gi, (run) => run.charAt(0) + run.slice(1).replace(/[lI]/g, '1').replace(/O/g, '0'));
    // A camera loses the open `A` of the printed block far more often than a digit: in OCR-B the
    // A is one thin stroke pair, and a reflection turns it into `4`, `@` or nothing at all. The
    // letter is therefore RECOVERED (never invented: the nine digits must be there) — and only
    // when a catalogue can confirm the digits, so a serial cannot ride the same repair.
    const repairedPrefix = _medCatalogueLoaded()
        ? compact.replace(/[4@&$#][0-9lIO]{9}/g, (run) => 'A' + run.slice(1).replace(/[lI]/g, '1').replace(/O/g, '0'))
        : '';
    const haystacks = [source, compact, confusable];
    if (repairedPrefix) haystacks.push(repairedPrefix);
    const hits = [];
    for (const pattern of (patterns || [])) {
        let re = null;
        try { re = new RegExp(pattern, 'i'); } catch (e) { re = null; }
        if (!re) continue;
        for (const haystack of haystacks) {
            // Every match, and the prefix letter must START a token: in `AAA014058142` (the tail of
            // another word printed above the pack's progressive number) the A merely ENDS a word —
            // taking it promoted a serial to a drug code, which is the bug this reader was born
            // from. A boundary check, not a looser pattern.
            const scan = new RegExp(re.source, re.flags.indexOf('g') === -1 ? re.flags + 'g' : re.flags);
            let m = null;
            while ((m = scan.exec(haystack)) !== null) {
                const before = m.index > 0 ? haystack.charAt(m.index - 1) : '';
                // Only a LETTER A in front is the trap (`AAA014058142` = the tail of another word
                // glued to the progressive number). Any other letter is just the previous word of
                // the line after the separators were compacted (`…compresseA040313076`).
                if (/[Aa]/.test(before)) {
                    scan.lastIndex = m.index + 1;
                    continue;
                }
                const local = _medLocalCode(m[0]);
                if (local && local.aic) return local.aic;
                if (local) hits.push(local.code);
            }
        }
    }
    // A module without an `aic` still answers with a canonical code: return the first hit's key.
    if (hits.length) return hits[0];
    // NO unproven fallback: a bare 9-digit run is exactly the pack serial printed under the SSN
    // barcode, and taking it as the drug number is what loaded somebody else's box. The
    // catalogue-gated path below (`_medOcrDigitCandidates`) is the only exception, and every
    // candidate must be proved against the rows before anything opens.
    return null;
}

/**
 * The nine-digit candidates of a page when the printed prefix letter was NOT read at all.
 *
 * The reader cannot choose between them — the catalogue can: `A040313076` is a row, the pack's
 * progressive number `014058142` is not. So the page hands over its digit runs and the caller
 * asks the rows (`_medTryLooseReads`); a candidate that matches nothing is dropped like any
 * other wrong read. Without a catalogue this returns NOTHING, because there is no proof.
 *
 * A ten-digit run is offered as both of its nine-digit windows: the bollino prints the pack's
 * progressive number with a check digit (`0140581421`) next to the drug number, and that check digit is
 * not part of the number anybody stores.
 *
 * @param {string} text the page (or the pages) of OCR text
 * @param {number} [limit] how many candidates the caller is willing to verify (default 4)
 * @returns {string[]} nine-digit candidates, in reading order
 */
function _medOcrDigitCandidates(text, limit = 4) {
    if (!_medCatalogueLoaded()) return [];
    // The letters are NOT stripped away first: a `1`/`l`/`I` repair applied to the whole page
    // glues a word to the number next to it ("lotto 0140581421" became one long run and the real
    // one disappeared). The runs are matched first, and repaired inside only.
    const compact = String(text || '').replace(/[^0-9A-Za-z]/g, '');
    const out = [];
    // A thin printed `0` right after the A is the character OCR drops most often: the household's
    // own label reads `A42386348` for `A042386348`. The repaired number is only ever a CANDIDATE —
    // the catalogue has the last word (`_medTryLooseReads`).
    for (const m of compact.matchAll(/A([0-9lIO]{8})(?![0-9lIO])/g)) {
        const digits = m[1].replace(/[lI]/g, '1').replace(/O/g, '0');
        const fixed = '0' + digits;
        if (out.indexOf(fixed) === -1) out.push(fixed);
    }
    const re = /[0-9lIO]{9,10}/g;
    let m = null;
    while ((m = re.exec(compact)) !== null) {
        const run = m[0].replace(/[lI]/g, '1').replace(/O/g, '0');
        const windows = run.length > 9 ? [run.slice(0, 9), run.slice(-9)] : [run];
        for (const w of windows) {
            if (out.indexOf(w) === -1) out.push(w);
            if (out.length >= limit) return out.slice(0, limit);
        }
    }
    return out.slice(0, limit);
}

/**
 * How many pieces a pack description names ("10 buste", "20 compresse", "30 cpr").
 *
 * Country-neutral on purpose: a leading count followed by a word that is not a unit of measure.
 * A liquid ("1 flacone 200 ml") has no pieces, and a count of one is a single object, so both
 * answer zero — the caller then simply asks for a quantity instead of drawing fractions.
 *
 * @param {string} pack the catalogue's pack description (or any text that carries it)
 * @returns {{pieces:number, unit:string}}
 */
/**
 * The PACK SIZE of a box: what the full, sealed container holds.
 *
 * The catalogue text mixes the container with the concentration — "Fastum gel 2,5% 25 mg/g gel
 * tubo 50 g" — and taking the FIRST number (which is what the form used to do) put "25 mg" on a
 * 50 g tube: a dose per gram is not what the tube holds. So:
 *   1. countable pieces first (`_medPackCount()`: "10 compresse", "20 buste");
 *   2. otherwise the container measure, ignoring anything written as a RATIO (`25 mg/g`,
 *      `10 mg/ml`, `mg/100 g`) and picking the LARGEST remaining g/ml value — a dose is a rounding
 *      error next to the tube it comes in.
 *
 * @param {string} pack the pack/dosage text from the catalogue (or the row's notes)
 * @returns {{qty:number, unit:'pz'|'g'|'ml', label:string}} qty 0 when nothing is readable
 */
function _medPackSize(pack) {
    const text = String(pack || '');
    const pieces = _medPackCount(text);
    if (pieces.pieces >= 2) {
        return { qty: pieces.pieces, unit: 'pz', label: pieces.pieces + ' ' + pieces.unit };
    }
    const re = /(\d{1,4}(?:[.,]\d{1,2})?)\s*(kg|g|gr|ml|cl|dl|l|cc)\b(\s*\/\s*(?:\d+\s*)?(?:g|gr|kg|ml|cl|dl|l))?/gi;
    let best = null;
    let m = null;
    while ((m = re.exec(text)) !== null) {
        if (m[3]) continue;                       // "mg/g", "mg/ml": a dose, not the pack
        const raw = Number(String(m[1]).replace(',', '.'));
        if (!(raw > 0)) continue;
        const u = String(m[2]).toLowerCase();
        const liquid = (u === 'ml' || u === 'cl' || u === 'dl' || u === 'l' || u === 'cc');
        let qty = raw;
        if (u === 'cl') qty = raw * 10;
        else if (u === 'dl') qty = raw * 100;
        else if (u === 'l') qty = raw * 1000;
        else if (u === 'kg') qty = raw * 1000;
        if (!(qty > 0)) continue;
        if (!best || qty > best.qty) {
            best = { qty: qty, unit: liquid ? 'ml' : 'g', label: m[1] + ' ' + m[2] };
        }
    }
    return best || { qty: 0, unit: 'pz', label: '' };
}

function _medPackCount(pack) {
    const measures = ['ml', 'l', 'cl', 'dl', 'cc', 'g', 'gr', 'mg', 'kg', 'mcg', 'ui', 'iu', 'dose', 'gocce'];
    // Prefer real pack words when several numbers appear ("200 MG … 24 COMPRESSE" → 24).
    const preferred = /^(compresse?|cpr|bustine?|capsule?|cps|fiale?|supposte?|ovuli|cerotti|bustina|sachets?|tablets?|capsules?)$/i;
    const re = /(\d{1,3})\s*([A-Za-zÀ-ÿ]{2,})/g;
    let best = { pieces: 0, unit: '' };
    let m = null;
    while ((m = re.exec(String(pack || ''))) !== null) {
        const pieces = Number(m[1]);
        const unit = String(m[2]).toLowerCase();
        if (measures.indexOf(unit) !== -1) continue;
        if (!(pieces >= 2 && pieces <= 200)) continue;
        const score = preferred.test(unit) ? (pieces + 1000) : pieces;
        const bestScore = preferred.test(best.unit) ? (best.pieces + 1000) : best.pieces;
        if (score > bestScore) best = { pieces: pieces, unit: String(m[2]) };
    }
    return best;
}


/**
 * Is this scanned code a medicine pack, for the active country?
 *
 * Country-NEUTRAL: the identifying code comes from the country module (`_medLocalCode()`), the
 * packaging codes from the schema's `ignored` list, and the remaining national formats are
 * plain patterns (`pzn`, `cn`, `ndc`, an EAN where the national code really is one). A country
 * with no schema — or whose module is not registered — claims NOTHING.
 *
 * @returns {{detected:boolean, confidence:'high'|'probable'|'none', type:string, label_key:string, aic:string|null, ignored:boolean}}
 */
function _medDetectCode(code) {
    const out = { detected: false, confidence: 'none', type: '', label_key: '', aic: null, ignored: false };
    const schema = _medStatus.schema;
    if (!schema || !schema.status || schema.status === 'none') return out;
    const c = String(code || '').trim().toUpperCase();
    if (!c) return out;
    const digits = c.replace(/\D/g, '');
    const status = String(schema.status || 'none');
    const confidence = status === 'full' ? 'high' : 'probable';

    // 1. The country's own drug code — only if the schema declares that type, so the data file
    //    stays in charge of what is recognisable.
    const local = _medLocalCode(c);
    if (local) {
        const def = (schema.codes || []).find(d => d && d.type === local.type);
        if (def) {
            return {
                detected: true,
                confidence: confidence,
                type: String(local.type),
                label_key: String(local.label_key || def.label_key || ''),
                aic: local.aic ? String(local.aic) : null,
                ignored: false,
            };
        }
    }

    // 2. A packaging code (a line code of the box): say so, claim nothing.
    if (_medCodeIgnored(c)) {
        return { detected: false, confidence: 'none', type: '', label_key: _medIgnoredLabelKey(c), aic: null, ignored: true };
    }

    // Austrian PZN is embedded in the EAN (908888…): check it before the plain EAN rule.
    for (const def of (schema.codes || [])) {
        if (def && def.type === 'pzn_in_ean' && def.prefix && c.startsWith(String(def.prefix))) {
            return { detected: true, confidence: 'high', type: def.type, label_key: def.label_key || '', aic: null, ignored: false };
        }
    }

    // EAN-13: "ours" only when the GS1 prefix belongs to this country AND the schema declares
    // an EAN as a national code (a country whose number is not an EAN simply omits it).
    if (/^\d{13}$/.test(c)) {
        for (const range of (schema.gs1 || [])) {
            const lo = String((range && range[0]) || '');
            const hi = String((range && range[1]) || lo);
            const prefix = c.slice(0, lo.length);
            if (lo && prefix >= lo && prefix <= hi) {
                const def = (schema.codes || []).find(d => d.type === 'ean13' && Number(d.digits || 13) === 13);
                if (def) {
                    return { detected: true, confidence: confidence, type: 'ean13', label_key: def.label_key || '', aic: null, ignored: false };
                }
            }
        }
        return out;
    }

    // National short codes.
    for (const def of (schema.codes || [])) {
        if (def.type === 'ean13' || def.type === 'pzn_in_ean') continue;
        if (!_medCodeMatches(def, c, digits)) continue;
        return { detected: true, confidence: confidence, type: def.type, label_key: def.label_key || '', aic: null, ignored: false };
    }
    return out;
}

/**
 * Does this read match ONE code definition of the country schema?
 *
 * The `pattern` is matched against the whole code exactly as the box prints it, letters
 * included — that is the only way to tell a Laetus Pharmacode (`123456`) from the lot/serial
 * fragment of an EU FMD DataMatrix (`16G87N`). A definition without a pattern falls back to its
 * digit count, so a schema written before the patterns still works; the numeric bounds
 * (Pharmacode 3…131070) always apply.
 *
 * @param {Object} def one entry of schema.codes
 * @param {string} code the whole read, upper-cased
 * @param {string} digits the same read without letters
 * @returns {boolean}
 */
function _medCodeMatches(def, code, digits) {
    if (!def) return false;
    if (def.pattern) {
        let re = null;
        try { re = new RegExp(def.pattern); } catch (e) { re = null; }
        if (!re || !re.test(code)) return false;
    } else {
        const wanted = def.digits;
        if (Array.isArray(wanted)) {
            if (!wanted.map(Number).includes(digits.length)) return false;
        } else if (typeof wanted === 'number' && wanted > 0) {
            if (digits.length !== wanted) return false;
        } else if (def.type !== 'pharmacode') {
            return false;
        }
    }
    if (def.type === 'pharmacode' || def.min !== undefined || def.max !== undefined) {
        if (!/^\d+$/.test(code)) return false;
        const min = Number(def.min === undefined ? 3 : def.min);
        const max = Number(def.max === undefined ? 131070 : def.max);
        const n = Number(code);
        if (!(n >= min && n <= max)) return false;
    }
    return true;
}
