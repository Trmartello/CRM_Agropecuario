<!-- Modal: cadastro/edição de cliente -->
<div class="modal fade" id="modalCliente" tabindex="-1">
  <div class="modal-dialog modal-lg modal-fullscreen-md-down">
    <form class="modal-content" id="formCliente" onsubmit="return Clientes.salvar(event)">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-person-vcard me-2 text-success"></i><span id="modalClienteTitulo">Novo Cliente</span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id" value="0">
        <div class="row g-3">
          <div class="col-md-8">
            <label class="form-label">Nome do produtor *</label>
            <input name="nome" class="form-control" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Situação</label>
            <select name="situacao" class="form-select">
              <option>Associado</option>
              <option>Não Associado</option>
            </select>
          </div>
          <div class="col-md-4 d-flex align-items-end">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="prospecto" value="1" id="clienteProspecto">
              <label class="form-check-label" for="clienteProspecto">Prospecto (em prospecção)</label>
            </div>
          </div>
          <div class="col-md-4">
            <label class="form-label">CPF/CNPJ</label>
            <input name="cpf_cnpj" class="form-control">
          </div>
          <div class="col-md-4">
            <label class="form-label">Telefone</label>
            <input name="telefone" class="form-control" placeholder="(49) 9…">
          </div>
          <div class="col-md-4">
            <label class="form-label">E-mail</label>
            <input type="email" name="email" class="form-control">
          </div>
          <div class="col-md-6">
            <label class="form-label">Endereço</label>
            <input name="endereco" class="form-control">
          </div>
          <div class="col-md-3">
            <label class="form-label">Município</label>
            <input name="municipio" class="form-control">
          </div>
          <div class="col-md-1">
            <label class="form-label">UF</label>
            <input name="estado" class="form-control" maxlength="2" value="SC">
          </div>
          <div class="col-md-3">
            <label class="form-label">Linha <span class="text-muted small">(localidade)</span></label>
            <input name="linha" class="form-control" placeholder="Ex.: Linha São Roque">
          </div>
          <div class="col-md-2">
            <label class="form-label">Filial</label>
            <select name="filial_id" class="form-select">
              <option value="">—</option>
              <?php foreach ($filiais as $f): ?>
                <option value="<?= $f['id'] ?>"><?= e($f['nome']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-12">
            <div class="d-flex align-items-center gap-2">
              <button type="button" class="btn btn-outline-success btn-sm" onclick="App.capturarGeo('#formCliente')">
                <i class="bi bi-geo-alt me-1"></i>Capturar localização
              </button>
              <span class="small text-muted" id="geoStatusCliente">Latitude/Longitude preenchidas automaticamente.</span>
            </div>
            <input type="hidden" name="latitude"><input type="hidden" name="longitude">
          </div>

          <div class="col-12"><hr class="my-1"><strong class="text-success"><i class="bi bi-graph-up me-1"></i>Perfil comercial</strong></div>
          <div class="col-md-3">
            <label class="form-label">Nível tecnológico</label>
            <select name="nivel_tecnologico" class="form-select">
              <option>Alto</option><option selected>Médio</option><option>Baixo</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Volume compra anual (R$)</label>
            <input name="volume_compra_anual" class="form-control" inputmode="decimal">
          </div>
          <div class="col-md-3">
            <label class="form-label">Potencial de venda (R$)</label>
            <input name="potencial_venda" class="form-control" inputmode="decimal">
          </div>
          <div class="col-md-3">
            <label class="form-label">Limite de crédito (R$)</label>
            <input name="limite_credito" class="form-control" inputmode="decimal">
          </div>
          <?php if (App\Core\Permissoes::ehGestor()): ?>
          <div class="col-md-6">
            <label class="form-label">Responsável (carteira)</label>
            <select name="responsavel_id" class="form-select">
              <option value="">—</option>
              <?php foreach ($responsaveis as $rp): ?>
                <option value="<?= $rp['id'] ?>"><?= e($rp['nome']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Segmento (fixado pelo gestor)</label>
            <select name="segmento_manual" class="form-select">
              <option value="">Automático (calculado pelo sistema)</option>
              <?php foreach (\App\Services\SegmentacaoService::ROTULOS as $sig => $rot): ?>
                <option value="<?= $sig ?>"><?= e($rot) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Salvar</button>
      </div>
    </form>
  </div>
</div>
