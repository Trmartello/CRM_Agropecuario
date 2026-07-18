<!-- Modal: propriedade -->
<div class="modal fade" id="modalPropriedade" tabindex="-1">
  <div class="modal-dialog modal-fullscreen-sm-down">
    <form class="modal-content" id="formPropriedade" onsubmit="return Clientes.salvarPropriedade(event)">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-house me-2 text-success"></i>Propriedade</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id" value="0">
        <input type="hidden" name="cliente_id" value="0">
        <div class="mb-3">
          <label class="form-label">Nome da propriedade *</label>
          <input name="nome" class="form-control" required>
        </div>
        <div class="row g-3">
          <div class="col-6">
            <label class="form-label">Área (ha)</label>
            <input name="area_ha" class="form-control" inputmode="decimal">
          </div>
          <div class="col-6">
            <label class="form-label">Município</label>
            <input name="municipio" class="form-control">
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
