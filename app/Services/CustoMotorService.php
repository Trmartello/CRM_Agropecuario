<?php

namespace App\Services;

/**
 * Motor de custo da lavoura e ponto de equilíbrio — spec docs/specs/custo-lavoura.md §3.
 *
 * Funções PURAS: sem DB, sem HTTP, sem I/O. É a EXCEÇÃO declarada à regra do
 * CLAUDE.md "o CRM não calcula indicador" — aqui o cálculo é sobre dado que o
 * PRÓPRIO produtor digitou (não é indicador da Copérdia, não entra no DRE, e
 * precisa responder em tempo real no slider do Portal).
 *
 * Espelhado em public/assets/js/custo-motor.js (mesmas fórmulas). Os golden
 * tests (tests/custo_motor.php e tests/custo_motor.mjs) rodam os mesmos vetores
 * nas duas implementações e falham se divergirem. Mudança de fórmula exige bump
 * de VERSAO e nova rodada de golden tests (cenários salvos gravam a versão).
 */
class CustoMotorService
{
    public const VERSAO = '1.0.0';

    /** Deltas da matriz de cenários (§3): produtividade nas colunas, preço nas linhas. */
    public const PRODUTIVIDADE_DELTAS = [-0.30, -0.15, 0.0, 0.10];
    public const PRECO_DELTAS = [-0.20, -0.10, 0.0, 0.10, 0.20];

    /** Fração conservadora da produção esperada (referência de segurança, §3). */
    public const FRACAO_CONSERVADORA = 0.8;

    /** Divisão segura: null quando o denominador é zero (nunca Infinity/NaN). */
    private static function div(float $a, float $b): ?float
    {
        return $b == 0.0 ? null : $a / $b;
    }

    /**
     * Cálculo central do ponto de equilíbrio e cobertura (§3). Todos os números
     * em precisão plena; o arredondamento de exibição é da interface.
     *
     * @param float $area          A — área (ha)
     * @param float $produtividade P — produtividade esperada (sc/ha)
     * @param float $precoRef      M — preço de referência (R$/sc)
     * @param float $custoHa       C — custo por hectare na base escolhida (R$/ha)
     * @param float $pctTravado    t — fração travada (0..1)
     * @param float $precoTravado  Pt — preço travado (R$/sc)
     */
    public static function calcular(
        float $area,
        float $produtividade,
        float $precoRef,
        float $custoHa,
        float $pctTravado = 0.0,
        float $precoTravado = 0.0
    ): array {
        $producaoTotal = $produtividade * $area;
        $custoTotal    = $custoHa * $area;

        $precoEquilibrio         = self::div($custoHa, $produtividade);   // C / P
        $produtividadeEquilibrio = self::div($custoHa, $precoRef);        // C / M
        $sacasEquilibrio         = self::div($custoTotal, $precoTravado); // custo_total / Pt
        $pctEquilibrio           = $sacasEquilibrio === null
            ? null
            : self::div($sacasEquilibrio, $producaoTotal);

        $sacasTravadas  = $producaoTotal * $pctTravado;
        $receitaTravada = $sacasTravadas * $precoTravado;
        $coberturaCusto = self::div($receitaTravada, $custoTotal);        // 0 quando t=0
        $sacasLivres    = $producaoTotal * (1.0 - $pctTravado);

        // Veredito da posição travada em relação ao ponto de equilíbrio (§4 Caso C).
        $veredito = 'indefinido';
        if ($precoEquilibrio !== null) {
            if ($precoTravado < $precoEquilibrio) {
                $veredito = 'abaixo_equilibrio';
            } elseif ($precoTravado == $precoEquilibrio) {
                $veredito = 'no_equilibrio';
            } else {
                $veredito = 'acima_equilibrio';
            }
        }

        return [
            'versao'                   => self::VERSAO,
            'producao_total'           => $producaoTotal,
            'custo_total'              => $custoTotal,
            'preco_equilibrio'         => $precoEquilibrio,
            'produtividade_equilibrio' => $produtividadeEquilibrio,
            'sacas_equilibrio'         => $sacasEquilibrio,
            'pct_equilibrio'           => $pctEquilibrio,
            'sacas_travadas'           => $sacasTravadas,
            'receita_travada'          => $receitaTravada,
            'cobertura_custo'          => $coberturaCusto,
            'sacas_livres'             => $sacasLivres,
            'producao_conservadora'    => $producaoTotal * self::FRACAO_CONSERVADORA,
            // Travar acima da produção conservadora (80%) gera ALERTA, não bloqueio (§3/§11.8).
            'alerta_entrega'           => $pctTravado > self::FRACAO_CONSERVADORA,
            'veredito'                 => $veredito,
        ];
    }

    /**
     * Matriz de cenários (§3): produtividade em −30%/−15%/esperada/+10% (colunas)
     * e preço em −20%/−10%/ref/+10%/+20% (linhas). O travamento é fixado ANTES da
     * colheita, então sacas_travadas usa a produção ESPERADA; em cada célula
     * sacas_entregues = min(sacas_travadas, producao_do_cenario) — o produtor
     * nunca entrega mais do que colheu. Resultado da célula em R$/ha.
     */
    public static function matriz(
        float $area,
        float $produtividade,
        float $precoRef,
        float $custoHa,
        float $pctTravado,
        float $precoTravado
    ): array {
        $producaoEsperada = $produtividade * $area;
        $sacasTravadas    = $producaoEsperada * $pctTravado;
        $custoTotal       = $custoHa * $area;

        $linhas = [];
        foreach (self::PRECO_DELTAS as $fm) {
            $precoCenario = $precoRef * (1.0 + $fm);
            $celulas = [];
            foreach (self::PRODUTIVIDADE_DELTAS as $fp) {
                $prodCenarioHa   = $produtividade * (1.0 + $fp);
                $producaoCenario = $prodCenarioHa * $area;
                $sacasEntregues  = min($sacasTravadas, $producaoCenario);
                $sacasLivres     = max(0.0, $producaoCenario - $sacasEntregues);
                $receita         = $sacasEntregues * $precoTravado + $sacasLivres * $precoCenario;
                $celulas[] = [
                    'delta_produtividade' => $fp,
                    'delta_preco'         => $fm,
                    'produtividade'       => $prodCenarioHa,
                    'preco'               => $precoCenario,
                    'producao_cenario'    => $producaoCenario,
                    'sacas_entregues'     => $sacasEntregues,
                    'resultado_ha'        => self::div($receita - $custoTotal, $area),
                ];
            }
            $linhas[] = ['delta_preco' => $fm, 'preco' => $precoCenario, 'celulas' => $celulas];
        }
        return $linhas;
    }
}
