/* EverShelf PWA service worker — caches app shell for offline read */
const CACHE = 'evershelf-v3';
const BASE = (() => {
    const p = self.location.pathname || '/';
    return p.endsWith('sw.js') ? p.slice(0, -'sw.js'.length) : '/';
})();

const SHELL = [
    BASE,
    BASE + 'index.html',
    BASE + 'manifest.json',
    BASE + 'assets/css/style.css',
    BASE + 'assets/js/app.js',
    BASE + 'assets/js/core/auth.js',
    BASE + 'assets/js/core/dom.js',
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

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    if (url.pathname.includes('/api/')) return;
    if (event.request.method !== 'GET') return;
    event.respondWith(
        caches.match(event.request).then((cached) =>
            cached ||
            fetch(event.request).then((res) => {
                if (res.ok && url.origin === self.location.origin) {
                    const clone = res.clone();
                    caches.open(CACHE).then((c) => c.put(event.request, clone));
                }
                return res;
            }).catch(() => cached)
        )
    );
});
