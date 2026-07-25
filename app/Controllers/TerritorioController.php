<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\Permissoes;
use App\Services\ConfigService;
use App\Services\MapaTerritorialService;

/** Mapa Territorial (spec docs/specs/mapa-territorial.md). */
class TerritorioController
{
    /** Perfis internos que enxergam o mapa (dado comercial/território — sem firewall). */
    private const PERFIS = ['Administrador', 'Gestor Comercial', 'Gestor Técnico', 'Consultor Técnico', 'Vendedor', 'Analista'];

    /** Página do Mapa Territorial (PR 4): tela com o motor de satélite + polígonos. */
    public function index(): void
    {
        Permissoes::exigir(self::PERFIS);
        $municipios = Database::todos('SELECT DISTINCT municipio, uf FROM dim_imovel ORDER BY municipio');
        $safras = array_column(
            Database::todos('SELECT DISTINCT safra FROM fato_talhao_safra ORDER BY safra DESC'),
            'safra'
        );
        if (!$safras) {
            $safras = ['2025/26', '2024/25'];
        }
        render('territorio', [
            'titulo' => 'Mapa Territorial',
            'municipios' => $municipios,
            'safras' => $safras,
            'tiles' => [
                'url' => ConfigService::obter('mapa_tiles_url',
                    'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}'),
                'atribuicao' => ConfigService::obter('mapa_tiles_atribuicao',
                    'Imagens: Esri, Maxar, Earthstar Geographics'),
                'labels' => ConfigService::obter('mapa_labels_url',
                    'https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}'),
            ],
        ]);
    }

    /**
     * GET territorio/imoveis → GeoJSON FeatureCollection dos imóveis.
     * Filtros: municipio?, uf?, safra?, rtv?, bbox (minLat,minLng,maxLat,maxLng)?.
     * PR 3: o score vem MOCKADO (fonte_score='mock'); o Qlik entra no PR 9.
     */
    public function imoveis(): void
    {
        Permissoes::exigir(self::PERFIS);
        liberar_sessao(); // leitura pode varrer um município inteiro: não segura o lock

        $f = [
            'municipio' => $_GET['municipio'] ?? '',
            'uf' => $_GET['uf'] ?? '',
            'safra' => $_GET['safra'] ?? '',
            'rtv' => $_GET['rtv'] ?? '',
        ];
        $temBbox = isset($_GET['minLat'], $_GET['minLng'], $_GET['maxLat'], $_GET['maxLng'])
            && $_GET['minLat'] !== '' && $_GET['minLng'] !== '' && $_GET['maxLat'] !== '' && $_GET['maxLng'] !== '';
        if ($temBbox) {
            $f['bbox'] = [
                (float) $_GET['minLat'], (float) $_GET['minLng'],
                (float) $_GET['maxLat'], (float) $_GET['maxLng'],
            ];
        }

        try {
            $fc = MapaTerritorialService::geojson($f);
        } catch (\Throwable $e) {
            json_erro($e->getMessage());
        }
        json_resposta($fc);
    }
}
