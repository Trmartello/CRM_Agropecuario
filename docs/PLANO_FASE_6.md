# Plano — Fase 6: Assistência Técnica de Precisão e Gestão

Cinco frentes definidas com o usuário em 20/07/2026. Desenvolvimento uma a uma,
validando antes de avançar (padrão do projeto). Schema atual: v16.

---

## 6A. Croqui das propriedades (mapa de talhões)

**Objetivo**: desenhar no mapa o contorno de cada talhão da propriedade,
gerando um croqui visual da fazenda com áreas calculadas por GPS.

**Como (respeitando a regra "sem tiles/CDN externos")**:
- Mesmo motor do Mapa atual: desenho por coordenadas em SVG/canvas, sem
  imagem de satélite. (Satélite como evolução futura, se a TI liberar um
  provedor de tiles — não bloqueia nada.)
- **Dois modos de marcação** no modal de croqui (tela cheia):
  1. **Caminhar a divisa**: o técnico anda pelo perímetro e o app captura um
     ponto GPS automaticamente a cada ~10 m (watchPosition, filtro de precisão).
  2. **Manual**: toque para adicionar vértice, arrastar para ajustar, desfazer.
- Fechou o polígono → **área calculada em ha** (fórmula do shoelace sobre
  projeção local) comparada com a área declarada do talhão; botão "usar área
  calculada".
- **Croqui da propriedade**: SVG com todos os talhões coloridos + rótulos
  (nome, cultura, ha), rosa dos ventos e escala. Aparece na ficha do produtor
  (aba Propriedades), é imprimível e entra no relatório de safra (6B).
- Offline: captura funciona sem sinal (GPS não depende de internet); o save
  entra na fila (`enviarFormOffline`).

**Schema (v17)**: `talhoes` + `contorno TEXT` (JSON `[[lat,lng],...]`),
`area_gps DECIMAL(10,2)`.

**Arquivos**: `CroquiService` (área/validação), rota `clientes/salvar-croqui`,
partial `croqui_modal.php`, desenho em `app.js` (reusa helpers do Mapa).

---

## 6B. Relatório de fechamento de safra

**Objetivo**: documento por produtor + safra para a reunião de fim de safra —
o fechamento de contas técnico e comercial com o produtor.

**Conteúdo (seções)**:
1. Capa: produtor, propriedades, safra, consultor responsável.
2. Áreas plantadas por cultura (planos de safra + plantios reais do 6E).
3. Assistência prestada: nº de visitas, distribuição por mês, principais
   recomendações e checklists aplicados (6E), fotos-chave.
4. Comercial: compras por família × potencial (aproveitamento %), pedidos da
   safra, pacotes, entregas futuras pendentes, comparativo com a safra anterior.
5. Reclamações abertas/encerradas e desfecho.
6. **Produtividade por talhão** (sc/ha registrada na colheita — 6E) × média
   da carteira, quando houver.
7. Croqui da propriedade (6A), quando houver.

**Como**: rota `relatorios/safra?cliente=&safra=` — página imprimível no padrão
do roteiro do dia (PDF via imprimir). Botões na ficha do produtor e no
Gerencial. `RelatorioSafraService` reusa Comercial/Potencial/Priorização.
Sem mudança de schema própria (consome 6A/6E). Perfis: consultor vê a própria
carteira; gestores tudo; **Produtor vê o próprio relatório no Portal**.

---

## 6C. Segmentação de clientes

**Objetivo**: classificar automaticamente a carteira para orientar abordagem,
frequência de visita e ação comercial.

**Modelo (RFV agro, calculado em lote como o churn)**:
- Eixos: volume de compras 12m (curva ABC dentro da carteira), recência da
  última compra, aproveitamento do potencial, risco de churn.
- **Segmentos**: `A — Parceiro` (alto volume, ativo), `B — Crescimento`
  (potencial alto, aproveitamento baixo), `C — Ocasional` (compra esporádica),
  `D — Em risco` (churn/inativo), `Prospect` (nunca comprou).
- Cortes/pesos em constantes no `SegmentacaoService` (calibráveis no piloto).
- **Override manual** pelo gestor (campo separado, não é sobrescrito pelo
  cálculo).

**Onde aparece**: badge colorido em Produtores, Priorização e ficha; filtro
por segmento nas listagens; distribuição da carteira (gráfico) no Gerencial;
segmento entra como fator leve no score de priorização.

**Schema (v17)**: `clientes` + `segmento VARCHAR(20)` (cache do cálculo,
atualizado em lote no login) e `segmento_manual VARCHAR(20)`.

---

## 6D. Análise de desempenho da equipe (área administrativa)

**Objetivo**: gestores acompanham produtividade e efetividade de cada
vendedor/técnico.

**Indicadores por usuário e período (mês/safra)**:
- Visitas: total, finalizadas ×  incompletas, produtores distintos,
  **cobertura da carteira** (% de clientes visitados no período).
- **Efetividade de fechamento**: % de visitas que geraram pedido do mesmo
  cliente em até N dias (constante `DIAS_CONVERSAO`, padrão 15 — calibrável).
- Vendas: pedidos, valor total, ticket médio; funil (oportunidades
  ganhas/perdidas, motivo de perda).
- Custo: km rodado, despesas, **custo por visita**.
- Evolução mensal (Chart.js) + ranking da equipe.

**Como**: nova aba "Desempenho" no Gerencial (Administrador/Gestores;
consultor vê só os próprios números). `DesempenhoService` com consultas em
lote (padrão `quedaCompraLote`). **Sem mudança de schema** — só leitura das
tabelas existentes.

---

## 6E. Linha do tempo da cultura (plantio → fenologia → manejo → checklist)

**Objetivo**: ao informar a data de plantio do talhão, o sistema posiciona a
lavoura na fase fenológica, indica os manejos da fase e dá ao técnico um
checklist de vistoria — assistência técnica padronizada e proativa.

**Schema (v17)**:
- `plantios`: talhao_id, cultura_id, safra_id, **data_plantio**, cultivar,
  encerrado, **produtividade (sc/ha)** e colhido_em (preenchidos no
  encerramento → alimentam o relatório 6B). Um plantio ativo por talhão/safra.
- `fenologia_estagios`: cultura_id, codigo (VE, V4, R1…), nome, dias_inicio,
  dias_fim (DAP — dias após plantio), descricao, ordem. **Seed: soja (escala
  Fehr) e milho** — demais culturas cadastráveis.
- `manejos_fase`: cultura_id, estagio_id, titulo, familia_id (gatilho
  comercial), orientacao, eh_checklist. Seed com manejos típicos por fase.
- `visita_checklist`: visita_id, manejo_id, situacao (`OK`/`Atenção`/
  `Crítico`/`N/A`), observacao.

**UX**:
- Data de plantio informada no talhão (ficha) ou direto no modal de visita ao
  selecionar o talhão pela primeira vez na safra.
- No modal de visita, talhão com plantio ativo mostra a **linha do tempo**:
  barra horizontal com os estágios, marcador "hoje" pela idade da lavoura
  (DAP), fase atual estimada destacada — e pré-preenche `estagio_cultura`.
- Nova etapa **"Checklist da lavoura"** no wizard da visita: itens da fase
  atual com OK/Atenção/Crítico/N.A. + observação por item (entra na
  completude? NÃO — permanece opcional, não muda os campos obrigatórios).
- Ficha do produtor: timeline da cultura na aba do talhão.
- **Gatilhos**: lavoura entrando em fase com manejo crítico de uma família
  sem compra na safra → alerta/oportunidade (reusa o motor do calendário
  agronômico e do funil).
- Offline: fenologia/manejos entram no snapshot da carteira; checklist entra
  na fila como o resto da visita.

---

## Ordem sugerida (valor × dependência)

| # | Frente | Esforço | Status |
|---|--------|---------|--------|
| 1º | 6C Segmentação | Pequeno | ✅ Entregue (schema v17) |
| 2º | 6D Desempenho | Pequeno/médio | ✅ Entregue |
| 3º | 6E Linha do tempo | Grande | ✅ Entregue (schema v18) |
| 4º | 6A Croqui | Médio | ✅ Entregue (schema v22) |
| 5º | 6B Relatório de safra | Médio | ✅ Entregue — **Fase 6 completa** |

Cada frente: implementação → migração de schema → smoke/screenshot →
validação com o usuário antes da próxima.
