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
        ]);
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
        try {
            $resumo = IntegracaoService::importarCargaCap($carga);
        } catch (\Exception $e) {
            json_erro($e->getMessage());
        }
        auditar('importar', 'integracao', 0, 'carga CAP ' . ($resumo['ano'] ?? '?') . ' — ' . $resumo['vinculados'] . ' vinculados');
        json_ok(['resumo' => $resumo]);
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
