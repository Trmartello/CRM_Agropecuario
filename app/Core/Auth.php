<?php

namespace App\Core;

/**
 * Autenticação por sessão PHP.
 */
class Auth
{
    public static function iniciarSessao(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
            session_start();
        }
    }

    public static function tentar(string $email, string $senha): bool
    {
        $usuario = Database::um(
            'SELECT * FROM usuarios WHERE email = ? AND ativo = 1',
            [$email]
        );
        if (!$usuario || !password_verify($senha, $usuario['senha_hash'])) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['usuario'] = [
            'id' => (int) $usuario['id'],
            'nome' => $usuario['nome'],
            'email' => $usuario['email'],
            'perfil' => $usuario['perfil'],
        ];
        return true;
    }

    public static function sair(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    public static function usuario(): ?array
    {
        return $_SESSION['usuario'] ?? null;
    }

    public static function id(): int
    {
        return (int) ($_SESSION['usuario']['id'] ?? 0);
    }

    public static function perfil(): string
    {
        return $_SESSION['usuario']['perfil'] ?? '';
    }

    public static function logado(): bool
    {
        return isset($_SESSION['usuario']);
    }

    /** Redireciona para o login se não autenticado (ou 401 em chamadas AJAX). */
    public static function exigirLogin(): void
    {
        if (self::logado()) {
            return;
        }
        if (self::ehAjax()) {
            json_erro('Sessão expirada. Faça login novamente.', 401);
        }
        header('Location: ' . url('login'));
        exit;
    }

    public static function ehAjax(): bool
    {
        return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch'
            || str_starts_with($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }
}
