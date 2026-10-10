<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once __DIR__ . '/Admin.php';

use SmsApi\Admin\Admin;
use SmsApi\ApiException;
use SmsApi\App;
use SmsApi\MailException;
use SmsApi\PasswordReset;
use SmsApi\Translate;

Admin::start();

if (!App::isInstalled()) {
    Admin::redirect('../install.php');
}
if (Admin::isLoggedIn()) {
    Admin::redirect('index.php');
}

$db = App::db();
$db->migrate();
$users = App::ensureUsers();
$mailer = App::mailer();
$reset = new PasswordReset($db, App::queue(), $users, $mailer);
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

$erro = null;
$aviso = null;
$passo = 'pedir';            // pedir | confirmar
$canal = 'sms';
$utilizador = trim((string) ($_POST['user'] ?? $_GET['user'] ?? ''));
$enviadoPor = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string) ($_POST['do'] ?? '');

    // --- passo 1: pedir o código ------------------------------------------
    if ($acao === 'pedir') {
        $utilizador = trim((string) ($_POST['user'] ?? ''));
        $canal = (string) ($_POST['channel'] ?? 'sms') === 'email' ? 'email' : 'sms';

        if ($utilizador === '') {
            $erro = 'Escreva o nome de utilizador.';
        } else {
            try {
                $r = $reset->request($utilizador, $canal, $ip);

                if ($r['enviado']) {
                    $enviadoPor = $r['via'];
                    $passo = 'confirmar';
                } else {
                    // A mesma resposta exista ou não a conta. Não se confirma
                    // a quem pediu se aquele utilizador existe.
                    $passo = 'confirmar';
                    $enviadoPor = null;
                }
                // Guarda-se na sessão que conta se pide o código, para o passo
                // seguinte não poder ser usado contra outra conta.
                $_SESSION['reset_user'] = $utilizador;
                $_SESSION['reset_channel'] = $canal;
            } catch (ApiException $e) {
                $erro = $e->getMessage();
            } catch (MailException $e) {
                $erro = 'Não foi possível enviar o email: ' . $e->getMessage();
            }
        }
    }

    // --- passo 2: confirmar o código e pôr a palavra-passe nova ------------
    if ($acao === 'confirmar') {
        $utilizador = (string) ($_SESSION['reset_user'] ?? '');
        $canal = (string) ($_SESSION['reset_channel'] ?? 'sms');
        $codigo = trim((string) ($_POST['code'] ?? ''));
        $nova = (string) ($_POST['password'] ?? '');
        $repetida = (string) ($_POST['password_confirm'] ?? '');

        if ($utilizador === '') {
            $erro = 'A sessão expirou. Comece de novo.';
            $passo = 'pedir';
        } elseif (strlen($codigo) !== 6) {
            $erro = 'O código tem 6 dígitos.';
        } elseif (strlen($nova) < 10) {
            $erro = 'A palavra-passe nova tem de ter pelo menos 10 caracteres.';
        } elseif ($nova !== $repetida) {
            $erro = 'As duas palavras-passe não são iguais.';
        } else {
            try {
                $conta = $reset->verify($utilizador, $codigo, $canal);
                $users->setPassword((int) $conta['id'], $nova);
                $reset->consume($utilizador, $canal);

                unset($_SESSION['reset_user'], $_SESSION['reset_channel']);

                Admin::flash('Palavra-passe alterada. Já pode entrar.');
                Admin::redirect('login.php');
            } catch (ApiException $e) {
                $erro = $e->getMessage();
            }
        }
    }
}

$mailPronto = $mailer->isConfigured();
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Recuperar palavra-passe · SMSAPI Gateway</title>
<?= Translate::overrideMeta() ?>
<?= SmsApi\Branding::favicon(1) ?>
<link rel="stylesheet" href="assets/app.css">
</head>
<body class="centered">
<main class="card login">
    <div class="login-top">
        <h1>Recuperar acesso</h1>
        <p class="muted">SMSAPI Gateway</p>
    </div>

    <?php if ($erro !== null): ?>
        <p class="alert error"><?= Admin::e($erro) ?></p>
    <?php endif; ?>

    <?php if ($passo === 'pedir'): ?>
        <form method="post">
            <input type="hidden" name="do" value="pedir">

            <label for="user">Nome de utilizador</label>
            <input type="text" id="user" name="user" value="<?= Admin::e($utilizador) ?>" required autofocus>

            <label>Como quer receber o código?</label>
            <label class="choice">
                <input type="radio" name="channel" value="sms" checked>
                <span>SMS para o telefone da conta</span>
            </label>
            <label class="choice">
                <input type="radio" name="channel" value="email" <?= $mailPronto ? '' : 'disabled' ?>>
                <span>Email <?= $mailPronto ? '' : '(ainda não configurado)' ?></span>
            </label>

            <button type="submit" class="primary block">Enviar código</button>
        </form>
    <?php else: ?>
        <?php /* A mesma mensagem exista ou não a conta, exista ou não o
                 contacto. Se difresse, um atacante confirmava a existência de
                 um nome de utilizador só de ver a resposta. */ ?>
        <p class="alert ok">
            Se essa conta existir e tiver <?= $canal === 'sms' ? 'telefone' : 'email' ?>
            preenchido, enviámos um código de 6 dígitos. Vale durante 10 minutos.
        </p>

        <form method="post">
            <input type="hidden" name="do" value="confirmar">

            <label for="code">Código de 6 dígitos</label>
            <input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9]{6}"
                   maxlength="6" autocomplete="one-time-code" required autofocus>

            <label for="password">Palavra-passe nova</label>
            <input type="password" id="password" name="password" minlength="10" required>
            <p class="hint">Pelo menos 10 caracteres.</p>

            <label for="password_confirm">Repita a palavra-passe nova</label>
            <input type="password" id="password_confirm" name="password_confirm" minlength="10" required>

            <button type="submit" class="primary block">Alterar palavra-passe</button>
        </form>

        <p class="hint login-forgot">
            <a href="recuperar.php?user=<?= Admin::e($utilizador) ?>">Pedir outro código</a>
            · <a href="login.php">Voltar ao início de sessão</a>
        </p>
    <?php endif; ?>
</main>
<p class="site-foot">Tradução automática pelo Google Tradutor.</p>
<?= Translate::script() ?>
</body>
</html>