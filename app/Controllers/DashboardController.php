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

        render('dashboard', compact(
            'indicadores',
            'prioridades',
            'totaisFunil',
            'clientesChurn',
            'garantiasVencendo',
            'capGeral'
        ) + ['titulo' => 'Dashboard']);
    }
}
