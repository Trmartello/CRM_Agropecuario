<?php

namespace App\Services;

use App\Core\Database;

/**
 * Metas do programa CAP — Copérdia Alta Performance.
 *
 * Fase 5: os dados oficiais passam a vir do aplicativo CAPE;
 * apenas a fonte muda, a interface desta classe permanece.
 */
class CapService
{
    /** Metas e atingimento de um usuário (vendedor/técnico vê só as próprias). */
    public static function metasDoUsuario(int $usuarioId): array
    {
        $metas = Database::todos(
            'SELECT m.*, COALESCE((SELECT SUM(r.valor) FROM realizado_cap r WHERE r.meta_id = m.id), 0) AS realizado
               FROM metas_cap m
              WHERE m.usuario_id = ?
                AND CURDATE() BETWEEN m.periodo_inicio AND m.periodo_fim
              ORDER BY m.indicador',
            [$usuarioId]
        );
        foreach ($metas as &$m) {
            $meta = (float) $m['meta'];
            $realizado = (float) $m['realizado'];
            $m['percentual'] = $meta > 0 ? round($realizado / $meta * 100, 1) : 0;
            $m['saldo'] = max(0, $meta - $realizado);
        }
        return $metas;
    }

    /** Metas de toda a equipe (gestores). */
    public static function metasDaEquipe(): array
    {
        $usuarios = Database::todos(
            "SELECT DISTINCT u.id, u.nome, u.perfil
               FROM usuarios u
               JOIN metas_cap m ON m.usuario_id = u.id
              WHERE CURDATE() BETWEEN m.periodo_inicio AND m.periodo_fim
              ORDER BY u.nome"
        );
        foreach ($usuarios as &$u) {
            $u['metas'] = self::metasDoUsuario((int) $u['id']);
        }
        return $usuarios;
    }

    /** Atingimento geral (média dos percentuais) — cartão do dashboard. */
    public static function atingimentoGeral(int $usuarioId): ?float
    {
        $metas = self::metasDoUsuario($usuarioId);
        if (!$metas) {
            return null;
        }
        $soma = array_sum(array_map(fn ($m) => min(100, $m['percentual']), $metas));
        return round($soma / count($metas), 1);
    }

    /**
     * Produtores com maior potencial de contribuição para fechar as metas:
     * cruza potencial não atendido (potencial - realizado por família) com a
     * priorização de visitas — quem visitar para atingir o indicador.
     */
    public static function produtoresParaMeta(string $filtroCarteira, array $params, int $limite = 5): array
    {
        $safra = ComercialService::safraAtual();
        if (!$safra) {
            return [];
        }
        return Database::todos(
            "SELECT c.id, c.nome, c.municipio,
                    SUM(pc.valor_potencial) AS potencial_total,
                    COALESCE(re.realizado, 0) AS realizado,
                    SUM(pc.valor_potencial) - COALESCE(re.realizado, 0) AS espaco
               FROM clientes c
               JOIN potencial_compra pc ON pc.cliente_id = c.id AND pc.safra_id = ?
               LEFT JOIN (
                    SELECT cliente_id, SUM(valor_total) AS realizado
                      FROM compras WHERE safra_id = ? GROUP BY cliente_id
               ) re ON re.cliente_id = c.id
              WHERE c.ativo = 1 AND {$filtroCarteira}
              GROUP BY c.id, c.nome, c.municipio, re.realizado
             HAVING espaco > 0
              ORDER BY espaco DESC
              LIMIT " . (int) $limite,
            array_merge([(int) $safra['id'], (int) $safra['id']], $params)
        );
    }
}
