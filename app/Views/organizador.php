<?php
$wa = function (?string $tel, string $texto): ?string {
    if (!$tel) return null;
    $num = preg_replace('/\D+/', '', $tel);
    if (strlen($num) < 10) return null;
    if (strlen($num) <= 11) $num = '55' . $num;
    return 'https://wa.me/' . $num . '?text=' . rawurlencode($texto);
};
$mapa = fn ($la, $lo) => ($la && $lo) ? 'https://www.google.com/maps/search/?api=1&query=' . $la . ',' . $lo : null;
$dur = function (int $min): string {
    if ($min <= 0) return '0 min';
    $h = intdiv($min, 60); $m = $min % 60;
    return $h > 0 ? ($m > 0 ? "{$h}h {$m}min" : "{$h}h") : "{$m}min";
};
$diaBr = data_br($data);
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <form method="get" class="d-flex gap-2 align-items-center">
    <input type="hidden" name="r" value="agenda/organizador">
    <label class="form-label mb-0 small text-muted">Dia do roteiro</label>
    <input type="date" name="data" value="<?= e($data) ?>" class="form-control form-control-sm" onchange="this.form.submit()" style="max-width:170px">
  </form>
  <div class="d-flex gap-2">
    <button class="btn btn-outline-success" onclick="Organizador.otimizar()" <?= count($roteiro) < 2 ? 'disabled' : '' ?>><i class="bi bi-magic me-1"></i>Otimizar rota</button>
    <button class="btn btn-outline-secondary" onclick="Organizador.imprimir()"><i class="bi bi-printer me-1"></i>Imprimir</button>
  </div>
</div>

<div class="row g-3">
  <!-- Roteiro do dia -->
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header d-flex align-items-center bg-success-subtle flex-wrap gap-1">
        <i class="bi bi-signpost-split me-2 text-success"></i><strong>Roteiro de <?= $diaBr ?></strong>
        <span class="ms-auto badge text-bg-success"><?= count($roteiro) ?> parada(s)</span>
        <?php if ($kmRoteiro > 0): ?><span class="badge text-bg-light border text-dark" title="Distância total do roteiro na ordem atual"><i class="bi bi-signpost me-1"></i>≈ <?= numero($kmRoteiro, 1) ?> km</span><?php endif; ?>
        <?php if ($estimativa['min_total'] > 0): ?><span class="badge text-bg-light border text-dark" title="Estimativa do dia: ~<?= $dur($estimativa['min_viagem']) ?> de viagem + <?= $estimativa['paradas'] ?> visita(s) (~<?= $dur($estimativa['min_visitas']) ?>)"><i class="bi bi-clock me-1"></i>~<?= $dur($estimativa['min_total']) ?> no dia</span><?php endif; ?>
      </div>
      <ol class="list-group list-group-flush list-group-numbered" id="listaRoteiro">
        <?php if (!$roteiro): ?><li class="list-group-item text-muted">Nenhuma parada. Adicione produtores das sugestões ao lado.</li><?php endif; ?>
        <?php foreach ($roteiro as $i => $r): $link = $wa($r['cliente_telefone'] ?? null, 'Olá! Podemos agendar uma visita para ' . $diaBr . '? (' . str_replace('Visita — ', '', $r['titulo']) . ')'); $lmap = $mapa($r['latitude'], $r['longitude']); ?>
        <?php $vencida = !empty($r['visita_vencida']) && ($r['status'] ?? '') !== 'Concluído'; $diasTxt = isset($r['dias_sem_visita']) ? ($r['dias_sem_visita'] >= \App\Services\AgendaService::DIAS_TETO ? '+' . \App\Services\AgendaService::DIAS_TETO : $r['dias_sem_visita']) . 'd s/ visita' : null; ?>
        <li class="list-group-item d-flex align-items-center gap-2 <?= $r['status'] === 'Concluído' ? 'opacity-50' : ($vencida ? 'border-start border-warning border-3' : '') ?>">
          <div class="flex-grow-1">
            <div class="fw-semibold"><?= e($r['cliente'] ?? str_replace('Visita — ', '', $r['titulo'])) ?>
              <?php if ($r['status'] === 'Concluído'): ?><span class="badge text-bg-success ms-1">Visitado</span><?php endif; ?>
              <?php if ($vencida): ?><span class="badge text-bg-warning text-dark ms-1" title="Sem visita há <?= $diasTxt ?>"><i class="bi bi-exclamation-triangle me-1"></i>Visita vencida</span><?php endif; ?></div>
            <div class="small text-muted"><?= e($r['municipio'] ?? '—') ?><?= $r['hora'] ? ' · ' . substr($r['hora'],0,5) : '' ?><?= $diasTxt ? ' · ' . $diasTxt : '' ?></div>
          </div>
          <div class="btn-group-vertical btn-group-sm me-1">
            <button class="btn btn-outline-secondary py-0" title="Subir" onclick="Organizador.reordenar(<?= $r['id'] ?>,'cima')" <?= $i === 0 ? 'disabled' : '' ?>><i class="bi bi-chevron-up"></i></button>
            <button class="btn btn-outline-secondary py-0" title="Descer" onclick="Organizador.reordenar(<?= $r['id'] ?>,'baixo')" <?= $i === count($roteiro)-1 ? 'disabled' : '' ?>><i class="bi bi-chevron-down"></i></button>
          </div>
          <?php if ($link): ?><a class="btn btn-sm btn-outline-success" href="<?= e($link) ?>" target="_blank" title="Agendar por WhatsApp"><i class="bi bi-whatsapp"></i></a><?php endif; ?>
          <?php if ($lmap): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e($lmap) ?>" target="_blank" title="Abrir no mapa"><i class="bi bi-geo-alt"></i></a><?php endif; ?>
          <?php if ($r['status'] === 'Pendente'): ?><button class="btn btn-sm btn-outline-success" title="Marcar visitado" onclick="Organizador.concluir(<?= $r['id'] ?>)"><i class="bi bi-check-lg"></i></button><?php endif; ?>
          <button class="btn btn-sm btn-outline-danger" title="Remover do roteiro" onclick="Organizador.remover(<?= $r['id'] ?>)"><i class="bi bi-x-lg"></i></button>
        </li>
        <?php endforeach; ?>
      </ol>
    </div>
  </div>

  <!-- Sugestões priorizadas -->
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header">
        <div class="d-flex align-items-center mb-2">
          <i class="bi bi-stars me-2 text-success"></i><strong>Adicionar ao roteiro</strong>
          <span class="ms-auto small text-muted">buscar ou sugestões</span>
        </div>
        <div class="input-group input-group-sm mb-2">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input type="search" id="buscaProdutor" class="form-control" placeholder="Buscar produtor por nome…" oninput="Organizador.buscar()">
        </div>
        <div class="row g-2" data-linhas-por-municipio='<?= json_attr($linhasPorMunicipio) ?>'>
          <div class="col-6">
            <select id="filtroMunicipio" class="form-select form-select-sm" onchange="Organizador.municipioMudou()">
              <option value="">Todos os municípios</option>
              <?php foreach ($municipios as $m): ?><option value="<?= e($m) ?>"><?= e($m) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-6">
            <select id="filtroLinha" class="form-select form-select-sm" onchange="Organizador.buscar()">
              <option value="">Todas as linhas</option>
              <?php foreach ($linhas as $l): ?><option value="<?= e($l) ?>"><?= e($l) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
      <!-- Resultados da busca -->
      <div class="list-group list-group-flush d-none" id="resultadosBusca"></div>
      <!-- Sugestões priorizadas -->
      <div class="list-group list-group-flush" id="listaSugestoes" style="max-height:460px;overflow:auto">
        <?php if (!$sugestoes): ?><div class="list-group-item text-muted small">Sem sugestões — todos os prioritários já estão no roteiro.</div><?php endif; ?>
        <?php foreach ($sugestoes as $s): $link = $wa($s['telefone'] ?? null, 'Olá! Podemos agendar uma visita técnica?'); ?>
        <div class="list-group-item d-flex align-items-center gap-2">
          <span class="badge rounded-pill text-bg-success">score <?= $s['score'] ?></span>
          <div class="flex-grow-1">
            <div class="fw-semibold"><?= e($s['nome']) ?>
              <?php if ($s['risco_churn']): ?><span class="badge text-bg-danger ms-1"><i class="bi bi-graph-down-arrow me-1"></i>Churn −<?= $s['queda_percentual'] ?>%</span><?php endif; ?></div>
            <div class="small text-muted">
              <?= $s['dias_sem_visita'] >= 120 ? '+120' : $s['dias_sem_visita'] ?> dias sem visita · <?= e($s['municipio'] ?? '—') ?> · Nível <?= e($s['nivel_tecnologico']) ?>
            </div>
          </div>
          <?php if ($link): ?><a class="btn btn-sm btn-outline-success" href="<?= e($link) ?>" target="_blank" title="WhatsApp"><i class="bi bi-whatsapp"></i></a><?php endif; ?>
          <button class="btn btn-sm btn-success" title="Adicionar ao roteiro" onclick="Organizador.adicionar(<?= $s['id'] ?>)"><i class="bi bi-plus-lg"></i></button>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<script>
const Organizador = {
  data: '<?= e($data) ?>',
  async adicionar(clienteId) {
    try {
      const fd = new FormData(); fd.append('cliente_id', clienteId); fd.append('data', Organizador.data);
      await App.json('index.php?r=agenda/roteiro-adicionar', { method: 'POST', body: fd });
      App.alerta('Produtor adicionado ao roteiro.');
      setTimeout(() => location.reload(), 500);
    } catch (e) { App.alerta(e.message, 'danger'); }
  },
  async remover(id) {
    try {
      const fd = new FormData(); fd.append('id', id);
      await App.json('index.php?r=agenda/roteiro-remover', { method: 'POST', body: fd });
      setTimeout(() => location.reload(), 300);
    } catch (e) { App.alerta(e.message, 'danger'); }
  },
  async reordenar(id, direcao) {
    try {
      const fd = new FormData(); fd.append('id', id); fd.append('direcao', direcao);
      await App.json('index.php?r=agenda/roteiro-reordenar', { method: 'POST', body: fd });
      setTimeout(() => location.reload(), 200);
    } catch (e) { App.alerta(e.message, 'danger'); }
  },
  async concluir(id) {
    try {
      const fd = new FormData(); fd.append('id', id); fd.append('status', 'Concluído');
      await App.json('index.php?r=agenda/status', { method: 'POST', body: fd });
      App.alerta('Visita marcada como realizada.');
      setTimeout(() => location.reload(), 400);
    } catch (e) { App.alerta(e.message, 'danger'); }
  },
  async otimizar() {
    try {
      const fd = new FormData(); fd.append('data', Organizador.data);
      const r = await App.json('index.php?r=agenda/roteiro-otimizar', { method: 'POST', body: fd });
      App.alerta(r.otimizadas > 1
        ? 'Rota otimizada para a menor distância: ≈ ' + r.km.toLocaleString('pt-BR') + ' km · ~' + Organizador.fmtDur(r.min_total) + ' no dia (' + r.otimizadas + ' paradas).'
        : 'Poucas paradas com localização para otimizar.', r.otimizadas > 1 ? 'success' : 'info');
      setTimeout(() => location.reload(), 900);
    } catch (e) { App.alerta(e.message, 'danger'); }
  },

  /** Ao trocar o município, mostra só as linhas daquele município. */
  municipioMudou() {
    const mun = document.getElementById('filtroMunicipio').value;
    const selLinha = document.getElementById('filtroLinha');
    const mapa = JSON.parse(document.querySelector('[data-linhas-por-municipio]').dataset.linhasPorMunicipio || '{}');
    const todas = Object.values(mapa).flat();
    const linhas = mun ? (mapa[mun] || []) : [...new Set(todas)].sort();
    selLinha.innerHTML = '<option value="">Todas as linhas</option>' +
      linhas.map(l => `<option value="${App.escapeHtml(l)}">${App.escapeHtml(l)}</option>`).join('');
    Organizador.buscar();
  },

  _t: null,
  buscar() {
    clearTimeout(Organizador._t);
    const termo = document.getElementById('buscaProdutor').value.trim();
    const municipio = document.getElementById('filtroMunicipio').value;
    const linha = document.getElementById('filtroLinha').value;
    const res = document.getElementById('resultadosBusca');
    const sug = document.getElementById('listaSugestoes');
    // Sem nenhum critério → volta a mostrar as sugestões priorizadas
    if (termo.length < 2 && !municipio && !linha) {
      res.classList.add('d-none'); res.innerHTML = ''; sug.classList.remove('d-none');
      return;
    }
    Organizador._t = setTimeout(async () => {
      try {
        const qs = 'q=' + encodeURIComponent(termo) + '&municipio=' + encodeURIComponent(municipio) +
          '&linha=' + encodeURIComponent(linha) + '&data=' + Organizador.data;
        const d = await App.json('index.php?r=agenda/buscar-produtor&' + qs, { headers: { 'X-Requested-With': 'fetch' } });
        sug.classList.add('d-none');
        res.classList.remove('d-none');
        if (!d.resultados.length) {
          res.innerHTML = '<div class="list-group-item text-muted small">Nenhum produtor encontrado (ou já está no roteiro).</div>';
          return;
        }
        res.innerHTML = d.resultados.map(c => `
          <div class="list-group-item d-flex align-items-center gap-2">
            <div class="flex-grow-1">
              <div class="fw-semibold">${App.escapeHtml(c.nome)}</div>
              <div class="small text-muted">${App.escapeHtml(c.municipio || '—')}${c.linha ? ' · ' + App.escapeHtml(c.linha) : ''}</div>
            </div>
            <button class="btn btn-sm btn-success" title="Adicionar ao roteiro" onclick="Organizador.adicionar(${Number(c.id)})"><i class="bi bi-plus-lg"></i></button>
          </div>`).join('');
      } catch (e) { App.alerta(e.message, 'danger'); }
    }, 300);
  },
  fmtDur(min) {
    min = Math.max(0, Math.round(min || 0));
    const h = Math.floor(min / 60), m = min % 60;
    return h > 0 ? (m > 0 ? h + 'h ' + m + 'min' : h + 'h') : m + 'min';
  },
  imprimir() { window.print(); },
};
</script>
