<?php

namespace App\Services;

use App\Core\Database;

/**
 * Crédito e segurança de venda: limite, score interno A/B/C/D,
 * alçada de aprovação e garantias.
 *
 * Fase 5: fonte passa a ser o financeiro do ERP — manter interface estável.
 */
class CreditoService
{
    /** Situação de crédito consolidada do cliente. */
    public static function situacao(int $clienteId): array
    {
        $cliente = Database::um('SELECT limite_credito, volume_compra_anual, criado_em FROM clientes WHERE id = ?', [$clienteId]);
        if (!$cliente) {
            return ['limite' => 0, 'utilizado' => 0, 'disponivel' => 0, 'score' => 'D', 'exige_aprovacao' => true, 'garantias' => []];
        }

        $limite = (float) $cliente['limite_credito'];

        // Crédito utilizado = títulos em aberto (vencidos e a vencer)
        $utilizado = (float) Database::valor(
            "SELECT COALESCE(SUM(valor),0) FROM titulos_financeiros WHERE cliente_id = ? AND situacao = 'Aberto'",
            [$clienteId]
        );

        $inad = ComercialService::inadimplencia($clienteId);
        $garantias = self::garantias($clienteId);
        $valorGarantias = array_sum(array_map(fn ($g) => (float) $g['valor'], array_filter($garantias, fn ($g) => !$g['vencida'])));

        $score = self::calcularScore($clienteId, $inad, $limite, $utilizado, $valorGarantias);

        $acimaDoLimite = $limite > 0 && $utilizado > $limite;
        $exigeAprovacao = $inad['inadimplente'] || $acimaDoLimite;

        return [
            'limite' => $limite,
            'utilizado' => $utilizado,
            'disponivel' => max(0, $limite - $utilizado),
            'percentual_utilizado' => $limite > 0 ? min(100, round($utilizado / $limite * 100)) : 0,
            'acima_do_limite' => $acimaDoLimite,
            'score' => $score,
            'exige_aprovacao' => $exigeAprovacao,
            'motivo_aprovacao' => $exigeAprovacao
                ? ($inad['inadimplente'] ? 'Cliente inadimplente' : 'Limite de crédito excedido')
                : null,
            'garantias' => $garantias,
            'valor_garantias' => $valorGarantias,
        ];
    }

    /**
     * Score interno A/B/C/D:
     *  A — em dia, folga de limite, com garantia
     *  B — em dia
     *  C — atraso leve/médio ou limite estourado
     *  D — inadimplência grave
     */
    private static function calcularScore(int $clienteId, array $inad, float $limite, float $utilizado, float $valorGarantias): string
    {
        if ($inad['dias_atraso'] > 90) {
            return 'D';
        }
        if ($inad['inadimplente'] || ($limite > 0 && $utilizado > $limite)) {
            return 'C';
        }
        // Histórico: pagou títulos nos últimos 12 meses sem atraso?
        $pagosEmDia = (int) Database::valor(
            "SELECT COUNT(*) FROM titulos_financeiros
              WHERE cliente_id = ? AND situacao = 'Pago'
                AND data_pagamento IS NOT NULL AND data_pagamento <= vencimento
                AND vencimento >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)",
            [$clienteId]
        );
        $folga = $limite > 0 ? ($limite - $utilizado) / $limite : 0;
        if ($pagosEmDia > 0 && $folga >= 0.3 && $valorGarantias > 0) {
            return 'A';
        }
        return 'B';
    }

    /** Garantias do cliente com flags de vencida/vencendo (60 dias). */
    public static function garantias(int $clienteId): array
    {
        $lista = Database::todos(
            'SELECT * FROM garantias WHERE cliente_id = ? ORDER BY vencimento',
            [$clienteId]
        );
        foreach ($lista as &$g) {
            $dias = (int) floor((strtotime($g['vencimento']) - time()) / 86400);
            $g['vencida'] = $dias < 0;
            $g['vencendo'] = $dias >= 0 && $dias <= 60;
            $g['dias_para_vencer'] = $dias;
        }
        return $lista;
    }

    /** Garantias vencendo em até 60 dias em toda a carteira (alerta do dashboard). */
    public static function garantiasVencendo(string $filtroCarteira, array $params): array
    {
        return Database::todos(
            "SELECT g.*, c.nome AS cliente
               FROM garantias g
               JOIN clientes c ON c.id = g.cliente_id
              WHERE {$filtroCarteira}
                AND g.vencimento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)
              ORDER BY g.vencimento",
            $params
        );
    }
}
