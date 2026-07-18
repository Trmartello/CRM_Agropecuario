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
        return self::obter('logo_aplicacao', 'assets/img/logo-coperdia.svg');
    }

    /** URL do ícone da aba do navegador (personalizado ou padrão). */
    public static function faviconAplicacao(): string
    {
        return self::obter('favicon_aplicacao', 'assets/icons/favicon-32.png');
    }
}
