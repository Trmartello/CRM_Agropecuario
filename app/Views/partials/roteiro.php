<?php
$wa = function (?string $tel, string $texto): ?string {
    if (!$tel) return null;
    $num = preg_replace('/\D+/', '', $tel);
    if (strlen($num) < 10) return null;
    if (strlen($num) <= 11) $num = '55' . $num;
    return 'https://wa.me/' . $num . '?text=' . rawurlencode($texto);
};
?>
<p class="text-muted small">Roteiro de <?= data_br($data) ?> — <?= count($eventos) ?> parada(s), na ordem dos horários.</p>
<?php if (!$eventos): ?><p class="text-muted">Nenhum evento para este dia.</p><?php endif; ?>
<ol class="list-group list-group-numbered">
  <?php foreach ($eventos as $e): $link = $wa($e['cliente_telefone'] ?? null, 'Olá! Sobre a visita de hoje: ' . $e['titulo'] . '. Podemos confirmar o horário?'); ?>
  <?php $vencida = !empty($e['visita_vencida']) && ($e['status'] ?? '') !== 'Concluído'; $diasTxt = isset($e['dias_sem_visita']) ? ($e['dias_sem_visita'] >= 120 ? '+120' : $e['dias_sem_visita']) . 'd s/ visita' : null; ?>
  <li class="list-group-item d-flex align-items-start gap-2 <?= $vencida ? 'border-start border-warning border-3' : '' ?>">
    <div class="flex-grow-1">
      <div class="fw-semibold"><?= $e['hora'] ? substr($e['hora'],0,5) . ' · ' : '' ?><?= e($e['titulo']) ?>
        <?php if ($vencida): ?><span class="badge text-bg-warning text-dark ms-1" title="Sem visita há <?= $diasTxt ?>"><i class="bi bi-exclamation-triangle me-1"></i>Visita vencida</span><?php endif; ?></div>
      <div class="small text-muted"><?= e($e['cliente'] ?? '—') ?><?= $e['municipio'] ? ' · ' . e($e['municipio']) : '' ?><?= $diasTxt ? ' · ' . $diasTxt : '' ?></div>
      <?php if ($e['descricao']): ?><div class="small"><?= e($e['descricao']) ?></div><?php endif; ?>
    </div>
    <div class="d-flex flex-column gap-1">
      <?php if ($link): ?><a class="btn btn-sm btn-outline-success" href="<?= e($link) ?>" target="_blank"><i class="bi bi-whatsapp me-1"></i>WhatsApp</a><?php endif; ?>
      <?php if ($e['latitude'] && $e['longitude']): ?><a class="btn btn-sm btn-outline-secondary" href="https://www.google.com/maps/search/?api=1&query=<?= $e['latitude'] ?>,<?= $e['longitude'] ?>" target="_blank"><i class="bi bi-geo-alt me-1"></i>Mapa</a><?php endif; ?>
    </div>
  </li>
  <?php endforeach; ?>
</ol>
