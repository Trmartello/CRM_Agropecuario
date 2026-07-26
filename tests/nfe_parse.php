<?php
/**
 * Golden de parsing da NF-e (spec nf-ingestao — PR 2). Sem banco: só o parse.
 *   php tests/nfe_parse.php
 */
require __DIR__ . '/../app/helpers.php';
require __DIR__ . '/../app/Services/FiscalAdapterInterface.php';
require __DIR__ . '/../app/Services/NotaFiscalService.php';

use App\Services\NotaFiscalService as NF;

$falhas = 0;
function chk($got, $esp, string $nome): void
{
    global $falhas;
    $ok = $got === $esp;
    printf("  [%s] %-28s got=%s esp=%s\n", $ok ? 'OK ' : 'XXX', $nome, var_export($got, true), var_export($esp, true));
    if (!$ok) {
        $falhas++;
    }
}

echo "=== golden: tests/fixtures/nfe_exemplo.xml ===\n";
$nf = NF::parse((string) file_get_contents(__DIR__ . '/fixtures/nfe_exemplo.xml'));
chk($nf['chave'], '42260701234567000189550010000123451000123458', 'chave (44 dígitos)');
chk($nf['emit_cnpj'], '01234567000189', 'emit_cnpj');
chk($nf['emit_nome'], 'Agroinsumos Oeste Ltda', 'emit_nome');
chk($nf['serie'], '1', 'serie');
chk($nf['numero'], '12345', 'numero');
chk($nf['dt_emissao'] !== null && str_starts_with($nf['dt_emissao'], '2026-07-10'), true, 'dt_emissao 2026-07-10');
chk($nf['valor_total'], 36952.00, 'valor_total');
chk($nf['natureza_op'], 'VENDA DE INSUMOS AGRICOLAS', 'natureza_op');
chk($nf['dest_doc'], '12345678901', 'dest CPF');
chk(count($nf['itens']), 2, '2 itens');
chk($nf['itens'][0]['descricao'], 'FERTILIZANTE NPK 09-20-15 BIG BAG 1T', 'item1 descricao');
chk($nf['itens'][0]['ncm'], '31052000', 'item1 NCM');
chk($nf['itens'][0]['cfop'], '5102', 'item1 CFOP');
chk($nf['itens'][0]['quantidade'], 10.0, 'item1 quantidade');
chk($nf['itens'][0]['valor_unit'], 3250.0, 'item1 valor_unit');
chk($nf['itens'][0]['valor_total'], 32500.00, 'item1 valor_total');
chk($nf['itens'][1]['ncm'], '38089329', 'item2 NCM (defensivo)');
chk($nf['itens'][1]['valor_total'], 4452.00, 'item2 valor_total');

echo "=== rejeições ===\n";
foreach ([
    'nao-xml' => 'isto não é xml',
    'xml-sem-infNFe' => '<?xml version="1.0"?><outra><coisa/></outra>',
    'chave-curta' => '<?xml version="1.0"?><NFe xmlns="http://www.portalfiscal.inf.br/nfe"><infNFe Id="NFe123"><det nItem="1"><prod><xProd>X</xProd></prod></det></infNFe></NFe>',
] as $rot => $xml) {
    $pegou = false;
    try {
        NF::parse($xml);
    } catch (\RuntimeException $e) {
        $pegou = true;
    }
    chk($pegou, true, "recusa {$rot}");
}

echo "\n" . ($falhas === 0 ? '>>> GOLDEN NFE PARSE: TODOS PASSARAM <<<' : ">>> {$falhas} FALHA(S) <<<") . "\n";
exit($falhas === 0 ? 0 : 1);
