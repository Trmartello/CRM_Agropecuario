<?php
/**
 * Helpers globais da aplicação.
 */

/** Escapa saída HTML. */
function e(?string $valor): string
{
    return htmlspecialchars($valor ?? '', ENT_QUOTES, 'UTF-8');
}

/** JSON seguro para embutir em atributo HTML (onclick/data-*) — evita quebra de aspas e injeção de tags. */
function json_attr(mixed $valor): string
{
    return htmlspecialchars(
        (string) json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP),
        ENT_QUOTES,
        'UTF-8'
    );
}

/** Resposta JSON padronizada para as chamadas AJAX. */
function json_resposta(array $dados, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_ok(array $dados = []): never
{
    json_resposta(['ok' => true] + $dados);
}

function json_erro(string $mensagem, int $status = 400): never
{
    json_resposta(['ok' => false, 'erro' => $mensagem], $status);
}

/**
 * Idempotência do offline (O3): true se este uuid de reenvio da fila já foi
 * processado antes (evita cadastro duplicado se a resposta do OK se perdeu).
 */
function sync_uuid_processado(?string $uuid): bool
{
    $uuid = trim((string) $uuid);
    if ($uuid === '') {
        return false;
    }
    return (bool) \App\Core\Database::valor('SELECT 1 FROM sync_processados WHERE uuid = ?', [$uuid]);
}

/** Registra o uuid de um reenvio já processado com sucesso. */
function sync_registrar_uuid(?string $uuid): void
{
    $uuid = trim((string) $uuid);
    if ($uuid === '') {
        return;
    }
    \App\Core\Database::executar('INSERT IGNORE INTO sync_processados (uuid) VALUES (?)', [$uuid]);
}

/** Formata valor em reais. */
function moeda(float|int|string|null $valor): string
{
    return 'R$ ' . number_format((float)($valor ?? 0), 2, ',', '.');
}

/** Formata número com separador brasileiro. */
function numero(float|int|string|null $valor, int $decimais = 0): string
{
    return number_format((float)($valor ?? 0), $decimais, ',', '.');
}

/** Formata data (Y-m-d → d/m/Y). */
function data_br(?string $data): string
{
    if (!$data) {
        return '—';
    }
    $ts = strtotime($data);
    return $ts ? date('d/m/Y', $ts) : '—';
}

/** URL base de uma rota (?r=modulo/acao). */
function url(string $rota, array $params = []): string
{
    $query = http_build_query(['r' => $rota] + $params);
    return 'index.php?' . $query;
}

/** Renderiza uma view dentro do layout. */
function render(string $view, array $dados = []): void
{
    extract($dados);
    $conteudoView = __DIR__ . '/Views/' . $view . '.php';
    require __DIR__ . '/Views/layout.php';
}

/** Renderiza uma view sem layout (parciais/modais via AJAX). */
function render_parcial(string $view, array $dados = []): void
{
    extract($dados);
    require __DIR__ . '/Views/' . $view . '.php';
}
