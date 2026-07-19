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

    /** Proteção contra força bruta no login. */
    private const MAX_TENTATIVAS = 5;
    private const MINUTOS_BLOQUEIO = 15;
    private const MINUTOS_JANELA = 30; // falhas mais antigas que isso reiniciam a contagem

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
        self::limparTentativas($email);
        session_regenerate_id(true);
        self::gravarSessao($usuario);
        self::criarTokenPersistente((int) $usuario['id']);
        return true;
    }

    /** Minutos restantes de bloqueio do e-mail (0 = liberado). */
    public static function minutosBloqueado(string $email): int
    {
        try {
            $b = Database::um('SELECT bloqueado_ate FROM login_tentativas WHERE chave = ?', [mb_strtolower(trim($email))]);
        } catch (\PDOException $e) {
            return 0; // tabela ainda não migrada
        }
        if ($b && $b['bloqueado_ate'] && strtotime($b['bloqueado_ate']) > time()) {
            return (int) ceil((strtotime($b['bloqueado_ate']) - time()) / 60);
        }
        return 0;
    }

    /** Registra uma falha de login; ao atingir o limite, bloqueia temporariamente. */
    public static function registrarFalha(string $email): void
    {
        $chave = mb_strtolower(trim($email));
        if ($chave === '') {
            return;
        }
        try {
            $atual = Database::um('SELECT tentativas, atualizado_em FROM login_tentativas WHERE chave = ?', [$chave]);
            $dentroJanela = $atual && strtotime($atual['atualizado_em']) > time() - self::MINUTOS_JANELA * 60;
            $tentativas = ($dentroJanela ? (int) $atual['tentativas'] : 0) + 1;
            $bloqueio = null;
            if ($tentativas >= self::MAX_TENTATIVAS) {
                $bloqueio = date('Y-m-d H:i:s', time() + self::MINUTOS_BLOQUEIO * 60);
                $tentativas = 0;
            }
            Database::executar(
                'INSERT INTO login_tentativas (chave, tentativas, bloqueado_ate) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE tentativas = VALUES(tentativas), bloqueado_ate = VALUES(bloqueado_ate)',
                [$chave, $tentativas, $bloqueio]
            );
        } catch (\PDOException $e) { /* tabela ainda não migrada */ }
    }

    private static function limparTentativas(string $email): void
    {
        try {
            Database::executar('DELETE FROM login_tentativas WHERE chave = ?', [mb_strtolower(trim($email))]);
        } catch (\PDOException $e) { /* tabela ainda não migrada */ }
    }

    /**
     * Define a nova senha do usuário logado (fluxo de primeiro acesso/troca):
     * atualiza o hash, limpa a obrigação de troca e invalida os logins
     * persistentes antigos (outros aparelhos precisam logar de novo).
     */
    public static function definirNovaSenha(string $nova): void
    {
        Database::executar(
            'UPDATE usuarios SET senha_hash = ?, trocar_senha = 0 WHERE id = ?',
            [password_hash($nova, PASSWORD_DEFAULT), self::id()]
        );
        Database::executar('DELETE FROM sessoes_persistentes WHERE usuario_id = ?', [self::id()]);
        self::criarTokenPersistente(self::id());
        $_SESSION['usuario']['trocar_senha'] = 0;
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
            'trocar_senha' => (int) ($usuario['trocar_senha'] ?? 0),
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
