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
            header('Location: ' . url(Auth::perfil() === 'Produtor' ? 'portal' : 'dashboard'));
            exit;
        }
        render_parcial('login', ['erro' => 'E-mail ou senha inválidos.']);
    }

    public function sair(): void
    {
        Auth::sair();
        header('Location: ' . url('login'));
        exit;
    }
}
