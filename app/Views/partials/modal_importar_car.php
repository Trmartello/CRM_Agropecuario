<!-- Modal: importar o zip do imóvel baixado do SICAR (divisa + camadas ambientais — v49) -->
<div class="modal fade" id="modalImportarCar" tabindex="-1">
  <div class="modal-dialog modal-fullscreen-sm-down">
    <form class="modal-content" id="formImportarCar" onsubmit="return Clientes.enviarImportarCar(event)" enctype="multipart/form-data">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-cloud-download me-2 text-success"></i>Importar o CAR do imóvel</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="imovel_id" value="0">
        <p class="small text-muted mb-2">Na consulta pública do SICAR, abra o imóvel e use <strong>Baixar dados → Shapefile</strong>. O arquivo <code>.zip</code> traz a divisa oficial e, por camada, APP, Reserva Legal, Vegetação nativa, Servidão e Cobertura do solo.</p>
        <div class="mb-3">
          <label class="form-label">Arquivo .zip do SICAR *</label>
          <input type="file" name="arquivo" class="form-control" accept=".zip,application/zip" required>
        </div>
        <div class="form-check mb-2">
          <input class="form-check-input" type="checkbox" name="camadas" value="1" id="importarCarCamadas" checked>
          <label class="form-check-label" for="importarCarCamadas">
            <strong>Importar as camadas ambientais como áreas de não plantio</strong>
            <div class="form-text mt-0">APP, Reserva Legal, Vegetação nativa, Servidão (estradas) e Hidrografia viram áreas de não plantio do imóvel, descontadas das áreas de plantio e dos talhões. Elas se sobrepõem entre si (a APP fica dentro da mata) — o desconto é pela união. Reimportar substitui as camadas do CAR; o que você desenhou à mão fica.</div>
          </label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="consolidada" value="1" id="importarCarConsolidada">
          <label class="form-check-label" for="importarCarConsolidada">
            <strong>Criar as áreas de plantio a partir da "Área Consolidada" do CAR</strong>
            <div class="form-text mt-0" id="importarCarConsolidadaDica">Cada parte da área consolidada (uso já aberto) vira uma área de plantio (lavoura) para você ajustar e lançar os talhões. Só em imóvel que ainda não tem áreas de plantio.</div>
          </label>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-success" id="btnImportarCar"><i class="bi bi-cloud-download me-1"></i>Importar</button>
      </div>
    </form>
  </div>
</div>
