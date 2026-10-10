<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once __DIR__ . '/Admin.php';

use SmsApi\Admin\Admin;
use SmsApi\App;
use SmsApi\Translate;

Admin::start();

$flash = Admin::takeFlash();
$error = null;
$user = '';
$fatal = null;

// Um config.php partido dava um 500 sem dizer nada. Traduz-se a falha numa
// mensagem que diz o que fazer, que é o que o administrador precisa.
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!App::isInstalled()) {
            Admin::redirect('../install.php');
        }

        // O login é o único caminho que não criava as tabelas. Se o ficheiro
        // SQLite apontado no config.php estiver vazio — o que acontece se a
        // base foi movida de sítio e o ficheiro não foi copiado, porque o PDO
        // cria um ficheiro novo em silêncio — a consulta ao admin_users rebentava
        // com "no such table". Criam-se as tabelas antes de perguntar.
        App::db()->migrate();

        $user = trim((string) ($_POST['user'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($user !== '' && $password !== '') {
            $result = Admin::attempt($user, $password, (string) ($_SERVER['REMOTE_ADDR'] ?? ''));

            if ($result['ok']) {
                $conta = App::ensureUsers()->findByUsername($user);
                Admin::login((int) $conta['id'], (string) $conta['username']);
                Admin::redirect('index.php');
            }

            // A mesma mensagem nos três casos: não se diz se a conta existe, se
            // está desactivada ou se a palavra-passe é que está errada.
            usleep(300_000);
            $error = match ($result['reason']) {
                'account_disabled' => 'Esta conta está desativada. Contacte outro administrador.',
                'account_locked' => 'Demasiadas tentativas. Esta conta ficou bloqueada durante 15 minutos.',
                default => 'Utilizador ou palavra-passe incorretos.',
            };
        } else {
            usleep(300_000);
            $error = 'Utilizador ou palavra-passe incorretos.';
        }
    }
} catch (Throwable $e) {
    error_log('SMSAPI: falha no login: ' . $e->getMessage());

    // Se o ficheiro SQLite apontado no config.php estiver vazio, o PDO já o
    // criou em silêncio. É o que acontece quando se muda o caminho da base de
    // dados sem copiar o ficheiro antigo: as tabelas não existem e o login dá
    // "no such table". Diz-se isso ao administrator em vez de "palavra-passe
    // errada", que o deixaria a tentar adivinhar.
    $semTabelas = str_contains($e->getMessage(), 'no such table');

    $fatal = $semTabelas
        ? 'A base de dados não tem as tabelas do sistema. Se mudou o caminho no config.php, '
            . 'copie o ficheiro antigo para o novo sítio — se não, volte a executar o instalador.'
        : 'Não foi possível ligar à base de dados. A mensagem do servidor foi: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Entrar · SMSAPI Gateway</title>
<?= Translate::overrideMeta() ?>
<link rel="stylesheet" href="assets/app.css">
</head>
<body class="centered">
<main class="card login">
    <div class="login-top">
        <h1>SMSAPI Gateway</h1>
        <p class="muted">Painel de administração</p>
    </div>

    <?php if ($flash !== null): ?>
        <p class="alert <?= $flash['type'] === 'error' ? 'error' : 'ok' ?>"><?= Admin::e((string) $flash['message']) ?></p>
    <?php endif; ?>

    <?php if ($fatal !== null): ?>
        <p class="alert error"><?= Admin::e($fatal) ?></p>
        <p class="hint">
            Verifique o ficheiro <code>config.php</code>: se o moveu ou editou à mão,
            tem de continuar a ser um <code>&lt;?php return [...];</code> válido.
        </p>
    <?php elseif (!App::isInstalled()): ?>
        <p class="alert warn">O gateway ainda não foi instalado. Execute o <a href="../install.php">instalador</a>.</p>
    <?php elseif ($error !== null): ?>
        <p class="alert error"><?= Admin::e($error) ?></p>
    <?php endif; ?>

    <form method="post" autocomplete="on">
        <label for="user">Utilizador</label>
        <input type="text" id="user" name="user" value="<?= Admin::e($user) ?>" required autofocus>

        <label for="password">Palavra-passe</label>
        <input type="password" id="password" name="password" required>

        <button type="submit" class="primary block">Entrar</button>
    </form>

    <?php if (App::isInstalled()): ?>
        <p class="hint login-forgot">
            <a href="recuperar.php">Esqueci-me da palavra-passe</a>
        </p>
    <?php endif; ?>

    <?= Translate::slot() ?>
</main>
<p class="site-foot">Tradução automática pelo Google Tradutor.</p>
<?= Translate::script() ?>
</body>
</html>