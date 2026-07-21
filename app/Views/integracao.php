<?php $statusCor = ['Sucesso'=>'success','Parcial'=>'warning','Erro'=>'danger','—'=>'light']; ?>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-hdd-network me-2 text-success"></i><strong>Fonte de dados</strong></div>
      <div class="card-body">
        <form id="formIntegracao" onsubmit="return Integracao.salvar(event)">
          <p class="text-muted small">Enquanto o ERP/CAPE não está conectado, o sistema opera com a base local. Ao configurar a URL do ERP, a fonte passa a ser externa — as telas não mudam, apenas a origem dos dados.</p>
          <div class="mb-3">
            <label class="form-label">Fonte atual</label>
            <select name="fonte" class="form-select">
              <?php foreach (['Local','ERP','CAPE'] as $f): ?><option <?= $fonte === $f ? 'selected' : '' ?>><?= $f ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">URL do ERP <span class="text-muted small">(quando disponível)</span></label>
            <input name="erp_url" class="form-control" value="<?= e($erpUrl) ?>" placeholder="https://erp.coperdia.com.br/api">
          </div>
          <div class="d-flex justify-content-between align-items-center">
            <span class="badge text-bg-<?= $erpConfigurado ? 'success' : 'secondary' ?>">
              <?= $erpConfigurado ? 'ERP configurado' : 'ERP não configurado (usando base local)' ?>
            </span>
            <button class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Salvar</button>
          </div>
        </form>
      </div>
    </div>
    <button class="btn btn-outline-success w-100 mb-3" onclick="Integracao.sincronizar('_tudo')"><i class="bi bi-arrow-repeat me-1"></i>Sincronizar tudo agora</button>

    <div class="card">
      <div class="card-header"><i class="bi bi-cloud-upload me-2 text-success"></i><strong>Carga inicial (arquivo do Qlik)</strong></div>
      <div class="card-body">
        <p class="text-muted small mb-2">
          Aceita dois arquivos JSON extraídos do Qlik: <strong>metas do CAP</strong> (tipo
          <code>cap_anual</code> — vínculo pelo Cód. vendedor no cadastro de Usuários; reimportar o
          mesmo ano substitui) e <strong>cadastro de clientes</strong> (tipo <code>clientes</code> —
          cria/atualiza produtores pelo código do ERP sem tocar no que o CRM enriquece:
          responsável, nível tecnológico, potencial, segmento e coordenadas).
        </p>
        <form id="formCargaCap" onsubmit="return Integracao.importarCarga(event)" class="d-flex gap-2 align-items-center flex-wrap">
          <input type="file" name="arquivo" class="form-control" accept="application/json,.json" required style="max-width:320px">
          <button class="btn btn-success"><i class="bi bi-upload me-1"></i>Importar</button>
        </form>
        <div id="cargaCapResumo" class="small mt-2"></div>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-list-check me-2 text-success"></i><strong>Entidades</strong></div>
      <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Entidade</th><th>Última sinc.</th><th>Status</th><th class="text-end">Registros</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($status as $s): ?>
          <tr>
            <td class="fw-semibold"><?= e($s['rotulo']) ?></td>
            <td class="small"><?= $s['ultima'] ? data_br($s['ultima']) . ' ' . substr($s['ultima'],11,5) : '—' ?></td>
            <td><span class="badge text-bg-<?= $statusCor[$s['status']] ?? 'secondary' ?> <?= ($statusCor[$s['status']] ?? '')==='light' ? 'border text-dark' : '' ?>"><?= e($s['status']) ?></span></td>
            <td class="text-end"><?= numero($s['registros']) ?></td>
            <td class="text-end"><button class="btn btn-sm btn-outline-secondary" title="Sincronizar" onclick="Integracao.sincronizar('<?= e($s['entidade']) ?>')"><i class="bi bi-arrow-repeat"></i></button></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>

    <div class="card">
      <div class="card-header"><i class="bi bi-clock-history me-2 text-success"></i><strong>Histórico de sincronização</strong></div>
      <div class="list-group list-group-flush" style="max-height:260px;overflow:auto">
        <?php if (!$historico): ?><div class="list-group-item text-muted small">Nenhuma sincronização registrada.</div><?php endif; ?>
        <?php foreach ($historico as $h): ?>
        <div class="list-group-item d-flex justify-content-between align-items-center py-2">
          <div><span class="badge text-bg-<?= $statusCor[$h['status']] ?? 'secondary' ?> me-1"><?= e($h['status']) ?></span>
            <?= e($entidades[$h['entidade']] ?? $h['entidade']) ?>
            <div class="small text-muted"><?= e($h['fonte']) ?> · <?= e($h['mensagem'] ?? '') ?></div></div>
          <span class="small text-muted text-nowrap"><?= data_br($h['criado_em']) ?> <?= substr($h['criado_em'],11,5) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<script>
const Integracao = {
  async salvar(ev) {
    ev.preventDefault();
    try {
      await App.json('index.php?r=integracao/salvar-config', { method: 'POST', body: new FormData(ev.target) });
      App.alerta('Configuração salva.');
      setTimeout(() => location.reload(), 600);
    } catch (e) { App.alerta(e.message, 'danger'); }
    return false;
  },
  async importarCarga(ev) {
    ev.preventDefault();
    const alvo = document.getElementById('cargaCapResumo');
    alvo.innerHTML = '<span class="text-muted"><span class="spinner-border spinner-border-sm me-1"></span>Importando…</span>';
    try {
      const r = await App.json('index.php?r=integracao/importar-carga', { method: 'POST', body: new FormData(ev.target) });
      const s = r.resumo;
      if (r.tipo === 'clientes') {
        alvo.innerHTML = `<div class="alert alert-success py-2 mb-1">
            Carga de clientes: <strong>${s.criados}</strong> criado(s) e <strong>${s.atualizados}</strong> atualizado(s)
            de ${s.clientes_arquivo} no arquivo${s.ignorados ? ` · ${s.ignorados} ignorado(s)` : ''}.
          </div><div class="text-muted">Os produtores entram sem responsável — distribua as carteiras em Produtores/Clientes.</div>`;
      } else {
        alvo.innerHTML = `<div class="alert alert-${s.vinculados > 0 ? 'success' : 'warning'} py-2 mb-1">
            Carga ${App.escapeHtml(String(s.ano))}: <strong>${s.vinculados}</strong> de ${s.vendedores_arquivo} vendedor(es) vinculados ·
            <strong>${s.metas_importadas}</strong> metas importadas.
          </div>` +
          (s.sem_usuario.length ? `<details><summary class="text-muted">${s.sem_usuario.length} vendedor(es) sem usuário no CRM (preencha o Cód. vendedor no cadastro e reimporte)</summary>
            <div class="mt-1" style="max-height:160px;overflow:auto">${s.sem_usuario.map(n => `<div class="text-muted">${App.escapeHtml(n)}</div>`).join('')}</div></details>` : '');
      }
    } catch (e) { alvo.innerHTML = ''; App.alerta(e.message, 'danger'); }
    return false;
  },
  async sincronizar(entidade) {
    try {
      const fd = new FormData(); fd.append('entidade', entidade);
      const r = await App.json('index.php?r=integracao/sincronizar', { method: 'POST', body: fd });
      App.alerta('Sincronização concluída.');
      setTimeout(() => location.reload(), 700);
    } catch (e) { App.alerta(e.message, 'danger'); }
  },
};
</script>
