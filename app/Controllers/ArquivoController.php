<?php

namespace App\Controllers;

use App\Services\ConfigService;

/**
 * Serve as imagens personalizadas guardadas no banco (logo e favicon).
 * Rotas públicas: a tela de login também precisa delas.
 */
class ArquivoController
{
    public function logo(): void
    {
        $this->servir('logo_aplicacao');
    }

    public function favicon(): void
    {
        $this->servir('favicon_aplicacao');
    }

    private function servir(string $chave): void
    {
        $imagem = ConfigService::obterImagem($chave);
        if (!$imagem) {
            http_response_code(404);
            exit;
        }
        [$mime, $binario] = $imagem;
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . strlen($binario));
        header('Cache-Control: public, max-age=86400');
        echo $binario;
        exit;
    }
}
