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
      <button type="button" class="btn btn-outline-danger d-none" style="min-height:44px" id="pfBtnRevogar"
              onclick="PortalFiscal.revogar()">
        <i class="bi bi-x-circle me-1"></i>Revogar autorização
      </button>
    </div>
    <div class="small text-muted mt-2" id="pfNota"></div>
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
    nota.textContent = ativa
      ? 'Suas notas passam a ser capturadas automaticamente e aparecem aqui para você revisar antes de qualquer valor entrar no custo.'
      : (status === 'revogada' ? 'A captura está parada. Você pode autorizar de novo quando quiser.' : '');
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
