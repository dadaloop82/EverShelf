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
    const s = String(str);
    if (!s.includes('&')) return s;
    const ta = document.createElement('textarea');
    ta.innerHTML = s;
    return ta.value;
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
