<!-- Modal: imóvel rural (CAR) da propriedade — v40 (docs/specs/propriedade-imoveis-plantio.md) -->
<div class="modal fade" id="modalImovel" tabindex="-1">
  <div class="modal-dialog modal-fullscreen-sm-down">
    <form class="modal-content" id="formImovel" onsubmit="return Clientes.salvarImovel(event)">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-geo me-2 text-success"></i><span id="modalImovelTitulo">Imóvel (CAR)</span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id" value="0">
        <input type="hidden" name="propriedade_id" value="0">
        <p class="text-muted small mb-3">Cada inscrição no CAR é um imóvel. Uma propriedade pode ter vários — cada um com a própria divisa, a própria área de plantio e os próprios talhões.</p>
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label">Número do CAR <span class="text-muted small">(SICAR)</span></label>
            <input name="car_numero" class="form-control" placeholder="Ex.: SC-4204202-XXXX...">
            <div class="form-text">Permite abrir o imóvel na consulta pública e importar a divisa oficial (shapefile) no croqui.</div>
          </div>
          <div class="col-12">
            <label class="form-label">Apelido <span class="text-muted small">(opcional)</span></label>
            <input name="nome" class="form-control" placeholder="Ex.: Matrícula 1, Área da mãe, Terreno do rio">
          </div>
          <div class="col-6">
            <label class="form-label">Área total (ha)</label>
            <input name="area_ha" class="form-control" inputmode="decimal">
            <div class="form-text">Do CAR. O croqui pode medir e assumir como oficial.</div>
          </div>
          <div class="col-6">
            <label class="form-label">Área de plantio (ha)</label>
            <input name="area_plantio_ha" class="form-control" inputmode="decimal">
            <div class="form-text">O que dá para plantar (fora mata, APP, reserva, sede).</div>
          </div>
          <div class="col-12">
            <label class="form-label">Município</label>
            <input name="municipio" class="form-control">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-danger me-auto d-none" id="btnExcluirImovel" onclick="Clientes.excluirImovel()"><i class="bi bi-trash me-1"></i>Excluir</button>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Salvar</button>
      </div>
    </form>
  </div>
</div>
