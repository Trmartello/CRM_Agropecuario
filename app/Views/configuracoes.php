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
};
</script>
