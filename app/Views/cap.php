<?php use App\Core\Permissoes; ?>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header"><i class="bi bi-trophy me-1 text-success"></i><strong>Minhas metas — CAP Copérdia Alta Performance</strong></div>
      <div class="card-body">
        <?php if (!$minhasMetas): ?>
          <p class="text-muted mb-0">Nenhuma meta cadastrada para o seu usuário no período vigente.</p>
        <?php endif; ?>
        <?php foreach ($minhasMetas as $m): ?>
        <div class="mb-3">
          <?php $invertido = !empty($m['menor_melhor']); ?>
          <div class="d-flex justify-content-between flex-wrap">
            <strong><?= e($m['indicador']) ?>
              <?php if ($invertido): ?><span class="badge text-bg-light border text-muted fw-normal ms-1"><i class="bi bi-arrow-down-short"></i>quanto menor, melhor</span><?php endif; ?>
            </strong>
            <span class="small text-muted"><?= data_br($m['periodo_inicio']) ?> a <?= data_br($m['periodo_fim']) ?></span>
          </div>
          <div class="d-flex justify-content-between small mb-1">
            <span>Realizado: <strong><?= $m['unidade'] === 'R$' ? moeda($m['realizado']) : numero($m['realizado'], $m['unidade'] === '%' ? 1 : 0) . ' ' . e($m['unidade']) ?></strong></span>
            <span><?= $invertido ? 'Teto' : 'Meta' ?>: <?= $m['unidade'] === 'R$' ? moeda($m['meta']) : numero($m['meta'], $m['unidade'] === '%' ? 1 : 0) . ' ' . e($m['unidade']) ?></span>
          </div>
          <div class="progress" style="height:16px">
            <div class="progress-bar bg-<?= $m['percentual'] >= 100 ? 'success' : ($m['percentual'] >= 70 ? 'info' : ($m['percentual'] >= 40 ? 'warning' : 'danger')) ?>"
                 style="width:<?= min(100, $m['percentual']) ?>%"><?= numero($m['percentual'], 1) ?>%</div>
          </div>
          <?php if ($m['saldo'] > 0): ?>
            <?php if ($invertido): ?>
              <div class="small text-danger mt-1"><i class="bi bi-exclamation-triangle me-1"></i><?= $m['unidade'] === 'R$' ? moeda($m['saldo']) : numero($m['saldo'], $m['unidade'] === '%' ? 1 : 0) . ' ' . e($m['unidade']) ?> acima do teto.</div>
            <?php else: ?>
              <div class="small text-muted mt-1">Faltam <?= $m['unidade'] === 'R$' ? moeda($m['saldo']) : numero($m['saldo'], 0) . ' ' . e($m['unidade']) ?> para a meta.</div>
            <?php endif; ?>
          <?php else: ?>
            <div class="small text-success mt-1"><i class="bi bi-check-circle me-1"></i><?= $invertido ? 'Dentro do teto!' : 'Meta atingida!' ?></div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <p class="small text-muted mb-0 mt-3"><i class="bi bi-info-circle me-1"></i>Dados locais — a integração oficial com o aplicativo CAPE será ativada na Fase 5.</p>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-person-check me-1 text-success"></i><strong>Quem visitar para fechar a meta</strong></div>
      <div class="list-group list-group-flush">
        <?php if (!$produtoresSugeridos): ?><div class="list-group-item text-muted">Sem sugestões no momento.</div><?php endif; ?>
        <?php foreach ($produtoresSugeridos as $ps): ?>
        <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
           href="<?= url('visitas', ['nova' => 1, 'cliente_id' => $ps['id']]) ?>">
          <div>
            <div class="fw-semibold"><?= e($ps['nome']) ?></div>
            <div class="small text-muted"><?= e($ps['municipio'] ?? '') ?> · realizado <?= moeda($ps['realizado']) ?> de <?= moeda($ps['potencial_total']) ?></div>
          </div>
          <span class="badge text-bg-success-subtle text-success border border-success">+<?= moeda($ps['espaco']) ?></span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if (Permissoes::ehGestor() && $equipe): ?>
    <div class="card">
      <div class="card-header"><i class="bi bi-people me-1 text-success"></i><strong>Equipe</strong></div>
      <div class="card-body">
        <?php foreach ($equipe as $membro): ?>
        <div class="mb-3">
          <strong><?= e($membro['nome']) ?></strong> <span class="text-muted small">· <?= e($membro['perfil']) ?></span>
          <?php foreach ($membro['metas'] as $m): ?>
          <div class="d-flex align-items-center gap-2 small">
            <span class="text-truncate" style="max-width:180px"><?php if (!empty($m['menor_melhor'])): ?><i class="bi bi-arrow-down-short text-muted" title="quanto menor, melhor"></i><?php endif; ?><?= e($m['indicador']) ?></span>
            <div class="progress flex-grow-1" style="height:8px">
              <div class="progress-bar bg-<?= $m['percentual'] >= 100 ? 'success' : ($m['percentual'] >= 70 ? 'info' : 'warning') ?>" style="width:<?= min(100, $m['percentual']) ?>%"></div>
            </div>
            <span class="text-muted"><?= numero($m['percentual'], 0) ?>%</span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
