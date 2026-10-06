self.addEventListener('install', (event) => {
  event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', (event) => {
  const requestUrl = new URL(event.request.url);
  if (event.request.mode === 'navigate' && event.request.method === 'GET' && requestUrl.origin === self.location.origin) {
    event.respondWith(fetch(event.request));
  }
});
