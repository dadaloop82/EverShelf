/* EverShelf PWA service worker — offline fallback for the app shell.
 *
 * Strategy: NETWORK-FIRST, cache only as an offline fallback.
 * `.htaccess` sends `Cache-Control: no-cache` for JS/CSS precisely so a kiosk
 * always gets fresh files; a cache-first worker silently defeated that and could
 * pin a stale app.js indefinitely. The cache below is only a safety net when the
 * server cannot be reached.
 */
const CACHE = 'evershelf-v1.11.0';
const BASE = (() => {
    const p = self.location.pathname || '/';
    return p.endsWith('sw.js') ? p.slice(0, -'sw.js'.length) : '/';
})();

const SHELL = [
    BASE,
    BASE + 'index.html',
    BASE + 'manifest.json',
    BASE + 'assets/css/style.css',
    BASE + 'assets/css/corporate.css',
    BASE + 'assets/css/elegant.css',
    BASE + 'assets/js/app.js',
    BASE + 'assets/js/core/auth.js',
    BASE + 'assets/js/core/dom.js',
    // Barcode decoding must work offline too.
    BASE + 'assets/vendor/quagga/quagga.min.js',
    BASE + 'assets/vendor/zbar/index.js',
    BASE + 'assets/vendor/zbar/polyfill.js',
    BASE + 'assets/vendor/zbar/zbar.wasm',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE).then((cache) => cache.addAll(SHELL).catch(() => {}))
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))
        )
    );
    self.clients.claim();
});

async function networkFirst(request) {
    try {
        const res = await fetch(request);
        // Never cache opaque/partial/error responses.
        if (res && res.ok && res.type === 'basic') {
            const clone = res.clone();
            caches.open(CACHE).then((c) => c.put(request, clone)).catch(() => {});
        }
        return res;
    } catch (_) {
        const cached = await caches.match(request);
        if (cached) return cached;
        // Last resort: offline app shell for navigation requests.
        if (request.mode === 'navigate') {
            const shell = await caches.match(BASE + 'index.html');
            if (shell) return shell;
        }
        throw _;
    }
}

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    if (url.origin !== self.location.origin) return;
    if (url.pathname.includes('/api/')) return;
    if (event.request.method !== 'GET') return;
    event.respondWith(networkFirst(event.request));
});
