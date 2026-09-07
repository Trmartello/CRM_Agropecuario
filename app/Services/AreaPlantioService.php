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
    /**
     * v46: tipos das ÁREAS DE NÃO PLANTIO (mata, APP, açude...). São "buracos" dentro
     * da divisa: descontados da área de plantio e dos talhões que os contêm.
     */
    public const TIPOS_NAO_PLANTIO = [
        'mata' => 'Mata / Reserva legal',
        'app' => 'APP (rio, nascente)',
        'acude' => 'Açude / Barragem',
        'sede' => 'Sede / Benfeitorias',
        'estrada' => 'Estrada / Carreador',
        'outro' => 'Outro',
    ];

    /** Nome amigável de um tipo de área de não plantio. */
    public static function rotuloTipo(?string $tipo): string
    {
        return self::TIPOS_NAO_PLANTIO[$tipo ?? ''] ?? self::TIPOS_NAO_PLANTIO['outro'];
    }

    /** Áreas de não plantio de um imóvel (v46), na ordem. */
    public static function exclusoesDoImovel(int $imovelId): array
    {
        return Database::todos(
            'SELECT id, imovel_id, nome, tipo, contorno, area_gps, ordem FROM areas_nao_plantio WHERE imovel_id = ? ORDER BY ordem, id',
            [$imovelId]
        );
    }

    /**
     * Quanto (ha) um polígono perde para as áreas de não plantio: soma das
     * interseções com cada exclusão ([[lat,lng],...][]).
     */
    public static function descontoNaoPlantio(array $pontos, array $exclusoes): float
    {
        $desc = 0.0;
        foreach ($exclusoes as $ex) {
            if (count($ex) >= 3) {
                $desc += CroquiService::areaIntersecaoHa($pontos, $ex);
            }
        }
        return $desc;
    }

    /** Polígonos ([[lat,lng],...][]) das linhas de areas_nao_plantio. */
    public static function poligonosDe(array $linhas): array
    {
        $out = [];
        foreach ($linhas as $l) {
            $p = json_decode((string) ($l['contorno'] ?? ''), true);
            if (is_array($p) && count($p) >= 3) {
                $out[] = $p;
            }
        }
        return $out;
    }

    /**
     * Área LÍQUIDA de um talhão: a medida pelo desenho menos as áreas de não plantio
     * dentro dele; talhão sem desenho → a cadastrada.
     */
    public static function areaLiquidaTalhao(array $t, array $exclusoes): float
    {
        $bruta = self::areaValida($t, 'area_gps', 'area_ha');
        if (empty($t['contorno']) || !$exclusoes) {
            return $bruta;
        }
        $pts = json_decode((string) $t['contorno'], true);
        if (!is_array($pts) || count($pts) < 3) {
            return $bruta;
        }
        return max(0.0, round($bruta - self::descontoNaoPlantio($pts, $exclusoes), 2));
    }

    /**
     * Grava em talhoes.area_ha a área LÍQUIDA (medida − não plantio) de cada talhão
     * desenhado do imóvel — assim custo, produtividade (sc/ha), relatório e snapshot
     * leem o mesmo número. Chamar sempre que um talhão ou uma área de não plantio mudar.
     */
    public static function sincronizarLiquidas(int $imovelId): void
    {
        $exclusoes = self::poligonosDe(self::exclusoesDoImovel($imovelId));
        $talhoes = Database::todos(
            'SELECT id, contorno, area_gps, area_ha FROM talhoes WHERE imovel_id = ? AND contorno IS NOT NULL AND area_gps IS NOT NULL AND area_gps > 0',
            [$imovelId]
        );
        foreach ($talhoes as $t) {
            $liquida = self::areaLiquidaTalhao($t, $exclusoes);
            if (abs($liquida - (float) $t['area_ha']) >= 0.005) {
                Database::executar('UPDATE talhoes SET area_ha = ? WHERE id = ?', [$liquida, (int) $t['id']]);
            }
        }
    }

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
        // v44: VÁRIAS áreas de plantio desenhadas (areas_plantio) — soma; sem nenhuma,
        // cai no legado (área de plantio única/digitada) e, por fim, no imóvel inteiro
        $areas = $imovel['areas_plantio'] ?? null;
        if ($areas === null) {
            $areas = isset($imovel['id']) ? self::areasDoImovel((int) $imovel['id']) : [];
        }
        // v46: áreas de NÃO plantio (mata, açude...) — descontadas de cada área de plantio
        // e de cada talhão que as contém; somadas à parte por tipo
        $exclusoesLinhas = $imovel['areas_nao_plantio'] ?? null;
        if ($exclusoesLinhas === null) {
            $exclusoesLinhas = isset($imovel['id']) ? self::exclusoesDoImovel((int) $imovel['id']) : [];
        }
        $exclusoes = self::poligonosDe($exclusoesLinhas);
        $naoPlantio = 0.0;
        $naoPlantioTipos = [];
        $exclusoesResumo = [];
        foreach ($exclusoesLinhas as $x) {
            $ha = round((float) ($x['area_gps'] ?? 0), 2);
            $naoPlantio += $ha;
            $tipo = (string) ($x['tipo'] ?? 'outro');
            $naoPlantioTipos[$tipo] = round(($naoPlantioTipos[$tipo] ?? 0.0) + $ha, 2);
            $exclusoesResumo[] = ['id' => (int) ($x['id'] ?? 0), 'nome' => (string) ($x['nome'] ?? ''), 'tipo' => $tipo,
                'tipo_rotulo' => self::rotuloTipo($tipo), 'area_gps' => $ha];
        }
        $areaPlantio = 0.0;
        $areasResumo = [];
        foreach ($areas as $a) {
            $bruta = round((float) ($a['area_gps'] ?? 0), 2);
            $pol = json_decode((string) ($a['contorno'] ?? ''), true);
            $desconto = is_array($pol) && count($pol) >= 3 && $exclusoes ? round(self::descontoNaoPlantio($pol, $exclusoes), 2) : 0.0;
            $liquida = max(0.0, round($bruta - $desconto, 2));
            $areaPlantio += $liquida;
            $areasResumo[] = ['id' => (int) ($a['id'] ?? 0), 'nome' => (string) ($a['nome'] ?? ''),
                'area_gps' => $bruta, 'desconto' => $desconto, 'area_liquida' => $liquida];
        }
        if ($areaPlantio <= 0 && !$areas) {
            $areaPlantio = self::areaValida($imovel, 'area_plantio_gps', 'area_plantio_ha');
        }
        $origem = 'plantio';
        if ($areaPlantio <= 0) {
            $areaPlantio = max(0.0, $areaTotal - $naoPlantio); // sem área de plantio informada: o imóvel inteiro menos o não plantio
            $origem = 'total';
        }

        $grupos = [];
        $soma = 0.0;
        foreach ($talhoes as $t) {
            $area = self::areaLiquidaTalhao($t, $exclusoes);
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
            'areas' => $areasResumo,
            'nao_plantio' => round($naoPlantio, 2),
            'nao_plantio_tipos' => $naoPlantioTipos,
            'exclusoes' => $exclusoesResumo,
            'grupos' => array_values($grupos),
            'soma' => round($soma, 2),
            'nao_mapeado' => round(max(0.0, $areaPlantio - $soma), 2),
            'excedente' => round(max(0.0, $soma - $areaPlantio), 2),
            'qtd_talhoes' => count($talhoes),
        ];
    }

    /** Áreas de plantio desenhadas de um imóvel (v44), na ordem. */
    public static function areasDoImovel(int $imovelId): array
    {
        return Database::todos(
            'SELECT id, imovel_id, nome, contorno, area_gps, ordem FROM areas_plantio WHERE imovel_id = ? ORDER BY ordem, id',
            [$imovelId]
        );
    }

    /**
     * v45: a área de plantio que HOSPEDA um desenho de talhão — a que contém mais
     * vértices (tolerância curta); empate → $preferidaId (o vínculo já gravado),
     * senão a primeira. Null se nenhuma área contém vértice algum. $areas = linhas
     * de areas_plantio (id, nome, contorno).
     */
    public static function areaHospedeira(array $pontos, array $areas, ?int $preferidaId = null): ?array
    {
        $melhor = null;
        $melhorN = 0;
        foreach ($areas as $a) {
            $pol = json_decode((string) ($a['contorno'] ?? ''), true) ?: [];
            if (count($pol) < 3) {
                continue;
            }
            $fora = CroquiService::pontosFora($pontos, $pol, CroquiService::TOLERANCIA_SOBREPOSICAO_M);
            $dentro = count($pontos) - count($fora);
            if ($dentro > $melhorN || ($dentro === $melhorN && $dentro > 0 && $preferidaId !== null && (int) $a['id'] === $preferidaId)) {
                $melhor = $a;
                $melhorN = $dentro;
            }
        }
        return $melhorN > 0 ? $melhor : null;
    }

    /**
     * Pontos de $pontos que ficam FORA de TODAS as áreas de plantio ([[lat,lng],...][]):
     * um talhão está "dentro da área de plantio" quando cada vértice cai em alguma
     * das áreas. Sem área nenhuma, nada fica fora (a área de plantio é o imóvel inteiro).
     */
    public static function pontosForaDasAreas(array $pontos, array $areas): array
    {
        $poligonos = array_values(array_filter($areas, fn ($a) => count($a) >= 3));
        if (!$poligonos) {
            return [];
        }
        $fora = [];
        foreach ($pontos as $i => $p) {
            $dentroDeAlguma = false;
            foreach ($poligonos as $pol) {
                // tolerância curta (3 m, a das sobreposições): 15 m "engoliria" faixas estreitas entre áreas
                if (!CroquiService::pontosFora([$p], $pol, CroquiService::TOLERANCIA_SOBREPOSICAO_M)) {
                    $dentroDeAlguma = true;
                    break;
                }
            }
            if (!$dentroDeAlguma) {
                $fora[] = $i;
            }
        }
        return $fora;
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
        $naoPlantio = 0.0;
        $naoPlantioTipos = [];
        foreach ($resumos as $r) {
            $areaTotal += $r['area_total'];
            $areaPlantio += $r['area_plantio'];
            $soma += $r['soma'];
            $naoMapeado += $r['nao_mapeado'];
            $excedente += $r['excedente'];
            $qtd += $r['qtd_talhoes'];
            $naoPlantio += (float) ($r['nao_plantio'] ?? 0);
            foreach ($r['nao_plantio_tipos'] ?? [] as $tipo => $ha) {
                $naoPlantioTipos[$tipo] = round(($naoPlantioTipos[$tipo] ?? 0.0) + (float) $ha, 2);
            }
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
            'nao_plantio' => round($naoPlantio, 2),
            'nao_plantio_tipos' => $naoPlantioTipos,
            'grupos' => array_values($grupos),
            'soma' => round($soma, 2),
            'nao_mapeado' => round($naoMapeado, 2),
            'excedente' => round($excedente, 2),
            'qtd_talhoes' => $qtd,
        ];
    }

    /**
     * REGRA (teste de campo): a área total da propriedade NÃO é digitada — é a SOMA
     * das áreas dos seus imóveis (CARs), cada um pela divisa medida (area_gps) ou,
     * na falta, pela cadastrada. Grava em propriedades.area_ha para que ficha,
     * cartão do produtor, relatório e snapshot offline leiam o mesmo número.
     * Só sobrescreve quando a soma é > 0 (propriedade antiga sem CAR mantém o valor
     * que já tinha). Devolve a soma. Chamar sempre que a área de um imóvel mudar.
     */
    public static function sincronizarPropriedade(int $propriedadeId): float
    {
        $soma = (float) Database::valor(
            'SELECT COALESCE(SUM(CASE WHEN area_gps IS NOT NULL AND area_gps > 0 THEN area_gps ELSE area_ha END), 0)
               FROM imoveis WHERE propriedade_id = ?',
            [$propriedadeId]
        );
        $soma = round($soma, 2);
        if ($soma > 0) {
            Database::executar('UPDATE propriedades SET area_ha = ? WHERE id = ?', [$soma, $propriedadeId]);
        }
        return $soma;
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
        // v44: áreas de plantio de todos os imóveis da propriedade numa consulta
        $areasPorImovel = [];
        $exclusoesPorImovel = [];
        if ($imoveis) {
            $ids = array_map(fn ($im) => (int) $im['id'], $imoveis);
            $rows = Database::todos(
                'SELECT id, imovel_id, nome, contorno, area_gps, ordem FROM areas_plantio
                  WHERE imovel_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY ordem, id',
                $ids
            );
            foreach ($rows as $a) {
                $areasPorImovel[(int) $a['imovel_id']][] = $a;
            }
            // v46: áreas de não plantio de todos os imóveis numa consulta
            $rows = Database::todos(
                'SELECT id, imovel_id, nome, tipo, contorno, area_gps, ordem FROM areas_nao_plantio
                  WHERE imovel_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY ordem, id',
                $ids
            );
            foreach ($rows as $x) {
                $exclusoesPorImovel[(int) $x['imovel_id']][] = $x;
            }
        }
        foreach ($imoveis as $i => &$im) {
            $im['talhoes'] = $porImovel[(int) $im['id']] ?? [];
            if ($i === 0 && $semImovel) {
                $im['talhoes'] = array_merge($im['talhoes'], $semImovel);
            }
            $im['areas_plantio'] = $areasPorImovel[(int) $im['id']] ?? [];
            $im['areas_nao_plantio'] = $exclusoesPorImovel[(int) $im['id']] ?? [];
            $im['resumo'] = self::resumoImovel($im, $im['talhoes']);
        }
        unset($im);
        return $imoveis;
    }
}
