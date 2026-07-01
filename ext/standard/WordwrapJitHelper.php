<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * wordwrap() for compiled JIT/AOT modules (#14565, php-in-PHP).
 *
 * SSOT: {@see VmString::wordwrap()}
 * php-src: ext/standard/string.c — PHP_FUNCTION(wordwrap)
 */
final class WordwrapJitHelper
{
    public static function wordwrapArgv(string $text, int $width, string $break, bool $cut): string
    {
        return VmString::wordwrap($text, $width, $break, $cut);
    }
}
