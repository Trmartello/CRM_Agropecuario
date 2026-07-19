<?php

namespace App\Services;

use App\Core\Database;

/**
 * Dados comerciais do produtor: compras por safra, gap de recompra,
 * inadimplência e queda de compra (churn).
 *
 * Fase 5: esta classe passa a consultar o ERP — manter a interface estável.
 */
class ComercialService
{
    /** Queda de compra acima deste percentual marca risco de churn. */
    public const LIMIAR_CHURN = 0.30;

    /** Cache por requisição (as safras não mudam no meio de uma página). */
    private static array $cacheSafras = [];

    public static function safraAtual(): ?array
    {
        if (!array_key_exists('atual', self::$cacheSafras)) {
            self::$cacheSafras['atual'] = Database::um('SELECT * FROM safras WHERE atual = 1 LIMIT 1');
        }
        return self::$cacheSafras['atual'];
    }

    public static function safraAnterior(): ?array
    {
        if (!array_key_exists('anterior', self::$cacheSafras)) {
            self::$cacheSafras['anterior'] = Database::um(
                'SELECT * FROM safras WHERE atual = 0 ORDER BY data_inicio DESC LIMIT 1'
            );
        }
        return self::$cacheSafras['anterior'];
    }

    /** Compras do cliente em uma safra, com produto e família. */
    public static function comprasDaSafra(int $clienteId, int $safraId): array
    {
        return Database::todos(
            'SELECT co.*, p.nome AS produto, p.unidade, f.nome AS familia, f.id AS familia_id
               FROM compras co
               JOIN produtos p ON p.id = co.produto_id
               JOIN familias_produto f ON f.id = p.familia_id
              WHERE co.cliente_id = ? AND co.safra_id = ?
              ORDER BY co.data_compra DESC',
            [$clienteId, $safraId]
        );
    }

    /**
     * Gap de recompra: produtos comprados na safra anterior que ainda não
     * foram comprados na safra atual — oportunidades de venda.
     */
    public static function gapRecompra(int $clienteId): array
    {
        $atual = self::safraAtual();
        $anterior = self::safraAnterior();
        if (!$atual || !$anterior) {
            return [];
        }
        return Database::todos(
            'SELECT p.id AS produto_id, p.nome AS produto, f.id AS familia_id, f.nome AS familia,
                    SUM(co.quantidade) AS quantidade_anterior, SUM(co.valor_total) AS valor_anterior
               FROM compras co
               JOIN produtos p ON p.id = co.produto_id
               JOIN familias_produto f ON f.id = p.familia_id
              WHERE co.cliente_id = ? AND co.safra_id = ?
                AND p.id NOT IN (
                      SELECT produto_id FROM compras WHERE cliente_id = ? AND safra_id = ?
                )
              GROUP BY p.id, p.nome, f.id, f.nome
              ORDER BY valor_anterior DESC',
            [$clienteId, $anterior['id'], $clienteId, $atual['id']]
        );
    }

    /**
     * Situação financeira do cliente com a Copérdia.
     * Retorna: grau (Adimplente/Leve/Média/Grave), valor em aberto vencido,
     * dias de atraso do título mais antigo e valor a vencer.
     */
    public static function inadimplencia(int $clienteId): array
    {
        $linha = Database::um(
            "SELECT
                COALESCE(SUM(CASE WHEN vencimento < CURDATE() THEN valor END), 0) AS valor_vencido,
                COALESCE(SUM(CASE WHEN vencimento >= CURDATE() THEN valor END), 0) AS valor_a_vencer,
                COALESCE(MAX(CASE WHEN vencimento < CURDATE() THEN DATEDIFF(CURDATE(), vencimento) END), 0) AS dias_atraso
               FROM titulos_financeiros
              WHERE cliente_id = ? AND situacao = 'Aberto'",
            [$clienteId]
        );
        $valorVencido = (float) $linha['valor_vencido'];
        $diasAtraso = (int) $linha['dias_atraso'];

        if ($valorVencido <= 0) {
            $grau = 'Adimplente';
            $cor = 'success';
        } elseif ($diasAtraso <= 30) {
            $grau = 'Inadimplência leve';
            $cor = 'warning';
        } elseif ($diasAtraso <= 90) {
            $grau = 'Inadimplência média';
            $cor = 'orange';
        } else {
            $grau = 'Inadimplência grave';
            $cor = 'danger';
        }

        return [
            'grau' => $grau,
            'cor' => $cor,
            'inadimplente' => $valorVencido > 0,
            'valor_vencido' => $valorVencido,
            'valor_a_vencer' => (float) $linha['valor_a_vencer'],
            'dias_atraso' => $diasAtraso,
        ];
    }

    /**
     * Queda de compra: compara o realizado da safra atual com o mesmo período
     * decorrido da safra anterior. Queda acima do limiar marca risco de churn.
     */
    public static function quedaCompra(int $clienteId): array
    {
        return self::quedaCompraLote([$clienteId])[$clienteId];
    }

    /**
     * Queda de compra (anti-churn) de VÁRIOS clientes em 2 consultas agregadas
     * — usada pela priorização/snapshot para não fazer consultas por cliente.
     */
    public static function quedaCompraLote(array $clienteIds): array
    {
        $vazio = ['queda' => 0, 'risco_churn' => false, 'total_atual' => 0, 'total_anterior_periodo' => 0];
        $ids = array_values(array_unique(array_map('intval', $clienteIds)));
        if (!$ids) {
            return [];
        }
        $resultado = array_fill_keys($ids, $vazio);

        $atual = self::safraAtual();
        $anterior = self::safraAnterior();
        if (!$atual || !$anterior) {
            return $resultado;
        }

        // Dias decorridos da safra atual → mesmo recorte na safra anterior
        $diasDecorridos = (int) Database::valor(
            'SELECT DATEDIFF(LEAST(CURDATE(), data_fim), data_inicio) FROM safras WHERE id = ?',
            [$atual['id']]
        );

        $marcadores = implode(',', array_fill(0, count($ids), '?'));
        $totaisAtuais = [];
        foreach (Database::todos(
            "SELECT cliente_id, COALESCE(SUM(valor_total),0) AS total
               FROM compras WHERE safra_id = ? AND cliente_id IN ({$marcadores})
              GROUP BY cliente_id",
            array_merge([$atual['id']], $ids)
        ) as $l) {
            $totaisAtuais[(int) $l['cliente_id']] = (float) $l['total'];
        }
        $totaisAnteriores = [];
        foreach (Database::todos(
            "SELECT cliente_id, COALESCE(SUM(valor_total),0) AS total
               FROM compras
              WHERE safra_id = ? AND data_compra <= DATE_ADD(?, INTERVAL ? DAY)
                AND cliente_id IN ({$marcadores})
              GROUP BY cliente_id",
            array_merge([$anterior['id'], $anterior['data_inicio'], $diasDecorridos], $ids)
        ) as $l) {
            $totaisAnteriores[(int) $l['cliente_id']] = (float) $l['total'];
        }

        foreach ($ids as $id) {
            $totalAtual = $totaisAtuais[$id] ?? 0.0;
            $totalAnteriorPeriodo = $totaisAnteriores[$id] ?? 0.0;
            $queda = $totalAnteriorPeriodo > 0
                ? max(0.0, 1 - ($totalAtual / $totalAnteriorPeriodo))
                : 0.0;
            $resultado[$id] = [
                'queda' => $queda,
                'risco_churn' => $queda > self::LIMIAR_CHURN,
                'total_atual' => $totalAtual,
                'total_anterior_periodo' => $totalAnteriorPeriodo,
            ];
        }
        return $resultado;
    }

    /** Histórico completo de compras (todas as safras) — consulta comercial. */
    public static function historicoCompras(int $clienteId): array
    {
        return Database::todos(
            'SELECT co.*, p.nome AS produto, p.unidade, f.nome AS familia, s.nome AS safra
               FROM compras co
               JOIN produtos p ON p.id = co.produto_id
               JOIN familias_produto f ON f.id = p.familia_id
               JOIN safras s ON s.id = co.safra_id
              WHERE co.cliente_id = ?
              ORDER BY co.data_compra DESC',
            [$clienteId]
        );
    }

    /** Painel comercial completo do cliente (usado na visita e na ficha). */
    public static function painelCliente(int $clienteId): array
    {
        $atual = self::safraAtual();
        return [
            'safra' => $atual,
            'compras_safra_atual' => $atual ? self::comprasDaSafra($clienteId, (int) $atual['id']) : [],
            'gap_recompra' => self::gapRecompra($clienteId),
            'inadimplencia' => self::inadimplencia($clienteId),
            'queda' => self::quedaCompra($clienteId),
            'credito' => CreditoService::situacao($clienteId),
            'entregas_futuras' => PedidoService::entregasFuturas($clienteId),
            'pedidos' => PedidoService::doCliente($clienteId),
        ];
    }
}
