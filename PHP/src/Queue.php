<?php

declare(strict_types=1);

namespace SmsApi;

/**
 * The SMS queue. The website only ever writes here; the Android phone only
 * ever claims and reports back. Rows are claimed atomically so two devices
 * polling at the same time never receive the same message.
 */
final class Queue
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    public function __construct(
        private readonly Database $db,
        private readonly array $config
    ) {
    }

    /**
     * @param bool $toGsm7 rewrite the text to the cheap GSM 03.38 alphabet first
     * @param int|null $queueKeyId which external integration submitted it, so
     *        one key never sees or cancels another key's messages
     * @return array{id:int, encoding:string, segments:int, normalized:bool, report:?string}
     */
    public function enqueue(
        string $phone,
        string $body,
        ?int $deviceId = null,
        ?string $externalRef = null,
        bool $toGsm7 = false,
        ?int $queueKeyId = null
    ): array {
        $phone = trim($phone);
        $body = trim($body);

        if ($phone === '') {
            throw ApiException::badRequest('O número de destino é obrigatório.', 'missing_phone');
        }
        if ($body === '') {
            throw ApiException::badRequest('O texto da mensagem é obrigatório.', 'missing_body');
        }
        if (!preg_match('/^\+?[0-9][0-9\s\-().]{4,24}$/', $phone)) {
            throw ApiException::badRequest('Número de telefone inválido.', 'invalid_phone');
        }
        if (mb_strlen($body) > 2000) {
            throw ApiException::badRequest('A mensagem excede 2000 caracteres.', 'body_too_long');
        }

        $report = null;
        if ($toGsm7) {
            $converted = SmsText::toGsm7($body);
            if ($converted['changed']) {
                $body = trim($converted['text']);
                $report = self::normalizationReport($converted);
                if ($body === '') {
                    throw ApiException::badRequest(
                        'A mensagem ficou vazia depois de convertida para GSM-7.',
                        'empty_after_normalization'
                    );
                }
            }
        }

        $analysis = SmsText::analyze($body);
        $this->guardSegmentCount($analysis, $body);

        $id = $this->db->insert(
            'INSERT INTO sms_queue
                (device_id, phone, body, status, attempts, max_attempts, created_at, external_ref, encoding, segments, queue_key_id)
             VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?)',
            [
                $deviceId,
                $phone,
                $body,
                self::STATUS_PENDING,
                (int) ($this->config['settings']['max_attempts'] ?? 3),
                time(),
                $externalRef !== null ? substr($externalRef, 0, 190) : null,
                $analysis['encoding'],
                $analysis['segments'],
                $queueKeyId,
            ]
        );

        return [
            'id' => $id,
            'encoding' => $analysis['encoding'],
            'segments' => $analysis['segments'],
            'normalized' => $report !== null,
            'report' => $report,
        ];
    }

    /**
     * Blocks messages that would be split into too many SMS. Note that Android
     * never turns an SMS into MMS on its own — what a high segment count really
     * causes is a multi-part SMS billed as several messages, with partial
     * delivery and no way to tell which parts arrived.
     *
     * @param array{encoding:string, units:int, segments:int, single_limit:int, multipart_limit:int} $analysis
     */
    private function guardSegmentCount(array $analysis, string $body): void
    {
        $maxSegments = (int) ($this->config['settings']['max_segments'] ?? 3);
        if ($maxSegments <= 0 || $analysis['segments'] <= $maxSegments) {
            return;
        }

        $hint = $analysis['encoding'] === SmsText::ENCODING_UCS2
            ? sprintf(
                ' A codificação é Unicode (limite %d caracteres em vez de %d) por causa de: %s.',
                SmsText::UCS2_SINGLE,
                SmsText::GSM7_SINGLE,
                self::offenderList(SmsText::offendingCharacters($body))
            )
            : sprintf(' O limite por parte é %d caracteres.', $analysis['multipart_limit']);

        throw ApiException::badRequest(
            sprintf(
                'Esta mensagem seria enviada em %d SMS e o limite é %d.%s Reduza o texto ou active a conversão para GSM-7.',
                $analysis['segments'],
                $maxSegments,
                $hint
            ),
            'too_many_segments'
        );
    }

    private static function offenderList(array $chars): string
    {
        return $chars === [] ? 'caracteres fora do alfabeto' : implode(' ', $chars);
    }

    private static function normalizationReport(array $converted): string
    {
        $parts = [];
        $pairs = [];
        foreach ($converted['replacements'] as $from => $to) {
            $pairs[] = $from . '→' . $to;
        }
        if ($pairs !== []) {
            $parts[] = 'substituições: ' . implode(', ', $pairs);
        }
        if ($converted['dropped'] > 0) {
            $parts[] = $converted['dropped'] . ' símbolo(s) sem equivalente removido(s)';
        }
        return 'Convertida para GSM-7 (' . implode('; ', $parts) . ').';
    }

    /**
     * Atomically takes up to $limit messages for a device.
     *
     * @return list<array>
     */
    public function claim(int $deviceId, int $limit): array
    {
        $limit = max(1, min($limit, (int) ($this->config['settings']['max_per_poll'] ?? 25)));
        $claimTimeout = (int) ($this->config['settings']['claim_timeout'] ?? 300);

        return $this->db->transaction(function (Database $db) use ($deviceId, $limit, $claimTimeout): array {
            // Give up on claims that were never confirmed (phone died mid-send).
            $db->run(
                'UPDATE sms_queue SET status = ?, claimed_at = NULL, claimed_by = NULL
                 WHERE status = ? AND claimed_at IS NOT NULL AND claimed_at < ?',
                [self::STATUS_PENDING, self::STATUS_PROCESSING, time() - $claimTimeout]
            );

            $ids = array_map(
                'intval',
                array_column($db->all(
                    'SELECT id FROM sms_queue
                     WHERE status = ? AND (device_id IS NULL OR device_id = ?)
                     ORDER BY id ASC
                     LIMIT ' . $limit,
                    [self::STATUS_PENDING, $deviceId]
                ), 'id')
            );

            if ($ids === []) {
                return [];
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $params = array_merge([self::STATUS_PROCESSING, time(), $deviceId], $ids);

            $db->run(
                "UPDATE sms_queue
                 SET status = ?, claimed_at = ?, claimed_by = ?, attempts = attempts + 1
                 WHERE id IN ({$placeholders})",
                $params
            );

            return $db->all(
                "SELECT id, phone, body, attempts, created_at, external_ref, encoding, segments
                 FROM sms_queue WHERE id IN ({$placeholders}) ORDER BY id ASC",
                $ids
            );
        });
    }

    /** Marks messages as successfully handed to the modem. */
    public function ack(int $deviceId, array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $affected = $this->db->run(
            "UPDATE sms_queue
             SET status = ?, sent_at = ?, claimed_at = NULL, claimed_by = NULL, last_error = NULL
             WHERE id IN ({$placeholders}) AND claimed_by = ? AND status = ?",
            array_merge([self::STATUS_SENT, time()], $ids, [$deviceId, self::STATUS_PROCESSING])
        )->rowCount();

        return (int) $affected;
    }

    /**
     * Reports a failure. The row goes back to pending while attempts remain,
     * otherwise it lands in the dead-letter state for the admin to inspect.
     *
     * @return array{status: string, attempts: int}
     */
    public function fail(int $deviceId, int $id, string $reason): array
    {
        $row = $this->db->one('SELECT * FROM sms_queue WHERE id = ?', [$id]);
        if ($row === null || (int) $row['claimed_by'] !== $deviceId) {
            throw ApiException::notFound('Mensagem não encontrada ou não pertence a este dispositivo.', 'queue_not_found');
        }

        $attempts = (int) $row['attempts'];
        $maxAttempts = max(1, (int) $row['max_attempts']);
        $reason = substr(trim($reason), 0, 300);
        $exhausted = $attempts >= $maxAttempts;

        $this->db->run(
            'UPDATE sms_queue SET status = ?, last_error = ?, claimed_at = NULL, claimed_by = NULL WHERE id = ?',
            [$exhausted ? self::STATUS_FAILED : self::STATUS_PENDING, $reason, $id]
        );

        return ['status' => $exhausted ? self::STATUS_FAILED : self::STATUS_PENDING, 'attempts' => $attempts];
    }

    /**
     * Estado de uma mensagem, para o site externo saber se foi entregue.
     *
     * Quando $queueKeyId é dado, a mensagem tem de ter sido submetida por essa
     * chave. Sem esta restrição, qualquer chave com o endpoint /status consegue
     * ler o histórico de todas as outras e confirmar números de telefone que
     * não são seus.
     *
     * @return array{id:int, phone:string, body:string, status:string, attempts:int, created_at:int, sent_at:?int, last_error:?string, external_ref:?string, encoding:string, segments:int}|null
     */
    public function find(int $id, ?int $queueKeyId = null): ?array
    {
        $row = $queueKeyId === null
            ? $this->db->one('SELECT * FROM sms_queue WHERE id = ?', [$id])
            : $this->db->one('SELECT * FROM sms_queue WHERE id = ? AND queue_key_id = ?', [$id, $queueKeyId]);

        return $row;
    }

    /** Procura pela referência externa que o próprio site forneceu. */
    public function findByReference(string $reference, ?int $queueKeyId = null): ?array
    {
        $reference = substr(trim($reference), 0, 190);
        if ($reference === '') {
            return null;
        }

        return $queueKeyId === null
            ? $this->db->one('SELECT * FROM sms_queue WHERE external_ref = ? ORDER BY id DESC LIMIT 1', [$reference])
            : $this->db->one(
                'SELECT * FROM sms_queue WHERE external_ref = ? AND queue_key_id = ? ORDER BY id DESC LIMIT 1',
                [$reference, $queueKeyId]
            );
    }

    /**
     * Espera que a mensagem deixe de estar pendente.
     *
     * Só faz sentido quando o site quer a resposta antes de mostrar nada ao
     * utilizador. Fora disso o envio devolve logo o id e o site consulta o
     * estado quando lhe interessar — um pedido externo não deve ficar preso
     * à espera do modem.
     *
     * @return array{status:string, sent_at:?int, error:?string, attempts:int, settled:bool}
     */
    public function awaitDelivery(int $id, int $maxSeconds): array
    {
        $deadline = time() + $maxSeconds;

        while (true) {
            $row = $this->db->one(
                'SELECT status, sent_at, last_error, attempts FROM sms_queue WHERE id = ?',
                [$id]
            );

            if ($row === null) {
                return ['status' => 'unknown', 'sent_at' => null, 'error' => null, 'attempts' => 0, 'settled' => false];
            }

            $status = (string) $row['status'];
            if ($status === self::STATUS_SENT || $status === self::STATUS_FAILED) {
                return [
                    'status' => $status,
                    'sent_at' => $row['sent_at'] !== null ? (int) $row['sent_at'] : null,
                    'error' => $row['last_error'] !== null ? (string) $row['last_error'] : null,
                    'attempts' => (int) $row['attempts'],
                    'settled' => true,
                ];
            }

            if (time() >= $deadline) {
                return [
                    'status' => $status,
                    'sent_at' => null,
                    'error' => null,
                    'attempts' => (int) $row['attempts'],
                    'settled' => false,
                ];
            }

            usleep(400_000);
        }
    }

    public function pendingCount(?int $deviceId = null): int
    {
        if ($deviceId === null) {
            return (int) $this->db->value('SELECT COUNT(*) FROM sms_queue WHERE status = ?', [self::STATUS_PENDING]);
        }
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM sms_queue WHERE status = ? AND (device_id IS NULL OR device_id = ?)',
            [self::STATUS_PENDING, $deviceId]
        );
    }

    public function requeue(int $id): bool
    {
        return $this->db->run(
            'UPDATE sms_queue SET status = ?, attempts = 0, claimed_at = NULL, claimed_by = NULL, last_error = NULL WHERE id = ?',
            [self::STATUS_PENDING, $id]
        )->rowCount() > 0;
    }

    public function delete(int $id): bool
    {
        return $this->db->run('DELETE FROM sms_queue WHERE id = ?', [$id])->rowCount() > 0;
    }

    public function stats(): array
    {
        $row = $this->db->one(
            "SELECT
                SUM(status = 'pending')    AS pending,
                SUM(status = 'processing') AS processing,
                SUM(status = 'sent')       AS sent,
                SUM(status = 'failed')     AS failed,
                SUM(CASE WHEN status = 'sent' THEN segments ELSE 0 END) AS parts_sent,
                SUM(CASE WHEN status = 'pending' THEN segments ELSE 0 END) AS parts_pending
             FROM sms_queue"
        ) ?? [];

        return [
            'pending' => (int) ($row['pending'] ?? 0),
            'processing' => (int) ($row['processing'] ?? 0),
            'sent' => (int) ($row['sent'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'parts_sent' => (int) ($row['parts_sent'] ?? 0),
            'parts_pending' => (int) ($row['parts_pending'] ?? 0),
        ];
    }
}