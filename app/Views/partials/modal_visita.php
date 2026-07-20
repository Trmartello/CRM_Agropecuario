<!-- Modal: nova visita técnica (wizard em etapas) -->
<div class="modal fade" id="modalVisita" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-xl modal-fullscreen-md-down">
    <form class="modal-content" id="formVisita" novalidate onsubmit="return Visitas.salvar(event)" oninput="Visitas.atualizarCompletude()" onchange="Visitas.atualizarCompletude()">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-clipboard2-plus me-2 text-success"></i><span id="visitaModalTitulo">Nova Visita Técnica</span></h5>
        <span class="badge text-bg-light border ms-2" id="visitaGeoStatus"><i class="bi bi-geo-alt me-1"></i>capturando GPS…</span>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <input type="hidden" name="id" value="0">
        <input type="hidden" name="finalizar_definitivo" value="0">
        <input type="hidden" name="latitude"><input type="hidden" name="longitude">

        <!-- Navegação das etapas (clique para preencher em qualquer ordem) -->
        <ul class="nav nav-pills nav-fill mb-3 etapas" id="visitaEtapas">
          <li class="nav-item"><button type="button" class="nav-link active" data-etapa="1" onclick="Visitas.irParaEtapa(1)">1. Identificação</button></li>
          <li class="nav-item"><button type="button" class="nav-link" data-etapa="2" onclick="Visitas.irParaEtapa(2)">2. Avaliação</button></li>
          <li class="nav-item"><button type="button" class="nav-link" data-etapa="3" onclick="Visitas.irParaEtapa(3)">3. Recomendação</button></li>
          <li class="nav-item"><button type="button" class="nav-link" data-etapa="4" onclick="Visitas.irParaEtapa(4)">4. Fotos & Extras</button></li>
          <li class="nav-item"><button type="button" class="nav-link" data-etapa="5" onclick="Visitas.irParaEtapa(5)"><i class="bi bi-cart me-1"></i>Comercial</button></li>
        </ul>

        <!-- Progresso dos campos obrigatórios para finalizar -->
        <div class="mb-3">
          <div class="d-flex align-items-center gap-2">
            <div class="progress flex-grow-1" style="height:8px" title="Campos obrigatórios preenchidos">
              <div class="progress-bar bg-danger" id="visitaCompletudeBar" role="progressbar" style="width:0%"></div>
            </div>
            <span class="small text-nowrap fw-semibold text-muted" id="visitaCompletudeLbl">Falta 100%</span>
          </div>
          <div class="small text-muted mt-1" id="visitaCompletudeFalta"></div>
        </div>

        <!-- ETAPA 1: Identificação -->
        <div class="etapa" data-etapa="1">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Cliente *</label>
              <select name="cliente_id" class="form-select" id="visitaCliente" required onchange="Visitas.carregarApoio(this.value)">
                <option value="">Selecione o produtor…</option>
                <?php foreach ($clientes as $c): ?>
                  <option value="<?= $c['id'] ?>"><?= e($c['nome']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Propriedade</label>
              <select name="propriedade_id" class="form-select" id="visitaPropriedade" onchange="Visitas.filtrarTalhoes()"></select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Talhão</label>
              <select name="talhao_id" class="form-select" id="visitaTalhao" onchange="Visitas.aoEscolherTalhao()"></select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Cultura <span class="text-danger" title="Obrigatório para finalizar">*</span></label>
              <select name="cultura_id" class="form-select" id="visitaCultura" onchange="Visitas.carregarModelos()">
                <option value="">—</option>
                <?php foreach ($culturas as $cu): ?>
                  <option value="<?= $cu['id'] ?>"><?= e($cu['nome']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Data e hora *</label>
              <input type="datetime-local" id="visitaDataHora" class="form-control" value="<?= date('Y-m-d\TH:i') ?>" required oninput="Visitas.sincronizarDataHora(this)">
              <input type="hidden" name="data_visita" value="<?= date('Y-m-d') ?>">
              <input type="hidden" name="hora" value="<?= date('H:i') ?>">
            </div>
            <div class="col-md-8">
              <label class="form-label">Objetivo <span class="text-danger" title="Obrigatório para finalizar">*</span></label>
              <input name="objetivo" class="form-control" placeholder="Ex.: acompanhamento fitossanitário">
            </div>
          </div>
          <!-- Linha do tempo da cultura do talhão (plantio → fase atual) -->
          <div id="visitaFenologia" class="mt-3"></div>
        </div>

        <!-- ETAPA 2: Avaliação técnica -->
        <div class="etapa d-none" data-etapa="2">
          <!-- Checklist da lavoura (manejos da fase fenológica atual) -->
          <div id="visitaChecklist"></div>
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Estágio da cultura</label>
              <input name="estagio_cultura" class="form-control" placeholder="Ex.: V4, R1, florescimento…">
            </div>
            <div class="col-md-8">
              <label class="form-label">Desenvolvimento <span class="text-danger" title="Obrigatório para finalizar">*</span></label>
              <div class="campo-voz"><textarea name="desenvolvimento" rows="1" class="form-control auto-crescer" oninput="App.autoCrescer(this)" placeholder="Condição geral da lavoura…"></textarea><button type="button" class="btn-voz" title="Ditar por voz" aria-label="Ditar por voz"><i class="bi bi-mic-fill"></i></button></div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Pragas</label>
              <div class="campo-voz"><textarea name="pragas" rows="1" class="form-control auto-crescer" oninput="App.autoCrescer(this)" placeholder="Percevejo, lagarta…"></textarea><button type="button" class="btn-voz" title="Ditar por voz" aria-label="Ditar por voz"><i class="bi bi-mic-fill"></i></button></div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Doenças</label>
              <div class="campo-voz"><textarea name="doencas" rows="1" class="form-control auto-crescer" oninput="App.autoCrescer(this)" placeholder="Ferrugem, mancha…"></textarea><button type="button" class="btn-voz" title="Ditar por voz" aria-label="Ditar por voz"><i class="bi bi-mic-fill"></i></button></div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Plantas daninhas</label>
              <div class="campo-voz"><textarea name="plantas_daninhas" rows="1" class="form-control auto-crescer" oninput="App.autoCrescer(this)" placeholder="Buva, azevém…"></textarea><button type="button" class="btn-voz" title="Ditar por voz" aria-label="Ditar por voz"><i class="bi bi-mic-fill"></i></button></div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Deficiência nutricional</label>
              <div class="campo-voz"><textarea name="deficiencia_nutricional" rows="1" class="form-control auto-crescer" oninput="App.autoCrescer(this)" placeholder="N, K, Mn…"></textarea><button type="button" class="btn-voz" title="Ditar por voz" aria-label="Ditar por voz"><i class="bi bi-mic-fill"></i></button></div>
            </div>
            <div class="col-md-4">
              <label class="form-label">Condições climáticas</label>
              <input name="condicoes_climaticas" class="form-control" placeholder="Ensolarado, 25°C">
            </div>
            <div class="col-md-8">
              <label class="form-label">Observações <span class="text-muted small">(use o microfone para ditar)</span></label>
              <div class="campo-voz"><textarea name="observacoes" class="form-control auto-crescer" rows="2" oninput="App.autoCrescer(this)"></textarea><button type="button" class="btn-voz" title="Ditar por voz" aria-label="Ditar por voz"><i class="bi bi-mic-fill"></i></button></div>
            </div>
          </div>
        </div>

        <!-- ETAPA 3: Recomendação técnica -->
        <div class="etapa d-none" data-etapa="3">
          <div class="mb-3">
            <label class="form-label"><i class="bi bi-journal-bookmark me-1"></i>Modelos padrão (selecione para carregar e ajuste o necessário)</label>
            <div id="visitaModelos" class="d-flex flex-wrap gap-2">
              <span class="text-muted small">Escolha a cultura na etapa 1 para listar os modelos.</span>
            </div>
          </div>
          <label class="form-label">Recomendação técnica <span class="text-danger" title="Obrigatório para finalizar">*</span> <span class="text-muted small">(texto livre — pode ditar pelo microfone)</span></label>
          <div class="campo-voz">
            <textarea name="recomendacao" class="form-control auto-crescer" rows="6" oninput="App.autoCrescer(this)" placeholder="Escreva ou carregue um modelo padrão…"></textarea>
            <button type="button" class="btn-voz" title="Ditar por voz" aria-label="Ditar por voz"><i class="bi bi-mic-fill"></i></button>
          </div>
        </div>

        <!-- ETAPA 4: Fotos e concorrência -->
        <div class="etapa d-none" data-etapa="4">
          <label class="form-label"><i class="bi bi-camera me-1"></i>Fotos da lavoura</label>
          <!-- Sem HEIC no accept: o iPhone converte para JPEG ao escolher da galeria (HEIC não abre no navegador) -->
          <input type="file" name="fotos[]" id="visitaFotos" class="d-none" accept="image/jpeg,image/png,image/webp" capture="environment" multiple onchange="App.previewFotosGrid(this, 'visitaFotosPreview')">
          <div class="dropzone-foto mb-2" onclick="document.getElementById('visitaFotos').click()">
            <div class="text-center py-4">
              <i class="bi bi-camera fs-2 text-success d-block mb-1"></i>
              <span class="fw-semibold text-success">Tirar foto ou anexar</span>
              <div class="small text-muted">toque para usar a câmera ou escolher (várias fotos)</div>
            </div>
          </div>
          <div id="visitaFotosPreview" class="d-flex flex-wrap gap-2 mb-4"></div>

          <div class="card border-secondary-subtle">
            <div class="card-header py-2"><i class="bi bi-shop me-1"></i><strong>Concorrência</strong> <span class="text-muted small">(opcional — o produtor compra de outro fornecedor?)</span></div>
            <div class="card-body row g-3">
              <div class="col-md-4">
                <label class="form-label">Concorrente</label>
                <input name="concorrente" class="form-control" placeholder="Nome do fornecedor">
              </div>
              <div class="col-md-4">
                <label class="form-label">Família de produto</label>
                <select name="concorrente_familia_id" class="form-select">
                  <option value="">—</option>
                  <?php foreach (\App\Core\Database::todos('SELECT * FROM familias_produto ORDER BY nome') as $fp): ?>
                    <option value="<?= $fp['id'] ?>"><?= e($fp['nome']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label">Condições oferecidas</label>
                <input name="concorrente_condicoes" class="form-control" placeholder="Preço, prazo…">
              </div>
            </div>
          </div>
        </div>

        <!-- ETAPA 5: Painel comercial do produtor -->
        <div class="etapa d-none" data-etapa="5">
          <div id="visitaPainelComercial" class="text-muted small">Selecione o cliente na etapa 1 para carregar os dados comerciais.</div>
        </div>
      </div>

      <div class="modal-footer justify-content-between">
        <button type="button" class="btn btn-outline-secondary" id="btnEtapaAnterior" onclick="Visitas.etapaAnterior()" disabled><i class="bi bi-arrow-left me-1"></i>Anterior</button>
        <div>
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn btn-outline-warning d-none" id="btnFinalizarDefinitivo" onclick="Visitas.finalizarDefinitivo()" title="Encerra a visita mesmo com o cadastro incompleto"><i class="bi bi-flag-checkered me-1"></i>Finalizar assim mesmo</button>
          <button type="button" class="btn btn-outline-success" id="btnEtapaProxima" onclick="Visitas.proximaEtapa()">Próxima<i class="bi bi-arrow-right ms-1"></i></button>
          <button type="submit" class="btn btn-success d-none" id="btnSalvarVisita"><i class="bi bi-check-lg me-1"></i>Salvar Visita</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Modal: identificação visual do estágio fenológico (abre ao clicar num estágio da timeline) -->
<div class="modal fade" id="modalEstagio" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h5 class="modal-title d-flex align-items-center gap-2">
          <span class="fen2-selo" id="estagioSelo"></span>
          <span id="estagioNome"></span>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body pt-2">
        <div class="d-flex align-items-center gap-2">
          <button type="button" class="btn btn-outline-success feno-nav" id="estagioAnterior"
                  onclick="Visitas.navegarEstagio(-1)" title="Fase anterior (menos avançada)"><i class="bi bi-chevron-left"></i></button>
          <div class="feno-figura flex-grow-1" id="estagioFigura"></div>
          <button type="button" class="btn btn-outline-success feno-nav" id="estagioProximo"
                  onclick="Visitas.navegarEstagio(1)" title="Próxima fase (mais avançada)"><i class="bi bi-chevron-right"></i></button>
        </div>
        <div class="text-center small text-muted mt-1" id="estagioJanela"></div>
        <div class="feno-carac mt-2" id="estagioCarac"></div>
      </div>
      <div class="modal-footer py-2 justify-content-between align-items-center">
        <span class="small text-muted"><i class="bi bi-arrow-left-right me-1"></i>Use as setas para comparar com a lavoura</span>
        <button type="button" class="btn btn-success btn-sm" onclick="Visitas.usarEstagio()">
          <i class="bi bi-check-lg me-1"></i>A lavoura está nesta fase
        </button>
      </div>
    </div>
  </div>
</div>
