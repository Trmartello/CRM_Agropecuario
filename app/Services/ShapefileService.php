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
     * Compatibilidade: mantém o retorno como lista de pontos.
     */
    public static function contornoDoCarZip(string $caminho): array
    {
        return self::lerCarZip($caminho)['contorno'];
    }

    /**
     * Lê o .zip do CAR e devolve ['contorno'=>[[lat,lng],...], 'cod'=>string|null].
     * Aceita o que o SICAR (e apps de GIS) entregam: Shapefile (.shp),
     * KML/KMZ e GeoJSON — inclusive quando vêm dentro de zips aninhados
     * (o download individual empacota um zip por camada). Quando o polígono
     * vem de um shapefile, extrai o número do imóvel (cod_imovel) do .dbf pareado.
     */
    public static function lerCarZip(string $caminho): array
    {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('O servidor está sem suporte a ZIP (extensão php-zip).');
        }
        // Coleta os arquivos geográficos por extensão (recursivo em zips/kmz)
        $arquivos = [];
        self::coletar($caminho, $arquivos, 0);

        $melhorAnel = [];
        $melhorArea = -1.0;
        $melhorBase = null; // nome-base do shapefile vencedor (parear com o .dbf)

        // 1) Shapefile (.shp) — formato oficial do CAR
        foreach ($arquivos['shp'] ?? [] as $base => $bin) {
            [$anel, $area] = self::maiorAnel($bin);
            if ($anel && $area > $melhorArea) {
                $melhorArea = $area;
                $melhorAnel = $anel;
                $melhorBase = $base;
            }
        }
        // 2) KML/KMZ  3) GeoJSON — fallbacks para outros downloads (sem código)
        if (!$melhorAnel) {
            foreach ($arquivos['kml'] ?? [] as $bin) {
                [$anel, $area] = self::maiorAnelTexto(self::aneisDeKml($bin));
                if ($anel && $area > $melhorArea) {
                    $melhorArea = $area;
                    $melhorAnel = $anel;
                }
            }
        }
        if (!$melhorAnel) {
            foreach ($arquivos['geojson'] ?? [] as $bin) {
                [$anel, $area] = self::maiorAnelTexto(self::aneisDeGeoJson($bin));
                if ($anel && $area > $melhorArea) {
                    $melhorArea = $area;
                    $melhorAnel = $anel;
                }
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

        // Número do imóvel + município/UF do .dbf pareado com o shapefile vencedor
        $cod = null;
        $municipio = null;
        $uf = null;
        if ($melhorBase !== null && !empty($arquivos['dbf'][$melhorBase])) {
            foreach (self::lerDbfLinhas($arquivos['dbf'][$melhorBase], self::DBF_ALIASES) as $r) {
                if ($cod === null && trim((string) ($r['cod'] ?? '')) !== '') {
                    $cod = trim($r['cod']);
                }
                if ($municipio === null && trim((string) ($r['municipio'] ?? '')) !== '') {
                    $municipio = trim($r['municipio']);
                }
                if ($uf === null && trim((string) ($r['estado'] ?? '')) !== '') {
                    $uf = self::ufDeEstado($r['estado']);
                }
                if ($cod !== null && $municipio !== null && $uf !== null) {
                    break;
                }
            }
        }

        return [
            'contorno' => self::simplificar($melhorAnel, CroquiService::MAX_PONTOS - 5),
            'cod' => $cod,
            'municipio' => $municipio,
            'uf' => $uf,
        ];
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
            $base = strtolower(pathinfo($nome, PATHINFO_FILENAME));
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
                $saida['shp'][$base] = $bin; // por nome, para parear com o .dbf
            } elseif ($ext === 'dbf') {
                $saida['dbf'][$base] = $bin;
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

    /* =====================================================================
     * Base do CAR por MUNICÍPIO: lê TODOS os imóveis do zip (shapefile) e o
     * código do CAR de cada um (do .dbf pareado). Usado para identificar o
     * imóvel por GPS (ponto-dentro-do-polígono), inclusive offline.
     * ===================================================================== */

    /**
     * Versão em FLUXO (streaming) para arquivos grandes (município inteiro):
     * lê o .shp registro a registro direto do zip (sem carregar tudo na
     * memória) e chama $cb(imovel) para cada imóvel. Devolve a contagem.
     * O .dbf (menor) é lido inteiro para acesso aleatório por registro.
     */
    public static function streamImoveis(string $caminho, callable $cb, int $maxPontos = 60): int
    {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('O servidor está sem suporte a ZIP (extensão php-zip).');
        }
        $zip = new \ZipArchive();
        if ($zip->open($caminho) !== true) {
            throw new \InvalidArgumentException('Não consegui abrir o arquivo .zip do CAR do município.');
        }
        $shpNames = [];
        $dbfNames = [];
        $aninhados = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nm = (string) $zip->getNameIndex($i);
            $ext = strtolower(pathinfo($nm, PATHINFO_EXTENSION));
            $base = strtolower(pathinfo($nm, PATHINFO_FILENAME));
            if ($ext === 'shp') {
                $shpNames[$base] = $nm;
            } elseif ($ext === 'dbf') {
                $dbfNames[$base] = $nm;
            } elseif ($ext === 'zip' || $ext === 'kmz') {
                $aninhados[] = $nm;
            }
        }

        $n = 0;
        foreach ($shpNames as $base => $shpName) {
            $stream = $zip->getStream($shpName); // lê descomprimido sob demanda
            if (!$stream) {
                continue;
            }
            $dbfBin = isset($dbfNames[$base]) ? (string) $zip->getFromName($dbfNames[$base]) : '';
            $n += self::streamUmShp($stream, $dbfBin, $cb, $maxPontos);
            fclose($stream);
            unset($dbfBin);
        }
        // Zips aninhados (caso raro no download por município): extrai e recorre
        foreach ($aninhados as $nm) {
            $bin = $zip->getFromName($nm);
            if ($bin === false) {
                continue;
            }
            $tmp = tempnam(sys_get_temp_dir(), 'carm');
            if ($tmp !== false) {
                file_put_contents($tmp, $bin);
                unset($bin);
                $n += self::streamImoveis($tmp, $cb, $maxPontos);
                @unlink($tmp);
            }
        }
        $zip->close();
        return $n;
    }

    /** Processa um .shp (stream aberto) registro a registro, com o .dbf pareado. */
    private static function streamUmShp($stream, string $dbfBin, callable $cb, int $maxPontos): int
    {
        $hdr = self::freadN($stream, 100);
        if (strlen($hdr) < 100) {
            return 0;
        }
        $tipo = unpack('Vt', substr($hdr, 32, 4))['t'];
        if (!in_array($tipo, [5, 15, 25, 3, 13, 23], true)) {
            return 0;
        }
        $dbf = self::prepararDbf($dbfBin);
        $i = 0;
        $n = 0;
        while (true) {
            $rh = self::freadN($stream, 8);
            if (strlen($rh) < 8) {
                break;
            }
            $rec = unpack('Nnum/Nwords', $rh);
            $cl = $rec['words'] * 2;
            if ($cl <= 0 || $cl > 50_000_000) {
                break;
            }
            $shape = self::freadN($stream, $cl);
            if (strlen($shape) < $cl) {
                break;
            }
            $anel = self::maiorAnelDoShape($shape);
            $meta = $dbf ? self::dbfRegistro($dbf, $dbfBin, $i) : [];
            $i++;
            if (count($anel) < 3) {
                continue;
            }
            $m = count($anel);
            if ($anel[0] === $anel[$m - 1]) {
                array_pop($anel);
            }
            if (count($anel) < 3) {
                continue;
            }
            // Descarta imóvel em coordenadas projetadas (não trava o arquivo todo)
            if ($anel[0][0] < -90 || $anel[0][0] > 90 || $anel[0][1] < -180 || $anel[0][1] > 180) {
                continue;
            }
            $simpl = self::simplificar($anel, $maxPontos);
            $lats = array_column($simpl, 0);
            $lngs = array_column($simpl, 1);
            $cb([
                'cod' => ($meta['cod'] ?? '') !== '' ? $meta['cod'] : null,
                'municipio' => ($meta['municipio'] ?? '') !== '' ? $meta['municipio'] : null,
                'uf' => isset($meta['estado']) && $meta['estado'] !== '' ? self::ufDeEstado($meta['estado']) : null,
                'contorno' => $simpl,
                'area_ha' => CroquiService::areaHa($simpl),
                'bbox' => [min($lats), min($lngs), max($lats), max($lngs)],
            ]);
            $n++;
        }
        return $n;
    }

    /** Lê exatamente N bytes de um stream (o zip pode entregar em pedaços). */
    private static function freadN($stream, int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n && !feof($stream)) {
            $pedaco = fread($stream, $n - strlen($buf));
            if ($pedaco === false || $pedaco === '') {
                break;
            }
            $buf .= $pedaco;
        }
        return $buf;
    }

    /** Maior anel (por área) de UM registro .shp (binário do shape). [lat,lng]. */
    private static function maiorAnelDoShape(string $shape): array
    {
        if (strlen($shape) < 44) {
            return [];
        }
        $st = unpack('Vt', substr($shape, 0, 4))['t'];
        if ($st === 0) {
            return [];
        }
        $hdr = unpack('Vparts/Vpoints', substr($shape, 36, 8));
        $numParts = $hdr['parts'];
        $numPoints = $hdr['points'];
        if ($numParts < 1 || $numPoints < 3) {
            return [];
        }
        $parts = array_values(unpack('V' . $numParts, substr($shape, 44, 4 * $numParts)));
        $pointsOff = 44 + 4 * $numParts;
        $coords = array_values(unpack('d' . ($numPoints * 2), substr($shape, $pointsOff, 16 * $numPoints)));
        $melhor = [];
        $melhorArea = -1.0;
        for ($p = 0; $p < $numParts; $p++) {
            $ini = $parts[$p];
            $fim = ($p + 1 < $numParts) ? $parts[$p + 1] : $numPoints;
            $anel = [];
            for ($k = $ini; $k < $fim; $k++) {
                $anel[] = [$coords[$k * 2 + 1], $coords[$k * 2]];
            }
            if (count($anel) < 3) {
                continue;
            }
            $a = abs(self::areaShoelace($anel));
            if ($a > $melhorArea) {
                $melhorArea = $a;
                $melhor = $anel;
            }
        }
        return $melhor;
    }

    /** Prepara os descritores do .dbf (uma vez) para leitura por registro. */
    private static function prepararDbf(string $bin): ?array
    {
        if (strlen($bin) < 32) {
            return null;
        }
        $h = unpack('Cver/C3d/Vnrec/vhsize/vrsize', substr($bin, 0, 12));
        $campos = [];
        $pos = 32;
        $offset = 1;
        while ($pos < $h['hsize'] - 1 && ord($bin[$pos]) !== 0x0D) {
            $campos[] = ['nome' => rtrim(substr($bin, $pos, 11), "\0"), 'off' => $offset, 'tam' => ord($bin[$pos + 16])];
            $offset += ord($bin[$pos + 16]);
            $pos += 32;
        }
        $alvos = [];
        foreach (self::DBF_ALIASES as $alias => $nomes) {
            foreach ($nomes as $pref) {
                foreach ($campos as $c) {
                    if (strcasecmp($c['nome'], $pref) === 0) {
                        $alvos[$alias] = $c;
                        break 2;
                    }
                }
            }
            if (!isset($alvos[$alias]) && $alias === 'cod') {
                foreach ($campos as $c) {
                    if (stripos($c['nome'], 'imovel') !== false || stripos($c['nome'], 'cod') !== false) {
                        $alvos[$alias] = $c;
                        break;
                    }
                }
            }
        }
        return ['hsize' => $h['hsize'], 'rsize' => $h['rsize'], 'nrec' => $h['nrec'], 'alvos' => $alvos];
    }

    /** Lê os campos de um registro do .dbf já preparado. */
    private static function dbfRegistro(array $dbf, string $bin, int $i): array
    {
        $recOff = $dbf['hsize'] + $i * $dbf['rsize'];
        if ($recOff + $dbf['rsize'] > strlen($bin)) {
            return [];
        }
        $out = [];
        foreach ($dbf['alvos'] as $alias => $c) {
            $val = trim(substr($bin, $recOff + $c['off'], $c['tam']));
            if ($val !== '' && !mb_check_encoding($val, 'UTF-8')) {
                $val = mb_convert_encoding($val, 'UTF-8', 'Windows-1252');
            }
            $out[$alias] = $val;
        }
        return $out;
    }

    /**
     * Devolve a lista de imóveis do município:
     * [ ['cod'=>string|null, 'contorno'=>[[lat,lng],...], 'area_ha'=>float,
     *    'bbox'=>[minLat,minLng,maxLat,maxLng]], ... ]
     */
    public static function imoveisDoZip(string $caminho, int $maxPontos = 60): array
    {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('O servidor está sem suporte a ZIP (extensão php-zip).');
        }
        $shp = [];
        $dbf = [];
        self::coletarPares($caminho, $shp, $dbf, 0);
        if (!$shp) {
            throw new \InvalidArgumentException('O arquivo não contém shapefile (.shp) do CAR do município.');
        }

        $imoveis = [];
        foreach ($shp as $base => $binShp) {
            $rings = self::registrosPoligono($binShp);
            $linhas = isset($dbf[$base]) ? self::lerDbfLinhas($dbf[$base], self::DBF_ALIASES) : [];
            foreach ($rings as $i => $anel) {
                if (count($anel) < 3) {
                    continue;
                }
                $n = count($anel);
                if ($anel[0] === $anel[$n - 1]) {
                    array_pop($anel);
                }
                if (count($anel) < 3) {
                    continue;
                }
                foreach ($anel as $pt) {
                    if ($pt[0] < -90 || $pt[0] > 90 || $pt[1] < -180 || $pt[1] > 180) {
                        throw new \InvalidArgumentException(
                            'O arquivo do município está em coordenadas projetadas (UTM). Baixe o CAR em graus (SIRGAS 2000).'
                        );
                    }
                }
                $simpl = self::simplificar($anel, $maxPontos);
                $lats = array_column($simpl, 0);
                $lngs = array_column($simpl, 1);
                $r = $linhas[$i] ?? [];
                $imoveis[] = [
                    'cod' => ($r['cod'] ?? '') !== '' ? $r['cod'] : null,
                    'municipio' => ($r['municipio'] ?? '') !== '' ? $r['municipio'] : null,
                    'uf' => isset($r['estado']) && $r['estado'] !== '' ? self::ufDeEstado($r['estado']) : null,
                    'contorno' => $simpl,
                    'area_ha' => CroquiService::areaHa($simpl),
                    'bbox' => [min($lats), min($lngs), max($lats), max($lngs)],
                ];
            }
        }
        if (!$imoveis) {
            throw new \InvalidArgumentException('Não encontrei polígonos de imóveis no shapefile do município.');
        }
        return $imoveis;
    }

    /** Coleta binários .shp e .dbf por nome-base, entrando em zips aninhados. */
    private static function coletarPares(string $caminho, array &$shp, array &$dbf, int $prof): void
    {
        if ($prof > 4) {
            return;
        }
        $zip = new \ZipArchive();
        if ($zip->open($caminho) !== true) {
            return;
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nome = (string) $zip->getNameIndex($i);
            $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
            $base = strtolower(pathinfo($nome, PATHINFO_FILENAME));
            $bin = $zip->getFromIndex($i);
            if ($bin === false) {
                continue;
            }
            if ($ext === 'zip' || $ext === 'kmz') {
                $tmp = tempnam(sys_get_temp_dir(), 'carm');
                if ($tmp !== false) {
                    file_put_contents($tmp, $bin);
                    self::coletarPares($tmp, $shp, $dbf, $prof + 1);
                    @unlink($tmp);
                }
            } elseif ($ext === 'shp' && strlen($bin) >= 100) {
                $shp[$base] = $bin;
            } elseif ($ext === 'dbf') {
                $dbf[$base] = $bin;
            }
        }
        $zip->close();
    }

    /** Um anel (maior por área) por REGISTRO do .shp, mantendo a ordem (alinha com o .dbf). */
    private static function registrosPoligono(string $bin): array
    {
        $tipo = unpack('Vt', substr($bin, 32, 4))['t'];
        if (!in_array($tipo, [5, 15, 25, 3, 13, 23], true)) {
            return [];
        }
        $len = strlen($bin);
        $off = 100;
        $out = [];
        while ($off + 8 <= $len) {
            $rec = unpack('Nnum/Nwords', substr($bin, $off, 8));
            $off += 8;
            $cl = $rec['words'] * 2;
            if ($cl <= 0 || $off + $cl > $len) {
                break;
            }
            $shape = substr($bin, $off, $cl);
            $off += $cl;
            $st = unpack('Vt', substr($shape, 0, 4))['t'];
            if ($st === 0) {
                $out[] = []; // registro nulo — mantém o índice alinhado ao .dbf
                continue;
            }
            $hdr = unpack('Vparts/Vpoints', substr($shape, 36, 8));
            $numParts = $hdr['parts'];
            $numPoints = $hdr['points'];
            if ($numParts < 1 || $numPoints < 3) {
                $out[] = [];
                continue;
            }
            $parts = array_values(unpack('V' . $numParts, substr($shape, 44, 4 * $numParts)));
            $pointsOff = 44 + 4 * $numParts;
            $coords = array_values(unpack('d' . ($numPoints * 2), substr($shape, $pointsOff, 16 * $numPoints)));
            $melhor = [];
            $melhorArea = -1.0;
            for ($p = 0; $p < $numParts; $p++) {
                $ini = $parts[$p];
                $fim = ($p + 1 < $numParts) ? $parts[$p + 1] : $numPoints;
                $anel = [];
                for ($k = $ini; $k < $fim; $k++) {
                    $anel[] = [$coords[$k * 2 + 1], $coords[$k * 2]];
                }
                if (count($anel) < 3) {
                    continue;
                }
                $a = abs(self::areaShoelace($anel));
                if ($a > $melhorArea) {
                    $melhorArea = $a;
                    $melhor = $anel;
                }
            }
            $out[] = $melhor;
        }
        return $out;
    }

    /** Lê um campo do .dbf (dBASE) para todos os registros, na ordem. */
    /** Nomes de campo do .dbf do CAR por informação (o SICAR usa "recibo", "municipio", "estado"). */
    private const DBF_ALIASES = [
        'cod' => ['recibo', 'cod_imovel', 'codigo', 'cod_car', 'nom_imovel', 'cod_tema'],
        'municipio' => ['municipio', 'nome_munic', 'nm_mun', 'municipi', 'nm_municip', 'nome_mun'],
        'estado' => ['estado', 'uf', 'nome_uf', 'sigla_uf', 'nm_uf'],
    ];

    /** Estados por nome (normalizado, sem acento) → sigla UF. */
    private const ESTADOS = [
        'ACRE' => 'AC', 'ALAGOAS' => 'AL', 'AMAPA' => 'AP', 'AMAZONAS' => 'AM', 'BAHIA' => 'BA',
        'CEARA' => 'CE', 'DISTRITO FEDERAL' => 'DF', 'ESPIRITO SANTO' => 'ES', 'GOIAS' => 'GO',
        'MARANHAO' => 'MA', 'MATO GROSSO' => 'MT', 'MATO GROSSO DO SUL' => 'MS', 'MINAS GERAIS' => 'MG',
        'PARA' => 'PA', 'PARAIBA' => 'PB', 'PARANA' => 'PR', 'PERNAMBUCO' => 'PE', 'PIAUI' => 'PI',
        'RIO DE JANEIRO' => 'RJ', 'RIO GRANDE DO NORTE' => 'RN', 'RIO GRANDE DO SUL' => 'RS',
        'RONDONIA' => 'RO', 'RORAIMA' => 'RR', 'SANTA CATARINA' => 'SC', 'SAO PAULO' => 'SP',
        'SERGIPE' => 'SE', 'TOCANTINS' => 'TO',
    ];

    /** Nome (ou sigla) do estado → UF de 2 letras. */
    private static function ufDeEstado(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^[A-Za-z]{2}$/', $v)) {
            return strtoupper($v);
        }
        $k = mb_strtoupper($v, 'UTF-8');
        $k = strtr($k, ['Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'É' => 'E', 'Ê' => 'E',
            'Í' => 'I', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ú' => 'U', 'Ç' => 'C']);
        $k = preg_replace('/\s+/', ' ', $k);
        return self::ESTADOS[$k] ?? null;
    }

    /**
     * Lê o .dbf (dBASE) devolvendo uma linha por registro com os campos pedidos
     * (aliases → possíveis nomes de coluna). Ordem preservada (alinha com o .shp).
     */
    private static function lerDbfLinhas(string $bin, array $aliases): array
    {
        if (strlen($bin) < 32) {
            return [];
        }
        $h = unpack('Cver/C3d/Vnrec/vhsize/vrsize', substr($bin, 0, 12));
        $nrec = $h['nrec'];
        $hsize = $h['hsize'];
        $rsize = $h['rsize'];
        // Descritores dos campos (32 bytes cada) a partir do byte 32, até 0x0D
        $campos = [];
        $pos = 32;
        $offset = 1; // 1º byte de cada registro é a flag de deleção
        while ($pos < $hsize - 1 && ord($bin[$pos]) !== 0x0D) {
            $campos[] = ['nome' => rtrim(substr($bin, $pos, 11), "\0"), 'off' => $offset, 'tam' => ord($bin[$pos + 16])];
            $offset += ord($bin[$pos + 16]);
            $pos += 32;
        }
        // Resolve cada alias para um campo (nomes preferidos; heurística só para o código)
        $alvos = [];
        foreach ($aliases as $alias => $nomes) {
            $achado = null;
            foreach ($nomes as $pref) {
                foreach ($campos as $c) {
                    if (strcasecmp($c['nome'], $pref) === 0) {
                        $achado = $c;
                        break 2;
                    }
                }
            }
            if (!$achado && $alias === 'cod') {
                foreach ($campos as $c) {
                    if (stripos($c['nome'], 'imovel') !== false || stripos($c['nome'], 'cod') !== false) {
                        $achado = $c;
                        break;
                    }
                }
            }
            if ($achado) {
                $alvos[$alias] = $achado;
            }
        }
        if (!$alvos) {
            return [];
        }
        $rows = [];
        for ($i = 0; $i < $nrec; $i++) {
            $recOff = $hsize + $i * $rsize;
            if ($recOff + $rsize > strlen($bin)) {
                break;
            }
            $row = [];
            foreach ($alvos as $alias => $c) {
                $val = trim(substr($bin, $recOff + $c['off'], $c['tam']));
                // .dbf costuma vir em Windows-1252/latin1 (acentos): normaliza para UTF-8
                if ($val !== '' && !mb_check_encoding($val, 'UTF-8')) {
                    $val = mb_convert_encoding($val, 'UTF-8', 'Windows-1252');
                }
                $row[$alias] = $val;
            }
            $rows[] = $row;
        }
        return $rows;
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
