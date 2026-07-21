<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\Permissoes;
use App\Services\ConfigService;

/**
 * Mapa de clientes (Módulo 15). Pontos plotados sobre a imagem de satélite
 * (mesmo provedor de tiles do croqui, configurável; offline os tiles somem
 * e a distribuição por coordenadas continua). Filtros por produtor,
 * município, estado, nível tecnológico e cultura atual.
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

        // Cultura ATUAL: o que está plantado agora (plantios não encerrados);
        // quem ainda não registrou plantio cai no plano da safra atual (intenção).
        $plantadas = [];
        foreach (Database::todos(
            "SELECT pr.cliente_id, GROUP_CONCAT(DISTINCT cu.nome ORDER BY cu.nome SEPARATOR ', ') AS culturas
               FROM plantios pl
               JOIN talhoes ta ON ta.id = pl.talhao_id
               JOIN propriedades pr ON pr.id = ta.propriedade_id
               JOIN culturas cu ON cu.id = pl.cultura_id
              WHERE pl.encerrado = 0
              GROUP BY pr.cliente_id"
        ) as $l) {
            $plantadas[(int) $l['cliente_id']] = $l['culturas'];
        }
        $planejadas = [];
        foreach (Database::todos(
            "SELECT ps.cliente_id, GROUP_CONCAT(DISTINCT cu.nome ORDER BY cu.nome SEPARATOR ', ') AS culturas
               FROM planos_safra ps
               JOIN safras s ON s.id = ps.safra_id AND s.atual = 1
               JOIN culturas cu ON cu.id = ps.cultura_id
              GROUP BY ps.cliente_id"
        ) as $l) {
            $planejadas[(int) $l['cliente_id']] = $l['culturas'];
        }

        $municipios = [];
        $estados = [];
        $culturasFiltro = [];
        foreach ($clientes as &$c) {
            $id = (int) $c['id'];
            $c['culturas'] = $plantadas[$id] ?? $planejadas[$id] ?? '';
            if (!empty($c['municipio'])) {
                $municipios[$c['municipio']] = true;
            }
            if (!empty($c['estado'])) {
                $estados[$c['estado']] = true;
            }
            foreach (array_filter(array_map('trim', explode(',', $c['culturas']))) as $cu) {
                $culturasFiltro[$cu] = true;
            }
        }
        unset($c);
        ksort($municipios);
        ksort($estados);
        ksort($culturasFiltro);

        $comGeo = array_values(array_filter($clientes, fn ($c) => $c['latitude'] !== null && $c['longitude'] !== null));
        render('mapa', [
            'clientes' => $clientes,
            'comGeo' => $comGeo,
            'municipios' => array_keys($municipios),
            'estados' => array_keys($estados),
            'culturasFiltro' => array_keys($culturasFiltro),
            // Mesmo provedor de satélite do croqui (vazio = sem mapa online)
            'tiles' => [
                'url' => ConfigService::obter('mapa_tiles_url',
                    'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}'),
                'atribuicao' => ConfigService::obter('mapa_tiles_atribuicao',
                    'Imagens: Esri, Maxar, Earthstar Geographics'),
            ],
            'titulo' => 'Mapa de Clientes',
        ]);
    }
}
