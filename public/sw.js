/**
 * FamilyHub service worker — offline shell only.
 *
 * Deliberately minimal: it caches the static shell so a cold start on a dropped
 * Wi-Fi connection shows the app frame instead of Safari's error page. It never
 * caches HTML or API responses, because a wall calendar showing yesterday's
 * agenda is worse than one showing an honest "offline" message.
 */

const CACHE = 'familyhub-shell-v1';

const SHELL = ['/offline.html', '/icons/icon-192.png', '/icons/icon-512.png'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(CACHE)
            .then((cache) => cache.addAll(SHELL))
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

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') return;

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) return;

    // Never intercept Livewire's own traffic — a stale component update would
    // desync the page in ways that are very hard to debug. Livewire 4 serves
    // this from a hashed prefix (/livewire-<hash>/update), not a literal
    // /livewire/, so match the pattern rather than the plain string.
    if (/^\/livewire[^/]*\//.test(url.pathname)) return;

    // Navigations: always go to the network, fall back to the offline card.
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match('/offline.html')));
        return;
    }

    // Build assets are content-hashed, so cache-first is safe and instant.
    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/icons/')) {
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
    }
});
