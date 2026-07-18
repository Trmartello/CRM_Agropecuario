<div class="row g-3">
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-image me-2 text-success"></i><strong>Logo do aplicativo</strong></div>
      <div class="card-body">
        <p class="text-muted small">Exibida na barra lateral e na tela de login. Envie a logo oficial da Copérdia (PNG, JPG, WEBP ou SVG — fundo transparente fica melhor; máx. 2 MB).</p>
        <div class="text-center mb-3 p-3 bg-light rounded">
          <img src="<?= e($logoAtual) ?>" alt="Logo atual" style="max-height:110px;max-width:100%">
          <div class="small text-muted mt-1"><?= $logoPersonalizada ? 'Logo personalizada' : 'Logo padrão do sistema' ?></div>
        </div>
        <form onsubmit="return Config.enviar(event, 'logo_aplicacao')" class="d-flex gap-2 flex-wrap">
          <input type="file" name="imagem" class="form-control flex-grow-1" accept=".png,.jpg,.jpeg,.webp,.svg" required style="min-width:200px">
          <button class="btn btn-success"><i class="bi bi-upload me-1"></i>Enviar</button>
          <?php if ($logoPersonalizada): ?>
            <button type="button" class="btn btn-outline-secondary" onclick="Config.restaurar('logo_aplicacao')"><i class="bi bi-arrow-counterclockwise me-1"></i>Padrão</button>
          <?php endif; ?>
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
};
</script>
