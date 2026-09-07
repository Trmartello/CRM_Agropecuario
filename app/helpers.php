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
 * Libera o LOCK da sessão. O PHP tranca o arquivo da sessão durante toda a
 * requisição — então uma rota LONGA (import do CAR, dump de backup) que segura
 * a sessão faz as OUTRAS requisições do MESMO usuário (sino de notificações,
 * navegação) ficarem presas em `session_start()` até estourar o timeout do
 * proxy ("upstream error"). Chamar logo após checar a permissão: o `$_SESSION`
 * segue legível em memória (Auth::id() etc.), só não persiste novas gravações.
 */
function liberar_sessao(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

/**
 * Idempotência do offline (O3) — ATÔMICA.
 * Abre uma transação e insere o uuid do reenvio como "trava" (PK). Se o uuid já
 * existe (reenvio de uma requisição cuja resposta se perdeu), desfaz e responde
 * `duplicado` sem reprocessar. O cadastro real roda dentro da mesma transação e
 * só é confirmado por `sync_confirmar()` — assim uuid + cadastro + anexos são
 * tudo-ou-nada (sem duplicata por crash, sem perda de anexos). Sem uuid (envio
 * online normal) é no-op: fluxo autocommit inalterado.
 */
function sync_iniciar(?string $uuid): void
{
    $uuid = trim((string) $uuid);
    if ($uuid === '') {
        return;
    }
    $pdo = \App\Core\Database::conexao();
    $pdo->beginTransaction();
    try {
        \App\Core\Database::executar('INSERT INTO sync_processados (uuid) VALUES (?)', [$uuid]);
    } catch (\PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_ok(['duplicado' => true]); // já processado — encerra (json_ok é never)
    }
}

/** Confirma (commit) a transação idempotente aberta por sync_iniciar(). */
function sync_confirmar(?string $uuid): void
{
    if (trim((string) $uuid) === '') {
        return;
    }
    $pdo = \App\Core\Database::conexao();
    if ($pdo->inTransaction()) {
        $pdo->commit();
    }
}

/** Limpeza periódica: remove uuids de idempotência mais antigos que N dias. */
function sync_limpar_antigos(int $dias = 90): void
{
    try {
        \App\Core\Database::executar(
            'DELETE FROM sync_processados WHERE criado_em < (NOW() - INTERVAL ? DAY)',
            [$dias]
        );
    } catch (\Throwable $e) { /* tabela ainda não migrada: ignora */ }
}

/**
 * Selo (badge) do segmento do cliente. Usa o segmento efetivo (manual do
 * gestor prevalece); devolve '' se o cliente ainda não foi segmentado.
 */
function selo_segmento(?string $manual, ?string $auto, bool $compacto = false): string
{
    $seg = \App\Services\SegmentacaoService::efetivo($manual, $auto);
    if ($seg === null || !isset(\App\Services\SegmentacaoService::ROTULOS[$seg])) {
        return '';
    }
    $cor = \App\Services\SegmentacaoService::CORES[$seg];
    $rotulo = $compacto ? $seg : \App\Services\SegmentacaoService::ROTULOS[$seg];
    $titulo = \App\Services\SegmentacaoService::DESCRICOES[$seg]
        . (trim((string) $manual) !== '' ? ' (fixado pelo gestor)' : '');
    return '<span class="badge text-bg-' . $cor . '" title="' . e($titulo) . '">' . e($rotulo) . '</span>';
}

/** Retenção da auditoria: apaga eventos mais antigos que N dias (padrão 1 ano). */
function auditoria_limpar_antiga(int $dias = 365): void
{
    try {
        \App\Core\Database::executar(
            'DELETE FROM auditoria WHERE criado_em < (NOW() - INTERVAL ? DAY)',
            [$dias]
        );
    } catch (\Throwable $e) { /* tabela ainda não migrada: ignora */ }
}

/**
 * Trilha de auditoria: registra quem fez o quê (best-effort — nunca quebra o fluxo).
 * Ex.: auditar('criar', 'visita', $id, 'Cliente Fulano');
 */
function auditar(string $acao, string $entidade, ?int $entidadeId = null, string $detalhe = ''): void
{
    try {
        \App\Core\Database::executar(
            'INSERT INTO auditoria (usuario_id, perfil, acao, tabela, registro_id, dados, ip) VALUES (?,?,?,?,?,?,?)',
            [
                \App\Core\Auth::id() ?: null,
                \App\Core\Auth::perfil() ?: null,
                mb_substr($acao, 0, 40),
                mb_substr($entidade, 0, 60),
                $entidadeId,
                mb_substr($detalhe, 0, 255) ?: null,
                mb_substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45) ?: null,
            ]
        );
    } catch (\Throwable $e) { /* colunas ainda não migradas: ignora */ }
}

/**
 * Diretório de uploads FORA do docroot (fotos/comprovantes/documentos não são
 * acessíveis por URL direta; tudo passa pela rota autenticada arquivo/upload).
 */
function uploads_dir(): string
{
    return dirname(__DIR__) . '/dados/uploads';
}

/**
 * URL autenticada para um arquivo enviado (foto, comprovante, documento).
 * $miniatura = true serve a versão 320px (listagens), com fallback no original.
 */
function upload_url(string $arquivo, bool $miniatura = false): string
{
    return 'index.php?r=arquivo/upload&f=' . rawurlencode($arquivo) . ($miniatura ? '&mini=1' : '');
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
    // HTML sempre revalida: garante que o app.js/app.css novos (versionados por ?v=)
    // sejam carregados após um deploy — sem isso o iPhone (PWA/Safari) pode reter a
    // página antiga e, com ela, o app.js antigo. Não afeta os assets (?v= = cache longo).
    if (!headers_sent()) {
        header('Cache-Control: no-cache, must-revalidate');
    }
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

/**
 * Select de MUNICÍPIO – UF a partir da lista pré-cadastrada (MunicipiosSul, SC/RS/PR),
 * em lista única ordenada por nome (o seletor do celular não mostra grupos bem).
 * $valorPor: 'nome' (value = nome oficial) ou 'ibge' (value = código IBGE).
 * Cada option leva data-nome, data-uf e data-cod para o JS.
 * A classe `select-busca` faz o app.js trocar o seletor nativo por um campo de
 * busca digitável (App.selectBusca) — no celular, 1.191 itens numa roda é inviável.
 */
function select_municipios(string $name, string $id, string $valorPor = 'nome', string $classe = 'form-select select-busca', string $extra = ''): string
{
    $itens = [];
    foreach (\App\Services\MunicipiosSul::porUf() as $uf => $muns) {
        foreach ($muns as $cod => $nome) {
            $itens[] = [(string) $cod, $nome, $uf];
        }
    }
    usort($itens, fn ($a, $b) => strcmp(
        iconv('UTF-8', 'ASCII//TRANSLIT', mb_strtolower($a[1])) ?: $a[1],
        iconv('UTF-8', 'ASCII//TRANSLIT', mb_strtolower($b[1])) ?: $b[1]
    ) ?: strcmp($a[2], $b[2]));
    $placeholder = str_contains($extra, 'data-placeholder=') ? '' : 'data-placeholder="Digite o nome do município…" ';
    $html = '<select name="' . e($name) . '" id="' . e($id) . '" class="' . e($classe) . '" ' . $placeholder . $extra . '>'
        . '<option value="">— selecione —</option>';
    foreach ($itens as [$cod, $nome, $uf]) {
        $valor = $valorPor === 'ibge' ? $cod : $nome;
        $html .= '<option value="' . e($valor) . '" data-nome="' . e($nome) . '" data-uf="' . e($uf) . '" data-cod="' . e($cod) . '">'
            . e($nome . ' – ' . $uf) . '</option>';
    }
    return $html . '</select>';
}

/**
 * v51 — SAFRA DO PLANTIO no formato "AAAA/AAAA" (pedido do teste de campo): 2025/2025 é a
 * safra de inverno/safrinha do ano, 2025/2026 a de verão. Opções em torno do ano atual
 * (2026 → 2025/2025, 2025/2026, 2026/2026, 2026/2027), mais a já gravada, se for outra.
 * Espelho de App.opcoesSafra / App.safraSugerida no app.js.
 */
function opcoes_safra(?string $incluir = null): array
{
    $y = (int) date('Y');
    $lista = [($y - 1) . '/' . ($y - 1), ($y - 1) . '/' . $y, $y . '/' . $y, $y . '/' . ($y + 1)];
    if ($incluir !== null && safra_valida($incluir) && !in_array($incluir, $lista, true)) {
        $lista[] = $incluir;
        sort($lista);
    }
    return $lista;
}

/** "AAAA/AAAA" com o 2º ano igual ao 1º ou o seguinte. */
function safra_valida(?string $safra): bool
{
    return $safra !== null && preg_match('/^(\d{4})\/(\d{4})$/', $safra, $m) === 1
        && ((int) $m[2] === (int) $m[1] || (int) $m[2] === (int) $m[1] + 1);
}

/** Safra sugerida pela data (hoje, se vazia): jan–fev → verão que termina; mar–ago → inverno/safrinha; set–dez → verão que começa. */
function safra_sugerida(?string $data = null): string
{
    $t = ($data !== null && $data !== '' ? date_create($data) : null) ?: date_create('today');
    $y = (int) $t->format('Y');
    $m = (int) $t->format('n');
    if ($m <= 2) {
        return ($y - 1) . '/' . $y;
    }
    if ($m <= 8) {
        return $y . '/' . $y;
    }
    return $y . '/' . ($y + 1);
}

/** Select de safra (opções de opcoes_safra, sugerida marcada). */
function select_safra(string $name, string $id, ?string $valor = null, string $classe = 'form-select', string $extra = ''): string
{
    $sel = $valor !== null && safra_valida($valor) ? $valor : safra_sugerida();
    $html = '<select name="' . e($name) . '" id="' . e($id) . '" class="' . e($classe) . '" ' . $extra . '>';
    foreach (opcoes_safra($sel) as $s) {
        $html .= '<option value="' . e($s) . '"' . ($s === $sel ? ' selected' : '') . '>' . e($s) . '</option>';
    }
    return $html . '</select>';
}
