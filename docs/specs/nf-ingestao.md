# SPEC — Ingestão de Notas Fiscais do Produtor

**Projeto:** CRM Agropecuário Copérdia · Portal do Produtor
**Local no repo:** `docs/specs/nf-ingestao.md`
**Status:** aprovado para especificação · piloto restrito
**Depende de:** `docs/specs/custo-lavoura.md` (`lavoura_custo`, `cat_item_custo`, `agg_custo_regional`)
**Invariantes tocadas:** 4 (dado sensível), 5 (firewall do cooperado)

---

## 0. Leia isto antes de qualquer coisa

A nota fiscal de um produtor **revela o que ele comprou de outros fornecedores** —
é o dado competitivo mais sensível que existe sobre o cooperado. Três consequências
não negociáveis:

1. **Tudo o que entra por aqui é dado do produtor, sob firewall (invariante 5).**
   Perfil comercial da Copérdia nunca lê NF individual. Restrição no usuário de
   banco, não só na aplicação.
2. **Só entra com autorização explícita e revogável do produtor** (procuração
   eletrônica / opt-in). Sem autorização ativa, não se captura nada.
3. **Nada vira custo sem o produtor confirmar o mapeamento.** A captura sugere; o
   produtor revisa e aplica.

## 1. Objetivo

Trazer para o Portal as notas fiscais de insumos do produtor — inclusive de
**outros fornecedores** — para preencher a tabela de custo (`lavoura_custo`) e
tornar o ponto de equilíbrio real, sem depender de digitação manual.

**A pergunta que o módulo responde, na voz do produtor:** *o que eu já gastei nesta
lavoura, somando tudo o que comprei — não só na Copérdia.*

## 2. Onde encaixa

`docs/specs/custo-lavoura.md` define `lavoura_custo.fonte ENUM('manual','preset','nf_coperdia')`.
Esta spec **estende** essa origem e alimenta a mesma tabela:

- `nf_coperdia` — o que o produtor comprou **na Copérdia**, já disponível via Qlik
  (`fato × produtos × categorias`, ver `docs/INTEGRACAO_QLIK.md`). Pré-preenchimento
  de partida, sem certificado nem procuração.
- **novo `dfe`** — NF-e de **qualquer** fornecedor, capturada por Distribuição de
  DF-e (eixo desta spec).
- **novo `upload`** — XML enviado pelo próprio produtor (via complementar).

## 3. Canais de ingestão

| # | Canal | Cobre | Autorização | Papel nesta spec |
|---|---|---|---|---|
| 1 | Copérdia via Qlik (`nf_coperdia`) | só compras na Copérdia | nenhuma | pré-preenchimento (já existe) |
| 2 | Upload do XML pelo produtor | todos os fornecedores | consentimento (doc dele) | **complementar** |
| 3 | Distribuição de DF-e (webservice SEFAZ) + procuração | todos | procuração + certificado + SOAP | base do canal 4 |
| 4 | **DF-e via SaaS de captura** | todos | procuração do produtor | **eixo (backbone)** |
| 5 | Via contador | todos | produtor autoriza o contador | fora do v1 |
| 6 | OCR do DANFE por foto | avulso/papel | consentimento | fallback futuro |

Nota: no canal 3 existe o `autXML` (o emitente inclui o CPF/CNPJ de um terceiro
autorizado a baixar o XML). Não escala — serve só a casos pontuais.

## 4. Decisão técnica — DF-e via SaaS, atrás de adaptador estável

- **Provedor plugável.** Um SaaS de captura (que já tem custódia de certificado e o
  webservice `NFeDistribuicaoDFe` implementados) puxa as NF-e onde o produtor é
  destinatário. Integramos a **API do provedor atrás de um adaptador com interface
  estável** — mesmo princípio do `IntegracaoService` (fonte configurável): trocar de
  provedor mexe só no adaptador. **A spec não amarra a um fornecedor específico.**
- **Pull agendado** (diário) por produtor com autorização **ativa**.
- **Dedup por chave de acesso** (44 dígitos) — reprocessar é idempotente.
- **Janela de ~90 dias** da SEFAZ: o pull cobre o período disponível; lacunas fora
  da janela entram pelo **upload de XML** (canal 2).
- **Manifestação do destinatário**, quando o provedor exigir para liberar o XML
  completo, é responsabilidade do adaptador — transparente para o resto do sistema.

Interface (conceitual, espelha `IntegracaoService`):

```
FiscalAdapter (por provedor)
  autorizar(produtor)            → inicia/registra a procuração no provedor
  revogar(produtor)              → encerra a autorização
  status(produtor)               → estado da autorização
  puxarDocumentos(produtor, desde) → [ {chave, emitente, itens[...], xml}, ... ]

NotaFiscalService (estável, não muda com o provedor)
  capturarPorProdutor(produtorId): resumo   ← chamado pelo job diário
  importarXmlUpload(produtorId, xml): resumo ← canal 2
  sugerirMapeamento(nfeId)
  aplicarEmLavoura(lavouraSafraId, itensConfirmados)
```

## 5. Modelo de dados

Todas as tabelas de NF nascem **sob firewall** (ver seção 10), exceto o catálogo
`map_ncm_item` (regra genérica, sem dado de produtor).

```sql
-- Autorização fiscal (procuração p/ captura de DF-e). Opt-in, revogável. ⚠ FIREWALL
CREATE TABLE produtor_autorizacao_fiscal (
  id             BIGINT AUTO_INCREMENT PRIMARY KEY,
  produtor_id    BIGINT      NOT NULL,
  provedor       VARCHAR(30) NOT NULL,
  status         ENUM('pendente','ativa','revogada','expirada','erro') NOT NULL DEFAULT 'pendente',
  procuracao_ref VARCHAR(120) NULL,          -- referência da procuração no provedor
  dt_autorizacao DATETIME NULL,
  dt_revogacao   DATETIME NULL,
  dt_atualizacao DATETIME NOT NULL,
  UNIQUE KEY uk_prod_prov (produtor_id, provedor),
  INDEX ix_status (status)
);

-- NF-e capturada (produtor = destinatário). ⚠ FIREWALL
CREATE TABLE nfe_documento (
  id            BIGINT AUTO_INCREMENT PRIMARY KEY,
  chave_acesso  CHAR(44)     NOT NULL,        -- dedup
  produtor_id   BIGINT       NOT NULL,
  emit_cnpj     VARCHAR(14)  NULL,
  emit_nome     VARCHAR(160) NULL,
  serie         VARCHAR(6)   NULL,
  numero        VARCHAR(20)  NULL,
  dt_emissao    DATETIME     NULL,
  valor_total   DECIMAL(14,2) NULL,
  natureza_op   VARCHAR(120) NULL,
  fonte         ENUM('dfe','upload','ocr') NOT NULL DEFAULT 'dfe',
  situacao      ENUM('capturada','autorizada','cancelada','denegada') NOT NULL DEFAULT 'capturada',
  xml           MEDIUMBLOB   NULL,            -- XML assinado (dado do produtor)
  dt_captura    DATETIME     NOT NULL,
  UNIQUE KEY uk_chave (chave_acesso),
  INDEX ix_prod (produtor_id, dt_emissao)
);

-- Item da NF-e. ⚠ FIREWALL
CREATE TABLE nfe_item (
  id           BIGINT AUTO_INCREMENT PRIMARY KEY,
  nfe_id       BIGINT       NOT NULL,
  n_item       SMALLINT     NOT NULL,
  descricao    VARCHAR(200) NOT NULL,
  ncm          CHAR(8)      NULL,
  cfop         CHAR(4)      NULL,
  unidade      VARCHAR(10)  NULL,
  quantidade   DECIMAL(14,4) NULL,
  valor_unit   DECIMAL(14,6) NULL,
  valor_total  DECIMAL(14,2) NULL,
  cat_item_id  INT          NULL,             -- mapeamento p/ custo
  status_map   ENUM('sugerido','confirmado','ignorado') NOT NULL DEFAULT 'sugerido',
  CONSTRAINT fk_item_nfe FOREIGN KEY (nfe_id) REFERENCES nfe_documento(id) ON DELETE CASCADE,
  CONSTRAINT fk_item_cat FOREIGN KEY (cat_item_id) REFERENCES cat_item_custo(id),
  INDEX ix_ncm (ncm)
);

-- Heurística NCM → item de custo (catálogo da Copérdia; NÃO é firewall)
CREATE TABLE map_ncm_item (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  ncm_prefix  VARCHAR(8) NOT NULL,
  cat_item_id INT        NOT NULL,
  confianca   ENUM('alta','media','baixa') NOT NULL DEFAULT 'media',
  UNIQUE KEY uk_ncm (ncm_prefix),
  CONSTRAINT fk_map_cat FOREIGN KEY (cat_item_id) REFERENCES cat_item_custo(id)
);

-- Log operacional do pull (auditoria da captura). ⚠ FIREWALL (tem produtor_id)
CREATE TABLE nfe_captura_log (
  id          BIGINT AUTO_INCREMENT PRIMARY KEY,
  produtor_id BIGINT NULL,
  provedor    VARCHAR(30) NOT NULL,
  dt_exec     DATETIME NOT NULL,
  documentos  INT NOT NULL DEFAULT 0,
  novos       INT NOT NULL DEFAULT 0,
  status      ENUM('ok','erro','sem_autorizacao') NOT NULL,
  mensagem    VARCHAR(255) NULL
);
```

Extensão em `custo-lavoura.md`: `lavoura_custo.fonte` passa a aceitar `'dfe'` e
`'upload'`, além de `'manual'`, `'preset'`, `'nf_coperdia'`.

## 6. Autorização do produtor (procuração)

- Fluxo **opt-in** no Portal: o produtor autoriza a Copérdia/provedor a capturar as
  NF-e onde ele é destinatário. `produtor_autorizacao_fiscal.status='ativa'`.
- **Revogável a qualquer momento** (`status='revogada'`, `dt_revogacao`). Revogação
  **para os pulls futuros imediatamente**; a retenção/eliminação dos dados já
  capturados segue a política de retenção (seção 10).
- Sem autorização ativa, o job diário registra `sem_autorizacao` e não captura.

## 7. Mapeamento item NF → item de custo

- `NotaFiscalService::sugerirMapeamento` casa cada `nfe_item` a um `cat_item_custo`
  por **NCM** (via `map_ncm_item`, por prefixo) e, na falta, por similaridade de
  descrição. Grava `status_map='sugerido'`.
- **O produtor revisa e confirma** (`status_map='confirmado'`) ou ignora. **Nada
  entra em `lavoura_custo` sem confirmação** — evita poluir o custo com item errado
  ou não-agrícola.
- Ao aplicar, o **servidor** grava em `lavoura_custo` com `fonte='dfe'` (ou
  `'upload'`), convertendo valor total do item para R$/ha pela área da lavoura.
  Nunca confia em valor calculado no cliente.
- `map_ncm_item` é mantido pela Copérdia (agronomia + controladoria) e melhora com o
  piloto.

## 8. Acesso e visibilidade

- **Produtor** (muitos usuários): autenticado no Portal, vê **só os próprios** dados
  — NFs, itens, custos, cenários, propriedades. Isolamento por `produtor_id`;
  **toda** rota `/portal/*` valida que o recurso pertence ao produtor autenticado
  (nunca só por ID no path). Vínculo usuário↔produtor pelo mecanismo já existente
  (`usuarios.cliente_id`).
- **Painel administrativo da Copérdia** — vê **todas** as informações e **compara
  propriedades**, com a fronteira do firewall:
  - **Território/comercial** (potencial, realizado, share, gap por propriedade —
    dado da Copérdia/Qlik): comparação **livre** entre propriedades. É o mapa
    territorial (`docs/specs/mapa-territorial.md`), sem firewall.
  - **Custo do produtor** — visibilidade por perfil, **decisão de governança da
    Diretoria (registrada)**:
    - **Veem o custo individual identificável e comparam propriedades:**
      **Diretoria** (Administrador), **Controladoria** (Analista) e **Gestor
      Comercial** — sempre com **auditoria de todo acesso** e com a ciência do
      produtor obtida no opt-in (seção 6: o termo de autorização informa que a
      gestão da Copérdia pode consultar o custo para fins de controladoria e
      estratégia).
    - **Nunca veem o custo individual:** **Vendedor/RTV**, **Consultor Técnico** e
      **Gestor Técnico** (perfis de campo). Para esses, a comparação de custo é só
      por **agregado anonimizado** — `agg_custo_regional`, k-anonimato ≥ 5
      produtores por bucket (custo-lavoura.md §7).
  - Esta decisão **relaxa o invariante 5 original** ("qualquer perfil comercial")
    para uma **lista explícita de perfis de gestão**, preservando o núcleo: o
    **campo/RTV nunca vê**. Ver `docs/INVARIANTES.md`, invariante 5 (atualizado).

## 9. Contrato de API

Rotas do **Portal** (autenticado como produtor), todas validando propriedade do
recurso:

```
POST   /portal/fiscal/autorizacao            → inicia a procuração (retorna o fluxo do provedor)
DELETE /portal/fiscal/autorizacao            → revoga
GET    /portal/fiscal/autorizacao            → status atual
GET    /portal/fiscal/notas?desde=           → NFs capturadas do produtor
GET    /portal/fiscal/notas/:chave           → NF + itens + sugestões de mapeamento
PUT    /portal/fiscal/notas/:chave/itens     → confirma/ajusta mapeamento item→cat_item
POST   /portal/lavouras/:id/aplicar-nfe      → aplica itens confirmados em lavoura_custo (servidor grava fonte='dfe')
POST   /portal/fiscal/upload                 → upload de XML (canal 2); mesmo pipeline de captura
```

Rotas do **painel admin**:

```
GET /admin/custo/agregado?safra=&cultura=&municipio=&faixa_area=
    → agg_custo_regional (k ≥ 5); comparação anonimizada. Disponível a qualquer
      perfil de gestão. É a ÚNICA visão de custo para os perfis de campo.
GET /admin/custo/individual?safra=&produtor=|&municipio=   [perfis: Diretoria, Controladoria, Gestor Comercial]
    → custo individual identificável por propriedade/produtor; comparação direta.
      Acesso restrito aos três perfis; TODA consulta gravada em log de auditoria.
      Vendedor/RTV, Consultor Técnico e Gestor Técnico → 403.
```
(Território/comercial usa a API do mapa territorial, sem firewall.)

**Job interno** (não é endpoint público): pull diário — para cada produtor com
autorização ativa, `NotaFiscalService::capturarPorProdutor` via o adaptador do
provedor; grava em `nfe_documento`/`nfe_item` (dedup por chave) e loga em
`nfe_captura_log`.

## 10. Firewall e LGPD — requisito de segurança

Firewall **por perfil** (decisão de governança, seção 8):

1. **Perfis de campo** — Vendedor/RTV, Consultor Técnico, Gestor Técnico: usuário de
   banco **sem `SELECT`** em `produtor_autorizacao_fiscal`, `nfe_documento`,
   `nfe_item`, `nfe_captura_log`, `lavoura_custo`, `lavoura_cenario`. Negado no MySQL,
   não só na app. Para eles, custo só via `agg_custo_regional` (k ≥ 5).
2. **Perfis de gestão autorizados** — Diretoria (Administrador), Controladoria
   (Analista), Gestor Comercial: acesso ao custo individual por um **usuário de banco
   próprio** e por rota dedicada (`/admin/custo/individual`), com **log de auditoria
   obrigatório em toda leitura** (usuário, timestamp, produtor consultado, motivo).
3. Nenhuma view/join/endpoint do CRM interno de campo referencia essas tabelas.
4. Portal e job de captura rodam com usuário de banco próprio.
5. **Autorização revogável**; a ciência do produtor sobre o acesso da gestão é obtida
   no opt-in (seção 6).
6. **Retenção**: XML e itens guardados enquanto a lavoura/cenário fizer referência;
   política de expurgo definida com a Diretoria (padrão: expurgar a pedido do
   produtor na revogação, preservando o agregado anonimizado já publicado).

Critério verificável: autenticar com credencial de **Vendedor/RTV** e executar
`SELECT * FROM nfe_documento` → **erro de permissão do banco**; com **Gestor
Comercial**, a leitura é permitida **e** deixa registro de auditoria.

## 11. Critérios de aceite

1. Produtor autoriza a captura; job diário traz as NF-e do período e nenhuma
   duplica (dedup por chave de acesso).
2. Produtor sem autorização ativa → nada é capturado; log `sem_autorizacao`.
3. Produtor vê **só** as próprias NFs; tentativa de acessar NF de outro produtor →
   negado.
4. `SELECT` nas tabelas de NF com credencial de **perfil de campo** (Vendedor/RTV,
   Consultor Técnico, Gestor Técnico) → erro de permissão do banco.
5. Item de NF só entra em `lavoura_custo` após confirmação do produtor; ao aplicar,
   o custo aparece com `fonte='dfe'` (ou `'upload'`).
6. Upload de um XML de NF-e válido produz os mesmos itens que o pull produziria.
7. Revogar a autorização interrompe os pulls seguintes.
8. `/admin/custo/individual` só responde a **Diretoria, Controladoria e Gestor
   Comercial** (demais → 403), e **toda** consulta gera registro de auditoria. Para os
   perfis de campo, a comparação de custo usa `agg_custo_regional` e não retorna
   nenhum bucket com `qtd_produtores < 5`.
9. Funciona em viewport de 360 px (produtor no celular).

## 12. Fora de escopo no v1

Emissão de NF-e · captura via contador (canal 5) · OCR do DANFE (canal 6) ·
conciliação NF × estoque do produtor · rateio de item entre talhões · importação de
NFC-e/cupom · integração SEFAZ construída internamente (usamos SaaS).

## 13. Ordem de implementação

Um PR por vez; não iniciar o seguinte antes de o anterior estar mergeado.

| PR | Entrega |
|---|---|
| 1 | Migrations das tabelas + **firewall no banco** (usuário de app sem SELECT) + teste de permissão |
| 2 | `FiscalAdapter` (1 provedor) + `NotaFiscalService::capturarPorProdutor` + dedup por chave + `nfe_captura_log` |
| 3 | Fluxo de **autorização/revogação** no Portal (`produtor_autorizacao_fiscal`) |
| 4 | Job de pull diário por produtor autorizado |
| 5 | `map_ncm_item` + `sugerirMapeamento` + tela de revisão/confirmação do produtor |
| 6 | `aplicarEmLavoura` → grava `lavoura_custo` (fonte `dfe`), servidor recalcula R$/ha |
| 7 | Canal 2 (`/portal/fiscal/upload`) reusando o mesmo pipeline |
| 8 | Endpoint admin de comparação agregada (`agg_custo_regional`) e checagem de k-anonimato |

PR 1 antes de tudo: o firewall existe **antes** de qualquer dado de NF entrar.

## 14. Padrões a reusar (implementação no CRM PHP atual)

Se implementado no CRM PHP (e não no `apps/portal` planejado em stack separada), o
molde já existe:

- `app/Controllers/IntegracaoController.php::importarCarga` — esqueleto de upload
  (valida `$_FILES`, `is_uploaded_file`, extensão, tamanho, `try/catch`,
  `json_ok`/`json_erro`, `auditar`, `liberar_sessao`).
- `app/Services/CarService.php` (commit em lotes) e `ShapefileService::streamImoveis`
  (parsing em streaming) — molde para XML em lote. Parse de NF-e via `simplexml`.
- `app/Services/IntegracaoService.php` — adaptador de fonte configurável (molde do
  `FiscalAdapter`) e `registrar()` → log.
- `Instalador::migracoesLeves()` — bump de `schema_versao` para as tabelas novas.

**Nota de stack:** como os specs irmãos (`custo-lavoura.md`, `mapa-territorial.md`),
esta descreve a API sob `/portal/*` e serviços em `src/core`. A reconciliação com o
CRM PHP atual (rotas `?r=`) é decisão da fase de implementação, registrada no PR 1.
