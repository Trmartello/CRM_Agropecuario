<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\Permissoes;
use App\Services\PriorizacaoService;

/**
 * Snapshot da carteira para leitura offline (Offline Ampliado — O2).
 * Devolve, num único JSON, os dados de que o técnico precisa no campo para
 * cadastrar uma visita sem sinal: produtores da carteira, propriedades/talhões
 * por produtor, culturas e modelos de recomendação. O cliente guarda em
 * IndexedDB e usa como fallback quando offline.
 */
class SyncController
{
    public function carteira(): void
    {
        Permissoes::exigirInterno();
        [$filtro, $params] = Permissoes::filtroCarteira();

        // Produtores já com os campos de priorização (score/dias/churn/cadastro),
        // para as telas Produtores/Priorização/Organizador funcionarem offline.
        $prioridades = PriorizacaoService::listaPriorizada($filtro, $params);
        $extra = Database::todos(
            "SELECT c.id, c.telefone, c.linha, c.latitude, c.longitude, c.situacao, c.prospecto,
                    (SELECT MAX(v.data_visita) FROM visitas v WHERE v.cliente_id = c.id) AS ultima_visita
               FROM clientes c WHERE c.ativo = 1 AND {$filtro}",
            $params
        );
        $mapaExtra = [];
        foreach ($extra as $x) {
            $mapaExtra[(int) $x['id']] = $x;
        }
        $produtores = [];
        foreach ($prioridades as $p) {
            $x = $mapaExtra[(int) $p['id']] ?? [];
            $produtores[] = array_merge($p, [
                'telefone' => $x['telefone'] ?? null,
                'linha' => $x['linha'] ?? null,
                'latitude' => $x['latitude'] ?? null,
                'longitude' => $x['longitude'] ?? null,
                'situacao' => $x['situacao'] ?? null,
                'prospecto' => isset($x['prospecto']) ? (int) $x['prospecto'] : 0,
                'ultima_visita' => $x['ultima_visita'] ?? null,
            ]);
        }

        $propriedades = Database::todos(
            "SELECT p.id, p.nome, p.cliente_id
               FROM propriedades p
               JOIN clientes c ON c.id = p.cliente_id
              WHERE c.ativo = 1 AND {$filtro}
              ORDER BY p.nome",
            $params
        );

        $talhoes = Database::todos(
            "SELECT t.id, t.nome, t.propriedade_id, t.cultura_id, cu.nome AS cultura, p.cliente_id
               FROM talhoes t
               JOIN propriedades p ON p.id = t.propriedade_id
               JOIN clientes c ON c.id = p.cliente_id
               LEFT JOIN culturas cu ON cu.id = t.cultura_id
              WHERE c.ativo = 1 AND {$filtro}
              ORDER BY t.nome",
            $params
        );

        // Agrupa propriedades/talhões por produtor (mesmo formato de visitas/apoio-modal)
        $apoio = [];
        foreach ($produtores as $p) {
            $apoio[(int) $p['id']] = ['propriedades' => [], 'talhoes' => []];
        }
        foreach ($propriedades as $pr) {
            $cid = (int) $pr['cliente_id'];
            $apoio[$cid]['propriedades'][] = ['id' => (int) $pr['id'], 'nome' => $pr['nome']];
        }
        foreach ($talhoes as $t) {
            $cid = (int) $t['cliente_id'];
            if (!isset($apoio[$cid])) {
                continue;
            }
            $apoio[$cid]['talhoes'][] = [
                'id' => (int) $t['id'],
                'nome' => $t['nome'],
                'propriedade_id' => (int) $t['propriedade_id'],
                'cultura_id' => $t['cultura_id'] !== null ? (int) $t['cultura_id'] : null,
                'cultura' => $t['cultura'],
            ];
        }

        $culturas = Database::todos('SELECT id, nome FROM culturas ORDER BY nome');
        $modelos = Database::todos(
            'SELECT id, cultura_id, categoria, titulo, texto_padrao
               FROM modelos_recomendacao WHERE ativo = 1 ORDER BY categoria, titulo'
        );

        json_ok([
            'atualizado_em' => date('c'),
            'produtores' => $produtores,
            'apoio' => $apoio,
            'culturas' => $culturas,
            'modelos' => $modelos,
        ]);
    }
}
