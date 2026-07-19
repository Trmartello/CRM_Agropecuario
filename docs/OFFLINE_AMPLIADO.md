# Offline Ampliado — Plano

Plano para evoluir o **offline básico da Fase 1** (só criação de Visitas) para
um offline útil no campo: **leitura da carteira sem sinal** e **criação offline
de despesas, agenda e reclamações**, com sincronização transparente e sem
duplicatas. Documento de planejamento — implementar por fases, validando cada
uma com o usuário.

## 1. Estado atual (ponto de partida)

- **Service worker** (`public/sw.js`, cache `crm-coperdia-vNN`): assets *cache-first*;
  páginas *rede-primeiro* (a última versão vista de cada tela abre offline).
- **Fila offline** (`public/assets/js/offline.js` + IndexedDB `crm_coperdia` v1,
  store `fila_visitas`): ao salvar visita sem sinal, grava campos + fotos (Blobs)
  na fila; ao reconectar reenvia para `visitas/salvar` e remove da fila ao receber `ok`.
- **Gatilhos de sync**: evento `online` e ~1,5 s após cada carregamento de página.
- **Indicadores**: selo "Offline" e "Sincronizando…" no topo (`layout.php`).
- **Limite**: só Visitas são criadas offline; não há snapshot da carteira em IndexedDB.

## 2. Arquitetura proposta

### A. Fila de sincronização genérica (escrita offline)
- Substituir `fila_visitas` por store genérica **`fila_sync`** (migrar `DB_VERSAO` 1→2,
  criando a nova store e drenando a antiga).
- Registro:
  ```js
  { id, rota, modulo, rotulo, campos:{}, arquivos:[{campo,nome,tipo,blob}], uuid, criado_em }
  ```
  - `rota`: ex. `despesas/salvar-km`, `despesas/salvar-refeicao`, `agenda/salvar`,
    `agenda/status`, `reclamacoes/salvar`, `visitas/salvar`.
  - `rotulo`: texto amigável para listar pendências ("KM 12/07 — 45 km").
  - `uuid`: id gerado no cliente para **deduplicação idempotente** no servidor.
- Helper único `Offline.enfileirar(rota, form, {modulo, rotulo})` reaproveitado por
  todos os `salvar` elegíveis. Cada `Modulo.salvar()` passa a: `!navigator.onLine`
  → enfileira; senão envia normal, **com fallback para a fila em erro de rede**.

### B. Sincronização robusta
- `Offline.sincronizar()` genérico: lê a fila ordenada por `criado_em`, reenvia
  cada item para sua `rota`; `ok` → remove; **erro de rede** → para e tenta depois;
  **erro de negócio (validação)** → move para "falhas" e avisa (não trava a fila).
- Gatilhos: evento `online`, após load (já existe) e **Background Sync API**
  (`SyncManager`) quando suportado, com fallback ao mecanismo atual.
- **Indicador com contador de pendências** + painel "Pendências de envio" (listar
  `rotulo`, tentar de novo, descartar).

### C. Leitura offline da carteira (snapshot)
- Novo endpoint **`sync/carteira`** devolvendo o snapshot do que o técnico precisa
  no campo: produtores da carteira (id, nome, telefone, município, linha,
  coordenadas), última visita/completude, priorização (score/dias), e dados de
  apoio (veículos, filiais, categorias de reembolso, culturas, famílias, modelos
  de recomendação, propriedades/talhões da carteira).
- Guardar em stores de leitura no IndexedDB (`carteira`, `referencias`) com
  `atualizado_em`; atualizar ao abrir online e periodicamente; badge
  "dados de DD/MM HH:MM".
- Telas de leitura (Produtores, Priorização, Organizador) e o **apoio do modal de
  visita** ganham uma **camada de dados**: tenta rede → *fallback* IndexedDB.
  Meta mínima: cadastrar uma visita 100% offline já com produtor/propriedade/talhão certos.

### D. Escopo por módulo (prioridade)
1. **Visitas** — já ok; manter.
2. **Despesas** — KM e refeição (`salvar-km`, `salvar-refeicao`): alto valor no campo.
   Cadastro de veículo offline é raro → manter online-preferencial.
3. **Agenda** — salvar evento e `status` (concluir parada do roteiro).
4. **Reclamações** — registrar (`salvar`) com anexos como Blobs.
5. **Fora do escopo inicial** (exigem estoque/crédito/consistência ao vivo):
   Pedidos, Pacotes, Funil, aprovações e prestação de contas → manter online.

## 3. Consistência, segurança e limites

- **Servidor é a autoridade**: toda regra (preço, reembolso, completude,
  priorização, amarração KM↔visita) é recalculada no reenvio; o cliente nunca
  decide valores. Mantém a diretriz anti-adulteração já vigente.
- **Idempotência**: `uuid` por registro enfileirado + coluna `uuid_offline` nas
  tabelas alvo para o servidor **deduplicar** reenvios (evita visita/despesa
  duplicada se o `ok` se perder na volta).
- **Sessão expirada offline**: reenvio pode dar 401 → detectar, pedir re-login e
  **preservar a fila** (nunca descartar dados por sessão vencida).
- **Dependências entre itens**: registros que dependem de id gerado por outro
  devem relaxar a amarração no offline e amarrar no servidor por data/usuário
  (como já é feito em KM↔visita).
- **Anexos**: Blobs no IndexedDB; monitorar cota (`navigator.storage.estimate`) e
  avisar antes de estourar.
- **Conflitos**: quase tudo é *insert* (risco baixo); para edições offline,
  "última escrita vence" com aviso.

## 4. Mudanças de schema (migração leve) — implementado

- **Tabela central `sync_processados` (uuid PK)** em vez de uma coluna por tabela
  (schema v13, `Instalador` V13 + `database.sql`). A idempotência é **atômica**:
  `sync_iniciar()` abre uma transação e insere o `uuid` como trava (PK) no início;
  o cadastro real (+ fotos/vínculos) roda na mesma transação e só é confirmado por
  `sync_confirmar()` no fim — uuid + registro + anexos são tudo-ou-nada (sem
  duplicata por crash entre statements, sem perda de anexos, e um erro de validação
  faz rollback do uuid, permitindo retry). Concorrência entre abas é travada com
  `navigator.locks`; `agenda/status` dispensa `uuid` (UPDATE idempotente). Limpeza
  periódica via `sync_limpar_antigos()` no login (`criado_em < NOW() - INTERVAL 90 DAY`).

## 5. Cache (service worker)

- Garantir que as páginas dos módulos elegíveis (despesas, agenda, reclamações,
  clientes) fiquem acessíveis offline (já ficam via rede-primeiro após a 1ª visita).
- A cada asset novo/mudança na lista, **incrementar a constante `CACHE` do `sw.js`**.

## 6. Fases de entrega

- **O1 — Fila genérica + Despesas/Agenda/Reclamações offline. ✅ ENTREGUE.**
  `fila_sync` no IndexedDB (v2), `App.enviarFormOffline`, painel de "Pendências
  de envio", auto-sync com guard de reentrância, e *runtime caching* dos assets
  versionados no service worker. Falta a idempotência (vai na O3).
- **O2 — Snapshot da carteira + leitura offline. ✅ ENTREGUE.** Endpoint
  `sync/carteira` (`SyncController`) com produtores já priorizados + apoio +
  culturas + modelos; store `snapshot` no IndexedDB (atualiza a cada load online
  e no evento `online`); fallback offline no apoio da Nova Visita; e `OfflineView`
  re-renderiza do snapshot as telas Produtores, Priorização e Organizador
  (sugestões) quando offline, com banner de modo offline. Ações que exigem
  servidor (ficha completa, montar/otimizar roteiro) seguem online.
- **O3 — Robustez. ✅ ENTREGUE.** Idempotência **atômica** (uuid + transação, ver
  seção 4), sem duplicata por crash/concorrência nem perda de anexos. Sessão
  expirada durante o sync mantém a fila e pede login. Aviso de cota ao enfileirar
  anexos. **Background Sync com replay headless no próprio SW**: o SW lê a
  `fila_sync` do IndexedDB e reenvia cada item mesmo com o app fechado (idempotência
  cobre eventual concorrência com um cliente aberto), depois avisa os clientes para
  atualizar a UI. O SW **não cacheia respostas JSON** (APIs autenticadas como
  `sync/carteira` nunca ficam no CacheStorage); o snapshot em IndexedDB é limpo no
  logout. Ressalva de plataforma: `SyncManager` não existe em iOS/PWA — lá o reenvio
  cai no evento `online` + timer de load (cobre o caso comum).

## 7. Testes (a cada fase)

- Simular offline (DevTools / `navigator.onLine`), criar registros em cada módulo,
  reconectar e conferir **sincronização e ausência de duplicatas**.
- Reenvio com sessão expirada durante o offline.
- Cota de armazenamento com muitas fotos.
- Reabrir o app offline e navegar/ler a carteira (O2).
- Regressão: garantir que o fluxo online de cada módulo continua idêntico.

## 8. Esforço/risco (resumo)

| Fase | Esforço | Risco | Observação |
|------|---------|-------|------------|
| O1   | médio   | baixo | generalizar fila + adaptar ~4 fluxos de salvar |
| O2   | médio   | médio | camada de leitura + endpoint snapshot + telas |
| O3   | médio   | médio | idempotência toca schema; Background Sync é progressivo |
