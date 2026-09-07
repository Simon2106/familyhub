/**
 * FamilyHub service worker — static assets only.
 *
 * Deliberately narrow. It caches the offline card and content-hashed build
 * assets, and nothing else. HTML and Livewire traffic are never cached: a wall
 * calendar showing yesterday's agenda from a stale cache is worse than one
 * showing an honest "offline" message, and a cached Livewire response would
 * desync the page in ways that are very hard to debug.
 *
 * The version in CACHE is bumped by deploys through the asset URLs it holds;
 * activate() drops every other cache, so a new worker never inherits stale
 * entries.
 */

const CACHE = 'familyhub-static-v2';

const SHELL = ['/offline.html', '/icons/icon-192.png', '/icons/icon-512.png'];

/** Only these are ever cached. Everything else goes to the network. */
function isCacheable(url) {
    return url.pathname.startsWith('/build/') || url.pathname.startsWith('/icons/');
}

/** Livewire 4 serves from a hashed prefix (/livewire-<hash>/update). */
function isLivewire(url) {
    return /^\/livewire[^/]*\//.test(url.pathname);
}

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(CACHE)
            .then((cache) => cache.addAll(SHELL))
            // Take over immediately: a wall display has no second tab to close,
            // so waiting for one would leave the old worker in charge forever.
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim()),
    );
});

// A deploy can ask the current worker to step aside without waiting.
self.addEventListener('message', (event) => {
    if (event.data === 'skip-waiting') self.skipWaiting();
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') return;

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) return;
    if (isLivewire(url)) return;

    // Navigations always hit the network, so a deploy is picked up on reload.
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match('/offline.html')));

        return;
    }

    if (!isCacheable(url)) return;

    // Build assets are content-hashed, so cache-first is safe and instant.
    event.respondWith(
        caches.match(request).then(
            (hit) =>
                hit ||
                fetch(request).then((response) => {
                    if (response.ok) {
                        const copy = response.clone();
                        caches.open(CACHE).then((cache) => cache.put(request, copy));
                    }

                    return response;
                }),
        ),
    );
});
