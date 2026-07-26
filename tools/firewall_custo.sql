-- ============================================================================
-- Firewall do custo do cooperado — invariante 5 / spec custo-lavoura §7
-- ============================================================================
-- Aplicar UMA VEZ no MySQL de produção, autenticado como usuário administrativo
-- (não o usuário do app). Objetivo: o usuário COMERCIAL da aplicação (o DB_USER
-- de sempre) NÃO pode ler nem escrever em `lavoura_custo` e `lavoura_cenario`
-- (que guarda o `resultado_json`). Só o usuário de custo (DB_USER_CUSTO, usado
-- por Database::conexaoCusto()) acessa essas tabelas.
--
-- A restrição é imposta NO BANCO, não só na aplicação (requisito da spec §7).
-- Critério de aceite: um SELECT nessas tabelas com a credencial comercial deve
-- retornar erro de permissão do MySQL.
--
-- Ajuste nomes de usuário, host e senha conforme o ambiente. No Railway o host
-- costuma ser '%'. Troque 'crm' pelo valor real de DB_USER e defina uma senha
-- forte para o usuário de custo (que vira DB_USER_CUSTO / DB_PASS_CUSTO no app).
--
-- Requer MySQL 8.0.16+ com partial_revokes para revogar tabelas específicas de
-- um grant no nível do banco. Verifique e, se necessário e possível, ligue:
--   SHOW VARIABLES LIKE 'partial_revokes';
--   SET PERSIST partial_revokes = ON;   -- precisa de privilégio administrativo
-- ----------------------------------------------------------------------------

-- 1) Usuário PRIVILEGIADO de custo (Database::conexaoCusto()).
CREATE USER IF NOT EXISTS 'crm_custo'@'%' IDENTIFIED BY 'TROCAR_POR_SENHA_FORTE';
GRANT ALL PRIVILEGES ON crm_agropecuario.* TO 'crm_custo'@'%';

-- 2) Usuário COMERCIAL do app: remove o acesso às tabelas sob firewall.
--    Ele mantém acesso a todo o resto do banco (partial revoke).
REVOKE SELECT, INSERT, UPDATE, DELETE, REFERENCES
  ON crm_agropecuario.lavoura_custo   FROM 'crm'@'%';
REVOKE SELECT, INSERT, UPDATE, DELETE, REFERENCES
  ON crm_agropecuario.lavoura_cenario FROM 'crm'@'%';
-- Ingestão de NF do produtor (spec nf-ingestao §10): o que o cooperado compra
-- FORA da Copérdia nunca chega à credencial comercial. (map_ncm_item fica fora
-- do firewall: é catálogo genérico, sem dado de produtor.)
REVOKE SELECT, INSERT, UPDATE, DELETE, REFERENCES
  ON crm_agropecuario.produtor_autorizacao_fiscal FROM 'crm'@'%';
REVOKE SELECT, INSERT, UPDATE, DELETE, REFERENCES
  ON crm_agropecuario.nfe_documento   FROM 'crm'@'%';
REVOKE SELECT, INSERT, UPDATE, DELETE, REFERENCES
  ON crm_agropecuario.nfe_item        FROM 'crm'@'%';
REVOKE SELECT, INSERT, UPDATE, DELETE, REFERENCES
  ON crm_agropecuario.nfe_captura_log FROM 'crm'@'%';
FLUSH PRIVILEGES;

-- 3) Verificação — o primeiro comando DEVE falhar; o segundo DEVE funcionar:
--    mysql -u crm       -p -e "SELECT COUNT(*) FROM crm_agropecuario.lavoura_custo;"   -- ERRO esperado
--    mysql -u crm_custo -p -e "SELECT COUNT(*) FROM crm_agropecuario.lavoura_custo;"   -- OK

-- Observação: como a app comercial cria as tabelas na migração (v34), rode este
-- script DEPOIS da primeira migração. Alterações futuras de schema nessas duas
-- tabelas devem ser executadas com o usuário crm_custo (o comercial perde o DDL).
