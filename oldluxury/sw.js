/* sw.js — Service Worker de Luxury Store
 * Estrategia:
 *  - Navegaciones: network-first, con offline.html de respaldo y cacheo del HTML válido visto.
 *  - Assets locales (Tailwind, Chart.js, FontAwesome, Inter, iconos): cache-first.
 *  - Todo lo externo (nunca debería haber, todo es local): network, sin cachear.
 */
const CACHE = 'luxury-v1';
const PRECACHE = [
  './offline.html',
  './assets/img/icons/icon-192.png',
  './assets/img/icons/icon-512.png',
  './assets/img/logo.png',
  './assets/img/favicon.ico'
];

self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(CACHE).then((c) => c.addAll(PRECACHE)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

const isAsset = (url) =>
  /\/assets\/vendor\//.test(url) ||
  /\/assets\/img\//.test(url) ||
  /\/assets\/fonts\//.test(url) ||
  /\.(css|js|woff2?|png|jpe?g|gif|svg|ico|webmanifest|json)$/.test(url.split('?')[0]);

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);

  // Solo controlamos nuestro propio origen.
  if (url.origin !== self.location.origin) return;

  // Navegaciones (páginas PHP): red primero; si falla, cache u offline.
  if (req.mode === 'navigate') {
    e.respondWith(
      fetch(req)
        .then((res) => {
          if (res && res.ok && url.pathname.includes('/dashboard')) {
            const copy = res.clone();
            caches.open(CACHE).then((c) => c.put(req, copy));
          }
          return res;
        })
        .catch(() =>
          caches.match(req).then((hit) => hit || caches.match('./offline.html'))
        )
    );
    return;
  }

  // Assets: cache-first (son versionados por carpeta vendor; estáticos).
  if (isAsset(url.pathname)) {
    e.respondWith(
      caches.match(req).then(
        (hit) =>
          hit ||
          fetch(req).then((res) => {
            if (res && res.ok) {
              const copy = res.clone();
              caches.open(CACHE).then((c) => c.put(req, copy));
            }
            return res;
          })
      )
    );
  }
  // Resto (fetches de datos, uploads): red directa, sin interferir.
});
