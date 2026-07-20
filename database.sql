-- ============================================================================
-- CRM Agropecuário Copérdia — Schema completo + seed de demonstração
-- Idempotente: pode ser reimportado do zero a qualquer momento.
--   mysql -u root -p < database.sql
-- ============================================================================

SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS crm_agropecuario CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE crm_agropecuario;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS sessoes_persistentes, configuracoes, auditoria,
  integracao_log, notificacoes, agenda_eventos,
  documentos, prestacao_contas, reclamacao_fotos, reembolso_refeicoes, refeicoes, quilometragem, veiculos, reclamacoes, categorias_reembolso,
  pacote_obrigatorios, pacote_categorias, pacotes_agricolas,
  entregas_futuras, promocoes, pedidos_itens, pedidos,
  propostas_itens, propostas, oportunidades,
  calendario_agronomico, culturas_referencia, planos_safra,
  metas_cap, realizado_cap, garantias, potencial_compra, titulos_financeiros,
  compras, produtos, familias_produto, safras, concorrencia_registros,
  visita_fotos, visitas, modelos_recomendacao, talhoes, propriedades,
  cliente_contatos, clientes, culturas, municipios, filiais, usuarios;
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- NÚCLEO
-- ============================================================================

CREATE TABLE usuarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(120) NOT NULL,
  email VARCHAR(160) NOT NULL UNIQUE,
  senha_hash VARCHAR(255) NOT NULL,
  perfil ENUM('Administrador','Gestor Comercial','Gestor Técnico','Consultor Técnico','Vendedor','Analista','Produtor') NOT NULL,
  telefone VARCHAR(30),
  categoria_reembolso_id INT NULL COMMENT 'categoria de reembolso de despesas (KM/refeições)',
  cliente_id INT NULL COMMENT 'produtor vinculado (perfil Produtor — portal)',
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  trocar_senha TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = deve definir nova senha no próximo acesso',
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Proteção do login: contagem de tentativas falhas e bloqueio temporário por e-mail
DROP TABLE IF EXISTS login_tentativas;
CREATE TABLE login_tentativas (
  chave VARCHAR(190) NOT NULL PRIMARY KEY,
  tentativas INT NOT NULL DEFAULT 0,
  bloqueado_ate DATETIME NULL,
  atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Web Push: assinaturas de notificação por aparelho (Android/iPhone com PWA instalado)
DROP TABLE IF EXISTS push_assinaturas;
CREATE TABLE push_assinaturas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  endpoint_hash CHAR(64) NOT NULL UNIQUE,
  endpoint TEXT NOT NULL,
  p256dh VARCHAR(255) NULL,
  auth VARCHAR(64) NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;


CREATE TABLE filiais (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(120) NOT NULL,
  municipio VARCHAR(120) NOT NULL,
  estado CHAR(2) NOT NULL
) ENGINE=InnoDB;

-- Municípios pré-cadastrados (o UF fica atrelado ao município)
CREATE TABLE municipios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(120) NOT NULL,
  estado CHAR(2) NOT NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uk_municipio (nome, estado)
) ENGINE=InnoDB;

CREATE TABLE culturas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(80) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE clientes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(160) NOT NULL,
  situacao ENUM('Associado','Não Associado') NOT NULL DEFAULT 'Associado',
  cpf_cnpj VARCHAR(20),
  telefone VARCHAR(30),
  telefone2 VARCHAR(30),
  email VARCHAR(160),
  endereco VARCHAR(255),
  municipio VARCHAR(120),
  linha VARCHAR(120) COMMENT 'linha/localidade rural (comunidade)',
  estado CHAR(2) DEFAULT 'SC',
  filial_id INT,
  latitude DECIMAL(10,7),
  longitude DECIMAL(10,7),
  responsavel_id INT COMMENT 'usuário (vendedor/técnico) dono da carteira',
  nivel_tecnologico ENUM('Alto','Médio','Baixo') NOT NULL DEFAULT 'Médio',
  volume_compra_anual DECIMAL(14,2) NOT NULL DEFAULT 0,
  potencial_venda DECIMAL(14,2) NOT NULL DEFAULT 0,
  limite_credito DECIMAL(14,2) NOT NULL DEFAULT 0,
  prospecto TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'pré-cadastro (cliente em prospecção)',
  segmento CHAR(1) NULL COMMENT 'segmento calculado (A/B/C/D/P) — cache do SegmentacaoService',
  segmento_manual CHAR(1) NULL COMMENT 'segmento fixado pelo gestor (prevalece sobre o calculado)',
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (filial_id) REFERENCES filiais(id),
  FOREIGN KEY (responsavel_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

CREATE TABLE cliente_contatos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT NOT NULL,
  nome VARCHAR(120) NOT NULL,
  cargo VARCHAR(80),
  telefone VARCHAR(30),
  FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE propriedades (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT NOT NULL,
  nome VARCHAR(160) NOT NULL,
  area_ha DECIMAL(10,2) NOT NULL DEFAULT 0,
  municipio VARCHAR(120),
  latitude DECIMAL(10,7),
  longitude DECIMAL(10,7),
  FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE talhoes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  propriedade_id INT NOT NULL,
  nome VARCHAR(120) NOT NULL,
  area_ha DECIMAL(10,2) NOT NULL DEFAULT 0,
  cultura_id INT,
  FOREIGN KEY (propriedade_id) REFERENCES propriedades(id) ON DELETE CASCADE,
  FOREIGN KEY (cultura_id) REFERENCES culturas(id)
) ENGINE=InnoDB;

-- ============================================================================
-- VISITAS TÉCNICAS
-- ============================================================================

CREATE TABLE modelos_recomendacao (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cultura_id INT,
  categoria VARCHAR(60) NOT NULL COMMENT 'Fungicida, Inseticida, Herbicida, Nutrição...',
  titulo VARCHAR(160) NOT NULL,
  texto_padrao TEXT NOT NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (cultura_id) REFERENCES culturas(id)
) ENGINE=InnoDB;

CREATE TABLE visitas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT NOT NULL,
  propriedade_id INT,
  talhao_id INT,
  cultura_id INT,
  usuario_id INT NOT NULL,
  data_visita DATE NOT NULL,
  hora TIME,
  objetivo VARCHAR(255),
  estagio_cultura VARCHAR(120),
  desenvolvimento VARCHAR(255),
  pragas VARCHAR(255),
  doencas VARCHAR(255),
  plantas_daninhas VARCHAR(255),
  deficiencia_nutricional VARCHAR(255),
  condicoes_climaticas VARCHAR(120),
  observacoes TEXT,
  recomendacao TEXT,
  latitude DECIMAL(10,7),
  longitude DECIMAL(10,7),
  sincronizada_offline TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = criada offline e sincronizada depois',
  finalizada TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = cadastro salvo incompleto (não finalizada)',
  completude TINYINT NOT NULL DEFAULT 100 COMMENT 'percentual de campos do cadastro preenchidos (0-100)',
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (cliente_id) REFERENCES clientes(id),
  FOREIGN KEY (propriedade_id) REFERENCES propriedades(id),
  FOREIGN KEY (talhao_id) REFERENCES talhoes(id),
  FOREIGN KEY (cultura_id) REFERENCES culturas(id),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

CREATE TABLE visita_fotos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  visita_id INT NOT NULL,
  arquivo VARCHAR(255) NOT NULL,
  legenda VARCHAR(255),
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (visita_id) REFERENCES visitas(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE concorrencia_registros (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT NOT NULL,
  visita_id INT,
  concorrente VARCHAR(160) NOT NULL,
  familia_id INT,
  condicoes VARCHAR(255) COMMENT 'condições/preços oferecidos pelo concorrente',
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
  FOREIGN KEY (visita_id) REFERENCES visitas(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================================
-- COMERCIAL: safras, produtos, compras, financeiro, potencial, garantias
-- ============================================================================

CREATE TABLE safras (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(20) NOT NULL,
  data_inicio DATE NOT NULL,
  data_fim DATE NOT NULL,
  atual TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE familias_produto (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(80) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE produtos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(160) NOT NULL,
  familia_id INT NOT NULL,
  unidade VARCHAR(20) NOT NULL DEFAULT 'un',
  preco_referencia DECIMAL(12,2) NOT NULL DEFAULT 0,
  estoque DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'saldo local até integração ERP (Fase 5)',
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (familia_id) REFERENCES familias_produto(id)
) ENGINE=InnoDB;

CREATE TABLE promocoes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  produto_id INT NOT NULL,
  descricao VARCHAR(200) NOT NULL,
  desconto_pct DECIMAL(5,2) NOT NULL,
  valido_ate DATE NOT NULL,
  FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE entregas_futuras (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT NOT NULL,
  produto_id INT NOT NULL,
  quantidade_contratada DECIMAL(12,2) NOT NULL,
  quantidade_retirada DECIMAL(12,2) NOT NULL DEFAULT 0,
  data_contrato DATE NOT NULL,
  previsao_entrega DATE,
  FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
  FOREIGN KEY (produto_id) REFERENCES produtos(id)
) ENGINE=InnoDB;

CREATE TABLE compras (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT NOT NULL,
  produto_id INT NOT NULL,
  safra_id INT NOT NULL,
  quantidade DECIMAL(12,2) NOT NULL,
  valor_total DECIMAL(14,2) NOT NULL,
  data_compra DATE NOT NULL,
  FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
  FOREIGN KEY (produto_id) REFERENCES produtos(id),
  FOREIGN KEY (safra_id) REFERENCES safras(id)
) ENGINE=InnoDB;

CREATE TABLE titulos_financeiros (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT NOT NULL,
  descricao VARCHAR(160),
  valor DECIMAL(14,2) NOT NULL,
  vencimento DATE NOT NULL,
  situacao ENUM('Aberto','Pago') NOT NULL DEFAULT 'Aberto',
  data_pagamento DATE,
  FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE potencial_compra (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT NOT NULL,
  familia_id INT NOT NULL,
  safra_id INT NOT NULL,
  valor_potencial DECIMAL(14,2) NOT NULL,
  UNIQUE KEY uk_pot (cliente_id, familia_id, safra_id),
  FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
  FOREIGN KEY (familia_id) REFERENCES familias_produto(id),
  FOREIGN KEY (safra_id) REFERENCES safras(id)
) ENGINE=InnoDB;

CREATE TABLE garantias (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT NOT NULL,
  tipo ENUM('CPR','Penhor de safra','Aval','Hipoteca','Outra') NOT NULL,
  valor DECIMAL(14,2) NOT NULL,
  vencimento DATE NOT NULL,
  documento VARCHAR(255) COMMENT 'arquivo anexo em uploads/',
  observacao VARCHAR(255),
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================================
-- CAP — Copérdia Alta Performance (fonte local até integração com CAPE)
-- ============================================================================

CREATE TABLE metas_cap (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  indicador VARCHAR(120) NOT NULL,
  unidade VARCHAR(20) NOT NULL DEFAULT 'R$',
  meta DECIMAL(14,2) NOT NULL,
  periodo_inicio DATE NOT NULL,
  periodo_fim DATE NOT NULL,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE realizado_cap (
  id INT AUTO_INCREMENT PRIMARY KEY,
  meta_id INT NOT NULL,
  valor DECIMAL(14,2) NOT NULL,
  data_ref DATE NOT NULL,
  FOREIGN KEY (meta_id) REFERENCES metas_cap(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================================
-- PLANEJAMENTO DE SAFRA E CALENDÁRIO AGRONÔMICO
-- ============================================================================

CREATE TABLE planos_safra (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT NOT NULL,
  propriedade_id INT,
  cultura_id INT NOT NULL,
  safra_id INT NOT NULL,
  area_ha DECIMAL(10,2) NOT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
  FOREIGN KEY (propriedade_id) REFERENCES propriedades(id) ON DELETE SET NULL,
  FOREIGN KEY (cultura_id) REFERENCES culturas(id),
  FOREIGN KEY (safra_id) REFERENCES safras(id)
) ENGINE=InnoDB;

CREATE TABLE culturas_referencia (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cultura_id INT NOT NULL,
  familia_id INT NOT NULL,
  custo_por_ha DECIMAL(12,2) NOT NULL COMMENT 'investimento médio por hectare na família',
  UNIQUE KEY uk_ref (cultura_id, familia_id),
  FOREIGN KEY (cultura_id) REFERENCES culturas(id),
  FOREIGN KEY (familia_id) REFERENCES familias_produto(id)
) ENGINE=InnoDB;

CREATE TABLE calendario_agronomico (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cultura_id INT NOT NULL,
  atividade VARCHAR(120) NOT NULL,
  familia_id INT COMMENT 'família de produto associada ao gatilho comercial',
  mes_inicio TINYINT NOT NULL COMMENT '1-12',
  mes_fim TINYINT NOT NULL COMMENT '1-12',
  descricao VARCHAR(255),
  FOREIGN KEY (cultura_id) REFERENCES culturas(id),
  FOREIGN KEY (familia_id) REFERENCES familias_produto(id)
) ENGINE=InnoDB;

-- ============================================================================
-- FUNIL DE OPORTUNIDADES
-- ============================================================================

CREATE TABLE oportunidades (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT NOT NULL,
  usuario_id INT COMMENT 'vendedor/técnico responsável',
  safra_id INT,
  familia_id INT,
  produto_id INT,
  origem ENUM('Gap de recompra','Potencial não atendido','Calendário agronômico','Manual') NOT NULL DEFAULT 'Manual',
  titulo VARCHAR(200) NOT NULL,
  valor_estimado DECIMAL(14,2) NOT NULL DEFAULT 0,
  estagio ENUM('Identificada','Proposta','Negociação','Ganha','Perdida') NOT NULL DEFAULT 'Identificada',
  motivo_perda ENUM('Preço','Prazo','Concorrente','Desistência','Clima','Outro'),
  concorrente_perda VARCHAR(160),
  data_prevista DATE,
  pendente_aprovacao TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'cliente inadimplente/acima do limite: exige aprovação do Gestor Comercial',
  aprovada_por INT,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_oport_auto (cliente_id, origem, familia_id, safra_id, produto_id),
  FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  FOREIGN KEY (safra_id) REFERENCES safras(id),
  FOREIGN KEY (familia_id) REFERENCES familias_produto(id),
  FOREIGN KEY (produto_id) REFERENCES produtos(id),
  FOREIGN KEY (aprovada_por) REFERENCES usuarios(id)
) ENGINE=InnoDB;

CREATE TABLE propostas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  oportunidade_id INT NOT NULL,
  validade DATE NOT NULL,
  condicao_pagamento VARCHAR(120),
  valor_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  situacao ENUM('Em aberto','Aceita','Recusada','Vencida') NOT NULL DEFAULT 'Em aberto',
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (oportunidade_id) REFERENCES oportunidades(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE propostas_itens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  proposta_id INT NOT NULL,
  produto_id INT NOT NULL,
  quantidade DECIMAL(12,2) NOT NULL,
  valor_unitario DECIMAL(12,2) NOT NULL,
  FOREIGN KEY (proposta_id) REFERENCES propostas(id) ON DELETE CASCADE,
  FOREIGN KEY (produto_id) REFERENCES produtos(id)
) ENGINE=InnoDB;

-- ============================================================================
-- TABELAS ANTECIPADAS (Fases 2–4, sem telas nesta fase)
-- ============================================================================

CREATE TABLE pacotes_agricolas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(160) NOT NULL,
  cultura_id INT,
  safra_id INT,
  vigencia_inicio DATE,
  vigencia_fim DATE,
  regiao VARCHAR(120),
  campanha VARCHAR(120),
  bonificacao_sacas_ha DECIMAL(8,2) NOT NULL DEFAULT 0 COMMENT 'bonificação em sacas de grãos por hectare para pacote completo',
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (cultura_id) REFERENCES culturas(id),
  FOREIGN KEY (safra_id) REFERENCES safras(id)
) ENGINE=InnoDB;

CREATE TABLE pacote_categorias (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pacote_id INT NOT NULL,
  familia_id INT NOT NULL,
  desconto_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  bonificacao_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  obrigatoria TINYINT(1) NOT NULL DEFAULT 0,
  qtd_minima DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'quantidade mínima da categoria no pedido',
  UNIQUE KEY uk_pacote_familia (pacote_id, familia_id),
  FOREIGN KEY (pacote_id) REFERENCES pacotes_agricolas(id) ON DELETE CASCADE,
  FOREIGN KEY (familia_id) REFERENCES familias_produto(id)
) ENGINE=InnoDB;

CREATE TABLE pacote_obrigatorios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pacote_id INT NOT NULL,
  produto_id INT NOT NULL,
  dose_ha DECIMAL(10,3) NOT NULL DEFAULT 0 COMMENT 'dose por hectare',
  num_aplicacoes TINYINT NOT NULL DEFAULT 1,
  qtd_minima DECIMAL(12,2) NOT NULL DEFAULT 0,
  qtd_maxima DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT '0 = sem teto',
  UNIQUE KEY uk_pacote_produto (pacote_id, produto_id),
  FOREIGN KEY (pacote_id) REFERENCES pacotes_agricolas(id) ON DELETE CASCADE,
  FOREIGN KEY (produto_id) REFERENCES produtos(id)
) ENGINE=InnoDB;

CREATE TABLE pedidos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT NOT NULL,
  usuario_id INT NOT NULL,
  safra_id INT,
  tipo ENUM('Normal','Pacote Agrícola') NOT NULL DEFAULT 'Normal',
  pacote_id INT,
  area_ha DECIMAL(10,2) COMMENT 'área atendida (pedido de pacote)',
  status ENUM('Rascunho','Pendente de aprovação','Aprovado','Faturado','Cancelado') NOT NULL DEFAULT 'Rascunho',
  motivo_pendencia VARCHAR(160) COMMENT 'por que caiu em aprovação (crédito)',
  aprovado_por INT,
  condicao_pagamento VARCHAR(120),
  observacao VARCHAR(255),
  valor_bruto DECIMAL(14,2) NOT NULL DEFAULT 0,
  desconto_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  valor_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  bonificacao_sacas DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'bonificação em grãos prevista (pacote)',
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (cliente_id) REFERENCES clientes(id),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  FOREIGN KEY (safra_id) REFERENCES safras(id),
  FOREIGN KEY (pacote_id) REFERENCES pacotes_agricolas(id),
  FOREIGN KEY (aprovado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB;

CREATE TABLE pedidos_itens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pedido_id INT NOT NULL,
  produto_id INT NOT NULL,
  quantidade DECIMAL(12,2) NOT NULL,
  valor_unitario DECIMAL(12,2) NOT NULL,
  desconto_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE,
  FOREIGN KEY (produto_id) REFERENCES produtos(id)
) ENGINE=InnoDB;

-- Categorias de reembolso (o Administrador define o valor pago por km e o teto
-- de refeição para cada categoria; cada usuário recebe uma categoria).
CREATE TABLE categorias_reembolso (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(80) NOT NULL,
  valor_km DECIMAL(8,2) NOT NULL DEFAULT 0 COMMENT 'R$ por km rodado',
  teto_refeicao DECIMAL(8,2) NOT NULL DEFAULT 0 COMMENT 'teto por refeição (0 = sem teto)',
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

ALTER TABLE usuarios ADD FOREIGN KEY (categoria_reembolso_id) REFERENCES categorias_reembolso(id);

-- Veículos cadastrados por usuário (usados no lançamento de quilometragem)
CREATE TABLE veiculos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  descricao VARCHAR(120) NOT NULL COMMENT 'modelo/apelido do veículo',
  placa VARCHAR(20),
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Prestação de contas mensal (consolida KM + refeições do período)
CREATE TABLE prestacao_contas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  ano SMALLINT NOT NULL,
  mes TINYINT NOT NULL,
  total_km DECIMAL(10,1) NOT NULL DEFAULT 0,
  total_km_valor DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_refeicoes_gasto DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'total gasto em refeições (notas)',
  total_refeicoes DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'total de refeições reembolsado pela Copérdia',
  total_geral DECIMAL(12,2) NOT NULL DEFAULT 0,
  status ENUM('Aberta','Enviada','Aprovada','Rejeitada') NOT NULL DEFAULT 'Aberta',
  observacao VARCHAR(255),
  enviado_em DATETIME NULL,
  avaliado_por INT NULL,
  avaliado_em DATETIME NULL,
  parecer VARCHAR(255),
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_prestacao (usuario_id, ano, mes),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  FOREIGN KEY (avaliado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB;

CREATE TABLE reclamacoes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT NOT NULL,
  usuario_id INT NULL COMMENT 'quem registrou',
  produto_id INT,
  tipo ENUM('Sementes','Fertilizantes','Defensivos','Biológicos','Outros') NOT NULL,
  lote VARCHAR(80),
  nota_fiscal VARCHAR(80),
  cultura_id INT,
  problema VARCHAR(200),
  descricao TEXT,
  status ENUM('Registrada','Em análise','Procedente','Improcedente','Jurídico','Indenização','Encerrada') NOT NULL DEFAULT 'Registrada',
  parecer TEXT,
  valor_indenizacao DECIMAL(12,2) NULL,
  atualizado_em DATETIME NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (cliente_id) REFERENCES clientes(id),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  FOREIGN KEY (produto_id) REFERENCES produtos(id),
  FOREIGN KEY (cultura_id) REFERENCES culturas(id)
) ENGINE=InnoDB;

CREATE TABLE reclamacao_fotos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reclamacao_id INT NOT NULL,
  arquivo VARCHAR(255) NOT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (reclamacao_id) REFERENCES reclamacoes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE quilometragem (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  prestacao_id INT NULL,
  veiculo_id INT NULL,
  visita_id INT NULL COMMENT 'amarração automática com a visita realizada',
  veiculo VARCHAR(120),
  data DATE NOT NULL,
  km_inicial DECIMAL(10,1) NOT NULL,
  km_final DECIMAL(10,1) NOT NULL,
  valor DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'km rodados x valor_km da categoria (na data do lançamento)',
  tipo_destino ENUM('Produtor','Filial','Lugar') NOT NULL DEFAULT 'Lugar',
  cliente_id INT,
  filial_id INT,
  prospecto VARCHAR(160) COMMENT 'nome do cliente prospecto quando não cadastrado',
  destino VARCHAR(160),
  motivo VARCHAR(200),
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  FOREIGN KEY (prestacao_id) REFERENCES prestacao_contas(id) ON DELETE SET NULL,
  FOREIGN KEY (veiculo_id) REFERENCES veiculos(id) ON DELETE SET NULL,
  FOREIGN KEY (visita_id) REFERENCES visitas(id) ON DELETE SET NULL,
  FOREIGN KEY (cliente_id) REFERENCES clientes(id),
  FOREIGN KEY (filial_id) REFERENCES filiais(id)
) ENGINE=InnoDB;

CREATE TABLE refeicoes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  prestacao_id INT NULL,
  data DATE NOT NULL,
  hora TIME NULL,
  tipo ENUM('Café','Almoço','Lanche','Janta') NOT NULL DEFAULT 'Almoço',
  cliente_id INT,
  estabelecimento VARCHAR(160),
  valor DECIMAL(10,2) NOT NULL COMMENT 'valor gasto (nota)',
  valor_reembolso DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'valor que a Copérdia paga (limitado ao teto da categoria/tipo)',
  comprovante VARCHAR(255) NULL COMMENT 'foto/arquivo do comprovante',
  justificativa VARCHAR(255),
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  FOREIGN KEY (prestacao_id) REFERENCES prestacao_contas(id) ON DELETE SET NULL,
  FOREIGN KEY (cliente_id) REFERENCES clientes(id)
) ENGINE=InnoDB;

-- Valores de reembolso de refeição por categoria e tipo (definidos pelo Administrador)
CREATE TABLE reembolso_refeicoes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  categoria_reembolso_id INT NOT NULL,
  tipo ENUM('Café','Almoço','Lanche','Janta') NOT NULL,
  valor DECIMAL(8,2) NOT NULL DEFAULT 0,
  UNIQUE KEY uk_reembolso_ref (categoria_reembolso_id, tipo),
  FOREIGN KEY (categoria_reembolso_id) REFERENCES categorias_reembolso(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Gestão documental: anexos por produtor (fotos, PDFs, laudos, receitas, contratos)
CREATE TABLE documentos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT NOT NULL,
  usuario_id INT NULL,
  tipo ENUM('Foto','Laudo','Receita','Contrato','Nota fiscal','PDF','Outro') NOT NULL DEFAULT 'Outro',
  nome VARCHAR(160) NOT NULL,
  arquivo VARCHAR(255) NOT NULL,
  mime VARCHAR(100),
  tamanho INT NOT NULL DEFAULT 0,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

-- ============================================================================
-- FASE 4 — Relacionamento e gestão (agenda e notificações)
-- ============================================================================

CREATE TABLE agenda_eventos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  cliente_id INT NULL,
  tipo ENUM('Visita','Reunião','Tarefa','Entrega','Cobrança','Outro') NOT NULL DEFAULT 'Visita',
  titulo VARCHAR(160) NOT NULL,
  data DATE NOT NULL,
  hora TIME NULL,
  ordem SMALLINT NOT NULL DEFAULT 0 COMMENT 'ordem no roteiro do dia',
  status ENUM('Pendente','Concluído','Cancelado') NOT NULL DEFAULT 'Pendente',
  descricao VARCHAR(255),
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE notificacoes (
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
) ENGINE=InnoDB;

CREATE TABLE sessoes_persistentes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expira_em DATETIME NOT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================================
-- FASE 5 — Integração (ERP/CAPE) e sincronização
-- ============================================================================

CREATE TABLE integracao_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  fonte VARCHAR(40) NOT NULL COMMENT 'ERP, CAPE, Local…',
  entidade VARCHAR(60) NOT NULL,
  direcao ENUM('Importação','Exportação') NOT NULL DEFAULT 'Importação',
  status ENUM('Sucesso','Parcial','Erro') NOT NULL DEFAULT 'Sucesso',
  registros INT NOT NULL DEFAULT 0,
  mensagem VARCHAR(255),
  usuario_id INT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE configuracoes (
  chave VARCHAR(60) PRIMARY KEY,
  valor MEDIUMTEXT NOT NULL,
  atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE auditoria (
  id INT AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT,
  perfil VARCHAR(30) NULL,
  acao VARCHAR(40) NOT NULL,
  tabela VARCHAR(60) NOT NULL,
  registro_id INT,
  dados TEXT,
  ip VARCHAR(45) NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_aud_quando (criado_em),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

-- ============================================================================
-- SEED DE DEMONSTRAÇÃO
-- Senha de todos os usuários: coperdia123
-- ============================================================================

INSERT INTO usuarios (id, nome, email, senha_hash, perfil, telefone) VALUES
(1,'Administrador do Sistema','admin@coperdia.com.br','$2y$12$sjjXBbOZoRBJlVAnVSLqpOH.Yhel..WY.TMD58K6eXy9p/nDQX4aq','Administrador','(49) 99999-0001'),
(2,'Gustavo Gestor','gestor.comercial@coperdia.com.br','$2y$12$sjjXBbOZoRBJlVAnVSLqpOH.Yhel..WY.TMD58K6eXy9p/nDQX4aq','Gestor Comercial','(49) 99999-0002'),
(3,'Tânia Gestora Técnica','gestor.tecnico@coperdia.com.br','$2y$12$sjjXBbOZoRBJlVAnVSLqpOH.Yhel..WY.TMD58K6eXy9p/nDQX4aq','Gestor Técnico','(49) 99999-0003'),
(4,'Carlos Consultor','consultor@coperdia.com.br','$2y$12$sjjXBbOZoRBJlVAnVSLqpOH.Yhel..WY.TMD58K6eXy9p/nDQX4aq','Consultor Técnico','(49) 99999-0004'),
(5,'Vera Vendedora','vendedor@coperdia.com.br','$2y$12$sjjXBbOZoRBJlVAnVSLqpOH.Yhel..WY.TMD58K6eXy9p/nDQX4aq','Vendedor','(49) 99999-0005'),
(6,'André Analista','analista@coperdia.com.br','$2y$12$sjjXBbOZoRBJlVAnVSLqpOH.Yhel..WY.TMD58K6eXy9p/nDQX4aq','Analista','(49) 99999-0006'),
(7,'Pedro Produtor','produtor@coperdia.com.br','$2y$12$sjjXBbOZoRBJlVAnVSLqpOH.Yhel..WY.TMD58K6eXy9p/nDQX4aq','Produtor','(49) 99999-0007');

INSERT INTO filiais (id, nome, municipio, estado) VALUES
(1,'Matriz Concórdia','Concórdia','SC'),
(2,'Filial Seara','Seara','SC'),
(3,'Filial Chapecó','Chapecó','SC');

INSERT INTO culturas (id, nome) VALUES
(1,'Soja'),(2,'Milho'),(3,'Trigo'),(4,'Feijão'),(5,'Pastagem');

-- Municípios da região de atuação (Alto Uruguai Catarinense e entorno)
INSERT INTO municipios (nome, estado) VALUES
('Concórdia','SC'),('Seara','SC'),('Chapecó','SC'),('Ipumirim','SC'),('Itá','SC'),
('Arabutã','SC'),('Lindóia do Sul','SC'),('Irani','SC'),('Presidente Castello Branco','SC'),
('Peritiba','SC'),('Piratuba','SC'),('Alto Bela Vista','SC'),('Xavantina','SC'),('Arvoredo','SC'),
('Paial','SC'),('Ipira','SC'),('Jaborá','SC'),('Xanxerê','SC'),('Xaxim','SC'),('Coronel Freitas','SC'),
('Águas de Chapecó','SC'),('Nova Erechim','SC'),('Cordilheira Alta','SC'),('Guatambú','SC'),
('Erval Velho','SC'),('Joaçaba','SC'),('Capinzal','SC'),('Ouro','SC'),('Marcelino Ramos','RS'),('Erechim','RS');

INSERT INTO familias_produto (id, nome) VALUES
(1,'Sementes'),(2,'Fertilizantes'),(3,'Herbicidas'),(4,'Fungicidas'),
(5,'Inseticidas'),(6,'Adubos Foliares / Nutrição'),(7,'Biológicos'),
(8,'Adjuvantes'),(9,'Rações');

INSERT INTO produtos (id, nome, familia_id, unidade, preco_referencia) VALUES
(1,'Semente Soja 58I60 IPRO (sc 40kg)',1,'sc',420.00),
(2,'Semente Milho K9105 VIP3 (sc 60mil)',1,'sc',1450.00),
(3,'Fertilizante 02-20-20 (t)',2,'t',3200.00),
(4,'Ureia 45% (t)',2,'t',3050.00),
(5,'Glifosato 720 WG (kg)',3,'kg',48.00),
(6,'Herbicida 2,4-D (L)',3,'L',36.00),
(7,'Fungicida Triazol+Estrobilurina (L)',4,'L',180.00),
(8,'Fungicida Multissítio Mancozebe (kg)',4,'kg',52.00),
(9,'Inseticida Diamida (L)',5,'L',390.00),
(10,'Inseticida Piretróide (L)',5,'L',75.00),
(11,'Adubo Foliar Mn+Zn (L)',6,'L',42.00),
(12,'Cobalto e Molibdênio (L)',6,'L',58.00),
(13,'Inoculante Bradyrhizobium (dose)',7,'dose',18.00),
(14,'Trichoderma (L)',7,'L',95.00),
(15,'Óleo Mineral Adjuvante (L)',8,'L',28.00),
(16,'Ração Bovinos Leite 22% (sc 40kg)',9,'sc',92.00),
(17,'Ração Suínos Crescimento (sc 40kg)',9,'sc',88.00);

-- Estoque local de demonstração (Fase 5: virá do ERP)
UPDATE produtos SET estoque = CASE id
  WHEN 1 THEN 850 WHEN 2 THEN 320 WHEN 3 THEN 180 WHEN 4 THEN 240
  WHEN 5 THEN 4200 WHEN 6 THEN 1500 WHEN 7 THEN 950 WHEN 8 THEN 1200
  WHEN 9 THEN 400 WHEN 10 THEN 800 WHEN 11 THEN 1100 WHEN 12 THEN 700
  WHEN 13 THEN 2500 WHEN 14 THEN 300 WHEN 15 THEN 900
  WHEN 16 THEN 1800 WHEN 17 THEN 1600 ELSE 0 END;

-- Promoções vigentes
INSERT INTO promocoes (produto_id, descricao, desconto_pct, valido_ate) VALUES
(3,'Campanha de fertilizantes — antecipação safra 26/27',6.00,'2026-08-31'),
(7,'Programa fungicida antecipado',8.00,'2026-08-15'),
(16,'Ração leite — fidelidade inverno',4.00,'2026-08-31');

-- Safras: anterior (2024/25) e atual (2025/26, quase encerrando em jul/2026)
INSERT INTO safras (id, nome, data_inicio, data_fim, atual) VALUES
(1,'2024/25','2024-09-01','2025-08-31',0),
(2,'2025/26','2025-09-01','2026-08-31',1);

-- ---------------------------------------------------------------------------
-- CLIENTES — casos de demonstração:
-- 1 Alberto  : alto nível, alto volume, em dia, comprou bem nas duas safras
-- 2 Berenice : GAP de recompra (comprou fungicida/inseticida em 24/25, nada em 25/26)
-- 3 Celso    : CHURN (queda >30% na safra atual) + concorrência
-- 4 Dirceu   : INADIMPLENTE grave (títulos vencidos) — trava alçada
-- 5 Elisa    : alto potencial pouco atendido (potencial x realizado baixo)
-- 6 Fábio    : sem visita há muito tempo (prioridade máxima por tempo)
-- 7 Gilda    : cliente da carteira do consultor Carlos (perfil técnico)
-- 8 Hélio    : produtor pequeno, tudo em dia (baixa prioridade)
-- ---------------------------------------------------------------------------

INSERT INTO clientes (id, nome, situacao, cpf_cnpj, telefone, email, endereco, municipio, estado, filial_id, latitude, longitude, responsavel_id, nivel_tecnologico, volume_compra_anual, potencial_venda, limite_credito) VALUES
(1,'Alberto Antunes','Associado','052.481.930-01','(49) 98811-1001','alberto@fazsaojose.agr.br','Linha São José, s/n','Concórdia','SC',1,-27.2335000,-52.0278000,5,'Alto',850000.00,1100000.00,400000.00),
(2,'Berenice Bortolini','Associado','114.732.550-02','(49) 98811-1002','bere@terrasbb.agr.br','Linha Santa Cruz, km 4','Seara','SC',2,-27.1564000,-52.3112000,5,'Alto',420000.00,600000.00,250000.00),
(3,'Celso Casagrande','Associado','229.418.770-03','(49) 98811-1003','celso.casagrande@agro.br','Estrada Geral Alto Suruvi','Concórdia','SC',1,-27.2811000,-52.0655000,5,'Médio',380000.00,520000.00,200000.00),
(4,'Dirceu Dallabrida','Associado','338.204.190-04','(49) 98811-1004','dirceu.d@campo.agr.br','Linha Caravaggio','Chapecó','SC',3,-27.0965000,-52.6187000,5,'Médio',290000.00,410000.00,150000.00),
(5,'Elisa Ettori','Não Associado','447.916.320-05','(49) 98811-1005','elisa@granjaettori.agr.br','Rodovia SC-283, km 12','Seara','SC',2,-27.1387000,-52.2954000,5,'Alto',180000.00,750000.00,180000.00),
(6,'Fábio Fontana','Associado','556.087.410-06','(49) 98811-1006','fabio.fontana@sitio.agr.br','Linha Barra Fria','Concórdia','SC',1,-27.3102000,-52.1120000,5,'Médio',310000.00,450000.00,170000.00),
(7,'Gilda Guarnieri','Associado','665.298.530-07','(49) 98811-1007','gilda@recantogg.agr.br','Linha Pinhalzinho','Chapecó','SC',3,-27.1210000,-52.5878000,4,'Baixo',95000.00,160000.00,60000.00),
(8,'Hélio Hoffmann','Associado','774.359.620-08','(49) 98811-1008','helio.h@familiahoffmann.agr.br','Linha Tiradentes','Seara','SC',2,-27.1702000,-52.3345000,4,'Baixo',60000.00,80000.00,40000.00);

INSERT INTO cliente_contatos (cliente_id, nome, cargo, telefone) VALUES
(1,'Amanda Antunes','Filha / sucessora','(49) 98811-2001'),
(1,'José Antunes','Gerente da fazenda','(49) 98811-2002'),
(3,'Marta Casagrande','Esposa / financeiro','(49) 98811-2003');

INSERT INTO propriedades (id, cliente_id, nome, area_ha, municipio, latitude, longitude) VALUES
(1,1,'Fazenda São José',420.00,'Concórdia',-27.2335000,-52.0278000),
(2,1,'Sítio Boa Vista',85.00,'Concórdia',-27.2410000,-52.0301000),
(3,2,'Terras Bortolini',260.00,'Seara',-27.1564000,-52.3112000),
(4,3,'Granja Casagrande',190.00,'Concórdia',-27.2811000,-52.0655000),
(5,4,'Sítio Dallabrida',150.00,'Chapecó',-27.0965000,-52.6187000),
(6,5,'Granja Ettori',310.00,'Seara',-27.1387000,-52.2954000),
(7,6,'Sítio Fontana',175.00,'Concórdia',-27.3102000,-52.1120000),
(8,7,'Recanto Guarnieri',60.00,'Chapecó',-27.1210000,-52.5878000),
(9,8,'Chácara Hoffmann',35.00,'Seara',-27.1702000,-52.3345000);

INSERT INTO talhoes (id, propriedade_id, nome, area_ha, cultura_id) VALUES
(1,1,'Talhão 1 - Sede',120.00,1),
(2,1,'Talhão 2 - Encosta',95.00,1),
(3,1,'Talhão 3 - Várzea',110.00,2),
(4,2,'Talhão único',85.00,3),
(5,3,'Talhão A',140.00,1),
(6,3,'Talhão B',120.00,2),
(7,4,'Talhão Norte',100.00,1),
(8,4,'Talhão Sul',90.00,2),
(9,5,'Talhão 1',80.00,1),
(10,5,'Talhão 2',70.00,4),
(11,6,'Talhão Leste',160.00,1),
(12,6,'Talhão Oeste',150.00,2),
(13,7,'Talhão 1',95.00,1),
(14,7,'Talhão 2',80.00,3),
(15,8,'Potreiro',60.00,5),
(16,9,'Lavoura',35.00,2);

-- ---------------------------------------------------------------------------
-- COMPRAS — safra 2024/25 (anterior) e 2025/26 (atual)
-- ---------------------------------------------------------------------------

-- Alberto (1): forte nas duas safras, sem gap
INSERT INTO compras (cliente_id, produto_id, safra_id, quantidade, valor_total, data_compra) VALUES
(1,1,1,300,126000.00,'2024-09-20'),(1,3,1,60,192000.00,'2024-09-25'),
(1,7,1,400,72000.00,'2025-01-10'),(1,9,1,120,46800.00,'2025-01-15'),
(1,5,1,800,38400.00,'2024-10-05'),(1,13,1,600,10800.00,'2024-09-22'),
(1,1,2,320,134400.00,'2025-09-18'),(1,3,2,65,208000.00,'2025-09-22'),
(1,7,2,420,75600.00,'2026-01-08'),(1,9,2,130,50700.00,'2026-01-12'),
(1,5,2,850,40800.00,'2025-10-03'),(1,13,2,650,11700.00,'2025-09-20'),
(1,11,2,500,21000.00,'2025-11-10');

-- Berenice (2): GAP — comprou fungicida (7) e inseticida (9) em 24/25, nada em 25/26
INSERT INTO compras (cliente_id, produto_id, safra_id, quantidade, valor_total, data_compra) VALUES
(2,1,1,180,75600.00,'2024-09-28'),(2,3,1,35,112000.00,'2024-10-02'),
(2,7,1,250,45000.00,'2025-01-20'),(2,9,1,80,31200.00,'2025-01-25'),
(2,1,2,190,79800.00,'2025-09-25'),(2,3,2,38,121600.00,'2025-09-30'),
(2,5,2,400,19200.00,'2025-10-10');

-- Celso (3): CHURN — 24/25 comprou ~R$ 250 mil; 25/26 caiu para ~R$ 95 mil (queda ~62%)
INSERT INTO compras (cliente_id, produto_id, safra_id, quantidade, valor_total, data_compra) VALUES
(3,1,1,150,63000.00,'2024-09-30'),(3,3,1,30,96000.00,'2024-10-05'),
(3,7,1,280,50400.00,'2025-01-18'),(3,9,1,100,39000.00,'2025-01-22'),
(3,1,2,80,33600.00,'2025-10-02'),(3,3,2,12,38400.00,'2025-10-08'),
(3,5,2,480,23040.00,'2025-10-15');

-- Dirceu (4): comprou normal, mas está inadimplente
INSERT INTO compras (cliente_id, produto_id, safra_id, quantidade, valor_total, data_compra) VALUES
(4,1,1,120,50400.00,'2024-10-01'),(4,3,1,25,80000.00,'2024-10-06'),
(4,7,1,180,32400.00,'2025-01-25'),
(4,1,2,110,46200.00,'2025-10-05'),(4,3,2,22,70400.00,'2025-10-10');

-- Elisa (5): potencial alto (750 mil), realizado baixo
INSERT INTO compras (cliente_id, produto_id, safra_id, quantidade, valor_total, data_compra) VALUES
(5,1,1,90,37800.00,'2024-10-08'),(5,5,1,300,14400.00,'2024-10-15'),
(5,1,2,95,39900.00,'2025-10-08'),(5,5,2,320,15360.00,'2025-10-18'),
(5,16,2,400,36800.00,'2026-02-10');

-- Fábio (6): compras razoáveis, mas sem visita há muito tempo
INSERT INTO compras (cliente_id, produto_id, safra_id, quantidade, valor_total, data_compra) VALUES
(6,1,1,130,54600.00,'2024-10-03'),(6,3,1,28,89600.00,'2024-10-09'),
(6,7,1,200,36000.00,'2025-01-28'),
(6,1,2,135,56700.00,'2025-10-06'),(6,3,2,30,96000.00,'2025-10-12'),
(6,7,2,210,37800.00,'2026-01-20');

-- Gilda (7): rações e pastagem (carteira do consultor Carlos)
INSERT INTO compras (cliente_id, produto_id, safra_id, quantidade, valor_total, data_compra) VALUES
(7,16,1,600,55200.00,'2025-03-10'),(7,4,1,8,24400.00,'2024-11-20'),
(7,16,2,650,59800.00,'2026-03-08'),(7,4,2,9,27450.00,'2025-11-15');

-- Hélio (8): pequeno, regular
INSERT INTO compras (cliente_id, produto_id, safra_id, quantidade, valor_total, data_compra) VALUES
(8,2,1,20,29000.00,'2024-09-15'),(8,4,1,5,15250.00,'2024-11-10'),
(8,2,2,22,31900.00,'2025-09-12'),(8,4,2,5,15250.00,'2025-11-08');

-- ---------------------------------------------------------------------------
-- FINANCEIRO — títulos (Dirceu inadimplente grave; Celso atraso leve)
-- ---------------------------------------------------------------------------

INSERT INTO titulos_financeiros (cliente_id, descricao, valor, vencimento, situacao, data_pagamento) VALUES
(1,'Duplicata safra 25/26 parc. 1/3',69000.00,'2026-03-15','Pago','2026-03-12'),
(1,'Duplicata safra 25/26 parc. 2/3',69000.00,'2026-06-15','Pago','2026-06-14'),
(1,'Duplicata safra 25/26 parc. 3/3',69000.00,'2026-09-15','Aberto',NULL),
(2,'Duplicata insumos 25/26',60300.00,'2026-08-20','Aberto',NULL),
(3,'Duplicata insumos 25/26 parc. 1/2',23800.00,'2026-06-30','Aberto',NULL),
(3,'Duplicata insumos 25/26 parc. 2/2',23800.00,'2026-08-30','Aberto',NULL),
(4,'Duplicata insumos 24/25 parc. 2/2',40700.00,'2025-12-15','Aberto',NULL),
(4,'Duplicata insumos 25/26 parc. 1/2',29150.00,'2026-04-15','Aberto',NULL),
(4,'Duplicata insumos 25/26 parc. 2/2',29150.00,'2026-07-01','Aberto',NULL),
(5,'Duplicata ração fev/26',18400.00,'2026-05-10','Pago','2026-05-09'),
(6,'Duplicata insumos 25/26',47500.00,'2026-08-10','Aberto',NULL),
(7,'Duplicata ração mar/26',29900.00,'2026-06-08','Pago','2026-06-05'),
(8,'Duplicata insumos 25/26',15950.00,'2026-07-30','Aberto',NULL);

-- ---------------------------------------------------------------------------
-- POTENCIAL DE COMPRA por família — safra atual (2)
-- ---------------------------------------------------------------------------

INSERT INTO potencial_compra (cliente_id, familia_id, safra_id, valor_potencial) VALUES
(1,1,2,150000.00),(1,2,2,230000.00),(1,3,2,50000.00),(1,4,2,90000.00),(1,5,2,60000.00),(1,6,2,30000.00),(1,7,2,15000.00),
(2,1,2,90000.00),(2,2,2,140000.00),(2,3,2,25000.00),(2,4,2,55000.00),(2,5,2,38000.00),(2,6,2,18000.00),
(3,1,2,70000.00),(3,2,2,110000.00),(3,3,2,28000.00),(3,4,2,58000.00),(3,5,2,42000.00),
(4,1,2,55000.00),(4,2,2,85000.00),(4,4,2,38000.00),
(5,1,2,120000.00),(5,2,2,180000.00),(5,3,2,30000.00),(5,4,2,70000.00),(5,5,2,50000.00),(5,9,2,120000.00),
(6,1,2,62000.00),(6,2,2,100000.00),(6,4,2,42000.00),
(7,2,2,32000.00),(7,9,2,75000.00),
(8,1,2,35000.00),(8,2,2,20000.00),(8,9,2,15000.00);

-- ---------------------------------------------------------------------------
-- GARANTIAS
-- ---------------------------------------------------------------------------

INSERT INTO garantias (cliente_id, tipo, valor, vencimento, observacao) VALUES
(1,'CPR',300000.00,'2026-09-30','CPR de soja registrada'),
(2,'Penhor de safra',150000.00,'2026-08-31','Penhor da safra 25/26'),
(4,'Aval',80000.00,'2026-08-15','Aval do irmão — VENCENDO'),
(6,'CPR',120000.00,'2027-02-28','CPR de milho safrinha');

-- ---------------------------------------------------------------------------
-- VISITAS — históricos variados (Fábio 6 sem visita desde 2025; Alberto recente)
-- ---------------------------------------------------------------------------

INSERT INTO visitas (cliente_id, propriedade_id, talhao_id, cultura_id, usuario_id, data_visita, hora, objetivo, estagio_cultura, desenvolvimento, pragas, doencas, plantas_daninhas, deficiencia_nutricional, condicoes_climaticas, observacoes, recomendacao, latitude, longitude, criado_em) VALUES
(1,1,1,1,4,'2026-06-28','09:15','Acompanhamento pós-colheita e planejamento 26/27','Pós-colheita','Colheita concluída, produtividade 72 sc/ha','—','—','Buva em manchas','—','Ensolarado, 18°C','Produtor satisfeito com resultado. Planejar dessecação antecipada.','Dessecação de pré-plantio com Glifosato 720 WG 2,5 kg/ha + 2,4-D 1,0 L/ha nas manchas de buva. Programar compra de sementes para setembro.',-27.2335000,-52.0278000,'2026-06-28 09:15:00'),
(1,1,3,2,4,'2026-05-14','14:00','Avaliação de milho silagem','Colheita','Silagem fechada com boa qualidade','—','—','—','—','Nublado, 16°C','Discutida adubação de correção pós-silagem.','Aplicar 300 kg/ha de 02-20-20 na reforma. Análise de solo agendada.',-27.2335000,-52.0278000,'2026-05-14 14:00:00'),
(2,3,5,1,4,'2026-06-10','10:30','Planejamento fitossanitário 26/27','Pós-colheita','Área dessecada','—','—','Azevém resistente','—','Chuvisco, 14°C','Berenice ainda não fechou fungicidas e inseticidas desta safra — atenção ao gap.','Programa fungicida: Triazol+Estrobilurina 0,75 L/ha (3 aplicações) + multissítio. Apresentar proposta até agosto.',-27.1564000,-52.3112000,'2026-06-10 10:30:00'),
(3,4,7,1,4,'2026-04-22','15:45','Visita de retenção — queda de compras','Pós-colheita','Lavoura colhida','—','—','—','—','Ensolarado, 22°C','Celso comprou parte dos insumos no concorrente (Agrosul) alegando preço. Negociar pacote com bonificação.','Reapresentar proposta de pacote completo com desconto por volume e bonificação em grãos.',-27.2811000,-52.0655000,'2026-04-22 15:45:00'),
(4,5,9,1,4,'2026-03-18','08:50','Cobrança amigável + acompanhamento','Colheita','Produtividade 58 sc/ha, abaixo do esperado','Percevejo no final do ciclo','—','—','—','Ensolarado, 24°C','Conversado sobre os títulos em atraso; produtor pediu renegociação após venda da soja.','Encaminhar ao financeiro proposta de renegociação vinculada à entrega de grãos.',-27.0965000,-52.6187000,'2026-03-18 08:50:00'),
(5,6,11,1,4,'2026-06-30','11:20','Prospecção — ampliar participação','Pós-colheita','—','—','—','—','—','Ensolarado, 17°C','Elisa compra a maior parte dos insumos fora. Grande espaço em fertilizantes e defensivos. Levar proposta completa.','Elaborar proposta integrada: fertilizantes + programa fitossanitário completo para 310 ha.',-27.1387000,-52.2954000,'2026-06-30 11:20:00'),
(7,8,15,5,4,'2026-07-02','13:30','Manejo de pastagem de inverno','Estabelecimento','Aveia+azevém em bom estabelecimento','—','—','—','Deficiência de N visível','Nublado, 12°C','Orientada adubação nitrogenada em cobertura.','Ureia 45%: 150 kg/ha em cobertura após pastejo. Dividir em duas aplicações.',-27.1210000,-52.5878000,'2026-07-02 13:30:00'),
(6,7,13,1,4,'2025-11-05','09:40','Acompanhamento de plantio','Emergência (VE)','Stand adequado, 14 pl/m','—','—','—','—','Ensolarado, 25°C','Última visita registrada — produtor sem acompanhamento desde novembro.','Monitorar lagartas a partir de V4. Programar fungicida preventivo.',-27.3102000,-52.1120000,'2025-11-05 09:40:00'),
(8,9,16,2,4,'2026-05-20','16:10','Avaliação de milho','Colheita','Boa produtividade para a região','—','—','—','—','Ensolarado, 15°C','Produtor pequeno e fiel, sem pendências.','Manter programa atual. Sugerida análise de solo para 26/27.',-27.1702000,-52.3345000,'2026-05-20 16:10:00');

-- Concorrência registrada na visita do Celso
INSERT INTO concorrencia_registros (cliente_id, visita_id, concorrente, familia_id, condicoes) VALUES
(3,4,'Agrosul Insumos',4,'Fungicida com 8% abaixo da nossa tabela, prazo safra'),
(3,4,'Agrosul Insumos',5,'Inseticida em pacote casado com fungicida');

-- ---------------------------------------------------------------------------
-- MODELOS DE RECOMENDAÇÃO (pré-cadastrados, por cultura/categoria)
-- ---------------------------------------------------------------------------

INSERT INTO modelos_recomendacao (cultura_id, categoria, titulo, texto_padrao) VALUES
(1,'Fungicida','Programa fungicida soja — padrão','Aplicação preventiva de fungicida Triazol + Estrobilurina na dose de 0,75 L/ha a partir do estádio R1, com reaplicações em intervalos de 14 a 18 dias (total de 3 aplicações). Adicionar fungicida multissítio (Mancozebe 1,5 kg/ha) a partir da segunda aplicação para manejo de resistência. Utilizar óleo mineral 0,5% v/v. Volume de calda mínimo de 120 L/ha.'),
(1,'Inseticida','Controle de percevejos — soja','Monitorar com pano de batida a partir de R3. Ao atingir nível de ação (2 percevejos/m), aplicar inseticida Diamida 0,15 L/ha em mistura com Piretróide 0,3 L/ha. Reaplicar conforme monitoramento semanal. Priorizar aplicações nas primeiras horas da manhã.'),
(1,'Herbicida','Dessecação pré-plantio — soja','Dessecação com Glifosato 720 WG 2,5 kg/ha + 2,4-D 1,0 L/ha, 15 a 20 dias antes do plantio. Em áreas com buva resistente, complementar com aplicação sequencial. Respeitar intervalo mínimo de 7 dias entre aplicação de 2,4-D e o plantio.'),
(1,'Nutrição','Adubação foliar soja — Mn/Zn/CoMo','Aplicar Cobalto e Molibdênio 100 mL/ha no tratamento de sementes ou até V3. Adubo foliar Mn+Zn 1,0 L/ha em V4 e reaplicação em R1, especialmente em áreas com histórico de deficiência ou aplicação de glifosato.'),
(2,'Fungicida','Programa fungicida milho','Primeira aplicação em V8 e segunda no pré-pendoamento com Triazol + Estrobilurina 0,75 L/ha + óleo mineral 0,5% v/v. Em híbridos suscetíveis a doenças foliares, antecipar para V6 e considerar terceira aplicação.'),
(2,'Nutrição','Adubação de cobertura milho','Ureia 45%: 300 kg/ha em cobertura, dividida em duas aplicações (V4 e V8), preferencialmente antes de chuva prevista. Em solos arenosos, dividir em três aplicações.'),
(3,'Fungicida','Controle de giberela — trigo','Aplicação de fungicida no início do florescimento (10% de anteras expostas) com reaplicação em 7 a 10 dias se persistirem condições de umidade. Usar pontas de jato duplo para melhor cobertura das espigas.'),
(5,'Nutrição','Adubação nitrogenada pastagem inverno','Ureia 45%: 150 kg/ha em cobertura após cada ciclo de pastejo, iniciando 30 dias após emergência da aveia/azevém. Suspender aplicação em previsão de geada forte.'),
(NULL,'Geral','Recomendação de análise de solo','Coletar amostras de solo na camada 0-10 cm e 10-20 cm, com 15 subamostras por gleba homogênea. Encaminhar ao laboratório para definição do programa de adubação e correção da próxima safra.');

-- ---------------------------------------------------------------------------
-- METAS CAP — Vera Vendedora (5) e Carlos Consultor (4), período safra 25/26
-- ---------------------------------------------------------------------------

INSERT INTO metas_cap (id, usuario_id, indicador, unidade, meta, periodo_inicio, periodo_fim) VALUES
(1,5,'Faturamento Insumos','R$',1800000.00,'2025-09-01','2026-08-31'),
(2,5,'Fertilizantes (toneladas)','t',220.00,'2025-09-01','2026-08-31'),
(3,5,'Clientes positivados','clientes',30.00,'2025-09-01','2026-08-31'),
(4,5,'Pacotes Agrícolas vendidos','pacotes',12.00,'2025-09-01','2026-08-31'),
(5,4,'Visitas técnicas realizadas','visitas',180.00,'2025-09-01','2026-08-31'),
(6,4,'Recomendações emitidas','recom.',150.00,'2025-09-01','2026-08-31');

INSERT INTO realizado_cap (meta_id, valor, data_ref) VALUES
(1,1425000.00,'2026-06-30'),
(2,196.00,'2026-06-30'),
(3,24.00,'2026-06-30'),
(4,7.00,'2026-06-30'),
(5,164.00,'2026-06-30'),
(6,141.00,'2026-06-30');

-- ---------------------------------------------------------------------------
-- PLANOS DE SAFRA (intenção de plantio 26/27 usa a safra atual como referência)
-- ---------------------------------------------------------------------------

INSERT INTO planos_safra (cliente_id, propriedade_id, cultura_id, safra_id, area_ha) VALUES
(1,1,1,2,215.00),(1,1,2,2,110.00),(1,2,3,2,85.00),
(2,3,1,2,140.00),(2,3,2,2,120.00),
(3,4,1,2,100.00),(3,4,2,2,90.00),
(5,6,1,2,160.00),(5,6,2,2,150.00),
(6,7,1,2,95.00),(6,7,3,2,80.00);

-- Referência de investimento por ha (cultura x família)
INSERT INTO culturas_referencia (cultura_id, familia_id, custo_por_ha) VALUES
(1,1,700.00),(1,2,1100.00),(1,3,250.00),(1,4,420.00),(1,5,300.00),(1,6,90.00),(1,7,40.00),
(2,1,900.00),(2,2,1400.00),(2,3,180.00),(2,4,300.00),(2,5,220.00),
(3,1,450.00),(3,2,700.00),(3,4,280.00),
(5,2,400.00);

-- Calendário agronômico (gatilhos comerciais por janela)
INSERT INTO calendario_agronomico (cultura_id, atividade, familia_id, mes_inicio, mes_fim, descricao) VALUES
(1,'Compra de sementes e tratamento',1,7,9,'Janela de reserva de sementes de soja para o plantio de setembro/outubro'),
(1,'Adubação de base',2,8,10,'Aquisição e aplicação de fertilizante de base para soja'),
(1,'Dessecação pré-plantio',3,8,10,'Dessecação das áreas para plantio da soja'),
(1,'Programa fungicida',4,12,3,'Aplicações de fungicida em soja (R1 em diante)'),
(1,'Compra antecipada de fungicidas',4,6,8,'Negociação antecipada do programa fungicida da próxima safra'),
(1,'Controle de percevejos',5,1,3,'Monitoramento e controle de percevejos em soja'),
(2,'Compra de sementes de milho',1,6,8,'Reserva de sementes de milho para plantio antecipado'),
(2,'Adubação de cobertura',2,10,12,'Ureia em cobertura no milho (V4-V8)'),
(3,'Fungicida giberela',4,9,10,'Controle de giberela no florescimento do trigo'),
(5,'Adubação de pastagem de inverno',2,5,8,'Ureia em cobertura em aveia/azevém');

-- ---------------------------------------------------------------------------
-- ENTREGAS FUTURAS (produtos contratados com retirada parcial)
-- ---------------------------------------------------------------------------

INSERT INTO entregas_futuras (cliente_id, produto_id, quantidade_contratada, quantidade_retirada, data_contrato, previsao_entrega) VALUES
(1,3,65,40,'2025-09-22','2026-08-30'),
(1,1,320,320,'2025-09-18','2026-09-30'),
(2,3,38,20,'2025-09-30','2026-08-20'),
(6,3,30,12,'2025-10-12','2026-08-25'),
(7,16,650,380,'2026-03-08','2026-10-31');

-- ---------------------------------------------------------------------------
-- PACOTE AGRÍCOLA de demonstração — Soja 26/27
-- ---------------------------------------------------------------------------

INSERT INTO pacotes_agricolas (id, nome, cultura_id, safra_id, vigencia_inicio, vigencia_fim, regiao, campanha, bonificacao_sacas_ha) VALUES
(1,'Pacote Soja Alta Performance 26/27',1,2,'2026-06-01','2026-10-31','Alto Uruguai Catarinense','Campanha Safra 26/27',1.50);

-- Categorias do pacote: desconto/bonificação/obrigatoriedade/quantidade mínima
INSERT INTO pacote_categorias (pacote_id, familia_id, desconto_pct, bonificacao_pct, obrigatoria, qtd_minima) VALUES
(1,1,5.00,1.00,1,50),    -- Sementes (obrigatória, mín. 50 sc)
(1,2,7.00,1.50,1,10),    -- Fertilizantes (obrigatória, mín. 10 t)
(1,3,4.00,0.50,0,0),     -- Herbicidas
(1,4,8.00,2.00,1,100),   -- Fungicidas (obrigatória, mín. 100 L)
(1,5,6.00,1.00,1,30),    -- Inseticidas (obrigatória, mín. 30 L)
(1,6,3.00,0.50,0,0),     -- Nutrição/Adubo foliar
(1,7,3.00,0.50,0,0),     -- Biológicos
(1,8,2.00,0.00,0,0);     -- Adjuvantes

-- Produtos específicos obrigatórios com validação técnica (dose/ha, aplicações, mín/máx)
INSERT INTO pacote_obrigatorios (pacote_id, produto_id, dose_ha, num_aplicacoes, qtd_minima, qtd_maxima) VALUES
(1,7,0.75,3,100,2000),   -- Fungicida Triazol+Estrobilurina: 0,75 L/ha x 3 aplicações
(1,9,0.15,2,20,600),     -- Inseticida Diamida: 0,15 L/ha x 2 aplicações
(1,1,1.10,1,50,1200);    -- Semente de soja: 1,1 sc/ha

-- ---------------------------------------------------------------------------
-- OPORTUNIDADES manuais de exemplo (as automáticas são geradas pelo sistema)
-- ---------------------------------------------------------------------------

INSERT INTO oportunidades (cliente_id, usuario_id, safra_id, familia_id, origem, titulo, valor_estimado, estagio, data_prevista) VALUES
(5,5,2,2,'Manual','Proposta integrada de fertilizantes — Granja Ettori',165000.00,'Negociação','2026-08-15'),
(1,5,2,1,'Manual','Reserva antecipada de sementes 26/27',140000.00,'Proposta','2026-08-30');

INSERT INTO propostas (oportunidade_id, validade, condicao_pagamento, valor_total, situacao) VALUES
(2,'2026-07-25','Safra 26/27 — vencimento maio/2027',140000.00,'Em aberto');

INSERT INTO propostas_itens (proposta_id, produto_id, quantidade, valor_unitario) VALUES
(1,1,320,420.00);

-- ---------------------------------------------------------------------------
-- FASE 3 — Despesas, reembolso, reclamações e documentos
-- ---------------------------------------------------------------------------

-- Categorias de reembolso (valor por km e teto de refeição por categoria)
INSERT INTO categorias_reembolso (id, nome, valor_km, teto_refeicao) VALUES
(1,'Agrônomo',1.80,60.00),
(2,'Extensionista',1.60,50.00),
(3,'Vendedor',1.50,45.00),
(4,'Gestor',2.00,80.00);

-- Atribui categoria aos usuários de campo/gestão que recebem reembolso
UPDATE usuarios SET categoria_reembolso_id = 4 WHERE id IN (2,3);   -- gestores
UPDATE usuarios SET categoria_reembolso_id = 1 WHERE id = 3;        -- gestora técnica → agrônomo
UPDATE usuarios SET categoria_reembolso_id = 1 WHERE id = 4;        -- consultor técnico → agrônomo
UPDATE usuarios SET categoria_reembolso_id = 3 WHERE id = 5;        -- vendedora

-- Veículos cadastrados por usuário
INSERT INTO veiculos (id, usuario_id, descricao, placa) VALUES
(1,5,'Fiat Strada','ABC1D23'),
(2,4,'VW Saveiro','EFG4H56');

-- Quilometragem de exemplo (valor = km rodados x valor_km da categoria)
INSERT INTO quilometragem (usuario_id, veiculo_id, veiculo, data, km_inicial, km_final, valor, tipo_destino, cliente_id, destino, motivo) VALUES
(5,1,'Fiat Strada — ABC1D23','2026-07-06',45210.0,45298.0,132.00,'Produtor',1,'Linha São Roque, Concórdia','Visita técnica e negociação'),
(5,1,'Fiat Strada — ABC1D23','2026-07-10',45298.0,45362.0,96.00,'Produtor',2,'Seara','Acompanhamento de lavoura'),
(4,2,'VW Saveiro — EFG4H56','2026-07-08',88110.0,88190.0,144.00,'Produtor',7,'Chapecó','Assistência técnica');

-- Valores de reembolso de refeição por categoria e tipo (Café, Almoço, Lanche, Janta)
INSERT INTO reembolso_refeicoes (categoria_reembolso_id, tipo, valor) VALUES
(1,'Café',15.00),(1,'Almoço',40.00),(1,'Lanche',15.00),(1,'Janta',35.00),   -- Agrônomo
(2,'Café',12.00),(2,'Almoço',35.00),(2,'Lanche',12.00),(2,'Janta',30.00),   -- Extensionista
(3,'Café',10.00),(3,'Almoço',30.00),(3,'Lanche',10.00),(3,'Janta',28.00),   -- Vendedor
(4,'Café',18.00),(4,'Almoço',50.00),(4,'Lanche',18.00),(4,'Janta',45.00);   -- Gestor

-- Refeições de exemplo (valor_reembolso = min(gasto, teto da categoria/tipo))
INSERT INTO refeicoes (usuario_id, data, hora, tipo, cliente_id, estabelecimento, valor, valor_reembolso, justificativa) VALUES
(5,'2026-07-06','12:15','Almoço',1,'Restaurante Sabor da Roça',38.00,30.00,'Almoço em visita a produtor'),
(4,'2026-07-08','12:40','Almoço',7,'Cantina Central',42.00,40.00,'Almoço durante assistência técnica');

-- Reclamações de exemplo (fluxo de laudo)
INSERT INTO reclamacoes (cliente_id, usuario_id, produto_id, tipo, lote, nota_fiscal, cultura_id, problema, descricao, status) VALUES
(1,4,1,'Sementes','L2026-0455','NF-88231',1,'Baixa germinação','Germinação abaixo de 70% em duas glebas.','Em análise'),
(6,5,7,'Defensivos','FG-7781','NF-88410',1,'Fitotoxidez','Sintoma de fitotoxidez após aplicação de fungicida.','Registrada');

-- schema_versao: instalações novas já nascem na versão atual (não re-executam migrações)
-- ---------------------------------------------------------------------------
-- FASE 4 — vínculo do Produtor ao cliente, agenda e notificações de exemplo
-- ---------------------------------------------------------------------------
UPDATE usuarios SET cliente_id = 1 WHERE id = 7;  -- Pedro Produtor ↔ Alberto Antunes (portal)

INSERT INTO agenda_eventos (usuario_id, cliente_id, tipo, titulo, data, hora, status, descricao) VALUES
(5,2,'Visita','Acompanhar florescimento — Berenice','2026-07-22','08:30','Pendente','Conferir estágio R1'),
(5,4,'Cobrança','Renegociar título em atraso — Dirceu','2026-07-23','14:00','Pendente','Levar proposta de parcelamento'),
(4,7,'Reunião','Planejamento de safra — Gilda','2026-07-24','10:00','Pendente',NULL),
(5,1,'Tarefa','Enviar recomendação de fungicida','2026-07-21',NULL,'Concluído',NULL);

INSERT INTO notificacoes (usuario_id, tipo, titulo, texto, link) VALUES
(5,'agenda','Visita agendada','Acompanhar florescimento — Berenice (22/07)','index.php?r=agenda'),
(5,'churn','Risco de churn','Celso Casagrande com queda de 62% vs. safra anterior','index.php?r=clientes');

-- Linhas rurais de exemplo para alguns produtores
UPDATE clientes SET linha='Linha São Roque' WHERE id IN (1,4);
UPDATE clientes SET linha='Linha Barra Fria' WHERE id IN (2,5);
UPDATE clientes SET linha='Linha Sede' WHERE id IN (3,6);

-- Idempotência do offline (O3): dedup de reenvios da fila por uuid
DROP TABLE IF EXISTS sync_processados;
CREATE TABLE sync_processados (
  uuid VARCHAR(36) NOT NULL PRIMARY KEY,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO configuracoes (chave, valor) VALUES ('schema_versao','17')
  ON DUPLICATE KEY UPDATE valor = '16';
