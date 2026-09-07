# SPEC — Propriedade → Imóveis (CAR) → Área de plantio → Talhões por cultura e finalidade

**Projeto:** CRM Agropecuário Copérdia
**Local no repo:** `docs/specs/propriedade-imoveis-plantio.md`
**Status:** aprovado pelo dono do produto (06/09/2026) · schema v40 → v45 (várias áreas de plantio, talhão preso à área, croqui em 3 etapas)
**Decisões do usuário:** vários CARs por propriedade · finalidades editáveis em Configurações

---

## 1. Objetivo

Deixar claro, no cadastro e no mapa, **quanto de terra o produtor tem, quanto dela dá para plantar e o que está plantado em cada pedaço** — totalizando a área de plantio **por cultura e por finalidade** (milho silagem ≠ milho grão ≠ pastagem).

## 2. Hierarquia

```
Produtor (clientes)
 └─ Propriedade (propriedades)            "Morais 2", "Fazenda São José"
     └─ Imóvel rural / CAR (imoveis)      1 registro por inscrição no SICAR
         ├─ divisa oficial (contorno)     área total do CAR
         └─ Áreas de plantio (areas_plantio)   o que dá para plantar (fora mata/APP/reserva/sede), N por imóvel
             └─ Talhões (talhoes.imovel_id + area_plantio_id)   dentro de UMA área, cultura + cultivar + finalidade; lançados na aba "Talhões" (toda a área ou delimitado no croqui)
                 └─ Plantios por safra (plantios.finalidade_id guarda o histórico)
```

- **Duas telas (v48, pedido do teste de campo 07/09/2026: "deixar essa tela apenas de cadastro da propriedade e fazer uma tela para lançar os talhões de cada área")**: a aba **Propriedades** é só o **cadastro da terra** (propriedade → imóveis/CAR → divisa, áreas de plantio e de não plantio pelo croqui; sem botões de talhão) e a aba **Talhões** (logo depois) é onde se lança **o que está plantado em cada área**.

- Uma propriedade pode ter **N imóveis (CARs)**. Cada imóvel tem a própria divisa, a própria área de plantio e os próprios talhões.
- `talhoes.propriedade_id` **continua existindo** (tudo que já consulta talhão por propriedade segue igual); `talhoes.imovel_id` é o vínculo novo. Regra: o imóvel do talhão pertence à mesma propriedade.
- `propriedades.car_numero / contorno / area_gps` viram **legado**: a migração copia para o primeiro imóvel e o código passa a ler/gravar em `imoveis`. As colunas ficam no schema por segurança, sem uso.

## 3. Áreas de plantio (v44: várias por imóvel)

- **Várias por imóvel** (pedido do teste de campo, 06/09/2026): tabela `areas_plantio` (`imovel_id`, `nome`, `contorno`, `area_gps`, `ordem`) — cada área é um polígono com nome (Campo, Morro…) desenhado no croqui (alvo **"Nova área de plantio"**; cada área gravada vira um alvo próprio no seletor; verde tracejado com o nome quando há mais de uma). `imoveis.contorno_plantio`/`area_plantio_gps` são **legado** (a migração v44 copia a área única para a 1ª linha e zera as colunas); `area_plantio_ha` digitada só vale para imóvel sem área desenhada.
- Regras: cada área fica **dentro da divisa** (ponto fora é preso na borda; nenhuma linha fora do CAR — **linha que sai é corrigida sozinha**: o trecho de fora vira o caminho da própria borda, no cliente ao salvar e sempre no servidor, `CroquiService::margearDivisa`/`caminhoDentro`; trecho que ainda sair ganha pontos criados sozinhos — `dividirNaBorda`, meio preso na borda e metades tratadas de novo; só se nada fechar o salvar é recusado apontando a linha) e **uma área não cobre outra** (mesma regra dos talhões: ponto dentro da vizinha é puxado para a borda; cruzamento recusa; desenho igual recusa). A divisa nova não deixa nenhuma área para fora (bloqueio).
- **Área de plantio do imóvel = soma das áreas** (`AreaPlantioService`).
- **Uso da área (v47, pedido do teste de campo 07/09/2026: "essa área é reflorestamento; precisamos marcar reflorestamento e culturas perenes, nem sempre é área de plantio")**: `areas_plantio.uso` ∈ `lavoura` (anual, talhões por safra — padrão) | `perene` (maçã, uva, erva-mate) | `reflorestamento` (pinus, eucalipto) e `areas_plantio.cultura_id` (só perene/reflorestamento; FK `culturas`, `ON DELETE SET NULL`). Escolhido no croqui num select ao lado do alvo (etapa 2; área já gravada salva na hora por `renomear-area-plantio`, que aceita `uso`/`cultura_id`). **"Cultivado" = soma dos três usos** (líquidos, já sem o não plantio); `resumo.por_uso` traz a quebra. Área perene/reflorestamento **sem talhão entra inteira como um grupo** da barra (cultura da área × rótulo do uso, ex. "Maçã · Cultura perene") em vez de contar como "sem talhão"; talhões/quadras continuam opcionais dentro de qualquer área. Ficha: "cultivado X ha (lavoura a · perene b · reflorestamento c)", badges com ícone do uso e cultura, "Próximo passo 3/3" só quando há lavoura sem talhão; croqui e mini-croqui pintam por uso (verde/âmbar/verde-escuro) com a cultura no rótulo. Seed: culturas Maçã, Uva, Erva-mate, Pinus e Eucalipto (migração insere as que faltam).
- **Varinha mágica (pedido do teste de campo 07/09/2026: "tocar na mancha mais verde e marcar como APP")**: botão 🪄 na etapa 2; com ela ligada, o toque no meio de uma mancha (mato, açude, sede) lê a imagem de satélite da vista (tiles via cache CORS do "Baixar mapa" ou `fetch` com CORS), cresce a seleção pelos pixels de cor parecida (limitada à divisa; controle "Sensibilidade"), traça e simplifica a borda e abre o polígono como **nova área de não plantio** para escolher o tipo, ajustar e salvar. Exige divisa salva e zoom ≥ 15; sem imagem legível (provedor sem CORS, sem sinal e sem mapa baixado) o app avisa e o desenho segue à mão. Só no cliente — nada muda no servidor.
- **Áreas de NÃO plantio (v46, pedido do teste de campo 07/09/2026: "a meia-lua de mato dentro da área de plantio deveria ser mapeada como área de não plantio")**: tabela `areas_nao_plantio` (`imovel_id`, `nome`, `tipo` ∈ mata|app|acude|sede|estrada|outro, `contorno`, `area_gps`, `ordem`). São **buracos** dentro da divisa, desenhados na etapa 2 do croqui (grupo "Não plantio" no seletor, com o tipo num select ao lado): ficam dentro da divisa (prende/margeia/nenhuma linha fora), **uma não cobre outra**, mas **podem ficar dentro de área de plantio e de talhão** — não é preciso contornar o mato com a linha. **Área líquida** = medida − Σ interseções com as exclusões (`CroquiService::areaIntersecaoHa`: caixas separadas → 0; um polígono todo dentro do outro → a área dele; parcial → amostragem em grade ≈ 4000 pontos, espelho `Croqui._areaIntersecaoHa`): a área de plantio do imóvel soma as áreas **líquidas** (`resumo.areas[].area_liquida`/`desconto`), `talhoes.area_ha` passa a ser a **líquida** do talhão (`AreaPlantioService::sincronizarLiquidas`, chamado ao salvar talhão/exclusão e em "Área toda"; `area_gps` continua a bruta) e `resumo.nao_plantio`/`nao_plantio_tipos`/`exclusoes` alimentam a ficha ("Não plantio: X ha (Mata a, Açude b)", badges por exclusão), o mini-croqui e o relatório de safra (hachura). A divisa nova não deixa exclusão para fora (bloqueio). Sem plantio desenhado, a área de plantio "= total" desconta o não plantio.
- **Camadas ambientais do CAR (v49, pedido do teste de campo 07/09/2026: "faça a importação das camadas ambientais")**: o zip individual do SICAR traz, além de `Area_do_Imovel`, um zip por tema — `Area_de_Preservacao_Permanente`, `Reserva_Legal`, `Cobertura_do_Solo` (Remanescente de Vegetação Nativa + Área Consolidada), `Servidao_Administrativa`, `Hidrografia`. O botão **CAR** da ficha abre um modal (arquivo + duas opções) e `ShapefileService::camadasAmbientais` lê cada camada (só anéis **externos** — os buracos da Área Consolidada são as ilhas de mata; quando há o registro "Total" de um tema, só ele entra, pois repete os parciais) e o servidor grava cada parte como **área de não plantio de origem `car`** (`areas_nao_plantio.origem`/`tema`; nome "APP (CAR) 3/9"): APP → `app`, Reserva Legal → `reserva` (tipo novo), Vegetação nativa → `mata`, Servidão → `estrada`, Hidrografia → `acude`. Reimportar **substitui só as de origem `car`** (as desenhadas à mão ficam). As camadas **se sobrepõem** (a APP fica dentro da vegetação nativa, a reserva também): elas ficam **fora da regra "uma não cobre outra"** (servidor e cliente) e o **desconto passa a ser pela UNIÃO** — `CroquiService::mascaraUniao` rasteriza a união numa grade (~30 mil células, passo 1–30 m) e `descontoMascara` conta as células dentro de cada polígono (`AreaPlantioService::descontoNaoPlantio` só usa a máscara quando alguma caixa cobre outra; desenhos que não se tocam seguem no cálculo exato); espelho `Croqui._mascaraUniao/_descontoMascara`. Caches gravados por `sincronizarLiquidas`: `areas_plantio.area_liquida` e `imoveis.nao_plantio_ha` (a ficha lê o número pronto; `resumo.nao_plantio_sobreposto` avisa "camadas se sobrepõem — união X ha"). Cada parte é presa na divisa recém-gravada (sem o recorte de linhas — aresta coincidente com a borda dava falso "linha fora"; camada do CAR também fica isenta desse bloqueio ao editar/salvar e de prender a divisa ao ajustá-la); parte com vértice a mais de 15 m fora ou menor que 0,02 ha é ignorada. Opcional: **Área Consolidada → áreas de plantio** ("Área consolidada N (CAR)", uso lavoura, partes ≥ 0,5 ha), só em imóvel ainda sem áreas. Na ficha as camadas aparecem agrupadas por tipo ("Vegetação nativa (CAR) · 17 partes · 308,6 ha"). Teste com o zip real de Fraiburgo: 837 ha, união 314,6 ha, plantio líquido 514,7 ha (o `.dbf` do CAR diz 517,5).
- **Talhão dentro de UMA área de plantio (v45 — bloqueio, pedido do teste de campo 06/09/2026)**: `talhoes.area_plantio_id` (FK `areas_plantio`, `ON DELETE SET NULL`) guarda a área hospedeira. O limite do talhão passa a ser a **área de plantio**, não a divisa: ponto fora da área é preso na borda dela e a reta é margeada pela própria borda (`prenderNaDivisa`/`margearDivisa` com o contorno da área), linha fora da área é recusada (`exigirLinhasDentro` com rótulo da área), talhão sem nenhuma área que contenha seus vértices é **recusado** ("desenhe dentro de uma das áreas verdes"), e imóvel sem áreas de plantio recusa talhão ("marque primeiro as áreas de plantio — etapa 2"). A área hospedeira é a que contém mais vértices (`AreaPlantioService::areaHospedeira`, empate → a área atual do talhão). Uma área de plantio **não pode encolher deixando talhão hospedado para fora** nem ser excluída com talhões dentro (bloqueio); talhões legados sem vínculo fora de todas as áreas viram só aviso e aparecem na ficha em "Talhões fora das áreas de plantio (ajuste no croqui)". A migração v45 vincula os talhões existentes pela área que os contém.
- **Croqui guiado em 3 etapas (v45)**: 1 **Divisa** (traz o CAR e ajusta os pontos; único alvo, grupo do CAR visível) → 2 **Áreas de plantio** (seletor só com as áreas + "Nova área"; limite = divisa SALVA) → 3 **Talhões** (seletor só com talhões + "Novo talhão"; limite = área hospedeira, desenhada em laranja como limite, divisa apagada ao fundo). A etapa 2 só abre com divisa salva e a 3 só com ao menos uma área; o croqui abre na primeira etapa pendente e a ficha mostra "Próximo passo (n/3)" por imóvel. Os talhões na ficha ficam agrupados pela área de plantio.
- Renomear pelo croqui ("Renomear"); excluir = Limpar + Salvar na área. **Talhão cadastrado errado → "Virar área de plantio"** cria uma área nova com o nome do talhão (não substitui as outras); **"Copiar de talhão"** carrega o desenho de um talhão numa área nova para ajustar.
- **Aba "Talhões" (v48)**: lista propriedade → imóvel → **cada área de plantio** (ícone do uso, cultura, área líquida, "com talhão X ha"/"sem talhão") com dois botões por área: **"Toda a área"** (padrão — um talhão cobrindo a área inteira, `plantar-area-toda` com `area_plantio_id`; só aparece enquanto a área não tem talhão, e o servidor recusa repetir) e **"Delimitar"** (abre o croqui na etapa 3 já com a área escolhida como limite — `Croqui.abrir(imovelId, {novoTalhao:true, areaId})`; para mais de uma cultura na mesma área). Nos dois caminhos o modal do talhão pede **nome, cultura, cultivar/híbrido e finalidade (objetivo)** — cultura e finalidade vêm sugeridas pelo uso da área (perene → cultura da área + "Perene"; reflorestamento → "Reflorestamento"). Os talhões aparecem embaixo da própria área (cultura · cultivar · finalidade, plantio/colheita, editar). O "Plantar a área toda" do imóvel inteiro (um talhão por área) continua no servidor sem `area_plantio_id`, mas a tela não o oferece mais.

## 4. Finalidades

- Tabela `finalidades` (nome, ativo, ordem), editável em **Configurações → Finalidades de cultura**. Seed: Grão · Silagem · Pastagem · Feno/Pré-secado · Semente · **Perene · Reflorestamento** (v48 — a migração insere as que faltam).
- `talhoes.finalidade_id` = uso atual; `plantios.finalidade_id` = histórico por safra (registrar plantio copia para o talhão, como já acontece com a cultura).
- **Cultivar/híbrido (v48)**: `talhoes.cultivar` (texto livre, 80) — informado ao lançar o talhão (aba Talhões) e sugerido ao registrar plantio (`plantios.cultivar` já existia).

## 5. Totalização (`AreaPlantioService`, só leitura)

Por imóvel → por propriedade → por produtor:

| Medida | Origem |
|---|---|
| Área total | `area_gps` (divisa) ou `area_ha` |
| Área de plantio | soma das `areas_plantio.area_gps` (v44); sem área desenhada, `area_plantio_gps`/`area_plantio_ha` legado; se zero, cai na área total |
| Por cultura × finalidade | soma dos talhões (`area_gps` ou `area_ha`) |
| Não mapeado | área de plantio − soma dos talhões (quando > 0) |
| Excedente | soma dos talhões − área de plantio (quando > 0) → alerta |

Exibido na ficha: a aba **Propriedades** mostra o cadastro da terra (totais de área, plantio e não plantio por imóvel; contagem de talhões) e a aba **Talhões** (v48) mostra a barra por cultura/finalidade com %, o consolidado do produtor no topo e cada área com os seus talhões.

## 6. O que NÃO muda

- Visitas, fenologia, custo da lavoura, relatório de safra e snapshot offline continuam ligando talhão → propriedade.
- Mapa Territorial (`dim_imovel`) é outra base (CAR do município) e não é afetado.

## 7. Migração v40 (bancos já instalados)

1. `CREATE TABLE IF NOT EXISTS imoveis`, `finalidades`; colunas `talhoes.imovel_id`, `talhoes.finalidade_id`, `plantios.finalidade_id`.
2. Seed de finalidades se a tabela estiver vazia.
3. Para cada propriedade sem imóvel: cria 1 imóvel copiando `car_numero/contorno/area_gps/area_ha/municipio`.
4. Talhões sem `imovel_id` recebem o primeiro imóvel da própria propriedade.

Idempotente: pode rodar de novo sem duplicar.
