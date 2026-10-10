<?php

declare(strict_types=1);

namespace SmsApi;

/**
 * Recuperação de palavra-passe por SMS ou por email.
 *
 * O que este código tem de fazer bem:
 *
 *  - O código nunca é guardado em claro. Na base de dados fica o SHA-256, o
 *    que significa que nem com acesso à base de dados se consegue voltar a
 *    usar um código.
 *  - Um código serve uma vez só e expira. Passado o prazo, ainda que o código
 *    esteja certo, já não vale.
 *  - Errar o código conta uma tentativa, e depois de algumas o código morre.
 *    Sem isso, um código de 6 dígitos era encontrável em segundos por força
 *    bruta.
 *  - Há limite por utilizador e por endereço de origem. Limitar só por
 *    utilizador não trava um ataque que varre todos os nomes; limitar só por
 *    IP não trava alguém que muda de IP.
 *  - A resposta é sempre a mesma, exista ou não a conta. Dizer "utilizador
 *    inexistente" é dar a lista de quem tem conta.
 *
 * O SMS usa a fila do gateway: o código entra como uma SMS normal e o telefone
 * entrega-a. É o mesmo caminho de sempre, sem nada de especial.
 */
final class PasswordReset
{
    /** Dígitos do código. */
    private const CODE_LENGTH = 6;

    /** Validade do código, em segundos. */
    private const TTL_SECONDS = 600;

    /** Tentativas com um código antes de ele deixar de valer. */
    private const MAX_CODE_ATTEMPTS = 5;

    /** Códigos pedidos para a mesma conta dentro de uma hora. */
    private const MAX_PER_HOUR_PER_USER = 3;

    /** Códigos pedidos do mesmo endereço de origem dentro de uma hora. */
    private const MAX_PER_HOUR_PER_IP = 5;

    /** Mínimo de segundos entre pedidos da mesma conta. */
    private const COOLDOWN_SECONDS = 60;

    public function __construct(
        private readonly Database $db,
        private readonly Queue $queue,
        private readonly Users $users,
        private readonly Mailer $mailer
    ) {
    }

    /**
     * Pede um código de recuperação.
     *
     * O texto de retorno é sempre o mesmo, exista a conta ou não: não se
     * confirma a quem pediu se aquele nome tem uma conta.
     *
     * @return array{enviado:bool, via:?string}
     */
    public function request(string $username, string $channel, string $ip): array
    {
        $this->guardAbuse($username, $ip);

        $user = $this->users->findByUsername($username);
        if ($user === null || (int) $user['is_active'] !== 1) {
            // Não se diz que a conta não existe. Também não se gasta um código
            // que ninguém ia receber.
            return ['enviado' => false, 'via' => null];
        }

        $userId = (int) $user['id'];
        $contact = $channel === 'sms' ? (string) ($user['phone'] ?? '') : (string) ($user['email'] ?? '');
        if ($contact === '') {
            return ['enviado' => false, 'via' => null];
        }

        // Só o código activo conta. Os antigos deixam de poder ser usados.
        $this->db->run('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL',
            [time(), $userId]);

        $code = self::generateCode();
        $expiresAt = time() + self::TTL_SECONDS;

        $this->db->insert(
            'INSERT INTO password_resets (user_id, channel, code_hash, expires_at, created_at, ip)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, $channel, hash('sha256', $code), $expiresAt, time(), $ip]
        );

        if ($channel === 'sms') {
            $this->queue->enqueue(
                $contact,
                sprintf('Codigo de recuperacao do SMSAPI Gateway: %s. Valido durante %d minutos.', $code, intdiv(self::TTL_SECONDS, 60))
            );
            return ['enviado' => true, 'via' => 'sms'];
        }

        $this->mailer->send(
            $contact,
            'Recuperacao de palavra-passe - SMSAPI Gateway',
            self::emailBody($code),
            sprintf(
                "Codigo de recuperacao do SMSAPI Gateway: %s\n\nValido durante %d minutos.\n"
                . "Se foi o senhor, ignore este email e nao partilhe o codigo com ninguem.",
                $code,
                intdiv(self::TTL_SECONDS, 60)
            )
        );

        return ['enviado' => true, 'via' => 'email'];
    }

    /**
     * Confere o código e devolve o utilizador a quem pertence.
     *
     * @throws ApiException
     */
    public function verify(string $username, string $code, string $channel): array
    {
        $user = $this->users->findByUsername($username);
        if ($user === null) {
            throw ApiException::badRequest('Código ou utilizador inválido.', 'invalid_code');
        }

        $row = $this->db->one(
            'SELECT * FROM password_resets
             WHERE user_id = ? AND channel = ? AND used_at IS NULL
             ORDER BY id DESC LIMIT 1',
            [(int) $user['id'], $channel]
        );

        if ($row === null) {
            throw ApiException::badRequest('Código ou utilizador inválido.', 'invalid_code');
        }

        if ((int) $row['expires_at'] < time()) {
            $this->db->run('UPDATE password_resets SET used_at = ? WHERE id = ?', [time(), (int) $row['id']]);
            throw ApiException::badRequest('O código expirou. Peça um novo.', 'code_expired');
        }

        if ((int) $row['attempts'] >= self::MAX_CODE_ATTEMPTS) {
            $this->db->run('UPDATE password_resets SET used_at = ? WHERE id = ?', [time(), (int) $row['id']]);
            throw ApiException::badRequest('Demasiadas tentativas. Peça um código novo.', 'too_many_attempts');
        }

        if (!hash_equals((string) $row['code_hash'], hash('sha256', trim($code)))) {
            $this->db->run('UPDATE password_resets SET attempts = attempts + 1 WHERE id = ?', [(int) $row['id']]);
            throw ApiException::badRequest('Código ou utilizador inválido.', 'invalid_code');
        }

        return $user;
    }

    /** Marca o código como usado. Chamar depois de mudar a palavra-passe. */
    public function consume(string $username, string $channel): void
    {
        $user = $this->users->findByUsername($username);
        if ($user === null) {
            return;
        }
        $this->db->run(
            'UPDATE password_resets SET used_at = ? WHERE user_id = ? AND channel = ? AND used_at IS NULL',
            [time(), (int) $user['id'], $channel]
        );
    }

    /**
     * Trava quem tenta pedir códigos sem parar.
     *
     * @throws ApiException
     */
    private function guardAbuse(string $username, string $ip): void
    {
        $user = $this->users->findByUsername($username);

        if ($user !== null) {
            $perUser = (int) $this->db->value(
                'SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at > ?',
                [(int) $user['id'], time() - 3600]
            );
            if ($perUser >= self::MAX_PER_HOUR_PER_USER) {
                throw ApiException::tooMany(
                    'Já foram pedidos demasiados códigos para esta conta. Tente dentro de uma hora.',
                    'reset_rate_limited'
                );
            }

            $last = (int) $this->db->value(
                'SELECT created_at FROM password_resets WHERE user_id = ? ORDER BY id DESC LIMIT 1',
                [(int) $user['id']]
            );
            if ($last > 0 && time() - $last < self::COOLDOWN_SECONDS) {
                throw ApiException::tooMany(
                    'Espere ' . (self::COOLDOWN_SECONDS - (time() - $last)) . ' segundos antes de pedir outro código.',
                    'reset_cooldown'
                );
            }
        }

        $perIp = (int) $this->db->value(
            'SELECT COUNT(*) FROM password_resets WHERE ip = ? AND created_at > ?',
            [$ip, time() - 3600]
        );
        if ($perIp >= self::MAX_PER_HOUR_PER_IP) {
            throw ApiException::tooMany(
                'Demasiados pedidos deste endereço. Tente dentro de uma hora.',
                'reset_rate_limited'
            );
        }
    }

    /**
     * Código numérico com zeros à esquerda.
     *
     * random_int e não rand: rand() não é criptograficamente seguro, e um
     * gerador previsível transforma um código de 6 dígitos em trivia.
     */
    private static function generateCode(): string
    {
        $max = (10 ** self::CODE_LENGTH) - 1;
        return str_pad((string) random_int(0, $max), self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }

    private static function emailBody(string $code): string
    {
        $code = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
        $minutos = intdiv(self::TTL_SECONDS, 60);

        return <<<HTML
<!DOCTYPE html>
<html lang="pt">
<body style="margin:0;padding:24px;background:#0f1720;font-family:Arial,Helvetica,sans-serif;">
  <div style="max-width:520px;margin:0 auto;background:#172231;border:1px solid #2a3b52;border-radius:12px;padding:28px;">
    <p style="margin:0 0 4px;color:#14b8a6;font-size:13px;letter-spacing:.08em;text-transform:uppercase;">SMSAPI Gateway</p>
    <h1 style="margin:0 0 18px;color:#e6edf5;font-size:20px;">Recuperação de palavra-passe</h1>
    <p style="margin:0 0 12px;color:#93a4b8;font-size:14px;line-height:1.6;">
      Use este código na página de recuperação do painel. Vale durante {$minutos} minutos.
    </p>
    <p style="margin:0 0 20px;text-align:center;">
      <span style="display:inline-block;background:#0f1720;border:1px solid #14b8a6;border-radius:10px;
                   padding:14px 26px;color:#14b8a6;font-size:30px;font-weight:bold;
                   letter-spacing:.22em;">{$code}</span>
    </p>
    <p style="margin:0 0 8px;color:#93a4b8;font-size:13px;line-height:1.6;">
      Se não foi o senhor, ignore esta mensagem. Ninguém lhe vai pedir este código.
    </p>
    <p style="margin:0;color:#93a4b8;font-size:13px;line-height:1.6;">
      Ninguém do SMSAPI Gateway lhe pede a palavra-passe por email, telefone ou SMS.
    </p>
  </div>
</body>
</html>
HTML;
    }
}