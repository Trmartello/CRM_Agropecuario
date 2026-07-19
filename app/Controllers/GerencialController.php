<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\Permissoes;

/** Dashboards gerenciais (Módulo 16) — visão consolidada da equipe. */
class GerencialController
{
    public function index(): void
    {
        Permissoes::exigir(['Administrador', 'Gestor Comercial', 'Gestor Técnico', 'Analista']);
        $inicioMes = date('Y-m-01');

        // Desempenho por técnico/vendedor
        $equipe = Database::todos(
            "SELECT u.id, u.nome, u.perfil,
                    (SELECT COUNT(*) FROM visitas v WHERE v.usuario_id = u.id AND v.data_visita >= ?) AS visitas_mes,
                    (SELECT COUNT(*) FROM pedidos p WHERE p.usuario_id = u.id AND p.criado_em >= ? AND p.status <> 'Cancelado') AS pedidos_mes,
                    (SELECT COALESCE(SUM(p.valor_total),0) FROM pedidos p WHERE p.usuario_id = u.id AND p.criado_em >= ? AND p.status IN ('Aprovado','Faturado')) AS vendido_mes,
                    (SELECT COUNT(*) FROM clientes c WHERE c.responsavel_id = u.id AND c.ativo = 1) AS carteira
               FROM usuarios u
              WHERE u.perfil IN ('Consultor Técnico','Vendedor') AND u.ativo = 1
              ORDER BY vendido_mes DESC",
            [$inicioMes, $inicioMes, $inicioMes]
        );

        // Vendas por família (mês)
        $porFamilia = Database::todos(
            "SELECT f.nome AS familia,
                    COALESCE(SUM(pi.quantidade * pi.valor_unitario * (1 - pi.desconto_pct/100)),0) AS total
               FROM pedidos_itens pi
               JOIN pedidos p ON p.id = pi.pedido_id AND p.status IN ('Aprovado','Faturado') AND p.criado_em >= ?
               JOIN produtos pr ON pr.id = pi.produto_id
               JOIN familias_produto f ON f.id = pr.familia_id
              GROUP BY f.id ORDER BY total DESC",
            [$inicioMes]
        );

        // Funil consolidado
        $funil = Database::todos(
            "SELECT estagio, COUNT(*) AS qtd, COALESCE(SUM(valor_estimado),0) AS valor
               FROM oportunidades WHERE estagio NOT IN ('Ganha','Perdida') GROUP BY estagio"
        );

        // Reclamações por status
        $reclamacoes = Database::todos(
            "SELECT status, COUNT(*) AS qtd FROM reclamacoes GROUP BY status ORDER BY qtd DESC"
        );

        // Despesas do mês (equipe)
        $despesas = Database::um(
            "SELECT
               (SELECT COALESCE(SUM(valor),0) FROM quilometragem WHERE data >= ?) AS km,
               (SELECT COALESCE(SUM(valor_reembolso),0) FROM refeicoes WHERE data >= ?) AS refeicoes",
            [$inicioMes, $inicioMes]
        );

        // Totais gerais
        $totais = Database::um(
            "SELECT
               (SELECT COUNT(*) FROM clientes WHERE ativo = 1) AS clientes,
               (SELECT COUNT(*) FROM visitas WHERE data_visita >= ?) AS visitas,
               (SELECT COALESCE(SUM(valor_total),0) FROM pedidos WHERE criado_em >= ? AND status IN ('Aprovado','Faturado')) AS vendido,
               (SELECT COUNT(*) FROM reclamacoes WHERE status NOT IN ('Encerrada','Improcedente')) AS reclamacoes_abertas",
            [$inicioMes, $inicioMes]
        );

        render('gerencial', compact('equipe', 'porFamilia', 'funil', 'reclamacoes', 'despesas', 'totais')
            + ['titulo' => 'Painel Gerencial']);
    }
}
