<!-- Modal: "Plantar a área toda" — cria 1 talhão cobrindo a área de plantio do imóvel (v40 §3) -->
<div class="modal fade" id="modalPlantarArea" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" id="formPlantarArea" onsubmit="return Clientes.salvarPlantarArea(event)">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-grid-1x2 me-2 text-success"></i>Plantar a área toda — <span id="plantarAreaImovel"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body row g-3">
        <input type="hidden" name="imovel_id" value="0">
        <div class="col-12 small text-muted">
          Cria <strong>um único talhão</strong> com o mesmo contorno e a mesma área da <strong>área de plantio</strong> do imóvel
          (<span id="plantarAreaHa">—</span> ha). Use quando toda a área de plantio recebe uma cultura só.
          Para dividir (ex.: metade milho, metade pastagem), cadastre talhões e desenhe cada um no croqui.
        </div>
        <div class="col-md-6">
          <label class="form-label">Cultura *</label>
          <select name="cultura_id" class="form-select" required>
            <option value="">—</option>
            <?php foreach (($culturas ?? []) as $cu): ?>
              <option value="<?= (int) $cu['id'] ?>"><?= e($cu['nome']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label">Finalidade</label>
          <select name="finalidade_id" class="form-select">
            <option value="">—</option>
            <?php foreach (($finalidades ?? []) as $f): ?>
              <option value="<?= (int) $f['id'] ?>"><?= e($f['nome']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12">
          <label class="form-label">Nome do talhão</label>
          <input name="nome" class="form-control" value="Área toda" maxlength="120">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Criar talhão</button>
      </div>
    </form>
  </div>
</div>
