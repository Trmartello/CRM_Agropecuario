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
          <?php /* REGRA (teste de campo): a área da propriedade NÃO é digitada — é a SOMA dos CARs
                   (imóveis), com a área de plantio desenhada e os talhões. Aqui só se mostra. */ ?>
          <div class="col-4">
            <label class="form-label">Área total</label>
            <div class="form-control-plaintext fw-semibold" id="propAreaTotal">—</div>
            <div class="form-text" id="propAreaTotalNota">Soma dos CARs.</div>
          </div>
          <div class="col-4">
            <label class="form-label">Área de plantio</label>
            <div class="form-control-plaintext fw-semibold" id="propAreaPlantio">—</div>
            <div class="form-text">Desenhada nos croquis.</div>
          </div>
          <div class="col-4">
            <label class="form-label">Talhões</label>
            <div class="form-control-plaintext fw-semibold" id="propAreaTalhoes">—</div>
            <div class="form-text" id="propAreaTalhoesNota">Soma dos talhões.</div>
          </div>
          <div class="col-12">
            <label class="form-label">Município</label>
            <?= select_municipios('municipio', 'propMunicipio', 'nome') ?>
            <div class="form-text">Lista pré-cadastrada Município – UF (SC/RS/PR). Ao trazer o CAR de um imóvel, o município entra sozinho se ainda estiver vazio.</div>
          </div>
          <div class="col-12 form-text">
            O <strong>número do CAR</strong> fica em cada <strong>imóvel</strong> da propriedade (uma propriedade pode ter vários CARs).
            A propriedade nova já nasce com um imóvel: depois de salvar, abra o <strong>croqui</strong> dele para trazer a divisa do CAR — a área total é a soma das divisas.
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-danger me-auto d-none" id="btnExcluirPropriedade" onclick="Clientes.excluirPropriedade()"><i class="bi bi-trash me-1"></i>Excluir</button>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Salvar</button>
      </div>
    </form>
  </div>
</div>
