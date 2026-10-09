<?php

declare(strict_types=1);

namespace SmsApi;

/**
 * Verifies the shared-secret + HMAC handshake used by the Android app.
 *
 * Canonical string signed by the client (and recomputed here):
 *
 *     METHOD \n endpoint \n timestamp \n nonce \n sha256hex(raw body)
 *
 * Headers: X-Api-Key, X-Timestamp, X-Nonce, X-Signature, X-Device-Token.
 */
final class Auth
{
    public function __construct(
        private readonly Database $db,
        private readonly array $config
    ) {
    }

    /**
     * @return array{device: ?array, rate_limit: int}
     */
    public function authenticate(Request $request, bool $requiresDevice = true): array
    {
        $apiKey = (string) $this->config['api_key'];
        $skew = (int) ($this->config['settings']['clock_skew'] ?? 300);

        $providedKey = (string) $request->header('x-api-key', '');
        if ($providedKey === '' || !hash_equals($apiKey, $providedKey)) {
            throw ApiException::unauthorized('Chave da API inválida.', 'invalid_api_key');
        }

        $timestamp = (string) $request->header('x-timestamp', '');
        if (!ctype_digit($timestamp)) {
            throw ApiException::unauthorized('Timestamp ausente ou malformado.', 'invalid_timestamp');
        }
        $skewSeconds = abs(time() - (int) $timestamp);
        if ($skewSeconds > $skew) {
            throw ApiException::unauthorized(
                "Desvio de relógio de {$skewSeconds}s (máximo {$skew}s). Sincronize o relógio do telefone.",
                'clock_skew'
            );
        }

        $nonce = (string) $request->header('x-nonce', '');
        if (!preg_match('/^[0-9a-f]{16,64}$/i', $nonce)) {
            throw ApiException::unauthorized('Nonce ausente ou malformado.', 'invalid_nonce');
        }
        $this->consumeNonce($nonce);

        $signature = strtolower((string) $request->header('x-signature', ''));
        $expected = $this->sign($apiKey, $request->method, $request->endpoint, $timestamp, $nonce, $request->rawBody);
        if ($signature === '' || !hash_equals($expected, $signature)) {
            throw ApiException::unauthorized('Assinatura inválida.', 'invalid_signature');
        }

        if (!$requiresDevice) {
            return ['device' => null, 'rate_limit' => (int) ($this->config['settings']['rate_limit'] ?? 120)];
        }

        $device = $this->resolveDevice($request);
        $this->enforceRateLimit((int) $device['id']);

        return [
            'device' => $device,
            'rate_limit' => (int) ($this->config['settings']['rate_limit'] ?? 120),
        ];
    }

    public function sign(string $apiKey, string $method, string $endpoint, string $timestamp, string $nonce, string $rawBody): string
    {
        $canonical = strtoupper($method) . "\n" . strtolower($endpoint) . "\n" . $timestamp . "\n" . $nonce . "\n" . hash('sha256', $rawBody);
        return hash_hmac('sha256', $canonical, $apiKey);
    }

    private function consumeNonce(string $nonce): void
    {
        try {
            $this->db->run('INSERT INTO nonces (nonce, created_at) VALUES (?, ?)', [$nonce, time()]);
        } catch (\PDOException $e) {
            throw ApiException::unauthorized('Pedido repetido (nonce já usado).', 'replay_detected');
        }
    }

    private function resolveDevice(Request $request): array
    {
        $token = (string) $request->header('x-device-token', '');
        if ($token === '' || strlen($token) > 200) {
            throw ApiException::unauthorized('Token do dispositivo ausente.', 'missing_device_token');
        }

        $device = $this->db->one('SELECT * FROM devices WHERE token_hash = ?', [hash('sha256', $token)]);
        if ($device === null) {
            throw ApiException::unauthorized('Dispositivo desconhecido — registe novamente.', 'device_not_found');
        }
        if ((int) $device['is_active'] !== 1) {
            throw ApiException::forbidden('Este telefone foi revogado pelo administrador.', 'device_revoked');
        }

        $this->db->run(
            'UPDATE devices SET last_seen_at = ?, last_ip = ? WHERE id = ?',
            [time(), $request->ip(), (int) $device['id']]
        );

        return $device;
    }

    private function enforceRateLimit(int $deviceId): void
    {
        $limit = (int) ($this->config['settings']['rate_limit'] ?? 120);
        if ($limit <= 0) {
            return;
        }
        $count = (int) $this->db->value(
            'SELECT COUNT(*) FROM api_log WHERE device_id = ? AND created_at > ?',
            [$deviceId, time() - 60]
        );
        if ($count >= $limit) {
            throw ApiException::tooMany("Limite de {$limit} pedidos por minuto excedido.");
        }
    }
}