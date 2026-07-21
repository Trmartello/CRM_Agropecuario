<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link" href="<?= url('gerencial') ?>"><i class="bi bi-speedometer2 me-1"></i>Painel</a></li>
  <li class="nav-item"><a class="nav-link active"><i class="bi bi-trophy me-1"></i>Desempenho</a></li>
  <?php if (\App\Core\Auth::perfil() === 'Administrador'): ?>
    <li class="nav-item"><a class="nav-link" href="<?= url('gerencial/auditoria-campo') ?>"><i class="bi bi-shield-exclamation me-1"></i>Auditoria de campo</a></li>
  <?php endif; ?>
</ul>

<form method="get" class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <input type="hidden" name="r" value="gerencial/desempenho">
  <label class="form-label mb-0 fw-semibold">Período:</label>
  <input type="month" name="mes" class="form-control" style="max-width:180px" value="<?= e($mes) ?>" onchange="this.form.submit()">
  <span class="text-muted small">
    Efetividade = visitas que geraram pedido do mesmo produtor em até <?= (int) $diasConversao ?> dias.
  </span>
</form>

<div class="card mb-3">
  <div class="card-header"><i class="bi bi-people me-2 text-success"></i><strong>Equipe no período (<?= e(date('m/Y', strtotime($mes . '-01'))) ?>)</strong></div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Responsável</th>
          <th class="text-center">Visitas</th>
          <th class="text-center d-none d-lg-table-cell">Produtores<br><span class="fw-normal small">visitados / carteira</span></th>
          <th class="text-center">Cobertura</th>
          <th class="text-center">Efetividade</th>
          <th class="text-center d-none d-md-table-cell">Pedidos</th>
          <th class="text-end">Vendido</th>
          <th class="text-end d-none d-lg-table-cell">Ticket médio</th>
          <th class="text-center d-none d-lg-table-cell">Funil<br><span class="fw-normal small">ganhas · perdidas</span></th>
          <th class="text-end d-none d-md-table-cell">KM</th>
          <th class="text-end d-none d-md-table-cell">Custo/visita</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$linhas): ?>
          <tr><td colspan="11" class="text-center text-muted py-4">Nenhum vendedor/técnico ativo.</td></tr>
        <?php endif; ?>
        <?php foreach ($linhas as $l): ?>
        <tr>
          <td>
            <div class="fw-semibold"><?= e($l['nome']) ?></div>
            <div class="small text-muted"><?= e($l['perfil']) ?></div>
          </td>
          <td class="text-center">
            <span class="fw-semibold"><?= numero($l['visitas']) ?></span>
            <?php if ($l['visitas'] > $l['finalizadas']): ?>
              <div class="small text-warning" title="Visitas com cadastro incompleto"><?= numero($l['visitas'] - $l['finalizadas']) ?> incompleta(s)</div>
            <?php endif; ?>
            <?php if ($l['duracao_media'] !== null): ?>
              <div class="small text-muted" title="Duração média real (botão Iniciar Visita)"><i class="bi bi-stopwatch"></i> ~<?= $l['duracao_media'] ?> min</div>
            <?php endif; ?>
          </td>
          <td class="text-center d-none d-lg-table-cell"><?= numero($l['produtores']) ?> / <?= numero($l['carteira']) ?></td>
          <td class="text-center">
            <?php if ($l['cobertura'] === null): ?><span class="text-muted">—</span>
            <?php else: ?>
              <div class="progress" style="height:16px;min-width:70px" title="<?= $l['cobertura'] ?>% da carteira visitada no período">
                <div class="progress-bar <?= $l['cobertura'] >= 50 ? 'bg-success' : ($l['cobertura'] >= 25 ? 'bg-warning' : 'bg-danger') ?>" style="width:<?= min(100, $l['cobertura']) ?>%"><?= $l['cobertura'] ?>%</div>
              </div>
            <?php endif; ?>
          </td>
          <td class="text-center">
            <?php if ($l['efetividade'] === null): ?><span class="text-muted" title="Sem visitas no período">—</span>
            <?php else: ?>
              <div class="progress" style="height:16px;min-width:70px" title="<?= numero($l['convertidas']) ?> de <?= numero($l['visitas']) ?> visitas viraram pedido">
                <div class="progress-bar <?= $l['efetividade'] >= 40 ? 'bg-success' : ($l['efetividade'] >= 20 ? 'bg-warning' : 'bg-danger') ?>" style="width:<?= min(100, $l['efetividade']) ?>%"><?= $l['efetividade'] ?>%</div>
              </div>
            <?php endif; ?>
          </td>
          <td class="text-center d-none d-md-table-cell"><?= numero($l['pedidos']) ?></td>
          <td class="text-end fw-semibold"><?= moeda($l['vendido']) ?></td>
          <td class="text-end d-none d-lg-table-cell"><?= $l['ticket'] !== null ? moeda($l['ticket']) : '—' ?></td>
          <td class="text-center d-none d-lg-table-cell">
            <span class="badge text-bg-success"><?= numero($l['ganhas']) ?></span>
            <span class="badge text-bg-danger"><?= numero($l['perdidas']) ?></span>
          </td>
          <td class="text-end d-none d-md-table-cell" title="<?= moeda($l['custo_campo']) ?> de despesas de campo"><?= numero($l['km_rodado'], 0) ?> km</td>
          <td class="text-end d-none d-md-table-cell"><?= $l['custo_visita'] !== null ? moeda($l['custo_visita']) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer small text-muted">
    Ordenado pelo valor vendido. Funil conta oportunidades criadas no período; custo/visita = (reembolso de KM + refeições) ÷ visitas.
  </div>
</div>

<div class="card">
  <div class="card-header"><i class="bi bi-graph-up me-2 text-success"></i><strong>Evolução da equipe (últimos 6 meses)</strong></div>
  <div class="card-body">
    <canvas id="chartEvolucao" height="90" data-evolucao='<?= json_attr($evolucao) ?>'></canvas>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const cv = document.getElementById('chartEvolucao');
  if (!cv || typeof Chart === 'undefined') return;
  const serie = JSON.parse(cv.dataset.evolucao || '[]');
  new Chart(cv, {
    data: {
      labels: serie.map(s => s.rotulo),
      datasets: [
        { type: 'bar', label: 'Visitas', data: serie.map(s => s.visitas), backgroundColor: '#2e7d32', borderRadius: 6, yAxisID: 'y' },
        { type: 'line', label: 'Vendido (R$)', data: serie.map(s => Number(s.vendido)), borderColor: '#1565c0', backgroundColor: '#1565c0', tension: .3, yAxisID: 'y1' },
      ],
    },
    options: {
      plugins: { tooltip: { callbacks: { label: c => c.dataset.yAxisID === 'y1' ? c.dataset.label + ': ' + App.moeda(c.raw) : c.dataset.label + ': ' + c.raw } } },
      scales: {
        y: { beginAtZero: true, title: { display: true, text: 'Visitas' } },
        y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, ticks: { callback: v => 'R$ ' + Math.round(v / 1000) + 'k' } },
      },
    },
  });
});
</script>
