<?php

namespace App\Services;

use App\Core\Database;

/**
 * Priorização de visitas: ordena os produtores da carteira pelo score
 * de prioridade. O técnico deve visitar na ordem apresentada.
 *
 * Score (0–100) = soma ponderada de:
 *  - tempo desde a última visita
 *  - nível tecnológico
 *  - volume de compras
 *  - potencial de venda futura
 *  - risco de churn (queda de compra vs. safra anterior)
 */
class PriorizacaoService
{
    // Pesos dos fatores (somam 1.0)
    public const PESO_TEMPO = 0.30;
    public const PESO_NIVEL = 0.15;
    public const PESO_VOLUME = 0.20;
    public const PESO_POTENCIAL = 0.20;
    public const PESO_CHURN = 0.15;

    /** Dias sem visita que valem pontuação máxima no fator tempo. */
    private const TETO_DIAS = 120;

    /**
     * Lista priorizada da carteira.
     * $filtroCarteira/$params vêm de Permissoes::filtroCarteira().
     */
    public static function listaPriorizada(string $filtroCarteira, array $params, int $limite = 0): array
    {
        $clientes = Database::todos(
            "SELECT c.id, c.nome, c.municipio, c.nivel_tecnologico,
                    c.volume_compra_anual, c.potencial_venda,
                    (SELECT MAX(v.data_visita) FROM visitas v WHERE v.cliente_id = c.id) AS ultima_visita
               FROM clientes c
              WHERE c.ativo = 1 AND {$filtroCarteira}",
            $params
        );
        if (!$clientes) {
            return [];
        }

        $maxVolume = max(array_map(fn ($c) => (float) $c['volume_compra_anual'], $clientes)) ?: 1;
        $maxPotencial = max(array_map(fn ($c) => (float) $c['potencial_venda'], $clientes)) ?: 1;

        foreach ($clientes as &$c) {
            $dias = $c['ultima_visita']
                ? (int) floor((time() - strtotime($c['ultima_visita'])) / 86400)
                : self::TETO_DIAS;
            $fatorTempo = min(1.0, $dias / self::TETO_DIAS);

            $fatorNivel = match ($c['nivel_tecnologico']) {
                'Alto' => 1.0,
                'Médio' => 0.6,
                default => 0.3,
            };

            $fatorVolume = (float) $c['volume_compra_anual'] / $maxVolume;
            $fatorPotencial = (float) $c['potencial_venda'] / $maxPotencial;

            $queda = ComercialService::quedaCompra((int) $c['id']);
            $fatorChurn = $queda['risco_churn'] ? 1.0 : min(1.0, $queda['queda'] / ComercialService::LIMIAR_CHURN * 0.5);

            $score =
                self::PESO_TEMPO * $fatorTempo +
                self::PESO_NIVEL * $fatorNivel +
                self::PESO_VOLUME * $fatorVolume +
                self::PESO_POTENCIAL * $fatorPotencial +
                self::PESO_CHURN * $fatorChurn;

            $c['dias_sem_visita'] = $dias;
            $c['risco_churn'] = $queda['risco_churn'];
            $c['queda_percentual'] = round($queda['queda'] * 100);
            $c['score'] = round($score * 100);
        }
        unset($c);

        usort($clientes, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $limite > 0 ? array_slice($clientes, 0, $limite) : $clientes;
    }
}
