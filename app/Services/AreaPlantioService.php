<?php

namespace App\Services;

use App\Core\Database;

/**
 * Totalização da área de plantio por cultura × finalidade (schema v40).
 *
 * Só leitura, funções puras sobre os dados já carregados: por IMÓVEL (CAR),
 * consolidado por PROPRIEDADE e por PRODUTOR. Regras em
 * docs/specs/propriedade-imoveis-plantio.md §5.
 *
 * Convenção de área: a medida pelo croqui (area_gps) vale mais que a digitada
 * (area_ha); área de plantio zerada cai na área total do imóvel.
 */
class AreaPlantioService
{
    /** Área "que vale": medida pelo croqui se houver, senão a cadastrada. */
    public static function areaValida(?array $linha, string $gps, string $ha): float
    {
        if (!$linha) {
            return 0.0;
        }
        $medida = isset($linha[$gps]) && $linha[$gps] !== null ? (float) $linha[$gps] : 0.0;
        return $medida > 0 ? $medida : (float) ($linha[$ha] ?? 0);
    }

    /**
     * Resumo de um imóvel. $talhoes precisa de cultura (nome), finalidade (nome),
     * area_gps, area_ha, contorno.
     *
     * @return array{area_total:float, area_plantio:float, plantio_origem:string,
     *   grupos:array<int,array{cultura:string,finalidade:?string,area:float,pct:float,talhoes:int,mapeados:int}>,
     *   soma:float, nao_mapeado:float, excedente:float, qtd_talhoes:int}
     */
    public static function resumoImovel(array $imovel, array $talhoes): array
    {
        $areaTotal = self::areaValida($imovel, 'area_gps', 'area_ha');
        $areaPlantio = self::areaValida($imovel, 'area_plantio_gps', 'area_plantio_ha');
        $origem = 'plantio';
        if ($areaPlantio <= 0) {
            $areaPlantio = $areaTotal; // sem área de plantio informada: assume o imóvel inteiro
            $origem = 'total';
        }

        $grupos = [];
        $soma = 0.0;
        foreach ($talhoes as $t) {
            $area = self::areaValida($t, 'area_gps', 'area_ha');
            $cultura = trim((string) ($t['cultura'] ?? '')) ?: 'Sem cultura';
            $finalidade = trim((string) ($t['finalidade'] ?? '')) ?: null;
            $chave = $cultura . '|' . ($finalidade ?? '');
            $grupos[$chave] ??= [
                'cultura' => $cultura, 'finalidade' => $finalidade,
                'area' => 0.0, 'pct' => 0.0, 'talhoes' => 0, 'mapeados' => 0,
            ];
            $grupos[$chave]['area'] += $area;
            $grupos[$chave]['talhoes']++;
            if (!empty($t['contorno'])) {
                $grupos[$chave]['mapeados']++;
            }
            $soma += $area;
        }
        foreach ($grupos as &$g) {
            $g['area'] = round($g['area'], 2);
            $g['pct'] = $areaPlantio > 0 ? round($g['area'] / $areaPlantio * 100, 1) : 0.0;
        }
        unset($g);
        // Maior área primeiro — a barra lê de cima para baixo
        uasort($grupos, fn ($a, $b) => $b['area'] <=> $a['area']);

        return [
            'area_total' => round($areaTotal, 2),
            'area_plantio' => round($areaPlantio, 2),
            'plantio_origem' => $origem,
            'grupos' => array_values($grupos),
            'soma' => round($soma, 2),
            'nao_mapeado' => round(max(0.0, $areaPlantio - $soma), 2),
            'excedente' => round(max(0.0, $soma - $areaPlantio), 2),
            'qtd_talhoes' => count($talhoes),
        ];
    }

    /**
     * Consolida vários resumos (imóveis de uma propriedade, ou propriedades de
     * um produtor) somando áreas e reagrupando por cultura × finalidade.
     */
    public static function consolidar(array $resumos): array
    {
        $areaTotal = 0.0;
        $areaPlantio = 0.0;
        $soma = 0.0;
        $naoMapeado = 0.0;
        $excedente = 0.0;
        $qtd = 0;
        $grupos = [];
        foreach ($resumos as $r) {
            $areaTotal += $r['area_total'];
            $areaPlantio += $r['area_plantio'];
            $soma += $r['soma'];
            $naoMapeado += $r['nao_mapeado'];
            $excedente += $r['excedente'];
            $qtd += $r['qtd_talhoes'];
            foreach ($r['grupos'] as $g) {
                $chave = $g['cultura'] . '|' . ($g['finalidade'] ?? '');
                $grupos[$chave] ??= [
                    'cultura' => $g['cultura'], 'finalidade' => $g['finalidade'],
                    'area' => 0.0, 'pct' => 0.0, 'talhoes' => 0, 'mapeados' => 0,
                ];
                $grupos[$chave]['area'] += $g['area'];
                $grupos[$chave]['talhoes'] += $g['talhoes'];
                $grupos[$chave]['mapeados'] += $g['mapeados'];
            }
        }
        foreach ($grupos as &$g) {
            $g['area'] = round($g['area'], 2);
            $g['pct'] = $areaPlantio > 0 ? round($g['area'] / $areaPlantio * 100, 1) : 0.0;
        }
        unset($g);
        uasort($grupos, fn ($a, $b) => $b['area'] <=> $a['area']);

        return [
            'area_total' => round($areaTotal, 2),
            'area_plantio' => round($areaPlantio, 2),
            'plantio_origem' => 'consolidado',
            'grupos' => array_values($grupos),
            'soma' => round($soma, 2),
            'nao_mapeado' => round($naoMapeado, 2),
            'excedente' => round($excedente, 2),
            'qtd_talhoes' => $qtd,
        ];
    }

    /**
     * Carrega os imóveis de uma propriedade com os talhões de cada um (cultura e
     * finalidade já resolvidas) e o resumo por imóvel. Talhão ainda sem imóvel
     * (legado) aparece agrupado no primeiro imóvel, para não sumir da ficha.
     *
     * @return array<int,array> imóveis com chaves 'talhoes' e 'resumo'
     */
    public static function imoveisDaPropriedade(int $propriedadeId): array
    {
        $imoveis = Database::todos(
            'SELECT * FROM imoveis WHERE propriedade_id = ? ORDER BY ordem, id', [$propriedadeId]
        );
        $talhoes = Database::todos(
            'SELECT t.*, cu.nome AS cultura, f.nome AS finalidade
               FROM talhoes t
               LEFT JOIN culturas cu ON cu.id = t.cultura_id
               LEFT JOIN finalidades f ON f.id = t.finalidade_id
              WHERE t.propriedade_id = ? ORDER BY t.nome',
            [$propriedadeId]
        );
        $porImovel = [];
        $semImovel = [];
        foreach ($talhoes as $t) {
            if ($t['imovel_id'] !== null) {
                $porImovel[(int) $t['imovel_id']][] = $t;
            } else {
                $semImovel[] = $t;
            }
        }
        foreach ($imoveis as $i => &$im) {
            $im['talhoes'] = $porImovel[(int) $im['id']] ?? [];
            if ($i === 0 && $semImovel) {
                $im['talhoes'] = array_merge($im['talhoes'], $semImovel);
            }
            $im['resumo'] = self::resumoImovel($im, $im['talhoes']);
        }
        unset($im);
        return $imoveis;
    }
}
