<!-- Modal: talhão -->
<div class="modal fade" id="modalTalhao" tabindex="-1">
  <div class="modal-dialog modal-fullscreen-sm-down">
    <form class="modal-content" id="formTalhao" onsubmit="return Clientes.salvarTalhao(event)">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-grid-3x3-gap me-2 text-success"></i>Talhão</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id" value="0">
        <input type="hidden" name="propriedade_id" value="0">
        <div class="mb-3">
          <label class="form-label">Nome do talhão *</label>
          <input name="nome" class="form-control" required>
        </div>
        <div class="row g-3">
          <div class="col-6">
            <label class="form-label">Área (ha)</label>
            <input name="area_ha" class="form-control" inputmode="decimal">
          </div>
          <div class="col-6">
            <label class="form-label">Cultura</label>
            <select name="cultura_id" class="form-select" id="talhaoCultura">
              <option value="">—</option>
              <?php foreach (($culturas ?? []) as $cu): ?>
                <option value="<?= $cu['id'] ?>"><?= e($cu['nome']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Salvar</button>
      </div>
    </form>
  </div>
</div>
