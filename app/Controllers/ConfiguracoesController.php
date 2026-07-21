<?php

namespace App\Controllers;

use App\Core\Database;
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

    /**
     * Diagnóstico do armazenamento de fotos/arquivos (volume no Railway):
     * confere se a pasta é gravável e cruza as referências do banco com os
     * arquivos que existem de fato no disco — fotos enviadas ANTES do volume
     * correto foram perdidas em redeploys e aparecem como "perdidas" aqui.
     */
    private function diagnosticoUploads(): array
    {
        $dir = uploads_dir();
        $legado = dirname(__DIR__, 2) . '/public/uploads';
        $existeArquivo = function (?string $rel) use ($dir, $legado): bool {
            if ($rel === null || $rel === '') {
                return false;
            }
            return is_file($dir . '/' . $rel) || is_file($legado . '/' . $rel);
        };

        $fontes = [
            'Fotos de visitas' => Database::todos('SELECT arquivo FROM visita_fotos'),
            'Fotos de reclamações' => Database::todos('SELECT arquivo FROM reclamacao_fotos'),
            'Comprovantes de refeição' => Database::todos('SELECT comprovante AS arquivo FROM refeicoes WHERE comprovante IS NOT NULL'),
            'Documentos' => Database::todos('SELECT arquivo FROM documentos'),
        ];
        $detalhe = [];
        foreach ($fontes as $rotulo => $linhas) {
            $ok = 0;
            $faltando = 0;
            foreach ($linhas as $l) {
                $existeArquivo($l['arquivo']) ? $ok++ : $faltando++;
            }
            $detalhe[] = ['rotulo' => $rotulo, 'ok' => $ok, 'faltando' => $faltando];
        }

        // Teste real de escrita (o volume pode existir mas estar montado errado)
        $gravavel = false;
        if (is_dir($dir)) {
            $teste = $dir . '/.teste_escrita';
            $gravavel = @file_put_contents($teste, 'ok') !== false;
            @unlink($teste);
        }

        return [
            'caminho' => $dir,
            'existe' => is_dir($dir),
            'gravavel' => $gravavel,
            'detalhe' => $detalhe,
        ];
    }

    public function index(): void
    {
        Permissoes::exigir(['Administrador']);
        render('configuracoes', [
            'titulo' => 'Configurações',
            'armazenamento' => $this->diagnosticoUploads(),
            'culturas' => \App\Core\Database::todos('SELECT id, nome FROM culturas ORDER BY nome'),
            'familias' => \App\Core\Database::todos('SELECT id, nome FROM familias_produto ORDER BY nome'),
            'categoriasReembolso' => $this->categoriasComValores(),
            'tiposRefeicao' => \App\Services\DespesaService::TIPOS_REFEICAO,
            'logoAtual' => ConfigService::logoAplicacao(),
            'faviconAtual' => ConfigService::faviconAplicacao(),
            'logoPersonalizada' => ConfigService::logoPersonalizada(),
            'faviconPersonalizado' => ConfigService::faviconPersonalizado(),
            'ajustes' => [
                'sidebar_largura' => (int) ConfigService::obter('logo_sidebar_largura', '180'),
                'login_largura' => (int) ConfigService::obter('logo_login_largura', '170'),
                'fundo' => ConfigService::obter('logo_fundo', 'branco'),
                'posicao' => ConfigService::obter('logo_posicao', 'acima'),
                'borda_raio' => (int) ConfigService::obter('logo_borda_raio', '10'),
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
        $bordaRaio = max(0, min(30, (int) ($_POST['borda_raio'] ?? 10)));
        ConfigService::definir('logo_sidebar_largura', (string) $sidebar);
        ConfigService::definir('logo_login_largura', (string) $login);
        ConfigService::definir('logo_fundo', $fundo);
        ConfigService::definir('logo_posicao', $posicao);
        ConfigService::definir('logo_borda_raio', (string) $bordaRaio);
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

    /** Cria/atualiza uma categoria de reembolso (valor por km e teto de refeição). */
    public function salvarCategoria(): void
    {
        Permissoes::exigir(['Administrador']);
        $id = (int) ($_POST['id'] ?? 0);
        $nome = trim($_POST['nome'] ?? '');
        if ($nome === '') {
            json_erro('Informe o nome da categoria.');
        }
        $valorKm = max(0, (float) str_replace(',', '.', $_POST['valor_km'] ?? 0));
        $teto = max(0, (float) str_replace(',', '.', $_POST['teto_refeicao'] ?? 0));
        $ativo = (int) ($_POST['ativo'] ?? 1);

        if ($id > 0) {
            Database::executar(
                'UPDATE categorias_reembolso SET nome=?, valor_km=?, teto_refeicao=?, ativo=? WHERE id=?',
                [$nome, $valorKm, $teto, $ativo, $id]
            );
        } else {
            Database::executar(
                'INSERT INTO categorias_reembolso (nome, valor_km, teto_refeicao, ativo) VALUES (?,?,?,?)',
                [$nome, $valorKm, $teto, $ativo]
            );
            $id = Database::ultimoId();
        }

        // Valores de reembolso por tipo de refeição (Café, Almoço, Lanche, Janta)
        foreach (\App\Services\DespesaService::TIPOS_REFEICAO as $tipo) {
            $campo = 'ref_' . self::slugTipo($tipo);
            if (!array_key_exists($campo, $_POST)) {
                continue;
            }
            $valorTipo = max(0, (float) str_replace(',', '.', $_POST[$campo]));
            Database::executar(
                'INSERT INTO reembolso_refeicoes (categoria_reembolso_id, tipo, valor) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE valor = VALUES(valor)',
                [$id, $tipo, $valorTipo]
            );
        }
        json_ok(['id' => $id]);
    }

    /** Categorias com os valores de reembolso por tipo agrupados (para a tela). */
    private function categoriasComValores(): array
    {
        $categorias = Database::todos(
            'SELECT cr.*, (SELECT COUNT(*) FROM usuarios u WHERE u.categoria_reembolso_id = cr.id) AS qtd_usuarios
               FROM categorias_reembolso cr ORDER BY cr.nome'
        );
        foreach ($categorias as &$c) {
            $c['refeicoes'] = [];
            $linhas = Database::todos('SELECT tipo, valor FROM reembolso_refeicoes WHERE categoria_reembolso_id = ?', [(int) $c['id']]);
            foreach ($linhas as $l) {
                $c['refeicoes'][$l['tipo']] = (float) $l['valor'];
            }
        }
        unset($c);
        return $categorias;
    }

    private static function slugTipo(string $tipo): string
    {
        return strtr(mb_strtolower($tipo), ['á' => 'a', 'ç' => 'c', 'ã' => 'a', 'ó' => 'o', 'é' => 'e']);
    }
}
