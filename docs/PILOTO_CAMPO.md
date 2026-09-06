# Piloto de campo — CRM AGRO Copérdia

**Região:** Fraiburgo/SC · **Equipe:** 3 a 5 técnicos + 1 gestor · **Duração:** 2 semanas, retorno semanal
**Versão do app no piloto:** schema v40 (imóveis/CAR, área de plantio, finalidades, mapa offline)
**Guia do técnico (1 página, para imprimir):** `docs/GUIA_CAMPO.md`

---

## 1. O que o piloto responde

Três perguntas, nesta ordem de importância:

1. **O técnico consegue trabalhar sem sinal?** Visita, croqui e fila de envio funcionam na propriedade e sincronizam ao voltar.
2. **O cadastro da terra fecha?** Cada produtor visitado sai com imóvel (CAR), área de plantio e talhões por cultura/finalidade — e os totais batem com o que o técnico sabe da fazenda.
3. **A priorização e a segmentação fazem sentido no campo?** Os cortes (`PriorizacaoService`, `SegmentacaoService`) são constantes que o `CLAUDE.md` manda **calibrar no piloto**. Aqui é onde isso acontece.

O que o piloto **não** testa: pedidos, pacotes, funil e integração ERP/CAPE (ficam online e dependem da TI).

## 2. Antes de começar — checklist do Administrador

Tudo abaixo é feito **no app em produção** ou no painel do Railway. Sem isso o piloto começa errado.

| # | Item | Onde | Como conferir |
|---|---|---|---|
| 1 | Volume de uploads montado | Railway → app → Settings → Volumes → `/var/www/html/dados/uploads` | Enviar uma foto numa visita, redeployar, foto continua abrindo |
| 2 | Backup do banco baixado **antes** do piloto | Configurações → "Backup do banco de dados" | Arquivo `.sql` guardado fora do servidor |
| 3 | **Base do CAR de Fraiburgo importada** | Integração → "Base do CAR por município" (shapefile `AREA_IMOVEL` do SICAR, município inteiro) | Abrir o croqui de um produtor de Fraiburgo → "CAR pela sede" ou "CAR no mapa" acha imóveis |
| 4 | Contas dos participantes criadas | Usuários → perfil correto, **Cód. vendedor (ERP/CAP)** se houver, categoria de reembolso | Cada um recebe e-mail + senha temporária; o app obriga a trocar no 1º acesso |
| 5 | Carteira atribuída | Produtores → responsável = o técnico do piloto | Cada técnico vê **só** os próprios produtores na Priorização |
| 6 | Produtores de Fraiburgo com sede localizada | Ficha → propriedade → coordenada da sede | Sem sede, "CAR pela sede" e a auditoria de campo não funcionam |
| 7 | Usuários seed desativados | Usuários → `consultor@`, `vendedor@` etc. inativos | Ninguém entra com senha de demonstração |
| 8 | Finalidades conferidas | Configurações → "Finalidades de cultura" | Grão, Silagem, Pastagem, Feno/Pré-secado, Semente — acrescentar o que a equipe usa |
| 9 | Provedor de mapa decidido | Configurações (`mapa_tiles_url`) | Ver §7 — termos do Esri para cache offline |

**Responsável:** Administrador do sistema. **Prazo:** antes do treinamento (§3).

## 3. Cronograma (sugestão: 08/09 a 19/09/2026)

### Dia 0 — Treinamento (1h30, presencial ou vídeo, com celular na mão)

1. Instalar o app como **PWA** (Android: Chrome → "Adicionar à tela inicial"; iPhone: Safari → Compartilhar → "Adicionar à Tela de Início"). **Sem isso, no iPhone não há push nem modo offline confiável.**
2. Primeiro acesso: trocar a senha (o app obriga) — usar o **olho** para conferir a digitação.
3. Sino → "Ativar notificações neste aparelho".
4. Roteiro do `GUIA_CAMPO.md`, passo a passo, com um produtor de verdade da carteira de cada um.
5. Combinar o **canal de retorno** (§5).

### Semana 1 (08 a 12/09) — Cadastrar a terra, no escritório e no campo

Meta por técnico: **5 produtores da carteira com o cadastro fechado**:

- Propriedade → imóvel com **nº do CAR** (pelo "CAR pela sede" no escritório, ou "CAR aqui" na propriedade).
- **Área de plantio** desenhada no croqui (ou digitada no imóvel).
- Talhões com **cultura e finalidade**; usar **"Área toda"** quando for uma cultura só.
- Conferir os totais na ficha com o produtor ("são 18 ha de milho silagem mesmo?").

Preparo diário no escritório, com wi-fi: abrir o croqui de cada propriedade do dia e tocar em **Baixar mapa**.

**Retorno 1 (sexta 12/09, 30 min):** §5.

### Semana 2 (15 a 19/09) — Visitas reais com o app

Meta por técnico: **todas as visitas da semana registradas no app**, do início ao fim:

- Montar o roteiro do dia no **Organizador de Visitas** (otimizar rota).
- Na propriedade: **Iniciar Visita** (GPS), fotos com legenda, checklist da lavoura pela linha do tempo da cultura, recomendação, próximo retorno.
- Sem sinal: continuar normalmente; ao voltar, conferir o **sino de nuvem** (fila de envio) zerado.
- Despesas de KM e refeição pelo app.

O gestor acompanha no **Gerencial** (Desempenho, Auditoria de campo) e na **Priorização** de cada técnico.

**Retorno 2 (sexta 19/09, 45 min):** §5 + decisão de continuidade (§6).

## 4. O que medir (tudo sai do próprio app)

| Métrica | Onde ver | Sinal de sucesso em 2 semanas |
|---|---|---|
| Produtores com imóvel + CAR + área de plantio | Ficha → aba Propriedades (aviso "Sem nº do CAR" some) | ≥ 5 por técnico |
| Talhões com finalidade | Ficha → barra por cultura × finalidade sem "Sem cultura" | 100% dos talhões novos |
| Visitas finalizadas (não "incompletas") | Gerencial → Desempenho | ≥ 80% das visitas |
| Itens na fila offline que sincronizaram | Sino de nuvem (técnico) + Auditoria (admin) | 0 itens presos ao fim de cada dia |
| Visitas "fora da propriedade" | Gerencial → Auditoria de campo | Só falsos positivos explicáveis (sede mal localizada) |
| Duração média da visita | Gerencial → Desempenho | Referência para o piloto seguinte |
| Priorização "faz sentido" | Pergunta direta ao técnico (§5) | Técnico concorda com ≥ 7 dos 10 primeiros da lista |
| Segmento A/B/C/D "bate" | Gestor confere 10 produtores conhecidos | ≥ 8 de 10 |

Os dois últimos são os que **calibram constantes** (`PriorizacaoService`, `SegmentacaoService`): anotar os casos discordantes com o motivo.

## 5. Canal de retorno

- **Grupo de WhatsApp do piloto** (técnicos + gestor + responsável pelo sistema). Regra: **print + uma frase** ("no croqui, o ponto foi parar fora da divisa" + print). Print vale mais que descrição.
- **Retorno semanal (30–45 min)**, roteiro fixo:
  1. O que **travou** (não conseguiu fazer). Prioridade máxima.
  2. O que **funcionou mas irritou** (lento, muitos toques, texto confuso).
  3. O que **faltou** (funcionalidade que o técnico esperava).
  4. Os 10 primeiros da Priorização de cada um: concorda? por quê não?
- Cada item vira uma linha em `docs/backlog.md` ou uma correção direta, decidida no retorno. **Nada fica só no WhatsApp.**

## 6. Critério de continuidade (retorno 2)

**Segue para a equipe inteira** se:
- nenhum item de "travou" ficou sem correção;
- fila offline sincronizou todos os dias sem perda;
- ≥ 5 produtores por técnico com o cadastro da terra fechado;
- os técnicos **preferem** o app ao processo anterior (pergunta direta, resposta anônima se preciso).

**Repete mais 2 semanas** se houve correções na semana 2 que a equipe ainda não usou em campo.

**Para e reavalia** se o modo offline perdeu dados ou se a equipe não adotou (menos da metade das visitas no app).

## 7. Riscos conhecidos e o que fazer

| Risco | O que acontece | Mitigação |
|---|---|---|
| **Termos do Esri** para mapa offline | O "Baixar mapa" guarda imagens do provedor no aparelho; a licença do Esri World Imagery pode restringir | Decidir antes do piloto. Trocar o provedor é só configuração (`mapa_tiles_url`) — sem mexer em código |
| iPhone com app antigo em cache | Botão "Croqui" chama rota antiga, mapa não aparece | Fechar a aba/PWA e abrir de novo após cada deploy (o app manda `no-cache`, mas o Safari às vezes insiste) |
| Sede sem coordenada | "CAR pela sede" e auditoria de campo não funcionam | Item 6 do checklist; na dúvida, usar "CAR aqui" na propriedade |
| Base do CAR de outro município | Produtor com terra em município vizinho não acha o imóvel | Importar o município vizinho na Integração |
| Bloqueio de login (5 erros / 15 min) | Técnico travado no campo | Senha com o olho; Administrador redefine em Usuários |
| Talhão legado no imóvel errado | Propriedade com 2+ CARs: a migração pôs todos os talhões antigos no 1º imóvel | Editar o talhão → trocar o imóvel (move) |
| Cota de armazenamento do celular | "Baixar mapa" avisa "acabou o espaço" | Aproximar o mapa e baixar área menor; limpar dados de outros apps |

## 8. Papéis

| Quem | Faz |
|---|---|
| **Administrador do sistema** | Checklist §2, contas, base do CAR, backup semanal, correções durante o piloto |
| **Gestor (Comercial ou Técnico)** | Atribui carteiras, acompanha Gerencial/Priorização, conduz os retornos, decide continuidade |
| **Técnicos (3 a 5)** | Cumprem as metas semanais, mandam print + frase no grupo, participam dos retornos |
| **Responsável pelo sistema (Claude Code)** | Recebe prints/relatos, corrige por PR, atualiza `backlog.md` e este plano |

## 9. Depois do piloto

Com os dados de 2 semanas em produção:
- ajustar as constantes de priorização/segmentação com os casos discordantes anotados (§4);
- promover o `backlog.md` do que "faltou" a specs, na ordem que a equipe pediu;
- estender para a filial inteira usando este mesmo plano, com o `GUIA_CAMPO.md` revisado pelo que os técnicos do piloto disseram.
