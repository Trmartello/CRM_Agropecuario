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
