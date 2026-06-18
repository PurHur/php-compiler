<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules for addcslashes/stripcslashes runtime (#9578, php-in-PHP).
 *
 * SSOT: {@see VmString::addcslashes()} / {@see VmString::stripcslashes()} (php-src ext/standard/string.c).
 */
final class CslashesJitHelper
{
    public static function addcslashes(string $subject, string $charlist): string
    {
        return VmString::addcslashes($subject, $charlist);
    }

    public static function stripcslashes(string $subject): string
    {
        return VmString::stripcslashes($subject);
    }
}
