<div class="row g-3">
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-image me-2 text-success"></i><strong>Logo do aplicativo</strong></div>
      <div class="card-body">
        <p class="text-muted small">Exibida na barra lateral e na tela de login. Envie a logo oficial da Copérdia (PNG, JPG, WEBP ou SVG — fundo transparente fica melhor; máx. 2 MB). A imagem enviada é guardada no banco de dados e vira o padrão do sistema — permanece mesmo após atualizações.</p>
        <div class="text-center mb-3 p-3 bg-light rounded">
          <img src="<?= e($logoAtual) ?>" alt="Logo atual" style="max-height:110px;max-width:100%">
          <div class="small text-muted mt-1"><?= $logoPersonalizada ? 'Logo personalizada' : 'Logo padrão do sistema' ?></div>
        </div>
        <form onsubmit="return Config.enviar(event, 'logo_aplicacao')" class="d-flex gap-2 flex-wrap mb-4">
          <input type="file" name="imagem" class="form-control flex-grow-1" accept=".png,.jpg,.jpeg,.webp,.svg" required style="min-width:200px">
          <button class="btn btn-success"><i class="bi bi-upload me-1"></i>Enviar</button>
          <?php if ($logoPersonalizada): ?>
            <button type="button" class="btn btn-outline-secondary" onclick="Config.restaurar('logo_aplicacao')"><i class="bi bi-arrow-counterclockwise me-1"></i>Padrão</button>
          <?php endif; ?>
        </form>

        <h6 class="text-success"><i class="bi bi-sliders me-1"></i>Ajustes de exibição</h6>
        <form onsubmit="return Config.salvarAjustes(event)">
          <div class="mb-3">
            <label class="form-label d-flex justify-content-between">Largura no menu lateral <span class="text-muted" id="valSidebar"><?= $ajustes['sidebar_largura'] ?>px</span></label>
            <input type="range" class="form-range" name="sidebar_largura" min="60" max="240" step="5"
                   value="<?= $ajustes['sidebar_largura'] ?>" oninput="document.getElementById('valSidebar').textContent=this.value+'px'; Config.previa()">
          </div>
          <div class="mb-3">
            <label class="form-label d-flex justify-content-between">Largura na tela de login <span class="text-muted" id="valLogin"><?= $ajustes['login_largura'] ?>px</span></label>
            <input type="range" class="form-range" name="login_largura" min="100" max="340" step="5"
                   value="<?= $ajustes['login_largura'] ?>" oninput="document.getElementById('valLogin').textContent=this.value+'px'">
          </div>
          <div class="mb-3">
            <label class="form-label">Fundo atrás da logo no menu</label>
            <select name="fundo" class="form-select" onchange="Config.previa()">
              <option value="branco" <?= $ajustes['fundo'] === 'branco' ? 'selected' : '' ?>>Cartão branco (para logos com fundo transparente)</option>
              <option value="transparente" <?= $ajustes['fundo'] === 'transparente' ? 'selected' : '' ?>>Transparente (para logos que já têm fundo próprio)</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label d-flex justify-content-between">Arredondamento dos cantos <span class="text-muted" id="valRaio"><?= $ajustes['borda_raio'] ?>px</span></label>
            <input type="range" class="form-range" name="borda_raio" min="0" max="30" step="1"
                   value="<?= $ajustes['borda_raio'] ?>" oninput="document.getElementById('valRaio').textContent=this.value+'px'; Config.previa()">
            <div class="form-text">0 = cantos retos (a imagem aparece inteira, sem nenhum recorte).</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Posição da logo no menu</label>
            <select name="posicao" class="form-select" onchange="Config.previa()">
              <option value="acima" <?= $ajustes['posicao'] === 'acima' ? 'selected' : '' ?>>Acima do título CRM AGRO (empilhada)</option>
              <option value="lado" <?= $ajustes['posicao'] === 'lado' ? 'selected' : '' ?>>Ao lado do título CRM AGRO</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label small text-muted">Prévia no menu lateral</label>
            <div class="p-3 rounded" style="background:linear-gradient(180deg,#1b5e20,#123d15)">
              <div id="previaMarca" class="d-flex flex-column align-items-center gap-2 text-center">
                <span id="previaCartao" class="d-inline-block rounded" style="background:#fff;padding:.4rem .6rem;max-width:<?= $ajustes['sidebar_largura'] ?>px">
                  <img src="<?= e($logoAtual) ?>" style="width:100%" alt="Prévia">
                </span>
                <strong class="text-white">CRM AGRO</strong>
              </div>
            </div>
          </div>
          <button class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Salvar ajustes</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-window me-2 text-success"></i><strong>Ícone da aba do navegador</strong></div>
      <div class="card-body">
        <p class="text-muted small">O favicon que aparece na aba do navegador e nos favoritos. Ideal: imagem quadrada de 32×32 ou 48×48 (PNG ou ICO; máx. 2 MB).</p>
        <div class="text-center mb-3 p-3 bg-light rounded">
          <img src="<?= e($faviconAtual) ?>" alt="Favicon atual" style="width:48px;height:48px;object-fit:contain">
          <div class="small text-muted mt-1"><?= $faviconPersonalizado ? 'Ícone personalizado' : 'Ícone padrão do sistema' ?></div>
        </div>
        <form onsubmit="return Config.enviar(event, 'favicon_aplicacao')" class="d-flex gap-2 flex-wrap">
          <input type="file" name="imagem" class="form-control flex-grow-1" accept=".png,.ico,.svg,.jpg,.jpeg" required style="min-width:200px">
          <button class="btn btn-success"><i class="bi bi-upload me-1"></i>Enviar</button>
          <?php if ($faviconPersonalizado): ?>
            <button type="button" class="btn btn-outline-secondary" onclick="Config.restaurar('favicon_aplicacao')"><i class="bi bi-arrow-counterclockwise me-1"></i>Padrão</button>
          <?php endif; ?>
        </form>
        <p class="small text-muted mt-3 mb-0"><i class="bi bi-info-circle me-1"></i>O navegador guarda o favicon em cache: depois de trocar, feche e reabra a aba (ou Ctrl+Shift+R) para ver o novo ícone.</p>
      </div>
    </div>
  </div>

  <!-- Categorias de reembolso (KM/refeições) -->
  <div class="col-12">
    <div class="card">
      <div class="card-header d-flex align-items-center">
        <i class="bi bi-cash-stack me-2 text-success"></i><strong>Categorias de reembolso (KM e refeições)</strong>
        <button class="btn btn-sm btn-success ms-auto" onclick="Config.novaCategoria()"><i class="bi bi-plus-lg me-1"></i>Nova categoria</button>
      </div>
      <div class="card-body">
        <p class="text-muted small">Defina o valor pago por km rodado e o teto de refeição de cada categoria (ex.: Agrônomo, Extensionista, Vendedor, Gestor). Cada usuário recebe uma categoria na tela de <strong>Usuários</strong> — o valor da despesa é calculado automaticamente por ela.</p>
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>Categoria</th><th class="text-end">Valor por km</th><th class="d-none d-md-table-cell">Refeições (Café/Almoço/Lanche/Janta)</th><th>Situação</th><th class="text-end">Usuários</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($categoriasReembolso as $c): ?>
              <tr>
                <td class="fw-semibold"><?= e($c['nome']) ?></td>
                <td class="text-end"><?= moeda($c['valor_km']) ?></td>
                <td class="d-none d-md-table-cell small text-muted">
                  <?php $r = $c['refeicoes']; $fmt = fn($t) => isset($r[$t]) ? numero($r[$t], 2) : '—'; ?>
                  <?= $fmt('Café') ?> / <?= $fmt('Almoço') ?> / <?= $fmt('Lanche') ?> / <?= $fmt('Janta') ?>
                </td>
                <td><span class="badge text-bg-<?= $c['ativo'] ? 'success' : 'secondary' ?>"><?= $c['ativo'] ? 'Ativa' : 'Inativa' ?></span></td>
                <td class="text-end"><?= (int)$c['qtd_usuarios'] ?></td>
                <td class="text-end"><button class="btn btn-sm btn-outline-secondary" onclick='Config.editarCategoria(<?= json_attr($c) ?>)'><i class="bi bi-pencil"></i></button></td>
              </tr>
              <?php endforeach; ?>
              <?php if (!$categoriasReembolso): ?><tr><td colspan="6" class="text-muted text-center py-3">Nenhuma categoria cadastrada.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Backup do banco -->
  <div class="col-12">
    <div class="card">
      <div class="card-header"><i class="bi bi-database-down me-2 text-success"></i><strong>Backup do banco de dados</strong></div>
      <div class="card-body d-flex flex-wrap align-items-center gap-3">
        <div class="flex-grow-1 small text-muted">
          Baixa um arquivo <code>.sql</code> completo (estrutura + dados) para guardar fora do servidor
          (Drive, pendrive etc.). Recomendado ao menos <strong>1x por semana</strong>.
          As fotos/documentos ficam no volume do servidor e não entram neste arquivo.
        </div>
        <a class="btn btn-success" href="<?= url('backup/baixar') ?>"><i class="bi bi-download me-1"></i>Baixar backup agora</a>
      </div>
    </div>
  </div>

  <!-- Diagnóstico do armazenamento de fotos (volume) -->
  <div class="col-12">
    <div class="card">
      <div class="card-header"><i class="bi bi-hdd me-2 text-success"></i><strong>Armazenamento de fotos e arquivos</strong>
        <span class="text-muted small">diagnóstico do volume no servidor</span></div>
      <div class="card-body">
        <div class="d-flex flex-wrap gap-3 align-items-center mb-2">
          <code class="small"><?= e($armazenamento['caminho']) ?></code>
          <?php if ($armazenamento['gravavel']): ?>
            <span class="badge text-bg-success"><i class="bi bi-check-circle me-1"></i>Pasta gravável (volume OK)</span>
          <?php elseif ($armazenamento['existe']): ?>
            <span class="badge text-bg-danger"><i class="bi bi-x-circle me-1"></i>Pasta existe mas NÃO é gravável — confira o volume</span>
          <?php else: ?>
            <span class="badge text-bg-danger"><i class="bi bi-x-circle me-1"></i>Pasta não existe — volume não montado</span>
          <?php endif; ?>
        </div>
        <div class="table-responsive">
          <table class="table table-sm mb-2" style="max-width:560px">
            <thead class="table-light"><tr><th>Tipo</th><th class="text-end">No disco</th><th class="text-end">Perdidos</th></tr></thead>
            <tbody>
              <?php foreach ($armazenamento['detalhe'] as $d): ?>
              <tr>
                <td><?= e($d['rotulo']) ?></td>
                <td class="text-end text-success fw-semibold"><?= (int) $d['ok'] ?></td>
                <td class="text-end <?= $d['faltando'] > 0 ? 'text-danger fw-semibold' : 'text-muted' ?>"><?= (int) $d['faltando'] ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="small text-muted">
          "Perdidos" = registro no banco cujo arquivo não está mais no servidor — em geral fotos enviadas
          <strong>antes</strong> de o volume estar montado no caminho certo (foram apagadas num redeploy e não têm recuperação).
          As novas ficam no volume e sobrevivem aos deploys; para conferir, envie uma foto e recarregue esta tela.
        </div>
      </div>
    </div>
  </div>

  <!-- Fenologia das culturas (fases, imagens e recomendações por fase) -->
  <div class="col-12">
    <div class="card">
      <div class="card-header d-flex flex-wrap align-items-center gap-2">
        <span><i class="bi bi-flower1 me-2 text-success"></i><strong>Fenologia das culturas</strong>
          <span class="text-muted small">fases, imagens e recomendações da linha do tempo da lavoura</span></span>
        <div class="ms-auto d-flex gap-2">
          <select id="fenoCfgCultura" class="form-select form-select-sm" style="max-width:200px" onchange="FenoCfg.carregar()">
            <?php foreach ($culturas as $cu): ?>
              <option value="<?= $cu['id'] ?>"><?= e($cu['nome']) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-sm btn-success" onclick="FenoCfg.novaFase()"><i class="bi bi-plus-lg me-1"></i>Nova fase</button>
        </div>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr>
              <th style="width:60px">Ordem</th><th>Fase</th><th class="d-none d-md-table-cell">Janela (DAP)</th>
              <th class="d-none d-md-table-cell">Macrofase</th><th>Imagem</th><th>Recomendações</th><th class="text-end">Ações</th>
            </tr></thead>
            <tbody id="fenoCfgLista"><tr><td colspan="7" class="text-center text-muted py-3">Carregando…</td></tr></tbody>
          </table>
        </div>
      </div>
      <div class="card-footer small text-muted">
        A imagem personalizada (foto ou arte) substitui a ilustração padrão do sistema no cartão da fase que o
        técnico vê na visita. Sem imagem, vale o desenho padrão — e dá para voltar a ele a qualquer momento.
      </div>
    </div>
  </div>
</div>

<!-- Modal: fase fenológica (Configurações) -->
<div class="modal fade" id="modalFaseCfg" tabindex="-1">
  <div class="modal-dialog modal-lg modal-fullscreen-md-down">
    <form class="modal-content" id="formFaseCfg" onsubmit="return FenoCfg.salvarFase(event)">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-flower1 me-2 text-success"></i><span id="faseCfgTitulo">Nova fase</span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id" value="0">
        <input type="hidden" name="cultura_id" value="0">
        <div class="row g-3">
          <div class="col-6 col-md-2"><label class="form-label">Código *</label><input name="codigo" class="form-control" required placeholder="R1-R2"></div>
          <div class="col-6 col-md-4"><label class="form-label">Nome *</label><input name="nome" class="form-control" required placeholder="Florescimento"></div>
          <div class="col-6 col-md-2"><label class="form-label">Ordem</label><input type="number" name="ordem" class="form-control" min="0"></div>
          <div class="col-6 col-md-4"><label class="form-label">Macrofase (faixa)</label><input name="grupo" class="form-control" placeholder="Vegetativo, Reprodutivo…"></div>
          <div class="col-6 col-md-3"><label class="form-label">Início (DAP) *</label><input type="number" name="dias_inicio" class="form-control" min="0" required></div>
          <div class="col-6 col-md-3"><label class="form-label">Fim (DAP) *</label><input type="number" name="dias_fim" class="form-control" min="0" required></div>
          <div class="col-md-6"><label class="form-label">Descrição curta</label><input name="descricao" class="form-control" placeholder="Início e plena floração"></div>
          <div class="col-12">
            <label class="form-label">Como identificar no campo (características fisiológicas)</label>
            <div class="campo-voz"><textarea name="caracteristicas" class="form-control auto-crescer" oninput="App.autoCrescer(this)" rows="2" placeholder="O que o extensionista deve observar na planta para confirmar esta fase…"></textarea><button type="button" class="btn-voz" title="Ditar por voz" aria-label="Ditar por voz"><i class="bi bi-mic-fill"></i></button></div>
          </div>
          <div class="col-12">
            <div class="card border-success-subtle">
              <div class="card-header py-2"><i class="bi bi-image me-1"></i><strong>Imagem da fase</strong>
                <span class="text-muted small">foto real ou arte — aparece no cartão que o técnico abre na visita</span></div>
              <div class="card-body d-flex flex-wrap align-items-center gap-3">
                <div id="faseCfgPreview" style="max-width:220px" class="text-center"></div>
                <div class="flex-grow-1">
                  <input type="file" name="imagem" class="form-control" accept="image/jpeg,image/png,image/webp">
                  <div class="form-text">A imagem é comprimida no servidor. Sem imagem, vale a ilustração padrão do sistema.</div>
                  <button type="button" class="btn btn-sm btn-outline-secondary mt-1 d-none" id="btnImagemPadrao" onclick="FenoCfg.imagemPadrao()">
                    <i class="bi bi-arrow-counterclockwise me-1"></i>Voltar à imagem padrão do sistema
                  </button>
                </div>
              </div>
            </div>
          </div>
          <div class="col-12" id="faseCfgManejosBloco">
            <div class="card border-success-subtle">
              <div class="card-header py-2 d-flex flex-wrap align-items-center gap-2">
                <span><i class="bi bi-clipboard2-check me-1"></i><strong>Recomendações técnicas da fase</strong>
                  <span class="text-muted small">viram o checklist do técnico</span></span>
                <div class="ms-auto d-flex gap-2">
                  <button type="button" class="btn btn-sm btn-outline-success" onclick="FenoCfg.manejosPadrao()">
                    <i class="bi bi-stars me-1"></i>Adicionar recomendações padrão do sistema</button>
                  <button type="button" class="btn btn-sm btn-success" onclick="FenoCfg.novoManejo()"><i class="bi bi-plus-lg"></i></button>
                </div>
              </div>
              <ul class="list-group list-group-flush" id="faseCfgManejos"></ul>
              <div class="card-body py-2 d-none" id="faseCfgManejoForm">
                <div class="row g-2 align-items-end">
                  <input type="hidden" id="manejoCfgId" value="0">
                  <div class="col-md-4"><label class="form-label small mb-0">Título *</label><input id="manejoCfgTitulo" class="form-control form-control-sm"></div>
                  <div class="col-md-3"><label class="form-label small mb-0">Família (gatilho comercial)</label>
                    <select id="manejoCfgFamilia" class="form-select form-select-sm"><option value="">—</option>
                      <?php foreach ($familias as $f): ?><option value="<?= $f['id'] ?>"><?= e($f['nome']) ?></option><?php endforeach; ?>
                    </select></div>
                  <div class="col-md-4"><label class="form-label small mb-0">Orientação</label><input id="manejoCfgOrientacao" class="form-control form-control-sm"></div>
                  <div class="col-md-1 d-flex gap-1">
                    <button type="button" class="btn btn-sm btn-success" onclick="FenoCfg.salvarManejo()" title="Salvar recomendação"><i class="bi bi-check-lg"></i></button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>
        <button class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Salvar fase</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: categoria de reembolso -->
<div class="modal fade" id="modalCategoria" tabindex="-1">
  <div class="modal-dialog modal-fullscreen-sm-down">
    <form class="modal-content" id="formCategoria" onsubmit="return Config.salvarCategoria(event)">
      <div class="modal-header"><h5 class="modal-title"><i class="bi bi-cash-stack me-2 text-success"></i><span id="modalCategoriaTitulo">Nova categoria</span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="hidden" name="id" value="0">
        <div class="mb-3"><label class="form-label">Nome *</label><input name="nome" class="form-control" required placeholder="Ex.: Agrônomo"></div>
        <div class="row g-3">
          <div class="col-6"><label class="form-label">Valor por km (R$) *</label><input type="number" step="0.01" min="0" name="valor_km" class="form-control" required></div>
          <div class="col-6"><label class="form-label">Situação</label><select name="ativo" class="form-select"><option value="1">Ativa</option><option value="0">Inativa</option></select></div>
        </div>
        <hr>
        <label class="form-label fw-semibold">Reembolso de refeição por tipo (R$)</label>
        <p class="text-muted small mb-2">Valor máximo que a Copérdia paga por refeição desta categoria. Se a nota passar, o reembolso é limitado a este valor.</p>
        <div class="row g-3">
          <div class="col-6 col-md-3"><label class="form-label small mb-0">Café</label><input type="number" step="0.01" min="0" name="ref_cafe" class="form-control"></div>
          <div class="col-6 col-md-3"><label class="form-label small mb-0">Almoço</label><input type="number" step="0.01" min="0" name="ref_almoco" class="form-control"></div>
          <div class="col-6 col-md-3"><label class="form-label small mb-0">Lanche</label><input type="number" step="0.01" min="0" name="ref_lanche" class="form-control"></div>
          <div class="col-6 col-md-3"><label class="form-label small mb-0">Janta</label><input type="number" step="0.01" min="0" name="ref_janta" class="form-control"></div>
        </div>
        <input type="hidden" name="teto_refeicao" value="0">
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Salvar</button></div>
    </form>
  </div>
</div>

<script>
const Config = {
  async enviar(ev, chave) {
    ev.preventDefault();
    const fd = new FormData(ev.target);
    fd.append('chave', chave);
    try {
      await App.json('index.php?r=configuracoes/salvar-imagem', { method: 'POST', body: fd });
      App.alerta('Imagem atualizada com sucesso.');
      setTimeout(() => location.reload(), 700);
    } catch (e) { App.alerta(e.message, 'danger'); }
    return false;
  },
  async restaurar(chave) {
    try {
      const fd = new FormData();
      fd.append('chave', chave);
      await App.json('index.php?r=configuracoes/restaurar-padrao', { method: 'POST', body: fd });
      App.alerta('Imagem padrão restaurada.');
      setTimeout(() => location.reload(), 700);
    } catch (e) { App.alerta(e.message, 'danger'); }
  },
  previa() {
    const cartao = document.getElementById('previaCartao');
    const marca = document.getElementById('previaMarca');
    const largura = Number(document.querySelector('[name=sidebar_largura]').value);
    const fundo = document.querySelector('[name=fundo]').value;
    const posicao = document.querySelector('[name=posicao]').value;
    const lado = posicao === 'lado';
    const raio = document.querySelector('[name=borda_raio]').value + 'px';
    cartao.style.maxWidth = (lado ? Math.min(110, largura) : largura) + 'px';
    cartao.style.background = fundo === 'transparente' ? 'transparent' : '#fff';
    cartao.style.padding = fundo === 'transparente' ? '0' : '.4rem .6rem';
    cartao.style.borderRadius = raio;
    cartao.querySelector('img').style.borderRadius = raio;
    marca.classList.toggle('flex-column', !lado);
    marca.classList.toggle('flex-row', lado);
    marca.classList.toggle('justify-content-center', lado);
  },
  async salvarAjustes(ev) {
    ev.preventDefault();
    try {
      await App.json('index.php?r=configuracoes/salvar-ajustes', { method: 'POST', body: new FormData(ev.target) });
      App.alerta('Ajustes salvos — a logo já aparece no novo tamanho.');
      setTimeout(() => location.reload(), 700);
    } catch (e) { App.alerta(e.message, 'danger'); }
    return false;
  },
  novaCategoria() {
    const form = document.getElementById('formCategoria');
    form.reset();
    form.querySelector('[name=id]').value = 0;
    document.getElementById('modalCategoriaTitulo').textContent = 'Nova categoria';
    new bootstrap.Modal('#modalCategoria').show();
  },
  editarCategoria(c) {
    const form = document.getElementById('formCategoria');
    form.reset();
    form.querySelector('[name=id]').value = c.id;
    form.querySelector('[name=nome]').value = c.nome;
    form.querySelector('[name=valor_km]').value = c.valor_km;
    form.querySelector('[name=teto_refeicao]').value = c.teto_refeicao;
    form.querySelector('[name=ativo]').value = c.ativo;
    const r = c.refeicoes || {};
    form.querySelector('[name=ref_cafe]').value = r['Café'] ?? '';
    form.querySelector('[name=ref_almoco]').value = r['Almoço'] ?? '';
    form.querySelector('[name=ref_lanche]').value = r['Lanche'] ?? '';
    form.querySelector('[name=ref_janta]').value = r['Janta'] ?? '';
    document.getElementById('modalCategoriaTitulo').textContent = 'Editar categoria';
    new bootstrap.Modal('#modalCategoria').show();
  },
  async salvarCategoria(ev) {
    ev.preventDefault();
    try {
      await App.json('index.php?r=configuracoes/salvar-categoria', { method: 'POST', body: new FormData(ev.target) });
      bootstrap.Modal.getInstance('#modalCategoria').hide();
      App.alerta('Categoria salva.');
      setTimeout(() => location.reload(), 600);
    } catch (e) { App.alerta(e.message, 'danger'); }
    return false;
  },
};

/* ===== Fenologia das culturas: fases, imagens e recomendações ===== */
const FenoCfg = {
  estagios: [],
  atual: null,

  culturaId() { return Number(document.getElementById('fenoCfgCultura').value); },

  async carregar() {
    const alvo = document.getElementById('fenoCfgLista');
    try {
      const { estagios } = await App.json('index.php?r=fenologia/estagios&cultura_id=' + FenoCfg.culturaId());
      FenoCfg.estagios = estagios;
      alvo.innerHTML = estagios.length ? estagios.map(e => `
        <tr>
          <td>${Number(e.ordem)}</td>
          <td><span class="badge text-bg-success">${App.escapeHtml(e.codigo)}</span> ${App.escapeHtml(e.nome)}</td>
          <td class="d-none d-md-table-cell">${e.dias_inicio}–${e.dias_fim} dias</td>
          <td class="d-none d-md-table-cell">${App.escapeHtml(e.grupo || '—')}</td>
          <td>${Number(e.tem_imagem)
            ? '<span class="badge text-bg-primary"><i class="bi bi-camera me-1"></i>Personalizada</span>'
            : '<span class="badge text-bg-light border text-dark">Padrão do sistema</span>'}</td>
          <td>${e.manejos.length} recomendação(ões)</td>
          <td class="text-end">
            <button class="btn btn-sm btn-outline-secondary" onclick="FenoCfg.editarFase(${Number(e.id)})" title="Editar fase"><i class="bi bi-pencil"></i></button>
            <button class="btn btn-sm btn-outline-danger" onclick="FenoCfg.excluirFase(${Number(e.id)})" title="Excluir fase"><i class="bi bi-trash"></i></button>
          </td>
        </tr>`).join('')
        : '<tr><td colspan="7" class="text-center text-muted py-3">Nenhuma fase cadastrada para esta cultura — use "Nova fase".</td></tr>';
    } catch (e) { alvo.innerHTML = `<tr><td colspan="7" class="text-danger small py-3 text-center">${App.escapeHtml(e.message)}</td></tr>`; }
  },

  _abrirModal(titulo) {
    const form = document.getElementById('formFaseCfg');
    form.reset();
    form.querySelector('[name=cultura_id]').value = FenoCfg.culturaId();
    document.getElementById('faseCfgTitulo').textContent = titulo;
    document.getElementById('faseCfgManejoForm').classList.add('d-none');
    // getOrCreateInstance: reabrir com o modal já aberto NÃO pode criar 2ª
    // instância (deixava um backdrop órfão cobrindo a tela após salvar)
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalFaseCfg')).show();
    return form;
  },

  novaFase() {
    FenoCfg.atual = null;
    const form = FenoCfg._abrirModal('Nova fase — ' + document.querySelector('#fenoCfgCultura option:checked').textContent);
    form.querySelector('[name=id]').value = 0;
    form.querySelector('[name=ordem]').value = FenoCfg.estagios.length + 1;
    document.getElementById('faseCfgPreview').innerHTML = '<div class="small text-muted">Salve a fase para ver a ilustração padrão.</div>';
    document.getElementById('btnImagemPadrao').classList.add('d-none');
    document.getElementById('faseCfgManejosBloco').classList.add('d-none'); // manejos após salvar
  },

  editarFase(id) {
    const e = FenoCfg.estagios.find(x => Number(x.id) === Number(id));
    if (!e) return;
    FenoCfg.atual = e;
    const form = FenoCfg._abrirModal(`Fase ${e.codigo} — ${e.nome}`);
    ['id', 'codigo', 'nome', 'ordem', 'grupo', 'dias_inicio', 'dias_fim', 'descricao', 'caracteristicas'].forEach(n => {
      const campo = form.querySelector(`[name=${n}]`);
      if (campo) campo.value = e[n] ?? '';
    });
    FenoCfg._preview(e);
    document.getElementById('faseCfgManejosBloco').classList.remove('d-none');
    FenoCfg.renderManejos(e);
  },

  _preview(e) {
    const alvo = document.getElementById('faseCfgPreview');
    if (Number(e.tem_imagem)) {
      alvo.innerHTML = `<img src="index.php?r=arquivo/estagio&id=${Number(e.id)}&t=${Date.now()}" class="img-fluid rounded border" alt="Imagem da fase">
        <div class="small text-muted mt-1">Imagem personalizada em uso</div>`;
      document.getElementById('btnImagemPadrao').classList.remove('d-none');
    } else {
      alvo.innerHTML = (typeof FenologiaArte !== 'undefined' ? FenologiaArte.svg(FenoCfg.culturaId(), e) : '')
        + '<div class="small text-muted mt-1">Ilustração padrão do sistema</div>';
      document.getElementById('btnImagemPadrao').classList.add('d-none');
    }
  },

  async salvarFase(ev) {
    ev.preventDefault();
    try {
      const resp = await App.json('index.php?r=fenologia/salvar-estagio', { method: 'POST', body: new FormData(ev.target) });
      if (resp.aviso) App.alerta(resp.aviso, 'warning'); else App.alerta('Fase salva.');
      await FenoCfg.carregar();
      FenoCfg.editarFase(resp.id); // segue direto para imagem/recomendações
    } catch (e) { App.alerta(e.message, 'danger'); }
    return false;
  },

  async imagemPadrao() {
    if (!FenoCfg.atual || !confirm('Descartar a imagem personalizada e voltar à ilustração padrão do sistema?')) return;
    const fd = new FormData(); fd.append('id', FenoCfg.atual.id);
    try {
      await App.json('index.php?r=fenologia/imagem-padrao', { method: 'POST', body: fd });
      App.alerta('Fase de volta à ilustração padrão do sistema.');
      await FenoCfg.carregar();
      FenoCfg.editarFase(FenoCfg.atual.id);
    } catch (e) { App.alerta(e.message, 'danger'); }
  },

  async excluirFase(id) {
    if (!confirm('Excluir esta fase? As recomendações dela e as marcações de checklist já feitas em visitas serão removidas juntas.')) return;
    const fd = new FormData(); fd.append('id', id);
    try {
      await App.json('index.php?r=fenologia/excluir-estagio', { method: 'POST', body: fd });
      App.alerta('Fase excluída.');
      FenoCfg.carregar();
    } catch (e) { App.alerta(e.message, 'danger'); }
  },

  renderManejos(e) {
    document.getElementById('faseCfgManejos').innerHTML = e.manejos.length ? e.manejos.map(m => `
      <li class="list-group-item py-2 d-flex justify-content-between align-items-start gap-2">
        <div>
          <strong>${App.escapeHtml(m.titulo)}</strong>
          ${m.familia ? `<span class="badge text-bg-light border text-dark ms-1">${App.escapeHtml(m.familia)}</span>` : ''}
          ${m.orientacao ? `<div class="small text-muted">${App.escapeHtml(m.orientacao)}</div>` : ''}
        </div>
        <span class="btn-group">
          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="FenoCfg.editarManejo(${Number(m.id)})"><i class="bi bi-pencil"></i></button>
          <button type="button" class="btn btn-sm btn-outline-danger" onclick="FenoCfg.excluirManejo(${Number(m.id)})"><i class="bi bi-trash"></i></button>
        </span>
      </li>`).join('')
      : '<li class="list-group-item small text-muted py-2">Nenhuma recomendação — cadastre a sua ou use as padrão do sistema.</li>';
  },

  novoManejo() {
    document.getElementById('faseCfgManejoForm').classList.remove('d-none');
    document.getElementById('manejoCfgId').value = 0;
    document.getElementById('manejoCfgTitulo').value = '';
    document.getElementById('manejoCfgFamilia').value = '';
    document.getElementById('manejoCfgOrientacao').value = '';
    document.getElementById('manejoCfgTitulo').focus();
  },

  editarManejo(id) {
    const m = (FenoCfg.atual?.manejos || []).find(x => Number(x.id) === Number(id));
    if (!m) return;
    document.getElementById('faseCfgManejoForm').classList.remove('d-none');
    document.getElementById('manejoCfgId').value = m.id;
    document.getElementById('manejoCfgTitulo').value = m.titulo;
    document.getElementById('manejoCfgFamilia').value = m.familia_id ?? '';
    document.getElementById('manejoCfgOrientacao').value = m.orientacao ?? '';
  },

  async salvarManejo() {
    const fd = new FormData();
    fd.append('id', document.getElementById('manejoCfgId').value);
    fd.append('estagio_id', FenoCfg.atual.id);
    fd.append('titulo', document.getElementById('manejoCfgTitulo').value);
    fd.append('familia_id', document.getElementById('manejoCfgFamilia').value);
    fd.append('orientacao', document.getElementById('manejoCfgOrientacao').value);
    try {
      await App.json('index.php?r=fenologia/salvar-manejo', { method: 'POST', body: fd });
      document.getElementById('faseCfgManejoForm').classList.add('d-none');
      await FenoCfg._refrescarAtual();
      App.alerta('Recomendação salva.');
    } catch (e) { App.alerta(e.message, 'danger'); }
  },

  async excluirManejo(id) {
    if (!confirm('Excluir esta recomendação? Marcações de checklist já feitas com ela em visitas serão removidas.')) return;
    const fd = new FormData(); fd.append('id', id);
    try {
      await App.json('index.php?r=fenologia/excluir-manejo', { method: 'POST', body: fd });
      await FenoCfg._refrescarAtual();
      App.alerta('Recomendação excluída.');
    } catch (e) { App.alerta(e.message, 'danger'); }
  },

  async manejosPadrao() {
    const fd = new FormData(); fd.append('estagio_id', FenoCfg.atual.id);
    try {
      const r = await App.json('index.php?r=fenologia/manejos-padrao', { method: 'POST', body: fd });
      await FenoCfg._refrescarAtual();
      App.alerta(r.inseridos > 0 ? `${r.inseridos} recomendação(ões) padrão adicionada(s).` : 'As recomendações padrão desta fase já estão cadastradas.', 'info');
    } catch (e) { App.alerta(e.message, 'danger'); }
  },

  /** Recarrega a cultura e re-renderiza a fase aberta no modal. */
  async _refrescarAtual() {
    const id = FenoCfg.atual?.id;
    const { estagios } = await App.json('index.php?r=fenologia/estagios&cultura_id=' + FenoCfg.culturaId());
    FenoCfg.estagios = estagios;
    FenoCfg.atual = estagios.find(x => Number(x.id) === Number(id)) || null;
    if (FenoCfg.atual) FenoCfg.renderManejos(FenoCfg.atual);
    FenoCfg.carregar();
  },
};
document.addEventListener('DOMContentLoaded', () => {
  if (document.getElementById('fenoCfgLista')) FenoCfg.carregar();
});
</script>
