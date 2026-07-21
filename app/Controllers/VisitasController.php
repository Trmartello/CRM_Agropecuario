<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Permissoes;
use App\Services\ComercialService;
use App\Services\FenologiaService;
use App\Services\ImagemService;
use App\Services\PriorizacaoService;

class VisitasController
{
    /** Campos obrigatórios para finalizar o cadastro da visita (base do percentual). */
    private const CAMPOS_OBRIGATORIOS = ['cultura_id', 'objetivo', 'desenvolvimento', 'recomendacao'];

    /** Percentual (0-100) dos campos obrigatórios já preenchidos. */
    private function calcularCompletude(array $post): int
    {
        $total = count(self::CAMPOS_OBRIGATORIOS);
        $preenchidos = 0;
        foreach (self::CAMPOS_OBRIGATORIOS as $campo) {
            $valor = $post[$campo] ?? '';
            if ($campo === 'cultura_id') {
                if ((int) $valor > 0) {
                    $preenchidos++;
                }
            } elseif (trim((string) $valor) !== '') {
                $preenchidos++;
            }
        }
        return (int) round($preenchidos / $total * 100);
    }

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
        // Última visita não finalizada (o modal de nova visita troca para "completar")
        $ultima = Database::um(
            'SELECT id, completude, finalizada FROM visitas WHERE cliente_id = ?
              ORDER BY data_visita DESC, id DESC LIMIT 1',
            [$clienteId]
        );
        $visitaPendente = ($ultima && !(int) $ultima['finalizada'])
            ? ['id' => (int) $ultima['id'], 'completude' => (int) $ultima['completude']]
            : null;
        // Fase 6E: plantios ativos por talhão + catálogo de fenologia das culturas
        $plantios = FenologiaService::plantiosAtivosPorCliente($clienteId);
        $culturasIds = array_values(array_unique(array_merge(
            array_map(fn ($t) => (int) $t['cultura_id'], array_filter($talhoes, fn ($t) => $t['cultura_id'])),
            array_map(fn ($p) => (int) $p['cultura_id'], $plantios)
        )));
        json_ok(compact('propriedades', 'talhoes', 'painel') + [
            'visita_pendente' => $visitaPendente,
            'plantios' => $plantios ?: new \stdClass(),
            'fenologia' => ($culturasIds ? FenologiaService::catalogo($culturasIds) : null) ?: new \stdClass(),
        ]);
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

    /** Dados de uma visita para completar o cadastro (modal de edição). */
    public function dados(): void
    {
        Permissoes::exigirInterno();
        $id = (int) ($_GET['id'] ?? 0);
        [$filtro, $params] = Permissoes::filtroCarteira();
        $visita = Database::um(
            "SELECT v.* FROM visitas v JOIN clientes c ON c.id = v.cliente_id
              WHERE v.id = ? AND {$filtro}",
            array_merge([$id], $params)
        );
        if (!$visita) {
            json_erro('Visita não encontrada.', 404);
        }
        // Checklist da lavoura já marcado (para reabrir o modal preenchido)
        $visita['checklist'] = Database::todos(
            'SELECT manejo_id, situacao, observacao FROM visita_checklist WHERE visita_id = ?', [$id]
        );
        json_ok(['visita' => $visita]);
    }

    /** Salva a visita (modal AJAX, com fotos em multipart). id > 0 completa uma visita não finalizada. */
    public function salvar(): void
    {
        Permissoes::exigir(['Administrador', 'Gestor Técnico', 'Consultor Técnico', 'Vendedor', 'Gestor Comercial']);
        sync_iniciar($_POST['uuid_offline'] ?? null);
        $clienteId = (int) ($_POST['cliente_id'] ?? 0);
        [$filtro, $params] = Permissoes::filtroCarteira();
        $cliente = Database::um(
            "SELECT c.id FROM clientes c WHERE c.id = ? AND {$filtro}",
            array_merge([$clienteId], $params)
        );
        if (!$cliente) {
            json_erro('Cliente não encontrado na sua carteira.', 404);
        }
        $data = trim($_POST['data_visita'] ?? '') ?: date('Y-m-d');
        $completude = $this->calcularCompletude($_POST);
        $finalizada = $completude >= 100 ? 1 : 0;
        $campos = [
            (int) ($_POST['propriedade_id'] ?? 0) ?: null,
            (int) ($_POST['talhao_id'] ?? 0) ?: null,
            (int) ($_POST['cultura_id'] ?? 0) ?: null,
            $data,
            trim($_POST['hora'] ?? '') ?: date('H:i'),
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
            preg_match('/^\d{2}:\d{2}$/', $_POST['hora_inicio'] ?? '') ? $_POST['hora_inicio'] : null,
            preg_match('/^\d{2}:\d{2}$/', $_POST['hora_fim'] ?? '') ? $_POST['hora_fim'] : null,
            isset($_POST['produtor_presente']) ? 1 : 0,
        ];

        $visitaId = (int) ($_POST['id'] ?? 0);
        if ($visitaId > 0) {
            // Completar cadastro: só o dono (ou gestor), e só enquanto não finalizada
            $anterior = Database::um('SELECT usuario_id, finalizada, recomendacao FROM visitas WHERE id = ?', [$visitaId]);
            if (!$anterior || (!Permissoes::ehGestor() && (int) $anterior['usuario_id'] !== Auth::id())) {
                json_erro('Visita não encontrada.', 404);
            }
            if ((int) $anterior['finalizada'] === 1) {
                json_erro('Esta visita já está finalizada — registre uma nova visita.');
            }
            // Finalização definitiva: encerra a visita mesmo com o cadastro incompleto
            if ((int) ($_POST['finalizar_definitivo'] ?? 0) === 1) {
                $finalizada = 1;
            }
            Database::executar(
                'UPDATE visitas SET cliente_id=?, propriedade_id=?, talhao_id=?, cultura_id=?, data_visita=?, hora=?,
                        objetivo=?, estagio_cultura=?, desenvolvimento=?, pragas=?, doencas=?, plantas_daninhas=?,
                        deficiencia_nutricional=?, condicoes_climaticas=?, observacoes=?, recomendacao=?,
                        latitude=?, longitude=?, hora_inicio=?, hora_fim=?, produtor_presente=?,
                        finalizada=?, completude=? WHERE id=?',
                array_merge([$clienteId], $campos, [$finalizada, $completude, $visitaId])
            );
            $fotosIgnoradas = $this->salvarFotos($visitaId);
            $this->salvarConcorrencia($visitaId, $clienteId);
            $this->salvarChecklist($visitaId);
            // Notifica o produtor só se a recomendação surgiu agora (não repete aviso)
            $notificar = trim($_POST['recomendacao'] ?? '') !== '' && trim((string) $anterior['recomendacao']) === '';
        } else {
            // Não permite iniciar uma NOVA visita enquanto a última não estiver finalizada
            $ultima = Database::um(
                'SELECT id, completude, finalizada FROM visitas WHERE cliente_id = ?
                  ORDER BY data_visita DESC, id DESC LIMIT 1',
                [$clienteId]
            );
            if ($ultima && (int) $ultima['finalizada'] === 0) {
                json_erro('Este produtor tem uma visita com cadastro incompleto (' . (int) $ultima['completude']
                    . '%). Complete-a (botão Completar) ou finalize-a antes de registrar uma nova visita.');
            }
            Database::executar(
                'INSERT INTO visitas (cliente_id, propriedade_id, talhao_id, cultura_id, usuario_id, data_visita, hora,
                        objetivo, estagio_cultura, desenvolvimento, pragas, doencas, plantas_daninhas,
                        deficiencia_nutricional, condicoes_climaticas, observacoes, recomendacao,
                        latitude, longitude, hora_inicio, hora_fim, produtor_presente,
                        sincronizada_offline, finalizada, completude)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                array_merge(
                    [$clienteId],
                    array_slice($campos, 0, 3),
                    [Auth::id()],
                    array_slice($campos, 3),
                    [(int) ($_POST['offline'] ?? 0), $finalizada, $completude]
                )
            );
            $visitaId = Database::ultimoId();

            $fotosIgnoradas = $this->salvarFotos($visitaId);
            $this->salvarConcorrencia($visitaId, $clienteId);
            $this->salvarChecklist($visitaId);

            // Amarra automaticamente a quilometragem do dia (mesmo técnico/produtor) a esta visita
            \App\Services\DespesaService::vincularVisitaPorEvento($visitaId, Auth::id(), $clienteId, $data);
            $notificar = trim($_POST['recomendacao'] ?? '') !== '';
        }

        // Próximo retorno combinado na visita → compromisso na Agenda do técnico
        $retorno = trim($_POST['proximo_retorno'] ?? '');
        if ($retorno !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $retorno) && $retorno > date('Y-m-d')) {
            $jaAgendado = Database::valor(
                'SELECT 1 FROM agenda_eventos
                  WHERE usuario_id = ? AND cliente_id = ? AND data = ? AND tipo = "Visita" AND status = "Pendente" LIMIT 1',
                [Auth::id(), $clienteId, $retorno]
            );
            if (!$jaAgendado) {
                $nomeCliente = (string) Database::valor('SELECT nome FROM clientes WHERE id = ?', [$clienteId]);
                Database::executar(
                    'INSERT INTO agenda_eventos (usuario_id, cliente_id, tipo, titulo, data, status, descricao)
                     VALUES (?,?, "Visita", ?, ?, "Pendente", ?)',
                    [Auth::id(), $clienteId, 'Retorno — ' . $nomeCliente, $retorno,
                     'Retorno combinado na visita de ' . data_br($data)]
                );
            }
        }

        // Notifica o produtor (portal) quando há recomendação técnica nova
        if ($notificar) {
            $produtorUid = (int) Database::valor(
                'SELECT id FROM usuarios WHERE cliente_id = ? AND perfil = "Produtor"', [$clienteId]
            );
            if ($produtorUid) {
                \App\Services\NotificacaoService::criar($produtorUid, 'recomendacao',
                    'Nova recomendação técnica', 'Seu consultor registrou uma recomendação na visita de ' . data_br($data) . '.', 'index.php?r=portal');
            }
        }

        // Confirma a transação idempotente: uuid + visita + fotos/vínculos juntos.
        sync_confirmar($_POST['uuid_offline'] ?? null);
        $acaoAud = 'criar';
        if (((int) ($_POST['id'] ?? 0)) > 0) {
            $acaoAud = (int) ($_POST['finalizar_definitivo'] ?? 0) === 1 ? 'finalizar' : 'completar';
        }
        auditar($acaoAud, 'visita', $visitaId, 'cliente #' . $clienteId . " · {$completude}%");
        json_ok([
            'id' => $visitaId,
            'finalizada' => $finalizada,
            'completude' => $completude,
            'aviso' => $fotosIgnoradas > 0
                ? "{$fotosIgnoradas} foto(s) em formato não suportado (ex.: HEIC) não foram salvas — envie em JPEG/PNG."
                : null,
        ]);
    }

    /** Salva as fotos e retorna quantos arquivos foram ignorados (formato não exibível no navegador). */
    private function salvarFotos(int $visitaId): int
    {
        if (empty($_FILES['fotos']['name'][0] ?? null)) {
            return 0;
        }
        $dir = uploads_dir();
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        // Sem HEIC: navegadores não exibem HEIC (thumbnail quebraria); o iPhone converte
        // para JPEG quando o accept do input não inclui HEIC.
        $permitidas = ['jpg', 'jpeg', 'png', 'webp'];
        $ignoradas = 0;
        foreach ($_FILES['fotos']['tmp_name'] as $i => $tmp) {
            if (!is_uploaded_file($tmp)) {
                continue;
            }
            $ext = strtolower(pathinfo($_FILES['fotos']['name'][$i], PATHINFO_EXTENSION));
            if (!in_array($ext, $permitidas, true)) {
                $ignoradas++;
                continue;
            }
            $nomeBase = sprintf('visita_%d_%s', $visitaId, bin2hex(random_bytes(6)));
            // Comprime no servidor (JPEG máx. 1600px + miniatura); se não der, guarda o original
            $arquivo = ImagemService::comprimirFoto($tmp, $nomeBase);
            if ($arquivo === null) {
                $arquivo = $nomeBase . '.' . $ext;
                if (!move_uploaded_file($tmp, $dir . '/' . $arquivo)) {
                    continue;
                }
            }
            Database::executar(
                'INSERT INTO visita_fotos (visita_id, arquivo) VALUES (?,?)',
                [$visitaId, $arquivo]
            );
        }
        return $ignoradas;
    }

    /** Grava o checklist da lavoura marcado na visita (substitui o anterior). */
    private function salvarChecklist(int $visitaId): void
    {
        if (!isset($_POST['checklist']) || !is_array($_POST['checklist'])) {
            return;
        }
        $validas = ['OK', 'Atenção', 'Crítico', 'N/A'];
        Database::executar('DELETE FROM visita_checklist WHERE visita_id = ?', [$visitaId]);
        foreach ($_POST['checklist'] as $manejoId => $situacao) {
            if (!in_array($situacao, $validas, true)) {
                continue;
            }
            $obs = trim((string) ($_POST['checklist_obs'][$manejoId] ?? '')) ?: null;
            try {
                Database::executar(
                    'INSERT INTO visita_checklist (visita_id, manejo_id, situacao, observacao) VALUES (?,?,?,?)',
                    [$visitaId, (int) $manejoId, $situacao, $obs !== null ? mb_substr($obs, 0, 255) : null]
                );
            } catch (\Throwable $e) { /* manejo removido do catálogo: ignora o item */ }
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
        $checklist = Database::todos(
            'SELECT vc.situacao, vc.observacao, m.titulo, fe.codigo AS estagio
               FROM visita_checklist vc
               JOIN manejos_fase m ON m.id = vc.manejo_id
               JOIN fenologia_estagios fe ON fe.id = m.estagio_id
              WHERE vc.visita_id = ? ORDER BY vc.id',
            [$id]
        );
        render_parcial('partials/visita_detalhe', compact('visita', 'fotos', 'checklist'));
    }
}
