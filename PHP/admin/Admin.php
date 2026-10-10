<?php

declare(strict_types=1);

namespace SmsApi\Admin;

use SmsApi\App;
use SmsApi\Config;

/** Session bootstrap, login guard and CSRF helpers shared by the admin pages. */
final class Admin
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'secure' => $secure,
            'samesite' => 'Lax',
        ]);
        session_name('smsapi_admin');
        session_start();
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['csrf'];
    }

    public static function checkCsrf(): void
    {
        $token = (string) ($_POST['csrf'] ?? '');
        if ($token === '' || !hash_equals(self::csrfToken(), $token)) {
            self::flash('Sessão expirada ou pedido inválido. Tente novamente.', 'error');
            self::redirect('index.php');
        }
    }

    public static function isLoggedIn(): bool
    {
        return !empty($_SESSION['admin_user_id']);
    }

    public static function requireLogin(): void
    {
        if (self::isLoggedIn()) {
            // A conta pode ter sido desactivada ou apagada por outro
            // administrador enquanto esta sessão estava aberta.
            if (App::ensureUsers()->find((int) $_SESSION['admin_user_id']) !== null) {
                return;
            }
            self::logout();
        }
        self::redirect('login.php');
    }

    /** A conta com que esta sessão foi iniciada. */
    public static function currentUserId(): int
    {
        return (int) ($_SESSION['admin_user_id'] ?? 0);
    }

    /**
     * Verifica as credenciais.
     *
     * @return array{ok:bool, reason:?string}
     */
    public static function attempt(string $user, string $password, string $ip = ''): array
    {
        $result = App::ensureUsers()->attempt($user, $password, $ip);
        return ['ok' => $result['ok'], 'reason' => $result['reason']];
    }

    public static function login(int $userId, string $user): void
    {
        session_regenerate_id(true);
        $_SESSION['admin_user_id'] = $userId;
        $_SESSION['admin_user'] = $user;
        $_SESSION['login_at'] = time();
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public static function flash(string $message, string $type = 'success'): void
    {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
    }

    public static function takeFlash(): ?array
    {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return $flash;
    }

    /**
     * Segredo para mostrar uma vez, depois de criado.
     *
     * Vai pela sessão e nunca pela URL: um segredo no query string fica no
     * histórico do navegador, nos logs do servidor e no cabeçalho Referer — o
     * mesmo problema que a API externa resolve mandando a chave no cabeçalho.
     */
    public static function showSecretOnce(string $secret): void
    {
        $_SESSION['one_time_secret'] = $secret;
    }

    /** Lê e apaga: a segunda visita à página já não mostra nada. */
    public static function takeSecret(): ?string
    {
        $secret = $_SESSION['one_time_secret'] ?? null;
        unset($_SESSION['one_time_secret']);
        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    public static function redirect(string $path): never
    {
        $base = self::baseUrl();
        header('Location: ' . $base . $path);
        exit;
    }

    /** Absolute base URL of the admin folder, so redirects survive sub-directory installs. */
    public static function baseUrl(): string
    {
        $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/admin/index.php')));
        return rtrim($dir, '/') . '/';
    }

    public static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Constrói a query string da página a partir dos filtros que já estão na URL.
     *
     * Serve para os botões de paginação e de exportação manterem os filtros que o
     * administrador escolheu, em vez de voltarem a mostrar tudo. Sem isto, carregar
     * em "Seguinte" apagava o filtro e o filtro parecia não funcionar.
     *
     * @param array<string, scalar|null> $mudar valores a trocar ou acrescentar; null apaga
     */
    public static function buildQuery(array $mudar = []): string
    {
        $params = array_merge($_GET, $mudar);
        $params = array_filter(
            $params,
            static fn ($v): bool => $v !== null && $v !== '' && $v !== false
        );

        return 'index.php?' . http_build_query($params);
    }

    public static function timestamp(?int $ts): string
    {
        if ($ts === null || $ts <= 0) {
            return '—';
        }
        return date('d/m/Y H:i:s', $ts);
    }

    public static function relative(?int $ts): string
    {
        if ($ts === null || $ts <= 0) {
            return 'nunca';
        }
        $delta = time() - $ts;
        if ($delta < 0) {
            return 'agora';
        }
        if ($delta < 60) {
            return "há {$delta}s";
        }
        if ($delta < 3600) {
            return 'há ' . intdiv($delta, 60) . 'min';
        }
        if ($delta < 86400) {
            return 'há ' . intdiv($delta, 3600) . 'h';
        }
        return 'há ' . intdiv($delta, 86400) . 'd';
    }

    public static function apiKey(): string
    {
        return (string) App::config()['api_key'];
    }
}