<?php
use App\Core\Auth;
$mesLabel = function (?string $ym): string {
    if (!$ym || !preg_match('/^\d{4}-\d{2}$/', $ym)) return '—';
    $meses = [1=>'jan',2=>'fev',3=>'mar',4=>'abr',5=>'mai',6=>'jun',7=>'jul',8=>'ago',9=>'set',10=>'out',11=>'nov',12=>'dez'];
    [$a,$m] = explode('-', $ym);
    return ($meses[(int)$m] ?? $m) . '/' . $a;
};
$statusCor = ['Aberta'=>'secondary','Enviada'=>'info','Aprovada'=>'success','Rejeitada'=>'danger'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <p class="text-muted mb-0">
    Quilometragem, refeições e prestação de contas mensal.
    <?php if ($categoria): ?>
      <span class="badge text-bg-light border text-dark ms-1">Sua categoria: <?= e($categoria['nome']) ?> · <?= moeda($categoria['valor_km']) ?>/km</span>
    <?php else: ?>
      <span class="badge text-bg-warning ms-1">Sem categoria de reembolso definida</span>
    <?php endif; ?>
  </p>
  <div class="d-flex gap-2">
    <button class="btn btn-outline-success" onclick="Despesas.novoKm()"><i class="bi bi-signpost-2 me-1"></i>Lançar KM</button>
    <button class="btn btn-success" onclick="Despesas.novaRefeicao()"><i class="bi bi-cup-hot me-1"></i>Lançar Refeição</button>
  </div>
</div>

<?php if ($ehGestor): ?>
<form class="row g-2 align-items-end mb-3" method="get">
  <input type="hidden" name="r" value="despesas">
  <div class="col-auto">
    <label class="form-label small mb-0">Usuário</label>
    <select name="usuario_id" class="form-select form-select-sm">
      <option value="0">Toda a equipe</option>
      <?php foreach ($equipe as $u): ?>
        <option value="<?= $u['id'] ?>" <?= $filtroUsuario === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['nome']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-auto">
    <label class="form-label small mb-0">Mês</label>
    <input type="month" name="mes" value="<?= e($mes) ?>" class="form-control form-control-sm">
  </div>
  <div class="col-auto">
    <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-filter me-1"></i>Filtrar</button>
  </div>
</form>
<?php endif; ?>

<ul class="nav nav-tabs mb-3" role="tablist">
  <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabKm" type="button"><i class="bi bi-signpost-2 me-1"></i>Quilometragem</button></li>
  <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabRefeicoes" type="button"><i class="bi bi-cup-hot me-1"></i>Refeições</button></li>
  <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabPrestacao" type="button"><i class="bi bi-file-earmark-text me-1"></i>Prestação de Contas</button></li>
</ul>

<div class="tab-content">
  <!-- ABA: QUILOMETRAGEM -->
  <div class="tab-pane fade show active" id="tabKm">
    <div class="card"><div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr>
          <th>Data</th><?php if ($ehGestor): ?><th>Usuário</th><?php endif; ?>
          <th class="d-none d-md-table-cell">Veículo</th><th class="d-none d-md-table-cell">Destino</th>
          <th class="text-end">KM</th><th class="text-end">Valor</th><th></th>
        </tr></thead>
        <tbody>
          <?php if (!$km): ?><tr><td colspan="7" class="text-center text-muted py-4">Nenhum lançamento no período.</td></tr><?php endif; ?>
          <?php foreach ($km as $l): ?>
          <tr>
            <td><?= data_br($l['data']) ?></td>
            <?php if ($ehGestor): ?><td class="small"><?= e($l['usuario']) ?></td><?php endif; ?>
            <td class="d-none d-md-table-cell small"><?= e($l['veiculo'] ?? '—') ?></td>
            <td class="d-none d-md-table-cell small">
              <span class="badge text-bg-light border text-dark me-1"><?= e($l['tipo_destino']) ?></span>
              <?= e($l['destino_desc'] ?? '—') ?>
              <?php if (!empty($l['visita_id'])): ?><i class="bi bi-clipboard2-check text-success ms-1" title="Amarrado à visita realizada"></i><?php endif; ?>
            </td>
            <td class="text-end"><?= numero($l['km_rodados'], 1) ?></td>
            <td class="text-end fw-semibold"><?= moeda($l['valor']) ?></td>
            <td class="text-end">
              <?php if (!$l['prestacao_id'] && ($ehGestor || (int)$l['usuario_id'] === Auth::id())): ?>
              <button class="btn btn-sm btn-outline-danger" title="Excluir" onclick="Despesas.excluir('km', <?= $l['id'] ?>)"><i class="bi bi-trash"></i></button>
              <?php elseif ($l['prestacao_id']): ?><span class="badge text-bg-light border text-muted">consolidado</span><?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div></div>
  </div>

  <!-- ABA: REFEIÇÕES -->
  <div class="tab-pane fade" id="tabRefeicoes">
    <div class="card"><div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr>
          <th>Data</th><?php if ($ehGestor): ?><th>Usuário</th><?php endif; ?>
          <th>Tipo</th><th class="d-none d-md-table-cell">Justificativa</th>
          <th class="text-end">Gasto</th><th class="text-end">Reembolso</th><th class="d-none d-md-table-cell"></th><th></th>
        </tr></thead>
        <tbody>
          <?php if (!$refeicoes): ?><tr><td colspan="8" class="text-center text-muted py-4">Nenhuma refeição no período.</td></tr><?php endif; ?>
          <?php foreach ($refeicoes as $l): ?>
          <tr>
            <td class="text-nowrap"><?= data_br($l['data']) ?><?= $l['hora'] ? ' <span class="text-muted small">' . substr($l['hora'],0,5) . '</span>' : '' ?></td>
            <?php if ($ehGestor): ?><td class="small"><?= e($l['usuario']) ?></td><?php endif; ?>
            <td><span class="badge text-bg-light border text-dark"><?= e($l['tipo'] ?? 'Almoço') ?></span></td>
            <td class="d-none d-md-table-cell small text-muted"><?= e($l['justificativa'] ?? '—') ?></td>
            <td class="text-end"><?= moeda($l['valor']) ?></td>
            <td class="text-end fw-semibold text-success"><?= moeda($l['valor_reembolso']) ?>
              <?php if ((float)$l['valor_reembolso'] < (float)$l['valor']): ?><i class="bi bi-info-circle text-warning ms-1" title="Limitado ao teto da categoria"></i><?php endif; ?>
            </td>
            <td class="d-none d-md-table-cell text-center">
              <?php if (!empty($l['comprovante'])): ?><a href="uploads/<?= e($l['comprovante']) ?>" target="_blank" title="Ver comprovante"><i class="bi bi-paperclip"></i></a><?php endif; ?>
            </td>
            <td class="text-end">
              <?php if (!$l['prestacao_id'] && ($ehGestor || (int)$l['usuario_id'] === Auth::id())): ?>
              <button class="btn btn-sm btn-outline-danger" title="Excluir" onclick="Despesas.excluir('refeicao', <?= $l['id'] ?>)"><i class="bi bi-trash"></i></button>
              <?php elseif ($l['prestacao_id']): ?><span class="badge text-bg-light border text-muted">consolidado</span><?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div></div>
  </div>

  <!-- ABA: PRESTAÇÃO DE CONTAS -->
  <div class="tab-pane fade" id="tabPrestacao">
    <div class="card mb-3 border-success">
      <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
          <h6 class="mb-1"><i class="bi bi-calendar-check me-1 text-success"></i>Prestação do mês corrente (<?= $mesLabel(date('Y-m')) ?>)</h6>
          <div class="small text-muted">
            KM: <strong><?= numero($previa['total_km'], 1) ?></strong> (<?= moeda($previa['total_km_valor']) ?>) ·
            Refeições: gasto <strong><?= moeda($previa['total_refeicoes_gasto']) ?></strong> · reembolso <strong><?= moeda($previa['total_refeicoes']) ?></strong> ·
            <span class="text-dark">Total a receber: <strong><?= moeda($previa['total_geral']) ?></strong></span>
          </div>
        </div>
        <button class="btn btn-success" onclick="Despesas.gerarPrestacao(<?= $previa['ano'] ?>, <?= $previa['mes'] ?>)"
                <?= $previa['total_geral'] <= 0 ? 'disabled' : '' ?>>
          <i class="bi bi-file-earmark-plus me-1"></i>Gerar prestação do mês
        </button>
      </div>
    </div>

    <div class="card"><div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr>
          <th>Competência</th><?php if ($ehGestor): ?><th>Usuário</th><?php endif; ?>
          <th class="text-end">KM (R$)</th><th class="text-end">Refeições</th><th class="text-end">Total</th>
          <th>Status</th><th class="text-end"></th>
        </tr></thead>
        <tbody>
          <?php if (!$prestacoes): ?><tr><td colspan="7" class="text-center text-muted py-4">Nenhuma prestação gerada.</td></tr><?php endif; ?>
          <?php foreach ($prestacoes as $p): ?>
          <tr>
            <td class="fw-semibold"><?= $mesLabel(sprintf('%04d-%02d', $p['ano'], $p['mes'])) ?></td>
            <?php if ($ehGestor): ?><td class="small"><?= e($p['usuario']) ?></td><?php endif; ?>
            <td class="text-end"><?= moeda($p['total_km_valor']) ?></td>
            <td class="text-end"><?= moeda($p['total_refeicoes']) ?></td>
            <td class="text-end fw-semibold"><?= moeda($p['total_geral']) ?></td>
            <td><span class="badge text-bg-<?= $statusCor[$p['status']] ?? 'secondary' ?>"><?= e($p['status']) ?></span></td>
            <td class="text-end text-nowrap">
              <button class="btn btn-sm btn-outline-secondary" title="Ver" onclick="Despesas.verPrestacao(<?= $p['id'] ?>)"><i class="bi bi-eye"></i></button>
              <?php if (!$ehGestor && (int)$p['usuario_id'] === Auth::id() && in_array($p['status'], ['Aberta','Rejeitada'], true)): ?>
              <button class="btn btn-sm btn-outline-primary" title="Enviar para aprovação" onclick="Despesas.enviarPrestacao(<?= $p['id'] ?>)"><i class="bi bi-send"></i></button>
              <?php endif; ?>
              <?php if ($ehGestor && $p['status'] === 'Enviada' && in_array(Auth::perfil(), ['Administrador','Gestor Comercial','Gestor Técnico'], true)): ?>
              <button class="btn btn-sm btn-outline-success" title="Avaliar" onclick="Despesas.avaliar(<?= $p['id'] ?>)"><i class="bi bi-check2-square"></i></button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div></div>
  </div>
</div>

<!-- Modal: Quilometragem -->
<div class="modal fade" id="modalKm" tabindex="-1">
  <div class="modal-dialog modal-fullscreen-sm-down">
    <form class="modal-content" id="formKm" onsubmit="return Despesas.salvarKm(event)">
      <div class="modal-header"><h5 class="modal-title"><i class="bi bi-signpost-2 me-2 text-success"></i>Lançar Quilometragem</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label">Data *</label><input type="date" name="data" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
          <div class="col-md-6">
            <label class="form-label">Veículo</label>
            <div class="input-group">
              <select name="veiculo_id" class="form-select" onchange="Despesas.veiculoMudou()">
                <option value="0">— selecione —</option>
                <?php foreach ($veiculos as $v): ?>
                  <option value="<?= $v['id'] ?>"><?= e($v['descricao']) ?><?= $v['placa'] ? ' — ' . e($v['placa']) : '' ?></option>
                <?php endforeach; ?>
              </select>
              <button type="button" class="btn btn-outline-success" title="Cadastrar veículo" onclick="Despesas.novoVeiculo()"><i class="bi bi-plus-lg"></i></button>
            </div>
          </div>
          <div class="col-6">
            <label class="form-label">KM inicial *</label>
            <div class="input-group">
              <input type="number" step="0.1" name="km_inicial" class="form-control" required oninput="Despesas.previewKm()">
              <button type="button" class="btn btn-outline-secondary" id="btnUltimoKm" title="Pegar a KM final do último lançamento" onclick="Despesas.pegarUltimoKm()"><i class="bi bi-clock-history"></i></button>
            </div>
          </div>
          <div class="col-6"><label class="form-label">KM final *</label><input type="number" step="0.1" name="km_final" class="form-control" required oninput="Despesas.previewKm()"></div>
          <div class="col-12">
            <div class="alert alert-light border mb-0 py-2 small" id="kmPreview">Informe os KM para calcular o valor.</div>
          </div>

          <!-- Tipo de deslocamento -->
          <div class="col-12">
            <label class="form-label d-block">Destino do deslocamento *</label>
            <div class="btn-group w-100" role="group">
              <input type="radio" class="btn-check" name="tipo_destino" id="td_prod" value="Produtor" checked onchange="Despesas.tipoDestino('Produtor')">
              <label class="btn btn-outline-success" for="td_prod"><i class="bi bi-person me-1"></i>Produtor</label>
              <input type="radio" class="btn-check" name="tipo_destino" id="td_fil" value="Filial" onchange="Despesas.tipoDestino('Filial')">
              <label class="btn btn-outline-success" for="td_fil"><i class="bi bi-building me-1"></i>Filial</label>
              <input type="radio" class="btn-check" name="tipo_destino" id="td_lug" value="Lugar" onchange="Despesas.tipoDestino('Lugar')">
              <label class="btn btn-outline-success" for="td_lug"><i class="bi bi-geo-alt me-1"></i>Lugar</label>
            </div>
          </div>

          <!-- Produtor -->
          <div class="col-12 destino-bloco" data-destino="Produtor">
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" id="chkProspecto" onchange="Despesas.toggleProspecto(this.checked)">
              <label class="form-check-label small" for="chkProspecto">É um cliente prospecto (em prospecção)</label>
            </div>
            <div id="blocoProdutor">
              <select name="cliente_produtor" class="form-select" onchange="Despesas.setCliente(this.value)">
                <option value="0">Selecione o produtor…</option>
                <?php foreach ($clientes as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['nome']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div id="blocoProspecto" class="d-none">
              <div class="input-group">
                <select name="cliente_prospecto" class="form-select" onchange="Despesas.setCliente(this.value)">
                  <option value="0">Selecione o prospecto…</option>
                  <?php foreach ($prospectos as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['nome']) ?></option><?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-outline-success" title="Pré-cadastrar prospecto" onclick="Despesas.novoProspecto()"><i class="bi bi-person-plus"></i></button>
              </div>
              <div class="form-text">O prospecto vira um cliente que você completa depois (propriedade, potencial, dados) na tela de Clientes.</div>
            </div>
            <input type="hidden" name="cliente_id" value="0">
          </div>

          <!-- Filial -->
          <div class="col-12 destino-bloco d-none" data-destino="Filial">
            <select name="filial_id" class="form-select">
              <option value="0">Selecione a filial…</option>
              <?php foreach ($filiais as $f): ?><option value="<?= $f['id'] ?>"><?= e($f['nome']) ?> — <?= e($f['municipio']) ?>/<?= e($f['estado']) ?></option><?php endforeach; ?>
            </select>
          </div>

          <!-- Lugar -->
          <div class="col-12 destino-bloco d-none" data-destino="Lugar">
            <input name="destino" class="form-control" placeholder="Informe aonde está indo (localidade/município)">
          </div>

          <div class="col-12"><label class="form-label">Motivo do deslocamento *</label>
            <div class="campo-voz"><input name="motivo" class="form-control" placeholder="Ex.: visita técnica, entrega de proposta…">
              <button type="button" class="btn-voz" title="Ditar por voz"><i class="bi bi-mic-fill"></i></button></div>
          </div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Salvar</button></div>
    </form>
  </div>
</div>

<!-- Modal: pré-cadastro de prospecto -->
<div class="modal fade" id="modalProspecto" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" id="formProspecto" onsubmit="return Despesas.salvarProspecto(event)">
      <div class="modal-header"><h5 class="modal-title"><i class="bi bi-person-plus me-2 text-success"></i>Pré-cadastrar prospecto</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <p class="text-muted small">Basta o nome. Você completa telefone, município, propriedade, potencial e demais dados depois na tela de <strong>Clientes</strong> ou durante a visita.</p>
        <div class="row g-3">
          <div class="col-12"><label class="form-label">Nome *</label><input name="nome" class="form-control" required></div>
          <div class="col-12"><label class="form-label">Telefone <span class="text-muted small">(opcional)</span></label><input name="telefone" class="form-control"></div>
          <div class="col-12"><label class="form-label">Município <span class="text-muted small">(opcional)</span></label>
            <select name="municipio_id" class="form-select">
              <option value="0">—</option>
              <?php foreach ($municipios as $m): ?><option value="<?= $m['id'] ?>"><?= e($m['nome']) ?> (<?= e($m['estado']) ?>)</option><?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-success">Salvar pré-cadastro</button></div>
    </form>
  </div>
</div>

<!-- Modal: novo veículo -->
<div class="modal fade" id="modalVeiculo" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <form class="modal-content" id="formVeiculo" onsubmit="return Despesas.salvarVeiculo(event)">
      <div class="modal-header"><h5 class="modal-title"><i class="bi bi-truck me-2 text-success"></i>Novo veículo</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-2"><label class="form-label">Descrição *</label><input name="descricao" class="form-control" placeholder="Ex.: Fiat Strada" required></div>
        <div><label class="form-label">Placa</label><input name="placa" class="form-control" placeholder="ABC1D23"></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-success">Salvar</button></div>
    </form>
  </div>
</div>

<!-- Modal: Refeição -->
<div class="modal fade" id="modalRefeicao" tabindex="-1">
  <div class="modal-dialog modal-fullscreen-sm-down">
    <form class="modal-content" id="formRefeicao" onsubmit="return Despesas.salvarRefeicao(event)" enctype="multipart/form-data"
          data-valores='<?= json_encode($valoresRefeicao, JSON_UNESCAPED_UNICODE) ?>'>
      <div class="modal-header"><h5 class="modal-title"><i class="bi bi-cup-hot me-2 text-success"></i>Lançar Refeição</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-12"><label class="form-label">Data e horário *</label>
            <input type="datetime-local" name="datahora" class="form-control" value="<?= date('Y-m-d\TH:i') ?>" required></div>
          <div class="col-12">
            <label class="form-label d-block">Tipo *</label>
            <div class="btn-group w-100 flex-wrap" role="group">
              <?php foreach ($tiposRefeicao as $i => $t): ?>
                <input type="radio" class="btn-check" name="tipo" id="ref_<?= $i ?>" value="<?= e($t) ?>" <?= $i === 1 ? 'checked' : '' ?> onchange="Despesas.tipoRefeicao()">
                <label class="btn btn-outline-success" for="ref_<?= $i ?>"><?= e($t) ?></label>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="col-12"><label class="form-label">Valor gasto (nota) *</label><input type="number" step="0.01" min="0" name="valor" class="form-control" required oninput="Despesas.previewRefeicao()"></div>
          <div class="col-12"><div class="alert alert-light border mb-0 py-2 small" id="refeicaoPreview">Selecione o tipo e informe o valor.</div></div>
          <div class="col-12">
            <label class="form-label">Comprovante</label>
            <input type="file" name="comprovante" id="refComprovante" class="d-none" accept="image/*,.pdf" capture="environment" onchange="Despesas.previewComprovante(this)">
            <div id="refDropzone" class="dropzone-foto" onclick="document.getElementById('refComprovante').click()">
              <div id="refDropVazio" class="text-center py-4">
                <i class="bi bi-camera fs-2 text-success d-block mb-1"></i>
                <span class="fw-semibold text-success">Tirar foto ou anexar</span>
                <div class="small text-muted">toque para usar a câmera ou escolher um arquivo (foto/PDF)</div>
              </div>
              <div id="refDropPreview" class="d-none position-relative text-center">
                <img id="refDropImg" alt="Comprovante" class="dropzone-previa">
                <div id="refDropPdf" class="d-none py-4"><i class="bi bi-file-earmark-pdf fs-1 text-danger"></i><div class="small" id="refDropNome"></div></div>
                <button type="button" class="btn btn-sm btn-light border position-absolute top-0 end-0 m-1" onclick="Despesas.limparComprovante(event)"><i class="bi bi-x-lg"></i></button>
              </div>
            </div>
          </div>
          <div class="col-12"><label class="form-label">Justificativa</label>
            <div class="campo-voz"><input name="justificativa" class="form-control" placeholder="Ex.: almoço durante visita a produtor">
              <button type="button" class="btn-voz" title="Ditar por voz"><i class="bi bi-mic-fill"></i></button></div>
          </div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Salvar</button></div>
    </form>
  </div>
</div>

<!-- Modal: detalhe/avaliação da prestação -->
<div class="modal fade" id="modalPrestacao" tabindex="-1">
  <div class="modal-dialog modal-lg modal-fullscreen-sm-down">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"><i class="bi bi-file-earmark-text me-2 text-success"></i>Prestação de Contas</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body" id="prestacaoCorpo"></div>
    </div>
  </div>
</div>

<script src="assets/js/despesas.js?v=<?= filemtime(dirname(__DIR__, 2) . '/public/assets/js/despesas.js') ?>"></script>
