<div class="card mb-3">
  <div class="card-body d-flex flex-wrap gap-3 align-items-end">
    <div>
      <label class="form-label mb-1">Consolidar por</label>
      <div class="btn-group" role="group" id="seletorDimensao">
        <button class="btn btn-success" data-dimensao="cliente">Produtor</button>
        <button class="btn btn-outline-success" data-dimensao="municipio">Município</button>
        <button class="btn btn-outline-success" data-dimensao="estado">Estado</button>
        <button class="btn btn-outline-success" data-dimensao="filial">Filial</button>
      </div>
    </div>
    <div>
      <label class="form-label mb-1">Família de produtos</label>
      <select id="filtroFamilia" class="form-select">
        <option value="">Todas</option>
        <?php foreach ($familias as $f): ?><option value="<?= $f['id'] ?>"><?= e($f['nome']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="form-label mb-1">Ordem</label>
      <select id="filtroOrdem" class="form-select">
        <option value="desc">Maior utilização primeiro</option>
        <option value="asc">Menor utilização primeiro</option>
      </select>
    </div>
    <div class="flex-grow-1" style="min-width:180px">
      <label class="form-label mb-1">Buscar</label>
      <input type="search" id="filtroBusca" class="form-control" placeholder="Nome do produtor, município…" oninput="Potencial.aplicar()">
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header"><i class="bi bi-bar-chart-line me-1 text-success"></i><strong>% do potencial utilizado</strong></div>
      <div class="card-body"><canvas id="graficoRanking" height="300"></canvas></div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header"><i class="bi bi-list-ol me-1 text-success"></i><strong>Ranking</strong></div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light" id="tabelaRankingHead"><tr>
            <th>#</th>
            <th role="button" data-col="dimensao" onclick="Potencial.ordenarPor('dimensao')">Dimensão <i class="bi bi-arrow-down-up ms-1 small"></i></th>
            <th role="button" class="text-end" data-col="potencial" onclick="Potencial.ordenarPor('potencial')">Potencial <i class="bi bi-arrow-down-up ms-1 small"></i></th>
            <th role="button" class="text-end" data-col="realizado" onclick="Potencial.ordenarPor('realizado')">Realizado <i class="bi bi-arrow-down-up ms-1 small"></i></th>
            <th role="button" class="text-end" data-col="percentual" onclick="Potencial.ordenarPor('percentual')">% <i class="bi bi-arrow-down-up ms-1 small"></i></th>
          </tr></thead>
          <tbody id="tabelaRanking">
            <tr><td colspan="5" class="text-center text-muted py-4">Carregando…</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>document.addEventListener('DOMContentLoaded', () => Potencial.iniciar());</script>
