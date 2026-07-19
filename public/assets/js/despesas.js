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
    // Reinicia a seleção ao alternar produtor/prospecto (só um vale)
    const form = document.getElementById('formKm');
    form.querySelector('[name=cliente_produtor]').value = '0';
    form.querySelector('[name=cliente_prospecto]').value = '0';
    Despesas.setCliente('0');
  },

  /** Sincroniza o produtor/prospecto escolhido no campo cliente_id enviado. */
  setCliente(valor) {
    document.getElementById('formKm').querySelector('[name=cliente_id]').value = valor || '0';
  },

  novoProspecto() {
    const form = document.getElementById('formProspecto');
    form.reset();
    new bootstrap.Modal('#modalProspecto').show();
  },

  async salvarProspecto(ev) {
    ev.preventDefault();
    try {
      const r = await App.enviarForm(ev.target, 'index.php?r=clientes/pre-cadastro');
      const sel = document.querySelector('#formKm [name=cliente_prospecto]');
      sel.add(new Option(r.nome, r.id, true, true));
      Despesas.setCliente(r.id);
      bootstrap.Modal.getInstance('#modalProspecto').hide();
      App.alerta('Prospecto pré-cadastrado. Complete os dados depois em Clientes.');
    } catch (e) { App.alerta(e.message, 'danger'); }
    return false;
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
    const agora = new Date();
    // datetime-local no fuso local (evita o deslocamento do toISOString)
    const local = new Date(agora.getTime() - agora.getTimezoneOffset() * 60000);
    form.querySelector('[name=datahora]').value = local.toISOString().slice(0, 16);
    Despesas.limparComprovante();
    Despesas.previewRefeicao();
    new bootstrap.Modal('#modalRefeicao').show();
  },

  tipoRefeicao() { Despesas.previewRefeicao(); },

  /** Mostra a prévia da foto/PDF do comprovante escolhido. */
  previewComprovante(input) {
    const arq = input.files && input.files[0];
    const vazio = document.getElementById('refDropVazio');
    const previa = document.getElementById('refDropPreview');
    const img = document.getElementById('refDropImg');
    const pdf = document.getElementById('refDropPdf');
    if (!arq) { Despesas.limparComprovante(); return; }
    vazio.classList.add('d-none');
    previa.classList.remove('d-none');
    if (arq.type === 'application/pdf') {
      img.classList.add('d-none');
      pdf.classList.remove('d-none');
      document.getElementById('refDropNome').textContent = arq.name;
    } else {
      pdf.classList.add('d-none');
      img.classList.remove('d-none');
      img.src = URL.createObjectURL(arq);
    }
  },

  limparComprovante(ev) {
    if (ev) ev.stopPropagation();
    const input = document.getElementById('refComprovante');
    if (input) input.value = '';
    document.getElementById('refDropVazio')?.classList.remove('d-none');
    document.getElementById('refDropPreview')?.classList.add('d-none');
  },

  /** Mostra quanto a Copérdia vai reembolsar para o tipo/valor informado. */
  previewRefeicao() {
    const form = document.getElementById('formRefeicao');
    const alvo = document.getElementById('refeicaoPreview');
    const valores = JSON.parse(form.dataset.valores || '{}');
    const tipo = (form.querySelector('[name=tipo]:checked') || {}).value;
    const gasto = parseFloat(form.querySelector('[name=valor]').value);
    if (!tipo) { alvo.textContent = 'Selecione o tipo da refeição.'; return; }
    const teto = valores[tipo];
    if (teto === undefined) {
      alvo.innerHTML = `Sua categoria não tem valor definido para <strong>${tipo}</strong> — reembolso integral do gasto.`;
      return;
    }
    if (isNaN(gasto)) {
      alvo.innerHTML = `Reembolso de <strong>${tipo}</strong> na sua categoria: até <strong>${App.moeda(teto)}</strong>.`;
      return;
    }
    const reembolso = Math.min(gasto, teto);
    if (gasto > teto) {
      alvo.className = 'alert alert-warning border mb-0 py-2 small';
      alvo.innerHTML = `Gasto ${App.moeda(gasto)} acima do teto de ${tipo} (${App.moeda(teto)}). A Copérdia reembolsa <strong>${App.moeda(reembolso)}</strong>.`;
    } else {
      alvo.className = 'alert alert-light border mb-0 py-2 small';
      alvo.innerHTML = `A Copérdia reembolsa <strong>${App.moeda(reembolso)}</strong> (dentro do teto de ${App.moeda(teto)}).`;
    }
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
      if (r.vinculada_visita) App.alerta('Deslocamento amarrado automaticamente à visita do dia.', 'info');
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
      App.alerta('Refeição lançada. Reembolso: ' + App.moeda(r.valor_reembolso));
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
