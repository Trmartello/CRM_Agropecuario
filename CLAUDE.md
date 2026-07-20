# CLAUDE.md

Guia para o Claude Code trabalhar neste repositório.

## Sobre o projeto

**CRM AGRO — Copérdia** — Sistema de Gestão Comercial e Assistência Técnica Agrícola. Aplicativo integrado para as áreas Comercial, Assistência Técnica e Relacionamento com o Produtor da cooperativa Copérdia. Centraliza as atividades do profissional de campo (agrônomo, extensionista, vendedor): visitas técnicas, recomendações, vendas, priorização de clientes, metas, crédito e prestação de contas.

A especificação completa está em `docs/ESCOPO_DO_PROJETO.md` (19 módulos + fases). **O desenvolvimento é feito por fases, uma a uma, sempre validando com o usuário antes de avançar.**

**Status**: Fases 1 a 5 entregues (revisão por agentes por fase). **Fase 4**: Agenda (eventos, roteiro do dia, agendamento por WhatsApp via wa.me), Notificações (central com sino, gatilhos em pedido faturado/reclamação/recomendação), Painel Gerencial, Mapa de clientes (por coordenadas, sem tiles externos) e Portal do Produtor (perfil Produtor vê só os próprios dados, vinculado por `usuarios.cliente_id`). **Fase 5**: camada de integração ERP/CAPE (`IntegracaoService`, fonte configurável Local/ERP/CAPE, log de sincronização) com interface estável — na conexão real do ERP muda só o adaptador. Segurança: JSON embutido em atributo HTML sempre via helper `json_attr()`; texto em innerHTML via `App.escapeHtml`. Pendências antigas já resolvidas: KPIs estratégicos no Gerencial (atingimento CAP da equipe, conversão do funil, clientes em churn via lote, aproveitamento do potencial da safra), offline ampliado (O1–O3) e Portal do Produtor com fotos das visitas (servidas com autorização por cliente) e entregas futuras. Pendências que dependem de terceiros: adaptador ERP/CAPE real (TI Copérdia) e piloto com usuários de campo. **Web Push (schema v16)**: notificações no aparelho sem dependências — `PushService` (VAPID ES256 puro OpenSSL) envia push **sem payload** e o `sw.js` busca `notificacoes/ultima` para exibir; assinaturas em `push_assinaturas` (dedup por hash do endpoint, limpeza automática em 404/410); disparo automático em todo `NotificacaoService::criar`; botão "Ativar notificações neste aparelho" no sino. Android direto; iPhone exige PWA instalado (iOS 16.4+). Push real só é testável em aparelho (headless não tem push service).

**Refinamentos pós-fases (schema atual v13)**: **Organizador de Visitas** (`agenda/organizador`, `AgendaService`) — monta o roteiro do dia a partir de sugestões priorizadas (score de priorização), permite buscar/adicionar qualquer produtor da carteira, filtrar por **município** e **linha** (linha depende do município), reordenar/otimizar a rota pela **menor quilometragem total** (vizinho-mais-próximo multi-início + 2-opt, com km exibido), marcar visitado, WhatsApp e imprimir; cada parada é um evento de agenda (coluna `ordem`). Mostra também a **estimativa de tempo do dia** (viagem pela velocidade média + duração média por visita, constantes em `AgendaService`) e destaca paradas com **visita vencida** (sem visita há ≥ `DIAS_VISITA_VENCIDA` dias, borda/badge). Lançamento de KM com **pré-preenchimento inteligente**: veículo, tipo de destino e filial vêm da última escolha do usuário, e a KM inicial vem da KM final do último lançamento do veículo. Cliente ganhou campo **`linha`** (localidade rural). **Completar visita**: na Priorização, produtor cuja última visita não está finalizada mostra o botão **Completar** (abre o modal pré-preenchido via `visitas/dados` e atualiza por UPDATE em `visitas/salvar` com `id>0`); finalizada → só "Visitar", e o servidor bloqueia edição de visita finalizada. **Duplo clique na linha** executa a ação disponível. **Nova visita é bloqueada enquanto a última não estiver finalizada** (servidor recusa o INSERT; o modal de nova visita troca sozinho para "Completar" via `visita_pendente` do `apoio-modal`; offline o snapshot bloqueia com aviso). Na edição há o botão **"Finalizar assim mesmo"** (`finalizar_definitivo=1`): encerra a visita com o cadastro incompleto (completude real preservada; selo verde "Cadastro X%"), auditada como `finalizar`. A aba "Visitas realizadas" tem o lápis nas não finalizadas. Sidebar rola no celular (`overflow-y:auto`). Refeição usa só data (sem hora). Cabeçalhos de segurança em `index.php` e cookies `Secure` sob HTTPS (`Auth::httpsAtivo`). **Segurança de produção (schema v14)**: troca obrigatória de senha no primeiro acesso (`usuarios.trocar_senha`; a migração V14 marca todos os usuários de bancos existentes — mata as senhas seed no Railway; senha definida pelo admin é temporária; guard no front controller + telas `login/trocar-senha`); bloqueio de força bruta no login (tabela `login_tentativas`: 5 falhas/30min → 15min de bloqueio por e-mail). Instalação nova do `database.sql` não força troca (dev); checklist de produção (volume de uploads, backup) em `docs/PRODUCAO_CHECKLIST.md`. **Menu lateral organizado em módulos/grupos recolhíveis** (`layout.php`): Dashboard solto no topo e grupos **Atendimento ao Produtor** (Produtores/Clientes, Visitas, Agenda, Mapa, Reclamações), **Comercial** (Pedidos, Pacotes, Funil, Potencial de Vendas, Metas CAP), **Despesas** (KM e Refeição) e **Gestão** (Gerencial, Usuários, Integração — conforme perfil); o grupo da rota atual abre sozinho e, no menu recolhido, os cabeçalhos somem e os itens viram ícones.

**Status anterior**: Fases 1, 2 e 3 entregues. Fase 3 inclui: módulo **Despesas** (quilometragem com cálculo por categoria de reembolso, refeições com teto, prestação de contas mensal com fluxo Aberta→Enviada→Aprovada/Rejeitada), módulo **Reclamações** (laudos com máquina de estados Registrada→Em análise→Procedente/Improcedente→Jurídico→Indenização→Encerrada, fotos, parecer), **Gestão Documental** (aba Documentos na ficha do produtor, upload/download autenticado), **categorias de reembolso** configuráveis (valor/km e teto por categoria, atribuídas por usuário) e cartões de despesas/reclamações no dashboard. Próxima: Fase 4 (agenda, mapa, dashboards gerenciais, notificações, portal do produtor). App em produção de teste no Railway: https://crmagropecuario-production.up.railway.app

## Stack (padrão Copérdia — não alterar sem autorização)

- **Backend**: PHP 8 puro, MVC leve com roteador próprio (sem framework). PDO com prepared statements, sempre.
- **Banco**: MySQL/MariaDB. Schema e seed em `database.sql` (único arquivo, idempotente: `DROP TABLE IF EXISTS` + `CREATE` + seed).
- **Frontend**: Bootstrap 5 + JavaScript vanilla com `fetch` (AJAX). Chart.js para gráficos. **Todos os assets servidos localmente** (sem CDN — o app é usado no campo, com internet ruim).
- **Auth**: sessão PHP, `password_hash`/`password_verify`, 7 perfis de acesso.
- **Idioma**: 100% português (UI, mensagens, comentários de negócio). Nomes de tabelas/campos em português (`clientes`, `visitas`, `talhoes`).
- **Mobile**: o app é um **PWA** (manifest.json + ícones + service worker) — instalável no Android/iOS, um único código. Nada de app nativo sem autorização.
- **Offline (Fase 1 + O1/O2/O3)**: service worker cacheia o app/assets (assets *cache-first* com *runtime caching*, inclusive os versionados por `?v=filemtime`; páginas *rede-primeiro* → a última versão vista abre offline). Escritas feitas sem sinal entram numa **fila genérica no IndexedDB** (`crm_coperdia` v2 › `fila_sync`): cada item guarda `rota`, campos e anexos (Blobs) e é reenviado à sua rota original ao reconectar (evento `online` + ~1,5 s após load), com **guard de reentrância** contra sincronização concorrente/duplicada. Módulos offline hoje: **Visitas, Despesas (KM/refeição), Agenda (evento/status) e Reclamações** (via `App.enviarFormOffline`); Pedidos/Pacotes/Funil ficam online. Um **painel de "Pendências de envio"** (sino de nuvem no topo) lista o que está na fila, com tentar-de-novo/descartar. O servidor sempre recalcula tudo no reenvio. **Snapshot da carteira (O2)**: `sync/carteira` (`SyncController`) devolve produtores **já com a priorização** (score/dias/churn/cadastro) + propriedades/talhões por produtor + culturas + modelos; o cliente guarda no IndexedDB (store `snapshot`, atualizado a cada load online e no evento `online`). Usos offline: a **Nova Visita** popula propriedades/talhões/cultura/modelos do snapshot; e `OfflineView` (app.js) **re-renderiza do snapshot** as telas **Produtores, Priorização e Organizador (sugestões)** quando offline, com banner "Modo offline — carteira salva de …". Ações que exigem servidor (ficha completa, montar/otimizar roteiro) seguem online. **Robustez (O3)**: cada item da fila leva um `uuid` e o servidor deduplica pela tabela `sync_processados` (schema v13) — reenvio idempotente, sem duplicata mesmo se a resposta do OK se perder; sessão expirada no sync mantém a fila e pede login; aviso de cota ao anexar arquivos; Background Sync (SW registra `sync-fila` e avisa os clientes ao reconectar). Detalhes em `docs/OFFLINE_AMPLIADO.md`.
- **Banco via variáveis de ambiente**: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` com fallback para padrões locais (`localhost`/root) — necessário para o deploy no Railway.
- **Deploy de testes**: Railway (Dockerfile com servidor embutido do PHP em `$PORT`, MySQL interno `mysql.railway.internal`, HTTPS automático, volume para `public/uploads` — necessário só para fotos de visitas; identidade visual fica no banco). Guia em `docs/DEPLOY_RAILWAY.md`.

## Diretrizes de UX (exigência do usuário)

- **O mínimo de páginas/abas possível.** Uma página por módulo principal; **todo cadastro/edição abre em modal** (Bootstrap) sobre a tela atual, salvando via AJAX sem navegar.
- Interface simples para o usuário de campo: botões grandes, busca rápida em toda listagem, responsivo mobile-first (modais em tela cheia no celular).
- Campos de texto longos (observações, recomendações) têm **botão de microfone** (Web Speech API, pt-BR) com fallback silencioso para digitação.
- Geolocalização automática (`navigator.geolocation`) em visitas e cadastro de clientes.

## Arquitetura

```
/public/index.php     # front controller único (roteia via ?r= ou path)
/public/assets/       # css, js, chart.js, bootstrap (locais)
/public/uploads/      # fotos de visitas e documentos
/app/Core/            # Router, Database (PDO singleton), Auth, Permissoes
/app/Controllers/     # um controller por módulo (respondem também às chamadas AJAX)
/app/Models/          # um model por tabela principal
/app/Services/        # regras de negócio: CreditoService, ComercialService,
                      # CapService, PriorizacaoService, OportunidadeService...
/app/Views/           # layout.php + páginas + partials de modais
/database.sql         # schema completo + seed de demonstração
/docs/                # REQUISITOS.md (19 módulos), ROADMAP.md
```

Regras de código:
- Regra de negócio fica em `app/Services/`, nunca em view; controller só orquestra.
- Serviços que futuramente lerão do ERP/CAPE (`ComercialService`, `CapService`, `CreditoService`) devem manter interface estável — na Fase 5 apenas a fonte de dados muda.
- Toda rota passa pelo helper de permissões (`Permissoes::exigir(...)`) conforme o perfil.
- Nunca interpolar variáveis em SQL; sempre prepared statements.
- Escapar saída HTML com `htmlspecialchars` (helper `e()`).
- Preços e valores sempre calculados no servidor (nunca confiar no que vem do navegador).
- Erros são capturados globalmente no `index.php` (JSON legível para AJAX).

**Migrações de banco (obrigatório ao mudar o schema):**
- `database.sql` é a fonte da verdade para instalações novas (idempotente).
- Bancos já instalados (Railway) migram sozinhos: `Instalador::migracoesLeves()` usa a chave `schema_versao` em `configuracoes`. Ao alterar o schema, **incremente a versão e adicione os passos** (CREATE IF NOT EXISTS / ADD COLUMN com verificação em information_schema + seed leve se vazio). Nunca exigir recriação manual do banco.

**Cache do PWA (obrigatório ao mudar assets):**
- `app.css`, `app.js` e `offline.js` são versionados por `filemtime` no layout — automático.
- Outros assets estáticos novos: adicionar na lista do `sw.js` **e** incrementar a constante `CACHE` (vN) — senão o service worker serve versão antiga.

**Sessão/login:** persistente por 30 dias via token hasheado em `sessoes_persistentes` (sobrevive a deploys). `Auth::iniciarSessao()` restaura sozinho.

**Identidade visual:** logo e favicon personalizáveis em Configurações (Administrador), gravados em base64 no banco (`configuracoes`) e servidos por `arquivo/logo|favicon` — não usar arquivos em uploads para identidade.

**Validação visual:** o Chromium da sessão permite screenshot real das telas:
`/opt/pw-browsers/chromium --headless --no-sandbox --screenshot=... URL` (ou playwright-core no scratchpad para fluxos com login). Use antes de commitar mudanças de UI.

## Perfis de acesso

Administrador, Gestor Comercial, Gestor Técnico, Consultor Técnico, Vendedor, Analista, Produtor. Vendedor/técnico só vê **os próprios** dados de metas (CAP) e carteira; gestores veem a equipe; Produtor (fase futura) só vê os próprios dados.

## Regras de negócio centrais (Fase 1)

- **Priorização de visitas**: score = tempo desde última visita + nível tecnológico + volume de compras + potencial de venda + risco de churn (pesos em constantes no `PriorizacaoService`).
- **Crédito**: limite por cliente, score interno A/B/C/D, cliente inadimplente ou acima do limite gera "Pendente de aprovação" (alçada do Gestor Comercial); garantias (CPR, penhor, aval) com alerta de vencimento.
- **Funil**: oportunidades geradas automaticamente por gap de recompra (comprou na safra passada, não nesta), potencial não atendido e gatilhos do calendário agronômico; perda exige motivo.
- **Anti-churn**: queda >30% vs. mesmo período da safra anterior marca risco e eleva prioridade de visita.
- **CAP (Copérdia Alta Performance)**: metas por vendedor; dados oficiais virão do app CAPE na Fase 5 — até lá, tabelas locais `metas_cap`/`realizado_cap`.

## Como rodar

```bash
# banco (uma vez, e sempre que database.sql mudar)
mysql -u root -p < database.sql

# servidor de desenvolvimento
php -S localhost:8000 -t public
```

Login seed: admin@coperdia.com.br / senha definida no seed de `database.sql`. Há usuários seed para cada perfil e dados de demonstração (clientes, safras, compras, visitas) que exercitam todas as regras (inadimplente, churn, gap de recompra etc.).

## Verificação antes de commitar

1. `php -l` em todos os arquivos PHP alterados.
2. Subir o servidor e percorrer o fluxo afetado manualmente (login → tela → modal → salvar → conferir persistência).
3. **Smoke test automatizado**: `NODE_PATH=<dir com playwright-core> BASE_URL=http://127.0.0.1:8000 CHROMIUM=/opt/pw-browsers/chromium node tests/smoke.js` — cobre login, priorização, nova visita, KM, agenda, reclamação, offline (fila+sync), auditoria e logout. Cria registros "SMOKE" (nunca rodar contra produção); reimportar o banco depois se quiser zerar.
4. Se `database.sql` mudou, reimportar do zero e conferir que o seed carrega sem erro.
5. Testar com perfil sem permissão para confirmar o bloqueio de acesso.

## Git

- Branch de trabalho: `claude/novo-projeto-w35g2t` (nunca commitar direto na `main`).
- Push: `git push -u origin claude/novo-projeto-w35g2t`.
- Commits em português, descritivos, um assunto por commit.
