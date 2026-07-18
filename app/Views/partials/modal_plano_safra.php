<!-- Modal: plano de safra (intenção de plantio) -->
<div class="modal fade" id="modalPlanoSafra" tabindex="-1">
  <div class="modal-dialog modal-fullscreen-sm-down">
    <form class="modal-content" id="formPlanoSafra" onsubmit="return Clientes.salvarPlanoSafra(event)">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-calendar3 me-2 text-success"></i>Intenção de Plantio</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="cliente_id" value="0">
        <div class="mb-3">
          <label class="form-label">Propriedade</label>
          <select name="propriedade_id" class="form-select" id="planoPropriedade">
            <option value="">—</option>
          </select>
        </div>
        <div class="row g-3">
          <div class="col-6">
            <label class="form-label">Cultura *</label>
            <select name="cultura_id" class="form-select" required>
              <option value="">Selecione…</option>
              <?php foreach (($culturas ?? []) as $cu): ?>
                <option value="<?= $cu['id'] ?>"><?= e($cu['nome']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label">Área (ha) *</label>
            <input name="area_ha" class="form-control" inputmode="decimal" required>
          </div>
        </div>
        <p class="small text-muted mt-3 mb-0">
          A intenção de plantio projeta a demanda de insumos por família e alimenta os gatilhos do calendário agronômico.
        </p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Salvar</button>
      </div>
    </form>
  </div>
</div>
