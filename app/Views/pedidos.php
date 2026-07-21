<?php use App\Core\Auth;
$cores = ['Rascunho' => 'secondary', 'Pendente de aprovação' => 'warning', 'Aprovado' => 'primary', 'Faturado' => 'success', 'Cancelado' => 'dark'];
$ehGestor = in_array(Auth::perfil(), ['Administrador', 'Gestor Comercial'], true);
?>

<div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
  <form class="flex-grow-1" method="get" action="index.php">
    <input type="hidden" name="r" value="pedidos">
    <div class="input-group">
      <span class="input-group-text"><i class="bi bi-search"></i></span>
      <input type="search" name="busca" class="form-control" placeholder="Buscar por cliente…" value="<?= e($busca) ?>">
      <button class="btn btn-outline-secondary">Buscar</button>
    </div>
  </form>
  <button class="btn btn-success" onclick="Pedidos.novo()"><i class="bi bi-cart-plus me-1"></i>Novo Pedido</button>
  <button class="btn btn-outline-success" onclick="Pedidos.novoPacote()"><i class="bi bi-box-seam me-1"></i>Pedido de Pacote</button>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr><th>#</th><th>Cliente</th><th class="d-none d-md-table-cell">Tipo</th><th>Status</th><th class="text-end">Total</th><th class="d-none d-lg-table-cell">Data</th><th class="text-end"></th></tr>
      </thead>
      <tbody>
        <?php if (!$pedidos): ?><tr><td colspan="7" class="text-center text-muted py-4">Nenhum pedido registrado.</td></tr><?php endif; ?>
        <?php foreach ($pedidos as $pe): ?>
        <tr>
          <td>#<?= (int) $pe['id'] ?></td>
          <td>
            <div class="fw-semibold"><?= e($pe['cliente']) ?></div>
            <div class="small text-muted d-md-none"><?= e($pe['tipo']) ?></div>
          </td>
          <td class="d-none d-md-table-cell">
            <?= e($pe['tipo']) ?>
            <?php if ($pe['pacote']): ?><div class="small text-muted"><?= e($pe['pacote']) ?></div><?php endif; ?>
          </td>
          <td><span class="badge text-bg-<?= $cores[$pe['status']] ?? 'secondary' ?>"><?= e($pe['status']) ?></span></td>
          <td class="text-end fw-semibold"><?= moeda($pe['valor_total']) ?></td>
          <td class="d-none d-lg-table-cell"><?= data_br(substr($pe['criado_em'], 0, 10)) ?></td>
          <td class="text-end text-nowrap">
            <button class="btn btn-sm btn-outline-success" onclick="Pedidos.detalhe(<?= $pe['id'] ?>)" title="Detalhe"><i class="bi bi-eye"></i></button>
            <?php if ($pe['status'] === 'Pendente de aprovação' && $ehGestor): ?>
              <button class="btn btn-sm btn-warning" onclick="Pedidos.aprovar(<?= $pe['id'] ?>)" title="Aprovar crédito"><i class="bi bi-check2-circle"></i></button>
            <?php endif; ?>
            <?php if ($pe['status'] === 'Aprovado' && $ehGestor): ?>
              <button class="btn btn-sm btn-primary" onclick="Pedidos.faturar(<?= $pe['id'] ?>)" title="Faturar"><i class="bi bi-receipt"></i></button>
            <?php endif; ?>
            <?php if (in_array($pe['status'], ['Rascunho', 'Pendente de aprovação', 'Aprovado'])): ?>
              <button class="btn btn-sm btn-outline-danger" onclick="Pedidos.cancelar(<?= $pe['id'] ?>)" title="Cancelar"><i class="bi bi-x-lg"></i></button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Painel lateral: detalhe -->
<div class="offcanvas offcanvas-end offcanvas-ficha" tabindex="-1" id="painelPedido">
  <div class="offcanvas-header border-bottom">
    <h5 class="offcanvas-title"><i class="bi bi-receipt me-2 text-success"></i>Pedido</h5>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
  </div>
  <div class="offcanvas-body" id="painelPedidoCorpo"></div>
</div>

<!-- Modal: pedido normal -->
<div class="modal fade" id="modalPedido" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-xl modal-fullscreen-md-down">
    <form class="modal-content" onsubmit="return Pedidos.salvar(event)">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-cart-plus me-2 text-success"></i>Novo Pedido</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3 mb-3">
          <div class="col-md-6">
            <label class="form-label">Cliente *</label>
            <select name="cliente_id" class="form-select" required>
              <option value="">Selecione…</option>
              <?php foreach ($clientes as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['nome']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Condição de pagamento</label>
            <input name="condicao_pagamento" class="form-control" placeholder="Safra, 30/60/90…">
          </div>
          <div class="col-md-3">
            <label class="form-label">Observação</label>
            <div class="campo-voz"><textarea name="observacao" rows="1" class="form-control auto-crescer" oninput="App.autoCrescer(this)"></textarea><button type="button" class="btn-voz" title="Ditar por voz" aria-label="Ditar por voz"><i class="bi bi-mic-fill"></i></button></div>
          </div>
        </div>

        <div class="d-flex gap-2 align-items-end mb-2 flex-wrap">
          <div class="flex-grow-1">
            <label class="form-label mb-1">Adicionar produto</label>
            <select id="pedidoProduto" class="form-select">
              <option value="">Selecione o produto…</option>
              <?php foreach ($catalogo as $p): ?>
                <option value="<?= $p['id'] ?>" data-preco="<?= $p['preco_referencia'] ?>" data-estoque="<?= $p['estoque'] ?>"
                        data-unidade="<?= e($p['unidade']) ?>" data-promocao="<?= $p['promocao_pct'] ?? '' ?>">
                  <?= e($p['familia']) ?> — <?= e($p['nome']) ?> (<?= moeda($p['preco_referencia']) ?> · estoque <?= numero($p['estoque'], 0) ?><?= $p['promocao_pct'] ? ' · PROMO −' . numero($p['promocao_pct'], 0) . '%' : '' ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div style="width:110px">
            <label class="form-label mb-1">Qtde</label>
            <input id="pedidoQtde" class="form-control" inputmode="decimal" value="1">
          </div>
          <button type="button" class="btn btn-outline-success" onclick="Pedidos.addItem()"><i class="bi bi-plus-lg"></i></button>
        </div>

        <div class="table-responsive">
          <table class="table table-sm align-middle" id="pedidoItens">
            <thead class="table-light"><tr><th>Produto</th><th class="text-end">Qtde</th><th class="text-end">Unitário</th><th class="text-end">Desc.</th><th class="text-end">Subtotal</th><th></th></tr></thead>
            <tbody></tbody>
            <tfoot><tr class="table-light"><th colspan="4" class="text-end">Total</th><th class="text-end" id="pedidoTotal">R$ 0,00</th><th></th></tr></tfoot>
          </table>
        </div>
        <p class="small text-muted mb-0"><i class="bi bi-info-circle me-1"></i>Cliente inadimplente ou acima do limite: o pedido entra como <strong>Pendente de aprovação</strong> do Gestor Comercial.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Emitir Pedido</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: pedido de Pacote Agrícola -->
<div class="modal fade" id="modalPacote" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-xl modal-fullscreen-md-down">
    <form class="modal-content" onsubmit="return Pedidos.salvarPacote(event)">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-box-seam me-2 text-success"></i>Pedido de Pacote Agrícola</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3 mb-3">
          <div class="col-md-4">
            <label class="form-label">Pacote *</label>
            <select name="pacote_id" id="pacoteSelect" class="form-select" required onchange="Pedidos.carregarPacote(this.value)">
              <option value="">Selecione…</option>
              <?php foreach ($pacotes as $pa): ?>
                <option value="<?= $pa['id'] ?>"><?= e($pa['nome']) ?> (<?= e($pa['cultura'] ?? '') ?> · <?= e($pa['safra'] ?? '') ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Cliente *</label>
            <select name="cliente_id" id="pacoteCliente" class="form-select" required onchange="Pedidos.avaliarPacote()">
              <option value="">Selecione…</option>
              <?php foreach ($clientes as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['nome']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label">Área (ha) *</label>
            <input name="area_ha" id="pacoteArea" class="form-control" inputmode="decimal" required onchange="Pedidos.sugerirQuantidades()">
          </div>
          <div class="col-md-2">
            <label class="form-label">Condição</label>
            <input name="condicao_pagamento" class="form-control" placeholder="Safra…">
          </div>
        </div>

        <div class="row g-3">
          <div class="col-lg-7">
            <div class="d-flex gap-2 align-items-end mb-2 flex-wrap">
              <div class="flex-grow-1">
                <label class="form-label mb-1">Adicionar produto</label>
                <select id="pacoteProduto" class="form-select">
                  <option value="">Selecione o produto…</option>
                  <?php foreach ($catalogo as $p): ?>
                    <option value="<?= $p['id'] ?>" data-preco="<?= $p['preco_referencia'] ?>" data-unidade="<?= e($p['unidade']) ?>">
                      <?= e($p['familia']) ?> — <?= e($p['nome']) ?> (<?= moeda($p['preco_referencia']) ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div style="width:110px">
                <label class="form-label mb-1">Qtde</label>
                <input id="pacoteQtde" class="form-control" inputmode="decimal" value="1">
              </div>
              <button type="button" class="btn btn-outline-success" onclick="Pedidos.addItemPacote()"><i class="bi bi-plus-lg"></i></button>
            </div>
            <div class="table-responsive">
              <table class="table table-sm align-middle" id="pacoteItens">
                <thead class="table-light"><tr><th>Produto</th><th class="text-end">Qtde</th><th class="text-end">Unitário</th><th class="text-end">Subtotal</th><th></th></tr></thead>
                <tbody></tbody>
              </table>
            </div>
          </div>

          <!-- Painel do pacote em tempo real -->
          <div class="col-lg-5">
            <div class="card border-success-subtle sticky-top" style="top:.5rem">
              <div class="card-header py-2 bg-success-subtle"><i class="bi bi-clipboard-data me-1"></i><strong>Painel do Pacote</strong></div>
              <div class="card-body" id="painelPacote">
                <p class="text-muted small mb-0">Escolha o pacote, o cliente e a área para acompanhar o atendimento das exigências em tempo real.</p>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-success" id="btnConcluirPacote"><i class="bi bi-check-lg me-1"></i>Concluir Pacote</button>
      </div>
    </form>
  </div>
</div>

<script src="assets/js/pedidos.js?v=<?= filemtime(dirname(__DIR__, 2) . '/public/assets/js/pedidos.js') ?>"></script>
