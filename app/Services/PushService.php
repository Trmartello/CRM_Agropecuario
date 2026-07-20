<?php

namespace App\Services;

use App\Core\Database;

/**
 * Web Push (VAPID) sem dependências externas.
 *
 * Estratégia: o push é enviado SEM payload (não exige a criptografia RFC 8291);
 * o service worker acorda com o evento e busca a última notificação não lida no
 * servidor para exibir. Autenticação VAPID (JWT ES256) feita com OpenSSL puro.
 * Funciona no Android (Chrome) e no iPhone com o PWA instalado (iOS 16.4+).
 */
class PushService
{
    private const ASSUNTO = 'mailto:crm@coperdia.com.br';

    /** Chave pública VAPID em base64url (ponto EC não comprimido) — cria o par se não existir. */
    public static function chavePublica(): string
    {
        $pub = ConfigService::obter('vapid_publica');
        if ($pub) {
            return $pub;
        }
        $chave = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($chave, $privPem);
        $det = openssl_pkey_get_details($chave);
        $pub = self::b64url("\x04" . $det['ec']['x'] . $det['ec']['y']);
        ConfigService::definir('vapid_privada', $privPem);
        ConfigService::definir('vapid_publica', $pub);
        return $pub;
    }

    /** Registra (ou renova) a assinatura de um aparelho para o usuário. */
    public static function registrar(int $usuarioId, string $endpoint, ?string $p256dh, ?string $auth): void
    {
        if ($endpoint === '' || !preg_match('#^https://#', $endpoint)) {
            throw new \InvalidArgumentException('Assinatura de notificação inválida.');
        }
        Database::executar(
            'INSERT INTO push_assinaturas (usuario_id, endpoint_hash, endpoint, p256dh, auth)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE usuario_id = VALUES(usuario_id), p256dh = VALUES(p256dh), auth = VALUES(auth)',
            [$usuarioId, hash('sha256', $endpoint), $endpoint, $p256dh ?: null, $auth ?: null]
        );
    }

    public static function remover(string $endpoint): void
    {
        Database::executar('DELETE FROM push_assinaturas WHERE endpoint_hash = ?', [hash('sha256', $endpoint)]);
    }

    /** Envia um "toque" push a todos os aparelhos do usuário (best-effort). */
    public static function enviarParaUsuario(int $usuarioId): void
    {
        $assinaturas = Database::todos(
            'SELECT endpoint, endpoint_hash FROM push_assinaturas WHERE usuario_id = ?',
            [$usuarioId]
        );
        foreach ($assinaturas as $a) {
            $status = self::enviar($a['endpoint']);
            if (in_array($status, [404, 410], true)) {
                // Assinatura expirada/cancelada no aparelho: limpa
                Database::executar('DELETE FROM push_assinaturas WHERE endpoint_hash = ?', [$a['endpoint_hash']]);
            }
        }
    }

    /** POST vazio ao endpoint com autenticação VAPID. Retorna o status HTTP (0 = falha de rede). */
    private static function enviar(string $endpoint): int
    {
        $partes = parse_url($endpoint);
        if (!$partes || empty($partes['host'])) {
            return 0;
        }
        $aud = ($partes['scheme'] ?? 'https') . '://' . $partes['host']
            . (isset($partes['port']) ? ':' . $partes['port'] : '');
        $jwt = self::jwtVapid($aud);
        if ($jwt === null) {
            return 0;
        }
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'Authorization: vapid t=' . $jwt . ', k=' . self::chavePublica(),
                'TTL: 86400',
                'Urgency: normal',
                'Content-Length: 0',
            ],
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $status;
    }

    /** JWT ES256 do VAPID para o push service ($aud = origem do endpoint). */
    private static function jwtVapid(string $aud): ?string
    {
        self::chavePublica(); // garante o par de chaves
        $privPem = ConfigService::obter('vapid_privada');
        if (!$privPem) {
            return null;
        }
        $cabecalho = self::b64url(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $corpo = self::b64url(json_encode(['aud' => $aud, 'exp' => time() + 43200, 'sub' => self::ASSUNTO]));
        $dados = $cabecalho . '.' . $corpo;
        if (!openssl_sign($dados, $assinaturaDer, $privPem, OPENSSL_ALGO_SHA256)) {
            return null;
        }
        return $dados . '.' . self::b64url(self::derParaRaw($assinaturaDer));
    }

    /** Converte a assinatura ECDSA DER do OpenSSL para r||s (64 bytes), como o JWT exige. */
    private static function derParaRaw(string $der): string
    {
        $pos = 2; // SEQUENCE header (0x30 len)
        if ((ord($der[1]) & 0x80) !== 0) {
            $pos = 2 + (ord($der[1]) & 0x7f);
        }
        $saida = '';
        for ($i = 0; $i < 2; $i++) {
            $pos++; // 0x02 (INTEGER)
            $len = ord($der[$pos++]);
            $int = substr($der, $pos, $len);
            $pos += $len;
            $int = ltrim($int, "\x00");              // remove zero de sinal
            $saida .= str_pad($int, 32, "\x00", STR_PAD_LEFT); // fixa em 32 bytes
        }
        return $saida;
    }

    private static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
