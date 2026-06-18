<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules for phpc_similar_text (#9731, php-in-PHP).
 *
 * SSOT: {@see VmString::similar_text()} (php-src ext/standard/string.c — php_similar_text).
 */
final class SimilarTextJitHelper
{
    public static function compute(string $string1, string $string2): int
    {
        return VmString::similar_text($string1, $string2);
    }
}
