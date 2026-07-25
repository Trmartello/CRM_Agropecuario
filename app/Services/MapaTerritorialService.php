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
}
