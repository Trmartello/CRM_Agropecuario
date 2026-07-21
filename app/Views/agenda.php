<?php
use App\Core\Auth;
// Agrupa eventos por data
$porData = [];
foreach ($eventos as $e) { $porData[$e['data']][] = $e; }
$statusCor = ['Pendente' => 'warning', 'Concluído' => 'success', 'Cancelado' => 'secondary'];
$tipoIcone = ['Visita'=>'bi-clipboard2-pulse','Reunião'=>'bi-people','Tarefa'=>'bi-check2-square','Entrega'=>'bi-truck','Cobrança'=>'bi-cash-coin','Outro'=>'bi-dot'];
// Link do WhatsApp
$wa = function (?string $tel, string $texto): ?string {
    if (!$tel) return null;
    $num = preg_replace('/\D+/', '', $tel);
    if (strlen($num) < 10) return null;
    if (strlen($num) <= 11) $num = '55' . $num;
    return 'https://wa.me/' . $num . '?text=' . rawurlencode($texto);
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div class="d-flex gap-2 align-items-center">
    <form method="get" class="d-flex gap-2 align-items-center">
      <input type="hidden" name="r" value="agenda">
      <input type="month" name="mes" value="<?= e($mesRef) ?>" class="form-control form-control-sm" onchange="this.form.submit()">
      <?php if ($ehGestor): ?>
      <select name="usuario_id" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="0">Toda a equipe</option>
        <?php foreach ($equipe as $u): ?><option value="<?= $u['id'] ?>" <?= $usuarioFiltro === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['nome']) ?></option><?php endforeach; ?>
      </select>
      <?php endif; ?>
    </form>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-success" href="<?= url('agenda/organizador') ?>"><i class="bi bi-signpost-split me-1"></i>Organizar visitas</a>
    <button class="btn btn-success" onclick="Agenda.novo()"><i class="bi bi-calendar-plus me-1"></i>Novo evento</button>
  </div>
</div>

<!-- Roteiro do dia (organizador de visitas) -->
<?php if ($roteiroHoje): ?>
<div class="card mb-3 border-success">
  <div class="card-header bg-success-subtle d-flex align-items-center"><i class="bi bi-signpost-split me-2"></i><strong>Roteiro de hoje</strong>
    <span class="ms-auto small text-muted"><?= count($roteiroHoje) ?> parada(s)</span></div>
  <ol class="list-group list-group-flush list-group-numbered">
    <?php foreach ($roteiroHoje as $r): $link = $wa($r['cliente_telefone'] ?? null, 'Olá! Sobre a visita agendada (' . ($r['titulo']) . ').');
      $vencida = !empty($r['visita_vencida']) && ($r['status'] ?? '') !== 'Concluído';
      $diasTxt = isset($r['dias_sem_visita']) ? ($r['dias_sem_visita'] >= \App\Services\AgendaService::DIAS_TETO ? '+' . \App\Services\AgendaService::DIAS_TETO : $r['dias_sem_visita']) . 'd s/ visita' : null; ?>
    <li class="list-group-item d-flex align-items-center gap-2 <?= $vencida ? 'border-start border-warning border-3' : '' ?>">
      <div class="flex-grow-1">
        <span class="fw-semibold"><?= $r['hora'] ? substr($r['hora'],0,5) . ' · ' : '' ?><?= e($r['titulo']) ?></span>
        <?php if ($vencida): ?><span class="badge text-bg-warning text-dark ms-1" title="Sem visita há <?= $diasTxt ?>"><i class="bi bi-exclamation-triangle me-1"></i>Visita vencida</span><?php endif; ?>
        <div class="small text-muted"><?= e($r['cliente'] ?? '—') ?><?= $r['municipio'] ? ' · ' . e($r['municipio']) : '' ?><?= $diasTxt ? ' · ' . $diasTxt : '' ?></div>
      </div>
      <?php if ($link): ?><a class="btn btn-sm btn-outline-success" href="<?= e($link) ?>" target="_blank" title="Agendar por WhatsApp"><i class="bi bi-whatsapp"></i></a><?php endif; ?>
      <?php if ($r['latitude'] && $r['longitude']): ?><a class="btn btn-sm btn-outline-secondary" href="https://www.google.com/maps/search/?api=1&query=<?= $r['latitude'] ?>,<?= $r['longitude'] ?>" target="_blank" title="Abrir no mapa"><i class="bi bi-geo-alt"></i></a><?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ol>
</div>
<?php endif; ?>

<!-- Lista de eventos do mês -->
<div class="card"><div class="list-group list-group-flush">
  <?php if (!$porData): ?><div class="list-group-item text-muted text-center py-4">Nenhum evento no mês.</div><?php endif; ?>
  <?php foreach ($porData as $data => $lista): ?>
  <div class="list-group-item bg-light fw-semibold small text-uppercase text-muted"><?= data_br($data) ?></div>
  <?php foreach ($lista as $e): $link = $wa($e['cliente_telefone'] ?? null, 'Olá! Vamos agendar uma visita? (' . $e['titulo'] . ')'); ?>
  <div class="list-group-item d-flex align-items-center gap-2 <?= $e['status'] === 'Cancelado' ? 'opacity-50' : '' ?>">
    <i class="bi <?= $tipoIcone[$e['tipo']] ?? 'bi-dot' ?> fs-5 text-success"></i>
    <div class="flex-grow-1">
      <div class="fw-semibold"><?= $e['hora'] ? substr($e['hora'],0,5) . ' · ' : '' ?><?= e($e['titulo']) ?>
        <span class="badge text-bg-light border text-dark ms-1"><?= e($e['tipo']) ?></span></div>
      <div class="small text-muted"><?= e($e['cliente'] ?? '') ?><?= $ehGestor ? ' · ' . e($e['responsavel']) : '' ?><?= $e['descricao'] ? ' · ' . e($e['descricao']) : '' ?></div>
    </div>
    <span class="badge text-bg-<?= $statusCor[$e['status']] ?? 'secondary' ?>"><?= e($e['status']) ?></span>
    <?php if ($link): ?><a class="btn btn-sm btn-outline-success" href="<?= e($link) ?>" target="_blank" title="WhatsApp"><i class="bi bi-whatsapp"></i></a><?php endif; ?>
    <?php if ($e['status'] === 'Pendente'): ?>
    <button class="btn btn-sm btn-outline-success" title="Concluir" onclick="Agenda.status(<?= $e['id'] ?>,'Concluído')"><i class="bi bi-check-lg"></i></button>
    <button class="btn btn-sm btn-outline-secondary" title="Editar" onclick='Agenda.editar(<?= json_attr($e) ?>)'><i class="bi bi-pencil"></i></button>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php endforeach; ?>
</div></div>

<!-- Modal: evento -->
<div class="modal fade" id="modalAgenda" tabindex="-1">
  <div class="modal-dialog modal-fullscreen-sm-down">
    <form class="modal-content" id="formAgenda" onsubmit="return Agenda.salvar(event)">
      <div class="modal-header"><h5 class="modal-title"><i class="bi bi-calendar-plus me-2 text-success"></i><span id="agendaTitulo">Novo evento</span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="hidden" name="id" value="0">
        <div class="row g-3">
          <div class="col-12"><label class="form-label">Título *</label>
            <div class="campo-voz"><input name="titulo" class="form-control" required><button type="button" class="btn-voz" title="Ditar por voz" aria-label="Ditar por voz"><i class="bi bi-mic-fill"></i></button></div></div>
          <div class="col-6"><label class="form-label">Tipo</label>
            <select name="tipo" class="form-select"><?php foreach ($tipos as $t): ?><option><?= e($t) ?></option><?php endforeach; ?></select></div>
          <div class="col-6"><label class="form-label">Cliente</label>
            <select name="cliente_id" class="form-select"><option value="0">—</option>
              <?php foreach ($clientes as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['nome']) ?></option><?php endforeach; ?></select></div>
          <div class="col-6"><label class="form-label">Data *</label><input type="date" name="data" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
          <div class="col-6"><label class="form-label">Hora</label><input type="time" name="hora" class="form-control"></div>
          <div class="col-12"><label class="form-label">Descrição</label>
            <div class="campo-voz"><textarea name="descricao" rows="1" class="form-control auto-crescer" oninput="App.autoCrescer(this)"></textarea><button type="button" class="btn-voz" title="Ditar por voz" aria-label="Ditar por voz"><i class="bi bi-mic-fill"></i></button></div></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Salvar</button></div>
    </form>
  </div>
</div>

<!-- Modal: roteiro -->
<div class="modal fade" id="modalRoteiro" tabindex="-1">
  <div class="modal-dialog modal-lg modal-fullscreen-sm-down">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"><i class="bi bi-signpost-split me-2 text-success"></i>Roteiro de visitas</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body" id="roteiroCorpo"></div>
    </div>
  </div>
</div>

<script src="assets/js/agenda.js?v=<?= filemtime(dirname(__DIR__, 2) . '/public/assets/js/agenda.js') ?>"></script>
