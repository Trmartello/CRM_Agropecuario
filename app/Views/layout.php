<?php
use App\Core\Auth;
use App\Core\Permissoes;
use App\Services\ConfigService;

$logoApp = ConfigService::logoMenu();
$faviconApp = ConfigService::faviconAplicacao();
$logoLargura = (int) ConfigService::obter('logo_sidebar_largura', '180');
$logoFundo = ConfigService::obter('logo_fundo', ConfigService::logoPersonalizada() ? 'branco' : 'transparente');
$logoPosicao = ConfigService::obter('logo_posicao', 'acima');
$logoRaio = (int) ConfigService::obter('logo_borda_raio', '10');
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
            style="max-width:<?= min(110, $logoLargura) ?>px;border-radius:<?= $logoRaio ?>px"><img src="<?= e($logoApp) ?>" alt="Copérdia" style="border-radius:<?= $logoRaio ?>px"></span>
      <span class="rotulo-marca">
        <strong class="d-block">CRM AGRO</strong>
        <span class="small opacity-75">Copérdia</span>
      </span>
      <img src="<?= e($faviconApp) ?>" class="logo-mini" alt="CRM AGRO">
    </a>
    <?php else: ?>
    <a href="<?= url('dashboard') ?>" class="sidebar-marca text-decoration-none text-center d-block">
      <span class="logo-cartao <?= $logoFundo === 'transparente' ? 'logo-transparente' : '' ?> d-block mx-auto mb-1"
            style="max-width:<?= $logoLargura ?>px;border-radius:<?= $logoRaio ?>px"><img src="<?= e($logoApp) ?>" alt="Copérdia" style="border-radius:<?= $logoRaio ?>px"></span>
      <span class="rotulo-marca">
        <strong class="d-block">CRM AGRO</strong>
        <span class="small opacity-75">Copérdia</span>
      </span>
      <img src="<?= e($faviconApp) ?>" class="logo-mini" alt="CRM AGRO">
    </a>
    <?php endif; ?>
    <hr class="text-white-50 my-2">
    <?php
      $rotaAtual = $_GET['r'] ?? 'dashboard';
      $rotaBase = explode('/', $rotaAtual)[0];
      $ativo = fn (string $rota): bool => $rotaBase === explode('/', $rota)[0];

      if (Auth::perfil() === 'Produtor') {
          // Portal do Produtor — menu enxuto (sem grupos)
          $itensSoltos = [['portal', 'bi-house-heart', 'Meu Portal']];
          $grupos = [];
      } else {
          $ehGestao = in_array(Auth::perfil(), ['Administrador', 'Gestor Comercial', 'Gestor Técnico', 'Analista'], true);
          $ehAdmin = Auth::perfil() === 'Administrador';

          $itensSoltos = [['dashboard', 'bi-speedometer2', 'Dashboard']];

          $comercial = [['pedidos', 'bi-cart3', 'Pedidos']];
          if ($ehGestao) {
              $comercial[] = ['pacotes', 'bi-box-seam', 'Pacotes'];
          }
          $comercial[] = ['funil', 'bi-funnel', 'Funil'];
          $comercial[] = ['relatorios/potencial', 'bi-bar-chart-line', 'Potencial de Vendas'];
          $comercial[] = ['cap', 'bi-trophy', 'Metas CAP'];

          $gestao = [];
          if ($ehGestao) {
              $gestao[] = ['gerencial', 'bi-graph-up-arrow', 'Gerencial'];
          }
          if ($ehAdmin) {
              $gestao[] = ['usuarios', 'bi-person-gear', 'Usuários'];
              $gestao[] = ['integracao', 'bi-hdd-network', 'Integração'];
          }

          $grupos = [
              ['Atendimento ao Produtor', 'bi-people', [
                  ['clientes', 'bi-person-vcard', 'Produtores'],
                  ['visitas', 'bi-clipboard2-pulse', 'Visitas'],
                  ['agenda', 'bi-calendar-week', 'Agenda'],
                  ['mapa', 'bi-geo-alt', 'Mapa'],
                  ['reclamacoes', 'bi-exclamation-octagon', 'Reclamações'],
              ]],
              ['Comercial', 'bi-graph-up', $comercial],
              ['Despesas', 'bi-receipt', [
                  ['despesas', 'bi-receipt', 'KM e Refeição'],
              ]],
          ];
          if ($gestao) {
              $grupos[] = ['Gestão', 'bi-gear-wide-connected', $gestao];
          }
      }
    ?>
    <ul class="nav nav-pills flex-column mb-auto" id="menuPrincipal">
      <?php foreach ($itensSoltos as [$rota, $icone, $rotulo]): ?>
      <li class="nav-item">
        <a href="<?= url($rota) ?>" title="<?= e($rotulo) ?>" class="nav-link <?= $ativo($rota) ? 'active' : 'text-white' ?>">
          <i class="bi <?= $icone ?> me-2"></i><span class="rotulo"><?= $rotulo ?></span>
        </a>
      </li>
      <?php endforeach; ?>

      <?php foreach ($grupos as $gi => [$gLabel, $gIcone, $itens]): ?>
        <?php if (!$itens) { continue; }
          $grupoAtivo = false;
          foreach ($itens as $it) { if ($ativo($it[0])) { $grupoAtivo = true; break; } }
          $collId = 'grupo' . $gi;
        ?>
      <li class="nav-item mt-1">
        <button type="button" class="nav-link grupo-header w-100 d-flex align-items-center <?= $grupoAtivo ? '' : 'collapsed' ?>"
                data-bs-toggle="collapse" data-bs-target="#<?= $collId ?>" aria-expanded="<?= $grupoAtivo ? 'true' : 'false' ?>" title="<?= e($gLabel) ?>">
          <i class="bi <?= $gIcone ?> me-2"></i><span class="rotulo flex-grow-1 text-start"><?= $gLabel ?></span>
          <i class="bi bi-chevron-down chevron rotulo"></i>
        </button>
        <ul class="nav nav-pills flex-column grupo-itens collapse <?= $grupoAtivo ? 'show' : '' ?>" id="<?= $collId ?>" data-bs-parent="#menuPrincipal">
          <?php foreach ($itens as [$rota, $icone, $rotulo]): ?>
          <li class="nav-item">
            <a href="<?= url($rota) ?>" title="<?= e($rotulo) ?>" class="nav-link ps-4 <?= $ativo($rota) ? 'active' : 'text-white' ?>">
              <i class="bi <?= $icone ?> me-2"></i><span class="rotulo"><?= $rotulo ?></span>
            </a>
          </li>
          <?php endforeach; ?>
        </ul>
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
      <?php if (Auth::perfil() === 'Administrador'): ?>
      <a class="btn btn-light border <?= ($_GET['r'] ?? '') === 'configuracoes' ? 'active' : '' ?>"
         href="<?= url('configuracoes') ?>" title="Configurações do sistema" aria-label="Configurações">
        <i class="bi bi-gear"></i>
      </a>
      <?php endif; ?>
      <h1 class="h5 mb-0 flex-grow-1"><?= e($titulo ?? '') ?></h1>
      <span id="indicadorOffline" class="badge text-bg-warning d-none"><i class="bi bi-wifi-off me-1"></i>Offline</span>
      <span id="indicadorSync" class="badge text-bg-info d-none"><i class="bi bi-arrow-repeat me-1"></i>Sincronizando…</span>
      <!-- Sino de notificações -->
      <div class="dropdown">
        <button class="btn btn-light border position-relative" id="btnSino" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-label="Notificações" onclick="Notificacoes.abrir()">
          <i class="bi bi-bell"></i>
          <span id="sinoContador" class="position-absolute top-0 start-100 translate-middle badge rounded-pill text-bg-danger d-none">0</span>
        </button>
        <div class="dropdown-menu dropdown-menu-end shadow" style="width:320px;max-height:420px;overflow:auto" id="sinoLista" aria-labelledby="btnSino">
          <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
            <strong class="small">Notificações</strong>
            <button class="btn btn-sm btn-link p-0 text-decoration-none" onclick="Notificacoes.lerTodas(event)">Marcar todas</button>
          </div>
          <div id="sinoItens"><div class="text-muted small text-center py-3">Carregando…</div></div>
        </div>
      </div>
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
