<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once __DIR__ . '/Admin.php';

use SmsApi\Admin\Admin;
use SmsApi\App;

Admin::start();
Admin::requireLogin();

if (!App::isInstalled()) {
    Admin::redirect('../install.php');
}

$db = App::db();
$db->migrate();
$queue = App::queue();

$flash = Admin::takeFlash();
$tab = (string) ($_GET['tab'] ?? 'painel');
$allowedTabs = ['painel', 'fila', 'dispositivos', 'chaves', 'definicoes'];
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'painel';
}

$stats = $queue->stats();
$devices = $db->all('SELECT * FROM devices ORDER BY last_seen_at IS NULL, last_seen_at DESC, id DESC');
$onlineThreshold = time() - (int) App::setting('online_threshold', 300);
$onlineCount = 0;
foreach ($devices as $device) {
    if ((int) $device['is_active'] === 1 && (int) ($device['last_seen_at'] ?? 0) >= $onlineThreshold) {
        $onlineCount++;
    }
}

$recentQueue = $db->all('SELECT * FROM sms_queue ORDER BY id DESC LIMIT 40');
$recentLog = $db->all('SELECT * FROM api_log ORDER BY id DESC LIMIT 25');
$apiBaseUrl = rtrim((string) (App::config()['public_base_url'] ?? ''), '/') . '/';
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SMSAPI Gateway · <?= Admin::e($tab) ?></title>
<?= SmsApi\Translate::overrideMeta() ?>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>

<header class="topbar">
    <div class="brand">SMSAPI <span>Gateway</span></div>
    <nav>
        <?php foreach (['painel' => 'Painel', 'fila' => 'Fila', 'dispositivos' => 'Telefones', 'chaves' => 'Chaves API', 'definicoes' => 'Definições'] as $key => $label): ?>
            <a href="index.php?tab=<?= $key ?>" class="<?= $tab === $key ? 'active' : '' ?>"><?= $label ?></a>
        <?php endforeach; ?>
    </nav>
    <?= SmsApi\Translate::slot() ?>
    <span class="muted"><?= Admin::e((string) $_SESSION['admin_user']) ?></span>
    <a class="btn small" href="logout.php">Sair</a>
</header>

<main class="wrap">

    <?php if ($flash !== null): ?>
        <p class="alert <?= $flash['type'] === 'error' ? 'error' : 'ok' ?>"><?= Admin::e((string) $flash['message']) ?></p>
    <?php endif; ?>

    <?php if (!empty($_GET['sent'])): ?>
        <p class="alert ok">SMS colocado na fila com o número #<?= Admin::e((string) $_GET['sent']) ?>. O telefone entrega-o no próximo ciclo.</p>
    <?php endif; ?>

    <!-- ============================================================ PAINEL -->
    <?php if ($tab === 'painel'): ?>

        <section class="stats">
            <div class="stat"><span class="label">Na fila</span><span class="value"><?= $stats['pending'] ?></span><span class="sub"><?= (int) $stats['parts_pending'] ?> SMS por enviar</span></div>
            <div class="stat"><span class="label">A processar</span><span class="value"><?= $stats['processing'] ?></span></div>
            <div class="stat ok"><span class="label">Entregues</span><span class="value"><?= $stats['sent'] ?></span><span class="sub"><?= (int) $stats['parts_sent'] ?> SMS cobrados</span></div>
            <div class="stat bad"><span class="label">Falhadas</span><span class="value"><?= $stats['failed'] ?></span></div>
            <div class="stat"><span class="label">Telefones online</span><span class="value"><?= $onlineCount ?> / <?= count($devices) ?></span></div>
        </section>

        <div class="grid-2">
            <section class="card">
                <h2>Enviar SMS</h2>
                <p class="muted">A mensagem entra na fila e é entregue pelo telefone no próximo ciclo de verificação.</p>
                <form method="post" action="action.php">
                    <input type="hidden" name="csrf" value="<?= Admin::csrfToken() ?>">
                    <input type="hidden" name="do" value="enqueue">

                    <label for="phone">Número de destino</label>
                    <input type="text" id="phone" name="phone" placeholder="+351912345678" required>

                    <label for="body">Mensagem</label>
                    <textarea id="body" name="body" rows="4" required
                              data-single-gsm="160" data-multi-gsm="153"
                              data-single-ucs="70" data-multi-ucs="67"></textarea>
                    <p class="hint" id="smsCounter">0 caracteres · 0 SMS</p>

                    <label class="checkline">
                        <input type="checkbox" name="to_gsm7" id="toGsm7" value="1">
                        <span>Converter para GSM-7 (substitui <code>ç</code>, <code>ã</code>, acentos e emojis por equivalentes ASCII, voltando ao limite de 160 caracteres)</span>
                    </label>

                    <label for="device_id">Telefone específico (opcional)</label>
                    <select id="device_id" name="device_id">
                        <option value="">Qualquer telefone ativo</option>
                        <?php foreach ($devices as $device): ?>
                            <option value="<?= (int) $device['id'] ?>">
                                <?= Admin::e((string) $device['device_name']) ?> · <?= Admin::e((string) $device['token_prefix']) ?><?= (int) $device['is_active'] === 1 ? '' : ' (revogado)' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="reference">Referência do site (opcional)</label>
                    <input type="text" id="reference" name="reference" placeholder="ex.: encomenda #4821">
                    <p class="hint">Guarde esta referência para saber que mensagem corresponde a quê no seu website.</p>

                    <button type="submit" class="primary">Colocar na fila</button>
                </form>
            </section>

            <section class="card">
                <h2>Para configurar no telefone</h2>
                <p class="muted">Endereço do servidor</p>
                <code class="block"><?= Admin::e($apiBaseUrl) ?></code>

                <p class="muted">Chave da API</p>
                <code class="block secret" id="apiKey"><?= Admin::e(Admin::apiKey()) ?></code>
                <button type="button" class="small" onclick="reveal()">Mostrar / ocultar</button>

                <p class="muted">Segurança</p>
                <ul class="checklist">
                    <li>O endereço tem de começar por <code>https://</code> quando estiver online.</li>
                    <li>A chave é guardada cifrada (Android Keystore) no telefone.</li>
                    <li>Cada telefone recebe o seu próprio token, revogável aqui.</li>
                    <li>Apague <code>install.php</code> do servidor depois de instalar.</li>
                </ul>

                <?php if ($onlineCount === 0): ?>
                    <p class="alert warn">Nenhum telefone registado aindapillars. Instale a app, abra-a e preencha o endereço e a chave acima.</p>
                <?php endif; ?>
            </section>
        </div>

        <section class="card">
            <h2>Últimas atividades da API</h2>
            <?php if ($recentLog === []): ?>
                <p class="muted">Sem registos.</p>
            <?php else: ?>
                <table>
                    <thead><tr><th>Quando</th><th>Endpoint</th><th>Telefone</th><th>IP</th><th>Código</th><th>Mensagem</th></tr></thead>
                    <tbody>
                    <?php foreach ($recentLog as $row): ?>
                        <tr class="<?= (int) $row['code'] >= 400 ? 'row-bad' : '' ?>">
                            <td><?= Admin::e(Admin::relative((int) $row['created_at'])) ?></td>
                            <td><code><?= Admin::e((string) $row['endpoint']) ?></code></td>
                            <td>#<?= Admin::e((string) ($row['device_id'] ?? '—')) ?></td>
                            <td><code><?= Admin::e((string) $row['ip']) ?></code></td>
                            <td><?= (int) $row['code'] ?></td>
                            <td><?= Admin::e((string) ($row['message'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

    <?php endif; ?>

    <!-- ============================================================== FILA -->
    <?php if ($tab === 'fila'): ?>
        <section class="card">
            <h2>Fila de SMS <span class="badge"><?= count($recentQueue) ?> mais recentes</span></h2>
            <?php if ($recentQueue === []): ?>
                <p class="muted">A fila está vazia.</p>
            <?php else: ?>
                <table>
                    <thead><tr><th>#</th><th>Criado</th><th>Número</th><th>Mensagem</th><th>Codificação</th><th>Partes</th><th>Estado</th><th>Tentativas</th><th>Referência</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($recentQueue as $row): ?>
                        <?php $status = (string) $row['status']; ?>
                        <tr>
                            <td><?= (int) $row['id'] ?></td>
                            <td title="<?= Admin::e(Admin::timestamp((int) $row['created_at'])) ?>"><?= Admin::e(Admin::relative((int) $row['created_at'])) ?></td>
                            <td><code><?= Admin::e((string) $row['phone']) ?></code></td>
                            <td class="msg"><?= Admin::e(mb_strimwidth((string) $row['body'], 0, 70, '…')) ?></td>
                            <td><span class="pill <?= (string) $row['encoding'] === 'gsm7' ? 'sent' : 'pending' ?>"><?= Admin::e((string) $row['encoding']) ?></span></td>
                            <td><?= (int) $row['segments'] ?></td>
                            <td><span class="pill <?= Admin::e($status) ?>"><?= Admin::e($status) ?></span></td>
                            <td><?= (int) $row['attempts'] ?> / <?= (int) $row['max_attempts'] ?></td>
                            <td><?= Admin::e((string) ($row['external_ref'] ?? '—')) ?></td>
                            <td class="actions">
                                <?php if ($status === 'failed' || $status === 'sent'): ?>
                                    <form method="post" action="action.php" class="inline">
                                        <input type="hidden" name="csrf" value="<?= Admin::csrfToken() ?>">
                                        <input type="hidden" name="do" value="requeue">
                                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                        <button class="small" type="submit">Repor na fila</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" action="action.php" class="inline">
                                    <input type="hidden" name="csrf" value="<?= Admin::csrfToken() ?>">
                                    <input type="hidden" name="do" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                    <button class="small danger" type="submit">Apagar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <!-- ====================================================== DISPOSITIVOS -->
    <?php if ($tab === 'dispositivos'): ?>
        <section class="card">
            <h2>Telefones registados</h2>
            <?php if ($devices === []): ?>
                <p class="muted">Nenhum telefone registado. Abra a app Android e preencha o endereço do servidor e a chave da API.</p>
            <?php else: ?>
                <table>
                    <thead><tr><th>#</th><th>Nome</th><th>Aparelho</th><th>Token</th><th>Última verificação</th><th>IP</th><th>Estado</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($devices as $device): ?>
                        <?php
                        $id = (int) $device['id'];
                        $isOnline = (int) $device['is_active'] === 1 && (int) ($device['last_seen_at'] ?? 0) >= $onlineThreshold;
                        ?>
                        <tr>
                            <td><?= $id ?></td>
                            <td><strong><?= Admin::e((string) $device['device_name']) ?></strong></td>
                            <td>
                                <?= Admin::e(trim((string) $device['manufacturer'] . ' ' . (string) $device['model'])) ?>
                                <br><span class="muted"><?= Admin::e((string) $device['os_version']) ?> · app <?= Admin::e((string) $device['app_version']) ?></span>
                                <?php if (!empty($device['sim_label'])): ?>
                                    <br><span class="pill sent">SIM: <?= Admin::e((string) $device['sim_label']) ?></span>
                                <?php else: ?>
                                    <br><span class="muted">SIM por omissão</span>
                                <?php endif; ?>
                            </td>
                            <td><code><?= Admin::e((string) $device['token_prefix']) ?>…</code></td>
                            <td title="<?= Admin::e(Admin::timestamp($device['last_seen_at'] === null ? null : (int) $device['last_seen_at'])) ?>">
                                <?= Admin::e(Admin::relative($device['last_seen_at'] === null ? null : (int) $device['last_seen_at'])) ?>
                            </td>
                            <td><code><?= Admin::e((string) ($device['last_ip'] ?? '—')) ?></code></td>
                            <td>
                                <span class="pill <?= (int) $device['is_active'] === 1 ? ($isOnline ? 'sent' : 'pending') : 'failed' ?>">
                                    <?= (int) $device['is_active'] !== 1 ? 'revogado' : ($isOnline ? 'online' : 'offline') ?>
                                </span>
                            </td>
                            <td class="actions">
                                <form method="post" action="action.php" class="inline">
                                    <input type="hidden" name="csrf" value="<?= Admin::csrfToken() ?>">
                                    <input type="hidden" name="do" value="<?= (int) $device['is_active'] === 1 ? 'revoke' : 'restore' ?>">
                                    <input type="hidden" name="id" value="<?= $id ?>">
                                    <button class="small <?= (int) $device['is_active'] === 1 ? 'danger' : '' ?>" type="submit">
                                        <?= (int) $device['is_active'] === 1 ? 'Revogar' : 'Reativar' ?>
                                    </button>
                                </form>
                                <form method="post" action="action.php" class="inline">
                                    <input type="hidden" name="csrf" value="<?= Admin::csrfToken() ?>">
                                    <input type="hidden" name="do" value="reissue">
                                    <input type="hidden" name="id" value="<?= $id ?>">
                                    <button class="small" type="submit">Novo token</button>
                                </form>
                                <form method="post" action="action.php" class="inline">
                                    <input type="hidden" name="csrf" value="<?= Admin::csrfToken() ?>">
                                    <input type="hidden" name="do" value="purge">
                                    <input type="hidden" name="id" value="<?= $id ?>">
                                    <button class="small danger" type="submit">Apagar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <!-- ==================================================== CHAVES DE API -->
    <?php if ($tab === 'chaves'): ?>
        <?php $apiKeys = App::queueKeys()->all(); ?>
        <section class="card">
            <h2>Chaves para sites e aplicações externas</h2>
            <p class="hint">
                Uma chave por integração. Permite que uma loja envie SMS sem nunca ver a chave
                do telemóvel, e revogar uma integração sem mexer nas restantes. A chave é
                mostrada <strong>uma única vez</strong> — a partir daí só fica o seu SHA-256,
                tal como nos tokens dos telefones.
            </p>
            <p class="hint">
                As chaves viajam em <code>Authorization: Bearer &lt;chave&gt;</code>, nunca no
                URL: um URL com a chave fica nos logs do servidor e no histórico do navegador.
            </p>

            <?php $newKeySecret = Admin::takeSecret(); ?>
            <?php if ($newKeySecret !== null): ?>
                <div class="alert ok">
                    <p>
                        <strong>Copie esta chave agora. Depois de sair desta página não volta a
                        ser mostrada.</strong>
                    </p>
                    <p><code class="key-reveal"><?= Admin::e($newKeySecret) ?></code></p>
                    <p class="hint">
                        Endpoint de envio: <code><?= Admin::e($apiBaseUrl) ?>send</code>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($apiKeys === []): ?>
                <p class="hint">Ainda não há chaves. Crie a primeira em baixo.</p>
            <?php else: ?>
                <table>
                    <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Chave</th>
                        <th>Limites</th>
                        <th>Origem</th>
                        <th>Uso</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($apiKeys as $apiKeyRow): ?>
                        <?php
                        $apiKeyId = (int) $apiKeyRow['id'];
                        $ipList = trim((string) ($apiKeyRow['ip_allowlist'] ?? ''));
                        $wait = (int) $apiKeyRow['wait_seconds'];
                        ?>
                        <tr>
                            <td>
                                <?= Admin::e((string) $apiKeyRow['name']) ?>
                                <?php if ((int) $apiKeyRow['is_active'] !== 1): ?>
                                    <span class="pill failed">revogada</span>
                                <?php endif; ?>
                            </td>
                            <td><code><?= Admin::e((string) $apiKeyRow['key_prefix']) ?>…</code></td>
                            <td>
                                <?= (int) $apiKeyRow['rate_per_min'] ?>/min
                                <?php if ((int) $apiKeyRow['max_per_day'] > 0): ?>
                                    · <?= (int) $apiKeyRow['max_per_day'] ?>/dia
                                <?php endif; ?>
                                <?php if ($wait > 0): ?>
                                    · espera <?= $wait ?>s
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($ipList === ''): ?>
                                    <span class="muted">qualquer</span>
                                <?php else: ?>
                                    <code><?= Admin::e($ipList) ?></code>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= (int) $apiKeyRow['total_sent'] ?> envios
                                <?php if ($apiKeyRow['last_used_at'] !== null): ?>
                                    <br><span class="muted">
                                        <?= date('d/m H:i', (int) $apiKeyRow['last_used_at']) ?>
                                        · <?= Admin::e((string) $apiKeyRow['last_used_ip']) ?>
                                    </span>
                                <?php else: ?>
                                    <br><span class="muted">nunca usada</span>
                                <?php endif; ?>
                            </td>
                            <td class="actions">
                                <form method="post" action="action.php" class="inline">
                                    <input type="hidden" name="csrf" value="<?= Admin::csrfToken() ?>">
                                    <input type="hidden" name="do" value="<?= (int) $apiKeyRow['is_active'] === 1 ? 'revoke_key' : 'restore_key' ?>">
                                    <input type="hidden" name="id" value="<?= $apiKeyId ?>">
                                    <button class="small <?= (int) $apiKeyRow['is_active'] === 1 ? 'danger' : '' ?>" type="submit">
                                        <?= (int) $apiKeyRow['is_active'] === 1 ? 'Revogar' : 'Reativar' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2 style="margin-top:26px">Nova chave</h2>
            <form method="post" action="action.php">
                <input type="hidden" name="csrf" value="<?= Admin::csrfToken() ?>">
                <input type="hidden" name="do" value="create_key">

                <label for="key_name">Nome da integração</label>
                <input type="text" id="key_name" name="name" maxlength="120" placeholder="Loja online">

                <label for="key_rate">Pedidos por minuto</label>
                <input type="number" id="key_rate" name="rate_per_min" min="1" max="600" value="60">
                <p class="hint">Cortesia acima do que uma loja normal usa; é uma rede de segurança, não um plano.</p>

                <label for="key_daily">Máximo por dia</label>
                <input type="number" id="key_daily" name="max_per_day" min="0" max="100000" value="0">
                <p class="hint">0 = sem teto diário. Serve para travar uma chave roubada antes de a fazer passar por cima.</p>

                <label for="key_wait">Esperar pela entrega (segundos)</label>
                <input type="number" id="key_wait" name="wait_seconds" min="0" max="30" value="0">
                <p class="hint">
                    <strong>0</strong> = o envio responde logo com o <code>id</code> e o site consulta
                    o estado depois, se quiser. Maior que 0 = o envio fica à espera do modem até
                    esse tempo e devolve já o resultado. Só faz sentido se a aplicação externa usar
                    a resposta; caso contrário é uma ligação parada à espera de nada.
                </p>

                <label for="key_ips">Endereços permitidos</label>
                <input type="text" id="key_ips" name="ip_allowlist" placeholder="203.0.113.10, 198.51.100.0/24">
                <p class="hint">
                    Vazio = qualquer origem. Aceita endereços exactos e blocos CIDR.
                    Só vale a pena se souber de onde vem o pedido; com um proxy ou CDN à frente,
                    deixe vazio.
                </p>

                <button class="primary" type="submit">Criar chave</button>
            </form>
        </section>
    <?php endif; ?>

    <!-- ========================================================= DEFINIÇÕES -->
    <?php if ($tab === 'definicoes'): ?>
        <div class="grid-2">
            <section class="card">
                <h2>Comportamento</h2>
                <form method="post" action="action.php">
                    <input type="hidden" name="csrf" value="<?= Admin::csrfToken() ?>">
                    <input type="hidden" name="do" value="settings">

                    <label for="forced_poll_interval">Intervalo forçado (segundos)</label>
                    <input type="number" id="forced_poll_interval" name="forced_poll_interval" min="0" max="86400"
                           value="<?= (int) App::setting('forced_poll_interval', 0) ?>">
                    <p class="hint">0 = o telefone usa o intervalo que configurou na app. Qualquer outro valor substitui-o a cada verificação.</p>

                    <label for="max_per_poll">Máximo de SMS por ciclo</label>
                    <input type="number" id="max_per_poll" name="max_per_poll" min="1" max="100"
                           value="<?= (int) App::setting('max_per_poll', 25) ?>">

                    <label for="max_segments">Máximo de partes por mensagem</label>
                    <input type="number" id="max_segments" name="max_segments" min="0" max="10"
                           value="<?= (int) App::setting('max_segments', 3) ?>">
                    <p class="hint">
                        0 = sem limite. O Android nunca converte um SMS em MMS: acima de 160
                        caracteres GSM a mensagem é <strong>dividida em vários SMS</strong>, cada um
                        cobrado à parte e sem garantia de entrega parcial.
                        <strong>1</strong> garante uma única mensagem;
                        <strong>3</strong> é um bom compromisso.
                    </p>
                    <p class="hint">
                        Atenção: o alfabeto GSM 03.38 tem <code>Ç</code> e <code>ã</code>, mas
                        <strong>não</strong> tem <code>ç</code>, <code>á</code>, <code>ó</code>,
                        <code>í</code>, <code>ú</code>, <code>â</code>, <code>ê</code> nem emojis.
                        Um único destes caracteres baixa o limite de 160 para <strong>70</strong>.
                    </p>

                    <label for="max_attempts">Tentativas antes de marcar como falhada</label>
                    <input type="number" id="max_attempts" name="max_attempts" min="1" max="20"
                           value="<?= (int) App::setting('max_attempts', 3) ?>">

                    <label for="clock_skew">Tolerância de relógio (segundos)</label>
                    <input type="number" id="clock_skew" name="clock_skew" min="30" max="3600"
                           value="<?= (int) App::setting('clock_skew', 300) ?>">

                    <label for="rate_limit">Máximo de pedidos por minuto e por telefone</label>
                    <input type="number" id="rate_limit" name="rate_limit" min="0" max="10000"
                           value="<?= (int) App::setting('rate_limit', 120) ?>">

                    <label for="online_threshold">Considerar offline ao fim de (segundos)</label>
                    <input type="number" id="online_threshold" name="online_threshold" min="60" max="86400"
                           value="<?= (int) App::setting('online_threshold', 300) ?>">

                    <button type="submit" class="primary">Guardar</button>
                </form>
            </section>

            <section class="card">
                <h2>Chave da API</h2>
                <p class="muted">É o segredo partilhado entre o site e o telefone. Só o mostre num canal seguro.</p>
                <code class="block secret"><?= Admin::e(Admin::apiKey()) ?></code>

                <form method="post" action="action.php">
                    <input type="hidden" name="csrf" value="<?= Admin::csrfToken() ?>">
                    <input type="hidden" name="do" value="rotate_key">
                    <p>Ao rodar a chave, todos os tokens de telefone deixam de funcionar até voltarem a registar-se.</p>
                    <label for="new_key">Nova chave (deixe vazio para gerar automaticamente)</label>
                    <input type="text" id="new_key" name="new_key" placeholder="mínimo 24 caracteres">
                    <button type="submit" class="danger">Rodar chave da API</button>
                </form>

                <hr>
                <h2>Base de dados</h2>
                <p class="muted">Motor: <code><?= Admin::e((string) App::db()->driver) ?></code></p>
                <p class="muted">Versão do gateway: <?= Admin::e(SMSAPI_VERSION) ?></p>
                <p class="muted">Instalado em <?= Admin::e((string) (App::config()['installed_at'] ?? '—')) ?></p>
            </section>
        </div>
    <?php endif; ?>

</main>

<p class="site-foot">Tradução automática pelo Google Tradutor.</p>

<script>
// Alfabeto GSM 03.38: um unico destes caracteres abaixo obriga a Unicode.
const GSM7 = '@£$¥èéùìòÇØøÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?' +
             '¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà^{}\\[~]|€';

const TRANSLIT = {
    'à':'a','á':'a','â':'a','ã':'a','ä':'a','å':'a','æ':'ae','ç':'c','ć':'c','č':'c',
    'è':'e','é':'e','ê':'e','ë':'e','ì':'i','í':'i','î':'i','ï':'i','ñ':'n','ń':'n',
    'ò':'o','ó':'o','ô':'o','õ':'o','ö':'o','ø':'o','œ':'oe','ù':'u','ú':'u','û':'u',
    'ü':'u','ý':'y','ÿ':'y','š':'s','ś':'s','ž':'z','ź':'z','ż':'z','ł':'l','đ':'d',
    'ð':'d','þ':'th','ß':'ss'
};

function analyse(text, normalize) {
    if (normalize) {
        text = Array.from(text).map(c => {
            if (c === '\n' || c === '\r' || c === '\f') return c;
            if (GSM7.includes(c)) return c;
            return TRANSLIT[c] !== undefined ? TRANSLIT[c] : '';
        }).join('');
    }

    let gsm7 = true;
    for (const c of Array.from(text)) {
        if (c === '\n' || c === '\r' || c === '\f') continue;
        if (!GSM7.includes(c)) { gsm7 = false; break; }
    }

    const units = gsm7 ? Array.from(text).length : Array.from(text).length;
    const single = gsm7 ? 160 : 70;
    const multi = gsm7 ? 153 : 67;
    const segments = units <= single ? 1 : Math.ceil(units / multi);
    return { gsm7, units, segments };
}

(function () {
    const body = document.getElementById('body');
    const counter = document.getElementById('smsCounter');
    const normalize = document.getElementById('toGsm7');
    if (!body || !counter || !normalize) return;

    function update() {
        const info = analyse(body.value, normalize.checked);
        const limit = document.querySelector('input[name="max_segments"]');
        const max = limit ? parseInt(limit.value, 10) : 3;
        const total = max > 0 ? info.segments > max : false;

        counter.textContent = info.units + ' caracteres · ' + info.segments + ' SMS · '
            + (info.gsm7 ? 'GSM-7 (160)' : 'Unicode (70)');
        counter.className = total ? 'hint bad' : (info.segments > 1 ? 'hint warn' : 'hint');
        if (total) {
            counter.textContent += ' — acima do limite de ' + max + ' partes';
        }
    }

    body.addEventListener('input', update);
    normalize.addEventListener('change', update);
    const limitField = document.querySelector('input[name="max_segments"]');
    if (limitField) { limitField.addEventListener('input', update); }
    update();
})();

function reveal() {
    const el = document.getElementById('apiKey');
    if (!el) return;
    el.classList.toggle('hidden-secret');
}
</script>
<?= SmsApi\Translate::script() ?>
</body>
</html>