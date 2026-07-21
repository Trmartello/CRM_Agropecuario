<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Permissoes;
use App\Services\CapService;
use App\Services\CreditoService;
use App\Services\OportunidadeService;
use App\Services\PriorizacaoService;
use App\Services\ReclamacaoService;

class DashboardController
{
    public function index(): void
    {
        Auth::exigirLogin();
        if (Auth::perfil() === 'Produtor') {
            header('Location: ' . url('portal'));
            exit;
        }
        Permissoes::exigirInterno();
        [$filtro, $params] = Permissoes::filtroCarteira();

        // Gera/atualiza oportunidades automáticas da carteira ao abrir o painel
        OportunidadeService::gerarAutomaticas($filtro, $params);

        $inicioMes = date('Y-m-01');

        $filtroVisitas = Permissoes::ehGestor() ? '1=1' : 'v.usuario_id = ' . Auth::id();
        // Despesas: gestor vê a equipe; campo vê as próprias (tabelas de coluna única usuario_id)
        $filtroDespesa = Permissoes::ehGestor() ? '1=1' : 'usuario_id = ' . Auth::id();

        $indicadores = [
            'visitas_mes' => (int) Database::valor(
                "SELECT COUNT(*) FROM visitas v WHERE {$filtroVisitas} AND v.data_visita >= ?",
                [$inicioMes]
            ),
            'clientes_visitados' => (int) Database::valor(
                "SELECT COUNT(DISTINCT v.cliente_id) FROM visitas v WHERE {$filtroVisitas} AND v.data_visita >= ?",
                [$inicioMes]
            ),
            'fotos_mes' => (int) Database::valor(
                "SELECT COUNT(*) FROM visita_fotos vf JOIN visitas v ON v.id = vf.visita_id
                  WHERE {$filtroVisitas} AND v.data_visita >= ?",
                [$inicioMes]
            ),
            'pendencias_aprovacao' => (int) Database::valor(
                "SELECT COUNT(*) FROM oportunidades o JOIN clientes c ON c.id = o.cliente_id
                  WHERE o.pendente_aprovacao = 1 AND o.aprovada_por IS NULL
                    AND o.estagio NOT IN ('Ganha','Perdida') AND {$filtro}",
                $params
            ) + (int) Database::valor(
                "SELECT COUNT(*) FROM pedidos pe JOIN clientes c ON c.id = pe.cliente_id
                  WHERE pe.status = 'Pendente de aprovação' AND {$filtro}",
                $params
            ),
            'pedidos_mes' => (int) Database::valor(
                "SELECT COUNT(*) FROM pedidos pe JOIN clientes c ON c.id = pe.cliente_id
                  WHERE pe.criado_em >= ? AND pe.status <> 'Cancelado' AND {$filtro}",
                array_merge([$inicioMes], $params)
            ),
            'valor_vendido_mes' => (float) Database::valor(
                "SELECT COALESCE(SUM(pe.valor_total),0) FROM pedidos pe JOIN clientes c ON c.id = pe.cliente_id
                  WHERE pe.criado_em >= ? AND pe.status IN ('Aprovado','Faturado') AND {$filtro}",
                array_merge([$inicioMes], $params)
            ),
            'pacotes_mes' => (int) Database::valor(
                "SELECT COUNT(*) FROM pedidos pe JOIN clientes c ON c.id = pe.cliente_id
                  WHERE pe.criado_em >= ? AND pe.tipo = 'Pacote Agrícola' AND pe.status <> 'Cancelado' AND {$filtro}",
                array_merge([$inicioMes], $params)
            ),
            'reclamacoes_abertas' => ReclamacaoService::indicadores($filtro, $params)['abertas'],
            'despesas_mes' => (float) Database::valor(
                "SELECT COALESCE(SUM(valor),0) FROM quilometragem WHERE {$filtroDespesa} AND data >= ?",
                [$inicioMes]
            ) + (float) Database::valor(
                "SELECT COALESCE(SUM(valor),0) FROM refeicoes WHERE {$filtroDespesa} AND data >= ?",
                [$inicioMes]
            ),
        ];

        $prioridades = PriorizacaoService::listaPriorizada($filtro, $params, 5);

        $kanban = OportunidadeService::kanban($filtro, $params);
        $totaisFunil = OportunidadeService::totais($kanban);

        $clientesChurn = array_values(array_filter(
            PriorizacaoService::listaPriorizada($filtro, $params),
            fn ($c) => $c['risco_churn']
        ));

        $garantiasVencendo = CreditoService::garantiasVencendo($filtro, $params);

        $capGeral = CapService::atingimentoGeral(Auth::id());

        // "Seu dia em campo": agenda de hoje + visitas feitas + carteira vencida
        $hoje = date('Y-m-d');
        $eventosHoje = Database::todos(
            "SELECT e.id, e.tipo, e.titulo, e.hora, e.status, c.nome AS cliente, c.telefone
               FROM agenda_eventos e LEFT JOIN clientes c ON c.id = e.cliente_id
              WHERE e.usuario_id = ? AND e.data = ? AND e.status <> 'Cancelado'
              ORDER BY (e.status = 'Pendente') DESC, e.ordem, (e.hora IS NULL), e.hora",
            [Auth::id(), $hoje]
        );
        $meuDia = [
            'eventos' => $eventosHoje,
            'pendentes' => count(array_filter($eventosHoje, fn ($e) => $e['status'] === 'Pendente')),
            'visitas_hoje' => (int) Database::valor(
                'SELECT COUNT(*) FROM visitas WHERE usuario_id = ? AND data_visita = ?',
                [Auth::id(), $hoje]
            ),
            // Produtores da PRÓPRIA carteira sem visita há N dias (ou nunca visitados)
            'vencidas' => (int) Database::valor(
                'SELECT COUNT(*) FROM clientes c
                  WHERE c.ativo = 1 AND c.prospecto = 0 AND c.responsavel_id = ?
                    AND COALESCE((SELECT MAX(v.data_visita) FROM visitas v WHERE v.cliente_id = c.id), "2000-01-01")
                        <= DATE_SUB(?, INTERVAL ' . \App\Services\AgendaService::DIAS_VISITA_VENCIDA . ' DAY)',
                [Auth::id(), $hoje]
            ),
        ];

        render('dashboard', compact(
            'indicadores',
            'prioridades',
            'totaisFunil',
            'clientesChurn',
            'garantiasVencendo',
            'capGeral',
            'meuDia'
        ) + ['titulo' => 'Dashboard']);
    }
}
