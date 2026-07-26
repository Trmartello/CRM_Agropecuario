<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link" href="<?= url('gerencial') ?>"><i class="bi bi-speedometer2 me-1"></i>Painel</a></li>
  <li class="nav-item"><a class="nav-link" href="<?= url('gerencial/desempenho') ?>"><i class="bi bi-trophy me-1"></i>Desempenho</a></li>
  <li class="nav-item"><a class="nav-link" href="<?= url('gerencial/custo-regional') ?>"><i class="bi bi-bar-chart-steps me-1"></i>Custo regional</a></li>
  <li class="nav-item"><a class="nav-link active"><i class="bi bi-shield-exclamation me-1"></i>Auditoria de campo</a></li>
</ul>

<form method="get" class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <input type="hidden" name="r" value="gerencial/auditoria-campo">
  <label class="form-label mb-0 fw-semibold">Período:</label>
  <input type="month" name="mes" class="form-control" style="max-width:180px" value="<?= e($mes) ?>" onchange="this.form.submit()">
  <span class="text-muted small">
    O GPS do "Iniciar Visita" é comparado com a propriedade cadastrada:
    croqui (tolerância <?= (int) $limiteContorno ?> m) ou sede/coordenadas do produtor (<?= number_format($limitePonto / 1000, 1, ',', '.') ?> km).
  </span>
</form>

<?php
  $totVisitas = array_sum(array_map(fn($r) => (int) $r['visitas'], $resumo));
  $totFora = array_sum(array_map(fn($r) => (int) $r['fora'], $resumo));
  $totSemAval = array_sum(array_map(fn($r) => (int) $r['sem_avaliacao'], $resumo));
  $totSemInicio = array_sum(array_map(fn($r) => (int) $r['sem_inicio'], $resumo));
?>
<div class="row g-3 mb-3">
  <div class="col-6 col-md-3"><div class="card indicador h-100"><div class="card-body"><div class="text-muted small">Visitas no período</div><div class="fs-3 fw-bold"><?= numero($totVisitas) ?></div><i class="bi bi-clipboard2-pulse icone-fundo"></i></div></div></div>
  <div class="col-6 col-md-3"><div class="card indicador h-100 <?= $totFora > 0 ? 'border-danger' : '' ?>"><div class="card-body"><div class="text-muted small">Fora da propriedade</div><div class="fs-3 fw-bold <?= $totFora > 0 ? 'text-danger' : 'text-success' ?>"><?= numero($totFora) ?></div><i class="bi bi-geo-alt icone-fundo"></i></div></div></div>
  <div class="col-6 col-md-3"><div class="card indicador h-100"><div class="card-body"><div class="text-muted small">% fora</div><div class="fs-3 fw-bold"><?= $totVisitas > 0 ? numero($totFora / $totVisitas * 100, 1) . '%' : '—' ?></div><i class="bi bi-percent icone-fundo"></i></div></div></div>
  <div class="col-6 col-md-3"><div class="card indicador h-100"><div class="card-body"><div class="text-muted small">Sem avaliação (sem GPS/referência)</div><div class="fs-3 fw-bold"><?= numero($totSemAval) ?></div><i class="bi bi-question-circle icone-fundo"></i></div></div></div>
</div>

<div class="card mb-3">
  <div class="card-header"><i class="bi bi-people me-2 text-success"></i><strong>Por responsável</strong></div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Responsável</th>
          <th class="text-center">Visitas</th>
          <th class="text-center">Fora da propriedade</th>
          <th class="text-center">% fora</th>
          <th class="text-center d-none d-md-table-cell">Sem avaliação</th>
          <th class="text-center d-none d-md-table-cell">Sem "Iniciar" (antigas)</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$resumo): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">Nenhuma visita no período.</td></tr>
        <?php endif; ?>
        <?php foreach ($resumo as $r): $fora = (int) $r['fora']; $vis = (int) $r['visitas']; ?>
        <tr>
          <td><div class="fw-semibold"><?= e($r['nome']) ?></div><div class="small text-muted"><?= e($r['perfil']) ?></div></td>
          <td class="text-center"><?= numero($vis) ?></td>
          <td class="text-center">
            <?php if ($fora > 0): ?><span class="badge text-bg-danger fs-6"><?= numero($fora) ?></span>
            <?php else: ?><span class="badge text-bg-success">0</span><?php endif; ?>
          </td>
          <td class="text-center"><?= $vis > 0 ? numero($fora / $vis * 100, 1) . '%' : '—' ?></td>
          <td class="text-center d-none d-md-table-cell"><?= numero($r['sem_avaliacao']) ?></td>
          <td class="text-center d-none d-md-table-cell"><?= numero($r['sem_inicio']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer small text-muted">
    "Sem avaliação" = visita sem GPS de início (aparelho sem sinal/permissão) ou produtor sem croqui/coordenadas cadastradas.
    "Sem Iniciar" = visitas antigas, registradas antes da obrigatoriedade do botão.
  </div>
</div>

<div class="card">
  <div class="card-header"><i class="bi bi-shield-exclamation me-2 text-danger"></i><strong>Visitas lançadas fora da propriedade</strong></div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Data</th>
          <th>Responsável</th>
          <th>Produtor</th>
          <th class="d-none d-md-table-cell">Propriedade</th>
          <th class="text-end">Distância</th>
          <th class="text-center d-none d-md-table-cell">Início—Fim</th>
          <th class="text-center">Onde foi lançada</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$visitasFora): ?>
          <tr><td colspan="7" class="text-center text-muted py-4"><i class="bi bi-check-circle me-1 text-success"></i>Nenhuma visita fora da propriedade neste período.</td></tr>
        <?php endif; ?>
        <?php foreach ($visitasFora as $v):
          $lat = $v['inicio_lat'] ?? $v['latitude'];
          $lng = $v['inicio_lng'] ?? $v['longitude'];
          $dist = (int) $v['dist_propriedade_m'];
        ?>
        <tr>
          <td><?= data_br($v['data_visita']) ?></td>
          <td class="fw-semibold"><?= e($v['responsavel']) ?></td>
          <td><?= e($v['cliente']) ?></td>
          <td class="d-none d-md-table-cell"><?= e($v['propriedade'] ?? '—') ?></td>
          <td class="text-end"><span class="badge text-bg-danger"><?= $dist >= 1000 ? number_format($dist / 1000, 1, ',', '.') . ' km' : $dist . ' m' ?></span></td>
          <td class="text-center d-none d-md-table-cell"><?= $v['hora_inicio'] ? substr($v['hora_inicio'], 0, 5) . '—' . ($v['hora_fim'] ? substr($v['hora_fim'], 0, 5) : '?') : '—' ?></td>
          <td class="text-center">
            <?php if ($lat !== null && $lng !== null): ?>
              <a class="btn btn-sm btn-outline-danger" target="_blank"
                 href="https://www.google.com/maps/search/?api=1&query=<?= (float) $lat ?>,<?= (float) $lng ?>"
                 title="Abrir o local do lançamento no mapa"><i class="bi bi-geo-alt"></i> ver no mapa</a>
            <?php else: ?>—<?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer small text-muted">
    Cada ocorrência também gera uma notificação automática para os Administradores no momento do lançamento.
    Distância 0 = dentro do croqui da propriedade; sem croqui, a referência é a sede/coordenada do produtor.
  </div>
</div>
