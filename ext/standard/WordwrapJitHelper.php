<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * wordwrap() for compiled JIT/AOT modules (#14565, #26904, #30812, php-in-PHP).
 *
 * Thin argv bridge — algorithm in {@see VmWordwrap}, NestedJIT-bundled with this file
 * (peer {@see SoundexJitHelper} / #30790). Solo NestedJIT of the former `$s[$i]` helper
 * SIGSEGV'd under thin AOT after c:main_before_php (#30812).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(wordwrap)
 */
final class WordwrapJitHelper
{
    public static function wordwrapArgv(string $text, int $width, string $break, int $cut): string
    {
        return VmWordwrap::wrap($text, $width, $break, $cut);
    }
}
