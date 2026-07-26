<?php

namespace App\Services;

use App\Core\Database;

/**
 * Referências públicas de mercado (ref_mercado) — spec custo-lavoura §8/§9/§13 PR8.
 *
 * Cache diário de cotações (CEPEA/ESALQ, futuro B3 e oferta Copérdia) mantido
 * pelo Administrador no card da Integração. Não há serviço externo: a saída do
 * servidor é restrita (mesma limitação dos tiles) — a entrada é manual/importada
 * e um adaptador automático futuro é plugável aqui sem mudar as telas.
 *
 * NÃO é dado sob firewall (é referência pública): usa a conexão comercial normal
 * — o Administrador grava por ela; o Portal só lê.
 *
 * REGRA §8 (imposta AQUI, no servidor): a oferta da Copérdia NUNCA sai sem o
 * CEPEA e o B3 na mesma resposta — sem as referências públicas, a oferta é
 * omitida (nenhum cliente consegue contornar).
 */
class MercadoService
{
    public const FONTES = ['cepea', 'b3', 'coperdia'];

    /** Culturas que interessam ao módulo (presets + lavouras já criadas). */
    public static function culturas(): array
    {
        return array_column(Database::todos(
            'SELECT DISTINCT cultura FROM custo_preset
              UNION SELECT DISTINCT cultura FROM lavoura_safra
              ORDER BY cultura'
        ), 'cultura');
    }

    /**
     * Cotação vigente (mais recente) por cultura+fonte.
     *
     * @return array<string, array> chave "cultura|fonte" → linha
     */
    public static function vigentes(?string $cultura = null): array
    {
        $sql = 'SELECT id, cultura, fonte, vencimento, preco, dt_cotacao
                  FROM ref_mercado' . ($cultura !== null ? ' WHERE cultura = ?' : '') . '
                 ORDER BY dt_cotacao DESC, id DESC';
        $map = [];
        foreach (Database::todos($sql, $cultura !== null ? [$cultura] : []) as $r) {
            $k = $r['cultura'] . '|' . $r['fonte'];
            if (!isset($map[$k])) { // primeira = mais recente
                $map[$k] = $r;
            }
        }
        return $map;
    }

    /**
     * Grava uma cotação (upsert do dia — uk cultura+fonte+vencimento+data).
     *
     * @throws \RuntimeException quando a validação falha.
     */
    public static function salvar(string $cultura, string $fonte, float $preco, string $vencimento = '', ?string $dt = null): void
    {
        $cultura = trim($cultura);
        $fonte = strtolower(trim($fonte));
        $vencimento = mb_substr(trim($vencimento), 0, 20);
        $dt = trim((string) $dt) ?: date('Y-m-d');
        if ($cultura === '' || mb_strlen($cultura) > 40) {
            throw new \RuntimeException('Informe a cultura da cotação.');
        }
        if (!in_array($fonte, self::FONTES, true)) {
            throw new \RuntimeException('Fonte inválida (cepea, b3 ou coperdia).');
        }
        if ($preco <= 0 || $preco > 99999) {
            throw new \RuntimeException('Informe o preço da cotação (R$/sc, maior que zero).');
        }
        $d = \DateTime::createFromFormat('Y-m-d', $dt);
        if (!$d || $d->format('Y-m-d') !== $dt) {
            throw new \RuntimeException('Data da cotação inválida (use AAAA-MM-DD).');
        }
        // vencimento vazio vira '' (não NULL): NULL não deduplica na UNIQUE do MySQL
        Database::executar(
            'INSERT INTO ref_mercado (cultura, fonte, vencimento, preco, dt_cotacao)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE preco = VALUES(preco)',
            [$cultura, $fonte, $vencimento, round($preco, 2), $dt]
        );
    }

    /**
     * Cotações para o Portal (§8): vigentes da cultura, com a oferta da Copérdia
     * OMITIDA se faltar CEPEA ou B3 — nunca a oferta sozinha.
     *
     * @return array{cotacoes: array<string,array>, completa: bool}
     */
    public static function paraPortal(string $cultura): array
    {
        $vig = self::vigentes($cultura);
        $out = [];
        foreach (self::FONTES as $f) {
            $r = $vig[$cultura . '|' . $f] ?? null;
            if ($r !== null) {
                $out[$f] = [
                    'preco' => (float) $r['preco'],
                    'vencimento' => (string) ($r['vencimento'] ?? ''),
                    'data' => $r['dt_cotacao'],
                ];
            }
        }
        $completa = isset($out['cepea'], $out['b3']);
        if (!$completa) {
            unset($out['coperdia']); // §8: oferta nunca sem as referências públicas
        }
        return ['cotacoes' => $out, 'completa' => $completa];
    }
}
