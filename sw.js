/* EverShelf PWA service worker — offline fallback for the app shell.
 *
 * Strategy: NETWORK-FIRST, cache only as an offline fallback.
 * `.htaccess` sends `Cache-Control: no-cache` for JS/CSS precisely so a kiosk
 * always gets fresh files; a cache-first worker silently defeated that and could
 * pin a stale app.js indefinitely. The cache below is only a safety net when the
 * server cannot be reached.
 */
const CACHE = 'evershelf-v2.0.0a';
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
    // The GitHub mark on the splash: it says who makes the app and where its source lives,
    // and that line has to survive an offline boot like the rest of the splash.
    BASE + 'assets/img/icons/github.svg',
    // The two badges of the same line: the licence (SPDX `MIT`) and "no ads".
    BASE + 'assets/img/icons/license.svg',
    BASE + 'assets/img/icons/no-ads.svg',
    // SHELFY's face: the centre of the bottom nav, its sheet and its 10 s confirmation card
    // all paint it, and the assistant must still have a face offline.
    BASE + 'assets/img/shelfy/shelfy-avatar-96.png',
    BASE + 'assets/img/shelfy/shelfy-avatar-192.png',
    BASE + 'assets/css/style.css',
    BASE + 'assets/css/corporate.css',
    BASE + 'assets/css/elegant.css',
    BASE + 'assets/css/surfaces.css',
    BASE + 'assets/css/tablet.css',
    BASE + 'assets/css/mobile.css',
    BASE + 'assets/js/app.js',
    BASE + 'assets/js/audit.js',
    BASE + 'assets/js/receipt.js',
    BASE + 'assets/js/settings.schema.generated.js',
    BASE + 'assets/js/settings_search.js',
    BASE + 'assets/js/screensaver.js',
    BASE + 'assets/js/vocab.generated.js',
    BASE + 'assets/js/food_facts.generated.js',
    BASE + 'assets/js/free_apis.generated.js',
    BASE + 'assets/js/storage_actions.generated.js',
    BASE + 'assets/js/off_categories.generated.js',
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
        // `cache: 'reload'` on purpose: the shell is fetched from the NETWORK, never
        // revalidated. `.htaccess` keeps JS/CSS/index on `no-cache`, so a conditional
        // request answers `304 Not Modified` — which `cache.addAll()` cannot store (it accepts
        // 200s only), and the offline shell would silently come out half empty after a
        // release, exactly when somebody is offline and needs it.
        caches.open(CACHE).then((cache) => cache.addAll(
            SHELL.map((url) => new Request(url, { cache: 'reload' }))
        ).catch(() => {}))
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            // `IMG_CACHE` survives a shell update: it holds product photos, not code.
            Promise.all(keys.filter((k) => k !== CACHE && k !== IMG_CACHE).map((k) => caches.delete(k)))
        )
    );
    self.clients.claim();
});

/* ── Product photos: their own runtime cache ──────────────────────────────────
 * `products.image_url` is a REMOTE Open Food Facts URL, and the fetch handler below
 * deliberately ignores other origins — so every row asked the network again, and offline
 * the photo simply never arrived. A package picture is immutable content: cache-first,
 * in its own cache (`IMG_CACHE`), pruned by count so a pantry of 2 000 rows cannot fill
 * the disk. The image host sends no CORS headers, so the response is opaque (status 0):
 * `cache.put()` still stores it and an `<img>` renders it fine.
 */
const IMG_CACHE = 'evershelf-images-v1';
// IMAGE hosts only, never the API host: cache-first on `world.openfoodfacts.org` would
// serve a stale search for ever.
const IMG_HOST_RE = /^(images|static)\.(openfoodfacts|openbeautyfacts|openproductsfacts|openpetfoodfacts)\.org$/;
const IMG_MAX = 400;

async function pruneImageCache() {
    const cache = await caches.open(IMG_CACHE);
    const keys = await cache.keys();
    if (keys.length <= IMG_MAX) return;
    // `keys()` is in insertion order: the oldest leaves first.
    await Promise.all(keys.slice(0, keys.length - IMG_MAX).map((k) => cache.delete(k)));
}

async function imageCacheFirst(request) {
    const cache = await caches.open(IMG_CACHE);
    const hit = await cache.match(request, { ignoreVary: true });
    if (hit) return hit;
    const res = await fetch(request);
    if (res && (res.ok || res.type === 'opaque')) {
        const clone = res.clone();
        cache.put(request, clone).then(pruneImageCache).catch(() => {});
    }
    return res;
}

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
    // Product photos first: they are remote and immutable, and the branch below would
    // ignore them (another origin). Cached here, a row paints instantly the second time
    // and still paints with the network down.
    if (event.request.method === 'GET' && IMG_HOST_RE.test(url.hostname)) {
        event.respondWith(imageCacheFirst(event.request));
        return;
    }
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
