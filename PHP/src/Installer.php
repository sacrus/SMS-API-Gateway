<?php

declare(strict_types=1);

namespace SmsApi;

use PDO;
use PDOException;

/**
 * All installation logic, shared by the browser wizard (install.php) and the
 * command line installer (tests/install-cli.php). Keeping it in one place means
 * both paths validate and persist exactly the same configuration.
 */
final class Installer
{
    /**
     * @return list<array{label:string, value:string, ok:bool, fatal:bool}>
     */
    public static function requirements(string $root): array
    {
        $storage = $root . '/storage';
        $storageWritable = is_dir($storage) && is_writable($storage);
        $drivers = class_exists('PDO') ? PDO::getAvailableDrivers() : [];

        return [
            [
                'label' => 'PHP 8.1 ou superior',
                'value' => PHP_VERSION,
                'ok' => version_compare(PHP_VERSION, '8.1.0', '>='),
                'fatal' => true,
            ],
            [
                'label' => 'Extensão mbstring',
                'value' => extension_loaded('mbstring') ? 'carregada' : 'em falta',
                'ok' => extension_loaded('mbstring'),
                'fatal' => true,
            ],
            [
                'label' => 'Extensão PDO',
                'value' => extension_loaded('pdo') ? 'carregada' : 'em falta',
                'ok' => extension_loaded('pdo'),
                'fatal' => true,
            ],
            [
                'label' => 'Driver PDO SQLite',
                'value' => in_array('sqlite', $drivers, true) ? 'disponível' : 'em falta',
                'ok' => in_array('sqlite', $drivers, true),
                'fatal' => false,
            ],
            [
                'label' => 'Driver PDO MySQL',
                'value' => in_array('mysql', $drivers, true) ? 'disponível' : 'em falta',
                'ok' => in_array('mysql', $drivers, true),
                'fatal' => false,
            ],
            [
                'label' => 'Pasta storage/ gravável',
                'value' => $storageWritable ? 'sim' : 'não',
                'ok' => $storageWritable,
                'fatal' => true,
            ],
            [
                'label' => 'Raiz do gateway gravável',
                'value' => is_writable($root) ? 'sim' : 'não',
                'ok' => is_writable($root),
                'fatal' => true,
            ],
            [
                'label' => 'config.php já existe',
                'value' => Config::exists() ? 'sim (será substituído)' : 'não',
                'ok' => true,
                'fatal' => false,
            ],
        ];
    }

    /** @return list<array> only the fatal, unmet requirements */
    public static function blockers(string $root): array
    {
        return array_values(array_filter(
            self::requirements($root),
            static fn (array $r): bool => !$r['ok'] && $r['fatal']
        ));
    }

    /**
     * @return array normalised database configuration
     */
    public static function normalizeDatabase(string $driver, array $input): array
    {
        $driver = $driver === 'mysql' ? 'mysql' : 'sqlite';

        if ($driver === 'sqlite') {
            $path = trim((string) ($input['sqlite_path'] ?? ''));
            if ($path === '') {
                throw new \RuntimeException('Indique o caminho do ficheiro SQLite.');
            }
            if (!str_ends_with($path, '.sqlite') && !str_ends_with($path, '.db') && !str_ends_with($path, '.sqlite3')) {
                $path .= '.sqlite';
            }
            return ['driver' => 'sqlite', 'sqlite_path' => $path];
        }

        $name = trim((string) ($input['name'] ?? ''));
        $user = trim((string) ($input['user'] ?? ''));

        if ($name === '' || $user === '') {
            throw new \RuntimeException('No MySQL, o nome da base de dados e o utilizador são obrigatórios.');
        }
        if (!preg_match('/^[A-Za-z0-9_$-]+$/', $name)) {
            throw new \RuntimeException('Nome de base de dados inválido.');
        }

        return [
            'driver' => 'mysql',
            'host' => trim((string) ($input['host'] ?? 'localhost')) ?: 'localhost',
            'port' => max(1, min(65535, (int) ($input['port'] ?? 3306))),
            'name' => $name,
            'user' => $user,
            'pass' => (string) ($input['pass'] ?? ''),
            'charset' => 'utf8mb4',
        ];
    }

    /** Connects, creates the schema and throws a readable error on failure. */
    public static function testDatabase(array $dbConfig): void
    {
        try {
            $handle = Database::connect($dbConfig);
            $handle->migrate();
            $handle->run('SELECT 1');
        } catch (\PDOException $e) {
            if ($dbConfig['driver'] === 'mysql') {
                throw new \RuntimeException('MySQL: ' . $e->getMessage() . ' — confirme o nome da base de dados e as permissões do utilizador.', 0, $e);
            }
            throw new \RuntimeException('SQLite: ' . $e->getMessage() . ' — confirme que a pasta storage/ é gravável.', 0, $e);
        }
    }

    /**
     * @return array{user:string, hash:string}
     */
    public static function normalizeAdmin(string $user, string $password, string $confirm): array
    {
        $user = trim($user);
        if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $user)) {
            throw new \RuntimeException('Utilizador inválido: use 3 a 60 letras, números, ponto, traço ou underscore.');
        }
        if (strlen($password) < 10) {
            throw new \RuntimeException('A palavra-passe do administrador tem de ter pelo menos 10 caracteres.');
        }
        if ($password !== $confirm) {
            throw new \RuntimeException('As palavras-passe não coincidem.');
        }

        return ['user' => $user, 'hash' => password_hash($password, PASSWORD_DEFAULT)];
    }

    public static function normalizeApiKey(string $mode, string $custom = ''): string
    {
        if ($mode !== 'custom') {
            return Config::generateApiKey();
        }

        $key = preg_replace('/\s+/', '', $custom) ?? '';
        if (strlen($key) < Config::MIN_KEY_LENGTH) {
            throw new \RuntimeException('A chave personalizada tem de ter pelo menos ' . Config::MIN_KEY_LENGTH . ' caracteres.');
        }
        if (!preg_match('/^[A-Za-z0-9._\-]+$/', $key)) {
            throw new \RuntimeException('A chave só pode conter letras, números, ponto, traço e underscore (é digitada no teclado do telefone).');
        }

        return $key;
    }

    public static function buildConfig(
        array $dbConfig,
        string $adminUser,
        string $adminHash,
        string $apiKey,
        string $baseUrl,
        string $timezone = 'Europe/Lisbon'
    ): array {
        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            $timezone = 'Europe/Lisbon';
        }

        return [
            'app' => [
                'version' => SMSAPI_VERSION,
                'installed_at' => date('c'),
            ],
            'timezone' => $timezone,
            'db' => $dbConfig,
            'api_key' => $apiKey,
            'admin_user' => $adminUser,
            'admin_hash' => $adminHash,
            'public_base_url' => rtrim($baseUrl, '/') . '/public',
            'settings' => [
                'forced_poll_interval' => 0,
                'max_per_poll' => 25,
                'max_segments' => 3,
                'max_attempts' => 3,
                'clock_skew' => 300,
                'rate_limit' => 120,
                'claim_timeout' => 300,
                'online_threshold' => 300,
                'log_retention' => 604800,
                'cron_url' => rtrim($baseUrl, '/') . '/cron/maintain.php',
            ],
        ];
    }

    public static function write(array $config): string
    {
        $path = Config::path();
        $tmp = $path . '.tmp';

        if (file_put_contents($tmp, Config::export($config), LOCK_EX) === false) {
            throw new \RuntimeException("Não foi possível escrever {$path}. Verifique as permissões da pasta.");
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Não foi possível substituir {$path}.");
        }
        @chmod($path, 0640);

        return $path;
    }
}