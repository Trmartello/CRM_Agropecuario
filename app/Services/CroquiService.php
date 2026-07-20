<?php

namespace App\Services;

/**
 * Croqui das propriedades (Fase 6A): contorno dos talhões marcado no campo
 * (caminhando a divisa com GPS ou por toques no mapa). Sem tiles externos —
 * o desenho é vetorial, por coordenadas, e o servidor SEMPRE recalcula a
 * área (nunca confia no valor vindo do navegador).
 */
class CroquiService
{
    public const MAX_PONTOS = 800;
    /** Paleta dos talhões no croqui (mesma família da timeline fenológica). */
    public const CORES = ['#2e7d32', '#c05e11', '#00695c', '#9a7d0a', '#5d4037', '#455a64'];

    /**
     * Valida o JSON do contorno e devolve os pontos [[lat,lng],...].
     * @throws \InvalidArgumentException
     */
    public static function validarContorno(string $json): array
    {
        $pontos = json_decode($json, true);
        if (!is_array($pontos) || count($pontos) < 3) {
            throw new \InvalidArgumentException('O contorno precisa de pelo menos 3 pontos.');
        }
        if (count($pontos) > self::MAX_PONTOS) {
            throw new \InvalidArgumentException('Contorno com pontos demais (máx. ' . self::MAX_PONTOS . ').');
        }
        $limpos = [];
        foreach ($pontos as $p) {
            if (!is_array($p) || count($p) < 2 || !is_numeric($p[0]) || !is_numeric($p[1])) {
                throw new \InvalidArgumentException('Ponto inválido no contorno.');
            }
            $lat = (float) $p[0];
            $lng = (float) $p[1];
            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                throw new \InvalidArgumentException('Coordenada fora do intervalo válido.');
            }
            $limpos[] = [round($lat, 7), round($lng, 7)];
        }
        return $limpos;
    }

    /** Projeção local equiretangular: [lat,lng] → metros [x,y] em torno do centro. */
    public static function projetar(array $pontos): array
    {
        $lat0 = array_sum(array_column($pontos, 0)) / count($pontos);
        $mLat = 110574.0;                               // metros por grau de latitude
        $mLng = 111320.0 * cos(deg2rad($lat0));          // metros por grau de longitude
        return array_map(fn ($p) => [($p[1]) * $mLng, -($p[0]) * $mLat], $pontos);
    }

    /** Área do polígono em hectares (shoelace sobre a projeção local). */
    public static function areaHa(array $pontos): float
    {
        if (count($pontos) < 3) {
            return 0.0;
        }
        $xy = self::projetar($pontos);
        $n = count($xy);
        $soma = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $j = ($i + 1) % $n;
            $soma += $xy[$i][0] * $xy[$j][1] - $xy[$j][0] * $xy[$i][1];
        }
        return round(abs($soma) / 2 / 10000, 2);
    }

    /**
     * SVG estático do croqui de uma propriedade (ficha/impressão): polígonos
     * coloridos com rótulo (nome + ha) + barra de escala. $talhoes precisa de
     * nome, contorno (JSON) e area_gps/area_ha.
     */
    public static function svg(array $talhoes, int $largura = 340, int $altura = 240, ?array $propriedade = null): string
    {
        $comContorno = array_values(array_filter($talhoes, fn ($t) => !empty($t['contorno'])));
        $divisa = null;
        if ($propriedade && !empty($propriedade['contorno'])) {
            $d = json_decode((string) $propriedade['contorno'], true);
            if (is_array($d) && count($d) >= 3) {
                $divisa = $d;
            }
        }
        if (!$comContorno && !$divisa) {
            return '';
        }
        // Junta todos os pontos para calcular o enquadramento comum
        $todos = $divisa ?: [];
        $poligonos = [];
        foreach ($comContorno as $t) {
            $pontos = json_decode((string) $t['contorno'], true);
            if (!is_array($pontos) || count($pontos) < 3) {
                continue;
            }
            $poligonos[] = ['talhao' => $t, 'pontos' => $pontos];
            $todos = array_merge($todos, $pontos);
        }
        if (!$poligonos && !$divisa) {
            return '';
        }
        $xy = self::projetar($todos);
        $xs = array_column($xy, 0);
        $ys = array_column($xy, 1);
        $minX = min($xs); $maxX = max($xs);
        $minY = min($ys); $maxY = max($ys);
        $spanX = max(1.0, $maxX - $minX);
        $spanY = max(1.0, $maxY - $minY);
        $margem = 16;
        $esc = min(($largura - 2 * $margem) / $spanX, ($altura - 2 * $margem - 14) / $spanY);
        $paraTela = function (array $p) use ($todos, $minX, $minY, $esc, $margem): array {
            // projeta o ponto na mesma referência do conjunto
            static $lat0 = null, $mLng = null;
            if ($lat0 === null) {
                $lat0 = array_sum(array_column($todos, 0)) / count($todos);
                $mLng = 111320.0 * cos(deg2rad($lat0));
            }
            $x = $p[1] * $mLng;
            $y = -$p[0] * 110574.0;
            return [round(($x - $minX) * $esc + $margem, 1), round(($y - $minY) * $esc + $margem, 1)];
        };

        $svg = '';
        // Divisa da propriedade (área total) por baixo dos talhões
        if ($divisa) {
            $telaDiv = array_map($paraTela, $divisa);
            $svg .= '<polygon points="' . implode(' ', array_map(fn ($p) => $p[0] . ',' . $p[1], $telaDiv)) . '"'
                . ' fill="#8d6e2f" fill-opacity=".07" stroke="#8d6e2f" stroke-width="2.5" stroke-dasharray="8 5"/>';
        }
        foreach ($poligonos as $i => $pol) {
            $cor = self::CORES[$i % count(self::CORES)];
            $tela = array_map($paraTela, $pol['pontos']);
            $pts = implode(' ', array_map(fn ($p) => $p[0] . ',' . $p[1], $tela));
            $cx = round(array_sum(array_column($tela, 0)) / count($tela), 1);
            $cy = round(array_sum(array_column($tela, 1)) / count($tela), 1);
            $areaTxt = $pol['talhao']['area_gps'] ?? $pol['talhao']['area_ha'] ?? null;
            $svg .= '<polygon points="' . $pts . '" fill="' . $cor . '" fill-opacity=".28" stroke="' . $cor . '" stroke-width="2"/>';
            $svg .= '<text x="' . $cx . '" y="' . $cy . '" text-anchor="middle" font-size="10" font-weight="700" fill="#243b24">'
                . e($pol['talhao']['nome']) . '</text>';
            if ($areaTxt !== null) {
                $svg .= '<text x="' . $cx . '" y="' . ($cy + 11) . '" text-anchor="middle" font-size="9" fill="#4a5d4a">'
                    . numero((float) $areaTxt, 1) . ' ha</text>';
            }
        }
        // Barra de escala (~1/4 da largura em metros, arredondada p/ valor "redondo")
        $alvoM = $spanX * 0.25;
        $passo = pow(10, floor(log10(max(1, $alvoM))));
        $escalaM = $passo * max(1, floor($alvoM / $passo));
        $escalaPx = $escalaM * $esc;
        $svg .= '<line x1="' . $margem . '" y1="' . ($altura - 8) . '" x2="' . round($margem + $escalaPx, 1) . '" y2="' . ($altura - 8) . '" stroke="#333" stroke-width="2"/>';
        $svg .= '<text x="' . round($margem + $escalaPx / 2, 1) . '" y="' . ($altura - 12) . '" text-anchor="middle" font-size="8" fill="#333">'
            . ($escalaM >= 1000 ? round($escalaM / 1000, 1) . ' km' : round($escalaM) . ' m') . '</text>';
        // Rosa dos ventos (norte para cima)
        $svg .= '<g transform="translate(' . ($largura - 16) . ',20)"><path d="M0,-10 L4,4 L0,1 L-4,4 Z" fill="#333"/>'
            . '<text y="-13" text-anchor="middle" font-size="8" fill="#333">N</text></g>';

        return '<svg viewBox="0 0 ' . $largura . ' ' . $altura . '" xmlns="http://www.w3.org/2000/svg" '
            . 'style="width:100%;max-width:' . $largura . 'px;height:auto" role="img" aria-label="Croqui da propriedade">'
            . '<rect width="' . $largura . '" height="' . $altura . '" rx="10" fill="#f4f9f4" stroke="#dbe7db"/>' . $svg . '</svg>';
    }
}
