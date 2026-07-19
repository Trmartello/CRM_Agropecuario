<?php $statusCor = ['Sucesso'=>'success','Parcial'=>'warning','Erro'=>'danger','—'=>'light']; ?>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-hdd-network me-2 text-success"></i><strong>Fonte de dados</strong></div>
      <div class="card-body">
        <form id="formIntegracao" onsubmit="return Integracao.salvar(event)">
          <p class="text-muted small">Enquanto o ERP/CAPE não está conectado, o sistema opera com a base local. Ao configurar a URL do ERP, a fonte passa a ser externa — as telas não mudam, apenas a origem dos dados.</p>
          <div class="mb-3">
            <label class="form-label">Fonte atual</label>
            <select name="fonte" class="form-select">
              <?php foreach (['Local','ERP','CAPE'] as $f): ?><option <?= $fonte === $f ? 'selected' : '' ?>><?= $f ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">URL do ERP <span class="text-muted small">(quando disponível)</span></label>
            <input name="erp_url" class="form-control" value="<?= e($erpUrl) ?>" placeholder="https://erp.coperdia.com.br/api">
          </div>
          <div class="d-flex justify-content-between align-items-center">
            <span class="badge text-bg-<?= $erpConfigurado ? 'success' : 'secondary' ?>">
              <?= $erpConfigurado ? 'ERP configurado' : 'ERP não configurado (usando base local)' ?>
            </span>
            <button class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Salvar</button>
          </div>
        </form>
      </div>
    </div>
    <button class="btn btn-outline-success w-100" onclick="Integracao.sincronizar('_tudo')"><i class="bi bi-arrow-repeat me-1"></i>Sincronizar tudo agora</button>
  </div>

  <div class="col-lg-7">
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-list-check me-2 text-success"></i><strong>Entidades</strong></div>
      <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Entidade</th><th>Última sinc.</th><th>Status</th><th class="text-end">Registros</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($status as $s): ?>
          <tr>
            <td class="fw-semibold"><?= e($s['rotulo']) ?></td>
            <td class="small"><?= $s['ultima'] ? data_br($s['ultima']) . ' ' . substr($s['ultima'],11,5) : '—' ?></td>
            <td><span class="badge text-bg-<?= $statusCor[$s['status']] ?? 'secondary' ?> <?= ($statusCor[$s['status']] ?? '')==='light' ? 'border text-dark' : '' ?>"><?= e($s['status']) ?></span></td>
            <td class="text-end"><?= numero($s['registros']) ?></td>
            <td class="text-end"><button class="btn btn-sm btn-outline-secondary" title="Sincronizar" onclick="Integracao.sincronizar('<?= e($s['entidade']) ?>')"><i class="bi bi-arrow-repeat"></i></button></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>

    <div class="card">
      <div class="card-header"><i class="bi bi-clock-history me-2 text-success"></i><strong>Histórico de sincronização</strong></div>
      <div class="list-group list-group-flush" style="max-height:260px;overflow:auto">
        <?php if (!$historico): ?><div class="list-group-item text-muted small">Nenhuma sincronização registrada.</div><?php endif; ?>
        <?php foreach ($historico as $h): ?>
        <div class="list-group-item d-flex justify-content-between align-items-center py-2">
          <div><span class="badge text-bg-<?= $statusCor[$h['status']] ?? 'secondary' ?> me-1"><?= e($h['status']) ?></span>
            <?= e($entidades[$h['entidade']] ?? $h['entidade']) ?>
            <div class="small text-muted"><?= e($h['fonte']) ?> · <?= e($h['mensagem'] ?? '') ?></div></div>
          <span class="small text-muted text-nowrap"><?= data_br($h['criado_em']) ?> <?= substr($h['criado_em'],11,5) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<script>
const Integracao = {
  async salvar(ev) {
    ev.preventDefault();
    try {
      await App.json('index.php?r=integracao/salvar-config', { method: 'POST', body: new FormData(ev.target) });
      App.alerta('Configuração salva.');
      setTimeout(() => location.reload(), 600);
    } catch (e) { App.alerta(e.message, 'danger'); }
    return false;
  },
  async sincronizar(entidade) {
    try {
      const fd = new FormData(); fd.append('entidade', entidade);
      const r = await App.json('index.php?r=integracao/sincronizar', { method: 'POST', body: fd });
      App.alerta('Sincronização concluída.');
      setTimeout(() => location.reload(), 700);
    } catch (e) { App.alerta(e.message, 'danger'); }
  },
};
</script>
