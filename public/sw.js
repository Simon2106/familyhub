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

const CACHE = 'familyhub-static-v4';

const SHELL = [
    '/offline.html',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
    // The notes board's handwriting. Cached on install rather than on first
    // use, because the morning the internet is down is exactly the morning
    // nobody wants the wall falling back to a different face.
    '/fonts/caveat-latin.woff2',
    '/fonts/caveat-latin-ext.woff2',
];

/** Only these are ever cached. Everything else goes to the network. */
function isCacheable(url) {
    return (
        url.pathname.startsWith('/build/') ||
        url.pathname.startsWith('/icons/') ||
        // Fonts are versioned by filename and never change under one, so
        // cache-first is safe and means no network on a second boot.
        url.pathname.startsWith('/fonts/')
    );
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

/* -------------------------------------------------------------------------
 * Web Push
 *
 * The only reason this worker needs to exist while the app is closed. Every
 * payload is a small JSON object the server built; the worker does no
 * thinking of its own, because a notification that renders differently from
 * what the server intended is a notification nobody can debug.
 * ---------------------------------------------------------------------- */

self.addEventListener('push', (event) => {
    let notice = {};

    try {
        notice = event.data ? event.data.json() : {};
    } catch {
        // A push with no readable payload is still worth showing: something
        // happened, and silence would be worse than a bare title.
    }

    event.waitUntil(
        self.registration.showNotification(notice.title || 'FamilyHub', {
            body: notice.body || '',
            icon: '/icons/icon-192.png',
            badge: '/icons/icon-192.png',
            // Tagged by trigger, so a second reminder replaces the first
            // rather than stacking three of the same thing.
            tag: notice.tag || 'familyhub',
            renotify: false,
            data: { url: notice.url || '/app' },
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const target = event.notification.data?.url || '/app';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
            // Reuse a tab that is already open rather than opening a fourth
            // copy of the app on somebody's phone.
            for (const client of clients) {
                if (client.url.includes(new URL(target, self.location.origin).pathname)) {
                    return client.focus();
                }
            }

            return self.clients.openWindow(target);
        }),
    );
});
