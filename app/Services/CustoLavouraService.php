<?php

namespace App\Services;

use App\Core\Database;

/**
 * Lavouras e custo do produtor — spec docs/specs/custo-lavoura.md §9 (PR 4).
 *
 * REGRAS INEGOCIÁVEIS (§0/§7 + invariante 5):
 * - Este service é usado SOMENTE pelas rotas do Portal (perfil Produtor).
 *   Nenhum endpoint do CRM interno pode referenciar lavoura_custo/lavoura_cenario.
 * - Toda a persistência passa por Database::conexaoCusto(): em produção o
 *   usuário comercial do banco NÃO tem acesso às tabelas sob firewall, então a
 *   conexão comercial quebraria aqui — e é assim que deve ser.
 * - Todo método valida a POSSE: a lavoura/talhão/imóvel tem de pertencer ao
 *   produtor autenticado (clienteId resolvido no banco pelo controller, nunca
 *   vindo do POST).
 * - O servidor recalcula tudo (motor CustoMotorService); nunca confia em número
 *   do navegador.
 */
class CustoLavouraService
{
    private const BASES = ['coe', 'cot', 'ct'];
    private const MAX_VALOR_HA = 9999999.99; // DECIMAL(12,2)

    /* ---------- acesso ao banco: SEMPRE pela conexão de custo ---------- */

    private static function cExec(string $sql, array $p = []): \PDOStatement
    {
        $stmt = Database::conexaoCusto()->prepare($sql);
        $stmt->execute($p);
        return $stmt;
    }

    private static function cTodos(string $sql, array $p = []): array
    {
        return self::cExec($sql, $p)->fetchAll();
    }

    private static function cUm(string $sql, array $p = []): ?array
    {
        $l = self::cExec($sql, $p)->fetch();
        return $l === false ? null : $l;
    }

    private static function cValor(string $sql, array $p = []): mixed
    {
        return self::cExec($sql, $p)->fetchColumn();
    }

    /* ---------------------------- leitura ---------------------------- */

    /** Lavouras do produtor (filtro opcional por safra), com custo CT somado. */
    public static function listar(int $clienteId, string $safra = ''): array
    {
        $where = 'ls.produtor_id = ?';
        $params = [$clienteId];
        if ($safra !== '') {
            $where .= ' AND ls.safra = ?';
            $params[] = $safra;
        }
        return array_map(static fn ($l) => self::formatarLavoura($l), self::cTodos(
            "SELECT ls.*, COALESCE(SUM(lc.valor_ha), 0) AS custo_ct_ha,
                    COUNT(lc.id) AS itens_preenchidos
               FROM lavoura_safra ls
               LEFT JOIN lavoura_custo lc ON lc.lavoura_safra_id = ls.id
              WHERE {$where}
              GROUP BY ls.id
              ORDER BY ls.safra DESC, ls.cultura, ls.id",
            $params
        ));
    }

    /** Catálogo de itens ativos (ordem do agrupamento COE→COT→CT). */
    public static function catalogo(): array
    {
        return self::cTodos(
            "SELECT id, codigo, descricao, grupo, ordem
               FROM cat_item_custo WHERE ativo = 1
              ORDER BY FIELD(grupo,'coe','cot','ct'), ordem, id"
        );
    }

    /**
     * Ficha completa da lavoura: cadastro + itens de custo (catálogo inteiro,
     * com valor/fonte quando preenchido) + somas por base + último cenário +
     * cálculo do motor na base padrão. null se não for do produtor.
     */
    public static function detalhe(int $clienteId, int $id): ?array
    {
        $l = self::lavouraDoProdutor($clienteId, $id);
        if ($l === null) {
            return null;
        }

        $itens = self::cTodos(
            "SELECT c.id AS cat_item_id, c.codigo, c.descricao, c.grupo, c.ordem,
                    lc.valor_ha, lc.fonte
               FROM cat_item_custo c
               LEFT JOIN lavoura_custo lc
                      ON lc.cat_item_id = c.id AND lc.lavoura_safra_id = ?
              WHERE c.ativo = 1
              ORDER BY FIELD(c.grupo,'coe','cot','ct'), c.ordem, c.id",
            [$id]
        );

        $somas = ['coe' => 0.0, 'cot' => 0.0, 'ct' => 0.0];
        foreach ($itens as $i) {
            $v = $i['valor_ha'] !== null ? (float) $i['valor_ha'] : 0.0;
            // acumulativo: COE entra nas 3 bases, COT em cot+ct, CT só em ct
            if ($i['grupo'] === 'coe') {
                $somas['coe'] += $v;
                $somas['cot'] += $v;
                $somas['ct'] += $v;
            } elseif ($i['grupo'] === 'cot') {
                $somas['cot'] += $v;
                $somas['ct'] += $v;
            } else {
                $somas['ct'] += $v;
            }
        }

        $cenario = self::cUm(
            'SELECT id, nome, pct_travado, preco_travado, base_custo, versao_motor,
                    resultado_json, dt_criacao
               FROM lavoura_cenario WHERE lavoura_safra_id = ?
              ORDER BY id DESC LIMIT 1',
            [$id]
        );
        if ($cenario !== null) {
            $cenario['resultado_json'] = json_decode((string) $cenario['resultado_json'], true);
        }

        $base = in_array($l['base_custo_padrao'], self::BASES, true) ? $l['base_custo_padrao'] : 'ct';
        $calculo = CustoMotorService::calcular(
            (float) $l['area_ha'],
            (float) $l['produtividade_esperada'],
            (float) $l['preco_referencia'],
            $somas[$base]
        );

        return [
            'lavoura' => self::formatarLavoura($l),
            'itens' => array_map(static fn ($i) => [
                'catItemId' => (int) $i['cat_item_id'],
                'codigo' => $i['codigo'],
                'descricao' => $i['descricao'],
                'grupo' => $i['grupo'],
                'valorHa' => $i['valor_ha'] !== null ? (float) $i['valor_ha'] : null,
                'fonte' => $i['fonte'],
            ], $itens),
            'somas' => ['coe' => round($somas['coe'], 2), 'cot' => round($somas['cot'], 2), 'ct' => round($somas['ct'], 2)],
            'calculo' => $calculo,
            'ultimo_cenario' => $cenario,
        ];
    }

    /* ---------------------------- escrita ---------------------------- */

    /**
     * Cria a lavoura do produtor e aplica o preset da cultura (fonte='preset')
     * como ponto de partida. Retorna o id criado.
     *
     * @throws \RuntimeException com mensagem legível quando a validação falha.
     */
    public static function criar(int $clienteId, array $d): int
    {
        $cultura = trim((string) ($d['cultura'] ?? ''));
        $safra = trim((string) ($d['safra'] ?? ''));
        $area = (float) ($d['area_ha'] ?? 0);
        $prod = (float) ($d['produtividade_esperada'] ?? 0);
        $preco = (float) ($d['preco_referencia'] ?? 0);
        $base = strtolower(trim((string) ($d['base_custo_padrao'] ?? 'ct')));
        $codCar = trim((string) ($d['cod_car'] ?? ''));
        $talhaoId = (int) ($d['talhao_id'] ?? 0);

        if ($cultura === '' || mb_strlen($cultura) > 40) {
            throw new \RuntimeException('Informe a cultura (até 40 caracteres).');
        }
        if (!preg_match('#^\d{4}/\d{2}$#', $safra)) {
            throw new \RuntimeException('Informe a safra no formato 2025/26.');
        }
        if ($area <= 0 || $area > 99999) {
            throw new \RuntimeException('Informe a área em hectares (maior que zero).');
        }
        if ($prod <= 0 || $prod > 99999) {
            throw new \RuntimeException('Informe a produtividade esperada em sc/ha (maior que zero).');
        }
        if ($preco <= 0 || $preco > self::MAX_VALOR_HA) {
            throw new \RuntimeException('Informe o preço de referência em R$/sc (maior que zero).');
        }
        if (!in_array($base, self::BASES, true)) {
            $base = 'ct';
        }
        if ($codCar !== '') {
            // Posse do imóvel: precisa estar vinculado a ESTE produtor na bridge
            $vinculado = self::cValor(
                'SELECT COUNT(*) FROM bridge_imovel_produtor WHERE cod_car = ? AND produtor_id = ?',
                [$codCar, $clienteId]
            );
            if (!$vinculado) {
                throw new \RuntimeException('O imóvel (CAR) informado não está vinculado ao seu cadastro.');
            }
        }
        if ($talhaoId > 0) {
            // Posse do talhão: propriedade do próprio produtor
            $doProdutor = self::cValor(
                'SELECT COUNT(*) FROM talhoes t JOIN propriedades p ON p.id = t.propriedade_id
                  WHERE t.id = ? AND p.cliente_id = ?',
                [$talhaoId, $clienteId]
            );
            if (!$doProdutor) {
                throw new \RuntimeException('O talhão informado não pertence ao seu cadastro.');
            }
        }

        $pdo = Database::conexaoCusto();
        $pdo->beginTransaction();
        try {
            self::cExec(
                'INSERT INTO lavoura_safra
                    (produtor_id, cod_car, talhao_id, safra, cultura, area_ha,
                     produtividade_esperada, preco_referencia, base_custo_padrao)
                 VALUES (?,?,?,?,?,?,?,?,?)',
                [$clienteId, $codCar !== '' ? $codCar : null, $talhaoId > 0 ? $talhaoId : null,
                    $safra, mb_substr($cultura, 0, 40), round($area, 3), round($prod, 3),
                    round($preco, 2), $base]
            );
            $id = (int) $pdo->lastInsertId();
            // Preset da cultura como ponto de partida (casamento sem caixa;
            // cultura sem preset começa em branco — o produtor preenche)
            self::cExec(
                'INSERT INTO lavoura_custo (lavoura_safra_id, cat_item_id, valor_ha, fonte)
                 SELECT ?, p.cat_item_id, p.valor_ha, "preset"
                   FROM custo_preset p JOIN cat_item_custo c ON c.id = p.cat_item_id
                  WHERE LOWER(p.cultura) = LOWER(?) AND c.ativo = 1',
                [$id, $cultura]
            );
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return $id;
    }

    /**
     * Upsert dos itens de custo digitados pelo produtor (fonte='manual').
     *
     * @param array $itens lista de ['catItemId' => int, 'valorHa' => float]
     * @return int quantidade de itens gravados
     */
    public static function salvarCustos(int $clienteId, int $id, array $itens): int
    {
        if (self::lavouraDoProdutor($clienteId, $id) === null) {
            throw new \RuntimeException('Lavoura não encontrada.');
        }
        if (!$itens) {
            throw new \RuntimeException('Nenhum item de custo enviado.');
        }
        if (count($itens) > 200) {
            throw new \RuntimeException('Itens demais em uma só gravação.');
        }

        // Valida tudo ANTES de gravar (tudo-ou-nada)
        $validos = [];
        $catalogoIds = array_column(self::catalogo(), 'id');
        $catalogoIds = array_map('intval', $catalogoIds);
        foreach ($itens as $i) {
            $catId = (int) ($i['catItemId'] ?? $i['cat_item_id'] ?? 0);
            $valor = $i['valorHa'] ?? $i['valor_ha'] ?? null;
            if (!in_array($catId, $catalogoIds, true)) {
                throw new \RuntimeException('Item de custo inválido ou inativo (id ' . $catId . ').');
            }
            if (!is_numeric($valor) || (float) $valor < 0 || (float) $valor > self::MAX_VALOR_HA) {
                throw new \RuntimeException('Valor por hectare inválido para o item ' . $catId . '.');
            }
            $validos[$catId] = round((float) $valor, 2); // último vence em caso de duplicata
        }

        $pdo = Database::conexaoCusto();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO lavoura_custo (lavoura_safra_id, cat_item_id, valor_ha, fonte)
                 VALUES (?,?,?, "manual")
                 ON DUPLICATE KEY UPDATE valor_ha = VALUES(valor_ha), fonte = "manual"'
            );
            foreach ($validos as $catId => $valor) {
                $stmt->execute([$id, $catId, $valor]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return count($validos);
    }

    /* ---------------------------- internos ---------------------------- */

    /** Carrega a lavoura SOMENTE se pertencer ao produtor (posse verificada). */
    private static function lavouraDoProdutor(int $clienteId, int $id): ?array
    {
        if ($clienteId <= 0 || $id <= 0) {
            return null;
        }
        return self::cUm(
            'SELECT * FROM lavoura_safra WHERE id = ? AND produtor_id = ?',
            [$id, $clienteId]
        );
    }

    private static function formatarLavoura(array $l): array
    {
        return [
            'id' => (int) $l['id'],
            'codCar' => $l['cod_car'],
            'talhaoId' => $l['talhao_id'] !== null ? (int) $l['talhao_id'] : null,
            'safra' => $l['safra'],
            'cultura' => $l['cultura'],
            'areaHa' => (float) $l['area_ha'],
            'produtividadeEsperada' => (float) $l['produtividade_esperada'],
            'precoReferencia' => (float) $l['preco_referencia'],
            'baseCustoPadrao' => $l['base_custo_padrao'],
            'custoCtHa' => isset($l['custo_ct_ha']) ? (float) $l['custo_ct_ha'] : null,
            'itensPreenchidos' => isset($l['itens_preenchidos']) ? (int) $l['itens_preenchidos'] : null,
            'criadoEm' => $l['dt_criacao'] ?? null,
            'atualizadoEm' => $l['dt_atualizacao'] ?? null,
        ];
    }
}
