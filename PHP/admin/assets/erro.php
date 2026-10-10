<?php
/**
 * Página de erro do painel: o config.php ou a base de dados não estão bons.
 * Mostra o que o servidor disse em vez de um 500 em branco.
 *
 * Não lleva declare(strict_types=1): este ficheiro é incluido dentro do
 * index.php, que já tem o seu proprio, e o PHP não aceita dois no mesmo
 * pedido.
 *
 * @var string $erroPainel
 */

use SmsApi\Admin\Admin;
use SmsApi\Translate;

$detalhe = (string) ($erroPainel ?? '');
$configQuebrado = str_contains($detalhe, 'config.php');
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Erro · SMSAPI Gateway</title>
<?= Translate::overrideMeta() ?>
<?= SmsApi\Branding::favicon(2) ?>
<link rel="stylesheet" href="assets/app.css">
</head>
<body class="centered">
<main class="card login">
    <div class="login-top">
        <h1>SMSAPI Gateway</h1>
        <p class="muted">O painel não conseguiu abrir</p>
    </div>

    <p class="alert error"><?= Admin::e($detalhe) ?></p>

    <?php if ($configQuebrado): ?>
        <p class="hint">
            O <code>config.php</code> existe mas não está a devolver uma
            configuração válida. É o que acontece quando se edita o ficheiro à mão
            e fica a meio, ou quando lhe fica HTML ou texto no fim.<br>
            O ficheiro tem de ser exactamente um <code>&lt;?php … return [ … ];</code>,
            sem nada depois do <code>;</code>.<br>
            Se acabou de mover a base de dados para fora da pasta web, confirme
            também que a pasta existe e que o PHP tem permissão de escrita nela.
        </p>
    <?php else: ?>
        <p class="hint">
            Confirme que a pasta da base de dados existe e que o PHP tem
            permissão de escrita nela. Se o caminho tiver mudado, está em
            <code>config.php</code>, na opção <code>sqlite_path</code>.
        </p>
    <?php endif; ?>

    <p><a href="login.php">Voltar ao início de sessão</a></p>

    <?= Translate::slot() ?>
</main>
<p class="site-foot">Tradução automática pelo Google Tradutor.</p>
<?= Translate::script() ?>
</body>
</html>