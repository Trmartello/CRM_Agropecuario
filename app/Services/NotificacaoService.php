<?php

namespace App\Services;

use App\Core\Database;

/** Central de notificações por usuário. */
class NotificacaoService
{
    public static function criar(int $usuarioId, string $tipo, string $titulo, ?string $texto = null, ?string $link = null): void
    {
        Database::executar(
            'INSERT INTO notificacoes (usuario_id, tipo, titulo, texto, link) VALUES (?,?,?,?,?)',
            [$usuarioId, $tipo, mb_substr($titulo, 0, 160), $texto ? mb_substr($texto, 0, 255) : null, $link]
        );
        // Acorda os aparelhos do usuário (Web Push, best-effort — nunca quebra o fluxo)
        try {
            PushService::enviarParaUsuario($usuarioId);
        } catch (\Throwable $e) { /* sem assinaturas/rede: ignora */ }
    }

    /** Evita duplicar a mesma notificação (mesmo tipo+link) ainda não lida. */
    public static function criarUnica(int $usuarioId, string $tipo, string $titulo, ?string $texto, ?string $link): void
    {
        $existe = Database::valor(
            'SELECT 1 FROM notificacoes WHERE usuario_id = ? AND tipo = ? AND (link <=> ?) AND lida = 0 LIMIT 1',
            [$usuarioId, $tipo, $link]
        );
        if (!$existe) {
            self::criar($usuarioId, $tipo, $titulo, $texto, $link);
        }
    }

    public static function naoLidas(int $usuarioId): int
    {
        return (int) Database::valor('SELECT COUNT(*) FROM notificacoes WHERE usuario_id = ? AND lida = 0', [$usuarioId]);
    }

    public static function listar(int $usuarioId, int $limite = 30): array
    {
        return Database::todos(
            'SELECT * FROM notificacoes WHERE usuario_id = ? ORDER BY lida ASC, criado_em DESC LIMIT ' . (int) $limite,
            [$usuarioId]
        );
    }

    public static function marcarLida(int $id, int $usuarioId): void
    {
        Database::executar('UPDATE notificacoes SET lida = 1 WHERE id = ? AND usuario_id = ?', [$id, $usuarioId]);
    }

    public static function marcarTodasLidas(int $usuarioId): void
    {
        Database::executar('UPDATE notificacoes SET lida = 1 WHERE usuario_id = ? AND lida = 0', [$usuarioId]);
    }
}
