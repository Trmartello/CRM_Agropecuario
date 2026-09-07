<?php
/** Ficha completa do produtor — carregada via AJAX no offcanvas. */
$inad = $painel['inadimplencia'];
$credito = $painel['credito'];
$queda = $painel['queda'];
$corInad = $inad['cor'] === 'orange' ? 'warning' : $inad['cor'];
?>
<div class="mb-3">
  <?php
    // Cartão do produtor (estilo app de campo): contato e ação principal em 1 toque
    $telefoneDigitos = preg_replace('/\D/', '', (string) ($cliente['telefone'] ?? ''));
    $whatsapp = $telefoneDigitos !== '' ? '55' . ltrim($telefoneDigitos, '0') : '';
    $areaTotalFicha = 0.0;
    foreach ($propriedades as $prTmp) {
        $areaTotalFicha += (float) (($prTmp['area_gps'] ?? null) ?: $prTmp['area_ha']);
    }
    $ultimaVisitaFicha = $historico[0]['data_visita'] ?? null;
    $diasSemVisita = $ultimaVisitaFicha ? (int) floor((time() - strtotime($ultimaVisitaFicha)) / 86400) : null;
  ?>
  <div class="cartao-produtor mb-3">
    <div class="d-flex align-items-center gap-3 flex-wrap">
      <div class="cartao-produtor-avatar"><?= e(mb_strtoupper(mb_substr(trim($cliente['nome']), 0, 1))) ?></div>
      <div class="flex-grow-1">
        <h4 class="mb-0 text-white"><?= e($cliente['nome']) ?></h4>
        <div class="cartao-produtor-sub">
          <?= e($cliente['municipio'] ?? '—') ?>/<?= e($cliente['estado'] ?? '') ?> · <?= e($cliente['situacao']) ?>
          <?= $cliente['cpf_cnpj'] ? ' · ' . e($cliente['cpf_cnpj']) : '' ?>
        </div>
        <div class="d-flex gap-1 flex-wrap mt-1">
          <?= selo_segmento($cliente['segmento_manual'] ?? null, $cliente['segmento'] ?? null) ?>
          <span class="badge text-bg-<?= $corInad ?>" title="<?= $inad['inadimplente'] ? moeda($inad['valor_vencido']) . ' vencido há ' . $inad['dias_atraso'] . ' dias' : 'Sem títulos vencidos' ?>"><?= e($inad['grau']) ?></span>
          <span class="badge text-bg-<?= ['A' => 'success', 'B' => 'primary', 'C' => 'warning', 'D' => 'danger'][$credito['score']] ?>">Score <?= $credito['score'] ?></span>
        </div>
      </div>
    </div>
    <div class="row g-2 mt-1">
      <div class="col-4"><div class="cartao-produtor-tile"><div class="ct-valor"><?= numero($areaTotalFicha, 0) ?> ha</div><div class="ct-rotulo">área total</div></div></div>
      <div class="col-4"><div class="cartao-produtor-tile"><div class="ct-valor"><?= moeda($cliente['limite_credito']) ?></div><div class="ct-rotulo">limite de crédito</div></div></div>
      <div class="col-4"><div class="cartao-produtor-tile"><div class="ct-valor"><?= $diasSemVisita === null ? '—' : $diasSemVisita . ' d' ?></div><div class="ct-rotulo">sem visita</div></div></div>
    </div>
    <button class="btn btn-light btn-lg w-100 mt-2 fw-bold text-success" onclick="Visitas.nova(<?= (int) $cliente['id'] ?>)">
      <i class="bi bi-clipboard2-plus me-2"></i>Registrar Visita
    </button>
    <div class="row g-2 mt-0">
      <div class="col-6">
        <?php if ($telefoneDigitos !== ''): ?>
          <a class="btn btn-outline-light w-100" href="tel:+<?= e($whatsapp) ?>"><i class="bi bi-telephone me-1"></i>Ligar</a>
        <?php else: ?>
          <button class="btn btn-outline-light w-100" disabled title="Sem telefone no cadastro"><i class="bi bi-telephone me-1"></i>Ligar</button>
        <?php endif; ?>
      </div>
      <div class="col-6">
        <?php if ($whatsapp !== ''): ?>
          <a class="btn btn-outline-light w-100" target="_blank" href="https://wa.me/<?= e($whatsapp) ?>"><i class="bi bi-whatsapp me-1"></i>WhatsApp</a>
        <?php else: ?>
          <button class="btn btn-outline-light w-100" disabled title="Sem telefone no cadastro"><i class="bi bi-whatsapp me-1"></i>WhatsApp</button>
        <?php endif; ?>
      </div>
    </div>
    <div class="text-center mt-2">
      <a class="btn btn-sm btn-outline-light" target="_blank" title="Relatório de fechamento de safra (imprimível)"
         href="<?= url('relatorios/safra') ?>&cliente=<?= (int) $cliente['id'] ?>"><i class="bi bi-file-earmark-bar-graph me-1"></i>Fechamento de safra</a>
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
  <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#abaTalhoes"><i class="bi bi-grid-3x3-gap me-1"></i>Talhões</button></li>
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
      <h6 class="text-success mb-0"><i class="bi bi-house me-1"></i>Propriedades (cadastro da terra)</h6>
      <button class="btn btn-sm btn-outline-success" onclick="Clientes.novaPropriedade(<?= $cliente['id'] ?>)"><i class="bi bi-plus-lg"></i> Propriedade</button>
    </div>
    <p class="text-muted small mb-2">Propriedade → imóveis (CAR) → áreas de plantio e não plantio, no croqui. O que está plantado em cada área fica na aba <strong>Talhões</strong>.</p>
    <?php if (!$propriedades): ?><p class="text-muted small">Nenhuma propriedade cadastrada.</p><?php endif; ?>
    <?php
      // v40: barra de área de plantio por cultura × finalidade (docs/specs/propriedade-imoveis-plantio.md §5)
      $cores = \App\Services\CroquiService::CORES;
      $barraPlantio = function (array $r, bool $compacta = false) use ($cores): string {
          if ($r['area_plantio'] <= 0 && $r['soma'] <= 0) {
              return '';
          }
          $base = max($r['area_plantio'], $r['soma'], 0.01);
          $html = '<div class="progress mt-2" style="height:14px" role="img" aria-label="Área de plantio por cultura">';
          $itens = [];
          foreach ($r['grupos'] as $i => $g) {
              $cor = $cores[$i % count($cores)];
              $pct = round($g['area'] / $base * 100, 2);
              $rot = $g['cultura'] . ($g['finalidade'] ? ' · ' . $g['finalidade'] : '');
              $html .= '<div class="progress-bar" style="width:' . $pct . '%;background:' . $cor . '" title="' . e($rot) . ': ' . numero($g['area'], 1) . ' ha"></div>';
              $itens[] = '<span class="me-2"><span class="croqui-cor" style="background:' . $cor . '"></span>' . e($rot)
                  . ' <strong>' . numero($g['area'], 1) . ' ha</strong> <span class="text-muted">(' . numero($g['pct'], 0) . '%)</span></span>';
          }
          if ($r['nao_mapeado'] > 0) {
              $pct = round($r['nao_mapeado'] / $base * 100, 2);
              $html .= '<div class="progress-bar bg-light border-start" style="width:' . $pct . '%" title="Sem talhão: ' . numero($r['nao_mapeado'], 1) . ' ha"></div>';
              $itens[] = '<span class="me-2 text-muted"><span class="croqui-cor" style="background:#e9ecef;border:1px solid #ccc"></span>Sem talhão <strong>' . numero($r['nao_mapeado'], 1) . ' ha</strong></span>';
          }
          $html .= '</div>';
          if ($r['excedente'] > 0) {
              $itens[] = '<span class="text-danger fw-semibold"><i class="bi bi-exclamation-triangle-fill me-1"></i>Talhões somam '
                  . numero($r['excedente'], 1) . ' ha a mais que a área de plantio</span>';
          }
          if ($r['plantio_origem'] === 'total' && !$compacta) {
              $itens[] = '<span class="text-muted"><i class="bi bi-info-circle me-1"></i>Sem área de plantio informada — usando a área total</span>';
          }
          return $html . '<div class="small mt-1 d-flex flex-wrap">' . implode('', $itens) . '</div>';
      };
    ?>

    <?php
      // Card "Total do produtor" (usado nas abas Propriedades e Talhões)
      $iconeUso = ['lavoura' => '🌱', 'perene' => '🍎', 'reflorestamento' => '🌲'];
      $cardTotal = function (bool $comBarra) use ($resumoProdutor, $propriedades, $barraPlantio): string {
          if (!$propriedades || ($resumoProdutor['area_plantio'] <= 0 && $resumoProdutor['soma'] <= 0)) {
              return '';
          }
          $pu = $resumoProdutor['por_uso'] ?? [];
          $temUso = (($pu['perene'] ?? 0) > 0 || ($pu['reflorestamento'] ?? 0) > 0);
          $h = '<div class="card mb-2 border-success-subtle bg-success-subtle bg-opacity-25"><div class="card-body py-2"><div class="d-flex flex-wrap gap-3 small">'
              . '<span><i class="bi bi-person-vcard me-1 text-success"></i><strong>Total do produtor</strong></span>'
              . '<span>Área total <strong>' . numero($resumoProdutor['area_total'], 1) . ' ha</strong></span>'
              . '<span>Área cultivada <strong>' . numero($resumoProdutor['area_plantio'], 1) . ' ha</strong>'
              . ($temUso ? ' <span class="text-muted">(lavoura ' . numero($pu['lavoura'] ?? 0, 1) . ($pu['perene'] > 0 ? ' · perene ' . numero($pu['perene'], 1) : '') . ($pu['reflorestamento'] > 0 ? ' · reflorestamento ' . numero($pu['reflorestamento'], 1) : '') . ')</span>' : '') . '</span>'
              . (($resumoProdutor['nao_plantio'] ?? 0) > 0 ? '<span title="Mata, APP, açude, sede, estrada… descontados da área de plantio">Não plantio <strong>' . numero($resumoProdutor['nao_plantio'], 1) . ' ha</strong></span>' : '')
              . '<span>Talhões <strong>' . (int) $resumoProdutor['qtd_talhoes'] . '</strong></span></div>'
              . ($comBarra ? $barraPlantio($resumoProdutor, true) : '') . '</div></div>';
          return $h;
      };
    ?>
    <?= $cardTotal(false) ?>

    <?php foreach ($propriedades as $p): ?>
    <?php $imoveisLista = array_map(fn ($i) => ['id' => (int) $i['id'], 'rotulo' => \App\Controllers\ClientesController::rotuloImovel($i)], $p['imoveis']); ?>
    <div class="card mb-2">
      <div class="card-header py-2 d-flex justify-content-between align-items-center flex-wrap gap-1">
        <div>
          <strong><?= e($p['nome']) ?></strong>
          <span class="text-muted small"><?= numero($p['resumo']['area_total'] ?: $p['area_ha'], 0) ?> ha · <?= e($p['municipio'] ?? '—') ?>
            · <?= count($p['imoveis']) ?> <?= count($p['imoveis']) === 1 ? 'imóvel' : 'imóveis' ?> (CAR)</span>
        </div>
        <div class="btn-group">
          <button class="btn btn-sm btn-outline-secondary" title="Editar a propriedade" onclick='Clientes.editarPropriedade(<?= json_attr($p) ?>)'><i class="bi bi-pencil"></i></button>
          <button class="btn btn-sm btn-outline-success" title="Adicionar outro imóvel (CAR) a esta propriedade"
                  onclick="Clientes.novoImovel(<?= (int) $p['id'] ?>)"><i class="bi bi-plus-lg"></i> Imóvel (CAR)</button>
        </div>
      </div>

      <?php foreach ($p['imoveis'] as $im): ?>
      <?php $r = $im['resumo']; $rotuloIm = \App\Controllers\ClientesController::rotuloImovel($im);
            // v46: áreas de não plantio (mata, açude...) do imóvel — descontadas dos talhões e das áreas
            $exclusoesPol = \App\Services\AreaPlantioService::poligonosDe($im['areas_nao_plantio'] ?? []);
            $liquidaPorArea = [];
            foreach ($r['areas'] ?? [] as $ap) { $liquidaPorArea[(int) $ap['id']] = $ap; } ?>
      <div class="border-top">
        <div class="px-3 pt-2 pb-1 d-flex justify-content-between align-items-start flex-wrap gap-1 bg-light bg-opacity-50">
          <div>
            <i class="bi bi-geo text-success me-1"></i><strong><?= e($rotuloIm) ?></strong>
            <?php $pu = $r['por_uso'] ?? []; $temUso = (($pu['perene'] ?? 0) > 0 || ($pu['reflorestamento'] ?? 0) > 0); ?>
            <span class="text-muted small">· total <?= numero($r['area_total'], 1) ?> ha · <?= $temUso ? 'cultivado' : 'plantio' ?> <?= numero($r['area_plantio'], 1) ?> ha<?= $r['plantio_origem'] === 'total' ? ' (= total)' : ($temUso ? ' (lavoura ' . numero($pu['lavoura'] ?? 0, 1) . ($pu['perene'] > 0 ? ' · perene ' . numero($pu['perene'], 1) : '') . ($pu['reflorestamento'] > 0 ? ' · reflorestamento ' . numero($pu['reflorestamento'], 1) : '') . ')' : (count($r['areas'] ?? []) > 1 ? ' (' . count($r['areas']) . ' áreas)' : '')) ?><?= ($r['nao_plantio'] ?? 0) > 0 ? ' · não plantio ' . numero($r['nao_plantio'], 1) . ' ha' : '' ?><?= !empty($im['municipio']) ? ' · ' . e($im['municipio']) . (!empty($im['uf']) ? '/' . e($im['uf']) : '') : '' ?></span>
            <?php if (count($r['areas'] ?? []) > 0): ?>
              <div class="small text-muted"><i class="bi bi-layers me-1 text-success"></i>Áreas:
                <?php foreach ($r['areas'] as $ap): ?><span class="badge text-bg-light border text-dark me-1" title="<?= e($ap['uso_rotulo'] ?? 'Lavoura anual') ?><?= ($ap['desconto'] ?? 0) > 0 ? ' · desenho ' . numero($ap['area_gps'], 1) . ' ha − não plantio ' . numero($ap['desconto'], 1) . ' ha' : '' ?>"><?= $iconeUso[$ap['uso'] ?? 'lavoura'] ?? '🌱' ?> <?= e($ap['nome']) ?><?= ($ap['uso'] ?? 'lavoura') !== 'lavoura' ? ' · ' . e($ap['cultura'] ?: $ap['uso_rotulo']) : '' ?> · <?= numero($ap['area_liquida'] ?? $ap['area_gps'], 1) ?> ha</span><?php endforeach; ?>
              </div>
            <?php endif; ?>
            <?php if (!empty($r['exclusoes'])): ?>
              <?php
                // v49: as camadas importadas do CAR (muitas partes por tema) aparecem AGRUPADAS por tipo;
                // as desenhadas à mão seguem uma a uma
                $doCar = [];
                $manuais = [];
                foreach ($r['exclusoes'] as $ex) {
                    if (($ex['origem'] ?? 'manual') === 'car') {
                        $doCar[$ex['tipo']] ??= ['rotulo' => $ex['tipo_rotulo'], 'tema' => $ex['tema'] ?? '', 'partes' => 0, 'ha' => 0.0];
                        $doCar[$ex['tipo']]['partes']++;
                        $doCar[$ex['tipo']]['ha'] += (float) $ex['area_gps'];
                    } else {
                        $manuais[] = $ex;
                    }
                }
              ?>
              <div class="small text-muted"><i class="bi bi-slash-circle me-1 text-secondary"></i>Não plantio:
                <?php foreach ($doCar as $g): ?><span class="badge text-bg-light border text-secondary me-1" title="Camada do CAR: <?= e($g['tema']) ?><?= $g['partes'] > 1 ? ' · ' . (int) $g['partes'] . ' partes' : '' ?>"><i class="bi bi-patch-check me-1"></i><?= e($g['rotulo']) ?> (CAR)<?= $g['partes'] > 1 ? ' · ' . (int) $g['partes'] . ' partes' : '' ?> · <?= numero($g['ha'], 1) ?> ha</span><?php endforeach; ?>
                <?php foreach ($manuais as $ex): ?><span class="badge text-bg-light border text-secondary me-1" title="<?= e($ex['tipo_rotulo']) ?>"><?= e($ex['nome']) ?> · <?= numero($ex['area_gps'], 1) ?> ha</span><?php endforeach; ?>
                <?php if (!empty($r['nao_plantio_sobreposto'])): ?><span class="text-muted" title="A APP fica dentro da vegetação nativa, a reserva legal também: o total descontado é a união das camadas, não a soma">(camadas se sobrepõem — união <?= numero($r['nao_plantio'], 1) ?> ha)</span><?php endif; ?>
              </div>
            <?php endif; ?>
            <?php if (!empty($im['car_numero'])): ?>
              <div class="small text-muted">CAR: <?= e($im['car_numero']) ?>
                <a href="https://consultapublica.car.gov.br/publico/imoveis/index" target="_blank" rel="noopener" class="ms-1"
                   title="Abrir a consulta pública do CAR — o número é copiado para você colar na busca"
                   onclick='Clientes.copiarCar(<?= json_attr($im['car_numero']) ?>)'>abrir no CAR <i class="bi bi-box-arrow-up-right"></i></a>
              </div>
            <?php else: ?>
              <div class="small text-warning-emphasis"><i class="bi bi-exclamation-circle me-1"></i>Sem nº do CAR — edite o imóvel ou use "CAR aqui" no croqui.</div>
            <?php endif; ?>
          </div>
          <div class="btn-group">
            <button class="btn btn-sm btn-outline-success" title="Importar o CAR deste imóvel (zip do SICAR): divisa oficial + APP, Reserva Legal, Vegetação nativa e Servidão como áreas de não plantio"
                    onclick="Clientes.importarCar(<?= (int) $im['id'] ?>, <?= count($im['areas_plantio'] ?? []) ?>)"><i class="bi bi-cloud-download me-1"></i>CAR</button>
            <button class="btn btn-sm btn-outline-success" title="Croqui do imóvel: divisa, área de plantio e talhões"
                    onclick="Croqui.abrir(<?= (int) $im['id'] ?>)"><i class="bi bi-bounding-box-circles me-1"></i>Croqui</button>
            <button class="btn btn-sm btn-outline-secondary" title="Editar o imóvel (CAR, áreas)"
                    onclick='Clientes.editarImovel(<?= json_attr($im) ?>)'><i class="bi bi-pencil"></i></button>
          </div>
        </div>

        <?php
          // Fluxo guiado (v45): próximo passo do CADASTRO deste imóvel (o passo 3 vive na aba Talhões — v48)
          $passo = empty($im['contorno']) ? [1, 'Traga a divisa do CAR e ajuste os pontos da área total (Croqui → etapa 1).']
              : (empty($im['areas_plantio']) ? [2, 'Marque as áreas de plantio dentro da divisa (Croqui → etapa 2).']
              // v47: imóvel só com perene/reflorestamento não precisa de talhão
              : (!$im['talhoes'] && array_filter($im['areas_plantio'], fn ($ap) => ($ap['uso'] ?? 'lavoura') === 'lavoura') ? [3, 'Cadastro da terra pronto — lance o que está plantado em cada área na aba Talhões.'] : null));
        ?>
        <?php if ($passo): ?>
          <div class="px-3 py-1 small text-success-emphasis bg-success-subtle bg-opacity-25 border-top">
            <i class="bi bi-signpost-2 me-1"></i><strong>Próximo passo (<?= (int) $passo[0] ?>/3):</strong> <?= e($passo[1]) ?>
            <?php if ($passo[0] === 3): ?><a href="#" class="ms-1" onclick="event.preventDefault(); bootstrap.Tab.getOrCreateInstance(document.querySelector('#painelFicha [data-bs-target=\'#abaTalhoes\']')).show();">Abrir a aba Talhões <i class="bi bi-arrow-right"></i></a><?php endif; ?>
          </div>
        <?php endif; ?>

        <?php $croquiSvg = \App\Services\CroquiService::svg($im['talhoes'], 340, 240, $im); ?>
        <?php if ($croquiSvg !== '' || $r['area_plantio'] > 0 || $r['soma'] > 0): ?>
          <div class="px-3 pt-2 pb-3">
            <?php if ($croquiSvg !== ''): ?><div class="text-center"><?= $croquiSvg ?></div><?php endif; ?>
            <div class="small text-muted mt-1">
              <?php if (!empty($im['area_gps'])): ?>Divisa medida: <strong><?= numero((float) $im['area_gps'], 1) ?> ha</strong> · <?php endif; ?>
              <?php if ($r['plantio_origem'] === 'plantio' && count($r['areas'] ?? []) > 0): ?>Área <?= $temUso ? 'cultivada' : 'de plantio' ?> desenhada: <strong><?= numero($r['area_plantio'], 1) ?> ha</strong><?= $temUso ? ' (lavoura ' . numero($pu['lavoura'] ?? 0, 1) . ($pu['perene'] > 0 ? ' · perene ' . numero($pu['perene'], 1) : '') . ($pu['reflorestamento'] > 0 ? ' · reflorestamento ' . numero($pu['reflorestamento'], 1) : '') . ')' : (count($r['areas']) > 1 ? ' (' . count($r['areas']) . ' áreas)' : '') ?> · <?php endif; ?>
              <?php if (($r['nao_plantio'] ?? 0) > 0): ?>Não plantio: <strong><?= numero($r['nao_plantio'], 1) ?> ha</strong> (<?= e(implode(', ', array_map(fn ($k, $v) => \App\Services\AreaPlantioService::rotuloTipo($k) . ' ' . numero($v, 1), array_keys($r['nao_plantio_tipos']), $r['nao_plantio_tipos']))) ?><?= !empty($r['nao_plantio_sobreposto']) ? ' — sobrepostas, união' : '' ?>) · <?php endif; ?>
              <?= (int) $r['qtd_talhoes'] ?> <?= (int) $r['qtd_talhoes'] === 1 ? 'talhão' : 'talhões' ?> (aba Talhões)
            </div>
          </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
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

  <!-- ABA TALHÕES DE PLANTIO (v48): o que está plantado em cada ÁREA cultivada -->
  <div class="tab-pane fade" id="abaTalhoes">
    <h6 class="text-success mb-1"><i class="bi bi-grid-3x3-gap me-1"></i>Talhões de plantio</h6>
    <p class="text-muted small mb-2">Para cada área cultivada do cadastro, lance o que está plantado: por padrão <strong>toda a área</strong> vira um talhão com cultura, cultivar e finalidade; se a área tiver mais de uma cultura, <strong>delimite</strong> cada talhão no mapa.</p>
    <?php if (!$propriedades): ?><p class="text-muted small">Nenhuma propriedade cadastrada — comece pela aba Propriedades.</p><?php endif; ?>
    <?= $cardTotal(true) ?>
    <?php
      // Linha de um talhão (usada por área e no grupo "fora das áreas")
      $itemTalhao = function (array $t, array $exclusoesPol, array $imoveisLista, bool $recuado) use ($plantiosAtivos, $colheitas): string {
          $pa = $plantiosAtivos[(int) $t['id']] ?? null;
          $co = $colheitas[(int) $t['id']] ?? null;
          $h = '<li class="list-group-item py-1 d-flex justify-content-between align-items-center flex-wrap gap-1' . ($recuado ? ' ps-4' : '') . '">'
              . '<span><i class="bi bi-grid-3x3-gap me-1 text-muted"></i>' . e($t['nome'])
              . '<span class="text-muted small">· ' . numero(\App\Services\AreaPlantioService::areaLiquidaTalhao($t, $exclusoesPol), 1) . ' ha'
              . ($t['cultura'] ? ' · ' . e($t['cultura']) : '') . (!empty($t['cultivar']) ? ' <span class="text-body-secondary">' . e($t['cultivar']) . '</span>' : '')
              . (!empty($t['finalidade']) ? ' <span class="badge text-bg-light border text-dark">' . e($t['finalidade']) . '</span>' : '') . '</span>'
              . (empty($t['contorno']) ? '<span class="badge text-bg-light border text-muted ms-1" title="Sem contorno no croqui">não desenhado</span>' : '');
          if ($pa) {
              $h .= '<span class="badge text-bg-success ms-1" title="' . e($pa['cultura']) . ' plantado em ' . data_br($pa['data_plantio']) . ($pa['cultivar'] ? ' (' . e($pa['cultivar']) . ')' : '') . '">'
                  . '<i class="bi bi-flower1 me-1"></i>' . e($pa['fase'] ?? 'Em ciclo') . ' · ' . (int) $pa['dap'] . ' d</span>';
          } elseif ($co) {
              $h .= '<span class="badge text-bg-light border text-dark ms-1" title="Colhido em ' . data_br($co['colhido_em']) . '"><i class="bi bi-check2-circle me-1"></i>Colhido'
                  . ($co['produtividade'] ? ': ' . numero($co['produtividade'], 1) . ' sc/ha' : '') . '</span>';
          }
          $h .= '</span><span class="btn-group">';
          // nome via json_attr: entidades de e() são decodificadas antes do JS rodar (XSS em onclick)
          if ($pa) {
              $h .= '<button class="btn btn-sm btn-outline-success" title="Encerrar plantio registrando a colheita" onclick="Plantios.colheita(' . (int) $pa['id'] . ', ' . json_attr($t['nome']) . ')"><i class="bi bi-basket me-1"></i>Colheita</button>';
          } else {
              $h .= '<button class="btn btn-sm btn-outline-success" title="Registrar plantio (ativa a linha do tempo da cultura)" onclick="Plantios.abrir(' . (int) $t['id'] . ', ' . (int) ($t['cultura_id'] ?? 0) . ', ' . json_attr($t['nome']) . ', ' . (int) ($t['finalidade_id'] ?? 0) . ', ' . json_attr((string) ($t['cultivar'] ?? '')) . ')"><i class="bi bi-calendar-plus me-1"></i>Plantio</button>';
          }
          $h .= '<button class="btn btn-sm btn-outline-secondary" title="Editar o talhão" onclick=\'Clientes.editarTalhao(' . json_attr($t) . ', ' . json_attr($imoveisLista) . ')\'><i class="bi bi-pencil"></i></button>';
          return $h . '</span></li>';
      };
    ?>
    <?php foreach ($propriedades as $p): ?>
    <?php $imoveisLista = array_map(fn ($i) => ['id' => (int) $i['id'], 'rotulo' => \App\Controllers\ClientesController::rotuloImovel($i)], $p['imoveis']); ?>
    <div class="card mb-2">
      <div class="card-header py-2"><strong><?= e($p['nome']) ?></strong>
        <span class="text-muted small">· cultivado <?= numero($p['resumo']['area_plantio'], 1) ?> ha · <?= count($p['imoveis']) ?> <?= count($p['imoveis']) === 1 ? 'imóvel' : 'imóveis' ?></span></div>
      <?php foreach ($p['imoveis'] as $im): ?>
      <?php $r = $im['resumo']; $rotuloIm = \App\Controllers\ClientesController::rotuloImovel($im);
            $exclusoesPol = \App\Services\AreaPlantioService::poligonosDe($im['areas_nao_plantio'] ?? []);
            $liquidaPorArea = [];
            foreach ($r['areas'] ?? [] as $ap) { $liquidaPorArea[(int) $ap['id']] = $ap; }
            $porArea = [];
            $semArea = [];
            foreach ($im['talhoes'] as $t) {
                $apId = (int) ($t['area_plantio_id'] ?? 0);
                if ($apId && isset($liquidaPorArea[$apId])) { $porArea[$apId][] = $t; } else { $semArea[] = $t; }
            } ?>
      <div class="border-top">
        <div class="px-3 py-2 bg-light bg-opacity-50 small">
          <i class="bi bi-geo text-success me-1"></i><strong><?= e($rotuloIm) ?></strong>
          <span class="text-muted">· cultivado <?= numero($r['area_plantio'], 1) ?> ha · talhões <?= numero($r['soma'], 1) ?> ha<?= $r['nao_mapeado'] > 0 ? ' · sem talhão ' . numero($r['nao_mapeado'], 1) . ' ha' : '' ?></span>
        </div>
        <?php if (empty($im['contorno']) || empty($im['areas_plantio'])): ?>
          <div class="px-3 py-2 small text-warning-emphasis bg-warning-subtle border-top"><i class="bi bi-exclamation-circle me-1"></i>
            <?= empty($im['contorno']) ? 'Este imóvel ainda não tem a divisa do CAR.' : 'Este imóvel ainda não tem áreas de plantio.' ?>
            Complete o cadastro na aba Propriedades (Croqui → etapa <?= empty($im['contorno']) ? 1 : 2 ?>) para lançar os talhões.
          </div>
        <?php endif; ?>
        <?php foreach ($im['areas_plantio'] ?? [] as $ap): ?>
        <?php $apL = $liquidaPorArea[(int) $ap['id']] ?? null;
              $liquida = (float) ($apL['area_liquida'] ?? $ap['area_gps']);
              $uso = $ap['uso'] ?? 'lavoura';
              $ts = $porArea[(int) $ap['id']] ?? [];
              $somaT = 0.0;
              foreach ($ts as $t) { $somaT += \App\Services\AreaPlantioService::areaLiquidaTalhao($t, $exclusoesPol); }
              $livre = max(0.0, $liquida - $somaT);
              $infoArea = ['area_id' => (int) $ap['id'], 'nome' => (string) $ap['nome'], 'ha' => round($liquida, 2), 'imovel_id' => (int) $im['id'],
                  'imovel_rotulo' => $rotuloIm, 'propriedade_id' => (int) $p['id'], 'uso' => $uso, 'cultura_id' => (int) ($ap['cultura_id'] ?? 0) ?: null]; ?>
        <div class="px-3 py-2 border-top">
          <div class="d-flex justify-content-between align-items-start flex-wrap gap-1">
            <div><?= $iconeUso[$uso] ?? '🌱' ?> <strong><?= e($ap['nome']) ?></strong>
              <span class="text-muted small">· <?= e($apL['uso_rotulo'] ?? 'Lavoura anual') ?><?= $uso !== 'lavoura' && !empty($ap['cultura']) ? ' (' . e($ap['cultura']) . ')' : '' ?> · <?= numero($liquida, 1) ?> ha<?= $ts ? ' · com talhão ' . numero($somaT, 1) . ' ha' . ($livre > 0.05 ? ' · livre ' . numero($livre, 1) . ' ha' : '') : ' · <span class="text-warning-emphasis">sem talhão</span>' ?></span>
            </div>
            <div class="btn-group">
              <?php if (!$ts): ?>
                <button class="btn btn-sm btn-success" title="Toda a área com uma cultura só: cria um talhão cobrindo a área inteira"
                        onclick='Clientes.talhaoAreaToda(<?= json_attr($infoArea) ?>)'><i class="bi bi-grid-1x2 me-1"></i>Toda a área</button>
              <?php endif; ?>
              <button class="btn btn-sm btn-outline-success" title="Delimitar um talhão dentro desta área no mapa (mais de uma cultura na mesma área)"
                      onclick="Clientes.delimitarTalhao(<?= (int) $im['id'] ?>, <?= (int) $ap['id'] ?>)"><i class="bi bi-bounding-box-circles me-1"></i>Delimitar</button>
            </div>
          </div>
          <?php if ($ts): ?>
          <ul class="list-group list-group-flush mt-1">
            <?php foreach ($ts as $t): ?><?= $itemTalhao($t, $exclusoesPol, $imoveisLista, false) ?><?php endforeach; ?>
          </ul>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if ($semArea): ?>
        <ul class="list-group list-group-flush border-top">
          <li class="list-group-item py-1 small text-warning-emphasis bg-warning-subtle"><i class="bi bi-exclamation-circle me-1"></i><?= $im['areas_plantio'] ? 'Talhões fora das áreas de plantio (ajuste no croqui)' : 'Talhões sem área de plantio' ?></li>
          <?php foreach ($semArea as $t): ?><?= $itemTalhao($t, $exclusoesPol, $imoveisLista, true) ?><?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <?php if ($r['area_plantio'] > 0 || $r['soma'] > 0): ?><div class="px-3 pb-3"><?= $barraPlantio($r) ?></div><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
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
