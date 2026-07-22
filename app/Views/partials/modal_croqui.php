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
          <div class="ms-auto d-flex flex-wrap gap-2 align-items-center">
            <span class="small" id="croquiArea"></span>
            <button class="btn btn-sm btn-outline-secondary" onclick="Croqui.desfazer()" title="Remover o último ponto"><i class="bi bi-arrow-counterclockwise"></i> Desfazer</button>
            <button class="btn btn-sm btn-outline-danger" onclick="Croqui.limpar()" title="Apagar o contorno deste talhão"><i class="bi bi-trash"></i> Limpar</button>
            <button class="btn btn-sm btn-success" onclick="Croqui.salvar()"><i class="bi bi-check-lg me-1"></i>Salvar croqui</button>
          </div>
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
