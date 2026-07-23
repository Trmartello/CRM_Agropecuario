<!-- Modal: croqui da propriedade (Fase 6A) — marcar contornos dos talhões -->
<div class="modal fade" id="modalCroqui" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-fullscreen">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h5 class="modal-title"><i class="bi bi-bounding-box-circles me-2 text-success"></i>Croqui — <span id="croquiPropNome"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button><!-- Croqui.fechar() roda no hidden.bs.modal (cobre Esc também) -->
      </div>
      <div class="modal-body d-flex flex-column p-2 gap-2">
        <div class="d-flex flex-wrap align-items-center gap-2">
          <select id="croquiTalhao" class="form-select form-select-sm" style="max-width:230px" onchange="Croqui.trocarTalhao()"></select>
          <div class="btn-group btn-group-sm" role="group" title="Como marcar os pontos">
            <input type="radio" class="btn-check" name="croquiModo" id="croquiModoGps" value="gps" onchange="Croqui.trocarModo()">
            <label class="btn btn-outline-success" for="croquiModoGps"><i class="bi bi-geo-alt me-1"></i>Caminhar a divisa</label>
            <input type="radio" class="btn-check" name="croquiModo" id="croquiModoManual" value="manual" checked onchange="Croqui.trocarModo()">
            <label class="btn btn-outline-success" for="croquiModoManual"><i class="bi bi-hand-index-thumb me-1"></i>Manual (toque)</label>
          </div>
          <span id="croquiGpsStatus" class="badge text-bg-light border d-none"></span>
          <button class="btn btn-sm btn-outline-primary" onclick="Croqui.carAqui()"
                  title="Identificar o imóvel pela sua posição (GPS) na base do CAR e puxar a divisa oficial"><i class="bi bi-crosshair me-1"></i>CAR aqui</button>
          <button class="btn btn-sm btn-outline-primary" onclick="Croqui.carDaSede()"
                  title="Puxar a divisa oficial do CAR pela posição da sede (sem GPS) — para preparar a divisa no escritório, antes de ir à propriedade"><i class="bi bi-house-door me-1"></i>CAR pela sede</button>
          <button id="croquiCarMapaBtn" class="btn btn-sm btn-outline-warning" onclick="Croqui.toggleCarLayer()"
                  title="Mostrar os imóveis do CAR no mapa e tocar na área do produtor para adotar a divisa"><i class="bi bi-grid-3x3-gap me-1"></i>CAR no mapa</button>
          <div class="ms-auto d-flex flex-wrap gap-2 align-items-center">
            <span class="small" id="croquiArea"></span>
            <button class="btn btn-sm btn-outline-secondary" onclick="Croqui.desfazer()" title="Remover o último ponto"><i class="bi bi-arrow-counterclockwise"></i> Desfazer</button>
            <button class="btn btn-sm btn-outline-danger" onclick="Croqui.limpar()" title="Apagar o contorno deste talhão"><i class="bi bi-trash"></i> Limpar</button>
            <button class="btn btn-sm btn-success" onclick="Croqui.salvar()"><i class="bi bi-check-lg me-1"></i>Salvar croqui</button>
          </div>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-1">
          <span class="small text-muted me-1"><i class="bi bi-geo-alt-fill text-danger"></i> Ir para:</span>
          <input id="croquiIrMun" class="form-control form-control-sm" style="max-width:180px" placeholder="Município"
                 onkeydown="if(event.key==='Enter'){event.preventDefault();Croqui.irParaArea();}">
          <input id="croquiIrUf" class="form-control form-control-sm text-uppercase" style="max-width:64px" maxlength="2" placeholder="UF"
                 onkeydown="if(event.key==='Enter'){event.preventDefault();Croqui.irParaArea();}">
          <input id="croquiIrLinha" class="form-control form-control-sm" style="max-width:200px" placeholder="Linha (localidade rural)"
                 onkeydown="if(event.key==='Enter'){event.preventDefault();Croqui.irParaArea();}">
          <button class="btn btn-sm btn-outline-secondary" onclick="Croqui.irParaArea()" title="Centralizar o mapa nessa região"><i class="bi bi-search me-1"></i>Ir</button>
        </div>
        <div id="croquiPalco" class="croqui-palco flex-grow-1"></div>
        <div class="d-flex flex-wrap align-items-center gap-3">
          <div class="form-check form-check-sm mb-0">
            <input class="form-check-input" type="checkbox" id="croquiUsarArea">
            <label class="form-check-label small" for="croquiUsarArea">Usar a área medida como área oficial da propriedade</label>
          </div>
          <span id="croquiTotais" class="small text-muted"></span>
          <div id="croquiLegenda" class="d-flex flex-wrap gap-2 small ms-auto"></div>
        </div>
        <div class="small text-muted">
          <i class="bi bi-info-circle me-1"></i>Escolha <strong>Propriedade</strong> para marcar a divisa (área total) ou um talhão para a
          área de plantio. <strong>Manual</strong>: toque sobre a imagem de satélite para marcar cada canto (arraste o mapa para navegar,
          use +/− ou a roda do mouse para o zoom, arraste um ponto para ajustar). <strong>Caminhar a divisa</strong>: ande pelo perímetro —
          o app marca um ponto a cada ~10 m pelo GPS, mesmo sem sinal (a imagem some, o desenho continua; o salvar entra na fila).
        </div>
      </div>
    </div>
  </div>
</div>
