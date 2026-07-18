<?php use App\Core\Permissoes; ?>

<!-- Atalhos -->
<div class="d-flex flex-wrap gap-2 mb-3">
  <a class="btn btn-success btn-atalho" href="<?= url('visitas', ['nova' => 1]) ?>"><i class="bi bi-clipboard2-plus me-1"></i>Nova Visita</a>
  <a class="btn btn-success btn-atalho" href="<?= url('pedidos') ?>"><i class="bi bi-cart-plus me-1"></i>Novo Pedido</a>
  <a class="btn btn-outline-success btn-atalho" href="<?= url('pedidos') ?>"><i class="bi bi-box-seam me-1"></i>Pacote Agrícola</a>
  <a class="btn btn-outline-success btn-atalho" href="<?= url('clientes', ['novo' => 1]) ?>"><i class="bi bi-person-plus me-1"></i>Novo Cliente</a>
  <a class="btn btn-outline-success btn-atalho" href="<?= url('clientes') ?>"><i class="bi bi-search me-1"></i>Consultar Cliente</a>
  <a class="btn btn-outline-success btn-atalho" href="<?= url('funil') ?>"><i class="bi bi-funnel me-1"></i>Funil</a>
  <button class="btn btn-outline-secondary btn-atalho" disabled title="Fase 3"><i class="bi bi-exclamation-octagon me-1"></i>Reclamação <span class="badge text-bg-secondary ms-1">Fase 3</span></button>
</div>

<!-- Indicadores -->
<div class="row g-3 mb-3">
  <div class="col-6 col-md-3">
    <div class="card indicador h-100"><div class="card-body">
      <div class="text-muted small">Visitas no mês</div>
      <div class="fs-2 fw-bold"><?= numero($indicadores['visitas_mes']) ?></div>
      <i class="bi bi-clipboard2-pulse icone-fundo"></i>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card indicador h-100"><div class="card-body">
      <div class="text-muted small">Clientes visitados</div>
      <div class="fs-2 fw-bold"><?= numero($indicadores['clientes_visitados']) ?></div>
      <i class="bi bi-people icone-fundo"></i>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card indicador h-100"><div class="card-body">
      <div class="text-muted small">Fotos registradas</div>
      <div class="fs-2 fw-bold"><?= numero($indicadores['fotos_mes']) ?></div>
      <i class="bi bi-camera icone-fundo"></i>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card indicador h-100 <?= $indicadores['pendencias_aprovacao'] > 0 ? 'border-warning' : '' ?>"><div class="card-body">
      <div class="text-muted small">Pendências de aprovação</div>
      <div class="fs-2 fw-bold"><?= numero($indicadores['pendencias_aprovacao']) ?></div>
      <i class="bi bi-hourglass-split icone-fundo"></i>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card indicador h-100"><div class="card-body">
      <div class="text-muted small">Pedidos no mês</div>
      <div class="fs-2 fw-bold"><?= numero($indicadores['pedidos_mes']) ?></div>
      <i class="bi bi-cart3 icone-fundo"></i>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card indicador h-100"><div class="card-body">
      <div class="text-muted small">Valor vendido no mês</div>
      <div class="fs-4 fw-bold"><?= moeda($indicadores['valor_vendido_mes']) ?></div>
      <i class="bi bi-currency-dollar icone-fundo"></i>
    </div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card indicador h-100"><div class="card-body">
      <div class="text-muted small">Pacotes vendidos no mês</div>
      <div class="fs-2 fw-bold"><?= numero($indicadores['pacotes_mes']) ?></div>
      <i class="bi bi-box-seam icone-fundo"></i>
    </div></div>
  </div>
</div>

<div class="row g-3">
  <!-- Priorização de visitas -->
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center">
        <i class="bi bi-sort-numeric-down me-2 text-success"></i>
        <strong>Próximas visitas prioritárias</strong>
        <a href="<?= url('visitas') ?>" class="ms-auto small">ver todas</a>
      </div>
      <div class="list-group list-group-flush">
        <?php if (!$prioridades): ?>
          <div class="list-group-item text-muted">Nenhum cliente na carteira.</div>
        <?php endif; ?>
        <?php foreach ($prioridades as $i => $p): ?>
        <a class="list-group-item list-group-item-action d-flex align-items-center gap-3"
           href="<?= url('visitas', ['nova' => 1, 'cliente_id' => $p['id']]) ?>">
          <span class="badge rounded-pill text-bg-success fs-6"><?= $i + 1 ?>º</span>
          <div class="flex-grow-1">
            <div class="fw-semibold"><?= e($p['nome']) ?>
              <?php if ($p['risco_churn']): ?><span class="badge text-bg-danger ms-1"><i class="bi bi-graph-down-arrow me-1"></i>Churn −<?= $p['queda_percentual'] ?>%</span><?php endif; ?>
            </div>
            <div class="small text-muted">
              <?= $p['dias_sem_visita'] >= 120 ? '<strong class="text-danger">+120 dias sem visita</strong>' : $p['dias_sem_visita'] . ' dias sem visita' ?>
              · Nível <?= e($p['nivel_tecnologico']) ?> · Potencial <?= moeda($p['potencial_venda']) ?>
            </div>
          </div>
          <span class="badge text-bg-light border">score <?= $p['score'] ?></span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-6 d-flex flex-column gap-3">
    <!-- CAP -->
    <div class="card">
      <div class="card-header d-flex align-items-center">
        <i class="bi bi-trophy me-2 text-success"></i><strong>CAP — Copérdia Alta Performance</strong>
        <a href="<?= url('cap') ?>" class="ms-auto small">detalhes</a>
      </div>
      <div class="card-body">
        <?php if ($capGeral === null): ?>
          <span class="text-muted">Sem metas cadastradas para o seu usuário no período.</span>
        <?php else: ?>
          <div class="d-flex align-items-center gap-3">
            <div class="fs-2 fw-bold text-<?= $capGeral >= 80 ? 'success' : ($capGeral >= 50 ? 'warning' : 'danger') ?>"><?= numero($capGeral, 1) ?>%</div>
            <div class="flex-grow-1">
              <div class="small text-muted mb-1">Atingimento geral das metas</div>
              <div class="progress" style="height:10px"><div class="progress-bar bg-success" style="width:<?= min(100, $capGeral) ?>%"></div></div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Funil resumo -->
    <div class="card">
      <div class="card-header d-flex align-items-center">
        <i class="bi bi-funnel me-2 text-success"></i><strong>Funil de oportunidades</strong>
        <a href="<?= url('funil') ?>" class="ms-auto small">abrir funil</a>
      </div>
      <div class="card-body d-flex flex-wrap gap-3">
        <?php foreach ($totaisFunil as $estagio => $t): if (in_array($estagio, ['Ganha','Perdida'])) continue; ?>
        <div class="text-center flex-grow-1">
          <div class="small text-muted"><?= e($estagio) ?></div>
          <div class="fw-bold"><?= $t['qtd'] ?></div>
          <div class="small"><?= moeda($t['valor']) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Alertas -->
    <?php if ($clientesChurn || $garantiasVencendo): ?>
    <div class="card border-warning">
      <div class="card-header bg-warning-subtle"><i class="bi bi-bell me-2"></i><strong>Alertas</strong></div>
      <ul class="list-group list-group-flush">
        <?php foreach ($clientesChurn as $cc): ?>
        <li class="list-group-item d-flex align-items-center gap-2">
          <i class="bi bi-graph-down-arrow text-danger"></i>
          <span><strong><?= e($cc['nome']) ?></strong> com queda de <?= $cc['queda_percentual'] ?>% vs. safra anterior — risco de perda</span>
        </li>
        <?php endforeach; ?>
        <?php foreach ($garantiasVencendo as $g): ?>
        <li class="list-group-item d-flex align-items-center gap-2">
          <i class="bi bi-shield-exclamation text-warning"></i>
          <span>Garantia <strong><?= e($g['tipo']) ?></strong> de <?= e($g['cliente']) ?> (<?= moeda($g['valor']) ?>) vence em <?= data_br($g['vencimento']) ?></span>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  </div>
</div>
