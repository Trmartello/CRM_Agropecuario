<?php
/**
 * Portal do Produtor — Custo da lavoura e ponto de equilíbrio (PR 5).
 * Spec docs/specs/custo-lavoura.md §10: SetupLavoura + TabelaCusto +
 * PontoEquilibrio, adaptados ao Bootstrap/vanilla do repo. Textos das
 * explicações/avisos vêm do protótipo (docs/prototipos/custo_lavoura.html) —
 * não reescrever sem revisão (§8).
 *
 * O recálculo é EM TEMPO REAL no navegador (custo-motor.js, cópia espelhada do
 * CustoMotorService — §11.2: mudar um valor atualiza o equilíbrio sem
 * requisição); o servidor recalcula ao salvar e a tela re-renderiza com o
 * número do servidor (mesmas fórmulas, mesmo resultado).
 */
?>
<style>
  /* Alvo de toque ≥44px e números grandes (spec §10) */
  #cardCustoLavoura .cl-toque { min-height: 44px; }
  #cardCustoLavoura .cl-big { font-size: 2rem; font-weight: 700; line-height: 1.1; }
  #cardCustoLavoura .cl-grupo td { background: var(--bs-light); font-weight: 600; font-size: .85rem; }
  #cardCustoLavoura .cl-sub td { border-top: 2px solid var(--bs-gray-400); font-weight: 700; }
  #cardCustoLavoura input.cl-valor { max-width: 8.5rem; text-align: right; min-height: 44px; }
  #cardCustoLavoura .cl-chip[aria-pressed="true"] { background: var(--bs-success); color: #fff; }
</style>

<div class="card mt-3" id="cardCustoLavoura">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <span><i class="bi bi-calculator me-2 text-success"></i><strong>Custo da lavoura e ponto de equilíbrio</strong></span>
    <button type="button" class="btn btn-success cl-toque" onclick="CustoLavoura.novaLavoura()">
      <i class="bi bi-plus-lg me-1"></i>Nova lavoura
    </button>
  </div>
  <div class="card-body">
    <div id="clLista" class="d-flex flex-wrap gap-2 mb-3"></div>
    <div id="clVazio" class="text-muted d-none">
      Monte o custo da sua lavoura e descubra <strong>quantas sacas só pagam a conta — e quantas
      sobram para você</strong>. Toque em “Nova lavoura” para começar.
    </div>

    <div id="clDetalhe" class="d-none">
      <!-- 1. A sua lavoura (Setup) -->
      <h6 class="text-success mt-2">1. A sua lavoura</h6>
      <div class="row g-2 align-items-end">
        <div class="col-6 col-md-3">
          <label class="form-label small mb-1" for="clArea">Área (ha)</label>
          <input type="number" class="form-control cl-toque" id="clArea" min="0.1" step="any"
                 oninput="CustoLavoura.recalc()">
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label small mb-1" for="clProd">Produtividade esperada (sc/ha)</label>
          <input type="number" class="form-control cl-toque" id="clProd" min="0.1" step="any"
                 oninput="CustoLavoura.recalc()">
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label small mb-1" for="clPreco">Preço de referência (R$/sc)</label>
          <input type="number" class="form-control cl-toque" id="clPreco" min="0.01" step="any"
                 oninput="CustoLavoura.recalc()">
        </div>
      </div>

      <!-- 2. Formação do custo -->
      <h6 class="text-success mt-4">2. Formação do custo <small class="text-muted fw-normal">valores por hectare</small></h6>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-2" id="clTabela"></table>
      </div>

      <!-- 3. Ponto de equilíbrio -->
      <h6 class="text-success mt-4">3. Ponto de equilíbrio</h6>
      <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
        <span class="small text-muted me-1">Base de custo</span>
        <button type="button" class="btn btn-outline-success cl-toque cl-chip" data-b="coe" aria-pressed="false" onclick="CustoLavoura.trocarBase('coe')">COE</button>
        <button type="button" class="btn btn-outline-success cl-toque cl-chip" data-b="cot" aria-pressed="false" onclick="CustoLavoura.trocarBase('cot')">COT</button>
        <button type="button" class="btn btn-outline-success cl-toque cl-chip" data-b="ct" aria-pressed="true" onclick="CustoLavoura.trocarBase('ct')">CT</button>
      </div>
      <div class="small text-muted mb-3" id="clBaseHint"></div>
      <div class="row g-3">
        <div class="col-12 col-md-6">
          <div class="border rounded p-3 h-100">
            <div class="small text-muted">Preço de equilíbrio</div>
            <div class="cl-big text-success" id="clEqPreco">—</div>
            <div class="small text-muted mt-1">Abaixo deste preço por saca, a safra fecha no prejuízo com a produtividade que você espera.</div>
          </div>
        </div>
        <div class="col-12 col-md-6">
          <div class="border rounded p-3 h-100">
            <div class="small text-muted">Produtividade de equilíbrio</div>
            <div class="cl-big text-success" id="clEqProd">—</div>
            <div class="small text-muted mt-1">Quanto você precisa colher por hectare, ao preço de referência, para apenas empatar.</div>
          </div>
        </div>
      </div>

      <div class="d-flex flex-wrap gap-2 mt-3">
        <button type="button" class="btn btn-success cl-toque px-4" id="clBtnSalvar" onclick="CustoLavoura.salvar()">
          <i class="bi bi-check-lg me-1"></i>Salvar alterações
        </button>
      </div>
    </div>

    <div class="alert alert-light border small mt-4 mb-0">
      <strong>Esta ferramenta não recomenda comprar nem vender.</strong> Ela mostra os seus próprios números
      frente a referências públicas de mercado, para que a decisão seja sua. Os custos que você digita aqui
      são seus e não são compartilhados com a equipe comercial da Copérdia. Para decisões de contrato futuro
      ou operações em bolsa, procure seu contador ou assessor.
    </div>
  </div>
</div>

<!-- Modal: nova lavoura (SetupLavoura) -->
<div class="modal fade" id="modalNovaLavoura" tabindex="-1">
  <div class="modal-dialog modal-fullscreen-sm-down">
    <form class="modal-content" id="formNovaLavoura" onsubmit="return CustoLavoura.criar(event)">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-calculator me-2 text-success"></i>Nova lavoura</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label" for="nlCultura">Cultura</label>
            <input class="form-control cl-toque" id="nlCultura" name="cultura" list="nlCulturas"
                   required maxlength="40" placeholder="Soja, Milho, Trigo…">
            <datalist id="nlCulturas"><option>Soja</option><option>Milho</option><option>Trigo</option></datalist>
            <div class="form-text">Soja e Milho começam com valores de referência da Copérdia — você ajusta tudo depois.</div>
          </div>
          <div class="col-6">
            <label class="form-label" for="nlSafra">Safra</label>
            <input class="form-control cl-toque" id="nlSafra" name="safra" required
                   pattern="\d{4}/\d{2}" placeholder="2025/26">
          </div>
          <div class="col-6">
            <label class="form-label" for="nlArea">Área (ha)</label>
            <input type="number" class="form-control cl-toque" id="nlArea" name="area_ha" required min="0.1" step="any">
          </div>
          <div class="col-6">
            <label class="form-label" for="nlProd">Produtividade esperada (sc/ha)</label>
            <input type="number" class="form-control cl-toque" id="nlProd" name="produtividade_esperada" required min="0.1" step="any">
          </div>
          <div class="col-6">
            <label class="form-label" for="nlPreco">Preço de referência (R$/sc)</label>
            <input type="number" class="form-control cl-toque" id="nlPreco" name="preco_referencia" required min="0.01" step="any">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary cl-toque" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-success cl-toque px-4">Criar lavoura</button>
      </div>
    </form>
  </div>
</div>

<script src="assets/js/custo-motor.js?v=<?= filemtime(dirname(__DIR__, 3) . '/public/assets/js/custo-motor.js') ?>"></script>
<script>
/* Custo da Lavoura — Portal (PR 5). Estado + render; cálculo local = CustoMotor. */
const CustoLavoura = {
  lavouras: [],
  det: null,        // detalhe da lavoura aberta (resposta do servidor)
  base: 'ct',

  /* Textos do protótipo (§8 — não reescrever sem revisão) */
  GRUPOS: {
    coe: 'COE — Custo Operacional Efetivo',
    cot: 'Complemento até o COT',
    ct: 'Complemento até o CT'
  },
  BASEHINT: {
    coe: 'COE — o mínimo para não ficar devendo nesta safra. Não repõe máquina nem remunera a terra.',
    cot: 'COT — cobre o desgaste do que você já tem. É o piso da sustentabilidade no médio prazo.',
    ct: 'CT — inclui o que a terra e o capital renderiam em outro uso. É a régua econômica de verdade.'
  },

  brl(v) { return (v === null || isNaN(v)) ? '—' : v.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); },

  async carregar() {
    if (!document.getElementById('cardCustoLavoura')) return;
    try {
      const d = await App.json('index.php?r=portal/lavouras');
      this.lavouras = d.lavouras || [];
      this.renderLista();
      if (this.lavouras.length === 1) this.abrir(this.lavouras[0].id);
    } catch (e) { /* portal segue utilizável sem o módulo */ }
  },

  renderLista() {
    const el = document.getElementById('clLista');
    document.getElementById('clVazio').classList.toggle('d-none', this.lavouras.length > 0);
    el.innerHTML = this.lavouras.map(l =>
      `<button type="button" class="btn btn-outline-success cl-toque cl-chip" data-lav="${l.id}"
               aria-pressed="${this.det && this.det.lavoura.id === l.id}"
               onclick="CustoLavoura.abrir(${l.id})">
         ${App.escapeHtml(l.cultura)} · ${App.escapeHtml(l.safra)} · ${l.areaHa} ha
       </button>`).join('');
  },

  async abrir(id) {
    try {
      const d = await App.json('index.php?r=portal/lavoura&id=' + id);
      this.det = d;
      this.base = d.lavoura.baseCustoPadrao || 'ct';
      this.renderDetalhe();
      this.renderLista();
    } catch (e) { App.alerta(e.message, 'danger'); }
  },

  renderDetalhe() {
    const d = this.det;
    document.getElementById('clDetalhe').classList.remove('d-none');
    document.getElementById('clArea').value = d.lavoura.areaHa;
    document.getElementById('clProd').value = d.lavoura.produtividadeEsperada;
    document.getElementById('clPreco').value = d.lavoura.precoReferencia;

    // Tabela por grupos com subtotais acumulados (COE / COT / CT)
    const porGrupo = { coe: [], cot: [], ct: [] };
    d.itens.forEach(i => porGrupo[i.grupo] && porGrupo[i.grupo].push(i));
    const linhas = [];
    const subRot = { coe: 'COE', cot: 'COT', ct: 'CT' };
    ['coe', 'cot', 'ct'].forEach(g => {
      linhas.push(`<tr class="cl-grupo"><td colspan="2">${App.escapeHtml(this.GRUPOS[g])}</td></tr>`);
      porGrupo[g].forEach(i => {
        linhas.push(
          `<tr><td>${App.escapeHtml(i.descricao)}</td>
               <td class="text-end"><input type="number" class="form-control form-control-sm cl-valor d-inline-block"
                    min="0" step="any" data-item="${i.catItemId}" value="${i.valorHa === null ? '' : i.valorHa}"
                    oninput="CustoLavoura.recalc()" aria-label="${App.escapeHtml(i.descricao)} (R$/ha)"></td></tr>`);
      });
      linhas.push(`<tr class="cl-sub"><td>${subRot[g]}</td><td class="text-end" id="clSub-${g}">—</td></tr>`);
    });
    document.getElementById('clTabela').innerHTML = linhas.join('');
    this.trocarBase(this.base, true);
  },

  /** Somas acumuladas por base a partir dos INPUTS (estado atual da tela). */
  somas() {
    const t = { coe: 0, cot: 0, ct: 0 };
    document.querySelectorAll('#clTabela input[data-item]').forEach(inp => {
      const v = parseFloat(inp.value) || 0;
      const g = this.det.itens.find(i => i.catItemId === parseInt(inp.dataset.item, 10));
      if (!g) return;
      if (g.grupo === 'coe') { t.coe += v; t.cot += v; t.ct += v; }
      else if (g.grupo === 'cot') { t.cot += v; t.ct += v; }
      else t.ct += v;
    });
    return t;
  },

  /** §11.2: recálculo 100% local (CustoMotor) — nenhuma requisição. */
  recalc() {
    if (!this.det) return;
    const area = parseFloat(document.getElementById('clArea').value) || 0;
    const prod = parseFloat(document.getElementById('clProd').value) || 0;
    const preco = parseFloat(document.getElementById('clPreco').value) || 0;
    const t = this.somas();
    ['coe', 'cot', 'ct'].forEach(g => {
      document.getElementById('clSub-' + g).textContent = this.brl(t[g]);
    });
    const r = CustoMotor.calcular(area, prod, preco, t[this.base]);
    document.getElementById('clEqPreco').textContent =
      r.preco_equilibrio === null ? '—' : this.brl(r.preco_equilibrio) + '/sc';
    document.getElementById('clEqProd').textContent =
      r.produtividade_equilibrio === null ? '—'
        : r.produtividade_equilibrio.toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + ' sc/ha';
  },

  trocarBase(b, semRender) {
    this.base = b;
    document.querySelectorAll('#cardCustoLavoura .cl-chip[data-b]').forEach(x =>
      x.setAttribute('aria-pressed', x.dataset.b === b ? 'true' : 'false'));
    document.getElementById('clBaseHint').textContent = this.BASEHINT[b];
    this.recalc();
  },

  async salvar() {
    if (!this.det) return;
    const btn = document.getElementById('clBtnSalvar');
    btn.disabled = true;
    try {
      const id = this.det.lavoura.id;
      // 1) setup (área/produtividade/preço/base)
      const fd = new FormData();
      fd.append('id', id);
      fd.append('area_ha', document.getElementById('clArea').value);
      fd.append('produtividade_esperada', document.getElementById('clProd').value);
      fd.append('preco_referencia', document.getElementById('clPreco').value);
      fd.append('base_custo_padrao', this.base);
      await App.json('index.php?r=portal/lavoura-atualizar', { method: 'POST', body: fd });
      // 2) itens de custo (todos os inputs; servidor valida e RECALCULA)
      const itens = [];
      document.querySelectorAll('#clTabela input[data-item]').forEach(inp => {
        if (inp.value !== '') itens.push({ catItemId: parseInt(inp.dataset.item, 10), valorHa: parseFloat(inp.value) });
      });
      const fd2 = new FormData();
      fd2.append('id', id);
      fd2.append('itens', JSON.stringify(itens));
      const resp = await App.json('index.php?r=portal/lavoura-custos', { method: 'POST', body: fd2 });
      this.det = resp.detalhe;                 // número do servidor = número da tela
      this.renderDetalhe();
      const i = this.lavouras.findIndex(l => l.id === id);
      if (i >= 0) { this.lavouras[i] = resp.detalhe.lavoura; this.renderLista(); }
      App.alerta('Custos salvos.');
    } catch (e) { App.alerta(e.message, 'danger'); } finally { btn.disabled = false; }
  },

  novaLavoura() {
    const f = document.getElementById('formNovaLavoura');
    f.reset();
    // sugestão de safra pelo calendário agrícola (jul em diante = safra nova)
    const hoje = new Date();
    const a = hoje.getMonth() >= 6 ? hoje.getFullYear() : hoje.getFullYear() - 1;
    document.getElementById('nlSafra').value = a + '/' + String((a + 1) % 100).padStart(2, '0');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalNovaLavoura')).show();
  },

  async criar(ev) {
    ev.preventDefault();
    try {
      const d = await App.json('index.php?r=portal/lavoura-criar',
        { method: 'POST', body: new FormData(document.getElementById('formNovaLavoura')) });
      bootstrap.Modal.getInstance(document.getElementById('modalNovaLavoura')).hide();
      this.lavouras.push(d.detalhe.lavoura);
      this.det = d.detalhe;
      this.base = d.detalhe.lavoura.baseCustoPadrao || 'ct';
      this.renderLista();
      this.renderDetalhe();
    } catch (e) { App.alerta(e.message, 'danger'); }
    return false;
  }
};
document.addEventListener('DOMContentLoaded', () => CustoLavoura.carregar());
</script>
