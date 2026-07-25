<?php

/**
 * Gera app/Services/MunicipiosSul.php com os municípios da Região Sul (SC, RS, PR)
 * a partir da API pública do IBGE. Rode de um ambiente COM acesso à internet:
 *
 *   php tools/gerar_municipios_sul.php
 *
 * A API do IBGE é usada só aqui (build-time), não em produção: o resultado fica
 * embutido no PHP (const MAPA), sem dependência de rede em runtime.
 */

$ufs = [42 => 'SC', 43 => 'RS', 41 => 'PR'];
$mapa = [];
foreach ($ufs as $codUf => $sigla) {
    $url = "https://servicodados.ibge.gov.br/api/v1/localidades/estados/{$codUf}/municipios";
    fwrite(STDERR, "Baixando {$sigla}... ");
    $json = @file_get_contents($url);
    if ($json === false) {
        fwrite(STDERR, "FALHOU ({$url})\n");
        exit(1);
    }
    $lista = json_decode($json, true);
    if (!is_array($lista)) {
        fwrite(STDERR, "resposta inválida\n");
        exit(1);
    }
    foreach ($lista as $m) {
        $id = (string) ($m['id'] ?? '');
        $nome = trim((string) ($m['nome'] ?? ''));
        if ($id !== '' && $nome !== '') {
            $mapa[$id] = $nome;
        }
    }
    fwrite(STDERR, count($lista) . " municípios\n");
}
ksort($mapa);

$linhas = [];
foreach ($mapa as $id => $nome) {
    $linhas[] = "        '" . $id . "' => '" . str_replace("'", "\\'", $nome) . "',";
}
$bloco = implode("\n", $linhas);

$arquivo = __DIR__ . '/../app/Services/MunicipiosSul.php';
$conteudo = file_get_contents($arquivo);
$conteudo = preg_replace(
    '/(private const MAPA = \[\n).*?(\n    \];)/s',
    "\$1" . $bloco . "\$2",
    $conteudo
);
file_put_contents($arquivo, $conteudo);
fwrite(STDERR, 'OK — ' . count($mapa) . " municípios gravados em MunicipiosSul.php\n");
