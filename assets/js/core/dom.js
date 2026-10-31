/**
 * EverShelf core — safe HTML escaping (loaded before app.js).
 */

/**
 * Turn stored HTML entities back into real characters before display/escape.
 * Catalog titles sometimes arrive as `Marmellata di &quot;Limone…&quot;` — if we
 * escape that string as-is the household sees the literal `&quot;`.
 */
function decodeHtmlEntities(str) {
    if (str == null) return '';
    let s = String(str);
    if (!s.includes('&')) return s;
    const ta = document.createElement('textarea');
    // A catalogue hands the title escaped once, an old import escaped it again
    // ("Marmellata di &amp;quot;Limone…&amp;quot;"): one pass leaves a visible &quot;,
    // so decode until the string stops changing. Bounded — never a loop on a
    // pathological value.
    for (let i = 0; i < 4 && s.includes('&'); i++) {
        ta.innerHTML = s;
        const next = ta.value;
        if (next === s) break;
        s = next;
    }
    return s;
}

function escapeHtml(str) {
    if (str == null) return '';
    const div = document.createElement('div');
    // Decode first so a title that already carries &quot; is shown with real quotes.
    div.textContent = decodeHtmlEntities(String(str));
    return div.innerHTML;
}

/**
 * Escape a value for use inside an HTML attribute (quoted with " or ').
 * escapeHtml() only covers & < > — this additionally covers both quote
 * characters, so it is safe in attribute and inline-handler contexts.
 */
function escapeAttr(str) {
    return escapeHtml(str).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

window.decodeHtmlEntities = decodeHtmlEntities;
window.escapeHtml = escapeHtml;
window.escapeAttr = escapeAttr;
