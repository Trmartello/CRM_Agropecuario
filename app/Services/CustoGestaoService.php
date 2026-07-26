<?php

namespace App\Services;

use App\Core\Database;

/**
 * Custo individual do cooperado — visão da GESTÃO (nf-ingestao §8/§9/§10.2).
 *
 * Decisão de governança da Diretoria (registrada em docs/INVARIANTES.md, Inv.5):
 * SOMENTE Diretoria (Administrador), Controladoria (Analista) e Gestor
 * Comercial consultam o custo identificável — o gate é
 * Permissoes::PODE_CUSTO_INDIVIDUAL (NUNCA GESTORES: Gestor Técnico é campo e
 * não vê). TODA leitura é auditada no controller com produtor consultado e
 * MOTIVO. A ciência do produtor é obtida no opt-in do Portal.
 *
 * Leituras pela conexão PRIVILEGIADA (Database::conexaoCusto — §10.2 "usuário
 * de banco próprio"): a credencial comercial não enxerga estas tabelas.
 */
class CustoGestaoService
{
    private static function cTodos(string $sql, array $p = []): array
    {
        $stmt = Database::conexaoCusto()->prepare($sql);
        $stmt->execute($p);
        return $stmt->fetchAll();
    }

    /** Produtores que têm lavoura no módulo de custo (para o seletor da tela). */
    public static function produtoresComCusto(): array
    {
        return self::cTodos(
            'SELECT DISTINCT c.id, c.nome, c.municipio
               FROM lavoura_safra ls JOIN clientes c ON c.id = ls.produtor_id
              ORDER BY c.nome'
        );
    }

    /** Safras existentes no módulo (filtro). */
    public static function safras(): array
    {
        return array_column(self::cTodos(
            'SELECT DISTINCT safra FROM lavoura_safra ORDER BY safra DESC'
        ), 'safra');
    }

    /**
     * Visão completa do custo de UM produtor: lavouras (com filtro de safra),
     * itens preenchidos com fonte, somas acumuladas por base, equilíbrio pelo
     * motor e o último cenário salvo.
     */
    public static function visao(int $produtorId, string $safra = ''): array
    {
        $where = 'ls.produtor_id = ?';
        $params = [$produtorId];
        if ($safra !== '') {
            $where .= ' AND ls.safra = ?';
            $params[] = $safra;
        }
        $lavouras = self::cTodos(
            "SELECT ls.* FROM lavoura_safra ls WHERE {$where} ORDER BY ls.safra DESC, ls.cultura",
            $params
        );
        $saida = [];
        foreach ($lavouras as $l) {
            $itens = self::cTodos(
                'SELECT c.descricao, c.grupo, lc.valor_ha, lc.fonte
                   FROM lavoura_custo lc JOIN cat_item_custo c ON c.id = lc.cat_item_id
                  WHERE lc.lavoura_safra_id = ?
                  ORDER BY FIELD(c.grupo, "coe", "cot", "ct"), c.ordem',
                [(int) $l['id']]
            );
            $somas = ['coe' => 0.0, 'cot' => 0.0, 'ct' => 0.0];
            foreach ($itens as $i) {
                $v = (float) $i['valor_ha'];
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
            $base = in_array($l['base_custo_padrao'], ['coe', 'cot', 'ct'], true) ? $l['base_custo_padrao'] : 'ct';
            $cenario = self::cTodos(
                'SELECT nome, versao_motor, dt_criacao FROM lavoura_cenario
                  WHERE lavoura_safra_id = ? ORDER BY id DESC LIMIT 1',
                [(int) $l['id']]
            )[0] ?? null;
            $saida[] = [
                'lavoura' => [
                    'id' => (int) $l['id'],
                    'safra' => $l['safra'],
                    'cultura' => $l['cultura'],
                    'areaHa' => (float) $l['area_ha'],
                    'produtividade' => (float) $l['produtividade_esperada'],
                    'precoReferencia' => (float) $l['preco_referencia'],
                    'base' => $base,
                ],
                'itens' => $itens,
                'somas' => array_map(static fn ($v) => round($v, 2), $somas),
                'calculo' => CustoMotorService::calcular(
                    (float) $l['area_ha'],
                    (float) $l['produtividade_esperada'],
                    (float) $l['preco_referencia'],
                    $somas[$base]
                ),
                'ultimo_cenario' => $cenario,
            ];
        }
        return $saida;
    }
}
