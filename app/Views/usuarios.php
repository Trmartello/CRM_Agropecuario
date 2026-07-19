<div class="d-flex justify-content-between align-items-center mb-3">
  <p class="text-muted mb-0">Gestão de usuários e perfis de acesso.</p>
  <button class="btn btn-success" onclick="Usuarios.novo()"><i class="bi bi-person-plus me-1"></i>Novo Usuário</button>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr><th>Nome</th><th class="d-none d-md-table-cell">E-mail</th><th>Perfil</th><th class="d-none d-md-table-cell">Situação</th><th class="text-end"></th></tr>
      </thead>
      <tbody>
        <?php foreach ($usuarios as $u): ?>
        <tr>
          <td class="fw-semibold"><?= e($u['nome']) ?></td>
          <td class="d-none d-md-table-cell"><?= e($u['email']) ?></td>
          <td><span class="badge text-bg-light border text-dark"><?= e($u['perfil']) ?></span></td>
          <td class="d-none d-md-table-cell">
            <span class="badge text-bg-<?= $u['ativo'] ? 'success' : 'secondary' ?>"><?= $u['ativo'] ? 'Ativo' : 'Inativo' ?></span>
          </td>
          <td class="text-end">
            <button class="btn btn-sm btn-outline-secondary" onclick='Usuarios.editar(<?= json_encode($u, JSON_UNESCAPED_UNICODE) ?>)'><i class="bi bi-pencil"></i></button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal: usuário -->
<div class="modal fade" id="modalUsuario" tabindex="-1">
  <div class="modal-dialog modal-fullscreen-sm-down">
    <form class="modal-content" id="formUsuario" onsubmit="return Usuarios.salvar(event)">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-person-gear me-2 text-success"></i><span id="modalUsuarioTitulo">Novo Usuário</span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id" value="0">
        <div class="mb-3">
          <label class="form-label">Nome *</label>
          <input name="nome" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label">E-mail *</label>
          <input type="email" name="email" class="form-control" required>
        </div>
        <div class="row g-3">
          <div class="col-6">
            <label class="form-label">Perfil *</label>
            <select name="perfil" class="form-select" required>
              <?php foreach ($perfis as $p): ?><option><?= e($p) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label">Telefone</label>
            <input name="telefone" class="form-control">
          </div>
          <div class="col-6">
            <label class="form-label">Categoria de reembolso <span class="text-muted small">(KM/refeições)</span></label>
            <select name="categoria_reembolso_id" class="form-select">
              <option value="0">— não recebe —</option>
              <?php foreach ($categorias as $cat): ?><option value="<?= $cat['id'] ?>"><?= e($cat['nome']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label">Senha <span class="text-muted small">(vazio = manter)</span></label>
            <input type="password" name="senha" class="form-control" autocomplete="new-password">
          </div>
          <div class="col-6">
            <label class="form-label">Situação</label>
            <select name="ativo" class="form-select"><option value="1">Ativo</option><option value="0">Inativo</option></select>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Salvar</button>
      </div>
    </form>
  </div>
</div>
