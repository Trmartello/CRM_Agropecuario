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
        <label class="form-label">Nova senha</label>
        <input type="password" name="senha" class="form-control form-control-lg" required minlength="8" autofocus autocomplete="new-password">
        <div class="form-text">Mínimo de 8 caracteres, misturando letras e números.</div>
      </div>
      <div class="mb-4">
        <label class="form-label">Confirmar a nova senha</label>
        <input type="password" name="confirmar" class="form-control form-control-lg" required minlength="8" autocomplete="new-password">
      </div>
      <button class="btn btn-success btn-lg w-100"><i class="bi bi-check-lg me-1"></i>Salvar e continuar</button>
    </form>
    <div class="text-center mt-3">
      <a class="small text-muted" href="<?= url('login/sair') ?>">Sair</a>
    </div>
  </div>
</div>
</body>
</html>
