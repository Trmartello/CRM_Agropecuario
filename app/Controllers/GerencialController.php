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

        // Segmentação da carteira (distribuição por segmento efetivo)
        $segmentacao = \App\Services\SegmentacaoService::distribuicao('1=1', []);

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

        // KPIs adicionais -----------------------------------------------------
        // Atingimento CAP médio da equipe (metas vigentes)
        $cap = Database::um(
            "SELECT AVG(LEAST(r.realizado / m.meta, 1.5)) AS atingimento
               FROM metas_cap m
               JOIN (SELECT meta_id, COALESCE(SUM(valor),0) AS realizado FROM realizado_cap GROUP BY meta_id) r
                 ON r.meta_id = m.id
              WHERE m.meta > 0 AND CURDATE() BETWEEN m.periodo_inicio AND m.periodo_fim"
        );
        // Conversão do funil (histórico de oportunidades fechadas)
        $conversao = Database::um(
            "SELECT SUM(estagio = 'Ganha') AS ganhas, SUM(estagio = 'Perdida') AS perdidas
               FROM oportunidades WHERE estagio IN ('Ganha','Perdida')"
        );
        // Clientes em risco de churn (cálculo em lote — rápido mesmo com carteira grande)
        $idsAtivos = array_map(fn ($c) => (int) $c['id'], Database::todos('SELECT id FROM clientes WHERE ativo = 1'));
        $quedas = \App\Services\ComercialService::quedaCompraLote($idsAtivos);
        $emChurn = count(array_filter($quedas, fn ($q) => $q['risco_churn']));
        // Aproveitamento do potencial na safra atual (realizado ÷ potencial)
        $safraAtual = \App\Services\ComercialService::safraAtual();
        $potencial = $safraAtual ? Database::um(
            'SELECT COALESCE(SUM(pc.valor_potencial),0) AS potencial,
                    (SELECT COALESCE(SUM(co.valor_total),0) FROM compras co WHERE co.safra_id = ?) AS realizado
               FROM potencial_compra pc WHERE pc.safra_id = ?',
            [(int) $safraAtual['id'], (int) $safraAtual['id']]
        ) : null;

        $kpis = [
            'cap_atingimento' => $cap && $cap['atingimento'] !== null ? round($cap['atingimento'] * 100) : null,
            'funil_ganhas' => (int) ($conversao['ganhas'] ?? 0),
            'funil_perdidas' => (int) ($conversao['perdidas'] ?? 0),
            'funil_conversao' => ((int) ($conversao['ganhas'] ?? 0) + (int) ($conversao['perdidas'] ?? 0)) > 0
                ? round($conversao['ganhas'] / ($conversao['ganhas'] + $conversao['perdidas']) * 100) : null,
            'clientes_churn' => $emChurn,
            'potencial_pct' => $potencial && (float) $potencial['potencial'] > 0
                ? round($potencial['realizado'] / $potencial['potencial'] * 100) : null,
        ];

        render('gerencial', compact('equipe', 'porFamilia', 'funil', 'reclamacoes', 'despesas', 'totais', 'kpis', 'segmentacao')
            + ['titulo' => 'Painel Gerencial']);
    }
}
