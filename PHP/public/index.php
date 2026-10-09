<?php

declare(strict_types=1);

/**
 * SMSAPI Gateway — public API endpoint.
 *
 * The Android app polls this file (not the whole directory). Point the app at:
 *   https://your-domain.tld/smsapi/public/
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use SmsApi\ApiException;
use SmsApi\App;
use SmsApi\Auth;
use SmsApi\Database;
use SmsApi\Queue;
use SmsApi\Request;
use SmsApi\Response;

if (!App::isInstalled()) {
    Response::json([
        'ok' => false,
        'error' => 'not_installed',
        'message' => 'Gateway ainda não instalado. Abra install.php no navegador.',
    ], 503);
}

$request = Request::capture();
$deviceId = null;
$queueKey = null;
$queueKeyId = null;

try {
    $db = App::db();
    $auth = App::auth();
    $config = App::config();
    $queue = App::queue();

    // Keep the schema current on every deploy; both statements are no-ops if present.
    $db->migrate();

    $authMode = 'device';

    switch ($request->endpoint) {
        case 'register':
            $requiresDevice = false;
            $allowMethods = ['POST'];
            break;

        case 'heartbeat':
            $requiresDevice = true;
            $allowMethods = ['GET', 'POST', 'HEAD'];
            break;

        case 'poll':
            $requiresDevice = true;
            $allowMethods = ['GET', 'POST'];
            break;

        case 'ack':
            $requiresDevice = true;
            $allowMethods = ['POST'];
            break;

        case 'fail':
            $requiresDevice = true;
            $allowMethods = ['POST'];
            break;

        // Integracoes externas. Nao usam a chave mestra nem o handshake HMAC:
        // chega uma chave propria, no cabecalho. Sao os unicos endpoints que
        // nao exigem token de dispositivo.
        case 'send':
            $authMode = 'queue';
            $allowMethods = ['POST'];
            break;

        case 'status':
            $authMode = 'queue';
            $allowMethods = ['GET', 'POST'];
            break;

        default:
            throw ApiException::notFound(
                'Endpoint desconhecido: /' . $request->endpoint,
                'unknown_endpoint'
            );
    }

    if (!in_array($request->method, $allowMethods, true)) {
        throw new ApiException('method_not_allowed', 'Método HTTP não permitido.', 405);
    }

    if ($authMode === 'queue') {
        guardTransport($request, $config);
        $queueKey = App::queueKeys()->authenticate($request);
        $queueKeyId = (int) $queueKey['id'];
    } else {
        $authResult = $auth->authenticate($request, $requiresDevice);
        $device = $authResult['device'];
        if ($device !== null) {
            $deviceId = (int) $device['id'];
        }
    }

    $serverInfo = [
        'server_time' => time(),
        'app_version' => SMSAPI_VERSION,
        'poll_interval' => (int) ($config['settings']['forced_poll_interval'] ?? 0),
        'max_per_poll' => (int) ($config['settings']['max_per_poll'] ?? 25),
    ];

    switch ($request->endpoint) {
        case 'register':
            $payload = registerDevice($request, $db);
            break;

        case 'heartbeat':
            $sim = trim((string) $request->field('sim_label', ''));
            if ($sim !== '') {
                $db->run('UPDATE devices SET sim_label = ? WHERE id = ?', [mb_substr($sim, 0, 120), $deviceId]);
            }
            $payload = array_merge($serverInfo, [
                'ok' => true,
                'device_id' => $deviceId,
                'pending' => $queue->pendingCount($deviceId),
                'registered_at' => (int) $device['created_at'],
                'max_segments' => (int) App::setting('max_segments', 3),
            ]);
            break;

        case 'poll':
            $limit = (int) ($request->field('limit') ?? $request->query['limit'] ?? 10);
            $messages = $queue->claim($deviceId, $limit);
            $payload = array_merge($serverInfo, [
                'ok' => true,
                'count' => count($messages),
                'messages' => array_map(static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'phone' => (string) $row['phone'],
                    'body' => (string) $row['body'],
                    'attempts' => (int) $row['attempts'],
                    'created_at' => (int) $row['created_at'],
                    'reference' => $row['external_ref'],
                    'encoding' => (string) ($row['encoding'] ?? 'gsm7'),
                    'segments' => (int) ($row['segments'] ?? 1),
                ], $messages),
            ]);
            break;

        case 'ack':
            $ids = $request->field('ids', []);
            if (!is_array($ids)) {
                throw ApiException::badRequest('O campo "ids" tem de ser uma lista.', 'invalid_ids');
            }
            $acknowledged = $queue->ack($deviceId, $ids);
            $payload = array_merge($serverInfo, [
                'ok' => true,
                'acknowledged' => $acknowledged,
            ]);
            break;

        case 'fail':
            $id = (int) $request->field('id', 0);
            if ($id <= 0) {
                throw ApiException::badRequest('O campo "id" é obrigatório.', 'invalid_id');
            }
            $reason = (string) $request->field('reason', 'falha desconhecida');
            $outcome = $queue->fail($deviceId, $id, $reason);
            $payload = array_merge($serverInfo, [
                'ok' => true,
                'id' => $id,
                'status' => $outcome['status'],
                'attempts' => $outcome['attempts'],
            ]);
            break;

        case 'send':
            $payload = array_merge($serverInfo, submitMessage($request, $queue, $queueKey, $queueKeyId, $db));
            break;

        case 'status':
            $payload = array_merge($serverInfo, messageStatus($request, $queue, (int) $queueKeyId));
            break;

        default:
            throw ApiException::notFound();
    }

    App::logRequest($request->endpoint, $deviceId, $request->ip(), 200, null, $queueKeyId);
    Response::json($payload, 200);
} catch (ApiException $e) {
    App::logRequest($request->endpoint, $deviceId, $request->ip(), $e->status, $e->getMessage(), $queueKeyId);
    Response::fromException($e);
} catch (Throwable $e) {
    App::logRequest($request->endpoint, $deviceId, $request->ip(), 500, $e->getMessage(), $queueKeyId);
    Response::json([
        'ok' => false,
        'error' => 'server_error',
        'message' => 'Erro interno. Consulte o registo do servidor.',
    ], 500);
}

/**
 * A chave da API é um segredo de envio de SMS: em HTTP simples era lida por
 * qualquer pessoa na mesma rede.
 *
 * Só se exige quando o próprio gateway está configurado com HTTPS — é esse o
 * sinal de que o senhor montou isto para produção. Numa instalação em
 * http://192.168.x.x, para testar, o aviso não impede o trabalho.
 */
function guardTransport(Request $request, array $config): void
{
    $baseUrl = (string) ($config['public_base_url'] ?? '');
    if (!str_starts_with(strtolower($baseUrl), 'https://')) {
        return;
    }
    if ($request->isHttps()) {
        return;
    }

    throw new ApiException(
        'https_required',
        'Esta chamada tem de ser feita por HTTPS: a chave seria enviada em claro.',
        400
    );
}

/**
 * Submete um SMS a partir de uma integração externa.
 *
 * A espera pela entrega é opt-in por chave: o valor configurado na chave é o
 * tecto e o pedido só pode pedir menos. Assim uma integração que não usa o
 * resultado não fica com a ligação presa à espera do modem.
 */
function submitMessage(Request $request, Queue $queue, array $queueKey, int $queueKeyId, Database $db): array
{
    $to = trim((string) $request->field('to', $request->field('phone', '')));
    $text = (string) $request->field('text', $request->field('body', ''));
    $reference = trim((string) $request->field('ref', $request->field('reference', '')));
    $toGsm7 = filter_var($request->field('gsm7', false), FILTER_VALIDATE_BOOLEAN);

    $deviceId = null;
    $rawDevice = $request->field('device_id', null);
    if ($rawDevice !== null && $rawDevice !== '' && $rawDevice !== 0 && $rawDevice !== '0') {
        $deviceId = (int) $rawDevice;
        if ($deviceId <= 0) {
            throw ApiException::badRequest('O campo "device_id" tem de ser um número.', 'invalid_device_id');
        }
        if ($db->one('SELECT id FROM devices WHERE id = ?', [$deviceId]) === null) {
            throw ApiException::badRequest('Telefone desconhecido.', 'unknown_device');
        }
    }

    $result = $queue->enqueue(
        $to,
        $text,
        $deviceId,
        $reference !== '' ? $reference : null,
        $toGsm7,
        $queueKeyId
    );

    App::queueKeys()->countSent($queueKeyId);

    $payload = [
        'ok' => true,
        'id' => $result['id'],
        'status' => Queue::STATUS_PENDING,
        'ref' => $reference !== '' ? $reference : null,
        'encoding' => $result['encoding'],
        'segments' => $result['segments'],
        'created_at' => time(),
    ];

    if ($result['report'] !== null) {
        $payload['normalization'] = $result['report'];
    }

    $keyWait = (int) ($queueKey['wait_seconds'] ?? 0);
    $requested = (int) $request->field('wait', $keyWait);
    $wait = max(0, min($keyWait, $requested));

    if ($wait > 0) {
        $outcome = $queue->awaitDelivery($result['id'], $wait);
        $payload['status'] = $outcome['status'];
        $payload['result'] = [
            'status' => $outcome['status'],
            'settled' => $outcome['settled'],
            'attempts' => $outcome['attempts'],
            'sent_at' => $outcome['sent_at'],
            'error' => $outcome['error'],
        ];
    }

    return $payload;
}

/**
 * Estado de uma mensagem submetida por esta chave.
 *
 * Uma falha de entrega responde 200 com "sent": false e o erro dentro. Não é
 * um erro do pedido — a mensagem foi aceite e o problema é do modem ou da
 * rede. Responder 4xx/5xx faria o site repetir o envio e duplicar o SMS.
 */
function messageStatus(Request $request, Queue $queue, int $queueKeyId): array
{
    $id = (int) $request->field('id', 0);
    $reference = trim((string) $request->field('ref', $request->field('reference', '')));

    if ($id <= 0 && $reference === '') {
        throw ApiException::badRequest(
            'Indique "id" ou "ref" para consultar uma mensagem.',
            'missing_lookup'
        );
    }

    $row = $id > 0
        ? $queue->find($id, $queueKeyId)
        : $queue->findByReference($reference, $queueKeyId);

    if ($row === null) {
        throw ApiException::notFound(
            'Mensagem não encontrada para esta chave.',
            'message_not_found'
        );
    }

    $status = (string) $row['status'];

    return [
        'ok' => true,
        'id' => (int) $row['id'],
        'ref' => $row['external_ref'],
        'to' => (string) $row['phone'],
        'status' => $status,
        'settled' => in_array($status, [Queue::STATUS_SENT, Queue::STATUS_FAILED], true),
        'attempts' => (int) $row['attempts'],
        'segments' => (int) $row['segments'],
        'encoding' => (string) $row['encoding'],
        'created_at' => (int) $row['created_at'],
        'sent_at' => $row['sent_at'] !== null ? (int) $row['sent_at'] : null,
        'error' => $row['last_error'] !== null ? (string) $row['last_error'] : null,
    ];
}

/**
 * Issues (or rotates) the device token. Re-registering with a known install id
 * silently revokes the previous token, which is what happens after a reinstall.
 */
function registerDevice(Request $request, Database $db): array
{
    $name = trim((string) $request->field('device_name', '')) ?: 'Telefone sem nome';
    if (mb_strlen($name) > 190) {
        $name = mb_substr($name, 0, 190);
    }

    $installId = trim((string) $request->field('install_id', ''));
    $installHash = $installId !== '' ? hash('sha256', $installId) : null;
    $simLabel = mb_substr(trim((string) $request->field('sim_label', '')), 0, 120);

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $tokenPrefix = substr($token, 0, 8);
    $now = time();

    $existing = $installHash !== null
        ? $db->one('SELECT * FROM devices WHERE install_id_hash = ? ORDER BY id DESC LIMIT 1', [$installHash])
        : null;

    if ($existing !== null) {
        $deviceId = (int) $existing['id'];
        $db->run(
            'UPDATE devices
             SET token_hash = ?, token_prefix = ?, device_name = ?, manufacturer = ?, model = ?,
                 os_version = ?, app_version = ?, sim_label = ?, is_active = 1, last_seen_at = ?
             WHERE id = ?',
            [
                $tokenHash,
                $tokenPrefix,
                $name,
                substr((string) $request->field('manufacturer', ''), 0, 120),
                substr((string) $request->field('model', ''), 0, 120),
                substr((string) $request->field('os_version', ''), 0, 60),
                substr((string) $request->field('app_version', ''), 0, 60),
                $simLabel !== '' ? $simLabel : ($existing['sim_label'] ?? null),
                $now,
                $deviceId,
            ]
        );
    } else {
        $deviceId = $db->insert(
            'INSERT INTO devices
                (token_hash, token_prefix, device_name, manufacturer, model, os_version, app_version,
                 sim_label, install_id_hash, is_active, created_at, last_seen_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)',
            [
                $tokenHash,
                $tokenPrefix,
                $name,
                substr((string) $request->field('manufacturer', ''), 0, 120),
                substr((string) $request->field('model', ''), 0, 120),
                substr((string) $request->field('os_version', ''), 0, 60),
                substr((string) $request->field('app_version', ''), 0, 60),
                $simLabel !== '' ? $simLabel : null,
                $installHash,
                $now,
                $now,
            ]
        );
    }

    return [
        'ok' => true,
        'device_id' => $deviceId,
        'device_token' => $token,
        'server_time' => $now,
        'app_version' => SMSAPI_VERSION,
        'poll_interval' => (int) (App::setting('forced_poll_interval', 0)),
        'max_per_poll' => (int) (App::setting('max_per_poll', 25)),
    ];
}