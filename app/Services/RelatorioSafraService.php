<?php

namespace App\Services;

use App\Core\Database;

/**
 * Relatório de fechamento de safra (Fase 6B): consolida, por produtor e
 * safra, tudo o que o consultor precisa para a reunião de fim de safra —
 * plantios e produtividade, assistência prestada (visitas + checklists),
 * comercial (compras × potencial, pedidos, entregas futuras), reclamações
 * e o croqui das propriedades. Só leitura.
 */
class RelatorioSafraService
{
    public static function dados(int $clienteId, int $safraId): array
    {
        $cliente = Database::um(
            'SELECT c.*, u.nome AS responsavel, f.nome AS filial
               FROM clientes c
               LEFT JOIN usuarios u ON u.id = c.responsavel_id
               LEFT JOIN filiais f ON f.id = c.filial_id
              WHERE c.id = ?',
            [$clienteId]
        );
        $safra = Database::um('SELECT * FROM safras WHERE id = ?', [$safraId]);
        if (!$cliente || !$safra) {
            return [];
        }
        $inicio = $safra['data_inicio'];
        $fim = $safra['data_fim'];
        $anterior = Database::um(
            'SELECT * FROM safras WHERE data_inicio < ? ORDER BY data_inicio DESC LIMIT 1',
            [$inicio]
        );

        // ---- Plantios e produtividade (6E) ----
        $plantios = Database::todos(
            'SELECT p.*, cu.nome AS cultura, t.nome AS talhao, t.area_ha, t.area_gps, pr.nome AS propriedade
               FROM plantios p
               JOIN culturas cu ON cu.id = p.cultura_id
               JOIN talhoes t ON t.id = p.talhao_id
               JOIN propriedades pr ON pr.id = t.propriedade_id
              WHERE pr.cliente_id = ? AND p.safra_id = ?
              ORDER BY pr.nome, t.nome',
            [$clienteId, $safraId]
        );
        // Média de produtividade da base na mesma cultura/safra (referência de comparação)
        $mediasProdutividade = [];
        foreach (Database::todos(
            'SELECT cultura_id, AVG(produtividade) AS media, COUNT(*) AS amostras
               FROM plantios WHERE safra_id = ? AND encerrado = 1 AND produtividade IS NOT NULL
              GROUP BY cultura_id',
            [$safraId]
        ) as $m) {
            $mediasProdutividade[(int) $m['cultura_id']] = $m;
        }
        $planos = Database::todos(
            'SELECT ps.area_ha, cu.nome AS cultura FROM planos_safra ps
               JOIN culturas cu ON cu.id = ps.cultura_id
              WHERE ps.cliente_id = ? AND ps.safra_id = ? ORDER BY cu.nome',
            [$clienteId, $safraId]
        );

        // ---- Assistência técnica na janela da safra ----
        $visitasResumo = Database::um(
            'SELECT COUNT(*) AS total, COALESCE(SUM(finalizada = 1), 0) AS finalizadas,
                    COUNT(DISTINCT usuario_id) AS tecnicos
               FROM visitas WHERE cliente_id = ? AND data_visita BETWEEN ? AND ?',
            [$clienteId, $inicio, $fim]
        );
        $visitasPorMes = Database::todos(
            "SELECT DATE_FORMAT(data_visita, '%m/%Y') AS mes, COUNT(*) AS total
               FROM visitas WHERE cliente_id = ? AND data_visita BETWEEN ? AND ?
              GROUP BY DATE_FORMAT(data_visita, '%Y-%m') ORDER BY MIN(data_visita)",
            [$clienteId, $inicio, $fim]
        );
        $recomendacoes = Database::todos(
            'SELECT v.data_visita, v.recomendacao, u.nome AS tecnico, cu.nome AS cultura
               FROM visitas v JOIN usuarios u ON u.id = v.usuario_id
               LEFT JOIN culturas cu ON cu.id = v.cultura_id
              WHERE v.cliente_id = ? AND v.data_visita BETWEEN ? AND ?
                AND v.recomendacao IS NOT NULL AND v.recomendacao <> ""
              ORDER BY v.data_visita',
            [$clienteId, $inicio, $fim]
        );
        $checklistResumo = Database::todos(
            'SELECT vc.situacao, COUNT(*) AS total
               FROM visita_checklist vc JOIN visitas v ON v.id = vc.visita_id
              WHERE v.cliente_id = ? AND v.data_visita BETWEEN ? AND ?
              GROUP BY vc.situacao',
            [$clienteId, $inicio, $fim]
        );
        $checklistCriticos = Database::todos(
            'SELECT v.data_visita, m.titulo, vc.observacao
               FROM visita_checklist vc
               JOIN visitas v ON v.id = vc.visita_id
               JOIN manejos_fase m ON m.id = vc.manejo_id
              WHERE v.cliente_id = ? AND v.data_visita BETWEEN ? AND ? AND vc.situacao = "Crítico"
              ORDER BY v.data_visita',
            [$clienteId, $inicio, $fim]
        );

        // ---- Comercial da safra ----
        $comprasFamilia = Database::todos(
            'SELECT f.nome AS familia,
                    COALESCE(SUM(co.valor_total), 0) AS realizado,
                    COALESCE(MAX(pc.valor_potencial), 0) AS potencial
               FROM familias_produto f
               LEFT JOIN produtos p ON p.familia_id = f.id
               LEFT JOIN compras co ON co.produto_id = p.id AND co.cliente_id = ? AND co.safra_id = ?
               LEFT JOIN potencial_compra pc ON pc.familia_id = f.id AND pc.cliente_id = ? AND pc.safra_id = ?
              GROUP BY f.id, f.nome
             HAVING realizado > 0 OR potencial > 0
              ORDER BY potencial DESC, realizado DESC',
            [$clienteId, $safraId, $clienteId, $safraId]
        );
        $totalSafra = (float) Database::valor(
            'SELECT COALESCE(SUM(valor_total), 0) FROM compras WHERE cliente_id = ? AND safra_id = ?',
            [$clienteId, $safraId]
        );
        $totalAnterior = $anterior ? (float) Database::valor(
            'SELECT COALESCE(SUM(valor_total), 0) FROM compras WHERE cliente_id = ? AND safra_id = ?',
            [$clienteId, (int) $anterior['id']]
        ) : null;

        $pedidos = Database::todos(
            'SELECT status, COUNT(*) AS qtd, COALESCE(SUM(valor_total), 0) AS total
               FROM pedidos WHERE cliente_id = ? AND safra_id = ? AND status <> "Cancelado"
              GROUP BY status',
            [$clienteId, $safraId]
        );
        $entregasPendentes = Database::todos(
            'SELECT ef.*, p.nome AS produto, p.unidade,
                    (ef.quantidade_contratada - ef.quantidade_retirada) AS saldo
               FROM entregas_futuras ef JOIN produtos p ON p.id = ef.produto_id
              WHERE ef.cliente_id = ? AND ef.quantidade_retirada < ef.quantidade_contratada
              ORDER BY ef.previsao_entrega',
            [$clienteId]
        );

        // ---- Reclamações na janela da safra ----
        $reclamacoes = Database::todos(
            'SELECT r.criado_em, r.tipo, r.status, r.problema, p.nome AS produto
               FROM reclamacoes r LEFT JOIN produtos p ON p.id = r.produto_id
              WHERE r.cliente_id = ? AND DATE(r.criado_em) BETWEEN ? AND ?
              ORDER BY r.criado_em',
            [$clienteId, $inicio, $fim]
        );

        // ---- Croquis (6A) por propriedade ----
        $croquis = [];
        foreach (Database::todos(
            'SELECT id, nome FROM propriedades WHERE cliente_id = ? ORDER BY nome', [$clienteId]
        ) as $prop) {
            $talhoes = Database::todos(
                'SELECT nome, area_ha, area_gps, contorno FROM talhoes WHERE propriedade_id = ?',
                [(int) $prop['id']]
            );
            $svg = CroquiService::svg($talhoes, 420, 280);
            if ($svg !== '') {
                $croquis[] = ['propriedade' => $prop['nome'], 'svg' => $svg];
            }
        }

        return compact(
            'cliente', 'safra', 'anterior', 'plantios', 'mediasProdutividade', 'planos',
            'visitasResumo', 'visitasPorMes', 'recomendacoes', 'checklistResumo', 'checklistCriticos',
            'comprasFamilia', 'totalSafra', 'totalAnterior', 'pedidos', 'entregasPendentes',
            'reclamacoes', 'croquis'
        );
    }
}
