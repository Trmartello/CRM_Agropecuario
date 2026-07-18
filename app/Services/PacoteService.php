<?php

namespace App\Services;

use App\Core\Database;

/**
 * Pacotes Agrícolas: composição por categoria, produtos obrigatórios,
 * validação técnica pela área plantada e cálculo de bonificação.
 */
class PacoteService
{
    /** Pacotes vigentes (dentro da vigência e ativos). */
    public static function vigentes(): array
    {
        return Database::todos(
            'SELECT pa.*, cu.nome AS cultura, s.nome AS safra
               FROM pacotes_agricolas pa
               LEFT JOIN culturas cu ON cu.id = pa.cultura_id
               LEFT JOIN safras s ON s.id = pa.safra_id
              WHERE pa.ativo = 1 AND CURDATE() BETWEEN pa.vigencia_inicio AND pa.vigencia_fim
              ORDER BY pa.nome'
        );
    }

    /** Estrutura completa do pacote (categorias + obrigatórios). */
    public static function estrutura(int $pacoteId): ?array
    {
        $pacote = Database::um(
            'SELECT pa.*, cu.nome AS cultura, s.nome AS safra
               FROM pacotes_agricolas pa
               LEFT JOIN culturas cu ON cu.id = pa.cultura_id
               LEFT JOIN safras s ON s.id = pa.safra_id
              WHERE pa.id = ?',
            [$pacoteId]
        );
        if (!$pacote) {
            return null;
        }
        $pacote['categorias'] = Database::todos(
            'SELECT pc.*, f.nome AS familia
               FROM pacote_categorias pc
               JOIN familias_produto f ON f.id = pc.familia_id
              WHERE pc.pacote_id = ?
              ORDER BY pc.obrigatoria DESC, f.nome',
            [$pacoteId]
        );
        $pacote['obrigatorios'] = Database::todos(
            'SELECT po.*, p.nome AS produto, p.unidade, p.preco_referencia, p.familia_id
               FROM pacote_obrigatorios po
               JOIN produtos p ON p.id = po.produto_id
              WHERE po.pacote_id = ?',
            [$pacoteId]
        );
        return $pacote;
    }

    /**
     * Avalia o pedido de pacote em tempo real.
     * $itens: [['produto_id'=>, 'quantidade'=>], ...], $areaHa: área atendida.
     *
     * Retorna o painel do pacote: % concluído, categorias atendidas/faltantes,
     * obrigatórios ausentes, validação técnica (dose × área × aplicações,
     * mín/máx), descontos por categoria, bonificação prevista e elegibilidade.
     */
    public static function avaliar(int $pacoteId, int $clienteId, float $areaHa, array $itens): array
    {
        $pacote = self::estrutura($pacoteId);
        if (!$pacote) {
            throw new \InvalidArgumentException('Pacote não encontrado.');
        }

        // Índices auxiliares
        $produtos = [];
        foreach ($itens as $item) {
            $pid = (int) $item['produto_id'];
            $produtos[$pid] = ($produtos[$pid] ?? 0) + (float) $item['quantidade'];
        }
        $familiasProduto = [];
        if ($produtos) {
            $marcadores = implode(',', array_fill(0, count($produtos), '?'));
            foreach (Database::todos(
                "SELECT id, familia_id, nome, preco_referencia FROM produtos WHERE id IN ({$marcadores})",
                array_keys($produtos)
            ) as $p) {
                $familiasProduto[(int) $p['id']] = $p;
            }
        }

        // Quantidade e valor por família
        $porFamilia = [];
        foreach ($produtos as $pid => $qtd) {
            $info = $familiasProduto[$pid] ?? null;
            if (!$info) {
                continue;
            }
            $fid = (int) $info['familia_id'];
            $porFamilia[$fid]['quantidade'] = ($porFamilia[$fid]['quantidade'] ?? 0) + $qtd;
            $porFamilia[$fid]['valor'] = ($porFamilia[$fid]['valor'] ?? 0) + $qtd * (float) $info['preco_referencia'];
        }

        // 1) Categorias: atendidas x faltantes
        $categorias = [];
        $obrigatoriasFaltantes = [];
        $descontoTotal = 0.0;
        $bonificacaoPct = 0.0;
        foreach ($pacote['categorias'] as $cat) {
            $fid = (int) $cat['familia_id'];
            $qtd = (float) ($porFamilia[$fid]['quantidade'] ?? 0);
            $valor = (float) ($porFamilia[$fid]['valor'] ?? 0);
            $atendida = $qtd > 0 && $qtd >= (float) $cat['qtd_minima'];
            if ($atendida) {
                $descontoTotal += $valor * ((float) $cat['desconto_pct'] / 100);
                $bonificacaoPct += (float) $cat['bonificacao_pct'];
            } elseif ((int) $cat['obrigatoria'] === 1) {
                $obrigatoriasFaltantes[] = $cat['familia'] . ((float) $cat['qtd_minima'] > 0 ? " (mín. {$cat['qtd_minima']})" : '');
            }
            $categorias[] = [
                'familia' => $cat['familia'],
                'obrigatoria' => (bool) $cat['obrigatoria'],
                'qtd_minima' => (float) $cat['qtd_minima'],
                'quantidade' => $qtd,
                'desconto_pct' => (float) $cat['desconto_pct'],
                'atendida' => $atendida,
            ];
        }

        // 2) Produtos específicos obrigatórios + validação técnica pela área
        $obrigatoriosFaltantes = [];
        $validacoes = [];
        foreach ($pacote['obrigatorios'] as $ob) {
            $pid = (int) $ob['produto_id'];
            $qtd = (float) ($produtos[$pid] ?? 0);
            $esperado = (float) $ob['dose_ha'] * $areaHa * (int) $ob['num_aplicacoes'];
            $minimo = max((float) $ob['qtd_minima'], $esperado > 0 ? $esperado * 0.9 : 0); // tolerância de 10%
            $maximo = (float) $ob['qtd_maxima'] > 0
                ? max((float) $ob['qtd_maxima'], $esperado * 1.1)
                : ($esperado > 0 ? $esperado * 1.5 : 0);

            $situacao = 'ok';
            $mensagem = null;
            if ($qtd <= 0) {
                $situacao = 'ausente';
                $mensagem = 'Produto obrigatório ausente';
                $obrigatoriosFaltantes[] = $ob['produto'];
            } elseif ($qtd < $minimo) {
                $situacao = 'abaixo';
                $mensagem = sprintf('Quantidade abaixo do recomendado para %s ha (esperado ≈ %s %s)', numero($areaHa, 0), numero($esperado, 1), $ob['unidade']);
            } elseif ($maximo > 0 && $qtd > $maximo) {
                $situacao = 'acima';
                $mensagem = sprintf('Quantidade acima do máximo técnico (esperado ≈ %s %s)', numero($esperado, 1), $ob['unidade']);
            }
            $validacoes[] = [
                'produto' => $ob['produto'],
                'unidade' => $ob['unidade'],
                'dose_ha' => (float) $ob['dose_ha'],
                'num_aplicacoes' => (int) $ob['num_aplicacoes'],
                'esperado' => round($esperado, 1),
                'quantidade' => $qtd,
                'situacao' => $situacao,
                'mensagem' => $mensagem,
            ];
        }

        // 3) Percentual concluído = categorias obrigatórias atendidas + obrigatórios presentes
        $exigencias = count(array_filter($pacote['categorias'], fn ($c) => (int) $c['obrigatoria'] === 1))
            + count($pacote['obrigatorios']);
        $atendidasObrig = count(array_filter($categorias, fn ($c) => $c['obrigatoria'] && $c['atendida']))
            + count(array_filter($validacoes, fn ($v) => $v['situacao'] !== 'ausente'));
        $percentual = $exigencias > 0 ? round($atendidasObrig / $exigencias * 100) : 100;

        // 4) Bonificação em grãos e elegibilidade
        $completo = !$obrigatoriasFaltantes && !$obrigatoriosFaltantes
            && !array_filter($validacoes, fn ($v) => in_array($v['situacao'], ['abaixo', 'acima'], true));
        $bonificacaoSacas = $completo ? round((float) $pacote['bonificacao_sacas_ha'] * $areaHa, 1) : 0.0;

        $cliente = Database::um('SELECT situacao FROM clientes WHERE id = ?', [$clienteId]);
        $inad = ComercialService::inadimplencia($clienteId);
        $elegivel = ($cliente['situacao'] ?? '') === 'Associado' && !$inad['inadimplente'];
        $motivoInelegivel = null;
        if (!$elegivel) {
            $motivoInelegivel = ($cliente['situacao'] ?? '') !== 'Associado'
                ? 'Produtor não associado — bonificação exclusiva para associados'
                : 'Produtor inadimplente — regularizar para receber a bonificação';
        }

        $valorBruto = array_sum(array_map(fn ($f) => (float) ($f['valor'] ?? 0), $porFamilia));

        return [
            'percentual' => $percentual,
            'completo' => $completo,
            'pode_concluir' => $completo, // ausência de obrigatórios impede a conclusão
            'categorias' => $categorias,
            'categorias_faltantes' => $obrigatoriasFaltantes,
            'obrigatorios_faltantes' => $obrigatoriosFaltantes,
            'validacoes' => $validacoes,
            'valor_bruto' => $valorBruto,
            'desconto_total' => round($descontoTotal, 2),
            'valor_liquido' => round($valorBruto - $descontoTotal, 2),
            'bonificacao_pct' => $bonificacaoPct,
            'bonificacao_sacas' => $bonificacaoSacas,
            'elegivel' => $elegivel,
            'motivo_inelegivel' => $motivoInelegivel,
        ];
    }
}
