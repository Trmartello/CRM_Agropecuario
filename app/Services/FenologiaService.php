<?php

namespace App\Services;

use App\Core\Database;

/**
 * Linha do tempo da cultura (Fase 6E): a data de plantio do talhão ancora a
 * fenologia — o sistema estima a fase atual pela idade da lavoura (DAP, dias
 * após o plantio), indica os manejos da fase e monta o checklist do técnico.
 */
class FenologiaService
{
    /**
     * Recomendações PADRÃO do sistema por fase ("cultura_id:codigo") — usadas
     * no seed e no botão "Adicionar recomendações padrão" das Configurações.
     * Formato: [titulo, familia_id|null, orientacao]
     */
    public const MANEJOS_PADRAO = [
        '1:VE' => [
            ['Avaliar estande e emergência', 1, 'Contar população de plantas por metro e comparar com a meta da cultivar; decidir replantio até V2.'],
            ['Controle de daninhas em pós-emergência inicial', 3, 'Aplicar com as daninhas pequenas (até 4 folhas); atenção a buva e azevém resistentes.'],
        ],
        '1:V2-V4' => [
            ['Herbicida pós-emergente', 3, 'Completar o controle antes do fechamento; verificar falhas de aplicação.'],
            ['Monitorar lagartas desfolhadoras', 5, 'Limite de desfolha na fase vegetativa: 30%.'],
        ],
        '1:V5+' => [
            ['Adubação foliar com micronutrientes', 6, 'Mn, Co e Mo conforme análise; aproveitar a entrada do fechamento.'],
            ['Monitorar doenças de início de ciclo', 4, 'Oídio e manchas iniciais; registrar pressão para posicionar o programa.'],
        ],
        '1:R1-R2' => [
            ['1ª aplicação de fungicida (ferrugem asiática)', 4, 'Posicionamento preventivo no florescimento; reaplicar em 14–21 dias.'],
            ['Monitorar percevejos — início', 5, 'Amostrar com pano de batida; registrar espécies e níveis.'],
        ],
        '1:R3-R4' => [
            ['2ª aplicação de fungicida', 4, 'Sequência do programa; rotacionar mecanismos de ação.'],
            ['Inseticida para percevejos', 5, 'Nível de controle: 2 percevejos/pano (1 em campos de semente).'],
        ],
        '1:R5' => [
            ['3ª aplicação de fungicida (se houver pressão)', 4, 'Avaliar pressão de ferrugem e clima antes de fechar o programa.'],
            ['Percevejo — fase crítica do enchimento', 5, 'Dano direto no grão: rigor no monitoramento semanal.'],
            ['Adubação foliar de enchimento', 6, 'Potássio/nitrogênio foliar conforme demanda.'],
        ],
        '1:R7-R8' => [
            ['Dessecação pré-colheita', 3, 'Aplicar em R7.3 quando indicado; respeitar o período de carência.'],
            ['Planejar colheita: umidade e perdas', null, 'Colher entre 13–15% de umidade; regular a plataforma para perdas < 1 sc/ha.'],
        ],
        '2:VE' => [
            ['Avaliar estande e emergência', 1, 'População final define a produtividade; avaliar falhas e replantio.'],
        ],
        '2:V3-V5' => [
            ['Adubação nitrogenada de cobertura (1ª)', 2, 'Aplicar N em V3–V4 — estádio que define as fileiras da espiga.'],
            ['Herbicida pós-emergente', 3, 'Milho é sensível à matocompetição inicial; controlar cedo.'],
            ['Monitorar cigarrinha-do-milho', 5, 'Vetor dos enfezamentos: controle no início do ciclo.'],
        ],
        '2:V6-V8' => [
            ['2ª cobertura nitrogenada', 2, 'Completar o N até V8 conforme expectativa de produtividade.'],
            ['Lagarta-do-cartucho', 5, 'Controlar com dano no cartucho acima de 20% das plantas.'],
        ],
        '2:V9-VT' => [
            ['1ª aplicação de fungicida', 4, 'Pré-pendoamento: proteger folha bandeira e colmo.'],
            ['Adubação foliar', 6, 'Complementar micronutrientes no pré-pendoamento.'],
        ],
        '2:R1' => [
            ['2ª aplicação de fungicida (doenças foliares)', 4, 'Proteger a polinização — fase mais sensível a estresse.'],
        ],
        '2:R2-R4' => [
            ['Monitorar percevejo barriga-verde e doenças de colmo', 5, 'Avaliar colmos e grãos; risco de tombamento.'],
        ],
        '2:R5-R6' => [
            ['Planejar colheita: umidade e perdas', null, 'Acompanhar a dry-down; colher na janela para evitar grãos ardidos.'],
        ],
        '3:F1-3' => [
            ['Avaliar estande (plantas/m²)', 1, 'Contar plantas/m² e comparar com a meta da cultivar; falhas comprometem o rendimento.'],
            ['Herbicida pós-emergente (azevém/nabo)', 3, 'Controlar cedo — a matocompetição no afilhamento reduz perfilhos.'],
        ],
        '3:F4-5' => [
            ['1ª adubação nitrogenada de cobertura', 2, 'N no afilhamento define espigas por planta.'],
        ],
        '3:F6-10' => [
            ['2ª cobertura de nitrogênio', 2, 'Completar o N no início do alongamento conforme expectativa de produtividade.'],
            ['1ª aplicação de fungicida (manchas foliares)', 4, 'Proteger a folha bandeira — principal fonte de enchimento do grão.'],
            ['Monitorar pulgões', 5, 'Vetores de viroses (nanismo-amarelo); controlar pelo nível de dano.'],
        ],
        '3:F10.1-10.5' => [
            ['Fungicida para giberela', 4, 'Aplicar no espigamento/floração, especialmente com molhamento prolongado — janela crítica.'],
        ],
        '3:F11.1-11.2' => [
            ['Monitorar percevejos e lagartas da espiga', 5, 'Dano direto ao grão no enchimento; amostrar semanalmente.'],
        ],
        '3:F11.3-11.4' => [
            ['Planejar colheita: umidade e germinação na espiga', null, 'Colher na janela para preservar PH e evitar germinação na espiga com chuva.'],
        ],
    ];

    /** Plantio ativo (não encerrado) de um talhão, se houver. */
    public static function plantioAtivo(int $talhaoId): ?array
    {
        $p = Database::um(
            'SELECT p.*, cu.nome AS cultura FROM plantios p
               JOIN culturas cu ON cu.id = p.cultura_id
              WHERE p.talhao_id = ? AND p.encerrado = 0
              ORDER BY p.data_plantio DESC LIMIT 1',
            [$talhaoId]
        );
        return $p ?: null;
    }

    /** Plantios ativos de todos os talhões de um cliente: talhao_id => plantio. */
    public static function plantiosAtivosPorCliente(int $clienteId): array
    {
        $mapa = [];
        foreach (Database::todos(
            'SELECT p.*, cu.nome AS cultura FROM plantios p
               JOIN culturas cu ON cu.id = p.cultura_id
               JOIN talhoes t ON t.id = p.talhao_id
               JOIN propriedades pr ON pr.id = t.propriedade_id
              WHERE pr.cliente_id = ? AND p.encerrado = 0
              ORDER BY p.data_plantio DESC',
            [$clienteId]
        ) as $p) {
            // ORDER BY DESC + primeiro vence: fica o plantio mais recente do talhão
            $mapa[(int) $p['talhao_id']] ??= $p;
        }
        return $mapa;
    }

    /**
     * Catálogo de referência (estágios + manejos embutidos) das culturas
     * informadas — vai para o modal de visita e para o snapshot offline.
     * Formato: cultura_id => [ {estagio..., manejos: [...]}, ... ]
     */
    public static function catalogo(?array $culturaIds = null): array
    {
        $where = '';
        $params = [];
        if ($culturaIds !== null) {
            $ids = array_values(array_unique(array_map('intval', $culturaIds)));
            if (!$ids) {
                return [];
            }
            $where = 'WHERE fe.cultura_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params = $ids;
        }
        // Colunas explícitas: a imagem personalizada (MEDIUMBLOB) NÃO entra no
        // catálogo JSON (modal/snapshot) — vai só a flag, servida por arquivo/estagio
        $estagios = Database::todos(
            "SELECT fe.id, fe.cultura_id, fe.codigo, fe.nome, fe.dias_inicio, fe.dias_fim,
                    fe.descricao, fe.ordem, fe.grupo, fe.caracteristicas,
                    (fe.imagem IS NOT NULL) AS tem_imagem
               FROM fenologia_estagios fe {$where} ORDER BY fe.cultura_id, fe.ordem",
            $params
        );
        if (!$estagios) {
            return [];
        }

        $porEstagio = [];
        $idsEstagios = array_map(fn ($e) => (int) $e['id'], $estagios);
        $marcadores = implode(',', array_fill(0, count($idsEstagios), '?'));
        foreach (Database::todos(
            "SELECT m.id, m.estagio_id, m.titulo, m.familia_id, f.nome AS familia, m.orientacao, m.eh_checklist
               FROM manejos_fase m LEFT JOIN familias_produto f ON f.id = m.familia_id
              WHERE m.estagio_id IN ({$marcadores})
              ORDER BY m.id",
            $idsEstagios
        ) as $m) {
            $porEstagio[(int) $m['estagio_id']][] = $m;
        }

        $catalogo = [];
        foreach ($estagios as $e) {
            $e['manejos'] = $porEstagio[(int) $e['id']] ?? [];
            $catalogo[(int) $e['cultura_id']][] = $e;
        }
        return $catalogo;
    }

    /** Estágio estimado para uma idade de lavoura (DAP), dentro de um catálogo de cultura. */
    public static function estagioPorDap(array $estagiosCultura, int $dap): ?array
    {
        $ultimo = null;
        foreach ($estagiosCultura as $e) {
            if ($dap >= (int) $e['dias_inicio'] && $dap <= (int) $e['dias_fim']) {
                return $e;
            }
            $ultimo = $e;
        }
        // Além do fim do ciclo: permanece no último estágio (colheita pendente)
        return ($ultimo && $dap > (int) $ultimo['dias_fim']) ? $ultimo : null;
    }

    /** Registra o plantio de um talhão (um ativo por vez). */
    public static function salvarPlantio(int $talhaoId, int $culturaId, string $dataPlantio, ?string $cultivar): int
    {
        if ($talhaoId <= 0 || $culturaId <= 0) {
            throw new \InvalidArgumentException('Informe o talhão e a cultura do plantio.');
        }
        $data = date_create($dataPlantio);
        if (!$data || $dataPlantio === '') {
            throw new \InvalidArgumentException('Informe a data de plantio.');
        }
        if ($data > date_create('today')) {
            throw new \InvalidArgumentException('A data de plantio não pode ser futura.');
        }
        if (self::plantioAtivo($talhaoId)) {
            throw new \InvalidArgumentException('Este talhão já tem um plantio em andamento — encerre-o (colheita) antes de registrar outro.');
        }
        $safra = ComercialService::safraAtual();
        Database::executar(
            'INSERT INTO plantios (talhao_id, cultura_id, safra_id, data_plantio, cultivar) VALUES (?,?,?,?,?)',
            [$talhaoId, $culturaId, $safra ? (int) $safra['id'] : null, $data->format('Y-m-d'), trim((string) $cultivar) ?: null]
        );
        // Mantém a cultura do talhão alinhada ao plantio real
        Database::executar('UPDATE talhoes SET cultura_id = ? WHERE id = ?', [$culturaId, $talhaoId]);
        return Database::ultimoId();
    }

    /** Encerra o plantio registrando a colheita (produtividade em sc/ha). */
    public static function encerrarPlantio(int $plantioId, ?float $produtividade, ?string $colhidoEm): void
    {
        $plantio = Database::um('SELECT * FROM plantios WHERE id = ? AND encerrado = 0', [$plantioId]);
        if (!$plantio) {
            throw new \InvalidArgumentException('Plantio não encontrado ou já encerrado.');
        }
        $data = $colhidoEm ? date_create($colhidoEm) : date_create('today');
        if (!$data || $data->format('Y-m-d') < $plantio['data_plantio']) {
            throw new \InvalidArgumentException('Data de colheita inválida (anterior ao plantio).');
        }
        Database::executar(
            'UPDATE plantios SET encerrado = 1, colhido_em = ?, produtividade = ? WHERE id = ?',
            [$data->format('Y-m-d'), $produtividade > 0 ? $produtividade : null, $plantioId]
        );
    }

    /**
     * Gatilho comercial da lavoura: plantio ativo em fase com manejo ligado a
     * uma família SEM compra na safra atual → lista para virar oportunidade.
     */
    public static function gatilhosLavoura(int $clienteId, int $safraId): array
    {
        $plantios = Database::todos(
            'SELECT p.id, p.talhao_id, p.cultura_id, p.data_plantio, cu.nome AS cultura, t.nome AS talhao, t.area_ha
               FROM plantios p
               JOIN culturas cu ON cu.id = p.cultura_id
               JOIN talhoes t ON t.id = p.talhao_id
               JOIN propriedades pr ON pr.id = t.propriedade_id
              WHERE pr.cliente_id = ? AND p.encerrado = 0',
            [$clienteId]
        );
        if (!$plantios) {
            return [];
        }
        $catalogo = self::catalogo(array_map(fn ($p) => (int) $p['cultura_id'], $plantios));

        $gatilhos = [];
        foreach ($plantios as $p) {
            $dap = (int) floor((time() - strtotime((string) $p['data_plantio'])) / 86400);
            $estagio = self::estagioPorDap($catalogo[(int) $p['cultura_id']] ?? [], $dap);
            if (!$estagio) {
                continue;
            }
            foreach ($estagio['manejos'] as $m) {
                if (empty($m['familia_id'])) {
                    continue;
                }
                $comprou = Database::valor(
                    'SELECT 1 FROM compras co JOIN produtos pr ON pr.id = co.produto_id
                      WHERE co.cliente_id = ? AND co.safra_id = ? AND pr.familia_id = ? LIMIT 1',
                    [$clienteId, $safraId, (int) $m['familia_id']]
                );
                if ($comprou) {
                    continue;
                }
                $custoHa = (float) (Database::valor(
                    'SELECT custo_por_ha FROM culturas_referencia WHERE cultura_id = ? AND familia_id = ?',
                    [(int) $p['cultura_id'], (int) $m['familia_id']]
                ) ?: 0);
                $gatilhos[] = [
                    'familia_id' => (int) $m['familia_id'],
                    'familia' => $m['familia'],
                    'titulo' => "{$m['titulo']} — {$p['cultura']} {$estagio['codigo']} ({$p['talhao']})",
                    'valor_estimado' => $custoHa * (float) $p['area_ha'],
                ];
            }
        }
        return $gatilhos;
    }
}
