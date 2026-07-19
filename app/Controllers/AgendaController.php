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
        sync_iniciar($_POST['uuid_offline'] ?? null);
        try {
            $id = AgendaService::salvar($_POST);
        } catch (\InvalidArgumentException $e) {
            json_erro($e->getMessage());
        } catch (\RuntimeException $e) {
            json_erro($e->getMessage(), 404);
        }
        sync_confirmar($_POST['uuid_offline'] ?? null);
        json_ok(['id' => $id]);
    }

    public function status(): void
    {
        // Enfileirável offline, mas dispensa dedup por uuid: mudarStatus é um UPDATE
        // naturalmente idempotente (reaplicar o mesmo status não causa efeito colateral).
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
        $data = $this->dataValida();
        $eventos = AgendaService::roteiro($data, Auth::id());
        render_parcial('partials/roteiro', ['eventos' => $eventos, 'data' => $data]);
    }

    /** Organizador de visitas: monta o roteiro do dia a partir das sugestões priorizadas. */
    public function organizador(): void
    {
        Permissoes::exigirInterno();
        $data = $this->dataValida();
        $locais = AgendaService::locaisCarteira();
        render('organizador', [
            'data' => $data,
            'roteiro' => AgendaService::roteiro($data, Auth::id()),
            'sugestoes' => AgendaService::sugestoesVisita($data, Auth::id()),
            'kmRoteiro' => AgendaService::distanciaRoteiro($data, Auth::id()),
            'estimativa' => AgendaService::estimativaDia($data, Auth::id()),
            'municipios' => $locais['municipios'],
            'linhas' => $locais['linhas'],
            'linhasPorMunicipio' => $locais['porMunicipio'],
            'titulo' => 'Organizador de Visitas',
        ]);
    }

    /** Busca produtores da carteira por nome/município/linha (para encaixar no roteiro). */
    public function buscarProdutor(): void
    {
        Permissoes::exigirInterno();
        $resultados = AgendaService::buscarProdutorRoteiro(
            $_GET['q'] ?? '',
            $this->dataValida(),
            Auth::id(),
            $_GET['municipio'] ?? '',
            $_GET['linha'] ?? ''
        );
        json_ok(['resultados' => $resultados]);
    }

    public function roteiroAdicionar(): void
    {
        Permissoes::exigirInterno();
        try {
            $id = AgendaService::adicionarAoRoteiro((int) ($_POST['cliente_id'] ?? 0), $this->dataValida($_POST['data'] ?? null));
        } catch (\RuntimeException $e) {
            json_erro($e->getMessage(), 404);
        }
        json_ok(['id' => $id]);
    }

    public function roteiroRemover(): void
    {
        Permissoes::exigirInterno();
        try {
            AgendaService::removerDoRoteiro((int) ($_POST['id'] ?? 0));
        } catch (\RuntimeException $e) {
            json_erro($e->getMessage(), 404);
        }
        json_ok();
    }

    public function roteiroReordenar(): void
    {
        Permissoes::exigirInterno();
        try {
            AgendaService::reordenar((int) ($_POST['id'] ?? 0), ($_POST['direcao'] ?? '') === 'cima' ? 'cima' : 'baixo');
        } catch (\RuntimeException $e) {
            json_erro($e->getMessage(), 404);
        }
        json_ok();
    }

    public function roteiroOtimizar(): void
    {
        Permissoes::exigirInterno();
        $r = AgendaService::otimizarRota($this->dataValida($_POST['data'] ?? null), Auth::id());
        json_ok($r);
    }

    private function dataValida(?string $data = null): string
    {
        $data = $data ?? ($_GET['data'] ?? date('Y-m-d'));
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) ? $data : date('Y-m-d');
    }
}
