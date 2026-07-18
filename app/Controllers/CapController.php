<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Permissoes;
use App\Services\CapService;

class CapController
{
    public function index(): void
    {
        Permissoes::exigirInterno();
        [$filtro, $params] = Permissoes::filtroCarteira();

        // Vendedor/técnico vê apenas as próprias metas; gestor vê a equipe
        $minhasMetas = CapService::metasDoUsuario(Auth::id());
        $equipe = Permissoes::ehGestor() ? CapService::metasDaEquipe() : [];
        $produtoresSugeridos = CapService::produtoresParaMeta($filtro, $params, 8);

        render('cap', compact('minhasMetas', 'equipe', 'produtoresSugeridos') + ['titulo' => 'Metas CAP']);
    }
}
