<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#abaPriorizacao"><i class="bi bi-sort-numeric-down me-1"></i>Priorização</button></li>
  <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#abaVisitas"><i class="bi bi-clipboard2-pulse me-1"></i>Visitas realizadas</button></li>
</ul>

<div class="tab-content">
  <!-- PAINEL DE PRIORIZAÇÃO -->
  <div class="tab-pane fade show active" id="abaPriorizacao">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
      <p class="text-muted small mb-0">
        Ordem recomendada de visita: tempo sem visita, nível tecnológico, volume, potencial e risco de churn.
      </p>
      <button class="btn btn-success" onclick="Visitas.nova()"><i class="bi bi-clipboard2-plus me-1"></i>Nova Visita</button>
    </div>
    <!-- Celular: cartões de campo (1 produtor por cartão, ações em 1 toque) -->
    <div class="d-md-none">
      <?php foreach ($prioridades as $i => $p): ?>
      <?php
        $telDig = preg_replace('/\D/', '', (string) ($p['telefone'] ?? ''));
        $wa = $telDig !== '' ? '55' . ltrim($telDig, '0') : '';
        $cpClasse = $p['dias_sem_visita'] >= 90 ? 'cp-vencida' : ($p['dias_sem_visita'] >= 60 ? 'cp-atencao' : 'cp-emdia');
      ?>
      <div class="card mb-2 cartao-prior <?= $cpClasse ?>">
        <div class="card-body py-2 px-3">
          <div class="d-flex justify-content-between align-items-start gap-2">
            <div>
              <span class="badge rounded-pill text-bg-<?= $i < 3 ? 'danger' : 'success' ?>"><?= $i + 1 ?>º</span>
              <strong><?= e($p['nome']) ?></strong>
              <?= selo_segmento($p['segmento_manual'] ?? null, $p['segmento'] ?? null, true) ?>
              <?php if ($p['risco_churn']): ?><span class="badge text-bg-danger">Churn −<?= $p['queda_percentual'] ?>%</span><?php endif; ?>
              <div class="small text-muted"><?= e($p['municipio'] ?? '—') ?> · <?= e($p['nivel_tecnologico']) ?></div>
            </div>
            <div class="text-end">
              <div class="small <?= $p['dias_sem_visita'] >= 120 ? 'text-danger fw-bold' : 'text-muted' ?>">
                <i class="bi bi-clock-history"></i> <?= $p['dias_sem_visita'] >= 120 ? '120+' : $p['dias_sem_visita'] ?> d
              </div>
              <div class="progress mt-1" style="width:64px;height:7px" title="score <?= $p['score'] ?>">
                <div class="progress-bar bg-<?= $p['score'] >= 60 ? 'danger' : ($p['score'] >= 40 ? 'warning' : 'success') ?>" style="width:<?= $p['score'] ?>%"></div>
              </div>
            </div>
          </div>
          <div class="d-flex gap-2 mt-2">
            <?php if (($p['ultima_completude'] ?? null) !== null && !$p['ultima_finalizada']): ?>
              <button class="btn btn-warning flex-grow-1" onclick="Visitas.editar(<?= (int) $p['ultima_visita_id'] ?>)"><i class="bi bi-pencil-square me-1"></i>Completar (<?= (int) $p['ultima_completude'] ?>%)</button>
            <?php else: ?>
              <button class="btn btn-success flex-grow-1" onclick="Visitas.nova(<?= $p['id'] ?>)"><i class="bi bi-clipboard2-plus me-1"></i>Visitar</button>
            <?php endif; ?>
            <?php if ($wa !== ''): ?>
              <a class="btn btn-outline-success" target="_blank" href="https://wa.me/<?= e($wa) ?>" title="WhatsApp"><i class="bi bi-whatsapp"></i></a>
              <a class="btn btn-outline-secondary" href="tel:+<?= e($wa) ?>" title="Ligar"><i class="bi bi-telephone"></i></a>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if (!$prioridades): ?><div class="text-center text-muted py-4">Nenhum produtor na carteira.</div><?php endif; ?>
    </div>

    <div class="card d-none d-md-block">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th style="width:60px">#</th>
              <th>Produtor</th>
              <th class="d-none d-md-table-cell">Dias sem visita</th>
              <th class="d-none d-md-table-cell">Nível tec.</th>
              <th class="d-none d-lg-table-cell">Volume anual</th>
              <th class="d-none d-lg-table-cell">Potencial</th>
              <th>Score</th>
              <th class="text-end"></th>
            </tr>
          </thead>
          <tbody id="tabelaPriorizacao">
            <?php foreach ($prioridades as $i => $p): ?>
            <tr class="<?= $i < 3 ? 'table-warning-subtle' : '' ?>">
              <td><span class="badge rounded-pill text-bg-<?= $i < 3 ? 'danger' : 'success' ?> fs-6"><?= $i + 1 ?>º</span></td>
              <td>
                <div class="fw-semibold"><?= e($p['nome']) ?>
                  <span class="ms-1"><?= selo_segmento($p['segmento_manual'] ?? null, $p['segmento'] ?? null, true) ?></span>
                  <?php if ($p['risco_churn']): ?><span class="badge text-bg-danger ms-1">Churn −<?= $p['queda_percentual'] ?>%</span><?php endif; ?>
                </div>
                <div class="small text-muted"><?= e($p['municipio'] ?? '') ?></div>
                <div class="mt-1">
                  <?php if (($p['ultima_completude'] ?? null) === null): ?>
                    <span class="badge rounded-pill text-bg-light border text-muted" title="Nenhuma visita registrada"><i class="bi bi-clipboard-x me-1"></i>sem visita</span>
                  <?php elseif ($p['ultima_finalizada']): ?>
                    <span class="badge rounded-pill text-bg-success" title="Última visita finalizada (<?= (int) $p['ultima_completude'] ?>% do cadastro)"><i class="bi bi-clipboard-check me-1"></i>Cadastro <?= (int) $p['ultima_completude'] ?>%</span>
                  <?php else: ?>
                    <span class="badge rounded-pill text-bg-warning text-dark" title="Cadastro da última visita não finalizado"><i class="bi bi-clipboard-check me-1"></i>Cadastro <?= (int) $p['ultima_completude'] ?>%</span>
                  <?php endif; ?>
                </div>
              </td>
              <td class="d-none d-md-table-cell <?= $p['dias_sem_visita'] >= 120 ? 'text-danger fw-bold' : '' ?>">
                <?= $p['dias_sem_visita'] >= 120 ? '120+' : $p['dias_sem_visita'] ?>
              </td>
              <td class="d-none d-md-table-cell"><?= e($p['nivel_tecnologico']) ?></td>
              <td class="d-none d-lg-table-cell"><?= moeda($p['volume_compra_anual']) ?></td>
              <td class="d-none d-lg-table-cell"><?= moeda($p['potencial_venda']) ?></td>
              <td>
                <div class="progress" style="width:70px;height:8px" title="score <?= $p['score'] ?>">
                  <div class="progress-bar bg-<?= $p['score'] >= 60 ? 'danger' : ($p['score'] >= 40 ? 'warning' : 'success') ?>" style="width:<?= $p['score'] ?>%"></div>
                </div>
                <span class="small text-muted"><?= $p['score'] ?></span>
              </td>
              <td class="text-end">
                <?php if (($p['ultima_completude'] ?? null) !== null && !$p['ultima_finalizada']): ?>
                  <button class="btn btn-sm btn-warning" onclick="Visitas.editar(<?= (int) $p['ultima_visita_id'] ?>)" title="Completar o cadastro da última visita (<?= (int) $p['ultima_completude'] ?>%)"><i class="bi bi-pencil-square"></i><span class="d-none d-md-inline ms-1">Completar</span></button>
                <?php else: ?>
                  <button class="btn btn-sm btn-success" onclick="Visitas.nova(<?= $p['id'] ?>)" title="Registrar nova visita"><i class="bi bi-clipboard2-plus"></i><span class="d-none d-md-inline ms-1">Visitar</span></button>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- VISITAS REALIZADAS -->
  <div class="tab-pane fade" id="abaVisitas">
    <form class="mb-2" method="get" action="index.php">
      <input type="hidden" name="r" value="visitas">
      <div class="input-group">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input type="search" name="busca" class="form-control" placeholder="Buscar por cliente ou cultura…" value="<?= e($busca) ?>">
        <button class="btn btn-outline-secondary">Buscar</button>
      </div>
    </form>
    <div class="card">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr><th>Data</th><th>Cliente</th><th class="d-none d-md-table-cell">Local</th><th class="d-none d-md-table-cell">Cultura</th><th class="d-none d-lg-table-cell">Técnico</th><th></th></tr>
          </thead>
          <tbody>
            <?php if (!$visitas): ?><tr><td colspan="6" class="text-center text-muted py-4">Nenhuma visita registrada.</td></tr><?php endif; ?>
            <?php foreach ($visitas as $v): ?>
            <tr>
              <td class="text-nowrap"><?= data_br($v['data_visita']) ?></td>
              <td>
                <div class="fw-semibold"><?= e($v['cliente']) ?>
                  <?php if (isset($v['finalizada']) && !$v['finalizada']): ?><span class="badge text-bg-warning text-dark ms-1" title="Cadastro incompleto (<?= (int) $v['completude'] ?>% preenchido)"><i class="bi bi-hourglass-split me-1"></i>Não finalizada · <?= (int) $v['completude'] ?>%</span><?php endif; ?>
                </div>
                <?php if ($v['qtd_fotos'] > 0): ?><span class="small text-muted"><i class="bi bi-camera me-1"></i><?= $v['qtd_fotos'] ?> foto(s)</span><?php endif; ?>
              </td>
              <td class="d-none d-md-table-cell small"><?= e($v['propriedade'] ?? '—') ?><?= $v['talhao'] ? ' · ' . e($v['talhao']) : '' ?></td>
              <td class="d-none d-md-table-cell"><?= e($v['cultura'] ?? '—') ?></td>
              <td class="d-none d-lg-table-cell small"><?= e($v['tecnico']) ?></td>
              <td class="text-end text-nowrap">
                <?php if (isset($v['finalizada']) && !$v['finalizada']): ?>
                  <button class="btn btn-sm btn-warning" onclick="Visitas.editar(<?= $v['id'] ?>)" title="Completar cadastro"><i class="bi bi-pencil-square"></i></button>
                <?php endif; ?>
                <button class="btn btn-sm btn-outline-success" onclick="Visitas.detalhe(<?= $v['id'] ?>)" title="Ver detalhe"><i class="bi bi-eye"></i></button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Painel lateral: detalhe da visita -->
<div class="offcanvas offcanvas-end offcanvas-ficha" tabindex="-1" id="painelVisita">
  <div class="offcanvas-header border-bottom">
    <h5 class="offcanvas-title"><i class="bi bi-clipboard2-pulse me-2 text-success"></i>Detalhe da Visita</h5>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
  </div>
  <div class="offcanvas-body" id="painelVisitaCorpo"></div>
</div>

<?php require __DIR__ . '/partials/modal_visita.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
  <?php if (isset($_GET['nova'])): ?>
    Visitas.nova(<?= (int) ($_GET['cliente_id'] ?? 0) ?: 'null' ?>);
  <?php elseif (isset($_GET['editar'])): ?>
    Visitas.editar(<?= (int) $_GET['editar'] ?>);
  <?php endif; ?>

  // Duplo clique na linha do produtor executa a ação disponível (Visitar ou Completar)
  document.getElementById('tabelaPriorizacao')?.addEventListener('dblclick', ev => {
    if (ev.target.closest('button, a')) return; // clique já foi no próprio botão
    ev.target.closest('tr')?.querySelector('td:last-child button')?.click();
  });
});
</script>
