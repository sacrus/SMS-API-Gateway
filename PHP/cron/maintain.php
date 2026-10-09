<?php

declare(strict_types=1);

/**
 * Manutenção periódica. Ligue-o ao cron do alojamento (a cada 15 minutos serve):
 *
 *   [cron] a cada 15 min -> /usr/bin/php /var/www/html/smsapi/cron/maintain.php
 *
 * Em XAMPP/Windows pode usar o Agendador de Tarefas, ou deixar sem cron: o
 * servidor recupera mensagens presas por conta própria a cada pedido.
 *
 * Também pode ser aberto pelo browser; devolve 403 a partir de outro endereço.
 */

if (PHP_SAPI !== 'cli' && !in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit("403 - executar apenas via linha de comandos.\n");
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

use SmsApi\App;

try {
    $db = App::db();
    $db->migrate();

    $claimTimeout = (int) App::setting('claim_timeout', 300);
    $now = time();

    // 1. Devolve a fila as mensagens que um telefone nunca confirmou.
    $recovered = $db->run(
        "UPDATE sms_queue SET status = 'pending', claimed_at = NULL, claimed_by = NULL
         WHERE status = 'processing' AND claimed_at IS NOT NULL AND claimed_at < ?",
        [$now - $claimTimeout]
    )->rowCount();

    // 2. Limpa nonces ja expirados e trim do registo de API.
    $db->purge(
        (int) App::setting('clock_skew', 300) * 4,
        (int) App::setting('log_retention', 604800)
    );

    // 3. Purga registos de SMS ja entregues com mais de 30 dias.
    $purged = $db->run(
        "DELETE FROM sms_queue WHERE status = 'sent' AND sent_at IS NOT NULL AND sent_at < ?",
        [$now - 2592000]
    )->rowCount();

    printf(
        "[%s] concluído: %dclaimed devolvidos, %d registos antigos removidos.\n",
        gmdate('Y-m-d H:i:s'),
        $recovered,
        $purged
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'erro: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}