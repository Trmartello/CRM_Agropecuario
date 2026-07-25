<?php

namespace App\Controllers;

use App\Core\Permissoes;
use App\Services\MapaTerritorialService;

/** Mapa Territorial (spec docs/specs/mapa-territorial.md). */
class TerritorioController
{
    /** Perfis internos que enxergam o mapa (dado comercial/território — sem firewall). */
    private const PERFIS = ['Administrador', 'Gestor Comercial', 'Gestor Técnico', 'Consultor Técnico', 'Vendedor', 'Analista'];

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
