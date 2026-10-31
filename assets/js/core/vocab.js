/**
 * EverShelf — vocabulary engine (browser side).
 *
 * Product names, genres, brands and plurals are DATA: assets/js/vocab.generated.js,
 * produced from api/vocab/<lang>.json by scripts/gen-vocab-js.php. This file is the
 * algorithm and is a port of api/lib/vocabulary.php — no product word, no genre, no
 * brand, and no regex on vocabulary belongs here. Adding a language = adding a JSON on
 * the server and regenerating; a missing entry never invents anything.
 *
 * Term syntax (one term = one array item in the JSON):
 *   "pane"                a whole word
 *   "pomodor*"            a stem (pomodoro, pomodori, pomodorini…)
 *   "fette biscottate"    consecutive words
 *   "estratto+vaniglia"   all these words, in any order
 */
function _vocabData() {
    const all = (typeof EVERSHELF_VOCAB_ALL !== 'undefined' && EVERSHELF_VOCAB_ALL) ? EVERSHELF_VOCAB_ALL : {};
    const lang = (typeof _currentLang === 'string' && _currentLang) ? _currentLang : 'it';
    return all[lang] || all.it || {};
}

/** True when the character is one the tokenizer keeps INSIDE a word (data, not code). */
function _vocabSeparators() {
    if (!_vocabSeparators._set) {
        const list = String(_vocabData().word_separators || " \t\n\r.,;:!?()[]{}\"'«»…/\\-–—_+*&%#@|");
        const set = new Set();
        Array.from(list).forEach(ch => set.add(ch));
        _vocabSeparators._set = set;
    }
    return _vocabSeparators._set;
}

/** Lowercase + diacritics folded, spaces collapsed (no regex). */
function vocabFold(text) {
    const fold = _vocabData().fold || {};
    let out = String(text == null ? '' : text).trim().toLowerCase();
    let folded = '';
    Array.from(out).forEach(ch => { folded += (fold[ch] !== undefined ? fold[ch] : ch); });
    let collapsed = '';
    let prevSpace = false;
    Array.from(folded).forEach(ch => {
        const isSpace = ch === ' ';
        if (isSpace && prevSpace) return;
        collapsed += ch;
        prevSpace = isSpace;
    });
    return collapsed;
}

/** Words of a name, folded — any script (Latin, accented, Cyrillic, CJK). */
function vocabTokens(text) {
    const seps = _vocabSeparators();
    const tokens = [];
    let cur = '';
    Array.from(vocabFold(text)).forEach(ch => {
        if (ch === ' ' || seps.has(ch)) {
            if (cur !== '') { tokens.push(cur); cur = ''; }
            return;
        }
        cur += ch;
    });
    if (cur !== '') tokens.push(cur);
    return tokens;
}

/**
 * Best match of one term: {pos, len} or null.
 * "+" terms score by the earliest part and the number of required parts.
 */
function vocabTermMatchScore(tokens, term) {
    const t = String(term || '').trim();
    if (!t || !tokens.length) return null;
    if (t.includes('+')) {
        const parts = t.split('+');
        let earliest = null;
        for (const part of parts) {
            const score = vocabTermMatchScore(tokens, part);
            if (!score) return null;
            if (earliest === null || score.pos < earliest) earliest = score.pos;
        }
        return { pos: earliest, len: parts.length };
    }
    const isStem = t.endsWith('*');
    const words = vocabTokens(isStem ? t.slice(0, -1) : t);
    if (!words.length) return null;
    for (let i = 0; i <= tokens.length - words.length; i++) {
        let ok = true;
        for (let j = 0; j < words.length; j++) {
            const token = tokens[i + j] || '';
            const hit = (isStem && j === words.length - 1) ? token.startsWith(words[j]) : token === words[j];
            if (!hit) { ok = false; break; }
        }
        if (ok) return { pos: i, len: words.length };
    }
    return null;
}

/** Does an already tokenized name satisfy one term? */
function vocabMatchesTerm(tokens, term) {
    return vocabTermMatchScore(tokens, term) !== null;
}

function vocabMatchesTermsTokens(tokens, terms) {
    return (terms || []).some(term => vocabMatchesTerm(tokens, term));
}

function vocabNameHasAnyTerm(name, terms) {
    return vocabMatchesTermsTokens(vocabTokens(name), terms);
}

/** Best {cat,pos,len} among the terms of one rule, or null. */
function vocabBestTermInRule(tokens, rule) {
    const cat = (rule && rule.cat) || '';
    if (!cat) return null;
    let best = null;
    for (const term of (rule.terms || [])) {
        const score = vocabTermMatchScore(tokens, term);
        if (!score) continue;
        if (!best || score.len > best.len || (score.len === best.len && score.pos < best.pos)) {
            best = { cat, pos: score.pos, len: score.len };
        }
    }
    return best;
}

/**
 * Local category of a product title.
 *
 * Language-neutral ranking (mirrors api/lib/vocabulary.php):
 *   1. among category hits: longer phrase, then earliest word in the title,
 *   2. brand → category only when the title itself named no genre.
 * No product word belongs here.
 */
function vocabCategoryOfName(name, brand = '') {
    const data = _vocabData();
    const tokens = vocabTokens(String(name || '') + ' ' + String(brand || ''));
    if (!tokens.length) return 'altro';
    let best = null;
    for (const rule of (data.categories || [])) {
        const hit = vocabBestTermInRule(tokens, rule);
        if (!hit) continue;
        if (!best || hit.len > best.len || (hit.len === best.len && hit.pos < best.pos)) {
            best = hit;
        }
    }
    if (best) return best.cat;
    for (const rule of (data.brands || [])) {
        if (vocabMatchesTermsTokens(tokens, rule.terms)) return rule.cat || 'altro';
    }
    return 'altro';
}

/**
 * OFF slug → local category (data/off_categories.json → off_categories.generated.js).
 * Returns the local slug, or null when the tag is a catch-all / unknown
 * (caller guesses from the title via vocabCategoryOfName).
 */
function vocabMapOffSlug(slug) {
    let s = String(slug || '').trim().toLowerCase();
    if (!s) return null;
    const m = s.match(/^[a-z]{2}:(.+)$/);
    if (m) s = m[1];
    const table = (typeof EVERSHELF_OFF_CATEGORIES !== 'undefined' && EVERSHELF_OFF_CATEGORIES)
        ? EVERSHELF_OFF_CATEGORIES
        : null;
    const map = (table && table.by_slug) || {};
    if (!Object.prototype.hasOwnProperty.call(map, s)) return null;
    const cat = map[s];
    return (cat != null && cat !== '') ? String(cat) : null;
}

/** Does the title carry a pack size ("500 g", "1,5 l")? Format, not vocabulary. */
function vocabHasPackSize(name) {
    const units = _vocabData().pack_size_units || [];
    const tokens = vocabTokens(name);
    for (let i = 0; i < tokens.length - 1; i++) {
        const number = tokens[i].replace(',', '.');
        if (number !== '' && !isNaN(Number(number)) && units.includes(tokens[i + 1])) return true;
    }
    return false;
}

/** Fresh produce this household counts by piece (and not a preparation of it). */
function vocabIsPieceProduce(name) {
    const data = _vocabData();
    if (vocabNameHasAnyTerm(name, data.produce_preparations || []) || vocabHasPackSize(name)) return false;
    return vocabNameHasAnyTerm(name, data.piece_produce || []);
}

/** Fresh produce whose default unit is the piece — the old "classic" subset (data). */
function vocabIsClassicPiece(name) {
    return vocabNameHasAnyTerm(name, _vocabData().classic_piece_defaults || []);
}

/** A preparation of a product: never the fresh article of the same word. */
function vocabLooksProcessed(name) {
    return vocabNameHasAnyTerm(name, _vocabData().processed || []);
}

/** "Insalata di riso" is a prepared dish, not leafy produce. */
function vocabIsPreparedSalad(name, brand = '') {
    const rules = _vocabData().prepared_salad || {};
    const tokens = vocabTokens(name);
    for (const phrase of (rules.phrases || [])) {
        if (vocabMatchesTerm(tokens, (phrase || []).join(' '))) return true;
    }
    for (const pair of (rules.requires || [])) {
        if ((pair || []).every(word => vocabMatchesTerm(tokens, String(word)))) return true;
    }
    if (brand) {
        const brandTokens = vocabTokens(brand);
        for (const word of (rules.brands || [])) {
            if (vocabMatchesTerm(brandTokens, String(word))) return true;
        }
    }
    return false;
}

/** Curated number forms of a word ("Pomodoro"/"Pomodori"), or null for an unknown word. */
function vocabNumberForms(word) {
    if (!vocabNumberForms._index) {
        const index = {};
        const forms = _vocabData().number_forms || {};
        Object.keys(forms).forEach(canonical => {
            const v = forms[canonical];
            if (!Array.isArray(v) || v.length < 2) return;
            [canonical, v[0], v[1]].forEach(key => {
                const fold = vocabFold(String(key));
                if (fold !== '' && !index[fold]) {
                    index[fold] = { singular: v[0], plural: v[1], gender: v[2] || '' };
                }
            });
        });
        vocabNumberForms._index = index;
    }
    const hit = vocabNumberForms._index[vocabFold(word)];
    return hit || null;
}

function vocabAgreementIndex() {
    if (!vocabAgreementIndex._index) {
        const index = {};
        (_vocabData().agreement_forms || []).forEach((group, i) => {
            (group || []).forEach(form => {
                const fold = vocabFold(String(form));
                if (fold !== '') index[fold] = i;
            });
        });
        vocabAgreementIndex._index = index;
    }
    return vocabAgreementIndex._index;
}

/** The same word at another number: curated forms, agreement groups, suffix pairs. */
function vocabSameLemmaWord(a, b) {
    const fa = vocabFold(a);
    const fb = vocabFold(b);
    if (!fa || !fb || fa === fb) return fa === fb && fa !== '';
    const formsA = vocabNumberForms(fa);
    const formsB = vocabNumberForms(fb);
    if (formsA && formsB && formsA.singular === formsB.singular) return true;
    const agreements = vocabAgreementIndex();
    if (agreements[fa] !== undefined && agreements[fb] !== undefined && agreements[fa] === agreements[fb]) return true;
    const minLen = Math.min(Array.from(fa).length, Array.from(fb).length);
    if (minLen < 4) return false;
    const endsWith = (word, suffix) => {
        if (!suffix) return false;
        const w = Array.from(word);
        const s = Array.from(suffix);
        return w.length >= s.length && w.slice(w.length - s.length).join('') === s.join('');
    };
    const drop = (word, suffix) => {
        const w = Array.from(word);
        const s = Array.from(suffix);
        return w.length >= s.length ? w.slice(0, w.length - s.length).join('') : '';
    };
    for (const pair of (_vocabData().plural_pairs || [])) {
        const sa = String(pair[0] || '');
        const sb = String(pair[1] || '');
        if (sa === '' || sb === '') continue;
        if (endsWith(fa, sa) && endsWith(fb, sb) && drop(fa, sa) === drop(fb, sb)) return true;
        if (endsWith(fa, sb) && endsWith(fb, sa) && drop(fa, sb) === drop(fb, sa)) return true;
    }
    return false;
}

/** Two TITLES at another number, word by word ("Uova medie" ↔ "Uovo medio"). */
function vocabSameLemma(a, b) {
    const ta = vocabTokens(a);
    const tb = vocabTokens(b);
    if (!ta.length || ta.length !== tb.length) return false;
    return ta.every((word, i) => vocabSameLemmaWord(word, tb[i]));
}

// ── Capture lexicon (SHELFY: one sentence in, one confirmed action out) ─────
// The words the capture sheet can hear are vocabulary too: they live in the same
// api/vocab/<lang>.json ("capture"), never in app.js. The parser reads ONE merged
// lexicon — the current language first, then every other shipped language — because the
// recogniser listens in the device language, which is not necessarily the UI language,
// and people mix ("ok, aggiungi il latte").

/** One language's "capture" block, or null when that file does not define it. */
function vocabCaptureOf(lang) {
    const all = (typeof EVERSHELF_VOCAB_ALL !== 'undefined' && EVERSHELF_VOCAB_ALL) ? EVERSHELF_VOCAB_ALL : {};
    const data = all[String(lang || '')];
    const capture = data && data.capture;
    return (capture && typeof capture === 'object') ? capture : null;
}

/** One language's "locale" block (its own name, collation tag, speech tag). */
function vocabLocale(lang) {
    const all = (typeof EVERSHELF_VOCAB_ALL !== 'undefined' && EVERSHELF_VOCAB_ALL) ? EVERSHELF_VOCAB_ALL : {};
    const data = all[String(lang || '')];
    const locale = data && data.locale;
    return (locale && typeof locale === 'object') ? locale : {};
}

/**
 * The shipped languages, in the data's own order: `{ it: 'Italiano', en: 'English', … }`.
 *
 * The selector and the `?lang=` deep link read this, so adding a language is dropping
 * `api/vocab/<code>.json` with its "locale" block — the browser names it itself, and
 * that name is never written in app.js.
 */
function vocabLanguages() {
    const all = (typeof EVERSHELF_VOCAB_ALL !== 'undefined' && EVERSHELF_VOCAB_ALL) ? EVERSHELF_VOCAB_ALL : {};
    const out = {};
    Object.keys(all).forEach(code => {
        const locale = vocabLocale(code);
        out[code] = String(locale.name || code);
    });
    return out;
}

/**
 * Every capture word we ship, merged.
 *
 *   intents     { recipe: [...], throw: [...], consume: [...], add: [...] }
 *   numbers     { "due": 2 }                             spelled quantities
 *   units       { "etti": { unit: 'g', factor: 100 } }   spoken unit → canonical + multiplier
 *   fractions   [["mezza", 0.5]]                         "half an apple"
 *   all_words   ["tutti", "used up"]                     the whole row
 *   stop_words  ["il", "the", "我"]                        articles, prepositions, filler
 *
 * A collision keeps the current language: the first language that declares a key owns
 * it. A file that omits a section simply contributes nothing — the data never invents.
 */
function vocabCaptureLexicon() {
    const lang = (typeof _currentLang === 'string' && _currentLang) ? _currentLang : 'it';
    if (vocabCaptureLexicon._lang === lang && vocabCaptureLexicon._value) return vocabCaptureLexicon._value;
    const all = (typeof EVERSHELF_VOCAB_ALL !== 'undefined' && EVERSHELF_VOCAB_ALL) ? EVERSHELF_VOCAB_ALL : {};
    const order = [lang].concat(Object.keys(all).filter(code => code !== lang).sort());
    const intents = { recipe: [], throw: [], consume: [], add: [] };
    const out = { intents: intents, numbers: {}, units: {}, fractions: [], all_words: [], stop_words: [] };
    const seenFractions = new Set();
    const seenAll = new Set();
    const seenStop = new Set();
    const pushUnique = (list, seen, word) => {
        const key = String(word == null ? '' : word);
        if (key === '' || seen.has(key)) return;
        seen.add(key);
        list.push(key);
    };
    order.forEach(code => {
        const capture = vocabCaptureOf(code);
        if (!capture) return;
        const spoken = capture.intent || {};
        Object.keys(intents).forEach(intent => {
            (spoken[intent] || []).forEach(word => {
                if (typeof word === 'string' && word !== '' && intents[intent].indexOf(word) === -1) {
                    intents[intent].push(word);
                }
            });
        });
        Object.entries(capture.numbers || {}).forEach(pair => {
            if (!(pair[0] in out.numbers)) out.numbers[pair[0]] = pair[1];
        });
        Object.entries(capture.units || {}).forEach(pair => {
            const word = pair[0];
            if (word in out.units) return;
            const spec = pair[1];
            const unit = Array.isArray(spec) ? spec[0] : (spec && spec.unit);
            const factor = Array.isArray(spec) ? spec[1] : (spec && spec.factor);
            out.units[word] = { unit: String(unit || ''), factor: Number(factor) || 1 };
        });
        (capture.fractions || []).forEach(pair => {
            const word = String((pair && pair[0]) || '');
            const value = Number(pair && pair[1]);
            if (word === '' || !(value > 0) || seenFractions.has(word)) return;
            seenFractions.add(word);
            out.fractions.push([word, value]);
        });
        (capture.all_words || []).forEach(word => pushUnique(out.all_words, seenAll, word));
        (capture.stop_words || []).forEach(word => pushUnique(out.stop_words, seenStop, word));
    });
    vocabCaptureLexicon._lang = lang;
    vocabCaptureLexicon._value = out;
    return out;
}

/** The BCP-47 tag the recogniser should ask for ("" when the data has none). */
function vocabCaptureSpeechTag(lang) {
    return String(vocabLocale(lang).speech_tag || '');
}

/** The BCP-47 collation tag used by dates, numbers and the TTS voice ("" if absent). */
function vocabCollationTag(lang) {
    return String(vocabLocale(lang).collation || '');
}

