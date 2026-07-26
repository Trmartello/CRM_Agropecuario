<?php

namespace App\Core;

use PDO;

/**
 * Conexão PDO única. Lê variáveis de ambiente (Railway) com padrão local.
 */
class Database
{
    private static ?PDO $pdo = null;
    private static ?PDO $pdoCusto = null;

    /** Cria uma conexão PDO com a credencial informada (mesmo host/porta/banco). */
    private static function criar(string $usuario, string $senha): PDO
    {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '3306';
        $nome = getenv('DB_NAME') ?: 'crm_agropecuario';
        $dsn = "mysql:host={$host};port={$port};dbname={$nome};charset=utf8mb4";
        return new PDO($dsn, $usuario, $senha, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /**
     * Conexão padrão (usuário COMERCIAL do app). Este usuário NÃO deve ter GRANT
     * de leitura nas tabelas sob firewall do invariante 5 (custo do cooperado / NF
     * do produtor) — a restrição é imposta no MySQL, não só na aplicação.
     */
    public static function conexao(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::criar(getenv('DB_USER') ?: 'crm', getenv('DB_PASS') ?: 'crm123');
        }
        return self::$pdo;
    }

    /**
     * Conexão PRIVILEGIADA para os dados sob firewall (invariante 5): custo
     * individual do cooperado e NF do produtor. Só as rotas de custo gateadas a
     * Diretoria (Administrador), Controladoria (Analista) e Gestor Comercial
     * usam esta conexão (com auditoria de todo acesso).
     *
     * Em produção, DB_USER_CUSTO/DB_PASS_CUSTO apontam para um usuário de banco
     * que TEM acesso às tabelas de custo/NF — enquanto o usuário comercial não.
     * Em desenvolvimento, sem essas variáveis, cai na credencial padrão (o
     * firewall no nível do banco é configurado só no ambiente real).
     */
    public static function conexaoCusto(): PDO
    {
        if (self::$pdoCusto === null) {
            self::$pdoCusto = self::criar(
                getenv('DB_USER_CUSTO') ?: (getenv('DB_USER') ?: 'crm'),
                getenv('DB_PASS_CUSTO') ?: (getenv('DB_PASS') ?: 'crm123')
            );
        }
        return self::$pdoCusto;
    }

    /** Atalho: prepara, executa e retorna o statement. */
    public static function executar(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::conexao()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function todos(string $sql, array $params = []): array
    {
        return self::executar($sql, $params)->fetchAll();
    }

    public static function um(string $sql, array $params = []): ?array
    {
        $linha = self::executar($sql, $params)->fetch();
        return $linha === false ? null : $linha;
    }

    public static function valor(string $sql, array $params = []): mixed
    {
        return self::executar($sql, $params)->fetchColumn();
    }

    public static function ultimoId(): int
    {
        return (int) self::conexao()->lastInsertId();
    }
}
