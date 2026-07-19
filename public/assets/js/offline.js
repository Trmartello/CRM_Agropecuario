/* Offline ampliado — Fase O1
 * - Registra o service worker (cache do app e assets)
 * - Fila GENÉRICA de escritas feitas sem conexão (IndexedDB) com sincronização
 *   automática: cada item guarda a rota de destino, os campos e os anexos (Blobs),
 *   e é reenviado ao servidor quando a internet volta. O servidor recalcula tudo.
 *
 * Módulos elegíveis hoje: visitas, despesas (KM/refeição), agenda (evento/status),
 * reclamações. Pedidos/pacotes/funil ficam online (exigem estoque/crédito ao vivo).
 */

'use strict';

const Offline = {
  DB_NOME: 'crm_coperdia',
  DB_VERSAO: 2,
  STORE: 'fila_sync',

  abrirBanco() {
    return new Promise((resolver, rejeitar) => {
      const req = indexedDB.open(Offline.DB_NOME, Offline.DB_VERSAO);
      req.onupgradeneeded = (ev) => {
        const db = req.result;
        if (!db.objectStoreNames.contains('fila_sync')) {
          db.createObjectStore('fila_sync', { keyPath: 'id', autoIncrement: true });
        }
        // Migração v1→v2: leva os itens antigos de fila_visitas para a fila genérica
        if (ev.oldVersion < 2 && db.objectStoreNames.contains('fila_visitas')) {
          const tx = req.transaction;
          const antigo = tx.objectStore('fila_visitas');
          const destino = tx.objectStore('fila_sync');
          antigo.getAll().onsuccess = (e) => {
            for (const r of (e.target.result || [])) {
              destino.add({
                rota: 'visitas/salvar', modulo: 'visitas', rotulo: 'Visita técnica',
                campos: r.campos || {},
                arquivos: (r.fotos || []).map(f => ({ campo: 'fotos[]', nome: f.nome, tipo: f.tipo, blob: f.blob })),
                criado_em: r.criado_em || new Date().toISOString(),
              });
            }
            antigo.clear();
          };
        }
      };
      req.onsuccess = () => resolver(req.result);
      req.onerror = () => rejeitar(req.error);
    });
  },

  /**
   * Enfileira uma escrita para envio posterior.
   * @param {string} rota  ex.: 'despesas/salvar-km'
   * @param {HTMLFormElement|FormData} origem  formulário ou FormData
   * @param {{modulo?:string, rotulo?:string}} opc
   */
  async enfileirar(rota, origem, opc = {}) {
    const fd = origem instanceof FormData ? origem : new FormData(origem);
    const registro = {
      rota, modulo: opc.modulo || '', rotulo: opc.rotulo || rota,
      campos: {}, arquivos: [], criado_em: new Date().toISOString(),
    };
    for (const [chave, valor] of fd.entries()) {
      if (valor instanceof File) {
        if (valor.size > 0) registro.arquivos.push({ campo: chave, nome: valor.name, tipo: valor.type, blob: valor });
      } else {
        registro.campos[chave] = valor;
      }
    }
    registro.campos['offline'] = '1';

    const db = await Offline.abrirBanco();
    await new Promise((resolver, rejeitar) => {
      const tx = db.transaction(Offline.STORE, 'readwrite');
      tx.objectStore(Offline.STORE).add(registro);
      tx.oncomplete = resolver;
      tx.onerror = () => rejeitar(tx.error);
    });
    Offline.notificarMudanca();
  },

  /** Lista os itens da fila (para o painel de pendências). */
  async listar() {
    const db = await Offline.abrirBanco();
    return new Promise(resolver => {
      const tx = db.transaction(Offline.STORE, 'readonly');
      const req = tx.objectStore(Offline.STORE).getAll();
      req.onsuccess = () => resolver(req.result || []);
      req.onerror = () => resolver([]);
    });
  },

  async contar() {
    return (await Offline.listar()).length;
  },

  async remover(id) {
    const db = await Offline.abrirBanco();
    await new Promise(resolver => {
      const tx = db.transaction(Offline.STORE, 'readwrite');
      tx.objectStore(Offline.STORE).delete(id);
      tx.oncomplete = resolver;
    });
    Offline.notificarMudanca();
  },

  async _atualizar(registro) {
    const db = await Offline.abrirBanco();
    await new Promise(resolver => {
      const tx = db.transaction(Offline.STORE, 'readwrite');
      tx.objectStore(Offline.STORE).put(registro);
      tx.oncomplete = resolver;
    });
  },

  /** Limpa o erro de um item e tenta enviar de novo. */
  async tentarNovamente(id) {
    const itens = await Offline.listar();
    const reg = itens.find(r => r.id === id);
    if (reg) { delete reg.erro; await Offline._atualizar(reg); }
    await Offline.sincronizar(true);
  },

  notificarMudanca() {
    if (typeof Pendencias !== 'undefined') Pendencias.atualizar();
  },

  _sincronizando: false,

  /** Envia os itens pendentes. Por padrão pula os que já falharam por validação. */
  async sincronizar(incluirFalhados = false) {
    if (!navigator.onLine || Offline._sincronizando) return;
    Offline._sincronizando = true;
    try {
      await Offline._sincronizar(incluirFalhados);
    } finally {
      Offline._sincronizando = false;
    }
  },

  async _sincronizar(incluirFalhados) {
    const todos = await Offline.listar();
    const pendentes = todos.filter(r => incluirFalhados || !r.erro);
    if (!pendentes.length) { Offline.notificarMudanca(); return; }

    const badge = document.getElementById('indicadorSync');
    if (badge) badge.classList.remove('d-none');

    let enviadas = 0;
    for (const reg of pendentes) {
      const fd = new FormData();
      for (const [chave, valor] of Object.entries(reg.campos)) fd.append(chave, valor);
      for (const a of reg.arquivos) fd.append(a.campo, a.blob, a.nome);

      let resp;
      try {
        resp = await fetch('index.php?r=' + reg.rota, {
          method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' },
        });
      } catch (e) {
        break; // conexão caiu de novo — tenta na próxima
      }
      let dados = null;
      try { dados = await resp.json(); } catch (e) { dados = null; }
      if (dados && dados.ok) {
        await Offline.remover(reg.id);
        enviadas++;
      } else {
        // Erro de negócio (validação/sessão): marca e não bloqueia a fila
        reg.erro = (dados && dados.erro) ? dados.erro : ('HTTP ' + (resp ? resp.status : '?'));
        await Offline._atualizar(reg);
      }
    }

    if (badge) badge.classList.add('d-none');
    Offline.notificarMudanca();
    if (enviadas > 0 && typeof App !== 'undefined') {
      App.alerta(`${enviadas} lançamento(s) feito(s) offline foram sincronizados.`, 'success');
    }
  },

  // Compatibilidade: chamada antiga de visitas continua funcionando.
  async guardarVisita(form) {
    await Offline.enfileirar('visitas/salvar', form, { modulo: 'visitas', rotulo: 'Visita técnica' });
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
