<?php

namespace App\Services;

use App\Core\Database;

/**
 * Job de agregação do custo regional — spec custo-lavoura §7 itens 3-4 (PR 10).
 *
 * Lê as tabelas SOB FIREWALL (lavoura_custo/lavoura_safra) pela conexão
 * PRIVILEGIADA (Database::conexaoCusto — "job roda com usuário próprio") e
 * publica em agg_custo_regional a MEDIANA do valor/ha por
 * safra × cultura × município × faixa de área × item de custo.
 *
 * K-ANONIMATO (§7.4, aceite §11.5): só publica a linha com >= K_MINIMO (5)
 * produtores DISTINTOS; abaixo disso a linha inteira é suprimida — sem exceção
 * "só para ver". Produtor com mais de uma lavoura no mesmo grupo conta UMA vez
 * (a média das lavouras dele entra na mediana) — um produtor grande não domina
 * o número nem multiplica sua presença.
 *
 * O agregado publicado NÃO é dado sob firewall: é exatamente o que a
 * Controladoria e os perfis de campo podem ver (invariante 5).
 */
class AgregacaoCustoService
{
    public const K_MINIMO = 5;

    /** Faixa de área do bucket (limites em ha, fechados à direita). */
    public static function faixaArea(float $ha): string
    {
        if ($ha <= 20) {
            return 'ate_20';
        }
        if ($ha <= 50) {
            return '20_50';
        }
        if ($ha <= 100) {
            return '50_100';
        }
        return 'acima_100';
    }

    /**
     * Recalcula o agregado (da safra informada, ou de todas). Re-executável:
     * apaga e regrava o escopo em transação.
     *
     * @return array{lavouras:int,produtores:int,publicadas:int,suprimidas:int}
     */
    public static function executar(?string $safra = null): array
    {
        $pdo = Database::conexaoCusto();
        $sql = "SELECT ls.produtor_id, ls.safra, ls.cultura, ls.area_ha,
                       COALESCE(NULLIF(c.municipio, ''), '—') AS municipio,
                       lc.cat_item_id, lc.valor_ha
                  FROM lavoura_safra ls
                  JOIN clientes c ON c.id = ls.produtor_id
                  JOIN lavoura_custo lc ON lc.lavoura_safra_id = ls.id"
            . ($safra !== null ? ' WHERE ls.safra = ?' : '');
        $stmt = $pdo->prepare($sql);
        $stmt->execute($safra !== null ? [$safra] : []);

        // bucket (safra|cultura|municipio|faixa|item) → produtor → [valores/ha]
        $buckets = [];
        $lavourasVistas = [];
        $produtoresVistos = [];
        while ($r = $stmt->fetch()) {
            $chave = $r['safra'] . '|' . $r['cultura'] . '|' . $r['municipio'] . '|'
                . self::faixaArea((float) $r['area_ha']) . '|' . $r['cat_item_id'];
            $buckets[$chave][(int) $r['produtor_id']][] = (float) $r['valor_ha'];
            $lavourasVistas[$r['produtor_id'] . '|' . $r['safra'] . '|' . $r['cultura'] . '|' . $r['area_ha']] = true;
            $produtoresVistos[(int) $r['produtor_id']] = true;
        }

        $publicadas = 0;
        $suprimidas = 0;
        $pdo->beginTransaction();
        try {
            $del = $pdo->prepare('DELETE FROM agg_custo_regional' . ($safra !== null ? ' WHERE safra = ?' : ''));
            $del->execute($safra !== null ? [$safra] : []);
            $ins = $pdo->prepare(
                'INSERT INTO agg_custo_regional
                    (safra, cultura, municipio, faixa_area, cat_item_id, valor_mediano, qtd_produtores)
                 VALUES (?,?,?,?,?,?,?)'
            );
            foreach ($buckets as $chave => $porProdutor) {
                $qtd = count($porProdutor); // produtores DISTINTOS
                if ($qtd < self::K_MINIMO) {
                    $suprimidas++;
                    continue; // §7.4: suprime a linha inteira
                }
                // média por produtor → mediana das médias
                $medias = array_map(
                    static fn (array $vals): float => array_sum($vals) / count($vals),
                    array_values($porProdutor)
                );
                [$s, $cult, $mun, $faixa, $item] = explode('|', $chave);
                $ins->execute([$s, $cult, $mun, $faixa, (int) $item,
                    round(self::mediana($medias), 2), $qtd]);
                $publicadas++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return [
            'lavouras' => count($lavourasVistas),
            'produtores' => count($produtoresVistos),
            'publicadas' => $publicadas,
            'suprimidas' => $suprimidas,
        ];
    }

    /** Mediana clássica (par = média dos dois centrais). */
    public static function mediana(array $valores): float
    {
        sort($valores);
        $n = count($valores);
        if ($n === 0) {
            return 0.0;
        }
        $meio = intdiv($n, 2);
        return $n % 2 === 1 ? $valores[$meio] : ($valores[$meio - 1] + $valores[$meio]) / 2;
    }
}
