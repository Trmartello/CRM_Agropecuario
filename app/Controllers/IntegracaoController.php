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
        // Município/UF são OPCIONAIS: se em branco, são detectados do próprio arquivo (.dbf)
        $municipio = trim($_POST['municipio'] ?? '');
        $uf = trim($_POST['uf'] ?? '');
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
        try {
            // Streaming: lê registro a registro (aguenta município inteiro sem estourar a memória)
            $r = \App\Services\CarService::importarMunicipioArquivo($_FILES['arquivo']['tmp_name'], $municipio ?: null, $uf ?: null);
        } catch (\Throwable $e) {
            json_erro($e->getMessage());
        }
        if ($r['imoveis'] === 0) {
            json_erro('Não consegui identificar o município dos imóveis. Preencha Município e UF e importe de novo.');
        }
        $rotulo = implode(', ', array_map(fn ($m) => $m['municipio'] . '/' . $m['uf'] . " ({$m['imoveis']})", $r['municipios']));
        auditar('importar', 'car_municipio', 0, $rotulo);
        json_ok(['imoveis' => $r['imoveis'], 'municipios' => $r['municipios']]);
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
        // O tipo do arquivo decide o importador (cap_anual | clientes)
        $tipo = (string) ($carga['tipo'] ?? '');
        try {
            $resumo = $tipo === 'clientes'
                ? IntegracaoService::importarCargaClientes($carga)
                : IntegracaoService::importarCargaCap($carga);
        } catch (\Exception $e) {
            json_erro($e->getMessage());
        }
        $detalhe = $tipo === 'clientes'
            ? 'carga clientes — ' . $resumo['criados'] . ' criados, ' . $resumo['atualizados'] . ' atualizados'
            : 'carga CAP ' . ($resumo['ano'] ?? '?') . ' — ' . $resumo['vinculados'] . ' vinculados';
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
