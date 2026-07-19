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

        $clientes = $this->clientesCarteira(false);
        $prospectos = $this->clientesCarteira(true);
        $veiculos = DespesaService::veiculosUsuario(Auth::id());
        $filiais = Database::todos('SELECT id, nome, municipio, estado FROM filiais ORDER BY nome');
        $municipios = Database::todos('SELECT id, nome, estado FROM municipios WHERE ativo = 1 ORDER BY nome');
        $equipe = $ehGestor
            ? Database::todos("SELECT id, nome FROM usuarios WHERE ativo = 1 AND perfil <> 'Produtor' ORDER BY nome")
            : [];

        render('despesas', [
            'km' => $km,
            'refeicoes' => $refeicoes,
            'prestacoes' => $prestacoes,
            'categoria' => $categoria,
            'valoresRefeicao' => DespesaService::valoresRefeicaoUsuario(Auth::id()),
            'tiposRefeicao' => DespesaService::TIPOS_REFEICAO,
            'previa' => $previa,
            'clientes' => $clientes,
            'prospectos' => $prospectos,
            'veiculos' => $veiculos,
            'filiais' => $filiais,
            'municipios' => $municipios,
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
        $dados = $_POST;
        $dados['comprovante'] = $this->salvarComprovante();
        try {
            $r = DespesaService::registrarRefeicao(Auth::id(), $dados);
        } catch (\InvalidArgumentException $e) {
            json_erro($e->getMessage());
        }
        json_ok($r);
    }

    /** Salva a foto/arquivo do comprovante da refeição e retorna o nome do arquivo. */
    private function salvarComprovante(): ?string
    {
        if (empty($_FILES['comprovante']['name'] ?? null) || !is_uploaded_file($_FILES['comprovante']['tmp_name'] ?? '')) {
            return null;
        }
        $ext = strtolower(pathinfo($_FILES['comprovante']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'heic', 'pdf'], true)) {
            return null;
        }
        $dir = dirname(__DIR__, 2) . '/public/uploads/comprovantes';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $arquivo = sprintf('ref_%d_%s.%s', Auth::id(), bin2hex(random_bytes(6)), $ext);
        return move_uploaded_file($_FILES['comprovante']['tmp_name'], $dir . '/' . $arquivo) ? 'comprovantes/' . $arquivo : null;
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

    /** Clientes da carteira do usuário (para vincular a despesa a um produtor). */
    private function clientesCarteira(bool $apenasProspecto = false): array
    {
        [$filtro, $params] = Permissoes::filtroCarteira();
        $params[] = $apenasProspecto ? 1 : 0;
        return Database::todos(
            "SELECT c.id, c.nome FROM clientes c WHERE c.ativo = 1 AND {$filtro} AND c.prospecto = ? ORDER BY c.nome",
            $params
        );
    }
}
