<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\Permissoes;

/**
 * Mapa de clientes (Módulo 15). Sem tiles externos (uso em campo, offline):
 * usa as coordenadas cadastradas para plotar e abrir no mapa do aparelho.
 */
class MapaController
{
    public function index(): void
    {
        Permissoes::exigirInterno();
        [$filtro, $params] = Permissoes::filtroCarteira();
        $clientes = Database::todos(
            "SELECT c.id, c.nome, c.municipio, c.estado, c.latitude, c.longitude,
                    c.nivel_tecnologico, c.potencial_venda, c.prospecto,
                    (SELECT MAX(v.data_visita) FROM visitas v WHERE v.cliente_id = c.id) AS ultima_visita
               FROM clientes c
              WHERE c.ativo = 1 AND {$filtro}
              ORDER BY c.nome",
            $params
        );
        $comGeo = array_values(array_filter($clientes, fn ($c) => $c['latitude'] !== null && $c['longitude'] !== null));
        render('mapa', [
            'clientes' => $clientes,
            'comGeo' => $comGeo,
            'titulo' => 'Mapa de Clientes',
        ]);
    }
}
