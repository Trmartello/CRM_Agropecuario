<?php

namespace App\Services;

/**
 * Leitura do shapefile do CAR (SICAR) em PHP puro — sem depender de bibliotecas
 * externas nem de serviço online (segue a regra do projeto). O produtor baixa o
 * .zip do imóvel na consulta pública do CAR (Shapefile) e o app extrai o
 * PERÍMETRO do imóvel (AREA_IMOVEL), devolvendo o anel externo como
 * [[lat,lng],...] pronto para virar a divisa da propriedade no croqui.
 *
 * O CAR publica em coordenadas geográficas SIRGAS 2000 (graus, compatível com
 * o WGS84 do croqui). Arquivos em coordenadas projetadas (UTM) são recusados
 * com aviso claro — converter projeção fugiria do escopo "tudo local".
 */
class ShapefileService
{
    /**
     * Abre o arquivo baixado do CAR e devolve a divisa do imóvel [[lat,lng],...].
     * Aceita o que o SICAR (e apps de GIS) entregam: Shapefile (.shp),
     * KML/KMZ e GeoJSON — inclusive quando vêm dentro de zips aninhados
     * (o download individual costuma empacotar um zip por camada).
     */
    public static function contornoDoCarZip(string $caminho): array
    {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('O servidor está sem suporte a ZIP (extensão php-zip).');
        }
        // Coleta os arquivos geográficos por extensão (recursivo em zips/kmz)
        $arquivos = [];
        self::coletar($caminho, $arquivos, 0);

        $melhorAnel = [];
        $melhorArea = -1.0;
        $avaliar = function (array $anel, float $area) use (&$melhorAnel, &$melhorArea) {
            if ($anel && $area > $melhorArea) {
                $melhorArea = $area;
                $melhorAnel = $anel;
            }
        };

        // 1) Shapefile (.shp) — formato oficial do CAR
        foreach ($arquivos['shp'] ?? [] as $bin) {
            [$anel, $area] = self::maiorAnel($bin);
            $avaliar($anel, $area);
        }
        // 2) KML/KMZ  3) GeoJSON — fallbacks para outros downloads
        if (!$melhorAnel) {
            foreach ($arquivos['kml'] ?? [] as $bin) {
                [$anel, $area] = self::maiorAnelTexto(self::aneisDeKml($bin));
                $avaliar($anel, $area);
            }
        }
        if (!$melhorAnel) {
            foreach ($arquivos['geojson'] ?? [] as $bin) {
                [$anel, $area] = self::maiorAnelTexto(self::aneisDeGeoJson($bin));
                $avaliar($anel, $area);
            }
        }

        if (!$melhorAnel) {
            $vistos = array_keys($arquivos);
            $lista = $vistos ? implode(', ', $vistos) : 'nenhum arquivo reconhecido';
            throw new \InvalidArgumentException(
                'Não encontrei o polígono do imóvel no arquivo (conteúdo: ' . $lista . '). '
                . 'Na consulta pública do CAR, baixe o imóvel em Shapefile (ou KML/GeoJSON) e envie o arquivo .zip.'
            );
        }

        // Coordenadas devem ser geográficas (graus). Projetadas (UTM) têm valores gigantes.
        foreach ($melhorAnel as $pt) {
            if ($pt[0] < -90 || $pt[0] > 90 || $pt[1] < -180 || $pt[1] > 180) {
                throw new \InvalidArgumentException(
                    'O arquivo está em coordenadas projetadas (UTM). Baixe o CAR em coordenadas geográficas (graus/SIRGAS 2000).'
                );
            }
        }

        // Fecha repetido no fim (o anel repete o 1º ponto) e simplifica.
        $n = count($melhorAnel);
        if ($n > 1 && $melhorAnel[0] === $melhorAnel[$n - 1]) {
            array_pop($melhorAnel);
        }
        if (count($melhorAnel) < 3) {
            throw new \InvalidArgumentException('O polígono do imóvel tem menos de 3 pontos.');
        }
        return self::simplificar($melhorAnel, CroquiService::MAX_PONTOS - 5);
    }

    /**
     * Percorre um zip (ou kmz) e guarda os binários geográficos por extensão,
     * entrando em zips aninhados (o CAR empacota um zip por camada). Também
     * aceita um arquivo solto .kml/.geojson que não seja zip.
     */
    private static function coletar(string $caminho, array &$saida, int $profundidade): void
    {
        if ($profundidade > 4) {
            return; // trava contra zip-bomba/recursão
        }
        $zip = new \ZipArchive();
        if ($zip->open($caminho) !== true) {
            // não é zip: talvez um .kml/.geojson solto
            $bin = @file_get_contents($caminho);
            if ($bin !== false && $bin !== '') {
                if (stripos($bin, '<kml') !== false || stripos($bin, '<coordinates') !== false) {
                    $saida['kml'][] = $bin;
                } elseif (stripos($bin, '"coordinates"') !== false || stripos($bin, '"FeatureCollection"') !== false) {
                    $saida['geojson'][] = $bin;
                }
            }
            return;
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nome = (string) $zip->getNameIndex($i);
            $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
            $bin = $zip->getFromIndex($i);
            if ($bin === false) {
                continue;
            }
            if ($ext === 'zip' || $ext === 'kmz') {
                // extrai para arquivo temporário e recorre
                $tmp = tempnam(sys_get_temp_dir(), 'car');
                if ($tmp !== false) {
                    file_put_contents($tmp, $bin);
                    self::coletar($tmp, $saida, $profundidade + 1);
                    @unlink($tmp);
                }
            } elseif ($ext === 'shp' && strlen($bin) >= 100) {
                $saida['shp'][] = $bin;
            } elseif ($ext === 'kml') {
                $saida['kml'][] = $bin;
            } elseif ($ext === 'geojson' || $ext === 'json') {
                $saida['geojson'][] = $bin;
            }
        }
        $zip->close();
    }

    /** Maior anel (por área) de uma lista de anéis já em [[lat,lng],...]. */
    private static function maiorAnelTexto(array $aneis): array
    {
        $melhor = [];
        $area = -1.0;
        foreach ($aneis as $anel) {
            if (count($anel) < 3) {
                continue;
            }
            $a = abs(self::areaShoelace($anel));
            if ($a > $area) {
                $area = $a;
                $melhor = $anel;
            }
        }
        return [$melhor, $area];
    }

    /** Extrai os anéis (listas de pontos) de todos os <coordinates> de um KML. */
    private static function aneisDeKml(string $kml): array
    {
        $aneis = [];
        if (!preg_match_all('/<coordinates>(.*?)<\/coordinates>/is', $kml, $m)) {
            return $aneis;
        }
        foreach ($m[1] as $blob) {
            $anel = [];
            foreach (preg_split('/\s+/', trim($blob)) as $tok) {
                if ($tok === '') {
                    continue;
                }
                $p = explode(',', $tok);
                if (count($p) < 2 || !is_numeric($p[0]) || !is_numeric($p[1])) {
                    continue;
                }
                $anel[] = [(float) $p[1], (float) $p[0]]; // KML = lng,lat
            }
            if (count($anel) >= 3) {
                $aneis[] = $anel;
            }
        }
        return $aneis;
    }

    /** Extrai os anéis de Polygon/MultiPolygon de um GeoJSON. */
    private static function aneisDeGeoJson(string $texto): array
    {
        $dados = json_decode($texto, true);
        if (!is_array($dados)) {
            return [];
        }
        $aneis = [];
        $coletaGeom = function ($geom) use (&$aneis, &$coletaGeom) {
            if (!is_array($geom) || empty($geom['type'])) {
                return;
            }
            $tipo = $geom['type'];
            if ($tipo === 'Polygon' && !empty($geom['coordinates'][0])) {
                $aneis[] = self::pontosGeoJson($geom['coordinates'][0]);
            } elseif ($tipo === 'MultiPolygon' && !empty($geom['coordinates'])) {
                foreach ($geom['coordinates'] as $poly) {
                    if (!empty($poly[0])) {
                        $aneis[] = self::pontosGeoJson($poly[0]);
                    }
                }
            } elseif ($tipo === 'GeometryCollection' && !empty($geom['geometries'])) {
                foreach ($geom['geometries'] as $g) {
                    $coletaGeom($g);
                }
            }
        };
        if (!empty($dados['features'])) {
            foreach ($dados['features'] as $f) {
                $coletaGeom($f['geometry'] ?? null);
            }
        } elseif (!empty($dados['geometry'])) {
            $coletaGeom($dados['geometry']);
        } else {
            $coletaGeom($dados);
        }
        return array_filter($aneis, fn ($a) => count($a) >= 3);
    }

    /** Converte a lista de pontos GeoJSON [lng,lat] para [[lat,lng],...]. */
    private static function pontosGeoJson(array $ring): array
    {
        $anel = [];
        foreach ($ring as $p) {
            if (is_array($p) && count($p) >= 2 && is_numeric($p[0]) && is_numeric($p[1])) {
                $anel[] = [(float) $p[1], (float) $p[0]];
            }
        }
        return $anel;
    }

    /**
     * Percorre um .shp binário e devolve [anelMaior, areaMaior]. O anel externo
     * de um polígono é sempre o de maior área (buracos têm área menor).
     */
    private static function maiorAnel(string $bin): array
    {
        // Header: shape type no byte 32 (int32 little-endian). 5/15/25 = Polygon.
        $tipo = unpack('Vt', substr($bin, 32, 4))['t'];
        if (!in_array($tipo, [5, 15, 25, 3, 13, 23], true)) {
            return [[], -1.0]; // não é polígono nem polyline
        }
        $len = strlen($bin);
        $off = 100; // fim do header do arquivo
        $melhor = [];
        $melhorArea = -1.0;

        while ($off + 8 <= $len) {
            // Cabeçalho do registro (big-endian): número + tamanho em palavras de 16 bits
            $rec = unpack('Nnum/Nwords', substr($bin, $off, 8));
            $off += 8;
            $contentLen = $rec['words'] * 2;
            if ($contentLen <= 0 || $off + $contentLen > $len) {
                break;
            }
            $shape = substr($bin, $off, $contentLen);
            $off += $contentLen;

            $st = unpack('Vt', substr($shape, 0, 4))['t'];
            if ($st === 0) {
                continue; // registro nulo
            }
            // Após o tipo: bbox (4 doubles = 32 bytes) → numParts, numPoints
            $hdr = unpack('Vparts/Vpoints', substr($shape, 36, 8));
            $numParts = $hdr['parts'];
            $numPoints = $hdr['points'];
            if ($numParts < 1 || $numPoints < 3) {
                continue;
            }
            $parts = array_values(unpack('V' . $numParts, substr($shape, 44, 4 * $numParts)));
            $pointsOff = 44 + 4 * $numParts;
            $coords = array_values(unpack('d' . ($numPoints * 2), substr($shape, $pointsOff, 16 * $numPoints)));

            for ($p = 0; $p < $numParts; $p++) {
                $ini = $parts[$p];
                $fim = ($p + 1 < $numParts) ? $parts[$p + 1] : $numPoints;
                $anel = [];
                for ($k = $ini; $k < $fim; $k++) {
                    // shapefile guarda X=longitude, Y=latitude
                    $anel[] = [$coords[$k * 2 + 1], $coords[$k * 2]];
                }
                if (count($anel) < 3) {
                    continue;
                }
                $area = abs(self::areaShoelace($anel));
                if ($area > $melhorArea) {
                    $melhorArea = $area;
                    $melhor = $anel;
                }
            }
        }
        return [$melhor, $melhorArea];
    }

    /** Área por fórmula do sarrafo (relativa — só para comparar anéis). */
    private static function areaShoelace(array $anel): float
    {
        $soma = 0.0;
        $n = count($anel);
        for ($i = 0; $i < $n; $i++) {
            $a = $anel[$i];
            $b = $anel[($i + 1) % $n];
            $soma += $a[1] * $b[0] - $b[1] * $a[0];
        }
        return $soma / 2;
    }

    /**
     * Reduz o número de vértices para caber no limite do croqui, preservando a
     * forma (Douglas-Peucker em metros; decimação uniforme como rede de segurança).
     */
    private static function simplificar(array $anel, int $max): array
    {
        $arred = fn ($p) => [round($p[0], 7), round($p[1], 7)];
        if (count($anel) <= $max) {
            return array_map($arred, $anel);
        }
        $lat0 = array_sum(array_column($anel, 0)) / count($anel);
        $mLat = 110574.0;
        $mLng = 111320.0 * cos(deg2rad($lat0));
        $proj = array_map(fn ($p) => [$p[1] * $mLng, $p[0] * $mLat], $anel);

        $eps = 1.0; // metros
        $out = $anel;
        for ($i = 0; $i < 30 && count($out) > $max; $i++) {
            $keep = array_fill(0, count($proj), false);
            $keep[0] = true;
            $keep[count($proj) - 1] = true;
            self::dp($proj, 0, count($proj) - 1, $eps, $keep);
            $out = [];
            foreach ($anel as $j => $pt) {
                if ($keep[$j]) {
                    $out[] = $pt;
                }
            }
            $eps *= 1.7;
        }
        if (count($out) > $max) { // decimação uniforme
            $passo = (int) ceil(count($anel) / $max);
            $out = [];
            for ($j = 0; $j < count($anel); $j += $passo) {
                $out[] = $anel[$j];
            }
        }
        return array_map($arred, $out);
    }

    /** Douglas-Peucker: marca em $keep os vértices que preservam a forma. */
    private static function dp(array $pts, int $ini, int $fim, float $eps, array &$keep): void
    {
        if ($fim <= $ini + 1) {
            return;
        }
        [$ax, $ay] = $pts[$ini];
        [$bx, $by] = $pts[$fim];
        $dx = $bx - $ax;
        $dy = $by - $ay;
        $comp = hypot($dx, $dy) ?: 1e-9;
        $maxD = -1.0;
        $idx = -1;
        for ($i = $ini + 1; $i < $fim; $i++) {
            $d = abs(($pts[$i][0] - $ax) * $dy - ($pts[$i][1] - $ay) * $dx) / $comp;
            if ($d > $maxD) {
                $maxD = $d;
                $idx = $i;
            }
        }
        if ($maxD > $eps && $idx > $ini) {
            $keep[$idx] = true;
            self::dp($pts, $ini, $idx, $eps, $keep);
            self::dp($pts, $idx, $fim, $eps, $keep);
        }
    }
}
