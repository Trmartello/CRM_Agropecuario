<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Permissoes;
use App\Services\ComercialService;
use App\Services\PotencialService;

class ClientesController
{
    public function index(): void
    {
        Permissoes::exigirInterno();
        [$filtro, $params] = Permissoes::filtroCarteira();

        $busca = trim($_GET['busca'] ?? '');
        $where = $filtro;
        if ($busca !== '') {
            $where .= ' AND (c.nome LIKE ? OR c.municipio LIKE ? OR c.cpf_cnpj LIKE ?)';
            $like = "%{$busca}%";
            array_push($params, $like, $like, $like);
        }

        $clientes = Database::todos(
            "SELECT c.*, f.nome AS filial,
                    (SELECT MAX(v.data_visita) FROM visitas v WHERE v.cliente_id = c.id) AS ultima_visita
               FROM clientes c
               LEFT JOIN filiais f ON f.id = c.filial_id
              WHERE c.ativo = 1 AND {$where}
              ORDER BY c.nome",
            $params
        );

        $filiais = Database::todos('SELECT * FROM filiais ORDER BY nome');
        $responsaveis = Database::todos(
            "SELECT id, nome FROM usuarios WHERE perfil IN ('Consultor Técnico','Vendedor') AND ativo = 1 ORDER BY nome"
        );
        $culturas = Database::todos('SELECT * FROM culturas ORDER BY nome');

        render('clientes', compact('clientes', 'filiais', 'responsaveis', 'culturas', 'busca') + ['titulo' => 'Clientes']);
    }

    /** Dados de um cliente para preencher o modal de edição (AJAX). */
    public function obter(): void
    {
        Permissoes::exigirInterno();
        $cliente = $this->clienteDaCarteira((int) ($_GET['id'] ?? 0));
        json_ok(['cliente' => $cliente]);
    }

    /** Cria/atualiza cliente via modal (AJAX). */
    public function salvar(): void
    {
        Permissoes::exigir(['Administrador', 'Gestor Comercial', 'Gestor Técnico', 'Consultor Técnico', 'Vendedor']);
        $id = (int) ($_POST['id'] ?? 0);
        $nome = trim($_POST['nome'] ?? '');
        if ($nome === '') {
            json_erro('Informe o nome do produtor.');
        }

        $dados = [
            $nome,
            $_POST['situacao'] ?? 'Associado',
            trim($_POST['cpf_cnpj'] ?? '') ?: null,
            trim($_POST['telefone'] ?? '') ?: null,
            trim($_POST['email'] ?? '') ?: null,
            trim($_POST['endereco'] ?? '') ?: null,
            trim($_POST['municipio'] ?? '') ?: null,
            strtoupper(trim($_POST['estado'] ?? 'SC')) ?: 'SC',
            (int) ($_POST['filial_id'] ?? 0) ?: null,
            $_POST['latitude'] !== '' ? (float) $_POST['latitude'] : null,
            $_POST['longitude'] !== '' ? (float) $_POST['longitude'] : null,
            (int) ($_POST['responsavel_id'] ?? 0) ?: (Permissoes::ehGestor() ? null : Auth::id()),
            $_POST['nivel_tecnologico'] ?? 'Médio',
            (float) str_replace(',', '.', $_POST['volume_compra_anual'] ?? 0),
            (float) str_replace(',', '.', $_POST['potencial_venda'] ?? 0),
            (float) str_replace(',', '.', $_POST['limite_credito'] ?? 0),
        ];

        if ($id > 0) {
            $this->clienteDaCarteira($id);
            Database::executar(
                'UPDATE clientes SET nome=?, situacao=?, cpf_cnpj=?, telefone=?, email=?, endereco=?,
                        municipio=?, estado=?, filial_id=?, latitude=?, longitude=?, responsavel_id=?,
                        nivel_tecnologico=?, volume_compra_anual=?, potencial_venda=?, limite_credito=?
                  WHERE id=?',
                array_merge($dados, [$id])
            );
        } else {
            Database::executar(
                'INSERT INTO clientes (nome, situacao, cpf_cnpj, telefone, email, endereco, municipio, estado,
                        filial_id, latitude, longitude, responsavel_id, nivel_tecnologico,
                        volume_compra_anual, potencial_venda, limite_credito)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                $dados
            );
            $id = Database::ultimoId();
        }
        $this->auditar($id > 0 ? 'salvar' : 'criar', 'clientes', $id);
        json_ok(['id' => $id]);
    }

    /** Ficha completa do cliente (painel lateral, AJAX → HTML parcial). */
    public function ficha(): void
    {
        Permissoes::exigirInterno();
        $id = (int) ($_GET['id'] ?? 0);
        $cliente = $this->clienteDaCarteira($id);

        $propriedades = Database::todos(
            'SELECT p.*,
                    (SELECT COUNT(*) FROM talhoes t WHERE t.propriedade_id = p.id) AS qtd_talhoes
               FROM propriedades p WHERE p.cliente_id = ? ORDER BY p.nome',
            [$id]
        );
        foreach ($propriedades as &$p) {
            $p['talhoes'] = Database::todos(
                'SELECT t.*, cu.nome AS cultura FROM talhoes t
                  LEFT JOIN culturas cu ON cu.id = t.cultura_id
                 WHERE t.propriedade_id = ? ORDER BY t.nome',
                [(int) $p['id']]
            );
        }
        unset($p);

        $contatos = Database::todos('SELECT * FROM cliente_contatos WHERE cliente_id = ? ORDER BY nome', [$id]);
        $painel = ComercialService::painelCliente($id);
        $historicoCompras = ComercialService::historicoCompras($id);
        $potencial = PotencialService::porCliente($id);
        $demandaPlano = PotencialService::demandaPlanoSafra($id);
        $planos = Database::todos(
            'SELECT ps.*, cu.nome AS cultura, pr.nome AS propriedade
               FROM planos_safra ps
               JOIN culturas cu ON cu.id = ps.cultura_id
               LEFT JOIN propriedades pr ON pr.id = ps.propriedade_id
              WHERE ps.cliente_id = ? ORDER BY cu.nome',
            [$id]
        );
        $historico = Database::todos(
            'SELECT v.*, u.nome AS tecnico, cu.nome AS cultura, pr.nome AS propriedade, t.nome AS talhao,
                    (SELECT COUNT(*) FROM visita_fotos vf WHERE vf.visita_id = v.id) AS qtd_fotos
               FROM visitas v
               JOIN usuarios u ON u.id = v.usuario_id
               LEFT JOIN culturas cu ON cu.id = v.cultura_id
               LEFT JOIN propriedades pr ON pr.id = v.propriedade_id
               LEFT JOIN talhoes t ON t.id = v.talhao_id
              WHERE v.cliente_id = ?
              ORDER BY v.data_visita DESC, v.id DESC',
            [$id]
        );
        foreach ($historico as &$h) {
            $h['fotos'] = Database::todos('SELECT * FROM visita_fotos WHERE visita_id = ?', [(int) $h['id']]);
        }
        unset($h);

        $culturas = Database::todos('SELECT * FROM culturas ORDER BY nome');

        render_parcial('partials/cliente_ficha', compact(
            'cliente', 'propriedades', 'contatos', 'painel', 'potencial',
            'demandaPlano', 'planos', 'historico', 'historicoCompras', 'culturas'
        ));
    }

    /** Salva propriedade (modal AJAX). */
    public function salvarPropriedade(): void
    {
        Permissoes::exigirInterno();
        $clienteId = (int) ($_POST['cliente_id'] ?? 0);
        $this->clienteDaCarteira($clienteId);
        $id = (int) ($_POST['id'] ?? 0);
        $nome = trim($_POST['nome'] ?? '');
        if ($nome === '') {
            json_erro('Informe o nome da propriedade.');
        }
        $dados = [
            $nome,
            (float) str_replace(',', '.', $_POST['area_ha'] ?? 0),
            trim($_POST['municipio'] ?? '') ?: null,
        ];
        if ($id > 0) {
            Database::executar(
                'UPDATE propriedades SET nome=?, area_ha=?, municipio=? WHERE id=? AND cliente_id=?',
                array_merge($dados, [$id, $clienteId])
            );
        } else {
            Database::executar(
                'INSERT INTO propriedades (nome, area_ha, municipio, cliente_id) VALUES (?,?,?,?)',
                array_merge($dados, [$clienteId])
            );
            $id = Database::ultimoId();
        }
        json_ok(['id' => $id]);
    }

    /** Salva talhão (modal AJAX). */
    public function salvarTalhao(): void
    {
        Permissoes::exigirInterno();
        $propriedadeId = (int) ($_POST['propriedade_id'] ?? 0);
        $clienteId = (int) Database::valor('SELECT cliente_id FROM propriedades WHERE id = ?', [$propriedadeId]);
        $this->clienteDaCarteira($clienteId);
        $id = (int) ($_POST['id'] ?? 0);
        $nome = trim($_POST['nome'] ?? '');
        if ($nome === '') {
            json_erro('Informe o nome do talhão.');
        }
        $dados = [
            $nome,
            (float) str_replace(',', '.', $_POST['area_ha'] ?? 0),
            (int) ($_POST['cultura_id'] ?? 0) ?: null,
        ];
        if ($id > 0) {
            Database::executar(
                'UPDATE talhoes SET nome=?, area_ha=?, cultura_id=? WHERE id=? AND propriedade_id=?',
                array_merge($dados, [$id, $propriedadeId])
            );
        } else {
            Database::executar(
                'INSERT INTO talhoes (nome, area_ha, cultura_id, propriedade_id) VALUES (?,?,?,?)',
                array_merge($dados, [$propriedadeId])
            );
            $id = Database::ultimoId();
        }
        json_ok(['id' => $id]);
    }

    /** Salva plano de safra (intenção de plantio) via modal AJAX. */
    public function salvarPlanoSafra(): void
    {
        Permissoes::exigirInterno();
        $clienteId = (int) ($_POST['cliente_id'] ?? 0);
        $this->clienteDaCarteira($clienteId);
        $safra = ComercialService::safraAtual();
        if (!$safra) {
            json_erro('Nenhuma safra atual cadastrada.');
        }
        $culturaId = (int) ($_POST['cultura_id'] ?? 0);
        $area = (float) str_replace(',', '.', $_POST['area_ha'] ?? 0);
        if (!$culturaId || $area <= 0) {
            json_erro('Informe a cultura e a área plantada.');
        }
        Database::executar(
            'INSERT INTO planos_safra (cliente_id, propriedade_id, cultura_id, safra_id, area_ha) VALUES (?,?,?,?,?)',
            [$clienteId, (int) ($_POST['propriedade_id'] ?? 0) ?: null, $culturaId, (int) $safra['id'], $area]
        );
        json_ok(['id' => Database::ultimoId()]);
    }

    /** Garante que o cliente pertence à carteira do usuário (ou é gestor). */
    private function clienteDaCarteira(int $id): array
    {
        [$filtro, $params] = Permissoes::filtroCarteira();
        $cliente = Database::um(
            "SELECT c.* FROM clientes c WHERE c.id = ? AND {$filtro}",
            array_merge([$id], $params)
        );
        if (!$cliente) {
            json_erro('Cliente não encontrado na sua carteira.', 404);
        }
        return $cliente;
    }

    private function auditar(string $acao, string $tabela, int $registroId): void
    {
        Database::executar(
            'INSERT INTO auditoria (usuario_id, acao, tabela, registro_id) VALUES (?,?,?,?)',
            [Auth::id(), $acao, $tabela, $registroId]
        );
    }
}
