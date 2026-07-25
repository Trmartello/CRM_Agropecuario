<?php

namespace App\Services;

/**
 * Tabela código IBGE → nome do município (Região Sul: SC, RS, PR).
 *
 * Usada na importação da base do CAR por município: o `cod_imovel` do SICAR vem
 * no formato "UF-IBGE-hash" (ex.: "SC-4204202-..."), então o código IBGE (7
 * dígitos) identifica o município com precisão — e este mapa preenche o NOME
 * legível sozinho, sem o Administrador precisar digitar a cada importação.
 *
 * A identificação/dedup dos imóveis NÃO depende deste mapa (é feita pelo próprio
 * código IBGE em CarService); o mapa serve só para o nome amigável (rótulos e a
 * busca "Ir para município"). Quando o código não está no mapa, o CarService cai
 * no nome digitado no formulário e, por fim, em "IBGE {código}".
 *
 * Para popular/atualizar: rode `php tools/gerar_municipios_sul.php` de um ambiente
 * com acesso à API do IBGE (servicodados.ibge.gov.br) — ele reescreve o array
 * abaixo com os ~1.191 municípios do Sul. Mantido embutido (sem I/O em runtime).
 */
class MunicipiosSul
{
    /** Código IBGE (7 dígitos, string) => nome do município. Gerado pelo IBGE. */
    private const MAPA = [
        // Preenchido por tools/gerar_municipios_sul.php (API do IBGE, SC/RS/PR).
        // Vazio = cai no nome digitado no formulário / "IBGE {código}".
    ];

    /** Nome do município a partir do código IBGE (ou null se desconhecido). */
    public static function nome(?string $codIbge): ?string
    {
        if ($codIbge === null || $codIbge === '') {
            return null;
        }
        return self::MAPA[$codIbge] ?? null;
    }

    /** Há tabela carregada? (para diagnóstico/telas). */
    public static function carregada(): bool
    {
        return self::MAPA !== [];
    }
}
