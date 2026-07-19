/* Módulo Pedidos e Pacotes Agrícolas — página de pedidos */

'use strict';

const Pedidos = {
  itens: [],        // pedido normal
  itensPacote: [],  // pedido de pacote
  estruturaPacote: null,

  /* ---------- Pedido normal ---------- */

  novo() {
    Pedidos.itens = [];
    Pedidos.renderItens();
    document.querySelector('#modalPedido form').reset();
    new bootstrap.Modal('#modalPedido').show();
  },

  addItem() {
    const sel = document.getElementById('pedidoProduto');
    const opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) { App.alerta('Selecione um produto.', 'warning'); return; }
    const qtde = parseFloat((document.getElementById('pedidoQtde').value || '0').replace(',', '.'));
    if (qtde <= 0) { App.alerta('Informe a quantidade.', 'warning'); return; }
    const estoque = parseFloat(opt.dataset.estoque || '0');
    if (qtde > estoque) {
      App.alerta(`Atenção: quantidade acima do estoque disponível (${estoque}).`, 'warning');
    }
    Pedidos.itens.push({
      produto_id: Number(opt.value),
      nome: opt.textContent.trim(),
      unidade: opt.dataset.unidade,
      quantidade: qtde,
      valor_unitario: parseFloat(opt.dataset.preco),
      desconto_pct: parseFloat(opt.dataset.promocao || '0'),
    });
    sel.value = '';
    document.getElementById('pedidoQtde').value = '1';
    Pedidos.renderItens();
  },

  removerItem(i) {
    Pedidos.itens.splice(i, 1);
    Pedidos.renderItens();
  },

  renderItens() {
    const corpo = document.querySelector('#pedidoItens tbody');
    let total = 0;
    corpo.innerHTML = Pedidos.itens.map((it, i) => {
      const sub = it.quantidade * it.valor_unitario * (1 - it.desconto_pct / 100);
      total += sub;
      return `<tr>
        <td class="small">${App.escapeHtml(it.nome)}</td>
        <td class="text-end">${it.quantidade.toLocaleString('pt-BR')} ${App.escapeHtml(it.unidade)}</td>
        <td class="text-end">${App.moeda(it.valor_unitario)}</td>
        <td class="text-end">${it.desconto_pct ? it.desconto_pct + '%' : '—'}</td>
        <td class="text-end">${App.moeda(sub)}</td>
        <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" onclick="Pedidos.removerItem(${i})"><i class="bi bi-trash"></i></button></td>
      </tr>`;
    }).join('') || '<tr><td colspan="6" class="text-center text-muted py-3">Nenhum item.</td></tr>';
    document.getElementById('pedidoTotal').textContent = App.moeda(total);
  },

  async salvar(ev) {
    ev.preventDefault();
    if (!Pedidos.itens.length) { App.alerta('Inclua ao menos um item.', 'warning'); return false; }
    const fd = new FormData(ev.target);
    fd.append('itens', JSON.stringify(Pedidos.itens));
    try {
      const r = await App.json('index.php?r=pedidos/salvar', { method: 'POST', body: fd });
      bootstrap.Modal.getInstance('#modalPedido').hide();
      if (r.status === 'Pendente de aprovação') {
        App.alerta(`Pedido #${r.id} criado como PENDENTE DE APROVAÇÃO: ${r.motivo_pendencia}`, 'warning');
      } else {
        App.alerta(`Pedido #${r.id} emitido com sucesso (${App.moeda(r.valor_total)}).`);
      }
      setTimeout(() => location.reload(), 900);
    } catch (e) { App.alerta(e.message, 'danger'); }
    return false;
  },

  /* ---------- Pedido de Pacote Agrícola ---------- */

  novoPacote() {
    Pedidos.itensPacote = [];
    Pedidos.estruturaPacote = null;
    Pedidos.renderItensPacote();
    document.getElementById('painelPacote').innerHTML =
      '<p class="text-muted small mb-0">Escolha o pacote, o cliente e a área para acompanhar as exigências em tempo real.</p>';
    document.querySelector('#modalPacote form').reset();
    new bootstrap.Modal('#modalPacote').show();
  },

  async carregarPacote(id) {
    if (!id) return;
    try {
      const { pacote } = await App.json(`index.php?r=pedidos/estrutura-pacote&id=${id}`);
      Pedidos.estruturaPacote = pacote;
      Pedidos.sugerirQuantidades();
      Pedidos.avaliarPacote();
    } catch (e) { App.alerta(e.message, 'danger'); }
  },

  /** Pré-carrega os produtos obrigatórios com a quantidade sugerida pela área. */
  sugerirQuantidades() {
    const area = parseFloat((document.getElementById('pacoteArea').value || '0').replace(',', '.'));
    if (!Pedidos.estruturaPacote || area <= 0) return;
    for (const ob of Pedidos.estruturaPacote.obrigatorios) {
      const sugerida = Math.round(ob.dose_ha * area * ob.num_aplicacoes * 10) / 10;
      const existente = Pedidos.itensPacote.find(i => i.produto_id === Number(ob.produto_id));
      if (existente) {
        existente.quantidade = sugerida;
      } else {
        Pedidos.itensPacote.push({
          produto_id: Number(ob.produto_id),
          nome: ob.produto,
          unidade: ob.unidade,
          quantidade: sugerida,
          valor_unitario: parseFloat(ob.preco_referencia),
        });
      }
    }
    Pedidos.renderItensPacote();
    Pedidos.avaliarPacote();
  },

  addItemPacote() {
    const sel = document.getElementById('pacoteProduto');
    const opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) { App.alerta('Selecione um produto.', 'warning'); return; }
    const qtde = parseFloat((document.getElementById('pacoteQtde').value || '0').replace(',', '.'));
    if (qtde <= 0) { App.alerta('Informe a quantidade.', 'warning'); return; }
    const existente = Pedidos.itensPacote.find(i => i.produto_id === Number(opt.value));
    if (existente) {
      existente.quantidade += qtde;
    } else {
      Pedidos.itensPacote.push({
        produto_id: Number(opt.value),
        nome: opt.textContent.trim(),
        unidade: opt.dataset.unidade,
        quantidade: qtde,
        valor_unitario: parseFloat(opt.dataset.preco),
      });
    }
    sel.value = '';
    document.getElementById('pacoteQtde').value = '1';
    Pedidos.renderItensPacote();
    Pedidos.avaliarPacote();
  },

  removerItemPacote(i) {
    Pedidos.itensPacote.splice(i, 1);
    Pedidos.renderItensPacote();
    Pedidos.avaliarPacote();
  },

  renderItensPacote() {
    const corpo = document.querySelector('#pacoteItens tbody');
    corpo.innerHTML = Pedidos.itensPacote.map((it, i) => `<tr>
        <td class="small">${App.escapeHtml(it.nome)}</td>
        <td class="text-end">${it.quantidade.toLocaleString('pt-BR')} ${it.unidade || ''}</td>
        <td class="text-end">${App.moeda(it.valor_unitario)}</td>
        <td class="text-end">${App.moeda(it.quantidade * it.valor_unitario)}</td>
        <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" onclick="Pedidos.removerItemPacote(${i})"><i class="bi bi-trash"></i></button></td>
      </tr>`).join('') || '<tr><td colspan="5" class="text-center text-muted py-3">Nenhum item.</td></tr>';
  },

  avaliarTimer: null,
  avaliarPacote() {
    clearTimeout(Pedidos.avaliarTimer);
    Pedidos.avaliarTimer = setTimeout(Pedidos._avaliarPacote, 350);
  },

  async _avaliarPacote() {
    const pacoteId = document.getElementById('pacoteSelect').value;
    const clienteId = document.getElementById('pacoteCliente').value;
    const area = (document.getElementById('pacoteArea').value || '0');
    if (!pacoteId || !clienteId) return;
    const fd = new FormData();
    fd.append('pacote_id', pacoteId);
    fd.append('cliente_id', clienteId);
    fd.append('area_ha', area);
    fd.append('itens', JSON.stringify(Pedidos.itensPacote));
    try {
      const { painel } = await App.json('index.php?r=pedidos/avaliar-pacote', { method: 'POST', body: fd });
      Pedidos.renderPainel(painel);
    } catch (e) { /* silencioso durante a digitação */ }
  },

  renderPainel(p) {
    const cor = p.percentual >= 100 ? 'success' : (p.percentual >= 60 ? 'warning' : 'danger');
    const linhaCat = c => `<li class="d-flex justify-content-between small py-1 border-bottom">
        <span>${c.atendida ? '✅' : (c.obrigatoria ? '❌' : '▫️')} ${c.familia}${c.obrigatoria ? ' <span class="text-danger">*</span>' : ''}</span>
        <span class="text-muted">${c.quantidade > 0 ? c.quantidade.toLocaleString('pt-BR') : '—'}${c.qtd_minima > 0 ? ' / mín ' + c.qtd_minima : ''} · ${c.desconto_pct}%</span>
      </li>`;
    const linhaVal = v => v.mensagem
      ? `<li class="small text-${v.situacao === 'ausente' ? 'danger' : 'warning'} py-1"><i class="bi bi-exclamation-triangle me-1"></i><strong>${v.produto}</strong>: ${v.mensagem}</li>`
      : `<li class="small text-success py-1"><i class="bi bi-check-circle me-1"></i><strong>${v.produto}</strong>: ${v.quantidade.toLocaleString('pt-BR')} ${v.unidade} (esperado ≈ ${v.esperado})</li>`;

    document.getElementById('painelPacote').innerHTML = `
      <div class="d-flex align-items-center gap-2 mb-2">
        <div class="progress flex-grow-1" style="height:16px">
          <div class="progress-bar bg-${cor}" style="width:${Math.min(100, p.percentual)}%">${p.percentual}%</div>
        </div>
        ${p.completo ? '<span class="badge text-bg-success">Completo</span>' : '<span class="badge text-bg-secondary">Incompleto</span>'}
      </div>
      <ul class="list-unstyled mb-2">${p.categorias.map(linhaCat).join('')}</ul>
      <div class="mb-2"><strong class="small">Validação técnica (${document.getElementById('pacoteArea').value || 0} ha):</strong>
        <ul class="list-unstyled mb-0">${p.validacoes.map(linhaVal).join('') || '<li class="small text-muted">—</li>'}</ul>
      </div>
      <dl class="row small mb-1">
        <dt class="col-7">Valor bruto</dt><dd class="col-5 text-end mb-0">${App.moeda(p.valor_bruto)}</dd>
        <dt class="col-7">Desconto do pacote</dt><dd class="col-5 text-end mb-0 text-success">− ${App.moeda(p.desconto_total)}</dd>
        <dt class="col-7 fw-bold">Valor líquido</dt><dd class="col-5 text-end mb-0 fw-bold">${App.moeda(p.valor_liquido)}</dd>
        <dt class="col-7 text-success">Bonificação prevista</dt><dd class="col-5 text-end mb-0 text-success fw-semibold">${p.bonificacao_sacas > 0 ? p.bonificacao_sacas.toLocaleString('pt-BR') + ' sacas' : '—'}</dd>
      </dl>
      ${p.elegivel
        ? '<div class="small text-success"><i class="bi bi-patch-check me-1"></i>Produtor elegível à bonificação</div>'
        : `<div class="small text-danger"><i class="bi bi-x-circle me-1"></i>${p.motivo_inelegivel}</div>`}
    `;
    const btn = document.getElementById('btnConcluirPacote');
    btn.disabled = !p.pode_concluir;
    btn.title = p.pode_concluir ? '' : 'Atenda todas as exigências do pacote para concluir';
  },

  async salvarPacote(ev) {
    ev.preventDefault();
    const fd = new FormData(ev.target);
    fd.append('itens', JSON.stringify(Pedidos.itensPacote));
    try {
      const r = await App.json('index.php?r=pedidos/salvar-pacote', { method: 'POST', body: fd });
      bootstrap.Modal.getInstance('#modalPacote').hide();
      let msg = `Pedido de pacote #${r.id} emitido (${App.moeda(r.valor_total)})`;
      if (r.bonificacao_sacas > 0) msg += ` — bonificação prevista de ${r.bonificacao_sacas} sacas`;
      App.alerta(r.status === 'Pendente de aprovação' ? msg + '. PENDENTE DE APROVAÇÃO: ' + r.motivo_pendencia : msg + '.',
        r.status === 'Pendente de aprovação' ? 'warning' : 'success');
      setTimeout(() => location.reload(), 1200);
    } catch (e) { App.alerta(e.message, 'danger'); }
    return false;
  },

  /* ---------- Ações da listagem ---------- */

  async detalhe(id) {
    new bootstrap.Offcanvas('#painelPedido').show();
    const corpo = document.getElementById('painelPedidoCorpo');
    corpo.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-success"></div></div>';
    const resp = await fetch(`index.php?r=pedidos/detalhe&id=${id}`, { headers: { 'X-Requested-With': 'fetch-html' } });
    corpo.innerHTML = await resp.text();
  },

  async aprovar(id) {
    await Pedidos.acao('pedidos/aprovar', id, 'Pedido aprovado.');
  },

  async faturar(id) {
    await Pedidos.acao('pedidos/faturar', id, 'Pedido faturado: estoque baixado e compra lançada no histórico.');
  },

  async cancelar(id) {
    if (!confirm('Cancelar este pedido?')) return;
    await Pedidos.acao('pedidos/cancelar', id, 'Pedido cancelado.');
  },

  async acao(rota, id, msg) {
    try {
      const fd = new FormData();
      fd.append('id', id);
      await App.json(`index.php?r=${rota}`, { method: 'POST', body: fd });
      App.alerta(msg);
      setTimeout(() => location.reload(), 700);
    } catch (e) { App.alerta(e.message, 'danger'); }
  },
};
