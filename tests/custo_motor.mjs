/**
 * Golden tests do motor de custo em JS (espelho do PHP) + cross-check bit a bit.
 *   node tests/custo_motor.mjs
 * Requer que tests/custo_motor.php tenha rodado antes (gera custo_motor_php.json).
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { createRequire } from 'node:module';

const __dirname = dirname(fileURLToPath(import.meta.url));
const require = createRequire(import.meta.url);
const M = require(join(__dirname, '..', 'public', 'assets', 'js', 'custo-motor.js'));

const TOL = 0.01;
let falhas = 0;

function quase(got, esp, nome) {
  let ok;
  if (esp === null) ok = got === null;
  else if (got === null) ok = false;
  else ok = Math.abs(got - esp) <= TOL;
  console.log(`  [${ok ? 'OK ' : 'XXX'}] ${nome.padEnd(26)} got=${got === null ? 'null' : (+got).toFixed(4)} esp=${esp}`);
  if (!ok) falhas++;
}
function eq(got, esp, nome) {
  const ok = got === esp;
  console.log(`  [${ok ? 'OK ' : 'XXX'}] ${nome.padEnd(26)} got=${JSON.stringify(got)} esp=${JSON.stringify(esp)}`);
  if (!ok) falhas++;
}

// ---------- Caso A (valores corrigidos — ver spec §4) ----------
console.log('=== Caso A — soja, base CT ===');
const a = M.calcular(64, 60, 132, 7360, 0.40, 131);
quase(a.custo_total, 471040.00, 'custo_total');
quase(a.producao_total, 3840, 'producao_total');
quase(a.preco_equilibrio, 122.67, 'preco_equilibrio');
quase(a.produtividade_equilibrio, 55.76, 'produtividade_equilibrio');
quase(a.sacas_equilibrio, 3595.73, 'sacas_equilibrio');
quase(a.pct_equilibrio, 0.9364, 'pct_equilibrio');
quase(a.sacas_travadas, 1536, 'sacas_travadas');
quase(a.receita_travada, 201216.00, 'receita_travada');
quase(a.cobertura_custo, 0.4272, 'cobertura_custo');

// ---------- Caso B ----------
console.log('=== Caso B — milho, base CT ===');
const b = M.calcular(64, 160, 62, 8550);
quase(b.preco_equilibrio, 53.44, 'preco_equilibrio');
quase(b.produtividade_equilibrio, 137.90, 'produtividade_equilibrio');

// ---------- Caso C ----------
console.log('=== Caso C — bordas ===');
quase(M.calcular(64, 0, 132, 7360, 0.40, 131).preco_equilibrio, null, 'P=0 -> preco_eq null');
quase(M.calcular(64, 60, 132, 7360, 0.40, 0).sacas_equilibrio, null, 'Pt=0 -> sacas_eq null');
quase(M.calcular(64, 60, 132, 7360, 0.0, 131).cobertura_custo, 0, 't=0 -> cobertura 0');
eq(M.calcular(64, 60, 132, 7360, 1.0, 131).alerta_entrega, true, 't=1.0 -> alerta');
eq(M.calcular(64, 60, 132, 7360, 0.40, 100).veredito, 'abaixo_equilibrio', 'Pt<eq -> abaixo');

// ---------- Caso D — matriz ----------
console.log('=== Caso D — matriz ===');
const mz = M.matriz(64, 60, 132, 7360, 0.40, 131);
let cel = null;
for (const linha of mz) for (const x of linha.celulas) {
  if (Math.abs(x.delta_preco) < 1e-9 && Math.abs(x.delta_produtividade + 0.30) < 1e-9) cel = x;
}
quase(cel.producao_cenario, 2688, 'producao_cenario');
quase(cel.sacas_entregues, 1536, 'sacas_entregues');
eq(cel.sacas_entregues <= cel.producao_cenario, true, 'nunca entrega > colheita');

// ---------- Cross-check bit a bit: JS === PHP ----------
console.log('=== Cross-check JS x PHP (mesmos inputs, tolerância 1e-9) ===');
const php = JSON.parse(readFileSync(join(__dirname, 'custo_motor_php.json'), 'utf8'));
eq(M.VERSAO, php.versao, 'VERSAO igual');

function comparaObj(js, ph, rot) {
  for (const k of Object.keys(ph)) {
    const vj = js[k], vp = ph[k];
    let ok;
    if (vp === null || typeof vp === 'boolean' || typeof vp === 'string') ok = vj === vp;
    else ok = Math.abs(vj - vp) <= 1e-9;
    if (!ok) { console.log(`  [XXX] ${rot}.${k} js=${vj} php=${vp}`); falhas++; }
  }
}
comparaObj(M.calcular(64, 60, 132, 7360, 0.40, 131), php.A, 'A');
comparaObj(M.calcular(64, 160, 62, 8550), php.B, 'B');
comparaObj(M.calcular(64, 0, 132, 7360, 0.40, 131), php.C_P0, 'C_P0');
comparaObj(M.calcular(64, 60, 132, 7360, 0.40, 0), php.C_Pt0, 'C_Pt0');
// matriz completa
const mjs = M.matriz(64, 60, 132, 7360, 0.40, 131);
let celulas = 0;
for (let i = 0; i < mjs.length; i++) for (let j = 0; j < mjs[i].celulas.length; j++) {
  comparaObj(mjs[i].celulas[j], php.matrizA[i].celulas[j], `matriz[${i}][${j}]`);
  celulas++;
}
console.log(`  [OK ] matriz comparada célula a célula (${celulas} células)`);

console.log('\n' + (falhas === 0 ? '>>> GOLDEN JS + CROSS-CHECK: TODOS PASSARAM <<<' : `>>> GOLDEN JS: ${falhas} FALHA(S) <<<`));
process.exit(falhas === 0 ? 0 : 1);
