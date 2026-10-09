<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once __DIR__ . '/Admin.php';

use SmsApi\Admin\Admin;
use SmsApi\App;
use SmsApi\Translate;

Admin::start();

$error = null;
$user = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!App::isInstalled()) {
        Admin::redirect('../install.php');
    }

    $user = trim((string) ($_POST['user'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($user !== '' && $password !== '' && Admin::attempt($user, $password)) {
        Admin::login($user);
        Admin::redirect('index.php');
    }

    usleep(300_000);
    $error = 'Utilizador ou palavra-passe incorretos.';
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

    <?php if (!App::isInstalled()): ?>
        <p class="alert warn">O gateway ainda não foi instalado. Executar o <a href="../install.php">instalador</a>.</p>
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

    <?= Translate::slot() ?>
</main>
<p class="site-foot">Tradução automática pelo Google Tradutor.</p>
<?= Translate::script() ?>
</body>
</html>