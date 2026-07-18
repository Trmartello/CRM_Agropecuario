<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#1b5e20">
<title>Entrar — CRM AGRO · Copérdia</title>
<link rel="manifest" href="manifest.json">
<link rel="icon" href="assets/icons/icone-192.png">
<link rel="stylesheet" href="assets/vendor/bootstrap.min.css">
<link rel="stylesheet" href="assets/vendor/bootstrap-icons.min.css">
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="tela-login d-flex align-items-center justify-content-center min-vh-100">
<div class="card shadow-lg border-0" style="max-width:420px;width:100%">
  <div class="card-body p-4 p-md-5">
    <div class="text-center mb-4">
      <img src="assets/img/logo-coperdia.svg" alt="Copérdia" style="width:170px;max-width:70%">
      <h1 class="h4 mt-3 mb-0">CRM AGRO</h1>
      <p class="text-muted">Copérdia</p>
    </div>
    <?php if (!empty($erro)): ?>
      <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle me-1"></i><?= e($erro) ?></div>
    <?php endif; ?>
    <form method="post" action="<?= url('login/entrar') ?>">
      <div class="mb-3">
        <label class="form-label">E-mail</label>
        <input type="email" name="email" class="form-control form-control-lg" required autofocus autocomplete="username">
      </div>
      <div class="mb-4">
        <label class="form-label">Senha</label>
        <input type="password" name="senha" class="form-control form-control-lg" required autocomplete="current-password">
      </div>
      <button class="btn btn-success btn-lg w-100"><i class="bi bi-box-arrow-in-right me-1"></i>Entrar</button>
    </form>
  </div>
</div>
</body>
</html>
