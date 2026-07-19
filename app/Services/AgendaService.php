<?php

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Permissoes;

/** Agenda de eventos (visitas, reuniões, tarefas, cobranças) e roteiro do dia. */
class AgendaService
{
    public const TIPOS = ['Visita', 'Reunião', 'Tarefa', 'Entrega', 'Cobrança', 'Outro'];

    /** Dias sem visita a partir dos quais a parada é considerada "visita vencida". */
    public const DIAS_VISITA_VENCIDA = 90;
    /** Teto de dias exibido (produtor nunca visitado usa este valor). */
    private const DIAS_TETO = 120;
    /** Velocidade média assumida em estrada rural (km/h) para estimar o tempo de viagem. */
    private const VELOCIDADE_KMH = 45.0;
    /** Duração média estimada de uma visita/parada (minutos). */
    private const MIN_POR_PARADA = 40;

    /** Eventos de um período. Gestor vê a equipe; campo vê os próprios. */
    public static function listar(?string $de = null, ?string $ate = null, int $usuarioFiltro = 0): array
    {
        $where = '1=1';
        $params = [];
        if (!Permissoes::ehGestor()) {
            $where .= ' AND e.usuario_id = ?';
            $params[] = Auth::id();
        } elseif ($usuarioFiltro > 0) {
            $where .= ' AND e.usuario_id = ?';
            $params[] = $usuarioFiltro;
        }
        if ($de) {
            $where .= ' AND e.data >= ?';
            $params[] = $de;
        }
        if ($ate) {
            $where .= ' AND e.data <= ?';
            $params[] = $ate;
        }
        return Database::todos(
            "SELECT e.*, c.nome AS cliente, c.telefone AS cliente_telefone, u.nome AS responsavel
               FROM agenda_eventos e
               LEFT JOIN clientes c ON c.id = e.cliente_id
               JOIN usuarios u ON u.id = e.usuario_id
              WHERE {$where}
              ORDER BY e.data, e.hora IS NULL, e.hora",
            $params
        );
    }

    public static function salvar(array $dados): int
    {
        $id = (int) ($dados['id'] ?? 0);
        $titulo = trim($dados['titulo'] ?? '');
        $data = $dados['data'] ?? '';
        if ($titulo === '' || $data === '') {
            throw new \InvalidArgumentException('Informe o título e a data do evento.');
        }
        $tipo = in_array($dados['tipo'] ?? '', self::TIPOS, true) ? $dados['tipo'] : 'Visita';
        $campos = [
            $tipo,
            $titulo,
            $data,
            trim($dados['hora'] ?? '') ?: null,
            (int) ($dados['cliente_id'] ?? 0) ?: null,
            trim($dados['descricao'] ?? '') ?: null,
        ];
        if ($id > 0) {
            // Só o dono do evento (ou gestor) edita
            $dono = (int) Database::valor('SELECT usuario_id FROM agenda_eventos WHERE id = ?', [$id]);
            if (!$dono || (!Permissoes::ehGestor() && $dono !== Auth::id())) {
                throw new \RuntimeException('Evento não encontrado.');
            }
            Database::executar(
                'UPDATE agenda_eventos SET tipo=?, titulo=?, data=?, hora=?, cliente_id=?, descricao=? WHERE id=?',
                array_merge($campos, [$id])
            );
        } else {
            Database::executar(
                'INSERT INTO agenda_eventos (tipo, titulo, data, hora, cliente_id, descricao, usuario_id) VALUES (?,?,?,?,?,?,?)',
                array_merge($campos, [Auth::id()])
            );
            $id = Database::ultimoId();
            // Notifica o próprio responsável do agendamento
            NotificacaoService::criar(Auth::id(), 'agenda', 'Evento agendado', $titulo . ' — ' . data_br($data), 'index.php?r=agenda');
        }
        return $id;
    }

    public static function mudarStatus(int $id, string $status): void
    {
        if (!in_array($status, ['Pendente', 'Concluído', 'Cancelado'], true)) {
            throw new \InvalidArgumentException('Status inválido.');
        }
        $dono = (int) Database::valor('SELECT usuario_id FROM agenda_eventos WHERE id = ?', [$id]);
        if (!$dono || (!Permissoes::ehGestor() && $dono !== Auth::id())) {
            throw new \RuntimeException('Evento não encontrado.');
        }
        Database::executar('UPDATE agenda_eventos SET status = ? WHERE id = ?', [$status, $id]);
    }

    /** Roteiro do dia ordenado (para o organizador de visitas). */
    public static function roteiro(string $data, int $usuarioId): array
    {
        $rows = Database::todos(
            "SELECT e.*, c.nome AS cliente, c.telefone AS cliente_telefone, c.municipio,
                    c.latitude, c.longitude,
                    (SELECT MAX(v.data_visita) FROM visitas v WHERE v.cliente_id = e.cliente_id) AS ultima_visita
               FROM agenda_eventos e
               LEFT JOIN clientes c ON c.id = e.cliente_id
              WHERE e.usuario_id = ? AND e.data = ? AND e.status <> 'Cancelado'
              ORDER BY e.ordem, e.hora IS NULL, e.hora",
            [$usuarioId, $data]
        );
        foreach ($rows as &$r) {
            if ($r['cliente_id']) {
                $dias = $r['ultima_visita']
                    ? (int) floor((time() - strtotime($r['ultima_visita'])) / 86400)
                    : self::DIAS_TETO; // nunca visitado
                $r['dias_sem_visita'] = $dias;
                $r['visita_vencida'] = $dias >= self::DIAS_VISITA_VENCIDA;
            } else {
                $r['dias_sem_visita'] = null;
                $r['visita_vencida'] = false;
            }
        }
        unset($r);
        return $rows;
    }

    /** Sugestões priorizadas de quem visitar num dia (exclui quem já está no roteiro). */
    public static function sugestoesVisita(string $data, int $usuarioId, int $limite = 12): array
    {
        [$filtro, $params] = Permissoes::filtroCarteira();
        $lista = PriorizacaoService::listaPriorizada($filtro, $params);
        // Clientes já no roteiro do dia
        $noRoteiro = Database::todos(
            "SELECT cliente_id FROM agenda_eventos WHERE usuario_id = ? AND data = ? AND cliente_id IS NOT NULL AND status <> 'Cancelado'",
            [$usuarioId, $data]
        );
        $ids = array_map(fn ($r) => (int) $r['cliente_id'], $noRoteiro);
        $lista = array_values(array_filter($lista, fn ($c) => !in_array((int) $c['id'], $ids, true)));
        $lista = array_slice($lista, 0, $limite);
        if (!$lista) {
            return [];
        }
        // Enriquece com telefone/coordenadas (não estão no PriorizacaoService)
        $idsLista = implode(',', array_map(fn ($c) => (int) $c['id'], $lista));
        $extra = Database::todos("SELECT id, telefone, latitude, longitude FROM clientes WHERE id IN ({$idsLista})");
        $mapa = [];
        foreach ($extra as $x) {
            $mapa[(int) $x['id']] = $x;
        }
        foreach ($lista as &$c) {
            $c['telefone'] = $mapa[(int) $c['id']]['telefone'] ?? null;
            $c['latitude'] = $mapa[(int) $c['id']]['latitude'] ?? null;
            $c['longitude'] = $mapa[(int) $c['id']]['longitude'] ?? null;
        }
        unset($c);
        return $lista;
    }

    /** Adiciona um produtor ao roteiro do dia como parada (evento de visita). */
    public static function adicionarAoRoteiro(int $clienteId, string $data): int
    {
        [$filtro, $params] = Permissoes::filtroCarteira();
        $cliente = Database::um(
            "SELECT c.nome FROM clientes c WHERE c.id = ? AND {$filtro}",
            array_merge([$clienteId], $params)
        );
        if (!$cliente) {
            throw new \RuntimeException('Produtor não encontrado na sua carteira.');
        }
        // Já está no roteiro do dia?
        $existe = Database::valor(
            "SELECT id FROM agenda_eventos WHERE usuario_id = ? AND cliente_id = ? AND data = ? AND status <> 'Cancelado'",
            [Auth::id(), $clienteId, $data]
        );
        if ($existe) {
            return (int) $existe;
        }
        $ordem = (int) Database::valor(
            'SELECT COALESCE(MAX(ordem),0)+1 FROM agenda_eventos WHERE usuario_id = ? AND data = ?',
            [Auth::id(), $data]
        );
        Database::executar(
            "INSERT INTO agenda_eventos (usuario_id, cliente_id, tipo, titulo, data, ordem, status)
             VALUES (?,?,'Visita',?,?,?,'Pendente')",
            [Auth::id(), $clienteId, 'Visita — ' . $cliente['nome'], $data, $ordem]
        );
        return Database::ultimoId();
    }

    public static function removerDoRoteiro(int $id): void
    {
        $dono = (int) Database::valor('SELECT usuario_id FROM agenda_eventos WHERE id = ?', [$id]);
        if (!$dono || (!Permissoes::ehGestor() && $dono !== Auth::id())) {
            throw new \RuntimeException('Parada não encontrada.');
        }
        Database::executar('DELETE FROM agenda_eventos WHERE id = ?', [$id]);
    }

    /** Move uma parada para cima/baixo no roteiro (troca a ordem com a vizinha). */
    public static function reordenar(int $id, string $direcao): void
    {
        $ev = Database::um('SELECT usuario_id, data, ordem FROM agenda_eventos WHERE id = ?', [$id]);
        if (!$ev || (!Permissoes::ehGestor() && (int) $ev['usuario_id'] !== Auth::id())) {
            throw new \RuntimeException('Parada não encontrada.');
        }
        $op = $direcao === 'cima' ? '<' : '>';
        $ord = $direcao === 'cima' ? 'DESC' : 'ASC';
        $vizinho = Database::um(
            "SELECT id, ordem FROM agenda_eventos
              WHERE usuario_id = ? AND data = ? AND status <> 'Cancelado' AND ordem {$op} ?
              ORDER BY ordem {$ord} LIMIT 1",
            [$ev['usuario_id'], $ev['data'], $ev['ordem']]
        );
        if (!$vizinho) {
            return; // já é a primeira/última
        }
        Database::executar('UPDATE agenda_eventos SET ordem = ? WHERE id = ?', [(int) $vizinho['ordem'], $id]);
        Database::executar('UPDATE agenda_eventos SET ordem = ? WHERE id = ?', [(int) $ev['ordem'], (int) $vizinho['id']]);
    }

    /**
     * Otimiza a rota do dia para a MENOR quilometragem total percorrida.
     * Heurística: vizinho-mais-próximo a partir de cada início (pega a melhor)
     * + refinamento 2-opt. Retorna [otimizadas, km_total].
     */
    public static function otimizarRota(string $data, int $usuarioId): array
    {
        $paradas = Database::todos(
            "SELECT e.id, c.latitude AS lat, c.longitude AS lng
               FROM agenda_eventos e LEFT JOIN clientes c ON c.id = e.cliente_id
              WHERE e.usuario_id = ? AND e.data = ? AND e.status <> 'Cancelado'
              ORDER BY e.ordem, e.id",
            [$usuarioId, $data]
        );
        $comGeo = array_values(array_filter($paradas, fn ($p) => $p['lat'] !== null && $p['lng'] !== null));
        $semGeo = array_values(array_filter($paradas, fn ($p) => $p['lat'] === null || $p['lng'] === null));
        $n = count($comGeo);
        if ($n < 2) {
            return ['otimizadas' => 0, 'km' => 0.0];
        }

        // Matriz de distâncias entre as paradas com coordenadas
        $d = [];
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                $d[$i][$j] = self::distancia(
                    (float) $comGeo[$i]['lat'], (float) $comGeo[$i]['lng'],
                    (float) $comGeo[$j]['lat'], (float) $comGeo[$j]['lng']
                );
            }
        }

        // Vizinho-mais-próximo a partir de cada início; guarda a rota de menor custo
        $melhorRota = range(0, $n - 1);
        $melhorCusto = self::custoRota($melhorRota, $d);
        for ($ini = 0; $ini < $n; $ini++) {
            $usado = array_fill(0, $n, false);
            $rota = [$ini];
            $usado[$ini] = true;
            $atual = $ini;
            for ($k = 1; $k < $n; $k++) {
                $best = -1;
                $bd = INF;
                for ($j = 0; $j < $n; $j++) {
                    if (!$usado[$j] && $d[$atual][$j] < $bd) {
                        $bd = $d[$atual][$j];
                        $best = $j;
                    }
                }
                $usado[$best] = true;
                $rota[] = $best;
                $atual = $best;
            }
            $custo = self::custoRota($rota, $d);
            if ($custo < $melhorCusto) {
                $melhorCusto = $custo;
                $melhorRota = $rota;
            }
        }

        // Refinamento 2-opt (reduz cruzamentos → menor distância)
        [$melhorRota, $melhorCusto] = self::doisOpt($melhorRota, $d);

        // Grava a nova ordem (paradas com coordenada primeiro; sem-coordenada ao final)
        $ordem = 1;
        foreach ($melhorRota as $idx) {
            Database::executar('UPDATE agenda_eventos SET ordem = ? WHERE id = ?', [$ordem++, (int) $comGeo[$idx]['id']]);
        }
        foreach ($semGeo as $p) {
            Database::executar('UPDATE agenda_eventos SET ordem = ? WHERE id = ?', [$ordem++, (int) $p['id']]);
        }
        $km = round($melhorCusto, 1);
        $minViagem = (int) round($km / self::VELOCIDADE_KMH * 60);
        $minTotal = $minViagem + count($paradas) * self::MIN_POR_PARADA;
        return ['otimizadas' => $n, 'km' => $km, 'min_total' => $minTotal];
    }

    /**
     * Estimativa do dia: distância total, tempo de viagem (pela velocidade média),
     * tempo de visitas (paradas × duração média) e tempo total, em minutos.
     */
    public static function estimativaDia(string $data, int $usuarioId): array
    {
        $km = self::distanciaRoteiro($data, $usuarioId);
        $paradas = (int) Database::valor(
            "SELECT COUNT(*) FROM agenda_eventos WHERE usuario_id = ? AND data = ? AND status <> 'Cancelado'",
            [$usuarioId, $data]
        );
        $minViagem = $km > 0 ? (int) round($km / self::VELOCIDADE_KMH * 60) : 0;
        $minVisitas = $paradas * self::MIN_POR_PARADA;
        return [
            'km' => $km,
            'paradas' => $paradas,
            'min_viagem' => $minViagem,
            'min_visitas' => $minVisitas,
            'min_total' => $minViagem + $minVisitas,
        ];
    }

    /** Distância total (km) de uma rota (caminho aberto: soma dos trechos consecutivos). */
    private static function custoRota(array $rota, array $d): float
    {
        $total = 0.0;
        for ($i = 0; $i < count($rota) - 1; $i++) {
            $total += $d[$rota[$i]][$rota[$i + 1]];
        }
        return $total;
    }

    /** Refinamento 2-opt para caminho aberto. */
    private static function doisOpt(array $rota, array $d): array
    {
        $n = count($rota);
        $melhorou = true;
        while ($melhorou) {
            $melhorou = false;
            $custoAtual = self::custoRota($rota, $d);
            for ($i = 0; $i < $n - 1; $i++) {
                for ($k = $i + 1; $k < $n; $k++) {
                    $nova = array_merge(
                        array_slice($rota, 0, $i),
                        array_reverse(array_slice($rota, $i, $k - $i + 1)),
                        array_slice($rota, $k + 1)
                    );
                    $c = self::custoRota($nova, $d);
                    if ($c < $custoAtual - 1e-9) {
                        $rota = $nova;
                        $custoAtual = $c;
                        $melhorou = true;
                    }
                }
            }
        }
        return [$rota, self::custoRota($rota, $d)];
    }

    /** Distância total (km) do roteiro na ordem atual. */
    public static function distanciaRoteiro(string $data, int $usuarioId): float
    {
        $paradas = Database::todos(
            "SELECT c.latitude AS lat, c.longitude AS lng
               FROM agenda_eventos e LEFT JOIN clientes c ON c.id = e.cliente_id
              WHERE e.usuario_id = ? AND e.data = ? AND e.status <> 'Cancelado'
                AND c.latitude IS NOT NULL AND c.longitude IS NOT NULL
              ORDER BY e.ordem, e.id",
            [$usuarioId, $data]
        );
        $total = 0.0;
        for ($i = 0; $i < count($paradas) - 1; $i++) {
            $total += self::distancia(
                (float) $paradas[$i]['lat'], (float) $paradas[$i]['lng'],
                (float) $paradas[$i + 1]['lat'], (float) $paradas[$i + 1]['lng']
            );
        }
        return round($total, 1);
    }

    /**
     * Busca produtores da carteira para encaixar no roteiro, por nome e/ou
     * município e/ou linha (localidade rural). Exclui quem já está no roteiro.
     */
    public static function buscarProdutorRoteiro(string $termo, string $data, int $usuarioId, string $municipio = '', string $linha = ''): array
    {
        $termo = trim($termo);
        $municipio = trim($municipio);
        $linha = trim($linha);
        // Precisa de pelo menos um critério (nome com 2+ letras, ou um filtro de local)
        if (mb_strlen($termo) < 2 && $municipio === '' && $linha === '') {
            return [];
        }
        [$filtro, $params] = Permissoes::filtroCarteira();
        $where = "c.ativo = 1 AND {$filtro}";
        if (mb_strlen($termo) >= 2) {
            $where .= ' AND c.nome LIKE ?';
            $params[] = '%' . $termo . '%';
        }
        if ($municipio !== '') {
            $where .= ' AND c.municipio = ?';
            $params[] = $municipio;
        }
        if ($linha !== '') {
            $where .= ' AND c.linha = ?';
            $params[] = $linha;
        }
        $where .= " AND c.id NOT IN (
                       SELECT cliente_id FROM agenda_eventos
                        WHERE usuario_id = ? AND data = ? AND cliente_id IS NOT NULL AND status <> 'Cancelado')";
        $params[] = $usuarioId;
        $params[] = $data;
        return Database::todos(
            "SELECT c.id, c.nome, c.municipio, c.linha, c.telefone
               FROM clientes c WHERE {$where} ORDER BY c.nome LIMIT 30",
            $params
        );
    }

    /** Municípios e linhas distintos da carteira (para os filtros do organizador). */
    public static function locaisCarteira(): array
    {
        [$filtro, $params] = Permissoes::filtroCarteira();
        $pares = Database::todos(
            "SELECT DISTINCT municipio, linha FROM clientes c
              WHERE c.ativo = 1 AND {$filtro} AND municipio IS NOT NULL AND municipio <> ''
              ORDER BY municipio, linha",
            $params
        );
        $municipios = [];
        $linhas = [];
        $porMunicipio = []; // município => [linhas]
        foreach ($pares as $p) {
            $mun = $p['municipio'];
            if (!in_array($mun, $municipios, true)) {
                $municipios[] = $mun;
            }
            if (!empty($p['linha'])) {
                if (!in_array($p['linha'], $linhas, true)) {
                    $linhas[] = $p['linha'];
                }
                $porMunicipio[$mun][] = $p['linha'];
            }
        }
        sort($linhas);
        return ['municipios' => $municipios, 'linhas' => $linhas, 'porMunicipio' => $porMunicipio];
    }

    /** Distância aproximada (Haversine, km). */
    private static function distancia(float $la1, float $lo1, float $la2, float $lo2): float
    {
        $r = 6371;
        $dLa = deg2rad($la2 - $la1);
        $dLo = deg2rad($lo2 - $lo1);
        $a = sin($dLa / 2) ** 2 + cos(deg2rad($la1)) * cos(deg2rad($la2)) * sin($dLo / 2) ** 2;
        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public static function indicadores(int $usuarioId): array
    {
        $hoje = date('Y-m-d');
        return [
            'hoje' => (int) Database::valor(
                "SELECT COUNT(*) FROM agenda_eventos WHERE usuario_id = ? AND data = ? AND status = 'Pendente'",
                [$usuarioId, $hoje]
            ),
            'atrasados' => (int) Database::valor(
                "SELECT COUNT(*) FROM agenda_eventos WHERE usuario_id = ? AND data < ? AND status = 'Pendente'",
                [$usuarioId, $hoje]
            ),
        ];
    }
}
