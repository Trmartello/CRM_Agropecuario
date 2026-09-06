<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#1b5e20">
<title>Definir nova senha — CRM AGRO · Copérdia</title>
<?php use App\Services\ConfigService; use App\Core\Auth; ?>
<link rel="icon" href="<?= e(ConfigService::faviconAplicacao()) ?>">
<link rel="stylesheet" href="assets/vendor/bootstrap.min.css">
<link rel="stylesheet" href="assets/vendor/bootstrap-icons.min.css">
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="tela-login d-flex align-items-center justify-content-center min-vh-100">
<div class="card shadow-lg border-0" style="max-width:440px;width:100%">
  <div class="card-body p-4 p-md-5">
    <div class="text-center mb-3">
      <div class="rounded-3 py-3 px-2 mb-3" style="background:linear-gradient(160deg,#1b5e20,#2e7d32)">
        <img src="<?= e(ConfigService::logoAplicacao()) ?>" alt="Copérdia"
             style="width:<?= (int) ConfigService::obter('logo_login_largura', '170') ?>px;max-width:85%">
      </div>
      <h1 class="h5 mb-1"><i class="bi bi-shield-lock me-1 text-success"></i>Defina sua nova senha</h1>
      <p class="text-muted small mb-0">Olá, <strong><?= e(Auth::usuario()['nome'] ?? '') ?></strong>. Por segurança,
        crie uma senha pessoal antes de continuar.</p>
    </div>
    <?php if (!empty($erro)): ?>
      <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle me-1"></i><?= e($erro) ?></div>
    <?php endif; ?>
    <form method="post" action="<?= url('login/salvar-senha') ?>">
      <div class="mb-3">
        <label class="form-label" for="senha">Nova senha</label>
        <div class="input-group input-group-lg">
          <input type="password" name="senha" id="senha" class="form-control" required minlength="8" autofocus autocomplete="new-password">
          <button class="btn btn-outline-secondary" type="button" data-ver-senha="senha" data-rotulo="a nova senha"
                  title="Mostrar a nova senha" aria-label="Mostrar a nova senha" aria-pressed="false">
            <i class="bi bi-eye" aria-hidden="true"></i>
          </button>
        </div>
        <div class="form-text">Mínimo de 8 caracteres, misturando letras e números.</div>
      </div>
      <div class="mb-4">
        <label class="form-label" for="confirmar">Confirmar a nova senha</label>
        <div class="input-group input-group-lg">
          <input type="password" name="confirmar" id="confirmar" class="form-control" required minlength="8" autocomplete="new-password">
          <button class="btn btn-outline-secondary" type="button" data-ver-senha="confirmar" data-rotulo="a confirmação"
                  title="Mostrar a confirmação" aria-label="Mostrar a confirmação" aria-pressed="false">
            <i class="bi bi-eye" aria-hidden="true"></i>
          </button>
        </div>
      </div>
      <button class="btn btn-success btn-lg w-100"><i class="bi bi-check-lg me-1"></i>Salvar e continuar</button>
    </form>
    <div class="text-center mt-3">
      <a class="small text-muted" href="<?= url('login/sair') ?>">Sair</a>
    </div>
  </div>
</div>
<script>
  // Mostrar/ocultar cada senha — digitar às cegas duas vezes no celular erra fácil,
  // e o erro só aparece depois de enviar. Esta tela não carrega o app.js.
  (function () {
    document.querySelectorAll('[data-ver-senha]').forEach(function (botao) {
      const campo = document.getElementById(botao.dataset.verSenha);
      if (!campo) return;
      botao.addEventListener('click', function () {
        const visivel = campo.type === 'text';
        const cursor = campo.selectionStart;
        campo.type = visivel ? 'password' : 'text';
        const rotulo = (visivel ? 'Mostrar ' : 'Ocultar ') + (botao.dataset.rotulo || 'a senha');
        botao.title = rotulo;
        botao.setAttribute('aria-label', rotulo);
        botao.setAttribute('aria-pressed', visivel ? 'false' : 'true');
        botao.querySelector('i').className = visivel ? 'bi bi-eye' : 'bi bi-eye-slash';
        // Devolve o foco e o cursor onde estavam (trocar o type reposiciona o caret).
        campo.focus();
        if (cursor !== null) { try { campo.setSelectionRange(cursor, cursor); } catch (e) { /* ignora */ } }
      });
    });
  })();
</script>
</body>
</html>
