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
  DB_VERSAO: 3,
  STORE: 'fila_sync',

  abrirBanco() {
    return new Promise((resolver, rejeitar) => {
      const req = indexedDB.open(Offline.DB_NOME, Offline.DB_VERSAO);
      req.onupgradeneeded = (ev) => {
        const db = req.result;
        if (!db.objectStoreNames.contains('fila_sync')) {
          db.createObjectStore('fila_sync', { keyPath: 'id', autoIncrement: true });
        }
        // v3: snapshot da carteira para leitura offline (O2)
        if (!db.objectStoreNames.contains('snapshot')) {
          db.createObjectStore('snapshot', { keyPath: 'chave' });
        }
        // Migração v1→v2: leva os itens antigos de fila_visitas para a fila genérica
        if (ev.oldVersion < 2 && db.objectStoreNames.contains('fila_visitas')) {
          const tx = req.transaction;
          const antigo = tx.objectStore('fila_visitas');
          const destino = tx.objectStore('fila_sync');
          antigo.getAll().onsuccess = (e) => {
            for (const r of (e.target.result || [])) {
              const uuid = Offline._uuid();
              destino.add({
                rota: 'visitas/salvar', modulo: 'visitas', rotulo: 'Visita técnica', uuid,
                campos: Object.assign({}, r.campos || {}, { uuid_offline: uuid }),
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
    const uuid = Offline._uuid();
    const registro = {
      rota, modulo: opc.modulo || '', rotulo: opc.rotulo || rota, uuid,
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
    registro.campos['uuid_offline'] = uuid; // idempotência no servidor (O3)

    const db = await Offline.abrirBanco();
    await new Promise((resolver, rejeitar) => {
      const tx = db.transaction(Offline.STORE, 'readwrite');
      tx.objectStore(Offline.STORE).add(registro);
      tx.oncomplete = resolver;
      tx.onerror = () => rejeitar(tx.error);
    });
    if (registro.arquivos.length) Offline._avisarCota();
    Offline._registrarBackgroundSync();
    Offline.notificarMudanca();
  },

  /** uuid v4 (crypto quando disponível). */
  _uuid() {
    if (typeof crypto !== 'undefined' && crypto.randomUUID) return crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
      const r = Math.floor(Math.random() * 16);
      return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
    });
  },

  /** Avisa se o armazenamento do aparelho está quase cheio (anexos ocupam espaço). */
  async _avisarCota() {
    try {
      if (navigator.storage && navigator.storage.estimate) {
        const { usage, quota } = await navigator.storage.estimate();
        if (quota && usage / quota > 0.9 && typeof App !== 'undefined') {
          App.alerta('Armazenamento do aparelho quase cheio — sincronize assim que tiver conexão.', 'warning');
        }
      }
    } catch (e) { /* ignore */ }
  },

  /** Registra Background Sync (o navegador reenvia ao voltar a conexão). */
  async _registrarBackgroundSync() {
    try {
      if ('serviceWorker' in navigator && 'SyncManager' in window) {
        const reg = await navigator.serviceWorker.ready;
        await reg.sync.register('sync-fila');
      }
    } catch (e) { /* sem suporte: cai no evento 'online' e no timer de load */ }
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
      // Trava entre abas: só uma aba/janela sincroniza a fila por vez (evita reenvio duplo).
      if (navigator.locks && navigator.locks.request) {
        await navigator.locks.request('crm-sync-fila', { ifAvailable: true }, async lock => {
          if (!lock) return; // outra aba já está sincronizando
          await Offline._sincronizar(incluirFalhados);
        });
      } else {
        await Offline._sincronizar(incluirFalhados);
      }
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
      // Sessão expirada: mantém a fila intacta e pede login (não perde dados)
      if ((resp.redirected && /r=login/.test(resp.url)) || resp.status === 401 || resp.status === 403) {
        if (typeof App !== 'undefined') App.alerta('Sessão expirada. Faça login para enviar os lançamentos pendentes.', 'warning');
        break;
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

  // ---------- Snapshot da carteira (leitura offline — O2) ----------

  async salvarSnapshot(dados) {
    const db = await Offline.abrirBanco();
    await new Promise(resolver => {
      const tx = db.transaction('snapshot', 'readwrite');
      tx.objectStore('snapshot').put({ chave: 'carteira', dados, atualizado_em: dados.atualizado_em || new Date().toISOString() });
      tx.oncomplete = resolver;
    });
  },

  async lerSnapshot() {
    const db = await Offline.abrirBanco();
    return new Promise(resolver => {
      const tx = db.transaction('snapshot', 'readonly');
      const req = tx.objectStore('snapshot').get('carteira');
      req.onsuccess = () => resolver(req.result ? req.result.dados : null);
      req.onerror = () => resolver(null);
    });
  },

  /** Baixa a carteira do servidor e guarda no IndexedDB (só quando online). */
  async baixarCarteira() {
    if (!navigator.onLine) return;
    try {
      const resp = await fetch('index.php?r=sync/carteira', { headers: { 'X-Requested-With': 'fetch' } });
      const dados = await resp.json();
      if (dados && dados.ok) await Offline.salvarSnapshot(dados);
    } catch (e) { /* offline/erro: mantém o snapshot anterior */ }
  },

  // ---------- Base do CAR do município (offline — pesada, muda pouco) ----------

  async lerCarMunicipio() {
    const db = await Offline.abrirBanco();
    return new Promise(resolver => {
      const tx = db.transaction('snapshot', 'readonly');
      const req = tx.objectStore('snapshot').get('car_municipio');
      req.onsuccess = () => resolver(req.result || null);
      req.onerror = () => resolver(null);
    });
  },

  /**
   * Baixa a base do CAR do município uma vez (ou a cada 7 dias) e guarda no
   * IndexedDB — é grande, então não refaz a cada load.
   */
  async baixarCarMunicipio(forcar) {
    if (!navigator.onLine) return;
    try {
      const atual = await Offline.lerCarMunicipio();
      const idade = atual ? (Date.now() - new Date(atual.atualizado_em).getTime()) : Infinity;
      if (!forcar && atual && idade < 7 * 864e5) return; // fresca o suficiente
      const resp = await fetch('index.php?r=sync/car-municipio', { headers: { 'X-Requested-With': 'fetch' } });
      const dados = await resp.json();
      if (dados && dados.ok) {
        const db = await Offline.abrirBanco();
        await new Promise(r => {
          const tx = db.transaction('snapshot', 'readwrite');
          tx.objectStore('snapshot').put({ chave: 'car_municipio', imoveis: dados.imoveis, atualizado_em: dados.atualizado_em });
          tx.oncomplete = r;
        });
      }
    } catch (e) { /* mantém a base anterior */ }
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
  // Background Sync: o SW avisa os clientes para sincronizar quando a conexão volta
  navigator.serviceWorker.addEventListener('message', ev => {
    if (ev.data === 'sincronizar-fila') Offline.sincronizar();
  });
}

// Ao abrir a página: sincroniza a fila e atualiza o snapshot da carteira.
document.addEventListener('DOMContentLoaded', () => {
  setTimeout(() => { Offline.sincronizar(); Offline.baixarCarteira(); }, 1500);
});
window.addEventListener('online', () => Offline.baixarCarteira());
