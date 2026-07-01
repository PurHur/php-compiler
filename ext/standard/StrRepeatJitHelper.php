<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * str_repeat() for compiled JIT/AOT modules (#14602, php-in-PHP).
 *
 * SSOT: {@see VmString::repeat()}
 * php-src: ext/standard/string.c — PHP_FUNCTION(str_repeat)
 */
final class StrRepeatJitHelper
{
    public static function strRepeatArgv(string $input, int $times): string
    {
        return VmString::repeat($input, $times);
    }
}
