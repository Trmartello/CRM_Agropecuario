# SPEC — Módulo Mapa Territorial

**Projeto:** CRM Agropecuário Copérdia
**Local sugerido no repo:** `docs/specs/mapa-territorial.md`
**Status:** aprovado para implementação · piloto em 1 município
**Referência visual:** `docs/prototipos/mapa_territorial.html`

---

## 1. Objetivo

Dar à equipe comercial agro uma visão geográfica da área de influência da Copérdia, onde cada imóvel rural é um polígono e cada polígono carrega quatro números: potencial, realizado, share e gap.

**A pergunta que o módulo responde:** onde está a receita de insumos que existe no território e não passa por nós.

## 2. Tese do produto — leia antes de codar

Este módulo **não é** um clone do Aegro. O Aegro é um ERP de gestão agrícola cujo usuário é o produtor administrando a própria lavoura.

Aqui o usuário é o **RTV, o gerente regional e a Controladoria**. O objeto visual é parecido (polígono de propriedade), a finalidade é oposta.

Consequências práticas de projeto:

- Não construímos caderno de campo, controle de estoque do produtor, gestão de maquinário ou apontamento de operações agrícolas.
- Não pedimos que ninguém desenhe polígono. A geometria vem pronta do SICAR.
- A unidade de análise é o **imóvel rural**, não o talhão. Talhão é detalhe de ficha, não camada de mapa no v1.

## 3. Decisões técnicas — fechadas, não reabrir sem discussão

| Item | Decisão | Motivo |
|---|---|---|
| Banco | MySQL 8 com tipos espaciais nativos | já é o stack; `ST_Contains`, `ST_Distance_Sphere` e `SPATIAL INDEX` cobrem o v1 |
| SRID | 4326 em toda a base | evita reprojeção em runtime |
| Biblioteca de mapa | Leaflet via `react-leaflet` | maturidade e simplicidade; suficiente para ~5k polígonos |
| Renderer | `L.canvas()` (não SVG) | SVG degrada acima de ~1.500 polígonos |
| Tiles base | OpenStreetMap | gratuito |
| Tiles satélite | Esri World Imagery | gratuito, atribuição obrigatória no rodapé do mapa |
| Formato de transporte | GeoJSON `FeatureCollection` | nativo do Leaflet |

**Gatilho de migração para PostGIS:** se a base passar de ~50 mil imóveis ou se surgir necessidade de análise raster/NDVI. Até lá, MySQL. Não antecipar.

**Regra de performance inegociável:** o endpoint de mapa devolve geometria simplificada e apenas os campos usados para colorir e rotular. Detalhe completo só no endpoint de ficha, sob demanda.

## 4. Modelo de dados

Três tabelas novas. Nenhuma alteração destrutiva nas existentes.

```sql
-- Imóvel rural. PK é o código CAR.
CREATE TABLE dim_imovel (
  cod_car           VARCHAR(60)   NOT NULL PRIMARY KEY,
  nome_imovel       VARCHAR(160)  NULL,
  municipio         VARCHAR(80)   NOT NULL,
  cod_ibge          CHAR(7)       NOT NULL,
  uf                CHAR(2)       NOT NULL DEFAULT 'SC',
  area_ha           DECIMAL(12,4) NOT NULL,
  modulos_fiscais   DECIMAL(8,2)  NULL,
  tipo_imovel       VARCHAR(30)   NULL,   -- IRU / AST / PCT
  situacao_car      VARCHAR(30)   NULL,   -- AT / PE / SU / CA
  geom              GEOMETRY      NOT NULL SRID 4326,   -- geometria oficial
  geom_simpl        GEOMETRY      NOT NULL SRID 4326,   -- para renderizar
  centroide         POINT         NOT NULL SRID 4326,
  fonte             VARCHAR(20)   NOT NULL DEFAULT 'SICAR',
  dt_carga          DATETIME      NOT NULL,
  SPATIAL INDEX ix_geom (geom),
  INDEX ix_mun (cod_ibge)
);

-- Vínculo N:M. Obrigatório: condomínio e posse localizada geram
-- múltiplos produtores no mesmo CAR, e um produtor pode ter vários CAR.
CREATE TABLE bridge_imovel_produtor (
  id            BIGINT AUTO_INCREMENT PRIMARY KEY,
  cod_car       VARCHAR(60) NOT NULL,
  produtor_id   BIGINT      NOT NULL,
  papel         ENUM('proprietario','posseiro','arrendatario','parceiro') NOT NULL,
  principal     TINYINT(1)  NOT NULL DEFAULT 0,
  origem        ENUM('documento','gps_visita','informado','manual') NOT NULL,
  confianca     ENUM('alta','media','baixa') NOT NULL,
  dt_vinculo    DATETIME    NOT NULL,
  usuario_id    BIGINT      NULL,
  UNIQUE KEY uk_car_prod (cod_car, produtor_id),
  INDEX ix_prod (produtor_id),
  CONSTRAINT fk_bip_car  FOREIGN KEY (cod_car)     REFERENCES dim_imovel(cod_car),
  CONSTRAINT fk_bip_prod FOREIGN KEY (produtor_id) REFERENCES dim_produtor(id)
);

-- Talhão por safra. Alimentado por Agronavis/EEmovel ou pelo RTV.
CREATE TABLE fato_talhao_safra (
  id             BIGINT AUTO_INCREMENT PRIMARY KEY,
  cod_car        VARCHAR(60) NOT NULL,
  safra          VARCHAR(9)  NOT NULL,          -- '2025/26'
  nome_talhao    VARCHAR(80) NOT NULL,
  cultura        VARCHAR(40) NOT NULL,
  area_plantada  DECIMAL(10,3) NOT NULL,
  produtividade  DECIMAL(10,3) NULL,            -- sc/ha
  geom           GEOMETRY NULL SRID 4326,       -- opcional no v1
  fonte          VARCHAR(20) NOT NULL,
  UNIQUE KEY uk_talhao (cod_car, safra, nome_talhao),
  CONSTRAINT fk_tal_car FOREIGN KEY (cod_car) REFERENCES dim_imovel(cod_car)
);
```

**Não criar** tabela de indicadores. Potencial, realizado, share e gap vêm do `SCORE_QLIK`. Ver seção 7.

## 5. Pipeline de ingestão SICAR

Script isolado em `scripts/etl/sicar_import.js`, executável por município. Não roda em request.

Passos:

1. Baixar manualmente o shapefile `AREA_IMOVEL` do município em `car.gov.br/publico/imoveis/index` e colocar em `data/sicar/<cod_ibge>/`.
2. Ler o `.shp` com `shapefile` (npm) ou converter antes com `ogr2ogr`.
3. Reprojetar de SIRGAS 2000 (EPSG:4674) para EPSG:4326.
4. Validar e corrigir geometrias inválidas (`ST_IsValid`; descartar e logar as irrecuperáveis).
5. Gerar `geom_simpl` com tolerância de **0,00005 grau** (~5 m). Conferir visualmente antes de fixar o valor.
6. Calcular centroide.
7. Upsert por `cod_car`.
8. Emitir relatório: total lido, inseridos, atualizados, descartados e motivo.

**Municípios do piloto:** Concórdia (4204202). Só depois Ipira, Peritiba, Alto Bela Vista, Presidente Castello Branco.

## 6. Vínculo CAR ↔ Produtor

O download público do SICAR **não contém CPF, CNPJ nem nome do titular**. O vínculo é construído por cascata, e cada origem grava seu nível de confiança:

| Origem | Como | Confiança |
|---|---|---|
| `documento` | CAR já arquivado em crédito rural / contrato de integração | alta |
| `gps_visita` | RTV abre a visita na propriedade; ponto-em-polígono resolve o CAR | alta |
| `informado` | produtor informa o código | média |
| `manual` | operador vincula na retaguarda | baixa |

Regra: **um vínculo `gps_visita` nunca é criado em silêncio.** O sistema propõe, o RTV confirma na tela. Se o ponto cair em mais de um polígono ou em nenhum, apresentar a lista dos três CAR mais próximos.

Antes de codar isto, executar o levantamento: quantos CAR a Copérdia já possui arquivados. Esse número define se o item 2 é o caminho principal ou o complementar.

## 7. Indicadores — onde cada um é calculado

Princípio do projeto: **o Qlik calcula, o CRM exibe.** O CRM nunca reconstrói métrica financeira.

| Indicador | O que mede | Fórmula | Origem |
|---|---|---|---|
| Potencial | receita total de insumos que o imóvel consome na safra, independente do fornecedor | `área_plantada × R$/ha da cultura` | Qlik |
| Realizado | faturamento agro efetivo da Copérdia para os produtores vinculados ao imóvel | soma do faturamento na safra | Qlik |
| Share of wallet | fatia da carteira do produtor que já é nossa | `realizado ÷ potencial` | Qlik |
| Gap | receita disponível não capturada — o white space | `potencial − realizado` | Qlik |

Sincronização: job diário lendo `SCORE_QLIK` (read-only) para cache local `cache_score_imovel`, chaveado por `cod_car` + `safra`. O front lê o cache. Se o cache estiver com mais de 48 h, exibir aviso no mapa em vez de mostrar número silenciosamente velho.

**Nota sobre agregação:** quando um CAR tem vários produtores vinculados, o realizado do imóvel é a soma dos produtores com `principal = 1`. Definir a regra para condomínio antes de implementar — não deixar o Claude Code decidir isso.

## 8. Contrato de API

```
GET /api/territorio/imoveis
  query: municipio?, safra (obrigatório), rtv?, bbox?
  200 → GeoJSON FeatureCollection
        feature.geometry   = geom_simpl
        feature.properties = {
          codCar, nomeImovel, municipio, areaHa,
          produtorPrincipal, produtorId,
          statusComercial: 'ativo'|'inativo'|'prospect',
          culturaPrincipal,
          potencial, realizado, share, gap,
          rtv, ultimaVisita
        }
```
```
GET /api/territorio/imoveis/:codCar?safra=
  200 → objeto completo: cadastro + talhões + histórico de visitas + vínculos
```
```
GET /api/territorio/localizar?lat=&lng=
  200 → { match: [{codCar, nomeImovel, distanciaM}], exato: boolean }
  usa ST_Contains; se vazio, os 3 centroides mais próximos por ST_Distance_Sphere
```
```
POST /api/territorio/vinculos
  body: { codCar, produtorId, papel, origem, principal }
  201 → vínculo criado
```

Paginação: o endpoint de mapa **não pagina**. Filtra por `bbox` ou por município. Se o retorno passar de 8 MB, o filtro está errado.

## 9. Frontend

Componentes em `src/modules/territorio/`:

```
MapaTerritorial.jsx     container; estado de camada, filtros e seleção
  CamadaImoveis.jsx     GeoJSON layer do Leaflet, renderer canvas
  SeletorCamada.jsx     cobertura | share | gap | cultura
  Legenda.jsx           reativa à camada; muda escala e texto explicativo
  BarraKPI.jsx          agregado do que está visível no filtro
  FichaImovel.jsx       painel lateral
  useEscalaCor.js       hook: (imovel, camada) => cor
```

**O comportamento central do módulo:** a geometria é sempre a mesma; a camada troca apenas o significado da cor. Quatro camadas, um mapa. Não criar quatro telas.

Escalas em `useEscalaCor.js`, valores exatos no protótipo HTML:

- **cobertura** — categórica: ativo `#4FA87C`, inativo `#D4A64A`, não-cliente hachurado
- **share** — sequencial verde, 5 classes de 0 a 100%
- **gap** — sequencial âmbar→vermelho, 5 classes, normalizada pelo maior gap do filtro
- **cultura** — categórica por cultura

Transição de cor ao trocar camada: 450 ms, `ease-in-out`. Respeitar `prefers-reduced-motion`.

## 10. Critérios de aceite

1. Importar Concórdia gera registros em `dim_imovel` com geometria válida e relatório de carga sem descartes não explicados.
2. O mapa carrega o município inteiro em menos de 3 s em rede local.
3. Trocar de camada não refaz requisição — recolore o que já está em memória.
4. Clicar em um polígono abre a ficha com o código CAR completo e legível.
5. `GET /localizar` com uma coordenada dentro de um imóvel conhecido retorna `exato: true` e o CAR correto.
6. Filtrar por RTV recalcula a barra de KPI apenas com os imóveis visíveis.
7. Nenhum indicador financeiro é calculado no backend do CRM. Auditável por busca no código.
8. Funciona em tela de celular (o RTV usa em campo).

## 11. Fora de escopo no v1

NDVI e imagem de satélite processada · edição de geometria · roteirização otimizada (a linha do roteiro é manual) · APP e Reserva Legal como camadas · talhão como camada de mapa · versionamento histórico de polígono.

## 12. Ordem de implementação

Um PR por vez. Não iniciar o seguinte antes de o anterior estar mergeado.

| PR | Entrega |
|---|---|
| 1 | Migrations das três tabelas + seed de 5 imóveis fictícios |
| 2 | `sicar_import.js` funcionando para Concórdia |
| 3 | `GET /imoveis` devolvendo GeoJSON com dados mockados de score |
| 4 | `MapaTerritorial` + `CamadaImoveis` renderizando com camada única |
| 5 | `SeletorCamada` + `Legenda` + `useEscalaCor` |
| 6 | `FichaImovel` + `GET /imoveis/:codCar` |
| 7 | `BarraKPI` + filtros de município e RTV |
| 8 | `GET /localizar` + tela de confirmação de vínculo no fluxo de visita |
| 9 | Job de sync do `SCORE_QLIK` substituindo o mock do PR 3 |

O PR 3 usa score mockado de propósito: desacopla o desenvolvimento do frontend da disponibilidade do Qlik.
