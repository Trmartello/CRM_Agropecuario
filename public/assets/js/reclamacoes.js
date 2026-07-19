/* CRM Agropecuário Copérdia — Módulo Reclamações (laudos) */

'use strict';

const Reclamacoes = {
  nova() {
    const form = document.getElementById('formReclamacao');
    form.reset();
    document.getElementById('reclamacaoFotosPreview').innerHTML = '';
    new bootstrap.Modal('#modalReclamacao').show();
  },

  async salvar(ev) {
    ev.preventDefault();
    try {
      await App.enviarForm(ev.target, 'index.php?r=reclamacoes/salvar');
      bootstrap.Modal.getInstance('#modalReclamacao').hide();
      App.alerta('Reclamação registrada.');
      setTimeout(() => location.reload(), 700);
    } catch (e) { App.alerta(e.message, 'danger'); }
    return false;
  },

  async ver(id) {
    try {
      const resp = await fetch('index.php?r=reclamacoes/detalhe&id=' + id, { headers: { 'X-Requested-With': 'fetch' } });
      if (!resp.ok) { const d = await resp.json().catch(() => ({})); throw new Error(d.erro || 'Erro'); }
      document.getElementById('reclamacaoCorpo').innerHTML = await resp.text();
      new bootstrap.Modal('#modalReclamacaoDetalhe').show();
    } catch (e) { App.alerta(e.message, 'danger'); }
  },

  toggleIndenizacao(status) {
    const campo = document.getElementById('campoIndenizacao');
    const input = campo.querySelector('[name=valor_indenizacao]');
    const mostra = status === 'Indenização';
    campo.classList.toggle('d-none', !mostra);
    input.required = mostra;
  },

  async mover(ev) {
    ev.preventDefault();
    try {
      await App.enviarForm(ev.target, 'index.php?r=reclamacoes/mover');
      App.alerta('Laudo atualizado.');
      setTimeout(() => location.reload(), 600);
    } catch (e) { App.alerta(e.message, 'danger'); }
    return false;
  },
};
