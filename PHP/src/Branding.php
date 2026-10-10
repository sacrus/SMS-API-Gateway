<?php

declare(strict_types=1);

namespace SmsApi;

/**
 * Identidade visual do gateway.
 *
 * O logotipo é o mesmo da app Android (ver
 * android/app/src/main/res/drawable/ic_launcher_foreground.xml): balão de fala
 * branco com um avião de papel sobre o verde #00695C.
 *
 * Os ficheiros vivem na raiz do gateway, e as páginas nem todas estão à mesma
 * profundidade — o instalador está na raiz, o painel um nível abaixo. Em vez de
 * escrever "../favicon-32.png" à mão em cada página e errar o caminho, calcula-se
 * a partir do script que está a correr.
 */
final class Branding
{
    /**
     * Os ficheiros estão na raiz do gateway. O caminho relativo até eles depende
     * de a que profundidade está a página.
     *
     * Contar os segmentos de dirname(SCRIPT_NAME) não dá: o gateway pode estar
     * na raiz do domínio, onde o painel fica a um nível, ou em /smsapi/, onde
     * o mesmo dirname() tem mais um segmento e dava "../.." a mais — que é
     * exatamente o que levava o browser a pedir o ficheiro fora do gateway.
     *
     * Por isso a profundidade é dada por quem conhece a página. É um valor por
     * página, e cada página sabe o seu.
     *
     * @param int $niveis quantos níveis abaixo da raiz do gateway está a página
     */
    public static function prefixo(int $niveis): string
    {
        return str_repeat('../', max(0, $niveis));
    }

    /**
     * As etiquetas <link> do ícone do site.
     *
     * O SVG vai primeiro porque é o que fica nítido em ecrãs grandes; os PNG
     * cobrem os navegadores que não aceitam SVG. A apple-touch-icon é o que o
     * iPhone mostra quando alguém adiciona o painel ao ecrã principal.
     *
     * @param int $niveis profundidade da página em relação à raiz do gateway
     */
    public static function favicon(int $niveis): string
    {
        $p = self::prefixo($niveis);

        return sprintf(
            '<link rel="icon" type="image/svg+xml" href="%slogo.svg">' . "\n"
            . '<link rel="icon" type="image/png" sizes="32x32" href="%sfavicon-32.png">' . "\n"
            . '<link rel="icon" type="image/png" sizes="16x16" href="%sfavicon-16.png">' . "\n"
            . '<link rel="apple-touch-icon" sizes="180x180" href="%sfavicon-180.png">' . "\n"
            . '<link rel="manifest" href="%smanifesto.webmanifest">',
            $p, $p, $p, $p, $p
        );
    }
}