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
            $deletados = [];      // chaves de dedup já limpas nesta carga
            $porMunicipio = [];   // "MUN/UF" => contagem
            $noLote = 0;          // inseridos na transação atual
            $stmt = $pdo->prepare(
                'INSERT INTO car_imoveis
                    (cod_imovel, cod_ibge, municipio, uf, contorno, area_ha, min_lat, min_lng, max_lat, max_lng)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            );

            // Confirma em LOTES: município inteiro numa única transação geraria
            // um undo log gigante (risco de estouro/lentidão no MySQL do Railway).
            \App\Services\ShapefileService::streamImoveis($caminho, function (array $im) use ($pdo, $stmt, $municipioPadrao, $ufPadrao, &$deletados, &$porMunicipio, &$noLote) {
                $codIbge = ($im['cod_ibge'] ?? null) ?: null;
                // Nome do município: .dbf → tabela IBGE do Sul → digitado no
                // formulário → "IBGE {código}" (nunca descarta por falta de nome).
                $mun = ($im['municipio'] ?? null) ? mb_strtoupper(trim($im['municipio'])) : null;
                if (!$mun && $codIbge) {
                    $nome = \App\Services\MunicipiosSul::nome($codIbge);
                    if ($nome) {
                        $mun = mb_strtoupper($nome);
                    }
                }
                if (!$mun) {
                    $mun = $municipioPadrao;
                }
                if (!$mun && $codIbge) {
                    $mun = 'IBGE ' . $codIbge;
                }
                $uf = ($im['uf'] ?? null) ?: $ufPadrao;
                if (!$uf || !$mun) {
                    return; // sem UF e sem como classificar
                }
                $uf = strtoupper(substr($uf, 0, 2));

                // Dedup por CÓDIGO IBGE: importar município a município passa a
                // ACUMULAR (nunca apaga os já carregados, mesmo com nome repetido).
                // Sem código IBGE, cai no par município+UF (comportamento legado).
                $chaveDel = $codIbge ? ('i:' . $codIbge) : ('m:' . $mun . '|' . $uf);
                if (!isset($deletados[$chaveDel])) {
                    if ($codIbge) {
                        $pdo->prepare('DELETE FROM car_imoveis WHERE cod_ibge = ?')->execute([$codIbge]);
                        // purga linhas legadas do mesmo município (sem código IBGE)
                        $pdo->prepare('DELETE FROM car_imoveis WHERE cod_ibge IS NULL AND municipio = ? AND uf = ?')->execute([$mun, $uf]);
                    } else {
                        $pdo->prepare('DELETE FROM car_imoveis WHERE municipio = ? AND uf = ?')->execute([$mun, $uf]);
                    }
                    $deletados[$chaveDel] = true;
                }
                [$minLat, $minLng, $maxLat, $maxLng] = $im['bbox'];
                $stmt->execute([
                    mb_substr((string) ($im['cod'] ?? ''), 0, 80) ?: '(sem código)',
                    $codIbge,
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

    /** Raio (m) para aceitar o imóvel MAIS PRÓXIMO quando o ponto (ex.: sede
     *  aproximada) cai logo fora do polígono do CAR. */
    public const TOLERANCIA_PONTO_M = 250.0;
    /** Raio (m) para dizer que HÁ base do CAR na região (município importado). */
    private const RAIO_BASE_PERTO_M = 6000.0;
    /** Maior altura de bbox de um imóvel (graus). Usado para LIMITAR o range de
     *  min_lat na consulta por caixa — sem isso o índice varre metade da tabela
     *  (bbox overlap não é indexável em B-tree); com o limite inferior o índice
     *  (min_lat,...) fica seletivo. 0.5° (~55 km) cobre qualquer imóvel rural. */
    private const MAX_SPAN_GRAU = 0.5;

    /**
     * Imóvel do CAR no ponto [lat,lng]: primeiro o polígono que CONTÉM o ponto
     * (bbox + ray casting); se nenhum contém e $tolMetros > 0, o imóvel mais
     * PRÓXIMO dentro do raio (marcado 'aproximado' + 'dist_m') — cobre a sede
     * cadastrada de forma aproximada, que às vezes cai logo fora da divisa.
     * Retorna null se não há imóvel no ponto nem próximo o bastante.
     */
    public static function imovelNoPonto(float $lat, float $lng, float $tolMetros = 0.0): ?array
    {
        $candidatos = Database::todos(
            'SELECT cod_imovel, contorno, area_ha FROM car_imoveis
              WHERE min_lat >= ? AND min_lat <= ? AND max_lat >= ? AND min_lng <= ? AND max_lng >= ?',
            [$lat - self::MAX_SPAN_GRAU, $lat, $lat, $lng, $lng]
        );
        foreach ($candidatos as $c) {
            $pontos = json_decode((string) $c['contorno'], true);
            if (is_array($pontos) && self::dentro($lat, $lng, $pontos)) {
                return [
                    'cod' => $c['cod_imovel'],
                    'contorno' => $pontos,
                    'area_ha' => (float) $c['area_ha'],
                    'aproximado' => false,
                    'dist_m' => 0,
                ];
            }
        }
        if ($tolMetros <= 0) {
            return null;
        }
        // Tolerante: imóvel mais próximo dentro do raio (pré-filtro por bbox com folga)
        $grau = $tolMetros / 111000.0;
        $perto = Database::todos(
            'SELECT cod_imovel, contorno, area_ha FROM car_imoveis
              WHERE min_lat >= ? AND min_lat <= ? AND max_lat >= ? AND max_lng >= ? AND min_lng <= ?',
            [$lat - $grau - self::MAX_SPAN_GRAU, $lat + $grau, $lat - $grau, $lng - $grau, $lng + $grau]
        );
        $melhor = null;
        $melhorDist = INF;
        foreach ($perto as $c) {
            $pontos = json_decode((string) $c['contorno'], true);
            if (!is_array($pontos)) {
                continue;
            }
            $d = self::distanciaAoPoligono($lat, $lng, $pontos);
            if ($d < $melhorDist) {
                $melhorDist = $d;
                $melhor = ['cod' => $c['cod_imovel'], 'contorno' => $pontos, 'area_ha' => (float) $c['area_ha']];
            }
        }
        if ($melhor !== null && $melhorDist <= $tolMetros) {
            $melhor['aproximado'] = true;
            $melhor['dist_m'] = (int) round($melhorDist);
            return $melhor;
        }
        return null;
    }

    /**
     * Imóveis do CAR numa ÁREA (bbox por raio) para desenhar o overlay no croqui —
     * o usuário vê todos os imóveis e toca no que é do produtor. Devolve
     * [ ['cod','contorno'=>[[lat,lng],...],'area_ha'], ... ] (limitado).
     */
    public static function imoveisNaArea(float $lat, float $lng, float $raioMetros = 3000.0, int $limite = 500): array
    {
        $grau = $raioMetros / 111000.0;
        $rows = Database::todos(
            'SELECT cod_imovel, contorno, area_ha FROM car_imoveis
              WHERE min_lat >= ? AND min_lat <= ? AND max_lat >= ? AND max_lng >= ? AND min_lng <= ?
              LIMIT ' . max(1, (int) $limite),
            [$lat - $grau - self::MAX_SPAN_GRAU, $lat + $grau, $lat - $grau, $lng - $grau, $lng + $grau]
        );
        $out = [];
        foreach ($rows as $r) {
            $pts = json_decode((string) $r['contorno'], true);
            // aceita anel único [[lat,lng],...] ou multipolygon [[[lat,lng],...],...]
            if (is_array($pts) && self::contornoValido($pts)) {
                $out[] = ['cod' => $r['cod_imovel'], 'contorno' => $pts, 'area_ha' => (float) $r['area_ha']];
            }
        }
        return $out;
    }

    /** Há algum imóvel do CAR até ~6 km do ponto? (base do município importada na região). */
    public static function temBasePerto(float $lat, float $lng): bool
    {
        $grau = self::RAIO_BASE_PERTO_M / 111000.0;
        return (int) Database::valor(
            'SELECT COUNT(*) FROM car_imoveis
              WHERE min_lat >= ? AND min_lat <= ? AND max_lat >= ? AND max_lng >= ? AND min_lng <= ?',
            [$lat - $grau - self::MAX_SPAN_GRAU, $lat + $grau, $lat - $grau, $lng - $grau, $lng + $grau]
        ) > 0;
    }

    /**
     * Imóvel do CAR MAIS PRÓXIMO do ponto dentro do raio (para diagnóstico
     * quando não bate: mostra distância + município — se for longe/de outro
     * município, o município do imóvel provavelmente não foi importado).
     * Retorna ['dist_m','municipio','uf'] ou null se não há base no raio.
     */
    public static function maisProximo(float $lat, float $lng, float $raioMetros = 10000.0): ?array
    {
        $grau = $raioMetros / 111000.0;
        $cand = Database::todos(
            'SELECT municipio, uf, contorno FROM car_imoveis
              WHERE min_lat >= ? AND min_lat <= ? AND max_lat >= ? AND max_lng >= ? AND min_lng <= ?',
            [$lat - $grau - self::MAX_SPAN_GRAU, $lat + $grau, $lat - $grau, $lng - $grau, $lng + $grau]
        );
        $melhor = null;
        $melhorDist = INF;
        foreach ($cand as $c) {
            $pontos = json_decode((string) $c['contorno'], true);
            if (!is_array($pontos)) {
                continue;
            }
            $d = self::distanciaAoPoligono($lat, $lng, $pontos);
            if ($d < $melhorDist) {
                $melhorDist = $d;
                $melhor = $c;
            }
        }
        if ($melhor === null) {
            return null;
        }
        return ['dist_m' => (int) round($melhorDist), 'municipio' => $melhor['municipio'], 'uf' => $melhor['uf']];
    }

    /**
     * Normaliza o contorno em lista de anéis: aceita anel único [[lat,lng],...]
     * ou multipolygon [[[lat,lng],...],...] (imóvel com partes desconexas).
     */
    private static function aneis(array $contorno): array
    {
        if (!$contorno) {
            return [];
        }
        $p = $contorno[0] ?? null;
        // multipolygon: o 1º elemento é um anel (lista de pares), não um par [lat,lng]
        if (is_array($p) && isset($p[0]) && is_array($p[0])) {
            return $contorno;
        }
        return [$contorno];
    }

    /**
     * Maior anel (por área) de um contorno — a divisa de uma PROPRIEDADE é sempre
     * um único anel, então ao adotar um imóvel do CAR multipartes pega-se a maior
     * parte (o desenho/área/relatório do croqui esperam anel simples).
     */
    public static function maiorAnel(array $contorno): array
    {
        $aneis = self::aneis($contorno);
        if (count($aneis) <= 1) {
            return $aneis[0] ?? [];
        }
        $melhor = $aneis[0];
        $melhorArea = -1.0;
        foreach ($aneis as $a) {
            $area = CroquiService::areaHa($a);
            if ($area > $melhorArea) {
                $melhorArea = $area;
                $melhor = $a;
            }
        }
        return $melhor;
    }

    /** Contorno tem ao menos um anel com 3+ pontos? */
    private static function contornoValido(array $contorno): bool
    {
        foreach (self::aneis($contorno) as $anel) {
            if (is_array($anel) && count($anel) >= 3) {
                return true;
            }
        }
        return false;
    }

    /** Menor distância (m) do ponto ao imóvel: 0 se dentro de qualquer parte. */
    private static function distanciaAoPoligono(float $lat, float $lng, array $contorno): float
    {
        if (self::dentro($lat, $lng, $contorno)) {
            return 0.0;
        }
        $min = INF;
        foreach (self::aneis($contorno) as $anel) {
            $d = self::distanciaAoAnel($lat, $lng, $anel);
            if ($d < $min) {
                $min = $d;
            }
        }
        return $min;
    }

    /** Menor distância (m) do ponto às arestas de UM anel [[lat,lng],...]. */
    private static function distanciaAoAnel(float $lat, float $lng, array $pol): float
    {
        $mLat = 110574.0;
        $mLng = 111320.0 * cos(deg2rad($lat));
        $px = $lng * $mLng;
        $py = $lat * $mLat;
        $min = INF;
        $n = count($pol);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $ax = ((float) $pol[$j][1]) * $mLng;
            $ay = ((float) $pol[$j][0]) * $mLat;
            $bx = ((float) $pol[$i][1]) * $mLng;
            $by = ((float) $pol[$i][0]) * $mLat;
            $dx = $bx - $ax;
            $dy = $by - $ay;
            $len2 = $dx * $dx + $dy * $dy;
            $t = $len2 > 0 ? max(0.0, min(1.0, (($px - $ax) * $dx + ($py - $ay) * $dy) / $len2)) : 0.0;
            $d = hypot($px - ($ax + $t * $dx), $py - ($ay + $t * $dy));
            if ($d < $min) {
                $min = $d;
            }
        }
        return $min;
    }

    /** O ponto [lat,lng] está dentro do imóvel (qualquer parte do multipolygon)? */
    private static function dentro(float $lat, float $lng, array $contorno): bool
    {
        foreach (self::aneis($contorno) as $anel) {
            if (self::pontoNoAnel($lat, $lng, $anel)) {
                return true;
            }
        }
        return false;
    }

    /** Ray casting: o ponto [lat,lng] está dentro do anel [[lat,lng],...]? */
    private static function pontoNoAnel(float $lat, float $lng, array $poligono): bool
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

    /** Teto de imóveis na base offline (protege o aparelho: base estadual não cabe no celular). */
    public const MAX_SNAPSHOT = 25000;

    /**
     * Base do CAR para o snapshot offline, em FLUXO (streaming) — chama $cb(linha)
     * para cada imóvel sem carregar a tabela inteira na memória (query NÃO-bufferizada,
     * pico ~1 linha; sem isso o fetchAll estourava o memory_limit num município grande).
     *
     * $bbox = [minLat,minLng,maxLat,maxLng] limita à REGIÃO (caixa da carteira + margem):
     * com base estadual (SC inteiro, >1 mi de imóveis) baixar tudo travaria o aparelho,
     * então o offline cobre só a região onde o técnico atua. $limite é o teto de segurança.
     * Só o essencial: código, caixa e contorno.
     */
    public static function streamSnapshot(callable $cb, ?array $bbox = null, int $limite = self::MAX_SNAPSHOT): void
    {
        $sql = 'SELECT cod_imovel AS cod, contorno, min_lat, min_lng, max_lat, max_lng FROM car_imoveis';
        $params = [];
        if ($bbox !== null) {
            [$minLat, $minLng, $maxLat, $maxLng] = $bbox;
            // mesmo padrão indexável de imoveisNaArea (limite inferior de min_lat)
            $sql .= ' WHERE min_lat >= ? AND min_lat <= ? AND max_lat >= ? AND max_lng >= ? AND min_lng <= ?';
            $params = [$minLat - self::MAX_SPAN_GRAU, $maxLat, $minLat, $minLng, $maxLng];
        }
        $sql .= ' LIMIT ' . max(1, (int) $limite);

        $pdo = Database::conexao();
        $bufferAntes = null;
        // Query não-bufferizada: só o driver mysql tem essa flag; ignora se ausente.
        if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
            $bufferAntes = $pdo->getAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
            $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        }
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            while ($row = $stmt->fetch()) {
                $cb($row);
            }
            $stmt->closeCursor();
        } finally {
            if ($bufferAntes !== null) {
                $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $bufferAntes);
            }
        }
    }
}
