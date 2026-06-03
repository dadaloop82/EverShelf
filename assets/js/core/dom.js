/**
 * EverShelf core — safe HTML escaping (loaded before app.js).
 */
function escapeHtml(str) {
    if (str == null) return '';
    const div = document.createElement('div');
    div.textContent = String(str);
    return div.innerHTML;
}

window.escapeHtml = escapeHtml;
