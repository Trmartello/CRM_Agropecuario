<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\Permissoes;

class UsuariosController
{
    private const PERFIS = ['Administrador', 'Gestor Comercial', 'Gestor Técnico', 'Consultor Técnico', 'Vendedor', 'Analista', 'Produtor'];

    public function index(): void
    {
        Permissoes::exigir(['Administrador']);
        $usuarios = Database::todos(
            'SELECT u.id, u.nome, u.email, u.perfil, u.telefone, u.ativo, u.categoria_reembolso_id, cr.nome AS categoria
               FROM usuarios u LEFT JOIN categorias_reembolso cr ON cr.id = u.categoria_reembolso_id
              ORDER BY u.nome'
        );
        $categorias = Database::todos('SELECT id, nome FROM categorias_reembolso WHERE ativo = 1 ORDER BY nome');
        render('usuarios', ['usuarios' => $usuarios, 'perfis' => self::PERFIS, 'categorias' => $categorias, 'titulo' => 'Usuários']);
    }

    public function salvar(): void
    {
        Permissoes::exigir(['Administrador']);
        $id = (int) ($_POST['id'] ?? 0);
        $nome = trim($_POST['nome'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $perfil = $_POST['perfil'] ?? '';
        $senha = $_POST['senha'] ?? '';

        if ($nome === '' || $email === '' || !in_array($perfil, self::PERFIS, true)) {
            json_erro('Preencha nome, e-mail e perfil.');
        }
        $duplicado = Database::um('SELECT id FROM usuarios WHERE email = ? AND id <> ?', [$email, $id]);
        if ($duplicado) {
            json_erro('Já existe um usuário com este e-mail.');
        }

        $categoriaId = (int) ($_POST['categoria_reembolso_id'] ?? 0) ?: null;
        if ($categoriaId !== null && !Database::valor('SELECT 1 FROM categorias_reembolso WHERE id = ?', [$categoriaId])) {
            $categoriaId = null;
        }

        if ($id > 0) {
            Database::executar(
                'UPDATE usuarios SET nome=?, email=?, perfil=?, telefone=?, categoria_reembolso_id=?, ativo=? WHERE id=?',
                [$nome, $email, $perfil, trim($_POST['telefone'] ?? '') ?: null, $categoriaId, (int) ($_POST['ativo'] ?? 1), $id]
            );
            if ($senha !== '') {
                Database::executar(
                    'UPDATE usuarios SET senha_hash=? WHERE id=?',
                    [password_hash($senha, PASSWORD_DEFAULT), $id]
                );
            }
        } else {
            if ($senha === '') {
                json_erro('Informe a senha do novo usuário.');
            }
            Database::executar(
                'INSERT INTO usuarios (nome, email, perfil, telefone, categoria_reembolso_id, senha_hash, ativo) VALUES (?,?,?,?,?,?,1)',
                [$nome, $email, $perfil, trim($_POST['telefone'] ?? '') ?: null, $categoriaId, password_hash($senha, PASSWORD_DEFAULT)]
            );
            $id = Database::ultimoId();
        }
        json_ok(['id' => $id]);
    }
}
