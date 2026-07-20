<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;

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

        // Bloqueio temporário após muitas tentativas falhas (força bruta)
        $minutos = Auth::minutosBloqueado($email);
        if ($minutos > 0) {
            render_parcial('login', ['erro' => "Muitas tentativas de acesso. Aguarde {$minutos} minuto(s) e tente de novo."]);
            return;
        }

        if (Auth::tentar($email, $senha)) {
            sync_limpar_antigos();      // manutenção leve: poda uuids de idempotência antigos
            auditoria_limpar_antiga();  // retenção da auditoria: eventos > 1 ano
            \App\Services\SegmentacaoService::atualizarTodos(); // recalcula a segmentação (máx. 1x/12h)
            auditar('login', 'sessao', Auth::id());
            if (!empty(Auth::usuario()['trocar_senha'])) {
                header('Location: ' . url('login/trocar-senha'));
                exit;
            }
            header('Location: ' . url(Auth::perfil() === 'Produtor' ? 'portal' : 'dashboard'));
            exit;
        }

        Auth::registrarFalha($email);
        usleep(400000); // atrasa respostas de falha (dificulta automação de tentativas)
        render_parcial('login', ['erro' => 'E-mail ou senha inválidos.']);
    }

    /** Primeiro acesso / senha temporária: obriga a definir uma senha própria. */
    public function trocarSenhaForm(): void
    {
        Auth::exigirLogin();
        render_parcial('trocar_senha', ['erro' => null]);
    }

    public function salvarSenha(): void
    {
        Auth::exigirLogin();
        $nova = $_POST['senha'] ?? '';
        $confirma = $_POST['confirmar'] ?? '';

        if (strlen($nova) < 8 || !preg_match('/[A-Za-z]/', $nova) || !preg_match('/\d/', $nova)) {
            render_parcial('trocar_senha', ['erro' => 'A senha deve ter ao menos 8 caracteres, misturando letras e números.']);
            return;
        }
        if ($nova !== $confirma) {
            render_parcial('trocar_senha', ['erro' => 'As senhas digitadas não conferem.']);
            return;
        }
        $hashAtual = (string) Database::valor('SELECT senha_hash FROM usuarios WHERE id = ?', [Auth::id()]);
        if ($hashAtual !== '' && password_verify($nova, $hashAtual)) {
            render_parcial('trocar_senha', ['erro' => 'A nova senha não pode ser igual à senha atual.']);
            return;
        }

        Auth::definirNovaSenha($nova);
        auditar('trocar-senha', 'usuario', Auth::id());
        header('Location: ' . url(Auth::perfil() === 'Produtor' ? 'portal' : 'dashboard'));
        exit;
    }

    public function sair(): void
    {
        Auth::sair();
        header('Location: ' . url('login') . '&saiu=1');
        exit;
    }
}
