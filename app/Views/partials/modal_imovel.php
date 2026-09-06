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
            <input name="car_numero" class="form-control" placeholder="Ex.: SC-4204202-XXXX..." oninput="Clientes.municipioDoCar(this.value)">
            <div class="form-text">Permite abrir o imóvel na consulta pública e importar a divisa oficial (shapefile) no croqui. O município é identificado pelo próprio número.</div>
          </div>
          <div class="col-12">
            <label class="form-label">Apelido <span class="text-muted small">(opcional)</span></label>
            <input name="nome" class="form-control" placeholder="Ex.: Matrícula 1, Área da mãe, Terreno do rio">
          </div>
          <?php /* As áreas NUNCA são digitadas: a área total vem da divisa do CAR e a área de
                   plantio do desenho dentro dela (croqui). Aqui só se mostra o que já foi medido. */ ?>
          <div class="col-6">
            <label class="form-label">Área total</label>
            <div class="form-control-plaintext fw-semibold" id="imovelAreaMedidaValor">—</div>
            <div class="form-text" id="imovelAreaMedidaNota">Vem da divisa do CAR no croqui.</div>
          </div>
          <div class="col-6">
            <label class="form-label">Área de plantio</label>
            <div class="form-control-plaintext fw-semibold" id="imovelPlantioMedidaValor">—</div>
            <div class="form-text" id="imovelPlantioMedidaNota">Desenhada dentro da área do CAR.</div>
          </div>
          <div class="col-12 d-none" id="imovelNovoAviso">
            <div class="alert alert-success small mb-0"><i class="bi bi-map me-1"></i>Ao salvar, o croqui abre para trazer a divisa do CAR — a área total é medida por ela, e a área de plantio e os talhões são desenhados dentro dela.</div>
          </div>
          <?php /* Município: LISTA pré-cadastrada (IBGE — MunicipiosSul, SC/RS/PR), sem texto livre.
                   O nº do CAR ("UF-IBGE-hash") identifica o município sozinho (JS + servidor). */ ?>
          <div class="col-12">
            <label class="form-label">Município</label>
            <select name="cod_ibge" class="form-select" id="imovelMunicipio">
              <option value="">— selecione —</option>
              <?php foreach (\App\Services\MunicipiosSul::porUf() as $ufLista => $muns): ?>
                <optgroup label="<?= e($ufLista) ?>">
                  <?php foreach ($muns as $cod => $nomeMun): ?>
                    <option value="<?= e($cod) ?>"><?= e($nomeMun) ?></option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endforeach; ?>
            </select>
            <div class="form-text" id="imovelMunicipioNota">Preenchido sozinho pelo número do CAR; escolha na lista só se o imóvel ainda não tem CAR.</div>
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
