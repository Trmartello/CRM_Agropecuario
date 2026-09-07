<!-- Modal: croqui da propriedade (Fase 6A) — marcar contornos dos talhões -->
<div class="modal fade" id="modalCroqui" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-fullscreen">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h5 class="modal-title"><i class="bi bi-bounding-box-circles me-2 text-success"></i>Croqui — <span id="croquiPropNome"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button><!-- Croqui.fechar() roda no hidden.bs.modal (cobre Esc também) -->
      </div>
      <div class="modal-body d-flex flex-column p-2 gap-2">
        <?php /* FLUXO GUIADO (v45): 1 Divisa (CAR ajustado) → 2 Áreas de plantio (dentro da divisa) → 3 Talhões (dentro de uma área) */ ?>
        <div class="d-flex flex-wrap align-items-center gap-1 croqui-etapas">
          <div class="btn-group btn-group-sm" role="group" aria-label="Etapas do croqui">
            <button type="button" class="btn btn-outline-secondary" id="croquiEtapa1" onclick="Croqui.irEtapa(1)"><i class="croqui-etapa-icone bi bi-circle me-1"></i>1 Divisa (CAR)</button>
            <button type="button" class="btn btn-outline-secondary" id="croquiEtapa2" onclick="Croqui.irEtapa(2)"><i class="croqui-etapa-icone bi bi-circle me-1"></i>2 Áreas de plantio</button>
            <button type="button" class="btn btn-outline-secondary" id="croquiEtapa3" onclick="Croqui.irEtapa(3)"><i class="croqui-etapa-icone bi bi-circle me-1"></i>3 Talhões</button>
          </div>
          <div class="ms-auto d-flex gap-1">
            <button type="button" class="btn btn-sm btn-outline-secondary d-none" id="croquiEtapaAnterior" onclick="Croqui.irEtapa(Croqui.etapa - 1)"><i class="bi bi-arrow-left"></i> Voltar</button>
            <button type="button" class="btn btn-sm btn-outline-success d-none" id="croquiEtapaProxima" onclick="Croqui.irEtapa(Croqui.etapa + 1)">Próxima etapa <i class="bi bi-arrow-right"></i></button>
          </div>
        </div>
        <div class="small text-muted" id="croquiEtapaDica"></div>
        <div class="d-flex flex-wrap align-items-center gap-2">
          <select id="croquiTalhao" class="form-select form-select-sm" style="max-width:230px" onchange="Croqui.trocarTalhao()"></select>
          <?php /* Rádios do MODO ficam escondidos: o GPS (trocarModo) e o toque no mapa leem o estado deles.
                   Na tela, um split button — o botão principal mostra o modo ATIVO (toque manual por padrão). */ ?>
          <input type="radio" class="d-none" name="croquiModo" id="croquiModoGps" value="gps" onchange="Croqui.trocarModo()">
          <input type="radio" class="d-none" name="croquiModo" id="croquiModoManual" value="manual" checked onchange="Croqui.trocarModo()">
          <div class="btn-group btn-group-sm" role="group" title="Como marcar os pontos">
            <button type="button" class="btn btn-success" id="croquiModoBtn" onclick="Croqui.setModo('manual')"><i class="bi bi-hand-index-thumb me-1"></i>Manual (toque)</button>
            <button type="button" class="btn btn-success dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false"><span class="visually-hidden">Trocar o modo</span></button>
            <ul class="dropdown-menu">
              <li><button type="button" class="dropdown-item" onclick="Croqui.setModo('manual')"><i class="bi bi-hand-index-thumb me-2"></i>Manual (toque)<div class="small text-muted">Toque nos cantos sobre o satélite</div></button></li>
              <li><button type="button" class="dropdown-item" onclick="Croqui.setModo('gps')"><i class="bi bi-geo-alt me-2"></i>Caminhar a divisa<div class="small text-muted">Um ponto a cada ~10 m pelo GPS</div></button></li>
            </ul>
          </div>
          <span id="croquiGpsStatus" class="badge text-bg-light border d-none"></span>
          <?php /* CAR: "CAR no mapa" é o principal (fica ativo sozinho quando há base na região); os demais no menu. */ ?>
          <div class="btn-group btn-group-sm" role="group" title="Divisa oficial do CAR" id="croquiCarGrupo">
            <button type="button" id="croquiCarMapaBtn" class="btn btn-outline-warning" onclick="Croqui.toggleCarLayer()"
                    title="Mostrar os imóveis do CAR no mapa e tocar na área do produtor para adotar a divisa"><i class="bi bi-grid-3x3-gap me-1"></i>CAR no mapa</button>
            <button type="button" class="btn btn-outline-warning dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false"><span class="visually-hidden">Mais opções do CAR</span></button>
            <ul class="dropdown-menu">
              <li><button type="button" class="dropdown-item" onclick="Croqui.carAqui()"><i class="bi bi-crosshair me-2"></i>CAR aqui<div class="small text-muted">Pela sua posição (GPS), na propriedade</div></button></li>
              <li><button type="button" class="dropdown-item" onclick="Croqui.carDaSede()"><i class="bi bi-house-door me-2"></i>CAR pela sede<div class="small text-muted">Pela sede cadastrada, no escritório</div></button></li>
              <li><hr class="dropdown-divider"></li>
              <li><button type="button" class="dropdown-item" id="croquiBaixarMapaBtn" onclick="Croqui.baixarMapa()"><i class="bi bi-cloud-arrow-down me-2"></i>Baixar mapa<div class="small text-muted">Guardar o satélite desta área para usar sem sinal</div></button></li>
            </ul>
          </div>
          <span id="croquiMapaStatus" class="badge text-bg-light border d-none"></span>
          <div class="ms-auto d-flex flex-wrap gap-2 align-items-center">
            <span class="small" id="croquiArea"></span>
            <?php /* Alvo = talhão: o desenho pode virar a ÁREA DE PLANTIO (cadastro errado). Alvo = área de plantio: copiar o desenho de um talhão. */ ?>
            <button type="button" class="btn btn-sm btn-outline-success d-none" id="croquiVirarPlantioBtn" onclick="Croqui.talhaoParaPlantio()"
                    title="Este desenho é a área de plantio do imóvel, não um talhão"><i class="bi bi-arrow-right-circle"></i> Virar área de plantio</button>
            <button type="button" class="btn btn-sm btn-outline-secondary d-none" id="croquiRenomearBtn" onclick="Croqui.renomearAreaPlantio()"
                    title="Renomear esta área de plantio"><i class="bi bi-pencil"></i> Renomear</button>
            <div class="btn-group btn-group-sm d-none" id="croquiCopiarTalhaoWrap">
              <button type="button" class="btn btn-outline-success dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"
                      title="Carregar o desenho de um talhão como área de plantio (ajuste e salve)"><i class="bi bi-copy"></i> Copiar de talhão</button>
              <ul class="dropdown-menu" id="croquiCopiarTalhaoMenu"></ul>
            </div>
            <button class="btn btn-sm btn-outline-secondary" onclick="Croqui.desfazer()" title="Remover o último ponto"><i class="bi bi-arrow-counterclockwise"></i> Desfazer</button>
            <button class="btn btn-sm btn-outline-danger" onclick="Croqui.limpar()" title="Apagar o contorno deste talhão"><i class="bi bi-trash"></i> Limpar</button>
            <button class="btn btn-sm btn-success" onclick="Croqui.salvar()"><i class="bi bi-check-lg me-1"></i>Salvar croqui</button>
          </div>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-1">
          <span class="small text-muted me-1"><i class="bi bi-geo-alt-fill text-danger"></i> Ir para:</span>
          <?php /* Município – UF da lista pré-cadastrada com busca digitável (a UF vem da escolha, no hidden) */ ?>
          <div style="flex:1 1 200px;max-width:280px">
            <?= select_municipios('ir_municipio', 'croquiIrMun', 'nome', 'form-select form-select-sm select-busca', 'data-placeholder="Município (digite para buscar)" onchange="Croqui.ufDoIrPara()"') ?>
          </div>
          <input type="hidden" id="croquiIrUf">
          <input id="croquiIrLinha" class="form-control form-control-sm" style="max-width:200px" placeholder="Linha (localidade rural)"
                 onkeydown="if(event.key==='Enter'){event.preventDefault();Croqui.irParaArea();}">
          <button class="btn btn-sm btn-outline-secondary" onclick="Croqui.irParaArea()" title="Centralizar o mapa nessa região"><i class="bi bi-search me-1"></i>Ir</button>
        </div>
        <div id="croquiPalco" class="croqui-palco flex-grow-1"></div>
        <?php /* Barra flutuante da TELA CHEIA (só aparece com #modalCroqui.croqui-cheio) */ ?>
        <div class="croqui-cheio-barra" id="croquiCheioBarra">
          <span class="croqui-cheio-area" id="croquiCheioArea"></span>
          <button class="btn btn-sm btn-outline-secondary" onclick="Croqui.desfazer()" title="Desfazer a última ação"><i class="bi bi-arrow-counterclockwise"></i></button>
          <button class="btn btn-sm btn-success" onclick="Croqui.salvar()"><i class="bi bi-check-lg me-1"></i>Salvar</button>
          <button class="btn btn-sm btn-outline-dark" onclick="Croqui.telaCheia(false)" title="Voltar à tela normal"><i class="bi bi-fullscreen-exit me-1"></i>Sair</button>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-3">
          <div class="form-check form-check-sm mb-0">
            <input class="form-check-input" type="checkbox" id="croquiUsarArea" checked>
            <label class="form-check-label small" for="croquiUsarArea">Usar a área medida como área oficial do imóvel</label>
          </div>
          <span id="croquiTotais" class="small text-muted"></span>
          <div id="croquiLegenda" class="d-flex flex-wrap gap-2 small ms-auto"></div>
        </div>
        <div class="small text-muted">
          <i class="bi bi-info-circle me-1"></i>O croqui segue <strong>três etapas presas uma à outra</strong>: a divisa do CAR ajustada por você é o limite das áreas de plantio, e cada área de plantio é o limite dos seus talhões — pontos fora são puxados para a borda e nenhuma linha atravessa o limite (laranja).
          <strong>Manual</strong>: toque sobre a imagem de satélite para marcar cada canto (arraste o mapa para navegar,
          use +/− ou a roda do mouse para o zoom, <strong>⤢ para o mapa ocupar a tela toda</strong>, arraste um ponto para ajustar, dê <strong>dois toques sobre a linha ciano para inserir um ponto</strong> ali e refinar, e <strong>dois toques em um ponto para removê-lo</strong>). O que você desenha fica em <strong>ciano</strong>; as linhas do CAR ficam em amarelo (na etapa 1, com o <em>CAR no mapa</em> ligado, tocar dentro de um imóvel amarelo adota aquela divisa). <strong>Caminhar a divisa</strong>: ande pelo perímetro —
          o app marca um ponto a cada ~10 m pelo GPS, mesmo sem sinal (o salvar entra na fila).
          <strong>Sem sinal na propriedade?</strong> Antes de sair, com wi-fi, enquadre a área e toque em <strong>Baixar mapa</strong>:
          a imagem de satélite fica guardada no aparelho e o mapa abre no campo mesmo sem internet.
        </div>
      </div>
    </div>
  </div>
</div>
