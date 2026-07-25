# CRM Agropecuário — Copérdia

## O que este sistema é

Duas aplicações sobre um modelo de dados e uma biblioteca de cálculo comuns.

**CRM interno** — usuários: RTV, gerência regional, Controladoria.
Território, carteira, visitas, oportunidade comercial.

**Portal do Produtor** — usuário: o cooperado.
Custo de lavoura, ponto de equilíbrio, simulação de venda antecipada,
solicitação de visita técnica, monitoria de lavoura.

Não é um sistema de gestão de lavoura no modelo Aegro. Não construímos caderno
de campo, controle de estoque do produtor nem gestão de maquinário.

Tese comercial: vender por **potencial do produtor**, não por histórico de compra.
Tese do portal: dar ao produtor visibilidade sobre a própria margem. É a
contrapartida que justifica a confiança e o dado.

## Invariantes — nunca violar

1. **Duas classes de número, duas regras.**

   *Indicador da Copérdia* — share, gap, potencial, margem, faturamento, EVA:
   calculado **no Qlik**, consumido via `SCORE_QLIK`. Nunca reimplementar a
   fórmula aqui. Se o número não existe lá, peça a medida no Qlik.

   *Cálculo do produtor sobre dados que o próprio produtor digitou* — custo,
   ponto de equilíbrio, cobertura de travamento: calculado em
   `src/core/custo/motor.js`, funções puras, testadas, versionadas. Não é dado
   da Copérdia, não entra no DRE, e precisa responder em tempo real.

   Na dúvida sobre qual regra se aplica: se o número descreve a Copérdia, é Qlik.
   Se descreve a lavoura do cooperado, é motor local.

2. **CAR identifica área, não pessoa.** A relação imóvel × produtor é N:M por
   causa de condomínio e posse localizada. Nunca modelar como 1:1, nunca usar
   CAR como chave de cliente.

3. **Geometria é herdada, não desenhada.** Polígonos vêm do SICAR. Não construir
   ferramenta de desenho de talhão.

4. **Dado de produtor é sensível.** CPF, CNPJ, coordenada de propriedade e
   faturamento individual nunca aparecem em log, URL, query string ou mensagem
   de erro.

5. **Firewall do dado do cooperado — por perfil.** As tabelas `lavoura_custo`,
   `lavoura_cenario`, o campo `resultado_json` e as tabelas de NF do produtor
   (`nfe_documento`, `nfe_item`, `produtor_autorizacao_fiscal`) são **inacessíveis
   aos perfis de campo** — Vendedor/RTV, Consultor Técnico e Gestor Técnico —
   sempre. Restrição no usuário de banco, não só na aplicação; para esses perfis a
   comparação de custo é só via `agg_custo_regional`, com mínimo de 5 produtores por
   bucket.

   Por **decisão de governança da Diretoria**, o **custo individual identificável**
   pode ser consultado por **Diretoria (Administrador), Controladoria (Analista) e
   Gestor Comercial**, por usuário de banco/rota próprios, com **auditoria
   obrigatória de todo acesso** e ciência do produtor obtida no opt-in. Detalhes em
   `docs/specs/nf-ingestao.md`, seção 8.

   Motivo: se o **RTV** souber o ponto de equilíbrio do cooperado, negocia com
   vantagem informacional sobre ele — por isso o campo nunca vê. A gestão vê para
   controladoria e estratégia, sob trilha de auditoria.

6. **A ferramenta é descritiva, nunca prescritiva.** Nada no Portal recomenda
   comprar, vender, travar ou aguardar. Nada afirma direção de preço. Nenhuma
   oferta da Copérdia aparece sem a referência CEPEA e o futuro B3 na mesma tela
   e com igual destaque. Lista de termos proibidos e teste de lint em
   `docs/specs/custo-lavoura.md`, seção 8.

   Motivo: a Copérdia é compradora da produção. Uma ferramenta que aconselha
   vender é instrumento de venda disfarçado, e o cooperado percebe.

7. **Código do Portal é território separado.** Frontend em `apps/portal/`, API
   em rotas `/portal/*`, autenticação de usuário externo. Nunca importar módulo
   do CRM interno no Portal nem o contrário. O compartilhado vive em `src/core/`.

   Motivo: superfície de ataque, LGPD e tolerância a falha são diferentes.

## Stack

React + Node.js + MySQL 8. Mapas: Leaflet via react-leaflet, renderer canvas.
Geometria em SRID 4326.

## Convenções

- Código, nomes de variável e commits em português.
- Migrations versionadas e reversíveis. Nunca alterar migration já mergeada.
- Nenhum segredo em código. `.env` fora do versionamento.
- Componentes por módulo em `src/modules/<nome>/`, não por tipo de arquivo.

## Como trabalhar comigo

- Antes de implementar, leia o spec correspondente em `docs/specs/`.
- Apresente o plano e espere aprovação antes de escrever código.
- Um PR por item da ordem de implementação do spec. Não adiantar etapas.
- Se o spec estiver ambíguo, pergunte. Não escolha por conta própria em
  regra de negócio.

## Rigor por criticidade

| Área | Exigência |
|---|---|
| `src/core/custo/` | teste escrito antes da implementação, cobertura 100%, funções puras |
| Endpoints do Portal | validação de propriedade do recurso em toda rota; servidor recalcula, nunca confia no cliente |
| CRM interno | teste em regra de negócio; UI sem exigência de cobertura |
| Scripts de ETL | relatório de carga obrigatório; sem descarte silencioso |

Motivo do primeiro item: um erro no ponto de equilíbrio faz um cooperado travar
preço abaixo do custo dele. É a única parte do sistema onde o defeito tem
consequência financeira para terceiro.

## Estado atual

Ver `docs/specs/` para as funcionalidades especificadas e o status de cada uma.
