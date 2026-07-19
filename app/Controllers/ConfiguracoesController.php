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
            'logoPersonalizada' => ConfigService::logoPersonalizada(),
            'faviconPersonalizado' => ConfigService::faviconPersonalizado(),
            'ajustes' => [
                'sidebar_largura' => (int) ConfigService::obter('logo_sidebar_largura', '180'),
                'login_largura' => (int) ConfigService::obter('logo_login_largura', '170'),
                'fundo' => ConfigService::obter('logo_fundo', 'branco'),
                'posicao' => ConfigService::obter('logo_posicao', 'acima'),
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
        $posicao = ($_POST['posicao'] ?? 'acima') === 'lado' ? 'lado' : 'acima';
        ConfigService::definir('logo_sidebar_largura', (string) $sidebar);
        ConfigService::definir('logo_login_largura', (string) $login);
        ConfigService::definir('logo_fundo', $fundo);
        ConfigService::definir('logo_posicao', $posicao);
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

        $mimes = [
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
        ];
        // Imagem vira o padrão do sistema: gravada no banco, sobrevive a deploys
        ConfigService::definirImagem($chave, $_FILES['imagem']['tmp_name'], $mimes[$ext]);
        json_ok();
    }

    /** Restaura a imagem padrão original do sistema. */
    public function restaurarPadrao(): void
    {
        Permissoes::exigir(['Administrador']);
        $chave = $_POST['chave'] ?? '';
        if (!isset(self::CHAVES_IMAGEM[$chave])) {
            json_erro('Configuração inválida.');
        }
        ConfigService::removerImagem($chave);
        json_ok();
    }
}
