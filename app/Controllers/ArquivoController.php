<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
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
        Auth::exigirLogin();
        $f = (string) ($_GET['f'] ?? '');
        // Só nomes seguros (subpastas comprovantes/, documentos/); nunca ".." ou absoluto
        if ($f === '' || !preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', $f) || str_contains($f, '..')) {
            http_response_code(404);
            exit;
        }
        if (Auth::perfil() === 'Produtor') {
            // Produtor só acessa fotos das PRÓPRIAS visitas (portal); o resto é interno
            $clienteId = (int) Database::valor('SELECT cliente_id FROM usuarios WHERE id = ?', [Auth::id()]);
            $pertence = $clienteId > 0 && Database::valor(
                'SELECT 1 FROM visita_fotos vf JOIN visitas v ON v.id = vf.visita_id
                  WHERE vf.arquivo = ? AND v.cliente_id = ?',
                [$f, $clienteId]
            );
            if (!$pertence) {
                http_response_code(404);
                exit;
            }
        } elseif (!Permissoes::ehGestor() && !$this->uploadNaCarteira($f)) {
            // Perfil interno de campo (Vendedor/Consultor): só serve arquivo do
            // cliente na SUA carteira ou de sua autoria — mesmo firewall que o
            // clientes/baixar-documento. Sem isso, arquivo/upload servia qualquer
            // documento/foto de qualquer produtor a quem soubesse o nome do arquivo.
            http_response_code(404);
            exit;
        }
        // &mini=1: serve a miniatura (uploads/miniaturas/<f>) quando existir;
        // fotos antigas não têm miniatura e caem no arquivo original.
        $candidatos = [];
        if ((string) ($_GET['mini'] ?? '') === '1') {
            $candidatos[] = [uploads_dir(), 'miniaturas/' . $f];
        }
        foreach ([uploads_dir(), dirname(__DIR__, 2) . '/public/uploads'] as $base) {
            $candidatos[] = [$base, $f];
        }
        foreach ($candidatos as [$base, $relativo]) {
            $caminho = $base . '/' . $relativo;
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

    /**
     * True se o arquivo pedido pertence à carteira do usuário logado (ou é de sua
     * autoria). Aplica o mesmo firewall de carteira do clientes/baixar-documento,
     * resolvendo o dono a partir do PREFIXO do nome (todos únicos por hash):
     *   doc_<id>_        → documentos           (cliente ou autor)
     *   visita_<id>_     → visita_fotos→visitas (cliente da visita ou autor)
     *   reclamacao_<id>_ → reclamacao_fotos     (cliente da reclamação ou autor)
     *   ref_<uid>_       → refeicoes            (só o próprio autor)
     * Origem desconhecida: nega (fail-closed) para perfil de campo.
     */
    private function uploadNaCarteira(string $f): bool
    {
        $base = basename($f); // ignora subpasta (documentos/, comprovantes/, miniaturas/)
        $uid = (int) Auth::id();
        if (str_starts_with($base, 'doc_')) {
            return (bool) Database::valor(
                'SELECT 1 FROM documentos d JOIN clientes c ON c.id = d.cliente_id
                  WHERE d.arquivo = ? AND (c.responsavel_id = ? OR d.usuario_id = ?) LIMIT 1',
                [$base, $uid, $uid]
            );
        }
        if (str_starts_with($base, 'visita_')) {
            return (bool) Database::valor(
                'SELECT 1 FROM visita_fotos vf JOIN visitas v ON v.id = vf.visita_id
                   JOIN clientes c ON c.id = v.cliente_id
                  WHERE vf.arquivo = ? AND (c.responsavel_id = ? OR v.usuario_id = ?) LIMIT 1',
                [$base, $uid, $uid]
            );
        }
        if (str_starts_with($base, 'reclamacao_')) {
            return (bool) Database::valor(
                'SELECT 1 FROM reclamacao_fotos rf JOIN reclamacoes r ON r.id = rf.reclamacao_id
                   JOIN clientes c ON c.id = r.cliente_id
                  WHERE rf.arquivo = ? AND (c.responsavel_id = ? OR r.usuario_id = ?) LIMIT 1',
                [$base, $uid, $uid]
            );
        }
        if (str_starts_with($base, 'ref_')) { // comprovante de refeição: só o autor
            return (bool) Database::valor(
                'SELECT 1 FROM refeicoes WHERE comprovante = ? AND usuario_id = ? LIMIT 1',
                ['comprovantes/' . $base, $uid]
            );
        }
        return false;
    }

    /** Foto/arte personalizada de um estágio fenológico (guardada no banco). */
    public function estagio(): void
    {
        Auth::exigirLogin();
        $id = (int) ($_GET['id'] ?? 0);
        $linha = Database::um(
            'SELECT imagem, imagem_mime FROM fenologia_estagios WHERE id = ? AND imagem IS NOT NULL', [$id]
        );
        if (!$linha) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: ' . ($linha['imagem_mime'] ?: 'image/jpeg'));
        header('Content-Length: ' . strlen($linha['imagem']));
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        echo $linha['imagem'];
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
