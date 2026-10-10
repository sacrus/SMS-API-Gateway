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

    /**
     * A pasta de dados está acessível pela web?
     *
     * O ficheiro SQLite tem dentro os hashes das palavras-passe, a chave da
     * API, os números de telefone e o texto das mensagens. Se alguém o
     * conseguir descarregar, o sistema está perdido — não há nada a fazer do
     * lado do PHP, porque quem serve o ficheiro é o servidor web, não nós.
     *
     * Esta verificação é feita sem rede e sem pedido ao próprio site, por duas
     * razões. Primeiro, o PHP não sabe se o .htaccess está a ser respeitado:
     * em Apache com AllowOverride None o ficheiro é servido na mesma, e um
     * pedido de teste dariia uma falsa segurança. Segundo, pedir o ficheiro ao
     * próprio servidor a partir de um pedido que ele está a atender trava o
     * pedido até ao timeout em servidores de um só processo, e ainda dava a
     * quem_controls o Host a escolher o endereço do pedido.
     *
     * Decide-se pela configuração visível: o ficheiro está dentro da pasta
     * pública, existe um .htaccess que nega o acesso e o servidor é Apache?
     *
     * `exposed` true é nunca um palpite: só acontece quando se vê que o ficheiro
     * está na pasta pública e não há nada que o negue. O caso Apache com
     * .htaccess devolve `exposed` false mas `unconfirmed` true, porque o PHP não
     * lê o AllowOverride e não quer dar um falso silêncio justamente onde o
     * ficheiro costuma estar exposto.
     *
     * @return array{checked:bool, exposed:bool, unconfirmed:bool, url:string, note:?string}
     */
    public static function storageExposed(string $root, array $dbConfig, ?string $baseUrl = null): array
    {
        $result = [
            'checked' => false,
            'exposed' => false,
            'unconfirmed' => false,
            'url' => '',
            'note' => null,
        ];

        if (($dbConfig['driver'] ?? '') !== 'sqlite') {
            // Em MySQL o ficheiro não está na pasta pública.
            return $result;
        }

        $path = (string) ($dbConfig['sqlite_path'] ?? '');
        if ($path === '') {
            return $result;
        }

        // O caminho pode estar guardado como relativo ao gateway. Resolve-se
        // contra a pasta do gateway, que é como a aplicação o abre quando o
        // servidor web executa o pedido — resolver contra o directório de
        // trabalho daria um caminho diferente.
        if (!self::isAbsolutePath($path)) {
            $path = rtrim(str_replace('\\', '/', $root), '/') . '/' . ltrim(str_replace('\\', '/', $path), '/');
        }

        $real = realpath($path);
        if ($real === false || !is_file($real)) {
            return $result;
        }
        $path = $real;

        $rel = self::relativeToRoot($path, $root);
        if ($rel === null) {
            $result['note'] = 'O ficheiro da base de dados está fora da pasta do gateway.';
            return $result;
        }

        // O endereço só serve para mostrar ao administrador qual é o caminho
        // perigoso. Não é pedido a ninguém: vem da configuração, nunca do exterior.
        if ($baseUrl !== null && $baseUrl !== '') {
            $result['url'] = rtrim($baseUrl, '/') . '/' . str_replace('\\', '/', $rel);
        }

        $pasta = dirname($path);
        $temHtaccess = self::htaccessNegaAcesso($pasta, $root);
        $servidor = (string) ($_SERVER['SERVER_SOFTWARE'] ?? '');
        $apache = $servidor !== '' && preg_match('#apache#i', $servidor) === 1;

        $result['checked'] = true;
        $relLimpo = str_replace('\\', '/', $rel);

        if (!$apache) {
            // Sem Apache não há .htaccess que valha. Nginx, LiteSpeed e o
            // servidor embutido do PHP sirve o ficheiro se o pedirem.
            $result['exposed'] = true;
            $result['note'] = sprintf(
                'O ficheiro %s está dentro da pasta pública e o servidor (%s) não é Apache: '
                . 'o .htaccess não é lido e o ficheiro fica disponível para download.',
                $relLimpo,
                $servidor !== '' ? $servidor : 'desconhecido'
            );
            return $result;
        }

        if (!$temHtaccess) {
            $result['exposed'] = true;
            $result['note'] = sprintf(
                'O ficheiro %s está dentro da pasta pública e a pasta não tem .htaccess a negar o acesso.',
                $relLimpo
            );
            return $result;
        }

        // O .htaccess existe, mas o PHP não tem forma de saber se o AllowOverride
        // está ligado. Em alojamento partilhado é frequente não estar, e aí o
        // ficheiro é servido na mesma. Não se pode chamar isto "protegido":
        // diz-se que não se consegue confirmar, para não haver um falso silêncio
        // justo no caso em que o ficheiro está mesmo exposto.
        $result['exposed'] = false;
        $result['unconfirmed'] = true;
        $result['note'] = sprintf(
            'O servidor é Apache e %s tem um .htaccess a negar o acesso, mas o PHP não consegue '
            . 'ver se o AllowOverride está ligado — em alojamento partilhado muitas vezes não está. '
            . 'Abre %s numa janela anónima: tem de dar 403 ou 404. Se der 200 e descarregar, '
            . 'mete o ficheiro fora da pasta web.',
            $relLimpo,
            $result['url'] !== '' ? $result['url'] : $relLimpo
        );

        return $result;
    }

    /**
     * A pasta tem um .htaccess que nega o acesso a quem não é o servidor?
     *
     * Vale tanto para o "Require all denied" do Apache 2.4 como para o
     * "Deny from all" do 2.2, com as duas directivas de AllowOverride.
     */
    /**
     * A pasta tem um .htaccess que nega o acesso a quem não é o servidor?
     *
     * Vale tanto para o "Require all denied" do Apache 2.4 como para o
     * "Deny from all" do 2.2.
     *
     * Sobe-se até à raiz do gateway: um "Require all denied" numa pasta vale
     * para tudo o que está dentro dela. O que não conta é o `.htaccess` da
     * gateway, porque o deny que lá está está dentro de um <FilesMatch> e
     * apenas abrange os ficheiros nomeados — proteger um subdirectório com ele
     * seria dar como seguro um ficheiro que ninguém está a proteger.
     */
    private static function htaccessNegaAcesso(string $pasta, string $root): bool
    {
        $pasta = rtrim(str_replace('\\', '/', (string) realpath($pasta)), '/');
        $root = rtrim(str_replace('\\', '/', (string) realpath($root)), '/');

        if ($pasta === '' || $root === '') {
            return false;
        }

        // Não se olha para o .htaccess da raiz do gateway: o deny que lá está está
        // dentro de um <FilesMatch> e só abrange os ficheiros nomeados. Um
        // ficheiro da storage/ que não esteja nessa lista não fica protegido
        // por ele, e dar como seguro seria um falso negativo.
        while ($pasta !== $root && str_starts_with($pasta . '/', $root . '/')) {
            $ficheiro = $pasta . '/.htaccess';
            if (is_file($ficheiro)) {
                $conteudo = (string) @file_get_contents($ficheiro);
                if (preg_match('#^\s*Require\s+all\s+denied#im', $conteudo) === 1
                    || preg_match('#^\s*Deny\s+from\s+all#im', $conteudo) === 1) {
                    return true;
                }
            }
            $pasta = dirname($pasta);
        }

        return false;
    }

    private static function isAbsolutePath(string $path): bool
    {
        $path = str_replace('\\', '/', $path);
        return str_starts_with($path, '/')
            || (bool) preg_match('#^[A-Za-z]:/#', $path);
    }

    private static function relativeToRoot(string $path, string $root): ?string
    {
        $path = str_replace('\\', '/', (string) realpath($path));
        $root = rtrim(str_replace('\\', '/', (string) realpath($root)), '/');

        if ($path === '' || $root === '') {
            return null;
        }

        return str_starts_with($path, $root . '/') ? substr($path, strlen($root) + 1) : null;
    }

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

                // Email. Vazio por omissão: o gateway funciona sem email, e a
                // recuperação de palavra-passe fica só por SMS até alguém
                // preencher isto nas Definições.
                'mail_transport' => '',
                'smtp_host' => '',
                'smtp_port' => '587',
                'smtp_username' => '',
                'smtp_password' => '',
                'smtp_encryption' => 'tls',
                'mail_from_email' => '',
                'mail_from_name' => 'SMSAPI Gateway',
                'mail_sendmail' => '/usr/sbin/sendmail -bs',
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
