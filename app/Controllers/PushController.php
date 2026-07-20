<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Services\PushService;

/** Registro de assinaturas Web Push (notificações no aparelho). */
class PushController
{
    /** Chave pública VAPID para o navegador assinar. */
    public function chave(): void
    {
        Auth::exigirLogin();
        json_ok(['chave' => PushService::chavePublica()]);
    }

    public function registrar(): void
    {
        Auth::exigirLogin();
        $dados = json_decode(file_get_contents('php://input'), true) ?: [];
        try {
            PushService::registrar(
                Auth::id(),
                (string) ($dados['endpoint'] ?? ''),
                $dados['keys']['p256dh'] ?? null,
                $dados['keys']['auth'] ?? null
            );
        } catch (\InvalidArgumentException $e) {
            json_erro($e->getMessage());
        }
        json_ok();
    }

    public function desregistrar(): void
    {
        Auth::exigirLogin();
        $dados = json_decode(file_get_contents('php://input'), true) ?: [];
        PushService::remover((string) ($dados['endpoint'] ?? ''));
        json_ok();
    }
}
