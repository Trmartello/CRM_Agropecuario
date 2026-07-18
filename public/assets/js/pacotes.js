/* Gestão de Pacotes Agrícolas (cadastro do gestor) */

'use strict';

const Pacotes = {
  novo() {
    const form = document.querySelector('#modalPacoteCad form');
    form.reset();
    form.querySelector('[name=id]').value = 0;
    document.getElementById('pacoteCadTitulo').textContent = 'Novo Pacote Agrícola';
    document.querySelectorAll('#tabelaCategorias tbody tr').forEach(tr => {
      tr.querySelector('.cat-incluir').checked = false;
      tr.querySelector('.cat-desconto').value = 0;
      tr.querySelector('.cat-bonificacao').value = 0;
      tr.querySelector('.cat-obrigatoria').checked = false;
      tr.querySelector('.cat-minima').value = 0;
    });
    document.querySelector('#tabelaObrigatorios tbody').innerHTML = '';
    new bootstrap.Modal('#modalPacoteCad').show();
  },

  async editar(id) {
    Pacotes.novo();
    document.getElementById('pacoteCadTitulo').textContent = 'Editar Pacote Agrícola';
    try {
      const { pacote } = await App.json(`index.php?r=pacotes/obter&id=${id}`);
      const form = document.querySelector('#modalPacoteCad form');
      for (const campo of ['id', 'nome', 'cultura_id', 'safra_id', 'vigencia_inicio', 'vigencia_fim', 'regiao', 'campanha', 'bonificacao_sacas_ha', 'ativo']) {
        const input = form.querySelector(`[name=${campo}]`);
        if (input && pacote[campo] !== null && pacote[campo] !== undefined) input.value = pacote[campo];
      }
      for (const cat of pacote.categorias) {
        const tr = document.querySelector(`#tabelaCategorias tr[data-familia="${cat.familia_id}"]`);
        if (!tr) continue;
        tr.querySelector('.cat-incluir').checked = true;
        tr.querySelector('.cat-desconto').value = cat.desconto_pct;
        tr.querySelector('.cat-bonificacao').value = cat.bonificacao_pct;
        tr.querySelector('.cat-obrigatoria').checked = Number(cat.obrigatoria) === 1;
        tr.querySelector('.cat-minima').value = cat.qtd_minima;
      }
      for (const ob of pacote.obrigatorios) {
        Pacotes.linhaObrigatorio(ob.produto_id, ob.produto, ob.dose_ha, ob.num_aplicacoes, ob.qtd_minima, ob.qtd_maxima);
      }
    } catch (e) { App.alerta(e.message, 'danger'); }
  },

  addObrigatorio() {
    const sel = document.getElementById('obrigProduto');
    const opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) return;
    Pacotes.linhaObrigatorio(opt.value, opt.dataset.nome, 0, 1, 0, 0);
    sel.value = '';
  },

  linhaObrigatorio(produtoId, nome, dose, aplicacoes, min, max) {
    const tr = document.createElement('tr');
    tr.dataset.produto = produtoId;
    tr.innerHTML = `
      <td class="small">${nome}</td>
      <td><input class="form-control form-control-sm ob-dose" inputmode="decimal" value="${dose}"></td>
      <td><input class="form-control form-control-sm ob-aplic" inputmode="numeric" value="${aplicacoes}"></td>
      <td><input class="form-control form-control-sm ob-min" inputmode="decimal" value="${min}"></td>
      <td><input class="form-control form-control-sm ob-max" inputmode="decimal" value="${max}"></td>
      <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()"><i class="bi bi-trash"></i></button></td>`;
    document.querySelector('#tabelaObrigatorios tbody').appendChild(tr);
  },

  async salvar(ev) {
    ev.preventDefault();
    const categorias = [];
    document.querySelectorAll('#tabelaCategorias tbody tr').forEach(tr => {
      if (!tr.querySelector('.cat-incluir').checked) return;
      categorias.push({
        familia_id: Number(tr.dataset.familia),
        desconto_pct: parseFloat(tr.querySelector('.cat-desconto').value.replace(',', '.')) || 0,
        bonificacao_pct: parseFloat(tr.querySelector('.cat-bonificacao').value.replace(',', '.')) || 0,
        obrigatoria: tr.querySelector('.cat-obrigatoria').checked ? 1 : 0,
        qtd_minima: parseFloat(tr.querySelector('.cat-minima').value.replace(',', '.')) || 0,
      });
    });
    const obrigatorios = [];
    document.querySelectorAll('#tabelaObrigatorios tbody tr').forEach(tr => {
      obrigatorios.push({
        produto_id: Number(tr.dataset.produto),
        dose_ha: parseFloat(tr.querySelector('.ob-dose').value.replace(',', '.')) || 0,
        num_aplicacoes: parseInt(tr.querySelector('.ob-aplic').value) || 1,
        qtd_minima: parseFloat(tr.querySelector('.ob-min').value.replace(',', '.')) || 0,
        qtd_maxima: parseFloat(tr.querySelector('.ob-max').value.replace(',', '.')) || 0,
      });
    });
    if (!categorias.length) { App.alerta('Inclua ao menos uma categoria no pacote.', 'warning'); return false; }

    const fd = new FormData(ev.target);
    fd.append('categorias', JSON.stringify(categorias));
    fd.append('obrigatorios', JSON.stringify(obrigatorios));
    try {
      await App.json('index.php?r=pacotes/salvar', { method: 'POST', body: fd });
      App.alerta('Pacote salvo.');
      setTimeout(() => location.reload(), 700);
    } catch (e) { App.alerta(e.message, 'danger'); }
    return false;
  },
};
