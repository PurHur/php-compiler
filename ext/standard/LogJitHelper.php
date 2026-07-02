<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * log() for compiled JIT/AOT modules (#15117, php-in-PHP).
 *
 * SSOT: {@see VmMath::log()}
 * php-src: ext/standard/math.c — PHP_FUNCTION(log)
 */
final class LogJitHelper
{
    public static function logArgv(float $num): float
    {
        return VmMath::log($num);
    }
}
