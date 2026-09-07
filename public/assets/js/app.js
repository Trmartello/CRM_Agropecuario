/* CRM Agropecuário Copérdia — JavaScript da aplicação (vanilla + fetch) */

'use strict';

/* ============================== NÚCLEO ============================== */

const App = {
  /** fetch JSON com tratamento de erro padrão. */
  async json(url, opcoes = {}) {
    opcoes.headers = Object.assign({ 'X-Requested-With': 'fetch' }, opcoes.headers || {});
    const resp = await fetch(url, opcoes);
    const dados = await resp.json().catch(() => ({ ok: false, erro: 'Resposta inválida do servidor.' }));
    if (!dados.ok) throw new Error(dados.erro || 'Erro inesperado.');
    return dados;
  },

  /**
   * TRAVA DE ENVIO DUPLO (pedido do teste de campo: o mesmo talhão foi gravado
   * 7 vezes com toques repetidos em "Salvar" na rede lenta). Enquanto um envio
   * está em voo, o mesmo formulário/rota não sai de novo: os botões de submit
   * ficam desabilitados e um segundo toque recebe "Aguarde…".
   */
  _emVoo: new Set(),
  _travar(chave, form) {
    if (App._emVoo.has(chave)) throw new Error('Aguarde — ainda estamos salvando o envio anterior.');
    App._emVoo.add(chave);
    const botoes = form && form.querySelectorAll ? [...form.querySelectorAll('button:not([type=button]):not([type=reset])')] : [];
    botoes.forEach(b => { b.dataset.travado = b.disabled ? '' : '1'; b.disabled = true; });
    return () => {
      App._emVoo.delete(chave);
      botoes.forEach(b => { if (b.dataset.travado === '1') b.disabled = false; delete b.dataset.travado; });
    };
  },

  /** Envia um formulário via AJAX (FormData). Um envio por vez por formulário. */
  async enviarForm(form, url) {
    const soltar = App._travar('form:' + (form.id || url), form);
    try {
      return await App.json(url, { method: 'POST', body: new FormData(form) });
    } finally { soltar(); }
  },

  /**
   * Envia um formulário/FormData com suporte offline: sem conexão (ou se a rede
   * cair no meio), guarda na fila local e devolve { ok:true, offline:true }.
   * Erros de negócio (validação do servidor) continuam sendo lançados.
   * Um envio por vez por formulário/rota (trava de envio duplo).
   */
  async enviarFormOffline(origem, url, opc = {}) {
    const rota = String(url).replace(/^.*[?&]r=/, '').replace(/&.*$/, '');
    const ehForm = !(origem instanceof FormData);
    const soltar = App._travar(ehForm ? 'form:' + (origem.id || rota) : 'rota:' + rota, ehForm ? origem : null);
    try {
      const fd = ehForm ? new FormData(origem) : origem;
      if (!navigator.onLine) {
        await Offline.enfileirar(rota, fd, opc);
        return { ok: true, offline: true };
      }
      try {
        return await App.json(url, { method: 'POST', body: fd });
      } catch (e) {
        if (e instanceof TypeError) { // falha de REDE (não de negócio) → enfileira
          await Offline.enfileirar(rota, fd, opc);
          return { ok: true, offline: true };
        }
        throw e;
      }
    } finally { soltar(); }
  },

  alerta(mensagem, tipo = 'success') {
    const div = document.createElement('div');
    div.className = `toast align-items-center text-bg-${tipo} border-0 show mb-2`;
    div.innerHTML = `<div class="d-flex"><div class="toast-body">${App.escapeHtml(mensagem)}</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
    document.getElementById('alertas').appendChild(div);
    setTimeout(() => div.remove(), 5000);
  },

  /** Captura geolocalização e preenche inputs latitude/longitude do form. */
  capturarGeo(seletorForm, aoTerminar) {
    const form = document.querySelector(seletorForm);
    if (!form || !navigator.geolocation) { if (aoTerminar) aoTerminar(false); return; }
    navigator.geolocation.getCurrentPosition(
      pos => {
        form.querySelector('[name=latitude]').value = pos.coords.latitude.toFixed(7);
        form.querySelector('[name=longitude]').value = pos.coords.longitude.toFixed(7);
        if (aoTerminar) aoTerminar(true);
      },
      () => { if (aoTerminar) aoTerminar(false); },
      { enableHighAccuracy: true, timeout: 8000 }
    );
  },

  moeda(v) {
    return (Number(v) || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
  },

  /** Miniatura que não carregou (formato não exibível ou arquivo ausente): vira um cartão-link. */
  fotoIndisponivel(img) {
    const link = img.closest('a');
    const alvo = document.createElement('span');
    alvo.className = 'foto-miniatura d-inline-flex flex-column align-items-center justify-content-center bg-light border text-muted text-center';
    alvo.style.fontSize = '.7rem';
    alvo.innerHTML = '<i class="bi bi-file-earmark-image fs-4 d-block"></i>abrir foto';
    img.replaceWith(alvo);
    if (link) link.title = 'Prévia indisponível (formato não suportado ou arquivo ausente) — toque para abrir.';
  },

  /** Cresce um textarea para caber todo o texto; respeita o aumento manual (alça). */
  autoCrescer(el) {
    if (!el || el.tagName !== 'TEXTAREA') return;
    el.style.height = 'auto';
    const manual = parseInt(el.dataset.alturaManual || '0', 10);
    el.style.height = Math.max(el.scrollHeight, manual) + 'px';
  },

  /**
   * Select com busca digitável para listas longas (ex.: os 1.191 municípios —
   * no celular o seletor nativo vira uma roda impossível de percorrer).
   * O <select> continua no DOM, escondido, como fonte da verdade (name, value,
   * options, onchange e data-* intactos); o campo de texto filtra as opções
   * sem acento/maiúsculas ("conc" → Concórdia – SC) e dispara `change` no
   * select ao escolher. Mudança programática (sel.value = …, disabled) chama
   * App.selectBuscaSync(sel) para refletir no campo.
   */
  selectBusca(sel) {
    if (!sel || sel.tagName !== 'SELECT' || sel.dataset.busca === '1') return;
    sel.dataset.busca = '1';
    const norm = s => String(s || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
    const wrap = document.createElement('div');
    wrap.className = 'select-busca';
    const inp = document.createElement('input');
    inp.type = 'text';
    inp.className = 'form-control' + (sel.classList.contains('form-select-sm') ? ' form-control-sm' : '');
    inp.autocomplete = 'off';
    inp.setAttribute('enterkeyhint', 'done');
    inp.placeholder = sel.dataset.placeholder || 'Digite para buscar…';
    inp.setAttribute('aria-label', inp.placeholder);
    const lista = document.createElement('div');
    lista.className = 'select-busca-lista list-group d-none';
    sel.parentNode.insertBefore(wrap, sel);
    wrap.append(inp, lista, sel);
    sel.classList.add('d-none');
    sel.tabIndex = -1;

    const opts = [...sel.options].filter(o => o.value !== '').map(o => ({ o, t: o.textContent, n: norm(o.textContent) }));
    const MAX = 60;
    let atuais = [];
    let ativo = -1;
    const fechar = () => { lista.classList.add('d-none'); lista.innerHTML = ''; };
    const sync = (forcar = false) => {
      inp.disabled = sel.disabled;
      // usuário digitando (ex.: shown.bs.modal chega depois do 1º toque): não interrompe
      if (!forcar && document.activeElement === inp && !inp.disabled) return;
      const o = sel.selectedOptions[0];
      inp.value = o && o.value !== '' ? o.textContent : '';
      fechar();
    };
    sel._buscaSync = () => sync(false);
    const escolher = o => {
      if (sel.value !== o.value) {
        sel.value = o.value;
        sel.dispatchEvent(new Event('change', { bubbles: true }));
      }
      sync(true);
    };
    const render = () => {
      if (inp.disabled) return;
      const q = norm(inp.value);
      let cand = q ? opts.filter(x => x.n.includes(q)) : opts;
      if (q) cand = cand.slice().sort((a, b) => Number(b.n.startsWith(q)) - Number(a.n.startsWith(q)));
      atuais = cand.slice(0, MAX);
      ativo = atuais.length ? 0 : -1;
      const html = atuais.map((x, i) =>
        `<button type="button" class="list-group-item list-group-item-action${i === ativo ? ' active' : ''}" data-i="${i}">${App.escapeHtml(x.t)}</button>`);
      if (!atuais.length) html.push('<div class="list-group-item text-muted small">Nenhum resultado. Confira a grafia.</div>');
      else if (cand.length > MAX) html.push(`<div class="list-group-item text-muted small">Mostrando ${MAX} de ${cand.length} — continue digitando para refinar.</div>`);
      lista.innerHTML = html.join('');
      lista.classList.remove('d-none');
    };
    const marcar = () => lista.querySelectorAll('.list-group-item-action').forEach((b, i) => b.classList.toggle('active', i === ativo));

    inp.addEventListener('focus', render);
    inp.addEventListener('input', () => {
      if (norm(inp.value) === '' && sel.value !== '') {
        sel.value = '';
        sel.dispatchEvent(new Event('change', { bubbles: true }));
      }
      render();
    });
    inp.addEventListener('keydown', ev => {
      if (lista.classList.contains('d-none')) return;
      if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
        ev.preventDefault();
        if (!atuais.length) return;
        ativo = (ativo + (ev.key === 'ArrowDown' ? 1 : atuais.length - 1)) % atuais.length;
        marcar();
        const b = lista.querySelectorAll('.list-group-item-action')[ativo];
        if (b && b.scrollIntoView) b.scrollIntoView({ block: 'nearest' });
      } else if (ev.key === 'Enter') {
        ev.preventDefault();
        if (ativo >= 0 && atuais[ativo]) escolher(atuais[ativo].o);
      } else if (ev.key === 'Escape') {
        ev.preventDefault();
        sync(true);
      }
    });
    // mousedown prevenido: tocar na lista não tira o foco do campo (o blur fecharia antes do click)
    lista.addEventListener('mousedown', ev => ev.preventDefault());
    lista.addEventListener('click', ev => {
      const b = ev.target.closest('.list-group-item-action');
      if (b && atuais[Number(b.dataset.i)]) escolher(atuais[Number(b.dataset.i)].o);
    });
    inp.addEventListener('blur', () => setTimeout(() => {
      if (document.activeElement === inp) return;
      const q = norm(inp.value);
      if (q === '') { if (sel.value !== '') { sel.value = ''; sel.dispatchEvent(new Event('change', { bubbles: true })); } sync(true); return; }
      // texto digitado igual a uma opção (ou único resultado) → adota; senão volta ao valor atual
      const exato = opts.find(x => x.n === q) || (atuais.length === 1 ? atuais[0] : null);
      if (exato) escolher(exato.o); else sync(true);
    }, 150));
    sel.addEventListener('change', () => sync(false));
    sync(true);
  },

  /** Reflete no campo de busca uma mudança programática do select (valor/disabled). */
  selectBuscaSync(sel) {
    if (sel && typeof sel._buscaSync === 'function') sel._buscaSync();
  },

  /** Escapa texto para inserção segura via innerHTML. */
  escapeHtml(v) {
    return String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  },

  /** Aceita apenas links internos (index.php…); descarta esquemas perigosos como javascript:. */
  linkSeguro(v) {
    const s = String(v || '');
    // "/(?!\/)": caminho interno sim, mas NUNCA "//host" (URL protocolo-relativa
    // navegaria para domínio externo — open redirect)
    return /^(index\.php|\?|#|uploads\/|\/(?!\/))/.test(s) ? s : '#';
  },

  /**
   * Renderiza miniaturas das fotos/PDFs escolhidos em um input múltiplo,
   * cada uma com um campo de comentário individual opcional (fotos_legenda[],
   * alinhado por índice com fotos[] no servidor).
   */
  previewFotosGrid(input, gridId) {
    const grid = document.getElementById(gridId);
    if (!grid) return;
    grid.innerHTML = '';
    for (const arquivo of input.files || []) {
      const item = document.createElement('div');
      item.className = 'foto-item';
      if (arquivo.type === 'application/pdf') {
        item.innerHTML = '<div class="foto-miniatura d-flex align-items-center justify-content-center bg-light border"><i class="bi bi-file-earmark-pdf fs-3 text-danger"></i></div>';
      } else {
        const img = document.createElement('img');
        img.className = 'foto-miniatura';
        img.src = URL.createObjectURL(arquivo);
        item.appendChild(img);
      }
      item.insertAdjacentHTML('beforeend',
        `<div class="campo-voz mt-1">
           <input type="text" name="fotos_legenda[]" class="form-control form-control-sm" maxlength="255" placeholder="Comentário (opcional)">
           <button type="button" class="btn-voz" title="Ditar por voz" aria-label="Ditar por voz"><i class="bi bi-mic-fill"></i></button>
         </div>`);
      grid.appendChild(item);
    }
  },

  /** Gráfico Potencial x Realizado na ficha do cliente. */
  graficoPotencialCliente() {
    const canvas = document.getElementById('graficoPotencial');
    if (!canvas || typeof Chart === 'undefined') return;
    const dados = JSON.parse(canvas.dataset.potencial || '[]');
    if (!dados.length) return;
    const c = new Chart(canvas, {
      type: 'bar',
      data: {
        labels: dados.map(d => d.familia),
        datasets: [{
          label: '% do potencial comprado',
          data: dados.map(d => Math.min(150, Number(d.percentual))),
          backgroundColor: dados.map(d => Number(d.percentual) >= 70 ? '#2e7d32' : (Number(d.percentual) >= 40 ? '#f9a825' : '#c62828')),
          borderRadius: 6,
        }],
      },
      options: {
        indexAxis: 'y',
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: ctx => {
            const d = dados[ctx.dataIndex];
            return `${d.percentual}% — ${App.moeda(d.realizado)} de ${App.moeda(d.valor_potencial)}`;
          } } },
        },
        scales: { x: { max: 120, ticks: { callback: v => v + '%' } } },
      },
    });
    c.$rotulo = { formatter: v => v + '%', color: '#1b5e20' };
    c.update();
  },
};

/* ============================== VOZ (Web Speech API) ============================== */

const Voz = {
  reconhecimento: null,
  botaoAtivo: null,

  iniciar() {
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SpeechRecognition) return; // fallback silencioso: botões ficam ocultos

    document.querySelectorAll('.btn-voz').forEach(btn => { btn.style.display = 'inline-flex'; });
    // Campos criados DEPOIS (ex.: observação do checklist) mostram o microfone via CSS
    document.body.classList.add('voz-suportada');

    document.addEventListener('click', ev => {
      const btn = ev.target.closest('.btn-voz');
      if (!btn) return;
      ev.preventDefault();
      if (Voz.botaoAtivo === btn) { Voz.parar(); return; }
      Voz.parar();
      Voz.gravar(btn, SpeechRecognition);
    });
  },

  gravar(btn, SpeechRecognition) {
    const campo = btn.closest('.campo-voz').querySelector('input, textarea');
    const rec = new SpeechRecognition();
    rec.lang = 'pt-BR';
    rec.continuous = true;
    rec.interimResults = false;

    rec.onresult = ev => {
      for (let i = ev.resultIndex; i < ev.results.length; i++) {
        if (ev.results[i].isFinal) {
          const texto = ev.results[i][0].transcript.trim();
          campo.value = (campo.value ? campo.value.trimEnd() + ' ' : '') + texto;
          App.autoCrescer(campo);
        }
      }
    };
    rec.onend = () => Voz.parar();
    rec.onerror = () => Voz.parar();

    rec.start();
    btn.classList.add('gravando');
    btn.title = 'Parar gravação';
    btn.querySelector('i').className = 'bi bi-stop-fill';
    Voz.reconhecimento = rec;
    Voz.botaoAtivo = btn;
  },

  parar() {
    if (Voz.reconhecimento) { try { Voz.reconhecimento.stop(); } catch (e) {} }
    if (Voz.botaoAtivo) {
      Voz.botaoAtivo.classList.remove('gravando');
      Voz.botaoAtivo.title = 'Ditar por voz';
      Voz.botaoAtivo.querySelector('i').className = 'bi bi-mic-fill';
    }
    Voz.reconhecimento = null;
    Voz.botaoAtivo = null;
  },
};

/* ============================== NOTIFICAÇÕES ============================== */

const Notificacoes = {
  async atualizarContador() {
    try {
      const d = await App.json('index.php?r=notificacoes/listar', { headers: { 'X-Requested-With': 'fetch' } });
      const badge = document.getElementById('sinoContador');
      if (!badge) return;
      badge.textContent = d.nao_lidas;
      badge.classList.toggle('d-none', d.nao_lidas === 0);
      Notificacoes._itens = d.itens;
    } catch (e) { /* silencioso */ }
  },

  abrir() {
    const alvo = document.getElementById('sinoItens');
    const itens = Notificacoes._itens || [];
    if (!itens.length) { alvo.innerHTML = '<div class="text-muted small text-center py-3">Sem notificações.</div>'; return; }
    alvo.innerHTML = itens.map(n => `
      <a class="dropdown-item d-flex gap-2 py-2 ${n.lida == 0 ? 'bg-success-subtle' : ''}" href="${App.escapeHtml(App.linkSeguro(n.link))}"
         onclick="Notificacoes.ler(${Number(n.id)})">
        <i class="bi bi-dot fs-4 ${n.lida == 0 ? 'text-success' : 'text-muted'}"></i>
        <div style="white-space:normal"><div class="fw-semibold small">${App.escapeHtml(n.titulo)}</div>
          <div class="small text-muted">${App.escapeHtml(n.texto || '')}</div></div>
      </a>`).join('');
  },

  async ler(id) {
    try {
      const fd = new FormData(); fd.append('id', id);
      await App.json('index.php?r=notificacoes/ler', { method: 'POST', body: fd });
      Notificacoes.atualizarContador();
    } catch (e) { /* segue o link mesmo assim */ }
  },

  async lerTodas(ev) {
    if (ev) ev.preventDefault();
    try {
      await App.json('index.php?r=notificacoes/ler-todas', { method: 'POST', body: new FormData() });
      await Notificacoes.atualizarContador();
      Notificacoes.abrir();
    } catch (e) { App.alerta('Erro ao marcar notificações.', 'danger'); }
  },

  /** Ativa as notificações push neste aparelho (Android; iPhone com o app instalado). */
  async ativarPush() {
    try {
      if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        App.alerta('Este navegador não suporta notificações. No iPhone, instale o app pela opção "Adicionar à Tela de Início".', 'warning');
        return;
      }
      const perm = await Notification.requestPermission();
      if (perm !== 'granted') { App.alerta('Permissão de notificação não concedida.', 'warning'); return; }
      const reg = await navigator.serviceWorker.ready;
      const { chave } = await App.json('index.php?r=push/chave');
      const sub = await reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: Notificacoes._b64ParaBytes(chave),
      });
      const r = await fetch('index.php?r=push/registrar', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' },
        body: JSON.stringify(sub.toJSON()),
      });
      const d = await r.json();
      if (!d.ok) throw new Error(d.erro || 'Falha ao registrar.');
      App.alerta('Notificações ativadas neste aparelho.');
      Notificacoes._atualizarBotaoPush();
    } catch (e) {
      App.alerta('Não foi possível ativar as notificações: ' + e.message, 'danger');
    }
  },

  _b64ParaBytes(b64) {
    const pad = '='.repeat((4 - b64.length % 4) % 4);
    const raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from([...raw].map(c => c.charCodeAt(0)));
  },

  /** Esconde o botão quando o aparelho já está assinado (ou não há suporte). */
  async _atualizarBotaoPush() {
    const btn = document.getElementById('btnAtivarPush');
    if (!btn) return;
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) return; // deixa visível com a dica do iPhone
    try {
      const reg = await navigator.serviceWorker.ready;
      const sub = await reg.pushManager.getSubscription();
      btn.classList.toggle('d-none', !!sub);
    } catch (e) { /* mantém visível */ }
  },
};

/* ============================== CLIENTES ============================== */

const Clientes = {
  novo() {
    const form = document.getElementById('formCliente');
    form.reset();
    form.querySelector('[name=id]').value = 0;
    document.getElementById('modalClienteTitulo').textContent = 'Novo Cliente';
    App.capturarGeo('#formCliente');
  },

  async editar(id) {
    const form = document.getElementById('formCliente');
    form.reset();
    document.getElementById('modalClienteTitulo').textContent = 'Editar Cliente';
    try {
      const { cliente } = await App.json(`index.php?r=clientes/obter&id=${id}`);
      for (const [chave, valor] of Object.entries(cliente)) {
        const campo = form.querySelector(`[name=${chave}]`);
        if (campo && campo.type !== 'checkbox' && valor !== null) campo.value = valor;
      }
      const chkProsp = form.querySelector('[name=prospecto]');
      if (chkProsp) chkProsp.checked = Number(cliente.prospecto) === 1;
      // Município – UF da lista: cadastro antigo em texto é casado sem acento (e pela UF)
      const selMun = form.querySelector('[name=municipio]');
      if (selMun && !selMun.value && cliente.municipio) {
        const alvo = Clientes._semAcento(cliente.municipio);
        const opt = [...selMun.options].find(o => Clientes._semAcento(o.dataset.nome) === alvo && (!cliente.estado || o.dataset.uf === cliente.estado))
          || [...selMun.options].find(o => Clientes._semAcento(o.dataset.nome) === alvo);
        if (opt) selMun.value = opt.value;
      }
      if (selMun) { Clientes.ufDoMunicipio(selMun); App.selectBuscaSync(selMun); }
    } catch (e) { App.alerta(e.message, 'danger'); }
  },

  /** A UF do produtor vem do município escolhido na lista (hidden `estado`). */
  ufDoMunicipio(sel) {
    const uf = sel.selectedOptions[0] && sel.selectedOptions[0].dataset.uf;
    const hid = sel.form && sel.form.querySelector('[name=estado]');
    if (hid && uf) hid.value = uf;
  },

  async salvar(ev) {
    ev.preventDefault();
    try {
      await App.enviarForm(ev.target, 'index.php?r=clientes/salvar');
      App.alerta('Cliente salvo com sucesso.');
      setTimeout(() => location.href = 'index.php?r=clientes', 600);
    } catch (e) { App.alerta(e.message, 'danger'); }
    return false;
  },

  async ficha(id) {
    Clientes.fichaClienteId = id;
    const painel = new bootstrap.Offcanvas('#painelFicha');
    painel.show();
    const corpo = document.getElementById('painelFichaCorpo');
    corpo.innerHTML = '<div class="text-center py-5 text-muted"><div class="spinner-border text-success"></div></div>';
    const resp = await fetch(`index.php?r=clientes/ficha&id=${id}`, { headers: { 'X-Requested-With': 'fetch-html' } });
    corpo.innerHTML = await resp.text();
    App.graficoPotencialCliente();
  },

  async salvarDocumento(ev) {
    ev.preventDefault();
    try {
      await App.enviarForm(ev.target, 'index.php?r=clientes/salvar-documento');
      App.alerta('Documento anexado.');
      Clientes.ficha(Number(ev.target.querySelector('[name=cliente_id]').value));
    } catch (e) { App.alerta(e.message, 'danger'); }
    return false;
  },

  async excluirDocumento(id, clienteId) {
    if (!confirm('Excluir este documento?')) return;
    try {
      const fd = new FormData();
      fd.append('id', id);
      await App.json('index.php?r=clientes/excluir-documento', { method: 'POST', body: fd });
      App.alerta('Documento excluído.');
      Clientes.ficha(clienteId);
    } catch (e) { App.alerta(e.message, 'danger'); }
  },

  novaPropriedade(clienteId) {
    const form = document.getElementById('formPropriedade');
    form.reset();
    document.getElementById('btnExcluirPropriedade').classList.add('d-none');
    form.querySelector('[name=id]').value = 0;
    form.querySelector('[name=cliente_id]').value = clienteId;
    Clientes._areasNoModalPropriedade(null);
    new bootstrap.Modal('#modalPropriedade').show();
  },

  /**
   * As áreas da propriedade NUNCA são digitadas: total = soma dos CARs (imóveis),
   * plantio = desenhado nos croquis, talhões = soma dos talhões. Só leitura.
   */
  _areasNoModalPropriedade(resumo) {
    const fmt = v => Number(v).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + ' ha';
    const r = resumo || {};
    const total = Number(r.area_total || 0), plantio = Number(r.area_plantio || 0), soma = Number(r.soma || 0);
    document.getElementById('propAreaTotal').textContent = total > 0 ? fmt(total) : '—';
    document.getElementById('propAreaTotalNota').textContent = total > 0
      ? 'Soma dos CARs (imóveis).'
      : 'Vem dos CARs: abra o croqui do imóvel e traga a divisa.';
    document.getElementById('propAreaPlantio').textContent = plantio > 0 ? fmt(plantio) + (r.plantio_origem === 'total' ? ' (= total)' : '') : '—';
    document.getElementById('propAreaTalhoes').textContent = soma > 0 ? fmt(soma) : '—';
    document.getElementById('propAreaTalhoesNota').textContent = Number(r.excedente || 0) > 0
      ? `Passam ${fmt(r.excedente)} da área de plantio.`
      : (Number(r.nao_mapeado || 0) > 0 ? `Sem talhão: ${fmt(r.nao_mapeado)}.` : 'Soma dos talhões.');
  },

  editarPropriedade(p) {
    const form = document.getElementById('formPropriedade');
    form.reset();
    form.querySelector('[name=id]').value = p.id;
    form.querySelector('[name=cliente_id]').value = p.cliente_id;
    form.querySelector('[name=nome]').value = p.nome;
    // Município: lista pré-cadastrada — casa pelo nome sem acento (cadastro antigo em texto)
    const selMun = form.querySelector('[name=municipio]');
    selMun.value = p.municipio || '';
    if (!selMun.value && p.municipio) {
      const alvo = Clientes._semAcento(p.municipio);
      const opt = [...selMun.options].find(o => Clientes._semAcento(o.value) === alvo);
      if (opt) selMun.value = opt.value;
    }
    App.selectBuscaSync(selMun);
    Clientes._areasNoModalPropriedade(p.resumo || null);
    document.getElementById('btnExcluirPropriedade').classList.remove('d-none');
    new bootstrap.Modal('#modalPropriedade').show(); // v40: o nº do CAR fica no imóvel, não aqui
  },

  /** Exclui a propriedade (só sem talhões e sem visitas — o servidor confere). */
  async excluirPropriedade() {
    const form = document.getElementById('formPropriedade');
    const id = Number(form.querySelector('[name=id]').value);
    if (!id || !confirm('Excluir esta propriedade? Os imóveis (CAR) dela vão junto. Só é possível se ela não tiver talhões nem visitas.')) return;
    try {
      const fd = new FormData();
      fd.append('id', id);
      await App.json('index.php?r=clientes/excluir-propriedade', { method: 'POST', body: fd });
      bootstrap.Modal.getInstance('#modalPropriedade').hide();
      App.alerta('Propriedade excluída.');
      if (Clientes.fichaClienteId) Clientes.ficha(Clientes.fichaClienteId);
    } catch (e) { App.alerta(e.message, 'danger'); }
  },

  /** Importa a divisa oficial do CAR (shapefile .zip) e desenha no croqui. */
  importarCar(imovelId) { // v40: a divisa do CAR é do IMÓVEL
    const inp = document.createElement('input');
    inp.type = 'file';
    inp.accept = '.zip,application/zip';
    inp.onchange = async () => {
      if (!inp.files || !inp.files.length) return;
      const fd = new FormData();
      fd.append('imovel_id', imovelId);
      fd.append('arquivo', inp.files[0]);
      App.alerta('Lendo o shapefile do CAR…', 'info');
      try {
        const r = await App.json('index.php?r=clientes/importar-car', { method: 'POST', body: fd });
        App.alerta(`Divisa do CAR importada: ${r.pontos} pontos · ${Number(r.area_gps).toLocaleString('pt-BR', { maximumFractionDigits: 1 })} ha`
          + (r.car_numero ? ` · nº ${App.escapeHtml(r.car_numero)}` : '') + '.', 'success');
        if (r.talhoes_fora && r.talhoes_fora.length) {
          App.alerta('Atenção: talhão(ões) fora da divisa oficial do CAR: ' + r.talhoes_fora.join(', ') + '. Ajuste no croqui.', 'warning');
        }
        if (Clientes.fichaClienteId) setTimeout(() => Clientes.ficha(Clientes.fichaClienteId), 900);
      } catch (e) { App.alerta(e.message, 'danger'); }
    };
    inp.click();
  },

  /** Abre a consulta pública do SICAR e copia o número do CAR para colar na busca.
   *  (O SICAR é um app que exige colar o código na busca — não há URL pública que
   *  aplique o filtro sozinha; então copiamos o número e o técnico só cola e busca.) */
  copiarCar(cod) {
    if (cod && navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(cod).then(
        () => App.alerta('Número do CAR copiado. No SICAR, cole (Ctrl+V) na busca e clique na lupa para ver a área do imóvel.', 'info'),
        () => App.alerta('No SICAR, busque pelo número do CAR: ' + App.escapeHtml(cod), 'info')
      );
    }
    // não previne o default: o link segue abrindo o SICAR em nova aba
  },

  async salvarPropriedade(ev) {
    ev.preventDefault();
    try {
      await App.enviarForm(ev.target, 'index.php?r=clientes/salvar-propriedade');
      bootstrap.Modal.getInstance('#modalPropriedade').hide();
      App.alerta('Propriedade salva.');
      Clientes.ficha(Number(ev.target.querySelector('[name=cliente_id]').value));
    } catch (e) { App.alerta(e.message, 'danger'); }
    return false;
  },

  /* ---- v40: imóveis (CAR) da propriedade ---- */
  novoImovel(propriedadeId) {
    const form = document.getElementById('formImovel');
    form.reset();
    form.querySelector('[name=id]').value = 0;
    form.querySelector('[name=propriedade_id]').value = propriedadeId;
    document.getElementById('modalImovelTitulo').textContent = 'Novo imóvel (CAR)';
    document.getElementById('btnExcluirImovel').classList.add('d-none');
    Clientes._areasNoModalImovel(null, null, true); // novo: nada medido ainda → o croqui abre ao salvar
    Clientes.municipioDoCar(''); // lista livre até o nº do CAR identificar o município
    new bootstrap.Modal('#modalImovel').show();
  },

  _semAcento(s) {
    return String(s || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
  },

  /**
   * O município vem do próprio nº do CAR ("UF-IBGE-hash", ex.: SC-4207304-…): o
   * código IBGE embutido seleciona o município na lista pré-cadastrada e trava o
   * select (o servidor faz a mesma leitura e prevalece). Sem CAR, a lista fica livre.
   */
  municipioDoCar(car) {
    const sel = document.getElementById('imovelMunicipio');
    const nota = document.getElementById('imovelMunicipioNota');
    if (!sel) return;
    const m = /^\s*([A-Za-z]{2})[-\s.](\d{7})/.exec(String(car || ''));
    const opt = m ? sel.querySelector(`option[value="${m[2]}"]`) : null;
    if (opt) {
      sel.value = m[2];
      sel.disabled = true;
      nota.textContent = `Identificado pelo nº do CAR: ${opt.dataset.nome}/${opt.dataset.uf}.`;
      nota.classList.add('text-success');
    } else {
      sel.disabled = false;
      nota.classList.remove('text-success');
      nota.textContent = m
        ? 'O código do CAR não bate com a lista (SC/RS/PR) — escolha o município na lista.'
        : 'Preenchido sozinho pelo número do CAR; escolha na lista só se o imóvel ainda não tem CAR.';
    }
    App.selectBuscaSync(sel);
  },

  /**
   * As áreas do imóvel NUNCA são digitadas: a total vem da divisa do CAR e a de
   * plantio do desenho dentro dela. O modal só mostra o que já foi medido.
   */
  _areasNoModalImovel(areaMedida, plantioMedido, novo = false) {
    const fmt = v => Number(v).toLocaleString('pt-BR', { maximumFractionDigits: 2 }) + ' ha';
    const temArea = areaMedida !== null && areaMedida !== undefined && Number(areaMedida) > 0;
    document.getElementById('imovelAreaMedidaValor').textContent = temArea ? fmt(areaMedida) : '—';
    document.getElementById('imovelAreaMedidaNota').textContent = temArea
      ? 'Medida pela divisa do CAR no croqui.'
      : 'Ainda sem divisa — abra o croqui e traga o CAR.';
    const temPlantio = plantioMedido !== null && plantioMedido !== undefined && Number(plantioMedido) > 0;
    document.getElementById('imovelPlantioMedidaValor').textContent = temPlantio ? fmt(plantioMedido) : '—';
    document.getElementById('imovelPlantioMedidaNota').textContent = temPlantio
      ? 'Desenhada dentro da área do CAR.'
      : (temArea ? 'Ainda não desenhada — no croqui, escolha "Nova área de plantio" e desenhe dentro da divisa (pode haver várias).' : 'Desenhada dentro da área do CAR, depois de trazer a divisa.');
    document.getElementById('imovelNovoAviso').classList.toggle('d-none', !novo);
  },

  editarImovel(im) {
    const form = document.getElementById('formImovel');
    form.reset();
    form.querySelector('[name=id]').value = im.id;
    form.querySelector('[name=propriedade_id]').value = im.propriedade_id;
    form.querySelector('[name=nome]').value = im.nome || '';
    form.querySelector('[name=car_numero]').value = im.car_numero || '';
    // Município: lista pré-cadastrada (IBGE). Sem código gravado, tenta pelo nome antigo.
    const selMun = form.querySelector('[name=cod_ibge]');
    selMun.value = im.cod_ibge || '';
    if (!selMun.value && im.municipio) {
      const alvo = Clientes._semAcento(im.municipio);
      const opt = [...selMun.options].find(o => Clientes._semAcento(o.dataset.nome) === alvo && (!im.uf || o.dataset.uf === im.uf));
      if (opt) selMun.value = opt.value;
    }
    document.getElementById('imovelMunicipioNota').textContent = 'Preenchido sozinho pelo número do CAR; escolha na lista só se o imóvel ainda não tem CAR.';
    Clientes.municipioDoCar(im.car_numero || '');
    document.getElementById('modalImovelTitulo').textContent = 'Editar imóvel (CAR)';
    document.getElementById('btnExcluirImovel').classList.remove('d-none');
    Clientes._areasNoModalImovel(im.contorno ? im.area_gps : null, im.resumo && im.resumo.plantio_origem === 'plantio' ? im.resumo.area_plantio : null);
    new bootstrap.Modal('#modalImovel').show();
  },

  async salvarImovel(ev) {
    ev.preventDefault();
    const eraNovo = Number(ev.target.querySelector('[name=id]').value) <= 0;
    try {
      const r = await App.enviarForm(ev.target, 'index.php?r=clientes/salvar-imovel');
      bootstrap.Modal.getInstance('#modalImovel').hide();
      if (Clientes.fichaClienteId) await Clientes.ficha(Clientes.fichaClienteId);
      if (eraNovo && r.id && typeof Croqui !== 'undefined') {
        // Imóvel novo: a área total vem da divisa do CAR — o croqui abre direto para trazê-la
        // (CAR pela sede/no mapa/aqui ou importar o shapefile); a área de plantio e os
        // talhões são desenhados dentro dela.
        App.alerta('Imóvel criado. Agora traga a divisa do CAR — a área total é medida por ela.', 'info');
        Croqui.abrir(Number(r.id));
      } else {
        App.alerta('Imóvel salvo.');
      }
    } catch (e) { App.alerta(e.message, 'danger'); }
    return false;
  },

  async excluirImovel() {
    const form = document.getElementById('formImovel');
    const id = Number(form.querySelector('[name=id]').value);
    if (!id || !confirm('Excluir este imóvel (CAR)? Só é possível se ele não tiver talhões.')) return;
    try {
      const fd = new FormData();
      fd.append('id', id);
      await App.json('index.php?r=clientes/excluir-imovel', { method: 'POST', body: fd });
      bootstrap.Modal.getInstance('#modalImovel').hide();
      App.alerta('Imóvel excluído.');
      if (Clientes.fichaClienteId) Clientes.ficha(Clientes.fichaClienteId);
    } catch (e) { App.alerta(e.message, 'danger'); }
  },

  /** "Plantar a área toda": um talhão único cobrindo a área de plantio do imóvel. */
  plantarAreaToda(imovelId, rotulo, areaPlantio) {
    const form = document.getElementById('formPlantarArea');
    form.reset();
    form.querySelector('[name=imovel_id]').value = imovelId;
    document.getElementById('plantarAreaImovel').textContent = rotulo || '';
    document.getElementById('plantarAreaHa').textContent = Number(areaPlantio || 0).toLocaleString('pt-BR', { maximumFractionDigits: 1 });
    new bootstrap.Modal('#modalPlantarArea').show();
  },

  async salvarPlantarArea(ev) {
    ev.preventDefault();
    try {
      const r = await App.enviarForm(ev.target, 'index.php?r=clientes/plantar-area-toda');
      bootstrap.Modal.getInstance('#modalPlantarArea').hide();
      App.alerta(`${r.talhoes > 1 ? r.talhoes + ' talhões criados' : 'Talhão criado'} com ${Number(r.area_ha || 0).toLocaleString('pt-BR', { maximumFractionDigits: 1 })} ha — a área de plantio inteira.`);
      if (Clientes.fichaClienteId) Clientes.ficha(Clientes.fichaClienteId);
    } catch (e) { App.alerta(e.message, 'danger'); }
    return false;
  },

  /** Preenche o seletor de imóvel do modal de talhão com os imóveis da propriedade. */
  _imoveisNoModalTalhao(imoveis, selecionado) {
    const sel = document.getElementById('talhaoImovel');
    if (!sel) return;
    const lista = Array.isArray(imoveis) ? imoveis : [];
    sel.innerHTML = lista.map(i => `<option value="${Number(i.id)}">${App.escapeHtml(i.rotulo || ('Imóvel ' + i.id))}</option>`).join('');
    if (selecionado && lista.some(i => Number(i.id) === Number(selecionado))) sel.value = selecionado;
    else if (lista.length) sel.value = lista[0].id;
  },

  /** Talhão novo NASCE DESENHADO (teste de campo): abre o croqui do imóvel já no modo "novo talhão". */
  novoTalhao(propriedadeId, imovelId) {
    Croqui.abrir(imovelId, { novoTalhao: true });
  },

  /**
   * Chamado pelo croqui ao salvar um talhão novo: o contorno já está desenhado,
   * falta nome, cultura e finalidade. O modal grava tudo junto (salvar-talhao com
   * contorno; o servidor mede a área, prende na divisa e recusa sobreposição).
   */
  novoTalhaoDoCroqui(pontos) {
    const form = document.getElementById('formTalhao');
    form.reset();
    document.getElementById('btnExcluirTalhao').classList.add('d-none');
    form.querySelector('[name=id]').value = 0;
    form.querySelector('[name=propriedade_id]').value = Croqui.prop.id;
    form.querySelector('[name=contorno]').value = JSON.stringify(pontos);
    form.dataset.origem = 'croqui';
    Clientes._imoveisNoModalTalhao([{ id: Croqui.imovel.id, rotulo: Croqui.imovel.rotulo }], Croqui.imovel.id);
    Clientes._modoTalhaoModal({ croqui: true, medida: Croqui.areaHa(pontos) });
    document.getElementById('btnTalhaoParaPlantio').classList.add('d-none');
    new bootstrap.Modal('#modalTalhao').show();
  },

  /**
   * Cadastrou como TALHÃO o que era a ÁREA DE PLANTIO (pedido do teste de campo):
   * o desenho do talhão vira a área de plantio do imóvel (substitui a atual) e,
   * se o usuário quiser, o talhão é excluído (com histórico ele fica, avisado).
   * Núcleo comum do modal do talhão e do croqui. Devolve a resposta ou null.
   */
  async _converterTalhaoEmPlantio(t) {
    const nome = t.nome || 'este talhão';
    if (!confirm(`Transformar o desenho do talhão "${nome}" em uma ÁREA DE PLANTIO do imóvel (com este nome)?\n\nAs outras áreas de plantio já desenhadas continuam como estão.`)) return null;
    const excluir = confirm(`Excluir o talhão "${nome}" depois de virar área de plantio?\n\nOK = excluir (o cadastro estava errado)\nCancelar = manter o talhão também`);
    try {
      const fd = new FormData();
      fd.append('id', t.id);
      fd.append('excluir', excluir ? '1' : '0');
      const r = await App.json('index.php?r=clientes/talhao-para-plantio', { method: 'POST', body: fd });
      App.alerta(`Área de plantio "${r.nome || nome}" criada — ${Number(r.area_gps).toLocaleString('pt-BR', { maximumFractionDigits: 2 })} ha`
        + (r.excluido ? '. Talhão excluído.' : (r.aviso ? '. ' + r.aviso : '.')), r.aviso ? 'warning' : 'success');
      return r;
    } catch (e) { App.alerta(e.message, 'danger'); return null; }
  },

  /** Botão "Virar área de plantio" do modal do talhão (só no editar, com desenho). */
  async talhaoParaPlantio() {
    const form = document.getElementById('formTalhao');
    const t = { id: Number(form.querySelector('[name=id]').value), nome: form.querySelector('[name=nome]').value };
    if (!t.id) return;
    const r = await Clientes._converterTalhaoEmPlantio(t);
    if (!r) return;
    bootstrap.Modal.getInstance('#modalTalhao').hide();
    const croquiAberto = document.getElementById('modalCroqui') && document.getElementById('modalCroqui').classList.contains('show');
    if (croquiAberto && typeof Croqui !== 'undefined') await Croqui._recarregar(r.plantio_id ? Croqui._alvoPlantio(r.plantio_id) : Croqui.PLANTIO_ID);
    else if (Clientes.fichaClienteId) Clientes.ficha(Clientes.fichaClienteId);
  },

  editarTalhao(t, imoveis) {
    const form = document.getElementById('formTalhao');
    form.reset();
    form.querySelector('[name=id]').value = t.id;
    form.querySelector('[name=propriedade_id]').value = t.propriedade_id;
    form.querySelector('[name=contorno]').value = '';
    form.dataset.origem = 'ficha';
    form.querySelector('[name=nome]').value = t.nome;
    form.querySelector('[name=area_ha]').value = t.area_ha;
    if (t.cultura_id) form.querySelector('[name=cultura_id]').value = t.cultura_id;
    if (t.finalidade_id) form.querySelector('[name=finalidade_id]').value = t.finalidade_id;
    Clientes._imoveisNoModalTalhao(imoveis, t.imovel_id);
    // Área digitada só vale para talhão antigo SEM desenho; com desenho, a área é a medida
    Clientes._modoTalhaoModal({ croqui: false, medida: t.contorno ? Number(t.area_gps || t.area_ha) : null });
    document.getElementById('btnExcluirTalhao').classList.remove('d-none');
    // com desenho, o talhão pode virar a área de plantio do imóvel (cadastro errado)
    document.getElementById('btnTalhaoParaPlantio').classList.toggle('d-none', !t.contorno);
    new bootstrap.Modal('#modalTalhao').show();
  },

  /** Exclui o talhão (com visitas/lavoura de custo o servidor recusa — o histórico aponta para ele). */
  async excluirTalhao() {
    const form = document.getElementById('formTalhao');
    const id = Number(form.querySelector('[name=id]').value);
    const nome = form.querySelector('[name=nome]').value || 'este talhão';
    if (!id || !confirm(`Excluir o talhão "${nome}"? O desenho e os plantios registrados nele são apagados. Não dá para excluir talhão com visitas.`)) return;
    try {
      const fd = new FormData();
      fd.append('id', id);
      await App.json('index.php?r=clientes/excluir-talhao', { method: 'POST', body: fd });
      bootstrap.Modal.getInstance('#modalTalhao').hide();
      App.alerta('Talhão excluído.');
      if (Clientes.fichaClienteId) Clientes.ficha(Clientes.fichaClienteId);
    } catch (e) { App.alerta(e.message, 'danger'); }
  },

  /** Mostra/esconde os campos do modal de talhão conforme a origem (croqui × ficha) e se há desenho. */
  _modoTalhaoModal({ croqui, medida }) {
    const titulo = document.getElementById('modalTalhaoTitulo');
    if (titulo) titulo.textContent = croqui ? 'Novo talhão desenhado' : 'Talhão';
    const wrapImovel = document.getElementById('talhaoImovelWrap');
    if (wrapImovel) wrapImovel.classList.toggle('d-none', !!croqui); // no croqui o imóvel é o aberto
    const wrapArea = document.getElementById('talhaoAreaWrap');
    const medidaEl = document.getElementById('talhaoAreaMedida');
    const temDesenho = medida !== null && medida !== undefined;
    if (wrapArea) wrapArea.classList.toggle('d-none', temDesenho);
    if (medidaEl) {
      medidaEl.classList.toggle('d-none', !temDesenho);
      if (temDesenho) medidaEl.innerHTML = `<i class="bi bi-bounding-box-circles me-1 text-success"></i>Área medida no croqui: <strong>${Number(medida).toLocaleString('pt-BR', { maximumFractionDigits: 2 })} ha</strong>`;
    }
  },

  async salvarTalhao(ev) {
    ev.preventDefault();
    try {
      const r = await App.enviarForm(ev.target, 'index.php?r=clientes/salvar-talhao');
      bootstrap.Modal.getInstance('#modalTalhao').hide();
      if (ev.target.dataset.origem === 'croqui' && r.talhao && typeof Croqui !== 'undefined') {
        // Veio do croqui: o talhão entra na lista, vira o alvo selecionado e o desenho
        // passa a ser o gravado (o servidor pode ter prendido pontos na divisa/vizinhos)
        Croqui.talhoes.push(r.talhao);
        Croqui._montarSelect(r.talhao.id);
        Croqui.atualId = Number(r.talhao.id);
        Croqui.pontos = Croqui._contornoDe(r.talhao.id);
        Croqui._dirty = false;
        Croqui._selecionado = null;
        Croqui._botoesPorAlvo();
        Croqui.render();
        App.alerta(`Talhão "${r.talhao.nome}" criado — ${Number(r.area_gps || r.talhao.area_ha).toLocaleString('pt-BR', { maximumFractionDigits: 2 })} ha medidos.`);
        return false; // a ficha atualiza ao fechar o croqui (Croqui.fechar)
      }
      App.alerta('Talhão salvo.');
      if (Clientes.fichaClienteId) Clientes.ficha(Clientes.fichaClienteId);
    } catch (e) { App.alerta(e.message, 'danger'); }
    return false;
  },

  novoPlanoSafra(clienteId) {
    const form = document.getElementById('formPlanoSafra');
    form.reset();
    form.querySelector('[name=cliente_id]').value = clienteId;
    new bootstrap.Modal('#modalPlanoSafra').show();
  },

  async salvarPlanoSafra(ev) {
    ev.preventDefault();
    try {
      await App.enviarForm(ev.target, 'index.php?r=clientes/salvar-plano-safra');
      bootstrap.Modal.getInstance('#modalPlanoSafra').hide();
      App.alerta('Intenção de plantio registrada.');
      if (Clientes.fichaClienteId) Clientes.ficha(Clientes.fichaClienteId);
    } catch (e) { App.alerta(e.message, 'danger'); }
    return false;
  },
};

/* ============ ILUSTRAÇÕES FENOLÓGICAS (SVG local — funciona offline) ============ */

const FenologiaArte = {
  C: {
    talo: '#2e6b33', folha: '#3f9144', folhaClara: '#67ae6c', dourado: '#c9a13b',
    douradoEscuro: '#8d6e2f', flor: '#8e6cd0', grao: '#e3c26a', solo: '#a98352', cabelo: '#b06e2a',
  },

  /** SVG do estágio (estilizado): a cultura define o "tipo" de planta desenhada. */
  svg(culturaId, estagio) {
    const ordem = Math.max(1, Number(estagio.ordem) || 1);
    let corpo;
    if (Number(culturaId) === 2) corpo = this._milho(ordem);
    else if (Number(culturaId) === 3) corpo = this._trigo(ordem);
    else corpo = this._soja(ordem); // soja e demais dicotiledôneas
    return `<svg viewBox="0 0 240 200" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Ilustração do estágio">
      <path d="M24,180 H216" stroke="${this.C.solo}" stroke-width="5" stroke-linecap="round"/>
      <path d="M40,186 h14 M72,186 h10 M160,186 h14 M196,186 h9" stroke="${this.C.solo}" stroke-width="3" stroke-linecap="round" opacity=".5"/>
      ${corpo}</svg>`;
  },

  _trifolio(x, y, lado, esc, cor) {
    const dx = 26 * esc * lado;
    const fx = x + dx, fy = y - 3 * esc;
    return `<path d="M${x},${y} Q${x + dx / 2},${y - 6 * esc} ${fx},${fy}" stroke="${this.C.talo}" stroke-width="2" fill="none"/>
      <ellipse cx="${fx + 7 * lado * esc}" cy="${fy}" rx="${9 * esc}" ry="${5.5 * esc}" fill="${cor}"/>
      <ellipse cx="${fx}" cy="${fy - 6 * esc}" rx="${7 * esc}" ry="${4.5 * esc}" fill="${cor}" transform="rotate(${-30 * lado} ${fx} ${fy - 6 * esc})"/>
      <ellipse cx="${fx}" cy="${fy + 6 * esc}" rx="${7 * esc}" ry="${4.5 * esc}" fill="${cor}" transform="rotate(${30 * lado} ${fx} ${fy + 6 * esc})"/>`;
  },

  _vagem(x, y, lado, esc, cor) {
    return `<rect x="${x - 3}" y="${y}" width="6" height="${16 * esc}" rx="3" fill="${cor}"
              transform="rotate(${18 * lado} ${x} ${y})"/>`;
  },

  _soja(ordem) {
    const p = {
      1: { alt: 30, folhas: 0 }, 2: { alt: 62, folhas: 3 }, 3: { alt: 96, folhas: 5 },
      4: { alt: 112, folhas: 5, flores: 1 }, 5: { alt: 122, folhas: 6, flores: 1, vag: .7 },
      6: { alt: 126, folhas: 6, vag: 1 }, 7: { alt: 126, folhas: 6, vag: 1.25 },
      8: { alt: 118, folhas: 4, vag: 1.25, seca: 1 },
    }[ordem] || { alt: 90, folhas: 4 };
    const folha = p.seca ? this.C.dourado : this.C.folha;
    const vagemCor = p.seca ? this.C.douradoEscuro : this.C.folhaClara;
    let s = `<path d="M120,180 Q116,${180 - p.alt * .55} 120,${180 - p.alt}" stroke="${p.seca ? this.C.douradoEscuro : this.C.talo}" stroke-width="4" fill="none" stroke-linecap="round"/>`;
    if (!p.folhas) { // emergência: cotilédones
      s += `<ellipse cx="112" cy="${178 - p.alt}" rx="8" ry="5" fill="${this.C.folhaClara}"/>
            <ellipse cx="128" cy="${178 - p.alt}" rx="8" ry="5" fill="${this.C.folhaClara}"/>`;
      return s;
    }
    for (let i = 0; i < p.folhas; i++) {
      const t = (i + 1) / (p.folhas + .6);
      const y = 180 - p.alt * (0.28 + 0.72 * t);
      const lado = i % 2 ? -1 : 1;
      const esc = 1.15 - t * 0.35;
      s += this._trifolio(120, y, lado, esc, i === p.folhas - 1 && !p.seca ? this.C.folhaClara : folha);
      if (p.flores && t > 0.45) s += `<circle cx="${120 + 6 * lado}" cy="${y - 2}" r="3" fill="${this.C.flor}"/>`;
      if (p.vag && t > 0.3) s += this._vagem(120 + 5 * lado, y + 2, lado, p.vag, vagemCor);
    }
    return s;
  },

  _milho(ordem) {
    const p = {
      1: { alt: 28, folhas: 2 }, 2: { alt: 62, folhas: 4 }, 3: { alt: 98, folhas: 6 },
      4: { alt: 132, folhas: 7, pend: .5 }, 5: { alt: 152, folhas: 7, pend: 1, cabelo: 1 },
      6: { alt: 152, folhas: 7, pend: 1, espiga: 1 }, 7: { alt: 152, folhas: 6, pend: 1, espiga: 1, seca: 1 },
    }[ordem] || { alt: 90, folhas: 5 };
    const folha = p.seca ? this.C.dourado : this.C.folha;
    const topo = 180 - p.alt;
    let s = `<path d="M120,180 V${topo}" stroke="${p.seca ? this.C.douradoEscuro : this.C.talo}" stroke-width="6" stroke-linecap="round"/>`;
    for (let i = 0; i < p.folhas; i++) {
      const t = (i + 1) / (p.folhas + 1);
      const y = 180 - p.alt * (0.18 + 0.78 * t);
      const lado = i % 2 ? -1 : 1;
      const alc = (46 - 18 * t) * lado;
      s += `<path d="M120,${y} Q${120 + alc * .55},${y - 22} ${120 + alc},${y - 4}" stroke="${folha}" stroke-width="6" fill="none" stroke-linecap="round"/>`;
    }
    if (p.pend) { // pendão
      const h = 16 * p.pend;
      s += `<path d="M120,${topo} v-${h} M120,${topo - h * .4} l-9,-${h * .55} M120,${topo - h * .4} l9,-${h * .55}"
              stroke="${this.C.dourado}" stroke-width="3" fill="none" stroke-linecap="round"/>`;
    }
    const ey = topo + p.alt * 0.42;
    if (p.cabelo && !p.espiga) { // boneca com cabelos
      s += `<ellipse cx="132" cy="${ey}" rx="7" ry="13" fill="${this.C.folhaClara}"/>
            <path d="M132,${ey - 12} q3,-7 1,-11 M135,${ey - 11} q4,-5 4,-9" stroke="${this.C.cabelo}" stroke-width="2" fill="none" stroke-linecap="round"/>`;
    }
    if (p.espiga) {
      s += `<ellipse cx="134" cy="${ey}" rx="9" ry="16" fill="${this.C.grao}"/>
            <path d="M128,${ey - 13} q6,-4 12,0" stroke="${p.seca ? this.C.dourado : this.C.folhaClara}" stroke-width="4" fill="none"/>
            <path d="M131,${ey - 9} v19 M137,${ey - 9} v19" stroke="${this.C.douradoEscuro}" stroke-width="1.2" opacity=".55"/>`;
    }
    return s;
  },

  _espigaTrigo(x, y, cor) {
    let g = `<path d="M${x},${y + 4} l4,-16 M${x},${y - 8} l7,-9 M${x + 2},${y - 10} l6,-11" stroke="${cor}" stroke-width="1.4" fill="none"/>`;
    for (let i = 0; i < 4; i++) {
      g += `<ellipse cx="${x - 3}" cy="${y - i * 5}" rx="3.4" ry="3" fill="${cor}"/>
            <ellipse cx="${x + 3}" cy="${y - i * 5 - 2}" rx="3.4" ry="3" fill="${cor}"/>`;
    }
    return g;
  },

  _trigo(ordem) {
    const p = {
      1: { alt: 36, perf: 3 }, 2: { alt: 58, perf: 6 }, 3: { alt: 104, perf: 5 },
      4: { alt: 132, perf: 5, espiga: 1 }, 5: { alt: 136, perf: 5, espiga: 1, claro: 1 },
      6: { alt: 130, perf: 5, espiga: 1, seca: 1 },
    }[ordem] || { alt: 80, perf: 4 };
    const cor = p.seca ? this.C.douradoEscuro : this.C.talo;
    const corEspiga = p.seca ? this.C.dourado : (p.claro ? this.C.grao : this.C.folhaClara);
    let s = '';
    for (let i = 0; i < p.perf; i++) {
      const esp = (i - (p.perf - 1) / 2) * 14;
      const alt = p.alt * (1 - Math.abs(esp) / 220);
      const tx = 120 + esp * 2.2, ty = 180 - alt;
      s += `<path d="M120,180 Q${120 + esp},${180 - alt * .6} ${tx},${ty}" stroke="${cor}" stroke-width="2.6" fill="none" stroke-linecap="round"/>`;
      if (p.espiga) s += this._espigaTrigo(tx, ty, corEspiga);
      else s += `<path d="M${tx},${ty} q${esp > 0 ? 8 : -8},-6 ${esp > 0 ? 12 : -12},-2" stroke="${p.seca ? this.C.dourado : this.C.folha}" stroke-width="2.6" fill="none" stroke-linecap="round"/>`;
    }
    return s;
  },
};

/* ============================== PLANTIOS (Fase 6E) ============================== */

const Plantios = {
  abrir(talhaoId, culturaId, nomeTalhao, finalidadeId) {
    const form = document.getElementById('formPlantio');
    if (!form) return;
    form.reset();
    form.querySelector('[name=talhao_id]').value = talhaoId;
    if (culturaId) form.querySelector('[name=cultura_id]').value = culturaId;
    // v40: sugere a finalidade atual do talhão (grão, silagem...) — pode trocar nesta safra
    const fin = form.querySelector('[name=finalidade_id]');
    if (fin && finalidadeId) fin.value = finalidadeId;
    document.getElementById('plantioTalhaoNome').textContent = nomeTalhao || '';
    new bootstrap.Modal('#modalPlantio').show();
  },

  async salvar(ev) {
    ev.preventDefault();
    try {
      await App.enviarForm(ev.target, 'index.php?r=plantios/salvar');
      bootstrap.Modal.getInstance('#modalPlantio').hide();
      App.alerta('Plantio registrado — linha do tempo da cultura ativada.');
      if (typeof Clientes !== 'undefined' && Clientes.fichaClienteId) Clientes.ficha(Clientes.fichaClienteId);
    } catch (e) {
      App.alerta(navigator.onLine ? e.message : 'Sem conexão — registre o plantio quando estiver online.', 'warning');
    }
    return false;
  },

  colheita(plantioId, nomeTalhao) {
    const form = document.getElementById('formColheita');
    if (!form) return;
    form.reset();
    form.querySelector('[name=id]').value = plantioId;
    document.getElementById('colheitaTalhaoNome').textContent = nomeTalhao || '';
    new bootstrap.Modal('#modalColheita').show();
  },

  async salvarColheita(ev) {
    ev.preventDefault();
    try {
      await App.enviarForm(ev.target, 'index.php?r=plantios/encerrar');
      bootstrap.Modal.getInstance('#modalColheita').hide();
      App.alerta('Colheita registrada — plantio encerrado.');
      if (typeof Clientes !== 'undefined' && Clientes.fichaClienteId) Clientes.ficha(Clientes.fichaClienteId);
    } catch (e) {
      App.alerta(navigator.onLine ? e.message : 'Sem conexão — registre a colheita quando estiver online.', 'warning');
    }
    return false;
  },
};

/* ==================== CROQUI DAS PROPRIEDADES (Fase 6A) ==================== */

const Croqui = {
  CORES: ['#2e7d32', '#c05e11', '#00695c', '#9a7d0a', '#5d4037', '#455a64'],
  COR_PROP: '#e6b400',
  COR_LIMITE: '#ff6d00', // laranja forte: a divisa SALVA do imóvel quando ela é o LIMITE do que se desenha (área de plantio/talhão)
  COR_PLANTIO: '#7cb342', // verde: ÁREA DE PLANTIO do imóvel (o que dá para plantar dentro da divisa) — v40
  COR_EDICAO: '#00e5ff', // ciano: a divisa que VOCÊ desenha/ajusta (contrasta com o amarelo do CAR)
  prop: null,          // propriedade (nome, sede lat/lng, município/UF/linha p/ "Ir para")
  imovel: null,        // v40: IMÓVEL (CAR) em edição — divisa, área de plantio, nº do CAR
  outros: [],          // v40: divisas dos OUTROS imóveis da mesma propriedade (só contexto, não editáveis)
  talhoes: [],
  tiles: null,
  atualId: 0,          // 0 = divisa do IMÓVEL (CAR); -1 = ÁREA DE PLANTIO do imóvel; >0 = talhão
  /**
   * FLUXO GUIADO (v45, pedido do teste de campo): 1 Divisa do imóvel (CAR ajustado
   * pelo usuário) → 2 Áreas de plantio (dentro da divisa) → 3 Talhões (dentro de UMA
   * área de plantio). Cada camada é presa à anterior; o seletor mostra só os alvos da etapa.
   */
  etapa: 1,
  PLANTIO_ID: -1, // "Nova área de plantio (desenhar)" — v44: as áreas gravadas são alvos -(PLANTIO_BASE + id)
  PLANTIO_BASE: 1000,
  areasPlantio: [],
  _alvoPlantio(id) { return -(Croqui.PLANTIO_BASE + Number(id)); },
  _plantioIdDe(alvo) { return Number(alvo) <= -Croqui.PLANTIO_BASE ? -Number(alvo) - Croqui.PLANTIO_BASE : 0; },
  _ehPlantio(alvo) { alvo = Number(alvo); return alvo === Croqui.PLANTIO_ID || alvo <= -Croqui.PLANTIO_BASE; },
  _areaPlantioDe(alvo) { const id = Croqui._plantioIdDe(alvo); return id ? Croqui.areasPlantio.find(a => Number(a.id) === id) : null; },
  NOVO_ID: -2,         // "➕ Novo talhão (desenhar)": desenha primeiro, dá nome/cultura ao salvar
  SNAP_DIVISA_M: 6,    // ponto a até 6 m da divisa é encaixado NA divisa (o talhão margeia o CAR)
  _undo: [],           // pilha de estados p/ Desfazer (uma ação = um estado, mesmo que insira vários pontos)
  pontos: [],
  vista: null,         // {z, cx, cy} em coordenadas de mundo Web Mercator (0..1); z pode ser fracionário (pinça)
  watchId: null,
  _dirty: false,
  _arrasto: null,
  _arrastoIni: null,   // posição de tela ao pegar o vértice (distingue TOQUE de ARRASTO)
  _arrastoMoveu: false,
  _selecionado: null,  // índice do ponto tocado (mostra o botão de remover)
  _ultimoTap: null,    // último toque {tipo:'vertice'|'linha', idx/x/y, t} p/ detectar DUPLO toque
  _DUPLO_MS: 500,      // intervalo máximo entre os dois toques (folga p/ toque no celular)
  _DUPLO_PX: 30,       // distância máxima (px na tela) entre os dois toques
  _pan: null,
  _pinch: null,        // zoom de pinça (dois dedos)
  _ponteiros: new Map(),
  _eventosOk: false,
  carLayer: [],        // imóveis do CAR próximos (overlay p/ selecionar no mapa)
  carLayerOn: false,   // overlay do CAR visível/ativo (toque na área seleciona)
  _carLayerCentro: null, // [lat,lng] do último carregamento (evita recarregar à toa)
  CAR_ZOOM_MIN: 13,    // só plota os imóveis do CAR com zoom aproximado (afastado vira ruído/peso)

  /* --- Web Mercator (mesma projeção dos tiles de satélite) --- */
  _wx(p) { return (Number(p[1]) + 180) / 360; },
  _wy(p) { const s = Math.sin(Number(p[0]) * Math.PI / 180); return 0.5 - Math.log((1 + s) / (1 - s)) / (4 * Math.PI); },
  _geo(wx, wy) {
    return [Math.atan(Math.sinh(Math.PI * (1 - 2 * wy))) * 180 / Math.PI, wx * 360 - 180];
  },
  _escala() { return 256 * Math.pow(2, this.vista.z); },
  _paraTela(p, larg, alt) {
    const e = this._escala();
    return [(this._wx(p) - this.vista.cx) * e + larg / 2, (this._wy(p) - this.vista.cy) * e + alt / 2];
  },
  _paraGeo(x, y, larg, alt) {
    const e = this._escala();
    return this._geo(this.vista.cx + (x - larg / 2) / e, this.vista.cy + (y - alt / 2) / e);
  },

  // v40: o croqui é por IMÓVEL (CAR). Abre com a divisa do imóvel; o seletor tem
  // também a ÁREA DE PLANTIO e os talhões desse imóvel.
  async abrir(imovelId, opts = {}) {
    let dados;
    try {
      dados = await App.json('index.php?r=clientes/croqui-dados&imovel_id=' + Number(imovelId));
    } catch (e) {
      App.alerta(navigator.onLine ? e.message : 'Abra o croqui com conexão ao menos uma vez — depois a marcação por GPS funciona sem sinal.', 'warning');
      return;
    }
    Croqui.prop = dados.propriedade;
    Croqui.imovel = dados.imovel;
    Croqui.outros = dados.outros || [];
    Croqui.talhoes = dados.talhoes;
    Croqui.areasPlantio = dados.areas_plantio || [];
    Croqui.tiles = dados.tiles && dados.tiles.url ? dados.tiles : null;
    Croqui._dirty = false;
    // Garante a base do CAR do município no aparelho para o "CAR aqui" offline
    if (typeof Offline !== 'undefined') Offline.baixarCarMunicipio();
    document.getElementById('croquiPropNome').textContent = dados.propriedade.nome
      + (Croqui.outros.length || dados.imovel.nome ? ' · ' + dados.imovel.rotulo : ''); // só o apelido (o nº do CAR fica na ficha)
    // pré-preenche o "Ir para" com o endereço do produtor (município/UF/linha)
    Croqui._setIrPara(dados.imovel.municipio || dados.propriedade.municipio, dados.propriedade.estado, dados.propriedade.linha);
    // Fluxo guiado: abre na primeira etapa pendente (sem divisa → 1; sem área → 2; senão 3)
    Croqui.etapa = opts.novoTalhao && Croqui.areasPlantio.length ? 3 : Croqui._etapaMinima();
    let alvoInicial = 0;
    if (Croqui.etapa === 2) alvoInicial = Croqui.areasPlantio.length ? Croqui._alvoPlantio(Croqui.areasPlantio[0].id) : Croqui.PLANTIO_ID;
    else if (Croqui.etapa === 3) { const t = Croqui.talhoes.find(x => Croqui._contornoDe(x.id).length >= 3) || Croqui.talhoes[0]; alvoInicial = opts.novoTalhao ? Croqui.NOVO_ID : (t ? Number(t.id) : Croqui.NOVO_ID); }
    Croqui._montarSelect(alvoInicial);
    Croqui.atualId = alvoInicial;
    Croqui.pontos = Croqui._contornoDe(alvoInicial);
    Croqui._carCod = '';
    Croqui._selecionado = null;
    Croqui.carLayer = []; Croqui.carLayerOn = false; Croqui._carLayerCentro = null;
    Croqui._undo = [];
    Croqui._botoesPorAlvo();
    { const b = document.getElementById('croquiCarMapaBtn'); if (b) b.classList.remove('active'); }
    document.getElementById('croquiUsarArea').checked = true; // o desenho define a área (desmarque só se a oficial for outra)
    document.getElementById('croquiModoManual').checked = true;
    Croqui._refletirModo(); // split button mostra "Manual (toque)" ao abrir
    Croqui._prepararEventos();
    Croqui.vista = null; // recalcula o enquadramento ao abrir
    new bootstrap.Modal('#modalCroqui').show();
    setTimeout(() => {
      // Tela cheia lembrada do último uso neste aparelho
      let cheio = false; try { cheio = localStorage.getItem('croqui_tela_cheia') === '1'; } catch (e) { /* sem storage */ }
      document.getElementById('modalCroqui').classList.toggle('croqui-cheio', cheio);
      Croqui._enquadrar();
      Croqui.render();
      // Propriedade sem nenhuma referência (nova): mostra o satélite na
      // posição atual para já dar para tocar os pontos ou usar "CAR aqui".
      if (!Croqui.vista) Croqui._centrarNoGps();
      // Já traz a divisa oficial do CAR da sede, se ainda não houver divisa.
      Croqui._autoCarSede();
      // E mostra todos os imóveis do CAR no mapa p/ o técnico escolher a área do produtor.
      Croqui._autoMostrarCar();
      // "+ Talhão" na ficha: já entra no modo "novo talhão" (desenha, depois dá o nome)
      if (opts.novoTalhao) {
        if (Croqui.etapa === 3) App.alerta('Toque dentro de uma área de plantio para marcar os cantos do talhão. Ao salvar, você dá o nome, a cultura e a finalidade.', 'info');
        else App.alerta(Croqui.etapa === 1 ? 'Antes do talhão: traga a divisa do CAR (etapa 1) e marque as áreas de plantio (etapa 2).' : 'Antes do talhão: marque ao menos uma área de plantio (etapa 2). O talhão é desenhado dentro dela.', 'warning');
      }
      Croqui._carLayerNoLimite(Croqui.atualId === 0);
    }, 250);
  },

  /**
   * Botões que dependem do alvo: talhão → "Virar área de plantio" (o desenho é a
   * área de plantio, cadastro errado); área de plantio → "Copiar de talhão"
   * (carrega o desenho de um talhão para ajustar e salvar).
   */
  _botoesPorAlvo() {
    const virar = document.getElementById('croquiVirarPlantioBtn');
    const copiar = document.getElementById('croquiCopiarTalhaoWrap');
    const menu = document.getElementById('croquiCopiarTalhaoMenu');
    if (!virar || !copiar || !menu) return;
    virar.classList.toggle('d-none', !(Croqui.atualId > 0 && Croqui._contornoDe(Croqui.atualId).length >= 3));
    const comDesenho = Croqui.talhoes.filter(t => Croqui._contornoDe(t.id).length >= 3);
    copiar.classList.toggle('d-none', !(Croqui._ehPlantio(Croqui.atualId) && comDesenho.length));
    const renomear = document.getElementById('croquiRenomearBtn');
    if (renomear) renomear.classList.toggle('d-none', !Croqui._plantioIdDe(Croqui.atualId));
    menu.innerHTML = comDesenho.map(t =>
      `<li><button type="button" class="dropdown-item" onclick="Croqui.copiarDeTalhao(${Number(t.id)})">${App.escapeHtml(t.nome)}<div class="small text-muted">${Number(t.area_gps || t.area_ha || 0).toLocaleString('pt-BR', { maximumFractionDigits: 2 })} ha</div></button></li>`).join('');
  },

  /** Alvo = talhão: o desenho dele vira a ÁREA DE PLANTIO do imóvel (núcleo em Clientes). */
  async talhaoParaPlantio() {
    const t = Croqui.talhoes.find(x => Number(x.id) === Croqui.atualId);
    if (!t) return;
    if (Croqui._dirty) { App.alerta('Salve (ou desfaça) o ajuste do talhão antes de transformá-lo em área de plantio.', 'warning'); return; }
    if (!confirm('O desenho deste talhão vai virar uma área de plantio (etapa 2). Continuar?')) return;
    const r = await Clientes._converterTalhaoEmPlantio(t);
    if (r) await Croqui._recarregar(r.plantio_id ? Croqui._alvoPlantio(r.plantio_id) : Croqui.PLANTIO_ID);
  },

  /** v44: renomeia a área de plantio selecionada. */
  async renomearAreaPlantio() {
    const a = Croqui._areaPlantioDe(Croqui.atualId);
    if (!a) return;
    const nome = prompt('Nome da área de plantio:', a.nome || '');
    if (nome === null || !nome.trim()) return;
    try {
      const fd = new FormData(); fd.append('id', a.id); fd.append('nome', nome.trim());
      const r = await App.json('index.php?r=clientes/renomear-area-plantio', { method: 'POST', body: fd });
      a.nome = r.nome;
      Croqui._montarSelect(Croqui.atualId);
      Croqui.render();
      App.alerta('Área de plantio renomeada.');
    } catch (e) { App.alerta(e.message, 'danger'); }
  },

  /** Alvo = área de plantio: carrega o desenho de um talhão para ajustar e salvar. */
  copiarDeTalhao(id) {
    if (!Croqui._ehPlantio(Croqui.atualId)) return;
    const t = Croqui.talhoes.find(x => Number(x.id) === Number(id));
    const pts = Croqui._contornoDe(id);
    if (!t || pts.length < 3) return;
    if (Croqui.pontos.length >= 3 && !confirm(`Substituir o desenho atual da área de plantio pelo do talhão "${t.nome}"?`)) return;
    Croqui._snapshot();
    Croqui.pontos = pts.map(p => [Number(p[0]), Number(p[1])]);
    Croqui._selecionado = null;
    Croqui._dirty = true;
    Croqui.render();
    App.alerta(`Desenho do talhão "${t.nome}" carregado como área de plantio. Ajuste os pontos se quiser (arraste; dois toques na linha inserem um ponto) e toque em "Salvar croqui". Depois, exclua o talhão se ele não existe de fato.`, 'info');
  },

  /** Recarrega os dados do croqui (após conversão/exclusão) mantendo a vista; abre no alvo pedido. */
  async _recarregar(alvo) {
    try {
      const dados = await App.json('index.php?r=clientes/croqui-dados&imovel_id=' + Number(Croqui.imovel.id));
      Croqui.prop = dados.propriedade;
      Croqui.imovel = dados.imovel;
      Croqui.outros = dados.outros || [];
      Croqui.talhoes = dados.talhoes;
      Croqui.areasPlantio = dados.areas_plantio || [];
    } catch (e) { App.alerta(e.message, 'danger'); return; }
    Croqui.etapa = Croqui._etapaDe(alvo);
    Croqui._montarSelect(alvo);
    Croqui.atualId = Number(alvo);
    Croqui.pontos = Croqui._contornoDe(Croqui.atualId);
    Croqui._undo = [];
    Croqui._dirty = false;
    Croqui._carCod = '';
    Croqui._selecionado = null;
    Croqui._carLayerNoLimite(Croqui.atualId === 0);
    Croqui._botoesPorAlvo();
    Croqui.render();
  },

  /** Etapa a que um alvo pertence: 1 divisa, 2 área de plantio, 3 talhão. */
  _etapaDe(alvo) { alvo = Number(alvo); return alvo === 0 ? 1 : (Croqui._ehPlantio(alvo) ? 2 : 3); },
  /** Primeira etapa pendente do imóvel (onde o croqui abre por padrão). */
  _etapaMinima() {
    if (Croqui._contornoDe(0).length < 3) return 1;
    if (!Croqui.areasPlantio.length) return 2;
    return 3;
  },
  _temDivisa() { return Croqui._contornoDe(0).length >= 3; },

  /** Monta o seletor de alvos SÓ da etapa atual (2: áreas + nova; 3: talhões + novo). */
  _montarSelect(selecionar) {
    const sel = document.getElementById('croquiTalhao');
    if (selecionar !== undefined) Croqui.etapa = Croqui._etapaDe(selecionar);
    let html = '';
    if (Croqui.etapa === 1) {
      html = '<option value="0">🏠 Divisa do imóvel (CAR) — área total</option>';
    } else if (Croqui.etapa === 2) {
      html = Croqui.areasPlantio.map(a =>
        `<option value="${Croqui._alvoPlantio(a.id)}">🌱 ${App.escapeHtml(a.nome)} (${Number(a.area_gps || 0).toLocaleString('pt-BR', { maximumFractionDigits: 1 })} ha)</option>`).join('')
        + `<option value="${Croqui.PLANTIO_ID}">🌱➕ Nova área de plantio (desenhar)</option>`;
    } else {
      html = Croqui.talhoes.map(t =>
        `<option value="${Number(t.id)}">▪ ${App.escapeHtml(t.nome)}${t.cultura ? ' (' + App.escapeHtml(t.cultura) + (t.finalidade ? ' · ' + App.escapeHtml(t.finalidade) : '') + ')' : ''}</option>`).join('')
        + `<option value="${Croqui.NOVO_ID}">➕ Novo talhão (desenhar)</option>`;
    }
    sel.innerHTML = html;
    sel.classList.toggle('d-none', Croqui.etapa === 1);
    if (selecionar !== undefined) sel.value = String(selecionar);
    Croqui._atualizarEtapas();
  },

  /**
   * Vai para a etapa n (1 divisa, 2 áreas de plantio, 3 talhões) escolhendo o alvo
   * padrão dela. Não deixa pular o que ainda falta: sem divisa salva não há etapa 2;
   * sem área de plantio não há etapa 3.
   */
  irEtapa(n, alvo) {
    n = Number(n);
    if (Croqui._dirty) {
      if (!confirm('Há pontos não salvos nesta etapa — descartar e trocar de etapa?')) return;
      Croqui._dirty = false;
    }
    if (n >= 2 && !Croqui._temDivisa()) { App.alerta('Etapa 1 primeiro: traga a divisa do CAR e ajuste os pontos da área total. As áreas de plantio ficam dentro dela.', 'warning'); n = 1; }
    if (n === 3 && !Croqui.areasPlantio.length) { App.alerta('Etapa 2 primeiro: marque ao menos uma área de plantio. Os talhões ficam dentro das áreas de plantio.', 'warning'); n = 2; }
    Croqui.etapa = n;
    if (alvo === undefined) {
      if (n === 1) alvo = 0;
      else if (n === 2) alvo = Croqui.areasPlantio.length ? Croqui._alvoPlantio(Croqui.areasPlantio[0].id) : Croqui.PLANTIO_ID;
      else { const t = Croqui.talhoes.find(x => Croqui._contornoDe(x.id).length >= 3) || Croqui.talhoes[0]; alvo = t ? Number(t.id) : Croqui.NOVO_ID; }
    }
    Croqui._montarSelect(alvo);
    document.getElementById('croquiTalhao').value = String(alvo);
    Croqui.trocarTalhao();
  },

  /** Pinta o passo a passo (feito / atual / pendente), a dica da etapa e os botões Voltar/Próxima. */
  _atualizarEtapas() {
    const feito = [null, Croqui._temDivisa(), Croqui.areasPlantio.length > 0, Croqui.talhoes.some(t => Croqui._contornoDe(t.id).length >= 3)];
    [1, 2, 3].forEach(n => {
      const b = document.getElementById('croquiEtapa' + n);
      if (!b) return;
      b.classList.toggle('btn-success', Croqui.etapa === n);
      b.classList.toggle('btn-outline-success', Croqui.etapa !== n && feito[n]);
      b.classList.toggle('btn-outline-secondary', Croqui.etapa !== n && !feito[n]);
      const ic = b.querySelector('.croqui-etapa-icone');
      if (ic) ic.className = 'croqui-etapa-icone bi ' + (feito[n] ? 'bi-check-circle-fill' : (Croqui.etapa === n ? 'bi-pencil-fill' : 'bi-circle'));
    });
    const dicas = {
      1: '<strong>Etapa 1 — Área total do imóvel.</strong> Traga a divisa do CAR (<em>CAR no mapa</em>, <em>CAR aqui</em> ou <em>CAR pela sede</em>) e <strong>ajuste os pontos</strong> até a linha ciano ser a área real da propriedade. Salve: essa marcação é o limite de tudo que vem depois.',
      2: '<strong>Etapa 2 — Áreas de plantio.</strong> Dentro da divisa salva (laranja), marque cada pedaço que dá para plantar (Campo, Morro…). Pontos fora da divisa são puxados para a borda e uma área não cobre outra. Salve cada área com um nome.',
      3: '<strong>Etapa 3 — Talhões.</strong> Escolha <em>Novo talhão</em> e toque <strong>dentro de uma área de plantio</strong>: o talhão fica preso a ela (laranja) e não pode passar por cima de outro talhão. Ao salvar, informe nome, cultura e finalidade.',
    };
    const dica = document.getElementById('croquiEtapaDica');
    if (dica) dica.innerHTML = dicas[Croqui.etapa] || '';
    const ant = document.getElementById('croquiEtapaAnterior'), prox = document.getElementById('croquiEtapaProxima');
    if (ant) ant.classList.toggle('d-none', Croqui.etapa <= 1);
    if (prox) {
      prox.classList.toggle('d-none', Croqui.etapa >= 3);
      const pode = Croqui.etapa === 1 ? feito[1] : feito[2];
      prox.disabled = !pode;
      prox.title = pode ? '' : (Croqui.etapa === 1 ? 'Salve a divisa para seguir' : 'Salve ao menos uma área de plantio para seguir');
    }
    const grupoCar = document.getElementById('croquiCarGrupo');
    if (grupoCar) grupoCar.classList.toggle('d-none', Croqui.etapa !== 1);
  },

  /**
   * Ao abrir: se a propriedade ainda não tem divisa e tem sede cadastrada,
   * puxa a divisa oficial do CAR automaticamente (silencioso se não achar).
   */
  async _autoCarSede() {
    if (Croqui.atualId !== 0 || Croqui.pontos.length >= 3) return; // não sobrescreve divisa existente
    const lat = Croqui.prop && Croqui.prop.latitude, lng = Croqui.prop && Croqui.prop.longitude;
    if (lat === null || lat === undefined || lng === null || lng === undefined) return;
    await Croqui._aplicarCarDoPonto(Number(lat), Number(lng), 'auto');
  },

  /** Centraliza o mapa na posição atual quando ainda não há referência. */
  _centrarNoGps() {
    if (!navigator.geolocation) return;
    const status = document.getElementById('croquiGpsStatus');
    if (status) { status.classList.remove('d-none'); status.textContent = 'Localizando você…'; }
    navigator.geolocation.getCurrentPosition(pos => {
      if (Croqui.vista) { if (status) status.classList.add('d-none'); return; }
      const p = [pos.coords.latitude, pos.coords.longitude];
      Croqui.vista = { z: 16, cx: Croqui._wx(p), cy: Croqui._wy(p) }; // escala de fazenda
      if (status) status.classList.add('d-none');
      Croqui.render();
    }, () => {
      if (status) status.textContent = 'GPS indisponível — use "Caminhar a divisa"';
    }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 10000 });
  },

  // Contorno GRAVADO de um alvo: 0 = divisa do imóvel, -1 = área de plantio, >0 = talhão
  _contornoDe(id) {
    id = Number(id);
    let json = null;
    if (id === 0) json = Croqui.imovel && Croqui.imovel.contorno;
    else if (id === Croqui.PLANTIO_ID) json = null; // nova área de plantio: começa vazia
    else if (Croqui._ehPlantio(id)) { const a = Croqui._areaPlantioDe(id); json = a && a.contorno; }
    else { const t = Croqui.talhoes.find(x => Number(x.id) === id); json = t && t.contorno; }
    try { return json ? JSON.parse(json) : []; } catch (e) { return []; }
  },

  /** Contorno ATUAL de um alvo: o que está sendo editado, senão o gravado. */
  _contornoAtual(id) {
    return Number(id) === Croqui.atualId ? Croqui.pontos : Croqui._contornoDe(id);
  },

  _todosPontosBase() {
    const todos = [];
    todos.push(...Croqui._contornoAtual(0));
    Croqui.areasPlantio.forEach(a => todos.push(...Croqui._contornoAtual(Croqui._alvoPlantio(a.id))));
    if (Croqui.atualId === Croqui.PLANTIO_ID) todos.push(...Croqui.pontos);
    Croqui.talhoes.forEach(t => todos.push(...Croqui._contornoAtual(t.id)));
    // outros imóveis da propriedade entram no enquadramento (o técnico vê a fazenda inteira)
    Croqui.outros.forEach(o => { try { todos.push(...(JSON.parse(o.contorno) || [])); } catch (e) { /* ignora */ } });
    if (!todos.length && Croqui.prop.latitude !== null) todos.push([Croqui.prop.latitude, Croqui.prop.longitude]);
    return todos;
  },

  /** Enquadra a vista nos pontos existentes (ou centra na sede em zoom de fazenda). */
  _enquadrar() {
    const palco = document.getElementById('croquiPalco');
    const larg = Math.max(300, palco.clientWidth), alt = Math.max(260, palco.clientHeight);
    const todos = Croqui._todosPontosBase();
    if (!todos.length) { Croqui.vista = null; return; }
    const xs = todos.map(p => Croqui._wx(p)), ys = todos.map(p => Croqui._wy(p));
    const cx = (Math.min(...xs) + Math.max(...xs)) / 2;
    const cy = (Math.min(...ys) + Math.max(...ys)) / 2;
    const spanX = Math.max(1e-9, Math.max(...xs) - Math.min(...xs));
    const spanY = Math.max(1e-9, Math.max(...ys) - Math.min(...ys));
    let z = 16; // padrão: escala de fazenda (~2 km de largura na tela)
    if (todos.length > 1) {
      z = Math.floor(Math.min(Math.log2(larg * 0.8 / (256 * spanX)), Math.log2(alt * 0.8 / (256 * spanY))));
    }
    Croqui.vista = { z: Math.max(3, Math.min(18, z)), cx, cy };
  },

  _cheio() { const m = document.getElementById('modalCroqui'); return !!m && m.classList.contains('croqui-cheio'); },

  /**
   * TELA CHEIA do mapa (pedido do teste de campo): o palco cobre a tela toda e uma
   * barra flutuante mantém área/Desfazer/Salvar/sair. A escolha fica guardada no
   * aparelho e volta sozinha ao abrir o próximo croqui.
   */
  telaCheia(forcar) {
    const m = document.getElementById('modalCroqui');
    if (!m) return;
    const ativo = typeof forcar === 'boolean' ? forcar : !m.classList.contains('croqui-cheio');
    m.classList.toggle('croqui-cheio', ativo);
    try { localStorage.setItem('croqui_tela_cheia', ativo ? '1' : '0'); } catch (e) { /* sem storage */ }
    Croqui.render(); // o palco mudou de tamanho: reenquadra os tiles e o desenho
  },

  zoom(delta) {
    if (!Croqui.vista) return;
    // Botões andam em níveis inteiros mesmo depois de um zoom de pinça fracionário
    Croqui.vista.z = Math.max(3, Math.min(19, Math.round(Croqui.vista.z) + delta));
    Croqui.render();
    Croqui._agendarRecargaCar(); // recarrega o CAR para a nova área visível
  },

  trocarTalhao() {
    const alvo = Number(document.getElementById('croquiTalhao').value);
    if (Croqui._dirty) {
      // Saindo da divisa com alteração não salva: a área de plantio/talhão é limitada
      // pela divisa SALVA — o que está na tela seria perdido (pedido do teste de campo)
      const msg = Croqui.atualId === 0 && alvo !== 0
        ? 'A divisa foi alterada e NÃO foi salva. A área de plantio e os talhões são limitados pela divisa SALVA — toque em "Salvar croqui" antes de trocar. Descartar o ajuste da divisa e trocar mesmo assim?'
        : 'Há pontos não salvos — descartar e trocar?';
      if (!confirm(msg)) {
        document.getElementById('croquiTalhao').value = Croqui.atualId;
        return;
      }
    }
    // REGRA (teste de campo): a área de plantio e os talhões são desenhados DENTRO da
    // área do CAR — sem a divisa não há onde desenhar. Traga o CAR primeiro.
    if (alvo !== 0 && Croqui._contornoDe(0).length < 3) {
      App.alerta('Traga primeiro a divisa do CAR (área total do imóvel). A área de plantio e os talhões são desenhados dentro dela.', 'warning');
      document.getElementById('croquiTalhao').value = Croqui.atualId;
      return;
    }
    // v45: talhão só dentro de uma área de plantio — sem área não há onde desenhar
    if (Croqui._ehTalhao(alvo) && !Croqui.areasPlantio.length) {
      App.alerta('Marque primeiro ao menos uma área de plantio (etapa 2). O talhão é desenhado dentro dela.', 'warning');
      document.getElementById('croquiTalhao').value = Croqui.atualId;
      return;
    }
    Croqui.atualId = alvo;
    Croqui.etapa = Croqui._etapaDe(alvo);
    Croqui._hostId = null;
    Croqui.pontos = Croqui._contornoDe(Croqui.atualId);
    Croqui._undo = []; // outro alvo: o Desfazer recomeça
    Croqui._dirty = false;
    Croqui._carCod = '';
    Croqui._selecionado = null;
    // O limite da área de plantio/talhão é a divisa SALVA pelo usuário, não o CAR:
    // ao sair da divisa o overlay do CAR desliga (amarelo quase igual à divisa —
    // confundia o técnico); volta sozinho ao editar a divisa de novo.
    Croqui._carLayerNoLimite(alvo === 0);
    Croqui._botoesPorAlvo();
    Croqui._atualizarEtapas();
    const rotulo = document.querySelector('label[for="croquiUsarArea"]');
    if (rotulo) rotulo.textContent = Croqui.atualId === 0
      ? 'Usar a área medida como área oficial do imóvel'
      : (Croqui._ehPlantio(Croqui.atualId)
        ? 'A área da área de plantio é a medida pelo desenho'
        : 'Usar a área medida como área oficial do talhão');
    Croqui.render();
  },

  /** Só faz sentido puxar a divisa do CAR sobre a divisa da PROPRIEDADE (área total). */
  _podeCar() {
    if (Croqui.atualId !== 0) {
      App.alerta('Selecione "🏠 Divisa do imóvel (CAR)" no seletor para trazer a divisa do CAR.', 'warning');
      return false;
    }
    return true;
  },

  /**
   * "CAR aqui": identifica o imóvel do CAR na posição atual (GPS) — online
   * pelo servidor, ou offline pela base do município no snapshot — e traz a
   * divisa oficial para o croqui da propriedade.
   */
  async carAqui() {
    if (!Croqui._podeCar()) return;
    if (!navigator.geolocation) { App.alerta('GPS indisponível neste aparelho.', 'warning'); return; }
    App.alerta('Localizando o imóvel do CAR na sua posição…', 'info');
    navigator.geolocation.getCurrentPosition(
      pos => Croqui._aplicarCarDoPonto(pos.coords.latitude, pos.coords.longitude, 'gps'),
      () => App.alerta('Não consegui obter sua posição (permita a localização).', 'warning'),
      { enableHighAccuracy: true, timeout: 12000, maximumAge: 10000 });
  },

  /**
   * "CAR pela sede": usa a posição CADASTRADA da sede da propriedade (sem GPS),
   * o mesmo ponto marcado como "sede" no mapa. Deixa o extensionista puxar a
   * divisa oficial no escritório, antes de ir à propriedade.
   */
  async carDaSede() {
    if (!Croqui._podeCar()) return;
    const lat = Croqui.prop && Croqui.prop.latitude, lng = Croqui.prop && Croqui.prop.longitude;
    if (lat === null || lat === undefined || lat === '' || lng === null || lng === undefined || lng === '') {
      App.alerta('Esta propriedade ainda não tem a posição da sede cadastrada. Informe a localização da propriedade no cadastro, ou use "CAR aqui" no local.', 'warning');
      return;
    }
    App.alerta('Localizando o imóvel do CAR na posição da sede…', 'info');
    Croqui._aplicarCarDoPonto(Number(lat), Number(lng), 'sede');
  },

  _TOL_CAR_M: 250, // tolerância p/ pegar o imóvel mais próximo quando a sede cai logo fora

  /**
   * Núcleo comum do "CAR aqui"/"CAR pela sede"/auto-abertura: busca o imóvel no
   * ponto (exato ou mais próximo dentro da tolerância) e traz a divisa. `origem`
   * = 'gps' | 'sede' | 'auto' (auto = ao abrir, falha em silêncio).
   */
  async _aplicarCarDoPonto(lat, lng, origem = 'gps') {
    let imovel = null, contexto = null, diag = null;
    try {
      if (navigator.onLine) {
        const r = await App.json(`index.php?r=clientes/car-por-ponto&lat=${lat.toFixed(7)}&lng=${lng.toFixed(7)}`);
        imovel = r.imovel; contexto = r.contexto; diag = r.diagnostico || null;
      } else {
        imovel = await OfflineView.carNoPonto(lat, lng, Croqui._TOL_CAR_M); // base do município no snapshot
      }
    } catch (e) {
      // sem conexão no meio: tenta o offline
      imovel = await OfflineView.carNoPonto(lat, lng, Croqui._TOL_CAR_M);
    }
    if (!imovel) {
      if (origem === 'auto') return; // auto-abertura: não incomoda se não achar
      let msg;
      if (diag) { // há base na região, mas o ponto ficou fora: mostra distância + município do mais próximo
        const dist = diag.dist_m >= 1000 ? (diag.dist_m / 1000).toFixed(1) + ' km' : diag.dist_m + ' m';
        msg = `O imóvel do CAR mais próximo está a ${dist} (${App.escapeHtml(diag.municipio)}/${App.escapeHtml(diag.uf)}). `
            + 'Se esta propriedade fica em outro município, importe-o na Integração ("Base do CAR por município"); '
            + 'se for esse mesmo, ajuste a localização da sede no cadastro, use "CAR aqui" no local, ou desenhe manualmente.';
      } else if (contexto === 'sem_base_perto') {
        msg = 'Não há base do CAR carregada nesta região. Peça ao Administrador para importar o município na Integração ("Base do CAR por município").';
      } else {
        msg = origem === 'sede'
          ? 'A posição da sede não caiu em nenhum imóvel do CAR. Ajuste a localização da propriedade no cadastro, use "CAR aqui" na propriedade, ou desenhe manualmente.'
          : 'Nenhum imóvel do CAR encontrado nesta posição. Confira se o município foi importado (Integração) ou desenhe manualmente.';
      }
      App.alerta(msg, 'warning');
      return;
    }
    if (Croqui.pontos.length >= 3 && !confirm('Substituir a divisa atual pela divisa oficial do CAR?')) return;
    Croqui.pontos = Croqui._maiorAnel(imovel.contorno); // divisa da propriedade = 1 anel (a maior parte)
    Croqui._selecionado = null;
    Croqui._carCod = imovel.cod || '';
    Croqui._dirty = true;
    Croqui._enquadrar();
    Croqui.render();
    const cod = imovel.cod ? ' (' + App.escapeHtml(imovel.cod) + ')' : '';
    // Grava o nº do CAR no cadastro assim que identifica (match EXATO, alta confiança),
    // sem depender de "Salvar croqui". Aproximado NÃO grava sozinho (pode ser vizinho).
    let gravouCar = false;
    if (!imovel.aproximado && imovel.cod && imovel.cod !== (Croqui.imovel.car_numero || '')) {
      gravouCar = true;
      Croqui._persistirCarNumero(imovel.cod);
    }
    if (imovel.aproximado) {
      App.alerta(`Imóvel do CAR mais próximo${cod} carregado (~${imovel.dist_m} m ${origem === 'sede' ? 'da sede' : 'do ponto'}). Confira se é o correto e ajuste antes de salvar.`, 'warning');
    } else {
      App.alerta((origem === 'auto' ? 'Divisa oficial do CAR carregada da sede' : 'Divisa do CAR carregada') + cod
        + (gravouCar ? '. Nº do CAR gravado no cadastro' : '') + '. Confira e toque em "Salvar croqui".', 'success');
    }
  },

  /** Grava o nº do CAR no cadastro da propriedade assim que identificado (online, ou fila offline). */
  async _persistirCarNumero(cod) {
    const fd = new FormData();
    fd.append('imovel_id', Croqui.imovel.id); // v40: o nº do CAR é do imóvel
    fd.append('car_numero', cod);
    try {
      await App.enviarFormOffline(fd, 'index.php?r=clientes/salvar-car-numero',
        { modulo: 'Croqui', rotulo: 'Nº do CAR — ' + (Croqui.prop.nome || '') + ' · ' + (Croqui.imovel.rotulo || '') });
      Croqui.imovel.car_numero = cod; // reflete local p/ não regravar no mesmo croqui
    } catch (e) { /* silencioso: o nº ainda vai junto ao "Salvar croqui" */ }
  },

  /** Liga/desliga o overlay dos imóveis do CAR no mapa (toque na área seleciona a divisa). */
  async toggleCarLayer() {
    Croqui.carLayerOn = !Croqui.carLayerOn;
    const btn = document.getElementById('croquiCarMapaBtn');
    if (btn) btn.classList.toggle('active', Croqui.carLayerOn);
    if (Croqui.carLayerOn) {
      if (Croqui.vista && Croqui.vista.z < Croqui.CAR_ZOOM_MIN) {
        App.alerta('Aproxime o mapa para ver os imóveis do CAR (afastado, viram muitos e pesam).', 'info');
      } else {
        if (!Croqui.carLayer.length) await Croqui._carregarCarLayer();
        App.alerta(Croqui.carLayer.length
          ? 'Imóveis do CAR no mapa. Toque na área que é do produtor para adotar a divisa.'
          : 'Nenhum imóvel do CAR carregado nesta região (importe o município na Integração).', Croqui.carLayer.length ? 'info' : 'warning');
      }
    }
    Croqui.render();
  },

  /** Caixa (bbox) da área VISÍVEL no mapa, com uma margem para arrastar sem buraco. */
  _viewportBBox(margem = 0.15) {
    const palco = document.getElementById('croquiPalco');
    const larg = Math.max(300, palco.clientWidth), alt = Math.max(260, palco.clientHeight);
    const tl = Croqui._paraGeo(0, 0, larg, alt), br = Croqui._paraGeo(larg, alt, larg, alt);
    let minLat = Math.min(tl[0], br[0]), maxLat = Math.max(tl[0], br[0]);
    let minLng = Math.min(tl[1], br[1]), maxLng = Math.max(tl[1], br[1]);
    const mLat = (maxLat - minLat) * margem, mLng = (maxLng - minLng) * margem;
    return { minLat: minLat - mLat, minLng: minLng - mLng, maxLat: maxLat + mLat, maxLng: maxLng + mLng };
  },

  /** Município do filtro (campo "Ir para") — plota só esse município, se preenchido. */
  _carFiltroMun() { return (document.getElementById('croquiIrMun')?.value || '').trim(); },

  /**
   * "Ir para" usa a lista pré-cadastrada Município – UF (busca digitável): o
   * select guarda o nome oficial e a UF vai para o hidden #croquiIrUf.
   * O endereço do produtor (texto do ERP, às vezes sem acento/maiúsculo) é
   * casado sem acento; fora da lista, o campo fica vazio para o técnico escolher.
   */
  _setIrPara(municipio, uf, linha) {
    const sel = document.getElementById('croquiIrMun');
    const elLinha = document.getElementById('croquiIrLinha');
    if (elLinha) elLinha.value = linha || '';
    if (!sel) return;
    sel.value = '';
    if (municipio) {
      const alvo = Clientes._semAcento(municipio);
      const opts = [...sel.options];
      const opt = opts.find(o => Clientes._semAcento(o.dataset.nome) === alvo && (!uf || o.dataset.uf === String(uf).toUpperCase()))
        || opts.find(o => Clientes._semAcento(o.dataset.nome) === alvo);
      if (opt) sel.value = opt.value;
    }
    App.selectBuscaSync(sel);
    Croqui.ufDoIrPara();
  },

  /** UF do "Ir para" acompanha o município escolhido (hidden #croquiIrUf). */
  ufDoIrPara() {
    const sel = document.getElementById('croquiIrMun');
    const hid = document.getElementById('croquiIrUf');
    if (!sel || !hid) return;
    const o = sel.selectedOptions[0];
    hid.value = o && o.value !== '' ? (o.dataset.uf || '') : '';
  },

  /** Carrega os imóveis do CAR na ÁREA VISÍVEL (menos dados) + filtro de município. */
  async _carregarCarLayer(silencioso = false) {
    if (!Croqui.vista) return;
    // Afastado demais: não carrega nem plota (a essa escala vira ruído e pesa).
    if (Croqui.vista.z < Croqui.CAR_ZOOM_MIN) { Croqui.carLayer = []; return; }
    const b = Croqui._viewportBBox();
    const mun = Croqui._carFiltroMun();
    try {
      if (navigator.onLine) {
        const qs = `minLat=${b.minLat.toFixed(7)}&minLng=${b.minLng.toFixed(7)}&maxLat=${b.maxLat.toFixed(7)}&maxLng=${b.maxLng.toFixed(7)}`
          + (mun ? `&municipio=${encodeURIComponent(mun)}` : '');
        const r = await App.json(`index.php?r=clientes/car-proximos&${qs}`);
        Croqui.carLayer = r.imoveis || [];
      } else if (typeof Offline !== 'undefined') {
        const base = await Offline.lerCarMunicipio();
        Croqui.carLayer = (base && base.imoveis) ? base.imoveis.map(im => ({ cod: im.cod, contorno: im.contorno })) : [];
      }
      Croqui._carVista = b; // área já carregada (evita recarregar à toa)
    } catch (e) {
      if (!silencioso) App.alerta('Não consegui carregar os imóveis do CAR aqui.', 'warning');
    }
  },

  /** Recarrega o overlay do CAR ao mover o mapa (pan/zoom), sem spam (debounce). */
  _agendarRecargaCar() {
    if (!Croqui.carLayerOn) return;
    clearTimeout(Croqui._carTimer);
    // Em SEGUNDO PLANO (não trava o zoom/pan): busca a nova área e só então redesenha.
    Croqui._carTimer = setTimeout(async () => {
      await Croqui._carregarCarLayer(true);
      Croqui.render();
    }, 350);
  },

  /** Imóvel do overlay que contém o ponto [lat,lng] (o de menor área, se houver sobreposição). */
  _carDoMapaNoPonto(lat, lng) {
    const dentro = (pol) => {
      let d = false;
      for (let i = 0, j = pol.length - 1; i < pol.length; j = i++) {
        const yi = pol[i][0], xi = pol[i][1], yj = pol[j][0], xj = pol[j][1];
        if (((yi > lat) !== (yj > lat)) && lng < (xj - xi) * (lat - yi) / ((yj - yi) || 1e-12) + xi) d = !d;
      }
      return d;
    };
    const dentroImovel = (contorno) => Croqui._aneis(contorno).some(anel => anel.length >= 3 && dentro(anel));
    let achado = null, menorArea = Infinity;
    for (const im of Croqui.carLayer) {
      if (!dentroImovel(im.contorno)) continue;
      const a = Croqui._areaContorno(im.contorno);
      if (a < menorArea) { menorArea = a; achado = im; }
    }
    return achado;
  },

  /** Adota o imóvel do CAR escolhido no mapa como divisa da propriedade. */
  _selecionarCarDoMapa(im) {
    if (Croqui.atualId !== 0) { App.alerta('Selecione "🏠 Divisa do imóvel (CAR)" no seletor para adotar a divisa do CAR.', 'warning'); return; }
    if (Croqui.pontos.length >= 3 && !confirm('Substituir a divisa atual pela área do CAR escolhida?')) return;
    Croqui.pontos = Croqui._maiorAnel(im.contorno); // divisa da propriedade = 1 anel (a maior parte)
    Croqui._selecionado = null;
    Croqui._carCod = im.cod || '';
    Croqui._dirty = true;
    Croqui.render();
    // o técnico escolheu explicitamente a área => grava o nº do CAR na hora
    if (im.cod && im.cod !== (Croqui.imovel.car_numero || '')) Croqui._persistirCarNumero(im.cod);
    App.alerta('Área do CAR adotada' + (im.cod ? ' (' + App.escapeHtml(im.cod) + ')' : '')
      + (im.cod ? '. Nº do CAR gravado no cadastro' : '') + '. Confira e toque em "Salvar croqui".', 'success');
  },

  /**
   * Overlay do CAR conforme o alvo: editando a divisa → auto-mostra (o técnico
   * escolhe/adota a área); área de plantio ou talhão → desliga (só a divisa
   * salva é o limite; o CAR pode ser maior que o ajuste do usuário e confundia).
   */
  _carLayerNoLimite(editandoDivisa) {
    if (editandoDivisa) {
      Croqui._autoMostrarCar();
      return;
    }
    if (!Croqui.carLayerOn) return;
    Croqui.carLayerOn = false;
    const btn = document.getElementById('croquiCarMapaBtn');
    if (btn) btn.classList.remove('active');
  },

  /** Ao abrir: mostra o overlay do CAR se houver base na região (o técnico escolhe a área). */
  async _autoMostrarCar() {
    if (Croqui.carLayerOn || Croqui.atualId !== 0) return;
    await Croqui._carregarCarLayer(true);
    if (Croqui.carLayer.length) {
      Croqui.carLayerOn = true;
      const btn = document.getElementById('croquiCarMapaBtn');
      if (btn) btn.classList.add('active');
      Croqui.render();
    }
  },

  /** "Ir para": centraliza o mapa num município/UF/linha (dados locais e, se faltar, geocoder). */
  async irParaArea() {
    Croqui.ufDoIrPara();
    const mun = (document.getElementById('croquiIrMun').value || '').trim();
    const uf = (document.getElementById('croquiIrUf').value || '').trim();
    const linha = (document.getElementById('croquiIrLinha').value || '').trim();
    if (!mun && !linha) { App.alerta('Informe ao menos o município.', 'warning'); return; }
    if (!navigator.onLine) { App.alerta('Sem conexão: o "Ir para" precisa de internet.', 'warning'); return; }
    let alvo = null, diagnostico = '';
    try {
      const r = await App.json(`index.php?r=clientes/localizar-area&municipio=${encodeURIComponent(mun)}&uf=${encodeURIComponent(uf)}&linha=${encodeURIComponent(linha)}`);
      if (r.lat != null) {
        alvo = { lat: r.lat, lng: r.lng, bbox: r.bbox }; // achou nos dados locais
      } else if (Array.isArray(r.geocode) && r.geocode.length) {
        App.alerta('Procurando o endereço…', 'info'); // geocodifica no navegador (como o Google)
        const g = await Croqui._geocodeNavegador(r.geocode, r.geocoder_base, r.estado_alvo);
        if (g && g.lat != null) {
          alvo = g;
        } else if (r.fallback && r.fallback.lat != null) {
          alvo = r.fallback; // buscador não achou a linha → cai no centro do município (dados locais)
        } else if (g && g.semRede) {
          App.alerta('Não consegui contatar o buscador de endereços (verifique a internet/rede e tente de novo). Se persistir, avise o suporte.', 'warning');
          return;
        } else {
          diagnostico = r.diagnostico || 'Endereço não encontrado pelo buscador. Tente só o município.';
        }
      } else if (r.fallback && r.fallback.lat != null) {
        alvo = r.fallback; // sem geocoder configurado → centro do município (dados locais)
      } else {
        diagnostico = r.diagnostico || '';
      }
    } catch (e) { App.alerta(e.message, 'warning'); return; }
    if (!alvo) { App.alerta(diagnostico || 'Não achei essa região. Confira o endereço.', 'warning'); return; }
    // só move e mexe no overlay DEPOIS de achar (não marca "CAR no mapa" à toa)
    Croqui._irPara(alvo.lat, alvo.lng, alvo.bbox);
    Croqui.carLayer = [];
    await Croqui._carregarCarLayer(true);
    Croqui.carLayerOn = Croqui.carLayer.length > 0;
    const b = document.getElementById('croquiCarMapaBtn'); if (b) b.classList.toggle('active', Croqui.carLayerOn);
    Croqui.render();
    App.alerta(Croqui.carLayer.length
      ? 'Mapa na região. Toque na área que é do produtor para adotar a divisa.'
      : 'Cheguei na região. Não há base do CAR importada aqui — dá para desenhar manual ou importar o município.',
      Croqui.carLayer.length ? 'success' : 'info');
  },

  /** Parser da resposta do Nominatim (jsonv2, com addressdetails). Devolve {lat,lng,bbox?,estado} ou null. */
  _parseNominatim(d) {
    const h = Array.isArray(d) ? d[0] : null;
    if (!h || !h.lat || !h.lon) return null;
    let bbox = null;
    if (Array.isArray(h.boundingbox) && h.boundingbox.length === 4) {
      const b = h.boundingbox; bbox = [+b[0], +b[2], +b[1], +b[3]]; // [sul,norte,oeste,leste]->[minLat,minLng,maxLat,maxLng]
    }
    return { lat: +h.lat, lng: +h.lon, bbox, estado: (h.address && h.address.state) || '' };
  },

  /** Parser da resposta do Photon (GeoJSON). Devolve {lat,lng,bbox?,estado} ou null. */
  _parsePhoton(d) {
    const f = d && d.features && d.features[0];
    if (!f || !f.geometry || !Array.isArray(f.geometry.coordinates)) return null;
    const c = f.geometry.coordinates; // [lng, lat]
    const pr = f.properties || {};
    const e = pr.extent; // [oeste, norte, leste, sul]
    const bbox = (Array.isArray(e) && e.length === 4) ? [+e[3], +e[0], +e[1], +e[2]] : null;
    return { lat: +c[1], lng: +c[0], bbox, estado: pr.state || '' };
  },

  /**
   * Geocodifica no NAVEGADOR (que tem internet, como os tiles). Tenta cada consulta
   * em VÁRIOS buscadores até um responder — PHOTON primeiro (CORS confiável, não exige
   * User-Agent) e NOMINATIM de reserva (dá 403 sem header de CORS quando bloqueia).
   * Se `estadoAlvo` vier, descarta resultado cujo estado não bata (evita casar município
   * de mesmo nome em outra UF). Se `base` (geocoder_url) foi configurado, usa só ele.
   * Devolve {lat,lng,bbox}; senão {semRede:true} se NENHUM respondeu ou {semRede:false}
   * se respondeu mas não achou — o chamador diferencia a mensagem.
   */
  async _geocodeNavegador(queries, base, estadoAlvo) {
    const norm = s => (s || '').toUpperCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim();
    const alvo = norm(estadoAlvo);
    const usaConfig = base && !/nominatim\.openstreetmap\.org/.test(base);
    const provedores = usaConfig
      ? [{ url: q => `${base}${base.includes('?') ? '&' : '?'}format=jsonv2&addressdetails=1&limit=1&countrycodes=br&q=${encodeURIComponent(q)}`, parse: Croqui._parseNominatim }]
      : [
          { url: q => `https://photon.komoot.io/api/?limit=1&lang=pt&q=${encodeURIComponent(q)}`, parse: Croqui._parsePhoton },
          { url: q => `https://nominatim.openstreetmap.org/search?format=jsonv2&addressdetails=1&limit=1&countrycodes=br&q=${encodeURIComponent(q)}`, parse: Croqui._parseNominatim },
        ];
    let respondeu = false;
    for (const q of queries) {
      for (const p of provedores) {
        let resp;
        try { resp = await fetch(p.url(q), { headers: { Accept: 'application/json' } }); }
        catch (e) { continue; } // rede/CORS/bloqueio: tenta o próximo provedor
        respondeu = true;
        if (!resp.ok) continue;
        let d;
        try { d = await resp.json(); } catch (e) { continue; }
        const r = p.parse(d);
        if (!r || !isFinite(r.lat) || !isFinite(r.lng)) continue;
        if (r.lat < -34 || r.lat > 6 || r.lng < -74 || r.lng > -32) continue; // fora do Brasil
        if (alvo && r.estado && norm(r.estado) !== alvo) continue; // estado não bate: descarta
        return { lat: r.lat, lng: r.lng, bbox: r.bbox || [r.lat, r.lng, r.lat, r.lng] };
      }
    }
    return { semRede: !respondeu };
  },

  /** Centra a vista em [lat,lng] (ou enquadra o bbox [minLat,minLng,maxLat,maxLng] se informado). */
  _irPara(lat, lng, bbox) {
    const palco = document.getElementById('croquiPalco');
    const larg = Math.max(300, palco.clientWidth), alt = Math.max(260, palco.clientHeight);
    if (bbox && bbox.length === 4 && (bbox[0] !== bbox[2] || bbox[1] !== bbox[3])) {
      const x0 = Croqui._wx([0, bbox[1]]), x1 = Croqui._wx([0, bbox[3]]);
      const y0 = Croqui._wy([bbox[0], 0]), y1 = Croqui._wy([bbox[2], 0]);
      const cx = (x0 + x1) / 2, cy = (y0 + y1) / 2;
      const spanX = Math.max(1e-9, Math.abs(x1 - x0)), spanY = Math.max(1e-9, Math.abs(y1 - y0));
      const z = Math.floor(Math.min(Math.log2(larg * 0.8 / (256 * spanX)), Math.log2(alt * 0.8 / (256 * spanY))));
      Croqui.vista = { z: Math.max(3, Math.min(17, z)), cx, cy };
    } else {
      Croqui.vista = { z: 14, cx: Croqui._wx([lat, lng]), cy: Croqui._wy([lat, lng]) };
    }
    Croqui.render();
  },

  trocarModo() {
    if (document.getElementById('croquiModoGps').checked) Croqui._iniciarGPS();
    else Croqui._pararGPS();
    Croqui._refletirModo();
  },

  /** Split button do modo: escolhe 'manual' | 'gps' (marca o rádio escondido e aplica). */
  setModo(modo) {
    const alvo = document.getElementById(modo === 'gps' ? 'croquiModoGps' : 'croquiModoManual');
    if (!alvo) return;
    if (!alvo.checked) { alvo.checked = true; Croqui.trocarModo(); }
    else Croqui._refletirModo();
  },

  /** O botão principal do split mostra o modo ATIVO (rótulo, ícone e cor). */
  _refletirModo() {
    const btn = document.getElementById('croquiModoBtn');
    if (!btn) return;
    const gps = document.getElementById('croquiModoGps').checked;
    btn.innerHTML = gps
      ? '<i class="bi bi-geo-alt me-1"></i>Caminhando a divisa'
      : '<i class="bi bi-hand-index-thumb me-1"></i>Manual (toque)';
    btn.classList.toggle('btn-primary', gps);
    btn.classList.toggle('btn-success', !gps);
    // a seta do split acompanha a cor do principal
    const seta = btn.nextElementSibling;
    if (seta) { seta.classList.toggle('btn-primary', gps); seta.classList.toggle('btn-success', !gps); }
  },

  _iniciarGPS() {
    Croqui._pararGPS(); // nunca acumula watchers (o antigo ficaria órfão)
    const status = document.getElementById('croquiGpsStatus');
    if (!navigator.geolocation) { App.alerta('GPS indisponível neste aparelho.', 'warning'); return; }
    status.classList.remove('d-none');
    status.textContent = 'GPS: aguardando sinal…';
    Croqui.watchId = navigator.geolocation.watchPosition(pos => {
      const { latitude, longitude, accuracy } = pos.coords;
      status.textContent = `GPS ±${Math.round(accuracy)} m · ${Croqui.pontos.length} ponto(s)`;
      if (accuracy > 35) return; // sinal ruim: não marca
      const p = Croqui._prender([latitude, longitude]);
      const ultimo = Croqui.pontos[Croqui.pontos.length - 1];
      if (ultimo && Croqui._distM(ultimo, p) < 10) return; // anda ~10 m entre pontos
      Croqui.pontos.push(p);
      Croqui._dirty = true;
      if (!Croqui.vista) { Croqui.vista = { z: 17, cx: Croqui._wx(p), cy: Croqui._wy(p) }; }
      Croqui.render();
    }, () => { status.textContent = 'GPS: sem sinal (permita a localização)'; },
    { enableHighAccuracy: true, maximumAge: 2000, timeout: 15000 });
  },

  _pararGPS() {
    if (Croqui.watchId !== null) { navigator.geolocation.clearWatch(Croqui.watchId); Croqui.watchId = null; }
    document.getElementById('croquiGpsStatus').classList.add('d-none');
  },

  fechar() {
    Croqui._pararGPS();
    const m = document.getElementById('modalCroqui'); if (m) m.classList.remove('croqui-cheio');
    if (typeof Clientes !== 'undefined' && Clientes.fichaClienteId) Clientes.ficha(Clientes.fichaClienteId);
  },

  _distM(a, b) {
    const mLat = 110574, mLng = 111320 * Math.cos(a[0] * Math.PI / 180);
    return Math.hypot((a[0] - b[0]) * mLat, (a[1] - b[1]) * mLng);
  },

  /** Índice da aresta (i, i+1) cuja linha na tela passa a ≤ ~18 px de (x,y); -1 se nenhuma. */
  _arestaProxima(x, y, w, h) {
    const tela = Croqui.pontos.map(p => Croqui._paraTela(p, w, h));
    const n = tela.length;
    let melhorI = -1, melhorD = 22; // limiar de proximidade em pixels (folga p/ toque no campo)
    for (let i = 0; i < n; i++) {
      const d = Croqui._distSegTela([x, y], tela[i], tela[(i + 1) % n]); // % n fecha o polígono
      if (d < melhorD) { melhorD = d; melhorI = i; }
    }
    return melhorI;
  },

  /** Distância (px) de um ponto p ao segmento a-b na tela. */
  _distSegTela(p, a, b) {
    const dx = b[0] - a[0], dy = b[1] - a[1], len2 = dx * dx + dy * dy;
    const t = len2 > 0 ? Math.max(0, Math.min(1, ((p[0] - a[0]) * dx + (p[1] - a[1]) * dy) / len2)) : 0;
    return Math.hypot(p[0] - (a[0] + t * dx), p[1] - (a[1] + t * dy));
  },

  areaHa(pontos) {
    if (pontos.length < 3) return 0;
    const lat0 = pontos.reduce((s, p) => s + Number(p[0]), 0) / pontos.length;
    const mLat = 110574, mLng = 111320 * Math.cos(lat0 * Math.PI / 180);
    let soma = 0;
    for (let i = 0; i < pontos.length; i++) {
      const a = pontos[i], b = pontos[(i + 1) % pontos.length];
      soma += (a[1] * mLng) * (-b[0] * mLat) - (b[1] * mLng) * (-a[0] * mLat);
    }
    return Math.abs(soma) / 2 / 10000;
  },

  /** Normaliza o contorno em lista de anéis: aceita anel único [[lat,lng],...]
   *  ou multipolygon [[[lat,lng],...],...] (imóvel do CAR com partes desconexas). */
  _aneis(contorno) {
    if (!Array.isArray(contorno) || !contorno.length) return [];
    return Array.isArray(contorno[0]) && Array.isArray(contorno[0][0]) ? contorno : [contorno];
  },

  /** Maior anel (por área) de um contorno — a divisa da propriedade é sempre 1 anel. */
  _maiorAnel(contorno) {
    const aneis = Croqui._aneis(contorno);
    if (aneis.length <= 1) return (aneis[0] || []).map(p => [Number(p[0]), Number(p[1])]);
    let melhor = aneis[0], melhorA = -1;
    for (const a of aneis) { const ar = Croqui.areaHa(a); if (ar > melhorA) { melhorA = ar; melhor = a; } }
    return melhor.map(p => [Number(p[0]), Number(p[1])]);
  },

  /** Área total (ha) do contorno (soma das partes). */
  _areaContorno(contorno) {
    return Croqui._aneis(contorno).reduce((s, a) => s + Croqui.areaHa(a), 0);
  },

  /* REGRA: talhão JAMAIS sai da divisa da propriedade (tolerância ~15 m p/ GPS) */
  TOLERANCIA_DIVISA_M: 15,

  /** Índices dos pontos que caem fora da divisa (espelho do CroquiService). */
  _pontosFora(pontos, divisa, tolM = 15) {
    if (!divisa || divisa.length < 3) return [];
    const lat0 = divisa.reduce((s, p) => s + Number(p[0]), 0) / divisa.length;
    const mLat = 110574, mLng = 111320 * Math.cos(lat0 * Math.PI / 180);
    const proj = p => [Number(p[1]) * mLng, -Number(p[0]) * mLat];
    const pol = divisa.map(proj);
    const dentro = p => {
      let d = false;
      for (let i = 0, j = pol.length - 1; i < pol.length; j = i++) {
        const [xi, yi] = pol[i], [xj, yj] = pol[j];
        if (((yi > p[1]) !== (yj > p[1])) && p[0] < (xj - xi) * (p[1] - yi) / ((yj - yi) || 1e-12) + xi) d = !d;
      }
      return d;
    };
    const distBorda = p => {
      let menor = Infinity;
      for (let i = 0; i < pol.length; i++) {
        const [ax, ay] = pol[i], [bx, by] = pol[(i + 1) % pol.length];
        const abx = bx - ax, aby = by - ay;
        const len2 = abx * abx + aby * aby;
        const t = len2 > 0 ? Math.max(0, Math.min(1, ((p[0] - ax) * abx + (p[1] - ay) * aby) / len2)) : 0;
        menor = Math.min(menor, Math.hypot(p[0] - (ax + t * abx), p[1] - (ay + t * aby)));
      }
      return menor;
    };
    const fora = [];
    pontos.forEach((p, i) => {
      const xy = proj(p);
      if (!dentro(xy) && distBorda(xy) > tolM) fora.push(i);
    });
    return fora;
  },

  /**
   * Prende na divisa: desenhando um TALHÃO, ponto que cairia fora da
   * propriedade é puxado para a borda mais próxima (o servidor faz o mesmo).
   */
  _prender(p) {
    if (Croqui.atualId === 0) return p; // desenhando a própria divisa
    // 1) fora do LIMITE (área de plantio → divisa; talhão → área de plantio hospedeira, v45)
    //    → puxa para a borda. Fora OU colado (≤ SNAP_DIVISA_M): vai para a borda EXATA —
    //    o desenho margeia o limite tal como foi marcado (pedido do teste de campo)
    const lim = Croqui._limite(p);
    const divisa = lim ? lim.pontos : [];
    if (divisa.length >= 3 && (!Croqui._dentroDe(p, divisa) || Croqui._distBordaM(p, divisa) <= Croqui.SNAP_DIVISA_M)) p = Croqui._bordaMaisProxima(p, divisa);
    // 2) talhão: dentro de OUTRO talhão → puxa para a borda do vizinho (talhões não se cobrem)
    if (Croqui._ehTalhao(Croqui.atualId)) {
      for (const t of Croqui.talhoes) {
        if (Number(t.id) === Croqui.atualId) continue;
        const pts = Croqui._contornoDe(t.id);
        if (pts.length >= 3 && Croqui._dentroDe(p, pts)) p = Croqui._bordaMaisProxima(p, pts);
      }
    }
    // 3) v44: área de plantio dentro de OUTRA área de plantio → mesma regra
    if (Croqui._ehPlantio(Croqui.atualId)) {
      for (const a of Croqui.areasPlantio) {
        if (Croqui._alvoPlantio(a.id) === Croqui.atualId) continue;
        const pts = Croqui._contornoDe(Croqui._alvoPlantio(a.id));
        if (pts.length >= 3 && Croqui._dentroDe(p, pts)) p = Croqui._bordaMaisProxima(p, pts);
      }
    }
    return p;
  },

  _ehTalhao(id) { return Number(id) > 0 || Number(id) === Croqui.NOVO_ID; },

  /**
   * v45: a ÁREA DE PLANTIO que hospeda o talhão em edição — a que contém mais vértices
   * (tolerância curta); empate → o vínculo gravado (area_plantio_id). Com o desenho
   * ainda vazio, a área que contém o ponto tocado (ou a de borda mais próxima).
   */
  _areaHospedeira(pontos, pontoNovo) {
    const areas = Croqui.areasPlantio.filter(a => Croqui._contornoDe(Croqui._alvoPlantio(a.id)).length >= 3);
    if (!areas.length) return null;
    const t = Croqui.talhoes.find(x => Number(x.id) === Croqui.atualId);
    const preferida = t ? Number(t.area_plantio_id || 0) : 0;
    if (pontos && pontos.length) {
      let melhor = null, melhorN = 0;
      for (const a of areas) {
        const pol = Croqui._contornoDe(Croqui._alvoPlantio(a.id));
        const dentro = pontos.length - Croqui._pontosFora(pontos, pol, 3).length;
        if (dentro > melhorN || (dentro === melhorN && dentro > 0 && Number(a.id) === preferida)) { melhor = a; melhorN = dentro; }
      }
      if (melhor) return melhor;
    }
    if (pontoNovo) {
      const dentro = areas.find(a => Croqui._dentroDe(pontoNovo, Croqui._contornoDe(Croqui._alvoPlantio(a.id))));
      if (dentro) return dentro;
      let melhor = null, menor = Infinity;
      for (const a of areas) {
        const d = Croqui._distBordaM(pontoNovo, Croqui._contornoDe(Croqui._alvoPlantio(a.id)));
        if (d < menor) { menor = d; melhor = a; }
      }
      return melhor;
    }
    return preferida ? areas.find(a => Number(a.id) === preferida) || null : null;
  },

  /**
   * O LIMITE do alvo em edição: área de plantio → divisa do imóvel; talhão → a área
   * de plantio hospedeira (v45); divisa → nenhum. {pontos, rotulo, area}.
   */
  _limite(pontoNovo) {
    if (Croqui.atualId === 0) return null;
    if (Croqui._ehPlantio(Croqui.atualId)) {
      const d = Croqui._contornoDe(0);
      return d.length >= 3 ? { pontos: d, rotulo: 'divisa do imóvel', area: null } : null;
    }
    const a = Croqui._areaHospedeira(Croqui.pontos, pontoNovo);
    if (!a) return null;
    const pol = Croqui._contornoDe(Croqui._alvoPlantio(a.id));
    return pol.length >= 3 ? { pontos: pol, rotulo: 'área de plantio "' + a.nome + '"', area: a } : null;
  },

  /** Projeção local (metros) de um polígono + função para projetar pontos na mesma referência. */
  _projetor(poligono) {
    const lat0 = poligono.reduce((s, q) => s + Number(q[0]), 0) / poligono.length;
    const mLat = 110574, mLng = 111320 * Math.cos(lat0 * Math.PI / 180);
    return { mLat, mLng, proj: q => [Number(q[1]) * mLng, -Number(q[0]) * mLat] };
  },

  /** Ponto [lat,lng] dentro do polígono [[lat,lng],...] (ray casting). */
  _dentroDe(p, poligono) {
    const { proj } = Croqui._projetor(poligono);
    const pol = poligono.map(proj), xy = proj(p);
    let dentro = false;
    for (let i = 0, j = pol.length - 1; i < pol.length; j = i++) {
      const [xi, yi] = pol[i], [xj, yj] = pol[j];
      if (((yi > xy[1]) !== (yj > xy[1])) && xy[0] < (xj - xi) * (xy[1] - yi) / ((yj - yi) || 1e-12) + xi) dentro = !dentro;
    }
    return dentro;
  },

  /** Ponto da borda do polígono mais próximo de p, devolvido em [lat,lng]. */
  _bordaMaisProxima(p, poligono) {
    const { mLat, mLng, proj } = Croqui._projetor(poligono);
    const pol = poligono.map(proj), xy = proj(p);
    let melhor = pol[0], menor = Infinity;
    for (let i = 0; i < pol.length; i++) {
      const [ax, ay] = pol[i], [bx, by] = pol[(i + 1) % pol.length];
      const abx = bx - ax, aby = by - ay, len2 = abx * abx + aby * aby;
      const t = len2 > 0 ? Math.max(0, Math.min(1, ((xy[0] - ax) * abx + (xy[1] - ay) * aby) / len2)) : 0;
      const cx = ax + t * abx, cy = ay + t * aby;
      const d = (xy[0] - cx) ** 2 + (xy[1] - cy) ** 2;
      if (d < menor) { menor = d; melhor = [cx, cy]; }
    }
    return [-melhor[1] / mLat, melhor[0] / mLng];
  },

  /**
   * Dois polígonos se sobrepõem? (mesma regra do servidor, CroquiService::sobrepoe)
   * Vértice de um dentro do outro além de 3 m da borda, ou arestas que se cruzam
   * de verdade. Vizinhos que só dividem uma linha não contam.
   */
  _sobrepoe(a, b) {
    if (a.length < 3 || b.length < 3) return false;
    const TOL = 3;
    if (Croqui._pontosDentroDe(a, b, TOL).length || Croqui._pontosDentroDe(b, a, TOL).length) return true;
    const { proj } = Croqui._projetor(a.concat(b));
    const pa = a.map(proj), pb = b.map(proj);
    const orient = (o, q, r) => (q[0] - o[0]) * (r[1] - o[1]) - (q[1] - o[1]) * (r[0] - o[0]);
    for (let i = 0; i < pa.length; i++) {
      const p1 = pa[i], p2 = pa[(i + 1) % pa.length];
      for (let j = 0; j < pb.length; j++) {
        const q1 = pb[j], q2 = pb[(j + 1) % pb.length];
        const d1 = orient(q1, q2, p1), d2 = orient(q1, q2, p2), d3 = orient(p1, p2, q1), d4 = orient(p1, p2, q2);
        if (!((d1 > 0) !== (d2 > 0)) || !((d3 > 0) !== (d4 > 0))) continue;
        const t = d1 / (d1 - d2), x = p1[0] + t * (p2[0] - p1[0]), y = p1[1] + t * (p2[1] - p1[1]);
        if ([p1, p2, q1, q2].some(pt => Math.hypot(x - pt[0], y - pt[1]) <= TOL)) continue; // só encostou
        return true;
      }
    }
    // Polígonos IGUAIS (mesmo desenho salvo de novo): sem vértice dentro e sem
    // cruzamento — um ponto do INTERIOR de um está dentro do outro (espelho do servidor)
    const dentroLonge = (xy, pol) => Croqui._dentroXY(xy, pol) && Croqui._posicaoNaBorda(xy, pol).dist > TOL;
    const ia = Croqui._pontoInterior(pa, TOL);
    if (ia && dentroLonge(ia, pb)) return true;
    const ib = Croqui._pontoInterior(pb, TOL);
    return !!(ib && dentroLonge(ib, pa));
  },

  /** Ray casting em coordenadas já projetadas (metros). */
  _dentroXY(p, pol) {
    let dentro = false;
    for (let i = 0, j = pol.length - 1; i < pol.length; j = i++) {
      const [xi, yi] = pol[i], [xj, yj] = pol[j];
      if ((yi > p[1]) !== (yj > p[1]) && p[0] < (xj - xi) * (p[1] - yi) / ((yj - yi) || 1e-12) + xi) dentro = !dentro;
    }
    return dentro;
  },

  /** Ponto no INTERIOR do polígono (metros), longe da borda mais que tol; null se degenerado. */
  _pontoInterior(pol, tol) {
    const n = pol.length;
    const cx = pol.reduce((s, p) => s + p[0], 0) / n, cy = pol.reduce((s, p) => s + p[1], 0) / n;
    const cand = [[cx, cy]];
    pol.forEach(p => cand.push([(cx + p[0]) / 2, (cy + p[1]) / 2]));
    for (let i = 0; i < n; i++) { const q = pol[(i + 2) % n]; cand.push([(pol[i][0] + q[0]) / 2, (pol[i][1] + q[1]) / 2]); }
    return cand.find(c => Croqui._dentroXY(c, pol) && Croqui._posicaoNaBorda(c, pol).dist > tol) || null;
  },

  /** Distância (m) de um ponto [lat,lng] à borda do polígono [[lat,lng],...]. */
  _distBordaM(p, poligono) {
    const { proj } = Croqui._projetor(poligono);
    return Croqui._posicaoNaBorda(proj(p), poligono.map(proj)).dist;
  },

  /** Aresta da borda mais próxima do ponto (já em metros): {i, t, dist}. */
  _posicaoNaBorda(xy, pol) {
    let melhor = { i: 0, t: 0, dist: Infinity };
    for (let i = 0; i < pol.length; i++) {
      const [ax, ay] = pol[i], [bx, by] = pol[(i + 1) % pol.length];
      const abx = bx - ax, aby = by - ay, len2 = abx * abx + aby * aby;
      const t = len2 > 0 ? Math.max(0, Math.min(1, ((xy[0] - ax) * abx + (xy[1] - ay) * aby) / len2)) : 0;
      const d = Math.hypot(xy[0] - (ax + t * abx), xy[1] - (ay + t * aby));
      if (d < melhor.dist) melhor = { i, t, dist: d };
    }
    return melhor;
  },

  /**
   * Vértices da borda entre duas posições da divisa, no sentido MAIS CURTO
   * (espelho de CroquiService::caminhoBorda). Posição contínua s = aresta + t.
   */
  _caminhoBorda(pol, pa, pb) {
    const n = pol.length, sa = pa.i + pa.t, sb = pb.i + pb.t;
    const frente = [], tras = [];
    let k = Math.floor(sa) + 1, arco = ((sb - sa) % n + n) % n, passo = k - sa;
    while (passo < arco - 1e-9 && frente.length < n) { frente.push(pol[k % n]); k++; passo += 1; }
    k = Math.ceil(sa) - 1; arco = ((sa - sb) % n + n) % n; passo = sa - k;
    while (passo < arco - 1e-9 && tras.length < n) { tras.push(pol[((k % n) + n) % n]); k--; passo += 1; }
    const ponto = q => { const [ax, ay] = pol[q.i], [bx, by] = pol[(q.i + 1) % n]; return [ax + q.t * (bx - ax), ay + q.t * (by - ay)]; };
    const A = ponto(pa), B = ponto(pb);
    const compr = vs => { let l = 0, ant = A; for (const v of vs.concat([B])) { l += Math.hypot(v[0] - ant[0], v[1] - ant[1]); ant = v; } return l; };
    return compr(frente) <= compr(tras) ? frente : tras;
  },

  /**
   * MARGEAR A DIVISA (pedido do teste de campo): dois pontos seguidos na borda da
   * divisa cuja reta SAI da área do CAR (a divisa faz curva/quina entre eles) ganham
   * o próprio caminho da borda no meio — os vértices da divisa entram no desenho e
   * nenhuma linha fica fora. Reta que segue por dentro (corte de lado a lado) não
   * muda. Idempotente. Espelho de CroquiService::margearDivisa. Devolve true se mudou.
   */
  _margearDivisa() {
    if (Croqui.atualId === 0) return false;
    const lim = Croqui._limite();
    const divisa = lim ? lim.pontos : [], pts = Croqui.pontos, n = pts.length;
    if (divisa.length < 3 || n < 2) return false;
    const { mLat, mLng, proj } = Croqui._projetor(divisa);
    const pol = divisa.map(proj), xy = pts.map(proj);
    const desproj = q => [Number((-q[1] / mLat).toFixed(7)), Number((q[0] / mLng).toFixed(7))];
    const arestas = n >= 3 ? n : n - 1, saida = [];
    let mudou = false;
    for (let i = 0; i < arestas; i++) {
      saida.push(pts[i]);
      const pa = Croqui._posicaoNaBorda(xy[i], pol), pb = Croqui._posicaoNaBorda(xy[(i + 1) % n], pol);
      if (pa.dist > Croqui.SNAP_DIVISA_M || pb.dist > Croqui.SNAP_DIVISA_M) continue;
      if (!Croqui._linhasFora([pts[i], pts[(i + 1) % n]], divisa).length) continue;
      for (const v of Croqui._caminhoBorda(pol, pa, pb)) { saida.push(desproj(v)); mudou = true; }
    }
    if (n === 2) saida.push(pts[1]);
    if (mudou) Croqui.pontos = saida;
    return mudou;
  },

  /** Guarda o estado atual para o Desfazer (uma ação do usuário = um estado). */
  _snapshot() {
    Croqui._undo.push(Croqui.pontos.map(p => [p[0], p[1]]));
    if (Croqui._undo.length > 40) Croqui._undo.shift();
  },

  /**
   * REGRA (teste de campo): NENHUMA LINHA fica fora da área do CAR. Espelho de
   * CroquiService::linhasFora — índices i das arestas (i → i+1) que cruzam a
   * divisa de verdade ou cujo ponto médio cai fora dela (além de tolM). Pega o
   * caso em que os dois vértices estão dentro mas a linha corta uma reentrância.
   */
  _linhasFora(pontos, divisa, tolM = 3) {
    if (pontos.length < 2 || divisa.length < 3) return [];
    const { proj } = Croqui._projetor(divisa);
    const pd = divisa.map(proj), pp = pontos.map(proj);
    const orient = (o, q, r) => (q[0] - o[0]) * (r[1] - o[1]) - (q[1] - o[1]) * (r[0] - o[0]);
    const distBorda = xy => {
      let menor = Infinity;
      for (let i = 0; i < pd.length; i++) {
        const [ax, ay] = pd[i], [bx, by] = pd[(i + 1) % pd.length];
        const abx = bx - ax, aby = by - ay, len2 = abx * abx + aby * aby;
        const t = len2 > 0 ? Math.max(0, Math.min(1, ((xy[0] - ax) * abx + (xy[1] - ay) * aby) / len2)) : 0;
        menor = Math.min(menor, Math.hypot(xy[0] - (ax + t * abx), xy[1] - (ay + t * aby)));
      }
      return menor;
    };
    const n = pontos.length, arestas = n >= 3 ? n : n - 1, out = [];
    for (let i = 0; i < arestas; i++) {
      const p1 = pp[i], p2 = pp[(i + 1) % n];
      let ruim = false;
      for (let j = 0; j < pd.length && !ruim; j++) {
        const q1 = pd[j], q2 = pd[(j + 1) % pd.length];
        const d1 = orient(q1, q2, p1), d2 = orient(q1, q2, p2), d3 = orient(p1, p2, q1), d4 = orient(p1, p2, q2);
        if (!((d1 > 0) !== (d2 > 0)) || !((d3 > 0) !== (d4 > 0))) continue;
        const t = d1 / (d1 - d2), x = p1[0] + t * (p2[0] - p1[0]), y = p1[1] + t * (p2[1] - p1[1]);
        if ([p1, p2, q1, q2].some(pt => Math.hypot(x - pt[0], y - pt[1]) <= tolM)) continue; // só encostou
        ruim = true;
      }
      if (!ruim) {
        const meio = [(Number(pontos[i][0]) + Number(pontos[(i + 1) % n][0])) / 2, (Number(pontos[i][1]) + Number(pontos[(i + 1) % n][1])) / 2];
        if (!Croqui._dentroDe(meio, divisa) && distBorda(proj(meio)) > tolM) ruim = true;
      }
      if (ruim) out.push(i);
    }
    return out;
  },

  /** Índices dos pontos de `pontos` que caem dentro de `outro` (além de tolM da borda). */
  _pontosDentroDe(pontos, outro, tolM = 3) {
    if (outro.length < 3) return [];
    const { proj } = Croqui._projetor(outro);
    const pol = outro.map(proj);
    const distBorda = xy => {
      let menor = Infinity;
      for (let i = 0; i < pol.length; i++) {
        const [ax, ay] = pol[i], [bx, by] = pol[(i + 1) % pol.length];
        const abx = bx - ax, aby = by - ay, len2 = abx * abx + aby * aby;
        const t = len2 > 0 ? Math.max(0, Math.min(1, ((xy[0] - ax) * abx + (xy[1] - ay) * aby) / len2)) : 0;
        menor = Math.min(menor, Math.hypot(xy[0] - (ax + t * abx), xy[1] - (ay + t * aby)));
      }
      return menor;
    };
    const out = [];
    pontos.forEach((p, i) => { if (Croqui._dentroDe(p, outro) && distBorda(proj(p)) > tolM) out.push(i); });
    return out;
  },

  /** Situação da regra para o desenho atual: {fora: [índices], talhoesFora: [nomes]} */
  /**
   * Regras (v40, spec §3): área de plantio e talhão ficam DENTRO da divisa do
   * imóvel (ponto fora = bloqueio); a divisa nova não pode deixar para fora nem
   * talhões nem a área de plantio (bloqueio); talhão fora da ÁREA DE PLANTIO é
   * só AVISO. Devolve {fora, talhoesFora, avisos}.
   */
  _validarRegra() {
    const avisos = [];
    const sobrepostos = [];
    const sobrepostosHosp = []; // talhões hospedados que a área de plantio em edição deixaria para fora
    if (Croqui.atualId !== 0) {
      // LIMITE: área de plantio → divisa; talhão → área de plantio hospedeira (v45)
      const lim = Croqui._limite();
      const divisa = lim ? lim.pontos : [];
      let semLimite = false;
      let fora = divisa.length >= 3 ? Croqui._pontosFora(Croqui.pontos, divisa) : [];
      if (Croqui._ehTalhao(Croqui.atualId) && !lim && Croqui.pontos.length) { fora = Croqui.pontos.map((_, i) => i); semLimite = true; }
      // REGRA (teste de campo): nenhuma LINHA sai do limite — mesmo com os dois
      // pontos dentro, uma aresta que atravessa uma reentrância fica vermelha
      const linhasFora = divisa.length >= 3 ? Croqui._linhasFora(Croqui.pontos, divisa) : [];
      if (Croqui._ehPlantio(Croqui.atualId)) {
        // v44: uma área de plantio NÃO cobre outra (mesma regra dos talhões)
        Croqui.areasPlantio.forEach(a => {
          if (Croqui._alvoPlantio(a.id) === Croqui.atualId) return;
          const pts = Croqui._contornoDe(Croqui._alvoPlantio(a.id));
          if (pts.length < 3) return;
          fora = fora.concat(Croqui._pontosDentroDe(Croqui.pontos, pts));
          if (Croqui.pontos.length >= 3 && Croqui._sobrepoe(Croqui.pontos, pts)) sobrepostos.push(a.nome);
        });
        fora = [...new Set(fora)];
        // v45: a área de plantio NÃO deixa para fora os talhões hospedados nela (bloqueio);
        // talhão legado (sem área) fora de todas as áreas é só aviso
        if (Croqui.pontos.length >= 3) {
          const areas = Croqui._areasPlantioAtuais();
          const meuId = Croqui._plantioIdDe(Croqui.atualId);
          Croqui.talhoes.forEach(t => {
            const pts = Croqui._contornoDe(t.id);
            if (pts.length < 3) return;
            if (meuId && Number(t.area_plantio_id || 0) === meuId) {
              if (Croqui._pontosFora(pts, Croqui.pontos, 3).length) sobrepostosHosp.push(t.nome);
            } else if (Croqui._pontosForaDasAreas(pts, areas).length) avisos.push(t.nome);
          });
        }
      } else if (Croqui._ehTalhao(Croqui.atualId)) {
        // REGRA (teste de campo): talhão NÃO cobre outro talhão — ponto dentro de
        // um vizinho fica vermelho e o cruzamento bloqueia o salvar
        Croqui.talhoes.forEach(t => {
          if (Number(t.id) === Croqui.atualId) return;
          const pts = Croqui._contornoDe(t.id);
          if (pts.length < 3) return;
          fora = fora.concat(Croqui._pontosDentroDe(Croqui.pontos, pts));
          if (Croqui.pontos.length >= 3 && Croqui._sobrepoe(Croqui.pontos, pts)) sobrepostos.push(t.nome);
        });
        fora = [...new Set(fora)];
      }
      return { fora, talhoesFora: sobrepostosHosp, avisos, sobrepostos, linhasFora, semLimite };
    }
    // Editando a divisa: nenhum talhão já desenhado (nem as áreas de plantio) pode ficar para fora
    const talhoesFora = [];
    if (Croqui.pontos.length >= 3) {
      Croqui.talhoes.forEach(t => {
        const pts = Croqui._contornoDe(t.id);
        if (pts.length >= 3 && Croqui._pontosFora(pts, Croqui.pontos).length) talhoesFora.push(t.nome);
      });
      Croqui.areasPlantio.forEach(a => {
        const pts = Croqui._contornoDe(Croqui._alvoPlantio(a.id));
        if (pts.length >= 3 && Croqui._pontosFora(pts, Croqui.pontos).length) talhoesFora.push('área de plantio "' + a.nome + '"');
      });
    }
    return { fora: [], talhoesFora, avisos, sobrepostos, linhasFora: [] };
  },

  /** v44: contornos ATUAIS das áreas de plantio (a em edição entra com os pontos da tela). */
  _areasPlantioAtuais() {
    const areas = Croqui.areasPlantio.map(a => Croqui._contornoAtual(Croqui._alvoPlantio(a.id))).filter(p => p.length >= 3);
    if (Croqui.atualId === Croqui.PLANTIO_ID && Croqui.pontos.length >= 3) areas.push(Croqui.pontos);
    return areas;
  },

  /** Pontos fora de TODAS as áreas ([[lat,lng],...][]); sem área, nada fica fora (espelho do servidor). */
  _pontosForaDasAreas(pontos, areas) {
    if (!areas.length) return [];
    const fora = [];
    // tolerância curta (3 m, a das sobreposições): 15 m "engoliria" faixas estreitas entre áreas
    pontos.forEach((p, i) => { if (!areas.some(a => !Croqui._pontosFora([p], a, 3).length)) fora.push(i); });
    return fora;
  },

  /** HTML de uma camada de tiles (satélite OU rótulos) para a vista atual. */
  _tilesHtml(url, larg, alt) {
    const e = Croqui._escala();
    const zTile = Math.max(3, Math.min(19, Math.round(Croqui.vista.z))); // pinça usa o nível inteiro mais próximo
    const ts = 256 * Math.pow(2, Croqui.vista.z - zTile);
    const n = Math.pow(2, zTile);
    const px0 = Croqui.vista.cx * e - larg / 2, py0 = Croqui.vista.cy * e - alt / 2;
    const tx0 = Math.floor(px0 / ts), tx1 = Math.floor((px0 + larg) / ts);
    const ty0 = Math.max(0, Math.floor(py0 / ts)), ty1 = Math.min(n - 1, Math.floor((py0 + alt) / ts));
    let html = '';
    for (let tx = tx0; tx <= tx1; tx++) {
      for (let ty = ty0; ty <= ty1; ty++) {
        const txn = ((tx % n) + n) % n; // dá a volta no antimeridiano
        const u = url.replace('{z}', zTile).replace('{x}', txn).replace('{y}', ty);
        html += `<img src="${App.escapeHtml(u)}" class="croqui-tile" loading="lazy" alt=""
          style="left:${(tx * ts - px0).toFixed(1)}px;top:${(ty * ts - py0).toFixed(1)}px;width:${ts.toFixed(2)}px;height:${ts.toFixed(2)}px" onerror="this.remove()">`;
      }
    }
    return html;
  },

  // ---- Mapa offline ------------------------------------------------------
  // Mesmo nome de cache do sw.js (CACHE_MAPA) — os dois lados gravam/leem aqui.
  CACHE_MAPA: 'crm-mapa-v1',
  NIVEIS_OFFLINE: 3,      // zoom atual + 2 níveis mais próximos
  MAX_TILES_OFFLINE: 900, // teto para não encher o aparelho de uma vez

  // URLs dos tiles que cobrem o retângulo VISÍVEL, do zoom atual para baixo.
  // Mesma matemática do _tilesHtml, só que por nível de zoom inteiro.
  _urlsDaArea(url, larg, alt, niveis) {
    const e = Croqui._escala();
    const meiaL = larg / (2 * e), meiaA = alt / (2 * e); // metade da tela em unidades de mundo (0..1)
    const wx0 = Croqui.vista.cx - meiaL, wx1 = Croqui.vista.cx + meiaL;
    const wy0 = Croqui.vista.cy - meiaA, wy1 = Croqui.vista.cy + meiaA;
    const zBase = Math.max(3, Math.min(19, Math.round(Croqui.vista.z)));
    const porNivel = [];
    for (let dz = 0; dz < niveis && zBase + dz <= 19; dz++) {
      const z = zBase + dz, n = Math.pow(2, z), urls = [];
      const ty0 = Math.max(0, Math.floor(wy0 * n)), ty1 = Math.min(n - 1, Math.floor(wy1 * n));
      for (let tx = Math.floor(wx0 * n); tx <= Math.floor(wx1 * n); tx++) {
        const txn = ((tx % n) + n) % n; // dá a volta no antimeridiano
        for (let ty = ty0; ty <= ty1; ty++) {
          urls.push(url.replace('{z}', z).replace('{x}', txn).replace('{y}', ty));
        }
      }
      porNivel.push(urls);
    }
    return porNivel;
  },

  // Guarda a imagem de satélite da área visível no aparelho, para o mapa abrir
  // sem sinal no campo (e sem esperar a rede a cada arrastar).
  async baixarMapa() {
    const botao = document.getElementById('croquiBaixarMapaBtn');
    if (!Croqui.tiles || !Croqui.tiles.url) { App.alerta('Nenhum provedor de mapa configurado.', 'warning'); return; }
    if (!Croqui.vista) { App.alerta('Enquadre o mapa na área desejada antes de baixar.', 'warning'); return; }
    if (!('caches' in window)) { App.alerta('Este navegador não guarda mapas offline.', 'warning'); return; }
    if (!navigator.onLine) { App.alerta('Sem conexão. Conecte-se ao wi-fi para baixar o mapa.', 'warning'); return; }

    const palco = document.getElementById('croquiPalco');
    const larg = Math.max(300, palco.clientWidth), alt = Math.max(260, palco.clientHeight);

    // Monta nível a nível e para quando estourar o teto — o nível mais AFASTADO
    // é o que mais importa (cobre toda a área), os mais próximos são o detalhe.
    const niveis = Croqui._urlsDaArea(Croqui.tiles.url, larg, alt, Croqui.NIVEIS_OFFLINE);
    const rotulos = Croqui.tiles.labels ? Croqui._urlsDaArea(Croqui.tiles.labels, larg, alt, Croqui.NIVEIS_OFFLINE) : [];
    let urls = [], zoomsOk = 0;
    for (let i = 0; i < niveis.length; i++) {
      const lote = niveis[i].concat(rotulos[i] || []);
      if (urls.length + lote.length > Croqui.MAX_TILES_OFFLINE) break;
      urls = urls.concat(lote);
      zoomsOk++;
    }
    if (!zoomsOk) { App.alerta('Área grande demais para baixar. Aproxime o mapa e tente de novo.', 'warning'); return; }

    const rotuloOriginal = botao ? botao.innerHTML : '';
    if (botao) botao.disabled = true;
    // O botão agora vive num menu (fecha ao tocar): o progresso vai também para
    // um selo ao lado do split button, que fica visível o tempo todo.
    const selo = document.getElementById('croquiMapaStatus');
    const mostrarProgresso = (pct) => {
      if (botao) botao.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span>Baixando… ${pct}%`;
      if (selo) { selo.classList.remove('d-none'); selo.innerHTML = `<span class="spinner-border spinner-border-sm me-1" style="width:.8em;height:.8em"></span>Mapa ${pct}%`; }
    };
    mostrarProgresso(0);
    const cache = await caches.open(Croqui.CACHE_MAPA);
    let prontos = 0, falhas = 0, semEspaco = false;

    // Um tile por vez seria lento demais; 900 de uma vez o 3G do campo não aguenta.
    for (let i = 0; i < urls.length && !semEspaco; i += 6) {
      await Promise.all(urls.slice(i, i + 6).map(async u => {
        if (semEspaco) return;
        try {
          if (await cache.match(u)) { prontos++; return; } // já estava guardado
          // CORS de propósito: resposta opaca (no-cors) é contabilizada na cota
          // com uma folga enorme e estoura o armazenamento do aparelho.
          let resp = null;
          try {
            const r = await fetch(u, { mode: 'cors', cache: 'no-store', credentials: 'omit' });
            if (r.ok) resp = r;
          } catch (e) { /* provedor não libera CORS */ }
          if (!resp) resp = await fetch(u, { mode: 'no-cors', cache: 'no-store' });
          await cache.put(u, resp);
          prontos++;
        } catch (e) {
          if (e && e.name === 'QuotaExceededError') semEspaco = true;
          falhas++;
        }
      }));
      mostrarProgresso(Math.round((i / urls.length) * 100));
    }

    if (botao) { botao.disabled = false; botao.innerHTML = rotuloOriginal; }
    if (selo) { selo.classList.add('d-none'); selo.innerHTML = ''; }
    if (semEspaco) {
      App.alerta(`Acabou o espaço do aparelho para mapas — ${prontos} imagens guardadas antes disso. `
        + 'Aproxime o mapa e baixe uma área menor, ou libere espaço no aparelho.', 'warning');
      return;
    }
    const detalhe = `${prontos} imagens · ${zoomsOk} nível(is) de zoom` + (falhas ? ` · ${falhas} falharam` : '');
    App.alerta(prontos
      ? `Mapa desta área guardado no aparelho (${detalhe}). Agora ele abre sem sinal.`
      : 'Não foi possível baixar o mapa desta área.',
      prontos ? (falhas ? 'warning' : 'success') : 'danger');
  },

  // leve=true: atualiza só o DESENHO (SVG), sem recriar os tiles do satélite —
  // usado ao ARRASTAR um vértice (a vista não muda, então a imagem fica FIXA e
  // não pisca/recarrega, facilitando posicionar o ponto).
  render(leve = false) {
    const palco = document.getElementById('croquiPalco');
    if (!palco) return;
    const larg = Math.max(300, palco.clientWidth), alt = Math.max(260, palco.clientHeight);
    if (!Croqui.vista) Croqui._enquadrar();
    if (!Croqui.vista) {
      palco.innerHTML = `<div class="d-flex h-100 align-items-center justify-content-center text-center text-muted p-4">
        Sem referência de localização ainda.<br>Use "Caminhar a divisa" para capturar o primeiro ponto por GPS.</div>`;
      Croqui._atualizarArea();
      return;
    }
    const svgEl = palco.querySelector('#croquiSvg');
    const podeLeve = leve && svgEl; // só faz leve se o mapa já foi montado uma vez

    // Camada de satélite (Web Mercator). Offline ela CONTINUA sendo montada: o
    // service worker serve os tiles já guardados (cache-first, CACHE_MAPA), e o
    // que não estiver guardado some sozinho pelo onerror do <img>. Antes havia um
    // guard navigator.onLine aqui, que apagava o satélite inteiro sem sinal.
    // A camada de RÓTULOS (nomes de cidades/localidades/ruas, como no Google) é
    // outra camada de tiles transparente por cima do satélite (provedor configurável).
    let tilesHtml = '', labelsHtml = '';
    if (!podeLeve && Croqui.tiles && Croqui.tiles.url) {
      tilesHtml = Croqui._tilesHtml(Croqui.tiles.url, larg, alt);
      if (Croqui.tiles.labels) labelsHtml = Croqui._tilesHtml(Croqui.tiles.labels, larg, alt);
    }

    let svg = '';
    const legenda = [];
    // Overlay dos imóveis do CAR (tracejado amarelo, como no SICAR) — sob tudo,
    // recortado ao viewport p/ não pesar; a área adotada (=_carCod) fica destacada.
    // Só plota com zoom aproximado: afastado, muitos contornos viram ruído e pesam.
    if (Croqui.carLayerOn && Croqui.vista.z < Croqui.CAR_ZOOM_MIN) {
      legenda.push('<span class="text-warning"><i class="bi bi-zoom-in"></i> Aproxime para ver os imóveis do CAR</span>');
    }
    if (Croqui.carLayerOn && Croqui.carLayer.length && Croqui.vista.z >= Croqui.CAR_ZOOM_MIN) {
      const tl = Croqui._paraGeo(0, 0, larg, alt), br = Croqui._paraGeo(larg, alt, larg, alt);
      const vMinLat = Math.min(tl[0], br[0]), vMaxLat = Math.max(tl[0], br[0]);
      const vMinLng = Math.min(tl[1], br[1]), vMaxLng = Math.max(tl[1], br[1]);
      let desenhados = 0;
      for (const im of Croqui.carLayer) {
        if (desenhados > 600) break;
        const aneis = Croqui._aneis(im.contorno);
        if (!aneis.length) continue;
        // bbox do imóvel sobre TODAS as partes (multipolygon)
        let iMinLat = 91, iMaxLat = -91, iMinLng = 181, iMaxLng = -181;
        for (const anel of aneis) for (const p of anel) { if (p[0] < iMinLat) iMinLat = p[0]; if (p[0] > iMaxLat) iMaxLat = p[0]; if (p[1] < iMinLng) iMinLng = p[1]; if (p[1] > iMaxLng) iMaxLng = p[1]; }
        if (iMaxLat < vMinLat || iMinLat > vMaxLat || iMaxLng < vMinLng || iMinLng > vMaxLng) continue; // fora da tela
        const sel = im.cod && im.cod === Croqui._carCod;
        // Fora da divisa (plantio/talhão) o CAR é só referência: mais apagado, para não
        // passar por limite — o limite é a divisa salva (laranja)
        const soRef = Croqui.atualId !== 0;
        for (const anel of aneis) {
          if (anel.length < 3) continue;
          const tela = anel.map(p => Croqui._paraTela(p, larg, alt));
          svg += `<polygon points="${tela.map(p => p[0].toFixed(1) + ',' + p[1].toFixed(1)).join(' ')}"
                    fill="#ffd400" fill-opacity="${sel && !soRef ? '.20' : '0'}" stroke="#ffd400" stroke-opacity="${soRef ? '.45' : '1'}" stroke-width="${sel && !soRef ? 3 : 1.5}"
                    stroke-dasharray="${sel && !soRef ? 'none' : '5 4'}" style="pointer-events:none"/>`;
        }
        desenhados++;
      }
      legenda.push(Croqui.atualId === 0
        ? '<span><span class="croqui-cor" style="background:#ffd400"></span>Imóveis do CAR (toque p/ adotar)</span>'
        : '<span><span class="croqui-cor" style="background:#ffd400;opacity:.5"></span>CAR (só referência)</span>');
    }
    // Outros imóveis (CAR) da mesma propriedade — contexto apagado, não editáveis (v40)
    if (Croqui.outros.length) {
      Croqui.outros.forEach(o => {
        let pts = []; try { pts = JSON.parse(o.contorno) || []; } catch (e) { /* ignora */ }
        if (pts.length < 3) return;
        const tela = pts.map(p => Croqui._paraTela(p, larg, alt));
        svg += `<polygon points="${tela.map(p => p.map(v => v.toFixed(1)).join(',')).join(' ')}"
                  fill="${Croqui.COR_PROP}" fill-opacity=".03" stroke="${Croqui.COR_PROP}" stroke-opacity=".45" stroke-width="2" stroke-dasharray="3 6"/>`;
        const cx = tela.reduce((s, p) => s + p[0], 0) / tela.length, cy = tela.reduce((s, p) => s + p[1], 0) / tela.length;
        svg += `<text x="${cx.toFixed(1)}" y="${cy.toFixed(1)}" text-anchor="middle" class="croqui-rotulo" opacity=".7">${App.escapeHtml(o.rotulo || 'outro imóvel')}</text>`;
      });
      legenda.push(`<span><span class="croqui-cor" style="background:${Croqui.COR_PROP};opacity:.45"></span>Outros imóveis</span>`);
    }
    // Divisa SALVA do imóvel — quando se desenha área de plantio/talhão ela é o LIMITE
    // (a marcação do usuário, não o CAR): laranja forte com halo branco, inconfundível
    // com o amarelo do CAR. Por baixo de tudo que é do imóvel.
    const divisa = Croqui._contornoAtual(0);
    const limAtual = Croqui.atualId !== 0 ? Croqui._limite() : null;
    const limEhDivisa = !!(limAtual && !limAtual.area);
    if (Croqui.atualId !== 0 && divisa.length >= 3) {
      const tela = divisa.map(p => Croqui._paraTela(p, larg, alt));
      const ptsDiv = tela.map(p => p.map(v => v.toFixed(1)).join(',')).join(' ');
      if (limEhDivisa) {
        svg += `<polygon points="${ptsDiv}" fill="${Croqui.COR_LIMITE}" fill-opacity=".05" stroke="#fff" stroke-opacity=".9" stroke-width="6"/>
                <polygon points="${ptsDiv}" fill="none" stroke="${Croqui.COR_LIMITE}" stroke-width="3" stroke-dasharray="12 5"/>`;
        legenda.push(`<span><span class="croqui-cor" style="background:${Croqui.COR_LIMITE}"></span>Divisa do imóvel (limite — sua marcação)</span>`);
      } else {
        // etapa 3: a divisa é só contexto (o limite do talhão é a área de plantio)
        svg += `<polygon points="${ptsDiv}" fill="${Croqui.COR_PROP}" fill-opacity=".04" stroke="${Croqui.COR_PROP}" stroke-opacity=".7" stroke-width="2" stroke-dasharray="9 6"/>`;
        legenda.push(`<span><span class="croqui-cor" style="background:${Croqui.COR_PROP}"></span>Divisa do imóvel</span>`);
      }
    }
    // v45 — talhão: a área de plantio HOSPEDEIRA é o limite (laranja com halo)
    if (limAtual && limAtual.area) {
      const tela = limAtual.pontos.map(p => Croqui._paraTela(p, larg, alt));
      const ptsLim = tela.map(p => p.map(v => v.toFixed(1)).join(',')).join(' ');
      svg += `<polygon points="${ptsLim}" fill="${Croqui.COR_LIMITE}" fill-opacity=".05" stroke="#fff" stroke-opacity=".9" stroke-width="6"/>
              <polygon points="${ptsLim}" fill="none" stroke="${Croqui.COR_LIMITE}" stroke-width="3" stroke-dasharray="12 5"/>`;
      legenda.push(`<span><span class="croqui-cor" style="background:${Croqui.COR_LIMITE}"></span>Área de plantio "${App.escapeHtml(limAtual.area.nome)}" (limite do talhão)</span>`);
    }
    // Áreas de plantio (verde tracejado, v44: várias — Campo, Morro...) — entre a divisa e os talhões
    let plantiosDesenhados = 0;
    Croqui.areasPlantio.forEach(a => {
      const alvo = Croqui._alvoPlantio(a.id);
      if (alvo === Croqui.atualId) return; // a em edição é desenhada em ciano
      if (limAtual && limAtual.area && Number(limAtual.area.id) === Number(a.id)) return; // já desenhada como limite
      const pts = Croqui._contornoDe(alvo);
      if (pts.length < 3) return;
      const tela = pts.map(p => Croqui._paraTela(p, larg, alt));
      svg += `<polygon points="${tela.map(p => p.map(v => v.toFixed(1)).join(',')).join(' ')}"
                fill="${Croqui.COR_PLANTIO}" fill-opacity=".10" stroke="${Croqui.COR_PLANTIO}" stroke-width="2.5" stroke-dasharray="4 4"/>`;
      if (Croqui.areasPlantio.length > 1) {
        const cx = tela.reduce((s, p) => s + p[0], 0) / tela.length, cy = tela.reduce((s, p) => s + p[1], 0) / tela.length;
        svg += `<text x="${cx.toFixed(1)}" y="${(cy - 14).toFixed(1)}" text-anchor="middle" class="croqui-rotulo" style="fill:#558b2f" opacity=".85">🌱 ${App.escapeHtml(a.nome)}</text>`;
      }
      plantiosDesenhados++;
    });
    if (plantiosDesenhados) legenda.push(`<span><span class="croqui-cor" style="background:${Croqui.COR_PLANTIO}"></span>Área${plantiosDesenhados > 1 ? 's' : ''} de plantio</span>`);
    Croqui.talhoes.forEach((tal, i) => {
      const cor = Croqui.CORES[i % Croqui.CORES.length];
      const pontos = Number(tal.id) === Croqui.atualId ? Croqui.pontos : Croqui._contornoDe(tal.id);
      if (pontos.length) legenda.push(`<span><span class="croqui-cor" style="background:${cor}"></span>${App.escapeHtml(tal.nome)}</span>`);
      if (Number(tal.id) !== Croqui.atualId && pontos.length >= 3) {
        const tela = pontos.map(p => Croqui._paraTela(p, larg, alt));
        svg += `<polygon points="${tela.map(p => p.map(v => v.toFixed(1)).join(',')).join(' ')}"
                  fill="${cor}" fill-opacity=".25" stroke="${cor}" stroke-width="2"/>`;
        const cx = tela.reduce((s, p) => s + p[0], 0) / tela.length;
        const cy = tela.reduce((s, p) => s + p[1], 0) / tela.length;
        svg += `<text x="${cx.toFixed(1)}" y="${cy.toFixed(1)}" text-anchor="middle" class="croqui-rotulo">${App.escapeHtml(tal.nome)}</text>`;
      }
    });
    // Contorno em EDIÇÃO — cor própria (ciano) para separar do amarelo do CAR:
    // é a linha que VOCÊ desenha/ajusta (sólida + vértices arrastáveis).
    const corAtual = Croqui.COR_EDICAO;
    if (Croqui.pontos.length) {
      const regraR = Croqui._validarRegra();
      const foraSet = new Set(regraR.fora);
      const tela = Croqui.pontos.map(p => Croqui._paraTela(p, larg, alt));
      const pts = tela.map(p => p.map(v => v.toFixed(1)).join(',')).join(' ');
      svg += Croqui.pontos.length >= 3
        ? `<polygon points="${pts}" fill="${corAtual}" fill-opacity=".18" stroke="${corAtual}" stroke-width="3"/>`
        : `<polyline points="${pts}" fill="none" stroke="${corAtual}" stroke-width="3"/>`;
      // Linhas que saem da área do CAR ficam VERMELHAS (regra: nenhuma linha fora da divisa)
      (regraR.linhasFora || []).forEach(i => {
        const a = tela[i], b = tela[(i + 1) % tela.length];
        if (!a || !b) return;
        svg += `<line x1="${a[0].toFixed(1)}" y1="${a[1].toFixed(1)}" x2="${b[0].toFixed(1)}" y2="${b[1].toFixed(1)}" stroke="#dc3545" stroke-width="4" stroke-dasharray="8 5"/>`;
      });
      // Realce (anel branco) do ponto tocado uma vez — feedback do "toque de novo para remover".
      const sel = Croqui._selecionado !== null && Croqui._selecionado < tela.length ? Croqui._selecionado : null;
      tela.forEach((p, i) => {
        const invalido = foraSet.has(i); // ponto fora da divisa da propriedade
        if (i === sel) {
          svg += `<circle cx="${p[0].toFixed(1)}" cy="${p[1].toFixed(1)}" r="15" fill="none" stroke="#fff" stroke-width="2.5" stroke-opacity=".95"/>`;
        }
        svg += `<circle cx="${p[0].toFixed(1)}" cy="${p[1].toFixed(1)}" r="9" class="croqui-vertice" data-idx="${i}"
                  fill="${invalido ? '#dc3545' : (i === 0 ? '#fff' : corAtual)}"
                  stroke="${invalido ? '#7a121f' : '#0a5b6b'}" stroke-width="3"/>`;
      });
      const rotuloEdicao = Croqui.atualId === 0 ? 'Divisa (seu ajuste)'
        : (Croqui._ehPlantio(Croqui.atualId) ? 'Área de plantio (desenhando)' : 'Talhão (desenhando)');
      legenda.push(`<span><span class="croqui-cor" style="background:${corAtual}"></span>${rotuloEdicao}</span>`);
    }
    // Sede como referência
    if (Croqui.prop.latitude !== null) {
      const s = Croqui._paraTela([Croqui.prop.latitude, Croqui.prop.longitude], larg, alt);
      svg += `<g transform="translate(${s[0].toFixed(1)},${s[1].toFixed(1)})" opacity=".9">
        <path d="M-8,0 H8 M0,-8 V8" stroke="#fff" stroke-width="4"/><path d="M-8,0 H8 M0,-8 V8" stroke="#8d3c00" stroke-width="2"/>
        <text y="-12" text-anchor="middle" class="croqui-rotulo">sede</text></g>`;
    }
    // Escala + norte
    const lat0 = Croqui._geo(Croqui.vista.cx, Croqui.vista.cy)[0];
    const mPorPx = 156543.03392 * Math.cos(lat0 * Math.PI / 180) / Math.pow(2, Croqui.vista.z);
    const alvoM = (larg / 4) * mPorPx;
    const passo = Math.pow(10, Math.floor(Math.log10(Math.max(1, alvoM))));
    const escalaM = passo * Math.max(1, Math.floor(alvoM / passo));
    const escalaPx = escalaM / mPorPx;
    const yEsc = alt - (Croqui._cheio() ? 100 : 0); // tela cheia: acima da barra flutuante
    svg += `<g class="croqui-escala"><rect x="14" y="${yEsc - 34}" width="${(escalaPx + 14).toFixed(1)}" height="24" rx="5" fill="#fff" opacity=".75"/>
      <line x1="20" y1="${yEsc - 16}" x2="${(20 + escalaPx).toFixed(1)}" y2="${yEsc - 16}" stroke="#222" stroke-width="2"/>
      <text x="${(20 + escalaPx / 2).toFixed(1)}" y="${yEsc - 21}" text-anchor="middle" font-size="11" fill="#222">${escalaM >= 1000 ? (escalaM / 1000) + ' km' : escalaM + ' m'}</text></g>
      <g transform="translate(${larg - 26},34)"><circle r="14" fill="#fff" opacity=".75"/><path d="M0,-9 L4,5 L0,2 L-4,5 Z" fill="#222"/><text y="-14" text-anchor="middle" font-size="10" fill="#fff" stroke="#333" stroke-width=".4">N</text></g>`;

    if (podeLeve) {
      // Render leve: só troca o desenho; os tiles do satélite ficam INTACTOS (não piscam).
      svgEl.innerHTML = svg;
    } else {
      palco.innerHTML = `
        <div class="croqui-tiles">${tilesHtml}</div>
        ${labelsHtml ? `<div class="croqui-tiles croqui-labels">${labelsHtml}</div>` : ''}
        <svg id="croquiSvg" viewBox="0 0 ${larg} ${alt}" width="${larg}" height="${alt}"></svg>
        <div class="croqui-zoom">
          <button type="button" class="btn btn-light btn-sm" onclick="Croqui.zoom(1)" title="Aproximar"><i class="bi bi-plus-lg"></i></button>
          <button type="button" class="btn btn-light btn-sm" onclick="Croqui.zoom(-1)" title="Afastar"><i class="bi bi-dash-lg"></i></button>
          <button type="button" class="btn btn-light btn-sm" onclick="Croqui.telaCheia()" title="${Croqui._cheio() ? 'Voltar à tela normal' : 'Mapa na tela toda'}"><i class="bi ${Croqui._cheio() ? 'bi-fullscreen-exit' : 'bi-arrows-fullscreen'}"></i></button>
        </div>
        ${Croqui.tiles ? `<div class="croqui-atribuicao">${App.escapeHtml(Croqui.tiles.atribuicao || '')}</div>` : ''}`;
      palco.querySelector('#croquiSvg').innerHTML = svg;
    }
    document.getElementById('croquiLegenda').innerHTML = legenda.join('');
    Croqui._atualizarArea();
  },

  _atualizarArea() {
    const ehImovel = Croqui.atualId === 0, ehPlantio = Croqui._ehPlantio(Croqui.atualId);
    const areaAtual = ehPlantio ? Croqui._areaPlantioDe(Croqui.atualId) : null;
    const alvo = ehImovel ? (Croqui.imovel || {}) : (ehPlantio ? (areaAtual || {}) : (Croqui.talhoes.find(x => Number(x.id) === Croqui.atualId) || {}));
    const medida = Croqui.areaHa(Croqui.pontos);
    const rotuloAlvo = ehImovel ? 'Imóvel (área total)' : (ehPlantio ? (areaAtual ? 'Área de plantio "' + App.escapeHtml(areaAtual.nome) + '"' : 'Nova área de plantio') : 'Talhão');
    const cadastrada = Number((ehPlantio ? alvo.area_gps : alvo.area_ha) || 0);
    const regra = Croqui._validarRegra();
    const fmt = (v, d = 1) => Number(v).toLocaleString('pt-BR', { maximumFractionDigits: d });
    let alerta = '';
    if (regra.sobrepostos && regra.sobrepostos.length) {
      alerta = ehPlantio
        ? ` <span class="text-danger fw-semibold"><i class="bi bi-exclamation-triangle-fill"></i> cobre outra área de plantio: ${App.escapeHtml(regra.sobrepostos.join(', '))} — as áreas não se sobrepõem</span>`
        : ` <span class="text-danger fw-semibold"><i class="bi bi-exclamation-triangle-fill"></i> cobre outro talhão: ${App.escapeHtml(regra.sobrepostos.join(', '))} — talhões não se sobrepõem</span>`;
    } else if (regra.fora.length) {
      alerta = regra.semLimite
        ? ` <span class="text-danger fw-semibold"><i class="bi bi-exclamation-triangle-fill"></i> o talhão precisa ficar dentro de uma área de plantio — toque dentro de uma área verde</span>`
        : ` <span class="text-danger fw-semibold"><i class="bi bi-exclamation-triangle-fill"></i> ${regra.fora.length} ponto(s) ${Croqui._ehTalhao(Croqui.atualId) ? 'fora da área de plantio ou dentro de outro talhão' : (ehPlantio ? 'fora da divisa ou dentro de outra área de plantio' : 'fora da divisa do imóvel')}</span>`;
    } else if (regra.linhasFora && regra.linhasFora.length) {
      alerta = ` <span class="text-danger fw-semibold"><i class="bi bi-exclamation-triangle-fill"></i> ${regra.linhasFora.length} linha(s) fora da área do CAR — toque na linha vermelha para acrescentar um ponto e puxe-o para dentro</span>`;
    } else if (regra.talhoesFora.length) {
      alerta = ` <span class="text-danger fw-semibold"><i class="bi bi-exclamation-triangle-fill"></i> ${ehPlantio ? 'a área deixaria para fora o talhão' : 'divisa deixa fora'}: ${App.escapeHtml(regra.talhoesFora.join(', '))}</span>`;
    } else if (regra.avisos.length) {
      alerta = ` <span class="text-warning-emphasis"><i class="bi bi-exclamation-circle"></i> ${ehPlantio ? 'talhões fora da área de plantio: ' + App.escapeHtml(regra.avisos.join(', ')) : App.escapeHtml(regra.avisos.join(', '))}</span>`;
    }
    document.getElementById('croquiArea').innerHTML = (Croqui.pontos.length >= 3
      ? `<strong>${rotuloAlvo}: ${fmt(medida, 2)} ha</strong> <span class="text-muted">(cadastrada: ${fmt(cadastrada)} ha)</span>`
      : `<span class="text-muted">${Croqui.pontos.length} ponto(s) — marque pelo menos 3 para fechar a área</span>`) + alerta;

    // Resumo (v40): divisa × área de plantio × talhões POR CULTURA E FINALIDADE
    const areaDe = (id, gravada) => {
      if (Number(id) === Croqui.atualId) return Croqui.pontos.length >= 3 ? medida : 0;
      return Number(gravada || 0);
    };
    const areaImovel = areaDe(0, Croqui.imovel && (Croqui.imovel.area_gps || Croqui.imovel.area_ha));
    // v44: área de plantio = soma das áreas desenhadas (a em edição entra pela medida da tela)
    let areaPlantio = 0;
    Croqui.areasPlantio.forEach(a => { areaPlantio += areaDe(Croqui._alvoPlantio(a.id), a.area_gps); });
    if (Croqui.atualId === Croqui.PLANTIO_ID && Croqui.pontos.length >= 3) areaPlantio += medida;
    if (areaPlantio <= 0) areaPlantio = Number(Croqui.imovel && (Croqui.imovel.area_plantio_gps || Croqui.imovel.area_plantio_ha) || 0); // legado
    const plantioEhTotal = areaPlantio <= 0;
    if (plantioEhTotal) areaPlantio = areaImovel;
    const grupos = new Map();
    let soma = 0;
    Croqui.talhoes.forEach(t => {
      const a = areaDe(t.id, t.area_gps || t.area_ha);
      if (a <= 0) return;
      const chave = (t.cultura || 'Sem cultura') + (t.finalidade ? ' · ' + t.finalidade : '');
      grupos.set(chave, (grupos.get(chave) || 0) + a);
      soma += a;
    });
    const partes = [];
    if (areaImovel > 0) partes.push(`Imóvel <strong>${fmt(areaImovel)} ha</strong>`);
    if (areaPlantio > 0) partes.push(`Plantio <strong>${fmt(areaPlantio)} ha</strong>${plantioEhTotal ? ' <span class="text-muted">(= total)</span>' : ''}`);
    [...grupos.entries()].sort((a, b) => b[1] - a[1]).forEach(([k, v]) => {
      const pct = areaPlantio > 0 ? ` (${fmt(v / areaPlantio * 100, 0)}%)` : '';
      partes.push(`${App.escapeHtml(k)} <strong>${fmt(v)} ha</strong>${pct}`);
    });
    if (areaPlantio > 0 && soma > areaPlantio + 0.05) {
      partes.push(`<span class="text-danger fw-semibold"><i class="bi bi-exclamation-triangle-fill"></i> talhões passam ${fmt(soma - areaPlantio)} ha da área de plantio</span>`);
    } else if (areaPlantio > 0 && areaPlantio - soma > 0.05 && soma > 0) {
      partes.push(`<span class="text-muted">sem talhão ${fmt(areaPlantio - soma)} ha</span>`);
    }
    const totais = document.getElementById('croquiTotais');
    if (totais) totais.innerHTML = partes.join(' · ');
    const cheioArea = document.getElementById('croquiCheioArea'); // barra da tela cheia espelha o painel
    if (cheioArea) cheioArea.innerHTML = document.getElementById('croquiArea').innerHTML;
  },

  _prepararEventos() {
    if (Croqui._eventosOk) return;
    Croqui._eventosOk = true;
    // Esc/backdrop fecham o modal sem passar pelo botão X: garante parar o
    // GPS e atualizar a ficha em QUALQUER forma de fechar
    document.getElementById('modalCroqui').addEventListener('hidden.bs.modal', () => Croqui.fechar());
    const palco = document.getElementById('croquiPalco');
    const pos = ev => {
      const r = palco.getBoundingClientRect();
      return [ev.clientX - r.left, ev.clientY - r.top, r.width, r.height];
    };
    palco.addEventListener('pointerdown', ev => {
      if (ev.target.closest('.croqui-zoom')) return;
      // Soltar fora do palco ainda dispara o pointerup (alguns navegadores lançam
      // erro se o ponteiro já não existe — não pode matar o gesto)
      try { palco.setPointerCapture(ev.pointerId); } catch (e) { /* segue sem captura */ }
      Croqui._ponteiros.set(ev.pointerId, { x: ev.clientX, y: ev.clientY });
      // Dois dedos = PINÇA (zoom contínuo ancorado no ponto médio dos dedos)
      if (Croqui._ponteiros.size === 2 && Croqui.vista) {
        Croqui._arrasto = null;
        Croqui._pan = null;
        const [a, b] = [...Croqui._ponteiros.values()];
        const r = palco.getBoundingClientRect();
        const e = Croqui._escala();
        const mx = (a.x + b.x) / 2 - r.left, my = (a.y + b.y) / 2 - r.top;
        Croqui._pinch = {
          d0: Math.max(1, Math.hypot(a.x - b.x, a.y - b.y)),
          z0: Croqui.vista.z,
          wx: Croqui.vista.cx + (mx - r.width / 2) / e,   // ponto do mundo sob a pinça
          wy: Croqui.vista.cy + (my - r.height / 2) / e,
        };
        ev.preventDefault();
        return;
      }
      if (!ev.isPrimary) return;
      const v = ev.target.closest('.croqui-vertice');
      if (v) {
        // Pega o vértice: ARRASTO (ajustar) ou TOQUE (2 toques = remover) — decidido no move/up
        Croqui._arrasto = Number(v.dataset.idx);
        Croqui._arrastoIni = { x: ev.clientX, y: ev.clientY };
        Croqui._arrastoMoveu = false;
        Croqui._snapshot();
        ev.preventDefault();
        return;
      }
      if (!Croqui.vista) return;
      Croqui._pan = { x: ev.clientX, y: ev.clientY, cx0: Croqui.vista.cx, cy0: Croqui.vista.cy, moved: false };
      ev.preventDefault();
    });
    palco.addEventListener('pointermove', ev => {
      if (Croqui._ponteiros.has(ev.pointerId)) Croqui._ponteiros.set(ev.pointerId, { x: ev.clientX, y: ev.clientY });
      if (Croqui._pinch && Croqui._ponteiros.size >= 2 && Croqui.vista) {
        const [a, b] = [...Croqui._ponteiros.values()];
        const r = palco.getBoundingClientRect();
        const d = Math.max(1, Math.hypot(a.x - b.x, a.y - b.y));
        Croqui.vista.z = Math.max(3, Math.min(19, Croqui._pinch.z0 + Math.log2(d / Croqui._pinch.d0)));
        const e2 = Croqui._escala();
        const mx = (a.x + b.x) / 2 - r.left, my = (a.y + b.y) / 2 - r.top;
        // O ponto do mundo que estava sob os dedos segue os dedos (zoom + pan juntos)
        Croqui.vista.cx = Croqui._pinch.wx - (mx - r.width / 2) / e2;
        Croqui.vista.cy = Croqui._pinch.wy - (my - r.height / 2) / e2;
        Croqui.render();
        ev.preventDefault();
        return;
      }
      if (!ev.isPrimary) return;
      if (Croqui._arrasto !== null && Croqui.vista) {
        if (!Croqui._arrastoMoveu) {
          // Ainda pode ser um toque (seleção): só vira arrasto ao passar do limiar
          const d = Croqui._arrastoIni ? Math.hypot(ev.clientX - Croqui._arrastoIni.x, ev.clientY - Croqui._arrastoIni.y) : 99;
          if (d <= 6) { ev.preventDefault(); return; }
          Croqui._arrastoMoveu = true;
          Croqui._selecionado = null; // arrastar cancela a seleção
        }
        const [x, y, w, h] = pos(ev);
        Croqui.pontos[Croqui._arrasto] = Croqui._prender(Croqui._paraGeo(x, y, w, h));
        Croqui._dirty = true;
        Croqui.render(true); // leve: mapa FIXO enquanto arrasta o ponto (não pisca/recarrega)
        ev.preventDefault();
        return;
      }
      if (Croqui._pan) {
        const dx = ev.clientX - Croqui._pan.x, dy = ev.clientY - Croqui._pan.y;
        if (Math.hypot(dx, dy) > 6) Croqui._pan.moved = true;
        if (Croqui._pan.moved) {
          const e = Croqui._escala();
          Croqui.vista.cx = Croqui._pan.cx0 - dx / e;
          Croqui.vista.cy = Croqui._pan.cy0 - dy / e;
          Croqui.render();
        }
        ev.preventDefault();
      }
    });
    ['pointerup', 'pointercancel'].forEach(n => palco.addEventListener(n, ev => {
      Croqui._ponteiros.delete(ev.pointerId);
      if (Croqui._pinch) {
        // Fim (ou redução) da pinça: nunca vira clique/ponto
        if (Croqui._ponteiros.size < 2) { Croqui._pinch = null; Croqui._agendarRecargaCar(); }
        return;
      }
      if (Croqui._arrasto !== null) {
        const idx = Croqui._arrasto, moveu = Croqui._arrastoMoveu;
        Croqui._arrasto = null; Croqui._arrastoMoveu = false; Croqui._arrastoIni = null;
        if (moveu) { if (Croqui._margearDivisa()) Croqui.render(); return; } // soltou: margeia a divisa se precisar
        if (ev.type !== 'pointerup') { Croqui._undo.pop(); return; }
        Croqui._undo.pop(); // toque sem arrasto: não é uma ação a desfazer
        // Toque no ponto (sem arrastar): DUPLO toque no mesmo ponto = REMOVE direto.
        // O 1º toque só realça o ponto (feedback "toque de novo para remover").
        const lt = Croqui._ultimoTap;
        const duplo = lt && lt.tipo === 'vertice' && lt.idx === idx && (ev.timeStamp - lt.t) < Croqui._DUPLO_MS;
        if (duplo) {
          Croqui._ultimoTap = null;
          Croqui._removerPonto(idx);
        } else {
          Croqui._ultimoTap = { tipo: 'vertice', idx, t: ev.timeStamp };
          Croqui._selecionado = idx;
          Croqui.render();
        }
        return;
      }
      if (Croqui._pan) {
        // Gesto CANCELADO pelo navegador (ligação, palm rejection) nunca vira ponto
        const foiClique = ev.type === 'pointerup' && ev.isPrimary && !Croqui._pan.moved;
        const arrastou = Croqui._pan.moved;
        Croqui._pan = null;
        if (arrastou) Croqui._agendarRecargaCar(); // moveu o mapa: recarrega o CAR da nova área
        if (foiClique && Croqui.vista && !ev.target.closest('.croqui-zoom')) {
          const [x, y, w, h] = pos(ev);
          const geo = Croqui._paraGeo(x, y, w, h);
          // PRIORIDADE: se o toque cai SOBRE a linha ciano que você edita, é edição da
          // divisa — nunca "adotar CAR" (mesmo com o overlay do CAR ligado). Assim, dois
          // toques na linha inserem um ponto sem disparar "substituir pela área do CAR".
          const manual = document.getElementById('croquiModoManual').checked;
          const aresta = (manual && Croqui.pontos.length >= 3) ? Croqui._arestaProxima(x, y, w, h) : -1;
          const lt = Croqui._ultimoTap;
          if (aresta >= 0) {
            // DUPLO toque sobre a linha = INSERE um ponto ali. O 1º toque só aguarda o 2º.
            const duplo = lt && lt.tipo === 'linha' && (ev.timeStamp - lt.t) < Croqui._DUPLO_MS
              && Math.hypot(x - lt.x, y - lt.y) < Croqui._DUPLO_PX;
            if (duplo) {
              Croqui._ultimoTap = null;
              Croqui._snapshot();
              Croqui.pontos.splice(aresta + 1, 0, Croqui._prender(geo));
              Croqui._selecionado = aresta + 1; // realça o ponto recém-criado
              if (Croqui._margearDivisa()) Croqui._selecionado = null;
              Croqui._dirty = true;
              Croqui.render();
            } else {
              Croqui._ultimoTap = { tipo: 'linha', x, y, t: ev.timeStamp };
              if (Croqui._selecionado !== null) { Croqui._selecionado = null; Croqui.render(); }
            }
          } else if (Croqui.carLayerOn && Croqui.atualId === 0) {
            // Fora da linha, com overlay ligado e editando a DIVISA: toque na área de um
            // imóvel do CAR = adotar a divisa. Em "Área de plantio"/talhão o overlay fica
            // só como referência (amarelo) e o toque DESENHA — bug do teste de campo:
            // com o overlay auto-ligado não dava para marcar a área de plantio.
            const im = Croqui._carDoMapaNoPonto(geo[0], geo[1]);
            if (im) Croqui._selecionarCarDoMapa(im);
          } else if (manual && Croqui._selecionado !== null) {
            // Ponto realçado + toque no vazio = só tira o realce (não desenha)
            Croqui._selecionado = null; Croqui._ultimoTap = null;
            Croqui.render();
          } else if (manual) {
            // Toque no VAZIO (longe das linhas) = adiciona um ponto no fim (desenhar)
            Croqui._ultimoTap = null;
            Croqui._snapshot();
            Croqui.pontos.push(Croqui._prender(geo));
            Croqui._margearDivisa(); // dois pontos na divisa com a reta saindo do CAR → segue a borda
            Croqui._dirty = true;
            Croqui.render();
          }
        }
      }
    }));
    palco.addEventListener('wheel', ev => {
      ev.preventDefault();
      Croqui.zoom(ev.deltaY < 0 ? 1 : -1);
    }, { passive: false });
    window.addEventListener('resize', () => { if (document.querySelector('#modalCroqui.show')) Croqui.render(); });
  },

  desfazer() {
    // Desfaz a ÚLTIMA AÇÃO inteira (um toque que margeou a divisa pode ter inserido vários pontos)
    const ant = Croqui._undo.pop();
    if (ant) Croqui.pontos = ant; else Croqui.pontos.pop();
    Croqui._selecionado = null; Croqui._ultimoTap = null; Croqui._dirty = true; Croqui.render();
  },

  /** Remove um ponto específico (duplo toque no ponto). */
  _removerPonto(i) {
    if (i < 0 || i >= Croqui.pontos.length) return;
    Croqui._snapshot();
    Croqui.pontos.splice(i, 1);
    Croqui._margearDivisa();
    Croqui._selecionado = null;
    Croqui._ultimoTap = null;
    Croqui._dirty = true;
    Croqui.render();
  },

  limpar() {
    if (!confirm('Apagar todos os pontos deste contorno?')) return;
    Croqui._snapshot(); // Desfazer traz o contorno de volta
    Croqui.pontos = [];
    Croqui._selecionado = null;
    Croqui._ultimoTap = null;
    Croqui._dirty = true;
    Croqui.render();
  },

  async salvar() {
    if (Croqui.pontos.length > 0 && Croqui.pontos.length < 3) {
      App.alerta('Marque pelo menos 3 pontos para fechar a área (ou Limpar para remover o croqui).', 'warning');
      return;
    }
    // REGRAS (v40): área de plantio e talhão dentro da divisa do imóvel; a divisa
    // não deixa nada para fora (o servidor também valida)
    const regra = Croqui._validarRegra();
    // Sobreposição primeiro: é a causa mais provável dos pontos vermelhos num talhão
    if (regra.sobrepostos && regra.sobrepostos.length) {
      App.alerta(Croqui._ehPlantio(Croqui.atualId)
        ? 'A área de plantio cobre outra área de plantio: ' + regra.sobrepostos.join(', ') + '. As áreas não se sobrepõem — ajuste os pontos em vermelho.'
        : 'O talhão cobre outro talhão: ' + regra.sobrepostos.join(', ') + '. Um talhão não pode passar por cima de outro — ajuste os pontos em vermelho.', 'danger');
      return;
    }
    if (regra.fora.length) {
      App.alerta(Croqui._ehPlantio(Croqui.atualId)
        ? `A área de plantio deve ficar DENTRO da divisa do imóvel e fora das outras áreas de plantio — ajuste os ${regra.fora.length} ponto(s) em vermelho.`
        : (regra.semLimite ? 'O talhão precisa ficar dentro de uma área de plantio — toque dentro de uma das áreas verdes.'
          : `O talhão deve ficar dentro da área de plantio e fora dos outros talhões — ajuste os ${regra.fora.length} ponto(s) em vermelho.`), 'danger');
      return;
    }
    if (regra.linhasFora && regra.linhasFora.length) {
      App.alerta(`Nenhuma linha pode sair da área do CAR — ${regra.linhasFora.length} linha(s) em vermelho atravessam a divisa. Toque na linha vermelha para acrescentar um ponto e puxe-o para dentro.`, 'danger');
      return;
    }
    if (regra.talhoesFora.length) {
      App.alerta((Croqui._ehPlantio(Croqui.atualId) ? 'A área de plantio deixaria para fora o(s) talhão(ões): ' : 'A divisa deixaria para fora: ') + regra.talhoesFora.join(', ') + '. Amplie a área ou ajuste antes.', 'danger');
      return;
    }
    // NOVO talhão: desenhou primeiro; agora dá nome, cultura e finalidade (o modal grava tudo junto)
    if (Croqui.atualId === Croqui.NOVO_ID) {
      if (Croqui.pontos.length < 3) { App.alerta('Marque pelo menos 3 pontos para fechar o talhão.', 'warning'); return; }
      Clientes.novoTalhaoDoCroqui(Croqui.pontos.map(p => [Number(Number(p[0]).toFixed(7)), Number(Number(p[1]).toFixed(7))]));
      return;
    }
    const ehImovel = Croqui.atualId === 0, ehPlantio = Croqui._ehPlantio(Croqui.atualId);
    const areaAtual = ehPlantio ? Croqui._areaPlantioDe(Croqui.atualId) : null;
    const alvo = ehImovel ? Croqui.imovel : (ehPlantio ? areaAtual : Croqui.talhoes.find(x => Number(x.id) === Croqui.atualId));
    const tipo = ehImovel ? 'imovel' : (ehPlantio ? 'plantio' : 'talhao');
    // v44: nova área de plantio pede um nome (pode ficar em branco → "Área de plantio N")
    let nomePlantio = '';
    if (ehPlantio && !areaAtual) {
      if (Croqui.pontos.length < 3) { App.alerta('Marque pelo menos 3 pontos para fechar a área de plantio.', 'warning'); return; }
      const sugestao = 'Área de plantio' + (Croqui.areasPlantio.length ? ' ' + (Croqui.areasPlantio.length + 1) : '');
      const n = prompt('Nome desta área de plantio (ex.: Campo, Morro):', sugestao);
      if (n === null) return;
      nomePlantio = n.trim() || sugestao;
    }
    const fd = new FormData();
    fd.append('tipo', tipo);
    fd.append(tipo === 'talhao' ? 'talhao_id' : 'imovel_id', tipo === 'talhao' ? Croqui.atualId : Croqui.imovel.id);
    if (ehPlantio) { fd.append('plantio_id', areaAtual ? areaAtual.id : 0); if (nomePlantio) fd.append('nome', nomePlantio); }
    fd.append('contorno', JSON.stringify(Croqui.pontos.map(p => [Number(Number(p[0]).toFixed(7)), Number(Number(p[1]).toFixed(7))])));
    fd.append('usar_area', document.getElementById('croquiUsarArea').checked ? '1' : '0');
    // Divisa veio do CAR (identificação por GPS): grava o nº do imóvel junto
    if (ehImovel && Croqui._carCod) fd.append('car_numero', Croqui._carCod);
    const rotuloAlvo = ehImovel ? 'divisa · ' + (Croqui.imovel.rotulo || '') : (ehPlantio ? 'área de plantio ' + (areaAtual ? areaAtual.nome : nomePlantio) + ' · ' + (Croqui.imovel.rotulo || '') : (alvo ? alvo.nome : 'talhão'));
    try {
      const r = await App.enviarFormOffline(fd, 'index.php?r=clientes/salvar-croqui',
        { modulo: 'Croqui', rotulo: 'Croqui — ' + Croqui.prop.nome + ' · ' + rotuloAlvo });
      const json = Croqui.pontos.length ? JSON.stringify(Croqui.pontos) : null;
      const area = r.area_gps ?? Croqui.areaHa(Croqui.pontos).toFixed(2);
      const usar = document.getElementById('croquiUsarArea').checked && Croqui.pontos.length >= 3;
      if (ehPlantio) {
        // v44: atualiza a lista de áreas (nova, editada ou removida) e mantém o alvo coerente
        if (!Croqui.pontos.length) {
          if (areaAtual) Croqui.areasPlantio = Croqui.areasPlantio.filter(a => Number(a.id) !== Number(areaAtual.id));
          Croqui._montarSelect(Croqui.PLANTIO_ID); Croqui.atualId = Croqui.PLANTIO_ID;
        } else if (r.plantio) {
          const i = Croqui.areasPlantio.findIndex(a => Number(a.id) === Number(r.plantio.id));
          if (i >= 0) Croqui.areasPlantio[i] = r.plantio; else Croqui.areasPlantio.push(r.plantio);
          Croqui._montarSelect(Croqui._alvoPlantio(r.plantio.id)); Croqui.atualId = Croqui._alvoPlantio(r.plantio.id);
          Croqui.pontos = Croqui._contornoDe(Croqui.atualId);
        } else if (areaAtual) {
          areaAtual.contorno = json; areaAtual.area_gps = area; // offline: fila
        } else {
          // offline: nova área ainda sem id — entra provisória para o resumo; o servidor cria ao sincronizar
          Croqui.areasPlantio.push({ id: -Date.now(), nome: nomePlantio, contorno: json, area_gps: area, provisoria: true });
          Croqui._montarSelect(Croqui.PLANTIO_ID); Croqui.atualId = Croqui.PLANTIO_ID; Croqui.pontos = [];
        }
        Croqui._botoesPorAlvo();
      } else {
        alvo.contorno = json; alvo.area_gps = area;
        if (usar) alvo.area_ha = area;
        if (Croqui._ehTalhao(Croqui.atualId) && r.talhao && r.talhao.area_plantio_id) alvo.area_plantio_id = r.talhao.area_plantio_id;
      }
      Croqui._dirty = false;
      Croqui._atualizarEtapas();
      let msg = r.offline ? 'Sem sinal: croqui salvo na fila — será enviado ao reconectar.'
        : (Croqui.pontos.length ? `Croqui salvo — ${Number(area).toLocaleString('pt-BR')} ha medidos.` : 'Croqui removido.');
      if (r.talhoes_fora && r.talhoes_fora.length) msg += ' Atenção: talhão(ões) fora da área de plantio: ' + r.talhoes_fora.join(', ') + '.';
      App.alerta(msg, r.offline ? 'info' : (r.talhoes_fora && r.talhoes_fora.length ? 'warning' : 'success'));
      Croqui.render();
    } catch (e) { App.alerta(e.message, 'danger'); }
  },
};

/* ============================== VISITAS ============================== */

const Visitas = {
  etapa: 1,
  apoio: null,
  // Estado da linha do tempo fenológica (modal de identificação de estágio)
  _fenoEstagios: [],
  _fenoIdx: 0,
  _fenoCulturaId: null,
  _fenoManualId: null,
  _fenoTalhao: null,

  /** Reseta e abre o modal (comum a nova visita e completar cadastro). */
  _prepararModal(titulo) {
    const form = document.getElementById('formVisita');
    form.reset();
    // Limpa o estado do cliente anterior (evita mostrar propriedades/talhões/painel de outro produtor)
    Visitas.apoio = null;
    document.getElementById('visitaPropriedade').innerHTML = '';
    document.getElementById('visitaTalhao').innerHTML = '';
    document.getElementById('visitaPainelComercial').innerHTML = '<span class="text-muted small">Selecione o cliente na etapa 1 para carregar os dados comerciais.</span>';
    document.getElementById('visitaModelos').innerHTML = '<span class="text-muted small">Escolha a cultura na etapa 1 para listar os modelos.</span>';
    document.querySelectorAll('#formVisita textarea.auto-crescer').forEach(t => { delete t.dataset.alturaManual; t.style.height = ''; });
    const fen = document.getElementById('visitaFenologia'); if (fen) fen.innerHTML = '';
    const chk = document.getElementById('visitaChecklist'); if (chk) chk.innerHTML = '';
    Visitas._fenoManualId = null;
    Visitas._fenoTalhao = null;
    // Estado do "Iniciar Visita" e dos chips de motivo
    const btnIni = document.getElementById('btnIniciarVisita');
    if (btnIni) btnIni.disabled = false;
    const stIni = document.getElementById('visitaInicioStatus');
    if (stIni) stIni.textContent = 'Obrigatório: toque ao chegar na propriedade — hora e local do início são registrados.';
    document.querySelectorAll('#visitaMotivos .motivo-chip').forEach(b => b.classList.remove('active'));
    Visitas.irParaEtapa(1);
    document.getElementById('visitaFotosPreview').innerHTML = '';
    Visitas.atualizarCompletude();
    const t = document.getElementById('visitaModalTitulo');
    if (t) t.textContent = titulo;
    document.getElementById('btnFinalizarDefinitivo')?.classList.add('d-none');
    new bootstrap.Modal('#modalVisita').show();
    return form;
  },

  nova(clienteId) {
    if (!document.getElementById('formVisita')) { location.href = 'index.php?r=visitas&nova=1' + (clienteId ? '&cliente_id=' + clienteId : ''); return; }
    Visitas._prepararModal('Nova Visita Técnica');

    const status = document.getElementById('visitaGeoStatus');
    status.innerHTML = '<i class="bi bi-geo-alt me-1"></i>capturando GPS…';
    App.capturarGeo('#formVisita', ok => {
      status.innerHTML = ok
        ? '<i class="bi bi-geo-alt-fill me-1 text-success"></i>GPS ok'
        : '<i class="bi bi-geo-alt me-1 text-muted"></i>sem GPS';
    });

    if (clienteId) {
      document.getElementById('visitaCliente').value = clienteId;
      Visitas.carregarApoio(clienteId);
    }
  },

  /** Abre uma visita não finalizada para completar o cadastro. */
  async editar(id) {
    if (!document.getElementById('formVisita')) { location.href = 'index.php?r=visitas&editar=' + Number(id); return; }
    let visita;
    try {
      ({ visita } = await App.json('index.php?r=visitas/dados&id=' + Number(id)));
    } catch (e) { App.alerta(e.message, 'danger'); return; }

    const form = Visitas._prepararModal('Completar Visita Técnica');
    // Mantém a localização registrada na visita original (não recaptura GPS)
    document.getElementById('visitaGeoStatus').innerHTML = '<i class="bi bi-geo-alt-fill me-1 text-success"></i>local original mantido';
    document.getElementById('btnFinalizarDefinitivo')?.classList.remove('d-none');
    form.querySelector('[name=id]').value = visita.id;
    form.querySelector('[name=latitude]').value = visita.latitude ?? '';
    form.querySelector('[name=longitude]').value = visita.longitude ?? '';

    document.getElementById('visitaCliente').value = visita.cliente_id;
    await Visitas.carregarApoio(visita.cliente_id);
    if (visita.propriedade_id) {
      document.getElementById('visitaPropriedade').value = visita.propriedade_id;
      Visitas.filtrarTalhoes();
    }
    if (visita.talhao_id) document.getElementById('visitaTalhao').value = visita.talhao_id;
    if (visita.cultura_id) {
      document.getElementById('visitaCultura').value = visita.cultura_id;
      Visitas.carregarModelos();
    }
    const dh = document.getElementById('visitaDataHora');
    if (dh && visita.data_visita) {
      dh.value = visita.data_visita + 'T' + String(visita.hora || '08:00').substring(0, 5);
      Visitas.sincronizarDataHora(dh);
    }
    ['objetivo', 'estagio_cultura', 'desenvolvimento', 'pragas', 'doencas', 'plantas_daninhas',
      'deficiencia_nutricional', 'condicoes_climaticas', 'observacoes', 'recomendacao'].forEach(nome => {
      const el = form.querySelector(`[name=${nome}]`);
      if (el) {
        el.value = visita[nome] || '';
        if (el.tagName === 'TEXTAREA') App.autoCrescer(el);
      }
    });
    // Iniciar Visita / presença: recupera o que já foi registrado
    if (visita.hora_inicio) {
      form.querySelector('[name=hora_inicio]').value = String(visita.hora_inicio).substring(0, 5);
      if (visita.hora_fim) form.querySelector('[name=hora_fim]').value = String(visita.hora_fim).substring(0, 5);
      Visitas._mostrarInicio(String(visita.hora_inicio).substring(0, 5),
        visita.inicio_precisao ? Number(visita.inicio_precisao) : null);
    }
    // GPS do início já registrado volta ao form (o UPDATE não pode perdê-lo)
    ['inicio_lat', 'inicio_lng', 'inicio_precisao'].forEach(n => {
      const el = form.querySelector(`[name=${n}]`);
      if (el && visita[n] !== null && visita[n] !== undefined) el.value = visita[n];
    });
    const presente = form.querySelector('[name=produtor_presente]');
    if (presente && visita.produtor_presente !== null && visita.produtor_presente !== undefined) {
      presente.checked = Number(visita.produtor_presente) === 1;
    }
    // Linha do tempo + checklist já marcado na visita original
    Visitas.renderFenologia(visita.checklist || []);
    Visitas.atualizarCompletude();
  },

  /** Motivo de 1 toque: preenche o Objetivo (o texto continua livre/editável). */
  usarMotivo(btn) {
    const campo = document.querySelector('#formVisita [name=objetivo]');
    if (!campo) return;
    campo.value = btn.textContent.trim();
    document.querySelectorAll('#visitaMotivos .motivo-chip').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    Visitas.atualizarCompletude();
  },

  _horaAgora() {
    const d = new Date();
    return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
  },

  /**
   * "Iniciar Visita": carimba a chegada (a hora_fim é gravada ao salvar) e,
   * em segundo plano, captura o GPS — o servidor confere se o lançamento
   * aconteceu na propriedade cadastrada (auditoria de campo).
   */
  iniciarVisita() {
    const form = document.getElementById('formVisita');
    const hora = Visitas._horaAgora();
    form.querySelector('[name=hora_inicio]').value = hora;
    Visitas._mostrarInicio(hora);
    if (navigator.geolocation) {
      navigator.geolocation.getCurrentPosition(pos => {
        form.querySelector('[name=inicio_lat]').value = pos.coords.latitude.toFixed(7);
        form.querySelector('[name=inicio_lng]').value = pos.coords.longitude.toFixed(7);
        form.querySelector('[name=inicio_precisao]').value = Math.round(pos.coords.accuracy);
        Visitas._mostrarInicio(hora, Math.round(pos.coords.accuracy));
        Visitas.proporVinculo(pos.coords.latitude, pos.coords.longitude); // Mapa Territorial: propõe vínculo CAR↔produtor
      }, () => { /* sem GPS: segue só com a hora */ }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 15000 });
    }
  },

  /** Após o Iniciar Visita pegar o GPS, propõe vincular o imóvel do CAR ao produtor.
   *  Nunca vincula em silêncio: o RTV confirma na tela (spec Mapa Territorial §6). */
  async proporVinculo(lat, lng) {
    const box = document.getElementById('visitaPropostaVinculo');
    const sel = document.getElementById('visitaCliente');
    const clienteId = sel ? Number(sel.value) : 0;
    if (!box || !clienteId) return;
    try {
      // POST no corpo: coordenada da propriedade nunca vai em query string (Inv.4)
      const fd = new FormData();
      fd.append('lat', lat.toFixed(7));
      fd.append('lng', lng.toFixed(7));
      fd.append('produtor', clienteId);
      const r = await App.json('index.php?r=territorio/localizar', { method: 'POST', body: fd });
      const nome = sel.selectedOptions[0] ? sel.selectedOptions[0].text : 'este produtor';
      Visitas._vinculoCtx = { clienteId, nome };
      const ms = (r.match || []).filter(Boolean);
      if (!ms.length) { Visitas.fecharProposta(); return; }
      // exato e único já vinculado a este produtor → nada a propor
      if (r.exato && ms.length === 1 && ms[0].jaVinculado) { Visitas.fecharProposta(); return; }
      const btn = m => `<button type="button" class="btn btn-sm ${m.jaVinculado ? 'btn-outline-secondary' : 'btn-success'} me-1 mb-1"`
        + (m.jaVinculado ? ' disabled' : ` onclick="Visitas.confirmarVinculo('${App.escapeHtml(m.codCar)}')"`) + '>'
        + `<i class="bi bi-link-45deg me-1"></i>${App.escapeHtml(m.nomeImovel || m.codCar)}`
        + (m.distanciaM ? ` <span class="text-muted">· ${m.distanciaM} m</span>` : '')
        + (m.jaVinculado ? ' <span class="text-muted">(já vinculado)</span>' : '') + '</button>';
      let html;
      if (r.exato && ms.length === 1) {
        const m = ms[0];
        html = `<div class="alert alert-info py-2 mb-0"><i class="bi bi-geo-alt-fill me-1"></i>`
          + `Você está no imóvel do CAR <strong>${App.escapeHtml(m.nomeImovel || m.codCar)}</strong> `
          + `(${Number(m.areaHa).toLocaleString('pt-BR')} ha). Vincular ao produtor <strong>${App.escapeHtml(nome)}</strong>?`
          + `<div class="mt-2 d-flex gap-2 flex-wrap"><button type="button" class="btn btn-sm btn-success" onclick="Visitas.confirmarVinculo('${App.escapeHtml(m.codCar)}')"><i class="bi bi-link-45deg me-1"></i>Vincular</button>`
          + `<button type="button" class="btn btn-sm btn-outline-secondary" onclick="Visitas.fecharProposta()">Agora não</button></div></div>`;
      } else {
        html = `<div class="alert alert-warning py-2 mb-0"><i class="bi bi-geo-alt me-1"></i>`
          + (r.exato ? 'O ponto caiu em mais de um imóvel do CAR — escolha o correto' : 'Não caiu exatamente sobre um imóvel do CAR — imóveis próximos')
          + ` para vincular ao produtor <strong>${App.escapeHtml(nome)}</strong>:<div class="mt-2">${ms.map(btn).join('')}</div>`
          + `<button type="button" class="btn btn-sm btn-outline-secondary mt-1" onclick="Visitas.fecharProposta()">Agora não</button></div>`;
      }
      box.innerHTML = html;
      box.classList.remove('d-none');
    } catch (e) { Visitas.fecharProposta(); /* sem base do CAR/erro: não atrapalha a visita */ }
  },

  async confirmarVinculo(codCar) {
    const ctx = Visitas._vinculoCtx || {};
    if (!ctx.clienteId) return;
    try {
      const fd = new FormData();
      fd.append('cod_car', codCar);
      fd.append('produtor_id', ctx.clienteId);
      fd.append('papel', 'proprietario');
      fd.append('origem', 'gps_visita');
      fd.append('principal', '0');
      await App.json('index.php?r=territorio/vincular', { method: 'POST', body: fd });
      App.alerta('Imóvel vinculado ao produtor (origem: visita por GPS).', 'success');
      Visitas.fecharProposta();
    } catch (e) { App.alerta(e.message, 'danger'); }
  },

  fecharProposta() {
    const box = document.getElementById('visitaPropostaVinculo');
    if (box) { box.classList.add('d-none'); box.innerHTML = ''; }
  },

  _mostrarInicio(hora, precisao) {
    const status = document.getElementById('visitaInicioStatus');
    const btn = document.getElementById('btnIniciarVisita');
    const gps = (precisao || precisao === 0) ? ` · local registrado (±${precisao} m)` : '';
    if (status) status.innerHTML = `<span class="text-success fw-semibold"><i class="bi bi-check-circle-fill me-1"></i>Iniciada às ${App.escapeHtml(hora)}${gps}</span> — a duração é registrada ao salvar.`;
    if (btn) btn.disabled = true;
  },

  /** Divide o campo único de data+hora nos campos que o servidor espera. */
  sincronizarDataHora(input) {
    const form = input.closest('form');
    const [d = '', h = ''] = (input.value || '').split('T');
    form.querySelector('[name=data_visita]').value = d;
    form.querySelector('[name=hora]').value = h;
    Visitas.renderFenologia(); // o DAP (e a fase) dependem da data da visita
  },

  async carregarApoio(clienteId) {
    if (!clienteId) return;
    let dados;
    try {
      dados = await App.json(`index.php?r=visitas/apoio-modal&cliente_id=${clienteId}`);
    } catch (e) {
      // Offline/erro de rede: usa o snapshot da carteira salvo no aparelho
      dados = await Visitas.apoioOffline(clienteId);
      if (!dados) { App.alerta('Sem conexão e sem carteira salva no aparelho para este produtor.', 'warning'); return; }
      // Offline não dá para completar a visita pendente — bloqueia a nova (seria recusada no sync)
      const fv = document.getElementById('formVisita');
      if (fv && Number(fv.querySelector('[name=id]').value) === 0 && dados._pendente) {
        App.alerta('Este produtor tem uma visita com cadastro incompleto. Complete-a quando tiver conexão antes de registrar uma nova.', 'warning');
        return;
      }
      App.alerta('Modo offline: usando a carteira salva' + (dados._atualizado ? ' de ' + dados._atualizado : '') + '.', 'info');
    }
    // Nova visita para produtor com visita pendente → troca direto para "completar"
    const formVisita = document.getElementById('formVisita');
    const criandoNova = formVisita && Number(formVisita.querySelector('[name=id]').value) === 0;
    if (criandoNova && dados.visita_pendente) {
      App.alerta(`Este produtor tem uma visita com cadastro incompleto (${dados.visita_pendente.completude}%) — abrindo para completar.`, 'info');
      Visitas.editar(dados.visita_pendente.id);
      return;
    }
    Visitas.apoio = dados;
    const selProp = document.getElementById('visitaPropriedade');
    selProp.innerHTML = '<option value="">—</option>' +
      dados.propriedades.map(p => `<option value="${Number(p.id)}">${App.escapeHtml(p.nome)}</option>`).join('');
    if (dados.propriedades.length === 1) selProp.value = dados.propriedades[0].id;
    Visitas.filtrarTalhoes();
    Visitas.renderPainelComercial(dados.painel);
  },

  /** Monta o "apoio" (propriedades/talhões) a partir do snapshot offline. */
  async apoioOffline(clienteId) {
    const snap = typeof Offline !== 'undefined' ? await Offline.lerSnapshot() : null;
    const a = snap && snap.apoio ? snap.apoio[clienteId] : null;
    if (!a) return null;
    const prod = (snap.produtores || []).find(pr => Number(pr.id) === Number(clienteId));
    // Visita incompleta salva offline ainda na FILA também conta como pendente
    // (o snapshot só reflete o servidor; sem isso, a 2ª visita seria recusada no sync)
    let pendenteNaFila = false;
    try {
      pendenteNaFila = (await Offline.listar()).some(i => i.rota === 'visitas/salvar'
        && Number(i.campos && i.campos.cliente_id) === Number(clienteId)
        && Number((i.campos && i.campos.id) || 0) === 0);
    } catch (e) { /* fila indisponível: segue só com o snapshot */ }
    return {
      propriedades: a.propriedades || [],
      talhoes: a.talhoes || [],
      painel: null, // dados comerciais não ficam no snapshot (indisponíveis offline)
      plantios: snap.plantios || {},   // linha do tempo/checklist funcionam offline
      fenologia: snap.fenologia || {},
      _pendente: !!(prod && prod.ultima_completude !== null && !prod.ultima_finalizada) || pendenteNaFila,
      _atualizado: snap.atualizado_em ? new Date(snap.atualizado_em).toLocaleString('pt-BR') : '',
    };
  },

  filtrarTalhoes() {
    if (!Visitas.apoio) return;
    const propId = Number(document.getElementById('visitaPropriedade').value);
    const talhoes = Visitas.apoio.talhoes.filter(t => !propId || Number(t.propriedade_id) === propId);
    document.getElementById('visitaTalhao').innerHTML = '<option value="">—</option>' +
      talhoes.map(t => `<option value="${Number(t.id)}" data-cultura="${Number(t.cultura_id) || ''}">${App.escapeHtml(t.nome)}${t.cultura ? ' (' + App.escapeHtml(t.cultura) + ')' : ''}</option>`).join('');
    Visitas.renderFenologia(); // talhão mudou/limpou: refaz (ou limpa) a linha do tempo
  },

  aoEscolherTalhao() {
    const opt = document.querySelector('#visitaTalhao option:checked');
    const culturaId = opt ? opt.dataset.cultura : '';
    if (culturaId) {
      document.getElementById('visitaCultura').value = culturaId;
      Visitas.carregarModelos();
    }
    Visitas.renderFenologia();
  },

  /* ---------- Linha do tempo da cultura + checklist da lavoura (Fase 6E) ---------- */

  /** Estágio estimado pela idade da lavoura (espelho do FenologiaService). */
  _estagioPorDap(estagios, dap) {
    // Fora de qualquer janela (lacuna criada na edição, ou além do ciclo):
    // vale a última fase já iniciada — a timeline nunca some
    let melhor = null;
    for (const e of estagios) {
      if (dap >= Number(e.dias_inicio) && dap <= Number(e.dias_fim)) return e;
      if (dap > Number(e.dias_fim) && (!melhor || Number(e.dias_fim) > Number(melhor.dias_fim))) melhor = e;
    }
    return melhor;
  },

  /** Coleta o checklist já marcado no DOM (preserva as marcações ao re-renderizar). */
  _checklistMarcado() {
    const itens = [];
    document.querySelectorAll('#visitaChecklist input[type=radio]:checked').forEach(r => {
      const id = (r.name.match(/\[(\d+)\]/) || [])[1];
      if (!id) return;
      const obs = document.querySelector(`#visitaChecklist [name="checklist_obs[${id}]"]`);
      itens.push({ manejo_id: Number(id), situacao: r.value, observacao: obs ? obs.value : '' });
    });
    return itens;
  },

  /** Desenha a linha do tempo do plantio do talhão e o checklist da fase. */
  renderFenologia(checklistSalvo) {
    const alvo = document.getElementById('visitaFenologia');
    const alvoChk = document.getElementById('visitaChecklist');
    if (!alvo) return;
    const salvo = checklistSalvo || Visitas._checklistMarcado();
    alvo.innerHTML = ''; if (alvoChk) alvoChk.innerHTML = '';

    const talhaoId = Number(document.getElementById('visitaTalhao')?.value);
    if (!talhaoId || !Visitas.apoio) return;
    const plantio = Visitas.apoio.plantios ? Visitas.apoio.plantios[talhaoId] : null;
    // Data LOCAL (toISOString é UTC: depois das 21h no Brasil viraria "amanhã")
    const hojeLocal = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);

    if (!plantio) {
      // Sem plantio ativo: oferece o registro ali mesmo (ancora a linha do tempo)
      alvo.innerHTML = `
        <div class="card border-success-subtle">
          <div class="card-body py-2 d-flex flex-wrap align-items-end gap-2">
            <div><i class="bi bi-calendar-plus text-success me-1"></i><strong>Talhão sem plantio registrado.</strong>
              <div class="small text-muted">Informe a data de plantio para acompanhar a fase da lavoura e o checklist.</div></div>
            <div><label class="form-label mb-0 small">Data do plantio</label>
              <input type="date" id="plantioData" class="form-control form-control-sm" max="${hojeLocal}"></div>
            <div><label class="form-label mb-0 small">Cultivar (opcional)</label>
              <input id="plantioCultivar" class="form-control form-control-sm" placeholder="Ex.: 58I60 IPRO"></div>
            <button type="button" class="btn btn-success btn-sm" onclick="Visitas.registrarPlantio(${talhaoId})">
              <i class="bi bi-check-lg me-1"></i>Registrar plantio</button>
          </div>
        </div>`;
      return;
    }

    const estagios = (Visitas.apoio.fenologia && Visitas.apoio.fenologia[plantio.cultura_id]) || [];
    if (!estagios.length) return;
    const dataRef = document.querySelector('#formVisita [name=data_visita]')?.value || hojeLocal;
    const dap = Math.max(0, Math.floor((new Date(dataRef + 'T12:00') - new Date(plantio.data_plantio + 'T12:00')) / 864e5));
    const atual = Visitas._estagioPorDap(estagios, dap);
    const cicloTotal = Number(estagios[estagios.length - 1].dias_fim) + 1;
    const pct = Math.min(100, dap / cicloTotal * 100);

    // Fase EM USO: a estimada pelo DAP, salvo ajuste manual do técnico
    // ("A lavoura está nesta fase" no cartão do estágio)
    if (Visitas._fenoTalhao !== talhaoId) { Visitas._fenoManualId = null; Visitas._fenoTalhao = talhaoId; }
    if (!Visitas._fenoManualId && checklistSalvo && checklistSalvo.length) {
      // Recupera a fase SÓ pelo checklist vindo do servidor (visita reaberta) —
      // marcações ainda não salvas no DOM não "fixam" a fase sem o técnico pedir
      const daMarcacao = estagios.find(e => (e.manejos || []).some(m => Number(m.id) === Number(checklistSalvo[0].manejo_id)));
      if (daMarcacao) Visitas._fenoManualId = daMarcacao.id;
    }
    const emUso = estagios.find(e => e.id === Visitas._fenoManualId) || atual;
    Visitas._fenoEstagios = estagios;
    Visitas._fenoCulturaId = Number(plantio.cultura_id);

    // Macrofases (faixas Vegetativo/Reprodutivo/Feekes…): agrupa estágios consecutivos
    const zonas = [];
    estagios.forEach(e => {
      const g = e.grupo || '';
      if (!zonas.length || zonas[zonas.length - 1].nome !== g) zonas.push({ nome: g, estagios: [], dias: 0 });
      const z = zonas[zonas.length - 1];
      z.estagios.push(e);
      z.dias += Number(e.dias_fim) - Number(e.dias_inicio) + 1;
    });
    const trilha = zonas.map((z, i) => {
      const chips = z.estagios.map(e => {
        const span = Number(e.dias_fim) - Number(e.dias_inicio) + 1;
        let classe = '';
        if (emUso && e.id === emUso.id) {
          classe = 'atual';
        } else {
          if (dap > Number(e.dias_fim)) classe = 'passada';
          if (atual && e.id === atual.id) classe += ' estimada'; // estimativa ≠ fase ajustada
        }
        return `<div class="fen2-chip ${classe}" style="flex-grow:${span}" onclick="Visitas.abrirEstagio(${Number(e.id)})"
                  title="${App.escapeHtml(e.codigo)} — ${App.escapeHtml(e.nome)} (${e.dias_inicio}–${e.dias_fim} DAP) — toque para ver a ilustração">${App.escapeHtml(e.codigo)}</div>`;
      }).join('');
      return `<div class="fen2-zona zc-${i % 6}" style="flex-grow:${z.dias}">
                ${z.nome ? `<div class="fen2-zona-nome">${App.escapeHtml(z.nome)}</div>` : ''}
                <div class="fen2-zona-chips">${chips}</div>
              </div>`;
    }).join('');

    // Posição na fase em uso e prévia da próxima
    let infoFase = '', proxima = '', ajuste = '';
    if (emUso) {
      const idx = estagios.findIndex(e => e.id === emUso.id);
      const prox = estagios[idx + 1];
      if (atual && emUso.id === atual.id) {
        const diaFase = Math.min(dap, Number(emUso.dias_fim)) - Number(emUso.dias_inicio) + 1;
        const duracaoFase = Number(emUso.dias_fim) - Number(emUso.dias_inicio) + 1;
        infoFase = `Dia <strong>${diaFase}</strong> de ${duracaoFase} da fase (${emUso.dias_inicio}–${emUso.dias_fim} DAP)`;
        if (prox && dap <= Number(emUso.dias_fim)) {
          proxima = `Próxima: <strong>${App.escapeHtml(prox.codigo)} — ${App.escapeHtml(prox.nome)}</strong> em ~${Number(prox.dias_inicio) - dap} dia(s)`;
        } else if (!prox || dap > Number(emUso.dias_fim)) {
          proxima = '<strong>Fim de ciclo</strong> — planejar/registrar a colheita';
        }
      } else if (atual) {
        infoFase = `Janela de referência: ${emUso.dias_inicio}–${emUso.dias_fim} DAP`;
        ajuste = `<div class="fen2-ajustada mt-1"><i class="bi bi-person-check me-1"></i>Fase ajustada pelo técnico — estimativa pelo plantio: <strong>${App.escapeHtml(atual.codigo)}</strong></div>`;
        proxima = prox ? `Próxima: <strong>${App.escapeHtml(prox.codigo)} — ${App.escapeHtml(prox.nome)}</strong>` : '<strong>Última fase do ciclo</strong>';
      }
    }
    const manejos = emUso && emUso.manejos ? emUso.manejos : [];
    const listaManejos = manejos.length
      ? manejos.map(m => `
          <div class="fen2-manejo">
            <i class="bi bi-check2-square"></i>
            <div>
              <strong>${App.escapeHtml(m.titulo)}</strong>
              ${m.familia ? `<span class="badge text-bg-light border text-dark ms-1">${App.escapeHtml(m.familia)}</span>` : ''}
              ${m.orientacao ? `<div class="small text-muted">${App.escapeHtml(m.orientacao)}</div>` : ''}
            </div>
          </div>`).join('')
        + '<div class="small text-success mt-2"><i class="bi bi-arrow-right-circle me-1"></i>Avalie e marque estes itens no <strong>checklist da etapa 2 — Avaliação</strong>.</div>'
      : '<div class="small text-muted">Sem manejos de referência cadastrados para esta fase.</div>';

    alvo.innerHTML = `
      <div class="fen2">
        <div class="fen2-cabecalho">
          <div>
            <div class="fen2-cultura"><i class="bi bi-flower1 me-1"></i>${App.escapeHtml(plantio.cultura)}${plantio.cultivar ? ' · ' + App.escapeHtml(plantio.cultivar) : ''}</div>
            <div class="fen2-sub">Plantio em ${new Date(plantio.data_plantio + 'T12:00').toLocaleDateString('pt-BR')}</div>
          </div>
          <div class="fen2-dap"><strong>${dap}</strong><span>dias (DAP)</span></div>
        </div>
        <div class="fen2-track-wrap"><div class="fen2-track">${trilha}</div></div>
        <div class="fen2-linha"><div class="fen2-fill" style="width:${pct.toFixed(1)}%"></div><div class="fen2-marcador" style="left:${pct.toFixed(1)}%" title="Hoje — ${dap} DAP"></div></div>
        <div class="fen2-rotulos"><span>plantio</span><span>${cicloTotal} dias de ciclo</span></div>
        ${emUso ? `
        <div class="fen2-atual">
          <div class="fen2-atual-topo">
            <span class="fen2-selo" style="cursor:pointer" onclick="Visitas.abrirEstagio(${Number(emUso.id)})"
                  title="Ver a ilustração e as características desta fase">${App.escapeHtml(emUso.codigo)}</span>
            <div>
              <strong>${App.escapeHtml(emUso.nome)}</strong>
              ${emUso.descricao ? `<div class="small text-muted">${App.escapeHtml(emUso.descricao)}</div>` : ''}
              <div class="small text-muted mt-1">${infoFase}</div>
              ${ajuste}
            </div>
            <div class="fen2-proxima">${proxima}</div>
          </div>
          <div class="fen2-manejos">
            <div class="fen2-manejos-titulo"><i class="bi bi-clipboard2-check me-1"></i>Boas práticas e manejos desta fase</div>
            ${listaManejos}
          </div>
          <div class="small text-muted mt-2"><i class="bi bi-hand-index-thumb me-1"></i>Toque num estágio da linha do tempo para ver a ilustração e confirmar a fase real da lavoura.</div>
        </div>` : ''}
      </div>`;

    // Pré-preenche o estágio da visita com a fase em uso (sem sobrescrever o técnico)
    const campoEstagio = document.querySelector('#formVisita [name=estagio_cultura]');
    if (campoEstagio && !campoEstagio.value && emUso) campoEstagio.value = emUso.codigo + ' — ' + emUso.nome;

    Visitas.renderChecklist(emUso, salvo);
  },

  /** Abre o cartão do estágio (ilustração + características fisiológicas). */
  abrirEstagio(estagioId) {
    const idx = (Visitas._fenoEstagios || []).findIndex(e => Number(e.id) === Number(estagioId));
    if (idx < 0) return;
    Visitas._fenoIdx = idx;
    Visitas._renderEstagioModal();
    const el = document.getElementById('modalEstagio');
    if (!el.dataset.shimEmpilhado) {
      // Modais empilhados: fechar o de cima remove o modal-open do body e o
      // fundo volta a rolar por baixo do modal de visita — restaura o estado
      el.dataset.shimEmpilhado = '1';
      el.addEventListener('hidden.bs.modal', () => {
        if (document.querySelector('#modalVisita.show')) document.body.classList.add('modal-open');
      });
    }
    bootstrap.Modal.getOrCreateInstance(el).show();
  },

  /** Navega para a fase anterior/seguinte no cartão (comparação no campo). */
  navegarEstagio(delta) {
    const novo = Visitas._fenoIdx + delta;
    if (novo < 0 || novo >= Visitas._fenoEstagios.length) return;
    Visitas._fenoIdx = novo;
    Visitas._renderEstagioModal();
  },

  _renderEstagioModal() {
    const e = Visitas._fenoEstagios[Visitas._fenoIdx];
    if (!e) return;
    document.getElementById('estagioSelo').textContent = e.codigo;
    document.getElementById('estagioNome').textContent = e.nome;
    // Foto/arte personalizada do administrador quando houver; sem ela (ou
    // offline, sem cache) cai na ilustração padrão do sistema (SVG local)
    document.getElementById('estagioFigura').innerHTML = Number(e.tem_imagem)
      ? `<img src="index.php?r=arquivo/estagio&id=${Number(e.id)}" class="img-fluid rounded" alt="Foto da fase ${App.escapeHtml(e.codigo)}"
             onerror="this.closest('.feno-figura').innerHTML = Visitas._figuraPadrao();">`
      : FenologiaArte.svg(Visitas._fenoCulturaId, e);
    document.getElementById('estagioJanela').textContent =
      `${e.dias_inicio}–${e.dias_fim} dias após o plantio` + (e.grupo ? ` · macrofase ${e.grupo}` : '');
    document.getElementById('estagioCarac').innerHTML =
      '<div class="feno-carac-titulo"><i class="bi bi-search me-1"></i>Como identificar no campo</div>'
      + App.escapeHtml(e.caracteristicas || e.descricao || 'Sem descrição cadastrada para esta fase.');
    document.getElementById('estagioAnterior').disabled = Visitas._fenoIdx === 0;
    document.getElementById('estagioProximo').disabled = Visitas._fenoIdx === Visitas._fenoEstagios.length - 1;
  },

  /** Fallback da figura quando a foto personalizada não carrega (ex.: offline). */
  _figuraPadrao() {
    const e = Visitas._fenoEstagios[Visitas._fenoIdx];
    return e ? FenologiaArte.svg(Visitas._fenoCulturaId, e) : '';
  },

  /** "A lavoura está nesta fase": ajusta a visita e o checklist para a fase real observada. */
  usarEstagio() {
    const e = Visitas._fenoEstagios[Visitas._fenoIdx];
    if (!e) return;
    Visitas._fenoManualId = e.id;
    const campo = document.querySelector('#formVisita [name=estagio_cultura]');
    if (campo) campo.value = e.codigo + ' — ' + e.nome;
    bootstrap.Modal.getInstance('#modalEstagio')?.hide();
    Visitas.renderFenologia();
    App.alerta(`Fase da visita ajustada para ${e.codigo} — ${e.nome}. Checklist atualizado.`, 'info');
  },

  /** Checklist da fase atual na etapa de Avaliação (OK/Atenção/Crítico/N.A.). */
  renderChecklist(estagio, salvo) {
    const alvo = document.getElementById('visitaChecklist');
    if (!alvo) return;
    const manejos = estagio && estagio.manejos ? estagio.manejos : [];
    if (!manejos.length) { alvo.innerHTML = ''; return; }
    const mapa = {};
    (salvo || []).forEach(c => { mapa[Number(c.manejo_id)] = c; });
    const cores = { 'OK': 'success', 'Atenção': 'warning', 'Crítico': 'danger', 'N/A': 'secondary' };

    alvo.innerHTML = `
      <div class="card border-success-subtle mb-3">
        <div class="card-header py-2 bg-success-subtle">
          <i class="bi bi-list-check me-1"></i><strong>Checklist da lavoura — fase ${App.escapeHtml(estagio.codigo)} (${App.escapeHtml(estagio.nome)})</strong>
          <span class="text-muted small ms-1">opcional — marque o que avaliou</span>
        </div>
        <ul class="list-group list-group-flush">` +
      manejos.map(m => {
        const s = mapa[Number(m.id)];
        const botoes = ['OK', 'Atenção', 'Crítico', 'N/A'].map(op => {
          const idr = `chk_${m.id}_${op.replace(/\W/g, '')}`;
          return `<input type="radio" class="btn-check" name="checklist[${m.id}]" id="${idr}" value="${op}"
                    ${s && s.situacao === op ? 'checked' : ''} onchange="Visitas._obsChecklist(${m.id})">
                  <label class="btn btn-sm btn-outline-${cores[op]}" for="${idr}">${op}</label>`;
        }).join('');
        const obs = s && s.observacao ? App.escapeHtml(s.observacao) : '';
        return `<li class="list-group-item py-2">
          <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="me-auto">
              <div class="fw-semibold">${App.escapeHtml(m.titulo)}
                ${m.familia ? `<span class="badge text-bg-light border text-dark ms-1">${App.escapeHtml(m.familia)}</span>` : ''}</div>
              ${m.orientacao ? `<div class="small text-muted">${App.escapeHtml(m.orientacao)}</div>` : ''}
            </div>
            <div class="btn-group" role="group">${botoes}</div>
          </div>
          <div class="campo-voz mt-2 ${obs ? '' : 'd-none'}">
            <input name="checklist_obs[${m.id}]" class="form-control form-control-sm"
                   placeholder="Observação do item… (ou dite pelo microfone)" value="${obs}">
            <button type="button" class="btn-voz" title="Ditar por voz" aria-label="Ditar por voz"><i class="bi bi-mic-fill"></i></button>
          </div>
        </li>`;
      }).join('') + '</ul></div>';
  },

  /** Mostra o campo de observação do item quando ele é marcado. */
  _obsChecklist(manejoId) {
    document.querySelector(`#visitaChecklist [name="checklist_obs[${manejoId}]"]`)
      ?.closest('.campo-voz')?.classList.remove('d-none');
  },

  /** Registra o plantio do talhão direto do modal de visita. */
  async registrarPlantio(talhaoId) {
    const data = document.getElementById('plantioData')?.value;
    const culturaId = Number(document.getElementById('visitaCultura')?.value);
    if (!data) { App.alerta('Informe a data do plantio.', 'warning'); return; }
    if (!culturaId) { App.alerta('Selecione a cultura (etapa 1) antes de registrar o plantio.', 'warning'); return; }
    const fd = new FormData();
    fd.append('talhao_id', talhaoId);
    fd.append('cultura_id', culturaId);
    fd.append('data_plantio', data);
    fd.append('cultivar', document.getElementById('plantioCultivar')?.value || '');
    try {
      const resp = await App.json('index.php?r=plantios/salvar', { method: 'POST', body: fd });
      if (!Visitas.apoio.plantios || Array.isArray(Visitas.apoio.plantios)) Visitas.apoio.plantios = {};
      Visitas.apoio.plantios[talhaoId] = resp.plantio;
      App.alerta('Plantio registrado — linha do tempo ativada.');
      Visitas.renderFenologia();
    } catch (e) {
      App.alerta(navigator.onLine ? e.message : 'Sem conexão — registre o plantio quando estiver online.', 'warning');
    }
  },

  async carregarModelos() {
    const culturaId = document.getElementById('visitaCultura').value;
    const alvo = document.getElementById('visitaModelos');
    let modelos;
    try {
      ({ modelos } = await App.json(`index.php?r=visitas/modelos&cultura_id=${culturaId || 0}`));
    } catch (e) {
      // Offline: filtra os modelos do snapshot pela cultura (ou sem cultura definida)
      const snap = typeof Offline !== 'undefined' ? await Offline.lerSnapshot() : null;
      const cid = Number(culturaId) || 0;
      modelos = (snap && snap.modelos ? snap.modelos : [])
        .filter(m => m.cultura_id === null || Number(m.cultura_id) === cid);
    }
    alvo.innerHTML = modelos.length
      ? modelos.map(m => `<button type="button" class="btn btn-outline-success btn-sm"
          data-texto="${App.escapeHtml(m.texto_padrao)}" onclick="Visitas.usarModelo(this.dataset.texto)">
          <i class="bi bi-journal-plus me-1"></i>${App.escapeHtml(m.categoria)}: ${App.escapeHtml(m.titulo)}</button>`).join('')
      : '<span class="text-muted small">Nenhum modelo cadastrado para esta cultura.</span>';
  },

  usarModelo(texto) {
    const campo = document.querySelector('#formVisita [name=recomendacao]');
    campo.value = (campo.value ? campo.value.trimEnd() + '\n\n' : '') + texto;
    App.autoCrescer(campo);
    App.alerta('Modelo carregado — ajuste o que for necessário.', 'info');
  },

  renderPainelComercial(p) {
    const alvo = document.getElementById('visitaPainelComercial');
    if (!p) { alvo.innerHTML = '<span class="text-muted small">Dados comerciais indisponíveis offline.</span>'; return; }
    const corInad = { success: 'success', warning: 'warning', orange: 'warning', danger: 'danger' }[p.inadimplencia.cor] || 'secondary';
    const linhaCompra = c => `<tr><td>${App.escapeHtml(c.produto)}</td><td class="text-end">${Number(c.quantidade).toLocaleString('pt-BR')} ${App.escapeHtml(c.unidade || '')}</td><td class="text-end">${App.moeda(c.valor_total)}</td></tr>`;
    const linhaGap = g => `<li class="list-group-item d-flex justify-content-between align-items-center py-1">
        <span>${App.escapeHtml(g.produto)} <span class="text-muted small">(${App.escapeHtml(g.familia)})</span></span>
        <span class="badge text-bg-success-subtle text-success border border-success">${App.moeda(g.valor_anterior)}</span></li>`;

    alvo.innerHTML = `
      <div class="d-flex flex-wrap gap-2 mb-3">
        <span class="badge fs-6 text-bg-${corInad}">${p.inadimplencia.grau}</span>
        ${p.inadimplencia.inadimplente ? `<span class="small text-danger align-self-center">${App.moeda(p.inadimplencia.valor_vencido)} vencido há ${p.inadimplencia.dias_atraso} dias</span>` : ''}
        <span class="badge fs-6 text-bg-${{A:'success',B:'primary',C:'warning',D:'danger'}[p.credito.score]}">Score ${p.credito.score}</span>
        <span class="small align-self-center text-muted">Limite: ${App.moeda(p.credito.limite)} · Disponível: ${App.moeda(p.credito.disponivel)}</span>
      </div>
      ${p.credito.exige_aprovacao ? `<div class="alert alert-warning py-2 small"><i class="bi bi-exclamation-triangle me-1"></i><strong>${p.credito.motivo_aprovacao}.</strong> Vendas a prazo exigem aprovação do Gestor Comercial.</div>` : ''}
      ${p.queda.risco_churn ? `<div class="alert alert-danger py-2 small"><i class="bi bi-graph-down-arrow me-1"></i><strong>Risco de churn:</strong> compras ${Math.round(p.queda.queda * 100)}% abaixo da safra anterior.</div>` : ''}

      <h6 class="text-success">Oportunidades — gap de recompra</h6>
      ${p.gap_recompra.length ? `<ul class="list-group mb-3">${p.gap_recompra.map(linhaGap).join('')}</ul>` : '<p class="text-muted small">Sem gap de recompra.</p>'}

      <h6 class="text-success">Compras da safra ${p.safra ? App.escapeHtml(p.safra.nome) : ''}</h6>
      ${p.compras_safra_atual.length
        ? `<div class="table-responsive"><table class="table table-sm"><thead class="table-light"><tr><th>Produto</th><th class="text-end">Qtde</th><th class="text-end">Valor</th></tr></thead><tbody>${p.compras_safra_atual.map(linhaCompra).join('')}</tbody></table></div>`
        : '<p class="text-muted small">Nenhuma compra na safra atual.</p>'}

      <h6 class="text-success">Entrega futura</h6>
      ${(p.entregas_futuras || []).length
        ? `<div class="table-responsive"><table class="table table-sm"><thead class="table-light"><tr><th>Produto</th><th class="text-end">Contratado</th><th class="text-end">Pendente</th><th>Previsão</th></tr></thead><tbody>${p.entregas_futuras.map(ef => `<tr><td>${App.escapeHtml(ef.produto)}</td><td class="text-end">${Number(ef.quantidade_contratada).toLocaleString('pt-BR')} ${App.escapeHtml(ef.unidade || '')}</td><td class="text-end fw-semibold">${Number(ef.quantidade_pendente).toLocaleString('pt-BR')}</td><td>${ef.previsao_entrega ? ef.previsao_entrega.split('-').reverse().join('/') : '—'}</td></tr>`).join('')}</tbody></table></div>`
        : '<p class="text-muted small">Sem contratos de entrega futura.</p>'}

      <h6 class="text-success">Pedidos recentes</h6>
      ${(p.pedidos || []).length
        ? `<ul class="list-unstyled small mb-0">${p.pedidos.slice(0, 5).map(pd => `<li class="py-1 border-bottom d-flex justify-content-between"><span>#${Number(pd.id)} · ${App.escapeHtml(pd.tipo)}${pd.pacote ? ' (' + App.escapeHtml(pd.pacote) + ')' : ''}</span><span><span class="badge text-bg-${{'Rascunho':'secondary','Pendente de aprovação':'warning','Aprovado':'primary','Faturado':'success','Cancelado':'dark'}[pd.status] || 'secondary'}">${App.escapeHtml(pd.status)}</span> ${App.moeda(pd.valor_total)}</span></li>`).join('')}</ul>`
        : '<p class="text-muted small mb-0">Nenhum pedido registrado.</p>'}`;
  },

  // Campos de cada etapa — usados para marcar a aba como "preenchida" (independe da ordem).
  CAMPOS_ETAPA: {
    1: ['propriedade_id', 'talhao_id', 'cultura_id', 'objetivo'],
    2: ['estagio_cultura', 'desenvolvimento', 'pragas', 'doencas', 'plantas_daninhas', 'deficiencia_nutricional', 'condicoes_climaticas', 'observacoes'],
    3: ['recomendacao'],
    4: ['concorrente', 'concorrente_familia_id', 'concorrente_condicoes'],
  },

  /** True se a etapa tem algum campo preenchido (etapa 4 também conta fotos). */
  etapaPreenchida(n) {
    const form = document.getElementById('formVisita');
    if (!form) return false;
    if (n === 4) {
      const fotos = document.getElementById('visitaFotos');
      if (fotos && fotos.files && fotos.files.length) return true;
    }
    return (Visitas.CAMPOS_ETAPA[n] || []).some(name => {
      const el = form.querySelector(`[name="${name}"]`);
      return el && String(el.value).trim() !== '';
    });
  },

  /** Marca a aba ativa e, sem depender da ordem, as que já têm conteúdo. */
  atualizarPills() {
    document.querySelectorAll('#visitaEtapas .nav-link').forEach(btn => {
      const e = Number(btn.dataset.etapa);
      btn.classList.toggle('active', e === Visitas.etapa);
      btn.classList.toggle('preenchida', e !== Visitas.etapa && Visitas.etapaPreenchida(e));
    });
  },

  irParaEtapa(n) {
    Visitas.etapa = n;
    document.querySelectorAll('#modalVisita .etapa').forEach(div => {
      div.classList.toggle('d-none', Number(div.dataset.etapa) !== n);
    });
    Visitas.atualizarPills();
    document.getElementById('btnEtapaAnterior').disabled = n === 1;
    document.getElementById('btnEtapaProxima').classList.toggle('d-none', n === 5);
    // Salvar fica sempre disponível: dá para finalizar de qualquer etapa.
    document.getElementById('btnSalvarVisita').classList.remove('d-none');
  },

  /** Encerra a visita em edição mesmo com o cadastro incompleto (não poderá mais ser editada). */
  finalizarDefinitivo() {
    const pct = Visitas.completude();
    const faltando = Visitas.camposFaltando();
    const msg = pct >= 100
      ? 'Finalizar esta visita?'
      : `Finalizar DEFINITIVAMENTE com ${pct}% do cadastro preenchido` +
        (faltando.length ? ` (sem: ${faltando.join(', ')})` : '') +
        '?\n\nDepois disso a visita não poderá mais ser editada.';
    if (!confirm(msg)) return;
    const form = document.getElementById('formVisita');
    form.querySelector('[name=finalizar_definitivo]').value = '1';
    form.requestSubmit(document.getElementById('btnSalvarVisita'));
  },

  proximaEtapa() {
    if (Visitas.etapa < 5) Visitas.irParaEtapa(Visitas.etapa + 1);
  },

  etapaAnterior() {
    if (Visitas.etapa > 1) Visitas.irParaEtapa(Visitas.etapa - 1);
  },

  // Campos obrigatórios para finalizar o cadastro (espelha o servidor).
  CAMPOS_OBRIGATORIOS: [
    { name: 'cultura_id', label: 'Cultura' },
    { name: 'objetivo', label: 'Objetivo' },
    { name: 'desenvolvimento', label: 'Desenvolvimento' },
    { name: 'recomendacao', label: 'Recomendação' },
  ],

  /** Rótulos dos campos obrigatórios ainda vazios. */
  camposFaltando() {
    const form = document.getElementById('formVisita');
    if (!form) return [];
    return Visitas.CAMPOS_OBRIGATORIOS.filter(c => {
      const el = form.querySelector(`[name="${c.name}"]`);
      return !el || String(el.value).trim() === '';
    }).map(c => c.label);
  },

  /** Percentual (0-100) dos campos obrigatórios já preenchidos. */
  completude() {
    const total = Visitas.CAMPOS_OBRIGATORIOS.length;
    const faltam = Visitas.camposFaltando().length;
    return Math.round((total - faltam) / total * 100);
  },

  atualizarCompletude() {
    const pct = Visitas.completude();
    const falta = 100 - pct;
    const bar = document.getElementById('visitaCompletudeBar');
    const lbl = document.getElementById('visitaCompletudeLbl');
    const det = document.getElementById('visitaCompletudeFalta');
    if (bar) {
      bar.style.width = pct + '%';
      bar.className = 'progress-bar bg-' + (pct >= 100 ? 'success' : pct >= 50 ? 'warning' : 'danger');
    }
    if (lbl) {
      lbl.textContent = pct >= 100 ? 'Cadastro completo' : `Falta ${falta}%`;
      lbl.className = 'small text-nowrap fw-semibold ' + (pct >= 100 ? 'text-success' : 'text-muted');
    }
    if (det) {
      const faltando = Visitas.camposFaltando();
      det.textContent = faltando.length ? 'Falta preencher: ' + faltando.join(', ') : '';
    }
    Visitas.atualizarPills();
  },

  previewFotos() {
    const preview = document.getElementById('visitaFotosPreview');
    preview.innerHTML = '';
    for (const arquivo of document.getElementById('visitaFotos').files) {
      const img = document.createElement('img');
      img.className = 'foto-miniatura';
      img.src = URL.createObjectURL(arquivo);
      preview.appendChild(img);
    }
  },

  async salvar(ev) {
    ev.preventDefault();
    Voz.parar();
    const form = ev.target;
    if (!form.querySelector('[name=cliente_id]').value) {
      App.alerta('Selecione o cliente.', 'warning');
      Visitas.irParaEtapa(1);
      return false;
    }
    const definitivo = form.querySelector('[name=finalizar_definitivo]')?.value === '1';
    // Sem "Iniciar Visita" não há registro: a hora de início e o local são obrigatórios
    const hIni = form.querySelector('[name=hora_inicio]');
    if (hIni && !hIni.value) {
      App.alerta('Toque em "Iniciar Visita" (1ª etapa) ao chegar na propriedade — a hora de início e a localização são obrigatórias.', 'warning');
      return false;
    }
    const pct = Visitas.completude();
    if (pct < 100 && !definitivo) { // no fluxo definitivo a confirmação já foi feita
      const faltando = Visitas.camposFaltando();
      const msg = `Falta ${100 - pct}% do cadastro para finalizar` +
        (faltando.length ? ` (${faltando.join(', ')})` : '') + '.\n\n' +
        'Deseja salvar assim mesmo? A visita ficará marcada como NÃO FINALIZADA e poderá ser completada depois.';
      if (!confirm(msg)) return false;
    }
    try {
      const sel = document.getElementById('visitaCliente');
      const nome = sel && sel.selectedOptions[0] ? sel.selectedOptions[0].text : 'Visita';
      const editando = Number(form.querySelector('[name=id]').value) > 0;
      // Visita iniciada: o salvar carimba o fim (duração real no campo)
      const hFim = form.querySelector('[name=hora_fim]');
      if (hIni && hFim && hIni.value && !hFim.value) hFim.value = Visitas._horaAgora();
      const r = await App.enviarFormOffline(form, 'index.php?r=visitas/salvar', { modulo: 'Visitas', rotulo: (editando ? 'Completar visita — ' : 'Visita — ') + nome });
      bootstrap.Modal.getInstance('#modalVisita').hide();
      if (r.offline) {
        App.alerta('Sem conexão: visita guardada no aparelho. Será enviada quando a internet voltar.', 'info');
      } else {
        App.alerta(editando
          ? (definitivo && r.completude < 100 ? 'Visita finalizada definitivamente (' + r.completude + '%).'
            : (r.finalizada ? 'Cadastro da visita completado (100%).' : 'Visita atualizada — cadastro ainda incompleto.'))
          : 'Visita registrada com sucesso.');
        if (r.aviso) App.alerta(r.aviso, 'warning');
        setTimeout(() => location.href = 'index.php?r=visitas', r.aviso ? 2500 : 700);
      }
    } catch (e) { App.alerta(e.message, 'danger'); }
    return false;
  },

  async detalhe(id) {
    new bootstrap.Offcanvas('#painelVisita').show();
    const corpo = document.getElementById('painelVisitaCorpo');
    corpo.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-success"></div></div>';
    const resp = await fetch(`index.php?r=visitas/detalhe&id=${id}`, { headers: { 'X-Requested-With': 'fetch-html' } });
    corpo.innerHTML = await resp.text();
  },
};

/* ============================== FUNIL ============================== */

const Funil = {
  async salvar(ev) {
    ev.preventDefault();
    try {
      const r = await App.enviarForm(ev.target, 'index.php?r=funil/salvar');
      if (r.pendente_aprovacao) {
        App.alerta('Oportunidade criada como PENDENTE DE APROVAÇÃO (crédito).', 'warning');
      } else {
        App.alerta('Oportunidade criada.');
      }
      setTimeout(() => location.reload(), 800);
    } catch (e) { App.alerta(e.message, 'danger'); }
    return false;
  },

  mover(id, estagioAtual) {
    const form = document.querySelector('#modalMover form');
    form.reset();
    form.querySelector('[name=id]').value = id;
    form.querySelector('[name=estagio]').value = estagioAtual;
    Funil.aoTrocarEstagio(estagioAtual);
    new bootstrap.Modal('#modalMover').show();
  },

  aoTrocarEstagio(estagio) {
    document.getElementById('camposPerda').classList.toggle('d-none', estagio !== 'Perdida');
  },

  async confirmarMover(ev) {
    ev.preventDefault();
    try {
      await App.enviarForm(ev.target, 'index.php?r=funil/mover');
      location.reload();
    } catch (e) { App.alerta(e.message, 'danger'); }
    return false;
  },

  async aprovar(id) {
    try {
      const fd = new FormData();
      fd.append('id', id);
      await App.json('index.php?r=funil/aprovar', { method: 'POST', body: fd });
      App.alerta('Pendência de crédito aprovada.');
      setTimeout(() => location.reload(), 600);
    } catch (e) { App.alerta(e.message, 'danger'); }
  },

  proposta(oportunidadeId, valor) {
    const form = document.querySelector('#modalProposta form');
    form.reset();
    form.querySelector('[name=oportunidade_id]').value = oportunidadeId;
    if (valor) form.querySelector('[name=valor_total]').value = valor;
    new bootstrap.Modal('#modalProposta').show();
  },

  async salvarProposta(ev) {
    ev.preventDefault();
    try {
      await App.enviarForm(ev.target, 'index.php?r=funil/salvar-proposta');
      App.alerta('Proposta registrada.');
      setTimeout(() => location.reload(), 600);
    } catch (e) { App.alerta(e.message, 'danger'); }
    return false;
  },
};

/* ============================== POTENCIAL ============================== */

const Potencial = {
  grafico: null,
  dimensao: 'cliente',

  iniciar() {
    document.querySelectorAll('#seletorDimensao button').forEach(btn => {
      btn.addEventListener('click', () => {
        document.querySelectorAll('#seletorDimensao button').forEach(b => b.className = 'btn btn-outline-success');
        btn.className = 'btn btn-success';
        Potencial.dimensao = btn.dataset.dimensao;
        Potencial.carregar();
      });
    });
    document.getElementById('filtroFamilia').addEventListener('change', () => Potencial.carregar());
    document.getElementById('filtroOrdem').addEventListener('change', () => Potencial.carregar());
    Potencial.carregar();
  },

  _dados: [],
  _ordCol: null,
  _ordDir: 'desc',

  async carregar() {
    const familia = document.getElementById('filtroFamilia').value;
    const ordem = document.getElementById('filtroOrdem').value;
    try {
      const { ranking } = await App.json(
        `index.php?r=relatorios/potencial-dados&dimensao=${Potencial.dimensao}&ordem=${ordem}&familia_id=${familia}`
      );
      Potencial._dados = ranking;
      Potencial._ordCol = null; // volta a respeitar a ordem do servidor
      Potencial.aplicar();
    } catch (e) { App.alerta(e.message, 'danger'); }
  },

  /** Aplica busca textual + ordenação de coluna (client-side) sobre os dados carregados. */
  aplicar() {
    const termo = (document.getElementById('filtroBusca').value || '').toLowerCase();
    let lista = Potencial._dados.filter(r => String(r.dimensao).toLowerCase().includes(termo));
    if (Potencial._ordCol) {
      const col = Potencial._ordCol, dir = Potencial._ordDir === 'asc' ? 1 : -1;
      lista = lista.slice().sort((a, b) => {
        const va = col === 'dimensao' ? String(a.dimensao).toLowerCase() : Number(a[col]);
        const vb = col === 'dimensao' ? String(b.dimensao).toLowerCase() : Number(b[col]);
        return va < vb ? -1 * dir : (va > vb ? dir : 0);
      });
    }
    Potencial.renderTabela(lista);
    Potencial.renderGrafico(lista);
  },

  ordenarPor(col) {
    if (Potencial._ordCol === col) {
      Potencial._ordDir = Potencial._ordDir === 'asc' ? 'desc' : 'asc';
    } else {
      Potencial._ordCol = col;
      Potencial._ordDir = col === 'dimensao' ? 'asc' : 'desc';
    }
    document.querySelectorAll('#tabelaRankingHead th[data-col]').forEach(th => {
      const ic = th.querySelector('.bi');
      if (ic) ic.className = 'bi ' + (th.dataset.col === col ? (Potencial._ordDir === 'asc' ? 'bi-sort-up' : 'bi-sort-down') : 'bi-arrow-down-up') + ' ms-1 small';
    });
    Potencial.aplicar();
  },

  renderTabela(ranking) {
    const corpo = document.getElementById('tabelaRanking');
    if (!ranking.length) {
      corpo.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">Sem dados para o filtro.</td></tr>';
      return;
    }
    corpo.innerHTML = ranking.map((r, i) => `
      <tr>
        <td>${i + 1}º</td>
        <td>${App.escapeHtml(r.dimensao)}</td>
        <td class="text-end small">${App.moeda(r.potencial)}</td>
        <td class="text-end small">${App.moeda(r.realizado)}</td>
        <td class="text-end fw-bold ${Number(r.percentual) >= 70 ? 'text-success' : (Number(r.percentual) >= 40 ? 'text-warning' : 'text-danger')}">${r.percentual}%</td>
      </tr>`).join('');
  },

  renderGrafico(ranking) {
    const canvas = document.getElementById('graficoRanking');
    if (Potencial.grafico) Potencial.grafico.destroy();
    Potencial.grafico = new Chart(canvas, {
      type: 'bar',
      data: {
        labels: ranking.map(r => r.dimensao),
        datasets: [{
          label: '% do potencial utilizado',
          data: ranking.map(r => Number(r.percentual)),
          backgroundColor: ranking.map(r => Number(r.percentual) >= 70 ? '#2e7d32' : (Number(r.percentual) >= 40 ? '#f9a825' : '#c62828')),
          borderRadius: 6,
        }],
      },
      options: {
        indexAxis: 'y',
        plugins: { legend: { display: false } },
        scales: { x: { ticks: { callback: v => v + '%' } } },
      },
    });
    Potencial.grafico.$rotulo = { formatter: v => v + '%', color: '#1b5e20' };
    Potencial.grafico.update();
  },
};

/* ============================== USUÁRIOS ============================== */

const Usuarios = {
  novo() {
    const form = document.getElementById('formUsuario');
    form.reset();
    form.querySelector('[name=id]').value = 0;
    document.getElementById('modalUsuarioTitulo').textContent = 'Novo Usuário';
    new bootstrap.Modal('#modalUsuario').show();
  },

  editar(u) {
    const form = document.getElementById('formUsuario');
    form.reset();
    form.querySelector('[name=id]').value = u.id;
    form.querySelector('[name=nome]').value = u.nome;
    form.querySelector('[name=email]').value = u.email;
    form.querySelector('[name=perfil]').value = u.perfil;
    form.querySelector('[name=telefone]').value = u.telefone || '';
    form.querySelector('[name=categoria_reembolso_id]').value = u.categoria_reembolso_id || 0;
    form.querySelector('[name=cod_vendedor]').value = u.cod_vendedor || '';
    form.querySelector('[name=ativo]').value = u.ativo;
    document.getElementById('modalUsuarioTitulo').textContent = 'Editar Usuário';
    new bootstrap.Modal('#modalUsuario').show();
  },

  async salvar(ev) {
    ev.preventDefault();
    try {
      await App.enviarForm(ev.target, 'index.php?r=usuarios/salvar');
      App.alerta('Usuário salvo.');
      setTimeout(() => location.reload(), 600);
    } catch (e) { App.alerta(e.message, 'danger'); }
    return false;
  },
};

/* ============================== INICIALIZAÇÃO ============================== */

// Plugin global de rótulos de dados: mostra o valor em barras e pontos de todos os gráficos.
// A configuração fica em chart.$rotulo (propriedade da instância) — NÃO em options,
// para o Chart.js não tratar o formatter como "scriptable option" e invocá-lo com o contexto interno.
if (typeof Chart !== 'undefined') {
  Chart.register({
    id: 'rotuloDados',
    afterDatasetsDraw(chart) {
      const cfg = chart.$rotulo;
      if (cfg === false) return;
      const fmt = (cfg && cfg.formatter) || (v => (typeof v === 'number' ? v.toLocaleString('pt-BR') : String(v)));
      const ctx = chart.ctx;
      ctx.save();
      ctx.font = '600 11px sans-serif';
      ctx.fillStyle = (cfg && cfg.color) || '#333';
      chart.data.datasets.forEach((ds, di) => {
        const meta = chart.getDatasetMeta(di);
        if (meta.hidden) return;
        meta.data.forEach((el, i) => {
          const raw = ds.data[i];
          if (raw === null || raw === undefined || raw === 0) return;
          const txt = fmt(raw, ds, i);
          const horizontal = chart.options.indexAxis === 'y';
          ctx.textAlign = horizontal ? 'left' : 'center';
          ctx.textBaseline = horizontal ? 'middle' : 'bottom';
          const x = horizontal ? el.x + 6 : el.x;
          const y = horizontal ? el.y : el.y - 4;
          ctx.fillText(txt, x, y);
        });
      });
      ctx.restore();
    },
  });
}

document.addEventListener('DOMContentLoaded', () => {
  Voz.iniciar();
  if (document.getElementById('btnSino')) Notificacoes.atualizarContador();

  // Campos de texto autoajustáveis: dimensiona ao carregar a página e sempre
  // que um modal abre (escondido, o textarea tem scrollHeight 0)
  document.querySelectorAll('textarea.auto-crescer').forEach(t => App.autoCrescer(t));
  document.addEventListener('shown.bs.modal', ev => {
    ev.target.querySelectorAll('textarea.auto-crescer').forEach(t => App.autoCrescer(t));
    ev.target.querySelectorAll('select.select-busca').forEach(s => App.selectBuscaSync(s));
  });

  // Selects longos (municípios) viram campo de busca digitável; form.reset()
  // limpa o select antes do campo, então sincroniza no tick seguinte
  document.querySelectorAll('select.select-busca').forEach(s => App.selectBusca(s));
  document.addEventListener('reset', ev => {
    if (ev.target && ev.target.querySelectorAll) {
      setTimeout(() => ev.target.querySelectorAll('select.select-busca').forEach(s => App.selectBuscaSync(s)), 0);
    }
  }, true);

  // Recolher/expandir menu lateral (desktop) com preferência lembrada
  if (localStorage.getItem('menuRecolhido') === '1') {
    document.body.classList.add('menu-recolhido');
  }
  const btnRecolher = document.getElementById('btnRecolherMenu');
  if (btnRecolher) {
    btnRecolher.addEventListener('click', () => {
      const recolhido = document.body.classList.toggle('menu-recolhido');
      localStorage.setItem('menuRecolhido', recolhido ? '1' : '0');
    });
  }

  // Tooltips dos itens do menu — só aparecem com o menu recolhido (apenas ícones)
  if (window.bootstrap) {
    document.querySelectorAll('#sidebar [title]').forEach(el => {
      new bootstrap.Tooltip(el, { placement: 'right', trigger: 'hover', container: 'body' });
      el.addEventListener('show.bs.tooltip', ev => {
        if (!document.body.classList.contains('menu-recolhido') || window.innerWidth < 992) {
          ev.preventDefault();
        }
      });
    });
  }

  // Menu lateral no celular
  const btnMenu = document.getElementById('btnMenu');
  if (btnMenu) {
    btnMenu.addEventListener('click', () => document.getElementById('sidebar').classList.toggle('aberta'));
    document.addEventListener('click', ev => {
      if (!ev.target.closest('#sidebar') && !ev.target.closest('#btnMenu')) {
        document.getElementById('sidebar').classList.remove('aberta');
      }
    });
  }

  // Indicador online/offline
  const atualizarIndicador = () => {
    const badge = document.getElementById('indicadorOffline');
    if (badge) badge.classList.toggle('d-none', navigator.onLine);
  };
  window.addEventListener('online', () => { atualizarIndicador(); if (typeof Offline !== 'undefined') Offline.sincronizar(); });
  window.addEventListener('offline', () => { atualizarIndicador(); if (typeof OfflineView !== 'undefined') OfflineView.aplicar(); });
  // Se o usuário arrastar a alça de um campo auto-crescível, memoriza a altura escolhida
  document.addEventListener('mouseup', ev => {
    const el = ev.target;
    if (el && el.classList && el.classList.contains('auto-crescer') && el.offsetHeight > el.scrollHeight + 4) {
      el.dataset.alturaManual = el.offsetHeight;
    }
  });
  atualizarIndicador();
  if (typeof Offline !== 'undefined') { Pendencias.atualizar(); OfflineView.aplicar(); }
  Notificacoes._atualizarBotaoPush();
});

/* ===================== PENDÊNCIAS DE ENVIO (offline) ===================== */

const Pendencias = {
  async atualizar() {
    const itens = await Offline.listar();
    const btn = document.getElementById('btnPendencias');
    const cont = document.getElementById('pendenciasContador');
    if (!btn || !cont) return;
    cont.textContent = itens.length;
    btn.classList.toggle('d-none', itens.length === 0);
    const corpo = document.getElementById('pendenciasCorpo');
    if (corpo && corpo.offsetParent !== null) Pendencias.render(itens);
  },

  async abrir() {
    Pendencias.render(await Offline.listar());
    new bootstrap.Offcanvas('#painelPendencias').show();
  },

  render(itens) {
    const corpo = document.getElementById('pendenciasCorpo');
    if (!corpo) return;
    if (!itens.length) {
      corpo.innerHTML = '<p class="text-muted small p-3 mb-0">Nada pendente — tudo sincronizado.</p>';
      return;
    }
    corpo.innerHTML = itens.map(r => `
      <div class="list-group-item">
        <div class="d-flex justify-content-between align-items-start gap-2">
          <div class="flex-grow-1">
            <div class="fw-semibold small">${App.escapeHtml(r.rotulo || r.rota)}</div>
            <div class="text-muted" style="font-size:.78rem">${App.escapeHtml(r.modulo || '')} · ${App.escapeHtml(new Date(r.criado_em).toLocaleString('pt-BR'))}</div>
            ${r.erro
              ? `<div class="text-danger small mt-1"><i class="bi bi-exclamation-triangle me-1"></i>${App.escapeHtml(r.erro)}</div>`
              : '<div class="text-muted small mt-1"><i class="bi bi-clock-history me-1"></i>aguardando envio</div>'}
          </div>
          <div class="btn-group-vertical btn-group-sm flex-shrink-0">
            ${r.erro ? `<button class="btn btn-outline-success" onclick="Pendencias.tentar(${Number(r.id)})" title="Tentar novamente"><i class="bi bi-arrow-repeat"></i></button>` : ''}
            <button class="btn btn-outline-danger" onclick="Pendencias.descartar(${Number(r.id)})" title="Descartar"><i class="bi bi-trash"></i></button>
          </div>
        </div>
      </div>`).join('');
  },

  async tentar(id) {
    if (!navigator.onLine) { App.alerta('Sem conexão — conecte-se para reenviar.', 'warning'); return; }
    await Offline.tentarNovamente(id);
  },

  async descartar(id) {
    if (!confirm('Descartar este lançamento pendente? Ele não será enviado.')) return;
    await Offline.remover(id);
    App.alerta('Lançamento pendente descartado.', 'info');
  },
};

/* ============ LEITURA OFFLINE das telas a partir do snapshot (O2) ============ */

const OfflineView = {
  /**
   * Identifica o imóvel do CAR na posição usando a base do município no snapshot.
   * Igual ao servidor: polígono que CONTÉM o ponto e, se nenhum contém e
   * tolMetros > 0, o imóvel mais PRÓXIMO dentro do raio (marcado 'aproximado').
   */
  async carNoPonto(lat, lng, tolMetros = 0) {
    if (typeof Offline === 'undefined') return null;
    const base = await Offline.lerCarMunicipio();
    if (!base || !base.imoveis) return null;
    const dentro = (p, pol) => {
      let d = false;
      for (let i = 0, j = pol.length - 1; i < pol.length; j = i++) {
        const yi = pol[i][0], xi = pol[i][1], yj = pol[j][0], xj = pol[j][1];
        if (((yi > p[0]) !== (yj > p[0])) && p[1] < (xj - xi) * (p[0] - yi) / ((yj - yi) || 1e-12) + xi) d = !d;
      }
      return d;
    };
    const g = tolMetros > 0 ? tolMetros / 111000 : 0;
    const aneisDe = (c) => (typeof Croqui !== 'undefined' ? Croqui._aneis(c) : [c]);
    let melhor = null, melhorDist = Infinity;
    for (const im of base.imoveis) {
      const [minLat, minLng, maxLat, maxLng] = im.bbox;
      if (lat < minLat - g || lat > maxLat + g || lng < minLng - g || lng > maxLng + g) continue;
      const aneis = aneisDe(im.contorno);
      if (aneis.some(a => Array.isArray(a) && a.length >= 3 && dentro([lat, lng], a))) {
        return { cod: im.cod, contorno: im.contorno, aproximado: false, dist_m: 0 };
      }
      if (tolMetros > 0) {
        let d = Infinity;
        for (const a of aneis) { const dd = OfflineView._distPoligono(lat, lng, a); if (dd < d) d = dd; }
        if (d < melhorDist) { melhorDist = d; melhor = im; }
      }
    }
    if (tolMetros > 0 && melhor && melhorDist <= tolMetros) {
      return { cod: melhor.cod, contorno: melhor.contorno, aproximado: true, dist_m: Math.round(melhorDist) };
    }
    return null;
  },

  /** Menor distância (m) do ponto ao polígono [[lat,lng],...] (0 se dentro). */
  _distPoligono(lat, lng, pol) {
    const mLat = 110574, mLng = 111320 * Math.cos(lat * Math.PI / 180);
    const px = lng * mLng, py = lat * mLat;
    let min = Infinity;
    for (let i = 0, j = pol.length - 1; i < pol.length; j = i++) {
      const ax = pol[j][1] * mLng, ay = pol[j][0] * mLat;
      const bx = pol[i][1] * mLng, by = pol[i][0] * mLat;
      const dx = bx - ax, dy = by - ay, len2 = dx * dx + dy * dy;
      const t = len2 > 0 ? Math.max(0, Math.min(1, ((px - ax) * dx + (py - ay) * dy) / len2)) : 0;
      const d = Math.hypot(px - (ax + t * dx), py - (ay + t * dy));
      if (d < min) min = d;
    }
    return min;
  },

  async aplicar() {
    if (navigator.onLine || typeof Offline === 'undefined') return;
    const snap = await Offline.lerSnapshot();
    if (!snap || !snap.produtores) return;
    const quando = snap.atualizado_em ? new Date(snap.atualizado_em).toLocaleString('pt-BR') : '';
    if (document.getElementById('tabelaClientes')) OfflineView.clientes(snap, quando);
    if (document.getElementById('tabelaPriorizacao')) OfflineView.priorizacao(snap, quando);
    if (document.getElementById('listaSugestoes')) OfflineView.sugestoes(snap, quando);
  },

  banner(texto) {
    if (document.getElementById('bannerOffline')) return;
    const main = document.querySelector('main .p-3') || document.querySelector('main');
    if (!main) return;
    const div = document.createElement('div');
    div.id = 'bannerOffline';
    div.className = 'alert alert-warning d-flex align-items-center gap-2 py-2';
    div.innerHTML = '<i class="bi bi-wifi-off"></i><span class="small">' + App.escapeHtml(texto) + '</span>';
    main.insertBefore(div, main.firstChild);
  },

  wa(tel, texto) {
    if (!tel) return null;
    let n = String(tel).replace(/\D+/g, '');
    if (n.length < 10) return null;
    if (n.length <= 11) n = '55' + n;
    return 'https://wa.me/' + n + '?text=' + encodeURIComponent(texto);
  },

  _dias(d) { return Number(d) >= 120 ? '120+' : Number(d); },
  _seloCadastro(p) {
    if (p.ultima_completude === null || p.ultima_completude === undefined) {
      return '<span class="badge rounded-pill text-bg-light border text-muted">sem visita</span>';
    }
    return p.ultima_finalizada
      ? `<span class="badge rounded-pill text-bg-success">Cadastro ${Number(p.ultima_completude)}%</span>`
      : `<span class="badge rounded-pill text-bg-warning text-dark">Cadastro ${Number(p.ultima_completude)}%</span>`;
  },

  /** Selo do segmento (espelho do helper PHP selo_segmento; manual prevalece). */
  _seloSegmento(p, compacto) {
    const seg = (p.segmento_manual || p.segmento || '').trim();
    const cores = { A: 'success', B: 'primary', C: 'secondary', D: 'danger', P: 'warning' };
    const rotulos = { A: 'A — Parceiro', B: 'B — Crescimento', C: 'C — Ocasional', D: 'D — Em risco', P: 'Prospect' };
    if (!cores[seg]) return '';
    return `<span class="badge text-bg-${cores[seg]}">${compacto ? seg : rotulos[seg]}</span>`;
  },

  clientes(snap, quando) {
    const tb = document.getElementById('tabelaClientes');
    const lista = [...snap.produtores].sort((a, b) => String(a.nome).localeCompare(b.nome, 'pt-BR'));
    tb.innerHTML = lista.map(c => `
      <tr>
        <td>
          <div class="fw-semibold">${App.escapeHtml(c.nome)}${Number(c.prospecto) ? ' <span class="badge text-bg-warning ms-1">Prospecto</span>' : ''}
            <span class="d-md-none ms-1">${OfflineView._seloSegmento(c, true)}</span></div>
          <div class="small text-muted d-md-none">${App.escapeHtml(c.municipio || '')}</div>
        </td>
        <td class="d-none d-md-table-cell">${OfflineView._seloSegmento(c) || '<span class="text-muted">—</span>'}</td>
        <td class="d-none d-md-table-cell">${App.escapeHtml(c.municipio || '—')}</td>
        <td class="d-none d-md-table-cell">${c.situacao ? `<span class="badge text-bg-${c.situacao === 'Associado' ? 'success' : 'secondary'}">${App.escapeHtml(c.situacao)}</span>` : '—'}</td>
        <td class="d-none d-lg-table-cell">${App.escapeHtml(c.nivel_tecnologico || '')}</td>
        <td class="d-none d-lg-table-cell">${c.ultima_visita ? App.escapeHtml(String(c.ultima_visita).split('-').reverse().join('/')) : '—'}</td>
        <td class="text-end text-nowrap"><button class="btn btn-sm btn-success" onclick="Visitas.nova(${Number(c.id)})" title="Nova visita"><i class="bi bi-clipboard2-plus"></i></button></td>
      </tr>`).join('');
    OfflineView.banner('Modo offline — carteira salva de ' + quando + '. A ficha completa e a busca do servidor exigem conexão.');
  },

  priorizacao(snap, quando) {
    const tb = document.getElementById('tabelaPriorizacao');
    tb.innerHTML = snap.produtores.map((p, i) => {
      const cor = p.score >= 60 ? 'danger' : (p.score >= 40 ? 'warning' : 'success');
      return `<tr class="${i < 3 ? 'table-warning-subtle' : ''}">
        <td><span class="badge rounded-pill text-bg-${i < 3 ? 'danger' : 'success'} fs-6">${i + 1}º</span></td>
        <td>
          <div class="fw-semibold">${App.escapeHtml(p.nome)} <span class="ms-1">${OfflineView._seloSegmento(p, true)}</span>${p.risco_churn ? ` <span class="badge text-bg-danger ms-1">Churn −${Number(p.queda_percentual)}%</span>` : ''}</div>
          <div class="small text-muted">${App.escapeHtml(p.municipio || '')}</div>
          <div class="mt-1">${OfflineView._seloCadastro(p)}</div>
        </td>
        <td class="d-none d-md-table-cell">${OfflineView._dias(p.dias_sem_visita)}</td>
        <td class="d-none d-md-table-cell">${App.escapeHtml(p.nivel_tecnologico || '')}</td>
        <td class="d-none d-lg-table-cell">${App.moeda(p.volume_compra_anual)}</td>
        <td class="d-none d-lg-table-cell">${App.moeda(p.potencial_venda)}</td>
        <td><div class="progress" style="width:70px;height:8px"><div class="progress-bar bg-${cor}" style="width:${Number(p.score)}%"></div></div><span class="small text-muted">${Number(p.score)}</span></td>
        <td class="text-end"><button class="btn btn-sm btn-success" onclick="Visitas.nova(${Number(p.id)})" title="Visitar"><i class="bi bi-clipboard2-plus"></i></button></td>
      </tr>`;
    }).join('');
    OfflineView.banner('Modo offline — prioridades da carteira salva de ' + quando + '.');
  },

  sugestoes(snap, quando) {
    const alvo = document.getElementById('listaSugestoes');
    alvo.innerHTML = snap.produtores.slice(0, 12).map(s => {
      const wa = OfflineView.wa(s.telefone, 'Olá! Podemos agendar uma visita técnica?');
      return `<div class="list-group-item d-flex align-items-center gap-2">
        <span class="badge rounded-pill text-bg-success">score ${Number(s.score)}</span>
        <div class="flex-grow-1">
          <div class="fw-semibold">${App.escapeHtml(s.nome)}${s.risco_churn ? ` <span class="badge text-bg-danger ms-1">Churn −${Number(s.queda_percentual)}%</span>` : ''}</div>
          <div class="small text-muted">${Number(s.dias_sem_visita) >= 120 ? '+120' : Number(s.dias_sem_visita)} dias sem visita · ${App.escapeHtml(s.municipio || '—')} · Nível ${App.escapeHtml(s.nivel_tecnologico || '')}</div>
        </div>
        ${wa ? `<a class="btn btn-sm btn-outline-success" href="${App.escapeHtml(wa)}" target="_blank" title="WhatsApp"><i class="bi bi-whatsapp"></i></a>` : ''}
      </div>`;
    }).join('');
    OfflineView.banner('Modo offline — sugestões da carteira salva de ' + quando + '. Montar/otimizar o roteiro exige conexão.');
  },
};
