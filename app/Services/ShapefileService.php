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
    /** Abre o .zip do CAR e devolve a divisa do imóvel [[lat,lng],...]. */
    public static function contornoDoCarZip(string $zipPath): array
    {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('O servidor está sem suporte a ZIP (extensão php-zip).');
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \InvalidArgumentException('Não consegui abrir o arquivo. Envie o .zip baixado do CAR (formato Shapefile).');
        }

        // Prioriza o shapefile do PERÍMETRO do imóvel; se não achar pelo nome,
        // avalia todos os .shp e fica com o maior polígono (o perímetro).
        $preferidos = [];
        $todos = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nome = (string) $zip->getNameIndex($i);
            if (!preg_match('/\.shp$/i', $nome)) {
                continue;
            }
            $todos[] = $i;
            if (preg_match('/area[_ ]?imovel/i', $nome)) {
                $preferidos[] = $i;
            }
        }
        $candidatos = $preferidos ?: $todos;
        if (!$candidatos) {
            $zip->close();
            throw new \InvalidArgumentException('O .zip não contém um shapefile (.shp) do CAR.');
        }

        $melhorAnel = [];
        $melhorArea = -1.0;
        foreach ($candidatos as $idx) {
            $bin = $zip->getFromIndex($idx);
            if ($bin === false || strlen($bin) < 100) {
                continue;
            }
            [$anel, $area] = self::maiorAnel($bin);
            if ($anel && $area > $melhorArea) {
                $melhorArea = $area;
                $melhorAnel = $anel;
            }
        }
        $zip->close();

        if (!$melhorAnel) {
            throw new \InvalidArgumentException('Não encontrei um polígono de área no shapefile do CAR.');
        }

        // Coordenadas devem ser geográficas (graus). Projetadas (UTM) têm valores gigantes.
        foreach ($melhorAnel as $pt) {
            if ($pt[0] < -90 || $pt[0] > 90 || $pt[1] < -180 || $pt[1] > 180) {
                throw new \InvalidArgumentException(
                    'O shapefile está em coordenadas projetadas (UTM). Baixe o CAR em coordenadas geográficas (graus/SIRGAS 2000).'
                );
            }
        }

        // Fecha repetido no fim (shapefile repete o 1º ponto) e simplifica.
        $n = count($melhorAnel);
        if ($n > 1 && $melhorAnel[0] === $melhorAnel[$n - 1]) {
            array_pop($melhorAnel);
        }
        return self::simplificar($melhorAnel, CroquiService::MAX_PONTOS - 5);
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
