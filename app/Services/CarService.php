<?php

namespace App\Services;

use App\Core\Database;

/**
 * Base do CAR por município (SICAR): guarda os polígonos dos imóveis rurais
 * para identificar a propriedade por GPS (ponto-dentro-do-polígono) — no
 * servidor e, via snapshot, offline no campo. Os dados são baixados uma vez
 * do SICAR (shapefile do município) e importados pelo Administrador.
 */
class CarService
{
    /** Substitui a base de um município pelos imóveis recém-importados. */
    public static function importarMunicipio(array $imoveis, string $municipio, string $uf): int
    {
        $municipio = mb_strtoupper(trim($municipio));
        $uf = strtoupper(substr(trim($uf), 0, 2));
        Database::executar('DELETE FROM car_imoveis WHERE municipio = ? AND uf = ?', [$municipio, $uf]);

        $n = 0;
        foreach ($imoveis as $im) {
            if (empty($im['contorno']) || count($im['contorno']) < 3) {
                continue;
            }
            [$minLat, $minLng, $maxLat, $maxLng] = $im['bbox'];
            Database::executar(
                'INSERT INTO car_imoveis
                    (cod_imovel, municipio, uf, contorno, area_ha, min_lat, min_lng, max_lat, max_lng)
                 VALUES (?,?,?,?,?,?,?,?,?)',
                [
                    mb_substr((string) ($im['cod'] ?? ''), 0, 80) ?: '(sem código)',
                    $municipio, $uf, json_encode($im['contorno']),
                    round((float) ($im['area_ha'] ?? 0), 2),
                    $minLat, $minLng, $maxLat, $maxLng,
                ]
            );
            $n++;
        }
        return $n;
    }

    /** Municípios com base carregada (para a tela e para o snapshot). */
    public static function municipios(): array
    {
        return Database::todos(
            'SELECT municipio, uf, COUNT(*) AS imoveis, MAX(criado_em) AS atualizado
               FROM car_imoveis GROUP BY municipio, uf ORDER BY municipio'
        );
    }

    public static function total(): int
    {
        return (int) Database::valor('SELECT COUNT(*) FROM car_imoveis');
    }

    /**
     * Imóvel cujo polígono contém o ponto [lat,lng] (pré-filtro por caixa
     * delimitadora + ray casting). Retorna null se o ponto não cai em nenhum.
     */
    public static function imovelNoPonto(float $lat, float $lng): ?array
    {
        $candidatos = Database::todos(
            'SELECT cod_imovel, contorno, area_ha FROM car_imoveis
              WHERE ? BETWEEN min_lat AND max_lat AND ? BETWEEN min_lng AND max_lng',
            [$lat, $lng]
        );
        foreach ($candidatos as $c) {
            $pontos = json_decode((string) $c['contorno'], true);
            if (is_array($pontos) && self::dentro($lat, $lng, $pontos)) {
                return [
                    'cod' => $c['cod_imovel'],
                    'contorno' => $pontos,
                    'area_ha' => (float) $c['area_ha'],
                ];
            }
        }
        return null;
    }

    /** Ray casting: o ponto [lat,lng] está dentro do polígono [[lat,lng],...]? */
    private static function dentro(float $lat, float $lng, array $poligono): bool
    {
        $dentro = false;
        $n = count($poligono);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $yi = (float) $poligono[$i][0];
            $xi = (float) $poligono[$i][1];
            $yj = (float) $poligono[$j][0];
            $xj = (float) $poligono[$j][1];
            if ((($yi > $lat) !== ($yj > $lat))
                && $lng < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi) {
                $dentro = !$dentro;
            }
        }
        return $dentro;
    }

    /**
     * Base do município para o snapshot offline (identificação por GPS sem
     * internet). Só o essencial: código, caixa e contorno.
     */
    public static function paraSnapshot(): array
    {
        return Database::todos(
            'SELECT cod_imovel AS cod, contorno, min_lat, min_lng, max_lat, max_lng FROM car_imoveis'
        );
    }
}
