/* CRM Agropecuário Copérdia — Módulo Despesas (KM, refeições, prestação de contas) */

'use strict';

const Despesas = {
  valorKm: 0,

  novoKm() {
    const form = document.getElementById('formKm');
    form.reset();
    form.querySelector('[name=data]').value = new Date().toISOString().slice(0, 10);
    document.getElementById('kmPreview').textContent = 'Informe os KM para calcular o valor.';
    Despesas.tipoDestino('Produtor');
    Despesas.toggleProspecto(false);
    new bootstrap.Modal('#modalKm').show();
  },

  /** Alterna os blocos de destino (Produtor / Filial / Lugar). */
  tipoDestino(tipo) {
    document.querySelectorAll('#formKm .destino-bloco').forEach(b => {
      b.classList.toggle('d-none', b.dataset.destino !== tipo);
    });
  },

  toggleProspecto(ehProspecto) {
    document.getElementById('blocoProdutor').classList.toggle('d-none', ehProspecto);
    document.getElementById('blocoProspecto').classList.toggle('d-none', !ehProspecto);
    const chk = document.getElementById('chkProspecto');
    if (chk && chk.checked !== ehProspecto) chk.checked = ehProspecto;
  },

  novoVeiculo() {
    const form = document.getElementById('formVeiculo');
    form.reset();
    new bootstrap.Modal('#modalVeiculo').show();
  },

  async salvarVeiculo(ev) {
    ev.preventDefault();
    try {
      const r = await App.enviarForm(ev.target, 'index.php?r=despesas/salvar-veiculo');
      const sel = document.querySelector('#formKm [name=veiculo_id]');
      const opt = new Option(r.veiculo.descricao + (r.veiculo.placa ? ' — ' + r.veiculo.placa : ''), r.veiculo.id, true, true);
      sel.add(opt);
      bootstrap.Modal.getInstance('#modalVeiculo').hide();
      App.alerta('Veículo cadastrado.');
    } catch (e) { App.alerta(e.message, 'danger'); }
    return false;
  },

  veiculoMudou() {
    // Ao trocar de veículo, oferece a KM final do último lançamento daquele veículo
    Despesas.pegarUltimoKm(true);
  },

  async pegarUltimoKm(silencioso = false) {
    const form = document.getElementById('formKm');
    const veiculoId = form.querySelector('[name=veiculo_id]').value || 0;
    try {
      const r = await App.json('index.php?r=despesas/ultimo-km&veiculo_id=' + veiculoId, { headers: { 'X-Requested-With': 'fetch' } });
      if (r.km === null || r.km === undefined) {
        if (!silencioso) App.alerta('Nenhum lançamento anterior encontrado.', 'info');
        return;
      }
      form.querySelector('[name=km_inicial]').value = r.km;
      Despesas.previewKm();
      if (!silencioso) App.alerta('KM inicial preenchida com o último lançamento (' + r.km + ').');
    } catch (e) { if (!silencioso) App.alerta(e.message, 'danger'); }
  },

  novaRefeicao() {
    const form = document.getElementById('formRefeicao');
    form.reset();
    form.querySelector('[name=data]').value = new Date().toISOString().slice(0, 10);
    new bootstrap.Modal('#modalRefeicao').show();
  },

  /** Prévia local do valor (o cálculo oficial é feito no servidor pela categoria do usuário). */
  previewKm() {
    const form = document.getElementById('formKm');
    const ini = parseFloat(form.querySelector('[name=km_inicial]').value);
    const fim = parseFloat(form.querySelector('[name=km_final]').value);
    const alvo = document.getElementById('kmPreview');
    if (isNaN(ini) || isNaN(fim) || fim <= ini) {
      alvo.textContent = 'Informe KM inicial e final válidos (final maior que inicial).';
      return;
    }
    alvo.innerHTML = `Distância: <strong>${(fim - ini).toFixed(1)} km</strong>. O valor será calculado pela sua categoria de reembolso ao salvar.`;
  },

  async salvarKm(ev) {
    ev.preventDefault();
    try {
      const r = await App.enviarForm(ev.target, 'index.php?r=despesas/salvar-km');
      bootstrap.Modal.getInstance('#modalKm').hide();
      App.alerta('Quilometragem lançada. Valor: ' + App.moeda(r.valor));
      if (r.aviso) App.alerta(r.aviso, 'warning');
      setTimeout(() => location.reload(), 700);
    } catch (e) { App.alerta(e.message, 'danger'); }
    return false;
  },

  async salvarRefeicao(ev) {
    ev.preventDefault();
    try {
      const r = await App.enviarForm(ev.target, 'index.php?r=despesas/salvar-refeicao');
      bootstrap.Modal.getInstance('#modalRefeicao').hide();
      App.alerta('Refeição lançada.');
      if (r.aviso) App.alerta(r.aviso, 'warning');
      setTimeout(() => location.reload(), 700);
    } catch (e) { App.alerta(e.message, 'danger'); }
    return false;
  },

  async excluir(tipo, id) {
    if (!confirm('Excluir este lançamento?')) return;
    const rota = tipo === 'km' ? 'despesas/excluir-km' : 'despesas/excluir-refeicao';
    try {
      const fd = new FormData();
      fd.append('id', id);
      await App.json('index.php?r=' + rota, { method: 'POST', body: fd });
      App.alerta('Lançamento excluído.');
      setTimeout(() => location.reload(), 500);
    } catch (e) { App.alerta(e.message, 'danger'); }
  },

  async gerarPrestacao(ano, mes) {
    if (!confirm('Gerar a prestação de contas do mês corrente? Os lançamentos abertos serão consolidados.')) return;
    try {
      const fd = new FormData();
      fd.append('ano', ano);
      fd.append('mes', mes);
      await App.json('index.php?r=despesas/gerar-prestacao', { method: 'POST', body: fd });
      App.alerta('Prestação gerada.');
      setTimeout(() => location.reload(), 700);
    } catch (e) { App.alerta(e.message, 'danger'); }
  },

  async enviarPrestacao(id) {
    if (!confirm('Enviar a prestação para aprovação do gestor?')) return;
    try {
      const fd = new FormData();
      fd.append('id', id);
      await App.json('index.php?r=despesas/enviar-prestacao', { method: 'POST', body: fd });
      App.alerta('Prestação enviada para aprovação.');
      setTimeout(() => location.reload(), 700);
    } catch (e) { App.alerta(e.message, 'danger'); }
  },

  async verPrestacao(id) {
    try {
      const resp = await fetch('index.php?r=despesas/detalhe-prestacao&id=' + id, { headers: { 'X-Requested-With': 'fetch' } });
      document.getElementById('prestacaoCorpo').innerHTML = await resp.text();
      new bootstrap.Modal('#modalPrestacao').show();
    } catch (e) { App.alerta('Não foi possível abrir a prestação.', 'danger'); }
  },

  async avaliar(id) {
    const decisao = confirm('Aprovar esta prestação?\n\nOK = Aprovar · Cancelar = escolher rejeitar') ? 'aprovar' : null;
    let parecer = '';
    let decisaoFinal = decisao;
    if (!decisao) {
      if (!confirm('Deseja REJEITAR esta prestação?')) return;
      decisaoFinal = 'rejeitar';
      parecer = prompt('Motivo da rejeição (parecer):', '') || '';
    }
    try {
      const fd = new FormData();
      fd.append('id', id);
      fd.append('decisao', decisaoFinal);
      fd.append('parecer', parecer);
      await App.json('index.php?r=despesas/avaliar-prestacao', { method: 'POST', body: fd });
      App.alerta(decisaoFinal === 'aprovar' ? 'Prestação aprovada.' : 'Prestação rejeitada.');
      setTimeout(() => location.reload(), 700);
    } catch (e) { App.alerta(e.message, 'danger'); }
  },
};
