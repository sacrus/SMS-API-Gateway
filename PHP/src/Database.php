<?php

declare(strict_types=1);

namespace SmsApi;

use PDO;
use PDOStatement;

/**
 * Thin PDO wrapper that speaks both MySQL and SQLite so the same codebase runs
 * on shared hosting and on a laptop with XAMPP.
 */
final class Database
{
    private PDO $pdo;

    private function __construct(PDO $pdo, public readonly string $driver)
    {
        $this->pdo = $pdo;
    }

    /** Public factory, used by the installer, the cron job and the tests. */
    public static function open(array $config): self
    {
        return self::connect($config);
    }

    public static function connect(array $config): self
    {
        $driver = (string) ($config['driver'] ?? 'sqlite');

        if ($driver === 'sqlite') {
            $path = (string) ($config['sqlite_path'] ?? '');
            if ($path === '') {
                throw new \RuntimeException('O caminho do ficheiro SQLite não foi indicado.');
            }
            $directory = dirname($path);
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new \RuntimeException("Não foi possível criar a pasta de dados: {$directory}");
            }

            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA busy_timeout = 8000');
            $pdo->exec('PRAGMA foreign_keys = ON');

            return new self($pdo, 'sqlite');
        }

        $charset = (string) ($config['charset'] ?? 'utf8mb4');
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string) ($config['host'] ?? 'localhost'),
            (int) ($config['port'] ?? 3306),
            (string) ($config['name'] ?? ''),
            $charset
        );

        $pdo = new PDO($dsn, (string) ($config['user'] ?? ''), (string) ($config['pass'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $pdo->exec("SET NAMES {$charset} COLLATE {$charset}_unicode_ci");

        return new self($pdo, 'mysql');
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function exec(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    public function run(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement;
    }

    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function value(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    public function insert(string $sql, array $params = []): int
    {
        $this->run($sql, $params);
        return (int) $this->pdo->lastInsertId();
    }

    /** @template T */
    public function transaction(callable $work): mixed
    {
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $work($this);
            if ($own) {
                $this->pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * MySQL has no CREATE INDEX IF NOT EXISTS, so check first. Also covers
     * MariaDB and SQLite with the same code path.
     */
    private function indexExists(string $table, string $index): bool
    {
        if ($this->driver === 'sqlite') {
            return (int) $this->value(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = ?",
                [$index]
            ) > 0;
        }

        return (int) $this->value(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $index]
        ) > 0;
    }

    private function createIndex(string $table, string $index, string $columns, bool $unique = false): void
    {
        if ($this->indexExists($table, $index)) {
            return;
        }

        // MySQL rejects the IF NOT EXISTS clause, SQLite accepts it.
        $ifNotExists = $this->driver === 'sqlite' ? 'IF NOT EXISTS ' : '';

        try {
            $this->exec(sprintf(
                'CREATE %sINDEX %s%s ON %s (%s)',
                $unique ? 'UNIQUE ' : '',
                $ifNotExists,
                $index,
                $table,
                $columns
            ));
        } catch (PDOException $e) {
            // Two requests racing on a fresh install: the other one won.
            if (!str_contains(strtolower($e->getMessage()), 'exist')
                && !str_contains(strtolower($e->getMessage()), 'duplicate')) {
                throw $e;
            }
        }
    }

    /**
     * ALTER TABLE ADD COLUMN has no IF NOT EXISTS, so check first. This is what
     * lets an existing installation pick up new columns on the next request.
     */
    public function addColumnIfMissing(string $table, string $column, string $definition): bool
    {
        if ($this->columnExists($table, $column)) {
            return false;
        }

        try {
            $this->exec(sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $column, $definition));
            return true;
        } catch (PDOException $e) {
            // A concurrent request may have added it first.
            if ($this->columnExists($table, $column)) {
                return false;
            }
            throw $e;
        }
    }

    public function columnExists(string $table, string $column): bool
    {
        if ($this->driver === 'sqlite') {
            return (int) $this->value(
                'SELECT COUNT(*) FROM pragma_table_info(?) WHERE name = ?',
                [$table, $column]
            ) > 0;
        }

        return (int) $this->value(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        ) > 0;
    }

    /**
     * Creates every table if missing. Safe to run on every request, so a deploy
     * never needs a separate migration step.
     *
     * @return list<string> the table names that exist after the call
     */
    public function migrate(): array
    {
        $pk = $this->driver === 'sqlite'
            ? 'INTEGER PRIMARY KEY AUTOINCREMENT'
            : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';

        $suffix = $this->driver === 'sqlite'
            ? ''
            : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $tables = [
            'devices' => "CREATE TABLE IF NOT EXISTS devices (
                id {$pk},
                token_hash VARCHAR(64) NOT NULL,
                token_prefix VARCHAR(16) NOT NULL DEFAULT '',
                device_name VARCHAR(190) NOT NULL DEFAULT '',
                manufacturer VARCHAR(120) NOT NULL DEFAULT '',
                model VARCHAR(120) NOT NULL DEFAULT '',
                os_version VARCHAR(60) NOT NULL DEFAULT '',
                app_version VARCHAR(60) NOT NULL DEFAULT '',
                install_id_hash VARCHAR(64) NULL,
                sim_label VARCHAR(120) NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                notes TEXT NULL,
                created_at BIGINT NOT NULL,
                last_seen_at BIGINT NULL,
                last_ip VARCHAR(45) NULL
            ){$suffix}",

            'sms_queue' => "CREATE TABLE IF NOT EXISTS sms_queue (
                id {$pk},
                device_id BIGINT NULL,
                phone VARCHAR(64) NOT NULL,
                body TEXT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                attempts INT NOT NULL DEFAULT 0,
                max_attempts INT NOT NULL DEFAULT 3,
                claimed_at BIGINT NULL,
                claimed_by BIGINT NULL,
                created_at BIGINT NOT NULL,
                sent_at BIGINT NULL,
                last_error TEXT NULL,
                external_ref VARCHAR(190) NULL,
                encoding VARCHAR(8) NOT NULL DEFAULT 'gsm7',
                segments INT NOT NULL DEFAULT 1
            ){$suffix}",

            'nonces' => "CREATE TABLE IF NOT EXISTS nonces (
                nonce VARCHAR(64) NOT NULL PRIMARY KEY,
                created_at BIGINT NOT NULL
            ){$suffix}",

            'api_log' => "CREATE TABLE IF NOT EXISTS api_log (
                id {$pk},
                created_at BIGINT NOT NULL,
                endpoint VARCHAR(40) NOT NULL DEFAULT '',
                device_id BIGINT NULL,
                ip VARCHAR(45) NOT NULL DEFAULT '',
                code INT NOT NULL DEFAULT 0,
                message VARCHAR(255) NULL
            ){$suffix}",

            // Chaves de submissao para sites e aplicacoes externas. key_hash e
            // SHA-256: a chave em claro mostra-se uma unica vez, na criacao, e
            // nao volta a ser guardada.
            'queue_keys' => "CREATE TABLE IF NOT EXISTS queue_keys (
                id {$pk},
                name VARCHAR(120) NOT NULL DEFAULT '',
                key_hash VARCHAR(64) NOT NULL,
                key_prefix VARCHAR(24) NOT NULL DEFAULT '',
                ip_allowlist TEXT NULL,
                rate_per_min INT NOT NULL DEFAULT 60,
                max_per_day INT NOT NULL DEFAULT 0,
                wait_seconds INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                total_sent INT NOT NULL DEFAULT 0,
                created_at BIGINT NOT NULL,
                last_used_at BIGINT NULL,
                last_used_ip VARCHAR(45) NULL
            ){$suffix}",
        ];

        $indexes = [
            ['devices', 'idx_devices_token', 'token_hash', true],
            ['devices', 'idx_devices_install', 'install_id_hash', false],
            ['sms_queue', 'idx_queue_pick', 'status, device_id, id', false],
            ['sms_queue', 'idx_queue_ref', 'external_ref', false],
            ['api_log', 'idx_log_created', 'created_at', false],
            ['queue_keys', 'idx_qkey_hash', 'key_hash', true],
            // O limite de ritmo por chave conta linhas de api_log; sem este
            // indice cada pedido faria uma varredura da tabela.
            ['api_log', 'idx_log_key', 'key_id, created_at', false],
            ['sms_queue', 'idx_queue_key', 'queue_key_id, id', false],
        ];

        foreach ($tables as $sql) {
            $this->exec($sql);
        }

        // Antes dos índices: um índice sobre uma coluna que ainda não existe
        // rebenta numa instalação anterior, e createIndex propaga o erro.
        $this->addColumnIfMissing('sms_queue', 'encoding', "VARCHAR(8) NOT NULL DEFAULT 'gsm7'");
        $this->addColumnIfMissing('sms_queue', 'segments', 'INT NOT NULL DEFAULT 1');
        $this->addColumnIfMissing('sms_queue', 'queue_key_id', 'BIGINT NULL');
        $this->addColumnIfMissing('devices', 'sim_label', 'VARCHAR(120) NULL');
        $this->addColumnIfMissing('api_log', 'key_id', 'BIGINT NULL');

        foreach ($indexes as [$table, $index, $columns, $unique]) {
            $this->createIndex($table, $index, $columns, $unique);
        }

        return array_keys($tables);
    }

    /** Removes nonces and log rows older than the given TTL, in seconds. */
    public function purge(int $nonceTtl, int $logTtl): void
    {
        $now = time();
        $this->run('DELETE FROM nonces WHERE created_at < ?', [$now - $nonceTtl]);
        $this->run('DELETE FROM api_log WHERE created_at < ?', [$now - $logTtl]);
    }
}