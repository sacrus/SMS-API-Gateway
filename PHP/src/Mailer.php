<?php

declare(strict_types=1);

namespace SmsApi;

/**
 * Envio de email de notificação.
 *
 * Não há Composer neste projecto, e obrigar o senhor a instalar uma biblioteca
 * de SMTP no servidor não seria aceitável. Por isso o cliente é mínimo e está
 * aqui: chega para enviar um email de recuperação de palavra-passe e avisos do
 * painel.
 *
 * Três transportes:
 *
 *   smtp      — com STARTTLS (porta 587, o mais comum na hospedagem)
 *   tls       — TLS já ligado à partida (porta 465, "SMTPS")
 *   sendmail  — entrega ao programa local, quando o servidor tem um
 *
 * A palavra-passe do SMTP é guardada como está no config.php, tal como a
 * palavra-passe da base de dados. Fica fora do alcance do browser: o config.php
 * é bloqueado pelo .htaccess.
 */
final class Mailer
{
    private const CONNECT_TIMEOUT = 15;
    private const IO_TIMEOUT = 20;

    public function __construct(
        private readonly array $config
    ) {
    }

    /** Há transporte configurado? Sem isto, a recuperação por email não funciona. */
    public function isConfigured(): bool
    {
        $s = $this->settings();

        if ($s['transport'] === '') {
            return false;
        }
        // Para sendmail não se pede servidor: é um caminho local.
        if ($s['transport'] === 'sendmail') {
            return $s['sendmail'] !== '';
        }

        return $s['host'] !== '';
    }

    /** @return array<string,string> */
    public function settings(): array
    {
        $s = $this->config['settings'] ?? [];

        return [
            'transport' => (string) ($s['mail_transport'] ?? ''),
            'host' => (string) ($s['smtp_host'] ?? ''),
            'port' => (string) ($s['smtp_port'] ?? '587'),
            'username' => (string) ($s['smtp_username'] ?? ''),
            'password' => (string) ($s['smtp_password'] ?? ''),
            'encryption' => (string) ($s['smtp_encryption'] ?? 'tls'),
            'from_email' => (string) ($s['mail_from_email'] ?? ''),
            'from_name' => (string) ($s['mail_from_name'] ?? 'SMSAPI Gateway'),
            'sendmail' => (string) ($s['mail_sendmail'] ?? '/usr/sbin/sendmail -bs'),
        ];
    }

    /**
     * @throws MailException
     */
    public function send(string $to, string $subject, string $html, string $text = ''): void
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new MailException('Endereço de destino inválido.', 'invalid_recipient');
        }
        if (!$this->isConfigured()) {
            throw new MailException(
                'O email ainda não foi configurado. Preencha os dados SMTP em Definições.',
                'not_configured'
            );
        }

        $s = $this->settings();
        $from = $s['from_email'] !== '' ? $s['from_email'] : ($s['username'] !== '' ? $s['username'] : '');
        if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new MailException('Falta definir o remetente nas definições de email.', 'missing_from');
        }

        if ($text === '') {
            $text = self::htmlToText($html);
        }

        // A fronteira é aleatória de propósito: um valor previsível num
        // email remoto pode ser usado para injectar conteúdo.
        $boundary = bin2hex(random_bytes(16));

        $headers = implode("\r\n", [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . self::formatAddress($from, $s['from_name']),
            'To: ' . self::formatAddress($to, ''),
            'Subject: ' . self::encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@smsapi>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="smsapi_' . $boundary . '"',
        ]);

        $body = "--smsapi_{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text)) . "\r\n"
            . "--smsapi_{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html)) . "\r\n"
            . "--smsapi_{$boundary}--\r\n";

        $envelopeFrom = $s['username'] !== '' ? $s['username'] : $from;

        if ($s['transport'] === 'sendmail') {
            $this->sendViaSendmail($s['sendmail'], $envelopeFrom, $to, $headers, $body);
            return;
        }

        $this->sendViaSmtp($s, $envelopeFrom, $to, $headers, $body);
    }

    /**
     * @param array<string,string> $s
     * @throws MailException
     */
    private function sendViaSmtp(array $s, string $from, string $to, string $headers, string $body): void
    {
        $implicitTls = $s['transport'] === 'tls';
        $port = (int) ($s['port'] !== '' ? $s['port'] : ($implicitTls ? 465 : 587));
        $host = $s['host'];
        $target = ($implicitTls ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $target,
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            throw new MailException(
                "Não foi possível ligar ao servidor de email ({$host}:{$port}): {$errstr}",
                'connect_failed'
            );
        }

        stream_set_timeout($socket, self::IO_TIMEOUT);

        try {
            $this->expect($socket, 220);

            if (!$implicitTls && $s['encryption'] !== 'none') {
                $this->command($socket, 'EHLO ' . self::helloName($host));
                $this->command($socket, 'STARTTLS', 220);
                if (!@stream_socket_enable_crypto(
                    $socket,
                    true,
                    STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
                )) {
                    throw new MailException('A encriptação TLS não pôde ser negociada.', 'tls_failed');
                }
                // O servidor tem de se apresentar de novo, agora sobre TLS.
                $this->command($socket, 'EHLO ' . self::helloName($host));
            } else {
                $this->command($socket, 'EHLO ' . self::helloName($host));
            }

            if ($s['username'] !== '') {
                // AUTH só faz sentido sobre TLS: em claro seria mandar a
                // palavra-passe do email legível a quem estiver no caminho.
                if (!$implicitTls && $s['encryption'] === 'none') {
                    throw new MailException(
                        'O servidor SMTP pede palavra-passe sem TLS. Não se envia a palavra-passe do email em claro.',
                        'insecure_auth'
                    );
                }

                $autenticado = $this->challenge($socket, 'AUTH PLAIN', [
                    base64_encode("\0" . $s['username'] . "\0" . $s['password']),
                ]);
                if (!$autenticado) {
                    $autenticado = $this->challenge($socket, 'AUTH LOGIN', [
                        base64_encode($s['username']),
                        base64_encode($s['password']),
                    ]);
                }
                if (!$autenticado) {
                    throw new MailException('O servidor de email recusou a autenticação.', 'auth_failed');
                }
            }

            $this->command($socket, 'MAIL FROM:<' . $from . '>', 250);
            $this->command($socket, 'RCPT TO:<' . $to . '>', 250);
            $this->command($socket, 'DATA', 354);

            // O corpo já termina com CRLF; acrescentar o ponto final encerra.
            fwrite($socket, self::dotStuff($headers . "\r\n\r\n" . $body) . ".\r\n");
            $this->expect($socket, 250);
            $this->command($socket, 'QUIT');
        } finally {
            @fclose($socket);
        }
    }

    /**
     * Faz o desafio de autenticação do SMTP: o servidor pede cada passo com
     * "334" e só no fim responde "235".
     *
     * Devolve falso em vez de lançar, para se poder tentar PLAIN e depois
     * LOGIN sem que o primeiro erro esconda o segundo.
     *
     * @param list<string> $steps
     */
    private function challenge($socket, string $greeting, array $steps): bool
    {
        try {
            $this->command($socket, $greeting, 334);
            foreach ($steps as $step) {
                $this->command($socket, $step, 334);
            }
            $this->expect($socket, 235);
            return true;
        } catch (MailException) {
            // O servidor não suporta este mecanismo; tenta-se o seguinte.
            // Só depois de esgotar as tentativas se avisa o utilizador.
            return false;
        }
    }

    /** @throws MailException */
    private function sendViaSendmail(string $binary, string $from, string $to, string $headers, string $body): void
    {
        $command = $binary . ' -f' . escapeshellarg($from) . ' -t' . escapeshellarg($to);

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $process = @proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new MailException('Não foi possível executar o programa de envio de email.', 'sendmail_failed');
        }

        fwrite($pipes[0], $headers . "\r\n\r\n" . $body);
        fclose($pipes[0]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        if ($code !== 0) {
            throw new MailException('O programa de envio de email falhou: ' . trim($err), 'sendmail_failed');
        }
    }

    /** @throws MailException */
    private function command($socket, string $line, int $expected = 250): void
    {
        fwrite($socket, $line . "\r\n");
        $this->expect($socket, $expected);
    }

    /** @throws MailException */
    private function expect($socket, int $expected): void
    {
        while (true) {
            $line = fgets($socket, 2048);
            if ($line === false) {
                $meta = stream_get_meta_data($socket);
                throw new MailException(
                    'O servidor de email fechou a ligação'
                    . (!empty($meta['timed_out']) ? ' (tempo esgotado).' : '.'),
                    'connection_lost'
                );
            }
            // Linhas de continuação de SMTP têm um "-" na quarta posição.
            if (strlen($line) < 4 || $line[3] === '-') {
                continue;
            }
            $code = (int) substr($line, 0, 3);
            if ($code !== $expected) {
                throw new MailException(
                    'Resposta inesperada do servidor de email: ' . trim($line),
                    'smtp_error'
                );
            }
            return;
        }
    }

    private static function dotStuff(string $body): string
    {
        // Uma linha que comece por ponto seria interpretada como fim da
        // mensagem; o SMTP resolve com um ponto a mais.
        return preg_replace("/^\./m", '..', $body) ?? $body;
    }

    private static function helloName(string $host): string
    {
        return preg_replace('/[^A-Za-z0-9.\-]/', '', $host) ?: 'localhost';
    }

    private static function formatAddress(string $email, string $name): string
    {
        return $name === '' ? '<' . $email . '>' : self::encodeHeader($name) . ' <' . $email . '>';
    }

    private static function encodeHeader(string $value): string
    {
        // Cabeçalhos só aceitam ASCII. UTF-8 vai base64, como manda o RFC 2047.
        return preg_match('/[\x80-\xFF]/', $value)
            ? '=?UTF-8?B?' . base64_encode($value) . '?='
            : $value;
    }

    private static function htmlToText(string $html): string
    {
        $text = preg_replace('#<br\s*/?>|</p>|</div>|</h[1-6]>#i', "\n", $html) ?? $html;
        $text = preg_replace('/<[^>]+>/', '', $text) ?? $text;
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }
}