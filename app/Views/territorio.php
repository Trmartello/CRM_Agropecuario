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

<div id="terPalco" class="croqui-palco" style="height:70vh;min-height:420px"></div>

<div class="d-flex flex-wrap align-items-center gap-3 mt-2 small">
  <span class="text-muted">Cobertura comercial:</span>
  <span><span class="croqui-cor" style="background:#4FA87C"></span>Cliente ativo</span>
  <span><span class="croqui-cor" style="background:#D4A64A"></span>Inativo</span>
  <span><span class="croqui-cor" style="background:#8899a6"></span>Prospect (só CAR)</span>
  <span id="terInfo" class="ms-auto"></span>
</div>

<script>
window.__terTiles = <?= json_encode($tiles, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const Territorio = {
  tiles: null, vista: null, feats: [], sel: null,
  _pan: null, _pinch: null, _ponteiros: new Map(), _eventosOk: false, _alvo: null,

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
      cont.textContent = this.feats.length + ' imóvel(is)' + (fc.truncado ? ' (limite de 5000 — aproxime/filtre)' : '');
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

  cor(status) { return status === 'ativo' ? '#4FA87C' : (status === 'inativo' ? '#D4A64A' : '#8899a6'); },

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
      const status = f.p.statusComercial;
      const fill = status === 'prospect' ? 'url(#terHatch)' : this.cor(status);
      const op = status === 'prospect' ? '1' : '.5';
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

  _selecionar(i) { this.sel = (this.sel === i ? null : i); this.render(); },

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
        else if (foiClique && this.sel !== null) { this.sel = null; this.render(); }
      }
      this._alvo = null;
    }));
    palco.addEventListener('wheel', ev => { ev.preventDefault(); this.zoom(ev.deltaY < 0 ? 1 : -1); }, { passive: false });
    window.addEventListener('resize', () => { if (document.getElementById('terPalco')) this.render(); });
  },
};
document.addEventListener('DOMContentLoaded', () => Territorio.init());
</script>
