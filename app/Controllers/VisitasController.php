<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Permissoes;
use App\Services\ComercialService;
use App\Services\PriorizacaoService;

class VisitasController
{
    public function index(): void
    {
        Permissoes::exigirInterno();
        [$filtro, $params] = Permissoes::filtroCarteira();

        $busca = trim($_GET['busca'] ?? '');
        $where = $filtro;
        if ($busca !== '') {
            $where .= ' AND (c.nome LIKE ? OR cu.nome LIKE ?)';
            $like = "%{$busca}%";
            array_push($params, $like, $like);
        }

        $visitas = Database::todos(
            "SELECT v.*, c.nome AS cliente, u.nome AS tecnico, cu.nome AS cultura,
                    pr.nome AS propriedade, t.nome AS talhao,
                    (SELECT COUNT(*) FROM visita_fotos vf WHERE vf.visita_id = v.id) AS qtd_fotos
               FROM visitas v
               JOIN clientes c ON c.id = v.cliente_id
               JOIN usuarios u ON u.id = v.usuario_id
               LEFT JOIN culturas cu ON cu.id = v.cultura_id
               LEFT JOIN propriedades pr ON pr.id = v.propriedade_id
               LEFT JOIN talhoes t ON t.id = v.talhao_id
              WHERE {$where}
              ORDER BY v.data_visita DESC, v.id DESC
              LIMIT 200",
            $params
        );

        [$filtroC, $paramsC] = Permissoes::filtroCarteira();
        $prioridades = PriorizacaoService::listaPriorizada($filtroC, $paramsC);
        $clientes = Database::todos(
            "SELECT c.id, c.nome FROM clientes c WHERE c.ativo = 1 AND {$filtroC} ORDER BY c.nome",
            $paramsC
        );
        $culturas = Database::todos('SELECT * FROM culturas ORDER BY nome');

        render('visitas', compact('visitas', 'prioridades', 'clientes', 'culturas', 'busca') + ['titulo' => 'Visitas Técnicas']);
    }

    /** Dados auxiliares do modal de visita: propriedades/talhões do cliente, modelos, painel comercial. */
    public function apoioModal(): void
    {
        Permissoes::exigirInterno();
        $clienteId = (int) ($_GET['cliente_id'] ?? 0);
        [$filtro, $params] = Permissoes::filtroCarteira();
        $cliente = Database::um(
            "SELECT c.id, c.nome FROM clientes c WHERE c.id = ? AND {$filtro}",
            array_merge([$clienteId], $params)
        );
        if (!$cliente) {
            json_erro('Cliente não encontrado na sua carteira.', 404);
        }
        $propriedades = Database::todos(
            'SELECT id, nome FROM propriedades WHERE cliente_id = ? ORDER BY nome', [$clienteId]
        );
        $talhoes = Database::todos(
            'SELECT t.id, t.nome, t.propriedade_id, t.cultura_id, cu.nome AS cultura
               FROM talhoes t
               JOIN propriedades p ON p.id = t.propriedade_id
               LEFT JOIN culturas cu ON cu.id = t.cultura_id
              WHERE p.cliente_id = ? ORDER BY t.nome',
            [$clienteId]
        );
        $painel = ComercialService::painelCliente($clienteId);
        json_ok(compact('propriedades', 'talhoes', 'painel'));
    }

    /** Modelos de recomendação por cultura (ou gerais). */
    public function modelos(): void
    {
        Permissoes::exigirInterno();
        $culturaId = (int) ($_GET['cultura_id'] ?? 0);
        $modelos = Database::todos(
            'SELECT id, categoria, titulo, texto_padrao FROM modelos_recomendacao
              WHERE ativo = 1 AND (cultura_id = ? OR cultura_id IS NULL)
              ORDER BY categoria, titulo',
            [$culturaId ?: null]
        );
        json_ok(['modelos' => $modelos]);
    }

    /** Salva a visita (modal AJAX, com fotos em multipart). */
    public function salvar(): void
    {
        Permissoes::exigir(['Administrador', 'Gestor Técnico', 'Consultor Técnico', 'Vendedor', 'Gestor Comercial']);
        $clienteId = (int) ($_POST['cliente_id'] ?? 0);
        [$filtro, $params] = Permissoes::filtroCarteira();
        $cliente = Database::um(
            "SELECT c.id FROM clientes c WHERE c.id = ? AND {$filtro}",
            array_merge([$clienteId], $params)
        );
        if (!$cliente) {
            json_erro('Cliente não encontrado na sua carteira.', 404);
        }
        $data = $_POST['data_visita'] ?? date('Y-m-d');

        Database::executar(
            'INSERT INTO visitas (cliente_id, propriedade_id, talhao_id, cultura_id, usuario_id, data_visita, hora,
                    objetivo, estagio_cultura, desenvolvimento, pragas, doencas, plantas_daninhas,
                    deficiencia_nutricional, condicoes_climaticas, observacoes, recomendacao,
                    latitude, longitude, sincronizada_offline)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $clienteId,
                (int) ($_POST['propriedade_id'] ?? 0) ?: null,
                (int) ($_POST['talhao_id'] ?? 0) ?: null,
                (int) ($_POST['cultura_id'] ?? 0) ?: null,
                Auth::id(),
                $data,
                $_POST['hora'] ?? date('H:i'),
                trim($_POST['objetivo'] ?? '') ?: null,
                trim($_POST['estagio_cultura'] ?? '') ?: null,
                trim($_POST['desenvolvimento'] ?? '') ?: null,
                trim($_POST['pragas'] ?? '') ?: null,
                trim($_POST['doencas'] ?? '') ?: null,
                trim($_POST['plantas_daninhas'] ?? '') ?: null,
                trim($_POST['deficiencia_nutricional'] ?? '') ?: null,
                trim($_POST['condicoes_climaticas'] ?? '') ?: null,
                trim($_POST['observacoes'] ?? '') ?: null,
                trim($_POST['recomendacao'] ?? '') ?: null,
                $_POST['latitude'] !== '' ? (float) $_POST['latitude'] : null,
                $_POST['longitude'] !== '' ? (float) $_POST['longitude'] : null,
                (int) ($_POST['offline'] ?? 0),
            ]
        );
        $visitaId = Database::ultimoId();

        $this->salvarFotos($visitaId);
        $this->salvarConcorrencia($visitaId, $clienteId);

        // Amarra automaticamente a quilometragem do dia (mesmo técnico/produtor) a esta visita
        \App\Services\DespesaService::vincularVisitaPorEvento($visitaId, Auth::id(), $clienteId, $data);

        json_ok(['id' => $visitaId]);
    }

    private function salvarFotos(int $visitaId): void
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
            $arquivo = sprintf('visita_%d_%s.%s', $visitaId, bin2hex(random_bytes(6)), $ext);
            if (move_uploaded_file($tmp, $dir . '/' . $arquivo)) {
                Database::executar(
                    'INSERT INTO visita_fotos (visita_id, arquivo) VALUES (?,?)',
                    [$visitaId, $arquivo]
                );
            }
        }
    }

    private function salvarConcorrencia(int $visitaId, int $clienteId): void
    {
        $concorrente = trim($_POST['concorrente'] ?? '');
        if ($concorrente === '') {
            return;
        }
        Database::executar(
            'INSERT INTO concorrencia_registros (cliente_id, visita_id, concorrente, familia_id, condicoes) VALUES (?,?,?,?,?)',
            [
                $clienteId,
                $visitaId,
                $concorrente,
                (int) ($_POST['concorrente_familia_id'] ?? 0) ?: null,
                trim($_POST['concorrente_condicoes'] ?? '') ?: null,
            ]
        );
    }

    /** Detalhe da visita (painel AJAX → HTML). */
    public function detalhe(): void
    {
        Permissoes::exigirInterno();
        $id = (int) ($_GET['id'] ?? 0);
        [$filtro, $params] = Permissoes::filtroCarteira();
        $visita = Database::um(
            "SELECT v.*, c.nome AS cliente, u.nome AS tecnico, cu.nome AS cultura,
                    pr.nome AS propriedade, t.nome AS talhao
               FROM visitas v
               JOIN clientes c ON c.id = v.cliente_id
               JOIN usuarios u ON u.id = v.usuario_id
               LEFT JOIN culturas cu ON cu.id = v.cultura_id
               LEFT JOIN propriedades pr ON pr.id = v.propriedade_id
               LEFT JOIN talhoes t ON t.id = v.talhao_id
              WHERE v.id = ? AND {$filtro}",
            array_merge([$id], $params)
        );
        if (!$visita) {
            json_erro('Visita não encontrada.', 404);
        }
        $fotos = Database::todos('SELECT * FROM visita_fotos WHERE visita_id = ?', [$id]);
        render_parcial('partials/visita_detalhe', compact('visita', 'fotos'));
    }
}
