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
        // A pasta de dados so e testavel depois de a base estar escolhida.
        if ($action === 'db' || ($action ?? '') === 'admin') {
            $probeDir = Installer::storageExposed(__DIR__, (array) ($data['db'] ?? []), detect_base_url());
            $dbExposta = (bool) ($probeDir['exposed'] ?? false);
            $dbProbeUrl = (string) ($probeDir['url'] ?? '');
        } else {
            $dbExposta = (bool) ($data['db_exposta'] ?? false);
            $dbProbeUrl = (string) ($data['db_probe_url'] ?? '');
        }

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

                // A base ainda está vazia neste ponto: o ficheiro acabou de ser
                // criado e ainda não tem palavras-passe nem chave da API. Se
                // mesmo assim estiver acessível, avisa-se mas deixa-se
                // terminar — o painel volta a avisar assim que houver dados.
                $probeDir = Installer::storageExposed(__DIR__, $dbConfig, detect_base_url());
                $data['db_exposta'] = (bool) ($probeDir['exposed'] ?? false);
                $data['db_probe_url'] = (string) ($probeDir['url'] ?? '');
                $data['db_probe_note'] = (string) ($probeDir['note'] ?? '');

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

                // Contactos do administrador. São opcionais, mas sem eles não há
                // forma de recuperar o acesso se a palavra-passe for perdida.
                // Validam-se já aqui para o erro aparecer no instalador e não
                // mais tarde, no painel.
                $data['admin_email'] = SmsApi\Users::normalizeEmail(post('admin_email'));
                $data['admin_phone'] = SmsApi\Users::normalizePhone(post('admin_phone'));

                $data['mail_transport'] = post('mail_transport');
                if (!in_array($data['mail_transport'], ['smtp', 'tls', 'sendmail'], true)) {
                    $data['mail_transport'] = '';
                }
                if ($data['mail_transport'] !== '') {
                    $data['smtp_host'] = post('smtp_host');
                    if (in_array($data['mail_transport'], ['smtp', 'tls'], true) && $data['smtp_host'] === '') {
                        throw new SmsApi\ApiException('smtp_host', 'Indique o servidor SMTP, ou escolha o transporte sendmail.', 400);
                    }
                    $data['smtp_port'] = (string) max(1, min(65535, (int) post('smtp_port', '587')));
                    $data['smtp_username'] = post('smtp_username');
                    $data['smtp_password'] = (string) ($_POST['smtp_password'] ?? '');
                    $data['smtp_encryption'] = post('smtp_encryption') === 'none' ? 'none' : 'tls';
                    $data['mail_from_email'] = SmsApi\Users::normalizeEmail(post('mail_from_email'));
                    $data['mail_from_name'] = post('mail_from_name', 'SMSAPI Gateway');
                }

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

                // As definições de email dadas no passo do administrador vão
                // para o config. Vêm a seguir a buildConfig() para não perder
                // os valores por omissão que lá estão definidos.
                if (($data['mail_transport'] ?? '') !== '') {
                    foreach ([
                        'mail_transport', 'smtp_host', 'smtp_port', 'smtp_username',
                        'smtp_password', 'smtp_encryption', 'mail_from_email',
                        'mail_from_name',
                    ] as $campo) {
                        $config['settings'][$campo] = (string) ($data[$campo] ?? '');
                    }
                    Installer::write($config);
                }

                Installer::testDatabase($config['db']);

                // A primeira conta entra na tabela admin_users, com os contactos
                // que o senhor deu aqui. O config.php fica só com a conta base,
                // para uma instalação antiga continuar a funcionar.
                if (($data['admin_email'] ?? '') !== '' || ($data['admin_phone'] ?? '') !== '') {
                    $dbInst = SmsApi\Database::open($config['db']);
                    $dbInst->migrate();
                    (new SmsApi\Users($dbInst))->createFromHash(
                        (string) $data['admin_user'],
                        (string) $data['admin_hash'],
                        (string) ($data['admin_email'] ?? ''),
                        (string) ($data['admin_phone'] ?? '')
                    );
                }

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
            <p class="hint">
                Pode apontar para fora da pasta servida pela web. É a forma mais
                segura quando não tem acesso à configuração do Apache: um ficheiro
                fora do <code>public_html</code> (ou <code>www</code>) não é
                servido, qualquer que seja a configuração. Exemplo em cPanel:
                <code>/home/SEU_USER/smsapi-data/database.sqlite</code>.
                O painel avisa se o caminho escolhido ainda estiver dentro da pasta web.
            </p>

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

            <?php if (!empty($data['db_exposta'])): ?>
                <div class="alert warn">
                    <strong>A pasta <code>storage/</code> parece acessível pela web.</strong>
                    A partir de agora a base de dados guarda a hash da sua palavra-passe,
                    a chave mestra da API e os números de telefone. Termine a instalação e
                    bloqueie a pasta no servidor antes de usar o sistema.<br>
                    <?= esc_html((string) ($data['db_probe_note'] ?? '')) ?><br>

                    <strong>Solução que não depende do servidor:</strong> mover o
                    ficheiro para fora da pasta web. Se não tem acesso à configuração
                    do Apache, esta é a via. Escolha em «Caminho do ficheiro SQLite»
                    um sítio fora do <code>public_html</code>, por exemplo
                    <code>/home/SEU_USER/smsapi-data/database.sqlite</code>, e
                    reinstale. O painel deixa de avisar assim que o ficheiro estiver lá.<br>

                    <?php if (!empty($data['db_probe_url'])): ?>
                        Endereço a bloquear, se preferir resolver pelo servidor:
                        <code><?= esc_html((string) $data['db_probe_url']) ?></code><br>
                    <?php endif; ?>
                    <strong>Nginx:</strong> <code>location ~* ^/storage/ { deny all; }</code><br>
                    <strong>Apache:</strong> confirmar <code>AllowOverride All</code> no virtual host.<br>
                    Não o resolve o <code>chmod 700</code> na pasta: as permissões de
                    ficheiro aplicam-se a quem acede pelo sistema operativo, e o
                    servidor web serve o ficheiro com o seu próprio utilizador.
                </div>
            <?php endif; ?>

            <label for="admin_user">Utilizador</label>
            <input type="text" id="admin_user" name="admin_user" value="<?= esc_html($data['admin_user'] ?? 'admin') ?>" required>

            <label for="admin_pass">Palavra-passe (mínimo 10 caracteres)</label>
            <input type="password" id="admin_pass" name="admin_pass" required>

            <label for="admin_pass2">Confirmar palavra-passe</label>
            <input type="password" id="admin_pass2" name="admin_pass2" required>

            <h2 style="margin-top:26px">Recuperação de acesso</h2>
            <p class="hint">
                Se perder a palavra-passe, o painel manda um código de 6 dígitos
                para um destes contactos. Sem nenhum dos dois, não há como
                recuperar o acesso sem abrir a base de dados à mão.
            </p>

            <label for="admin_email">Email</label>
            <input type="email" id="admin_email" name="admin_email"
                   value="<?= esc_html((string) ($data['admin_email'] ?? '')) ?>" placeholder="o.seu.email@exemplo.com">

            <label for="admin_phone">Telefone</label>
            <input type="text" id="admin_phone" name="admin_phone"
                   value="<?= esc_html((string) ($data['admin_phone'] ?? '')) ?>" placeholder="+351912345678">
            <p class="hint">Com formato internacional, para receber o código por SMS. Um dos dois chega.</p>

            <h2 style="margin-top:26px">Email (opcional)</h2>
            <p class="hint">
                Para enviar emails de aviso e permitir recuperação por email.
                Pode deixar vazio e configurar mais tarde em Definições.
            </p>

            <label for="mail_transport">Envio de email</label>
            <select id="mail_transport" name="mail_transport">
                <?php foreach ([
                    '' => 'Depois, nas Definições',
                    'smtp' => 'SMTP com STARTTLS (porta 587) — o mais comum',
                    'tls' => 'SMTP com TLS já ligado (porta 465)',
                    'sendmail' => 'Programa local de envio (sendmail)',
                ] as $valor => $rotulo): ?>
                    <option value="<?= esc_html($valor) ?>" <?= (($data['mail_transport'] ?? '') === $valor) ? 'selected' : '' ?>>
                        <?= esc_html($rotulo) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <div class="grid-2">
                <div>
                    <label for="smtp_host">Servidor SMTP</label>
                    <input type="text" id="smtp_host" name="smtp_host"
                           value="<?= esc_html((string) ($data['smtp_host'] ?? '')) ?>" placeholder="smtp.gmail.com">
                </div>
                <div>
                    <label for="smtp_port">Porta</label>
                    <input type="number" id="smtp_port" name="smtp_port"
                           value="<?= esc_html((string) ($data['smtp_port'] ?? '587')) ?>">
                </div>
                <div>
                    <label for="smtp_username">Utilizador</label>
                    <input type="text" id="smtp_username" name="smtp_username"
                           value="<?= esc_html((string) ($data['smtp_username'] ?? '')) ?>">
                </div>
                <div>
                    <label for="smtp_password">Palavra-passe</label>
                    <input type="password" id="smtp_password" name="smtp_password">
                </div>
            </div>

            <label for="mail_from_email">Email do remetente</label>
            <input type="email" id="mail_from_email" name="mail_from_email"
                   value="<?= esc_html((string) ($data['mail_from_email'] ?? '')) ?>">

            <label for="mail_from_name">Nome do remetente</label>
            <input type="text" id="mail_from_name" name="mail_from_name"
                   value="<?= esc_html((string) ($data['mail_from_name'] ?? 'SMSAPI Gateway')) ?>">

            <p class="hint">Depois de instalar, use "Enviar email de teste" em Definições para confirmar.</p>

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