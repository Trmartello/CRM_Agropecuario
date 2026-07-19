<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <p class="text-muted mb-0">Clientes com localização cadastrada. Toque em um ponto para abrir no mapa do aparelho.</p>
  <input type="search" id="mapaBusca" class="form-control form-control-sm" style="max-width:240px" placeholder="Buscar cliente…" oninput="Mapa.filtrar(this.value)">
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card"><div class="card-body">
      <?php if (!$comGeo): ?>
        <p class="text-muted text-center py-5">Nenhum cliente com coordenadas cadastradas. Capture a localização no cadastro/visita.</p>
      <?php else: ?>
        <canvas id="mapaCanvas" height="360" data-pontos='<?= json_encode(array_map(fn($c) => [
          'id' => (int)$c['id'], 'nome' => $c['nome'], 'lat' => (float)$c['latitude'], 'lng' => (float)$c['longitude'],
          'nivel' => $c['nivel_tecnologico'], 'prospecto' => (int)$c['prospecto'],
        ], $comGeo), JSON_UNESCAPED_UNICODE) ?>'></canvas>
        <div class="small text-muted mt-2"><i class="bi bi-info-circle me-1"></i>Distribuição aproximada por coordenadas (sem mapa online — pensado para uso em campo).</div>
      <?php endif; ?>
    </div></div>
  </div>
  <div class="col-lg-5">
    <div class="card"><div class="list-group list-group-flush" id="mapaLista" style="max-height:420px;overflow:auto">
      <?php foreach ($clientes as $c): ?>
      <div class="list-group-item d-flex align-items-center gap-2 item-mapa" data-nome="<?= e(mb_strtolower($c['nome'])) ?>">
        <div class="flex-grow-1">
          <div class="fw-semibold"><?= e($c['nome']) ?>
            <?php if ($c['prospecto']): ?><span class="badge text-bg-warning ms-1">Prospecto</span><?php endif; ?></div>
          <div class="small text-muted"><?= e($c['municipio'] ?? '—') ?><?= $c['estado'] ? '/' . e($c['estado']) : '' ?> · Nível <?= e($c['nivel_tecnologico']) ?></div>
        </div>
        <?php if ($c['latitude'] && $c['longitude']): ?>
        <a class="btn btn-sm btn-outline-success" href="https://www.google.com/maps/search/?api=1&query=<?= $c['latitude'] ?>,<?= $c['longitude'] ?>" target="_blank" title="Abrir no mapa"><i class="bi bi-geo-alt"></i></a>
        <?php else: ?><span class="badge text-bg-light border text-muted">sem GPS</span><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div></div>
  </div>
</div>

<script>
const Mapa = {
  filtrar(termo) {
    const t = (termo || '').toLowerCase();
    document.querySelectorAll('#mapaLista .item-mapa').forEach(el => {
      el.style.display = el.dataset.nome.includes(t) ? '' : 'none';
    });
  },
  desenhar() {
    const cv = document.getElementById('mapaCanvas');
    if (!cv) return;
    const pontos = JSON.parse(cv.dataset.pontos || '[]');
    if (!pontos.length) return;
    const ctx = cv.getContext('2d');
    const w = cv.width = cv.clientWidth, h = cv.height;
    const lats = pontos.map(p => p.lat), lngs = pontos.map(p => p.lng);
    const minLa = Math.min(...lats), maxLa = Math.max(...lats), minLo = Math.min(...lngs), maxLo = Math.max(...lngs);
    const pad = 30;
    const x = lo => pad + (maxLo === minLo ? 0.5 : (lo - minLo) / (maxLo - minLo)) * (w - 2 * pad);
    const y = la => h - pad - (maxLa === minLa ? 0.5 : (la - minLa) / (maxLa - minLa)) * (h - 2 * pad);
    ctx.clearRect(0, 0, w, h);
    ctx.fillStyle = '#f6faf6'; ctx.fillRect(0, 0, w, h);
    pontos.forEach(p => {
      ctx.beginPath();
      ctx.arc(x(p.lng), y(p.lat), 7, 0, 2 * Math.PI);
      ctx.fillStyle = p.prospecto ? '#f9a825' : (p.nivel === 'Alto' ? '#1b5e20' : (p.nivel === 'Médio' ? '#2e7d32' : '#81c784'));
      ctx.fill(); ctx.strokeStyle = '#fff'; ctx.lineWidth = 2; ctx.stroke();
      ctx.fillStyle = '#333'; ctx.font = '11px sans-serif';
      ctx.fillText(p.nome.slice(0, 14), x(p.lng) + 9, y(p.lat) + 4);
    });
  },
};
Mapa.desenhar();
window.addEventListener('resize', () => Mapa.desenhar());
</script>
