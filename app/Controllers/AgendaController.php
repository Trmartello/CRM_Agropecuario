<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Permissoes;
use App\Services\AgendaService;

class AgendaController
{
    public function index(): void
    {
        Permissoes::exigirInterno();
        $mesRef = $_GET['mes'] ?? date('Y-m');
        if (!preg_match('/^\d{4}-\d{2}$/', $mesRef)) {
            $mesRef = date('Y-m');
        }
        $de = $mesRef . '-01';
        $ate = date('Y-m-t', strtotime($de));
        $usuarioFiltro = Permissoes::ehGestor() ? (int) ($_GET['usuario_id'] ?? 0) : 0;

        $eventos = AgendaService::listar($de, $ate, $usuarioFiltro);
        $roteiroHoje = AgendaService::roteiro(date('Y-m-d'), Auth::id());

        [$fc, $fp] = Permissoes::filtroCarteira();
        $clientes = Database::todos(
            "SELECT c.id, c.nome, c.telefone FROM clientes c WHERE c.ativo = 1 AND {$fc} ORDER BY c.nome",
            $fp
        );
        $equipe = Permissoes::ehGestor()
            ? Database::todos("SELECT id, nome FROM usuarios WHERE ativo = 1 AND perfil <> 'Produtor' ORDER BY nome")
            : [];

        render('agenda', [
            'eventos' => $eventos,
            'roteiroHoje' => $roteiroHoje,
            'clientes' => $clientes,
            'equipe' => $equipe,
            'tipos' => AgendaService::TIPOS,
            'mesRef' => $mesRef,
            'usuarioFiltro' => $usuarioFiltro,
            'ehGestor' => Permissoes::ehGestor(),
            'titulo' => 'Agenda',
        ]);
    }

    public function salvar(): void
    {
        Permissoes::exigirInterno();
        try {
            $id = AgendaService::salvar($_POST);
        } catch (\InvalidArgumentException $e) {
            json_erro($e->getMessage());
        } catch (\RuntimeException $e) {
            json_erro($e->getMessage(), 404);
        }
        json_ok(['id' => $id]);
    }

    public function status(): void
    {
        Permissoes::exigirInterno();
        try {
            AgendaService::mudarStatus((int) ($_POST['id'] ?? 0), $_POST['status'] ?? '');
        } catch (\Exception $e) {
            json_erro($e->getMessage());
        }
        json_ok();
    }

    /** Roteiro do dia (organizador de visitas) — HTML parcial para impressão/visualização. */
    public function roteiro(): void
    {
        Permissoes::exigirInterno();
        $data = $_GET['data'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
            $data = date('Y-m-d');
        }
        $eventos = AgendaService::roteiro($data, Auth::id());
        render_parcial('partials/roteiro', ['eventos' => $eventos, 'data' => $data]);
    }
}
