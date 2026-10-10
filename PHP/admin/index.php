<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once __DIR__ . '/Admin.php';

use SmsApi\Admin\Admin;
use SmsApi\App;
use SmsApi\SecurityCheck;

Admin::start();
Admin::requireLogin();

if (!App::isInstalled()) {
    Admin::redirect('../install.php');
}

// O painel tem de abrir mesmo que a base de dados ou o config estejam
// partidos: um erro de configuração mostra-se, não devolve um 500 sem explicação.
try {
    $db = App::db();
    $db->migrate();
    $queue = App::queue();
} catch (Throwable $e) {
    error_log('SMSAPI: falha ao abrir o painel: ' . $e->getMessage());

    http_response_code(500);
    $erroPainel = $e->getMessage();
    require __DIR__ . '/assets/erro.php';

    return;
}

// Verifica se a base de dados está descarregável pela web. Fica aqui, e não no
// bootstrap, porque só depois de Admin::start() é que a sessão existe — e sem
// sessão não há como saber se quem está a ver o painel é administrador.
SecurityCheck::start();

$flash = Admin::takeFlash();
$tab = (string) ($_GET['tab'] ?? 'painel');
$allowedTabs = ['painel', 'fila', 'dispositivos', 'chaves', 'utilizadores', 'email', 'definicoes'];
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

// Registo da API: filtrável e exportável. Os filtros vêm do formulário e são
// montados com parâmetros ligados — nunca concatenados no SQL.
$logPorPagina = 25;
$logPagina = max(1, (int) ($_GET['log_pagina'] ?? 1));
$logFiltros = [
    'endpoint' => trim((string) ($_GET['f_endpoint'] ?? '')),
    'device_id' => trim((string) ($_GET['f_device'] ?? '')),
    'ip' => trim((string) ($_GET['f_ip'] ?? '')),
    'code' => trim((string) ($_GET['f_code'] ?? '')),
    'message' => trim((string) ($_GET['f_message'] ?? '')),
    'de' => trim((string) ($_GET['f_de'] ?? '')),
    'ate' => trim((string) ($_GET['f_ate'] ?? '')),
];

$logWhere = [];
$logArgs = [];

// O IP pode ser exacto ou um prefixo: escrever 192.168. mostra a sub-rede
// inteira, que é o que se quer quando se investiga um abuso.
if ($logFiltros['endpoint'] !== '') {
    $logWhere[] = 'endpoint = ?';
    $logArgs[] = $logFiltros['endpoint'];
}
if ($logFiltros['device_id'] !== '') {
    $logWhere[] = 'device_id = ?';
    $logArgs[] = max(0, (int) $logFiltros['device_id']);
}
if ($logFiltros['ip'] !== '') {
    $logWhere[] = 'ip LIKE ?';
    $logArgs[] = str_replace(['%', '_'], ['\%', '\_'], $logFiltros['ip']) . '%';
}
if ($logFiltros['code'] !== '') {
    $logWhere[] = 'code = ?';
    $logArgs[] = max(0, (int) $logFiltros['code']);
}
if ($logFiltros['message'] !== '') {
    $logWhere[] = 'message LIKE ?';
    $logArgs[] = '%' . str_replace(['%', '_'], ['\%', '\_'], $logFiltros['message']) . '%';
}
// As datas são dias, não instantes: de 2026-01-01 ate 2026-01-31 inclui o
// dia 31 às 23:59.
if ($logFiltros['de'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $logFiltros['de']) === 1) {
    $logWhere[] = 'created_at >= ?';
    $logArgs[] = (int) strtotime($logFiltros['de'] . ' 00:00:00');
}
if ($logFiltros['ate'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $logFiltros['ate']) === 1) {
    $logWhere[] = 'created_at <= ?';
    $logArgs[] = (int) strtotime($logFiltros['ate'] . ' 23:59:59');
}

$logSql = 'SELECT * FROM api_log';
if ($logWhere !== []) {
    $logSql .= ' WHERE ' . implode(' AND ', $logWhere);
}
$logSql .= ' ORDER BY id DESC';

$logTotal = (int) ($db->value('SELECT COUNT(*) FROM api_log' . ($logWhere !== [] ? ' WHERE ' . implode(' AND ', $logWhere) : ''), $logArgs) ?? 0);
$logPaginas = max(1, (int) ceil($logTotal / $logPorPagina));
$logPagina = min($logPagina, $logPaginas);
$logOffset = ($logPagina - 1) * $logPorPagina;

$recentLog = $db->all($logSql . ' LIMIT ' . $logPorPagina . ' OFFSET ' . $logOffset, $logArgs);

// A exportação respeita os mesmos filtros, senão exportava sempre tudo.
if (isset($_GET['log_exportar'])) {
    $linhas = $db->all($logSql . ' LIMIT 5000', $logArgs);
    $nomeFicheiro = 'api-log-' . date('Ymd-His') . '.txt';

    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nomeFicheiro . '"');

    echo "Registo da API - SMSAPI Gateway\n";
    echo "Exportado em " . date('Y-m-d H:i:s') . "\n";
    echo "Filtros: " . (implode(', ', array_filter([
        $logFiltros['endpoint'] !== '' ? 'endpoint=' . $logFiltros['endpoint'] : '',
        $logFiltros['device_id'] !== '' ? 'telefone=' . $logFiltros['device_id'] : '',
        $logFiltros['ip'] !== '' ? 'ip=' . $logFiltros['ip'] : '',
        $logFiltros['code'] !== '' ? 'codigo=' . $logFiltros['code'] : '',
        $logFiltros['message'] !== '' ? 'mensagem=' . $logFiltros['message'] : '',
        $logFiltros['de'] !== '' ? 'de=' . $logFiltros['de'] : '',
        $logFiltros['ate'] !== '' ? 'ate=' . $logFiltros['ate'] : '',
    ])) ?: 'nenhum') . "\n";
    echo str_repeat('-', 100) . "\n";
    echo sprintf("%-20s %-12s %-8s %-16s %-5s %s\n", 'QUANDO', 'ENDPOINT', 'TELEFONE', 'IP', 'CODIGO', 'MENSAGEM');

    foreach ($linhas as $linha) {
        echo sprintf(
            "%-20s %-12s %-8s %-16s %-5s %s\n",
            date('Y-m-d H:i:s', (int) $linha['created_at']),
            substr((string) $linha['endpoint'], 0, 12),
            $linha['device_id'] === null ? '-' : '#' . (string) $linha['device_id'],
            substr((string) $linha['ip'], 0, 16),
            (string) $linha['code'],
            (string) ($linha['message'] ?? '')
        );
    }
    echo "\n" . count($linhas) . " registo(s) exportado(s)"
        . ($logTotal > count($linhas) ? ' de ' . $logTotal . ' no total (limite 5000)' : '') . "\n";

    return;
}

// As opcoes dos filtros vem dos proprios dados, para o utilizador nao ter de
// adivinhar o nome de um endpoint.
$logEndpoints = array_column($db->all('SELECT DISTINCT endpoint FROM api_log ORDER BY endpoint'), 'endpoint');

$apiBaseUrl = rtrim((string) (App::config()['public_base_url'] ?? ''), '/') . '/';
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SMSAPI Gateway · <?= Admin::e($tab) ?></title>
<?= SmsApi\Translate::overrideMeta() ?>
<?= SmsApi\Branding::favicon(1) ?>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>

<header class="topbar">
    <div class="brand">SMSAPI <span>Gateway</span></div>
    <nav>
        <?php foreach (['painel' => 'Painel', 'fila' => 'Fila', 'dispositivos' => 'Telefones', 'chaves' => 'Chaves API', 'utilizadores' => 'Utilizadores', 'email' => 'Email', 'definicoes' => 'Definições'] as $key => $label): ?>
            <a href="index.php?tab=<?= $key ?>" class="<?= $tab === $key ? 'active' : '' ?>"><?= $label ?></a>
        <?php endforeach; ?>
    </nav>
    <?= SmsApi\Translate::slot() ?>
    <span class="muted"><?= Admin::e((string) $_SESSION['admin_user']) ?></span>
    <a class="btn small" href="logout.php">Sair</a>
</header>

<main class="wrap">

<?php $avisoDb = SecurityCheck::aviso(); ?>
<?php if ($avisoDb !== null): ?>
        <p class="alert <?= $avisoDb['tipo'] ?>">
            <strong><?= Admin::e($avisoDb['titulo']) ?></strong>
            <?= Admin::e((string) ($_SESSION['smsapi_db_nota'] ?? '')) ?><br>
            O que fazer, por ordem. A primeira opção não precisa de acesso ao servidor:
            editar <code>config.php</code> e pôr
            <code>'sqlite_path' =&gt; '/home/SEU_USER/smsapi-data/database.sqlite'</code>,
            um caminho fora da pasta web. A segunda é bloquear a pasta
            <code>storage/</code> na configuração do servidor
            (Nginx: <code>location ~* ^/storage/ { deny all; }</code>; Apache:
            <code>AllowOverride All</code>). O <code>chmod</code> não resolve isto.
            Veja <code>docs/DEPLOY.md</code>.<br>
            <form method="post" action="action.php" style="margin-top:10px">
                <input type="hidden" name="csrf" value="<?= Admin::csrfToken() ?>">
                <input type="hidden" name="do" value="security_check">
                <button class="small" type="submit">Verificar outra vez</button>
            </form>
            <form method="post" action="action.php" style="display:inline;margin-top:10px">
                <input type="hidden" name="csrf" value="<?= Admin::csrfToken() ?>">
                <input type="hidden" name="do" value="security_check_dismiss">
                <button class="link" type="submit"
                        title="Esconde este aviso. Se a situação mudar, volta a aparecer.">Não voltar a lembrar-me</button>
            </form>
        </p>
    <?php endif; ?>

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
                    <p class="alert warn">Nenhum telefone registado ainda. Instale a app, abra-a e preencha o endereço e a chave acima.</p>
                <?php endif; ?>
            </section>
        </div>

        <section class="card">
            <h2>Últimas atividades da API <span class="badge"><?= $logTotal ?> registo<?= $logTotal === 1 ? '' : 's' ?></span></h2>

            <form method="get" class="filtros">
                <input type="hidden" name="tab" value="painel">

                <label for="f_endpoint">Endpoint</label>
                <select id="f_endpoint" name="f_endpoint">
                    <option value="">todos</option>
                    <?php foreach ($logEndpoints as $ep): ?>
                        <option value="<?= Admin::e($ep) ?>"<?= $logFiltros['endpoint'] === $ep ? ' selected' : '' ?>><?= Admin::e($ep) ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="f_device">Telefone</label>
                <input type="text" id="f_device" name="f_device" inputmode="numeric"
                       value="<?= Admin::e($logFiltros['device_id']) ?>" placeholder="ex.: 1">

                <label for="f_ip">IP</label>
                <input type="text" id="f_ip" name="f_ip" value="<?= Admin::e($logFiltros['ip']) ?>"
                       placeholder="ex.: 192.168.">

                <label for="f_code">Código</label>
                <input type="text" id="f_code" name="f_code" inputmode="numeric"
                       value="<?= Admin::e($logFiltros['code']) ?>" placeholder="ex.: 429">

                <label for="f_message">Mensagem</label>
                <input type="text" id="f_message" name="f_message"
                       value="<?= Admin::e($logFiltros['message']) ?>" placeholder="parte do texto">

                <label for="f_de">De</label>
                <input type="date" id="f_de" name="f_de" value="<?= Admin::e($logFiltros['de']) ?>">

                <label for="f_ate">Até</label>
                <input type="date" id="f_ate" name="f_ate" value="<?= Admin::e($logFiltros['ate']) ?>">

                <div class="filtros-acoes">
                    <button class="small" type="submit">Filtrar</button>
                    <a class="btn small" href="index.php?tab=painel">Limpar</a>
                    <a class="btn small" href="<?= Admin::e(Admin::buildQuery(['log_exportar' => 1, 'tab' => 'painel'])) ?>">Exportar</a>
                </div>
            </form>

            <?php if ($recentLog === []): ?>
                <p class="muted"><?= $logTotal === 0 && array_filter($logFiltros) !== []
                    ? 'Nenhum registo corresponde aos filtros.' : 'Sem registos.' ?></p>
            <?php else: ?>
                <table>
                    <thead><tr><th>Quando</th><th>Endpoint</th><th>Telefone</th><th>IP</th><th>Código</th><th>Mensagem</th></tr></thead>
                    <tbody>
                    <?php foreach ($recentLog as $row): ?>
                        <tr class="<?= (int) $row['code'] >= 400 ? 'row-bad' : '' ?>">
                            <td><?= Admin::e(date('Y-m-d H:i', (int) $row['created_at'])) ?></td>
                            <td><code><?= Admin::e((string) $row['endpoint']) ?></code></td>
                            <td>#<?= Admin::e((string) ($row['device_id'] ?? '—')) ?></td>
                            <td><code><?= Admin::e((string) $row['ip']) ?></code></td>
                            <td><?= (int) $row['code'] ?></td>
                            <td><?= Admin::e((string) ($row['message'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($logPaginas > 1): ?>
                    <p class="paginacao">
                        <?php if ($logPagina > 1): ?>
                            <a class="btn small" href="<?= Admin::e(Admin::buildQuery(['log_pagina' => $logPagina - 1])) ?>">&larr; Anterior</a>
                        <?php endif; ?>
                        <span class="muted">Página <?= $logPagina ?> de <?= $logPaginas ?></span>
                        <?php if ($logPagina < $logPaginas): ?>
                            <a class="btn small" href="<?= Admin::e(Admin::buildQuery(['log_pagina' => $logPagina + 1])) ?>">Seguinte &rarr;</a>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
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

    <!-- ================================================= UTILIZADORES -->
    <?php if ($tab === 'utilizadores'): ?>
        <?php
        $users = App::ensureUsers();
        $userRows = $users->all();
        $editUser = isset($_GET['edit_user'])
            ? $users->find((int) $_GET['edit_user'])
            : null;
        ?>
        <section class="card">
            <h2>Utilizadores do painel</h2>
            <p class="hint">
                Todas as contas entram no painel com a mesma autoridade. Preencha o
                telefone ou o email de quem tem conta para que possa recuperar o
                acesso: a página <a href="recuperar.php">recuperar.php</a> manda um
                código de 6 dígitos para esse contacto.
            </p>
            <p class="hint">
                A conta que existia no <code>config.php</code> foi copiada para aqui na
                primeira vez que este separador foi aberto. A palavra-passe é a mesma.
            </p>

            <table>
                <thead>
                <tr>
                    <th>Utilizador</th>
                    <th>Contacto</th>
                    <th>Estado</th>
                    <th>Último acesso</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($userRows as $row): ?>
                    <?php
                    $rowId = (int) $row['id'];
                    $bloqueado = $row['locked_until'] !== null && (int) $row['locked_until'] > time();
                    $euMesmo = $rowId === Admin::currentUserId();
                    ?>
                    <tr>
                        <td>
                            <?= Admin::e((string) $row['username']) ?>
                            <?php if ($euMesmo): ?>
                                <span class="pill sent">você</span>
                            <?php endif; ?>
                            <?php if ((string) $row['display_name'] !== '' && (string) $row['display_name'] !== (string) $row['username']): ?>
                                <br><span class="muted"><?= Admin::e((string) $row['display_name']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (($row['email'] ?? '') !== ''): ?>
                                <?= Admin::e((string) $row['email']) ?>
                            <?php else: ?>
                                <span class="muted">sem email</span>
                            <?php endif; ?>
                            <?php if (($row['phone'] ?? '') !== ''): ?>
                                <br><span class="muted"><?= Admin::e((string) $row['phone']) ?></span>
                            <?php endif; ?>
                            <?php if (($row['email'] ?? '') === '' && ($row['phone'] ?? '') === ''): ?>
                                <br><span class="pill failed">sem recuperação</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int) $row['is_active'] !== 1): ?>
                                <span class="pill failed">desativado</span>
                            <?php elseif ($bloqueado): ?>
                                <span class="pill pending">bloqueado</span>
                            <?php else: ?>
                                <span class="pill sent">ativo</span>
                            <?php endif; ?>
                        </td>
                        <td><?= Admin::relative($row['last_login_at'] === null ? null : (int) $row['last_login_at']) ?></td>
                        <td class="actions">
                            <a class="btn small" href="index.php?tab=utilizadores&amp;edit_user=<?= $rowId ?>">Editar</a>
                            <?php if (!$euMesmo): ?>
                                <form method="post" action="action.php" class="inline">
                                    <input type="hidden" name="csrf" value="<?= Admin::csrfToken() ?>">
                                    <input type="hidden" name="do" value="<?= (int) $row['is_active'] === 1 ? 'deactivate_user' : 'activate_user' ?>">
                                    <input type="hidden" name="id" value="<?= $rowId ?>">
                                    <button class="small <?= (int) $row['is_active'] === 1 ? 'danger' : '' ?>" type="submit">
                                        <?= (int) $row['is_active'] === 1 ? 'Desativar' : 'Ativar' ?>
                                    </button>
                                </form>
                                <form method="post" action="action.php" class="inline"
                                      onsubmit="return confirm('Apagar a conta &quot;<?= Admin::e((string) $row['username']) ?>&quot;? Esta acção não se desfaz.')">
                                    <input type="hidden" name="csrf" value="<?= Admin::csrfToken() ?>">
                                    <input type="hidden" name="do" value="delete_user">
                                    <input type="hidden" name="id" value="<?= $rowId ?>">
                                    <button class="small danger" type="submit">Apagar</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <section class="card">
            <?php if ($editUser !== null): ?>
                <h2>Editar conta · <?= Admin::e((string) $editUser['username']) ?></h2>
                <form method="post" action="action.php">
                    <input type="hidden" name="csrf" value="<?= Admin::csrfToken() ?>">
                    <input type="hidden" name="do" value="update_user">
                    <input type="hidden" name="id" value="<?= (int) $editUser['id'] ?>">

                    <label for="u_username">Nome de utilizador</label>
                    <input type="text" id="u_username" name="username" maxlength="60"
                           value="<?= Admin::e((string) $editUser['username']) ?>" required>

                    <label for="u_display">Nome a mostrar</label>
                    <input type="text" id="u_display" name="display_name" maxlength="120"
                           value="<?= Admin::e((string) $editUser['display_name']) ?>">

                    <label for="u_email">Email</label>
                    <input type="email" id="u_email" name="email" maxlength="190"
                           value="<?= Admin::e((string) ($editUser['email'] ?? '')) ?>">
                    <p class="hint">Usado para recuperação de palavra-passe.</p>

                    <label for="u_phone">Telefone</label>
                    <input type="text" id="u_phone" name="phone" maxlength="32"
                           value="<?= Admin::e((string) ($editUser['phone'] ?? '')) ?>">
                    <p class="hint">Com formato internacional, por exemplo +351912345678. Recebe o código por SMS.</p>

                    <label for="u_password">Palavra-passe nova</label>
                    <input type="password" id="u_password" name="password" minlength="10" autocomplete="new-password">
                    <p class="hint">Deixe em branco para não alterar a actual.</p>

                    <button class="primary" type="submit">Guardar</button>
                    <a class="btn" href="index.php?tab=utilizadores">Cancelar</a>
                </form>
            <?php else: ?>
                <h2>Nova conta</h2>
                <form method="post" action="action.php">
                    <input type="hidden" name="csrf" value="<?= Admin::csrfToken() ?>">
                    <input type="hidden" name="do" value="create_user">

                    <label for="n_username">Nome de utilizador</label>
                    <input type="text" id="n_username" name="username" maxlength="60" required>

                    <label for="n_display">Nome a mostrar</label>
                    <input type="text" id="n_display" name="display_name" maxlength="120">
                    <p class="hint">Opcional. Se ficar vazio, usa-se o nome de utilizador.</p>

                    <label for="n_email">Email</label>
                    <input type="email" id="n_email" name="email" maxlength="190">

                    <label for="n_phone">Telefone</label>
                    <input type="text" id="n_phone" name="phone" maxlength="32" placeholder="+351912345678">

                    <label for="n_password">Palavra-passe</label>
                    <input type="password" id="n_password" name="password" minlength="10" required autocomplete="new-password">
                    <p class="hint">Pelo menos 10 caracteres.</p>

                    <button class="primary" type="submit">Criar conta</button>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <!-- ============================================================ EMAIL -->
    <?php if ($tab === 'email'): ?>
        <?php
        $mail = App::mailer();
        $mailSettings = $mail->settings();
        $transportes = [
            '' => 'Não configurado (email desactivado)',
            'smtp' => 'SMTP com STARTTLS (porta 587) — o mais comum',
            'tls' => 'SMTP com TLS já ligado (porta 465)',
            'sendmail' => 'Programa local de envio (sendmail)',
        ];
        ?>
        <div class="grid-2">
            <section class="card">
                <h2>Servidor de email</h2>
                <p class="hint">
                    Usado para a recuperação de palavra-passe e para avisos do painel.
                    Sem isto, a recuperação só funciona por SMS.
                </p>

                <form method="post" action="action.php">
                    <input type="hidden" name="csrf" value="<?= Admin::csrfToken() ?>">
                    <input type="hidden" name="do" value="mail_settings">

                    <label for="m_transport">Transporte</label>
                    <select id="m_transport" name="mail_transport">
                        <?php foreach ($transportes as $valor => $rotulo): ?>
                            <option value="<?= Admin::e($valor) ?>" <?= $mailSettings['transport'] === $valor ? 'selected' : '' ?>>
                                <?= Admin::e($rotulo) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="m_host">Servidor SMTP</label>
                    <input type="text" id="m_host" name="smtp_host" maxlength="190"
                           value="<?= Admin::e($mailSettings['host']) ?>" placeholder="smtp.gmail.com">

                    <label for="m_port">Porta</label>
                    <input type="number" id="m_port" name="smtp_port" min="1" max="65535"
                           value="<?= Admin::e($mailSettings['port'] !== '' ? $mailSettings['port'] : '587') ?>">

                    <label for="m_encryption">Encriptação</label>
                    <select id="m_encryption" name="smtp_encryption">
                        <option value="tls" <?= $mailSettings['encryption'] === 'tls' ? 'selected' : '' ?>>STARTTLS (recomendado)</option>
                        <option value="none" <?= $mailSettings['encryption'] === 'none' ? 'selected' : '' ?>>Nenhuma (só em servidor local)</option>
                    </select>

                    <label for="m_user">Utilizador</label>
                    <input type="text" id="m_user" name="smtp_username" maxlength="190" autocomplete="off"
                           value="<?= Admin::e($mailSettings['username']) ?>">

                    <label for="m_pass">Palavra-passe</label>
                    <input type="password" id="m_pass" name="smtp_password" autocomplete="off"
                           value="<?= Admin::e($mailSettings['password']) ?>">
                    <p class="hint">Guardada no <code>config.php</code>, que está bloqueado no servidor.</p>

                    <label for="m_sendmail">Caminho do sendmail</label>
                    <input type="text" id="m_sendmail" name="mail_sendmail" maxlength="190"
                           value="<?= Admin::e($mailSettings['sendmail']) ?>">

                    <label for="m_from_email">Email do remetente</label>
                    <input type="email" id="m_from_email" name="mail_from_email" maxlength="190"
                           value="<?= Admin::e($mailSettings['from_email']) ?>">

                    <label for="m_from_name">Nome do remetente</label>
                    <input type="text" id="m_from_name" name="mail_from_name" maxlength="120"
                           value="<?= Admin::e($mailSettings['from_name']) ?>">

                    <button class="primary" type="submit">Guardar</button>
                </form>
            </section>

            <section class="card">
                <h2>Testar o envio</h2>
                <p class="hint">
                    Estado actual:
                    <?php if ($mail->isConfigured()): ?>
                        <span class="pill sent">configurado</span>
                    <?php else: ?>
                        <span class="pill failed">não configurado</span>
                    <?php endif; ?>
                </p>
                <p class="hint">
                    Manda um email para si próprio e confirma que chega. Sem isto,
                    é fácil só descobrir que o email não funciona no dia em que
                    alguém precisa de recuperar a palavra-passe.
                </p>

                <form method="post" action="action.php">
                    <input type="hidden" name="csrf" value="<?= Admin::csrfToken() ?>">
                    <input type="hidden" name="do" value="test_mail">

                    <label for="t_email">Enviar para</label>
                    <input type="email" id="t_email" name="to" maxlength="190"
                           value="<?= Admin::e((string) ($_POST['to'] ?? '')) ?>"
                           placeholder="o.seu.email@exemplo.com" required>

                    <button class="primary" type="submit">Enviar email de teste</button>
                </form>

                <h2 style="margin-top:26px">Notas</h2>
                <p class="hint">
                    <strong>Gmail</strong> não aceita password de conta. É preciso uma
                    <em>palavra-passe de aplicação</em> e ligado o acesso menos seguro.
                </p>
                <p class="hint">
                    <strong>Office 365 / Outlook</strong> pode exigir SMTP autenticado
                    na porta 587.
                </p>
                <p class="hint">
                    Se a sua hospedagem não der dados SMTP, escolha <em>sendmail</em>:
                    muitos servidores já têm um programa de envio local a funcionar.
                </p>
            </section>
        </div>
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
                <?php
                // A data vive em app.installed_at. Instalacoes feitas antes de
                // esse campo existir nao o tem, e ai mostra-se a data do proprio
                // ficheiro, que e a melhor aproximacao disponivel.
                $instaladoEm = (string) (App::config()['app']['installed_at'] ?? '');
                if ($instaladoEm === '' && is_file(SmsApi\Config::path())) {
                    $mtime = filemtime(SmsApi\Config::path());
                    $instaladoEm = $mtime === false ? '' : date('Y-m-d H:i', $mtime);
                }
                ?>
                <p class="muted">Instalado em <?= Admin::e($instaladoEm !== '' ? $instaladoEm : '—') ?></p>
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
