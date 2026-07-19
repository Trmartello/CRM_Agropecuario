<?php

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;

/**
 * Pedidos: criação com trava de crédito (alçada), totais e faturamento.
 * Fase 5: integração direta com o ERP mantendo esta interface.
 */
class PedidoService
{
    /**
     * Cria um pedido com itens.
     * $itens: [['produto_id'=>, 'quantidade'=>, 'valor_unitario'=>, 'desconto_pct'=>], ...]
     * Aplica a regra de crédito: inadimplente/limite estourado → Pendente de aprovação.
     */
    public static function criar(int $clienteId, array $itens, array $dados = []): array
    {
        if (!$itens) {
            throw new \InvalidArgumentException('Inclua ao menos um item no pedido.');
        }

        // Preço sempre da tabela oficial (nunca do navegador); promoção vigente
        // aplicada automaticamente quando o item não traz desconto definido.
        $ids = array_map(fn ($i) => (int) $i['produto_id'], $itens);
        $marcadores = implode(',', array_fill(0, count($ids), '?'));
        $precos = [];
        $promos = [];
        foreach (Database::todos(
            "SELECT p.id, p.preco_referencia, pr.desconto_pct AS promo
               FROM produtos p
               LEFT JOIN promocoes pr ON pr.produto_id = p.id AND pr.valido_ate >= CURDATE()
              WHERE p.id IN ({$marcadores})",
            $ids
        ) as $linha) {
            $precos[(int) $linha['id']] = (float) $linha['preco_referencia'];
            $promos[(int) $linha['id']] = (float) ($linha['promo'] ?? 0);
        }

        $bruto = 0.0;
        $desconto = 0.0;
        foreach ($itens as &$item) {
            $pid = (int) $item['produto_id'];
            if (!isset($precos[$pid])) {
                throw new \InvalidArgumentException('Produto inválido no pedido.');
            }
            $item['valor_unitario'] = $precos[$pid];
            if (!isset($item['desconto_pct']) || $item['desconto_pct'] === '' || $item['desconto_pct'] === null) {
                $item['desconto_pct'] = $promos[$pid];
            }
            $subtotal = (float) $item['quantidade'] * $item['valor_unitario'];
            $bruto += $subtotal;
            $desconto += $subtotal * ((float) $item['desconto_pct'] / 100);
        }
        unset($item);
        $total = $bruto - $desconto;

        $credito = CreditoService::situacao($clienteId);
        // Simulação do pedido consumindo limite: total + já utilizado
        $estouraLimite = $credito['limite'] > 0 && ($credito['utilizado'] + $total) > $credito['limite'];
        $pendente = $credito['exige_aprovacao'] || $estouraLimite;
        $motivo = null;
        if ($pendente) {
            $motivo = $credito['motivo_aprovacao'] ?? 'Pedido excede o limite de crédito disponível';
            if ($estouraLimite && !$credito['exige_aprovacao']) {
                $motivo = 'Pedido excede o limite de crédito disponível';
            }
        }

        $safra = ComercialService::safraAtual();

        Database::executar(
            'INSERT INTO pedidos (cliente_id, usuario_id, safra_id, tipo, pacote_id, area_ha, status, motivo_pendencia,
                    condicao_pagamento, observacao, valor_bruto, desconto_total, valor_total, bonificacao_sacas)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $clienteId,
                Auth::id(),
                $safra ? (int) $safra['id'] : null,
                $dados['tipo'] ?? 'Normal',
                $dados['pacote_id'] ?? null,
                $dados['area_ha'] ?? null,
                $pendente ? 'Pendente de aprovação' : 'Aprovado',
                $motivo,
                $dados['condicao_pagamento'] ?? null,
                $dados['observacao'] ?? null,
                $bruto,
                $desconto,
                $total,
                $dados['bonificacao_sacas'] ?? 0,
            ]
        );
        $pedidoId = Database::ultimoId();

        foreach ($itens as $item) {
            Database::executar(
                'INSERT INTO pedidos_itens (pedido_id, produto_id, quantidade, valor_unitario, desconto_pct) VALUES (?,?,?,?,?)',
                [
                    $pedidoId,
                    (int) $item['produto_id'],
                    (float) $item['quantidade'],
                    (float) $item['valor_unitario'],
                    (float) ($item['desconto_pct'] ?? 0),
                ]
            );
        }

        return [
            'id' => $pedidoId,
            'status' => $pendente ? 'Pendente de aprovação' : 'Aprovado',
            'motivo_pendencia' => $motivo,
            'valor_total' => $total,
        ];
    }

    /** Fatura um pedido aprovado: baixa estoque e lança a compra (histórico/CAP). */
    public static function faturar(int $pedidoId): void
    {
        $pedido = Database::um('SELECT * FROM pedidos WHERE id = ?', [$pedidoId]);
        if (!$pedido || $pedido['status'] !== 'Aprovado') {
            throw new \RuntimeException('Somente pedidos aprovados podem ser faturados.');
        }
        $itens = Database::todos('SELECT * FROM pedidos_itens WHERE pedido_id = ?', [$pedidoId]);
        foreach ($itens as $item) {
            Database::executar(
                'UPDATE produtos SET estoque = estoque - ? WHERE id = ?',
                [(float) $item['quantidade'], (int) $item['produto_id']]
            );
            // Compra entra no histórico comercial (alimenta gap, potencial e churn)
            $liquido = (float) $item['quantidade'] * (float) $item['valor_unitario'] * (1 - (float) $item['desconto_pct'] / 100);
            Database::executar(
                'INSERT INTO compras (cliente_id, produto_id, safra_id, quantidade, valor_total, data_compra) VALUES (?,?,?,?,?,CURDATE())',
                [
                    (int) $pedido['cliente_id'],
                    (int) $item['produto_id'],
                    (int) $pedido['safra_id'],
                    (float) $item['quantidade'],
                    $liquido,
                ]
            );
        }
        Database::executar("UPDATE pedidos SET status = 'Faturado' WHERE id = ?", [$pedidoId]);

        // Notifica o vendedor responsável e o produtor (se tiver usuário no portal)
        $cliente = Database::valor('SELECT nome FROM clientes WHERE id = ?', [(int) $pedido['cliente_id']]);
        NotificacaoService::criar((int) $pedido['usuario_id'], 'pedido', 'Pedido faturado',
            'Pedido #' . $pedidoId . ' de ' . $cliente . ' faturado (' . moeda($pedido['valor_total']) . ').', 'index.php?r=pedidos');
        $produtorUid = (int) Database::valor('SELECT id FROM usuarios WHERE cliente_id = ? AND perfil = "Produtor"', [(int) $pedido['cliente_id']]);
        if ($produtorUid) {
            NotificacaoService::criar($produtorUid, 'pedido', 'Seu pedido foi faturado',
                'Pedido #' . $pedidoId . ' — ' . moeda($pedido['valor_total']), 'index.php?r=portal');
        }
    }

    /** Catálogo para o modal de pedido: produtos com estoque, preço e promoção vigente. */
    public static function catalogo(): array
    {
        return Database::todos(
            "SELECT p.id, p.nome, p.unidade, p.preco_referencia, p.estoque, f.nome AS familia,
                    pr.desconto_pct AS promocao_pct, pr.descricao AS promocao
               FROM produtos p
               JOIN familias_produto f ON f.id = p.familia_id
               LEFT JOIN promocoes pr ON pr.produto_id = p.id AND pr.valido_ate >= CURDATE()
              WHERE p.ativo = 1
              ORDER BY f.nome, p.nome"
        );
    }

    /** Pedidos do cliente com status (consulta comercial). */
    public static function doCliente(int $clienteId): array
    {
        return Database::todos(
            "SELECT pe.*, u.nome AS vendedor, pa.nome AS pacote,
                    (SELECT COUNT(*) FROM pedidos_itens i WHERE i.pedido_id = pe.id) AS qtd_itens
               FROM pedidos pe
               JOIN usuarios u ON u.id = pe.usuario_id
               LEFT JOIN pacotes_agricolas pa ON pa.id = pe.pacote_id
              WHERE pe.cliente_id = ?
              ORDER BY pe.criado_em DESC",
            [$clienteId]
        );
    }

    /** Entregas futuras do cliente com saldo. */
    public static function entregasFuturas(int $clienteId): array
    {
        return Database::todos(
            'SELECT ef.*, p.nome AS produto, p.unidade,
                    (ef.quantidade_contratada - ef.quantidade_retirada) AS quantidade_pendente
               FROM entregas_futuras ef
               JOIN produtos p ON p.id = ef.produto_id
              WHERE ef.cliente_id = ?
              ORDER BY ef.previsao_entrega',
            [$clienteId]
        );
    }
}
