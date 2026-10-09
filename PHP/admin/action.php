<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once __DIR__ . '/Admin.php';

use SmsApi\Admin\Admin;
use SmsApi\App;
use SmsApi\Config;
use SmsApi\Queue;

Admin::start();
Admin::requireLogin();

if (!App::isInstalled()) {
    Admin::redirect('../install.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Admin::redirect('index.php');
}

Admin::checkCsrf();

$db = App::db();
$queue = App::queue();
$do = (string) ($_POST['do'] ?? '');
$id = (int) ($_POST['id'] ?? 0);

try {
    switch ($do) {
        case 'enqueue':
            $result = $queue->enqueue(
                (string) ($_POST['phone'] ?? ''),
                (string) ($_POST['body'] ?? ''),
                ($_POST['device_id'] ?? '') !== '' ? (int) $_POST['device_id'] : null,
                trim((string) ($_POST['reference'] ?? '')) ?: null,
                isset($_POST['to_gsm7'])
            );
            if (!empty($result['report'])) {
                Admin::flash((string) $result['report']);
            }
            Admin::redirect('index.php?tab=painel&sent=' . $result['id']);

        case 'requeue':
            $queue->requeue($id)
                ? Admin::flash("Mensagem #{$id} reposta na fila.")
                : Admin::flash("Mensagem #{$id} não existe.", 'error');
            Admin::redirect('index.php?tab=fila');

        case 'delete':
            $queue->delete($id)
                ? Admin::flash("Mensagem #{$id} apagada.")
                : Admin::flash("Mensagem #{$id} não existe.", 'error');
            Admin::redirect('index.php?tab=fila');

        case 'revoke':
            $db->run('UPDATE devices SET is_active = 0 WHERE id = ?', [$id]);
            Admin::flash("Telefone #{$id} revogado. Deixe de receber SMS.", 'error');
            Admin::redirect('index.php?tab=dispositivos');

        case 'restore':
            $db->run('UPDATE devices SET is_active = 1 WHERE id = ?', [$id]);
            Admin::flash("Telefone #{$id} reativado.");
            Admin::redirect('index.php?tab=dispositivos');

        case 'reissue':
            // Invalidates the stored token; the phone gets a new one on its next register call.
            $db->run('UPDATE devices SET token_hash = ?, token_prefix = ? WHERE id = ?', [bin2hex(random_bytes(32)), 'pending', $id]);
            Admin::flash("Telefone #{$id} tem de se registar de novo para voltar a funcionar.");
            Admin::redirect('index.php?tab=dispositivos');

        case 'purge':
            $db->run('DELETE FROM devices WHERE id = ?', [$id]);
            Admin::flash("Telefone #{$id} apagado.");
            Admin::redirect('index.php?tab=dispositivos');

        // ----------------------------------------------- chaves de API externa
        case 'create_key':
            $ipList = array_values(array_filter(
                array_map('trim', explode(',', (string) ($_POST['ip_allowlist'] ?? ''))),
                'strlen'
            ));
            $created = App::queueKeys()->create(
                (string) ($_POST['name'] ?? ''),
                max(1, min(600, (int) ($_POST['rate_per_min'] ?? 60))),
                max(0, min(100000, (int) ($_POST['max_per_day'] ?? 0))),
                max(0, min(30, (int) ($_POST['wait_seconds'] ?? 0))),
                $ipList
            );
            Admin::showSecretOnce($created['key']);
            Admin::flash('Chave criada. Copie-a abaixo antes de sair desta página.');
            Admin::redirect('index.php?tab=chaves');

        case 'revoke_key':
            App::queueKeys()->revoke($id)
                ? Admin::flash("Chave #{$id} revogada. Os pedidos dela deixam de ser aceites.", 'error')
                : Admin::flash("Chave #{$id} não existe.", 'error');
            Admin::redirect('index.php?tab=chaves');

        case 'restore_key':
            App::queueKeys()->reactivate($id)
                ? Admin::flash("Chave #{$id} reativada.")
                : Admin::flash("Chave #{$id} não existe.", 'error');
            Admin::redirect('index.php?tab=chaves');

        case 'settings':
            $settings = App::config()['settings'];
            $settings['forced_poll_interval'] = max(0, min(86400, (int) ($_POST['forced_poll_interval'] ?? 0)));
            $settings['max_per_poll'] = max(1, min(100, (int) ($_POST['max_per_poll'] ?? 25)));
            $settings['max_segments'] = max(0, min(10, (int) ($_POST['max_segments'] ?? 3)));
            $settings['max_attempts'] = max(1, min(20, (int) ($_POST['max_attempts'] ?? 3)));
            $settings['clock_skew'] = max(30, min(3600, (int) ($_POST['clock_skew'] ?? 300)));
            $settings['rate_limit'] = max(0, min(10000, (int) ($_POST['rate_limit'] ?? 120)));
            $settings['online_threshold'] = max(60, min(86400, (int) ($_POST['online_threshold'] ?? 300)));
            writeConfigWithSettings($settings);
            Admin::flash('Definições guardadas. Os telefone recebem os novos valores na próxima verificação.');
            Admin::redirect('index.php?tab=definicoes');

        case 'rotate_key':
            $newKey = trim((string) ($_POST['new_key'] ?? ''));
            if ($newKey === '') {
                $newKey = Config::generateApiKey();
            }
            if (strlen($newKey) < Config::MIN_KEY_LENGTH) {
                Admin::flash('A chave da API tem de ter pelo menos ' . Config::MIN_KEY_LENGTH . ' caracteres.', 'error');
                Admin::redirect('index.php?tab=definicoes');
            }
            $config = App::config();
            $config['api_key'] = $newKey;
            $config['settings']['rotate_all_tokens_at'] = time();
            writeConfig($config);
            $db->exec('DELETE FROM devices');
            Admin::flash('Chave da API rodada. Todos os telefone foram removidos e terão de se registar de novo.');
            Admin::redirect('index.php?tab=definicoes');

        default:
            Admin::flash('Ação desconhecida.', 'error');
            Admin::redirect('index.php');
    }
} catch (Throwable $e) {
    Admin::flash('Erro: ' . $e->getMessage(), 'error');
    Admin::redirect('index.php');
}

function writeConfigWithSettings(array $settings): void
{
    $config = App::config();
    $config['settings'] = $settings;
    writeConfig($config);
}

function writeConfig(array $config): void
{
    $path = Config::path();
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, Config::export($config), LOCK_EX) === false) {
        throw new RuntimeException("Não foi possível escrever {$path}. Verifique as permissões da pasta.");
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException("Não foi possível substituir {$path}.");
    }
}