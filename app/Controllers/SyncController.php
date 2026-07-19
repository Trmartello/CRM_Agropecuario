<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\Permissoes;

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

        $produtores = Database::todos(
            "SELECT c.id, c.nome, c.telefone, c.municipio, c.linha, c.latitude, c.longitude
               FROM clientes c
              WHERE c.ativo = 1 AND {$filtro}
              ORDER BY c.nome",
            $params
        );

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
