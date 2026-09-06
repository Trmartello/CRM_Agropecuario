<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#1b5e20">
<title>Entrar — CRM AGRO · Copérdia</title>
<?php use App\Services\ConfigService; ?>
<link rel="manifest" href="manifest.json">
<link rel="icon" href="<?= e(ConfigService::faviconAplicacao()) ?>">
<link rel="stylesheet" href="assets/vendor/bootstrap.min.css">
<link rel="stylesheet" href="assets/vendor/bootstrap-icons.min.css">
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="tela-login d-flex align-items-center justify-content-center min-vh-100">
<div class="card shadow-lg border-0" style="max-width:420px;width:100%">
  <div class="card-body p-4 p-md-5">
    <div class="text-center mb-4">
      <div class="rounded-3 py-3 px-2 mb-3" style="background:linear-gradient(160deg,#1b5e20,#2e7d32)">
        <img src="<?= e(ConfigService::logoAplicacao()) ?>" alt="Copérdia"
             style="width:<?= (int) ConfigService::obter('logo_login_largura', '170') ?>px;max-width:85%">
      </div>
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
        <label class="form-label" for="senha">Senha</label>
        <div class="input-group input-group-lg">
          <input type="password" name="senha" id="senha" class="form-control" required autocomplete="current-password">
          <button class="btn btn-outline-secondary" type="button" id="verSenha"
                  title="Mostrar a senha" aria-label="Mostrar a senha" aria-pressed="false">
            <i class="bi bi-eye" aria-hidden="true"></i>
          </button>
        </div>
      </div>
      <button class="btn btn-success btn-lg w-100"><i class="bi bi-box-arrow-in-right me-1"></i>Entrar</button>
    </form>
  </div>
</div>
<script>
  // Mostrar/ocultar a senha — no celular é fácil errar a digitação às cegas,
  // e o app bloqueia o e-mail após 5 tentativas. Esta tela não carrega o app.js.
  (function () {
    const campo = document.getElementById('senha');
    const botao = document.getElementById('verSenha');
    if (!campo || !botao) return;
    botao.addEventListener('click', function () {
      const visivel = campo.type === 'text';
      const cursor = campo.selectionStart;
      campo.type = visivel ? 'password' : 'text';
      const rotulo = visivel ? 'Mostrar a senha' : 'Ocultar a senha';
      botao.title = rotulo;
      botao.setAttribute('aria-label', rotulo);
      botao.setAttribute('aria-pressed', visivel ? 'false' : 'true');
      botao.querySelector('i').className = visivel ? 'bi bi-eye' : 'bi bi-eye-slash';
      // Devolve o foco e o cursor onde estavam (trocar o type reposiciona o caret).
      campo.focus();
      if (cursor !== null) { try { campo.setSelectionRange(cursor, cursor); } catch (e) { /* ignora */ } }
    });
  })();
</script>
<?php if (isset($_GET['saiu'])): ?>
<script>
  // Logout: limpa o snapshot da carteira do aparelho (não vaza dados para o próximo
  // usuário em aparelho compartilhado). A fila de pendências (fila_sync) é preservada.
  try {
    const req = indexedDB.open('crm_coperdia');
    req.onsuccess = () => {
      const db = req.result;
      if (db.objectStoreNames.contains('snapshot')) {
        db.transaction('snapshot', 'readwrite').objectStore('snapshot').clear();
      }
      db.close();
    };
  } catch (e) { /* ignora */ }
</script>
<?php endif; ?>
</body>
</html>
