<?php use App\Core\Auth; ?>

<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
  <div class="d-flex flex-wrap gap-3">
    <?php foreach ($totais as $estagio => $t): ?>
      <div class="text-center">
        <div class="small text-muted"><?= e($estagio) ?></div>
        <div class="fw-bold"><?= $t['qtd'] ?> · <?= moeda($t['valor']) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalOportunidade">
    <i class="bi bi-plus-lg me-1"></i>Nova Oportunidade
  </button>
</div>

<p class="text-muted small mb-2">
  Oportunidades automáticas são geradas por gap de recompra, potencial não atendido e calendário agronômico.
  <i class="bi bi-hourglass-split text-warning"></i> = pendente de aprovação de crédito.
</p>

<div class="kanban d-flex gap-3 overflow-auto pb-3">
  <?php foreach ($kanban as $estagio => $itens): ?>
  <div class="kanban-coluna flex-shrink-0">
    <div class="kanban-titulo d-flex justify-content-between align-items-center">
      <strong><?= e($estagio) ?></strong>
      <span class="badge text-bg-secondary"><?= count($itens) ?></span>
    </div>
    <div class="kanban-corpo">
      <?php foreach ($itens as $o): ?>
      <div class="card kanban-cartao mb-2">
        <div class="card-body p-2">
          <div class="d-flex justify-content-between align-items-start gap-1">
            <strong class="small"><?= e($o['cliente']) ?></strong>
            <?php if ($o['pendente_aprovacao'] && !$o['aprovada_por']): ?>
              <i class="bi bi-hourglass-split text-warning" title="Pendente de aprovação de crédito"></i>
            <?php endif; ?>
          </div>
          <div class="small"><?= e($o['titulo']) ?></div>
          <div class="small text-muted"><?= e($o['origem']) ?><?= $o['familia'] ? ' · ' . e($o['familia']) : '' ?></div>
          <?php if ($o['proposta_vencendo']): ?>
            <span class="badge text-bg-warning mt-1"><i class="bi bi-alarm me-1"></i>Proposta vence <?= data_br($o['proposta_validade']) ?></span>
          <?php endif; ?>
          <?php if ($o['estagio'] === 'Perdida' && $o['motivo_perda']): ?>
            <span class="badge text-bg-danger mt-1">Perda: <?= e($o['motivo_perda']) ?><?= $o['concorrente_perda'] ? ' (' . e($o['concorrente_perda']) . ')' : '' ?></span>
          <?php endif; ?>
          <div class="d-flex justify-content-between align-items-center mt-2">
            <span class="fw-semibold small"><?= moeda($o['valor_estimado']) ?></span>
            <div class="btn-group">
              <?php if (!in_array($o['estagio'], ['Ganha', 'Perdida'])): ?>
                <button class="btn btn-sm btn-outline-secondary" title="Registrar proposta" onclick="Funil.proposta(<?= $o['id'] ?>, <?= (float) $o['valor_estimado'] ?>)"><i class="bi bi-file-earmark-text"></i></button>
                <button class="btn btn-sm btn-outline-success" title="Mover de estágio" onclick="Funil.mover(<?= $o['id'] ?>, '<?= e($o['estagio']) ?>')"><i class="bi bi-arrow-right-circle"></i></button>
              <?php endif; ?>
              <?php if ($o['pendente_aprovacao'] && !$o['aprovada_por'] && in_array(Auth::perfil(), ['Administrador', 'Gestor Comercial'])): ?>
                <button class="btn btn-sm btn-warning" title="Aprovar crédito" onclick="Funil.aprovar(<?= $o['id'] ?>)"><i class="bi bi-check2-circle"></i></button>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if (!$itens): ?><div class="text-center text-muted small py-3">vazio</div><?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Modal: nova oportunidade manual -->
<div class="modal fade" id="modalOportunidade" tabindex="-1">
  <div class="modal-dialog modal-fullscreen-sm-down">
    <form class="modal-content" onsubmit="return Funil.salvar(event)">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-funnel me-2 text-success"></i>Nova Oportunidade</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Cliente *</label>
          <select name="cliente_id" class="form-select" required>
            <option value="">Selecione…</option>
            <?php foreach ($clientes as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['nome']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Título *</label>
          <input name="titulo" class="form-control" required placeholder="Ex.: Proposta de fertilizantes 26/27">
        </div>
        <div class="row g-3">
          <div class="col-6">
            <label class="form-label">Família</label>
            <select name="familia_id" class="form-select">
              <option value="">—</option>
              <?php foreach ($familias as $f): ?><option value="<?= $f['id'] ?>"><?= e($f['nome']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label">Valor estimado (R$)</label>
            <input name="valor_estimado" class="form-control" inputmode="decimal">
          </div>
          <div class="col-6">
            <label class="form-label">Fechamento previsto</label>
            <input type="date" name="data_prevista" class="form-control">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Criar</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: mover estágio -->
<div class="modal fade" id="modalMover" tabindex="-1">
  <div class="modal-dialog modal-sm modal-fullscreen-sm-down">
    <form class="modal-content" onsubmit="return Funil.confirmarMover(event)">
      <div class="modal-header">
        <h5 class="modal-title">Mover para…</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id">
        <div class="mb-3">
          <label class="form-label">Novo estágio</label>
          <select name="estagio" class="form-select" onchange="Funil.aoTrocarEstagio(this.value)">
            <option>Identificada</option><option>Proposta</option><option>Negociação</option><option>Ganha</option><option>Perdida</option>
          </select>
        </div>
        <div id="camposPerda" class="d-none">
          <div class="mb-3">
            <label class="form-label">Motivo da perda *</label>
            <select name="motivo_perda" class="form-select">
              <option value="">Selecione…</option>
              <option>Preço</option><option>Prazo</option><option>Concorrente</option><option>Desistência</option><option>Clima</option><option>Outro</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Concorrente (se aplicável)</label>
            <input name="concorrente_perda" class="form-control">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-success">Confirmar</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: proposta -->
<div class="modal fade" id="modalProposta" tabindex="-1">
  <div class="modal-dialog modal-fullscreen-sm-down">
    <form class="modal-content" onsubmit="return Funil.salvarProposta(event)">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-file-earmark-text me-2 text-success"></i>Registrar Proposta</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="oportunidade_id">
        <div class="row g-3">
          <div class="col-6">
            <label class="form-label">Validade *</label>
            <input type="date" name="validade" class="form-control" required>
          </div>
          <div class="col-6">
            <label class="form-label">Valor total (R$)</label>
            <input name="valor_total" class="form-control" inputmode="decimal">
          </div>
          <div class="col-12">
            <label class="form-label">Condição de pagamento</label>
            <input name="condicao_pagamento" class="form-control" placeholder="Ex.: safra, 30/60/90…">
          </div>
        </div>
        <p class="small text-muted mt-2 mb-0">Proposta com validade próxima gera pendência de follow-up no funil.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Salvar</button>
      </div>
    </form>
  </div>
</div>
