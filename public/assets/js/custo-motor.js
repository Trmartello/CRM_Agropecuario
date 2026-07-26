/**
 * Motor de custo da lavoura e ponto de equilíbrio — cópia espelhada do
 * app/Services/CustoMotorService.php (spec docs/specs/custo-lavoura.md §3).
 *
 * Existe para calcular em tempo real no slider do Portal, sem ida ao servidor.
 * As MESMAS fórmulas do PHP; os golden tests (tests/custo_motor.php e
 * tests/custo_motor.mjs) rodam os mesmos vetores nas duas implementações e
 * falham se divergirem. Qualquer mudança de fórmula tem de ser feita nos dois
 * arquivos, com bump de VERSAO e nova rodada de golden tests.
 *
 * Funções puras (sem DOM, sem fetch). Exposto como window.CustoMotor no
 * navegador e como module.exports no Node (para o teste).
 */
(function (root, factory) {
  var mod = factory();
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = mod;
  } else {
    root.CustoMotor = mod;
  }
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  var VERSAO = '1.0.0';
  var PRODUTIVIDADE_DELTAS = [-0.30, -0.15, 0.0, 0.10];
  var PRECO_DELTAS = [-0.20, -0.10, 0.0, 0.10, 0.20];
  var FRACAO_CONSERVADORA = 0.8;

  /** Divisão segura: null quando o denominador é zero (nunca Infinity/NaN). */
  function div(a, b) {
    return b === 0 ? null : a / b;
  }

  /** Cálculo central do ponto de equilíbrio e cobertura (§3). */
  function calcular(area, produtividade, precoRef, custoHa, pctTravado, precoTravado) {
    pctTravado = pctTravado || 0;
    precoTravado = precoTravado || 0;

    var producaoTotal = produtividade * area;
    var custoTotal = custoHa * area;

    var precoEquilibrio = div(custoHa, produtividade);          // C / P
    var produtividadeEquilibrio = div(custoHa, precoRef);       // C / M
    var sacasEquilibrio = div(custoTotal, precoTravado);        // custo_total / Pt
    var pctEquilibrio = sacasEquilibrio === null ? null : div(sacasEquilibrio, producaoTotal);

    var sacasTravadas = producaoTotal * pctTravado;
    var receitaTravada = sacasTravadas * precoTravado;
    var coberturaCusto = div(receitaTravada, custoTotal);       // 0 quando t=0
    var sacasLivres = producaoTotal * (1.0 - pctTravado);

    var veredito = 'indefinido';
    if (precoEquilibrio !== null) {
      if (precoTravado < precoEquilibrio) veredito = 'abaixo_equilibrio';
      else if (precoTravado === precoEquilibrio) veredito = 'no_equilibrio';
      else veredito = 'acima_equilibrio';
    }

    return {
      versao: VERSAO,
      producao_total: producaoTotal,
      custo_total: custoTotal,
      preco_equilibrio: precoEquilibrio,
      produtividade_equilibrio: produtividadeEquilibrio,
      sacas_equilibrio: sacasEquilibrio,
      pct_equilibrio: pctEquilibrio,
      sacas_travadas: sacasTravadas,
      receita_travada: receitaTravada,
      cobertura_custo: coberturaCusto,
      sacas_livres: sacasLivres,
      producao_conservadora: producaoTotal * FRACAO_CONSERVADORA,
      alerta_entrega: pctTravado > FRACAO_CONSERVADORA,
      veredito: veredito
    };
  }

  /** Matriz de cenários (§3): resultado da célula em R$/ha. */
  function matriz(area, produtividade, precoRef, custoHa, pctTravado, precoTravado) {
    var producaoEsperada = produtividade * area;
    var sacasTravadas = producaoEsperada * pctTravado;
    var custoTotal = custoHa * area;

    var linhas = [];
    for (var i = 0; i < PRECO_DELTAS.length; i++) {
      var fm = PRECO_DELTAS[i];
      var precoCenario = precoRef * (1.0 + fm);
      var celulas = [];
      for (var j = 0; j < PRODUTIVIDADE_DELTAS.length; j++) {
        var fp = PRODUTIVIDADE_DELTAS[j];
        var prodCenarioHa = produtividade * (1.0 + fp);
        var producaoCenario = prodCenarioHa * area;
        var sacasEntregues = Math.min(sacasTravadas, producaoCenario);
        var sacasLivres = Math.max(0.0, producaoCenario - sacasEntregues);
        var receita = sacasEntregues * precoTravado + sacasLivres * precoCenario;
        celulas.push({
          delta_produtividade: fp,
          delta_preco: fm,
          produtividade: prodCenarioHa,
          preco: precoCenario,
          producao_cenario: producaoCenario,
          sacas_entregues: sacasEntregues,
          resultado_ha: div(receita - custoTotal, area)
        });
      }
      linhas.push({ delta_preco: fm, preco: precoCenario, celulas: celulas });
    }
    return linhas;
  }

  return {
    VERSAO: VERSAO,
    PRODUTIVIDADE_DELTAS: PRODUTIVIDADE_DELTAS,
    PRECO_DELTAS: PRECO_DELTAS,
    FRACAO_CONSERVADORA: FRACAO_CONSERVADORA,
    calcular: calcular,
    matriz: matriz
  };
});
