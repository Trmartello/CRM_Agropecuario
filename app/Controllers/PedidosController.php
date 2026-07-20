<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Permissoes;
use App\Services\PacoteService;
use App\Services\PedidoService;

class PedidosController
{
    public function index(): void
    {
        Permissoes::exigirInterno();
        [$filtro, $params] = Permissoes::filtroCarteira();

        $busca = trim($_GET['busca'] ?? '');
        $where = $filtro;
        if ($busca !== '') {
            $where .= ' AND c.nome LIKE ?';
            $params[] = "%{$busca}%";
        }

        $pedidos = Database::todos(
            "SELECT pe.*, c.nome AS cliente, u.nome AS vendedor, pa.nome AS pacote,
                    (SELECT COUNT(*) FROM pedidos_itens i WHERE i.pedido_id = pe.id) AS qtd_itens
               FROM pedidos pe
               JOIN clientes c ON c.id = pe.cliente_id
               JOIN usuarios u ON u.id = pe.usuario_id
               LEFT JOIN pacotes_agricolas pa ON pa.id = pe.pacote_id
              WHERE {$where}
              ORDER BY pe.criado_em DESC
              LIMIT 200",
            $params
        );

        [$filtroC, $paramsC] = Permissoes::filtroCarteira();
        $clientes = Database::todos(
            "SELECT c.id, c.nome FROM clientes c WHERE c.ativo = 1 AND {$filtroC} ORDER BY c.nome",
            $paramsC
        );
        $catalogo = PedidoService::catalogo();
        $pacotes = PacoteService::vigentes();

        render('pedidos', compact('pedidos', 'clientes', 'catalogo', 'pacotes', 'busca') + ['titulo' => 'Pedidos']);
    }

    /** Cria pedido normal (modal AJAX). Itens em JSON no campo `itens`. */
    public function salvar(): void
    {
        Permissoes::exigir(['Administrador', 'Gestor Comercial', 'Vendedor', 'Consultor Técnico']);
        $clienteId = (int) ($_POST['cliente_id'] ?? 0);
        $this->exigirClienteDaCarteira($clienteId);

        $itens = json_decode($_POST['itens'] ?? '[]', true) ?: [];
        try {
            $resultado = PedidoService::criar($clienteId, $itens, [
                'tipo' => 'Normal',
                'condicao_pagamento' => trim($_POST['condicao_pagamento'] ?? '') ?: null,
                'observacao' => trim($_POST['observacao'] ?? '') ?: null,
            ]);
        } catch (\InvalidArgumentException $e) {
            json_erro($e->getMessage());
        }
        json_ok($resultado);
    }

    /** Cria pedido de Pacote Agrícola — valida obrigatórios antes (modal AJAX). */
    public function salvarPacote(): void
    {
        Permissoes::exigir(['Administrador', 'Gestor Comercial', 'Vendedor', 'Consultor Técnico']);
        $clienteId = (int) ($_POST['cliente_id'] ?? 0);
        $this->exigirClienteDaCarteira($clienteId);

        $pacoteId = (int) ($_POST['pacote_id'] ?? 0);
        $areaHa = (float) str_replace(',', '.', $_POST['area_ha'] ?? 0);
        $itens = json_decode($_POST['itens'] ?? '[]', true) ?: [];
        if ($areaHa <= 0) {
            json_erro('Informe a área atendida pelo pacote (ha).');
        }

        try {
            $painel = PacoteService::avaliar($pacoteId, $clienteId, $areaHa, $itens);
        } catch (\InvalidArgumentException $e) {
            json_erro($e->getMessage());
        }

        // Regra do requisito: obrigatórios ausentes impedem a conclusão do pacote
        if (!$painel['pode_concluir']) {
            $faltantes = array_merge($painel['categorias_faltantes'], $painel['obrigatorios_faltantes']);
            $problemas = array_filter($painel['validacoes'], fn ($v) => $v['mensagem']);
            json_resposta([
                'ok' => false,
                'erro' => 'O pacote não pode ser concluído: há exigências não atendidas.',
                'faltantes' => array_values($faltantes),
                'validacoes' => array_values($problemas),
            ], 422);
        }

        // Aplica o desconto por categoria proporcionalmente nos itens
        $catalogoDescontos = [];
        foreach ($painel['categorias'] as $cat) {
            if ($cat['atendida']) {
                $catalogoDescontos[$cat['familia']] = $cat['desconto_pct'];
            }
        }
        $familias = [];
        foreach (Database::todos('SELECT p.id, f.nome AS familia FROM produtos p JOIN familias_produto f ON f.id = p.familia_id') as $l) {
            $familias[(int) $l['id']] = $l['familia'];
        }
        foreach ($itens as &$item) {
            $familia = $familias[(int) $item['produto_id']] ?? null;
            $item['desconto_pct'] = $catalogoDescontos[$familia] ?? 0;
        }
        unset($item);

        $resultado = PedidoService::criar($clienteId, $itens, [
            'tipo' => 'Pacote Agrícola',
            'pacote_id' => $pacoteId,
            'area_ha' => $areaHa,
            'bonificacao_sacas' => $painel['bonificacao_sacas'],
            'condicao_pagamento' => trim($_POST['condicao_pagamento'] ?? '') ?: null,
            'observacao' => trim($_POST['observacao'] ?? '') ?: null,
        ]);
        json_ok($resultado + ['bonificacao_sacas' => $painel['bonificacao_sacas']]);
    }

    /** Painel do pacote em tempo real (AJAX, chamado a cada alteração de item). */
    public function avaliarPacote(): void
    {
        Permissoes::exigirInterno();
        $painel = PacoteService::avaliar(
            (int) ($_POST['pacote_id'] ?? 0),
            (int) ($_POST['cliente_id'] ?? 0),
            (float) str_replace(',', '.', $_POST['area_ha'] ?? 0),
            json_decode($_POST['itens'] ?? '[]', true) ?: []
        );
        json_ok(['painel' => $painel]);
    }

    /** Estrutura do pacote para montar o modal (AJAX). */
    public function estruturaPacote(): void
    {
        Permissoes::exigirInterno();
        $estrutura = PacoteService::estrutura((int) ($_GET['id'] ?? 0));
        if (!$estrutura) {
            json_erro('Pacote não encontrado.', 404);
        }
        json_ok(['pacote' => $estrutura]);
    }

    /** Aprovação de pendência de crédito (Gestor Comercial). */
    public function aprovar(): void
    {
        Permissoes::exigir(['Administrador', 'Gestor Comercial']);
        $id = (int) ($_POST['id'] ?? 0);
        Database::executar(
            "UPDATE pedidos SET status = 'Aprovado', aprovado_por = ? WHERE id = ? AND status = 'Pendente de aprovação'",
            [Auth::id(), $id]
        );
        auditar('aprovar', 'pedido', $id);
        json_ok();
    }

    /** Fatura pedido aprovado: baixa estoque e lança compra no histórico. */
    public function faturar(): void
    {
        Permissoes::exigir(['Administrador', 'Gestor Comercial', 'Analista']);
        try {
            PedidoService::faturar((int) ($_POST['id'] ?? 0));
        } catch (\RuntimeException $e) {
            json_erro($e->getMessage());
        }
        auditar('faturar', 'pedido', (int) ($_POST['id'] ?? 0));
        json_ok();
    }

    /** Cancela pedido não faturado. */
    public function cancelar(): void
    {
        Permissoes::exigirInterno();
        $id = (int) ($_POST['id'] ?? 0);
        [$filtro, $params] = Permissoes::filtroCarteira();
        Database::executar(
            "UPDATE pedidos pe JOIN clientes c ON c.id = pe.cliente_id
                SET pe.status = 'Cancelado'
              WHERE pe.id = ? AND pe.status IN ('Rascunho','Pendente de aprovação','Aprovado') AND {$filtro}",
            array_merge([$id], $params)
        );
        auditar('cancelar', 'pedido', $id);
        json_ok();
    }

    /** Itens de um pedido (painel de detalhe AJAX). */
    public function detalhe(): void
    {
        Permissoes::exigirInterno();
        $id = (int) ($_GET['id'] ?? 0);
        [$filtro, $params] = Permissoes::filtroCarteira();
        $pedido = Database::um(
            "SELECT pe.*, c.nome AS cliente, u.nome AS vendedor, pa.nome AS pacote
               FROM pedidos pe
               JOIN clientes c ON c.id = pe.cliente_id
               JOIN usuarios u ON u.id = pe.usuario_id
               LEFT JOIN pacotes_agricolas pa ON pa.id = pe.pacote_id
              WHERE pe.id = ? AND {$filtro}",
            array_merge([$id], $params)
        );
        if (!$pedido) {
            json_erro('Pedido não encontrado.', 404);
        }
        $itens = Database::todos(
            'SELECT i.*, p.nome AS produto, p.unidade FROM pedidos_itens i JOIN produtos p ON p.id = i.produto_id WHERE i.pedido_id = ?',
            [$id]
        );
        render_parcial('partials/pedido_detalhe', compact('pedido', 'itens'));
    }

    private function exigirClienteDaCarteira(int $clienteId): void
    {
        [$filtro, $params] = Permissoes::filtroCarteira();
        $ok = Database::um(
            "SELECT c.id FROM clientes c WHERE c.id = ? AND {$filtro}",
            array_merge([$clienteId], $params)
        );
        if (!$ok) {
            json_erro('Cliente não encontrado na sua carteira.', 404);
        }
    }
}
