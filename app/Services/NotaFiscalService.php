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

    /* ---------------- autorização do produtor (§6 — PR 3) ---------------- */

    /** Autorização atual do produtor no provedor configurado (null = nunca autorizou). */
    public static function autorizacao(int $produtorId): ?array
    {
        $aut = self::cUm(
            'SELECT provedor, status, dt_autorizacao, dt_revogacao, dt_atualizacao
               FROM produtor_autorizacao_fiscal WHERE produtor_id = ? AND provedor = ?',
            [$produtorId, self::adapter()->nome()]
        );
        return $aut ?: null;
    }

    /**
     * Opt-in: registra a procuração no provedor e grava a autorização (§6).
     * Idempotente (já ativa = permanece); re-autorizar após revogação reativa.
     *
     * @throws \RuntimeException cadastro sem CPF/CNPJ (provedor exige o documento).
     */
    public static function autorizar(int $produtorId): array
    {
        $cpf = preg_replace('/\D/', '', (string) Database::valor(
            'SELECT cpf_cnpj FROM clientes WHERE id = ?', [$produtorId]
        ));
        if ($cpf === '') {
            throw new \RuntimeException(
                'Seu cadastro ainda não tem CPF/CNPJ — fale com seu consultor Copérdia para completar antes de autorizar.'
            );
        }
        $adapter = self::adapter();
        $r = $adapter->autorizar($produtorId, $cpf);
        $status = in_array($r['status'] ?? '', ['pendente', 'ativa', 'erro'], true) ? $r['status'] : 'pendente';
        self::cExec(
            'INSERT INTO produtor_autorizacao_fiscal
                (produtor_id, provedor, status, procuracao_ref, dt_autorizacao, dt_revogacao)
             VALUES (?,?,?,?, NOW(), NULL)
             ON DUPLICATE KEY UPDATE status = VALUES(status), procuracao_ref = VALUES(procuracao_ref),
                dt_autorizacao = NOW(), dt_revogacao = NULL',
            [$produtorId, $adapter->nome(), $status, mb_substr((string) ($r['procuracao_ref'] ?? ''), 0, 120) ?: null]
        );
        return self::autorizacao($produtorId);
    }

    /** Revogação (§6): encerra no provedor e PARA os pulls futuros na hora. */
    public static function revogar(int $produtorId): array
    {
        $aut = self::cUm(
            'SELECT procuracao_ref, status FROM produtor_autorizacao_fiscal WHERE produtor_id = ? AND provedor = ?',
            [$produtorId, self::adapter()->nome()]
        );
        if ($aut === null) {
            throw new \RuntimeException('Não há autorização para revogar.');
        }
        if ($aut['status'] !== 'revogada') {
            try {
                self::adapter()->revogar($produtorId, (string) ($aut['procuracao_ref'] ?? ''));
            } catch (\Throwable $e) {
                // A revogação LOCAL vale mesmo se o provedor falhar (o pull checa
                // o status local antes de qualquer chamada) — loga e segue.
                error_log('[CRM][nfe] falha ao revogar no provedor: ' . $e->getMessage());
            }
        }
        self::cExec(
            "UPDATE produtor_autorizacao_fiscal SET status = 'revogada', dt_revogacao = NOW()
              WHERE produtor_id = ? AND provedor = ?",
            [$produtorId, self::adapter()->nome()]
        );
        return self::autorizacao($produtorId);
    }

    /* ---------------- pull diário (PR 4 — gatilho oportunista) ---------------- */

    /** Intervalo mínimo entre pulls automáticos do mesmo produtor. */
    public const INTERVALO_PULL_H = 24;

    /**
     * "Job" diário sem cron (padrão do repo, como a poda de auditoria): o Portal
     * dispara isto em segundo plano ao abrir; roda no máximo 1x/24h por produtor
     * — a menos que $forcar (botão "Buscar minhas notas agora").
     *
     * Sem autorização ATIVA devolve null SILENCIOSAMENTE (não polui o log com
     * sem_autorizacao todo dia — esse status é para o job explícito).
     *
     * @return array{executado:bool,captura?:array,total_notas:int,ultima_busca:?string}|null
     */
    public static function pullSeNecessario(int $produtorId, bool $forcar = false): ?array
    {
        $aut = self::autorizacao($produtorId);
        if ($aut === null || $aut['status'] !== 'ativa') {
            return null;
        }
        if (!$forcar) {
            $ultima = self::cUm(
                "SELECT dt_exec FROM nfe_captura_log
                  WHERE produtor_id = ? AND provedor = ? AND status IN ('ok','erro')
                  ORDER BY id DESC LIMIT 1",
                [$produtorId, self::adapter()->nome()]
            );
            if ($ultima !== null && strtotime((string) $ultima['dt_exec']) > time() - self::INTERVALO_PULL_H * 3600) {
                return ['executado' => false] + self::resumoNotas($produtorId);
            }
        }
        $captura = self::capturarPorProdutor($produtorId);
        return ['executado' => true, 'captura' => $captura] + self::resumoNotas($produtorId);
    }

    /** Contagem de notas + última busca (linha de status do card do Portal). */
    public static function resumoNotas(int $produtorId): array
    {
        $total = (int) self::cExec(
            'SELECT COUNT(*) FROM nfe_documento WHERE produtor_id = ?', [$produtorId]
        )->fetchColumn();
        $ultima = self::cUm(
            "SELECT dt_exec FROM nfe_captura_log
              WHERE produtor_id = ? AND status IN ('ok','erro') ORDER BY id DESC LIMIT 1",
            [$produtorId]
        );
        return ['total_notas' => $total, 'ultima_busca' => $ultima['dt_exec'] ?? null];
    }

    /* ---------------- captura (o pull acima chama isto) ---------------- */

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

    /* ---------- mapeamento e revisão (§7 — PR 5) ---------- */

    /** Palavra-chave na descrição → código do item (fallback quando o NCM não casa). */
    private const PALAVRAS_ITEM = [
        'FERTIL' => 'FERTILIZANTES', 'ADUBO' => 'FERTILIZANTES',
        'HERBICIDA' => 'DEFENSIVOS', 'FUNGICIDA' => 'DEFENSIVOS',
        'INSETICIDA' => 'DEFENSIVOS', 'DEFENSIV' => 'DEFENSIVOS',
        'SEMENTE' => 'SEMENTES',
        'CALCARIO' => 'CORRETIVOS', 'CALCÁRIO' => 'CORRETIVOS', 'GESSO' => 'CORRETIVOS',
        'FRETE' => 'SECAGEM_FRETE', 'TRANSPORTE' => 'SECAGEM_FRETE', 'SECAGEM' => 'SECAGEM_FRETE',
        'SEGURO' => 'SEGURO', 'DIESEL' => 'OPERACOES',
    ];

    /**
     * Sugere o item de custo para os itens ainda sem mapeamento: maior prefixo
     * de NCM em map_ncm_item; na falta, palavra-chave da descrição. Mantém
     * status_map='sugerido' — NADA entra no custo sem confirmação (§7).
     */
    public static function sugerirMapeamento(int $nfeId): int
    {
        $mapa = Database::todos('SELECT ncm_prefix, cat_item_id FROM map_ncm_item ORDER BY LENGTH(ncm_prefix) DESC');
        $porCodigo = [];
        foreach (Database::todos('SELECT id, codigo FROM cat_item_custo WHERE ativo = 1') as $c) {
            $porCodigo[$c['codigo']] = (int) $c['id'];
        }
        $itens = self::cExec(
            "SELECT id, ncm, descricao FROM nfe_item
              WHERE nfe_id = ? AND cat_item_id IS NULL AND status_map = 'sugerido'",
            [$nfeId]
        )->fetchAll();
        $n = 0;
        foreach ($itens as $i) {
            $catId = null;
            $ncm = (string) ($i['ncm'] ?? '');
            foreach ($mapa as $m) { // ordenado do prefixo mais longo p/ o mais curto
                if ($ncm !== '' && str_starts_with($ncm, $m['ncm_prefix'])) {
                    $catId = (int) $m['cat_item_id'];
                    break;
                }
            }
            if ($catId === null) {
                $desc = mb_strtoupper((string) $i['descricao']);
                foreach (self::PALAVRAS_ITEM as $palavra => $codigo) {
                    if (str_contains($desc, $palavra) && isset($porCodigo[$codigo])) {
                        $catId = $porCodigo[$codigo];
                        break;
                    }
                }
            }
            if ($catId !== null) {
                self::cExec('UPDATE nfe_item SET cat_item_id = ? WHERE id = ?', [$catId, (int) $i['id']]);
                $n++;
            }
        }
        return $n;
    }

    /** Notas do produtor com contagens de itens (lista do card do Portal). */
    public static function notasDoProdutor(int $produtorId): array
    {
        return array_map(static fn ($n) => [
            'id' => (int) $n['id'],
            'chave' => $n['chave_acesso'],
            'emitente' => $n['emit_nome'],
            'numero' => $n['numero'],
            'dtEmissao' => $n['dt_emissao'],
            'valorTotal' => $n['valor_total'] !== null ? (float) $n['valor_total'] : null,
            'fonte' => $n['fonte'],
            'itens' => (int) $n['qtd_itens'],
            'pendentes' => (int) $n['qtd_pendentes'],
            'confirmados' => (int) $n['qtd_confirmados'],
        ], self::cExec(
            "SELECT d.id, d.chave_acesso, d.emit_nome, d.numero, d.dt_emissao, d.valor_total, d.fonte,
                    COUNT(i.id) AS qtd_itens,
                    SUM(i.status_map = 'sugerido') AS qtd_pendentes,
                    SUM(i.status_map = 'confirmado') AS qtd_confirmados
               FROM nfe_documento d LEFT JOIN nfe_item i ON i.nfe_id = d.id
              WHERE d.produtor_id = ?
              GROUP BY d.id
              ORDER BY d.dt_emissao DESC, d.id DESC",
            [$produtorId]
        )->fetchAll());
    }

    /** Nota + itens (com sugestões preenchidas) — posse verificada. */
    public static function notaDetalhe(int $produtorId, int $nfeId): ?array
    {
        $nota = self::cUm(
            'SELECT id, chave_acesso, emit_nome, emit_cnpj, numero, serie, dt_emissao,
                    valor_total, natureza_op, fonte
               FROM nfe_documento WHERE id = ? AND produtor_id = ?',
            [$nfeId, $produtorId]
        );
        if ($nota === null) {
            return null;
        }
        self::sugerirMapeamento($nfeId);
        $itens = self::cExec(
            'SELECT id, n_item, descricao, ncm, cfop, unidade, quantidade,
                    valor_unit, valor_total, cat_item_id, status_map
               FROM nfe_item WHERE nfe_id = ? ORDER BY n_item',
            [$nfeId]
        )->fetchAll();
        return [
            'nota' => [
                'id' => (int) $nota['id'],
                'chave' => $nota['chave_acesso'],
                'emitente' => $nota['emit_nome'],
                'numero' => $nota['numero'],
                'dtEmissao' => $nota['dt_emissao'],
                'valorTotal' => $nota['valor_total'] !== null ? (float) $nota['valor_total'] : null,
                'naturezaOp' => $nota['natureza_op'],
                'fonte' => $nota['fonte'],
            ],
            'itens' => array_map(static fn ($i) => [
                'id' => (int) $i['id'],
                'nItem' => (int) $i['n_item'],
                'descricao' => $i['descricao'],
                'ncm' => $i['ncm'],
                'unidade' => $i['unidade'],
                'quantidade' => $i['quantidade'] !== null ? (float) $i['quantidade'] : null,
                'valorTotal' => $i['valor_total'] !== null ? (float) $i['valor_total'] : null,
                'catItemId' => $i['cat_item_id'] !== null ? (int) $i['cat_item_id'] : null,
                'status' => $i['status_map'],
            ], $itens),
        ];
    }

    /**
     * Revisão do produtor (§7): confirma/ajusta/ignora o mapeamento de cada
     * item. Confirmado exige um item de custo válido.
     */
    public static function salvarMapeamento(int $produtorId, int $nfeId, array $itens): int
    {
        if (self::cUm('SELECT id FROM nfe_documento WHERE id = ? AND produtor_id = ?', [$nfeId, $produtorId]) === null) {
            throw new \RuntimeException('Nota não encontrada.');
        }
        $validos = array_map('intval', array_column(
            Database::todos('SELECT id FROM cat_item_custo WHERE ativo = 1'), 'id'
        ));
        $n = 0;
        foreach ($itens as $i) {
            $itemId = (int) ($i['id'] ?? 0);
            $status = (string) ($i['status'] ?? '');
            $catId = isset($i['catItemId']) && $i['catItemId'] !== null && $i['catItemId'] !== '' ? (int) $i['catItemId'] : null;
            if (!in_array($status, ['confirmado', 'ignorado', 'sugerido'], true)) {
                throw new \RuntimeException('Situação inválida para o item ' . $itemId . '.');
            }
            if ($status === 'confirmado' && ($catId === null || !in_array($catId, $validos, true))) {
                throw new \RuntimeException('Escolha o item de custo antes de confirmar (item ' . $itemId . ').');
            }
            $ok = self::cExec(
                'UPDATE nfe_item SET cat_item_id = ?, status_map = ? WHERE id = ? AND nfe_id = ?',
                [$catId, $status, $itemId, $nfeId]
            )->rowCount();
            if ($ok === 0 && self::cUm('SELECT id FROM nfe_item WHERE id = ? AND nfe_id = ?', [$itemId, $nfeId]) === null) {
                throw new \RuntimeException('Item ' . $itemId . ' não pertence a esta nota.');
            }
            $n++;
        }
        return $n;
    }

    /* ---------- aplicar no custo da lavoura (§7 — PR 6) ---------- */

    /**
     * Aplica itens CONFIRMADOS de NF no custo da lavoura: soma o valor total
     * por item de custo e converte para R$/ha pela área da lavoura — cálculo do
     * SERVIDOR, nunca do navegador (§7). Upsert em lavoura_custo com
     * fonte='dfe'/'upload' (a origem do documento). Idempotente: re-aplicar a
     * mesma seleção grava os mesmos valores.
     *
     * @param int[] $nfeItemIds itens (já confirmados pelo produtor) a aplicar
     * @return array{aplicados:int,por_item:array<int,array{catItemId:int,valorTotal:float,valorHa:float}>}
     */
    public static function aplicarEmLavoura(int $produtorId, int $lavouraSafraId, array $nfeItemIds): array
    {
        $lav = self::cUm(
            'SELECT id, area_ha FROM lavoura_safra WHERE id = ? AND produtor_id = ?',
            [$lavouraSafraId, $produtorId]
        );
        if ($lav === null) {
            throw new \RuntimeException('Lavoura não encontrada.');
        }
        $area = (float) $lav['area_ha'];
        if ($area <= 0) {
            throw new \RuntimeException('A lavoura está sem área — corrija antes de aplicar.');
        }
        $ids = array_values(array_unique(array_map('intval', $nfeItemIds)));
        if (!$ids || count($ids) > 500) {
            throw new \RuntimeException('Nenhum item de nota para aplicar.');
        }

        // Só itens CONFIRMADOS, com item de custo, de notas DESTE produtor (§7:
        // nada entra sem confirmação; posse verificada no dado, não no path)
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $itens = self::cExec(
            "SELECT i.id, i.cat_item_id, i.valor_total, d.fonte
               FROM nfe_item i JOIN nfe_documento d ON d.id = i.nfe_id
              WHERE i.id IN ($marks) AND d.produtor_id = ?
                AND i.status_map = 'confirmado' AND i.cat_item_id IS NOT NULL",
            array_merge($ids, [$produtorId])
        )->fetchAll();
        if (count($itens) !== count($ids)) {
            throw new \RuntimeException(
                'Só itens confirmados (e das suas notas) podem entrar no custo — revise a nota antes de aplicar.'
            );
        }

        // Soma por item de custo; fonte 'dfe' prevalece se qualquer item veio do pull
        $porCat = [];
        foreach ($itens as $i) {
            $cat = (int) $i['cat_item_id'];
            $porCat[$cat]['total'] = ($porCat[$cat]['total'] ?? 0) + (float) $i['valor_total'];
            $porCat[$cat]['fonte'] = (($porCat[$cat]['fonte'] ?? '') === 'dfe' || $i['fonte'] === 'dfe') ? 'dfe' : 'upload';
        }

        $pdo = Database::conexaoCusto();
        $pdo->beginTransaction();
        try {
            $up = $pdo->prepare(
                'INSERT INTO lavoura_custo (lavoura_safra_id, cat_item_id, valor_ha, fonte)
                 VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE valor_ha = VALUES(valor_ha), fonte = VALUES(fonte)'
            );
            $resumo = [];
            foreach ($porCat as $cat => $v) {
                $valorHa = round($v['total'] / $area, 2);
                $up->execute([$lavouraSafraId, $cat, $valorHa, $v['fonte']]);
                $resumo[] = ['catItemId' => $cat, 'valorTotal' => round($v['total'], 2), 'valorHa' => $valorHa];
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return ['aplicados' => count($itens), 'por_item' => $resumo];
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
