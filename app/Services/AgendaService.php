<?php

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Permissoes;

/** Agenda de eventos (visitas, reuniões, tarefas, cobranças) e roteiro do dia. */
class AgendaService
{
    public const TIPOS = ['Visita', 'Reunião', 'Tarefa', 'Entrega', 'Cobrança', 'Outro'];

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
        return Database::todos(
            "SELECT e.*, c.nome AS cliente, c.telefone AS cliente_telefone, c.municipio,
                    c.latitude, c.longitude
               FROM agenda_eventos e
               LEFT JOIN clientes c ON c.id = e.cliente_id
              WHERE e.usuario_id = ? AND e.data = ? AND e.status <> 'Cancelado'
              ORDER BY e.ordem, e.hora IS NULL, e.hora",
            [$usuarioId, $data]
        );
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

    /** Otimiza a rota do dia por proximidade (vizinho mais próximo a partir da 1ª parada). */
    public static function otimizarRota(string $data, int $usuarioId): int
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
        if (count($comGeo) < 2) {
            return 0; // nada a otimizar
        }
        // Vizinho mais próximo, começando pela primeira parada atual
        $ordenados = [];
        $restantes = $comGeo;
        $atual = array_shift($restantes);
        $ordenados[] = $atual;
        while ($restantes) {
            $melhor = null;
            $melhorDist = INF;
            foreach ($restantes as $i => $p) {
                $d = self::distancia((float) $atual['lat'], (float) $atual['lng'], (float) $p['lat'], (float) $p['lng']);
                if ($d < $melhorDist) {
                    $melhorDist = $d;
                    $melhor = $i;
                }
            }
            $atual = $restantes[$melhor];
            $ordenados[] = $atual;
            array_splice($restantes, $melhor, 1);
        }
        // Grava a nova ordem (geo primeiro, sem-coordenada ao final)
        $ordem = 1;
        foreach (array_merge($ordenados, $semGeo) as $p) {
            Database::executar('UPDATE agenda_eventos SET ordem = ? WHERE id = ?', [$ordem++, (int) $p['id']]);
        }
        return count($comGeo);
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
