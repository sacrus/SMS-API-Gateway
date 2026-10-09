<?php

declare(strict_types=1);

namespace SmsApi;

/**
 * Selector de idioma para as páginas do gateway.
 *
 * Renderiza a marcação e devolve o caminho correcto do script, que difere
 * entre o instalador (na raiz) e o painel (dentro de admin/).
 */
final class Translate
{
    /** Prefixo relativo à página actual. */
    public static function assetsPrefix(): string
    {
        return basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'admin/index.php')) === 'install.php'
            ? 'admin/'
            : '';
    }

    /** Contentor onde o JavaScript vai construir o selector. */
    public static function slot(): string
    {
        return '<div class="smsapi-t-slot" id="smsapi-translate"></div>';
    }

    /**
     * O tradutor fica ao lado do CSS, em admin/assets/. O instalador está na
     * raiz do gateway e por isso precisa do prefixo admin/.
     */
    public static function script(): string
    {
        return sprintf(
            '<script src="%sassets/translate.js"></script>',
            htmlspecialchars(self::assetsPrefix(), ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * Tag <meta> opcional. Basta acrescentar ao <head> para fixar uma versão,
     * alojar o ficheiro localmente, ou apontar para um espelho:
     *
     *   <meta name="smsapi-translate-src" content="https://cdn.exemplo/element.js">
     */
    public static function overrideMeta(): string
    {
        $configured = Config::load()['settings']['translate_src'] ?? null;
        if (!is_string($configured) || $configured === '') {
            return '';
        }

        return sprintf(
            '<meta name="smsapi-translate-src" content="%s">',
            htmlspecialchars($configured, ENT_QUOTES, 'UTF-8')
        );
    }
}