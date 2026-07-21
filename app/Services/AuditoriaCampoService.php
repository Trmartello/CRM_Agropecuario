<?php

namespace App\Services;

use App\Core\Database;

/**
 * Auditoria de campo (antifraude de visitas): confere se o lançamento da
 * visita aconteceu na propriedade cadastrada do produtor, usando o GPS
 * capturado em segundo plano no "Iniciar Visita" (fallback: GPS do salvar).
 *
 * Referência de local, na ordem: contorno da propriedade desenhado no croqui
 * (distância 0 = dentro) → sede da propriedade → coordenadas do cliente.
 * Sem GPS ou sem referência, a visita fica "não avaliada" (NULL) — o
 * relatório mostra esses casos separadamente.
 */
class AuditoriaCampoService
{
    /** Tolerância quando a referência é o CONTORNO do croqui (GPS + margem). */
    public const LIMITE_CONTORNO_M = 300;

    /** Tolerância quando a referência é só um PONTO (sede/cliente): a
     *  lavoura pode ficar longe da sede — calibrar no piloto. */
    public const LIMITE_PONTO_M = 2000;

    /**
     * Avalia o local do lançamento. Retorna:
     * ['dist' => metros até a propriedade (0 = dentro) ou null,
     *  'fora' => 1 fora / 0 confere / null não avaliável].
     */
    public static function avaliarLocal(?int $propriedadeId, int $clienteId, ?float $lat, ?float $lng): array
    {
        if ($lat === null || $lng === null) {
            return ['dist' => null, 'fora' => null];
        }
        $ponto = [$lat, $lng];

        // 1) Contorno da propriedade (croqui) — a referência mais precisa
        $prop = $propriedadeId
            ? Database::um('SELECT contorno, latitude, longitude FROM propriedades WHERE id = ?', [$propriedadeId])
            : null;
        $contorno = [];
        if ($prop && !empty($prop['contorno'])) {
            $decodificado = json_decode((string) $prop['contorno'], true);
            if (is_array($decodificado) && count($decodificado) >= 3) {
                $contorno = $decodificado;
            }
        }
        if ($contorno) {
            $dist = (int) round(CroquiService::distanciaAteAreaM($ponto, $contorno));
            return ['dist' => $dist, 'fora' => $dist > self::LIMITE_CONTORNO_M ? 1 : 0];
        }

        // 2) Sede da propriedade → 3) coordenadas do cliente
        $ref = null;
        if ($prop && $prop['latitude'] !== null && $prop['longitude'] !== null) {
            $ref = [(float) $prop['latitude'], (float) $prop['longitude']];
        } else {
            $cli = Database::um('SELECT latitude, longitude FROM clientes WHERE id = ?', [$clienteId]);
            if ($cli && $cli['latitude'] !== null && $cli['longitude'] !== null) {
                $ref = [(float) $cli['latitude'], (float) $cli['longitude']];
            }
        }
        if ($ref === null) {
            return ['dist' => null, 'fora' => null];
        }
        $dist = (int) round(self::distanciaM($ponto, $ref));
        return ['dist' => $dist, 'fora' => $dist > self::LIMITE_PONTO_M ? 1 : 0];
    }

    /** Distância equiretangular em metros entre dois [lat,lng]. */
    public static function distanciaM(array $a, array $b): float
    {
        $mLat = 110574.0;
        $mLng = 111320.0 * cos(deg2rad(($a[0] + $b[0]) / 2));
        return hypot(($a[0] - $b[0]) * $mLat, ($a[1] - $b[1]) * $mLng);
    }

    /** Resumo por responsável no período (datas YYYY-MM-DD, inclusive). */
    public static function resumo(string $inicio, string $fim): array
    {
        return Database::todos(
            "SELECT u.id, u.nome, u.perfil,
                    COUNT(v.id) AS visitas,
                    SUM(v.fora_propriedade = 1) AS fora,
                    SUM(v.fora_propriedade IS NULL) AS sem_avaliacao,
                    SUM(v.hora_inicio IS NULL) AS sem_inicio
               FROM usuarios u
               JOIN visitas v ON v.usuario_id = u.id AND v.data_visita BETWEEN ? AND ?
              GROUP BY u.id, u.nome, u.perfil
              ORDER BY fora DESC, visitas DESC",
            [$inicio, $fim]
        );
    }

    /** Visitas lançadas fora da propriedade no período (detalhe do relatório). */
    public static function visitasFora(string $inicio, string $fim): array
    {
        return Database::todos(
            "SELECT v.id, v.data_visita, v.hora_inicio, v.hora_fim, v.dist_propriedade_m,
                    v.inicio_lat, v.inicio_lng, v.latitude, v.longitude,
                    u.nome AS responsavel, c.nome AS cliente, p.nome AS propriedade
               FROM visitas v
               JOIN usuarios u ON u.id = v.usuario_id
               JOIN clientes c ON c.id = v.cliente_id
               LEFT JOIN propriedades p ON p.id = v.propriedade_id
              WHERE v.fora_propriedade = 1 AND v.data_visita BETWEEN ? AND ?
              ORDER BY v.data_visita DESC, v.id DESC",
            [$inicio, $fim]
        );
    }
}
