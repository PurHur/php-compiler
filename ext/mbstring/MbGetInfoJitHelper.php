<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_get_info() NestedJIT runtime (#20014 leftover — compile-time-only type blocked AOT).
 *
 * Hand-rolled per-type dispatch (no strcasecmp) — NestedJIT misclassifies keys and
 * may not observe MbstringState statics reliably (peer {@see MbHttpInputJitHelper}).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_get_info)
 */
final class MbGetInfoJitHelper
{
    public const KIND_FALSE = 0;

    public const KIND_NULL = 1;

    public const KIND_INT = 2;

    public const KIND_STRING = 3;

    public const KIND_ARRAY = 4;

    /**
     * @return int kind code
     */
    public static function kindArgv(string $type): int
    {
        if (self::eq($type, 'http_input')) {
            return self::KIND_NULL;
        }
        if (self::eq($type, 'func_overload') || self::eq($type, 'nope')) {
            return self::KIND_FALSE;
        }
        if (self::eq($type, 'illegal_chars') || self::eq($type, 'substitute_character')) {
            return self::KIND_INT;
        }
        if (self::eq($type, 'all') || self::eq($type, 'detect_order')) {
            return self::KIND_ARRAY;
        }

        return self::KIND_STRING;
    }

    /**
     * Payload for string/array kinds — ignored for false/null/int.
     */
    public static function payloadArgv(string $type): string
    {
        if (self::eq($type, 'all')) {
            return json_encode(self::allArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        if (self::eq($type, 'detect_order')) {
            return json_encode(['ASCII', 'UTF-8'], JSON_THROW_ON_ERROR);
        }
        if (self::eq($type, 'internal_encoding') || self::eq($type, 'http_output')
            || self::eq($type, 'mail_charset')) {
            return 'UTF-8';
        }
        if (self::eq($type, 'http_output_conv_mimetypes')) {
            return '^(text/|application/xhtml\\+xml)';
        }
        if (self::eq($type, 'mail_header_encoding') || self::eq($type, 'mail_body_encoding')) {
            return 'BASE64';
        }
        if (self::eq($type, 'encoding_translation') || self::eq($type, 'strict_detection')) {
            return 'Off';
        }
        if (self::eq($type, 'language')) {
            return 'neutral';
        }

        return '';
    }

    /** Int scalar for {@see KIND_INT}. */
    public static function intArgv(string $type): int
    {
        if (self::eq($type, 'illegal_chars')) {
            return 0;
        }
        if (self::eq($type, 'substitute_character')) {
            return 63;
        }

        return 0;
    }

    /**
     * @return array<string, mixed>
     */
    private static function allArray(): array
    {
        return [
            'internal_encoding' => 'UTF-8',
            'http_output' => 'UTF-8',
            'http_output_conv_mimetypes' => '^(text/|application/xhtml\\+xml)',
            'mail_charset' => 'UTF-8',
            'mail_header_encoding' => 'BASE64',
            'mail_body_encoding' => 'BASE64',
            'illegal_chars' => 0,
            'encoding_translation' => 'Off',
            'language' => 'neutral',
            'detect_order' => ['ASCII', 'UTF-8'],
            'substitute_character' => 63,
            'strict_detection' => 'Off',
        ];
    }

    private static function eq(string $type, string $want): bool
    {
        if ($type === $want) {
            return true;
        }
        $len = \strlen($type);
        if ($len !== \strlen($want)) {
            return false;
        }
        for ($i = 0; $i < $len; ++$i) {
            $a = $type[$i];
            $b = $want[$i];
            if ($a >= 'A' && $a <= 'Z') {
                $a = \chr(\ord($a) + 32);
            }
            if ($b >= 'A' && $b <= 'Z') {
                $b = \chr(\ord($b) + 32);
            }
            if ($a !== $b) {
                return false;
            }
        }

        return true;
    }
}
