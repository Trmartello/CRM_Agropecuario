/* CRM Agropecuário Copérdia — Módulo Agenda / Roteiro de visitas */

'use strict';

const Agenda = {
  novo() {
    const form = document.getElementById('formAgenda');
    form.reset();
    form.querySelector('[name=id]').value = 0;
    form.querySelector('[name=data]').value = new Date().toISOString().slice(0, 10);
    document.getElementById('agendaTitulo').textContent = 'Novo evento';
    new bootstrap.Modal('#modalAgenda').show();
  },

  editar(e) {
    const form = document.getElementById('formAgenda');
    form.reset();
    form.querySelector('[name=id]').value = e.id;
    form.querySelector('[name=titulo]').value = e.titulo;
    form.querySelector('[name=tipo]').value = e.tipo;
    form.querySelector('[name=cliente_id]').value = e.cliente_id || 0;
    form.querySelector('[name=data]').value = e.data;
    form.querySelector('[name=hora]').value = e.hora ? e.hora.slice(0, 5) : '';
    form.querySelector('[name=descricao]').value = e.descricao || '';
    document.getElementById('agendaTitulo').textContent = 'Editar evento';
    new bootstrap.Modal('#modalAgenda').show();
  },

  async salvar(ev) {
    ev.preventDefault();
    try {
      await App.enviarForm(ev.target, 'index.php?r=agenda/salvar');
      bootstrap.Modal.getInstance('#modalAgenda').hide();
      App.alerta('Evento salvo.');
      setTimeout(() => location.reload(), 600);
    } catch (e) { App.alerta(e.message, 'danger'); }
    return false;
  },

  async status(id, status) {
    try {
      const fd = new FormData();
      fd.append('id', id); fd.append('status', status);
      await App.json('index.php?r=agenda/status', { method: 'POST', body: fd });
      App.alerta('Evento atualizado.');
      setTimeout(() => location.reload(), 500);
    } catch (e) { App.alerta(e.message, 'danger'); }
  },

  async verRoteiro() {
    try {
      const resp = await fetch('index.php?r=agenda/roteiro&data=' + new Date().toISOString().slice(0, 10),
        { headers: { 'X-Requested-With': 'fetch' } });
      document.getElementById('roteiroCorpo').innerHTML = await resp.text();
      new bootstrap.Modal('#modalRoteiro').show();
    } catch (e) { App.alerta('Não foi possível abrir o roteiro.', 'danger'); }
  },
};
