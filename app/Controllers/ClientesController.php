<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Permissoes;
use App\Services\ComercialService;
use App\Services\PotencialService;
use App\Services\SegmentacaoService;

class ClientesController
{
    public function index(): void
    {
        Permissoes::exigirInterno();
        [$filtro, $params] = Permissoes::filtroCarteira();

        $busca = trim($_GET['busca'] ?? '');
        $where = $filtro;
        if ($busca !== '') {
            $where .= ' AND (c.nome LIKE ? OR c.municipio LIKE ? OR c.cpf_cnpj LIKE ?)';
            $like = "%{$busca}%";
            array_push($params, $like, $like, $like);
        }
        // Filtro por segmento efetivo (manual do gestor prevalece sobre o calculado)
        $segmentoFiltro = trim($_GET['segmento'] ?? '');
        if (isset(SegmentacaoService::ROTULOS[$segmentoFiltro])) {
            $where .= " AND COALESCE(NULLIF(c.segmento_manual, ''), c.segmento) = ?";
            $params[] = $segmentoFiltro;
        } else {
            $segmentoFiltro = '';
        }

        $clientes = Database::todos(
            "SELECT c.*, f.nome AS filial,
                    (SELECT MAX(v.data_visita) FROM visitas v WHERE v.cliente_id = c.id) AS ultima_visita
               FROM clientes c
               LEFT JOIN filiais f ON f.id = c.filial_id
              WHERE c.ativo = 1 AND {$where}
              ORDER BY c.nome",
            $params
        );

        $filiais = Database::todos('SELECT * FROM filiais ORDER BY nome');
        $responsaveis = Database::todos(
            "SELECT id, nome FROM usuarios WHERE perfil IN ('Consultor Técnico','Vendedor') AND ativo = 1 ORDER BY nome"
        );
        $culturas = Database::todos('SELECT * FROM culturas ORDER BY nome');

        render('clientes', compact('clientes', 'filiais', 'responsaveis', 'culturas', 'busca', 'segmentoFiltro') + ['titulo' => 'Clientes']);
    }

    /** Dados de um cliente para preencher o modal de edição (AJAX). */
    public function obter(): void
    {
        Permissoes::exigirInterno();
        $cliente = $this->clienteDaCarteira((int) ($_GET['id'] ?? 0));
        json_ok(['cliente' => $cliente]);
    }

    /** Cria/atualiza cliente via modal (AJAX). */
    public function salvar(): void
    {
        Permissoes::exigir(['Administrador', 'Gestor Comercial', 'Gestor Técnico', 'Consultor Técnico', 'Vendedor']);
        $id = (int) ($_POST['id'] ?? 0);
        $nome = trim($_POST['nome'] ?? '');
        if ($nome === '') {
            json_erro('Informe o nome do produtor.');
        }

        $dados = [
            $nome,
            $_POST['situacao'] ?? 'Associado',
            trim($_POST['cpf_cnpj'] ?? '') ?: null,
            trim($_POST['telefone'] ?? '') ?: null,
            trim($_POST['email'] ?? '') ?: null,
            trim($_POST['endereco'] ?? '') ?: null,
            trim($_POST['municipio'] ?? '') ?: null,
            trim($_POST['linha'] ?? '') ?: null,
            strtoupper(trim($_POST['estado'] ?? 'SC')) ?: 'SC',
            (int) ($_POST['filial_id'] ?? 0) ?: null,
            $_POST['latitude'] !== '' ? (float) $_POST['latitude'] : null,
            $_POST['longitude'] !== '' ? (float) $_POST['longitude'] : null,
            (int) ($_POST['responsavel_id'] ?? 0) ?: (Permissoes::ehGestor() ? null : Auth::id()),
            $_POST['nivel_tecnologico'] ?? 'Médio',
            (float) str_replace(',', '.', $_POST['volume_compra_anual'] ?? 0),
            (float) str_replace(',', '.', $_POST['potencial_venda'] ?? 0),
            (float) str_replace(',', '.', $_POST['limite_credito'] ?? 0),
            (int) ($_POST['prospecto'] ?? 0) ? 1 : 0,
        ];

        if ($id > 0) {
            $this->clienteDaCarteira($id);
            Database::executar(
                'UPDATE clientes SET nome=?, situacao=?, cpf_cnpj=?, telefone=?, email=?, endereco=?,
                        municipio=?, linha=?, estado=?, filial_id=?, latitude=?, longitude=?, responsavel_id=?,
                        nivel_tecnologico=?, volume_compra_anual=?, potencial_venda=?, limite_credito=?, prospecto=?
                  WHERE id=?',
                array_merge($dados, [$id])
            );
        } else {
            Database::executar(
                'INSERT INTO clientes (nome, situacao, cpf_cnpj, telefone, email, endereco, municipio, linha, estado,
                        filial_id, latitude, longitude, responsavel_id, nivel_tecnologico,
                        volume_compra_anual, potencial_venda, limite_credito, prospecto)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                $dados
            );
            $id = Database::ultimoId();
        }
        // Segmento manual: só gestor fixa/limpa ('' = voltar ao automático)
        if (Permissoes::ehGestor() && array_key_exists('segmento_manual', $_POST)) {
            $seg = trim($_POST['segmento_manual']);
            if ($seg === '' || isset(SegmentacaoService::ROTULOS[$seg])) {
                Database::executar(
                    'UPDATE clientes SET segmento_manual = ? WHERE id = ?',
                    [$seg !== '' ? $seg : null, $id]
                );
            }
        }
        auditar(((int) ($_POST['id'] ?? 0)) > 0 ? 'editar' : 'criar', 'cliente', $id, $nome);
        json_ok(['id' => $id]);
    }

    /**
     * Pré-cadastro de prospecto (registro leve): cria um cliente marcado como
     * prospecto, na carteira do usuário, para ser completado depois. Sem texto livre.
     */
    public function preCadastro(): void
    {
        Permissoes::exigir(['Administrador', 'Gestor Comercial', 'Gestor Técnico', 'Consultor Técnico', 'Vendedor']);
        $nome = trim($_POST['nome'] ?? '');
        if ($nome === '') {
            json_erro('Informe o nome do prospecto.');
        }
        // Município vem da lista pré-cadastrada; o UF fica atrelado ao município
        $municipio = null;
        $estado = 'SC';
        $municipioId = (int) ($_POST['municipio_id'] ?? 0);
        if ($municipioId > 0) {
            $m = Database::um('SELECT nome, estado FROM municipios WHERE id = ?', [$municipioId]);
            if ($m) {
                $municipio = $m['nome'];
                $estado = $m['estado'];
            }
        }
        Database::executar(
            'INSERT INTO clientes (nome, situacao, telefone, municipio, estado, responsavel_id, prospecto)
             VALUES (?, ?, ?, ?, ?, ?, 1)',
            [
                $nome,
                'Não Associado',
                trim($_POST['telefone'] ?? '') ?: null,
                $municipio,
                $estado,
                Permissoes::ehGestor() ? ((int) ($_POST['responsavel_id'] ?? 0) ?: Auth::id()) : Auth::id(),
            ]
        );
        $id = Database::ultimoId();
        auditar('pre-cadastro', 'clientes', $id);
        json_ok(['id' => $id, 'nome' => $nome]);
    }

    /** Ficha completa do cliente (painel lateral, AJAX → HTML parcial). */
    public function ficha(): void
    {
        Permissoes::exigirInterno();
        $id = (int) ($_GET['id'] ?? 0);
        $cliente = $this->clienteDaCarteira($id);

        $propriedades = Database::todos(
            'SELECT p.*,
                    (SELECT COUNT(*) FROM talhoes t WHERE t.propriedade_id = p.id) AS qtd_talhoes
               FROM propriedades p WHERE p.cliente_id = ? ORDER BY p.nome',
            [$id]
        );
        foreach ($propriedades as &$p) {
            $p['talhoes'] = Database::todos(
                'SELECT t.*, cu.nome AS cultura FROM talhoes t
                  LEFT JOIN culturas cu ON cu.id = t.cultura_id
                 WHERE t.propriedade_id = ? ORDER BY t.nome',
                [(int) $p['id']]
            );
        }
        unset($p);

        // Fase 6E: plantio ativo (com fase estimada) e última colheita por talhão
        $plantiosAtivos = \App\Services\FenologiaService::plantiosAtivosPorCliente($id);
        if ($plantiosAtivos) {
            $catalogo = \App\Services\FenologiaService::catalogo(
                array_map(fn ($pl) => (int) $pl['cultura_id'], $plantiosAtivos)
            );
            foreach ($plantiosAtivos as &$pl) {
                $dap = (int) floor((time() - strtotime((string) $pl['data_plantio'])) / 86400);
                $estagio = \App\Services\FenologiaService::estagioPorDap($catalogo[(int) $pl['cultura_id']] ?? [], $dap);
                $pl['dap'] = $dap;
                $pl['fase'] = $estagio ? $estagio['codigo'] . ' — ' . $estagio['nome'] : null;
            }
            unset($pl);
        }
        $colheitas = [];
        foreach (Database::todos(
            'SELECT p.* FROM plantios p
               JOIN talhoes t ON t.id = p.talhao_id
               JOIN propriedades pr ON pr.id = t.propriedade_id
              WHERE pr.cliente_id = ? AND p.encerrado = 1
              ORDER BY p.colhido_em DESC',
            [$id]
        ) as $co) {
            $colheitas[(int) $co['talhao_id']] ??= $co;
        }

        $contatos = Database::todos('SELECT * FROM cliente_contatos WHERE cliente_id = ? ORDER BY nome', [$id]);
        $painel = ComercialService::painelCliente($id);
        $historicoCompras = ComercialService::historicoCompras($id);
        $potencial = PotencialService::porCliente($id);
        $demandaPlano = PotencialService::demandaPlanoSafra($id);
        $planos = Database::todos(
            'SELECT ps.*, cu.nome AS cultura, pr.nome AS propriedade
               FROM planos_safra ps
               JOIN culturas cu ON cu.id = ps.cultura_id
               LEFT JOIN propriedades pr ON pr.id = ps.propriedade_id
              WHERE ps.cliente_id = ? ORDER BY cu.nome',
            [$id]
        );
        $historico = Database::todos(
            'SELECT v.*, u.nome AS tecnico, cu.nome AS cultura, pr.nome AS propriedade, t.nome AS talhao,
                    (SELECT COUNT(*) FROM visita_fotos vf WHERE vf.visita_id = v.id) AS qtd_fotos
               FROM visitas v
               JOIN usuarios u ON u.id = v.usuario_id
               LEFT JOIN culturas cu ON cu.id = v.cultura_id
               LEFT JOIN propriedades pr ON pr.id = v.propriedade_id
               LEFT JOIN talhoes t ON t.id = v.talhao_id
              WHERE v.cliente_id = ?
              ORDER BY v.data_visita DESC, v.id DESC',
            [$id]
        );
        foreach ($historico as &$h) {
            $h['fotos'] = Database::todos('SELECT * FROM visita_fotos WHERE visita_id = ?', [(int) $h['id']]);
        }
        unset($h);

        $culturas = Database::todos('SELECT * FROM culturas ORDER BY nome');
        $documentos = Database::todos(
            'SELECT d.*, u.nome AS enviado_por FROM documentos d
               LEFT JOIN usuarios u ON u.id = d.usuario_id
              WHERE d.cliente_id = ? ORDER BY d.criado_em DESC',
            [$id]
        );

        render_parcial('partials/cliente_ficha', compact(
            'cliente', 'propriedades', 'contatos', 'painel', 'potencial',
            'demandaPlano', 'planos', 'historico', 'historicoCompras', 'culturas', 'documentos',
            'plantiosAtivos', 'colheitas'
        ));
    }

    /** Dados da propriedade para o editor de croqui (talhões + contornos). */
    public function croquiDados(): void
    {
        Permissoes::exigirInterno();
        $propId = (int) ($_GET['propriedade_id'] ?? 0);
        $prop = $this->propriedadeDaCarteira($propId);
        $talhoes = Database::todos(
            'SELECT t.id, t.nome, t.area_ha, t.area_gps, t.contorno, cu.nome AS cultura
               FROM talhoes t LEFT JOIN culturas cu ON cu.id = t.cultura_id
              WHERE t.propriedade_id = ? ORDER BY t.nome',
            [$propId]
        );
        json_ok([
            'propriedade' => [
                'id' => (int) $prop['id'],
                'nome' => $prop['nome'],
                'latitude' => $prop['latitude'] !== null ? (float) $prop['latitude'] : null,
                'longitude' => $prop['longitude'] !== null ? (float) $prop['longitude'] : null,
                'area_ha' => (float) $prop['area_ha'],
                'area_gps' => isset($prop['area_gps']) && $prop['area_gps'] !== null ? (float) $prop['area_gps'] : null,
                'contorno' => $prop['contorno'] ?? null,
            ],
            'talhoes' => $talhoes,
            // Imagem de satélite de fundo (provedor configurável; vazio = sem mapa)
            'tiles' => [
                'url' => \App\Services\ConfigService::obter('mapa_tiles_url',
                    'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}'),
                'atribuicao' => \App\Services\ConfigService::obter('mapa_tiles_atribuicao',
                    'Imagens: Esri, Maxar, Earthstar Geographics'),
            ],
        ]);
    }

    /**
     * Salva o contorno (croqui) de um TALHÃO ou da PROPRIEDADE (tipo=propriedade,
     * área total da divisa); a área é SEMPRE recalculada no servidor.
     */
    public function salvarCroqui(): void
    {
        Permissoes::exigirInterno();
        sync_iniciar($_POST['uuid_offline'] ?? null);
        $ehPropriedade = ($_POST['tipo'] ?? 'talhao') === 'propriedade';
        if ($ehPropriedade) {
            $alvoId = (int) ($_POST['propriedade_id'] ?? 0);
            $this->propriedadeDaCarteira($alvoId);
            $tabela = 'propriedades';
        } else {
            $alvoId = (int) ($_POST['talhao_id'] ?? 0);
            $talhao = Database::um('SELECT t.id, t.propriedade_id FROM talhoes t WHERE t.id = ?', [$alvoId]);
            if (!$talhao) {
                json_erro('Talhão não encontrado.', 404);
            }
            $this->propriedadeDaCarteira((int) $talhao['propriedade_id']);
            $tabela = 'talhoes';
        }

        $contornoJson = trim($_POST['contorno'] ?? '');
        if ($contornoJson === '' || $contornoJson === '[]') {
            Database::executar("UPDATE {$tabela} SET contorno = NULL, area_gps = NULL WHERE id = ?", [$alvoId]);
            sync_confirmar($_POST['uuid_offline'] ?? null);
            auditar('excluir', 'croqui', $alvoId, ($ehPropriedade ? 'propriedade' : 'talhão') . ' — contorno removido');
            json_ok(['area_gps' => null]);
        }
        try {
            $pontos = \App\Services\CroquiService::validarContorno($contornoJson);
        } catch (\InvalidArgumentException $e) {
            json_erro($e->getMessage());
        }

        // REGRA: talhão JAMAIS sai da divisa da propriedade
        if ($ehPropriedade) {
            // Divisa nova não pode deixar talhões já desenhados para fora
            $foraDaNova = [];
            foreach (Database::todos(
                'SELECT nome, contorno FROM talhoes WHERE propriedade_id = ? AND contorno IS NOT NULL', [$alvoId]
            ) as $t) {
                $pts = json_decode((string) $t['contorno'], true) ?: [];
                if ($pts && \App\Services\CroquiService::pontosFora($pts, $pontos)) {
                    $foraDaNova[] = $t['nome'];
                }
            }
            if ($foraDaNova) {
                json_erro('A divisa desenhada deixa talhão(ões) para fora da propriedade: '
                    . implode(', ', $foraDaNova) . '. Amplie a divisa ou ajuste os talhões antes.');
            }
        } else {
            // Talhão fora da divisa é PRESO na borda da propriedade (não recusa —
            // a fila offline nunca falha e o desenho fica sempre válido)
            $divisaJson = Database::valor(
                'SELECT p.contorno FROM propriedades p JOIN talhoes t ON t.propriedade_id = p.id WHERE t.id = ?',
                [$alvoId]
            );
            $divisa = $divisaJson ? (json_decode((string) $divisaJson, true) ?: []) : [];
            if ($divisa) {
                $pontos = \App\Services\CroquiService::prenderNaDivisa($pontos, $divisa);
            }
        }

        $areaGps = \App\Services\CroquiService::areaHa($pontos);
        Database::executar(
            "UPDATE {$tabela} SET contorno = ?, area_gps = ? WHERE id = ?",
            [json_encode($pontos), $areaGps, $alvoId]
        );
        // Opcional: assumir a área medida como a área oficial
        if ((int) ($_POST['usar_area'] ?? 0) === 1 && $areaGps > 0) {
            Database::executar("UPDATE {$tabela} SET area_ha = ? WHERE id = ?", [$areaGps, $alvoId]);
        }
        sync_confirmar($_POST['uuid_offline'] ?? null);
        auditar('salvar', 'croqui', $alvoId, ($ehPropriedade ? 'propriedade' : 'talhão') . ' · ' . count($pontos) . " pontos · {$areaGps} ha");
        json_ok(['area_gps' => $areaGps]);
    }

    /**
     * Importa a divisa oficial da propriedade a partir do shapefile do CAR
     * (arquivo .zip baixado da consulta pública do SICAR). Desenha o perímetro
     * no croqui (mesma tabela/área do croqui manual) — o servidor recalcula a área.
     */
    public function importarCar(): void
    {
        Permissoes::exigirInterno();
        $propId = (int) ($_POST['propriedade_id'] ?? 0);
        $this->propriedadeDaCarteira($propId);

        if (empty($_FILES['arquivo']['tmp_name']) || !is_uploaded_file($_FILES['arquivo']['tmp_name'])) {
            json_erro('Selecione o arquivo .zip do CAR (Shapefile).');
        }
        if (($_FILES['arquivo']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            json_erro('Falha no envio do arquivo (verifique o tamanho).');
        }
        if (strtolower(pathinfo($_FILES['arquivo']['name'], PATHINFO_EXTENSION)) !== 'zip') {
            json_erro('Envie o .zip do CAR (na consulta pública, "Baixar dados" → Shapefile).');
        }
        if ($_FILES['arquivo']['size'] > 30 * 1024 * 1024) {
            json_erro('Arquivo muito grande (máximo 30 MB).');
        }

        try {
            $pontos = \App\Services\ShapefileService::contornoDoCarZip($_FILES['arquivo']['tmp_name']);
            $pontos = \App\Services\CroquiService::validarContorno(json_encode($pontos));
        } catch (\Exception $e) {
            json_erro($e->getMessage());
        }

        $areaGps = \App\Services\CroquiService::areaHa($pontos);

        // A divisa do CAR é a verdade oficial: se algum talhão já desenhado ficar
        // para fora, avisamos (não bloqueia — deixa o gestor ajustar o talhão).
        $fora = [];
        foreach (Database::todos(
            'SELECT nome, contorno FROM talhoes WHERE propriedade_id = ? AND contorno IS NOT NULL', [$propId]
        ) as $t) {
            $pts = json_decode((string) $t['contorno'], true) ?: [];
            if ($pts && \App\Services\CroquiService::pontosFora($pts, $pontos)) {
                $fora[] = $t['nome'];
            }
        }

        $car = trim($_POST['car_numero'] ?? '');
        if ($car !== '') {
            Database::executar(
                'UPDATE propriedades SET contorno = ?, area_gps = ?, car_numero = ? WHERE id = ?',
                [json_encode($pontos), $areaGps, mb_substr($car, 0, 60), $propId]
            );
        } else {
            Database::executar(
                'UPDATE propriedades SET contorno = ?, area_gps = ? WHERE id = ?',
                [json_encode($pontos), $areaGps, $propId]
            );
        }
        auditar('importar', 'croqui', $propId, 'CAR shapefile · ' . count($pontos) . " pontos · {$areaGps} ha");
        json_ok(['area_gps' => $areaGps, 'pontos' => count($pontos), 'talhoes_fora' => $fora]);
    }

    /** Garante que a propriedade pertence a um cliente da carteira do usuário. */
    private function propriedadeDaCarteira(int $propId): array
    {
        [$filtro, $params] = Permissoes::filtroCarteira();
        $prop = Database::um(
            "SELECT p.* FROM propriedades p JOIN clientes c ON c.id = p.cliente_id
              WHERE p.id = ? AND {$filtro}",
            array_merge([$propId], $params)
        );
        if (!$prop) {
            json_erro('Propriedade não encontrada na sua carteira.', 404);
        }
        return $prop;
    }

    /** Tipos de documento e extensões aceitas na gestão documental. */
    private const DOC_EXT = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic', 'doc', 'docx', 'xls', 'xlsx'];
    private const DOC_TIPOS = ['Foto', 'Laudo', 'Receita', 'Contrato', 'Nota fiscal', 'PDF', 'Outro'];

    /** Anexa um documento ao produtor (upload multipart). */
    public function salvarDocumento(): void
    {
        Permissoes::exigirInterno();
        $clienteId = (int) ($_POST['cliente_id'] ?? 0);
        $this->clienteDaCarteira($clienteId);

        if (empty($_FILES['arquivo']['name'] ?? null) || !is_uploaded_file($_FILES['arquivo']['tmp_name'] ?? '')) {
            json_erro('Selecione um arquivo.');
        }
        if (($_FILES['arquivo']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            json_erro('Falha no envio do arquivo (verifique o tamanho).');
        }
        $ext = strtolower(pathinfo($_FILES['arquivo']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::DOC_EXT, true)) {
            json_erro('Tipo de arquivo não permitido. Aceitos: ' . implode(', ', self::DOC_EXT) . '.');
        }
        $tipo = $_POST['tipo'] ?? 'Outro';
        if (!in_array($tipo, self::DOC_TIPOS, true)) {
            $tipo = 'Outro';
        }
        $nome = trim($_POST['nome'] ?? '') ?: pathinfo($_FILES['arquivo']['name'], PATHINFO_FILENAME);

        $dir = uploads_dir() . '/documentos';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $arquivo = sprintf('doc_%d_%s.%s', $clienteId, bin2hex(random_bytes(8)), $ext);
        if (!move_uploaded_file($_FILES['arquivo']['tmp_name'], $dir . '/' . $arquivo)) {
            json_erro('Não foi possível salvar o arquivo.');
        }
        Database::executar(
            'INSERT INTO documentos (cliente_id, usuario_id, tipo, nome, arquivo, mime, tamanho) VALUES (?,?,?,?,?,?,?)',
            [
                $clienteId,
                Auth::id(),
                $tipo,
                mb_substr($nome, 0, 160),
                $arquivo,
                $_FILES['arquivo']['type'] ?? null,
                (int) ($_FILES['arquivo']['size'] ?? 0),
            ]
        );
        $docId = Database::ultimoId();
        auditar('anexar', 'documento', $docId, 'cliente #' . $clienteId);
        json_ok(['id' => $docId]);
    }

    /** Baixa um documento (autenticado, restrito à carteira ou ao próprio produtor). */
    public function baixarDocumento(): void
    {
        Auth::exigirLogin();
        $doc = $this->documentoDaCarteira((int) ($_GET['id'] ?? 0));
        $caminho = uploads_dir() . '/documentos/' . basename($doc['arquivo']);
        if (!is_file($caminho)) { // arquivos antigos ainda no docroot
            $caminho = dirname(__DIR__, 2) . '/public/uploads/documentos/' . basename($doc['arquivo']);
        }
        if (!is_file($caminho)) {
            http_response_code(404);
            echo 'Arquivo não encontrado.';
            return;
        }
        $ext = strtolower(pathinfo($doc['arquivo'], PATHINFO_EXTENSION));
        header('Content-Type: ' . ($doc['mime'] ?: 'application/octet-stream'));
        header('Content-Disposition: inline; filename="' . rawurlencode($doc['nome']) . '.' . $ext . '"');
        header('Content-Length: ' . filesize($caminho));
        header('X-Content-Type-Options: nosniff');
        readfile($caminho);
    }

    public function excluirDocumento(): void
    {
        Permissoes::exigirInterno();
        $doc = $this->documentoDaCarteira((int) ($_POST['id'] ?? 0));
        foreach ([uploads_dir(), dirname(__DIR__, 2) . '/public/uploads'] as $base) {
            $caminho = $base . '/documentos/' . basename($doc['arquivo']);
            if (is_file($caminho)) {
                @unlink($caminho);
            }
        }
        Database::executar('DELETE FROM documentos WHERE id = ?', [(int) $doc['id']]);
        auditar('excluir', 'documento', (int) $doc['id']);
        json_ok();
    }

    /** Carrega o documento garantindo acesso: carteira do usuário ou o próprio produtor. */
    private function documentoDaCarteira(int $id): array
    {
        if (Auth::perfil() === 'Produtor') {
            $clienteId = (int) Database::valor('SELECT cliente_id FROM usuarios WHERE id = ?', [Auth::id()]);
            $doc = Database::um('SELECT * FROM documentos WHERE id = ? AND cliente_id = ?', [$id, $clienteId]);
        } else {
            [$filtro, $params] = Permissoes::filtroCarteira();
            $doc = Database::um(
                "SELECT d.* FROM documentos d JOIN clientes c ON c.id = d.cliente_id
                  WHERE d.id = ? AND {$filtro}",
                array_merge([$id], $params)
            );
        }
        if (!$doc) {
            json_erro('Documento não encontrado.', 404);
        }
        return $doc;
    }

    /** Salva propriedade (modal AJAX). */
    public function salvarPropriedade(): void
    {
        Permissoes::exigirInterno();
        $clienteId = (int) ($_POST['cliente_id'] ?? 0);
        $this->clienteDaCarteira($clienteId);
        $id = (int) ($_POST['id'] ?? 0);
        $nome = trim($_POST['nome'] ?? '');
        if ($nome === '') {
            json_erro('Informe o nome da propriedade.');
        }
        $dados = [
            $nome,
            (float) str_replace(',', '.', $_POST['area_ha'] ?? 0),
            trim($_POST['municipio'] ?? '') ?: null,
            trim($_POST['car_numero'] ?? '') ? mb_substr(trim($_POST['car_numero']), 0, 60) : null,
        ];
        if ($id > 0) {
            Database::executar(
                'UPDATE propriedades SET nome=?, area_ha=?, municipio=?, car_numero=? WHERE id=? AND cliente_id=?',
                array_merge($dados, [$id, $clienteId])
            );
        } else {
            Database::executar(
                'INSERT INTO propriedades (nome, area_ha, municipio, car_numero, cliente_id) VALUES (?,?,?,?,?)',
                array_merge($dados, [$clienteId])
            );
            $id = Database::ultimoId();
        }
        json_ok(['id' => $id]);
    }

    /** Salva talhão (modal AJAX). */
    public function salvarTalhao(): void
    {
        Permissoes::exigirInterno();
        $propriedadeId = (int) ($_POST['propriedade_id'] ?? 0);
        $clienteId = (int) Database::valor('SELECT cliente_id FROM propriedades WHERE id = ?', [$propriedadeId]);
        $this->clienteDaCarteira($clienteId);
        $id = (int) ($_POST['id'] ?? 0);
        $nome = trim($_POST['nome'] ?? '');
        if ($nome === '') {
            json_erro('Informe o nome do talhão.');
        }
        $dados = [
            $nome,
            (float) str_replace(',', '.', $_POST['area_ha'] ?? 0),
            (int) ($_POST['cultura_id'] ?? 0) ?: null,
        ];
        if ($id > 0) {
            Database::executar(
                'UPDATE talhoes SET nome=?, area_ha=?, cultura_id=? WHERE id=? AND propriedade_id=?',
                array_merge($dados, [$id, $propriedadeId])
            );
        } else {
            Database::executar(
                'INSERT INTO talhoes (nome, area_ha, cultura_id, propriedade_id) VALUES (?,?,?,?)',
                array_merge($dados, [$propriedadeId])
            );
            $id = Database::ultimoId();
        }
        json_ok(['id' => $id]);
    }

    /** Salva plano de safra (intenção de plantio) via modal AJAX. */
    public function salvarPlanoSafra(): void
    {
        Permissoes::exigirInterno();
        $clienteId = (int) ($_POST['cliente_id'] ?? 0);
        $this->clienteDaCarteira($clienteId);
        $safra = ComercialService::safraAtual();
        if (!$safra) {
            json_erro('Nenhuma safra atual cadastrada.');
        }
        $culturaId = (int) ($_POST['cultura_id'] ?? 0);
        $area = (float) str_replace(',', '.', $_POST['area_ha'] ?? 0);
        if (!$culturaId || $area <= 0) {
            json_erro('Informe a cultura e a área plantada.');
        }
        Database::executar(
            'INSERT INTO planos_safra (cliente_id, propriedade_id, cultura_id, safra_id, area_ha) VALUES (?,?,?,?,?)',
            [$clienteId, (int) ($_POST['propriedade_id'] ?? 0) ?: null, $culturaId, (int) $safra['id'], $area]
        );
        json_ok(['id' => Database::ultimoId()]);
    }

    /** Garante que o cliente pertence à carteira do usuário (ou é gestor). */
    private function clienteDaCarteira(int $id): array
    {
        [$filtro, $params] = Permissoes::filtroCarteira();
        $cliente = Database::um(
            "SELECT c.* FROM clientes c WHERE c.id = ? AND {$filtro}",
            array_merge([$id], $params)
        );
        if (!$cliente) {
            json_erro('Cliente não encontrado na sua carteira.', 404);
        }
        return $cliente;
    }

}
