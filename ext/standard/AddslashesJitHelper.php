<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * addslashes() for compiled JIT/AOT modules (#14741, #28104, php-in-PHP).
 *
 * Self-contained for NestedJIT (#16075 / #25345) — must not NestedJIT {@see VmString}
 * (thin AOT segfault after c:main_before_php when deps pull VmString — #26907).
 * Escape subset mirrors {@see VmString}::addslashes / php-src ext/standard/string.c.
 *
 * NestedJIT cannot lower a loop-carried string accumulator when indexing by offset;
 * recurse with `$prefix.$rest` instead (peer {@see HtmlspecialcharsJitHelper}).
 */
final class AddslashesJitHelper
{
    public static function addslashesArgv(string $string): string
    {
        return self::escapeFrom($string, 0);
    }

    /** Public so NestedJIT helper TUs bind the recursive callee (#25345). */
    public static function escapeFrom(string $string, int $i): string
    {
        if (!isset($string[$i])) {
            return '';
        }
        $ch = $string[$i];
        $rest = self::escapeFrom($string, $i + 1);
        if ("\0" === $ch) {
            // php-src string.c php_addslashes: NUL -> backslash + ASCII '0'
            return '\\0'.$rest;
        }
        if ('\\' === $ch || "'" === $ch || '"' === $ch) {
            return '\\'.$ch.$rest;
        }

        return $ch.$rest;
    }
}
