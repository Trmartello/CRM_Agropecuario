<?php

namespace App\Services;

use App\Core\Database;

/**
 * Configurações do sistema (chave/valor) — logo, favicon e demais ajustes.
 */
class ConfigService
{
    public static function obter(string $chave, ?string $padrao = null): ?string
    {
        $valor = Database::valor('SELECT valor FROM configuracoes WHERE chave = ?', [$chave]);
        return $valor !== false && $valor !== null ? (string) $valor : $padrao;
    }

    public static function definir(string $chave, string $valor): void
    {
        Database::executar(
            'INSERT INTO configuracoes (chave, valor) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor)',
            [$chave, $valor]
        );
    }

    public static function remover(string $chave): void
    {
        Database::executar('DELETE FROM configuracoes WHERE chave = ?', [$chave]);
    }

    /**
     * Guarda uma imagem no banco (base64) — torna-se o padrão do sistema,
     * sobrevivendo a deploys sem depender de volume de arquivos.
     */
    public static function definirImagem(string $chave, string $caminhoArquivo, string $mime): void
    {
        self::definir($chave . '_dados', $mime . ';base64,' . base64_encode(file_get_contents($caminhoArquivo)));
    }

    /** Conteúdo da imagem armazenada: [mime, binário] ou null. */
    public static function obterImagem(string $chave): ?array
    {
        $valor = self::obter($chave . '_dados');
        if (!$valor || !str_contains($valor, ';base64,')) {
            return null;
        }
        [$mime, $b64] = explode(';base64,', $valor, 2);
        $binario = base64_decode($b64, true);
        return $binario === false ? null : [$mime, $binario];
    }

    public static function removerImagem(string $chave): void
    {
        self::remover($chave . '_dados');
    }

    /** Carimbo para furar cache do navegador quando a imagem muda. */
    private static function versaoImagem(string $chave): string
    {
        return (string) (Database::valor(
            'SELECT UNIX_TIMESTAMP(atualizado_em) FROM configuracoes WHERE chave = ?',
            [$chave . '_dados']
        ) ?: '');
    }

    /** URL da logo do aplicativo (personalizada no banco ou padrão). */
    public static function logoAplicacao(): string
    {
        $v = self::versaoImagem('logo_aplicacao');
        return $v !== '' ? 'index.php?r=arquivo/logo&v=' . $v : 'assets/img/logo-coperdia.svg';
    }

    /** URL do ícone da aba do navegador (personalizado no banco ou padrão). */
    public static function faviconAplicacao(): string
    {
        $v = self::versaoImagem('favicon_aplicacao');
        return $v !== '' ? 'index.php?r=arquivo/favicon&v=' . $v : 'assets/icons/favicon-32.png';
    }

    public static function logoPersonalizada(): bool
    {
        return self::obter('logo_aplicacao_dados') !== null;
    }

    public static function faviconPersonalizado(): bool
    {
        return self::obter('favicon_aplicacao_dados') !== null;
    }
}
