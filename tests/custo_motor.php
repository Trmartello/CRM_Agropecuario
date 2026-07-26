<?php
/**
 * Golden tests do motor de custo (spec docs/specs/custo-lavoura.md §4).
 * Escritos ANTES do motor. Tolerância 0,01.
 *   php tests/custo_motor.php
 * Também grava tests/custo_motor_php.json (dump p/ o cross-check com o JS).
 */
require __DIR__ . '/../app/Services/CustoMotorService.php';

use App\Services\CustoMotorService as M;

$falhas = 0;
$TOL = 0.01;

function quase($got, $esp, float $tol, string $nome, &$falhas): void
{
    if ($esp === null) {
        $ok = $got === null;
    } elseif ($got === null) {
        $ok = false;
    } else {
        $ok = abs(((float) $got) - ((float) $esp)) <= $tol;
    }
    printf("  [%s] %-26s got=%s esp=%s\n", $ok ? 'OK ' : 'XXX', $nome,
        $got === null ? 'null' : rtrim(rtrim(number_format((float) $got, 4, '.', ''), '0'), '.'),
        $esp === null ? 'null' : $esp);
    if (!$ok) {
        $falhas++;
    }
}
function eq($got, $esp, string $nome, &$falhas): void
{
    $ok = $got === $esp;
    printf("  [%s] %-26s got=%s esp=%s\n", $ok ? 'OK ' : 'XXX', $nome, var_export($got, true), var_export($esp, true));
    if (!$ok) {
        $falhas++;
    }
}

// ---------- Caso A — soja, base CT ----------
echo "=== Caso A — soja, base CT (A=64 P=60 M=132 Pt=131 t=0.40 C=7360) ===\n";
$a = M::calcular(64, 60, 132, 7360, 0.40, 131);
// NOTA: a spec §4 imprimia custo_total=470.240 (e os derivados 3.589,62 / 0,9348 /
// 0,4279), o que viola a própria fórmula §3 custo_total = C×A = 7360×64 = 471.040.
// Corrigido (confirmado pelo dono do produto): valores derivados da fórmula literal.
quase($a['custo_total'],              471040.00, $TOL, 'custo_total', $falhas);
quase($a['producao_total'],           3840,      $TOL, 'producao_total', $falhas);
quase($a['preco_equilibrio'],         122.67,    $TOL, 'preco_equilibrio', $falhas);
quase($a['produtividade_equilibrio'], 55.76,     $TOL, 'produtividade_equilibrio', $falhas);
quase($a['sacas_equilibrio'],         3595.73,   $TOL, 'sacas_equilibrio', $falhas);
quase($a['pct_equilibrio'],           0.9364,    $TOL, 'pct_equilibrio', $falhas);
quase($a['sacas_travadas'],           1536,      $TOL, 'sacas_travadas', $falhas);
quase($a['receita_travada'],          201216.00, $TOL, 'receita_travada', $falhas);
quase($a['cobertura_custo'],          0.4272,    $TOL, 'cobertura_custo', $falhas);

// ---------- Caso B — milho, base CT ----------
echo "=== Caso B — milho, base CT (A=64 P=160 M=62 C=8550) ===\n";
$b = M::calcular(64, 160, 62, 8550);
quase($b['preco_equilibrio'],         53.44,  $TOL, 'preco_equilibrio', $falhas);
quase($b['produtividade_equilibrio'], 137.90, $TOL, 'produtividade_equilibrio', $falhas);

// ---------- Caso C — bordas ----------
echo "=== Caso C — bordas ===\n";
$c1 = M::calcular(64, 0, 132, 7360, 0.40, 131);   // P=0
quase($c1['preco_equilibrio'], null, $TOL, 'P=0 -> preco_equilibrio null', $falhas);
$c2 = M::calcular(64, 60, 132, 7360, 0.40, 0);    // Pt=0
quase($c2['sacas_equilibrio'], null, $TOL, 'Pt=0 -> sacas_equilibrio null', $falhas);
$c3 = M::calcular(64, 60, 132, 7360, 0.0, 131);   // t=0
quase($c3['cobertura_custo'], 0, $TOL, 't=0 -> cobertura_custo 0', $falhas);
$c4 = M::calcular(64, 60, 132, 7360, 1.0, 131);   // t=1.0
eq($c4['alerta_entrega'], true, 't=1.0 -> alerta_entrega', $falhas);
$c5 = M::calcular(64, 60, 132, 7360, 0.40, 100);  // Pt=100 < preco_eq 122.67
eq($c5['veredito'], 'abaixo_equilibrio', 'Pt<eq -> abaixo_equilibrio', $falhas);

// ---------- Caso D — matriz ----------
echo "=== Caso D — matriz (prod -30%, t=0.40, base CT do Caso A) ===\n";
$mz = M::matriz(64, 60, 132, 7360, 0.40, 131);
// linha do preço de referência (delta 0), coluna produtividade -30% (delta -0.30)
$cel = null;
foreach ($mz as $linha) {
    foreach ($linha['celulas'] as $x) {
        if (abs($x['delta_preco']) < 1e-9 && abs($x['delta_produtividade'] - (-0.30)) < 1e-9) {
            $cel = $x;
        }
    }
}
quase($cel['producao_cenario'], 2688, $TOL, 'producao_cenario (42*64)', $falhas);
quase($cel['sacas_entregues'],  1536, $TOL, 'sacas_entregues=min(1536,2688)', $falhas);
eq($cel['sacas_entregues'] <= $cel['producao_cenario'], true, 'nunca entrega > colheita', $falhas);

// dump p/ cross-check com o JS
$dump = [
    'A' => M::calcular(64, 60, 132, 7360, 0.40, 131),
    'B' => M::calcular(64, 160, 62, 8550),
    'C_P0' => M::calcular(64, 0, 132, 7360, 0.40, 131),
    'C_Pt0' => M::calcular(64, 60, 132, 7360, 0.40, 0),
    'matrizA' => M::matriz(64, 60, 132, 7360, 0.40, 131),
    'versao' => M::VERSAO,
];
file_put_contents(__DIR__ . '/custo_motor_php.json', json_encode($dump));

echo "\n" . ($falhas === 0 ? ">>> GOLDEN PHP: TODOS OS CASOS PASSARAM <<<" : ">>> GOLDEN PHP: {$falhas} FALHA(S) <<<") . "\n";
exit($falhas === 0 ? 0 : 1);
