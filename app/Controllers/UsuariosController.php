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

        // Código do vendedor no ERP/CAP (vincula as cargas do Qlik)
        $codVendedor = (int) ($_POST['cod_vendedor'] ?? 0) ?: null;
        if ($codVendedor !== null
            && Database::um('SELECT id FROM usuarios WHERE cod_vendedor = ? AND id <> ?', [$codVendedor, $id])) {
            json_erro('Já existe um usuário com este código de vendedor (ERP).');
        }

        if ($id > 0) {
            Database::executar(
                'UPDATE usuarios SET nome=?, email=?, perfil=?, telefone=?, categoria_reembolso_id=?, cod_vendedor=?, ativo=? WHERE id=?',
                [$nome, $email, $perfil, trim($_POST['telefone'] ?? '') ?: null, $categoriaId, $codVendedor, (int) ($_POST['ativo'] ?? 1), $id]
            );
            if ($senha !== '') {
                // Senha definida pelo admin é temporária: o usuário cria a própria no
                // primeiro acesso (exceto quando o admin troca a própria senha).
                $trocar = $id !== \App\Core\Auth::id() ? 1 : 0;
                Database::executar(
                    'UPDATE usuarios SET senha_hash=?, trocar_senha=? WHERE id=?',
                    [password_hash($senha, PASSWORD_DEFAULT), $trocar, $id]
                );
            }
        } else {
            if ($senha === '') {
                json_erro('Informe a senha do novo usuário.');
            }
            Database::executar(
                'INSERT INTO usuarios (nome, email, perfil, telefone, categoria_reembolso_id, cod_vendedor, senha_hash, ativo, trocar_senha) VALUES (?,?,?,?,?,?,?,1,1)',
                [$nome, $email, $perfil, trim($_POST['telefone'] ?? '') ?: null, $categoriaId, $codVendedor, password_hash($senha, PASSWORD_DEFAULT)]
            );
            $id = Database::ultimoId();
        }
        auditar(((int) ($_POST['id'] ?? 0)) > 0 ? 'editar' : 'criar', 'usuario', $id,
            $email . ($senha !== '' ? ' (senha redefinida)' : ''));
        json_ok(['id' => $id]);
    }
}
