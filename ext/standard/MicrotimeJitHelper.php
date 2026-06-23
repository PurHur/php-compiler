<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * microtime() for compiled JIT/AOT modules (#9181 slice, php-in-PHP).
 *
 * SSOT: {@see VmDate::microtime()}
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(microtime)
 */
final class MicrotimeJitHelper
{
    public static function microtimeFloat(): float
    {
        return VmDate::microtime(true);
    }

    public static function microtimeString(): string
    {
        return VmDate::microtime(false);
    }
}
