<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Permissoes;
use App\Services\PotencialService;

class RelatoriosController
{
    /** Fase 6B: relatório de fechamento de safra por produtor (imprimível). */
    public function safra(): void
    {
        Auth::exigirLogin();
        if (Auth::perfil() === 'Produtor') {
            // Portal: o produtor vê apenas o próprio relatório (vínculo usuarios.cliente_id)
            $clienteId = (int) Database::valor('SELECT cliente_id FROM usuarios WHERE id = ?', [Auth::id()]);
            if ($clienteId <= 0) {
                http_response_code(403);
                exit('Acesso não autorizado.');
            }
        } else {
            Permissoes::exigirInterno();
            $clienteId = (int) ($_GET['cliente'] ?? 0);
            [$filtro, $params] = Permissoes::filtroCarteira();
            $ok = Database::valor(
                "SELECT 1 FROM clientes c WHERE c.id = ? AND {$filtro}",
                array_merge([$clienteId], $params)
            );
            if (!$ok) {
                http_response_code(404);
                exit('Produtor não encontrado na sua carteira.');
            }
        }

        $safras = Database::todos('SELECT * FROM safras ORDER BY data_inicio DESC');
        $safraId = (int) ($_GET['safra'] ?? 0);
        if ($safraId <= 0) {
            $atual = \App\Services\ComercialService::safraAtual();
            $safraId = $atual ? (int) $atual['id'] : (int) ($safras[0]['id'] ?? 0);
        }

        $dados = \App\Services\RelatorioSafraService::dados($clienteId, $safraId);
        if (!$dados) {
            http_response_code(404);
            exit('Safra ou produtor não encontrado.');
        }
        auditar('gerar', 'relatorio_safra', $clienteId, 'safra #' . $safraId);
        render('relatorio_safra', $dados + [
            'safras' => $safras,
            'safraId' => $safraId,
            'ehProdutor' => Auth::perfil() === 'Produtor',
            'titulo' => 'Fechamento de Safra',
        ]);
    }

    /** Dashboard Potencial x Realizado com troca de dimensão na mesma tela. */
    public function potencial(): void
    {
        Permissoes::exigirInterno();
        $familias = Database::todos('SELECT * FROM familias_produto ORDER BY nome');
        render('potencial', compact('familias') + ['titulo' => 'Potencial x Realizado']);
    }

    /** Dados do ranking (AJAX) — dimensão: cliente|municipio|estado|filial. */
    public function potencialDados(): void
    {
        Permissoes::exigirInterno();
        [$filtro, $params] = Permissoes::filtroCarteira();
        $dimensao = $_GET['dimensao'] ?? 'cliente';
        $ordem = $_GET['ordem'] ?? 'desc';
        $familiaId = (int) ($_GET['familia_id'] ?? 0) ?: null;
        $ranking = PotencialService::ranking($dimensao, $ordem, $filtro, $params, $familiaId);
        json_ok(['ranking' => $ranking]);
    }
}
