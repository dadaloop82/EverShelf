/* EverShelf PWA service worker — offline fallback for the app shell.
 *
 * Strategy: NETWORK-FIRST, cache only as an offline fallback.
 * `.htaccess` sends `Cache-Control: no-cache` for JS/CSS precisely so a kiosk
 * always gets fresh files; a cache-first worker silently defeated that and could
 * pin a stale app.js indefinitely. The cache below is only a safety net when the
 * server cannot be reached.
 */
const CACHE = 'evershelf-v1.29.4s';
const BASE = (() => {
    const p = self.location.pathname || '/';
    return p.endsWith('sw.js') ? p.slice(0, -'sw.js'.length) : '/';
})();

const SHELL = [
    BASE,
    BASE + 'index.html',
    BASE + 'manifest.json',
    // The credits of the free services: the splash paints them before any fetch, so they
    // must be in the shell (and they are tiny).
    BASE + 'assets/img/apis/open-food-facts.svg',
    BASE + 'assets/img/apis/open-prices.png',
    BASE + 'assets/img/apis/open-meteo.png',
    BASE + 'assets/img/apis/open-products-facts.svg',
    BASE + 'assets/img/apis/open-beauty-facts.svg',
    BASE + 'assets/img/apis/open-pet-food-facts.svg',
    // The medicine mark (EverShelf Med): the row thumbnails, the cabinet pill and the pantry
    // tab draw it, so a medicine never reads as a broken image when the server is unreachable.
    BASE + 'assets/img/icons/medicine.svg',
    BASE + 'assets/css/style.css',
    BASE + 'assets/css/corporate.css',
    BASE + 'assets/css/elegant.css',
    BASE + 'assets/css/surfaces.css',
    BASE + 'assets/css/mobile.css',
    BASE + 'assets/js/app.js',
    BASE + 'assets/js/audit.js',
    BASE + 'assets/js/receipt.js',
    BASE + 'assets/js/settings.schema.generated.js',
    BASE + 'assets/js/settings_search.js',
    BASE + 'assets/js/vocab.generated.js',
    BASE + 'assets/js/food_facts.generated.js',
    BASE + 'assets/js/free_apis.generated.js',
    BASE + 'assets/js/core/vocab.js',
    BASE + 'assets/js/core/auth.js',
    BASE + 'assets/js/core/dom.js',
    BASE + 'assets/js/core/support.js',
    // The medicine scanner: a country-neutral core + one module per country (Italy prints its
    // AIC, so its rules live in assets/js/med/italy.js, never in the core).
    BASE + 'assets/js/core/med.js',
    BASE + 'assets/js/med/italy.js',
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

/* Empty-payload Web Push: wake, pull inbox, show system notification. */
self.addEventListener('push', (event) => {
    event.waitUntil((async () => {
        let items = [];
        try {
            const res = await fetch(BASE + 'api/index.php?action=webpush_inbox', {
                credentials: 'same-origin',
                headers: { 'X-EverShelf-Request': '1' },
                cache: 'no-store',
            });
            if (res.ok) {
                const data = await res.json();
                items = Array.isArray(data.items) ? data.items : [];
            }
        } catch (_) { /* offline */ }
        if (!items.length) {
            items = [{ title: 'EverShelf', body: '', url: BASE }];
        }
        await Promise.all(items.map((it) =>
            self.registration.showNotification(String(it.title || 'EverShelf'), {
                body: String(it.body || ''),
                icon: BASE + 'assets/img/logo/logo_icon.png',
                badge: BASE + 'assets/img/logo/logo_icon.png',
                data: { url: it.url || BASE },
                tag: 'evershelf-' + String(it.ts || Date.now()),
            })
        ));
    })());
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = (event.notification.data && event.notification.data.url) || BASE;
    event.waitUntil((async () => {
        const all = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const client of all) {
            if ('focus' in client) {
                await client.focus();
                if (client.navigate) {
                    try { await client.navigate(target); } catch (_) {}
                }
                return;
            }
        }
        if (self.clients.openWindow) {
            await self.clients.openWindow(target);
        }
    })());
});
