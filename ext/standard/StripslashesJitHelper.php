<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * stripslashes() for compiled JIT/AOT modules (#14742, #28104, php-in-PHP).
 *
 * Self-contained for NestedJIT (#16075 / #25345) — must not NestedJIT {@see VmString}
 * (thin AOT segfault after c:main_before_php when deps pull VmString — #26907).
 * Semantics mirror {@see VmString}::stripslashes / php-src ext/standard/stripslashes.c.
 *
 * NestedJIT cannot lower a loop-carried string accumulator when indexing by offset;
 * recurse with `$prefix.$rest` instead (peer {@see AddslashesJitHelper}).
 *
 * Skip-2 advance via mutual `$i+1` recursion only — literal `$i+2` / flag-param strip
 * helpers truncate or segfault under thin AOT (#28104).
 */
final class StripslashesJitHelper
{
    public static function stripslashesArgv(string $string): string
    {
        return self::stripFrom($string, 0);
    }

    /** Public so NestedJIT helper TUs bind the recursive callee (#25345). */
    public static function stripFrom(string $string, int $i): string
    {
        if (!isset($string[$i])) {
            return '';
        }
        $ch = $string[$i];
        if ('\\' === $ch && isset($string[$i + 1])) {
            // Consume the backslash; emitEscaped advances one more past the escaped byte.
            return self::emitEscaped($string, $i + 1);
        }

        return $ch.self::stripFrom($string, $i + 1);
    }

    /**
     * $i points at the byte after a backslash. Emit it (or NUL for ASCII '0') then continue.
     * Public so NestedJIT helper TUs bind the mutual callee (#28104).
     */
    public static function emitEscaped(string $string, int $i): string
    {
        if (!isset($string[$i])) {
            return '';
        }
        $next = $string[$i];
        // php-src stripslashes.c: drop backslash; \0 C-escape maps to NUL (addslashes inverse).
        $emitted = '0' === $next ? "\0" : $next;

        return $emitted.self::stripFrom($string, $i + 1);
    }
}
