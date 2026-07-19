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

    /** Roteiro do dia ordenado por horário (para o organizador de visitas). */
    public static function roteiro(string $data, int $usuarioId): array
    {
        return Database::todos(
            "SELECT e.*, c.nome AS cliente, c.telefone AS cliente_telefone, c.municipio,
                    c.latitude, c.longitude
               FROM agenda_eventos e
               LEFT JOIN clientes c ON c.id = e.cliente_id
              WHERE e.usuario_id = ? AND e.data = ? AND e.status <> 'Cancelado'
              ORDER BY e.hora IS NULL, e.hora",
            [$usuarioId, $data]
        );
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
