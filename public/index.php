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

try {
    Instalador::garantirSchema();
} catch (\PDOException $e) {
    // Banco inacessível (ex.: variáveis DB_* ausentes no deploy): orienta em vez de quebrar
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><title>Configuração pendente</title>',
        '<meta name="viewport" content="width=device-width, initial-scale=1"></head>',
        '<body style="font-family:sans-serif;max-width:640px;margin:3rem auto;padding:0 1rem">',
        '<h1 style="color:#1b5e20">🌱 CRM Copérdia — configuração pendente</h1>',
        '<p>Não foi possível conectar ao banco de dados. Verifique:</p><ul>',
        '<li>O serviço <strong>MySQL</strong> foi criado no projeto?</li>',
        '<li>As variáveis <code>DB_HOST</code>, <code>DB_PORT</code>, <code>DB_NAME</code>, <code>DB_USER</code> e <code>DB_PASS</code> estão definidas no serviço da aplicação?</li>',
        '</ul><p style="color:#666">Detalhe técnico: ', e($e->getMessage()), '</p>',
        '<p>Após ajustar, recarregue esta página — o banco será instalado automaticamente.</p></body></html>';
    exit;
}
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

// Pedidos (Fase 2)
$router->registrar('pedidos', \App\Controllers\PedidosController::class, 'index');
$router->registrar('pedidos/salvar', \App\Controllers\PedidosController::class, 'salvar');
$router->registrar('pedidos/salvar-pacote', \App\Controllers\PedidosController::class, 'salvarPacote');
$router->registrar('pedidos/avaliar-pacote', \App\Controllers\PedidosController::class, 'avaliarPacote');
$router->registrar('pedidos/estrutura-pacote', \App\Controllers\PedidosController::class, 'estruturaPacote');
$router->registrar('pedidos/aprovar', \App\Controllers\PedidosController::class, 'aprovar');
$router->registrar('pedidos/faturar', \App\Controllers\PedidosController::class, 'faturar');
$router->registrar('pedidos/cancelar', \App\Controllers\PedidosController::class, 'cancelar');
$router->registrar('pedidos/detalhe', \App\Controllers\PedidosController::class, 'detalhe');

// Pacotes Agrícolas (Fase 2)
$router->registrar('pacotes', \App\Controllers\PacotesController::class, 'index');
$router->registrar('pacotes/obter', \App\Controllers\PacotesController::class, 'obter');
$router->registrar('pacotes/salvar', \App\Controllers\PacotesController::class, 'salvar');

// Relatórios
$router->registrar('relatorios/potencial', RelatoriosController::class, 'potencial');
$router->registrar('relatorios/potencial-dados', RelatoriosController::class, 'potencialDados');

// Metas CAP
$router->registrar('cap', CapController::class, 'index');

// Usuários (Administrador)
$router->registrar('usuarios', UsuariosController::class, 'index');
$router->registrar('usuarios/salvar', UsuariosController::class, 'salvar');

// Configurações (Administrador)
$router->registrar('configuracoes', \App\Controllers\ConfiguracoesController::class, 'index');
$router->registrar('configuracoes/salvar-imagem', \App\Controllers\ConfiguracoesController::class, 'salvarImagem');
$router->registrar('configuracoes/restaurar-padrao', \App\Controllers\ConfiguracoesController::class, 'restaurarPadrao');

$router->despachar($_GET['r'] ?? 'dashboard');
