/* Service Worker — CRM Agropecuário Copérdia
 * Cache do app e assets para abrir sem conexão (offline básico da Fase 1).
 */

const CACHE = 'crm-coperdia-v33';

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
  'assets/js/despesas.js',
  'assets/js/reclamacoes.js',
  'assets/js/agenda.js',
  'assets/js/pedidos.js',
  'assets/js/pacotes.js',
  'assets/js/custo-motor.js',
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

// ---- Background Sync: replay da fila pelo próprio SW (funciona com o app fechado) ----
// O SW lê a fila_sync do IndexedDB e reenvia cada item à sua rota. O servidor é
// idempotente (uuid + transação), então mesmo que um cliente aberto também
// sincronize, não há duplicata. Depois avisa os clientes para atualizarem a UI.
function abrirBancoSW() {
  return new Promise((res, rej) => {
    const req = indexedDB.open('crm_coperdia'); // sem versão: usa a atual (criada pela página)
    req.onsuccess = () => res(req.result);
    req.onerror = () => rej(req.error);
  });
}
function getAllSW(db) {
  return new Promise(res => {
    const req = db.transaction('fila_sync', 'readonly').objectStore('fila_sync').getAll();
    req.onsuccess = () => res(req.result || []);
    req.onerror = () => res([]);
  });
}
function removerSW(db, id) {
  return new Promise(res => {
    const tx = db.transaction('fila_sync', 'readwrite');
    tx.objectStore('fila_sync').delete(id);
    tx.oncomplete = res; tx.onerror = res;
  });
}
async function sincronizarFilaSW() {
  let db;
  try { db = await abrirBancoSW(); } catch (e) { return; }
  if (!db.objectStoreNames.contains('fila_sync')) return;
  for (const reg of await getAllSW(db)) {
    if (reg.erro) continue;
    const fd = new FormData();
    for (const k in reg.campos) fd.append(k, reg.campos[k]);
    for (const a of (reg.arquivos || [])) fd.append(a.campo, a.blob, a.nome);
    let resp;
    try {
      resp = await fetch('index.php?r=' + reg.rota, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' } });
    } catch (e) { break; } // conexão caiu de novo
    if ((resp.redirected && /r=login/.test(resp.url)) || resp.status === 401 || resp.status === 403) break; // sessão expirada
    let dados = null;
    try { dados = await resp.json(); } catch (e) { /* ignore */ }
    if (dados && dados.ok) await removerSW(db, reg.id);
  }
}
// ---- Web Push: o push chega SEM payload; buscamos a última notificação para exibir ----
self.addEventListener('push', ev => {
  ev.waitUntil((async () => {
    let titulo = 'CRM AGRO — Copérdia';
    let corpo = 'Você tem novidades.';
    let link = 'index.php?r=dashboard';
    try {
      const r = await fetch('index.php?r=notificacoes/ultima', { headers: { 'X-Requested-With': 'fetch' } });
      const d = await r.json();
      if (d.ok && d.notificacao) {
        titulo = d.notificacao.titulo || titulo;
        corpo = d.notificacao.texto || '';
        if (d.notificacao.link && /^index\.php/.test(d.notificacao.link)) link = d.notificacao.link;
      }
    } catch (e) { /* offline/sessão: usa o texto genérico */ }
    await self.registration.showNotification(titulo, {
      body: corpo,
      icon: 'assets/icons/icone-192.png',
      badge: 'assets/icons/icone-192.png',
      data: { link },
      tag: 'crm-coperdia', // agrupa (a mais nova substitui)
    });
  })());
});

self.addEventListener('notificationclick', ev => {
  ev.notification.close();
  const link = (ev.notification.data && ev.notification.data.link) || 'index.php?r=dashboard';
  ev.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(cs => {
    for (const c of cs) {
      if ('focus' in c) { c.navigate(link); return c.focus(); }
    }
    return self.clients.openWindow(link);
  }));
});

self.addEventListener('sync', ev => {
  if (ev.tag === 'sync-fila') {
    ev.waitUntil((async () => {
      await sincronizarFilaSW();
      const cs = await self.clients.matchAll({ includeUncontrolled: true });
      cs.forEach(c => c.postMessage('sincronizar-fila'));
    })());
  }
});

self.addEventListener('fetch', ev => {
  const url = new URL(ev.request.url);

  // Só tratamos GET do próprio domínio
  if (ev.request.method !== 'GET' || url.origin !== location.origin) return;

  // Assets: cache primeiro, guardando em runtime o que baixar (inclusive os
  // versionados por ?v=<filemtime>) — assim resolvem offline na próxima vez, e o
  // cache-busting continua valendo (URL nova = chave nova = busca fresca).
  if (url.pathname.includes('/assets/') || url.pathname.endsWith('manifest.json')) {
    ev.respondWith(
      caches.match(ev.request).then(resp => resp || fetch(ev.request).then(net => {
        if (net && net.ok) {
          const copia = net.clone();
          caches.open(CACHE).then(async c => {
            // Poda versões antigas do MESMO asset (?v=<filemtime> anterior) —
            // sem isso cada deploy deixava uma cópia morta no cache para sempre.
            if (url.searchParams.has('v')) {
              const chaves = await c.keys();
              await Promise.all(chaves.filter(req => {
                const u = new URL(req.url);
                return u.pathname === url.pathname && u.search !== url.search;
              }).map(req => c.delete(req)));
            }
            c.put(ev.request, copia);
          });
        }
        return net;
      }))
    );
    return;
  }

  // Páginas: rede primeiro, cache como fallback (última versão vista).
  // Só cacheia HTML — respostas JSON (APIs autenticadas, ex.: sync/carteira) NUNCA
  // vão para o CacheStorage, para não deixar dados de carteira em repouso.
  ev.respondWith(
    fetch(ev.request)
      .then(resp => {
        const ct = resp.headers.get('Content-Type') || '';
        // Relatório de safra fica FORA do cache: uma variante por cliente/safra
        // acumularia dados de produtores em repouso no aparelho
        const semCache = url.search.includes('r=relatorios%2Fsafra') || url.search.includes('r=relatorios/safra');
        if (resp.ok && ct.includes('text/html') && !semCache) {
          const copia = resp.clone();
          caches.open(CACHE).then(cache => cache.put(ev.request, copia));
        }
        return resp;
      })
      .catch(() => caches.match(ev.request))
  );
});
