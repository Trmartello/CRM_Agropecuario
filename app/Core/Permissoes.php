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

    /**
     * Perfis autorizados a ver o CUSTO INDIVIDUAL identificável do cooperado e a
     * NF do produtor (invariante 5): Diretoria (Administrador), Controladoria
     * (Analista) e Gestor Comercial. Todo acesso é auditado.
     *
     * ATENÇÃO: NÃO reutilizar GESTORES aqui — GESTORES inclui 'Gestor Técnico',
     * que é perfil de campo e, pelo invariante 5, NUNCA vê o custo do cooperado
     * (se soubesse o ponto de equilíbrio, negociaria com vantagem sobre ele).
     */
    public const PODE_CUSTO_INDIVIDUAL = ['Administrador', 'Analista', 'Gestor Comercial'];

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

    /** True se o perfil pode ver custo individual/NF do cooperado (invariante 5). */
    public static function podeCustoIndividual(): bool
    {
        return in_array(Auth::perfil(), self::PODE_CUSTO_INDIVIDUAL, true);
    }

    /** Exige perfil autorizado a custo individual/NF (invariante 5). */
    public static function exigirCustoIndividual(): void
    {
        self::exigir(self::PODE_CUSTO_INDIVIDUAL);
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
