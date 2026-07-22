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
    /**
     * Importa a base do CAR de um arquivo (município inteiro) em FLUXO —
     * lê o shapefile registro a registro e insere em transação, sem carregar
     * tudo na memória. Substitui apenas os municípios presentes no arquivo.
     */
    public static function importarMunicipioArquivo(string $caminho, ?string $municipioPadrao = null, ?string $ufPadrao = null): array
    {
        $municipioPadrao = $municipioPadrao !== null && trim($municipioPadrao) !== '' ? mb_strtoupper(trim($municipioPadrao)) : null;
        $ufPadrao = $ufPadrao !== null && trim($ufPadrao) !== '' ? strtoupper(substr(trim($ufPadrao), 0, 2)) : null;

        $pdo = Database::conexao();
        $pdo->beginTransaction();
        try {
            $deletados = [];      // "MUN|UF" já limpos nesta carga
            $porMunicipio = [];   // "MUN/UF" => contagem
            $noLote = 0;          // inseridos na transação atual
            $stmt = $pdo->prepare(
                'INSERT INTO car_imoveis
                    (cod_imovel, municipio, uf, contorno, area_ha, min_lat, min_lng, max_lat, max_lng)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            );

            // Confirma em LOTES: município inteiro numa única transação geraria
            // um undo log gigante (risco de estouro/lentidão no MySQL do Railway).
            \App\Services\ShapefileService::streamImoveis($caminho, function (array $im) use ($pdo, $stmt, $municipioPadrao, $ufPadrao, &$deletados, &$porMunicipio, &$noLote) {
                $mun = ($im['municipio'] ?? null) ? mb_strtoupper(trim($im['municipio'])) : $municipioPadrao;
                $uf = ($im['uf'] ?? null) ?: $ufPadrao;
                if (!$mun || !$uf) {
                    return; // sem como classificar
                }
                $uf = strtoupper(substr($uf, 0, 2));
                $chaveDel = $mun . '|' . $uf;
                if (!isset($deletados[$chaveDel])) {
                    $pdo->prepare('DELETE FROM car_imoveis WHERE municipio = ? AND uf = ?')->execute([$mun, $uf]);
                    $deletados[$chaveDel] = true;
                }
                [$minLat, $minLng, $maxLat, $maxLng] = $im['bbox'];
                $stmt->execute([
                    mb_substr((string) ($im['cod'] ?? ''), 0, 80) ?: '(sem código)',
                    $mun, $uf, json_encode($im['contorno']),
                    round((float) ($im['area_ha'] ?? 0), 2),
                    $minLat, $minLng, $maxLat, $maxLng,
                ]);
                $chave = $mun . '/' . $uf;
                $porMunicipio[$chave] = ($porMunicipio[$chave] ?? 0) + 1;
                if (++$noLote >= 2000) {
                    $pdo->commit();
                    $pdo->beginTransaction();
                    $noLote = 0;
                }
            });

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $resumo = [];
        $inseridos = 0;
        foreach ($porMunicipio as $chave => $qtd) {
            [$m, $u] = explode('/', $chave);
            $resumo[] = ['municipio' => $m, 'uf' => $u, 'imoveis' => $qtd];
            $inseridos += $qtd;
        }
        return ['imoveis' => $inseridos, 'municipios' => $resumo];
    }

    /**
     * Substitui a base pelos imóveis importados (lista em memória — arquivos
     * pequenos/testes). Para município inteiro, use importarMunicipioArquivo.
     * Devolve ['imoveis'=>n, 'municipios'=>[['municipio','uf','n'],...]].
     */
    public static function importarMunicipio(array $imoveis, ?string $municipioPadrao = null, ?string $ufPadrao = null): array
    {
        $municipioPadrao = $municipioPadrao !== null ? mb_strtoupper(trim($municipioPadrao)) : null;
        $ufPadrao = $ufPadrao !== null ? strtoupper(substr(trim($ufPadrao), 0, 2)) : null;

        // Resolve município/UF de cada imóvel e agrupa
        $prontos = [];
        $tocados = []; // "MUNICIPIO|UF" => [municipio, uf]
        foreach ($imoveis as $im) {
            if (empty($im['contorno']) || count($im['contorno']) < 3) {
                continue;
            }
            $mun = ($im['municipio'] ?? null) ? mb_strtoupper(trim($im['municipio'])) : $municipioPadrao;
            $uf = ($im['uf'] ?? null) ?: $ufPadrao;
            if (!$mun || !$uf) {
                continue; // sem como classificar o imóvel
            }
            $im['_mun'] = $mun;
            $im['_uf'] = strtoupper(substr($uf, 0, 2));
            $prontos[] = $im;
            $tocados[$mun . '|' . $im['_uf']] = [$mun, $im['_uf']];
        }

        // Substitui apenas os municípios presentes no arquivo
        foreach ($tocados as [$mun, $uf]) {
            Database::executar('DELETE FROM car_imoveis WHERE municipio = ? AND uf = ?', [$mun, $uf]);
        }

        $porMunicipio = [];
        foreach ($prontos as $im) {
            [$minLat, $minLng, $maxLat, $maxLng] = $im['bbox'];
            Database::executar(
                'INSERT INTO car_imoveis
                    (cod_imovel, municipio, uf, contorno, area_ha, min_lat, min_lng, max_lat, max_lng)
                 VALUES (?,?,?,?,?,?,?,?,?)',
                [
                    mb_substr((string) ($im['cod'] ?? ''), 0, 80) ?: '(sem código)',
                    $im['_mun'], $im['_uf'], json_encode($im['contorno']),
                    round((float) ($im['area_ha'] ?? 0), 2),
                    $minLat, $minLng, $maxLat, $maxLng,
                ]
            );
            $chave = $im['_mun'] . '/' . $im['_uf'];
            $porMunicipio[$chave] = ($porMunicipio[$chave] ?? 0) + 1;
        }

        $resumo = [];
        foreach ($porMunicipio as $chave => $qtd) {
            [$m, $u] = explode('/', $chave);
            $resumo[] = ['municipio' => $m, 'uf' => $u, 'imoveis' => $qtd];
        }
        return ['imoveis' => count($prontos), 'municipios' => $resumo];
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
