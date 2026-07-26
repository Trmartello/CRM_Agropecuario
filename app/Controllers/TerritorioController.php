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

    /** GET territorio/imovel?cod=&safra= → ficha completa do imóvel (PR 6). */
    public function imovel(): void
    {
        Permissoes::exigir(self::PERFIS);
        liberar_sessao();
        $cod = trim($_GET['cod'] ?? '');
        $safra = trim($_GET['safra'] ?? '');
        if ($cod === '') {
            json_erro('Código do imóvel não informado.');
        }
        $ficha = MapaTerritorialService::ficha($cod, $safra);
        if ($ficha === null) {
            json_resposta(['erro' => 'Imóvel não encontrado.'], 404);
        }
        json_resposta($ficha);
    }

    /** GET territorio/localizar?lat=&lng= → imóvel do CAR no ponto (PR 8). */
    public function localizar(): void
    {
        Permissoes::exigir(self::PERFIS);
        liberar_sessao();
        // Coordenada via POST (corpo), nunca query string: o GPS na propriedade é
        // efetivamente a coordenada da propriedade do cooperado e não pode parar
        // no access log do proxy (invariante 4).
        $lat = isset($_POST['lat']) && $_POST['lat'] !== '' ? (float) $_POST['lat'] : null;
        $lng = isset($_POST['lng']) && $_POST['lng'] !== '' ? (float) $_POST['lng'] : null;
        $produtor = (int) ($_POST['produtor'] ?? 0);
        if ($lat === null || $lng === null || ($lat === 0.0 && $lng === 0.0)) {
            json_erro('Coordenada inválida.');
        }
        json_ok(MapaTerritorialService::localizar($lat, $lng, $produtor));
    }

    /** POST territorio/vincular → cria o vínculo imóvel↔produtor (RTV confirma; PR 8). */
    public function vincular(): void
    {
        Permissoes::exigir(self::PERFIS);
        $cod = trim($_POST['cod_car'] ?? $_POST['codCar'] ?? '');
        $produtorId = (int) ($_POST['produtor_id'] ?? $_POST['produtorId'] ?? 0);
        $papel = trim($_POST['papel'] ?? 'proprietario');
        $origem = trim($_POST['origem'] ?? 'manual');
        $principal = !empty($_POST['principal']) && $_POST['principal'] !== '0';
        if ($cod === '' || $produtorId <= 0) {
            json_erro('Informe o imóvel e o produtor.');
        }
        try {
            $r = MapaTerritorialService::vincular($cod, $produtorId, $papel, $origem, $principal, \App\Core\Auth::id());
        } catch (\Throwable $e) {
            json_erro($e->getMessage());
        }
        auditar('vincular', 'imovel_produtor', $produtorId, "{$r['codCar']} <- produtor {$produtorId} ({$r['origem']})");
        json_ok($r);
    }
}
