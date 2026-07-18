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
        $usuarios = Database::todos('SELECT id, nome, email, perfil, telefone, ativo FROM usuarios ORDER BY nome');
        render('usuarios', ['usuarios' => $usuarios, 'perfis' => self::PERFIS, 'titulo' => 'Usuários']);
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

        if ($id > 0) {
            Database::executar(
                'UPDATE usuarios SET nome=?, email=?, perfil=?, telefone=?, ativo=? WHERE id=?',
                [$nome, $email, $perfil, trim($_POST['telefone'] ?? '') ?: null, (int) ($_POST['ativo'] ?? 1), $id]
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
                'INSERT INTO usuarios (nome, email, perfil, telefone, senha_hash, ativo) VALUES (?,?,?,?,?,1)',
                [$nome, $email, $perfil, trim($_POST['telefone'] ?? '') ?: null, password_hash($senha, PASSWORD_DEFAULT)]
            );
            $id = Database::ultimoId();
        }
        json_ok(['id' => $id]);
    }
}
