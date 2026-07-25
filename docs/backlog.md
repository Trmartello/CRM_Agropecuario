# Backlog — ideias a especificar

Itens levantados mas ainda **sem spec**. Cada um vira um `docs/specs/<nome>.md`
antes de qualquer código (ver `docs/INVARIANTES.md` › "Como trabalhar comigo").
A ordem aqui não é prioridade — é só a lista.

## Portal do Produtor

- **Benchmark de custo e produtividade** — comparar custo (R$/ha) e produtividade
  (sc/ha) entre fazendas e por cultura, contra o custo do próprio produtor.
  ⚠ Depende do dado sob firewall (`lavoura_custo`/`lavoura_cenario`): só pode ser
  exibido de forma **agregada e anonimizada** (`agg_custo_regional`, k-anonimato
  ≥ 5 produtores por bucket — invariante 5). Nunca comparar contra produtor
  identificável.

- **Giro do Agro** — leitura diária do mercado, em **texto e áudio** (boletim
  curto). ⚠ Descritivo, nunca prescritivo (invariante 6): informa preço/contexto,
  não recomenda comprar, vender, travar ou aguardar; CEPEA/B3 sempre à vista.

- **Trilhas de educação** — conteúdo formativo em trilhas: como ler o mercado,
  comercialização, custos. Complementa o módulo de custo (dá repertório para o
  produtor interpretar o próprio ponto de equilíbrio). Mesmo limite do invariante 6.

- **Calculadoras do dia a dia** — utilidades de campo, cálculo local (funções
  puras, sem dado da Copérdia): calda de pulverização, desconto de colheita
  (umidade/impureza), conversões (sc↔kg, ha↔alqueire etc.) e calagem
  (necessidade de calcário). Candidatas a `src/core/` compartilhado.

## CRM interno / operação

- **Parcelas pendentes** — visão das parcelas em aberto do produtor (a definir
  origem: ERP/Qlik). ⚠ Se envolver valor financeiro/inadimplência, o número vem
  do Qlik (`SCORE_QLIK`), não é recalculado aqui (invariante 1).
