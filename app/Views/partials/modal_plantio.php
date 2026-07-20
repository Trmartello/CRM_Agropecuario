<!-- Modal: registrar plantio do talhão (Fase 6E) -->
<div class="modal fade" id="modalPlantio" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" id="formPlantio" onsubmit="return Plantios.salvar(event)">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-calendar-plus me-2 text-success"></i>Registrar Plantio — <span id="plantioTalhaoNome"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body row g-3">
        <input type="hidden" name="talhao_id">
        <div class="col-md-6">
          <label class="form-label">Cultura *</label>
          <select name="cultura_id" class="form-select" required>
            <option value="">—</option>
            <?php foreach ($culturas as $cu): ?>
              <option value="<?= $cu['id'] ?>"><?= e($cu['nome']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label">Data do plantio *</label>
          <input type="date" name="data_plantio" class="form-control" required max="<?= date('Y-m-d') ?>">
        </div>
        <div class="col-12">
          <label class="form-label">Cultivar <span class="text-muted small">(opcional)</span></label>
          <input name="cultivar" class="form-control" placeholder="Ex.: 58I60 IPRO">
        </div>
        <div class="col-12 small text-muted">
          A data do plantio ancora a <strong>linha do tempo da cultura</strong>: o sistema estima a fase
          fenológica, indica os manejos e monta o checklist do técnico nas visitas.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Registrar</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: encerrar plantio (colheita) -->
<div class="modal fade" id="modalColheita" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" id="formColheita" onsubmit="return Plantios.salvarColheita(event)">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-basket me-2 text-success"></i>Registrar Colheita — <span id="colheitaTalhaoNome"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body row g-3">
        <input type="hidden" name="id">
        <div class="col-md-6">
          <label class="form-label">Data da colheita</label>
          <input type="date" name="colhido_em" class="form-control" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Produtividade (sc/ha)</label>
          <input name="produtividade" class="form-control" inputmode="decimal" placeholder="Ex.: 68,5">
        </div>
        <div class="col-12 small text-muted">
          Encerra o ciclo do talhão. A produtividade entra no fechamento de safra do produtor.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Encerrar plantio</button>
      </div>
    </form>
  </div>
</div>
