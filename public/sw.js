const cacheName = 'dag-public-v1';
const offlinePage = '/offline.html';

self.addEventListener('install', event => {
    event.waitUntil(caches.open(cacheName).then(cache => cache.add(offlinePage)).then(() => self.skipWaiting()));
});

function missionPath(path) {
    return typeof path === 'string' && /^\/(app\/missoes|guardian\/revisoes)\/[0-9a-f-]{36}$/.test(path) ? path : '/app';
}

self.addEventListener('push', event => {
    let payload;
    try {
        payload = event.data?.json();
    } catch {
        return;
    }
    if (!payload || typeof payload.title !== 'string' || typeof payload.body !== 'string') return;

    event.waitUntil(self.registration.showNotification(payload.title, {
        body: payload.body,
        icon: '/icons/dag-192.png',
        tag: payload.tag,
        data: { path: missionPath(payload.path) },
    }));
});

self.addEventListener('notificationclick', event => {
    event.notification.close();
    const url = new URL(missionPath(event.notification.data?.path), self.location.origin);

    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        const existing = windows.find(client => client.url === url.href);
        if (existing) return existing.focus();

        return self.clients.openWindow(url.href);
    })());
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
