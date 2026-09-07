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
        // v40: finalidades ativas para os modais de talhão/plantio/"plantar a área toda"
        $finalidades = Database::todos('SELECT id, nome FROM finalidades WHERE ativo = 1 ORDER BY ordem, nome');

        render('clientes', compact('clientes', 'filiais', 'responsaveis', 'culturas', 'finalidades', 'busca', 'segmentoFiltro') + ['titulo' => 'Clientes']);
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

        // Município – UF da lista pré-cadastrada (MunicipiosSul): nome oficial + UF
        // deduzida; texto fora da lista (carga do ERP/legado) segue como veio.
        $munCli = \App\Services\MunicipiosSul::porCodigo(
            \App\Services\MunicipiosSul::codigoPorNome(trim($_POST['municipio'] ?? ''), trim($_POST['estado'] ?? '') ?: null)
        );
        $dados = [
            $nome,
            $_POST['situacao'] ?? 'Associado',
            trim($_POST['cpf_cnpj'] ?? '') ?: null,
            trim($_POST['telefone'] ?? '') ?: null,
            trim($_POST['email'] ?? '') ?: null,
            trim($_POST['endereco'] ?? '') ?: null,
            $munCli['nome'] ?? (trim($_POST['municipio'] ?? '') ?: null),
            trim($_POST['linha'] ?? '') ?: null,
            $munCli['uf'] ?? (strtoupper(trim($_POST['estado'] ?? 'SC')) ?: 'SC'),
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
        // v40: propriedade → imóveis (CAR) → talhões, com totalização por cultura × finalidade
        $resumosProdutor = [];
        foreach ($propriedades as &$p) {
            $p['imoveis'] = \App\Services\AreaPlantioService::imoveisDaPropriedade((int) $p['id']);
            $p['talhoes'] = [];
            foreach ($p['imoveis'] as $im) {
                foreach ($im['talhoes'] as $t) {
                    $p['talhoes'][] = $t; // lista plana: plantios/colheitas por talhão continuam funcionando
                }
            }
            $p['resumo'] = \App\Services\AreaPlantioService::consolidar(array_column($p['imoveis'], 'resumo'));
            $resumosProdutor[] = $p['resumo'];
        }
        unset($p);
        $resumoProdutor = \App\Services\AreaPlantioService::consolidar($resumosProdutor);
        $finalidades = Database::todos('SELECT id, nome FROM finalidades WHERE ativo = 1 ORDER BY ordem, nome');

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
            'plantiosAtivos', 'colheitas', 'resumoProdutor', 'finalidades'
        ));
    }

    /** Dados da propriedade para o editor de croqui (talhões + contornos). */
    public function croquiDados(): void
    {
        Permissoes::exigirInterno();
        // v40: o croqui é por IMÓVEL (CAR). propriedade_id ainda é aceito (fila offline
        // antiga, links antigos) e cai no primeiro imóvel da propriedade.
        $imovel = $this->imovelAlvo();
        $prop = $this->propriedadeDaCarteira((int) $imovel['propriedade_id']);
        $propId = (int) $prop['id'];
        $imovelId = (int) $imovel['id'];
        $primeiroId = (int) Database::valor('SELECT MIN(id) FROM imoveis WHERE propriedade_id = ?', [$propId]);
        // Talhão legado (sem imóvel) aparece no PRIMEIRO imóvel, para não sumir do croqui
        $talhoes = Database::todos(
            'SELECT t.id, t.nome, t.area_ha, t.area_gps, t.contorno, t.imovel_id, t.area_plantio_id, cu.nome AS cultura, f.nome AS finalidade
               FROM talhoes t
               LEFT JOIN culturas cu ON cu.id = t.cultura_id
               LEFT JOIN finalidades f ON f.id = t.finalidade_id
              WHERE t.propriedade_id = ? AND (t.imovel_id = ? OR (t.imovel_id IS NULL AND ? = ?))
              ORDER BY t.nome',
            [$propId, $imovelId, $imovelId, $primeiroId]
        );
        // Outros imóveis da mesma propriedade: só a divisa, para contexto no mapa (não editáveis)
        $outros = Database::todos(
            'SELECT id, nome, car_numero, contorno FROM imoveis
              WHERE propriedade_id = ? AND id <> ? AND contorno IS NOT NULL ORDER BY ordem, id',
            [$propId, $imovelId]
        );
        // Município/estado/linha do produtor — pré-preenchem o "Ir para" (localizar no mapa)
        $cli = Database::um('SELECT municipio, estado, linha FROM clientes WHERE id = ?', [(int) $prop['cliente_id']]) ?: [];
        $num = fn ($v) => $v !== null && $v !== '' ? (float) $v : null;
        json_ok([
            'propriedade' => [
                'id' => $propId,
                'nome' => $prop['nome'],
                'latitude' => $num($prop['latitude']),
                'longitude' => $num($prop['longitude']),
                'area_ha' => (float) $prop['area_ha'],
                'municipio' => $prop['municipio'] ?? ($cli['municipio'] ?? null),
                'estado' => $cli['estado'] ?? null,
                'linha' => $cli['linha'] ?? null,
            ],
            'imovel' => [
                'id' => $imovelId,
                'nome' => $imovel['nome'],
                'rotulo' => self::rotuloImovel($imovel),
                'car_numero' => $imovel['car_numero'],
                'municipio' => $imovel['municipio'],
                'area_ha' => (float) $imovel['area_ha'],
                'area_gps' => $num($imovel['area_gps']),
                'contorno' => $imovel['contorno'],
                'area_plantio_ha' => (float) $imovel['area_plantio_ha'],
                'area_plantio_gps' => $num($imovel['area_plantio_gps']),
                'contorno_plantio' => $imovel['contorno_plantio'],
            ],
            'outros' => array_map(fn ($o) => [
                'id' => (int) $o['id'], 'rotulo' => self::rotuloImovel($o), 'contorno' => $o['contorno'],
            ], $outros),
            'talhoes' => $talhoes,
            // v44: VÁRIAS áreas de plantio por imóvel (cada uma um polígono dentro da divisa)
            'areas_plantio' => array_map(fn ($a) => [
                'id' => (int) $a['id'], 'nome' => $a['nome'], 'contorno' => $a['contorno'], 'area_gps' => (float) $a['area_gps'],
                // v47: uso da área (lavoura/perene/reflorestamento) + cultura
                'uso' => $a['uso'] ?? 'lavoura', 'cultura_id' => (int) ($a['cultura_id'] ?? 0) ?: null, 'cultura' => $a['cultura'] ?? null,
            ], \App\Services\AreaPlantioService::areasDoImovel($imovelId)),
            'usos_area' => \App\Services\AreaPlantioService::USOS_AREA,
            'culturas' => Database::todos('SELECT id, nome FROM culturas ORDER BY nome'),
            // v46: áreas de NÃO plantio (mata, açude...) — buracos descontados do plantio/talhões
            'areas_nao_plantio' => array_map(fn ($x) => [
                'id' => (int) $x['id'], 'nome' => $x['nome'], 'tipo' => $x['tipo'], 'contorno' => $x['contorno'], 'area_gps' => (float) $x['area_gps'],
            ], \App\Services\AreaPlantioService::exclusoesDoImovel($imovelId)),
            'tipos_nao_plantio' => \App\Services\AreaPlantioService::TIPOS_NAO_PLANTIO,
            // Imagem de satélite de fundo (provedor configurável; vazio = sem mapa)
            'tiles' => [
                'url' => \App\Services\ConfigService::obter('mapa_tiles_url',
                    'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}'),
                'atribuicao' => \App\Services\ConfigService::obter('mapa_tiles_atribuicao',
                    'Imagens: Esri, Maxar, Earthstar Geographics'),
                // Camada de rótulos (nomes de cidades/localidades/municípios) sobre o satélite,
                // aparecendo conforme o zoom (como no Google). Vazio desliga.
                'labels' => \App\Services\ConfigService::obter('mapa_labels_url',
                    'https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}'),
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
        // v40 — três alvos: 'imovel' (divisa do CAR; 'propriedade' é alias da fila
        // offline antiga), 'plantio' (área de plantio do imóvel) e 'talhao'.
        $tipo = (string) ($_POST['tipo'] ?? 'talhao');
        if ($tipo === 'propriedade') {
            $tipo = 'imovel';
        }
        if (!in_array($tipo, ['imovel', 'plantio', 'talhao', 'exclusao'], true)) {
            json_erro('Tipo de croqui inválido.');
        }
        $rotulo = ['imovel' => 'divisa do imóvel', 'plantio' => 'área de plantio', 'talhao' => 'talhão', 'exclusao' => 'área de não plantio'][$tipo];

        if ($tipo === 'talhao') {
            $alvoId = (int) ($_POST['talhao_id'] ?? 0);
            $talhao = Database::um('SELECT t.id, t.propriedade_id, t.imovel_id, t.area_plantio_id FROM talhoes t WHERE t.id = ?', [$alvoId]);
            if (!$talhao) {
                json_erro('Talhão não encontrado.', 404);
            }
            $this->propriedadeDaCarteira((int) $talhao['propriedade_id']);
            $imovel = $talhao['imovel_id'] !== null
                ? Database::um('SELECT * FROM imoveis WHERE id = ?', [(int) $talhao['imovel_id']])
                : $this->primeiroImovel((int) $talhao['propriedade_id']);
            $tabela = 'talhoes';
            $colContorno = 'contorno';
            $colArea = 'area_gps';
            $colOficial = 'area_ha';
        } elseif ($tipo === 'plantio') {
            // v44: várias áreas de plantio por imóvel — plantio_id 0 = nova área
            $imovel = $this->imovelAlvo();
            $plantioId = (int) ($_POST['plantio_id'] ?? 0);
            if ($plantioId > 0) {
                $existe = Database::valor('SELECT 1 FROM areas_plantio WHERE id = ? AND imovel_id = ?', [$plantioId, (int) $imovel['id']]);
                if (!$existe) {
                    json_erro('Área de plantio não encontrada neste imóvel.', 404);
                }
            }
            $alvoId = $plantioId;
            $tabela = 'areas_plantio';
            $colContorno = 'contorno';
            $colArea = 'area_gps';
            $colOficial = null;
        } elseif ($tipo === 'exclusao') {
            // v46: área de NÃO plantio (mata, açude...) — exclusao_id 0 = nova
            $imovel = $this->imovelAlvo();
            $exclusaoId = (int) ($_POST['exclusao_id'] ?? 0);
            if ($exclusaoId > 0) {
                $existe = Database::valor('SELECT 1 FROM areas_nao_plantio WHERE id = ? AND imovel_id = ?', [$exclusaoId, (int) $imovel['id']]);
                if (!$existe) {
                    json_erro('Área de não plantio não encontrada neste imóvel.', 404);
                }
            }
            $alvoId = $exclusaoId;
            $tabela = 'areas_nao_plantio';
            $colContorno = 'contorno';
            $colArea = 'area_gps';
            $colOficial = null;
        } else {
            $imovel = $this->imovelAlvo();
            $alvoId = (int) $imovel['id'];
            $tabela = 'imoveis';
            $colContorno = 'contorno';
            $colArea = 'area_gps';
            $colOficial = 'area_ha';
        }
        $imovelId = (int) ($imovel['id'] ?? 0);

        $contornoJson = trim($_POST['contorno'] ?? '');
        if ($contornoJson === '' || $contornoJson === '[]') {
            if ($tipo === 'plantio') {
                if ($alvoId > 0) {
                    $hospedados = Database::todos('SELECT nome FROM talhoes WHERE area_plantio_id = ?', [$alvoId]);
                    if ($hospedados) {
                        json_erro('Esta área de plantio tem talhões dentro (' . implode(', ', array_column($hospedados, 'nome'))
                            . '). Exclua ou mova os talhões antes de apagar a área.');
                    }
                    Database::executar('DELETE FROM areas_plantio WHERE id = ? AND imovel_id = ?', [$alvoId, $imovelId]);
                    auditar('excluir', 'croqui', $alvoId, 'área de plantio removida · imóvel #' . $imovelId);
                }
                sync_confirmar($_POST['uuid_offline'] ?? null);
                json_ok(['area_gps' => null, 'plantio_id' => $alvoId, 'removido' => $alvoId > 0]);
            }
            if ($tipo === 'exclusao') {
                // v46: apagar a área de não plantio devolve a área aos talhões/áreas que a continham
                if ($alvoId > 0) {
                    Database::executar('DELETE FROM areas_nao_plantio WHERE id = ? AND imovel_id = ?', [$alvoId, $imovelId]);
                    \App\Services\AreaPlantioService::sincronizarLiquidas($imovelId);
                    auditar('excluir', 'croqui', $alvoId, 'área de não plantio removida · imóvel #' . $imovelId);
                }
                sync_confirmar($_POST['uuid_offline'] ?? null);
                json_ok(['area_gps' => null, 'exclusao_id' => $alvoId, 'removido' => $alvoId > 0]);
            }
            Database::executar("UPDATE {$tabela} SET {$colContorno} = NULL, {$colArea} = NULL WHERE id = ?", [$alvoId]);
            sync_confirmar($_POST['uuid_offline'] ?? null);
            auditar('excluir', 'croqui', $alvoId, $rotulo . ' — contorno removido');
            json_ok(['area_gps' => null]);
        }
        try {
            $pontos = \App\Services\CroquiService::validarContorno($contornoJson);
        } catch (\InvalidArgumentException $e) {
            json_erro($e->getMessage());
        }

        $divisa = !empty($imovel['contorno']) ? (json_decode((string) $imovel['contorno'], true) ?: []) : [];
        $avisos = [];
        if ($tipo === 'imovel') {
            // REGRA: a divisa nova não pode deixar para fora nem os talhões já
            // desenhados nem a área de plantio (ambos ficam DENTRO do imóvel)
            $foraDaNova = [];
            foreach ($this->talhoesDoImovel($imovelId, true) as $t) {
                $pts = json_decode((string) $t['contorno'], true) ?: [];
                if ($pts && \App\Services\CroquiService::pontosFora($pts, $pontos)) {
                    $foraDaNova[] = $t['nome'];
                }
            }
            foreach (\App\Services\AreaPlantioService::areasDoImovel($imovelId) as $a) {
                $pl = json_decode((string) $a['contorno'], true) ?: [];
                if ($pl && \App\Services\CroquiService::pontosFora($pl, $pontos)) {
                    $foraDaNova[] = 'área de plantio "' . $a['nome'] . '"';
                }
            }
            foreach (\App\Services\AreaPlantioService::exclusoesDoImovel($imovelId) as $x) {
                $pl = json_decode((string) $x['contorno'], true) ?: [];
                if ($pl && \App\Services\CroquiService::pontosFora($pl, $pontos)) {
                    $foraDaNova[] = 'área de não plantio "' . $x['nome'] . '"';
                }
            }
            if ($foraDaNova) {
                json_erro('A divisa desenhada deixa para fora do imóvel: '
                    . implode(', ', $foraDaNova) . '. Amplie a divisa ou ajuste antes.');
            }
        } else {
            // Área de plantio e talhão: ponto fora da divisa é PRESO na borda (não
            // recusa — a fila offline nunca falha e o desenho fica sempre válido)
            // REGRA (teste de campo): a área de plantio e os talhões existem DENTRO da
            // área do CAR — sem divisa não há onde desenhar
            if (!$divisa) {
                json_erro('Este imóvel ainda não tem a divisa do CAR. Traga o CAR no croqui (CAR pela sede, CAR no mapa ou o arquivo do SICAR) antes de desenhar a área de plantio ou os talhões.');
            }
            $hostTalhao = null;
            if ($tipo === 'talhao') {
                // v45 — fluxo guiado: o talhão fica DENTRO de uma ÁREA DE PLANTIO (a
                // hospedeira é o limite: prende, margeia, não sobrepõe, nenhuma linha fora)
                [$pontos, $hostTalhao] = $this->talhaoDentroDaAreaPlantio($pontos, $imovelId, $alvoId, $talhao['area_plantio_id'] ?? null);
            } elseif ($tipo === 'exclusao') {
                // v46: área de NÃO plantio — dentro da divisa (prende/margeia), não cobre outra
                // exclusão; PODE ficar dentro de área de plantio/talhão (é um buraco, descontado)
                $pontos = \App\Services\CroquiService::prenderNaDivisa($pontos, $divisa);
                $pontos = \App\Services\CroquiService::margearDivisa($pontos, $divisa);
                $pontos = $this->semSobreporExclusoes($pontos, $imovelId, $alvoId);
                $this->exigirLinhasDentro($pontos, $divisa);
            } else {
                $pontos = \App\Services\CroquiService::prenderNaDivisa($pontos, $divisa);
                // Margeia a divisa: reta entre dois pontos da borda que sairia do CAR vira o caminho da borda
                $pontos = \App\Services\CroquiService::margearDivisa($pontos, $divisa);
                // v44: uma área de plantio NÃO cobre outra (mesma regra dos talhões)
                $pontos = $this->semSobreporAreasPlantio($pontos, $imovelId, $alvoId);
                $this->exigirLinhasDentro($pontos, $divisa);
                // v45: a área de plantio NÃO deixa para fora os talhões hospedados nela (bloqueio);
                // talhão legado (sem área) fora de todas as áreas segue como aviso
                $foraDaArea = [];
                $areasOutras = [];
                foreach (\App\Services\AreaPlantioService::areasDoImovel($imovelId) as $a) {
                    if ((int) $a['id'] !== $alvoId) {
                        $areasOutras[] = json_decode((string) $a['contorno'], true) ?: [];
                    }
                }
                foreach ($this->talhoesDoImovel($imovelId, true) as $t) {
                    $pts = json_decode((string) $t['contorno'], true) ?: [];
                    if (!$pts) {
                        continue;
                    }
                    if ($alvoId > 0 && (int) ($t['area_plantio_id'] ?? 0) === $alvoId) {
                        if (\App\Services\CroquiService::pontosFora($pts, $pontos, \App\Services\CroquiService::TOLERANCIA_SOBREPOSICAO_M)) {
                            $foraDaArea[] = $t['nome'];
                        }
                    } elseif (\App\Services\AreaPlantioService::pontosForaDasAreas($pts, array_merge($areasOutras, [$pontos]))) {
                        $avisos[] = $t['nome'];
                    }
                }
                if ($foraDaArea) {
                    json_erro('A área de plantio deixaria para fora o(s) talhão(ões) ' . implode(', ', $foraDaArea)
                        . '. Os talhões ficam dentro da área de plantio — amplie a área ou ajuste os talhões antes.');
                }
            }
        }

        $areaGps = \App\Services\CroquiService::areaHa($pontos);
        $plantioRow = null;
        if ($tipo === 'plantio') {
            $nome = mb_substr(trim($_POST['nome'] ?? ''), 0, 120);
            // v47: uso (lavoura/perene/reflorestamento) + cultura da área
            [$uso, $culturaId] = $this->usoECulturaDaArea($alvoId > 0);
            if ($alvoId > 0) {
                Database::executar(
                    'UPDATE areas_plantio SET contorno = ?, area_gps = ?' . ($nome !== '' ? ', nome = ?' : '') . ($uso !== null ? ', uso = ?, cultura_id = ?' : '') . ' WHERE id = ?',
                    array_merge([json_encode($pontos), $areaGps], $nome !== '' ? [$nome] : [], $uso !== null ? [$uso, $culturaId] : [], [$alvoId])
                );
            } else {
                $qtd = (int) Database::valor('SELECT COUNT(*) FROM areas_plantio WHERE imovel_id = ?', [$imovelId]);
                if ($nome === '') {
                    $nome = 'Área de plantio' . ($qtd > 0 ? ' ' . ($qtd + 1) : '');
                }
                Database::executar(
                    'INSERT INTO areas_plantio (imovel_id, nome, uso, cultura_id, contorno, area_gps, ordem) VALUES (?,?,?,?,?,?,?)',
                    [$imovelId, $nome, $uso ?? 'lavoura', $culturaId, json_encode($pontos), $areaGps, $qtd + 1]
                );
                $alvoId = Database::ultimoId();
            }
            $plantioRow = $this->areaPlantioRow($alvoId);
        } elseif ($tipo === 'exclusao') {
            // v46: área de não plantio — nome + tipo (mata, app, acude, sede, estrada, outro)
            $nome = mb_substr(trim($_POST['nome'] ?? ''), 0, 120);
            $tipoEx = (string) ($_POST['tipo_exclusao'] ?? '');
            if (!isset(\App\Services\AreaPlantioService::TIPOS_NAO_PLANTIO[$tipoEx])) {
                $tipoEx = $alvoId > 0 ? null : 'mata';
            }
            if ($alvoId > 0) {
                Database::executar(
                    'UPDATE areas_nao_plantio SET contorno = ?, area_gps = ?' . ($nome !== '' ? ', nome = ?' : '') . ($tipoEx !== null ? ', tipo = ?' : '') . ' WHERE id = ?',
                    array_merge([json_encode($pontos), $areaGps], $nome !== '' ? [$nome] : [], $tipoEx !== null ? [$tipoEx] : [], [$alvoId])
                );
            } else {
                $qtd = (int) Database::valor('SELECT COUNT(*) FROM areas_nao_plantio WHERE imovel_id = ?', [$imovelId]);
                if ($nome === '') {
                    $nome = \App\Services\AreaPlantioService::rotuloTipo($tipoEx) . ($qtd > 0 ? ' ' . ($qtd + 1) : '');
                }
                Database::executar(
                    'INSERT INTO areas_nao_plantio (imovel_id, nome, tipo, contorno, area_gps, ordem) VALUES (?,?,?,?,?,?)',
                    [$imovelId, $nome, $tipoEx, json_encode($pontos), $areaGps, $qtd + 1]
                );
                $alvoId = Database::ultimoId();
            }
            $exclusaoRow = Database::um('SELECT id, nome, tipo, contorno, area_gps FROM areas_nao_plantio WHERE id = ?', [$alvoId]);
        } else {
            Database::executar(
                "UPDATE {$tabela} SET {$colContorno} = ?, {$colArea} = ? WHERE id = ?",
                [json_encode($pontos), $areaGps, $alvoId]
            );
            if ($tipo === 'talhao' && $hostTalhao !== null) {
                Database::executar('UPDATE talhoes SET area_plantio_id = ? WHERE id = ?', [(int) $hostTalhao['id'], $alvoId]);
            }
            // Opcional: assumir a área medida como a área oficial (total ou do talhão)
            if ($colOficial !== null && (int) ($_POST['usar_area'] ?? 0) === 1 && $areaGps > 0) {
                Database::executar("UPDATE {$tabela} SET {$colOficial} = ? WHERE id = ?", [$areaGps, $alvoId]);
            }
        }
        if ($tipo === 'talhao' || $tipo === 'exclusao') {
            // v46: talhoes.area_ha = área LÍQUIDA (medida − não plantio dentro dele)
            \App\Services\AreaPlantioService::sincronizarLiquidas($imovelId);
        }
        // Divisa vinda do CAR (identificação por GPS) traz o número do imóvel
        if ($tipo === 'imovel' && trim($_POST['car_numero'] ?? '') !== '') {
            // Divisa adotada do CAR: a área total do imóvel é a medida por ela (oficial)
            Database::executar('UPDATE imoveis SET car_numero = ?, area_ha = ? WHERE id = ?',
                [mb_substr(trim($_POST['car_numero']), 0, 60), $areaGps, $alvoId]);
            $this->municipioDoCar($alvoId, trim($_POST['car_numero'])); // município vem do nº do CAR
        }
        if ($tipo === 'imovel') {
            // área total da propriedade = soma dos CARs
            \App\Services\AreaPlantioService::sincronizarPropriedade((int) $imovel['propriedade_id']);
        }
        sync_confirmar($_POST['uuid_offline'] ?? null);
        auditar('salvar', 'croqui', $alvoId, $rotulo . ' · ' . count($pontos) . " pontos · {$areaGps} ha");
        $resposta = ['area_gps' => $areaGps, 'talhoes_fora' => $avisos, 'plantio' => $plantioRow];
        if ($tipo === 'exclusao' && !empty($exclusaoRow)) {
            $resposta['exclusao'] = ['id' => (int) $exclusaoRow['id'], 'nome' => $exclusaoRow['nome'], 'tipo' => $exclusaoRow['tipo'],
                'contorno' => $exclusaoRow['contorno'], 'area_gps' => (float) $exclusaoRow['area_gps']];
        }
        if ($tipo === 'talhao') {
            $resposta['area_ha'] = (float) Database::valor('SELECT area_ha FROM talhoes WHERE id = ?', [$alvoId]);
        }
        json_ok($resposta);
    }

    /** v46: renomeia / troca o tipo de uma área de não plantio (croqui). */
    public function renomearExclusao(): void
    {
        Permissoes::exigirInterno();
        $id = (int) ($_POST['id'] ?? 0);
        $x = Database::um('SELECT * FROM areas_nao_plantio WHERE id = ?', [$id]);
        if (!$x) {
            json_erro('Área de não plantio não encontrada.', 404);
        }
        $this->imovelDaCarteira((int) $x['imovel_id']);
        $nome = mb_substr(trim($_POST['nome'] ?? ''), 0, 120) ?: (string) $x['nome'];
        $tipo = (string) ($_POST['tipo'] ?? $x['tipo']);
        if (!isset(\App\Services\AreaPlantioService::TIPOS_NAO_PLANTIO[$tipo])) {
            json_erro('Tipo de área de não plantio inválido.');
        }
        Database::executar('UPDATE areas_nao_plantio SET nome = ?, tipo = ? WHERE id = ?', [$nome, $tipo, $id]);
        auditar('salvar', 'croqui', $id, 'área de não plantio: ' . $nome . ' (' . $tipo . ')');
        json_ok(['id' => $id, 'nome' => $nome, 'tipo' => $tipo]);
    }

    /**
     * v47: uso (lavoura/perene/reflorestamento) + cultura vindos do formulário do croqui.
     * Devolve [uso|null, cultura_id|null]; uso null = não informado (mantém o gravado ao
     * editar; 'lavoura' ao criar). Cultura só vale para perene/reflorestamento e precisa existir.
     */
    private function usoECulturaDaArea(bool $editando): array
    {
        $uso = (string) ($_POST['uso'] ?? '');
        if (!isset(\App\Services\AreaPlantioService::USOS_AREA[$uso])) {
            return [$editando ? null : 'lavoura', null];
        }
        $culturaId = (int) ($_POST['cultura_id'] ?? 0);
        if ($uso === 'lavoura' || $culturaId <= 0 || !Database::valor('SELECT 1 FROM culturas WHERE id = ?', [$culturaId])) {
            $culturaId = null;
        }
        return [$uso, $culturaId];
    }

    /** Linha de areas_plantio pronta para o croqui (com uso e cultura por nome). */
    private function areaPlantioRow(int $id): ?array
    {
        $a = Database::um(
            'SELECT a.id, a.nome, a.uso, a.cultura_id, cu.nome AS cultura, a.contorno, a.area_gps
               FROM areas_plantio a LEFT JOIN culturas cu ON cu.id = a.cultura_id WHERE a.id = ?', [$id]
        );
        return $a ? ['id' => (int) $a['id'], 'nome' => $a['nome'], 'uso' => $a['uso'] ?: 'lavoura', 'cultura_id' => (int) ($a['cultura_id'] ?? 0) ?: null,
            'cultura' => $a['cultura'], 'contorno' => $a['contorno'], 'area_gps' => (float) $a['area_gps']] : null;
    }

    /** v44: renomeia uma área de plantio (croqui → botão "Renomear"); v47: também troca uso/cultura. */
    public function renomearAreaPlantio(): void
    {
        Permissoes::exigirInterno();
        $id = (int) ($_POST['id'] ?? 0);
        $area = Database::um('SELECT * FROM areas_plantio WHERE id = ?', [$id]);
        if (!$area) {
            json_erro('Área de plantio não encontrada.', 404);
        }
        $this->imovelDaCarteira((int) $area['imovel_id']);
        $nome = mb_substr(trim($_POST['nome'] ?? ''), 0, 120) ?: (string) $area['nome'];
        [$uso, $culturaId] = $this->usoECulturaDaArea(true);
        if ($uso !== null) {
            Database::executar('UPDATE areas_plantio SET nome = ?, uso = ?, cultura_id = ? WHERE id = ?', [$nome, $uso, $culturaId, $id]);
        } else {
            Database::executar('UPDATE areas_plantio SET nome = ? WHERE id = ?', [$nome, $id]);
        }
        auditar('salvar', 'croqui', $id, 'área de plantio: ' . $nome . ($uso !== null ? " ({$uso})" : ''));
        json_ok($this->areaPlantioRow($id));
    }

    /**
     * Importa a divisa oficial da propriedade a partir do shapefile do CAR
     * (arquivo .zip baixado da consulta pública do SICAR). Desenha o perímetro
     * no croqui (mesma tabela/área do croqui manual) — o servidor recalcula a área.
     */
    public function importarCar(): void
    {
        Permissoes::exigirInterno();
        // v40: a divisa do CAR é do IMÓVEL (imovel_id; propriedade_id cai no 1º imóvel)
        $imovel = $this->imovelAlvo();
        $imovelId = (int) $imovel['id'];
        $propId = (int) $imovel['propriedade_id'];

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
            $lido = \App\Services\ShapefileService::lerCarZip($_FILES['arquivo']['tmp_name']);
            $pontos = \App\Services\CroquiService::validarContorno(json_encode($lido['contorno']));
        } catch (\Exception $e) {
            json_erro($e->getMessage());
        }

        $areaGps = \App\Services\CroquiService::areaHa($pontos);

        // A divisa do CAR é a verdade oficial: se algum talhão já desenhado ficar
        // para fora, avisamos (não bloqueia — deixa o gestor ajustar o talhão).
        $fora = [];
        foreach ($this->talhoesDoImovel($imovelId, true) as $t) {
            $pts = json_decode((string) $t['contorno'], true) ?: [];
            if ($pts && \App\Services\CroquiService::pontosFora($pts, $pontos)) {
                $fora[] = $t['nome'];
            }
        }

        // Número do CAR: usa o que o usuário digitou; senão, o código lido do próprio arquivo (.dbf)
        $car = trim($_POST['car_numero'] ?? '') ?: (string) ($lido['cod'] ?? '');
        // A divisa do CAR é a verdade oficial: a área total do imóvel passa a ser a MEDIDA por ela
        if ($car !== '') {
            Database::executar(
                'UPDATE imoveis SET contorno = ?, area_gps = ?, area_ha = ?, car_numero = ? WHERE id = ?',
                [json_encode($pontos), $areaGps, $areaGps, mb_substr($car, 0, 60), $imovelId]
            );
        } else {
            Database::executar(
                'UPDATE imoveis SET contorno = ?, area_gps = ?, area_ha = ? WHERE id = ?',
                [json_encode($pontos), $areaGps, $areaGps, $imovelId]
            );
        }
        // Município: primeiro pelo nº do CAR (IBGE embutido → lista pré-cadastrada);
        // na falta, o nome lido do .dbf preenche imóvel e propriedade se estiverem vazios
        $munCar = $car !== '' ? $this->municipioDoCar($imovelId, $car) : null;
        if ($munCar === null && !empty($lido['municipio'])) {
            $codNome = \App\Services\MunicipiosSul::codigoPorNome((string) $lido['municipio'], $lido['uf'] ?? null);
            $munLista = \App\Services\MunicipiosSul::porCodigo($codNome);
            $mun = $munLista['nome'] ?? mb_substr((string) $lido['municipio'], 0, 120);
            Database::executar(
                "UPDATE imoveis SET municipio = ?, cod_ibge = COALESCE(?, cod_ibge), uf = COALESCE(?, uf)
                  WHERE id = ? AND (municipio IS NULL OR municipio = '')",
                [$mun, $munLista['ibge'] ?? null, $munLista['uf'] ?? ($lido['uf'] ?? null), $imovelId]
            );
            Database::executar(
                "UPDATE propriedades SET municipio = ? WHERE id = ? AND (municipio IS NULL OR municipio = '')", [$mun, $propId]
            );
        }
        \App\Services\AreaPlantioService::sincronizarPropriedade($propId); // área total da propriedade = soma dos CARs
        auditar('importar', 'croqui', $imovelId, 'CAR shapefile · imóvel · ' . count($pontos) . " pontos · {$areaGps} ha");
        json_ok([
            'area_gps' => $areaGps, 'pontos' => count($pontos), 'talhoes_fora' => $fora,
            'car_numero' => $car ?: null,
            'municipio' => $lido['municipio'] ?? null, 'uf' => $lido['uf'] ?? null,
        ]);
    }

    /**
     * Identifica o imóvel do CAR que contém o ponto (GPS) — base do município.
     * Usado pelo croqui ("CAR aqui") para puxar a divisa oficial da posição atual.
     */
    public function carPorPonto(): void
    {
        Permissoes::exigirInterno();
        $lat = ($_GET['lat'] ?? '') !== '' ? (float) $_GET['lat'] : null;
        $lng = ($_GET['lng'] ?? '') !== '' ? (float) $_GET['lng'] : null;
        if ($lat === null || $lng === null) {
            json_erro('Posição (GPS) não informada.');
        }
        $imovel = \App\Services\CarService::imovelNoPonto($lat, $lng, \App\Services\CarService::TOLERANCIA_PONTO_M);
        // contexto/diagnóstico ajudam o cliente a explicar quando não acha:
        // base ausente na região x ponto fora da divisa (com dist. e município do mais próximo)
        $contexto = 'ok';
        $diagnostico = null;
        if ($imovel === null) {
            $diagnostico = \App\Services\CarService::maisProximo($lat, $lng);
            $contexto = $diagnostico !== null ? 'fora_do_poligono' : 'sem_base_perto';
        } elseif (!empty($imovel['aproximado'])) {
            $contexto = 'aproximado';
        }
        json_ok(['imovel' => $imovel, 'contexto' => $contexto, 'diagnostico' => $diagnostico]);
    }

    /**
     * "Ir para": localiza uma região pelo endereço (município/UF/linha). Tenta os
     * dados LOCAIS (coordenadas de clientes na linha, centro dos imóveis do CAR do
     * município) e devolve {lat,lng,bbox,fonte}. Se não achar, devolve lat:null +
     * `geocode` (consultas) + `geocoder_base` para o CLIENTE geocodificar pelo
     * navegador (como o Google Maps) — a saída do servidor no Railway pode estar
     * bloqueada, mas o navegador tem internet (os tiles carregam por lá).
     */
    public function localizarArea(): void
    {
        Permissoes::exigirInterno();
        $mun = trim($_GET['municipio'] ?? '');
        $uf = strtoupper(substr(trim($_GET['uf'] ?? ''), 0, 2));
        $linha = trim($_GET['linha'] ?? '');
        if ($mun === '' && $linha === '') {
            json_erro('Informe ao menos o município.');
        }
        $bbox = fn ($r) => [(float) $r['mila'], (float) $r['milo'], (float) $r['mala'], (float) $r['malo']];
        $tem = fn ($r) => $r && $r['la'] !== null;
        $munN = self::semAcento($mun);   // normalizado (maiúsculo, sem acento) p/ comparar
        $linhaN = self::semAcento($linha);
        $estadoNome = self::ufParaEstado($uf); // "SC" -> "Santa Catarina" (melhora o geocoder)
        $LIN = self::semAcentoSql('linha');
        $MUN = self::semAcentoSql('municipio');
        $sqlCli = 'SELECT AVG(latitude) la, AVG(longitude) lo, MIN(latitude) mila, MAX(latitude) mala, MIN(longitude) milo, MAX(longitude) malo FROM clientes WHERE ';
        $sqlCar = 'SELECT AVG((min_lat+max_lat)/2) la, AVG((min_lng+max_lng)/2) lo, MIN(min_lat) mila, MAX(max_lat) mala, MIN(min_lng) milo, MAX(max_lng) malo FROM car_imoveis WHERE ';

        // A) Linha (mais específico): média das coords dos clientes na linha — sem acento e "contém"
        // ("barro preto" acha "Linha Barro Preto"; "santo antonio" acha "Santo Antônio")
        if ($linha !== '') {
            $cond = "latitude IS NOT NULL AND {$LIN} LIKE CONCAT('%', ?, '%')";
            $par = [$linhaN];
            if ($mun !== '') { $cond .= " AND {$MUN} LIKE CONCAT('%', ?, '%')"; $par[] = $munN; }
            $r = Database::um($sqlCli . $cond, $par);
            if ($tem($r)) {
                json_ok(['lat' => (float) $r['la'], 'lng' => (float) $r['lo'], 'bbox' => $bbox($r), 'fonte' => 'linha']);
            }
        }

        // Centro do município nos dados locais (CAR ou clientes). Quando NÃO há
        // linha, é a própria resposta; quando HÁ linha, vira RESERVA — primeiro
        // tentamos geocodificar a linha (mais específica) e só caímos aqui se falhar.
        $fallback = null;
        if ($mun !== '') {
            // B) Município via base do CAR (não depende de coordenada de cliente). Acha o NOME
            // exato na base (sem acento, exato→contém) e consulta por igualdade (usa índice).
            $muns = Database::todos('SELECT DISTINCT municipio FROM car_imoveis' . ($uf !== '' ? ' WHERE uf = ?' : ''), $uf !== '' ? [$uf] : []);
            $match = null;
            foreach ($muns as $m) { if (self::semAcento($m['municipio']) === $munN) { $match = $m['municipio']; break; } }
            if ($match === null) {
                foreach ($muns as $m) { if ($munN !== '' && str_contains(self::semAcento($m['municipio']), $munN)) { $match = $m['municipio']; break; } }
            }
            if ($match !== null) {
                $cond = 'municipio = ?';
                $par = [$match];
                if ($uf !== '') { $cond .= ' AND uf = ?'; $par[] = $uf; }
                $r = Database::um($sqlCar . $cond, $par);
                if ($tem($r)) {
                    if ($linha === '') {
                        json_ok(['lat' => (float) $r['la'], 'lng' => (float) $r['lo'], 'bbox' => $bbox($r), 'fonte' => 'car']);
                    }
                    $fallback = ['lat' => (float) $r['la'], 'lng' => (float) $r['lo'], 'bbox' => $bbox($r), 'fonte' => 'car'];
                }
            }
            // C) Município via clientes com coordenadas — sem acento (exato, depois "contém")
            if ($fallback === null) {
                foreach (["{$MUN} = ?", "{$MUN} LIKE CONCAT('%', ?, '%')"] as $cmp) {
                    $r = Database::um($sqlCli . "latitude IS NOT NULL AND {$cmp}", [$munN]);
                    if ($tem($r)) {
                        if ($linha === '') {
                            json_ok(['lat' => (float) $r['la'], 'lng' => (float) $r['lo'], 'bbox' => $bbox($r), 'fonte' => 'clientes']);
                        }
                        $fallback = ['lat' => (float) $r['la'], 'lng' => (float) $r['lo'], 'bbox' => $bbox($r), 'fonte' => 'clientes'];
                        break;
                    }
                }
            }
        }
        // Não achou a linha nos dados locais: devolve as consultas para o CLIENTE
        // geocodificar pelo navegador (que tem internet — como os tiles; a saída do
        // servidor no Railway pode estar bloqueada). geocoder_url vazio desliga.
        // Quando HÁ linha, tentamos "linha, município, estado" PRIMEIRO (mais específico)
        // e "município, estado" como reserva — ambos validados pelo estado no navegador
        // (evita casar município de mesmo nome em outra UF). Se o buscador não achar, o
        // cliente usa o $fallback (centro do município nos dados locais).
        $geocoderBase = trim(\App\Services\ConfigService::obter('geocoder_url', 'https://nominatim.openstreetmap.org/search'));
        $queries = [];
        if ($geocoderBase !== '' && $mun !== '') {
            if ($linha !== '') {
                $queries[] = implode(', ', array_filter([$linha, $mun, $estadoNome, 'Brasil'], fn ($v) => $v !== ''));
            }
            $queries[] = implode(', ', array_filter([$mun, $estadoNome, 'Brasil'], fn ($v) => $v !== ''));
        }
        $temCarUf = $uf !== '' && (int) Database::valor('SELECT COUNT(*) FROM car_imoveis WHERE uf = ?', [$uf]) > 0;
        $diag = $mun !== ''
            ? ('Não achei “' . ($linha !== '' ? $linha . ', ' : '') . $mun . ($uf ? '/' . $uf : '') . '”. '
                . ($temCarUf ? 'Confira o nome do município ou ' : '')
                . 'importe a base do CAR desse município na Integração.')
            : 'Não achei essa linha. Informe também o município.';
        json_ok(['lat' => null, 'geocode' => $queries, 'geocoder_base' => $geocoderBase,
            'estado_alvo' => $estadoNome, 'fallback' => $fallback, 'diagnostico' => $diag]);
    }

    /** Acentos PT-BR (maiúsculas) → letra base. Usado p/ comparar município/linha sem acento. */
    private const ACENTOS = [
        'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
        'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ç' => 'C', 'Ñ' => 'N',
    ];

    /** Normaliza um texto para comparação: maiúsculo, sem acento, sem espaços nas pontas. */
    private static function semAcento(string $s): string
    {
        return strtr(mb_strtoupper(trim($s), 'UTF-8'), self::ACENTOS);
    }

    /** UF (2 letras) → nome do estado, para o geocoder ("SC" → "Santa Catarina"). */
    private const ESTADOS_NOME = [
        'AC' => 'Acre', 'AL' => 'Alagoas', 'AP' => 'Amapá', 'AM' => 'Amazonas', 'BA' => 'Bahia', 'CE' => 'Ceará',
        'DF' => 'Distrito Federal', 'ES' => 'Espírito Santo', 'GO' => 'Goiás', 'MA' => 'Maranhão', 'MT' => 'Mato Grosso',
        'MS' => 'Mato Grosso do Sul', 'MG' => 'Minas Gerais', 'PA' => 'Pará', 'PB' => 'Paraíba', 'PR' => 'Paraná',
        'PE' => 'Pernambuco', 'PI' => 'Piauí', 'RJ' => 'Rio de Janeiro', 'RN' => 'Rio Grande do Norte',
        'RS' => 'Rio Grande do Sul', 'RO' => 'Rondônia', 'RR' => 'Roraima', 'SC' => 'Santa Catarina',
        'SP' => 'São Paulo', 'SE' => 'Sergipe', 'TO' => 'Tocantins',
    ];

    private static function ufParaEstado(string $uf): string
    {
        return self::ESTADOS_NOME[strtoupper(trim($uf))] ?? trim($uf);
    }

    /** Expressão SQL que devolve a coluna em maiúsculo e sem acento (mesma normalização). */
    private static function semAcentoSql(string $col): string
    {
        $e = "UPPER({$col})";
        foreach (self::ACENTOS as $de => $para) {
            $e = "REPLACE({$e}, '{$de}', '{$para}')";
        }
        return $e;
    }

    /**
     * Imóveis do CAR numa área (para o overlay do croqui — o técnico vê todos
     * e toca no que é do produtor). Recebe lat/lng do centro + raio (m).
     */
    public function carProximos(): void
    {
        Permissoes::exigirInterno();
        $municipio = trim($_GET['municipio'] ?? ''); // filtro opcional (só esse município)
        // Preferência: CAIXA visível do mapa (menos dados — só a área na tela);
        // fallback para lat/lng/raio (compatibilidade).
        $temBBox = ($_GET['minLat'] ?? '') !== '' && ($_GET['maxLat'] ?? '') !== ''
            && ($_GET['minLng'] ?? '') !== '' && ($_GET['maxLng'] ?? '') !== '';
        if ($temBBox) {
            $minLat = (float) $_GET['minLat'];
            $minLng = (float) $_GET['minLng'];
            $maxLat = (float) $_GET['maxLat'];
            $maxLng = (float) $_GET['maxLng'];
            // guarda contra caixa absurda (zoom muito longe): limita o span
            if (($maxLat - $minLat) > 1.5 || ($maxLng - $minLng) > 1.5) {
                $cLat = ($minLat + $maxLat) / 2;
                $cLng = ($minLng + $maxLng) / 2;
                $minLat = $cLat - 0.75; $maxLat = $cLat + 0.75;
                $minLng = $cLng - 0.75; $maxLng = $cLng + 0.75;
            }
            json_ok(['imoveis' => \App\Services\CarService::imoveisNaBBox($minLat, $minLng, $maxLat, $maxLng, $municipio ?: null, 800)]);
        }
        $lat = ($_GET['lat'] ?? '') !== '' ? (float) $_GET['lat'] : null;
        $lng = ($_GET['lng'] ?? '') !== '' ? (float) $_GET['lng'] : null;
        if ($lat === null || $lng === null) {
            json_erro('Posição não informada.');
        }
        $raio = min(8000.0, max(500.0, (float) ($_GET['raio'] ?? 3000)));
        $grau = $raio / 111000.0;
        json_ok(['imoveis' => \App\Services\CarService::imoveisNaBBox($lat - $grau, $lng - $grau, $lat + $grau, $lng + $grau, $municipio ?: null, 800)]);
    }

    /**
     * Grava o número do CAR no cadastro da propriedade assim que o imóvel é
     * identificado (pelo croqui — "CAR aqui"/"CAR pela sede"/auto), sem depender
     * de salvar o desenho. Idempotente (só atualiza o campo).
     */
    public function salvarCarNumero(): void
    {
        Permissoes::exigirInterno();
        sync_iniciar($_POST['uuid_offline'] ?? null);
        $imovel = $this->imovelAlvo(); // v40: o nº do CAR é do imóvel (valida a carteira)
        $imovelId = (int) $imovel['id'];
        $car = trim($_POST['car_numero'] ?? '');
        if ($car === '') {
            json_erro('Número do CAR não informado.');
        }
        $car = mb_substr($car, 0, 60);
        Database::executar('UPDATE imoveis SET car_numero = ? WHERE id = ?', [$car, $imovelId]);
        $mun = $this->municipioDoCar($imovelId, $car); // município vem do nº do CAR
        auditar('salvar', 'imovel', $imovelId, 'nº do CAR ' . $car . ' (identificado pela base do CAR)');
        sync_confirmar($_POST['uuid_offline'] ?? null);
        json_ok(['car_numero' => $car, 'municipio' => $mun['nome'] ?? null, 'uf' => $mun['uf'] ?? null]);
    }

    /* ------------------------- imóveis (CAR) — v40 ------------------------- */

    /**
     * Imóvel alvo de uma requisição: `imovel_id` (preferido) ou `propriedade_id`
     * (compatibilidade — fila offline/links antigos), que cai no PRIMEIRO imóvel
     * da propriedade, criando-o se a propriedade ainda não tiver nenhum.
     */
    private function imovelAlvo(): array
    {
        $imovelId = (int) ($_POST['imovel_id'] ?? $_GET['imovel_id'] ?? 0);
        if ($imovelId > 0) {
            return $this->imovelDaCarteira($imovelId);
        }
        $propId = (int) ($_POST['propriedade_id'] ?? $_GET['propriedade_id'] ?? 0);
        $this->propriedadeDaCarteira($propId);
        return $this->primeiroImovel($propId);
    }

    /** Garante que o imóvel pertence a uma propriedade de cliente da carteira. */
    private function imovelDaCarteira(int $imovelId): array
    {
        $imovel = Database::um('SELECT * FROM imoveis WHERE id = ?', [$imovelId]);
        if (!$imovel) {
            json_erro('Imóvel não encontrado.', 404);
        }
        $this->propriedadeDaCarteira((int) $imovel['propriedade_id']);
        return $imovel;
    }

    /** Primeiro imóvel da propriedade (cria um se não houver — propriedade antiga/nova). */
    private function primeiroImovel(int $propId): array
    {
        $imovel = Database::um('SELECT * FROM imoveis WHERE propriedade_id = ? ORDER BY ordem, id LIMIT 1', [$propId]);
        if ($imovel) {
            return $imovel;
        }
        $prop = Database::um('SELECT * FROM propriedades WHERE id = ?', [$propId]);
        if (!$prop) {
            json_erro('Propriedade não encontrada.', 404);
        }
        Database::executar(
            'INSERT INTO imoveis (propriedade_id, car_numero, municipio, area_ha, contorno, area_gps)
             VALUES (?,?,?,?,?,?)',
            [$propId, $prop['car_numero'] ?? null, $prop['municipio'] ?? null, (float) $prop['area_ha'],
                $prop['contorno'] ?? null, $prop['area_gps'] ?? null]
        );
        return Database::um('SELECT * FROM imoveis WHERE id = ?', [Database::ultimoId()]);
    }

    /**
     * Talhões de um imóvel. Talhão legado (imovel_id NULL) conta no PRIMEIRO imóvel
     * da propriedade. $soComContorno filtra os já desenhados no croqui.
     */
    private function talhoesDoImovel(int $imovelId, bool $soComContorno = false): array
    {
        $propId = (int) Database::valor('SELECT propriedade_id FROM imoveis WHERE id = ?', [$imovelId]);
        $primeiroId = (int) Database::valor('SELECT MIN(id) FROM imoveis WHERE propriedade_id = ?', [$propId]);
        return Database::todos(
            'SELECT id, nome, contorno, area_gps, area_ha, area_plantio_id FROM talhoes
              WHERE propriedade_id = ? AND (imovel_id = ? OR (imovel_id IS NULL AND ? = ?))'
            . ($soComContorno ? ' AND contorno IS NOT NULL' : '') . ' ORDER BY nome',
            [$propId, $imovelId, $imovelId, $primeiroId]
        );
    }

    /**
     * REGRA (teste de campo): talhão não cobre outro talhão do mesmo imóvel.
     * 1) ponto dentro de um vizinho é PUXADO para a borda dele (desenhar colado
     *    fica automático); 2) se ainda houver cruzamento, recusa com os nomes.
     * Devolve os pontos corrigidos.
     */
    private function semSobreporVizinhos(array $pontos, int $imovelId, int $ignorarTalhaoId = 0): array
    {
        $vizinhos = [];
        foreach ($this->talhoesDoImovel($imovelId, true) as $t) {
            if ((int) $t['id'] === $ignorarTalhaoId) {
                continue;
            }
            $pts = json_decode((string) $t['contorno'], true) ?: [];
            if (count($pts) >= 3) {
                $vizinhos[] = ['nome' => $t['nome'], 'pontos' => $pts];
            }
        }
        return $this->semSobrepor($pontos, $vizinhos, 'talhão');
    }

    /** v44: mesma regra para as áreas de plantio do imóvel (uma não cobre a outra). */
    private function semSobreporAreasPlantio(array $pontos, int $imovelId, int $ignorarAreaId = 0): array
    {
        $vizinhos = [];
        foreach (\App\Services\AreaPlantioService::areasDoImovel($imovelId) as $a) {
            if ((int) $a['id'] === $ignorarAreaId) {
                continue;
            }
            $pts = json_decode((string) $a['contorno'], true) ?: [];
            if (count($pts) >= 3) {
                $vizinhos[] = ['nome' => $a['nome'], 'pontos' => $pts];
            }
        }
        return $this->semSobrepor($pontos, $vizinhos, 'área de plantio');
    }

    /** v46: uma área de não plantio não cobre outra (mesma regra; pode ficar dentro de plantio/talhão). */
    private function semSobreporExclusoes(array $pontos, int $imovelId, int $ignorarId = 0): array
    {
        $vizinhos = [];
        foreach (\App\Services\AreaPlantioService::exclusoesDoImovel($imovelId) as $x) {
            if ((int) $x['id'] === $ignorarId) {
                continue;
            }
            $pts = json_decode((string) $x['contorno'], true) ?: [];
            if (count($pts) >= 3) {
                $vizinhos[] = ['nome' => $x['nome'], 'pontos' => $pts];
            }
        }
        return $this->semSobrepor($pontos, $vizinhos, 'área de não plantio');
    }

    /** Núcleo da regra "não se sobrepõem" (talhões e áreas de plantio). */
    private function semSobrepor(array $pontos, array $vizinhos, string $tipo): array
    {
        // concordância das mensagens: "o talhão / outro" × "a área de plantio / outra"
        $fem = str_starts_with($tipo, 'área');
        $artigo = $fem ? 'a' : 'o';
        $o = $fem ? 'a' : 'o';
        $a = $fem ? 'a' : '';
        $os = $fem ? 'as' : 'os';
        // MESMO desenho de um talhão já cadastrado (toque repetido em Salvar, reenvio):
        // não é "vizinho que divide a linha" — é duplicata. Recusa com o nome.
        foreach ($vizinhos as $v) {
            if (\App\Services\CroquiService::mesmoContorno($pontos, $v['pontos'])) {
                json_erro("Este desenho é o mesmo d{$artigo} {$tipo} \"" . $v['nome'] . '", que já está cadastrado. '
                    . "Edite {$artigo} {$tipo} existente em vez de criar outr{$o} igual.");
            }
        }
        // Desenho que está (quase) todo dentro de um vizinho: recusa antes de expulsar —
        // senão os pontos iriam todos para a borda e sobraria um talhão sem área.
        $metade = (int) ceil(count($pontos) / 2);
        foreach ($vizinhos as $v) {
            if (count(\App\Services\CroquiService::pontosDentroDe($pontos, $v['pontos'])) >= $metade) {
                json_erro("O desenho está dentro d{$artigo} {$tipo} \"" . $v['nome'] . "\". Um{$a} {$tipo} não pode ficar por cima de outr{$o} — "
                    . "desenhe ao lado ou edite {$artigo} {$tipo} existente.");
            }
        }
        foreach ($vizinhos as $v) {
            $pontos = \App\Services\CroquiService::expulsarDe($pontos, $v['pontos']);
        }
        if (\App\Services\CroquiService::areaHa($pontos) < 0.01) {
            json_erro("Depois de ajustar os pontos para fora d{$os} {$tipo}s vizinh{$o}s, {$artigo} {$tipo} ficou sem área. Desenhe em um espaço livre.");
        }
        $cruzam = [];
        foreach ($vizinhos as $v) {
            if (\App\Services\CroquiService::sobrepoe($pontos, $v['pontos'])) {
                $cruzam[] = $v['nome'];
            }
        }
        if ($cruzam) {
            json_erro(ucfirst($artigo) . " {$tipo} cobre outr{$o} {$tipo}: " . implode(', ', $cruzam)
                . ". Um{$a} {$tipo} não pode passar por cima de outr{$o} — ajuste os pontos em vermelho.");
        }
        return $pontos;
    }

    /**
     * REGRA (teste de campo): NENHUMA LINHA fica fora da área do CAR. Prender os
     * pontos não basta em divisa côncava; `margearDivisa` já corrige sozinha
     * cada linha que sai (recorta pela borda) — isto é só a rede de segurança
     * para o caso raro em que a correção não fecha.
     */
    private function exigirLinhasDentro(array $pontos, array $divisa, string $rotulo = 'área do CAR'): void
    {
        $fora = \App\Services\CroquiService::linhasFora($pontos, $divisa);
        if ($fora) {
            $nums = array_map(fn ($i) => ($i + 1) . '→' . (($i + 1) % count($pontos) + 1), $fora);
            json_erro("Linha fora da {$rotulo} (entre os pontos " . implode(', ', $nums)
                . '). Não foi possível ajustar sozinho — acrescente um ponto na linha vermelha e puxe-o para dentro.');
        }
    }

    /**
     * v45 — REGRA (fluxo guiado): o TALHÃO é desenhado DENTRO de uma ÁREA DE PLANTIO
     * (não só dentro da divisa). Acha a área hospedeira, prende os pontos nela,
     * margeia a borda dela, não sobrepõe outros talhões e nenhuma linha sai dela.
     * Devolve [pontos corrigidos, área hospedeira].
     */
    private function talhaoDentroDaAreaPlantio(array $pontos, int $imovelId, int $talhaoId, ?int $areaAtualId): array
    {
        $areas = \App\Services\AreaPlantioService::areasDoImovel($imovelId);
        if (!$areas) {
            json_erro('Marque primeiro as áreas de plantio deste imóvel (etapa 2 do croqui). O talhão é desenhado dentro de uma área de plantio.');
        }
        $host = \App\Services\AreaPlantioService::areaHospedeira($pontos, $areas, $areaAtualId);
        if ($host === null) {
            json_erro('O talhão precisa ficar dentro de uma área de plantio — desenhe dentro de uma das áreas verdes.');
        }
        $limite = json_decode((string) $host['contorno'], true) ?: [];
        $pontos = \App\Services\CroquiService::prenderNaDivisa($pontos, $limite);
        $pontos = \App\Services\CroquiService::margearDivisa($pontos, $limite);
        $pontos = $this->semSobreporVizinhos($pontos, $imovelId, $talhaoId);
        $this->exigirLinhasDentro($pontos, $limite, 'área de plantio "' . $host['nome'] . '"');
        return [$pontos, $host];
    }

    /** Nome de exibição do imóvel: apelido, senão nº do CAR, senão "Imóvel". */
    public static function rotuloImovel(array $imovel): string
    {
        if (!empty($imovel['nome'])) {
            return (string) $imovel['nome'];
        }
        // Sem apelido: "Imóvel" (o nº do CAR fica na linha própria, com o link — no
        // título ele ocupava três linhas no celular e aparecia duas vezes)
        return 'Imóvel' . (isset($imovel['ordem']) && (int) $imovel['ordem'] > 0 ? ' ' . (int) $imovel['ordem'] : '');
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
        // v40: o nº do CAR saiu da propriedade — fica em cada IMÓVEL (uma propriedade
        // pode ter vários CARs). Propriedade nova já nasce com 1 imóvel para cadastrar.
        // REGRA (teste de campo): a ÁREA da propriedade não é digitada — é a soma dos
        // CARs (AreaPlantioService::sincronizarPropriedade). Município vem da lista
        // pré-cadastrada (nome oficial de MunicipiosSul); texto fora da lista é ignorado.
        $munNome = trim($_POST['municipio'] ?? '');
        $codMun = \App\Services\MunicipiosSul::codigoPorNome($munNome, trim($_POST['uf'] ?? '') ?: null);
        $mun = \App\Services\MunicipiosSul::porCodigo($codMun);
        $dados = [$nome, $mun['nome'] ?? null];
        if ($id > 0) {
            $sql = $mun !== null
                ? 'UPDATE propriedades SET nome=?, municipio=? WHERE id=? AND cliente_id=?'
                : 'UPDATE propriedades SET nome=?, municipio=COALESCE(?, municipio) WHERE id=? AND cliente_id=?';
            Database::executar($sql, array_merge($dados, [$id, $clienteId]));
        } else {
            Database::executar(
                'INSERT INTO propriedades (nome, municipio, area_ha, cliente_id) VALUES (?,?,0,?)',
                array_merge($dados, [$clienteId])
            );
            $id = Database::ultimoId();
            $this->primeiroImovel($id); // cria o 1º imóvel (CAR) da propriedade
        }
        $area = \App\Services\AreaPlantioService::sincronizarPropriedade($id);
        json_ok(['id' => $id, 'area_ha' => $area, 'municipio' => $mun['nome'] ?? null]);
    }

    /** Salva imóvel rural (CAR) da propriedade — v40 (modal AJAX). */
    public function salvarImovel(): void
    {
        Permissoes::exigirInterno();
        $propriedadeId = (int) ($_POST['propriedade_id'] ?? 0);
        $this->propriedadeDaCarteira($propriedadeId);
        $id = (int) ($_POST['id'] ?? 0);
        // REGRA (teste de campo): as áreas do imóvel NÃO são digitadas. A área total
        // vem da divisa do CAR e a área de plantio é desenhada dentro dela (croqui).
        // Este cadastro só guarda nº do CAR, apelido e município.
        $dados = [
            trim($_POST['nome'] ?? '') ? mb_substr(trim($_POST['nome']), 0, 120) : null,
            trim($_POST['car_numero'] ?? '') ? mb_substr(trim($_POST['car_numero']), 0, 60) : null,
        ];
        // Município: LISTA pré-cadastrada (código IBGE) — nunca texto livre. O nº do CAR
        // ("UF-IBGE-hash") identifica o município sozinho e prevalece sobre o select.
        $mun = \App\Services\MunicipiosSul::deCodImovel($dados[1])
            ?: \App\Services\MunicipiosSul::porCodigo(trim($_POST['cod_ibge'] ?? '') ?: null);
        $dados = array_merge($dados, [$mun['nome'] ?? null, $mun['ibge'] ?? null, $mun['uf'] ?? null]);
        $novo = $id <= 0;
        if (!$novo) {
            Database::executar(
                'UPDATE imoveis SET nome=?, car_numero=?, municipio=?, cod_ibge=?, uf=? WHERE id=? AND propriedade_id=?',
                array_merge($dados, [$id, $propriedadeId])
            );
        } else {
            $ordem = (int) Database::valor('SELECT COALESCE(MAX(ordem), 0) + 1 FROM imoveis WHERE propriedade_id = ?', [$propriedadeId]);
            Database::executar(
                'INSERT INTO imoveis (nome, car_numero, municipio, cod_ibge, uf, propriedade_id, ordem) VALUES (?,?,?,?,?,?,?)',
                array_merge($dados, [$propriedadeId, $ordem])
            );
            $id = Database::ultimoId();
        }
        if ($mun !== null) {
            $this->propagarMunicipio($propriedadeId, $mun);
        }
        auditar('salvar', 'imovel', $id, 'propriedade #' . $propriedadeId);
        json_ok(['id' => $id, 'municipio' => $mun['nome'] ?? null, 'uf' => $mun['uf'] ?? null, 'cod_ibge' => $mun['ibge'] ?? null]);
    }

    /**
     * Município identificado pelo nº do CAR: grava no imóvel (nome oficial + IBGE + UF)
     * e propaga à propriedade se ela ainda não tiver município. Devolve o município
     * ou null quando o número não traz um IBGE conhecido (fora do padrão/da tabela).
     */
    private function municipioDoCar(int $imovelId, ?string $car): ?array
    {
        $mun = \App\Services\MunicipiosSul::deCodImovel($car);
        if ($mun === null) {
            return null;
        }
        Database::executar('UPDATE imoveis SET municipio = ?, cod_ibge = ?, uf = ? WHERE id = ?',
            [$mun['nome'], $mun['ibge'], $mun['uf'], $imovelId]);
        $propId = (int) Database::valor('SELECT propriedade_id FROM imoveis WHERE id = ?', [$imovelId]);
        if ($propId > 0) {
            $this->propagarMunicipio($propId, $mun);
        }
        return $mun;
    }

    /** Propriedade sem município herda o do imóvel (não sobrescreve o que já está lá). */
    private function propagarMunicipio(int $propId, array $mun): void
    {
        Database::executar(
            "UPDATE propriedades SET municipio = ? WHERE id = ? AND (municipio IS NULL OR municipio = '')",
            [$mun['nome'], $propId]
        );
    }

    /**
     * Exclui um talhão (pedido do teste de campo: cadastros duplicados no piloto).
     * Talhão com HISTÓRICO (visitas ou lavoura de custo) não se apaga — o histórico
     * aponta para ele; edite/renomeie. Plantios do talhão vão junto (cascata).
     */
    public function excluirTalhao(): void
    {
        Permissoes::exigirInterno();
        [$filtro, $params] = Permissoes::filtroCarteira();
        $id = (int) ($_POST['id'] ?? 0);
        $talhao = Database::um(
            "SELECT t.* FROM talhoes t JOIN propriedades p ON p.id = t.propriedade_id
               JOIN clientes c ON c.id = p.cliente_id WHERE t.id = ? AND {$filtro}",
            array_merge([$id], $params)
        );
        if (!$talhao) {
            json_erro('Talhão não encontrado na sua carteira.', 404);
        }
        $visitas = (int) Database::valor('SELECT COUNT(*) FROM visitas WHERE talhao_id = ?', [$id]);
        $lavouras = (int) Database::valor('SELECT COUNT(*) FROM lavoura_safra WHERE talhao_id = ?', [$id]);
        if ($visitas > 0 || $lavouras > 0) {
            $partes = [];
            if ($visitas > 0) { $partes[] = "{$visitas} visita(s)"; }
            if ($lavouras > 0) { $partes[] = "{$lavouras} lavoura(s) de custo"; }
            json_erro('Este talhão tem histórico (' . implode(' e ', $partes) . ') e não pode ser excluído. Edite o nome, a cultura ou o desenho em vez de excluir.');
        }
        Database::executar('DELETE FROM talhoes WHERE id = ?', [$id]); // plantios vão em cascata
        auditar('excluir', 'talhao', $id, (string) $talhao['nome'] . ' · propriedade #' . (int) $talhao['propriedade_id']);
        json_ok();
    }

    /**
     * Transforma o DESENHO de um talhão na ÁREA DE PLANTIO do imóvel (pedido do teste
     * de campo: "cadastrei errado — os talhões eram a área de plantio"). Substitui a
     * área de plantio atual; opcionalmente exclui o talhão (com as mesmas regras do
     * excluir-talhao: com visita/lavoura de custo ele fica, avisado).
     */
    public function talhaoParaPlantio(): void
    {
        Permissoes::exigirInterno();
        [$filtro, $params] = Permissoes::filtroCarteira();
        $id = (int) ($_POST['id'] ?? 0);
        $talhao = Database::um(
            "SELECT t.* FROM talhoes t JOIN propriedades p ON p.id = t.propriedade_id
               JOIN clientes c ON c.id = p.cliente_id WHERE t.id = ? AND {$filtro}",
            array_merge([$id], $params)
        );
        if (!$talhao) {
            json_erro('Talhão não encontrado na sua carteira.', 404);
        }
        $pontos = !empty($talhao['contorno']) ? (json_decode((string) $talhao['contorno'], true) ?: []) : [];
        if (count($pontos) < 3) {
            json_erro('Este talhão não tem desenho no croqui — não há o que transformar em área de plantio.');
        }
        $imovel = $talhao['imovel_id'] !== null
            ? Database::um('SELECT * FROM imoveis WHERE id = ?', [(int) $talhao['imovel_id']])
            : $this->primeiroImovel((int) $talhao['propriedade_id']);
        $imovelId = (int) $imovel['id'];
        $divisa = !empty($imovel['contorno']) ? (json_decode((string) $imovel['contorno'], true) ?: []) : [];
        if ($divisa) {
            // o talhão já nasceu dentro da divisa; repassa pelas mesmas regras por segurança
            $pontos = \App\Services\CroquiService::prenderNaDivisa($pontos, $divisa);
            $pontos = \App\Services\CroquiService::margearDivisa($pontos, $divisa);
            $this->exigirLinhasDentro($pontos, $divisa);
        }
        // v44: vira uma NOVA área de plantio com o nome do talhão (não substitui as outras);
        // não pode cobrir outra área de plantio já desenhada
        $pontos = $this->semSobreporAreasPlantio($pontos, $imovelId, 0);
        $areaGps = \App\Services\CroquiService::areaHa($pontos);
        $nome = (string) $talhao['nome'];
        if (Database::valor('SELECT 1 FROM areas_plantio WHERE imovel_id = ? AND nome = ?', [$imovelId, $nome])) {
            $nome = mb_substr($nome, 0, 110) . ' (plantio)';
        }
        $qtd = (int) Database::valor('SELECT COUNT(*) FROM areas_plantio WHERE imovel_id = ?', [$imovelId]);
        Database::executar(
            'INSERT INTO areas_plantio (imovel_id, nome, contorno, area_gps, ordem) VALUES (?,?,?,?,?)',
            [$imovelId, $nome, json_encode($pontos), $areaGps, $qtd + 1]
        );
        $plantioId = Database::ultimoId();
        auditar('salvar', 'croqui', $plantioId, 'área de plantio "' . $nome . '" a partir do talhão "' . $talhao['nome'] . "\" · {$areaGps} ha");

        $excluido = false;
        $aviso = '';
        if ((int) ($_POST['excluir'] ?? 0) === 1) {
            $visitas = (int) Database::valor('SELECT COUNT(*) FROM visitas WHERE talhao_id = ?', [$id]);
            $lavouras = (int) Database::valor('SELECT COUNT(*) FROM lavoura_safra WHERE talhao_id = ?', [$id]);
            if ($visitas > 0 || $lavouras > 0) {
                $aviso = 'O talhão "' . $talhao['nome'] . '" tem histórico (' . ($visitas > 0 ? "{$visitas} visita(s)" : "{$lavouras} lavoura(s) de custo")
                    . ') e foi mantido. A área de plantio foi gravada mesmo assim.';
            } else {
                Database::executar('DELETE FROM talhoes WHERE id = ?', [$id]); // plantios vão em cascata
                auditar('excluir', 'talhao', $id, (string) $talhao['nome'] . ' · virou área de plantio do imóvel #' . $imovelId);
                $excluido = true;
            }
        }
        json_ok(['imovel_id' => $imovelId, 'plantio_id' => $plantioId, 'nome' => $nome, 'area_gps' => $areaGps, 'excluido' => $excluido, 'aviso' => $aviso]);
    }

    /**
     * Exclui uma propriedade SEM talhões e SEM visitas (os imóveis/CARs vão em cascata).
     * Com talhões ou visitas, o histórico manda: exclua/mova os talhões antes.
     */
    public function excluirPropriedade(): void
    {
        Permissoes::exigirInterno();
        $prop = $this->propriedadeDaCarteira((int) ($_POST['id'] ?? 0));
        $id = (int) $prop['id'];
        $talhoes = (int) Database::valor('SELECT COUNT(*) FROM talhoes WHERE propriedade_id = ?', [$id]);
        $visitas = (int) Database::valor('SELECT COUNT(*) FROM visitas WHERE propriedade_id = ?', [$id]);
        if ($talhoes > 0) {
            json_erro("Esta propriedade tem {$talhoes} talhão(ões). Exclua ou mova os talhões antes de excluir a propriedade.");
        }
        if ($visitas > 0) {
            json_erro("Esta propriedade tem {$visitas} visita(s) registrada(s) e não pode ser excluída (o histórico aponta para ela).");
        }
        Database::executar('UPDATE planos_safra SET propriedade_id = NULL WHERE propriedade_id = ?', [$id]);
        Database::executar('DELETE FROM propriedades WHERE id = ?', [$id]); // imóveis (CAR) vão em cascata
        auditar('excluir', 'propriedade', $id, (string) $prop['nome']);
        json_ok();
    }

    /** Exclui imóvel sem talhões (com talhões, mova-os antes) — v40. */
    public function excluirImovel(): void
    {
        Permissoes::exigirInterno();
        $imovel = $this->imovelDaCarteira((int) ($_POST['id'] ?? 0));
        $qtd = (int) Database::valor('SELECT COUNT(*) FROM talhoes WHERE imovel_id = ?', [(int) $imovel['id']]);
        if ($qtd > 0) {
            json_erro("Este imóvel tem {$qtd} talhão(ões). Mova-os para outro imóvel (editar talhão) antes de excluir.");
        }
        $restantes = (int) Database::valor('SELECT COUNT(*) FROM imoveis WHERE propriedade_id = ?', [(int) $imovel['propriedade_id']]);
        if ($restantes <= 1) {
            json_erro('A propriedade precisa de ao menos um imóvel. Edite este em vez de excluir.');
        }
        Database::executar('DELETE FROM imoveis WHERE id = ?', [(int) $imovel['id']]);
        \App\Services\AreaPlantioService::sincronizarPropriedade((int) $imovel['propriedade_id']); // soma dos CARs que ficaram
        auditar('excluir', 'imovel', (int) $imovel['id'], self::rotuloImovel($imovel));
        json_ok();
    }

    /**
     * "Plantar a área toda": cria UM talhão cobrindo a área de plantio do imóvel
     * (mesmo contorno e área), na cultura/finalidade escolhidas — v40 §3.
     */
    public function plantarAreaToda(): void
    {
        Permissoes::exigirInterno();
        $imovel = $this->imovelDaCarteira((int) ($_POST['imovel_id'] ?? 0));
        $imovelId = (int) $imovel['id'];
        $culturaId = (int) ($_POST['cultura_id'] ?? 0);
        if (!$culturaId) {
            json_erro('Escolha a cultura.');
        }
        if ((int) Database::valor('SELECT COUNT(*) FROM talhoes WHERE imovel_id = ?', [$imovelId]) > 0) {
            json_erro('Este imóvel já tem talhões. Edite-os ou exclua-os para plantar a área toda de uma vez.');
        }
        $finalidadeId = (int) ($_POST['finalidade_id'] ?? 0) ?: null;
        $nome = trim($_POST['nome'] ?? '') ?: 'Área toda';
        // v44: UM talhão por área de plantio desenhada (com o nome da área quando há mais de uma);
        // sem área desenhada, cai no legado (área de plantio única/digitada) e, por fim, na divisa
        $areas = \App\Services\AreaPlantioService::areasDoImovel($imovelId);
        $lotes = [];
        foreach ($areas as $a) {
            $lotes[] = ['nome' => count($areas) > 1 ? (string) $a['nome'] : $nome, 'area' => (float) $a['area_gps'], 'contorno' => $a['contorno'], 'area_id' => (int) $a['id']];
        }
        if (!$lotes) {
            $areaPlantio = \App\Services\AreaPlantioService::areaValida($imovel, 'area_plantio_gps', 'area_plantio_ha');
            $contorno = $imovel['contorno_plantio'] ?: null;
            if ($areaPlantio <= 0 && !$contorno) {
                // sem área de plantio: usa o imóvel inteiro (divisa) como área de plantio
                $areaPlantio = \App\Services\AreaPlantioService::areaValida($imovel, 'area_gps', 'area_ha');
                $contorno = $imovel['contorno'] ?: null;
            }
            if ($areaPlantio <= 0 && !$contorno) {
                json_erro('Desenhe a área de plantio no croqui (ou traga a divisa do CAR) antes de plantar a área toda.');
            }
            $lotes[] = ['nome' => $nome, 'area' => $areaPlantio, 'contorno' => $contorno, 'area_id' => null];
        }
        $ids = [];
        $total = 0.0;
        foreach ($lotes as $l) {
            Database::executar(
                'INSERT INTO talhoes (propriedade_id, imovel_id, nome, area_ha, cultura_id, finalidade_id, contorno, area_gps, area_plantio_id)
                 VALUES (?,?,?,?,?,?,?,?,?)',
                [(int) $imovel['propriedade_id'], $imovelId, mb_substr($l['nome'], 0, 120), round($l['area'], 2),
                    $culturaId, $finalidadeId, $l['contorno'], $l['contorno'] ? round($l['area'], 2) : null, $l['area_id']]
            );
            $ids[] = Database::ultimoId();
            $total += $l['area'];
            auditar('criar', 'talhao', end($ids), 'área toda do imóvel #' . $imovelId . ' · ' . $l['nome'] . " · {$l['area']} ha");
        }
        // v46: descontar as áreas de não plantio (mata, açude...) que caem dentro
        \App\Services\AreaPlantioService::sincronizarLiquidas($imovelId);
        $total = (float) Database::valor(
            'SELECT COALESCE(SUM(area_ha), 0) FROM talhoes WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids
        );
        json_ok(['id' => $ids[0], 'ids' => $ids, 'talhoes' => count($ids), 'area_ha' => round($total, 2)]);
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
        // v40: o talhão pertence a um IMÓVEL da propriedade (sem escolha → 1º imóvel) e
        // tem FINALIDADE (grão, silagem, pastagem...). Mudar o imóvel move o talhão.
        $imovelId = (int) ($_POST['imovel_id'] ?? 0);
        if ($imovelId > 0) {
            $pertence = Database::valor('SELECT 1 FROM imoveis WHERE id = ? AND propriedade_id = ?', [$imovelId, $propriedadeId]);
            if (!$pertence) {
                json_erro('O imóvel escolhido não é desta propriedade.');
            }
        } else {
            $imovelId = (int) $this->primeiroImovel($propriedadeId)['id'];
        }
        $finalidadeId = (int) ($_POST['finalidade_id'] ?? 0) ?: null;

        // NÃO ACEITAR SALVAR MAIS DE UMA VEZ (teste de campo: "Morro" gravado 7x por
        // toques repetidos): nome repetido no mesmo imóvel só vale para EDITAR o existente.
        $homonimo = Database::um(
            'SELECT id FROM talhoes WHERE propriedade_id = ? AND imovel_id <=> ? AND LOWER(nome) = LOWER(?) AND id <> ? LIMIT 1',
            [$propriedadeId, $imovelId, $nome, $id]
        );
        if ($homonimo) {
            json_erro('Já existe um talhão chamado "' . $nome . '" neste imóvel. Edite o talhão existente (lápis na ficha) ou use outro nome.');
        }

        // Talhão DESENHADO no croqui (teste de campo): o contorno vem junto e a área
        // é a MEDIDA — a área digitada só vale para talhão antigo sem desenho.
        $contornoJson = trim($_POST['contorno'] ?? '');
        $pontos = null;
        $areaGps = null;
        if ($contornoJson !== '' && $contornoJson !== '[]') {
            try {
                $pontos = \App\Services\CroquiService::validarContorno($contornoJson);
            } catch (\InvalidArgumentException $e) {
                json_erro($e->getMessage());
            }
            $imovel = Database::um('SELECT contorno FROM imoveis WHERE id = ?', [$imovelId]);
            $divisa = !empty($imovel['contorno']) ? (json_decode((string) $imovel['contorno'], true) ?: []) : [];
            if (!$divisa) {
                json_erro('Este imóvel ainda não tem a divisa do CAR. Traga o CAR no croqui antes de desenhar talhões.');
            }
            // v45 — fluxo guiado: o talhão fica DENTRO de uma área de plantio (limite real)
            $areaAtual = $id > 0 ? Database::valor('SELECT area_plantio_id FROM talhoes WHERE id = ?', [$id]) : null;
            [$pontos, $hostTalhao] = $this->talhaoDentroDaAreaPlantio($pontos, $imovelId, $id, $areaAtual !== null ? (int) $areaAtual : null);
            $areaGps = \App\Services\CroquiService::areaHa($pontos);
        }
        $areaHa = $areaGps ?? (float) str_replace(',', '.', $_POST['area_ha'] ?? 0);
        $dados = [
            $nome,
            $areaHa,
            (int) ($_POST['cultura_id'] ?? 0) ?: null,
            $finalidadeId,
            $imovelId,
        ];
        if ($id > 0) {
            Database::executar(
                'UPDATE talhoes SET nome=?, area_ha=?, cultura_id=?, finalidade_id=?, imovel_id=? WHERE id=? AND propriedade_id=?',
                array_merge($dados, [$id, $propriedadeId])
            );
            if ($pontos !== null) {
                Database::executar('UPDATE talhoes SET contorno=?, area_gps=?, area_plantio_id=? WHERE id=?',
                    [json_encode($pontos), $areaGps, (int) $hostTalhao['id'], $id]);
            }
        } else {
            Database::executar(
                'INSERT INTO talhoes (nome, area_ha, cultura_id, finalidade_id, imovel_id, propriedade_id, contorno, area_gps, area_plantio_id)
                 VALUES (?,?,?,?,?,?,?,?,?)',
                array_merge($dados, [$propriedadeId, $pontos !== null ? json_encode($pontos) : null, $areaGps,
                    $pontos !== null ? (int) $hostTalhao['id'] : null])
            );
            $id = Database::ultimoId();
        }
        if ($pontos !== null) {
            auditar('salvar', 'croqui', $id, 'talhão desenhado · ' . count($pontos) . " pontos · {$areaGps} ha");
            // v46: area_ha = área LÍQUIDA (medida − áreas de não plantio dentro do talhão)
            \App\Services\AreaPlantioService::sincronizarLiquidas($imovelId);
        }
        // Devolve o talhão pronto para o croqui (com cultura/finalidade por nome)
        $talhao = Database::um(
            'SELECT t.id, t.nome, t.area_ha, t.area_gps, t.contorno, t.imovel_id, t.area_plantio_id, t.cultura_id, t.finalidade_id,
                    cu.nome AS cultura, f.nome AS finalidade
               FROM talhoes t
               LEFT JOIN culturas cu ON cu.id = t.cultura_id
               LEFT JOIN finalidades f ON f.id = t.finalidade_id
              WHERE t.id = ?',
            [$id]
        );
        json_ok(['id' => $id, 'talhao' => $talhao, 'area_gps' => $areaGps]);
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
