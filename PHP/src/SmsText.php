<?php

declare(strict_types=1);

namespace SmsApi;

/**
 * GSM 03.38 text analysis.
 *
 * Android's SmsManager never converts an SMS into MMS — that is an operator or
 * messaging-app policy. What really happens above the single-message limit is
 * fragmentation: the text is split into several SMS, each one billed
 * separately, and partial delivery becomes possible.
 *
 * A message fits in one SMS when it uses at most 160 GSM septets. If it needs
 * more, every segment carries 153 septets plus a 7-bit UDH header, so 161
 * septets already cost 2 SMS.
 *
 * Accents matter: only the characters in the GSM 03.38 alphabet are "cheap".
 * Anything outside it (é is fine, but ê, õ, ♪ or an emoji are not) forces the
 * whole message to UCS-2, where the limit drops from 160 to 70 characters.
 */
final class SmsText
{
    public const ENCODING_GSM7 = 'gsm7';
    public const ENCODING_UCS2 = 'ucs2';

    public const GSM7_SINGLE = 160;
    public const GSM7_MULTIPART = 153;
    public const UCS2_SINGLE = 70;
    public const UCS2_MULTIPART = 67;

    /** GSM 03.38 basic character set. One septet per character. */
    private const BASIC = '@£$¥èéùìòÇØøÅå'
        . "Δ_ΦΓΛΩΠΨΣΘΞÆæßÉ"
        . " !\"#¤%&'()*+,-./0123456789:;<=>?"
        . '¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§'
        . '¿abcdefghijklmnopqrstuvwxyzäöñüà';

    /** GSM 03.38 extension table. Two septets per character (ESC prefix). */
    private const EXTENSION = '^{}\\[~]|€';

    /**
     * @return array{
     *   encoding: string, units: int, segments: int,
     *   single_limit: int, multipart_limit: int
     * }
     */
    public static function analyze(string $body): array
    {
        $septets = self::septets($body);

        if ($septets >= 0) {
            return [
                'encoding' => self::ENCODING_GSM7,
                'units' => $septets,
                'segments' => self::segmentCount($septets, self::GSM7_SINGLE, self::GSM7_MULTIPART),
                'single_limit' => self::GSM7_SINGLE,
                'multipart_limit' => self::GSM7_MULTIPART,
            ];
        }

        $units = mb_strlen($body, 'UTF-8');

        return [
            'encoding' => self::ENCODING_UCS2,
            'units' => $units,
            'segments' => self::segmentCount($units, self::UCS2_SINGLE, self::UCS2_MULTIPART),
            'single_limit' => self::UCS2_SINGLE,
            'multipart_limit' => self::UCS2_MULTIPART,
        ];
    }

    public static function segments(string $body): int
    {
        return self::analyze($body)['segments'];
    }

    public static function encoding(string $body): string
    {
        return self::analyze($body)['encoding'];
    }

    /** True when every character fits the cheap GSM 03.38 alphabet. */
    public static function isGsm7(string $body): bool
    {
        return self::septets($body) >= 0;
    }

    /** The first characters that forced UCS-2, for user-facing explanations. */
    public static function offendingCharacters(string $body, int $limit = 5): array
    {
        if (self::isGsm7($body)) {
            return [];
        }

        $seen = [];
        foreach (self::characters($body) as $char) {
            if (self::septets($char) >= 0) {
                continue;
            }
            if (!in_array($char, $seen, true)) {
                $seen[] = $char;
            }
            if (count($seen) >= $limit) {
                break;
            }
        }

        return $seen;
    }

    /** Latin-1 and friends, mapped to their closest ASCII equivalent. */
    private const TRANSLITERATIONS = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
        'æ' => 'ae',
        'ç' => 'c', 'ć' => 'c', 'č' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ñ' => 'n', 'ń' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o',
        'œ' => 'oe',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ý' => 'y', 'ÿ' => 'y',
        'š' => 's', 'ś' => 's',
        'ž' => 'z', 'ź' => 'z', 'ż' => 'z',
        'ł' => 'l', 'đ' => 'd', 'ð' => 'd', 'þ' => 'th', 'ß' => 'ss',
    ];

    /**
     * Rewrites the text so it fits the cheap GSM 03.38 alphabet. Symbols with no
     * ASCII equivalent (emoji, arrows, box drawing) are dropped rather than
     * mangled into something meaningless.
     *
     * @return array{text: string, changed: bool, replacements: array<string,string>, dropped: int}
     */
    public static function toGsm7(string $body): array
    {
        if (self::isGsm7($body)) {
            return ['text' => $body, 'changed' => false, 'replacements' => [], 'dropped' => 0];
        }

        $out = '';
        $replacements = [];
        $dropped = 0;

        foreach (self::characters($body) as $char) {
            if ($char === "\n" || $char === "\r" || $char === "\f") {
                $out .= $char;
                continue;
            }
            if (strpos(self::BASIC, $char) !== false || strpos(self::EXTENSION, $char) !== false) {
                $out .= $char;
                continue;
            }
            if (isset(self::TRANSLITERATIONS[$char])) {
                $replacements[$char] = self::TRANSLITERATIONS[$char];
                $out .= self::TRANSLITERATIONS[$char];
                continue;
            }
            $dropped++;
        }

        return [
            'text' => $out,
            'changed' => true,
            'replacements' => $replacements,
            'dropped' => $dropped,
        ];
    }

    /**
     * Number of septets, or -1 when the text cannot be encoded as GSM 03.38.
     */
    public static function septets(string $body): int
    {
        $total = 0;

        foreach (self::characters($body) as $char) {
            if ($char === "\n" || $char === "\r") {
                $total += 1;
                continue;
            }
            if ($char === "\f") {
                $total += 2;
                continue;
            }
            if (strpos(self::BASIC, $char) !== false) {
                $total += 1;
                continue;
            }
            if (strpos(self::EXTENSION, $char) !== false) {
                $total += 2;
                continue;
            }
            return -1;
        }

        return $total;
    }

    /** @return list<string> one entry per UTF-8 character */
    private static function characters(string $body): array
    {
        if ($body === '') {
            return [];
        }

        $characters = preg_split('//u', $body, -1, PREG_SPLIT_NO_EMPTY);

        return $characters === false ? [] : $characters;
    }

    private static function segmentCount(int $units, int $singleLimit, int $multipartLimit): int
    {
        if ($units <= $singleLimit) {
            return 1;
        }

        return (int) ceil($units / $multipartLimit);
    }
}