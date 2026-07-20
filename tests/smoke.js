/* Smoke test — CRM AGRO Copérdia
 *
 * Percorre o fluxo essencial de ponta a ponta num navegador real:
 * login → priorização → nova visita (100%) → despesa KM → evento de agenda →
 * reclamação → escrita offline + sincronização → auditoria (admin) → logout.
 *
 * Uso (requer Node + playwright-core e um Chromium):
 *   npm i playwright-core
 *   BASE_URL=http://127.0.0.1:8000 CHROMIUM=/opt/pw-browsers/chromium node tests/smoke.js
 *
 * ATENÇÃO: cria registros de teste (prefixo "SMOKE") — rode contra o banco de
 * desenvolvimento/homologação, nunca contra produção.
 */

'use strict';

const { chromium } = require('playwright-core');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8000';
const EXEC = process.env.CHROMIUM || '/opt/pw-browsers/chromium';
const USUARIO = process.env.SMOKE_EMAIL || 'vendedor@coperdia.com.br';
const SENHA = process.env.SMOKE_SENHA || 'coperdia123';
const ADMIN = process.env.SMOKE_ADMIN_EMAIL || 'admin@coperdia.com.br';
const ADMIN_SENHA = process.env.SMOKE_ADMIN_SENHA || 'coperdia123';

const MARCA = 'SMOKE ' + new Date().toISOString().slice(0, 16);
let falhas = 0;

function ok(nome, cond, extra = '') {
  const simbolo = cond ? '✓' : '✗';
  console.log(`${simbolo} ${nome}${extra ? ' — ' + extra : ''}`);
  if (!cond) falhas++;
}

async function login(p, email, senha) {
  await p.goto(BASE + '/index.php?r=login');
  await p.fill('[name=email]', email);
  await p.fill('[name=senha]', senha);
  await p.click('form button.btn-success');
  await p.waitForLoadState('networkidle');
}

(async () => {
  const browser = await chromium.launch({ executablePath: EXEC, args: ['--no-sandbox'] });
  const ctx = await browser.newContext({ viewport: { width: 1100, height: 900 } });
  const p = await ctx.newPage();
  const errosJs = [];
  p.on('pageerror', e => errosJs.push(e.message));
  p.on('dialog', async d => await d.accept());

  // 1. Login
  await login(p, USUARIO, SENHA);
  ok('login', p.url().includes('dashboard'));

  // 2. Priorização renderiza
  await p.goto(BASE + '/index.php?r=visitas');
  await p.waitForLoadState('networkidle');
  const linhasPrio = await p.$$eval('#tabelaPriorizacao tr', rs => rs.length).catch(() => 0);
  ok('priorização', linhasPrio > 0, `${linhasPrio} produtores`);

  // 3. Nova visita 100%
  await p.evaluate(() => Visitas.nova());
  await p.waitForTimeout(400);
  await p.selectOption('#visitaCliente', { index: 1 });
  await p.waitForTimeout(600);
  await p.selectOption('#visitaCultura', { index: 1 });
  await p.fill('[name=objetivo]', MARCA + ' visita');
  await p.click('#visitaEtapas [data-etapa="2"]');
  await p.fill('[name=desenvolvimento]', 'ok');
  await p.click('#visitaEtapas [data-etapa="3"]');
  await p.fill('[name=recomendacao]', 'ok');
  // captura a resposta do salvar (determinístico, sem depender da lista)
  const [respSalvar] = await Promise.all([
    p.waitForResponse(r => r.url().includes('visitas/salvar'), { timeout: 10000 }).catch(() => null),
    p.click('#btnSalvarVisita'),
  ]);
  let visitaSalva = false, detalheErro = 'sem resposta';
  if (respSalvar) {
    const d = await respSalvar.json().catch(() => null);
    visitaSalva = !!(d && d.ok);
    detalheErro = d ? (d.erro || 'id ' + d.id) : 'resposta inválida';
  }
  ok('nova visita salva', visitaSalva, detalheErro);
  await p.waitForURL(/r=visitas/, { timeout: 8000 }).catch(() => {});
  await p.waitForLoadState('networkidle');

  // 4. Despesa KM (via fetch com o form da página)
  await p.goto(BASE + '/index.php?r=despesas');
  await p.waitForLoadState('networkidle');
  const km = await p.evaluate(async () => {
    const fd = new FormData();
    fd.append('data', new Date().toISOString().slice(0, 10));
    fd.append('tipo_destino', 'Lugar');
    fd.append('destino', 'SMOKE percurso');
    fd.append('cliente_id', '0');
    fd.append('motivo', 'SMOKE deslocamento de teste');
    fd.append('km_inicial', '1000');
    fd.append('km_final', '1042');
    const r = await fetch('index.php?r=despesas/salvar-km', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' } });
    return r.json();
  }).catch(e => ({ ok: false, erro: String(e) }));
  ok('despesa KM', km && km.ok === true, km && km.erro ? km.erro : '');

  // 5. Evento de agenda
  await p.goto(BASE + '/index.php?r=agenda');
  await p.waitForLoadState('networkidle');
  const ev = await p.evaluate(async (marca) => {
    const fd = new FormData();
    fd.append('titulo', marca + ' evento');
    fd.append('tipo', 'Tarefa');
    fd.append('data', new Date().toISOString().slice(0, 10));
    const r = await fetch('index.php?r=agenda/salvar', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' } });
    return r.json();
  }, MARCA).catch(e => ({ ok: false }));
  ok('evento de agenda', ev && ev.ok === true);

  // 6. Reclamação (exige cliente da carteira: usa o primeiro do select da página)
  await p.goto(BASE + '/index.php?r=reclamacoes');
  await p.waitForLoadState('networkidle');
  const rec2 = await p.evaluate(async (marca) => {
    const sel = document.querySelector('#formReclamacao [name=cliente_id], [name=cliente_id]');
    const clienteId = sel && sel.options && sel.options.length > 1 ? sel.options[1].value : '1';
    const fd = new FormData();
    fd.append('cliente_id', clienteId);
    fd.append('tipo', 'Defensivos');
    fd.append('problema', marca + ' problema');
    fd.append('descricao', 'teste automatizado');
    const r = await fetch('index.php?r=reclamacoes/salvar', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' } });
    return r.json();
  }, MARCA).catch(e => ({ ok: false, erro: String(e) }));
  ok('reclamação', rec2 && rec2.ok === true, rec2 && rec2.erro ? rec2.erro : '');

  // 7. Offline: enfileira evento e sincroniza ao reconectar
  await p.goto(BASE + '/index.php?r=agenda');
  await p.waitForLoadState('networkidle');
  await ctx.setOffline(true);
  await p.waitForTimeout(200);
  await p.evaluate(async (marca) => {
    const fd = new FormData();
    fd.append('titulo', marca + ' offline');
    fd.append('tipo', 'Tarefa');
    fd.append('data', new Date().toISOString().slice(0, 10));
    await App.enviarFormOffline(fd, 'index.php?r=agenda/salvar', { modulo: 'Agenda', rotulo: 'smoke offline' });
  }, MARCA);
  const naFila = await p.evaluate(async () => (await Offline.listar()).length);
  await ctx.setOffline(false);
  await p.evaluate(() => Offline.sincronizar());
  await p.waitForTimeout(1500);
  const aposSync = await p.evaluate(async () => (await Offline.listar()).length);
  ok('offline: enfileirou', naFila >= 1, `${naFila} na fila`);
  ok('offline: sincronizou', aposSync === 0);

  // 8. Auditoria (admin)
  await p.goto(BASE + '/index.php?r=login/sair');
  await p.waitForLoadState('networkidle');
  await login(p, ADMIN, ADMIN_SENHA);
  await p.goto(BASE + '/index.php?r=auditoria');
  await p.waitForLoadState('networkidle');
  const eventosAud = await p.$$eval('tbody tr', rs => rs.length).catch(() => 0);
  ok('auditoria (admin)', eventosAud > 0, `${eventosAud} eventos`);

  // 9. Logout
  await p.goto(BASE + '/index.php?r=login/sair');
  await p.waitForLoadState('networkidle');
  ok('logout', p.url().includes('r=login'));

  ok('sem erros de JavaScript', errosJs.length === 0, errosJs.slice(0, 2).join(' | '));

  await browser.close();
  console.log(falhas === 0 ? '\nSMOKE OK — todos os passos passaram.' : `\nSMOKE FALHOU — ${falhas} passo(s) com problema.`);
  process.exit(falhas === 0 ? 0 : 1);
})().catch(e => { console.error('Erro fatal do smoke:', e); process.exit(1); });
