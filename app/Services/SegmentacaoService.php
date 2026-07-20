<?php

namespace App\Services;

use App\Core\Database;

/**
 * Segmentação automática da carteira (RFV agro): classifica cada produtor
 * combinando volume de compras (curva ABC), recência da última compra,
 * aproveitamento do potencial e risco de churn.
 *
 * Segmentos (guardados em clientes.segmento como 1 letra):
 *   A — Parceiro     alto volume (curva A) e comprando ativamente
 *   B — Crescimento  potencial alto, aproveitamento baixo (espaço p/ crescer)
 *   C — Ocasional    compra esporádica / volume baixo
 *   D — Em risco     churn detectado ou sumiu há mais de 1 ano
 *   P — Prospect     nunca comprou (ou marcado prospecto no cadastro)
 *
 * O gestor pode fixar um segmento manual (clientes.segmento_manual) que
 * prevalece sobre o automático e nunca é sobrescrito pelo cálculo.
 * Cortes/pesos abaixo são chutes iniciais — calibrar no piloto de campo.
 */
class SegmentacaoService
{
    /** Janela de volume considerada (meses). */
    public const MESES_VOLUME = 12;
    /** Comprou nos últimos N dias = comprador ativo (exigência do segmento A).
     *  270 e não 180: a compra agro é sazonal — entre o fechamento de uma safra
     *  e as compras da próxima é normal passar 6–9 meses sem pedido. */
    public const DIAS_ATIVO = 270;
    /** Sem comprar há mais de N dias = Em risco. */
    public const DIAS_RISCO = 365;
    /** Curva ABC: clientes que acumulam este % do volume formam a classe A. */
    public const CORTE_CURVA_A = 0.80;
    /** Aproveitamento (volume 12m ÷ potencial) abaixo disto = Crescimento. */
    public const APROVEITAMENTO_BAIXO = 0.50;
    /** Intervalo mínimo entre recálculos em lote (horas). */
    public const HORAS_RECALCULO = 12;

    public const ROTULOS = [
        'A' => 'A — Parceiro',
        'B' => 'B — Crescimento',
        'C' => 'C — Ocasional',
        'D' => 'D — Em risco',
        'P' => 'Prospect',
    ];

    /** Classe de cor Bootstrap do selo. */
    public const CORES = [
        'A' => 'success',
        'B' => 'primary',
        'C' => 'secondary',
        'D' => 'danger',
        'P' => 'warning',
    ];

    public const DESCRICOES = [
        'A' => 'Alto volume e comprando ativamente — manter relacionamento',
        'B' => 'Potencial alto e aproveitamento baixo — espaço para crescer',
        'C' => 'Compra esporádica ou volume baixo',
        'D' => 'Queda de compra ou inativo há mais de 1 ano — agir',
        'P' => 'Nunca comprou — prospectar',
    ];

    /** Segmento efetivo: o manual do gestor prevalece sobre o calculado. */
    public static function efetivo(?string $manual, ?string $auto): ?string
    {
        $manual = trim((string) $manual);
        return $manual !== '' ? $manual : (trim((string) $auto) ?: null);
    }

    /**
     * Calcula o segmento de cada cliente informado.
     * Retorna id => ['segmento','volume_12m','recencia_dias','aproveitamento'].
     * Consultas em lote (padrão quedaCompraLote): 3 GROUP BY + churn.
     */
    public static function segmentarLote(array $clienteIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $clienteIds)));
        if (!$ids) {
            return [];
        }
        $marcadores = implode(',', array_fill(0, count($ids), '?'));

        // Cadastro (prospecto declarado + potencial informado)
        $cadastro = [];
        foreach (Database::todos(
            "SELECT id, prospecto, potencial_venda FROM clientes WHERE id IN ({$marcadores})",
            $ids
        ) as $l) {
            $cadastro[(int) $l['id']] = $l;
        }

        // Volume dos últimos 12 meses + recência da última compra
        $compras = [];
        foreach (Database::todos(
            "SELECT cliente_id,
                    COALESCE(SUM(CASE WHEN data_compra >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
                                      THEN valor_total ELSE 0 END), 0) AS volume_12m,
                    MAX(data_compra) AS ultima_compra
               FROM compras
              WHERE cliente_id IN ({$marcadores})
              GROUP BY cliente_id",
            array_merge([self::MESES_VOLUME], $ids)
        ) as $l) {
            $compras[(int) $l['cliente_id']] = $l;
        }

        // Curva ABC pelo volume 12m: quem acumula os primeiros 80% é classe A
        $volumes = [];
        foreach ($ids as $id) {
            $volumes[$id] = (float) ($compras[$id]['volume_12m'] ?? 0);
        }
        arsort($volumes);
        $totalVolume = array_sum($volumes);
        $classeA = [];
        $acumulado = 0.0;
        foreach ($volumes as $id => $v) {
            if ($v <= 0 || $totalVolume <= 0) {
                break;
            }
            $classeA[$id] = true;
            $acumulado += $v;
            if ($acumulado / $totalVolume >= self::CORTE_CURVA_A) {
                break;
            }
        }

        $quedas = ComercialService::quedaCompraLote($ids);

        $resultado = [];
        foreach ($ids as $id) {
            $volume = $volumes[$id];
            $ultima = $compras[$id]['ultima_compra'] ?? null;
            $recencia = $ultima !== null
                ? (int) floor((time() - strtotime((string) $ultima)) / 86400)
                : null; // nunca comprou
            $potencial = (float) ($cadastro[$id]['potencial_venda'] ?? 0);
            $aproveitamento = $potencial > 0 ? $volume / $potencial : null;
            $churn = !empty($quedas[$id]['risco_churn']);

            if (!empty($cadastro[$id]['prospecto']) || $recencia === null) {
                $segmento = 'P';
            } elseif ($churn || $recencia > self::DIAS_RISCO) {
                $segmento = 'D';
            } elseif (isset($classeA[$id]) && $recencia <= self::DIAS_ATIVO) {
                $segmento = 'A';
            } elseif ($aproveitamento !== null && $aproveitamento < self::APROVEITAMENTO_BAIXO) {
                $segmento = 'B';
            } else {
                $segmento = 'C';
            }

            $resultado[$id] = [
                'segmento' => $segmento,
                'volume_12m' => $volume,
                'recencia_dias' => $recencia,
                'aproveitamento' => $aproveitamento,
            ];
        }
        return $resultado;
    }

    /**
     * Recalcula e grava o segmento de TODA a base ativa (cache em
     * clientes.segmento). Chamado no login; sai cedo se rodou há menos de
     * HORAS_RECALCULO horas ($forcar ignora o intervalo). Best-effort.
     */
    public static function atualizarTodos(bool $forcar = false): void
    {
        try {
            if (!$forcar) {
                $ultima = Database::valor(
                    "SELECT valor FROM configuracoes WHERE chave = 'segmentacao_atualizada_em'"
                );
                if ($ultima && strtotime((string) $ultima) > time() - self::HORAS_RECALCULO * 3600) {
                    return;
                }
            }
            $ids = array_map(
                fn ($l) => (int) $l['id'],
                Database::todos('SELECT id FROM clientes WHERE ativo = 1')
            );
            $segmentos = self::segmentarLote($ids);

            $porSegmento = [];
            foreach ($segmentos as $id => $info) {
                $porSegmento[$info['segmento']][] = $id;
            }
            foreach ($porSegmento as $segmento => $grupo) {
                $marcadores = implode(',', array_fill(0, count($grupo), '?'));
                Database::executar(
                    "UPDATE clientes SET segmento = ? WHERE id IN ({$marcadores})",
                    array_merge([$segmento], $grupo)
                );
            }
            Database::executar(
                "INSERT INTO configuracoes (chave, valor) VALUES ('segmentacao_atualizada_em', NOW())
                 ON DUPLICATE KEY UPDATE valor = NOW()"
            );
        } catch (\Throwable $e) { /* colunas ainda não migradas: ignora */ }
    }

    /** Distribuição da carteira por segmento efetivo (para o Gerencial). */
    public static function distribuicao(string $filtroCarteira, array $params): array
    {
        $linhas = Database::todos(
            "SELECT COALESCE(NULLIF(c.segmento_manual, ''), c.segmento) AS seg, COUNT(*) AS total
               FROM clientes c
              WHERE c.ativo = 1 AND {$filtroCarteira}
              GROUP BY seg",
            $params
        );
        $dist = [];
        foreach (array_keys(self::ROTULOS) as $s) {
            $dist[$s] = 0;
        }
        $semSegmento = 0;
        foreach ($linhas as $l) {
            $s = (string) $l['seg'];
            if (isset($dist[$s])) {
                $dist[$s] = (int) $l['total'];
            } else {
                $semSegmento += (int) $l['total'];
            }
        }
        return ['distribuicao' => $dist, 'sem_segmento' => $semSegmento];
    }
}
