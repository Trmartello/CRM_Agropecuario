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
          responsável, nível tecnológico, potencial, segmento e coordenadas) e
          <strong>score do território</strong> (tipo <code>score_imovel</code> — potencial/realizado/share/gap
          por imóvel e safra para o Mapa Territorial; substitui o score de demonstração).
        </p>
        <form id="formCargaCap" onsubmit="return Integracao.importarCarga(event)" class="d-flex gap-2 align-items-center flex-wrap">
          <input type="file" name="arquivo" class="form-control" accept="application/json,.json" required style="max-width:320px">
          <button class="btn btn-success"><i class="bi bi-upload me-1"></i>Importar</button>
        </form>
        <div id="cargaCapResumo" class="small mt-2"></div>
      </div>
    </div>

    <div class="card mt-3">
      <div class="card-header"><i class="bi bi-geo-alt me-2 text-success"></i><strong>Base do CAR por município (SICAR)</strong></div>
      <div class="card-body">
        <p class="text-muted small mb-2">
          Baixe o <strong>shapefile do município</strong> na consulta pública do SICAR e importe aqui.
          O app passa a <strong>identificar o imóvel pela posição (GPS)</strong> e desenhar a divisa oficial no croqui —
          inclusive <strong>offline</strong> no campo. Reimportar o mesmo município substitui a base.
        </p>
        <form id="formCarMunicipio" onsubmit="return Integracao.importarCarMunicipio(event)">
          <div class="row g-2 align-items-end">
            <div class="col-7"><label class="form-label small mb-1">Município <span class="text-muted">(opcional)</span></label>
              <input name="municipio" class="form-control form-control-sm" placeholder="ex.: Concórdia"></div>
            <div class="col-5"><label class="form-label small mb-1">UF <span class="text-muted">(auto)</span></label>
              <input name="uf" class="form-control form-control-sm" placeholder="detectada" maxlength="2"></div>
            <div class="col-12"><div class="form-text small mt-0">Importe um município por vez — cada carga <strong>soma</strong> aos já carregados (dedup pelo código do imóvel, não apaga os outros).</div></div>
            <div class="col-12"><label class="form-label small mb-1">Arquivo .zip do CAR (Shapefile)</label>
              <input type="file" name="arquivo" class="form-control form-control-sm" accept=".zip,application/zip" required></div>
            <div class="col-12"><button class="btn btn-success btn-sm w-100"><i class="bi bi-upload me-1"></i>Importar município</button></div>
            <div class="col-12"><div class="form-text">A base por município do SICAR normalmente <strong>não traz o nome do município</strong> — digite-o acima. A <strong>UF é detectada sozinha</strong> do código do CAR (deixe em branco).</div></div>
          </div>
        </form>
        <div id="carMunicipioResumo" class="small mt-2"></div>
        <?php if (!empty($carMunicipios)): ?>
          <div class="mt-2 small">
            <div class="text-muted mb-1">Municípios carregados:</div>
            <?php foreach ($carMunicipios as $m): ?>
              <div class="d-flex justify-content-between border-bottom py-1">
                <span><i class="bi bi-geo me-1 text-success"></i><?= e($m['municipio']) ?>/<?= e($m['uf']) ?></span>
                <span class="text-muted"><?= numero($m['imoveis']) ?> imóveis</span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <hr class="my-3">
        <div class="small text-muted mb-2">
          <i class="bi bi-diagram-3 me-1 text-success"></i>Preencha a <strong>divisa e o nº do CAR</strong> de cada propriedade
          que tem a <strong>sede cadastrada</strong>, automaticamente, a partir da base importada.
        </div>
        <button class="btn btn-outline-success btn-sm w-100" onclick="Integracao.vincularCar(event)">
          <i class="bi bi-magic me-1"></i>Vincular CAR às propriedades
        </button>
        <div id="vincularCarResumo" class="small mt-2"></div>
        <hr class="my-3">
        <div class="small text-muted mb-2">
          <i class="bi bi-map me-1 text-success"></i>Gere o <strong>Mapa Territorial</strong> de um município a partir da base do CAR
          importada — cada imóvel do CAR vira um imóvel do mapa (potencial, realizado, share e gap virão do Qlik).
        </div>
        <div class="row g-2 align-items-end">
          <div class="col-7"><label class="form-label small mb-1">Município</label>
            <input id="territMun" class="form-control form-control-sm" placeholder="ex.: Concórdia"></div>
          <div class="col-5"><label class="form-label small mb-1">UF <span class="text-muted">(opc.)</span></label>
            <input id="territUf" class="form-control form-control-sm" placeholder="auto" maxlength="2"></div>
          <div class="col-12"><button class="btn btn-outline-success btn-sm w-100" onclick="Integracao.gerarTerritorio(event)">
            <i class="bi bi-map me-1"></i>Gerar Mapa Territorial do município</button></div>
        </div>
        <div id="territorioResumo" class="small mt-2"></div>
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
  async importarCarMunicipio(ev) {
    ev.preventDefault();
    const alvo = document.getElementById('carMunicipioResumo');
    alvo.innerHTML = '<span class="text-muted"><span class="spinner-border spinner-border-sm me-1"></span>Lendo o shapefile do município (pode levar um minuto)…</span>';
    try {
      const r = await App.json('index.php?r=integracao/importar-car-municipio', { method: 'POST', body: new FormData(ev.target) });
      const munis = (r.municipios || []).map(m => `${App.escapeHtml(m.municipio)}/${App.escapeHtml(m.uf)} (${m.imoveis})`).join(', ');
      alvo.innerHTML = `<div class="alert alert-success py-2 mb-1"><strong>${r.imoveis}</strong> imóveis importados — ${munis}.</div>`;
      setTimeout(() => location.reload(), 1400);
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
  async vincularCar(ev) {
    const btn = ev.currentTarget, alvo = document.getElementById('vincularCarResumo');
    if (!confirm('Preencher a divisa e o nº do CAR das propriedades que têm sede cadastrada e ainda sem divisa? (não altera as que já têm divisa)')) return;
    btn.disabled = true;
    alvo.innerHTML = '<span class="text-muted"><span class="spinner-border spinner-border-sm me-1"></span>Cruzando as sedes com a base do CAR…</span>';
    try {
      const r = await App.json('index.php?r=integracao/vincular-car-propriedades', { method: 'POST', body: new FormData() });
      alvo.innerHTML = `<div class="alert alert-success py-2 mb-1"><strong>${r.vinculadas}</strong> propriedade(s) receberam a divisa do CAR.`
        + (r.sem_car ? ` <span class="text-muted d-block">${r.sem_car} com sede, mas fora de qualquer imóvel do CAR (ajuste a sede ou desenhe no croqui).</span>` : '')
        + (r.sem_sede ? ` <span class="text-muted d-block">${r.sem_sede} sem coordenada de sede (cadastre a localização para vincular).</span>` : '')
        + '</div>';
    } catch (e) { alvo.innerHTML = ''; App.alerta(e.message, 'danger'); }
    btn.disabled = false;
  },
  async gerarTerritorio(ev) {
    const btn = ev.currentTarget, alvo = document.getElementById('territorioResumo');
    const municipio = (document.getElementById('territMun').value || '').trim();
    const uf = (document.getElementById('territUf').value || '').trim();
    if (!municipio) { App.alerta('Informe o município.', 'warning'); return; }
    btn.disabled = true;
    alvo.innerHTML = '<span class="text-muted"><span class="spinner-border spinner-border-sm me-1"></span>Gerando o mapa a partir da base do CAR…</span>';
    try {
      const fd = new FormData();
      fd.append('municipio', municipio);
      if (uf) fd.append('uf', uf);
      const r = await App.json('index.php?r=integracao/gerar-territorio', { method: 'POST', body: fd });
      alvo.innerHTML = `<div class="alert alert-success py-2 mb-1"><strong>${r.lido}</strong> imóvel(is) de `
        + `${App.escapeHtml(r.municipio)}/${App.escapeHtml(r.uf)} no mapa `
        + `(${r.inseridos} novos, ${r.atualizados} atualizados).</div>`;
    } catch (e) { alvo.innerHTML = ''; App.alerta(e.message, 'danger'); }
    btn.disabled = false;
  },
};
</script>
