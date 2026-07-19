<?php

namespace App\Controllers;

use App\Core\Permissoes;
use App\Services\ConfigService;

/**
 * Serve as imagens personalizadas guardadas no banco (logo e favicon).
 * Rotas públicas: a tela de login também precisa delas.
 */
class ArquivoController
{
    private const MIMES = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'webp' => 'image/webp', 'heic' => 'application/octet-stream', 'pdf' => 'application/pdf',
    ];

    public function logo(): void
    {
        $this->servir('logo_aplicacao');
    }

    public function favicon(): void
    {
        $this->servir('favicon_aplicacao');
    }

    /**
     * Serve um arquivo enviado (foto de visita/reclamação, comprovante) de forma
     * AUTENTICADA — os uploads ficam fora do docroot e não têm URL pública.
     * Lê primeiro em dados/uploads; cai para public/uploads (arquivos antigos).
     */
    public function upload(): void
    {
        Permissoes::exigirInterno();
        $f = (string) ($_GET['f'] ?? '');
        // Só nomes seguros (subpastas comprovantes/, documentos/); nunca ".." ou absoluto
        if ($f === '' || !preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', $f) || str_contains($f, '..')) {
            http_response_code(404);
            exit;
        }
        $bases = [uploads_dir(), dirname(__DIR__, 2) . '/public/uploads'];
        foreach ($bases as $base) {
            $caminho = $base . '/' . $f;
            $real = realpath($caminho);
            if ($real === false || !is_file($real)) {
                continue;
            }
            $baseReal = realpath($base);
            if ($baseReal === false || !str_starts_with($real, $baseReal . DIRECTORY_SEPARATOR)) {
                continue; // fora da pasta de uploads
            }
            $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
            header('Content-Type: ' . (self::MIMES[$ext] ?? 'application/octet-stream'));
            header('Content-Length: ' . (string) filesize($real));
            header('Cache-Control: private, max-age=3600');
            header('X-Content-Type-Options: nosniff');
            readfile($real);
            exit;
        }
        http_response_code(404);
        exit;
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
