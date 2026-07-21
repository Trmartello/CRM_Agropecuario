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
