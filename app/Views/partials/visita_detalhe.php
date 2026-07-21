<?php /** Detalhe da visita — carregado via AJAX no offcanvas. */ ?>
<h5 class="mb-1"><?= e($visita['cliente']) ?></h5>
<div class="text-muted small mb-2">
  <?= data_br($visita['data_visita']) ?><?= $visita['hora'] ? ' às ' . substr($visita['hora'], 0, 5) : '' ?>
  · <?= e($visita['tecnico']) ?>
  <?php if ($visita['sincronizada_offline']): ?><span class="badge text-bg-info ms-1">registrada offline</span><?php endif; ?>
  <?php if (isset($visita['finalizada']) && !$visita['finalizada']): ?><span class="badge text-bg-warning text-dark ms-1"><i class="bi bi-hourglass-split me-1"></i>Não finalizada</span><?php endif; ?>
</div>
<?php $compl = (int) ($visita['completude'] ?? 100); ?>
<div class="d-flex align-items-center gap-2 mb-3">
  <div class="progress flex-grow-1" style="height:8px" title="Percentual de campos preenchidos">
    <div class="progress-bar bg-<?= $compl >= 80 ? 'success' : ($compl >= 40 ? 'warning' : 'danger') ?>" style="width:<?= $compl ?>%"></div>
  </div>
  <span class="small text-muted text-nowrap"><?= $compl ?>% preenchido</span>
</div>

<dl class="row small">
  <dt class="col-5">Propriedade / talhão</dt><dd class="col-7"><?= e($visita['propriedade'] ?? '—') ?><?= $visita['talhao'] ? ' · ' . e($visita['talhao']) : '' ?></dd>
  <dt class="col-5">Cultura / estágio</dt><dd class="col-7"><?= e($visita['cultura'] ?? '—') ?><?= $visita['estagio_cultura'] ? ' · ' . e($visita['estagio_cultura']) : '' ?></dd>
  <?php if (!empty($visita['hora_inicio'])): ?>
    <dt class="col-5">Duração no campo</dt>
    <dd class="col-7">
      <?= substr($visita['hora_inicio'], 0, 5) ?><?php if (!empty($visita['hora_fim'])): ?> — <?= substr($visita['hora_fim'], 0, 5) ?>
        <?php $durMin = (int) round((strtotime($visita['hora_fim']) - strtotime($visita['hora_inicio'])) / 60); ?>
        <?php if ($durMin > 0): ?><span class="badge text-bg-success-subtle text-success border border-success"><?= $durMin ?> min</span><?php endif; ?>
      <?php endif; ?>
    </dd>
  <?php endif; ?>
  <?php if (isset($visita['produtor_presente']) && $visita['produtor_presente'] !== null): ?>
    <dt class="col-5">Produtor presente</dt>
    <dd class="col-7"><span class="badge text-bg-<?= (int) $visita['produtor_presente'] === 1 ? 'success' : 'secondary' ?>"><?= (int) $visita['produtor_presente'] === 1 ? 'Sim' : 'Não' ?></span></dd>
  <?php endif; ?>
  <?php if ($visita['objetivo']): ?><dt class="col-5">Objetivo</dt><dd class="col-7"><?= e($visita['objetivo']) ?></dd><?php endif; ?>
  <?php if ($visita['desenvolvimento']): ?><dt class="col-5">Desenvolvimento</dt><dd class="col-7"><?= e($visita['desenvolvimento']) ?></dd><?php endif; ?>
  <?php if ($visita['pragas']): ?><dt class="col-5">Pragas</dt><dd class="col-7"><?= e($visita['pragas']) ?></dd><?php endif; ?>
  <?php if ($visita['doencas']): ?><dt class="col-5">Doenças</dt><dd class="col-7"><?= e($visita['doencas']) ?></dd><?php endif; ?>
  <?php if ($visita['plantas_daninhas']): ?><dt class="col-5">Plantas daninhas</dt><dd class="col-7"><?= e($visita['plantas_daninhas']) ?></dd><?php endif; ?>
  <?php if ($visita['deficiencia_nutricional']): ?><dt class="col-5">Def. nutricional</dt><dd class="col-7"><?= e($visita['deficiencia_nutricional']) ?></dd><?php endif; ?>
  <?php if ($visita['condicoes_climaticas']): ?><dt class="col-5">Clima</dt><dd class="col-7"><?= e($visita['condicoes_climaticas']) ?></dd><?php endif; ?>
  <?php if ($visita['latitude']): ?>
    <dt class="col-5">Localização</dt>
    <dd class="col-7"><a href="https://maps.google.com/?q=<?= e($visita['latitude']) ?>,<?= e($visita['longitude']) ?>" target="_blank"><i class="bi bi-geo-alt"></i> <?= e($visita['latitude']) ?>, <?= e($visita['longitude']) ?></a></dd>
  <?php endif; ?>
</dl>

<?php if ($visita['observacoes']): ?>
  <h6 class="text-success">Observações</h6>
  <p class="small"><?= nl2br(e($visita['observacoes'])) ?></p>
<?php endif; ?>

<?php if ($visita['recomendacao']): ?>
  <h6 class="text-success">Recomendação técnica</h6>
  <div class="p-2 bg-success-subtle rounded small mb-3"><?= nl2br(e($visita['recomendacao'])) ?></div>
<?php endif; ?>

<?php if (!empty($checklist)): ?>
  <h6 class="text-success"><i class="bi bi-list-check me-1"></i>Checklist da lavoura (fase <?= e($checklist[0]['estagio']) ?>)</h6>
  <ul class="list-group mb-3">
    <?php $corSit = ['OK' => 'success', 'Atenção' => 'warning', 'Crítico' => 'danger', 'N/A' => 'secondary']; ?>
    <?php foreach ($checklist as $c): ?>
      <li class="list-group-item py-1 small d-flex justify-content-between align-items-center gap-2">
        <span><?= e($c['titulo']) ?><?= $c['observacao'] ? '<br><span class="text-muted">' . e($c['observacao']) . '</span>' : '' ?></span>
        <span class="badge text-bg-<?= $corSit[$c['situacao']] ?? 'secondary' ?>"><?= e($c['situacao']) ?></span>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

<?php if ($fotos): ?>
  <h6 class="text-success">Fotos</h6>
  <div class="d-flex flex-wrap gap-2">
    <?php foreach ($fotos as $f): ?>
      <a href="<?= e(upload_url($f['arquivo'])) ?>" target="_blank"><img src="<?= e(upload_url($f['arquivo'], true)) ?>" class="foto-miniatura" alt="Foto" loading="lazy" onerror="App.fotoIndisponivel(this)"></a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
