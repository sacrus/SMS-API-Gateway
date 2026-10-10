<?php

declare(strict_types=1);

/**
 * Shared entry point for both the public API and the admin panel.
 */

/**
 * Renders a self-contained error page. Used when the environment is too broken
 * to boot the application (missing extensions, missing config) — a blank white
 * page would tell the administrator nothing.
 */
function smsapi_fatal(string $title, array $lines, int $status = 500): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
    }

    $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    echo '<!DOCTYPE html><html lang="pt"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . $escape($title) . ' · SMSAPI Gateway</title>';
    echo '<style>
        body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
             background:#0f1720;color:#e6edf5;padding:24px;
             font:15px/1.6 -apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
        .box{max-width:640px;background:#172231;border:1px solid #2a3b52;border-radius:14px;padding:28px}
        h1{margin:0 0 6px;font-size:20px}
        p{margin:0 0 12px;color:#93a4b8}
        ul{margin:0;padding-left:20px;color:#e6edf5}
        li{margin:6px 0}
        code{background:#0f1720;padding:2px 6px;border-radius:4px;font-family:Consolas,monospace;font-size:13px}
        strong{color:#fbbf24}
    </style></head><body><div class="box">';
    echo '<h1>' . $escape($title) . '</h1>';
    echo '<p>Não foi possível arrancar o SMSAPI Gateway neste servidor.</p><ul>';
    foreach ($lines as $line) {
        echo '<li>' . $line . '</li>';
    }
    echo '</ul></div></body></html>';
    exit;
}

$missing = [];
if (!extension_loaded('mbstring')) {
    $missing[] = 'mbstring — funções de texto multibyte (obrigatória)';
}
if (!extension_loaded('pdo')) {
    $missing[] = 'pdo — acesso à base de dados (obrigatória)';
}
if (!extension_loaded('pdo_sqlite') && !extension_loaded('pdo_mysql')) {
    $missing[] = 'pdo_sqlite ou pdo_mysql — pelo menos um driver (obrigatória)';
}

if ($missing !== []) {
    smsapi_fatal(
        'Faltam extensões do PHP',
        array_merge(
            array_map(static fn (string $item): string => '<code>' . htmlspecialchars($item, ENT_QUOTES) . '</code>', $missing),
            ['Edite o ficheiro <code>php.ini</code> do PHP e remova o <code>;</code> à frente das linhas <code>extension=</code> acima.',
             'Versão de PHP em execução: <code>' . PHP_VERSION . '</code>']
        ),
        500
    );
}

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'SmsApi\\')) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen('SmsApi\\')));
    $file = __DIR__ . '/' . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

define('SMSAPI_ROOT', dirname(__DIR__));
define('SMSAPI_VERSION', '1.0.0');

mb_internal_encoding('UTF-8');

/**
 * O PHP anuncia a sua versão em todos os respostas com X-Powered-By.
 *
 * Não é uma falha grave, mas é dizer a quem está a tentar entrar
 * exactamente qual a versão e, com isso, quais as falhas conhecidas dessa
 * versão. Tira-se com uma linha; se a versão estiver escondida no php.ini
 * (expose_php = Off) não há nada a fazer.
 */
if (PHP_SAPI !== 'cli' && headers_sent() === false) {
    header_remove('X-Powered-By');
}
date_default_timezone_set('Europe/Lisbon');

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');