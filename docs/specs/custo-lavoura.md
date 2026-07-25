# SPEC — Custo da Lavoura e Ponto de Equilíbrio

**Projeto:** CRM Agropecuário Copérdia · Portal do Produtor
**Local no repo:** `docs/specs/custo-lavoura.md`
**Status:** aprovado para implementação · piloto restrito
**Referência visual:** `docs/prototipos/custo_lavoura.html`
**Depende de:** `docs/specs/mapa-territorial.md` (tabela `dim_imovel`, `fato_talhao_safra`)

---

## 0. Leia isto antes de qualquer coisa

Este módulo é usado **pelo cooperado**, não por funcionário da Copérdia. Três consequências que não são negociáveis:

1. **Um erro de cálculo aqui faz um produtor tomar decisão errada de venda.** O motor de cálculo é desenvolvido com teste antes da implementação. Sem exceção.
2. **A Copérdia é compradora da produção deste mesmo produtor.** A ferramenta é descritiva. Nunca recomenda comprar, vender, travar ou esperar.
3. **O custo que o produtor digita é dado dele.** A equipe comercial da Copérdia não tem acesso. Isso é decisão de schema, implementada no PR 1, e não pode ser retrofitada depois.

## 1. Objetivo

Permitir que o cooperado monte o custo de produção da lavoura, enxergue seu ponto de equilíbrio e simule o efeito de vender parte da produção antecipadamente.

**A pergunta que o módulo responde, na voz do produtor:** *quantas sacas da minha colheita só pagam a conta, e quantas sobram para mim.*

## 2. Metodologia — CONAB, não invenção própria

Três níveis acumulativos, em R$/ha:

| Sigla | Composição | Pergunta que responde |
|---|---|---|
| **COE** | desembolso direto: sementes, fertilizantes, defensivos, corretivos, operações mecanizadas, mão de obra contratada, secagem/frete, seguro e juros de custeio | consigo pagar as contas desta safra? |
| **COT** | COE + depreciação + mão de obra familiar + manutenção periódica | estou repondo o que gasto? |
| **CT** | COT + custo de oportunidade da terra + custo de oportunidade do capital próprio | compensa plantar ou arrendar? |

O usuário escolhe a base. O padrão da interface é **CT**.

## 3. Fórmulas — implementação literal, não interpretar

Seja `A` área em ha, `P` produtividade esperada em sc/ha, `M` preço de referência em R$/sc, `C` custo por hectare na base escolhida.

```
producao_total   = P * A                       [sc]
custo_total      = C * A                       [R$]

preco_equilibrio        = C / P                [R$/sc]
produtividade_equilibrio = C / M               [sc/ha]

-- travamento: t = fração travada (0..1), Pt = preço travado
sacas_travadas   = producao_total * t
receita_travada  = sacas_travadas * Pt
sacas_equilibrio = custo_total / Pt            [sc necessárias p/ zerar ao preço Pt]
pct_equilibrio   = sacas_equilibrio / producao_total
cobertura_custo  = receita_travada / custo_total
sacas_livres     = producao_total * (1 - t)
```

**Produção conservadora:** 80% da esperada. É a referência de segurança para travamento, não uma trava do sistema. Travar acima disso gera alerta, não bloqueio.

**Matriz de cenários:** produtividade em −30% / −15% / esperada / +10%; preço em −20% / −10% / referência / +10% / +20%. Regra crítica na célula: `sacas_entregues = min(sacas_travadas, producao_do_cenario)` — o produtor não entrega mais do que colheu. Resultado da célula em R$/ha.

## 4. Casos de teste obrigatórios (golden tests)

Escrever estes testes **antes** do motor. Tolerância de 0,01.

**Caso A — soja, base CT**
```
entrada:  A=64  P=60  M=132  Pt=131  t=0.40
custos/ha: COE=5040  COT=6180  CT=7360
esperado:
  custo_total             = 470.240,00
  producao_total          = 3.840 sc
  preco_equilibrio        = 122,67 R$/sc
  produtividade_equilibrio= 55,76 sc/ha
  sacas_equilibrio        = 3.589,62 sc
  pct_equilibrio          = 0,9348
  sacas_travadas          = 1.536 sc
  receita_travada         = 201.216,00
  cobertura_custo         = 0,4279
```

**Caso B — milho, base CT**
```
entrada:  A=64  P=160  M=62
custos/ha: COE=6090  COT=7330  CT=8550
esperado:
  preco_equilibrio         = 53,44 R$/sc
  produtividade_equilibrio = 137,90 sc/ha
```

**Caso C — bordas**
```
P=0    → preco_equilibrio  = null (não dividir por zero, não retornar Infinity)
Pt=0   → sacas_equilibrio  = null
t=0    → cobertura_custo   = 0
t=1.0  → alerta de risco de entrega ativo
Pt < preco_equilibrio → veredito de classe "abaixo do equilíbrio"
```

**Caso D — matriz**
```
produtividade -30%, t=0.40, base CT (Caso A)
producao_cenario = 42*64 = 2.688 sc
sacas_travadas   = 1.536 sc  (menor que 2.688, entrega integral)
verificar que a célula não permite entrega maior que a colheita
```

## 5. Onde o cálculo mora — e a exceção à regra do Qlik

O `CLAUDE.md` diz que o CRM não calcula indicador. **Este módulo é a exceção declarada**, e o limite é preciso:

| Tipo de número | Onde calcula | Por quê |
|---|---|---|
| Indicador da Copérdia (share, gap, potencial, margem, EVA) | **Qlik** | fonte única para a gestão |
| Cálculo do produtor sobre dados que o próprio produtor digitou | **motor local** | não é dado da Copérdia, não entra no DRE, precisa responder em tempo real no slider |

Implementação:

```
src/core/custo/
  motor.js        funções puras, sem DB, sem HTTP, sem I/O
  motor.test.js   os golden tests da seção 4
  versao.js       export const VERSAO_MOTOR = '1.0.0'
```

O mesmo `motor.js` é importado pelo backend e pelo frontend. Nunca duplicar a fórmula. O número na tela tem de ser bit a bit o número gravado.

**Versionamento:** todo cenário salvo grava `versao_motor`. Se a fórmula mudar, cenários antigos permanecem reproduzíveis e auditáveis. Mudança de fórmula exige bump de versão e nova rodada de golden tests.

## 6. Modelo de dados

```sql
-- Catálogo de itens, mantido pela Copérdia (agronomia + controladoria)
CREATE TABLE cat_item_custo (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  codigo      VARCHAR(30) NOT NULL UNIQUE,
  descricao   VARCHAR(120) NOT NULL,
  grupo       ENUM('coe','cot','ct') NOT NULL,
  ordem       SMALLINT NOT NULL,
  ativo       TINYINT(1) NOT NULL DEFAULT 1
);

-- A lavoura planejada
CREATE TABLE lavoura_safra (
  id                      BIGINT AUTO_INCREMENT PRIMARY KEY,
  produtor_id             BIGINT NOT NULL,
  cod_car                 VARCHAR(60) NULL,
  talhao_id               BIGINT NULL,
  safra                   VARCHAR(9) NOT NULL,
  cultura                 VARCHAR(40) NOT NULL,
  area_ha                 DECIMAL(10,3) NOT NULL,
  produtividade_esperada  DECIMAL(10,3) NOT NULL,
  preco_referencia        DECIMAL(10,2) NOT NULL,
  base_custo_padrao       ENUM('coe','cot','ct') NOT NULL DEFAULT 'ct',
  dt_criacao              DATETIME NOT NULL,
  dt_atualizacao          DATETIME NOT NULL,
  INDEX ix_prod_safra (produtor_id, safra),
  CONSTRAINT fk_ls_car FOREIGN KEY (cod_car) REFERENCES dim_imovel(cod_car)
);

-- ⚠ TABELA SOB FIREWALL — ver seção 7
CREATE TABLE lavoura_custo (
  id                BIGINT AUTO_INCREMENT PRIMARY KEY,
  lavoura_safra_id  BIGINT NOT NULL,
  cat_item_id       INT NOT NULL,
  valor_ha          DECIMAL(12,2) NOT NULL,
  fonte             ENUM('manual','preset','nf_coperdia') NOT NULL DEFAULT 'manual',
  UNIQUE KEY uk_lc (lavoura_safra_id, cat_item_id),
  CONSTRAINT fk_lc_ls FOREIGN KEY (lavoura_safra_id) REFERENCES lavoura_safra(id) ON DELETE CASCADE
);

-- Cenários salvos pelo produtor
CREATE TABLE lavoura_cenario (
  id                BIGINT AUTO_INCREMENT PRIMARY KEY,
  lavoura_safra_id  BIGINT NOT NULL,
  nome              VARCHAR(80) NOT NULL,
  pct_travado       DECIMAL(5,4) NOT NULL,
  preco_travado     DECIMAL(10,2) NOT NULL,
  base_custo        ENUM('coe','cot','ct') NOT NULL,
  versao_motor      VARCHAR(12) NOT NULL,
  resultado_json    JSON NOT NULL,      -- snapshot completo do cálculo
  dt_criacao        DATETIME NOT NULL,
  CONSTRAINT fk_cen_ls FOREIGN KEY (lavoura_safra_id) REFERENCES lavoura_safra(id) ON DELETE CASCADE
);

-- Referências públicas de mercado, cache diário
CREATE TABLE ref_mercado (
  id          BIGINT AUTO_INCREMENT PRIMARY KEY,
  cultura     VARCHAR(40) NOT NULL,
  fonte       ENUM('cepea','b3','coperdia') NOT NULL,
  vencimento  VARCHAR(20) NULL,
  preco       DECIMAL(10,2) NOT NULL,
  dt_cotacao  DATE NOT NULL,
  UNIQUE KEY uk_ref (cultura, fonte, vencimento, dt_cotacao)
);

-- Agregado liberado para a Controladoria — ver seção 7
CREATE TABLE agg_custo_regional (
  id            BIGINT AUTO_INCREMENT PRIMARY KEY,
  safra         VARCHAR(9) NOT NULL,
  cultura       VARCHAR(40) NOT NULL,
  municipio     VARCHAR(80) NOT NULL,
  faixa_area    ENUM('ate_20','20_50','50_100','acima_100') NOT NULL,
  cat_item_id   INT NOT NULL,
  valor_mediano DECIMAL(12,2) NOT NULL,
  qtd_produtores SMALLINT NOT NULL,
  dt_calculo    DATETIME NOT NULL
);
```

**Pré-carga de custo via nota da Copérdia:** `fonte = 'nf_coperdia'` permite sugerir valores a partir do que o produtor efetivamente comprou de nós. É dado nosso, não exige procuração nem certificado de ninguém. Boa conveniência para o produtor e demonstração concreta de valor antes de qualquer conversa sobre acesso a dados fiscais dele.

## 7. Firewall de dados — requisito de segurança, não de política

**Regra:** nenhum perfil comercial da Copérdia pode ler `lavoura_custo`, `lavoura_cenario` ou o `resultado_json`.

Implementação obrigatória:

1. Usuário de banco da API comercial **sem `SELECT`** nessas três tabelas. Permissão negada no nível do MySQL, não apenas checagem na aplicação.
2. Nenhuma view, join ou endpoint do CRM interno referencia essas tabelas.
3. Job de agregação roda com usuário próprio e escreve em `agg_custo_regional`.
4. **Regra de k-anonimato:** só publicar bucket com `qtd_produtores >= 5`. Abaixo disso, suprimir a linha inteira. Sem exceção para "só para ver".
5. Log de auditoria em qualquer leitura das tabelas sob firewall, com usuário, timestamp e motivo.

Critério de aceite verificável: autenticar com credencial de perfil comercial e executar `SELECT * FROM lavoura_custo` — deve retornar erro de permissão do banco.

## 8. Regras de comunicação na interface

A interface **não pode**:

- usar verbo imperativo de decisão comercial: "trave", "venda", "aguarde", "aproveite"
- afirmar direção de preço: "tendência de alta", "bom momento"
- sugerir posição em bolsa. Contrato a termo e troca-troca são contratos comerciais; recomendação de operação em B3 entra em terreno regulado
- exibir oferta da Copérdia sem exibir, na mesma tela e com igual destaque, a referência CEPEA e o futuro B3

A interface **deve**:

- expressar tudo em relação ao custo do próprio produtor: "a este preço, X% da sua produção cobre o custo"
- manter visível o aviso de que a Copérdia é uma das compradoras possíveis
- manter visível o aviso de que os custos digitados não são compartilhados com a equipe comercial

Textos exatos no protótipo. Não reescrever sem revisão.

## 9. Contrato de API

Todos os endpoints abaixo pertencem à **API do Portal**, autenticada como produtor. Não expor na API interna.

```
GET    /portal/lavouras?safra=            → lista do produtor autenticado
POST   /portal/lavouras                   → cria; body: cultura, area, produtividade, preco, codCar?
GET    /portal/lavouras/:id               → lavoura + itens de custo + último cenário
PUT    /portal/lavouras/:id/custos        → body: [{catItemId, valorHa}]
POST   /portal/lavouras/:id/cenarios      → salva cenário; servidor recalcula com o motor e grava versao_motor
GET    /portal/mercado?cultura=           → últimas cotações de ref_mercado
POST   /portal/visitas                    → solicita visita; body: motivo, urgencia, codCar?, observacao
```

**Regra:** o servidor **recalcula** ao salvar cenário. Nunca confia no número enviado pelo cliente. Divergência entre cálculo do cliente e do servidor é erro 409 e vai para log.

Toda rota valida que o recurso pertence ao produtor autenticado. Nunca por ID no path apenas.

## 10. Frontend

Projeto separado do CRM interno, em `apps/portal/`. Compartilha `src/core/custo/` e o cliente de API.

```
apps/portal/src/modules/custo/
  CustoLavoura.jsx      container
  SetupLavoura.jsx      cultura, área, produtividade, preço
  TabelaCusto.jsx       itens editáveis agrupados por COE/COT/CT
  PontoEquilibrio.jsx   os dois números grandes + seletor de base
  BarraCobertura.jsx    ⚠ elemento central do módulo
  SimuladorTravamento.jsx
  MatrizCenarios.jsx
  ReferenciaMercado.jsx
```

**Diretriz visual:** interface clara, número grande, poucos elementos. É oposta ao CRM interno por decisão, não por descuido. Usuário no celular, em campo, sob sol, faixa etária ampla. Alvo de toque mínimo 44 px. Contraste mínimo AA.

## 11. Critérios de aceite

1. Todos os golden tests da seção 4 passam. Cobertura de `motor.js` em 100%.
2. Alterar um valor de custo atualiza os dois números de equilíbrio sem requisição ao servidor.
3. Salvar cenário e recarregar reproduz exatamente os mesmos números.
4. `SELECT` em `lavoura_custo` com credencial comercial retorna erro de permissão do banco.
5. `agg_custo_regional` não contém nenhuma linha com `qtd_produtores < 5`.
6. Nenhuma string da interface contém verbo imperativo de decisão comercial. Verificável por teste de lint sobre a lista de termos proibidos da seção 8.
7. Preço travado abaixo do ponto de equilíbrio produz veredito da classe "abaixo do equilíbrio".
8. Percentual travado acima de 80% produz alerta de risco de entrega.
9. Funciona em viewport de 360 px.

## 12. Fora de escopo no v1

Integração com B3 ou corretora · execução de contrato dentro do portal · custo por operação agrícola realizada (isto é caderno de campo, escopo do Aegro, não nosso) · rateio de custo entre talhões · projeção de fluxo de caixa da propriedade · importação SEFAZ.

## 13. Ordem de implementação

| PR | Entrega |
|---|---|
| 1 | Migrations + firewall no banco + usuário de aplicação separado + teste de permissão |
| 2 | `src/core/custo/motor.js` **com os golden tests escritos primeiro** |
| 3 | Seed do `cat_item_custo` e dos presets de soja e milho |
| 4 | Endpoints de lavoura e custos |
| 5 | `SetupLavoura` + `TabelaCusto` + `PontoEquilibrio` |
| 6 | `SimuladorTravamento` + `BarraCobertura` |
| 7 | `MatrizCenarios` |
| 8 | `ref_mercado` + job de cotação + `ReferenciaMercado` |
| 9 | Salvar cenário com recálculo no servidor |
| 10 | Job de agregação com k-anonimato → `agg_custo_regional` |

PR 2 antes de PR 4: o motor existe e está testado antes de qualquer coisa depender dele.

## 14. Piloto

15 a 20 produtores do programa de sucessão, uma cultura, uma safra. Critério de sucesso a definir com a Diretoria antes de abrir — sugestão: metade dos participantes cria pelo menos um cenário e retorna ao portal em uma segunda sessão sem estímulo.

Não abrir para a base sem esse resultado.
