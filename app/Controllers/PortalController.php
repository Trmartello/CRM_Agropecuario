<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Permissoes;
use App\Services\ComercialService;
use App\Services\CustoLavouraService;

/** Portal do Produtor (Módulo 7): o produtor vê apenas os próprios dados. */
class PortalController
{
    public function index(): void
    {
        Auth::exigirLogin();
        if (Auth::perfil() !== 'Produtor') {
            // Perfis internos usam o dashboard normal
            header('Location: ' . url('dashboard'));
            exit;
        }
        $clienteId = (int) (Auth::usuario()['cliente_id'] ?? 0);
        if (!$clienteId) {
            $clienteId = (int) Database::valor('SELECT cliente_id FROM usuarios WHERE id = ?', [Auth::id()]);
        }
        if (!$clienteId) {
            render('portal', ['semVinculo' => true, 'titulo' => 'Meu Portal']);
            return;
        }

        $cliente = Database::um('SELECT * FROM clientes c WHERE c.id = ?', [$clienteId]);
        $painel = ComercialService::painelCliente($clienteId);
        $historicoCompras = ComercialService::historicoCompras($clienteId);

        $visitas = Database::todos(
            "SELECT v.*, u.nome AS tecnico, cu.nome AS cultura, pr.nome AS propriedade
               FROM visitas v
               JOIN usuarios u ON u.id = v.usuario_id
               LEFT JOIN culturas cu ON cu.id = v.cultura_id
               LEFT JOIN propriedades pr ON pr.id = v.propriedade_id
              WHERE v.cliente_id = ? ORDER BY v.data_visita DESC LIMIT 20",
            [$clienteId]
        );
        // Fotos das visitas listadas (uma consulta só)
        $fotosPorVisita = [];
        if ($visitas) {
            $ids = implode(',', array_map(fn ($v) => (int) $v['id'], $visitas));
            foreach (Database::todos("SELECT visita_id, arquivo, legenda FROM visita_fotos WHERE visita_id IN ({$ids}) ORDER BY id") as $f) {
                $fotosPorVisita[(int) $f['visita_id']][] = ['arquivo' => $f['arquivo'], 'legenda' => $f['legenda']];
            }
        }
        $entregas = Database::todos(
            'SELECT ef.*, p.nome AS produto, p.unidade,
                    (ef.quantidade_contratada - ef.quantidade_retirada) AS quantidade_pendente
               FROM entregas_futuras ef JOIN produtos p ON p.id = ef.produto_id
              WHERE ef.cliente_id = ? ORDER BY ef.previsao_entrega',
            [$clienteId]
        );
        $titulos = Database::todos(
            "SELECT * FROM titulos_financeiros WHERE cliente_id = ? ORDER BY vencimento DESC LIMIT 30",
            [$clienteId]
        );
        $documentos = Database::todos(
            'SELECT id, tipo, nome, arquivo, criado_em FROM documentos WHERE cliente_id = ? ORDER BY criado_em DESC',
            [$clienteId]
        );
        $pedidos = Database::todos(
            "SELECT id, tipo, status, valor_total, criado_em FROM pedidos WHERE cliente_id = ? ORDER BY criado_em DESC LIMIT 20",
            [$clienteId]
        );

        render('portal', compact('cliente', 'painel', 'historicoCompras', 'visitas', 'titulos', 'documentos', 'pedidos', 'fotosPorVisita', 'entregas')
            + ['semVinculo' => false, 'titulo' => 'Meu Portal']);
    }

    /* ============ Custo da Lavoura (spec custo-lavoura §9 — PR 4) ============
     * API do Portal, autenticada como Produtor. NÃO expor na API interna: o
     * guard de perfil recusa qualquer perfil que não seja 'Produtor', e o
     * CustoLavouraService acessa as tabelas sob firewall (invariante 5) pela
     * conexão de custo — a credencial comercial não as enxerga.
     */

    /** Resolve o cliente_id do Produtor logado NO BANCO (nunca do path/POST). */
    private function produtorId(): int
    {
        Permissoes::exigir(['Produtor']);
        $clienteId = (int) Database::valor('SELECT cliente_id FROM usuarios WHERE id = ?', [Auth::id()]);
        if ($clienteId <= 0) {
            json_erro('Seu usuário ainda não está vinculado a um cadastro de produtor.', 403);
        }
        return $clienteId;
    }

    /** GET portal/lavouras?safra= — lavouras do produtor + catálogo de itens. */
    public function lavouras(): void
    {
        $clienteId = $this->produtorId();
        json_ok([
            'lavouras' => CustoLavouraService::listar($clienteId, trim((string) ($_GET['safra'] ?? ''))),
            'catalogo' => CustoLavouraService::catalogo(),
        ]);
    }

    /** POST portal/lavoura-criar — cria a lavoura e aplica o preset da cultura. */
    public function lavouraCriar(): void
    {
        $clienteId = $this->produtorId();
        try {
            $id = CustoLavouraService::criar($clienteId, $_POST);
        } catch (\RuntimeException $e) {
            json_erro($e->getMessage());
        }
        auditar('criar', 'lavoura_safra', $id, 'portal: lavoura do próprio produtor');
        json_ok(['id' => $id, 'detalhe' => CustoLavouraService::detalhe($clienteId, $id)]);
    }

    /** GET portal/lavoura?id= — ficha: cadastro + custos + cálculo + cenário. */
    public function lavoura(): void
    {
        $clienteId = $this->produtorId();
        $id = (int) ($_GET['id'] ?? 0);
        $d = CustoLavouraService::detalhe($clienteId, $id);
        if ($d === null) {
            json_erro('Lavoura não encontrada.', 404);
        }
        // §7.5: toda leitura das tabelas sob firewall é auditada (quem, quando, motivo)
        auditar('ler', 'lavoura_custo', $id, 'portal: leitura pelo próprio produtor');
        json_ok($d);
    }

    /**
     * POST portal/lavoura-cenario — salva um cenário de travamento. O servidor
     * RECALCULA com o motor (§9); divergência com o cálculo do cliente = 409.
     */
    public function lavouraCenario(): void
    {
        $clienteId = $this->produtorId();
        $id = (int) ($_POST['id'] ?? 0);
        try {
            $cen = \App\Services\CustoLavouraService::salvarCenario($clienteId, $id, $_POST);
        } catch (\App\Services\DivergenciaCalculoException $e) {
            auditar('divergencia', 'lavoura_cenario', $id, 'portal: cálculo cliente≠servidor (409)');
            json_erro($e->getMessage(), 409);
        } catch (\RuntimeException $e) {
            json_erro($e->getMessage());
        }
        auditar('salvar', 'lavoura_cenario', (int) $cen['id'], 'portal: cenário do próprio produtor');
        json_ok(['cenario' => $cen]);
    }

    /* ==== Ingestão de NF (spec nf-ingestao §6/§9 — PR 3: opt-in/revogação) ==== */

    /** GET portal/fiscal-autorizacao — status atual da autorização do produtor. */
    public function fiscalAutorizacao(): void
    {
        $clienteId = $this->produtorId();
        json_ok([
            'autorizacao' => \App\Services\NotaFiscalService::autorizacao($clienteId),
            'provedor' => \App\Services\NotaFiscalService::adapter()->nome(),
        ]);
    }

    /** POST portal/fiscal-autorizar — opt-in do produtor (termo aceito na tela). */
    public function fiscalAutorizar(): void
    {
        $clienteId = $this->produtorId();
        if ((string) ($_POST['ciente'] ?? '') !== '1') {
            json_erro('Confirme a leitura do termo de autorização para continuar.');
        }
        try {
            $aut = \App\Services\NotaFiscalService::autorizar($clienteId);
        } catch (\RuntimeException $e) {
            json_erro($e->getMessage());
        }
        auditar('autorizar', 'produtor_autorizacao_fiscal', $clienteId,
            'portal: opt-in da captura de NF-e (termo aceito, provedor ' . ($aut['provedor'] ?? '?') . ')');
        json_ok(['autorizacao' => $aut]);
    }

    /**
     * POST portal/fiscal-pull — dispara o pull (1x/24h automático; forcar=1 no
     * botão "Buscar minhas notas agora"). Libera o lock da sessão: com provedor
     * SaaS real a busca pode demorar e não pode travar a navegação.
     */
    public function fiscalPull(): void
    {
        $clienteId = $this->produtorId();
        liberar_sessao();
        $r = \App\Services\NotaFiscalService::pullSeNecessario($clienteId, (string) ($_POST['forcar'] ?? '') === '1');
        if ($r === null) {
            json_erro('A captura não está autorizada.', 409);
        }
        if ($r['executado']) {
            auditar('capturar', 'nfe_documento', $clienteId,
                'portal: pull — ' . ($r['captura']['novos'] ?? 0) . ' nota(s) nova(s)');
        }
        json_ok($r);
    }

    /** POST portal/fiscal-revogar — revogação (para os pulls imediatamente). */
    public function fiscalRevogar(): void
    {
        $clienteId = $this->produtorId();
        try {
            $aut = \App\Services\NotaFiscalService::revogar($clienteId);
        } catch (\RuntimeException $e) {
            json_erro($e->getMessage());
        }
        auditar('revogar', 'produtor_autorizacao_fiscal', $clienteId, 'portal: revogação da captura de NF-e');
        json_ok(['autorizacao' => $aut]);
    }

    /** GET portal/fiscal-notas — notas capturadas do produtor (lista). */
    public function fiscalNotas(): void
    {
        $clienteId = $this->produtorId();
        json_ok(['notas' => \App\Services\NotaFiscalService::notasDoProdutor($clienteId)]);
    }

    /** GET portal/fiscal-nota?id= — nota + itens com sugestões + catálogo. */
    public function fiscalNota(): void
    {
        $clienteId = $this->produtorId();
        $d = \App\Services\NotaFiscalService::notaDetalhe($clienteId, (int) ($_GET['id'] ?? 0));
        if ($d === null) {
            json_erro('Nota não encontrada.', 404);
        }
        auditar('ler', 'nfe_documento', $d['nota']['id'], 'portal: leitura pelo próprio produtor');
        json_ok($d + ['catalogo' => \App\Services\CustoLavouraService::catalogo()]);
    }

    /** POST portal/fiscal-nota-itens — revisão do mapeamento item→custo (§7). */
    public function fiscalNotaItens(): void
    {
        $clienteId = $this->produtorId();
        $id = (int) ($_POST['id'] ?? 0);
        $itens = json_decode((string) ($_POST['itens'] ?? '[]'), true);
        if (!is_array($itens) || !$itens) {
            json_erro('Nenhum item enviado.');
        }
        try {
            $n = \App\Services\NotaFiscalService::salvarMapeamento($clienteId, $id, $itens);
        } catch (\RuntimeException $e) {
            json_erro($e->getMessage());
        }
        auditar('mapear', 'nfe_item', $id, "portal: revisão de {$n} item(ns) da nota");
        json_ok(['gravados' => $n, 'detalhe' => \App\Services\NotaFiscalService::notaDetalhe($clienteId, $id)]);
    }

    /** GET portal/mercado?cultura= — referências públicas (§8: oferta nunca sem CEPEA+B3). */
    public function mercado(): void
    {
        $this->produtorId(); // só autenticação/perfil: cotação é referência pública, não dado de produtor
        $cultura = trim((string) ($_GET['cultura'] ?? ''));
        if ($cultura === '') {
            json_erro('Informe a cultura.');
        }
        json_ok(\App\Services\MercadoService::paraPortal($cultura));
    }

    /** POST portal/lavoura-atualizar — setup (área/produtividade/preço/base). */
    public function lavouraAtualizar(): void
    {
        $clienteId = $this->produtorId();
        $id = (int) ($_POST['id'] ?? 0);
        try {
            CustoLavouraService::atualizarSetup($clienteId, $id, $_POST);
        } catch (\RuntimeException $e) {
            json_erro($e->getMessage());
        }
        auditar('salvar', 'lavoura_safra', $id, 'portal: setup da lavoura do próprio produtor');
        json_ok(['detalhe' => CustoLavouraService::detalhe($clienteId, $id)]);
    }

    /** POST portal/lavoura-custos — upsert dos itens digitados (fonte=manual). */
    public function lavouraCustos(): void
    {
        $clienteId = $this->produtorId();
        $id = (int) ($_POST['id'] ?? 0);
        $itens = json_decode((string) ($_POST['itens'] ?? '[]'), true);
        if (!is_array($itens)) {
            json_erro('Formato inválido dos itens de custo.');
        }
        try {
            $n = CustoLavouraService::salvarCustos($clienteId, $id, $itens);
        } catch (\RuntimeException $e) {
            json_erro($e->getMessage());
        }
        auditar('salvar', 'lavoura_custo', $id, "portal: {$n} itens do próprio produtor");
        json_ok(['gravados' => $n, 'detalhe' => CustoLavouraService::detalhe($clienteId, $id)]);
    }
}
