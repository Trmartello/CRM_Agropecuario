<?php

namespace App\Core;

use PDO;

/**
 * Conexão PDO única. Lê variáveis de ambiente (Railway) com padrão local.
 */
class Database
{
    private static ?PDO $pdo = null;

    public static function conexao(): PDO
    {
        if (self::$pdo === null) {
            $host = getenv('DB_HOST') ?: '127.0.0.1';
            $port = getenv('DB_PORT') ?: '3306';
            $nome = getenv('DB_NAME') ?: 'crm_agropecuario';
            $usuario = getenv('DB_USER') ?: 'crm';
            $senha = getenv('DB_PASS') ?: 'crm123';

            $dsn = "mysql:host={$host};port={$port};dbname={$nome};charset=utf8mb4";
            self::$pdo = new PDO($dsn, $usuario, $senha, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }
        return self::$pdo;
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
