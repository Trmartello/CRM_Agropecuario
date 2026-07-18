# Deploy de testes no Railway

Passo a passo para colocar o CRM no ar para homologação (web responsivo + PWA no celular).

## 1. Criar o projeto

1. Acesse [railway.app](https://railway.app) e crie um projeto.
2. **Add Service → GitHub Repo** → selecione `Trmartello/CRM_Agropecuario` (branch `claude/novo-projeto-w35g2t` ou `main` após o merge).
   O Railway detecta o `Dockerfile` automaticamente.

## 2. Adicionar o banco MySQL

1. **Add Service → Database → MySQL**.
2. No serviço da aplicação, aba **Variables**, adicione (usando as referências do serviço MySQL):

| Variável | Valor |
|---|---|
| `DB_HOST` | `${{MySQL.MYSQLHOST}}` |
| `DB_PORT` | `${{MySQL.MYSQLPORT}}` |
| `DB_NAME` | `${{MySQL.MYSQLDATABASE}}` |
| `DB_USER` | `${{MySQL.MYSQLUSER}}` |
| `DB_PASS` | `${{MySQL.MYSQLPASSWORD}}` |

## 3. Importar o schema + seed

No painel do serviço MySQL → aba **Data** (ou via `railway connect mysql`), execute o conteúdo de `database.sql`.

> Importante: o `database.sql` cria o banco `crm_agropecuario`. Se o Railway já fornece um banco (variável `MYSQLDATABASE`), rode o script sem as duas primeiras linhas (`CREATE DATABASE`/`USE`) ou ajuste `DB_NAME` para `crm_agropecuario`.

## 4. Uploads persistentes

No serviço da aplicação: **Settings → Volumes → Add Volume** montado em `/var/www/html/public/uploads`.
Sem o volume, as fotos somem a cada deploy.

## 5. Testar no celular

1. O Railway gera um domínio `https://…up.railway.app` (Settings → Networking → Generate Domain).
2. Abra no Chrome do celular → menu → **“Adicionar à tela inicial”** → o CRM instala como aplicativo (PWA).
3. HTTPS do Railway habilita GPS, câmera, microfone e o modo offline.

## Login de demonstração

| Perfil | E-mail | Senha |
|---|---|---|
| Administrador | admin@coperdia.com.br | coperdia123 |
| Gestor Comercial | gestor.comercial@coperdia.com.br | coperdia123 |
| Consultor Técnico | consultor@coperdia.com.br | coperdia123 |
| Vendedor | vendedor@coperdia.com.br | coperdia123 |

> Troque as senhas seed antes de qualquer uso com dados reais.
