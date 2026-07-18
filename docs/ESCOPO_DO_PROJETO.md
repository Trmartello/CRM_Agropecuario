# Escopo do Projeto — CRM Agropecuário Copérdia

**Sistema de Gestão Comercial e Assistência Técnica Agrícola**

> Documento-base do desenvolvimento. Consolida a especificação funcional (19 módulos), os requisitos adicionais definidos em planejamento e o plano de fases. Toda evolução do sistema deve respeitar este escopo; mudanças de escopo devem ser registradas aqui.

---

## 1. Objetivo Geral

Desenvolver um aplicativo integrado para atender as áreas **Comercial**, **Assistência Técnica** e **Relacionamento com o Produtor** da Copérdia.

O sistema substituirá diversos aplicativos utilizados atualmente, centralizando todas as atividades do profissional de campo (engenheiro agrônomo, técnico extensionista, vendedor) em uma única plataforma: registro de visitas, vendas, recomendações técnicas, controle de despesas, abertura de laudos, acompanhamento comercial, gestão de metas, segurança de crédito e comunicação com o produtor.

Objetivos de negócio norteadores:

1. **Aumentar o relacionamento com o cliente** — histórico completo, priorização inteligente de visitas, detecção de risco de perda (churn).
2. **Maximizar o volume de vendas sem perder oportunidades** — funil de oportunidades, gap de recompra, potencial x realizado, planejamento de safra e gatilhos do calendário agronômico.
3. **Garantir segurança na venda e evitar inadimplência** — limite de crédito, score interno, alçadas de aprovação e garantias.

---

## 2. Diretrizes Técnicas

| Item | Definição |
|---|---|
| Backend | PHP 8 puro, MVC leve com roteador próprio (padrão Copérdia) |
| Banco de dados | MySQL/MariaDB — schema e seed em `database.sql` |
| Frontend | Bootstrap 5 + JavaScript vanilla (AJAX via `fetch`), Chart.js para gráficos |
| Assets | 100% servidos localmente (sem CDN — uso em campo com internet instável) |
| Autenticação | Sessão PHP, `password_hash`, controle por perfil |
| Idioma | 100% português (UI, banco, mensagens) |
| Segurança | PDO prepared statements sempre; saída escapada com `htmlspecialchars` |

### Diretrizes de UX (obrigatórias)

- **Mínimo de páginas/abas**: uma página por módulo principal; **todos os cadastros e edições em modais** abertos sobre a tela atual, salvando via AJAX sem navegar.
- Interface simples e rápida para o usuário de campo; mobile-first; modais em tela cheia no celular.
- **Comando de voz** (Web Speech API, pt-BR) nos campos de texto longos — o técnico fala em vez de digitar; fallback para digitação.
- **Geolocalização automática** em visitas e cadastros.
- Busca rápida em todas as listagens.

### Estratégia Web / Mobile / Offline

- **Web responsivo**: interface mobile-first (Bootstrap 5); o mesmo sistema atende desktop e celular pelo navegador.
- **Aplicativo mobile = PWA (Progressive Web App)**: o sistema web é instalável no Android e iOS ("Adicionar à tela inicial"), com ícone próprio e tela cheia, usando um único código. Manifest, ícones e service worker fazem parte da Fase 1. Câmera, GPS e microfone via APIs do navegador. App nativo em loja só será avaliado em fase futura, se surgir necessidade que o PWA não atenda.
- **Offline básico já na Fase 1**: service worker cacheia o app e os assets (abre sem sinal); dados da carteira do técnico (clientes, propriedades, talhões, modelos de recomendação) armazenados localmente em IndexedDB; **visitas e fotos registradas sem conexão entram em fila local e sincronizam automaticamente quando o sinal volta**. Offline completo (consultas comerciais, pedidos, despesas) evolui nas fases seguintes até a Fase 5.
- **Ambiente de testes: Railway** — hospedagem do PHP + MySQL para homologação, com HTTPS automático (pré-requisito para PWA, geolocalização, câmera e microfone no celular). A conexão com o banco lê variáveis de ambiente (`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`), com padrão local para desenvolvimento; uploads em volume persistente.

---

## 3. Perfis de Usuário

| Perfil | Acesso |
|---|---|
| Administrador | Total, incluindo gestão de usuários |
| Gestor Comercial | Equipe comercial, aprovações de crédito/alçada, metas da equipe |
| Gestor Técnico | Equipe técnica, laudos, recomendações |
| Consultor Técnico | Visitas, recomendações, histórico dos seus clientes |
| Vendedor | Carteira própria, funil, metas próprias (CAP) |
| Analista | Consultas e relatórios |
| Produtor | Portal do Produtor (apenas os próprios dados) — Fase 4 |

Regra geral: vendedor/técnico vê **apenas os próprios** dados de metas e carteira; gestores veem a equipe.

---

## 4. FASE 1 — MVP (em desenvolvimento)

### 4.1 Fundação
- Estrutura MVC: `public/index.php` (front controller), roteador, controllers, models (PDO), services, views.
- `database.sql` com schema completo + seed de demonstração (usuários de todos os perfis, clientes, safras, compras, visitas, casos de inadimplência/churn/gap).
- Layout único: sidebar responsiva + área de conteúdo + modais globais.
- **PWA**: `manifest.json`, ícones, service worker com cache do app e assets (abre offline).
- **Offline básico**: carteira do técnico em IndexedDB + fila de sincronização para visitas e fotos registradas sem conexão.
- **Deploy de testes no Railway**: PHP + MySQL com variáveis de ambiente (`DB_*`), HTTPS automático, volume persistente para uploads.

### 4.2 Perfis de Usuários (Módulo 19)
- Login/logout com sessão; 7 perfis.
- Gestão de usuários em modal (somente Administrador).
- Helper de permissões aplicado em todas as rotas.

### 4.3 Cadastro de Clientes (Módulo 2)
- Página com busca rápida e filtros (situação, cidade).
- Modal de cadastro/edição: dados cadastrais, Associado/Não Associado, CPF/CNPJ, telefones, endereço, geolocalização, responsáveis, município, estado, filial.
- Campos comerciais: nível tecnológico (Alto/Médio/Baixo), volume de compra anual, potencial de venda, limite de crédito.
- Ficha do cliente em painel interno (sem nova página): propriedades e talhões, cada um com modal próprio (talhão: área em ha, cultura).
- Histórico completo de relacionamento.

### 4.4 CRM de Visitas Técnicas (Módulo 5)
- Modal em etapas (wizard): identificação (cliente → propriedade → talhão → cultura, data/hora, objetivo) → avaliação técnica (estágio, desenvolvimento, pragas, doenças, plantas daninhas, deficiência nutricional, clima, observações) → recomendação técnica → fotos.
- Geolocalização capturada automaticamente.
- **Registro por voz**: microfone nos campos de texto (observações, avaliação, recomendação).
- **Modelos de recomendação pré-cadastrados** por cultura e categoria de produto: o técnico seleciona, o texto padrão é carregado, ele ajusta só o necessário e anexa à visita.
- Múltiplas fotos com preview, vinculadas à visita/cultura/talhão.
- **Painel comercial do produtor dentro da visita**:
  - Compras da safra corrente (produtos, quantidades, valores);
  - Gap de recompra: produtos comprados na safra passada e ainda não comprados nesta — sugestões de venda;
  - Grau de inadimplência com a Copérdia (Adimplente / leve / média / grave), valor em aberto e dias de atraso, com selo colorido.

### 4.5 Painel de Priorização de Visitas
- Lista de produtores a visitar **em ordem de prioridade**, com score combinando:
  - tempo desde a última visita (sem visita = prioridade máxima);
  - nível tecnológico;
  - volume de compras;
  - potencial de venda futura;
  - risco de churn (queda de compra).
- Pesos configuráveis; botão que abre o modal "Nova Visita" pré-preenchido.
- Top 5 no Dashboard + listagem completa na página de visitas.

### 4.6 Dashboard de Metas CAP (Copérdia Alta Performance)
- Por indicador: meta, realizado, % de atingimento, saldo restante, período.
- Cada vendedor vê **apenas as próprias metas**; gestores veem a equipe.
- Para cada indicador abaixo da meta: lista de produtores com maior potencial de contribuição para o atingimento (integrado à priorização de visitas).
- Dados oficiais virão do aplicativo CAPE (Fase 5); nesta fase, tabelas locais `metas_cap`/`realizado_cap` atrás do `CapService`.

### 4.7 Potencial x Realizado por Família de Produtos
- Potencial de compra por cliente e família (insumos agrícolas, rações, fertilizantes, adubos foliares, defensivos...).
- Gráfico por cliente: % do potencial efetivamente comprado em cada família.
- Relatório/dashboard de aproveitamento do potencial: ranking do maior ao menor % de utilização, com **troca de dimensão** na mesma tela (Produtor / Município / Estado / Filial) e filtros por safra e família.

### 4.8 Histórico Agronômico (Módulo 6)
- Linha do tempo por propriedade e por talhão: visitas, recomendações, fotos, evolução da cultura.

### 4.9 Dashboard Inicial (Módulo 1)
- Indicadores: visitas no mês, clientes visitados, fotos, pendências de aprovação, resumo CAP, alertas de churn e gatilhos de safra. (Pedidos, valor vendido, KM e refeições ativam nas fases seguintes.)
- Atalhos: Nova Visita, Novo Cliente, Consultar Cliente (+ Novo Pedido, Pacote Agrícola e Nova Reclamação nas fases seguintes).
- Bloco "Próximas visitas prioritárias" (top 5).

### 4.10 Crédito e Segurança de Venda
- **Limite de crédito** por cliente com saldo consumido (títulos em aberto + vendas a prazo).
- **Score de crédito interno A/B/C/D** (histórico de pagamento, inadimplência atual, volume, tempo de relacionamento, garantias vigentes).
- **Bloqueio e alçada**: cliente inadimplente ou acima do limite → operação "Pendente de aprovação" do Gestor Comercial (regra no `CreditoService`, aplicada às propostas na Fase 1 e aos pedidos na Fase 2).
- **Garantias**: CPR, penhor de safra, aval, hipoteca — com valor, vencimento, documento anexo e alerta de vencimento.

### 4.11 Funil de Oportunidades
- Pipeline: Identificada → Proposta → Negociação → Ganha / Perdida.
- Oportunidades **geradas automaticamente** por: gap de recompra, potencial não atendido por família e gatilhos do calendário agronômico; criação manual na visita.
- Propostas com validade; proposta vencendo gera pendência de follow-up.
- **Motivo de perda obrigatório** (preço, prazo, concorrente — qual —, desistência, clima) com relatório por região/família.
- Visão Kanban + valor total por estágio; cartão no Dashboard.

### 4.12 Anti-churn e Inteligência de Concorrência
- Alerta de **queda de compra** (>30% vs. mesmo período da safra anterior) → risco de churn → sobe na priorização de visitas.
- **Registro de concorrência** na visita: de quem o produtor compra, famílias e condições; relatório consolidado por região/concorrente/família.

### 4.13 Planejamento de Safra e Calendário Agronômico
- **Intenção de plantio** por cliente/propriedade (área por cultura) → projeção da demanda de insumos por família (dose média/ha configurável) vs. já comprado.
- **Calendário agronômico** com janelas por cultura/região (plantio, dessecação, fungicida, inseticida, cobertura...) → **gatilhos comerciais** ("clientes com soja plantada e sem fungicida comprado na janela") que geram alertas e oportunidades.

### 4.14 Schema antecipado
- Tabelas `pedidos`, `pacotes_agricolas`, `reclamacoes`, `quilometragem`, `refeicoes` já criadas no `database.sql` (sem telas), preparando as fases 2–4.

---

## 5. FASE 2 — Comercial completo

### 5.1 Pedidos (Módulo 4)
- Emissão de pedidos: **pedido normal** e **pedido de Pacote Agrícola**.
- Consultas na emissão: estoque, preço, promoções, histórico de compra.
- Aplicação automática das regras de crédito da Fase 1 (bloqueio/alçada).
- Integração direta com ERP (efetivada na Fase 5; até lá, tabelas locais).

### 5.2 Consulta Comercial (Módulo 8)
- Compras: histórico completo, produtos adquiridos, quantidades, valores.
- **Entrega futura**: produtos contratados, saldo, quantidade retirada, quantidade pendente.
- Pedidos: status, entregas, previsão.

### 5.3 Pacotes Agrícolas (Módulo 3)
- Cadastro de pacotes por cultura, safra, vigência, região e campanha.
- Composição por categorias (sementes, fertilizantes, herbicidas, fungicidas, inseticidas, nutrição, biológicos, adjuvantes), cada uma com desconto, bonificação, obrigatoriedade e quantidade mínima.
- **Produtos obrigatórios**: pacote não conclui se ausentes; sistema informa os faltantes.
- **Validação técnica automática** conforme área plantada: dose/ha, nº de aplicações, quantidade mínima e máxima.
- **Bonificação automática**: desconto aplicado, percentual atingido, bonificação em grãos, elegibilidade do produtor.
- **Painel do pacote em tempo real**: % concluído, categorias atendidas, itens faltantes, obrigatórios, bonificação prevista.

---

## 6. FASE 3 — Despesas, laudos e documentos

### 6.1 Registro de Quilometragem (Módulo 9)
- Veículo, data, KM inicial/final, cliente, destino, motivo.
- Cálculo automático de quilômetros rodados e despesas.

### 6.2 Controle de Refeições (Módulo 10)
- Data, cliente, estabelecimento, valor, justificativa.
- Integrado à prestação de contas.

### 6.3 Prestação de Contas (Módulo 11)
- Consolidação automática de KM + refeições + despesas.
- Geração da prestação mensal.

### 6.4 Gestão de Reclamações (Módulo 12)
- Abertura de laudos: produtor, produto, lote, nota fiscal, cultura, problema, descrição, fotos.
- Tipos: sementes, fertilizantes, defensivos, biológicos, outros.
- Fluxo: Técnico registra → Gestor analisa → procedente/improcedente → encaminhamento → Jurídico → indenização → encerramento.

### 6.5 Gestão Documental (Módulo 13)
- Anexos por produtor: fotos, PDFs, laudos, receitas, contratos.

---

## 7. FASE 4 — Relacionamento e gestão

### 7.1 Agenda (Módulo 14)
- Agenda integrada: visitas, lembretes, retorno ao cliente, notificações.

### 7.2 Mapa (Módulo 15)
- Mapa das visitas: localização dos clientes, roteiro, visitas realizadas e pendentes.

### 7.3 Dashboards Gerenciais (Módulo 16)
- **Comercial**: vendas, faturamento, visitas, conversão, ticket médio.
- **Assistência Técnica**: visitas, culturas, recomendações, fotos, produtividade.
- **Pacotes Agrícolas**: quantidade vendida, valor, bonificações, pacotes completos/incompletos.
- **Reclamações**: abertas, encerradas, tempo médio, por fornecedor/produto/cultura.

### 7.4 Notificações (Módulo 17)
- Destinatários: produtor, técnico, gestor.
- Eventos: visita agendada, entrega futura, aprovação de reclamação, pedido faturado, nova recomendação técnica, pendências do pacote agrícola.

### 7.5 Portal do Produtor (Módulo 7)
- Acesso do produtor a: histórico de visitas, recomendações, fotos, pedidos, entregas futuras, compras, pacotes agrícolas, documentos, notas fiscais.

---

## 8. FASE 5 — Integrações e campo avançado

### 8.1 Integração com ERP da Copérdia (Módulo 18)
Consultar e sincronizar: cadastro de clientes, associados, produtos, estoque, tabelas de preço, pedidos, notas fiscais, entregas futuras, financeiro, contas a receber, contas a pagar, prestação de contas, CRM, assistência técnica, cadastro de propriedades e talhões.

Os serviços `ComercialService`, `CapService` e `CreditoService` trocam a fonte local pelo ERP/CAPE **sem alterar as telas**.

### 8.2 Integração com o aplicativo CAPE
- Metas e realizados oficiais do programa Copérdia Alta Performance por vendedor.

### 8.3 Operação offline completa
- O offline básico (registro de visitas, fotos e carteira do técnico) nasce na Fase 1; nesta fase ele se estende a **todos os módulos**: consultas comerciais, pedidos, despesas, reclamações e documentos, com sincronização bidirecional com o ERP e resolução de conflitos.

---

## 9. Requisitos Gerais (todas as fases)

- Interface moderna, intuitiva e responsiva (Android, iOS e Web via navegador).
- Operação offline com sincronização automática (Fase 5).
- Geolocalização automática das visitas.
- Upload de fotos e documentos.
- Histórico completo de alterações (auditoria).
- Busca rápida em todas as telas.
- Painéis com gráficos e indicadores em tempo real.
- Controle de permissões por usuário/perfil.
- Integração nativa com o ERP da Copérdia (Fase 5).
- Alto desempenho: o profissional realiza todas as atividades de campo em um único aplicativo, sem retrabalho nem duplicidade de lançamentos.

---

## 10. Critérios de Aceite por Fase (resumo)

Cada fase só é considerada concluída quando:

1. Todos os fluxos da fase funcionam de ponta a ponta no navegador (desktop e mobile).
2. O seed de demonstração exercita todas as regras de negócio da fase (ex.: cliente inadimplente bloqueado, churn detectado, gap gerando oportunidade).
3. Perfis sem permissão são efetivamente bloqueados nas novas telas.
4. `database.sql` reimporta do zero sem erros.
5. Validação do usuário (Copérdia) sobre a fase antes de iniciar a próxima.
