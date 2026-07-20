<?php

namespace App\Services;

use App\Core\Database;

/**
 * Linha do tempo da cultura (Fase 6E): a data de plantio do talhão ancora a
 * fenologia — o sistema estima a fase atual pela idade da lavoura (DAP, dias
 * após o plantio), indica os manejos da fase e monta o checklist do técnico.
 */
class FenologiaService
{
    /** Plantio ativo (não encerrado) de um talhão, se houver. */
    public static function plantioAtivo(int $talhaoId): ?array
    {
        $p = Database::um(
            'SELECT p.*, cu.nome AS cultura FROM plantios p
               JOIN culturas cu ON cu.id = p.cultura_id
              WHERE p.talhao_id = ? AND p.encerrado = 0
              ORDER BY p.data_plantio DESC LIMIT 1',
            [$talhaoId]
        );
        return $p ?: null;
    }

    /** Plantios ativos de todos os talhões de um cliente: talhao_id => plantio. */
    public static function plantiosAtivosPorCliente(int $clienteId): array
    {
        $mapa = [];
        foreach (Database::todos(
            'SELECT p.*, cu.nome AS cultura FROM plantios p
               JOIN culturas cu ON cu.id = p.cultura_id
               JOIN talhoes t ON t.id = p.talhao_id
               JOIN propriedades pr ON pr.id = t.propriedade_id
              WHERE pr.cliente_id = ? AND p.encerrado = 0
              ORDER BY p.data_plantio DESC',
            [$clienteId]
        ) as $p) {
            // ORDER BY DESC + primeiro vence: fica o plantio mais recente do talhão
            $mapa[(int) $p['talhao_id']] ??= $p;
        }
        return $mapa;
    }

    /**
     * Catálogo de referência (estágios + manejos embutidos) das culturas
     * informadas — vai para o modal de visita e para o snapshot offline.
     * Formato: cultura_id => [ {estagio..., manejos: [...]}, ... ]
     */
    public static function catalogo(?array $culturaIds = null): array
    {
        $where = '';
        $params = [];
        if ($culturaIds !== null) {
            $ids = array_values(array_unique(array_map('intval', $culturaIds)));
            if (!$ids) {
                return [];
            }
            $where = 'WHERE fe.cultura_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params = $ids;
        }
        $estagios = Database::todos(
            "SELECT fe.* FROM fenologia_estagios fe {$where} ORDER BY fe.cultura_id, fe.ordem",
            $params
        );
        if (!$estagios) {
            return [];
        }

        $porEstagio = [];
        $idsEstagios = array_map(fn ($e) => (int) $e['id'], $estagios);
        $marcadores = implode(',', array_fill(0, count($idsEstagios), '?'));
        foreach (Database::todos(
            "SELECT m.id, m.estagio_id, m.titulo, m.familia_id, f.nome AS familia, m.orientacao, m.eh_checklist
               FROM manejos_fase m LEFT JOIN familias_produto f ON f.id = m.familia_id
              WHERE m.estagio_id IN ({$marcadores})
              ORDER BY m.id",
            $idsEstagios
        ) as $m) {
            $porEstagio[(int) $m['estagio_id']][] = $m;
        }

        $catalogo = [];
        foreach ($estagios as $e) {
            $e['manejos'] = $porEstagio[(int) $e['id']] ?? [];
            $catalogo[(int) $e['cultura_id']][] = $e;
        }
        return $catalogo;
    }

    /** Estágio estimado para uma idade de lavoura (DAP), dentro de um catálogo de cultura. */
    public static function estagioPorDap(array $estagiosCultura, int $dap): ?array
    {
        $ultimo = null;
        foreach ($estagiosCultura as $e) {
            if ($dap >= (int) $e['dias_inicio'] && $dap <= (int) $e['dias_fim']) {
                return $e;
            }
            $ultimo = $e;
        }
        // Além do fim do ciclo: permanece no último estágio (colheita pendente)
        return ($ultimo && $dap > (int) $ultimo['dias_fim']) ? $ultimo : null;
    }

    /** Registra o plantio de um talhão (um ativo por vez). */
    public static function salvarPlantio(int $talhaoId, int $culturaId, string $dataPlantio, ?string $cultivar): int
    {
        if ($talhaoId <= 0 || $culturaId <= 0) {
            throw new \InvalidArgumentException('Informe o talhão e a cultura do plantio.');
        }
        $data = date_create($dataPlantio);
        if (!$data || $dataPlantio === '') {
            throw new \InvalidArgumentException('Informe a data de plantio.');
        }
        if ($data > date_create('today')) {
            throw new \InvalidArgumentException('A data de plantio não pode ser futura.');
        }
        if (self::plantioAtivo($talhaoId)) {
            throw new \InvalidArgumentException('Este talhão já tem um plantio em andamento — encerre-o (colheita) antes de registrar outro.');
        }
        $safra = ComercialService::safraAtual();
        Database::executar(
            'INSERT INTO plantios (talhao_id, cultura_id, safra_id, data_plantio, cultivar) VALUES (?,?,?,?,?)',
            [$talhaoId, $culturaId, $safra ? (int) $safra['id'] : null, $data->format('Y-m-d'), trim((string) $cultivar) ?: null]
        );
        // Mantém a cultura do talhão alinhada ao plantio real
        Database::executar('UPDATE talhoes SET cultura_id = ? WHERE id = ?', [$culturaId, $talhaoId]);
        return Database::ultimoId();
    }

    /** Encerra o plantio registrando a colheita (produtividade em sc/ha). */
    public static function encerrarPlantio(int $plantioId, ?float $produtividade, ?string $colhidoEm): void
    {
        $plantio = Database::um('SELECT * FROM plantios WHERE id = ? AND encerrado = 0', [$plantioId]);
        if (!$plantio) {
            throw new \InvalidArgumentException('Plantio não encontrado ou já encerrado.');
        }
        $data = $colhidoEm ? date_create($colhidoEm) : date_create('today');
        if (!$data || $data->format('Y-m-d') < $plantio['data_plantio']) {
            throw new \InvalidArgumentException('Data de colheita inválida (anterior ao plantio).');
        }
        Database::executar(
            'UPDATE plantios SET encerrado = 1, colhido_em = ?, produtividade = ? WHERE id = ?',
            [$data->format('Y-m-d'), $produtividade > 0 ? $produtividade : null, $plantioId]
        );
    }

    /**
     * Gatilho comercial da lavoura: plantio ativo em fase com manejo ligado a
     * uma família SEM compra na safra atual → lista para virar oportunidade.
     */
    public static function gatilhosLavoura(int $clienteId, int $safraId): array
    {
        $plantios = Database::todos(
            'SELECT p.id, p.talhao_id, p.cultura_id, p.data_plantio, cu.nome AS cultura, t.nome AS talhao, t.area_ha
               FROM plantios p
               JOIN culturas cu ON cu.id = p.cultura_id
               JOIN talhoes t ON t.id = p.talhao_id
               JOIN propriedades pr ON pr.id = t.propriedade_id
              WHERE pr.cliente_id = ? AND p.encerrado = 0',
            [$clienteId]
        );
        if (!$plantios) {
            return [];
        }
        $catalogo = self::catalogo(array_map(fn ($p) => (int) $p['cultura_id'], $plantios));

        $gatilhos = [];
        foreach ($plantios as $p) {
            $dap = (int) floor((time() - strtotime((string) $p['data_plantio'])) / 86400);
            $estagio = self::estagioPorDap($catalogo[(int) $p['cultura_id']] ?? [], $dap);
            if (!$estagio) {
                continue;
            }
            foreach ($estagio['manejos'] as $m) {
                if (empty($m['familia_id'])) {
                    continue;
                }
                $comprou = Database::valor(
                    'SELECT 1 FROM compras co JOIN produtos pr ON pr.id = co.produto_id
                      WHERE co.cliente_id = ? AND co.safra_id = ? AND pr.familia_id = ? LIMIT 1',
                    [$clienteId, $safraId, (int) $m['familia_id']]
                );
                if ($comprou) {
                    continue;
                }
                $custoHa = (float) (Database::valor(
                    'SELECT custo_por_ha FROM culturas_referencia WHERE cultura_id = ? AND familia_id = ?',
                    [(int) $p['cultura_id'], (int) $m['familia_id']]
                ) ?: 0);
                $gatilhos[] = [
                    'familia_id' => (int) $m['familia_id'],
                    'familia' => $m['familia'],
                    'titulo' => "{$m['titulo']} — {$p['cultura']} {$estagio['codigo']} ({$p['talhao']})",
                    'valor_estimado' => $custoHa * (float) $p['area_ha'],
                ];
            }
        }
        return $gatilhos;
    }
}
