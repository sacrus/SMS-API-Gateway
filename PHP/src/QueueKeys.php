<?php

declare(strict_types=1);

namespace SmsApi;

/**
 * Chaves de submissão de SMS para sites e aplicações externas.
 *
 * São deliberadamente separadas da chave mestra que autentica o telemóvel.
 * A chave mestra não deve sair do Android: quem a tiver pode registar
 * telefones falsos e esvaziar a fila sem passar pelo painel. Cada integração
 * externa recebe a sua chave, que se revoga sozinha sem afectar as restantes.
 *
 * A chave viaja num cabeçalho (Authorization: Bearer ou X-Queue-Key) e nunca
 * no URL: um URL fica nos logs do servidor, do proxy e do CDN, no histórico
 * do navegador e no cabeçalho Referer do site de onde partiu o pedido. Por
 * isso não existe modo de passar a chave na query string.
 *
 * Em disco fica só o SHA-256. A chave em claro é mostrada uma vez, no momento
 * em que é criada, e não é recuperável — como já acontecia com os tokens dos
 * telefones.
 */
final class QueueKeys
{
    /** Distintivo visível, para se saber de que chave se trata sem a revelar. */
    public const PREFIX = 'smsq_';

    /** Teto de espera por entrega, para um pedido externo não prender o servidor. */
    private const MAX_WAIT_SECONDS = 30;

    public function __construct(
        private readonly Database $db,
        private readonly array $config
    ) {
    }

    public static function generate(): string
    {
        return self::PREFIX . bin2hex(random_bytes(20));
    }

    /**
     * Cria uma chave. Devolve o segredo em claro — é a única vez que sai.
     *
     * @param list<string> $ipAllowlist IPs ou blocos CIDR; vazio = qualquer origem
     * @return array{id:int, key:string}
     */
    public function create(
        string $name,
        int $ratePerMin,
        int $maxPerDay,
        int $waitSeconds,
        array $ipAllowlist = []
    ): array {
        $secret = self::generate();
        $name = mb_substr(trim($name), 0, 120);

        $id = $this->db->insert(
            'INSERT INTO queue_keys
                (name, key_hash, key_prefix, ip_allowlist, rate_per_min, max_per_day, wait_seconds, is_active, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)',
            [
                $name !== '' ? $name : 'Integração sem nome',
                self::hash($secret),
                substr($secret, 0, 12),
                self::encodeAllowlist($ipAllowlist),
                max(1, min(600, $ratePerMin)),
                max(0, min(100000, $maxPerDay)),
                max(0, min(self::MAX_WAIT_SECONDS, $waitSeconds)),
                time(),
            ]
        );

        return ['id' => $id, 'key' => $secret];
    }

    /**
     * Autentica um pedido externo.
     *
     * @return array the queue_keys row
     */
    public function authenticate(Request $request): array
    {
        $token = $this->extractToken($request);
        if ($token === '') {
            throw ApiException::unauthorized(
                'Chave de API ausente. Envie "Authorization: Bearer <chave>" no cabeçalho.',
                'missing_api_key'
            );
        }
        if (strlen($token) > 200) {
            throw ApiException::unauthorized('Chave de API inválida.', 'invalid_api_key');
        }

        $hash = self::hash($token);
        $row = $this->db->one('SELECT * FROM queue_keys WHERE key_hash = ?', [$hash]);

        if ($row === null) {
            // Compara na mesma, para não responder mais depressa a uma chave
            // inexistente do que a uma revogada.
            hash_equals($hash, str_repeat('0', 64));
            throw ApiException::unauthorized('Chave de API inválida.', 'invalid_api_key');
        }
        if ((int) $row['is_active'] !== 1) {
            throw ApiException::forbidden('Esta chave foi revogada pelo administrador.', 'api_key_revoked');
        }

        $this->guardIp($row, $request->ip());
        $this->enforceLimits((int) $row['id'], (int) $row['rate_per_min'], (int) $row['max_per_day']);

        $this->db->run(
            'UPDATE queue_keys SET last_used_at = ?, last_used_ip = ? WHERE id = ?',
            [time(), $request->ip(), (int) $row['id']]
        );

        return $row;
    }

    /** @return list<array> */
    public function all(): array
    {
        return $this->db->all('SELECT * FROM queue_keys ORDER BY id DESC');
    }

    public function revoke(int $id): bool
    {
        return $this->db->run('UPDATE queue_keys SET is_active = 0 WHERE id = ?', [$id])->rowCount() > 0;
    }

    public function reactivate(int $id): bool
    {
        return $this->db->run('UPDATE queue_keys SET is_active = 1 WHERE id = ?', [$id])->rowCount() > 0;
    }

    public function delete(int $id): bool
    {
        return $this->db->run('DELETE FROM queue_keys WHERE id = ?', [$id])->rowCount() > 0;
    }

    public function countSent(int $id): void
    {
        $this->db->run('UPDATE queue_keys SET total_sent = total_sent + 1 WHERE id = ?', [$id]);
    }

    /**
     * Chave em claro a partir do cabeçalho.
     *
     * Não se lê de $_GET de propósito: um URL com a chave fica registado em
     * todo o lado por onde passa.
     */
    private function extractToken(Request $request): string
    {
        $authorization = trim((string) $request->header('authorization', ''));
        if (stripos($authorization, 'bearer ') === 0) {
            return trim(substr($authorization, 7));
        }

        foreach (['x-queue-key', 'x-api-queue-key'] as $header) {
            $value = trim((string) $request->header($header, ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /** @param array<string,mixed> $row */
    private function guardIp(array $row, string $ip): void
    {
        $allowed = self::decodeAllowlist($row['ip_allowlist'] ?? null);
        if ($allowed === []) {
            return;
        }

        if (!self::ipMatches($ip, $allowed)) {
            throw ApiException::forbidden(
                'Esta chave não pode ser usada a partir deste endereço.',
                'ip_not_allowed'
            );
        }
    }

    private function enforceLimits(int $keyId, int $ratePerMin, int $maxPerDay): void
    {
        if ($ratePerMin > 0) {
            $used = (int) $this->db->value(
                'SELECT COUNT(*) FROM api_log WHERE key_id = ? AND created_at > ?',
                [$keyId, time() - 60]
            );
            if ($used >= $ratePerMin) {
                throw ApiException::tooMany(
                    "Limite de {$ratePerMin} pedidos por minuto excedido nesta chave."
                );
            }
        }

        if ($maxPerDay > 0) {
            $used = (int) $this->db->value(
                'SELECT COUNT(*) FROM api_log WHERE key_id = ? AND created_at > ?',
                [$keyId, time() - 86400]
            );
            if ($used >= $maxPerDay) {
                throw ApiException::tooMany(
                    "Limite diário de {$maxPerDay} pedidos excedido nesta chave."
                );
            }
        }
    }

    /**
     * @param list<string> $allowed
     * Compara o IP contra endereços exactos e contra blocos CIDR, para o caso
     * de a integração estar atrás de um balanceador com um intervalo fixo.
     */
    private static function ipMatches(string $ip, array $allowed): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        foreach ($allowed as $entry) {
            if (!str_contains($entry, '/')) {
                $candidate = @inet_pton($entry);
                if ($candidate !== false && hash_equals($candidate, $packed)) {
                    return true;
                }
                continue;
            }

            [$network, $bits] = explode('/', $entry, 2);
            $networkBin = @inet_pton($network);
            if ($networkBin === false || $networkBin === '' || strlen($networkBin) !== strlen($packed)) {
                continue;
            }

            $bits = (int) $bits;
            $maxBits = strlen($networkBin) * 8;
            if ($bits < 0 || $bits > $maxBits) {
                continue;
            }

            $wholeBytes = intdiv($bits, 8);
            $remainder = $bits % 8;
            if ($wholeBytes > 0 && substr($networkBin, 0, $wholeBytes) !== substr($packed, 0, $wholeBytes)) {
                continue;
            }
            if ($remainder > 0) {
                $mask = chr((0xFF << (8 - $remainder)) & 0xFF);
                if ((($networkBin[$wholeBytes] & $mask)) !== (($packed[$wholeBytes] & $mask))) {
                    continue;
                }
            }

            return true;
        }

        return false;
    }

    /** @param list<string> $entries */
    private static function encodeAllowlist(array $entries): ?string
    {
        $clean = [];
        foreach ($entries as $entry) {
            $entry = trim((string) $entry);
            if ($entry !== '') {
                $clean[] = $entry;
            }
        }
        return $clean === [] ? null : implode(',', $clean);
    }

    /** @return list<string> */
    private static function decodeAllowlist(?string $stored): array
    {
        if ($stored === null || trim($stored) === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $stored)), 'strlen'));
    }

    private static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }
}