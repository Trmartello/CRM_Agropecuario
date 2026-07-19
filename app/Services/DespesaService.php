<?php

namespace App\Services;

use App\Core\Database;

/**
 * Despesas de campo: quilometragem e refeições.
 * O valor do km é calculado no servidor a partir da categoria de reembolso do
 * usuário (nunca confia no valor enviado pelo navegador).
 */
class DespesaService
{
    /** Categoria de reembolso do usuário (valor_km, teto_refeicao) ou null. */
    public static function categoriaUsuario(int $usuarioId): ?array
    {
        return Database::um(
            'SELECT cr.id, cr.nome, cr.valor_km, cr.teto_refeicao
               FROM usuarios u
               JOIN categorias_reembolso cr ON cr.id = u.categoria_reembolso_id
              WHERE u.id = ?',
            [$usuarioId]
        );
    }

    public const TIPOS_DESTINO = ['Produtor', 'Filial', 'Lugar'];

    /** Veículos ativos cadastrados pelo usuário. */
    public static function veiculosUsuario(int $usuarioId): array
    {
        return Database::todos(
            'SELECT id, descricao, placa FROM veiculos WHERE usuario_id = ? AND ativo = 1 ORDER BY descricao',
            [$usuarioId]
        );
    }

    /**
     * Veículo a pré-selecionar no lançamento: o usado no último lançamento do
     * usuário; se não houver, e ele tiver apenas um veículo, esse único.
     */
    public static function veiculoPadraoUsuario(int $usuarioId): int
    {
        $ultimo = (int) Database::valor(
            'SELECT veiculo_id FROM quilometragem
              WHERE usuario_id = ? AND veiculo_id IS NOT NULL
              ORDER BY data DESC, id DESC LIMIT 1',
            [$usuarioId]
        );
        if ($ultimo) {
            // confirma que o veículo ainda existe e está ativo
            $ok = Database::valor('SELECT 1 FROM veiculos WHERE id = ? AND usuario_id = ? AND ativo = 1', [$ultimo, $usuarioId]);
            if ($ok) {
                return $ultimo;
            }
        }
        $veiculos = self::veiculosUsuario($usuarioId);
        return count($veiculos) === 1 ? (int) $veiculos[0]['id'] : 0;
    }

    /** Cadastra um veículo para o usuário; retorna o registro criado. */
    public static function criarVeiculo(int $usuarioId, string $descricao, string $placa): array
    {
        $descricao = trim($descricao);
        if ($descricao === '') {
            throw new \InvalidArgumentException('Informe a descrição do veículo.');
        }
        Database::executar(
            'INSERT INTO veiculos (usuario_id, descricao, placa) VALUES (?,?,?)',
            [$usuarioId, mb_substr($descricao, 0, 120), trim($placa) ?: null]
        );
        $id = Database::ultimoId();
        return ['id' => $id, 'descricao' => $descricao, 'placa' => trim($placa) ?: null];
    }

    /** KM final do último lançamento do usuário (opcionalmente do veículo informado). */
    public static function ultimoKmFinal(int $usuarioId, int $veiculoId = 0): ?float
    {
        $sql = 'SELECT km_final FROM quilometragem WHERE usuario_id = ?';
        $params = [$usuarioId];
        if ($veiculoId > 0) {
            $sql .= ' AND veiculo_id = ?';
            $params[] = $veiculoId;
        }
        $sql .= ' ORDER BY data DESC, id DESC LIMIT 1';
        $valor = Database::valor($sql, $params);
        return $valor === false || $valor === null ? null : (float) $valor;
    }

    /** Registra um lançamento de quilometragem; retorna [id, valor, aviso]. */
    public static function registrarKm(int $usuarioId, array $dados): array
    {
        $kmInicial = (float) str_replace(',', '.', (string) ($dados['km_inicial'] ?? ''));
        $kmFinal = (float) str_replace(',', '.', (string) ($dados['km_final'] ?? ''));
        if ($kmFinal <= $kmInicial) {
            throw new \InvalidArgumentException('O KM final deve ser maior que o KM inicial.');
        }
        if (trim($dados['motivo'] ?? '') === '') {
            throw new \InvalidArgumentException('Informe o motivo do deslocamento.');
        }

        // Veículo do próprio usuário
        $veiculoId = (int) ($dados['veiculo_id'] ?? 0) ?: null;
        $veiculoTexto = null;
        if ($veiculoId) {
            $v = Database::um('SELECT descricao, placa FROM veiculos WHERE id = ? AND usuario_id = ?', [$veiculoId, $usuarioId]);
            if (!$v) {
                throw new \InvalidArgumentException('Veículo não encontrado.');
            }
            $veiculoTexto = $v['descricao'] . ($v['placa'] ? ' — ' . $v['placa'] : '');
        }

        // Destino estruturado
        $tipo = in_array($dados['tipo_destino'] ?? '', self::TIPOS_DESTINO, true) ? $dados['tipo_destino'] : 'Lugar';
        $clienteId = null;
        $filialId = null;
        $prospecto = null;
        $destino = null;
        if ($tipo === 'Produtor') {
            // Sempre um cliente cadastrado (produtor ou prospecto pré-cadastrado) — nunca texto livre
            $clienteId = (int) ($dados['cliente_id'] ?? 0) ?: null;
            if (!$clienteId) {
                throw new \InvalidArgumentException('Selecione o produtor ou faça o pré-cadastro do prospecto.');
            }
        } elseif ($tipo === 'Filial') {
            $filialId = (int) ($dados['filial_id'] ?? 0) ?: null;
            if (!$filialId) {
                throw new \InvalidArgumentException('Selecione a filial de destino.');
            }
        } else { // Lugar
            $destino = trim($dados['destino'] ?? '') ?: null;
            if (!$destino) {
                throw new \InvalidArgumentException('Informe o local de destino.');
            }
        }

        $rodados = $kmFinal - $kmInicial;
        $categoria = self::categoriaUsuario($usuarioId);
        $valorKm = $categoria ? (float) $categoria['valor_km'] : 0.0;
        $valor = round($rodados * $valorKm, 2);
        $aviso = $categoria ? null : 'Usuário sem categoria de reembolso definida — valor calculado como R$ 0,00.';

        Database::executar(
            'INSERT INTO quilometragem
                (usuario_id, veiculo_id, veiculo, data, km_inicial, km_final, valor, tipo_destino, cliente_id, filial_id, prospecto, destino, motivo)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $usuarioId,
                $veiculoId,
                $veiculoTexto,
                $dados['data'] ?: date('Y-m-d'),
                $kmInicial,
                $kmFinal,
                $valor,
                $tipo,
                $clienteId,
                $filialId,
                $prospecto,
                $destino,
                trim($dados['motivo'] ?? '') ?: null,
            ]
        );
        $kmId = Database::ultimoId();

        // Amarração automática com a visita realizada (mesmo técnico, produtor e data)
        $vinculada = false;
        if ($tipo === 'Produtor' && $clienteId) {
            $vinculada = self::vincularVisitaKm($kmId, $usuarioId, $clienteId, $dados['data'] ?: date('Y-m-d'));
        }
        return ['id' => $kmId, 'valor' => $valor, 'aviso' => $aviso, 'vinculada_visita' => $vinculada];
    }

    /**
     * Amarra um lançamento de KM à visita do mesmo técnico/produtor/data.
     * Casa 1:1: escolhe a primeira visita do dia que ainda NÃO tem KM amarrada,
     * para que duas visitas no mesmo dia (deslocamentos distintos) não colidam.
     */
    public static function vincularVisitaKm(int $kmId, int $usuarioId, int $clienteId, string $data): bool
    {
        $visitaId = Database::valor(
            'SELECT v.id FROM visitas v
              WHERE v.usuario_id = ? AND v.cliente_id = ? AND v.data_visita = ?
                AND NOT EXISTS (SELECT 1 FROM quilometragem q WHERE q.visita_id = v.id)
              ORDER BY v.id ASC LIMIT 1',
            [$usuarioId, $clienteId, $data]
        );
        if ($visitaId) {
            Database::executar('UPDATE quilometragem SET visita_id = ? WHERE id = ?', [(int) $visitaId, $kmId]);
            return true;
        }
        return false;
    }

    /**
     * Ao registrar uma visita, amarra UM deslocamento órfão do dia (o mais antigo)
     * a ela — cada visita corresponde a um deslocamento, mesmo com várias no dia.
     */
    public static function vincularVisitaPorEvento(int $visitaId, int $usuarioId, int $clienteId, string $data): int
    {
        $stmt = Database::executar(
            "UPDATE quilometragem SET visita_id = ?
              WHERE usuario_id = ? AND cliente_id = ? AND data = ? AND tipo_destino = 'Produtor' AND visita_id IS NULL
              ORDER BY id ASC LIMIT 1",
            [$visitaId, $usuarioId, $clienteId, $data]
        );
        return $stmt->rowCount();
    }

    public const TIPOS_REFEICAO = ['Café', 'Almoço', 'Lanche', 'Janta'];

    /** Valores de reembolso por tipo de refeição da categoria do usuário (tipo => valor). */
    public static function valoresRefeicaoUsuario(int $usuarioId): array
    {
        $categoria = self::categoriaUsuario($usuarioId);
        if (!$categoria) {
            return [];
        }
        $linhas = Database::todos(
            'SELECT tipo, valor FROM reembolso_refeicoes WHERE categoria_reembolso_id = ?',
            [(int) $categoria['id']]
        );
        $mapa = [];
        foreach ($linhas as $l) {
            $mapa[$l['tipo']] = (float) $l['valor'];
        }
        return $mapa;
    }

    /** Registra uma refeição; retorna [id, valor_reembolso, aviso]. */
    public static function registrarRefeicao(int $usuarioId, array $dados): array
    {
        $valor = (float) str_replace(',', '.', (string) ($dados['valor'] ?? ''));
        if ($valor <= 0) {
            throw new \InvalidArgumentException('Informe o valor da refeição.');
        }
        $tipo = in_array($dados['tipo'] ?? '', self::TIPOS_REFEICAO, true) ? $dados['tipo'] : 'Almoço';

        // Data e horário: aceita "datahora" (datetime-local) ou data/hora separados
        $data = $dados['data'] ?? '';
        $hora = $dados['hora'] ?? '';
        if (!empty($dados['datahora'])) {
            $dt = str_replace('T', ' ', trim($dados['datahora']));
            $data = substr($dt, 0, 10);
            $hora = substr($dt, 11, 5);
        }
        $data = $data ?: date('Y-m-d');
        $hora = $hora ?: null;

        // Reembolso = min(gasto, teto da categoria/tipo). Sem teto configurado, reembolsa o gasto.
        $valores = self::valoresRefeicaoUsuario($usuarioId);
        $teto = $valores[$tipo] ?? null;
        $reembolso = ($teto !== null && $teto > 0) ? min($valor, $teto) : $valor;
        $aviso = null;
        if ($teto !== null && $teto > 0 && $valor > $teto) {
            $aviso = 'Nota de ' . moeda($valor) . ' acima do teto de ' . $tipo . ' (' . moeda($teto)
                . ') — a Copérdia reembolsa ' . moeda($reembolso) . '.';
        }

        Database::executar(
            'INSERT INTO refeicoes (usuario_id, data, hora, tipo, cliente_id, estabelecimento, valor, valor_reembolso, comprovante, justificativa)
             VALUES (?,?,?,?,?,?,?,?,?,?)',
            [
                $usuarioId,
                $data,
                $hora,
                $tipo,
                (int) ($dados['cliente_id'] ?? 0) ?: null,
                trim($dados['estabelecimento'] ?? '') ?: null,
                $valor,
                $reembolso,
                $dados['comprovante'] ?? null,
                trim($dados['justificativa'] ?? '') ?: null,
            ]
        );
        return ['id' => Database::ultimoId(), 'valor_reembolso' => $reembolso, 'aviso' => $aviso];
    }

    /**
     * Lista lançamentos de KM. $escopoUsuario = id do usuário para restringir à
     * própria carteira; 0 (gestor) vê todos.
     */
    public static function listarKm(int $escopoUsuario, ?string $mes = null): array
    {
        [$where, $params] = self::filtro($escopoUsuario, 'q', $mes);
        return Database::todos(
            "SELECT q.*, (q.km_final - q.km_inicial) AS km_rodados,
                    u.nome AS usuario, c.nome AS cliente, f.nome AS filial,
                    CASE q.tipo_destino
                      WHEN 'Produtor' THEN COALESCE(c.nome, q.prospecto)
                      WHEN 'Filial' THEN f.nome
                      ELSE q.destino
                    END AS destino_desc
               FROM quilometragem q
               JOIN usuarios u ON u.id = q.usuario_id
               LEFT JOIN clientes c ON c.id = q.cliente_id
               LEFT JOIN filiais f ON f.id = q.filial_id
              WHERE {$where}
              ORDER BY q.data DESC, q.id DESC
              LIMIT 500",
            $params
        );
    }

    public static function listarRefeicoes(int $escopoUsuario, ?string $mes = null): array
    {
        [$where, $params] = self::filtro($escopoUsuario, 'r', $mes);
        return Database::todos(
            "SELECT r.*, u.nome AS usuario, c.nome AS cliente
               FROM refeicoes r
               JOIN usuarios u ON u.id = r.usuario_id
               LEFT JOIN clientes c ON c.id = r.cliente_id
              WHERE {$where}
              ORDER BY r.data DESC, r.id DESC
              LIMIT 500",
            $params
        );
    }

    /** Monta a cláusula de escopo (usuário + mês opcional AAAA-MM). */
    private static function filtro(int $escopoUsuario, string $alias, ?string $mes): array
    {
        $where = '1=1';
        $params = [];
        if ($escopoUsuario > 0) {
            $where .= " AND {$alias}.usuario_id = ?";
            $params[] = $escopoUsuario;
        }
        if ($mes && preg_match('/^\d{4}-\d{2}$/', $mes)) {
            $where .= " AND DATE_FORMAT({$alias}.data, '%Y-%m') = ?";
            $params[] = $mes;
        }
        return [$where, $params];
    }

    public static function excluirKm(int $id, int $usuarioId, bool $ehGestor): void
    {
        self::excluir('quilometragem', $id, $usuarioId, $ehGestor);
    }

    public static function excluirRefeicao(int $id, int $usuarioId, bool $ehGestor): void
    {
        self::excluir('refeicoes', $id, $usuarioId, $ehGestor);
    }

    /** Só permite excluir o próprio lançamento (ou gestor) e ainda não consolidado. */
    private static function excluir(string $tabela, int $id, int $usuarioId, bool $ehGestor): void
    {
        $registro = Database::um("SELECT usuario_id, prestacao_id FROM {$tabela} WHERE id = ?", [$id]);
        if (!$registro) {
            throw new \RuntimeException('Lançamento não encontrado.');
        }
        if (!$ehGestor && (int) $registro['usuario_id'] !== $usuarioId) {
            throw new \RuntimeException('Você só pode excluir os próprios lançamentos.');
        }
        if ($registro['prestacao_id'] !== null) {
            throw new \RuntimeException('Lançamento já consolidado em uma prestação — não pode ser excluído.');
        }
        Database::executar("DELETE FROM {$tabela} WHERE id = ?", [$id]);
    }
}
