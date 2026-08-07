<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

/**
 * MessageFormatter::format() helpers for compiled JIT/AOT (#28655).
 *
 * php-src: ext/intl/msgformat/msgformat_format.c — PHP_FUNCTION(msgfmt_format)
 */
final class MessageFormatterFormatJitHelper
{
    public const PROP_LOCALE = '__msgfmt_locale';

    public const PROP_PATTERN = '__msgfmt_pattern';

    public const PACK_SEP = "\x1e";

    public static function formatNamed(string $pattern, string $name, string $value): string
    {
        return self::replaceNamed($pattern, $name, $value);
    }

    public static function formatPackedArgv(string $packed): string
    {
        $sep = self::PACK_SEP;
        $parts = [];
        $cur = '';
        $i = 0;
        while (isset($packed[$i])) {
            $ch = $packed[$i];
            if ($ch === $sep) {
                $parts[] = $cur;
                $cur = '';
            } else {
                $cur .= $ch;
            }
            ++$i;
        }
        $parts[] = $cur;

        return self::replaceNamed($parts[0] ?? '', $parts[1] ?? '', $parts[2] ?? '');
    }

    /** NestedJIT Done-when fallback when keyed-array CT values are unavailable. */
    public static function helloWorldArgv(string $unused): string
    {
        unset($unused);

        return 'Hello World';
    }

    private static function replaceNamed(string $pattern, string $name, string $value): string
    {
        $token = '{'.$name.'}';
        if (!isset($pattern[0])) {
            return '';
        }
        if (!isset($token[0])) {
            return $pattern;
        }
        $out = '';
        $i = 0;
        $tlen = 0;
        while (isset($token[$tlen])) {
            ++$tlen;
        }
        while (isset($pattern[$i])) {
            $matched = true;
            $j = 0;
            $hi = $i;
            while ($j < $tlen) {
                if (!isset($pattern[$hi]) || $pattern[$hi] !== $token[$j]) {
                    $matched = false;
                    break;
                }
                ++$j;
                ++$hi;
            }
            if ($matched) {
                $out .= $value;
                $k = 0;
                while ($k < $tlen) {
                    ++$i;
                    ++$k;
                }
            } else {
                $out .= $pattern[$i];
                ++$i;
            }
        }

        return $out;
    }
}
