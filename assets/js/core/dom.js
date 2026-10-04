/**
 * EverShelf core — safe HTML escaping (loaded before app.js).
 */
function escapeHtml(str) {
    if (str == null) return '';
    const div = document.createElement('div');
    div.textContent = String(str);
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

window.escapeHtml = escapeHtml;
window.escapeAttr = escapeAttr;
