<?php

declare(strict_types=1);

namespace SmsApi;

/**
 * Small, dependency-free HTTP helpers: JSON in, JSON out.
 */
final class Request
{
    private ?array $json = null;

    public function __construct(
        public readonly string $method,
        public readonly string $endpoint,
        public readonly string $rawBody,
        public readonly array $headers,
        public readonly array $query,
        public readonly array $server
    ) {
    }

    public static function capture(): self
    {
        $raw = (string) file_get_contents('php://input');
        $headers = self::collectHeaders();

        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $segments = array_values(array_filter(explode('/', $path), 'strlen'));
        $endpoint = $segments !== [] ? strtolower((string) end($segments)) : '';

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $endpoint,
            $raw,
            $headers,
            $_GET,
            $_SERVER
        );
    }

    /** Lower-cased header names, so lookups are case-insensitive as per RFC 9110. */
    private static function collectHeaders(): array
    {
        $out = [];
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                $out[strtolower((string) $name)] = (string) $value;
            }
        }
        foreach ($_SERVER as $key => $value) {
            if (!str_starts_with((string) $key, 'HTTP_')) {
                continue;
            }
            $name = strtolower(str_replace('_', '-', substr((string) $key, 5)));
            $out[$name] = $out[$name] ?? (string) $value;
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $out['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        return $out;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function ip(): string
    {
        $forwarded = $this->header('x-forwarded-for');
        if ($forwarded !== null && $forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
    }

    /**
     * Corpo JSON já interpretado.
     *
     * Só se tenta interpretar como JSON quando o pedido o declara ou quando
     * o corpo claramente começa como objeto ou lista. Sem esta distinção, um
     * corpo form-urlencoded era rejeitado com "invalid_json" e uma integração
     * externa simples em cURL deixava de funcionar.
     */
    public function json(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }
        if (trim($this->rawBody) === '') {
            return $this->json = [];
        }

        $declaresJson = str_contains(
            strtolower((string) $this->header('content-type', '')),
            'json'
        );
        $looksLikeJson = str_starts_with(trim($this->rawBody), '{')
            || str_starts_with(trim($this->rawBody), '[');

        if (!$declaresJson && !$looksLikeJson) {
            return $this->json = [];
        }

        $decoded = json_decode($this->rawBody, true);
        if (!is_array($decoded)) {
            throw ApiException::badRequest('Corpo JSON inválido.', 'invalid_json');
        }
        return $this->json = $decoded;
    }

    /**
     * Lê um campo do corpo.
     *
     * Aceita JSON (o que a app Android usa), form-urlencoded e query string,
     * para que uma integração externa possa mandar um curl simples sem
     * seis linhas de PHP a construir o JSON.
     */
    public function field(string $name, mixed $default = null): mixed
    {
        $body = $this->json();
        if (array_key_exists($name, $body)) {
            return $body[$name];
        }
        if (array_key_exists($name, $_POST)) {
            return $_POST[$name];
        }
        if (array_key_exists($name, $this->query)) {
            return $this->query[$name];
        }
        return $default;
    }

    public function isHttps(): bool
    {
        $https = (string) ($_SERVER['HTTPS'] ?? '');
        if ($https !== '' && strtolower($https) !== 'off') {
            return true;
        }
        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }
        return strtolower((string) $this->header('x-forwarded-proto', '')) === 'https';
    }
}