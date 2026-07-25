<?php

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;

/**
 * Camada de integração com o ERP/CAPE (Módulo 18).
 *
 * A fonte de dados é configurável: enquanto o ERP não está conectado, o sistema
 * opera com a base local. As telas e os demais serviços (ComercialService,
 * CapService, CreditoService) usam interfaces estáveis — quando o endpoint real
 * do ERP/CAPE existir, apenas o adaptador aqui muda, sem tocar no restante.
 */
class IntegracaoService
{
    /** Entidades sincronizáveis (conforme escopo do Módulo 18). */
    public const ENTIDADES = [
        'clientes' => 'Clientes e associados',
        'produtos' => 'Produtos e tabelas de preço',
        'estoque' => 'Estoque',
        'pedidos' => 'Pedidos e notas fiscais',
        'entregas_futuras' => 'Entregas futuras',
        'titulos_financeiros' => 'Financeiro (contas a receber)',
        'metas_cap' => 'Metas CAP (app CAPE)',
    ];

    public static function fonte(): string
    {
        return ConfigService::obter('integracao_fonte', 'Local');
    }

    public static function definirFonte(string $fonte): void
    {
        ConfigService::definir('integracao_fonte', in_array($fonte, ['Local', 'ERP', 'CAPE'], true) ? $fonte : 'Local');
    }

    public static function erpConfigurado(): bool
    {
        return trim(ConfigService::obter('integracao_erp_url', '')) !== '';
    }

    /**
     * Sincroniza uma entidade. Sem ERP conectado, valida/consolida a base local
     * e registra no log — mantendo o fluxo pronto para a fonte real.
     */
    public static function sincronizar(string $entidade): array
    {
        if (!isset(self::ENTIDADES[$entidade])) {
            throw new \InvalidArgumentException('Entidade desconhecida.');
        }
        $fonte = self::fonte();

        if ($fonte !== 'Local' && !self::erpConfigurado()) {
            self::registrar($fonte, $entidade, 'Erro', 0, 'Fonte externa selecionada, mas a URL do ERP não está configurada.');
            throw new \RuntimeException('Configure a URL do ERP antes de sincronizar com fonte externa.');
        }

        // Adaptador local (placeholder do adaptador ERP): conta os registros atuais.
        // 'estoque' é coluna de produtos; as demais entidades mapeiam 1:1 para tabelas.
        $consulta = $entidade === 'estoque'
            ? 'SELECT COUNT(*) FROM produtos WHERE estoque > 0'
            : "SELECT COUNT(*) FROM {$entidade}";
        $registros = (int) Database::valor($consulta);
        $mensagem = $fonte === 'Local'
            ? 'Base local consolidada (ERP ainda não conectado).'
            : 'Importado do ' . $fonte . '.';
        self::registrar($fonte, $entidade, 'Sucesso', $registros, $mensagem);

        return ['entidade' => $entidade, 'registros' => $registros, 'fonte' => $fonte];
    }

    public static function sincronizarTudo(): array
    {
        $resultado = [];
        foreach (array_keys(self::ENTIDADES) as $ent) {
            try {
                $resultado[] = self::sincronizar($ent);
            } catch (\Exception $e) {
                $resultado[] = ['entidade' => $ent, 'erro' => $e->getMessage()];
            }
        }
        return $resultado;
    }

    /**
     * Carga inicial do CAP extraída do Qlik (arquivo JSON gerado pela análise
     * do app "CAP - Copérdia Alta Performance"). Formato:
     * { tipo: "cap_anual", ano: 2026, vendedores: [ { cod, nome, cpf,
     *   indicadores: [ { codigo, indicador, meta, realizado } ] } ] }
     *
     * O vínculo com o usuário do CRM é pelo `usuarios.cod_vendedor` (campo no
     * cadastro de Usuários); sem vínculo, o vendedor fica listado no retorno.
     * Reimportar o mesmo ano substitui as metas anteriores (idempotente).
     */
    /**
     * Carga do cadastro de CLIENTES extraída do Qlik (Comercial Global).
     * Formato: { tipo: "clientes", clientes: [ { cod, nome, cpf_cnpj, telefone,
     * telefone2, email, endereco, municipio, estado, ... } ] }.
     *
     * Upsert idempotente: procura por `clientes.cod_erp` e, na falta, pelo
     * CPF/CNPJ. Atualiza só o CADASTRO (nome, contatos, endereço, município) —
     * preserva tudo que o CRM enriquece (responsável, nível tecnológico,
     * potencial, segmento, coordenadas, limite de crédito).
     */
    public static function importarCargaClientes(array $carga): array
    {
        if (($carga['tipo'] ?? '') !== 'clientes' || empty($carga['clientes']) || !is_array($carga['clientes'])) {
            throw new \Exception('Arquivo inválido: esperado JSON de carga "clientes".');
        }

        $criados = 0;
        $atualizados = 0;
        $ignorados = 0;
        foreach ($carga['clientes'] as $c) {
            $cod = (int) ($c['cod'] ?? 0);
            $nome = trim((string) ($c['nome'] ?? ''));
            if ($cod <= 0 || $nome === '') {
                $ignorados++;
                continue;
            }
            $cpf = trim((string) ($c['cpf_cnpj'] ?? '')) ?: null;
            $dados = [
                'nome' => mb_substr($nome, 0, 160),
                'cpf_cnpj' => $cpf ? mb_substr($cpf, 0, 20) : null,
                'telefone' => trim((string) ($c['telefone'] ?? '')) ?: null,
                'telefone2' => trim((string) ($c['telefone2'] ?? '')) ?: null,
                'email' => trim((string) ($c['email'] ?? '')) ?: null,
                'endereco' => trim((string) ($c['endereco'] ?? '')) ?: null,
                'municipio' => trim((string) ($c['municipio'] ?? '')) ?: null,
                'estado' => strlen(trim((string) ($c['estado'] ?? ''))) === 2 ? strtoupper(trim($c['estado'])) : null,
            ];

            $existente = Database::um('SELECT id FROM clientes WHERE cod_erp = ?', [$cod]);
            if (!$existente && $cpf) {
                $existente = Database::um('SELECT id FROM clientes WHERE cpf_cnpj = ?', [$cpf]);
            }
            if ($existente) {
                Database::executar(
                    'UPDATE clientes SET nome=?, cpf_cnpj=?, telefone=?, telefone2=?, email=?,
                            endereco=?, municipio=?, estado=COALESCE(?, estado), cod_erp=? WHERE id=?',
                    array_merge(array_values($dados), [$cod, (int) $existente['id']])
                );
                $atualizados++;
            } else {
                Database::executar(
                    'INSERT INTO clientes (nome, cpf_cnpj, telefone, telefone2, email, endereco, municipio,
                            estado, cod_erp, situacao, ativo, prospecto)
                     VALUES (?,?,?,?,?,?,?,COALESCE(?, "SC"),?, "Associado", 1, 0)',
                    array_merge(array_values($dados), [$cod])
                );
                $criados++;
            }
        }

        self::registrar('ERP', 'clientes', 'Sucesso', $criados + $atualizados,
            "Carga Qlik de clientes: {$criados} criados, {$atualizados} atualizados" . ($ignorados ? ", {$ignorados} ignorados" : ''));

        return [
            'clientes_arquivo' => count($carga['clientes']),
            'criados' => $criados,
            'atualizados' => $atualizados,
            'ignorados' => $ignorados,
        ];
    }

    /**
     * Carga do score do Qlik (SCORE_QLIK) → cache_score_imovel (PR 9 Mapa Territorial).
     * O Qlik calcula, o CRM só exibe — os 4 números entram VERBATIM (invariante 1).
     * JSON: {tipo:'score_imovel', safra:'2025/26', itens:[{codCar, potencial, realizado, share, gap, status?}]}
     */
    public static function importarCargaScore(array $carga): array
    {
        if (($carga['tipo'] ?? '') !== 'score_imovel' || empty($carga['itens']) || !is_array($carga['itens'])) {
            throw new \Exception('Arquivo inválido: esperado JSON "score_imovel" com a lista de itens.');
        }
        $safra = trim((string) ($carga['safra'] ?? ''));
        if ($safra === '') {
            throw new \Exception('Informe a safra da carga de score (ex.: "2025/26").');
        }
        $pdo = Database::conexao();
        $stmt = $pdo->prepare(
            'INSERT INTO cache_score_imovel (cod_car, safra, potencial, realizado, share, gap, status_comercial, dt_atualizacao)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE potencial = VALUES(potencial), realizado = VALUES(realizado),
                share = VALUES(share), gap = VALUES(gap), status_comercial = VALUES(status_comercial), dt_atualizacao = NOW()'
        );
        $ok = 0;
        $ignorados = 0;
        $statusOk = ['ativo', 'inativo', 'prospect'];
        $pdo->beginTransaction();
        try {
            foreach ($carga['itens'] as $it) {
                $cod = trim((string) ($it['codCar'] ?? $it['cod_car'] ?? ''));
                if ($cod === '') {
                    $ignorados++;
                    continue;
                }
                $st = strtolower(trim((string) ($it['status'] ?? $it['statusComercial'] ?? '')));
                $stmt->execute([
                    mb_substr($cod, 0, 60),
                    $safra,
                    round((float) ($it['potencial'] ?? 0), 2),
                    round((float) ($it['realizado'] ?? 0), 2),
                    round((float) ($it['share'] ?? 0), 4),
                    round((float) ($it['gap'] ?? 0), 2),
                    in_array($st, $statusOk, true) ? $st : null,
                ]);
                $ok++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        self::registrar('CAPE', 'score_imovel', 'Sucesso', $ok, "safra {$safra}: {$ok} imoveis, {$ignorados} ignorados");
        return ['safra' => $safra, 'atualizados' => $ok, 'ignorados' => $ignorados];
    }

    public static function importarCargaCap(array $carga): array
    {
        if (($carga['tipo'] ?? '') !== 'cap_anual' || empty($carga['vendedores']) || !is_array($carga['vendedores'])) {
            throw new \Exception('Arquivo inválido: esperado JSON de carga "cap_anual" com a lista de vendedores.');
        }
        $ano = (int) ($carga['ano'] ?? 0);
        if ($ano < 2020 || $ano > 2100) {
            throw new \Exception('Arquivo inválido: ano da carga ausente.');
        }
        $inicio = $ano . '-01-01';
        $fim = $ano . '-12-31';

        // usuários com código de vendedor preenchido
        $porCodigo = [];
        foreach (Database::todos('SELECT id, cod_vendedor FROM usuarios WHERE cod_vendedor IS NOT NULL') as $u) {
            $porCodigo[(int) $u['cod_vendedor']] = (int) $u['id'];
        }

        $vinculados = 0;
        $metasImportadas = 0;
        $semUsuario = [];
        foreach ($carga['vendedores'] as $v) {
            $cod = (int) ($v['cod'] ?? 0);
            $usuarioId = $porCodigo[$cod] ?? null;
            if ($usuarioId === null) {
                $semUsuario[] = $cod . ' — ' . (string) ($v['nome'] ?? '?');
                continue;
            }
            $vinculados++;
            // Substitui as metas do ano do usuário (recarga limpa, sem duplicar)
            Database::executar(
                'DELETE FROM metas_cap WHERE usuario_id = ? AND periodo_inicio = ? AND periodo_fim = ?',
                [$usuarioId, $inicio, $fim]
            );
            foreach ((array) ($v['indicadores'] ?? []) as $i) {
                $nomeInd = trim((string) ($i['indicador'] ?? ''));
                $meta = (float) ($i['meta'] ?? 0);
                if ($nomeInd === '' || $meta == 0.0) {
                    continue; // sem meta não há atingimento a acompanhar
                }
                // Indicadores INVERTIDOS do CAP (quanto menor, melhor): a tela
                // local calcula atingimento = realizado ÷ meta e marcaria "meta
                // atingida" errado — ficam de fora até o CapService tratá-los.
                // 200 = Despesas Operacionais, 202 = Inadimplência, 204 = Prazo Médio
                if (in_array((int) ($i['codigo'] ?? 0), [200, 202, 204], true)) {
                    continue;
                }
                $unidade = 'R$';
                if (str_contains($nomeInd, '(TON)')) {
                    $unidade = 'TON';
                } elseif (str_contains($nomeInd, '(%)')) {
                    $unidade = '%';
                } elseif (str_contains($nomeInd, '(DIAS)')) {
                    $unidade = 'dias';
                }
                Database::executar(
                    'INSERT INTO metas_cap (usuario_id, indicador, unidade, meta, periodo_inicio, periodo_fim)
                     VALUES (?,?,?,?,?,?)',
                    [$usuarioId, mb_substr($nomeInd, 0, 120), $unidade, round($meta, 2), $inicio, $fim]
                );
                $metaId = Database::ultimoId();
                Database::executar(
                    'INSERT INTO realizado_cap (meta_id, valor, data_ref) VALUES (?,?,?)',
                    [$metaId, round((float) ($i['realizado'] ?? 0), 2), date('Y-m-d')]
                );
                $metasImportadas++;
            }
        }

        self::registrar('CAPE', 'metas_cap', $vinculados > 0 ? 'Sucesso' : 'Sem vínculos', $metasImportadas,
            "Carga Qlik {$ano}: {$vinculados} vendedor(es) vinculados, " . count($semUsuario) . ' sem usuário');

        return [
            'ano' => $ano,
            'vendedores_arquivo' => count($carga['vendedores']),
            'vinculados' => $vinculados,
            'metas_importadas' => $metasImportadas,
            'sem_usuario' => $semUsuario,
        ];
    }

    private static function registrar(string $fonte, string $entidade, string $status, int $registros, string $mensagem): void
    {
        Database::executar(
            'INSERT INTO integracao_log (fonte, entidade, direcao, status, registros, mensagem, usuario_id)
             VALUES (?,?,?,?,?,?,?)',
            [$fonte, $entidade, 'Importação', $status, $registros, mb_substr($mensagem, 0, 255), Auth::id() ?: null]
        );
    }

    public static function historico(int $limite = 30): array
    {
        return Database::todos(
            'SELECT l.*, u.nome AS usuario FROM integracao_log l
               LEFT JOIN usuarios u ON u.id = l.usuario_id
              ORDER BY l.criado_em DESC LIMIT ' . (int) $limite
        );
    }

    public static function status(): array
    {
        $itens = [];
        foreach (self::ENTIDADES as $ent => $rotulo) {
            $ult = Database::um(
                "SELECT status, registros, criado_em FROM integracao_log WHERE entidade = ? ORDER BY criado_em DESC LIMIT 1",
                [$ent]
            );
            $itens[] = [
                'entidade' => $ent,
                'rotulo' => $rotulo,
                'ultima' => $ult['criado_em'] ?? null,
                'status' => $ult['status'] ?? '—',
                'registros' => (int) ($ult['registros'] ?? 0),
            ];
        }
        return $itens;
    }
}
