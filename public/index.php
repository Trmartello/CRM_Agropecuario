<?php

/**
 * Front controller único — todas as rotas passam por aqui (?r=modulo/acao).
 */

declare(strict_types=1);

require dirname(__DIR__) . '/app/helpers.php';

// Rede de segurança para ERROS FATAIS (falta de memória, tempo esgotado): eles
// NÃO são capturados pelo try/catch do roteador, então sem isto o cliente AJAX
// recebe uma resposta não-JSON e mostra "Resposta inválida do servidor". Aqui
// devolvemos um erro JSON legível dizendo o que de fato aconteceu.
register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err === null
        || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)
        || headers_sent()) {
        return; // sem fatal, ou a resposta já começou (não dá para consertar o corpo)
    }
    $m = strtolower($err['message']);
    if (str_contains($m, 'memory')) {
        $amigavel = 'O servidor ficou sem memória ao processar o arquivo — ele é grande demais. '
            . 'Suba só a camada AREA_IMOVEL do município (não a de APP).';
    } elseif (str_contains($m, 'execution time') || str_contains($m, 'timeout')) {
        $amigavel = 'O processamento passou do tempo limite. O arquivo do município é muito grande; '
            . 'tente de novo — os municípios já gravados são mantidos.';
    } else {
        $amigavel = 'Erro interno ao processar a requisição.';
    }
    error_log('[CRM][fatal] ' . $err['message'] . ' em ' . $err['file'] . ':' . $err['line']);
    http_response_code(500);
    $ehAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch'
        || str_starts_with($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    if ($ehAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'erro' => $amigavel], JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><meta charset="utf-8"><div style="font-family:sans-serif;max-width:640px;margin:3rem auto">',
            '<h1 style="color:#c62828">Erro interno</h1><p>', htmlspecialchars($amigavel), '</p></div>';
    }
});

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
    // Detalhe técnico só no log do servidor (a mensagem do driver pode conter
    // host/usuário do banco — não deve chegar ao navegador).
    error_log('[CRM] Banco indisponível: ' . $e->getMessage());
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><title>Configuração pendente</title>',
        '<meta name="viewport" content="width=device-width, initial-scale=1"></head>',
        '<body style="font-family:sans-serif;max-width:640px;margin:3rem auto;padding:0 1rem">',
        '<h1 style="color:#1b5e20">🌱 CRM Copérdia — configuração pendente</h1>',
        '<p>Não foi possível conectar ao banco de dados. Verifique:</p><ul>',
        '<li>O serviço <strong>MySQL</strong> foi criado no projeto?</li>',
        '<li>As variáveis <code>DB_HOST</code>, <code>DB_PORT</code>, <code>DB_NAME</code>, <code>DB_USER</code> e <code>DB_PASS</code> estão definidas no serviço da aplicação?</li>',
        '</ul><p style="color:#666">O detalhe técnico foi registrado no log do servidor.</p>',
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
    // 'same-site' (outro subdomínio do mesmo domínio registrável) NÃO entra na
    // lista: com cookies SameSite=Lax um subdomínio vizinho comprometido poderia
    // forjar POSTs autenticados ao migrar para domínio próprio. A fila offline é
    // same-origin e navegação de topo manda 'none' — nada legítimo quebra.
    $crossSite = ($fetchSite !== '' && !in_array($fetchSite, ['same-origin', 'none'], true));
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

// Plantios por talhão (Fase 6E — linha do tempo da cultura)
$router->registrar('plantios/salvar', \App\Controllers\PlantiosController::class, 'salvar');
$router->registrar('plantios/encerrar', \App\Controllers\PlantiosController::class, 'encerrar');

// Relatórios (Fase 6B — fechamento de safra)
$router->registrar('relatorios/safra', \App\Controllers\RelatoriosController::class, 'safra');

// Administração da fenologia (Configurações → fases, imagens e recomendações)
$router->registrar('arquivo/estagio', \App\Controllers\ArquivoController::class, 'estagio');
$router->registrar('fenologia/estagios', \App\Controllers\FenologiaController::class, 'estagios');
$router->registrar('fenologia/salvar-estagio', \App\Controllers\FenologiaController::class, 'salvarEstagio');
$router->registrar('fenologia/imagem-padrao', \App\Controllers\FenologiaController::class, 'imagemPadrao');
$router->registrar('fenologia/excluir-estagio', \App\Controllers\FenologiaController::class, 'excluirEstagio');
$router->registrar('fenologia/salvar-manejo', \App\Controllers\FenologiaController::class, 'salvarManejo');
$router->registrar('fenologia/excluir-manejo', \App\Controllers\FenologiaController::class, 'excluirManejo');
$router->registrar('fenologia/manejos-padrao', \App\Controllers\FenologiaController::class, 'manejosPadrao');

// Clientes
$router->registrar('clientes', ClientesController::class, 'index');
$router->registrar('clientes/obter', ClientesController::class, 'obter');
$router->registrar('clientes/salvar', ClientesController::class, 'salvar');
$router->registrar('clientes/pre-cadastro', ClientesController::class, 'preCadastro');
$router->registrar('clientes/ficha', ClientesController::class, 'ficha');
$router->registrar('clientes/salvar-propriedade', ClientesController::class, 'salvarPropriedade');
$router->registrar('clientes/salvar-talhao', ClientesController::class, 'salvarTalhao');
$router->registrar('clientes/croqui-dados', ClientesController::class, 'croquiDados');
$router->registrar('clientes/salvar-croqui', ClientesController::class, 'salvarCroqui');
$router->registrar('clientes/importar-car', ClientesController::class, 'importarCar');
$router->registrar('clientes/car-por-ponto', ClientesController::class, 'carPorPonto');
$router->registrar('clientes/salvar-car-numero', ClientesController::class, 'salvarCarNumero');
$router->registrar('clientes/car-proximos', ClientesController::class, 'carProximos');
$router->registrar('clientes/localizar-area', ClientesController::class, 'localizarArea');
$router->registrar('integracao/importar-car-municipio', \App\Controllers\IntegracaoController::class, 'importarCarMunicipio');
$router->registrar('integracao/vincular-car-propriedades', \App\Controllers\IntegracaoController::class, 'vincularCarPropriedades');
$router->registrar('integracao/gerar-territorio', \App\Controllers\IntegracaoController::class, 'gerarTerritorio');
$router->registrar('integracao/salvar-cotacoes', \App\Controllers\IntegracaoController::class, 'salvarCotacoes');
$router->registrar('integracao/agregar-custo', \App\Controllers\IntegracaoController::class, 'agregarCusto');
$router->registrar('territorio', \App\Controllers\TerritorioController::class, 'index');
$router->registrar('territorio/imoveis', \App\Controllers\TerritorioController::class, 'imoveis');
$router->registrar('territorio/imovel', \App\Controllers\TerritorioController::class, 'imovel');
$router->registrar('territorio/localizar', \App\Controllers\TerritorioController::class, 'localizar');
$router->registrar('territorio/vincular', \App\Controllers\TerritorioController::class, 'vincular');
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
$router->registrar('sync/car-municipio', SyncController::class, 'carMunicipio');
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
$router->registrar('gerencial/desempenho', \App\Controllers\GerencialController::class, 'desempenho');
$router->registrar('gerencial/custo-regional', \App\Controllers\GerencialController::class, 'custoRegional');
$router->registrar('gerencial/auditoria-campo', \App\Controllers\GerencialController::class, 'auditoriaCampo');
$router->registrar('auditoria', \App\Controllers\AuditoriaController::class, 'index');
$router->registrar('backup/baixar', \App\Controllers\BackupController::class, 'baixar');
$router->registrar('mapa', \App\Controllers\MapaController::class, 'index');

// Portal do Produtor (Fase 4)
$router->registrar('portal', \App\Controllers\PortalController::class, 'index');
// Custo da Lavoura (spec custo-lavoura §9) — API do Portal, só perfil Produtor
$router->registrar('portal/lavouras', \App\Controllers\PortalController::class, 'lavouras');
$router->registrar('portal/lavoura', \App\Controllers\PortalController::class, 'lavoura');
$router->registrar('portal/lavoura-criar', \App\Controllers\PortalController::class, 'lavouraCriar');
$router->registrar('portal/lavoura-atualizar', \App\Controllers\PortalController::class, 'lavouraAtualizar');
$router->registrar('portal/lavoura-custos', \App\Controllers\PortalController::class, 'lavouraCustos');
$router->registrar('portal/lavoura-cenario', \App\Controllers\PortalController::class, 'lavouraCenario');
$router->registrar('portal/mercado', \App\Controllers\PortalController::class, 'mercado');
// Ingestão de NF (spec nf-ingestao §6/§9) — opt-in/revogação da captura
$router->registrar('portal/fiscal-autorizacao', \App\Controllers\PortalController::class, 'fiscalAutorizacao');
$router->registrar('portal/fiscal-autorizar', \App\Controllers\PortalController::class, 'fiscalAutorizar');
$router->registrar('portal/fiscal-revogar', \App\Controllers\PortalController::class, 'fiscalRevogar');
$router->registrar('portal/fiscal-pull', \App\Controllers\PortalController::class, 'fiscalPull');

// Integração ERP/CAPE (Fase 5)
$router->registrar('integracao', \App\Controllers\IntegracaoController::class, 'index');
$router->registrar('integracao/salvar-config', \App\Controllers\IntegracaoController::class, 'salvarConfig');
$router->registrar('integracao/sincronizar', \App\Controllers\IntegracaoController::class, 'sincronizar');
$router->registrar('integracao/importar-carga', \App\Controllers\IntegracaoController::class, 'importarCarga');

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
    // Nunca devolver a mensagem bruta ao cliente: uma PDOException carrega SQL,
    // nomes de tabelas/colunas e caminhos. O detalhe fica só no error_log acima.
    if (Auth::ehAjax()) {
        json_erro('Erro interno do servidor.', 500);
    }
    http_response_code(500);
    echo '<div style="font-family:sans-serif;max-width:640px;margin:3rem auto">',
        '<h1 style="color:#c62828">Erro interno</h1><p>Ocorreu um erro ao processar a solicitação. Tente novamente.</p>',
        '<p><a href="index.php?r=dashboard">Voltar ao Dashboard</a></p></div>';
}
