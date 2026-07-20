<form class="row g-2 align-items-end mb-3" method="get" action="index.php">
  <input type="hidden" name="r" value="auditoria">
  <div class="col-6 col-md-2">
    <label class="form-label small mb-1">De</label>
    <input type="date" name="de" value="<?= e($filtros['de']) ?>" class="form-control form-control-sm">
  </div>
  <div class="col-6 col-md-2">
    <label class="form-label small mb-1">Até</label>
    <input type="date" name="ate" value="<?= e($filtros['ate']) ?>" class="form-control form-control-sm">
  </div>
  <div class="col-6 col-md-3">
    <label class="form-label small mb-1">Usuário</label>
    <select name="usuario_id" class="form-select form-select-sm">
      <option value="0">Todos</option>
      <?php foreach ($usuarios as $u): ?>
        <option value="<?= (int) $u['id'] ?>" <?= $filtros['usuario_id'] === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['nome']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-6 col-md-3">
    <label class="form-label small mb-1">Registro</label>
    <select name="entidade" class="form-select form-select-sm">
      <option value="">Todos</option>
      <?php foreach ($entidades as $ent): ?>
        <option value="<?= e($ent) ?>" <?= $filtros['entidade'] === $ent ? 'selected' : '' ?>><?= e($ent) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-12 col-md-2">
    <button class="btn btn-success btn-sm w-100"><i class="bi bi-funnel me-1"></i>Filtrar</button>
  </div>
</form>

<div class="card">
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Quando</th>
          <th>Usuário</th>
          <th>Ação</th>
          <th>Registro</th>
          <th class="d-none d-md-table-cell">Detalhe</th>
          <th class="d-none d-lg-table-cell">IP</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$registros): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">Nenhum evento no período.</td></tr>
        <?php endif; ?>
        <?php foreach ($registros as $r): ?>
        <tr>
          <td class="text-nowrap small"><?= e(date('d/m H:i', strtotime($r['criado_em']))) ?></td>
          <td class="small"><?= e($r['usuario'] ?? '—') ?><?= $r['perfil'] ? '<span class="text-muted"> · ' . e($r['perfil']) . '</span>' : '' ?></td>
          <td><span class="badge text-bg-<?= in_array($r['acao'], ['excluir', 'cancelar', 'rejeitar'], true) ? 'danger' : (in_array($r['acao'], ['criar', 'aprovar'], true) ? 'success' : 'secondary') ?>"><?= e($r['acao']) ?></span></td>
          <td class="small"><?= e($r['tabela']) ?><?= $r['registro_id'] ? ' #' . (int) $r['registro_id'] : '' ?></td>
          <td class="d-none d-md-table-cell small text-muted"><?= e($r['dados'] ?? '') ?></td>
          <td class="d-none d-lg-table-cell small text-muted"><?= e($r['ip'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<p class="text-muted small mt-2">Mostrando até 300 eventos do período filtrado (mais recentes primeiro).</p>
