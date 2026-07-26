<?php

namespace App\Services;

/**
 * Provedor LOCAL de DF-e — o primeiro adaptador do pipeline (nf-ingestao PR2).
 *
 * Lê XMLs de um diretório no servidor (`<dir>/<cpf_cnpj só dígitos>/*.xml`). Serve para:
 * - desenvolvimento e testes do pipeline completo (parse, dedup, log) sem
 *   depender de contrato com SaaS;
 * - o piloto, quando os XMLs chegarem por outro canal (contador/e-mail) e forem
 *   depositados no diretório do produtor.
 *
 * O adaptador de SaaS real (API key do provedor contratado) implementa a mesma
 * interface e substitui este pela config `fiscal_provedor` — nada mais muda.
 * A "procuração" aqui é local (não há terceiro): autorizar/ativar é imediato.
 */
class FiscalAdapterLocal implements FiscalAdapterInterface
{
    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? ConfigService::obter('fiscal_dir_local', uploads_dir() . '/fiscal');
    }

    public function nome(): string
    {
        return 'local';
    }

    public function autorizar(int $produtorId, string $cpfCnpj): array
    {
        // Sem terceiro envolvido: a autorização local ativa na hora e a
        // referência é a pasta do produtor (por CPF/CNPJ, só dígitos).
        return ['procuracao_ref' => 'local:' . preg_replace('/\D/', '', $cpfCnpj), 'status' => 'ativa'];
    }

    public function revogar(int $produtorId, string $procuracaoRef): void
    {
        // Nada a encerrar em terceiro; a revogação local é gravada pelo service.
    }

    public function status(int $produtorId, string $procuracaoRef): string
    {
        return 'ativa';
    }

    public function puxarDocumentos(string $cpfCnpj, ?string $desde): array
    {
        $sub = preg_replace('/\D/', '', $cpfCnpj); // pasta por CPF/CNPJ (só dígitos)
        $pasta = $this->dir . '/' . $sub;
        if ($sub === '' || !is_dir($pasta)) {
            return [];
        }
        $docs = [];
        foreach (glob($pasta . '/*.xml') ?: [] as $arq) {
            if ($desde !== null && filemtime($arq) < strtotime($desde)) {
                continue;
            }
            $xml = file_get_contents($arq);
            if ($xml !== false && $xml !== '') {
                $docs[] = $xml;
            }
        }
        return $docs;
    }
}
