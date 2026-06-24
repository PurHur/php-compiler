<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules for strtr() two-string form (#9392, php-in-PHP).
 *
 * SSOT: {@see VmString::strtr()}
 */
final class StrtrTwoStringJitHelper
{
    public static function strtrTwoString(string $subject, string $from, string $to): string
    {
        return VmString::strtr($subject, $from, $to);
    }
}
