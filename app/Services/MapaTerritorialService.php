<?php

namespace App\Services;

use App\Core\Database;

/**
 * Mapa Territorial (spec docs/specs/mapa-territorial.md) — PR 2.
 *
 * Popula a tabela dim_imovel a partir da base do CAR já importada
 * (car_imoveis), por município. Reaproveita a geometria (JSON, já simplificada
 * a ≤120 pontos pelo ShapefileService) e a bbox do CAR — não há parser novo de
 * shapefile. Idempotente: upsert por cod_car (= cod_imovel do CAR).
 */
class MapaTerritorialService
{
    /** Página de leitura/commit (limita memória e evita undo log gigante). */
    private const LOTE = 2000;

    /**
     * Popula dim_imovel com os imóveis do CAR de um município.
     *
     * @return array{municipio:string,uf:string,lido:int,inseridos:int,atualizados:int}
     */
    public static function importarMunicipioDoCar(string $municipio, ?string $uf = null): array
    {
        $municipio = trim($municipio);
        if ($municipio === '') {
            throw new \RuntimeException('Informe o município.');
        }
        $uf = $uf !== null && trim($uf) !== '' ? mb_strtoupper(substr(trim($uf), 0, 2)) : null;

        // Resolve o município na base do CAR (sem acento, "contém") p/ pegar o cod_ibge.
        $alvo = self::semAcento($municipio);
        $cands = Database::todos(
            'SELECT DISTINCT municipio, uf, cod_ibge FROM car_imoveis' . ($uf ? ' WHERE uf = ?' : ''),
            $uf ? [$uf] : []
        );
        $ibges = [];
        $munOficial = $municipio;
        $ufOficial = $uf ?? 'SC';
        foreach ($cands as $c) {
            if (strpos(self::semAcento((string) $c['municipio']), $alvo) !== false) {
                if (!empty($c['cod_ibge'])) {
                    $ibges[(string) $c['cod_ibge']] = true;
                }
                $munOficial = (string) $c['municipio'];
                $ufOficial = (string) $c['uf'];
            }
        }

        // Filtro base: por cod_ibge (indexado) quando houver; senão por nome/UF exatos.
        if ($ibges) {
            $marks = implode(',', array_fill(0, count($ibges), '?'));
            $where = "cod_ibge IN ($marks)";
            $baseParams = array_keys($ibges);
        } else {
            $where = 'municipio = ? AND uf = ?';
            $baseParams = [$munOficial, $ufOficial];
        }

        $total = (int) Database::valor("SELECT COUNT(*) FROM car_imoveis WHERE $where", $baseParams);
        if ($total === 0) {
            throw new \RuntimeException(
                "A base do CAR de \"{$municipio}\" ainda não foi importada. "
                . 'Importe primeiro em "Base do CAR por município (SICAR)".'
            );
        }

        $pdo = Database::conexao();
        $sel = $pdo->prepare(
            "SELECT id, cod_imovel, cod_ibge, municipio, uf, contorno, area_ha,
                    min_lat, min_lng, max_lat, max_lng
               FROM car_imoveis
              WHERE $where AND id > ?
              ORDER BY id LIMIT " . self::LOTE
        );
        $ins = $pdo->prepare(
            'INSERT INTO dim_imovel
                (cod_car, nome_imovel, municipio, cod_ibge, uf, area_ha,
                 contorno, contorno_simpl, centro_lat, centro_lng,
                 min_lat, min_lng, max_lat, max_lng, fonte, dt_carga)
             VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "SICAR", NOW())
             ON DUPLICATE KEY UPDATE
                municipio = VALUES(municipio), cod_ibge = VALUES(cod_ibge), uf = VALUES(uf),
                area_ha = VALUES(area_ha), contorno = VALUES(contorno),
                contorno_simpl = VALUES(contorno_simpl),
                centro_lat = VALUES(centro_lat), centro_lng = VALUES(centro_lng),
                min_lat = VALUES(min_lat), min_lng = VALUES(min_lng),
                max_lat = VALUES(max_lat), max_lng = VALUES(max_lng),
                fonte = VALUES(fonte), dt_carga = VALUES(dt_carga)'
        );

        $lido = 0;
        $inseridos = 0;
        $atualizados = 0;
        $lastId = 0;
        $pdo->beginTransaction();
        try {
            while (true) {
                $sel->execute([...$baseParams, $lastId]);
                $linhas = $sel->fetchAll();
                if (!$linhas) {
                    break;
                }
                foreach ($linhas as $r) {
                    $lastId = (int) $r['id'];
                    $lido++;
                    $minLat = (float) $r['min_lat'];
                    $minLng = (float) $r['min_lng'];
                    $maxLat = (float) $r['max_lat'];
                    $maxLng = (float) $r['max_lng'];
                    $ins->execute([
                        mb_substr((string) $r['cod_imovel'], 0, 60),
                        (string) $r['municipio'],
                        $r['cod_ibge'] !== null ? (string) $r['cod_ibge'] : null,
                        (string) ($r['uf'] ?: $ufOficial),
                        round((float) $r['area_ha'], 4),
                        (string) $r['contorno'],
                        (string) $r['contorno'],   // contorno_simpl: o CAR já vem simplificado
                        ($minLat + $maxLat) / 2,    // centroide = ponto médio da bbox
                        ($minLng + $maxLng) / 2,
                        $minLat,
                        $minLng,
                        $maxLat,
                        $maxLng,
                    ]);
                    // MySQL: 1 = linha inserida, 2 = linha atualizada (0 = já igual)
                    if ($ins->rowCount() === 1) {
                        $inseridos++;
                    } else {
                        $atualizados++;
                    }
                }
                $pdo->commit();
                $pdo->beginTransaction();
                if (count($linhas) < self::LOTE) {
                    break;
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return [
            'municipio' => $munOficial,
            'uf' => $ufOficial,
            'lido' => $lido,
            'inseridos' => $inseridos,
            'atualizados' => $atualizados,
        ];
    }

    /** minúsculas sem acento — casa o município digitado com a base do CAR. */
    private static function semAcento(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        return strtr($s, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'ê' => 'e', 'è' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ò' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ]);
    }

    /* ===================== PR 3: GeoJSON dos imóveis ===================== */

    /** Preço de referência R$/ha por cultura para o POTENCIAL mockado (PR 3). */
    private const RHA_MOCK = [
        'Milho' => 5200, 'Soja' => 4600, 'Pastagem / Leite' => 1450,
        'Integração aves' => 2800, 'Integração suínos' => 2800, 'Trigo' => 3800,
    ];
    private const RHA_MOCK_PADRAO = 3500;
    private const MAX_FEATURES = 5000;

    /**
     * GeoJSON FeatureCollection dos imóveis do território (spec §8).
     *
     * PR 3: o score (potencial/realizado/share/gap) é MOCKADO de propósito — o
     * adaptador do Qlik entra no PR 9. Isso desacopla o front do Qlik. A
     * geometria sai em [lng,lat] (padrão GeoJSON); o contorno é guardado em
     * [lat,lng], então é convertido aqui.
     *
     * @param array $f {municipio?,uf?,safra?,rtv?,bbox?:[minLat,minLng,maxLat,maxLng]}
     */
    public static function geojson(array $f): array
    {
        $safra = trim((string) ($f['safra'] ?? ''));
        $rtv = trim((string) ($f['rtv'] ?? ''));

        $where = [];
        $params = [];

        $municipio = trim((string) ($f['municipio'] ?? ''));
        if ($municipio !== '') {
            [$w, $p] = self::filtroMunicipio($municipio, $f['uf'] ?? null);
            $where[] = $w;
            $params = array_merge($params, $p);
        }

        $bbox = $f['bbox'] ?? null;
        if (is_array($bbox) && count($bbox) === 4) {
            [$minLat, $minLng, $maxLat, $maxLng] = array_map('floatval', array_values($bbox));
            // overlap de bbox com piso em min_lat p/ manter o índice seletivo
            $where[] = 'min_lat >= ? AND min_lat <= ? AND max_lat >= ? AND min_lng <= ? AND max_lng >= ?';
            array_push($params, $minLat - 0.5, $maxLat, $minLat, $maxLng, $minLng);
        }

        if (!$where) {
            throw new \RuntimeException('Informe o município ou a área (bbox) do mapa.');
        }

        $sql = 'SELECT cod_car, nome_imovel, municipio, uf, area_ha,
                       COALESCE(NULLIF(contorno_simpl, ""), contorno) AS geo
                  FROM dim_imovel
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY cod_car LIMIT ' . (self::MAX_FEATURES + 1);
        $imoveis = Database::todos($sql, $params);
        $truncado = count($imoveis) > self::MAX_FEATURES;
        if ($truncado) {
            array_pop($imoveis);
        }
        if (!$imoveis) {
            return ['type' => 'FeatureCollection', 'features' => [], 'fonte_score' => 'mock', 'total' => 0, 'truncado' => false];
        }

        $cods = array_column($imoveis, 'cod_car');
        $prod = self::produtorPrincipalDe($cods);        // cod_car → {nome,id,rtv}
        $cult = self::culturaPrincipalDe($cods, $safra); // cod_car → cultura

        $features = [];
        foreach ($imoveis as $im) {
            $cod = (string) $im['cod_car'];
            $pinfo = $prod[$cod] ?? null;

            if ($rtv !== '' && (!$pinfo || self::semAcento((string) ($pinfo['rtv'] ?? '')) !== self::semAcento($rtv))) {
                continue; // filtro por RTV pedido e não bate
            }

            $areaHa = (float) $im['area_ha'];
            $cultura = $cult[$cod] ?? null;
            $sc = self::scoreMock($cod, $areaHa, $cultura, $pinfo !== null);
            $potencial = $sc['potencial'];
            $realizado = $sc['realizado'];
            $gap = $sc['gap'];
            $share = $sc['share'];
            $status = $sc['status'];

            $geom = self::geojsonGeometry($im['geo']);
            if ($geom === null) {
                continue;
            }
            $features[] = [
                'type' => 'Feature',
                'geometry' => $geom,
                'properties' => [
                    'codCar' => $cod,
                    'nomeImovel' => $im['nome_imovel'],
                    'municipio' => $im['municipio'],
                    'uf' => $im['uf'],
                    'areaHa' => round($areaHa, 2),
                    'produtorPrincipal' => $pinfo['nome'] ?? null,
                    'produtorId' => $pinfo['id'] ?? null,
                    'statusComercial' => $status,
                    'culturaPrincipal' => $cultura,
                    'potencial' => $potencial,
                    'realizado' => $realizado,
                    'share' => $share,
                    'gap' => $gap,
                    'rtv' => $pinfo['rtv'] ?? null,
                    'ultimaVisita' => null, // preenchido em PR futuro (visitas por produtor)
                ],
            ];
        }

        return [
            'type' => 'FeatureCollection',
            'features' => $features,
            'fonte_score' => 'mock', // PR 3: score sintético — Qlik entra no PR 9
            'total' => count($features),
            'truncado' => $truncado,
        ];
    }

    /** Score de demonstração (mock) determinístico por imóvel — Qlik substitui no PR 9. */
    private static function scoreMock(string $cod, float $areaHa, ?string $cultura, bool $temProd): array
    {
        $rate = $cultura !== null ? (self::RHA_MOCK[$cultura] ?? self::RHA_MOCK_PADRAO) : self::RHA_MOCK_PADRAO;
        $frac = (crc32($cod) % 1000) / 1000.0;
        $potencial = (int) round($areaHa * $rate);
        $realizado = $temProd ? (int) round($potencial * $frac) : 0; // prospect: Copérdia ainda não vendeu
        $gap = $potencial - $realizado;
        $share = $potencial > 0 ? round($realizado / $potencial, 4) : 0.0;
        $status = !$temProd ? 'prospect' : ($share >= 0.05 ? 'ativo' : 'inativo');
        return compact('potencial', 'realizado', 'gap', 'share', 'status');
    }

    /**
     * Ficha completa de um imóvel (spec §8): cadastro + vínculos + talhões + visitas.
     *
     * @return array|null  null se o imóvel não existir.
     */
    public static function ficha(string $codCar, string $safra = ''): ?array
    {
        $codCar = trim($codCar);
        $im = Database::um(
            'SELECT cod_car, nome_imovel, municipio, uf, area_ha, cod_ibge, modulos_fiscais, tipo_imovel, situacao_car
               FROM dim_imovel WHERE cod_car = ?',
            [$codCar]
        );
        if (!$im) {
            return null;
        }

        $produtores = Database::todos(
            'SELECT b.produtor_id, b.papel, b.principal, b.origem, b.confianca,
                    c.nome, c.telefone, c.municipio AS cli_municipio, u.nome AS rtv
               FROM bridge_imovel_produtor b
               JOIN clientes c ON c.id = b.produtor_id
               LEFT JOIN usuarios u ON u.id = c.responsavel_id
              WHERE b.cod_car = ?
              ORDER BY b.principal DESC, c.nome',
            [$codCar]
        );

        $talWhere = 'cod_car = ?';
        $talParams = [$codCar];
        if ($safra !== '') {
            $talWhere .= ' AND safra = ?';
            $talParams[] = $safra;
        }
        $talhoes = Database::todos(
            "SELECT safra, nome_talhao, cultura, area_plantada, produtividade
               FROM fato_talhao_safra WHERE $talWhere ORDER BY area_plantada DESC",
            $talParams
        );

        $visitas = [];
        $ids = array_column($produtores, 'produtor_id');
        if ($ids) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $visitas = Database::todos(
                "SELECT v.data_visita, v.objetivo, v.estagio_cultura, v.finalizada,
                        c.nome AS produtor, u.nome AS tecnico
                   FROM visitas v
                   JOIN clientes c ON c.id = v.cliente_id
                   LEFT JOIN usuarios u ON u.id = v.usuario_id
                  WHERE v.cliente_id IN ($marks)
                  ORDER BY v.data_visita DESC, v.id DESC
                  LIMIT 12",
                $ids
            );
        }

        $cultura = $talhoes[0]['cultura'] ?? null; // maior área na safra
        $sc = self::scoreMock($codCar, (float) $im['area_ha'], $cultura, count($produtores) > 0);
        $principal = $produtores[0] ?? null;

        return [
            'codCar' => $im['cod_car'],
            'nomeImovel' => $im['nome_imovel'],
            'municipio' => $im['municipio'],
            'uf' => $im['uf'],
            'areaHa' => round((float) $im['area_ha'], 2),
            'modulosFiscais' => $im['modulos_fiscais'] !== null ? (float) $im['modulos_fiscais'] : null,
            'tipoImovel' => $im['tipo_imovel'],
            'situacaoCar' => $im['situacao_car'],
            'culturaPrincipal' => $cultura,
            'statusComercial' => $sc['status'],
            'potencial' => $sc['potencial'],
            'realizado' => $sc['realizado'],
            'share' => $sc['share'],
            'gap' => $sc['gap'],
            'rtv' => $principal['rtv'] ?? null,
            'fonte_score' => 'mock',
            'produtores' => array_map(static fn ($p) => [
                'id' => (int) $p['produtor_id'],
                'nome' => $p['nome'],
                'telefone' => $p['telefone'],
                'municipio' => $p['cli_municipio'],
                'papel' => $p['papel'],
                'principal' => (int) $p['principal'] === 1,
                'origem' => $p['origem'],
                'confianca' => $p['confianca'],
                'rtv' => $p['rtv'],
            ], $produtores),
            'talhoes' => array_map(static fn ($t) => [
                'safra' => $t['safra'],
                'nomeTalhao' => $t['nome_talhao'],
                'cultura' => $t['cultura'],
                'areaPlantada' => (float) $t['area_plantada'],
                'produtividade' => $t['produtividade'] !== null ? (float) $t['produtividade'] : null,
            ], $talhoes),
            'visitas' => array_map(static fn ($v) => [
                'data' => $v['data_visita'],
                'objetivo' => $v['objetivo'],
                'estagio' => $v['estagio_cultura'],
                'finalizada' => (int) $v['finalizada'] === 1,
                'produtor' => $v['produtor'],
                'tecnico' => $v['tecnico'],
            ], $visitas),
        ];
    }

    /** Filtro de município: cod_ibge (indexado) quando houver; senão nome/UF exatos. */
    private static function filtroMunicipio(string $municipio, ?string $uf): array
    {
        $uf = $uf !== null && trim($uf) !== '' ? mb_strtoupper(substr(trim($uf), 0, 2)) : null;
        $alvo = self::semAcento($municipio);
        $cands = Database::todos(
            'SELECT DISTINCT municipio, uf, cod_ibge FROM dim_imovel' . ($uf ? ' WHERE uf = ?' : ''),
            $uf ? [$uf] : []
        );
        $ibges = [];
        $mun = $municipio;
        $ufv = $uf ?? 'SC';
        foreach ($cands as $c) {
            if (strpos(self::semAcento((string) $c['municipio']), $alvo) !== false) {
                if (!empty($c['cod_ibge'])) {
                    $ibges[(string) $c['cod_ibge']] = true;
                }
                $mun = (string) $c['municipio'];
                $ufv = (string) $c['uf'];
            }
        }
        if ($ibges) {
            $marks = implode(',', array_fill(0, count($ibges), '?'));
            return ["cod_ibge IN ($marks)", array_map('strval', array_keys($ibges))];
        }
        return ['municipio = ? AND uf = ?', [$mun, $ufv]];
    }

    /** cod_car → produtor principal (nome/id/rtv), em lote. */
    private static function produtorPrincipalDe(array $cods): array
    {
        $map = [];
        foreach (array_chunk($cods, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $rows = Database::todos(
                "SELECT b.cod_car, b.produtor_id, c.nome, u.nome AS rtv
                   FROM bridge_imovel_produtor b
                   JOIN clientes c ON c.id = b.produtor_id
                   LEFT JOIN usuarios u ON u.id = c.responsavel_id
                  WHERE b.cod_car IN ($marks)
                  ORDER BY b.principal DESC, b.id ASC",
                $chunk
            );
            foreach ($rows as $r) {
                $cod = (string) $r['cod_car'];
                if (!isset($map[$cod])) { // 1º = principal (ORDER principal DESC)
                    $map[$cod] = ['nome' => $r['nome'], 'id' => (int) $r['produtor_id'], 'rtv' => $r['rtv']];
                }
            }
        }
        return $map;
    }

    /** cod_car → cultura principal (maior área na safra), em lote. */
    private static function culturaPrincipalDe(array $cods, string $safra): array
    {
        $melhor = []; // cod_car => [cultura, area]
        foreach (array_chunk($cods, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $params = $chunk;
            $fSafra = '';
            if ($safra !== '') {
                $fSafra = ' AND safra = ?';
                $params[] = $safra;
            }
            $rows = Database::todos(
                "SELECT cod_car, cultura, SUM(area_plantada) AS area
                   FROM fato_talhao_safra
                  WHERE cod_car IN ($marks)$fSafra
                  GROUP BY cod_car, cultura",
                $params
            );
            foreach ($rows as $r) {
                $cod = (string) $r['cod_car'];
                $a = (float) $r['area'];
                if (!isset($melhor[$cod]) || $a > $melhor[$cod][1]) {
                    $melhor[$cod] = [(string) $r['cultura'], $a];
                }
            }
        }
        return array_map(static fn ($v) => $v[0], $melhor);
    }

    /** Normaliza o contorno em lista de anéis ([lat,lng]). */
    private static function aneis($contorno): array
    {
        if (!is_array($contorno) || !$contorno) {
            return [];
        }
        return (isset($contorno[0][0]) && is_array($contorno[0][0])) ? $contorno : [$contorno];
    }

    /** Contorno (JSON [lat,lng]) → geometria GeoJSON ([lng,lat], anéis fechados). */
    private static function geojsonGeometry($geo): ?array
    {
        $contorno = is_string($geo) ? json_decode($geo, true) : $geo;
        $partes = [];
        foreach (self::aneis($contorno) as $anel) {
            if (!is_array($anel) || count($anel) < 3) {
                continue;
            }
            $ring = [];
            foreach ($anel as $pt) {
                $ring[] = [(float) $pt[1], (float) $pt[0]]; // [lng, lat]
            }
            if ($ring[0] !== $ring[count($ring) - 1]) {
                $ring[] = $ring[0]; // GeoJSON exige o anel fechado
            }
            $partes[] = [$ring]; // polígono = [anel externo]
        }
        if (!$partes) {
            return null;
        }
        return count($partes) === 1
            ? ['type' => 'Polygon', 'coordinates' => $partes[0]]
            : ['type' => 'MultiPolygon', 'coordinates' => $partes];
    }
}
