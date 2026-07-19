<?php

namespace App\Controllers;

use App\Core\Auth;

class LoginController
{
    public function form(): void
    {
        if (Auth::logado()) {
            header('Location: ' . url(Auth::perfil() === 'Produtor' ? 'portal' : 'dashboard'));
            exit;
        }
        render_parcial('login', ['erro' => null]);
    }

    public function entrar(): void
    {
        $email = trim($_POST['email'] ?? '');
        $senha = $_POST['senha'] ?? '';
        if (Auth::tentar($email, $senha)) {
            sync_limpar_antigos(); // manutenção leve: poda uuids de idempotência antigos
            header('Location: ' . url(Auth::perfil() === 'Produtor' ? 'portal' : 'dashboard'));
            exit;
        }
        render_parcial('login', ['erro' => 'E-mail ou senha inválidos.']);
    }

    public function sair(): void
    {
        Auth::sair();
        header('Location: ' . url('login') . '&saiu=1');
        exit;
    }
}
