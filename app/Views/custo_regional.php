<?php
/**
 * Gerencial › Custo regional (agregado k-anônimo) — spec custo-lavoura §7.
 * A ÚNICA visão de custo fora do firewall: medianas por safra × cultura ×
 * município × faixa de área, sempre com >= K produtores por linha. O número
 * individual do cooperado não aparece aqui nem em lugar nenhum da gestão.
 */
$rotFaixa = ['ate_20' => 'até 20 ha', '20_50' => '20–50 ha', '50_100' => '50–100 ha', 'acima_100' => 'acima de 100 ha'];
$rotGrupo = ['coe' => 'COE — Custo Operacional Efetivo', 'cot' => 'Complemento até o COT', 'ct' => 'Complemento até o CT'];
?>
<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link" href="<?= url('gerencial') ?>"><i class="bi bi-speedometer2 me-1"></i>Painel</a></li>
  <li class="nav-item"><a class="nav-link" href="<?= url('gerencial/desempenho') ?>"><i class="bi bi-trophy me-1"></i>Desempenho</a></li>
  <li class="nav-item"><a class="nav-link active"><i class="bi bi-bar-chart-steps me-1"></i>Custo regional</a></li>
  <?php if (\App\Core\Permissoes::podeCustoIndividual()): ?>
  <li class="nav-item"><a class="nav-link" href="<?= url('gerencial/custo-individual') ?>"><i class="bi bi-person-lock me-1"></i>Custo individual</a></li>
  <?php endif; ?>
  <?php if (\App\Core\Auth::perfil() === 'Administrador'): ?>
    <li class="nav-item"><a class="nav-link" href="<?= url('gerencial/auditoria-campo') ?>"><i class="bi bi-shield-exclamation me-1"></i>Auditoria de campo</a></li>
  <?php endif; ?>
</ul>

<div class="alert alert-light border small">
  <i class="bi bi-shield-lock me-1 text-success"></i>
  Medianas do custo digitado pelos produtores no Portal, publicadas <strong>somente quando o grupo tem
  <?= (int) $kMinimo ?> ou mais produtores</strong> — o custo individual do cooperado não é acessível
  (firewall do invariante 5). Recalcule o agregado na Integração após novas digitações.
</div>

<form method="get" class="d-flex align-items-end gap-2 mb-3 flex-wrap">
  <input type="hidden" name="r" value="gerencial/custo-regional">
  <?php
  $selects = [
      'safra' => ['Safra', $opcoes['safras']],
      'cultura' => ['Cultura', $opcoes['culturas']],
      'municipio' => ['Município', $opcoes['municipios']],
      'faixa' => ['Faixa de área', $opcoes['faixas']],
  ];
  foreach ($selects as $nome => [$rotulo, $lista]): ?>
  <div>
    <label class="form-label small mb-1"><?= e($rotulo) ?></label>
    <select name="<?= $nome ?>" class="form-select form-select-sm" onchange="this.form.submit()" style="min-width:9rem">
      <option value="">Todas</option>
      <?php foreach ($lista as $v): ?>
      <option value="<?= e($v) ?>" <?= $filtros[$nome] === $v ? 'selected' : '' ?>>
        <?= e($nome === 'faixa' ? ($rotFaixa[$v] ?? $v) : $v) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endforeach; ?>
</form>

<?php if (!$linhas): ?>
<div class="text-muted">
  Nenhum agregado publicado<?= array_filter($filtros) ? ' para esses filtros' : '' ?>.
  Grupos com menos de <?= (int) $kMinimo ?> produtores são suprimidos por definição —
  gere/recalcule o agregado no card "Agregado regional de custo" da Integração.
</div>
<?php else: ?>
<?php
// agrupa por combinação safra|cultura|município|faixa (um bloco por combinação)
$blocos = [];
foreach ($linhas as $l) {
    $blocos[$l['safra'] . '|' . $l['cultura'] . '|' . $l['municipio'] . '|' . $l['faixa_area']][] = $l;
}
?>
<?php foreach ($blocos as $chave => $itens): [$bSafra, $bCult, $bMun, $bFaixa] = explode('|', $chave); ?>
<div class="card mb-3">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <strong><?= e($bCult) ?> · <?= e($bSafra) ?> · <?= e($bMun) ?> · <?= e($rotFaixa[$bFaixa] ?? $bFaixa) ?></strong>
    <span class="small text-muted">calculado em <?= data_br($itens[0]['dt_calculo']) ?></span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light"><tr>
        <th>Item de custo</th><th class="text-end">Mediana (R$/ha)</th><th class="text-end">Produtores</th>
      </tr></thead>
      <tbody>
        <?php
        $grupoAtual = null;
        $acum = 0.0;
        $rotAcum = ['coe' => 'COE (soma das medianas)', 'cot' => 'COT acumulado', 'ct' => 'CT acumulado'];
        $porGrupo = ['coe' => [], 'cot' => [], 'ct' => []];
        foreach ($itens as $i) {
            $porGrupo[$i['grupo']][] = $i;
        }
        foreach (['coe', 'cot', 'ct'] as $g):
            if (!$porGrupo[$g]) {
                continue;
            } ?>
          <tr class="table-light"><td colspan="3" class="small fw-semibold"><?= e($rotGrupo[$g]) ?></td></tr>
          <?php foreach ($porGrupo[$g] as $i): $acum += (float) $i['valor_mediano']; ?>
          <tr>
            <td><?= e($i['descricao']) ?></td>
            <td class="text-end"><?= number_format((float) $i['valor_mediano'], 2, ',', '.') ?></td>
            <td class="text-end"><span class="badge text-bg-light border"><?= (int) $i['qtd_produtores'] ?></span></td>
          </tr>
          <?php endforeach; ?>
          <tr class="fw-bold"><td><?= e($rotAcum[$g]) ?></td>
            <td class="text-end"><?= number_format($acum, 2, ',', '.') ?></td><td></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer small text-muted">
    Soma das medianas é um indicativo do custo típico da região — não é a mediana do custo total
    (nem todo produtor preenche todos os itens).
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>
