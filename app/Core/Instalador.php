<?php

namespace App\Core;

use PDO;

/**
 * Instalação automática do banco: na primeira execução (tabelas ausentes),
 * importa o database.sql — simplifica o deploy no Railway e em novos ambientes.
 */
class Instalador
{
    /** Garante que o schema existe; importa o seed se necessário. */
    public static function garantirSchema(): void
    {
        try {
            Database::valor('SELECT 1 FROM usuarios LIMIT 1');
            return; // banco pronto
        } catch (\PDOException $e) {
            // 42S02 = tabela não existe → primeira execução
            if (!in_array($e->getCode(), ['42S02', '42000'], true)) {
                throw $e;
            }
        }

        $arquivo = dirname(__DIR__, 2) . '/database.sql';
        if (!is_file($arquivo)) {
            throw new \RuntimeException('database.sql não encontrado para instalação automática.');
        }

        $sql = file_get_contents($arquivo);

        // O banco do ambiente (ex.: Railway) pode ter outro nome — remove o
        // CREATE DATABASE/USE e importa no banco da conexão atual.
        $sql = preg_replace('/^\s*CREATE DATABASE.*$/mi', '', $sql);
        $sql = preg_replace('/^\s*USE .*$/mi', '', $sql);

        // Conexão dedicada com multi-statements para importar o script inteiro
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '3306';
        $nome = getenv('DB_NAME') ?: 'crm_agropecuario';
        $usuario = getenv('DB_USER') ?: 'crm';
        $senha = getenv('DB_PASS') ?: 'crm123';

        $pdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$nome};charset=utf8mb4",
            $usuario,
            $senha,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
            ]
        );
        $stmt = $pdo->query($sql);
        // Percorre todos os result sets para o driver concluir cada statement
        while ($stmt->nextRowset()) {
            // nada — apenas consome
        }
        $stmt->closeCursor();
    }
}
