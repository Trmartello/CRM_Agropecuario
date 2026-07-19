<?php
use App\Core\Auth;
use App\Core\Permissoes;
use App\Services\ConfigService;

$logoApp = ConfigService::logoAplicacao();
$faviconApp = ConfigService::faviconAplicacao();
$logoLargura = (int) ConfigService::obter('logo_sidebar_largura', '180');
$logoFundo = ConfigService::obter('logo_fundo', 'branco');
$logoPosicao = ConfigService::obter('logo_posicao', 'acima');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#1b5e20">
<title><?= e($titulo ?? 'CRM') ?> — CRM AGRO · Copérdia</title>
<link rel="manifest" href="manifest.json">
<link rel="icon" href="<?= e($faviconApp) ?>">
<link rel="apple-touch-icon" href="assets/icons/icone-192.png">
<link rel="stylesheet" href="assets/vendor/bootstrap.min.css">
<link rel="stylesheet" href="assets/vendor/bootstrap-icons.min.css">
<link rel="stylesheet" href="assets/css/app.css?v=<?= filemtime(dirname(__DIR__, 2) . '/public/assets/css/app.css') ?>">
</head>
<body>
<div class="d-flex" id="app">
  <!-- Sidebar -->
  <nav class="sidebar d-flex flex-column flex-shrink-0" id="sidebar">
    <?php if ($logoPosicao === 'lado'): ?>
    <a href="<?= url('dashboard') ?>" class="sidebar-marca text-decoration-none d-flex align-items-center gap-2">
      <span class="logo-cartao <?= $logoFundo === 'transparente' ? 'logo-transparente' : '' ?> flex-shrink-0"
            style="max-width:<?= min(110, $logoLargura) ?>px"><img src="<?= e($logoApp) ?>" alt="Copérdia" class="w-100"></span>
      <strong class="rotulo-marca">CRM AGRO</strong>
    </a>
    <?php else: ?>
    <a href="<?= url('dashboard') ?>" class="sidebar-marca text-decoration-none text-center d-block">
      <span class="logo-cartao <?= $logoFundo === 'transparente' ? 'logo-transparente' : '' ?> d-block mx-auto mb-1"
            style="max-width:<?= $logoLargura ?>px"><img src="<?= e($logoApp) ?>" alt="Copérdia" class="w-100"></span>
      <strong class="rotulo-marca">CRM AGRO</strong>
    </a>
    <?php endif; ?>
    <hr class="text-white-50 my-2">
    <ul class="nav nav-pills flex-column mb-auto">
      <?php
        $rotaAtual = $_GET['r'] ?? 'dashboard';
        $menu = [
            ['dashboard', 'bi-speedometer2', 'Dashboard'],
            ['clientes', 'bi-people', 'Clientes'],
            ['visitas', 'bi-clipboard2-pulse', 'Visitas'],
            ['pedidos', 'bi-cart3', 'Pedidos'],
            ['funil', 'bi-funnel', 'Funil'],
            ['cap', 'bi-trophy', 'Metas CAP'],
            ['relatorios/potencial', 'bi-bar-chart-line', 'Potencial'],
        ];
        if (in_array(Auth::perfil(), ['Administrador', 'Gestor Comercial', 'Gestor Técnico', 'Analista'], true)) {
            $menu[] = ['pacotes', 'bi-box-seam', 'Pacotes'];
        }
        if (Auth::perfil() === 'Administrador') {
            $menu[] = ['usuarios', 'bi-person-gear', 'Usuários'];
            $menu[] = ['configuracoes', 'bi-gear', 'Configurações'];
        }
      ?>
      <?php foreach ($menu as [$rota, $icone, $rotulo]): ?>
      <li class="nav-item">
        <a href="<?= url($rota) ?>" class="nav-link <?= str_starts_with($rotaAtual, explode('/', $rota)[0]) && (explode('/', $rotaAtual)[0] === explode('/', $rota)[0]) ? 'active' : 'text-white' ?>">
          <i class="bi <?= $icone ?> me-2"></i><span class="rotulo"><?= $rotulo ?></span>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
    <hr class="text-white-50">
    <div class="sidebar-usuario small">
      <div class="fw-semibold text-truncate rotulo"><?= e(Auth::usuario()['nome'] ?? '') ?></div>
      <div class="opacity-75 mb-2 rotulo"><?= e(Auth::perfil()) ?></div>
      <a class="btn btn-outline-light btn-sm w-100" href="<?= url('login/sair') ?>" title="Sair"><i class="bi bi-box-arrow-right"></i><span class="rotulo ms-1">Sair</span></a>
    </div>
  </nav>

  <!-- Conteúdo -->
  <main class="conteudo flex-grow-1">
    <header class="topo d-flex align-items-center gap-2 px-3">
      <button class="btn btn-outline-secondary d-lg-none" id="btnMenu" aria-label="Menu"><i class="bi bi-list"></i></button>
      <button class="btn btn-light border d-none d-lg-inline-flex align-items-center" id="btnRecolherMenu"
              title="Recolher/expandir o menu lateral" aria-label="Recolher menu">
        <i class="bi bi-layout-sidebar"></i>
      </button>
      <h1 class="h5 mb-0 flex-grow-1"><?= e($titulo ?? '') ?></h1>
      <span id="indicadorOffline" class="badge text-bg-warning d-none"><i class="bi bi-wifi-off me-1"></i>Offline</span>
      <span id="indicadorSync" class="badge text-bg-info d-none"><i class="bi bi-arrow-repeat me-1"></i>Sincronizando…</span>
    </header>
    <div class="p-3">
      <?php require $conteudoView; ?>
    </div>
  </main>
</div>

<div id="alertas" class="position-fixed bottom-0 end-0 p-3" style="z-index:1080"></div>

<script src="assets/vendor/bootstrap.bundle.min.js"></script>
<script src="assets/vendor/chart.umd.min.js"></script>
<script src="assets/js/offline.js?v=<?= filemtime(dirname(__DIR__, 2) . '/public/assets/js/offline.js') ?>"></script>
<script src="assets/js/app.js?v=<?= filemtime(dirname(__DIR__, 2) . '/public/assets/js/app.js') ?>"></script>
</body>
</html>
