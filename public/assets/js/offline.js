/* Offline básico — Fase 1
 * - Registra o service worker (cache do app e assets)
 * - Fila de visitas criadas sem conexão (IndexedDB) com sincronização automática
 */

'use strict';

const Offline = {
  DB_NOME: 'crm_coperdia',
  DB_VERSAO: 1,

  abrirBanco() {
    return new Promise((resolver, rejeitar) => {
      const req = indexedDB.open(Offline.DB_NOME, Offline.DB_VERSAO);
      req.onupgradeneeded = () => {
        const db = req.result;
        if (!db.objectStoreNames.contains('fila_visitas')) {
          db.createObjectStore('fila_visitas', { keyPath: 'id', autoIncrement: true });
        }
      };
      req.onsuccess = () => resolver(req.result);
      req.onerror = () => rejeitar(req.error);
    });
  },

  /** Guarda uma visita (FormData) na fila local para sincronizar depois. */
  async guardarVisita(form) {
    const fd = new FormData(form);
    const registro = { campos: {}, fotos: [], criado_em: new Date().toISOString() };

    for (const [chave, valor] of fd.entries()) {
      if (valor instanceof File) {
        if (valor.size > 0) {
          registro.fotos.push({ nome: valor.name, tipo: valor.type, blob: valor });
        }
      } else {
        registro.campos[chave] = valor;
      }
    }
    registro.campos['offline'] = '1';

    const db = await Offline.abrirBanco();
    await new Promise((resolver, rejeitar) => {
      const tx = db.transaction('fila_visitas', 'readwrite');
      tx.objectStore('fila_visitas').add(registro);
      tx.oncomplete = resolver;
      tx.onerror = () => rejeitar(tx.error);
    });
  },

  /** Envia as visitas pendentes quando a conexão volta. */
  async sincronizar() {
    if (!navigator.onLine) return;
    const db = await Offline.abrirBanco();
    const pendentes = await new Promise(resolver => {
      const tx = db.transaction('fila_visitas', 'readonly');
      const req = tx.objectStore('fila_visitas').getAll();
      req.onsuccess = () => resolver(req.result || []);
      req.onerror = () => resolver([]);
    });
    if (!pendentes.length) return;

    const badge = document.getElementById('indicadorSync');
    if (badge) badge.classList.remove('d-none');

    let enviadas = 0;
    for (const registro of pendentes) {
      const fd = new FormData();
      for (const [chave, valor] of Object.entries(registro.campos)) fd.append(chave, valor);
      for (const foto of registro.fotos) fd.append('fotos[]', foto.blob, foto.nome);

      try {
        const resp = await fetch('index.php?r=visitas/salvar', {
          method: 'POST',
          body: fd,
          headers: { 'X-Requested-With': 'fetch' },
        });
        const dados = await resp.json();
        if (dados.ok) {
          await new Promise(resolver => {
            const tx = db.transaction('fila_visitas', 'readwrite');
            tx.objectStore('fila_visitas').delete(registro.id);
            tx.oncomplete = resolver;
          });
          enviadas++;
        }
      } catch (e) {
        break; // conexão caiu de novo — tenta na próxima
      }
    }

    if (badge) badge.classList.add('d-none');
    if (enviadas > 0 && window.App) {
      App.alerta(`${enviadas} visita(s) registrada(s) offline foram sincronizadas.`, 'success');
    }
  },
};

// Service worker (PWA + cache offline)
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('sw.js').catch(() => {});
  });
}

// Tenta sincronizar ao abrir a página
document.addEventListener('DOMContentLoaded', () => {
  setTimeout(() => Offline.sincronizar(), 1500);
});
