# SPEC — Propriedade → Imóveis (CAR) → Área de plantio → Talhões por cultura e finalidade

**Projeto:** CRM Agropecuário Copérdia
**Local no repo:** `docs/specs/propriedade-imoveis-plantio.md`
**Status:** aprovado pelo dono do produto (06/09/2026) · schema v40
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
         ├─ área de plantio (contorno_plantio)   o que dá para plantar (fora mata/APP/reserva/sede)
         └─ Talhões (talhoes.imovel_id)   cada um com cultura + finalidade, desenhado no croqui
             └─ Plantios por safra (plantios.finalidade_id guarda o histórico)
```

- Uma propriedade pode ter **N imóveis (CARs)**. Cada imóvel tem a própria divisa, a própria área de plantio e os próprios talhões.
- `talhoes.propriedade_id` **continua existindo** (tudo que já consulta talhão por propriedade segue igual); `talhoes.imovel_id` é o vínculo novo. Regra: o imóvel do talhão pertence à mesma propriedade.
- `propriedades.car_numero / contorno / area_gps` viram **legado**: a migração copia para o primeiro imóvel e o código passa a ler/gravar em `imoveis`. As colunas ficam no schema por segurança, sem uso.

## 3. Áreas de plantio (v44: várias por imóvel)

- **Várias por imóvel** (pedido do teste de campo, 06/09/2026): tabela `areas_plantio` (`imovel_id`, `nome`, `contorno`, `area_gps`, `ordem`) — cada área é um polígono com nome (Campo, Morro…) desenhado no croqui (alvo **"Nova área de plantio"**; cada área gravada vira um alvo próprio no seletor; verde tracejado com o nome quando há mais de uma). `imoveis.contorno_plantio`/`area_plantio_gps` são **legado** (a migração v44 copia a área única para a 1ª linha e zera as colunas); `area_plantio_ha` digitada só vale para imóvel sem área desenhada.
- Regras: cada área fica **dentro da divisa** (ponto fora é preso na borda; nenhuma linha fora do CAR) e **uma área não cobre outra** (mesma regra dos talhões: ponto dentro da vizinha é puxado para a borda; cruzamento recusa; desenho igual recusa). A divisa nova não deixa nenhuma área para fora (bloqueio).
- **Área de plantio do imóvel = soma das áreas** (`AreaPlantioService`). Talhão fora de **todas** as áreas é **aviso**, não bloqueio (tolerância curta, 3 m). Talhão fora da **divisa** continua bloqueado.
- Renomear pelo croqui ("Renomear"); excluir = Limpar + Salvar na área. **Talhão cadastrado errado → "Virar área de plantio"** cria uma área nova com o nome do talhão (não substitui as outras); **"Copiar de talhão"** carrega o desenho de um talhão numa área nova para ajustar.
- **"Plantar a área toda"**: cria **um talhão por área de plantio** (com o nome da área quando há mais de uma), na cultura/finalidade escolhidas. Só quando o imóvel ainda não tem talhões.

## 4. Finalidades

- Tabela `finalidades` (nome, ativo, ordem), editável em **Configurações → Finalidades de cultura**. Seed: Grão · Silagem · Pastagem · Feno/Pré-secado · Semente.
- `talhoes.finalidade_id` = uso atual; `plantios.finalidade_id` = histórico por safra (registrar plantio copia para o talhão, como já acontece com a cultura).

## 5. Totalização (`AreaPlantioService`, só leitura)

Por imóvel → por propriedade → por produtor:

| Medida | Origem |
|---|---|
| Área total | `area_gps` (divisa) ou `area_ha` |
| Área de plantio | soma das `areas_plantio.area_gps` (v44); sem área desenhada, `area_plantio_gps`/`area_plantio_ha` legado; se zero, cai na área total |
| Por cultura × finalidade | soma dos talhões (`area_gps` ou `area_ha`) |
| Não mapeado | área de plantio − soma dos talhões (quando > 0) |
| Excedente | soma dos talhões − área de plantio (quando > 0) → alerta |

Exibido na ficha (aba Propriedades) como barra por cultura/finalidade com %, mais consolidado do produtor no topo da aba.

## 6. O que NÃO muda

- Visitas, fenologia, custo da lavoura, relatório de safra e snapshot offline continuam ligando talhão → propriedade.
- Mapa Territorial (`dim_imovel`) é outra base (CAR do município) e não é afetado.

## 7. Migração v40 (bancos já instalados)

1. `CREATE TABLE IF NOT EXISTS imoveis`, `finalidades`; colunas `talhoes.imovel_id`, `talhoes.finalidade_id`, `plantios.finalidade_id`.
2. Seed de finalidades se a tabela estiver vazia.
3. Para cada propriedade sem imóvel: cria 1 imóvel copiando `car_numero/contorno/area_gps/area_ha/municipio`.
4. Talhões sem `imovel_id` recebem o primeiro imóvel da própria propriedade.

Idempotente: pode rodar de novo sem duplicar.
