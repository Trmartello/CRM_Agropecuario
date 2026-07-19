<?php
$statusCor = [
  'Registrada'=>'secondary','Em análise'=>'info','Procedente'=>'success','Improcedente'=>'dark',
  'Jurídico'=>'warning','Indenização'=>'primary','Encerrada'=>'light',
];
$r = $reclamacao;
?>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
  <div>
    <div class="fw-semibold fs-5"><?= e($r['cliente']) ?></div>
    <div class="text-muted small">
      <?= e($r['tipo']) ?><?= $r['produto'] ? ' · ' . e($r['produto']) : '' ?><?= $r['cultura'] ? ' · ' . e($r['cultura']) : '' ?>
      · registrada em <?= data_br($r['criado_em']) ?><?= $r['registrado_por'] ? ' por ' . e($r['registrado_por']) : '' ?>
    </div>
  </div>
  <span class="badge text-bg-<?= $statusCor[$r['status']] ?? 'secondary' ?> <?= ($statusCor[$r['status']] ?? '') === 'light' ? 'border text-dark' : '' ?> fs-6"><?= e($r['status']) ?></span>
</div>

<div class="row g-2 small mb-2">
  <?php if ($r['lote']): ?><div class="col-6"><span class="text-muted">Lote:</span> <?= e($r['lote']) ?></div><?php endif; ?>
  <?php if ($r['nota_fiscal']): ?><div class="col-6"><span class="text-muted">NF:</span> <?= e($r['nota_fiscal']) ?></div><?php endif; ?>
</div>

<?php if ($r['problema']): ?><p class="mb-1"><strong>Problema:</strong> <?= e($r['problema']) ?></p><?php endif; ?>
<?php if ($r['descricao']): ?><p class="mb-2 text-muted"><?= nl2br(e($r['descricao'])) ?></p><?php endif; ?>

<?php if ($r['valor_indenizacao']): ?>
<div class="alert alert-primary py-2"><i class="bi bi-cash-coin me-1"></i>Indenização: <strong><?= moeda($r['valor_indenizacao']) ?></strong></div>
<?php endif; ?>

<?php if ($r['parecer']): ?>
<div class="alert alert-light border py-2 small"><strong>Parecer:</strong> <?= nl2br(e($r['parecer'])) ?></div>
<?php endif; ?>

<?php if ($fotos): ?>
<div class="d-flex flex-wrap gap-2 mb-3">
  <?php foreach ($fotos as $f): ?>
    <a href="uploads/<?= e($f['arquivo']) ?>" target="_blank"><img src="uploads/<?= e($f['arquivo']) ?>" class="foto-miniatura" alt="Foto" loading="lazy" style="height:90px" onerror="App.fotoIndisponivel(this)"></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($podeGerir && $transicoes): ?>
<hr>
<form id="formMoverReclamacao" onsubmit="return Reclamacoes.mover(event)">
  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
  <label class="form-label fw-semibold">Movimentar laudo</label>
  <div class="row g-2 align-items-end">
    <div class="col-md-5">
      <select name="status" class="form-select" required onchange="Reclamacoes.toggleIndenizacao(this.value)">
        <option value="">Selecione o próximo status…</option>
        <?php foreach ($transicoes as $t): ?><option value="<?= e($t) ?>"><?= e($t) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4 d-none" id="campoIndenizacao">
      <input type="number" step="0.01" min="0" name="valor_indenizacao" class="form-control" placeholder="Valor da indenização (R$)">
    </div>
    <div class="col-md-3"><button class="btn btn-success w-100"><i class="bi bi-arrow-right-circle me-1"></i>Aplicar</button></div>
    <div class="col-12"><input name="parecer" class="form-control" placeholder="Parecer/observação (opcional)"></div>
  </div>
</form>
<?php elseif ($podeGerir && !$transicoes): ?>
<div class="alert alert-light border py-2 small mb-0"><i class="bi bi-check2-circle me-1"></i>Laudo encerrado — sem novas movimentações.</div>
<?php endif; ?>
