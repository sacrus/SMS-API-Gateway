<?php

declare(strict_types=1);

namespace SmsApi;

final class Response
{
    public static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function fromException(ApiException $e): never
    {
        $payload = ['ok' => false, 'error' => $e->errorCode, 'message' => $e->getMessage()];
        if ($e->extra !== []) {
            $payload = array_merge($payload, $e->extra);
        }
        self::json($payload, $e->status);
    }
}