<?php

declare(strict_types=1);

/**
 * Instalador interactivo do SMSAPI Gateway.
 *
 * Apague este ficheiro do servidor depois da instalação.
 */

require_once __DIR__ . '/src/bootstrap.php';

use SmsApi\Config;
use SmsApi\Installer;
use SmsApi\Translate;

$alreadyInstalled = Config::exists();
$reinstall = isset($_GET['reinstall']) && $_GET['reinstall'] === '1';

session_name('smsapi_install');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

$data = $_SESSION['install'] ?? [];
$justInstalled = isset($data['installed_config']) && (int) ($data['step'] ?? 0) === 6;
$summaryShown = !empty($_SESSION['install_summary_shown']);

// After the final step config.php exists, so this page would otherwise block
// itself before showing the summary. The summary is shown exactly once.
if ($alreadyInstalled && (!$justInstalled || $summaryShown) && !$reinstall) {
    esc_html_and_block();
}

if ($justInstalled && !$summaryShown) {
    $_SESSION['install_summary_shown'] = true;
}

const STEPS = ['Requisitos', 'Base de dados', 'Administrador', 'Chave da API', 'Instalar', 'Concluído'];

$requirements = Installer::requirements(__DIR__);
$blockers = Installer::blockers(__DIR__);

$step = (int) ($data['step'] ?? 1);
$errors = [];

function esc_html(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function post(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

function detect_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/install.php')));

    return ($https ? 'https' : 'http') . '://' . $host . rtrim($dir, '/') . '/';
}

function esc_html_and_block(): never
{
    $base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/install.php'))), '/') . '/';
    ?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Instalado · SMSAPI Gateway</title>
<link rel="stylesheet" href="admin/assets/app.css">
</head>
<body class="centered">
<main class="card login">
    <h1>SMSAPI Gateway</h1>
    <p class="alert ok">O gateway já está instalado.</p>
    <p class="muted">Por segurança, apague o ficheiro <code>install.php</code> do servidor.</p>
    <p>
        <a class="btn" href="<?= esc_html($base) ?>admin/">Abrir painel</a>
        <a class="btn" href="<?= esc_html($base) ?>admin/login.php">Iniciar sessão</a>
    </p>
    <p class="hint">Precisa de reinstalar do zero? <a href="install.php?reinstall=1">Forçar reinstalação</a>.</p>
    <?= Translate::slot() ?>
</main>
<p class="site-foot">Tradução automática pelo Google Tradutor.</p>
<?= Translate::script() ?>
</body>
</html>
    <?php
    exit;
}

// ----------------------------------------------------------------- POST

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    try {
        switch ($action) {
            case 'skip':
                if ($blockers !== []) {
                    throw new RuntimeException('Existem requisitos obrigatórios por satisfazer.');
                }
                $data['step'] = 2;
                break;

            case 'db':
                $dbConfig = Installer::normalizeDatabase(post('driver'), [
                    'sqlite_path' => post('sqlite_path'),
                    'host' => post('mysql_host', 'localhost'),
                    'port' => post('mysql_port') !== '' ? (int) post('mysql_port') : 3306,
                    'name' => post('mysql_name'),
                    'user' => post('mysql_user'),
                    'pass' => (string) ($_POST['mysql_pass'] ?? ''),
                ]);
                Installer::testDatabase($dbConfig);
                $data['db'] = $dbConfig;
                $data['step'] = 3;
                break;

            case 'admin':
                if (empty($data['db'])) {
                    throw new RuntimeException('Escolha primeiro a base de dados.');
                }
                $admin = Installer::normalizeAdmin(
                    post('admin_user'),
                    (string) ($_POST['admin_pass'] ?? ''),
                    (string) ($_POST['admin_pass2'] ?? '')
                );
                $data['admin_user'] = $admin['user'];
                $data['admin_hash'] = $admin['hash'];
                $data['timezone'] = post('timezone', 'Europe/Lisbon');
                $data['step'] = 4;
                break;

            case 'key':
                $data['api_key'] = Installer::normalizeApiKey(post('key_mode'), post('api_key'));
                $data['step'] = 5;
                break;

            case 'install':
                foreach (['db', 'admin_user', 'admin_hash', 'api_key'] as $required) {
                    if (empty($data[$required])) {
                        throw new RuntimeException('Faltam dados dos passos anteriores. Recomece pelo passo 1.');
                    }
                }
                $config = Installer::buildConfig(
                    $data['db'],
                    (string) $data['admin_user'],
                    (string) $data['admin_hash'],
                    (string) $data['api_key'],
                    detect_base_url(),
                    (string) ($data['timezone'] ?? 'Europe/Lisbon')
                );
                Installer::write($config);
                Installer::testDatabase($config['db']);

                $data['installed_config'] = $config;
                unset($data['admin_hash']);
                $data['step'] = 6;
                break;

            case 'restart':
                session_destroy();
                header('Location: install.php');
                exit;

            default:
                throw new RuntimeException('Passo desconhecido.');
        }

        $_SESSION['install'] = $data;
        header('Location: install.php');
        exit;
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
        $_SESSION['install'] = $data;
    }
}

$dbDraft = $data['db'] ?? [];

?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Instalar · SMSAPI Gateway</title>
<link rel="stylesheet" href="admin/assets/app.css">
</head>
<body>
<main class="wrap">

    <div class="install-top">
        <div>
            <h1>Instalar SMSAPI Gateway</h1>
            <p class="muted">Configura o servidor que recebe os SMS do website e os entrega ao telefone Android.</p>
        </div>
        <?= Translate::slot() ?>
    </div>

    <div class="steps">
        <?php foreach (STEPS as $index => $label): ?>
            <?php $number = $index + 1; ?>
            <div class="<?= $number < $step ? 'done' : ($number === $step ? 'current' : '') ?>">
                <?= $number ?>. <?= esc_html($label) ?>
            </div>
        <?php endforeach; ?>
    </div>

    <?php foreach ($errors as $error): ?>
        <p class="alert error"><?= esc_html($error) ?></p>
    <?php endforeach; ?>

    <?php if ($step === 1): ?>
        <section class="card">
            <h2>Requisitos do servidor</h2>
            <table>
                <thead><tr><th>Requisito</th><th>Estado</th><th>Detalhe</th></tr></thead>
                <tbody>
                <?php foreach ($requirements as $requirement): ?>
                    <tr class="<?= $requirement['ok'] ? '' : 'row-bad' ?>">
                        <td>
                            <?= esc_html($requirement['label']) ?>
                            <?php if ($requirement['fatal']): ?><span class="pill failed">obrigatório</span><?php endif; ?>
                        </td>
                        <td><span class="pill <?= $requirement['ok'] ? 'sent' : 'failed' ?>"><?= $requirement['ok'] ? 'OK' : 'Falta' ?></span></td>
                        <td class="muted"><?= esc_html($requirement['value']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($blockers !== []): ?>
                <p class="alert error">Corrija os requisitos obrigatórios em falta e recarregue esta página.</p>
            <?php else: ?>
                <form method="post">
                    <input type="hidden" name="action" value="skip">
                    <?php if ($alreadyInstalled): ?>
                        <p class="alert warn">Isto substitui a configuração existente. A fila de SMS e os telefones registados são mantidos.</p>
                    <?php endif; ?>
                    <button type="submit" class="primary">Continuar</button>
                </form>
            <?php endif; ?>
        </section>

    <?php elseif ($step === 2): ?>
        <form method="post" class="card">
            <input type="hidden" name="action" value="db">
            <h2>Motor de base de dados</h2>
            <p class="muted">
                Ambos suportam o mesmo código. SQLite é mais simples e funciona bem em XAMPP;
                MySQL é melhor para alojamento partilhado e para tráfego mais elevado. Pode mudar depois.
            </p>

            <label class="choice">
                <input type="radio" name="driver" value="sqlite" <?= (($dbDraft['driver'] ?? 'sqlite') === 'sqlite') ? 'checked' : '' ?>>
                <strong>SQLite</strong>
                <span class="hint">Um único ficheiro dentro de <code>storage/</code>. Sem palavras-passe e sem configuração de servidor.</span>
            </label>
            <label for="sqlite_path">Caminho do ficheiro SQLite</label>
            <input type="text" id="sqlite_path" name="sqlite_path" value="<?= esc_html($dbDraft['sqlite_path'] ?? (__DIR__ . '/storage/database.sqlite')) ?>">

            <label class="choice">
                <input type="radio" name="driver" value="mysql" <?= (($dbDraft['driver'] ?? '') === 'mysql') ? 'checked' : '' ?>>
                <strong>MySQL / MariaDB</strong>
                <span class="hint">A base de dados tem de existir, ou o utilizador precisa de permissão para a criar.</span>
            </label>

            <div class="grid-2">
                <div>
                    <label for="mysql_host">Servidor</label>
                    <input type="text" id="mysql_host" name="mysql_host" value="<?= esc_html($dbDraft['host'] ?? 'localhost') ?>">
                </div>
                <div>
                    <label for="mysql_port">Porta</label>
                    <input type="number" id="mysql_port" name="mysql_port" value="<?= esc_html((string) ($dbDraft['port'] ?? 3306)) ?>">
                </div>
                <div>
                    <label for="mysql_name">Nome da base de dados</label>
                    <input type="text" id="mysql_name" name="mysql_name" value="<?= esc_html($dbDraft['name'] ?? '') ?>">
                </div>
                <div>
                    <label for="mysql_user">Utilizador</label>
                    <input type="text" id="mysql_user" name="mysql_user" value="<?= esc_html($dbDraft['user'] ?? '') ?>">
                </div>
                <div>
                    <label for="mysql_pass">Palavra-passe</label>
                    <input type="password" id="mysql_pass" name="mysql_pass" value="">
                </div>
            </div>

            <p class="hint">A ligação é testada e as tabelas são criadas assim que carregar em continuar.</p>
            <button type="submit" class="primary">Testar e criar tabelas</button>
        </form>

    <?php elseif ($step === 3): ?>
        <form method="post" class="card">
            <input type="hidden" name="action" value="admin">
            <h2>Conta de administrador</h2>
            <p class="muted">Única conta com acesso ao painel. Não confundir com a chave da API.</p>

            <label for="admin_user">Utilizador</label>
            <input type="text" id="admin_user" name="admin_user" value="<?= esc_html($data['admin_user'] ?? 'admin') ?>" required>

            <label for="admin_pass">Palavra-passe (mínimo 10 caracteres)</label>
            <input type="password" id="admin_pass" name="admin_pass" required>

            <label for="admin_pass2">Confirmar palavra-passe</label>
            <input type="password" id="admin_pass2" name="admin_pass2" required>

            <label for="timezone">Fuso horário</label>
            <select id="timezone" name="timezone">
                <?php foreach (['Europe/Lisbon', 'Europe/Madrid', 'Europe/London', 'America/Sao_Paulo', 'UTC'] as $tz): ?>
                    <option value="<?= esc_html($tz) ?>" <?= (($data['timezone'] ?? 'Europe/Lisbon') === $tz) ? 'selected' : '' ?>>
                        <?= esc_html($tz) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="primary">Continuar</button>
        </form>

    <?php elseif ($step === 4): ?>
        <form method="post" class="card">
            <input type="hidden" name="action" value="key">
            <h2>Chave da API</h2>
            <p class="muted">Segredo partilhado entre o website e a app Android. Quem o tiver consegue enviar SMS pela sua conta.</p>

            <label class="choice">
                <input type="radio" name="key_mode" value="auto" checked>
                <strong>Gerar automaticamente (recomendado)</strong>
                <span class="hint">48 caracteres aleatórios em hexadecimal, praticamente impossível de adivinhar.</span>
            </label>

            <label class="choice">
                <input type="radio" name="key_mode" value="custom">
                <strong>Definir a minha chave</strong>
                <span class="hint">Só letras, números, ponto, traço e underscore — é digitada no teclado do telefone.</span>
            </label>

            <label for="api_key">Chave personalizada (deixe vazio se escolheu gerar)</label>
            <input type="text" id="api_key" name="api_key" placeholder="<?= esc_html(Config::generateApiKey()) ?>">

            <button type="submit" class="primary">Continuar</button>
        </form>

    <?php elseif ($step === 5): ?>
        <form method="post" class="card">
            <input type="hidden" name="action" value="install">
            <h2>Confirmar e instalar</h2>
            <table>
                <tbody>
                <tr><td>Motor de base de dados</td><td><code><?= esc_html($dbDraft['driver'] ?? '') ?></code></td></tr>
                <tr>
                    <td>Base de dados</td>
                    <td><code><?= esc_html(
                        ($dbDraft['driver'] ?? '') === 'sqlite'
                            ? ($dbDraft['sqlite_path'] ?? '')
                            : (($dbDraft['user'] ?? '') . '@' . ($dbDraft['host'] ?? '') . ':' . ($dbDraft['port'] ?? '') . '/' . ($dbDraft['name'] ?? ''))
                    ) ?></code></td>
                </tr>
                <tr><td>Administrador</td><td><code><?= esc_html($data['admin_user'] ?? '') ?></code></td></tr>
                <tr><td>Chave da API</td><td><code><?= esc_html($data['api_key'] ?? '') ?></code></td></tr>
                <tr><td>Endereço da API</td><td><code><?= esc_html(detect_base_url() . 'public/') ?></code></td></tr>
                </tbody>
            </table>
            <p class="alert warn">A palavra-passe do MySQL não é mostrada aqui. Se a escreveu, guarde-a: só fica registada em config.php.</p>
            <button type="submit" class="primary">Instalar agora</button>
        </form>

    <?php elseif ($step === 6): ?>
        <?php $installed = $data['installed_config']; ?>
        <section class="card">
            <h2>Instalação concluída</h2>
            <p class="alert ok">Tudo pronto. A partir de agora o telefone pode registar-se e a fila funciona.</p>

            <p class="muted">1. Endereço do servidor (na app Android)</p>
            <code class="block"><?= esc_html(detect_base_url() . 'public/') ?></code>

            <p class="muted">2. Chave da API (na app Android)</p>
            <code class="block"><?= esc_html((string) $installed['api_key']) ?></code>

            <p class="muted">3. Ficheiro do cron (no alojamento ou em XAMPP)</p>
            <code class="block"><?= esc_html((string) $installed['settings']['cron_url']) ?></code>

            <div class="alert warn">
                <strong>Passos obrigatórios antes de usar em produção:</strong>
                <ol style="margin:8px 0 0 18px;padding:0">
                    <li>Apague <code>install.php</code> do servidor.</li>
                    <li>Confirme que o endereço usa <code>https://</code>.</li>
                    <li>Agende o ficheiro do cron a cada 15 minutos.</li>
                    <li>Guarde a chave da API num local seguro — sem ela não consegue reconfigurar o telefone.</li>
                </ol>
            </div>

            <p>
                <a class="btn primary" href="admin/index.php">Abrir painel</a>
                <a class="btn" href="admin/login.php">Iniciar sessão</a>
            </p>
        </section>
    <?php endif; ?>

</main>
<p class="site-foot">Tradução automática pelo Google Tradutor.</p>
<?= Translate::script() ?>
</body>
</html>