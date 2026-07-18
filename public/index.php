<?php

/**
 * Front controller único — todas as rotas passam por aqui (?r=modulo/acao).
 */

declare(strict_types=1);

require dirname(__DIR__) . '/app/helpers.php';

// Autoloader PSR-4 simples: App\ → /app
spl_autoload_register(function (string $classe): void {
    if (str_starts_with($classe, 'App\\')) {
        $arquivo = dirname(__DIR__) . '/app/' . str_replace('\\', '/', substr($classe, 4)) . '.php';
        if (is_file($arquivo)) {
            require $arquivo;
        }
    }
});

use App\Controllers\CapController;
use App\Controllers\ClientesController;
use App\Controllers\DashboardController;
use App\Controllers\FunilController;
use App\Controllers\LoginController;
use App\Controllers\RelatoriosController;
use App\Controllers\UsuariosController;
use App\Controllers\VisitasController;
use App\Core\Auth;
use App\Core\Instalador;
use App\Core\Router;

Instalador::garantirSchema();
Auth::iniciarSessao();

$router = new Router();

// Autenticação
$router->registrar('login', LoginController::class, 'form');
$router->registrar('login/entrar', LoginController::class, 'entrar');
$router->registrar('login/sair', LoginController::class, 'sair');

// Dashboard
$router->registrar('dashboard', DashboardController::class, 'index');

// Clientes
$router->registrar('clientes', ClientesController::class, 'index');
$router->registrar('clientes/obter', ClientesController::class, 'obter');
$router->registrar('clientes/salvar', ClientesController::class, 'salvar');
$router->registrar('clientes/ficha', ClientesController::class, 'ficha');
$router->registrar('clientes/salvar-propriedade', ClientesController::class, 'salvarPropriedade');
$router->registrar('clientes/salvar-talhao', ClientesController::class, 'salvarTalhao');
$router->registrar('clientes/salvar-plano-safra', ClientesController::class, 'salvarPlanoSafra');

// Visitas
$router->registrar('visitas', VisitasController::class, 'index');
$router->registrar('visitas/apoio-modal', VisitasController::class, 'apoioModal');
$router->registrar('visitas/modelos', VisitasController::class, 'modelos');
$router->registrar('visitas/salvar', VisitasController::class, 'salvar');
$router->registrar('visitas/detalhe', VisitasController::class, 'detalhe');

// Funil de oportunidades
$router->registrar('funil', FunilController::class, 'index');
$router->registrar('funil/salvar', FunilController::class, 'salvar');
$router->registrar('funil/mover', FunilController::class, 'mover');
$router->registrar('funil/aprovar', FunilController::class, 'aprovar');
$router->registrar('funil/salvar-proposta', FunilController::class, 'salvarProposta');

// Relatórios
$router->registrar('relatorios/potencial', RelatoriosController::class, 'potencial');
$router->registrar('relatorios/potencial-dados', RelatoriosController::class, 'potencialDados');

// Metas CAP
$router->registrar('cap', CapController::class, 'index');

// Usuários (Administrador)
$router->registrar('usuarios', UsuariosController::class, 'index');
$router->registrar('usuarios/salvar', UsuariosController::class, 'salvar');

$router->despachar($_GET['r'] ?? 'dashboard');
