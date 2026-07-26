<?php
/**
 * Portal do Produtor — captura de notas fiscais (spec nf-ingestao §6 — PR 3).
 * Fluxo de OPT-IN/revogação da procuração de captura de DF-e. O termo dá a
 * ciência exigida no §8/§10: revogável a qualquer momento; o campo (RTV/técnico)
 * nunca vê; a gestão da Copérdia pode consultar para controladoria/estratégia.
 */
?>
<div class="card mt-3" id="cardPortalFiscal">
  <div class="card-header">
    <i class="bi bi-receipt me-2 text-success"></i><strong>Minhas notas fiscais (captura automática)</strong>
  </div>
  <div class="card-body">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
      <span class="small text-muted">Situação:</span>
      <span class="badge fs-6" id="pfStatus">carregando…</span>
      <span class="small text-muted" id="pfDesde"></span>
    </div>

    <p class="small mb-2">
      Com a sua autorização, a Copérdia captura automaticamente as notas fiscais em que
      <strong>você é o destinatário</strong> (compras de insumos em qualquer fornecedor) para
      preencher o custo da sua lavoura sem digitação. É você quem decide.
    </p>

    <div class="border rounded p-3 bg-light small mb-3" id="pfTermo">
      <strong>Termo de autorização — leia antes de aceitar</strong>
      <ul class="mb-2 mt-1">
        <li>Autorizo a captura das notas fiscais eletrônicas (NF-e) em que sou destinatário,
            para uso no <strong>meu</strong> custo de lavoura neste Portal.</li>
        <li>Os dados das minhas notas <strong>não são acessíveis à equipe comercial de campo</strong>
            (vendedor, consultor e gestor técnico) — proteção aplicada no próprio banco de dados.</li>
        <li>Estou ciente de que a <strong>gestão da Copérdia</strong> (Diretoria, Controladoria e
            Gestor Comercial) pode consultar o meu custo para fins de controladoria e estratégia,
            com todo acesso registrado em auditoria.</li>
        <li>Posso <strong>revogar esta autorização a qualquer momento</strong> — a captura para na
            hora e posso pedir a exclusão dos dados já capturados.</li>
      </ul>
      <div class="form-check">
        <input class="form-check-input" type="checkbox" id="pfCiente"
               onchange="document.getElementById('pfBtnAutorizar').disabled = !this.checked">
        <label class="form-check-label" for="pfCiente">Li e concordo com o termo acima.</label>
      </div>
    </div>

    <div class="d-flex flex-wrap gap-2">
      <button type="button" class="btn btn-success px-4" style="min-height:44px" id="pfBtnAutorizar"
              disabled onclick="PortalFiscal.autorizar()">
        <i class="bi bi-file-earmark-check me-1"></i>Autorizar a captura das minhas notas
      </button>
      <button type="button" class="btn btn-outline-success d-none" style="min-height:44px" id="pfBtnBuscar"
              onclick="PortalFiscal.pull(true)">
        <i class="bi bi-arrow-repeat me-1"></i>Buscar minhas notas agora
      </button>
      <button type="button" class="btn btn-outline-danger d-none" style="min-height:44px" id="pfBtnRevogar"
              onclick="PortalFiscal.revogar()">
        <i class="bi bi-x-circle me-1"></i>Revogar autorização
      </button>
    </div>
    <div class="small text-muted mt-2" id="pfResumo"></div>
    <div class="small text-muted mt-1" id="pfNota"></div>

    <!-- Canal 2 (§3): upload de XML — independe da captura automática -->
    <div class="border-top pt-3 mt-3">
      <div class="small text-muted mb-2">
        Recebeu o XML da nota por e-mail ou do seu contador? Envie aqui — funciona
        <strong>sem</strong> autorizar a captura automática.
      </div>
      <input type="file" id="pfXmls" accept=".xml" multiple class="d-none"
             onchange="PortalFiscal.upload(this.files)">
      <button type="button" class="btn btn-outline-success" style="min-height:44px"
              onclick="document.getElementById('pfXmls').click()">
        <i class="bi bi-file-earmark-arrow-up me-1"></i>Enviar XML de nota
      </button>
      <div class="small mt-2" id="pfUploadResumo"></div>
    </div>

    <div class="mt-3 d-none" id="pfNotas"></div>
  </div>
</div>

<!-- Modal: revisão dos itens da nota (mapeamento item -> custo, §7) -->
<div class="modal fade" id="modalNfe" tabindex="-1">
  <div class="modal-dialog modal-lg modal-fullscreen-md-down">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-receipt me-2 text-success"></i><span id="nfeTitulo">Nota fiscal</span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="small text-muted mb-2" id="nfeInfo"></div>
        <p class="small mb-2">Confira a que item do custo cada compra pertence. <strong>Nada entra no
          seu custo sem a sua confirmação</strong> — o que não fizer sentido, marque "Ignorar".</p>
        <div class="table-responsive">
          <table class="table table-sm align-middle" id="nfeItens"></table>
        </div>
      </div>
      <div class="modal-footer flex-wrap">
        <div class="d-flex flex-wrap gap-2 align-items-center me-auto d-none" id="nfeAplicarBox">
          <select class="form-select form-select-sm" style="min-height:44px;max-width:16rem" id="nfeLavoura"
                  aria-label="Lavoura que recebe o custo"></select>
          <button type="button" class="btn btn-outline-success" style="min-height:44px" id="nfeBtnAplicar"
                  onclick="PortalFiscal.aplicar()">
            <i class="bi bi-box-arrow-in-down me-1"></i>Aplicar no custo da lavoura
          </button>
        </div>
        <button type="button" class="btn btn-outline-secondary" style="min-height:44px" data-bs-dismiss="modal">Fechar</button>
        <button type="button" class="btn btn-success px-4" style="min-height:44px" id="nfeBtnSalvar"
                onclick="PortalFiscal.salvarItens()">
          <i class="bi bi-check-lg me-1"></i>Confirmar itens escolhidos
        </button>
      </div>
    </div>
  </div>
</div>

<script>
const PortalFiscal = {
  async carregar() {
    if (!document.getElementById('cardPortalFiscal')) return;
    try {
      const d = await App.json('index.php?r=portal/fiscal-autorizacao');
      this.render(d.autorizacao);
    } catch (e) {
      document.getElementById('pfStatus').textContent = 'indisponível';
      document.getElementById('pfStatus').className = 'badge fs-6 text-bg-secondary';
    }
    this.listarNotas(); // notas de upload aparecem mesmo sem captura automática
  },

  /** Canal 2: upload de XML da NF-e (mesmo pipeline do pull — §11.6). */
  async upload(files) {
    if (!files || !files.length) return;
    const resumo = document.getElementById('pfUploadResumo');
    resumo.innerHTML = '<span class="text-muted"><span class="spinner-border spinner-border-sm me-1"></span>Enviando…</span>';
    try {
      const fd = new FormData();
      for (const f of files) fd.append('xmls[]', f, f.name);
      const d = await App.json('index.php?r=portal/fiscal-upload', { method: 'POST', body: fd });
      const linhas = d.resultados.map(r => r.ok
        ? `<div class="text-success"><i class="bi bi-check-circle me-1"></i>${App.escapeHtml(r.arquivo)} — ${r.novo ? r.itens + ' item(ns) capturados' : 'nota já registrada'}</div>`
        : `<div class="text-danger"><i class="bi bi-x-circle me-1"></i>${App.escapeHtml(r.arquivo)} — ${App.escapeHtml(r.erro)}</div>`);
      resumo.innerHTML = linhas.join('');
      if (d.novas > 0) App.alerta(d.novas + ' nota(s) recebida(s). Revise os itens antes de aplicar ao custo.');
      this.listarNotas();
      document.getElementById('pfXmls').value = '';
    } catch (e) { resumo.innerHTML = ''; App.alerta(e.message, 'danger'); }
  },

  render(aut) {
    const st = document.getElementById('pfStatus');
    const desde = document.getElementById('pfDesde');
    const termo = document.getElementById('pfTermo');
    const bAut = document.getElementById('pfBtnAutorizar');
    const bRev = document.getElementById('pfBtnRevogar');
    const nota = document.getElementById('pfNota');
    const dataBr = v => (v ? v.slice(0, 10).split('-').reverse().join('/') : '');

    const status = aut ? aut.status : null;
    const mapa = {
      ativa: ['Ativa', 'text-bg-success'],
      pendente: ['Aguardando o provedor', 'text-bg-warning'],
      revogada: ['Revogada', 'text-bg-secondary'],
      expirada: ['Expirada', 'text-bg-warning'],
      erro: ['Erro no provedor', 'text-bg-danger'],
    };
    const [rot, cls] = mapa[status] || ['Nenhuma autorização', 'text-bg-light border'];
    st.textContent = rot;
    st.className = 'badge fs-6 ' + cls;
    desde.textContent = status === 'ativa' && aut.dt_autorizacao ? 'desde ' + dataBr(aut.dt_autorizacao)
      : status === 'revogada' && aut.dt_revogacao ? 'em ' + dataBr(aut.dt_revogacao) : '';

    const ativa = status === 'ativa' || status === 'pendente';
    termo.classList.toggle('d-none', ativa);
    bAut.classList.toggle('d-none', ativa);
    bRev.classList.toggle('d-none', !ativa);
    document.getElementById('pfBtnBuscar').classList.toggle('d-none', status !== 'ativa');
    nota.textContent = ativa
      ? 'Suas notas passam a ser capturadas automaticamente e aparecem aqui para você revisar antes de qualquer valor entrar no custo.'
      : (status === 'revogada' ? 'A captura está parada. Você pode autorizar de novo quando quiser.' : '');
    if (status === 'ativa') this.pull(false); // pull oportunista (1x/24h no servidor)
    else { document.getElementById('pfResumo').textContent = ''; document.getElementById('pfNotas').classList.add('d-none'); }
  },

  /** Pull em segundo plano (auto = 1x/24h; forçado pelo botão). */
  async pull(forcar) {
    const btn = document.getElementById('pfBtnBuscar');
    if (forcar) btn.disabled = true;
    try {
      const fd = new FormData();
      if (forcar) fd.append('forcar', '1');
      const d = await App.json('index.php?r=portal/fiscal-pull', { method: 'POST', body: fd });
      const dt = d.ultima_busca ? d.ultima_busca.slice(0, 16).replace('T', ' ').split('-').length === 3
        ? d.ultima_busca.slice(0, 10).split('-').reverse().join('/') + d.ultima_busca.slice(10, 16) : d.ultima_busca : null;
      document.getElementById('pfResumo').textContent =
        'Notas capturadas: ' + d.total_notas + (dt ? ' · última busca ' + dt : '');
      if (forcar) {
        App.alerta(d.captura && d.captura.novos > 0
          ? d.captura.novos + ' nota(s) nova(s) capturada(s).'
          : 'Nenhuma nota nova por enquanto.');
      }
      if (typeof this.listarNotas === 'function') this.listarNotas();
    } catch (e) { if (forcar) App.alerta(e.message, 'danger'); }
    finally { if (forcar) btn.disabled = false; }
  },

  async autorizar() {
    const btn = document.getElementById('pfBtnAutorizar');
    btn.disabled = true;
    try {
      const fd = new FormData();
      fd.append('ciente', document.getElementById('pfCiente').checked ? '1' : '0');
      const d = await App.json('index.php?r=portal/fiscal-autorizar', { method: 'POST', body: fd });
      this.render(d.autorizacao);
      App.alerta('Autorização registrada. Suas notas serão capturadas automaticamente.');
    } catch (e) { App.alerta(e.message, 'danger'); btn.disabled = false; }
  },

  /* ---- lista de notas + revisão do mapeamento (§7 — PR5) ---- */
  notaAberta: null,

  brl(v) { return (v === null || v === undefined || isNaN(v)) ? '—' : Number(v).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); },
  dataBr(v) { return v ? v.slice(0, 10).split('-').reverse().join('/') : '—'; },

  async listarNotas() {
    const box = document.getElementById('pfNotas');
    try {
      const d = await App.json('index.php?r=portal/fiscal-notas');
      const notas = d.notas || [];
      if (!notas.length) { box.classList.add('d-none'); return; }
      box.classList.remove('d-none');
      box.innerHTML = '<div class="list-group">' + notas.map(n => {
        const badge = n.pendentes > 0
          ? `<span class="badge text-bg-warning">${n.pendentes} a revisar</span>`
          : (n.confirmados > 0 ? `<span class="badge text-bg-success">${n.confirmados} confirmado(s)</span>`
            : '<span class="badge text-bg-light border">revisada</span>');
        return `<button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-2"
                        style="min-height:44px" onclick="PortalFiscal.abrirNota(${n.id})">
          <span class="text-start"><strong>${App.escapeHtml(n.emitente || 'Fornecedor')}</strong>
            <span class="small text-muted d-block">NF ${App.escapeHtml(n.numero || '—')} · ${this.dataBr(n.dtEmissao)} · ${this.brl(n.valorTotal)}</span></span>
          ${badge}</button>`;
      }).join('') + '</div>';
    } catch (e) { box.classList.add('d-none'); }
  },

  async abrirNota(id) {
    try {
      const d = await App.json('index.php?r=portal/fiscal-nota&id=' + id);
      this.notaAberta = d;
      document.getElementById('nfeTitulo').textContent =
        (d.nota.emitente || 'Nota fiscal') + ' · NF ' + (d.nota.numero || '—');
      document.getElementById('nfeInfo').textContent =
        'Emitida em ' + this.dataBr(d.nota.dtEmissao) + ' · total ' + this.brl(d.nota.valorTotal)
        + (d.nota.naturezaOp ? ' · ' + d.nota.naturezaOp : '');
      const opts = c => d.catalogo.map(x =>
        `<option value="${x.id}" ${c === x.id ? 'selected' : ''}>${App.escapeHtml(x.descricao)}</option>`).join('');
      document.getElementById('nfeItens').innerHTML =
        '<thead class="table-light"><tr><th>Compra</th><th class="text-end">Valor</th><th style="min-width:14rem">Item do custo</th></tr></thead><tbody>'
        + d.itens.map(i => `<tr data-item="${i.id}">
            <td>${App.escapeHtml(i.descricao)}<span class="small text-muted d-block">NCM ${App.escapeHtml(i.ncm || '—')}${i.quantidade ? ' · ' + i.quantidade + ' ' + App.escapeHtml(i.unidade || '') : ''}</span></td>
            <td class="text-end">${this.brl(i.valorTotal)}</td>
            <td><select class="form-select form-select-sm" style="min-height:44px" data-status="${i.status}">
              <option value="">— escolher depois —</option>${opts(i.catItemId)}
              <option value="ig" ${i.status === 'ignorado' ? 'selected' : ''}>Ignorar este item</option>
            </select>${i.status === 'confirmado' ? '<span class="small text-success"><i class="bi bi-check-circle me-1"></i>confirmado</span>' : ''}</td>
          </tr>`).join('') + '</tbody>';
      // lavouras do produtor p/ o "Aplicar no custo" (aparece se houver alguma)
      try {
        const lv = await App.json('index.php?r=portal/lavouras');
        const box = document.getElementById('nfeAplicarBox');
        const sel = document.getElementById('nfeLavoura');
        const lavs = lv.lavouras || [];
        box.classList.toggle('d-none', !lavs.length);
        sel.innerHTML = lavs.map(l =>
          `<option value="${l.id}">${App.escapeHtml(l.cultura)} · ${App.escapeHtml(l.safra)} · ${l.areaHa} ha</option>`).join('');
      } catch (e) { document.getElementById('nfeAplicarBox').classList.add('d-none'); }
      bootstrap.Modal.getOrCreateInstance(document.getElementById('modalNfe')).show();
    } catch (e) { App.alerta(e.message, 'danger'); }
  },

  /** Aplica os itens confirmados da nota no custo da lavoura escolhida (PR6). */
  async aplicar() {
    if (!this.notaAberta) return;
    const btn = document.getElementById('nfeBtnAplicar');
    btn.disabled = true;
    try {
      // garante que as escolhas atuais estão salvas antes de aplicar
      await this.salvarItens(true);
      const d = await App.json('index.php?r=portal/fiscal-nota&id=' + this.notaAberta.nota.id);
      const confirmados = d.itens.filter(i => i.status === 'confirmado').map(i => i.id);
      if (!confirmados.length) { App.alerta('Confirme ao menos um item antes de aplicar.', 'warning'); return; }
      const fd = new FormData();
      fd.append('id', document.getElementById('nfeLavoura').value);
      fd.append('itens', JSON.stringify(confirmados));
      const r = await App.json('index.php?r=portal/lavoura-aplicar-nfe', { method: 'POST', body: fd });
      bootstrap.Modal.getInstance(document.getElementById('modalNfe')).hide();
      App.alerta(r.aplicados + ' item(ns) aplicados ao custo. O valor por hectare foi recalculado pelo servidor.');
      // atualiza o card do custo se a lavoura aplicada estiver aberta
      if (typeof CustoLavoura !== 'undefined' && r.detalhe) {
        CustoLavoura.det = r.detalhe;
        CustoLavoura.base = r.detalhe.lavoura.baseCustoPadrao || 'ct';
        CustoLavoura.renderDetalhe();
        CustoLavoura.renderLista && CustoLavoura.carregar();
      }
      this.listarNotas();
    } catch (e) { App.alerta(e.message, 'danger'); } finally { btn.disabled = false; }
  },

  async salvarItens(silencioso) {
    if (!this.notaAberta) return;
    const btn = document.getElementById('nfeBtnSalvar');
    btn.disabled = true;
    try {
      const itens = [];
      document.querySelectorAll('#nfeItens tr[data-item]').forEach(tr => {
        const v = tr.querySelector('select').value;
        if (v === 'ig') itens.push({ id: parseInt(tr.dataset.item, 10), catItemId: null, status: 'ignorado' });
        else if (v !== '') itens.push({ id: parseInt(tr.dataset.item, 10), catItemId: parseInt(v, 10), status: 'confirmado' });
      });
      if (!itens.length) {
        if (!silencioso) App.alerta('Escolha o item do custo (ou "Ignorar") em pelo menos uma linha.', 'warning');
        return;
      }
      const fd = new FormData();
      fd.append('id', this.notaAberta.nota.id);
      fd.append('itens', JSON.stringify(itens));
      await App.json('index.php?r=portal/fiscal-nota-itens', { method: 'POST', body: fd });
      if (!silencioso) {
        bootstrap.Modal.getInstance(document.getElementById('modalNfe')).hide();
        App.alerta('Itens revisados. Você pode aplicá-los ao custo da sua lavoura.');
      }
      this.listarNotas();
    } catch (e) { App.alerta(e.message, 'danger'); throw e; } finally { btn.disabled = false; }
  },

  async revogar() {
    if (!confirm('Revogar a autorização? A captura das suas notas para imediatamente.')) return;
    try {
      const d = await App.json('index.php?r=portal/fiscal-revogar', { method: 'POST', body: new FormData() });
      document.getElementById('pfCiente').checked = false;
      document.getElementById('pfBtnAutorizar').disabled = true;
      this.render(d.autorizacao);
      App.alerta('Autorização revogada. A captura foi interrompida.');
    } catch (e) { App.alerta(e.message, 'danger'); }
  },
};
document.addEventListener('DOMContentLoaded', () => PortalFiscal.carregar());
</script>
