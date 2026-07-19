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

  alerta(mensagem, tipo = 'success') {
    const div = document.createElement('div');
    div.className = `toast align-items-center text-bg-${tipo} border-0 show mb-2`;
    div.innerHTML = `<div class="d-flex"><div class="toast-body">${mensagem}</div>
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

  /** Gráfico Potencial x Realizado na ficha do cliente. */
  graficoPotencialCliente() {
    const canvas = document.getElementById('graficoPotencial');
    if (!canvas || typeof Chart === 'undefined') return;
    const dados = JSON.parse(canvas.dataset.potencial || '[]');
    if (!dados.length) return;
    new Chart(canvas, {
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
        if (campo && valor !== null) campo.value = valor;
      }
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
    Visitas.irParaEtapa(1);
    document.getElementById('visitaFotosPreview').innerHTML = '';
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

  async carregarApoio(clienteId) {
    if (!clienteId) return;
    try {
      const dados = await App.json(`index.php?r=visitas/apoio-modal&cliente_id=${clienteId}`);
      Visitas.apoio = dados;
      const selProp = document.getElementById('visitaPropriedade');
      selProp.innerHTML = '<option value="">—</option>' +
        dados.propriedades.map(p => `<option value="${p.id}">${p.nome}</option>`).join('');
      if (dados.propriedades.length === 1) selProp.value = dados.propriedades[0].id;
      Visitas.filtrarTalhoes();
      Visitas.renderPainelComercial(dados.painel);
    } catch (e) { App.alerta(e.message, 'danger'); }
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
    try {
      const { modelos } = await App.json(`index.php?r=visitas/modelos&cultura_id=${culturaId || 0}`);
      alvo.innerHTML = modelos.length
        ? modelos.map(m => `<button type="button" class="btn btn-outline-success btn-sm"
            onclick='Visitas.usarModelo(${JSON.stringify(m.texto_padrao)})'>
            <i class="bi bi-journal-plus me-1"></i>${m.categoria}: ${m.titulo}</button>`).join('')
        : '<span class="text-muted small">Nenhum modelo cadastrado para esta cultura.</span>';
    } catch (e) { alvo.innerHTML = '<span class="text-muted small">Falha ao carregar modelos.</span>'; }
  },

  usarModelo(texto) {
    const campo = document.querySelector('#formVisita [name=recomendacao]');
    campo.value = (campo.value ? campo.value.trimEnd() + '\n\n' : '') + texto;
    App.alerta('Modelo carregado — ajuste o que for necessário.', 'info');
  },

  renderPainelComercial(p) {
    const alvo = document.getElementById('visitaPainelComercial');
    if (!p) { alvo.innerHTML = 'Sem dados.'; return; }
    const corInad = { success: 'success', warning: 'warning', orange: 'warning', danger: 'danger' }[p.inadimplencia.cor] || 'secondary';
    const linhaCompra = c => `<tr><td>${c.produto}</td><td class="text-end">${Number(c.quantidade).toLocaleString('pt-BR')} ${c.unidade}</td><td class="text-end">${App.moeda(c.valor_total)}</td></tr>`;
    const linhaGap = g => `<li class="list-group-item d-flex justify-content-between align-items-center py-1">
        <span>${g.produto} <span class="text-muted small">(${g.familia})</span></span>
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

      <h6 class="text-success">Compras da safra ${p.safra ? p.safra.nome : ''}</h6>
      ${p.compras_safra_atual.length
        ? `<div class="table-responsive"><table class="table table-sm"><thead class="table-light"><tr><th>Produto</th><th class="text-end">Qtde</th><th class="text-end">Valor</th></tr></thead><tbody>${p.compras_safra_atual.map(linhaCompra).join('')}</tbody></table></div>`
        : '<p class="text-muted small">Nenhuma compra na safra atual.</p>'}

      <h6 class="text-success">Entrega futura</h6>
      ${(p.entregas_futuras || []).length
        ? `<div class="table-responsive"><table class="table table-sm"><thead class="table-light"><tr><th>Produto</th><th class="text-end">Contratado</th><th class="text-end">Pendente</th><th>Previsão</th></tr></thead><tbody>${p.entregas_futuras.map(ef => `<tr><td>${ef.produto}</td><td class="text-end">${Number(ef.quantidade_contratada).toLocaleString('pt-BR')} ${ef.unidade}</td><td class="text-end fw-semibold">${Number(ef.quantidade_pendente).toLocaleString('pt-BR')}</td><td>${ef.previsao_entrega ? ef.previsao_entrega.split('-').reverse().join('/') : '—'}</td></tr>`).join('')}</tbody></table></div>`
        : '<p class="text-muted small">Sem contratos de entrega futura.</p>'}

      <h6 class="text-success">Pedidos recentes</h6>
      ${(p.pedidos || []).length
        ? `<ul class="list-unstyled small mb-0">${p.pedidos.slice(0, 5).map(pd => `<li class="py-1 border-bottom d-flex justify-content-between"><span>#${pd.id} · ${pd.tipo}${pd.pacote ? ' (' + pd.pacote + ')' : ''}</span><span><span class="badge text-bg-${{'Rascunho':'secondary','Pendente de aprovação':'warning','Aprovado':'primary','Faturado':'success','Cancelado':'dark'}[pd.status] || 'secondary'}">${pd.status}</span> ${App.moeda(pd.valor_total)}</span></li>`).join('')}</ul>`
        : '<p class="text-muted small mb-0">Nenhum pedido registrado.</p>'}`;
  },

  irParaEtapa(n) {
    Visitas.etapa = n;
    document.querySelectorAll('#modalVisita .etapa').forEach(div => {
      div.classList.toggle('d-none', Number(div.dataset.etapa) !== n);
    });
    document.querySelectorAll('#visitaEtapas .nav-link').forEach(btn => {
      const e = Number(btn.dataset.etapa);
      btn.classList.toggle('active', e === n);
      btn.classList.toggle('concluida', e < n);
    });
    document.getElementById('btnEtapaAnterior').disabled = n === 1;
    document.getElementById('btnEtapaProxima').classList.toggle('d-none', n === 5);
    document.getElementById('btnSalvarVisita').classList.toggle('d-none', n !== 5);
  },

  proximaEtapa() {
    if (Visitas.etapa === 1 && !document.getElementById('visitaCliente').value) {
      App.alerta('Selecione o cliente antes de avançar.', 'warning');
      return;
    }
    if (Visitas.etapa < 5) Visitas.irParaEtapa(Visitas.etapa + 1);
  },

  etapaAnterior() {
    if (Visitas.etapa > 1) Visitas.irParaEtapa(Visitas.etapa - 1);
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
    try {
      if (!navigator.onLine) {
        // Sem conexão: guarda na fila local e sincroniza depois
        await Offline.guardarVisita(form);
        bootstrap.Modal.getInstance('#modalVisita').hide();
        App.alerta('Sem conexão: visita guardada no aparelho. Será enviada automaticamente quando a internet voltar.', 'info');
        return false;
      }
      await App.enviarForm(form, 'index.php?r=visitas/salvar');
      bootstrap.Modal.getInstance('#modalVisita').hide();
      App.alerta('Visita registrada com sucesso.');
      setTimeout(() => location.href = 'index.php?r=visitas', 700);
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

  async carregar() {
    const familia = document.getElementById('filtroFamilia').value;
    const ordem = document.getElementById('filtroOrdem').value;
    try {
      const { ranking } = await App.json(
        `index.php?r=relatorios/potencial-dados&dimensao=${Potencial.dimensao}&ordem=${ordem}&familia_id=${familia}`
      );
      Potencial.renderTabela(ranking);
      Potencial.renderGrafico(ranking);
    } catch (e) { App.alerta(e.message, 'danger'); }
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
        <td>${r.dimensao}</td>
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

document.addEventListener('DOMContentLoaded', () => {
  Voz.iniciar();

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
  window.addEventListener('online', () => { atualizarIndicador(); if (window.Offline) Offline.sincronizar(); });
  window.addEventListener('offline', atualizarIndicador);
  atualizarIndicador();
});
