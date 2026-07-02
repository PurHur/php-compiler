<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * htmlspecialchars_decode() for compiled JIT/AOT modules (#14820, php-in-PHP).
 *
 * SSOT: {@see VmString::htmlspecialchars_decode()}
 * php-src: ext/standard/html.c — PHP_FUNCTION(htmlspecialchars_decode)
 */
final class HtmlspecialcharsDecodeJitHelper
{
    public static function htmlspecialcharsDecodeArgv(string $string, int $flags): string
    {
        return VmString::htmlspecialchars_decode($string, $flags);
    }
}
