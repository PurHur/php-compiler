<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * preg_quote() for compiled JIT/AOT modules (#14743, php-in-PHP).
 *
 * Escape loop matches php-src php_preg_quote / VM SSOT (kept inline — NestedJIT
 * user-script AOT segfaults when this helper pulls the full string runtime, #26827).
 * User-script AOT NestedJITs this leaf via HelperRuntimeCache USER_SCRIPT_INLINE_ONLY
 * (#27564) — the committed helper-runtime unit.o returns "" on default cache hit.
 *
 * php-src: ext/pcre/php_pcre.c — PHP_FUNCTION(preg_quote) / php_preg_quote
 */
final class PregQuoteJitHelper
{
    /**
     * Both params are non-nullable string so NestedJIT keeps `__string__*` ABI (#21109, #26827).
     * Empty `$delimiter` means "no delimiter" (php-src optional NULL).
     */
    public static function pregQuoteArgv(string $string, string $delimiter = ''): string
    {
        $delim = '' === $delimiter ? null : $delimiter[0];
        $out = '';
        $len = 0;
        while (isset($string[$len])) {
            ++$len;
        }
        for ($i = 0; $i < $len; ++$i) {
            $ch = $string[$i];
            if ("\0" === $ch) {
                // php-src string.c php_preg_quote: NUL -> \000
                $out .= '\\000';
                continue;
            }
            if (self::needsEscape($ch) || (null !== $delim && $ch === $delim)) {
                $out .= '\\'.$ch;
            } else {
                $out .= $ch;
            }
        }

        return $out;
    }

    private static function needsEscape(string $ch): bool
    {
        // php-src php_preg_quote metacharacter set (byte subset).
        return '.' === $ch || '\\' === $ch || '+' === $ch || '*' === $ch
            || '?' === $ch || '[' === $ch || '^' === $ch || ']' === $ch
            || '(' === $ch || ')' === $ch || '$' === $ch || '=' === $ch
            || '{' === $ch || '}' === $ch || '-' === $ch || '|' === $ch
            || '!' === $ch || '<' === $ch || '>' === $ch || ':' === $ch;
    }
}
