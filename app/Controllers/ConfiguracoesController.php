<?php

namespace App\Controllers;

use App\Core\Permissoes;
use App\Services\ConfigService;

/**
 * Configurações do sistema: logo do aplicativo e ícone da aba do navegador.
 */
class ConfiguracoesController
{
    private const CHAVES_IMAGEM = [
        'logo_aplicacao' => ['png', 'jpg', 'jpeg', 'webp', 'svg'],
        'favicon_aplicacao' => ['png', 'ico', 'svg', 'jpg', 'jpeg'],
    ];

    public function index(): void
    {
        Permissoes::exigir(['Administrador']);
        render('configuracoes', [
            'titulo' => 'Configurações',
            'logoAtual' => ConfigService::logoAplicacao(),
            'faviconAtual' => ConfigService::faviconAplicacao(),
            'logoPersonalizada' => ConfigService::obter('logo_aplicacao') !== null,
            'faviconPersonalizado' => ConfigService::obter('favicon_aplicacao') !== null,
            'ajustes' => [
                'sidebar_largura' => (int) ConfigService::obter('logo_sidebar_largura', '180'),
                'login_largura' => (int) ConfigService::obter('logo_login_largura', '170'),
                'fundo' => ConfigService::obter('logo_fundo', 'branco'),
            ],
        ]);
    }

    /** Ajustes de exibição da logo (tamanho e fundo). */
    public function salvarAjustes(): void
    {
        Permissoes::exigir(['Administrador']);
        $sidebar = max(60, min(240, (int) ($_POST['sidebar_largura'] ?? 180)));
        $login = max(100, min(340, (int) ($_POST['login_largura'] ?? 170)));
        $fundo = ($_POST['fundo'] ?? 'branco') === 'transparente' ? 'transparente' : 'branco';
        ConfigService::definir('logo_sidebar_largura', (string) $sidebar);
        ConfigService::definir('logo_login_largura', (string) $login);
        ConfigService::definir('logo_fundo', $fundo);
        json_ok();
    }

    /** Upload da logo ou do favicon (AJAX multipart). */
    public function salvarImagem(): void
    {
        Permissoes::exigir(['Administrador']);
        $chave = $_POST['chave'] ?? '';
        if (!isset(self::CHAVES_IMAGEM[$chave])) {
            json_erro('Configuração inválida.');
        }
        if (empty($_FILES['imagem']['tmp_name']) || !is_uploaded_file($_FILES['imagem']['tmp_name'])) {
            json_erro('Selecione o arquivo de imagem.');
        }
        $ext = strtolower(pathinfo($_FILES['imagem']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::CHAVES_IMAGEM[$chave], true)) {
            json_erro('Formato não aceito. Use: ' . implode(', ', self::CHAVES_IMAGEM[$chave]) . '.');
        }
        if ($_FILES['imagem']['size'] > 2 * 1024 * 1024) {
            json_erro('Arquivo muito grande (máximo 2 MB).');
        }

        $dir = dirname(__DIR__, 2) . '/public/uploads/config';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        // Nome novo a cada envio para furar o cache do navegador
        $arquivo = $chave . '_' . time() . '.' . $ext;

        // Remove o arquivo anterior desta chave
        $anterior = ConfigService::obter($chave);
        if ($anterior && str_starts_with($anterior, 'uploads/config/')) {
            @unlink(dirname(__DIR__, 2) . '/public/' . $anterior);
        }

        if (!move_uploaded_file($_FILES['imagem']['tmp_name'], $dir . '/' . $arquivo)) {
            json_erro('Falha ao salvar o arquivo.');
        }
        ConfigService::definir($chave, 'uploads/config/' . $arquivo);
        json_ok(['url' => 'uploads/config/' . $arquivo]);
    }

    /** Restaura a imagem padrão. */
    public function restaurarPadrao(): void
    {
        Permissoes::exigir(['Administrador']);
        $chave = $_POST['chave'] ?? '';
        if (!isset(self::CHAVES_IMAGEM[$chave])) {
            json_erro('Configuração inválida.');
        }
        $anterior = ConfigService::obter($chave);
        if ($anterior && str_starts_with($anterior, 'uploads/config/')) {
            @unlink(dirname(__DIR__, 2) . '/public/' . $anterior);
        }
        ConfigService::remover($chave);
        json_ok();
    }
}
