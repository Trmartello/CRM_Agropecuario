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

  /** Envia um formulário via AJAX (FormData). */
  async enviarForm(form, url) {
    return App.json(url, { method: 'POST', body: new FormData(form) });
  },

  /**
   * Envia um formulário/FormData com suporte offline: sem conexão (ou se a rede
   * cair no meio), guarda na fila local e devolve { ok:true, offline:true }.
   * Erros de negócio (validação do servidor) continuam sendo lançados.
   */
  async enviarFormOffline(origem, url, opc = {}) {
    const rota = String(url).replace(/^.*[?&]r=/, '').replace(/&.*$/, '');
    const fd = origem instanceof FormData ? origem : new FormData(origem);
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

  /** Escapa texto para inserção segura via innerHTML. */
  escapeHtml(v) {
    return String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  },

  /** Aceita apenas links internos (index.php…); descarta esquemas perigosos como javascript:. */
  linkSeguro(v) {
    const s = String(v || '');
    return /^(index\.php|\?|#|\/|uploads\/)/.test(s) ? s : '#';
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
    } catch (e) { App.alerta(e.message, 'danger'); }
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
    form.querySelector('[name=id]').value = 0;
    form.querySelector('[name=cliente_id]').value = clienteId;
    new bootstrap.Modal('#modalPropriedade').show();
  },

  editarPropriedade(p) {
    const form = document.getElementById('formPropriedade');
    form.reset();
    form.querySelector('[name=id]').value = p.id;
    form.querySelector('[name=cliente_id]').value = p.cliente_id;
    form.querySelector('[name=nome]').value = p.nome;
    form.querySelector('[name=area_ha]').value = p.area_ha;
    form.querySelector('[name=municipio]').value = p.municipio || '';
    form.querySelector('[name=car_numero]').value = p.car_numero || '';
    new bootstrap.Modal('#modalPropriedade').show();
  },

  /** Importa a divisa oficial do CAR (shapefile .zip) e desenha no croqui. */
  importarCar(propId) {
    const inp = document.createElement('input');
    inp.type = 'file';
    inp.accept = '.zip,application/zip';
    inp.onchange = async () => {
      if (!inp.files || !inp.files.length) return;
      const fd = new FormData();
      fd.append('propriedade_id', propId);
      fd.append('arquivo', inp.files[0]);
      App.alerta('Lendo o shapefile do CAR…', 'info');
      try {
        const r = await App.json('index.php?r=clientes/importar-car', { method: 'POST', body: fd });
        App.alerta(`Divisa do CAR importada: ${r.pontos} pontos · ${Number(r.area_gps).toLocaleString('pt-BR', { maximumFractionDigits: 1 })} ha.`, 'success');
        if (r.talhoes_fora && r.talhoes_fora.length) {
          App.alerta('Atenção: talhão(ões) fora da divisa oficial do CAR: ' + r.talhoes_fora.join(', ') + '. Ajuste no croqui.', 'warning');
        }
        if (Clientes.fichaClienteId) setTimeout(() => Clientes.ficha(Clientes.fichaClienteId), 900);
      } catch (e) { App.alerta(e.message, 'danger'); }
    };
    inp.click();
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

  novoTalhao(propriedadeId) {
    const form = document.getElementById('formTalhao');
    form.reset();
    form.querySelector('[name=id]').value = 0;
    form.querySelector('[name=propriedade_id]').value = propriedadeId;
    new bootstrap.Modal('#modalTalhao').show();
  },

  editarTalhao(t) {
    const form = document.getElementById('formTalhao');
    form.reset();
    form.querySelector('[name=id]').value = t.id;
    form.querySelector('[name=propriedade_id]').value = t.propriedade_id;
    form.querySelector('[name=nome]').value = t.nome;
    form.querySelector('[name=area_ha]').value = t.area_ha;
    if (t.cultura_id) form.querySelector('[name=cultura_id]').value = t.cultura_id;
    new bootstrap.Modal('#modalTalhao').show();
  },

  async salvarTalhao(ev) {
    ev.preventDefault();
    try {
      await App.enviarForm(ev.target, 'index.php?r=clientes/salvar-talhao');
      bootstrap.Modal.getInstance('#modalTalhao').hide();
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
  abrir(talhaoId, culturaId, nomeTalhao) {
    const form = document.getElementById('formPlantio');
    if (!form) return;
    form.reset();
    form.querySelector('[name=talhao_id]').value = talhaoId;
    if (culturaId) form.querySelector('[name=cultura_id]').value = culturaId;
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
  prop: null,
  talhoes: [],
  tiles: null,
  atualId: 0,          // 0 = divisa da PROPRIEDADE; >0 = talhão
  pontos: [],
  vista: null,         // {z, cx, cy} em coordenadas de mundo Web Mercator (0..1); z pode ser fracionário (pinça)
  watchId: null,
  _dirty: false,
  _arrasto: null,
  _pan: null,
  _pinch: null,        // zoom de pinça (dois dedos)
  _ponteiros: new Map(),
  _eventosOk: false,

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

  async abrir(propId) {
    let dados;
    try {
      dados = await App.json('index.php?r=clientes/croqui-dados&propriedade_id=' + Number(propId));
    } catch (e) {
      App.alerta(navigator.onLine ? e.message : 'Abra o croqui com conexão ao menos uma vez — depois a marcação por GPS funciona sem sinal.', 'warning');
      return;
    }
    Croqui.prop = dados.propriedade;
    Croqui.talhoes = dados.talhoes;
    Croqui.tiles = dados.tiles && dados.tiles.url ? dados.tiles : null;
    Croqui._dirty = false;
    document.getElementById('croquiPropNome').textContent = dados.propriedade.nome;
    const sel = document.getElementById('croquiTalhao');
    sel.innerHTML = '<option value="0">🏠 Propriedade — área total</option>' + Croqui.talhoes.map(t =>
      `<option value="${Number(t.id)}">${App.escapeHtml(t.nome)}${t.cultura ? ' (' + App.escapeHtml(t.cultura) + ')' : ''}</option>`).join('');
    Croqui.atualId = 0; // começa pela divisa da propriedade (área total)
    Croqui.pontos = Croqui._contornoDe(0);
    document.getElementById('croquiUsarArea').checked = false;
    document.getElementById('croquiModoManual').checked = true;
    Croqui._prepararEventos();
    Croqui.vista = null; // recalcula o enquadramento ao abrir
    new bootstrap.Modal('#modalCroqui').show();
    setTimeout(() => { Croqui._enquadrar(); Croqui.render(); }, 250);
  },

  _contornoDe(id) {
    const fonte = Number(id) === 0 ? Croqui.prop : Croqui.talhoes.find(x => Number(x.id) === Number(id));
    try { return fonte && fonte.contorno ? JSON.parse(fonte.contorno) : []; } catch (e) { return []; }
  },

  _todosPontosBase() {
    const todos = [];
    const divisa = Croqui.atualId === 0 ? Croqui.pontos : Croqui._contornoDe(0);
    todos.push(...divisa);
    Croqui.talhoes.forEach(t => {
      todos.push(...(Number(t.id) === Croqui.atualId ? Croqui.pontos : Croqui._contornoDe(t.id)));
    });
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

  zoom(delta) {
    if (!Croqui.vista) return;
    // Botões andam em níveis inteiros mesmo depois de um zoom de pinça fracionário
    Croqui.vista.z = Math.max(3, Math.min(19, Math.round(Croqui.vista.z) + delta));
    Croqui.render();
  },

  trocarTalhao() {
    if (Croqui._dirty && !confirm('Há pontos não salvos — descartar e trocar?')) {
      document.getElementById('croquiTalhao').value = Croqui.atualId;
      return;
    }
    Croqui.atualId = Number(document.getElementById('croquiTalhao').value);
    Croqui.pontos = Croqui._contornoDe(Croqui.atualId);
    Croqui._dirty = false;
    const rotulo = document.querySelector('label[for="croquiUsarArea"]');
    if (rotulo) rotulo.textContent = Croqui.atualId === 0
      ? 'Usar a área medida como área oficial da propriedade'
      : 'Usar a área medida como área oficial do talhão';
    Croqui.render();
  },

  trocarModo() {
    if (document.getElementById('croquiModoGps').checked) Croqui._iniciarGPS();
    else Croqui._pararGPS();
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
    if (typeof Clientes !== 'undefined' && Clientes.fichaClienteId) Clientes.ficha(Clientes.fichaClienteId);
  },

  _distM(a, b) {
    const mLat = 110574, mLng = 111320 * Math.cos(a[0] * Math.PI / 180);
    return Math.hypot((a[0] - b[0]) * mLat, (a[1] - b[1]) * mLng);
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

  /* REGRA: talhão JAMAIS sai da divisa da propriedade (tolerância ~15 m p/ GPS) */
  TOLERANCIA_DIVISA_M: 15,

  /** Índices dos pontos que caem fora da divisa (espelho do CroquiService). */
  _pontosFora(pontos, divisa) {
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
      if (!dentro(xy) && distBorda(xy) > Croqui.TOLERANCIA_DIVISA_M) fora.push(i);
    });
    return fora;
  },

  /**
   * Prende na divisa: desenhando um TALHÃO, ponto que cairia fora da
   * propriedade é puxado para a borda mais próxima (o servidor faz o mesmo).
   */
  _prender(p) {
    if (Croqui.atualId === 0) return p; // desenhando a própria divisa
    const divisa = Croqui._contornoDe(0);
    if (divisa.length < 3) return p;
    const lat0 = divisa.reduce((s, q) => s + Number(q[0]), 0) / divisa.length;
    const mLat = 110574, mLng = 111320 * Math.cos(lat0 * Math.PI / 180);
    const proj = q => [Number(q[1]) * mLng, -Number(q[0]) * mLat];
    const pol = divisa.map(proj);
    const xy = proj(p);
    let dentro = false;
    for (let i = 0, j = pol.length - 1; i < pol.length; j = i++) {
      const [xi, yi] = pol[i], [xj, yj] = pol[j];
      if (((yi > xy[1]) !== (yj > xy[1])) && xy[0] < (xj - xi) * (xy[1] - yi) / ((yj - yi) || 1e-12) + xi) dentro = !dentro;
    }
    if (dentro) return p;
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

  /** Situação da regra para o desenho atual: {fora: [índices], talhoesFora: [nomes]} */
  _validarRegra() {
    if (Croqui.atualId !== 0) {
      return { fora: Croqui._pontosFora(Croqui.pontos, Croqui._contornoDe(0)), talhoesFora: [] };
    }
    // Editando a divisa: nenhum talhão já desenhado pode ficar para fora
    const talhoesFora = [];
    if (Croqui.pontos.length >= 3) {
      Croqui.talhoes.forEach(t => {
        const pts = Croqui._contornoDe(t.id);
        if (pts.length >= 3 && Croqui._pontosFora(pts, Croqui.pontos).length) talhoesFora.push(t.nome);
      });
    }
    return { fora: [], talhoesFora };
  },

  render() {
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

    // Camada de satélite (Web Mercator) — some offline; o desenho continua
    let tilesHtml = '';
    if (Croqui.tiles && navigator.onLine) {
      const e = Croqui._escala();
      // Zoom fracionário (pinça): tiles do nível inteiro mais próximo, escalados
      const zTile = Math.max(3, Math.min(19, Math.round(Croqui.vista.z)));
      const ts = 256 * Math.pow(2, Croqui.vista.z - zTile); // tamanho do tile na tela
      const n = Math.pow(2, zTile);
      const px0 = Croqui.vista.cx * e - larg / 2, py0 = Croqui.vista.cy * e - alt / 2;
      const tx0 = Math.floor(px0 / ts), tx1 = Math.floor((px0 + larg) / ts);
      const ty0 = Math.max(0, Math.floor(py0 / ts)), ty1 = Math.min(n - 1, Math.floor((py0 + alt) / ts));
      for (let tx = tx0; tx <= tx1; tx++) {
        for (let ty = ty0; ty <= ty1; ty++) {
          const txn = ((tx % n) + n) % n; // dá a volta no antimeridiano
          const url = Croqui.tiles.url.replace('{z}', zTile).replace('{x}', txn).replace('{y}', ty);
          tilesHtml += `<img src="${App.escapeHtml(url)}" class="croqui-tile" loading="lazy" alt=""
            style="left:${(tx * ts - px0).toFixed(1)}px;top:${(ty * ts - py0).toFixed(1)}px;width:${ts.toFixed(2)}px;height:${ts.toFixed(2)}px" onerror="this.remove()">`;
        }
      }
    }

    let svg = '';
    const legenda = [];
    // Divisa da propriedade (amarela tracejada) — por baixo dos talhões
    const divisa = Croqui.atualId === 0 ? Croqui.pontos : Croqui._contornoDe(0);
    if (Croqui.atualId !== 0 && divisa.length >= 3) {
      const tela = divisa.map(p => Croqui._paraTela(p, larg, alt));
      svg += `<polygon points="${tela.map(p => p.map(v => v.toFixed(1)).join(',')).join(' ')}"
                fill="${Croqui.COR_PROP}" fill-opacity=".06" stroke="${Croqui.COR_PROP}" stroke-width="3" stroke-dasharray="9 6"/>`;
      legenda.push(`<span><span class="croqui-cor" style="background:${Croqui.COR_PROP}"></span>Propriedade</span>`);
    }
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
    // Contorno em edição (tracejado + vértices arrastáveis)
    const corAtual = Croqui.atualId === 0
      ? Croqui.COR_PROP
      : Croqui.CORES[Croqui.talhoes.findIndex(x => Number(x.id) === Croqui.atualId) % Croqui.CORES.length];
    if (Croqui.pontos.length) {
      const foraSet = new Set(Croqui._validarRegra().fora);
      const tela = Croqui.pontos.map(p => Croqui._paraTela(p, larg, alt));
      const pts = tela.map(p => p.map(v => v.toFixed(1)).join(',')).join(' ');
      svg += Croqui.pontos.length >= 3
        ? `<polygon points="${pts}" fill="${corAtual}" fill-opacity=".28" stroke="${corAtual}" stroke-width="3" stroke-dasharray="8 5"/>`
        : `<polyline points="${pts}" fill="none" stroke="${corAtual}" stroke-width="3" stroke-dasharray="8 5"/>`;
      tela.forEach((p, i) => {
        const invalido = foraSet.has(i); // ponto fora da divisa da propriedade
        svg += `<circle cx="${p[0].toFixed(1)}" cy="${p[1].toFixed(1)}" r="9" class="croqui-vertice" data-idx="${i}"
                  fill="${invalido ? '#dc3545' : (i === 0 ? '#fff' : corAtual)}"
                  stroke="${invalido ? '#7a121f' : corAtual}" stroke-width="3"/>`;
      });
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
    svg += `<g class="croqui-escala"><rect x="14" y="${alt - 34}" width="${(escalaPx + 14).toFixed(1)}" height="24" rx="5" fill="#fff" opacity=".75"/>
      <line x1="20" y1="${alt - 16}" x2="${(20 + escalaPx).toFixed(1)}" y2="${alt - 16}" stroke="#222" stroke-width="2"/>
      <text x="${(20 + escalaPx / 2).toFixed(1)}" y="${alt - 21}" text-anchor="middle" font-size="11" fill="#222">${escalaM >= 1000 ? (escalaM / 1000) + ' km' : escalaM + ' m'}</text></g>
      <g transform="translate(${larg - 26},34)"><circle r="14" fill="#fff" opacity=".75"/><path d="M0,-9 L4,5 L0,2 L-4,5 Z" fill="#222"/><text y="-14" text-anchor="middle" font-size="10" fill="#fff" stroke="#333" stroke-width=".4">N</text></g>`;

    palco.innerHTML = `
      <div class="croqui-tiles">${tilesHtml}</div>
      <svg id="croquiSvg" viewBox="0 0 ${larg} ${alt}" width="${larg}" height="${alt}"></svg>
      <div class="croqui-zoom">
        <button type="button" class="btn btn-light btn-sm" onclick="Croqui.zoom(1)" title="Aproximar"><i class="bi bi-plus-lg"></i></button>
        <button type="button" class="btn btn-light btn-sm" onclick="Croqui.zoom(-1)" title="Afastar"><i class="bi bi-dash-lg"></i></button>
      </div>
      ${Croqui.tiles && navigator.onLine ? `<div class="croqui-atribuicao">${App.escapeHtml(Croqui.tiles.atribuicao || '')}</div>` : ''}`;
    palco.querySelector('#croquiSvg').innerHTML = svg;
    document.getElementById('croquiLegenda').innerHTML = legenda.join('');
    Croqui._atualizarArea();
  },

  _atualizarArea() {
    const ehProp = Croqui.atualId === 0;
    const alvo = ehProp ? Croqui.prop : (Croqui.talhoes.find(x => Number(x.id) === Croqui.atualId) || {});
    const medida = Croqui.areaHa(Croqui.pontos);
    const rotuloAlvo = ehProp ? 'Propriedade (área total)' : 'Talhão';
    const regra = Croqui._validarRegra();
    let alerta = '';
    if (regra.fora.length) {
      alerta = ` <span class="text-danger fw-semibold"><i class="bi bi-exclamation-triangle-fill"></i> ${regra.fora.length} ponto(s) fora da divisa da propriedade</span>`;
    } else if (regra.talhoesFora.length) {
      alerta = ` <span class="text-danger fw-semibold"><i class="bi bi-exclamation-triangle-fill"></i> divisa deixa fora: ${App.escapeHtml(regra.talhoesFora.join(', '))}</span>`;
    }
    document.getElementById('croquiArea').innerHTML = (Croqui.pontos.length >= 3
      ? `<strong>${rotuloAlvo}: ${medida.toLocaleString('pt-BR', { maximumFractionDigits: 2 })} ha</strong>
         <span class="text-muted">(cadastrada: ${Number(alvo.area_ha || 0).toLocaleString('pt-BR', { maximumFractionDigits: 1 })} ha)</span>`
      : `<span class="text-muted">${Croqui.pontos.length} ponto(s) — marque pelo menos 3 para fechar a área</span>`) + alerta;
    // Resumo: divisa da propriedade × soma dos talhões mapeados
    const areaProp = ehProp && Croqui.pontos.length >= 3 ? medida : Number(Croqui.prop.area_gps || 0);
    let plantio = 0;
    Croqui.talhoes.forEach(t => {
      const pts = Number(t.id) === Croqui.atualId ? Croqui.pontos : Croqui._contornoDe(t.id);
      if (pts.length >= 3) plantio += Number(t.id) === Croqui.atualId ? medida : Number(t.area_gps || t.area_ha || 0);
    });
    const totais = document.getElementById('croquiTotais');
    if (totais) totais.innerHTML =
      (areaProp > 0 ? `Propriedade: <strong>${areaProp.toLocaleString('pt-BR', { maximumFractionDigits: 1 })} ha</strong>` : '') +
      (plantio > 0 ? `${areaProp > 0 ? ' · ' : ''}Plantio mapeado: <strong>${plantio.toLocaleString('pt-BR', { maximumFractionDigits: 1 })} ha</strong>` : '');
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
      if (v) { Croqui._arrasto = Number(v.dataset.idx); ev.preventDefault(); return; }
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
        const [x, y, w, h] = pos(ev);
        Croqui.pontos[Croqui._arrasto] = Croqui._prender(Croqui._paraGeo(x, y, w, h));
        Croqui._dirty = true;
        Croqui.render();
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
        if (Croqui._ponteiros.size < 2) Croqui._pinch = null;
        return;
      }
      if (Croqui._arrasto !== null) { Croqui._arrasto = null; return; }
      if (Croqui._pan) {
        // Gesto CANCELADO pelo navegador (ligação, palm rejection) nunca vira ponto
        const foiClique = ev.type === 'pointerup' && ev.isPrimary && !Croqui._pan.moved;
        Croqui._pan = null;
        if (foiClique && document.getElementById('croquiModoManual').checked && Croqui.vista
            && !ev.target.closest('.croqui-zoom')) {
          const [x, y, w, h] = pos(ev);
          Croqui.pontos.push(Croqui._prender(Croqui._paraGeo(x, y, w, h)));
          Croqui._dirty = true;
          Croqui.render();
        }
      }
    }));
    palco.addEventListener('wheel', ev => {
      ev.preventDefault();
      Croqui.zoom(ev.deltaY < 0 ? 1 : -1);
    }, { passive: false });
    window.addEventListener('resize', () => { if (document.querySelector('#modalCroqui.show')) Croqui.render(); });
  },

  desfazer() { Croqui.pontos.pop(); Croqui._dirty = true; Croqui.render(); },

  limpar() {
    if (!confirm('Apagar todos os pontos deste contorno?')) return;
    Croqui.pontos = [];
    Croqui._dirty = true;
    Croqui.render();
  },

  async salvar() {
    if (Croqui.pontos.length > 0 && Croqui.pontos.length < 3) {
      App.alerta('Marque pelo menos 3 pontos para fechar a área (ou Limpar para remover o croqui).', 'warning');
      return;
    }
    // REGRA: talhão dentro da divisa da propriedade (o servidor também valida)
    const regra = Croqui._validarRegra();
    if (regra.fora.length) {
      App.alerta(`O talhão deve ficar DENTRO da divisa da propriedade — ajuste os ${regra.fora.length} ponto(s) em vermelho.`, 'danger');
      return;
    }
    if (regra.talhoesFora.length) {
      App.alerta('A divisa deixaria talhão(ões) para fora: ' + regra.talhoesFora.join(', ') + '. Amplie a divisa.', 'danger');
      return;
    }
    const ehProp = Croqui.atualId === 0;
    const alvo = ehProp ? Croqui.prop : Croqui.talhoes.find(x => Number(x.id) === Croqui.atualId);
    const fd = new FormData();
    fd.append('tipo', ehProp ? 'propriedade' : 'talhao');
    fd.append(ehProp ? 'propriedade_id' : 'talhao_id', ehProp ? Croqui.prop.id : Croqui.atualId);
    fd.append('contorno', JSON.stringify(Croqui.pontos.map(p => [Number(Number(p[0]).toFixed(7)), Number(Number(p[1]).toFixed(7))])));
    fd.append('usar_area', document.getElementById('croquiUsarArea').checked ? '1' : '0');
    try {
      const r = await App.enviarFormOffline(fd, 'index.php?r=clientes/salvar-croqui',
        { modulo: 'Croqui', rotulo: 'Croqui — ' + (ehProp ? 'propriedade ' + Croqui.prop.nome : (alvo ? alvo.nome : 'talhão')) });
      alvo.contorno = Croqui.pontos.length ? JSON.stringify(Croqui.pontos) : null;
      alvo.area_gps = r.area_gps ?? Croqui.areaHa(Croqui.pontos).toFixed(2);
      if (document.getElementById('croquiUsarArea').checked && Croqui.pontos.length >= 3) alvo.area_ha = alvo.area_gps;
      Croqui._dirty = false;
      App.alerta(r.offline ? 'Sem sinal: croqui salvo na fila — será enviado ao reconectar.'
        : (Croqui.pontos.length ? `Croqui salvo — ${Number(alvo.area_gps).toLocaleString('pt-BR')} ha medidos.` : 'Croqui removido.'),
        r.offline ? 'info' : 'success');
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
      }, () => { /* sem GPS: segue só com a hora */ }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 15000 });
    }
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
  });

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
