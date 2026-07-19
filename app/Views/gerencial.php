<?php $statusCor = ['Registrada'=>'secondary','Em análise'=>'info','Procedente'=>'success','Improcedente'=>'dark','Jurídico'=>'warning','Indenização'=>'primary','Encerrada'=>'light']; ?>
<div class="row g-3 mb-3">
  <div class="col-6 col-md-3"><div class="card indicador h-100"><div class="card-body"><div class="text-muted small">Clientes ativos</div><div class="fs-3 fw-bold"><?= numero($totais['clientes']) ?></div><i class="bi bi-people icone-fundo"></i></div></div></div>
  <div class="col-6 col-md-3"><div class="card indicador h-100"><div class="card-body"><div class="text-muted small">Visitas no mês</div><div class="fs-3 fw-bold"><?= numero($totais['visitas']) ?></div><i class="bi bi-clipboard2-pulse icone-fundo"></i></div></div></div>
  <div class="col-6 col-md-3"><div class="card indicador h-100"><div class="card-body"><div class="text-muted small">Vendido no mês</div><div class="fs-5 fw-bold"><?= moeda($totais['vendido']) ?></div><i class="bi bi-currency-dollar icone-fundo"></i></div></div></div>
  <div class="col-6 col-md-3"><div class="card indicador h-100 <?= $totais['reclamacoes_abertas'] > 0 ? 'border-warning' : '' ?>"><div class="card-body"><div class="text-muted small">Reclamações abertas</div><div class="fs-3 fw-bold"><?= numero($totais['reclamacoes_abertas']) ?></div><i class="bi bi-exclamation-octagon icone-fundo"></i></div></div></div>
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
        <?php else: ?><canvas id="chartFamilia" height="180" data-familia='<?= json_encode($porFamilia, JSON_UNESCAPED_UNICODE) ?>'></canvas><?php endif; ?>
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
(function () {
  const cv = document.getElementById('chartFamilia');
  if (cv && typeof Chart !== 'undefined') {
    const dados = JSON.parse(cv.dataset.familia || '[]');
    new Chart(cv, {
      type: 'bar',
      data: { labels: dados.map(d => d.familia), datasets: [{ data: dados.map(d => Number(d.total)), backgroundColor: '#2e7d32', borderRadius: 6 }] },
      options: { indexAxis: 'y', plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => App.moeda(c.raw) } } }, scales: { x: { ticks: { callback: v => 'R$ ' + (v/1000) + 'k' } } } },
    });
  }
})();
</script>
