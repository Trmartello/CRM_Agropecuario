<?php
$wa = function (?string $tel, string $texto): ?string {
    if (!$tel) return null;
    $num = preg_replace('/\D+/', '', $tel);
    if (strlen($num) < 10) return null;
    if (strlen($num) <= 11) $num = '55' . $num;
    return 'https://wa.me/' . $num . '?text=' . rawurlencode($texto);
};
$mapa = fn ($la, $lo) => ($la && $lo) ? 'https://www.google.com/maps/search/?api=1&query=' . $la . ',' . $lo : null;
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
      <div class="card-header d-flex align-items-center bg-success-subtle">
        <i class="bi bi-signpost-split me-2 text-success"></i><strong>Roteiro de <?= $diaBr ?></strong>
        <span class="ms-auto badge text-bg-success"><?= count($roteiro) ?> parada(s)</span>
      </div>
      <ol class="list-group list-group-flush list-group-numbered" id="listaRoteiro">
        <?php if (!$roteiro): ?><li class="list-group-item text-muted">Nenhuma parada. Adicione produtores das sugestões ao lado.</li><?php endif; ?>
        <?php foreach ($roteiro as $i => $r): $link = $wa($r['cliente_telefone'] ?? null, 'Olá! Podemos agendar uma visita para ' . $diaBr . '? (' . str_replace('Visita — ', '', $r['titulo']) . ')'); $lmap = $mapa($r['latitude'], $r['longitude']); ?>
        <li class="list-group-item d-flex align-items-center gap-2 <?= $r['status'] === 'Concluído' ? 'opacity-50' : '' ?>">
          <div class="flex-grow-1">
            <div class="fw-semibold"><?= e($r['cliente'] ?? str_replace('Visita — ', '', $r['titulo'])) ?>
              <?php if ($r['status'] === 'Concluído'): ?><span class="badge text-bg-success ms-1">Visitado</span><?php endif; ?></div>
            <div class="small text-muted"><?= e($r['municipio'] ?? '—') ?><?= $r['hora'] ? ' · ' . substr($r['hora'],0,5) : '' ?></div>
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
      <div class="card-header d-flex align-items-center">
        <i class="bi bi-stars me-2 text-success"></i><strong>Sugestões para visitar</strong>
        <span class="ms-auto small text-muted">por prioridade</span>
      </div>
      <div class="list-group list-group-flush" style="max-height:520px;overflow:auto">
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
      App.alerta(r.otimizadas > 1 ? 'Rota otimizada por proximidade (' + r.otimizadas + ' paradas).' : 'Poucas paradas com localização para otimizar.', r.otimizadas > 1 ? 'success' : 'info');
      setTimeout(() => location.reload(), 700);
    } catch (e) { App.alerta(e.message, 'danger'); }
  },
  imprimir() { window.print(); },
};
</script>
