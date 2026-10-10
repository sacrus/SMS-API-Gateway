<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once __DIR__ . '/Admin.php';

use SmsApi\Admin\Admin;
use SmsApi\App;
use SmsApi\ApiException;
use SmsApi\Config;
use SmsApi\MailException;
use SmsApi\SecurityCheck;
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

        // ------------------------------------------------- utilizadores
        case 'create_user':
            $novoId = App::ensureUsers()->create(
                (string) ($_POST['username'] ?? ''),
                (string) ($_POST['password'] ?? ''),
                (string) ($_POST['email'] ?? ''),
                (string) ($_POST['phone'] ?? ''),
                (string) ($_POST['display_name'] ?? '')
            );
            Admin::flash("Conta criada. Já pode entrar no painel com as credenciais indicadas.");
            Admin::redirect('index.php?tab=utilizadores&edit_user=' . $novoId);

        case 'update_user':
            $users = App::ensureUsers();
            $users->update($id, [
                'username' => (string) ($_POST['username'] ?? ''),
                'display_name' => (string) ($_POST['display_name'] ?? ''),
                'email' => (string) ($_POST['email'] ?? ''),
                'phone' => (string) ($_POST['phone'] ?? ''),
            ]);
            $novaSenha = (string) ($_POST['password'] ?? '');
            if ($novaSenha !== '') {
                $users->setPassword($id, $novaSenha);
                Admin::flash('Conta actualizada e palavra-passe alterada.');
            } else {
                Admin::flash('Conta actualizada.');
            }
            Admin::redirect('index.php?tab=utilizadores');

        case 'deactivate_user':
            App::ensureUsers()->setActive($id, false, Admin::currentUserId());
            Admin::flash('Conta desativada. Não pode mais entrar no painel.', 'error');
            Admin::redirect('index.php?tab=utilizadores');

        case 'activate_user':
            App::ensureUsers()->setActive($id, true, Admin::currentUserId());
            Admin::flash('Conta ativada.');
            Admin::redirect('index.php?tab=utilizadores');

        case 'delete_user':
            App::ensureUsers()->delete($id, Admin::currentUserId());
            Admin::flash('Conta apagada.');
            Admin::redirect('index.php?tab=utilizadores');

        // --------------------------------------------------------- email
        case 'mail_settings':
            $settings = App::config()['settings'];
            $transporte = (string) ($_POST['mail_transport'] ?? '');
            if (!in_array($transporte, ['', 'smtp', 'tls', 'sendmail'], true)) {
                $transporte = '';
            }

            $settings['mail_transport'] = $transporte;
            $settings['smtp_host'] = mb_substr(trim((string) ($_POST['smtp_host'] ?? '')), 0, 190);
            $settings['smtp_port'] = (string) max(1, min(65535, (int) ($_POST['smtp_port'] ?? 587)));
            $settings['smtp_username'] = mb_substr(trim((string) ($_POST['smtp_username'] ?? '')), 0, 190);
            // Campo vazio = não mexer. Senão guardar o formulário apagava a senha.
            $senha = (string) ($_POST['smtp_password'] ?? '');
            if ($senha !== '') {
                $settings['smtp_password'] = $senha;
            }
            $settings['smtp_encryption'] = (string) ($_POST['smtp_encryption'] ?? 'tls') === 'none' ? 'none' : 'tls';
            $settings['mail_sendmail'] = mb_substr(trim((string) ($_POST['mail_sendmail'] ?? '')), 0, 190);
            $settings['mail_from_email'] = mb_substr(trim((string) ($_POST['mail_from_email'] ?? '')), 0, 190);
            $settings['mail_from_name'] = mb_substr(trim((string) ($_POST['mail_from_name'] ?? 'SMSAPI Gateway')), 0, 120);

            writeConfigWithSettings($settings);
            Admin::flash('Definições de email guardadas. Use "Enviar email de teste" para confirmar que funciona.');
            Admin::redirect('index.php?tab=email');

        case 'test_mail':
            $destino = trim((string) ($_POST['to'] ?? ''));
            App::mailer()->send(
                $destino,
                'Teste do SMSAPI Gateway',
                '<p style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#222;">'
                . 'Este é um email de teste do <strong>SMSAPI Gateway</strong>.</p>'
                . '<p style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#222;">'
                . 'Se o senhor está a ler isto, o email está configurado e a funcionar.</p>'
            );
            Admin::flash('Email de teste enviado para ' . $destino . '. Verifique a pasta de spam.');
            Admin::redirect('index.php?tab=email');

// ------------------------------------------------- esconder o aviso
        case 'security_check_dismiss':
            // O administrador diz que já confirmou a protecção. A dispensa fica
            // presa ao estado em que foi dada: se a situação mudar, o aviso volta.
            try {
                SecurityCheck::dispensar();
                Admin::flash('Aviso escondido. Se a situação mudar, volta a aparecer.');
            } catch (Throwable $e) {
                Admin::flash('Não foi possível esconder o aviso: ' . $e->getMessage(), 'error');
            }
            Admin::redirect('index.php?tab=painel');

// ------------------------------------------------- verificar seguranca
        case 'security_check':
            // A verificação lê a configuração em disco, por isso é instantânea e
            // pode correr em qualquer momento. Este botão existe para o
            // administrador confirmar depois de mexer no servidor.
            $r = SecurityCheck::verificar();
            Admin::flash($r['mensagem'], $r['tipo'] === 'error' ? 'error' : ($r['tipo'] === 'warn' ? 'ok' : 'ok'));
            Admin::redirect('index.php?tab=painel');

        default:
            Admin::flash('Ação desconhecida.', 'error');
            Admin::redirect('index.php');
    }
} catch (ApiException $e) {
    // Erros previstos (nome em uso, senha curta, contacto inválido): a
    // mensagem é para o administrador e não expõe detalhes internos.
    Admin::flash($e->getMessage(), 'error');
    Admin::redirect(refererTab($do));
} catch (MailException $e) {
    Admin::flash('Email: ' . $e->getMessage(), 'error');
    Admin::redirect('index.php?tab=email');
} catch (Throwable $e) {
    Admin::flash('Erro: ' . $e->getMessage(), 'error');
    Admin::redirect('index.php');
}

/** Volta ao separador certo depois de um erro, em vez de sempre ao painel. */
function refererTab(string $do): string
{
    return str_starts_with($do, 'mail_') || $do === 'test_mail'
        ? 'index.php?tab=email'
        : 'index.php?tab=utilizadores';
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
