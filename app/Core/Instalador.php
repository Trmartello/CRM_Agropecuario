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
        if ($versao < 3) {
            self::migrarParaV3();
            Database::executar(
                "INSERT INTO configuracoes (chave, valor) VALUES ('schema_versao', '3')
                 ON DUPLICATE KEY UPDATE valor = '3'"
            );
        }
        if ($versao < 4) {
            self::migrarParaV4();
            Database::executar(
                "INSERT INTO configuracoes (chave, valor) VALUES ('schema_versao', '4')
                 ON DUPLICATE KEY UPDATE valor = '4'"
            );
        }
        if ($versao < 5) {
            self::migrarParaV5();
            Database::executar(
                "INSERT INTO configuracoes (chave, valor) VALUES ('schema_versao', '5')
                 ON DUPLICATE KEY UPDATE valor = '5'"
            );
        }
        if ($versao < 6) {
            self::migrarParaV6();
            Database::executar(
                "INSERT INTO configuracoes (chave, valor) VALUES ('schema_versao', '6')
                 ON DUPLICATE KEY UPDATE valor = '6'"
            );
        }
        if ($versao < 7) {
            self::migrarParaV7();
            Database::executar(
                "INSERT INTO configuracoes (chave, valor) VALUES ('schema_versao', '7')
                 ON DUPLICATE KEY UPDATE valor = '7'"
            );
        }
        if ($versao < 8) {
            self::migrarParaV8();
            Database::executar(
                "INSERT INTO configuracoes (chave, valor) VALUES ('schema_versao', '8')
                 ON DUPLICATE KEY UPDATE valor = '8'"
            );
        }
        if ($versao < 9) {
            self::migrarParaV9();
            Database::executar(
                "INSERT INTO configuracoes (chave, valor) VALUES ('schema_versao', '9')
                 ON DUPLICATE KEY UPDATE valor = '9'"
            );
        }
        if ($versao < 10) {
            self::adicionarColuna('agenda_eventos', 'ordem', 'ordem SMALLINT NOT NULL DEFAULT 0 AFTER hora');
            Database::executar(
                "INSERT INTO configuracoes (chave, valor) VALUES ('schema_versao', '10')
                 ON DUPLICATE KEY UPDATE valor = '10'"
            );
        }
        if ($versao < 11) {
            self::adicionarColuna('clientes', 'linha', 'linha VARCHAR(120) NULL AFTER municipio');
            Database::executar(
                "INSERT INTO configuracoes (chave, valor) VALUES ('schema_versao', '11')
                 ON DUPLICATE KEY UPDATE valor = '11'"
            );
        }
        if ($versao < 12) {
            self::adicionarColuna('visitas', 'finalizada',
                "finalizada TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = cadastro salvo incompleto' AFTER sincronizada_offline");
            self::adicionarColuna('visitas', 'completude',
                "completude TINYINT NOT NULL DEFAULT 100 COMMENT 'percentual de campos preenchidos' AFTER finalizada");
            Database::executar(
                "INSERT INTO configuracoes (chave, valor) VALUES ('schema_versao', '12')
                 ON DUPLICATE KEY UPDATE valor = '12'"
            );
        }
        if ($versao < 13) {
            // Idempotência do offline (O3): dedup de reenvios da fila por uuid.
            if (!self::temTabela('sync_processados')) {
                Database::executar(
                    'CREATE TABLE sync_processados (
                        uuid VARCHAR(36) NOT NULL PRIMARY KEY,
                        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                     ) ENGINE=InnoDB'
                );
            }
            Database::executar(
                "INSERT INTO configuracoes (chave, valor) VALUES ('schema_versao', '13')
                 ON DUPLICATE KEY UPDATE valor = '13'"
            );
        }
        if ($versao < 14) {
            // Segurança de produção: troca obrigatória de senha + bloqueio de tentativas
            self::adicionarColuna('usuarios', 'trocar_senha',
                "trocar_senha TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = deve definir nova senha no próximo acesso' AFTER ativo");
            if (!self::temTabela('login_tentativas')) {
                Database::executar(
                    'CREATE TABLE login_tentativas (
                        chave VARCHAR(190) NOT NULL PRIMARY KEY,
                        tentativas INT NOT NULL DEFAULT 0,
                        bloqueado_ate DATETIME NULL,
                        atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                     ) ENGINE=InnoDB'
                );
            }
            // Instalações existentes (ex.: Railway) ainda usam as senhas do seed:
            // obriga todo mundo a definir uma senha própria no próximo acesso.
            Database::executar('UPDATE usuarios SET trocar_senha = 1');
            Database::executar(
                "INSERT INTO configuracoes (chave, valor) VALUES ('schema_versao', '14')
                 ON DUPLICATE KEY UPDATE valor = '14'"
            );
        }
        if ($versao < 15) {
            // Trilha de auditoria ampliada (a tabela existe desde a Fase 1; ganha perfil e ip)
            self::adicionarColuna('auditoria', 'perfil', 'perfil VARCHAR(30) NULL AFTER usuario_id');
            self::adicionarColuna('auditoria', 'ip', 'ip VARCHAR(45) NULL AFTER dados');
            Database::executar(
                "INSERT INTO configuracoes (chave, valor) VALUES ('schema_versao', '15')
                 ON DUPLICATE KEY UPDATE valor = '15'"
            );
        }
        if ($versao < 16) {
            // Web Push: assinaturas por aparelho
            if (!self::temTabela('push_assinaturas')) {
                Database::executar(
                    'CREATE TABLE push_assinaturas (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        usuario_id INT NOT NULL,
                        endpoint_hash CHAR(64) NOT NULL UNIQUE,
                        endpoint TEXT NOT NULL,
                        p256dh VARCHAR(255) NULL,
                        auth VARCHAR(64) NULL,
                        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                     ) ENGINE=InnoDB'
                );
            }
            Database::executar(
                "INSERT INTO configuracoes (chave, valor) VALUES ('schema_versao', '16')
                 ON DUPLICATE KEY UPDATE valor = '16'"
            );
        }
        if ($versao < 17) {
            // Fase 6C: segmentação da carteira (calculada + override manual do gestor)
            self::adicionarColuna('clientes', 'segmento',
                "segmento CHAR(1) NULL COMMENT 'segmento calculado (A/B/C/D/P) — cache do SegmentacaoService' AFTER prospecto");
            self::adicionarColuna('clientes', 'segmento_manual',
                "segmento_manual CHAR(1) NULL COMMENT 'segmento fixado pelo gestor (prevalece sobre o calculado)' AFTER segmento");
            Database::executar(
                "INSERT INTO configuracoes (chave, valor) VALUES ('schema_versao', '17')
                 ON DUPLICATE KEY UPDATE valor = '17'"
            );
        }
        if ($versao < 18) {
            self::migrarParaV18();
            Database::executar(
                "INSERT INTO configuracoes (chave, valor) VALUES ('schema_versao', '18')
                 ON DUPLICATE KEY UPDATE valor = '18'"
            );
        }
        if ($versao < 19) {
            self::migrarParaV19();
            Database::executar(
                "INSERT INTO configuracoes (chave, valor) VALUES ('schema_versao', '19')
                 ON DUPLICATE KEY UPDATE valor = '19'"
            );
        }
        if ($versao < 20) {
            self::migrarParaV20();
            Database::executar(
                "INSERT INTO configuracoes (chave, valor) VALUES ('schema_versao', '20')
                 ON DUPLICATE KEY UPDATE valor = '20'"
            );
        }
        if ($versao < 21) {
            // Fenologia configurável: foto/arte personalizada por estágio
            // (NULL = ilustração padrão do sistema, gerada em SVG no app)
            self::adicionarColuna('fenologia_estagios', 'imagem',
                'imagem MEDIUMBLOB NULL COMMENT "foto/arte personalizada da fase (NULL = ilustração padrão do sistema)" AFTER caracteristicas');
            self::adicionarColuna('fenologia_estagios', 'imagem_mime',
                'imagem_mime VARCHAR(40) NULL AFTER imagem');
            Database::executar(
                "INSERT INTO configuracoes (chave, valor) VALUES ('schema_versao', '21')
                 ON DUPLICATE KEY UPDATE valor = '21'"
            );
        }
        if ($versao < 22) {
            // Fase 6A: croqui das propriedades — contorno GPS por talhão
            self::adicionarColuna('talhoes', 'contorno',
                'contorno TEXT NULL COMMENT "croqui: vértices [[lat,lng],...] marcados no campo (Fase 6A)" AFTER cultura_id');
            self::adicionarColuna('talhoes', 'area_gps',
                'area_gps DECIMAL(10,2) NULL COMMENT "área (ha) calculada pelo contorno GPS" AFTER contorno');
            Database::executar(
                "INSERT INTO configuracoes (chave, valor) VALUES ('schema_versao', '22')
                 ON DUPLICATE KEY UPDATE valor = '22'"
            );
        }
        if ($versao < 23) {
            // Croqui v2: divisa (área total) da PROPRIEDADE, além dos talhões
            self::adicionarColuna('propriedades', 'contorno',
                'contorno TEXT NULL COMMENT "croqui: divisa da propriedade [[lat,lng],...] (Fase 6A)" AFTER longitude');
            self::adicionarColuna('propriedades', 'area_gps',
                'area_gps DECIMAL(10,2) NULL COMMENT "área total (ha) calculada pelo contorno GPS" AFTER contorno');
            Database::executar(
                "INSERT INTO configuracoes (chave, valor) VALUES ('schema_versao', '23')
                 ON DUPLICATE KEY UPDATE valor = '23'"
            );
        }
        if ($versao < 24) {
            // Visita rápida de campo: Iniciar Visita (duração real) + produtor presente
            self::adicionarColuna('visitas', 'hora_inicio',
                'hora_inicio TIME NULL COMMENT "botão Iniciar Visita (carimba a chegada no campo)" AFTER sincronizada_offline');
            self::adicionarColuna('visitas', 'hora_fim',
                'hora_fim TIME NULL COMMENT "preenchida ao salvar quando a visita foi iniciada — dá a duração real" AFTER hora_inicio');
            self::adicionarColuna('visitas', 'produtor_presente',
                'produtor_presente TINYINT(1) NULL COMMENT "1/0 = produtor estava presente na visita (NULL = não informado)" AFTER hora_fim');
            Database::executar(
                "INSERT INTO configuracoes (chave, valor) VALUES ('schema_versao', '24')
                 ON DUPLICATE KEY UPDATE valor = '24'"
            );
        }
        if ($versao < 25) {
            // Auditoria de campo (antifraude): GPS do "Iniciar Visita" + verificação
            // do local do lançamento contra a propriedade cadastrada
            self::adicionarColuna('visitas', 'inicio_lat',
                'inicio_lat DECIMAL(10,7) NULL COMMENT "GPS capturado ao tocar em Iniciar Visita" AFTER produtor_presente');
            self::adicionarColuna('visitas', 'inicio_lng',
                'inicio_lng DECIMAL(10,7) NULL AFTER inicio_lat');
            self::adicionarColuna('visitas', 'inicio_precisao',
                'inicio_precisao SMALLINT UNSIGNED NULL COMMENT "precisão do GPS em metros" AFTER inicio_lng');
            self::adicionarColuna('visitas', 'dist_propriedade_m',
                'dist_propriedade_m INT NULL COMMENT "distância (m) do lançamento à propriedade (0 = dentro do croqui)" AFTER inicio_precisao');
            self::adicionarColuna('visitas', 'fora_propriedade',
                'fora_propriedade TINYINT(1) NULL COMMENT "1 = lançada fora da propriedade cadastrada; NULL = sem GPS/referência" AFTER dist_propriedade_m');
            // Comentário individual por foto (visita_fotos.legenda já existia)
            self::adicionarColuna('reclamacao_fotos', 'legenda',
                'legenda VARCHAR(255) NULL AFTER arquivo');
            Database::executar(
                "INSERT INTO configuracoes (chave, valor) VALUES ('schema_versao', '25')
                 ON DUPLICATE KEY UPDATE valor = '25'"
            );
        }
        if ($versao < 26) {
            // Integração Qlik/ERP: código do vendedor no ERP vincula as cargas do CAP
            self::adicionarColuna('usuarios', 'cod_vendedor',
                'cod_vendedor INT NULL COMMENT "código do vendedor no ERP/CAP (vincula as cargas do Qlik)" AFTER cliente_id');
            Database::executar(
                "INSERT INTO configuracoes (chave, valor) VALUES ('schema_versao', '26')
                 ON DUPLICATE KEY UPDATE valor = '26'"
            );
        }
        if ($versao < 27) {
            // Carga de clientes do Qlik: código do cliente no ERP (upsert idempotente)
            self::adicionarColuna('clientes', 'cod_erp',
                'cod_erp INT NULL COMMENT "código do cliente no ERP (vincula as cargas do Qlik)" AFTER prospecto');
            Database::executar(
                "INSERT INTO configuracoes (chave, valor) VALUES ('schema_versao', '27')
                 ON DUPLICATE KEY UPDATE valor = '27'"
            );
        }
        if ($versao < 28) {
            // CAR (SICAR): número de inscrição + divisa oficial importada do shapefile
            self::adicionarColuna('propriedades', 'car_numero',
                'car_numero VARCHAR(60) NULL COMMENT "número de inscrição no CAR (SICAR)" AFTER area_gps');
            Database::executar(
                "INSERT INTO configuracoes (chave, valor) VALUES ('schema_versao', '28')
                 ON DUPLICATE KEY UPDATE valor = '28'"
            );
        }
        if ($versao < 29) {
            // Base do CAR por município (identificar imóvel por GPS, offline)
            if (!self::temTabela('car_imoveis')) {
                Database::executar(
                    'CREATE TABLE car_imoveis (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        cod_imovel VARCHAR(80) NOT NULL,
                        municipio VARCHAR(120) NOT NULL,
                        uf CHAR(2) NOT NULL DEFAULT "SC",
                        contorno MEDIUMTEXT NOT NULL,
                        area_ha DECIMAL(10,2) NULL,
                        min_lat DECIMAL(10,7) NOT NULL,
                        min_lng DECIMAL(10,7) NOT NULL,
                        max_lat DECIMAL(10,7) NOT NULL,
                        max_lng DECIMAL(10,7) NOT NULL,
                        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_car_municipio (municipio, uf),
                        INDEX idx_car_bbox (min_lat, max_lat, min_lng, max_lng)
                     ) ENGINE=InnoDB'
                );
            }
            Database::executar(
                "INSERT INTO configuracoes (chave, valor) VALUES ('schema_versao', '29')
                 ON DUPLICATE KEY UPDATE valor = '29'"
            );
        }
        if ($versao < 30) {
            // Código IBGE do município no CAR: dedup por município na importação
            // (importar município a município acumula, sem apagar os anteriores).
            // O contorno passa a aceitar multipolygon (imóveis com partes desconexas).
            self::adicionarColuna('car_imoveis', 'cod_ibge',
                "cod_ibge VARCHAR(7) NULL COMMENT 'código IBGE do município (do cod_imovel do SICAR)' AFTER cod_imovel");
            if (self::temTabela('car_imoveis') && !self::temIndice('car_imoveis', 'idx_car_ibge')) {
                Database::executar('ALTER TABLE car_imoveis ADD INDEX idx_car_ibge (cod_ibge)');
            }
            Database::executar(
                "INSERT INTO configuracoes (chave, valor) VALUES ('schema_versao', '30')
                 ON DUPLICATE KEY UPDATE valor = '30'"
            );
        }
        if ($versao < 31) {
            // Backfill dos imóveis do CAR já importados (antes do v30, sem cod_ibge):
            // deriva o código IBGE do próprio cod_imovel e preenche o NOME do
            // município pela tabela oficial (MunicipiosSul, Sul do país). Assim os
            // dados existentes ganham o nome certo e passam a deduplicar por IBGE
            // sem precisar reimportar.
            if (self::temColuna('car_imoveis', 'cod_ibge')) {
                $rows = Database::todos('SELECT id, cod_imovel FROM car_imoveis WHERE cod_ibge IS NULL');
                foreach ($rows as $r) {
                    $ibge = \App\Services\ShapefileService::ibgeDeCodImovel((string) $r['cod_imovel']);
                    if (!$ibge) {
                        continue;
                    }
                    $nome = \App\Services\MunicipiosSul::nome($ibge);
                    if ($nome !== null) {
                        Database::executar(
                            'UPDATE car_imoveis SET cod_ibge = ?, municipio = ? WHERE id = ?',
                            [$ibge, mb_strtoupper($nome), (int) $r['id']]
                        );
                    } else {
                        Database::executar('UPDATE car_imoveis SET cod_ibge = ? WHERE id = ?', [$ibge, (int) $r['id']]);
                    }
                }
            }
            Database::executar(
                "INSERT INTO configuracoes (chave, valor) VALUES ('schema_versao', '31')
                 ON DUPLICATE KEY UPDATE valor = '31'"
            );
        }
    }

    /** Fase 6E (refinamento): características fisiológicas por estágio (cartão ilustrado). */
    private static function migrarParaV20(): void
    {
        self::adicionarColuna('fenologia_estagios', 'caracteristicas',
            "caracteristicas VARCHAR(600) NULL COMMENT 'características fisiológicas para identificar a fase no campo' AFTER grupo");
        $textos = [
            [1, 'VE', 'Cotilédones acima do solo e folhas unifolioladas abrindo. Estande ainda em definição — conte plantas por metro.'],
            [1, 'V2-V4', 'Conte os trifólios completamente desenvolvidos: entre 2 e 4. Planta com 15–30 cm, nós bem visíveis.'],
            [1, 'V5+', '5 ou mais trifólios; copa fechando as entrelinhas. Crescimento vegetativo intenso, sem estruturas reprodutivas.'],
            [1, 'R1-R2', 'Flores abertas em qualquer nó (R1) até floração plena com flores nos nós superiores (R2). Flores brancas ou roxas.'],
            [1, 'R3-R4', 'Vagens de 0,5 cm ("canivetinho", R3) a 2 cm (R4) nos 4 nós superiores da haste principal.'],
            [1, 'R5', 'Grãos perceptíveis ao tato dentro das vagens (1–10 mm). Maior demanda de água e nutrientes do ciclo.'],
            [1, 'R6', 'Vagens com grãos verdes preenchendo toda a cavidade. Folhas ainda verdes, início do amarelecimento embaixo.'],
            [1, 'R7-R8', 'Uma vagem madura na haste principal (R7) até 95% das vagens maduras (R8). Folhas caindo, planta dourada.'],
            [2, 'VE', 'Coleóptilo rompendo o solo; plântula com até 2 folhas. Uniformidade de emergência define o potencial.'],
            [2, 'V3-V5', '3 a 5 folhas com colar visível. Ponto de crescimento ainda abaixo do solo — fase que define fileiras da espiga.'],
            [2, 'V6-V8', '6 a 8 folhas com colar; colmo alongando rápido. Espiga em definição de tamanho.'],
            [2, 'V9-VT', 'Folhas superiores enroladas (emborrachamento) até o pendão totalmente visível (VT).'],
            [2, 'R1', 'Cabelos (estilo-estigmas) visíveis fora da espiga — polinização em curso. Fase mais sensível a estresse.'],
            [2, 'R2-R4', "Grão de bolha d'água (R2) a pastoso (R4); linha do leite avançando no grão."],
            [2, 'R5-R6', 'Grão dentado (R5) até a camada preta na base do grão (R6) — maturação fisiológica; planta secando.'],
            [3, 'F1-3', 'Plântulas com 1 a 3 folhas; início da emissão de perfilhos. Conte plantas/m² para avaliar o estande.'],
            [3, 'F4-5', 'Touceira formada com perfilhos eretos; pseudocolmo alongando. Nº de perfilhos define espigas por planta.'],
            [3, 'F6-10', '1º e 2º nós visíveis no colmo; folha bandeira emergindo até o emborrachamento (bota).'],
            [3, 'F10.1-10.5', 'Espiga emergindo da bainha até floração plena — anteras amarelas visíveis. Janela crítica para giberela.'],
            [3, 'F11.1-11.2', 'Grão leitoso a massa mole; espiga verde clareando. Peso do grão em definição.'],
            [3, 'F11.3-11.4', 'Grão duro; planta dourada e nós escurecidos. Ponto de colheita — atenção à umidade e chuvas.'],
        ];
        foreach ($textos as [$cultura, $codigo, $texto]) {
            Database::executar(
                'UPDATE fenologia_estagios SET caracteristicas = ? WHERE cultura_id = ? AND codigo = ? AND caracteristicas IS NULL',
                [$texto, $cultura, $codigo]
            );
        }
    }

    /**
     * Fase 6E (refinamento): macrofases na fenologia (faixas Vegetativo/
     * Reprodutivo etc.) + escala Feekes-Large do trigo.
     */
    private static function migrarParaV19(): void
    {
        self::adicionarColuna('fenologia_estagios', 'grupo',
            "grupo VARCHAR(40) NULL COMMENT 'macrofase exibida como faixa (ex.: Vegetativo, Reprodutivo)' AFTER ordem");
        // Backfill soja/milho: códigos R* são reprodutivos, o resto vegetativo
        Database::executar(
            "UPDATE fenologia_estagios SET grupo = CASE WHEN codigo LIKE 'R%' THEN 'Reprodutivo' ELSE 'Vegetativo' END
              WHERE grupo IS NULL AND cultura_id IN (1,2)"
        );
        // Trigo (Feekes-Large) — só se a cultura 3 existir e ainda não tiver fenologia
        // (guard evita erro de FK travar a migração num banco com seed alterado)
        if (!Database::valor('SELECT 1 FROM culturas WHERE id = 3')
            || (int) Database::valor('SELECT COUNT(*) FROM fenologia_estagios WHERE cultura_id = 3') > 0) {
            return;
        }
        $estagios = [
            ['F1-3', 'Afilhamento inicial', 0, 30, 'Emergência ao início do afilhamento — estabelecimento do estande', 1, 'Afilhamento',
                [['Avaliar estande (plantas/m²)', 1, 'Contar plantas/m² e comparar com a meta da cultivar; falhas comprometem o rendimento.'],
                 ['Herbicida pós-emergente (azevém/nabo)', 3, 'Controlar cedo — a matocompetição no afilhamento reduz perfilhos.']]],
            ['F4-5', 'Afilhamento pleno', 31, 45, 'Perfilhos formados — define o nº de espigas por planta', 2, 'Afilhamento',
                [['1ª adubação nitrogenada de cobertura', 2, 'N no afilhamento define espigas por planta.']]],
            ['F6-10', 'Alongamento do colmo', 46, 70, 'Crescimento do colmo e da espiga — proteção da folha bandeira', 3, 'Alongamento',
                [['2ª cobertura de nitrogênio', 2, 'Completar o N no início do alongamento conforme expectativa de produtividade.'],
                 ['1ª aplicação de fungicida (manchas foliares)', 4, 'Proteger a folha bandeira — principal fonte de enchimento do grão.'],
                 ['Monitorar pulgões', 5, 'Vetores de viroses (nanismo-amarelo); controlar pelo nível de dano.']]],
            ['F10.1-10.5', 'Espigamento e florescimento', 71, 85, 'Espiga emergida e floração — janela crítica da giberela', 4, 'Espigamento',
                [['Fungicida para giberela', 4, 'Aplicar no espigamento/floração, especialmente com molhamento prolongado — janela crítica.']]],
            ['F11.1-11.2', 'Enchimento de grãos', 86, 110, 'Grão leitoso a massa mole — define o peso do grão', 5, 'Enchimento',
                [['Monitorar percevejos e lagartas da espiga', 5, 'Dano direto ao grão no enchimento; amostrar semanalmente.']]],
            ['F11.3-11.4', 'Maturação', 111, 135, 'Massa dura à maturação de colheita', 6, 'Maturação',
                [['Planejar colheita: umidade e germinação na espiga', null, 'Colher na janela para preservar PH e evitar germinação na espiga com chuva.']]],
        ];
        foreach ($estagios as [$codigo, $nome, $ini, $fim, $desc, $ordem, $grupo, $manejos]) {
            Database::executar(
                'INSERT INTO fenologia_estagios (cultura_id, codigo, nome, dias_inicio, dias_fim, descricao, ordem, grupo)
                 VALUES (3,?,?,?,?,?,?,?)',
                [$codigo, $nome, $ini, $fim, $desc, $ordem, $grupo]
            );
            $estagioId = Database::ultimoId();
            foreach ($manejos as [$titulo, $familiaId, $orientacao]) {
                Database::executar(
                    'INSERT INTO manejos_fase (estagio_id, titulo, familia_id, orientacao, eh_checklist) VALUES (?,?,?,?,1)',
                    [$estagioId, $titulo, $familiaId, $orientacao]
                );
            }
        }
    }

    /**
     * Fase 6E: linha do tempo da cultura — plantios por talhão, estágios
     * fenológicos de referência, manejos por fase e checklist da visita.
     * Seeda só a REFERÊNCIA (fenologia/manejos); plantios são dados do usuário.
     */
    private static function migrarParaV18(): void
    {
        if (!self::temTabela('plantios')) {
            Database::executar(
                'CREATE TABLE plantios (
                   id INT AUTO_INCREMENT PRIMARY KEY,
                   talhao_id INT NOT NULL,
                   cultura_id INT NOT NULL,
                   safra_id INT,
                   data_plantio DATE NOT NULL,
                   cultivar VARCHAR(120),
                   encerrado TINYINT(1) NOT NULL DEFAULT 0,
                   colhido_em DATE NULL,
                   produtividade DECIMAL(10,2) NULL COMMENT "sacas/ha colhidas (informada no encerramento)",
                   criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                   FOREIGN KEY (talhao_id) REFERENCES talhoes(id) ON DELETE CASCADE,
                   FOREIGN KEY (cultura_id) REFERENCES culturas(id),
                   FOREIGN KEY (safra_id) REFERENCES safras(id)
                 ) ENGINE=InnoDB'
            );
        }
        if (!self::temTabela('fenologia_estagios')) {
            Database::executar(
                'CREATE TABLE fenologia_estagios (
                   id INT AUTO_INCREMENT PRIMARY KEY,
                   cultura_id INT NOT NULL,
                   codigo VARCHAR(12) NOT NULL,
                   nome VARCHAR(120) NOT NULL,
                   dias_inicio SMALLINT NOT NULL,
                   dias_fim SMALLINT NOT NULL,
                   descricao VARCHAR(255),
                   ordem SMALLINT NOT NULL DEFAULT 0,
                   FOREIGN KEY (cultura_id) REFERENCES culturas(id) ON DELETE CASCADE
                 ) ENGINE=InnoDB'
            );
        }
        if (!self::temTabela('manejos_fase')) {
            Database::executar(
                'CREATE TABLE manejos_fase (
                   id INT AUTO_INCREMENT PRIMARY KEY,
                   estagio_id INT NOT NULL,
                   titulo VARCHAR(160) NOT NULL,
                   familia_id INT NULL,
                   orientacao VARCHAR(500),
                   eh_checklist TINYINT(1) NOT NULL DEFAULT 1,
                   FOREIGN KEY (estagio_id) REFERENCES fenologia_estagios(id) ON DELETE CASCADE,
                   FOREIGN KEY (familia_id) REFERENCES familias_produto(id)
                 ) ENGINE=InnoDB'
            );
        }
        if (!self::temTabela('visita_checklist')) {
            Database::executar(
                'CREATE TABLE visita_checklist (
                   id INT AUTO_INCREMENT PRIMARY KEY,
                   visita_id INT NOT NULL,
                   manejo_id INT NOT NULL,
                   situacao ENUM("OK","Atenção","Crítico","N/A") NOT NULL,
                   observacao VARCHAR(255),
                   UNIQUE KEY uq_visita_manejo (visita_id, manejo_id),
                   FOREIGN KEY (visita_id) REFERENCES visitas(id) ON DELETE CASCADE,
                   FOREIGN KEY (manejo_id) REFERENCES manejos_fase(id) ON DELETE CASCADE
                 ) ENGINE=InnoDB'
            );
        }
        // Seed da referência (só se vazio — nunca sobrescreve ajustes locais)
        if ((int) Database::valor('SELECT COUNT(*) FROM fenologia_estagios') === 0) {
            Database::executar(
                "INSERT INTO fenologia_estagios (id, cultura_id, codigo, nome, dias_inicio, dias_fim, descricao, ordem) VALUES
                 (1,1,'VE','Emergência',0,10,'Da semeadura à emergência das plântulas',1),
                 (2,1,'V2-V4','Desenvolvimento vegetativo',11,30,'2 a 4 trifólios — definição do estande',2),
                 (3,1,'V5+','Fechamento das entrelinhas',31,44,'Crescimento vegetativo pleno',3),
                 (4,1,'R1-R2','Florescimento',45,59,'Início e plena floração',4),
                 (5,1,'R3-R4','Formação de vagens',60,74,'Canivetinho a vagem formada',5),
                 (6,1,'R5','Enchimento de grãos',75,94,'Fase de maior demanda hídrica e nutricional',6),
                 (7,1,'R6','Grão cheio',95,109,'Grãos com volume máximo',7),
                 (8,1,'R7-R8','Maturação',110,135,'Maturação fisiológica à colheita',8),
                 (9,2,'VE','Emergência',0,8,'Da semeadura à emergência',1),
                 (10,2,'V3-V5','Definição da produtividade',9,25,'Estádio que define o número de fileiras da espiga',2),
                 (11,2,'V6-V8','Desenvolvimento vegetativo',26,40,'Crescimento acelerado do colmo',3),
                 (12,2,'V9-VT','Pré-pendoamento',41,60,'Emborrachamento ao pendoamento',4),
                 (13,2,'R1','Polinização',61,75,'Embonecamento — fase mais sensível a estresse',5),
                 (14,2,'R2-R4','Enchimento de grãos',76,105,'Grão leitoso a pastoso',6),
                 (15,2,'R5-R6','Maturação',106,140,'Formação de dente à maturação fisiológica',7)"
            );
            Database::executar(
                "INSERT INTO manejos_fase (estagio_id, titulo, familia_id, orientacao, eh_checklist) VALUES
                 (1,'Avaliar estande e emergência',1,'Contar população de plantas por metro e comparar com a meta da cultivar; decidir replantio até V2.',1),
                 (1,'Controle de daninhas em pós-emergência inicial',3,'Aplicar com as daninhas pequenas (até 4 folhas); atenção a buva e azevém resistentes.',1),
                 (2,'Herbicida pós-emergente',3,'Completar o controle antes do fechamento; verificar falhas de aplicação.',1),
                 (2,'Monitorar lagartas desfolhadoras',5,'Limite de desfolha na fase vegetativa: 30%.',1),
                 (3,'Adubação foliar com micronutrientes',6,'Mn, Co e Mo conforme análise; aproveitar a entrada do fechamento.',1),
                 (3,'Monitorar doenças de início de ciclo',4,'Oídio e manchas iniciais; registrar pressão para posicionar o programa.',1),
                 (4,'1ª aplicação de fungicida (ferrugem asiática)',4,'Posicionamento preventivo no florescimento; reaplicar em 14–21 dias.',1),
                 (4,'Monitorar percevejos — início',5,'Amostrar com pano de batida; registrar espécies e níveis.',1),
                 (5,'2ª aplicação de fungicida',4,'Sequência do programa; rotacionar mecanismos de ação.',1),
                 (5,'Inseticida para percevejos',5,'Nível de controle: 2 percevejos/pano (1 em campos de semente).',1),
                 (6,'3ª aplicação de fungicida (se houver pressão)',4,'Avaliar pressão de ferrugem e clima antes de fechar o programa.',1),
                 (6,'Percevejo — fase crítica do enchimento',5,'Dano direto no grão: rigor no monitoramento semanal.',1),
                 (6,'Adubação foliar de enchimento',6,'Potássio/nitrogênio foliar conforme demanda.',1),
                 (8,'Dessecação pré-colheita',3,'Aplicar em R7.3 quando indicado; respeitar o período de carência.',1),
                 (8,'Planejar colheita: umidade e perdas',NULL,'Colher entre 13–15% de umidade; regular a plataforma para perdas < 1 sc/ha.',1),
                 (9,'Avaliar estande e emergência',1,'População final define a produtividade; avaliar falhas e replantio.',1),
                 (10,'Adubação nitrogenada de cobertura (1ª)',2,'Aplicar N em V3–V4 — estádio que define as fileiras da espiga.',1),
                 (10,'Herbicida pós-emergente',3,'Milho é sensível à matocompetição inicial; controlar cedo.',1),
                 (10,'Monitorar cigarrinha-do-milho',5,'Vetor dos enfezamentos: controle no início do ciclo.',1),
                 (11,'2ª cobertura nitrogenada',2,'Completar o N até V8 conforme expectativa de produtividade.',1),
                 (11,'Lagarta-do-cartucho',5,'Controlar com dano no cartucho acima de 20% das plantas.',1),
                 (12,'1ª aplicação de fungicida',4,'Pré-pendoamento: proteger folha bandeira e colmo.',1),
                 (12,'Adubação foliar',6,'Complementar micronutrientes no pré-pendoamento.',1),
                 (13,'2ª aplicação de fungicida (doenças foliares)',4,'Proteger a polinização — fase mais sensível a estresse.',1),
                 (14,'Monitorar percevejo barriga-verde e doenças de colmo',5,'Avaliar colmos e grãos; risco de tombamento.',1),
                 (15,'Planejar colheita: umidade e perdas',NULL,'Acompanhar a dry-down; colher na janela para evitar grãos ardidos.',1)"
            );
        }
    }

    /** Fase 5: log de integração (ERP/CAPE). */
    private static function migrarParaV9(): void
    {
        if (!self::temTabela('integracao_log')) {
            Database::executar(
                'CREATE TABLE integracao_log (
                   id INT AUTO_INCREMENT PRIMARY KEY,
                   fonte VARCHAR(40) NOT NULL,
                   entidade VARCHAR(60) NOT NULL,
                   direcao ENUM("Importação","Exportação") NOT NULL DEFAULT "Importação",
                   status ENUM("Sucesso","Parcial","Erro") NOT NULL DEFAULT "Sucesso",
                   registros INT NOT NULL DEFAULT 0,
                   mensagem VARCHAR(255),
                   usuario_id INT NULL,
                   criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                   FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
                 ) ENGINE=InnoDB'
            );
        }
    }

    /** Fase 4: agenda, notificações e vínculo do Produtor ao cliente (portal). */
    private static function migrarParaV8(): void
    {
        self::adicionarColuna('usuarios', 'cliente_id', 'cliente_id INT NULL AFTER categoria_reembolso_id');
        if (!self::temTabela('agenda_eventos')) {
            Database::executar(
                'CREATE TABLE agenda_eventos (
                   id INT AUTO_INCREMENT PRIMARY KEY,
                   usuario_id INT NOT NULL,
                   cliente_id INT NULL,
                   tipo ENUM("Visita","Reunião","Tarefa","Entrega","Cobrança","Outro") NOT NULL DEFAULT "Visita",
                   titulo VARCHAR(160) NOT NULL,
                   data DATE NOT NULL,
                   hora TIME NULL,
                   status ENUM("Pendente","Concluído","Cancelado") NOT NULL DEFAULT "Pendente",
                   descricao VARCHAR(255),
                   criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                   FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
                   FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE SET NULL
                 ) ENGINE=InnoDB'
            );
        }
        if (!self::temTabela('notificacoes')) {
            Database::executar(
                'CREATE TABLE notificacoes (
                   id INT AUTO_INCREMENT PRIMARY KEY,
                   usuario_id INT NOT NULL,
                   tipo VARCHAR(40) NOT NULL,
                   titulo VARCHAR(160) NOT NULL,
                   texto VARCHAR(255),
                   link VARCHAR(160),
                   lida TINYINT(1) NOT NULL DEFAULT 0,
                   criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                   FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
                   INDEX idx_notif_usuario (usuario_id, lida)
                 ) ENGINE=InnoDB'
            );
        }
    }

    /** Fase 3 (ajuste): municípios pré-cadastrados (UF atrelado ao município). */
    private static function migrarParaV7(): void
    {
        if (!self::temTabela('municipios')) {
            Database::executar(
                'CREATE TABLE municipios (
                   id INT AUTO_INCREMENT PRIMARY KEY,
                   nome VARCHAR(120) NOT NULL,
                   estado CHAR(2) NOT NULL,
                   ativo TINYINT(1) NOT NULL DEFAULT 1,
                   UNIQUE KEY uk_municipio (nome, estado)
                 ) ENGINE=InnoDB'
            );
        }
        if ((int) Database::valor('SELECT COUNT(*) FROM municipios') === 0) {
            Database::executar(
                "INSERT IGNORE INTO municipios (nome, estado) VALUES
                 ('Concórdia','SC'),('Seara','SC'),('Chapecó','SC'),('Ipumirim','SC'),('Itá','SC'),
                 ('Arabutã','SC'),('Lindóia do Sul','SC'),('Irani','SC'),('Presidente Castello Branco','SC'),
                 ('Peritiba','SC'),('Piratuba','SC'),('Alto Bela Vista','SC'),('Xavantina','SC'),('Arvoredo','SC'),
                 ('Paial','SC'),('Ipira','SC'),('Jaborá','SC'),('Xanxerê','SC'),('Xaxim','SC'),('Coronel Freitas','SC'),
                 ('Águas de Chapecó','SC'),('Nova Erechim','SC'),('Cordilheira Alta','SC'),('Guatambú','SC'),
                 ('Erval Velho','SC'),('Joaçaba','SC'),('Capinzal','SC'),('Ouro','SC'),('Marcelino Ramos','RS'),('Erechim','RS')"
            );
        }
    }

    /** Fase 3 (ajuste): pré-cadastro de prospecto e amarração da KM com a visita. */
    private static function migrarParaV6(): void
    {
        self::adicionarColuna('clientes', 'prospecto', 'prospecto TINYINT(1) NOT NULL DEFAULT 0 AFTER limite_credito');
        self::adicionarColuna('quilometragem', 'visita_id', 'visita_id INT NULL AFTER veiculo_id');
    }

    /** Fase 3 (ajuste): refeições por tipo com reembolso por categoria/tipo e comprovante. */
    private static function migrarParaV5(): void
    {
        self::adicionarColuna('refeicoes', 'hora', 'hora TIME NULL AFTER data');
        self::adicionarColuna('refeicoes', 'tipo',
            "tipo ENUM('Café','Almoço','Lanche','Janta') NOT NULL DEFAULT 'Almoço' AFTER hora");
        self::adicionarColuna('refeicoes', 'valor_reembolso', 'valor_reembolso DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER valor');
        self::adicionarColuna('refeicoes', 'comprovante', 'comprovante VARCHAR(255) NULL AFTER valor_reembolso');
        self::adicionarColuna('prestacao_contas', 'total_refeicoes_gasto',
            'total_refeicoes_gasto DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER total_km_valor');

        if (!self::temTabela('reembolso_refeicoes')) {
            Database::executar(
                'CREATE TABLE reembolso_refeicoes (
                   id INT AUTO_INCREMENT PRIMARY KEY,
                   categoria_reembolso_id INT NOT NULL,
                   tipo ENUM("Café","Almoço","Lanche","Janta") NOT NULL,
                   valor DECIMAL(8,2) NOT NULL DEFAULT 0,
                   UNIQUE KEY uk_reembolso_ref (categoria_reembolso_id, tipo),
                   FOREIGN KEY (categoria_reembolso_id) REFERENCES categorias_reembolso(id) ON DELETE CASCADE
                 ) ENGINE=InnoDB'
            );
        }

        // Semente dos valores por tipo a partir do teto_refeicao existente (só se vazio)
        if ((int) Database::valor('SELECT COUNT(*) FROM reembolso_refeicoes') === 0) {
            $categorias = Database::todos('SELECT id, teto_refeicao FROM categorias_reembolso');
            foreach ($categorias as $c) {
                $teto = (float) $c['teto_refeicao'];
                // Distribui um valor razoável por tipo com base no teto (almoço/janta cheios, café/lanche parciais)
                $valores = [
                    'Café' => round($teto * 0.35, 2),
                    'Almoço' => $teto,
                    'Lanche' => round($teto * 0.35, 2),
                    'Janta' => round($teto * 0.85, 2),
                ];
                foreach ($valores as $tipo => $valor) {
                    Database::executar(
                        'INSERT IGNORE INTO reembolso_refeicoes (categoria_reembolso_id, tipo, valor) VALUES (?,?,?)',
                        [(int) $c['id'], $tipo, $valor]
                    );
                }
            }
        }
    }

    /** Fase 3 (ajuste): veículos por usuário e destino estruturado na quilometragem. */
    private static function migrarParaV4(): void
    {
        if (!self::temTabela('veiculos')) {
            Database::executar(
                'CREATE TABLE veiculos (
                   id INT AUTO_INCREMENT PRIMARY KEY,
                   usuario_id INT NOT NULL,
                   descricao VARCHAR(120) NOT NULL,
                   placa VARCHAR(20),
                   ativo TINYINT(1) NOT NULL DEFAULT 1,
                   criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                   FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
                 ) ENGINE=InnoDB'
            );
        }
        self::adicionarColuna('quilometragem', 'veiculo_id', 'veiculo_id INT NULL AFTER prestacao_id');
        self::adicionarColuna('quilometragem', 'tipo_destino',
            "tipo_destino ENUM('Produtor','Filial','Lugar') NOT NULL DEFAULT 'Lugar' AFTER valor");
        self::adicionarColuna('quilometragem', 'filial_id', 'filial_id INT NULL AFTER cliente_id');
        self::adicionarColuna('quilometragem', 'prospecto', 'prospecto VARCHAR(160) NULL AFTER filial_id');
    }

    /** Fase 3: reembolso por categoria, despesas (KM/refeições), prestação de contas, reclamações e documentos. */
    private static function migrarParaV3(): void
    {
        if (!self::temTabela('categorias_reembolso')) {
            Database::executar(
                'CREATE TABLE categorias_reembolso (
                   id INT AUTO_INCREMENT PRIMARY KEY,
                   nome VARCHAR(80) NOT NULL,
                   valor_km DECIMAL(8,2) NOT NULL DEFAULT 0,
                   teto_refeicao DECIMAL(8,2) NOT NULL DEFAULT 0,
                   ativo TINYINT(1) NOT NULL DEFAULT 1,
                   criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                 ) ENGINE=InnoDB'
            );
        }
        self::adicionarColuna('usuarios', 'categoria_reembolso_id',
            'categoria_reembolso_id INT NULL AFTER telefone');

        if (!self::temTabela('prestacao_contas')) {
            Database::executar(
                'CREATE TABLE prestacao_contas (
                   id INT AUTO_INCREMENT PRIMARY KEY,
                   usuario_id INT NOT NULL,
                   ano SMALLINT NOT NULL,
                   mes TINYINT NOT NULL,
                   total_km DECIMAL(10,1) NOT NULL DEFAULT 0,
                   total_km_valor DECIMAL(12,2) NOT NULL DEFAULT 0,
                   total_refeicoes DECIMAL(12,2) NOT NULL DEFAULT 0,
                   total_geral DECIMAL(12,2) NOT NULL DEFAULT 0,
                   status ENUM("Aberta","Enviada","Aprovada","Rejeitada") NOT NULL DEFAULT "Aberta",
                   observacao VARCHAR(255),
                   enviado_em DATETIME NULL,
                   avaliado_por INT NULL,
                   avaliado_em DATETIME NULL,
                   parecer VARCHAR(255),
                   criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                   UNIQUE KEY uk_prestacao (usuario_id, ano, mes),
                   FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
                   FOREIGN KEY (avaliado_por) REFERENCES usuarios(id)
                 ) ENGINE=InnoDB'
            );
        }

        // Colunas novas em tabelas criadas na Fase 1 (sem telas até agora)
        self::adicionarColuna('reclamacoes', 'usuario_id', 'usuario_id INT NULL AFTER cliente_id');
        self::adicionarColuna('reclamacoes', 'parecer', 'parecer TEXT NULL');
        self::adicionarColuna('reclamacoes', 'valor_indenizacao', 'valor_indenizacao DECIMAL(12,2) NULL');
        self::adicionarColuna('reclamacoes', 'atualizado_em', 'atualizado_em DATETIME NULL');
        self::adicionarColuna('quilometragem', 'prestacao_id', 'prestacao_id INT NULL AFTER usuario_id');
        self::adicionarColuna('quilometragem', 'valor', 'valor DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER km_final');
        self::adicionarColuna('quilometragem', 'criado_em', 'criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
        self::adicionarColuna('refeicoes', 'prestacao_id', 'prestacao_id INT NULL AFTER usuario_id');
        self::adicionarColuna('refeicoes', 'criado_em', 'criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');

        if (!self::temTabela('reclamacao_fotos')) {
            Database::executar(
                'CREATE TABLE reclamacao_fotos (
                   id INT AUTO_INCREMENT PRIMARY KEY,
                   reclamacao_id INT NOT NULL,
                   arquivo VARCHAR(255) NOT NULL,
                   criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                   FOREIGN KEY (reclamacao_id) REFERENCES reclamacoes(id) ON DELETE CASCADE
                 ) ENGINE=InnoDB'
            );
        }
        if (!self::temTabela('documentos')) {
            Database::executar(
                'CREATE TABLE documentos (
                   id INT AUTO_INCREMENT PRIMARY KEY,
                   cliente_id INT NOT NULL,
                   usuario_id INT NULL,
                   tipo ENUM("Foto","Laudo","Receita","Contrato","Nota fiscal","PDF","Outro") NOT NULL DEFAULT "Outro",
                   nome VARCHAR(160) NOT NULL,
                   arquivo VARCHAR(255) NOT NULL,
                   mime VARCHAR(100),
                   tamanho INT NOT NULL DEFAULT 0,
                   criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                   FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
                   FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
                 ) ENGINE=InnoDB'
            );
        }

        // Seed leve das categorias de reembolso (só se vazio)
        if ((int) Database::valor('SELECT COUNT(*) FROM categorias_reembolso') === 0) {
            Database::executar(
                "INSERT INTO categorias_reembolso (id, nome, valor_km, teto_refeicao) VALUES
                 (1,'Agrônomo',1.80,60.00),(2,'Extensionista',1.60,50.00),
                 (3,'Vendedor',1.50,45.00),(4,'Gestor',2.00,80.00)"
            );
            Database::executar('UPDATE usuarios SET categoria_reembolso_id = 4 WHERE id IN (2,3)');
            Database::executar('UPDATE usuarios SET categoria_reembolso_id = 1 WHERE id = 4');
            Database::executar('UPDATE usuarios SET categoria_reembolso_id = 3 WHERE id = 5');
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

    private static function temIndice(string $tabela, string $indice): bool
    {
        return (bool) Database::valor(
            'SELECT 1 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$tabela, $indice]
        );
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
