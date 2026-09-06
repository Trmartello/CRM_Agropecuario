<?php

namespace App\Controllers;

use App\Core\Permissoes;
use App\Services\ConfigService;
use App\Services\IntegracaoService;

/** Integração ERP/CAPE (Módulo 18) — Administrador. */
class IntegracaoController
{
    public function index(): void
    {
        Permissoes::exigir(['Administrador']);
        render('integracao', [
            'titulo' => 'Integração ERP/CAPE',
            'fonte' => IntegracaoService::fonte(),
            'erpUrl' => ConfigService::obter('integracao_erp_url', ''),
            'erpConfigurado' => IntegracaoService::erpConfigurado(),
            'status' => IntegracaoService::status(),
            'historico' => IntegracaoService::historico(),
            'entidades' => IntegracaoService::ENTIDADES,
            'carMunicipios' => \App\Services\CarService::municipios(),
            'cotacoes' => \App\Services\MercadoService::vigentes(),
            'cotacaoCulturas' => \App\Services\MercadoService::culturas(),
        ]);
    }

    /**
     * Cotações de mercado do Portal (ref_mercado) — spec custo-lavoura PR8.
     * Recebe a lista do dia em JSON: [{cultura, fonte, preco, vencimento?}].
     */
    public function salvarCotacoes(): void
    {
        Permissoes::exigir(['Administrador']);
        $itens = json_decode((string) ($_POST['itens'] ?? '[]'), true);
        $dt = trim((string) ($_POST['data'] ?? ''));
        if (!is_array($itens) || !$itens) {
            json_erro('Nenhuma cotação informada.');
        }
        if (count($itens) > 60) {
            json_erro('Cotações demais em um só envio.');
        }
        $n = 0;
        try {
            foreach ($itens as $i) {
                \App\Services\MercadoService::salvar(
                    (string) ($i['cultura'] ?? ''),
                    (string) ($i['fonte'] ?? ''),
                    (float) ($i['preco'] ?? 0),
                    (string) ($i['vencimento'] ?? ''),
                    $dt !== '' ? $dt : null
                );
                $n++;
            }
        } catch (\RuntimeException $e) {
            json_erro($e->getMessage() . ($n ? " ({$n} já gravadas antes do erro.)" : ''));
        }
        auditar('salvar', 'ref_mercado', 0, "{$n} cotações" . ($dt !== '' ? " de {$dt}" : ' de hoje'));
        json_ok(['gravadas' => $n]);
    }

    /** Importa a base do CAR de um município (shapefile do SICAR) para uso offline. */
    public function importarCarMunicipio(): void
    {
        Permissoes::exigir(['Administrador']);
        // Import demorado (município inteiro): libera o lock da sessão para não
        // travar o sino/navegação do mesmo admin (senão dá "upstream error").
        liberar_sessao();
        // POST que estoura o post_max_size chega VAZIO (o PHP descarta o corpo):
        // detecta e explica em vez de deixar dar "resposta inválida".
        $tamEnviado = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if (empty($_FILES) && empty($_POST) && $tamEnviado > 0) {
            json_erro('O arquivo passou do limite de upload do servidor (' . round($tamEnviado / 1048576) . ' MB). '
                . 'Suba só a camada AREA_IMOVEL (não a APP) ou reduza o arquivo.');
        }
        // Município/UF são OPCIONAIS: se em branco, são detectados do próprio arquivo (.dbf)
        $municipio = trim($_POST['municipio'] ?? '');
        $uf = trim($_POST['uf'] ?? '');
        $erroUp = $_FILES['arquivo']['error'] ?? UPLOAD_ERR_NO_FILE;
        if (in_array($erroUp, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            json_erro('O arquivo passou do limite de upload do servidor. Suba só a camada AREA_IMOVEL ou reduza o arquivo.');
        }
        if ($erroUp === UPLOAD_ERR_PARTIAL) {
            json_erro('O envio foi interrompido (conexão caiu no meio). Tente de novo com uma conexão estável.');
        }
        if (empty($_FILES['arquivo']['tmp_name']) || !is_uploaded_file($_FILES['arquivo']['tmp_name'])) {
            json_erro('Selecione o arquivo .zip do CAR do município (Shapefile).');
        }
        if (strtolower(pathinfo($_FILES['arquivo']['name'], PATHINFO_EXTENSION)) !== 'zip') {
            json_erro('Envie o .zip do CAR do município (Shapefile).');
        }
        if ($_FILES['arquivo']['size'] > 250 * 1024 * 1024) {
            json_erro('Arquivo muito grande (máximo 250 MB). Suba apenas a camada AREA_IMOVEL (não a de APP).');
        }
        @set_time_limit(600);
        @ini_set('memory_limit', '768M');
        // Warnings do parser (registros truncados etc.) NÃO podem vazar para o
        // corpo — poluiriam o JSON e o cliente veria "Resposta inválida".
        @ini_set('display_errors', '0');
        try {
            // Streaming: lê registro a registro (aguenta município inteiro sem estourar a memória)
            $r = \App\Services\CarService::importarMunicipioArquivo($_FILES['arquivo']['tmp_name'], $municipio ?: null, $uf ?: null);
        } catch (\Throwable $e) {
            json_erro($e->getMessage());
        }
        if ($r['imoveis'] === 0) {
            json_erro('O arquivo do CAR por município não traz o nome do município. '
                . 'Digite o Município no campo acima (a UF é detectada sozinha) e importe de novo.');
        }
        $rotulo = implode(', ', array_map(fn ($m) => $m['municipio'] . '/' . $m['uf'] . " ({$m['imoveis']})", $r['municipios']));
        auditar('importar', 'car_municipio', 0, $rotulo);
        json_ok(['imoveis' => $r['imoveis'], 'municipios' => $r['municipios']]);
    }

    /**
     * Vincula a base do CAR às PROPRIEDADES: para cada propriedade com sede
     * cadastrada e ainda SEM divisa, acha o imóvel do CAR que contém a sede
     * (match exato, alta confiança) e preenche contorno + área + nº do CAR.
     * Assim o CAR importado "reflete" no cadastro de cada produtor.
     */
    public function vincularCarPropriedades(): void
    {
        Permissoes::exigir(['Administrador']);
        liberar_sessao(); // lote demorado: não segura o lock da sessão
        @set_time_limit(600);
        if (\App\Services\CarService::total() === 0) {
            json_erro('Nenhuma base do CAR importada ainda. Importe o município/estado acima primeiro.');
        }
        // v40: a divisa mora no IMÓVEL (CAR) da propriedade. Candidatos: o 1º imóvel
        // de cada propriedade com sede cadastrada e ainda sem divisa (propriedade
        // antiga sem nenhum imóvel ganha um na hora).
        \App\Core\Database::executar(
            'INSERT INTO imoveis (propriedade_id, car_numero, municipio, area_ha, contorno, area_gps)
             SELECT p.id, p.car_numero, p.municipio, p.area_ha, p.contorno, p.area_gps FROM propriedades p
              WHERE NOT EXISTS (SELECT 1 FROM imoveis i WHERE i.propriedade_id = p.id)'
        );
        $props = \App\Core\Database::todos(
            "SELECT i.id AS imovel_id, p.latitude, p.longitude
               FROM propriedades p
               JOIN imoveis i ON i.propriedade_id = p.id
                AND i.id = (SELECT MIN(i2.id) FROM imoveis i2 WHERE i2.propriedade_id = p.id)
              WHERE p.latitude IS NOT NULL AND p.longitude IS NOT NULL AND (i.contorno IS NULL OR i.contorno = '')"
        );
        $vinc = 0;
        $semCar = 0;
        foreach ($props as $p) {
            $im = \App\Services\CarService::imovelNoPonto((float) $p['latitude'], (float) $p['longitude']); // exato
            if ($im === null || empty($im['contorno'])) {
                $semCar++;
                continue;
            }
            // divisa do imóvel = 1 anel (maior parte, se o imóvel do CAR for multipartes)
            $divisa = \App\Services\CarService::maiorAnel($im['contorno']);
            $area = \App\Services\CroquiService::areaHa($divisa);
            \App\Core\Database::executar(
                'UPDATE imoveis SET contorno = ?, area_gps = ?, area_ha = ?, car_numero = COALESCE(?, car_numero) WHERE id = ?',
                [json_encode($divisa), round((float) $area, 2), round((float) $area, 2),
                    mb_substr((string) ($im['cod'] ?? ''), 0, 60) ?: null, (int) $p['imovel_id']]
            );
            // Município do imóvel vem do nº do CAR (IBGE embutido → lista pré-cadastrada)
            $mun = \App\Services\MunicipiosSul::deCodImovel((string) ($im['cod'] ?? ''));
            if ($mun !== null) {
                \App\Core\Database::executar('UPDATE imoveis SET municipio = ?, cod_ibge = ?, uf = ? WHERE id = ?',
                    [$mun['nome'], $mun['ibge'], $mun['uf'], (int) $p['imovel_id']]);
                \App\Core\Database::executar(
                    "UPDATE propriedades p JOIN imoveis i ON i.propriedade_id = p.id SET p.municipio = ?
                      WHERE i.id = ? AND (p.municipio IS NULL OR p.municipio = '')",
                    [$mun['nome'], (int) $p['imovel_id']]
                );
            }
            // área total da propriedade = soma dos CARs
            $propId = (int) \App\Core\Database::valor('SELECT propriedade_id FROM imoveis WHERE id = ?', [(int) $p['imovel_id']]);
            \App\Services\AreaPlantioService::sincronizarPropriedade($propId);
            $vinc++;
        }
        $semSede = (int) \App\Core\Database::valor('SELECT COUNT(*) FROM propriedades WHERE latitude IS NULL OR longitude IS NULL');
        auditar('vincular', 'car_propriedades', 0, "vinculadas={$vinc}; sem CAR na sede={$semCar}; sem sede={$semSede}");
        json_ok(['vinculadas' => $vinc, 'sem_car' => $semCar, 'sem_sede' => $semSede, 'candidatas' => count($props)]);
    }

    /**
     * Mapa Territorial (PR 2): popula dim_imovel a partir da base do CAR já
     * importada (car_imoveis), por município. Reaproveita a geometria do CAR.
     */
    public function gerarTerritorio(): void
    {
        Permissoes::exigir(['Administrador']);
        liberar_sessao(); // lote demorado: não segura o lock da sessão
        @set_time_limit(600);
        @ini_set('memory_limit', '768M');
        @ini_set('display_errors', '0');
        $municipio = trim($_POST['municipio'] ?? '');
        $uf = trim($_POST['uf'] ?? '');
        if ($municipio === '') {
            json_erro('Informe o município.');
        }
        try {
            $r = \App\Services\MapaTerritorialService::importarMunicipioDoCar($municipio, $uf !== '' ? $uf : null);
        } catch (\Throwable $e) {
            json_erro($e->getMessage());
        }
        auditar('gerar', 'mapa_territorial', 0,
            "{$r['municipio']}/{$r['uf']} — lido {$r['lido']}, novos {$r['inseridos']}, atualizados {$r['atualizados']}");
        json_ok($r);
    }

    /**
     * Job de agregação do custo regional com k-anonimato (custo-lavoura PR10).
     * Lê as tabelas sob firewall pela conexão privilegiada e publica só os
     * grupos com 5+ produtores em agg_custo_regional.
     */
    public function agregarCusto(): void
    {
        Permissoes::exigir(['Administrador']);
        liberar_sessao(); // lote potencialmente demorado
        $safra = trim($_POST['safra'] ?? '');
        try {
            $r = \App\Services\AgregacaoCustoService::executar($safra !== '' ? $safra : null);
        } catch (\Throwable $e) {
            json_erro('Falha ao agregar: verifique se a credencial de custo (DB_USER_CUSTO) está configurada.');
        }
        auditar('agregar', 'agg_custo_regional', 0,
            ($safra !== '' ? "safra {$safra}: " : 'todas as safras: ')
            . "{$r['publicadas']} publicadas, {$r['suprimidas']} suprimidas (k<" . \App\Services\AgregacaoCustoService::K_MINIMO . ')');
        json_ok($r);
    }

    public function salvarConfig(): void
    {
        Permissoes::exigir(['Administrador']);
        IntegracaoService::definirFonte($_POST['fonte'] ?? 'Local');
        ConfigService::definir('integracao_erp_url', trim($_POST['erp_url'] ?? ''));
        json_ok();
    }

    /** Upload da carga inicial extraída do Qlik (JSON) — metas/realizado do CAP. */
    public function importarCarga(): void
    {
        Permissoes::exigir(['Administrador']);
        liberar_sessao(); // não segura o lock da sessão durante a importação
        if (empty($_FILES['arquivo']['tmp_name']) || !is_uploaded_file($_FILES['arquivo']['tmp_name'])) {
            json_erro('Selecione o arquivo JSON da carga.');
        }
        if ($_FILES['arquivo']['size'] > 10 * 1024 * 1024) {
            json_erro('Arquivo muito grande (máximo 10 MB).');
        }
        $carga = json_decode((string) file_get_contents($_FILES['arquivo']['tmp_name']), true);
        if (!is_array($carga)) {
            json_erro('O arquivo não é um JSON válido.');
        }
        // O tipo do arquivo decide o importador (cap_anual | clientes | score_imovel)
        $tipo = (string) ($carga['tipo'] ?? '');
        try {
            $resumo = match ($tipo) {
                'clientes' => IntegracaoService::importarCargaClientes($carga),
                'score_imovel' => IntegracaoService::importarCargaScore($carga),
                default => IntegracaoService::importarCargaCap($carga),
            };
        } catch (\Exception $e) {
            json_erro($e->getMessage());
        }
        $detalhe = match ($tipo) {
            'clientes' => 'carga clientes — ' . $resumo['criados'] . ' criados, ' . $resumo['atualizados'] . ' atualizados',
            'score_imovel' => 'carga score ' . ($resumo['safra'] ?? '?') . ' — ' . ($resumo['atualizados'] ?? 0) . ' imóveis',
            default => 'carga CAP ' . ($resumo['ano'] ?? '?') . ' — ' . $resumo['vinculados'] . ' vinculados',
        };
        auditar('importar', 'integracao', 0, $detalhe);
        json_ok(['resumo' => $resumo, 'tipo' => $tipo]);
    }

    public function sincronizar(): void
    {
        Permissoes::exigir(['Administrador']);
        $entidade = $_POST['entidade'] ?? '';
        try {
            if ($entidade === '_tudo') {
                $r = IntegracaoService::sincronizarTudo();
            } else {
                $r = IntegracaoService::sincronizar($entidade);
            }
        } catch (\Exception $e) {
            json_erro($e->getMessage());
        }
        json_ok(['resultado' => $r]);
    }
}
