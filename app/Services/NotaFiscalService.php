<?php

namespace App\Services;

use App\Core\Database;

/**
 * Ingestão de NF-e do produtor — spec docs/specs/nf-ingestao.md (PR 2).
 *
 * Camada ESTÁVEL sobre o FiscalAdapter (provedor plugável pela config
 * `fiscal_provedor`): captura por produtor autorizado, parse do XML (modelo 55),
 * dedup pela chave de acesso (44 dígitos, idempotente) e log operacional em
 * nfe_captura_log.
 *
 * FIREWALL (invariante 5 / spec §10): todas as tabelas de NF são lidas e
 * escritas SOMENTE pela conexão privilegiada (Database::conexaoCusto) — o que o
 * produtor compra fora da Copérdia nunca passa pela credencial comercial.
 * Nenhum endpoint do CRM interno pode chamar este service.
 */
class NotaFiscalService
{
    /** Janela típica da distribuição de DF-e na SEFAZ (§4). */
    public const JANELA_DIAS = 90;

    private const NS_NFE = 'http://www.portalfiscal.inf.br/nfe';

    /* ---------------- conexão de custo (firewall) ---------------- */

    private static function cExec(string $sql, array $p = []): \PDOStatement
    {
        $stmt = Database::conexaoCusto()->prepare($sql);
        $stmt->execute($p);
        return $stmt;
    }

    private static function cUm(string $sql, array $p = []): ?array
    {
        $l = self::cExec($sql, $p)->fetch();
        return $l === false ? null : $l;
    }

    /* ---------------- adaptador plugável ---------------- */

    /** Adaptador do provedor configurado (config `fiscal_provedor`, padrão local). */
    public static function adapter(): FiscalAdapterInterface
    {
        $provedor = strtolower((string) ConfigService::obter('fiscal_provedor', 'local'));
        // Provedores de SaaS reais entram aqui como novos cases + suas classes;
        // o resto do sistema não muda (spec §4).
        return match ($provedor) {
            default => new FiscalAdapterLocal(),
        };
    }

    /* ---------------- captura (job do PR4 chama isto) ---------------- */

    /**
     * Captura as NF-e do produtor via o provedor. Exige autorização ATIVA em
     * produtor_autorizacao_fiscal (§6); sem ela, loga `sem_autorizacao` e não
     * captura (aceite §11.2). Revogar interrompe os pulls seguintes (§11.7).
     *
     * @return array{status:string,documentos:int,novos:int}
     */
    public static function capturarPorProdutor(int $produtorId): array
    {
        $adapter = self::adapter();
        $aut = self::cUm(
            'SELECT * FROM produtor_autorizacao_fiscal WHERE produtor_id = ? AND provedor = ?',
            [$produtorId, $adapter->nome()]
        );
        if ($aut === null || $aut['status'] !== 'ativa') {
            self::registrarCaptura($produtorId, $adapter->nome(), 0, 0, 'sem_autorizacao',
                $aut === null ? 'produtor sem autorização cadastrada' : "autorização {$aut['status']}");
            return ['status' => 'sem_autorizacao', 'documentos' => 0, 'novos' => 0];
        }

        $cpf = (string) Database::valor('SELECT cpf_cnpj FROM clientes WHERE id = ?', [$produtorId]);
        $desde = date('Y-m-d', strtotime('-' . self::JANELA_DIAS . ' days'));
        try {
            $xmls = $adapter->puxarDocumentos($cpf, $desde);
        } catch (\Throwable $e) {
            self::registrarCaptura($produtorId, $adapter->nome(), 0, 0, 'erro', $e->getMessage());
            return ['status' => 'erro', 'documentos' => 0, 'novos' => 0];
        }

        $novos = 0;
        $erros = [];
        foreach ($xmls as $xml) {
            try {
                $r = self::importarXml($produtorId, $xml, 'dfe');
                if ($r['novo']) {
                    $novos++;
                }
            } catch (\RuntimeException $e) {
                $erros[] = $e->getMessage(); // um XML ruim não derruba o lote
            }
        }
        $msg = count($xmls) . ' documento(s) do provedor'
            . ($erros ? '; recusados: ' . mb_substr(implode(' | ', $erros), 0, 180) : '');
        self::registrarCaptura($produtorId, $adapter->nome(), count($xmls), $novos, 'ok', $msg);
        return ['status' => 'ok', 'documentos' => count($xmls), 'novos' => $novos];
    }

    /**
     * Importa UM XML de NF-e (nfeProc ou NFe) para o produtor. Usado pelo pull
     * (fonte='dfe') e pelo canal de upload do PR7 (fonte='upload') — mesmo
     * pipeline, mesmos itens (aceite §11.6). Idempotente pela chave (§11.1).
     *
     * @return array{chave:string,novo:bool,itens:int}
     * @throws \RuntimeException XML inválido ou de outro destinatário.
     */
    public static function importarXml(int $produtorId, string $xml, string $fonte = 'upload'): array
    {
        $nf = self::parse($xml);

        // POSSE: a NF precisa ser do produtor (destinatário). Compara CPF/CNPJ
        // por dígitos quando o cadastro tem o documento; sem documento no
        // cadastro, recusa — não dá para provar a titularidade.
        $cpfCliente = preg_replace('/\D/', '', (string) Database::valor(
            'SELECT cpf_cnpj FROM clientes WHERE id = ?', [$produtorId]
        ));
        if ($cpfCliente === '') {
            throw new \RuntimeException('Cadastro do produtor sem CPF/CNPJ — necessário para conferir a titularidade da nota.');
        }
        if ($nf['dest_doc'] === '' || $nf['dest_doc'] !== $cpfCliente) {
            throw new \RuntimeException('A nota não tem este produtor como destinatário.');
        }

        // DEDUP pela chave de acesso (44 dígitos) — reprocessar é idempotente
        $existente = self::cUm('SELECT id, produtor_id FROM nfe_documento WHERE chave_acesso = ?', [$nf['chave']]);
        if ($existente !== null) {
            if ((int) $existente['produtor_id'] !== $produtorId) {
                throw new \RuntimeException('Esta nota já está registrada para outro produtor.');
            }
            return ['chave' => $nf['chave'], 'novo' => false, 'itens' => 0];
        }

        $pdo = Database::conexaoCusto();
        $pdo->beginTransaction();
        try {
            self::cExec(
                'INSERT INTO nfe_documento
                    (chave_acesso, produtor_id, emit_cnpj, emit_nome, serie, numero,
                     dt_emissao, valor_total, natureza_op, fonte, situacao, xml)
                 VALUES (?,?,?,?,?,?,?,?,?,?,"capturada",?)',
                [$nf['chave'], $produtorId, $nf['emit_cnpj'], $nf['emit_nome'], $nf['serie'],
                    $nf['numero'], $nf['dt_emissao'], $nf['valor_total'], $nf['natureza_op'],
                    in_array($fonte, ['dfe', 'upload', 'ocr'], true) ? $fonte : 'upload', $xml]
            );
            $nfeId = (int) $pdo->lastInsertId();
            $ins = $pdo->prepare(
                'INSERT INTO nfe_item (nfe_id, n_item, descricao, ncm, cfop, unidade,
                                       quantidade, valor_unit, valor_total)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            );
            foreach ($nf['itens'] as $i) {
                $ins->execute([$nfeId, $i['n_item'], $i['descricao'], $i['ncm'], $i['cfop'],
                    $i['unidade'], $i['quantidade'], $i['valor_unit'], $i['valor_total']]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return ['chave' => $nf['chave'], 'novo' => true, 'itens' => count($nf['itens'])];
    }

    /* ---------------- parse do XML (modelo 55) ---------------- */

    /**
     * Extrai os campos da NF-e (aceita nfeProc ou NFe na raiz). Golden de
     * parsing em tests/nfe_parse.php com tests/fixtures/nfe_exemplo.xml.
     *
     * @throws \RuntimeException XML ilegível ou sem os campos mínimos.
     */
    public static function parse(string $xml): array
    {
        $antes = libxml_use_internal_errors(true);
        $sx = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($antes);
        if ($sx === false) {
            throw new \RuntimeException('XML ilegível (não é um arquivo de NF-e válido).');
        }
        $sx->registerXPathNamespace('n', self::NS_NFE);
        $inf = $sx->xpath('//n:NFe/n:infNFe');
        if (!$inf) {
            $inf = $sx->xpath('//infNFe'); // XML sem namespace (raro, tolerado)
        }
        if (!$inf) {
            throw new \RuntimeException('XML sem o bloco infNFe — não é uma NF-e (modelo 55).');
        }
        $inf = $inf[0];
        $chave = preg_replace('/\D/', '', (string) ($inf['Id'] ?? ''));
        if (strlen($chave) !== 44) {
            throw new \RuntimeException('Chave de acesso ausente/inválida na NF-e.');
        }

        $s = static fn ($node, $max) => $node !== null ? mb_substr(trim((string) $node), 0, $max) : null;
        $dhEmi = (string) ($inf->ide->dhEmi ?? $inf->ide->dEmi ?? '');
        $dtEmissao = $dhEmi !== '' ? date('Y-m-d H:i:s', strtotime($dhEmi)) : null;
        $destDoc = preg_replace('/\D/', '', (string) ($inf->dest->CPF ?? $inf->dest->CNPJ ?? ''));

        $itens = [];
        foreach ($inf->det as $det) {
            $p = $det->prod;
            $itens[] = [
                'n_item' => (int) ($det['nItem'] ?? (count($itens) + 1)),
                'descricao' => $s($p->xProd, 200) ?? '(sem descrição)',
                'ncm' => preg_replace('/\D/', '', (string) ($p->NCM ?? '')) ?: null,
                'cfop' => preg_replace('/\D/', '', (string) ($p->CFOP ?? '')) ?: null,
                'unidade' => $s($p->uCom, 10),
                'quantidade' => $p->qCom !== null ? round((float) $p->qCom, 4) : null,
                'valor_unit' => $p->vUnCom !== null ? round((float) $p->vUnCom, 6) : null,
                'valor_total' => $p->vProd !== null ? round((float) $p->vProd, 2) : null,
            ];
        }
        if (!$itens) {
            throw new \RuntimeException('NF-e sem itens (bloco det ausente).');
        }

        return [
            'chave' => $chave,
            'emit_cnpj' => preg_replace('/\D/', '', (string) ($inf->emit->CNPJ ?? '')) ?: null,
            'emit_nome' => $s($inf->emit->xNome, 160),
            'serie' => $s($inf->ide->serie, 6),
            'numero' => $s($inf->ide->nNF, 20),
            'dt_emissao' => $dtEmissao,
            'valor_total' => $inf->total->ICMSTot->vNF !== null ? round((float) $inf->total->ICMSTot->vNF, 2) : null,
            'natureza_op' => $s($inf->ide->natOp, 120),
            'dest_doc' => $destDoc,
            'itens' => $itens,
        ];
    }

    /* ---------------- log operacional ---------------- */

    private static function registrarCaptura(int $produtorId, string $provedor, int $docs, int $novos, string $status, string $msg): void
    {
        try {
            self::cExec(
                'INSERT INTO nfe_captura_log (produtor_id, provedor, documentos, novos, status, mensagem)
                 VALUES (?,?,?,?,?,?)',
                [$produtorId, mb_substr($provedor, 0, 30), $docs, $novos,
                    in_array($status, ['ok', 'erro', 'sem_autorizacao'], true) ? $status : 'erro',
                    mb_substr($msg, 0, 255) ?: null]
            );
        } catch (\Throwable $e) {
            error_log('[CRM][nfe] falha ao logar captura: ' . $e->getMessage());
        }
    }
}
