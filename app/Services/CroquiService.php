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

    /**
     * Tolerância (m) para considerar um ponto "dentro" da divisa mesmo
     * ligeiramente fora — absorve o erro natural do GPS ao caminhar a divisa.
     */
    public const TOLERANCIA_DIVISA_M = 15;

    /**
     * REGRA DE NEGÓCIO: o talhão deve ficar DENTRO da divisa da propriedade.
     * Devolve os índices dos pontos que caem fora (vazio = contido).
     */
    public static function pontosFora(array $pontos, array $divisa, float $tolM = self::TOLERANCIA_DIVISA_M): array
    {
        if (count($divisa) < 3) {
            return [];
        }
        // Projeta tudo na mesma referência métrica (centro da divisa)
        $lat0 = array_sum(array_column($divisa, 0)) / count($divisa);
        $mLat = 110574.0;
        $mLng = 111320.0 * cos(deg2rad($lat0));
        $proj = fn ($p) => [$p[1] * $mLng, -$p[0] * $mLat];
        $poligono = array_map($proj, $divisa);

        $fora = [];
        foreach ($pontos as $i => $p) {
            $xy = $proj($p);
            if (!self::dentro($xy, $poligono) && self::distanciaBordaM($xy, $poligono) > $tolM) {
                $fora[] = $i;
            }
        }
        return $fora;
    }

    /**
     * Prende na divisa: pontos fora da propriedade são puxados para o ponto
     * mais próximo da borda (em vez de recusar o desenho). Devolve os pontos
     * corrigidos [lat,lng].
     */
    public static function prenderNaDivisa(array $pontos, array $divisa): array
    {
        if (count($divisa) < 3) {
            return $pontos;
        }
        $lat0 = array_sum(array_column($divisa, 0)) / count($divisa);
        $mLat = 110574.0;
        $mLng = 111320.0 * cos(deg2rad($lat0));
        $proj = fn ($p) => [$p[1] * $mLng, -$p[0] * $mLat];
        $poligono = array_map($proj, $divisa);

        foreach ($pontos as $i => $p) {
            $xy = $proj($p);
            // Dentro e longe da borda: fica. Fora OU "colado" na borda (≤ SNAP_DIVISA_M):
            // vai para a borda exata — o talhão margeia a divisa tal como foi desenhada.
            if (self::dentro($xy, $poligono) && self::distanciaBordaM($xy, $poligono) > self::SNAP_DIVISA_M) {
                continue;
            }
            [$bx, $by] = self::pontoMaisProximoBorda($xy, $poligono);
            $pontos[$i] = [round(-$by / $mLat, 7), round($bx / $mLng, 7)];
        }
        return $pontos;
    }

    /**
     * Distância (m) para um ponto ser considerado "na divisa": é encaixado na
     * borda exata e o talhão passa a MARGEAR a divisa (pedido do teste de campo).
     */
    public const SNAP_DIVISA_M = 6;

    /**
     * MARGEAR A DIVISA (pedido do teste de campo): toda LINHA do desenho que sai
     * do limite é corrigida sozinha — o trecho que fica fora é substituído pelo
     * próprio caminho da borda. Cobre os dois casos do campo:
     *  - dois pontos seguidos na borda com a reta saindo do CAR (a divisa faz
     *    curva/quina entre eles): os vértices da divisa entram no desenho;
     *  - ponto no meio do imóvel com a reta cortando uma reentrância do CAR
     *    (antes só avisava "linha fora" e travava o salvar): a reta entra na
     *    borda onde sai, segue a borda e volta onde reentra.
     * Reta que segue por dentro (corte de um lado ao outro) não muda. Idempotente:
     * rodar de novo não insere nada. Devolve os pontos [lat,lng].
     */
    public static function margearDivisa(array $pontos, array $divisa, float $tolM = self::SNAP_DIVISA_M): array
    {
        $n = count($pontos);
        if ($n < 2 || count($divisa) < 3) {
            return $pontos;
        }
        $lat0 = array_sum(array_column($divisa, 0)) / count($divisa);
        $mLat = 110574.0;
        $mLng = 111320.0 * cos(deg2rad($lat0));
        $proj = fn ($p) => [$p[1] * $mLng, -$p[0] * $mLat];
        $desproj = fn ($xy) => [round(-$xy[1] / $mLat, 7), round($xy[0] / $mLng, 7)];
        $poligono = array_map($proj, $divisa);
        $xy = array_map($proj, $pontos);
        $arestas = $n >= 3 ? $n : $n - 1;
        $saida = [];
        for ($i = 0; $i < $arestas; $i++) {
            $saida[] = $pontos[$i];
            if (!self::linhasFora([$pontos[$i], $pontos[($i + 1) % $n]], $divisa)) {
                continue; // a reta já fica dentro: não muda
            }
            foreach (self::caminhoDentro($poligono, $xy[$i], $xy[($i + 1) % $n], $tolM) as $v) {
                $saida[] = $desproj($v);
            }
        }
        if ($n === 2) {
            $saida[] = $pontos[1];
        }
        return $saida;
    }

    /**
     * Pontos a inserir entre A e B (metros) para o caminho ficar DENTRO do
     * polígono: corta a reta AB em cada cruzamento com a borda (mais as pontas
     * que já estão na borda) e cada trecho cujo meio cai fora vira o caminho da
     * borda entre a saída e a reentrada. Trecho por dentro fica reto. Espelho de
     * Croqui._caminhoDentro (app.js).
     */
    private static function caminhoDentro(array $poligono, array $a, array $b, float $tolM): array
    {
        $n = count($poligono);
        $cortes = [];
        $pa = self::posicaoNaBorda($a, $poligono);
        $pb = self::posicaoNaBorda($b, $poligono);
        if ($pa['dist'] <= $tolM) {
            $cortes[] = ['t' => 0.0, 'pos' => $pa];
        }
        if ($pb['dist'] <= $tolM) {
            $cortes[] = ['t' => 1.0, 'pos' => $pb];
        }
        $orient = fn ($o, $q, $r) => ($q[0] - $o[0]) * ($r[1] - $o[1]) - ($q[1] - $o[1]) * ($r[0] - $o[0]);
        for ($j = 0; $j < $n; $j++) {
            $q1 = $poligono[$j];
            $q2 = $poligono[($j + 1) % $n];
            $d1 = $orient($q1, $q2, $a);
            $d2 = $orient($q1, $q2, $b);
            $d3 = $orient($a, $b, $q1);
            $d4 = $orient($a, $b, $q2);
            if (!(($d1 > 0) !== ($d2 > 0)) || !(($d3 > 0) !== ($d4 > 0))) {
                continue; // não cruzam (ou só encostam/colineares)
            }
            $cortes[] = ['t' => $d1 / ($d1 - $d2), 'pos' => ['i' => $j, 't' => $d3 / ($d3 - $d4), 'dist' => 0.0]];
        }
        if (count($cortes) < 2) {
            return [];
        }
        usort($cortes, fn ($x, $y) => $x['t'] <=> $y['t']);
        $compr = hypot($b[0] - $a[0], $b[1] - $a[1]);
        $ponto = fn (float $t) => [$a[0] + $t * ($b[0] - $a[0]), $a[1] + $t * ($b[1] - $a[1])];
        $saida = [];
        for ($k = 0; $k < count($cortes) - 1; $k++) {
            $c1 = $cortes[$k];
            $c2 = $cortes[$k + 1];
            if (($c2['t'] - $c1['t']) * $compr < 0.5) {
                continue; // cruzamentos praticamente no mesmo lugar (vértice da borda)
            }
            $meio = $ponto(($c1['t'] + $c2['t']) / 2);
            if (self::dentro($meio, $poligono) || self::distanciaBordaM($meio, $poligono) <= $tolM) {
                continue; // trecho por dentro (ou colado na borda): reto
            }
            // trecho por fora: entra na borda em c1, segue a borda, sai em c2
            if ($c1['t'] > 0) {
                $saida[] = self::pontoNaAresta($poligono, $c1['pos']);
            }
            foreach (self::caminhoBorda($poligono, $c1['pos'], $c2['pos']) as $v) {
                $saida[] = $v;
            }
            if ($c2['t'] < 1) {
                $saida[] = self::pontoNaAresta($poligono, $c2['pos']);
            }
        }
        return $saida;
    }

    /** Aresta da borda mais próxima do ponto (índice, parâmetro 0..1 e distância em m). */
    private static function posicaoNaBorda(array $p, array $poligono): array
    {
        $melhor = ['i' => 0, 't' => 0.0, 'dist' => INF];
        $n = count($poligono);
        for ($i = 0; $i < $n; $i++) {
            [$ax, $ay] = $poligono[$i];
            [$bx, $by] = $poligono[($i + 1) % $n];
            $abx = $bx - $ax; $aby = $by - $ay;
            $len2 = $abx * $abx + $aby * $aby;
            $t = $len2 > 0 ? max(0, min(1, (($p[0] - $ax) * $abx + ($p[1] - $ay) * $aby) / $len2)) : 0;
            $d = hypot($p[0] - ($ax + $t * $abx), $p[1] - ($ay + $t * $aby));
            if ($d < $melhor['dist']) {
                $melhor = ['i' => $i, 't' => $t, 'dist' => $d];
            }
        }
        return $melhor;
    }

    /**
     * Vértices da borda entre duas posições na divisa, no sentido MAIS CURTO
     * (a divisa é um anel: há dois caminhos). Não inclui os dois extremos.
     * Posição contínua no anel: s = aresta + parâmetro (0 ≤ s < n).
     */
    private static function caminhoBorda(array $poligono, array $pa, array $pb): array
    {
        $n = count($poligono);
        $sa = $pa['i'] + $pa['t'];
        $sb = $pb['i'] + $pb['t'];
        // frente (índices crescentes): vértices floor(sa)+1, ... até antes de sb
        $frente = [];
        $k = (int) floor($sa) + 1;
        $arco = fmod($sb - $sa + $n, $n);
        $passo = $k - $sa;
        while ($passo < $arco - 1e-9 && count($frente) < $n) {
            $frente[] = $poligono[$k % $n];
            $k++; $passo += 1;
        }
        // trás (índices decrescentes): vértices ceil(sa)-1, ... até antes de sb
        $tras = [];
        $k = (int) ceil($sa) - 1;
        $arco = fmod($sa - $sb + $n, $n);
        $passo = $sa - $k;
        while ($passo < $arco - 1e-9 && count($tras) < $n) {
            $tras[] = $poligono[(($k % $n) + $n) % $n];
            $k--; $passo += 1;
        }
        $ptA = self::pontoNaAresta($poligono, $pa);
        $ptB = self::pontoNaAresta($poligono, $pb);
        $compr = function (array $vs) use ($ptA, $ptB): float {
            $l = 0; $ant = $ptA;
            foreach (array_merge($vs, [$ptB]) as $v) { $l += hypot($v[0] - $ant[0], $v[1] - $ant[1]); $ant = $v; }
            return $l;
        };
        return $compr($frente) <= $compr($tras) ? $frente : $tras;
    }

    /** Ponto [x,y] correspondente a uma posição (aresta i, parâmetro t) da borda. */
    private static function pontoNaAresta(array $poligono, array $pos): array
    {
        $n = count($poligono);
        [$ax, $ay] = $poligono[$pos['i']];
        [$bx, $by] = $poligono[($pos['i'] + 1) % $n];
        return [$ax + $pos['t'] * ($bx - $ax), $ay + $pos['t'] * ($by - $ay)];
    }

    /**
     * Tolerância (m) da regra "talhão não cobre outro talhão": vértice a menos
     * disso da borda do vizinho não conta como sobreposição (erro do GPS/toque;
     * talhões lado a lado dividem a mesma linha).
     */
    public const TOLERANCIA_SOBREPOSICAO_M = 3;

    /**
     * REGRA (teste de campo): talhões NÃO se sobrepõem. Índices dos pontos de
     * $pontos que caem DENTRO de $outro (além da tolerância da borda).
     */
    public static function pontosDentroDe(array $pontos, array $outro, float $tolM = self::TOLERANCIA_SOBREPOSICAO_M): array
    {
        if (count($outro) < 3) {
            return [];
        }
        $proj = self::projetar(array_merge($outro, $pontos));
        $poligono = array_slice($proj, 0, count($outro));
        $xy = array_slice($proj, count($outro));
        $dentro = [];
        foreach ($xy as $i => $p) {
            if (self::dentro($p, $poligono) && self::distanciaBordaM($p, $poligono) > $tolM) {
                $dentro[] = $i;
            }
        }
        return $dentro;
    }

    /**
     * Expulsa de $outro: ponto que caiu dentro do talhão vizinho é puxado para a
     * borda mais próxima dele — desenhar "colado" no vizinho fica automático,
     * sem sobrepor. Devolve os pontos corrigidos [lat,lng].
     */
    public static function expulsarDe(array $pontos, array $outro): array
    {
        if (count($outro) < 3) {
            return $pontos;
        }
        $lat0 = array_sum(array_column($outro, 0)) / count($outro);
        $mLat = 110574.0;
        $mLng = 111320.0 * cos(deg2rad($lat0));
        $proj = fn ($p) => [$p[1] * $mLng, -$p[0] * $mLat];
        $poligono = array_map($proj, $outro);
        foreach ($pontos as $i => $p) {
            $xy = $proj($p);
            if (!self::dentro($xy, $poligono)) {
                continue;
            }
            [$bx, $by] = self::pontoMaisProximoBorda($xy, $poligono);
            $pontos[$i] = [round(-$by / $mLat, 7), round($bx / $mLng, 7)];
        }
        return $pontos;
    }

    /**
     * Dois polígonos se sobrepõem? Vértice de um dentro do outro (além da
     * tolerância) ou arestas que se CRUZAM de verdade. Vizinhos que só dividem
     * uma linha (aresta em comum, vértice encostado) NÃO contam.
     */
    public static function sobrepoe(array $a, array $b, float $tolM = self::TOLERANCIA_SOBREPOSICAO_M): bool
    {
        if (count($a) < 3 || count($b) < 3) {
            return false;
        }
        if (self::pontosDentroDe($a, $b, $tolM) || self::pontosDentroDe($b, $a, $tolM)) {
            return true;
        }
        $proj = self::projetar(array_merge($a, $b));
        $pa = array_slice($proj, 0, count($a));
        $pb = array_slice($proj, count($a));
        $na = count($pa);
        $nb = count($pb);
        for ($i = 0; $i < $na; $i++) {
            $a1 = $pa[$i];
            $a2 = $pa[($i + 1) % $na];
            for ($j = 0; $j < $nb; $j++) {
                if (self::segmentosCruzam($a1, $a2, $pb[$j], $pb[($j + 1) % $nb], $tolM)) {
                    return true;
                }
            }
        }
        // Polígonos IGUAIS (ou um dentro do outro com todos os vértices na borda):
        // nenhum vértice "dentro" e nenhuma aresta cruza — só um ponto do INTERIOR
        // de um denuncia que está dentro do outro (bug do piloto: o mesmo talhão
        // salvo 7 vezes passava como "vizinhos que dividem a linha").
        $ia = self::pontoInterior($pa, $tolM);
        if ($ia !== null && self::dentro($ia, $pb) && self::distanciaBordaM($ia, $pb) > $tolM) {
            return true;
        }
        $ib = self::pontoInterior($pb, $tolM);
        return $ib !== null && self::dentro($ib, $pa) && self::distanciaBordaM($ib, $pa) > $tolM;
    }

    /**
     * Mesmo desenho: todo vértice de um está a ≤ $tolM da borda do outro, nos dois
     * sentidos. Usado para dizer "este talhão já está cadastrado" em vez de
     * "cobre outro talhão".
     */
    public static function mesmoContorno(array $a, array $b, float $tolM = self::TOLERANCIA_SOBREPOSICAO_M): bool
    {
        if (count($a) < 3 || count($b) < 3) {
            return false;
        }
        $proj = self::projetar(array_merge($a, $b));
        $pa = array_slice($proj, 0, count($a));
        $pb = array_slice($proj, count($a));
        foreach ($pa as $p) {
            if (self::distanciaBordaM($p, $pb) > $tolM) {
                return false;
            }
        }
        foreach ($pb as $p) {
            if (self::distanciaBordaM($p, $pa) > $tolM) {
                return false;
            }
        }
        return true;
    }

    /**
     * Um ponto garantidamente no INTERIOR do polígono (em metros), longe da borda
     * mais que $tolM: centroide; senão o meio entre o centroide e cada vértice;
     * senão o meio de cada diagonal curta (i, i+2). Null se nada servir (polígono
     * degenerado/fino).
     */
    private static function pontoInterior(array $poligono, float $tolM): ?array
    {
        $n = count($poligono);
        $cx = array_sum(array_column($poligono, 0)) / $n;
        $cy = array_sum(array_column($poligono, 1)) / $n;
        $candidatos = [[$cx, $cy]];
        foreach ($poligono as $p) {
            $candidatos[] = [($cx + $p[0]) / 2, ($cy + $p[1]) / 2];
        }
        for ($i = 0; $i < $n; $i++) {
            $q = $poligono[($i + 2) % $n];
            $candidatos[] = [($poligono[$i][0] + $q[0]) / 2, ($poligono[$i][1] + $q[1]) / 2];
        }
        foreach ($candidatos as $c) {
            if (self::dentro($c, $poligono) && self::distanciaBordaM($c, $poligono) > $tolM) {
                return $c;
            }
        }
        return null;
    }

    /** Cruzamento PRÓPRIO de dois segmentos (metros): interseção estritamente no meio dos dois. */
    private static function segmentosCruzam(array $p1, array $p2, array $q1, array $q2, float $tolM): bool
    {
        $orient = fn ($a, $b, $c) => ($b[0] - $a[0]) * ($c[1] - $a[1]) - ($b[1] - $a[1]) * ($c[0] - $a[0]);
        $d1 = $orient($q1, $q2, $p1);
        $d2 = $orient($q1, $q2, $p2);
        $d3 = $orient($p1, $p2, $q1);
        $d4 = $orient($p1, $p2, $q2);
        if (!(($d1 > 0) !== ($d2 > 0)) || !(($d3 > 0) !== ($d4 > 0))) {
            return false; // não cruzam (ou só encostam/colineares)
        }
        // Cruzam: só conta se a interseção está longe das pontas (senão é vértice encostado na linha)
        $t = $d1 / ($d1 - $d2);
        $x = $p1[0] + $t * ($p2[0] - $p1[0]);
        $y = $p1[1] + $t * ($p2[1] - $p1[1]);
        foreach ([$p1, $p2, $q1, $q2] as $ponta) {
            if (hypot($x - $ponta[0], $y - $ponta[1]) <= $tolM) {
                return false;
            }
        }
        return true;
    }

    /**
     * REGRA (teste de campo): NENHUMA LINHA fica fora da divisa do CAR. Prender os
     * pontos na borda não basta em divisa côncava — a aresta entre dois pontos
     * válidos pode cortar por fora. Devolve os índices i das arestas (i → i+1) que
     * cruzam a divisa ou passam por fora dela.
     */
    public static function linhasFora(array $pontos, array $divisa, float $tolM = self::TOLERANCIA_SOBREPOSICAO_M): array
    {
        $n = count($pontos);
        if ($n < 2 || count($divisa) < 3) {
            return [];
        }
        $proj = self::projetar(array_merge($divisa, $pontos));
        $poligono = array_slice($proj, 0, count($divisa));
        $xy = array_slice($proj, count($divisa));
        $nd = count($poligono); // vértices da divisa (faltava: warning "Undefined variable" saía
                                // antes do JSON em produção → "Resposta inválida do servidor")
        $fora = [];
        for ($i = 0; $i < $n; $i++) {
            $a = $xy[$i];
            $b = $xy[($i + 1) % $n];
            if ($n === 2 && $i === 1) {
                break; // duas pontas: só uma linha
            }
            $cruza = false;
            for ($j = 0; $j < $nd && !$cruza; $j++) {
                $cruza = self::segmentosCruzam($a, $b, $poligono[$j], $poligono[($j + 1) % $nd], $tolM);
            }
            if (!$cruza) {
                // sem cruzamento: a linha está toda dentro ou toda fora — decide pelo meio
                $meio = [($a[0] + $b[0]) / 2, ($a[1] + $b[1]) / 2];
                $cruza = !self::dentro($meio, $poligono) && self::distanciaBordaM($meio, $poligono) > $tolM;
            }
            if ($cruza) {
                $fora[] = $i;
            }
        }
        return $fora;
    }

    /** Ponto da borda do polígono mais próximo de [x,y] (tudo em metros). */
    private static function pontoMaisProximoBorda(array $p, array $poligono): array
    {
        $melhor = $poligono[0];
        $menor = INF;
        $n = count($poligono);
        for ($i = 0; $i < $n; $i++) {
            [$ax, $ay] = $poligono[$i];
            [$bx, $by] = $poligono[($i + 1) % $n];
            $abx = $bx - $ax; $aby = $by - $ay;
            $len2 = $abx * $abx + $aby * $aby;
            $t = $len2 > 0 ? max(0, min(1, (($p[0] - $ax) * $abx + ($p[1] - $ay) * $aby) / $len2)) : 0;
            $cx = $ax + $t * $abx; $cy = $ay + $t * $aby;
            $d = ($p[0] - $cx) ** 2 + ($p[1] - $cy) ** 2;
            if ($d < $menor) {
                $menor = $d;
                $melhor = [$cx, $cy];
            }
        }
        return $melhor;
    }

    /** Ray casting: ponto [x,y] dentro do polígono [[x,y],...]. */
    private static function dentro(array $p, array $poligono): bool
    {
        $dentro = false;
        $n = count($poligono);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$xi, $yi] = $poligono[$i];
            [$xj, $yj] = $poligono[$j];
            if ((($yi > $p[1]) !== ($yj > $p[1]))
                && $p[0] < ($xj - $xi) * ($p[1] - $yi) / (($yj - $yi) ?: 1e-12) + $xi) {
                $dentro = !$dentro;
            }
        }
        return $dentro;
    }

    /** Menor distância (m) do ponto às bordas do polígono (ambos já em metros). */
    private static function distanciaBordaM(array $p, array $poligono): float
    {
        $menor = INF;
        $n = count($poligono);
        for ($i = 0; $i < $n; $i++) {
            [$ax, $ay] = $poligono[$i];
            [$bx, $by] = $poligono[($i + 1) % $n];
            $abx = $bx - $ax; $aby = $by - $ay;
            $len2 = $abx * $abx + $aby * $aby;
            $t = $len2 > 0 ? max(0, min(1, (($p[0] - $ax) * $abx + ($p[1] - $ay) * $aby) / $len2)) : 0;
            $dx = $p[0] - ($ax + $t * $abx);
            $dy = $p[1] - ($ay + $t * $aby);
            $menor = min($menor, sqrt($dx * $dx + $dy * $dy));
        }
        return $menor;
    }

    /**
     * Distância (m) de um ponto [lat,lng] até a área de um polígono [[lat,lng],...]:
     * 0 quando o ponto está dentro; senão a menor distância até a borda.
     * Usada na auditoria de campo (visita lançada fora da propriedade).
     */
    public static function distanciaAteAreaM(array $ponto, array $poligono): float
    {
        if (count($poligono) < 3) {
            return INF;
        }
        $proj = self::projetar(array_merge([$ponto], $poligono));
        $p = array_shift($proj);
        return self::dentro($p, $proj) ? 0.0 : self::distanciaBordaM($p, $proj);
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
        // Áreas de plantio do imóvel (v44: várias — Campo, Morro...): verde tracejado,
        // entre a divisa e os talhões. Legado: contorno_plantio único.
        $plantios = [];
        if ($propriedade && !empty($propriedade['areas_plantio']) && is_array($propriedade['areas_plantio'])) {
            foreach ($propriedade['areas_plantio'] as $a) {
                $d = json_decode((string) ($a['contorno'] ?? ''), true);
                if (is_array($d) && count($d) >= 3) {
                    $plantios[] = ['nome' => (string) ($a['nome'] ?? ''), 'pontos' => $d];
                }
            }
        } elseif ($propriedade && !empty($propriedade['contorno_plantio'])) {
            $d = json_decode((string) $propriedade['contorno_plantio'], true);
            if (is_array($d) && count($d) >= 3) {
                $plantios[] = ['nome' => '', 'pontos' => $d];
            }
        }
        $plantio = $plantios ? true : null;
        if (!$comContorno && !$divisa && !$plantio) {
            return '';
        }
        // Junta todos os pontos para calcular o enquadramento comum
        $todos = $divisa ?: [];
        foreach ($plantios as $pl) {
            $todos = array_merge($todos, $pl['pontos']);
        }
        $poligonos = [];
        foreach ($comContorno as $t) {
            $pontos = json_decode((string) $t['contorno'], true);
            if (!is_array($pontos) || count($pontos) < 3) {
                continue;
            }
            $poligonos[] = ['talhao' => $t, 'pontos' => $pontos];
            $todos = array_merge($todos, $pontos);
        }
        if (!$poligonos && !$divisa && !$plantio) {
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
        // Áreas de plantio (v44): o que dá para plantar dentro da divisa, com o nome
        foreach ($plantios as $pl) {
            $telaPl = array_map($paraTela, $pl['pontos']);
            $svg .= '<polygon points="' . implode(' ', array_map(fn ($p) => $p[0] . ',' . $p[1], $telaPl)) . '"'
                . ' fill="#7cb342" fill-opacity=".10" stroke="#558b2f" stroke-width="2" stroke-dasharray="4 4"/>';
            if ($pl['nome'] !== '' && count($plantios) > 1) {
                $cx = round(array_sum(array_column($telaPl, 0)) / count($telaPl), 1);
                $cy = round(array_sum(array_column($telaPl, 1)) / count($telaPl), 1);
                $svg .= '<text x="' . $cx . '" y="' . ($cy - 12) . '" text-anchor="middle" font-size="8" fill="#558b2f">'
                    . e($pl['nome']) . '</text>';
            }
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
