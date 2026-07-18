<?php

namespace App\Services;

use App\Core\Database;

/**
 * Potencial x Realizado por família de produtos.
 * Gráfico por cliente e ranking consolidável por dimensão
 * (Produtor / Município / Estado / Filial).
 */
class PotencialService
{
    /** Potencial x realizado por família para um cliente (gráfico da ficha). */
    public static function porCliente(int $clienteId): array
    {
        $safra = ComercialService::safraAtual();
        if (!$safra) {
            return [];
        }
        return Database::todos(
            'SELECT f.nome AS familia,
                    pc.valor_potencial,
                    COALESCE(re.realizado, 0) AS realizado,
                    ROUND(COALESCE(re.realizado, 0) / pc.valor_potencial * 100, 1) AS percentual
               FROM potencial_compra pc
               JOIN familias_produto f ON f.id = pc.familia_id
               LEFT JOIN (
                    SELECT p.familia_id, SUM(co.valor_total) AS realizado
                      FROM compras co
                      JOIN produtos p ON p.id = co.produto_id
                     WHERE co.cliente_id = ? AND co.safra_id = ?
                     GROUP BY p.familia_id
               ) re ON re.familia_id = pc.familia_id
              WHERE pc.cliente_id = ? AND pc.safra_id = ?
              ORDER BY f.nome',
            [$clienteId, (int) $safra['id'], $clienteId, (int) $safra['id']]
        );
    }

    /**
     * Ranking de aproveitamento do potencial consolidado pela dimensão pedida.
     * $dimensao: cliente | municipio | estado | filial
     * $ordem: asc | desc (percentual de utilização)
     */
    public static function ranking(string $dimensao, string $ordem, string $filtroCarteira, array $params, ?int $familiaId = null): array
    {
        $safra = ComercialService::safraAtual();
        if (!$safra) {
            return [];
        }

        $campoDimensao = match ($dimensao) {
            'municipio' => 'c.municipio',
            'estado' => 'c.estado',
            'filial' => "COALESCE(fi.nome, 'Sem filial')",
            default => 'c.nome',
        };
        $ordemSql = strtolower($ordem) === 'asc' ? 'ASC' : 'DESC';

        $filtroFamilia = '';
        $paramsFamilia = [];
        if ($familiaId) {
            $filtroFamilia = ' AND pc.familia_id = ? ';
            $paramsFamilia[] = $familiaId;
        }

        $sql =
            "SELECT {$campoDimensao} AS dimensao,
                    SUM(pc.valor_potencial) AS potencial,
                    COALESCE(SUM(re.realizado), 0) AS realizado,
                    ROUND(COALESCE(SUM(re.realizado), 0) / SUM(pc.valor_potencial) * 100, 1) AS percentual
               FROM potencial_compra pc
               JOIN clientes c ON c.id = pc.cliente_id
               LEFT JOIN filiais fi ON fi.id = c.filial_id
               LEFT JOIN (
                    SELECT co.cliente_id, p.familia_id, SUM(co.valor_total) AS realizado
                      FROM compras co
                      JOIN produtos p ON p.id = co.produto_id
                     WHERE co.safra_id = ?
                     GROUP BY co.cliente_id, p.familia_id
               ) re ON re.cliente_id = pc.cliente_id AND re.familia_id = pc.familia_id
              WHERE pc.safra_id = ? AND c.ativo = 1 AND {$filtroCarteira} {$filtroFamilia}
              GROUP BY dimensao
              ORDER BY percentual {$ordemSql}";

        return Database::todos(
            $sql,
            array_merge([(int) $safra['id'], (int) $safra['id']], $params, $paramsFamilia)
        );
    }

    /**
     * Demanda projetada pelo plano de safra do cliente (área x custo/ha por família)
     * comparada ao realizado — base do orçamento de venda.
     */
    public static function demandaPlanoSafra(int $clienteId): array
    {
        $safra = ComercialService::safraAtual();
        if (!$safra) {
            return [];
        }
        return Database::todos(
            'SELECT f.id AS familia_id, f.nome AS familia,
                    SUM(ps.area_ha * cr.custo_por_ha) AS demanda_projetada,
                    COALESCE(re.realizado, 0) AS realizado
               FROM planos_safra ps
               JOIN culturas_referencia cr ON cr.cultura_id = ps.cultura_id
               JOIN familias_produto f ON f.id = cr.familia_id
               LEFT JOIN (
                    SELECT p.familia_id, SUM(co.valor_total) AS realizado
                      FROM compras co JOIN produtos p ON p.id = co.produto_id
                     WHERE co.cliente_id = ? AND co.safra_id = ?
                     GROUP BY p.familia_id
               ) re ON re.familia_id = f.id
              WHERE ps.cliente_id = ? AND ps.safra_id = ?
              GROUP BY f.id, f.nome, re.realizado
              ORDER BY demanda_projetada DESC',
            [$clienteId, (int) $safra['id'], $clienteId, (int) $safra['id']]
        );
    }
}
