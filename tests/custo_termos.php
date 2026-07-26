<?php
/**
 * Lint de comunicação do módulo de custo (spec custo-lavoura §8 / aceite §11.6):
 * a interface NÃO pode usar verbo imperativo de decisão comercial nem afirmar
 * direção de preço. Roda sobre os arquivos de UI do módulo.
 *   php tests/custo_termos.php
 *
 * Nota: "venda" como SUBSTANTIVO é permitido ("Simulação de venda antecipada",
 * texto do protótipo); o proibido é o imperativo ("venda já", "venda agora").
 */
$arquivos = [
    __DIR__ . '/../app/Views/partials/portal_custo.php',
    __DIR__ . '/../public/assets/js/custo-motor.js',
];

$proibidos = [
    '/\btrave\b/iu' => 'imperativo "trave"',
    '/\baproveite\b/iu' => 'imperativo "aproveite"',
    '/\baguarde\b/iu' => 'imperativo "aguarde"',
    '/\bgaranta\b/iu' => 'imperativo "garanta"',
    '/\bvenda (já|agora|hoje)\b/iu' => 'imperativo "venda já/agora/hoje"',
    '/\bcompre\b/iu' => 'imperativo "compre"',
    '/não perca/iu' => '"não perca"',
    '/última chance/iu' => '"última chance"',
    '/tendência de (alta|queda|baixa)/iu' => 'direção de preço',
    '/bom momento/iu' => '"bom momento" (direção de preço)',
    '/hora de (vender|comprar|travar)/iu' => '"hora de vender/comprar/travar"',
];

$falhas = 0;
foreach ($arquivos as $arq) {
    if (!is_file($arq)) {
        echo "[XXX] arquivo não encontrado: $arq\n";
        $falhas++;
        continue;
    }
    $conteudo = (string) file_get_contents($arq);
    $falhasArq = 0;
    foreach ($proibidos as $regex => $rotulo) {
        if (preg_match($regex, $conteudo, $m)) {
            echo '[XXX] ' . basename($arq) . ": termo proibido ({$rotulo}): \"{$m[0]}\"\n";
            $falhasArq++;
        }
    }
    if ($falhasArq === 0) {
        echo '[OK ] ' . basename($arq) . " sem termos proibidos\n";
    }
    $falhas += $falhasArq;
}

echo $falhas === 0 ? ">>> LINT DE TERMOS: PASSOU <<<\n" : ">>> LINT DE TERMOS: {$falhas} FALHA(S) <<<\n";
exit($falhas === 0 ? 0 : 1);
