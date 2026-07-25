<?php /** Mapa Territorial (PR 4) — imóveis do CAR como polígonos sobre satélite. */ ?>
<div class="d-flex flex-wrap align-items-end gap-2 mb-2">
  <div>
    <label class="form-label small mb-1">Município</label>
    <select id="terMun" class="form-select form-select-sm" style="min-width:210px">
      <?php foreach ($municipios as $m): ?>
        <option value="<?= e($m['municipio']) ?>" data-uf="<?= e($m['uf']) ?>"><?= e($m['municipio']) ?>/<?= e($m['uf']) ?></option>
      <?php endforeach; ?>
      <?php if (!$municipios): ?><option value="">— gere o mapa de um município na Integração —</option><?php endif; ?>
    </select>
  </div>
  <div>
    <label class="form-label small mb-1">Safra</label>
    <select id="terSafra" class="form-select form-select-sm">
      <?php foreach ($safras as $s): ?><option><?= e($s) ?></option><?php endforeach; ?>
    </select>
  </div>
  <button class="btn btn-success btn-sm" onclick="Territorio.carregar()"><i class="bi bi-arrow-repeat me-1"></i>Carregar</button>
  <span id="terContagem" class="small text-muted"></span>
  <span class="badge text-bg-warning ms-auto" title="No piloto o score (potencial/realizado/share/gap) é sintético; o Qlik entra na integração real">
    <i class="bi bi-flask me-1"></i>Score de demonstração
  </span>
</div>

<style>
  .ter-poly { transition: fill .45s ease-in-out, fill-opacity .45s ease-in-out; }
  .ter-ramp { display:inline-flex; height:10px; width:120px; border-radius:2px; overflow:hidden; vertical-align:middle; margin-right:6px; border:1px solid rgba(0,0,0,.15); }
  .ter-ramp i { flex:1; }
  .ter-ficha { position:absolute; top:0; right:0; width:min(340px,92%); height:100%; overflow-y:auto; background:#fff; border-left:1px solid rgba(0,0,0,.1); box-shadow:-6px 0 18px rgba(0,0,0,.12); z-index:5; }
  .ter-carcode { font-family:var(--bs-font-monospace,monospace); font-size:10px; word-break:break-all; line-height:1.5; background:rgba(212,166,74,.08); border:1px solid rgba(212,166,74,.25); border-radius:3px; padding:5px 7px; color:#8a6d2e; }
  @media (prefers-reduced-motion: reduce) { .ter-poly { transition: none; } }
</style>

<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
  <div class="btn-group btn-group-sm" role="group" aria-label="Camada temática">
    <button type="button" class="btn btn-outline-success active" data-camada="cobertura" onclick="Territorio.trocarCamada('cobertura', this)"><i class="bi bi-people me-1"></i>Cobertura</button>
    <button type="button" class="btn btn-outline-success" data-camada="share" onclick="Territorio.trocarCamada('share', this)">Share of wallet</button>
    <button type="button" class="btn btn-outline-success" data-camada="gap" onclick="Territorio.trocarCamada('gap', this)">Gap em R$</button>
    <button type="button" class="btn btn-outline-success" data-camada="cultura" onclick="Territorio.trocarCamada('cultura', this)">Cultura</button>
  </div>
  <span id="terInfo" class="small ms-auto"></span>
</div>

<div id="terWrap" style="position:relative">
  <div id="terPalco" class="croqui-palco" style="height:66vh;min-height:400px"></div>
  <div id="terFicha" class="ter-ficha d-none"></div>
</div>

<div id="terLegenda" class="d-flex flex-wrap align-items-center mt-2 small"></div>

<script>
window.__terTiles = <?= json_encode($tiles, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const Territorio = {
  tiles: null, vista: null, feats: [], sel: null, camada: 'cobertura', _maxGap: 0,
  _pan: null, _pinch: null, _ponteiros: new Map(), _eventosOk: false, _alvo: null,

  /* Escalas de cor (valores do protótipo docs/prototipos/mapa_territorial.html) */
  CORES_COB: { ativo: '#4FA87C', inativo: '#D4A64A' },
  RAMP_SHARE: ['#17332B', '#20553F', '#2C7A59', '#41986F', '#68BF93'],
  RAMP_GAP: ['#2C2418', '#6B4A1D', '#A2681E', '#CE8226', '#C7452A'],
  CULT_CORES: { 'Milho': '#D4A64A', 'Soja': '#4FA87C', 'Pastagem / Leite': '#7E9B5A', 'Integração aves': '#8C7BA6', 'Integração suínos': '#8C7BA6', 'Trigo': '#C7862A' },
  _PALETA: ['#4FA87C', '#D4A64A', '#7E9B5A', '#8C7BA6', '#5B8CA8', '#C7452A', '#9a7d0a', '#00695c'],

  /* --- Web Mercator (mesma projeção dos tiles) — coords GeoJSON [lng,lat] --- */
  _wx(lng) { return (Number(lng) + 180) / 360; },
  _wy(lat) { const s = Math.sin(Number(lat) * Math.PI / 180); return 0.5 - Math.log((1 + s) / (1 - s)) / (4 * Math.PI); },
  _escala() { return 256 * Math.pow(2, this.vista.z); },
  _tela(c, larg, alt) { const e = this._escala(); return [(this._wx(c[0]) - this.vista.cx) * e + larg / 2, (this._wy(c[1]) - this.vista.cy) * e + alt / 2]; },

  init() {
    this.tiles = window.__terTiles && window.__terTiles.url ? window.__terTiles : null;
    this._prepararEventos();
    const mun = document.getElementById('terMun');
    if (mun && mun.value) this.carregar();
  },

  async carregar() {
    const mun = document.getElementById('terMun');
    const municipio = mun ? mun.value : '';
    const uf = mun && mun.selectedOptions[0] ? (mun.selectedOptions[0].dataset.uf || '') : '';
    const safra = (document.getElementById('terSafra') || {}).value || '';
    if (!municipio) { App.alerta('Selecione um município.', 'warning'); return; }
    const cont = document.getElementById('terContagem');
    cont.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Carregando…';
    try {
      // Endpoint é GeoJSON puro (sem envelope {ok}): fetch direto, não App.json.
      const qs = new URLSearchParams({ municipio, uf, safra });
      const resp = await fetch('index.php?r=territorio/imoveis&' + qs.toString(), { headers: { 'X-Requested-With': 'fetch' } });
      if (!resp.ok) throw new Error('Falha ao carregar o mapa (HTTP ' + resp.status + ').');
      const fc = await resp.json();
      if (fc && fc.erro) throw new Error(fc.erro);
      this.feats = (fc.features || []).map(f => ({ aneis: this._aneisDe(f.geometry), p: f.properties })).filter(f => f.aneis.length);
      this._maxGap = this.feats.reduce((m, f) => Math.max(m, Number(f.p.gap) || 0), 0); // gap normalizado pelo filtro
      cont.textContent = this.feats.length + ' imóvel(is)' + (fc.truncado ? ' (limite de 5000 — aproxime/filtre)' : '');
      this._fecharFicha();
      this.sel = null;
      this._enquadrar();
      this.render();
    } catch (e) { cont.textContent = ''; App.alerta(e.message, 'danger'); }
  },

  _aneisDe(geom) {
    if (!geom) return [];
    if (geom.type === 'Polygon') return [geom.coordinates[0]];
    if (geom.type === 'MultiPolygon') return geom.coordinates.map(poly => poly[0]);
    return [];
  },

  _enquadrar() {
    const pts = [];
    this.feats.forEach(f => f.aneis.forEach(a => a.forEach(c => pts.push(c))));
    if (!pts.length) { this.vista = { z: 12, cx: this._wx(-52), cy: this._wy(-27) }; return; }
    let minx = 1, maxx = 0, miny = 1, maxy = 0;
    pts.forEach(c => { const x = this._wx(c[0]), y = this._wy(c[1]); if (x < minx) minx = x; if (x > maxx) maxx = x; if (y < miny) miny = y; if (y > maxy) maxy = y; });
    const palco = document.getElementById('terPalco');
    const larg = Math.max(320, palco.clientWidth), alt = Math.max(320, palco.clientHeight);
    const dw = Math.max(1e-7, maxx - minx), dh = Math.max(1e-7, maxy - miny);
    let z = Math.log2(Math.min(larg / (dw * 256), alt / (dh * 256))) - 0.3;
    this.vista = { z: Math.max(3, Math.min(18, z)), cx: (minx + maxx) / 2, cy: (miny + maxy) / 2 };
  },

  /* useEscalaCor: (imóvel, camada) → [fill, fill-opacity]. Mesma geometria, cor muda de sentido. */
  _estilo(p) {
    const c = this.camada;
    if (c === 'cobertura') {
      return p.statusComercial === 'prospect' ? ['url(#terHatch)', '1'] : [this.CORES_COB[p.statusComercial] || '#8899a6', '.5'];
    }
    if (c === 'cultura') {
      return [this._corCultura(p.culturaPrincipal), '.6'];
    }
    if (c === 'share') {
      const idx = Math.min(4, Math.max(0, Math.floor((Number(p.share) || 0) * 5)));
      return [this.RAMP_SHARE[idx], '.62'];
    }
    if (c === 'gap') {
      const r = this._maxGap > 0 ? (Number(p.gap) || 0) / this._maxGap : 0;
      const idx = Math.min(4, Math.max(0, Math.floor(r * 5)));
      return [this.RAMP_GAP[idx], '.62'];
    }
    return ['#8899a6', '.5'];
  },

  _corCultura(cult) {
    if (!cult) return '#6b7a83'; // sem cultura declarada = cinza
    if (this.CULT_CORES[cult]) return this.CULT_CORES[cult];
    let h = 0; for (let i = 0; i < cult.length; i++) h = (h * 31 + cult.charCodeAt(i)) >>> 0; // cor estável por nome
    return this._PALETA[h % this._PALETA.length];
  },

  /* Troca a camada temática SEM refazer a requisição: só recolore o que já está em memória. */
  trocarCamada(c, btn) {
    this.camada = c;
    document.querySelectorAll('[data-camada]').forEach(b => b.classList.toggle('active', b === btn));
    this._recolorir();
  },

  _recolorir() {
    const svg = document.getElementById('terSvg');
    if (!svg) { this.render(); return; }
    this.feats.forEach((f, i) => {
      const [fill, op] = this._estilo(f.p);
      svg.querySelectorAll('.ter-poly[data-i="' + i + '"]').forEach(el => {
        el.setAttribute('fill', fill);
        el.setAttribute('fill-opacity', op);
      });
    });
    this._legenda();
  },

  _legenda() {
    const el = document.getElementById('terLegenda'); if (!el) return;
    const c = this.camada;
    const sw = (cor, txt, ex) => `<span class="me-3"><span class="croqui-cor" style="background:${cor}"></span>${App.escapeHtml(txt)}${ex ? ` <span class="text-muted">${App.escapeHtml(ex)}</span>` : ''}</span>`;
    if (c === 'cobertura') {
      el.innerHTML = sw('#4FA87C', 'Cliente ativo') + sw('#D4A64A', 'Inativo')
        + '<span class="me-3"><span class="croqui-cor" style="background:repeating-linear-gradient(45deg,#0f1c25,#0f1c25 3px,#c3ced6 3px,#c3ced6 5px)"></span>Prospect (só CAR)</span>';
    } else if (c === 'cultura') {
      const pres = [...new Set(this.feats.map(f => f.p.culturaPrincipal).filter(Boolean))].sort();
      el.innerHTML = (pres.length ? pres.map(cu => sw(this._corCultura(cu), cu)).join('') : '<span class="text-muted">sem culturas nesta safra</span>')
        + (this.feats.some(f => !f.p.culturaPrincipal) ? sw('#6b7a83', 'Sem cultura') : '');
    } else {
      const ramp = c === 'share' ? this.RAMP_SHARE : this.RAMP_GAP;
      const bar = `<span class="ter-ramp">${ramp.map(cor => `<i style="background:${cor}"></i>`).join('')}</span>`;
      const min = c === 'share' ? '0%' : 'R$ 0';
      const max = c === 'share' ? '100%' : ('R$ ' + Number(this._maxGap || 0).toLocaleString('pt-BR', { maximumFractionDigits: 0 }));
      const nota = c === 'share' ? 'mais claro = maior fatia da carteira já nossa' : 'mais quente = mais receita disponível não capturada (white space)';
      el.innerHTML = `${bar}<span class="text-muted me-3">${min} → ${max}</span><span class="text-muted">${nota}</span>`;
    }
  },

  render() {
    const palco = document.getElementById('terPalco'); if (!palco) return;
    if (!this.vista) this._enquadrar();
    const larg = Math.max(320, palco.clientWidth), alt = Math.max(320, palco.clientHeight);
    let tilesHtml = '', labelsHtml = '';
    if (this.tiles && this.tiles.url && navigator.onLine) {
      tilesHtml = this._tilesHtml(this.tiles.url, larg, alt);
      if (this.tiles.labels) labelsHtml = this._tilesHtml(this.tiles.labels, larg, alt);
    }
    let svg = '<defs><pattern id="terHatch" width="7" height="7" patternTransform="rotate(45)" patternUnits="userSpaceOnUse">'
      + '<rect width="7" height="7" fill="#0f1c25" fill-opacity=".35"/><line x1="0" y1="0" x2="0" y2="7" stroke="#c3ced6" stroke-width="1.4"/></pattern></defs>';
    this.feats.forEach((f, i) => {
      const [fill, op] = this._estilo(f.p); // cor conforme a camada temática ativa
      const sel = (this.sel === i);
      f.aneis.forEach(anel => {
        if (anel.length < 3) return;
        const tela = anel.map(c => this._tela(c, larg, alt));
        if (tela.every(p => p[0] < -40 || p[0] > larg + 40 || p[1] < -40 || p[1] > alt + 40)) return; // fora da tela
        const pts = tela.map(p => p[0].toFixed(1) + ',' + p[1].toFixed(1)).join(' ');
        svg += `<polygon points="${pts}" data-i="${i}" class="ter-poly" fill="${fill}" fill-opacity="${op}"
                  stroke="${sel ? '#fff' : '#0A1217'}" stroke-width="${sel ? 2.6 : 1}" style="cursor:pointer"/>`;
      });
    });
    palco.innerHTML = `
      <div class="croqui-tiles">${tilesHtml}</div>
      ${labelsHtml ? `<div class="croqui-tiles croqui-labels">${labelsHtml}</div>` : ''}
      <svg id="terSvg" viewBox="0 0 ${larg} ${alt}" width="${larg}" height="${alt}"></svg>
      <div class="croqui-zoom">
        <button type="button" class="btn btn-light btn-sm" onclick="Territorio.zoom(1)" title="Aproximar"><i class="bi bi-plus-lg"></i></button>
        <button type="button" class="btn btn-light btn-sm" onclick="Territorio.zoom(-1)" title="Afastar"><i class="bi bi-dash-lg"></i></button>
      </div>
      ${this.tiles && navigator.onLine ? `<div class="croqui-atribuicao">${App.escapeHtml(this.tiles.atribuicao || '')}</div>` : ''}`;
    palco.querySelector('#terSvg').innerHTML = svg;
    this._info();
    this._legenda();
  },

  _tilesHtml(url, larg, alt) {
    const e = this._escala();
    const zTile = Math.max(3, Math.min(19, Math.round(this.vista.z)));
    const ts = 256 * Math.pow(2, this.vista.z - zTile);
    const n = Math.pow(2, zTile);
    const px0 = this.vista.cx * e - larg / 2, py0 = this.vista.cy * e - alt / 2;
    const tx0 = Math.floor(px0 / ts), tx1 = Math.floor((px0 + larg) / ts);
    const ty0 = Math.max(0, Math.floor(py0 / ts)), ty1 = Math.min(n - 1, Math.floor((py0 + alt) / ts));
    let html = '';
    for (let tx = tx0; tx <= tx1; tx++) for (let ty = ty0; ty <= ty1; ty++) {
      const txn = ((tx % n) + n) % n;
      const u = url.replace('{z}', zTile).replace('{x}', txn).replace('{y}', ty);
      html += `<img src="${App.escapeHtml(u)}" class="croqui-tile" loading="lazy" alt=""
        style="left:${(tx * ts - px0).toFixed(1)}px;top:${(ty * ts - py0).toFixed(1)}px;width:${ts.toFixed(2)}px;height:${ts.toFixed(2)}px" onerror="this.remove()">`;
    }
    return html;
  },

  zoom(d) { if (!this.vista) return; this.vista.z = Math.max(3, Math.min(19, Math.round(this.vista.z) + d)); this.render(); },

  _selecionar(i) {
    if (i === null || i === this.sel) { this.sel = null; this._fecharFicha(); this.render(); return; }
    this.sel = i; this.render();
    this._abrirFicha(this.feats[i].p); // painel lateral com a ficha completa
  },

  async _abrirFicha(p) {
    const box = document.getElementById('terFicha');
    box.classList.remove('d-none');
    box.innerHTML = '<div class="p-3 text-muted"><span class="spinner-border spinner-border-sm me-1"></span>Carregando ficha…</div>';
    try {
      const safra = (document.getElementById('terSafra') || {}).value || '';
      const resp = await fetch('index.php?r=territorio/imovel&' + new URLSearchParams({ cod: p.codCar, safra }).toString(), { headers: { 'X-Requested-With': 'fetch' } });
      if (!resp.ok) throw new Error('Falha ao carregar a ficha (HTTP ' + resp.status + ').');
      const d = await resp.json();
      if (d.erro) throw new Error(d.erro);
      box.innerHTML = this._fichaHtml(d);
    } catch (e) {
      box.innerHTML = '<div class="p-3"><button type="button" class="btn-close float-end" onclick="Territorio._fecharFicha()"></button>'
        + '<div class="text-danger small">' + App.escapeHtml(e.message) + '</div></div>';
    }
  },

  _fecharFicha() {
    const box = document.getElementById('terFicha');
    if (box) { box.classList.add('d-none'); box.innerHTML = ''; }
    if (this.sel !== null) { this.sel = null; this.render(); }
  },

  _fichaHtml(d) {
    const esc = App.escapeHtml;
    const brl = v => 'R$ ' + Number(v || 0).toLocaleString('pt-BR', { maximumFractionDigits: 0 });
    const chip = { ativo: ['#e6f0e9', '#2f6b45', 'Cliente ativo'], inativo: ['#fbf3e2', '#8a6d2e', 'Inativo'], prospect: ['#eef1f3', '#5f6b73', 'Prospect'] }[d.statusComercial] || ['#eee', '#555', d.statusComercial];
    const prods = (d.produtores || []).map(p => {
      const tel = (p.telefone || '').replace(/\D/g, '');
      return '<div class="d-flex justify-content-between align-items-start border-bottom py-1">'
        + '<span class="small">' + esc(p.nome) + (p.principal ? ' <span class="badge text-bg-success">principal</span>' : '')
        + '<br><span class="text-muted">' + esc(p.papel) + ' · ' + esc(p.origem) + ' · confiança ' + esc(p.confianca) + '</span></span>'
        + (tel ? '<a class="btn btn-sm btn-outline-success ms-1" href="https://wa.me/55' + esc(tel) + '" target="_blank" rel="noopener"><i class="bi bi-whatsapp"></i></a>' : '')
        + '</div>';
    }).join('') || '<div class="text-muted small">Sem produtor vinculado (prospect). Vincule por GPS na visita.</div>';
    const tal = (d.talhoes || []).map(t => '<div class="d-flex justify-content-between py-1 border-bottom small">'
      + '<span>' + esc(t.nomeTalhao) + ' <span class="text-muted">· ' + esc(t.cultura) + '</span></span>'
      + '<span>' + Number(t.areaPlantada).toLocaleString('pt-BR') + ' ha' + (t.produtividade ? ' · ' + Number(t.produtividade).toLocaleString('pt-BR') + ' sc/ha' : '') + '</span></div>'
    ).join('') || '<div class="text-muted small">Sem talhões declarados nesta safra.</div>';
    const vis = (d.visitas || []).map(v => '<div class="py-1 border-bottom small">'
      + '<span class="text-muted">' + esc(v.data) + '</span> · ' + esc(v.tecnico || '—') + (v.finalizada ? '' : ' <span class="badge text-bg-warning">incompleta</span>')
      + '<br><span>' + esc(v.objetivo || v.estagio || '—') + '</span></div>'
    ).join('') || '<div class="text-muted small">Sem visitas registradas.</div>';
    return '<div class="p-3">'
      + '<button type="button" class="btn-close float-end" aria-label="Fechar" onclick="Territorio._fecharFicha()"></button>'
      + '<div class="small text-muted">Imóvel (CAR)</div>'
      + '<div class="fw-semibold" style="font-size:15px">' + esc(d.nomeImovel || d.codCar) + '</div>'
      + '<div class="text-muted small">' + esc(d.municipio) + '/' + esc(d.uf) + ' · ' + Number(d.areaHa).toLocaleString('pt-BR') + ' ha'
      + (d.situacaoCar ? ' · CAR ' + esc(d.situacaoCar) : '') + '</div>'
      + '<div class="mt-1"><span class="badge" style="background:' + chip[0] + ';color:' + chip[1] + '">' + esc(chip[2]) + '</span>'
      + (d.culturaPrincipal ? ' <span class="badge text-bg-light border">' + esc(d.culturaPrincipal) + '</span>' : '') + '</div>'
      + '<div class="ter-carcode mt-2">' + esc(d.codCar) + '</div>'
      + '<div class="mt-3">'
      + '<div class="d-flex justify-content-between"><span class="text-muted small">Potencial</span><strong>' + brl(d.potencial) + '</strong></div>'
      + '<div class="d-flex justify-content-between"><span class="text-muted small">Realizado Copérdia</span><strong class="text-success">' + brl(d.realizado) + '</strong></div>'
      + '<div class="progress my-1" style="height:6px"><div class="progress-bar bg-success" style="width:' + (Number(d.share) * 100).toFixed(1) + '%"></div></div>'
      + '<div class="text-muted small">Share of wallet — ' + (Number(d.share) * 100).toFixed(0) + '%</div>'
      + '<div class="d-flex justify-content-between border-top mt-1 pt-1"><span>Gap a capturar</span><strong style="color:#c7452a">' + brl(d.gap) + '</strong></div>'
      + '<div class="text-warning small mt-1"><i class="bi bi-flask me-1"></i>Score de demonstração</div></div>'
      + '<div class="mt-3"><div class="fw-semibold small mb-1">Produtores vinculados</div>' + prods + '</div>'
      + '<div class="mt-3"><div class="fw-semibold small mb-1">Talhões declarados</div>' + tal + '</div>'
      + '<div class="mt-3"><div class="fw-semibold small mb-1">Últimas visitas</div>' + vis + '</div>'
      + (d.rtv ? '<div class="mt-3 small text-muted">RTV responsável: <strong>' + esc(d.rtv) + '</strong></div>' : '')
      + '</div>';
  },

  _info() {
    const box = document.getElementById('terInfo');
    if (this.sel === null || !this.feats[this.sel]) { box.innerHTML = ''; return; }
    const p = this.feats[this.sel].p;
    const brl = v => 'R$ ' + Number(v || 0).toLocaleString('pt-BR', { maximumFractionDigits: 0 });
    box.innerHTML = `<strong>${App.escapeHtml(p.nomeImovel || p.codCar)}</strong> · ${App.escapeHtml(p.produtorPrincipal || 'sem produtor')}`
      + ` · ${Number(p.areaHa).toLocaleString('pt-BR')} ha · ${App.escapeHtml(p.culturaPrincipal || '—')}`
      + ` · <span class="text-muted">pot ${brl(p.potencial)} · realizado ${brl(p.realizado)} · gap ${brl(p.gap)}</span>`;
  },

  _prepararEventos() {
    if (this._eventosOk) return; this._eventosOk = true;
    const palco = document.getElementById('terPalco');
    palco.addEventListener('pointerdown', ev => {
      if (ev.target.closest('.croqui-zoom')) return;
      this._alvo = ev.target.closest('.ter-poly'); // lido ANTES do capture (o up vai p/ o palco)
      try { palco.setPointerCapture(ev.pointerId); } catch (e) {}
      this._ponteiros.set(ev.pointerId, { x: ev.clientX, y: ev.clientY });
      if (this._ponteiros.size === 2 && this.vista) {
        this._pan = null;
        const [a, b] = [...this._ponteiros.values()]; const r = palco.getBoundingClientRect(); const e = this._escala();
        const mx = (a.x + b.x) / 2 - r.left, my = (a.y + b.y) / 2 - r.top;
        this._pinch = { d0: Math.max(1, Math.hypot(a.x - b.x, a.y - b.y)), z0: this.vista.z, wx: this.vista.cx + (mx - r.width / 2) / e, wy: this.vista.cy + (my - r.height / 2) / e };
        ev.preventDefault(); return;
      }
      if (!ev.isPrimary || !this.vista) return;
      this._pan = { x: ev.clientX, y: ev.clientY, cx0: this.vista.cx, cy0: this.vista.cy, moved: false };
      ev.preventDefault();
    });
    palco.addEventListener('pointermove', ev => {
      if (this._ponteiros.has(ev.pointerId)) this._ponteiros.set(ev.pointerId, { x: ev.clientX, y: ev.clientY });
      if (this._pinch && this._ponteiros.size >= 2 && this.vista) {
        const [a, b] = [...this._ponteiros.values()]; const r = palco.getBoundingClientRect();
        const d = Math.max(1, Math.hypot(a.x - b.x, a.y - b.y));
        this.vista.z = Math.max(3, Math.min(19, this._pinch.z0 + Math.log2(d / this._pinch.d0)));
        const e2 = this._escala(); const mx = (a.x + b.x) / 2 - r.left, my = (a.y + b.y) / 2 - r.top;
        this.vista.cx = this._pinch.wx - (mx - r.width / 2) / e2; this.vista.cy = this._pinch.wy - (my - r.height / 2) / e2;
        this.render(); ev.preventDefault(); return;
      }
      if (!ev.isPrimary) return;
      if (this._pan) {
        const dx = ev.clientX - this._pan.x, dy = ev.clientY - this._pan.y;
        if (Math.hypot(dx, dy) > 6) this._pan.moved = true;
        if (this._pan.moved && this.vista) { const e = this._escala(); this.vista.cx = this._pan.cx0 - dx / e; this.vista.cy = this._pan.cy0 - dy / e; this.render(); }
        ev.preventDefault();
      }
    });
    ['pointerup', 'pointercancel'].forEach(n => palco.addEventListener(n, ev => {
      this._ponteiros.delete(ev.pointerId);
      if (this._pinch) { if (this._ponteiros.size < 2) this._pinch = null; this._alvo = null; return; }
      if (this._pan) {
        const foiClique = ev.type === 'pointerup' && ev.isPrimary && !this._pan.moved;
        this._pan = null;
        if (foiClique && this._alvo) this._selecionar(Number(this._alvo.dataset.i));
        else if (foiClique) this._selecionar(null); // clique no vazio fecha a ficha
      }
      this._alvo = null;
    }));
    palco.addEventListener('wheel', ev => { ev.preventDefault(); this.zoom(ev.deltaY < 0 ? 1 : -1); }, { passive: false });
    window.addEventListener('resize', () => { if (document.getElementById('terPalco')) this.render(); });
  },
};
document.addEventListener('DOMContentLoaded', () => Territorio.init());
</script>
