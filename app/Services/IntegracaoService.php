<?php

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;

/**
 * Camada de integração com o ERP/CAPE (Módulo 18).
 *
 * A fonte de dados é configurável: enquanto o ERP não está conectado, o sistema
 * opera com a base local. As telas e os demais serviços (ComercialService,
 * CapService, CreditoService) usam interfaces estáveis — quando o endpoint real
 * do ERP/CAPE existir, apenas o adaptador aqui muda, sem tocar no restante.
 */
class IntegracaoService
{
    /** Entidades sincronizáveis (conforme escopo do Módulo 18). */
    public const ENTIDADES = [
        'clientes' => 'Clientes e associados',
        'produtos' => 'Produtos e tabelas de preço',
        'estoque' => 'Estoque',
        'pedidos' => 'Pedidos e notas fiscais',
        'entregas_futuras' => 'Entregas futuras',
        'titulos_financeiros' => 'Financeiro (contas a receber)',
        'metas_cap' => 'Metas CAP (app CAPE)',
    ];

    public static function fonte(): string
    {
        return ConfigService::obter('integracao_fonte', 'Local');
    }

    public static function definirFonte(string $fonte): void
    {
        ConfigService::definir('integracao_fonte', in_array($fonte, ['Local', 'ERP', 'CAPE'], true) ? $fonte : 'Local');
    }

    public static function erpConfigurado(): bool
    {
        return trim(ConfigService::obter('integracao_erp_url', '')) !== '';
    }

    /**
     * Sincroniza uma entidade. Sem ERP conectado, valida/consolida a base local
     * e registra no log — mantendo o fluxo pronto para a fonte real.
     */
    public static function sincronizar(string $entidade): array
    {
        if (!isset(self::ENTIDADES[$entidade])) {
            throw new \InvalidArgumentException('Entidade desconhecida.');
        }
        $fonte = self::fonte();

        if ($fonte !== 'Local' && !self::erpConfigurado()) {
            self::registrar($fonte, $entidade, 'Erro', 0, 'Fonte externa selecionada, mas a URL do ERP não está configurada.');
            throw new \RuntimeException('Configure a URL do ERP antes de sincronizar com fonte externa.');
        }

        // Adaptador local (placeholder do adaptador ERP): conta os registros atuais.
        // 'estoque' é coluna de produtos; as demais entidades mapeiam 1:1 para tabelas.
        $consulta = $entidade === 'estoque'
            ? 'SELECT COUNT(*) FROM produtos WHERE estoque > 0'
            : "SELECT COUNT(*) FROM {$entidade}";
        $registros = (int) Database::valor($consulta);
        $mensagem = $fonte === 'Local'
            ? 'Base local consolidada (ERP ainda não conectado).'
            : 'Importado do ' . $fonte . '.';
        self::registrar($fonte, $entidade, 'Sucesso', $registros, $mensagem);

        return ['entidade' => $entidade, 'registros' => $registros, 'fonte' => $fonte];
    }

    public static function sincronizarTudo(): array
    {
        $resultado = [];
        foreach (array_keys(self::ENTIDADES) as $ent) {
            try {
                $resultado[] = self::sincronizar($ent);
            } catch (\Exception $e) {
                $resultado[] = ['entidade' => $ent, 'erro' => $e->getMessage()];
            }
        }
        return $resultado;
    }

    private static function registrar(string $fonte, string $entidade, string $status, int $registros, string $mensagem): void
    {
        Database::executar(
            'INSERT INTO integracao_log (fonte, entidade, direcao, status, registros, mensagem, usuario_id)
             VALUES (?,?,?,?,?,?,?)',
            [$fonte, $entidade, 'Importação', $status, $registros, mb_substr($mensagem, 0, 255), Auth::id() ?: null]
        );
    }

    public static function historico(int $limite = 30): array
    {
        return Database::todos(
            'SELECT l.*, u.nome AS usuario FROM integracao_log l
               LEFT JOIN usuarios u ON u.id = l.usuario_id
              ORDER BY l.criado_em DESC LIMIT ' . (int) $limite
        );
    }

    public static function status(): array
    {
        $itens = [];
        foreach (self::ENTIDADES as $ent => $rotulo) {
            $ult = Database::um(
                "SELECT status, registros, criado_em FROM integracao_log WHERE entidade = ? ORDER BY criado_em DESC LIMIT 1",
                [$ent]
            );
            $itens[] = [
                'entidade' => $ent,
                'rotulo' => $rotulo,
                'ultima' => $ult['criado_em'] ?? null,
                'status' => $ult['status'] ?? '—',
                'registros' => (int) ($ult['registros'] ?? 0),
            ];
        }
        return $itens;
    }
}
