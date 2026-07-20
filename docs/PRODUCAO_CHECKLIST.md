# Checklist de Produção — CRM AGRO (Railway)

Itens de segurança/confiabilidade para rodar o sistema com dados reais.
Os passos 1 e 2 são **configuração no painel do Railway** (não dá para
resolver por código). Os demais já estão implementados no app.

## 1. Volume para os uploads (`/var/www/html/dados/uploads`) — OBRIGATÓRIO

Sem volume, **fotos/comprovantes/documentos são apagados a cada redeploy**
(o banco guarda o registro, mas o arquivo some — a miniatura vira "abrir foto").

> Desde a versão com uploads autenticados, os arquivos ficam FORA do docroot,
> em **`dados/uploads`** (não use mais `public/uploads`). Tudo é servido pela
> rota autenticada `arquivo/upload` — sem URL pública direta.
> **Atenção ao caminho absoluto**: o Dockerfile usa `WORKDIR /var/www/html`,
> então o mount é `/var/www/html/dados/uploads` (NÃO `/app/...` — `/app` é o
> padrão do Railway sem Dockerfile, que não é o nosso caso).

1. No Railway, abra o serviço da **aplicação** (não o MySQL).
2. Aba **Settings → Volumes → Add Volume** (ou botão direito no serviço → *Attach volume*).
3. **Mount path**: `/var/www/html/dados/uploads`
4. Salve — o Railway reinicia o serviço com o volume montado.
5. Teste: envie uma foto numa visita, faça um redeploy e confira que a foto continua abrindo.

> Fotos enviadas ANTES do volume foram perdidas nos redeploys — os registros
> antigos mostrarão o cartão "abrir foto". Só as novas ficam persistidas.
> (Arquivos legados em `public/uploads` continuam legíveis: a rota autenticada
> procura primeiro em `dados/uploads` e cai para o caminho antigo.)

## 2. Backup do banco (MySQL) — OBRIGATÓRIO

O banco guarda visitas, despesas, reclamações e clientes. Sem backup, um
acidente (delete errado, corrupção, exclusão do serviço) perde tudo.

**Opção A — Botão de backup no próprio app (funciona em qualquer plano):**
1. Entre como **Administrador** → **Configurações** → card **"Backup do banco de dados"**.
2. Clique em **"Baixar backup agora"** — baixa um `.sql` completo (estrutura + dados).
3. Guarde o arquivo fora do servidor (Drive, pendrive) — ao menos **1x por semana**.
4. Restauração: `mysql -u <usuario> -p <banco> < arquivo.sql`.
5. Atenção: as **fotos/documentos** ficam no volume (`/var/www/html/dados/uploads`) e não
   entram nesse arquivo — o volume as preserva entre deploys.

**Opção B — Backups automáticos do Railway (aba "Backups" no volume do MySQL):**
disponível apenas em planos superiores (Pro). Se a aba não aparecer no seu
plano, use a Opção A.

**Opção C — Dump via Railway CLI (manual):**
```bash
railway run --service MySQL bash -c 'mysqldump -h $MYSQLHOST -P $MYSQLPORT -u $MYSQLUSER -p$MYSQLPASSWORD $MYSQLDATABASE' > backup_$(date +%Y%m%d).sql
```

## 3. Senhas do seed — resolvido no app (v14)

- Na primeira visita após o deploy da versão 14, a migração marca **todos os
  usuários existentes** para **troca obrigatória de senha**: no próximo login,
  cada um define a própria senha (mínimo 8 caracteres, letras e números)
  antes de acessar qualquer tela. As senhas de demonstração deixam de valer.
- Usuários novos (ou com senha redefinida pelo admin) também entram com senha
  temporária e trocam no primeiro acesso.
- Instalação **nova** direto do `database.sql` (ex.: ambiente de testes) não
  força a troca; para produção nova, rode uma vez:
  `UPDATE usuarios SET trocar_senha = 1;`

## 4. Proteção do login — resolvido no app (v14)

- 5 tentativas falhas em 30 min bloqueiam o e-mail por 15 min.
- Resposta de falha propositalmente lenta (dificulta robôs).

## 5. Notificações push no aparelho — teste após o deploy

1. Abra o app no celular (Android: Chrome; **iPhone: instale antes pela opção
   "Adicionar à Tela de Início"** — exigência do iOS 16.4+).
2. Toque no sino → **"Ativar notificações neste aparelho"** → permita.
3. Peça a outro usuário para gerar um evento (ex.: faturar um pedido seu, ou
   registrar uma recomendação para o produtor) — a notificação deve chegar no
   aparelho mesmo com o app fechado (Android) ou em segundo plano.
4. As chaves VAPID são geradas automaticamente no primeiro uso e ficam no banco
   (`configuracoes`) — nenhuma configuração externa é necessária.

## 6. Conferências rápidas finais

- [ ] HTTPS ativo (Railway já fornece; cookies `Secure` são automáticos).
- [ ] Volume montado (item 1) e backup agendado (item 2).
- [ ] Todos os usuários reais criados no módulo Usuários (perfil correto,
      categoria de reembolso atribuída) — cada um troca a senha no 1º acesso.
- [ ] Usuários seed que não serão usados: desativar no módulo Usuários.
- [ ] `docs/DEPLOY_RAILWAY.md` para detalhes gerais do deploy.
