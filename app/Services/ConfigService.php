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

    /** URL da logo do aplicativo (personalizada ou padrão). */
    public static function logoAplicacao(): string
    {
        return self::imagemValida('logo_aplicacao', 'assets/img/logo-coperdia.svg');
    }

    /** URL do ícone da aba do navegador (personalizado ou padrão). */
    public static function faviconAplicacao(): string
    {
        return self::imagemValida('favicon_aplicacao', 'assets/icons/favicon-32.png');
    }

    /**
     * Retorna a imagem configurada somente se o arquivo ainda existir no disco
     * (uploads somem quando o volume não está montado no deploy); caso
     * contrário, limpa a configuração órfã e volta ao padrão.
     */
    private static function imagemValida(string $chave, string $padrao): string
    {
        $valor = self::obter($chave);
        if ($valor === null) {
            return $padrao;
        }
        $arquivo = dirname(__DIR__, 2) . '/public/' . $valor;
        if (str_starts_with($valor, 'uploads/') && !is_file($arquivo)) {
            self::remover($chave);
            return $padrao;
        }
        return $valor;
    }
}
