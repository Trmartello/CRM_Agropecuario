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
        $municipio = trim($_POST['municipio'] ?? '');
        $uf = trim($_POST['uf'] ?? '');
        if ($municipio === '' || strlen($uf) !== 2) {
            json_erro('Informe o município e a UF (ex.: Concórdia / SC).');
        }
        if (empty($_FILES['arquivo']['tmp_name']) || !is_uploaded_file($_FILES['arquivo']['tmp_name'])) {
            json_erro('Selecione o arquivo .zip do CAR do município (Shapefile).');
        }
        if (strtolower(pathinfo($_FILES['arquivo']['name'], PATHINFO_EXTENSION)) !== 'zip') {
            json_erro('Envie o .zip do CAR do município (Shapefile).');
        }
        if ($_FILES['arquivo']['size'] > 200 * 1024 * 1024) {
            json_erro('Arquivo muito grande (máximo 200 MB).');
        }
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');
        try {
            $imoveis = \App\Services\ShapefileService::imoveisDoZip($_FILES['arquivo']['tmp_name']);
            $n = \App\Services\CarService::importarMunicipio($imoveis, $municipio, $uf);
        } catch (\Exception $e) {
            json_erro($e->getMessage());
        }
        auditar('importar', 'car_municipio', 0, mb_strtoupper($municipio) . '/' . strtoupper($uf) . " — {$n} imóveis");
        json_ok(['municipio' => mb_strtoupper($municipio), 'uf' => strtoupper($uf), 'imoveis' => $n]);
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
