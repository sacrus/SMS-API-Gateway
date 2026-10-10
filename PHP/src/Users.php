<?php

declare(strict_types=1);

namespace SmsApi;

/**
 * Contas do painel de administração.
 *
 * Antes havia um único administrador, guardado no config.php. A partir de agora
 * vivem numa tabela, para se poderem criar vários. A conta do config.php é
 * copiada para a tabela na primeira utilização, por isso uma instalação
 * existente continua a entrar com a mesma palavra-passe sem tocar em nada.
 *
 * As palavras-passe nunca são guardadas em claro: só o hash de password_hash(),
 * que não permite recuperar a original.
 */
final class Users
{
    /** Tentativas falhadas antes de a conta ficar bloqueada. */
    private const MAX_ATTEMPTS = 8;

    /** Quanto tempo a conta fica bloqueada, em segundos. */
    private const LOCK_SECONDS = 900;

    /** Hash falso, usado para gastar o mesmo tempo quando o utilizador não existe. */
    private const DUMMY_HASH = '$2y$10$usesomesillystringfor.e6zk2hZ0YQW4w5i4uWvS0lFbp1lJq0K';

    public function __construct(
        private readonly Database $db
    ) {
    }

    /**
     * Copia a conta do config.php para a tabela, uma única vez.
     *
     * Sem isto, uma instalação já feita ficaria com a tabela vazia e o
     * administrador deixaria de conseguir entrar.
     */
    public function ensureBootstrap(array $config): void
    {
        if ((int) $this->db->value('SELECT COUNT(*) FROM admin_users') > 0) {
            return;
        }

        $username = trim((string) ($config['admin_user'] ?? ''));
        $hash = (string) ($config['admin_hash'] ?? '');
        if ($username === '' || $hash === '') {
            return;
        }

        $this->db->insert(
            'INSERT INTO admin_users (username, password_hash, display_name, is_active, created_at)
             VALUES (?, ?, ?, 1, ?)',
            [mb_substr($username, 0, 60), $hash, mb_substr($username, 0, 120), time()]
        );
    }

    /** @return list<array> */
    public function all(): array
    {
        return $this->db->all('SELECT * FROM admin_users ORDER BY is_active DESC, username ASC');
    }

    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM admin_users WHERE id = ?', [$id]);
    }

    public function findByUsername(string $username): ?array
    {
        $username = trim($username);
        if ($username === '') {
            return null;
        }

        $exact = $this->db->one('SELECT * FROM admin_users WHERE username = ?', [$username]);
        if ($exact !== null) {
            return $exact;
        }

        // Não se usa "COLLATE NOCASE": essa sintaxe só existe no SQLite e o
        // MySQL não a conhece. São meia dúzia de contas, por isso compara-se
        // em PHP — funciona igual nos dois motores.
        foreach ($this->db->all('SELECT * FROM admin_users') as $row) {
            if (strcasecmp((string) $row['username'], $username) === 0) {
                return $row;
            }
        }

        return null;
    }

    public function countActive(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM admin_users WHERE is_active = 1');
    }

    /**
     * Verifica a palavra-passe e regista o início de sessão.
     *
     * @return array{ok:bool, reason:?string, user:?array}
     */
    public function attempt(string $username, string $password, string $ip): array
    {
        $user = $this->findByUsername($username);

        if ($user === null) {
            // Gasta-se o mesmo tempo quando o utilizador não existe, para não
            // se descobrir quem tem conta pelo tempo que o servidor demora.
            password_verify($password, self::DUMMY_HASH);
            return ['ok' => false, 'reason' => 'invalid_credentials', 'user' => null];
        }

        if ((int) $user['is_active'] !== 1) {
            password_verify($password, self::DUMMY_HASH);
            return ['ok' => false, 'reason' => 'account_disabled', 'user' => $user];
        }

        if ($user['locked_until'] !== null && (int) $user['locked_until'] > time()) {
            password_verify($password, self::DUMMY_HASH);
            return ['ok' => false, 'reason' => 'account_locked', 'user' => $user];
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            $this->registerFailure($user);
            return ['ok' => false, 'reason' => 'invalid_credentials', 'user' => $user];
        }

        // Rehash se o custo do algoritmo subiu desde que a senha foi criada.
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            $this->db->run(
                'UPDATE admin_users SET password_hash = ? WHERE id = ?',
                [password_hash($password, PASSWORD_DEFAULT), (int) $user['id']]
            );
        }

        $this->db->run(
            'UPDATE admin_users SET failed_attempts = 0, locked_until = NULL, last_login_at = ?, last_login_ip = ?
             WHERE id = ?',
            [time(), $ip, (int) $user['id']]
        );

        return ['ok' => true, 'reason' => null, 'user' => $user];
    }

    /**
     * Regista uma tentativa falhada e bloqueia quando chega ao limite.
     *
     * A contagem guarda-se sempre. Uma versão anterior guardava zero enquanto
     * não estivesse bloqueada, o que fazia o contador não passar nunca de 1 e
     * o bloqueio nunca chegava a acontecer — exactamente o oposto do que se
     * quer.
     */
    private function registerFailure(array $user): void
    {
        $attempts = (int) $user['failed_attempts'] + 1;
        $lockedUntil = $attempts >= self::MAX_ATTEMPTS ? time() + self::LOCK_SECONDS : null;

        $this->db->run(
            'UPDATE admin_users SET failed_attempts = ?, locked_until = ? WHERE id = ?',
            [$attempts, $lockedUntil, (int) $user['id']]
        );
    }

    /**
     * Cria a conta inicial a partir de um hash já feito.
     *
     * Usado pelo instalador, onde a palavra-passe já foi validada e guardada
     * com password_hash() noutro sítio. Evita criar a conta com uma senha
     * descartável só para a substituir a seguir.
     *
     * @throws ApiException
     */
    public function createFromHash(
        string $username,
        string $passwordHash,
        string $email = '',
        string $phone = ''
    ): int {
        $username = self::normalizeUsername($username);
        if ($username === '') {
            throw ApiException::badRequest('O nome de utilizador é obrigatório.', 'missing_username');
        }
        if ($this->findByUsername($username) !== null) {
            throw ApiException::badRequest('Já existe uma conta com esse nome de utilizador.', 'username_taken');
        }

        return (int) $this->db->insert(
            'INSERT INTO admin_users (username, password_hash, display_name, email, phone, is_active, created_at)
             VALUES (?, ?, ?, ?, ?, 1, ?)',
            [$username, $passwordHash, $username, $email !== '' ? $email : null, $phone !== '' ? $phone : null, time()]
        );
    }

    /**
     * @throws ApiException
     */
    public function create(string $username, string $password, string $email = '', string $phone = '', string $displayName = ''): int
    {
        $username = self::normalizeUsername($username);

        if ($username === '') {
            throw ApiException::badRequest('O nome de utilizador é obrigatório.', 'missing_username');
        }
        if (strlen($password) < 10) {
            throw ApiException::badRequest('A palavra-passe tem de ter pelo menos 10 caracteres.', 'weak_password');
        }
        if ($this->findByUsername($username) !== null) {
            throw ApiException::badRequest('Já existe uma conta com esse nome de utilizador.', 'username_taken');
        }
        $email = self::normalizeEmail($email);
        $phone = self::normalizePhone($phone);

        return (int) $this->db->insert(
            'INSERT INTO admin_users (username, password_hash, display_name, email, phone, is_active, created_at)
             VALUES (?, ?, ?, ?, ?, 1, ?)',
            [
                $username,
                password_hash($password, PASSWORD_DEFAULT),
                mb_substr(trim($displayName), 0, 120) !== '' ? mb_substr(trim($displayName), 0, 120) : $username,
                $email !== '' ? $email : null,
                $phone !== '' ? $phone : null,
                time(),
            ]
        );
    }

    /**
     * @throws ApiException
     */
    public function update(int $id, array $fields): void
    {
        $user = $this->find($id);
        if ($user === null) {
            throw ApiException::notFound('Conta inexistente.', 'user_not_found');
        }

        $email = self::normalizeEmail((string) ($fields['email'] ?? ''));
        $phone = self::normalizePhone((string) ($fields['phone'] ?? ''));
        $displayName = mb_substr(trim((string) ($fields['display_name'] ?? '')), 0, 120);
        $username = array_key_exists('username', $fields)
            ? self::normalizeUsername((string) $fields['username'])
            : (string) $user['username'];

        if ($username === '') {
            throw ApiException::badRequest('O nome de utilizador é obrigatório.', 'missing_username');
        }
        if ($username !== $user['username']) {
            $other = $this->findByUsername($username);
            if ($other !== null && (int) $other['id'] !== $id) {
                throw ApiException::badRequest('Já existe uma conta com esse nome de utilizador.', 'username_taken');
            }
        }

        $this->db->run(
            'UPDATE admin_users SET username = ?, display_name = ?, email = ?, phone = ? WHERE id = ?',
            [
                $username,
                $displayName !== '' ? $displayName : $username,
                $email !== '' ? $email : null,
                $phone !== '' ? $phone : null,
                $id,
            ]
        );
    }

    /** @throws ApiException */
    public function setPassword(int $id, string $password): void
    {
        if (strlen($password) < 10) {
            throw ApiException::badRequest('A palavra-passe tem de ter pelo menos 10 caracteres.', 'weak_password');
        }
        if ($this->find($id) === null) {
            throw ApiException::notFound('Conta inexistente.', 'user_not_found');
        }

        $this->db->run(
            'UPDATE admin_users SET password_hash = ?, failed_attempts = 0, locked_until = NULL WHERE id = ?',
            [password_hash($password, PASSWORD_DEFAULT), $id]
        );
    }

    /** @throws ApiException */
    public function setActive(int $id, bool $active, int $actingUserId): void
    {
        if ($id === $actingUserId && !$active) {
            throw ApiException::badRequest('Não pode desativar a conta com que está ligado.', 'cannot_disable_self');
        }
        if (!$active && $this->countActive() <= 1) {
            throw ApiException::badRequest(
                'Esta é a última conta ativa. Crie outra antes de desativar esta.',
                'last_active_user'
            );
        }
        $this->db->run('UPDATE admin_users SET is_active = ? WHERE id = ?', [$active ? 1 : 0, $id]);
    }

    /** @throws ApiException */
    public function delete(int $id, int $actingUserId): void
    {
        if ($id === $actingUserId) {
            throw ApiException::badRequest('Não pode apagar a conta com que está ligado.', 'cannot_delete_self');
        }
        if ($this->countActive() <= 1) {
            throw ApiException::badRequest(
                'Esta é a última conta ativa. Crie outra antes de apagar esta.',
                'last_active_user'
            );
        }
        $this->db->run('DELETE FROM password_resets WHERE user_id = ?', [$id]);
        $this->db->run('DELETE FROM admin_users WHERE id = ?', [$id]);
    }

    public static function normalizeUsername(string $username): string
    {
        return mb_substr(trim($username), 0, 60);
    }

    /** @throws ApiException */
    public static function normalizeEmail(string $email): string
    {
        $email = trim($email);
        if ($email === '') {
            return '';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            throw ApiException::badRequest('Endereço de email inválido.', 'invalid_email');
        }
        return mb_strtolower($email);
    }

    /**
     * @throws ApiException
     *
     * Aceita o mesmo formato do envio de SMS, para não haver duas regras
     * diferentes para o mesmo número.
     */
    public static function normalizePhone(string $phone): string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return '';
        }
        if (!preg_match('/^\+?[0-9][0-9\s\-().]{4,24}$/', $phone)) {
            throw ApiException::badRequest('Número de telefone inválido.', 'invalid_phone');
        }
        // Guarda-se só o que serve para enviar: os espaços e parênteses não são
        // necessários e só complicam a comparação.
        return preg_replace('/[\s\-().]/', '', $phone) ?? $phone;
    }
}