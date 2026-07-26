<?php $statusCor = ['Registrada'=>'secondary','Em análise'=>'info','Procedente'=>'success','Improcedente'=>'dark','Jurídico'=>'warning','Indenização'=>'primary','Encerrada'=>'light']; ?>
<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link active"><i class="bi bi-speedometer2 me-1"></i>Painel</a></li>
  <li class="nav-item"><a class="nav-link" href="<?= url('gerencial/desempenho') ?>"><i class="bi bi-trophy me-1"></i>Desempenho</a></li>
  <li class="nav-item"><a class="nav-link" href="<?= url('gerencial/custo-regional') ?>"><i class="bi bi-bar-chart-steps me-1"></i>Custo regional</a></li>
  <?php if (\App\Core\Permissoes::podeCustoIndividual()): ?>
  <li class="nav-item"><a class="nav-link" href="<?= url('gerencial/custo-individual') ?>"><i class="bi bi-person-lock me-1"></i>Custo individual</a></li>
  <?php endif; ?>
  <?php if (\App\Core\Auth::perfil() === 'Administrador'): ?>
    <li class="nav-item"><a class="nav-link" href="<?= url('gerencial/auditoria-campo') ?>"><i class="bi bi-shield-exclamation me-1"></i>Auditoria de campo</a></li>
  <?php endif; ?>
</ul>
<div class="row g-3 mb-3">
  <div class="col-6 col-md-3"><div class="card indicador h-100"><div class="card-body"><div class="text-muted small">Clientes ativos</div><div class="fs-3 fw-bold"><?= numero($totais['clientes']) ?></div><i class="bi bi-people icone-fundo"></i></div></div></div>
  <div class="col-6 col-md-3"><div class="card indicador h-100"><div class="card-body"><div class="text-muted small">Visitas no mês</div><div class="fs-3 fw-bold"><?= numero($totais['visitas']) ?></div><i class="bi bi-clipboard2-pulse icone-fundo"></i></div></div></div>
  <div class="col-6 col-md-3"><div class="card indicador h-100"><div class="card-body"><div class="text-muted small">Vendido no mês</div><div class="fs-5 fw-bold"><?= moeda($totais['vendido']) ?></div><i class="bi bi-currency-dollar icone-fundo"></i></div></div></div>
  <div class="col-6 col-md-3"><div class="card indicador h-100 <?= $totais['reclamacoes_abertas'] > 0 ? 'border-warning' : '' ?>"><div class="card-body"><div class="text-muted small">Reclamações abertas</div><div class="fs-3 fw-bold"><?= numero($totais['reclamacoes_abertas']) ?></div><i class="bi bi-exclamation-octagon icone-fundo"></i></div></div></div>

  <!-- KPIs estratégicos -->
  <div class="col-6 col-md-3"><div class="card indicador h-100"><div class="card-body">
    <div class="text-muted small">Atingimento CAP (equipe)</div>
    <div class="fs-3 fw-bold"><?= $kpis['cap_atingimento'] !== null ? $kpis['cap_atingimento'] . '%' : '—' ?></div>
    <i class="bi bi-trophy icone-fundo"></i></div></div></div>
  <div class="col-6 col-md-3"><div class="card indicador h-100"><div class="card-body">
    <div class="text-muted small">Conversão do funil</div>
    <div class="fs-3 fw-bold"><?= $kpis['funil_conversao'] !== null ? $kpis['funil_conversao'] . '%' : '—' ?></div>
    <div class="small text-muted"><?= $kpis['funil_ganhas'] ?> ganhas · <?= $kpis['funil_perdidas'] ?> perdidas</div>
    <i class="bi bi-funnel icone-fundo"></i></div></div></div>
  <div class="col-6 col-md-3"><div class="card indicador h-100 <?= $kpis['clientes_churn'] > 0 ? 'border-danger' : '' ?>"><div class="card-body">
    <div class="text-muted small">Clientes em risco de churn</div>
    <div class="fs-3 fw-bold text-<?= $kpis['clientes_churn'] > 0 ? 'danger' : 'body' ?>"><?= numero($kpis['clientes_churn']) ?></div>
    <i class="bi bi-graph-down-arrow icone-fundo"></i></div></div></div>
  <div class="col-6 col-md-3"><div class="card indicador h-100"><div class="card-body">
    <div class="text-muted small">Aproveitamento do potencial (safra)</div>
    <div class="fs-3 fw-bold"><?= $kpis['potencial_pct'] !== null ? $kpis['potencial_pct'] . '%' : '—' ?></div>
    <i class="bi bi-bar-chart-line icone-fundo"></i></div></div></div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-people me-2 text-success"></i><strong>Desempenho da equipe (mês)</strong></div>
      <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Responsável</th><th class="text-end">Carteira</th><th class="text-end">Visitas</th><th class="text-end">Pedidos</th><th class="text-end">Vendido</th></tr></thead>
        <tbody>
          <?php if (!$equipe): ?><tr><td colspan="5" class="text-muted text-center py-3">Sem dados.</td></tr><?php endif; ?>
          <?php foreach ($equipe as $u): ?>
          <tr><td class="fw-semibold"><?= e($u['nome']) ?><div class="small text-muted"><?= e($u['perfil']) ?></div></td>
            <td class="text-end"><?= numero($u['carteira']) ?></td>
            <td class="text-end"><?= numero($u['visitas_mes']) ?></td>
            <td class="text-end"><?= numero($u['pedidos_mes']) ?></td>
            <td class="text-end fw-semibold"><?= moeda($u['vendido_mes']) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
  </div>

  <div class="col-lg-5 d-flex flex-column gap-3">
    <div class="card">
      <div class="card-header"><i class="bi bi-bar-chart me-2 text-success"></i><strong>Vendas por família (mês)</strong></div>
      <div class="card-body">
        <?php if (!$porFamilia): ?><span class="text-muted small">Sem vendas no mês.</span>
        <?php else: ?><canvas id="chartFamilia" height="180" data-familia='<?= json_attr($porFamilia) ?>'></canvas><?php endif; ?>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><i class="bi bi-pie-chart me-2 text-success"></i><strong>Segmentação da carteira</strong></div>
      <div class="card-body">
        <?php
          $totalSeg = array_sum($segmentacao['distribuicao']);
          $dadosSeg = [];
          foreach ($segmentacao['distribuicao'] as $sig => $qtd) {
              $dadosSeg[] = ['sigla' => $sig, 'rotulo' => \App\Services\SegmentacaoService::ROTULOS[$sig], 'total' => $qtd];
          }
        ?>
        <?php if ($totalSeg === 0): ?>
          <span class="text-muted small">Carteira ainda não segmentada — o cálculo roda no primeiro login do dia.</span>
        <?php else: ?>
          <canvas id="chartSegmentos" height="170" data-segmentos='<?= json_attr($dadosSeg) ?>'></canvas>
          <?php if ($segmentacao['sem_segmento'] > 0): ?>
            <div class="small text-muted mt-2"><?= numero($segmentacao['sem_segmento']) ?> cliente(s) ainda sem segmento calculado.</div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><i class="bi bi-funnel me-2 text-success"></i><strong>Funil</strong></div>
      <div class="card-body d-flex flex-wrap gap-3">
        <?php if (!$funil): ?><span class="text-muted small">Sem oportunidades abertas.</span><?php endif; ?>
        <?php foreach ($funil as $f): ?>
        <div class="text-center flex-grow-1"><div class="small text-muted"><?= e($f['estagio']) ?></div><div class="fw-bold"><?= $f['qtd'] ?></div><div class="small"><?= moeda($f['valor']) ?></div></div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><i class="bi bi-receipt me-2 text-success"></i><strong>Despesas do mês (reembolso)</strong></div>
      <div class="card-body d-flex justify-content-around text-center">
        <div><div class="small text-muted">KM</div><div class="fw-bold"><?= moeda($despesas['km']) ?></div></div>
        <div><div class="small text-muted">Refeições</div><div class="fw-bold"><?= moeda($despesas['refeicoes']) ?></div></div>
        <div><div class="small text-muted">Total</div><div class="fw-bold text-success"><?= moeda($despesas['km'] + $despesas['refeicoes']) ?></div></div>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><i class="bi bi-exclamation-octagon me-2 text-success"></i><strong>Reclamações por status</strong></div>
      <ul class="list-group list-group-flush">
        <?php if (!$reclamacoes): ?><li class="list-group-item text-muted small">Nenhuma reclamação.</li><?php endif; ?>
        <?php foreach ($reclamacoes as $r): ?>
        <li class="list-group-item d-flex justify-content-between align-items-center py-2"><span><span class="badge text-bg-<?= $statusCor[$r['status']] ?? 'secondary' ?> me-1"> </span><?= e($r['status']) ?></span><span class="fw-semibold"><?= $r['qtd'] ?></span></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const cv = document.getElementById('chartFamilia');
  if (cv && typeof Chart !== 'undefined') {
    const dados = JSON.parse(cv.dataset.familia || '[]');
    const chart = new Chart(cv, {
      type: 'bar',
      data: { labels: dados.map(d => d.familia), datasets: [{ data: dados.map(d => Number(d.total)), backgroundColor: '#2e7d32', borderRadius: 6 }] },
      options: { indexAxis: 'y', plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => App.moeda(c.raw) } } }, scales: { x: { ticks: { callback: v => 'R$ ' + (v/1000) + 'k' } } } },
    });
    chart.$rotulo = { formatter: v => 'R$ ' + Math.round(v/1000) + 'k', color: '#1b5e20' };
    chart.update();
  }
  const cvSeg = document.getElementById('chartSegmentos');
  if (cvSeg && typeof Chart !== 'undefined') {
    const seg = JSON.parse(cvSeg.dataset.segmentos || '[]').filter(s => s.total > 0);
    const cores = { A: '#2e7d32', B: '#1565c0', C: '#78909c', D: '#c62828', P: '#f9a825' };
    new Chart(cvSeg, {
      type: 'doughnut',
      data: {
        labels: seg.map(s => s.rotulo),
        datasets: [{ data: seg.map(s => Number(s.total)), backgroundColor: seg.map(s => cores[s.sigla] || '#999') }],
      },
      options: { plugins: { legend: { position: 'right' } } },
    });
  }
});
</script>
