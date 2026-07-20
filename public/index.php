<?php

/**
 * Front controller único — todas as rotas passam por aqui (?r=modulo/acao).
 */

declare(strict_types=1);

require dirname(__DIR__) . '/app/helpers.php';

// Cabeçalhos de segurança (defesa em profundidade)
header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

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
use App\Controllers\SyncController;
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

// Endurecimento CSRF: escrita (POST) só a partir do próprio site. Navegadores
// modernos mandam Sec-Fetch-Site; os demais mandam Origin em POSTs. Sem nenhum
// dos dois (curl, clientes antigos) segue normalmente — os cookies SameSite=Lax
// já impedem que outro site envie a sessão. Sem token: não quebra a fila offline
// (o reenvio do service worker é same-origin e passa direto).
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $fetchSite = strtolower($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
    $origem = $_SERVER['HTTP_ORIGIN'] ?? '';
    $crossSite = ($fetchSite !== '' && !in_array($fetchSite, ['same-origin', 'same-site', 'none'], true));
    if (!$crossSite && $fetchSite === '' && $origem !== '' && strtolower($origem) !== 'null') {
        $hostOrigem = strtolower((string) parse_url($origem, PHP_URL_HOST));
        $hostAtual = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
        $crossSite = ($hostOrigem !== '' && $hostAtual !== '' && $hostOrigem !== $hostAtual);
    }
    if ($crossSite) {
        json_erro('Requisição de outra origem bloqueada.', 403);
    }
}

$router = new Router();

// Imagens personalizadas (logo/favicon do banco) — públicas, o login usa
$router->registrar('arquivo/logo', \App\Controllers\ArquivoController::class, 'logo');
$router->registrar('arquivo/favicon', \App\Controllers\ArquivoController::class, 'favicon');
$router->registrar('arquivo/upload', \App\Controllers\ArquivoController::class, 'upload');

// Autenticação
$router->registrar('login', LoginController::class, 'form');
$router->registrar('login/entrar', LoginController::class, 'entrar');
$router->registrar('login/sair', LoginController::class, 'sair');
$router->registrar('login/trocar-senha', LoginController::class, 'trocarSenhaForm');
$router->registrar('login/salvar-senha', LoginController::class, 'salvarSenha');

// Primeiro acesso / senha temporária: obriga a definir a própria senha antes de usar o sistema
$rotaPedida = $_GET['r'] ?? '';
if (Auth::logado() && !empty(Auth::usuario()['trocar_senha'])
    && !in_array($rotaPedida, ['login/trocar-senha', 'login/salvar-senha', 'login/sair', 'arquivo/logo', 'arquivo/favicon'], true)) {
    if (Auth::ehAjax()) {
        json_erro('Defina sua nova senha para continuar.', 403);
    }
    header('Location: ' . url('login/trocar-senha'));
    exit;
}

// Dashboard
$router->registrar('dashboard', DashboardController::class, 'index');

// Clientes
$router->registrar('clientes', ClientesController::class, 'index');
$router->registrar('clientes/obter', ClientesController::class, 'obter');
$router->registrar('clientes/salvar', ClientesController::class, 'salvar');
$router->registrar('clientes/pre-cadastro', ClientesController::class, 'preCadastro');
$router->registrar('clientes/ficha', ClientesController::class, 'ficha');
$router->registrar('clientes/salvar-propriedade', ClientesController::class, 'salvarPropriedade');
$router->registrar('clientes/salvar-talhao', ClientesController::class, 'salvarTalhao');
$router->registrar('clientes/salvar-plano-safra', ClientesController::class, 'salvarPlanoSafra');
$router->registrar('clientes/salvar-documento', ClientesController::class, 'salvarDocumento');
$router->registrar('clientes/baixar-documento', ClientesController::class, 'baixarDocumento');
$router->registrar('clientes/excluir-documento', ClientesController::class, 'excluirDocumento');

// Visitas
$router->registrar('visitas', VisitasController::class, 'index');
$router->registrar('visitas/apoio-modal', VisitasController::class, 'apoioModal');
$router->registrar('visitas/modelos', VisitasController::class, 'modelos');
$router->registrar('visitas/salvar', VisitasController::class, 'salvar');
$router->registrar('visitas/dados', VisitasController::class, 'dados');
$router->registrar('sync/carteira', SyncController::class, 'carteira');
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

// Despesas: quilometragem, refeições e prestação de contas (Fase 3)
$router->registrar('despesas', \App\Controllers\DespesasController::class, 'index');
$router->registrar('despesas/salvar-km', \App\Controllers\DespesasController::class, 'salvarKm');
$router->registrar('despesas/salvar-veiculo', \App\Controllers\DespesasController::class, 'salvarVeiculo');
$router->registrar('despesas/ultimo-km', \App\Controllers\DespesasController::class, 'ultimoKm');
$router->registrar('despesas/salvar-refeicao', \App\Controllers\DespesasController::class, 'salvarRefeicao');
$router->registrar('despesas/excluir-km', \App\Controllers\DespesasController::class, 'excluirKm');
$router->registrar('despesas/excluir-refeicao', \App\Controllers\DespesasController::class, 'excluirRefeicao');
$router->registrar('despesas/gerar-prestacao', \App\Controllers\DespesasController::class, 'gerarPrestacao');
$router->registrar('despesas/enviar-prestacao', \App\Controllers\DespesasController::class, 'enviarPrestacao');
$router->registrar('despesas/avaliar-prestacao', \App\Controllers\DespesasController::class, 'avaliarPrestacao');
$router->registrar('despesas/detalhe-prestacao', \App\Controllers\DespesasController::class, 'detalhePrestacao');

// Reclamações / laudos (Fase 3)
$router->registrar('reclamacoes', \App\Controllers\ReclamacoesController::class, 'index');
$router->registrar('reclamacoes/salvar', \App\Controllers\ReclamacoesController::class, 'salvar');
$router->registrar('reclamacoes/mover', \App\Controllers\ReclamacoesController::class, 'mover');
$router->registrar('reclamacoes/detalhe', \App\Controllers\ReclamacoesController::class, 'detalhe');

// Pacotes Agrícolas (Fase 2)
$router->registrar('pacotes', \App\Controllers\PacotesController::class, 'index');
$router->registrar('pacotes/obter', \App\Controllers\PacotesController::class, 'obter');
$router->registrar('pacotes/salvar', \App\Controllers\PacotesController::class, 'salvar');

// Agenda (Fase 4)
$router->registrar('agenda', \App\Controllers\AgendaController::class, 'index');
$router->registrar('agenda/salvar', \App\Controllers\AgendaController::class, 'salvar');
$router->registrar('agenda/status', \App\Controllers\AgendaController::class, 'status');
$router->registrar('agenda/roteiro', \App\Controllers\AgendaController::class, 'roteiro');
$router->registrar('agenda/organizador', \App\Controllers\AgendaController::class, 'organizador');
$router->registrar('agenda/roteiro-adicionar', \App\Controllers\AgendaController::class, 'roteiroAdicionar');
$router->registrar('agenda/roteiro-remover', \App\Controllers\AgendaController::class, 'roteiroRemover');
$router->registrar('agenda/roteiro-reordenar', \App\Controllers\AgendaController::class, 'roteiroReordenar');
$router->registrar('agenda/roteiro-otimizar', \App\Controllers\AgendaController::class, 'roteiroOtimizar');
$router->registrar('agenda/buscar-produtor', \App\Controllers\AgendaController::class, 'buscarProdutor');

// Notificações (Fase 4)
$router->registrar('notificacoes/listar', \App\Controllers\NotificacoesController::class, 'listar');
$router->registrar('notificacoes/ultima', \App\Controllers\NotificacoesController::class, 'ultima');
$router->registrar('push/chave', \App\Controllers\PushController::class, 'chave');
$router->registrar('push/registrar', \App\Controllers\PushController::class, 'registrar');
$router->registrar('push/desregistrar', \App\Controllers\PushController::class, 'desregistrar');
$router->registrar('notificacoes/ler', \App\Controllers\NotificacoesController::class, 'ler');
$router->registrar('notificacoes/ler-todas', \App\Controllers\NotificacoesController::class, 'lerTodas');

// Painel gerencial e mapa (Fase 4)
$router->registrar('gerencial', \App\Controllers\GerencialController::class, 'index');
$router->registrar('auditoria', \App\Controllers\AuditoriaController::class, 'index');
$router->registrar('backup/baixar', \App\Controllers\BackupController::class, 'baixar');
$router->registrar('mapa', \App\Controllers\MapaController::class, 'index');

// Portal do Produtor (Fase 4)
$router->registrar('portal', \App\Controllers\PortalController::class, 'index');

// Integração ERP/CAPE (Fase 5)
$router->registrar('integracao', \App\Controllers\IntegracaoController::class, 'index');
$router->registrar('integracao/salvar-config', \App\Controllers\IntegracaoController::class, 'salvarConfig');
$router->registrar('integracao/sincronizar', \App\Controllers\IntegracaoController::class, 'sincronizar');

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
$router->registrar('configuracoes/salvar-ajustes', \App\Controllers\ConfiguracoesController::class, 'salvarAjustes');
$router->registrar('configuracoes/salvar-categoria', \App\Controllers\ConfiguracoesController::class, 'salvarCategoria');

try {
    $router->despachar($_GET['r'] ?? 'dashboard');
} catch (\Throwable $e) {
    // Erro legível em vez de "resposta inválida" nas chamadas AJAX
    error_log('[CRM] ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
    if (Auth::ehAjax()) {
        json_erro('Erro interno do servidor: ' . $e->getMessage(), 500);
    }
    http_response_code(500);
    echo '<div style="font-family:sans-serif;max-width:640px;margin:3rem auto">',
        '<h1 style="color:#c62828">Erro interno</h1><p>', e($e->getMessage()), '</p>',
        '<p><a href="index.php?r=dashboard">Voltar ao Dashboard</a></p></div>';
}
