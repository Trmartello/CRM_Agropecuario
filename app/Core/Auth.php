<?php

namespace App\Core;

/**
 * Autenticação por sessão PHP.
 */
class Auth
{
    /** Dias de validade do login persistente (cookie + token no banco). */
    private const DIAS_LEMBRAR = 30;
    private const COOKIE_LEMBRAR = 'crm_lembrar';

    public static function iniciarSessao(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => self::DIAS_LEMBRAR * 86400,
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => self::httpsAtivo(),
            ]);
            session_start();
        }
        // Sessão perdida (ex.: deploy recriou o container)? Restaura pelo token do banco.
        if (!self::logado() && isset($_COOKIE[self::COOKIE_LEMBRAR])) {
            self::restaurarPeloToken($_COOKIE[self::COOKIE_LEMBRAR]);
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
        self::gravarSessao($usuario);
        self::criarTokenPersistente((int) $usuario['id']);
        return true;
    }

    public static function sair(): void
    {
        if (isset($_COOKIE[self::COOKIE_LEMBRAR])) {
            Database::executar(
                'DELETE FROM sessoes_persistentes WHERE token_hash = ?',
                [hash('sha256', $_COOKIE[self::COOKIE_LEMBRAR])]
            );
            setcookie(self::COOKIE_LEMBRAR, '', time() - 3600, '/', '', false, true);
        }
        $_SESSION = [];
        session_destroy();
    }

    private static function gravarSessao(array $usuario): void
    {
        $_SESSION['usuario'] = [
            'id' => (int) $usuario['id'],
            'nome' => $usuario['nome'],
            'email' => $usuario['email'],
            'perfil' => $usuario['perfil'],
        ];
    }

    private static function criarTokenPersistente(int $usuarioId): void
    {
        $token = bin2hex(random_bytes(32));
        Database::executar(
            'INSERT INTO sessoes_persistentes (usuario_id, token_hash, expira_em)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))',
            [$usuarioId, hash('sha256', $token), self::DIAS_LEMBRAR]
        );
        // Limpeza oportunista de tokens vencidos
        Database::executar('DELETE FROM sessoes_persistentes WHERE expira_em < NOW()');
        setcookie(self::COOKIE_LEMBRAR, $token, [
            'expires' => time() + self::DIAS_LEMBRAR * 86400,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => self::httpsAtivo(),
        ]);
    }

    /** Detecta HTTPS (inclui proxy do Railway via X-Forwarded-Proto). */
    private static function httpsAtivo(): bool
    {
        return (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? 'off') !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
            || ($_SERVER['SERVER_PORT'] ?? '') === '443';
    }

    private static function restaurarPeloToken(string $token): void
    {
        try {
            $usuario = Database::um(
                'SELECT u.* FROM sessoes_persistentes sp
                   JOIN usuarios u ON u.id = sp.usuario_id
                  WHERE sp.token_hash = ? AND sp.expira_em > NOW() AND u.ativo = 1',
                [hash('sha256', $token)]
            );
        } catch (\PDOException $e) {
            return; // tabela ainda não migrada — segue sem restaurar
        }
        if ($usuario) {
            session_regenerate_id(true);
            self::gravarSessao($usuario);
        }
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
