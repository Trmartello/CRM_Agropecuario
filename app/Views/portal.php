<?php if (!empty($semVinculo)): ?>
<div class="alert alert-warning"><i class="bi bi-info-circle me-2"></i>Seu usuário ainda não está vinculado a um cadastro de produtor. Fale com seu consultor Copérdia.</div>
<?php return; endif; ?>

<div class="card mb-3">
  <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
      <h5 class="mb-0"><i class="bi bi-person-badge me-2 text-success"></i><?= e($cliente['nome']) ?></h5>
      <div class="small text-muted"><?= e($cliente['municipio'] ?? '') ?><?= $cliente['estado'] ? '/' . e($cliente['estado']) : '' ?> · <?= e($cliente['situacao']) ?></div>
    </div>
    <?php $inad = $painel['inadimplencia'] ?? null; if ($inad): ?>
    <span class="badge fs-6 text-bg-<?= $inad['grau'] === 'Adimplente' ? 'success' : ($inad['grau'] === 'Grave' ? 'danger' : 'warning') ?>"><?= e($inad['grau']) ?></span>
    <?php endif; ?>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-clipboard2-pulse me-2 text-success"></i><strong>Minhas visitas técnicas</strong></div>
      <div class="list-group list-group-flush" style="max-height:320px;overflow:auto">
        <?php if (!$visitas): ?><div class="list-group-item text-muted small">Nenhuma visita registrada.</div><?php endif; ?>
        <?php foreach ($visitas as $v): ?>
        <div class="list-group-item">
          <div class="d-flex justify-content-between"><strong><?= data_br($v['data_visita']) ?></strong><span class="small text-muted"><?= e($v['tecnico']) ?></span></div>
          <div class="small text-muted"><?= e($v['propriedade'] ?? '') ?><?= $v['cultura'] ? ' · ' . e($v['cultura']) : '' ?></div>
          <?php if ($v['recomendacao']): ?><div class="small mt-1 p-2 bg-success-subtle rounded"><i class="bi bi-file-earmark-medical me-1"></i><?= nl2br(e($v['recomendacao'])) ?></div><?php endif; ?>
          <?php $fv = $fotosPorVisita[(int) $v['id']] ?? []; if ($fv): ?>
          <div class="d-flex gap-2 mt-2 flex-wrap">
            <?php foreach ($fv as $arq): ?>
              <a href="<?= e(upload_url($arq)) ?>" target="_blank"><img src="<?= e(upload_url($arq, true)) ?>" class="foto-miniatura" alt="Foto da visita" loading="lazy" onerror="App.fotoIndisponivel(this)"></a>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-6 d-flex flex-column gap-3">
    <div class="card">
      <div class="card-header"><i class="bi bi-cart3 me-2 text-success"></i><strong>Meus pedidos</strong></div>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th>Data</th><th>Tipo</th><th>Status</th><th class="text-end">Valor</th></tr></thead>
        <tbody>
          <?php if (!$pedidos): ?><tr><td colspan="4" class="text-muted small">Nenhum pedido.</td></tr><?php endif; ?>
          <?php foreach ($pedidos as $p): ?>
          <tr><td><?= data_br($p['criado_em']) ?></td><td class="small"><?= e($p['tipo']) ?></td>
            <td><span class="badge text-bg-light border text-dark"><?= e($p['status']) ?></span></td>
            <td class="text-end"><?= moeda($p['valor_total']) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>

    <div class="card">
      <div class="card-header"><i class="bi bi-truck me-2 text-success"></i><strong>Minhas entregas futuras</strong></div>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th>Produto</th><th class="text-end">Contratado</th><th class="text-end">Pendente</th><th>Previsão</th></tr></thead>
        <tbody>
          <?php if (empty($entregas)): ?><tr><td colspan="4" class="text-muted small">Sem contratos de entrega futura.</td></tr><?php endif; ?>
          <?php foreach ($entregas ?? [] as $ef): ?>
          <tr>
            <td class="small"><?= e($ef['produto']) ?></td>
            <td class="text-end small"><?= numero($ef['quantidade_contratada'], 0) ?> <?= e($ef['unidade'] ?? '') ?></td>
            <td class="text-end fw-semibold small"><?= numero($ef['quantidade_pendente'], 0) ?></td>
            <td class="small"><?= $ef['previsao_entrega'] ? data_br($ef['previsao_entrega']) : '—' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>

    <div class="card">
      <div class="card-header"><i class="bi bi-cash-coin me-2 text-success"></i><strong>Meu financeiro</strong></div>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th>Vencimento</th><th>Situação</th><th class="text-end">Valor</th></tr></thead>
        <tbody>
          <?php if (!$titulos): ?><tr><td colspan="3" class="text-muted small">Sem títulos.</td></tr><?php endif; ?>
          <?php foreach ($titulos as $t): ?>
          <tr><td><?= data_br($t['vencimento']) ?></td>
            <td><span class="badge text-bg-<?= $t['situacao'] === 'Pago' ? 'success' : ($t['situacao'] === 'Em atraso' ? 'danger' : 'warning') ?>"><?= e($t['situacao']) ?></span></td>
            <td class="text-end"><?= moeda($t['valor']) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>

    <div class="card">
      <div class="card-header"><i class="bi bi-folder2-open me-2 text-success"></i><strong>Meus documentos</strong></div>
      <div class="list-group list-group-flush">
        <?php if (!$documentos): ?><div class="list-group-item text-muted small">Nenhum documento.</div><?php endif; ?>
        <?php foreach ($documentos as $d): ?>
        <a class="list-group-item list-group-item-action d-flex align-items-center gap-2" href="<?= url('clientes/baixar-documento', ['id' => $d['id']]) ?>" target="_blank">
          <i class="bi bi-file-earmark-text text-success"></i>
          <div class="flex-grow-1"><?= e($d['nome']) ?><div class="small text-muted"><?= e($d['tipo']) ?> · <?= data_br($d['criado_em']) ?></div></div>
          <i class="bi bi-download text-muted"></i>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
