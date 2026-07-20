<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\Permissoes;

/** Trilha de auditoria — quem fez o quê e quando (Gestão). */
class AuditoriaController
{
    public function index(): void
    {
        Permissoes::exigir(['Administrador', 'Gestor Comercial', 'Gestor Técnico']);

        $usuarioId = (int) ($_GET['usuario_id'] ?? 0);
        $entidade = trim($_GET['entidade'] ?? '');
        $de = $_GET['de'] ?? date('Y-m-d', strtotime('-7 days'));
        $ate = $_GET['ate'] ?? date('Y-m-d');
        foreach (['de', 'ate'] as $v) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $$v)) {
                $$v = date('Y-m-d');
            }
        }

        $where = 'a.criado_em >= ? AND a.criado_em < DATE_ADD(?, INTERVAL 1 DAY)';
        $params = [$de, $ate];
        if ($usuarioId > 0) {
            $where .= ' AND a.usuario_id = ?';
            $params[] = $usuarioId;
        }
        if ($entidade !== '') {
            $where .= ' AND a.tabela = ?';
            $params[] = $entidade;
        }

        $registros = Database::todos(
            "SELECT a.*, u.nome AS usuario
               FROM auditoria a LEFT JOIN usuarios u ON u.id = a.usuario_id
              WHERE {$where}
              ORDER BY a.id DESC
              LIMIT 300",
            $params
        );
        $usuarios = Database::todos("SELECT id, nome FROM usuarios ORDER BY nome");
        $entidades = Database::todos('SELECT DISTINCT tabela FROM auditoria ORDER BY tabela');

        render('auditoria', [
            'registros' => $registros,
            'usuarios' => $usuarios,
            'entidades' => array_column($entidades, 'tabela'),
            'filtros' => ['usuario_id' => $usuarioId, 'entidade' => $entidade, 'de' => $de, 'ate' => $ate],
            'titulo' => 'Auditoria',
        ]);
    }
}
