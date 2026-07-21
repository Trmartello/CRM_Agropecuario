<?php
/** Ficha completa do produtor — carregada via AJAX no offcanvas. */
$inad = $painel['inadimplencia'];
$credito = $painel['credito'];
$queda = $painel['queda'];
$corInad = $inad['cor'] === 'orange' ? 'warning' : $inad['cor'];
?>
<div class="mb-3">
  <div class="d-flex align-items-start justify-content-between gap-2 flex-wrap">
    <div>
      <h4 class="mb-0"><?= e($cliente['nome']) ?></h4>
      <div class="text-muted small">
        <?= e($cliente['situacao']) ?> · <?= e($cliente['municipio'] ?? '—') ?>/<?= e($cliente['estado'] ?? '') ?>
        <?= $cliente['cpf_cnpj'] ? ' · ' . e($cliente['cpf_cnpj']) : '' ?>
      </div>
    </div>
    <div class="d-flex gap-2 align-items-center flex-wrap">
      <a class="btn btn-sm btn-outline-success" target="_blank" title="Relatório de fechamento de safra (imprimível)"
         href="<?= url('relatorios/safra') ?>&cliente=<?= (int) $cliente['id'] ?>"><i class="bi bi-file-earmark-bar-graph me-1"></i>Fechamento de safra</a>
      <span class="fs-6"><?= selo_segmento($cliente['segmento_manual'] ?? null, $cliente['segmento'] ?? null) ?></span>
      <span class="badge fs-6 text-bg-<?= $corInad ?>" title="<?= $inad['inadimplente'] ? moeda($inad['valor_vencido']) . ' vencido há ' . $inad['dias_atraso'] . ' dias' : 'Sem títulos vencidos' ?>">
        <?= e($inad['grau']) ?>
      </span>
      <span class="badge fs-6 text-bg-<?= ['A' => 'success', 'B' => 'primary', 'C' => 'warning', 'D' => 'danger'][$credito['score']] ?>">Score <?= $credito['score'] ?></span>
    </div>
  </div>
  <?php if ($queda['risco_churn']): ?>
    <div class="alert alert-danger py-2 mt-2 mb-0">
      <i class="bi bi-graph-down-arrow me-1"></i>
      <strong>Risco de churn:</strong> compras <?= round($queda['queda'] * 100) ?>% abaixo do mesmo período da safra anterior
      (<?= moeda($queda['total_atual']) ?> vs. <?= moeda($queda['total_anterior_periodo']) ?>).
    </div>
  <?php endif; ?>
</div>

<ul class="nav nav-tabs nav-fill mb-3" role="tablist">
  <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#abaComercial"><i class="bi bi-cart me-1"></i>Comercial</button></li>
  <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#abaCredito"><i class="bi bi-cash-coin me-1"></i>Crédito</button></li>
  <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#abaPropriedades"><i class="bi bi-house me-1"></i>Propriedades</button></li>
  <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#abaHistorico"><i class="bi bi-clock-history me-1"></i>Histórico</button></li>
  <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#abaDocumentos"><i class="bi bi-folder2-open me-1"></i>Documentos</button></li>
</ul>

<div class="tab-content">
  <!-- ABA COMERCIAL -->
  <div class="tab-pane fade show active" id="abaComercial">
    <h6 class="text-success"><i class="bi bi-bar-chart me-1"></i>Potencial x Realizado por família (safra <?= e($painel['safra']['nome'] ?? '') ?>)</h6>
    <?php if ($potencial): ?>
      <canvas id="graficoPotencial" height="170"
              data-potencial='<?= json_attr($potencial) ?>'></canvas>
    <?php else: ?>
      <p class="text-muted small">Sem potencial cadastrado para a safra atual.</p>
    <?php endif; ?>

    <h6 class="text-success mt-4"><i class="bi bi-arrow-repeat me-1"></i>Gap de recompra — comprou na safra passada e ainda não nesta</h6>
    <?php if ($painel['gap_recompra']): ?>
      <ul class="list-group mb-3">
        <?php foreach ($painel['gap_recompra'] as $g): ?>
        <li class="list-group-item d-flex justify-content-between align-items-center">
          <div>
            <div class="fw-semibold"><?= e($g['produto']) ?></div>
            <div class="small text-muted"><?= e($g['familia']) ?> · comprou <?= numero($g['quantidade_anterior'], 1) ?> na safra passada</div>
          </div>
          <span class="badge text-bg-success-subtle text-success border border-success"><?= moeda($g['valor_anterior']) ?></span>
        </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p class="text-muted small">Sem gap — todos os produtos da safra passada já foram recomprados. 👏</p>
    <?php endif; ?>

    <h6 class="text-success"><i class="bi bi-bag-check me-1"></i>Compras da safra atual</h6>
    <?php if ($painel['compras_safra_atual']): ?>
      <div class="table-responsive mb-3">
        <table class="table table-sm align-middle">
          <thead class="table-light"><tr><th>Produto</th><th class="text-end">Qtde</th><th class="text-end">Valor</th><th>Data</th></tr></thead>
          <tbody>
            <?php foreach ($painel['compras_safra_atual'] as $co): ?>
            <tr>
              <td><?= e($co['produto']) ?> <span class="text-muted small">(<?= e($co['familia']) ?>)</span></td>
              <td class="text-end"><?= numero($co['quantidade'], 1) ?> <?= e($co['unidade']) ?></td>
              <td class="text-end"><?= moeda($co['valor_total']) ?></td>
              <td><?= data_br($co['data_compra']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <p class="text-muted small">Nenhuma compra registrada na safra atual.</p>
    <?php endif; ?>

    <h6 class="text-success"><i class="bi bi-truck me-1"></i>Entrega futura</h6>
    <?php if ($painel['entregas_futuras']): ?>
      <div class="table-responsive mb-3">
        <table class="table table-sm align-middle">
          <thead class="table-light"><tr><th>Produto</th><th class="text-end">Contratado</th><th class="text-end">Retirado</th><th class="text-end">Pendente</th><th>Previsão</th></tr></thead>
          <tbody>
            <?php foreach ($painel['entregas_futuras'] as $ef): ?>
            <tr>
              <td><?= e($ef['produto']) ?></td>
              <td class="text-end"><?= numero($ef['quantidade_contratada'], 1) ?> <?= e($ef['unidade']) ?></td>
              <td class="text-end"><?= numero($ef['quantidade_retirada'], 1) ?></td>
              <td class="text-end fw-semibold <?= $ef['quantidade_pendente'] > 0 ? 'text-warning' : 'text-success' ?>"><?= numero($ef['quantidade_pendente'], 1) ?></td>
              <td><?= data_br($ef['previsao_entrega']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <p class="text-muted small">Nenhum contrato de entrega futura.</p>
    <?php endif; ?>

    <h6 class="text-success"><i class="bi bi-receipt me-1"></i>Pedidos</h6>
    <?php if ($painel['pedidos']): $coresPed = ['Rascunho' => 'secondary', 'Pendente de aprovação' => 'warning', 'Aprovado' => 'primary', 'Faturado' => 'success', 'Cancelado' => 'dark']; ?>
      <ul class="list-group mb-3">
        <?php foreach (array_slice($painel['pedidos'], 0, 6) as $ped): ?>
        <li class="list-group-item d-flex justify-content-between align-items-center py-2">
          <div>
            <strong class="small">#<?= (int) $ped['id'] ?></strong>
            <span class="small text-muted">· <?= e($ped['tipo']) ?><?= $ped['pacote'] ? ' (' . e($ped['pacote']) . ')' : '' ?> · <?= data_br(substr($ped['criado_em'], 0, 10)) ?></span>
          </div>
          <div class="text-end">
            <span class="badge text-bg-<?= $coresPed[$ped['status']] ?? 'secondary' ?>"><?= e($ped['status']) ?></span>
            <div class="small fw-semibold"><?= moeda($ped['valor_total']) ?></div>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p class="text-muted small">Nenhum pedido registrado.</p>
    <?php endif; ?>

    <details class="mb-3">
      <summary class="text-success small fw-semibold"><i class="bi bi-clock-history me-1"></i>Histórico completo de compras (todas as safras)</summary>
      <div class="table-responsive mt-2">
        <table class="table table-sm align-middle">
          <thead class="table-light"><tr><th>Safra</th><th>Produto</th><th class="text-end">Qtde</th><th class="text-end">Valor</th><th>Data</th></tr></thead>
          <tbody>
            <?php foreach ($historicoCompras as $hc): ?>
            <tr>
              <td><?= e($hc['safra']) ?></td>
              <td><?= e($hc['produto']) ?> <span class="text-muted small">(<?= e($hc['familia']) ?>)</span></td>
              <td class="text-end"><?= numero($hc['quantidade'], 1) ?> <?= e($hc['unidade']) ?></td>
              <td class="text-end"><?= moeda($hc['valor_total']) ?></td>
              <td><?= data_br($hc['data_compra']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </details>

    <h6 class="text-success"><i class="bi bi-calendar3 me-1"></i>Planejamento de safra
      <button class="btn btn-sm btn-outline-success ms-2" onclick="Clientes.novoPlanoSafra(<?= $cliente['id'] ?>)"><i class="bi bi-plus-lg"></i> Intenção de plantio</button>
    </h6>
    <?php if ($planos): ?>
      <ul class="list-inline small mb-2">
        <?php foreach ($planos as $pl): ?>
          <li class="list-inline-item badge text-bg-light border text-dark"><?= e($pl['cultura']) ?>: <?= numero($pl['area_ha'], 0) ?> ha<?= $pl['propriedade'] ? ' (' . e($pl['propriedade']) . ')' : '' ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <?php if ($demandaPlano): ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead class="table-light"><tr><th>Família</th><th class="text-end">Demanda projetada</th><th class="text-end">Comprado</th><th class="text-end">A vender</th></tr></thead>
          <tbody>
            <?php foreach ($demandaPlano as $d): $espaco = max(0, (float) $d['demanda_projetada'] - (float) $d['realizado']); ?>
            <tr>
              <td><?= e($d['familia']) ?></td>
              <td class="text-end"><?= moeda($d['demanda_projetada']) ?></td>
              <td class="text-end"><?= moeda($d['realizado']) ?></td>
              <td class="text-end fw-semibold <?= $espaco > 0 ? 'text-success' : 'text-muted' ?>"><?= moeda($espaco) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <p class="text-muted small">Sem intenção de plantio registrada para a safra atual.</p>
    <?php endif; ?>
  </div>

  <!-- ABA CRÉDITO -->
  <div class="tab-pane fade" id="abaCredito">
    <div class="row g-3 mb-3">
      <div class="col-4"><div class="card text-center"><div class="card-body py-2">
        <div class="small text-muted">Limite</div><div class="fw-bold"><?= moeda($credito['limite']) ?></div>
      </div></div></div>
      <div class="col-4"><div class="card text-center"><div class="card-body py-2">
        <div class="small text-muted">Utilizado</div><div class="fw-bold"><?= moeda($credito['utilizado']) ?></div>
      </div></div></div>
      <div class="col-4"><div class="card text-center"><div class="card-body py-2">
        <div class="small text-muted">Disponível</div><div class="fw-bold text-<?= $credito['acima_do_limite'] ? 'danger' : 'success' ?>"><?= moeda($credito['disponivel']) ?></div>
      </div></div></div>
    </div>
    <div class="progress mb-2" style="height:14px">
      <div class="progress-bar bg-<?= $credito['percentual_utilizado'] >= 100 ? 'danger' : ($credito['percentual_utilizado'] >= 80 ? 'warning' : 'success') ?>"
           style="width:<?= min(100, $credito['percentual_utilizado']) ?>%"><?= $credito['percentual_utilizado'] ?>%</div>
    </div>
    <?php if ($credito['exige_aprovacao']): ?>
      <div class="alert alert-warning py-2">
        <i class="bi bi-exclamation-triangle me-1"></i>
        <strong><?= e($credito['motivo_aprovacao']) ?>.</strong>
        Novas vendas a prazo entram como <em>Pendente de aprovação</em> do Gestor Comercial.
      </div>
    <?php endif; ?>
    <?php if ($inad['inadimplente']): ?>
      <p class="small mb-3"><i class="bi bi-cash-stack me-1 text-danger"></i>
        Em atraso: <strong><?= moeda($inad['valor_vencido']) ?></strong> (título mais antigo há <?= $inad['dias_atraso'] ?> dias).
        A vencer: <?= moeda($inad['valor_a_vencer']) ?>.
      </p>
    <?php endif; ?>

    <h6 class="text-success"><i class="bi bi-shield-check me-1"></i>Garantias</h6>
    <?php if ($credito['garantias']): ?>
      <ul class="list-group">
        <?php foreach ($credito['garantias'] as $g): ?>
        <li class="list-group-item d-flex justify-content-between align-items-center">
          <div>
            <div class="fw-semibold"><?= e($g['tipo']) ?></div>
            <div class="small text-muted">Vence em <?= data_br($g['vencimento']) ?><?= $g['observacao'] ? ' · ' . e($g['observacao']) : '' ?></div>
          </div>
          <div class="text-end">
            <div class="fw-semibold"><?= moeda($g['valor']) ?></div>
            <?php if ($g['vencida']): ?><span class="badge text-bg-danger">Vencida</span>
            <?php elseif ($g['vencendo']): ?><span class="badge text-bg-warning">Vence em <?= $g['dias_para_vencer'] ?> dias</span>
            <?php endif; ?>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p class="text-muted small">Nenhuma garantia registrada.</p>
    <?php endif; ?>
  </div>

  <!-- ABA PROPRIEDADES -->
  <div class="tab-pane fade" id="abaPropriedades">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h6 class="text-success mb-0"><i class="bi bi-house me-1"></i>Propriedades e talhões</h6>
      <button class="btn btn-sm btn-outline-success" onclick="Clientes.novaPropriedade(<?= $cliente['id'] ?>)"><i class="bi bi-plus-lg"></i> Propriedade</button>
    </div>
    <?php if (!$propriedades): ?><p class="text-muted small">Nenhuma propriedade cadastrada.</p><?php endif; ?>
    <?php foreach ($propriedades as $p): ?>
    <div class="card mb-2">
      <div class="card-header py-2 d-flex justify-content-between align-items-center">
        <div><strong><?= e($p['nome']) ?></strong> <span class="text-muted small"><?= numero($p['area_ha'], 0) ?> ha · <?= e($p['municipio'] ?? '—') ?></span></div>
        <div class="btn-group">
          <button class="btn btn-sm btn-outline-success" title="Croqui da propriedade: marcar os contornos dos talhões no campo"
                  onclick="Croqui.abrir(<?= (int) $p['id'] ?>)"><i class="bi bi-bounding-box-circles me-1"></i>Croqui</button>
          <button class="btn btn-sm btn-outline-secondary" onclick='Clientes.editarPropriedade(<?= json_attr($p) ?>)'><i class="bi bi-pencil"></i></button>
          <button class="btn btn-sm btn-outline-success" onclick="Clientes.novoTalhao(<?= $p['id'] ?>)"><i class="bi bi-plus-lg"></i> Talhão</button>
        </div>
      </div>
      <?php if ($p['talhoes']): ?>
      <ul class="list-group list-group-flush">
        <?php foreach ($p['talhoes'] as $t): ?>
        <?php $pa = $plantiosAtivos[(int) $t['id']] ?? null; $co = $colheitas[(int) $t['id']] ?? null; ?>
        <li class="list-group-item py-1 d-flex justify-content-between align-items-center flex-wrap gap-1">
          <span><i class="bi bi-grid-3x3-gap me-1 text-muted"></i><?= e($t['nome']) ?>
            <span class="text-muted small">· <?= numero($t['area_ha'], 0) ?> ha<?= $t['cultura'] ? ' · ' . e($t['cultura']) : '' ?></span>
            <?php if ($pa): ?>
              <span class="badge text-bg-success ms-1" title="<?= e($pa['cultura']) ?> plantado em <?= data_br($pa['data_plantio']) ?><?= $pa['cultivar'] ? ' (' . e($pa['cultivar']) . ')' : '' ?>">
                <i class="bi bi-flower1 me-1"></i><?= e($pa['fase'] ?? 'Em ciclo') ?> · <?= (int) $pa['dap'] ?> d
              </span>
            <?php elseif ($co): ?>
              <span class="badge text-bg-light border text-dark ms-1" title="Colhido em <?= data_br($co['colhido_em']) ?>">
                <i class="bi bi-check2-circle me-1"></i>Colhido<?= $co['produtividade'] ? ': ' . numero($co['produtividade'], 1) . ' sc/ha' : '' ?>
              </span>
            <?php endif; ?>
          </span>
          <span class="btn-group">
            <?php /* nome via json_attr: entidades de e() são decodificadas antes do JS rodar (XSS em onclick) */ ?>
            <?php if ($pa): ?>
              <button class="btn btn-sm btn-outline-success" title="Encerrar plantio registrando a colheita"
                      onclick="Plantios.colheita(<?= (int) $pa['id'] ?>, <?= json_attr($t['nome']) ?>)"><i class="bi bi-basket me-1"></i>Colheita</button>
            <?php else: ?>
              <button class="btn btn-sm btn-outline-success" title="Registrar plantio (ativa a linha do tempo da cultura)"
                      onclick="Plantios.abrir(<?= (int) $t['id'] ?>, <?= (int) ($t['cultura_id'] ?? 0) ?>, <?= json_attr($t['nome']) ?>)"><i class="bi bi-calendar-plus me-1"></i>Plantio</button>
            <?php endif; ?>
            <button class="btn btn-sm btn-outline-secondary" onclick='Clientes.editarTalhao(<?= json_attr($t) ?>)'><i class="bi bi-pencil"></i></button>
          </span>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
      <?php $croquiSvg = \App\Services\CroquiService::svg($p['talhoes'] ?? [], 340, 240, $p); ?>
      <?php if ($croquiSvg !== ''): ?>
        <div class="card-body pt-2 pb-3 text-center">
          <?= $croquiSvg ?>
          <?php
            $plantioTotal = array_sum(array_map(
                fn ($t) => (float) ($t['area_gps'] ?? 0) ?: (float) $t['area_ha'],
                array_filter($p['talhoes'] ?? [], fn ($t) => !empty($t['contorno']))
            ));
          ?>
          <div class="small text-muted mt-1">
            <?php if (!empty($p['area_gps'])): ?>Propriedade (divisa medida): <strong><?= numero((float) $p['area_gps'], 1) ?> ha</strong> · <?php endif; ?>
            <?php if ($plantioTotal > 0): ?>Área de plantio mapeada: <strong><?= numero($plantioTotal, 1) ?> ha</strong><?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <?php if ($contatos): ?>
      <h6 class="text-success mt-3"><i class="bi bi-person-lines-fill me-1"></i>Responsáveis / contatos</h6>
      <ul class="list-group">
        <?php foreach ($contatos as $ct): ?>
        <li class="list-group-item py-2">
          <strong><?= e($ct['nome']) ?></strong>
          <span class="text-muted small"><?= $ct['cargo'] ? '· ' . e($ct['cargo']) : '' ?> <?= $ct['telefone'] ? '· ' . e($ct['telefone']) : '' ?></span>
        </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <!-- ABA HISTÓRICO AGRONÔMICO -->
  <div class="tab-pane fade" id="abaHistorico">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h6 class="text-success mb-0"><i class="bi bi-clock-history me-1"></i>Linha do tempo</h6>
      <a class="btn btn-sm btn-success" href="<?= url('visitas', ['nova' => 1, 'cliente_id' => $cliente['id']]) ?>"><i class="bi bi-plus-lg"></i> Nova visita</a>
    </div>
    <?php if (!$historico): ?><p class="text-muted small">Nenhuma visita registrada.</p><?php endif; ?>
    <div class="linha-tempo">
      <?php foreach ($historico as $h): ?>
      <div class="linha-tempo-item">
        <div class="linha-tempo-ponto"></div>
        <div class="card mb-3">
          <div class="card-body py-2">
            <div class="d-flex justify-content-between flex-wrap gap-1">
              <strong><?= data_br($h['data_visita']) ?><?= $h['hora'] ? ' ' . substr($h['hora'], 0, 5) : '' ?></strong>
              <span class="text-muted small"><?= e($h['tecnico']) ?></span>
            </div>
            <div class="small text-muted mb-1">
              <?= e($h['propriedade'] ?? '') ?><?= $h['talhao'] ? ' · ' . e($h['talhao']) : '' ?><?= $h['cultura'] ? ' · ' . e($h['cultura']) : '' ?>
              <?= $h['estagio_cultura'] ? ' · ' . e($h['estagio_cultura']) : '' ?>
            </div>
            <?php if ($h['objetivo']): ?><div class="small"><strong>Objetivo:</strong> <?= e($h['objetivo']) ?></div><?php endif; ?>
            <?php if ($h['recomendacao']): ?>
              <div class="small mt-1 p-2 bg-success-subtle rounded"><i class="bi bi-file-earmark-medical me-1"></i><strong>Recomendação:</strong> <?= nl2br(e($h['recomendacao'])) ?></div>
            <?php endif; ?>
            <?php if ($h['fotos']): ?>
              <div class="d-flex gap-2 mt-2 flex-wrap">
                <?php foreach ($h['fotos'] as $f): ?>
                  <a href="<?= e(upload_url($f['arquivo'])) ?>" target="_blank">
                    <img src="<?= e(upload_url($f['arquivo'], true)) ?>" class="foto-miniatura" alt="Foto da visita" loading="lazy" onerror="App.fotoIndisponivel(this)">
                  </a>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ABA: DOCUMENTOS -->
  <div class="tab-pane fade" id="abaDocumentos">
    <h6 class="text-success mb-2"><i class="bi bi-folder2-open me-1"></i>Documentos do produtor</h6>
    <form class="row g-2 align-items-end mb-3" onsubmit="return Clientes.salvarDocumento(event)" enctype="multipart/form-data">
      <input type="hidden" name="cliente_id" value="<?= (int)$cliente['id'] ?>">
      <div class="col-6 col-md-3">
        <label class="form-label small mb-0">Tipo</label>
        <select name="tipo" class="form-select form-select-sm">
          <?php foreach (['Foto','Laudo','Receita','Contrato','Nota fiscal','PDF','Outro'] as $t): ?><option><?= $t ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-4">
        <label class="form-label small mb-0">Nome/descrição</label>
        <input name="nome" class="form-control form-control-sm" placeholder="opcional">
      </div>
      <div class="col-8 col-md-3">
        <label class="form-label small mb-0">Arquivo</label>
        <input type="file" name="arquivo" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.doc,.docx,.xls,.xlsx" required>
      </div>
      <div class="col-4 col-md-2">
        <button class="btn btn-sm btn-success w-100"><i class="bi bi-upload me-1"></i>Anexar</button>
      </div>
    </form>

    <?php if (!$documentos): ?><p class="text-muted small">Nenhum documento anexado.</p><?php endif; ?>
    <div class="list-group">
      <?php foreach ($documentos as $d): ?>
      <div class="list-group-item d-flex justify-content-between align-items-center gap-2">
        <div class="d-flex align-items-center gap-2 text-truncate">
          <i class="bi <?= in_array(strtolower(pathinfo($d['arquivo'], PATHINFO_EXTENSION)), ['jpg','jpeg','png','webp','heic']) ? 'bi-image' : 'bi-file-earmark-text' ?> text-success fs-5"></i>
          <div class="text-truncate">
            <a href="<?= url('clientes/baixar-documento', ['id' => $d['id']]) ?>" target="_blank" class="fw-semibold text-decoration-none"><?= e($d['nome']) ?></a>
            <div class="small text-muted"><span class="badge text-bg-light border text-dark"><?= e($d['tipo']) ?></span>
              <?= data_br($d['criado_em']) ?><?= $d['enviado_por'] ? ' · ' . e($d['enviado_por']) : '' ?>
              <?= $d['tamanho'] ? ' · ' . numero($d['tamanho'] / 1024) . ' KB' : '' ?></div>
          </div>
        </div>
        <button class="btn btn-sm btn-outline-danger flex-shrink-0" title="Excluir" onclick="Clientes.excluirDocumento(<?= $d['id'] ?>, <?= (int)$cliente['id'] ?>)"><i class="bi bi-trash"></i></button>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script>App.graficoPotencialCliente();</script>
