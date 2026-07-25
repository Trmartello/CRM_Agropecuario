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
        ]);
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
        $props = \App\Core\Database::todos(
            "SELECT id, latitude, longitude FROM propriedades
              WHERE latitude IS NOT NULL AND longitude IS NOT NULL AND (contorno IS NULL OR contorno = '')"
        );
        $vinc = 0;
        $semCar = 0;
        foreach ($props as $p) {
            $im = \App\Services\CarService::imovelNoPonto((float) $p['latitude'], (float) $p['longitude']); // exato
            if ($im === null || empty($im['contorno'])) {
                $semCar++;
                continue;
            }
            // divisa da propriedade = 1 anel (maior parte, se o imóvel do CAR for multipartes)
            $divisa = \App\Services\CarService::maiorAnel($im['contorno']);
            $area = \App\Services\CroquiService::areaHa($divisa);
            \App\Core\Database::executar(
                'UPDATE propriedades SET contorno = ?, area_gps = ?, car_numero = ? WHERE id = ?',
                [json_encode($divisa), round((float) $area, 2),
                    mb_substr((string) ($im['cod'] ?? ''), 0, 60) ?: null, (int) $p['id']]
            );
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
