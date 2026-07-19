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
</script>
