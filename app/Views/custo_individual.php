<?php
/**
 * Gerencial › Custo individual (nf-ingestao §8/§9 — governança da Diretoria).
 * Só Diretoria/Controladoria/Gestor Comercial chegam aqui (gate no controller);
 * toda consulta exige MOTIVO e fica na auditoria. O perfil de campo nunca vê
 * esta tela (para ele existe só o agregado k-anônimo).
 */
$rotGrupo = ['coe' => 'COE', 'cot' => 'COT', 'ct' => 'CT'];
?>
<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link" href="<?= url('gerencial') ?>"><i class="bi bi-speedometer2 me-1"></i>Painel</a></li>
  <li class="nav-item"><a class="nav-link" href="<?= url('gerencial/desempenho') ?>"><i class="bi bi-trophy me-1"></i>Desempenho</a></li>
  <li class="nav-item"><a class="nav-link" href="<?= url('gerencial/custo-regional') ?>"><i class="bi bi-bar-chart-steps me-1"></i>Custo regional</a></li>
  <li class="nav-item"><a class="nav-link active"><i class="bi bi-person-lock me-1"></i>Custo individual</a></li>
  <?php if (\App\Core\Auth::perfil() === 'Administrador'): ?>
    <li class="nav-item"><a class="nav-link" href="<?= url('gerencial/auditoria-campo') ?>"><i class="bi bi-shield-exclamation me-1"></i>Auditoria de campo</a></li>
  <?php endif; ?>
</ul>

<div class="alert alert-warning small">
  <i class="bi bi-shield-lock me-1"></i>
  <strong>Dado sob governança.</strong> Esta tela mostra o custo identificável digitado pelo
  cooperado no Portal — visível só a Diretoria, Controladoria e Gestor Comercial, com a
  ciência do produtor obtida no opt-in. <strong>Toda consulta exige motivo e fica registrada
  na auditoria</strong> (usuário, data, produtor e motivo).
</div>

<form method="get" class="row g-2 align-items-end mb-3">
  <input type="hidden" name="r" value="gerencial/custo-individual">
  <div class="col-12 col-md-4">
    <label class="form-label small mb-1">Produtor</label>
    <select name="produtor" class="form-select">
      <option value="">— escolher —</option>
      <?php foreach ($produtores as $p): ?>
      <option value="<?= (int) $p['id'] ?>" <?= $produtorId === (int) $p['id'] ? 'selected' : '' ?>>
        <?= e($p['nome']) ?><?= $p['municipio'] ? ' · ' . e($p['municipio']) : '' ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-6 col-md-2">
    <label class="form-label small mb-1">Safra</label>
    <select name="safra" class="form-select">
      <option value="">Todas</option>
      <?php foreach ($safras as $s): ?>
      <option value="<?= e($s) ?>" <?= $safra === $s ? 'selected' : '' ?>><?= e($s) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-12 col-md-4">
    <label class="form-label small mb-1">Motivo da consulta <span class="text-danger">*</span></label>
    <input name="motivo" class="form-control <?= $erroMotivo ? 'is-invalid' : '' ?>" required minlength="5"
           maxlength="200" placeholder="ex.: análise de crédito da safra" value="<?= e($motivo) ?>">
    <?php if ($erroMotivo): ?><div class="invalid-feedback">Informe o motivo (mínimo 5 caracteres) — ele vai para a auditoria.</div><?php endif; ?>
  </div>
  <div class="col-12 col-md-2">
    <button class="btn btn-success w-100"><i class="bi bi-search me-1"></i>Consultar</button>
  </div>
</form>

<?php if ($visao !== null): ?>
  <?php if (!$visao): ?>
  <div class="text-muted">Este produtor ainda não tem lavoura no módulo de custo<?= $safra ? ' nesta safra' : '' ?>.</div>
  <?php endif; ?>
  <?php foreach ($visao as $v): $l = $v['lavoura']; ?>
  <div class="card mb-3">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
      <strong><?= e($l['cultura']) ?> · <?= e($l['safra']) ?> · <?= e((string) $l['areaHa']) ?> ha</strong>
      <span class="small text-muted">
        <?= e((string) $l['produtividade']) ?> sc/ha esperados · referência R$ <?= number_format($l['precoReferencia'], 2, ',', '.') ?>/sc
        <?php if ($v['ultimo_cenario']): ?> · cenário "<?= e($v['ultimo_cenario']['nome']) ?>" (motor v<?= e($v['ultimo_cenario']['versao_motor']) ?>)<?php endif; ?>
      </span>
    </div>
    <div class="card-body py-2">
      <div class="row g-3 mb-2">
        <div class="col-6 col-md-3"><div class="small text-muted">COE</div><strong>R$ <?= number_format($v['somas']['coe'], 2, ',', '.') ?>/ha</strong></div>
        <div class="col-6 col-md-3"><div class="small text-muted">COT</div><strong>R$ <?= number_format($v['somas']['cot'], 2, ',', '.') ?>/ha</strong></div>
        <div class="col-6 col-md-3"><div class="small text-muted">CT</div><strong>R$ <?= number_format($v['somas']['ct'], 2, ',', '.') ?>/ha</strong></div>
        <div class="col-6 col-md-3"><div class="small text-muted">Preço de equilíbrio (base <?= strtoupper(e($l['base'])) ?>)</div>
          <strong><?= $v['calculo']['preco_equilibrio'] === null ? '—' : 'R$ ' . number_format($v['calculo']['preco_equilibrio'], 2, ',', '.') . '/sc' ?></strong></div>
      </div>
      <?php if ($v['itens']): ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light"><tr><th>Item</th><th>Base</th><th class="text-end">R$/ha</th><th>Fonte</th></tr></thead>
          <tbody>
            <?php foreach ($v['itens'] as $i): ?>
            <tr>
              <td><?= e($i['descricao']) ?></td>
              <td class="small text-muted"><?= e($rotGrupo[$i['grupo']] ?? $i['grupo']) ?></td>
              <td class="text-end"><?= number_format((float) $i['valor_ha'], 2, ',', '.') ?></td>
              <td><span class="badge text-bg-light border"><?= e($i['fonte']) ?></span></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?><div class="small text-muted">Sem itens de custo preenchidos.</div><?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
<?php endif; ?>
