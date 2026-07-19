<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Services\ComercialService;

/** Portal do Produtor (Módulo 7): o produtor vê apenas os próprios dados. */
class PortalController
{
    public function index(): void
    {
        Auth::exigirLogin();
        if (Auth::perfil() !== 'Produtor') {
            // Perfis internos usam o dashboard normal
            header('Location: ' . url('dashboard'));
            exit;
        }
        $clienteId = (int) (Auth::usuario()['cliente_id'] ?? 0);
        if (!$clienteId) {
            $clienteId = (int) Database::valor('SELECT cliente_id FROM usuarios WHERE id = ?', [Auth::id()]);
        }
        if (!$clienteId) {
            render('portal', ['semVinculo' => true, 'titulo' => 'Meu Portal']);
            return;
        }

        $cliente = Database::um('SELECT * FROM clientes c WHERE c.id = ?', [$clienteId]);
        $painel = ComercialService::painelCliente($clienteId);
        $historicoCompras = ComercialService::historicoCompras($clienteId);

        $visitas = Database::todos(
            "SELECT v.*, u.nome AS tecnico, cu.nome AS cultura, pr.nome AS propriedade
               FROM visitas v
               JOIN usuarios u ON u.id = v.usuario_id
               LEFT JOIN culturas cu ON cu.id = v.cultura_id
               LEFT JOIN propriedades pr ON pr.id = v.propriedade_id
              WHERE v.cliente_id = ? ORDER BY v.data_visita DESC LIMIT 20",
            [$clienteId]
        );
        $titulos = Database::todos(
            "SELECT * FROM titulos_financeiros WHERE cliente_id = ? ORDER BY vencimento DESC LIMIT 30",
            [$clienteId]
        );
        $documentos = Database::todos(
            'SELECT id, tipo, nome, arquivo, criado_em FROM documentos WHERE cliente_id = ? ORDER BY criado_em DESC',
            [$clienteId]
        );
        $pedidos = Database::todos(
            "SELECT id, tipo, status, valor_total, criado_em FROM pedidos WHERE cliente_id = ? ORDER BY criado_em DESC LIMIT 20",
            [$clienteId]
        );

        render('portal', compact('cliente', 'painel', 'historicoCompras', 'visitas', 'titulos', 'documentos', 'pedidos')
            + ['semVinculo' => false, 'titulo' => 'Meu Portal']);
    }
}
