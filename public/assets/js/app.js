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

  /** Escapa texto para inserção segura via innerHTML. */
  escapeHtml(v) {
    return String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  },

  /** Aceita apenas links internos (index.php…); descarta esquemas perigosos como javascript:. */
  linkSeguro(v) {
    const s = String(v || '');
    return /^(index\.php|\?|#|\/|uploads\/)/.test(s) ? s : '#';
  },

  /** Renderiza miniaturas das fotos/PDFs escolhidos em um input múltiplo. */
  previewFotosGrid(input, gridId) {
    const grid = document.getElementById(gridId);
    if (!grid) return;
    grid.innerHTML = '';
    for (const arquivo of input.files || []) {
      if (arquivo.type === 'application/pdf') {
        grid.insertAdjacentHTML('beforeend',
          '<div class="foto-miniatura d-flex align-items-center justify-content-center bg-light border"><i class="bi bi-file-earmark-pdf fs-3 text-danger"></i></div>');
      } else {
        const img = document.createElement('img');
        img.className = 'foto-miniatura';
        img.src = URL.createObjectURL(arquivo);
        grid.appendChild(img);
      }
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
    new bootstrap.Modal('#modalPropriedade').show();
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

/* ============================== VISITAS ============================== */

const Visitas = {
  etapa: 1,
  apoio: null,

  nova(clienteId) {
    const form = document.getElementById('formVisita');
    if (!form) { location.href = 'index.php?r=visitas&nova=1' + (clienteId ? '&cliente_id=' + clienteId : ''); return; }
    form.reset();
    // Limpa o estado do cliente anterior (evita mostrar propriedades/talhões/painel de outro produtor)
    Visitas.apoio = null;
    document.getElementById('visitaPropriedade').innerHTML = '';
    document.getElementById('visitaTalhao').innerHTML = '';
    document.getElementById('visitaPainelComercial').innerHTML = '<span class="text-muted small">Selecione o cliente na etapa 1 para carregar os dados comerciais.</span>';
    document.getElementById('visitaModelos').innerHTML = '<span class="text-muted small">Escolha a cultura na etapa 1 para listar os modelos.</span>';
    Visitas.irParaEtapa(1);
    document.getElementById('visitaFotosPreview').innerHTML = '';
    Visitas.atualizarCompletude();
    new bootstrap.Modal('#modalVisita').show();

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

  /** Divide o campo único de data+hora nos campos que o servidor espera. */
  sincronizarDataHora(input) {
    const form = input.closest('form');
    const [d = '', h = ''] = (input.value || '').split('T');
    form.querySelector('[name=data_visita]').value = d;
    form.querySelector('[name=hora]').value = h;
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
      App.alerta('Modo offline: usando a carteira salva' + (dados._atualizado ? ' de ' + dados._atualizado : '') + '.', 'info');
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
    return {
      propriedades: a.propriedades || [],
      talhoes: a.talhoes || [],
      painel: null, // dados comerciais não ficam no snapshot (indisponíveis offline)
      _atualizado: snap.atualizado_em ? new Date(snap.atualizado_em).toLocaleString('pt-BR') : '',
    };
  },

  filtrarTalhoes() {
    if (!Visitas.apoio) return;
    const propId = Number(document.getElementById('visitaPropriedade').value);
    const talhoes = Visitas.apoio.talhoes.filter(t => !propId || Number(t.propriedade_id) === propId);
    document.getElementById('visitaTalhao').innerHTML = '<option value="">—</option>' +
      talhoes.map(t => `<option value="${t.id}" data-cultura="${t.cultura_id || ''}">${t.nome}${t.cultura ? ' (' + t.cultura + ')' : ''}</option>`).join('');
  },

  aoEscolherTalhao() {
    const opt = document.querySelector('#visitaTalhao option:checked');
    const culturaId = opt ? opt.dataset.cultura : '';
    if (culturaId) {
      document.getElementById('visitaCultura').value = culturaId;
      Visitas.carregarModelos();
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
    const pct = Visitas.completude();
    if (pct < 100) {
      const faltando = Visitas.camposFaltando();
      const msg = `Falta ${100 - pct}% do cadastro para finalizar` +
        (faltando.length ? ` (${faltando.join(', ')})` : '') + '.\n\n' +
        'Deseja finalizar assim mesmo? A visita ficará marcada como NÃO FINALIZADA e poderá ser completada depois.';
      if (!confirm(msg)) return false;
    }
    try {
      const sel = document.getElementById('visitaCliente');
      const nome = sel && sel.selectedOptions[0] ? sel.selectedOptions[0].text : 'Visita';
      const r = await App.enviarFormOffline(form, 'index.php?r=visitas/salvar', { modulo: 'Visitas', rotulo: 'Visita — ' + nome });
      bootstrap.Modal.getInstance('#modalVisita').hide();
      if (r.offline) {
        App.alerta('Sem conexão: visita guardada no aparelho. Será enviada quando a internet voltar.', 'info');
      } else {
        App.alerta('Visita registrada com sucesso.');
        setTimeout(() => location.href = 'index.php?r=visitas', 700);
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
  atualizarIndicador();
  if (typeof Offline !== 'undefined') { Pendencias.atualizar(); OfflineView.aplicar(); }
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
      ? '<span class="badge rounded-pill text-bg-success">Cadastro 100%</span>'
      : `<span class="badge rounded-pill text-bg-warning text-dark">Cadastro ${Number(p.ultima_completude)}%</span>`;
  },

  clientes(snap, quando) {
    const tb = document.getElementById('tabelaClientes');
    const lista = [...snap.produtores].sort((a, b) => String(a.nome).localeCompare(b.nome, 'pt-BR'));
    tb.innerHTML = lista.map(c => `
      <tr>
        <td>
          <div class="fw-semibold">${App.escapeHtml(c.nome)}${Number(c.prospecto) ? ' <span class="badge text-bg-warning ms-1">Prospecto</span>' : ''}</div>
          <div class="small text-muted d-md-none">${App.escapeHtml(c.municipio || '')}</div>
        </td>
        <td class="d-none d-md-table-cell">${App.escapeHtml(c.municipio || '—')}</td>
        <td class="d-none d-md-table-cell">${c.situacao ? `<span class="badge text-bg-${c.situacao === 'Associado' ? 'success' : 'secondary'}">${App.escapeHtml(c.situacao)}</span>` : '—'}</td>
        <td class="d-none d-lg-table-cell">${App.escapeHtml(c.nivel_tecnologico || '')}</td>
        <td class="d-none d-lg-table-cell">${c.ultima_visita ? String(c.ultima_visita).split('-').reverse().join('/') : '—'}</td>
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
          <div class="fw-semibold">${App.escapeHtml(p.nome)}${p.risco_churn ? ` <span class="badge text-bg-danger ms-1">Churn −${Number(p.queda_percentual)}%</span>` : ''}</div>
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
