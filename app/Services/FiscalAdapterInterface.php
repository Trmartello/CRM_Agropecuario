<?php

namespace App\Services;

/**
 * Contrato do provedor de captura de DF-e — spec docs/specs/nf-ingestao.md §4.
 *
 * Um adaptador por provedor (SaaS de captura com custódia de certificado e o
 * webservice NFeDistribuicaoDFe). Trocar de provedor = nova classe implementando
 * esta interface + a config `fiscal_provedor`; o NotaFiscalService e as telas
 * não mudam (mesmo princípio do IntegracaoService). Manifestação do
 * destinatário, quando o provedor exigir, é responsabilidade do adaptador.
 */
interface FiscalAdapterInterface
{
    /** Nome curto do provedor (gravado em produtor_autorizacao_fiscal.provedor). */
    public function nome(): string;

    /**
     * Inicia/registra a procuração do produtor no provedor.
     *
     * @return array{procuracao_ref: string, status: string} status do ENUM de
     *         produtor_autorizacao_fiscal (pendente|ativa|erro)
     */
    public function autorizar(int $produtorId, string $cpfCnpj): array;

    /** Encerra a autorização no provedor (a revogação local é do service). */
    public function revogar(int $produtorId, string $procuracaoRef): void;

    /** Estado atual da procuração no provedor (pendente|ativa|revogada|expirada|erro). */
    public function status(int $produtorId, string $procuracaoRef): string;

    /**
     * Busca os documentos do produtor (destinatário) desde a data informada.
     *
     * @param string|null $desde data AAAA-MM-DD (null = janela máxima do provedor)
     * @return string[] lista de XMLs de NF-e (nfeProc ou NFe), um por documento
     */
    public function puxarDocumentos(string $cpfCnpj, ?string $desde): array;
}
