const cacheName = 'dag-public-v1';
const offlinePage = '/offline.html';

self.addEventListener('install', event => {
    event.waitUntil(caches.open(cacheName).then(cache => cache.add(offlinePage)));
});

self.addEventListener('activate', event => {
    event.waitUntil(caches.keys()
        .then(keys => Promise.all(keys.filter(key => key.startsWith('dag-public-') && key !== cacheName).map(key => caches.delete(key))))
        .then(() => self.clients.claim()));
});

self.addEventListener('fetch', event => {
    const request = event.request;
    const url = new URL(request.url);
    if (request.method !== 'GET' || url.origin !== self.location.origin) return;

    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(async () => await caches.match(offlinePage) ?? Response.error()));
        return;
    }

    if (!/^\/build\/assets\/[\w.-]+$/.test(url.pathname) || url.search) return;
    event.respondWith((async () => {
        const cache = await caches.open(cacheName);
        const stored = await cache.match(request);
        if (stored) return stored;

        const response = await fetch(request);
        const type = response.headers.get('Content-Type') ?? '';
        if (response.ok && !response.redirected && /^(text\/css|(?:application|text)\/javascript|image\/|font\/)/i.test(type)
            && !/private|no-store/i.test(response.headers.get('Cache-Control') ?? '')) {
            await cache.put(request, response.clone());
        }

        return response;
    })());
});
