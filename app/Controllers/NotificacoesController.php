<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Permissoes;
use App\Services\NotificacaoService;

class NotificacoesController
{
    public function listar(): void
    {
        Auth::exigirLogin();
        json_ok([
            'nao_lidas' => NotificacaoService::naoLidas(Auth::id()),
            'itens' => NotificacaoService::listar(Auth::id()),
        ]);
    }

    public function ler(): void
    {
        Auth::exigirLogin();
        NotificacaoService::marcarLida((int) ($_POST['id'] ?? 0), Auth::id());
        json_ok();
    }

    public function lerTodas(): void
    {
        Auth::exigirLogin();
        NotificacaoService::marcarTodasLidas(Auth::id());
        json_ok();
    }
}
