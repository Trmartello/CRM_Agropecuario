<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\Permissoes;
use App\Services\FenologiaService;

/** Plantio por talhão (Fase 6E): registrar e encerrar (colheita). */
class PlantiosController
{
    public function salvar(): void
    {
        Permissoes::exigirInterno();
        $talhaoId = (int) ($_POST['talhao_id'] ?? 0);
        $this->talhaoDaCarteira($talhaoId);
        try {
            $id = FenologiaService::salvarPlantio(
                $talhaoId,
                (int) ($_POST['cultura_id'] ?? 0),
                trim($_POST['data_plantio'] ?? ''),
                $_POST['cultivar'] ?? null,
                (int) ($_POST['finalidade_id'] ?? 0) ?: null, // v40: grão, silagem, pastagem...
                trim($_POST['safra'] ?? '') ?: null // v51: "2025/2026"
            );
        } catch (\InvalidArgumentException $e) {
            json_erro($e->getMessage());
        }
        auditar('criar', 'plantio', $id, 'talhão #' . $talhaoId);
        json_ok(['id' => $id, 'plantio' => FenologiaService::plantioAtivo($talhaoId)]);
    }

    public function encerrar(): void
    {
        Permissoes::exigirInterno();
        $id = (int) ($_POST['id'] ?? 0);
        $talhaoId = (int) Database::valor('SELECT talhao_id FROM plantios WHERE id = ?', [$id]);
        $this->talhaoDaCarteira($talhaoId);
        try {
            FenologiaService::encerrarPlantio(
                $id,
                (float) str_replace(',', '.', $_POST['produtividade'] ?? '0'),
                trim($_POST['colhido_em'] ?? '') ?: null
            );
        } catch (\InvalidArgumentException $e) {
            json_erro($e->getMessage());
        }
        auditar('finalizar', 'plantio', $id, 'colheita talhão #' . $talhaoId);
        json_ok();
    }

    /** Garante que o talhão pertence a um cliente da carteira do usuário. */
    private function talhaoDaCarteira(int $talhaoId): void
    {
        [$filtro, $params] = Permissoes::filtroCarteira();
        $ok = Database::valor(
            "SELECT 1 FROM talhoes t
               JOIN propriedades pr ON pr.id = t.propriedade_id
               JOIN clientes c ON c.id = pr.cliente_id
              WHERE t.id = ? AND {$filtro}",
            array_merge([$talhaoId], $params)
        );
        if (!$ok) {
            json_erro('Talhão não encontrado na sua carteira.', 404);
        }
    }
}
