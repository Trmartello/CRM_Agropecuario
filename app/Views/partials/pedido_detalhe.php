<?php /** Detalhe do pedido — carregado via AJAX no offcanvas. */
$cores = ['Rascunho' => 'secondary', 'Pendente de aprovação' => 'warning', 'Aprovado' => 'primary', 'Faturado' => 'success', 'Cancelado' => 'dark'];
?>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
  <div>
    <h5 class="mb-0">Pedido #<?= (int) $pedido['id'] ?> — <?= e($pedido['cliente']) ?></h5>
    <div class="text-muted small"><?= e($pedido['tipo']) ?><?= $pedido['pacote'] ? ' · ' . e($pedido['pacote']) : '' ?> · <?= e($pedido['vendedor']) ?> · <?= data_br(substr($pedido['criado_em'], 0, 10)) ?></div>
  </div>
  <span class="badge fs-6 text-bg-<?= $cores[$pedido['status']] ?? 'secondary' ?>"><?= e($pedido['status']) ?></span>
</div>

<?php if ($pedido['motivo_pendencia']): ?>
  <div class="alert alert-warning py-2 small"><i class="bi bi-exclamation-triangle me-1"></i><?= e($pedido['motivo_pendencia']) ?></div>
<?php endif; ?>

<div class="table-responsive">
  <table class="table table-sm align-middle">
    <thead class="table-light"><tr><th>Produto</th><th class="text-end">Qtde</th><th class="text-end">Unitário</th><th class="text-end">Desc.</th><th class="text-end">Subtotal</th></tr></thead>
    <tbody>
      <?php foreach ($itens as $i): $sub = $i['quantidade'] * $i['valor_unitario'] * (1 - $i['desconto_pct'] / 100); ?>
      <tr>
        <td><?= e($i['produto']) ?></td>
        <td class="text-end"><?= numero($i['quantidade'], 1) ?> <?= e($i['unidade']) ?></td>
        <td class="text-end"><?= moeda($i['valor_unitario']) ?></td>
        <td class="text-end"><?= numero($i['desconto_pct'], 1) ?>%</td>
        <td class="text-end"><?= moeda($sub) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<dl class="row small mb-0">
  <dt class="col-6 text-end">Valor bruto</dt><dd class="col-6 text-end mb-1"><?= moeda($pedido['valor_bruto']) ?></dd>
  <dt class="col-6 text-end">Descontos</dt><dd class="col-6 text-end mb-1">− <?= moeda($pedido['desconto_total']) ?></dd>
  <dt class="col-6 text-end fs-6">Total</dt><dd class="col-6 text-end fs-6 fw-bold"><?= moeda($pedido['valor_total']) ?></dd>
  <?php if ($pedido['bonificacao_sacas'] > 0): ?>
    <dt class="col-6 text-end text-success">Bonificação em grãos</dt>
    <dd class="col-6 text-end text-success fw-semibold"><?= numero($pedido['bonificacao_sacas'], 1) ?> sacas</dd>
  <?php endif; ?>
  <?php if ($pedido['condicao_pagamento']): ?><dt class="col-6 text-end">Condição</dt><dd class="col-6 text-end"><?= e($pedido['condicao_pagamento']) ?></dd><?php endif; ?>
  <?php if ($pedido['area_ha']): ?><dt class="col-6 text-end">Área do pacote</dt><dd class="col-6 text-end"><?= numero($pedido['area_ha'], 0) ?> ha</dd><?php endif; ?>
</dl>
