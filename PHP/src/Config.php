<?php

declare(strict_types=1);

namespace SmsApi;

final class Config
{
    public const MIN_KEY_LENGTH = 24;

    public static function path(): string
    {
        return dirname(__DIR__) . '/config.php';
    }

    public static function exists(): bool
    {
        return is_file(self::path());
    }

    /** @return array|null null when the file is missing or broken */
    public static function load(): ?array
    {
        if (!self::exists()) {
            return null;
        }
        /** @psalm-suppress UnresolvableInclude */
        $data = require self::path();
        return is_array($data) ? $data : null;
    }

    public static function generateApiKey(): string
    {
        return 'sms_' . bin2hex(random_bytes(24));
    }

    /** Serialises the config into a PHP file that returns an array. */
    public static function export(array $config): string
    {
        return "<?php" . PHP_EOL
            . "declare(strict_types=1);" . PHP_EOL . PHP_EOL
            . "// Gerado automaticamente pelo instalador do SMSAPI Gateway." . PHP_EOL
            . "// Não edite à mão enquanto o serviço estiver em produção." . PHP_EOL . PHP_EOL
            . 'return ' . self::prettyPrint($config) . ';' . PHP_EOL;
    }

    private static function prettyPrint(mixed $value, int $depth = 0): string
    {
        $indent = str_repeat('    ', $depth + 1);
        $closing = str_repeat('    ', $depth);

        if (is_array($value)) {
            if ($value === []) {
                return '[]';
            }
            $lines = [];
            $isList = array_is_list($value);
            foreach ($value as $key => $item) {
                $prefix = $isList ? '' : var_export((string) $key, true) . ' => ';
                $lines[] = $indent . $prefix . self::prettyPrint($item, $depth + 1) . ',';
            }
            return "[\n" . implode("\n", $lines) . "\n" . $closing . ']';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return var_export((string) $value, true);
    }
}