<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\Permissoes;
use App\Services\ReclamacaoService;

class ReclamacoesController
{
    /** Perfis que analisam/movimentam o laudo. */
    private const GESTAO = ['Administrador', 'Gestor Comercial', 'Gestor Técnico'];

    public function index(): void
    {
        Permissoes::exigirInterno();
        [$filtro, $params] = Permissoes::filtroCarteira();
        $status = $_GET['status'] ?? null;

        $reclamacoes = ReclamacaoService::listar($filtro, $params, $status);
        [$fc, $fp] = Permissoes::filtroCarteira();
        $clientes = Database::todos(
            "SELECT c.id, c.nome FROM clientes c WHERE c.ativo = 1 AND {$fc} ORDER BY c.nome",
            $fp
        );
        $produtos = Database::todos('SELECT id, nome FROM produtos ORDER BY nome');
        $culturas = Database::todos('SELECT * FROM culturas ORDER BY nome');

        render('reclamacoes', [
            'reclamacoes' => $reclamacoes,
            'clientes' => $clientes,
            'produtos' => $produtos,
            'culturas' => $culturas,
            'tipos' => ReclamacaoService::TIPOS,
            'statusLista' => ReclamacaoService::STATUS,
            'filtroStatus' => $status,
            'podeGerir' => in_array(\App\Core\Auth::perfil(), self::GESTAO, true),
            'titulo' => 'Reclamações',
        ]);
    }

    public function salvar(): void
    {
        Permissoes::exigirInterno();
        if (sync_uuid_processado($_POST['uuid_offline'] ?? null)) {
            json_ok(['duplicado' => true]);
        }
        // Confirma que o cliente está na carteira do usuário
        $clienteId = (int) ($_POST['cliente_id'] ?? 0);
        [$filtro, $params] = Permissoes::filtroCarteira();
        $cliente = Database::um(
            "SELECT c.id FROM clientes c WHERE c.id = ? AND {$filtro}",
            array_merge([$clienteId], $params)
        );
        if (!$cliente) {
            json_erro('Cliente não encontrado na sua carteira.', 404);
        }
        try {
            $id = ReclamacaoService::registrar($_POST);
        } catch (\InvalidArgumentException $e) {
            json_erro($e->getMessage());
        }
        sync_registrar_uuid($_POST['uuid_offline'] ?? null);
        $this->salvarFotos($id);
        json_ok(['id' => $id]);
    }

    public function mover(): void
    {
        Permissoes::exigir(self::GESTAO);
        $id = (int) ($_POST['id'] ?? 0);
        $novoStatus = $_POST['status'] ?? '';
        $valor = isset($_POST['valor_indenizacao']) && $_POST['valor_indenizacao'] !== ''
            ? (float) $_POST['valor_indenizacao'] : null;
        try {
            ReclamacaoService::mover($id, $novoStatus, $_POST['parecer'] ?? '', $valor);
        } catch (\RuntimeException $e) {
            json_erro($e->getMessage());
        }
        json_ok();
    }

    public function detalhe(): void
    {
        Permissoes::exigirInterno();
        $id = (int) ($_GET['id'] ?? 0);
        [$filtro, $params] = Permissoes::filtroCarteira();
        try {
            $dados = ReclamacaoService::detalhe($id, $filtro, $params);
        } catch (\RuntimeException $e) {
            json_erro($e->getMessage(), 404);
        }
        $dados['podeGerir'] = in_array(\App\Core\Auth::perfil(), self::GESTAO, true);
        render_parcial('partials/reclamacao_detalhe', $dados);
    }

    private function salvarFotos(int $reclamacaoId): void
    {
        if (empty($_FILES['fotos']['name'][0] ?? null)) {
            return;
        }
        $dir = dirname(__DIR__, 2) . '/public/uploads';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $permitidas = ['jpg', 'jpeg', 'png', 'webp', 'heic'];
        foreach ($_FILES['fotos']['tmp_name'] as $i => $tmp) {
            if (!is_uploaded_file($tmp)) {
                continue;
            }
            $ext = strtolower(pathinfo($_FILES['fotos']['name'][$i], PATHINFO_EXTENSION));
            if (!in_array($ext, $permitidas, true)) {
                continue;
            }
            $arquivo = sprintf('reclamacao_%d_%s.%s', $reclamacaoId, bin2hex(random_bytes(6)), $ext);
            if (move_uploaded_file($tmp, $dir . '/' . $arquivo)) {
                Database::executar(
                    'INSERT INTO reclamacao_fotos (reclamacao_id, arquivo) VALUES (?,?)',
                    [$reclamacaoId, $arquivo]
                );
            }
        }
    }
}
