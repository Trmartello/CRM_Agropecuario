<?php use App\Core\Permissoes; ?>

<div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
  <form class="flex-grow-1" method="get" action="index.php">
    <input type="hidden" name="r" value="clientes">
    <div class="input-group">
      <span class="input-group-text"><i class="bi bi-search"></i></span>
      <input type="search" name="busca" class="form-control" placeholder="Buscar por nome, município ou CPF/CNPJ…" value="<?= e($busca) ?>">
      <select name="segmento" class="form-select" style="max-width:170px" onchange="this.form.submit()" title="Filtrar por segmento">
        <option value="">Segmento: todos</option>
        <?php foreach (\App\Services\SegmentacaoService::ROTULOS as $sig => $rot): ?>
          <option value="<?= $sig ?>" <?= $segmentoFiltro === $sig ? 'selected' : '' ?>><?= e($rot) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-outline-secondary">Buscar</button>
    </div>
  </form>
  <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalCliente" onclick="Clientes.novo()">
    <i class="bi bi-person-plus me-1"></i>Novo Cliente
  </button>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Produtor</th>
          <th class="d-none d-md-table-cell">Segmento</th>
          <th class="d-none d-md-table-cell">Município</th>
          <th class="d-none d-md-table-cell">Situação</th>
          <th class="d-none d-lg-table-cell">Nível tec.</th>
          <th class="d-none d-lg-table-cell">Última visita</th>
          <th class="text-end">Ações</th>
        </tr>
      </thead>
      <tbody id="tabelaClientes">
        <?php if (!$clientes): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">Nenhum cliente encontrado.</td></tr>
        <?php endif; ?>
        <?php foreach ($clientes as $c): ?>
        <tr>
          <td>
            <div class="fw-semibold"><?= e($c['nome']) ?>
              <?php if (!empty($c['prospecto'])): ?><span class="badge text-bg-warning ms-1"><i class="bi bi-star-half me-1"></i>Prospecto</span><?php endif; ?>
              <span class="d-md-none ms-1"><?= selo_segmento($c['segmento_manual'] ?? null, $c['segmento'] ?? null, true) ?></span>
            </div>
            <div class="small text-muted d-md-none"><?= e($c['municipio'] ?? '') ?></div>
          </td>
          <td class="d-none d-md-table-cell"><?= selo_segmento($c['segmento_manual'] ?? null, $c['segmento'] ?? null) ?: '<span class="text-muted">—</span>' ?></td>
          <td class="d-none d-md-table-cell"><?= e($c['municipio'] ?? '—') ?></td>
          <td class="d-none d-md-table-cell">
            <span class="badge text-bg-<?= $c['situacao'] === 'Associado' ? 'success' : 'secondary' ?>"><?= e($c['situacao']) ?></span>
          </td>
          <td class="d-none d-lg-table-cell"><?= e($c['nivel_tecnologico']) ?></td>
          <td class="d-none d-lg-table-cell"><?= data_br($c['ultima_visita']) ?></td>
          <td class="text-end text-nowrap">
            <button class="btn btn-sm btn-outline-success" onclick="Clientes.ficha(<?= $c['id'] ?>)" title="Ficha completa"><i class="bi bi-folder2-open"></i><span class="d-none d-md-inline ms-1">Ficha</span></button>
            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modalCliente" onclick="Clientes.editar(<?= $c['id'] ?>)" title="Editar"><i class="bi bi-pencil"></i></button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Painel lateral: ficha do cliente -->
<div class="offcanvas offcanvas-end offcanvas-ficha" tabindex="-1" id="painelFicha">
  <div class="offcanvas-header border-bottom">
    <h5 class="offcanvas-title"><i class="bi bi-folder2-open me-2 text-success"></i>Ficha do Produtor</h5>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
  </div>
  <div class="offcanvas-body" id="painelFichaCorpo">
    <div class="text-center py-5 text-muted"><div class="spinner-border text-success"></div><p class="mt-2">Carregando…</p></div>
  </div>
</div>

<?php require __DIR__ . '/partials/modal_cliente.php'; ?>
<?php require __DIR__ . '/partials/modal_propriedade.php'; ?>
<?php require __DIR__ . '/partials/modal_imovel.php'; ?>
<?php require __DIR__ . '/partials/modal_plantar_area.php'; ?>
<?php require __DIR__ . '/partials/modal_plano_safra.php'; ?>
<?php require __DIR__ . '/partials/modal_plantio.php'; ?>
<?php require __DIR__ . '/partials/modal_croqui.php'; ?>
<?php /* DEPOIS do croqui: o modal do talhão abre por cima do croqui em tela cheia
         (novo talhão desenhado) — mesmo z-index, quem vem depois no DOM fica na frente. */ ?>
<?php require __DIR__ . '/partials/modal_talhao.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
  <?php if (isset($_GET['novo'])): ?> new bootstrap.Modal('#modalCliente').show(); Clientes.novo(); <?php endif; ?>
});
</script>
