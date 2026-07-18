<?php

namespace App\Core;

/**
 * Controle de acesso por perfil.
 *
 * Perfis: Administrador, Gestor Comercial, Gestor Técnico,
 *         Consultor Técnico, Vendedor, Analista, Produtor.
 */
class Permissoes
{
    /** Perfis com visão de gestão (veem dados da equipe toda). */
    public const GESTORES = ['Administrador', 'Gestor Comercial', 'Gestor Técnico', 'Analista'];

    /** Perfis de campo (carteira própria). */
    public const CAMPO = ['Consultor Técnico', 'Vendedor'];

    /** Bloqueia o acesso se o perfil do usuário logado não estiver na lista. */
    public static function exigir(array $perfis): void
    {
        Auth::exigirLogin();
        if (!in_array(Auth::perfil(), $perfis, true)) {
            if (Auth::ehAjax()) {
                json_erro('Acesso negado para o seu perfil.', 403);
            }
            http_response_code(403);
            render('erro_403');
            exit;
        }
    }

    /** Todos os perfis internos (exceto Produtor, que terá portal próprio na Fase 4). */
    public static function exigirInterno(): void
    {
        self::exigir(['Administrador', 'Gestor Comercial', 'Gestor Técnico', 'Consultor Técnico', 'Vendedor', 'Analista']);
    }

    public static function ehGestor(): bool
    {
        return in_array(Auth::perfil(), self::GESTORES, true);
    }

    /**
     * Filtro de carteira: gestores veem tudo; vendedor/técnico só a própria carteira.
     * Retorna [clausulaSql, params] para compor no WHERE (usa alias c de clientes).
     */
    public static function filtroCarteira(string $aliasCliente = 'c'): array
    {
        if (self::ehGestor()) {
            return ['1=1', []];
        }
        return ["{$aliasCliente}.responsavel_id = ?", [Auth::id()]];
    }
}
