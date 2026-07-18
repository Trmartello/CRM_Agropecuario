<?php use App\Core\Auth; use App\Core\Permissoes; ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#1b5e20">
<title><?= e($titulo ?? 'CRM') ?> — CRM Agropecuário Copérdia</title>
<link rel="manifest" href="manifest.json">
<link rel="icon" href="assets/icons/icone-192.png">
<link rel="apple-touch-icon" href="assets/icons/icone-192.png">
<link rel="stylesheet" href="assets/vendor/bootstrap.min.css">
<link rel="stylesheet" href="assets/vendor/bootstrap-icons.min.css">
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<div class="d-flex" id="app">
  <!-- Sidebar -->
  <nav class="sidebar d-flex flex-column flex-shrink-0" id="sidebar">
    <a href="<?= url('dashboard') ?>" class="sidebar-marca d-flex align-items-center text-decoration-none">
      <i class="bi bi-flower1 fs-3 me-2"></i>
      <div>
        <strong>CRM Copérdia</strong>
        <div class="small opacity-75">Comercial &amp; Técnico</div>
      </div>
    </a>
    <hr class="text-white-50 my-2">
    <ul class="nav nav-pills flex-column mb-auto">
      <?php
        $rotaAtual = $_GET['r'] ?? 'dashboard';
        $menu = [
            ['dashboard', 'bi-speedometer2', 'Dashboard'],
            ['clientes', 'bi-people', 'Clientes'],
            ['visitas', 'bi-clipboard2-pulse', 'Visitas'],
            ['funil', 'bi-funnel', 'Funil'],
            ['cap', 'bi-trophy', 'Metas CAP'],
            ['relatorios/potencial', 'bi-bar-chart-line', 'Potencial'],
        ];
        if (Auth::perfil() === 'Administrador') {
            $menu[] = ['usuarios', 'bi-person-gear', 'Usuários'];
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
      <div class="fw-semibold text-truncate"><?= e(Auth::usuario()['nome'] ?? '') ?></div>
      <div class="opacity-75 mb-2"><?= e(Auth::perfil()) ?></div>
      <a class="btn btn-outline-light btn-sm w-100" href="<?= url('login/sair') ?>"><i class="bi bi-box-arrow-right me-1"></i>Sair</a>
    </div>
  </nav>

  <!-- Conteúdo -->
  <main class="conteudo flex-grow-1">
    <header class="topo d-flex align-items-center gap-2 px-3">
      <button class="btn btn-outline-secondary d-lg-none" id="btnMenu" aria-label="Menu"><i class="bi bi-list"></i></button>
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
<script src="assets/js/offline.js"></script>
<script src="assets/js/app.js"></script>
</body>
</html>
