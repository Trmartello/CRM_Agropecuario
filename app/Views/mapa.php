<div class="d-flex flex-wrap align-items-end gap-2 mb-3">
  <div>
    <label class="form-label small mb-1 fw-semibold">Produtor</label>
    <input type="search" id="mapaBusca" class="form-control form-control-sm" style="max-width:200px" placeholder="Buscar produtor…" oninput="Mapa.filtrar()">
  </div>
  <div>
    <label class="form-label small mb-1 fw-semibold">Município</label>
    <select id="mapaMunicipio" class="form-select form-select-sm" onchange="Mapa.filtrar()">
      <option value="">Todos</option>
      <?php foreach ($municipios as $m): ?><option value="<?= e(mb_strtolower($m)) ?>"><?= e($m) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="form-label small mb-1 fw-semibold">Estado</label>
    <select id="mapaEstado" class="form-select form-select-sm" onchange="Mapa.filtrar()">
      <option value="">Todos</option>
      <?php foreach ($estados as $uf): ?><option value="<?= e(mb_strtolower($uf)) ?>"><?= e($uf) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="form-label small mb-1 fw-semibold">Nível tecnológico</label>
    <select id="mapaNivel" class="form-select form-select-sm" onchange="Mapa.filtrar()">
      <option value="">Todos</option>
      <option value="alto">Alto</option>
      <option value="médio">Médio</option>
      <option value="baixo">Baixo</option>
    </select>
  </div>
  <div>
    <label class="form-label small mb-1 fw-semibold">Cultura atual</label>
    <select id="mapaCultura" class="form-select form-select-sm" onchange="Mapa.filtrar()">
      <option value="">Todas</option>
      <?php foreach ($culturasFiltro as $cu): ?><option value="<?= e(mb_strtolower($cu)) ?>"><?= e($cu) ?></option><?php endforeach; ?>
    </select>
  </div>
  <button type="button" class="btn btn-sm btn-outline-secondary" onclick="Mapa.limpar()"><i class="bi bi-eraser me-1"></i>Limpar</button>
  <span id="mapaContador" class="small text-muted ms-auto"></span>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card"><div class="card-body p-2">
      <?php if (!$comGeo): ?>
        <p class="text-muted text-center py-5 mb-0">Nenhum cliente com coordenadas cadastradas. Capture a localização no cadastro/visita.</p>
      <?php else: ?>
        <div id="mapaPalco" class="croqui-palco mapa-palco" style="height:420px"
             data-pontos='<?= json_attr(array_map(fn($c) => [
               'id' => (int)$c['id'], 'nome' => $c['nome'], 'lat' => (float)$c['latitude'], 'lng' => (float)$c['longitude'],
               'nivel' => $c['nivel_tecnologico'], 'prospecto' => (int)$c['prospecto'],
               'municipio' => (string)($c['municipio'] ?? ''), 'estado' => (string)($c['estado'] ?? ''),
               'culturas' => (string)$c['culturas'],
             ], $comGeo)) ?>'
             data-tiles='<?= json_attr($tiles) ?>'></div>
        <div class="d-flex flex-wrap align-items-center gap-3 small text-muted mt-2 px-1">
          <span><span class="croqui-cor" style="background:#1b5e20"></span>Nível Alto</span>
          <span><span class="croqui-cor" style="background:#2e7d32"></span>Médio</span>
          <span><span class="croqui-cor" style="background:#81c784"></span>Baixo</span>
          <span><span class="croqui-cor" style="background:#f9a825"></span>Prospecto</span>
          <span class="ms-auto"><i class="bi bi-info-circle me-1"></i>Toque no ponto para abrir no mapa do aparelho.</span>
        </div>
      <?php endif; ?>
    </div></div>
  </div>
  <div class="col-lg-5">
    <div class="card"><div class="list-group list-group-flush" id="mapaLista" style="max-height:480px;overflow:auto">
      <?php foreach ($clientes as $c): ?>
      <div class="list-group-item d-flex align-items-center gap-2 item-mapa"
           data-nome="<?= e(mb_strtolower($c['nome'])) ?>"
           data-municipio="<?= e(mb_strtolower($c['municipio'] ?? '')) ?>"
           data-estado="<?= e(mb_strtolower($c['estado'] ?? '')) ?>"
           data-nivel="<?= e(mb_strtolower($c['nivel_tecnologico'] ?? '')) ?>"
           data-culturas="<?= e(mb_strtolower($c['culturas'])) ?>">
        <div class="flex-grow-1">
          <div class="fw-semibold"><?= e($c['nome']) ?>
            <?php if ($c['prospecto']): ?><span class="badge text-bg-warning ms-1">Prospecto</span><?php endif; ?></div>
          <div class="small text-muted"><?= e($c['municipio'] ?? '—') ?><?= $c['estado'] ? '/' . e($c['estado']) : '' ?> · Nível <?= e($c['nivel_tecnologico']) ?></div>
          <?php if ($c['culturas']): ?>
            <div class="small"><i class="bi bi-flower3 text-success me-1"></i><?= e($c['culturas']) ?></div>
          <?php endif; ?>
        </div>
        <?php if ($c['latitude'] && $c['longitude']): ?>
        <a class="btn btn-sm btn-outline-success" href="https://www.google.com/maps/search/?api=1&query=<?= $c['latitude'] ?>,<?= $c['longitude'] ?>" target="_blank" title="Abrir no mapa"><i class="bi bi-geo-alt"></i></a>
        <?php else: ?><span class="badge text-bg-light border text-muted">sem GPS</span><?php endif; ?>
      </div>
      <?php endforeach; ?>
      <div class="list-group-item text-center text-muted d-none" id="mapaListaVazia">Nenhum produtor com esses filtros.</div>
    </div></div>
  </div>
</div>

<script>
/**
 * Mapa de clientes sobre satélite — mesma projeção/tiles do croqui.
 * Pan (arrastar), zoom (botões, roda e pinça) e toque no ponto abre o
 * mapa do aparelho. Offline os tiles somem e os pontos continuam.
 */
const Mapa = {
  pontos: [], tiles: null, vista: null,
  _pan: null, _pinch: null, _ponteiros: new Map(), _moveu: false,

  _wx(p) { return (p.lng + 180) / 360; },
  _wy(p) { const s = Math.sin(p.lat * Math.PI / 180); return 0.5 - Math.log((1 + s) / (1 - s)) / (4 * Math.PI); },
  _escala() { return 256 * Math.pow(2, this.vista.z); },

  iniciar() {
    const palco = document.getElementById('mapaPalco');
    if (!palco) { Mapa.filtrar(); return; }
    Mapa.pontos = JSON.parse(palco.dataset.pontos || '[]');
    const tiles = JSON.parse(palco.dataset.tiles || 'null');
    Mapa.tiles = tiles && tiles.url ? tiles : null;
    Mapa._eventos(palco);
    Mapa.filtrar();
    window.addEventListener('resize', () => Mapa.render());
    window.addEventListener('online', () => Mapa.render());
    window.addEventListener('offline', () => Mapa.render());
  },

  _filtros() {
    return {
      busca: (document.getElementById('mapaBusca').value || '').toLowerCase().trim(),
      municipio: document.getElementById('mapaMunicipio').value,
      estado: document.getElementById('mapaEstado').value,
      nivel: document.getElementById('mapaNivel').value,
      cultura: document.getElementById('mapaCultura').value,
    };
  },

  _passa(d, f) {
    if (f.busca && !d.nome.includes(f.busca)) return false;
    if (f.municipio && d.municipio !== f.municipio) return false;
    if (f.estado && d.estado !== f.estado) return false;
    if (f.nivel && d.nivel !== f.nivel) return false;
    if (f.cultura && !d.culturas.split(',').map(s => s.trim()).includes(f.cultura)) return false;
    return true;
  },

  visiveis() {
    const f = Mapa._filtros();
    return Mapa.pontos.filter(p => Mapa._passa({
      nome: p.nome.toLowerCase(), municipio: p.municipio.toLowerCase(), estado: p.estado.toLowerCase(),
      nivel: (p.nivel || '').toLowerCase(), culturas: p.culturas.toLowerCase(),
    }, f));
  },

  filtrar() {
    const f = Mapa._filtros();
    let naLista = 0;
    const itens = document.querySelectorAll('#mapaLista .item-mapa');
    itens.forEach(el => {
      const ok = Mapa._passa(el.dataset, f);
      // d-none (e não style.display): o item tem d-flex, que é !important
      el.classList.toggle('d-none', !ok);
      if (ok) naLista++;
    });
    const vazio = document.getElementById('mapaListaVazia');
    if (vazio) vazio.classList.toggle('d-none', naLista > 0);
    const vis = Mapa.visiveis();
    const cont = document.getElementById('mapaContador');
    if (cont) cont.textContent = `${naLista} de ${itens.length} produtor(es) · ${vis.length} no mapa`;
    Mapa._enquadrar(vis);
    Mapa.render();
  },

  limpar() {
    ['mapaBusca', 'mapaMunicipio', 'mapaEstado', 'mapaNivel', 'mapaCultura'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.value = '';
    });
    Mapa.filtrar();
  },

  _enquadrar(pontos) {
    const palco = document.getElementById('mapaPalco');
    if (!palco || !pontos.length) { Mapa.vista = null; return; }
    const larg = Math.max(300, palco.clientWidth), alt = Math.max(260, palco.clientHeight);
    const xs = pontos.map(p => Mapa._wx(p)), ys = pontos.map(p => Mapa._wy(p));
    const cx = (Math.min(...xs) + Math.max(...xs)) / 2;
    const cy = (Math.min(...ys) + Math.max(...ys)) / 2;
    const spanX = Math.max(1e-9, Math.max(...xs) - Math.min(...xs));
    const spanY = Math.max(1e-9, Math.max(...ys) - Math.min(...ys));
    let z = 14; // um ponto só: zoom de região
    if (pontos.length > 1) {
      z = Math.floor(Math.min(Math.log2(larg * 0.75 / (256 * spanX)), Math.log2(alt * 0.75 / (256 * spanY))));
    }
    Mapa.vista = { z: Math.max(3, Math.min(17, z)), cx, cy };
  },

  zoom(delta) {
    if (!Mapa.vista) return;
    Mapa.vista.z = Math.max(3, Math.min(19, Math.round(Mapa.vista.z) + delta));
    Mapa.render();
  },

  render() {
    const palco = document.getElementById('mapaPalco');
    if (!palco) return;
    const larg = Math.max(300, palco.clientWidth), alt = Math.max(260, palco.clientHeight);
    const vis = Mapa.visiveis();
    if (!Mapa.vista) Mapa._enquadrar(vis);
    if (!Mapa.vista) {
      palco.innerHTML = '<div class="d-flex h-100 align-items-center justify-content-center text-muted p-4">Nenhum produtor com coordenadas nesses filtros.</div>';
      return;
    }
    const e = Mapa._escala();

    // Camada de satélite (some offline; os pontos continuam)
    let tilesHtml = '';
    if (Mapa.tiles && navigator.onLine) {
      const zTile = Math.max(3, Math.min(19, Math.round(Mapa.vista.z)));
      const ts = 256 * Math.pow(2, Mapa.vista.z - zTile);
      const n = Math.pow(2, zTile);
      const px0 = Mapa.vista.cx * e - larg / 2, py0 = Mapa.vista.cy * e - alt / 2;
      const tx0 = Math.floor(px0 / ts), tx1 = Math.floor((px0 + larg) / ts);
      const ty0 = Math.max(0, Math.floor(py0 / ts)), ty1 = Math.min(n - 1, Math.floor((py0 + alt) / ts));
      for (let tx = tx0; tx <= tx1; tx++) {
        for (let ty = ty0; ty <= ty1; ty++) {
          const txn = ((tx % n) + n) % n;
          const url = Mapa.tiles.url.replace('{z}', zTile).replace('{x}', txn).replace('{y}', ty);
          tilesHtml += `<img src="${App.escapeHtml(url)}" class="croqui-tile" loading="lazy" alt=""
            style="left:${(tx * ts - px0).toFixed(1)}px;top:${(ty * ts - py0).toFixed(1)}px;width:${ts.toFixed(2)}px;height:${ts.toFixed(2)}px" onerror="this.remove()">`;
        }
      }
    }

    let svg = '';
    vis.forEach(p => {
      const x = (Mapa._wx(p) - Mapa.vista.cx) * e + larg / 2;
      const y = (Mapa._wy(p) - Mapa.vista.cy) * e + alt / 2;
      if (x < -60 || x > larg + 60 || y < -20 || y > alt + 20) return;
      const cor = p.prospecto ? '#f9a825' : (p.nivel === 'Alto' ? '#1b5e20' : (p.nivel === 'Médio' ? '#2e7d32' : '#81c784'));
      svg += `<g class="mapa-ponto" data-lat="${p.lat}" data-lng="${p.lng}" style="cursor:pointer">
        <circle cx="${x.toFixed(1)}" cy="${y.toFixed(1)}" r="8" fill="${cor}" stroke="#fff" stroke-width="2.5"/>
        <text x="${(x + 11).toFixed(1)}" y="${(y + 4).toFixed(1)}" class="croqui-rotulo">${App.escapeHtml(p.nome.split(' ').slice(0, 2).join(' '))}</text>
      </g>`;
    });

    // Escala gráfica
    const lat0 = Math.atan(Math.sinh(Math.PI * (1 - 2 * Mapa.vista.cy))) * 180 / Math.PI;
    const mPorPx = 156543.03392 * Math.cos(lat0 * Math.PI / 180) / Math.pow(2, Mapa.vista.z);
    const alvoM = (larg / 4) * mPorPx;
    const passo = Math.pow(10, Math.floor(Math.log10(Math.max(1, alvoM))));
    const escalaM = passo * Math.max(1, Math.floor(alvoM / passo));
    const escalaPx = escalaM / mPorPx;
    svg += `<g><rect x="14" y="${alt - 34}" width="${(escalaPx + 14).toFixed(1)}" height="24" rx="5" fill="#fff" opacity=".75"/>
      <line x1="20" y1="${alt - 16}" x2="${(20 + escalaPx).toFixed(1)}" y2="${alt - 16}" stroke="#222" stroke-width="2"/>
      <text x="${(20 + escalaPx / 2).toFixed(1)}" y="${alt - 21}" text-anchor="middle" font-size="11" fill="#222">${escalaM >= 1000 ? (escalaM / 1000) + ' km' : escalaM + ' m'}</text></g>`;

    palco.innerHTML = `
      <div class="croqui-tiles">${tilesHtml}</div>
      <svg viewBox="0 0 ${larg} ${alt}" width="${larg}" height="${alt}"></svg>
      <div class="croqui-zoom">
        <button type="button" class="btn btn-light btn-sm" onclick="Mapa.zoom(1)" title="Aproximar"><i class="bi bi-plus-lg"></i></button>
        <button type="button" class="btn btn-light btn-sm" onclick="Mapa.zoom(-1)" title="Afastar"><i class="bi bi-dash-lg"></i></button>
      </div>
      ${Mapa.tiles && navigator.onLine ? `<div class="croqui-atribuicao">${App.escapeHtml(Mapa.tiles.atribuicao || '')}</div>` : ''}`;
    palco.querySelector('svg').innerHTML = svg;
  },

  _eventos(palco) {
    palco.addEventListener('pointerdown', ev => {
      if (ev.target.closest('.croqui-zoom')) return;
      // O alvo do toque é lido AQUI: depois do setPointerCapture o pointerup
      // é redirecionado para o palco e perde o ponto tocado
      Mapa._alvoToque = ev.target.closest('.mapa-ponto');
      try { palco.setPointerCapture(ev.pointerId); } catch (e) { /* segue sem captura */ }
      Mapa._ponteiros.set(ev.pointerId, { x: ev.clientX, y: ev.clientY, x0: ev.clientX, y0: ev.clientY });
      Mapa._moveu = false;
      if (Mapa._ponteiros.size === 2 && Mapa.vista) {
        // Pinça: zoom contínuo ancorado no ponto médio dos dedos
        Mapa._pan = null;
        const [a, b] = [...Mapa._ponteiros.values()];
        const r = palco.getBoundingClientRect();
        const e = Mapa._escala();
        const mx = (a.x + b.x) / 2 - r.left, my = (a.y + b.y) / 2 - r.top;
        Mapa._pinch = {
          d0: Math.max(1, Math.hypot(a.x - b.x, a.y - b.y)),
          z0: Mapa.vista.z,
          wx: Mapa.vista.cx + (mx - r.width / 2) / e,
          wy: Mapa.vista.cy + (my - r.height / 2) / e,
        };
        ev.preventDefault();
        return;
      }
      if (Mapa.vista) Mapa._pan = { x: ev.clientX, y: ev.clientY };
      ev.preventDefault();
    });
    palco.addEventListener('pointermove', ev => {
      const reg = Mapa._ponteiros.get(ev.pointerId);
      if (!reg) return;
      if (Math.hypot(ev.clientX - reg.x0, ev.clientY - reg.y0) > 6) Mapa._moveu = true;
      reg.x = ev.clientX; reg.y = ev.clientY;
      if (Mapa._pinch && Mapa._ponteiros.size === 2) {
        const [a, b] = [...Mapa._ponteiros.values()];
        const d = Math.max(1, Math.hypot(a.x - b.x, a.y - b.y));
        Mapa.vista.z = Math.max(3, Math.min(19, Mapa._pinch.z0 + Math.log2(d / Mapa._pinch.d0)));
        const r = palco.getBoundingClientRect();
        const e = Mapa._escala();
        const mx = (a.x + b.x) / 2 - r.left, my = (a.y + b.y) / 2 - r.top;
        Mapa.vista.cx = Mapa._pinch.wx - (mx - r.width / 2) / e;
        Mapa.vista.cy = Mapa._pinch.wy - (my - r.height / 2) / e;
        Mapa._moveu = true;
        Mapa.render();
        return;
      }
      if (Mapa._pan && Mapa.vista) {
        const e = Mapa._escala();
        Mapa.vista.cx -= (ev.clientX - Mapa._pan.x) / e;
        Mapa.vista.cy -= (ev.clientY - Mapa._pan.y) / e;
        Mapa._pan = { x: ev.clientX, y: ev.clientY };
        Mapa.render();
      }
    });
    const soltar = ev => {
      Mapa._ponteiros.delete(ev.pointerId);
      if (Mapa._ponteiros.size < 2) Mapa._pinch = null;
      if (!Mapa._ponteiros.size) Mapa._pan = null;
      // Toque parado sobre um ponto abre o mapa do aparelho
      if (ev.type === 'pointerup' && !Mapa._moveu && Mapa._alvoToque) {
        window.open('https://www.google.com/maps/search/?api=1&query=' + Mapa._alvoToque.dataset.lat + ',' + Mapa._alvoToque.dataset.lng, '_blank');
      }
      Mapa._alvoToque = null;
    };
    palco.addEventListener('pointerup', soltar);
    palco.addEventListener('pointercancel', soltar);
    palco.addEventListener('wheel', ev => {
      ev.preventDefault();
      Mapa.zoom(ev.deltaY < 0 ? 1 : -1);
    }, { passive: false });
  },
};
// O app.js (App.escapeHtml) carrega no fim do layout — inicia só com o DOM pronto
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => Mapa.iniciar());
else Mapa.iniciar();
</script>
