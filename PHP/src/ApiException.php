<?php

declare(strict_types=1);

namespace SmsApi;

/**
 * Thrown by the request pipeline. Rendered as a JSON error body by Response::fromException().
 */
final class ApiException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly array $extra = []
    ) {
        parent::__construct($message);
    }

    public static function badRequest(string $message, string $code = 'bad_request'): self
    {
        return new self($code, $message, 400);
    }

    public static function unauthorized(string $message = 'Pedido não autenticado.', string $code = 'unauthorized'): self
    {
        return new self($code, $message, 401);
    }

    public static function forbidden(string $message = 'Dispositivo não autorizado.', string $code = 'device_revoked'): self
    {
        return new self($code, $message, 403);
    }

    public static function notFound(string $message = 'Recurso inexistente.', string $code = 'not_found'): self
    {
        return new self($code, $message, 404);
    }

    public static function tooMany(string $message = 'Demasiados pedidos.', string $code = 'rate_limited'): self
    {
        return new self($code, $message, 429);
    }
}