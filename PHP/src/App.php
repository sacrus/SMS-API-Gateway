<?php

declare(strict_types=1);

namespace SmsApi;

/**
 * Lazily-built container so every entry point shares the same config,
 * database connection and queue instance.
 */
final class App
{
    private static ?array $config = null;
    private static ?Database $db = null;
    private static ?Queue $queue = null;
    private static ?Auth $auth = null;
    private static ?QueueKeys $queueKeys = null;

    public static function config(): array
    {
        if (self::$config === null) {
            $config = Config::load();
            if ($config === null) {
                throw new \RuntimeException('config.php em falta. Execute install.php para instalar o gateway.');
            }
            date_default_timezone_set((string) ($config['timezone'] ?? 'Europe/Lisbon'));
            self::$config = $config;
        }
        return self::$config;
    }

    public static function isInstalled(): bool
    {
        return Config::exists();
    }

    public static function db(): Database
    {
        return self::$db ??= Database::connect(self::config()['db']);
    }

    public static function queue(): Queue
    {
        return self::$queue ??= new Queue(self::db(), self::config());
    }

    public static function auth(): Auth
    {
        return self::$auth ??= new Auth(self::db(), self::config());
    }

    public static function queueKeys(): QueueKeys
    {
        return self::$queueKeys ??= new QueueKeys(self::db(), self::config());
    }

    public static function setting(string $key, mixed $default = null): mixed
    {
        return self::config()['settings'][$key] ?? $default;
    }

    /**
     * @param int|null $keyId which external integration made the request, so
     *        the per-key rate limit can count it
     */
    public static function logRequest(
        string $endpoint,
        ?int $deviceId,
        string $ip,
        int $code,
        ?string $message = null,
        ?int $keyId = null
    ): void {
        try {
            self::db()->run(
                'INSERT INTO api_log (created_at, endpoint, device_id, ip, code, message, key_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    time(),
                    substr($endpoint, 0, 40),
                    $deviceId,
                    $ip,
                    $code,
                    $message === null ? null : substr($message, 0, 255),
                    $keyId,
                ]
            );
        } catch (\Throwable) {
            // Logging must never break the request.
        }
    }
}