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
            self::migracoesLeves();
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

    /** Migrações leves para bancos já instalados (tabelas novas de versões posteriores). */
    private static function migracoesLeves(): void
    {
        Database::executar(
            'CREATE TABLE IF NOT EXISTS configuracoes (
               chave VARCHAR(60) PRIMARY KEY,
               valor MEDIUMTEXT NOT NULL,
               atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
             ) ENGINE=InnoDB'
        );
        Database::executar(
            'CREATE TABLE IF NOT EXISTS sessoes_persistentes (
               id INT AUTO_INCREMENT PRIMARY KEY,
               usuario_id INT NOT NULL,
               token_hash CHAR(64) NOT NULL UNIQUE,
               expira_em DATETIME NOT NULL,
               criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
               FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
             ) ENGINE=InnoDB'
        );
        // Amplia a coluna em bancos criados antes (imagens em base64 exigem MEDIUMTEXT)
        $tipo = Database::valor(
            "SELECT DATA_TYPE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'configuracoes' AND COLUMN_NAME = 'valor'"
        );
        if ($tipo === 'text') {
            Database::executar('ALTER TABLE configuracoes MODIFY valor MEDIUMTEXT NOT NULL');
        }

        // Migrações versionadas: bancos instalados em fases anteriores ganham
        // as estruturas novas automaticamente, sem recriação manual.
        $versao = (int) (Database::valor("SELECT valor FROM configuracoes WHERE chave = 'schema_versao'") ?: 1);
        if ($versao < 2) {
            self::migrarParaV2();
            Database::executar(
                "INSERT INTO configuracoes (chave, valor) VALUES ('schema_versao', '2')
                 ON DUPLICATE KEY UPDATE valor = '2'"
            );
        }
    }

    private static function temTabela(string $tabela): bool
    {
        return (bool) Database::valor(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$tabela]
        );
    }

    private static function temColuna(string $tabela, string $coluna): bool
    {
        return (bool) Database::valor(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$tabela, $coluna]
        );
    }

    private static function adicionarColuna(string $tabela, string $coluna, string $ddl): void
    {
        if (!self::temColuna($tabela, $coluna)) {
            Database::executar("ALTER TABLE {$tabela} ADD COLUMN {$ddl}");
        }
    }

    /** Fase 2: pedidos ampliados, estoque, promoções, entregas futuras e pacotes. */
    private static function migrarParaV2(): void
    {
        // Colunas novas em tabelas da Fase 1
        self::adicionarColuna('produtos', 'estoque',
            "estoque DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER preco_referencia");
        self::adicionarColuna('pacotes_agricolas', 'bonificacao_sacas_ha',
            'bonificacao_sacas_ha DECIMAL(8,2) NOT NULL DEFAULT 0');
        self::adicionarColuna('pacotes_agricolas', 'ativo',
            'ativo TINYINT(1) NOT NULL DEFAULT 1');
        self::adicionarColuna('pedidos', 'safra_id', 'safra_id INT NULL AFTER usuario_id');
        self::adicionarColuna('pedidos', 'pacote_id', 'pacote_id INT NULL AFTER tipo');
        self::adicionarColuna('pedidos', 'area_ha', 'area_ha DECIMAL(10,2) NULL AFTER pacote_id');
        self::adicionarColuna('pedidos', 'motivo_pendencia', 'motivo_pendencia VARCHAR(160) NULL AFTER status');
        self::adicionarColuna('pedidos', 'aprovado_por', 'aprovado_por INT NULL AFTER motivo_pendencia');
        self::adicionarColuna('pedidos', 'condicao_pagamento', 'condicao_pagamento VARCHAR(120) NULL');
        self::adicionarColuna('pedidos', 'observacao', 'observacao VARCHAR(255) NULL');
        self::adicionarColuna('pedidos', 'valor_bruto', 'valor_bruto DECIMAL(14,2) NOT NULL DEFAULT 0');
        self::adicionarColuna('pedidos', 'desconto_total', 'desconto_total DECIMAL(14,2) NOT NULL DEFAULT 0');
        self::adicionarColuna('pedidos', 'bonificacao_sacas', 'bonificacao_sacas DECIMAL(10,2) NOT NULL DEFAULT 0');
        self::adicionarColuna('pedidos_itens', 'desconto_pct', 'desconto_pct DECIMAL(5,2) NOT NULL DEFAULT 0');

        // Tabelas novas da Fase 2
        if (!self::temTabela('promocoes')) {
            Database::executar(
                'CREATE TABLE promocoes (
                   id INT AUTO_INCREMENT PRIMARY KEY,
                   produto_id INT NOT NULL,
                   descricao VARCHAR(200) NOT NULL,
                   desconto_pct DECIMAL(5,2) NOT NULL,
                   valido_ate DATE NOT NULL,
                   FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE CASCADE
                 ) ENGINE=InnoDB'
            );
        }
        if (!self::temTabela('entregas_futuras')) {
            Database::executar(
                'CREATE TABLE entregas_futuras (
                   id INT AUTO_INCREMENT PRIMARY KEY,
                   cliente_id INT NOT NULL,
                   produto_id INT NOT NULL,
                   quantidade_contratada DECIMAL(12,2) NOT NULL,
                   quantidade_retirada DECIMAL(12,2) NOT NULL DEFAULT 0,
                   data_contrato DATE NOT NULL,
                   previsao_entrega DATE,
                   FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
                   FOREIGN KEY (produto_id) REFERENCES produtos(id)
                 ) ENGINE=InnoDB'
            );
        }
        if (!self::temTabela('pacote_categorias')) {
            Database::executar(
                'CREATE TABLE pacote_categorias (
                   id INT AUTO_INCREMENT PRIMARY KEY,
                   pacote_id INT NOT NULL,
                   familia_id INT NOT NULL,
                   desconto_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
                   bonificacao_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
                   obrigatoria TINYINT(1) NOT NULL DEFAULT 0,
                   qtd_minima DECIMAL(12,2) NOT NULL DEFAULT 0,
                   UNIQUE KEY uk_pacote_familia (pacote_id, familia_id),
                   FOREIGN KEY (pacote_id) REFERENCES pacotes_agricolas(id) ON DELETE CASCADE,
                   FOREIGN KEY (familia_id) REFERENCES familias_produto(id)
                 ) ENGINE=InnoDB'
            );
        }
        if (!self::temTabela('pacote_obrigatorios')) {
            Database::executar(
                'CREATE TABLE pacote_obrigatorios (
                   id INT AUTO_INCREMENT PRIMARY KEY,
                   pacote_id INT NOT NULL,
                   produto_id INT NOT NULL,
                   dose_ha DECIMAL(10,3) NOT NULL DEFAULT 0,
                   num_aplicacoes TINYINT NOT NULL DEFAULT 1,
                   qtd_minima DECIMAL(12,2) NOT NULL DEFAULT 0,
                   qtd_maxima DECIMAL(12,2) NOT NULL DEFAULT 0,
                   UNIQUE KEY uk_pacote_produto (pacote_id, produto_id),
                   FOREIGN KEY (pacote_id) REFERENCES pacotes_agricolas(id) ON DELETE CASCADE,
                   FOREIGN KEY (produto_id) REFERENCES produtos(id)
                 ) ENGINE=InnoDB'
            );
        }

        // Seed leve de demonstração (apenas se as tabelas estiverem vazias e o seed base existir)
        $temProdutosSeed = (int) Database::valor('SELECT COUNT(*) FROM produtos WHERE id <= 17');
        if ($temProdutosSeed >= 17) {
            if ((float) Database::valor('SELECT COALESCE(SUM(estoque),0) FROM produtos') == 0.0) {
                Database::executar(
                    'UPDATE produtos SET estoque = CASE id
                       WHEN 1 THEN 850 WHEN 2 THEN 320 WHEN 3 THEN 180 WHEN 4 THEN 240
                       WHEN 5 THEN 4200 WHEN 6 THEN 1500 WHEN 7 THEN 950 WHEN 8 THEN 1200
                       WHEN 9 THEN 400 WHEN 10 THEN 800 WHEN 11 THEN 1100 WHEN 12 THEN 700
                       WHEN 13 THEN 2500 WHEN 14 THEN 300 WHEN 15 THEN 900
                       WHEN 16 THEN 1800 WHEN 17 THEN 1600 ELSE 0 END'
                );
            }
            if ((int) Database::valor('SELECT COUNT(*) FROM promocoes') === 0) {
                Database::executar(
                    "INSERT INTO promocoes (produto_id, descricao, desconto_pct, valido_ate) VALUES
                     (3,'Campanha de fertilizantes — antecipação safra 26/27',6.00,'2026-08-31'),
                     (7,'Programa fungicida antecipado',8.00,'2026-08-15'),
                     (16,'Ração leite — fidelidade inverno',4.00,'2026-08-31')"
                );
            }
            if ((int) Database::valor('SELECT COUNT(*) FROM entregas_futuras') === 0
                && (int) Database::valor('SELECT COUNT(*) FROM clientes WHERE id <= 7') >= 7) {
                Database::executar(
                    "INSERT INTO entregas_futuras (cliente_id, produto_id, quantidade_contratada, quantidade_retirada, data_contrato, previsao_entrega) VALUES
                     (1,3,65,40,'2025-09-22','2026-08-30'),
                     (1,1,320,320,'2025-09-18','2026-09-30'),
                     (2,3,38,20,'2025-09-30','2026-08-20'),
                     (6,3,30,12,'2025-10-12','2026-08-25'),
                     (7,16,650,380,'2026-03-08','2026-10-31')"
                );
            }
            if ((int) Database::valor('SELECT COUNT(*) FROM pacotes_agricolas') === 0) {
                Database::executar(
                    "INSERT INTO pacotes_agricolas (nome, cultura_id, safra_id, vigencia_inicio, vigencia_fim, regiao, campanha, bonificacao_sacas_ha) VALUES
                     ('Pacote Soja Alta Performance 26/27',1,2,'2026-06-01','2026-10-31','Alto Uruguai Catarinense','Campanha Safra 26/27',1.50)"
                );
                $pacoteId = Database::ultimoId();
                Database::executar(
                    "INSERT INTO pacote_categorias (pacote_id, familia_id, desconto_pct, bonificacao_pct, obrigatoria, qtd_minima) VALUES
                     ({$pacoteId},1,5.00,1.00,1,50),({$pacoteId},2,7.00,1.50,1,10),({$pacoteId},3,4.00,0.50,0,0),
                     ({$pacoteId},4,8.00,2.00,1,100),({$pacoteId},5,6.00,1.00,1,30),({$pacoteId},6,3.00,0.50,0,0),
                     ({$pacoteId},7,3.00,0.50,0,0),({$pacoteId},8,2.00,0.00,0,0)"
                );
                Database::executar(
                    "INSERT INTO pacote_obrigatorios (pacote_id, produto_id, dose_ha, num_aplicacoes, qtd_minima, qtd_maxima) VALUES
                     ({$pacoteId},7,0.75,3,100,2000),({$pacoteId},9,0.15,2,20,600),({$pacoteId},1,1.10,1,50,1200)"
                );
            }
        }
    }
}
