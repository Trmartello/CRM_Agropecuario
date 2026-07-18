<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Permissoes;
use App\Services\OportunidadeService;

class FunilController
{
    public function index(): void
    {
        Permissoes::exigirInterno();
        [$filtro, $params] = Permissoes::filtroCarteira();

        OportunidadeService::gerarAutomaticas($filtro, $params);
        $kanban = OportunidadeService::kanban($filtro, $params);
        $totais = OportunidadeService::totais($kanban);

        $clientes = Database::todos(
            "SELECT c.id, c.nome FROM clientes c WHERE c.ativo = 1 AND {$filtro} ORDER BY c.nome",
            $params
        );
        $familias = Database::todos('SELECT * FROM familias_produto ORDER BY nome');

        render('funil', compact('kanban', 'totais', 'clientes', 'familias') + ['titulo' => 'Funil de Oportunidades']);
    }

    /** Cria oportunidade manual (modal AJAX). */
    public function salvar(): void
    {
        Permissoes::exigirInterno();
        $clienteId = (int) ($_POST['cliente_id'] ?? 0);
        [$filtro, $params] = Permissoes::filtroCarteira();
        $ok = Database::um(
            "SELECT c.id FROM clientes c WHERE c.id = ? AND {$filtro}",
            array_merge([$clienteId], $params)
        );
        if (!$ok) {
            json_erro('Cliente não encontrado na sua carteira.', 404);
        }
        $titulo = trim($_POST['titulo'] ?? '');
        if ($titulo === '') {
            json_erro('Informe o título da oportunidade.');
        }
        $credito = \App\Services\CreditoService::situacao($clienteId);
        $safra = \App\Services\ComercialService::safraAtual();
        Database::executar(
            'INSERT INTO oportunidades (cliente_id, usuario_id, safra_id, familia_id, origem, titulo, valor_estimado, estagio, data_prevista, pendente_aprovacao)
             VALUES (?,?,?,?,"Manual",?,?,"Identificada",?,?)',
            [
                $clienteId,
                Auth::id(),
                $safra ? (int) $safra['id'] : null,
                (int) ($_POST['familia_id'] ?? 0) ?: null,
                $titulo,
                (float) str_replace(',', '.', $_POST['valor_estimado'] ?? 0),
                $_POST['data_prevista'] ?: null,
                $credito['exige_aprovacao'] ? 1 : 0,
            ]
        );
        json_ok(['id' => Database::ultimoId(), 'pendente_aprovacao' => $credito['exige_aprovacao']]);
    }

    /** Move oportunidade de estágio (Kanban AJAX). Perda exige motivo. */
    public function mover(): void
    {
        Permissoes::exigirInterno();
        $id = (int) ($_POST['id'] ?? 0);
        $estagio = $_POST['estagio'] ?? '';
        if (!in_array($estagio, OportunidadeService::ESTAGIOS, true)) {
            json_erro('Estágio inválido.');
        }

        [$filtro, $params] = Permissoes::filtroCarteira();
        $oport = Database::um(
            "SELECT o.* FROM oportunidades o JOIN clientes c ON c.id = o.cliente_id
              WHERE o.id = ? AND {$filtro}",
            array_merge([$id], $params)
        );
        if (!$oport) {
            json_erro('Oportunidade não encontrada.', 404);
        }

        // Trava de alçada: oportunidade pendente não avança para Ganha sem aprovação do gestor
        if ($estagio === 'Ganha' && (int) $oport['pendente_aprovacao'] === 1 && !$oport['aprovada_por']) {
            if (!in_array(Auth::perfil(), ['Administrador', 'Gestor Comercial'], true)) {
                json_erro('Cliente com pendência de crédito: fechamento exige aprovação do Gestor Comercial.', 403);
            }
            Database::executar('UPDATE oportunidades SET aprovada_por = ? WHERE id = ?', [Auth::id(), $id]);
        }

        if ($estagio === 'Perdida') {
            $motivo = $_POST['motivo_perda'] ?? '';
            if (!in_array($motivo, ['Preço', 'Prazo', 'Concorrente', 'Desistência', 'Clima', 'Outro'], true)) {
                json_erro('Informe o motivo da perda.');
            }
            Database::executar(
                'UPDATE oportunidades SET estagio=?, motivo_perda=?, concorrente_perda=? WHERE id=?',
                [$estagio, $motivo, trim($_POST['concorrente_perda'] ?? '') ?: null, $id]
            );
        } else {
            Database::executar('UPDATE oportunidades SET estagio=? WHERE id=?', [$estagio, $id]);
        }
        json_ok();
    }

    /** Aprova pendência de crédito (Gestor Comercial). */
    public function aprovar(): void
    {
        Permissoes::exigir(['Administrador', 'Gestor Comercial']);
        $id = (int) ($_POST['id'] ?? 0);
        Database::executar('UPDATE oportunidades SET aprovada_por = ? WHERE id = ? AND pendente_aprovacao = 1', [Auth::id(), $id]);
        json_ok();
    }

    /** Registra proposta para uma oportunidade (modal AJAX). */
    public function salvarProposta(): void
    {
        Permissoes::exigirInterno();
        $oportunidadeId = (int) ($_POST['oportunidade_id'] ?? 0);
        [$filtro, $params] = Permissoes::filtroCarteira();
        $oport = Database::um(
            "SELECT o.id FROM oportunidades o JOIN clientes c ON c.id = o.cliente_id
              WHERE o.id = ? AND {$filtro}",
            array_merge([$oportunidadeId], $params)
        );
        if (!$oport) {
            json_erro('Oportunidade não encontrada.', 404);
        }
        $validade = $_POST['validade'] ?? '';
        if (!$validade) {
            json_erro('Informe a validade da proposta.');
        }
        Database::executar(
            'INSERT INTO propostas (oportunidade_id, validade, condicao_pagamento, valor_total) VALUES (?,?,?,?)',
            [
                $oportunidadeId,
                $validade,
                trim($_POST['condicao_pagamento'] ?? '') ?: null,
                (float) str_replace(',', '.', $_POST['valor_total'] ?? 0),
            ]
        );
        Database::executar(
            "UPDATE oportunidades SET estagio = 'Proposta' WHERE id = ? AND estagio = 'Identificada'",
            [$oportunidadeId]
        );
        json_ok(['id' => Database::ultimoId()]);
    }
}
