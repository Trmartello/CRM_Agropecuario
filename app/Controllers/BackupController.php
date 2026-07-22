<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\Permissoes;
use PDO;

/**
 * Backup do banco pelo próprio app (Administrador): gera um dump SQL completo
 * (estrutura + dados) sem depender de mysqldump nem de recursos do plano da
 * hospedagem. Guarde o arquivo fora do servidor (Drive, S3 etc.).
 */
class BackupController
{
    public function baixar(): void
    {
        Permissoes::exigir(['Administrador']);
        liberar_sessao(); // dump longo: não segura o lock da sessão do admin
        $pdo = Database::conexao();

        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="crm_coperdia_backup_' . date('Ymd_His') . '.sql"');
        header('X-Content-Type-Options: nosniff');

        echo "-- Backup CRM AGRO Copérdia — gerado em " . date('d/m/Y H:i:s') . "\n";
        echo "-- Restaurar com: mysql -u <usuario> -p <banco> < este_arquivo.sql\n";
        echo "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";

        $tabelas = array_map(fn ($t) => array_values($t)[0], Database::todos('SHOW TABLES'));
        foreach ($tabelas as $tabela) {
            $create = Database::um("SHOW CREATE TABLE `{$tabela}`");
            echo "DROP TABLE IF EXISTS `{$tabela}`;\n" . array_values($create)[1] . ";\n\n";

            // Dados em streaming (linha a linha — não estoura memória)
            $stmt = $pdo->query("SELECT * FROM `{$tabela}`");
            while ($linha = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $valores = array_map(
                    fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v),
                    array_values($linha)
                );
                echo "INSERT INTO `{$tabela}` VALUES (" . implode(',', $valores) . ");\n";
            }
            echo "\n";
        }
        echo "SET FOREIGN_KEY_CHECKS=1;\n";

        auditar('backup', 'banco', null, count($tabelas) . ' tabelas');
        exit;
    }
}
