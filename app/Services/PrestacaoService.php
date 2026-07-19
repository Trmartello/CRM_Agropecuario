<?php

namespace App\Services;

use App\Core\Database;

/**
 * Prestação de contas mensal: consolida quilometragem + refeições de um mês
 * em um documento com fluxo Aberta → Enviada → Aprovada/Rejeitada.
 */
class PrestacaoService
{
    public const STATUS = ['Aberta', 'Enviada', 'Aprovada', 'Rejeitada'];

    /** Prévia do mês (lançamentos ainda não consolidados). */
    public static function previa(int $usuarioId, int $ano, int $mes): array
    {
        $competencia = sprintf('%04d-%02d', $ano, $mes);
        $km = Database::um(
            "SELECT COALESCE(SUM(km_final - km_inicial),0) AS km, COALESCE(SUM(valor),0) AS valor, COUNT(*) AS qtd
               FROM quilometragem
              WHERE usuario_id = ? AND prestacao_id IS NULL AND DATE_FORMAT(data,'%Y-%m') = ?",
            [$usuarioId, $competencia]
        );
        $ref = Database::um(
            "SELECT COALESCE(SUM(valor),0) AS valor, COUNT(*) AS qtd
               FROM refeicoes
              WHERE usuario_id = ? AND prestacao_id IS NULL AND DATE_FORMAT(data,'%Y-%m') = ?",
            [$usuarioId, $competencia]
        );
        $totalKmValor = (float) $km['valor'];
        $totalRef = (float) $ref['valor'];
        return [
            'ano' => $ano,
            'mes' => $mes,
            'total_km' => (float) $km['km'],
            'qtd_km' => (int) $km['qtd'],
            'total_km_valor' => $totalKmValor,
            'total_refeicoes' => $totalRef,
            'qtd_refeicoes' => (int) $ref['qtd'],
            'total_geral' => $totalKmValor + $totalRef,
        ];
    }

    /**
     * Gera (ou atualiza) a prestação do mês, vinculando os lançamentos abertos.
     * Uma prestação já enviada/aprovada não pode ser regerada.
     */
    public static function gerar(int $usuarioId, int $ano, int $mes): int
    {
        if ($mes < 1 || $mes > 12) {
            throw new \InvalidArgumentException('Mês inválido.');
        }
        $existente = Database::um(
            'SELECT id, status FROM prestacao_contas WHERE usuario_id = ? AND ano = ? AND mes = ?',
            [$usuarioId, $ano, $mes]
        );
        if ($existente && $existente['status'] !== 'Aberta' && $existente['status'] !== 'Rejeitada') {
            throw new \RuntimeException('A prestação deste mês já foi enviada e não pode ser regerada.');
        }

        $competencia = sprintf('%04d-%02d', $ano, $mes);
        $pdo = Database::conexao();
        $pdo->beginTransaction();
        try {
            if ($existente) {
                $prestacaoId = (int) $existente['id'];
                // Desvincula o que estava nesta prestação para reconsolidar do zero
                Database::executar('UPDATE quilometragem SET prestacao_id = NULL WHERE prestacao_id = ?', [$prestacaoId]);
                Database::executar('UPDATE refeicoes SET prestacao_id = NULL WHERE prestacao_id = ?', [$prestacaoId]);
            } else {
                Database::executar(
                    'INSERT INTO prestacao_contas (usuario_id, ano, mes, status) VALUES (?,?,?,\'Aberta\')',
                    [$usuarioId, $ano, $mes]
                );
                $prestacaoId = Database::ultimoId();
            }

            // Vincula os lançamentos abertos do mês
            Database::executar(
                "UPDATE quilometragem SET prestacao_id = ?
                  WHERE usuario_id = ? AND prestacao_id IS NULL AND DATE_FORMAT(data,'%Y-%m') = ?",
                [$prestacaoId, $usuarioId, $competencia]
            );
            Database::executar(
                "UPDATE refeicoes SET prestacao_id = ?
                  WHERE usuario_id = ? AND prestacao_id IS NULL AND DATE_FORMAT(data,'%Y-%m') = ?",
                [$prestacaoId, $usuarioId, $competencia]
            );

            self::recalcular($prestacaoId);
            Database::executar('UPDATE prestacao_contas SET status = \'Aberta\', parecer = NULL WHERE id = ?', [$prestacaoId]);
            $pdo->commit();
            return $prestacaoId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Recalcula os totais a partir dos lançamentos vinculados. */
    public static function recalcular(int $prestacaoId): void
    {
        $km = Database::um(
            'SELECT COALESCE(SUM(km_final - km_inicial),0) AS km, COALESCE(SUM(valor),0) AS valor
               FROM quilometragem WHERE prestacao_id = ?',
            [$prestacaoId]
        );
        $ref = Database::valor('SELECT COALESCE(SUM(valor),0) FROM refeicoes WHERE prestacao_id = ?', [$prestacaoId]);
        $totalKmValor = (float) $km['valor'];
        $totalRef = (float) $ref;
        Database::executar(
            'UPDATE prestacao_contas SET total_km = ?, total_km_valor = ?, total_refeicoes = ?, total_geral = ? WHERE id = ?',
            [(float) $km['km'], $totalKmValor, $totalRef, $totalKmValor + $totalRef, $prestacaoId]
        );
    }

    public static function enviar(int $prestacaoId, int $usuarioId): void
    {
        $p = self::obterProprio($prestacaoId, $usuarioId);
        if (!in_array($p['status'], ['Aberta', 'Rejeitada'], true)) {
            throw new \RuntimeException('Só é possível enviar uma prestação Aberta ou Rejeitada.');
        }
        if ((float) $p['total_geral'] <= 0) {
            throw new \RuntimeException('Não há lançamentos consolidados para enviar.');
        }
        Database::executar(
            'UPDATE prestacao_contas SET status = \'Enviada\', enviado_em = NOW() WHERE id = ?',
            [$prestacaoId]
        );
    }

    /** Aprovação/rejeição pelo gestor. */
    public static function avaliar(int $prestacaoId, int $gestorId, bool $aprovar, string $parecer = ''): void
    {
        $p = Database::um('SELECT status FROM prestacao_contas WHERE id = ?', [$prestacaoId]);
        if (!$p) {
            throw new \RuntimeException('Prestação não encontrada.');
        }
        if ($p['status'] !== 'Enviada') {
            throw new \RuntimeException('Só é possível avaliar uma prestação Enviada.');
        }
        Database::executar(
            'UPDATE prestacao_contas SET status = ?, avaliado_por = ?, avaliado_em = NOW(), parecer = ? WHERE id = ?',
            [$aprovar ? 'Aprovada' : 'Rejeitada', $gestorId, trim($parecer) ?: null, $prestacaoId]
        );
    }

    public static function listar(int $escopoUsuario): array
    {
        $where = '1=1';
        $params = [];
        if ($escopoUsuario > 0) {
            $where = 'p.usuario_id = ?';
            $params[] = $escopoUsuario;
        }
        return Database::todos(
            "SELECT p.*, u.nome AS usuario, a.nome AS avaliador
               FROM prestacao_contas p
               JOIN usuarios u ON u.id = p.usuario_id
               LEFT JOIN usuarios a ON a.id = p.avaliado_por
              WHERE {$where}
              ORDER BY p.ano DESC, p.mes DESC, u.nome
              LIMIT 200",
            $params
        );
    }

    public static function detalhe(int $prestacaoId): array
    {
        $prestacao = Database::um(
            "SELECT p.*, u.nome AS usuario, a.nome AS avaliador
               FROM prestacao_contas p
               JOIN usuarios u ON u.id = p.usuario_id
               LEFT JOIN usuarios a ON a.id = p.avaliado_por
              WHERE p.id = ?",
            [$prestacaoId]
        );
        if (!$prestacao) {
            throw new \RuntimeException('Prestação não encontrada.');
        }
        $km = Database::todos(
            "SELECT q.*, (q.km_final - q.km_inicial) AS km_rodados, c.nome AS cliente,
                    CASE q.tipo_destino
                      WHEN 'Produtor' THEN COALESCE(c.nome, q.prospecto)
                      WHEN 'Filial' THEN f.nome
                      ELSE q.destino
                    END AS destino_desc
               FROM quilometragem q
               LEFT JOIN clientes c ON c.id = q.cliente_id
               LEFT JOIN filiais f ON f.id = q.filial_id
              WHERE q.prestacao_id = ? ORDER BY q.data",
            [$prestacaoId]
        );
        $refeicoes = Database::todos(
            'SELECT r.*, c.nome AS cliente FROM refeicoes r LEFT JOIN clientes c ON c.id = r.cliente_id
              WHERE r.prestacao_id = ? ORDER BY r.data',
            [$prestacaoId]
        );
        return compact('prestacao', 'km', 'refeicoes');
    }

    private static function obterProprio(int $prestacaoId, int $usuarioId): array
    {
        $p = Database::um('SELECT * FROM prestacao_contas WHERE id = ? AND usuario_id = ?', [$prestacaoId, $usuarioId]);
        if (!$p) {
            throw new \RuntimeException('Prestação não encontrada na sua conta.');
        }
        return $p;
    }
}
