<?php

namespace App\Services;

use App\Core\Database;

/**
 * Análise de desempenho da equipe (Fase 6D): consolida, por vendedor/técnico
 * e período, as visitas, a efetividade de fechamento de pedido, as vendas,
 * o funil e o custo de campo. Só leitura — nenhuma tabela nova.
 */
class DesempenhoService
{
    /**
     * Uma visita "converteu" quando o MESMO usuário criou pedido para o MESMO
     * cliente em até N dias após a visita. Calibrar no piloto.
     */
    public const DIAS_CONVERSAO = 15;

    /** Indicadores por usuário no período (datas YYYY-MM-DD, inclusive). */
    public static function equipe(string $inicio, string $fim): array
    {
        $fimDt = $fim . ' 23:59:59';

        $usuarios = Database::todos(
            "SELECT id, nome, perfil FROM usuarios
              WHERE perfil IN ('Consultor Técnico','Vendedor') AND ativo = 1 ORDER BY nome"
        );

        // Visitas do período + conversão em pedido (mesmo usuário/cliente em N dias)
        $visitas = self::porUsuario(Database::todos(
            "SELECT v.usuario_id,
                    COUNT(*) AS visitas,
                    SUM(v.finalizada = 1) AS finalizadas,
                    COUNT(DISTINCT v.cliente_id) AS produtores,
                    COUNT(DISTINCT CASE WHEN c.ativo = 1 AND c.responsavel_id = v.usuario_id THEN v.cliente_id END) AS produtores_carteira,
                    AVG(CASE WHEN v.hora_inicio IS NOT NULL AND v.hora_fim IS NOT NULL AND v.hora_fim > v.hora_inicio
                             THEN TIME_TO_SEC(TIMEDIFF(v.hora_fim, v.hora_inicio)) / 60 END) AS duracao_media,
                    SUM(EXISTS (
                        SELECT 1 FROM pedidos p
                         WHERE p.cliente_id = v.cliente_id
                           AND p.usuario_id = v.usuario_id
                           AND p.status <> 'Cancelado'
                           AND DATE(p.criado_em) BETWEEN v.data_visita
                               AND DATE_ADD(v.data_visita, INTERVAL " . self::DIAS_CONVERSAO . " DAY)
                    )) AS convertidas
               FROM visitas v
               JOIN clientes c ON c.id = v.cliente_id
              WHERE v.data_visita BETWEEN ? AND ?
              GROUP BY v.usuario_id",
            [$inicio, $fim]
        ));

        $carteiras = self::porUsuario(Database::todos(
            "SELECT responsavel_id AS usuario_id, COUNT(*) AS carteira
               FROM clientes WHERE ativo = 1 AND responsavel_id IS NOT NULL
              GROUP BY responsavel_id"
        ));

        $pedidos = self::porUsuario(Database::todos(
            "SELECT usuario_id,
                    COUNT(*) AS pedidos,
                    SUM(status IN ('Aprovado','Faturado')) AS pedidos_fechados,
                    COALESCE(SUM(CASE WHEN status IN ('Aprovado','Faturado') THEN valor_total ELSE 0 END), 0) AS vendido
               FROM pedidos
              WHERE status <> 'Cancelado' AND criado_em BETWEEN ? AND ?
              GROUP BY usuario_id",
            [$inicio, $fimDt]
        ));

        // Funil: oportunidades não têm data de fechamento — recorte por criação
        $funil = self::porUsuario(Database::todos(
            "SELECT usuario_id,
                    SUM(estagio = 'Ganha') AS ganhas,
                    SUM(estagio = 'Perdida') AS perdidas
               FROM oportunidades
              WHERE criado_em BETWEEN ? AND ?
              GROUP BY usuario_id",
            [$inicio, $fimDt]
        ));

        $km = self::porUsuario(Database::todos(
            "SELECT usuario_id,
                    COALESCE(SUM(km_final - km_inicial), 0) AS km_rodado,
                    COALESCE(SUM(valor), 0) AS km_valor
               FROM quilometragem
              WHERE data BETWEEN ? AND ?
              GROUP BY usuario_id",
            [$inicio, $fim]
        ));

        $refeicoes = self::porUsuario(Database::todos(
            "SELECT usuario_id, COALESCE(SUM(valor_reembolso), 0) AS refeicao_valor
               FROM refeicoes
              WHERE data BETWEEN ? AND ?
              GROUP BY usuario_id",
            [$inicio, $fim]
        ));

        $linhas = [];
        foreach ($usuarios as $u) {
            $id = (int) $u['id'];
            $v = $visitas[$id] ?? [];
            $totalVisitas = (int) ($v['visitas'] ?? 0);
            $convertidas = (int) ($v['convertidas'] ?? 0);
            $carteira = (int) ($carteiras[$id]['carteira'] ?? 0);
            $produtores = (int) ($v['produtores'] ?? 0);
            $vendido = (float) ($pedidos[$id]['vendido'] ?? 0);
            $fechados = (int) ($pedidos[$id]['pedidos_fechados'] ?? 0);
            $custoCampo = (float) ($km[$id]['km_valor'] ?? 0) + (float) ($refeicoes[$id]['refeicao_valor'] ?? 0);

            $linhas[] = [
                'id' => $id,
                'nome' => $u['nome'],
                'perfil' => $u['perfil'],
                'carteira' => $carteira,
                'visitas' => $totalVisitas,
                'finalizadas' => (int) ($v['finalizadas'] ?? 0),
                'duracao_media' => isset($v['duracao_media']) && $v['duracao_media'] !== null ? (int) round((float) $v['duracao_media']) : null,
                'produtores' => $produtores,
                // Cobertura mede a PRÓPRIA carteira: visitas a clientes de outros
                // responsáveis contam como visita, mas não como cobertura.
                'cobertura' => $carteira > 0
                    ? min(100, (int) round((int) ($v['produtores_carteira'] ?? 0) / $carteira * 100))
                    : null,
                'convertidas' => $convertidas,
                'efetividade' => $totalVisitas > 0 ? (int) round($convertidas / $totalVisitas * 100) : null,
                'pedidos' => (int) ($pedidos[$id]['pedidos'] ?? 0),
                'vendido' => $vendido,
                'ticket' => $fechados > 0 ? $vendido / $fechados : null,
                'ganhas' => (int) ($funil[$id]['ganhas'] ?? 0),
                'perdidas' => (int) ($funil[$id]['perdidas'] ?? 0),
                'km_rodado' => (float) ($km[$id]['km_rodado'] ?? 0),
                'custo_campo' => $custoCampo,
                'custo_visita' => $totalVisitas > 0 ? $custoCampo / $totalVisitas : null,
            ];
        }

        usort($linhas, fn ($a, $b) => $b['vendido'] <=> $a['vendido']);
        return $linhas;
    }

    /** Evolução mensal da equipe inteira (últimos N meses): visitas × vendido. */
    public static function evolucaoMensal(int $meses = 6): array
    {
        // Âncora no dia 1º: "-N months" a partir do dia 29-31 estoura o mês
        // (ex.: 31/07 - 1 mês = 01/07) e duplicava/pulava meses na série
        $base = strtotime(date('Y-m-01'));
        $inicio = date('Y-m-01', strtotime('-' . ($meses - 1) . ' months', $base));

        $visitas = [];
        foreach (Database::todos(
            "SELECT DATE_FORMAT(data_visita, '%Y-%m') AS mes, COUNT(*) AS total
               FROM visitas WHERE data_visita >= ? GROUP BY mes",
            [$inicio]
        ) as $l) {
            $visitas[$l['mes']] = (int) $l['total'];
        }

        $vendido = [];
        foreach (Database::todos(
            "SELECT DATE_FORMAT(criado_em, '%Y-%m') AS mes, COALESCE(SUM(valor_total), 0) AS total
               FROM pedidos WHERE status IN ('Aprovado','Faturado') AND criado_em >= ?
              GROUP BY mes",
            [$inicio]
        ) as $l) {
            $vendido[$l['mes']] = (float) $l['total'];
        }

        $serie = [];
        for ($i = $meses - 1; $i >= 0; $i--) {
            $mes = date('Y-m', strtotime("-{$i} months", $base));
            $serie[] = [
                'mes' => $mes,
                'rotulo' => date('m/Y', strtotime($mes . '-01')),
                'visitas' => $visitas[$mes] ?? 0,
                'vendido' => $vendido[$mes] ?? 0,
            ];
        }
        return $serie;
    }

    private static function porUsuario(array $linhas): array
    {
        $mapa = [];
        foreach ($linhas as $l) {
            $mapa[(int) $l['usuario_id']] = $l;
        }
        return $mapa;
    }
}
