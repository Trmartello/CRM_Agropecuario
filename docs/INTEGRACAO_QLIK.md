# Integração com o Qlik Cloud da Copérdia (ERP/CAPE reais)

Levantamento feito em 21/07/2026 direto no tenant `coperdia.br.qlikcloud.com`
(conector Qlik autorizado). Este documento mapeia **onde estão os dados reais**
que a camada de integração da Fase 5 (`IntegracaoService`, fonte configurável
Local/ERP/CAPE) espera, e o que falta para ligar o adaptador de produção.

## Apps relevantes encontrados

| App | Espaço | ID | Interesse para o CRM |
|---|---|---|---|
| **CAP - Copérdia Alta Performance** | Vendedores (managed) | `28ca2841-1ad3-48e2-ab0d-3ab723c29666` | Metas e realizado oficiais do CAP por vendedor |
| **Comercial Global** | Filiais (managed) | `4aed35d9-bc8c-42dd-a5d7-ea13925a53b9` | Clientes, vendas (fato), produtos/famílias, vendedores, técnicos, carteira, filiais |
| Gestão Comercial | Filiais | `c70cb887-1565-4917-8fe2-313e289c5bf2` | Visões gerenciais de venda |
| Venda Entrega Futura | Desenvolvimento | `403d7692-f355-4f19-b686-0feba5fb4ea1` | Entregas futuras contratadas |
| Análise Oportunidade | Filiais | `8d145d9e-1b13-48df-a57c-b6a9c712f8b9` | Oportunidades comerciais |
| Compradores / Compacto / Auditoria / DRE… | Filiais | — | Fora do escopo do CRM por ora |

Os espaços **managed** (Filiais, Vendedores) são os de produção; os apps
homônimos no espaço Desenvolvimento são as versões de trabalho.

## App CAP — o que o `CapService` espera vs. o que existe

Tabelas do modelo (`qlik_get_fields`):

- **`indicadores_cap`**: `Código Indicador`, `Indicador`, `Pontuação`,
  `Multiplo`, `Tipo Indicador`, `FlagComercial`, `FlagInativo`.
  Os 18 indicadores reais: BIOLOGICOS (R$), DEFENSIVOS (R$), DESPESAS
  OPERACIONAIS (%), FERRAGENS (R$), FERT. FOLIARES (R$), FERT. FORMULADOS E
  NITROGENADOS (TON), INADIMPLENCIA (%), MARGEM BRUTA (%), MARKET SHARE (TON),
  MEDICAMENTOS (R$), NUTRICAO ANIMAL (TON), PRAZO MEDIO DE VENDAS (DIAS),
  RACOES E CONCENTRADOS (TON), RESULTADO LIQUIDO (R$), SEMENTE DE MILHO (TON),
  SEMENTE DE SOJA (TON), SEMENTE DE TRIGO (TON), SEMENTE PASTAGENS (TON).
- **`fato`**: `Valor Meta Vendedor`, `Valor Realizado Vendedor`,
  `Pontos Vendedor` (e as mesmas medidas por Filial/Regional/Copérdia).
- **`resumo_anual_vendedor`**: `Percentual Atingimento Anual Vendedor`,
  `Pontuação Objetivo/Máxima`.
- **`vendedores`**: `Cód Vendedor`, `Vendedor`, `CPF Vendedor`,
  `FlagParticipaCAP`, `Situação do Vendedor`.
- **`Lista_Vendedores_Interface`**: **`Vendedor.Email`** — chave natural para
  vincular ao `usuarios.email` do CRM.
- **`calendario`**: Mês/Ano etc.

Mapeamento direto para as tabelas locais:

| CRM (local) | Qlik CAP |
|---|---|
| `metas_cap.indicador` | `Indicador` |
| `metas_cap.meta` | `Valor Meta Vendedor` (por Mês/Ano) |
| `realizado_cap.realizado` | `Valor Realizado Vendedor` |
| vínculo vendedor | `Vendedor.Email` → `usuarios.email` (fallback: `CPF Vendedor`) |

## App Comercial Global — clientes, vendas e famílias

Tabelas principais: `cliente_fornecedor` (75 campos), `fato` (72),
`produtos` (42), `categorias` (18: Família/Grupo/Linha/Nível),
`vendedores`, `tecnicos`, `carteira`, `filiais`, `municipios`, `calendario`.

Campos úteis já confirmados em `cliente_fornecedor`:
`Cód. Cliente/Fornecedor`, `Cliente/Fornecedor`, `CPF/CNPJ`, endereço/bairro/CEP,
`Celular`, `E-MAIL`, `Crédito Cliente/Fornecedor`, `Código Técnico Responsável`,
`Código Segmento`, `Dap`, `Data da Última Venda` (fato), atividade/atividade
direcionadora, filial de cadastro/acerto.

| CRM (local) | Qlik Comercial Global |
|---|---|
| `clientes` (cadastro, crédito, responsável) | `cliente_fornecedor` |
| `compras` (realizado por família/safra) | `fato` × `produtos` × `categorias.Família` |
| `familias_produto` | `categorias.Família` |
| `titulos_financeiros` (inadimplência) | medidas de título no `fato` (`Valor Título`, `Valor Total Titulo`) — confirmar granularidade com a TI |
| `filiais` | `filiais` |
| entregas futuras | app **Venda Entrega Futura** |

## Como ligar o adaptador de produção (pendências)

O app PHP no Railway **não** acessa o conector MCP — ele precisa falar com a
API REST do Qlik Cloud diretamente. Falta apenas:

1. **API key do tenant** (TI Copérdia): Qlik Cloud → Administração → chave de
   API com acesso de leitura aos espaços Filiais e Vendedores. Guardar em
   variável de ambiente `QLIK_API_KEY` no Railway (+ `QLIK_TENANT=coperdia.br.qlikcloud.com`).
2. **Endpoint**: a leitura é feita por avaliação de hypercube
   (`POST /api/v1/apps/{appId}/evaluate` ou Engine REST/JSON-RPC) usando os
   campos acima como dimensões/medidas — o `IntegracaoService` ganha um
   `QlikAdapter` e as telas não mudam (interface estável da Fase 5).
3. **Decidir a cadência**: sincronização no login do gestor (como a
   segmentação, máx. 1x/12h) gravando nas tabelas locais (`compras`,
   `metas_cap`, `realizado_cap`, `titulos_financeiros`) — o app continua
   funcionando offline com a última carga.
4. **Chaves de vínculo**: `usuarios.email` ↔ `Vendedor.Email` (CAP) e
   `clientes.cpf_cnpj` ↔ `CPF/CNPJ Cliente/Fornecedor` (Comercial Global) —
   conferir formato (máscara) na primeira carga.

> Alternativa sem API key: exportação periódica agendada no Qlik (Automations)
> para um endpoint do CRM (`integracao/receber`, autenticado por token). Menos
> acoplada, porém depende de manutenção no lado Qlik.

## Carga inicial do CAP (implementada — schema v26)

Enquanto a API key não sai, a carga inicial funciona por **arquivo**:

1. Os dados anuais (meta/realizado por vendedor × indicador) foram extraídos do
   app CAP pelo conector (folha "Exportação CRM (temporária)" criada no app do
   espaço **Desenvolvimento** — pode ser apagada depois) e viram um JSON
   `{ tipo: "cap_anual", ano, vendedores: [ { cod, nome, cpf, indicadores:
   [ { codigo, indicador, meta, realizado } ] } ] }`.
2. No CRM: **Usuários** ganhou o campo **Cód. vendedor (ERP/CAP)**
   (`usuarios.cod_vendedor`, migração v26) — é ele que liga o vendedor do
   Qlik ao usuário do CRM (o e-mail não existe associado no modelo Qlik).
3. **Integração → "Carga inicial do CAP (arquivo do Qlik)"** (Administrador):
   upload do JSON → `IntegracaoService::importarCargaCap` substitui as metas
   do ano (`metas_cap` com período 01/01–31/12 + `realizado_cap` com
   `data_ref` = dia da carga; unidade deduzida do sufixo do indicador).
   Vendedores sem `cod_vendedor` correspondente são listados no resultado —
   preencher o código no cadastro e reimportar (idempotente).
4. O log aparece no histórico de sincronização (fonte CAPE, entidade metas_cap).
