<?php

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;

/**
 * Funil de oportunidades: geração automática (gap de recompra, potencial não
 * atendido, calendário agronômico), Kanban e regras de perda/aprovação.
 */
class OportunidadeService
{
    public const ESTAGIOS = ['Identificada', 'Proposta', 'Negociação', 'Ganha', 'Perdida'];

    /**
     * Gera oportunidades automáticas para a carteira do usuário logado.
     * Idempotente: a UNIQUE KEY uk_oport_auto impede duplicar a mesma
     * oportunidade automática (cliente+origem+família+safra+produto).
     */
    public static function gerarAutomaticas(string $filtroCarteira, array $params): int
    {
        $safra = ComercialService::safraAtual();
        if (!$safra) {
            return 0;
        }
        $safraId = (int) $safra['id'];
        $geradas = 0;

        $clientes = Database::todos(
            "SELECT c.id, c.nome FROM clientes c WHERE c.ativo = 1 AND {$filtroCarteira}",
            $params
        );

        foreach ($clientes as $cliente) {
            $clienteId = (int) $cliente['id'];
            $credito = CreditoService::situacao($clienteId);
            $pendente = $credito['exige_aprovacao'] ? 1 : 0;

            // 1) Gap de recompra — por produto
            foreach (ComercialService::gapRecompra($clienteId) as $gap) {
                $geradas += self::inserirAutomatica(
                    $clienteId,
                    'Gap de recompra',
                    "Recompra: {$gap['produto']}",
                    (float) $gap['valor_anterior'],
                    $safraId,
                    (int) $gap['familia_id'],
                    (int) $gap['produto_id'],
                    $pendente
                );
            }

            // 2) Potencial não atendido — família com menos de 50% do potencial comprado
            foreach (PotencialService::porCliente($clienteId) as $pot) {
                if ((float) $pot['percentual'] < 50 && (float) $pot['valor_potencial'] > 0) {
                    $familiaId = (int) Database::valor('SELECT id FROM familias_produto WHERE nome = ?', [$pot['familia']]);
                    $espaco = (float) $pot['valor_potencial'] - (float) $pot['realizado'];
                    $geradas += self::inserirAutomatica(
                        $clienteId,
                        'Potencial não atendido',
                        "Potencial em {$pot['familia']} ({$pot['percentual']}% atendido)",
                        $espaco,
                        $safraId,
                        $familiaId,
                        null,
                        $pendente
                    );
                }
            }

            // 3) Calendário agronômico — janela ativa sem compra da família
            $gatilhos = self::gatilhosCalendario($clienteId, $safraId);
            foreach ($gatilhos as $g) {
                $geradas += self::inserirAutomatica(
                    $clienteId,
                    'Calendário agronômico',
                    "{$g['atividade']} — {$g['cultura']} sem {$g['familia']} comprado",
                    (float) $g['valor_estimado'],
                    $safraId,
                    (int) $g['familia_id'],
                    null,
                    $pendente
                );
            }
        }

        return $geradas;
    }

    /** Janelas do calendário ativas no mês para culturas do plano de safra sem compra da família. */
    public static function gatilhosCalendario(int $clienteId, int $safraId): array
    {
        $mes = (int) date('n');
        return Database::todos(
            "SELECT ca.atividade, cu.nome AS cultura, f.id AS familia_id, f.nome AS familia,
                    SUM(ps.area_ha * COALESCE(cr.custo_por_ha, 0)) AS valor_estimado
               FROM planos_safra ps
               JOIN calendario_agronomico ca ON ca.cultura_id = ps.cultura_id
               JOIN culturas cu ON cu.id = ps.cultura_id
               JOIN familias_produto f ON f.id = ca.familia_id
               LEFT JOIN culturas_referencia cr ON cr.cultura_id = ps.cultura_id AND cr.familia_id = ca.familia_id
              WHERE ps.cliente_id = ? AND ps.safra_id = ?
                AND ca.familia_id IS NOT NULL
                AND (
                     (ca.mes_inicio <= ca.mes_fim AND ? BETWEEN ca.mes_inicio AND ca.mes_fim)
                  OR (ca.mes_inicio > ca.mes_fim AND (? >= ca.mes_inicio OR ? <= ca.mes_fim))
                )
                AND NOT EXISTS (
                     SELECT 1 FROM compras co JOIN produtos p ON p.id = co.produto_id
                      WHERE co.cliente_id = ps.cliente_id AND co.safra_id = ps.safra_id
                        AND p.familia_id = ca.familia_id
                )
              GROUP BY ca.atividade, cu.nome, f.id, f.nome",
            [$clienteId, $safraId, $mes, $mes, $mes]
        );
    }

    private static function inserirAutomatica(
        int $clienteId,
        string $origem,
        string $titulo,
        float $valor,
        int $safraId,
        ?int $familiaId,
        ?int $produtoId,
        int $pendente
    ): int {
        // Verificação explícita com comparação NULL-safe (<=>): a UNIQUE KEY não
        // barra duplicatas quando produto_id/família é NULL.
        $existe = Database::valor(
            'SELECT 1 FROM oportunidades
              WHERE cliente_id = ? AND origem = ? AND safra_id <=> ?
                AND familia_id <=> ? AND produto_id <=> ?
              LIMIT 1',
            [$clienteId, $origem, $safraId, $familiaId, $produtoId]
        );
        if ($existe) {
            return 0;
        }
        Database::executar(
            'INSERT IGNORE INTO oportunidades
                (cliente_id, usuario_id, safra_id, familia_id, produto_id, origem, titulo, valor_estimado, estagio, pendente_aprovacao, data_prevista)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, "Identificada", ?, DATE_ADD(CURDATE(), INTERVAL 30 DAY))',
            [$clienteId, Auth::id() ?: null, $safraId, $familiaId, $produtoId, $origem, $titulo, $valor, $pendente]
        );
        return 1;
    }

    /** Oportunidades agrupadas por estágio (Kanban). */
    public static function kanban(string $filtroCarteira, array $params): array
    {
        $lista = Database::todos(
            "SELECT o.*, c.nome AS cliente, f.nome AS familia,
                    (SELECT MIN(p.validade) FROM propostas p WHERE p.oportunidade_id = o.id AND p.situacao = 'Em aberto') AS proposta_validade
               FROM oportunidades o
               JOIN clientes c ON c.id = o.cliente_id
               LEFT JOIN familias_produto f ON f.id = o.familia_id
              WHERE {$filtroCarteira}
              ORDER BY o.valor_estimado DESC",
            $params
        );
        $kanban = array_fill_keys(self::ESTAGIOS, []);
        foreach ($lista as $o) {
            $o['proposta_vencendo'] = $o['proposta_validade']
                && strtotime($o['proposta_validade']) <= strtotime('+7 days');
            $kanban[$o['estagio']][] = $o;
        }
        return $kanban;
    }

    /** Totais por estágio para os cartões do funil. */
    public static function totais(array $kanban): array
    {
        $totais = [];
        foreach ($kanban as $estagio => $itens) {
            $totais[$estagio] = [
                'qtd' => count($itens),
                'valor' => array_sum(array_map(fn ($o) => (float) $o['valor_estimado'], $itens)),
            ];
        }
        return $totais;
    }
}
