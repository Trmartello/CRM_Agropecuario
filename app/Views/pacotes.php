<?php use App\Core\Auth; $podeEditar = in_array(Auth::perfil(), ['Administrador', 'Gestor Comercial'], true); ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <p class="text-muted mb-0">Composição, exigências e bonificação dos Pacotes Agrícolas. A venda é feita em <a href="<?= url('pedidos') ?>">Pedidos → Pedido de Pacote</a>.</p>
  <?php if ($podeEditar): ?>
    <button class="btn btn-success" onclick="Pacotes.novo()"><i class="bi bi-box-seam me-1"></i>Novo Pacote</button>
  <?php endif; ?>
</div>

<div class="row g-3">
  <?php if (!$pacotes): ?><div class="col-12 text-center text-muted py-5">Nenhum pacote cadastrado.</div><?php endif; ?>
  <?php foreach ($pacotes as $pa): ?>
  <div class="col-md-6 col-xl-4">
    <div class="card h-100 <?= $pa['ativo'] ? '' : 'opacity-50' ?>">
      <div class="card-header d-flex justify-content-between align-items-center py-2">
        <strong><?= e($pa['nome']) ?></strong>
        <?php if ($podeEditar): ?>
          <button class="btn btn-sm btn-outline-secondary" onclick="Pacotes.editar(<?= $pa['id'] ?>)"><i class="bi bi-pencil"></i></button>
        <?php endif; ?>
      </div>
      <div class="card-body py-2 small">
        <div class="mb-1"><i class="bi bi-flower3 me-1 text-success"></i><?= e($pa['cultura'] ?? '—') ?> · Safra <?= e($pa['safra'] ?? '—') ?></div>
        <div class="mb-1"><i class="bi bi-calendar-range me-1 text-success"></i><?= data_br($pa['vigencia_inicio']) ?> a <?= data_br($pa['vigencia_fim']) ?></div>
        <div class="mb-1"><i class="bi bi-geo me-1 text-success"></i><?= e($pa['regiao'] ?? '—') ?> · <?= e($pa['campanha'] ?? '') ?></div>
        <div class="d-flex gap-2 mt-2 flex-wrap">
          <span class="badge text-bg-light border text-dark"><?= $pa['qtd_categorias'] ?> categorias</span>
          <span class="badge text-bg-light border text-dark"><?= $pa['qtd_obrigatorios'] ?> obrigatórios</span>
          <span class="badge text-bg-success-subtle text-success border border-success"><?= numero($pa['bonificacao_sacas_ha'], 1) ?> sc/ha</span>
          <span class="badge text-bg-primary-subtle text-primary border border-primary"><?= $pa['qtd_vendas'] ?> vendas</span>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php if ($podeEditar): ?>
<!-- Modal: cadastro/edição do pacote -->
<div class="modal fade" id="modalPacoteCad" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-xl modal-fullscreen-md-down">
    <form class="modal-content" onsubmit="return Pacotes.salvar(event)">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-box-seam me-2 text-success"></i><span id="pacoteCadTitulo">Novo Pacote Agrícola</span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id" value="0">
        <div class="row g-3 mb-3">
          <div class="col-md-4">
            <label class="form-label">Nome *</label>
            <input name="nome" class="form-control" required>
          </div>
          <div class="col-md-2">
            <label class="form-label">Cultura</label>
            <select name="cultura_id" class="form-select">
              <option value="">—</option>
              <?php foreach ($culturas as $cu): ?><option value="<?= $cu['id'] ?>"><?= e($cu['nome']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label">Safra</label>
            <select name="safra_id" class="form-select">
              <option value="">—</option>
              <?php foreach ($safras as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['nome']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label">Vigência início</label>
            <input type="date" name="vigencia_inicio" class="form-control">
          </div>
          <div class="col-md-2">
            <label class="form-label">Vigência fim</label>
            <input type="date" name="vigencia_fim" class="form-control">
          </div>
          <div class="col-md-4">
            <label class="form-label">Região</label>
            <input name="regiao" class="form-control">
          </div>
          <div class="col-md-4">
            <label class="form-label">Campanha</label>
            <input name="campanha" class="form-control">
          </div>
          <div class="col-md-2">
            <label class="form-label">Bonificação (sc/ha)</label>
            <input name="bonificacao_sacas_ha" class="form-control" inputmode="decimal" value="0">
          </div>
          <div class="col-md-2">
            <label class="form-label">Situação</label>
            <select name="ativo" class="form-select"><option value="1">Ativo</option><option value="0">Inativo</option></select>
          </div>
        </div>

        <h6 class="text-success"><i class="bi bi-collection me-1"></i>Categorias (desconto · bonificação · obrigatoriedade · quantidade mínima)</h6>
        <div class="table-responsive mb-3">
          <table class="table table-sm align-middle" id="tabelaCategorias">
            <thead class="table-light">
              <tr><th></th><th>Família</th><th style="width:110px">Desc. %</th><th style="width:110px">Bonif. %</th><th style="width:110px">Obrigatória</th><th style="width:120px">Qtd. mínima</th></tr>
            </thead>
            <tbody>
              <?php foreach ($familias as $f): ?>
              <tr data-familia="<?= $f['id'] ?>">
                <td><input type="checkbox" class="form-check-input cat-incluir"></td>
                <td><?= e($f['nome']) ?></td>
                <td><input class="form-control form-control-sm cat-desconto" inputmode="decimal" value="0"></td>
                <td><input class="form-control form-control-sm cat-bonificacao" inputmode="decimal" value="0"></td>
                <td class="text-center"><input type="checkbox" class="form-check-input cat-obrigatoria"></td>
                <td><input class="form-control form-control-sm cat-minima" inputmode="decimal" value="0"></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <h6 class="text-success"><i class="bi bi-shield-check me-1"></i>Produtos obrigatórios + validação técnica</h6>
        <div class="d-flex gap-2 align-items-end mb-2 flex-wrap">
          <div class="flex-grow-1">
            <select id="obrigProduto" class="form-select form-select-sm">
              <option value="">Adicionar produto obrigatório…</option>
              <?php foreach ($produtos as $p): ?>
                <option value="<?= $p['id'] ?>" data-nome="<?= e($p['familia']) ?> — <?= e($p['nome']) ?>" data-unidade="<?= e($p['unidade']) ?>"><?= e($p['familia']) ?> — <?= e($p['nome']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="button" class="btn btn-sm btn-outline-success" onclick="Pacotes.addObrigatorio()"><i class="bi bi-plus-lg"></i></button>
        </div>
        <div class="table-responsive">
          <table class="table table-sm align-middle" id="tabelaObrigatorios">
            <thead class="table-light">
              <tr><th>Produto</th><th style="width:110px">Dose/ha</th><th style="width:100px">Aplicações</th><th style="width:110px">Qtd. mín</th><th style="width:110px">Qtd. máx</th><th></th></tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Salvar Pacote</button>
      </div>
    </form>
  </div>
</div>

<script src="assets/js/pacotes.js?v=<?= filemtime(dirname(__DIR__, 2) . '/public/assets/js/pacotes.js') ?>"></script>
<?php endif; ?>
