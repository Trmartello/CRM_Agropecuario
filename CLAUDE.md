# CLAUDE.md

Guia para o Claude Code trabalhar neste repositório.

## Sobre o projeto

**CRM AGRO — Copérdia** — Sistema de Gestão Comercial e Assistência Técnica Agrícola. Aplicativo integrado para as áreas Comercial, Assistência Técnica e Relacionamento com o Produtor da cooperativa Copérdia. Centraliza as atividades do profissional de campo (agrônomo, extensionista, vendedor): visitas técnicas, recomendações, vendas, priorização de clientes, metas, crédito e prestação de contas.

A especificação completa está em `docs/ESCOPO_DO_PROJETO.md` (19 módulos + fases). **O desenvolvimento é feito por fases, uma a uma, sempre validando com o usuário antes de avançar.**

**Status**: Fases 1, 2 e 3 entregues. Fase 3 inclui: módulo **Despesas** (quilometragem com cálculo por categoria de reembolso, refeições com teto, prestação de contas mensal com fluxo Aberta→Enviada→Aprovada/Rejeitada), módulo **Reclamações** (laudos com máquina de estados Registrada→Em análise→Procedente/Improcedente→Jurídico→Indenização→Encerrada, fotos, parecer), **Gestão Documental** (aba Documentos na ficha do produtor, upload/download autenticado), **categorias de reembolso** configuráveis (valor/km e teto por categoria, atribuídas por usuário) e cartões de despesas/reclamações no dashboard. Próxima: Fase 4 (agenda, mapa, dashboards gerenciais, notificações, portal do produtor). App em produção de teste no Railway: https://crmagropecuario-production.up.railway.app

## Stack (padrão Copérdia — não alterar sem autorização)

- **Backend**: PHP 8 puro, MVC leve com roteador próprio (sem framework). PDO com prepared statements, sempre.
- **Banco**: MySQL/MariaDB. Schema e seed em `database.sql` (único arquivo, idempotente: `DROP TABLE IF EXISTS` + `CREATE` + seed).
- **Frontend**: Bootstrap 5 + JavaScript vanilla com `fetch` (AJAX). Chart.js para gráficos. **Todos os assets servidos localmente** (sem CDN — o app é usado no campo, com internet ruim).
- **Auth**: sessão PHP, `password_hash`/`password_verify`, 7 perfis de acesso.
- **Idioma**: 100% português (UI, mensagens, comentários de negócio). Nomes de tabelas/campos em português (`clientes`, `visitas`, `talhoes`).
- **Mobile**: o app é um **PWA** (manifest.json + ícones + service worker) — instalável no Android/iOS, um único código. Nada de app nativo sem autorização.
- **Offline básico (Fase 1)**: service worker cacheia app/assets; carteira do técnico em IndexedDB; visitas/fotos criadas sem sinal entram em fila local e sincronizam ao reconectar (endpoint de sync no ApiController).
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
/app/Controllers/     # um controller por módulo + ApiController p/ AJAX
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
3. Se `database.sql` mudou, reimportar do zero e conferir que o seed carrega sem erro.
4. Testar com perfil sem permissão para confirmar o bloqueio de acesso.

## Git

- Branch de trabalho: `claude/novo-projeto-w35g2t` (nunca commitar direto na `main`).
- Push: `git push -u origin claude/novo-projeto-w35g2t`.
- Commits em português, descritivos, um assunto por commit.
