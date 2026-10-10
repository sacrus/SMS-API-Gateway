<?php

declare(strict_types=1);

namespace SmsApi;

/**
 * Falha no envio de email.
 *
 * Separate de ApiException porque não é um erro de pedido: quem chama não está
 * a falar com a API pública, e a mensagem vai para o painel, não para um
 * cliente JSON.
 */
final class MailException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'mail_failed',
        public readonly int $status = 500
    ) {
        parent::__construct($message);
    }
}