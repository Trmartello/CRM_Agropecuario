<?php

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;

/**
 * Gestão de reclamações (laudos de produto).
 * Fluxo: Registrada → Em análise → Procedente/Improcedente → Jurídico → Indenização → Encerrada.
 */
class ReclamacaoService
{
    public const STATUS = ['Registrada', 'Em análise', 'Procedente', 'Improcedente', 'Jurídico', 'Indenização', 'Encerrada'];
    public const TIPOS = ['Sementes', 'Fertilizantes', 'Defensivos', 'Biológicos', 'Outros'];

    /** Transições permitidas a partir de cada status (fluxo do laudo). */
    public const TRANSICOES = [
        'Registrada' => ['Em análise', 'Encerrada'],
        'Em análise' => ['Procedente', 'Improcedente', 'Encerrada'],
        'Procedente' => ['Jurídico', 'Indenização', 'Encerrada'],
        'Improcedente' => ['Encerrada'],
        'Jurídico' => ['Indenização', 'Encerrada'],
        'Indenização' => ['Encerrada'],
        'Encerrada' => [],
    ];

    public static function registrar(array $dados): int
    {
        $clienteId = (int) ($dados['cliente_id'] ?? 0);
        if ($clienteId <= 0) {
            throw new \InvalidArgumentException('Selecione o produtor.');
        }
        $tipo = $dados['tipo'] ?? '';
        if (!in_array($tipo, self::TIPOS, true)) {
            throw new \InvalidArgumentException('Tipo de reclamação inválido.');
        }
        Database::executar(
            'INSERT INTO reclamacoes (cliente_id, usuario_id, produto_id, tipo, lote, nota_fiscal, cultura_id, problema, descricao, status)
             VALUES (?,?,?,?,?,?,?,?,?,\'Registrada\')',
            [
                $clienteId,
                Auth::id(),
                (int) ($dados['produto_id'] ?? 0) ?: null,
                $tipo,
                trim($dados['lote'] ?? '') ?: null,
                trim($dados['nota_fiscal'] ?? '') ?: null,
                (int) ($dados['cultura_id'] ?? 0) ?: null,
                trim($dados['problema'] ?? '') ?: null,
                trim($dados['descricao'] ?? '') ?: null,
            ]
        );
        return Database::ultimoId();
    }

    /** Move o laudo para outro status, respeitando o fluxo. */
    public static function mover(int $id, string $novoStatus, string $parecer = '', ?float $valorIndenizacao = null): void
    {
        $rec = Database::um('SELECT status FROM reclamacoes WHERE id = ?', [$id]);
        if (!$rec) {
            throw new \RuntimeException('Reclamação não encontrada.');
        }
        $atual = $rec['status'];
        if (!in_array($novoStatus, self::TRANSICOES[$atual] ?? [], true)) {
            throw new \RuntimeException("Transição inválida: de \"{$atual}\" para \"{$novoStatus}\".");
        }
        if ($novoStatus === 'Indenização' && ($valorIndenizacao === null || $valorIndenizacao <= 0)) {
            throw new \RuntimeException('Informe o valor da indenização.');
        }
        Database::executar(
            'UPDATE reclamacoes
                SET status = ?, parecer = ?, valor_indenizacao = COALESCE(?, valor_indenizacao), atualizado_em = NOW()
              WHERE id = ?',
            [$novoStatus, trim($parecer) ?: null, $valorIndenizacao, $id]
        );

        // Notifica quem registrou a reclamação sobre a movimentação do laudo
        $rec2 = Database::um('SELECT usuario_id, cliente_id FROM reclamacoes WHERE id = ?', [$id]);
        if ($rec2 && $rec2['usuario_id']) {
            $cli = Database::valor('SELECT nome FROM clientes WHERE id = ?', [(int) $rec2['cliente_id']]);
            NotificacaoService::criar((int) $rec2['usuario_id'], 'reclamacao',
                'Reclamação: ' . $novoStatus, 'Laudo de ' . $cli . ' foi para "' . $novoStatus . '".', 'index.php?r=reclamacoes');
        }
    }

    /** Lista aplicando o filtro de carteira (gestor vê tudo; campo vê os próprios clientes). */
    public static function listar(string $filtroCarteira, array $params, ?string $status = null): array
    {
        $where = $filtroCarteira;
        if ($status && in_array($status, self::STATUS, true)) {
            $where .= ' AND r.status = ?';
            $params[] = $status;
        }
        return Database::todos(
            "SELECT r.*, c.nome AS cliente, p.nome AS produto, cu.nome AS cultura, u.nome AS registrado_por,
                    (SELECT COUNT(*) FROM reclamacao_fotos rf WHERE rf.reclamacao_id = r.id) AS qtd_fotos
               FROM reclamacoes r
               JOIN clientes c ON c.id = r.cliente_id
               LEFT JOIN produtos p ON p.id = r.produto_id
               LEFT JOIN culturas cu ON cu.id = r.cultura_id
               LEFT JOIN usuarios u ON u.id = r.usuario_id
              WHERE {$where}
              ORDER BY FIELD(r.status,'Registrada','Em análise','Procedente','Jurídico','Indenização','Improcedente','Encerrada'),
                       r.criado_em DESC
              LIMIT 300",
            $params
        );
    }

    public static function detalhe(int $id, string $filtroCarteira, array $params): array
    {
        $rec = Database::um(
            "SELECT r.*, c.nome AS cliente, p.nome AS produto, cu.nome AS cultura, u.nome AS registrado_por
               FROM reclamacoes r
               JOIN clientes c ON c.id = r.cliente_id
               LEFT JOIN produtos p ON p.id = r.produto_id
               LEFT JOIN culturas cu ON cu.id = r.cultura_id
               LEFT JOIN usuarios u ON u.id = r.usuario_id
              WHERE r.id = ? AND {$filtroCarteira}",
            array_merge([$id], $params)
        );
        if (!$rec) {
            throw new \RuntimeException('Reclamação não encontrada na sua carteira.');
        }
        $fotos = Database::todos('SELECT * FROM reclamacao_fotos WHERE reclamacao_id = ?', [$id]);
        return ['reclamacao' => $rec, 'fotos' => $fotos, 'transicoes' => self::TRANSICOES[$rec['status']] ?? []];
    }

    public static function indicadores(string $filtroCarteira, array $params): array
    {
        $abertas = Database::valor(
            "SELECT COUNT(*) FROM reclamacoes r JOIN clientes c ON c.id = r.cliente_id
              WHERE {$filtroCarteira} AND r.status NOT IN ('Encerrada','Improcedente')",
            $params
        );
        return ['abertas' => (int) $abertas];
    }
}
