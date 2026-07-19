<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Permissoes;
use App\Services\DespesaService;
use App\Services\PrestacaoService;

/**
 * Módulo de Despesas: Quilometragem, Refeições e Prestação de Contas.
 * Cada usuário lança as próprias despesas; gestores veem e avaliam a equipe.
 */
class DespesasController
{
    public function index(): void
    {
        Permissoes::exigirInterno();
        $ehGestor = Permissoes::ehGestor();

        // Escopo: gestor pode filtrar por usuário; campo vê só o próprio
        $filtroUsuario = (int) ($_GET['usuario_id'] ?? 0);
        $escopo = $ehGestor ? $filtroUsuario : Auth::id();
        $mes = $_GET['mes'] ?? date('Y-m');

        $km = DespesaService::listarKm($escopo, $mes);
        $refeicoes = DespesaService::listarRefeicoes($escopo, $mes);
        $prestacoes = PrestacaoService::listar($ehGestor ? $filtroUsuario : Auth::id());
        $categoria = DespesaService::categoriaUsuario(Auth::id());

        // Prévia do mês corrente do próprio usuário (para o card de gerar prestação)
        [$anoAtual, $mesAtual] = array_map('intval', explode('-', date('Y-m')));
        $previa = PrestacaoService::previa(Auth::id(), $anoAtual, $mesAtual);

        $clientes = $this->clientesCarteira();
        $veiculos = DespesaService::veiculosUsuario(Auth::id());
        $filiais = Database::todos('SELECT id, nome, municipio, estado FROM filiais ORDER BY nome');
        $equipe = $ehGestor
            ? Database::todos("SELECT id, nome FROM usuarios WHERE ativo = 1 AND perfil <> 'Produtor' ORDER BY nome")
            : [];

        render('despesas', [
            'km' => $km,
            'refeicoes' => $refeicoes,
            'prestacoes' => $prestacoes,
            'categoria' => $categoria,
            'previa' => $previa,
            'clientes' => $clientes,
            'veiculos' => $veiculos,
            'filiais' => $filiais,
            'equipe' => $equipe,
            'ehGestor' => $ehGestor,
            'filtroUsuario' => $filtroUsuario,
            'mes' => $mes,
            'titulo' => 'Despesas',
        ]);
    }

    /** Cadastra um veículo do usuário (usado no lançamento de KM). */
    public function salvarVeiculo(): void
    {
        Permissoes::exigirInterno();
        try {
            $v = DespesaService::criarVeiculo(Auth::id(), $_POST['descricao'] ?? '', $_POST['placa'] ?? '');
        } catch (\InvalidArgumentException $e) {
            json_erro($e->getMessage());
        }
        json_ok(['veiculo' => $v]);
    }

    /** Retorna a KM final do último lançamento (para pré-preencher a KM inicial). */
    public function ultimoKm(): void
    {
        Permissoes::exigirInterno();
        $km = DespesaService::ultimoKmFinal(Auth::id(), (int) ($_GET['veiculo_id'] ?? 0));
        json_ok(['km' => $km]);
    }

    public function salvarKm(): void
    {
        Permissoes::exigirInterno();
        try {
            $r = DespesaService::registrarKm(Auth::id(), $_POST);
        } catch (\InvalidArgumentException $e) {
            json_erro($e->getMessage());
        }
        json_ok($r);
    }

    public function salvarRefeicao(): void
    {
        Permissoes::exigirInterno();
        try {
            $r = DespesaService::registrarRefeicao(Auth::id(), $_POST);
        } catch (\InvalidArgumentException $e) {
            json_erro($e->getMessage());
        }
        json_ok($r);
    }

    public function excluirKm(): void
    {
        Permissoes::exigirInterno();
        try {
            DespesaService::excluirKm((int) ($_POST['id'] ?? 0), Auth::id(), Permissoes::ehGestor());
        } catch (\RuntimeException $e) {
            json_erro($e->getMessage());
        }
        json_ok();
    }

    public function excluirRefeicao(): void
    {
        Permissoes::exigirInterno();
        try {
            DespesaService::excluirRefeicao((int) ($_POST['id'] ?? 0), Auth::id(), Permissoes::ehGestor());
        } catch (\RuntimeException $e) {
            json_erro($e->getMessage());
        }
        json_ok();
    }

    public function gerarPrestacao(): void
    {
        Permissoes::exigirInterno();
        $ano = (int) ($_POST['ano'] ?? date('Y'));
        $mes = (int) ($_POST['mes'] ?? date('n'));
        try {
            $id = PrestacaoService::gerar(Auth::id(), $ano, $mes);
        } catch (\Exception $e) {
            json_erro($e->getMessage());
        }
        json_ok(['id' => $id]);
    }

    public function enviarPrestacao(): void
    {
        Permissoes::exigirInterno();
        try {
            PrestacaoService::enviar((int) ($_POST['id'] ?? 0), Auth::id());
        } catch (\RuntimeException $e) {
            json_erro($e->getMessage());
        }
        json_ok();
    }

    public function avaliarPrestacao(): void
    {
        Permissoes::exigir(['Administrador', 'Gestor Comercial', 'Gestor Técnico']);
        $aprovar = ($_POST['decisao'] ?? '') === 'aprovar';
        try {
            PrestacaoService::avaliar(
                (int) ($_POST['id'] ?? 0),
                Auth::id(),
                $aprovar,
                $_POST['parecer'] ?? ''
            );
        } catch (\RuntimeException $e) {
            json_erro($e->getMessage());
        }
        json_ok();
    }

    public function detalhePrestacao(): void
    {
        Permissoes::exigirInterno();
        $id = (int) ($_GET['id'] ?? 0);
        try {
            $dados = PrestacaoService::detalhe($id);
        } catch (\RuntimeException $e) {
            json_erro($e->getMessage(), 404);
        }
        // Campo só vê a própria prestação
        if (!Permissoes::ehGestor() && (int) $dados['prestacao']['usuario_id'] !== Auth::id()) {
            json_erro('Prestação não encontrada na sua conta.', 403);
        }
        render_parcial('partials/prestacao_detalhe', $dados);
    }

    /** Clientes da carteira do usuário (para vincular a despesa a uma visita/cliente). */
    private function clientesCarteira(): array
    {
        [$filtro, $params] = Permissoes::filtroCarteira();
        return Database::todos(
            "SELECT c.id, c.nome FROM clientes c WHERE c.ativo = 1 AND {$filtro} ORDER BY c.nome",
            $params
        );
    }
}
