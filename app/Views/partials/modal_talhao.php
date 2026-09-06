<!-- Modal: talhão -->
<div class="modal fade" id="modalTalhao" tabindex="-1">
  <div class="modal-dialog modal-fullscreen-sm-down">
    <form class="modal-content" id="formTalhao" onsubmit="return Clientes.salvarTalhao(event)">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-grid-3x3-gap me-2 text-success"></i><span id="modalTalhaoTitulo">Talhão</span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id" value="0">
        <input type="hidden" name="propriedade_id" value="0">
        <?php /* Talhão novo NASCE DESENHADO no croqui: o contorno vem aqui e a área é a medida
                 (o campo "Área (ha)" só aparece para talhão antigo sem desenho). */ ?>
        <input type="hidden" name="contorno" value="">
        <div id="talhaoAreaMedida" class="alert alert-success py-2 small d-none mb-3"></div>
        <div class="mb-3">
          <label class="form-label">Nome do talhão *</label>
          <input name="nome" class="form-control" required placeholder="Ex.: Silagem Norte, Várzea, Talhão 3">
        </div>
        <div class="row g-3">
          <div class="col-12" id="talhaoImovelWrap">
            <label class="form-label">Imóvel (CAR) *</label>
            <select name="imovel_id" class="form-select" id="talhaoImovel" required></select>
            <div class="form-text">O talhão fica dentro da divisa deste imóvel. Trocar o imóvel move o talhão.</div>
          </div>
          <div class="col-6" id="talhaoAreaWrap">
            <label class="form-label">Área (ha)</label>
            <input name="area_ha" class="form-control" inputmode="decimal">
            <div class="form-text">Talhão sem desenho. Desenhe no croqui para medir.</div>
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
          <div class="col-12">
            <label class="form-label">Finalidade</label>
            <select name="finalidade_id" class="form-select" id="talhaoFinalidade">
              <option value="">—</option>
              <?php foreach (($finalidades ?? []) as $f): ?>
                <option value="<?= (int) $f['id'] ?>"><?= e($f['nome']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Grão, silagem, pastagem... — é o que separa milho silagem de milho grão nos totais.</div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-danger me-auto d-none" id="btnExcluirTalhao" onclick="Clientes.excluirTalhao()"><i class="bi bi-trash me-1"></i>Excluir</button>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Salvar</button>
      </div>
    </form>
  </div>
</div>
