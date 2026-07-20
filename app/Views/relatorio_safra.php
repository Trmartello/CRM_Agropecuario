<?php
$corSit = ['OK' => 'success', 'Atenção' => 'warning', 'Crítico' => 'danger', 'N/A' => 'secondary'];
$corPed = ['Rascunho' => 'secondary', 'Pendente de aprovação' => 'warning', 'Aprovado' => 'primary', 'Faturado' => 'success'];
$variacao = ($totalAnterior !== null && $totalAnterior > 0)
    ? round(($totalSafra - $totalAnterior) / $totalAnterior * 100)
    : null;
?>
<!-- Barra de ações (não sai na impressão) -->
<div class="d-flex flex-wrap align-items-center gap-2 mb-3 d-print-none">
  <?php if (!$ehProdutor): ?>
  <form method="get" class="d-flex align-items-center gap-2">
    <input type="hidden" name="r" value="relatorios/safra">
    <input type="hidden" name="cliente" value="<?= (int) $cliente['id'] ?>">
    <label class="form-label mb-0 fw-semibold">Safra:</label>
    <select name="safra" class="form-select form-select-sm" style="max-width:140px" onchange="this.form.submit()">
      <?php foreach ($safras as $s): ?>
        <option value="<?= $s['id'] ?>" <?= (int) $s['id'] === $safraId ? 'selected' : '' ?>><?= e($s['nome']) ?></option>
      <?php endforeach; ?>
    </select>
  </form>
  <?php endif; ?>
  <div class="ms-auto d-flex gap-2">
    <?php if (!$ehProdutor): ?>
      <a class="btn btn-sm btn-outline-secondary" href="<?= url('clientes') ?>"><i class="bi bi-arrow-left me-1"></i>Voltar</a>
    <?php else: ?>
      <a class="btn btn-sm btn-outline-secondary" href="<?= url('portal') ?>"><i class="bi bi-arrow-left me-1"></i>Voltar ao portal</a>
    <?php endif; ?>
    <button class="btn btn-sm btn-success" onclick="window.print()"><i class="bi bi-printer me-1"></i>Imprimir / PDF</button>
  </div>
</div>

<!-- Cabeçalho do documento -->
<div class="card mb-3 relatorio-capa">
  <div class="card-body">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
      <div>
        <div class="text-success fw-bold" style="letter-spacing:.06em">CRM AGRO — COPÉRDIA</div>
        <h3 class="mb-1">Relatório de Fechamento de Safra <?= e($safra['nome']) ?></h3>
        <div class="text-muted small"><?= data_br($safra['data_inicio']) ?> a <?= data_br($safra['data_fim']) ?> · gerado em <?= date('d/m/Y H:i') ?></div>
      </div>
      <div class="text-end">
        <h5 class="mb-0"><?= e($cliente['nome']) ?> <?= selo_segmento($cliente['segmento_manual'] ?? null, $cliente['segmento'] ?? null) ?></h5>
        <div class="small text-muted">
          <?= e($cliente['municipio'] ?? '—') ?>/<?= e($cliente['estado'] ?? '') ?>
          <?= $cliente['filial'] ? ' · Filial ' . e($cliente['filial']) : '' ?><br>
          Consultor responsável: <strong><?= e($cliente['responsavel'] ?? '—') ?></strong>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- 1. Lavouras e produtividade -->
<div class="card mb-3">
  <div class="card-header"><strong>1. Lavouras da safra e produtividade</strong></div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light"><tr>
        <th>Talhão</th><th>Cultura</th><th>Plantio</th><th>Colheita</th>
        <th class="text-end">Área (ha)</th><th class="text-end">Produtividade</th><th>vs. média da base</th>
      </tr></thead>
      <tbody>
        <?php if (!$plantios): ?><tr><td colspan="7" class="text-muted text-center py-3">Nenhum plantio registrado nesta safra.</td></tr><?php endif; ?>
        <?php foreach ($plantios as $p): ?>
        <?php $media = $mediasProdutividade[(int) $p['cultura_id']] ?? null; ?>
        <tr>
          <td><?= e($p['propriedade']) ?> · <strong><?= e($p['talhao']) ?></strong></td>
          <td><?= e($p['cultura']) ?><?= $p['cultivar'] ? ' <span class="text-muted small">(' . e($p['cultivar']) . ')</span>' : '' ?></td>
          <td><?= data_br($p['data_plantio']) ?></td>
          <td><?= $p['encerrado'] ? data_br($p['colhido_em']) : '<span class="badge text-bg-success">Em ciclo</span>' ?></td>
          <td class="text-end"><?= numero((float) ($p['area_gps'] ?? $p['area_ha']), 1) ?></td>
          <td class="text-end fw-semibold"><?= $p['produtividade'] !== null ? numero($p['produtividade'], 1) . ' sc/ha' : '—' ?></td>
          <td>
            <?php if ($p['produtividade'] !== null && $media && (float) $media['media'] > 0): ?>
              <?php $delta = round(((float) $p['produtividade'] / (float) $media['media'] - 1) * 100); ?>
              <span class="badge text-bg-<?= $delta >= 0 ? 'success' : 'danger' ?>"><?= $delta >= 0 ? '+' : '' ?><?= $delta ?>%</span>
              <span class="text-muted small">média <?= numero((float) $media['media'], 1) ?> sc/ha (<?= (int) $media['amostras'] ?> lavouras)</span>
            <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($planos): ?>
  <div class="card-footer small text-muted">
    Intenção de plantio registrada:
    <?php foreach ($planos as $pl): ?>
      <span class="badge text-bg-light border text-dark"><?= e($pl['cultura']) ?>: <?= numero($pl['area_ha'], 0) ?> ha</span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- 2. Assistência técnica -->
<div class="card mb-3">
  <div class="card-header"><strong>2. Assistência técnica prestada</strong></div>
  <div class="card-body pb-2">
    <div class="row g-2 mb-2">
      <div class="col-4 col-md-2"><div class="border rounded p-2 text-center"><div class="fs-4 fw-bold"><?= (int) $visitasResumo['total'] ?></div><div class="small text-muted">visitas</div></div></div>
      <div class="col-4 col-md-2"><div class="border rounded p-2 text-center"><div class="fs-4 fw-bold"><?= (int) $visitasResumo['finalizadas'] ?></div><div class="small text-muted">finalizadas</div></div></div>
      <div class="col-4 col-md-2"><div class="border rounded p-2 text-center"><div class="fs-4 fw-bold"><?= count($recomendacoes) ?></div><div class="small text-muted">recomendações</div></div></div>
      <div class="col-12 col-md-6">
        <div class="border rounded p-2 h-100">
          <div class="small text-muted mb-1">Visitas por mês</div>
          <div class="d-flex flex-wrap gap-2">
            <?php if (!$visitasPorMes): ?><span class="text-muted small">—</span><?php endif; ?>
            <?php foreach ($visitasPorMes as $m): ?>
              <span class="badge text-bg-success-subtle text-success border border-success"><?= e($m['mes']) ?>: <?= (int) $m['total'] ?></span>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
    <?php if ($checklistResumo): ?>
    <div class="mb-2">
      <span class="small text-muted me-1">Checklist da lavoura:</span>
      <?php foreach ($checklistResumo as $c): ?>
        <span class="badge text-bg-<?= $corSit[$c['situacao']] ?? 'secondary' ?>"><?= e($c['situacao']) ?>: <?= (int) $c['total'] ?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if ($checklistCriticos): ?>
    <div class="alert alert-danger py-2 mb-2">
      <strong><i class="bi bi-exclamation-triangle me-1"></i>Pontos críticos apontados no campo:</strong>
      <ul class="mb-0 small">
        <?php foreach ($checklistCriticos as $c): ?>
          <li><?= data_br($c['data_visita']) ?> — <?= e($c['titulo']) ?><?= $c['observacao'] ? ': ' . e($c['observacao']) : '' ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  </div>
  <?php if ($recomendacoes): ?>
  <div class="table-responsive">
    <table class="table table-sm mb-0">
      <thead class="table-light"><tr><th style="width:90px">Data</th><th style="width:110px">Cultura</th><th>Recomendação técnica</th><th style="width:140px">Técnico</th></tr></thead>
      <tbody>
        <?php foreach ($recomendacoes as $r): ?>
        <tr>
          <td><?= data_br($r['data_visita']) ?></td>
          <td><?= e($r['cultura'] ?? '—') ?></td>
          <td class="small"><?= nl2br(e($r['recomendacao'])) ?></td>
          <td class="small"><?= e($r['tecnico']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- 3. Comercial -->
<div class="card mb-3">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <strong>3. Comercial da safra</strong>
    <span>
      Total comprado: <strong><?= moeda($totalSafra) ?></strong>
      <?php if ($variacao !== null): ?>
        <span class="badge text-bg-<?= $variacao >= 0 ? 'success' : 'danger' ?>" title="vs. safra <?= e($anterior['nome'] ?? '') ?> (<?= moeda($totalAnterior) ?>)">
          <?= $variacao >= 0 ? '+' : '' ?><?= $variacao ?>% vs. <?= e($anterior['nome'] ?? 'safra anterior') ?></span>
      <?php endif; ?>
    </span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light"><tr><th>Família</th><th class="text-end">Potencial</th><th class="text-end">Realizado</th><th style="width:32%">Aproveitamento</th></tr></thead>
      <tbody>
        <?php if (!$comprasFamilia): ?><tr><td colspan="4" class="text-muted text-center py-3">Sem compras ou potencial registrados nesta safra.</td></tr><?php endif; ?>
        <?php foreach ($comprasFamilia as $f): ?>
        <?php $pct = (float) $f['potencial'] > 0 ? min(100, (int) round((float) $f['realizado'] / (float) $f['potencial'] * 100)) : null; ?>
        <tr>
          <td><?= e($f['familia']) ?></td>
          <td class="text-end"><?= (float) $f['potencial'] > 0 ? moeda($f['potencial']) : '—' ?></td>
          <td class="text-end fw-semibold"><?= moeda($f['realizado']) ?></td>
          <td>
            <?php if ($pct !== null): ?>
              <div class="progress" style="height:14px">
                <div class="progress-bar <?= $pct >= 70 ? 'bg-success' : ($pct >= 40 ? 'bg-warning' : 'bg-danger') ?>" style="width:<?= $pct ?>%"><?= $pct ?>%</div>
              </div>
            <?php else: ?><span class="text-muted small">sem potencial cadastrado</span><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pedidos || $entregasPendentes): ?>
  <div class="card-body pt-2 pb-3">
    <?php if ($pedidos): ?>
      <span class="small text-muted me-1">Pedidos da safra:</span>
      <?php foreach ($pedidos as $pe): ?>
        <span class="badge text-bg-<?= $corPed[$pe['status']] ?? 'secondary' ?>"><?= e($pe['status']) ?>: <?= (int) $pe['qtd'] ?> (<?= moeda($pe['total']) ?>)</span>
      <?php endforeach; ?>
    <?php endif; ?>
    <?php if ($entregasPendentes): ?>
      <div class="mt-2 small">
        <strong><i class="bi bi-truck me-1"></i>Entregas futuras pendentes:</strong>
        <?php foreach ($entregasPendentes as $ef): ?>
          <span class="badge text-bg-light border text-dark">
            <?= e($ef['produto']) ?>: <?= numero($ef['saldo'], 1) ?> <?= e($ef['unidade']) ?> até <?= $ef['previsao_entrega'] ? data_br($ef['previsao_entrega']) : '—' ?>
          </span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<!-- 4. Reclamações -->
<?php if ($reclamacoes): ?>
<div class="card mb-3">
  <div class="card-header"><strong>4. Reclamações no período</strong></div>
  <div class="table-responsive">
    <table class="table table-sm mb-0">
      <thead class="table-light"><tr><th>Data</th><th>Tipo</th><th>Produto</th><th>Problema</th><th>Situação</th></tr></thead>
      <tbody>
        <?php foreach ($reclamacoes as $rc): ?>
        <tr>
          <td><?= data_br(substr($rc['criado_em'], 0, 10)) ?></td>
          <td><?= e($rc['tipo']) ?></td>
          <td><?= e($rc['produto'] ?? '—') ?></td>
          <td class="small"><?= e($rc['problema'] ?? '—') ?></td>
          <td><span class="badge text-bg-<?= in_array($rc['status'], ['Encerrada', 'Improcedente'], true) ? 'secondary' : 'warning' ?>"><?= e($rc['status']) ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- 5. Croqui das propriedades -->
<?php if ($croquis): ?>
<div class="card mb-3">
  <div class="card-header"><strong><?= $reclamacoes ? '5' : '4' ?>. Croqui das propriedades</strong></div>
  <div class="card-body d-flex flex-wrap gap-4">
    <?php foreach ($croquis as $cq): ?>
      <div class="text-center">
        <?= $cq['svg'] ?>
        <div class="small text-muted mt-1"><?= e($cq['propriedade']) ?><?= $cq['area_gps'] ? ' · divisa medida: ' . numero($cq['area_gps'], 1) . ' ha' : '' ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Assinaturas (fechamento com o produtor) -->
<div class="row mt-5 mb-4 relatorio-assinaturas">
  <div class="col-6 text-center">
    <div class="border-top mx-4 pt-1 small"><?= e($cliente['responsavel'] ?? 'Consultor técnico') ?><br><span class="text-muted">Consultor Copérdia</span></div>
  </div>
  <div class="col-6 text-center">
    <div class="border-top mx-4 pt-1 small"><?= e($cliente['nome']) ?><br><span class="text-muted">Produtor</span></div>
  </div>
</div>
