<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\Permissoes;
use App\Services\PotencialService;

class RelatoriosController
{
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
