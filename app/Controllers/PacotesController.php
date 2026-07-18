<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\Permissoes;
use App\Services\PacoteService;

/**
 * Gestão de Pacotes Agrícolas (Gestor Comercial/Administrador).
 * A venda do pacote acontece no módulo Pedidos.
 */
class PacotesController
{
    public function index(): void
    {
        Permissoes::exigir(['Administrador', 'Gestor Comercial', 'Gestor Técnico', 'Analista']);
        $pacotes = Database::todos(
            'SELECT pa.*, cu.nome AS cultura, s.nome AS safra,
                    (SELECT COUNT(*) FROM pacote_categorias pc WHERE pc.pacote_id = pa.id) AS qtd_categorias,
                    (SELECT COUNT(*) FROM pacote_obrigatorios po WHERE po.pacote_id = pa.id) AS qtd_obrigatorios,
                    (SELECT COUNT(*) FROM pedidos pe WHERE pe.pacote_id = pa.id AND pe.status <> \'Cancelado\') AS qtd_vendas
               FROM pacotes_agricolas pa
               LEFT JOIN culturas cu ON cu.id = pa.cultura_id
               LEFT JOIN safras s ON s.id = pa.safra_id
              ORDER BY pa.ativo DESC, pa.nome'
        );
        $culturas = Database::todos('SELECT * FROM culturas ORDER BY nome');
        $safras = Database::todos('SELECT * FROM safras ORDER BY data_inicio DESC');
        $familias = Database::todos('SELECT * FROM familias_produto ORDER BY nome');
        $produtos = Database::todos('SELECT p.id, p.nome, p.unidade, f.nome AS familia FROM produtos p JOIN familias_produto f ON f.id = p.familia_id WHERE p.ativo = 1 ORDER BY f.nome, p.nome');

        render('pacotes', compact('pacotes', 'culturas', 'safras', 'familias', 'produtos') + ['titulo' => 'Pacotes Agrícolas']);
    }

    /** Estrutura para edição (AJAX). */
    public function obter(): void
    {
        Permissoes::exigir(['Administrador', 'Gestor Comercial', 'Gestor Técnico', 'Analista']);
        $pacote = PacoteService::estrutura((int) ($_GET['id'] ?? 0));
        if (!$pacote) {
            json_erro('Pacote não encontrado.', 404);
        }
        json_ok(['pacote' => $pacote]);
    }

    /**
     * Cria/atualiza pacote com categorias e obrigatórios.
     * `categorias` e `obrigatorios` chegam como JSON.
     */
    public function salvar(): void
    {
        Permissoes::exigir(['Administrador', 'Gestor Comercial']);
        $id = (int) ($_POST['id'] ?? 0);
        $nome = trim($_POST['nome'] ?? '');
        if ($nome === '') {
            json_erro('Informe o nome do pacote.');
        }
        $dados = [
            $nome,
            (int) ($_POST['cultura_id'] ?? 0) ?: null,
            (int) ($_POST['safra_id'] ?? 0) ?: null,
            $_POST['vigencia_inicio'] ?: null,
            $_POST['vigencia_fim'] ?: null,
            trim($_POST['regiao'] ?? '') ?: null,
            trim($_POST['campanha'] ?? '') ?: null,
            (float) str_replace(',', '.', $_POST['bonificacao_sacas_ha'] ?? 0),
            (int) ($_POST['ativo'] ?? 1),
        ];

        if ($id > 0) {
            Database::executar(
                'UPDATE pacotes_agricolas SET nome=?, cultura_id=?, safra_id=?, vigencia_inicio=?, vigencia_fim=?, regiao=?, campanha=?, bonificacao_sacas_ha=?, ativo=? WHERE id=?',
                array_merge($dados, [$id])
            );
            Database::executar('DELETE FROM pacote_categorias WHERE pacote_id = ?', [$id]);
            Database::executar('DELETE FROM pacote_obrigatorios WHERE pacote_id = ?', [$id]);
        } else {
            Database::executar(
                'INSERT INTO pacotes_agricolas (nome, cultura_id, safra_id, vigencia_inicio, vigencia_fim, regiao, campanha, bonificacao_sacas_ha, ativo) VALUES (?,?,?,?,?,?,?,?,?)',
                $dados
            );
            $id = Database::ultimoId();
        }

        foreach (json_decode($_POST['categorias'] ?? '[]', true) ?: [] as $cat) {
            Database::executar(
                'INSERT INTO pacote_categorias (pacote_id, familia_id, desconto_pct, bonificacao_pct, obrigatoria, qtd_minima) VALUES (?,?,?,?,?,?)',
                [
                    $id,
                    (int) $cat['familia_id'],
                    (float) ($cat['desconto_pct'] ?? 0),
                    (float) ($cat['bonificacao_pct'] ?? 0),
                    (int) ($cat['obrigatoria'] ?? 0),
                    (float) ($cat['qtd_minima'] ?? 0),
                ]
            );
        }
        foreach (json_decode($_POST['obrigatorios'] ?? '[]', true) ?: [] as $ob) {
            Database::executar(
                'INSERT INTO pacote_obrigatorios (pacote_id, produto_id, dose_ha, num_aplicacoes, qtd_minima, qtd_maxima) VALUES (?,?,?,?,?,?)',
                [
                    $id,
                    (int) $ob['produto_id'],
                    (float) ($ob['dose_ha'] ?? 0),
                    (int) ($ob['num_aplicacoes'] ?? 1),
                    (float) ($ob['qtd_minima'] ?? 0),
                    (float) ($ob['qtd_maxima'] ?? 0),
                ]
            );
        }
        json_ok(['id' => $id]);
    }
}
