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
  /* Barra de cobertura (spec §10 — elemento central do módulo) */
  #cardCustoLavoura .cl-covbar { position: relative; display: flex; height: 36px; border-radius: 6px;
    overflow: visible; background: var(--bs-light); margin: 22px 0 10px; }
  #cardCustoLavoura .cl-covbar i { display: block; height: 100%; transition: width .3s; }
  #cardCustoLavoura .cl-cov { background: #2e7d32; border-radius: 6px 0 0 6px; }
  #cardCustoLavoura .cl-risk { background: #c62828; }
  #cardCustoLavoura .cl-free { background: #e9c46a; }
  #cardCustoLavoura .cl-eqline { position: absolute; top: -7px; bottom: -7px; width: 2px; background: #141e17; z-index: 3; transition: left .3s; }
  #cardCustoLavoura .cl-eqline::after { content: "equilíbrio"; position: absolute; top: -15px; left: 50%;
    transform: translateX(-50%); font-size: 10px; letter-spacing: .1em; text-transform: uppercase; white-space: nowrap; color: #141e17; }
  #cardCustoLavoura .cl-leg i { display: inline-block; width: 12px; height: 12px; border-radius: 3px; margin-right: 6px; vertical-align: -1px; }
  #cardCustoLavoura .cl-verdict { margin-top: 14px; padding: 12px 14px; border-radius: 4px; font-size: .9rem; border-left: 3px solid; }
  #cardCustoLavoura .cl-verdict.ok { background: #e8f3ea; border-color: #2e7d32; }
  #cardCustoLavoura .cl-verdict.warn { background: #fbf3e2; border-color: #e9c46a; }
  #cardCustoLavoura .cl-verdict.bad { background: #f8e9e6; border-color: #c62828; }
  #cardCustoLavoura input[type="range"] { width: 100%; height: 44px; }
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

      <!-- 4. Simulação de venda antecipada (SimuladorTravamento + BarraCobertura) -->
      <h6 class="text-success mt-4">4. Simulação de venda antecipada <small class="text-muted fw-normal">quanto travar, e a que preço</small></h6>
      <div class="row g-3">
        <div class="col-12 col-md-6">
          <div class="d-flex justify-content-between align-items-center">
            <span class="small text-muted">Percentual da produção travada</span>
            <strong id="clTravPct">0%</strong>
          </div>
          <input type="range" id="clTrav" min="0" max="100" step="5" value="0"
                 oninput="CustoLavoura.recalc()" aria-label="Percentual da produção travada">
          <div class="small text-muted" id="clTravHint"></div>
        </div>
        <div class="col-12 col-md-6">
          <div class="d-flex justify-content-between align-items-center">
            <span class="small text-muted">Preço travado (R$/sc)</span>
            <strong id="clPtVal">—</strong>
          </div>
          <input type="range" id="clPt" min="1" max="200" step="1" value="100"
                 oninput="CustoLavoura.recalc()" aria-label="Preço travado em reais por saca">
          <div class="small text-muted">Preço que você consegue fechar hoje em contrato a termo ou troca-troca.</div>
        </div>
      </div>

      <div class="mt-3">
        <div class="small text-muted">Sua produção esperada, saca por saca</div>
        <div class="cl-covbar">
          <i class="cl-cov" id="clBarCov" style="width:0"></i>
          <i class="cl-risk" id="clBarRisk" style="width:0"></i>
          <i class="cl-free" id="clBarFree" style="width:0"></i>
          <div class="cl-eqline" id="clEqLine" style="left:0"></div>
        </div>
        <div class="row g-2 small cl-leg">
          <div class="col-12 col-md-4"><i style="background:#2e7d32"></i>Já vendido
            <strong id="clLegCov">—</strong> <span class="text-muted" id="clLegCovP"></span></div>
          <div class="col-12 col-md-4"><i style="background:#e9c46a"></i>Produção livre
            <strong id="clLegFree">—</strong> <span class="text-muted">exposta ao preço da colheita</span></div>
          <div class="col-12 col-md-4"><i style="background:#141e17"></i>Sacas só para pagar a conta
            <strong id="clLegEq">—</strong> <span class="text-muted" id="clLegEqP"></span></div>
        </div>
        <div class="cl-verdict d-none" id="clVerdict"></div>
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
  sc(v) { return (v === null || isNaN(v)) ? '—' : Math.round(v).toLocaleString('pt-BR') + ' sc'; },
  pc(v) { return (v * 100).toFixed(0) + '%'; },

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

    // Simulador (seção 4): parte do último cenário salvo; sem cenário, começa
    // NEUTRO (0% travado, preço travado = referência) — a ferramenta descreve,
    // não sugere quanto travar (invariante 6).
    const ref = parseFloat(d.lavoura.precoReferencia) || 1;
    const pt = document.getElementById('clPt');
    pt.min = Math.max(1, Math.round(ref * 0.5));
    pt.max = Math.round(ref * 1.6);
    const cen = d.ultimo_cenario;
    document.getElementById('clTrav').value = cen ? Math.round(parseFloat(cen.pct_travado) * 100) : 0;
    pt.value = cen ? Math.round(parseFloat(cen.preco_travado)) : Math.round(ref);

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
    const pct = (parseInt(document.getElementById('clTrav').value, 10) || 0) / 100;
    const pt = parseFloat(document.getElementById('clPt').value) || 0;
    const r = CustoMotor.calcular(area, prod, preco, t[this.base], pct, pt);
    document.getElementById('clEqPreco').textContent =
      r.preco_equilibrio === null ? '—' : this.brl(r.preco_equilibrio) + '/sc';
    document.getElementById('clEqProd').textContent =
      r.produtividade_equilibrio === null ? '—'
        : r.produtividade_equilibrio.toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + ' sc/ha';
    this.renderSimulador(r, pct, pt);
  },

  /** Seção 4: barra de cobertura + veredito (textos do protótipo — §8). */
  renderSimulador(r, pct, pt) {
    const CONS = CustoMotor.FRACAO_CONSERVADORA; // 0.80
    document.getElementById('clTravPct').textContent = this.pc(pct);
    document.getElementById('clPtVal').textContent = this.brl(pt);

    // barra: coberto (travado), faixa de risco acima do conservador, e livre
    const pctRisco = Math.max(0, pct - CONS);
    document.getElementById('clBarCov').style.width = (Math.min(pct, CONS) * 100) + '%';
    document.getElementById('clBarRisk').style.width = (pctRisco * 100) + '%';
    document.getElementById('clBarFree').style.width = ((1 - pct) * 100) + '%';
    const eqline = document.getElementById('clEqLine');
    if (r.pct_equilibrio === null) {
      eqline.classList.add('d-none');
    } else {
      eqline.classList.remove('d-none');
      eqline.style.left = Math.min(r.pct_equilibrio * 100, 100) + '%';
    }

    document.getElementById('clLegCov').textContent = this.sc(r.sacas_travadas);
    document.getElementById('clLegCovP').textContent =
      r.sacas_travadas > 0 ? this.brl(r.receita_travada) + ' já garantidos' : '';
    document.getElementById('clLegFree').textContent = this.sc(r.sacas_livres);
    document.getElementById('clLegEq').textContent = this.sc(r.sacas_equilibrio);
    document.getElementById('clLegEqP').textContent =
      r.pct_equilibrio === null ? '' : this.pc(r.pct_equilibrio) + ' da produção esperada';

    document.getElementById('clTravHint').textContent = pct > CONS
      ? 'Acima de 80% da produção esperada. Se a safra frustrar, você pode ter que comprar saca no mercado para entregar.'
      : 'Produção conservadora de referência: 80% do esperado (' + this.sc(r.producao_conservadora) + ').';

    // veredito (3 classes do protótipo; só números computados no innerHTML)
    const v = document.getElementById('clVerdict');
    const baseUp = this.base.toUpperCase();
    const cob = r.cobertura_custo === null ? 0 : r.cobertura_custo;
    if (pct === 0 || r.preco_equilibrio === null) {
      v.className = 'cl-verdict d-none';
      v.innerHTML = '';
    } else if (pt < r.preco_equilibrio) {
      v.className = 'cl-verdict bad';
      v.innerHTML = 'O preço de <b>' + this.brl(pt) + '</b> por saca está <b>abaixo</b> do seu ponto de equilíbrio de <b>'
        + this.brl(r.preco_equilibrio) + '</b>. Cada saca travada neste preço entra no prejuízo pela base ' + baseUp + '.';
    } else if (pct > CONS) {
      v.className = 'cl-verdict warn';
      v.innerHTML = 'Você travou <b>' + this.pc(pct) + '</b> da produção esperada — acima da margem de segurança. Cobre <b>'
        + this.pc(cob) + '</b> do custo, mas assume risco de entrega se a lavoura frustrar.';
    } else {
      v.className = 'cl-verdict ok';
      v.innerHTML = 'Ao preço de <b>' + this.brl(pt) + '</b>, você precisa entregar <b>' + this.sc(r.sacas_equilibrio)
        + '</b> — <b>' + (r.pct_equilibrio === null ? '—' : this.pc(r.pct_equilibrio)) + '</b> da sua produção esperada — só para cobrir o custo '
        + baseUp + '. Com <b>' + this.pc(pct) + '</b> travado, <b>' + this.pc(cob)
        + '</b> do custo já está garantido. O que passar disso é seu.';
    }
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
