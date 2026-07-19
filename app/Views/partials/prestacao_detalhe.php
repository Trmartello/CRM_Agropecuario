<?php
$statusCor = ['Aberta'=>'secondary','Enviada'=>'info','Aprovada'=>'success','Rejeitada'=>'danger'];
$meses = [1=>'janeiro',2=>'fevereiro',3=>'março',4=>'abril',5=>'maio',6=>'junho',7=>'julho',8=>'agosto',9=>'setembro',10=>'outubro',11=>'novembro',12=>'dezembro'];
?>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
  <div>
    <div class="fw-semibold"><?= e($prestacao['usuario']) ?></div>
    <div class="text-muted small">Competência: <?= e($meses[(int)$prestacao['mes']] ?? $prestacao['mes']) ?> de <?= (int)$prestacao['ano'] ?></div>
  </div>
  <span class="badge text-bg-<?= $statusCor[$prestacao['status']] ?? 'secondary' ?> fs-6"><?= e($prestacao['status']) ?></span>
</div>

<div class="row text-center g-2 mb-3">
  <div class="col-4"><div class="border rounded p-2"><div class="small text-muted">KM (valor)</div><div class="fw-bold"><?= moeda($prestacao['total_km_valor']) ?></div></div></div>
  <div class="col-4"><div class="border rounded p-2"><div class="small text-muted">Refeições</div><div class="fw-bold"><?= moeda($prestacao['total_refeicoes']) ?></div></div></div>
  <div class="col-4"><div class="border rounded p-2 bg-light"><div class="small text-muted">Total</div><div class="fw-bold text-success"><?= moeda($prestacao['total_geral']) ?></div></div></div>
</div>

<?php if ($prestacao['parecer']): ?>
<div class="alert alert-<?= $prestacao['status'] === 'Rejeitada' ? 'danger' : 'success' ?> py-2 small">
  <strong>Parecer do gestor<?= $prestacao['avaliador'] ? ' (' . e($prestacao['avaliador']) . ')' : '' ?>:</strong> <?= e($prestacao['parecer']) ?>
</div>
<?php endif; ?>

<h6 class="mt-3"><i class="bi bi-signpost-2 me-1"></i>Quilometragem (<?= count($km) ?>)</h6>
<div class="table-responsive"><table class="table table-sm align-middle">
  <thead class="table-light"><tr><th>Data</th><th>Destino</th><th class="text-end">KM</th><th class="text-end">Valor</th></tr></thead>
  <tbody>
    <?php if (!$km): ?><tr><td colspan="4" class="text-muted small">Sem lançamentos.</td></tr><?php endif; ?>
    <?php foreach ($km as $l): ?>
    <tr><td><?= data_br($l['data']) ?></td><td class="small"><?= e($l['destino_desc'] ?? ($l['cliente'] ?? '—')) ?></td>
      <td class="text-end"><?= numero($l['km_rodados'], 1) ?></td><td class="text-end"><?= moeda($l['valor']) ?></td></tr>
    <?php endforeach; ?>
  </tbody>
</table></div>

<h6 class="mt-3"><i class="bi bi-cup-hot me-1"></i>Refeições (<?= count($refeicoes) ?>)</h6>
<div class="table-responsive"><table class="table table-sm align-middle">
  <thead class="table-light"><tr><th>Data</th><th>Estabelecimento</th><th class="text-end">Valor</th></tr></thead>
  <tbody>
    <?php if (!$refeicoes): ?><tr><td colspan="3" class="text-muted small">Sem lançamentos.</td></tr><?php endif; ?>
    <?php foreach ($refeicoes as $l): ?>
    <tr><td><?= data_br($l['data']) ?></td><td class="small"><?= e($l['estabelecimento'] ?? '—') ?></td><td class="text-end"><?= moeda($l['valor']) ?></td></tr>
    <?php endforeach; ?>
  </tbody>
</table></div>
