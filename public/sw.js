/* Service Worker — CRM Agropecuário Copérdia
 * Cache do app e assets para abrir sem conexão (offline básico da Fase 1).
 */

const CACHE = 'crm-coperdia-v6';

const ARQUIVOS_APP = [
  'assets/vendor/bootstrap.min.css',
  'assets/vendor/bootstrap-icons.min.css',
  'assets/vendor/bootstrap.bundle.min.js',
  'assets/vendor/chart.umd.min.js',
  'assets/vendor/fonts/bootstrap-icons.woff2',
  'assets/vendor/fonts/bootstrap-icons.woff',
  'assets/css/app.css',
  'assets/js/app.js',
  'assets/js/offline.js',
  'assets/icons/icone-192.png',
  'assets/icons/icone-512.png',
  'assets/icons/favicon-32.png',
  'assets/icons/favicon-48.png',
  'assets/img/logo-marca.svg',
  'assets/img/logo-coperdia.svg',
  'assets/img/logo-coperdia-clara.svg',
  'manifest.json',
];

self.addEventListener('install', ev => {
  ev.waitUntil(
    caches.open(CACHE).then(cache => cache.addAll(ARQUIVOS_APP)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', ev => {
  ev.waitUntil(
    caches.keys().then(chaves =>
      Promise.all(chaves.filter(c => c !== CACHE).map(c => caches.delete(c)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', ev => {
  const url = new URL(ev.request.url);

  // Só tratamos GET do próprio domínio
  if (ev.request.method !== 'GET' || url.origin !== location.origin) return;

  // Assets: cache primeiro (são versionados pelo nome do cache)
  if (url.pathname.includes('/assets/') || url.pathname.endsWith('manifest.json')) {
    ev.respondWith(
      caches.match(ev.request).then(resp => resp || fetch(ev.request))
    );
    return;
  }

  // Páginas: rede primeiro, cache como fallback (última versão vista)
  ev.respondWith(
    fetch(ev.request)
      .then(resp => {
        const copia = resp.clone();
        caches.open(CACHE).then(cache => cache.put(ev.request, copia));
        return resp;
      })
      .catch(() => caches.match(ev.request))
  );
});
