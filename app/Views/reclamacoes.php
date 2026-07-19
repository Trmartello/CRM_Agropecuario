<?php
$statusCor = [
  'Registrada'=>'secondary','Em análise'=>'info','Procedente'=>'success','Improcedente'=>'dark',
  'Jurídico'=>'warning','Indenização'=>'primary','Encerrada'=>'light',
];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <p class="text-muted mb-0">Laudos de produto: registro, análise e encaminhamento.</p>
  <div class="d-flex gap-2">
    <form method="get" class="d-flex gap-2">
      <input type="hidden" name="r" value="reclamacoes">
      <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">Todos os status</option>
        <?php foreach ($statusLista as $s): ?>
          <option value="<?= e($s) ?>" <?= $filtroStatus === $s ? 'selected' : '' ?>><?= e($s) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <button class="btn btn-success" onclick="Reclamacoes.nova()"><i class="bi bi-plus-lg me-1"></i>Nova Reclamação</button>
  </div>
</div>

<div class="card"><div class="table-responsive">
  <table class="table table-hover align-middle mb-0">
    <thead class="table-light"><tr>
      <th>Data</th><th>Produtor</th><th class="d-none d-md-table-cell">Tipo</th>
      <th class="d-none d-lg-table-cell">Produto</th><th>Problema</th><th>Status</th><th class="text-end"></th>
    </tr></thead>
    <tbody>
      <?php if (!$reclamacoes): ?><tr><td colspan="7" class="text-center text-muted py-4">Nenhuma reclamação registrada.</td></tr><?php endif; ?>
      <?php foreach ($reclamacoes as $r): ?>
      <tr style="cursor:pointer" onclick="Reclamacoes.ver(<?= $r['id'] ?>)">
        <td class="small"><?= data_br($r['criado_em']) ?></td>
        <td class="fw-semibold"><?= e($r['cliente']) ?></td>
        <td class="d-none d-md-table-cell"><span class="badge text-bg-light border text-dark"><?= e($r['tipo']) ?></span></td>
        <td class="d-none d-lg-table-cell small"><?= e($r['produto'] ?? '—') ?></td>
        <td class="small"><?= e($r['problema'] ?? '—') ?>
          <?php if ($r['qtd_fotos'] > 0): ?><i class="bi bi-paperclip ms-1 text-muted" title="<?= $r['qtd_fotos'] ?> foto(s)"></i><?php endif; ?>
        </td>
        <td><span class="badge text-bg-<?= $statusCor[$r['status']] ?? 'secondary' ?> <?= ($statusCor[$r['status']] ?? '') === 'light' ? 'border text-dark' : '' ?>"><?= e($r['status']) ?></span></td>
        <td class="text-end"><i class="bi bi-chevron-right text-muted"></i></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div></div>

<!-- Modal: nova reclamação -->
<div class="modal fade" id="modalReclamacao" tabindex="-1">
  <div class="modal-dialog modal-lg modal-fullscreen-sm-down">
    <form class="modal-content" id="formReclamacao" onsubmit="return Reclamacoes.salvar(event)" enctype="multipart/form-data">
      <div class="modal-header"><h5 class="modal-title"><i class="bi bi-exclamation-octagon me-2 text-success"></i>Nova Reclamação</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label">Produtor *</label>
            <select name="cliente_id" class="form-select" required><option value="">Selecione…</option>
              <?php foreach ($clientes as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['nome']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6"><label class="form-label">Tipo *</label>
            <select name="tipo" class="form-select" required>
              <?php foreach ($tipos as $t): ?><option><?= e($t) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6"><label class="form-label">Produto</label>
            <select name="produto_id" class="form-select"><option value="0">—</option>
              <?php foreach ($produtos as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['nome']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6"><label class="form-label">Cultura</label>
            <select name="cultura_id" class="form-select"><option value="0">—</option>
              <?php foreach ($culturas as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['nome']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6"><label class="form-label">Lote</label><input name="lote" class="form-control"></div>
          <div class="col-md-6"><label class="form-label">Nota fiscal</label><input name="nota_fiscal" class="form-control"></div>
          <div class="col-12"><label class="form-label">Problema</label><input name="problema" class="form-control" placeholder="Ex.: baixa germinação, fitotoxidez…"></div>
          <div class="col-12"><label class="form-label">Descrição detalhada</label>
            <div class="campo-voz"><textarea name="descricao" class="form-control" rows="3"></textarea>
              <button type="button" class="btn-voz" title="Ditar por voz"><i class="bi bi-mic-fill"></i></button></div>
          </div>
          <div class="col-12"><label class="form-label"><i class="bi bi-camera me-1"></i>Fotos do problema</label>
            <input type="file" name="fotos[]" class="form-control" accept="image/*" capture="environment" multiple>
          </div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Registrar</button></div>
    </form>
  </div>
</div>

<!-- Modal: detalhe / movimentação -->
<div class="modal fade" id="modalReclamacaoDetalhe" tabindex="-1">
  <div class="modal-dialog modal-lg modal-fullscreen-sm-down">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"><i class="bi bi-clipboard2-pulse me-2 text-success"></i>Reclamação</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body" id="reclamacaoCorpo"></div>
    </div>
  </div>
</div>

<script src="assets/js/reclamacoes.js?v=<?= filemtime(dirname(__DIR__, 2) . '/public/assets/js/reclamacoes.js') ?>"></script>
