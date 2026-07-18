<?php

namespace App\Core;

/**
 * Roteador simples: ?r=modulo/acao → Controller::acao().
 */
class Router
{
    /** Mapa rota → [classe, método]. */
    private array $rotas = [];

    public function registrar(string $rota, string $classe, string $metodo): void
    {
        $this->rotas[$rota] = [$classe, $metodo];
    }

    public function despachar(string $rota): void
    {
        $rota = $rota ?: 'dashboard';
        if (!isset($this->rotas[$rota])) {
            http_response_code(404);
            if (Auth::ehAjax()) {
                json_erro('Rota não encontrada: ' . $rota, 404);
            }
            echo 'Página não encontrada.';
            return;
        }
        [$classe, $metodo] = $this->rotas[$rota];
        (new $classe())->$metodo();
    }
}
